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
        // public promotions only: a custom (customer) discount is applied by the shop on one rental (CustomDiscounts)
        return inv()->runningDiscounts($supplierId, $at, fn () => Discount::where('supplier_id', $supplierId)->whereNull('customer_id')->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $at))
            ->orderBy('id')->get());
    }

    /**
     * Best discount for one product line. $rows = [['qty' => n, 'unit' => price], …] (one per date, or one row for
     * a sale item). Returns null or ['id', 'name', 'label', 'amount'].
     */
    public static function best(Product $p, array $rows, ?string $at = null): ?array
    {
        $best = null;
        foreach (self::running($p->supplier_id, $at ?? today_iso()) as $d) {
            if ($d->isOrderLevel() || ! $d->covers($p)) {
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

    /**
     * Best whole-order discount for one shop order. $lines = quoted lines (Pricing::quoteLine) of rental / sale items.
     * The base is the covered items' total before item discounts. Returns null or ['id','name','label','amount','base'].
     */
    public static function bestOrder(int $supplierId, array $lines, ?string $at = null): ?array
    {
        $best = null;
        foreach (self::running($supplierId, $at ?? today_iso()) as $d) {
            if (! $d->isOrderLevel()) {
                continue;
            }
            $base = 0.0;
            $pieces = 0;
            foreach ($lines as $l) {
                if (! in_array($l['kind'] ?? '', ['rent', 'sale'], true) || ! ($p = inv()->product($l['refId'] ?? 0)) || ! $d->covers($p)) {
                    continue;
                }
                $base += (float) ($l['gross'] ?? $l['total'] ?? 0);
                $pieces += $l['kind'] === 'sale' ? (int) array_sum(array_column($l['once'] ?? [], 'qty')) : (int) max(array_column($l['days'] ?? [], 'qty') ?: [0]);
            }
            $base = r2($base);
            if ($base <= 0 || ($d->min_amount && $base < $d->min_amount) || ($d->min_qty && $pieces < $d->min_qty)) {
                continue;
            }
            $off = r2($d->kind === 'order_percent' ? $base * min(100, $d->value) / 100 : min($d->value, $base));
            if ($off > 0 && (! $best || $off > $best['amount'])) {
                $best = ['id' => $d->id, 'name' => $d->name, 'label' => $d->label(), 'amount' => $off, 'base' => $base];
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
