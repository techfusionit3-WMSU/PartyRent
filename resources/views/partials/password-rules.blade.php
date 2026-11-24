{{-- What a new password needs, ticked off as it is typed (the server enforces the same: AccountRules::password()).
     $for = id of the password input, $confirm = id of the confirmation input. --}}
<ul class="pw-rules" data-pw-rules data-for="{{ $for }}" data-confirm="{{ $confirm ?? '' }}" aria-live="polite">
  @foreach(\App\Support\AccountRules::PASSWORD_NEEDS as $k => $l)<li data-rule="{{ $k }}">{{ $l }}</li>@endforeach
  @if(! empty($confirm))<li data-rule="match">Both passwords match</li>@endif
</ul>
@once
@push('scripts')
<script>
document.querySelectorAll('[data-pw-rules]').forEach(function (ul) {
  var pw = document.getElementById(ul.dataset.for), cf = ul.dataset.confirm ? document.getElementById(ul.dataset.confirm) : null;
  if (!pw) return;
  function draw() {
    var v = pw.value, t = { len: v.length >= 8, upper: /[A-Z]/.test(v), lower: /[a-z]/.test(v), match: !!cf && v !== '' && cf.value === v };
    ul.querySelectorAll('[data-rule]').forEach(function (li) { li.classList.toggle('ok', !!t[li.dataset.rule]); });
    var bad = !t.len ? 'Use at least 8 characters.' : !t.upper ? 'Add an uppercase letter (A–Z).' : !t.lower ? 'Add a lowercase letter (a–z).' : '';
    pw.setCustomValidity(v && bad ? bad : '');
    if (cf) cf.setCustomValidity(cf.value && cf.value !== v ? 'The two passwords do not match.' : '');
  }
  pw.addEventListener('input', draw); if (cf) cf.addEventListener('input', draw); draw();
});
</script>
@endpush
@endonce
