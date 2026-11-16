@extends('layouts.market', ['title' => 'AI Event Planner', 'nav' => 'ai'])

@section('content')
<div class="ai-hero">
  <div><span class="ai-badge">{{ icon('sparkle') }}PartyRent Assist</span><h1 class="mt-1 mb-0">AI Event Planner & Search</h1><p class="muted mt-1">Describe your event in English or Filipino. The assistant turns it into filters you can edit, then suggests items that are <b>actually listed and available</b> on PartyRent.</p></div>
  <form method="GET" action="{{ route('ai') }}">
    <div class="field mt-2"><label for="aiq" class="sr-only">Describe your event</label><textarea class="input" id="aiq" name="q" rows="3" maxlength="1000" placeholder="e.g. Debut for 120 guests, pink and white, need Tiffany chairs, backdrop and lights…">{{ $q }}</textarea></div>
    <div class="row wrap"><button class="btn btn-primary btn-lg">{{ icon('sparkle') }}Interpret my event</button><span class="small muted">Try:</span>
      @foreach($examples as $label => $text)<a class="chip" href="{{ route('ai', ['q' => $text]) }}">{{ $label }}</a>@endforeach
    </div>
  </form>
  <p class="demo-note mt-2">{{ icon('info', 'width:14px') }}{{ \App\Domain\Ai::LABEL }}. It never books, prices or reserves anything — you stay in control.</p>
</div>

@if($filters)
@php($f = $filters)
<div class="card mt-3">
  <div class="card-head"><h2>1 · Review what I understood</h2><span class="badge b-purple plain">{{ $f['language'] }} input</span></div>
  <p class="small muted">Edit anything that's wrong — your edits are what I'll use.</p>
  <form method="GET" action="{{ route('ai') }}" class="form-grid">
    <input type="hidden" name="plan" value="1"><input type="hidden" name="q" value="{{ $f['raw'] }}"><input type="hidden" name="language" value="{{ $f['language'] }}">
    <div class="field"><label>Event type</label><select class="input" name="eventType"><option value="">Not specified</option>@foreach(array_keys(\App\Domain\Ai::EVENTS) as $e)<option @selected($f['eventType'] === $e)>{{ $e }}</option>@endforeach</select></div>
    <div class="field"><label>Number of guests</label><input class="input" type="number" min="1" max="2000" name="guests" value="{{ $f['guests'] }}" placeholder="e.g. 50"></div>
    <div class="field"><label>Start date</label><input class="input" type="date" name="start" min="{{ today_iso() }}" value="{{ $f['start'] }}"></div>
    <div class="field"><label>End date</label><input class="input" type="date" name="end" min="{{ today_iso() }}" value="{{ $f['end'] }}"></div>
    <div class="field"><label>Location type</label><select class="input" name="locationType"><option value="">Not specified</option><option @selected($f['locationType'] === 'Outdoor')>Outdoor</option><option @selected($f['locationType'] === 'Indoor')>Indoor</option></select></div>
    <div class="field"><label for="aiCity">Preferred supplier city</label><input class="input" id="aiCity" name="city" list="aiCities" autocomplete="off" value="{{ $f['city'] }}" placeholder="Anywhere — start typing a city">
      <datalist id="aiCities">@foreach($cities as $c => $n)<option value="{{ $c }}">{{ $n }} supplier{{ $n > 1 ? 's' : '' }}</option>@endforeach</datalist>
      <span class="small muted">Leave blank to see suppliers from anywhere.</span></div>
    <div class="field"><label>Budget (optional)</label><input class="input" type="number" min="0" name="budget" value="{{ $f['budget'] }}" placeholder="₱"></div>
    <div class="field"><label>Fulfillment</label><select class="input" name="delivery"><option value="">Either</option><option value="1" @selected($f['delivery'] === true)>Delivery</option><option value="0" @selected($f['delivery'] === false)>Pickup</option></select></div>
    <div class="field full"><label>Colors</label><div class="row wrap gap-sm">@foreach(array_keys(\App\Domain\Ai::COLORS) as $c)<label class="chip {{ in_array($c, $f['colors'], true) ? 'active' : '' }}"><input type="checkbox" name="colors[]" value="{{ $c }}" @checked(in_array($c, $f['colors'], true)) hidden><span class="swatch" style="background:{{ color_hex($c) }}"></span> {{ $c }}</label>@endforeach</div></div>
    <div class="field full"><label>Theme</label><div class="row wrap gap-sm">@foreach(array_keys(\App\Domain\Ai::THEMES) as $c)<label class="chip {{ in_array($c, $f['themes'], true) ? 'active' : '' }}"><input type="checkbox" name="themes[]" value="{{ $c }}" @checked(in_array($c, $f['themes'], true)) hidden>{{ $c }}</label>@endforeach</div></div>
    <div class="field full"><label>Items needed <span class="muted">(leave empty to let me suggest a checklist)</span></label><div class="row wrap gap-sm">@foreach(array_keys(\App\Domain\Ai::ITEMS) as $c)<label class="chip {{ in_array($c, $f['items'], true) ? 'active' : '' }}"><input type="checkbox" name="items[]" value="{{ $c }}" @checked(in_array($c, $f['items'], true)) hidden>{{ $cats[$c] ?? $c }}</label>@endforeach</div></div>
    <div class="full row wrap"><button class="btn btn-primary">{{ icon('sparkle') }}Suggest supplies</button>
      <button class="btn" name="search" value="1">{{ icon('search') }}Just search listings with these filters</button></div>
  </form>
</div>
@endif

