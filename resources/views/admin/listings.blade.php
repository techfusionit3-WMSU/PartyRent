@extends('layouts.dash', ['title' => 'Listings & Packages', 'nav' => 'listings'])

@php($canManage = auth()->user()->hasPerm('listings.manage'))
@section('content')
<div class="page-head"><div><h1>Listings & packages</h1><p>Hide listings that break the rules — the supplier is notified. Prices and stock stay under the supplier's control.</p></div>
  <form method="GET" class="row wrap" style="gap:6px"><input type="hidden" name="view" value="{{ $view }}"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search item or supplier" style="width:220px">
    @if($view === 'items')<select class="input input-sm" name="status" style="width:auto" data-autosubmit><option value="">All statuses</option>@foreach(['published', 'hidden', 'draft'] as $s)<option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>@endforeach</select>@endif
    <button class="btn btn-sm">Search</button></form>
</div>
<div class="tabs"><a class="tab {{ $view === 'items' ? 'active' : '' }}" href="{{ route('admin.listings') }}">Items</a><a class="tab {{ $view === 'packages' ? 'active' : '' }}" href="{{ route('admin.listings', ['view' => 'packages']) }}">Packages</a></div>
<div class="card pad-0">
  @if($list->isEmpty())@include('partials.empty', ['text' => 'No listings match.'])@else
  <div class="table-wrap"><table class="table cards">
    @if($view === 'items')
      <thead><tr><th>Item</th><th>Supplier</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th><th></th></tr></thead><tbody>
      @foreach($list as $p)
        <tr><td data-label="Item"><div class="row">@include('partials.thumb', ['item' => $p, 'cls' => 'sm'])<div><b>{{ $p->name }}</b><div class="small muted">★ {{ rating_text($p) }} · {{ $p->rent_count }} rentals</div></div></div></td><td data-label="Supplier">{{ $p->supplier->name }}<div class="small muted">{{ ucfirst($p->supplier->status) }}</div></td><td data-label="Category">{{ $p->category->name ?? '' }}</td><td data-label="Price" class="num">{{ money($p->basePrice()) }}</td><td data-label="Stock" class="num">{{ $p->total_stock }}</td>
          <td data-label="Status"><span class="badge {{ ['published' => 'b-green', 'hidden' => 'b-red'][$p->status] ?? 'b-gray' }}">{{ ucfirst($p->status) }}</span></td>
          <td>@if($canManage && $p->status !== 'draft')
            @if($p->status === 'published')
              <form method="POST" action="{{ route('admin.listings.status', $p) }}" class="row" style="gap:4px">@csrf<input type="hidden" name="status" value="hidden"><input class="input input-sm" name="reason" placeholder="Reason" style="width:140px" required><button class="btn btn-xs btn-danger">Hide</button></form>
            @else
              <form method="POST" action="{{ route('admin.listings.status', $p) }}">@csrf<input type="hidden" name="status" value="published"><button class="btn btn-xs">Restore</button></form>
            @endif
          @endif</td></tr>
      @endforeach</tbody>
    @else
      <thead><tr><th>Package</th><th>Supplier</th><th class="num">Items</th><th class="num">Price / day</th><th>Status</th><th></th></tr></thead><tbody>
      @foreach($list as $k)
        <tr><td data-label="Package"><b>{{ $k->emoji }} {{ $k->name }}</b></td><td data-label="Supplier">{{ $k->supplier->name }}</td><td data-label="Items" class="num">{{ count($k->items) }}</td><td data-label="Price" class="num">{{ money($k->price) }}</td><td data-label="Status"><span class="badge {{ $k->active ? 'b-green' : 'b-red' }}">{{ $k->active ? 'Active' : 'Hidden' }}</span></td>
          <td>@if($canManage)<form method="POST" action="{{ route('admin.packages.status', $k) }}" @if($k->active) data-confirm-title="Hide {{ $k->name }}?" data-confirm="It disappears from the marketplace. Requests already booked keep their copy." data-confirm-ok="Hide" data-danger @endif>@csrf<button class="btn btn-xs {{ $k->active ? 'btn-danger' : '' }}">{{ $k->active ? 'Hide' : 'Restore' }}</button></form>@endif</td></tr>
      @endforeach</tbody>
    @endif
  </table></div><div style="padding:12px 16px">{{ $list->links('pagination::simple-default') }}</div>@endif
</div>
@endsection
