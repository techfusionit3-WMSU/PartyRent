@extends('layouts.dash', ['title' => 'Reports', 'nav' => 'reports'])

@section('content')
<div class="page-head"><div><h1>Platform reports</h1><p>{{ fmt_range($rg['from'], $rg['to']) }}</p></div>@include('partials.range-picker', ['route' => 'admin.reports', 'csvRoute' => 'admin.reports.csv'])</div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Verified rental volume', 'value' => money_short($gmv), 'sub' => 'net of refunds', 'icon' => 'chart', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Est. platform commission', 'value' => money_short($commission), 'sub' => setting('commissionPct').'% of volume', 'icon' => 'cash', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Requests submitted', 'value' => $inRange->count(), 'sub' => $acceptRate !== null ? $acceptRate.'% accepted' : 'none decided yet', 'icon' => 'list', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'New accounts', 'value' => $newUsers, 'sub' => $newSuppliers.' suppliers approved', 'icon' => 'users', 'color' => 'sky'])
</div>
<div class="grid grid-2">
  <div class="card"><h3>Requests per month</h3>@include('partials.columns', ['rows' => $monthlyReq, 'color' => '#7C3AED', 'label' => 'Requests per month'])</div>
  <div class="card"><h3>Verified rental volume per month</h3>@include('partials.columns', ['rows' => $monthlyGmv, 'money' => true, 'color' => '#0D9488', 'label' => 'Volume per month'])</div>
  <div class="card"><h3>Volume by supplier (period)</h3>@include('partials.bars', ['rows' => $bySupplier, 'money' => true])</div>
  <div class="card"><h3>Requests by event type (period)</h3>@include('partials.bars', ['rows' => $byEvent, 'color' => '#E11D74'])</div>
  <div class="card"><h3>Requests by supplier city (period)</h3>@include('partials.bars', ['rows' => $byCity, 'color' => '#2563EB'])</div>
</div>
@endsection
