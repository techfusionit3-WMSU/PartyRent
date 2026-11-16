{{-- Lines of a request / quote (reference Shared.linesTable). $pricing = price snapshot; optional $r (request) and
     $showSource (supplier view). Names and package contents come from the snapshot, so a booked request keeps
     showing what was booked. With several delivery locations the lines are grouped per location. --}}
@php
    $r ??= null;
    $showSource ??= false;
    $dests = $r?->destinations ?? [];
    $locNames = $r ? $r->supplier->locations->pluck('name', 'id') : collect();
    $groups = count($dests) > 1
        ? collect($dests)->map(fn ($d, $i) => ['d' => $d, 'i' => $i, 'lines' => array_values(array_filter($pricing['lines'], fn ($l) => ($l['destId'] ?? null) === $d['id']))])->all()
        : [['d' => null, 'i' => 0, 'lines' => $pricing['lines']]];
@endphp
@foreach($groups as $g)
  @if($g['d'])<div class="mb-2"><div class="row between mb-1"><div><span class="badge b-purple">Location {{ $g['i'] + 1 }}</span> <b>{{ $g['d']['label'] }}</b><div class="small muted">{{ $g['d']['address'] }}{{ ! empty($g['d']['deliveryTime']) ? ' · deliver by '.\App\Domain\Slots::fmt12($g['d']['deliveryTime']) : '' }}</div></div></div>@endif
  @foreach($g['lines'] as $l)
    @php($item = $l['type'] === 'package' ? inv()->package($l['refId']) : inv()->product($l['refId']))
    @php($name = $l['name'] ?? $item?->name)
    @continue(! $name)
    @php($kind = $l['kind'] ?? ($l['type'] === 'package' ? 'package' : 'rent'))
    @php($src = $r ? collect($r->lines)->firstWhere('id', $l['lineId'] ?? null) : null)
    @php($sline = ['days' => collect($l['days'])->pluck('qty', 'date')->all(), 'time' => $l['time'] ?? ($src['time'] ?? null)])
    <div class="card card-flat mb-1" style="padding:14px">
      <div class="row mb-1">@if($item)@include('partials.thumb', ['item' => $item, 'cls' => 'sm'])@endif<div class="grow" style="min-width:0"><b>{{ $name }}</b>
        @if($kind === 'package')<span class="badge b-pink plain">Package</span>@elseif($kind === 'sale')<span class="badge b-gold plain">For sale · not returned</span>@endif
        @if($kind === 'package')
          @if(! empty($l['summary']))@foreach($l['summary'] as $s)<div class="small muted">{{ $s }}</div>@endforeach
          @elseif($item)<div class="small muted">{{ collect($item->items)->map(fn ($it) => $it['qty'].'× '.(inv()->product($it['product_id'])?->name ?? ''))->implode(', ') }}</div>@endif
        @endif
        @if($kind === 'sale' && ! empty($l['dates']))<div class="small muted">Needed on {{ collect($l['dates'])->map(fn ($d) => fmt_date($d))->implode(', ') }}</div>@endif
        @if($kind !== 'sale')<div class="small">{{ icon('clock', 'width:13px;vertical-align:-2px') }} {{ \App\Domain\Slots::time($sline) ? \App\Domain\Slots::rangeLabel(\App\Domain\Slots::time($sline)).' · '.\App\Domain\Slots::duration($sline)['label'] : 'All day' }}</div>@endif
        @if($showSource && ! empty($src['sourceId']))<div class="small muted">{{ icon('box', 'width:13px;vertical-align:-2px') }} Inventory source: <b>{{ $locNames[$src['sourceId']] ?? '—' }}</b></div>@endif
      </div><b>{{ money($l['total']) }}</b></div>
      @if($l['days'])
        <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th class="num">Qty</th><th class="num">{{ $kind === 'package' ? 'Rental part / day' : 'Daily rate' }}</th><th class="num">Subtotal</th></tr></thead><tbody>
          @foreach($l['days'] as $d)<tr><td>{{ fmt_day($d['date']) }}</td><td class="num">{{ $d['qty'] }}</td><td class="num">{{ money($d['unit']) }}</td><td class="num">{{ money($d['subtotal']) }}</td></tr>@endforeach
        </tbody></table></div>
      @endif
      @foreach($l['once'] ?? [] as $o)
        <div class="sum-line small"><span>{{ $o['label'] }} <span class="muted">· charged once</span></span><span>{{ $o['qty'] }} × {{ money($o['unit']) }}{{ $kind === 'sale' ? ' / '.($l['unitWord'] ?? 'unit') : '' }} = <b>{{ money($o['subtotal']) }}</b></span></div>
      @endforeach
      @if(! empty($l['discount']))<div class="sum-line small" style="color:var(--green)"><span>Shop discount – {{ $l['discount']['name'] }} <span class="muted">· {{ $l['discount']['label'] }}</span></span><span>−{{ money($l['discount']['amount']) }}</span></div>@endif
    </div>
  @endforeach
  @if($g['d'])</div>@endif
@endforeach
