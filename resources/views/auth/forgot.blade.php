@extends('layouts.auth', ['title' => 'Forgot password'])

@section('content')
<h1>Reset your password</h1>
<p class="muted">Enter your account email and we'll send you a reset link.</p>
@if(session('status'))<div class="alert alert-success mt-2">{{ icon('check') }}<div>{{ session('status') }}</div></div>@endif
<form method="POST" action="{{ route('password.email') }}" class="mt-2">
  @csrf
  <div class="field @error('email') invalid @enderror"><label for="email">Email address</label><input class="input" id="email" type="email" name="email" required value="{{ old('email') }}">@error('email')<div class="error-text">{{ $message }}</div>@enderror</div>
  <button class="btn btn-primary btn-block btn-lg">Send reset link</button>
</form>
<p class="center mt-2 small"><a href="{{ route('login') }}">← Back to log in</a></p>
@endsection
