@extends('layouts.dash', ['title' => 'Dashboard', 'nav' => 'dashboard'])

@section('content')
<div class="page-head"><div><h1>Good day, {{ auth()->user()->firstName() }}! {{ $sup->logo }}</h1><p>{{ $sup->name }} · here's your business today, {{ fmt_date(today_iso()) }}.</p></div>
  <div class="row wrap"><a class="btn" href="{{ route('supplier.products.create') }}">{{ icon('plus') }}Add product</a><a class="btn btn-primary" href="{{ route('supplier.requests') }}">{{ icon('list') }}Review requests{{ $pend->count() ? ' ('.$pend->count().')' : '' }}</a></div>
</div>
@if($conflicted->isNotEmpty())<div class="alert alert-warn mb-2">{{ icon('alert') }}<div><b>Inventory conflict:</b> {!! $conflicted->map(fn ($r) => '<a href="'.e(route('supplier.requests.show', $r)).'">'.e($r->code).'</a>')->implode(', ') !!} request more than you have available. Review before accepting.</div></div>@endif
<div class="stats">
  @include('partials.stat', ['label' => 'Listed rental items', 'value' => $prods->where('status', 'published')->count(), 'sub' => $prods->count().' total incl. drafts', 'icon' => 'box', 'color' => 'purple', 'href' => route('supplier.products')])
  @include('partials.stat', ['label' => 'Total stock', 'value' => number_format($snap->sum('total')), 'sub' => 'units across all items', 'icon' => 'layers', 'color' => 'sky', 'href' => route('supplier.inventory')])
  @include('partials.stat', ['label' => 'Available today', 'value' => number_format($snap->sum('available')), 'sub' => 'units free to rent', 'icon' => 'check', 'color' => 'teal', 'href' => route('supplier.inventory')])
  @include('partials.stat', ['label' => 'Reserved today', 'value' => number_format($snap->sum('reserved')), 'sub' => 'from accepted rentals', 'icon' => 'calendar', 'color' => 'pink', 'href' => route('supplier.inventory')])
  @include('partials.stat', ['label' => 'Maintenance / damaged', 'value' => $snap->sum(fn ($b) => $b['maintenance'] + $b['damaged'] + $b['missing'] + $b['setaside']), 'sub' => $snap->sum('maintenance').' in maintenance', 'icon' => 'tool', 'color' => 'gold', 'href' => route('supplier.inventory')])
  @include('partials.stat', ['label' => 'Pending requests', 'value' => $pend->count(), 'sub' => 'awaiting your review', 'icon' => 'clock', 'color' => 'gold', 'href' => route('supplier.requests')])
  @include('partials.stat', ['label' => 'Accepted reservations', 'value' => $reqs->whereIn('status', ['accepted', 'in_progress', 'ready_pickup', 'out_delivery'])->count(), 'sub' => 'upcoming', 'icon' => 'calendar', 'color' => 'sky', 'href' => route('supplier.requests', ['tab' => 'upcoming'])])
  @include('partials.stat', ['label' => 'Ongoing rentals', 'value' => $reqs->whereIn('status', ['ongoing', 'return_pending', 'inspection'])->count(), 'sub' => 'with customers / returning', 'icon' => 'truck', 'color' => 'pink', 'href' => route('supplier.schedule')])
  @include('partials.stat', ['label' => 'Completed', 'value' => $reqs->where('status', 'completed')->count(), 'sub' => 'transactions', 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Pending payments', 'value' => $pendingPay->count(), 'sub' => money_short($pendingPay->sum('amount')).' awaiting cashier', 'icon' => 'card', 'color' => 'gold', 'href' => route('supplier.payments', ['tab' => 'pending'])])
  @include('partials.stat', ['label' => 'Fully paid', 'value' => $fins->where('status', 'Fully Paid')->count(), 'icon' => 'check', 'color' => 'teal', 'href' => route('supplier.payments')])
  @include('partials.stat', ['label' => 'Overdue payments', 'value' => $fins->where('isOverdue', true)->count(), 'sub' => money_short($fins->where('isOverdue', true)->sum('overdueAmount')).' outstanding', 'icon' => 'alert', 'color' => 'red', 'href' => route('supplier.payments', ['tab' => 'overdue'])])
  @include('partials.stat', ['label' => 'Rental income this month', 'value' => money_short($monthSplit['rental']), 'sub' => 'rentals, services & delivery', 'icon' => 'chart', 'color' => 'purple', 'href' => route('supplier.reports')])
  @include('partials.stat', ['label' => 'Sales income this month', 'value' => money_short($monthSplit['sales']), 'sub' => 'consumables sold', 'icon' => 'tag', 'color' => 'teal', 'href' => route('supplier.reports')])
  @include('partials.stat', ['label' => 'Low stock', 'value' => $lowStock->count(), 'sub' => $lowStock->count() ? $lowStock->pluck('name')->take(2)->implode(', ') : 'sale items are well stocked', 'icon' => 'alert', 'color' => $lowStock->count() ? 'red' : 'teal', 'href' => route('supplier.inventory')])
</div>
<div class="grid grid-2 mt-3">
  <div class="card"><div class="card-head"><h3>Monthly rental requests</h3><span class="small muted">Last 6 months</span></div>@include('partials.bars', ['rows' => $monthly, 'color' => '#7C3AED'])</div>
  <div class="card"><div class="card-head"><h3>Revenue trend</h3><span class="small muted">Verified rental payments</span></div>@include('partials.bars', ['rows' => $revenue, 'money' => true])</div>
  <div class="card"><div class="card-head"><h3>Most rented products</h3><span class="small muted">Units booked (all time)</span></div>@include('partials.bars', ['rows' => $topUnits])</div>
  <div class="card"><div class="card-head"><h3>Upcoming rental schedule</h3><a class="small strong" href="{{ route('supplier.schedule') }}">Open schedule</a></div>
    @forelse($upcoming as $r)
      <a class="row between" href="{{ route('supplier.requests.show', $r) }}" style="padding:10px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><div><b>{{ fmt_date($r->start_date, false) }}</b> · {{ $r->event_name }}<div class="small muted">{{ $r->code }} · {{ $r->customer->name }} · {{ $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup' }}</div></div>@include('partials.badges', ['status' => $r->status])</a>
    @empty <p class="muted">No upcoming rentals.</p> @endforelse
  </div>
</div>
@endsection
