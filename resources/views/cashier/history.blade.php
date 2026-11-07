@extends('layouts.dash', ['title' => 'Payment History & Receipts', 'nav' => 'history'])

@section('content')
<div class="page-head"><div><h1>Payment history & receipts</h1><p>Every payment, refund and receipt on the platform.</p></div>
  <form method="GET" class="row wrap" style="gap:6px"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="TXN, OR, reference or PR no." style="width:240px"><select class="input input-sm" name="status" style="width:auto" data-autosubmit><option value="">All statuses</option>@foreach(['pending', 'verified', 'rejected'] as $s)<option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>@endforeach</select><button class="btn btn-sm">Search</button></form>
</div>
<div class="card pad-0">
  @if($list->isEmpty())
    @include('partials.empty', ['text' => 'No payments match.'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Date</th><th>Transaction</th><th>Customer</th><th>Type</th><th>Method</th><th class="num">Amount</th><th>Status</th><th>Receipt</th></tr></thead><tbody>
      @foreach($list as $p)
        <tr><td data-label="Date">{{ fmt_date($p->date) }}</td><td data-label="Transaction"><b>{{ $p->code }}</b><div class="small muted">{{ $p->rentalRequest->code }} · {{ $p->reference }}</div></td><td data-label="Customer">{{ $p->rentalRequest->customer->name }}</td><td data-label="Type">{{ $p->typeLabel() }}</td><td data-label="Method">{{ $p->methodLabel() }}</td><td data-label="Amount" class="num"><b>{{ $p->isOutflow() ? '− ' : '' }}{{ money($p->amount) }}</b></td><td data-label="Status">@include('partials.badges', ['ver' => $p->status])@if($p->verifier)<div class="small muted">{{ $p->verifier->name }}</div>@endif</td><td data-label="Receipt">@if($p->receipt_no)<a class="btn btn-xs" target="_blank" href="{{ route('payments.receipt', $p) }}">{{ $p->receipt_no }}</a>@else<span class="small muted">—</span>@endif</td></tr>
      @endforeach
    </tbody></table></div>
    <div style="padding:12px 16px">{{ $list->links('pagination::simple-default') }}</div>
  @endif
</div>
@endsection
