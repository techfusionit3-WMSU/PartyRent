@extends('layouts.dash', ['title' => 'Discounts', 'nav' => 'discounts'])

{{-- Change list C11: every discount that can be applied to products — shop discounts + each item's bulk discount. --}}
@php($STATE = ['running' => ['Running', 'b-green'], 'scheduled' => ['Scheduled', 'b-sky'], 'ended' => ['Ended', 'b-gray'], 'off' => ['Off', 'b-gray']])
@section('content')
<div class="page-head"><div><h1>Discounts</h1><p>Every discount that can lower your prices. Shop discounts come on top of bulk discounts. A discount takes off per item (a percent or pesos per piece) or from the whole order; each order gets the one shop discount that saves the customer the most. Requests already placed keep the price they were quoted.</p></div><button class="btn btn-primary" data-modal="dNew">{{ icon('plus') }}New discount</button></div>
@if($errors->any())<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ $errors->first() }}</div></div>@endif

<div class="card mb-3"><h3>Shop discounts</h3>
  @if($list->isEmpty())
    @include('partials.empty', ['emoji' => '🏷️', 'title' => 'No shop discounts yet', 'text' => 'Run a promo like "10% off all chairs this December" or "₱20 off per table for 10 or more".'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Discount</th><th>Takes off</th><th>Items</th><th>Minimum</th><th>Period</th><th>Status</th><th></th></tr></thead><tbody>
      @foreach($list as $d)
        @php($st = $STATE[$d->state()])
        <tr>
          <td data-label="Discount"><b>{{ $d->name }}</b></td>
          <td data-label="Takes off">{{ $d->label() }}</td>
          <td data-label="Items">{{ $d->applies_to === 'all' ? 'All items' : $products->whereIn('id', $d->product_ids ?? [])->pluck('name')->implode(', ') }}</td>
          <td data-label="Minimum">{{ $d->isOrderLevel() ? ($d->min_amount ? money_short($d->min_amount).' order' : '—') : ($d->min_qty ? $d->min_qty.'+ pieces' : '—') }}</td>
          <td data-label="Period">{{ $d->starts_on ? fmt_date($d->starts_on->format('Y-m-d')) : 'Now' }} – {{ $d->ends_on ? fmt_date($d->ends_on->format('Y-m-d')) : 'no end' }}</td>
          <td data-label="Status"><span class="badge {{ $st[1] }}">{{ $st[0] }}</span></td>
          <td class="right"><div class="btn-group">
            <button class="btn btn-xs" data-modal="d{{ $d->id }}">{{ icon('edit') }}Edit</button>
            <form method="POST" action="{{ route('supplier.discounts.active', $d) }}">@csrf<button class="btn btn-xs">{{ $d->active ? 'Turn off' : 'Turn on' }}</button></form>
            <form method="POST" action="{{ route('supplier.discounts.delete', $d) }}" data-confirm-title="Delete {{ $d->name }}?" data-confirm="Requests already placed keep their price." data-confirm-ok="Delete" data-danger>@csrf @method('DELETE')<button class="btn btn-xs btn-ghost" aria-label="Delete {{ $d->name }}">{{ icon('trash') }}</button></form>
          </div></td>
        </tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>

<div class="card"><h3>Bulk discounts</h3>
  <p class="small muted">A lower price per piece when the customer rents more of an item on the same day. Customers see one line on the item page, for example "₱5 off per piece for orders of 100+".</p>
  @if($products->isEmpty())
    @include('partials.empty', ['emoji' => '📦', 'title' => 'No items yet', 'text' => 'Add a product first.'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th><th class="num">Base price</th><th>Bulk discount</th><th></th></tr></thead><tbody>
      @foreach($products as $p)
        @php($line = \App\Domain\Pricing::bulkLine($p))
        <tr><td data-label="Item"><b>{{ $p->name }}</b>@if($p->isSale()) <span class="badge b-gold plain">For sale</span>@endif</td>
          <td data-label="Base price" class="num">{{ money($p->basePrice()) }} <span class="small muted">/ {{ $p->unit }}</span></td>
          <td data-label="Bulk discount">{!! $line ? e($line) : '<span class="muted">None</span>' !!}</td>
          <td class="right"><button class="btn btn-xs" data-modal="b{{ $p->id }}">{{ icon('edit') }}{{ $line ? 'Edit' : 'Add' }}</button></td></tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>

{{-- shop discount form: new + one per discount --}}
@foreach($list->prepend(new \App\Models\Discount(['kind' => 'percent', 'applies_to' => 'all', 'active' => true])) as $d)
  @php($id = $d->exists ? 'd'.$d->id : 'dNew')
  @php($ids = array_map('intval', $d->product_ids ?? []))
  <div class="modal-backdrop hidden" id="{{ $id }}"><div class="modal" role="dialog" aria-modal="true" aria-label="{{ $d->exists ? 'Edit discount' : 'New discount' }}"><div class="modal-head"><h3>{{ $d->exists ? 'Edit '.$d->name : 'New discount' }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ $d->exists ? route('supplier.discounts.update', $d) : route('supplier.discounts.store') }}" data-disc-form>@csrf @if($d->exists) @method('PUT') @endif<div class="modal-body">
      <div class="field"><label for="{{ $id }}n">Name</label><input class="input" id="{{ $id }}n" name="name" required maxlength="80" value="{{ $d->name }}" placeholder="e.g. December promo"></div>
      <div class="field"><span class="label">What does it take off?</span>
        <div class="disc-kinds" role="radiogroup">
          @foreach(\App\Models\Discount::KINDS as $k => [$kl, $kh])
            <label class="disc-kind"><input type="radio" name="kind" value="{{ $k }}" @checked(($d->kind ?: 'percent') === $k) data-kind><span><b>{{ $kl }}</b><small>{{ $kh }}</small></span></label>
          @endforeach
        </div></div>
      <div class="field"><label for="{{ $id }}v">Amount</label>
        <div class="input-affix"><span data-pre>₱</span><input class="input" type="number" id="{{ $id }}v" name="value" min="0.01" step="0.01" required value="{{ $d->value }}" placeholder="e.g. 10"><span data-suf>%</span></div>
        <div class="hint disc-example" data-example></div></div>
      <div class="field"><span class="label">Applies to</span>
        <div class="row wrap gap-sm">
          <label class="chip {{ $d->applies_to !== 'products' ? 'active' : '' }}"><input type="radio" name="applies_to" value="all" hidden @checked($d->applies_to !== 'products') data-scope>All items</label>
          <label class="chip {{ $d->applies_to === 'products' ? 'active' : '' }}"><input type="radio" name="applies_to" value="products" hidden @checked($d->applies_to === 'products') data-scope>Selected items</label>
        </div>
        <div class="disc-items mt-1" @if($d->applies_to !== 'products') hidden @endif>
          <div class="pick-chips" data-chips></div>
          <button type="button" class="btn btn-sm mt-1" data-pick-open>{{ icon('plus') }}Choose items</button>
          {{-- item picker popup: search, picked items on top as removable chips --}}
          <div class="picker hidden" data-picker role="dialog" aria-modal="true" aria-label="Choose items"><div class="picker-box">
            <div class="modal-head"><h3>Choose items</h3><button type="button" class="icon-btn" data-pick-close aria-label="Close">{{ icon('x') }}</button></div>
            <div class="picker-body">
              <div class="mk-search picker-search"><span>{{ icon('search') }}</span><input type="search" placeholder="Search your items…" aria-label="Search items" data-pick-q></div>
              <div class="small muted mt-1 mb-1" data-pick-count></div>
              <div class="pick-chips" data-chips></div>
              <div class="pick-list" data-pick-list>
                @foreach($products as $p)<label class="pick-row" data-name="{{ mb_strtolower($p->name) }}"><input type="checkbox" name="product_ids[]" value="{{ $p->id }}" @checked(in_array($p->id, $ids, true)) data-pick data-label="{{ $p->name }}"><span class="grow">{{ $p->name }}</span><span class="small muted">{{ money($p->basePrice()) }}</span><span class="pick-add">{{ icon('plus') }}</span></label>@endforeach
                <div class="small muted hidden" data-pick-none style="padding:12px">No items match.</div>
              </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-primary" data-pick-close>Done</button></div>
          </div></div>
        </div></div>
      <div class="field" data-for-item><label for="{{ $id }}m">Minimum pieces <span class="muted">(optional)</span></label><input class="input" type="number" id="{{ $id }}m" name="min_qty" min="2" value="{{ $d->min_qty }}" placeholder="No minimum"><div class="hint">Only dates with at least this many pieces of the item get the discount.</div></div>
      <div class="field" data-for-order hidden><label for="{{ $id }}ma">Minimum order amount (₱) <span class="muted">(optional)</span></label><input class="input" type="number" id="{{ $id }}ma" name="min_amount" min="1" step="0.01" value="{{ $d->min_amount }}" placeholder="No minimum"><div class="hint">The covered items in the order must add up to at least this much.</div></div>
      <div class="form-grid"><div class="field"><label for="{{ $id }}s">From <span class="muted">(optional)</span></label><input class="input" type="date" id="{{ $id }}s" name="starts_on" value="{{ $d->starts_on?->format('Y-m-d') }}"></div>
        <div class="field"><label for="{{ $id }}e">Until <span class="muted">(optional)</span></label><input class="input" type="date" id="{{ $id }}e" name="ends_on" value="{{ $d->ends_on?->format('Y-m-d') }}"></div></div>
      <div class="hint">The dates are when the customer places the request.</div>
    </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save discount</button></div></form>
  </div></div>
@endforeach

{{-- bulk discount form per item --}}
@foreach($products as $p)
  @php($tiers = \App\Domain\Pricing::sortedTiers($p))
  <div class="modal-backdrop hidden" id="b{{ $p->id }}"><div class="modal" role="dialog" aria-modal="true" aria-label="Bulk discount"><div class="modal-head"><h3>Bulk discount · {{ $p->name }}</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
    <form method="POST" action="{{ route('supplier.discounts.bulk', $p) }}">@csrf @method('PUT')<div class="modal-body">
      <div class="field"><label for="bp{{ $p->id }}">Base price (₱ / {{ $p->unit }})</label><input class="input" type="number" id="bp{{ $p->id }}" name="base_price" min="0.01" step="0.01" required value="{{ $tiers[0]['price'] ?? '' }}"></div>
      <div class="label mb-1">Bulk discount <span class="muted">(optional)</span></div>
      <div data-bulk-rows>
        @foreach(array_slice($tiers, 1) as $i => $t)
          <div class="form-grid mb-1" data-bulk-row style="grid-template-columns:1fr 1fr auto;align-items:end"><div class="field"><label class="small">From (pieces)</label><input class="input input-sm" type="number" min="2" name="bulk[{{ $i }}][min]" value="{{ $t['min'] }}"></div><div class="field"><label class="small">Price (₱)</label><input class="input input-sm" type="number" min="0.01" step="0.01" name="bulk[{{ $i }}][price]" value="{{ $t['price'] }}"></div><div class="field"><button type="button" class="btn btn-xs btn-ghost" data-bulk-rm aria-label="Remove">✕</button></div></div>
        @endforeach
      </div>
      <button type="button" class="btn btn-sm" data-bulk-add>{{ icon('plus') }}Add a bulk price</button>
    </div><div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>
  </div></div>
@endforeach

@push('scripts')
<script>
(function () {
  var esc = function (t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var peso = function (n) { return '₱' + (Math.round(n * 100) / 100).toLocaleString('en-PH', { maximumFractionDigits: 2 }); };
  document.querySelectorAll('[data-disc-form]').forEach(function (f) {
    var box = f.querySelector('.disc-items'), picker = f.querySelector('[data-picker]'), q = f.querySelector('[data-pick-q]');
    var val = f.querySelector('input[name=value]'), ex = f.querySelector('[data-example]');
    // type cards: % or ₱ affix, the right minimum, a worked example
    function kind() { var k = f.querySelector('[data-kind]:checked'); return k ? k.value : 'percent'; }
    function syncKind() {
      var k = kind(), pct = k.indexOf('percent') >= 0, order = k.indexOf('order_') === 0, v = +val.value || 0;
      f.querySelector('[data-pre]').hidden = pct; f.querySelector('[data-suf]').hidden = !pct;
      val.max = pct ? 90 : '';
      f.querySelector('[data-for-item]').hidden = order; f.querySelector('[data-for-order]').hidden = !order;
      f.querySelectorAll('[data-kind]').forEach(function (r) { r.closest('.disc-kind').classList.toggle('on', r.checked); });
      var txt = !v ? 'Enter an amount to see an example.'
        : k === 'percent' ? 'Example: a ₱150 chair becomes ' + peso(150 * (1 - Math.min(v, 90) / 100)) + ' per piece.'
        : k === 'amount' ? 'Example: a ₱150 chair becomes ' + peso(Math.max(0, 150 - v)) + ' per piece; 50 chairs save ' + peso(Math.min(v, 150) * 50) + ' a day.'
        : k === 'order_percent' ? 'Example: an order of ₱5,000 of these items becomes ' + peso(5000 * (1 - Math.min(v, 90) / 100)) + '.'
        : 'Example: an order of ₱5,000 of these items becomes ' + peso(Math.max(0, 5000 - v)) + ' (taken off once).';
      ex.textContent = txt;
    }
    f.querySelectorAll('[data-kind]').forEach(function (r) { r.addEventListener('change', syncKind); });
    val.addEventListener('input', syncKind); syncKind();
    // "Selected items" reveals the picked items + the picker button
    f.querySelectorAll('[data-scope]').forEach(function (r) { r.addEventListener('change', function () {
      box.hidden = !(r.checked && r.value === 'products');
      f.querySelectorAll('[data-scope]').forEach(function (x) { x.closest('.chip').classList.toggle('active', x.checked); });
      if (!box.hidden && !f.querySelector('[data-pick]:checked')) openPicker();
    }); });
    // picker: picked items become chips on top (× removes), the rest stay in the searchable list
    function draw() {
      var picked = Array.prototype.filter.call(f.querySelectorAll('[data-pick]'), function (c) { return c.checked; });
      var html = picked.map(function (c) { return '<span class="pick-chip">' + esc(c.dataset.label) + '<button type="button" data-unpick="' + c.value + '" aria-label="Remove ' + esc(c.dataset.label) + '">×</button></span>'; }).join('');
      f.querySelectorAll('[data-chips]').forEach(function (el) { el.innerHTML = html || '<span class="small muted">No items chosen yet.</span>'; });
      var term = (q.value || '').trim().toLowerCase(), shown = 0;
      f.querySelectorAll('.pick-row').forEach(function (row) { var c = row.querySelector('[data-pick]'); var on = !c.checked && (!term || row.dataset.name.indexOf(term) >= 0); row.hidden = !on; if (on) shown++; });
      f.querySelector('[data-pick-none]').classList.toggle('hidden', shown > 0);
      f.querySelector('[data-pick-count]').textContent = picked.length + ' chosen';
      var ob = f.querySelector('[data-pick-open]'); if (ob) ob.lastChild.textContent = picked.length ? 'Change items' : 'Choose items';
    }
    function openPicker() { picker.classList.remove('hidden'); q.value = ''; draw(); setTimeout(function () { q.focus(); }, 30); }
    f.querySelector('[data-pick-open]').addEventListener('click', openPicker);
    picker.addEventListener('click', function (e) { if (e.target === picker || e.target.closest('[data-pick-close]')) picker.classList.add('hidden'); });
    f.addEventListener('change', function (e) { if (e.target.matches('[data-pick]')) { draw(); if (e.target.checked) { q.value = ''; draw(); q.focus(); } } });
    f.addEventListener('click', function (e) { var u = e.target.closest('[data-unpick]'); if (u) { e.preventDefault(); var c = f.querySelector('[data-pick][value="' + u.dataset.unpick + '"]'); if (c) { c.checked = false; draw(); } } });
    q.addEventListener('input', draw);
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var first = Array.prototype.find.call(f.querySelectorAll('.pick-row'), function (r) { return !r.hidden; }); if (first) { first.querySelector('[data-pick]').checked = true; q.value = ''; draw(); } } });
    f.addEventListener('submit', function (e) {
      if (f.querySelector('[data-scope][value=products]').checked && !f.querySelector('[data-pick]:checked')) { e.preventDefault(); e.stopImmediatePropagation(); window.PR.toast('Choose at least one item, or apply the discount to all items.', 'warn'); openPicker(); }
    }, true);
    draw();
  });
  // bulk rows: add / remove
  var n = 100;
  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-bulk-add]');
    if (add) {
      var wrap = add.parentNode.querySelector('[data-bulk-rows]'), i = n++;
      if (wrap.querySelectorAll('[data-bulk-row]').length >= 7) { window.PR.toast('Up to 7 bulk prices.', 'warn'); return; }
      wrap.insertAdjacentHTML('beforeend', '<div class="form-grid mb-1" data-bulk-row style="grid-template-columns:1fr 1fr auto;align-items:end"><div class="field"><label class="small">From (pieces)</label><input class="input input-sm" type="number" min="2" name="bulk[' + i + '][min]" required></div><div class="field"><label class="small">Price (₱)</label><input class="input input-sm" type="number" min="0.01" step="0.01" name="bulk[' + i + '][price]" required></div><div class="field"><button type="button" class="btn btn-xs btn-ghost" data-bulk-rm aria-label="Remove">✕</button></div></div>');
      return;
    }
    var rm = e.target.closest('[data-bulk-rm]'); if (rm) rm.closest('[data-bulk-row]').remove();
  });
})();
</script>
@endpush
@endsection
