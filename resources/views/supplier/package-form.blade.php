@extends('layouts.dash', ['title' => $k->exists ? 'Edit package' : 'New package', 'nav' => 'packages'])

@php($items = old('items', $k->items ?: [['product_id' => '', 'qty' => 1]]))
@section('content')
<div class="crumbs"><a href="{{ route('supplier.packages') }}">Packages</a> / {{ $k->exists ? $k->name : 'New package' }}</div>
<div class="page-head"><div><h1>{{ $k->exists ? 'Edit '.$k->name : 'New package' }}</h1></div></div>
<form method="POST" action="{{ $k->exists ? route('supplier.packages.update', $k) : route('supplier.packages.store') }}" class="grid grid-side">
  @csrf @if($k->exists) @method('PUT') @endif
  <div class="stack">
    <div class="card"><h3>Package details</h3>
      <div class="form-grid" style="grid-template-columns:100px 1fr"><div class="field"><label>Emoji</label><input class="input" name="emoji" value="{{ old('emoji', $k->emoji) }}" maxlength="4"></div><div class="field"><label>Package name</label><input class="input" name="name" required value="{{ old('name', $k->name) }}"></div></div>
      <div class="field"><label>Description</label><textarea class="input" name="description" required>{{ old('description', $k->description) }}</textarea></div>
      <div class="field"><label>Conditions</label><textarea class="input" name="conditions" placeholder="e.g. Price is per day. Setup included with delivery.">{{ old('conditions', $k->conditions) }}</textarea></div>
      <div class="field"><label>Events</label><div class="row wrap gap-sm">@foreach($events as $e)@php($on = in_array($e, old('events', $k->events ?? []), true))<label class="chip {{ $on ? 'active' : '' }}"><input type="checkbox" name="events[]" value="{{ $e }}" @checked($on) hidden>{{ $e }}</label>@endforeach</div></div>
    </div>
    <div class="card"><h3>Included items</h3>
      <div id="pitems">
        @foreach($items as $i => $it)
          <div class="form-grid pi-row" style="grid-template-columns:1fr 120px auto;align-items:end">
            <div class="field"><label class="small">Item</label><select class="input input-sm" name="items[{{ $i }}][product_id]"><option value="">Select…</option>@foreach($prods as $pr)<option value="{{ $pr->id }}" @selected((string) ($it['product_id'] ?? '') === (string) $pr->id)>{{ $pr->name }} ({{ $pr->total_stock }} in stock)</option>@endforeach</select></div>
            <div class="field"><label class="small">Qty per package</label><input class="input input-sm" type="number" min="1" name="items[{{ $i }}][qty]" value="{{ $it['qty'] ?? 1 }}"></div>
            <div class="field"><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest('.pi-row').remove()" aria-label="Remove item">{{ icon('trash') }}</button></div>
          </div>
        @endforeach
      </div>
      <button type="button" class="btn btn-sm" id="addItem">{{ icon('plus') }}Add item</button>
    </div>
  </div>
  <aside class="card sticky-side"><h3>Price</h3>
    <div class="field"><label>Package price per day (₱)</label><input class="input" type="number" min="1" step="0.01" name="price" required value="{{ old('price', $k->price) }}"></div>
    <label class="check mb-2"><input type="checkbox" name="active" value="1" @checked(old('active', $k->active ?? true))> Active (visible to customers)</label>
    <button class="btn btn-primary btn-block btn-lg">{{ $k->exists ? 'Save changes' : 'Create package' }}</button>
  </aside>
</form>
@push('scripts')
<script>
document.getElementById('addItem').addEventListener('click', function () {
  var wrap = document.getElementById('pitems'), rows = wrap.querySelectorAll('.pi-row'), last = rows[rows.length - 1]; if (!last) return;
  var row = last.cloneNode(true), i = Date.now();
  row.querySelectorAll('select,input').forEach(function (el) { el.name = el.name.replace(/items\[[^\]]+\]/, 'items[' + i + ']'); if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = 1; });
  wrap.appendChild(row);
});
</script>
@endpush
@endsection
