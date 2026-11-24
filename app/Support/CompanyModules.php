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
                ['Catalog'], ['products', 'supplier.products', 'box', 'Products', null, 'products.manage'], ['packages', 'supplier.packages', 'package', 'Packages', null, 'products.manage'], ['discounts', 'supplier.discounts', 'tag', 'Discounts', null, 'products.manage'], ['inventory', 'supplier.inventory', 'layers', 'Inventory', null, 'inventory.manage'], ['locations', 'supplier.locations', 'store', 'Branches', null, 'locations.manage'], ['calendar', 'supplier.calendar', 'calendar', 'Inventory Calendar', null, 'orders.view'],
                ['Money & reports'], ['payments', 'supplier.payments', 'card', 'Payments', 'payments', 'payments.view'], ['damages', 'supplier.damages', 'tool', 'Damage & Compensation', null, 'returns.inspect'], ['reports', 'supplier.reports', 'bars', 'Business Reports', null, 'reports.view'], ['records', 'supplier.records', 'file', 'Transaction Records', null, 'reports.view'], ['printable', 'supplier.reports.center', 'print', 'Printable Reports', null, 'reports.view'],
                ['Shop'], ['shop', 'supplier.shop', 'store', 'Shop Profile & Settings', null, 'shop.manage'], ['reviews', 'supplier.reviews', 'star', 'Reviews', null, 'orders.view'], ['team', 'supplier.team', 'users', 'Team & Roles', null, 'team.manage'], ['activity', 'supplier.activity', 'clock', 'Store History', null, 'team.manage'], ['notifications', 'notifications', 'bell', 'Notifications'],
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
                ['Records'], ['history', 'cashier.history', 'list', 'Payment History', null, 'payments.view'], ['payouts', 'cashier.payouts', 'cash', 'Earnings & Payouts', null, 'payments.view'], ['receipts', 'cashier.receipts', 'receipt', 'Receipts', null, 'receipts.issue'], ['reports', 'cashier.reports', 'bars', 'Financial Reports', null, 'reports.view'], ['printable', 'cashier.reports.center', 'print', 'Printable Reports', null, 'reports.view'], ['notifications', 'notifications', 'bell', 'Notifications'],
            ],
            'bottom' => [['dashboard', 'cashier.dashboard', 'grid', 'Home'], ['verification', 'cashier.verification', 'check', 'Confirm'], ['record', 'cashier.record', 'cash', 'Record'], ['history', 'cashier.history', 'list', 'History']],
        ],
        'inventory' => [
            'label' => 'Inventory', 'icon' => 'layers', 'roles' => ['inventory'], 'home' => 'supplier.stock',
            'about' => 'What to prepare, stock per location, maintenance and return inspections.',
            'nav' => [
                ['Stock'], ['stock', 'supplier.stock', 'grid', 'Stock Room'], ['inventory', 'supplier.inventory', 'layers', 'Inventory', null, 'inventory.manage'], ['locations', 'supplier.locations', 'store', 'Branches', null, 'locations.manage'], ['calendar', 'supplier.calendar', 'calendar', 'Inventory Calendar', null, 'orders.view'],
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

    /**
     * Modules of an event company (books whole events by quotation). Same company roles, event wording:
     * Front Desk works as Event Coordinator, Cashier as Finance, Field Crew as Event Crew (no item stock).
     * The Owner module is the full business workspace (App\Support\BusinessNav).
     */
    public const EVENT = [
        'event_manage' => [
            'label' => 'Owner', 'icon' => 'store', 'roles' => ['org_admin'], 'home' => 'business.dashboard',
            'about' => 'Everything: event types, packages, bookings, payments, teams, settings and team members.',
            'nav' => [], 'bottom' => [],
        ],
        'event_coord' => [
            'label' => 'Event Coordinator', 'icon' => 'list', 'roles' => ['desk'], 'home' => 'business.coordinator',
            'about' => 'Inquiries, quotations, clients and the events assigned to you.',
            'nav' => [
                ['Events'], ['coordinator', 'business.coordinator', 'grid', 'My Desk'], ['bookings', 'business.bookings', 'list', 'Event Bookings', 'inquiries', 'orders.view'],
                ['calendar', 'business.calendar', 'calendar', 'Event Calendar', null, 'orders.view'], ['teams', 'business.teams', 'users', 'Teams', null, 'orders.view'],
                ['Clients'], ['reviews', 'supplier.reviews', 'star', 'Reviews', null, 'orders.view'], ['notifications', 'notifications', 'bell', 'Notifications'],
            ],
            'bottom' => [['coordinator', 'business.coordinator', 'grid', 'Home'], ['bookings', 'business.bookings', 'list', 'Bookings', null, 'orders.view'], ['calendar', 'business.calendar', 'calendar', 'Calendar', null, 'orders.view']],
        ],
        'event_finance' => [
            'label' => 'Finance', 'icon' => 'card', 'roles' => ['cashier'], 'home' => 'business.payments',
            'about' => 'Reservation fees, deposits and balances: confirm payments, send reminders.',
            'nav' => [
                ['Money'], ['payments', 'business.payments', 'card', 'Event Payments', 'eventPayments', 'payments.view'], ['bookings', 'business.bookings', 'list', 'Event Bookings', null, 'orders.view'],
                ['notifications', 'notifications', 'bell', 'Notifications'],
            ],
            'bottom' => [['payments', 'business.payments', 'card', 'Payments', null, 'payments.view'], ['bookings', 'business.bookings', 'list', 'Bookings', null, 'orders.view']],
        ],
        'event_crew' => [
            'label' => 'Event Crew', 'icon' => 'truck', 'roles' => ['crew'], 'home' => 'business.crew',
            'about' => 'The events your team works: when, where, who to call, and marking each part done.',
            'nav' => [
                ['My work'], ['crew', 'business.crew', 'truck', 'My Events'], ['calendar', 'business.calendar', 'calendar', 'Event Calendar', null, 'orders.view'], ['notifications', 'notifications', 'bell', 'Notifications'],
            ],
            'bottom' => [['crew', 'business.crew', 'truck', 'My Events'], ['calendar', 'business.calendar', 'calendar', 'Calendar', null, 'orders.view']],
        ],
    ];

    /** Pages that belong to a module even though they are not in its menu (e.g. a request opened from a list). */
    private const OWNS = [
        'event_coord' => ['business.coordinator', 'business.bookings*', 'business.calendar', 'business.teams', 'supplier.reviews*'],
        'event_finance' => ['business.payments*', 'business.bookings', 'business.bookings.show'],
        'event_crew' => ['business.crew', 'business.calendar', 'business.bookings.part-done'],
        'event_manage' => ['business.*', 'supplier.*'],
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
        if (! $shop) {
            return [];
        }
        $set = self::setFor($shop);
        $roles = $u->orgRoles();
        if (in_array('org_admin', $roles, true)) {
            return array_keys($set);
        }
        $keys = array_keys(array_filter($set, fn ($m) => (bool) array_intersect($m['roles'], $roles)));
        // keep the member's main role first so it is the default module
        usort($keys, fn ($a, $b) => self::rank($roles, $a) <=> self::rank($roles, $b));

        return $keys;
    }

    /** The module set of a company: solo shops / rental companies, or event companies. */
    public static function setFor(?\App\Models\Supplier $s): array
    {
        return $s && $s->isOrganization() ? self::EVENT : self::SHOP;
    }

    private static function rank(array $roles, string $module): int
    {
        foreach ($roles as $i => $r) {
            if (in_array($r, self::get($module)['roles'], true)) {
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
        return self::SHOP[$key] ?? self::EVENT[$key];
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
        if ($module === 'event_manage') {
            return array_replace(BusinessNav::config($u->shop()), ['tag' => 'Owner']);
        }
        $m = self::get($module);
        $items = array_map(fn ($it) => count($it) === 1 ? $it : [$it[0], $it[1], $it[2], $it[3], ! empty($it[4]) ? ($badges[$it[4]] ?? 0) : 0, $it[5] ?? null], $m['nav']);
        $items = array_values(array_filter($items, fn ($it) => count($it) === 1 || $can($it)));
        if (isset(self::SHOP[$module]) && $module !== 'manage' && ! $u->isShopOwner()) {
            $have = [];
            foreach (self::forUser($u) as $k) {
                foreach (self::SHOP[$k]['nav'] as $it) {
                    if (count($it) > 1) {
                        $have[$it[1]] = true;
                    }
                }
            }
            // A page for a permission this module already covers (e.g. the cashier's own Payments / Financial Reports)
            // would only repeat it under another name, so it is left out.
            $covered = [];
            foreach ($m['nav'] as $it) {
                if (count($it) > 1 && ! empty($it[5])) {
                    $covered[$it[5]] = true;
                }
            }
            $extra = array_filter(self::SHOP['manage']['nav'], fn ($it) => count($it) > 1 && ! isset($have[$it[1]]) && ! empty($it[5]) && ! isset($covered[$it[5]]) && $can($it));
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
    public static function matrix(?\App\Models\Supplier $s = null): array
    {
        $out = [];
        foreach (self::setFor($s) as $k => $m) {
            $out[$k] = ['label' => $m['label'], 'about' => $m['about'], 'roles' => array_merge(['org_admin'], array_diff($m['roles'], ['org_admin']))];
        }

        return $out;
    }

    public static function roleLabel(string $role): string
    {
        return ShopStaff::ORG_ROLES[$role] ?? ucfirst($role);
    }
}
