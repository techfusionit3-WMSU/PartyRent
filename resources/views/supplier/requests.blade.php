@extends('layouts.dash', ['title' => 'Rentals', 'nav' => 'requests'])

@section('content')
<div class="page-head"><div><h1>Rentals</h1><p>Every rental from your customers, from new request to completed. Accepting re-checks stock for every date.</p></div>
  <form method="GET"><input type="hidden" name="tab" value="{{ $tab }}"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search no., customer, event" style="width:240px" aria-label="Search"></form>
</div>
<div class="tabs">@foreach([['pending', 'Pending'], ['upcoming', 'Accepted / Upcoming'], ['active', 'Ongoing & Returns'], ['completed', 'Completed'], ['closed', 'Declined / Cancelled'], ['all', 'All']] as [$k, $l])<a class="tab {{ $tab === $k ? 'active' : '' }}" href="{{ route('supplier.requests', array_filter(['tab' => $k, 'q' => $q])) }}">{{ $l }}<span class="count">{{ $counts[$k] }}</span></a>@endforeach</div>
<div class="card pad-0">
  @if($list->isEmpty())
    @include('partials.empty', ['emoji' => '📋', 'title' => __('No requests here yet'), 'text' => __('New requests from customers show up here. More customers find you when your shop has photos, clear prices and good reviews.'), 'action' => '<a class="btn" href="'.e(route('suppliers.show', auth()->user()->shop())).'">'.icon('store').e(__('See your shop page')).'</a>'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Request</th><th>Customer</th><th>Products</th><th>Rental dates</th><th class="num">Amount</th><th>Payment</th><th>Submitted</th><th>Status</th><th></th></tr></thead><tbody>
      @foreach($list as $r)
        @php($conf = $r->status === 'pending' ? inv()->check($r->lines, $r->id)['bad'] : [])
        <tr>
          <td data-label="Request"><a class="strong" href="{{ route('supplier.requests.show', $r) }}">{{ $r->code }}</a><div class="small muted">{{ $r->event_name }}</div></td>
          <td data-label="Customer">{{ $r->customer->name }}</td>
          <td data-label="Products"><div class="small" style="max-width:260px">{{ collect($r->lines)->map(fn ($l) => ($l['type'] === 'package' ? '📦 ' : '').inv()->itemName($l))->implode(', ') }}</div></td>
          <td data-label="Rental dates">{{ fmt_range($r->start_date, $r->end_date) }}<div class="small muted">{{ count($r->dates()) }} day(s) · {{ $r->totalUnits() }} units</div></td>
          <td data-label="Amount" class="num"><b>{{ money($r->pricing['payable']) }}</b></td>
          <td data-label="Payment"><div class="small">{{ (\App\Domain\Finance::PLAN_LABEL[$r->plan] ?? '') }}<br>{{ \App\Domain\Finance::METHOD_LABEL[$r->method] ?? $r->method }}</div></td>
          <td data-label="Submitted"><span class="small">{{ time_ago($r->created_at) }}</span></td>
          <td data-label="Status">@include('partials.badges', ['status' => $r->status])@if($conf)<div><span class="badge b-red mt-1">Stock conflict</span></div>@endif</td>
          <td><a class="btn btn-sm {{ $r->status === 'pending' ? 'btn-primary' : '' }}" href="{{ route('supplier.requests.show', $r) }}">{{ $r->status === 'pending' ? 'Review' : 'Open' }}</a></td>
        </tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>
@endsection
