@extends('layouts.dash', ['title' => 'My Desk', 'nav' => 'coordinator'])

@section('content')
<div class="page-head"><div><h1>My Desk</h1><p>{{ $s->name }} · inquiries to answer, quotations waiting for the client, and the events you coordinate.</p></div>
  <a class="btn" href="{{ route('business.calendar') }}">{{ icon('calendar') }}Event calendar</a></div>

@php($row = fn ($b, $hint) => '<a class="ny-row" href="'.route('business.bookings.show', $b).'"><span class="grow"><b>'.e($b->code).' · '.e($b->event_type).'</b><span class="small muted" style="display:block">'.e($b->customer->name).' · '.e($b->whenLabel()).' · '.$b->guests.' guests</span></span><span class="small">'.e($hint).'</span></a>')
<div class="grid grid-2">
  <div class="card"><h3>Inquiries to answer <span class="badge b-gold">{{ $toQuote->count() }}</span></h3>
    @forelse($toQuote as $b){!! $row($b, $b->coordinator_id ? 'Yours · send a quotation' : 'Not assigned · send a quotation') !!}
    @empty<p class="muted small">No new inquiries. 🎉</p>@endforelse</div>
  <div class="card"><h3>Quotations waiting <span class="badge b-sky">{{ $waiting->count() }}</span></h3>
    @forelse($waiting as $b){!! $row($b, 'Valid until '.fmt_date($b->quote['validUntil'] ?? $b->event_date->toDateString())) !!}
    @empty<p class="muted small">None of your quotations is waiting for the client.</p>@endforelse</div>
</div>
<div class="card mt-2"><h3>Events you coordinate <span class="badge b-green">{{ $mine->count() }}</span></h3>
  @forelse($mine as $b){!! $row($b, $b->statusLabel()) !!}
  @empty<p class="muted small">No booked events assigned to you yet. The owner assigns coordinators on each booking.</p>@endforelse
</div>
@push('head')<style>.ny-row{display:flex;gap:10px;align-items:center;padding:10px 4px;border-top:1px solid var(--line);color:inherit;text-decoration:none}.ny-row:hover{background:var(--surface-2)}</style>@endpush
@endsection
