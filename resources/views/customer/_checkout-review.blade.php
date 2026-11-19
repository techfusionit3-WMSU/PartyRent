{{-- One shop's review in the checkout (reference C.checkout refresh()). $q quote, $o order, $prep prepareOrder result --}}
@php
    $t = $q['terms'];
    $dd = fn ($n) => $n ? 'within '.$n.' day'.($n > 1 ? 's' : '').' after acceptance' : 'right after acceptance';
    $ev = $o['eventDate'] ?? null;
@endphp
@unless($prep['ok'])<div class="alert alert-danger mb-1">{{ icon('alert') }}<div>{{ $prep['error'] }}@if(! empty($prep['check']) || ! empty($prep['durationIssues']) || ! empty($prep['timeIssues'])) <a href="{{ route('cart') }}">Edit in Rental List</a>@endif</div></div>@endunless
<div class="label mt-1 mb-1">{{ \App\Domain\Finance::PLAN_LABEL[$q['plan']] }}</div>
@if($q['plan'] === 'full')
  <div class="sum-line"><span>Full Payment (100%){{ term('full_payment') }}<div class="small muted">{{ $dd($t['days'] ?? 0) }}</div></span><b>{{ money($q['total']) }}</b></div>
@else
  @if($q['reservationFee'])<div class="sum-line"><span>Reservation Fee ({{ $t['resPct'] }}%){{ term('reservation_fee') }} <span class="badge b-teal plain">credited</span>{{ term('credited') }}<div class="small muted">non-refundable · {{ $dd($t['resDays'] ?? 0) }}</div></span><b>{{ money($q['reservationFee']) }}</b></div>@endif
  @if($q['downPayment'])<div class="sum-line"><span>Down Payment ({{ $t['downPct'] }}%){{ term('down_payment') }} <span class="badge b-teal plain">credited</span>{{ term('credited') }}<div class="small muted">{{ $dd($t['downDays'] ?? 0) }}</div></span><b>{{ money($q['downPayment']) }}</b></div>@endif
  <div class="sum-line"><span>{{ $q['plan'] === 'installment' ? 'Installments ('.count($o['inst']['items'] ?? []).')' : 'Final Balance' }}{{ term($q['plan'] === 'installment' ? 'installment' : 'final_balance') }}<div class="small muted">on or before {{ $ev ? fmt_date($ev) : 'the event date' }}</div></span><b>{{ money($q['finalBalance']) }}</b></div>
@endif
<div class="sum-line"><span class="muted">Security deposit{{ term('security_deposit') }} <span class="badge b-purple plain">refundable</span></span><span>{{ money($q['deposit']) }}</span></div>
<div class="small muted">Collected separately from rental charges and returned after inspection.</div>
