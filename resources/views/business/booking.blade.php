@extends('layouts.dash', ['title' => $b->code, 'nav' => 'bookings'])

@section('content')
@php
    $u = auth()->user();
    $manage = $u->orgCan('orders.manage');
    $verify = $u->orgCan('payments.verify');
    $q = $b->quote;
    $lines = old('items', $q['items'] ?? ($b->package ? [['label' => $b->package->name.' ('.$b->package->guestsLabel().')', 'amount' => $b->package->price]] : [['label' => '', 'amount' => '']]));
    $sched = $q['schedule'] ?? [];
    $pctOf = fn ($k, $def) => old($k, collect($sched)->firstWhere('kind', $k === 'reservePct' ? 'reservation' : 'deposit')['pct'] ?? $def);
@endphp
<div class="crumbs"><a href="{{ route('business.bookings') }}">Inquiries & Bookings</a> / {{ $b->code }}</div>
<div class="page-head"><div><h1>{{ $b->event_type }} · {{ $b->event_date->format('D, M j, Y') }}</h1><p>{{ $b->code }} · <span class="badge {{ $b->statusBadge() }}">{{ $b->statusLabel() }}</span>@if($b->close_reason) · {{ $b->close_reason }}@endif</p></div>
  <div class="row wrap">
    @if($manage && $b->status === 'confirmed' && $b->event_date->lte(today()))<form method="POST" action="{{ route('business.bookings.complete', $b) }}">@csrf<button class="btn btn-success">{{ icon('check') }}Mark event done</button></form>@endif
    @if($manage && in_array($b->status, ['inquiry', 'quoted'], true))<button class="btn btn-ghost" data-modal="declineM">Decline</button>@endif
    @if($manage && in_array($b->status, ['booked', 'confirmed'], true))<button class="btn btn-ghost" data-modal="cancelM">Cancel booking</button>@endif
  </div></div>
@include('partials.next-step', ['ns' => \App\Domain\NextStep::event($b, 'shop')])

@if($heldThatDay >= $set['maxEventsPerDay'] && in_array($b->status, ['inquiry', 'quoted', 'booked'], true))
  <div class="alert alert-warn mb-2">{{ icon('alert') }}<div>You already have {{ $heldThatDay }} event(s) booked on this date (your limit is {{ $set['maxEventsPerDay'] }} a day). The customer can't book it unless you raise the limit in Bookings & Payment Settings.</div></div>
@endif

<div class="grid grid-2">
  <div class="card"><h3>Event details</h3>
    <dl class="kv">
      <dt>Customer</dt><dd>{{ $b->customer->name }}<br><span class="small muted">{{ $b->customer->email }} · {{ $b->customer->phone }}</span></dd>
      <dt>Package</dt><dd>{{ $b->package ? $b->package->name.' · '.money($b->package->price) : 'Custom event (no package)' }}</dd>
      <dt>Date & time</dt><dd>{{ $b->event_date->format('l, M j, Y') }}{{ $b->timeLabel() ? ' · '.$b->timeLabel() : '' }}</dd>
      <dt>Guests</dt><dd>{{ $b->guests }}</dd>
      <dt>Venue</dt><dd>{{ $b->venue }}{{ $b->city ? ', '.$b->city : '' }}</dd>
      @if($b->budget)<dt>Budget</dt><dd>{{ money($b->budget) }}</dd>@endif
      <dt>Notes</dt><dd style="white-space:pre-line">{{ $b->notes ?: '—' }}</dd>
    </dl>
    @if($others->isNotEmpty())<p class="small muted mb-0">Same day: {{ $others->map(fn ($o) => $o->code.' ('.$o->statusLabel().')')->implode(', ') }}</p>@endif
  </div>

  <div class="card">
    @if(in_array($b->status, ['inquiry', 'quoted'], true) && $manage)
      <h3>{{ $b->status === 'quoted' ? 'Update the quotation' : 'Send a quotation' }}</h3>
      @if($b->status === 'quoted')<p class="small muted">Sent {{ fmt_datetime($b->quoted_at) }} · valid until {{ fmt_date($q['validUntil']) }}. Sending again replaces it.</p>@endif
      <form method="POST" action="{{ route('business.bookings.quote', $b) }}" id="quoteF">@csrf
        <div class="label mb-1">What's included and the price</div>
        <div id="qLines" class="stack-sm">
          @foreach($lines as $i => $l)
            <div class="row" style="gap:8px" data-qline><input class="input" name="items[{{ $i }}][label]" value="{{ $l['label'] }}" placeholder="e.g. Styling & décor, Lights & sound, Travel fee" style="flex:2"><input class="input" type="number" min="0" step="0.01" name="items[{{ $i }}][amount]" value="{{ $l['amount'] }}" placeholder="₱" style="flex:1" data-amt><button type="button" class="icon-btn" data-qrm aria-label="Remove line">{{ icon('x') }}</button></div>
          @endforeach
        </div>
        <button type="button" class="btn btn-sm mt-1" id="qAdd">{{ icon('plus') }}Add line</button>
        <div class="row between mt-2"><b>Total</b><b id="qTotal">—</b></div>
        <div class="form-grid mt-2">
          <div class="field"><label>Reservation fee %</label><input class="input" type="number" min="0" max="100" step="0.5" name="reservePct" value="{{ $pctOf('reservePct', $set['reservePct']) }}" data-pct></div>
          <div class="field"><label>Deposit %</label><input class="input" type="number" min="0" max="100" step="0.5" name="depositPct" value="{{ $pctOf('depositPct', $set['depositPct']) }}" data-pct></div>
        </div>
        <div class="small muted mb-1" id="qSched"></div>
        <div class="form-grid">
          <div class="field"><label>Valid for (days)</label><input class="input" type="number" min="1" max="30" name="valid_days" value="{{ old('valid_days', $set['quoteDays']) }}"></div>
        </div>
        <div class="field"><label>Message to the customer <span class="muted small">(optional)</span></label><textarea class="input" name="note" maxlength="1000" placeholder="e.g. Includes setup from 2 PM; travel to the venue is included.">{{ old('note', $q['note'] ?? '') }}</textarea></div>
        <button class="btn btn-primary btn-block">{{ icon('send') }}{{ $b->status === 'quoted' ? 'Send updated quotation' : 'Send quotation' }}</button>
      </form>
    @elseif($q)
      <h3>Quotation</h3>
      <table class="table"><tbody>@foreach($q['items'] as $l)<tr><td>{{ $l['label'] }}</td><td class="right">{{ money($l['amount']) }}</td></tr>@endforeach
        <tr><td><b>Total</b></td><td class="right"><b>{{ money($q['total']) }}</b></td></tr></tbody></table>
      @if($q['note'])<p class="small" style="white-space:pre-line">{{ $q['note'] }}</p>@endif
    @else
      <h3>Quotation</h3><p class="muted mb-0">No quotation was sent.</p>
    @endif
  </div>
