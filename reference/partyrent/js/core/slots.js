/* =========================================================
   PartyRent — slots.js
   Rental TIME handling: start/end times (12-hour display),
   supplier rental hours, preparation & recovery buffers,
   automatic duration, and the overlap ("peak") calculation
   used by Inventory for time-based stock.

   A line's time { start:'HH:MM', end:'HH:MM' } means: the
   rental starts at `start` on its first date and ends at
   `end` on its last date (days in between are full days).
   A line without a time is an all-day rental.
   ========================================================= */
(function (g) {
  'use strict';
  const DAY = 1440;
  const DEFAULT = { open: '06:00', close: '22:00', prepHours: 1, recoveryHours: 1, step: 30 };

  const toMin = t => { if (t == null || t === '') return null; const m = String(t).match(/^(\d{1,2}):(\d{2})$/); if (!m) return null; return Number(m[1]) * 60 + Number(m[2]); };
  const fromMin = n => U.pad(Math.floor(n / 60)) + ':' + U.pad(n % 60);
  /** "6:00 AM" */
  function fmt12(t) {
    const n = typeof t === 'number' ? t : toMin(t); if (n == null) return '';
    if (n >= DAY) return '12:00 AM (midnight)';
    const h = Math.floor(n / 60), m = n % 60;
    return ((h % 12) || 12) + ':' + U.pad(m) + ' ' + (h < 12 ? 'AM' : 'PM');
  }
  const rangeLabel = time => (time && time.start && time.end ? fmt12(time.start) + ' – ' + fmt12(time.end) : 'All day');

  /** Supplier rental hours and turnaround buffers (with defaults). */
  function settings(supplierId) { const s = supplierId && Store.supplier(supplierId); return Object.assign({}, DEFAULT, (s && s.timeSettings) || {}); }
  /** Selectable times (every 30 minutes) within the supplier's rental hours. */
  function options(supplierId) {
    const st = settings(supplierId); const a = toMin(st.open), b = toMin(st.close); const out = [];
    for (let n = a; n <= b; n += st.step || 30) out.push(fromMin(n));
    return out;
  }
  /** Default time for a new booking: the supplier's full rental hours. */
  function defaultTime(supplierId) { const st = settings(supplierId); return { start: st.open, end: st.close }; }

  /** Absolute day number (timezone-safe). */
  function dayNum(d) { const [y, m, dd] = String(d).slice(0, 10).split('-').map(Number); return Math.round(Date.UTC(y, m - 1, dd) / 864e5); }

  /**
   * Occupied intervals of one line, one per rental date, in absolute minutes.
   * buf = { prep, rec } in minutes: preparation before the start of a run of dates and
   * recovery after its end (delivery/setup and dismantling/pickup), kept separate from the
   * customer's rental time (rawFrom/rawTo).
   */
  function lineIntervals(line, buf) {
    buf = buf || { prep: 0, rec: 0 };
    const dates = Object.keys(line.days || {}).filter(d => Number(line.days[d]) > 0).sort();
    const set = new Set(dates);
    const t = line.time && line.time.start && line.time.end ? line.time : null;
    return dates.map(d => {
      const runStart = !set.has(U.addDays(d, -1)), runEnd = !set.has(U.addDays(d, 1));
      const base = dayNum(d) * DAY;
      const f = runStart && t ? toMin(t.start) : 0;
      let e = runEnd && t ? toMin(t.end) : DAY;
      if (runStart && runEnd && t && e <= f) e = f; // invalid same-day range — reported by validateLine
      // all-day bookings already occupy the whole day, so buffers only apply to timed bookings
      return { date: d, qty: Number(line.days[d]) || 0, rawFrom: base + f, rawTo: base + e, from: base + f - (runStart && t ? buf.prep : 0), to: base + e + (runEnd && t ? buf.rec : 0) };
    });
  }

  /** Highest combined quantity of overlapping intervals inside [A, B). Half-open: touching intervals do not overlap. */
  function peak(intervals, A, B) {
    const ev = [];
    intervals.forEach(iv => { const f = Math.max(iv.from, A), t = Math.min(iv.to, B); if (f < t && iv.qty) { ev.push([f, iv.qty]); ev.push([t, -iv.qty]); } });
    ev.sort((a, b) => a[0] - b[0] || a[1] - b[1]); // ends before starts at the same minute
    let cur = 0, max = 0; ev.forEach(e => { cur += e[1]; if (cur > max) max = cur; });
    return max;
  }

  /** Total rental duration from the first date's start time to the last date's end time. */
  function duration(line) {
    const dates = Object.keys(line.days || {}).filter(d => Number(line.days[d]) > 0).sort();
    if (!dates.length) return { minutes: 0, label: '—' };
    const t = line.time && line.time.start ? line.time : { start: '00:00', end: '24:00' };
    const mins = (dayNum(dates[dates.length - 1]) - dayNum(dates[0])) * DAY + toMin(t.end) - toMin(t.start);
    return { minutes: mins, hours: mins / 60, label: durationLabel(mins, !line.time) };
  }
  function durationLabel(mins, allDay) {
    if (allDay) { const d = Math.round(mins / DAY); return d + ' Day' + (d === 1 ? '' : 's') + ' (all day)'; }
    if (mins <= 0) return '0 Hours';
    const h = Math.floor(mins / 60), m = mins % 60;
    const base = h + ' Hour' + (h === 1 ? '' : 's') + (m ? ' ' + m + ' Min' : '');
    if (h < 24) return base;
    const d = Math.floor(h / 24), rh = h % 24;
    return base + ' (' + d + ' day' + (d > 1 ? 's' : '') + (rh ? ', ' + rh + ' hour' + (rh > 1 ? 's' : '') : '') + (m ? ', ' + m + ' min' : '') + ')';
  }

  /**
   * Validate the time of a line against the supplier's rental hours and the item's
   * minimum / maximum rental duration. Returns a list of customer-readable messages.
   */
  function validateLine(line) {
    const out = [];
    const item = line.type === 'package' ? Store.pkg(line.refId) : Store.product(line.refId);
    if (!item) return out;
    const name = item.name;
    if (!line.time) return out; // all-day booking: day-based rules only
    const st = settings(item.supplierId);
    const a = toMin(line.time.start), b = toMin(line.time.end);
    if (a == null || b == null) { out.push('Select a start time and an end time for ' + name + '.'); return out; }
    const open = toMin(st.open), close = toMin(st.close);
    if (a < open || a > close || b < open || b > close) out.push(name + ': rental times must be within the supplier\'s rental hours (' + fmt12(st.open) + ' – ' + fmt12(st.close) + ').');
    const dates = Object.keys(line.days || {}).filter(d => Number(line.days[d]) > 0).sort();
    if (!dates.length) return out;
    const set = new Set(dates);
    const singleRuns = dates.filter(d => !set.has(U.addDays(d, -1)) && !set.has(U.addDays(d, 1)));
    if (singleRuns.length && b <= a) out.push(name + ': the end time must be later than the start time. For an overnight rental, set the end date to the next day.');
    const dur = duration(line).minutes / 60;
    if (item.minRentalHours > 0 && dur < item.minRentalHours) out.push(name + ' has a minimum rental duration of ' + item.minRentalHours + ' hour' + (item.minRentalHours > 1 ? 's' : '') + '. Your selected time is ' + durationLabel(duration(line).minutes) + '.');
    if (item.maxRentalHours > 0 && dur > item.maxRentalHours) out.push(name + ' has a maximum rental duration of ' + item.maxRentalHours + ' hours per booking. Your selected time is ' + durationLabel(duration(line).minutes) + '.');
    return out;
  }
  const timeIssues = lines => (lines || []).flatMap(validateLine);
  /** Label for an item's optional hour limits. */
  function hourLimitLabel(item) {
    const a = item && item.minRentalHours > 0 ? item.minRentalHours : 0, b = item && item.maxRentalHours > 0 ? item.maxRentalHours : 0;
    if (a && b) return a + '–' + b + ' hours per booking';
    if (a) return 'Minimum ' + a + ' hour' + (a > 1 ? 's' : '');
    if (b) return 'Maximum ' + b + ' hours per booking';
    return '';
  }
  /** <select> of 12-hour times. */
  function select(name, value, supplierId, attrs) {
    const opts = options(supplierId);
    if (value && !opts.includes(value)) opts.push(value);
    return '<select class="input" name="' + name + '" ' + (attrs || '') + '>' + opts.sort().map(t => '<option value="' + t + '"' + (t === value ? ' selected' : '') + '>' + fmt12(t) + '</option>').join('') + '</select>';
  }

  g.Slots = { DAY, DEFAULT, toMin, fromMin, fmt12, rangeLabel, settings, options, defaultTime, dayNum, lineIntervals, peak, duration, durationLabel, validateLine, timeIssues, hourLimitLabel, select };
})(window);
