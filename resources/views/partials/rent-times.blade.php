{{--
  Reference rent box times (Slots): start / end time selects inside the date grid's row, then the
  "Total rental duration" line. Vars: $sup (Supplier), $item (Product|Package, for hour limits).
  Expects #ps / #pe date inputs on the page. Part 'selects' goes inside .form-grid; part 'line' after it.
--}}
@php($st = \App\Domain\Slots::settings($sup))
@if(($part ?? 'selects') === 'selects')
  @php($dt = \App\Domain\Slots::defaultTime($sup))
  @foreach([['pst', 'start', 'Start time', $dt['start']], ['pet', 'end', 'End time', $dt['end']]] as [$tid, $tname, $tlabel, $tval])
    <div class="field"><label for="{{ $tid }}">{{ $tlabel }}</label><select class="input" id="{{ $tid }}" name="{{ $tname }}">@foreach(\App\Domain\Slots::options($sup) as $o)<option value="{{ $o }}" @selected($o === $tval)>{{ \App\Domain\Slots::fmt12($o) }}</option>@endforeach</select></div>
  @endforeach
@else
  @php($lim = \App\Domain\Slots::hourLimitLabel($item))
  <div class="row wrap between mb-2" style="gap:6px"><span class="small">{{ icon('clock', 'width:14px;vertical-align:-2px') }} Total rental duration: <b id="durTxt">Select dates</b></span><span class="small muted">Rental hours {{ \App\Domain\Slots::fmt12($st['open']) }} – {{ \App\Domain\Slots::fmt12($st['close']) }}{{ $lim ? ' · '.$lim : '' }}</span></div>
  @push('scripts')
  <script>
  // Total rental duration (reference Slots.duration): start time on the first date → end time on the last.
  (function () {
    var g = function (id) { return document.getElementById(id); };
    var m = function (t) { var x = /^(\d{1,2}):(\d{2})$/.exec(t || ''); return x ? +x[1] * 60 + +x[2] : null; };
    function draw() {
      var out = g('durTxt'); if (!out || !g('ps')) return;
      var a = g('ps').value, b = (g('pe') && g('pe').value) || a;
      if (!a) { out.textContent = 'Select dates'; return; }
      var days = Math.round((new Date(b) - new Date(a)) / 864e5), mins = days * 1440 + m(g('pet').value) - m(g('pst').value);
      if (mins <= 0) { out.textContent = 'End must be after start'; return; }
      var d = Math.floor(mins / 1440), h = Math.floor(mins % 1440 / 60), mm = mins % 60;
      out.textContent = [d ? d + ' Day' + (d > 1 ? 's' : '') : '', h ? h + ' Hour' + (h > 1 ? 's' : '') : '', mm ? mm + ' Min' : ''].filter(Boolean).join(' ');
    }
    ['ps', 'pe', 'pst', 'pet'].forEach(function (id) { var e = g(id); if (e) { e.addEventListener('input', draw); e.addEventListener('change', draw); } });
    draw();
  })();
  </script>
  @endpush
@endif
