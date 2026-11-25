{{-- Delivery locations of a request (reference Shared.destinations). $r --}}
@php($sup = $r->supplier)
@foreach($r->destinations ?? [] as $i => $d)
  @php($ls = array_values(array_filter($r->pricing['lines'] ?? [], fn ($l) => ($l['destId'] ?? null) === $d['id'])))
  @php($fee = collect($r->pricing['delivery'] ?? [])->firstWhere('destId', $d['id'])['fee'] ?? 0)
  @php($times = collect($ls)->map(fn ($l) => \App\Domain\Slots::rangeLabel(\App\Domain\Slots::time($l)))->unique()->implode(', '))
  <div class="dest-card"><div class="row between"><b>{{ count($r->destinations) > 1 ? 'Location '.($i + 1).': ' : '' }}{{ $d['label'] }}</b><span class="small">{{ $fee ? money($fee) : 'Free' }}</span></div>
    <div class="small muted">{{ $d['address'] }}@if(isset($d['lat'], $d['lng'])) · <a href="https://www.openstreetmap.org/?mlat={{ $d['lat'] }}&mlon={{ $d['lng'] }}#map=18/{{ $d['lat'] }}/{{ $d['lng'] }}" target="_blank" rel="noopener">{{ icon('map', 'width:13px;height:13px;vertical-align:-2px') }} Pinned spot</a>@endif</div>
    @php($early = $d['early'] ?? null)
    <div class="small mt-1">{{ ! empty($d['deliveryTime']) ? 'Delivery by '.\App\Domain\Slots::fmt12($d['deliveryTime']).(($early['status'] ?? '') === 'accepted' ? ' (earlier delivery, confirmed)' : '').' · ' : '' }}Rental: {{ $times ?: '—' }} · {{ \App\Domain\Pricing::zoneName($sup, $d['zoneId'] ?? null) }}</div>
    @if(! empty($d['contactName']) || ! empty($d['contactPhone']))<div class="small mt-1">{{ icon('user', 'width:13px;height:13px;vertical-align:-2px') }} On-site contact: <b>{{ $d['contactName'] ?? '' }}</b>{{ ! empty($d['contactPhone']) ? ' · '.$d['contactPhone'] : '' }}</div>@endif
    @if($early)
      <div class="early-box mt-1 {{ $early['status'] }}">
        @if($early['status'] === 'pending')
          <b>Earlier delivery requested:</b> {{ \App\Domain\Slots::fmt12($early['time']) }} instead of {{ \App\Domain\Slots::fmt12($d['standardTime'] ?? $d['deliveryTime']) }} · +{{ money($early['fee']) }} <span class="badge b-gold">Waiting for the shop</span>
          <div class="small muted">Not confirmed yet: the standard time and price apply unless the shop accepts.</div>
          @if(($canDecideEarly ?? false) && ! in_array($r->status, ['declined', 'cancelled', 'completed'], true))
            @php($sameDay = \App\Models\RentalRequest::where('supplier_id', $r->supplier_id)->whereKeyNot($r->id)->where('start_date', $r->start_date)->where('fulfillment', 'delivery')->whereNotIn('status', ['declined', 'cancelled', 'pending'])->count())
            <div class="small mt-1">Your hours: {{ \App\Domain\Slots::fmt12(\App\Domain\Slots::settings($sup)['open']) }} – {{ \App\Domain\Slots::fmt12(\App\Domain\Slots::settings($sup)['close']) }} · other deliveries that day: {{ $sameDay }}</div>
            <div class="btn-group mt-1">
              <form method="POST" action="{{ route('supplier.requests.early', [$r, $d['id'], 'accept']) }}" data-confirm-title="Accept earlier delivery?" data-confirm="Deliver to {{ $d['label'] }} by {{ \App\Domain\Slots::fmt12($early['time']) }}. {{ money($early['fee']) }} is added to the customer's total.">@csrf<button class="btn btn-xs btn-primary">Accept (+{{ money($early['fee']) }})</button></form>
              <form method="POST" action="{{ route('supplier.requests.early', [$r, $d['id'], 'reject']) }}" data-confirm-title="Reject earlier delivery?" data-confirm="The standard delivery time stays and no fee is added.">@csrf<button class="btn btn-xs">Reject</button></form>
            </div>
          @endif
        @elseif($early['status'] === 'accepted')
          <b>Earlier delivery confirmed</b> by the shop: {{ \App\Domain\Slots::fmt12($early['time']) }} (+{{ money($early['fee']) }})
        @else
          <b>Earlier delivery not possible</b> ({{ \App\Domain\Slots::fmt12($early['time']) }} was asked). Delivery stays at {{ \App\Domain\Slots::fmt12($d['standardTime'] ?? $d['deliveryTime']) }}; no extra fee.
        @endif
      </div>
    @endif
    <div class="small mt-1">@foreach($ls as $l)<span class="tag">{{ $l['name'] ?? '' }} × {{ max(array_column($l['days'], 'qty') ?: [0]) }}</span> @endforeach</div>
  </div>
@endforeach
