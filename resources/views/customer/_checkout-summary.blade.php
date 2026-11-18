{{-- Checkout Order summary. Change list: Rental Charges collapsible (closed) per shop; down payment and total outside it. --}}
@foreach($orders as $o)
  @php($q = $o['summary']['q'])
  <div class="mb-2">
    <div class="sum-line"><span><b>{{ $o['summary']['name'] }}</b><div class="small muted">{{ $o['summary']['plan'] }} · {{ $o['summary']['where'] }}</div>@unless($o['ok'])<div class="small" style="color:var(--red)">Needs attention</div>@endunless</span><span></span></div>
    @include('partials.rental-charges', ['q' => $q, 'open' => false])
    @if($q['plan'] !== 'full')
      @if($q['reservationFee'] > 0)<div class="sum-line"><span class="muted">Reservation fee</span><span>{{ money($q['reservationFee']) }}</span></div>@endif
      @if($q['downPayment'] > 0)<div class="sum-line"><span class="muted">Down payment</span><span>{{ money($q['downPayment']) }}</span></div>@endif
    @endif
    <div class="sum-line strong"><span>Total</span><span>{{ money($q['total']) }}</span></div>
  </div>
@endforeach
@if(count($orders) > 1)<div class="sum-line total"><span>Combined total</span><span class="price">{{ money($total) }}</span></div>@endif
<p class="small muted mb-0">Each shop's down payment and balance are calculated from its own order total.</p>
