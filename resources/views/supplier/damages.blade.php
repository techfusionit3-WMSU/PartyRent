@extends('layouts.dash', ['title' => 'Damage & Compensation', 'nav' => 'damages'])

@php
    $withDmg = $list->filter(fn ($r) => ($r->inspection['compensation'] ?? 0) > 0 || collect($r->inspection['items'] ?? [])->contains(fn ($i) => ($i['minor'] ?? 0) + ($i['major'] ?? 0) + ($i['total'] ?? 0) + ($i['missing'] ?? 0) > 0));
@endphp
@section('content')
<div class="page-head"><div><h1>Damage & Compensation</h1><p>Inspection results, evidence and settlement status. Compensation is tracked separately from rental income.</p></div></div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Inspections', 'value' => $list->count(), 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'With damage / loss', 'value' => $withDmg->count(), 'icon' => 'tool', 'color' => 'red'])
  @include('partials.stat', ['label' => 'Compensation assessed', 'value' => money_short($list->sum(fn ($r) => $r->inspection['compensation'] ?? 0)), 'icon' => 'cash', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Pending settlement', 'value' => $list->filter(fn ($r) => ($r->inspection['settlement'] ?? '') === 'pending')->count(), 'sub' => money_short($list->sum(fn ($r) => $r->fin()['compensationDue'])).' due', 'icon' => 'clock', 'color' => 'gold'])
</div>
<div class="card pad-0">
  @if($list->isEmpty())
    @include('partials.empty', ['emoji' => '🧰', 'title' => 'No inspections yet', 'text' => 'Return inspections you record will appear here.'])
  @else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Inspected</th><th>Items</th><th class="num">Damaged</th><th class="num">Missing</th><th class="num">Compensation</th><th>Settlement</th><th class="num">Still due</th></tr></thead><tbody>
    @foreach($list as $r)
      @php($items = collect($r->inspection['items'] ?? []))
      @php($st = $r->inspection['settlement'] ?? 'settled')
      <tr>
        <td data-label="Request"><a class="strong" href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a><div class="small muted">{{ $r->customer->name }}</div></td>
        <td data-label="Inspected">{{ fmt_date($r->inspection['at'] ?? null) }}</td>
        <td data-label="Items"><span class="small">{!! $items->filter(fn ($i) => ($i['minor'] ?? 0) + ($i['major'] ?? 0) + ($i['total'] ?? 0) + ($i['missing'] ?? 0))->map(fn ($i) => e((inv()->product($i['productId'])?->name ?? 'Item').': '.(($i['minor'] ?? 0) + ($i['major'] ?? 0) + ($i['total'] ?? 0)).' damaged, '.($i['missing'] ?? 0).' missing'))->implode('<br>') ?: '<span class="muted">All good</span>' !!}</span></td>
        <td data-label="Damaged" class="num">{{ $items->sum(fn ($i) => ($i['minor'] ?? 0) + ($i['major'] ?? 0) + ($i['total'] ?? 0)) }}</td>
        <td data-label="Missing" class="num">{{ $items->sum(fn ($i) => $i['missing'] ?? 0) }}</td>
        <td data-label="Compensation" class="num">{{ money($r->inspection['compensation'] ?? 0) }}</td>
        <td data-label="Settlement"><span class="badge {{ $st === 'pending' ? 'b-red' : ($st === 'waived' ? 'b-gray' : 'b-green') }}">{{ ucfirst($st) }}</span></td>
        <td data-label="Still due" class="num"><b>{{ money($r->fin()['compensationDue']) }}</b></td>
      </tr>
    @endforeach
  </tbody></table></div>
  @endif
</div>
@endsection
