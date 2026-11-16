{{-- Delivery locations of a request (reference Shared.destinations). $r --}}
@php($sup = $r->supplier)
@foreach($r->destinations ?? [] as $i => $d)
  @php($ls = array_values(array_filter($r->pricing['lines'] ?? [], fn ($l) => ($l['destId'] ?? null) === $d['id'])))
  @php($fee = collect($r->pricing['delivery'] ?? [])->firstWhere('destId', $d['id'])['fee'] ?? 0)
  @php($times = collect($ls)->map(fn ($l) => \App\Domain\Slots::rangeLabel(\App\Domain\Slots::time($l)))->unique()->implode(', '))
  <div class="dest-card"><div class="row between"><b>{{ count($r->destinations) > 1 ? 'Location '.($i + 1).': ' : '' }}{{ $d['label'] }}</b><span class="small">{{ $fee ? money($fee) : 'Free' }}</span></div>
    <div class="small muted">{{ $d['address'] }}</div>
    <div class="small mt-1">{{ ! empty($d['deliveryTime']) ? 'Delivery by '.\App\Domain\Slots::fmt12($d['deliveryTime']).' · ' : '' }}Rental: {{ $times ?: '—' }} · {{ \App\Domain\Pricing::zoneName($sup, $d['zoneId'] ?? null) }}</div>
    <div class="small mt-1">@foreach($ls as $l)<span class="tag">{{ $l['name'] ?? '' }} × {{ max(array_column($l['days'], 'qty') ?: [0]) }}</span> @endforeach</div>
  </div>
@endforeach
