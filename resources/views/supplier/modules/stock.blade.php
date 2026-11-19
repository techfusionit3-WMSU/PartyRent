@extends('layouts.dash', ['title' => 'Stock Room', 'nav' => 'stock'])

{{-- Inventory module home: what to get ready, what came back, what is out of service. --}}
@section('content')
<div class="page-head"><div><h1>Stock Room</h1><p>{{ $sup->name }} · what to prepare, inspect and fix, {{ fmt_date($T) }}.</p></div>
  <a class="btn btn-primary" href="{{ route('supplier.inventory') }}">{{ icon('layers') }}Open inventory</a></div>
<div class="stats">
  @include('partials.stat', ['label' => 'To prepare', 'value' => count($prep), 'sub' => $reqs->count().' booking'.($reqs->count() === 1 ? '' : 's').' in the next 3 days', 'icon' => 'box', 'color' => 'sky'])
  @include('partials.stat', ['label' => 'To inspect', 'value' => $inspect->count(), 'sub' => $inspect->count() ? 'returns waiting' : 'nothing came back', 'icon' => 'check', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Out of service', 'value' => array_sum(array_map(fn ($o) => $o['maintenance'] + $o['damaged'] + $o['missing'], $out)), 'sub' => 'units in repair, damaged or missing', 'icon' => 'tool', 'color' => 'red', 'href' => route('supplier.damages')])
  @include('partials.stat', ['label' => 'Low stock', 'value' => $low->count(), 'sub' => 'items for sale to restock', 'icon' => 'alert', 'color' => 'pink'])
</div>

<div class="grid grid-2 mt-2">
  <div class="card"><div class="card-head"><h3>Prepare for the next 3 days</h3></div>
    @if(! $prep)<p class="small muted">Nothing goes out in the next 3 days.</p>@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th><th class="right">Qty</th><th>For</th></tr></thead><tbody>
      @foreach($prep as $x)<tr><td data-label="Item"><b>{{ $x['p']->name }}</b></td><td data-label="Qty" class="right"><b>{{ $x['qty'] }}</b></td><td data-label="For"><span class="small">{{ implode(', ', array_unique($x['codes'])) }}</span></td></tr>@endforeach
    </tbody></table></div>@endif
    @if($reqs->isNotEmpty())<div class="small muted mt-1">Bookings: @foreach($reqs as $r)<a href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a> ({{ fmt_day($r->start_date) }})@if(! $loop->last), @endif @endforeach</div>@endif
  </div>
  <div>
    <div class="card mb-2"><div class="card-head"><h3>Returns to inspect</h3></div>
      @forelse($inspect as $r)
        <a href="{{ route('supplier.requests.show', $r) }}" class="row between" style="padding:8px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><span><b>{{ $r->code }}</b> · {{ $r->customer->name }}</span>@include('partials.badges', ['status' => $r->status])</a>
      @empty <p class="small muted">No returns waiting for inspection.</p> @endforelse
    </div>
    <div class="card mb-2"><div class="card-head"><h3>Out of service today</h3></div>
      @forelse($out as $o)
        <div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><b class="small">{{ $o['p']->name }}</b><span class="small">@if($o['maintenance'])<span class="badge b-sky">{{ $o['maintenance'] }} in repair</span> @endif @if($o['damaged'])<span class="badge b-red">{{ $o['damaged'] }} damaged</span> @endif @if($o['missing'])<span class="badge b-gray">{{ $o['missing'] }} missing</span>@endif</span></div>
      @empty <p class="small muted">Every unit is available.</p> @endforelse
    </div>
    @if($low->isNotEmpty())
      <div class="card"><div class="card-head"><h3>Low stock (for sale)</h3></div>
        @foreach($low as $p)<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><b class="small">{{ $p->name }}</b><span class="badge b-pink">{{ $p->stockOnHand() }} left</span></div>@endforeach
      </div>
    @endif
  </div>
</div>
@endsection
