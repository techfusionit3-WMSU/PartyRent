{{-- Product page: delivery & policies, collapsible inside the rent box. Needs $p, $sup. --}}
<details class="pdx-pol mt-2"><summary>{{ icon('truck', 'width:16px;height:16px') }}<span class="grow">Delivery &amp; policies</span><span class="small muted">{{ $sup->fulfillmentLabel() }}</span></summary>
    <dl class="kv">
@php($myCity = customer_city())
<dt>Delivery / pickup</dt><dd>{{ $sup->fulfillmentLabel() }}{{ ! $p->delivery && $sup->offersDelivery() ? ' (this item: pickup only)' : '' }}{{ $sup->offersDelivery() ? ' · '.$sup->deliverySummary() : '' }}
  @if($myCity && $sup->offersDelivery() && $p->delivery)
    <div class="small">@foreach($sup->deliveryAreas() as $a)<div>{{ $a }}</div>@endforeach</div>
  @elseif(! $myCity && $sup->offersDelivery())
    <div class="small muted">@auth<a href="{{ route('profile') }}">Set your city</a>@else Log in and set your city @endauth to see the delivery fee.</div>
  @endif
</dd>
@if($p->handling_fee > 0)<dt>Handling</dt><dd>{{ money($p->handling_fee) }} {{ $p->handling_per === 'unit' ? 'per unit' : 'per order' }} when delivered</dd>@endif
<dt>Payment plans</dt><dd>{{ collect(\App\Domain\Finance::plansOf($sup))->map(fn ($a) => \App\Domain\Finance::PLAN_LABEL[$a])->implode(', ') }}</dd>
@php($sst = \App\Domain\Slots::settings($sup))
<dt>Rental hours</dt><dd>{{ \App\Domain\Slots::fmt12($sst['open']) }} – {{ \App\Domain\Slots::fmt12($sst['close']) }} · {{ +$sst['prepHours'] }}h preparation / {{ +$sst['recoveryHours'] }}h recovery between bookings</dd>
@if($frame = \App\Domain\Slots::frameLabel($p))<dt>Item time frame</dt><dd>{{ $frame }}</dd>@endif
<dt>Payment methods</dt><dd>{{ collect($sup->acceptedMethods())->map(fn ($m) => \App\Models\Payment::METHOD_LABEL[$m] ?? $m)->implode(', ') }}</dd>
<dt>Security deposit</dt><dd>{{ $sup->depositLabel() }} (refundable)</dd>
@if(! $p->isSale())<dt>Rental duration</dt><dd>{{ $p->max_rental_days ? 'Maximum Rental: '.$p->max_rental_days.' Day'.($p->max_rental_days > 1 ? 's' : '').' per booking' : 'No Rental Duration Limit' }}</dd>@endif
<dt>Cancellation</dt><dd>{{ $sup->pol('cancellation') }}</dd>
<dt>Returns</dt><dd>{{ $sup->pol('returns') }}</dd>
    </dl>
</details>
