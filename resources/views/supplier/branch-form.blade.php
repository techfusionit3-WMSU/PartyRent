@extends('layouts.dash', ['title' => $l->exists ? 'Edit branch' : 'Add branch', 'nav' => 'locations'])

{{-- One branch of the supplier: name, contact, address and map pin (where deliveries leave from). The business details
     (shop name, description, payout…) stay on the Shop Profile; customers still see one store. --}}
@php
    // an older branch saved only a line of text: start the pickers on its city and keep the text as the street
    $cityName = $l->city_code ? \App\Domain\Locations::name($l->city_code) : '';
    $street = $cityName ? trim(preg_replace('/,?\s*'.preg_quote($cityName, '/').'.*$/iu', '', (string) $l->address), " ,") : (string) $l->address;
    $parts = old('addr', $l->address_parts ?: ($l->city_code ? ['cityCode' => $l->city_code, 'street' => $street] : []));
    $lat = old('lat', $l->lat);
    $lng = old('lng', $l->lng);
    $city = $parts['cityCode'] ?? $l->city_code;
    $req = '<span class="req" aria-hidden="true">*</span>';
@endphp
@section('content')
<div class="crumbs"><a href="{{ route('supplier.locations') }}">Branches</a> / {{ $l->exists ? $l->name : 'New branch' }}</div>
<div class="page-head"><div><h1>{{ $l->exists ? 'Edit '.$l->name : 'Add a branch' }}</h1><p>A branch is another place your shop rents from, with its own stock. Customers still see one {{ $s->name }} store.</p></div></div>

