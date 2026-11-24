<?php

namespace App\Domain;

use App\Models\RentalRequest;
use App\Models\Supplier;
use Carbon\Carbon;

/**
 * Damage, loss & compensation (reference services.js "DAMAGE, LOSS & COMPENSATION").
 *
 * Compensation = product value × compensation % × affected quantity. The product's replacement value
 * (not the rental fee) is the basis. Each supplier sets its own percentages per category (optionally per
 * product category); "Lost or not returned" depends on the product's condition before the rental.
 * Nothing is charged until the supplier reviews and confirms the assessment.
 */
class Damage
{
    public const CONDITIONS = ['brand_new' => 'Brand New', 'good_as_new' => 'Good as New', 'used' => 'Used'];

    public const CATS = ['repairable' => 'Repairable Damage', 'nonRepairable' => 'Non-Repairable Damage', 'lost' => 'Lost or Not Returned'];

    public const DEFAULT = ['repairable' => ['enabled' => true, 'pct' => 50], 'nonRepairable' => ['enabled' => true, 'pct' => 70], 'lost' => ['enabled' => true, 'brand_new' => 100, 'good_as_new' => 100, 'used' => 70]];

    public static function conditionLabel(?string $c): string
    {
        return self::CONDITIONS[$c] ?? (string) $c;
    }

    /** The supplier's policy merged over the defaults. */
    public static function policy(?Supplier $sup): array
    {
        $d = (array) ($sup?->damage_policy ?? []);

        return [
            'repairable' => array_merge(self::DEFAULT['repairable'], (array) ($d['repairable'] ?? [])),
            'nonRepairable' => array_merge(self::DEFAULT['nonRepairable'], (array) ($d['nonRepairable'] ?? [])),
            'lost' => array_merge(self::DEFAULT['lost'], (array) ($d['lost'] ?? [])),
            'categories' => (array) ($d['categories'] ?? []),
        ];
    }

    /** Rates for one product: the category override if the supplier set one, otherwise the shop default. */
    public static function rates(?Supplier $sup, $product): array
    {
        $pol = self::policy($sup);
        $ov = $product ? ($pol['categories'][$product->category_id] ?? null) : null;
        $pick = fn ($k) => array_merge($pol[$k], $ov && ! empty($ov[$k]) ? (array) $ov[$k] : []);
        $lost = $pick('lost');
        $cond = $product?->condition && isset(self::CONDITIONS[$product->condition]) ? $product->condition : 'used';

        return [
            'source' => $ov ? 'category' : 'default', 'condition' => $cond,
            'repairable' => $pick('repairable'), 'nonRepairable' => $pick('nonRepairable'),
            'lost' => ['enabled' => (bool) ($lost['enabled'] ?? true), 'pct' => (float) ($lost[$cond] ?? $lost['used'] ?? 0)],
        ];
    }

    /* ---------- item damage fees: what a customer could be charged, and the terms kept with each rental ---------- */

    /**
     * Damage fee of one rental item per unit, as customers see it before booking: the minor fee (personal shops)
     * and the most one unit can cost (lost or beyond repair: the replacement value, or for business shops the
     * replacement value × the highest loss percentage of their policy). null max = no damage fee set.
     */
    public static function liability($p): array
    {
        $value = (float) ($p?->replacement_value ?? 0);
        if (! $p || $p->isSale()) {
            return ['minor' => null, 'max' => null, 'value' => 0.0];
        }
        if (self::usesOutcomes($p->supplier)) {
            return ['minor' => $p->minor_fee !== null ? (float) $p->minor_fee : null, 'max' => $value > 0 ? r2($value) : null, 'value' => $value];
        }
        $rt = self::rates($p->supplier, $p);
        $pct = max($rt['lost']['enabled'] ? $rt['lost']['pct'] : 0, $rt['nonRepairable']['enabled'] ? (float) $rt['nonRepairable']['pct'] : 0);

        return ['minor' => null, 'max' => $value > 0 && $pct > 0 ? r2($value * $pct / 100) : null, 'value' => $value];
    }

