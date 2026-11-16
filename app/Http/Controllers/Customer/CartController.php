<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Ai;
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
        $res = Rentals::addToCart($request->user(), $data['type'], (int) $data['ref_id'], (array) json_decode($data['days'], true), is_array($sel) ? $sel : null, $time);
        if ($request->expectsJson()) {
            return response()->json($res, $res['ok'] ? 200 : 422);
        }
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }
        if (($data['then'] ?? 'list') === 'checkout') {
            return redirect()->route('checkout', ['supplier' => $res['supplier_id']]);
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
        $orders = [];
        foreach (Rentals::cartGroups($u) as $sid => $lines) {
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
                'supplier_id' => $s->id, 'cart_line_ids' => array_column($chosen, 'id'), 'first' => $dates->first(), 'last' => $dates->last(), 'eventDate' => $dates->first(),
                'fulfillment' => $s->fulfillment === 'pickup' ? 'pickup' : 'delivery', 'plan' => self::firstPlan($s), 'method' => 'online', 'services' => [],
                'destinations' => [['id' => 'd1', 'label' => 'Event venue', 'address' => $u->address ?: '', 'zoneId' => '', 'deliveryTime' => $st['open'], 'contactName' => '', 'contactPhone' => '']],
                'lines' => array_map(fn ($l) => ['cart_id' => $l['id'], 'name' => inv()->itemName($l), 'type' => $l['type'], 'days' => Rentals::cleanDays($l['days']),
                    'time' => Slots::time($l) ?? Slots::defaultTime($s), 'destId' => 'd1', 'group' => $l['id']], $chosen),
                'inst' => ['n' => min(3, (int) $cfg['installment']['maxInstallments']), 'items' => [], 'auto' => true],
                // static shop info for the page
                'shop' => [
                    'name' => $s->name, 'logo' => $s->logo, 'color' => $s->color, 'address' => $s->address, 'hours' => $s->hours, 'fulfillment' => $s->fulfillment,
                    'zones' => $s->delivery_zones ?? [], 'deliveryFee' => (float) $s->delivery_fee, 'services' => $s->services ?? [], 'times' => Slots::options($s),
                    'plans' => array_map(fn ($k) => ['key' => $k, 'label' => Finance::PLAN_LABEL[$k], 'summary' => Finance::planSummary($s, $k)], Finance::plansOf($s)),
                    'maxInstallments' => (int) $cfg['installment']['maxInstallments'],
                ],
            ];
        }
        if (! $orders) {
            return view('customer.checkout', ['orders' => [], 'u' => $u, 'eventTypes' => []]);
        }

        return view('customer.checkout', ['orders' => $orders, 'u' => $u, 'eventTypes' => array_merge(array_keys(Ai::EVENTS), ['Community', 'Other'])]);
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
            $o = array_merge($shared, $o, ['eventName' => mb_strlen(trim((string) ($shared['eventName'] ?? ''))) >= 3 ? $shared['eventName'] : 'Event']);
            $prep = Rentals::prepareOrder($u, $o);
            $q = $prep['ok'] ? $prep['attrs']['pricing'] : Pricing::quoteOrder(['supplier' => $s, 'lines' => array_map(fn ($l) => ['type' => $l['type'] ?? 'item', 'ref_id' => CartLine::find($l['cart_id'] ?? 0)?->ref_id] + $l, $o['lines'] ?? []),
                'fulfillment' => $o['fulfillment'] ?? 'pickup', 'destinations' => $o['destinations'] ?? [], 'plan' => $o['plan'] ?? 'full', 'services' => $o['services'] ?? []]);
            $total += $q['total'];
            $suggest = ($o['plan'] ?? '') === 'installment'
                ? Finance::suggestInstallments($q['finalBalance'], max(1, (int) ($o['inst']['n'] ?? 3)), add_days(today_iso(), 1), $o['eventDate'] ?? today_iso()) : [];
            $elig = Finance::installmentEligibility($s, $o['eventDate'] ?? null);
            $out[] = [
                'supplier_id' => $s->id, 'ok' => $prep['ok'], 'error' => $prep['error'] ?? null, 'total' => $q['total'], 'finalBalance' => $q['finalBalance'],
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
            'shared.eventName' => 'required|string|min:3|max:120', 'shared.eventType' => 'required|string|max:40',
            'shared.contactName' => 'required|string|max:120', 'shared.contactPhone' => ['required', 'regex:/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/'],
            'shared.instructions' => 'nullable|string|max:1000', 'orders' => 'required|array|min:1', 'agree' => 'accepted',
        ], ['shared.contactPhone.regex' => 'Enter a valid PH mobile number (e.g. 0917 555 0101).', 'agree.accepted' => 'Please accept the shops\' policies.',
            'shared.eventName.*' => 'Enter the event name (at least 3 characters).', 'shared.eventType.required' => 'Choose the event type.']);
        $shared = (array) $request->input('shared');
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
