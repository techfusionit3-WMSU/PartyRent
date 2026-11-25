@extends('layouts.dash', ['title' => 'Add / Edit Product', 'nav' => 'products'])

{{-- Reference SP.productForm + change list C7 (Specs → Pricing → Inventory → Listing) + app: items for sale, hourly rental --}}
@php
    use App\Http\Controllers\Supplier\CatalogController as CC;
    $lt = old('listing_type', $p->listing_type ?: 'rent');
    $tiers = collect($p->tiers ?: [['min' => 1, 'max' => null, 'price' => '']])->sortBy('min')->values();
    $base = old('base_price', $tiers->first()['price'] ?? '');
    // bulk discount tiers as "from N pieces, X% off" (older tiers saved a price only: their percent is worked out)
    $bulk = array_values(array_filter((array) old('bulk', $p->exists ? \App\Domain\Pricing::bulkSteps($p) : []), fn ($t) => ($t['min'] ?? '') !== '' || ($t['pct'] ?? '') !== ''));
    $colors = old('colors', $p->colors ?? []);
    $themes = old('themes', $p->themes ?? []);
    $evs = old('events', $p->events ?? []);
    $reqCat = $p->requested_category_id ? \App\Models\Category::find($p->requested_category_id) : null;
    $catName = old('category', $reqCat?->name ?? ($p->category_id ? $cats->firstWhere('id', $p->category_id)?->name : ''));
    $mode = old('rental_mode', $p->rental_mode ?? 'day');
    $sup = auth()->user()->shop();
    $locs = $sup->locations()->get();
    $ts = \App\Domain\Slots::settings($sup);
    $frame = old('time_frame', (bool) $p->time_from);
    $hasMax = old('has_max_days', (bool) $p->max_rental_days);
    $b = $p->exists ? inv()->breakdown($p->id, today_iso()) : null;
    $peak = $p->exists ? max(0, ...array_map(fn ($d) => inv()->demandOn($p->id, $d, \App\Domain\Inventory::RESERVING)['total'], inv()->reservedDates($p->id) ?: [today_iso()])) : 0;
@endphp
@section('content')
<div class="crumbs"><a href="{{ route('supplier.products') }}">Products</a> / {{ $p->exists ? $p->name : 'New product' }}</div>
<div class="page-head"><div><h1>{{ $p->exists ? 'Edit product' : ($lt === 'sale' ? 'Add an item for sale' : 'Add product') }}</h1><p>{{ $p->exists ? '#'.$p->id.' · created '.fmt_date($p->created_at) : 'Prices you set here are the only prices customers and the AI assistant ever see.' }}</p></div>
  @if($p->exists)<a class="btn btn-sm" href="{{ route('products.show', $p) }}" target="_blank">{{ icon('eye') }}View listing</a>@endif</div>
