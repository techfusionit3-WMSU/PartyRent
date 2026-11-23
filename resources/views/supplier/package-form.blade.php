@extends('layouts.dash', ['title' => $k->exists ? 'Edit package' : 'New package', 'nav' => 'packages'])

@php($oldDef = old('def') ? json_decode(old('def'), true) : null)
@php($startDef = is_array($oldDef) ? $oldDef : ['pricing' => $def['pricing'], 'components' => $def['components'], 'minGuests' => $def['minGuests'], 'maxGuests' => $def['maxGuests'], 'stages' => $def['stages'] ?? []])
@php($biz = $sup->isBusiness())
@php($live = $k->exists && ! $k->is_draft)
@php($mainLabel = $live ? 'Save changes' : ($k->exists ? 'Publish package' : 'Create package'))
@php($mainTitle = $live ? 'Save changes to this package?' : ($k->exists ? 'Publish this package?' : 'Create this package?'))
@php($draftLabel = $live ? 'Unpublish & save as draft' : 'Save as draft')
@php($groups = \App\Models\Package::where('supplier_id', $k->supplier_id ?? auth()->user()->shop()->id)->whereNotNull('tier_group')->distinct()->pluck('tier_group'))
@section('content')
<div class="crumbs"><a href="{{ route('supplier.packages') }}">Packages</a> / {{ $k->exists ? $k->name : 'New package' }}</div>
<div class="page-head"><div><h1>{{ $k->exists ? 'Edit '.$k->name : 'New package' }}</h1><p>Mix your rental items, consumables and services, let customers pick options, and choose how the package is priced. Requests already booked keep the version they were booked with.</p></div>
  @if($k->exists)<span class="badge {{ $k->is_draft ? 'b-gray' : ($k->active ? 'b-green' : 'b-gold') }}">{{ $k->statusLabel() }}</span>@endif
</div>
@if($prods->isEmpty())
  <div class="alert alert-warn">{{ icon('alert') }}<div>Add some products first: a package is built from your own listings. <a href="{{ route('supplier.products.create') }}">Add a product</a></div></div>
