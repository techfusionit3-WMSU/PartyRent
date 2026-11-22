@extends('layouts.auth', ['title' => 'Confirm your email', 'heading' => 'Almost there.', 'sub' => 'Confirm your email so only you can sign in and recover this account. Your account is created as soon as you enter the code.'])

@section('content')
<h1>Enter your code</h1>
<p class="muted">We sent a 6-digit code to <b>{{ $p->email }}</b>. Your account is created once you enter it.</p>
@if(session('success'))<div class="alert alert-success mt-2">{{ icon('check') }}<div>{{ session('success') }}</div></div>@endif
@if(session('error'))<div class="alert alert-danger mt-2">{{ icon('alert') }}<div>{{ session('error') }}</div></div>@endif
@if(session('dev_code'))<div class="alert alert-warn mt-2">{{ icon('info') }}<div><b>Email is not set up on this computer</b>, so the code is shown here: <b style="font-size:1.15rem;letter-spacing:.15em">{{ session('dev_code') }}</b>. To send real emails, put the team Gmail App Password in <code>MAIL_PASSWORD</code> in <code>.env</code> and run <code>php artisan config:clear</code>.</div></div>@endif
<form method="POST" action="{{ route('register.verify.submit') }}" class="mt-2">
  @csrf
  <div class="field @error('code') invalid @enderror">
    <label for="code">6-digit code</label>
    <input class="input" id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="\s*\d{3}\s?\d{3}\s*" maxlength="7" placeholder="123456" autofocus
      style="font-size:1.6rem;letter-spacing:.4em;text-align:center;font-weight:700" value="{{ old('code') }}">
    @error('code')<div class="error-text">{{ $message }}</div>@enderror
    <div class="hint">The code expires in {{ \App\Models\PendingRegistration::TTL_MINUTES }} minutes. Can't find it? Check Spam or Promotions.</div>
  </div>
  <button class="btn btn-primary btn-block btn-lg">Confirm &amp; create account</button>
</form>
<form method="POST" action="{{ route('register.resend') }}" class="mt-2" id="resendForm">
  @csrf
  <button class="btn btn-block" id="resendBtn" data-wait="{{ $p->secondsUntilResend() }}">Send a new code</button>
</form>
<p class="center mt-2 small"><a href="{{ route('register') }}">← Wrong email? Start again</a></p>
@push('scripts')
<script>
(function () {
  var b = document.getElementById('resendBtn'), n = +b.dataset.wait || 0, label = b.textContent;
  function tick() { if (n > 0) { b.disabled = true; b.textContent = 'Send a new code (' + n + 's)'; n--; setTimeout(tick, 1000); } else { b.disabled = false; b.textContent = label; } }
  tick();
  var c = document.getElementById('code');
  c.addEventListener('input', function () { var d = c.value.replace(/\D/g, '').slice(0, 6); if (c.value !== d) c.value = d; if (d.length === 6) c.form.requestSubmit ? c.form.requestSubmit() : c.form.submit(); });
})();
</script>
@endpush
@endsection
