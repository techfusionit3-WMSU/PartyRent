@extends('layouts.dash', ['title' => 'Products', 'nav' => 'products'])

{{-- Reference SP.products (+ app: items for sale) --}}
@section('content')
<div class="page-head"><div><h1>Products</h1><p>Your rental items. Active items appear in the marketplace, search and AI suggestions.</p></div>
  <div class="btn-group"><a class="btn btn-primary" href="{{ route('supplier.products.create') }}">{{ icon('plus') }}Add product</a></div></div>
<form class="row wrap mb-2" method="GET">
  <input type="hidden" name="f" value="{{ $f }}">
  <input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search products" style="width:240px" aria-label="Search">
  <div class="seg">@foreach(['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived', 'hidden' => 'Hidden by admin'] as $k => $l)<a class="{{ $f === $k ? 'active' : '' }}" href="{{ route('supplier.products', array_filter(['f' => $k, 'q' => $q])) }}">{{ $l }}</a>@endforeach</div>
</form>
<div class="card pad-0">
  @if($list->isEmpty())
    @include('partials.empty', ['emoji' => '📦', 'title' => 'No products here', 'text' => __('Add your first item: a name, a photo, the price per day and how many you have.'), 'action' => auth()->user()->orgCan('products.manage') ? '<a class="btn btn-primary" href="'.e(route('supplier.products.create')).'">'.icon('plus').e(__('Add a product')).'</a>' : ''])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th><th>Price</th><th>Stock today</th><th class="num">Rentals</th><th>Status</th><th></th></tr></thead><tbody>
      @foreach($list as $p)
        @php($b = inv()->breakdown($p->id, $today))
        <tr>
          <td data-label="Item"><div class="row">@include('partials.thumb', ['item' => $p, 'cls' => 'sm'])<div><a class="strong" href="{{ route('supplier.products.edit', $p) }}">{{ $p->name }}</a>@if($p->isSale()) <span class="badge b-purple">For sale</span>@endif @if($p->package_only) <span class="badge b-pink" title="Not listed on its own: only available inside your packages">Package only</span>@endif @if($p->isLowStock()) <span class="badge b-red">Low stock</span>@endif<div class="small muted">#{{ $p->id }} · {{ $p->category->name ?? '' }}</div></div></div></td>
          <td data-label="Price"><div>{{ money($p->basePrice()) }}<div class="small muted">/{{ $p->unit }}{{ count($p->tiers) > 1 ? ' · '.count($p->tiers).' tiers' : '' }}{{ $p->max_rental_days ? ' · max '.$p->max_rental_days.' day'.($p->max_rental_days > 1 ? 's' : '').'/booking' : '' }}</div></div></td>
          <td data-label="Stock today"><div><b>{{ $p->stockText($b['available']) }}</b> available of {{ $p->stockText($b['total']) }}<div class="small muted">{{ $b['reserved'] }} {{ $p->isSale() ? 'committed' : 'reserved' }} · {{ $b['damaged'] + $b['missing'] + $b['maintenance'] + $b['setaside'] }} unusable</div></div></td>
          <td data-label="Rentals" class="num">{{ $p->rent_count }}</td>
          <td data-label="Status"><span class="badge {{ ['active' => 'b-green', 'inactive' => 'b-gray', 'archived' => 'b-gray', 'hidden' => 'b-red'][$p->status] ?? 'b-gray' }}">{{ ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived', 'hidden' => 'Hidden by admin'][$p->status] ?? ucfirst($p->status) }}</span>@if($p->status === 'hidden' && $p->hidden_reason)<div class="small muted">{{ $p->hidden_reason }}</div>@endif</td>
          <td><div class="btn-group">
            <a class="btn btn-xs" href="{{ route('supplier.products.edit', $p) }}">{{ icon('edit') }}Edit</a>
            @if($p->status === 'archived')
              <form method="POST" action="{{ route('supplier.products.status', $p) }}">@csrf<input type="hidden" name="status" value="restore"><button class="btn btn-xs">Restore</button></form>
            @elseif($p->status !== 'hidden')
              <form method="POST" action="{{ route('supplier.products.status', $p) }}">@csrf<input type="hidden" name="status" value="{{ $p->status === 'active' ? 'inactive' : 'active' }}"><button class="btn btn-xs">{{ $p->status === 'active' ? 'Deactivate' : 'Activate' }}</button></form>
              <form method="POST" action="{{ route('supplier.products.status', $p) }}" data-confirm="It will be removed from the marketplace, and packages that use it will be deactivated. Past transactions keep their history." data-confirm-title="Archive {{ $p->name }}?" data-confirm-ok="Archive" data-danger>@csrf<input type="hidden" name="status" value="archived"><button class="btn btn-xs btn-ghost" aria-label="Archive {{ $p->name }}" title="Archive">{{ icon('archive') }}</button></form>
            @else
              <span class="small muted">Cannot be republished</span>
            @endif
          </div></td>
        </tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>
@endsection
