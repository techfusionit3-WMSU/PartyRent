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
        return ['roles' => 'array', 'perms_add' => 'array', 'perms_remove' => 'array', 'branch_ids' => 'array'];
    }

    public const ORG_ROLES = ['org_admin' => 'Owner', 'org_manager' => 'Organization Admin', 'branch_admin' => 'Branch Admin', 'desk' => 'Front Desk', 'cashier' => 'Cashier', 'inventory' => 'Inventory Keeper', 'crew' => 'Field Crew'];

    /** Roles an owner can give an employee (one or more). */
    public const EMPLOYEE_ROLES = ['org_manager', 'branch_admin', 'desk', 'cashier', 'inventory', 'crew'];

    /**
     * Levels (App\Support\BranchScope):
     *   organization — the owner and Organization Admins: every branch, organization-wide reports, branch switcher.
     *   branch       — a Branch Admin runs their branch(es); other members may also be limited to branches.
     * An Organization Admin always covers every branch; a Branch Admin always has at least one branch.
     */
    public const ORG_LEVEL_ROLES = ['org_manager'];

    public const BRANCH_ROLES = ['branch_admin'];

    /** Roles a Branch Admin may give the members of their branch. */
    public const BRANCH_TEAM_ROLES = ['desk', 'cashier', 'inventory', 'crew'];

    /** One line per role, shown when choosing roles. */
    public const ROLE_HINTS = [
        'org_manager' => 'Runs the whole business with the owner: every branch, all orders, stock, money and reports, and the branch teams. Only the owner changes who is an admin.',
        'branch_admin' => 'Runs one branch: its rentals, deliveries, returns, stock, payments, reports and its own team. Sees nothing of other branches.',
        'desk' => 'Takes bookings: accepts or declines requests, chats with customers, handles problem cases and reviews.',
        'cashier' => 'Handles money: confirms payments, records cash, receipts, balances and overdue accounts.',
        'inventory' => 'Keeps the stock: products and packages, stock per location, maintenance, return inspections.',
        'crew' => 'Does the field work: deliveries, pickups and returns, and marks each step done.',
    ];

    /** The same roles in an event company, in event words (no item stock, so no Inventory Keeper). */
    public const EVENT_ROLES = ['org_admin' => 'Owner', 'desk' => 'Event Coordinator', 'cashier' => 'Finance', 'crew' => 'Event Crew'];

    public const EVENT_ROLE_HINTS = [
        'desk' => 'Handles clients: answers inquiries, sends quotations, follows the events assigned to them.',
        'cashier' => 'Handles money: confirms reservation fees, deposits and balances, sends payment reminders.',
        'crew' => 'Works the events: sees the parts their team works (when, where, who to call) and marks each done.',
    ];

    /** Role names for this company (event companies use event words). */
    public static function labels(?Supplier $s): array
    {
        return $s && $s->isOrganization() ? self::EVENT_ROLES : self::ORG_ROLES;
    }

    /** Roles an owner can give in this company. */
    public static function employeeRoles(?Supplier $s): array
    {
        return $s && $s->isOrganization() ? ['desk', 'cashier', 'crew'] : self::EMPLOYEE_ROLES;
    }

    public static function hints(?Supplier $s): array
    {
        return $s && $s->isOrganization() ? self::EVENT_ROLE_HINTS : self::ROLE_HINTS;
    }

    public const ROLE_BADGE = ['org_admin' => 'b-purple', 'org_manager' => 'b-purple', 'branch_admin' => 'b-gold', 'desk' => 'b-pink', 'cashier' => 'b-teal', 'inventory' => 'b-sky', 'crew' => 'b-gray'];

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
        'team.branch' => 'Manage the team of their branch(es)',
    ];

    public const ORG_PERMS = [
        'org_manager' => ['orders.view', 'orders.manage', 'fulfillment.update', 'products.manage', 'inventory.manage', 'locations.manage', 'returns.inspect',
            'payments.view', 'payments.verify', 'payments.record', 'receipts.issue', 'reports.view', 'shop.manage', 'team.branch'],
        'branch_admin' => ['orders.view', 'orders.manage', 'fulfillment.update', 'inventory.manage', 'locations.manage', 'returns.inspect',
            'payments.view', 'payments.verify', 'payments.record', 'receipts.issue', 'reports.view', 'team.branch'],
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
        'requests.schedule' => 'orders.manage', 'requests.source' => 'orders.manage', 'requests.replan' => 'orders.manage',
        'requests.custom-discount*' => 'shop.manage', // custom discounts: owner-level money decisions
        'requests.advance' => 'fulfillment.update', 'requests.complete' => 'fulfillment.update',
        'requests.inspection' => 'returns.inspect', 'requests.settle' => 'returns.inspect',
        'requests.cash' => 'payments.record', 'requests.remind' => 'payments.view',
        'requests' => 'orders.view', 'requests.*' => 'orders.view', 'schedule' => 'orders.view', 'calendar' => 'orders.view',
        'reviews' => 'orders.view', 'reviews.*' => 'orders.manage',
        'products' => 'products.manage', 'products.*' => 'products.manage',
        'packages' => 'products.manage', 'packages.*' => 'products.manage',
        'discounts.custom.*' => 'shop.manage', 'discounts' => 'products.manage', 'discounts.*' => 'products.manage',
        'shortfalls' => 'orders.view', 'shortfalls.*' => 'orders.manage',
        'inventory' => 'inventory.manage', 'inventory.*' => 'inventory.manage',
        'locations' => 'locations.manage', 'locations.*' => 'locations.manage',
        'damages' => 'returns.inspect', 'damages.*' => 'returns.inspect',
        'payments' => 'payments.view', 'payments.*' => 'payments.verify',
        'payouts' => 'payments.view', 'payouts.account' => 'shop.manage', 'payouts.schedule' => 'shop.manage',
        'reports' => 'reports.view', 'reports.*' => 'reports.view', 'records' => 'reports.view', 'activity' => 'team.manage',
        'shop' => 'shop.manage', 'shop.*' => 'shop.manage', 'business' => 'shop.manage',
        'transfers.*' => null, // each step is checked against the user's branch (App\Domain\Transfers::can)
        'branch-view' => null,
        'team' => 'team.manage|team.branch', 'team.*' => 'team.manage|team.branch', 'staff' => 'team.manage', 'staff.*' => 'team.manage',
    ];

    /** Business (event company) workspace routes (after "business.") => permission; no match = any member. */
    public const BUSINESS_ROUTE_PERMS = [
        'dashboard' => 'shop.manage', 'calendar' => 'orders.view', 'coordinator' => 'orders.manage', 'crew' => 'fulfillment.update', 'bookings.coordinator' => 'team.manage',
        'bookings.quote' => 'orders.manage', 'bookings.decline' => 'orders.manage', 'bookings.cancel' => 'orders.manage', 'bookings.complete' => 'orders.manage', 'bookings.part-done' => 'fulfillment.update',
        'bookings' => 'orders.view', 'bookings.*' => 'orders.view',
        'payments.remind' => 'payments.view', 'payments.proof' => 'payments.view', 'payments.*' => 'payments.verify', 'payments' => 'payments.view',
        'packages' => 'products.manage', 'packages.*' => 'products.manage',
        'event-types' => 'shop.manage', 'event-types.*' => 'shop.manage',
        'teams' => 'orders.view', 'teams.*' => 'team.manage', 'bookings.part-team' => 'orders.manage',
        'settings' => 'shop.manage', 'settings.*' => 'shop.manage',
    ];

    /** Supplier pages a business still uses (the rest are for solo item-rental shops). */
    public const BUSINESS_KEEPS = ['supplier.team', 'supplier.team.*', 'supplier.shop', 'supplier.shop.*', 'supplier.business', 'supplier.reviews', 'supplier.reviews.*', 'supplier.activity'];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** Members holding a role (main role or one of their extra roles). */
    public function scopeWithRole(Builder $q, string $role): Builder
    {
        return $q->where(fn ($w) => $w->where('role', $role)->orWhereJsonContains('roles', $role));
    }

    /** Organization level (every branch): an Organization Admin, or a member with no branch set. */
    public function isOrgLevel(): bool
    {
        return (bool) array_intersect($this->roleList(), self::ORG_LEVEL_ROLES);
    }

    /** All roles of this member, main role first. */
    public function roleList(): array
    {
        return array_values(array_unique(array_filter(array_merge([$this->role], (array) $this->roles))));
    }

    public function label(): string { return self::rolesLabel($this->roleList()); }

    public static function rolesLabel(array $roles, ?Supplier $s = null): string
    {
        $l = self::labels($s);

        return implode(' + ', array_map(fn ($r) => __($l[$r] ?? self::ORG_ROLES[$r] ?? ucfirst($r)), $roles));
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

        return $perm === null || (bool) array_intersect(explode('|', $perm), $perms); // "a|b" = either permission
    }
}
