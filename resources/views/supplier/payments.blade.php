@extends('layouts.dash', ['title' => 'Payments', 'nav' => 'payments'])

@section('content')
<div class="page-head"><div><h1>Payments</h1><p>Every payment for your rentals. Only cashier-verified payments count toward balances.</p></div></div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Rental income (verified)', 'value' => money_short($income), 'sub' => 'net of refunds', 'icon' => 'chart', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Outstanding balances', 'value' => money_short($outstanding), 'sub' => 'active rentals', 'icon' => 'clock', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'Pending verification', 'value' => $all->where('status', 'pending')->count(), 'sub' => money_short($all->where('status', 'pending')->sum('amount')), 'icon' => 'alert', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Deposits held', 'value' => money_short($depositsHeld), 'sub' => 'refundable, not income', 'icon' => 'shield', 'color' => 'teal'])
</div>
<div class="tabs">@foreach([['all', 'All'], ['pending', 'Pending'], ['verified', 'Verified'], ['rejected', 'Rejected'], ['overdue', 'Overdue rentals']] as [$k, $l])<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('supplier.payments', ['tab' => $k]) }}">{{ $l }}@if($k === 'overdue')<span class="count">{{ $overdue->count() }}</span>@endif</a>@endforeach</div>
<div class="card pad-0">
  @if($tab === 'overdue')
    @if($overdue->isEmpty())@include('partials.empty', ['emoji' => '✅', 'title' => 'No overdue payments', 'text' => 'All active rentals are on schedule.'])@else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Customer</th><th class="num">Overdue</th><th class="num">Balance</th><th>Next due</th></tr></thead><tbody>
      @foreach($overdue as $r)@php($f = $r->fin())<tr><td data-label="Request"><a class="strong" href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a><div class="small muted">{{ $r->event_name }}</div></td><td data-label="Customer">{{ $r->customer->name }}</td><td data-label="Overdue" class="num" style="color:var(--red)"><b>{{ money($f['overdueAmount']) }}</b></td><td data-label="Balance" class="num">{{ money($f['balance']) }}</td><td data-label="Next due">{{ $f['next'] ? $f['next']['label'].' · '.fmt_date($f['next']['due']) : '—' }}</td></tr>@endforeach
    </tbody></table></div>@endif
  @elseif($pays->isEmpty())
    @include('partials.empty', ['text' => 'No payments in this tab.'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Date</th><th>Transaction</th><th>Customer</th><th>Type</th><th>Method</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
      @foreach($pays as $p)<tr><td data-label="Date">{{ fmt_date($p->date) }}</td><td data-label="Transaction"><b>{{ $p->code }}</b><div class="small muted"><a href="{{ route('supplier.requests.show', $p->rentalRequest) }}">{{ $p->rentalRequest->code }}</a></div></td><td data-label="Customer">{{ $p->rentalRequest->customer->name }}</td><td data-label="Type">{{ $p->typeLabel() }}</td><td data-label="Method">{{ $p->methodLabel() }}</td><td data-label="Amount" class="num"><b>{{ $p->isOutflow() ? '− ' : '' }}{{ money($p->amount) }}</b></td><td data-label="Status">@include('partials.badges', ['ver' => $p->status])</td><td>@if($p->receipt_no)<a class="btn btn-xs" target="_blank" href="{{ route('payments.receipt', $p) }}">{{ $p->receipt_no }}</a>@endif</td></tr>@endforeach
    </tbody></table></div>
  @endif
</div>
@endsection
