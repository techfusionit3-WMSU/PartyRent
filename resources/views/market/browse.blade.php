@extends('layouts.market', ['title' => $landing ? 'Party Supplies Rental Marketplace' : $heading, 'nav' => 'home'])

{{-- Change list: reference index.html (C.home) and customer/browse.html (C.browse) merged into one page.
     Home sections show on the landing view; the browse block below keeps the rental-date bar and the
     Filters / Sort row sticky. Filters open in a side panel (reference UI.drawer). --}}
@php
    $qs = fn (array $over) => route('home', array_filter(array_merge($st, $over), fn ($v) => $v !== '' && $v !== null && $v !== 'recommended' && $v !== 'items'));
    $labels = ['q' => 'Keyword', 'category' => 'Category', 'supplier' => 'Supplier', 'city' => 'Location', 'event' => 'Event', 'color' => 'Color', 'theme' => 'Theme', 'min' => 'Min ₱', 'max' => 'Max ₱', 'qty' => 'Qty', 'avail' => 'Available only', 'delivery' => 'Delivery', 'pickup' => 'Pickup', 'rating' => 'Rating ≥'];
    $filterCount = count(array_filter(array_intersect_key($st, $labels), fn ($v) => $v !== ''));
    $u = auth()->user();
    $nDays = $st['start'] ? diff_days($st['start'], $st['end']) + 1 : 0;
@endphp

@section('content')
@if($landing)
  <section class="hero"><svg class="confetti" viewBox="0 0 400 400" preserveAspectRatio="none" style="width:100%;height:100%">{{ confetti_svg() }}</svg>
    <div><span class="badge plain" style="background:rgba(255,255,255,.2);color:#fff">{{ icon('sparkle', 'width:14px') }} AI-assisted party rentals</span>
      <h1 class="mt-1">{{ $u ? 'Hi '.$u->firstName().', what are we celebrating?' : 'Rent everything your party needs.' }}</h1>
      <p>Chairs, tables, tents, sound, lights and décor from trusted local suppliers — with real-time availability for every date.</p>
      <form class="hero-ai mt-2" method="GET" action="{{ route('ai') }}"><label for="heroAi" class="small strong" style="display:block;margin-bottom:6px">Describe your event — English or Filipino</label>
        <textarea id="heroAi" name="q" placeholder="e.g. Outdoor birthday party for 50 guests, need chairs, tables, a tent, pink decorations and a sound system on {{ fmt_date(add_days(today_iso(), 13), false) }}"></textarea>
        <div class="row wrap mt-1"><button class="btn btn-lg" style="background:#fff;color:var(--brand);border-color:#fff">{{ icon('sparkle') }}Plan with AI</button><a class="btn btn-lg" style="background:transparent;color:#fff;border-color:rgba(255,255,255,.6)" href="#results">Browse all items</a></div></form>
      <div class="hero-stats"><div><b>{{ $supCount }}</b><span>verified suppliers</span></div><div><b>{{ $prodCount }}</b><span>rental items</span></div><div><b>{{ $pkgCount }}</b><span>party packages</span></div></div>
    </div>
    <div class="hero-art">🎉<div style="font-size:3.4rem;margin-top:-10px">🎈🪑🎪</div></div>
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

  <section class="section"><div class="section-title"><h2>Shop by category</h2><a class="small strong" href="#results">See all</a></div><div class="cat-grid">
    @foreach($cats as $c)<a class="cat-card" href="{{ $qs(['category' => $c->id]) }}#results"><div class="ce" style="background:linear-gradient(135deg,{{ $c->grad_from }},{{ $c->grad_to }})">{{ art($c->icon, '62%') }}</div><div class="cn">{{ $c->name }}</div><div class="cc">{{ $list->where('category_id', $c->id)->count() }} items</div></a>@endforeach
  </div></section>
  <section class="section"><div class="section-title"><h2>Shop by event</h2></div><div class="grid grid-3">
    @foreach($themes as [$e, $em, $bg])<a class="theme-card" style="background:{{ $bg }}" href="{{ $qs(['event' => $e]) }}#results"><span class="te">{{ $em }}</span><b>{{ $e }}</b><span>{{ $list->filter(fn ($p) => in_array($e, $p->events ?? [], true))->count() }} items · {{ $pkgs->filter(fn ($k) => in_array($e, $k->events ?? [], true))->count() }} packages</span></a>@endforeach
  </div></section>
  @foreach([['Featured rentals', $home['featured'], ['sort' => 'rating']], [$u ? 'Recommended for you' : 'Most rented this season', $home['recommended'], ['sort' => 'popular']]] as [$title, $items, $more])
    <section class="section"><div class="section-title"><h2>{{ $title }}</h2><a class="small strong" href="{{ $qs($more) }}#results">See more</a></div><div class="product-grid">@foreach($items as $p)@include('partials.product-card', ['p' => $p])@endforeach</div></section>
  @endforeach
  @if($pkgs->isNotEmpty())<section class="section"><div class="section-title"><h2>Popular rental packages</h2><a class="small strong" href="{{ route('packages.index') }}">See more</a></div><div class="product-grid">@foreach($pkgs->take(4) as $k)@include('partials.package-card', ['k' => $k])@endforeach</div></section>@endif
  <section class="section"><div class="section-title"><h2>Featured suppliers</h2><a class="small strong" href="{{ route('suppliers.index') }}">All suppliers</a></div><div class="grid grid-3">@foreach($home['topSuppliers'] as $s)@include('partials.supplier-card', ['s' => $s])@endforeach</div></section>
  @foreach([['Special bulk offers 🎁', $home['offers'], []], ['Recently added', $home['recent'], ['sort' => 'recent']]] as [$title, $items, $more])
    <section class="section"><div class="section-title"><h2>{{ $title }}</h2><a class="small strong" href="{{ $qs($more) }}#results">See more</a></div><div class="product-grid">@foreach($items as $p)@include('partials.product-card', ['p' => $p])@endforeach</div></section>
  @endforeach
