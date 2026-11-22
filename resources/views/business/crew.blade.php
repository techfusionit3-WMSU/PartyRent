@extends('layouts.dash', ['title' => 'My Events', 'nav' => 'crew'])

@section('content')
<div class="page-head"><div><h1>My Events</h1><p>The event parts your team works: when, where and who to call. Mark a part done when it is finished.</p></div></div>

@php($card = function ($p) {
    $b = $p->booking;
    $venue = $p->venue ?: trim($b->venue.($b->city ? ', '.$b->city : ''));
    $phone = $b->customer->phone;
    return view('business._crew-card', compact('p', 'b', 'venue', 'phone'))->render();
})
@if($late->isNotEmpty())
  <h3 class="mt-1" style="color:var(--red)">Not marked done yet</h3>
  <div class="stack mb-2">@foreach($late as $p){!! $card($p) !!}@endforeach</div>
@endif
<h3 class="mt-1">Today</h3>
<div class="stack mb-2">@forelse($today as $p){!! $card($p) !!}@empty<div class="card">@include('partials.empty', ['emoji' => '☕', 'title' => 'Nothing today', 'text' => 'Your team has no event parts today.'])</div>@endforelse</div>
<h3>Next</h3>
<div class="stack">@forelse($next as $p){!! $card($p) !!}@empty<div class="card"><p class="muted small mb-0">Nothing scheduled for your team yet. Events get a team when they are confirmed.</p></div>@endforelse</div>
@endsection
