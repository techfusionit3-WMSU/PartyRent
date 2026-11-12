@extends('layouts.market', ['title' => 'Party Supplies Rental Marketplace', 'nav' => 'home'])

@section('content')
<section class="hero">
  <svg class="confetti" viewBox="0 0 400 400" preserveAspectRatio="none" style="width:100%;height:100%">{{ confetti_svg() }}</svg>
  <div>
    <span class="badge plain" style="background:rgba(255,255,255,.2);color:#fff">{{ icon('sparkle', 'width:14px') }} AI-assisted party rentals</span>
    <h1 class="mt-1">{{ auth()->check() ? 'Hi '.auth()->user()->firstName().', what are we celebrating?' : 'Rent everything your party needs.' }}</h1>
    <p>Chairs, tables, tents, sound, lights and décor from trusted local rental partners — with real-time availability for every date.</p>
    <form class="hero-ai mt-2" method="GET" action="{{ route('ai') }}">
      <label for="heroAi" class="small strong" style="display:block;margin-bottom:6px">Describe your event — English or Filipino</label>
      <textarea id="heroAi" name="q" placeholder="e.g. Outdoor birthday party for 50 guests, need chairs, tables, a tent, pink decorations and a sound system on {{ fmt_date(add_days(today_iso(), 13), false) }}"></textarea>
      <div class="row wrap mt-1"><button class="btn btn-lg" style="background:#fff;color:var(--brand);border-color:#fff">{{ icon('sparkle') }}Plan with AI</button><a class="btn btn-lg" style="background:transparent;color:#fff;border-color:rgba(255,255,255,.6)" href="{{ route('browse') }}">Browse all items</a></div>
    </form>
    <div class="hero-stats"><div><b>{{ $sups->count() }}</b><span>verified rental partners</span></div><div><b>{{ $prods->count() }}</b><span>rental items</span></div><div><b>{{ $pkgs->count() }}</b><span>party packages</span></div></div>
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

<section class="section"><div class="section-title"><h2>Shop by category</h2><a class="small strong" href="{{ route('browse') }}">See all</a></div>
  <div class="cat-grid">
    @foreach($cats as $c)
      <a class="cat-card" href="{{ route('browse', ['category' => $c->id]) }}"><div class="ce" style="background:{{ $c->gradient() }}">{{ art($c->icon, '62%') }}</div><div class="cn">{{ $c->name }}</div><div class="cc">{{ $prods->where('category_id', $c->id)->count() }} items</div></a>
    @endforeach
  </div>
</section>

<section class="section"><div class="section-title"><h2>Shop by event</h2></div>
  <div class="grid grid-3">
    @foreach($themes as [$e, $em, $bg])
      <a class="theme-card" style="background:{{ $bg }}" href="{{ route('browse', ['event' => $e]) }}"><span class="te">{{ $em }}</span><b>{{ $e }}</b><span>{{ $prods->filter(fn ($p) => in_array($e, $p->events ?? [], true))->count() }} items · {{ $pkgs->filter(fn ($k) => in_array($e, $k->events ?? [], true))->count() }} packages</span></a>
    @endforeach
  </div>
</section>

@include('market._section', ['title' => 'Featured rentals', 'items' => $featured, 'more' => route('browse', ['sort' => 'rating'])])
@include('market._section', ['title' => auth()->check() ? 'Recommended for you' : 'Most rented this season', 'items' => $recommended, 'more' => route('browse', ['sort' => 'popular'])])

<section class="section"><div class="section-title"><h2>Popular rental packages</h2><a class="small strong" href="{{ route('packages.index') }}">See more</a></div>
  <div class="product-grid">@foreach($pkgs->take(4) as $k)@include('partials.package-card', ['k' => $k])@endforeach</div>
</section>

<section class="section"><div class="section-title"><h2>Featured rental partners</h2><a class="small strong" href="{{ route('suppliers.index') }}">All rental partners</a></div>
  <div class="grid grid-3">@foreach($sups->sortByDesc('rating')->take(3) as $s)@include('partials.supplier-card', ['s' => $s])@endforeach</div>
</section>

@include('market._section', ['title' => 'Special bulk offers 🎁', 'items' => $offers, 'more' => route('browse')])
@include('market._section', ['title' => 'Recently added', 'items' => $recent, 'more' => route('browse', ['sort' => 'recent'])])

@if(! auth()->check() || ! auth()->user()->supplier)
<section class="section card soft row wrap between" style="padding:28px">
  <div><h2 class="mb-0">Have party supplies at home or a rental business?</h2><p class="muted mb-0">Start selling on PartyRent using your same account — no new sign-up needed.</p></div>
  <a class="btn btn-primary btn-lg" href="{{ route('start-selling') }}">{{ icon('store') }}Start Selling</a>
</section>
@endif
@endsection
