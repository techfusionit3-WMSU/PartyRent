<?php

namespace App\Domain;

use App\Models\CartLine;
use App\Models\Hold;
use App\Models\Package;
use App\Models\PayAccount;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\RequestEvent;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Business workflows (port of js/core/services.js). Every state change goes through
 * here so inventory, payments, notifications and the audit trail stay consistent.
 * Each method returns ['ok' => bool, 'error' => string|null, ...].
 */
class Rentals
{
    public const FLOW = [
        'accepted' => ['in_progress'],
        'in_progress' => ['ready_pickup', 'out_delivery'],
        'ready_pickup' => ['ongoing'],
        'out_delivery' => ['ongoing'],
        'ongoing' => ['return_pending'],
        'return_pending' => ['inspection'],
        'inspection' => ['completed'],
    ];

    public const ACTION_LABEL = [
        'in_progress' => 'Start preparing items', 'ready_pickup' => 'Mark ready for pickup', 'out_delivery' => 'Mark out for delivery',
        'ongoing' => 'Record release to customer', 'return_pending' => 'Mark return pending', 'inspection' => 'Record returned items', 'completed' => 'Complete rental',
    ];

    private static function fail(string $error, array $extra = []): array
    {
        return array_merge(['ok' => false, 'error' => $error], $extra);
    }

    private static function ok(array $extra = []): array
    {
        return array_merge(['ok' => true, 'error' => null], $extra);
    }

    private static function link(string $route, mixed $params = []): string
    {
        return route($route, $params, false);
    }

    private static function history(RentalRequest $r, string $status, ?string $note = null): void
    {
        RequestEvent::create(['rental_request_id' => $r->id, 'status' => $status, 'user_id' => auth()->id(), 'note' => $note ?: null, 'created_at' => now()]);
    }

    private static function changed(RentalRequest $r): void
    {
        $r->finCache = null;
        inv()->flush();
    }

    /* ================= CART (Rental List) ================= */

    /** @return array<int, array<int, array>> supplier_id => lines (with `selected`) */
    public static function cartGroups(User $u): array
    {
        $groups = [];
        foreach (CartLine::where('user_id', $u->id)->orderBy('id')->get() as $c) {
            $groups[$c->supplier_id][] = $c->toLine();
        }

        return $groups;
    }

    public static function cartCount(User $u): int
    {
        return CartLine::where('user_id', $u->id)->count();
    }

    public static function cartLinesFor(User $u, int $supplierId): array
    {
        return CartLine::where('user_id', $u->id)->where('supplier_id', $supplierId)->orderBy('id')->get()->map->toLine()->all();
    }

    /** Days of notice a line needs: a sale item's lead time, or the longest among a package's sale items. */
    public static function leadDays(array $line): int
    {
        if ($line['type'] === 'package') {
            $def = inv()->lineDef($line);

            return $def ? Packages::leadDays($def, $line['sel'] ?? null) : 0;
        }
        $p = inv()->product($line['ref_id']);

        return $p && $p->isSale() ? (int) $p->lead_days : 0;
    }

    public static function leadTimeError(array $line): ?string
    {
        $lead = self::leadDays($line);
        $dates = array_keys(array_filter(array_map('intval', $line['days'] ?? [])));
        if ($lead > 0 && $dates && min($dates) < add_days(today_iso(), $lead)) {
            return inv()->itemName($line).': order at least '.$lead.' day'.($lead > 1 ? 's' : '').' before your event (earliest '.fmt_date(add_days(today_iso(), $lead)).').';
        }

        return null;
    }

    /**
     * Maximum rental duration (Services.durationIssues): within one booking, the period from the first to the
     * last day a product is rented (directly or inside a package) may not exceed the product's limit.
     */
    public static function durationIssues(array $lines): array
    {
        $byProduct = [];
        foreach ($lines as $l) {
            foreach ($l['days'] ?? [] as $d => $q) {
                if ((int) $q > 0) {
                    foreach (array_keys(inv()->unitsForLine($l, $d)) as $pid) {
                        $byProduct[$pid][$d] = true;
                    }
                }
            }
        }
        $out = [];
        foreach ($byProduct as $pid => $set) {
            $p = inv()->product($pid);
            if (! $p || ! ($p->max_rental_days > 0)) {
                continue;
            }
            $dates = array_keys($set);
            sort($dates);
            $first = $dates[0];
            $last = end($dates);
            $span = diff_days($first, $last) + 1;
            if ($span > $p->max_rental_days) {
                $out[] = ['product_id' => $pid, 'name' => $p->name, 'max' => (int) $p->max_rental_days, 'span' => $span, 'first' => $first, 'last' => $last,
                    'message' => $p->name.' can be rented for a maximum of '.$p->max_rental_days.' day'.($p->max_rental_days > 1 ? 's' : '').' per booking. Your selected period ('.fmt_range($first, $last).') is '.$span.' days. Please shorten the rental period, or make a separate booking for the other dates.'];
            }
        }

        return $out;
    }

    /** Message for a failed stock check. */
    private static function stockError(array $chk): string
    {
        return 'Not enough stock: '.implode('; ', array_map(fn ($b) => ! empty($b['sale'])
            ? $b['name'].' (only '.$b['available'].' in stock)'
            : (! empty($b['service']) ? $b['service'].' on '.fmt_date($b['date']).' (fully booked: '.$b['available'].' team'.($b['available'] === 1 ? '' : 's').' free)'
                : $b['name'].' on '.fmt_date($b['date']).' (requested '.$b['requested'].', available '.$b['available'].' for the selected time)'), $chk['bad'])).'.';
    }

    private static function timeKey(?array $t): string
    {
        return $t && ! empty($t['start']) && ! empty($t['end']) ? $t['start'].'-'.$t['end'] : 'all';
    }

    private static function listed(Product|Package|null $item): bool
    {
        return $item && $item->status === 'active' && ! ($item instanceof Package && $item->is_draft) && $item->supplier?->status === 'approved';
    }

    /**
     * Add to the Rental List (Services.addToCart). The same item with the same rental time merges into one
     * line; a different time slot is a separate line. Time, duration and stock are validated together with
     * the rest of this supplier's list, inside a transaction that locks the products involved.
     */
    public static function addToCart(User $u, string $type, int $refId, array $days, ?array $sel = null, ?array $time = null): array
    {
        $item = $type === 'package' ? Package::find($refId) : Product::find($refId);
        if (! self::listed($item)) {
            return self::fail('This listing is not available.');
        }
        if ($item->supplier->owner_id === $u->id) {
            return self::fail('You cannot rent from your own shop.');
        }
        $days = self::cleanDays($days);
        if (! $days) {
            return self::fail('Set a quantity for at least one date.');
        }
        if (min(array_keys($days)) < today_iso()) {
            return self::fail('You cannot rent for a past date.');
        }
        if (count($days) > (int) Settings::get('maxRentalDays', 30)) {
            return self::fail('Maximum of '.Settings::get('maxRentalDays', 30).' rental dates per item.');
        }
        $sel = $type === 'package' ? Packages::cleanSel(Packages::def($item), $sel) : null;
        $time = Slots::time(['time' => $time]);

        return DB::transaction(function () use ($u, $type, $refId, $days, $sel, $time, $item) {
            self::lockProducts([['type' => $type, 'ref_id' => $refId, 'days' => $days, 'sel' => $sel]]);
            inv()->flush();
            $existing = CartLine::where('user_id', $u->id)->where('type', $type)->where('ref_id', $refId)->get()
                ->first(fn ($c) => self::timeKey(Slots::time($c->toLine())) === self::timeKey($time) && ($type !== 'package' || Packages::cleanSel(Packages::def($item), $c->sel) == $sel));
            $merged = $existing ? $existing->days : [];
            foreach ($days as $d => $q) {
                $merged[$d] = (int) ($merged[$d] ?? 0) + $q;
            }
            ksort($merged);
            $cand = ['id' => '__new', 'type' => $type, 'ref_id' => $refId, 'days' => $merged] + ($sel ? ['sel' => $sel] : []) + ($time ? ['time' => $time] : []);
            if ($t = Slots::validateLine($cand)) {
                return self::fail($t[0], ['timeIssues' => $t]);
            }
            if ($e = self::leadTimeError($cand)) {
                return self::fail($e);
            }
            $others = array_values(array_filter(self::cartLinesFor($u, $item->supplier_id), fn ($l) => $l['id'] !== $existing?->id));
            if ($issues = self::durationIssues(array_merge($others, [$cand]))) {
                return self::fail($issues[0]['message'].(($existing || $others) ? ' (This includes dates already in your Rental List for this supplier — submit that request first, then book the other dates separately.)' : ''), ['issues' => $issues]);
            }
            $chk = inv()->check(array_merge($others, [$cand]));
            if (! $chk['ok']) {
                return self::fail(self::stockError($chk), ['check' => $chk]);
            }
            if ($existing) {
                $existing->update(['days' => $merged, 'selected' => true]);
            } else {
                CartLine::create(['user_id' => $u->id, 'supplier_id' => $item->supplier_id, 'type' => $type, 'ref_id' => $refId, 'days' => $days, 'sel' => $sel, 'time' => $time, 'selected' => true]);
            }

            return self::ok(['name' => $item->name, 'supplier_id' => $item->supplier_id]);
        });
    }

    /** Customer ticks / unticks items in the Rental List (unselected items stay in the list). */
    public static function setCartSelected(User $u, array $ids, bool $on): void
    {
        CartLine::where('user_id', $u->id)->whereIn('id', $ids)->update(['selected' => $on]);
    }

    /** Change a line's rental time (validated against the supplier's hours and stock). */
    public static function setCartTime(User $u, CartLine $line, array $time): array
    {
        if ($line->user_id !== $u->id) {
            return self::fail('Item not found.');
        }
        $time = Slots::time(['time' => $time]);
        $cand = array_merge($line->toLine(), ['time' => $time]);
        if ($t = Slots::validateLine($cand)) {
            return self::fail($t[0]);
        }
        $others = array_values(array_filter(self::cartLinesFor($u, $line->supplier_id), fn ($l) => $l['id'] !== $line->id));
        $chk = inv()->check(array_merge($others, [$cand]));
        if (! $chk['ok']) {
            return self::fail('Not enough stock for that time: '.implode('; ', array_map(fn ($b) => $b['name'].' on '.fmt_date($b['date']).' (available '.$b['available'].')', $chk['bad'])).'.');
        }
        $line->update(['time' => $time]);

        return self::ok();
    }

