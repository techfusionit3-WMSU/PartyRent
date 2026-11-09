{{-- Lines of a quote / price snapshot. $pricing = quote array. Names and package contents come from the
     snapshot when present, so a booked request keeps showing what was booked. --}}
@foreach($pricing['lines'] as $l)
  @php($item = $l['type'] === 'package' ? inv()->package($l['refId']) : inv()->product($l['refId']))
  @php($name = $l['name'] ?? $item?->name)
  @continue(! $name)
  @php($kind = $l['kind'] ?? ($l['type'] === 'package' ? 'package' : 'rent'))
  <div class="card card-flat mb-1" style="padding:14px">
    <div class="row mb-1">@if($item)@include('partials.thumb', ['item' => $item, 'cls' => 'sm'])@endif<div class="grow"><b>{{ $name }}</b>
      @if($kind === 'package')<span class="badge b-pink plain">Package</span>@elseif($kind === 'sale')<span class="badge b-gold plain">For sale · not returned</span>@endif
      @if($kind === 'package')
        @if(! empty($l['summary']))@foreach($l['summary'] as $s)<div class="small muted">{{ $s }}</div>@endforeach
        @elseif($item)<div class="small muted">{{ collect($item->items)->map(fn ($it) => $it['qty'].'× '.(inv()->product($it['product_id'])?->name ?? ''))->implode(', ') }}</div>@endif
      @endif
      @if($kind === 'sale' && ! empty($l['dates']))<div class="small muted">Needed on {{ collect($l['dates'])->map(fn ($d) => fmt_date($d))->implode(', ') }}</div>@endif
    </div><b>{{ money($l['total']) }}</b></div>
    @if($l['days'])
      <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th class="num">Qty</th><th class="num">{{ $kind === 'package' ? 'Rental part / day' : 'Daily rate' }}</th><th class="num">Subtotal</th></tr></thead><tbody>
        @foreach($l['days'] as $d)<tr><td>{{ fmt_day($d['date']) }}</td><td class="num">{{ $d['qty'] }}</td><td class="num">{{ money($d['unit']) }}</td><td class="num">{{ money($d['subtotal']) }}</td></tr>@endforeach
      </tbody></table></div>
    @endif
    @foreach($l['once'] ?? [] as $o)
      <div class="sum-line small"><span>{{ $o['label'] }} <span class="muted">· charged once</span></span><span>{{ $o['qty'] }} × {{ money($o['unit']) }}{{ $kind === 'sale' ? ' / '.($l['unitWord'] ?? 'unit') : '' }} = <b>{{ money($o['subtotal']) }}</b></span></div>
    @endforeach
  </div>
@endforeach
