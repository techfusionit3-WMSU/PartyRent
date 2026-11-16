@extends('layouts.market', ['title' => $landing ? 'Party Supplies Rental Marketplace' : $heading, 'nav' => 'home'])

@php
    $opt = fn ($v, $l, $cur) => '<option value="'.e($v).'"'.((string) $v === (string) $cur ? ' selected' : '').'>'.e($l).'</option>';
    $qs = fn (array $over) => route('home', array_filter(array_merge($st, $over), fn ($v) => $v !== '' && $v !== null && $v !== 'recommended' && $v !== 'items'));
    $labels = ['q' => 'Keyword', 'category' => 'Category', 'supplier' => 'Supplier', 'city' => 'Location', 'event' => 'Event', 'color' => 'Color', 'theme' => 'Theme', 'min' => 'Min ₱', 'max' => 'Max ₱', 'qty' => 'Qty', 'avail' => 'Available only', 'delivery' => 'Delivery', 'pickup' => 'Pickup', 'rating' => 'Rating ≥'];
    $filterCount = count(array_filter(array_diff_key($st, ['q' => 1, 'start' => 1, 'end' => 1, 'qty' => 1, 'sort' => 1, 'view' => 1]), fn ($v) => $v !== ''));
@endphp

{{-- Sticky bar under the header: dates and quantity are the first thing every renter sets. --}}
@section('subheader')
<div class="date-bar"><div class="container">
  <form method="GET" action="{{ route('home') }}" class="date-bar-form">
    @foreach(array_diff_key($st, ['start' => 1, 'end' => 1, 'qty' => 1]) as $k => $v)@if($v !== '' && ! ($k === 'sort' && $v === 'recommended') && ! ($k === 'view' && $v === 'items'))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
    <label class="db-field"><span>From</span><input class="input input-sm" type="date" name="start" min="{{ today_iso() }}" value="{{ $st['start'] }}" aria-label="Start date" data-autosubmit></label>
    <label class="db-field"><span>To</span><input class="input input-sm" type="date" name="end" min="{{ $st['start'] ?: today_iso() }}" value="{{ $st['end'] }}" aria-label="End date" data-autosubmit></label>
    <label class="db-field db-qty"><span>Qty/day</span><input class="input input-sm" type="number" min="1" name="qty" value="{{ $st['qty'] }}" placeholder="Any" aria-label="Quantity needed per day"></label>
    <button class="btn btn-sm btn-primary db-go" aria-label="Show availability">{{ icon('search') }}<span class="hide-xs">Check</span></button>
  </form>
  <div class="date-bar-tools">
    <div class="seg"><a href="{{ $qs(['view' => 'items']) }}"><button type="button" class="{{ $st['view'] !== 'packages' ? 'active' : '' }}">Items</button></a><a href="{{ $qs(['view' => 'packages']) }}"><button type="button" class="{{ $st['view'] === 'packages' ? 'active' : '' }}">Packages</button></a></div>
    <button class="btn btn-sm hide-lg" type="button" data-modal="filterModal">{{ icon('filter') }}Filters @if($filterCount)<span class="badge b-pink">{{ $filterCount }}</span>@endif</button>
    <form method="GET" action="{{ route('home') }}">@foreach(array_diff_key($st, ['sort' => 1]) as $k => $v)@if($v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
      <select class="input input-sm" name="sort" style="width:auto" aria-label="Sort" data-autosubmit>{!! collect([['recommended', 'Recommended'], ['price_asc', 'Lowest price'], ['price_desc', 'Highest price'], ['popular', 'Most popular'], ['recent', 'Recently added'], ['rating', 'Highest rated']])->map(fn ($o) => $opt($o[0], $o[1], $st['sort']))->implode('') !!}</select>
    </form>
  </div>
</div></div>
@endsection

@section('content')
@if($landing)
  <section class="hero hero-compact">
    <svg class="confetti" viewBox="0 0 400 400" preserveAspectRatio="none" style="width:100%;height:100%">{{ confetti_svg() }}</svg>
    <div>
      <h1>{{ auth()->check() ? 'Hi '.auth()->user()->firstName().', what are we celebrating?' : 'Rent everything your party needs.' }}</h1>
      <p class="mb-0">{{ $supCount }} verified suppliers · {{ $prodCount }} items · {{ $pkgs->count() }} packages — with real-time availability for every date.</p>
      <form class="hero-ai mt-2" method="GET" action="{{ route('ai') }}">
        <label for="heroAi" class="small strong" style="display:block;margin-bottom:6px">Describe your event — English or Filipino</label>
        <div class="row" style="gap:8px;align-items:stretch"><textarea id="heroAi" name="q" rows="1" style="min-height:44px" placeholder="e.g. Outdoor birthday for 50 guests, pink theme, on {{ fmt_date(add_days(today_iso(), 13), false) }}"></textarea><button class="btn" style="background:#fff;color:var(--brand);border-color:#fff;white-space:nowrap">{{ icon('sparkle') }}Plan with AI</button></div>
      </form>
    </div>
  </section>

  @if($active->isNotEmpty() || $notifs->isNotEmpty())
  <section class="section grid grid-2">
    @if($active->isNotEmpty())
      <div class="card"><div class="card-head"><h3>Your upcoming rentals</h3><a class="small strong" href="{{ route('rentals.index') }}">View all</a></div>
        @foreach($active as $r)
          <a href="{{ route('rentals.show', $r) }}" class="row between" style="padding:10px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><div><div class="strong">{{ $r->event_name }}</div><div class="small muted">{{ $r->code }} · {{ fmt_range($r->start_date, $r->end_date) }}</div></div><div class="stack-sm" style="align-items:flex-end">@include('partials.badges', ['status' => $r->status])@include('partials.badges', ['pay' => $r->fin()['status']])</div></a>
        @endforeach
      </div>
    @endif
    @if($notifs->isNotEmpty())
      <div class="card"><div class="card-head"><h3>New for you</h3><a class="small strong" href="{{ route('notifications') }}">All notifications</a></div>
        @foreach($notifs as $n)@include('partials.notif-item', ['n' => $n])@endforeach
      </div>
    @endif
  </section>
  @endif

  <section class="section"><div class="section-title"><h2>Shop by event</h2></div>
    <div class="scroll-x event-row">
      @foreach($themes as [$e, $em, $bg])
        <a class="theme-card" style="background:{{ $bg }}" href="{{ $qs(['event' => $e]) }}"><span class="te">{{ $em }}</span><b>{{ $e }}</b></a>
      @endforeach
    </div>
  </section>

  @if($pkgs->isNotEmpty())
  <section class="section"><div class="section-title"><h2>Popular rental packages</h2><a class="small strong" href="{{ $qs(['view' => 'packages']) }}">See all packages</a></div>
    <div class="product-grid">@foreach($pkgs->take(4) as $k)@include('partials.package-card', ['k' => $k])@endforeach</div>
  </section>
  @endif
@else
  <div class="crumbs"><a href="{{ route('home') }}">Home</a> / {{ $cat ? $cat->name : ($st['q'] ? 'Search' : 'Browse') }}</div>
@endif

<div class="section-title mt-2" id="results"><div><h2 class="mb-0">{{ $heading }}</h2><p class="small muted mb-0">{{ $list->count() }} {{ $st['view'] === 'packages' ? 'packages' : 'items' }}{{ $dates ? ' · availability for '.fmt_range($dates[0], end($dates)).($st['qty'] ? ' (need '.$st['qty'].'/day)' : '') : ' · pick your dates above to see real availability' }}</p></div></div>

<div class="browse-layout">
  <aside class="card browse-filters">@include('market._filters')</aside>
  <div>
    <div class="row wrap mb-2" style="gap:6px">
      @foreach($labels as $k => $l)
        @if($st[$k] !== '')
          @php($v = $k === 'category' ? (optional($cats->firstWhere('id', $st[$k]))->name) : ($k === 'supplier' ? optional($sups->firstWhere('id', (int) $st[$k]))->name : $st[$k]))
          <a class="chip" href="{{ $qs([$k => '']) }}">{{ $l }}{{ in_array($k, ['avail', 'delivery', 'pickup'], true) ? '' : ': '.$v }} <span class="x">×</span></a>
        @endif
      @endforeach
      @if($st['start'])<a class="chip" href="{{ $qs(['start' => '', 'end' => '']) }}">Dates: {{ fmt_range($st['start'], $st['end']) }} <span class="x">×</span></a>@endif
    </div>
    @if($ai && ($ai['guests'] || $ai['eventType'] || $ai['start'] || count($ai['items']) > 1))
      <div class="alert alert-ai mb-2">{{ icon('sparkle') }}<div>Looks like you're describing an event. <a href="{{ route('ai', ['q' => $st['q']]) }}" class="strong">Let the AI planner build a full checklist →</a></div></div>
    @endif

    @if($compare->isNotEmpty())
      <div class="card mb-2"><div class="card-head"><h3>Compare items</h3><a class="btn btn-xs btn-ghost" href="{{ $qs([]) }}">Close</a></div>
        <div class="table-wrap"><table class="table"><thead><tr><th></th>@foreach($compare as $p)<th style="text-transform:none;font-size:.86rem;color:var(--ink)">{{ $p->name }}</th>@endforeach</tr></thead><tbody>
          <tr><td class="muted strong">Supplier</td>@foreach($compare as $p)<td>{{ $p->supplier->name }}</td>@endforeach</tr>
          <tr><td class="muted strong">Location</td>@foreach($compare as $p)<td>{{ $p->supplier->city }}</td>@endforeach</tr>
          <tr><td class="muted strong">Daily price</td>@foreach($compare as $p)<td>{{ money($p->basePrice()) }}@if($p->bulkLine())<div class="small muted">{{ $p->bulkLine() }}</div>@endif</td>@endforeach</tr>
          <tr><td class="muted strong">Available {{ $dates ? fmt_range($dates[0], end($dates)) : 'today' }}</td>@foreach($compare as $p)<td><b>{{ inv()->minAvailable($p->id, $dates) }}</b> of {{ $p->total_stock }}</td>@endforeach</tr>
          <tr><td class="muted strong">Rating</td>@foreach($compare as $p)<td>★ {{ rating_text($p) }} · {{ $p->rent_count }} rentals</td>@endforeach</tr>
          <tr><td class="muted strong">Fulfillment</td>@foreach($compare as $p)<td>{{ $p->supplier->fulfillmentLabel() }}</td>@endforeach</tr>
          <tr><td class="muted strong">Payment</td>@foreach($compare as $p)<td>{{ collect(\App\Domain\Finance::plansOf($p->supplier))->map(fn ($a) => \App\Domain\Finance::PLAN_LABEL[$a])->implode(', ') }}</td>@endforeach</tr>
          <tr><td></td>@foreach($compare as $p)<td><a class="btn btn-sm btn-primary" href="{{ route('products.show', array_filter(['product' => $p->id, 'start' => $dates[0] ?? null, 'end' => $dates ? end($dates) : null])) }}">View</a></td>@endforeach</tr>
        </tbody></table></div>
      </div>
    @endif

    @if($list->isEmpty())
      @include('partials.empty', $st['view'] === 'packages' ? ['emoji' => '📦', 'title' => 'No packages match', 'text' => 'Try clearing some filters.'] : ['emoji' => '🔍', 'title' => 'No items match your filters', 'text' => 'Try different dates, a wider price range, or ask the AI planner for alternatives.', 'action' => '<a class="btn btn-primary" href="'.e(route('ai', array_filter(['q' => $st['q']]))).'">'.icon('sparkle').'Ask AI for suggestions</a>'])
    @elseif($st['view'] === 'packages')
      <div class="product-grid">@foreach($list as $k)@include('partials.package-card', ['k' => $k, 'dates' => $dates])@endforeach</div>
    @else
      <form id="compareForm" method="GET" action="{{ route('home') }}#results">@foreach($st as $k => $v)@if($v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach</form>
      <div class="product-grid">@foreach($list as $p)@include('partials.product-card', ['p' => $p, 'dates' => $dates, 'compare' => true])@endforeach</div>
      <div style="position:sticky;bottom:calc(var(--bottomnav-h) + 10px);display:flex;justify-content:center;margin-top:16px"><button form="compareForm" class="btn btn-sm" style="background:var(--ink);color:#fff;border-radius:999px">{{ icon('layers') }}Compare selected (up to 3)</button></div>
    @endif
  </div>
</div>

@if(! $landing || ! auth()->check() || ! auth()->user()->supplier)
@if($landing)
<section class="section card soft row wrap between" style="padding:28px">
  <div><h2 class="mb-0">Have party supplies at home or a rental business?</h2><p class="muted mb-0">Start selling on PartyRent using your same account — no new sign-up needed.</p></div>
  <a class="btn btn-primary btn-lg" href="{{ route('start-selling') }}">{{ icon('store') }}Start Selling</a>
</section>
@endif
@endif

<div class="modal-backdrop hidden" id="filterModal"><div class="modal" role="dialog" aria-modal="true" aria-label="Filters"><div class="modal-head"><h3>Filters</h3><button class="icon-btn" data-close aria-label="Close">{{ icon('x') }}</button></div><div class="modal-body">@include('market._filters')</div></div></div>
@endsection
