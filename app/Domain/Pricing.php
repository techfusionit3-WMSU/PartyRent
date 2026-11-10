<?php

namespace App\Domain;

use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;

/**
 * Tiered (quantity-based) pricing per date, package pricing, delivery fees (zones or distance) and
 * supplier payment-policy amounts (port of js/core/pricing.js).
 * Prices only ever come from supplier-configured data.
 */
class Pricing
{
    public static function sortedTiers(Product $p): array
    {
        $t = $p->tiers ?? [];
        usort($t, fn ($a, $b) => $a['min'] <=> $b['min']);

        return $t;
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

    /**
     * Price one line.
     *   rental item  per date, tier by that date's quantity
     *   sale item    ONCE: quantities across dates add up, tier by the total quantity
     *   package      by its pricing mode: rental part per date × packages that day,
     *                sale items + services once × the peak number of packages
     * Returns daily rows (`days`), one-time rows (`once`), the rent / sale / service split, the line
     * name and a description (packages), so the snapshot stays readable after the listing changes.
     */
    public static function quoteLine(array $line): array
    {
        $days = array_filter(array_map('intval', $line['days'] ?? []));
        ksort($days);
        $base = ['lineId' => $line['id'] ?? null, 'type' => $line['type'], 'refId' => (int) $line['ref_id'], 'days' => [], 'once' => [], 'rent' => 0.0, 'sale' => 0.0, 'service' => 0.0, 'summary' => []];

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
        foreach ($days as $date => $qty) {
            $unit = $p ? self::unitPrice($p, $qty) : 0.0;
            $base['days'][] = ['date' => $date, 'qty' => $qty, 'unit' => $unit, 'subtotal' => r2($unit * $qty)];
        }
        $rent = r2(array_sum(array_column($base['days'], 'subtotal')));

        return array_merge($base, ['kind' => 'rent', 'name' => $p?->name ?? 'Item', 'rent' => $rent, 'total' => $rent]);
    }

    /** Supplier policy → amounts for a rental total. */
    public static function policyAmounts(Supplier $s, float $rentalTotal, float $itemsSubtotal, string $arrangement, ?float $rentalItems = null): array
    {
        // The security deposit protects returnable items only: a % deposit is taken on rental items,
        // and an order of consumables only (nothing comes back) has no deposit.
        $rentalItems ??= $itemsSubtotal;
        $pol = $s->policy ?? [];
        $usesRes = in_array($arrangement, ['reservation', 'down', 'installment'], true) && ($pol['reservationFee'] ?? 0) > 0;
        $reservationFee = $usesRes ? (float) $pol['reservationFee'] : 0.0;
        $credited = $usesRes ? (bool) ($pol['reservationCredited'] ?? false) : true;
        $downRequired = 0.0;
        if ($arrangement === 'down') {
            $downRequired = ($pol['downType'] ?? 'percent') === 'fixed'
                ? min($rentalTotal, (float) ($pol['downValue'] ?? 0))
                : r2($rentalTotal * ($pol['downValue'] ?? 0) / 100);
        }
        $deposit = $rentalItems <= 0 ? 0.0 : (($pol['depositType'] ?? 'fixed') === 'percent'
            ? r2($rentalItems * ($pol['depositValue'] ?? 0) / 100)
            : (float) ($pol['depositValue'] ?? 0));

        return [
            'reservationFee' => $reservationFee,
            'reservationCredited' => $credited,
            'downRequired' => $downRequired,
            'downPct' => ($pol['downType'] ?? 'percent') === 'fixed' ? null : ($pol['downValue'] ?? 0),
            'deposit' => $deposit,
            'installments' => $arrangement === 'installment' ? max(2, (int) ($pol['installments'] ?? 2)) : 0,
        ];
    }

    /**
     * Delivery fee for one supplier's lines to a customer city.
     *
     *   zones mode     the most specific zone wins: city > province > region
     *   distance mode  baseFee + perKm × max(0, km − freeKm), km from the supplier's pin (or city centre)
 *                  to the customer's city centre; beyond maxKm = not deliverable
     *   roundTrip      true: one fee covers drop-off + retrieval; false: both legs are charged (× 2)
     *   chargePerTrip  each block of consecutive rental dates is a separate trip
     *   freeOver       items subtotal at which the trip fee is waived (handling still applies)
     *   handling       per product, per line or per unit, for bulky items
     *
     *   fee = (waived ? 0 : base × trips) + handling
     *
     * `$settings` overrides the supplier's saved settings (used for the live preview while editing).
     */
    public static function deliveryQuote(Supplier $s, array $lines, float $itemsSubtotal, ?string $cityCode, ?array $settings = null): array
    {
        $d = array_merge(Supplier::DELIVERY_DEFAULTS, $settings ?? $s->deliverySettings());
        $out = [
            'ok' => false, 'error' => null, 'code' => null, 'mode' => $d['mode'], 'cityCode' => $cityCode, 'cityLabel' => Locations::label($cityCode),
            'zone' => null, 'base' => 0.0, 'distanceKm' => null, 'handling' => 0.0, 'trips' => 1, 'legs' => $d['roundTrip'] ? 1 : 2,
            'waived' => false, 'fee' => 0.0, 'breakdown' => [],
        ];
        $fail = fn (string $code, string $msg) => array_merge($out, ['code' => $code, 'error' => $msg]);

        if (! $s->offersDelivery()) {
            return $fail('pickup_only', $s->name.' offers pickup only.');
        }
        if (! $cityCode || ! Locations::isCity($cityCode)) {
            return $fail('no_city', 'Choose your city or municipality to see the delivery fee.');
        }
        foreach (self::deliveredProducts($lines) as [$p]) {
            if (! $p->delivery) {
                return $fail('item_pickup_only', $p->name.' is available for pickup only.');
            }
        }

        $label = $out['cityLabel'];
        $bd = [];
        if ($d['mode'] === 'weight') {
            $tier = self::routeTier($s->city_code, $cityCode);
            if ($tier === null) {
                return $fail('no_origin', $s->name.' has not set its city, so delivery cannot be priced.');
            }
            $out['route'] = $tier;
            $reach = isset(self::TIER_RANK[$d['reach']]) ? $d['reach'] : 'island';
            if (self::TIER_RANK[$tier] > self::TIER_RANK[$reach]) {
                return $fail('out_of_coverage', $s->name.' delivers '.self::REACH_LABEL[$reach].'. '.$label.' is outside that area.');
            }
            $kg = self::chargeableKg($lines);
            $out['weightKg'] = $kg;
            $first = (float) (Settings::get('shipFirstKg')[$tier] ?? 0);
            $perKg = (float) (Settings::get('shipPerKg')[$tier] ?? 0);
            $extraKg = max(0, (int) ceil($kg - 1 - 0.0001));
            $trip = r2($first + $extraKg * $perKg);
            $bd[] = ['label' => 'Delivery '.self::TIER_LABEL[$tier].' · first kg', 'amount' => r2($first)];
            if ($extraKg > 0) {
                $bd[] = ['label' => $extraKg.' more kg × '.money($perKg).' (total ~'.$kg.' kg)', 'amount' => r2($extraKg * $perKg)];
            }
        } elseif ($d['mode'] === 'distance') {
            $km = Locations::distanceKmFrom($s->originCoords(), $cityCode); // from the shop pin, else its city centre
            if ($km === null) {
                return $fail('no_origin', $s->name.' has not set its location, so distance-based delivery cannot be priced.');
            }
            $out['distanceKm'] = $km;
            if ($d['maxKm'] > 0 && $km > $d['maxKm']) {
                return array_merge($fail('out_of_coverage', $s->name.' delivers up to '.(float) $d['maxKm'].' km from '.$s->city.'. '.$label.' is about '.$km.' km away.'), ['distanceKm' => $km]);
            }
            $chargeKm = max(0, $km - (float) $d['freeKm']);
            $distPart = r2($chargeKm * (float) $d['perKm']);
            $trip = r2((float) $d['baseFee'] + $distPart);
            $bd[] = ['label' => 'Base delivery fee', 'amount' => r2($d['baseFee'])];
            if ($d['perKm'] > 0) {
                $bd[] = ['label' => 'Distance ~'.$km.' km'.($d['freeKm'] > 0 ? ' (first '.(float) $d['freeKm'].' km free)' : '').' × '.money($d['perKm']).'/km', 'amount' => $distPart];
            }
        } else {
            $zone = self::matchZone($d['zones'], $cityCode);
            if (! $zone) {
                return $fail('out_of_coverage', $s->name.' does not deliver to '.$label.'.');
            }
            $out['zone'] = $zone + ['name' => Locations::name($zone['code'])];
            $trip = r2($zone['fee']);
            $bd[] = ['label' => 'Delivery to '.$label.($zone['level'] !== 'city' ? ' ('.Locations::name($zone['code']).' rate)' : ''), 'amount' => $trip];
        }

        $legs = $out['legs'];
        if ($legs === 2) {
            $bd[] = ['label' => 'Retrieval trip (charged separately)', 'amount' => $trip];
        }
        $base = r2($trip * $legs);
        $trips = $d['chargePerTrip'] ? max(1, self::dateBlocks($lines)) : 1;
        if ($trips > 1) {
            $bd[] = ['label' => ($trips - 1).' more trip'.($trips > 2 ? 's' : '').' (non-consecutive dates)', 'amount' => r2($base * ($trips - 1))];
        }
        $waived = $d['freeOver'] > 0 && $itemsSubtotal >= (float) $d['freeOver'];
        if ($waived) {
            $bd[] = ['label' => 'Free delivery on items over '.money($d['freeOver']), 'amount' => -r2($base * $trips)];
        }
        // Free Shipping program: PartyRent pays part of the trip fee (not handling) for the customer.
        $voucher = 0.0;
        if (! $waived && self::freeShipActive($s) && $itemsSubtotal > 0 && $itemsSubtotal >= (float) Settings::get('freeShipMinSpend')) {
            $voucher = r2(min((float) Settings::get('freeShipCap'), $base * $trips));
            if ($voucher > 0) {
                $bd[] = ['label' => 'Free Shipping voucher (paid by PartyRent)', 'amount' => -$voucher];
            }
        }
        $handling = 0.0;
        foreach (self::handlingLines($lines) as $h) {
            $bd[] = $h;
            $handling = r2($handling + $h['amount']);
        }

        return array_merge($out, [
            'ok' => true, 'base' => $base, 'trips' => $trips, 'waived' => $waived, 'handling' => $handling, 'voucher' => $voucher,
            'fee' => r2(($waived ? 0 : $base * $trips) - $voucher + $handling), 'breakdown' => $bd,
        ]);
    }

    /* ---------- PartyRent standard rates (weight-based, like Shopee) ---------- */

    public const TIER_RANK = ['local' => 0, 'island' => 1, 'inter' => 2];

    public const TIER_LABEL = ['local' => 'within the province', 'island' => 'within the island group', 'inter' => 'to another island group'];

    public const REACH_LABEL = ['local' => 'within its province only', 'island' => 'within its island group only (Luzon, Visayas or Mindanao)', 'inter' => 'nationwide'];

    /** Island group of each region, by the first two digits of its code. Metro Manila counts as Luzon. */
    public const ISLAND_GROUP = [
        '13' => 'luzon', '14' => 'luzon', '01' => 'luzon', '02' => 'luzon', '03' => 'luzon', '04' => 'luzon', '17' => 'luzon', '05' => 'luzon',
        '06' => 'visayas', '07' => 'visayas', '08' => 'visayas', '18' => 'visayas',
        '09' => 'mindanao', '10' => 'mindanao', '11' => 'mindanao', '12' => 'mindanao', '16' => 'mindanao', '15' => 'mindanao',
    ];

    /** local = same province (all of Metro Manila counts as one), island = same island group, inter = another island group. */
    public static function routeTier(?string $fromCity, ?string $toCity): ?string
    {
        $a = Locations::parentsOf($fromCity);
        $b = Locations::parentsOf($toCity);
        if (! $a['regionCode'] || ! $b['regionCode']) {
            return null;
        }
        $ncr = fn ($p) => str_starts_with((string) $p['regionCode'], '13');
        if (($ncr($a) && $ncr($b)) || ($a['provinceCode'] && $a['provinceCode'] === $b['provinceCode'])) {
            return 'local';
        }
        $g = fn ($p) => self::ISLAND_GROUP[substr((string) $p['regionCode'], 0, 2)] ?? null;

        return $g($a) && $g($a) === $g($b) ? 'island' : 'inter';
    }

    /** Weight that is charged for one unit: the bigger of the actual weight and the size weight (L × W × H ÷ divisor). */
    public static function unitKg(Product $p): float
    {
        $actual = (float) ($p->weight_kg ?? 0);
        $vol = $p->length_cm && $p->width_cm && $p->height_cm
            ? (float) $p->length_cm * (float) $p->width_cm * (float) $p->height_cm / max(1, (float) Settings::get('shipVolDivisor'))
            : 0.0;
        $kg = max($actual, $vol);

        return $kg > 0 ? $kg : (float) Settings::get('shipDefaultKg');
    }

    /** Total charged weight of everything that travels, 1 decimal. */
    public static function chargeableKg(array $lines): float
    {
        $kg = 0.0;
        foreach (self::deliveredProducts($lines) as [$p, $units]) {
            $kg += self::unitKg($p) * $units;
        }

        return round($kg, 1);
    }

    /** Supplier joined the Free Shipping program and the program is switched on. */
    public static function freeShipActive(Supplier $s): bool
    {
        return (bool) $s->free_ship && (bool) Settings::get('freeShipEnabled');
    }

    /** Most specific zone covering a city: city > province > region. */
    public static function matchZone(array $zones, string $cityCode): ?array
    {
        $p = Locations::parentsOf($cityCode);
        foreach (['city' => $p['cityCode'], 'province' => $p['provinceCode'], 'region' => $p['regionCode']] as $level => $code) {
            foreach ($zones as $z) {
                if (($z['level'] ?? null) === $level && (string) ($z['code'] ?? '') === (string) $code) {
                    return ['level' => $level, 'code' => (string) $z['code'], 'fee' => (float) $z['fee']];
                }
            }
        }

        return null;
    }

    /** Number of separate blocks of consecutive dates across all lines (1 for 12,13,14; 2 for 12,13,20). */
    public static function dateBlocks(array $lines): int
    {
        $dates = [];
        foreach ($lines as $l) {
            foreach ($l['days'] ?? [] as $date => $q) {
                if ((int) $q > 0) {
                    $dates[$date] = true;
                }
            }
        }
        $dates = array_keys($dates);
        sort($dates);
        $blocks = 0;
        $prev = null;
        foreach ($dates as $d) {
            if ($prev === null || add_days($prev, 1) !== $d) {
                $blocks++;
            }
            $prev = $d;
        }

        return $blocks;
    }

    /** [Product, units] for every product that would travel, packages expanded into their items. */
    private static function deliveredProducts(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $qs = array_filter(array_map('intval', $l['days'] ?? []));
            if (! $qs) {
                continue;
            }
            $peak = max($qs); // units that have to be brought out for this line
            if ($l['type'] === 'package') {
                $def = inv()->lineDef($l);
                $u = $def ? Packages::units($def, $l['sel'] ?? null) : ['rent' => [], 'sale' => []];
                foreach ($u['rent'] + $u['sale'] as $pid => $q) {
                    if ($p = inv()->product($pid)) {
                        $out[] = [$p, $q * $peak];
                    }
                }
            } elseif ($p = inv()->product($l['ref_id'])) {
                $out[] = [$p, $p->isSale() ? array_sum($qs) : $peak];
            }
        }

        return $out;
    }

