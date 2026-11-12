@extends('layouts.market', ['title' => $s->name, 'nav' => 'suppliers'])

@section('content')
<div class="crumbs"><a href="{{ route('suppliers.index') }}">Rental Partners</a> / {{ $s->name }}</div>
@if($s->status !== 'approved')<div class="alert alert-warn mb-2">{{ icon('clock') }}<div>Preview only — your shop is <b>{{ $s->status }}</b> and is not visible to customers yet.</div></div>@endif
<div class="card" style="background:linear-gradient(135deg,{{ $s->color }}14,#fff)">
  <div class="row wrap top">
    <div class="logo-tile" style="width:84px;height:84px;font-size:2.6rem;background:#fff;box-shadow:var(--shadow)">@if($s->logo_path)<img src="{{ asset('storage/'.$s->logo_path) }}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:14px">@else{{ $s->logo }}@endif</div>
    <div class="grow"><h1 class="mb-0">{{ $s->name }}@if($s->isVerifiedBusiness()) <span class="badge b-green" style="vertical-align:middle">{{ icon('check') }}Verified Business</span>@endif</h1><div class="muted">{{ $s->city }} · <span class="stars">{{ stars($s->rating) }}</span> <a href="{{ route('suppliers.show', [$s, 'tab' => 'reviews']) }}#reviews">{{ rating_text($s) }}</a></div><p class="mt-1 mb-1">{{ $s->description }}</p>
      @php($myCity = customer_city())
      <div class="row wrap gap-sm"><span class="badge b-teal">{{ icon('truck', 'width:12px') }} {{ $s->fulfillmentLabel() }}{{ $s->offersDelivery() ? ' · '.$s->deliverySummary() : '' }}</span>
        @if($myCity && $s->offersDelivery())
          @php($reach = $s->deliveryTo($myCity))
          @include('partials.free-ship-badge', ['s' => $s])
          @if($reach['ok'])<span class="badge b-green">Delivers to {{ \App\Domain\Locations::name($myCity) }} · {{ money_short($reach['fee']) }}</span>
          @elseif($s->fulfillment === 'both')<span class="badge b-gold">Pickup only in your area</span>
          @else<span class="badge b-red">Doesn't deliver to {{ \App\Domain\Locations::name($myCity) }}</span>@endif
        @endif
        @foreach($s->methods as $m)<span class="badge b-gray plain">{{ \App\Models\Payment::METHOD_LABEL[$m] ?? $m }}</span>@endforeach</div>
    </div>
    <dl class="kv" style="min-width:260px;max-width:340px"><dt>{{ $s->isPublicLocation() ? 'Address' : 'Location' }}</dt><dd>{{ $own ? $s->address : $s->publicAddress() }}</dd><dt>Contact</dt><dd>{{ $s->phone }}<br>{{ $s->email }}</dd><dt>Hours</dt><dd>{{ $s->hours }}</dd></dl>
  </div>
</div>
<div class="card mt-2"><h3>{{ icon('map', 'width:18px;vertical-align:-3px') }} Location</h3>@include('partials.supplier-location', ['s' => $s, 'reveal' => $own])</div>

<div class="mt-3"><div class="tabs" role="tablist">
  @foreach([['items', 'Products', $items->count()], ['packages', 'Packages', $pkgs->count()], ['policies', 'Policies & payments', null], ['reviews', 'Reviews', $reviews->count()]] as [$k, $l, $c])
    <a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('suppliers.show', ['supplier' => $s->id, 'tab' => $k]) }}">{{ $l }}@if($c !== null)<span class="count">{{ $c }}</span>@endif</a>
  @endforeach
</div></div>

@if($tab === 'packages')
  @if($pkgs->isEmpty())@include('partials.empty', ['emoji' => '📦', 'title' => 'No packages yet'])@else<div class="product-grid">@foreach($pkgs as $k)@include('partials.package-card', ['k' => $k])@endforeach</div>@endif
@elseif($tab === 'policies')
  <div class="grid grid-2">
    <div class="card"><h3>Payment arrangements</h3><dl class="kv">
      <dt>Accepted methods</dt><dd>{{ collect($s->methods)->map(fn ($m) => \App\Models\Payment::METHOD_LABEL[$m] ?? $m)->implode(', ') }}</dd>
      <dt>Arrangements</dt><dd>{{ collect($s->arrangements)->map(fn ($a) => \App\Models\Payment::ARR_LABEL[$a])->implode(', ') }}</dd>
      <dt>Reservation fee</dt><dd>{{ $s->pol('reservationFee') ? money($s->pol('reservationFee')).' — '.($s->pol('reservationCredited') ? 'credited toward your down payment' : 'charged in addition to the rental') : 'None' }}</dd>
      <dt>Down payment</dt><dd>{{ $s->pol('downValue') ? ($s->pol('downType') === 'percent' ? $s->pol('downValue').'% of the rental amount' : money($s->pol('downValue'))) : 'Not required' }}</dd>
      <dt>Installments</dt><dd>{{ in_array('installment', $s->arrangements, true) ? $s->pol('installments').' payments before the rental date' : 'Not offered' }}</dd>
      <dt>Security deposit</dt><dd>{{ $s->depositLabel() }} (refundable)</dd>
      @if($s->offersDelivery())<dt>Delivery areas</dt><dd>{!! collect($s->deliveryAreas())->map(fn ($a) => e($a))->implode('<br>') !!}@if($s->deliverySettings()['freeOver'] > 0)<div class="small muted">Free delivery when items total {{ money($s->deliverySettings()['freeOver']) }} or more.</div>@endif</dd>@endif
    </dl></div>
    <div class="card"><h3>Business policies</h3><p><b>Cancellation:</b> {{ $s->pol('cancellation') }}</p><p><b>Returns:</b> {{ $s->pol('returns') }}</p><p class="mb-0"><b>Rental conditions:</b> {{ $s->pol('conditions') }}</p></div>
  </div>
@elseif($tab === 'reviews')
  @include('partials.reviews-block', ['reviews' => $reviews, 'showItem' => true, 'emptyText' => 'Reviews appear here after customers complete a rental with this shop.'])
@else
  @php($cats = $items->pluck('category')->filter()->unique('id'))
  <form class="row wrap mb-2" method="GET"><input type="hidden" name="tab" value="items"><select class="input input-sm" name="category" style="width:auto" aria-label="Category" data-autosubmit><option value="">All categories</option>@foreach($cats as $c)<option value="{{ $c->id }}" @selected($catFilter === $c->id)>{{ $c->name }}</option>@endforeach</select></form>
  <div class="product-grid">@foreach($items->when($catFilter, fn ($c) => $c->where('category_id', $catFilter)) as $p)@include('partials.product-card', ['p' => $p])@endforeach</div>
@endif
@endsection
