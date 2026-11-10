@extends('layouts.dash', ['title' => $k->exists ? 'Edit package' : 'New package', 'nav' => 'packages'])

@php($oldDef = old('def') ? json_decode(old('def'), true) : null)
@php($startDef = is_array($oldDef) ? $oldDef : ['pricing' => $def['pricing'], 'components' => $def['components'], 'minGuests' => $def['minGuests'], 'maxGuests' => $def['maxGuests']])
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
      <div class="form-grid" style="grid-template-columns:100px 1fr"><div class="field"><label for="pkEmoji">Emoji</label><input class="input" id="pkEmoji" name="emoji" value="{{ old('emoji', $k->emoji) }}" maxlength="4"></div><div class="field"><label for="pkName">Package name</label><input class="input" id="pkName" name="name" required minlength="3" value="{{ old('name', $k->name) }}"></div></div>
      <div class="field"><label for="pkDesc">Description</label><textarea class="input" id="pkDesc" name="description" required>{{ old('description', $k->description) }}</textarea></div>
      <div class="field"><label for="pkCond">Conditions</label><textarea class="input" id="pkCond" name="conditions" placeholder="e.g. Setup included with delivery. Food is prepared the morning of the event.">{{ old('conditions', $k->conditions) }}</textarea></div>
      <div class="field"><label>Events</label><div class="row wrap gap-sm">@foreach($events as $e)@php($on = in_array($e, old('events', $k->events ?? []), true))<label class="chip {{ $on ? 'active' : '' }}"><input type="checkbox" name="events[]" value="{{ $e }}" @checked($on) hidden>{{ $e }}</label>@endforeach</div></div>
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
    </div>

    <div class="card"><h3>Guests <span class="small muted">(optional)</span></h3>
      <p class="small muted">Give an item a "per guest" quantity and it scales with the guest count the customer enters (rounded up).</p>
      <div class="form-grid"><div class="field"><label for="minG">Minimum guests</label><input class="input" type="number" min="1" id="minG" data-def="minGuests"></div><div class="field"><label for="maxG">Maximum guests</label><input class="input" type="number" min="1" id="maxG" data-def="maxGuests"></div></div>
    </div>
  </div>

  <aside class="card sticky-side"><h3>Price</h3>
    <div class="field"><label for="pMode">Pricing</label><select class="input" id="pMode">@foreach($modes as $m => $l)<option value="{{ $m }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field" data-mode="fixed"><label for="pPrice">Package price (₱)</label><input class="input" type="number" min="0" step="0.01" id="pPrice"><div class="hint">Per day for rentals. Choices with a price difference and add-ons are charged on top.</div></div>
    <div class="field" data-mode="sum_discount"><label for="pDisc">Discount (%)</label><input class="input" type="number" min="0" max="90" step="0.5" id="pDisc"><div class="hint">Taken off the sum of everything included. Add-ons are not discounted.</div></div>
    <div class="card card-flat mb-2" style="padding:12px" id="priceBox"><div class="small muted">Add components to see the price.</div></div>
    <div id="pkgMsgs"></div>
    <button class="btn btn-primary btn-block btn-lg" name="draft" value="0">{{ $k->exists && ! $k->is_draft ? 'Save changes' : 'Publish package' }}</button>
    <button class="btn btn-block mt-1" name="draft" value="1" formnovalidate>{{ $k->exists && ! $k->is_draft ? 'Unpublish & save as draft' : 'Save as draft' }}</button>
    <button type="button" class="btn btn-block btn-ghost mt-1" id="previewBtn" data-modal="pkgPreview">{{ icon('eye') }}Preview as customer</button>
  </aside>
</form>

<div class="modal-backdrop hidden" id="pkgPreview"><div class="modal lg" role="dialog" aria-modal="true" aria-label="Customer preview"><div class="modal-head"><h3>Customer preview</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div><div class="modal-body pad-0" id="previewBody"></div></div></div>