@endif
<form method="POST" action="{{ $k->exists ? route('supplier.packages.update', $k) : route('supplier.packages.store') }}" class="grid grid-side" id="pkgForm">
  @csrf @if($k->exists) @method('PUT') @endif
  <input type="hidden" name="def" id="defInput" value="{{ json_encode($startDef) }}">
  <div class="stack">
    <div class="card"><h3>Package details</h3>
      <div class="form-grid" style="grid-template-columns:100px 1fr"><div class="field"><label for="pkEmoji">Emoji / icon</label><input class="input" id="pkEmoji" name="emoji" value="{{ old('emoji', $k->emoji) }}" maxlength="4"></div><div class="field"><label for="pkName">Package name</label><input class="input" id="pkName" name="name" required minlength="3" value="{{ old('name', $k->name) }}"></div></div>
      <div class="field"><label for="pkDesc">Description</label><textarea class="input" id="pkDesc" name="description" required>{{ old('description', $k->description) }}</textarea></div>
      @php($evNow = old('events', $k->events ?? []))
      <div class="field"><label for="evType">Rental event</label>
        <div class="row wrap gap-sm" id="evTags">@foreach($evNow as $e)<span class="chip active" data-ev="{{ $e }}"><input type="hidden" name="events[]" value="{{ $e }}">{{ $e }} <button type="button" class="x" data-ev-rm aria-label="Remove {{ $e }}" style="background:none;border:0;color:inherit;cursor:pointer;padding:0">✕</button></span>@endforeach</div>
        <div class="row gap-sm mt-1"><input class="input grow" id="evType" list="evList" maxlength="40" placeholder="Type an event, or pick one below"><datalist id="evList">@foreach($events as $e)<option value="{{ $e }}">@endforeach</datalist><button type="button" class="btn" id="evAdd">{{ icon('plus') }}Add</button></div>
        <div class="row wrap gap-sm mt-1" id="evPick">@foreach($events as $e)<button type="button" class="chip" data-ev-pick="{{ $e }}" @if(in_array($e, $evNow, true)) hidden @endif>+ {{ $e }}</button>@endforeach</div>
        <div class="hint">Customers find the package when they filter by these events.</div></div>
      <div class="field"><label for="pkCond">Package conditions</label><textarea class="input" id="pkCond" name="conditions" placeholder="e.g. Price is per day. Setup included with delivery.">{{ old('conditions', $k->conditions) }}</textarea></div>
      <div class="form-grid"><div class="field"><label for="pkMinH">Minimum rental hours <span class="muted">(optional)</span></label><input class="input" type="number" id="pkMinH" name="min_hours" min="0.5" max="720" step="0.5" value="{{ old('min_hours', $k->min_hours ?: '') }}"></div>
        <div class="field"><label for="pkMaxH">Maximum rental hours <span class="muted">(optional)</span></label><input class="input" type="number" id="pkMaxH" name="max_hours" min="0.5" max="720" step="0.5" value="{{ old('max_hours', $k->max_hours ?: '') }}">@error('max_hours')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div></div>
      @php($photo = ($k->images ?? [])[0] ?? null)
      <div class="field"><label for="pkPhoto">Package photo</label>
        <div class="row gap-sm top"><div id="photoPrev" class="thumb" style="width:96px;height:96px;border-radius:12px;overflow:hidden;background:var(--surface-2);flex:none;display:grid;place-items:center">@if($photo)<img src="{{ asset('storage/'.$photo) }}" alt="Package photo" style="width:100%;height:100%;object-fit:cover">@else<span class="small muted">No photo</span>@endif</div>
          <div class="grow"><input type="file" id="pkPhoto" accept="image/jpeg,image/png,image/webp" class="input"><div class="hint">You crop it to a square after choosing it.</div>
            @if($photo)<label class="check small mt-1"><input type="checkbox" name="clear_photo" value="1" id="clearPhoto"> Remove the current photo</label>@endif
            @error('photo')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div></div>
        <input type="hidden" name="photo" id="photoData"></div>
    </div>

    <div class="card"><h3>What's inside</h3>
      <p class="small muted">Rental items come back after the event; consumables are sold and used up; services are work you provide. Choices let the customer pick one option, add-ons are optional extras.</p>
      <div id="comps"></div>
      <div class="row wrap gap-sm mt-1">
        <button type="button" class="btn btn-sm" data-add="rent">{{ icon('plus') }}Rental item</button>
        <button type="button" class="btn btn-sm" data-add="sale">{{ icon('plus') }}Consumable</button>
        <button type="button" class="btn btn-sm" data-add="service">{{ icon('plus') }}Service</button>
        <button type="button" class="btn btn-sm" data-add="choice">{{ icon('plus') }}Customer choice</button>
        <button type="button" class="btn btn-sm" data-add="addon">{{ icon('plus') }}Optional add-on</button>
      </div>
      @unless($biz)
        <div class="row wrap gap-sm mt-2" style="align-items:center"><button type="button" class="btn btn-sm" data-modal="pkgOnlyModal">{{ icon('plus') }}Item just for this package</button>
          <span class="small muted">Not in your listings: customers can only get it with this package.</span></div>
      @endunless
    </div>

    <div class="card"><h3>Inventory</h3>
      <p class="small muted">Reserving a package deducts each included item from your inventory. Figures use the default options.</p>
      <div id="stockBox" class="small muted">Add rental items or consumables to see how many packages your stock covers.</div>
    </div>

    @if($biz)
    <div class="card"><h3>Stages <span class="small muted">(optional)</span></h3>
      <p class="small muted">For events with parts, like a wedding: Preparation, Ceremony, Reception. Put each item or service in a stage below; customers give every stage its own time and venue when they book.</p>
      <div id="stages"></div>
      <button type="button" class="btn btn-sm mt-1" id="addStage">{{ icon('plus') }}Add stage</button>
    </div>

    <div class="card"><h3>Tier <span class="small muted">(optional)</span></h3>
      <p class="small muted">Offer Silver, Gold and Platinum versions of the same package. Packages with the same group name show a tier switch for customers. Tip: build one, then use Duplicate on the Packages page for the next tier.</p>
      <div class="form-grid"><div class="field"><label for="tierGroup">Group name</label><input class="input" id="tierGroup" name="tier_group" maxlength="60" list="tierGroups" value="{{ old('tier_group', $k->tier_group) }}" placeholder="e.g. Wedding"><datalist id="tierGroups">@foreach($groups as $g)<option value="{{ $g }}">@endforeach</datalist></div>
        <div class="field"><label for="tierLabel">Tier name</label><input class="input" id="tierLabel" name="tier_label" maxlength="30" value="{{ old('tier_label', $k->tier_label) }}" placeholder="e.g. Gold"></div></div>
    </div>
    @endif

    <div class="card"><h3>Guests</h3>
      <p class="small muted">How many guests this package is good for. Customers who search or ask the AI planner for an event of that size or smaller are shown this package. Leave it empty if the package does not depend on the guest count.</p>
      <div class="field" style="max-width:260px"><label for="maxG">Guests accommodated</label><input class="input" type="number" min="1" max="5000" id="maxG" data-def="maxGuests" placeholder="e.g. 50"></div>
    </div>
  </div>

  <aside class="card sticky-side"><h3>Price</h3>
    <div class="field"><label for="pMode">Pricing</label><select class="input" id="pMode">@foreach($modes as $m => $l)<option value="{{ $m }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field" data-mode="fixed"><label for="pPrice">Package price (₱)</label><input class="input" type="number" min="0" step="0.01" id="pPrice"><div class="hint">Per day for rentals. Choices with a price difference and add-ons are charged on top.</div></div>
    <div class="field" data-mode="sum_discount"><label for="pDisc">Discount (%)</label><input class="input" type="number" min="0" max="90" step="0.5" id="pDisc"><div class="hint">Taken off the sum of everything included. Add-ons are not discounted.</div></div>
    <div class="card card-flat mb-2" style="padding:12px" id="priceBox"><div class="small muted">Add components to see the price.</div></div>
    <div id="pkgMsgs"></div>
    <button class="btn btn-primary btn-block btn-lg" name="draft" value="0">{{ icon('check') }}{{ $mainLabel }}</button>
    <button class="btn btn-block mt-1" name="draft" value="1" formnovalidate>{{ $draftLabel }}</button>
    <button type="button" class="btn btn-block btn-ghost mt-1" id="previewBtn" data-modal="pkgPreview">{{ icon('eye') }}Preview as customer</button>
  </aside>
  <div class="form-actionbar form-actionbar-narrow" role="group" aria-label="Save this package">
    <button class="btn" name="draft" value="1" formnovalidate>{{ $draftLabel }}</button>
    <button class="btn btn-primary grow" name="draft" value="0">{{ icon('check') }}{{ $mainLabel }}</button>
  </div>
