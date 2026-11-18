<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Finance;
use App\Domain\Pricing;
use App\Domain\Rentals;
use App\Domain\Slots;
use App\Http\Controllers\Controller;
use App\Models\CartLine;
use App\Models\RentalRequest;
use App\Models\Supplier;
use Illuminate\Http\Request;

/**
 * Rental List and checkout (reference customer/cart.html + checkout.html).
 * Items from several shops sit in one list, grouped by shop, with per-item / per-shop / Select-all checkboxes.
 * Checkout turns the selected items into ONE request per supplier (Rentals::submitOrders).
 */
class CartController extends Controller
{
    /** First plan a shop offers (Standard when available). */
    public static function firstPlan(Supplier $s): string
    {
        $pl = Finance::plansOf($s);

        return in_array('standard', $pl, true) ? 'standard' : ($pl[0] ?? 'full');
    }

    /** Estimate for a shop's lines: first plan, delivery at the standard rate. */
    public static function estimate(Supplier $s, array $lines): array
    {
        $del = $s->fulfillment !== 'pickup';

        return Pricing::quoteOrder(['supplier' => $s, 'lines' => $lines, 'fulfillment' => $del ? 'delivery' : 'pickup', 'plan' => self::firstPlan($s),
            'destinations' => $del ? [['id' => 'd1', 'label' => 'Delivery (standard rate)', 'zoneId' => '']] : []]);
    }

    public function index(Request $request)
    {
        $u = $request->user();
        $groups = [];
        $blocked = false;
        foreach (Rentals::cartGroups($u) as $sid => $lines) {
            $s = Supplier::find($sid);
            if (! $s) {
                continue;
            }
            $lines = array_map(fn ($l) => array_merge($l, ['days' => Rentals::cleanDays($l['days'])]), $lines);
            $chosen = array_values(array_filter($lines, fn ($l) => $l['selected']));
            $chosenBad = $chosen && (! inv()->check($chosen)['ok'] || Rentals::durationIssues($chosen) || Slots::timeIssues($chosen));
            $blocked = $blocked || $chosenBad;
            $groups[] = [
                'supplier' => $s, 'lines' => $lines, 'chosen' => $chosen, 'chosenBad' => $chosenBad,
                'check' => inv()->check($lines), 'duration' => Rentals::durationIssues($lines), 'timeIssues' => Slots::timeIssues($lines),
                'quote' => $chosen ? self::estimate($s, $chosen) : null,
            ];
        }
        $all = collect($groups)->flatMap(fn ($g) => $g['lines']);

        return view('customer.cart', [
            'groups' => $groups, 'blocked' => $blocked, 'total' => $all->count(), 'selected' => $all->where('selected', true)->count(),
            'grand' => r2(collect($groups)->sum(fn ($g) => $g['quote']['total'] ?? 0)),
        ]);
    }

    public function add(Request $request)
    {
        $t = ['nullable', 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'];
        $data = $request->validate(['type' => 'required|in:item,package', 'ref_id' => 'required|integer', 'days' => 'required|string|max:5000', 'sel' => 'nullable|string|max:5000',
            'then' => 'nullable|in:list,checkout', 'start' => $t, 'end' => $t, 'from' => $t, 'to' => $t]);
        $sel = json_decode((string) ($data['sel'] ?? ''), true);
        $time = ($data['start'] ?? $data['from'] ?? null) ? ['start' => $data['start'] ?? $data['from'], 'end' => $data['end'] ?? $data['to'] ?? ''] : null;
        $direct = ($data['then'] ?? 'list') === 'checkout' && ! $request->expectsJson();
        $res = Rentals::addToCart($request->user(), $data['type'], (int) $data['ref_id'], (array) json_decode($data['days'], true), is_array($sel) ? $sel : null, $time, $direct);
        if ($request->expectsJson()) {
            return response()->json($res, $res['ok'] ? 200 : 422);
        }
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }
        if (($data['then'] ?? 'list') === 'checkout') {
            return redirect()->route('checkout', ['line' => $res['line_id']]);
        }

        return back()->with('success', $res['name'].' added to your Rental List.');
    }