@else
  <div class="crumbs"><a href="{{ route('home') }}">Home</a> / {{ $cat ? $cat->name : ($st['q'] ? 'Search' : 'Browse') }}</div>
@endif

{{-- ================= browse ================= --}}
<div class="page-head mt-2" id="results"><div><h1>{{ $landing ? 'Browse party supplies' : $heading }}</h1><p>{{ $list->count() }} {{ $st['view'] === 'packages' ? 'packages' : 'items' }}{{ $dates ? ' · availability for '.fmt_range($dates[0], end($dates)).($st['qty'] ? ' (need '.$st['qty'].'/day)' : '') : ' · select rental dates to see available stock' }}</p></div></div>
<div class="browse-sticky">
  <form class="card date-bar" id="dateBar" aria-label="Rental dates" method="GET" action="{{ route('home') }}#results">
    @foreach(array_diff_key($st, ['start' => 1, 'end' => 1]) as $k => $v)@if($v !== '' && ! ($k === 'sort' && $v === 'recommended') && ! ($k === 'view' && $v === 'items'))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
    <span class="db-label">{{ icon('calendar') }}Rental dates</span>
    <div class="db-inputs"><input class="input input-sm" type="date" name="start" min="{{ today_iso() }}" value="{{ $st['start'] }}" aria-label="Start date" data-autosubmit><span class="muted">–</span><input class="input input-sm" type="date" name="end" min="{{ $st['start'] ?: today_iso() }}" value="{{ $st['end'] }}" aria-label="End date" data-autosubmit></div>
    <div class="db-summary" aria-live="polite">@if($st['start'])<span>{{ \App\Support\RentalPeriod::rangeLabel($st['start'], $st['end']) }}</span><span class="db-sep">|</span><span class="badge b-purple plain">{{ \App\Support\RentalPeriod::durationLabel($nDays) }}</span>@else<span class="small muted" style="font-weight:500">Select dates to see available stock for your rental period</span>@endif</div>
    @if($st['start'])<a class="btn btn-sm btn-ghost" href="{{ $qs(['start' => '', 'end' => '', 'clear' => 1]) }}#results">{{ icon('x') }}Clear dates</a>@endif
  </form>
  <div class="browse-tools">
    <div class="seg"><a href="{{ $qs(['view' => 'items']) }}#results"><button type="button" class="{{ $st['view'] !== 'packages' ? 'active' : '' }}">Items</button></a><a href="{{ $qs(['view' => 'packages']) }}#results"><button type="button" class="{{ $st['view'] === 'packages' ? 'active' : '' }}">Packages</button></a></div>
    <div class="row">
      <button class="btn btn-sm" type="button" data-drawer="filterDrawer" aria-haspopup="dialog">{{ icon('filter') }}Filters @if($filterCount)<span class="filter-count">{{ $filterCount }}</span>@endif</button>
      <form method="GET" action="{{ route('home') }}#results">@foreach(array_diff_key($st, ['sort' => 1]) as $k => $v)@if($v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
        <select class="input input-sm" name="sort" style="width:auto" aria-label="Sort" data-autosubmit>@foreach([['recommended', 'Recommended'], ['price_asc', 'Lowest price'], ['price_desc', 'Highest price'], ['popular', 'Most popular'], ['recent', 'Recently added'], ['rating', 'Highest rated']] as [$v, $l])<option value="{{ $v }}" @selected($st['sort'] === $v)>{{ $l }}</option>@endforeach</select></form>
    </div>
  </div>
</div>

<div class="row wrap mb-2" style="gap:6px">
  @foreach($labels as $k => $l)
    @if($st[$k] !== '')
      @php($v = $k === 'category' ? optional($cats->firstWhere('id', $st[$k]))->name : ($k === 'supplier' ? optional($sups->firstWhere('id', (int) $st[$k]))->name : $st[$k]))
      <a class="chip" href="{{ $qs([$k => '']) }}#results">{{ $l }}{{ in_array($k, ['avail', 'delivery', 'pickup'], true) ? '' : ': '.$v }} <span class="x">×</span></a>
    @endif
  @endforeach
</div>
@if($ai && ($ai['guests'] || $ai['eventType'] || $ai['start'] || count($ai['items']) > 1))
  <div class="alert alert-ai mb-2">{{ icon('sparkle') }}<div>Looks like you're describing an event. <a href="{{ route('ai', ['q' => $st['q']]) }}" class="strong">Let the AI planner build a full checklist →</a></div></div>
@endif

@if($list->isEmpty())
  @include('partials.empty', $st['view'] === 'packages' ? ['emoji' => '📦', 'title' => 'No packages match', 'text' => 'Try clearing some filters.'] : ['emoji' => '🔍', 'title' => 'No items match your filters', 'text' => 'Try different dates, a wider price range, or ask the AI planner for alternatives.', 'action' => '<a class="btn btn-primary" href="'.route('ai', $st['q'] ? ['q' => $st['q']] : []).'">'.icon('sparkle').'Ask AI for suggestions</a>'])
@elseif($st['view'] === 'packages')
  <div class="product-grid">@foreach($list as $k)@include('partials.package-card', ['k' => $k, 'dates' => $dates])@endforeach</div>
@else
  <form id="compareForm" method="GET" action="{{ route('home') }}#results">@foreach($st as $k => $v)@if($v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach</form>
  <div class="product-grid">@foreach($list as $p)@include('partials.product-card', ['p' => $p, 'dates' => $dates, 'showCompare' => true, 'compareOn' => $compare->contains('id', $p->id)])@endforeach</div>
  <div id="compareBar" class="hidden" style="position:fixed;left:50%;transform:translateX(-50%);bottom:calc(var(--bottomnav-h) + 10px);z-index:880;background:var(--ink);color:#fff;border-radius:999px;padding:8px 8px 8px 18px;display:flex;gap:12px;align-items:center;box-shadow:var(--shadow-lg)"><span class="small strong" id="compareN"></span><button form="compareForm" class="btn btn-sm btn-primary">Compare</button><button type="button" class="btn btn-sm btn-ghost" style="color:#fff" id="compareClear">Clear</button></div>
@endif

@if($landing && (! $u || ! $u->supplier))
<section class="section card soft row wrap between" style="padding:28px">
  <div><h2 class="mb-0">Have party supplies at home or a rental business?</h2><p class="muted mb-0">Start selling on PartyRent using your same account — no new sign-up needed.</p></div>
  <a class="btn btn-primary btn-lg" href="{{ route('start-selling') }}">{{ icon('store') }}Start Selling</a>
</section>
@endif

{{-- Filters side panel --}}
<div class="drawer-backdrop hidden" data-drawer-close="filterDrawer"></div>
<aside class="drawer hidden" id="filterDrawer" role="dialog" aria-modal="true" aria-label="Filters">
  <div class="drawer-head"><h3 class="mb-0">Filters</h3><button class="icon-btn" type="button" data-drawer-close="filterDrawer" aria-label="Close">{{ icon('x') }}</button></div>
  <div class="drawer-body">@include('market._filters')</div>
  <div class="drawer-foot"><a class="btn" href="{{ route('home', array_filter(['view' => $st['view'] === 'packages' ? 'packages' : null, 'sort' => $st['sort'] !== 'recommended' ? $st['sort'] : null])) }}#results">Clear all</a><button class="btn btn-primary" form="ff">Show results</button></div>
</aside>

@if($compare->isNotEmpty())
<div class="modal-backdrop" id="compareModal"><div class="modal lg" role="dialog" aria-modal="true"><div class="modal-head"><h3>Compare items</h3><a class="icon-btn" href="{{ $qs([]) }}#results" aria-label="Close">{{ icon('x') }}</a></div><div class="modal-body">
  <div class="table-wrap"><table class="table"><thead><tr><th></th>@foreach($compare as $p)<th style="text-transform:none;font-size:.86rem;color:var(--ink)">{{ $p->name }}</th>@endforeach</tr></thead><tbody>
    <tr><td class="muted strong">Supplier</td>@foreach($compare as $p)<td>{{ $p->supplier->name }}</td>@endforeach</tr>
    <tr><td class="muted strong">Location</td>@foreach($compare as $p)<td>{{ $p->supplier->city }}</td>@endforeach</tr>
    <tr><td class="muted strong">Daily price</td>@foreach($compare as $p)<td>{{ money($p->basePrice()) }}@if(count($p->tiers ?? []) > 1)<div class="small muted">from {{ money(\App\Domain\Pricing::lowestPrice($p)) }} in bulk</div>@endif</td>@endforeach</tr>
    <tr><td class="muted strong">Available{{ $dates ? ' '.fmt_range($dates[0], end($dates)) : '' }}</td>@foreach($compare as $p)<td>@if($dates)<b>{{ inv()->minAvailable($p->id, $dates) }}</b> for your dates @else<span class="muted">Select rental dates</span>@endif</td>@endforeach</tr>
    <tr><td class="muted strong">Rental limit</td>@foreach($compare as $p)<td>{{ $p->max_rental_days ? 'Maximum Rental: '.$p->max_rental_days.' Day'.($p->max_rental_days > 1 ? 's' : '') : 'No Rental Duration Limit' }}</td>@endforeach</tr>
    <tr><td class="muted strong">Rating</td>@foreach($compare as $p)<td>★ {{ rating_text($p) }} · {{ $p->rent_count }} rentals</td>@endforeach</tr>
    <tr><td class="muted strong">Fulfillment</td>@foreach($compare as $p)<td>{{ $p->supplier->fulfillmentLabel() }}</td>@endforeach</tr>
    <tr><td class="muted strong">Payment plans</td>@foreach($compare as $p)<td>{{ collect(\App\Domain\Finance::plansOf($p->supplier))->map(fn ($a) => \App\Domain\Finance::PLAN_LABEL[$a])->implode(', ') }}</td>@endforeach</tr>
    <tr><td class="muted strong">Colors</td>@foreach($compare as $p)<td>@foreach($p->colors ?? [] as $c)<span class="swatch" style="background:{{ color_hex($c) }}"></span> @endforeach{{ implode(', ', $p->colors ?? []) }}</td>@endforeach</tr>
    <tr><td></td>@foreach($compare as $p)<td><a class="btn btn-sm btn-primary" href="{{ route('products.show', array_filter(['product' => $p->id, 'start' => $dates[0] ?? null, 'end' => $dates ? end($dates) : null])) }}">View</a></td>@endforeach</tr>
  </tbody></table></div>
</div></div></div>
@endif
@endsection
