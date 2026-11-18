@extends('layouts.market', ['title' => 'My Rentals', 'nav' => 'rentals'])

@section('content')
<div class="page-head"><div><h1>My Rentals</h1><p>Track requests, payments and returns.</p></div>
  <form method="GET"><input type="hidden" name="tab" value="{{ $tab }}"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search request no. or event" style="width:240px" aria-label="Search rentals"></form>
</div>
<div class="tabs" role="tablist">
  @foreach([['all', 'All'], ['pending', 'Pending'], ['active', 'Active'], ['completed', 'Completed'], ['closed', 'Declined / Cancelled']] as [$k, $l])
    <a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('rentals.index', array_filter(['tab' => $k, 'q' => $q])) }}">{{ $l }}<span class="count">{{ $counts[$k] }}</span></a>
  @endforeach
</div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '📋', 'title' => 'No rentals here', 'text' => $tab === 'all' ? 'When you submit a rental request it will appear here.' : 'Nothing in this tab yet.', 'action' => '<a class="btn btn-primary" href="'.route('home').'">Find party supplies</a>'])</div>
@else
  <div class="stack">
    @foreach($list as $r)
      @php($f = $r->fin())
      <a class="card row wrap between" style="color:inherit;text-decoration:none" href="{{ route('rentals.show', $r) }}">
        <div class="row" style="min-width:0"><div class="logo-tile" style="background:{{ $r->supplier->color }}1a">{{ $r->supplier->logo }}</div><div style="min-width:0"><div class="strong">{{ $r->event_name }}</div><div class="small muted"><span class="txn-mini">{{ $r->code }}</span> {{ $r->supplier->name }}</div><div class="small">{{ icon('calendar', 'width:13px;vertical-align:-2px') }} {{ fmt_range($r->start_date, $r->end_date) }} · {{ $r->fulfillment === 'delivery' ? 'Delivery' : 'Pickup' }}</div></div></div>
        <div class="row wrap"><div class="right"><div class="small muted">Total</div><b>{{ money($f['payable']) }}</b>@if($f['balance'] > 0 && $r->isActive())<div class="small" style="color:var(--brand)">Balance {{ money($f['balance']) }}</div>@endif</div><div class="stack-sm" style="align-items:flex-end">@include('partials.badges', ['status' => $r->status])@include('partials.badges', ['pay' => $f['status']])</div></div>
      </a>
    @endforeach
  </div>
@endif
@endsection
