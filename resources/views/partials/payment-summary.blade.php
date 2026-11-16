{{-- Payment summary card (reference Shared.paymentSummary): customer, supplier, cashier and admin all see the same numbers. --}}
@php($f = $r->fin())
@php($p = $f['pricing'])
@php($ip = $r->installment_plan)
@php($plan = $p['plan'] ?? 'full')
<div class="row between mb-1"><h3 class="mb-0">Payment summary</h3>@include('partials.badges', ['pay' => $f['status']])</div>
<div class="row wrap gap-sm mb-1">@include('partials.badges', ['planBadge' => $plan])@if($f['reservationConfirmed'])<span class="badge b-green plain">{{ $plan === 'full' ? 'Order confirmed' : 'Reservation confirmed' }}</span>@endif @if($f['pendingRental'])<span class="badge b-gold plain">Awaiting verification: {{ money($f['pendingRental']) }}</span>@endif</div>
@include('partials.rental-charges', ['q' => $p])
@if($plan === 'full')
  <div class="sum-line"><span class="muted">Full Payment (100%)</span><span>{{ money($p['total']) }}</span></div>
@else
  @if($p['reservationFee'])<div class="sum-line"><span class="muted">Reservation Fee ({{ $p['terms']['resPct'] }}%) <span class="badge b-teal plain">non-refundable · credited</span></span><span>{{ money($p['reservationFee']) }}</span></div>@endif
  @if($p['downPayment'])<div class="sum-line"><span class="muted">Down Payment ({{ $p['terms']['downPct'] }}%) <span class="badge b-teal plain">credited</span></span><span>{{ money($p['downPayment']) }}</span></div>@endif
  <div class="sum-line"><span class="muted">{{ $plan === 'installment' ? 'Installment balance' : 'Final Balance' }} ({{ 100 - ($p['terms']['resPct'] ?? 0) - ($p['terms']['downPct'] ?? 0) }}%)</span><span>{{ money($p['finalBalance']) }}</span></div>
@endif
@if($ip)<div class="small mt-1">Installment schedule: <span class="badge {{ ['approved' => 'b-green', 'proposed' => 'b-gold', 'rejected' => 'b-red'][$ip['status']] ?? 'b-gray' }}">{{ ucfirst($ip['status'] === 'proposed' ? 'awaiting supplier approval' : $ip['status']) }}</span>@if($ip['status'] === 'rejected' && ! empty($ip['reason']))<div class="muted">{{ $ip['reason'] }}</div>@endif</div>@endif
<div class="sum-line strong mt-1"><span>Total rental amount</span><span>{{ money($f['payable']) }}</span></div>
<div class="sum-line"><span class="muted">Verified payments</span><span style="color:var(--green)">− {{ money($f['paidRental']) }}</span></div>
@if($f['pendingRental'])<div class="sum-line"><span class="muted">Pending verification</span><span style="color:#B45309">{{ money($f['pendingRental']) }}</span></div>@endif
@if($f['refunded'])<div class="sum-line"><span class="muted">Refunded</span><span>{{ money($f['refunded']) }}</span></div>@endif
<div class="sum-line total"><span>Outstanding balance</span><span class="price">{{ money($f['balance']) }}</span></div>

@if($f['schedule'])
  <div class="mt-2"><div class="label mb-1">Payment schedule</div>
    @foreach($f['schedule'] as $sc)
      <div class="sum-line"><span>{{ ['paid' => '✅', 'overdue' => '⚠️', 'upcoming' => '🕒'][$sc['state']] }} {{ $sc['label'] }}<div class="small muted">Due {{ fmt_date($sc['due']) }}</div></span><span class="right"><b>{{ money($sc['amount']) }}</b><div class="small" style="color:{{ $sc['state'] === 'overdue' ? 'var(--red)' : ($sc['state'] === 'paid' ? 'var(--green)' : 'var(--muted)') }}">{{ ucfirst($sc['state']) }}</div></span></div>
    @endforeach
    <p class="small muted mb-0">All payments are due on or before the event date ({{ fmt_date($r->event_date) }}).</p>
  </div>
@elseif($r->status === 'pending')
  <div class="alert alert-info mt-2">{{ icon('info') }}<div>Payment deadlines start once the supplier accepts this request.</div></div>
@endif

<div class="card card-flat soft mt-2" style="padding:14px">
  <div class="row between"><b>{{ icon('shield', 'width:16px;vertical-align:-3px') }} Security deposit</b><span class="badge b-purple">{{ $f['depositStatus'] }}</span></div>
  <div class="small muted mt-1">Refundable · tracked separately from rental charges</div>
  <div class="sum-line"><span class="muted">Required</span><span>{{ money($f['depositRequired']) }}</span></div>
  <div class="sum-line"><span class="muted">Collected</span><span>{{ money($f['depositPaid']) }}</span></div>
  @if($f['depositApplied'])<div class="sum-line"><span class="muted">Applied to damages</span><span>{{ money($f['depositApplied']) }}</span></div>@endif
  @if($f['depositRefunded'])<div class="sum-line"><span class="muted">Refunded</span><span>{{ money($f['depositRefunded']) }}</span></div>@endif
  <div class="sum-line strong"><span>Currently held</span><span>{{ money($f['depositHeld']) }}</span></div>
</div>

@if($f['compensation'])
  <div class="card card-flat mt-2" style="padding:14px;border-color:#F8C4C4">
    <div class="row between"><b>{{ icon('tool', 'width:16px;vertical-align:-3px') }} Damage compensation</b><span class="badge {{ ($r->inspection['settlement'] ?? '') === 'pending' ? 'b-red' : 'b-green' }}">{{ ucfirst($r->inspection['settlement'] ?? '') }}</span></div>
    <div class="small muted mt-1">Separate from rental charges</div>
    <div class="sum-line"><span class="muted">Assessed</span><span>{{ money($f['compensation']) }}</span></div>
    <div class="sum-line strong"><span>Still due</span><span>{{ money($f['compensationDue']) }}</span></div>
  </div>
@endif
