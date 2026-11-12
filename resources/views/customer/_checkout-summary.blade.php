{{-- Checkout order summary numbers; re-rendered by CartController@quote when the delivery city changes. --}}
@include('partials.subtotals', ['p' => $q])
@if($q['delivery'])
  @if($q['delivery']['ok'])
    <div class="sum-line"><span class="muted">Delivery{{ $q['delivery']['waived'] ? ' (free)' : '' }}</span><span>{{ money($q['deliveryFee']) }}</span></div>
  @else
    <div class="sum-line"><span class="muted">Delivery</span><span class="small" style="color:var(--red)">Not available</span></div>
  @endif
@endif
<div class="sum-line total"><span>Total rental amount</span><span class="price">{{ money($q['total']) }}</span></div>
@if($q['reservationFee'])<div class="sum-line"><span class="muted">Reservation fee</span><span>{{ money($q['reservationFee']) }}</span></div><div class="small muted mb-1">{!! $q['reservationCredited'] ? 'Credited toward your down payment / total — not charged twice.' : 'Charged in addition to the rental amount. Total payable: <b>'.e(money($q['payable'])).'</b>' !!}</div>@endif
@if($q['arrangement'] === 'down')
  <div class="sum-line"><span class="muted">Down payment ({{ $q['downPct'] !== null ? $q['downPct'].'%' : 'fixed' }})</span><span>{{ money($q['downRequired']) }}</span></div>
  @if($q['reservationFee'] && $q['reservationCredited'])<div class="sum-line"><span class="muted">Remaining down payment</span><span>{{ money($q['downRequired'] - $q['reservationFee']) }}</span></div>@endif
  <div class="sum-line"><span class="muted">Balance after down payment</span><span>{{ money($q['payable'] - $q['downRequired'] - ($q['reservationCredited'] ? 0 : $q['reservationFee'])) }}</span></div>
@endif
<div class="sum-line"><span class="muted">Security deposit <span class="badge b-purple plain">refundable</span></span><span>{{ money($q['deposit']) }}</span></div>
<div class="small muted">{{ ($q['rentalSubtotal'] ?? 1) > 0 ? 'Collected separately from rental charges and returned after inspection.' : 'No deposit: nothing in this order is returned.' }}</div>
@if(($q['salesSubtotal'] ?? 0) > 0)<div class="alert alert-info mt-1">{{ icon('info') }}<div class="small">Items for sale ({{ money($q['salesSubtotal']) }}) are consumed and not returned. They are refundable only until the rental partner starts preparing your order; after that they are non-refundable.</div></div>@endif
@isset($due)
  @php($first = reset($due))
  <div class="card card-flat mt-2" style="padding:12px 14px;border-color:var(--brand)" data-due-box @foreach($due as $m => $d) data-due-{{ $m }}="{{ $d['label'] }}|{{ money($d['amount']) }}" @endforeach>
    <div class="sum-line strong"><span>Pay now to confirm<br><span class="small muted" data-due-label>{{ $first['label'] }}</span></span><span class="price" data-due-amount>{{ money($first['amount']) }}</span></div>
    <div class="small muted">Paid to PartyRent and held until your rental is completed. Refunded in full if the rental partner declines.</div>
  </div>
@endisset
<div class="label mt-2 mb-1">Expected schedule (if accepted today)</div>
@foreach($schedule as $x)<div class="sum-line small"><span>{{ $x['label'] }}<br><span class="muted">by {{ fmt_date($x['due']) }}</span></span><b>{{ money($x['amount']) }}</b></div>@endforeach
