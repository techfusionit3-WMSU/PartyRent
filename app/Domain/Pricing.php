<?php

namespace App\Domain;

use App\Models\Package;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;

/**
 * Tiered (quantity-based) pricing per date, package pricing, delivery fee and
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

    /** Price one line for each date. Item lines use the tier for that date's quantity. */
    public static function quoteLine(array $line): array
    {
        $days = $line['days'] ?? [];
        ksort($days);
        $out = [];
        foreach ($days as $date => $qty) {
            $qty = (int) $qty;
            if (! $qty) {
                continue;
            }
            if ($line['type'] === 'package') {
                $unit = (float) (inv()->package($line['ref_id'])?->price ?? 0);
            } else {
                $p = inv()->product($line['ref_id']);
                $unit = $p ? self::unitPrice($p, $qty) : 0.0;
            }
            $out[] = ['date' => $date, 'qty' => $qty, 'unit' => $unit, 'subtotal' => r2($unit * $qty)];
        }

        return [
            'lineId' => $line['id'] ?? null, 'type' => $line['type'], 'refId' => (int) $line['ref_id'],
            'days' => $out, 'total' => r2(array_sum(array_column($out, 'subtotal'))),
        ];
    }

    /** Supplier policy → amounts for a rental total. */
    public static function policyAmounts(Supplier $s, float $rentalTotal, float $itemsSubtotal, string $arrangement): array
    {
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
        $deposit = ($pol['depositType'] ?? 'fixed') === 'percent'
            ? r2($itemsSubtotal * ($pol['depositValue'] ?? 0) / 100)
            : (float) ($pol['depositValue'] ?? 0);

        return [
            'reservationFee' => $reservationFee,
            'reservationCredited' => $credited,
            'downRequired' => $downRequired,
            'downPct' => ($pol['downType'] ?? 'percent') === 'fixed' ? null : ($pol['downValue'] ?? 0),
            'deposit' => $deposit,
            'installments' => $arrangement === 'installment' ? max(2, (int) ($pol['installments'] ?? 2)) : 0,
        ];
    }

    /** Quote a set of lines for one supplier. */
    public static function quote(array $lines, Supplier $s, string $fulfillment, string $arrangement = 'full'): array
    {
        $lq = array_map([self::class, 'quoteLine'], $lines);
        $itemsSubtotal = r2(array_sum(array_column($lq, 'total')));
        $deliveryFee = $fulfillment === 'delivery' ? (float) $s->delivery_fee : 0.0;
        $total = r2($itemsSubtotal + $deliveryFee);
        $pol = self::policyAmounts($s, $total, $itemsSubtotal, $arrangement);
        $payable = r2($total + ($pol['reservationCredited'] ? 0 : $pol['reservationFee']));

        return array_merge([
            'lines' => $lq, 'itemsSubtotal' => $itemsSubtotal, 'deliveryFee' => $deliveryFee,
            'total' => $total, 'payable' => $payable, 'arrangement' => $arrangement,
            'quotedAt' => now()->toIso8601String(),
        ], $pol);
    }

    public static function quoteRequest(RentalRequest $r): array
    {
        return self::quote($r->lines, $r->supplier, $r->fulfillment, $r->arrangement);
    }

    /** Package price vs. renting its items separately. */
    public static function packageValue(Package $k): array
    {
        $separate = 0.0;
        foreach ($k->items as $it) {
            $p = inv()->product($it['product_id']);
            if ($p) {
                $separate += self::unitPrice($p, (int) $it['qty']) * (int) $it['qty'];
            }
        }

        return ['separate' => r2($separate), 'price' => (float) $k->price, 'savings' => r2(max(0, $separate - $k->price))];
    }
}
