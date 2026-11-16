@extends('layouts.market', ['title' => 'Party suppliers', 'nav' => 'suppliers'])

@section('content')
<div class="page-head"><div><h1>Party suppliers</h1><p>Verified local rental shops on PartyRent.</p></div>
  <form class="row wrap" method="GET"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search suppliers or city" style="width:220px" aria-label="Search suppliers"><select class="input input-sm" name="f" style="width:auto" aria-label="Fulfillment" data-autosubmit><option value="">All fulfillment</option><option value="delivery" @selected($f === 'delivery')>Offers delivery</option><option value="pickup" @selected($f === 'pickup')>Offers pickup</option></select><button class="btn btn-sm">Search</button></form>
</div>
<div class="grid grid-2">
  @forelse($list as $s)
    <div class="card">
      <div class="row top"><div class="logo-tile" style="background:{{ $s->color }}1a">{{ $s->logo }}</div><div class="grow"><a class="strong" style="font-size:1.05rem;color:var(--ink)" href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a> <div class="small muted">{{ icon('map', 'width:13px;vertical-align:-2px') }} {{ $s->city }} · <span class="stars">★</span> {{ rating_text($s) }} · {{ $s->fulfillmentLabel() }}</div><p class="small mt-1 mb-1">{{ $s->description }}</p><div class="row wrap gap-sm">@foreach($s->methods as $m)<span class="tag">{{ \App\Models\Payment::METHOD_LABEL[$m] ?? $m }}</span>@endforeach</div></div></div>
      <div class="scroll-x mt-2">@foreach($s->products->take(5) as $p)<a href="{{ route('products.show', $p) }}" title="{{ $p->name }}">@include('partials.thumb', ['item' => $p])</a>@endforeach</div>
      <div class="row between mt-1"><span class="small muted">{{ $s->products->count() }} items · {{ $s->packages_count }} packages</span><a class="btn btn-sm btn-outline" href="{{ route('suppliers.show', $s) }}">Visit shop</a></div>
    </div>
  @empty
    @include('partials.empty', ['emoji' => '🏪', 'title' => 'No suppliers found', 'text' => 'Try another search.'])
  @endforelse
</div>
@endsection
