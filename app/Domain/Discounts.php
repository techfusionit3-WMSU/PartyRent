<?php

namespace App\Domain;

use App\Models\Discount;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Shop discounts (change list C11), applied by Pricing::quoteLine on top of the item's bulk discount price.
 *
 * - One discount per product line: the one that takes the most off. Packages have their own pricing and are not
 *   discounted here.
 * - percent: % off the unit price; amount: ₱ off per piece (per day for rentals), never more than the price.
 * - min_qty: only dates where the line has at least that many pieces (sale items: the total quantity).
 * - The period (starts_on / ends_on) is checked against the day the request is placed ($at), so a saved request
 *   keeps its discount when it is re-quoted later.
 * The supplier funds the discount: it lowers the items total, so commission and payout follow the lower amount.
 */
class Discounts
{
    /** Discounts of a shop that are running on $at (Y-m-d). */
    public static function running(int $supplierId, string $at): Collection
    {
        return Discount::where('supplier_id', $supplierId)->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $at))
            ->orderBy('id')->get();
    }

    /**
     * Best discount for one product line. $rows = [['qty' => n, 'unit' => price], …] (one per date, or one row for
     * a sale item). Returns null or ['id', 'name', 'label', 'amount'].
     */
    public static function best(Product $p, array $rows, ?string $at = null): ?array
    {
        $best = null;
        foreach (self::running($p->supplier_id, $at ?? today_iso()) as $d) {
            if (! $d->covers($p)) {
                continue;
            }
            $off = 0.0;
            foreach ($rows as $r) {
                if ($d->min_qty && $r['qty'] < $d->min_qty) {
                    continue;
                }
                $each = $d->kind === 'percent' ? $r['unit'] * min(100, $d->value) / 100 : min($d->value, $r['unit']);
                $off += r2($each) * $r['qty'];
            }
            $off = r2($off);
            if ($off > 0 && (! $best || $off > $best['amount'])) {
                $best = ['id' => $d->id, 'name' => $d->name, 'label' => $d->label(), 'amount' => $off];
            }
        }

        return $best;
    }

    /** Products a discount currently lowers, for the Discounts page. */
    public static function productsOf(Discount $d, Collection $products): Collection
    {
        return $d->applies_to === 'all' ? $products : $products->filter(fn ($p) => $d->covers($p))->values();
    }
}
