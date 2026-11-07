{{-- Daily schedule of a quote's lines. $pricing = quote array --}}
@foreach($pricing['lines'] as $l)
  @php($item = $l['type'] === 'package' ? inv()->package($l['refId']) : inv()->product($l['refId']))
  @continue(! $item)
  <div class="card card-flat mb-1" style="padding:14px">
    <div class="row mb-1">@include('partials.thumb', ['item' => $item, 'cls' => 'sm'])<div class="grow"><b>{{ $item->name }}</b>
      @if($l['type'] === 'package')<span class="badge b-pink plain">Package</span><div class="small muted">{{ collect($item->items)->map(fn ($it) => $it['qty'].'× '.(inv()->product($it['product_id'])?->name ?? ''))->implode(', ') }}</div>@endif
    </div><b>{{ money($l['total']) }}</b></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th class="num">Qty</th><th class="num">Daily rate</th><th class="num">Subtotal</th></tr></thead><tbody>
      @foreach($l['days'] as $d)<tr><td>{{ fmt_day($d['date']) }}</td><td class="num">{{ $d['qty'] }}</td><td class="num">{{ money($d['unit']) }}</td><td class="num">{{ money($d['subtotal']) }}</td></tr>@endforeach
    </tbody></table></div>
  </div>
@endforeach