    /**
     * Damage terms of every rental item in some lines (packages are broken into their items; the same item from a
     * package and on its own is counted once, at its busiest day): [productId => ['name', 'unit', 'qty', 'minor',
     * 'max', 'value', 'condition', 'rates']]. Kept with the rental when it is booked (pricing.damageTerms), so the
     * return inspection charges the terms the customer agreed to, even if the shop changes them later.
     */
    public static function termsForLines(array $lines): array
    {
        $peak = [];
        foreach (inv()->demandMap($lines) as $units) {
            foreach ($units as $pid => $q) {
                $peak[$pid] = max($peak[$pid] ?? 0, (int) $q);
            }
        }
        $out = [];
        foreach ($peak as $pid => $qty) {
            $p = inv()->product($pid);
            if (! $p || $p->isSale()) {
                continue;
            }
            $l = self::liability($p);
            $out[$pid] = ['name' => $p->name, 'unit' => $p->unitWord() ?: 'piece', 'qty' => $qty, 'minor' => $l['minor'], 'max' => $l['max'],
                'value' => $l['value'], 'condition' => $p->condition, 'rates' => self::rates($p->supplier, $p)];
        }

        return $out;
    }

    /** The damage terms the return inspection uses for one item of a rental: kept from booking, else the item's current ones. */
    public static function termsAt(RentalRequest $r, $p): array
    {
        $t = $p ? ($r->pricing['damageTerms'][$p->id] ?? $r->pricing['damageTerms'][(string) $p->id] ?? null) : null;
        if ($t) {
            return ['value' => (float) $t['value'], 'minorFee' => $t['minor'] !== null ? (float) $t['minor'] : null, 'rates' => $t['rates'], 'kept' => true];
        }

        return ['value' => (float) ($p?->replacement_value ?? 0), 'minorFee' => $p?->minor_fee !== null ? (float) $p->minor_fee : null, 'rates' => self::rates($r->supplier, $p), 'kept' => false];
    }

    /** Error message for an invalid policy, or null. */
    public static function validatePolicy(array $dp): ?string
    {
        $chk = function ($c, $label) {
            foreach ((array) $c as $k => $v) {
                if ($k === 'enabled') {
                    continue;
                }
                if (! is_numeric($v) || $v < 0 || $v > 100) {
                    return $label.': percentages must be between 0% and 100%.';
                }
            }

            return null;
        };
        foreach (self::CATS as $k => $l) {
            if ($e = $chk($dp[$k] ?? [], $l)) {
                return $e;
            }
        }
        foreach ((array) ($dp['categories'] ?? []) as $cat => $o) {
            $name = \App\Models\Category::find($cat)?->name ?? $cat;
            foreach (self::CATS as $k => $l) {
                if ($e = $chk($o[$k] ?? [], $name.' – '.$l)) {
                    return $e;
                }
            }
        }

        return null;
    }

    /** Units of each product that went out on a rental (the busiest day). */
    public static function rentedUnits(RentalRequest $r): array
    {
        return Rentals::peakUnits($r);
    }

