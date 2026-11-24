@extends('layouts.auth', ['title' => 'Team invitation', 'heading' => 'Work with a party rental shop.', 'sub' => 'One PartyRent account to rent and to work: switch to Business Mode for the shop, and back to Customer Mode any time.'])

@section('content')
@if(session('success'))<div class="alert alert-success mb-2">{{ icon('check') }}<div>{{ session('success') }}</div></div>@endif
@if(session('error'))<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ session('error') }}</div></div>@endif
@error('invite')<div class="alert alert-danger mb-2">{{ icon('alert') }}<div>{{ $message }}</div></div>@enderror

@if($state === 'gone')
  <h1>Invitation not available</h1>
  <p class="muted">This invitation has expired, was cancelled, or a newer one was sent. Ask the shop to send it again and use the link in the latest email.</p>
  <a class="btn btn-primary btn-block mt-2" href="{{ route(auth()->check() ? 'home' : 'login') }}">{{ auth()->check() ? 'Go to PartyRent' : 'Log in' }}</a>
@else
  <h1>Join {{ $inv->supplier->name }}</h1>
  <p class="muted">{{ $inv->inviter?->name ?? $inv->supplier->name }} invited <b>{{ $inv->email }}</b> to work as <span class="badge {{ \App\Models\ShopStaff::ROLE_BADGE[$inv->role] ?? 'b-gray' }}">{{ $inv->roleLabel() }}</span></p>

  @if($state === 'other')
    <div class="alert alert-warn mt-2">{{ icon('alert') }}<div>You are signed in as <b>{{ auth()->user()->email }}</b>, but this invitation is for <b>{{ $inv->email }}</b>. Log out, then open the link again.</div></div>
    <form method="POST" action="{{ route('logout') }}" class="mt-2">@csrf<button class="btn btn-block">Log out</button></form>

  @elseif($state === 'accept')
    <p>You keep your own login and your rentals. To work for the shop, choose <b>Switch to Business Mode</b> from your account menu. You can only work for one business at a time.</p>
    <form method="POST" action="{{ route('invite.accept', $token) }}" class="mt-2">@csrf<button class="btn btn-primary btn-block btn-lg">Accept &amp; join</button></form>
    <form method="POST" action="{{ route('invite.decline', $token) }}" class="mt-1" data-confirm-title="Decline the invitation?" data-confirm="{{ $inv->supplier->name }} will be told you declined." data-confirm-ok="Decline" data-danger>@csrf<button class="btn btn-ghost btn-block">Decline</button></form>

  @else
    <p class="muted small">Create your PartyRent account to join. Enter the 6-digit code from the invitation email and choose your own password.</p>
    <form method="POST" action="{{ route('invite.join', $token) }}" class="mt-2">
      @csrf
      <div class="field @error('code') invalid @enderror">
        <label for="code">6-digit code from the email</label>
        <input class="input" id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="\s*\d{3}\s?\d{3}\s*" maxlength="7" placeholder="123456" autofocus style="font-size:1.6rem;letter-spacing:.4em;text-align:center;font-weight:700">
        @error('code')<div class="error-text">{{ $message }}</div>@enderror
      </div>
      @include('partials.person-fields')
      <div class="field @error('phone') invalid @enderror"><label for="phone">Mobile number</label><input class="input" id="phone" name="phone" required placeholder="0917 555 0101" value="{{ old('phone') }}" autocomplete="tel">@error('phone')<div class="error-text">{{ $message }}</div>@enderror</div>
      <div class="form-grid">
        <div class="field @error('password') invalid @enderror"><label for="password">Password</label><input class="input" id="password" type="password" name="password" required minlength="8" autocomplete="new-password">@error('password')<div class="error-text">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="password_confirmation">Confirm password</label><input class="input" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
      </div>
      @include('partials.password-rules', ['for' => 'password', 'confirm' => 'password_confirmation'])
      <button class="btn btn-primary btn-block btn-lg">Create account &amp; join</button>
    </form>
    <p class="center mt-2 small muted">The invitation expires {{ $inv->expires_at->diffForHumans() }}.</p>
  @endif
@endif
@endsection
