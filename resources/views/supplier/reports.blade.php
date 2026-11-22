@extends('layouts.dash', ['title' => 'Business Reports', 'nav' => 'reports'])

@section('content')
<div class="page-head"><div><h1>Business Reports</h1><p>{{ fmt_range($rg['from'], $rg['to']) }} · <b>How the shop is doing</b>: rentals, revenue, best-selling items and stock use. For the money record (each payment, refund and deposit) open Financial Reports in the Cashier module.</p></div>@include('partials.range-picker', ['route' => 'supplier.reports', 'csvRoute' => 'supplier.reports.csv'])</div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Rental income', 'value' => money_short($income), 'sub' => 'verified, net of refunds'.($split['sales'] > 0 ? ' · sales '.money_short($split['sales']) : ''), 'icon' => 'chart', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Requests received', 'value' => $inRange->count(), 'sub' => $inRange->where('status', 'declined')->count().' declined', 'icon' => 'list', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Outstanding balances', 'value' => money_short($outstanding->sum(fn ($r) => $r->fin()['balance'])), 'sub' => $outstanding->count().' rentals', 'icon' => 'cash', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'Damage & loss', 'value' => money_short($dmg->sum(fn ($r) => $r->inspection['compensation'])), 'sub' => $dmg->count().' incidents', 'icon' => 'tool', 'color' => 'red'])
</div>
<div class="grid grid-2">
  <div class="card"><h3>Daily rental transactions</h3>@include('partials.chart', ['kind' => 'bars', 'rows' => $daily, 'opts' => ['color' => '#7C3AED', 'label' => 'Daily requests']])</div>
  <div class="card"><h3>Monthly rental transactions</h3>@include('partials.chart', ['kind' => 'bars', 'rows' => $monthly, 'opts' => ['color' => '#E11D74', 'label' => 'Monthly requests']])</div>
  <div class="card"><h3>Rental income by month</h3>@include('partials.chart', ['kind' => 'line', 'rows' => $incomeByMonth, 'opts' => ['money' => true, 'color' => '#0D9488', 'label' => 'Rental income by month']])</div>
  <div class="card"><h3>Most rented products</h3>@include('partials.chart', ['kind' => 'hbars', 'rows' => $topUnits])</div>
  <div class="card"><h3>Inventory utilization · next 30 days</h3><p class="small muted">Reserved unit-days ÷ usable unit-days</p>@include('partials.chart', ['kind' => 'hbars', 'rows' => $util, 'opts' => ['pct' => true, 'color' => '#7C3AED']])</div>
  <div class="card"><h3>Outstanding balances</h3>
    @if($outstanding->isEmpty())<p class="small muted">No outstanding balances.</p>@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Customer</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
      @foreach($outstanding as $r)<tr><td data-label="Request"><a href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a></td><td data-label="Customer">{{ $r->customer->name }}</td><td data-label="Balance" class="num">{{ money($r->fin()['balance']) }}</td><td data-label="Status">@include('partials.badges', ['pay' => $r->fin()['status']])</td></tr>@endforeach
    </tbody></table></div>@endif
  </div>
  <div class="card"><h3>Damage & loss report</h3>
    @if($dmg->isEmpty())@include('partials.empty', ['emoji' => '🗂️', 'title' => 'Nothing here yet', 'text' => 'No damage recorded.'])@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Items</th><th class="num">Charged</th></tr></thead><tbody>
      @foreach($dmg as $r)<tr><td data-label="Request"><a href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a></td>
        <td data-label="Items"><span class="small">{!! collect($r->inspection['items'] ?? [])->filter(fn ($i) => ($i['repairable'] ?? 0) + ($i['nonRepairable'] ?? 0) + ($i['lost'] ?? 0))->map(fn ($i) => e((inv()->product($i['productId'])?->name ?? 'Item').': '.(($i['repairable'] ?? 0) + ($i['nonRepairable'] ?? 0)).' dmg, '.($i['lost'] ?? 0).' missing'))->implode('<br>') ?: '—' !!}</span></td>
        <td data-label="Charged" class="num">{{ money($r->inspection['compensation']) }}</td></tr>@endforeach
    </tbody></table></div>@endif
  </div>
  <div class="card"><h3>Upcoming reservations</h3>
    @if($upcoming->isEmpty())<p class="small muted">No upcoming reservations.</p>@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Dates</th><th>Request</th><th class="num">Units</th><th class="num">Amount</th></tr></thead><tbody>
      @foreach($upcoming as $r)<tr><td data-label="Dates">{{ fmt_range($r->start_date, $r->end_date) }}</td><td data-label="Request"><a href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a></td><td data-label="Units" class="num">{{ collect($r->lines)->sum(fn ($l) => array_sum((array) ($l['days'] ?? []))) }}</td><td data-label="Amount" class="num">{{ money($r->fin()['payable']) }}</td></tr>@endforeach
    </tbody></table></div>@endif
  </div>
</div>
@endsection
