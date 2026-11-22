@extends('layouts.market', ['title' => 'Book '.$s->name, 'nav' => 'suppliers'])

@section('content')
@php($sel = old('event_package_id', $pkg?->id))
<div class="crumbs"><a href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a> / Ask for a quotation</div>
<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start" id="evGrid">
<form class="card" method="POST" action="{{ route('events.store', $s) }}">
  @csrf
  <h1 class="mb-0">Ask {{ $s->name }} for a quotation</h1>
  <p class="muted">Tell them about your event. They reply with a price and payment schedule; nothing is booked or paid until you accept.</p>
  @if(session('error'))<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ session('error') }}</div></div>@endif
  @if($types->isEmpty())<div class="alert alert-warn mb-2">{{ icon('clock') }}<div>{{ $s->name }} is not taking event bookings yet: PartyRent has not verified the kinds of events they handle.</div></div>@endif

  <div class="field"><label>Package</label>
    <div class="stack-sm">
      @foreach($packages as $k)
        <label class="card card-flat row" style="padding:10px 12px;cursor:pointer;gap:10px"><input type="radio" name="event_package_id" value="{{ $k->id }}" @checked((int) $sel === $k->id)><div class="grow"><b>{{ $k->name }}</b><div class="small muted">{{ $k->guestsLabel() }} · from {{ money($k->price) }}</div></div></label>
      @endforeach
      <label class="card card-flat row" style="padding:10px 12px;cursor:pointer;gap:10px"><input type="radio" name="event_package_id" value="" @checked(! $sel)><div><b>Custom event</b><div class="small muted">Not sure yet, or something different: describe it below.</div></div></label>
    </div>
  </div>
  <div class="form-grid">
    <div class="field @error('event_type') invalid @enderror"><label for="ev-type">Type of event</label><select class="input" id="ev-type" name="event_type" required>@php($evSel = old('event_type', $pkg?->events[0] ?? ''))<option value="">Choose…</option>@foreach($types as $name => $vt)<option value="{{ $name }}" data-max="{{ $vt->max_guests }}" @selected($evSel === $name)>{{ $name }} · up to {{ number_format($vt->max_guests) }} guests</option>@endforeach</select>@error('event_type')<div class="error-text">{{ $message }}</div>@enderror<div class="hint">The kinds of events PartyRent verified {{ $s->name }} for.</div></div>
    <div class="field @error('guests') invalid @enderror"><label>Number of guests</label><input class="input" type="number" name="guests" min="1" required value="{{ old('guests', $pkg?->max_guests ?? $pkg?->min_guests) }}">@error('guests')<div class="error-text">{{ $message }}</div>@enderror</div>
  </div>
  {{-- When: one row per part of the event (from the package, else the event type's usual parts). Optional parts are ticked to include. --}}
  <div class="field" id="evParts" data-defs='@json($defs)' data-old='@json(old('parts', []))' data-min="{{ today()->addDays((int) $set['minNoticeDays'])->toDateString() }}">
    <label>When</label>
    <div class="hint mb-1">Each part of your event gets its own date (and time or venue if different). The first part must be at least {{ $set['minNoticeDays'] }} days from today.</div>
    <div class="stack-sm" data-rows></div>
    @error('parts')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
    @error('parts.*.date')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
    <noscript><input class="input" type="date" name="event_date" required min="{{ today()->addDays((int) $set['minNoticeDays'])->toDateString() }}"></noscript>
  </div>
  <div class="field @error('venue') invalid @enderror"><label>Venue address</label><input class="input" name="venue" required minlength="5" maxlength="255" value="{{ old('venue') }}" placeholder="e.g. Grand Ballroom, Garden Orchid Hotel">@error('venue')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="form-grid">
    <div class="field"><label>City</label><input class="input" name="city" maxlength="120" list="evCities" value="{{ old('city') }}"><datalist id="evCities">@foreach($set['serviceAreas'] as $c)<option value="{{ $c }}">@endforeach</datalist></div>
    <div class="field"><label>Budget (₱) <span class="muted small">(optional)</span></label><input class="input" type="number" min="0" step="1" name="budget" value="{{ old('budget') }}"></div>
  </div>
  <div class="field"><label>Anything else? <span class="muted small">(optional)</span></label><textarea class="input" name="notes" maxlength="2000" placeholder="Theme, colors, must-haves, questions…">{{ old('notes') }}</textarea></div>
  <button class="btn btn-primary btn-lg btn-block">{{ icon('send') }}Send inquiry</button>
</form>
<aside class="card">
  <div class="row"><div class="logo-tile" style="background:{{ $s->color }}1a">@if($s->logoUrl())<img src="{{ $s->logoUrl() }}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:12px">@else{{ $s->logo }}@endif</div><div><b>{{ $s->name }}</b><div class="small muted">{{ $s->city }}</div></div></div>
  <dl class="kv mt-2">
    @if($set['serviceAreas'])<dt>Serves</dt><dd>{{ implode(', ', $set['serviceAreas']) }}</dd>@endif
    @if($set['travelNote'])<dt>Travel</dt><dd>{{ $set['travelNote'] }}</dd>@endif
    <dt>How it works</dt><dd><ol class="small" style="padding-left:18px;margin:0"><li>Send your inquiry</li><li>Get a quotation</li><li>Accept and pay the {{ rtrim(rtrim(number_format($set['reservePct'], 1), '0'), '.') }}% reservation fee to hold the date</li><li>Pay the deposit and balance before the event</li></ol></dd>
  </dl>
</aside>
</div>
@push('scripts')
<script>
(function () {
  var box = document.getElementById('evParts'); if (!box) return;
  var defs = JSON.parse(box.dataset.defs), old = JSON.parse(box.dataset.old || '[]'), min = box.dataset.min, rows = box.querySelector('[data-rows]');
  var form = box.closest('form'), type = form.querySelector('[name=event_type]');
  var esc = function (t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };
  var peso = function (n) { return '₱' + Number(n).toLocaleString('en-PH'); };
  function current() {
    var r = form.querySelector('[name=event_package_id]:checked');
    if (r && r.value && defs.pkg[r.value]) return defs.pkg[r.value];
    return defs.type[type.value] || [{ name: 'Event', optional: false, price: 0 }];
  }
  function draw() {
    var keep = {}; rows.querySelectorAll('[data-part]').forEach(function (el) { keep[el.dataset.part] = { date: el.querySelector('[data-f=date]').value, start: el.querySelector('[data-f=start]').value, end: el.querySelector('[data-f=end]').value, venue: el.querySelector('[data-f=venue]').value, include: (el.querySelector('[data-f=include]') || {}).checked }; });
    rows.innerHTML = current().map(function (p, i) {
      var o = keep[p.name] || old[i] || {}, inc = !p.optional || !!o.include;
      return '<div class="card card-flat ev-part" data-part="' + esc(p.name) + '" style="padding:10px 12px">' +
        '<div class="row between wrap" style="gap:6px"><b>' + esc(p.name) + '</b>' + (p.optional ? '<label class="check small"><input type="checkbox" data-f="include" name="parts[' + i + '][include]" value="1"' + (inc ? ' checked' : '') + '> Include' + (p.price > 0 ? ' (+' + peso(p.price) + ')' : '') + '</label>' : '<span class="small muted">included</span>') + '</div>' +
        '<div class="ev-part-f' + (inc ? '' : ' hidden') + '"><div class="form-grid mt-1">' +
        '<div class="field mb-0"><label>Date</label><input class="input" type="date" data-f="date" name="parts[' + i + '][date]" min="' + min + '" value="' + esc(o.date || '') + '"' + (inc ? ' required' : '') + '></div>' +
        '<div class="field mb-0"><label>Time <span class="muted small">(optional)</span></label><div class="row" style="gap:6px"><input class="input" type="time" data-f="start" name="parts[' + i + '][start]" value="' + esc(o.start || '') + '" aria-label="' + esc(p.name) + ' start"><span class="muted">to</span><input class="input" type="time" data-f="end" name="parts[' + i + '][end]" value="' + esc(o.end || '') + '" aria-label="' + esc(p.name) + ' end"></div></div></div>' +
        '<div class="field mb-0 mt-1"><label>Venue <span class="muted small">(only if different from the main venue)</span></label><input class="input" data-f="venue" name="parts[' + i + '][venue]" maxlength="255" value="' + esc(o.venue || '') + '"></div></div></div>';
    }).join('');
    rows.querySelectorAll('[data-f=include]').forEach(function (c) { c.onchange = function () { var f = c.closest('.ev-part').querySelector('.ev-part-f'); f.classList.toggle('hidden', !c.checked); f.querySelector('[data-f=date]').required = c.checked; }; });
  }
  form.querySelectorAll('[name=event_package_id]').forEach(function (r) { r.addEventListener('change', draw); });
  type.addEventListener('change', draw);
  draw();
})();
</script>
@endpush
@push('head')<style>@media (max-width: 860px) { #evGrid { grid-template-columns: 1fr !important; } }</style>@endpush
@endsection
