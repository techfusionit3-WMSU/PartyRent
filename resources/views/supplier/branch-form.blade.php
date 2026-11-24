@extends('layouts.dash', ['title' => $l->exists ? 'Edit branch' : 'Add branch', 'nav' => 'locations'])

{{-- One branch of the supplier: its own name, address, map pin (delivery origin) and contact. The business details
     (shop name, description, payout…) stay on the Shop Profile; customers still see one store. --}}
@php
    $parts = old('addr', $l->address_parts ?? []);
    $lat = old('lat', $l->lat);
    $lng = old('lng', $l->lng);
    $city = $parts['cityCode'] ?? $l->city_code;
@endphp
@section('content')
<div class="crumbs"><a href="{{ route('supplier.locations') }}">Branches</a> / {{ $l->exists ? $l->name : 'New branch' }}</div>
<div class="page-head"><div><h1>{{ $l->exists ? 'Edit '.$l->name : 'Add a branch' }}</h1><p>A branch is a physical place {{ $s->name }} rents from. It keeps its own stock; customers still see one {{ $s->name }} store and pick (or are given) the branch that fulfils their rental.</p></div></div>

<form method="POST" action="{{ $l->exists ? route('supplier.locations.update', $l) : route('supplier.locations.store') }}" class="grid grid-side" novalidate>
  @csrf @if($l->exists) @method('PUT') @endif
  <div class="stack">
    <div class="card"><h3>Branch details</h3>
      <div class="form-grid">
        <div class="field full"><label for="bName">Branch name</label><input class="input" id="bName" name="name" required minlength="2" maxlength="120" value="{{ old('name', $l->name) }}" placeholder="e.g. Main Warehouse, Pasig Branch"></div>
        <div class="field"><label for="bContact">Person in charge <span class="muted">(optional)</span></label><input class="input" id="bContact" name="contact" maxlength="120" value="{{ old('contact', $l->contact) }}"></div>
        <div class="field"><label for="bPhone">Branch contact number</label><input class="input" id="bPhone" name="phone" maxlength="40" value="{{ old('phone', $l->phone) }}" placeholder="0917 555 0101" inputmode="tel"></div>
      </div>
    </div>
    <div class="card"><h3>Address & map pin</h3>
      <p class="small muted mt-0">Deliveries leave from here and pickups happen here. Customers' delivery fees and branch recommendations are measured from this pin.</p>
      @if($l->exists && ! $l->address_parts && $l->address)<div class="alert alert-info mb-2">{{ icon('info') }}<div class="small">Current address: <b>{{ $l->address }}</b>. Pick the city below to keep it structured (needed for distance-based delivery).</div></div>@endif
      @include('partials.leaflet')
      @include('partials.address-fields', ['key' => 'addr', 'value' => $parts, 'full' => true, 'required' => ! $l->exists || (bool) $l->address_parts])
      @if($l->exists && ! $l->address_parts)<input type="hidden" name="address" value="{{ $l->address }}">@endif
      <div class="field mt-1" data-map-picker data-city-from="addr" data-city="{{ $city }}">
        <label>Map pin</label>
        <div class="pr-map tall" data-map role="application" aria-label="Map: click to place the branch pin"></div>
        <input type="hidden" name="lat" value="{{ $lat }}" data-lat><input type="hidden" name="lng" value="{{ $lng }}" data-lng>
        <div class="row wrap mt-1" style="gap:8px"><button type="button" class="btn btn-sm" data-locate>{{ icon('map') }}Use my current location</button><button type="button" class="btn btn-sm btn-ghost" data-unpin>{{ icon('x') }}Remove pin</button></div>
        <div class="hint" data-pin-status aria-live="polite"></div>
      </div>
    </div>
  </div>
  <aside class="stack">
    <div class="card"><h3>Status</h3>
      @if($l->exists)
        <dl class="kv small"><dt>Taking rentals</dt><dd>{{ $l->active ? 'Yes' : 'No (deactivated)' }}</dd><dt>Main branch</dt><dd>{{ $l->is_main ? 'Yes' : 'No' }}</dd></dl>
      @endif
      @unless($l->is_main)<label class="check mt-1"><input type="hidden" name="is_main" value="0"><input type="checkbox" name="is_main" value="1" @checked(old('is_main'))> Make this the main branch</label>
        <div class="hint">The main branch is listed first, gets the stock of new items, and is used when a shop has only one branch.</div>@endunless
    </div>
    <button class="btn btn-primary btn-block btn-lg">{{ $l->exists ? 'Save branch' : 'Add branch' }}</button>
    <a class="btn btn-block btn-ghost" href="{{ route('supplier.locations') }}">Cancel</a>
  </aside>
</form>
@endsection
