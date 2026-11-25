@extends('layouts.market', ['title' => 'Party suppliers', 'nav' => 'suppliers'])

@section('content')
<div class="page-head"><div><h1>{{ $type === 'business' && \App\Models\Supplier::eventCompanies() ? 'Event companies' : 'Rental shops' }}</h1><p>{{ $type === 'business' && \App\Models\Supplier::eventCompanies() ? 'Companies that handle your whole event: send an inquiry and get a quotation.' : (\App\Models\Supplier::eventCompanies() ? 'Verified local rental shops and event companies on PartyRent.' : 'Verified local shops that rent decorations and party items for your event.') }}</p></div>
  <form class="row wrap" method="GET"><input type="hidden" name="type" value="{{ $type }}"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search suppliers or city" style="width:220px" aria-label="Search suppliers"><select class="input input-sm" name="f" style="width:auto" aria-label="Fulfillment" data-autosubmit><option value="">All fulfillment</option><option value="delivery" @selected($f === 'delivery')>Offers delivery</option><option value="pickup" @selected($f === 'pickup')>Offers pickup</option></select></form>
</div>
@if(\App\Models\Supplier::eventCompanies())<div class="tabs mb-2">@foreach(['' => 'All', 'shops' => 'Rental shops', 'business' => 'Event companies'] as $k => $l)<a class="tab {{ $type === $k ? 'active' : '' }}" href="{{ route('suppliers.index', array_filter(['type' => $k, 'q' => $q])) }}">{{ $l }}</a>@endforeach</div>@endif
@php($nearNow = \App\Support\Near::get())
{{-- where we are browsing (search / current location / map), list or map of the shops near it --}}
<div class="card loc-bar mb-2">
  <div class="loc-bar-now">{{ icon('map') }}<span>@if($nearNow)Showing shops near <b>{{ $nearNow['label'] }}</b>@else<b>Set your location</b> to see the shops near you @endif</span></div>
  <div class="place-search loc-bar-q"><input class="input input-sm" id="supNearQ" type="search" placeholder="Change location: city, barangay or landmark" aria-label="Change browsing location" aria-expanded="false"><ul class="place-sugg" role="listbox" hidden></ul></div>
  <button type="button" class="btn btn-sm" id="supLocate">{{ icon('map') }}Use my current location</button>
  <div class="seg"><a href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}"><button type="button" class="{{ $view === 'list' ? 'active' : '' }}">List</button></a><a href="{{ request()->fullUrlWithQuery(['view' => 'map']) }}"><button type="button" class="{{ $view === 'map' ? 'active' : '' }}">Map</button></a></div>
  @if($nearNow)<div class="seg"><a href="{{ request()->fullUrlWithQuery(['sort' => 'near']) }}"><button type="button" class="{{ $sort === 'near' ? 'active' : '' }}">Nearest</button></a><a href="{{ request()->fullUrlWithQuery(['sort' => 'rating']) }}"><button type="button" class="{{ $sort === 'rating' ? 'active' : '' }}">Top rated</button></a></div>@endif
  <span class="small muted loc-bar-msg" id="supNearMsg" role="status"></span>
</div>
@if($view === 'map')
  @include('partials.leaflet')
  <div class="card pad-0 mb-2"><div class="pr-map stores-map" id="storesMap" role="region" aria-label="Map of the shops near you"></div></div>
  @if($pins)
    <p class="small muted">{{ count($pins) }} branch{{ count($pins) === 1 ? '' : 'es' }} of {{ $list->count() }} shop{{ $list->count() === 1 ? '' : 's' }}. Shops that work from home show their city only. Tap a marker for the shop.</p>
  @else
    @include('partials.empty', ['emoji' => '🗺️', 'title' => 'No shops near here yet', 'text' => 'No shop delivers to or rents near '.($nearNow['label'] ?? 'this place').'. Try another place above.'])
  @endif
