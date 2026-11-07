@extends('layouts.market', ['title' => $p->name, 'nav' => 'browse', 'alternativesUrl' => route('products.alternatives', $p)])

@section('content')
<div class="crumbs"><a href="{{ route('browse') }}">Browse</a> / <a href="{{ route('browse', ['category' => $cat->id]) }}">{{ $cat->name }}</a> / {{ $p->name }}</div>
<div class="pd">
  <div class="pd-gallery">
    <div class="main">@include('partials.img', ['item' => $p])</div>
    @if(count($p->images ?? []) > 1)<div class="thumbs">@foreach($p->images as $im)<img src="{{ asset('storage/'.$im) }}" alt="Photo of {{ $p->name }}">@endforeach</div>@endif
    <div class="card mt-2"><h3>Availability calendar</h3><div data-calendar data-kind="item" data-id="{{ $p->id }}" data-month="{{ substr($start, 0, 7) }}" data-need="{{ $qty }}"></div></div>
  </div>

  <div data-rentbox data-type="item" data-max-days="{{ setting('maxRentalDays', 30) }}">
    <span class="badge b-purple plain">{{ $cat->name }}</span> <span class="badge b-gray plain">Condition: {{ $p->condition }}</span>
    <h1 class="mt-1">{{ $p->name }}</h1>
    <div class="row wrap small muted"><span class="stars">{{ stars($p->rating) }}</span><span>{{ $p->rating }} · {{ $reviews->count() }} reviews · {{ $p->rent_count }} rentals</span></div>
    <div class="mt-2"><span class="price-lg">{{ money($p->basePrice()) }}</span> <span class="muted">/ {{ $p->unit }}</span></div>
    @if(count($p->tiers) > 1)
      <div class="mt-1"><div class="small strong mb-1">Quantity-based pricing (per day)</div><div class="tiers">
        @foreach(\App\Domain\Pricing::sortedTiers($p) as $t)<div class="tier" data-tier data-min="{{ $t['min'] }}" data-max="{{ $t['max'] }}"><b>{{ money($t['price']) }}</b>{{ $t['min'] }}{{ $t['max'] ? '–'.$t['max'] : '+' }} pcs</div>@endforeach
      </div></div>
    @endif
    <p class="mt-2">{{ $p->description }}</p>
    <div class="row wrap gap-sm mb-2">
      @foreach($p->colors ?? [] as $c)<span class="tag"><span class="swatch" title="{{ $c }}" style="background:{{ color_hex($c) }}"></span> {{ $c }}</span>@endforeach
      @foreach($p->themes ?? [] as $t)<span class="tag">{{ $t }}</span>@endforeach
    </div>

    <form class="card soft" method="POST" action="{{ route('cart.add') }}">
      @csrf
      <input type="hidden" name="type" value="item"><input type="hidden" name="ref_id" value="{{ $p->id }}"><input type="hidden" name="days" id="daysInput" value="{{ json_encode($days) }}">
      <div class="form-grid"><div class="field"><label for="ps">Start date</label><input class="input" type="date" id="ps" min="{{ today_iso() }}" value="{{ $start }}"></div><div class="field"><label for="pe">End date</label><input class="input" type="date" id="pe" min="{{ today_iso() }}" value="{{ $end }}"></div></div>
      <div class="row wrap between">
        <div><div class="label mb-1">Quantity per day</div><div class="qty" id="qbox"><button type="button" data-d aria-label="Decrease">−</button><input type="number" id="pq" min="1" value="{{ $qty }}" aria-label="Quantity"><button type="button" data-i aria-label="Increase">+</button></div></div>
        <div class="right"><div class="small muted">Stock: {{ $p->total_stock }} total</div><div id="availTxt"></div></div>
      </div>
      <details class="mt-2" id="perDay"><summary class="strong" style="cursor:pointer">Need different quantities per day? Customize each date</summary>
        <div class="mt-1" data-scheduler data-type="item" data-ref="{{ $p->id }}" data-days='@json((object) $days)' data-siblings='@json($siblings)' data-sync="#daysInput"></div>
      </details>
      <div id="altBox"></div>
      <div class="sum-line total"><span>Estimated rental</span><span class="price" id="est"></span></div>
      @if($own)
        <div class="alert alert-info">{{ icon('info') }}<div>This is your own listing. <a href="{{ route('supplier.products.edit', $p) }}">Edit in Supplier Mode</a></div></div>
      @else
        <div class="row wrap mt-1"><button class="btn btn-outline btn-lg grow" name="then" value="list" data-rent-submit>{{ icon('cart') }}Add to Rental List</button><button class="btn btn-primary btn-lg grow" name="then" value="checkout" data-rent-submit>Request Rental</button></div>
      @endif
      <p class="small muted mt-1 mb-0">Submitting a request does not reserve the items until the supplier accepts it.</p>
    </form>

    <div class="card mt-2">
      <div class="row"><div class="logo-tile" style="background:{{ $sup->color }}1a">{{ $sup->logo }}</div><div class="grow"><a class="strong" href="{{ route('suppliers.show', $sup) }}">{{ $sup->name }}</a><div class="small muted">{{ $sup->city }} · ★ {{ $sup->rating }}</div></div><a class="btn btn-sm" href="{{ route('suppliers.show', $sup) }}">View Supplier Profile</a></div>
      <hr>
      <dl class="kv">
        <dt>Delivery / pickup</dt><dd>{{ $sup->fulfillmentLabel() }}{{ ! $p->delivery && $sup->fulfillment !== 'pickup' ? ' (this item: pickup only)' : '' }}{{ $sup->fulfillment !== 'pickup' ? ' · delivery fee '.money($sup->delivery_fee) : '' }}</dd>
        <dt>Payment arrangements</dt><dd>{{ collect($sup->arrangements)->map(fn ($a) => \App\Models\Payment::ARR_LABEL[$a])->implode(', ') }}</dd>
        <dt>Payment methods</dt><dd>{{ collect($sup->methods)->map(fn ($m) => \App\Models\Payment::METHOD_LABEL[$m] ?? $m)->implode(', ') }}</dd>
        <dt>Security deposit</dt><dd>{{ $sup->depositLabel() }} (refundable)</dd>
        <dt>Cancellation</dt><dd>{{ $sup->pol('cancellation') }}</dd>
        <dt>Returns</dt><dd>{{ $sup->pol('returns') }}</dd>
      </dl>
    </div>
  </div>
</div>

@if($reviews->isNotEmpty())
<section class="section"><h2>Reviews</h2><div class="grid grid-2">
  @foreach($reviews as $rv)<div class="card card-flat"><div class="row between"><b>{{ $rv->customer->firstName() }}</b><span class="stars">{{ stars($rv->rating) }}</span></div><p class="mb-0 mt-1">{{ $rv->comment }}</p></div>@endforeach
</div></section>
@endif
@if($related->isNotEmpty())@include('market._section', ['title' => 'More from '.$sup->name, 'items' => $related])@endif
@if($similar->isNotEmpty())@include('market._section', ['title' => 'Similar items from other shops', 'items' => $similar])@endif
@endsection
