<?php

namespace App\Support;

use App\Models\ShopStaff;
use App\Models\User;

/**
 * Company role modules (docs/ROLE_MODULES_PLAN.md). Roles live inside a company; each role has its own module:
 * a home page, a menu and the pages that belong to it. The owner can open every module; an employee opens the
 * modules of their roles and switches between them when they hold more than one.
 *
 * This class is the single source for "which role works where": routes are guarded by permissions
 * (ShopStaff::ROUTE_PERMS), menus and home pages come from here.
 *
 * Menu items use the layouts.dash format: [key, route, icon, label, badge key, permission].
 */
class CompanyModules
{
    /** Modules of a solo shop / rental company (direct bookings). */
    public const SHOP = [
        'manage' => [
            'label' => 'Owner', 'icon' => 'store', 'roles' => ['org_admin'], 'home' => 'supplier.dashboard',
            'about' => 'Everything: catalog, policies, team, reports and payouts.',
            'nav' => [
                ['Overview'], ['dashboard', 'supplier.dashboard', 'grid', 'Dashboard'], ['requests', 'supplier.requests', 'list', 'Rentals', 'requests', 'orders.view'], ['schedule', 'supplier.schedule', 'truck', 'Fulfillment & Returns', null, 'orders.view'], ['cases', 'supplier.shortfalls', 'alert', 'Problems & Cases', 'cases', 'orders.view'],
                ['Catalog'], ['products', 'supplier.products', 'box', 'Products', null, 'products.manage'], ['packages', 'supplier.packages', 'package', 'Packages', null, 'products.manage'], ['discounts', 'supplier.discounts', 'tag', 'Discounts', null, 'products.manage'], ['inventory', 'supplier.inventory', 'layers', 'Inventory', null, 'inventory.manage'], ['locations', 'supplier.locations', 'map', 'Inventory Locations', null, 'locations.manage'], ['calendar', 'supplier.calendar', 'calendar', 'Inventory Calendar', null, 'orders.view'],
                ['Money & reports'], ['payments', 'supplier.payments', 'card', 'Payments', 'payments', 'payments.view'], ['damages', 'supplier.damages', 'tool', 'Damage & Compensation', null, 'returns.inspect'], ['reports', 'supplier.reports', 'bars', 'Business Reports', null, 'reports.view'],
                ['Shop'], ['shop', 'supplier.shop', 'store', 'Shop Profile & Settings', null, 'shop.manage'], ['reviews', 'supplier.reviews', 'star', 'Reviews', null, 'orders.view'], ['team', 'supplier.team', 'users', 'Team & Roles', null, 'team.manage'], ['notifications', 'notifications', 'bell', 'Notifications'],
            ],
            'bottom' => [['dashboard', 'supplier.dashboard', 'grid', 'Dashboard'], ['products', 'supplier.products', 'box', 'Products', null, 'products.manage'], ['requests', 'supplier.requests', 'list', 'Rentals', null, 'orders.view'], ['payments', 'supplier.payments', 'card', 'Payments', null, 'payments.view']],
        ],
        'desk' => [
            'label' => 'Front Desk', 'icon' => 'list', 'roles' => ['desk'], 'home' => 'supplier.desk',
            'about' => 'New requests, customer chat, schedule changes, problem cases and reviews.',
            'nav' => [
                ['Front desk'], ['desk', 'supplier.desk', 'grid', 'Front Desk'], ['requests', 'supplier.requests', 'list', 'Rentals', 'requests', 'orders.view'], ['schedule', 'supplier.schedule', 'truck', 'Fulfillment & Returns', null, 'orders.view'], ['calendar', 'supplier.calendar', 'calendar', 'Availability Calendar', null, 'orders.view'],
                ['Customers'], ['cases', 'supplier.shortfalls', 'alert', 'Problems & Cases', 'cases', 'orders.view'], ['reviews', 'supplier.reviews', 'star', 'Reviews', null, 'orders.view'], ['notifications', 'notifications', 'bell', 'Notifications'],
            ],
            'bottom' => [['desk', 'supplier.desk', 'grid', 'Home'], ['requests', 'supplier.requests', 'list', 'Rentals', null, 'orders.view'], ['cases', 'supplier.shortfalls', 'alert', 'Cases', null, 'orders.view'], ['calendar', 'supplier.calendar', 'calendar', 'Calendar', null, 'orders.view']],
        ],
        'cashier' => [
            'label' => 'Cashier', 'icon' => 'card', 'roles' => ['cashier'], 'home' => 'cashier.dashboard',
            'about' => 'Payments to confirm, cash, receipts, balances and overdue accounts.',
            'nav' => [
                ['Payments'], ['dashboard', 'cashier.dashboard', 'grid', 'Cashier Dashboard'], ['verification', 'cashier.verification', 'check', 'Payments to Confirm', 'verification', 'payments.view'], ['record', 'cashier.record', 'cash', 'Record Cash', null, 'payments.record'], ['balances', 'cashier.balances', 'clock', 'Installments & Overdue', null, 'payments.view'],
                ['Records'], ['history', 'cashier.history', 'list', 'Payment History', null, 'payments.view'], ['payouts', 'cashier.payouts', 'cash', 'Earnings & Payouts', null, 'payments.view'], ['receipts', 'cashier.receipts', 'receipt', 'Receipts', null, 'receipts.issue'], ['reports', 'cashier.reports', 'bars', 'Financial Reports', null, 'reports.view'], ['notifications', 'notifications', 'bell', 'Notifications'],
            ],
            'bottom' => [['dashboard', 'cashier.dashboard', 'grid', 'Home'], ['verification', 'cashier.verification', 'check', 'Confirm'], ['record', 'cashier.record', 'cash', 'Record'], ['history', 'cashier.history', 'list', 'History']],
        ],
        'inventory' => [
            'label' => 'Inventory', 'icon' => 'layers', 'roles' => ['inventory'], 'home' => 'supplier.stock',
            'about' => 'What to prepare, stock per location, maintenance and return inspections.',
            'nav' => [
                ['Stock'], ['stock', 'supplier.stock', 'grid', 'Stock Room'], ['inventory', 'supplier.inventory', 'layers', 'Inventory', null, 'inventory.manage'], ['locations', 'supplier.locations', 'map', 'Inventory Locations', null, 'locations.manage'], ['calendar', 'supplier.calendar', 'calendar', 'Inventory Calendar', null, 'orders.view'],
                ['Catalog'], ['products', 'supplier.products', 'box', 'Products', null, 'products.manage'], ['packages', 'supplier.packages', 'package', 'Packages', null, 'products.manage'],
                ['Returns'], ['damages', 'supplier.damages', 'tool', 'Damage & Compensation', null, 'returns.inspect'], ['notifications', 'notifications', 'bell', 'Notifications'],
            ],
            'bottom' => [['stock', 'supplier.stock', 'grid', 'Home'], ['inventory', 'supplier.inventory', 'layers', 'Stock', null, 'inventory.manage'], ['products', 'supplier.products', 'box', 'Products', null, 'products.manage'], ['calendar', 'supplier.calendar', 'calendar', 'Calendar', null, 'orders.view']],
        ],
        'crew' => [
            'label' => 'Field Crew', 'icon' => 'truck', 'roles' => ['crew'], 'home' => 'supplier.crew',
            'about' => 'Deliveries, pickups and returns to do, with addresses and steps to mark done.',
            'nav' => [
                ['My work'], ['crew', 'supplier.crew', 'truck', 'My Jobs'], ['schedule', 'supplier.schedule', 'calendar', 'Fulfillment & Returns', null, 'orders.view'], ['notifications', 'notifications', 'bell', 'Notifications'],
            ],
            'bottom' => [['crew', 'supplier.crew', 'truck', 'Jobs'], ['schedule', 'supplier.schedule', 'calendar', 'Schedule', null, 'orders.view']],
        ],
    ];

