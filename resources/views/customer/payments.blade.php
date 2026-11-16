@extends('layouts.market', ['title' => 'Payment History', 'nav' => 'rentals'])

@section('content')
<div class="page-head"><div><h1>Payment History</h1><p>All payments, receipts, deposits and refunds.</p></div></div>
<div class="stats mb-3">
  @include('partials.stat', ['label' => 'Total paid (verified)', 'value' => money_short($paid), 'sub' => 'Rental payments', 'icon' => 'check', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Outstanding balance', 'value' => money_short($owed), 'sub' => $activeCount.' active rentals', 'icon' => 'clock', 'color' => 'pink'])
  @include('partials.stat', ['label' => 'Pending verification', 'value' => $all->where('status', 'pending')->count(), 'sub' => money_short($all->where('status', 'pending')->sum('amount')), 'icon' => 'alert', 'color' => 'gold'])
  @include('partials.stat', ['label' => 'Deposits held', 'value' => money_short($held), 'sub' => 'Refundable', 'icon' => 'shield', 'color' => 'purple'])
</div>
<div class="card">
  <div class="tabs">@foreach([['all', 'All', $all->count()], ['pending', 'Pending', $all->where('status', 'pending')->count()], ['verified', 'Verified', null], ['rejected', 'Rejected', null]] as [$k, $l, $c])<a class="tab {{ $f === $k ? 'active' : '' }}" href="{{ route('customer.payments', ['f' => $k]) }}">{{ $l }}@if($c !== null)<span class="count">{{ $c }}</span>@endif</a>@endforeach</div>
  @if($pays->isEmpty())
    @include('partials.empty', ['title' => 'Nothing here yet', 'text' => 'No payments yet.'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Date</th><th>Transaction</th><th>Type</th><th>Method</th><th class="num">Amount</th><th>Status</th><th>Receipt</th></tr></thead><tbody>
      @foreach($pays as $p)
        <tr><td data-label="Date">{{ fmt_date($p->date) }}</td><td data-label="Transaction"><b>{{ $p->code }}</b><div class="small muted"><a href="{{ route('rentals.show', $p->rentalRequest) }}">{{ $p->rentalRequest->code }}</a></div></td><td data-label="Type">{{ $p->typeLabel() }}</td><td data-label="Method">{{ $p->methodLabel() }}</td><td data-label="Amount" class="num"><b>{{ money($p->amount) }}</b></td><td data-label="Status">@include('partials.badges', ['ver' => $p->status])</td><td data-label="Receipt">@if($p->receipt_no)<a class="btn btn-xs" href="{{ route('payments.receipt', $p) }}" target="_blank">{{ $p->receipt_no }}</a>@else<a class="btn btn-xs btn-ghost" href="{{ route('rentals.show', $p->rentalRequest) }}">Details</a>@endif</td></tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>
@endsection
