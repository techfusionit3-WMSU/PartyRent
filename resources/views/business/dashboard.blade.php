@extends('layouts.dash', ['title' => 'Business Dashboard', 'nav' => 'dashboard'])

{{-- Event company workspace: inquiries → quotations → booked events → scheduled payments. No item rentals here. --}}
@section('content')
@php($canQuote = auth()->user()->orgCan('orders.manage'))
<div class="page-head"><div><h1>Good day, {{ auth()->user()->firstName() }}!</h1><p>{{ $s->name }} · your events and bookings, {{ fmt_date(today_iso()) }}.</p></div>
  <div class="row wrap"><a class="btn btn-primary" href="{{ route('business.bookings') }}">{{ icon('calendar') }}Inquiries & bookings{{ $inquiries->count() ? ' ('.$inquiries->count().')' : '' }}</a></div></div>

@if(! $hasPackages || $needsSettings)
  <div class="card mb-2"><h3 class="mb-0">Get your business ready</h3>
    <p class="small muted">Customers find you by your event packages and book you by asking for a quotation.</p>
    <div class="stack-sm">
      <div class="row between wrap"><span>@if($hasPackages){{ icon('check') }}@else<b>1.</b>@endif Create an event package: what's included, for how many guests, starting price.</span>@unless($hasPackages)<a class="btn btn-primary btn-sm" href="{{ route('business.packages.create') }}">{{ icon('package') }}Create your first event package</a>@endunless</div>
      <div class="row between wrap"><span>@if(! $needsSettings){{ icon('check') }}@else<b>2.</b>@endif Set the areas you serve and your payment schedule (reservation fee, deposit, balance).</span>@if($needsSettings)<a class="btn btn-sm" href="{{ route('business.settings') }}">{{ icon('settings') }}Bookings & payment settings</a>@endif</div>
    </div>
  </div>
@endif

<div class="stats">
  @include('partials.stat', ['label' => 'New inquiries', 'value' => $inquiries->count(), 'sub' => $inquiries->count() ? 'waiting for a quotation' : 'all answered', 'icon' => 'message', 'color' => 'gold', 'href' => route('business.bookings')])
  @include('partials.stat', ['label' => 'Quotations sent', 'value' => $quoted, 'sub' => 'waiting for the customer', 'icon' => 'file', 'color' => 'sky', 'href' => route('business.bookings', ['tab' => 'quoted'])])
  @include('partials.stat', ['label' => 'Upcoming events', 'value' => $upcoming->count(), 'sub' => $upcoming->first() ? 'next on '.$upcoming->first()->event_date->format('M j') : 'none booked', 'icon' => 'calendar', 'color' => 'purple', 'href' => route('business.bookings', ['tab' => 'upcoming'])])
  @include('partials.stat', ['label' => 'Payments to confirm', 'value' => $toConfirm, 'sub' => $overdue ? $overdue.' overdue' : 'proofs sent by customers', 'icon' => 'card', 'color' => $overdue ? 'red' : 'pink', 'href' => route('business.payments')])
  @include('partials.stat', ['label' => 'Received', 'value' => money_short($revenue), 'sub' => 'this month, confirmed', 'icon' => 'chart', 'color' => 'teal', 'href' => route('business.payments')])
</div>

<div class="grid grid-2 mt-3">
  <div class="card"><div class="card-head"><h3>Upcoming events</h3><a class="small strong" href="{{ route('business.bookings', ['tab' => 'upcoming']) }}">All bookings</a></div>
    @forelse($upcoming->take(6) as $b)
      <a class="row between" href="{{ route('business.bookings.show', $b) }}" style="padding:10px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none">
        <div><b>{{ $b->event_date->format('M j') }}</b> · {{ $b->event_type }} · {{ $b->guests }} guests<div class="small muted">{{ $b->code }} · {{ $b->customer->name }} · {{ \Illuminate\Support\Str::limit($b->venue, 40) }}</div></div>
        <span class="badge {{ $b->statusBadge() }}">{{ $b->statusLabel() }}</span></a>
    @empty
      <p class="muted">No booked events yet. Answer inquiries with a quotation to get your first booking.</p>
    @endforelse
  </div>
  <div class="card"><div class="card-head"><h3>New inquiries</h3><a class="small strong" href="{{ route('business.bookings') }}">Open</a></div>
    @forelse($inquiries->take(6) as $b)
      <a class="row between" href="{{ route('business.bookings.show', $b) }}" style="padding:10px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none">
        <div><b>{{ $b->event_type }}</b> on {{ $b->event_date->format('M j, Y') }}<div class="small muted">{{ $b->customer->name }} · {{ $b->guests }} guests{{ $b->package ? ' · '.$b->package->name : ' · custom event' }}</div></div>
        @if($canQuote)<span class="btn btn-xs btn-primary">Quote</span>@endif</a>
    @empty
      <p class="muted">No new inquiries.</p>
    @endforelse
  </div>
</div>
@endsection
