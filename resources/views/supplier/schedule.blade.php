@extends('layouts.dash', ['title' => 'Fulfillment & Returns', 'nav' => 'schedule'])

@section('content')
<div class="page-head"><div><h1>Fulfillment & Returns</h1><p>Move rentals through preparation, release, return and inspection.</p></div></div>
<div class="card mb-3"><div class="card-head"><h3>Rental schedule — next 14 days</h3></div>
  @if(! $events)<p class="muted">Nothing scheduled in the next two weeks.</p>@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Date</th><th>Activity</th><th>Request</th><th>Customer</th><th>Items</th><th>Status</th></tr></thead><tbody>
    @foreach($events as $e)
      <tr><td data-label="Date"><b>{{ fmt_day($e['d']) }}</b>@if($e['d'] < $T) <span class="badge b-red">Late</span>@elseif($e['d'] === $T) <span class="badge b-pink">Today</span>@endif</td>
        <td data-label="Activity"><span class="badge {{ in_array($e['kind'], ['Return', 'Inspect return'], true) ? 'b-gold' : 'b-teal' }}">{{ $e['kind'] }}</span></td>
        <td data-label="Request"><a href="{{ route('supplier.requests.show', $e['r']) }}">{{ $e['r']->code }}</a> · {{ $e['r']->event_name }}</td>
        <td data-label="Customer">{{ $e['r']->customer->name }}</td>
        <td data-label="Items"><span class="small">{{ collect($e['r']->lines)->map(fn ($l) => inv()->itemName($l))->implode(', ') }}</span></td>
        <td data-label="Status">@include('partials.badges', ['status' => $e['r']->status])</td></tr>
    @endforeach
  </tbody></table></div>@endif
</div>
<div class="grid grid-3">
  @foreach($cols as [$t, $st, $cls])
    @php($l = $reqs->whereIn('status', $st)->sortBy('start_date'))
    <div class="card"><div class="card-head"><h3>{{ $t }}</h3><span class="badge {{ $cls }}">{{ $l->count() }}</span></div>
      @forelse($l as $r)
        <a href="{{ route('supplier.requests.show', $r) }}" class="card card-flat mb-1" style="display:block;padding:12px;color:inherit;text-decoration:none"><div class="row between"><b>{{ $r->code }}</b><span class="small muted">{{ fmt_range($r->start_date, $r->end_date) }}</span></div><div class="small">{{ $r->event_name }} · {{ $r->customer->name }}</div><div class="small muted">{{ $r->fulfillment === 'delivery' ? '🚚 Delivery' : '🏬 Pickup' }} · @include('partials.badges', ['pay' => $r->fin()['status']])</div></a>
      @empty <p class="small muted">None</p> @endforelse
    </div>
  @endforeach
</div>
@endsection
