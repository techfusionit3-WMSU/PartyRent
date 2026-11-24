{{--
  Damage fees customers see before booking (item page, package page, checkout). Not a rental charge: only paid when an
  item comes back damaged or missing, after the shop checks it (App\Domain\Damage). Itemized per rental item, with
  packages broken into their items.
  $rows = [['name', 'unit', 'qty', 'minor', 'max'], …] (Damage::termsForLines / liability), $personal (shop uses the
  minor / repair / not working / lost outcomes), $title (optional), $showQty (default true).
--}}
@php
    $showQty = $showQty ?? true;
    $rows = array_values($rows);
    $withFee = array_filter($rows, fn ($x) => $x['max'] !== null);
    $total = r2(array_sum(array_map(fn ($x) => (float) $x['max'] * (int) ($x['qty'] ?? 1), $withFee)));
    $anyMinor = (bool) array_filter($rows, fn ($x) => $x['minor'] !== null);
@endphp
<div class="dmg-fees">
  <p class="small mt-0 mb-1"><b>Not part of the rental price.</b> You only pay a damage fee if an item comes back damaged or missing, after the shop checks it at return. You get {{ \App\Domain\Damage::REVIEW_HOURS }} hours to accept or dispute any charge, and PartyRent decides disputes.</p>
  <div class="table-wrap"><table class="table cards dmg-table"><thead><tr><th>Item</th>@if($showQty)<th class="num">Qty</th>@endif @if($anyMinor)<th class="num">Minor damage</th>@endif<th class="num">Lost or beyond repair</th>@if($showQty)<th class="num">Up to</th>@endif</tr></thead><tbody>
    @foreach($rows as $x)
      <tr><td data-label="Item"><b>{{ $x['name'] }}</b></td>
        @if($showQty)<td data-label="Qty" class="num">{{ $x['qty'] }}</td>@endif
        @if($anyMinor)<td data-label="Minor damage" class="num">{{ $x['minor'] !== null ? money($x['minor']).' each' : '—' }}</td>@endif
        <td data-label="Lost or beyond repair" class="num">{!! $x['max'] !== null ? e(money($x['max'])).' each' : '<span class="muted">No fee set</span>' !!}</td>
        @if($showQty)<td data-label="Up to" class="num"><b>{{ $x['max'] !== null ? money($x['max'] * $x['qty']) : '—' }}</b></td>@endif</tr>
    @endforeach
  </tbody></table></div>
  @if($showQty && $withFee)<div class="sum-line strong dmg-total"><span>Most you could be charged<div class="small muted" style="font-weight:400">only if every item were lost or beyond repair</div></span><span>{{ money($total) }}</span></div>@endif
  <p class="small muted mb-0">{{ ($personal ?? false)
      ? 'Repairs cost what the repair costs, never more than the amount above. A normal fault (not working, not misused) is free.'
      : 'Repairs are charged as a share of each item\'s value, set in the shop\'s damage policy, and never more than the amount above.' }}</p>
</div>
