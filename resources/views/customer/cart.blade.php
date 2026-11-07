@extends('layouts.market', ['title' => 'Rental List', 'nav' => 'rentals'])

@section('content')
@if(! $groups)
  <div class="page-head"><div><h1>Rental List</h1></div></div>
  <div class="card">@include('partials.empty', ['emoji' => '🛒', 'title' => 'Your rental list is empty', 'text' => 'Add items or packages, then set the dates and quantities for each day.', 'action' => '<div class="row wrap" style="justify-content:center"><a class="btn btn-primary" href="'.route('browse').'">Browse supplies</a><a class="btn" href="'.route('ai').'">'.icon('sparkle').'Plan with AI</a></div>'])</div>
@else
<div class="page-head"><div><h1>Rental List</h1><p>Set a different quantity for each date if you need to. Availability is checked day by day and changes save automatically.</p></div>
  <div class="row">@if(count($groups) > 1)<a class="btn btn-sm" href="{{ route('cart', ['compare' => 1]) }}">{{ icon('layers') }}Compare suppliers</a>@endif<a class="btn btn-sm btn-ghost" href="{{ route('browse') }}">{{ icon('plus') }}Add more items</a></div>
</div>

@if($compare && count($groups) > 1)
  <div class="card mb-2"><div class="card-head"><h3>Compare suppliers in your list</h3><a class="btn btn-xs btn-ghost" href="{{ route('cart') }}">Close</a></div>
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Supplier</th><th class="num">Items</th><th>Delivery</th><th>Payment options</th><th class="num">Deposit</th><th>Availability</th></tr></thead><tbody>
      @foreach($groups as $g)
        <tr><td data-label="Supplier"><b>{{ $g['supplier']->name }}</b><div class="small muted">{{ $g['supplier']->city }} · ★ {{ $g['supplier']->rating }}</div></td><td data-label="Items" class="num">{{ money($g['quote']['itemsSubtotal']) }}</td><td data-label="Delivery">{{ $g['supplier']->fulfillmentLabel() }}@if($g['supplier']->fulfillment !== 'pickup')<div class="small muted">{{ money($g['supplier']->delivery_fee) }}</div>@endif</td><td data-label="Payment">{!! collect($g['supplier']->arrangements)->map(fn ($a) => e(\App\Models\Payment::ARR_LABEL[$a]))->implode('<br>') !!}</td><td data-label="Deposit" class="num">{{ money($g['compareQuote']['deposit']) }}</td><td data-label="Availability">@if($g['check']['ok'])<span class="badge b-green">All dates OK</span>@else<span class="badge b-red">Conflicts</span>@endif</td></tr>
      @endforeach
    </tbody></table></div>
  </div>
@endif

<div class="grid grid-side">
  <div data-reload-on-save>
    @foreach($groups as $g)
      @php($s = $g['supplier'])
      <div class="card pad-0 cart-group">
        <div class="cg-head">
          <div class="row"><div class="logo-tile" style="width:42px;height:42px;font-size:1.3rem;background:{{ $s->color }}1a">{{ $s->logo }}</div><div><a class="strong" href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a><div class="small muted">{{ $s->city }} · {{ $s->fulfillmentLabel() }}</div></div></div>
          <div class="row"><div class="right"><div class="small muted">Subtotal</div><b class="price">{{ money($g['quote']['itemsSubtotal']) }}</b></div>
            @if($g['check']['ok'])<a class="btn btn-primary btn-sm" href="{{ route('checkout', $s) }}">Request from this supplier</a>@else<button class="btn btn-primary btn-sm" disabled>Request from this supplier</button>@endif
          </div>
        </div>
        @if(! $g['check']['ok'])<div class="alert alert-danger" style="margin:12px 18px 0">{{ icon('alert') }}<div>Some dates exceed available stock. Lower the highlighted quantities or remove those dates.</div></div>@endif
        @foreach($g['lines'] as $l)
          @php($item = $l['type'] === 'package' ? inv()->package($l['ref_id']) : inv()->product($l['ref_id']))
          @continue(! $item)
          @php($ds = array_keys($l['days']))
          @php(sort($ds))
          @php($lineId = (int) substr($l['id'], 1))
          @php($siblings = collect($g['lines'])->reject(fn ($x) => $x['id'] === $l['id'])->values()->all())
          <div class="cart-line">
            <div class="cl-top">@include('partials.thumb', ['item' => $item])<div class="grow"><div class="row between top"><div><a class="strong" href="{{ $l['type'] === 'package' ? route('packages.show', $item) : route('products.show', $item) }}">{{ $item->name }}</a>@if($l['type'] === 'package') <span class="badge b-pink plain">Package</span>@endif
              <div class="small muted">{{ $ds ? count($ds).' date'.(count($ds) > 1 ? 's' : '').' · '.fmt_range($ds[0], end($ds)) : 'No dates' }}{{ $l['type'] === 'item' && count($item->tiers) > 1 ? ' · tiered pricing applies per day' : '' }}</div></div>
              <form method="POST" action="{{ route('cart.remove', $lineId) }}" data-confirm="Remove this item and its schedule from your rental list?" data-confirm-title="Remove item?" data-confirm-ok="Remove" data-danger>@csrf @method('DELETE')<button class="btn btn-xs btn-ghost">{{ icon('trash') }}Remove</button></form>
            </div></div></div>
            <div class="mt-1" data-scheduler data-type="{{ $l['type'] }}" data-ref="{{ $l['ref_id'] }}" data-days='@json((object) $l['days'])' data-siblings='@json($siblings)' data-save="{{ route('cart.update', $lineId) }}"></div>
          </div>
        @endforeach
      </div>
    @endforeach
  </div>
  <aside class="card sticky-side">
    <h3>Summary</h3>
    @foreach($groups as $g)<div class="sum-line"><span>{{ $g['supplier']->name }}</span><span>{{ money($g['quote']['itemsSubtotal']) }}</span></div>@endforeach
    <div class="sum-line total"><span>Items total</span><span class="price">{{ money(collect($groups)->sum(fn ($g) => $g['quote']['itemsSubtotal'])) }}</span></div>
    <p class="small muted">Delivery fees, deposits and payment schedules are shown at checkout. Each supplier receives its own request.</p>
    <div class="alert alert-info">{{ icon('info') }}<div class="small">Items in your list are <b>not reserved</b>. Stock is only reserved when a supplier accepts your request.</div></div>
  </aside>
</div>
@endif
@endsection
