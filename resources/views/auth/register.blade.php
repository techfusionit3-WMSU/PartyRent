@extends('layouts.auth', ['title' => 'Create an account', 'heading' => 'One account to rent — and to sell.', 'sub' => 'Rent party supplies as a customer, and when you are ready, open your own rental shop with the same account.'])

@section('content')
<h1>Create your account</h1>
<p class="muted">It takes less than a minute.</p>
<p class="small muted mb-0"><span class="req">*</span> Required</p>
<form method="POST" action="{{ route('register') }}" class="mt-2">
  @csrf
  <div class="label mb-1">Your legal name</div>
  @include('partials.person-fields')
  <div class="field @error('email') invalid @enderror"><label for="email">Email address <span class="req" aria-hidden="true">*</span></label><input class="input" id="email" type="email" name="email" required value="{{ old('email') }}" autocomplete="email">@error('email')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="field @error('phone') invalid @enderror"><label for="phone">Mobile number <span class="req" aria-hidden="true">*</span></label><input class="input" id="phone" name="phone" required placeholder="0917 555 0101" value="{{ old('phone') }}" autocomplete="tel">@error('phone')<div class="error-text">{{ $message }}</div>@enderror</div>
  <div class="label mb-1">Where are you? <span class="muted small" style="text-transform:none;font-weight:400">· so we can show who delivers to you</span></div>
  @include('partials.address-fields', ['key' => 'addr', 'value' => [], 'full' => false, 'required' => true])
  <div class="form-grid">
    <div class="field @error('password') invalid @enderror"><label for="password">Password <span class="req" aria-hidden="true">*</span></label><input class="input" id="password" type="password" name="password" required minlength="8" autocomplete="new-password" aria-describedby="pwRules">@error('password')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="field"><label for="password_confirmation">Confirm password <span class="req" aria-hidden="true">*</span></label><input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
  </div>
  <div id="pwRules">@include('partials.password-rules', ['for' => 'password', 'confirm' => 'password_confirmation'])</div>
  <label class="check mb-2"><input type="checkbox" name="terms" value="1" required> I agree to the PartyRent terms and privacy policy.</label>
  <button class="btn btn-primary btn-block btn-lg">Create account</button>
</form>
<p class="center mt-2 small">Already have an account? <a href="{{ route('login') }}" class="strong">Log in</a></p>
@endsection
