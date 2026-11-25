@extends('layouts.dash', ['title' => 'Inventory Management', 'nav' => 'inventory'])

{{-- Reference SP.inventory (+ app: items for sale with restock and stock movements) --}}
@php
    $rows = $prods->map(fn ($p) => ['p' => $p, 'b' => inv()->breakdown($p->id, $date)]);
    $sum = fn ($k) => $rows->sum(fn ($x) => $x['b'][$k] ?? 0);
@endphp
@section('content')
@include('supplier._transfers-todo')
<div class="page-head"><div><h1>Inventory Management</h1><p>One shared calculation powers the marketplace, calendar, request review and AI.</p></div>
  <form method="GET" class="row wrap"><label class="small strong" for="dt">Check date</label><input class="input input-sm" type="date" id="dt" name="date" value="{{ $date }}" style="width:auto" data-autosubmit><a class="btn btn-sm" href="{{ route('supplier.calendar') }}">{{ icon('calendar') }}Calendar</a></form></div>
<div class="alert alert-info mb-2">{{ icon('info') }}<div class="small"><b>Available</b> = Total − Reserved (the highest number of units booked at the same time on {{ fmt_date($date) }}, incl. preparation/recovery time) − Damaged − Written off − Missing − Maintenance − Set-aside. Pending requests do not consume stock.</div></div>
@if($conflicts)
  <div class="alert alert-warn mb-2">{{ icon('alert') }}<div><b>Potential conflicts from pending requests:</b><br>@foreach($conflicts as $c)<a href="{{ route('supplier.requests.show', $c['r']) }}">{{ $c['r']->code }}</a> needs {{ $c['b']['requested'] }}× {{ $c['b']['name'] }} on {{ fmt_date($c['b']['date']) }} — only {{ $c['b']['available'] }} available<br>@endforeach</div></div>
