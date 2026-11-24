@extends('layouts.dash', ['title' => 'Custom discounts', 'nav' => 'custom-discounts'])

{{-- Audit of every shop's custom (customer) discounts and the rentals they were used on (amounts as applied). --}}
@section('content')
<div class="page-head"><div><h1>Custom discounts</h1><p>Private discounts shops gave to selected customers, and the rentals they were used on. Amounts are shown as they were applied (read-only).</p></div>
  <form method="GET"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search shop, customer or name" style="width:260px" aria-label="Search custom discounts"></form></div>
<div class="card pad-0">
  @if($list->isEmpty())@include('partials.empty', ['emoji' => '💝', 'title' => 'No custom discounts', 'text' => $q !== '' ? 'Nothing matches your search.' : 'No shop has made a custom discount yet.'])@else
  <div class="table-wrap"><table class="table cards"><thead><tr><th>Shop</th><th>Customer</th><th>Discount</th><th>Used on</th><th>Status</th></tr></thead><tbody>
    @foreach($list as $d)
      <tr>
        <td data-label="Shop"><b>{{ $d->supplier?->name }}</b><div class="small muted">made {{ fmt_date($d->created_at) }}</div></td>
        <td data-label="Customer">{{ $d->customer?->name ?? 'Deleted account' }}<div class="small muted">{{ $d->customer?->email }}</div></td>
        <td data-label="Discount"><b>{{ $d->name }}</b><div class="small">{{ \App\Domain\CustomDiscounts::label($d) }}{{ $d->max_uses ? ' · up to '.$d->max_uses.' rental'.($d->max_uses === 1 ? '' : 's') : '' }}</div></td>
        <td data-label="Used on">
          @forelse($d->rentals as $r)
            @php($cd = $r->pricing['customDiscount'] ?? null)
            <div class="small"><b>{{ $r->code }}</b> · {{ \App\Models\RentalRequest::statusLabel($r->status) }}@if($cd) · −{{ money($cd['amount']) }} ({{ money($cd['totalBefore']) }} → {{ money($cd['totalAfter']) }}) · by {{ $cd['appliedBy'] }}, {{ fmt_date(substr($cd['appliedAt'], 0, 10)) }}@endif</div>
          @empty<span class="muted small">Not used yet</span>@endforelse
        </td>
        <td data-label="Status"><span class="badge {{ $d->active ? 'b-green' : 'b-gray' }}">{{ $d->active ? 'On' : 'Off' }}</span></td>
      </tr>
    @endforeach
  </tbody></table></div><div style="padding:12px 16px">{{ $list->links('pagination::simple-default') }}</div>@endif
</div>
@endsection
