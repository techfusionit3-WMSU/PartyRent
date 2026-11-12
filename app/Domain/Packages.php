<?php

namespace App\Domain;

use App\Models\Package;
use App\Models\Product;
use App\Models\Supplier;

/**
 * Supplier-built packages: what is inside, what the customer may choose, and what it costs.
 *
 * Definition ("def"), as stored on the package and frozen into each booked request line (`snap`):
 *   pricing    {mode: fixed | sum | sum_discount, price, discountPct}
 *   components [
 *     {kind: rent,    productId, qty, perGuest?}                  included rental item
 *     {kind: sale,    productId, qty, perGuest?}                  included consumable (sold, not returned)
 *     {kind: service, name, price, qty, unit}                     e.g. setup crew, styling, host
 *     {kind: addon,   productId? | name+price, qty, price?, default}  optional, the customer ticks it
 *     {kind: choice,  label, pick: 1, options: [{productId, qty, priceDelta, perGuest?}]}
 *   ]
 *   minGuests, maxGuests   with perGuest set, qty = ceil(perGuest × guests)
 *   stages     [{name, hint}]  e.g. Preparation / Ceremony / Reception; any component may carry `stage` (index)
 *   service.teamsPerDay        how many of this service the shop can do on one date (null = no limit)
 *
 * Selection ("sel") saved on the cart / request line: {choices: {componentIndex: optionIndex}, addons: [componentIndex], guests,
 *   stages: [{time: "HH:MM"|"", venue}] one per package stage}
 *
 * Pricing of ONE package:
 *   fixed         price per day covers everything included (at minGuests and the first option of each choice)
 *                 + choice priceDelta × qty + extra per-guest units + add-ons
 *   sum           sum of every included component and chosen option at its own price, + add-ons
 *   sum_discount  the same sum minus discountPct %, add-ons not discounted
 *   Rental parts are charged per day (× packages that day); sale items and services once (× peak packages).
 */
class Packages
{
    public const MODES = ['fixed' => 'Fixed price', 'sum' => 'Sum of components', 'sum_discount' => 'Sum minus a discount'];

    /* ---------------- definition ---------------- */

    /** Normalised definition from a package model (old item-list packages included) or a stored snapshot. */
    public static function def(Package|array $k): array
    {
        if (is_array($k)) {
            return self::normalize($k);
        }
        $components = $k->components;
        if (! $components) { // legacy package: items + fixed daily price
            $components = array_map(fn ($it) => ['kind' => 'rent', 'productId' => (int) $it['product_id'], 'qty' => (int) $it['qty']], $k->items ?? []);
        }

        return self::normalize([
            'id' => $k->id, 'name' => $k->name, 'emoji' => $k->emoji,
            'pricing' => $k->pricing ?: ['mode' => 'fixed', 'price' => (float) $k->price, 'discountPct' => 0],
            'components' => $components, 'minGuests' => $k->min_guests, 'maxGuests' => $k->max_guests, 'stages' => $k->stages ?? [],
        ]);
    }

