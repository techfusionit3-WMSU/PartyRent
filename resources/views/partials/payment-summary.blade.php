{{-- Payment summary card: customer, supplier, cashier and admin all see the same numbers. --}}
@php($f = $r->fin())
@php($p = $f['pricing'])
<div class="row between mb-1"><h3 class="mb-0">Payment summary</h3>@include('partials.badges', ['pay' => $f['status']])</div>
<div class="sum-line"><span class="muted">Items & packages</span><span>{{ money($p['itemsSubtotal']) }}</span></div>
@if($p['deliveryFee'])<div class="sum-line"><span class="muted">Delivery fee</span><span>{{ money($p['deliveryFee']) }}</span></div>@endif
<div class="sum-line strong"><span>Total rental amount</span><span>{{ money($p['total']) }}</span></div>
@if($p['reservationFee'])
  <div class="sum-line"><span class="muted">Reservation fee @if($p['reservationCredited'])<span class="badge b-teal plain">credited to down payment</span>@else<span class="badge b-gold plain">additional charge</span>@endif</span><span>{{ money($p['reservationFee']) }}</span></div>
@endif
@if($p['arrangement'] === 'down')
  <div class="sum-line"><span class="muted">Required down payment{{ $p['downPct'] ? ' ('.$p['downPct'].'%)' : '' }}</span><span>{{ money($p['downRequired']) }}</span></div>
  @if($p['reservationFee'] && $p['reservationCredited'])<div class="sum-line"><span class="muted">Remaining down payment after reservation fee</span><span>{{ money(max(0, $p['downRequired'] - $p['reservationFee'])) }}</span></div>@endif
  <div class="sum-line"><span class="muted">Balance after down payment</span><span>{{ money($p['payable'] - $p['downRequired'] - ($p['reservationCredited'] ? 0 : $p['reservationFee'])) }}</span></div>
@endif
@if(! $p['reservationCredited'] && $p['reservationFee'])<div class="sum-line strong"><span>Total payable</span><span>{{ money($p['payable']) }}</span></div>@endif
<div class="sum-line"><span class="muted">Verified payments</span><span style="color:var(--green)">− {{ money($f['paidRental']) }}</span></div>
@if($f['pendingRental'])<div class="sum-line"><span class="muted">Pending verification</span><span style="color:#B45309">{{ money($f['pendingRental']) }}</span></div>@endif
@if($f['refunded'])<div class="sum-line"><span class="muted">Refunded</span><span>{{ money($f['refunded']) }}</span></div>@endif
<div class="sum-line total"><span>Remaining balance</span><span class="price">{{ money($f['balance']) }}</span></div>

@if($f['schedule'])
  <div class="mt-2"><div class="label mb-1">Payment schedule</div>
    @foreach($f['schedule'] as $sc)
      <div class="sum-line"><span>{{ ['paid' => '✅', 'overdue' => '⚠️', 'upcoming' => '🕒'][$sc['state']] }} {{ $sc['label'] }}<div class="small muted">Due {{ fmt_date($sc['due']) }}</div></span><span class="right"><b>{{ money($sc['amount']) }}</b><div class="small" style="color:{{ $sc['state'] === 'overdue' ? 'var(--red)' : ($sc['state'] === 'paid' ? 'var(--green)' : 'var(--muted)') }}">{{ ucfirst($sc['state']) }}</div></span></div>
    @endforeach
  </div>
@elseif($r->status === 'pending')
  <div class="alert alert-info mt-2">{{ icon('info') }}<div>Payment requirements will be generated once the supplier accepts this request.</div></div>
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
