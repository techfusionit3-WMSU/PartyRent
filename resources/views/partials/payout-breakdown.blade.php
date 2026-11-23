{{-- Shopee-style payout breakdown. $b = Payouts::breakdown() or Payout::breakdown(); optional $title, $netLabel --}}
@php($pctTxt = rtrim(rtrim(number_format($b['pct'], 2), '0'), '.'))
<div class="payout-bd small" style="min-width:min(240px, 100%);text-align:left;margin-top:6px">
  @isset($title)<div class="strong mb-1">{{ $title }}</div>@endisset
  <div class="row between"><span>Items &amp; services</span><span class="num">{{ money($b['items']) }}</span></div>
  <div class="row between"><span>Platform commission ({{ $pctTxt }}%)</span><span class="num">− {{ money($b['commission']) }}</span></div>
  @if($b['delivery'] > 0)<div class="row between"><span>Delivery fee <span class="small muted">no commission</span></span><span class="num">+ {{ money($b['delivery']) }}</span></div>@endif
  @if($b['voucher'] > 0)<div class="row between"><span>Free Shipping voucher <span class="small muted">paid by PartyRent</span></span><span class="num">+ {{ money($b['voucher']) }}</span></div>@endif
  @if($b['compensation'] > 0)<div class="row between"><span>Damage charges <span class="small muted">no commission</span></span><span class="num">+ {{ money($b['compensation']) }}</span></div>@endif
  @if(abs($b['cashKept'] ?? 0) >= 0.005)
    <div class="row between strong" style="border-top:1px solid var(--line);padding-top:6px;margin-top:4px"><span>You earned</span><span class="num">{{ money($b['earned']) }}</span></div>
    <div class="row between"><span>Cash you already collected <span class="small muted">yours already</span></span><span class="num">− {{ money($b['cashKept']) }}</span></div>
  @endif
  <div class="row between strong" style="border-top:1px solid var(--line);padding-top:6px;margin-top:4px"><span>{{ $netLabel ?? 'You receive' }}</span><span class="num">{{ $b['net'] < 0 ? '− '.money(-$b['net']) : money($b['net']) }}</span></div>
</div>
