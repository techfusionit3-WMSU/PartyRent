{{-- Reference Shared.inspectionReport: confirmed return & damage report (compensation per damage policy). --}}
@php($x = $r->inspection)
@if($x)
@php($affected = collect($x['items'] ?? [])->filter(fn ($it) => ($it['repairable'] ?? 0) || ($it['nonRepairable'] ?? 0) || ($it['lost'] ?? 0) || ($it['returned'] ?? 0) !== ($it['rented'] ?? 0)))
<div class="card">
  <div class="row between mb-1"><h3 class="mb-0">Return & damage report</h3><span class="small muted">Confirmed {{ fmt_datetime($x['confirmedAt'] ?? $x['at'] ?? null) }}</span></div>
  @forelse($affected as $it)
    @php($parts = array_values(array_filter([
        ($it['repairable'] ?? 0) ? ['Repairable Damage', $it['repairable'], $it['pcts']['repairable'] ?? 0, $it['amounts']['repairable'] ?? 0] : null,
        ($it['nonRepairable'] ?? 0) ? ['Non-Repairable Damage', $it['nonRepairable'], $it['pcts']['nonRepairable'] ?? 0, $it['amounts']['nonRepairable'] ?? 0] : null,
        ($it['lost'] ?? 0) ? ['Lost or Not Returned', $it['lost'], $it['pcts']['lost'] ?? 0, $it['amounts']['lost'] ?? 0] : null,
    ])))
    <div class="card card-flat mb-1" style="padding:14px">
      <div class="row between"><b>{{ inv()->product($it['productId'])?->name }}</b><b>{{ money($it['total'] ?? 0) }}</b></div>
      <div class="small muted">Rented {{ $it['rented'] ?? 0 }} · returned {{ $it['returned'] ?? 0 }} · condition before rental: {{ \App\Domain\Damage::conditionLabel($it['condition'] ?? 'used') }} · product value {{ money($it['value'] ?? 0) }}</div>
      @if($parts)
        <div class="table-wrap mt-1"><table class="table"><thead><tr><th>Category</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Compensation</th></tr></thead><tbody>
          @foreach($parts as [$l, $q, $pc, $amt])
            <tr><td>{{ $l }}</td><td class="num">{{ $q }}</td><td class="num">{{ +$pc }}%</td><td class="num">{{ money($amt) }}<div class="small muted">{{ money($it['value'] ?? 0) }} × {{ +$pc }}% × {{ $q }}</div></td></tr>
          @endforeach
        </tbody></table></div>
      @endif
      @if(! empty($it['description']))<p class="small mb-0 mt-1"><b>Assessment:</b> {{ $it['description'] }}</p>@endif
    </div>
  @empty
    <p class="muted">All items returned in good condition.</p>
  @endforelse
  @if(! empty($x['notes']))<p class="mt-1"><b>Notes:</b> {{ $x['notes'] }}</p>@endif
  @if(! empty($x['photos']))<div class="label mt-1">Photo evidence</div><div class="thumbs">@foreach($x['photos'] as $ph)<img src="{{ asset('storage/'.$ph) }}" alt="Evidence photo">@endforeach</div>@endif
  <div class="sum-line total"><span>Total compensation</span><span>{{ money($x['compensation'] ?? 0) }}</span></div>
  <p class="small muted mb-0">Compensation = product value × compensation % × affected quantity. It is recorded separately from the rental fee.</p>
</div>
@endif
