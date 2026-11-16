@extends('layouts.dash', ['title' => 'Add / Edit Product', 'nav' => 'products'])

{{-- Reference SP.productForm + change list C7 (Specs → Pricing → Inventory → Listing) + app: items for sale, hourly rental --}}
@php
    use App\Http\Controllers\Supplier\CatalogController as CC;
    $lt = old('listing_type', $p->listing_type ?: 'rent');
    $tiers = collect($p->tiers ?: [['min' => 1, 'max' => null, 'price' => '']])->sortBy('min')->values();
    $base = old('base_price', $tiers->first()['price'] ?? '');
    $bulk = old('bulk', $tiers->slice(1)->map(fn ($t) => ['min' => $t['min'], 'price' => $t['price']])->values()->all());
    $colors = old('colors', array_values(array_intersect($p->colors ?? [], CC::COLORS)));
    $colorsOther = old('colors_other', implode(', ', array_diff($p->colors ?? [], CC::COLORS)));
    $themes = old('themes', array_values(array_intersect($p->themes ?? [], CC::THEMES)));
    $themesOther = old('themes_other', implode(', ', array_diff($p->themes ?? [], CC::THEMES)));
    $evs = old('events', $p->events ?? []);
    $mode = old('rental_mode', $p->rental_mode ?? 'day');
    $sup = auth()->user()->shop();
    $locs = $sup->locations()->get();
    $ts = \App\Domain\Slots::settings($sup);
    $frame = old('time_frame', (bool) $p->time_from);
    $hasMax = old('has_max_days', (bool) $p->max_rental_days);
    $b = $p->exists ? inv()->breakdown($p->id, today_iso()) : null;
    $peak = $p->exists ? max(0, ...array_map(fn ($d) => inv()->demandOn($p->id, $d, \App\Domain\Inventory::RESERVING)['total'], inv()->reservedDates($p->id) ?: [today_iso()])) : 0;
    $chip = fn ($name, $val, $on) => '<label class="chip '.($on ? 'active' : '').'"><input type="checkbox" name="'.$name.'[]" value="'.e($val).'" '.($on ? 'checked' : '').' hidden>'.($name === 'colors' ? '<span class="swatch" style="background:'.e(color_hex($val)).'"></span> ' : '').e($val).'</label>';
@endphp
@section('content')
<div class="crumbs"><a href="{{ route('supplier.products') }}">Products</a> / {{ $p->exists ? $p->name : 'New product' }}</div>
<div class="page-head"><div><h1>{{ $p->exists ? 'Edit product' : ($lt === 'sale' ? 'Add an item for sale' : 'Add product') }}</h1><p>{{ $p->exists ? '#'.$p->id.' · created '.fmt_date($p->created_at) : 'Prices you set here are the only prices customers and the AI assistant ever see.' }}</p></div>
  @if($p->exists)<a class="btn btn-sm" href="{{ route('products.show', $p) }}" target="_blank">{{ icon('eye') }}View listing</a>@endif</div>
