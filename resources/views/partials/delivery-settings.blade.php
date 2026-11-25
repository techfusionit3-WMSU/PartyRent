{{--
  Supplier delivery settings (inside shop-fields step 2). $dl = current settings (Supplier::DELIVERY_DEFAULTS shape).
  Zone rows reuse the address picker with optional province/city: the deepest place picked is the zone.
--}}
@php
    $L = \App\Domain\Locations::class;
    $dl = array_merge(\App\Models\Supplier::DELIVERY_DEFAULTS, $dl ?? []);
    $dv = fn ($k) => old('delivery.'.$k, $dl[$k]);
    $mode = $dv('mode');
    $zoneRows = old('delivery.zones') !== null
        ? array_values(old('delivery.zones'))
        : array_map(function ($z) use ($L) {
            $p = $L::path($z['code']);

            return ['regionCode' => $p[0]['code'] ?? null, 'provinceCode' => $p[1]['code'] ?? null, 'cityCode' => $p[2]['code'] ?? null, 'fee' => $z['fee']];
        }, $dl['zones']);
    if (! $zoneRows) {
        $zoneRows = [['regionCode' => null, 'provinceCode' => null, 'cityCode' => null, 'fee' => '']];
    }
    $cur = currency_symbol();
@endphp
<div id="deliveryBox" class="mt-2">
  <h3 class="mb-1">Delivery rates</h3>
  <p class="small muted">Set where you deliver and how much it costs. Customers outside your coverage can still pick up (if you offer pickup).</p>
  <div class="row wrap gap-sm mb-2">
    @foreach(['weight' => ['PartyRent standard rates', 'Like Shopee: by weight and route, no setup needed'], 'zones' => ['By area', 'Your own fee per region, province or city'], 'distance' => ['By distance', 'Your own base fee + rate per km']] as $m => [$l, $desc])
      <label class="card card-flat row grow" style="padding:10px 12px;cursor:pointer;min-width:220px"><input type="radio" name="delivery[mode]" value="{{ $m }}" @checked($mode === $m) style="accent-color:var(--brand);width:18px;height:18px"><div><b>{{ $l }}</b><div class="small muted">{{ $desc }}</div></div></label>
    @endforeach
  </div>

  <div data-dmode="weight" class="{{ $mode === 'weight' ? '' : 'hidden' }}">
    @php($S = \App\Domain\Settings::class)
    @php($first = $S::get('shipFirstKg'))
    @php($per = $S::get('shipPerKg'))
    <p class="small muted">The fee is worked out from the weight of the items (or their size, if bigger) and how far the customer is. Set each product's weight and size in its form for accurate fees.</p>
    <div class="table-wrap mb-1"><table class="table"><thead><tr><th>Route</th><th class="num">First kg</th><th class="num">Each extra kg</th></tr></thead><tbody>
      @foreach(['local' => 'Same province (all of Metro Manila counts as one)', 'island' => 'Same island group (Luzon, Visayas or Mindanao)', 'inter' => 'Another island group'] as $t => $tl)
        <tr><td>{{ $tl }}</td><td class="num">{{ money($first[$t] ?? 0) }}</td><td class="num">{{ money($per[$t] ?? 0) }}</td></tr>
      @endforeach
    </tbody></table></div>
    <div class="field"><label>Deliver to</label><select class="input" name="delivery[reach]">
      @foreach(['local' => 'My province only', 'island' => 'My island group (Luzon, Visayas or Mindanao)', 'inter' => 'Nationwide'] as $k => $l)<option value="{{ $k }}" @selected($dv('reach') === $k)>{{ $l }}</option>@endforeach
    </select><div class="hint">Rates are set by PartyRent. You still make the delivery and the whole fee goes to you, with no commission.</div></div>
  </div>

  <div data-dmode="zones" class="{{ $mode === 'zones' ? '' : 'hidden' }}">
    <div class="small muted mb-1">The most specific area wins: a city row overrides its province, a province row overrides its region.</div>
    @if($errors->has('delivery.zones'))<div class="error-text mb-1" style="display:block">{{ $errors->first('delivery.zones') }}</div>@endif
    <div id="zoneRows" class="stack-sm">
      @foreach($zoneRows as $i => $z)
        <div class="card card-flat zone-row" style="padding:12px">
          @include('partials.address-fields', ['key' => "delivery[zones][$i]", 'value' => $z, 'full' => false, 'required' => false])
          <div class="row mt-1" style="gap:8px"><label class="small strong" style="white-space:nowrap">Fee ({{ $cur }})</label><input class="input input-sm" type="number" min="0" step="0.01" name="delivery[zones][{{ $i }}][fee]" value="{{ $z['fee'] ?? '' }}" style="max-width:140px"><span class="grow"></span><button type="button" class="btn btn-xs btn-ghost" data-zone-remove>{{ icon('trash') }}Remove</button></div>
        </div>
      @endforeach
    </div>
    <button type="button" class="btn btn-sm mt-1" id="zoneAdd">{{ icon('plus') }}Add delivery area</button>
    <template id="zoneTpl">
      <div class="card card-flat zone-row" style="padding:12px">
        @include('partials.address-fields', ['key' => 'delivery[zones][__i__]', 'value' => [], 'full' => false, 'required' => false])
        <div class="row mt-1" style="gap:8px"><label class="small strong" style="white-space:nowrap">Fee ({{ $cur }})</label><input class="input input-sm" type="number" min="0" step="0.01" name="delivery[zones][__i__][fee]" value="" style="max-width:140px"><span class="grow"></span><button type="button" class="btn btn-xs btn-ghost" data-zone-remove>{{ icon('trash') }}Remove</button></div>
      </div>
    </template>
  </div>

  <div data-dmode="distance" class="{{ $mode === 'distance' ? '' : 'hidden' }}">
    <div class="form-grid">
      <div class="field"><label>Base fee per trip ({{ $cur }})</label><input class="input" type="number" min="0" step="0.01" name="delivery[baseFee]" value="{{ $dv('baseFee') }}"></div>
      <div class="field"><label>Rate per km ({{ $cur }})</label><input class="input" type="number" min="0" step="0.01" name="delivery[perKm]" value="{{ $dv('perKm') }}"></div>
      <div class="field"><label>Free kilometres</label><input class="input" type="number" min="0" step="0.1" name="delivery[freeKm]" value="{{ $dv('freeKm') }}"><div class="hint">Distance included in the base fee.</div></div>
      <div class="field"><label>Maximum distance (km)</label><input class="input" type="number" min="0" step="0.1" name="delivery[maxKm]" value="{{ $dv('maxKm') }}"><div class="hint">0 = no limit. Distance is measured from your shop city (approximate road distance).</div></div>
    </div>
  </div>

  <div class="form-grid mt-1">
    <div class="field"><label>Earlier-delivery fee per hour ({{ $cur }})</label><input class="input" type="number" min="0" step="1" name="delivery[earlyPerHour]" value="{{ $dv('earlyPerHour') ?? 100 }}"><div class="hint">When a customer asks for delivery before the standard time (rental start − setup time), per started hour earlier. You accept or reject each request; nothing is charged unless you accept.</div></div>
    <div class="field"><label>Free delivery when items total at least ({{ $cur }})</label><input class="input" type="number" min="0" step="0.01" name="delivery[freeOver]" value="{{ $dv('freeOver') }}"><div class="hint">0 = never free. Handling fees still apply.</div></div>
    <div class="field"><label>Fee covers</label><select class="input" name="delivery[roundTrip]"><option value="1" @selected((string) (int) $dv('roundTrip') === '1')>Drop-off and retrieval (round trip)</option><option value="0" @selected((string) (int) $dv('roundTrip') === '0')>One way — retrieval is charged again</option></select></div>
    <div class="field full"><label class="check"><input type="hidden" name="delivery[chargePerTrip]" value="0"><input type="checkbox" name="delivery[chargePerTrip]" value="1" @checked((string) (int) $dv('chargePerTrip') === '1')> Charge a separate trip for each block of non-consecutive rental dates</label></div>
  </div>

  @if($S::get('freeShipEnabled'))
  <div class="card card-flat mt-1" style="padding:12px">
    <label class="check"><input type="hidden" name="free_ship" value="0"><input type="checkbox" name="free_ship" value="1" @checked((string) old('free_ship', ($freeShip ?? false) ? '1' : '0') === '1')> <b>Join the Free Shipping program</b></label>
    <div class="small muted" style="padding-left:28px">Like Shopee's Free Shipping: customers who order at least {{ money($S::get('freeShipMinSpend')) }} in items from you get up to {{ money($S::get('freeShipCap')) }} off delivery, paid by PartyRent. Your shop shows a "Free Shipping" badge. In return, your commission is {{ rtrim(rtrim(number_format($S::get('freeShipExtraPct'), 2), '0'), '.') }}% higher on every order.</div>
  </div>
  @endif

  <div class="card card-flat soft mt-1" style="padding:12px">
    <div class="label mb-1">Preview</div>
    @include('partials.address-fields', ['key' => 'preview', 'value' => ['cityCode' => $previewCity ?? null], 'full' => false, 'required' => false, 'class' => 'preview-addr'])
    <div id="deliveryPreview" class="strong mt-1" aria-live="polite"><span class="muted small">Pick a city to see what a customer there would pay.</span></div>
  </div>
