@php
    $dates = $dates ?? [];
    $avail = inv()->minAvailable($p->id, $dates);
    $link = route('products.show', array_filter(['product' => $p->id, 'start' => $dates[0] ?? null, 'end' => $dates ? end($dates) : null]));
@endphp
<article class="p-card">
  @if(! empty($compare))<label class="p-compare"><input type="checkbox" name="compare[]" value="{{ $p->id }}" form="compareForm"> Compare</label>@endif
  <a class="cover" href="{{ $link }}"><div class="p-img">@include('partials.img', ['item' => $p])<div class="ribbon">
    @if($avail <= 0)<span class="badge b-red plain">Fully booked{{ $dates ? '' : ' today' }}</span>
    @elseif($p->isNew())<span class="badge b-purple plain">New</span>
    @elseif(count($p->tiers) > 1)<span class="badge b-gold plain">Bulk discount</span>@endif
  </div></div></a>
  <div class="p-body">
    <a class="p-name" href="{{ $link }}">{{ $p->name }}</a>
    <div class="p-sup">{{ icon('store', 'width:13px;height:13px') }}{{ $p->supplier->name }}</div>
    <div class="p-sup">{{ icon('map', 'width:13px;height:13px') }}{{ $p->supplier->city }} · <span class="stars">★</span> {{ rating_text($p) }}</div>
    @php($myCity = customer_city())
    @if($myCity)
      @php($canDeliver = $p->delivery && $p->supplier->deliversTo($myCity))
      <div class="p-sup" style="color:{{ $canDeliver ? 'var(--green)' : '#B45309' }}">{{ icon('truck', 'width:13px;height:13px') }}{{ $canDeliver ? 'Delivers to '.\App\Domain\Locations::name($myCity) : ($p->delivery && $p->supplier->offersDelivery() ? 'Pickup only in your area' : 'Pickup only') }}</div>
    @endif
    <div class="p-meta"><div class="p-price">{{ str_replace('.00', '', money($p->basePrice())) }} <small>/ {{ $p->unitWord() }}</small></div><span class="small {{ $avail > 0 ? 'muted' : '' }}" style="{{ $avail > 0 ? '' : 'color:var(--red)' }}">{{ $avail }} avail.</span></div>
  </div>
</article>
