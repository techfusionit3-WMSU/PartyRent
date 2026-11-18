{{-- Post-login prompt (reference showDatePrompt in js/ui/layout.js): select rental dates or just browse. --}}
@php($max = (int) setting('maxRentalDays', 30))
<div class="modal-backdrop" id="datePrompt"><div class="modal sm" role="dialog" aria-modal="true" aria-labelledby="dpTitle">
  <div class="modal-head"><h3 id="dpTitle">How would you like to browse PartyRent?</h3><a class="icon-btn" href="{{ route('home', ['clear' => 1]) }}" aria-label="Close">{{ icon('x') }}</a></div>
  <div class="modal-body">
    <p class="muted small" style="margin-top:0">Pick your rental dates to see what is available for your event, or look around first.</p>
    <form class="card card-flat soft" style="padding:16px" method="GET" action="{{ route('home') }}#results" id="dpForm">
      <div class="strong mb-1">{{ icon('calendar', 'width:16px;vertical-align:-3px') }} Select rental dates</div>
      <div class="range-fields"><div class="field mb-0"><label for="dpS" class="small">Start date</label><input class="input" type="date" id="dpS" name="start" min="{{ today_iso() }}"></div><div class="field mb-0"><label for="dpE" class="small">End date</label><input class="input" type="date" id="dpE" name="end" min="{{ today_iso() }}"></div></div>
      <div class="row between mt-2" style="flex-wrap:wrap;gap:6px"><span class="small strong" id="dpRange" aria-live="polite">No dates selected</span><span class="badge b-purple plain" id="dpDays" style="display:none"></span></div>
      <div class="small mt-1" id="dpErr" style="color:var(--red)" role="alert"></div>
      <button class="btn btn-primary btn-block mt-2" id="dpGo" disabled>Continue with these dates</button>
    </form>
    <div class="divider-text">or</div>
    <a class="btn btn-outline btn-block" id="dpBrowse" href="{{ route('home', ['clear' => 1]) }}">I'm Just Browsing</a>
  </div>
</div></div>
<script>
(function () {
  var S = document.getElementById('dpS'), E = document.getElementById('dpE'), max = {{ $max }}, today = '{{ today_iso() }}';
  var day = function (s) { return new Date(s + 'T00:00:00'); }, iso = function (d) { return d.toISOString().slice(0, 10); };
  var addDays = function (s, n) { var d = day(s); d.setDate(d.getDate() + n); return iso(new Date(d.getTime() - d.getTimezoneOffset() * 60000)); };
  var mdy = function (s) { var p = s.split('-'); return p[1] + '/' + p[2] + '/' + p[0]; };
  var sync = function () {
    if (S.value) { E.min = S.value; E.max = addDays(S.value, max - 1); if (!E.value || E.value < S.value) E.value = S.value; }
    var err = null;
    if (S.value && E.value) { if (S.value < today) err = 'The start date cannot be in the past.'; else if (E.value < S.value) err = 'The end date must be on or after the start date.'; else if ((day(E.value) - day(S.value)) / 864e5 + 1 > max) err = 'Rental periods can be at most ' + max + ' days.'; }
    var n = S.value && E.value && E.value >= S.value ? Math.round((day(E.value) - day(S.value)) / 864e5) + 1 : 0;
    document.getElementById('dpRange').textContent = S.value && E.value ? mdy(S.value) + ' - ' + mdy(E.value) : 'No dates selected';
    var dd = document.getElementById('dpDays'); dd.style.display = n ? '' : 'none'; dd.textContent = n ? n + ' Day' + (n === 1 ? '' : 's') : '';
    document.getElementById('dpErr').textContent = err || '';
    document.getElementById('dpGo').disabled = !(S.value && E.value && !err);
  };
  ['change', 'input'].forEach(function (ev) { S.addEventListener(ev, sync); E.addEventListener(ev, sync); });
})();
</script>
