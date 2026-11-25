@extends('layouts.market', ['title' => 'Where are you renting?'])

{{-- Browsing location: search a place or move the pin (or use the current location); they follow each other
     (public/js/geo.js, the same location service as checkout). Saved for this visit (and on the account). --}}
@section('content')
@include('partials.leaflet')
<div class="card near-card" style="max-width:760px;margin:0 auto">
  <h1 class="mb-1" style="font-size:1.4rem">Where are you renting?</h1>
  <p class="muted">We show the shops that deliver or rent near this place, and the stock they have for you. Search for a place, or move the pin on the map.</p>
  @if($near)<div class="alert alert-info mb-2">{{ icon('map') }}<div>Now showing shops near <b>{{ $near['label'] }}</b>{{ ($near['from'] ?? '') === 'address' ? ' (from your account address)' : '' }}.</div></div>@endif
  <form method="POST" action="{{ route('near.store') }}" id="nearForm" novalidate>@csrf
    <input type="hidden" name="back" value="{{ $back }}">
    <input type="hidden" name="near[cityCode]" value="{{ $near['city'] ?? '' }}" data-n="city">
    <input type="hidden" name="lat" value="" data-n="lat"><input type="hidden" name="lng" value="" data-n="lng">
    <div class="field place-find"><label for="nearQ">Search for a place</label>
      <div class="place-search"><input class="input" id="nearQ" type="search" placeholder="City, barangay, street or landmark, e.g. Tetuan Zamboanga" aria-expanded="false"><ul class="place-sugg" role="listbox" hidden></ul></div></div>
    <div class="loc-map-wrap"><div class="pr-map loc-map" id="nearMap" role="application" aria-label="Map: tap or drag the pin to where you are renting"></div>
      <button type="button" class="btn btn-sm loc-locate" id="nearLocate">{{ icon('map') }}Use my current location</button></div>
    <p class="small loc-pin-note mt-1" id="nearNote">{{ $near ? 'The pin shows the place now used. Move it or search to change it.' : 'Search above, tap the map, or use your current location.' }}</p>
    <div class="near-picked" id="nearPicked" @if(! $near) hidden @endif><span class="small muted">Browsing near</span> <b id="nearLabel">{{ $near['label'] ?? '' }}</b></div>
    @error('near.cityCode')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
    @error('lat')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
    <div class="row wrap mt-2" style="gap:8px">
      <button class="btn btn-primary" id="nearSave" @if(! $near) disabled @endif>{{ icon('check') }}Show shops near this place</button>
      <a class="btn" href="{{ $back }}">Cancel</a>
    </div>
    <p class="small muted mt-2 mb-0">Your exact spot is only used to find shops near you. It is never shown to shops or other customers.</p>
  </form>
  @if($near && ($near['from'] ?? '') === 'chosen')
    <form method="POST" action="{{ route('near.clear') }}" class="mt-2">@csrf @method('DELETE')<input type="hidden" name="back" value="{{ $back }}"><button class="link-btn small">Forget this place</button></form>
  @endif
</div>
<script src="{{ asset('js/geo.js') }}?v={{ filemtime(public_path('js/geo.js')) }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const G = window.PRGeo, f = document.getElementById('nearForm');
  const city = f.querySelector('[data-n=city]'), lat = f.querySelector('[data-n=lat]'), lng = f.querySelector('[data-n=lng]');
  const note = document.getElementById('nearNote'), label = document.getElementById('nearLabel'), picked = document.getElementById('nearPicked'), save = document.getElementById('nearSave');
  const start = @json($near ? [$near['lat'], $near['lng']] : null);
  const set = (h, msg) => { city.value = h.parts.cityCode; lat.value = (+h.lat).toFixed(6); lng.value = (+h.lng).toFixed(6); label.textContent = h.label; picked.hidden = false; save.disabled = false; note.textContent = msg || (h.precise ? 'Pin placed. Move it if needed.' : 'Pin placed in the area. Move it closer if you like.'); };
  const map = G.pinMap(document.getElementById('nearMap'), start ? { lat: start[0], lng: start[1], zoom: 13, onMove } : { lat: null, onMove });
  async function onMove(a, b) {
    note.innerHTML = '<span class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:-2px"></span> Finding this place…';
    try { const h = await G.reverse(a, b); set(Object.assign(h, { lat: a, lng: b })); } catch (e) { note.textContent = e.message; }
  }
  G.attachSearch(document.getElementById('nearQ'), h => { set(h); if (map) map.setPin(h.lat, h.lng, h.precise ? 16 : 13); });
  document.getElementById('nearLocate').addEventListener('click', async () => {
    if (!await window.PRUI.confirmDialog({ title: 'Use your current location?', message: 'Your browser will ask to share your location. We only use it to show shops near you; it is not shown to anyone.', ok: 'Share my location' })) return;
    note.textContent = 'Getting your location…';
    try { const p = await G.locate(); if (map) map.setPin(p.lat, p.lng, 14); await onMove(p.lat, p.lng); } catch (e) { note.textContent = e.message; window.PRUI.toast(e.message, 'warn'); }
  });
});
</script>
@endsection
