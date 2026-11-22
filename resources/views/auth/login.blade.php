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

<p class="center mt-2"><a href="{{ route('home') }}" class="small">← Continue browsing without logging in</a></p>

@unless(app()->isProduction())
@php
  // Every demo account (DemoSeeder). Only the PartyRent accounts exist after a plain seed; the rest need SEED_DEMO=true.
  $demoGroups = [
    'Customers' => [['Maria Santos', 'maria@demo.ph'], ['Jose Garcia', 'jose@demo.ph'], ['Kristine Aquino', 'kristine@demo.ph'], ['Bea Mendoza', 'bea@demo.ph'], ['Miguel Torres · suspended', 'miguel@demo.ph']],
    // one group per business, owner first, then the team
    'Elegant Occasion Rentals (shop with a full team)' => [['Liza · Owner', 'liza@demo.ph'], ['Leah · Front Desk', 'desk@elegantoccasion.ph'], ['Grace · Cashier', 'cashier@elegantoccasion.ph'], ['Rico · Inventory + Field Crew', 'inventory@elegantoccasion.ph'], ['Joy · Field Crew', 'staff@elegantoccasion.ph']],
    'Happy Moments Party Rentals' => [['Juan · Owner (no team)', 'juan@demo.ph']],
    'Celebration Hub Rentals' => [['Ana · Owner (no team)', 'ana@demo.ph']],
    'Dream Events Supplies' => [['Carlo · Owner (no team)', 'carlo@demo.ph']],
    'Party Essentials Shop' => [['Ramon · Owner (no team)', 'ramon@demo.ph']],
    'Grand Fiesta Events Co. (event company)' => [['Marco · Owner', 'events@demo.ph'], ['Ella · Event Coordinator', 'coordinator@grandfiesta.ph'], ['Nina · Finance', 'finance@grandfiesta.ph'], ['Ben · Event Crew', 'crew@grandfiesta.ph']],
    'Pending applications' => [['Kristine · Owner, Aquino Events Corp.', 'kristine@demo.ph'], ['Bea · Owner, Bea\'s Balloon Corner', 'bea@demo.ph']],
    'PartyRent' => [['Rosa · Cashier', 'cashier@partyrent.ph'], ['Mark · Admin', 'admin@partyrent.ph'], ['Paolo · Admin (limited)', 'paolo.admin@partyrent.ph'], ['Patricia · Super admin', 'super@partyrent.ph']],
  ];
@endphp
<div class="card card-flat mt-2" style="padding:14px">
  <div class="strong mb-1">Demo accounts <span class="muted small">— password <code>demo123</code></span></div>
  <p class="muted small" style="margin:0 0 8px">Click one to fill the form, then press Log in.</p>
  @foreach($demoGroups as $group => $accounts)
    <div class="small muted" style="margin:8px 0 4px">{{ $group }}</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
      @foreach($accounts as [$label, $email])
        <button type="button" class="chip demo-login" data-email="{{ $email }}" title="{{ $email }}" style="padding:5px 10px;font-size:.78rem">{{ $label }}</button>
      @endforeach
    </div>
  @endforeach
</div>
<script>
  document.querySelectorAll('.demo-login').forEach(function (b) {
    b.addEventListener('click', function () {
      document.getElementById('email').value = b.dataset.email;
      document.getElementById('password').value = 'demo123';
      document.getElementById('password').focus();
    });
  });
</script>
@endunless
@endsection
