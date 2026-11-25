@extends('layouts.base')

@section('body')
@php($nav = $nav ?? '')
<header class="mk-header">
  <div class="container bar">
    @include('partials.brand')
    <form class="mk-search" id="mkSearch" role="search" action="{{ route('home') }}" method="GET">
      <span>{{ icon('search') }}</span>
      <input name="q" placeholder="{{ __('Search chairs, tents, suppliers…') }}" aria-label="{{ __('Search') }}" value="{{ request('q') }}">
      <button type="submit" class="search-go">{{ icon('search') }}<span>{{ __('Search') }}</span></button>
    </form>
    <button type="submit" form="mkSearch" formaction="{{ route('ai') }}" class="ai-square" title="AI Search: describe your event and let AI build the search" aria-label="AI Search">{{ icon('sparkle') }}<span>AI</span></button>
    <div class="mk-actions">
      @auth
        @include('partials.notif-dropdown')
        <a class="icon-btn" href="{{ route('cart') }}" aria-label="Rental list">{{ icon('cart') }}@if($shellCart)<span class="dot-count">{{ $shellCart }}</span>@endif</a>
        @include('partials.profile-menu')
      @else
        <a class="btn btn-ghost btn-sm" href="{{ route('login') }}">{{ __('Log in') }}</a>
        <a class="btn btn-primary btn-sm" href="{{ route('register') }}">{{ __('Sign up') }}</a>
      @endauth
    </div>
  </div>
  {{-- Main sections: their own centered row, four equal tabs (same look on every screen size) --}}
  <nav class="mk-nav" aria-label="Main">
    <div class="container mk-nav-in">
      @foreach([['home', 'home', 'Browse', 'grid'], ['packages', 'packages.index', 'Packages', 'package'], ['suppliers', 'suppliers.index', 'Suppliers', 'store'], ['rentals', 'rentals.index', 'My Rentals', 'list']] as [$k, $r, $l, $i])
        <a href="{{ route($r) }}" class="{{ $nav === $k || ($k === 'home' && $nav === 'browse') ? 'active' : '' }}" @if($nav === $k) aria-current="page" @endif>{{ icon($i) }}<span>{{ __($l) }}</span>@if($k === 'rentals')@auth @if($shellPendingRentals)<span class="nav-count">{{ $shellPendingRentals }}</span>@endif @endauth @endif</a>
      @endforeach
    </div>
  </nav>
  @yield('subheader')
</header>

<main class="mk-main" id="main" tabindex="-1"><div class="container">
  @unless(request()->routeIs('near'))
    @php($nearNow = \App\Support\Near::get())
    @unless(request()->routeIs('home', 'suppliers.index'))<div class="near-row"><a class="near-chip" href="{{ route('near', ['back' => request()->fullUrl()]) }}">{{ icon('map') }}@if($nearNow)<span>{{ __('Showing shops near') }}</span> <b>{{ $nearNow['label'] }}</b> · <span class="link">{{ __('Change') }}</span>@else<span>{{ __('Set where you are renting to see shops near you') }}</span>@endif</a></div>@endunless
    @if(! empty($outOfArea))
      <div class="alert alert-warn mb-2">{{ icon('map') }}<div><b>{{ __('This shop does not serve :place.', ['place' => $outOfArea]) }}</b> {{ __('None of its branches reach you, so you cannot rent from it there.') }} <a href="{{ route('near', ['back' => request()->fullUrl()]) }}">{{ __('Change place') }}</a></div></div>
    @endif
  @endunless
  @yield('content')
  @if(session(\App\Support\RentalPeriod::ASK))@include('partials.date-prompt')@endif
</div></main>

<footer class="mk-footer"><div class="container">
  <div class="cols">
    <div>@include('partials.brand')<p class="mt-2">The party supplies rental marketplace for Filipino celebrations — discover, schedule, and rent from trusted local suppliers.</p><p class="small">{{ setting('supportEmail') }} · {{ setting('supportPhone') }}</p></div>
    <div><h4>Rent</h4><ul><li><a href="{{ route('home') }}">Browse decorations &amp; supplies</a></li><li><a href="{{ route('packages.index') }}">Decoration packages</a></li>@if(\App\Models\Supplier::eventCompanies())<li><a href="{{ route('suppliers.index', ['type' => 'business']) }}">Event companies</a></li>@endif<li><a href="{{ route('ai') }}">Find items with AI</a></li></ul></div>
    <div><h4>Sell</h4><ul><li><a href="{{ route('start-selling') }}">Start selling</a></li><li><a href="{{ route('suppliers.index') }}">Our suppliers</a></li></ul></div>
    <div><h4>Account</h4><ul>@auth<li><a href="{{ route('rentals.index') }}">My rentals</a></li>@if(\App\Models\Supplier::eventCompanies())<li><a href="{{ route('events.index') }}">My events</a></li>@endif<li><a href="{{ route('notifications') }}">Notifications</a></li>@else<li><a href="{{ route('login') }}">Log in</a></li><li><a href="{{ route('register') }}">Create an account</a></li>@endauth</ul></div>
  </div>
  <hr style="border-color:rgba(255,255,255,.1)">
  <div class="small">© {{ date('Y') }} {{ setting('platformName', 'PartyRent') }} · Online payments are confirmed automatically by the payment gateway. PartyRent Assist suggests items but never books, prices or reserves anything.</div>
</div></footer>

<nav class="bottom-nav" aria-label="Main">
  <a href="{{ route('home') }}" class="{{ in_array($nav, ['browse', 'home'], true) ? 'active' : '' }}">{{ icon('grid') }}{{ __('Browse') }}</a>
  <a href="{{ route('suppliers.index') }}" class="{{ $nav === 'suppliers' ? 'active' : '' }}">{{ icon('store') }}{{ __('Suppliers') }}</a>
  <a href="{{ route('ai') }}" class="fab {{ $nav === 'ai' ? 'active' : '' }}" aria-label="AI Planner">{{ icon('sparkle') }}</a>
  <a href="{{ route('rentals.index') }}" class="{{ $nav === 'rentals' ? 'active' : '' }}">{{ icon('list') }}{{ __('Rentals') }} @auth @if($shellPendingRentals)<span class="bn-dot">{{ $shellPendingRentals }}</span>@endif @endauth</a>
  <a href="{{ auth()->check() ? route('profile') : route('login') }}" class="{{ $nav === 'profile' ? 'active' : '' }}">{{ icon('user') }}{{ __('Profile') }}</a>
</nav>

@if(setting('aiEnabled', true))
  @include('partials.chat')
@endif
@endsection
