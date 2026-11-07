<div class="alert alert-ai mt-2">{{ icon('sparkle') }}<div class="grow"><b>Not enough stock — PartyRent Assist found available alternatives:</b>
  @forelse($alts as $a)
    @if(isset($a['pkg']))
      <div class="row between mt-1"><span>📦 <a href="{{ route('packages.show', $a['pkg']) }}">{{ $a['pkg']->name }}</a> · {{ $a['supplier']->name }}<br><span class="small">{{ $a['reason'] }}</span></span><b>{{ money_short($a['pkg']->price) }}</b></div>
    @else
      <div class="row between mt-1"><span><a href="{{ route('products.show', ['product' => $a['product']->id, 'start' => $dates[0] ?? null, 'end' => $dates ? end($dates) : null, 'qty' => $maxQty]) }}">{{ $a['product']->name }}</a> · {{ $a['supplier']->name }}<br><span class="small">{{ $a['reason'] }}</span></span><b>{{ money($a['unit']) }}</b></div>
    @endif
  @empty
    <div class="small mt-1">No similar items are free on these dates. Try other dates or lower quantities.</div>
  @endforelse
  <div class="small mt-1" style="opacity:.8">Suggestions are checked against live availability. You decide what to rent.</div>
</div></div>