    /**
     * Compute a damage assessment (no side effects).
     * $input: [productId => {returned, repairable, nonRepairable, description}] (or a list with productId keys).
     */
    public static function assess(RentalRequest $r, array $input): array
    {
        $sup = $r->supplier;
        $byPid = [];
        foreach ($input as $k => $x) {
            $byPid[(string) ($x['productId'] ?? $k)] = (array) $x;
        }
        $errors = [];
        $items = [];
        foreach (self::rentedUnits($r) as $pid => $rented) {
            $p = inv()->product($pid);
            $name = $p?->name ?? 'Item';
            $x = $byPid[(string) $pid] ?? [];
            $n = fn ($k) => max(0, (int) floor((float) ($x[$k] ?? 0)));
            $returned = ! isset($x['returned']) || $x['returned'] === '' ? $rented : $n('returned');
            $repairable = $n('repairable');
            $nonRepairable = $n('nonRepairable');
            $lost = max(0, $rented - $returned);
            if ($returned > $rented) {
                $errors[] = $name.': returned ('.$returned.') cannot exceed the quantity rented ('.$rented.').';
            }
            if ($repairable + $nonRepairable > $returned) {
                $errors[] = $name.': damaged units ('.($repairable + $nonRepairable).') cannot exceed the units returned ('.$returned.').';
            }
            $kept = self::termsAt($r, $p); // the terms agreed at booking, not today's
            $rates = $kept['rates'];
            $value = $kept['value'];
            $pct = [
                'repairable' => $rates['repairable']['enabled'] ? (float) $rates['repairable']['pct'] : 0,
                'nonRepairable' => $rates['nonRepairable']['enabled'] ? (float) $rates['nonRepairable']['pct'] : 0,
                'lost' => $rates['lost']['enabled'] ? (float) $rates['lost']['pct'] : 0,
            ];
            $amounts = [
                'repairable' => r2($value * $pct['repairable'] / 100 * $repairable),
                'nonRepairable' => r2($value * $pct['nonRepairable'] / 100 * $nonRepairable),
                'lost' => r2($value * $pct['lost'] / 100 * $lost),
            ];
            $desc = trim((string) ($x['description'] ?? ''));
            if ($repairable + $nonRepairable + $lost > 0 && $desc === '') {
                $errors[] = $name.': describe the damage or loss.';
            }
            $items[] = [
                'productId' => $pid, 'rented' => $rented, 'returned' => $returned, 'repairable' => $repairable, 'nonRepairable' => $nonRepairable, 'lost' => $lost,
                'condition' => $rates['condition'], 'value' => $value, 'pcts' => $pct,
                'enabled' => ['repairable' => (bool) $rates['repairable']['enabled'], 'nonRepairable' => (bool) $rates['nonRepairable']['enabled'], 'lost' => $rates['lost']['enabled']],
                'rateSource' => $rates['source'], 'amounts' => $amounts, 'total' => r2(array_sum($amounts)), 'description' => $desc, 'name' => $name, 'termsKept' => $kept['kept'],
            ];
        }

        return ['ok' => ! $errors, 'errors' => $errors, 'items' => $items, 'total' => r2(array_sum(array_column($items, 'total')))];
    }

    /* ================= Personal shops: purchase record & four damage outcomes ================= */

    /** What can happen to a returned unit (personal shops). "lost" is worked out from the returned count. */
    public const OUTCOMES = [
        'minor' => 'Minor (cleaning, stain, small scratch)',
        'repairable' => 'Repairable',
        'notWorking' => 'Not working',
        'beyond' => 'Beyond repair',
        'lost' => 'Lost / not returned',
    ];

    /** Hours the customer has to accept or dispute a damage charge; no answer = accepted. */
    public const REVIEW_HOURS = 48;

    /** Personal shops use the outcome model; business shops keep the percentage policy. */
    public static function usesOutcomes(?Supplier $s): bool
    {
        return $s !== null && ! $s->isBusiness();
    }

    /** Share of the price paid an item keeps with age: under 1 year 100%, 1–2 years 85%, 2–3 years 70%, older 55%. */
    public static function ageFactor($purchasedOn): float
    {
        if (! $purchasedOn) {
            return 1.0;
        }
        $years = Carbon::parse($purchasedOn)->floatDiffInYears(now());

        return $years < 1 ? 1.0 : ($years < 2 ? 0.85 : ($years < 3 ? 0.7 : 0.55));
    }

    /** Share kept for the condition before rental: Brand New 100%, Good as New 90%, Used 75%. */
    public static function conditionFactor(?string $condition): float
    {
        return ['brand_new' => 1.0, 'good_as_new' => 0.9, 'used' => 0.75][$condition] ?? 0.75;
    }

    /** Suggested replacement value from the purchase record: price paid lowered for age and condition (never below 30%). */
    public static function suggestedValue(?float $price, $purchasedOn, ?string $condition): ?float
    {
        if (! $price || $price <= 0) {
            return null;
        }

        return r2(max($price * 0.3, $price * self::ageFactor($purchasedOn) * self::conditionFactor($condition)));
    }

