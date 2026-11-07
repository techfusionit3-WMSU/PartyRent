@if(in_array($r->status, ['declined', 'cancelled'], true))
  <div class="alert {{ $r->status === 'declined' ? 'alert-danger' : 'alert-warn' }}">{{ icon('x') }}<div><b>This request was {{ $r->status }}.</b> {{ $r->decline_reason ?: $r->cancel_reason }}</div></div>
@else
  @php
      $steps = $r->fulfillment === 'pickup'
          ? [['pending', 'Requested'], ['accepted', 'Accepted'], ['in_progress', 'Preparing'], ['ready_pickup', 'Ready for pickup'], ['ongoing', 'Ongoing'], ['return_pending', 'Return'], ['inspection', 'Inspection'], ['completed', 'Completed']]
          : [['pending', 'Requested'], ['accepted', 'Accepted'], ['in_progress', 'Preparing'], ['out_delivery', 'Out for delivery'], ['ongoing', 'Ongoing'], ['return_pending', 'Return'], ['inspection', 'Inspection'], ['completed', 'Completed']];
      $idx = collect($steps)->search(fn ($s) => $s[0] === $r->status);
      $done = $r->status === 'completed';
  @endphp
  <div class="stepper">
    @foreach($steps as $i => $s)
      <div class="step {{ $done || $i < $idx ? 'done' : ($i === $idx ? 'current' : '') }}"><div class="sd">{{ $done || $i < $idx ? '✓' : $i + 1 }}</div>{{ $s[1] }}</div>
    @endforeach
  </div>
@endif
