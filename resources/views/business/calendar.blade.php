@extends('layouts.dash', ['title' => 'Event Calendar', 'nav' => 'calendar'])

@section('content')
@php
    $prev = $month->copy()->subMonth()->format('Y-m');
    $next = $month->copy()->addMonth()->format('Y-m');
    $held = ['booked', 'confirmed'];
@endphp
<div class="page-head"><div><h1>Event Calendar</h1><p>Your booked events and open inquiries. A day is <b>full</b> once {{ $max }} event{{ $max > 1 ? 's are' : ' is' }} booked (change it in Bookings & Payment Settings).</p></div>
  <div class="row gap-sm"><a class="btn btn-sm" href="{{ route('business.calendar', ['m' => $prev]) }}" aria-label="Previous month">{{ icon('left') }}</a><b style="min-width:140px;text-align:center">{{ $month->format('F Y') }}</b><a class="btn btn-sm" href="{{ route('business.calendar', ['m' => $next]) }}" aria-label="Next month">{{ icon('right') }}</a>@if(! $month->isSameMonth(today()))<a class="btn btn-sm btn-ghost" href="{{ route('business.calendar') }}">Today</a>@endif</div></div>
<div class="row wrap gap-sm mb-2 small">@foreach(['confirmed', 'booked', 'quoted', 'inquiry', 'completed'] as $st)<span class="badge {{ \App\Models\EventBooking::STATUS[$st][1] }}">{{ \App\Models\EventBooking::STATUS[$st][0] }}</span>@endforeach</div>
<div class="card pad-0" style="overflow:hidden">
  <div class="biz-cal">
    @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d)<div class="biz-cal-h">{{ $d }}</div>@endforeach
    @for($d = $from->copy(); $d->lte($to); $d->addDay())
      @php($k = $d->toDateString())
      @php($day = $byDay[$k] ?? collect())
      @php($full = $day->whereIn('status', $held)->count() >= $max)
      <div class="biz-cal-d {{ $d->isSameMonth($month) ? '' : 'out' }} {{ $d->isToday() ? 'today' : '' }} {{ $full ? 'full' : '' }}">
        <div class="row between"><span class="small strong">{{ $d->day }}</span>@if($full)<span class="badge b-red plain" style="font-size:.66rem">Full</span>@endif</div>
        @foreach($day as $b)
          <a class="biz-cal-e badge {{ $b->statusBadge() }}" href="{{ route('business.bookings.show', $b) }}" title="{{ $b->code }} · {{ $b->statusLabel() }}">{{ $b->calTime ? \App\Domain\Slots::fmt12($b->calTime).' ' : '' }}{{ $b->event_type }}{{ $b->calPart ? ' ('.$b->calPart.')' : '' }} · {{ $b->customer->firstName() }}{{ $b->calTeam ? ' · '.$b->calTeam : '' }}</a>
        @endforeach
      </div>
    @endfor
  </div>
</div>
@push('head')<style>
.biz-cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
.biz-cal-h { padding: 8px; font-size: .78rem; font-weight: 700; color: var(--ink-2); border-bottom: 1px solid var(--line); text-align: center; }
.biz-cal-d { min-height: 104px; padding: 6px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.biz-cal-d:nth-child(7n) { border-right: 0; }
.biz-cal-d.out { background: var(--bg, #faf7fd); opacity: .55; }
.biz-cal-d.today { box-shadow: inset 0 0 0 2px var(--brand); }
.biz-cal-d.full { background: rgba(239, 68, 68, .05); }
.biz-cal-e { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .72rem; text-decoration: none; }
@media (max-width: 640px) { .biz-cal-d { min-height: 64px; padding: 3px; } .biz-cal-e { font-size: .6rem; padding: 2px 4px; } }
</style>@endpush
@endsection