@endif
<div class="grid grid-2" @if($view === 'map') hidden @endif>
  @forelse($list as $s)
    @if($s->isOrganization())
    @php($bset = $s->bizSettings())
    <div class="card">
      <div class="row top"><div class="logo-tile" style="background:{{ $s->color }}1a">@if($s->logoUrl())<img src="{{ $s->logoUrl() }}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:12px">@else{{ $s->logo }}@endif</div><div class="grow"><a class="strong" style="font-size:1.05rem;color:var(--ink)" href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a> <span class="badge b-purple plain">Event company</span><div class="small muted">{{ icon('map', 'width:13px;vertical-align:-2px') }} {{ $bset['serviceAreas'] ? implode(', ', array_slice($bset['serviceAreas'], 0, 3)) : $s->city }} · <span class="stars">★</span> {{ rating_text($s) }}</div><p class="small mt-1 mb-1">{{ \Illuminate\Support\Str::limit($s->description, 160) }}</p>
        <div class="row wrap gap-sm">@foreach(array_slice($s->profile('events', []), 0, 4) as $e)<span class="tag">{{ $e }}</span>@endforeach</div></div></div>
      @if($s->portfolio)<div class="scroll-x mt-2">@foreach(array_slice($s->portfolio, 0, 5) as $ph)<img src="{{ asset('storage/'.$ph) }}" alt="" loading="lazy" style="width:84px;height:64px;object-fit:cover;border-radius:10px">@endforeach</div>@endif
      <div class="row between mt-1"><span class="small muted">{{ $s->profile('packagesFrom') ? 'Packages from '.money($s->profile('packagesFrom')) : ($s->profile('eventSize') ? \App\Models\Supplier::EVENT_SIZES[$s->profile('eventSize')] ?? '' : '') }}</span><a class="btn btn-sm btn-primary" href="{{ route('suppliers.show', $s) }}">See packages</a></div>
    </div>
    @continue
    @endif
    <div class="card">
      <div class="row top"><div class="logo-tile" style="background:{{ $s->color }}1a">{{ $s->logo }}</div><div class="grow"><a class="strong" style="font-size:1.05rem;color:var(--ink)" href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a> <div class="small muted">{{ icon('map', 'width:13px;vertical-align:-2px') }} {{ $s->city }} · <span class="stars">★</span> {{ rating_text($s) }} · {{ $s->fulfillmentLabel() }}</div><p class="small mt-1 mb-1">{{ $s->description }}</p><div class="row wrap gap-sm">@foreach($s->acceptedMethods() as $m)<span class="tag">{{ \App\Models\Payment::METHOD_LABEL[$m] ?? $m }}</span>@endforeach</div></div></div>
      <div class="scroll-x mt-2">@foreach($s->products->take(5) as $p)<a href="{{ route('products.show', $p) }}" title="{{ $p->name }}">@include('partials.thumb', ['item' => $p])</a>@endforeach</div>
      <div class="row between mt-1"><span class="small muted">{{ $s->products->count() }} items · {{ $s->packages_count }} packages</span><a class="btn btn-sm btn-outline" href="{{ route('suppliers.show', $s) }}">Visit shop</a></div>
    </div>
  @empty
    @include('partials.empty', ['emoji' => '🏪', 'title' => 'No suppliers found', 'text' => \App\Support\Near::label() ? __('No shop near :place matches. Try a shorter name, or change where you are renting.', ['place' => \App\Support\Near::label()]) : __('Try a shorter name, another city, or clear the search.')])
  @endforelse
</div>
<script src="{{ asset('js/geo.js') }}?v={{ filemtime(public_path('js/geo.js')) }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const G = window.PRGeo, msg = document.getElementById('supNearMsg'), csrf = document.querySelector('meta[name=csrf-token]').content;
  // changing the browsing place: saved on the server, then the shops are listed again for it
  async function useHere(h) {
    msg.innerHTML = '<span class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:-2px"></span> Finding shops near ' + G.esc(h.label) + '…';
    try {
      const r = await fetch(window.PR.routes.near, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ near: { cityCode: h.parts.cityCode }, lat: h.lat, lng: h.lng }) });
      if (!r.ok) throw new Error('Could not change the location. Try another place.');
      location.reload();
    } catch (e) { msg.textContent = e.message; }
  }
  G.attachSearch(document.getElementById('supNearQ'), useHere);
  document.getElementById('supLocate').addEventListener('click', async () => {
    if (!await window.PRUI.confirmDialog({ title: 'Use your current location?', message: 'Your browser will ask to share your location. We only use it to show the shops near you; it is not shown to anyone.', ok: 'Share my location' })) return;
    msg.textContent = 'Getting your location…';
    try { const p = await G.locate(); msg.textContent = 'Finding this place…'; const h = await G.reverse(p.lat, p.lng); await useHere(Object.assign(h, { lat: p.lat, lng: p.lng })); }
    catch (e) { msg.textContent = e.message.replace('your address', 'a place'); }
  });
  const el = document.getElementById('storesMap');
  if (!el || !window.L) return;
  const pins = @json($pins), center = @json($center), cfg = window.PR.map;
  const map = window.L.map(el, { maxBounds: cfg.bounds, maxBoundsViscosity: 0.8, minZoom: 5 }).setView(center || cfg.center, center ? 13 : cfg.zoom);
  window.L.tileLayer(cfg.tiles, { attribution: cfg.attribution, maxZoom: 19 }).addTo(map);
  if (center) window.L.circleMarker(center, { radius: 9, color: '#2563EB', fillColor: '#3B82F6', fillOpacity: .5, weight: 2 }).addTo(map).bindTooltip('You are browsing here');
  const pts = [];
  pins.forEach(p => {
    const icon = window.L.divIcon({ className: 'store-pin', html: '<span style="background:' + G.esc(p.color) + '">' + G.esc(p.logo || '🏬') + '</span>', iconSize: [34, 34], iconAnchor: [17, 34], popupAnchor: [0, -30] });
    window.L.marker([p.lat, p.lng], { icon, title: p.shop }).addTo(map).bindPopup('<div class="store-pop"><b>' + G.esc(p.shop) + '</b><div class="small">' + G.esc(p.branch) + ' · ' + G.esc(p.city) + (p.exact ? '' : ' (area)') + '</div>' +
      '<div class="small muted">' + (p.rating ? '★ ' + p.rating + ' · ' : '') + p.items + ' items' + (p.km != null ? ' · ' + p.km + ' km from you' : '') + '</div>' +
      '<a class="btn btn-sm btn-primary mt-1" href="' + G.esc(p.url) + '">View products</a></div>');
    pts.push([p.lat, p.lng]);
  });
  if (!center && pts.length) map.fitBounds(pts, { padding: [30, 30], maxZoom: 14 });
});
</script>
@endsection
