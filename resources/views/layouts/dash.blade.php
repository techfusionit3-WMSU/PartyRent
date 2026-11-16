@extends('layouts.base')

@php
    // Port of NAV in reference js/ui/layout.js: [key, route, icon, label, count, org permission].
    $role = $shellRole;
    $b = $shellBadges ?? [];
    $NAV = [
        'supplier' => ['tag' => 'Supplier', 'items' => [
            ['Overview'], ['dashboard', 'supplier.dashboard', 'grid', 'Dashboard'], ['requests', 'supplier.requests', 'list', 'Rental Requests', $b['requests'] ?? 0, 'orders.view'], ['schedule', 'supplier.schedule', 'truck', 'Fulfillment & Returns', 0, 'orders.view'],
            ['Catalog'], ['products', 'supplier.products', 'box', 'Products', 0, 'products.manage'], ['packages', 'supplier.packages', 'package', 'Packages', 0, 'products.manage'], ['discounts', 'supplier.discounts', 'tag', 'Discounts', 0, 'products.manage'], ['inventory', 'supplier.inventory', 'layers', 'Inventory', 0, 'inventory.manage'], ['locations', 'supplier.locations', 'map', 'Inventory Locations', 0, 'locations.manage'], ['calendar', 'supplier.calendar', 'calendar', 'Inventory Calendar', 0, 'orders.view'],
            ['Money & reports'], ['payments', 'supplier.payments', 'card', 'Payments', $b['payments'] ?? 0, 'payments.view'], ['damages', 'supplier.damages', 'tool', 'Damage & Compensation', 0, 'returns.inspect'], ['reports', 'supplier.reports', 'bars', 'Business Reports', 0, 'reports.view'],
            ['Shop'], ['shop', 'supplier.shop', 'store', 'Shop Profile & Settings', 0, 'shop.manage'], ['team', 'supplier.team', 'users', 'Team & Roles', 0, 'team.manage'], ['notifications', 'notifications', 'bell', 'Notifications'],
        ], 'bottom' => [['dashboard', 'supplier.dashboard', 'grid', 'Dashboard'], ['products', 'supplier.products', 'box', 'Products', 'products.manage'], ['requests', 'supplier.requests', 'list', 'Requests', 'orders.view'], ['payments', 'supplier.payments', 'card', 'Payments', 'payments.view']]],
        'cashier' => ['tag' => 'Cashier', 'items' => [
            ['Payments'], ['dashboard', 'cashier.dashboard', 'grid', 'Dashboard'], ['verification', 'cashier.verification', 'check', 'Payment Verification', $b['verification'] ?? 0], ['record', 'cashier.record', 'cash', 'Record Payment'], ['balances', 'cashier.balances', 'clock', 'Installments & Overdue'],
            ['Records'], ['history', 'cashier.history', 'list', 'Payment History'], ['receipts', 'cashier.receipts', 'receipt', 'Receipts'], ['reports', 'cashier.reports', 'bars', 'Financial Reports'], ['notifications', 'notifications', 'bell', 'Notifications'],
        ], 'bottom' => [['dashboard', 'cashier.dashboard', 'grid', 'Home'], ['verification', 'cashier.verification', 'check', 'Verify'], ['record', 'cashier.record', 'cash', 'Record'], ['history', 'cashier.history', 'list', 'History']]],
        'admin' => ['tag' => 'Admin', 'items' => [
            ['Operations'], ['dashboard', 'admin.dashboard', 'grid', 'Dashboard'], ['users', 'admin.users', 'users', 'Customers & Suppliers'], ['applications', 'admin.applications', 'file', 'Supplier Applications', $b['applications'] ?? 0], ['listings', 'admin.listings', 'box', 'Listings & Packages'], ['categories', 'admin.categories', 'tag', 'Categories'],
            ['Monitoring'], ['transactions', 'admin.transactions', 'activity', 'Transactions & Reservations'], ['complaints', 'admin.complaints', 'flag', 'Complaints & Issues', $b['complaints'] ?? 0], ['activity', 'admin.activity', 'clock', 'Activity Log'], ['reports', 'admin.reports', 'bars', 'Reports'], ['notifications', 'notifications', 'bell', 'Notifications'],
        ], 'bottom' => [['dashboard', 'admin.dashboard', 'grid', 'Home'], ['users', 'admin.users', 'users', 'Users'], ['applications', 'admin.applications', 'file', 'Apps'], ['transactions', 'admin.transactions', 'activity', 'Rentals']]],
        'superadmin' => ['tag' => 'Superadmin', 'items' => [
            ['Platform'], ['dashboard', 'super.dashboard', 'grid', 'Dashboard'], ['monitoring', 'super.monitoring', 'activity', 'Platform Monitoring'], ['users', 'super.users', 'users', 'Platform Users'], ['reports', 'super.reports', 'bars', 'Platform Reports'],
            ['Access control'], ['admins', 'super.admins', 'shield', 'Administrators'], ['roles', 'super.roles', 'lock', 'Roles & Permissions'],
            ['System'], ['audit', 'super.audit', 'file', 'Audit Logs'], ['settings', 'super.settings', 'settings', 'System Settings'], ['notifications', 'notifications', 'bell', 'Notifications'],
        ], 'bottom' => [['dashboard', 'super.dashboard', 'grid', 'Home'], ['monitoring', 'super.monitoring', 'activity', 'Monitor'], ['admins', 'super.admins', 'shield', 'Admins'], ['audit', 'super.audit', 'file', 'Audit']]],
    ];
    $cfg = $NAV[$role] ?? $NAV['admin'];
    $sup = in_array($role, ['supplier', 'cashier'], true) ? $shellUser->shop() : null;
    $emp = $shellUser->isEmployee();
    // Supplier employees only see the sections their organization role allows; a group label shows only before a visible item.
    $allowed = fn ($it) => \Illuminate\Support\Facades\Route::has($it[1]) && ($role !== 'supplier' || empty($it[5]) || $shellUser->orgCan($it[5]));
    $items = [];
    $label = null;
    foreach ($cfg['items'] as $it) {
        if (count($it) === 1) { $label = $it; continue; }
        if (! $allowed($it)) { continue; }
        if ($label) { $items[] = $label; $label = null; }
        $items[] = $it;
    }
    $bottom = array_filter($cfg['bottom'], fn ($it) => \Illuminate\Support\Facades\Route::has($it[1]) && ($role !== 'supplier' || empty($it[4]) || $shellUser->orgCan($it[4])));
    $tag = $role === 'supplier' && $emp ? (\App\Models\ShopStaff::ORG_ROLES[$shellUser->orgRole()] ?? 'Staff') : ($role === 'supplier' && $sup && $sup->type === 'organization' ? 'Org Admin' : $cfg['tag']);
    $nav = $nav ?? '';
