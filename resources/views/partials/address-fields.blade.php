{{--
  Structured address: Region → Province → City/Municipality selects (+ barangay, street, landmark).
  Params: $key (input prefix, default "addr"), $value (address_parts array), $full (show barangay/street/landmark),
          $required.
  Server-rendered so it works without JS; public/js/app.js cascades the selects and fires a
  `pr:city` event (detail.cityCode) whenever the city changes.
--}}
@php
    $key = $key ?? 'addr';
    $full = $full ?? true;
    $required = $required ?? true;
    $dot = str_replace(['[', ']'], ['.', ''], $key); // delivery[zones][0] → delivery.zones.0 for old() / errors
    $v = old($dot, $value ?? []) ?: [];
    $cc = $v['cityCode'] ?? null;
    $par = $cc ? \App\Domain\Locations::parentsOf($cc) : [];
    $rc = $par['regionCode'] ?? ($v['regionCode'] ?? null);
    $pc = $par['provinceCode'] ?? ($v['provinceCode'] ?? null);
    $provinces = \App\Domain\Locations::provincesOf($rc);
    $cities = \App\Domain\Locations::citiesOf($pc);
    $err = fn ($k) => $errors->first($dot.'.'.$k);
    $req = $required ? 'required' : '';
@endphp
<div class="form-grid {{ $class ?? '' }}" data-address="{{ $key }}">
  <div class="field {{ $err('regionCode') ? 'invalid' : '' }}"><label>Region</label>
    <select class="input" name="{{ $key }}[regionCode]" data-level="region" {{ $req }}><option value="">Select region…</option>@foreach(\App\Domain\Locations::regions() as $r)<option value="{{ $r['code'] }}" @selected($rc === $r['code'])>{{ $r['name'] }}</option>@endforeach</select>
    @if($err('regionCode'))<div class="error-text" style="display:block">{{ $err('regionCode') }}</div>@endif</div>
  <div class="field {{ $err('provinceCode') ? 'invalid' : '' }}"><label>Province</label>
    <select class="input" name="{{ $key }}[provinceCode]" data-level="province" {{ $req }} @disabled(! $provinces)><option value="">{{ $required ? 'Select province…' : 'Whole region' }}</option>@foreach($provinces as $p)<option value="{{ $p['code'] }}" @selected($pc === $p['code'])>{{ $p['name'] }}</option>@endforeach</select>
    @if($err('provinceCode'))<div class="error-text" style="display:block">{{ $err('provinceCode') }}</div>@endif</div>
  <div class="field {{ $err('cityCode') ? 'invalid' : '' }}"><label>City / municipality</label>
    <select class="input" name="{{ $key }}[cityCode]" data-level="city" {{ $req }} @disabled(! $cities)><option value="">{{ $required ? 'Select city / municipality…' : 'Whole province' }}</option>@foreach($cities as $c)<option value="{{ $c['code'] }}" @selected($cc === $c['code'])>{{ $c['name'] }}</option>@endforeach</select>
    @if($err('cityCode'))<div class="error-text" style="display:block">{{ $err('cityCode') }}</div>@endif</div>
  @if($full)
    <div class="field {{ $err('barangay') ? 'invalid' : '' }}"><label>Barangay</label><input class="input" name="{{ $key }}[barangay]" {{ $req }} maxlength="120" value="{{ $v['barangay'] ?? '' }}" placeholder="e.g. Kamuning">
      @if($err('barangay'))<div class="error-text" style="display:block">{{ $err('barangay') }}</div>@endif</div>
    <div class="field full {{ $err('street') ? 'invalid' : '' }}"><label>House no. / building and street</label><input class="input" name="{{ $key }}[street]" {{ $req }} maxlength="180" value="{{ $v['street'] ?? '' }}" placeholder="e.g. 23 Mabini St." autocomplete="street-address">
      @if($err('street'))<div class="error-text" style="display:block">{{ $err('street') }}</div>@endif</div>
    <div class="field full"><label>Landmark <span class="muted">(optional)</span></label><input class="input" name="{{ $key }}[landmark]" maxlength="180" value="{{ $v['landmark'] ?? '' }}" placeholder="e.g. beside the barangay hall"></div>
  @endif
</div>
