@extends('layouts.dash', ['title' => 'Inventory Locations', 'nav' => 'locations'])

{{-- Reference SP.locations --}}
@php($today = today_iso())
@section('content')
<div class="page-head"><div><h1>Inventory Locations</h1><p>Warehouses, branches and storage facilities. Each location keeps its own dedicated stock; the total inventory is the sum of all locations and stock is never duplicated.</p></div><button class="btn btn-primary" data-modal="locNew">{{ icon('plus') }}Add location</button></div>

<div class="grid grid-3 mb-3">
  @foreach($locs as $l)
    <div class="card"><div class="row between"><b>{{ icon('map', 'width:16px;vertical-align:-3px') }} {{ $l->name }}</b><span class="badge b-purple">{{ $prods->sum(fn ($p) => inv()->stockAt($p->id, $l->id)) }} units</span></div>
      <div class="small muted mt-1">{{ $l->address }}</div><div class="small mt-1">{{ $l->contact ?: '—' }}{{ $l->phone ? ' · '.$l->phone : '' }}</div>
      <div class="btn-group mt-2"><button class="btn btn-xs" data-modal="loc{{ $l->id }}">{{ icon('edit') }}Edit</button>
        <form method="POST" action="{{ route('supplier.locations.delete', $l) }}" data-confirm-title="Remove {{ $l->name }}?" data-confirm="Only empty locations without allocated bookings can be removed." data-confirm-ok="Remove" data-danger>@csrf @method('DELETE')<button class="btn btn-xs btn-ghost">{{ icon('trash') }}Remove</button></form></div>
    </div>
  @endforeach
</div>

<div class="card pad-0 mb-3"><div class="card-head" style="padding:16px 18px 0"><h3>Stock by location</h3><span class="small muted">Available today per location (after bookings allocated there)</span></div>
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Product</th>@foreach($locs as $l)<th class="num">{{ $l->name }}</th>@endforeach<th class="num">Total</th><th></th></tr></thead><tbody>
    @foreach($prods as $p)
      <tr><td data-label="Product"><div><b>{{ $p->name }}</b><div class="small muted">#{{ $p->id }}</div></div></td>
        @foreach($locs as $l)@php($st = inv()->stockAt($p->id, $l->id))<td data-label="{{ $l->name }}" class="num">@if($st)<div><b>{{ $st }}</b><div class="small muted">{{ inv()->availableAtLocation($p->id, $l->id, $today) }} free today</div></div>@else<span class="muted">0</span>@endif</td>@endforeach
        <td data-label="Total" class="num"><b>{{ $p->total_stock }}</b></td>
        <td>@if($locs->count() > 1)<button class="btn btn-xs" data-modal="tr{{ $p->id }}">Transfer</button>@endif</td></tr>
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

@foreach($locs->prepend(null) as $l)
  <div class="modal-backdrop hidden" id="{{ $l ? 'loc'.$l->id : 'locNew' }}"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>{{ $l ? 'Edit location' : 'Add inventory location' }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ $l ? route('supplier.locations.update', $l) : route('supplier.locations.store') }}">@csrf @if($l) @method('PUT') @endif<div class="modal-body">
      <div class="field"><label>Location name</label><input class="input" name="name" required minlength="2" value="{{ $l?->name }}" placeholder="e.g. Main Warehouse, Branch A"></div>
      <div class="field"><label>Complete address</label><input class="input" name="address" required minlength="8" value="{{ $l?->address }}"></div>
      <div class="form-grid"><div class="field"><label>Contact person / representative</label><input class="input" name="contact" value="{{ $l?->contact }}"></div><div class="field"><label>Contact number</label><input class="input" name="phone" value="{{ $l?->phone }}"></div></div>
    </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>
  </div></div>
@endforeach
@php($locs->shift())
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
