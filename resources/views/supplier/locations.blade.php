@extends('layouts.dash', ['title' => 'Branches', 'nav' => 'locations'])

{{-- Reference SP.locations --}}
@php($today = $date)
@section('content')
<div class="page-head"><div><h1>Branches</h1><p>The places your shop rents from. Customers still see one {{ $s->name }} store; checkout picks the nearest branch that serves them and has everything.</p></div>@if($orgLevel)<a class="btn btn-primary" href="{{ route('supplier.locations.create') }}">{{ icon('plus') }}Add branch</a>@endif</div>

@if($orgLevel)
<form method="POST" action="{{ route('supplier.locations.settings') }}" class="card mb-3">@csrf
  <h3 class="mb-1">How branches serve customers</h3>
  <div class="form-grid">
    <div class="field"><label for="bsKm">Each branch serves customers up to (km)</label><input class="input" id="bsKm" type="number" name="serviceKm" min="1" max="2000" step="1" value="{{ $settings['serviceKm'] }}" required>
      <div class="hint">A branch is never offered to customers farther than this (for delivery or pickup). Set a different distance on a branch to override it.</div></div>
    <div class="field"><label class="check"><input type="checkbox" name="combine" value="1" @checked($settings['combine'])> <span><b>Combine nearby branches</b><span class="small muted" style="display:block">When no single branch has everything, the branch serving the customer borrows the missing items from your other branches nearby. The customer gets one rental and pays no extra; you move the items between branches (a transfer task on the rental).</span></span></label></div>
    <div class="field"><label for="bsCk">Only combine branches within (km of each other)</label><input class="input" id="bsCk" type="number" name="combineKm" min="1" max="500" step="1" value="{{ $settings['combineKm'] }}" required></div>
  </div>
  <button class="btn btn-sm">Save branch settings</button>
</form>
@endif
<div class="grid grid-3 mb-3">
  @foreach($locs as $l)
    @php($o = $open[$l->id] ?? ['pending' => 0, 'booked' => 0])
    <div class="card branch-card {{ $l->active ? '' : 'is-off' }}">
      <div class="branch-card-head"><b>{{ icon('store', 'width:16px;vertical-align:-3px') }} {{ $l->name }}</b>
        <div class="row wrap" style="gap:4px">@if($l->is_main)<span class="badge b-purple plain">Main</span>@endif<span class="badge {{ $l->active ? 'b-green' : 'b-gray' }} plain">{{ $l->active ? 'Open for rentals' : 'Paused' }}</span></div></div>
      <div class="small muted">{{ $l->address }}</div>
      <div class="small">{{ $l->contact ? $l->contact.' · ' : '' }}{{ $l->phone ?: 'No contact number yet' }}</div>
      <div class="small">{{ $l->hours ? $l->hours.' · ' : '' }}Delivers: {{ \App\Domain\Branches::coverageText($l) }}{{ \App\Domain\Branches::coverage($l)['mode'] === 'distance' && $l->service_km === null ? ' (shop default)' : '' }}</div>
      <div class="small">@if($l->hasPin()){{ icon('map', 'width:13px;vertical-align:-2px') }} Pinned on the map @else<a href="{{ route('supplier.locations.edit', $l) }}" style="color:#B45309">{{ icon('alert', 'width:13px;vertical-align:-2px') }} Add a map pin for accurate delivery fees</a>@endif</div>
      <div class="branch-stats"><div><b>{{ $prods->sum(fn ($p) => inv()->stockAt($p->id, $l->id)) }}</b><span>in stock</span></div><div><b>{{ $o['pending'] }}</b><span>new requests</span></div><div><b>{{ $o['booked'] }}</b><span>booked</span></div></div>
      <div class="branch-actions">
        <a class="btn btn-sm" href="{{ route('supplier.locations.edit', $l) }}">{{ icon('edit') }}Edit</a>
        <a class="btn btn-sm" href="{{ route('supplier.requests', ['branch' => $l->id, 'tab' => 'all']) }}">{{ icon('list') }}See rentals</a>
      </div>
      @if(! $l->is_main && $orgLevel)<div class="branch-more">
        <form method="POST" action="{{ route('supplier.locations.active', $l) }}" @if($l->active) data-confirm-title="Pause {{ $l->name }}?" data-confirm="Customers can't rent from this branch while it's paused. Its stock stays recorded, and you can open it again any time." data-confirm-ok="Pause branch" @endif>@csrf<button class="link-btn small">{{ $l->active ? 'Pause this branch' : 'Open this branch again' }}</button></form>
        <form method="POST" action="{{ route('supplier.locations.delete', $l) }}" data-confirm-title="Delete {{ $l->name }}?" data-confirm="This can't be undone. Only a branch with no stock and no rentals can be deleted." data-confirm-ok="Delete branch" data-danger>@csrf @method('DELETE')<button class="link-btn small" style="color:var(--red)">Delete</button></form>
      </div>@endif
    </div>
  @endforeach
