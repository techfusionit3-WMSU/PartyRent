@extends('layouts.dash', ['title' => 'Inventory', 'nav' => 'inventory'])

@section('content')
<div class="page-head"><div><h1>Inventory</h1><p>Available = total − reserved − damaged − missing − maintenance − set-aside. Changes that would break accepted reservations are blocked.</p></div>
  <form method="GET" class="row"><label class="small muted" for="invDate">Stock on</label><input class="input input-sm" type="date" id="invDate" name="date" value="{{ $date }}" data-autosubmit></form>
</div>
<div class="card pad-0">
  @if($prods->isEmpty())
    @include('partials.empty', ['emoji' => '📦', 'title' => 'No products yet', 'text' => 'Add products to track their inventory.'])
  @else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th><th class="num">Total</th><th class="num">Reserved</th><th class="num">Pending</th><th class="num">Damaged</th><th class="num">Missing</th><th class="num">Maint.</th><th class="num">Set aside</th><th class="num">Available</th><th></th></tr></thead><tbody>
    @foreach($prods as $p)
      @php($b = inv()->breakdown($p->id, $date))
      <tr>
        <td data-label="Item"><b>{{ $p->name }}</b>@if($p->isSale()) <span class="badge b-purple">For sale</span>@endif @if($p->isLowStock()) <span class="badge b-red">Low stock</span>@endif @if($p->holds->isNotEmpty())<div class="small muted">{{ $p->holds->count() }} hold(s)</div>@endif</td>
        <td data-label="Total" class="num">{{ $b['total'] }}</td><td data-label="Reserved" class="num">{{ $b['reserved'] }}</td><td data-label="Pending" class="num">{!! $b['pending'] ? '<span style="color:#B45309">'.$b['pending'].'</span>' : '0' !!}</td>
        @if($p->isSale())<td colspan="4" class="small muted" data-label="Stock">Stock on hand · accepted orders hold {{ $b['reserved'] }}</td>@else<td data-label="Damaged" class="num">{{ $b['damaged'] }}</td><td data-label="Missing" class="num">{{ $b['missing'] }}</td><td data-label="Maintenance" class="num">{{ $b['maintenance'] }}</td><td data-label="Set aside" class="num">{{ $b['setaside'] }}</td>@endif
        <td data-label="Available" class="num"><b>{{ $b['available'] }}</b></td>
        <td><button class="btn btn-xs" data-modal="inv{{ $p->id }}">{{ $p->isSale() ? 'Restock' : 'Manage' }}</button></td>
      </tr>
    @endforeach
  </tbody></table></div>
  @endif
</div>

@foreach($prods as $p)
<div class="modal-backdrop hidden" id="inv{{ $p->id }}"><div class="modal lg" role="dialog" aria-modal="true"><div class="modal-head"><h3>{{ $p->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <div class="modal-body">
    @if($p->isSale())
    <p class="small muted">{{ $p->stockOnHand() }} {{ $p->unitWord() }} on hand{{ $p->low_stock_at !== null ? ' · alert at '.$p->low_stock_at : '' }}{{ $p->lead_days ? ' · order '.$p->lead_days.' day(s) ahead' : '' }}{{ $p->perishable && $p->shelf_life_days ? ' · keeps '.$p->shelf_life_days.' day(s)' : '' }}. Stock goes down for good when an order is released.</p>
    <form method="POST" action="{{ route('supplier.inventory.restock', $p) }}" class="card card-flat" style="padding:14px">@csrf
      <div class="form-grid"><div class="field"><label class="small">Add quantity</label><input class="input input-sm" type="number" min="1" name="qty" required></div><div class="field"><label class="small">Note</label><input class="input input-sm" name="note" maxlength="255" placeholder="e.g. Delivery from supplier"></div></div>
      <button class="btn btn-sm btn-primary">{{ icon('plus') }}Restock</button>
    </form>
    <h4 class="mt-3">Stock movements</h4>
    @forelse($p->movements as $m)
      <div class="row between small" style="padding:6px 0;border-bottom:1px solid var(--line)"><div><span class="badge {{ ['sold' => 'b-gray', 'restock' => 'b-green', 'adjust' => 'b-gold'][$m->type] ?? 'b-gray' }}">{{ ucfirst($m->type) }}</span> {{ $m->note }}<div class="muted">{{ $m->created_at ? fmt_date($m->created_at->toDateString()) : '' }}</div></div><div class="num"><b>{{ $m->qty > 0 ? '+' : '' }}{{ $m->qty }}</b><div class="muted">→ {{ $m->balance }}</div></div></div>
    @empty <p class="small muted">No movements yet.</p> @endforelse
    @else
    <h4>Condition counts</h4>
    <p class="small muted">Repaired or found units go back to available stock when you lower these counts.</p>
    <div class="form-grid">
      @foreach(['damaged' => 'Damaged units', 'missing' => 'Missing units'] as $field => $label)
        <form method="POST" action="{{ route('supplier.inventory.condition', $p) }}" class="row" style="align-items:end;gap:8px">@csrf<input type="hidden" name="field" value="{{ $field }}"><div class="field grow mb-0"><label class="small">{{ $label }}</label><input class="input input-sm" type="number" min="0" name="value" value="{{ $p->{$field} }}"></div><button class="btn btn-sm">Save</button></form>
      @endforeach
    </div>
    <h4 class="mt-3">Maintenance & set-aside holds</h4>
    @forelse($p->holds as $h)
      <div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><div><span class="badge {{ $h->type === 'setaside' ? 'b-purple' : 'b-gold' }}">{{ $h->type === 'setaside' ? 'Set aside' : 'Maintenance' }}</span> <b>{{ $h->qty }}</b> units · {{ fmt_date($h->start_date) }} – {{ $h->end_date ? fmt_date($h->end_date) : 'until released' }}<div class="small muted">{{ $h->note }}</div></div>
        <form method="POST" action="{{ route('supplier.inventory.release', $h) }}">@csrf @method('DELETE')<button class="btn btn-xs btn-ghost">Release</button></form></div>
    @empty <p class="small muted">No holds.</p> @endforelse
    <form method="POST" action="{{ route('supplier.inventory.hold', $p) }}" class="card card-flat mt-2" style="padding:14px">@csrf
      <div class="form-grid">
        <div class="field"><label class="small">Type</label><select class="input input-sm" name="type"><option value="maintenance">Maintenance / repair</option><option value="setaside">Set aside (not for rent)</option></select></div>
        <div class="field"><label class="small">Quantity</label><input class="input input-sm" type="number" min="1" name="qty" required></div>
        <div class="field"><label class="small">From</label><input class="input input-sm" type="date" name="start_date" value="{{ today_iso() }}" required></div>
        <div class="field"><label class="small">Until (optional)</label><input class="input input-sm" type="date" name="end_date"></div>
        <div class="field full"><label class="small">Note</label><input class="input input-sm" name="note" maxlength="255"></div>
      </div>
      <button class="btn btn-sm btn-primary">{{ icon('plus') }}Add hold</button>
    </form>
    @endif
  </div>
</div></div>
@endforeach
@endsection
