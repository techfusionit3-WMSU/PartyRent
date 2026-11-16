<?php

namespace App\Domain;

use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;

/**
 * Rental Charges of one supplier order (port of reference js/core/pricing.js, v4):
 *   Rental Fee   – tiered (quantity-based) daily prices and package prices
 *   Delivery Fee – per delivery location, from the supplier's delivery zones
 *   Service Fee  – supplier services (setup, preparation, dismantling…)
 * Their sum is the order total, the basis for the payment plan (reservation fee, down payment,
 * final balance / installments). Prices only ever come from supplier-configured data.
 *
 * App additions kept: sale (consumable) items priced once, package pricing modes, hourly items.
 * Change list: a "per location" service is charged once per visit — a run of consecutive days at the
 * same location (see serviceVisits()).
 */
class Pricing
{
    public static function sortedTiers(Product $p): array
    {
        $t = $p->tiers ?? [];
        usort($t, fn ($a, $b) => $a['min'] <=> $b['min']);

        return $t;
    }

    /**
     * Change list C3: the bulk discounts as one short plain-language line instead of a quantity-price table,
     * e.g. "₱5 off per piece for orders of 100+" (+ "· up to ₱8 off at 200+" when there are more steps). '' without discounts.
     */
    public static function bulkLine(Product $p): string
    {
        $t = self::sortedTiers($p);
        if (count($t) < 2) {
            return '';
        }
        $base = (float) $t[0]['price'];
        $word = $p->unitWord() ?: 'piece';
        $amt = fn (float $n) => fmod(round($n, 2), 1.0) == 0.0 ? money_short($n) : money($n);
        $parts = [];
        foreach (array_slice($t, 1) as $x) {
            $off = $base - (float) $x['price'];
            if ($off <= 0) {
                continue;
            }
            $parts[] = [$off, $x['min']];
        }
        if (! $parts) {
            return '';
        }
        // one short line: the first step, plus the deepest discount when there are more
        $line = $amt($parts[0][0]).' off per '.$word.' for orders of '.$parts[0][1].'+';
        $last = end($parts);

        return count($parts) > 1 ? $line.' · up to '.$amt($last[0]).' off at '.$last[1].'+' : $line;
    }

    public static function tierFor(Product $p, int $qty): array
    {
        $tiers = self::sortedTiers($p);
        $t = $tiers[0] ?? ['min' => 1, 'max' => null, 'price' => 0];
        foreach ($tiers as $x) {
            if ($qty >= $x['min'] && ($x['max'] === null || $qty <= $x['max'])) {
                $t = $x;
            }
        }

        return $t;
    }

    public static function unitPrice(Product $p, int $qty): float
    {
        return (float) self::tierFor($p, max(1, $qty))['price'];
    }

    public static function basePrice(Product $p): float
    {
        return (float) (self::sortedTiers($p)[0]['price'] ?? 0);
    }

    public static function lowestPrice(Product $p): float
    {
        $prices = array_column($p->tiers ?? [], 'price');

        return $prices ? (float) min($prices) : 0.0;
    }

    /** Combined daily quantity per product across the order's item lines ("refId|date" => qty). */
    public static function combinedQty(array $lines): array
    {
        $m = [];
        foreach ($lines as $l) {
            if (($l['type'] ?? '') !== 'item') {
                continue;
            }
            foreach ($l['days'] ?? [] as $d => $q) {
                if ((int) $q) {
                    $k = $l['ref_id'].'|'.$d;
                    $m[$k] = ($m[$k] ?? 0) + (int) $q;
                }
            }
        }

        return $m;
    }

