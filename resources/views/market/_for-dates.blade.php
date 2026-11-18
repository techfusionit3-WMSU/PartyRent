{{-- Suggestions for the customer's rental dates (after the post-login date prompt or the date bar):
     only items and packages that are free on every selected day. Rendered at #results so both land here. --}}
@php($range = fmt_range($dates[0], end($dates)))
<section class="section" id="results">
  <div class="section-title"><div><h2>{{ icon('calendar', 'width:20px;vertical-align:-3px') }} Available for your dates</h2><p class="muted small mb-0">{{ $range }}{{ $st['qty'] ? ' · at least '.$st['qty'].' per day' : '' }} · {{ $forDates['count'] }} {{ $forDates['count'] === 1 ? 'item is' : 'items are' }} free on every day</p></div>
    @if($forDates['count'] > $forDates['items']->count())<a class="small strong" href="{{ $qs(['avail' => 1]) }}#results">See all {{ $forDates['count'] }}</a>@endif</div>
  @if($forDates['items']->isEmpty())
    <div class="card card-flat soft center" style="padding:20px"><div class="strong">Nothing is free on all of {{ $range }}</div><p class="muted small mb-1">Try fewer days or a different date. Everything is still listed below with how many are left.</p><a class="btn btn-sm" href="#browseBar">{{ icon('calendar') }}Change dates</a></div>
  @else
    <div class="product-grid">@foreach($forDates['items'] as $p)@include('partials.product-card', ['p' => $p, 'dates' => $dates])@endforeach</div>
  @endif
  @if($forDates['packages']->isNotEmpty())
    <h3 class="mt-3">Packages available {{ $range }}</h3>
    <div class="product-grid">@foreach($forDates['packages'] as $k)@include('partials.package-card', ['k' => $k, 'dates' => $dates])@endforeach</div>
  @endif
</section>
