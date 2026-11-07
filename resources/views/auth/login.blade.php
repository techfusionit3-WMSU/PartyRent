@extends('layouts.auth', ['title' => 'Log in'])

@section('content')
<h1>Welcome back</h1>
<p class="muted">Log in to manage your rentals.</p>
<form method="POST" action="{{ route('login') }}" class="mt-2" id="loginForm">
  @csrf
  <div class="field @error('email') invalid @enderror"><label for="email">Email address</label><input class="input" id="email" name="email" type="email" required autocomplete="email" placeholder="you@email.com" value="{{ old('email') }}">@error('email')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field"><label for="password">Password</label><input class="input" id="password" name="password" type="password" required autocomplete="current-password" placeholder="••••••••"></div>
  <div class="row between mb-2"><label class="check"><input type="checkbox" name="remember" value="1" checked> Remember me</label><a href="{{ route('password.request') }}" class="small strong">Forgot password?</a></div>
  @if(session('error'))<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ session('error') }}</div></div>@endif
  <button class="btn btn-primary btn-block btn-lg">Log in</button>
</form>
<p class="center mt-2 small">New to PartyRent? <a href="{{ route('register') }}" class="strong">Create an account</a></p>

@if(! app()->environment('production'))
  <div class="divider-text">Demo accounts · password <b>demo123</b></div>
  <div class="demo-accounts">
    @foreach([['maria@demo.ph', 'Maria Santos', 'Customer'], ['juan@demo.ph', 'Juan Dela Cruz', 'Customer + Supplier'], ['bea@demo.ph', 'Bea Mendoza', 'Customer · application pending'], ['cashier@partyrent.ph', 'Grace Villanueva', 'Cashier'], ['admin@partyrent.ph', 'Mark Reyes', 'Admin'], ['super@partyrent.ph', 'Patricia Lim', 'Superadmin']] as [$e, $n, $r])
      <button type="button" onclick="var f=document.getElementById('loginForm');f.email.value='{{ $e }}';f.password.value='demo123';f.submit();">{{ $n }}<span>{{ $r }}</span></button>
    @endforeach
  </div>
@endif
<p class="center mt-2"><a href="{{ route('home') }}" class="small">← Continue browsing without logging in</a></p>
@endsection
