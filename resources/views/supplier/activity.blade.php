@extends('layouts.dash', ['title' => 'Activity Log', 'nav' => 'activity'])

@section('content')
<div class="page-head no-print"><div><h1>Activity Log</h1><p>Every change your team made: accepting requests, confirming payments, inspections, stock and product changes. Each line shows who did it and when.</p></div>
  <button type="button" class="btn btn-sm" onclick="window.print()">{{ icon('print') }}Print</button></div>
<div class="card no-print mb-2">
  @include('partials.range-picker', ['route' => 'supplier.activity'])
  @if($people->count() > 1)
  <form method="GET" action="{{ route('supplier.activity') }}" class="row wrap mt-1" style="gap:6px">
    @foreach(['range', 'from', 'to'] as $k)<input type="hidden" name="{{ $k }}" value="{{ $rg[$k] }}">@endforeach
    <select class="input input-sm" name="who" style="width:auto" aria-label="Person"><option value="">Everyone</option>@foreach($people as $p)<option value="{{ $p->id }}" @selected($who === $p->id)>{{ $p->name }}</option>@endforeach</select>
    <button class="btn btn-sm">Filter</button>
  </form>
  @endif
</div>
<div class="printable">
  <div class="print-only print-head"><b>{{ $sup->name }}</b> · Activity Log · {{ fmt_range($rg['from'], $rg['to']) }}<br><span class="small">Printed {{ fmt_datetime(now()) }} by {{ auth()->user()->name }}</span></div>
  @if($list->isEmpty())
    <div class="card">@include('partials.empty', ['emoji' => '🕘', 'title' => 'Nothing recorded in this period', 'text' => 'Changes your team makes from now on show here with their name and time.'])</div>
  @else
  @php($rmap = \App\Models\RentalRequest::where('supplier_id', $sup->id)->whereIn('code', $list->pluck('ref')->filter()->unique())->get()->keyBy('code'))
  <div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>About</th></tr></thead>
    <tbody>
    @foreach($list as $a)
      <tr><td data-label="When" class="small">{{ fmt_datetime($a->created_at) }}</td><td data-label="Who"><b>{{ $a->actor->name ?? 'System' }}</b><div class="small muted">{{ ucfirst($a->role) }}</div></td><td data-label="What">{{ $a->action }}</td>
        <td data-label="About">@if($a->ref && ($rr = $rmap[$a->ref] ?? null))<a href="{{ route('supplier.requests.show', $rr) }}">{{ $a->ref }}</a>@else{{ $a->ref ?: '—' }}@endif</td></tr>
    @endforeach
    </tbody>
  </table></div></div>
  <div class="mt-2 no-print">{{ $list->links() }}</div>
  @endif
</div>
@endsection
