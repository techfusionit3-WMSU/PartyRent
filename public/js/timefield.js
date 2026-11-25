/* =========================================================
   PartyRent — time fields everywhere: type the time OR pick it on a clock.

   Every time <select> (all its options are "HH:MM") and every <input type="time"> is upgraded in place, including the
   ones drawn later (checkout, schedules): a text box where the time can be typed ("8", "8:30 pm", "830", "20:30")
   and a clock button that opens a dial (hour, then minutes, AM / PM). The original field stays in the page, hidden,
   and keeps the value in 24-hour "HH:MM"; it gets a normal "change" event, so forms, drafts and page scripts work
   unchanged. Allowed times follow the field: a select's own options (e.g. the shop's hours in 30-minute steps),
   or an input's min / max / step. A typed time is checked: outside the allowed range it is refused with the reason;
   between steps it is rounded to the nearest allowed time (and the box says so).
   Opt out with data-plain-time.
   ========================================================= */
(function () {
  'use strict';
  const HHMM = /^(\d{1,2}):(\d{2})$/;
  const toMin = v => { const m = HHMM.exec(v || ''); return m ? (+m[1]) * 60 + (+m[2]) : null; };
  const toHHMM = n => String(Math.floor(n / 60)).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0');
  const fmt = v => { const n = toMin(v); if (n == null) return ''; const h = Math.floor(n / 60) % 24, m = n % 60; if (n >= 1440) return '12:00 AM (midnight)'; return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM'); };

  /** Typed text → minutes of the day, or null. "8" = 8:00 AM, "8p" / "8 pm" = 8:00 PM, "830", "8.30", "20:30". */
  function parse(t) {
    const s = String(t || '').trim().toLowerCase().replace(/\s+/g, ' ');
    if (!s) return null;
    if (/^(12 ?(am)? ?\(?midnight\)?|midnight)$/.test(s)) return 0;
    if (s === 'noon') return 720;
    const m = /^(\d{1,2})(?:[:.h ]?(\d{2}))?\s*(a|am|a\.m\.|p|pm|p\.m\.)?$/.exec(s.replace(/\s*(am|pm)$/, ' $1').trim()) || /^(\d{1,2})(\d{2})\s*(a|am|p|pm)?$/.exec(s);
    if (!m) return null;
    let h = +m[1]; const mi = m[2] != null ? +m[2] : 0, ap = m[3] ? m[3][0] : null;
    if (mi > 59 || h > 24) return null;
    if (ap) { if (h < 1 || h > 12) return null; h = (h % 12) + (ap === 'p' ? 12 : 0); }
    if (h === 24 && mi > 0) return null;
    return h * 60 + mi;
  }

  /** What a field allows: a list of times (select) or a min / max / step range (input). */
  function rule(src) {
    if (src.tagName === 'SELECT') {
      const list = Array.from(src.options).map(o => toMin(o.value)).filter(n => n != null).sort((a, b) => a - b);
      return { list, min: list[0], max: list[list.length - 1] };
    }
    const step = Math.max(60, +src.step || 60) / 60;
    return { list: null, min: toMin(src.min) ?? 0, max: toMin(src.max) ?? 1439, step };
  }
  /** A typed time made valid: refused outside the range, else snapped to the nearest allowed time. */
  function fit(n, r) {
    if (n < r.min || n > r.max) return { bad: 'Choose a time between ' + fmt(toHHMM(r.min)) + ' and ' + fmt(toHHMM(r.max)) + '.' };
    if (r.list) { let best = r.list[0]; r.list.forEach(x => { if (Math.abs(x - n) < Math.abs(best - n)) best = x; }); return { v: best, rounded: best !== n }; }
    const v = r.min + Math.round((n - r.min) / r.step) * r.step;
    return { v: Math.min(r.max, v), rounded: v !== n };
  }

  let open = null; // the open clock
  function closeClock() { if (open) { open.remove(); open = null; document.removeEventListener('mousedown', outside, true); } }
  function outside(e) { if (open && !open.contains(e.target) && !e.target.closest('.tf-clock-btn')) closeClock(); }

  function clock(field, btn) {
    closeClock();
    const r = rule(field.src), cur = toMin(field.src.value) ?? (r.list ? r.list[0] : 8 * 60);
    let h24 = Math.floor(cur / 60) % 24, min = cur % 60, stage = 'h';
    const ok = n => (r.list ? r.list.includes(n) : n >= r.min && n <= r.max && (n - r.min) % r.step === 0);
    const pop = document.createElement('div');
    pop.className = 'tf-pop'; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Pick a time');
    function draw() {
      const pm = h24 >= 12, h12 = (h24 % 12) || 12;
      const items = stage === 'h'
        ? Array.from({ length: 12 }, (_, i) => { const hh = i + 1, v = ((hh % 12) + (pm ? 12 : 0)); const any = Array.from({ length: 60 }, (__, m) => ok(v * 60 + m)).some(Boolean); return { label: hh, on: hh === h12, dis: !any, v }; })
        : Array.from({ length: 12 }, (_, i) => { const m = i * 5; return { label: String(m).padStart(2, '0'), on: m === min, dis: !ok(h24 * 60 + m), v: m }; });
      pop.innerHTML = '<div class="tf-head"><button type="button" class="tf-part' + (stage === 'h' ? ' on' : '') + '" data-stage="h">' + h12 + '</button>:<button type="button" class="tf-part' + (stage === 'm' ? ' on' : '') + '" data-stage="m">' + String(min).padStart(2, '0') + '</button>' +
        '<span class="tf-ap"><button type="button" data-ap="am" class="' + (pm ? '' : 'on') + '">AM</button><button type="button" data-ap="pm" class="' + (pm ? 'on' : '') + '">PM</button></span></div>' +
        '<div class="tf-dial">' + items.map((it, i) => { const a = (i + 1) / 12 * 2 * Math.PI; return '<button type="button" class="tf-n' + (it.on ? ' on' : '') + '" data-v="' + it.v + '"' + (it.dis ? ' disabled' : '') + ' style="left:' + (50 + 40 * Math.sin(a)).toFixed(1) + '%;top:' + (50 - 40 * Math.cos(a)).toFixed(1) + '%">' + it.label + '</button>'; }).join('') +
        '<span class="tf-hint">' + (stage === 'h' ? 'Hour' : 'Minutes') + '</span></div>' +
        '<div class="tf-foot"><button type="button" class="btn btn-sm btn-ghost" data-cancel>Cancel</button><button type="button" class="btn btn-sm btn-primary" data-ok>OK</button></div>';
    }
    pop.addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b || b.disabled) return;
      if (b.dataset.stage) stage = b.dataset.stage;
      else if (b.dataset.ap) h24 = (h24 % 12) + (b.dataset.ap === 'pm' ? 12 : 0);
      else if (b.dataset.v != null) { if (stage === 'h') { h24 = +b.dataset.v; stage = 'm'; } else min = +b.dataset.v; }
      else if (b.hasAttribute('data-cancel')) { closeClock(); btn.focus(); return; }
      else if (b.hasAttribute('data-ok')) { field.set(h24 * 60 + min); closeClock(); btn.focus(); return; }
      draw();
    });
    pop.addEventListener('keydown', e => { if (e.key === 'Escape') { closeClock(); btn.focus(); } });
    draw();
    document.body.appendChild(pop);
    const rc = btn.getBoundingClientRect(), w = 248;
    pop.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, rc.right - w + window.scrollX)) + 'px';
    pop.style.top = (rc.bottom + 6 + window.scrollY) + 'px';
    open = pop;
    setTimeout(() => document.addEventListener('mousedown', outside, true), 0);
    const f = pop.querySelector('.tf-n.on:not([disabled])') || pop.querySelector('.tf-n:not([disabled])'); if (f) f.focus();
  }

  function upgrade(src) {
    if (src.dataset.tfDone || src.hasAttribute('data-plain-time') || src.closest('[data-plain-time]')) return;
    if (src.tagName === 'SELECT') {
      const vals = Array.from(src.options).map(o => o.value).filter(v => v !== '');
      if (vals.length < 2 || !vals.every(v => HHMM.test(v))) return;
    }
    src.dataset.tfDone = '1';
    const wrap = document.createElement('span');
    wrap.className = 'tf' + (src.classList.contains('input-sm') ? ' tf-sm' : '');
    const box = document.createElement('input');
    box.type = 'text'; box.className = 'input tf-box' + (src.classList.contains('input-sm') ? ' input-sm' : '');
    box.inputMode = 'text'; box.autocomplete = 'off'; box.spellcheck = false; box.placeholder = 'e.g. 8:30 AM';
    box.setAttribute('aria-label', src.getAttribute('aria-label') || (src.id && document.querySelector('label[for="' + src.id + '"]') ? document.querySelector('label[for="' + src.id + '"]').textContent.trim() : 'Time'));
    if (src.id) { box.id = src.id + '__t'; const lb = document.querySelector('label[for="' + src.id + '"]'); if (lb) lb.htmlFor = box.id; }
    if (src.disabled) box.disabled = true;
    if (src.required) box.required = true;
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'tf-clock-btn'; btn.setAttribute('aria-label', 'Pick the time on a clock'); btn.title = 'Pick on a clock';
    btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
    const msg = document.createElement('span'); msg.className = 'tf-msg'; msg.setAttribute('role', 'status');
    src.parentNode.insertBefore(wrap, src);
    wrap.append(box, btn, src, msg);
    src.classList.add('tf-src'); src.tabIndex = -1; src.setAttribute('aria-hidden', 'true');
    const field = {
      src,
      set(n, note) {
        const v = toHHMM(n);
        if (src.tagName === 'SELECT' && !Array.from(src.options).some(o => o.value === v)) { const o = new Option(fmt(v), v); src.add(o); }
        const changed = src.value !== v;
        src.value = v; box.value = fmt(v); box.classList.remove('is-bad'); box.removeAttribute('aria-invalid'); msg.textContent = note || '';
        if (changed) src.dispatchEvent(new Event('change', { bubbles: true }));
      },
    };
    const show = () => { box.value = fmt(src.value); };
    show();
    function commit() {
      if (box.value.trim() === '' ) { if (!src.required) { msg.textContent = ''; return; } }
      const n = parse(box.value);
      if (n == null) { box.classList.add('is-bad'); box.setAttribute('aria-invalid', 'true'); msg.textContent = 'Type a time like 8:30 AM, or use the clock.'; return; }
      const r = fit(n, rule(src));
      if (r.bad) { box.classList.add('is-bad'); box.setAttribute('aria-invalid', 'true'); msg.textContent = r.bad; return; }
      field.set(r.v, r.rounded ? 'Rounded to ' + fmt(toHHMM(r.v)) + ' (the nearest time allowed here).' : '');
    }
    box.addEventListener('change', commit);
    box.addEventListener('blur', commit);
    box.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); commit(); } if (e.key === 'ArrowDown' && e.altKey) { e.preventDefault(); clock(field, btn); } });
    btn.addEventListener('click', () => { if (!box.disabled) clock(field, btn); });
    src.addEventListener('change', show); // a script changed the hidden field
    new MutationObserver(() => { box.disabled = src.disabled; }).observe(src, { attributes: true, attributeFilter: ['disabled'] });
  }

  const scan = root => (root.querySelectorAll ? root : document).querySelectorAll('select, input[type="time"]').forEach(upgrade);
  let queued = false;
  const later = () => { if (queued) return; queued = true; requestAnimationFrame(() => { queued = false; scan(document); }); };
  document.addEventListener('DOMContentLoaded', () => { scan(document); new MutationObserver(later).observe(document.body, { childList: true, subtree: true }); });
  window.PRTime = { parse, fmt, upgrade, scan };
})();
