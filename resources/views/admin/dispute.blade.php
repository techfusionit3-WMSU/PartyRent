@extends('layouts.dash', ['title' => 'Damage dispute '.$r->code, 'nav' => 'disputes'])

@section('content')
@php
    $x = $r->inspection;
    $rev = $x['review'];
    $items = collect($x['items'])->keyBy(fn ($i) => (string) $i['productId']);
    $open = $rev['status'] === 'disputed';
@endphp
<div class="crumbs"><a href="{{ route('admin.disputes') }}">Damage Disputes</a> / {{ $r->code }}</div>
<div class="page-head"><div><h1>Damage dispute · {{ $r->code }}</h1><p>{{ $r->supplier->name }} (shop) and {{ $r->customer->name }} (customer). @if($ds = $r->dates())Rental {{ fmt_range(min($ds), max($ds)) }}.@endif</p></div></div>
<div class="grid grid-side">
  <div class="stack">
    @include('partials.damage-outcomes', ['r' => $r, 'x' => $x, 'viewer' => 'admin'])
  </div>
  <aside class="card sticky-side"><h3>{{ $open ? 'Decision' : 'Decided' }}</h3>
    @if($open)
      <form method="POST" action="{{ route('admin.disputes.resolve', $r) }}">@csrf
        @foreach($rev['decisions'] as $pid => $d)
          @continue($d['state'] !== 'disputed')
          @php($it = $items[$pid])
          @php($p = inv()->product($pid))
          <div class="card card-flat mb-1" style="padding:12px">
            <b>{{ $p?->name }}</b>
            <div class="small muted">Shop charged {{ money($it['total']) }} · replacement value {{ money($it['value']) }}</div>
            <div class="small muted">Purchase record: @if($p?->purchase_price){{ money($p->purchase_price) }}{{ $p->purchased_on ? ', bought '.fmt_date($p->purchased_on->format('Y-m-d')) : '' }}@else none @endif
              @if($p?->purchase_receipt) · <a href="{{ route('admin.receipts.show', $p) }}" target="_blank" rel="noopener">View receipt</a>@endif</div>
            @if($p?->value_reason)<div class="small muted">Why the value is above the price paid: {{ $p->value_reason }}</div>@endif
            <div class="field mt-1"><label class="small" for="amt{{ $pid }}">Final amount (₱)</label><input class="input input-sm" id="amt{{ $pid }}" type="number" min="0" max="{{ $it['total'] }}" step="0.01" name="decisions[{{ $pid }}][amount]" value="{{ old('decisions.'.$pid.'.amount', $it['total']) }}" required><div class="hint">0 cancels the charge. It cannot be more than the shop charged.</div></div>
            <div class="field"><label class="small" for="note{{ $pid }}">Reason shown to both sides</label><textarea class="input" id="note{{ $pid }}" name="decisions[{{ $pid }}][note]" rows="2" maxlength="1000">{{ old('decisions.'.$pid.'.note') }}</textarea></div>
          </div>
        @endforeach
        <button class="btn btn-primary btn-block" data-confirm="Set the final compensation? Both sides are notified and the shop can then settle the deposit." data-confirm-title="Decide the dispute?">Save decision</button>
      </form>
    @else
      <p>Final compensation <b>{{ money($x['compensation']) }}</b> (the shop charged {{ money($x['assessed'] ?? $x['compensation']) }}).</p>
    @endif
  </aside>
</div>
@endsection
