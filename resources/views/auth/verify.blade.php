@extends('layouts.auth', ['title' => 'Verify your email', 'heading' => 'One last step.', 'sub' => 'Confirm your email so suppliers can reach you about your bookings, and so only you can recover this account.'])

@section('content')
<h1>Check your inbox</h1>
<p class="muted">We sent a verification link to <b>{{ auth()->user()->email }}</b>. Open the email and tap <b>Verify Email Address</b> to activate renting and checkout.</p>
@if(session('success'))<div class="alert alert-success mt-2">{{ icon('check') }}<div>{{ session('success') }}</div></div>@endif
@if(session('error'))<div class="alert alert-danger mt-2">{{ icon('alert') }}<div>{{ session('error') }}</div></div>@endif
<ul class="small muted mt-2" style="padding-left:18px">
  <li>The link works for {{ config('auth.verification.expire', 60) }} minutes.</li>
  <li>Can't find it? Check your Spam or Promotions folder.</li>
  <li>You can keep browsing while you wait.</li>
</ul>
<form method="POST" action="{{ route('verification.send') }}" class="mt-2">
  @csrf
  <button class="btn btn-primary btn-block btn-lg">Resend verification email</button>
</form>
<div class="row between mt-2 small">
  <a href="{{ route('account') }}">Wrong email? Change it</a>
  <a href="{{ route('home') }}">Keep browsing →</a>
</div>
<form method="POST" action="{{ route('logout') }}" class="center mt-2">@csrf<button class="btn btn-ghost btn-sm">Log out</button></form>
@endsection
