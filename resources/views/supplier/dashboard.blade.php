@extends('layouts.dash', ['title' => 'Supplier Dashboard', 'nav' => 'dashboard'])

{{-- Reference SP.dashboard --}}
@php($st = $stats)
@section('content')
<div class="page-head"><div><h1>Good day, {{ auth()->user()->firstName() }}! {{ $sup->logo }}</h1><p>{{ $sup->name }} · here's your business today, {{ fmt_date(today_iso()) }}.</p></div>
  <div class="row wrap">@if($canProd)<a class="btn" href="{{ route('supplier.products.create') }}">{{ icon('plus') }}Add product</a>@endif<a class="btn btn-primary" href="{{ route('supplier.requests') }}">{{ icon('list') }}Review requests{{ $pend->count() ? ' ('.$pend->count().')' : '' }}</a></div></div>
@if($conflicts->isNotEmpty())
  <div class="alert alert-warn mb-2">{{ icon('alert') }}<div><b>Inventory conflict:</b> {!! $conflicts->map(fn ($r) => '<a href="'.e(route('supplier.requests.show', $r)).'">'.e($r->code).'</a>')->implode(', ') !!} request more than you have available. Review before accepting.</div></div>
@endif
<div class="stats">
  @include('partials.stat', ['label' => 'Listed rental items', 'value' => $st['listed'], 'sub' => $st['prodCount'].' total incl. inactive', 'icon' => 'box', 'color' => 'purple', 'href' => route('supplier.products')])
  @include('partials.stat', ['label' => 'Total stock', 'value' => number_format($st['total']), 'sub' => 'units across all items', 'icon' => 'layers', 'color' => 'sky', 'href' => route('supplier.inventory')])
  @include('partials.stat', ['label' => 'Available today', 'value' => number_format($st['available']), 'sub' => 'units free to rent', 'icon' => 'check', 'color' => 'teal', 'href' => route('supplier.inventory')])
  @include('partials.stat', ['label' => 'Reserved today', 'value' => number_format($st['reserved']), 'sub' => 'from accepted rentals', 'icon' => 'calendar', 'color' => 'pink', 'href' => route('supplier.calendar')])
  @include('partials.stat', ['label' => 'Maintenance / damaged', 'value' => $st['outOfService'], 'sub' => $st['maintenance'].' in maintenance', 'icon' => 'tool', 'color' => 'gold', 'href' => route('supplier.inventory')])
  @include('partials.stat', ['label' => 'Pending requests', 'value' => $pend->count(), 'sub' => 'awaiting your review', 'icon' => 'clock', 'color' => 'gold', 'href' => route('supplier.requests')])
  @include('partials.stat', ['label' => 'Accepted reservations', 'value' => $st['accepted'], 'sub' => 'upcoming', 'icon' => 'calendar', 'color' => 'sky', 'href' => route('supplier.requests', ['tab' => 'upcoming'])])
  @include('partials.stat', ['label' => 'Ongoing rentals', 'value' => $st['ongoing'], 'sub' => 'with customers / returning', 'icon' => 'truck', 'color' => 'pink', 'href' => route('supplier.schedule')])
  @include('partials.stat', ['label' => 'Completed', 'value' => $st['completed'], 'sub' => 'transactions', 'icon' => 'check', 'color' => 'teal'])
  @if($money)
    @include('partials.stat', ['label' => 'Pending payments', 'value' => $st['pendingPay'], 'sub' => money_short($st['pendingPayAmt']).' awaiting verification', 'icon' => 'card', 'color' => 'gold', 'href' => route('supplier.payments', ['tab' => 'verify'])])
    @include('partials.stat', ['label' => 'Partially paid', 'value' => $st['partly'], 'icon' => 'cash', 'color' => 'sky', 'href' => route('supplier.payments')])
    @include('partials.stat', ['label' => 'Fully paid', 'value' => $st['fully'], 'icon' => 'check', 'color' => 'teal', 'href' => route('supplier.payments')])
    @include('partials.stat', ['label' => 'Overdue payments', 'value' => $st['overdue'], 'sub' => money_short($st['overdueAmt']).' outstanding', 'icon' => 'alert', 'color' => 'red', 'href' => route('supplier.payments', ['tab' => 'overdue'])])
    @include('partials.stat', ['label' => 'Revenue this month', 'value' => money_short($st['revenue']), 'sub' => 'verified rental payments', 'icon' => 'chart', 'color' => 'purple', 'href' => route('supplier.reports')])
  @endif
</div>
<div class="grid grid-2 mt-3">
  <div class="card"><div class="card-head"><h3>Monthly rental transactions</h3><span class="small muted">Requests received</span></div>@include('partials.chart', ['kind' => 'bars', 'rows' => $charts['monthly'], 'opts' => ['color' => '#7C3AED', 'label' => 'Monthly requests']])</div>
  @if($money)<div class="card"><div class="card-head"><h3>Revenue trend</h3><span class="small muted">Verified rental payments</span></div>@include('partials.chart', ['kind' => 'line', 'rows' => $charts['revenue'], 'opts' => ['money' => true, 'color' => '#E11D74', 'label' => 'Revenue']])</div>@endif
  <div class="card"><div class="card-head"><h3>Most rented products</h3><span class="small muted">Units booked (all time)</span></div>@include('partials.chart', ['kind' => 'hbars', 'rows' => $charts['top'], 'opts' => ['color' => '#E11D74']])</div>
  <div class="card"><div class="card-head"><h3>Reservation status</h3></div>@include('partials.chart', ['kind' => 'donut', 'rows' => $charts['status'], 'opts' => ['center' => 'requests']])</div>
  @if($money)<div class="card"><div class="card-head"><h3>Category performance</h3><span class="small muted">Rental income by category</span></div>@include('partials.chart', ['kind' => 'hbars', 'rows' => $charts['category'], 'opts' => ['money' => true, 'color' => '#7C3AED']])</div>@endif
  <div class="card"><div class="card-head"><h3>Upcoming rental schedule</h3><a class="small strong" href="{{ route('supplier.schedule') }}">Open schedule</a></div>
    @forelse($upcoming as $r)
      <a class="row between" href="{{ route('supplier.requests.show', $r) }}" style="padding:10px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><div><b>{{ \Carbon\Carbon::parse($r->start_date)->format('M j') }}</b> · {{ $r->event_name }}<div class="small muted">{{ $r->code }} · {{ $r->customer->name }} · {{ $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup' }}</div></div>@include('partials.badges', ['status' => $r->status])</a>
    @empty
      <p class="muted">No upcoming rentals.</p>
    @endforelse
  </div>
</div>
@endsection