@push('scripts')
<script>
(function () {
  var PRODS = @json($prods), MODES = @json($modes);
  var def = JSON.parse(document.getElementById('defInput').value || '{}');
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
      return '<option value="' + p.id + '"' + (+selected === p.id ? ' selected' : '') + '>' + esc(p.name) + ' · ' + peso(p.price) + (p.type === 'sale' ? '/' + esc(p.unit) : '/day') + ' (' + p.stock + ' ' + (p.type === 'sale' ? 'on hand' : 'owned') + ')</option>';
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

  function render() {
    var wrap = $('#comps');
    wrap.innerHTML = def.components.length ? def.components.map(function (c, i) {
      return '<div class="pkg-comp"><div class="head"><span class="badge ' + BADGE[c.kind] + '">' + LABEL[c.kind] + '</span><span class="grow"></span>' +
        '<button type="button" class="btn btn-xs btn-ghost" data-mv="' + i + '.-1" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>↑</button>' +
        '<button type="button" class="btn btn-xs btn-ghost" data-mv="' + i + '.1" aria-label="Move down"' + (i === def.components.length - 1 ? ' disabled' : '') + '>↓</button>' +
        '<button type="button" class="btn btn-xs btn-ghost" data-rm="' + i + '" aria-label="Remove component">✕</button></div>' + body(c, i) + '</div>';
    }).join('') : '<div class="empty small" style="padding:18px">Nothing inside yet. Add a rental item, consumable or service below.</div>';
    $('#pMode').value = def.pricing.mode;
    $('#pPrice').value = def.pricing.price || '';
    $('#pDisc').value = def.pricing.discountPct || '';
    $('#minG').value = def.minGuests || '';
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
  function changed() {
    document.getElementById('defInput').value = JSON.stringify(def);
    clearTimeout(timer);
    timer = setTimeout(function () {
      if (!def.components.length) { $('#priceBox').innerHTML = '<div class="small muted">Add components to see the price.</div>'; $('#pkgMsgs').innerHTML = ''; return; }
      post(@json(route('supplier.packages.price')), { def: def }).then(function (r) {
        var rows = r.rows.map(function (x) { return '<div class="row between small"><span>' + x.qty + '× ' + esc(x.name) + (x.group !== 'included' ? ' <span class="muted">(' + x.group + ')</span>' : '') + '</span><span class="muted">' + esc(x.cost) + '</span></div>'; }).join('');
        $('#priceBox').innerHTML = '<div class="row between"><span class="strong">Customer pays</span><b class="price">' + esc(r.packageText) + '</b></div>' +
          '<div class="small muted mb-1">' + esc(r.perDayText) + ' per day + ' + esc(r.onceText) + ' once · default options' + (r.usesGuests ? ', ' + r.guests + ' guests' : '') + '</div>' + rows +
          '<div class="row between small mt-1"><span>Separately</span><span>' + esc(r.separateText) + '</span></div>' + (r.savings > 0 ? '<div class="small" style="color:var(--green)">Customer saves ' + esc(r.savingsText) + '</div>' : '');
        $('#pkgMsgs').innerHTML = (r.error ? '<div class="alert alert-danger small">' + esc(r.error) + '</div>' : '') + r.warnings.map(function (w) { return '<div class="alert alert-warn small">' + esc(w) + '</div>'; }).join('');
      }).catch(function () { $('#priceBox').innerHTML = '<div class="small muted">Price unavailable right now.</div>'; });
    }, 250);
  }

  var form = document.getElementById('pkgForm');
  form.addEventListener('input', function (e) {
    var t = e.target, k = t.getAttribute('data-k');
    if (k) {
      var v = t.type === 'checkbox' ? t.checked : (t.type === 'number' ? (t.value === '' ? null : +t.value) : t.value);
      if (t.tagName === 'SELECT') v = t.value === '' ? null : +t.value;
      setPath(k, v);
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
    if (b.hasAttribute('data-rm')) { def.components.splice(+b.getAttribute('data-rm'), 1); render(); return; }
    if (b.hasAttribute('data-mv')) { var m = b.getAttribute('data-mv').split('.'), i = +m[0], j = i + (+m[1]); var tmp = def.components[i]; def.components[i] = def.components[j]; def.components[j] = tmp; render(); return; }
    if (b.hasAttribute('data-addopt')) { def.components[+b.getAttribute('data-addopt')].options.push({ productId: null, qty: 1, priceDelta: 0 }); render(); return; }
    if (b.hasAttribute('data-rmopt')) { var o = b.getAttribute('data-rmopt').split('.'); def.components[+o[0]].options.splice(+o[1], 1); render(); return; }
  });
  document.getElementById('previewBtn').addEventListener('click', function () {
    var box = document.getElementById('previewBody');
    box.innerHTML = '<div class="small muted" style="padding:16px">Loading…</div>';
    post(@json(route('supplier.packages.preview')), { def: def, name: $('#pkName').value }, 'text/html').then(function (h) { box.innerHTML = h; }).catch(function (err) { box.innerHTML = '<div class="alert alert-danger">' + esc(err.message) + '</div>'; });
  });
  render();
})();
</script>
@endpush
@endsection
