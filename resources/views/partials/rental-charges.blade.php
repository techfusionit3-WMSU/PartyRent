{{-- Rental Charges collapsible (reference UI.rentalCharges). $q = Pricing::quoteOrder(), $open, $estimate, $note, $feeNote --}}
@php
    $lines = $q['lines'] ?? [];
    $dayCount = collect($lines)->flatMap(fn ($l) => array_column($l['days'] ?? [], 'date'))->unique()->count();
    $del = array_values(array_filter($q['delivery'] ?? [], fn ($d) => $d['fee'] > 0));
    $sm = 'width:14px;height:14px;vertical-align:-2px';
@endphp
<details class="charges" @if($open ?? false) open @endif>
  <summary><span class="ch-title">{{ icon('receipt', $sm) }} Rental Charges @if($estimate ?? false)<span class="badge b-gray plain">Estimate</span>@endif</span><span class="ch-total">{{ money($q['total']) }}</span><span class="ch-chev" aria-hidden="true">{{ icon('down', $sm) }}</span></summary>
  <div class="ch-body">
    <div class="sum-line"><span>Rental Fee<div class="small muted">{{ $feeNote ?? (count($lines).' item'.(count($lines) === 1 ? '' : 's').($dayCount ? ' · '.$dayCount.' rental day'.($dayCount > 1 ? 's' : '') : '').' · supplier daily rates') }}</div></span><span>{{ money($q['rentalFee'] + ($q['discount'] ?? 0) + (float) ($q['customDiscount']['amount'] ?? 0) + array_sum(array_column($q['credits'] ?? [], 'amount')) - array_sum(array_column($q['charges'] ?? [], 'amount'))) }}</span></div>
    @if(($q['bulkSaved'] ?? 0) > 0)<div class="small" style="color:var(--green);margin:-4px 0 6px">{{ icon('tag', 'width:13px;height:13px;vertical-align:-2px') }} Includes bulk discount: you save {{ money($q['bulkSaved']) }}</div>@endif
    @foreach($q['discounts'] ?? [] as $dc)
      <div class="sum-line" style="color:var(--green)"><span>Shop discount – {{ $dc['name'] }}<div class="small muted">{{ $dc['item'] }} · {{ $dc['label'] }}</div></span><span>−{{ money($dc['amount']) }}</span></div>
    @endforeach
    {{-- a custom discount the shop gave this customer (kept as it was applied, App\Domain\CustomDiscounts) --}}
    @if(! empty($q['customDiscount']))
      <div class="sum-line" style="color:var(--green)"><span>Special discount – {{ $q['customDiscount']['name'] }}<div class="small muted">{{ $q['customDiscount']['label'] }} · given by the shop</div></span><span>−{{ money($q['customDiscount']['amount']) }}</span></div>
    @endif
    {{-- problem cases: agreed price reductions and rush costs the customer agreed to cover --}}
    @foreach($q['credits'] ?? [] as $cr)
      <div class="sum-line" style="color:var(--green)"><span>Case credit<div class="small muted">{{ $cr['label'] }}</div></span><span>−{{ money($cr['amount']) }}</span></div>
    @endforeach
    @foreach($q['charges'] ?? [] as $cg)
      <div class="sum-line"><span>Rush costs<div class="small muted">{{ $cg['label'] }}</div></span><span>{{ money($cg['amount']) }}</span></div>
    @endforeach
    @if(count($del) === 1)
      <div class="sum-line"><span>Delivery Fee<div class="small muted">{{ $del[0]['label'] }} · {{ $del[0]['zone'] }}</div></span><span>{{ money($del[0]['fee']) }}</span></div>
    @elseif(count($del) > 1)
      <div class="sum-line"><span>Delivery Fee <span class="small muted">({{ count($del) }} locations)</span></span><span>{{ money($q['deliveryFee']) }}</span></div>
      @foreach($del as $d)<div class="sum-line small ch-sub"><span>{{ $d['label'] }}<div class="muted">{{ $d['zone'] }}</div></span><span>{{ money($d['fee']) }}</span></div>@endforeach
    @endif
    @if(($q['earlyDeliveryFee'] ?? 0) > 0)
      <div class="sum-line"><span>Earlier delivery<div class="small muted">accepted by the shop</div></span><span>{{ money($q['earlyDeliveryFee']) }}</span></div>
    @endif
    @foreach(array_filter($q['services'] ?? [], fn ($x) => $x['amount'] > 0) as $x)
      <div class="sum-line"><span>Service Fee – {{ $x['name'] }}<div class="small muted">{{ $x['description'] }}{{ $x['qty'] > 1 ? ' · '.money($x['unit']).' × '.$x['qty'].' visits' : '' }}{{ $x['required'] ? '' : ' · optional' }}</div></span><span>{{ money($x['amount']) }}</span></div>
    @endforeach
    <div class="sum-line total"><span>Total Rental Charges</span><span class="price">{{ money($q['total']) }}</span></div>
    @if(! empty($note))<div class="small muted">{{ $note }}</div>@endif
  </div>
</details>
