@extends('layouts.dash', ['title' => 'Event Payments', 'nav' => 'payments'])

@section('content')
@php($verify = auth()->user()->orgCan('payments.verify'))
<div class="page-head"><div><h1>Event Payments</h1><p>Customers pay each step of the schedule to your account ({{ $s->payout_bank ?: ($s->payout_method === 'gcash' ? 'GCash' : 'your payout account') }}) and send proof. Confirm a payment once the money is in.</p></div></div>

<div class="card mb-3"><div class="card-head"><h3>To confirm <span class="badge b-sky">{{ $pending->count() }}</span></h3></div>
  @if($pending->isEmpty())<p class="muted mb-0">No payment proofs waiting.</p>@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Booking</th><th>Payment</th><th>Amount</th><th>Sent</th><th></th></tr></thead><tbody>
  @foreach($pending as $p)
    <tr><td data-label="Booking"><a href="{{ route('business.bookings.show', $p->booking) }}">{{ $p->booking->code }}</a><div class="small muted">{{ $p->booking->customer->name }} · {{ $p->booking->event_date->format('M j') }}</div></td>
      <td data-label="Payment">{{ $p->label }}<div class="small muted">{{ \App\Models\EventPayment::METHODS[$p->method] ?? $p->method }} · ref {{ $p->reference }}@if($p->proof_path) · <a href="{{ route('business.payments.proof', $p) }}" target="_blank" rel="noopener">proof</a>@endif</div></td>
      <td data-label="Amount"><b>{{ money($p->amount) }}</b></td>
      <td data-label="Sent">{{ fmt_datetime($p->submitted_at) }}</td>
      <td data-label="">@if($verify)@include('business._confirm', ['p' => $p])@endif</td></tr>
  @endforeach
  </tbody></table></div>@endif
</div>

<div class="card mb-3"><div class="card-head"><h3>Coming due</h3></div>
  @if($due->isEmpty())<p class="muted mb-0">Nothing due.</p>@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Booking</th><th>Payment</th><th>Amount</th><th>Due</th><th></th></tr></thead><tbody>
  @foreach($due as $p)
    <tr><td data-label="Booking"><a href="{{ route('business.bookings.show', $p->booking) }}">{{ $p->booking->code }}</a><div class="small muted">{{ $p->booking->customer->name }}</div></td>
      <td data-label="Payment">{{ $p->label }}</td><td data-label="Amount">{{ money($p->amount) }}</td>
      <td data-label="Due"><span class="badge {{ $p->statusBadge() }}">{{ $p->isOverdue() ? 'Overdue' : '' }} {{ $p->due_date->format('M j, Y') }}</span></td>
      <td data-label=""><form method="POST" action="{{ route('business.payments.remind', $p) }}">@csrf<button class="btn btn-xs">{{ icon('bell') }}Remind</button></form></td></tr>
  @endforeach
  </tbody></table></div>@endif
</div>

<div class="card"><div class="card-head"><h3>Received</h3><span class="small muted">{{ money($paid->sum('amount')) }}</span></div>
  @if($paid->isEmpty())<p class="muted mb-0">No confirmed payments yet.</p>@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Receipt</th><th>Booking</th><th>Payment</th><th>Amount</th><th>Confirmed</th><th>Money</th></tr></thead><tbody>
  @foreach($paid as $p)
    <tr><td data-label="Receipt">{{ $p->receipt_no }}</td><td data-label="Booking"><a href="{{ route('business.bookings.show', $p->booking) }}">{{ $p->booking->code }}</a></td>
      <td data-label="Payment">{{ $p->label }}</td><td data-label="Amount">{{ money($p->amount) }}</td><td data-label="Confirmed">{{ fmt_datetime($p->paid_at) }}</td>
      <td data-label="Money">@if($p->gateway)@if($p->remitted_at)<span class="badge b-green">Sent to you {{ $p->remitted_at->format('M j') }}</span>@else<span class="badge b-gold">Paid online · PartyRent sends it</span>@endif @else<span class="small muted">In your account</span>@endif</td></tr>
  @endforeach
  </tbody></table></div>@endif
</div>
@endsection
