@extends('layouts.dash', ['title' => 'Event Types', 'nav' => 'event-types'])

@section('content')
@php
  $openId = (int) (session('apply') ?: $open);
  $verified = $mine->where('status', 'verified');
@endphp
<div class="page-head"><div><h1>Event Types</h1><p>Tell PartyRent which kinds of events your business can handle, how big and how many a day, with photos of past ones. PartyRent checks each type and sets your limits. You can only offer packages and take inquiries for <b>verified</b> types, within those limits.</p></div></div>

@if($verified->isEmpty())
  <div class="alert alert-info mb-2">{{ icon('info') }}<div><b>Start here.</b> Apply for at least one event type below. Until one is verified, your packages are not shown to customers.</div></div>
@endif

@if($mine->isNotEmpty())
<div class="card mb-3">
  <h3>Your event types</h3>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Event type</th><th>Status</th><th>Your limits</th><th>Done before</th><th></th></tr></thead>
    <tbody>
    @foreach($mine->sortBy(fn ($r) => [$r->status !== 'verified', $r->type->name]) as $r)
      <tr>
        <td data-label="Event type"><b>{{ $r->type->name }}</b></td>
        <td data-label="Status"><span class="badge {{ $r->statusClass() }}">{{ $r->statusLabel() }}</span>@if($r->raise_pending)<div class="small muted mt-1">Higher limit asked: {{ number_format($r->claimed_max_guests) }} guests · {{ $r->claimed_per_day }} a day (waiting)</div>@endif
          @if($r->reason && in_array($r->status, ['rejected', 'paused'], true))<div class="small" style="color:var(--red)">{{ $r->reason }}</div>@endif</td>
        <td data-label="Your limits" class="small">{{ $r->limitsLabel() }}</td>
        <td data-label="Done before" class="small">{{ $r->past_events }}</td>
        <td data-label="">@if($r->status !== 'paused')<a class="btn btn-xs" href="{{ route('business.event-types', ['apply' => $r->event_type_id]) }}#type-{{ $r->event_type_id }}">{{ $r->status === 'verified' ? 'Ask for more' : 'Update proof' }}</a>@endif</td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
</div>
@endif