    /** What a customer pays per unit, shown before booking (personal shops, rental items). */
    public static function terms($p): ?array
    {
        if (! $p || $p->isSale() || ! self::usesOutcomes($p->supplier)) {
            return null;
        }
        $value = (float) ($p->replacement_value ?? 0);

        return [
            'minor' => $p->minor_fee !== null ? (float) $p->minor_fee : null,
            'value' => $value,
            'receipt' => (bool) $p->purchase_receipt,
            'text' => ($p->minor_fee !== null ? 'Minor: '.money($p->minor_fee).' · ' : '')
                .'Damaged: repair cost'.($value > 0 ? ', up to '.money($value) : '').' · Not working: free unless misused · '
                .'Lost or beyond repair: '.($value > 0 ? money($value) : 'not set'),
        ];
    }

    /**
     * Assessment for a personal shop (no side effects).
     * $input: [productId => {returned, minor, repairable, repairCost, notWorking, misuse, misuseCost, beyond, description}]
     * Repairs are charged at the cost entered (capped at the replacement value per unit); "not working" is free
     * unless the supplier marks misuse; beyond repair and lost units are charged the replacement value.
     */
    public static function assessOutcomes(RentalRequest $r, array $input): array
    {
        $byPid = [];
        foreach ($input as $k => $x) {
            $byPid[(string) ($x['productId'] ?? $k)] = (array) $x;
        }
        $errors = [];
        $items = [];
        foreach (self::rentedUnits($r) as $pid => $rented) {
            $p = inv()->product($pid);
            $name = $p?->name ?? 'Item';
            $x = $byPid[(string) $pid] ?? [];
            $n = fn ($k) => max(0, (int) floor((float) ($x[$k] ?? 0)));
            $money = fn ($k) => r2(max(0, (float) ($x[$k] ?? 0)));
            $returned = ! isset($x['returned']) || $x['returned'] === '' ? $rented : $n('returned');
            $q = ['minor' => $n('minor'), 'repairable' => $n('repairable'), 'notWorking' => $n('notWorking'), 'beyond' => $n('beyond')];
            $lost = max(0, $rented - $returned);
            $misuse = $q['notWorking'] > 0 && ! empty($x['misuse']) && $x['misuse'] !== '0';
            $kept = self::termsAt($r, $p); // the terms agreed at booking, not today's
            $value = $kept['value'];
            $fee = (float) ($kept['minorFee'] ?? 0);
            if ($returned > $rented) {
                $errors[] = $name.': returned ('.$returned.') cannot exceed the quantity rented ('.$rented.').';
            }
            if (array_sum($q) > $returned) {
                $errors[] = $name.': units with a problem ('.array_sum($q).') cannot exceed the units returned ('.$returned.').';
            }
            $repairCost = $q['repairable'] ? $money('repairCost') : 0.0;
            $misuseCost = $misuse ? $money('misuseCost') : 0.0;
            if ($q['repairable'] && $repairCost <= 0) {
                $errors[] = $name.': enter the repair cost for the repairable units.';
            }
            if ($misuse && $misuseCost <= 0) {
                $errors[] = $name.': enter the repair cost for the units broken by misuse, or untick misuse.';
            }
            $cap = fn (int $units, float $cost) => $value > 0 ? min($cost, r2($value * $units)) : $cost;
            $amounts = [
                'minor' => r2($fee * $q['minor']),
                'repairable' => $q['repairable'] ? r2($cap($q['repairable'], $repairCost)) : 0.0,
                'notWorking' => $misuse ? r2($cap($q['notWorking'], $misuseCost)) : 0.0,
                'beyond' => r2($value * $q['beyond']),
                'lost' => r2($value * $lost),
            ];
            $desc = trim((string) ($x['description'] ?? ''));
            if (array_sum($q) + $lost > 0 && $desc === '') {
                $errors[] = $name.': describe what happened.';
            }
            $items[] = [
                'model' => 'outcomes', 'productId' => $pid, 'rented' => $rented, 'returned' => $returned,
                'minor' => $q['minor'], 'repairable' => $q['repairable'], 'notWorking' => $q['notWorking'], 'misuse' => $misuse, 'beyond' => $q['beyond'], 'lost' => $lost,
                'nonRepairable' => $q['beyond'], // inventory effect uses the same keys as the percentage model
                'value' => $value, 'minorFee' => $fee, 'repairCost' => $repairCost, 'misuseCost' => $misuseCost,
                'amounts' => $amounts, 'total' => r2(array_sum($amounts)), 'description' => $desc, 'name' => $name, 'termsKept' => $kept['kept'],
            ];
        }

        return ['ok' => ! $errors, 'errors' => $errors, 'items' => $items, 'total' => r2(array_sum(array_column($items, 'total')))];
    }

