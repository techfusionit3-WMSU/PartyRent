@extends('layouts.dash', ['title' => 'Rentals & Transactions', 'nav' => 'transactions'])

@section('content')
<div class="page-head"><div><h1>Transactions & reservations</h1><p>Every rental request on the platform (read-only — suppliers and cashiers act on them).</p></div>
  <form method="GET"><select class="input input-sm" name="status" data-autosubmit style="width:auto"><option value="">All statuses</option>@foreach(\App\Models\RentalRequest::STATUS as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v[0] }}</option>@endforeach</select></form></div>
<div class="card pad-0">
  @if($list->isEmpty())@include('partials.empty', ['text' => 'No requests match.'])@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Customer</th><th>Supplier</th><th>Dates</th><th class="num">Total</th><th>Status</th><th>Payment</th></tr></thead><tbody>
    @foreach($list as $r)<tr><td data-label="Request"><b>{{ $r->code }}</b><div class="small muted">{{ $r->event_name }}</div></td><td data-label="Customer">{{ $r->customer->name }}</td><td data-label="Supplier">{{ $r->supplier->name }}</td><td data-label="Dates">{{ fmt_range($r->start_date, $r->end_date) }}</td><td data-label="Total" class="num">{{ money($r->pricing['payable']) }}@if(! empty($r->pricing['customDiscount']))<div class="small" style="color:var(--green)">−{{ money($r->pricing['customDiscount']['amount']) }} special discount</div>@endif</td><td data-label="Status">@include('partials.badges', ['status' => $r->status])</td><td data-label="Payment">@include('partials.badges', ['pay' => $r->fin()['status']])<div class="small muted">bal. {{ money($r->fin()['balance']) }}</div></td></tr>@endforeach
  </tbody></table></div><div style="padding:12px 16px">{{ $list->links('pagination::simple-default') }}</div>@endif
</div>
@endsection
