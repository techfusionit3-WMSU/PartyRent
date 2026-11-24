@extends('layouts.auth', ['title' => 'Create an account', 'heading' => 'One account to rent — and to sell.', 'sub' => 'Rent party supplies as a customer, and when you are ready, open your own rental shop with the same account.'])

{{-- Sign-up in four short groups: about you → how to reach you → where you are → password. --}}
@php($req = '<span class="req" aria-hidden="true">*</span>')
@section('content')
<h1>Create your account</h1>
<p class="muted mb-0">It takes about a minute. Fields marked {!! $req !!} are required.</p>
<form method="POST" action="{{ route('register') }}" class="mt-2">
  @csrf
  <fieldset class="reg-sec"><legend>About you</legend>
    <p class="small muted mt-0">Use your name as it appears on a valid ID.</p>
    @include('partials.person-fields')
  </fieldset>

  <fieldset class="reg-sec"><legend>How we can reach you</legend>
    <div class="form-grid">
      <div class="field @error('email') invalid @enderror"><label for="email">Email address {!! $req !!}</label><input class="input" id="email" type="email" name="email" required value="{{ old('email') }}" autocomplete="email" placeholder="you@example.com">@error('email')<div class="error-text">{{ $message }}</div>@enderror<div class="hint">We'll send a 6-digit code here to confirm it.</div></div>
      <div class="field @error('phone') invalid @enderror"><label for="phone">Mobile number {!! $req !!}</label><input class="input" id="phone" name="phone" required placeholder="0917 555 0101" value="{{ old('phone') }}" autocomplete="tel" inputmode="tel">@error('phone')<div class="error-text">{{ $message }}</div>@enderror</div>
    </div>
  </fieldset>

  <fieldset class="reg-sec"><legend>Where you are {!! $req !!}</legend>
    <p class="small muted mt-0">So we can show the shops that deliver to you.</p>
    @include('partials.address-fields', ['key' => 'addr', 'value' => [], 'full' => false, 'required' => true])
  </fieldset>

  <fieldset class="reg-sec"><legend>Create a password</legend>
    <div class="form-grid">
      <div class="field @error('password') invalid @enderror"><label for="password">Password {!! $req !!}</label><input class="input" id="password" type="password" name="password" required minlength="8" autocomplete="new-password" aria-describedby="pwRules">@error('password')<div class="error-text">{{ $message }}</div>@enderror</div>
      <div class="field"><label for="password_confirmation">Type it again {!! $req !!}</label><input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
    </div>
    <div id="pwRules">@include('partials.password-rules', ['for' => 'password', 'confirm' => 'password_confirmation'])</div>
  </fieldset>

  <label class="check mb-2 @error('terms') invalid @enderror"><input type="checkbox" name="terms" value="1" required> I agree to the PartyRent terms and privacy policy.</label>
  @error('terms')<div class="error-text" style="display:block;margin-top:-8px" >Please tick the box to agree before creating your account.</div>@enderror
  <button class="btn btn-primary btn-block btn-lg">Create account</button>
</form>
<p class="center mt-2 small">Already have an account? <a href="{{ route('login') }}" class="strong">Log in</a></p>
@endsection
