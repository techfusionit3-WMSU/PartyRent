@extends('layouts.market', ['title' => 'Rental packages', 'nav' => 'packages'])

@section('content')
<div class="page-head"><div><h1>Rental packages</h1><p>Ready-made bundles from our suppliers. Package stock comes from the real items inside each bundle.</p></div>
  <div class="row wrap gap-sm"><a class="chip {{ $ev === '' ? 'active' : '' }}" href="{{ route('packages.index') }}">All</a>@foreach($events as $e)<a class="chip {{ $ev === $e ? 'active' : '' }}" href="{{ route('packages.index', ['event' => $e]) }}">{{ $e }}</a>@endforeach</div>
</div>
@if($list->isEmpty())
  @include('partials.empty', ['emoji' => '📦', 'title' => 'No packages', 'text' => ''])
@else
  <div class="product-grid">@foreach($list as $k)@include('partials.package-card', ['k' => $k])@endforeach</div>
@endif
@endsection
