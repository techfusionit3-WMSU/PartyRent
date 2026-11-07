@extends('layouts.dash', ['title' => 'Packages', 'nav' => 'packages'])

@section('content')
<div class="page-head"><div><h1>Packages</h1><p>Bundles of your own items. Package availability always comes from the stock of the items inside.</p></div><a class="btn btn-primary" href="{{ route('supplier.packages.create') }}">{{ icon('plus') }}New package</a></div>
@if($list->isEmpty())
  <div class="card">@include('partials.empty', ['emoji' => '📦', 'title' => 'No packages yet', 'text' => 'Bundle items that customers often rent together.'])</div>
@else
  <div class="grid grid-2">
    @foreach($list as $k)
      @php($v = \App\Domain\Pricing::packageValue($k))
      <div class="card"><div class="row between top"><div><div style="font-size:1.8rem">{{ $k->emoji }}</div><b>{{ $k->name }}</b> @if(! $k->active)<span class="badge b-gray">Inactive</span>@endif<div class="small muted">{{ count($k->items) }} items · {{ money($k->price) }}/day{{ $v['savings'] > 0 ? ' · saves customers '.money($v['savings']) : '' }}</div></div><a class="btn btn-sm" href="{{ route('supplier.packages.edit', $k) }}">{{ icon('edit') }}Edit</a></div>
        <p class="small mt-1 mb-1">{{ $k->description }}</p>
        <div class="small muted">{{ collect($k->items)->map(fn ($it) => $it['qty'].'× '.(inv()->product($it['product_id'])?->name ?? ''))->implode(', ') }}</div>
        <div class="small mt-1">Available today: <b>{{ inv()->packageAvailableOn($k->id, today_iso()) }}</b></div>
      </div>
    @endforeach
  </div>
@endif
@endsection
