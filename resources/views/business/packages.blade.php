@extends('layouts.dash', ['title' => 'Event Packages', 'nav' => 'packages'])

@section('content')
<div class="page-head"><div><h1>Event Packages</h1><p>What you offer, priced per event. Customers pick a package and ask for a quotation; you can still adjust the final price for their venue and needs.</p></div>
  <a class="btn btn-primary" href="{{ route('business.packages.create') }}">{{ icon('plus') }}New event package</a></div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '🎉', 'title' => 'No event packages yet', 'text' => 'Create a package like "Debut Package · 150 guests" with everything it includes and a starting price.', 'action' => '<a class="btn btn-primary" href="'.route('business.packages.create').'">Create your first event package</a>'])</div>
@else
  <div class="grid grid-3">
    @foreach($list as $k)
      <div class="card pad-0" style="overflow:hidden;{{ $k->active ? '' : 'opacity:.6' }}">
        @if($k->cover())<img src="{{ $k->cover() }}" alt="" style="width:100%;aspect-ratio:16/9;object-fit:cover">@else<div style="aspect-ratio:16/9;display:grid;place-items:center;background:var(--brand-50);font-size:2.5rem">🎉</div>@endif
        <div style="padding:14px 16px">
          <div class="row between top"><b>{{ $k->name }}</b>@unless($k->active)<span class="badge b-gray">Hidden</span>@endunless</div>
          <div class="small muted">{{ $k->guestsLabel() }}{{ $k->events ? ' · '.implode(', ', $k->events) : '' }}</div>
          <div class="mt-1"><span class="small muted">from</span> <b style="font-size:1.15rem">{{ money($k->price) }}</b></div>
          <div class="small muted mt-1">{{ count($k->inclusions ?? []) }} inclusions</div>
          <div class="row gap-sm mt-2"><a class="btn btn-sm" href="{{ route('business.packages.edit', $k) }}">{{ icon('edit') }}Edit</a>
            <form method="POST" action="{{ route('business.packages.toggle', $k) }}">@csrf<button class="btn btn-sm btn-ghost">{{ $k->active ? 'Hide' : 'Show' }}</button></form></div>
        </div>
      </div>
    @endforeach
  </div>
@endif
@endsection