</div>

@if($b->payments->isNotEmpty())
<div class="card mt-3"><div class="card-head"><h3>Payment schedule</h3><span class="small muted">{{ money($b->paid()) }} of {{ money($b->total()) }} received</span></div>
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Payment</th><th>Amount</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>
  @foreach($b->payments as $p)
    <tr>
      <td data-label="Payment"><b>{{ $p->label }}</b><div class="small muted">{{ $p->code }}</div></td>
      <td data-label="Amount">{{ money($p->amount) }}</td>
      <td data-label="Due">{{ $p->due_date->format('M j, Y') }}</td>
      <td data-label="Status"><span class="badge {{ $p->statusBadge() }}">{{ $p->statusLabel() }}</span>@if($p->status === 'paid')<div class="small muted">{{ $p->receipt_no }} · {{ fmt_datetime($p->paid_at) }}</div>@endif
        @if($p->status !== 'due' && $p->reference)<div class="small muted">{{ \App\Models\EventPayment::METHODS[$p->method] ?? $p->method }} · ref {{ $p->reference }}@if($p->proof_path) · <a href="{{ route('business.payments.proof', $p) }}" target="_blank" rel="noopener">proof</a>@endif</div>@endif
        @if($p->status === 'due' && $p->reject_reason)<div class="small" style="color:var(--red)">Last proof rejected: {{ $p->reject_reason }}</div>@endif</td>
      <td data-label="">@if($p->status === 'pending' && $verify)@include('business._confirm', ['p' => $p])@elseif($p->status === 'due' && in_array($b->status, ['booked', 'confirmed'], true))<form method="POST" action="{{ route('business.payments.remind', $p) }}">@csrf<button class="btn btn-xs">{{ icon('bell') }}Remind</button></form>@endif</td>
    </tr>
  @endforeach
  </tbody></table></div>
</div>
@endif

<div class="modal-backdrop hidden" id="declineM"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Decline this inquiry?</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('business.bookings.decline', $b) }}">@csrf<div class="modal-body"><div class="field"><label>Reason for the customer <span class="muted small">(optional)</span></label><input class="input" name="reason" maxlength="300" placeholder="e.g. Fully booked that weekend"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Decline</button></div></form></div></div>
<div class="modal-backdrop hidden" id="cancelM"><div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>Cancel this booking?</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div>
  <form method="POST" action="{{ route('business.bookings.cancel', $b) }}">@csrf<div class="modal-body"><p class="small muted">The customer is told right away. Refund what they paid ({{ money($b->paid()) }}) according to your cancellation policy.</p><div class="field"><label>Reason</label><input class="input" name="reason" required minlength="5" maxlength="500"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Cancel booking</button></div></form></div></div>

@push('scripts')
<script>
(function () {
  var f = document.getElementById('quoteF'); if (!f) return;
  var box = document.getElementById('qLines'), n = box.querySelectorAll('[data-qline]').length;
  var peso = function (x) { return '₱' + (Math.round(x * 100) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function calc() {
    var t = 0; box.querySelectorAll('[data-amt]').forEach(function (i) { t += Math.max(0, +i.value || 0); });
    document.getElementById('qTotal').textContent = peso(t);
    var r = +f.reservePct.value || 0, d = +f.depositPct.value || 0, bal = Math.max(0, 100 - r - d);
    document.getElementById('qSched').textContent = r + d > 100 ? 'Reservation fee + deposit cannot be more than 100%.'
      : 'Schedule: reservation fee ' + peso(t * r / 100) + ' when they accept · deposit ' + peso(t * d / 100) + ' {{ $set['depositDays'] }} days before · balance ' + peso(t * bal / 100) + ' {{ $set['balanceDays'] }} days before the event.';
  }
  document.getElementById('qAdd').addEventListener('click', function () {
    var row = box.querySelector('[data-qline]').cloneNode(true);
    row.querySelectorAll('input').forEach(function (i) { i.value = ''; i.name = i.name.replace(/items\[\d+\]/, 'items[' + n + ']'); });
    n++; box.appendChild(row); row.querySelector('input').focus(); calc();
  });
  box.addEventListener('click', function (e) { var b = e.target.closest('[data-qrm]'); if (!b) return; var rows = box.querySelectorAll('[data-qline]'); if (rows.length > 1) b.closest('[data-qline]').remove(); else b.closest('[data-qline]').querySelectorAll('input').forEach(function (i) { i.value = ''; }); calc(); });
  f.addEventListener('input', calc); calc();
})();
</script>
@endpush
@endsection
