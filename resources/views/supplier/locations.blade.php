@extends('layouts.dash', ['title' => 'Branches', 'nav' => 'locations'])

{{-- Reference SP.locations --}}
@php($today = $date)
@section('content')
<div class="page-head"><div><h1>Branches</h1><p>The physical places {{ $s->name }} rents from. Customers see one {{ $s->name }} store; each rental is fulfilled by one branch, from that branch's own stock (branches never borrow from each other). Stock is moved between branches with a transfer.</p></div><a class="btn btn-primary" href="{{ route('supplier.locations.create') }}">{{ icon('plus') }}Add branch</a></div>

<div class="grid grid-3 mb-3">
  @foreach($locs as $l)
    @php($o = $open[$l->id] ?? ['pending' => 0, 'booked' => 0])
    <div class="card branch-card {{ $l->active ? '' : 'is-off' }}">
      <div class="row between" style="gap:8px;align-items:flex-start"><b>{{ icon('store', 'width:16px;vertical-align:-3px') }} {{ $l->name }}</b>
        <div class="row wrap" style="gap:4px;justify-content:flex-end">@if($l->is_main)<span class="badge b-purple plain">Main</span>@endif<span class="badge {{ $l->active ? 'b-green' : 'b-gray' }} plain">{{ $l->active ? 'Active' : 'Deactivated' }}</span></div></div>
      <div class="small muted mt-1">{{ $l->address }}</div>
      <div class="small mt-1">{{ $l->contact ?: '—' }}{{ $l->phone ? ' · '.$l->phone : '' }}</div>
      <div class="small mt-1">@if($l->hasPin()){{ icon('map', 'width:13px;vertical-align:-2px') }} Pinned on the map @else<span style="color:#B45309">{{ icon('alert', 'width:13px;vertical-align:-2px') }} No map pin: delivery distance uses its city</span>@endif</div>
      <div class="branch-stats mt-2"><div><b>{{ $prods->sum(fn ($p) => inv()->stockAt($p->id, $l->id)) }}</b><span>units</span></div><div><b>{{ $o['pending'] }}</b><span>to review</span></div><div><b>{{ $o['booked'] }}</b><span>booked</span></div></div>
      <div class="btn-group mt-2"><a class="btn btn-xs" href="{{ route('supplier.locations.edit', $l) }}">{{ icon('edit') }}Edit</a>
        <a class="btn btn-xs" href="{{ route('supplier.requests', ['branch' => $l->id, 'tab' => 'all']) }}">{{ icon('list') }}Rentals</a>
        @unless($l->is_main)<form method="POST" action="{{ route('supplier.locations.active', $l) }}" @if($l->active) data-confirm-title="Deactivate {{ $l->name }}?" data-confirm="Customers can no longer rent from it. Its stock stays recorded and you can reactivate it any time." data-confirm-ok="Deactivate" @endif>@csrf<button class="btn btn-xs btn-ghost">{{ $l->active ? 'Deactivate' : 'Reactivate' }}</button></form>
        <form method="POST" action="{{ route('supplier.locations.delete', $l) }}" data-confirm-title="Remove {{ $l->name }}?" data-confirm="Only empty branches without rentals can be removed." data-confirm-ok="Remove" data-danger>@csrf @method('DELETE')<button class="btn btn-xs btn-ghost">{{ icon('trash') }}Remove</button></form>@endunless</div>
    </div>
  @endforeach
</div>

