@extends('layouts.market', ['title' => 'Party suppliers', 'nav' => 'suppliers'])

@section('content')
<div class="page-head"><div><h1>{{ $type === 'business' && \App\Models\Supplier::eventCompanies() ? 'Event companies' : 'Rental shops' }}</h1><p>{{ $type === 'business' && \App\Models\Supplier::eventCompanies() ? 'Companies that handle your whole event: send an inquiry and get a quotation.' : (\App\Models\Supplier::eventCompanies() ? 'Verified local rental shops and event companies on PartyRent.' : 'Verified local shops that rent decorations and party items for your event.') }}</p></div>
  <form class="row wrap" method="GET"><input type="hidden" name="type" value="{{ $type }}"><input class="input input-sm" name="q" value="{{ $q }}" placeholder="Search suppliers or city" style="width:220px" aria-label="Search suppliers"><select class="input input-sm" name="f" style="width:auto" aria-label="Fulfillment" data-autosubmit><option value="">All fulfillment</option><option value="delivery" @selected($f === 'delivery')>Offers delivery</option><option value="pickup" @selected($f === 'pickup')>Offers pickup</option></select></form>
</div>
@if(\App\Models\Supplier::eventCompanies())<div class="tabs mb-2">@foreach(['' => 'All', 'shops' => 'Rental shops', 'business' => 'Event companies'] as $k => $l)<a class="tab {{ $type === $k ? 'active' : '' }}" href="{{ route('suppliers.index', array_filter(['type' => $k, 'q' => $q])) }}">{{ $l }}</a>@endforeach</div>@endif
<div class="grid grid-2">
  @forelse($list as $s)
    @if($s->isOrganization())
    @php($bset = $s->bizSettings())
    <div class="card">
      <div class="row top"><div class="logo-tile" style="background:{{ $s->color }}1a">@if($s->logoUrl())<img src="{{ $s->logoUrl() }}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:12px">@else{{ $s->logo }}@endif</div><div class="grow"><a class="strong" style="font-size:1.05rem;color:var(--ink)" href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a> <span class="badge b-purple plain">Event company</span><div class="small muted">{{ icon('map', 'width:13px;vertical-align:-2px') }} {{ $bset['serviceAreas'] ? implode(', ', array_slice($bset['serviceAreas'], 0, 3)) : $s->city }} · <span class="stars">★</span> {{ rating_text($s) }}</div><p class="small mt-1 mb-1">{{ \Illuminate\Support\Str::limit($s->description, 160) }}</p>
        <div class="row wrap gap-sm">@foreach(array_slice($s->profile('events', []), 0, 4) as $e)<span class="tag">{{ $e }}</span>@endforeach</div></div></div>
      @if($s->portfolio)<div class="scroll-x mt-2">@foreach(array_slice($s->portfolio, 0, 5) as $ph)<img src="{{ asset('storage/'.$ph) }}" alt="" loading="lazy" style="width:84px;height:64px;object-fit:cover;border-radius:10px">@endforeach</div>@endif
      <div class="row between mt-1"><span class="small muted">{{ $s->profile('packagesFrom') ? 'Packages from '.money($s->profile('packagesFrom')) : ($s->profile('eventSize') ? \App\Models\Supplier::EVENT_SIZES[$s->profile('eventSize')] ?? '' : '') }}</span><a class="btn btn-sm btn-primary" href="{{ route('suppliers.show', $s) }}">See packages</a></div>
    </div>
    @continue
    @endif
    <div class="card">
      <div class="row top"><div class="logo-tile" style="background:{{ $s->color }}1a">{{ $s->logo }}</div><div class="grow"><a class="strong" style="font-size:1.05rem;color:var(--ink)" href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a> <div class="small muted">{{ icon('map', 'width:13px;vertical-align:-2px') }} {{ $s->city }} · <span class="stars">★</span> {{ rating_text($s) }} · {{ $s->fulfillmentLabel() }}</div><p class="small mt-1 mb-1">{{ $s->description }}</p><div class="row wrap gap-sm">@foreach($s->acceptedMethods() as $m)<span class="tag">{{ \App\Models\Payment::METHOD_LABEL[$m] ?? $m }}</span>@endforeach</div></div></div>
      <div class="scroll-x mt-2">@foreach($s->products->take(5) as $p)<a href="{{ route('products.show', $p) }}" title="{{ $p->name }}">@include('partials.thumb', ['item' => $p])</a>@endforeach</div>
      <div class="row between mt-1"><span class="small muted">{{ $s->products->count() }} items · {{ $s->packages_count }} packages</span><a class="btn btn-sm btn-outline" href="{{ route('suppliers.show', $s) }}">Visit shop</a></div>
    </div>
  @empty
    @include('partials.empty', ['emoji' => '🏪', 'title' => 'No suppliers found', 'text' => __('Try a shorter name, another city, or clear the search.')])
  @endforelse
</div>
@endsection