    /**
     * Price one line.
     *   rental item  per date, tier by that date's COMBINED quantity of the product in the order
     *   sale item    once: quantities across dates add up, tier by the total quantity
     *   package      by its pricing mode: rental part per date × packages that day,
     *                sale items + services once × the peak number of packages
     */
    public static function quoteLine(array $line, ?array $combined = null): array
    {
        $days = array_filter(array_map('intval', $line['days'] ?? []));
        ksort($days);
        $base = [
            'lineId' => $line['id'] ?? null, 'type' => $line['type'], 'refId' => (int) $line['ref_id'],
            'destId' => $line['destId'] ?? null, 'sourceId' => $line['sourceId'] ?? null, 'time' => $line['time'] ?? null,
            'days' => [], 'once' => [], 'rent' => 0.0, 'sale' => 0.0, 'service' => 0.0, 'summary' => [],
        ];

        if ($line['type'] === 'package') {
            $def = inv()->lineDef($line);
            if (! $def || ! $days) {
                return $base + ['kind' => 'package', 'name' => $def['name'] ?? 'Package', 'total' => 0.0];
            }
            $pp = Packages::price($def, $line['sel'] ?? null);
            foreach ($days as $date => $n) {
                $base['days'][] = ['date' => $date, 'qty' => $n, 'unit' => $pp['perDay'], 'subtotal' => r2($pp['perDay'] * $n)];
            }
            $peak = max($days);
            foreach (['sale' => 'Sale items (consumed, not returned)', 'service' => 'Services'] as $k => $label) {
                if ($pp[$k] > 0) {
                    $base['once'][] = ['label' => $label, 'qty' => $peak, 'unit' => $pp[$k], 'subtotal' => r2($pp[$k] * $peak)];
                }
            }
            $rent = r2(array_sum(array_column($base['days'], 'subtotal')));
            $sale = r2($pp['sale'] * $peak);
            $service = r2($pp['service'] * $peak);

            return array_merge($base, [
                'kind' => 'package', 'name' => $def['name'], 'summary' => Packages::describe($def, $pp['sel']), 'sel' => $pp['sel'],
                'rent' => $rent, 'sale' => $sale, 'service' => $service, 'total' => r2($rent + $sale + $service),
            ]);
        }

        $p = inv()->product($line['ref_id']);
        if ($p && $p->isSale()) {
            $qty = array_sum($days);
            $unit = $qty ? self::unitPrice($p, $qty) : 0.0;
            $total = r2($unit * $qty);

            return array_merge($base, [
                'kind' => 'sale', 'name' => $p->name, 'unitWord' => $p->unitWord(), 'dates' => array_keys($days),
                'once' => $qty ? [['label' => $p->name, 'qty' => $qty, 'unit' => $unit, 'subtotal' => $total]] : [],
                'sale' => $total, 'total' => $total,
            ]);
        }
        // Hourly items: the tier price is per hour, so one unit costs price × hours of the booking.
        $hours = $p && $p->isHourly() ? Hours::length($line['time'] ?? null) : 0.0;
        foreach ($days as $date => $qty) {
            $tierQty = $combined[$line['ref_id'].'|'.$date] ?? $qty;
            $unit = $p ? self::unitPrice($p, $tierQty) : 0.0;
            $row = ['date' => $date, 'qty' => $qty, 'unit' => $hours ? r2($unit * $hours) : $unit];
            if ($hours) {
                $row += ['hourly' => $unit, 'hours' => $hours];
            }
            $base['days'][] = $row + ['subtotal' => r2($row['unit'] * $qty)];
        }
        $rent = r2(array_sum(array_column($base['days'], 'subtotal')));
        $name = ($p?->name ?? 'Item').($hours ? ' · '.Hours::label($line['time']) : '');

        return array_merge($base, ['kind' => 'rent', 'name' => $name, 'rent' => $rent, 'total' => $rent] + ($hours ? ['hours' => $hours] : []));
    }

    /* ---------- delivery ---------- */

    public static function zones(?Supplier $s): array
    {
        return $s?->delivery_zones ?? [];
    }

    public static function zoneFee(?Supplier $s, ?string $zoneId): float
    {
        foreach (self::zones($s) as $z) {
            if (($z['id'] ?? null) === $zoneId) {
                return (float) $z['fee'];
            }
        }

        return (float) ($s?->delivery_fee ?? 0);
    }

    public static function zoneName(?Supplier $s, ?string $zoneId): string
    {
        foreach (self::zones($s) as $z) {
            if (($z['id'] ?? null) === $zoneId) {
                return $z['name'];
            }
        }

        return 'Other areas (standard rate)';
    }

    /* ---------- services ---------- */

    /**
     * Number of service visits (change list): for each delivery location, every run of consecutive dates
     * counts once. Consecutive days at one place = 1; a change of place = +1; each separate block of dates = +1.
     * Pickup orders (no locations) count their separate date blocks.
     */
    public static function serviceVisits(array $lines, array $destinations = []): int
    {
        $byDest = [];
        foreach ($lines as $l) {
            $dest = $destinations ? ($l['destId'] ?? ($destinations[0]['id'] ?? 'd1')) : '_';
            foreach ($l['days'] ?? [] as $d => $q) {
                if ((int) $q > 0) {
                    $byDest[$dest][$d] = true;
                }
            }
        }
        $visits = 0;
        foreach ($byDest as $dates) {
            $visits += self::blocks(array_keys($dates));
        }

        return max(1, $visits);
    }

    /** Number of runs of consecutive dates (1 for 12,13,14; 2 for 12,13,20). */
    public static function blocks(array $dates): int
    {
        sort($dates);
        $n = 0;
        $prev = null;
        foreach ($dates as $d) {
            if ($prev === null || add_days($prev, 1) !== $d) {
                $n++;
            }
            $prev = $d;
        }

        return $n;
    }

    /** Back-compat name: blocks of consecutive dates across all lines. */
    public static function dateBlocks(array $lines): int
    {
        $dates = [];
        foreach ($lines as $l) {
            foreach ($l['days'] ?? [] as $d => $q) {
                if ((int) $q > 0) {
                    $dates[$d] = true;
                }
            }
        }

        return self::blocks(array_keys($dates));
    }

