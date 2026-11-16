<?php

namespace App\Domain;

use App\Models\RentalRequest;
use App\Models\Supplier;

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
            $rates = self::rates($sup, $p);
            $value = (float) ($p?->replacement_value ?? 0);
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
                'rateSource' => $rates['source'], 'amounts' => $amounts, 'total' => r2(array_sum($amounts)), 'description' => $desc,
            ];
        }

        return ['ok' => ! $errors, 'errors' => $errors, 'items' => $items, 'total' => r2(array_sum(array_column($items, 'total')))];
    }
}
