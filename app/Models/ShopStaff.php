<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member of one company (supplier). Roles live inside the company, not in the system: the system only knows
 * customer / admin / superadmin, and "works at company X as Cashier" is this row. The owner is always the Owner
 * (org_admin). A member can hold several roles; each role opens its own module (App\Support\CompanyModules).
 * The owner can add or remove single permissions per member on top of the role defaults.
 */
class ShopStaff extends Model
{
    protected $table = 'shop_staff';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['roles' => 'array', 'perms_add' => 'array', 'perms_remove' => 'array'];
    }

    public const ORG_ROLES = ['org_admin' => 'Owner', 'desk' => 'Front Desk', 'cashier' => 'Cashier', 'inventory' => 'Inventory Keeper', 'crew' => 'Field Crew'];

    /** Roles an owner can give an employee (one or more). */
    public const EMPLOYEE_ROLES = ['desk', 'cashier', 'inventory', 'crew'];

    /** One line per role, shown when choosing roles. */
    public const ROLE_HINTS = [
        'desk' => 'Takes bookings: accepts or declines requests, chats with customers, handles problem cases and reviews.',
        'cashier' => 'Handles money: confirms payments, records cash, receipts, balances and overdue accounts.',
        'inventory' => 'Keeps the stock: products and packages, stock per location, maintenance, return inspections.',
        'crew' => 'Does the field work: deliveries, pickups and returns, and marks each step done.',
    ];

    public const ROLE_BADGE = ['org_admin' => 'b-purple', 'desk' => 'b-pink', 'cashier' => 'b-teal', 'inventory' => 'b-sky', 'crew' => 'b-gray'];

    /** Permissions an owner may NOT hand to an employee. */
    public const OWNER_ONLY = ['team.manage'];

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
        'desk' => ['orders.view', 'orders.manage'],
        'cashier' => ['payments.view', 'payments.verify', 'payments.record', 'receipts.issue', 'reports.view'],
        'inventory' => ['orders.view', 'products.manage', 'inventory.manage', 'locations.manage', 'returns.inspect'],
        'crew' => ['orders.view', 'fulfillment.update', 'returns.inspect'],
    ];

    /**
     * Supplier route name (after "supplier.") => permission it needs. First match wins; no match = any member.
     * Mirrors the permission keys on the reference NAV items and Services checks.
     */
    public const ROUTE_PERMS = [
        'dashboard' => null,
        // module home pages (App\Support\CompanyModules)
        'desk' => 'orders.manage', 'stock' => 'inventory.manage', 'crew' => 'fulfillment.update',
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
        'payouts' => 'payments.view', 'payouts.account' => 'shop.manage', 'payouts.schedule' => 'shop.manage',
        'reports' => 'reports.view', 'reports.*' => 'reports.view', 'records' => 'reports.view', 'activity' => 'team.manage',
        'shop' => 'shop.manage', 'shop.*' => 'shop.manage', 'business' => 'shop.manage',
        'team' => 'team.manage', 'team.*' => 'team.manage', 'staff' => 'team.manage', 'staff.*' => 'team.manage',
    ];

    /** Business (event company) workspace routes (after "business.") => permission; no match = any member. */
    public const BUSINESS_ROUTE_PERMS = [
        'dashboard' => null, 'calendar' => 'orders.view',
        'bookings.quote' => 'orders.manage', 'bookings.decline' => 'orders.manage', 'bookings.cancel' => 'orders.manage', 'bookings.complete' => 'orders.manage',
        'bookings' => 'orders.view', 'bookings.*' => 'orders.view',
        'payments.remind' => 'payments.view', 'payments.proof' => 'payments.view', 'payments.*' => 'payments.verify', 'payments' => 'payments.view',
        'packages' => 'products.manage', 'packages.*' => 'products.manage',
        'event-types' => 'shop.manage', 'event-types.*' => 'shop.manage',
        'settings' => 'shop.manage', 'settings.*' => 'shop.manage',
    ];

    /** Supplier pages a business still uses (the rest are for solo item-rental shops). */
    public const BUSINESS_KEEPS = ['supplier.team', 'supplier.team.*', 'supplier.shop', 'supplier.shop.*', 'supplier.business', 'supplier.reviews', 'supplier.reviews.*'];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** Members holding a role (main role or one of their extra roles). */
    public function scopeWithRole(Builder $q, string $role): Builder
    {
        return $q->where(fn ($w) => $w->where('role', $role)->orWhereJsonContains('roles', $role));
    }

    /** All roles of this member, main role first. */
    public function roleList(): array
    {
        return array_values(array_unique(array_filter(array_merge([$this->role], (array) $this->roles))));
    }

    public function label(): string { return self::rolesLabel($this->roleList()); }

    public static function rolesLabel(array $roles): string
    {
        return implode(' + ', array_map(fn ($r) => __(self::ORG_ROLES[$r] ?? ucfirst($r)), $roles));
    }

    /** Role defaults of this member (before the owner's tweaks). */
    public function rolePerms(): array
    {
        return self::permsOfRoles($this->roleList());
    }

    /** What this member may do: role defaults + permissions the owner added − permissions the owner removed. */
    public function perms(): array
    {
        $add = array_diff((array) $this->perms_add, self::OWNER_ONLY);

        return array_values(array_diff(array_unique(array_merge($this->rolePerms(), $add)), (array) $this->perms_remove));
    }

    /** Store the member's final permission list as tweaks against their role defaults. */
    public function setPerms(array $perms): void
    {
        $perms = array_values(array_intersect(array_keys(self::ORG_PERM_LIST), array_diff($perms, self::OWNER_ONLY)));
        $base = $this->rolePerms();
        $this->perms_add = array_values(array_diff($perms, $base)) ?: null;
        $this->perms_remove = array_values(array_diff($base, $perms)) ?: null;
    }

    /** Permissions of an organization role (org_admin = all). */
    public static function permsOf(?string $role): array
    {
        if ($role === 'org_admin') {
            return array_keys(self::ORG_PERM_LIST);
        }

        return self::ORG_PERMS[$role] ?? [];
    }

    public static function permsOfRoles(array $roles): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn ($r) => self::permsOf($r), $roles))));
    }

    /** Permission needed to open a supplier route (null = any member of the shop). */
    public static function permFor(string $routeName): ?string
    {
        if (str_starts_with($routeName, 'business.')) {
            foreach (self::BUSINESS_ROUTE_PERMS as $pattern => $perm) {
                if (fnmatch($pattern, substr($routeName, 9))) {
                    return $perm;
                }
            }

            return null;
        }
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
        return $role !== null && self::permsAllow(self::permsOf($role), $routeName);
    }

    public static function permsAllow(array $perms, string $routeName): bool
    {
        $perm = self::permFor($routeName);

        return $perm === null || in_array($perm, $perms, true);
    }
}
