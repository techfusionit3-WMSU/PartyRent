@extends('layouts.dash', ['title' => 'Shortfall Cases', 'nav' => 'cases'])

{{-- Change list C12: PartyRent support sees every case; escalated ones (deadline passed) need settling. --}}
@section('content')
<div class="page-head"><div><h1>Shortfall Cases</h1><p>Problems shops reported with accepted bookings. Cases come to you when the shop and the customer do not agree before a proposal's deadline.</p></div></div>
<div class="card">
  @if($cases->isEmpty())
    @include('partials.empty', ['emoji' => '🛟', 'title' => 'No cases', 'text' => 'Nothing has been reported.'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Case</th><th>Booking</th><th>Shop</th><th>Customer</th><th>Item</th><th class="num">Units</th><th>Status</th><th>Opened</th><th></th></tr></thead><tbody>
      @foreach($cases as $c)
        @php($st = \App\Models\ShortfallCase::STATUS[$c->status] ?? [$c->status, 'b-gray'])
        <tr><td data-label="Case"><b>{{ $c->code }}</b></td><td data-label="Booking">{{ $c->rentalRequest->code }}</td><td data-label="Shop">{{ $c->supplier->name }}</td>
          <td data-label="Customer">{{ $c->customer->name }}</td><td data-label="Item">{{ $c->product->name }}</td><td data-label="Units" class="num">{{ $c->units() }}</td>
          <td data-label="Status"><span class="badge {{ $st[1] }}">{{ $st[0] }}</span></td><td data-label="Opened">{{ $c->created_at->format('M j, Y') }}</td>
          <td class="right"><a class="btn btn-xs {{ $c->status === 'escalated' ? 'btn-primary' : '' }}" href="{{ route('cases.show', $c) }}">{{ $c->status === 'escalated' ? 'Settle' : 'Open' }}</a></td></tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>
@endsection