</div>
@push('scripts')
<script>
(function () {
  var box = document.getElementById('deliveryBox'); if (!box) return;
  var form = box.closest('form'), rows = document.getElementById('zoneRows'), next = {{ count($zoneRows) }};
  function modeSync() {
    var m = (form.querySelector('[name="delivery[mode]"]:checked') || {}).value || 'weight';
    box.querySelectorAll('[data-dmode]').forEach(function (d) { d.classList.toggle('hidden', d.dataset.dmode !== m); });
    var ful = (form.querySelector('[name="fulfillment"]:checked') || {}).value;
    box.classList.toggle('hidden', ful === 'pickup');
  }
  document.getElementById('zoneAdd').addEventListener('click', function () {
    var html = document.getElementById('zoneTpl').innerHTML.replace(/__i__/g, String(next++));
    var wrap = document.createElement('div'); wrap.innerHTML = html.trim();
    var row = wrap.firstChild; rows.appendChild(row);
    window.PRUI.AddressPicker(row.querySelector('[data-address]'));
  });
  rows.addEventListener('click', function (e) { var b = e.target.closest('[data-zone-remove]'); if (b) { b.closest('.zone-row').remove(); preview(); } });

  var timer = null, seq = 0;
  function preview() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      var out = document.getElementById('deliveryPreview');
      var city = (box.querySelector('.preview-addr [data-level="city"]') || {}).value;
      if (!city) { out.innerHTML = '<span class="muted small">Pick a city to see what a customer there would pay.</span>'; return; }
      var fd = new FormData();
      new FormData(form).forEach(function (v, k) { if (/^(delivery\[|shop_addr\[|fulfillment$)/.test(k)) fd.append(k, v); });
      fd.append('city', city);
      var mine = ++seq;
      fetch(window.PR.routes.deliveryPreview, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } })
        .then(function (r) { return r.json(); })
        .then(function (r) { if (mine !== seq) return; out.innerHTML = r.ok ? window.PRUI.esc(r.text) + (r.detail ? '<div class="small muted" style="font-weight:400">' + window.PRUI.esc(r.detail) + '</div>' : '') : '<span style="color:var(--red)">' + window.PRUI.esc(r.error) + '</span>'; })
        .catch(function () { out.textContent = 'Preview unavailable.'; });
    }, 300);
  }
  form.addEventListener('change', function (e) { modeSync(); if (e.target.closest('#deliveryBox') || /^(shop_addr|fulfillment)/.test(e.target.name || '')) preview(); });
  form.addEventListener('input', function (e) { if (e.target.closest('#deliveryBox') && e.target.type === 'number') preview(); });
  form.addEventListener('pr:city', preview);
  modeSync(); preview();
})();
</script>
@endpush
