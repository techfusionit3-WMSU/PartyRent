@extends('layouts.dash', ['title' => $k->exists ? 'Edit event package' : 'New event package', 'nav' => 'packages'])

@section('content')
@php
    $events = old('events', $k->events ?? $s->profile('events', []));
    $inc = old('inclusions', $k->inclusions ?? []);
    $incSuggest = ['Event coordinator on the day', 'Styling & décor', 'Backdrop & stage', 'Tables & chairs with covers', 'Lights & sound system', 'Host / emcee', 'Photo & video coverage', 'Catering for the guest count', 'Setup & teardown', 'Tent', 'Balloon décor', 'Cake & dessert table', 'Souvenirs', 'Travel within service areas'];
@endphp
<div class="crumbs"><a href="{{ route('business.packages') }}">Event Packages</a> / {{ $k->exists ? $k->name : 'New' }}</div>
<div class="page-head"><div><h1>{{ $k->exists ? 'Edit event package' : 'New event package' }}</h1><p>Keep it simple: what the customer gets, for how many guests, and the starting price.</p></div></div>
<form class="card" method="POST" enctype="multipart/form-data" action="{{ $k->exists ? route('business.packages.update', $k) : route('business.packages.store') }}" style="max-width:820px">
  @csrf @if($k->exists) @method('PUT') @endif
  <div class="field @error('name') invalid @enderror"><label>Package name</label><input class="input" name="name" required minlength="3" maxlength="120" value="{{ old('name', $k->name) }}" placeholder="e.g. Classic Debut Package">@error('name')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field @error('description') invalid @enderror"><label>Description</label><textarea class="input" name="description" required minlength="20" placeholder="The look, the feel, and how the day runs.">{{ old('description', $k->description) }}</textarea>@error('description')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="form-grid">
    <div class="field @error('price') invalid @enderror"><label>Starting price (₱ per event)</label><input class="input" type="number" min="1" step="1" name="price" required value="{{ old('price', $k->price) }}">@error('price')<div class="error-text">{{ $message }}</div>@enderror<div class="hint">Shown as "from ₱…". Your quotation sets the final price.</div></div>
    <div class="field"><label>Guests</label><div class="row" style="gap:8px"><input class="input" type="number" min="1" name="min_guests" value="{{ old('min_guests', $k->min_guests) }}" placeholder="min"><span class="muted">to</span><input class="input" type="number" min="1" name="max_guests" value="{{ old('max_guests', $k->max_guests) }}" placeholder="max"></div>@error('max_guests')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
  </div>
  <div class="field @error('events') invalid @enderror"><label>Event types this package is for</label>
    <div class="row wrap gap-sm">@foreach($verified as $name => $vt)<label class="check card card-flat" style="padding:8px 12px"><input type="checkbox" name="events[]" value="{{ $name }}" @checked(in_array($name, (array) $events, true))> {{ $name }} <span class="small muted">· up to {{ number_format($vt->max_guests) }} guests</span></label>@endforeach</div>
    @error('events')<div class="error-text" style="display:block">{{ $message }}</div>@enderror
    <div class="hint">Only your verified event types are listed. Need another one? Apply on the <a href="{{ route('business.event-types') }}">Event Types</a> page.</div></div>
  @php($partRows = array_pad(array_values((array) old('parts', $k->parts ?? [])), max(4, count((array) old('parts', $k->parts ?? [])) + 2), ['name' => '', 'optional' => false, 'price' => '']))
  <div class="field @error('parts') invalid @enderror"><label>Parts of this event</label>
    <div class="hint mb-1">Each part gets its own date when the customer asks (e.g. Prenup shoot, Ceremony, Reception; or Day 1, Day 2). Tick "optional" for a part the customer may add, with its extra price. Leave a row empty to drop it.</div>
    <div class="stack-sm">@foreach($partRows as $i => $pr)
      <div class="row wrap" style="gap:8px;align-items:center"><input class="input" name="parts[{{ $i }}][name]" maxlength="60" value="{{ $pr['name'] ?? '' }}" placeholder="Part {{ $i + 1 }}" style="flex:2;min-width:160px" aria-label="Part {{ $i + 1 }} name">
        <label class="check small"><input type="checkbox" name="parts[{{ $i }}][optional]" value="1" @checked(! empty($pr['optional']))> Optional</label>
        <input class="input" type="number" min="0" step="1" name="parts[{{ $i }}][price]" value="{{ ! empty($pr['optional']) && ($pr['price'] ?? 0) > 0 ? (int) $pr['price'] : '' }}" placeholder="Extra ₱ if optional" style="width:150px" aria-label="Part {{ $i + 1 }} extra price"></div>
    @endforeach</div>
    @error('parts')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
  <div class="field @error('inclusions') invalid @enderror"><label>What's included</label>@include('partials.tag-input', ['name' => 'inclusions', 'values' => $inc, 'suggest' => $incSuggest, 'placeholder' => 'Type an inclusion, e.g. 2-layer cake'])@error('inclusions')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field"><label>Photos <span class="muted small">(up to 6; the first is the cover)</span></label>
    @if($k->photos)<div class="bp-grid mb-1">@foreach($k->photos as $ph)<label class="bp-thumb"><img src="{{ asset('storage/'.$ph) }}" alt=""><span class="check small"><input type="checkbox" name="photos_keep[]" value="{{ $ph }}" checked> Keep</span></label>@endforeach</div>@endif
    <input class="input" type="file" name="photos[]" accept="image/*" multiple>@error('photos.*')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
  <label class="check mb-2"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', $k->active ?? true))> Visible to customers</label>
  <div class="row gap-sm"><button class="btn btn-primary">{{ icon('check') }}Save package</button><a class="btn" href="{{ route('business.packages') }}">Cancel</a></div>
</form>
@endsection
