@extends('layouts.dash', ['title' => 'Event Bookings', 'nav' => 'events'])

@section('content')
<div class="page-head"><div><h1>Event bookings</h1><p>Bookings of event companies (inquiry → quotation → payment schedule). Online payments come to PartyRent and are sent on to the business.</p></div>
  <form class="row" method="GET"><input type="hidden" name="tab" value="{{ $tab }}"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Code, company, customer" style="width:220px" aria-label="Search"></form></div>
<div class="stats">
  @include('partials.stat', ['label' => 'Booked events', 'value' => $stats['bookings'], 'sub' => 'confirmed or done', 'icon' => 'calendar', 'color' => 'purple'])
  @include('partials.stat', ['label' => 'Booked value', 'value' => money_short($stats['value']), 'sub' => 'accepted quotations', 'icon' => 'chart', 'color' => 'teal'])
  @include('partials.stat', ['label' => 'Paid so far', 'value' => money_short($stats['paid']), 'sub' => 'all installments', 'icon' => 'card', 'color' => 'sky'])
  @include('partials.stat', ['label' => 'To send', 'value' => money_short($stats['online']), 'sub' => 'paid online, not yet sent', 'icon' => 'cash', 'color' => $stats['online'] > 0 ? 'gold' : 'teal', 'href' => route('admin.events', ['tab' => 'tosend'])])
</div>
<div class="tabs mt-3">@foreach(\App\Http\Controllers\Admin\EventBookingController::TABS as $k => $l)<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('admin.events', array_filter(['tab' => $k, 'q' => $q])) }}">{{ $l }}<span class="count">{{ $counts[$k] }}</span></a>@endforeach</div>

@if($tab === 'tosend')
  <div class="card pad-0">@if($toSend->isEmpty())<div class="pad">@include('partials.empty', ['emoji' => '✅', 'title' => 'Nothing to send', 'text' => 'Online event payments show here until they are sent to the business.'])</div>@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Business</th><th>Booking</th><th>Payment</th><th>Amount</th><th>Paid</th><th>Send to</th><th></th></tr></thead><tbody>
  @foreach($toSend as $p)
    @php($sup = $p->booking->supplier)
    <tr><td data-label="Business"><b>{{ $sup->name }}</b></td><td data-label="Booking">{{ $p->booking->code }}</td><td data-label="Payment">{{ $p->label }}<div class="small muted">{{ $p->reference }}</div></td>
      <td data-label="Amount"><b>{{ money($p->amount) }}</b></td><td data-label="Paid">{{ fmt_datetime($p->paid_at) }}</td>
      <td data-label="Send to"><span class="small">{{ $sup->payout_method === 'gcash' ? 'GCash' : ($sup->payout_bank ?: '—') }} · {{ $sup->payout_account_name }} · {{ $sup->payout_account_no }}</span></td>
      <td data-label="">@if($canSend)<form method="POST" action="{{ route('admin.events.remit', $p) }}" data-confirm-title="Mark {{ money($p->amount) }} as sent?" data-confirm="Only after the transfer to {{ $sup->name }} is done." data-confirm-ok="Mark as sent">@csrf<button class="btn btn-xs btn-success">Mark as sent</button></form>@endif</td></tr>
  @endforeach
  </tbody></table></div>@endif</div>
@elseif($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '📅', 'title' => 'No event bookings here'])</div>
@else
  <div class="card pad-0"><div class="table-wrap"><table class="table cards"><thead><tr><th>Booking</th><th>Business</th><th>Customer</th><th>Event</th><th>Amount</th><th>Status</th></tr></thead><tbody>
  @foreach($list as $b)
    <tr><td data-label="Booking"><b>{{ $b->code }}</b><div class="small muted">{{ fmt_date($b->created_at?->toDateString()) }}</div></td>
      <td data-label="Business">{{ $b->supplier->name }}</td>
      <td data-label="Customer">{{ $b->customer->name }}<div class="small muted">{{ $b->customer->email }}</div></td>
      <td data-label="Event">{{ $b->event_type }} · {{ $b->event_date->format('M j, Y') }}<div class="small muted">{{ $b->guests }} guests · {{ \Illuminate\Support\Str::limit($b->venue, 40) }}</div></td>
      <td data-label="Amount">@if($b->quote){{ money($b->total()) }}<div class="small muted">{{ money($b->paid()) }} paid</div>@else<span class="muted">—</span>@endif</td>
      <td data-label="Status"><span class="badge {{ $b->statusBadge() }}">{{ $b->statusLabel() }}</span>@if($b->close_reason)<div class="small muted">{{ $b->close_reason }}</div>@endif</td></tr>
  @endforeach
  </tbody></table></div></div>
@endif
@endsection
