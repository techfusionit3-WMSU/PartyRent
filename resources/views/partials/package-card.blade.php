{{-- Marketplace package card (reference UI.packageCard). --}}
@php
    $dates = $dates ?? \App\Support\RentalPeriod::dates();
    $avail = $dates ? inv()->packageMinAvailable($k->id, $dates) : null;
    $v = \App\Domain\Pricing::packageValue($k);
    $link = route('packages.show', $k);
@endphp
<article class="p-card">
  <a class="cover" href="{{ $link }}"><div class="p-img">@include('partials.img', ['item' => $k])<div class="ribbon">@if($v['savings'] > 0)<span class="badge b-gold plain">Save {{ money_short($v['savings']) }}</span>@endif</div><div style="position:absolute;right:12px;bottom:8px;font-size:2rem">{{ $k->emoji }}</div></div></a>
  <div class="p-body">
    <a class="p-name" href="{{ $link }}">{{ $k->name }}</a>
    <div class="p-sup">{{ $k->supplier->name }} · {{ $k->supplier->city }}</div>
    <div class="small muted">{{ count($k->items) }} items included @if($k->max_guests)· good for up to {{ $k->max_guests }} guests @endif</div>
    <div class="p-meta"><div class="p-price">{{ money_short($k->price) }} <small>/ day</small></div>@if($avail !== null)@if($avail > 0)<span class="small muted">{{ $avail }} available</span>@else<span class="small" style="color:var(--red)">Fully booked</span>@endif @endif</div>
  </div>
</article>