</form>

<div class="modal-backdrop hidden" id="pkgPreview"><div class="modal lg" role="dialog" aria-modal="true" aria-label="Customer preview"><div class="modal-head"><h3>Customer preview</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div><div class="modal-body pad-0" id="previewBody"></div></div></div>

@unless($biz)
<div class="modal-backdrop hidden" id="pkgOnlyModal"><div class="modal" role="dialog" aria-modal="true" aria-label="Item just for this package"><div class="modal-head"><h3>Item just for this package</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <div class="modal-body"><p class="small muted">The item is added to your inventory but is not listed on its own: customers cannot find it in Browse or rent it separately. It is only available inside your packages.</p>
    <div id="poErr"></div>
    <div class="field"><label>Type</label><div class="row gap-sm"><label class="check"><input type="radio" name="po_type" value="rent" checked> Rental item (comes back)</label><label class="check"><input type="radio" name="po_type" value="sale"> Consumable (used up)</label></div></div>
    <div class="field"><label for="poName">Item name</label><input class="input" id="poName" maxlength="120" placeholder="e.g. Custom name backdrop"></div>
    <div class="field"><label for="poCat">Category</label><select class="input" id="poCat">@foreach($cats as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
    <div class="form-grid"><div class="field"><label for="poStock">How many you have</label><input class="input" type="number" id="poStock" min="1" max="100000" value="1"></div>
      <div class="field"><label for="poPrice">Value (₱ <span id="poPer">per day</span>)</label><input class="input" type="number" id="poPrice" min="0.01" step="0.01" placeholder="e.g. 500"><div class="hint">Used when the package price is a sum of its items, and shown as the "separately" value.</div></div></div>
    <div class="form-grid" id="poDmg"><div class="field"><label for="poRep">Replacement value (₱ each){{ term('replacement_value') }}</label><input class="input" type="number" id="poRep" min="0.01" step="0.01" placeholder="what it costs to replace"><div class="hint">Charged if it is lost or beyond repair; repairs never cost more.</div></div>
      <div class="field"><label for="poMinor">Minor damage fee (₱ each) <span class="muted">(optional)</span></label><input class="input" type="number" id="poMinor" min="0" step="0.01" placeholder="cleaning, stain"></div></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" id="poSave">Add to package</button></div></div></div>
@endunless

<div class="modal-backdrop hidden" id="cropModal"><div class="modal" role="dialog" aria-modal="true" aria-label="Crop photo"><div class="modal-head"><h3>Crop photo</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <div class="modal-body"><p class="small muted">Drag the photo to position it inside the square. Use the slider to zoom.</p>
    <canvas id="cropCv" width="320" height="320" style="display:block;margin:0 auto;width:min(320px,100%);aspect-ratio:1;border-radius:12px;cursor:grab;touch-action:none;background:var(--surface-2)"></canvas>
    <div class="field mt-2"><label for="cropZoom">Zoom</label><input type="range" id="cropZoom" min="1" max="3" step="0.01" value="1"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" id="cropOk">Use photo</button></div></div></div>

@push('scripts')
<script>
(function () {
  var PRODS = @json($prods), MODES = @json($modes), BIZ = @json($biz);
  var def = JSON.parse(document.getElementById('defInput').value || '{}');
  if (!BIZ) def.stages = []; // stages are for business accounts
  def.pricing = def.pricing || { mode: 'sum_discount', price: 0, discountPct: 10 };
  def.components = def.components || [];
  var token = document.querySelector('meta[name="csrf-token"]').content;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var peso = function (n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var post = function (url, body, accept) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': accept || 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) })
      .then(function (r) { if (!r.ok) throw new Error('Request failed (' + r.status + ')'); return accept === 'text/html' ? r.text() : r.json(); });
  };
  var LABEL = { rent: 'Rental item', sale: 'Consumable', service: 'Service', choice: 'Customer choice', addon: 'Optional add-on' };
  var BADGE = { rent: 'b-sky', sale: 'b-purple', service: 'b-gold', choice: 'b-pink', addon: 'b-green' };

  function prodOptions(type, selected) {
    var list = PRODS.filter(function (p) { return !type || p.type === type; });
    return '<option value="">Select…</option>' + list.map(function (p) {
      return '<option value="' + p.id + '"' + (+selected === p.id ? ' selected' : '') + '>' + esc(p.name) + (p.packageOnly ? ' (package only)' : '') + ' · ' + peso(p.price) + (p.type === 'sale' ? '/' + esc(p.unit) : '/day') + ' (' + p.stock + ' ' + (p.type === 'sale' ? 'on hand' : 'owned') + ')</option>';
    }).join('');
  }
  function num(path, val, attrs, label, cls) {
    return '<div class="field ' + (cls || '') + '"><label class="small">' + label + '</label><input class="input input-sm" type="number" data-k="' + path + '" value="' + (val == null ? '' : esc(val)) + '" ' + (attrs || '') + '></div>';
  }
  function txt(path, val, label, ph) {
    return '<div class="field"><label class="small">' + label + '</label><input class="input input-sm" data-k="' + path + '" value="' + esc(val || '') + '" placeholder="' + esc(ph || '') + '"></div>';
  }
  function sel(path, html, label) { return '<div class="field"><label class="small">' + label + '</label><select class="input input-sm" data-k="' + path + '">' + html + '</select></div>'; }

  function body(c, i) {
    var p = i + '.';
    switch (c.kind) {
      case 'rent': case 'sale':
        return '<div class="form-grid" style="grid-template-columns:2fr 1fr 1fr">' + sel(p + 'productId', prodOptions(c.kind, c.productId), 'Item') + num(p + 'qty', c.qty || 1, 'min="1"', 'Qty per package') + num(p + 'perGuest', c.perGuest, 'min="0" step="0.01" placeholder="—"', 'Or per guest') + '</div>';
      case 'service':
        return '<div class="form-grid" style="grid-template-columns:2fr 1fr 1fr 1fr">' + txt(p + 'name', c.name, 'Service', 'e.g. Setup & styling crew') + num(p + 'price', c.price, 'min="0" step="0.01"', 'Price (₱)') + num(p + 'qty', c.qty || 1, 'min="1"', 'Qty') + txt(p + 'unit', c.unit, 'Unit', 'service') + '</div>';
      case 'addon':
        var custom = !c.productId;
        return '<div class="form-grid" style="grid-template-columns:2fr 1fr 1fr">' + sel(p + 'productId', '<option value="">Custom service (enter name)</option>' + prodOptions(null, c.productId).replace('<option value="">Select…</option>', ''), 'Add-on') +
          num(p + 'qty', c.qty || 1, 'min="1"', 'Qty') + num(p + 'price', c.price, 'min="0" step="0.01" placeholder="' + (custom ? '' : 'Item price') + '"', custom ? 'Price (₱)' : 'Special price (₱, optional)') + '</div>' +
          (custom ? txt(p + 'name', c.name, 'Add-on name', 'e.g. Photo booth host') : '') +
          '<label class="check small"><input type="checkbox" data-k="' + p + 'default"' + (c.default ? ' checked' : '') + '> Ticked by default</label>';
      case 'choice':
        var opts = (c.options || []).map(function (o, j) {
          var q = p + 'options.' + j + '.';
          return '<div class="form-grid" style="grid-template-columns:2fr 1fr 1fr 1fr auto;align-items:end">' + sel(q + 'productId', prodOptions(null, o.productId), 'Option ' + (j + 1)) + num(q + 'qty', o.qty || 1, 'min="1"', 'Qty') + num(q + 'perGuest', o.perGuest, 'min="0" step="0.01" placeholder="—"', 'Or per guest') + num(q + 'priceDelta', o.priceDelta || 0, 'step="0.01"', '± price each') +
            '<div class="field"><button type="button" class="btn btn-xs btn-ghost" data-rmopt="' + i + '.' + j + '" aria-label="Remove option">✕</button></div></div>';
        }).join('');
        return txt(p + 'label', c.label, 'Question shown to the customer', 'e.g. Chair style') + '<div class="opts">' + opts + '<button type="button" class="btn btn-xs" data-addopt="' + i + '">+ Option</button><div class="hint">The first option is the default. "± price each" is added per unit when the customer picks it (fixed pricing).</div></div>';
    }
    return '';
  }

  // Teams-per-day limit for services, and which stage a component belongs to.
  function extra(c, i) {
    var out = '';
    if (c.kind === 'service') out += '<div class="form-grid" style="grid-template-columns:1fr 2fr">' + num(i + '.teamsPerDay', c.teamsPerDay, 'min="1" placeholder="No limit"', 'Teams per day') + '<div class="hint" style="align-self:end">How many of this service you can do on one date (e.g. 2 photo teams). Extra bookings that day are refused.</div></div>';
    if ((def.stages || []).length) out += '<div class="field" style="max-width:260px"><label class="small">Stage</label><select class="input input-sm" data-k="' + i + '.stage"><option value="">Whole event</option>' +
      def.stages.map(function (s, j) { return '<option value="' + j + '"' + (c.stage === j ? ' selected' : '') + '>' + esc(s.name || 'Stage ' + (j + 1)) + '</option>'; }).join('') + '</select></div>';
    return out;
  }
  function renderStages() {
    def.stages = def.stages || [];
    $('#stages').innerHTML = def.stages.map(function (s, j) {
      return '<div class="form-grid" style="grid-template-columns:1fr 2fr auto;align-items:end"><div class="field"><label class="small">Stage ' + (j + 1) + '</label><input class="input input-sm" data-st="' + j + '.name" value="' + esc(s.name) + '" placeholder="e.g. Ceremony"></div>' +
        '<div class="field"><label class="small">Note for the customer (optional)</label><input class="input input-sm" data-st="' + j + '.hint" value="' + esc(s.hint) + '" placeholder="e.g. usually 2–3 hours, at the church"></div>' +
        '<div class="field"><button type="button" class="btn btn-xs btn-ghost" data-rmstage="' + j + '" aria-label="Remove stage">✕</button></div></div>';
    }).join('') || '<div class="small muted">No stages: everything is for the whole event.</div>';
  }
  function render() {
    if (document.getElementById('stages')) renderStages();
    var wrap = $('#comps');
    wrap.innerHTML = def.components.length ? def.components.map(function (c, i) {
      return '<div class="pkg-comp"><div class="head"><span class="badge ' + BADGE[c.kind] + '">' + LABEL[c.kind] + '</span><span class="grow"></span>' +
        '<button type="button" class="btn btn-xs btn-ghost" data-mv="' + i + '.-1" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>↑</button>' +
        '<button type="button" class="btn btn-xs btn-ghost" data-mv="' + i + '.1" aria-label="Move down"' + (i === def.components.length - 1 ? ' disabled' : '') + '>↓</button>' +
        '<button type="button" class="btn btn-xs btn-ghost" data-rm="' + i + '" aria-label="Remove component">✕</button></div>' + body(c, i) + extra(c, i) + '</div>';
    }).join('') : '<div class="empty small" style="padding:18px">Nothing inside yet. Add a rental item, consumable or service below.</div>';
    $('#pMode').value = def.pricing.mode;
    $('#pPrice').value = def.pricing.price || '';
    $('#pDisc').value = def.pricing.discountPct || '';
    $('#maxG').value = def.maxGuests || '';
    document.querySelectorAll('[data-mode]').forEach(function (el) { el.hidden = el.getAttribute('data-mode') !== def.pricing.mode; });
    changed();
  }

  function setPath(path, value) {
    var parts = path.split('.'), o = def.components;
    for (var n = 0; n < parts.length - 1; n++) o = o[parts[n]];
    o[parts[parts.length - 1]] = value;
  }

  var timer = null;
  var lastPrice = null; // the latest price check, shown again in the publish confirmation
  function changed() {
    document.getElementById('defInput').value = JSON.stringify(def);
    clearTimeout(timer);
    timer = setTimeout(function () {
      if (!def.components.length) { $('#priceBox').innerHTML = '<div class="small muted">Add components to see the price.</div>'; $('#pkgMsgs').innerHTML = ''; $('#stockBox').innerHTML = '<span class="small muted">Add rental items or consumables to see how many packages your stock covers.</span>'; return; }
      post(@json(route('supplier.packages.price')), { def: def }).then(function (r) {
        lastPrice = r;
        var rows = r.rows.map(function (x) { return '<div class="row between small"><span>' + x.qty + '× ' + esc(x.name) + (x.group !== 'included' ? ' <span class="muted">(' + x.group + ')</span>' : '') + '</span><span class="muted">' + esc(x.cost) + '</span></div>'; }).join('');
        $('#priceBox').innerHTML = '<div class="row between"><span class="strong">Customer pays</span><b class="price">' + esc(r.packageText) + '</b></div>' +
          '<div class="small muted mb-1">' + esc(r.perDayText) + ' per day + ' + esc(r.onceText) + ' once · default options' + (r.usesGuests ? ', ' + r.guests + ' guests' : '') + '</div>' + rows +
          '<div class="row between small mt-1"><span>Separately</span><span>' + esc(r.separateText) + '</span></div>' + (r.savings > 0 ? '<div class="small" style="color:var(--green)">Customer saves ' + esc(r.savingsText) + '</div>' : '');
        var st = r.stock;
        $('#stockBox').innerHTML = st && st.counts ? '<div class="row wrap" style="gap:16px"><div><div class="price">' + st.today + '</div><div class="small muted">packages available today</div></div><div><div class="price">' + st.min30 + '</div><div class="small muted">lowest in the next 30 days</div></div></div>' + (st.limit ? '<div class="small mt-1">Limited by <b>' + esc(st.limit) + '</b></div>' : '')
          : '<span class="small muted">Add rental items or consumables to see how many packages your stock covers.</span>';
        $('#pkgMsgs').innerHTML = (r.error ? '<div class="alert alert-danger small">' + esc(r.error) + '</div>' : '') + r.warnings.map(function (w) { return '<div class="alert alert-warn small">' + esc(w) + '</div>'; }).join('');
      }).catch(function () { $('#priceBox').innerHTML = '<div class="small muted">Price unavailable right now.</div>'; });
    }, 250);
  }

  var form = document.getElementById('pkgForm');

  // Publishing (or saving a live package) asks first, with a summary of what customers will see.
  // Drafts stay one click: customers cannot see them.
  var publishOk = false;
  form.addEventListener('submit', function (e) {
    var btn = e.submitter;
    if (publishOk || !btn || btn.name !== 'draft' || btn.value !== '0') return;
    e.preventDefault();
    var name = ($('#pkName') && $('#pkName').value.trim()) || 'This package';
    var evs = Array.prototype.map.call(document.querySelectorAll('#evTags input[name="events[]"]'), function (i) { return i.value; });
    var rows = lastPrice && lastPrice.rows ? lastPrice.rows : [];
    var inside = rows.length ? '<ul class="small" style="margin:4px 0 0;padding-left:18px">' + rows.map(function (x) {
      return '<li>' + x.qty + '× ' + esc(x.name) + (x.group !== 'included' ? ' <span class="muted">(' + esc(x.group) + ')</span>' : '') + '</li>'; }).join('') + '</ul>'
      : '<div class="small" style="color:var(--red)">Nothing inside yet. Add at least one item or service.</div>';
    var html = '<p style="margin:0 0 10px">' + (@json($k->exists && ! $k->is_draft) ? 'Customers will see these changes right away. Requests already booked keep the version they were booked with.' : 'Customers will be able to find and book it right away.') + '</p>' +
      '<div class="card card-flat" style="padding:12px"><div class="strong">' + esc(($('#pkEmoji') ? $('#pkEmoji').value : '') + ' ' + name) + '</div>' +
      '<div class="small muted">' + (evs.length ? esc(evs.join(', ')) : 'No event tags') + (def.maxGuests ? ' · up to ' + esc(def.maxGuests) + ' guests' : '') + '</div>' +
      '<div class="small strong mt-1">What&#39;s inside</div>' + inside +
      '<div class="row between mt-1"><span class="strong">Customer pays</span><b class="price">' + esc(lastPrice && lastPrice.packageText ? lastPrice.packageText : '—') + '</b></div></div>';
    window.PRUI.confirmDialog({ title: @json($mainTitle), html: html, ok: @json($mainLabel) }).then(function (ok) {
      if (!ok) return;
      publishOk = true;
      if (form.requestSubmit) form.requestSubmit(btn); else { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'draft'; h.value = '0'; form.appendChild(h); form.submit(); }
    });
  });
  form.addEventListener('input', function (e) {
    var t = e.target, k = t.getAttribute('data-k'), st = t.getAttribute('data-st');
    if (st) { var sp = st.split('.'); def.stages[+sp[0]][sp[1]] = t.value; changed(); return; }
    if (k) {
      var v = t.type === 'checkbox' ? t.checked : (t.type === 'number' ? (t.value === '' ? null : +t.value) : t.value);
      if (t.tagName === 'SELECT') v = t.value === '' ? null : +t.value;
      setPath(k, v);
      if (t.tagName === 'SELECT' && /\.stage$/.test(k)) { changed(); return; }
      if (t.tagName === 'SELECT' && /^\d+\.productId$/.test(k)) render(); // add-on switches between product and custom service
      else changed();
      return;
    }
    if (t.id === 'pPrice') def.pricing.price = +t.value || 0;
    if (t.id === 'pDisc') def.pricing.discountPct = +t.value || 0;
    if (t.getAttribute('data-def')) def[t.getAttribute('data-def')] = t.value === '' ? null : +t.value;
    if (t.id === 'pMode') { def.pricing.mode = t.value; render(); return; }
    if (t.id === 'pPrice' || t.id === 'pDisc' || t.getAttribute('data-def')) changed();
  });
  form.addEventListener('change', function (e) { if (e.target.id === 'pMode') { def.pricing.mode = e.target.value; render(); } });
  form.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var a = b.getAttribute('data-add');
    if (a) {
      var blank = { rent: { kind: 'rent', productId: null, qty: 1 }, sale: { kind: 'sale', productId: null, qty: 1 }, service: { kind: 'service', name: '', price: null, qty: 1, unit: 'service' },
        choice: { kind: 'choice', label: '', options: [{ productId: null, qty: 1, priceDelta: 0 }, { productId: null, qty: 1, priceDelta: 0 }] }, addon: { kind: 'addon', productId: null, name: '', price: null, qty: 1, default: false } }[a];
      def.components.push(blank); render(); return;
    }
    if (b.id === 'addStage') { (def.stages = def.stages || []).push({ name: '', hint: '' }); render(); return; }
    if (b.hasAttribute('data-rmstage')) {
      var r = +b.getAttribute('data-rmstage'); def.stages.splice(r, 1);
      def.components.forEach(function (c) { if (c.stage === r) c.stage = null; else if (c.stage > r) c.stage--; });
      render(); return;
    }
    if (b.hasAttribute('data-rm')) { def.components.splice(+b.getAttribute('data-rm'), 1); render(); return; }
    if (b.hasAttribute('data-mv')) { var m = b.getAttribute('data-mv').split('.'), i = +m[0], j = i + (+m[1]); var tmp = def.components[i]; def.components[i] = def.components[j]; def.components[j] = tmp; render(); return; }
    if (b.hasAttribute('data-addopt')) { def.components[+b.getAttribute('data-addopt')].options.push({ productId: null, qty: 1, priceDelta: 0 }); render(); return; }
    if (b.hasAttribute('data-rmopt')) { var o = b.getAttribute('data-rmopt').split('.'); def.components[+o[0]].options.splice(+o[1], 1); render(); return; }
  });
  // Package-only item (personal accounts): created right away as a package-only item, then added as a component.
  var poSave = $('#poSave');
  if (poSave) {
    document.querySelectorAll('input[name="po_type"]').forEach(function (r) { r.addEventListener('change', function () { $('#poPer').textContent = r.value === 'sale' ? 'each' : 'per day'; $('#poDmg').hidden = r.value === 'sale'; }); });
    poSave.addEventListener('click', function () {
      var type = document.querySelector('input[name="po_type"]:checked').value;
      $('#poErr').innerHTML = ''; poSave.disabled = true;
      fetch(@json(route('supplier.packages.item')), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ type: type, name: $('#poName').value, category_id: $('#poCat').value, stock: $('#poStock').value, price: $('#poPrice').value, replacement_value: $('#poRep').value, minor_fee: $('#poMinor').value }) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          poSave.disabled = false;
          if (!res.ok) { var m = res.j.errors ? Object.values(res.j.errors)[0][0] : (res.j.message || 'Could not add the item.'); $('#poErr').innerHTML = '<div class="alert alert-danger small">' + esc(m) + '</div>'; return; }
          PRODS.push(res.j.product);
          def.components.push({ kind: res.j.product.type, productId: res.j.product.id, qty: 1 });
          $('#poName').value = ''; $('#poPrice').value = ''; $('#poRep').value = ''; $('#poMinor').value = ''; $('#poStock').value = 1;
          window.PRUI.closeModal($('#pkgOnlyModal'));
          render();
          window.PR.toast(res.j.product.name + ' added to the package.', 'success');
        }).catch(function () { poSave.disabled = false; $('#poErr').innerHTML = '<div class="alert alert-danger small">Could not add the item. Try again.</div>'; });
    });
  }

  document.getElementById('previewBtn').addEventListener('click', function () {
    var box = document.getElementById('previewBody');
    box.innerHTML = '<div class="small muted" style="padding:16px">Loading…</div>';
    post(@json(route('supplier.packages.preview')), { def: def, name: $('#pkName').value }, 'text/html').then(function (h) { box.innerHTML = h; }).catch(function (err) { box.innerHTML = '<div class="alert alert-danger">' + esc(err.message) + '</div>'; });
  });
  render();

  // Rental event tags: pick from the list or type your own (change list C8)
  var evTags = $('#evTags'), evType = $('#evType');
  function hasEv(v) { return Array.prototype.some.call(evTags.querySelectorAll('[data-ev]'), function (c) { return c.getAttribute('data-ev').toLowerCase() === v.toLowerCase(); }); }
  function syncPick() { document.querySelectorAll('[data-ev-pick]').forEach(function (b) { b.hidden = hasEv(b.getAttribute('data-ev-pick')); }); }
  function addEv(v) {
    v = String(v || '').replace(/\s+/g, ' ').trim().replace(/(^|\s)\S/g, function (c) { return c.toUpperCase(); });
    if (!v || hasEv(v)) return;
    if (evTags.querySelectorAll('[data-ev]').length >= 12) { window.PR.toast('Up to 12 events.', 'warn'); return; }
    var c = document.createElement('span'); c.className = 'chip active'; c.setAttribute('data-ev', v);
    c.innerHTML = '<input type="hidden" name="events[]" value="' + esc(v) + '">' + esc(v) + ' <button type="button" class="x" data-ev-rm aria-label="Remove ' + esc(v) + '" style="background:none;border:0;color:inherit;cursor:pointer;padding:0">✕</button>';
    evTags.appendChild(c); syncPick();
  }
  $('#evAdd').addEventListener('click', function () { addEv(evType.value); evType.value = ''; evType.focus(); });
  evType.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addEv(evType.value); evType.value = ''; } });
  document.addEventListener('click', function (e) {
    var rm = e.target.closest('[data-ev-rm]'); if (rm) { rm.closest('[data-ev]').remove(); syncPick(); return; }
    var pk = e.target.closest('[data-ev-pick]'); if (pk) addEv(pk.getAttribute('data-ev-pick'));
  });

  // Package photo: crop to a square in the browser, posted as a JPEG data URL (change list C8; no GD on the server).
  // State = the image point shown at the centre of the square, so zooming keeps the same spot in the middle.
  var cv = $('#cropCv'), cx = cv.getContext('2d'), zoom = $('#cropZoom'), img = null, ctr = { x: 0, y: 0 }, drag = null, OUT = 800;
  function scale() { return Math.max(cv.width / img.width, cv.height / img.height) * +zoom.value; }
  function clampCtr() {
    var k = scale(), hw = cv.width / 2 / k, hh = cv.height / 2 / k;
    ctr.x = Math.min(img.width - hw, Math.max(hw, ctr.x)); ctr.y = Math.min(img.height - hh, Math.max(hh, ctr.y));
  }
  function paint(c, size) {
    var k = scale() * size / cv.width;
    c.fillStyle = '#fff'; c.fillRect(0, 0, size, size);
    c.drawImage(img, size / 2 - ctr.x * k, size / 2 - ctr.y * k, img.width * k, img.height * k);
  }
  function draw() { if (!img) return; clampCtr(); paint(cx, cv.width); }
  $('#pkPhoto').addEventListener('change', function (e) {
    var f = e.target.files[0]; if (!f) return;
    if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { window.PR.toast('Choose a JPG, PNG or WebP image.', 'error'); e.target.value = ''; return; }
    var rd = new FileReader();
    rd.onload = function () {
      var im = new Image();
      im.onload = function () { img = im; zoom.value = 1; ctr = { x: im.width / 2, y: im.height / 2 }; draw(); window.PRUI.openModal($('#cropModal')); };
      im.onerror = function () { window.PR.toast('That image could not be opened.', 'error'); };
      im.src = rd.result;
    };
    rd.readAsDataURL(f);
  });
  zoom.addEventListener('input', draw);
  function pt(e) { var r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left) * cv.width / r.width, y: (e.clientY - r.top) * cv.height / r.height }; }
  cv.addEventListener('pointerdown', function (e) { if (!img) return; drag = { p: pt(e), c: { x: ctr.x, y: ctr.y } }; cv.setPointerCapture(e.pointerId); cv.style.cursor = 'grabbing'; });
  cv.addEventListener('pointermove', function (e) { if (!drag) return; var p = pt(e), k = scale(); ctr.x = drag.c.x - (p.x - drag.p.x) / k; ctr.y = drag.c.y - (p.y - drag.p.y) / k; draw(); });
  ['pointerup', 'pointercancel'].forEach(function (t) { cv.addEventListener(t, function () { drag = null; cv.style.cursor = 'grab'; }); });
  $('#cropOk').addEventListener('click', function () {
    if (!img) return;
    var out = document.createElement('canvas'); out.width = out.height = OUT;
    paint(out.getContext('2d'), OUT);
    var url = out.toDataURL('image/jpeg', 0.85);
    $('#photoData').value = url;
    $('#photoPrev').innerHTML = '<img src="' + url + '" alt="Package photo" style="width:100%;height:100%;object-fit:cover">';
    if ($('#clearPhoto')) $('#clearPhoto').checked = false;
    window.PRUI.closeModal($('#cropModal'));
  });
})();
</script>
@endpush
@endsection