    public static function normalize(array $d): array
    {
        $p = $d['pricing'] ?? [];
        $mode = array_key_exists($p['mode'] ?? '', self::MODES) ? $p['mode'] : 'fixed';
        $num = fn ($v, $min = 0) => max($min, (float) ($v ?? 0));
        $stages = array_slice(array_values(array_map(fn ($s) => [
            'name' => mb_substr(trim((string) ($s['name'] ?? '')), 0, 60), 'hint' => mb_substr(trim((string) ($s['hint'] ?? '')), 0, 120),
        ], array_filter((array) ($d['stages'] ?? []), 'is_array'))), 0, 8);
        $comps = [];
        foreach (array_values($d['components'] ?? []) as $c) {
            $raw = $c;
            $kind = $c['kind'] ?? 'rent';
            $c = match ($kind) {
                'rent', 'sale' => ['kind' => $kind, 'productId' => (int) ($c['productId'] ?? 0), 'qty' => max(1, (int) ($c['qty'] ?? 1)), 'perGuest' => ($c['perGuest'] ?? null) ? $num($c['perGuest']) : null],
                'service' => ['kind' => 'service', 'name' => trim((string) ($c['name'] ?? '')), 'price' => r2($num($c['price'] ?? 0)), 'qty' => max(1, (int) ($c['qty'] ?? 1)), 'unit' => trim((string) ($c['unit'] ?? '')) ?: 'service',
                    'teamsPerDay' => ($c['teamsPerDay'] ?? null) ? max(1, (int) $c['teamsPerDay']) : null],
                'addon' => [
                    'kind' => 'addon', 'productId' => ($c['productId'] ?? null) ? (int) $c['productId'] : null, 'name' => trim((string) ($c['name'] ?? $c['service'] ?? '')),
                    'price' => isset($c['price']) && $c['price'] !== '' && $c['price'] !== null ? r2($num($c['price'])) : null,
                    'qty' => max(1, (int) ($c['qty'] ?? 1)), 'default' => (bool) ($c['default'] ?? false),
                ],
                'choice' => ['kind' => 'choice', 'label' => trim((string) ($c['label'] ?? '')) ?: 'Choose one', 'pick' => 1, 'options' => array_values(array_map(fn ($o) => [
                    'productId' => (int) ($o['productId'] ?? 0), 'qty' => max(1, (int) ($o['qty'] ?? 1)), 'priceDelta' => r2((float) ($o['priceDelta'] ?? 0)),
                    'perGuest' => ($o['perGuest'] ?? null) ? $num($o['perGuest']) : null,
                ], $c['options'] ?? []))],
                default => null,
            };
            if ($c) {
                $st = $raw['stage'] ?? null;
                $c['stage'] = $st !== null && $st !== '' && isset($stages[(int) $st]) ? (int) $st : null;
                $comps[] = $c;
            }
        }
        // Spec shape `perGuest: [{productId, qtyPerGuest}]` is folded into the matching components.
        foreach ($d['perGuest'] ?? [] as $pg) {
            foreach ($comps as &$c) {
                if (in_array($c['kind'], ['rent', 'sale'], true) && $c['productId'] === (int) $pg['productId']) {
                    $c['perGuest'] = $num($pg['qtyPerGuest'] ?? 0) ?: null;
                }
            }
            unset($c);
        }
        $min = ($d['minGuests'] ?? null) ? (int) $d['minGuests'] : null;
        $max = ($d['maxGuests'] ?? null) ? max((int) $d['maxGuests'], $min ?? 0) : null;

        return [
            'id' => $d['id'] ?? null, 'name' => $d['name'] ?? '', 'emoji' => $d['emoji'] ?? '🎉',
            'pricing' => ['mode' => $mode, 'price' => r2($num($p['price'] ?? 0)), 'discountPct' => min(90, $num($p['discountPct'] ?? 0))],
            'components' => $comps, 'minGuests' => $min, 'maxGuests' => $max, 'stages' => $stages,
        ];
    }

    /** Services of one package with a daily team limit: [lower-case name => [name, qty in one package, teamsPerDay]]. */
    public static function limitedServices(array $def): array
    {
        $out = [];
        foreach ($def['components'] as $c) {
            if ($c['kind'] === 'service' && $c['name'] !== '') {
                $k = mb_strtolower($c['name']);
                $out[$k] = [$c['name'], ($out[$k][1] ?? 0) + $c['qty'], $c['teamsPerDay'] ?? null];
            }
        }

        return $out;
    }

    /** Frozen copy stored on a booked line, so later edits never change that booking. */
    public static function snapshot(Package $k): array
    {
        return self::def($k) + ['takenAt' => now()->toIso8601String()];
    }

