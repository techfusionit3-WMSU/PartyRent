{{--
  Supplier location (inside shop-fields): location type, address and a map pin.
  Not every seller has a shop: home-based sellers keep their exact spot private until a pickup is accepted,
  and sellers with no pickup location only name their city (pin optional, used for delivery distance).
  $d = supplier attributes. $locFor: 'individual' (default) | 'business' | 'either' (Start Selling, where the
  supplier type is picked on the page). A business always works from a physical workplace, so it gets no choice:
  its location type is always "shop" (address + pin).
--}}
@php
    $locFor = $locFor ?? 'individual';
    $type = $locFor === 'business' ? 'shop' : old('location_type', $d['location_type'] ?? 'shop');
    $lat = old('lat', $d['lat'] ?? null);
    $lng = old('lng', $d['lng'] ?? null);
    $city = old('shop_addr.cityCode', $d['address_parts']['cityCode'] ?? ($d['city_code'] ?? null));
@endphp
@include('partials.leaflet')
<div id="locBox">
  @if($locFor !== 'business')
  <div @if($locFor === 'either') data-for="individual" @endif>
    <h3 class="mt-2 mb-1">Where do you operate from?</h3>
    <div class="radio-cards loc-types mb-2">
      @foreach(\App\Models\Supplier::LOCATION_TYPES as $k => [$label, $desc])
        <label class="radio-card"><input type="radio" name="location_type" value="{{ $k }}" @checked($type === $k)><div><b>{{ $label }}</b><div class="small muted">{{ $desc }}</div></div></label>
      @endforeach
    </div>
    @error('location_type')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
  </div>
  @endif
  @if($locFor !== 'individual')<input type="hidden" name="location_type" value="shop" @if($locFor === 'either') data-for="organization" @endif>@endif
  @if($locFor === 'business')<h3 class="mt-2 mb-1">Your workplace</h3>@endif

  <h3 class="mb-1" data-loc-title>{{ ['shop' => 'Shop / pickup address', 'home' => 'Your address (kept private)', 'none' => 'Where you are based'][$type] }}</h3>
  <p class="small muted mt-0" data-loc-hint></p>
  @include('partials.address-fields', ['key' => 'shop_addr', 'value' => $d['address_parts'] ?? [], 'full' => true, 'required' => true])

  <div class="field mt-1" data-map-picker data-city-from="shop_addr" data-city="{{ $city }}">
    <label>Map pin <span class="muted" data-pin-optional>(optional)</span></label>
    <div class="pr-map tall" data-map role="application" aria-label="Map: click to place your pin"></div>
    <input type="hidden" name="lat" value="{{ $lat }}" data-lat><input type="hidden" name="lng" value="{{ $lng }}" data-lng>
    <div class="row wrap mt-1" style="gap:8px"><button type="button" class="btn btn-sm" data-locate>{{ icon('map') }}Use my current location</button><button type="button" class="btn btn-sm btn-ghost" data-unpin>{{ icon('x') }}Remove pin</button></div>
    <div class="hint" data-pin-status aria-live="polite"></div>
    @error('lat')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
  </div>
</div>
@once
@push('scripts')
<script>
(function () {
  var box = document.getElementById('locBox'); if (!box) return;
  var form = box.closest('form');
  var HINT = {
    shop: 'Customers see this address and pin, and pick up here. Distance-based delivery is measured from your pin.',
    home: 'Customers only see your city until you accept their pickup request. Then they get this address and your pin.',
    none: 'Only your city is needed. You deliver everything, so this is never shown as a pickup point. A pin is optional and only makes delivery distances more accurate.'
  };
  var TITLE = { shop: 'Shop / pickup address', home: 'Your address (kept private)', none: 'Where you are based' };
  function sync() {
    // a business has no choice: an enabled hidden location_type=shop
    var hid = [].find.call(form.querySelectorAll('input[type=hidden][name="location_type"]'), function (i) { return !i.disabled; });
    var t = hid ? 'shop' : ((form.querySelector('[name="location_type"]:checked:not(:disabled)') || {}).value || 'shop');
    box.querySelector('[data-loc-hint]').textContent = HINT[t];
    box.querySelector('[data-loc-title]').textContent = TITLE[t];
    // barangay / street are only required when customers may come to you
    ['shop_addr[barangay]', 'shop_addr[street]'].forEach(function (n) { var i = form.elements[n]; if (i) { i.required = t !== 'none'; if (t === 'none') { var f = i.closest('.field'); if (f) f.classList.remove('invalid'); } } });
    // no pickup location → delivery only
    var ful = form.querySelectorAll('[name="fulfillment"]');
    ful.forEach(function (r) {
      var off = t === 'none' && r.value !== 'delivery';
      r.disabled = off; var card = r.closest('label'); if (card) card.style.opacity = off ? '.45' : '';
      if (off && r.checked) { var d = form.querySelector('[name="fulfillment"][value="delivery"]'); if (d) { d.checked = true; d.dispatchEvent(new Event('change', { bubbles: true })); } }
    });
    var f = (form.querySelector('[name="fulfillment"]:checked') || {}).value;
    box.querySelector('[data-pin-optional]').textContent = t !== 'none' && f !== 'delivery' ? '(required for pickup)' : '(optional)';
  }
  form.addEventListener('change', function (e) { if (e.target.name === 'location_type' || e.target.name === 'fulfillment' || e.target.name === 'app[type]') setTimeout(sync); });
  sync();
})();
</script>
@endpush
@endonce
