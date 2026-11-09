@extends('layouts.dash', ['title' => 'Business Reports', 'nav' => 'reports'])

@section('content')
<div class="page-head"><div><h1>Business Reports</h1><p>{{ fmt_range($rg['from'], $rg['to']) }}</p></div>@include('partials.range-picker', ['route' => 'supplier.reports', 'csvRoute' => 'supplier.reports.csv'])</div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Total income', 'value' => money_short($income), 'sub' => 'Rental '.money_short($split['rental']).' · Sales '.money_short($split['sales']), 'icon' => 'chart', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Requests received', 'value' => $inRange->count(), 'sub' => $inRange->where('status', 'declined')->count().' declined', 'icon' => 'list', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Outstanding balances', 'value' => money_short($outstanding->sum(fn ($r) => $r->fin()['balance'])), 'sub' => $outstanding->count().' rentals', 'icon' => 'cash', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'Damage & loss', 'value' => money_short($dmg->sum(fn ($r) => $r->inspection['compensation'])), 'sub' => $dmg->count().' incidents', 'icon' => 'tool', 'color' => 'red'])
</div>
<div class="grid grid-2">
  <div class="card"><h3>Daily rental requests</h3>@include('partials.columns', ['rows' => $daily, 'color' => '#7C3AED', 'label' => 'Daily requests'])</div>
  <div class="card"><h3>Monthly rental requests</h3>@include('partials.columns', ['rows' => $monthly, 'color' => '#E11D74', 'label' => 'Monthly requests'])</div>
  <div class="card"><h3>Rental income by month</h3>@include('partials.columns', ['rows' => $incomeByMonth, 'money' => true, 'color' => '#0D9488', 'label' => 'Income by month'])</div>
  <div class="card"><h3>Most rented products</h3>@include('partials.bars', ['rows' => $topUnits])</div>
  <div class="card"><h3>Inventory utilization · next 30 days</h3><p class="small muted">Reserved unit-days ÷ usable unit-days (%)</p>@include('partials.bars', ['rows' => $util, 'color' => '#7C3AED'])</div>
  <div class="card"><h3>Outstanding balances</h3>
    @forelse($outstanding as $r)<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><div><a href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a> · {{ $r->customer->name }}</div><div class="row gap-sm"><b>{{ money($r->fin()['balance']) }}</b>@include('partials.badges', ['pay' => $r->fin()['status']])</div></div>@empty<p class="small muted">No outstanding balances.</p>@endforelse
  </div>
  <div class="card"><h3>Damage & loss report</h3>
    @forelse($dmg as $r)<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><div><a href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a> · {{ $r->customer->name }}</div><b>{{ money($r->inspection['compensation']) }}</b></div>@empty<p class="small muted">No damage recorded.</p>@endforelse
  </div>
  <div class="card"><h3>Upcoming reservations</h3>
    @forelse($upcoming as $r)<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><div>{{ fmt_range($r->start_date, $r->end_date) }} · <a href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a></div>@include('partials.badges', ['status' => $r->status])</div>@empty<p class="small muted">No upcoming reservations.</p>@endforelse
  </div>
</div>
@endsection
