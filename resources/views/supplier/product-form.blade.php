@extends('layouts.dash', ['title' => $p->exists ? 'Edit product' : 'Add product', 'nav' => 'products'])

@php($tiers = old('tiers', $p->tiers ?: [['min' => 1, 'max' => null, 'price' => '']]))
@php($lt = old('listing_type', $p->listing_type ?: 'rent'))
@php($lt = old('listing_type', $p->listing_type ?: 'rent'))
@section('content')
<div class="crumbs"><a href="{{ route('supplier.products') }}">Products</a> / {{ $p->exists ? $p->name : 'New product' }}</div>
<div class="page-head"><div><h1>{{ $p->exists ? 'Edit '.$p->name : ($lt === 'sale' ? 'Add an item for sale' : 'Add a rental item') }}</h1><p>Prices you set here are the only prices customers and the AI assistant ever see.</p></div></div>
<form method="POST" action="{{ $p->exists ? route('supplier.products.update', $p) : route('supplier.products.store') }}" enctype="multipart/form-data" class="grid grid-side" id="productForm" data-type="{{ $lt }}">
  @csrf @if($p->exists) @method('PUT') @endif
  <div class="stack">
    <div class="card"><h3>Listing type</h3>
      <div class="row wrap gap-sm">
        <label class="check"><input type="radio" name="listing_type" value="rent" @checked($lt === 'rent')> <span><b>For rent</b> <span class="small muted">· returned after the event, priced per day</span></span></label>
        <label class="check"><input type="radio" name="listing_type" value="sale" @checked($lt === 'sale')> <span><b>For sale</b> <span class="small muted">· consumables (food, flowers, favors, balloons), sold once and not returned</span></span></label>
      </div>
      @if($p->exists)<div class="hint">Items with open bookings cannot switch type until those bookings are completed.</div>@endif
    </div>
    <div class="card"><h3>Details</h3>
      <div class="field"><label>Item name</label><input class="input" name="name" required minlength="3" value="{{ old('name', $p->name) }}" placeholder="e.g. White Monoblock Chair"></div>
      <div class="form-grid">
        <div class="field"><label>Category</label><select class="input" name="category_id" required><option value="">Select…</option>@foreach($cats as $c)<option value="{{ $c->id }}" @selected(old('category_id', $p->category_id) === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="field"><label>Condition</label><select class="input" name="condition">@foreach(['Excellent', 'Very Good', 'Good', 'Fair'] as $c)<option @selected(old('condition', $p->condition) === $c)>{{ $c }}</option>@endforeach</select></div>
      </div>
      <div class="field"><label>Description</label><textarea class="input" name="description" required rows="4">{{ old('description', $p->description) }}</textarea></div>
      <div class="form-grid">
        <div class="field"><label>Colors (comma-separated)</label><input class="input" name="colors" value="{{ old('colors', implode(', ', $p->colors ?? [])) }}" placeholder="White, Gold"></div>
        <div class="field"><label>Themes (comma-separated)</label><input class="input" name="themes" value="{{ old('themes', implode(', ', $p->themes ?? [])) }}" placeholder="Elegant, Classic"></div>
      </div>
      <div class="field"><label>Suitable events</label><div class="row wrap gap-sm">@foreach($events as $e)@php($on = in_array($e, old('events', $p->events ?? []), true))<label class="chip {{ $on ? 'active' : '' }}"><input type="checkbox" name="events[]" value="{{ $e }}" @checked($on) hidden>{{ $e }}</label>@endforeach</div></div>
    </div>
    <div class="card"><h3>Quantity-based pricing <span data-for="rent">(per day)</span><span data-for="sale">(per unit sold)</span></h3>
      <p class="small muted">The first tier starts at 1. Each tier starts right after the previous one ends; leave the last tier's maximum empty.</p>
      <div id="tiers">
        @foreach($tiers as $i => $t)
          <div class="form-grid tier-row" style="grid-template-columns:1fr 1fr 1fr auto;align-items:end">
            <div class="field"><label class="small">From qty</label><input class="input input-sm" type="number" min="1" name="tiers[{{ $i }}][min]" value="{{ $t['min'] ?? '' }}"></div>
            <div class="field"><label class="small">To qty</label><input class="input input-sm" type="number" min="1" name="tiers[{{ $i }}][max]" value="{{ $t['max'] ?? '' }}" placeholder="and up"></div>
            <div class="field"><label class="small">Price per unit (₱)</label><input class="input input-sm" type="number" min="0" step="0.01" name="tiers[{{ $i }}][price]" value="{{ $t['price'] ?? '' }}"></div>
            <div class="field"><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest('.tier-row').remove()" aria-label="Remove tier">{{ icon('trash') }}</button></div>
          </div>
        @endforeach
      </div>
      <button type="button" class="btn btn-sm" id="addTier">{{ icon('plus') }}Add tier</button>
    </div>
    <div class="card" data-for="sale"><h3>Ordering & freshness</h3>
      <div class="form-grid">
        <div class="field"><label>Order lead time (days)</label><input class="input" type="number" min="0" max="60" name="lead_days" value="{{ old('lead_days', $p->lead_days ?? 0) }}"><div class="hint">Customers must order at least this many days before the event.</div></div>
        <div class="field"><label>Low-stock alert at</label><input class="input" type="number" min="0" name="low_stock_at" value="{{ old('low_stock_at', $p->low_stock_at) }}" placeholder="No alert"><div class="hint">You are notified when stock on hand drops to this.</div></div>
      </div>
      <label class="check mb-1"><input type="checkbox" name="perishable" value="1" id="perishable" @checked(old('perishable', $p->perishable))> Perishable (food, fresh flowers)</label>
      <div class="field" id="shelfLife"><label>Shelf life (days)</label><input class="input" type="number" min="1" name="shelf_life_days" value="{{ old('shelf_life_days', $p->shelf_life_days) }}"><div class="hint">Shown to customers so they know how long it keeps.</div></div>
    </div>
    <div class="card"><h3>Photos</h3>
      @if($p->images)<div class="thumbs mb-1">@foreach($p->images as $im)<img src="{{ asset('storage/'.$im) }}" alt="Product photo">@endforeach</div><label class="check mb-1"><input type="checkbox" name="clear_images" value="1"> Remove current photos</label>@endif
      <input class="input" type="file" name="images[]" accept="image/*" multiple><div class="hint">Up to 5 photos, 8 MB each. Without photos, the category illustration is shown.</div>
    </div>
  </div>
  <aside class="card sticky-side"><h3>Stock & visibility</h3>
    <div class="field"><label><span data-for="rent">Total stock (units you own)</span><span data-for="sale">Stock on hand</span></label><input class="input" type="number" min="0" name="total_stock" required value="{{ old('total_stock', $p->total_stock ?? 0) }}"><div class="hint" data-for="rent">Reducing stock is blocked if it would break accepted reservations.</div><div class="hint" data-for="sale">Goes down when an order is released. Add stock later with Restock on the Inventory page.</div></div>
    <div class="field" data-for="rent"><label>Rental unit</label><select class="input" name="unit">@foreach(['piece / day', 'set / day', 'unit / day', 'pair / day', 'panel / day', 'kit / day', 'system / day'] as $un)<option @selected(old('unit', $p->unit) === $un)>{{ $un }}</option>@endforeach</select></div>
    <div class="field" data-for="sale"><label>Sold per</label><select class="input" name="unit">@foreach(\App\Models\Product::SALE_UNITS as $un)<option @selected(old('unit', $p->unit) === $un)>{{ $un }}</option>@endforeach</select></div>
    <div class="field" data-for="rent"><label for="replacement_value">Replacement value per piece (₱) <span class="muted">(recommended)</span></label><input class="input" type="number" min="0" step="0.01" id="replacement_value" name="replacement_value" value="{{ old('replacement_value', $p->replacement_value) }}" placeholder="e.g. 850">
      <div class="hint">How much it would cost you to buy one new today. Used for damage charges: lost or destroyed = this amount, major damage = {{ setting('majorDamagePct', 50) }}% of it. Customers see it before booking, and charges can never be more than this.</div>@error('replacement_value')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="field"><label>Status</label><select class="input" name="status">@foreach(['published' => 'Published (visible)', 'draft' => 'Draft', 'hidden' => 'Hidden'] as $k => $l)<option value="{{ $k }}" @selected(old('status', $p->status) === $k)>{{ $l }}</option>@endforeach</select></div>
    <label class="check mb-2"><input type="checkbox" name="delivery" value="1" @checked(old('delivery', $p->delivery ?? true))> Available for delivery</label>
    <div class="field"><label>Handling fee for delivery <span class="muted">(optional)</span></label>
      <div class="row" style="gap:6px"><input class="input" type="number" min="0" step="0.01" name="handling_fee" value="{{ old('handling_fee', $p->handling_fee ?? 0) }}" aria-label="Handling fee"><select class="input" name="handling_per" style="width:auto" aria-label="Charged">@foreach(['line' => 'per order', 'unit' => 'per unit'] as $k => $l)<option value="{{ $k }}" @selected(old('handling_per', $p->handling_per ?? 'line') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="hint">For bulky items (tents, stages, sound systems). Added to the delivery fee, even when delivery is free.</div>@error('handling_fee')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
    <div id="sizeBox" data-presets='@json(\App\Domain\ItemSizes::forJs())' data-defaults='@json(\App\Domain\ItemSizes::CATEGORY_DEFAULT)'>
      <div class="field mb-1"><label>Weight and size <span class="muted">(for delivery fees)</span></label>
        <div class="hint" style="margin-top:0">Not sure? We fill in typical values from the item name. Change them if yours is different.</div></div>
      <div id="sizeTip" class="alert alert-info mb-1 small" hidden>{{ icon('box') }}<div>Looks like a <b id="sizeTipLabel"></b>: <span id="sizeTipText"></span>. <button type="button" class="btn btn-xs" id="sizeUse">Use this</button></div></div>
      <div class="field"><label for="sizePick" class="small">Or pick a similar item</label><select class="input" id="sizePick"><option value="">Choose…</option>@foreach(\App\Domain\ItemSizes::PRESETS as $i => $sp)<option value="{{ $i }}">{{ $sp[0] }} · {{ rtrim(rtrim(number_format($sp[3], 1), '0'), '.') }} kg</option>@endforeach</select></div>
      <div class="field"><label for="weight_kg">Weight of one piece (kg)</label><input class="input" type="number" min="0" step="0.01" id="weight_kg" name="weight_kg" value="{{ old('weight_kg', $p->weight_kg) }}" placeholder="e.g. 2.5">@error('weight_kg')<div class="error-text">{{ $message }}</div>@enderror</div>
      <div class="field"><label>Size when packed for delivery (cm)</label>
        <div class="row" style="gap:6px;align-items:flex-start">
          <div style="flex:1"><input class="input" type="number" min="0" step="0.1" name="length_cm" value="{{ old('length_cm', $p->length_cm) }}" placeholder="Length" aria-label="Length in cm"><div class="hint">Length</div></div>
          <div style="flex:1"><input class="input" type="number" min="0" step="0.1" name="width_cm" value="{{ old('width_cm', $p->width_cm) }}" placeholder="Width" aria-label="Width in cm"><div class="hint">Width</div></div>
          <div style="flex:1"><input class="input" type="number" min="0" step="0.1" name="height_cm" value="{{ old('height_cm', $p->height_cm) }}" placeholder="Height" aria-label="Height in cm"><div class="hint">Height</div></div>
        </div>
        <div class="hint">Measure the item (or its box) the way it goes in the truck, e.g. a folded table lies flat. Big but light items are charged by size, like on Shopee.</div></div>
    </div>
    <button class="btn btn-primary btn-block btn-lg">{{ $p->exists ? 'Save changes' : 'Add item' }}</button>
    <a class="btn btn-block btn-ghost mt-1" href="{{ route('supplier.products') }}">Cancel</a>
  </aside>
</form>
@push('scripts')
<script>
(function () {
  var form = document.getElementById('productForm'), perish = document.getElementById('perishable');
  function sync() {
    var type = form.querySelector('[name="listing_type"]:checked').value;
    form.querySelectorAll('[data-for]').forEach(function (el) {
      var on = el.getAttribute('data-for') === type;
      el.classList.toggle("hidden", !on);
      el.querySelectorAll('input,select').forEach(function (f) { f.disabled = !on; });
    });
    document.getElementById('shelfLife').hidden = !perish.checked;
  }
  form.querySelectorAll('[name="listing_type"]').forEach(function (r) { r.addEventListener('change', sync); });
  perish.addEventListener('change', sync);
  sync();
})();
(function () {
  var box = document.getElementById('sizeBox'); if (!box) return;
  var presets = JSON.parse(box.dataset.presets), defaults = JSON.parse(box.dataset.defaults);
  var form = document.getElementById('productForm');
  var nameEl = form.querySelector('[name="name"]'), catEl = form.querySelector('[name="category_id"]');
  var f = { kg: form.querySelector('[name="weight_kg"]'), l: form.querySelector('[name="length_cm"]'), w: form.querySelector('[name="width_cm"]'), h: form.querySelector('[name="height_cm"]') };
  var tip = document.getElementById('sizeTip'), current = null;
  // fields the rental partner typed into are never overwritten automatically
  Object.keys(f).forEach(function (k) {
    if (f[k].value !== '' && +f[k].value > 0) f[k].dataset.mine = '1';
    f[k].addEventListener('input', function () { f[k].dataset.mine = f[k].value === '' ? '' : '1'; });
  });
  function guess() {
    var name = ' ' + nameEl.value.toLowerCase().replace(/[^a-z0-9]+/g, ' ') + ' ', cat = catEl.value, best = null, bestLen = 0;
    presets.forEach(function (p) { p.words.forEach(function (w) {
      var len = w.length + (p.cat === cat ? 100 : 0);
      if (name.indexOf(' ' + w + ' ') >= 0 && len > bestLen) { best = p; bestLen = len; }
    }); });
    if (!best && defaults[cat]) best = presets.filter(function (p) { return p.label === defaults[cat]; })[0] || null;
    return best;
  }
  function text(p) { return p.kg + ' kg, ' + p.l + ' × ' + p.w + ' × ' + p.h + ' cm'; }
  function fill(p, force) {
    Object.keys(f).forEach(function (k) { if (force || !f[k].dataset.mine) { f[k].value = p[k]; if (force) f[k].dataset.mine = '1'; } });
  }
  function update() {
    current = guess();
    tip.hidden = !current;
    if (!current) return;
    document.getElementById('sizeTipLabel').textContent = current.label.toLowerCase();
    document.getElementById('sizeTipText').textContent = text(current);
    fill(current, false);
  }
  var t; nameEl.addEventListener('input', function () { clearTimeout(t); t = setTimeout(update, 300); });
  catEl.addEventListener('change', update);
  document.getElementById('sizeUse').addEventListener('click', function () { if (current) fill(current, true); });
  document.getElementById('sizePick').addEventListener('change', function () {
    var p = presets[this.value]; if (!p) return;
    fill(p, true); current = p; tip.hidden = false;
    document.getElementById('sizeTipLabel').textContent = p.label.toLowerCase();
    document.getElementById('sizeTipText').textContent = text(p);
  });
  if (nameEl.value) update();
})();
document.getElementById('addTier').addEventListener('click', function () {
  var wrap = document.getElementById('tiers'), rows = wrap.querySelectorAll('.tier-row'), i = Date.now();
  var last = rows[rows.length - 1], lastMax = last ? last.querySelector('[name$="[max]"]').value : '';
  var row = (last ? last.cloneNode(true) : null);
  if (!row) return;
  row.querySelectorAll('input').forEach(function (inp) { inp.name = inp.name.replace(/tiers\[[^\]]+\]/, 'tiers[' + i + ']'); inp.value = ''; });
  if (lastMax) row.querySelector('[name$="[min]"]').value = (+lastMax + 1);
  wrap.appendChild(row);
});
</script>
@endpush
@endsection