<form method="POST" action="{{ $p->exists ? route('supplier.products.update', $p) : route('supplier.products.store') }}" data-no-enter enctype="multipart/form-data" class="grid grid-side" id="productForm" data-wizard data-draft="{{ $p->exists ? 'product-'.$p->id : 'product-new' }}">
  @csrf @if($p->exists) @method('PUT') @endif
  <div class="stack">
    {{-- Specs --}}
    <div class="card" data-wiz-step="{{ __('Item') }}"><h3>Basic information</h3>
      <div class="field"><label>Listing type</label><div class="row wrap gap-sm">
        <label class="check"><input type="radio" name="listing_type" value="rent" @checked($lt === 'rent')> <span><b>For rent</b> <span class="small muted">· returned after the event</span></span></label>
        <label class="check"><input type="radio" name="listing_type" value="sale" @checked($lt === 'sale')> <span><b>For sale</b> <span class="small muted">· consumables sold once (food, flowers, favors)</span></span></label>
      </div>@if($p->exists)<div class="hint">Items with open bookings cannot switch type until those bookings are completed.</div>@endif</div>
      <div class="field"><label>Item name</label><input class="input" name="name" required minlength="3" value="{{ old('name', $p->name) }}" placeholder="e.g. White Monoblock Chair"></div>
      <div class="form-grid">
        <div class="field @error('category') invalid @enderror"><label for="pCat">Category</label><input class="input" id="pCat" name="category" list="pCatList" required maxlength="60" autocomplete="off" value="{{ $catName }}" placeholder="Type or pick, e.g. Chairs, Photo Booths"><datalist id="pCatList">@foreach($cats as $c)<option value="{{ $c->name }}">@endforeach</datalist>
          @error('category')<div class="error-text">{{ $message }}</div>@enderror
          <div class="hint">@if($reqCat)<b>"{{ $reqCat->name }}"</b> is waiting for PartyRent to approve it; until then this item shows under Other Party Supplies.@else Pick a suggestion, or type a new one. A new category is reviewed by PartyRent; until then the item shows under Other Party Supplies.@endif</div></div>
        <div class="field" data-for="rent"><label>Condition</label><select class="input" name="condition">@foreach(\App\Domain\Damage::CONDITIONS as $k => $l)<option value="{{ $k }}" @selected(old('condition', $p->condition ?: 'brand_new') === $k)>{{ $l }}</option>@endforeach</select><div class="hint">Used for "Lost or Not Returned" compensation.</div></div>
      </div>
      <div class="field"><label>Description</label><textarea class="input" name="description" required rows="4">{{ old('description', $p->description) }}</textarea></div>
      @include('partials.image-gallery', ['name' => 'images', 'current' => $p->images ?? [], 'max' => 8, 'label' => 'Product photos'])
      <h4 class="mt-2">Tags</h4>
      <div class="field"><label>Colors</label>@include('partials.tag-input', ['name' => 'colors', 'values' => $colors, 'suggest' => CC::COLORS, 'swatch' => true, 'placeholder' => 'Type a color, e.g. Lavender'])</div>
      <div class="field"><label>Themes</label>@include('partials.tag-input', ['name' => 'themes', 'values' => $themes, 'suggest' => CC::THEMES, 'placeholder' => 'Type a theme, e.g. Boho'])</div>
      <div class="field"><label>Suitable events</label>@include('partials.tag-input', ['name' => 'events', 'values' => $evs, 'suggest' => $events, 'placeholder' => 'Type an event, e.g. Graduation'])</div>
    </div>

    {{-- Pricing --}}
    <div class="card" data-wiz-step="{{ __('Price') }}"><h3>Pricing</h3>
      <div class="form-grid">
        <div class="field" data-for="rent"><label>How is it rented?</label><select class="input" name="rental_mode" id="rentalMode"><option value="day" @selected($mode === 'day')>Per day</option><option value="hour" @selected($mode === 'hour')>By the hour</option></select></div>
        @php($uw = old('unit_custom') ? 'other' : trim(explode('/', old('unit', $p->unit ?? 'piece'))[0]))
        @php($uCustom = old('unit_custom', in_array($uw, array_merge(\App\Models\Product::STOCK_UNITS, \App\Models\Product::SALE_UNITS), true) ? '' : $uw))
        <div class="field" data-for="rent"><label for="stockUnitR">Stock unit</label><select class="input" id="stockUnitR" name="unit" data-unit-sel>@foreach(\App\Models\Product::STOCK_UNITS as $un)<option value="{{ $un }}" @selected($uw === $un)>{{ $un === 'piece' ? 'Piece (pc)' : ucfirst($un) }}</option>@endforeach<option value="other" @selected($uCustom !== '' || $uw === 'other')>Other (type your own)</option></select><div class="hint">How you count this item, e.g. 50 pcs or 12 sets. It is also the unit of the price.</div></div>
        <div class="field" data-for="sale"><label for="stockUnitS">Stock unit</label><select class="input" id="stockUnitS" name="unit" data-unit-sel>@foreach(\App\Models\Product::SALE_UNITS as $un)<option value="{{ $un }}" @selected($uw === $un)>{{ $un === 'pc' ? 'Piece (pc)' : ucfirst($un) }}</option>@endforeach<option value="other" @selected($uCustom !== '' || $uw === 'other')>Other (type your own)</option></select><div class="hint">How you count and sell it, e.g. 20 packs.</div></div>
        <div class="field" data-unit-custom @if($uCustom === '' && $uw !== 'other') hidden @endif><label for="unitCustom">Your stock unit</label><input class="input" id="unitCustom" name="unit_custom" maxlength="20" value="{{ $uCustom }}" placeholder="e.g. roll, meter, tray">@error('unit_custom')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
      </div>
      <div class="field"><label>Base price (₱ per unit<span data-for="rent"> per <span data-unit-word>{{ $mode === 'hour' ? 'hour' : 'day' }}</span></span>)</label><input class="input" type="number" min="0.01" step="0.01" name="base_price" required value="{{ $base }}" style="max-width:300px">@error('base_price')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
      <div class="mt-2">@include('partials.bulk-editor', ['rows' => $bulk, 'stock' => null, 'errorText' => $errors->first('bulk') ?: $errors->first('bulk.*')])</div>
      <p class="small muted mt-1 mb-0">Price changes only affect new requests. Requests you already received keep their price.</p>
    </div>

    {{-- Damage & replacement (rental items): on the Price step, after the price --}}
      @if(auth()->user()->shop()?->isBusiness())
      <div class="card" data-for="rent" data-wiz-step="{{ __('Price') }}"><h3>Damage fees</h3><p class="small muted">What a customer pays only if this item comes back damaged or missing (not part of the rental price). Your damage policy sets how much of this value a repair costs. Each rental keeps the value from the day it was booked.</p><div class="field"><label>Item value for damage fees (₱ per unit){{ term('replacement_value') }}</label><input class="input" type="number" min="0" step="0.01" name="replacement_value" value="{{ old('replacement_value', $p->replacement_value) }}" style="max-width:300px"><div class="hint">The selling / replacement value — the basis for damage compensation (not the rental fee).</div>@error('replacement_value')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div></div>
      @else
      {{-- Individual shops: purchase record → suggested replacement value (lowered for age and condition) + minor fee --}}
      <div class="card" data-for="rent" id="dmgBox" data-wiz-step="{{ __('Price') }}">
        <h3>Damage fees</h3>
        <p class="small muted">What a customer pays only if this item comes back damaged or missing (not part of the rental price). Customers see these before they book, and each rental keeps the fees from the day it was booked. A repair costs what the repair costs, never more than the lost-or-beyond-repair fee; a normal fault (not working, not misused) is free.</p>
        <div class="form-grid">
          <div class="field"><label for="buyPrice">Price you paid (₱ per unit) <span class="muted">(optional)</span></label><input class="input" type="number" min="0" step="0.01" id="buyPrice" name="purchase_price" value="{{ old('purchase_price', $p->purchase_price) }}">@error('purchase_price')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
          <div class="field"><label for="buyDate">When you bought it</label><input class="input" type="date" id="buyDate" name="purchased_on" max="{{ today_iso() }}" value="{{ old('purchased_on', $p->purchased_on?->format('Y-m-d')) }}">@error('purchased_on')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
        </div>
        <div class="field"><label for="buyReceipt">Receipt <span class="muted">(optional, private)</span></label><input class="input" type="file" id="buyReceipt" name="purchase_receipt" accept="image/*,application/pdf">
          <div class="hint">Only PartyRent admins see it, and only if a customer disputes a charge. Customers just see "Receipt on file".@if($p->purchase_receipt) <a href="{{ route('supplier.products.receipt', $p) }}" target="_blank" rel="noopener">View current receipt</a> · <label class="check" style="display:inline-flex"><input type="checkbox" name="clear_receipt" value="1"> remove</label>@endif</div>
          @error('purchase_receipt')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
        <div class="form-grid">
          <div class="field"><label for="repValue">Lost or beyond repair (₱ per unit){{ term('replacement_value') }}</label><input class="input" type="number" min="0" step="0.01" id="repValue" name="replacement_value" value="{{ old('replacement_value', $p->replacement_value) }}">
            <div class="hint" id="sugHint">Enter the price you paid to get a suggestion.</div>@error('replacement_value')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
          <div class="field"><label for="minorFee">Minor damage (₱ per unit) <span class="muted">(optional)</span></label><input class="input" type="number" min="0" step="0.01" id="minorFee" name="minor_fee" value="{{ old('minor_fee', $p->minor_fee) }}"><div class="hint">For cleaning, a stain or a small scratch.</div>@error('minor_fee')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
        </div>
        <div class="field" id="reasonBox" hidden><label for="valReason">Why is the value above what you paid?</label><input class="input" id="valReason" name="value_reason" maxlength="255" value="{{ old('value_reason', $p->value_reason) }}" placeholder="e.g. Same chair now costs ₱1,200 at the store (link)">@error('value_reason')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
      </div>
      @endif

  </div>

  <aside class="stack">
    {{-- Inventory --}}
    <div class="card" data-wiz-step="{{ __('Stock & listing') }}"><h3>Inventory</h3>
      <div data-for="rent">
        @if($locs->isNotEmpty())
          <div class="label mb-1">{{ $locs->count() > 1 ? 'How many you have at each branch' : 'How many you have' }}</div>
          @if($locs->count() > 1)<p class="small muted mt-0">Customers rent from one branch at a time, so each branch's count is kept separately.</p>@endif
          @foreach($locs as $i => $l)
            <div class="row between mb-1" style="gap:10px"><div><b class="small">{{ $l->name }}</b><div class="small muted">{{ $l->address }}</div></div><input class="input" type="number" min="0" name="qty[{{ $l->id }}]" data-loc value="{{ old('qty.'.$l->id, $p->exists ? inv()->stockAt($p->id, $l->id) : ($i === 0 ? '' : 0)) }}" style="width:100px" aria-label="Quantity at {{ $l->name }}"></div>
          @endforeach
          <div class="sum-line strong"><span>{{ $locs->count() > 1 ? 'Total at all branches' : 'Total' }}</span><span data-tot>{{ $p->total_stock ?? 0 }}</span></div>
          @if($p->exists)<p class="small muted">Most booked at one time: <b>{{ $peak }}</b>. A branch can't go below what is already booked there. <a href="{{ route('supplier.locations') }}">Manage branches</a></p>@endif
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

    {{-- Sale-only: ordering & freshness --}}
    <div class="card" data-for="sale" data-wiz-step="{{ __('Stock & listing') }}"><h3>Ordering & freshness</h3>
      <div class="form-grid">
        <div class="field"><label>Order lead time (days)</label><input class="input" type="number" min="0" max="60" name="lead_days" value="{{ old('lead_days', $p->lead_days ?? 0) }}"><div class="hint">Customers must order at least this many days before the event.</div></div>
        <div class="field"><label>Low-stock alert at</label><input class="input" type="number" min="0" name="low_stock_at" value="{{ old('low_stock_at', $p->low_stock_at) }}" placeholder="No alert"></div>
      </div>
      <label class="check mb-1"><input type="checkbox" name="perishable" value="1" id="perishable" @checked(old('perishable', $p->perishable))> Perishable (food, fresh flowers)</label>
      <div class="field" id="shelfLife"><label>Shelf life (days)</label><input class="input" type="number" min="1" name="shelf_life_days" value="{{ old('shelf_life_days', $p->shelf_life_days) }}"></div>
    </div>

    {{-- Listing --}}
    <div class="card" data-wiz-step="{{ __('Stock & listing') }}"><h3>Listing</h3>
      <div class="field"><label>Status</label><select class="input" name="status">@foreach(['active' => 'Active (visible to customers)', 'inactive' => 'Inactive (hidden)'] as $k => $l)<option value="{{ $k }}" @selected(old('status', $p->status ?: 'active') === $k)>{{ $l }}</option>@endforeach</select></div>
      @unless(auth()->user()->shop()?->isBusiness())
        <label class="check"><input type="hidden" name="package_only" value="0"><input type="checkbox" name="package_only" value="1" @checked(old('package_only', $p->package_only))> Only available inside my packages</label>
        <div class="hint">Not listed on its own: customers cannot find it in Browse or rent it separately, only as part of a package that includes it.</div>
      @endunless
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
        <div class="field"><label for="setupMin">Allocated setup time <span class="muted">(optional)</span></label>
          <div class="row" style="gap:8px;align-items:center"><input class="input" type="number" id="setupMin" name="setup_minutes" min="0" max="1440" step="5" value="{{ old('setup_minutes', $p->setup_minutes) }}" placeholder="0" style="width:120px" data-setup-min><span class="small muted">minutes</span></div>
          <div class="hint" data-setup-hint>How long before the rental start this item must arrive to be set up. Example: rental at 8:00 AM with 60 minutes → delivered by 7:00 AM. Checkout works it out for the customer.</div>
          @error('setup_minutes')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
        <div class="field"><label>Item rental policy <span class="muted">(optional)</span></label><textarea class="input" name="rental_policy" rows="3" placeholder="e.g. Indoor use only">{{ old('rental_policy', $p->rental_policy) }}</textarea></div>
      </div>
    </div>
    <div data-wiz-nav></div>
    <button class="btn btn-primary btn-block btn-lg" data-wiz-submit>{{ $p->exists ? 'Save changes' : 'Add product' }}</button>
    <a class="btn btn-block btn-ghost" href="{{ route('supplier.products') }}">Cancel</a>
  </aside>