</div>

<div class="card pad-0 mb-3"><div class="card-head" style="padding:16px 18px 0"><h3>Stock by branch</h3><form method="GET" class="row" style="gap:6px"><label class="small muted" for="avDate">Available on</label><input class="input input-sm" type="date" id="avDate" name="date" value="{{ $date }}" min="{{ today_iso() }}" data-autosubmit style="width:auto"></form></div>
  <p class="small muted" style="padding:0 18px">How many of each item every branch can still rent out on {{ fmt_date($date) }}. A package can only be rented from a branch that has all of its items.</p>
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th>@foreach($locs as $l)<th class="num">{{ $l->name }}</th>@endforeach<th class="num">Total</th><th></th></tr></thead><tbody>
    @foreach($prods as $p)
      <tr><td data-label="Item"><div><b>{{ $p->name }}</b><div class="small muted">#{{ $p->id }}</div></div></td>
        @foreach($locs as $l)@php($st = inv()->stockAt($p->id, $l->id))<td data-label="{{ $l->name }}" class="num">@if($st)<div><b>{{ $st }}</b><div class="small muted">{{ inv()->availableAtLocation($p->id, $l->id, $today) }} free{{ $today === today_iso() ? ' today' : '' }}</div></div>@else<span class="muted">0</span>@endif</td>@endforeach
        <td data-label="Total" class="num"><b>{{ $p->stockText($p->total_stock) }}</b></td>
        <td>@if($locs->count() > 1)<button class="btn btn-xs" data-modal="tr{{ $p->id }}">Move stock</button>@endif</td></tr>
    @endforeach
    @foreach($pkgs as $k)
      <tr><td data-label="Item"><div><b>📦 {{ $k->name }}</b><div class="small muted">Package</div></div></td>
        @foreach($locs as $l)<td data-label="{{ $l->name }}" class="num">@php($pa = inv()->packageAvailableOn($k->id, $today, null, null, null, null, $l->id))<b>{{ $pa }}</b><div class="small muted">package{{ $pa === 1 ? '' : 's' }}</div></td>@endforeach
        <td class="num">—</td><td></td></tr>
    @endforeach
  </tbody></table></div>
</div>

<div class="card"><div class="card-head"><h3>Stock history</h3><span class="small muted">Latest 40 changes</span></div>
  @if($moves->isEmpty())@include('partials.empty', ['emoji' => '🗂️', 'title' => 'Nothing here yet', 'text' => 'Stock moved between branches, stock counts, damaged and lost items will be listed here.'])@else
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
        <p class="small muted">Move items from one branch to another. Items already booked at a branch stay there until those rentals are done.</p>
        <div class="form-grid">
          <div class="field"><label>From</label><select class="input" name="from">@foreach($locs as $l)<option value="{{ $l->id }}">{{ $l->name }} ({{ inv()->stockAt($p->id, $l->id) }})</option>@endforeach</select></div>
          <div class="field"><label>To</label><select class="input" name="to">@foreach($locs as $i => $l)<option value="{{ $l->id }}" @selected($i === 1)>{{ $l->name }} ({{ inv()->stockAt($p->id, $l->id) }})</option>@endforeach</select></div>
          <div class="field"><label>Quantity</label><input class="input" type="number" min="1" name="qty" required value="1"></div>
          <div class="field"><label>Note (optional)</label><input class="input" name="note" maxlength="255" placeholder="e.g. For the Pasig wedding"></div>
        </div>
      </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Move stock</button></div></form>
    </div></div>
  @endforeach
@endif
@push('scripts')<script>document.querySelectorAll('[data-autoopen]').forEach(function (m) { var b = document.querySelector('[data-modal="' + m.id + '"]'); if (b) b.click(); });</script>@endpush
@endsection