    /** Required services are always included; optional ones only when the customer selects them. */
    public static function serviceLines(?Supplier $s, array $selected, int $visits): array
    {
        $out = [];
        foreach ($s?->services ?? [] as $x) {
            if (($x['active'] ?? true) === false || (empty($x['required']) && ! in_array($x['id'], $selected, true))) {
                continue;
            }
            $per = $x['per'] ?? 'order';
            $qty = $per === 'location' ? max(1, $visits) : 1;
            $out[] = [
                'id' => $x['id'], 'name' => $x['name'], 'description' => $x['description'] ?? '', 'unit' => (float) $x['amount'],
                'qty' => $qty, 'per' => $per, 'required' => ! empty($x['required']), 'amount' => r2((float) $x['amount'] * $qty),
            ];
        }

        return $out;
    }

    /**
     * Quote one supplier order.
     * $o = [supplier, lines, fulfillment, destinations: [{id, label, zoneId}], plan, services: [ids]]
     */
    public static function quoteOrder(array $o): array
    {
        /** @var Supplier|null $s */
        $s = $o['supplier'] ?? null;
        $lines = array_values($o['lines'] ?? []);
        $combined = self::combinedQty($lines);
        $lq = array_map(fn ($l) => self::quoteLine($l, $combined), $lines);
        $rentalSubtotal = r2(array_sum(array_column($lq, 'rent')));
        $salesSubtotal = r2(array_sum(array_column($lq, 'sale')));
        $packageServices = r2(array_sum(array_column($lq, 'service')));
        $rentalFee = r2(array_sum(array_column($lq, 'total')));

        $isDelivery = ($o['fulfillment'] ?? 'pickup') === 'delivery';
        $dests = $isDelivery ? (($o['destinations'] ?? []) ?: [['id' => 'd1', 'label' => 'Delivery address', 'zoneId' => '']]) : [];
        $delivery = array_map(fn ($d) => [
            'destId' => $d['id'], 'label' => ($d['label'] ?? '') ?: 'Delivery address', 'zoneId' => $d['zoneId'] ?? '',
            'zone' => self::zoneName($s, $d['zoneId'] ?? null), 'fee' => self::zoneFee($s, $d['zoneId'] ?? null),
        ], $dests);
        $deliveryFee = r2(array_sum(array_column($delivery, 'fee')));

        $visits = self::serviceVisits($lines, $dests);
        $services = self::serviceLines($s, $o['services'] ?? [], $visits);
        $serviceFee = r2(array_sum(array_column($services, 'amount')));

        $total = r2($rentalFee + $deliveryFee + $serviceFee);
        $plan = in_array($o['plan'] ?? null, Finance::PLANS, true) ? $o['plan'] : 'full';
        $terms = Finance::planConfig($s)[$plan];
        $reservationFee = $plan === 'full' ? 0.0 : r2($total * ($terms['resPct'] ?? 0) / 100);
        $downPayment = $plan === 'full' ? 0.0 : r2($total * ($terms['downPct'] ?? 0) / 100);
        $pol = $s?->policy ?? [];
        // The security deposit protects returnable items only.
        $returnable = $rentalSubtotal;
        $deposit = $returnable <= 0 ? 0.0 : (($pol['depositType'] ?? 'fixed') === 'percent'
            ? r2($returnable * ($pol['depositValue'] ?? 0) / 100)
            : (float) ($pol['depositValue'] ?? 0));

        return [
            'lines' => $lq, 'rentalFee' => $rentalFee, 'itemsSubtotal' => $rentalFee,
            'rentalSubtotal' => $rentalSubtotal, 'salesSubtotal' => $salesSubtotal, 'servicesSubtotal' => $packageServices,
            'delivery' => $delivery, 'deliveryFee' => $deliveryFee, 'services' => $services, 'serviceFee' => $serviceFee, 'serviceVisits' => $visits,
            'total' => $total, 'payable' => $total, 'plan' => $plan, 'terms' => $terms,
            'reservationFee' => $reservationFee, 'downPayment' => $downPayment, 'finalBalance' => r2($total - $reservationFee - $downPayment),
            'deposit' => $deposit, 'voucher' => 0.0, 'commissionExtraPct' => 0.0,
            'quotedAt' => now()->toIso8601String(),
        ];
    }

    /** Back-compatible: quote a set of lines for one supplier. */
    public static function quote(array $lines, Supplier $s, string $fulfillment, string $plan = 'full', array $extra = []): array
    {
        return self::quoteOrder(array_merge(['supplier' => $s, 'lines' => $lines, 'fulfillment' => $fulfillment, 'plan' => $plan], $extra));
    }

    /** Live re-quote of a request. */
    public static function quoteRequest(RentalRequest $r): array
    {
        return self::quoteOrder([
            'supplier' => $r->supplier, 'lines' => $r->lines, 'fulfillment' => $r->fulfillment,
            'destinations' => $r->destinations ?? [], 'plan' => $r->plan ?? 'full', 'services' => $r->services ?? [],
        ]);
    }

    /** Package price vs. getting everything separately (one package, one day, default options). */
    public static function packageValue(Package $k, ?array $sel = null): array
    {
        $pp = Packages::price(Packages::def($k), $sel);

        return ['separate' => $pp['separate'], 'price' => $pp['package'], 'savings' => $pp['savings']];
    }
}
