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
    @foreach(['zones' => ['By area', 'A fee per region, province or city'], 'distance' => ['By distance', 'Base fee + rate per km from your shop']] as $m => [$l, $desc])
      <label class="card card-flat row grow" style="padding:10px 12px;cursor:pointer;min-width:220px"><input type="radio" name="delivery[mode]" value="{{ $m }}" @checked($mode === $m) style="accent-color:var(--brand);width:18px;height:18px"><div><b>{{ $l }}</b><div class="small muted">{{ $desc }}</div></div></label>
    @endforeach
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
    <div class="field"><label>Free delivery when items total at least ({{ $cur }})</label><input class="input" type="number" min="0" step="0.01" name="delivery[freeOver]" value="{{ $dv('freeOver') }}"><div class="hint">0 = never free. Handling fees still apply.</div></div>
    <div class="field"><label>Fee covers</label><select class="input" name="delivery[roundTrip]"><option value="1" @selected((string) (int) $dv('roundTrip') === '1')>Drop-off and retrieval (round trip)</option><option value="0" @selected((string) (int) $dv('roundTrip') === '0')>One way — retrieval is charged again</option></select></div>
    <div class="field full"><label class="check"><input type="hidden" name="delivery[chargePerTrip]" value="0"><input type="checkbox" name="delivery[chargePerTrip]" value="1" @checked((string) (int) $dv('chargePerTrip') === '1')> Charge a separate trip for each block of non-consecutive rental dates</label></div>
  </div>

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
    var m = (form.querySelector('[name="delivery[mode]"]:checked') || {}).value || 'zones';
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
