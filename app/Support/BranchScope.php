<?php

namespace App\Support;

use App\Models\InventoryLocation;
use App\Models\User;

/**
 * Which branches a shop user works with, and which one the dashboard is showing.
 *
 * Levels (App\Models\ShopStaff):
 *   - Organization level: the owner, an Organization Admin, and any member with no branch set (branch_ids null).
 *     They see every branch, may switch the dashboard to one branch, and may see organization-wide reports.
 *   - Branch level: a Branch Admin or a member assigned to one or more branches (branch_ids). They see, open and act
 *     only on their branches; with one branch the switcher is locked to it.
 *
 * allowed()  — branch ids the user may work with (null = every branch of the shop).
 * current()  — the branch the switcher shows (null = all the user's branches).
 * viewIds()  — what list pages filter by: [current] or allowed().
 * The middleware App\Http\Middleware\BranchGuard refuses (404) a rental, payment, branch or transfer outside allowed().
 */
class BranchScope
{
    /**
     * Pages for the whole organization: branch-level members never open them (catalog and prices, discounts, shop
     * settings, payouts, the store history, adding / closing / removing branches, shop-wide stock totals).
     */
    public const ORG_ONLY = [
        'supplier.payouts*', 'cashier.payouts', 'supplier.shop*', 'supplier.business', 'supplier.activity',
        'supplier.products*', 'supplier.packages*', 'supplier.discounts*', 'supplier.requests.custom-discount*',
        'supplier.locations.create', 'supplier.locations.store', 'supplier.locations.delete', 'supplier.locations.active', 'supplier.locations.settings',
        'supplier.inventory.restock', 'supplier.inventory.hold', 'supplier.inventory.release', 'supplier.inventory.condition',
    ];

    public static function orgOnly(string $route): bool
    {
        foreach (self::ORG_ONLY as $p) {
            if (fnmatch($p, $route)) {
                return true;
            }
        }

        return false;
    }

    public static function allowed(?User $u = null): ?array
    {
        $u ??= auth()->user();

        return $u?->branchIds();
    }

    public static function isOrgLevel(?User $u = null): bool
    {
        $u ??= auth()->user();

        return $u !== null && $u->shop() !== null && $u->branchIds() === null;
    }

    /** Branches the switcher offers this user (only the ones they may see). */
    public static function choices(?User $u = null)
    {
        $u ??= auth()->user();
        $s = $u?->shop();
        if (! $s) {
            return collect();
        }
        $ids = self::allowed($u);

        return $s->branches()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get();
    }

    public static function current(?User $u = null): ?InventoryLocation
    {
        $u ??= auth()->user();
        $s = $u?->shop();
        if (! $s) {
            return null;
        }
        $ids = self::allowed($u);
        if ($ids !== null && count($ids) === 1) {
            return InventoryLocation::where('supplier_id', $s->id)->find($ids[0]); // locked to their only branch
        }
        $want = (int) session('branch_view.'.$s->id);
        if (! $want || ($ids !== null && ! in_array($want, $ids, true))) {
            return null;
        }

        return InventoryLocation::where('supplier_id', $s->id)->find($want);
    }

    public static function viewIds(?User $u = null): ?array
    {
        $u ??= auth()->user();
        $c = self::current($u);

        return $c ? [$c->id] : self::allowed($u);
    }

    /** Switch the dashboard to a branch (null = all the user's branches). False when not allowed. */
    public static function set(User $u, ?int $id): bool
    {
        $s = $u->shop();
        if (! $s) {
            return false;
        }
        $ids = self::allowed($u);
        if ($id === null) {
            session()->forget('branch_view.'.$s->id);

            return $ids === null || count($ids) > 1;
        }
        if (! InventoryLocation::where('supplier_id', $s->id)->whereKey($id)->exists() || ($ids !== null && ! in_array($id, $ids, true))) {
            return false;
        }
        session(['branch_view.'.$s->id => $id]);

        return true;
    }

    /** May the user work with this branch? */
    public static function covers(?int $branchId, ?User $u = null): bool
    {
        $ids = self::allowed($u);

        return $ids === null || ($branchId !== null && in_array($branchId, $ids, true));
    }
}
