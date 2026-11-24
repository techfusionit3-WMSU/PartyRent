{{-- Bulk discount on an item page: nothing until the customer's quantity reaches a tier; then only that discount
     ("26.67% bulk discount applied · ₱11.00 each, was ₱15.00"). The tiers are hidden data for public/js/app.js. Needs $p. --}}
@php($steps = \App\Domain\Pricing::bulkSteps($p))
@if($steps)
<div class="bulk-applied" data-bulk-prices hidden aria-live="polite" data-was="{{ money($p->basePrice()) }}">
  @foreach($steps as $t)<span hidden data-tier data-min="{{ $t['min'] }}" data-pct="{{ \App\Domain\Pricing::pctText($t['pct']) }}" data-price="{{ money($t['price']) }}"></span>@endforeach
  {{ icon('tag', 'width:14px;height:14px;vertical-align:-2px') }} <span data-bulk-text></span>
</div>
@endif