    /** Pages that belong to a module even though they are not in its menu (e.g. a request opened from a list). */
    private const OWNS = [
        'desk' => ['supplier.desk', 'supplier.requests*', 'supplier.schedule', 'supplier.calendar', 'supplier.shortfalls*', 'supplier.reviews*'],
        'cashier' => ['cashier.*'],
        'inventory' => ['supplier.stock', 'supplier.inventory*', 'supplier.locations*', 'supplier.products*', 'supplier.packages*', 'supplier.damages*', 'supplier.calendar'],
        'crew' => ['supplier.crew', 'supplier.schedule'],
        'manage' => ['supplier.*'],
    ];

    /** Modules this user can open in their company, in display order (empty when not in a solo/rental company). */
    public static function forUser(User $u): array
    {
        $shop = $u->shop();
        if (! $shop || $shop->isOrganization()) {
            return []; // event companies: business workspace (split into role modules in step 2)
        }
        $roles = $u->orgRoles();
        if (in_array('org_admin', $roles, true)) {
            return array_keys(self::SHOP);
        }
        $keys = array_keys(array_filter(self::SHOP, fn ($m) => (bool) array_intersect($m['roles'], $roles)));
        // keep the member's main role first so it is the default module
        usort($keys, fn ($a, $b) => self::rank($roles, $a) <=> self::rank($roles, $b));

        return $keys;
    }