<form method="POST" action="{{ $l->exists ? route('supplier.locations.update', $l) : route('supplier.locations.store') }}" class="grid grid-side" novalidate>
  @csrf @if($l->exists) @method('PUT') @endif
  <div class="stack">
    <div class="card"><h3 class="mb-1">1. Branch name</h3>
      <div class="field mb-0"><label for="bName">Name customers and your team will see {!! $req !!}</label><input class="input" id="bName" name="name" required minlength="2" maxlength="120" value="{{ old('name', $l->name) }}" placeholder="e.g. Pasig Branch, Main Warehouse"></div>
    </div>
    <div class="card"><h3 class="mb-1">2. Contact</h3>
      <div class="form-grid">
        <div class="field"><label for="bContact">Person in charge <span class="muted small">(optional)</span></label><input class="input" id="bContact" name="contact" maxlength="120" value="{{ old('contact', $l->contact) }}" placeholder="e.g. Joy Lim"></div>
        <div class="field"><label for="bPhone">Contact number <span class="muted small">(optional)</span></label><input class="input" id="bPhone" name="phone" maxlength="40" value="{{ old('phone', $l->phone) }}" placeholder="0917 555 0101" inputmode="tel"><div class="hint">Mobile or landline, e.g. (02) 8820 1234.</div></div>
        <div class="field"><label for="bHours">Opening hours <span class="muted small">(optional)</span></label><input class="input" id="bHours" name="hours" maxlength="120" value="{{ old('hours', $l->hours) }}" placeholder="e.g. Mon–Sat 8 AM–6 PM"></div>
      </div>
    </div>
    @php
      $cv = \App\Domain\Branches::coverage($l->exists ? $l : new \App\Models\InventoryLocation(['supplier_id' => $s->id]));
      $cvOld = old('coverage');
      $cvMode = $cvOld['mode'] ?? ($l->exists ? $cv['mode'] : 'distance');
      $cvAreas = $cvOld['areas'] ?? array_map(fn ($a) => ['city' => \App\Domain\Locations::label($a['city']), 'barangays' => implode(', ', $a['barangays'])], $l->exists ? $cv['areas'] : []);
      $cvAreas = array_values(array_pad($cvAreas, max(2, count($cvAreas) + 1), ['city' => '', 'barangays' => '']));
    @endphp
    <div class="card cov-card"><h3 class="mb-1">Delivery coverage</h3>
      <p class="small muted mt-0">Where this branch delivers. Checkout picks the branch for each customer from their delivery address, so a branch is only given addresses it covers. If no branch covers an address, the customer is told before they can send the request.</p>
      <label class="check mb-1"><input type="radio" name="coverage[mode]" value="distance" @checked($cvMode !== 'areas')> <span><b>Within a distance of this branch</b><span class="small muted" style="display:block">Measured from the branch's map pin to the customer's pinned address (or their city).</span></span></label>
      <div class="field" data-cov="distance" style="margin-left:26px"><label for="bKm">Up to (km)</label><input class="input" id="bKm" name="service_km" type="number" min="1" max="2000" step="1" value="{{ old('service_km', $l->service_km) }}" placeholder="Shop default: {{ $s->branchSettings()['serviceKm'] }} km" style="max-width:200px"><div class="hint">Leave blank to use the shop default.</div></div>
      <label class="check mb-1 mt-1"><input type="radio" name="coverage[mode]" value="areas" @checked($cvMode === 'areas')> <span><b>Only the areas I list</b><span class="small muted" style="display:block">Cities or municipalities, and optionally only some of their barangays.</span></span></label>
      <div data-cov="areas" style="margin-left:26px">
        <div class="cov-rows" data-cov-rows>
          @foreach($cvAreas as $i => $a)
            <div class="cov-row" data-cov-row>
              <div class="field mb-0"><label class="small" for="covC{{ $i }}">City / municipality</label><input class="input" id="covC{{ $i }}" name="coverage[areas][{{ $i }}][city]" list="covCities" value="{{ $a['city'] }}" placeholder="Start typing, e.g. Zamboanga City" autocomplete="off"></div>
              <div class="field mb-0"><label class="small" for="covB{{ $i }}">Barangays <span class="muted">(optional)</span></label><input class="input" id="covB{{ $i }}" name="coverage[areas][{{ $i }}][barangays]" value="{{ $a['barangays'] }}" placeholder="All barangays, or e.g. Tetuan, Putik, Guiwan"></div>
            </div>
          @endforeach
        </div>
        <button type="button" class="btn btn-sm mt-1" data-cov-add>{{ icon('plus') }}Add an area</button>
        <datalist id="covCities">@foreach(\App\Domain\Locations::cities() as $c)<option value="{{ \App\Domain\Locations::label($c['code']) }}"></option>@endforeach</datalist>
      </div>
      @error('coverage')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
    </div>
    @push('scripts')
    <script>
    (function () {
      const card = document.querySelector('.cov-card'); if (!card) return;
      const sync = () => { const m = (card.querySelector('[name="coverage[mode]"]:checked') || {}).value; card.querySelectorAll('[data-cov]').forEach(el => { el.hidden = el.dataset.cov !== m; }); };
      card.querySelectorAll('[name="coverage[mode]"]').forEach(r => r.addEventListener('change', sync)); sync();
      card.querySelector('[data-cov-add]').addEventListener('click', () => {
        const rows = card.querySelector('[data-cov-rows]'), last = rows.lastElementChild, n = rows.children.length, c = last.cloneNode(true);
        c.querySelectorAll('input').forEach(i => { i.value = ''; i.name = i.name.replace(/\[\d+\]/, '[' + n + ']'); i.id = i.id.replace(/\d+$/, n); });
        c.querySelectorAll('label').forEach(lb => { lb.htmlFor = lb.htmlFor.replace(/\d+$/, n); });
        rows.appendChild(c); c.querySelector('input').focus();
      });
    })();
    </script>
    @endpush
    <div class="card"><h3 class="mb-1">3. Location</h3>
      <p class="small muted mt-0">Deliveries leave from here and pickups happen here, so the delivery fee customers see is measured from this branch.</p>
      @if($l->exists && ! $l->address_parts && $l->address)<div class="alert alert-info mb-2">{{ icon('info') }}<div class="small">Saved address: <b>{{ $l->address }}</b>. Check the region, province and city below and add the barangay, then save.</div></div>@endif
      @include('partials.leaflet')
      @include('partials.address-fields', ['key' => 'addr', 'value' => $parts, 'full' => true, 'required' => ! $l->exists || (bool) $l->address_parts])
      @if($l->exists && ! $l->address_parts)<input type="hidden" name="address" value="{{ $l->address }}">@endif
      <div class="field mt-1 mb-0" data-map-picker data-city-from="addr" data-city="{{ $city }}">
        <label>Pin on the map <span class="muted small">(recommended)</span></label>
        <div class="hint mt-0 mb-1">Tap the map where the branch is. A pin makes delivery fees and the branch suggested to customers accurate.</div>
        <div class="pr-map tall" data-map role="application" aria-label="Map: click to place the branch pin"></div>
        <input type="hidden" name="lat" value="{{ $lat }}" data-lat><input type="hidden" name="lng" value="{{ $lng }}" data-lng>
        <div class="row wrap mt-1" style="gap:8px"><button type="button" class="btn btn-sm" data-locate>{{ icon('map') }}Use my current location</button><button type="button" class="btn btn-sm btn-ghost" data-unpin>{{ icon('x') }}Remove pin</button></div>
        <div class="hint" data-pin-status aria-live="polite"></div>
      </div>
    </div>
  </div>
  <aside class="stack">
    <div class="card"><h3 class="mb-1">Main branch</h3>
      @if(! \App\Support\BranchScope::isOrgLevel())<p class="small muted mb-0">Only the owner or an Organization Admin chooses the main branch.</p>
      @elseif($l->is_main)
        <p class="small mb-0">{{ icon('check', 'width:14px;vertical-align:-2px') }} This is your <b>main branch</b>. It is listed first, and new items start with their stock here.</p>
      @else
        <label class="check mb-1"><input type="hidden" name="is_main" value="0"><input type="checkbox" name="is_main" value="1" @checked(old('is_main'))> Make this my main branch</label>
        <div class="hint">The main branch is listed first, and new items start with their stock here.</div>
      @endif
      @if($l->exists && ! $l->active)<div class="alert alert-warn mt-2 mb-0">{{ icon('alert') }}<div class="small">This branch is paused: customers can't rent from it. Open it again from the Branches page.</div></div>@endif
    </div>
    <button class="btn btn-primary btn-block btn-lg">{{ $l->exists ? 'Save changes' : 'Add branch' }}</button>
    <a class="btn btn-block btn-ghost" href="{{ route('supplier.locations') }}">Cancel</a>
    @unless($l->exists)<p class="small muted center mb-0">After adding it, set how many of each item this branch has on each item's page, or move stock here from the Branches page.</p>@endunless
  </aside>
</form>
@endsection
