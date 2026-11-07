@extends('layouts.base')

@php
    $role = $shellRole;
    $b = $shellBadges ?? [];
    $NAV = [
        'supplier' => ['tag' => 'Supplier', 'items' => [
            ['Overview'], ['dashboard', 'supplier.dashboard', 'grid', 'Dashboard'], ['requests', 'supplier.requests', 'list', 'Rental Requests', $b['requests'] ?? 0], ['schedule', 'supplier.schedule', 'truck', 'Fulfillment & Returns'],
            ['Catalog'], ['products', 'supplier.products', 'box', 'Products'], ['packages', 'supplier.packages', 'package', 'Packages'], ['inventory', 'supplier.inventory', 'layers', 'Inventory'], ['calendar', 'supplier.calendar', 'calendar', 'Inventory Calendar'], ['damages', 'supplier.damages', 'tool', 'Damage & Compensation'],
            ['Money'], ['payments', 'supplier.payments', 'card', 'Payments'], ['reports', 'supplier.reports', 'chart', 'Business Reports'],
            ['Shop'], ['shop', 'supplier.shop', 'store', 'Shop Profile & Settings'], ['notifications', 'notifications', 'bell', 'Notifications'],
        ], 'bottom' => [['dashboard', 'supplier.dashboard', 'grid', 'Dashboard'], ['products', 'supplier.products', 'box', 'Products'], ['requests', 'supplier.requests', 'list', 'Requests'], ['shop', 'supplier.shop', 'store', 'Profile']]],
        'cashier' => ['tag' => 'Cashier', 'items' => [
            ['Payments'], ['dashboard', 'cashier.dashboard', 'grid', 'Dashboard'], ['verification', 'cashier.verification', 'check', 'Payment Verification', $b['verification'] ?? 0], ['record', 'cashier.record', 'cash', 'Record Payment'], ['balances', 'cashier.balances', 'clock', 'Installments & Overdue'],
            ['Records'], ['history', 'cashier.history', 'list', 'Payment History & Receipts'], ['reports', 'cashier.reports', 'chart', 'Financial Reports', 0, 'reports.view'], ['notifications', 'notifications', 'bell', 'Notifications'],
        ], 'bottom' => [['dashboard', 'cashier.dashboard', 'grid', 'Home'], ['verification', 'cashier.verification', 'check', 'Verify'], ['record', 'cashier.record', 'cash', 'Record'], ['history', 'cashier.history', 'list', 'History']]],
        'admin' => ['tag' => 'Admin', 'items' => [
            ['Operations'], ['dashboard', 'admin.dashboard', 'grid', 'Dashboard'], ['users', 'admin.users', 'users', 'Customers & Suppliers'], ['applications', 'admin.applications', 'file', 'Supplier Applications', $b['applications'] ?? 0],
            ['Monitoring'], ['transactions', 'admin.transactions', 'activity', 'Transactions & Reservations'], ['audit', 'admin.audit', 'clock', 'Activity Log'], ['notifications', 'notifications', 'bell', 'Notifications'],
        ], 'bottom' => [['dashboard', 'admin.dashboard', 'grid', 'Home'], ['users', 'admin.users', 'users', 'Users'], ['applications', 'admin.applications', 'file', 'Apps'], ['transactions', 'admin.transactions', 'activity', 'Rentals']]],
    ];
    $NAV['superadmin'] = ['tag' => 'Superadmin', 'items' => array_merge($NAV['admin']['items'], [['Payments'], ['verification', 'cashier.verification', 'check', 'Payment Verification'], ['cash-history', 'cashier.history', 'list', 'Payment History']]), 'bottom' => $NAV['admin']['bottom']];
    $cfg = $NAV[$role] ?? $NAV['admin'];
    $sup = $role === 'supplier' ? $shellUser->supplier : null;
    $nav = $nav ?? '';
@endphp

@section('body')
<div class="dash">
  <aside class="side" id="side">
    @include('partials.brand', ['tag' => $cfg['tag']])
    @if($sup)
      <div style="display:flex;gap:10px;align-items:center;padding:0 8px 12px"><div class="logo-tile" style="width:40px;height:40px;font-size:1.3rem;background:rgba(255,255,255,.1)">{{ $sup->logo }}</div><div class="small" style="color:#fff;font-weight:700;line-height:1.25">{{ $sup->name }}<div style="color:#8E83A8;font-weight:500">{{ $sup->city }}</div></div></div>
      <form method="POST" action="{{ route('mode.switch') }}">@csrf<input type="hidden" name="mode" value="customer"><button class="mode-switch">{{ icon('swap') }}Switch to Customer Mode</button></form>
    @endif
    @foreach($cfg['items'] as $it)
      @continue(! empty($it[5]) && ! $shellUser->hasPerm($it[5]))
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
  @foreach($cfg['bottom'] as [$k, $r, $ic, $l])<a href="{{ route($r) }}" class="{{ $nav === $k ? 'active' : '' }}">{{ icon($ic) }}{{ $l }}</a>@endforeach
  <button type="button" data-side-open>{{ icon('menu') }}More</button>
</nav>
@endsection
