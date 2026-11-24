{{-- Bulk discount price list on an item page: every quantity range with its price each; the one the customer's
     quantity reaches is highlighted, with how many more pieces reach the next discount (public/js/app.js). Needs $p. --}}
@php
  $steps = \App\Domain\Pricing::bulkSteps($p);
  $base = $p->basePrice();
  $list = array_merge([['min' => 1, 'pct' => 0.0, 'price' => $base]], $steps);
@endphp
@if($steps)
<div class="bulk-prices" data-bulk-prices>
  <div class="label mb-0">{{ icon('tag', 'width:14px;height:14px;vertical-align:-2px') }} Bulk discount <span class="muted small">· per {{ $p->isSale() ? 'order' : 'day, by how many you rent that day' }}</span></div>
  <div class="bulk-list">
    @foreach($list as $i => $t)
      @php($next = $list[$i + 1]['min'] ?? null)
      <span class="bulk-chip" data-tier data-min="{{ $t['min'] }}" @if($next) data-max="{{ $next - 1 }}" @endif data-pct="{{ \App\Domain\Pricing::pctText($t['pct']) }}" data-price="{{ money($t['price']) }}">{{ $next ? ($next - 1 > $t['min'] ? $t['min'].'–'.($next - 1) : $t['min']) : $t['min'].'+' }} · <b>{{ money($t['price']) }}</b>@if($t['pct']) · {{ \App\Domain\Pricing::pctText($t['pct']) }}% off @endif</span>
    @endforeach
  </div>
  <div class="bulk-next" data-bulk-next aria-live="polite"></div>
</div>
@endif
