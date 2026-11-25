{{--
  Where a supplier operates from, with privacy rules applied.
  $s = supplier; $reveal = true when this viewer may see the exact spot (the owner, staff, or a customer
  whose pickup request was accepted). Home-based sellers otherwise show only a circle around their city.
--}}
@php
    $reveal = ($reveal ?? false) || $s->isPublicLocation();
    $centre = \App\Domain\Locations::get($s->city_code);
    $area = \App\Domain\Locations::label($s->city_code) ?: $s->city;
@endphp
@include('partials.leaflet')
<div class="stack-sm">
  @if($s->location_type === 'none')
    <div class="small">{{ icon('truck', 'width:14px;vertical-align:-2px') }} <b>Online seller</b> based in {{ $area }}. No pickup location; everything is delivered.</div>
  @elseif($reveal && $s->hasPin())
    <div class="small"><b>{{ ['home' => 'Pickup address (home-based seller)', 'warehouse' => 'Warehouse / pickup address'][$s->location_type] ?? 'Shop / pickup address' }}:</b> {{ $s->address }}</div>
    <div class="pr-map" data-map-view data-lat="{{ $s->lat }}" data-lng="{{ $s->lng }}" role="img" aria-label="Map showing {{ $s->name }}"></div>
    <a class="btn btn-sm" href="{{ $s->directionsUrl() }}" target="_blank" rel="noopener">{{ icon('map') }}Get directions</a>
  @elseif($reveal)
    <div class="small"><b>Pickup address:</b> {{ $s->address }}</div>
  @else
    <div class="small">{{ icon('home', 'width:14px;vertical-align:-2px') }} <b>Home-based seller</b> in {{ $area }}. The exact pickup address is shared once {{ $s->name }} accepts your request.</div>
    @if($centre && $centre['lat'] !== null)
      <div class="pr-map" data-map-view data-lat="{{ $centre['lat'] }}" data-lng="{{ $centre['lng'] }}" data-radius="2500" role="img" aria-label="Approximate area: {{ $area }}"></div>
    @endif
  @endif
</div>
