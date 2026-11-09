@extends('layouts.market', ['title' => $heading, 'nav' => 'browse'])

@php
    $opt = fn ($v, $l, $cur) => '<option value="'.e($v).'"'.((string) $v === (string) $cur ? ' selected' : '').'>'.e($l).'</option>';
    $qs = fn (array $over) => route('browse', array_filter(array_merge($st, $over), fn ($v) => $v !== '' && $v !== null && $v !== 'recommended' && $v !== 'items'));
    $labels = ['q' => 'Keyword', 'category' => 'Category', 'supplier' => 'Supplier', 'city' => 'Location', 'event' => 'Event', 'color' => 'Color', 'theme' => 'Theme', 'min' => 'Min ₱', 'max' => 'Max ₱', 'start' => 'From', 'end' => 'To', 'qty' => 'Qty', 'avail' => 'Available only', 'delivery' => 'Delivery', 'pickup' => 'Pickup', 'rating' => 'Rating ≥'];
@endphp

@section('content')
<div class="crumbs"><a href="{{ route('home') }}">Home</a> / {{ $cat ? $cat->name : ($st['q'] ? 'Search' : 'Browse') }}</div>
<div class="page-head">
  <div><h1>{{ $heading }}</h1><p>{{ $list->count() }} {{ $st['view'] === 'packages' ? 'packages' : 'items' }}{{ $dates ? ' · availability for '.fmt_range($dates[0], end($dates)).($st['qty'] ? ' (need '.$st['qty'].'/day)' : '') : ' · availability shown for today' }}</p></div>
  <div class="row wrap">
    <div class="seg"><a href="{{ $qs(['view' => 'items']) }}"><button type="button" class="{{ $st['view'] !== 'packages' ? 'active' : '' }}">Items</button></a><a href="{{ $qs(['view' => 'packages']) }}"><button type="button" class="{{ $st['view'] === 'packages' ? 'active' : '' }}">Packages</button></a></div>
    <button class="btn btn-sm hide-lg" type="button" data-modal="filterModal">{{ icon('filter') }}Filters</button>
    <form method="GET" action="{{ route('browse') }}">@foreach(array_diff_key($st, ['sort' => 1]) as $k => $v)@if($v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
      <select class="input input-sm" name="sort" style="width:auto" aria-label="Sort" data-autosubmit>{!! collect([['recommended', 'Recommended'], ['price_asc', 'Lowest price'], ['price_desc', 'Highest price'], ['popular', 'Most popular'], ['recent', 'Recently added'], ['rating', 'Highest rated']])->map(fn ($o) => $opt($o[0], $o[1], $st['sort']))->implode('') !!}</select>
    </form>
  </div>
</div>

<div class="browse-layout" style="display:grid;grid-template-columns:270px minmax(0,1fr);gap:20px;align-items:start">
  <aside class="card browse-filters" style="position:sticky;top:calc(var(--header-h) + 60px)">@include('market._filters')</aside>
  <div>
    <div class="row wrap mb-2" style="gap:6px">
      @foreach($labels as $k => $l)
        @if($st[$k] !== '')
          @php($v = $k === 'category' ? (optional($cats->firstWhere('id', $st[$k]))->name) : ($k === 'supplier' ? optional($sups->firstWhere('id', (int) $st[$k]))->name : (in_array($k, ['start', 'end'], true) ? fmt_date($st[$k]) : $st[$k])))
          <a class="chip" href="{{ $qs([$k => '']) }}">{{ $l }}{{ in_array($k, ['avail', 'delivery', 'pickup'], true) ? '' : ': '.$v }} <span class="x">×</span></a>
        @endif
      @endforeach
    </div>
    @if($ai && ($ai['guests'] || $ai['eventType'] || $ai['start'] || count($ai['items']) > 1))
      <div class="alert alert-ai mb-2">{{ icon('sparkle') }}<div>Looks like you're describing an event. <a href="{{ route('ai', ['q' => $st['q']]) }}" class="strong">Let the AI planner build a full checklist →</a></div></div>
    @endif

    @if($compare->isNotEmpty())
      <div class="card mb-2"><div class="card-head"><h3>Compare items</h3><a class="btn btn-xs btn-ghost" href="{{ $qs([]) }}">Close</a></div>
        <div class="table-wrap"><table class="table"><thead><tr><th></th>@foreach($compare as $p)<th style="text-transform:none;font-size:.86rem;color:var(--ink)">{{ $p->name }}</th>@endforeach</tr></thead><tbody>
          <tr><td class="muted strong">Supplier</td>@foreach($compare as $p)<td>{{ $p->supplier->name }}</td>@endforeach</tr>
          <tr><td class="muted strong">Location</td>@foreach($compare as $p)<td>{{ $p->supplier->city }}</td>@endforeach</tr>
          <tr><td class="muted strong">Daily price</td>@foreach($compare as $p)<td>{{ money($p->basePrice()) }}@if(count($p->tiers) > 1)<div class="small muted">from {{ money($p->lowestPrice()) }} in bulk</div>@endif</td>@endforeach</tr>
          <tr><td class="muted strong">Available {{ $dates ? fmt_range($dates[0], end($dates)) : 'today' }}</td>@foreach($compare as $p)<td><b>{{ inv()->minAvailable($p->id, $dates) }}</b> of {{ $p->total_stock }}</td>@endforeach</tr>
          <tr><td class="muted strong">Rating</td>@foreach($compare as $p)<td>★ {{ rating_text($p) }} · {{ $p->rent_count }} rentals</td>@endforeach</tr>
          <tr><td class="muted strong">Fulfillment</td>@foreach($compare as $p)<td>{{ $p->supplier->fulfillmentLabel() }}</td>@endforeach</tr>
          <tr><td class="muted strong">Payment</td>@foreach($compare as $p)<td>{{ collect($p->supplier->arrangements)->map(fn ($a) => \App\Models\Payment::ARR_LABEL[$a])->implode(', ') }}</td>@endforeach</tr>
          <tr><td></td>@foreach($compare as $p)<td><a class="btn btn-sm btn-primary" href="{{ route('products.show', array_filter(['product' => $p->id, 'start' => $dates[0] ?? null, 'end' => $dates ? end($dates) : null])) }}">View</a></td>@endforeach</tr>
        </tbody></table></div>
      </div>
    @endif

    @if($list->isEmpty())
      @include('partials.empty', $st['view'] === 'packages' ? ['emoji' => '📦', 'title' => 'No packages match', 'text' => 'Try clearing some filters.'] : ['emoji' => '🔍', 'title' => 'No items match your filters', 'text' => 'Try different dates, a wider price range, or ask the AI planner for alternatives.', 'action' => '<a class="btn btn-primary" href="'.e(route('ai', array_filter(['q' => $st['q']]))).'">'.icon('sparkle').'Ask AI for suggestions</a>'])
    @elseif($st['view'] === 'packages')
      <div class="product-grid">@foreach($list as $k)@include('partials.package-card', ['k' => $k, 'dates' => $dates])@endforeach</div>
    @else
      <form id="compareForm" method="GET" action="{{ route('browse') }}">@foreach($st as $k => $v)@if($v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach</form>
      <div class="product-grid">@foreach($list as $p)@include('partials.product-card', ['p' => $p, 'dates' => $dates, 'compare' => true])@endforeach</div>
      <div style="position:sticky;bottom:calc(var(--bottomnav-h) + 10px);display:flex;justify-content:center;margin-top:16px"><button form="compareForm" class="btn btn-sm" style="background:var(--ink);color:#fff;border-radius:999px">{{ icon('layers') }}Compare selected (up to 3)</button></div>
    @endif
  </div>
</div>

<div class="modal-backdrop hidden" id="filterModal"><div class="modal" role="dialog" aria-modal="true" aria-label="Filters"><div class="modal-head"><h3>Filters</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div><div class="modal-body">@include('market._filters')</div></div></div>

@push('head')<style>@media (max-width:1024px){.browse-layout{grid-template-columns:1fr!important}.browse-filters{display:none}}@media (min-width:1025px){.hide-lg{display:none}}</style>@endpush
@endsection