@endif
<div class="stats mb-2">
  @include('partials.stat', ['label' => 'Total stock', 'value' => $sum('total'), 'icon' => 'layers', 'color' => 'sky'])
  @include('partials.stat', ['label' => 'Reserved', 'value' => $sum('reserved'), 'sub' => fmt_date($date), 'icon' => 'calendar', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'Available', 'value' => $sum('available'), 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Damaged (to repair)', 'value' => $sum('damaged'), 'sub' => $sum('writtenOff').' written off', 'icon' => 'tool', 'color' => 'red'])
  @include('partials.stat', ['label' => 'Missing', 'value' => $sum('missing'), 'icon' => 'alert', 'color' => 'red'])
  @include('partials.stat', ['label' => 'Maintenance', 'value' => $sum('maintenance'), 'icon' => 'tool', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Set aside', 'value' => $sum('setaside'), 'icon' => 'archive', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Pending demand', 'value' => $sum('pending'), 'sub' => 'not reserved', 'icon' => 'clock', 'color' => 'gold'])
</div>
<div class="card pad-0">
  @if($prods->isEmpty())
    @include('partials.empty', ['emoji' => '📦', 'title' => 'No products yet', 'text' => __('Stock appears here once you add a product.'), 'action' => auth()->user()->orgCan('products.manage') ? '<a class="btn btn-primary" href="'.e(route('supplier.products.create')).'">'.icon('plus').e(__('Add a product')).'</a>' : ''])
  @else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th><th class="num">Total</th><th class="num">Reserved</th><th class="num">Damaged</th><th class="num">Written off</th><th class="num">Missing</th><th class="num">Maint.</th><th class="num">Set aside</th><th class="num">Available</th><th class="num">Pending</th><th></th></tr></thead><tbody>
    @foreach($rows as ['p' => $p, 'b' => $b])
      <tr>
        <td data-label="Item"><div class="row">@include('partials.thumb', ['item' => $p, 'cls' => 'sm'])<div><b>{{ $p->name }}</b>@if($p->isSale()) <span class="badge b-purple">For sale</span>@endif @if($p->isLowStock()) <span class="badge b-red">Low stock</span>@endif<div class="small muted">#{{ $p->id }}</div></div></div></td>
        <td data-label="Total" class="num"><b>{{ $b['total'] }}</b></td>
        <td data-label="Reserved" class="num">@if($b['reserved'])<a href="#" data-modal="res{{ $p->id }}">{{ $b['reserved'] }}</a>@else 0 @endif</td>
        <td data-label="Damaged" class="num">@if($b['damaged'])<div>{{ $b['damaged'] }}<div class="small muted">to repair</div></div>@else 0 @endif</td>
        <td data-label="Written off" class="num">{{ $b['writtenOff'] ?? 0 }}</td>
        <td data-label="Missing" class="num">{{ $b['missing'] }}</td>
        <td data-label="Maint." class="num">{{ $b['maintenance'] }}</td>
        <td data-label="Set aside" class="num">{{ $b['setaside'] }}</td>
        <td data-label="Available" class="num"><span class="avail-pill {{ $b['available'] === 0 ? 'none' : ($b['available'] < $b['total'] * 0.2 ? 'low' : 'ok') }}">{{ $b['available'] }}</span></td>
        <td data-label="Pending" class="num">{!! $b['pending'] ? '<span style="color:'.($b['pending'] > $b['available'] ? 'var(--red)' : '#B45309').';font-weight:700">'.$b['pending'].'</span>' : '0' !!}</td>
        <td><div class="btn-group">
          @if($p->isSale())
            @if($orgLevel)<button class="btn btn-xs" data-modal="sale{{ $p->id }}">Restock</button>@endif
          @else
            <button class="btn btn-xs" data-modal="stock{{ $p->id }}">Update stock</button>
            @if($orgLevel)<button class="btn btn-xs" data-modal="hold{{ $p->id }}">Maintenance / set aside</button>@endif
            @if($orgLevel)<button class="btn btn-xs btn-ghost" data-modal="cond{{ $p->id }}">Damaged / missing</button>@endif
          @endif
        </div></td>
      </tr>
    @endforeach
  </tbody></table></div>
  @endif
</div>

<div class="card mt-3"><h3>Maintenance & set-aside schedule</h3>
  @if($holds->isEmpty())<p class="muted">No active holds.</p>@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th><th>Type</th><th class="num">Qty</th><th>From</th><th>Until</th><th>Note</th><th></th></tr></thead><tbody>
    @foreach($holds as $h)
      <tr><td data-label="Item">{{ $h->product->name }}</td><td data-label="Type"><span class="badge {{ $h->type === 'setaside' ? 'b-purple' : 'b-gold' }}">{{ $h->type === 'setaside' ? 'Set aside' : 'Maintenance' }}</span></td><td data-label="Qty" class="num">{{ $h->qty }}</td><td data-label="From">{{ fmt_date($h->start_date) }}</td><td data-label="Until">{{ $h->end_date ? fmt_date($h->end_date) : 'Until released' }}</td><td data-label="Note">{{ $h->note }}</td>
        <td>@if($orgLevel)<form method="POST" action="{{ route('supplier.inventory.release', $h) }}" data-confirm-title="Release hold?" data-confirm="These units return to available stock." data-confirm-ok="Release">@csrf @method('DELETE')<button class="btn btn-xs">Release</button></form>@endif</td></tr>
    @endforeach
  </tbody></table></div>
  @endif
</div>

@foreach($rows as ['p' => $p, 'b' => $b])
  @if($b['reserved'])
  <div class="modal-backdrop hidden" id="res{{ $p->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Reserved on {{ fmt_date($date) }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div><div class="modal-body">
    @foreach($b['reservedRefs'] as $x)@php($rr = \App\Models\RentalRequest::find($x['request_id']))<div class="sum-line">@if($rr)<a href="{{ route('supplier.requests.show', $rr) }}">{{ $rr->code }}</a>@endif<b>{{ $x['qty'] }} units</b></div>@endforeach
  </div></div></div>
  @endif
  @if($p->isSale())
  <div class="modal-backdrop hidden" id="sale{{ $p->id }}"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Restock · {{ $p->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div><div class="modal-body">
    <p class="small muted">{{ $p->stockOnHand() }} {{ $p->unitWord() }} on hand{{ $p->low_stock_at !== null ? ' · alert at '.$p->low_stock_at : '' }}{{ $p->lead_days ? ' · order '.$p->lead_days.' day(s) ahead' : '' }}</p>
    <form method="POST" action="{{ route('supplier.inventory.restock', $p) }}">@csrf
      <div class="form-grid"><div class="field"><label>Add quantity</label><input class="input" type="number" min="1" name="qty" required></div><div class="field"><label>Note</label><input class="input" name="note" maxlength="255"></div></div>
      <button class="btn btn-primary">{{ icon('plus') }}Restock</button>
    </form>
    <h4 class="mt-3">Stock movements</h4>
    @forelse($p->movements as $m)
      <div class="row between small" style="padding:6px 0;border-bottom:1px solid var(--line)"><div><span class="badge {{ ['sold' => 'b-gray', 'restock' => 'b-green', 'adjust' => 'b-gold'][$m->type] ?? 'b-gray' }}">{{ ucfirst(str_replace('_', ' ', $m->type)) }}</span> {{ $m->note }}</div><b>{{ $m->qty > 0 ? '+' : '' }}{{ $m->qty }}</b></div>
    @empty <p class="small muted">No movements yet.</p> @endforelse
  </div></div></div>
  @else
  <div class="modal-backdrop hidden" id="stock{{ $p->id }}"><div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Update stock · {{ $p->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('supplier.inventory.stock', $p) }}" data-stock-form>@csrf<div class="modal-body">
      <p class="small muted">Each inventory location keeps its own dedicated quantity. The total is always the sum of all locations.</p>
      @foreach($locs as $l)
        @php($peak = collect(inv()->reservedDates($p->id, $l->id))->map(fn ($d) => inv()->demandOn($p->id, $d, \App\Domain\Inventory::RESERVING, null, null, $l->id)['total'])->max() ?? 0)
        <div class="field"><label>{{ $l->name }}</label><input class="input" type="number" min="{{ $peak }}" name="qty[{{ $l->id }}]" value="{{ inv()->stockAt($p->id, $l->id) }}" data-loc><div class="hint">Peak booking allocated here: <b>{{ $peak }}</b></div></div>
      @endforeach
      <div class="sum-line strong"><span>Total inventory</span><span data-tot>{{ $p->total_stock }}</span></div>
    </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>
  </div></div>
  <div class="modal-backdrop hidden" id="hold{{ $p->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Maintenance / set aside · {{ $p->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('supplier.inventory.hold', $p) }}">@csrf<div class="modal-body">
      <div class="field"><label>Type</label><select class="input" name="type"><option value="maintenance">Maintenance / repair</option><option value="setaside">Set aside (display, personal use)</option></select></div>
      <div class="field"><label for="hq{{ $p->id }}">Quantity <span class="muted">· total stock {{ $p->total_stock }}</span></label><input class="input" type="number" id="hq{{ $p->id }}" min="1" max="{{ $p->total_stock }}" name="qty" required value="1">
        <label class="check small mt-1"><input type="checkbox" data-all-units="hq{{ $p->id }}" value="{{ $p->total_stock }}"> All {{ $p->total_stock }} units</label>
        <div class="hint">Available on {{ fmt_date($date) }}: {{ $b['available'] }} of {{ $p->total_stock }}</div></div>
      <div class="form-grid"><div class="field"><label>From</label><input class="input" type="date" name="start_date" required value="{{ today_iso() }}"></div><div class="field"><label>Until (optional)</label><input class="input" type="date" name="end_date"></div></div>
      <div class="field"><label>Note</label><input class="input" name="note" required maxlength="255" placeholder="e.g. Repainting"></div>
    </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Set aside units</button></div></form>
  </div></div>
  <div class="modal-backdrop hidden" id="cond{{ $p->id }}"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Damaged & missing units · {{ $p->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('supplier.inventory.condition', $p) }}">@csrf<div class="modal-body">
      <p class="small muted">Lower these numbers when units are repaired or found — they return to available stock. Raise them to report new losses.</p>
      <div class="form-grid"><div class="field"><label>Damaged units</label><input class="input" type="number" min="0" name="damaged" value="{{ (int) $p->damaged }}"></div><div class="field"><label>Missing units</label><input class="input" type="number" min="0" name="missing" value="{{ (int) $p->missing }}"></div></div>
    </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>
  </div></div>
  @endif
@endforeach
@push('scripts')
<script>
document.querySelectorAll('[data-stock-form]').forEach(function (f) {
  var sum = function () { var t = 0; f.querySelectorAll('[data-loc]').forEach(function (i) { t += Math.max(0, Math.floor(+i.value || 0)); }); f.querySelector('[data-tot]').textContent = t; };
  f.addEventListener('input', sum);
});
</script>
@endpush
@push('scripts')
<script>
// "All N units" (change list C9): fills the quantity with the total stock and locks it while ticked.
document.querySelectorAll('[data-all-units]').forEach(function (cb) {
  var q = document.getElementById(cb.getAttribute('data-all-units')), prev = q.value;
  cb.addEventListener('change', function () { if (cb.checked) { prev = q.value; q.value = cb.value; q.readOnly = true; } else { q.value = prev; q.readOnly = false; } });
  q.addEventListener('input', function () { if (+q.value !== +cb.value) cb.checked = false; });
});
</script>
@endpush
@endsection
