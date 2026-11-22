{{-- The parts of an event booking: date, time, venue, done. $b EventBooking, $canMark (business may mark a part done). --}}
<ol class="ev-sched">
  @foreach($b->parts as $pt)
    <li class="{{ $pt->done_at ? 'done' : '' }}">
      <div class="row between wrap" style="gap:6px">
        <div><b>{{ $pt->name }}</b> · {{ $pt->date->format('D, M j, Y') }}{{ $pt->timeLabel() ? ' · '.$pt->timeLabel() : '' }}
          @if($pt->price > 0)<span class="badge b-purple plain">+{{ money($pt->price) }}</span>@endif
          @if($pt->venue)<div class="small muted">{{ icon('map', 'width:12px;vertical-align:-1px') }} {{ $pt->venue }}</div>@endif</div>
        @if($pt->done_at)<span class="badge b-green">{{ icon('check', 'width:12px') }} Done</span>
        @elseif(! empty($canMark) && $pt->date->lte(today()))<form method="POST" action="{{ route('business.bookings.part-done', [$b, $pt->id]) }}">@csrf<button class="btn btn-xs">{{ icon('check') }}Mark done</button></form>@endif
      </div>
    </li>
  @endforeach
</ol>
