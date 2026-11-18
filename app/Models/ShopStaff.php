<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee account tied to one supplier (port of Auth.ORG_ROLES / ORG_PERMS in reference js/core/auth.js).
 * The supplier's owner is always the Organization Admin; employees are Cashier, Inventory Staff or Staff and
 * only ever see that supplier's data.
 */
class ShopStaff extends Model
{
    protected $table = 'shop_staff';

    protected $guarded = ['id'];

    public const ORG_ROLES = ['org_admin' => 'Organization Admin', 'cashier' => 'Cashier', 'inventory' => 'Inventory Staff', 'staff' => 'Staff'];

    /** Roles an owner can give an employee. */
    public const EMPLOYEE_ROLES = ['cashier', 'inventory', 'staff'];

    public const ROLE_BADGE = ['org_admin' => 'b-purple', 'cashier' => 'b-teal', 'inventory' => 'b-sky', 'staff' => 'b-gray'];

    public const ORG_PERM_LIST = [
        'orders.view' => 'View rental requests & schedules',
        'orders.manage' => 'Accept / decline rental requests',
        'fulfillment.update' => 'Update delivery, pickup & return status',
        'products.manage' => 'Add & update products and packages',
        'inventory.manage' => 'Manage stock, maintenance & damaged units',
        'locations.manage' => 'Manage inventory locations & transfers',
        'returns.inspect' => 'Record return inspections & damage',
        'payments.view' => 'View payments & balances',
        'payments.verify' => 'Verify / reject payments',
        'payments.record' => 'Record payments & refunds',
        'receipts.issue' => 'Issue receipts',
        'reports.view' => 'View business & financial reports',
        'shop.manage' => 'Edit shop profile, payment plans & policies',
        'team.manage' => 'Manage employees & roles',
    ];

    public const ORG_PERMS = [
        'cashier' => ['payments.view', 'payments.verify', 'payments.record', 'receipts.issue', 'reports.view'],
        'inventory' => ['orders.view', 'products.manage', 'inventory.manage', 'locations.manage', 'returns.inspect'],
        'staff' => ['orders.view', 'fulfillment.update', 'returns.inspect'],
    ];

    /**
     * Supplier route name (after "supplier.") => permission it needs. First match wins; no match = any member.
     * Mirrors the permission keys on the reference NAV items and Services checks.
     */
    public const ROUTE_PERMS = [
        'dashboard' => null,
        'requests.accept' => 'orders.manage', 'requests.decline' => 'orders.manage', 'requests.notes' => 'orders.manage',
        'requests.schedule' => 'orders.manage', 'requests.source' => 'orders.manage',
        'requests.advance' => 'fulfillment.update', 'requests.complete' => 'fulfillment.update',
        'requests.inspection' => 'returns.inspect', 'requests.settle' => 'returns.inspect',
        'requests.cash' => 'payments.record', 'requests.remind' => 'payments.view',
        'requests' => 'orders.view', 'requests.*' => 'orders.view', 'schedule' => 'orders.view', 'calendar' => 'orders.view',
        'reviews' => 'orders.view', 'reviews.*' => 'orders.manage',
        'products' => 'products.manage', 'products.*' => 'products.manage',
        'packages' => 'products.manage', 'packages.*' => 'products.manage',
        'discounts' => 'products.manage', 'discounts.*' => 'products.manage',
        'shortfalls' => 'orders.view', 'shortfalls.*' => 'orders.manage',
        'inventory' => 'inventory.manage', 'inventory.*' => 'inventory.manage',
        'locations' => 'locations.manage', 'locations.*' => 'locations.manage',
        'damages' => 'returns.inspect', 'damages.*' => 'returns.inspect',
        'payments' => 'payments.view', 'payments.*' => 'payments.verify',
        'payouts' => 'payments.view', 'payouts.account' => 'shop.manage',
        'reports' => 'reports.view', 'reports.*' => 'reports.view',
        'shop' => 'shop.manage', 'shop.*' => 'shop.manage', 'business' => 'shop.manage',
        'team' => 'team.manage', 'team.*' => 'team.manage', 'staff' => 'team.manage', 'staff.*' => 'team.manage',
    ];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function label(): string { return self::ORG_ROLES[$this->role] ?? ucfirst($this->role); }

    /** Permissions of an organization role (org_admin = all). */
    public static function permsOf(?string $role): array
    {
        if ($role === 'org_admin') {
            return array_keys(self::ORG_PERM_LIST);
        }

        return self::ORG_PERMS[$role] ?? [];
    }

    /** Permission needed to open a supplier route (null = any member of the shop). */
    public static function permFor(string $routeName): ?string
    {
        $name = str_starts_with($routeName, 'supplier.') ? substr($routeName, 9) : $routeName;
        foreach (self::ROUTE_PERMS as $pattern => $perm) {
            if (fnmatch($pattern, $name)) {
                return $perm;
            }
        }

        return null;
    }

    /** Can this organization role open the supplier route with this name? */
    public static function allows(?string $role, string $routeName): bool
    {
        if ($role === null) {
            return false;
        }
        $perm = self::permFor($routeName);

        return $perm === null || in_array($perm, self::permsOf($role), true);
    }
}
