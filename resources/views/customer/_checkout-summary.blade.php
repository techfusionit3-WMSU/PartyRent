{{-- Checkout Order summary. Change list: Rental Charges collapsible (closed) per shop; down payment and total outside it. --}}
@foreach($orders as $o)
  @php($q = $o['summary']['q'])
  <div class="mb-2">
    <div class="sum-line"><span><b>{{ $o['summary']['name'] }}</b><div class="small muted">{{ $o['summary']['plan'] }} · {{ $o['summary']['where'] }}</div>@unless($o['ok'])<div class="small" style="color:var(--red)">Needs attention</div>@endunless</span><span></span></div>
    @include('partials.rental-charges', ['q' => $q, 'open' => true])
    @if($q['plan'] !== 'full')
      @if($q['reservationFee'] > 0)<div class="sum-line"><span class="muted">Reservation fee{{ term('reservation_fee') }}</span><span>{{ money($q['reservationFee']) }}</span></div>@endif
      @if($q['downPayment'] > 0)<div class="sum-line"><span class="muted">Down payment{{ term('down_payment') }}</span><span>{{ money($q['downPayment']) }}</span></div>@endif
    @endif
    <div class="sum-line strong"><span>Total</span><span>{{ money($q['total']) }}</span></div>
    @php($first = $q['plan'] === 'full' ? $q['total'] : $q['reservationFee'] + $q['downPayment'])
    <div class="pay-when mt-1">
      <div class="sum-line"><span><b>{{ __('Pay first') }}</b><div class="small muted">{{ __('after the shop accepts') }}</div></span><b>{{ money($first) }}</b></div>
      @if($q['plan'] !== 'full' && $q['finalBalance'] > 0)<div class="sum-line"><span><b>{{ __('Pay later') }}</b><div class="small muted">{{ $q['plan'] === 'installment' ? __('in installments, by the event date') : __('by the event date') }}</div></span><b>{{ money($q['finalBalance']) }}</b></div>@endif
      @if(($q['deposit'] ?? 0) > 0)<div class="sum-line"><span>{{ __('Security deposit') }}{{ term('security_deposit') }}<div class="small muted">{{ __('refundable, collected separately') }}</div></span><span>{{ money($q['deposit']) }}</span></div>@endif
    </div>
  </div>
@endforeach
@if(count($orders) > 1)<div class="sum-line total"><span>Combined total</span><span class="price">{{ money($total) }}</span></div>@endif
<p class="small muted mb-0">{{ __('Nothing is charged until the shop accepts. Each shop's down payment and balance come from its own order total.') }}</p>
