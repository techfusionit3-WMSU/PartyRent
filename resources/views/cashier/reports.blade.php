@extends('layouts.dash', ['title' => 'Financial Reports', 'nav' => 'reports'])

@section('content')
<div class="page-head"><div><h1>Financial reports</h1><p>{{ fmt_range($rg['from'], $rg['to']) }} · verified payments only, by payment date.</p></div>@include('partials.range-picker', ['route' => 'cashier.reports', 'csvRoute' => 'cashier.reports.csv'])</div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Rental payments collected', 'value' => money_short($collected), 'sub' => $count.' verified transactions', 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Rental refunds', 'value' => money_short($refunds), 'sub' => 'net '.money_short($collected - $refunds), 'icon' => 'cash', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Deposits received', 'value' => money_short($depositsIn), 'sub' => money_short($depositsOut).' refunded', 'icon' => 'shield', 'color' => 'sky'])
  @include('partials.stat', ['label' => 'Damage compensation', 'value' => money_short($compensation), 'sub' => 'paid directly', 'icon' => 'tool', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Rejected submissions', 'value' => $rejected, 'icon' => 'x', 'color' => 'red'])
</div>
<div class="grid grid-2">
  <div class="card"><h3>Daily rental collections</h3>@include('partials.columns', ['rows' => $daily, 'money' => true, 'color' => '#0D9488', 'label' => 'Daily collections'])</div>
  <div class="card"><h3>Collections by method</h3>@include('partials.bars', ['rows' => $byMethod, 'money' => true])</div>
  <div class="card"><h3>All verified amounts by type</h3>@include('partials.bars', ['rows' => $byType, 'money' => true, 'color' => '#7C3AED'])</div>
  <div class="card"><h3>Transactions processed by staff</h3>@include('partials.bars', ['rows' => $byCashier, 'color' => '#2563EB'])</div>
</div>
@endsection
