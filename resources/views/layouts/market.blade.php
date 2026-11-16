@extends('layouts.base')

@section('body')
@php($nav = $nav ?? '')
@php($cats = \App\Models\Category::where('active', true)->orderBy('sort')->get())
<header class="mk-header">
  <div class="container bar">
    @include('partials.brand')
    <form class="mk-search" role="search" action="{{ route('home') }}" method="GET">
      <span>{{ icon('search') }}</span>
      <input name="q" placeholder="Search chairs, tents, rental partners…" aria-label="Search" value="{{ request('q') }}">
      <button type="submit" formaction="{{ route('ai') }}" class="ai-toggle" title="Describe your event and let AI build the search">{{ icon('sparkle') }}<span>AI Search</span></button>
    </form>
    <nav class="mk-nav">
      @foreach([['home', 'home', 'Browse'], ['packages', 'packages.index', 'Packages'], ['suppliers', 'suppliers.index', 'Rental Partners'], ['rentals', 'rentals.index', 'My Rentals']] as [$k, $r, $l])
        <a href="{{ route($r) }}" class="{{ $nav === $k ? 'active' : '' }}">{{ $l }}</a>
      @endforeach
    </nav>
    <div class="mk-actions">
      @auth
        @include('partials.notif-dropdown')
        <a class="icon-btn" href="{{ route('cart') }}" aria-label="Rental list">{{ icon('cart') }}@if($shellCart)<span class="dot-count">{{ $shellCart }}</span>@endif</a>
        @include('partials.profile-menu')
      @else
        <a class="btn btn-ghost btn-sm hide-xs" href="{{ route('login') }}">Log in</a>
        <a class="btn btn-primary btn-sm" href="{{ route('register') }}">Sign up</a>
      @endauth
    </div>
  </div>
  @if(in_array($nav, ['home', 'browse'], true))
    <div class="cat-strip"><div class="container scroll-x">
      <a href="{{ route('home', array_filter(request()->only(['start', 'end', 'qty']))) }}" class="{{ ! request('category') ? 'active' : '' }}">All</a>
      @foreach($cats as $c)<a href="{{ route('home', array_filter(['category' => $c->id] + request()->only(['start', 'end', 'qty', 'view']))) }}" class="{{ request('category') === $c->id ? 'active' : '' }}">{{ $c->name }}</a>@endforeach
    </div></div>
  @endif
  @yield('subheader')
</header>

<div class="mk-main"><div class="container">
  @yield('content')
</div></div>

<footer class="mk-footer"><div class="container">
  <div class="cols">
    <div>@include('partials.brand')<p class="mt-2">The party supplies rental marketplace for Filipino celebrations — discover, schedule, and rent from trusted local rental partners.</p><p class="small">{{ setting('supportEmail') }} · {{ setting('supportPhone') }}</p></div>
    <div><h4>Rent</h4><ul><li><a href="{{ route('home') }}">Browse supplies</a></li><li><a href="{{ route('packages.index') }}">Rental packages</a></li><li><a href="{{ route('ai') }}">AI event planner</a></li></ul></div>
    <div><h4>Sell</h4><ul><li><a href="{{ route('start-selling') }}">Start selling</a></li><li><a href="{{ route('suppliers.index') }}">Our rental partners</a></li></ul></div>
    <div><h4>Account</h4><ul>@auth<li><a href="{{ route('rentals.index') }}">My rentals</a></li><li><a href="{{ route('notifications') }}">Notifications</a></li>@else<li><a href="{{ route('login') }}">Log in</a></li><li><a href="{{ route('register') }}">Create an account</a></li>@endauth</ul></div>
  </div>
  <hr style="border-color:rgba(255,255,255,.1)">
  <div class="small">© {{ date('Y') }} {{ setting('platformName', 'PartyRent') }} · Payments are verified by PartyRent cashiers. PartyRent Assist suggests items but never books, prices or reserves anything.</div>
</div></footer>

<nav class="bottom-nav" aria-label="Main">
  <a href="{{ route('home') }}" class="{{ in_array($nav, ['browse', 'home'], true) ? 'active' : '' }}">{{ icon('grid') }}Browse</a>
  <a href="{{ route('suppliers.index') }}" class="{{ $nav === 'suppliers' ? 'active' : '' }}">{{ icon('store') }}Rental Partners</a>
  <a href="{{ route('ai') }}" class="fab {{ $nav === 'ai' ? 'active' : '' }}" aria-label="AI Planner">{{ icon('sparkle') }}</a>
  <a href="{{ route('rentals.index') }}" class="{{ $nav === 'rentals' ? 'active' : '' }}">{{ icon('list') }}Requests @auth @if($shellPendingRentals)<span class="bn-dot">{{ $shellPendingRentals }}</span>@endif @endauth</a>
  <a href="{{ auth()->check() ? route('profile') : route('login') }}" class="{{ $nav === 'profile' ? 'active' : '' }}">{{ icon('user') }}Profile</a>
</nav>

@if(setting('aiEnabled', true))
  @include('partials.chat')
@endif
@endsection
