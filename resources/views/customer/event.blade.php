@extends('layouts.market', ['title' => $b->code, 'nav' => 'rentals'])

@section('content')
@php
    $q = $b->quote;
    $steps = ['inquiry' => 'Inquiry sent', 'quoted' => 'Quotation', 'booked' => 'Reserve the date', 'confirmed' => 'Booked', 'completed' => 'Done'];
    $order = array_keys($steps);
    $at = array_search($b->status, $order, true);
    $payTo = $s->payout_method === 'gcash' ? 'GCash' : ($s->payout_bank ?: 'Bank');
    $online = \App\Domain\EventOnlinePay::mode();
    $myReview = \App\Models\Review::where('event_booking_id', $b->id)->first();
@endphp
<div class="crumbs"><a href="{{ route('events.index') }}">My Events</a> / {{ $b->code }}</div>
<div class="page-head"><div><h1>{{ $b->event_type }} · {{ $b->event_date->format('D, M j, Y') }}</h1><p>with <a href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a> · {{ $b->code }} · <span class="badge {{ $b->statusBadge() }}">{{ $b->statusLabel() }}</span></p></div></div>

@if($at !== false)
  <div class="wizard-steps mb-2">@foreach($steps as $k => $l)@php($i = array_search($k, $order, true))<div class="ws {{ $i === $at ? 'on' : ($i < $at ? 'done' : '') }}"><span>Step {{ $i + 1 }}</span>{{ $l }}</div>@endforeach</div>
@else
  <div class="alert alert-info mb-2">{{ icon('info') }}<div>This booking is <b>{{ mb_strtolower($b->statusLabel()) }}</b>{{ $b->close_reason ? ': '.$b->close_reason : '' }}.@if($b->paid() > 0) {{ money($b->paid()) }} was paid; refunds follow {{ $s->name }}'s cancellation policy.@endif</div></div>
@endif

@if($b->status === 'inquiry')
  <div class="alert alert-info mb-2">{{ icon('clock') }}<div>{{ $s->name }} is preparing your quotation. You'll get a notification when it's ready.</div></div>
@endif

<div class="grid grid-2">
  <div class="card">
    @if($q)
      <h3>Quotation</h3>
      <table class="table"><tbody>@foreach($q['items'] as $l)<tr><td>{{ $l['label'] }}</td><td class="right">{{ money($l['amount']) }}</td></tr>@endforeach
        <tr><td><b>Total</b></td><td class="right"><b>{{ money($q['total']) }}</b></td></tr></tbody></table>
      @if($q['note'])<p class="small" style="white-space:pre-line">{{ $q['note'] }}</p>@endif
      @if($b->status === 'quoted')
        <h4 class="mt-2 mb-1">How you'll pay</h4>
        <ul class="small" style="padding-left:18px">@foreach($q['schedule'] as $st)<li><b>{{ $st['label'] }}</b> {{ money($st['amount']) }} · {{ $st['due'] === 'on acceptance' ? 'within '.$s->bizSettings()['reserveHours'].' hours of accepting' : 'by '.fmt_date($st['due']) }}</li>@endforeach</ul>
        <p class="small muted">Valid until {{ fmt_date($q['validUntil']) }}. Your date is held once the reservation fee is confirmed.</p>
        <form method="POST" action="{{ route('events.accept', $b) }}" data-confirm-title="Accept this quotation?" data-confirm="You agree to {{ money($q['total']) }} and the payment schedule. Next, pay the reservation fee to hold {{ $b->event_date->format('M j') }}." data-confirm-ok="Accept">@csrf<button class="btn btn-primary btn-block btn-lg">{{ icon('check') }}Accept quotation</button></form>
      @endif
    @else
      <h3>Your request</h3>
    @endif
    <dl class="kv mt-2">
      <dt>Package</dt><dd>{{ $b->package?->name ?? 'Custom event' }}</dd>
      <dt>Guests</dt><dd>{{ $b->guests }}</dd>
      <dt>When</dt><dd>{{ $b->event_date->format('l, M j, Y') }}{{ $b->timeLabel() ? ' · '.$b->timeLabel() : '' }}</dd>
      <dt>Venue</dt><dd>{{ $b->venue }}{{ $b->city ? ', '.$b->city : '' }}</dd>
      @if($b->notes)<dt>Notes</dt><dd style="white-space:pre-line">{{ $b->notes }}</dd>@endif
    </dl>
    @if($b->isOpen())<button class="btn btn-ghost btn-sm mt-1" data-modal="cancelM">Cancel {{ in_array($b->status, ['inquiry', 'quoted'], true) ? 'request' : 'booking' }}</button>@endif
  </div>

  <div class="card">
    <h3>Payments</h3>
    @if($b->payments->isEmpty())
      <p class="muted mb-0">Nothing to pay yet. You pay nothing until you accept a quotation.</p>
    @else
      <p class="small muted">@if($online)<b>Pay online</b> (confirmed right away{{ $online === 'practice' ? ', practice mode: no real money' : '' }}), or @endif pay to <b>{{ $s->name }}</b>'s account and send a screenshot: <b>{{ $payTo }}</b> · {{ $s->payout_account_name }} · <b>{{ $s->payout_account_no }}</b>.</p>
      <div class="stack-sm">
      @foreach($b->payments as $p)
        <div class="card card-flat" style="padding:12px 14px">
          <div class="row between"><div><b>{{ $p->label }}</b> · {{ money($p->amount) }}<div class="small muted">due {{ $p->due_date->format('M j, Y') }}</div></div><span class="badge {{ $p->statusBadge() }}">{{ $p->statusLabel() }}</span></div>
          @if($p->status === 'paid')<div class="small muted mt-1">Receipt {{ $p->receipt_no }} · {{ fmt_datetime($p->paid_at) }}</div>@endif
          @if($p->status === 'pending')<div class="small muted mt-1">Sent {{ fmt_datetime($p->submitted_at) }} · ref {{ $p->reference }}. Waiting for {{ $s->name }} to confirm.</div>@endif
          @if($p->status === 'due' && in_array($b->status, ['booked', 'confirmed'], true))
            @if($p->reject_reason)<div class="small mt-1" style="color:var(--red)">Not confirmed: {{ $p->reject_reason }}</div>@endif
            @if($online)<form method="POST" action="{{ route('events.online', [$b, $p]) }}" class="mt-1">@csrf<button class="btn btn-primary btn-sm">{{ icon('card') }}Pay {{ money($p->amount) }} online</button></form><div class="small muted mt-1">or by transfer:</div>@endif
            <form method="POST" action="{{ route('events.pay', [$b, $p]) }}" enctype="multipart/form-data" class="mt-1">@csrf
              <div class="form-grid">
                <div class="field"><label>Paid with</label><select class="input" name="method"><option value="bank">Bank transfer</option><option value="gcash" @selected($s->payout_method === 'gcash')>GCash</option></select></div>
                <div class="field"><label>Reference no.</label><input class="input" name="reference" required minlength="4" maxlength="80"></div>
              </div>
              <div class="field"><label>Screenshot or receipt</label><input class="input" type="file" name="proof" accept="image/*,.pdf" required></div>
              <button class="btn {{ $online ? '' : 'btn-primary' }} btn-sm">{{ icon('upload') }}Send transfer proof</button>
            </form>
          @endif
        </div>
      @endforeach
      </div>
    @endif
    @php($pol = (array) ($s->policy ?? []))
    @if(! empty($pol['cancellation']))<p class="small muted mt-2 mb-0"><b>Cancellation:</b> {{ $pol['cancellation'] }}@if(! empty($pol['rescheduling']))<br><b>Rescheduling:</b> {{ $pol['rescheduling'] }}@endif</p>@endif
  </div>