<form method="POST" action="{{ $p->exists ? route('supplier.products.update', $p) : route('supplier.products.store') }}" enctype="multipart/form-data" class="grid grid-side" id="productForm">
  @csrf @if($p->exists) @method('PUT') @endif
  <div class="stack">
    {{-- Specs --}}
    <div class="card"><h3>Basic information</h3>
      <div class="field"><label>Listing type</label><div class="row wrap gap-sm">
        <label class="check"><input type="radio" name="listing_type" value="rent" @checked($lt === 'rent')> <span><b>For rent</b> <span class="small muted">· returned after the event</span></span></label>
        <label class="check"><input type="radio" name="listing_type" value="sale" @checked($lt === 'sale')> <span><b>For sale</b> <span class="small muted">· consumables sold once (food, flowers, favors)</span></span></label>
      </div>@if($p->exists)<div class="hint">Items with open bookings cannot switch type until those bookings are completed.</div>@endif</div>
      <div class="field"><label>Item name</label><input class="input" name="name" required minlength="3" value="{{ old('name', $p->name) }}" placeholder="e.g. White Monoblock Chair"></div>
      <div class="form-grid">
        <div class="field"><label>Category</label><select class="input" name="category_id" required><option value="">Select…</option>@foreach($cats as $c)<option value="{{ $c->id }}" @selected(old('category_id', $p->category_id) === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="field" data-for="rent"><label>Condition</label><select class="input" name="condition">@foreach(\App\Domain\Damage::CONDITIONS as $k => $l)<option value="{{ $k }}" @selected(old('condition', $p->condition ?: 'brand_new') === $k)>{{ $l }}</option>@endforeach</select><div class="hint">Used for "Lost or Not Returned" compensation.</div></div>
      </div>
      <div class="field" data-for="rent"><label>Replacement value (₱ per unit)</label><input class="input" type="number" min="0" step="0.01" name="replacement_value" value="{{ old('replacement_value', $p->replacement_value) }}" style="max-width:300px"><div class="hint">The selling / replacement value — the basis for damage compensation (not the rental fee).</div>@error('replacement_value')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
      <div class="field"><label>Description</label><textarea class="input" name="description" required rows="4">{{ old('description', $p->description) }}</textarea></div>
      <div class="field"><label>Product images</label>
        @if($p->images)<div class="thumbs mb-1">@foreach($p->images as $im)<img src="{{ asset('storage/'.$im) }}" alt="Product photo">@endforeach</div><label class="check mb-1"><input type="checkbox" name="clear_images" value="1"> Remove current photos</label>@endif
        <input class="input" type="file" name="images[]" accept="image/*" multiple><div class="hint">Upload up to 5 photos (JPG/PNG, 8 MB each). Without photos, a category illustration is shown.</div></div>
      <h4 class="mt-2">Tags</h4>
      <div class="field"><label>Colors</label><div class="row wrap gap-sm">@foreach(CC::COLORS as $c){!! $chip('colors', $c, in_array($c, $colors, true)) !!}@endforeach<label class="chip {{ $colorsOther ? 'active' : '' }}" data-other="colorsOther"><input type="checkbox" hidden @checked($colorsOther)>Others</label></div>
        <input class="input mt-1" id="colorsOther" name="colors_other" value="{{ $colorsOther }}" placeholder="Other colors, comma-separated (e.g. Lavender, Sage)" @if(! $colorsOther) hidden @endif></div>
      <div class="field"><label>Themes</label><div class="row wrap gap-sm">@foreach(CC::THEMES as $t){!! $chip('themes', $t, in_array($t, $themes, true)) !!}@endforeach<label class="chip {{ $themesOther ? 'active' : '' }}" data-other="themesOther"><input type="checkbox" hidden @checked($themesOther)>Others</label></div>
        <input class="input mt-1" id="themesOther" name="themes_other" value="{{ $themesOther }}" placeholder="Other themes, comma-separated (e.g. Boho, Tropical)" @if(! $themesOther) hidden @endif></div>
      <div class="field"><label>Suitable events</label><div class="row wrap gap-sm">@foreach($events as $e){!! $chip('events', $e, in_array($e, $evs, true)) !!}@endforeach</div></div>
    </div>

    {{-- Pricing --}}
    <div class="card"><h3>Pricing</h3>
      <div class="form-grid">
        <div class="field" data-for="rent"><label>How is it rented?</label><select class="input" name="rental_mode" id="rentalMode"><option value="day" @selected($mode === 'day')>Per day</option><option value="hour" @selected($mode === 'hour')>By the hour</option></select></div>
        <div class="field" data-for="rent"><label>Pricing unit</label><select class="input" name="unit">@foreach(['piece', 'set', 'unit', 'pair', 'panel', 'kit', 'system'] as $un)<option value="{{ $un }} / day" @selected(explode(' /', old('unit', $p->unit ?? 'piece'))[0] === $un)>{{ $un }}</option>@endforeach</select></div>
        <div class="field" data-for="sale"><label>Sold per</label><select class="input" name="unit">@foreach(\App\Models\Product::SALE_UNITS as $un)<option @selected(old('unit', $p->unit) === $un)>{{ $un }}</option>@endforeach</select></div>
      </div>
      <div class="field"><label>Base price (₱ per unit<span data-for="rent"> per <span data-unit-word>{{ $mode === 'hour' ? 'hour' : 'day' }}</span></span>)</label><input class="input" type="number" min="0.01" step="0.01" name="base_price" required value="{{ $base }}" style="max-width:300px">@error('base_price')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
      <div class="label mt-1">Bulk discount <span class="muted">(optional)</span></div>
      <p class="small muted">Cheaper per unit when a customer rents more. Each row applies from that quantity up; the price that fits each date's quantity is used automatically.</p>
      <div id="bulk">
        @foreach(array_merge($bulk, [['min' => '', 'price' => '']]) as $i => $t)
          <div class="row mb-1 bulk-row" style="gap:8px;align-items:center" data-row><span class="small muted">Quantity</span><input class="input input-sm" type="number" min="2" name="bulk[{{ $i }}][min]" value="{{ $t['min'] }}" style="width:100px" aria-label="From quantity"><span class="small muted">pcs and up →</span><input class="input input-sm" type="number" min="0.01" step="0.01" name="bulk[{{ $i }}][price]" value="{{ $t['price'] }}" style="width:120px" aria-label="Price per unit"><span class="small muted">each</span><button type="button" class="btn btn-xs btn-ghost" data-rmrow aria-label="Remove">{{ icon('trash') }}</button></div>
        @endforeach
      </div>
      @error('bulk')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
      <button type="button" class="btn btn-sm" data-addrow="#bulk">{{ icon('plus') }}Add bulk discount</button>
      <p class="small muted mt-1 mb-0">Changes apply to new requests only; accepted requests keep their quoted price.</p>
    </div>

    {{-- Sale-only: ordering & freshness --}}
    <div class="card" data-for="sale"><h3>Ordering & freshness</h3>
      <div class="form-grid">
        <div class="field"><label>Order lead time (days)</label><input class="input" type="number" min="0" max="60" name="lead_days" value="{{ old('lead_days', $p->lead_days ?? 0) }}"><div class="hint">Customers must order at least this many days before the event.</div></div>
        <div class="field"><label>Low-stock alert at</label><input class="input" type="number" min="0" name="low_stock_at" value="{{ old('low_stock_at', $p->low_stock_at) }}" placeholder="No alert"></div>
      </div>
      <label class="check mb-1"><input type="checkbox" name="perishable" value="1" id="perishable" @checked(old('perishable', $p->perishable))> Perishable (food, fresh flowers)</label>
      <div class="field" id="shelfLife"><label>Shelf life (days)</label><input class="input" type="number" min="1" name="shelf_life_days" value="{{ old('shelf_life_days', $p->shelf_life_days) }}"></div>
    </div>
  </div>

  <aside class="stack">
    {{-- Inventory --}}
    <div class="card"><h3>Inventory</h3>
      <div data-for="rent">
        @if($locs->isNotEmpty())
          <div class="label mb-1">Quantity per inventory location</div>
          @foreach($locs as $i => $l)
            <div class="row between mb-1" style="gap:10px"><div><b class="small">{{ $l->name }}</b><div class="small muted">{{ $l->address }}</div></div><input class="input" type="number" min="0" name="qty[{{ $l->id }}]" data-loc value="{{ old('qty.'.$l->id, $p->exists ? inv()->stockAt($p->id, $l->id) : ($i === 0 ? '' : 0)) }}" style="width:100px" aria-label="Quantity at {{ $l->name }}"></div>
          @endforeach
          <div class="sum-line strong"><span>Total inventory</span><span data-tot>{{ $p->total_stock ?? 0 }}</span></div>
          @if($p->exists)<p class="small">Highest accepted reservation at one time: <b>{{ $peak }}</b>. A location cannot go below the bookings allocated to it. <a href="{{ route('supplier.locations') }}">Manage locations</a></p>@endif
        @else
          <div class="field"><label>Quantity (units you own)</label><input class="input" type="number" min="0" name="total_stock" value="{{ old('total_stock', $p->total_stock ?? 0) }}"></div>
        @endif
      </div>
      <div class="field" data-for="sale"><label>Stock on hand</label><input class="input" type="number" min="0" name="total_stock" value="{{ old('total_stock', $p->total_stock ?? 0) }}"></div>
      @if($b)
        <dl class="kv small mt-1"><dt>Available today</dt><dd>{{ $b['available'] }}</dd><dt>Reserved today</dt><dd>{{ $b['reserved'] }}</dd>@unless($p->isSale())<dt>Damaged</dt><dd>{{ $b['damaged'] }}</dd><dt>Missing</dt><dd>{{ $b['missing'] }}</dd><dt>Maintenance</dt><dd>{{ $b['maintenance'] }}</dd><dt>Set aside</dt><dd>{{ $b['setaside'] }}</dd>@endunless</dl>
        <a class="small strong" href="{{ route('supplier.inventory') }}">Manage damaged / maintenance →</a>
      @endif
    </div>

    {{-- Listing --}}
    <div class="card"><h3>Listing</h3>
      <div class="field"><label>Status</label><select class="input" name="status">@foreach(['active' => 'Active (visible to customers)', 'inactive' => 'Inactive (hidden)'] as $k => $l)<option value="{{ $k }}" @selected(old('status', $p->status ?: 'active') === $k)>{{ $l }}</option>@endforeach</select></div>
      <label class="check mb-2"><input type="checkbox" name="delivery" value="1" @checked(old('delivery', $p->delivery ?? true))> Available for delivery</label>
      <div data-for="rent">
        <div class="field"><label>Maximum rental duration <span class="muted">(optional)</span></label>
          <input type="hidden" name="has_max_days" value="{{ $hasMax ? 1 : 0 }}" id="hasMax">
          <button type="button" class="btn btn-sm" id="addMax" @if($hasMax) hidden @endif>{{ icon('plus') }}Add maximum</button>
          <div class="row" style="gap:8px" id="maxBox" @if(! $hasMax) hidden @endif><input class="input" type="number" min="1" max="365" name="max_rental_days" value="{{ old('max_rental_days', $p->max_rental_days) }}" style="width:110px" placeholder="e.g. 1"><span class="small muted">consecutive day(s) per booking</span><button type="button" class="btn btn-xs btn-ghost" id="rmMax" aria-label="Remove maximum">{{ icon('x') }}</button></div>
          <div class="hint">Without a maximum, normal rental rules apply. A limit doesn't change stock or availability; customers can still make separate bookings for other dates.</div></div>
        <div class="field"><label>Rental time limits <span class="muted">(optional)</span></label><div class="row" style="gap:8px"><input class="input" type="number" min="0.5" max="720" step="0.5" name="min_hours" value="{{ old('min_hours', $p->min_hours ?: '') }}" placeholder="Min. hours"><input class="input" type="number" min="0.5" max="720" step="0.5" name="max_hours" value="{{ old('max_hours', $p->max_hours) }}" placeholder="Max. hours"></div>
          <div class="hint">Minimum and maximum hours per booking (from start time to end time). Leave blank for no limit.</div>@error('max_hours')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
        <div class="field"><label>Rental time frame</label>
          <div class="small muted mb-1">Shop default: {{ \App\Domain\Slots::fmt12($ts['open']) }} – {{ \App\Domain\Slots::fmt12($ts['close']) }} (<a href="{{ route('supplier.shop', ['tab' => 'hours']) }}">Shop settings</a>)</div>
          <label class="check"><input type="checkbox" name="time_frame" value="1" id="tfOn" @checked($frame)> Set specific time frame for this item</label>
          <div class="row mt-1" style="gap:8px" id="tfBox" @if(! $frame) hidden @endif>
            @foreach(['time_from' => $p->time_from ?: $ts['open'], 'time_to' => $p->time_to ?: $ts['close']] as $k => $val)<select class="input" name="{{ $k }}" aria-label="{{ $k === 'time_from' ? 'From' : 'To' }}">@foreach(\App\Domain\Slots::options($sup) as $o)<option value="{{ $o }}" @selected(old($k, $val) === $o)>{{ \App\Domain\Slots::fmt12($o) }}</option>@endforeach</select>@endforeach
          </div>@error('time_to')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
          <label class="check mt-1"><input type="checkbox" name="allow_overnight" value="1" @checked(old('allow_overnight', $p->allow_overnight))> Allow overnight rental</label></div>
        <div class="field"><label>Item rental policy <span class="muted">(optional)</span></label><textarea class="input" name="rental_policy" rows="3" placeholder="e.g. Indoor use only">{{ old('rental_policy', $p->rental_policy) }}</textarea></div>
      </div>
    </div>
    <button class="btn btn-primary btn-block btn-lg">{{ $p->exists ? 'Save changes' : 'Add product' }}</button>
    <a class="btn btn-block btn-ghost" href="{{ route('supplier.products') }}">Cancel</a>
  </aside>
</form>
@push('scripts')
<script>
(function () {
  var f = document.getElementById('productForm'), $ = function (id) { return document.getElementById(id); };
  function sync() {
    var type = f.querySelector('[name="listing_type"]:checked').value;
    f.querySelectorAll('[data-for]').forEach(function (el) { var on = el.dataset.for === type; el.classList.toggle('hidden', !on); el.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; }); });
    if ($('perishable')) $('shelfLife').hidden = !$('perishable').checked;
    var word = ($('rentalMode') || {}).value === 'hour' ? 'hour' : 'day';
    f.querySelectorAll('[data-unit-word]').forEach(function (s) { s.textContent = word; });
  }
  f.addEventListener('change', function (e) {
    if (e.target.matches('[name=listing_type], #perishable, #rentalMode')) sync();
    if (e.target.id === 'tfOn') $('tfBox').hidden = !e.target.checked;
    var other = e.target.closest('[data-other]'); if (other) { var inp = $(other.dataset.other); inp.hidden = !e.target.checked; if (!e.target.checked) inp.value = ''; else inp.focus(); }
  });
  f.addEventListener('input', function (e) { if (e.target.matches('[data-loc]')) { var t = 0; f.querySelectorAll('[data-loc]').forEach(function (i) { t += Math.max(0, Math.floor(+i.value || 0)); }); f.querySelector('[data-tot]').textContent = t; } });
  f.addEventListener('click', function (e) {
    if (e.target.closest('#addMax')) { $('addMax').hidden = true; $('maxBox').hidden = false; $('hasMax').value = 1; $('maxBox').querySelector('input').focus(); }
    if (e.target.closest('#rmMax')) { $('addMax').hidden = false; $('maxBox').hidden = true; $('hasMax').value = 0; }
    var rm = e.target.closest('[data-rmrow]'); if (rm) { var rows = f.querySelectorAll('#bulk [data-row]'); if (rows.length > 1) rm.closest('[data-row]').remove(); else rm.closest('[data-row]').querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
    var add = e.target.closest('[data-addrow]'); if (add) { var box = f.querySelector(add.dataset.addrow), last = box.querySelector('[data-row]:last-child'), c = last.cloneNode(true), n = Date.now();
      c.querySelectorAll('input').forEach(function (i) { i.name = i.name.replace(/\[\d+\]/, '[' + n + ']'); i.value = ''; }); box.appendChild(c); c.querySelector('input').focus(); }
  });
  sync();
})();
</script>
@endpush
@endsection