    private static function rank(array $roles, string $module): int
    {
        foreach ($roles as $i => $r) {
            if (in_array($r, self::SHOP[$module]['roles'], true)) {
                return $i;
            }
        }

        return 99;
    }

    /** The module the user is working in now (session), or their first one. */
    public static function current(User $u): ?string
    {
        $mods = self::forUser($u);
        $s = session('module');

        return in_array($s, $mods, true) ? $s : ($mods[0] ?? null);
    }

    public static function get(string $key): array
    {
        return self::SHOP[$key];
    }

    /** Does this page belong to the module? */
    public static function owns(string $module, string $route): bool
    {
        foreach (self::OWNS[$module] ?? [] as $p) {
            if (fnmatch($p, $route)) {
                return true;
            }
        }

        return false;
    }

    /** Best module (among the user's) for a page: the current one if it owns the page, else the first that does. */
    public static function moduleFor(User $u, string $route): ?string
    {
        $cur = self::current($u);
        if ($cur && self::owns($cur, $route)) {
            return $cur;
        }
        foreach (self::forUser($u) as $m) {
            if (self::owns($m, $route)) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Sidebar + bottom bar of a module for this user, in layouts.dash format, filtered by the user's permissions.
     * An employee who was given extra permissions by the owner sees those pages under "Also allowed".
     */
    public static function nav(User $u, string $module, array $badges): array
    {
        $can = fn ($it) => empty($it[5]) || $u->orgCan($it[5]);
        $m = self::SHOP[$module];
        $items = array_map(fn ($it) => count($it) === 1 ? $it : [$it[0], $it[1], $it[2], $it[3], ! empty($it[4]) ? ($badges[$it[4]] ?? 0) : 0, $it[5] ?? null], $m['nav']);
        $items = array_values(array_filter($items, fn ($it) => count($it) === 1 || $can($it)));
        if ($module !== 'manage' && ! $u->isShopOwner()) {
            $have = [];
            foreach (self::forUser($u) as $k) {
                foreach (self::SHOP[$k]['nav'] as $it) {
                    if (count($it) > 1) {
                        $have[$it[1]] = true;
                    }
                }
            }
            $extra = array_filter(self::SHOP['manage']['nav'], fn ($it) => count($it) > 1 && ! isset($have[$it[1]]) && ! empty($it[5]) && $can($it));
            if ($extra) {
                $items[] = ['Also allowed'];
                foreach ($extra as $it) {
                    $items[] = [$it[0], $it[1], $it[2], $it[3], ! empty($it[4]) ? ($badges[$it[4]] ?? 0) : 0, $it[5]];
                }
            }
        }
        $bottom = array_values(array_filter($m['bottom'], $can));

        return ['tag' => $m['label'], 'items' => $items, 'bottom' => array_map(fn ($it) => [$it[0], $it[1], $it[2], $it[3]], $bottom)];
    }

    /** RBAC matrix rows for documentation and the Team page: module => roles that open it. */
    public static function matrix(): array
    {
        $out = [];
        foreach (self::SHOP as $k => $m) {
            $out[$k] = ['label' => $m['label'], 'about' => $m['about'], 'roles' => array_merge(['org_admin'], array_diff($m['roles'], ['org_admin']))];
        }

        return $out;
    }

    public static function roleLabel(string $role): string
    {
        return ShopStaff::ORG_ROLES[$role] ?? ucfirst($role);
    }
}