    private static function handlingLines(array $lines): array
    {
        $out = [];
        foreach (self::deliveredProducts($lines) as [$p, $units]) {
            if ($p->handling_fee <= 0) {
                continue;
            }
            $perUnit = $p->handling_per === 'unit';
            $out[] = [
                'label' => 'Handling: '.$p->name.($perUnit ? ' × '.$units : ''),
                'amount' => r2($p->handling_fee * ($perUnit ? $units : 1)),
            ];
        }

        return $out;
    }

    /** Quote a set of lines for one supplier. `$cityCode` is the delivery city (ignored for pickup). */
    public static function quote(array $lines, Supplier $s, string $fulfillment, string $arrangement = 'full', ?string $cityCode = null): array
    {
        $lq = array_map([self::class, 'quoteLine'], $lines);
        $rentalSubtotal = r2(array_sum(array_column($lq, 'rent')));
        $salesSubtotal = r2(array_sum(array_column($lq, 'sale')));
        $servicesSubtotal = r2(array_sum(array_column($lq, 'service')));
        $itemsSubtotal = r2($rentalSubtotal + $salesSubtotal + $servicesSubtotal);
        $delivery = $fulfillment === 'delivery' ? self::deliveryQuote($s, $lines, $itemsSubtotal, $cityCode) : null;
        $deliveryFee = $delivery && $delivery['ok'] ? $delivery['fee'] : 0.0;
        $total = r2($itemsSubtotal + $deliveryFee);
        $pol = self::policyAmounts($s, $total, $itemsSubtotal, $arrangement, $rentalSubtotal);
        $payable = r2($total + ($pol['reservationCredited'] ? 0 : $pol['reservationFee']));

        return array_merge([
            'lines' => $lq, 'itemsSubtotal' => $itemsSubtotal,
            'rentalSubtotal' => $rentalSubtotal, 'salesSubtotal' => $salesSubtotal, 'servicesSubtotal' => $servicesSubtotal,
            'deliveryFee' => $deliveryFee, 'delivery' => $delivery,
            'total' => $total, 'payable' => $payable, 'arrangement' => $arrangement,
            'commissionExtraPct' => self::freeShipActive($s) ? (float) Settings::get('freeShipExtraPct') : 0.0,
            'quotedAt' => now()->toIso8601String(),
        ], $pol);
    }

    /** Live re-quote of a request (used to compare against its stored snapshot). */
    public static function quoteRequest(RentalRequest $r): array
    {
        return self::quote($r->lines, $r->supplier, $r->fulfillment, $r->arrangement, $r->city_code);
    }

    /** Package price vs. getting everything separately (one package, one day, default options). */
    public static function packageValue(Package $k, ?array $sel = null): array
    {
        $pp = Packages::price(Packages::def($k), $sel);

        return ['separate' => $pp['separate'], 'price' => $pp['package'], 'savings' => $pp['savings']];
    }
}