    /** Replace a cart line's per-day schedule (rows with qty 0 are kept so the customer can edit them). */
    public static function updateCartLine(User $u, CartLine $line, array $days): array
    {
        if ($line->user_id !== $u->id) {
            return self::fail('Not your rental list.');
        }
        $clean = [];
        foreach ($days as $d => $q) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) {
                $clean[$d] = max(0, (int) $q);
            }
        }
        if (count($clean) > (int) Settings::get('maxRentalDays', 30)) {
            return self::fail('Maximum of '.Settings::get('maxRentalDays', 30).' rental dates per item.');
        }
        ksort($clean);
        if ($e = self::leadTimeError(array_merge($line->toLine(), ['days' => $clean]))) {
            return self::fail($e);
        }
        $line->update(['days' => $clean]);

        return self::ok();
    }

    public static function cleanDays(array $days): array
    {
        $out = [];
        foreach ($days as $d => $q) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) && (int) $q > 0) {
                $out[$d] = (int) $q;
            }
        }
        ksort($out);

        return $out;
    }

    /** Lock the product rows behind some lines (packages expand) so concurrent bookings serialize. */
    private static function lockProducts(array $lines): void
    {
        $ids = [];
        foreach ($lines as $l) {
            foreach (array_keys($l['days'] ?? []) as $d) {
                foreach (array_keys(inv()->unitsForLine($l, $d)) as $pid) {
                    $ids[$pid] = true;
                }
            }
            if (($l['type'] ?? 'item') === 'item') {
                $ids[(int) $l['ref_id']] = true;
            }
        }
        if ($ids) {
            Product::whereIn('id', array_keys($ids))->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    /* ================= RENTAL REQUESTS ================= */

    /**
     * Inventory source allocation (multi-location): each line is assigned to ONE location; a single location is
     * preferred, otherwise the line is split by quantity across locations. The supplier reviews it before accepting.
     */
    public static function autoAllocate(array $lines, Supplier $s, ?int $excludeId = null): array
    {
        $locs = $s->locations()->pluck('id')->all();
        if (! $locs) {
            return self::ok(['lines' => array_map(fn ($l) => array_merge($l, ['sourceId' => null]), $lines)]);
        }
        $used = [];
        $perUnit = function (array $l) {
            if ($l['type'] === 'package') {
                $def = inv()->lineDef($l);

                return $def ? Packages::units($def, $l['sel'] ?? null)['rent'] : [];
            }

            return inv()->product($l['ref_id'])?->isSale() ? [] : [(int) $l['ref_id'] => 1];
        };
        $freeAt = function (array $l, int $loc, string $d) use (&$used, $perUnit, $excludeId) {
            $win = inv()->lineWindow($l, $d);
            $min = PHP_INT_MAX;
            foreach ($perUnit($l) as $pid => $k) {
                $min = min($min, intdiv(inv()->availableAtLocation($pid, $loc, $d, $excludeId, $win) - ($used[$pid.'|'.$loc.'|'.$d] ?? 0), max(1, $k)));
            }

            return $min;
        };
        $take = function (array $l, int $loc, string $d, int $q) use (&$used, $perUnit) {
            foreach ($perUnit($l) as $pid => $k) {
                $used[$pid.'|'.$loc.'|'.$d] = ($used[$pid.'|'.$loc.'|'.$d] ?? 0) + $q * $k;
            }
        };
        $out = [];
        foreach ($lines as $l) {
            $dates = array_keys(array_filter($l['days'], fn ($q) => (int) $q > 0));
            sort($dates);
            if (! $perUnit($l)) { // sale-only line: no location needed
                $out[] = array_merge($l, ['sourceId' => null]);

                continue;
            }
            $fits = [];
            foreach ($locs as $loc) {
                $slack = min(array_map(fn ($d) => $freeAt($l, $loc, $d) - (int) $l['days'][$d], $dates));
                if ($slack >= 0) {
                    $fits[] = ['loc' => $loc, 'slack' => $slack];
                }
            }
            usort($fits, fn ($a, $b) => $b['slack'] <=> $a['slack']);
            $pref = ! empty($l['sourceId']) ? collect($fits)->firstWhere('loc', (int) $l['sourceId']) : null;
            if ($fits) {
                $loc = ($pref ?? $fits[0])['loc'];
                foreach ($dates as $d) {
                    $take($l, $loc, $d, (int) $l['days'][$d]);
                }
                $out[] = array_merge($l, ['sourceId' => $loc]);

                continue;
            }
            $parts = [];
            foreach ($dates as $d) {
                $rest = (int) $l['days'][$d];
                $order = array_map(fn ($loc) => ['id' => $loc, 'free' => $freeAt($l, $loc, $d)], $locs);
                usort($order, fn ($a, $b) => $b['free'] <=> $a['free']);
                foreach ($order as $o) {
                    if ($rest <= 0) {
                        break;
                    }
                    $q = min($rest, max(0, $o['free']));
                    if ($q > 0) {
                        $parts[$o['id']][$d] = $q;
                        $take($l, $o['id'], $d, $q);
                        $rest -= $q;
                    }
                }
                if ($rest > 0) {
                    return self::fail('Not enough '.inv()->itemName($l).' across the supplier\'s inventory locations on '.fmt_date($d).'.');
                }
            }
            $i = 0;
            foreach ($parts as $loc => $pdays) {
                $out[] = array_merge($l, ['id' => $l['id'].($i ? '-'.($i + 1) : ''), 'days' => $pdays, 'sourceId' => $loc, 'splitFrom' => $l['id']]);
                $i++;
            }
        }

        return self::ok(['lines' => $out]);
    }

    /** Quantities per item and date — proves the checkout did not change what was in the Rental List. */
    private static function qtyFingerprint(array $lines): array
    {
        $m = [];
        foreach ($lines as $l) {
            foreach ($l['days'] ?? [] as $d => $q) {
                if ((int) $q) {
                    $k = $l['type'].':'.$l['ref_id'].':'.$d;
                    $m[$k] = ($m[$k] ?? 0) + (int) $q;
                }
            }
        }
        ksort($m);

        return $m;
    }

    /**
     * Validate one supplier order and build the request attributes (nothing saved). $o = [supplier_id,
     * cart_line_ids, lines?, eventName, eventType, eventDate, fulfillment, destinations, contactName,
     * contactPhone, instructions, plan, method, services, installments].
     */
    public static function prepareOrder(User $u, array $o): array
    {
        $s = Supplier::find($o['supplier_id'] ?? 0);
        if (! $s || $s->status !== 'approved') {
            return self::fail('This supplier is not available.');
        }
        if ($s->owner_id === $u->id) {
            return self::fail('You cannot rent from your own shop.');
        }
        $src = array_values(array_filter(self::cartLinesFor($u, $s->id), fn ($l) => in_array($l['id'], array_map('intval', $o['cart_line_ids'] ?? []), true)));
        if (! $src) {
            return self::fail('There are no selected items for '.$s->name.' in your rental list.');
        }
        // the checkout may split items between delivery locations or change their time, but never the quantities
        $byCart = collect($src)->keyBy('id');
        $lines = [];
        foreach (($o['lines'] ?? null) ?: $src as $i => $l) {
            $base = isset($l['cart_id']) ? ($byCart[(int) $l['cart_id']] ?? null) : $l;
            if (! $base) {
                return self::fail('An item in your checkout is no longer in your Rental List.');
            }
            $days = self::cleanDays($l['days'] ?? []);
            if (! $days) {
                continue;
            }
            $line = ['id' => 'l'.($i + 1), 'type' => $base['type'], 'ref_id' => (int) $base['ref_id'], 'days' => $days];
            if ($t = Slots::time(['time' => $l['time'] ?? ($base['time'] ?? null)])) {
                $line['time'] = $t;
            }
            $line['destId'] = $l['destId'] ?? null;
            if ($base['type'] === 'package' && ($k = Package::find($base['ref_id']))) {
                $line['snap'] = Packages::snapshot($k);
                $line['sel'] = Packages::cleanSel(Packages::def($k), $base['sel'] ?? null);
            }
            $lines[] = $line;
        }
        if (! $lines) {
            return self::fail('Set a quantity for at least one date.');
        }
        if (self::qtyFingerprint($src) !== self::qtyFingerprint($lines)) {
            return self::fail('The quantities assigned to your delivery locations must add up to exactly what is in your Rental List for '.$s->name.'.');
        }
        foreach ($lines as $l) {
            $it = $l['type'] === 'package' ? Package::find($l['ref_id']) : Product::find($l['ref_id']);
            if (! $it || $it->supplier_id !== $s->id || ! self::listed($it)) {
                return self::fail(($it->name ?? 'An item').' is no longer available from '.$s->name.'.');
            }
            if ($e = self::leadTimeError($l)) {
                return self::fail($e.' Please update the date in your Rental List.');
            }
        }
        if ($t = Slots::timeIssues($lines)) {
            return self::fail($t[0], ['timeIssues' => $t]);
        }
        if ($dur = self::durationIssues($lines)) {
            return self::fail($dur[0]['message'], ['durationIssues' => $dur]);
        }
        $allDates = collect($lines)->flatMap(fn ($l) => array_keys($l['days']))->unique()->sort()->values()->all();
        if ($allDates[0] < today_iso()) {
            return self::fail('Some dates in your list are in the past. Please update them in your Rental List.');
        }
        $chk = inv()->check($lines);
        if (! $chk['ok']) {
            return self::fail($s->name.': some quantities exceed the available stock for the selected dates and times. Please adjust them.', ['check' => $chk]);
        }
        // fulfillment & delivery locations
        $fulfillment = ($o['fulfillment'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
        if ($fulfillment === 'delivery' && $s->fulfillment === 'pickup') {
            return self::fail($s->name.' offers pickup only.');
        }
        if ($fulfillment === 'pickup' && $s->fulfillment === 'delivery') {
            return self::fail($s->name.' offers delivery only.');
        }
        $zoneIds = array_column($s->delivery_zones ?? [], 'id');
        $destinations = [];
        if ($fulfillment === 'delivery') {
            foreach (array_values($o['destinations'] ?? []) as $i => $d) {
                $destinations[] = [
                    'id' => (string) (($d['id'] ?? '') ?: 'd'.($i + 1)), 'label' => trim((string) (($d['label'] ?? '') ?: 'Location '.($i + 1))),
                    'address' => trim((string) ($d['address'] ?? '')), 'zoneId' => in_array($d['zoneId'] ?? '', $zoneIds, true) ? $d['zoneId'] : '',
                    'deliveryTime' => (string) ($d['deliveryTime'] ?? ''), 'contactName' => trim((string) ($d['contactName'] ?? '')), 'contactPhone' => trim((string) ($d['contactPhone'] ?? '')),
                ];
            }
            if (! $destinations) {
                return self::fail('Add a delivery address.');
            }
            foreach ($destinations as $d) {
                if (mb_strlen($d['address']) < 8) {
                    return self::fail('Enter the complete address for "'.$d['label'].'".');
                }
            }
            $ids = array_column($destinations, 'id');
            if (count($destinations) === 1) {
                foreach ($lines as &$l) {
                    $l['destId'] = $l['destId'] ?: $ids[0];
                }
                unset($l);
            }
            foreach ($lines as $l) {
                if (! in_array($l['destId'], $ids, true)) {
                    return self::fail('Assign every item to one of your delivery locations.');
                }
            }
            foreach ($destinations as $d) {
                if (! collect($lines)->contains(fn ($l) => $l['destId'] === $d['id'])) {
                    return self::fail('"'.$d['label'].'" has no items assigned. Assign items to it or remove it.');
                }
            }
        } else {
            foreach ($lines as &$l) {
                $l['destId'] = null;
            }
            unset($l);
        }
        // payment plan & method
        $plans = Finance::plansOf($s);
        $plan = in_array($o['plan'] ?? null, $plans, true) ? $o['plan'] : null;
        if (! $plan) {
            return self::fail($s->name.' does not offer the selected payment plan. Choose one of: '.implode(', ', array_map(fn ($k) => Finance::PLAN_LABEL[$k], $plans)).'.');
        }
        $method = $o['method'] ?? 'online';
        if ($method !== 'online' && ! in_array($method, $s->methods ?? [], true)) {
            return self::fail($s->name.' does not accept that payment method.');
        }
        $eventDate = ($o['eventDate'] ?? null) ?: $allDates[0];
        if ($eventDate < $allDates[0] || $eventDate > end($allDates)) {
            return self::fail($s->name.': the event date must fall within the rental dates ('.fmt_range($allDates[0], end($allDates)).').');
        }
        $eventName = trim((string) ($o['eventName'] ?? ''));
        if (mb_strlen($eventName) < 3) {
            return self::fail('Enter the event name.');
        }
        $services = array_values(array_intersect($o['services'] ?? [], array_column($s->services ?? [], 'id')));
        $attrs = [
            'customer_id' => $u->id, 'supplier_id' => $s->id, 'event_name' => $eventName, 'event_type' => $o['eventType'] ?? 'Other', 'event_date' => $eventDate,
            'start_date' => $allDates[0], 'end_date' => end($allDates), 'fulfillment' => $fulfillment, 'destinations' => $destinations,
            'address' => $destinations[0]['address'] ?? '', 'contact_name' => $o['contactName'] ?? $u->name, 'contact_phone' => $o['contactPhone'] ?? $u->phone,
            'instructions' => $o['instructions'] ?? '', 'lines' => $lines, 'plan' => $plan, 'arrangement' => $plan, 'method' => $method, 'services' => $services,
            'status' => 'pending', 'supplier_notes' => '',
        ];
        $probe = new RentalRequest($attrs);
        $probe->setRelation('supplier', $s);
        $pricing = Pricing::quoteRequest($probe);
        if ($plan === 'installment') {
            $el = Finance::installmentEligibility($s, $eventDate);
            if (! $el['ok']) {
                return self::fail($s->name.': '.$el['message']);
            }
            $items = array_map(fn ($x) => ['due' => (string) ($x['due'] ?? ''), 'amount' => r2((float) ($x['amount'] ?? 0))], array_values($o['installments'] ?? []));
            if ($err = Finance::validateInstallments($items, $pricing['finalBalance'], $eventDate, add_days(today_iso(), 1), (int) Finance::planConfig($s)['installment']['maxInstallments'])) {
                return self::fail($s->name.': '.$err);
            }
            $attrs['installment_plan'] = ['status' => 'proposed', 'items' => $items, 'proposedAt' => now()->toIso8601String()];
        }
        $alloc = self::autoAllocate($lines, $s);
        if (! $alloc['ok']) {
            return $alloc;
        }
        $attrs['lines'] = $alloc['lines'];
        $attrs['allocation'] = ['status' => 'proposed'];
        $attrs['pricing'] = $pricing;

        return self::ok(['attrs' => $attrs, 'supplier' => $s, 'sourceIds' => array_column($src, 'id')]);
    }

    /**
     * Submit one or more supplier orders from a single checkout (Services.submitOrders). Everything is validated
     * inside ONE transaction that locks the products involved; then each supplier gets its own request.
     */
    public static function submitOrders(User $u, array $orders): array
    {
        if (! $orders) {
            return self::fail('Select at least one item to check out.');
        }
        $result = DB::transaction(function () use ($u, $orders) {
            foreach ($orders as $o) {
                self::lockProducts(self::cartLinesFor($u, (int) ($o['supplier_id'] ?? 0)));
            }
            inv()->flush();
            $prepared = [];
            foreach ($orders as $o) {
                $p = self::prepareOrder($u, $o);
                if (! $p['ok']) {
                    return $p + ['supplier_id' => $o['supplier_id'] ?? null];
                }
                $prepared[] = $p;
            }
            $group = count($prepared) > 1 ? 'ORD-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)) : null;
            $created = [];
            foreach ($prepared as $p) {
                $r = new RentalRequest($p['attrs'] + ['code' => 'PR-'.str_pad((string) Settings::nextSeq('request'), 4, '0', STR_PAD_LEFT), 'order_group' => $group]);
                $r->save();
                self::history($r, 'pending', 'Request submitted');
                CartLine::where('user_id', $u->id)->whereIn('id', $p['sourceIds'])->delete(); // unselected items stay in the list
                BookingDays::sync($r);
                $created[] = [$r, $p['supplier']];
            }

            return self::ok(['created' => $created]);
        });
        if (! $result['ok']) {
            return $result;
        }
        inv()->flush();
        $requests = [];
        foreach ($result['created'] as [$r, $s]) {
            $conflicts = array_filter(inv()->check($r->lines)['rows'], fn ($x) => ! empty($x['product_id']) && inv()->pendingOn($x['product_id'], $x['date'], $r->id) + $x['requested'] > $x['available']);
            Notifier::notify($s->owner_id, 'supplier', 'New rental request', $u->name.' sent '.$r->code.' ('.$r->event_name.') for '.fmt_range($r->start_date, $r->end_date).' · '.Finance::PLAN_LABEL[$r->plan].(count($r->destinations ?? []) > 1 ? ' · '.count($r->destinations).' delivery locations' : '').'.'.($conflicts ? ' ⚠ Overlaps other pending requests.' : '').($r->installment_plan ? ' Installment schedule needs your review.' : ''), self::link('supplier.requests.show', $r));
            Notifier::notify($u, 'customer', 'Rental request submitted', $r->code.' was sent to '.$s->name.' and is pending review. You will be notified once they respond.', self::link('rentals.show', $r));
            $requests[] = $r;
        }

        return self::ok(['requests' => $requests, 'request' => $requests[0]]);
    }

    /** Single-supplier submission (all of that supplier's selected items in the Rental List). */
    public static function submitRequest(User $u, Supplier $s, array $form): array
    {
        $ids = array_column(array_filter(self::cartLinesFor($u, $s->id), fn ($l) => $l['selected'] ?? true), 'id');
        if (! $ids) {
            return self::fail('There are no items for this supplier in your rental list.');
        }

        return self::submitOrders($u, [array_merge($form, ['supplier_id' => $s->id, 'cart_line_ids' => $ids])]);
    }

    /** Supplier changes the inventory source of one line (before the items are released). */
    public static function setLineSource(RentalRequest $r, string $lineId, int $locId): array
    {
        if (! in_array($r->status, ['pending', 'accepted', 'in_progress'], true)) {
            return self::fail('The allocation can only be changed before the items are released.');
        }
        $loc = $r->supplier->locations()->find($locId);
        if (! $loc) {
            return self::fail('Unknown inventory location.');
        }
        $lines = $r->lines;
        $idx = collect($lines)->search(fn ($l) => $l['id'] === $lineId);
        if ($idx === false) {
            return self::fail('Line not found.');
        }
        $fromId = $lines[$idx]['sourceId'] ?? null;
        $lines[$idx]['sourceId'] = $locId;
        $chk = inv()->check($lines, $r->id);
        $locBad = array_filter($chk['bad'], fn ($b) => ($b['location_id'] ?? null) === $locId);
        if ($locBad) {
            return self::fail('Not enough units at '.$loc->name.': '.implode('; ', array_map(fn ($b) => $b['name'].' on '.fmt_date($b['date']).' (available '.$b['available'].', needed '.$b['requested'].')', $locBad)).'.');
        }
        $r->update(['lines' => $lines]);
        BookingDays::sync($r);
        $from = $fromId ? $r->supplier->locations()->find($fromId)?->name : '—';
        self::history($r, $r->status, 'Inventory source for '.inv()->itemName($lines[$idx]).' changed: '.$from.' → '.$loc->name);
        self::changed($r);
        Notifier::audit('Changed inventory allocation', $r->code.' · '.inv()->itemName($lines[$idx]).' → '.$loc->name);

        return self::ok();
    }

    /** Customer (re)proposes an installment schedule; the supplier approves or rejects it. */
    public static function proposeInstallmentPlan(RentalRequest $r, User $by, array $items): array
    {
        if ($r->customer_id !== $by->id) {
            return self::fail('Request not found.');
        }
        if ($r->plan !== 'installment') {
            return self::fail('This order does not use the Installment Payment Plan.');
        }
        if (($r->installment_plan['status'] ?? null) === 'approved') {
            return self::fail('The installment schedule is already approved.');
        }
        if (! in_array($r->status, array_merge(['pending'], Finance::ACTIVE), true)) {
            return self::fail('This order is closed.');
        }
        $items = array_map(fn ($x) => ['due' => (string) ($x['due'] ?? ''), 'amount' => r2((float) ($x['amount'] ?? 0))], array_values($items));
        if ($err = Finance::validateInstallments($items, (float) $r->pricing['finalBalance'], $r->event_date, add_days(today_iso(), 1), (int) Finance::planConfig($r->supplier)['installment']['maxInstallments'])) {
            return self::fail($err);
        }
        $prev = $r->installment_plan;
        $history = array_merge($prev['history'] ?? [], $prev ? [['status' => $prev['status'], 'reason' => $prev['reason'] ?? '', 'at' => $prev['reviewedAt'] ?? $prev['proposedAt'] ?? null]] : []);
        $r->update(['installment_plan' => ['status' => 'proposed', 'items' => $items, 'proposedAt' => now()->toIso8601String(), 'history' => $history]]);
        self::history($r, $r->status, 'Installment schedule proposed ('.count($items).' payments)');
        Notifier::notify($r->supplier->owner_id, 'supplier', 'Installment schedule to review', $by->name.' proposed '.count($items).' installment(s) for '.$r->code.'.', self::link('supplier.requests.show', $r));

        return self::ok();
    }

    public static function reviewInstallmentPlan(RentalRequest $r, bool $approve, ?string $reason, User $by): array
    {
        $ip = $r->installment_plan;
        if (! $ip || ($ip['status'] ?? '') !== 'proposed') {
            return self::fail('There is no installment schedule waiting for review.');
        }
        $reason = trim((string) $reason);
        if (! $approve && $reason === '') {
            return self::fail('Give the customer a reason so they can propose a new schedule.');
        }
        if ($approve && ($err = Finance::validateInstallments($ip['items'], (float) $r->pricing['finalBalance'], $r->event_date))) {
            return self::fail($err);
        }
        $ip = array_merge($ip, ['status' => $approve ? 'approved' : 'rejected', 'reason' => $approve ? '' : $reason, 'reviewedAt' => now()->toIso8601String(), 'reviewedBy' => $by->id]);
        $r->update(['installment_plan' => $ip]);
        self::history($r, $r->status, $approve ? 'Installment schedule approved' : 'Installment schedule rejected: '.$reason);
        self::changed($r);
        $last = end($ip['items']);
        Notifier::notify($r->customer_id, 'customer', $approve ? 'Installment schedule approved' : 'Installment schedule rejected', $approve
            ? $r->supplier->name.' approved your '.count($ip['items']).'-payment schedule for '.$r->code.'. The last installment is due '.fmt_date($last['due']).'.'
            : 'Your installment schedule for '.$r->code.' was not approved: '.$reason.' Please propose a new one.', self::link('rentals.show', $r));
        if ($approve) {
            Notifier::finance($r->supplier, 'Installment schedule approved', $r->code.' now has '.count($ip['items']).' scheduled installments.', self::link('cashier.balances'));
        }
        Notifier::audit($approve ? 'Approved installment schedule' : 'Rejected installment schedule', $r->code.($approve ? ' · '.count($ip['items']).' installments' : ' — '.$reason));

        return self::ok();
    }

    /** Customer cancellation, following the supplier's cancellation policy. */
    public static function cancelPolicy(RentalRequest $r): array
    {
        $s = $r->supplier;
        $days = (int) $s->pol('cancelDays', 0);
        if ($r->status === 'pending') {
            return ['allowed' => true, 'free' => true, 'note' => 'This request has not been accepted yet, so it can be cancelled at no cost.'];
        }
        if (in_array($r->status, ['accepted', 'in_progress'], true)) {
            $deadline = add_days($r->start_date, -$days);
            $free = today_iso() <= $deadline;
            $sales = (float) ($r->pricing['salesSubtotal'] ?? 0);
            $saleLocked = $sales > 0 && $r->status === 'in_progress';
            $saleNote = $saleLocked ? ' Items for sale ('.money($sales).') are no longer refundable because the supplier has started preparing them.' : '';

            return ['allowed' => true, 'free' => $free && ! $saleLocked, 'deadline' => $deadline, 'nonRefundable' => $saleLocked ? $sales : 0.0, 'note' => ($free
                ? 'Free cancellation until '.fmt_date($deadline).'. Verified payments will be refunded through PartyRent.'
                : 'The free cancellation period ended on '.fmt_date($deadline).'. Per policy: '.$s->pol('cancellation', '')).$saleNote];
        }

        return ['allowed' => false, 'free' => false, 'note' => 'Items have already been released or the rental is closed, so it can no longer be cancelled online. Please contact the supplier.'];
    }

    public static function cancelRequest(RentalRequest $r, string $reason): array
    {
        $pol = self::cancelPolicy($r);
        if (! $pol['allowed'] || in_array($r->status, ['declined', 'cancelled', 'completed'], true)) {
            return self::fail($pol['note']);
        }
        $r->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancel_free' => $pol['free']]);
        self::history($r, 'cancelled', $reason);
        self::changed($r);
        $fin = $r->fin();
        Notifier::notify($r->supplier->owner_id, 'supplier', 'Customer cancellation', $r->customer->name.' cancelled '.$r->code.'. '.($fin['paidRental'] > 0 ? 'Verified payments of '.money($fin['paidRental']).' need refund review.' : 'No payments were made.'), self::link('supplier.requests.show', $r));
        if ($fin['paidRental'] > 0) {
            Notifier::finance($r->supplier, 'Refund review', $r->code.' was cancelled by the customer with '.money($fin['paidRental']).' paid. Record any refund due.', self::link('cashier.record', ['request' => $r->code]));
        }
        Notifier::audit('Cancelled rental request', $r->code.' — '.($pol['free'] ? 'within free period' : 'after free period').($reason ? ' · '.$reason : ''));

        return self::ok();
    }

    /** Acceptance re-checks inventory for EVERY date, validates pricing and policy (Services.acceptReview). */
    public static function acceptReview(RentalRequest $r): array
    {
        $chk = inv()->check($r->lines, $r->id);
        $fresh = Pricing::quoteRequest($r);
        $s = $r->supplier;
        $issues = [];
        if (! in_array($r->plan, Finance::plansOf($s), true)) {
            $issues[] = '"'.Finance::PLAN_LABEL[$r->plan].'" is no longer enabled in your settings (the customer\'s quoted terms are still honored).';
        }
        if ($r->plan === 'installment' && ($r->installment_plan['status'] ?? null) !== 'approved') {
            $issues[] = 'The installment schedule is '.($r->installment_plan['status'] ?? 'missing').' — review it so the customer knows the due dates.';
        }
        if ($r->method !== 'online' && ! in_array($r->method, $s->methods ?? [], true)) {
            $issues[] = 'Payment method "'.(Finance::METHOD_LABEL[$r->method] ?? $r->method).'" is no longer accepted.';
        }

        return [
            'check' => $chk, 'fresh' => $fresh, 'priceChanged' => abs($fresh['total'] - (float) $r->pricing['total']) > 0.009,
            'policyIssues' => $issues, 'durationNotes' => self::durationIssues($r->lines),
            'canAccept' => $chk['ok'] && $r->status === 'pending',
        ];
    }

    public static function acceptRequest(RentalRequest $r, ?string $note, ?User $by = null): array
    {
        $by ??= auth()->user();
        $result = DB::transaction(function () use ($r, $note, $by) {
            self::lockProducts($r->lines);
            $r->refresh();
            if ($r->status !== 'pending') {
                return self::fail('Only pending requests can be accepted.');
            }
            inv()->flush();
            $rev = self::acceptReview($r);
            if (! $rev['check']['ok']) {
                $locName = fn ($id) => $r->supplier->locations()->find($id)?->name;

                return self::fail('Not enough stock on '.implode('; ', array_map(fn ($b) => fmt_date($b['date']).' ('.$b['name'].(! empty($b['location_id']) ? ' at '.$locName($b['location_id']) : '').': need '.$b['requested'].', available '.$b['available'].')', $rev['check']['bad'])), ['conflict' => $rev['check']['bad'], 'review' => $rev]);
            }
            $r->update(['status' => 'accepted', 'accepted_at' => today_iso(), 'supplier_notes' => $note ?: $r->supplier_notes,
                'allocation' => ['status' => 'approved', 'at' => now()->toIso8601String(), 'by' => $by?->id]]);
            self::history($r, 'accepted', $note ?: 'Request accepted');

            return self::ok();
        });
        if (! $result['ok']) {
            if (! empty($result['conflict'])) {
                Notifier::notify($r->supplier->owner_id, 'supplier', 'Inventory conflict', $r->code.' cannot be accepted — stock is insufficient on '.implode(', ', array_unique(array_map(fn ($b) => fmt_date($b['date']), $result['conflict']))).'.', self::link('supplier.requests.show', $r));
            }

            return $result;
        }
        self::changed($r);
        $first = $r->fresh()->fin()['schedule'][0] ?? null;
        Notifier::notify($r->customer_id, 'customer', 'Request accepted 🎉', $r->supplier->name.' accepted '.$r->code.'. '.($first ? $first['label'].' of '.money($first['amount']).' is due by '.fmt_date($first['due']).'.' : ''), self::link('rentals.show', $r));
        Notifier::audit('Accepted rental request', $r->code.' · '.money($r->pricing['total']));

        return self::ok();
    }

    public static function declineRequest(RentalRequest $r, string $reason): array
    {
        if ($r->status !== 'pending') {
            return self::fail('Only pending requests can be declined.');
        }
        $r->update(['status' => 'declined', 'decline_reason' => $reason]);
        self::history($r, 'declined', $reason);
        self::changed($r);
        $paid = $r->fin()['paidRental'];
        Notifier::notify($r->customer_id, 'customer', 'Request declined', $r->supplier->name.' declined '.$r->code.($reason ? ': '.$reason : '.').($paid > 0 ? ' Your '.money($paid).' will be refunded in full.' : ''), self::link('rentals.show', $r));
        if ($paid > 0) {
            Notifier::finance($r->supplier, 'Refund due', $r->code.' was declined. Refund the customer\'s '.money($paid).' in full.', self::link('cashier.record', ['request' => $r->code]));
        }
        Notifier::audit('Declined rental request', $r->code.($reason ? ' — '.$reason : ''));

        return self::ok();
    }

    /** Allowed next steps for a request (respecting delivery vs pickup). */
    public static function nextSteps(RentalRequest $r): array
    {
        return array_values(array_filter(self::FLOW[$r->status] ?? [], fn ($st) => ! ($st === 'ready_pickup' && $r->fulfillment !== 'pickup') && ! ($st === 'out_delivery' && $r->fulfillment !== 'delivery')));
    }

    public static function advance(RentalRequest $r, string $to, array $data = []): array
    {
        if (! in_array($to, self::FLOW[$r->status] ?? [], true)) {
            return self::fail('This step is not allowed from "'.RentalRequest::statusLabel($r->status).'".');
        }
        if ($to === 'ready_pickup' && $r->fulfillment !== 'pickup') {
            return self::fail('This rental is for delivery.');
        }
        if ($to === 'out_delivery' && $r->fulfillment !== 'delivery') {
            return self::fail('This rental is for pickup.');
        }
        if (in_array($to, ['inspection', 'completed'], true)) {
            return self::fail('Use the inspection / completion action for this step.');
        }
        $log = $r->fulfillment_log ?? [];
        if ($to === 'ongoing') {
            if (empty($data['receivedBy'])) {
                return self::fail('Enter the name of the person receiving the items.');
            }
            $log = array_merge($log, ['releaseDate' => $data['releaseDate'] ?? today_iso(), 'releaseQty' => $data['releaseQty'] ?? '', 'receivedBy' => $data['receivedBy'], 'method' => $r->fulfillment]);
        }
        $low = [];
        DB::transaction(function () use ($r, $to, $data, &$log, &$low) {
            if ($to === 'ongoing' && empty($log['saleDeducted'])) {
                $low = self::deductSaleStock($r);
                $log['saleDeducted'] = true;
            }
            $r->update(['status' => $to, 'fulfillment_log' => $log ?: null]);
            self::history($r, $to, $data['note'] ?? null);
        });
        self::changed($r);
        foreach ($low as $p) {
            self::lowStockAlert($p);
        }
        $msgs = [
            'in_progress' => 'is being prepared',
            'ready_pickup' => 'is ready for pickup at '.$r->supplier->address,
            'out_delivery' => 'is out for delivery',
            'ongoing' => 'has been released — enjoy your event!',
            'return_pending' => 'is due for return. Please prepare the items for pickup/return.',
        ];
        if (isset($msgs[$to])) {
            $title = $to === 'return_pending' ? 'Return reminder' : ($r->fulfillment === 'delivery' ? 'Delivery update' : 'Pickup update');
            Notifier::notify($r->customer_id, 'customer', $title, $r->code.' '.$msgs[$to].'.', self::link('rentals.show', $r));
        }

        return self::ok();
    }

    /**
     * Released to the customer: consumables are gone for good. Deduct them from stock on hand and log
     * a 'sold' movement for each. Runs once per request. Returns products that dropped to low stock.
     */
    private static function deductSaleStock(RentalRequest $r): array
    {
        $low = [];
        foreach (inv()->saleDemand($r->lines ?? []) as $pid => $qty) {
            $p = Product::lockForUpdate()->find($pid);
            if (! $p || ! $p->isSale()) {
                continue;
            }
            $wasLow = $p->isLowStock();
            $p->total_stock = max(0, $p->stockOnHand() - $qty);
            $p->save();
            StockMovement::create(['product_id' => $p->id, 'qty' => -$qty, 'type' => 'sold', 'rental_request_id' => $r->id, 'user_id' => auth()->id(), 'balance' => $p->stockOnHand(), 'note' => 'Released with '.$r->code, 'created_at' => now()]);
            if (! $wasLow && $p->isLowStock()) {
                $low[] = $p;
            }
        }

        return $low;
    }

    private static function lowStockAlert(Product $p): void
    {
        Notifier::notify($p->supplier->owner_id, 'supplier', 'Low stock: '.$p->name, 'Only '.$p->stockOnHand().' '.$p->unitWord().' left (alert at '.$p->low_stock_at.'). Restock it from Inventory so new orders can be accepted.', self::link('supplier.inventory'));
    }

    /** Supplier adds stock on hand to a sale item. */
    public static function restock(Product $p, int $qty, string $note = ''): array
    {
        if (! $p->isSale()) {
            return self::fail('Only items for sale are restocked. Change a rental item\'s total stock in the product form.');
        }
        if ($qty < 1 || $qty > 100000) {
            return self::fail('Enter a quantity from 1 to 100,000.');
        }
        DB::transaction(function () use ($p, $qty, $note) {
            $p->refresh();
            $p->total_stock = $p->stockOnHand() + $qty;
            $p->save();
            StockMovement::create(['product_id' => $p->id, 'qty' => $qty, 'type' => 'restock', 'user_id' => auth()->id(), 'balance' => $p->stockOnHand(), 'note' => $note, 'created_at' => now()]);
        });
        inv()->flush();
        Notifier::audit('Restocked item', $p->name.' +'.$qty.' → '.$p->stockOnHand());

        return self::ok(['product' => $p]);
    }

    /** Peak released quantity per product across the rental dates. */
    public static function peakUnits(RentalRequest $r): array
    {
        $peak = [];
        foreach (inv()->demandMap($r->lines) as $m) {
            foreach ($m as $pid => $q) {
                $peak[$pid] = max($peak[$pid] ?? 0, $q);
            }
        }

        return $peak;
    }

    /** Return inspection: updates inventory counters by condition and records compensation. */
    public static function recordInspection(RentalRequest $r, array $data): array
    {
        if ($r->status !== 'return_pending') {
            return self::fail('Inspection can only be recorded when the rental is Return Pending.');
        }
        $peak = self::peakUnits($r);
        $items = [];
        foreach ($peak as $pid => $released) {
            $it = $data['items'][$pid] ?? [];
            $g = fn ($k) => max(0, (int) ($it[$k] ?? 0));
            $row = ['productId' => $pid, 'returned' => $g('returned'), 'good' => $g('good'), 'minor' => $g('minor'), 'major' => $g('major'), 'total' => $g('total'), 'missing' => $g('missing'), 'repairFee' => max(0, (float) ($it['repairFee'] ?? 0)), 'replaceFee' => max(0, (float) ($it['replaceFee'] ?? 0))];
            $name = inv()->product($pid)?->name ?? 'Item';
            if ($row['good'] + $row['minor'] + $row['major'] + $row['total'] !== $row['returned']) {
                return self::fail($name.': good + minor + major + completely damaged must equal returned ('.$row['returned'].').');
            }
            if ($row['returned'] + $row['missing'] !== $released) {
                return self::fail($name.': returned + missing must equal released quantity ('.$released.').');
            }
            $value = (float) (inv()->product($pid)?->replacement_value ?? 0);
            $affected = $row['minor'] + $row['major'] + $row['total'] + $row['missing'];
            if ($value > 0 && $row['repairFee'] + $row['replaceFee'] > $value * $affected + 0.001) {
                return self::fail($name.': charges can be at most the replacement value ('.money($value).' × '.$affected.' damaged or missing = '.money($value * $affected).').');
            }
            $items[] = $row;
        }
        $comp = 0.0;
        DB::transaction(function () use ($r, $items, $data, &$comp) {
            foreach ($items as $it) {
                $p = Product::lockForUpdate()->find($it['productId']);
                if (! $p) {
                    continue;
                }
                $p->damaged += $it['major'] + $it['total'];
                $p->missing += $it['missing'];
                $p->save();
                if ($it['minor']) {
                    Hold::create(['product_id' => $p->id, 'type' => 'maintenance', 'qty' => $it['minor'], 'start_date' => today_iso(), 'end_date' => add_days(today_iso(), 3), 'note' => 'Minor damage from '.$r->code.' — for repair']);
                }
                $comp += $it['repairFee'] + $it['replaceFee'];
            }
            $summary = implode(', ', array_map(fn ($i) => $i['returned'].'× '.(inv()->product($i['productId'])?->name ?? ''), $items));
            $r->fulfillment_log = array_merge($r->fulfillment_log ?? [], ['returnDate' => $data['returnDate'] ?? today_iso(), 'returnedQty' => $summary]);
            $r->inspection = ['at' => now()->toIso8601String(), 'by' => auth()->id(), 'items' => $items, 'notes' => $data['notes'] ?? '', 'photos' => $data['photos'] ?? [], 'compensation' => r2($comp), 'settlement' => $comp > 0 ? 'pending' : 'settled'];
            $r->status = 'inspection';
            $r->save();
            self::history($r, 'inspection', $comp > 0 ? 'Damage/missing items recorded: '.money($comp) : 'All items returned in good condition');
        });
        self::changed($r);
        Notifier::notify($r->customer_id, 'customer', $comp > 0 ? 'Damage report' : 'Return inspection done', $comp > 0 ? 'The supplier recorded damaged/missing items for '.$r->code.'. Compensation: '.money($comp).'. View the report for details.' : 'All items for '.$r->code.' passed inspection.', self::link('rentals.show', $r));
        Notifier::audit('Recorded return inspection', $r->code.' · compensation '.money($comp));

        return self::ok();
    }

    /** Settle the deposit vs compensation (deducted | paid | waived) and record the deposit refund. */
    public static function settleDeposit(RentalRequest $r, string $mode): array
    {
        $f = $r->fin();
        if (! $r->inspection) {
            return self::fail('Record the return inspection first.');
        }
        if (! in_array($mode, ['deducted', 'paid', 'waived', 'settled'], true)) {
            return self::fail('Choose how to settle the deposit.');
        }
        if ($mode === 'paid' && $f['compensationDue'] > 0 && $f['compPaidDirect'] < $f['compensation']) {
            return self::fail('Compensation has not been paid yet. The customer must pay '.money($f['compensationDue']).' (verified by the cashier).');
        }
        $insp = $r->inspection;
        $insp['settlement'] = ($insp['compensation'] ?? 0) > 0 ? $mode : 'settled';
        $insp['settledAt'] = now()->toIso8601String();
        $r->update(['inspection' => $insp]);
        $r->load('payments');
        self::changed($r);
        $after = $r->fin();
        $refund = r2($after['depositHeld']);
        if ($refund > 0) {
            Payment::create([
                'code' => self::nextPaymentCode(), 'rental_request_id' => $r->id, 'type' => 'deposit_refund', 'method' => 'cash',
                'amount' => $refund, 'date' => today_iso(), 'submitted_at' => now(), 'reference' => 'RF-'.substr((string) now()->getTimestampMs(), -5),
                'status' => 'verified', 'verified_by' => auth()->id(), 'verified_at' => now(),
                'notes' => $mode === 'deducted' ? money(min($f['compensation'], $f['depositPaid'])).' deducted for damage' : 'Full deposit returned',
                'recorded_by' => 'supplier',
            ]);
            $r->load('payments');
            self::changed($r);
        }
        Notifier::notify($r->customer_id, 'customer', 'Refund update', 'Security deposit for '.$r->code.' settled. '.($refund > 0 ? money($refund).' returned to you.' : 'No refund due.').($mode === 'deducted' && $f['compensation'] ? ' '.money(min($f['compensation'], $f['depositPaid'])).' was applied to damages.' : ''), self::link('rentals.show', $r));
        Notifier::audit('Settled security deposit', $r->code.' · '.$mode.' · refund '.money($refund));

        return self::ok(['refund' => $refund]);
    }

    public static function completionChecklist(RentalRequest $r): array
    {
        $f = $r->fin();
        $insp = $r->inspection;

        return [
            ['label' => 'Returned items inspected', 'ok' => (bool) $insp, 'detail' => ''],
            ['label' => 'Rental balance fully paid', 'ok' => $f['balance'] <= 0, 'detail' => $f['balance'] > 0 ? money($f['balance']).' outstanding' : ''],
            ['label' => 'Compensation settled', 'ok' => ! $insp || (($insp['settlement'] ?? '') !== 'pending' && $f['compensationDue'] <= 0), 'detail' => $f['compensationDue'] > 0 ? money($f['compensationDue']).' due' : ''],
            ['label' => 'Security deposit resolved', 'ok' => $f['depositHeld'] <= 0, 'detail' => $f['depositHeld'] > 0 ? money($f['depositHeld']).' still held' : ''],
        ];
    }

    public static function completeRental(RentalRequest $r): array
    {
        if ($r->status !== 'inspection') {
            return self::fail('Record the return inspection before completing.');
        }
        $bad = array_filter(self::completionChecklist($r), fn ($c) => ! $c['ok']);
        if ($bad) {
            return self::fail('Cannot complete yet: '.implode('; ', array_map(fn ($b) => $b['label'].($b['detail'] ? ' ('.$b['detail'].')' : ''), $bad)));
        }
        $r->update(['status' => 'completed', 'completed_at' => today_iso()]);
        self::history($r, 'completed', 'Rental completed');
        foreach ($r->lines as $l) {
            if ($l['type'] === 'item') {
                Product::whereKey($l['ref_id'])->increment('rent_count');
            }
        }
        self::changed($r);
        Notifier::notify($r->customer_id, 'customer', 'Rental completed', $r->code.' is complete. Thank you for renting with '.$r->supplier->name.'! You can now leave a review.', self::link('rentals.show', $r));
        Notifier::audit('Completed rental', $r->code);
        // escrow: PartyRent releases the supplier's money now (skipped if a payment is still pending —
        // the request then waits in the admin's "awaiting release" list)
        $r->load('payments');
        $payout = Payouts::release($r);

        return self::ok(['payout' => $payout['payout'] ?? null]);
    }

    /* ================= PAYMENTS ================= */

    public static function nextPaymentCode(): string
    {
        return 'TXN-'.(10000 + Settings::nextSeq('payment'));
    }

    private static function issueReceipt(Payment $p): void
    {
        $p->receipt_no = 'OR-'.str_pad((string) Settings::nextSeq('receipt'), 5, '0', STR_PAD_LEFT);
    }

    public static function validatePayment(RentalRequest $r, array $data): ?string
    {
        $amt = (float) ($data['amount'] ?? 0);
        if (! ($amt > 0)) {
            return 'Enter an amount greater than zero.';
        }
        $f = $r->fin();
        if (in_array($data['type'], Finance::RENTAL_TYPES, true) && $amt > $f['balance'] - $f['pendingRental'] + 0.001) {
            return 'Amount exceeds the remaining balance ('.money(max(0, $f['balance'] - $f['pendingRental'])).' after pending submissions).';
        }
        if ($data['type'] === 'deposit' && $amt > $f['depositRequired'] - $f['depositPaid'] + 0.001) {
            return 'Deposit required is '.money(max(0, $f['depositRequired'] - $f['depositPaid'])).'.';
        }
        if ($data['type'] === 'compensation' && $amt > $f['compensationDue'] + 0.001) {
            return 'Compensation due is '.money($f['compensationDue']).'.';
        }
        if (in_array($data['method'], ['bank', 'debit'], true) && trim((string) ($data['reference'] ?? '')) === '') {
            return 'Reference number is required for bank transfers and debit payments.';
        }
        if (! empty($data['pay_account_id']) && ! PayAccount::whereKey($data['pay_account_id'])->exists()) {
            return 'Choose the PartyRent account the money was sent to.';
        }
        if (in_array($data['method'], Payment::REF_CHECKED, true) && in_array($data['type'], Payment::INFLOW, true) && self::referenceUsed((string) $data['reference'])) {
            return 'Reference number '.trim((string) $data['reference']).' was already used for another payment. Check the number on your receipt.';
        }

        return null;
    }

    /** A bank / e-wallet reference already on a live (pending or verified) inflow payment. Stops one receipt being reused. */
    public static function referenceUsed(string $reference, ?int $exceptId = null): bool
    {
        $key = Payment::refKey($reference);

        return $key !== null && Payment::where('reference_key', $key)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereIn('method', Payment::REF_CHECKED)->whereIn('type', Payment::INFLOW)->whereIn('status', ['pending', 'verified'])->exists();
    }

    /** Customer (or supplier on the customer's behalf) submits a payment — PENDING until a cashier verifies it. */
    public static function submitPayment(RentalRequest $r, array $data, string $source = 'customer'): array
    {
        if ($r->status === 'pending') {
            return self::fail('You can pay once the supplier accepts your request. Stock is not reserved until then.');
        }
        if (! in_array($r->status, Finance::ACTIVE, true) && $r->status !== 'completed') {
            return self::fail('This request is '.strtolower(RentalRequest::statusLabel($r->status)).', so no more payments can be made.');
        }
        if (! in_array($data['type'], array_merge(Finance::RENTAL_TYPES, ['deposit', 'compensation']), true)) {
            return self::fail('Choose what you are paying for.');
        }
        if ($err = self::validatePayment($r, $data)) {
            return self::fail($err);
        }
        $online = $data['method'] === 'online';
        if (in_array($data['method'], ['bank', 'debit'], true) && $source === 'customer' && PayAccount::where('active', true)->exists()) {
            $acc = empty($data['pay_account_id']) ? null : PayAccount::find($data['pay_account_id']);
            if (! $acc) {
                return self::fail('Choose which PartyRent account you sent the money to.');
            }
            if (! $acc->accepts((float) $data['amount'])) {
                return self::fail($acc->active ? $acc->label.' cannot take '.money($data['amount']).' more this month. Please send it to another account.' : $acc->label.' is no longer accepting payments. Please send it to another account.');
            }
        }
        $p = Payment::create([
            'code' => self::nextPaymentCode(), 'rental_request_id' => $r->id, 'type' => $data['type'], 'method' => $data['method'],
            'pay_account_id' => in_array($data['method'], ['bank', 'debit'], true) ? ($data['pay_account_id'] ?? null) : null,
            'amount' => r2($data['amount']), 'date' => $data['date'] ?? today_iso(), 'submitted_at' => now(),
            'reference' => trim((string) ($data['reference'] ?? '')), 'proof_path' => $data['proof_path'] ?? null, 'proof_name' => $data['proof_name'] ?? null,
            'notes' => $data['notes'] ?? null, 'status' => $online ? 'awaiting' : 'pending', 'recorded_by' => $source,
            'gateway' => $online ? ($data['gateway'] ?? 'paymongo') : null,
        ]);
        $r->load('payments');
        if ($online) {
            return self::ok(['payment' => $p]); // nothing to verify or announce until the gateway says it's paid
        }
        self::changed($r);
        $cust = $r->customer;
        Notifier::finance($r->supplier, 'New payment submission', $cust->name.' · '.money($p->amount).' ('.$p->typeLabel().') for '.$r->code.' — pending verification.', self::link('supplier.payments', ['tab' => 'verify']));
        if ($source === 'supplier') {
            Notifier::notify($r->customer_id, 'customer', 'Payment recorded', 'The supplier recorded your '.money($p->amount).' payment for '.$r->code.'. It will count once verified.', self::link('rentals.show', $r));
        }

        return self::ok(['payment' => $p]);
    }

    /** May this user act on a shop's money (Auth.canFinance)? The shop's cashier / owner, or the superadmin. */
    public static function canFinance(int $supplierId, string $perm, User $u): bool
    {
        return $u->hasRole('superadmin') || ($u->isActive() && $u->shop()?->id === $supplierId && $u->orgCan($perm));
    }

    public static function verifyPayment(Payment $p, User $by): array
    {
        if (! self::canFinance($p->rentalRequest->supplier_id, 'payments.verify', $by)) {
            return self::fail('You do not have permission to verify payments.');
        }
        if ($dup = $p->duplicates()->firstWhere('status', 'verified')) {
            return self::fail('Reference '.$p->reference.' was already verified on '.$dup->code.' ('.$dup->rentalRequest->code.'). Reject this one unless the bank shows two separate transfers.');
        }

        return self::confirmPayment($p, $by);
    }

    /**
     * Mark a payment verified, issue the receipt and tell everyone. $by is the cashier, or null when
     * the gateway confirmed an online payment (then an "awaiting" payment may be confirmed directly).
     */
    public static function confirmPayment(Payment $p, ?User $by): array
    {
        return DB::transaction(function () use ($p, $by) {
            $p = Payment::lockForUpdate()->find($p->id);
            if (! $p || ! ($p->status === 'pending' || ($by === null && $p->status === 'awaiting'))) {
                return self::fail('Only pending payments can be verified.');
            }
            $r = $p->rentalRequest()->with('payments')->first();
            $before = $r->fin();
            if (in_array($p->type, Finance::RENTAL_TYPES, true) && $p->amount > $before['balance'] + 0.001) {
                return self::fail('This payment exceeds the remaining balance of '.money($before['balance']).'. Reject it or ask the customer to correct the amount.');
            }
            $p->status = 'verified';
            $p->verified_by = $by?->id;
            $p->verified_at = now();
            self::issueReceipt($p);
            $p->save();
            $r->load('payments');
            self::changed($r);
            $after = $r->fin();
            Notifier::notify($r->customer_id, 'customer', 'Payment verified ✅', money($p->amount).' for '.$r->code.' was verified. Receipt '.$p->receipt_no.'. Payment status: '.$after['status'].($after['balance'] > 0 ? ', balance '.money($after['balance']) : '').'.', self::link('rentals.show', $r));
            Notifier::notify($r->supplier->owner_id, 'supplier', 'Payment verified', money($p->amount).' for '.$r->code.' verified by '.($by ? 'cashier' : 'PartyRent (paid online)').'. Status: '.$after['status'].'.', self::link('supplier.requests.show', $r));
            Notifier::audit($by ? 'Verified payment' : 'Online payment confirmed by '.OnlinePay::name($p->gateway), $p->code.' · '.money($p->amount).' · '.$r->code.' · '.$p->receipt_no, $by);

            return self::ok(['payment' => $p]);
        });
    }

    public static function rejectPayment(Payment $p, string $reason, User $by): array
    {
        if (! self::canFinance($p->rentalRequest->supplier_id, 'payments.verify', $by)) {
            return self::fail('You do not have permission to reject payments.');
        }
        if ($p->status !== 'pending') {
            return self::fail('Only pending payments can be rejected.');
        }
        if (trim($reason) === '') {
            return self::fail('Please give a reason so the customer can correct it.');
        }
        $p->update(['status' => 'rejected', 'reject_reason' => $reason, 'verified_by' => $by->id, 'verified_at' => now()]);
        $r = $p->rentalRequest;
        self::changed($r);
        Notifier::notify($r->customer_id, 'customer', 'Payment rejected', money($p->amount).' for '.$r->code.' was rejected: '.$reason, self::link('rentals.show', $r));
        Notifier::notify($r->supplier->owner_id, 'supplier', 'Payment rejected', 'A '.money($p->amount).' submission for '.$r->code.' was rejected by the cashier.', self::link('supplier.requests.show', $r));
        Notifier::audit('Rejected payment', $p->code.' · '.money($p->amount).' · '.$reason, $by);

        return self::ok();
    }

    /** Cashier records an authorized offline payment or refund — verified at once, receipt issued. */
    public static function cashierRecord(RentalRequest $r, array $data, User $by): array
    {
        if (! self::canFinance($r->supplier_id, 'payments.record', $by)) {
            return self::fail('You do not have permission to record payments for this shop.');
        }
        $f = $r->fin();
        if (! in_array($data['type'], array_merge(Finance::RENTAL_TYPES, ['deposit', 'compensation', 'refund']), true)) {
            return self::fail('Choose a payment type.');
        }
        if ($r->status === 'pending' && $data['type'] !== 'refund') {
            return self::fail('Payments are recorded once the request is accepted.');
        }
        if (in_array($r->status, ['declined', 'cancelled'], true) && $data['type'] !== 'refund') {
            return self::fail($r->code.' is '.$r->status.': only refunds can be recorded.');
        }
        if ($r->payout()->exists()) {
            return self::fail('The money for '.$r->code.' was already released to the supplier ('.$r->payout->code.'). Ask an admin to handle any correction.');
        }
        if ($data['type'] === 'refund' && (float) $data['amount'] > $f['paidRental'] - $f['refunded'] + 0.001) {
            return self::fail('Refund cannot exceed verified rental payments ('.money($f['paidRental'] - $f['refunded']).').');
        }
        if ($data['type'] !== 'refund' && ($err = self::validatePayment($r, $data))) {
            return self::fail($err);
        }
        if (trim((string) ($data['reference'] ?? '')) === '') {
            return self::fail('Enter the official reference / OR number.');
        }
        $p = new Payment([
            'code' => self::nextPaymentCode(), 'rental_request_id' => $r->id, 'type' => $data['type'], 'method' => $data['method'],
            'amount' => r2($data['amount']), 'date' => $data['date'] ?? today_iso(), 'submitted_at' => now(), 'reference' => trim($data['reference']),
            'pay_account_id' => $data['type'] !== 'refund' && in_array($data['method'], ['bank', 'debit'], true) ? ($data['pay_account_id'] ?? null) : null,
            'notes' => $data['notes'] ?? null, 'status' => 'verified', 'verified_by' => $by->id, 'verified_at' => now(), 'recorded_by' => 'cashier',
        ]);
        self::issueReceipt($p);
        $p->save();
        $r->load('payments');
        self::changed($r);
        $after = $r->fin();
        $isRefund = $data['type'] === 'refund';
        Notifier::notify($r->customer_id, 'customer', $isRefund ? 'Refund update' : 'Payment verified ✅', money($p->amount).' '.($isRefund ? 'refunded' : 'received').' for '.$r->code.'. Receipt '.$p->receipt_no.'.', self::link('rentals.show', $r));
        Notifier::notify($r->supplier->owner_id, 'supplier', 'Payment verified', 'Cashier recorded '.money($p->amount).' ('.$p->typeLabel().') for '.$r->code.'. Status: '.$after['status'].'.', self::link('supplier.requests.show', $r));
        Notifier::audit('Recorded offline '.($isRefund ? 'refund' : 'payment'), $p->code.' · '.money($p->amount).' · '.$r->code, $by);

        return self::ok(['payment' => $p]);
    }

    /** Flag newly overdue schedule items and notify everyone involved (runs on a schedule and on dashboard loads). */
    public static function scanOverdue(): int
    {
        $count = 0;
        foreach (RentalRequest::whereIn('status', Finance::ACTIVE)->with(['payments', 'supplier', 'customer'])->get() as $r) {
            $f = $r->fin();
            $flags = $r->overdue_flags ?? [];
            foreach ($f['schedule'] as $s) {
                if ($s['state'] !== 'overdue' || isset($flags[$s['key']])) {
                    continue;
                }
                $flags[$s['key']] = now()->toIso8601String();
                $count++;
                $msg = $s['label'].' of '.money($s['amount']).' for '.$r->code.' was due '.fmt_date($s['due']).'. Outstanding: '.money($f['overdueAmount']).'.';
                Notifier::notify($r->customer_id, 'customer', 'Payment overdue', $msg.' Please pay as soon as possible.', self::link('rentals.show', $r));
                Notifier::notify($r->supplier->owner_id, 'supplier', 'Overdue payment', $r->customer->name.': '.$msg, self::link('supplier.requests.show', $r));
                if (\App\Models\ShopStaff::where('supplier_id', $r->supplier_id)->where('role', 'cashier')->exists()) {
                    Notifier::finance($r->supplier, 'Overdue payment', $r->customer->name.': '.$msg, self::link('cashier.balances'));
                }
                Notifier::notify('admin', 'admin', 'Overdue payment', $r->customer->name.' · '.$msg, '');
                Notifier::audit('Payment marked overdue', $r->code.' · '.$s['label'].' · '.money($f['overdueAmount']), null);
            }
            if ($flags !== ($r->overdue_flags ?? [])) {
                $r->overdue_flags = $flags;
                $r->saveQuietly();
            }
        }

        return $count;
    }

    /* ================= SUPPLIER ONBOARDING ================= */

    public static function submitApplication(User $u, array $data): array
    {
        $existing = $u->supplier;
        if ($existing && $existing->status !== 'rejected') {
            return self::fail('You already have a supplier profile ('.$existing->status.').');
        }
        $needsReview = (bool) Settings::get('requireSupplierApproval', true);
        if (! $needsReview && ($data['account_type'] ?? null) === 'business') {
            $data['biz_status'] = 'verified';
            $data['biz_verified_at'] = now();
        }
        $attrs = array_merge($data, [
            'owner_id' => $u->id, 'status' => $needsReview ? 'pending' : 'approved', 'applied_at' => now(),
            'approved_at' => $needsReview ? null : now(), 'reject_reason' => null, 'reviewed_at' => null, 'reviewed_by' => null,
        ]);
        $s = $existing ? tap($existing)->update($attrs) : Supplier::create(array_merge(['rating' => 0], $attrs));
        if ($needsReview) {
            Notifier::notify('admin', 'admin', 'New supplier application', $u->name.' applied to open '.$s->name.'.', '');
            Notifier::notify($u, 'customer', 'Application submitted', 'Your supplier application for '.$s->name.' is pending review. We will notify you once it is approved.', self::link('start-selling'));
        } else {
            Notifier::notify($u, 'customer', 'Your shop is live!', $s->name.' is approved. Tap your shop name in the profile menu to add your first product.', self::link('supplier.dashboard'));
        }
        Notifier::audit('Submitted supplier application', $s->name, $u);

        return self::ok(['supplier' => $s]);
    }

    /** Admin checks the documents of a shop that upgraded from Personal to Business. */
    public static function reviewBusiness(Supplier $s, bool $approve, ?string $reason, User $by): array
    {
        if (! $by->hasPerm('applications.review')) {
            return self::fail('You do not have permission to review applications.');
        }
        if (! $s->isBusiness() || $s->biz_status !== 'pending') {
            return self::fail('There is nothing to check for this shop.');
        }
        if (! $approve && ! trim((string) $reason)) {
            return self::fail('Please state the reason for rejection.');
        }
        $s->update($approve ? ['biz_status' => 'verified', 'biz_verified_at' => now(), 'biz_reject_reason' => null] : ['biz_status' => 'rejected', 'biz_reject_reason' => $reason]);
        Notifier::notify($s->owner_id, 'supplier', $approve ? 'Business account verified' : 'Business documents not accepted', $approve ? $s->name.' is now a Verified Business. You can add staff under Staff & Roles.' : 'We could not verify the business documents for '.$s->name.': '.$reason.'. Update them on your Shop Profile page.', self::link($approve ? 'supplier.staff' : 'supplier.shop'));
        Notifier::audit($approve ? 'Verified business' : 'Rejected business documents', $s->name.($reason ? ' — '.$reason : ''), $by);

        return self::ok();
    }

    public static function reviewApplication(Supplier $s, bool $approve, ?string $reason, User $by): array
    {
        if (! $by->hasPerm('applications.review')) {
            return self::fail('You do not have permission to review applications.');
        }
        if ($s->status !== 'pending') {
            return self::fail('This application is not pending.');
        }
        if (! $approve && ! trim((string) $reason)) {
            return self::fail('Please state the reason for rejection.');
        }
        $s->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_at' => now(), 'reviewed_by' => $by->id, 'reject_reason' => $approve ? null : $reason, 'approved_at' => $approve ? now() : null]
            // Approving a business application also accepts its documents.
            + ($approve && $s->isBusiness() ? ['biz_status' => 'verified', 'biz_verified_at' => now()] : []));
        Notifier::notify($s->owner_id, 'customer', $approve ? 'Supplier application approved 🎉' : 'Supplier application rejected', $approve ? $s->name.' is approved! Tap '.$s->name.' in your profile menu to start listing items.' : 'Your application for '.$s->name.' was not approved: '.$reason.'. You may update and resubmit.', self::link('start-selling'));
        Notifier::audit($approve ? 'Approved supplier application' : 'Rejected supplier application', $s->name.($reason ? ' — '.$reason : ''), $by);

        return self::ok();
    }

    /* ================= SUPPLIER CATALOG / INVENTORY ================= */

    /** Validate and normalize pricing tiers: continuous, starting at 1, last tier open-ended. */
    public static function normalizeTiers(array $tiers): array|string
    {
        $tiers = array_values(array_filter(array_map(fn ($t) => ['min' => (int) ($t['min'] ?? 0), 'max' => isset($t['max']) && $t['max'] !== '' && $t['max'] !== null ? (int) $t['max'] : null, 'price' => r2($t['price'] ?? 0)], $tiers), fn ($t) => $t['price'] > 0));
        usort($tiers, fn ($a, $b) => $a['min'] <=> $b['min']);
        if (! $tiers) {
            return 'Add at least one price.';
        }
        foreach ($tiers as $i => $t) {
            if ($i === 0 && $t['min'] !== 1) {
                return 'The first pricing tier must start at quantity 1.';
            }
            if ($i > 0 && $t['min'] !== ($tiers[$i - 1]['max'] ?? 0) + 1) {
                return 'Pricing tiers must be continuous (tier '.($i + 1).' should start at '.(($tiers[$i - 1]['max'] ?? 0) + 1).').';
            }
            if ($i < count($tiers) - 1 && ! (($t['max'] ?? 0) >= $t['min'])) {
                return 'Tier '.($i + 1).' needs a maximum quantity.';
            }
        }
        $tiers[count($tiers) - 1]['max'] = null;

        return $tiers;
    }

    public static function saveProduct(Supplier $s, array $data, ?Product $p = null): array
    {
        $tiers = self::normalizeTiers($data['tiers'] ?? []);
        if (is_string($tiers)) {
            return self::fail($tiers);
        }
        $data['tiers'] = $tiers;
        if ($p) {
            if ($p->supplier_id !== $s->id) {
                return self::fail('You can only edit your own products.');
            }
            $type = $data['listing_type'] ?? $p->listing_type;
            if ($type !== $p->listing_type && (inv()->reservedDates($p->id) || inv()->committedSale($p->id) || inv()->pendingSale($p->id))) {
                return self::fail('This item has open bookings, so it cannot switch between rent and sale until they are completed.');
            }
            if ($type === 'sale') {
                $committed = inv()->committedSale($p->id);
                if ((int) $data['total_stock'] < $committed) {
                    return self::fail('Stock on hand cannot go below '.$committed.': accepted orders still need that many.');
                }
            } else {
                $conflicts = inv()->stockChangeConflicts($p->id, (int) $data['total_stock']);
                if ($conflicts) {
                    return self::fail('Total stock cannot be reduced to '.$data['total_stock'].'. Accepted reservations need '.implode(', ', array_map(fn ($c) => $c['reserved'].' on '.fmt_date($c['date']), $conflicts)).'.');
                }
            }
            $priceChanged = json_encode($p->tiers) !== json_encode($tiers);
            $stockDelta = $type === 'sale' && $p->isSale() ? (int) $data['total_stock'] - $p->stockOnHand() : 0;
            $p->update($data);
            if ($stockDelta) {
                StockMovement::create(['product_id' => $p->id, 'qty' => $stockDelta, 'type' => 'adjust', 'user_id' => auth()->id(), 'balance' => $p->stockOnHand(), 'note' => 'Edited stock on hand', 'created_at' => now()]);
            }
            inv()->flush();
            if ($priceChanged) {
                Notifier::audit('Updated product pricing', $p->name.' · '.implode(', ', array_map(fn ($t) => $t['min'].($t['max'] ? '–'.$t['max'] : '+').': '.money($t['price']), $tiers)));
            }

            return self::ok(['product' => $p]);
        }
        $p = Product::create(array_merge(['damaged' => 0, 'missing' => 0, 'rating' => 0, 'rent_count' => 0, 'images' => []], $data, ['supplier_id' => $s->id]));
        Notifier::audit('Created product', $p->name);

        return self::ok(['product' => $p]);
    }

    public static function addHold(Product $p, array $h): array
    {
        if (! ((int) ($h['qty'] ?? 0) > 0)) {
            return self::fail('Quantity must be at least 1.');
        }
        if (! empty($h['end_date']) && $h['end_date'] < $h['start_date']) {
            return self::fail('End date must be on or after the start date.');
        }
        $c = inv()->holdConflicts($p->id, (int) $h['qty'], $h['start_date'], $h['end_date'] ?: null);
        if ($c) {
            return self::fail('Setting aside '.$h['qty'].' units would break accepted reservations on '.implode(', ', array_map(fn ($x) => fmt_date($x['date']).' ('.$x['reserved'].' reserved)', $c)).'.');
        }
        Hold::create(['product_id' => $p->id, 'type' => $h['type'] === 'setaside' ? 'setaside' : 'maintenance', 'qty' => (int) $h['qty'], 'start_date' => $h['start_date'], 'end_date' => $h['end_date'] ?: null, 'note' => $h['note'] ?? '']);
        inv()->flush();

        return self::ok();
    }

    /** Adjust damaged / missing counters (repaired or found units go back to available). */
    public static function adjustCondition(Product $p, string $field, int $newValue): array
    {
        if (! in_array($field, ['damaged', 'missing'], true)) {
            return self::fail('Unknown field.');
        }
        if ($newValue < 0) {
            return self::fail('Value cannot be negative.');
        }
        $c = inv()->stockChangeConflicts($p->id, $p->total_stock, $field === 'damaged' ? $newValue : null, $field === 'missing' ? $newValue : null);
        if ($c) {
            return self::fail('This would leave too few units for accepted reservations on '.implode(', ', array_map(fn ($x) => fmt_date($x['date']), $c)).'.');
        }
        $p->update([$field => $newValue]);
        inv()->flush();
        Notifier::audit('Adjusted '.$field.' units', $p->name.' → '.$newValue);

        return self::ok();
    }

    /**
     * Create / update a package from the editor. $data: name, emoji, description, conditions, events,
     * images, pricing, components, minGuests, maxGuests, draft (bool). See App\Domain\Packages.
     * Booked requests keep their own snapshot, so editing is always safe.
     */
    public static function savePackage(Supplier $s, array $data, ?Package $k = null): array
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return self::fail('Package name is required.');
        }
        if ($k && $k->supplier_id !== $s->id) {
            return self::fail('Not your package.');
        }
        $v = Packages::validate($s, $data);
        if (! $v['ok']) {
            return self::fail($v['error']);
        }
        $def = $v['def'];
        $attrs = [
            'name' => trim($data['name']), 'emoji' => ($data['emoji'] ?? '') ?: '🎉', 'description' => (string) ($data['description'] ?? ''),
            'conditions' => (string) ($data['conditions'] ?? ''), 'events' => array_values($data['events'] ?? []),
            'components' => $def['components'], 'pricing' => $def['pricing'], 'min_guests' => $def['minGuests'], 'max_guests' => $def['maxGuests'],
            'stages' => $def['stages'] ?: null, 'tier_group' => $data['tier_group'] ?? null, 'tier_label' => ($data['tier_group'] ?? null) ? ($data['tier_label'] ?? null) : null,
            'is_draft' => (bool) ($data['draft'] ?? false),
        ];
        if (isset($data['images'])) {
            $attrs['images'] = $data['images'];
        }
        if ($k) {
            if (! $attrs['is_draft'] && $k->is_draft) {
                $attrs['active'] = true; // publishing a draft
            }
            $k->update($attrs);
        } else {
            $attrs['active'] = ! $attrs['is_draft'];
            $attrs['sort'] = (int) Package::where('supplier_id', $s->id)->max('sort') + 1;
            $k = Package::create(array_merge(['images' => []], $attrs, ['supplier_id' => $s->id]));
        }
        inv()->flush();
        Notifier::audit($attrs['is_draft'] ? 'Saved package draft' : 'Saved package', $k->name.' · '.money($k->price));

        return self::ok(['package' => $k]);
    }

    public static function duplicatePackage(Package $k): Package
    {
        $copy = $k->replicate(['created_at', 'updated_at']);
        $copy->name = mb_substr('Copy of '.$k->name, 0, 120);
        $copy->is_draft = true;
        $copy->active = false;
        $copy->sort = (int) Package::where('supplier_id', $k->supplier_id)->max('sort') + 1;
        $copy->save();

        return $copy;
    }

    /** Move a package one place up / down in the supplier's list (renumbers the sort order). */
    public static function movePackage(Package $k, string $dir): void
    {
        $list = Package::where('supplier_id', $k->supplier_id)->orderBy('sort')->orderBy('id')->get()->values();
        $i = $list->search(fn ($x) => $x->id === $k->id);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($i === false || $j < 0 || $j >= $list->count()) {
            return;
        }
        $ids = $list->pluck('id')->all();
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach ($ids as $n => $id) {
            Package::whereKey($id)->update(['sort' => $n]);
        }
    }

    /** Removing a package never touches booked requests (they hold a snapshot); rental-list lines are dropped. */
    public static function deletePackage(Package $k): void
    {
        CartLine::where('type', 'package')->where('ref_id', $k->id)->delete();
        Notifier::audit('Deleted package', $k->name);
        $k->delete();
        inv()->flush();
    }
}
