@extends('layouts.dash', ['title' => 'Transaction Records', 'nav' => 'records'])

@section('content')
<div class="page-head no-print"><div><h1>Transaction Records</h1><p>Every rental with dates in the period, by month: money in, what is still owed and who processed it. Print it or download it for your files.</p></div>
  <div class="row wrap" style="gap:6px"><button type="button" class="btn btn-sm" onclick="window.print()">{{ icon('print') }}Print</button><a class="btn btn-sm btn-outline" href="{{ route('supplier.records', array_merge(request()->query(), ['csv' => 1])) }}">{{ icon('file') }}Download CSV</a></div></div>
<div class="card no-print mb-2">
  @include('partials.range-picker', ['route' => 'supplier.records'])
  <form method="GET" action="{{ route('supplier.records') }}" class="row wrap mt-1" style="gap:6px">
    @foreach(['range', 'from', 'to'] as $k)<input type="hidden" name="{{ $k }}" value="{{ $rg[$k] }}">@endforeach
    <select class="input input-sm" name="status" style="width:auto" aria-label="Status"><option value="">All statuses</option>@foreach(\App\Models\RentalRequest::STATUS as $k => $s)<option value="{{ $k }}" @selected($status === $k)>{{ $s[0] }}</option>@endforeach</select>
    <input class="input input-sm" name="q" value="{{ $q }}" placeholder="Code, event or customer" style="width:220px" aria-label="Search">
    <button class="btn btn-sm">Filter</button>
  </form>
</div>

<div class="printable">
  <div class="print-only print-head"><b>{{ $sup->name }}</b> · Transaction Records · {{ fmt_range($rg['from'], $rg['to']) }}@if($status) · {{ \App\Models\RentalRequest::statusLabel($status) }}@endif<br><span class="small">Printed {{ fmt_datetime(now()) }} by {{ auth()->user()->name }}</span></div>
  <div class="grid grid-4 mb-2 rec-totals">
    <div class="card stat"><div class="small muted">Rentals</div><b>{{ $totals['count'] }}</b></div>
    <div class="card stat"><div class="small muted">Rental charges</div><b>{{ money($totals['total']) }}</b></div>
    <div class="card stat"><div class="small muted">Paid</div><b>{{ money($totals['paid']) }}</b></div>
    <div class="card stat"><div class="small muted">Still owed</div><b>{{ money($totals['balance']) }}</b></div>
  </div>
  <p class="small muted">Declined and cancelled rentals are listed but not counted in the money totals.</p>
  @forelse($groups as $ym => $rows)
    <div class="card mb-2 rec-month">
      <div class="row between wrap"><h3 class="mb-1">{{ \Carbon\Carbon::parse($ym.'-01')->format('F Y') }}</h3><span class="small muted">{{ $rows->count() }} rental{{ $rows->count() === 1 ? '' : 's' }}</span></div>
      <div class="table-wrap"><table class="table rec-table">
        <thead><tr><th>Rental</th><th>Customer</th><th>Dates</th><th>Status</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Owed</th><th>Processed by</th></tr></thead>
        <tbody>
        @foreach($rows as $x)
          @php($r = $x['r'])
          <tr>
            <td data-label="Rental"><a href="{{ route('supplier.requests.show', $r) }}"><b>{{ $r->code }}</b></a><div class="small muted">{{ $r->event_name }}</div></td>
            <td data-label="Customer">{{ $r->customer->name }}</td>
            <td data-label="Dates" class="small">{{ $r->start_date === $r->end_date ? fmt_date($r->start_date) : fmt_range($r->start_date, $r->end_date) }}</td>
            <td data-label="Status">@include('partials.badges', ['status' => $r->status])<div class="small muted">{{ $x['pay'] }}</div></td>
            <td data-label="Total" class="num">{{ money($x['total']) }}</td>
            <td data-label="Paid" class="num">{{ money($x['paid']) }}</td>
            <td data-label="Owed" class="num">{{ $x['balance'] > 0.009 ? money($x['balance']) : '—' }}</td>
            <td data-label="Processed by" class="small">@if($x['acceptedBy'])Accepted by <b>{{ $x['acceptedBy'] }}</b> · {{ fmt_datetime($x['acceptedAt']) }}<br>@endif @if($x['lastBy'])<span class="muted">Last: {{ $x['lastWhat'] }} · {{ $x['lastBy'] }} · {{ fmt_datetime($x['lastAt']) }}</span>@endif</td>
          </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="4"><b>{{ \Carbon\Carbon::parse($ym.'-01')->format('F') }} total</b></td>@php($live = $rows->reject(fn ($x) => in_array($x['r']->status, ['declined', 'cancelled'], true)))<td class="num"><b>{{ money($live->sum('total')) }}</b></td><td class="num"><b>{{ money($live->sum('paid')) }}</b></td><td class="num"><b>{{ money($live->sum('balance')) }}</b></td><td></td></tr></tfoot>
      </table></div>
    </div>
  @empty
    <div class="card">@include('partials.empty', ['emoji' => '🗂️', 'title' => 'No rentals in this period', 'text' => 'Pick another period above, or clear the filters.'])</div>
  @endforelse
</div>
@endsection