</div>

@if($b->status === 'completed')
<div class="card mt-3" id="review">
  @if($myReview)
    <h3>Your review</h3>
    <div><span class="stars">{{ str_repeat('★', $myReview->rating) }}{{ str_repeat('☆', 5 - $myReview->rating) }}</span></div>
    <p class="mb-0" style="white-space:pre-line">{{ $myReview->comment }}</p>
    @if($myReview->reply)<p class="small muted mt-1 mb-0"><b>{{ $s->name }} replied:</b> {{ $myReview->reply }}</p>@endif
  @else
    <h3>How was your event?</h3>
    <p class="small muted">Your review helps other customers choose {{ $s->name }}.</p>
    <form method="POST" action="{{ route('events.review', $b) }}" enctype="multipart/form-data">@csrf
      <div class="field @error('rating') invalid @enderror"><label>Rating</label><div class="row gap-sm">@for($i = 5; $i >= 1; $i--)<label class="chip"><input type="radio" name="rating" value="{{ $i }}" @checked((int) old('rating') === $i) style="accent-color:var(--brand)"> {{ str_repeat('★', $i) }}</label>@endfor</div>@error('rating')<div class="error-text">{{ $message }}</div>@enderror</div>
      <div class="field @error('comment') invalid @enderror"><label>Your review</label><textarea class="input" name="comment" required minlength="{{ \App\Domain\Reviews::MIN_COMMENT }}" maxlength="2000" placeholder="How did the setup, team and day go?">{{ old('comment') }}</textarea>@error('comment')<div class="error-text">{{ $message }}</div>@enderror</div>
      <div class="field"><label>Photos <span class="muted small">(optional, up to {{ \App\Domain\Reviews::MAX_PHOTOS }})</span></label><input class="input" type="file" name="photos[]" accept="image/*" multiple></div>
      <button class="btn btn-primary">{{ icon('star') }}Post review</button>
    </form>
  @endif
</div>
@endif

<div class="modal-backdrop hidden" id="cancelM"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Cancel?</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('events.cancel', $b) }}">@csrf<div class="modal-body">@if($b->paid() > 0)<p class="small">You paid {{ money($b->paid()) }}. Refunds follow {{ $s->name }}'s cancellation policy.</p>@endif<div class="field"><label>Reason <span class="muted small">(optional)</span></label><input class="input" name="reason" maxlength="300"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Cancel</button></div></form></div></div>
@endsection