    public function update(Request $request, CartLine $line)
    {
        abort_unless($line->user_id === $request->user()->id, 404);
        $res = Rentals::updateCartLine($request->user(), $line, (array) $request->input('days', []));

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    /** Tick / untick lines (one line, one shop, or Select all). */
    public function select(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer', 'on' => 'required|boolean']);
        Rentals::setCartSelected($request->user(), $data['ids'], (bool) $data['on']);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function time(Request $request, CartLine $line)
    {
        abort_unless($line->user_id === $request->user()->id, 404);
        $t = ['required', 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'];
        $data = $request->validate(['start' => $t, 'end' => $t]);
        $res = Rentals::setCartTime($request->user(), $line, $data);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Rental time updated.' : $res['error']);
    }

    public function remove(Request $request, CartLine $line)
    {
        abort_unless($line->user_id === $request->user()->id, 404);
        $line->delete();

        return back()->with('success', 'Item removed from your Rental List.');
    }

    /* ---------------- checkout ---------------- */

    /** Initial state of every selected shop order (the checkout page's JS edits it and asks quote() to price it). */
    public function checkout(Request $request)
    {
        $u = $request->user();
        $only = (int) $request->query('supplier');
        // ?line= : "Request Rental" from a listing = only that item; otherwise the ticked Rental List items (of one shop with ?supplier=)
        $direct = (int) $request->query('line');
        if ($direct) {
            $dl = \App\Models\CartLine::where('user_id', $u->id)->where('direct', true)->find($direct);
            if (! $dl) {
                return redirect()->route('cart')->with('warn', 'That checkout has expired. Please request the item again from its page.');
            }
            $groups = [$dl->supplier_id => [$dl->toLine()]];
        } else {
            $groups = Rentals::cartGroups($u);
        }
        $orders = [];
        foreach ($groups as $sid => $lines) {
            $chosen = array_values(array_filter($lines, fn ($l) => $l['selected'] && (! $only || $sid === $only)));
            if (! $chosen || ! ($s = Supplier::find($sid))) {
                continue;
            }
            if ($s->owner_id === $u->id) {
                return redirect()->route('cart')->with('warn', 'You cannot rent from your own shop. Untick or remove those items in your Rental List.');
            }
            $dates = collect($chosen)->flatMap(fn ($l) => array_keys(Rentals::cleanDays($l['days'])))->unique()->sort()->values();
            if ($dates->isEmpty()) {
                continue;
            }
            $st = Slots::settings($s);
            $cfg = Finance::planConfig($s);
            $orders[] = [
                'supplier_id' => $s->id, 'cart_line_ids' => array_column($chosen, 'id'), 'first' => $dates->first(), 'last' => $dates->last(), 'range' => fmt_range($dates->first(), $dates->last()), 'eventDate' => $dates->first(),
                'fulfillment' => $s->fulfillment === 'pickup' ? 'pickup' : 'delivery', 'plan' => self::firstPlan($s), 'method' => 'online', 'services' => [],
                'destinations' => [['id' => 'd1', 'label' => 'Event venue', 'address' => $u->address ?: '', 'zoneId' => '', 'deliveryTime' => $st['open'], 'contactName' => '', 'contactPhone' => '']],
                'lines' => array_map(fn ($l) => ['cart_id' => $l['id'], 'name' => inv()->itemName($l), 'type' => $l['type'], 'days' => Rentals::cleanDays($l['days']),
                    'time' => Slots::time($l) ?? Slots::defaultTime($s, $l['type'] === 'package' ? null : inv()->product($l['ref_id'])), 'destId' => 'd1', 'group' => $l['id']], $chosen),
                'inst' => ['n' => min(3, (int) $cfg['installment']['maxInstallments']), 'items' => [], 'auto' => true],
                // static shop info for the page
                'shop' => [
                    'name' => $s->name, 'logo' => $s->logo, 'color' => $s->color, 'address' => $s->publicAddress(), 'hours' => $s->hours, 'fulfillment' => $s->fulfillment,
                    'zones' => $s->delivery_zones ?? [], 'deliveryFee' => (float) $s->delivery_fee, 'services' => $s->services ?? [], 'times' => Slots::options($s),
                    'plans' => array_map(fn ($k) => ['key' => $k, 'label' => Finance::PLAN_LABEL[$k], 'summary' => Finance::planSummary($s, $k)], Finance::plansOf($s)),
                    'maxInstallments' => (int) $cfg['installment']['maxInstallments'],
                ],
            ];
        }
        return view('customer.checkout', ['orders' => $orders, 'u' => $u, 'direct' => (bool) $direct]);
    }

    /**
     * Checkout quantity field: change how many of a selected item (per rental date) and check the stock at once.
     * Saves the new quantities on the cart line (the Rental List or a direct "Request Rental" line), so the order
     * that is submitted always matches. 422 + a clear message + what IS available when the stock is short.
     */
    public function qty(Request $request)
    {
        $data = $request->validate(
            ['cart_id' => 'required|integer', 'days' => 'required|array|min:1', 'days.*' => 'required|integer|min:1|max:1000000'],
            ['days.*.min' => 'The quantity must be at least 1. To drop an item, remove it from your Rental List.', 'days.*.max' => 'That quantity is too large.',
                'days.*.integer' => 'Enter a whole number.', 'days.*.required' => 'Enter a quantity.']
        );
        $line = CartLine::where('user_id', $request->user()->id)->find($data['cart_id']);
        if (! $line) {
            return response()->json(['ok' => false, 'error' => 'This item is no longer in your Rental List. Reload the page.'], 404);
        }
        $cur = $line->toLine();
        $days = array_map('intval', $data['days']);
        ksort($days);
        if (array_keys($days) !== array_keys(Rentals::cleanDays($cur['days']))) {
            return response()->json(['ok' => false, 'error' => 'The rental dates can only be changed in your Rental List or on the item page.'], 422);
        }
        inv()->flush();
        $chk = inv()->check([array_merge($cur, ['id' => 'x', 'days' => $days])]);
        $avail = array_map(fn ($x) => $x['available'], $chk['byLine']['x'] ?? []);
        if (! $chk['ok']) {
            return response()->json(['ok' => false, 'error' => self::stockMessage($chk), 'available' => $avail], 422);
        }
        $res = Rentals::updateCartLine($request->user(), $line, $days);
        if (! $res['ok']) {
            return response()->json(['ok' => false, 'error' => $res['error'], 'available' => $avail], 422);
        }

        return response()->json(['ok' => true, 'days' => $days, 'available' => $avail]);
    }

    /** "Only 12 Gold Chairs are available on Jan 26 (you asked for 20)." — up to three problems. */
    public static function stockMessage(array $chk): string
    {
        // one sentence per item + numbers, listing every date it applies to
        $parts = collect($chk['bad'] ?? [])->unique(fn ($b) => ($b['product_id'] ?? 0).'|'.$b['date'])
            ->groupBy(fn ($b) => ($b['product_id'] ?? 0).'|'.$b['name'].'|'.$b['available'].'|'.$b['requested'].'|'.(! empty($b['service']) ? 's' : ''))
            ->take(3)->map(function ($g) {
                $b = $g->first();
                $on = ! empty($b['sale']) ? '' : ' on '.$g->pluck('date')->sort()->map(fn ($d) => fmt_date($d))->join(', ', ' and ');

                return ! empty($b['service'])
                    ? $b['name'].' is fully booked'.$on.' ('.$b['available'].' team'.($b['available'] === 1 ? '' : 's').' free)'
                    : ($b['available'] > 0 ? 'Only '.$b['available'].' × '.$b['name'].' '.($b['available'] === 1 ? 'is' : 'are').' available' : $b['name'].' is not available').$on.' (you asked for '.$b['requested'].')';
            });

        return $parts->isEmpty() ? 'Some quantities exceed the available stock. Please lower them.' : $parts->implode('. ').'. Lower the quantity or pick other dates.';
    }

    /** Availability per selected item and date for the checkout hints: [cart_id => [date => [available, requested, ok]]]. */
    private static function availability(int $userId, array $o): array
    {
        $byCart = [];
        foreach ((array) ($o['lines'] ?? []) as $l) {
            $id = (int) ($l['cart_id'] ?? 0);
            foreach (Rentals::cleanDays((array) ($l['days'] ?? [])) as $d => $q) {
                $byCart[$id][$d] = ($byCart[$id][$d] ?? 0) + $q;
            }
        }
        $lines = [];
        foreach (CartLine::where('user_id', $userId)->whereIn('id', array_keys($byCart))->get() as $cl) {
            $lines[] = array_merge($cl->toLine(), ['id' => (string) $cl->id, 'days' => $byCart[$cl->id]]);
        }

        return $lines ? inv()->check($lines)['byLine'] : [];
    }

    /** Validate + price the checkout state (no save): per shop the review HTML, plus the order summary. */
    public function quote(Request $request)
    {
        $u = $request->user();
        $shared = (array) $request->input('shared', []);
        $out = [];
        $total = 0.0;
        foreach ((array) $request->input('orders', []) as $o) {
            $s = Supplier::find($o['supplier_id'] ?? 0);
            if (! $s) {
                continue;
            }
            $o = array_merge($shared, $o);
            $prep = Rentals::prepareOrder($u, $o);
            $q = $prep['ok'] ? $prep['attrs']['pricing'] : Pricing::quoteOrder(['supplier' => $s, 'lines' => array_map(fn ($l) => ['type' => $l['type'] ?? 'item', 'ref_id' => CartLine::find($l['cart_id'] ?? 0)?->ref_id] + $l, $o['lines'] ?? []),
                'fulfillment' => $o['fulfillment'] ?? 'pickup', 'destinations' => $o['destinations'] ?? [], 'plan' => $o['plan'] ?? 'full', 'services' => $o['services'] ?? []]);
            $total += $q['total'];
            $suggest = ($o['plan'] ?? '') === 'installment'
                ? Finance::suggestInstallments($q['finalBalance'], max(1, (int) ($o['inst']['n'] ?? 3)), add_days(today_iso(), 1), $o['eventDate'] ?? today_iso()) : [];
            $elig = Finance::installmentEligibility($s, $o['eventDate'] ?? null);
            $out[] = [
                'supplier_id' => $s->id, 'ok' => $prep['ok'], 'error' => $prep['error'] ?? null, 'total' => $q['total'], 'finalBalance' => $q['finalBalance'],
                'avail' => self::availability($u->id, $o),
                'suggest' => $suggest, 'installmentOk' => $elig['ok'], 'installmentMsg' => $elig['message'] ?? '',
                'html' => view('customer._checkout-review', ['q' => $q, 'o' => $o, 'prep' => $prep])->render(),
                'summary' => ['name' => $s->name, 'plan' => Finance::PLAN_LABEL[$q['plan']], 'where' => ($o['fulfillment'] ?? '') === 'delivery' ? count($o['destinations'] ?? []).' location'.(count($o['destinations'] ?? []) > 1 ? 's' : '') : 'pickup', 'q' => $q],
            ];
        }

        return response()->json(['orders' => $out, 'summaryHtml' => view('customer._checkout-summary', ['orders' => $out, 'total' => r2($total)])->render()]);
    }

    public function submit(Request $request)
    {
        $request->validate([
            'shared.contactName' => 'required|string|max:120', 'shared.contactPhone' => ['required', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'],
            'shared.instructions' => 'nullable|string|max:1000', 'orders' => 'required|array|min:1', 'agree' => 'accepted',
        ], ['shared.contactPhone.regex' => 'Enter a valid PH mobile number (e.g. 0917 555 0101).', 'agree.accepted' => 'Please accept the shops\' policies.',
]);
        // Renting needs only the customer's contact details: no event name/type (the booking is named after its items).
        $shared = array_diff_key((array) $request->input('shared'), ['eventName' => 1, 'eventType' => 1]);
        $orders = array_map(fn ($o) => array_merge($shared, $o, ['installments' => $o['inst']['items'] ?? []]), (array) $request->input('orders'));
        $res = Rentals::submitOrders($request->user(), $orders);
        if (! $res['ok']) {
            return response()->json(['ok' => false, 'error' => $res['error'], 'supplier_id' => $res['supplier_id'] ?? null], 422);
        }

        return response()->json(['ok' => true, 'redirect' => route('rentals.submitted', ['ids' => collect($res['requests'])->pluck('code')->implode(',')])]);
    }

    /** "Request submitted" page for one or more requests from one checkout. */
    public function submitted(Request $request)
    {
        $codes = array_filter(explode(',', (string) $request->query('ids')));
        $list = RentalRequest::whereIn('code', $codes)->where('customer_id', $request->user()->id)->with('supplier')->orderBy('id')->get();
        abort_if($list->isEmpty(), 404);

        return view('customer.confirmation', ['list' => $list, 'left' => Rentals::cartCount($request->user())]);
    }
}
