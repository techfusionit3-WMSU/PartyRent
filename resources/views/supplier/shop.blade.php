@extends('layouts.dash', ['title' => 'Shop Profile & Settings', 'nav' => 'shop'])

@section('content')
<div class="page-head"><div><h1>Shop Profile & Settings</h1><p>What customers see on your shop page, and the payment rules applied to new requests.</p></div><a class="btn" href="{{ route('suppliers.show', $s) }}">{{ icon('eye') }}View public shop</a></div>
<form method="POST" action="{{ route('supplier.shop.update') }}" class="stack" style="max-width:820px">
  @csrf @method('PUT')
  <div class="card shop-sections">@include('partials.shop-fields')</div>
  <div class="row"><button class="btn btn-primary btn-lg">Save shop profile</button><span class="small muted">Submitted requests keep the terms that were quoted to the customer.</span></div>
</form>
@push('head')<style>.shop-sections section + section{margin-top:28px;padding-top:20px;border-top:1px solid var(--line)}</style>@endpush
@endsection
