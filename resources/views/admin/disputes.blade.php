@extends('layouts.dash', ['title' => 'Damage Disputes', 'nav' => 'disputes'])

{{-- Damage charges a customer disputed within 48 hours of the return inspection (individual shops). --}}
@section('content')
<div class="page-head"><div><h1>Damage Disputes</h1><p>Customers who disputed a damage charge from an individual shop. Compare the photos taken at release and at return, the purchase record and the repair cost, then set the final amount. The deposit stays held until you decide.</p></div></div>
<div class="card"><h3>Waiting for a decision</h3>
  @if($open->isEmpty())
    @include('partials.empty', ['emoji' => '⚖️', 'title' => 'No open disputes', 'text' => 'Nothing to decide right now.'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Booking</th><th>Shop</th><th>Customer</th><th>Disputed items</th><th class="num">Charged</th><th>Disputed on</th><th></th></tr></thead><tbody>
      @foreach($open as $r)
        @php($dec = collect($r->inspection['review']['decisions'])->filter(fn ($d) => $d['state'] === 'disputed'))
        <tr><td data-label="Booking"><b>{{ $r->code }}</b></td><td data-label="Shop">{{ $r->supplier->name }}</td><td data-label="Customer">{{ $r->customer->name }}</td>
          <td data-label="Disputed items">{{ $dec->keys()->map(fn ($pid) => inv()->product($pid)?->name)->implode(', ') }}</td>
          <td data-label="Charged" class="num">{{ money($dec->sum('amount')) }}</td><td data-label="Disputed on">{{ fmt_datetime($r->inspection['review']['answeredAt'] ?? null) }}</td>
          <td class="right"><a class="btn btn-xs btn-primary" href="{{ route('admin.disputes.show', $r) }}">Decide</a></td></tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>
@if($done->isNotEmpty())
<div class="card"><h3>Recently decided</h3>
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Booking</th><th>Shop</th><th class="num">Shop charged</th><th class="num">Final</th><th>Decided</th><th></th></tr></thead><tbody>
    @foreach($done as $r)
      <tr><td data-label="Booking"><b>{{ $r->code }}</b></td><td data-label="Shop">{{ $r->supplier->name }}</td><td data-label="Shop charged" class="num">{{ money($r->inspection['assessed'] ?? $r->inspection['compensation']) }}</td>
        <td data-label="Final" class="num">{{ money($r->inspection['compensation']) }}</td><td data-label="Decided">{{ fmt_datetime($r->inspection['review']['resolvedAt']) }}</td>
        <td class="right"><a class="btn btn-xs" href="{{ route('admin.disputes.show', $r) }}">Open</a></td></tr>
    @endforeach
  </tbody></table></div>
</div>
@endif
@endsection
