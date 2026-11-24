{{--
  Legal name in parts, birthday and gender (registration, staff invitation, profile). The server checks everything
  again (App\Support\AccountRules): first + last name required, 18 or older from the birthday, a gender option.
  $u = the account being edited (optional), $required = birthday / gender required (default true).
--}}
@php
    $u = $u ?? null;
    $required = $required ?? true;
    $latest = today()->subYears(\App\Support\AccountRules::MIN_AGE)->format('Y-m-d'); // born on or before this day = 18+
    $f = fn ($k) => old($k, $u?->{$k} instanceof \Carbon\Carbon ? $u->{$k}->format('Y-m-d') : $u?->{$k});
    $bad = fn ($k) => $errors->has($k) ? 'invalid' : '';
    $msg = fn ($k) => $errors->has($k) ? '<div class="error-text" style="display:block">'.e($errors->first($k)).'</div>' : '';
    $tag = fn ($on) => $on ? '<span class="req" aria-hidden="true">*</span>' : '<span class="muted small">(optional)</span>';
@endphp
<div class="name-grid {{ ! empty($stacked) ? 'stacked' : '' }}">
  <div class="field {{ $bad('first_name') }}"><label for="first_name">First name {!! $tag(true) !!}</label><input class="input" id="first_name" name="first_name" required maxlength="80" value="{{ $f('first_name') }}" autocomplete="given-name" placeholder="e.g. Maria">{!! $msg('first_name') !!}</div>
  <div class="field {{ $bad('middle_name') }}"><label for="middle_name">Middle name</label><input class="input" id="middle_name" name="middle_name" maxlength="80" value="{{ $f('middle_name') }}" autocomplete="additional-name" placeholder="Optional">{!! $msg('middle_name') !!}</div>
  <div class="field {{ $bad('last_name') }}"><label for="last_name">Last name {!! $tag(true) !!}</label><input class="input" id="last_name" name="last_name" required maxlength="80" value="{{ $f('last_name') }}" autocomplete="family-name" placeholder="e.g. Santos">{!! $msg('last_name') !!}</div>
</div>
<div class="form-grid">
  <div class="field {{ $bad('birthday') }}"><label for="birthday">Birthday {!! $tag($required) !!}</label>
    <input class="input" id="birthday" type="date" name="birthday" @if($required) required @endif max="{{ $latest }}" min="1900-01-02" value="{{ $f('birthday') }}" autocomplete="bday" data-birthday>
    <div class="hint" data-age-hint @if($errors->has('birthday')) hidden @endif>You must be at least {{ \App\Support\AccountRules::MIN_AGE }} years old.</div>{!! $msg('birthday') !!}</div>
  <div class="field {{ $bad('gender') }}"><label for="gender">Gender {!! $tag($required) !!}</label>
    <select class="input" id="gender" name="gender" @if($required) required @endif><option value="">Select…</option>@foreach(\App\Support\AccountRules::GENDERS as $k => $l)<option value="{{ $k }}" @selected($f('gender') === $k)>{{ $l }}</option>@endforeach</select>{!! $msg('gender') !!}</div>
</div>
@once
@push('scripts')
<script>
// Age from the birthday as it is picked (the server checks it again): 18 or older.
document.querySelectorAll('[data-birthday]').forEach(function (inp) {
  var hint = inp.closest('.field').querySelector('[data-age-hint]'), base = hint.textContent;
  function check() {
    var v = inp.value, ok = true;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { hint.textContent = base; inp.setCustomValidity(''); return; }
    var b = new Date(v + 'T00:00:00'), t = new Date(); t.setHours(0, 0, 0, 0);
    if (b > t) { inp.setCustomValidity('Your birthday cannot be in the future.'); hint.textContent = 'Your birthday cannot be in the future.'; return; }
    var age = t.getFullYear() - b.getFullYear(); if (t.getMonth() < b.getMonth() || (t.getMonth() === b.getMonth() && t.getDate() < b.getDate())) age--;
    ok = age >= {{ \App\Support\AccountRules::MIN_AGE }};
    inp.setCustomValidity(ok ? '' : 'You must be at least {{ \App\Support\AccountRules::MIN_AGE }} years old.');
    hint.textContent = ok ? 'Age: ' + age : 'You are ' + age + '. You must be at least {{ \App\Support\AccountRules::MIN_AGE }} years old.';
    hint.style.color = ok ? '' : 'var(--red)';
  }
  // the server's message stays until the date is changed; then the live hint takes over
  function edited() { var srv = inp.closest('.field').querySelector('.error-text'); if (srv) srv.remove(); inp.closest('.field').classList.remove('invalid'); hint.hidden = false; check(); }
  inp.addEventListener('input', edited); inp.addEventListener('change', edited); check();
});
</script>
@endpush
@endonce
