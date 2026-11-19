@extends('layouts.dash', ['title' => 'Supplier Dashboard', 'nav' => 'dashboard'])

{{-- Short on purpose: what needs you now, what is coming up, how the money is doing.
     Stock, paid/unpaid counts and category figures are on Inventory, Payments and Reports. --}}
@php($st = $stats)
@section('content')
<div class="page-head"><div><h1>Good day, {{ auth()->user()->firstName() }}! {{ $sup->logo }}</h1><p>{{ $sup->name }} · here's your business today, {{ fmt_date(today_iso()) }}.</p></div>
  <div class="row wrap">@if($canProd)<a class="btn" href="{{ route('supplier.products.create') }}">{{ icon('plus') }}Add product</a>@endif<a class="btn btn-primary" href="{{ route('supplier.requests') }}">{{ icon('list') }}Review requests{{ $pend->count() ? ' ('.$pend->count().')' : '' }}</a></div></div>
@if($conflicts->isNotEmpty())
  <div class="alert alert-warn mb-2">{{ icon('alert') }}<div><b>Not enough stock:</b> {!! $conflicts->map(fn ($r) => '<a href="'.e(route('supplier.requests.show', $r)).'">'.e($r->code).'</a>')->implode(', ') !!} ask for more than you have available. Check before accepting.</div></div>
@endif
@if($sup->isOrganization() && $canProd && ! $sup->packages()->exists())
  <div class="card mb-2"><div class="row wrap between"><div><h3 class="mb-0">Get your business ready</h3><p class="small muted mb-0">Customers book businesses by event package. Create your first package (items, services and setup in one price); single items are optional.</p></div><div class="row wrap gap-sm"><a class="btn btn-primary" href="{{ route('supplier.packages.create') }}">{{ icon('package') }}Create your first event package</a><a class="btn" href="{{ route('supplier.shop') }}">{{ icon('store') }}Shop settings</a></div></div></div>
@elseif(! $sup->isOrganization() && ! $hasProducts && $canProd)
  <div class="card mb-2"><div class="row wrap between"><div><h3 class="mb-0">Get your shop ready</h3><p class="small muted mb-0">Customers can rent from you once you add your first product or package.</p></div><div class="row wrap gap-sm"><a class="btn btn-primary" href="{{ route('supplier.products.create') }}">{{ icon('plus') }}Add your first product</a><a class="btn" href="{{ route('supplier.shop') }}">{{ icon('store') }}Shop settings</a></div></div></div>
@endif
<div class="stats">
  @include('partials.stat', ['label' => 'New requests', 'value' => $pend->count(), 'sub' => $pend->count() ? 'waiting for your answer' : 'all caught up', 'icon' => 'clock', 'color' => 'gold', 'href' => route('supplier.requests')])
  @if($money)
    @include('partials.stat', ['label' => 'Check payments', 'value' => $st['pendingPay'], 'sub' => $st['pendingPay'] ? money_short($st['pendingPayAmt']).' sent by customers' : 'nothing waiting', 'icon' => 'card', 'color' => 'sky', 'href' => route('supplier.payments', ['tab' => 'verify'])])
  @endif
  @include('partials.stat', ['label' => 'Upcoming', 'value' => $st['upcoming'], 'sub' => $st['next'] ? 'next on '.\Carbon\Carbon::parse($st['next'])->format('M j') : 'none scheduled', 'icon' => 'calendar', 'color' => 'purple', 'href' => route('supplier.requests', ['tab' => 'upcoming'])])
  @include('partials.stat', ['label' => 'With customers', 'value' => $st['out'], 'sub' => $st['returning'] ? $st['returning'].' coming back / to inspect' : 'items being used', 'icon' => 'truck', 'color' => 'pink', 'href' => route('supplier.schedule')])
  @if($money)
    @include('partials.stat', ['label' => 'Overdue', 'value' => $st['overdue'], 'sub' => $st['overdue'] ? money_short($st['overdueAmt']).' late' : 'none late', 'icon' => 'alert', 'color' => 'red', 'href' => route('supplier.payments', ['tab' => 'overdue'])])
    @include('partials.stat', ['label' => 'Revenue', 'value' => money_short($st['revenue']), 'sub' => 'this month, verified', 'icon' => 'chart', 'color' => 'teal', 'href' => route('supplier.reports')])
  @else
    @include('partials.stat', ['label' => 'To repair', 'value' => $st['repair'], 'sub' => 'damaged, missing or in maintenance', 'icon' => 'tool', 'color' => 'gold', 'href' => route('supplier.inventory')])
  @endif
</div>
<div class="grid grid-2 mt-3">
  <div class="card"><div class="card-head"><h3>Upcoming rentals</h3><a class="small strong" href="{{ route('supplier.schedule') }}">Open schedule</a></div>
    @forelse($upcoming as $r)
      <a class="row between" href="{{ route('supplier.requests.show', $r) }}" style="padding:10px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><div><b>{{ \Carbon\Carbon::parse($r->start_date)->format('M j') }}</b> · {{ $r->event_name }}<div class="small muted">{{ $r->code }} · {{ $r->customer->name }} · {{ $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup' }}</div></div>@include('partials.badges', ['pay' => $r->fin()['status']])</a>
    @empty
      <p class="muted">No upcoming rentals yet.</p>
    @endforelse
  </div>
  @if($money)<div class="card"><div class="card-head"><h3>Revenue</h3><span class="small muted">Last 6 months</span></div>@if(collect($charts['revenue'])->sum('value') != 0)@include('partials.chart', ['kind' => 'line', 'rows' => $charts['revenue'], 'opts' => ['money' => true, 'color' => '#E11D74', 'label' => 'Revenue']])@else<p class="muted">No payments yet. Your income shows here once customers pay.</p>@endif</div>@endif
  @if($charts['top'])<div class="card"><div class="card-head"><h3>Most rented items</h3><span class="small muted">Units booked</span></div>@include('partials.chart', ['kind' => 'hbars', 'rows' => $charts['top'], 'opts' => ['color' => '#7C3AED']])</div>@endif
</div>
@endsection
