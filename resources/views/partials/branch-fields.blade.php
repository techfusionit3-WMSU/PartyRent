{{--
  One extra branch in the supplier application: the same details as the main branch (name, contact, structured
  address Region → Province → City → Barangay → Street, and a map pin). $i = row key (or "__i__" in the template),
  $b = saved values ['name', 'contact', 'phone', 'addr' => address parts, 'lat', 'lng'].
--}}
@php
    $b = $b ?? [];
    $key = 'app[branches]['.$i.']';
    $e = fn ($k) => $errors->first('app.branches.'.$i.'.'.$k);
@endphp
<div class="card card-flat branch-row mb-2" style="padding:14px" data-branch-row>
  <div class="row between mb-1"><b data-branch-title>{{ icon('store', 'width:16px;vertical-align:-3px') }} Branch</b><button type="button" class="btn btn-xs btn-ghost" data-branch-rm>{{ icon('trash') }}Remove</button></div>
  <div class="form-grid">
    <div class="field full {{ $e('name') ? 'invalid' : '' }}"><label>Branch name</label><input class="input" name="{{ $key }}[name]" required minlength="2" maxlength="120" value="{{ $b['name'] ?? '' }}" placeholder="e.g. Pasig Branch">@if($e('name'))<div class="error-text" style="display:block">{{ $e('name') }}</div>@endif</div>
    <div class="field"><label>Person in charge <span class="muted">(optional)</span></label><input class="input" name="{{ $key }}[contact]" maxlength="120" value="{{ $b['contact'] ?? '' }}"></div>
    <div class="field {{ $e('phone') ? 'invalid' : '' }}"><label>Branch contact number</label><input class="input" name="{{ $key }}[phone]" required maxlength="40" value="{{ $b['phone'] ?? '' }}" data-phone>@if($e('phone'))<div class="error-text" style="display:block">{{ $e('phone') }}</div>@endif</div>
  </div>
  <h4 class="mt-1 mb-1">Branch address</h4>
  @include('partials.address-fields', ['key' => $key.'[addr]', 'value' => $b['addr'] ?? [], 'full' => true, 'required' => true])
  <div class="field mt-1" data-map-picker data-city-from="{{ $key }}[addr]" data-city="{{ $b['addr']['cityCode'] ?? '' }}">
    <label>Map pin <span class="muted">(optional, used for delivery distance)</span></label>
    <div class="pr-map" data-map role="application" aria-label="Map: click to place the branch pin"></div>
    <input type="hidden" name="{{ $key }}[lat]" value="{{ $b['lat'] ?? '' }}" data-lat><input type="hidden" name="{{ $key }}[lng]" value="{{ $b['lng'] ?? '' }}" data-lng>
    <div class="row wrap mt-1" style="gap:8px"><button type="button" class="btn btn-sm" data-locate>{{ icon('map') }}Use my current location</button><button type="button" class="btn btn-sm btn-ghost" data-unpin>{{ icon('x') }}Remove pin</button></div>
    <div class="hint" data-pin-status aria-live="polite"></div>
  </div>
</div>
