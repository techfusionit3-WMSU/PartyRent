/* =========================================================
   PartyRent — app.js
   Progressive enhancement for server-rendered pages:
   dropdowns, modals, confirm dialogs, toasts, the multi-day
   scheduler, availability calendars and the AI chat widget.
   All business rules run on the server; this file only renders
   what /api/schedule and /api/calendar return.
   ========================================================= */
(function () {
  'use strict';
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const csrf = () => ($('meta[name="csrf-token"]') || {}).content || '';

  // Pages restored from the back/forward cache carry an old CSRF token (e.g. after logging out),
  // so their forms would fail with "Page Expired". Reload them to get a fresh token.
  window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });
  // Sticky things under the market header (filters sidebar) need its real height, which changes when it wraps on phones.
  const mkHead = document.querySelector('.mk-header');
  if (mkHead) {
    const setHead = () => document.documentElement.style.setProperty('--mk-head', mkHead.offsetHeight + 'px');
    setHead();
    if (window.ResizeObserver) new ResizeObserver(setHead).observe(mkHead); else window.addEventListener('resize', setHead);
  }
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ICON = (window.PR && window.PR.icons) || {};
  const svg = (name, style) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' + (style ? ' style="' + style + '"' : '') + '>' + (ICON[name] || '') + '</svg>';
  const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const addDays = (s, n) => { const [y, m, d] = s.split('-').map(Number); return iso(new Date(y, m - 1, d + n)); };
  const range = (a, b) => { const out = []; let d = a, g = 0; while (d <= b && g < 400) { out.push(d); d = addDays(d, 1); g++; } return out; };

  async function api(url, opts) {
    opts = opts || {};
    const res = await fetch(url, Object.assign({ headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }, opts));
    if (!res.ok) throw new Error('Request failed (' + res.status + ')');
    return res.json();
  }

  /* ---------- toasts ---------- */
  window.PR = window.PR || {}; window.PR.toast = (m, t) => toast(m, t);
  function toast(msg, type) {
    let box = $('.toasts'); if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('role', 'status'); document.body.appendChild(box); }
    const t = document.createElement('div'); t.className = 'toast ' + (type || '');
    t.innerHTML = svg(type === 'success' ? 'check' : type === 'error' || type === 'warn' ? 'alert' : 'info') + '<div>' + esc(msg) + '</div>';
    box.appendChild(t);
    setTimeout(() => { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, type === 'error' ? 6500 : 4000);
  }

  /* ---------- modals ---------- */
  function openModal(el) { el.classList.remove('hidden'); document.body.style.overflow = 'hidden'; const f = el.querySelector('input:not([type=hidden]),select,textarea'); if (f) setTimeout(() => f.focus(), 50); }
  function closeModal(el) { el.classList.add('hidden'); if (!$$('.modal-backdrop:not(.hidden)').length) document.body.style.overflow = ''; }
  function confirmDialog(o) {
    return new Promise(res => {
      const bd = document.createElement('div'); bd.className = 'modal-backdrop';
      bd.innerHTML = '<div class="modal ' + (o.html ? '' : 'sm') + '" role="dialog" aria-modal="true"><div class="modal-head"><h3>' + esc(o.title || 'Please confirm') + '</h3><button class="icon-btn" data-x aria-label="Close">' + svg('x') + '</button></div><div class="modal-body">' + (o.html || '<p style="margin:0">' + esc(o.message || 'Are you sure?') + '</p>') + '</div><div class="modal-foot"><button class="btn" data-x>Cancel</button><button class="btn ' + (o.danger ? 'btn-danger' : 'btn-primary') + '" data-ok>' + esc(o.ok || 'Confirm') + '</button></div></div>';
      document.body.appendChild(bd); document.body.style.overflow = 'hidden';
      const done = v => { bd.remove(); if (!$$('.modal-backdrop:not(.hidden)').length) document.body.style.overflow = ''; res(v); };
      bd.addEventListener('click', e => { if (e.target === bd || e.target.closest('[data-x]')) done(false); if (e.target.closest('[data-ok]')) done(true); });
    });
  }

  document.addEventListener('click', e => {
    // dropdowns
    const tg = e.target.closest('[data-dropdown]');
    $$('.dropdown.open').forEach(d => { if (!tg || d !== tg.closest('.dropdown')) { if (!e.target.closest('.menu') || e.target.closest('a,button.menu-item')) d.classList.remove('open'); } });
    if (tg) { e.preventDefault(); tg.closest('.dropdown').classList.toggle('open'); }
    // modals
    const op = e.target.closest('[data-modal]');
    if (op) { e.preventDefault(); const m = document.getElementById(op.dataset.modal); if (m) openModal(m); }
    const bd = e.target.classList && e.target.classList.contains('modal-backdrop') ? e.target : null;
    if (bd && bd.id) closeModal(bd);
    const cl = e.target.closest('[data-close]');
    if (cl) { const m = cl.closest('.modal-backdrop'); if (m && m.id) { e.preventDefault(); closeModal(m); } }
    // sidebar
    if (e.target.closest('[data-side-open]')) { $('#side') && $('#side').classList.add('open'); $('#sideBd') && $('#sideBd').classList.add('open'); }
    if (e.target.closest('#sideBd')) { $('#side').classList.remove('open'); $('#sideBd').classList.remove('open'); }
    // tabs that filter rows client-side
    const tab = e.target.closest('[data-filter-tab]');
    if (tab) {
      const group = tab.closest('[data-filter-group]'); const key = tab.dataset.filterTab;
      $$('[data-filter-tab]', group).forEach(t => t.classList.toggle('active', t === tab));
      $$('[data-filter-row]', document).forEach(r => { r.style.display = key === 'all' || (r.dataset.filterRow || '').split(' ').includes(key) ? '' : 'none'; });
    }
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $$('.modal-backdrop:not(.hidden)').forEach(m => m.id && closeModal(m)); });

  // forms needing confirmation: <form data-confirm="..." data-confirm-title="..." data-danger>
  document.addEventListener('submit', async e => {
    const f = e.target;
    if (f.dataset.confirm && !f.dataset.confirmed) {
      e.preventDefault();
      const ok = await confirmDialog({ title: f.dataset.confirmTitle, message: f.dataset.confirm, html: f.dataset.confirmHtml, ok: f.dataset.confirmOk, danger: f.hasAttribute('data-danger') });
      if (ok) { f.dataset.confirmed = '1'; if (f.requestSubmit) f.requestSubmit(); else f.submit(); }
      return;
    }
    const btn = f.querySelector('[type=submit][data-busy]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;border-top-color:#fff"></span> ' + esc(btn.dataset.busy); }
  });

  // chip radios / checkboxes
  document.addEventListener('change', e => {
    const i = e.target;
    if (i.closest && i.closest('label.chip') && (i.type === 'radio' || i.type === 'checkbox')) {
      if (i.type === 'radio') $$('input[name="' + i.name + '"]').forEach(x => x.closest('label.chip') && x.closest('label.chip').classList.toggle('active', x.checked));
      else i.closest('label.chip').classList.toggle('active', i.checked);
    }
    if (i.matches && i.matches('[data-autosubmit]')) i.form.submit();
  });

  /* ---------- one rental date by default, "Multiple days" toggle shows the end date ----------
     <div data-multiday> [data-md-start] input, [data-md-toggle] checkbox, [data-md-end] wrapper (holds the end input).
     Off: end = start (hidden). Fires a bubbling "multiday" event on the box after each change. */
  $$('[data-multiday]').forEach(box => {
    const start = $('[data-md-start]', box), t = $('[data-md-toggle]', box), endWrap = $('[data-md-end]', box);
    if (!start || !t || !endWrap) return;
    const end = endWrap.matches('input') ? endWrap : $('input', endWrap);
    const fire = () => box.dispatchEvent(new CustomEvent('multiday', { bubbles: true, detail: { on: t.checked } }));
    const sync = () => {
      endWrap.classList.toggle('hidden', !t.checked); box.classList.toggle('md-on', t.checked);
      if (end) { end.min = start.value || start.min; if (!t.checked) end.value = start.value; else if (end.value && start.value && end.value < start.value) end.value = start.value; }
    };
    start.addEventListener('change', () => { if (!t.checked && end) end.value = start.value; sync(); fire(); });
    t.addEventListener('change', () => {
      sync(); fire();
      if (t.checked) { if (end && start.value && (!end.value || end.value <= start.value)) end.value = start.value; if (end) setTimeout(() => { try { end.showPicker ? end.showPicker() : end.focus(); } catch (_) { end.focus(); } }, 30); }
      else if (start.value && start.matches('[data-autosubmit]')) start.form.submit();
    });
    sync();
  });

  /* ---------- notifications ---------- */
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-mark-all]'); if (!b) return;
    e.preventDefault();
    try { await api(b.dataset.markAll, { method: 'POST', body: '{}' }); $$('.notif.unread').forEach(x => x.classList.remove('unread')); $$('.dropdown .dot-count[data-notif-count]').forEach(x => x.remove()); toast('All notifications marked as read.', 'success'); } catch (err) { toast(err.message, 'error'); }
  });

  /* ---------- multi-day scheduler ----------
     <div data-scheduler data-type="item" data-ref="1" data-days='{"2026-11-12":3}'
          data-siblings='[...]' data-sync="#hiddenInput" data-save="/cart/5"></div>  */
  function Scheduler(el, opts) {
    opts = opts || {};
    const st = { type: el.dataset.type, ref: +el.dataset.ref, days: JSON.parse(el.dataset.days || '{}'), siblings: JSON.parse(el.dataset.siblings || '[]'), sel: JSON.parse(el.dataset.sel || 'null') };
    let timer = null, last = null;
    const fixed = el.hasAttribute('data-fixed');
    const sync = () => { const t = el.dataset.sync && $(el.dataset.sync); if (t) t.value = JSON.stringify(st.days); };
    const save = () => { if (!el.dataset.save) return; clearTimeout(timer); timer = setTimeout(async () => { try { await api(el.dataset.save, { method: 'PATCH', body: JSON.stringify({ days: st.days }) }); el.dispatchEvent(new CustomEvent('pr:saved', { bubbles: true })); } catch (err) { toast('Could not save: ' + err.message, 'error'); } }, 450); };
    async function refresh() {
      sync();
      let r;
      try { r = await api(window.PR.routes.schedule, { method: 'POST', body: JSON.stringify({ type: st.type, ref_id: st.ref, days: st.days, siblings: st.siblings, sel: st.sel }) }); }
      catch (err) { el.innerHTML = '<div class="alert alert-danger">' + svg('alert') + '<div>' + esc(err.message) + '</div></div>'; return; }
      last = r;
      const rows = r.rows.map(x => '<div class="day-row ' + (x.ok ? '' : 'bad') + '" data-date="' + x.date + '"><div class="dl">' + esc(x.label) + (x.past ? ' <span class="badge b-red">Past date</span>' : '') + '</div>' +
        '<div data-l="Quantity"><div class="qty ' + (x.ok ? '' : 'bad') + '"><button type="button" data-dec aria-label="Decrease">−</button><input type="number" min="0" value="' + x.qty + '" data-qty aria-label="Quantity for ' + esc(x.label) + '"><button type="button" data-inc aria-label="Increase">+</button></div></div>' +
        '<div data-l="Available">' + (x.ok || x.past ? '<span class="avail-pill ' + (x.available - x.qty < 5 && st.type === 'item' ? 'low' : 'ok') + '">' + x.available + ' ' + (st.type === 'package' ? 'pkg' : 'avail.') + '</span>' : '<span class="avail-pill none">Only ' + x.available + '</span>') + '</div>' +
        '<div data-l="Daily rate">' + esc(x.unitText) + '<div class="small muted">/' + esc(r.unitWord) + (x.tier ? ' · tier ' + esc(x.tier) : '') + '</div></div>' +
        '<div data-l="Subtotal" class="strong">' + esc(x.subtotalText) + '</div>' +
        '<div class="rm">' + (fixed ? '' : '<button type="button" class="icon-btn" data-remove aria-label="Remove date" style="width:34px;height:34px">' + svg('trash', 'width:17px') + '</button>') + '</div></div>').join('');
      const first = Object.keys(st.days).sort()[0];
      el.innerHTML = '<div class="day-editor"><div class="day-row head"><div>Date</div><div>Requested qty</div><div>Available</div><div>Daily rate</div><div>Daily subtotal</div><div></div></div>' + (rows || '<div class="empty small" style="padding:18px">' + (fixed ? 'Pick your rental dates first.' : 'No dates yet — add a rental date below.') + '</div>') + '</div>' +
        '<div class="row wrap mt-1" style="gap:8px">' + (fixed ? '' : '<input type="date" class="input input-sm" style="width:auto" data-newdate min="' + r.today + '" aria-label="Add a rental date"><button type="button" class="btn btn-sm" data-adddate>' + svg('plus') + 'Add date</button>') + '<span class="grow"></span><div class="row" style="gap:6px"><span class="small muted">Same qty for all:</span><input type="number" min="1" class="input input-sm" style="width:80px" data-allqty value="' + (first ? st.days[first] : 1) + '" aria-label="Quantity for all dates"><button type="button" class="btn btn-sm btn-outline" data-applyall>Apply</button></div></div>' +
        '<div class="row between mt-1"><div class="small">' + (r.ok ? '<span style="color:var(--green)">' + svg('check', 'width:15px;vertical-align:-3px') + ' All dates available</span>' : '<span style="color:var(--red)">' + svg('alert', 'width:15px;vertical-align:-3px') + ' ' + esc(r.lead || 'Fix the highlighted dates before requesting') + '</span>') + '</div><div class="strong">Line total: <span class="price">' + esc(r.totalText) + '</span>' + (r.once > 0 ? '<div class="small muted" style="font-weight:400">incl. ' + esc(r.onceText) + ' once for consumables &amp; services</div>' : '') + '</div></div>';
      el.dispatchEvent(new CustomEvent('pr:schedule', { bubbles: true, detail: r }));
      if (opts.onChange) opts.onChange(r, st);
    }
    const changed = () => { save(); refresh(); };
    el.addEventListener('click', e => {
      const row = e.target.closest('.day-row[data-date]'); const d = row && row.dataset.date;
      if (e.target.closest('[data-dec]')) { st.days[d] = Math.max(0, (+st.days[d] || 0) - 1); changed(); }
      if (e.target.closest('[data-inc]')) { st.days[d] = (+st.days[d] || 0) + 1; changed(); }
      if (e.target.closest('[data-remove]')) { delete st.days[d]; changed(); }
      if (e.target.closest('[data-adddate]')) {
        const v = $('[data-newdate]', el).value;
        if (!v) return toast('Pick a date to add.', 'warn');
        if (last && v < last.today) return toast('You cannot rent for a past date.', 'error');
        if (st.days[v] != null) return toast('That date is already in the schedule.', 'warn');
        if (last && Object.keys(st.days).length >= last.maxDays) return toast('Maximum of ' + last.maxDays + ' rental dates per item.', 'warn');
        st.days[v] = Math.max(1, +$('[data-allqty]', el).value || 1); changed();
      }
      if (e.target.closest('[data-applyall]')) { const q = Math.max(1, Math.floor(+$('[data-allqty]', el).value || 1)); Object.keys(st.days).forEach(k => { st.days[k] = q; }); changed(); }
    });
    el.addEventListener('change', e => { const i = e.target.closest('[data-qty]'); if (!i) return; const d = i.closest('.day-row').dataset.date; st.days[d] = Math.max(0, Math.floor(+i.value || 0)); changed(); });
    refresh();
    const self = { state: st, refresh, setDays(days) { st.days = days; refresh(); }, setSel(sel) { st.sel = sel; refresh(); }, last: () => last };
    el._scheduler = self;
    return self;
  }

  /* ---------- package configurator ----------
     <div data-pkg-config data-package="id" data-quote="/api/package-quote"> around market/_package-contents.
     Choices, add-ons and guests → re-quote, update prices, the hidden #selInput and the scheduler. */
  function PackageConfig(box) {
    let timer = null;
    const read = () => {
      const sel = { choices: {}, addons: [], guests: null };
      $$('[data-sel-choice]:checked', box).forEach(i => { sel.choices[i.dataset.selChoice] = +i.value; });
      $$('[data-sel-addon]:checked', box).forEach(i => sel.addons.push(+i.value));
      const g = $('[data-sel-guests]', box); if (g) sel.guests = Math.max(1, Math.floor(+g.value || 0));
      return sel;
    };
    const set = (sel, txt) => { $$(sel, document).forEach(x => { x.textContent = txt; }); };
    async function quote() {
      let r;
      try { r = await api(box.dataset.quote, { method: 'POST', body: JSON.stringify({ package_id: +box.dataset.package, sel: read() }) }); }
      catch (err) { return toast(err.message, 'error'); }
      const g = $('[data-sel-guests]', box); if (g && r.sel.guests != null && +g.value !== r.sel.guests) g.value = r.sel.guests;
      set('[data-pkg-price]', r.packageText); set('[data-pkg-headline]', r.packageText); set('[data-pkg-lead]', r.leadDays);
      const split = $('[data-pkg-split]', box);
      if (split) split.textContent = r.perDay > 0 && r.once > 0 ? r.perDayText + ' per day for rentals + ' + r.onceText + ' once for consumables & services' : (r.once > 0 ? 'Charged once (consumables & services)' : 'Per package, per day');
      const sv = $('[data-pkg-savings]', box); if (sv) sv.textContent = r.savings > 0 ? 'Save ' + r.savingsText + ' vs. ' + r.separateText + ' separately' : '';
      const hidden = $('#selInput'); if (hidden) hidden.value = JSON.stringify(r.sel);
      $$('#ps, #pe').forEach(i => { i.min = r.earliest; });
      const sch = $('[data-scheduler][data-type="package"]'); if (sch && sch._scheduler) sch._scheduler.setSel(r.sel);
    }
    box.addEventListener('change', e => { if (e.target.closest('[data-sel-choice],[data-sel-addon],[data-sel-guests]')) { clearTimeout(timer); timer = setTimeout(quote, 150); } });
  }

  /* ---------- availability calendar ---------- */
  function Calendar(el, opts) {
    opts = opts || {};
    let month = el.dataset.month, need = +(el.dataset.need || 1), selected = [], seq = 0;
    async function draw() {
      const my = ++seq; // only the latest request may draw (responses can come back out of order)
      let r; try { r = await api(window.PR.routes.calendar + '?' + new URLSearchParams({ kind: el.dataset.kind, id: el.dataset.id, month, need })); } catch (err) { if (my === seq) el.innerHTML = '<p class="small muted">Calendar unavailable.</p>'; return; }
      if (my !== seq) return;
      let cells = ['S', 'M', 'T', 'W', 'T', 'F', 'S'].map(d => '<div class="dow">' + d + '</div>').join('');
      for (let i = 0; i < r.startPad; i++) cells += '<div class="d blank"></div>';
      const s0 = selected[0], s1 = selected[selected.length - 1];
      r.days.forEach(d => { const on = selected.includes(d.date); cells += '<div class="d ' + d.cls + (on ? ' sel' : '') + (on && d.date === s0 ? ' sel-a' : '') + (on && d.date === s1 ? ' sel-b' : '') + (d.date === r.today ? ' today' : '') + '" title="' + d.date + ': ' + d.available + ' available"' + (d.cls !== 'past' ? ' data-pick="' + d.date + '" role="button" tabindex="0" aria-label="' + d.date + ', ' + d.available + ' available' + (on ? ', selected' : '') + '"' : '') + '><span class="n">' + d.n + '</span><span class="a">' + (d.cls === 'past' ? '' : d.available + ' free') + '</span></div>'; });
      el.innerHTML = '<div class="row between mb-1"><button type="button" class="btn btn-sm btn-ghost btn-icon" data-m="' + r.prev + '" aria-label="Previous month"' + (r.canPrev ? '' : ' disabled') + '>' + svg('left') + '</button><b>' + esc(r.title) + '</b><button type="button" class="btn btn-sm btn-ghost btn-icon" data-m="' + r.next + '" aria-label="Next month">' + svg('right') + '</button></div><div class="cal">' + cells + '</div>' +
        '<div class="legend mt-1"><span><i style="background:var(--brand);"></i>Your dates</span><span><i style="background:var(--green-50);border:1px solid #BCE6CB"></i>Enough for ' + need + '</span><span><i style="background:var(--gold-50);border:1px solid #FBDDA4"></i>Fewer than ' + need + '</span><span><i style="background:var(--red-50);border:1px solid #F8C4C4"></i>Fully booked</span></div>';
    }
    el.addEventListener('click', e => { const m = e.target.closest('[data-m]'); if (m) { month = m.dataset.m; draw(); } const p = e.target.closest('[data-pick]'); if (p && opts.onPick) opts.onPick(p.dataset.pick); });
    el.addEventListener('keydown', e => { const p = e.target.closest('[data-pick]'); if (p && opts.onPick && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); opts.onPick(p.dataset.pick); } });
    draw();
    return { update(o) { if (o.month) month = o.month; if (o.need) need = o.need; if (o.selected) selected = o.selected; draw(); } };
  }

  /* ---------- product / package rental box ----------
     <div data-rentbox data-type data-max-days> around partials/rent-box: #ps #pe (+ #mdToggle "Multiple days"),
     #pq quantity (#qtyRow), #perDayToggle "Different quantity per day" (#perDayWrap, only for several days) with
     the fixed-date scheduler in #perDay, the availability calendar and the form. */
  function RentBox(box) {
    const type = box.dataset.type;
    const ps = $('#ps', box), pe = $('#pe', box), pq = $('#pq', box), md = $('#mdToggle', box);
    const pdT = $('#perDayToggle', box), pdWrap = $('#perDayWrap', box), pdBox = $('#perDay', box), qRow = $('#qtyRow', box);
    let start = ps.value, end = pe.value || ps.value, qty = pq ? Math.max(1, +pq.value || 1) : 1, picking = 'start';
    const maxDays = +box.dataset.maxDays || 30;
    const multi = () => !!(md && md.checked);
    const perDay = () => !!(pdT && pdT.checked && start !== end);
    // per-day quantities survive date changes while "Different quantity per day" is on
    const daysFor = () => { const o = {}, old = sch ? sch.state.days : {}; if (!start) return o; range(start, end).forEach(d => { o[d] = perDay() && old[d] != null ? old[d] : qty; }); return o; };
    const ui = () => {
      box.classList.toggle('md-on', start !== end);
      if (pdWrap) pdWrap.classList.toggle('hidden', start === end);
      if (pdBox) pdBox.classList.toggle('hidden', !perDay());
      if (qRow) qRow.classList.toggle('hidden', perDay());
      if (calHint) calHint.textContent = multi() ? (picking === 'end' ? 'Now tap your last rental day.' : 'Tap your first rental day, then your last.') : 'Tap a date to rent for that day.';
    };
    const calEl = $('[data-calendar]', document);
    let calHint = null;
    if (calEl) { calHint = document.createElement('p'); calHint.className = 'small muted mb-1 cal-hint'; calEl.parentNode.insertBefore(calHint, calEl); }
    const setRange = (a, b) => {
      const t = sch && sch.last() ? sch.last().today : iso(new Date());
      const min = ps.min || t;
      if (a < min) { toast(a < t ? 'You cannot rent for a past date.' : 'The earliest date you can pick is ' + min + '.', 'error'); return false; }
      if (b < a) b = a;
      if (range(a, b).length > maxDays) { toast('Maximum rental period is ' + maxDays + ' days.', 'warn'); b = addDays(a, maxDays - 1); }
      start = a; end = b; ps.value = a; pe.value = b;
      if (md && start !== end && !md.checked) { md.checked = true; md.dispatchEvent(new Event('change')); }
      return true;
    };
    const cal = calEl ? Calendar(calEl, { onPick: d => {
      if (!multi()) { if (!setRange(d, d)) return; picking = 'start'; }
      else if (picking === 'end' && d >= start) { if (!setRange(start, d)) return; picking = 'start'; }
      else { if (!setRange(d, d)) return; picking = 'end'; }
      ui(); sch.setDays(daysFor()); box.dispatchEvent(new CustomEvent('multiday', { bubbles: true }));
    } }) : null;
    const sch = Scheduler($('[data-scheduler]', box), {
      onChange: (r, st) => {
        const dates = Object.keys(st.days).sort();
        const vals = Object.values(st.days).map(Number);
        const at = $('#availTxt', box);
        if (at) at.innerHTML = !dates.length ? '<span class="badge b-gray">Pick a date</span>' : r.ok ? '<span class="badge b-green">Available for your ' + (dates.length > 1 ? 'dates' : 'date') + '</span>' + (r.minAvailable != null ? '<div class="small muted">' + (dates.length > 1 ? 'Min. ' + r.minAvailable + ' free per day' : r.minAvailable + ' free') + '</div>' : '') : '<span class="badge b-red">Not enough stock</span><div class="small" style="color:var(--red)">' + r.bad.map(b => esc(b.date) + ': only ' + b.available).join(', ') + '</div>';
        const qb = $('#qbox', box); if (qb) qb.classList.toggle('bad', !r.ok);
        const est = $('#est', box); if (est) est.textContent = r.totalText + (dates.length > 1 ? ' · ' + dates.length + ' days' : '');
        $$('[data-tier]', box).forEach(t => { const min = +t.dataset.min, max = t.dataset.max ? +t.dataset.max : null; t.classList.toggle('on', vals.some(q => q >= min && (max == null || q <= max))); });
        $$('[data-rent-submit]', box).forEach(b => { b.disabled = !r.ok; });
        if (cal) cal.update({ month: (dates[0] || r.today).slice(0, 7), need: Math.max(1, ...vals, 1), selected: dates });
        const alt = $('#altBox', box);
        if (alt && window.PR.routes.alternatives) {
          if (dates.length && !r.ok && type === 'item') fetch(window.PR.routes.alternatives + '?' + new URLSearchParams({ days: JSON.stringify(st.days) }), { headers: { Accept: 'text/html' } }).then(x => x.text()).then(h => { alt.innerHTML = h; }).catch(() => {});
          else alt.innerHTML = '';
        }
      }
    });
    const onDates = () => {
      if (!ps.value) return;
      const a = ps.value, b = multi() && pe.value ? pe.value : ps.value;
      if (a === start && b === end && Object.keys(sch.state.days).length) return;
      if (!setRange(a, b)) { ps.value = start; pe.value = end; return; }
      picking = 'start'; ui(); sch.setDays(daysFor());
    };
    ps.addEventListener('change', onDates); pe.addEventListener('change', onDates);
    box.addEventListener('multiday', onDates);
    if (pdT) pdT.addEventListener('change', () => {
      if (!pdT.checked) { const q = Math.max(1, ...Object.values(sch.state.days).map(Number), 1); qty = q; if (pq) pq.value = q; }
      ui(); sch.setDays(daysFor());
    });
    if (pq) {
      const setQ = v => { qty = Math.max(1, Math.floor(v) || 1); pq.value = qty; Object.keys(sch.state.days).forEach(d => { sch.state.days[d] = qty; }); sch.refresh(); };
      pq.addEventListener('change', () => setQ(+pq.value));
      $('#qbox [data-d]', box).addEventListener('click', () => setQ(qty - 1));
      $('#qbox [data-i]', box).addEventListener('click', () => setQ(qty + 1));
    }
    ui();
  }

  /* ---------- cascading address selects ----------
     <div data-address> with select[data-level=region|province|city] (see partials/address-fields).
     Children lists come from /api/locations once per page; fires `pr:city` {cityCode} on change. */
  let locPromise = null;
  const locationTree = () => locPromise || (locPromise = api(window.PR.routes.locations).then(rows => {
    const kids = {}, byCode = {};
    rows.forEach(r => {
      const p = r[3] || '', x = { code: r[0], name: r[1], lat: r[4] != null ? r[4] : null, lng: r[5] != null ? r[5] : null };
      (kids[p] = kids[p] || []).push(x); byCode[x.code] = x;
    });
    Object.keys(kids).forEach(k => { if (k) kids[k].sort((a, b) => a.name.localeCompare(b.name)); });
    return { kids, byCode };
  }));
  function AddressPicker(box) {
    const sel = l => $('select[data-level="' + l + '"]', box);
    const fill = (s, list) => {
      const ph = s.options[0] ? s.options[0].text : '';
      s.innerHTML = '<option value="">' + esc(ph) + '</option>' + list.map(x => '<option value="' + esc(x.code) + '">' + esc(x.name) + '</option>').join('');
      s.value = ''; s.disabled = !list.length;
    };
    const emit = () => box.dispatchEvent(new CustomEvent('pr:city', { bubbles: true, detail: { cityCode: sel('city').value } }));
    const load = fn => locationTree().then(fn).catch(() => toast('Could not load the list of places. Please reload the page.', 'error'));
    sel('region').addEventListener('change', () => load(t => { fill(sel('province'), t.kids[sel('region').value] || []); fill(sel('city'), []); emit(); }));
    sel('province').addEventListener('change', () => load(t => { fill(sel('city'), t.kids[sel('province').value] || []); emit(); }));
    sel('city').addEventListener('change', emit);
  }

  /* ---------- map pins (Leaflet, loaded only on pages that include partials/leaflet) ---------- */
  const mapBase = (el, opts) => {
    const cfg = window.PR.map;
    const map = window.L.map(el, Object.assign({ maxBounds: cfg.bounds, maxBoundsViscosity: 0.8, minZoom: 5 }, opts || {})).setView(cfg.center, cfg.zoom);
    window.L.tileLayer(cfg.tiles, { attribution: cfg.attribution, maxZoom: 19 }).addTo(map);
    // maps inside hidden wizard steps / tabs have no size until shown
    if (window.ResizeObserver) new ResizeObserver(() => map.invalidateSize()).observe(el);
    return map;
  };
  const noMap = el => { el.innerHTML = '<div class="small muted" style="padding:16px">Map unavailable right now.</div>'; };

  /* <div data-map-picker data-city-from="shop_addr"> with [data-map], input[data-lat], input[data-lng],
     [data-locate], [data-unpin], [data-pin-status]. Click / drag to pin; follows the city picker until pinned. */
  function MapPicker(box) {
    const el = $('[data-map]', box), latI = $('[data-lat]', box), lngI = $('[data-lng]', box), st = $('[data-pin-status]', box);
    if (!window.L) return noMap(el);
    const map = mapBase(el);
    let marker = null;
    const status = () => { if (st) st.textContent = marker ? 'Pinned at ' + (+latI.value).toFixed(5) + ', ' + (+lngI.value).toFixed(5) + '. Drag the pin to adjust.' : 'No pin yet. Click the map at your location, or use your current location.'; };
    const changed = () => box.dispatchEvent(new CustomEvent('pr:pin', { bubbles: true }));
    const set = (lat, lng, zoomIn) => {
      latI.value = (+lat).toFixed(7); lngI.value = (+lng).toFixed(7);
      if (!marker) {
        marker = window.L.marker([lat, lng], { draggable: true, autoPan: true }).addTo(map);
        marker.on('dragend', () => { const p = marker.getLatLng(); set(p.lat, p.lng, false); });
      } else marker.setLatLng([lat, lng]);
      if (zoomIn) map.setView([lat, lng], Math.max(map.getZoom(), 16));
      status(); changed();
    };
    const centreOnCity = code => locationTree().then(t => { const c = t.byCode[code]; if (c && c.lat != null && !marker) map.setView([c.lat, c.lng], 13); }).catch(() => {});
    map.on('click', e => set(e.latlng.lat, e.latlng.lng, false));
    $('[data-locate]', box).addEventListener('click', () => {
      if (!navigator.geolocation) return toast('Your browser cannot share its location. Click the map instead.', 'warn');
      navigator.geolocation.getCurrentPosition(p => set(p.coords.latitude, p.coords.longitude, true),
        err => toast('Could not get your location (' + err.message + '). Click the map instead.', 'warn'), { enableHighAccuracy: true, timeout: 10000 });
    });
    $('[data-unpin]', box).addEventListener('click', () => { if (marker) { map.removeLayer(marker); marker = null; } latI.value = ''; lngI.value = ''; status(); changed(); });
    document.addEventListener('pr:city', e => { const a = e.target.closest && e.target.closest('[data-address]'); if (a && a.dataset.address === box.dataset.cityFrom && e.detail.cityCode) centreOnCity(e.detail.cityCode); });
    if (latI.value && lngI.value) set(latI.value, lngI.value, true);
    else if (box.dataset.city) centreOnCity(box.dataset.city);
    status();
  }

  /* Read-only: <div data-map-view data-lat data-lng [data-radius="2000"]> — a circle hides the exact spot. */
  function MapView(el) {
    if (!window.L) return noMap(el);
    const lat = +el.dataset.lat, lng = +el.dataset.lng, r = +el.dataset.radius || 0;
    const map = mapBase(el, { scrollWheelZoom: false });
    map.setView([lat, lng], r ? 12 : 16);
    if (r) window.L.circle([lat, lng], { radius: r, color: '#E11D74', weight: 2, fillOpacity: 0.12 }).addTo(map);
    else window.L.marker([lat, lng]).addTo(map);
  }

  /* ---------- AI chat widget ---------- */
  function Chat() {
    const fab = $('.chat-fab'), panel = $('.chat-panel'); if (!fab || !panel) return;
    const body = $('#chatBody', panel), sugg = $('#chatSugg', panel);
    let hist = []; try { hist = JSON.parse(sessionStorage.getItem('pr_chat') || '[]'); } catch (e) { /* storage unavailable */ }
    const add = (who, html) => { const d = document.createElement('div'); d.className = 'msg ' + who; d.innerHTML = html; body.appendChild(d); body.scrollTop = body.scrollHeight; };
    const chips = list => { sugg.innerHTML = (list || []).map(c => '<button class="chip" type="button">' + esc(c) + '</button>').join(''); };
    const persist = () => { try { sessionStorage.setItem('pr_chat', JSON.stringify(hist.slice(-30))); } catch (e) { /* ignore */ } };
    if (!hist.length) hist.push({ who: 'bot', html: "Hi! 👋 I'm <b>PartyRent Assist</b>. Ask me about products, prices, availability, payment policies, delivery, or your request status. I only use information listed on PartyRent and never make bookings for you." });
    hist.forEach(h => add(h.who, h.html));
    chips(['Plan a birthday for 50 guests', 'Tiffany chair prices', 'My request status', 'How do I rent?']);
    async function send(text) {
      if (!text.trim()) return;
      add('me', esc(text)); hist.push({ who: 'me', html: esc(text) });
      const typing = document.createElement('div'); typing.className = 'msg bot'; typing.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px"></span>'; body.appendChild(typing); body.scrollTop = body.scrollHeight;
      try { const r = await api(window.PR.routes.chat, { method: 'POST', body: JSON.stringify({ message: text }) }); typing.remove(); add('bot', r.html); hist.push({ who: 'bot', html: r.html }); chips(r.chips); persist(); }
      catch (err) { typing.remove(); add('bot', 'Sorry, I could not answer right now. Please try again.'); }
    }
    $('#chatForm', panel).addEventListener('submit', e => { e.preventDefault(); const i = e.target.m; send(i.value); i.value = ''; });
    sugg.addEventListener('click', e => { const b = e.target.closest('.chip'); if (b) send(b.textContent); });
    fab.addEventListener('click', () => { panel.classList.toggle('open'); if (panel.classList.contains('open')) $('input', panel).focus(); });
    $('[data-chat-close]', panel).addEventListener('click', () => panel.classList.remove('open'));
  }

  /* ---------- boot ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    (window.PR && window.PR.flash || []).forEach(f => toast(f[1], f[0]));
    $$('[data-address]').forEach(AddressPicker);
    $$('[data-map-picker]').forEach(MapPicker);
    $$('[data-map-view]').forEach(MapView);
    $$('[data-rentbox]').forEach(RentBox);
    $$('[data-pkg-config]').forEach(PackageConfig);

    // Show / hide password: an eye button inside every password field.
    var EYE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    $$('input[type=password]').forEach(function (inp) {
        if (inp.closest('.pw-wrap')) return;
        var wrap = document.createElement('div');
        wrap.className = 'pw-wrap';
        inp.parentNode.insertBefore(wrap, inp);
        wrap.appendChild(inp);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.innerHTML = EYE;
        btn.addEventListener('click', function () {
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            btn.innerHTML = show ? EYE_OFF : EYE;
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            inp.focus({ preventScroll: true });
        });
        wrap.appendChild(btn);
        // switch back before submitting so password managers still recognise the field
        if (inp.form) inp.form.addEventListener('submit', function () { inp.type = 'password'; });
    });

    // supplier payout account: the bank name only applies to bank transfers
    $$('[data-payout-method]').forEach(r => r.addEventListener('change', () => {
        const box = $('[data-for-bank]');
        if (box) box.hidden = r.value !== 'bank';
    }));
    $$('[data-scheduler]').forEach(el => { if (!el.closest('[data-rentbox]')) Scheduler(el); });
    $$('[data-calendar]').forEach(el => { if (!$('[data-rentbox]')) Calendar(el); });
    // cart page: reload totals after the scheduler autosaves
    document.addEventListener('pr:saved', e => { if (e.target.closest('[data-reload-on-save]')) { clearTimeout(window.__rl); window.__rl = setTimeout(() => location.reload(), 900); } });
    Chat();
    // live search on simple lists: <input data-live-search="#list">
    $$('[data-live-search]').forEach(inp => inp.addEventListener('input', () => { const q = inp.value.toLowerCase(); $$(inp.dataset.liveSearch + ' [data-search]').forEach(r => { r.style.display = r.dataset.search.toLowerCase().includes(q) ? '' : 'none'; }); }));
  });

  /* ---------- review photos/videos: lightbox ----------
     Thumbnails: <button data-media-group="g" data-media-type="image|video" data-media-src="url"> */
  function Lightbox() {
    const lb = $('#mediaLightbox'); if (!lb) return;
    const stage = $('.lb-stage', lb); const count = $('.lb-count', lb);
    let items = []; let idx = 0;
    const show = i => {
      idx = (i + items.length) % items.length;
      const it = items[idx];
      stage.innerHTML = it.type === 'video'
        ? '<video src="' + esc(it.src) + '" controls autoplay playsinline></video>'
        : '<img src="' + esc(it.src) + '" alt="Customer photo ' + (idx + 1) + ' of ' + items.length + '">';
      count.textContent = items.length > 1 ? (idx + 1) + ' / ' + items.length : '';
      $$('.lb-nav', lb).forEach(b => { b.style.display = items.length > 1 ? '' : 'none'; });
    };
    const close = () => { stage.innerHTML = ''; closeModal(lb); lb.setAttribute('aria-hidden', 'true'); };
    document.addEventListener('click', e => {
      const t = e.target.closest('[data-media-src]');
      if (t) {
        e.preventDefault();
        items = $$('[data-media-group="' + t.dataset.mediaGroup + '"]').map(b => ({ type: b.dataset.mediaType, src: b.dataset.mediaSrc }));
        openModal(lb); lb.setAttribute('aria-hidden', 'false');
        show(Math.max(0, $$('[data-media-group="' + t.dataset.mediaGroup + '"]').indexOf(t)));
        $('.lb-close', lb).focus();
        return;
      }
      if (lb.classList.contains('hidden')) return;
      const nav = e.target.closest('[data-lb]');
      if (nav) { show(idx + Number(nav.dataset.lb)); return; }
      if (e.target.closest('[data-close]') || e.target === lb || e.target.classList.contains('lightbox-inner')) close();
    }, true); // capture: runs before the generic modal handler, so a playing video is stopped on close
    document.addEventListener('keydown', e => {
      if (lb.classList.contains('hidden')) return;
      if (e.key === 'Escape') close();
      else if (e.key === 'ArrowRight' && items.length > 1) show(idx + 1);
      else if (e.key === 'ArrowLeft' && items.length > 1) show(idx - 1);
    });
    let x0 = null;
    lb.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
    lb.addEventListener('touchend', e => { if (x0 === null || items.length < 2) return; const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 50) show(idx + (dx < 0 ? 1 : -1)); x0 = null; });
  }

  /* ---------- review form: preview + check photos/videos before upload ---------- */
  function MediaInputs(form) {
    const urls = new WeakMap();
    const render = box => {
      const old = urls.get(box) || []; old.forEach(u => URL.revokeObjectURL(u));
      const made = [];
      box.innerHTML = $$('[data-media-input]', box.closest('fieldset')).flatMap(inp => Array.from(inp.files || []).map(f => {
        const u = URL.createObjectURL(f); made.push(u);
        return '<div class="mp">' + (inp.dataset.mediaInput === 'video' ? '<video src="' + u + '#t=0.1" muted playsinline preload="metadata"></video>' : '<img src="' + u + '" alt="">') + '<span>' + esc(f.name) + '</span></div>';
      })).join('');
      urls.set(box, made);
    };
    $$('[data-media-input]', form).forEach(inp => inp.addEventListener('change', () => {
      const max = Number(inp.dataset.max || 1); const mb = Number(inp.dataset.maxMb || 5);
      const files = Array.from(inp.files || []);
      const isVideo = inp.dataset.mediaInput === 'video';
      let err = '';
      if (files.length > max) err = 'You can add up to ' + max + ' ' + (isVideo ? 'video' : 'photos') + ' per item.';
      else if (files.some(f => f.size > mb * 1024 * 1024)) err = (isVideo ? 'The video' : 'Each photo') + ' must be ' + mb + ' MB or smaller.';
      else if (files.some(f => !f.type.startsWith(isVideo ? 'video/' : 'image/'))) err = isVideo ? 'Please choose a video file (MP4, WEBM or MOV).' : 'Please choose image files (JPG, PNG or WEBP).';
      if (err) { inp.value = ''; toast(err, 'error'); }
      render($('[data-media-preview]', inp.closest('fieldset')));
    }));
    form.addEventListener('submit', e => {
      const rated = $$('.star-input input:checked', form).length;
      if (!rated) { e.preventDefault(); toast('Tap the stars to rate at least one item.', 'error'); return; }
      const btn = $('button:not([type=button])', form); if (btn) { btn.disabled = true; btn.textContent = 'Uploading…'; }
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    Lightbox();
    $$('[data-review-form]').forEach(MediaInputs);
  });

  /* ---------- side drawer (reference UI.drawer): <button data-drawer="id">, [data-drawer-close="id"] ---------- */
  document.addEventListener('click', e => {
    const op = e.target.closest('[data-drawer]');
    if (op) { const d = document.getElementById(op.dataset.drawer); if (d) { d.classList.remove('hidden'); const bd = document.querySelector('.drawer-backdrop[data-drawer-close="' + op.dataset.drawer + '"]'); if (bd) bd.classList.remove('hidden'); document.body.style.overflow = 'hidden'; } }
    const cl = e.target.closest('[data-drawer-close]');
    if (cl) { const id = cl.dataset.drawerClose; const d = document.getElementById(id); if (d) d.classList.add('hidden'); const bd = document.querySelector('.drawer-backdrop[data-drawer-close="' + id + '"]'); if (bd) bd.classList.add('hidden'); document.body.style.overflow = ''; }
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $$('.drawer:not(.hidden)').forEach(d => { d.classList.add('hidden'); $$('.drawer-backdrop').forEach(b => b.classList.add('hidden')); document.body.style.overflow = ''; }); });

  /* ---------- browse: compare up to 3 items (floating bar) ---------- */
  const cmpBar = document.getElementById('compareBar');
  if (cmpBar) {
    const boxes = () => $$('input[name="compare[]"]');
    const sync = () => { const n = boxes().filter(b => b.checked).length; cmpBar.classList.toggle('hidden', !n); document.getElementById('compareN').textContent = n + ' selected'; };
    document.addEventListener('change', e => { if (e.target.name === 'compare[]') { if (boxes().filter(b => b.checked).length > 3) { e.target.checked = false; toast('You can compare up to 3 items.', 'warn'); } sync(); } });
    document.getElementById('compareClear').onclick = () => { boxes().forEach(b => { b.checked = false; }); sync(); };
    sync();
  }

  /* ---------- Rental List checkboxes (per item / per shop / Select all) ---------- */
  $$('[data-indet]').forEach(x => { x.indeterminate = true; });
  document.addEventListener('change', async e => {
    const box = e.target.closest && e.target.closest('input[data-select]'); if (!box) return;
    box.disabled = true;
    try { await api('/cart/select', { method: 'POST', body: JSON.stringify({ ids: JSON.parse(box.dataset.select), on: box.checked }) }); location.reload(); }
    catch (err) { box.disabled = false; toast(err.message, 'error'); }
  });

  window.PRUI = { toast, confirmDialog, openModal, closeModal, Scheduler, Calendar, AddressPicker, api, esc, icon: (n, st) => svg(n, st || 'width:16px;height:16px;vertical-align:-3px') };
})();