<div class="card pad-0 mb-3"><div class="card-head" style="padding:16px 18px 0"><h3>Stock by branch</h3><form method="GET" class="row" style="gap:6px"><label class="small muted" for="avDate">Available on</label><input class="input input-sm" type="date" id="avDate" name="date" value="{{ $date }}" min="{{ today_iso() }}" data-autosubmit style="width:auto"></form></div>
  <p class="small muted" style="padding:0 18px">Units each branch still has free on {{ fmt_date($date) }}, after the rentals it fulfils. A package is only available where every one of its items is.</p>
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Product</th>@foreach($locs as $l)<th class="num">{{ $l->name }}</th>@endforeach<th class="num">Total</th><th></th></tr></thead><tbody>
    @foreach($prods as $p)
      <tr><td data-label="Product"><div><b>{{ $p->name }}</b><div class="small muted">#{{ $p->id }}</div></div></td>
        @foreach($locs as $l)@php($st = inv()->stockAt($p->id, $l->id))<td data-label="{{ $l->name }}" class="num">@if($st)<div><b>{{ $st }}</b><div class="small muted">{{ inv()->availableAtLocation($p->id, $l->id, $today) }} free{{ $today === today_iso() ? ' today' : '' }}</div></div>@else<span class="muted">0</span>@endif</td>@endforeach
        <td data-label="Total" class="num"><b>{{ $p->total_stock }}</b></td>
        <td>@if($locs->count() > 1)<button class="btn btn-xs" data-modal="tr{{ $p->id }}">Transfer</button>@endif</td></tr>
    @endforeach
    @foreach($pkgs as $k)
      <tr><td data-label="Package"><div><b>📦 {{ $k->name }}</b><div class="small muted">package · every item from one branch</div></div></td>
        @foreach($locs as $l)<td data-label="{{ $l->name }}" class="num">@php($pa = inv()->packageAvailableOn($k->id, $today, null, null, null, null, $l->id))<b>{{ $pa }}</b><div class="small muted">package{{ $pa === 1 ? '' : 's' }}</div></td>@endforeach
        <td class="num">—</td><td></td></tr>
    @endforeach
  </tbody></table></div>
</div>

<div class="card"><div class="card-head"><h3>Inventory movement records</h3><span class="small muted">Latest 40</span></div>
  @if($moves->isEmpty())@include('partials.empty', ['emoji' => '🗂️', 'title' => 'Nothing here yet', 'text' => 'No movements recorded yet. Transfers, stock counts, write-offs and losses appear here.'])@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>When</th><th>Product</th><th>Movement</th><th class="num">Qty</th><th>From → To</th><th>By</th><th>Note</th></tr></thead><tbody>
    @foreach($moves as $m)
      <tr><td data-label="When"><span class="small">{{ fmt_datetime($m->created_at) }}</span></td><td data-label="Product">{{ $m->product?->name }}</td>
        <td data-label="Movement"><span class="badge b-gray plain">{{ \App\Domain\StockLocations::MOVE_LABEL[$m->type] ?? ucfirst($m->type) }}</span></td>
        <td data-label="Qty" class="num">{{ $m->qty > 0 && $m->type === 'adjust' ? '+' : '' }}{{ $m->qty }}</td>
        <td data-label="From → To">{{ trim(($m->fromLocation?->name ?? '').($m->toLocation ? ' → '.$m->toLocation->name : '')) ?: '—' }}</td>
        <td data-label="By">{{ $m->user?->name ?? 'System' }}</td><td data-label="Note"><span class="small">{{ $m->note }}</span></td></tr>
    @endforeach
  </tbody></table></div>@endif
</div>


@if($locs->count() > 1)
  @foreach($prods as $p)
    <div class="modal-backdrop hidden" id="tr{{ $p->id }}" @if($focus === $p->id) data-autoopen @endif><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Transfer stock · {{ $p->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
      <form method="POST" action="{{ route('supplier.locations.transfer', $p) }}">@csrf<div class="modal-body">
        <p class="small muted">Moves units from one location to another and records the movement. Units already allocated to bookings at the source location cannot be moved.</p>
        <div class="form-grid">
          <div class="field"><label>From</label><select class="input" name="from">@foreach($locs as $l)<option value="{{ $l->id }}">{{ $l->name }} ({{ inv()->stockAt($p->id, $l->id) }})</option>@endforeach</select></div>
          <div class="field"><label>To</label><select class="input" name="to">@foreach($locs as $i => $l)<option value="{{ $l->id }}" @selected($i === 1)>{{ $l->name }} ({{ inv()->stockAt($p->id, $l->id) }})</option>@endforeach</select></div>
          <div class="field"><label>Quantity</label><input class="input" type="number" min="1" name="qty" required value="1"></div>
          <div class="field"><label>Note (optional)</label><input class="input" name="note" maxlength="255" placeholder="e.g. For the Pasig wedding"></div>
        </div>
      </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Transfer</button></div></form>
    </div></div>
  @endforeach
@endif
@push('scripts')<script>document.querySelectorAll('[data-autoopen]').forEach(function (m) { var b = document.querySelector('[data-modal="' + m.id + '"]'); if (b) b.click(); });</script>@endpush
@endsection