@if($plan)
@php($dates = $plan['dates'])
<form method="POST" action="{{ route('ai.plan') }}" class="card pad-0 mt-3">
  @csrf
  <input type="hidden" name="start" value="{{ $dates[0] ?? '' }}"><input type="hidden" name="end" value="{{ $dates ? end($dates) : '' }}">
  <div class="card-head" style="padding:18px 18px 0"><div><h2>2 · Suggested checklist</h2><div class="small muted">{{ $plan['filters']['eventType'] ?: 'Event' }}{{ $plan['filters']['guests'] ? ' · '.$plan['filters']['guests'].' guests' : '' }} · {{ $dates ? fmt_range($dates[0], end($dates)).' ('.count($dates).' day'.(count($dates) > 1 ? 's' : '').')' : 'no dates yet — availability shown for today' }}</div></div><div class="right"><div class="small muted">Estimated total (available picks)</div><div class="price-lg">{{ money($plan['total']) }}</div></div></div>
  @if($plan['overBudget'])<div class="alert alert-warn" style="margin:12px 18px 0">{{ icon('alert') }}<div>The suggested items exceed your budget of {{ money($plan['filters']['budget']) }}. Untick optional items or lower quantities.</div></div>@endif
  @if(! $dates)<div class="alert alert-info" style="margin:12px 18px 0">{{ icon('info') }}<div>Add your event dates above to check real availability for those days.</div></div>@endif
  <div class="mt-1">
    @foreach($plan['rows'] as $row)
      @php($b = $row['best'])
      @if(! $b)
        <div class="sugg-row"><div class="thumb sm"></div><div><b>{{ $cats[$row['category']] ?? $row['category'] }}</b><div class="small muted">No listings in this category yet.</div></div></div>
      @else
        <div class="sugg-row">
          <label class="check"><input type="checkbox" name="pick[{{ $b['product']->id }}]" value="1" @checked($b['enough']) aria-label="Select {{ $b['product']->name }}"></label>
          <div class="row" style="gap:10px;min-width:0">@include('partials.thumb', ['item' => $b['product'], 'cls' => 'sm'])<div style="min-width:0"><div class="small muted strong">{{ $cats[$row['category']] ?? '' }}</div><a class="strong" href="{{ route('products.show', array_filter(['product' => $b['product']->id, 'start' => $dates[0] ?? null, 'end' => $dates ? end($dates) : null])) }}">{{ $b['product']->name }}</a><div class="small muted">{{ $b['reason'] }}</div></div></div>
          <div class="s-qty"><div class="small muted">Qty / day</div><input type="number" class="input input-sm" min="1" name="qty[{{ $b['product']->id }}]" value="{{ $b['qty'] }}" style="width:90px" aria-label="Suggested quantity"></div>
          <div class="s-sup small"><b>{{ $b['supplier']->name }}</b> · {{ $b['supplier']->city }}<br>@if($b['enough'])<span class="badge b-green">{{ $b['available'] }} available</span>@else<span class="badge b-red">Only {{ $b['available'] }} available</span>@endif
            @if($row['alternatives'])<details class="mt-1"><summary class="small strong" style="cursor:pointer">{{ count($row['alternatives']) }} other options</summary>@foreach($row['alternatives'] as $a)<div class="small mt-1"><a href="{{ route('products.show', array_filter(['product' => $a['product']->id, 'start' => $dates[0] ?? null, 'end' => $dates ? end($dates) : null, 'qty' => $a['qty']])) }}">{{ $a['product']->name }}</a> · {{ $a['supplier']->name }} · {{ money(\App\Domain\Pricing::unitPrice($a['product'], $a['qty'])) }} · {{ $a['enough'] ? $a['available'].' available' : 'only '.$a['available'] }}</div>@endforeach</details>@endif
          </div>
          <div class="s-cost"><div class="small muted">Est. cost</div><b>{{ money($b['cost']) }}</b></div>
          <div class="s-act small muted">{{ money(\App\Domain\Pricing::unitPrice($b['product'], $b['qty'])) }}/{{ $b['product']->unitWord() }}</div>
        </div>
      @endif
    @endforeach
  </div>
  @php($pickable = collect($plan['rows'])->filter(fn ($r) => $r['best'])->count())
  <div class="row wrap between" style="padding:16px 18px;border-top:1px solid var(--line)">
    @if($pickable)
      <span class="small muted" style="flex:1 1 320px">Prices come from each supplier's listing. Estimates use the same quantity each day — you can change per-day quantities in your Rental List.</span>
      <div class="right"><button class="btn btn-primary" id="aiAdd">{{ icon('cart') }}Add selected to Rental List</button><div class="small" id="aiAddHint" style="color:var(--red,#dc2626)" hidden>Check the box beside at least one item first.</div></div>
    @else
      <span class="small muted">No suppliers have listed these items yet, so there's nothing to add. Try other items above, or browse all listings.</span>
      <button class="btn btn-primary" type="button" disabled>{{ icon('cart') }}Nothing to add yet</button>
    @endif
  </div>
</form>
@push('scripts')
<script>
(function () {
  var btn = document.getElementById('aiAdd'); if (!btn) return;
  var form = btn.form, hint = document.getElementById('aiAddHint');
  form.addEventListener('submit', function (e) {
    if (!form.querySelector('input[name^="pick["]:checked')) { e.preventDefault(); hint.hidden = false; }
  });
  form.addEventListener('change', function () { hint.hidden = true; });
})();
</script>
@endpush
@if($plan['packages'])
  <div class="section"><div class="section-title"><h2>Or choose a ready-made package</h2></div><div class="product-grid">@foreach(array_slice($plan['packages'], 0, 4) as $x)@include('partials.package-card', ['k' => $x['pkg'], 'dates' => $dates])@endforeach</div></div>
@endif
@endif
@endsection
