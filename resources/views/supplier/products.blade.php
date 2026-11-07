@extends('layouts.dash', ['title' => 'Products', 'nav' => 'products'])

@section('content')
<div class="page-head"><div><h1>Products</h1><p>{{ $list->count() }} listings. Stock shown for today; open Inventory for holds and condition counts.</p></div><a class="btn btn-primary" href="{{ route('supplier.products.create') }}">{{ icon('plus') }}Add product</a></div>
<div class="card pad-0">
  @if($list->isEmpty())
    @include('partials.empty', ['emoji' => '📦', 'title' => 'No products yet', 'text' => 'Add your first rental item to start receiving requests.', 'action' => '<a class="btn btn-primary" href="'.route('supplier.products.create').'">Add product</a>'])
  @else
    <div class="table-wrap"><table class="table cards"><thead><tr><th>Item</th><th>Category</th><th class="num">Price / day</th><th class="num">Stock</th><th class="num">Available today</th><th>Status</th><th></th></tr></thead><tbody>
      @foreach($list as $p)
        @php($b = inv()->breakdown($p->id, $today))
        <tr>
          <td data-label="Item"><div class="row">@include('partials.thumb', ['item' => $p, 'cls' => 'sm'])<div><b>{{ $p->name }}</b><div class="small muted">{{ $p->rent_count }} rentals · ★ {{ $p->rating }}</div></div></div></td>
          <td data-label="Category">{{ $p->category->name ?? '' }}</td>
          <td data-label="Price" class="num">{{ money($p->basePrice()) }}@if(count($p->tiers) > 1)<div class="small muted">{{ count($p->tiers) }} tiers</div>@endif</td>
          <td data-label="Stock" class="num">{{ $p->total_stock }}</td>
          <td data-label="Available" class="num"><b>{{ $b['available'] }}</b>@if($b['reserved'])<div class="small muted">{{ $b['reserved'] }} reserved</div>@endif</td>
          <td data-label="Status"><span class="badge {{ ['published' => 'b-green', 'draft' => 'b-gray', 'hidden' => 'b-gold'][$p->status] ?? 'b-gray' }}">{{ ucfirst($p->status) }}</span></td>
          <td><div class="btn-group">
            <a class="btn btn-xs" href="{{ route('supplier.products.edit', $p) }}">{{ icon('edit') }}Edit</a>
            <form method="POST" action="{{ route('supplier.products.status', $p) }}">@csrf<input type="hidden" name="status" value="{{ $p->status === 'published' ? 'hidden' : 'published' }}"><button class="btn btn-xs btn-ghost">{{ $p->status === 'published' ? 'Hide' : 'Publish' }}</button></form>
            <form method="POST" action="{{ route('supplier.products.status', $p) }}" data-confirm="Archive {{ $p->name }}? It will disappear from your shop. Past rentals keep their records." data-confirm-title="Archive listing?" data-confirm-ok="Archive" data-danger>@csrf<input type="hidden" name="status" value="archived"><button class="btn btn-xs btn-ghost">{{ icon('trash') }}</button></form>
          </div></td>
        </tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>
@endsection
