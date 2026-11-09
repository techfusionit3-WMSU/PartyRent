@extends('layouts.auth', ['title' => 'Create an account', 'heading' => 'One account to rent — and to sell.', 'sub' => 'Rent party supplies as a customer, and when you are ready, open your own rental shop with the same account.'])

@section('content')
<h1>Create your account</h1>
<p class="muted">It takes less than a minute.</p>
<form method="POST" action="{{ route('register') }}" class="mt-2">
  @csrf
  <div class="field @error('name') invalid @enderror"><label for="name">Full name</label><input class="input" id="name" name="name" required minlength="3" value="{{ old('name') }}" autocomplete="name">@error('name')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field @error('email') invalid @enderror"><label for="email">Email address</label><input class="input" id="email" type="email" name="email" required value="{{ old('email') }}" autocomplete="email">@error('email')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field @error('phone') invalid @enderror"><label for="phone">Mobile number</label><input class="input" id="phone" name="phone" required placeholder="0917 555 0101" value="{{ old('phone') }}" autocomplete="tel">@error('phone')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="label mb-1">Where are you? <span class="muted small" style="text-transform:none;font-weight:400">· so we can show who delivers to you</span></div>
  @include('partials.address-fields', ['key' => 'addr', 'value' => [], 'full' => false, 'required' => true])
  <div class="form-grid">
    <div class="field @error('password') invalid @enderror"><label for="password">Password</label><input class="input" id="password" type="password" name="password" required minlength="8" autocomplete="new-password">@error('password')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="field"><label for="password_confirmation">Confirm password</label><input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
  </div>
  <label class="check mb-2"><input type="checkbox" name="terms" value="1" required> I agree to the PartyRent terms and privacy policy.</label>
  <button class="btn btn-primary btn-block btn-lg">Create account</button>
</form>
<p class="center mt-2 small">Already have an account? <a href="{{ route('login') }}" class="strong">Log in</a></p>
@endsection
