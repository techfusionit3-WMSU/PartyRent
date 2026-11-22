@extends('layouts.base')

@section('body')
<div class="auth">
  <div class="auth-art">
    <a class="brand" href="{{ route('home') }}"><span class="brand-mark">{{ icon('party') }}</span><span>Party<b>Rent</b></span></a>
    <svg class="confetti" viewBox="0 0 400 400" style="position:absolute;inset:0;width:100%;height:100%;opacity:.35">{{ confetti_svg() }}</svg>
    <div style="position:relative">
      <h2>{{ $heading ?? 'Every celebration, perfectly supplied.' }}</h2>
      <p style="opacity:.9;font-size:1.05rem;max-width:420px">{{ $sub ?? 'Discover party supplies from local suppliers, schedule multi-day rentals with different quantities per day, and track every payment in one place.' }}</p>
      <div class="hero-stats">
        <div><b>{{ \App\Models\Supplier::approved()->count() }}</b><span>trusted suppliers</span></div>
        <div><b>{{ \App\Models\Product::live()->count() }}+</b><span>rental items</span></div>
        <div><b>AI</b><span>event planning</span></div>
      </div>
    </div>
    <div class="small" style="opacity:.8;position:relative">Tables · Chairs · Tents · Sound · Lights · Décor — for every Filipino celebration.</div>
  </div>
  <main class="auth-form" id="main" tabindex="-1"><div class="box">
    @yield('content')
  </div></main>
</div>
@endsection
