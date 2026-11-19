<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Finance;
use App\Domain\Notifier;
use App\Domain\Rentals;
use App\Http\Controllers\Customer\StartSellingController;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /** Reference SP.dashboard: stats, charts and the upcoming schedule. */
    /**
     * Supplier dashboard, kept short on purpose (user decision 2026-11-17): what needs the owner now
     * (requests to answer, payments to check), what is coming up, and how the money is doing.
     * Stock, paid/unpaid counts and category figures live on Inventory, Payments and Reports.
     */
    public function dashboard(Request $request)
    {
        $u = $request->user();
        $sup = $u->shop();
        $T = today_iso();
        $reqs = RentalRequest::where('supplier_id', $sup->id)->with(['payments', 'customer'])->get();
        $ids = $reqs->pluck('id');
        $pend = $reqs->where('status', 'pending');
        $booked = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'];
        $upcoming = $reqs->whereIn('status', $booked)->sortBy('start_date')->values();
        $out = $reqs->whereIn('status', ['ongoing', 'return_pending', 'inspection']);
        $fins = $reqs->filter(fn ($r) => in_array($r->status, Finance::ACTIVE, true))->map(fn ($r) => $r->fin());
        $ver = Payment::whereIn('rental_request_id', $ids)->where('status', 'verified')->get();
        $revBy = fn ($m) => (float) $ver->filter(fn ($p) => str_starts_with((string) $p->date, $m) && in_array($p->type, Finance::RENTAL_TYPES, true))->sum('amount')
            - (float) $ver->filter(fn ($p) => str_starts_with((string) $p->date, $m) && $p->type === 'refund')->sum('amount');
        $pendingPay = Payment::whereIn('rental_request_id', $ids)->where('status', 'pending')->get();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $d = now()->startOfMonth()->subMonths($i);
            $months[] = ['key' => $d->format('Y-m'), 'label' => $d->format('M')];
        }
        $units = [];
        foreach ($reqs->reject(fn ($r) => in_array($r->status, ['declined', 'cancelled', 'pending'], true)) as $r) {
            foreach (inv()->demandMap($r->lines) as $m) {
                foreach ($m as $pid => $q) {
                    $units[$pid] = ($units[$pid] ?? 0) + $q;
                }
            }
        }
        arsort($units);
        $prods = Product::where('supplier_id', $sup->id)->whereNotIn('status', ['deleted', 'archived'])->get();
        inv()->primeProducts($prods);
        $repair = $prods->reject->isSale()->sum(function ($p) use ($T) {
            $b = inv()->breakdown($p->id, $T);

            return $b['maintenance'] + $b['damaged'] + $b['missing'];
        });

        return view('supplier.dashboard', [
            'sup' => $sup, 'pend' => $pend, 'money' => $u->orgCan('payments.view'), 'canProd' => $u->orgCan('products.manage'),
            'conflicts' => $pend->filter(fn ($r) => inv()->check($r->lines, $r->id)['bad'])->values(),
            'hasProducts' => $prods->isNotEmpty(),
            'stats' => [
                'upcoming' => $upcoming->count(), 'next' => $upcoming->first()?->start_date,
                'out' => $out->count(), 'returning' => $out->whereIn('status', ['return_pending', 'inspection'])->count(),
                'pendingPay' => $pendingPay->count(), 'pendingPayAmt' => (float) $pendingPay->sum('amount'),
                'overdue' => $fins->where('isOverdue', true)->count(), 'overdueAmt' => (float) $fins->where('isOverdue', true)->sum('overdueAmount'),
                'revenue' => $revBy(substr($T, 0, 7)), 'repair' => $repair,
            ],
            'charts' => [
                'revenue' => array_map(fn ($m) => ['label' => $m['label'], 'value' => $revBy($m['key'])], $months),
                'top' => collect($units)->take(5)->map(fn ($v, $pid) => ['label' => inv()->product($pid)?->name ?? 'Item', 'value' => $v])->values()->all(),
            ],
            'upcoming' => $upcoming->take(6),
        ]);
    }

    public function schedule(Request $request)
    {
        $sup = $request->user()->shop();
        $T = today_iso();
        $reqs = RentalRequest::where('supplier_id', $sup->id)->whereIn('status', Finance::ACTIVE)->with(['customer', 'payments'])->get();
        $events = [];
        foreach ($reqs as $r) {
            if (in_array($r->status, ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'], true)) {
                $events[] = ['d' => $r->start_date, 'kind' => $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup', 'r' => $r];
            }
            if (in_array($r->status, ['accepted', 'in_progress', 'ready_pickup', 'out_delivery', 'ongoing'], true)) {
                $events[] = ['d' => add_days($r->end_date, 1), 'kind' => 'Return', 'r' => $r];
            }
            if ($r->status === 'return_pending') {
                $events[] = ['d' => $T, 'kind' => 'Inspect return', 'r' => $r];
            }
        }
        usort($events, fn ($a, $b) => strcmp($a['d'], $b['d']));

        return view('supplier.schedule', [
            'events' => array_values(array_filter($events, fn ($e) => $e['d'] <= add_days($T, 14))), 'reqs' => $reqs, 'T' => $T,
            'cols' => [['To prepare', ['accepted'], 'b-sky'], ['Preparing', ['in_progress'], 'b-purple'], ['Ready / out for delivery', ['ready_pickup', 'out_delivery'], 'b-teal'], ['With customer', ['ongoing'], 'b-pink'], ['Return pending', ['return_pending'], 'b-gold'], ['Inspection', ['inspection'], 'b-purple']],
        ]);
    }

    /** Reference SP.payments "Payment Monitoring": the shop verifies its own payments. */
    public function payments(Request $request)
    {
        $u = $request->user();
        $sup = $u->shop();
        $reqs = RentalRequest::where('supplier_id', $sup->id)->whereIn('status', array_merge(Finance::ACTIVE, ['completed', 'cancelled']))->with(['payments', 'customer'])->get();
        $pays = Payment::whereIn('rental_request_id', $reqs->pluck('id'))->with('rentalRequest.customer')->orderByDesc('submitted_at')->get();
        $fins = $reqs->map(fn ($r) => ['r' => $r, 'f' => $r->fin()]);
        $canVerify = Rentals::canFinance($sup->id, 'payments.verify', $u);
        $tab = in_array($request->query('tab'), ['verify', 'balances', 'overdue', 'history'], true) ? $request->query('tab') : ($canVerify ? 'verify' : 'balances');

        return view('supplier.payments', [
            'tab' => $tab, 'canVerify' => $canVerify, 'pays' => $pays, 'pend' => $pays->where('status', 'pending')->values(), 'fins' => $fins,
            'cashiers' => \App\Models\ShopStaff::where('supplier_id', $sup->id)->where('role', 'cashier')->where('status', 'active')->with('user')->get()->pluck('user')->filter(fn ($x) => $x && $x->status === 'active'),
            'collected' => (float) $pays->where('status', 'verified')->whereIn('type', Finance::RENTAL_TYPES)->sum('amount'),
            'outstanding' => (float) $fins->filter(fn ($x) => in_array($x['r']->status, Finance::ACTIVE, true))->sum(fn ($x) => $x['f']['balance']),
            'overdue' => $fins->filter(fn ($x) => $x['f']['isOverdue']),
            'depositsHeld' => (float) $fins->sum(fn ($x) => $x['f']['depositHeld']),
        ]);
    }

    public function verifyPayment(Request $request, Payment $payment)
    {
        abort_unless($payment->rentalRequest?->supplier_id === $request->user()->shop()->id, 404);
        $res = Rentals::verifyPayment($payment, $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Verified · receipt '.$res['payment']->receipt_no.' · '.$payment->rentalRequest->fresh()->fin()['status'] : $res['error']);
    }

    public function rejectPayment(Request $request, Payment $payment)
    {
        abort_unless($payment->rentalRequest?->supplier_id === $request->user()->shop()->id, 404);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $res = Rentals::rejectPayment($payment, $data['reason'], $request->user());

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Payment rejected. Customer notified.' : $res['error']);
    }

    public const SHOP_TABS = ['profile' => 'Shop profile', 'delivery' => 'Delivery & pickup', 'hours' => 'Rental hours', 'plans' => 'Payment plans', 'services' => 'Services & fees', 'damage' => 'Damage policy', 'policies' => 'Business policies'];

    /** Reference SP.shop: seven tabs, each saving its own section. */
    public function shop(Request $request)
    {
        $s = $request->user()->shop();
        $tab = array_key_exists($request->query('tab'), self::SHOP_TABS) ? $request->query('tab') : 'profile';

        return view('supplier.shop', ['s' => $s, 'tab' => $tab, 'tabs' => self::SHOP_TABS, 'd' => $s->toArray(), 'logos' => StartSellingController::LOGOS,
            'cats' => \App\Models\Category::where('active', true)->orderBy('sort')->get()]);
    }

    /** Reference Services.saveShopSettings (+ profile / policies). Booked requests keep their quoted terms. */
    public function updateShop(Request $request)
    {
        $s = $request->user()->shop();
        $section = (string) $request->input('section', 'profile');
        $back = fn ($ok, $msg) => redirect()->route('supplier.shop', ['tab' => $section])->with($ok ? 'success' : 'error', $msg)->withInput($ok ? [] : $request->input());
        switch ($section) {
            case 'profile':
                if ($s->type === 'organization' || $s->isBusiness()) { // a business always works from a physical workplace
                    $request->merge(['location_type' => 'shop']);
                }
                $v = $request->validate([
                    'name' => 'required|string|min:3|max:120', 'description' => 'required|string|min:20|max:2000', 'logo' => 'required|string|max:16',
                    'phone' => ['required', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'], 'email' => 'required|email|max:180', 'hours' => 'required|string|max:120',
                    'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'location_type' => 'required|in:shop,home,none',
                    ...\App\Support\AddressInput::rules('shop_addr', $request->input('location_type', 'shop') !== 'none'),
                    'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
                ], StartSellingController::messages());
                $addr = \App\Support\AddressInput::attributes($v['shop_addr'] ?? []);
                if ($loc = StartSellingController::locationErrors($v + ['fulfillment' => $s->fulfillment], $addr['city_code'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages($loc);
                }
                $pin = isset($v['lat'], $v['lng']) && $v['lat'] !== null ? [round((float) $v['lat'], 7), round((float) $v['lng'], 7)] : [null, null];
                $s->update(['name' => trim($v['name']), 'description' => trim($v['description']), 'logo' => $v['logo'], 'phone' => $v['phone'], 'email' => $v['email'], 'hours' => $v['hours'],
                    'color' => $v['color'] ?? $s->color, 'city' => \App\Domain\Locations::name($addr['city_code']), ...$addr, 'location_type' => $v['location_type'], 'lat' => $pin[0], 'lng' => $pin[1]]);
                break;
            case 'delivery':
                $v = $request->validate(['fulfillment' => 'required|in:delivery,pickup,both', 'delivery_fee' => 'required|numeric|min:0', 'zones' => 'nullable|array', 'zones.*.name' => 'nullable|string|max:80', 'zones.*.fee' => 'nullable|numeric']);
                $zones = [];
                foreach (array_values($v['zones'] ?? []) as $i => $z) {
                    if (trim((string) ($z['name'] ?? '')) === '' && ($z['fee'] ?? '') === '') {
                        continue; // blank new row
                    }
                    if (mb_strlen(trim((string) $z['name'])) < 2 || ! is_numeric($z['fee'] ?? null) || $z['fee'] < 0) {
                        return $back(false, 'Every delivery zone needs a name and a fee of ₱0 or more.');
                    }
                    $zones[] = ['id' => $s->delivery_zones[$i]['id'] ?? 'z'.($i + 1), 'name' => trim($z['name']), 'fee' => (float) $z['fee']];
                }
                if ($v['fulfillment'] !== 'delivery' && $s->location_type === 'none') {
                    return $back(false, 'Without a pickup location you can only offer delivery. Set a location on the Shop profile tab first.');
                }
                $clash = RentalRequest::where('supplier_id', $s->id)->whereIn('status', Finance::ACTIVE)->get()->filter(fn ($r) => ($v['fulfillment'] === 'pickup' && $r->fulfillment === 'delivery') || ($v['fulfillment'] === 'delivery' && $r->fulfillment === 'pickup'))->count();
                $s->update(['fulfillment' => $v['fulfillment'], 'delivery_fee' => (float) $v['delivery_fee'], 'delivery_zones' => $zones]);
                Notifier::audit('Updated delivery settings', $s->name);
                if ($clash) {
                    return $back(true, 'Shop settings saved. Note: '.$clash.' accepted rental(s) still use the previous fulfillment option and will be honored.');
                }
                break;
            case 'hours':
                $v = $request->validate(['open' => ['required', 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'], 'close' => ['required', 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'], 'prepHours' => 'required|numeric|min:0|max:24', 'recoveryHours' => 'required|numeric|min:0|max:24']);
                if (\App\Domain\Slots::toMin($v['close']) <= \App\Domain\Slots::toMin($v['open'])) {
                    return $back(false, 'Rental hours must end later than they start.');
                }
                $s->update(['time_settings' => array_merge(\App\Domain\Slots::DEFAULT, (array) $s->time_settings, ['open' => $v['open'], 'close' => $v['close'], 'prepHours' => (float) $v['prepHours'], 'recoveryHours' => (float) $v['recoveryHours']])]);
                inv()->flush();
                Notifier::audit('Updated rental hours', $s->name.' · '.\App\Domain\Slots::fmt12($v['open']).'–'.\App\Domain\Slots::fmt12($v['close']).', prep '.$v['prepHours'].'h, recovery '.$v['recoveryHours'].'h');
                break;
            case 'plans':
                $v = $request->validate(['methods' => 'nullable|array', 'methods.*' => 'in:cash,bank,debit', 'plans' => 'required|array', 'depositType' => 'required|in:fixed,percent', 'depositValue' => 'required|numeric|min:0']);
                $pp = [];
                foreach (Finance::PLANS as $k) {
                    $in = (array) ($v['plans'][$k] ?? []);
                    $pp[$k] = ['enabled' => ! empty($in['enabled'])] + array_map(fn ($x) => is_numeric($x) ? $x + 0 : $x, array_intersect_key($in, Finance::DEFAULT_PLANS[$k]));
                    $pp[$k] = array_merge(Finance::DEFAULT_PLANS[$k], $pp[$k]);
                }
                if ($e = Finance::validatePlanConfig($pp)) {
                    return $back(false, $e);
                }
                if (empty($v['methods'])) {
                    return $back(false, 'Keep at least one payment method.');
                }
                if ($v['depositType'] === 'percent' && $v['depositValue'] > 100) {
                    return $back(false, 'Deposit percentage cannot exceed 100%.');
                }
                $s->update(['payment_plans' => $pp, 'methods' => array_values(array_unique($v['methods'])), 'policy' => array_merge((array) $s->policy, ['depositType' => $v['depositType'], 'depositValue' => (float) $v['depositValue']])]);
                Notifier::audit('Updated payment plans', $s->name.' · '.collect(Finance::plansOf($s->fresh()))->map(fn ($k) => Finance::PLAN_LABEL[$k])->implode(', '));
                break;
            case 'services':
                $list = [];
                foreach (array_values((array) $request->input('services', [])) as $i => $x) {
                    if (trim((string) ($x['name'] ?? '')) === '' && ($x['amount'] ?? '') === '') {
                        continue;
                    }
                    if (mb_strlen(trim((string) $x['name'])) < 3 || ! is_numeric($x['amount'] ?? null) || $x['amount'] < 0) {
                        return $back(false, 'Every service needs a name (3+ characters) and an amount of ₱0 or more.');
                    }
                    $list[] = ['id' => $x['id'] ?? ('sv'.($i + 1)), 'name' => trim($x['name']), 'description' => trim((string) ($x['description'] ?? '')), 'amount' => (float) $x['amount'], 'per' => ($x['per'] ?? '') === 'location' ? 'location' : 'order', 'required' => ! empty($x['required'])];
                }
                $s->update(['services' => $list]);
                Notifier::audit('Updated services', $s->name);
                break;
            case 'damage':
                $read = function ($o) {
                    $o = (array) $o;

                    return ['repairable' => ['enabled' => ! empty($o['rep_on']), 'pct' => (float) ($o['rep_pct'] ?? 0)], 'nonRepairable' => ['enabled' => ! empty($o['non_on']), 'pct' => (float) ($o['non_pct'] ?? 0)],
                        'lost' => ['enabled' => ! empty($o['lost_on']), 'brand_new' => (float) ($o['lost_brand_new'] ?? 0), 'good_as_new' => (float) ($o['lost_good_as_new'] ?? 0), 'used' => (float) ($o['lost_used'] ?? 0)]];
                };
                $dp = $read($request->input('d', [])) + ['categories' => []];
                foreach ((array) $request->input('c', []) as $cid => $o) {
                    $dp['categories'][(string) $cid] = $read($o);
                }
                if (($add = (string) $request->input('addCategory')) !== '' && \App\Models\Category::whereKey($add)->exists()) {
                    $dp['categories'][$add] ??= ['repairable' => $dp['repairable'], 'nonRepairable' => $dp['nonRepairable'], 'lost' => $dp['lost']];
                }
                if (($rm = (string) $request->input('removeCategory')) !== '') {
                    unset($dp['categories'][$rm]);
                }
                if ($e = \App\Domain\Damage::validatePolicy($dp)) {
                    return $back(false, $e);
                }
                $s->update(['damage_policy' => $dp]);
                Notifier::audit('Updated damage policy', $s->name);
                break;
            case 'policies':
                $v = $request->validate(['cancelDays' => 'nullable|integer|min:0|max:30', 'cancellation' => 'required|string|max:1000', 'returns' => 'required|string|max:1000', 'conditions' => 'required|string|max:1000']);
                $s->update(['policy' => array_merge((array) $s->policy, ['cancelDays' => (int) ($v['cancelDays'] ?? 0), 'cancellation' => $v['cancellation'], 'returns' => $v['returns'], 'conditions' => $v['conditions']])]);
                break;
            default:
                return $back(false, 'Unknown settings section.');
        }

        return $back(true, 'Shop settings saved.');
    }
}
