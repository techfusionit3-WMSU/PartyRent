@extends('layouts.dash', ['title' => 'Damage & Compensation', 'nav' => 'damages'])

{{-- Reference SP.damages --}}
@php
    $n = fn ($r, $k) => collect($r->inspection['items'] ?? [])->sum(fn ($i) => $i[$k] ?? 0);
    $withDmg = $list->filter(fn ($r) => ($r->inspection['compensation'] ?? 0) > 0 || $n($r, 'repairable') + $n($r, 'nonRepairable') + $n($r, 'lost') > 0);
    $pol = \App\Domain\Damage::policy(auth()->user()->shop());
@endphp
@section('content')
<div class="page-head"><div><h1>Damage & Compensation</h1><p>Confirmed return inspections, evidence and settlement status. Compensation is recorded separately from rental income.</p></div>
  @if(auth()->user()->orgCan('shop.manage'))<a class="btn btn-sm" href="{{ route('supplier.shop', ['tab' => 'damage']) }}">{{ icon('settings') }}Damage policy</a>@endif
</div>
<div class="card mb-2"><b>Your current policy</b>
  <div class="row wrap gap-sm mt-1">
    <span class="badge b-gray">Repairable: {{ $pol['repairable']['enabled'] ? +$pol['repairable']['pct'].'%' : 'off' }}</span>
    <span class="badge b-gray">Non-repairable: {{ $pol['nonRepairable']['enabled'] ? +$pol['nonRepairable']['pct'].'%' : 'off' }}</span>
    <span class="badge b-gray">Lost — Brand New {{ +$pol['lost']['brand_new'] }}% · Good as New {{ +$pol['lost']['good_as_new'] }}% · Used {{ +$pol['lost']['used'] }}%{{ $pol['lost']['enabled'] ? '' : ' (off)' }}</span>
    @foreach(array_keys($pol['categories']) as $c)<span class="badge b-purple plain">{{ \App\Models\Category::find($c)?->name ?? $c }} rule</span>@endforeach
  </div>
  <p class="small muted mb-0 mt-1">Compensation = product value × compensation % × affected quantity.</p>
</div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Inspections', 'value' => $list->count(), 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'With damage / loss', 'value' => $withDmg->count(), 'icon' => 'tool', 'color' => 'red'])
  @include('partials.stat', ['label' => 'Compensation assessed', 'value' => money_short($list->sum(fn ($r) => $r->inspection['compensation'] ?? 0)), 'icon' => 'cash', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Pending settlement', 'value' => $list->filter(fn ($r) => ($r->inspection['settlement'] ?? '') === 'pending')->count(), 'sub' => money_short($list->sum(fn ($r) => $r->fin()['compensationDue'])).' due', 'icon' => 'clock', 'color' => 'gold'])
</div>
<div class="card pad-0">
  @if($list->isEmpty())
    @include('partials.empty', ['emoji' => '🧰', 'title' => 'No inspections recorded yet.', 'text' => ''])
  @else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Confirmed</th><th class="num">Repairable</th><th class="num">Non-repairable</th><th class="num">Lost</th><th class="num">Compensation</th><th>Settlement</th><th>Evidence</th><th></th></tr></thead><tbody>
    @foreach($list as $r)
      @php($st = $r->inspection['settlement'] ?? 'settled')
      @php($ph = count($r->inspection['photos'] ?? []))
      <tr>
        <td data-label="Request"><a class="strong" href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a><div class="small muted">{{ $r->customer->name }}</div></td>
        <td data-label="Confirmed">{{ fmt_date(substr($r->inspection['confirmedAt'] ?? $r->inspection['at'] ?? '', 0, 10)) }}</td>
        <td data-label="Repairable" class="num">{{ $n($r, 'repairable') }}</td>
        <td data-label="Non-repairable" class="num">{{ $n($r, 'nonRepairable') }}</td>
        <td data-label="Lost" class="num">{{ $n($r, 'lost') }}</td>
        <td data-label="Compensation" class="num">{{ money($r->inspection['compensation'] ?? 0) }}</td>
        <td data-label="Settlement"><span class="badge {{ $st === 'pending' ? 'b-red' : 'b-green' }}">{{ ucfirst($st) }}</span></td>
        <td data-label="Evidence">{{ $ph ? $ph.' photo(s)' : '—' }}</td>
        <td><a class="btn btn-xs" href="{{ route('supplier.requests.show', $r) }}">Open</a></td>
      </tr>
    @endforeach
  </tbody></table></div>
  @endif
</div>
@endsection
