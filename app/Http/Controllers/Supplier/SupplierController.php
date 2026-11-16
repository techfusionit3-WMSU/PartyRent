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
    public function dashboard(Request $request)
    {
        $u = $request->user();
        $sup = $u->shop();
        $T = today_iso();
        $reqs = RentalRequest::where('supplier_id', $sup->id)->with(['payments', 'customer'])->get();
        $prods = Product::where('supplier_id', $sup->id)->where('status', '!=', 'deleted')->with('category')->get();
        inv()->primeProducts($prods);
        $snap = $prods->reject->isSale()->map(fn ($p) => inv()->breakdown($p->id, $T));
        $ids = $reqs->pluck('id');
        $pend = $reqs->where('status', 'pending');
        $booked = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'];
        $fins = $reqs->filter(fn ($r) => in_array($r->status, Finance::ACTIVE, true) || $r->status === 'completed')->map(fn ($r) => $r->fin());
        $ver = Payment::whereIn('rental_request_id', $ids)->where('status', 'verified')->get();
        $revBy = fn ($m) => (float) $ver->filter(fn ($p) => str_starts_with((string) $p->date, $m) && in_array($p->type, Finance::RENTAL_TYPES, true))->sum('amount')
            - (float) $ver->filter(fn ($p) => str_starts_with((string) $p->date, $m) && $p->type === 'refund')->sum('amount');
        $pendingPay = Payment::whereIn('rental_request_id', $ids)->where('status', 'pending')->get();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $d = now()->startOfMonth()->subMonths($i);
            $months[] = ['key' => $d->format('Y-m'), 'label' => $d->format('M')];
        }
        $live = $reqs->reject(fn ($r) => in_array($r->status, ['declined', 'cancelled', 'pending'], true));
        $units = [];
        $catRev = [];
        foreach ($live as $r) {
            foreach (inv()->demandMap($r->lines) as $m) {
                foreach ($m as $pid => $q) {
                    $units[$pid] = ($units[$pid] ?? 0) + $q;
                }
            }
            foreach ($r->pricing['lines'] ?? [] as $l) {
                $cat = ($l['type'] ?? '') === 'package' ? 'Packages' : (inv()->product($l['refId'] ?? 0)?->category?->name ?? 'Other');
                $catRev[$cat] = ($catRev[$cat] ?? 0) + (float) ($l['total'] ?? 0);
            }
        }
        arsort($units);
        arsort($catRev);
        $statusGroups = [['Pending', ['pending']], ['Upcoming', $booked], ['Ongoing / returning', ['ongoing', 'return_pending', 'inspection']], ['Completed', ['completed']], ['Declined / cancelled', ['declined', 'cancelled']]];

        return view('supplier.dashboard', [
            'sup' => $sup, 'pend' => $pend, 'money' => $u->orgCan('payments.view'), 'canProd' => $u->orgCan('products.manage'),
            'conflicts' => $pend->filter(fn ($r) => inv()->check($r->lines, $r->id)['bad'])->values(),
            'stats' => [
                'listed' => $prods->where('status', 'active')->count(), 'prodCount' => $prods->count(),
                'total' => $snap->sum('total'), 'available' => $snap->sum('available'), 'reserved' => $snap->sum('reserved'),
                'outOfService' => $snap->sum(fn ($b) => $b['maintenance'] + $b['damaged'] + $b['missing'] + $b['setaside']), 'maintenance' => $snap->sum('maintenance'),
                'accepted' => $reqs->whereIn('status', $booked)->count(), 'ongoing' => $reqs->whereIn('status', ['ongoing', 'return_pending', 'inspection'])->count(),
                'completed' => $reqs->where('status', 'completed')->count(),
                'pendingPay' => $pendingPay->count(), 'pendingPayAmt' => (float) $pendingPay->sum('amount'),
                'partly' => $fins->where('status', 'Partially Paid')->count(), 'fully' => $fins->where('status', 'Fully Paid')->count(),
                'overdue' => $fins->where('isOverdue', true)->count(), 'overdueAmt' => (float) $fins->where('isOverdue', true)->sum('overdueAmount'),
                'revenue' => $revBy(substr($T, 0, 7)),
            ],
            'charts' => [
                'monthly' => array_map(fn ($m) => ['label' => $m['label'], 'value' => $reqs->filter(fn ($r) => $r->created_at?->format('Y-m') === $m['key'])->count()], $months),
                'revenue' => array_map(fn ($m) => ['label' => $m['label'], 'value' => $revBy($m['key'])], $months),
                'top' => collect($units)->take(6)->map(fn ($v, $pid) => ['label' => inv()->product($pid)?->name ?? 'Item', 'value' => $v])->values()->all(),
                'status' => array_map(fn ($g) => ['label' => $g[0], 'value' => $reqs->whereIn('status', $g[1])->count()], $statusGroups),
                'category' => collect($catRev)->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all(),
            ],
            'upcoming' => $reqs->whereIn('status', $booked)->sortBy('start_date')->take(6),
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
            'cashiers' => \App\Models\ShopStaff::where('supplier_id', $sup->id)->where('role', 'cashier')->with('user')->get()->pluck('user')->filter(fn ($x) => $x && $x->status === 'active'),
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

    public function shop(Request $request)
    {
        $s = $request->user()->shop();

        return view('supplier.shop', ['s' => $s, 'd' => $s->toArray(), 'p' => $s->policy ?? [], 'logos' => StartSellingController::LOGOS, 'previewCity' => $s->city_code]);
    }

    public function updateShop(Request $request)
    {
        $s = $request->user()->shop();
        $v = $request->validate(StartSellingController::rules(), StartSellingController::messages());
        $s->update(StartSellingController::toAttributes($v));
        Notifier::audit('Updated shop profile', $s->name);

        return back()->with('success', 'Shop profile saved. New prices and policies apply to new requests only — submitted requests keep their quoted terms.');
    }
}
