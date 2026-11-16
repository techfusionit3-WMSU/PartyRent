{{-- Marketplace product card (reference UI.productCard). Stock is only shown for a selected rental period. --}}
@php
    $dates = $dates ?? \App\Support\RentalPeriod::dates();
    $avail = $dates ? inv()->minAvailable($p->id, $dates) : null;
    $link = route('products.show', array_filter(['product' => $p->id, 'start' => $dates[0] ?? null, 'end' => $dates ? end($dates) : null]));
    $maxDays = (int) ($p->max_rental_days ?? 0);
@endphp
<article class="p-card">
  @if(! empty($showCompare))<label class="p-compare"><input type="checkbox" name="compare[]" value="{{ $p->id }}" form="compareForm" @checked(! empty($compareOn))> Compare</label>@endif
  <a class="cover" href="{{ $link }}"><div class="p-img">@include('partials.img', ['item' => $p])<div class="ribbon">
    @if($avail !== null && $avail <= 0)<span class="badge b-red plain">Fully booked</span>
    @elseif($p->isNew())<span class="badge b-purple plain">New</span>
    @elseif(count($p->tiers ?? []) > 1)<span class="badge b-gold plain">Bulk discount</span>@endif
  </div></div></a>
  <div class="p-body">
    <a class="p-name" href="{{ $link }}">{{ $p->name }}</a>
    <div class="p-sup">{{ icon('store', 'width:13px;height:13px') }}{{ $p->supplier->name }}</div>
    <div class="p-sup">{{ icon('map', 'width:13px;height:13px') }}{{ $p->supplier->city }} · <span class="stars">★</span> {{ $p->rating ? rtrim(rtrim(number_format($p->rating, 1), '0'), '.') : '—' }}</div>
    <div class="p-sup p-dur">{{ icon('clock', 'width:13px;height:13px') }}{{ $maxDays ? 'Maximum Rental: '.$maxDays.' Day'.($maxDays > 1 ? 's' : '') : 'No Rental Duration Limit' }}</div>
    <div class="p-meta"><div class="p-price">{{ str_replace('.00', '', money($p->basePrice())) }} <small>/ {{ trim(explode('/', $p->unit)[0]) }}</small></div>@if($avail !== null)<span class="small {{ $avail > 0 ? 'muted' : '' }}" style="{{ $avail > 0 ? '' : 'color:var(--red)' }}" title="Available for {{ fmt_range($dates[0], end($dates)) }}">{{ $avail }} available</span>@endif</div>
  </div>
</article>