</form>
@push('scripts')
<script>
// Suggested replacement value = price paid × age (<1 yr 100%, 1–2 85%, 2–3 70%, older 55%) × condition (new 100%, good as new 90%, used 75%), at least 30%.
(function () {
  var box = document.getElementById('dmgBox'); if (!box) return;
  var price = document.getElementById('buyPrice'), date = document.getElementById('buyDate'), val = document.getElementById('repValue'), hint = document.getElementById('sugHint'), reason = document.getElementById('reasonBox');
  var cond = document.querySelector('[name="condition"]');
  var peso = function (n) { return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function suggest() {
    var p = +price.value || 0;
    if (p <= 0) { hint.textContent = 'Enter the price you paid to get a suggestion.'; reason.hidden = true; return null; }
    var yrs = date.value ? (Date.now() - new Date(date.value).getTime()) / (365.25 * 864e5) : 0;
    var age = yrs < 1 ? 1 : yrs < 2 ? 0.85 : yrs < 3 ? 0.7 : 0.55, c = { brand_new: 1, good_as_new: 0.9, used: 0.75 }[cond.value] || 0.75;
    var s = Math.round(Math.max(p * 0.3, p * age * c) * 100) / 100;
    hint.innerHTML = 'Suggested: <b>' + peso(s) + '</b> (price paid, lowered ' + Math.round((1 - s / p) * 100) + '% for age and condition) <button type="button" class="btn btn-xs" id="useSug">Use it</button>';
    reason.hidden = !((+val.value || 0) > p);
    return s;
  }
  box.addEventListener('input', suggest); cond.addEventListener('change', suggest);
  box.addEventListener('click', function (e) { if (e.target.id === 'useSug') { val.value = suggest(); suggest(); } });
  suggest();
})();
(function () {
  var f = document.getElementById('productForm'), $ = function (id) { return document.getElementById(id); };
  function sync() {
    var type = f.querySelector('[name="listing_type"]:checked').value;
    f.querySelectorAll('[data-for]').forEach(function (el) { var on = el.dataset.for === type; el.classList.toggle('hidden', !on); el.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; }); });
    if ($('perishable')) $('shelfLife').hidden = !$('perishable').checked;
    var word = ($('rentalMode') || {}).value === 'hour' ? 'hour' : 'day';
    f.querySelectorAll('[data-unit-word]').forEach(function (s) { s.textContent = word; });
    // stock unit "Other": type your own
    var us = Array.prototype.find.call(f.querySelectorAll('[data-unit-sel]'), function (x) { return !x.disabled; });
    var uc = f.querySelector('[data-unit-custom]');
    if (uc && us) { uc.hidden = us.value !== 'other'; uc.querySelector('input').required = us.value === 'other'; }
    // setup time: show what it means
    var sm = f.querySelector('[data-setup-min]'), sh = f.querySelector('[data-setup-hint]');
    if (sm && sh) { var m = Math.max(0, parseInt(sm.value, 10) || 0), h = Math.floor(m / 60), r = m % 60; sh.textContent = m ? 'Set up time: ' + (h ? h + ' h ' : '') + (r ? r + ' min' : '') + '. A rental starting at 8:00 AM is delivered by ' + (function () { var t = 8 * 60 - m; t = (t + 1440) % 1440; var hh = Math.floor(t / 60), mm = t % 60; return ((hh % 12) || 12) + ':' + String(mm).padStart(2, '0') + (hh < 12 ? ' AM' : ' PM'); })() + '. Checkout works it out for the customer.' : 'How long before the rental start this item must arrive to be set up. Example: rental at 8:00 AM with 60 minutes → delivered by 7:00 AM. Checkout works it out for the customer.'; }
  }
  f.addEventListener('change', function (e) {
    if (e.target.matches('[name=listing_type], #perishable, #rentalMode, [data-unit-sel]')) sync();
    if (e.target.id === 'tfOn') $('tfBox').hidden = !e.target.checked;
  });
  f.addEventListener('input', function (e) { if (e.target.matches('[data-setup-min]')) sync(); });
  f.addEventListener('input', function (e) { if (e.target.matches('[data-loc]')) { var t = 0; f.querySelectorAll('[data-loc]').forEach(function (i) { t += Math.max(0, Math.floor(+i.value || 0)); }); f.querySelector('[data-tot]').textContent = t; } });
  f.addEventListener('click', function (e) {
    if (e.target.closest('#addMax')) { $('addMax').hidden = true; $('maxBox').hidden = false; $('hasMax').value = 1; $('maxBox').querySelector('input').focus(); }
    if (e.target.closest('#rmMax')) { $('addMax').hidden = false; $('maxBox').hidden = true; $('hasMax').value = 0; }
  });
  sync();
})();
</script>
@endpush
@endsection
