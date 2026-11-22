@extends('layouts.dash', ['title' => 'Teams', 'nav' => 'teams'])

@section('content')
@php($active = $teams->where('active', true))
<div class="page-head"><div><h1>Teams</h1><p>A team runs one event at a time. How many events you can take at the same time comes from your active teams: one team can do a morning and an evening event on the same day when the hours (with your setup and teardown time) do not overlap. Confirmed bookings get a free team automatically; you can move them on each booking.</p></div></div>

@if($active->isEmpty())
  <div class="alert alert-info mb-2">{{ icon('info') }}<div>No teams yet, so you take up to <b>{{ $s->bizSettings()['maxEventsPerDay'] }} event(s) a day</b> (Bookings & Payment Settings). Add your teams to plan by the hour instead.</div></div>
@else
  <div class="alert alert-success mb-2">{{ icon('check') }}<div><b>{{ $active->count() }} active team{{ $active->count() > 1 ? 's' : '' }}</b>: up to {{ $active->count() }} event{{ $active->count() > 1 ? 's' : '' }} at the same time. Your verified limits per event type still apply.</div></div>
@endif

<div class="grid grid-2">
@foreach($teams as $t)
  <div class="card {{ $t->active ? '' : 'muted' }}">
    <div class="row between"><h3 class="mb-0">{{ $t->name }}</h3>@unless($t->active)<span class="badge b-gray">Not working</span>@endunless</div>
    <div class="small muted mb-1">{{ $t->memberUsers()->pluck('name')->implode(', ') ?: 'No members yet' }}</div>
    <div class="label mt-1 mb-1">Next events</div>
    @forelse(($work[$t->id] ?? collect())->take(5) as $p)
      <div class="small mb-1"><b>{{ $p->date->format('D, M j') }}</b>{{ $p->timeLabel() ? ' · '.$p->timeLabel() : '' }} · <a href="{{ route('business.bookings.show', $p->booking) }}">{{ $p->booking->code }}</a> {{ $p->booking->event_type }} ({{ $p->name }}) · {{ $p->booking->customer->firstName() }}</div>
    @empty
      <div class="small muted">Nothing scheduled.</div>
    @endforelse
    <details class="mt-2"><summary class="small strong" style="cursor:pointer">Edit team</summary>
      @include('business._team-form', ['t' => $t])
    </details>
  </div>
@endforeach
  <div class="card"><h3>Add a team</h3>@include('business._team-form', ['t' => null])</div>
</div>
@endsection
