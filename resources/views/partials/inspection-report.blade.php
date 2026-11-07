@php($x = $r->inspection)
@if($x)
<div class="card">
  <div class="row between mb-1"><h3 class="mb-0">Return & damage report</h3><span class="small muted">{{ fmt_datetime($x['at'] ?? null) }}</span></div>
  @if(! empty($x['items']))
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th><th class="num">Returned</th><th class="num">Good</th><th class="num">Minor</th><th class="num">Major / total loss</th><th class="num">Missing</th><th class="num">Charges</th></tr></thead><tbody>
      @foreach($x['items'] as $it)
        <tr><td data-label="Item">{{ inv()->product($it['productId'])?->name }}</td><td data-label="Returned" class="num">{{ $it['returned'] }}</td><td data-label="Good" class="num">{{ $it['good'] }}</td><td data-label="Minor" class="num">{{ $it['minor'] ?? 0 }}</td><td data-label="Major / total" class="num">{{ ($it['major'] ?? 0) + ($it['total'] ?? 0) }}</td><td data-label="Missing" class="num">{{ $it['missing'] ?? 0 }}</td><td data-label="Charges" class="num">{{ money(($it['repairFee'] ?? 0) + ($it['replaceFee'] ?? 0)) }}</td></tr>
      @endforeach
    </tbody></table></div>
  @else
    <p class="muted">All items returned in good condition.</p>
  @endif
  @if(! empty($x['notes']))<p class="mt-1"><b>Notes:</b> {{ $x['notes'] }}</p>@endif
  @if(! empty($x['photos']))<div class="thumbs">@foreach($x['photos'] as $ph)<img src="{{ asset('storage/'.$ph) }}" alt="Evidence photo">@endforeach</div>@endif
  <div class="sum-line total"><span>Total compensation</span><span>{{ money($x['compensation'] ?? 0) }}</span></div>
</div>
@endif
