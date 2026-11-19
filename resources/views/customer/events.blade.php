@extends('layouts.market', ['title' => 'My Events', 'nav' => 'rentals'])

@section('content')
<div class="page-head"><div><h1>My Events</h1><p>Events you booked with event companies: quotations, payment schedules and status. Item rentals are in <a href="{{ route('rentals.index') }}">My Rentals</a>.</p></div>
  <a class="btn" href="{{ route('suppliers.index', ['type' => 'business']) }}">{{ icon('store') }}Find event companies</a></div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '🎊', 'title' => 'No event bookings yet', 'text' => 'Event companies handle the whole event for you. Pick one and ask for a quotation.', 'action' => '<a class="btn btn-primary" href="'.route('suppliers.index', ['type' => 'business']).'">Find event companies</a>'])</div>
@else
  <div class="stack">
    @foreach($list as $b)
      @php($next = $b->payments->firstWhere('status', 'due'))
      <a class="card row between wrap" href="{{ route('events.show', $b) }}" style="color:inherit;text-decoration:none">
        <div><b>{{ $b->event_type }}</b> · {{ $b->event_date->format('D, M j, Y') }}<div class="small muted">{{ $b->supplier->name }} · {{ $b->code }} · {{ $b->guests }} guests{{ $b->package ? ' · '.$b->package->name : '' }}</div>
          @if($b->status === 'quoted')<div class="small strong mt-1" style="color:var(--brand)">Quotation ready: {{ money($b->total()) }} · accept by {{ fmt_date($b->quote['validUntil']) }}</div>@endif
          @if($next && in_array($b->status, ['booked', 'confirmed'], true))<div class="small mt-1" @if($next->isOverdue()) style="color:var(--red)" @endif>{{ $next->label }} {{ money($next->amount) }} due {{ $next->due_date->format('M j') }}</div>@endif</div>
        <span class="badge {{ $b->statusBadge() }}">{{ $b->statusLabel() }}</span>
      </a>
    @endforeach
  </div>
@endif
@endsection