    /* ---------- customer review (accept / dispute) and admin decision ---------- */

    /** Review block stored on the inspection when a personal shop charges something. */
    public static function newReview(array $items): array
    {
        $dec = [];
        foreach ($items as $it) {
            if (($it['total'] ?? 0) > 0) {
                $dec[(string) $it['productId']] = ['state' => 'open', 'reason' => '', 'amount' => (float) $it['total']];
            }
        }

        return ['status' => 'pending', 'deadline' => now()->addHours(self::REVIEW_HOURS)->toIso8601String(), 'decisions' => $dec];
    }

    /** pending | disputed | done | null (no review). A pending review past its deadline is accepted here. */
    public static function reviewStatus(RentalRequest $r): ?string
    {
        $rev = $r->inspection['review'] ?? null;
        if (! $rev) {
            return null;
        }
        if ($rev['status'] === 'pending' && now()->greaterThanOrEqualTo(Carbon::parse($rev['deadline']))) {
            self::closeReview($r, [], true);

            return $r->inspection['review']['status'] ?? null;
        }

        return $rev['status'];
    }

    /**
     * Customer answer for each charged item: [productId => {action: accept|dispute, reason}] (missing = accept).
     * Any dispute sends the request to a PartyRent admin; otherwise the charge is final.
     */
    public static function closeReview(RentalRequest $r, array $answers, bool $auto = false): array
    {
        $insp = $r->inspection;
        $rev = $insp['review'] ?? null;
        if (! $rev || $rev['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'This damage charge is no longer waiting for an answer.'];
        }
        $disputed = [];
        foreach ($rev['decisions'] as $pid => &$d) {
            $a = (array) ($answers[$pid] ?? []);
            if (! $auto && ($a['action'] ?? 'accept') === 'dispute') {
                $reason = trim((string) ($a['reason'] ?? ''));
                if (mb_strlen($reason) < 5) {
                    return ['ok' => false, 'error' => 'Explain why you dispute the charge for '.(inv()->product($pid)?->name ?? 'the item').'.'];
                }
                $d = array_merge($d, ['state' => 'disputed', 'reason' => mb_substr($reason, 0, 1000)]);
                $disputed[] = inv()->product($pid)?->name ?? 'Item';
            } else {
                $d['state'] = 'accepted';
            }
        }
        unset($d);
        $rev['status'] = $disputed ? 'disputed' : 'done';
        $rev['answeredAt'] = now()->toIso8601String();
        $rev['auto'] = $auto;
        $insp['review'] = $rev;
        $r->inspection = $insp;
        $r->save();
        $r->finCache = null;
        $sup = $r->supplier;
        if ($disputed) {
            Rentals::history($r, 'inspection', 'Customer disputed the damage charge for '.implode(', ', $disputed));
            Notifier::notify($sup?->owner_id, 'supplier', 'Damage charge disputed', $r->code.': the customer disputed '.implode(', ', $disputed).'. A PartyRent admin will compare the photos and decide.', route('supplier.requests.show', $r, false));
            foreach (\App\Models\User::whereJsonContains('roles', 'admin')->orWhereJsonContains('roles', 'superadmin')->pluck('id') as $aid) {
                Notifier::notify($aid, 'admin', 'Damage dispute', $r->code.': the customer disputed the damage charge for '.implode(', ', $disputed).'.', route('admin.disputes.show', $r, false));
            }
        } else {
            Rentals::history($r, 'inspection', $auto ? 'Damage charge accepted (no answer within '.self::REVIEW_HOURS.' hours)' : 'Customer accepted the damage charge');
            Notifier::notify($sup?->owner_id, 'supplier', 'Damage charge accepted', $r->code.': '.money($insp['compensation'] ?? 0).' accepted'.($auto ? ' (no answer within '.self::REVIEW_HOURS.' hours)' : '').'. You can settle the deposit.', route('supplier.requests.show', $r, false));
        }

        return ['ok' => true, 'disputed' => $disputed];
    }

    /** Admin decision on the disputed items: [productId => {amount, note}]. Sets the final compensation. */
    public static function resolveDispute(RentalRequest $r, array $decisions, int $adminId): array
    {
        $insp = $r->inspection;
        $rev = $insp['review'] ?? null;
        if (! $rev || $rev['status'] !== 'disputed') {
            return ['ok' => false, 'error' => 'There is no open dispute on this rental.'];
        }
        $items = collect($insp['items'])->keyBy(fn ($i) => (string) $i['productId']);
        foreach ($rev['decisions'] as $pid => &$d) {
            if ($d['state'] !== 'disputed') {
                continue;
            }
            $in = (array) ($decisions[$pid] ?? []);
            $name = inv()->product($pid)?->name ?? 'the item';
            if (! isset($in['amount']) || $in['amount'] === '' || ! is_numeric($in['amount'])) {
                return ['ok' => false, 'error' => 'Set the final amount for '.$name.' (0 cancels the charge).'];
            }
            $max = (float) ($items[(string) $pid]['total'] ?? 0);
            $amt = r2(max(0, (float) $in['amount']));
            if ($amt > $max) {
                return ['ok' => false, 'error' => $name.': the final amount cannot be more than the supplier charged ('.money($max).').'];
            }
            $d = array_merge($d, ['state' => 'resolved', 'amount' => $amt, 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 1000)]);
        }
        unset($d);
        $rev['status'] = 'done';
        $rev['resolvedAt'] = now()->toIso8601String();
        $rev['resolvedBy'] = $adminId;
        $insp['review'] = $rev;
        $insp['assessed'] ??= $insp['compensation'];
        $insp['compensation'] = r2(array_sum(array_map(fn ($d) => (float) $d['amount'], $rev['decisions'])));
        $insp['settlement'] = $insp['compensation'] > 0 ? 'pending' : 'settled';
        $r->inspection = $insp;
        $r->save();
        $r->finCache = null;
        Rentals::history($r, 'inspection', 'PartyRent admin decided the damage dispute: '.money($insp['compensation']).' compensation');
        $msg = $r->code.': the damage dispute was decided. Final compensation: '.money($insp['compensation']).'.';
        Notifier::notify($r->customer_id, 'customer', 'Damage dispute decided', $msg, route('rentals.show', $r, false));
        Notifier::notify($r->supplier?->owner_id, 'supplier', 'Damage dispute decided', $msg.' You can settle the deposit now.', route('supplier.requests.show', $r, false));
        Notifier::audit('PartyRent decided the damage dispute', $r->code.' · '.money($insp['compensation']), null, ['supplier_id' => $r->supplier_id, 'type' => 'completed', 'category' => 'cases', 'ref' => $r->code]);

        return ['ok' => true, 'compensation' => $insp['compensation']];
    }

    /** Accept every review whose 48 hours have passed (scheduler). */
    public static function expireReviews(): int
    {
        $n = 0;
        foreach (RentalRequest::where('status', 'inspection')->get() as $r) {
            if (($r->inspection['review']['status'] ?? null) === 'pending' && self::reviewStatus($r) !== 'pending') {
                $n++;
            }
        }

        return $n;
    }
}
