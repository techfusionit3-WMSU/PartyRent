{{--
  Operating hours per day (App\Support\WeeklyHours): closed days, opening / closing time (typed or on the clock),
  "Copy to…" other days. Input: weekly_hours[mon][closed|open|close]. $value = saved weekly hours (or null).
--}}
@php
  $wh = old('weekly_hours', $value ?? null) ?: \App\Support\WeeklyHours::defaults();
  $whErr = $errors->first('weekly_hours');
@endphp
<div class="field full wh {{ $whErr ? 'invalid' : '' }}" data-weekly-hours>
  <label>Operating hours</label>
  <p class="small muted mt-0">Set the hours for each day. Customers see these on your shop page.</p>
  <div class="wh-rows">
    @foreach(\App\Support\WeeklyHours::DAYS as $d => $name)
      @php($r = (array) ($wh[$d] ?? []))
      @php($closed = filter_var($r['closed'] ?? false, FILTER_VALIDATE_BOOLEAN))
      <div class="wh-row {{ $closed ? 'is-closed' : '' }} {{ $errors->has('weekly_hours.'.$d) ? 'invalid' : '' }}" data-wh-day="{{ $d }}">
        <b class="wh-day">{{ $name }}</b>
        <label class="check small wh-closed"><input type="hidden" name="weekly_hours[{{ $d }}][closed]" value="0"><input type="checkbox" name="weekly_hours[{{ $d }}][closed]" value="1" data-wh-closed @checked($closed)> Closed</label>
        <div class="wh-times">
          <input class="input input-sm" type="time" step="900" name="weekly_hours[{{ $d }}][open]" value="{{ $r['open'] ?? '09:00' }}" aria-label="{{ $name }} opening time" data-wh-open>
          <span class="muted">to</span>
          <input class="input input-sm" type="time" step="900" name="weekly_hours[{{ $d }}][close]" value="{{ $r['close'] ?? '18:00' }}" aria-label="{{ $name }} closing time" data-wh-close>
        </div>
        <div class="wh-copy">
          <button type="button" class="btn btn-xs btn-ghost" data-wh-copy aria-expanded="false">{{ icon('copy', 'width:13px;height:13px') }}Copy to…</button>
          <div class="wh-pop card" hidden>
            <div class="small strong mb-1">Copy {{ $name }}'s hours to</div>
            <div class="wh-pop-days">@foreach(\App\Support\WeeklyHours::DAYS as $d2 => $n2)@if($d2 !== $d)<label class="check small"><input type="checkbox" value="{{ $d2 }}"> {{ $n2 }}</label>@endif @endforeach</div>
            <div class="row mt-1" style="gap:6px"><button type="button" class="btn btn-xs" data-wh-all>All days</button><button type="button" class="btn btn-xs btn-primary" data-wh-apply>Apply</button></div>
          </div>
        </div>
        <div class="error-text wh-err" @if($errors->has('weekly_hours.'.$d)) style="display:block" @endif>{{ $errors->first('weekly_hours.'.$d) }}</div>
      </div>
    @endforeach
  </div>
  <div class="error-text" data-wh-error @if($whErr) style="display:block" @endif>{{ $whErr }}</div>
</div>
@once
@push('scripts')
<script>
(function () {
  document.querySelectorAll('[data-weekly-hours]').forEach(function (box) {
    var rows = function () { return box.querySelectorAll('[data-wh-day]'); };
    var setTime = function (inp, v) { inp.value = v; inp.dispatchEvent(new Event('input', { bubbles: true })); inp.dispatchEvent(new Event('change', { bubbles: true })); if (window.PRTime && window.PRTime.refresh) window.PRTime.refresh(inp); };
    var check = function (row) {
      var closed = row.querySelector('[data-wh-closed]').checked, o = row.querySelector('[data-wh-open]').value, c = row.querySelector('[data-wh-close]').value, err = row.querySelector('.wh-err'), msg = '';
      row.classList.toggle('is-closed', closed);
      row.querySelectorAll('[data-wh-open],[data-wh-close]').forEach(function (i) { i.disabled = closed; });
      if (!closed && (!o || !c)) msg = 'Enter both times, or mark it closed.';
      else if (!closed && c <= o) msg = 'The closing time must be after the opening time.';
      err.textContent = msg; err.style.display = msg ? 'block' : ''; row.classList.toggle('invalid', !!msg);
      return !msg;
    };
    box.addEventListener('change', function (e) { var row = e.target.closest('[data-wh-day]'); if (row && !e.target.closest('.wh-pop')) check(row); });
    box.addEventListener('click', function (e) {
      var row = e.target.closest('[data-wh-day]'); if (!row) return;
      var pop = row.querySelector('.wh-pop');
      if (e.target.closest('[data-wh-copy]')) { var open = pop.hidden; box.querySelectorAll('.wh-pop').forEach(function (p) { p.hidden = true; }); pop.hidden = !open; e.target.closest('[data-wh-copy]').setAttribute('aria-expanded', String(open)); }
      if (e.target.closest('[data-wh-all]')) pop.querySelectorAll('input').forEach(function (i) { i.checked = true; });
      if (e.target.closest('[data-wh-apply]')) {
        var src = { closed: row.querySelector('[data-wh-closed]').checked, open: row.querySelector('[data-wh-open]').value, close: row.querySelector('[data-wh-close]').value };
        var n = 0;
        pop.querySelectorAll('input:checked').forEach(function (i) {
          var t = box.querySelector('[data-wh-day="' + i.value + '"]'); if (!t) return;
          t.querySelector('[data-wh-closed]').checked = src.closed;
          setTime(t.querySelector('[data-wh-open]'), src.open); setTime(t.querySelector('[data-wh-close]'), src.close);
          check(t); n++; i.checked = false;
        });
        pop.hidden = true;
        if (n && window.PR) window.PR.toast('Copied to ' + n + ' day' + (n > 1 ? 's' : '') + '.', 'success');
      }
    });
    // the wizard / form checks these before moving on
    box.validate = function () {
      var ok = true; rows().forEach(function (r) { ok = check(r) && ok; });
      var anyOpen = [].some.call(rows(), function (r) { return !r.querySelector('[data-wh-closed]').checked; });
      var g = box.querySelector('[data-wh-error]'); g.textContent = anyOpen ? '' : 'Open on at least one day of the week.'; g.style.display = anyOpen ? '' : 'block';
      return ok && anyOpen;
    };
    rows().forEach(check);
  });
})();
</script>
@endpush
@endonce
