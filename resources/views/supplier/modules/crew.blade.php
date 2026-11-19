@extends('layouts.dash', ['title' => 'My Jobs', 'nav' => 'crew'])

{{-- Field Crew module home: the day's handovers and returns, phone-first, with where to go. --}}
@php($canStep = auth()->user()->orgCan('fulfillment.update'))
@section('content')
<div class="page-head"><div><h1>My Jobs</h1><p>{{ $sup->name }} · deliveries, pickups and returns. Open a job to mark each step done.</p></div></div>

@foreach([['Late', $late, 'b-red', 'Should have been done already'], ['Today · '.fmt_date($T), $today, 'b-pink', null], ['Next 7 days', $next, 'b-sky', null]] as [$head, $list, $cls, $note])
  @continue($head === 'Late' && ! $list)
  <div class="card mb-2"><div class="card-head"><h3>{{ $head }}</h3><span class="badge {{ $cls }}">{{ count($list) }}</span></div>
    @if($note)<p class="small muted">{{ $note }}</p>@endif
    @forelse($list as $j)
      @php($r = $j['r'])
      @php($road = \App\Domain\Jobs::onTheRoad($j))
      <div class="card card-flat mb-1" style="padding:12px">
        <div class="row between wrap"><div><span class="badge {{ in_array($j['kind'], ['Return', 'Inspect return'], true) ? 'b-gold' : 'b-teal' }}">{{ $j['kind'] }}</span> <b>{{ $r->code }}</b> <span class="small muted">· {{ fmt_day($j['d']) }}</span></div>@include('partials.badges', ['status' => $r->status])</div>
        <div class="small mt-1">{{ $r->event_name }} · {{ collect($r->lines)->map(fn ($l) => inv()->itemName($l))->implode(', ') }}</div>
        <div class="small mt-1">{{ icon('user') }} {{ $r->contact_name ?: $r->customer->name }}@if($r->contact_phone) · <a href="tel:{{ preg_replace('/[^0-9+]/', '', $r->contact_phone) }}">{{ $r->contact_phone }}</a>@endif</div>
        @if($road && $r->address)
          <div class="small mt-1">{{ icon('map') }} {{ $r->address }} · <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($r->address) }}" target="_blank" rel="noopener">Open map</a></div>
        @elseif(! $road)
          <div class="small muted mt-1">{{ icon('store') }} Customer comes to the shop</div>
        @endif
        @if($r->instructions)<div class="small muted mt-1">Note: {{ $r->instructions }}</div>@endif
        <div class="mt-1"><a class="btn btn-sm {{ $canStep ? 'btn-primary' : '' }}" href="{{ route('supplier.requests.show', $r) }}">{{ $canStep ? 'Open & update steps' : 'Open' }}</a></div>
      </div>
    @empty <p class="small muted">No jobs.</p> @endforelse
  </div>
@endforeach
@endsection