    public static function usesGuests(array $def): bool
    {
        foreach ($def['components'] as $c) {
            if (! empty($c['perGuest'])) {
                return true;
            }
            foreach ($c['options'] ?? [] as $o) {
                if (! empty($o['perGuest'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /* ---------------- selection ---------------- */

    public static function defaultSel(array $def): array
    {
        $sel = ['choices' => [], 'addons' => [], 'guests' => self::usesGuests($def) ? ($def['minGuests'] ?? 50) : null];
        if ($def['stages'] ?? []) {
            $sel['stages'] = array_fill(0, count($def['stages']), ['time' => '', 'venue' => '']);
        }
        foreach ($def['components'] as $i => $c) {
            if ($c['kind'] === 'choice' && $c['options']) {
                $sel['choices'][$i] = 0;
            }
            if ($c['kind'] === 'addon' && $c['default']) {
                $sel['addons'][] = $i;
            }
        }

        return $sel;
    }

    /** Keep only valid indexes; missing choices fall back to the first option. */
    public static function cleanSel(array $def, ?array $sel): array
    {
        $base = self::defaultSel($def);
        if ($sel === null || $sel === []) {
            return $base;
        }
        $out = ['choices' => [], 'addons' => [], 'guests' => null];
        if ($def['stages'] ?? []) {
            $out['stages'] = [];
            foreach (array_keys($def['stages']) as $i) {
                $s = (array) ($sel['stages'][$i] ?? []);
                $t = (string) ($s['time'] ?? '');
                $out['stages'][$i] = ['time' => Hours::toMin($t) !== null && strlen($t) === 5 ? $t : '', 'venue' => mb_substr(trim((string) ($s['venue'] ?? '')), 0, 160)];
            }
        }
        foreach ($def['components'] as $i => $c) {
            if ($c['kind'] === 'choice' && $c['options']) {
                $pick = (int) ($sel['choices'][$i] ?? $sel['choices'][(string) $i] ?? 0);
                $out['choices'][$i] = isset($c['options'][$pick]) ? $pick : 0;
            }
            if ($c['kind'] === 'addon' && in_array($i, array_map('intval', (array) ($sel['addons'] ?? [])), true)) {
                $out['addons'][] = $i;
            }
        }
        if (self::usesGuests($def)) {
            $g = (int) ($sel['guests'] ?? 0) ?: $base['guests'];
            $g = max($def['minGuests'] ?? 1, $g);
            $out['guests'] = $def['maxGuests'] ? min($def['maxGuests'], $g) : $g;
        }

        return $out;
    }

    private static function qtyFor(array $c, ?int $guests): int
    {
        return ! empty($c['perGuest']) && $guests ? max(1, (int) ceil($c['perGuest'] * $guests)) : max(1, (int) $c['qty']);
    }

    /**
     * Everything one package contains for a selection.
     * Rows: {group: included|choice|addon, kind: rent|sale|service, productId?, name, qty, unitPrice, cost, perDay, charge}
     *   cost   = the row at its own price (what renting/buying it separately costs, one day)
     *   charge = what the row adds to the package price (depends on the pricing mode), filled by price()
     */
    public static function resolve(array $def, array $sel): array
    {
        $g = $sel['guests'] ?? null;
        $rows = [];
        $productRow = function (string $group, int $pid, int $qty, ?float $price = null, array $extra = []) use (&$rows) {
            $p = inv()->product($pid);
            if (! $p) {
                return;
            }
            $unit = $price ?? Pricing::unitPrice($p, $qty);
            $rows[] = array_merge(['group' => $group, 'kind' => $p->isSale() ? 'sale' : 'rent', 'productId' => $p->id, 'name' => $p->name, 'qty' => $qty,
                'unitPrice' => r2($unit), 'cost' => r2($unit * $qty), 'perDay' => ! $p->isSale(), 'charge' => 0.0], $extra);
        };
        foreach ($def['components'] as $i => $c) {
            switch ($c['kind']) {
                case 'rent':
                case 'sale':
                    $productRow('included', $c['productId'], self::qtyFor($c, $g), null, ['index' => $i, 'perGuest' => $c['perGuest'] ?? null]);
                    break;
                case 'service':
                    $rows[] = ['group' => 'included', 'kind' => 'service', 'name' => $c['name'], 'qty' => $c['qty'], 'unit' => $c['unit'], 'unitPrice' => $c['price'], 'cost' => r2($c['price'] * $c['qty']), 'perDay' => false, 'charge' => 0.0, 'index' => $i];
                    break;
                case 'choice':
                    $o = $c['options'][$sel['choices'][$i] ?? 0] ?? null;
                    if ($o) {
                        $productRow('choice', $o['productId'], self::qtyFor($o, $g), null, ['index' => $i, 'label' => $c['label'], 'priceDelta' => $o['priceDelta'], 'perGuest' => $o['perGuest'] ?? null]);
                    }
                    break;
                case 'addon':
                    if (! in_array($i, $sel['addons'] ?? [], true)) {
                        break;
                    }
                    if ($c['productId']) {
                        $productRow('addon', $c['productId'], $c['qty'], $c['price'], ['index' => $i]);
                    } else {
                        $rows[] = ['group' => 'addon', 'kind' => 'service', 'name' => $c['name'], 'qty' => $c['qty'], 'unit' => 'service', 'unitPrice' => (float) $c['price'], 'cost' => r2((float) $c['price'] * $c['qty']), 'perDay' => false, 'charge' => 0.0, 'index' => $i];
                    }
                    break;
            }
        }

        return $rows;
    }

    /**
     * Price of ONE package for one day with a selection.
     * @return array{perDay: float, once: float, rent: float, sale: float, service: float, separate: float, package: float, savings: float, rows: array, sel: array}
     */
    public static function price(array $def, ?array $sel = null): array
    {
        $sel = self::cleanSel($def, $sel);
        $rows = self::resolve($def, $sel);
        $mode = $def['pricing']['mode'];
        $f = $mode === 'sum_discount' ? 1 - $def['pricing']['discountPct'] / 100 : 1.0;
        $parts = ['rent' => 0.0, 'sale' => 0.0, 'service' => 0.0];

        if ($mode === 'fixed') {
            $parts['rent'] = $def['pricing']['price']; // the fixed price is a per-day package fee
            $baseGuests = $def['minGuests'];
            foreach ($rows as &$r) {
                $charge = 0.0;
                if ($r['group'] === 'addon') {
                    $charge = $r['cost'];
                } elseif ($r['group'] === 'choice') {
                    $charge = r2($r['priceDelta'] * $r['qty']);
                } elseif (! empty($r['perGuest']) && $baseGuests && ($sel['guests'] ?? 0) > $baseGuests) {
                    // extra units for guests above the minimum the fixed price was set for
                    $extra = $r['qty'] - max(1, (int) ceil($r['perGuest'] * $baseGuests));
                    $charge = r2(max(0, $extra) * $r['unitPrice']);
                }
                $r['charge'] = $charge;
                $parts[$r['kind']] = r2($parts[$r['kind']] + $charge);
            }
            unset($r);
        } else {
            foreach ($rows as &$r) {
                $r['charge'] = r2($r['group'] === 'addon' ? $r['cost'] : $r['cost'] * $f);
                $parts[$r['kind']] = r2($parts[$r['kind']] + $r['charge']);
            }
            unset($r);
        }
        $separate = r2(array_sum(array_column($rows, 'cost')));
        $package = r2($parts['rent'] + $parts['sale'] + $parts['service']);

        return [
            'perDay' => $parts['rent'], 'once' => r2($parts['sale'] + $parts['service']),
            'rent' => $parts['rent'], 'sale' => $parts['sale'], 'service' => $parts['service'],
            'separate' => $separate, 'package' => $package, 'savings' => r2(max(0, $separate - $package)),
            'rows' => $rows, 'sel' => $sel,
        ];
    }

    /** Product units in ONE package: ['rent' => [pid => qty], 'sale' => [pid => qty]]. Services use no stock. */
    public static function units(array $def, ?array $sel = null): array
    {
        $out = ['rent' => [], 'sale' => []];
        foreach (self::resolve($def, self::cleanSel($def, $sel)) as $r) {
            if (isset($r['productId'])) {
                $out[$r['kind']][$r['productId']] = ($out[$r['kind']][$r['productId']] ?? 0) + $r['qty'];
            }
        }

        return $out;
    }

    /** Longest lead time among the sale items in a selection. */
    public static function leadDays(array $def, ?array $sel = null): int
    {
        $lead = 0;
        foreach (array_keys(self::units($def, $sel)['sale']) as $pid) {
            $lead = max($lead, (int) (inv()->product($pid)?->lead_days ?? 0));
        }

        return $lead;
    }

    /** "Included: …", "Chair style: …", "Add-ons: …", "80 guests" */
    public static function describe(array $def, ?array $sel = null): array
    {
        $sel = self::cleanSel($def, $sel);
        $rows = self::resolve($def, $sel);
        $fmt = fn ($r) => $r['qty'].'× '.$r['name'];
        $out = [];
        // Rows placed in a stage are listed under that stage instead (below).
        $staged = fn ($r) => isset($r['index']) && ($def['components'][$r['index']]['stage'] ?? null) !== null && ! empty($def['stages']);
        $all = $rows;
        $rows = array_filter($rows, fn ($r) => ! $staged($r));
        $inc = array_filter($rows, fn ($r) => $r['group'] === 'included');
        if ($inc) {
            $out[] = 'Included: '.implode(', ', array_map($fmt, $inc));
        }
        foreach (array_filter($rows, fn ($r) => $r['group'] === 'choice') as $r) {
            $out[] = $r['label'].': '.$fmt($r);
        }
        $add = array_filter($rows, fn ($r) => $r['group'] === 'addon');
        if ($add) {
            $out[] = 'Add-ons: '.implode(', ', array_map($fmt, $add));
        }
        if ($sel['guests']) {
            $out[] = $sel['guests'].' guests';
        }
        foreach ($def['stages'] ?? [] as $i => $st) {
            $info = array_filter([($t = $sel['stages'][$i]['time'] ?? '') !== '' ? Hours::fmt(Hours::toMin($t)) : '', $sel['stages'][$i]['venue'] ?? '']);
            $what = array_map($fmt, array_filter($all, fn ($r) => isset($r['index']) && ($def['components'][$r['index']]['stage'] ?? null) === $i));
            $out[] = $st['name'].($info ? ' ('.implode(', ', $info).')' : '').($what ? ': '.implode(', ', $what) : '');
        }

        return $out;
    }

    /** Included products at base quantity (first option of each choice) — the denormalised `items` column. */
    public static function summaryItems(array $def): array
    {
        $u = self::units($def, null);
        $out = [];
        foreach ($u['rent'] + [] as $pid => $q) {
            $out[] = ['product_id' => $pid, 'qty' => $q];
        }
        foreach ($u['sale'] as $pid => $q) {
            $out[] = ['product_id' => $pid, 'qty' => $q];
        }

        return $out;
    }

    /** One package, one day, default options. */
    public static function headlinePrice(array $def): float
    {
        return self::price($def)['package'];
    }

    /* ---------------- supplier input ---------------- */

    /**
     * Validate an editor submission. Products must be the supplier's own; choices need 2+ options.
     * @return array{ok: bool, error: ?string, def: array}
     */
    public static function validate(Supplier $s, array $in): array
    {
        $def = self::normalize($in);
        $fail = fn (string $e) => ['ok' => false, 'error' => $e, 'def' => $def];
        if (! $def['components']) {
            return $fail('Add at least one component to the package.');
        }
        foreach ($def['stages'] as $n => $st) {
            if ($st['name'] === '') {
                return $fail('Stage '.($n + 1).' needs a name (e.g. Ceremony), or remove it.');
            }
        }
        $own = Product::where('supplier_id', $s->id)->where('status', '!=', 'archived')->pluck('listing_type', 'id')->all();
        $hasStock = false;
        foreach ($def['components'] as $n => $c) {
            $label = 'Component '.($n + 1);
            $pids = match ($c['kind']) {
                'rent', 'sale' => [$c['productId']],
                'addon' => $c['productId'] ? [$c['productId']] : [],
                'choice' => array_column($c['options'], 'productId'),
                default => [],
            };
            foreach ($pids as $pid) {
                if (! $pid) {
                    return $fail($label.': choose an item.');
                }
                if (! isset($own[$pid])) {
                    return $fail($label.': packages can only contain your own listings.');
                }
            }
            if (in_array($c['kind'], ['rent', 'sale'], true) && $own[$c['productId']] !== $c['kind']) {
                $c['kind'] = $own[$c['productId']]; // trust the product's listing type
                $def['components'][$n] = $c;
            }
            if ($c['kind'] === 'service' && $c['teamsPerDay'] !== null && $c['teamsPerDay'] < $c['qty']) {
                return $fail($label.' ("'.$c['name'].'"): teams per day must be at least the quantity in one package.');
            }
            if ($c['kind'] === 'service' && ($c['name'] === '' || $c['price'] <= 0)) {
                return $fail($label.': a service needs a name and a price.');
            }
            if ($c['kind'] === 'addon' && ! $c['productId'] && ($c['name'] === '' || $c['price'] === null)) {
                return $fail($label.': an add-on service needs a name and a price.');
            }
            if ($c['kind'] === 'choice' && count($c['options']) < 2) {
                return $fail($label.' ("'.$c['label'].'"): a choice needs at least two options.');
            }
            $hasStock = $hasStock || in_array($c['kind'], ['rent', 'sale', 'choice'], true);
        }
        if (! $hasStock) {
            return $fail('Include at least one of your listings (a service alone cannot be a package).');
        }
        if ($def['pricing']['mode'] === 'fixed' && $def['pricing']['price'] <= 0) {
            return $fail('Set the fixed package price.');
        }
        if ($def['pricing']['mode'] === 'sum_discount' && $def['pricing']['discountPct'] <= 0) {
            return $fail('Set the discount percentage, or choose "Sum of components".');
        }
        if ($def['minGuests'] && $def['maxGuests'] && $def['maxGuests'] < $def['minGuests']) {
            return $fail('Maximum guests cannot be lower than minimum guests.');
        }

        return ['ok' => true, 'error' => null, 'def' => $def];
    }

    /** Stock warnings for the editor: rental stock below the package quantity, low sale stock. */
    public static function stockWarnings(array $def): array
    {
        $out = [];
        $sel = self::defaultSel($def);
        if (self::usesGuests($def)) {
            $sel['guests'] = $def['maxGuests'] ?: $def['minGuests'] ?: $sel['guests'];
        }
        foreach (self::units($def, $sel) as $kind => $list) {
            foreach ($list as $pid => $q) {
                $p = inv()->product($pid);
                if (! $p) {
                    continue;
                }
                if ($kind === 'rent' && $p->total_stock - $p->damaged - $p->missing < $q) {
                    $out[] = $p->name.': a package needs '.$q.' but you only have '.($p->total_stock - $p->damaged - $p->missing).' usable.';
                }
                if ($kind === 'sale' && ($p->stockOnHand() < $q || $p->isLowStock())) {
                    $out[] = $p->name.': only '.$p->stockOnHand().' '.$p->unitWord().' on hand (a package uses '.$q.').';
                }
            }
        }

        return $out;
    }
}
