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

  <div class="field"><label>Package</label>
    <div class="stack-sm">
      @foreach($packages as $k)
        <label class="card card-flat row" style="padding:10px 12px;cursor:pointer;gap:10px"><input type="radio" name="event_package_id" value="{{ $k->id }}" @checked((int) $sel === $k->id)><div class="grow"><b>{{ $k->name }}</b><div class="small muted">{{ $k->guestsLabel() }} · from {{ money($k->price) }}</div></div></label>
      @endforeach
      <label class="card card-flat row" style="padding:10px 12px;cursor:pointer;gap:10px"><input type="radio" name="event_package_id" value="" @checked(! $sel)><div><b>Custom event</b><div class="small muted">Not sure yet, or something different: describe it below.</div></div></label>
    </div>
  </div>
  <div class="form-grid">
    <div class="field @error('event_type') invalid @enderror"><label>Type of event</label><input class="input" name="event_type" required maxlength="60" list="evTypes" value="{{ old('event_type', $pkg?->events[0] ?? '') }}" placeholder="e.g. Wedding"><datalist id="evTypes">@foreach(array_unique(array_merge(array_keys(\App\Domain\Ai::EVENTS), $s->profile('events', []))) as $e)<option value="{{ $e }}">@endforeach</datalist>@error('event_type')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="field @error('guests') invalid @enderror"><label>Number of guests</label><input class="input" type="number" name="guests" min="1" required value="{{ old('guests', $pkg?->max_guests ?? $pkg?->min_guests) }}">@error('guests')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="field @error('event_date') invalid @enderror"><label>Event date</label><input class="input" type="date" name="event_date" required min="{{ today()->addDays((int) $set['minNoticeDays'])->toDateString() }}" value="{{ old('event_date') }}">@error('event_date')<div class="error-text">{{ $message }}</div>@enderror<div class="hint">At least {{ $set['minNoticeDays'] }} days from today.</div></div>
    <div class="field"><label>Time <span class="muted small">(optional)</span></label><div class="row" style="gap:8px"><input class="input" type="time" name="start_time" value="{{ old('start_time') }}"><span class="muted">to</span><input class="input" type="time" name="end_time" value="{{ old('end_time') }}"></div></div>
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
@push('head')<style>@media (max-width: 860px) { #evGrid { grid-template-columns: 1fr !important; } }</style>@endpush
@endsection