<h2 class="mb-1">Kinds of events</h2>
<p class="muted small">Open one to apply. Be honest about your size: PartyRent may verify a lower limit than you ask, and you can ask for more after completed events.</p>
<div class="stack">
@foreach($types as $t)
  @php($r = $mine->get($t->id))
  @php($proofs = \App\Domain\EventTypes::proofsFor($t))
  <details class="card et-card" id="type-{{ $t->id }}" @if($openId === $t->id) open @endif>
    <summary>
      <span class="grow"><b>{{ $t->name }}</b> <span class="small muted">· {{ $t->description }}</span></span>
      @if($r)<span class="badge {{ $r->statusClass() }}">{{ $r->statusLabel() }}</span>@else<span class="btn btn-xs">Apply</span>@endif
    </summary>
    <div class="et-body">
      <dl class="kv small">
        @if($t->guestsLabel())<dt>Usually</dt><dd>{{ $t->guestsLabel() }}</dd>@endif
        @if($t->services)<dt>Usual services</dt><dd>{{ implode(', ', $t->services) }}</dd>@endif
        @if($t->parts)<dt>Usual parts</dt><dd>{{ collect($t->parts)->map(fn ($p) => $p['name'].(! empty($p['optional']) ? ' (optional)' : ''))->implode(' → ') }}</dd>@endif
        <dt>Proof needed</dt><dd>Photos of {{ $t->name }} events you handled{{ $proofs ? ', '.implode(', ', $proofs) : '' }}</dd>
      </dl>
      @if($r && $r->status === 'paused')
        <div class="alert alert-warn">{{ icon('alert') }}<div>PartyRent paused new {{ $t->name }} bookings{{ $r->reason ? ': '.$r->reason : '' }}. Contact PartyRent support to resume.</div></div>
      @else
      <form method="POST" action="{{ route('business.event-types.apply', $t) }}" enctype="multipart/form-data" class="mt-1">@csrf
        @if(session('error') && $openId === $t->id)<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ session('error') }}</div></div>@endif
        <div class="form-grid">
          <div class="field"><label for="g-{{ $t->id }}">Biggest guest count you can handle</label><input class="input" type="number" min="1" id="g-{{ $t->id }}" name="max_guests" required value="{{ $openId === $t->id ? old('max_guests', $r?->claimed_max_guests) : $r?->claimed_max_guests }}" placeholder="e.g. 200"></div>
          <div class="field"><label for="d-{{ $t->id }}">How many on the same day</label><input class="input" type="number" min="1" max="20" id="d-{{ $t->id }}" name="per_day" required value="{{ $openId === $t->id ? old('per_day', $r?->claimed_per_day ?? 1) : ($r?->claimed_per_day ?? 1) }}"><div class="hint">Count your teams: one team runs one event at a time.</div></div>
          <div class="field"><label for="p-{{ $t->id }}">How many have you done</label><input class="input" type="number" min="0" id="p-{{ $t->id }}" name="past_events" required value="{{ $openId === $t->id ? old('past_events', $r?->past_events ?? 0) : ($r?->past_events ?? 0) }}"></div>
        </div>
        <div class="field"><label>Photos of past {{ $t->name }} events <span class="muted small">(1 to 8; only PartyRent sees them)</span></label>
          @if($r && $r->photos)<div class="row wrap gap-sm mb-1">@foreach(array_values($r->photos) as $i => $ph)<label class="et-thumb"><img src="{{ route('business.event-types.file', [$r, 'photo', $i]) }}" alt="Past event photo {{ $i + 1 }}"><span class="check small"><input type="checkbox" name="photos_keep[]" value="{{ $ph }}" checked> Keep</span></label>@endforeach</div>@endif
          <input class="input" type="file" name="photos[]" accept="image/*" multiple></div>
        @foreach($proofs as $i => $label)
          <div class="field"><label>{{ $label }} @if($label === \App\Domain\EventTypes::FOOD_PROOF)<span class="muted small">(optional if you do not serve food)</span>@endif</label>
            @if($r && ! empty($r->docs[$label]))<div class="small mb-1">{{ icon('check', 'width:14px;color:var(--green)') }} On file: <a target="_blank" href="{{ route('business.event-types.file', [$r, 'doc', array_search($label, array_keys($r->docs))]) }}">view</a>. Upload again to replace it.</div>@endif
            <input class="input" type="file" name="docs[{{ $i }}]" accept=".pdf,image/*"></div>
        @endforeach
        <div class="field"><label for="n-{{ $t->id }}">Anything PartyRent should know <span class="muted small">(optional)</span></label><textarea class="input" id="n-{{ $t->id }}" name="note" rows="2" maxlength="1000" placeholder="e.g. We have two complete styling teams and our own sound system.">{{ $openId === $t->id ? old('note', $r?->note) : $r?->note }}</textarea></div>
        <button class="btn btn-primary">{{ icon('send') }}{{ ! $r ? 'Apply for '.$t->name : ($r->status === 'verified' ? 'Save / ask for more' : 'Send again') }}</button>
      </form>
      @endif
    </div>
  </details>
@endforeach
</div>

<div class="card mt-3">
  <h3>Your kind of event is not listed?</h3>
  <p class="small muted">Ask PartyRent to add it. Once added, apply for it like the others.</p>
  @foreach($requested as $q)<div class="small mb-1"><span class="badge b-gold">Asked</span> {{ $q->name }}</div>@endforeach
  <form method="POST" action="{{ route('business.event-types.request') }}" class="row wrap" style="gap:8px;align-items:flex-end">@csrf
    <div class="field mb-0 grow"><label for="rq-name">Kind of event</label><input class="input" id="rq-name" name="name" required minlength="3" maxlength="60" placeholder="e.g. Pet birthday"></div>
    <div class="field mb-0 grow"><label for="rq-note">Short note <span class="muted small">(optional)</span></label><input class="input" id="rq-note" name="note" maxlength="500"></div>
    <button class="btn">{{ icon('plus') }}Ask to add it</button>
  </form>
</div>
@endsection
