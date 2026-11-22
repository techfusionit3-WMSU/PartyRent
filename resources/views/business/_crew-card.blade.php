{{-- One event part for the crew (phone-first): when, where, who to call, mark done. --}}
<div class="card crew-card">
  <div class="row between wrap top" style="gap:6px">
    <div><div class="small muted">{{ $p->date->format('D, M j') }}{{ $p->timeLabel() ? ' · '.$p->timeLabel() : ' · all day' }}</div>
      <h3 class="mb-0">{{ $b->event_type }} · {{ $p->name }}</h3>
      <div class="small">{{ $b->code }} · {{ $b->guests }} guests{{ $b->coordinator ? ' · coordinator '.$b->coordinator->name : '' }}</div></div>
    @if($p->done_at)<span class="badge b-green">{{ icon('check', 'width:12px') }} Done</span>@endif
  </div>
  <div class="mt-1">{{ icon('map', 'width:14px;vertical-align:-2px') }} {{ $venue }}</div>
  <div class="small muted">Client: {{ $b->customer->name }}</div>
  <div class="row wrap mt-2" style="gap:8px">
    @if($phone)<a class="btn btn-sm" href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ icon('send') }}Call client</a>@endif
    <a class="btn btn-sm" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ urlencode($venue) }}">{{ icon('map') }}Map</a>
    @if(! $p->done_at && $p->date->lte(today()) && $b->status === 'confirmed' && auth()->user()->orgCan('fulfillment.update'))
      <form method="POST" action="{{ route('business.bookings.part-done', [$b, $p->id]) }}">@csrf<button class="btn btn-sm btn-success">{{ icon('check') }}Mark {{ $p->name }} done</button></form>
    @endif
  </div>
</div>
