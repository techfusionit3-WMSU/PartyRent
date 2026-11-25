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
 * Their sum is the order total, the basis for the payment plan (down payment,
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

    /** Largest bulk discount a supplier may set, in percent. */
    public const MAX_BULK_PCT = 90;

    /** Most bulk discount tiers per item. */
    public const MAX_BULK_TIERS = 7;

    /** "10" / "12.5": a percent without trailing zeros. */
    public static function pctText(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
    }

    /**
     * Percent off the base price of one tier: the stored 'pct' (tiers saved since bulk discounts became percentages),
     * else worked out from its price (older tiers kept a price only). 0 for the base tier.
     */
    public static function tierPct(array $tier, float $base): float
    {
        if (isset($tier['pct'])) {
            return (float) $tier['pct'];
        }
        if ($base <= 0 || (int) ($tier['min'] ?? 1) <= 1) {
            return 0.0;
        }

        return max(0.0, round((1 - (float) $tier['price'] / $base) * 100, 2));
    }

    /**
     * The bulk discount tiers of an item for forms and price lists: [['min', 'max', 'pct', 'price'], …] without the
     * base tier. Steps that don't lower the price are left out.
     */
    public static function bulkSteps(Product $p): array
    {
        $t = self::sortedTiers($p);
        $base = (float) ($t[0]['price'] ?? 0);
        $out = [];
        foreach (array_slice($t, 1) as $x) {
            $pct = self::tierPct($x, $base);
            if ($pct > 0) {
                $out[] = ['min' => (int) $x['min'], 'max' => $x['max'] ?? null, 'pct' => $pct, 'price' => (float) $x['price']];
            }
        }

        return $out;
    }

    /** Price of one piece at $pct off $base, rounded to the centavo once (the same rounding the form preview uses). */
    public static function discounted(float $base, float $pct): float
    {
        return r2($base * (1 - $pct / 100));
    }

    /**
     * Change list C3: the bulk discounts as one short plain-language line instead of a quantity-price table,
     * e.g. "5% off for 5+ pieces · up to 15% off at 20+". '' without discounts.
     */
    public static function bulkLine(Product $p): string
    {
        $steps = self::bulkSteps($p);
        if (! $steps) {
            return '';
        }
        $words = $p->unitWord() ?: 'piece';
        $plural = str_ends_with($words, 's') ? $words : $words.'s';
        $line = self::pctText($steps[0]['pct']).'% off for '.$steps[0]['min'].'+ '.$plural;
        $last = end($steps);

        return count($steps) > 1 ? $line.' · up to '.self::pctText($last['pct']).'% off at '.$last['min'].'+' : $line;
    }

    /**
     * Base price + bulk discount tiers ([['min' => n, 'pct' => %], …]) → continuous tiers starting at 1, the last one
     * open-ended; each discount tier keeps its percent and its price (base − %), so the price follows the base price.
     * An older ['min', 'price'] row is still read (turned into a percent). Returns an error message when:
     *   a row is half filled in · a tier starts below 2 · two tiers start at the same quantity · a percent is not
     *   between 1 and 90 · a bigger quantity does not get a bigger discount · a tier starts above the stock
     *   ($stock, when known) · more than 7 tiers.
     */
    public static function tiersFrom(float $base, array $bulk, ?int $stock = null): array|string
    {
        $blank = fn ($v) => $v === null || $v === '';
        $steps = collect();
        foreach ($bulk as $b) {
            $min = $b['min'] ?? null;
            $pct = $b['pct'] ?? null;
            if ($blank($pct) && ! $blank($b['price'] ?? null) && $base > 0) {
                $pct = round((1 - (float) $b['price'] / $base) * 100, 2); // older price rows
            }
            if ($blank($min) && $blank($pct)) {
                continue; // empty row
            }
            if ($blank($min) || $blank($pct)) {
                return 'Fill in both the quantity and the % off for each bulk discount, or remove the row.';
            }
            $steps->push(['min' => (int) $min, 'pct' => round((float) $pct, 2)]);
        }
        $steps = $steps->sortBy('min')->values();
        if ($steps->count() > self::MAX_BULK_TIERS) {
            return 'Up to '.self::MAX_BULK_TIERS.' bulk discounts per item.';
        }
        if ($steps->contains(fn ($b) => $b['min'] < 2)) {
            return 'A bulk discount starts at 2 or more pieces.';
        }
        if ($steps->pluck('min')->duplicates()->isNotEmpty()) {
            return 'Each bulk discount needs a different quantity.';
        }
        if ($bad = $steps->first(fn ($b) => $b['pct'] < 1 || $b['pct'] > self::MAX_BULK_PCT)) {
            return 'The discount for '.$bad['min'].'+ pieces must be between 1% and '.self::MAX_BULK_PCT.'%.';
        }
        foreach ($steps as $i => $b) {
            if ($i > 0 && $b['pct'] <= $steps[$i - 1]['pct']) {
                return 'Bigger quantities need a bigger discount: '.$b['min'].'+ pieces ('.self::pctText($b['pct']).'%) must be more than '.$steps[$i - 1]['min'].'+ pieces ('.self::pctText($steps[$i - 1]['pct']).'%).';
            }
        }
        if ($stock !== null && $stock > 0 && ($over = $steps->first(fn ($b) => $b['min'] > $stock))) {
            return 'The discount for '.$over['min'].'+ pieces can never apply: you have '.$stock.' in stock.';
        }
        if ($steps->contains(fn ($b) => self::discounted($base, $b['pct']) <= 0)) {
            return 'A bulk discount cannot make the price ₱0.';
        }
        $all = collect([['min' => 1, 'pct' => 0.0]])->concat($steps)->values();

        return $all->map(function ($t, $i) use ($all, $base) {
            $row = ['min' => $t['min'], 'max' => isset($all[$i + 1]) ? $all[$i + 1]['min'] - 1 : null, 'price' => $i ? self::discounted($base, $t['pct']) : r2($base)];

            return $i ? $row + ['pct' => $t['pct']] : $row;
        })->all();
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
    public static function quoteLine(array $line, ?array $combined = null, ?string $at = null): array
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
            $gross = r2($unit * $qty);
            $disc = $qty ? Discounts::best($p, [['qty' => $qty, 'unit' => $unit]], $at) : null; // shop discount (C11)
            $total = r2($gross - ($disc['amount'] ?? 0));

            return array_merge($base, [
                'kind' => 'sale', 'name' => $p->name, 'unitWord' => $p->unitWord(), 'dates' => array_keys($days),
                'once' => $qty ? [['label' => $p->name, 'qty' => $qty, 'unit' => $unit, 'subtotal' => $gross]] : [],
                'gross' => $gross, 'discount' => $disc, 'sale' => $total, 'total' => $total,
                'bulkSaved' => $qty ? r2(max(0, $p->basePrice() - $unit) * $qty) : 0.0, // already in the unit price; shown, never taken off again
            ]);
        }
        // Hourly items: the tier price is per hour, so one unit costs price × hours of the booking.
        // Bulk discount: the tier comes from the date's combined quantity of the item and is taken once, in the unit
        // price; a shop discount is then worked out on that lower price (Discounts::best), never on the base price.
        $hours = $p && $p->isHourly() ? Hours::length($line['time'] ?? null) : 0.0;
        $bulkSaved = 0.0;
        foreach ($days as $date => $qty) {
            $tierQty = $combined[$line['ref_id'].'|'.$date] ?? $qty;
            $unit = $p ? self::unitPrice($p, $tierQty) : 0.0;
            $bulkSaved += $p ? max(0, $p->basePrice() - $unit) * ($hours ?: 1) * $qty : 0;
            $row = ['date' => $date, 'qty' => $qty, 'unit' => $hours ? r2($unit * $hours) : $unit];
            if ($hours) {
                $row += ['hourly' => $unit, 'hours' => $hours];
            }
            $base['days'][] = $row + ['subtotal' => r2($row['unit'] * $qty)];
        }
        $gross = r2(array_sum(array_column($base['days'], 'subtotal')));
        $disc = $p && $base['days'] ? Discounts::best($p, $base['days'], $at) : null; // shop discount (C11)
        $rent = r2($gross - ($disc['amount'] ?? 0));
        $name = ($p?->name ?? 'Item').($hours ? ' · '.Hours::label($line['time']) : '');

        return array_merge($base, ['kind' => 'rent', 'name' => $name, 'gross' => $gross, 'discount' => $disc, 'rent' => $rent, 'total' => $rent, 'bulkSaved' => r2($bulkSaved)] + ($hours ? ['hours' => $hours] : []));
    }

    /* ---------- delivery ---------- */

    /**
     * Delivery of one destination from the fulfilling branch (the origin; the supplier's own pin when no branch).
     * Distance pricing (shop setting "By distance"): base fee + per km beyond the free km (both ways when the shop
     * charges the round trip), refused beyond its maximum km. Other shops keep their delivery zones / standard rate.
     * The distance is measured from the branch pin (or its city) to the address pin. Returns
     * ['fee', 'km' (null when unknown), 'ok', 'basis' (distance|zone), 'note' (why not deliverable)].
     */
    public static function deliveryFor(?Supplier $s, ?\App\Models\InventoryLocation $branch, array $d): array
    {
        $set = array_merge(Supplier::DELIVERY_DEFAULTS, (array) ($s?->delivery ?? []));
        $from = $branch?->point() ?? ($s && $s->lat !== null && $s->lng !== null ? [(float) $s->lat, (float) $s->lng] : null);
        $to = isset($d['lat'], $d['lng']) && is_numeric($d['lat']) && is_numeric($d['lng']) ? [(float) $d['lat'], (float) $d['lng']] : null;
        $km = $from && $to ? Locations::roadKm($from, $to) : null;
        if (($set['mode'] ?? '') === 'distance' && (float) $set['perKm'] > 0 && $km !== null) {
            if ((float) $set['maxKm'] > 0 && $km > (float) $set['maxKm']) {
                return ['fee' => 0.0, 'km' => $km, 'ok' => false, 'basis' => 'distance',
                    'note' => ($branch?->name ?? $s?->name ?? 'The shop').' delivers up to '.rtrim(rtrim(number_format((float) $set['maxKm'], 1), '0'), '.').' km (your address is about '.$km.' km away)'];
            }
            $billed = max(0, $km - (float) $set['freeKm']) * (! empty($set['roundTrip']) ? 2 : 1);

            return ['fee' => r2((float) $set['baseFee'] + $billed * (float) $set['perKm']), 'km' => $km, 'ok' => true, 'basis' => 'distance', 'note' => null];
        }

        return ['fee' => self::zoneFee($s, $d['zoneId'] ?? null), 'km' => $km, 'ok' => true, 'basis' => 'zone', 'note' => null];
    }

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

    /**
     * The shop's delivery zone for an address, worked out by the system (never chosen by the customer).
     * A zone's name lists its places separated by "/" ("Sta. Maria / Tetuan"). The barangay is compared first, then
     * the city / municipality; only an address typed without a known city is searched for place names.
     * '' = not in a listed zone (standard rate).
     */
    public static function zoneForAddress(?Supplier $s, array $d): string
    {
        $zones = self::zones($s);
        if (! $zones) {
            return '';
        }
        $places = fn ($z) => array_values(array_filter(array_map(fn ($p) => Locations::normBarangay(Locations::norm(trim($p))), explode('/', (string) ($z['name'] ?? '')))));
        $brgy = Locations::normBarangay($d['addr']['barangay'] ?? ($d['barangay'] ?? ''));
        $cityCode = $d['cityCode'] ?? ($d['addr']['cityCode'] ?? null);
        $city = $cityCode ? Locations::normBarangay(Locations::norm(Locations::name($cityCode))) : '';
        foreach ([$brgy, $city] as $want) {
            if ($want === '') {
                continue;
            }
            foreach ($zones as $z) {
                if (in_array($want, $places($z), true)) {
                    return (string) $z['id'];
                }
            }
        }
        if ($cityCode) {
            return ''; // a known barangay and city that no zone names: not in a listed zone (never guessed from the text)
        }
        $text = ' '.Locations::normBarangay((string) ($d['address'] ?? '')).' ';
        foreach ($zones as $z) {
            foreach ($places($z) as $p) {
                if (mb_strlen($p) >= 3 && str_contains($text, ' '.$p.' ')) {
                    return (string) $z['id'];
                }
            }
        }

        return '';
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
        $at = $o['at'] ?? null; // day the request is placed: decides which shop discounts apply
        $lq = array_map(fn ($l) => self::quoteLine($l, $combined, $at), $lines);
        $discounts = array_values(array_filter(array_map(fn ($l) => ! empty($l['discount']) ? $l['discount'] + ['item' => $l['name']] : null, $lq)));
        $discount = r2(array_sum(array_column($discounts, 'amount')));
        // A whole-order discount replaces the item discounts when it saves the customer more (one shop discount at a time).
        $orderDisc = $s ? Discounts::bestOrder($s->id, $lq, $at) : null;
        if ($orderDisc && $orderDisc['amount'] > $discount) {
            foreach ($lq as &$ql) {
                if (! empty($ql['discount'])) {
                    $k = $ql['kind'] === 'sale' ? 'sale' : 'rent';
                    $ql[$k] = $ql['total'] = r2((float) $ql['gross']);
                    $ql['discount'] = null;
                }
            }
            unset($ql);
            // spread the order discount over the covered lines (by their share) so line totals still add up
            $left = $orderDisc['amount'];
            $covered = array_keys(array_filter($lq, fn ($l) => in_array($l['kind'] ?? '', ['rent', 'sale'], true) && ($p = inv()->product($l['refId'] ?? 0)) && \App\Models\Discount::find($orderDisc['id'])?->covers($p)));
            foreach ($covered as $n => $i) {
                $k = $lq[$i]['kind'] === 'sale' ? 'sale' : 'rent';
                $share = $n === count($covered) - 1 ? $left : r2($orderDisc['amount'] * (float) $lq[$i]['gross'] / max(0.01, $orderDisc['base']));
                $share = min($share, (float) $lq[$i]['total']);
                $lq[$i][$k] = $lq[$i]['total'] = r2((float) $lq[$i]['total'] - $share);
                $lq[$i]['orderShare'] = $share;
                $left = r2($left - $share);
            }
            $discounts = [$orderDisc + ['item' => 'Whole order']];
            $discount = (float) $orderDisc['amount'];
        }
        $rentalSubtotal = r2(array_sum(array_column($lq, 'rent')));
        $salesSubtotal = r2(array_sum(array_column($lq, 'sale')));
        $packageServices = r2(array_sum(array_column($lq, 'service')));
        $rentalFee = r2(array_sum(array_column($lq, 'total')));

        $isDelivery = ($o['fulfillment'] ?? 'pickup') === 'delivery';
        $dests = $isDelivery ? (($o['destinations'] ?? []) ?: [['id' => 'd1', 'label' => 'Delivery address', 'zoneId' => '']]) : [];
        // delivered from the fulfilling branch (multi-branch suppliers): its location decides distance-based fees
        $branch = $o['branch'] ?? null;
        $delivery = array_map(function ($d) use ($s, $branch) {
            $f = self::deliveryFor($s, $branch, $d);

            return [
                'destId' => $d['id'], 'label' => ($d['label'] ?? '') ?: 'Delivery address', 'zoneId' => $d['zoneId'] ?? '',
                'zone' => $f['basis'] === 'distance' ? $f['km'].' km from '.($branch?->name ?? $s?->name) : self::zoneName($s, $d['zoneId'] ?? null),
                'fee' => $f['fee'], 'km' => $f['km'], 'basis' => $f['basis'],
                // false = the address is in none of the shop's listed zones (standard rate): the customer is told
                'matched' => $f['basis'] === 'distance' || ($d['zoneId'] ?? '') !== '' || ! self::zones($s),
            ];
        }, $dests);
        $deliveryFee = r2(array_sum(array_column($delivery, 'fee')));

        $visits = self::serviceVisits($lines, $dests);
        $services = self::serviceLines($s, $o['services'] ?? [], $visits);
        $serviceFee = r2(array_sum(array_column($services, 'amount')));

        $total = r2($rentalFee + $deliveryFee + $serviceFee);
        $plan = in_array($o['plan'] ?? null, Finance::PLANS, true) ? $o['plan'] : 'full';
        $terms = Finance::planConfig($s)[$plan];
        $reservationFee = 0.0; // no separate reservation fee: the down payment reserves the booking
        $downPayment = $plan === 'full' ? 0.0 : r2($total * ($terms['downPct'] ?? 0) / 100);
        $pol = $s?->policy ?? [];
        // The security deposit protects returnable items only.
        $returnable = $rentalSubtotal;
        $deposit = $returnable <= 0 ? 0.0 : (($pol['depositType'] ?? 'fixed') === 'percent'
            ? r2($returnable * ($pol['depositValue'] ?? 0) / 100)
            : (float) ($pol['depositValue'] ?? 0));

        return [
            'lines' => $lq, 'rentalFee' => $rentalFee, 'itemsSubtotal' => $rentalFee, 'discount' => $discount, 'discounts' => $discounts,
            'bulkSaved' => r2(array_sum(array_map(fn ($l) => (float) ($l['bulkSaved'] ?? 0), $lq))), // informational: already in the Rental Fee
            'rentalSubtotal' => $rentalSubtotal, 'salesSubtotal' => $salesSubtotal, 'servicesSubtotal' => $packageServices,
            'delivery' => $delivery, 'deliveryFee' => $deliveryFee, 'services' => $services, 'serviceFee' => $serviceFee, 'serviceVisits' => $visits,
            'total' => $total, 'payable' => $total, 'plan' => $plan, 'terms' => $terms,
            'reservationFee' => $reservationFee, 'downPayment' => $downPayment, 'finalBalance' => r2($total - $reservationFee - $downPayment),
            'deposit' => $deposit, 'voucher' => 0.0, 'commissionExtraPct' => 0.0,
            'quotedAt' => now()->toIso8601String(),
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name, 'address' => $branch->address] : null,
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
            'at' => $r->created_at?->format('Y-m-d'), 'branch' => $r->branch_id ? $r->branch : null,
        ]);
    }

    /** Package price vs. getting everything separately (one package, one day, default options). */
    public static function packageValue(Package $k, ?array $sel = null): array
    {
        $pp = Packages::price(Packages::def($k), $sel);

        return ['separate' => $pp['separate'], 'price' => $pp['package'], 'savings' => $pp['savings']];
    }
}
