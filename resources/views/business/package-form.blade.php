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
  <div class="field"><label>Good for</label>@include('partials.tag-input', ['name' => 'events', 'values' => $events, 'suggest' => array_keys(\App\Domain\Ai::EVENTS), 'placeholder' => 'Type an event, e.g. Graduation'])</div>
  <div class="field @error('inclusions') invalid @enderror"><label>What's included</label>@include('partials.tag-input', ['name' => 'inclusions', 'values' => $inc, 'suggest' => $incSuggest, 'placeholder' => 'Type an inclusion, e.g. 2-layer cake'])@error('inclusions')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field"><label>Photos <span class="muted small">(up to 6; the first is the cover)</span></label>
    @if($k->photos)<div class="bp-grid mb-1">@foreach($k->photos as $ph)<label class="bp-thumb"><img src="{{ asset('storage/'.$ph) }}" alt=""><span class="check small"><input type="checkbox" name="photos_keep[]" value="{{ $ph }}" checked> Keep</span></label>@endforeach</div>@endif
    <input class="input" type="file" name="photos[]" accept="image/*" multiple>@error('photos.*')<div class="error-text" style="display:block">{{ $message }}</div>@enderror</div>
  <label class="check mb-2"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', $k->active ?? true))> Visible to customers</label>
  <div class="row gap-sm"><button class="btn btn-primary">{{ icon('check') }}Save package</button><a class="btn" href="{{ route('business.packages') }}">Cancel</a></div>
</form>
@endsection
