@extends('layouts.auth', ['title' => 'Choose a new password'])

@section('content')
<h1>Choose a new password</h1>
<form method="POST" action="{{ route('password.update') }}" class="mt-2">
  @csrf
  <input type="hidden" name="token" value="{{ $token }}">
  <div class="field @error('email') invalid @enderror"><label for="email">Email address</label><input class="input" id="email" type="email" name="email" required value="{{ old('email', $email) }}">@error('email')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field @error('password') invalid @enderror"><label for="password">New password</label><input class="input" id="password" type="password" name="password" required minlength="8">@error('password')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field"><label for="password_confirmation">Confirm new password</label><input class="input" id="password_confirmation" type="password" name="password_confirmation" required></div>
  <button class="btn btn-primary btn-block btn-lg">Update password</button>
</form>
@endsection