@endphp

@section('body')
<div class="dash">
  <aside class="side" id="side">
    @include('partials.brand', ['tag' => $tag])
    @if($sup)
      <div style="display:flex;gap:10px;align-items:center;padding:0 8px 12px"><div class="logo-tile" style="width:40px;height:40px;font-size:1.3rem;background:rgba(255,255,255,.1)">{{ $sup->logo }}</div><div class="small" style="color:#fff;font-weight:700;line-height:1.25">{{ $sup->name }}<div style="color:#8E83A8;font-weight:500">{{ $sup->city }}{{ $sup->type === 'organization' ? ' · Organization' : '' }}</div></div></div>
      @unless($emp)<form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="customer"><button class="mode-switch">{{ icon('swap') }}Switch to Customer Mode</button></form>@endunless
    @endif
    @foreach($items as $it)
      @if(count($it) === 1)
        <div class="nav-label">{{ $it[0] }}</div>
      @else
        <a class="nav {{ $nav === $it[0] ? 'active' : '' }}" href="{{ route($it[1]) }}">{{ icon($it[2]) }}<span>{{ $it[3] }}</span>@if(! empty($it[4]))<span class="cnt">{{ $it[4] }}</span>@endif</a>
      @endif
    @endforeach
    <div class="side-foot">Signed in as <b style="color:#fff">{{ $shellUser->name }}</b><br>{{ $shellUser->email }}</div>
  </aside>
  <div class="side-backdrop" id="sideBd"></div>
  <div class="dash-body">
    <header class="dash-top"><button class="icon-btn menu-toggle" data-side-open aria-label="Open menu">{{ icon('menu') }}</button><div class="ttl grow">{{ $title ?? '' }}</div>@include('partials.notif-dropdown')@include('partials.profile-menu')</header>
    <main class="dash-main" id="main">
      @yield('content')
    </main>
  </div>
</div>
<nav class="bottom-nav">
  @foreach($bottom as $it)<a href="{{ route($it[1]) }}" class="{{ $nav === $it[0] ? 'active' : '' }}">{{ icon($it[2]) }}{{ $it[3] }}</a>@endforeach
  <button type="button" data-side-open>{{ icon('menu') }}More</button>
</nav>
@endsection
