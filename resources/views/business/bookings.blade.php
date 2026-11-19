@extends('layouts.dash', ['title' => 'Event Bookings', 'nav' => 'bookings'])

@section('content')
<div class="page-head"><div><h1>Event Bookings</h1><p>Customers send an inquiry, you reply with a quotation, and the date is yours once they pay the reservation fee.</p></div></div>
<div class="tabs">@foreach(\App\Http\Controllers\Business\BookingController::TABS as $k => $l)<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('business.bookings', ['tab' => $k]) }}">{{ $l }}<span class="count">{{ $counts[$k] }}</span></a>@endforeach</div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '📅', 'title' => 'Nothing here', 'text' => $tab === 'inquiry' ? 'New inquiries from customers show up here.' : ''])</div>
@else
  <div class="card pad-0"><div class="table-wrap"><table class="table cards"><thead><tr><th>Event</th><th>Customer</th><th>Guests</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>
  @foreach($list as $b)
    @php($next = $b->payments->firstWhere('status', '!=', 'paid'))
    <tr>
      <td data-label="Event"><b>{{ $b->event_type }}</b> · {{ $b->event_date->format('D, M j, Y') }}<div class="small muted">{{ $b->code }} · {{ $b->package?->name ?? 'Custom event' }}</div></td>
      <td data-label="Customer">{{ $b->customer->name }}<div class="small muted">{{ \Illuminate\Support\Str::limit($b->venue, 40) }}</div></td>
      <td data-label="Guests">{{ $b->guests }}</td>
      <td data-label="Amount">@if($b->quote){{ money($b->total()) }}<div class="small muted">{{ money($b->paid()) }} paid</div>@else<span class="muted">{{ $b->budget ? 'Budget '.money($b->budget) : '—' }}</span>@endif</td>
      <td data-label="Status"><span class="badge {{ $b->statusBadge() }}">{{ $b->statusLabel() }}</span>@if($next && in_array($b->status, ['booked', 'confirmed'], true))<div class="small {{ $next->isOverdue() ? '' : 'muted' }}" @if($next->isOverdue()) style="color:var(--red)" @endif>{{ $next->label }} {{ $next->status === 'pending' ? 'to confirm' : 'due '.$next->due_date->format('M j') }}</div>@endif</td>
      <td data-label=""><a class="btn btn-xs {{ $b->status === 'inquiry' ? 'btn-primary' : '' }}" href="{{ route('business.bookings.show', $b) }}">{{ $b->status === 'inquiry' ? 'Send quotation' : 'Open' }}</a></td>
    </tr>
  @endforeach
  </tbody></table></div></div>
@endif
@endsection
