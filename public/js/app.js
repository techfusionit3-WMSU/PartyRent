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
      bd.innerHTML = '<div class="modal sm" role="dialog" aria-modal="true"><div class="modal-head"><h3>' + esc(o.title || 'Please confirm') + '</h3><button class="icon-btn" data-x aria-label="Close">' + svg('x') + '</button></div><div class="modal-body"><p style="margin:0">' + esc(o.message || 'Are you sure?') + '</p></div><div class="modal-foot"><button class="btn" data-x>Cancel</button><button class="btn ' + (o.danger ? 'btn-danger' : 'btn-primary') + '" data-ok>' + esc(o.ok || 'Confirm') + '</button></div></div>';
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
      const ok = await confirmDialog({ title: f.dataset.confirmTitle, message: f.dataset.confirm, ok: f.dataset.confirmOk, danger: f.hasAttribute('data-danger') });
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
    const st = { type: el.dataset.type, ref: +el.dataset.ref, days: JSON.parse(el.dataset.days || '{}'), siblings: JSON.parse(el.dataset.siblings || '[]') };
    let timer = null, last = null;
    const sync = () => { const t = el.dataset.sync && $(el.dataset.sync); if (t) t.value = JSON.stringify(st.days); };
    const save = () => { if (!el.dataset.save) return; clearTimeout(timer); timer = setTimeout(async () => { try { await api(el.dataset.save, { method: 'PATCH', body: JSON.stringify({ days: st.days }) }); el.dispatchEvent(new CustomEvent('pr:saved', { bubbles: true })); } catch (err) { toast('Could not save: ' + err.message, 'error'); } }, 450); };
    async function refresh() {
      sync();
      let r;
      try { r = await api(window.PR.routes.schedule, { method: 'POST', body: JSON.stringify({ type: st.type, ref_id: st.ref, days: st.days, siblings: st.siblings }) }); }
      catch (err) { el.innerHTML = '<div class="alert alert-danger">' + svg('alert') + '<div>' + esc(err.message) + '</div></div>'; return; }
      last = r;
      const rows = r.rows.map(x => '<div class="day-row ' + (x.ok ? '' : 'bad') + '" data-date="' + x.date + '"><div class="dl">' + esc(x.label) + (x.past ? ' <span class="badge b-red">Past date</span>' : '') + '</div>' +
        '<div data-l="Quantity"><div class="qty ' + (x.ok ? '' : 'bad') + '"><button type="button" data-dec aria-label="Decrease">−</button><input type="number" min="0" value="' + x.qty + '" data-qty aria-label="Quantity for ' + esc(x.label) + '"><button type="button" data-inc aria-label="Increase">+</button></div></div>' +
        '<div data-l="Available">' + (x.ok || x.past ? '<span class="avail-pill ' + (x.available - x.qty < 5 && st.type === 'item' ? 'low' : 'ok') + '">' + x.available + ' ' + (st.type === 'package' ? 'pkg' : 'avail.') + '</span>' : '<span class="avail-pill none">Only ' + x.available + '</span>') + '</div>' +
        '<div data-l="Daily rate">' + esc(x.unitText) + '<div class="small muted">/' + esc(r.unitWord) + (x.tier ? ' · tier ' + esc(x.tier) : '') + '</div></div>' +
        '<div data-l="Subtotal" class="strong">' + esc(x.subtotalText) + '</div>' +
        '<div class="rm"><button type="button" class="icon-btn" data-remove aria-label="Remove date" style="width:34px;height:34px">' + svg('trash', 'width:17px') + '</button></div></div>').join('');
      const first = Object.keys(st.days).sort()[0];
      el.innerHTML = '<div class="day-editor"><div class="day-row head"><div>Date</div><div>Requested qty</div><div>Available</div><div>Daily rate</div><div>Daily subtotal</div><div></div></div>' + (rows || '<div class="empty small" style="padding:18px">No dates yet — add a rental date below.</div>') + '</div>' +
        '<div class="row wrap mt-1" style="gap:8px"><input type="date" class="input input-sm" style="width:auto" data-newdate min="' + r.today + '" aria-label="Add a rental date"><button type="button" class="btn btn-sm" data-adddate>' + svg('plus') + 'Add date</button><span class="grow"></span><div class="row" style="gap:6px"><span class="small muted">Same qty for all:</span><input type="number" min="1" class="input input-sm" style="width:80px" data-allqty value="' + (first ? st.days[first] : 1) + '" aria-label="Quantity for all dates"><button type="button" class="btn btn-sm btn-outline" data-applyall>Apply</button></div></div>' +
        '<div class="row between mt-1"><div class="small">' + (r.ok ? '<span style="color:var(--green)">' + svg('check', 'width:15px;vertical-align:-3px') + ' All dates available</span>' : '<span style="color:var(--red)">' + svg('alert', 'width:15px;vertical-align:-3px') + ' Fix the highlighted dates before requesting</span>') + '</div><div class="strong">Line total: <span class="price">' + esc(r.totalText) + '</span></div></div>';
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
    return { state: st, refresh, setDays(days) { st.days = days; refresh(); }, last: () => last };
  }

  /* ---------- availability calendar ---------- */
  function Calendar(el, opts) {
    opts = opts || {};
    let month = el.dataset.month, need = +(el.dataset.need || 1), selected = [];
    async function draw() {
      let r; try { r = await api(window.PR.routes.calendar + '?' + new URLSearchParams({ kind: el.dataset.kind, id: el.dataset.id, month, need })); } catch (err) { el.innerHTML = '<p class="small muted">Calendar unavailable.</p>'; return; }
      let cells = ['S', 'M', 'T', 'W', 'T', 'F', 'S'].map(d => '<div class="dow">' + d + '</div>').join('');
      for (let i = 0; i < r.startPad; i++) cells += '<div class="d blank"></div>';
      r.days.forEach(d => { cells += '<div class="d ' + d.cls + (selected.includes(d.date) ? ' sel' : '') + (d.date === r.today ? ' today' : '') + '" title="' + d.date + ': ' + d.available + ' available"' + (d.cls !== 'past' ? ' data-pick="' + d.date + '" style="cursor:pointer"' : '') + '><span class="n">' + d.n + '</span><span class="a">' + (d.cls === 'past' ? '' : d.available) + '</span></div>'; });
      el.innerHTML = '<div class="row between mb-1"><button type="button" class="btn btn-sm btn-ghost btn-icon" data-m="' + r.prev + '" aria-label="Previous month"' + (r.canPrev ? '' : ' disabled') + '>' + svg('left') + '</button><b>' + esc(r.title) + '</b><button type="button" class="btn btn-sm btn-ghost btn-icon" data-m="' + r.next + '" aria-label="Next month">' + svg('right') + '</button></div><div class="cal">' + cells + '</div>' +
        '<div class="legend mt-1"><span><i style="background:var(--green-50);border:1px solid #BCE6CB"></i>Enough for ' + need + '</span><span><i style="background:var(--gold-50);border:1px solid #FBDDA4"></i>Fewer than ' + need + '</span><span><i style="background:var(--red-50);border:1px solid #F8C4C4"></i>Fully booked</span></div>';
    }
    el.addEventListener('click', e => { const m = e.target.closest('[data-m]'); if (m) { month = m.dataset.m; draw(); } const p = e.target.closest('[data-pick]'); if (p && opts.onPick) opts.onPick(p.dataset.pick); });
    draw();
    return { update(o) { if (o.month) month = o.month; if (o.need) need = o.need; if (o.selected) selected = o.selected; draw(); } };
  }

  /* ---------- product / package rental box ----------
     <div data-rentbox data-type data-ref data-start data-end data-qty data-max-days> with #ps #pe #pq, scheduler, calendar, form */
  function RentBox(box) {
    const type = box.dataset.type;
    const ps = $('#ps', box), pe = $('#pe', box), pq = $('#pq', box);
    let start = ps.value, end = pe.value, qty = pq ? Math.max(1, +pq.value || 1) : 1;
    const maxDays = +box.dataset.maxDays || 30;
    const daysFor = () => { const o = {}; range(start, end).forEach(d => { o[d] = qty; }); return o; };
    const cal = $('[data-calendar]', document) ? Calendar($('[data-calendar]'), { onPick: d => { if (d < start || d > end || start !== end) { start = d; end = d; } else { end = d; } ps.value = start; pe.value = end; sch.setDays(daysFor()); } }) : null;
    const sch = Scheduler($('[data-scheduler]', box), {
      onChange: (r, st) => {
        const dates = Object.keys(st.days).sort();
        const vals = Object.values(st.days).map(Number);
        const custom = new Set(vals).size > 1;
        const at = $('#availTxt', box);
        if (at) at.innerHTML = !dates.length ? '<span class="badge b-gray">Pick dates</span>' : r.ok ? '<span class="badge b-green">Available for your dates</span>' + (r.minAvailable != null ? '<div class="small muted">Min. ' + r.minAvailable + ' free per day</div>' : '') : '<span class="badge b-red">Not enough stock</span><div class="small" style="color:var(--red)">' + r.bad.map(b => esc(b.date) + ': only ' + b.available).join(', ') + '</div>';
        const qb = $('#qbox', box); if (qb) qb.classList.toggle('bad', !r.ok);
        const est = $('#est', box); if (est) est.textContent = r.totalText + (dates.length > 1 ? ' · ' + dates.length + ' days' : '');
        $$('[data-tier]', box).forEach(t => { const min = +t.dataset.min, max = t.dataset.max ? +t.dataset.max : null; t.classList.toggle('on', vals.some(q => q >= min && (max == null || q <= max))); });
        if (custom && $('#perDay', box)) $('#perDay', box).open = true;
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
      const t = sch.last() ? sch.last().today : iso(new Date());
      if (ps.value < t) { toast('Start date cannot be in the past.', 'error'); ps.value = start; return; }
      start = ps.value; end = pe.value && pe.value >= start ? pe.value : start;
      if (range(start, end).length > maxDays) { toast('Maximum rental period is ' + maxDays + ' days.', 'warn'); end = addDays(start, maxDays - 1); }
      pe.value = end; sch.setDays(daysFor());
    };
    ps.addEventListener('change', onDates); pe.addEventListener('change', onDates);
    if (pq) {
      const setQ = v => { qty = Math.max(1, Math.floor(v) || 1); pq.value = qty; Object.keys(sch.state.days).forEach(d => { sch.state.days[d] = qty; }); sch.refresh(); };
      pq.addEventListener('change', () => setQ(+pq.value));
      $('#qbox [data-d]', box).addEventListener('click', () => setQ(qty - 1));
      $('#qbox [data-i]', box).addEventListener('click', () => setQ(qty + 1));
    }
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
    $$('[data-rentbox]').forEach(RentBox);
    $$('[data-scheduler]').forEach(el => { if (!el.closest('[data-rentbox]')) Scheduler(el); });
    $$('[data-calendar]').forEach(el => { if (!$('[data-rentbox]')) Calendar(el); });
    // cart page: reload totals after the scheduler autosaves
    document.addEventListener('pr:saved', e => { if (e.target.closest('[data-reload-on-save]')) { clearTimeout(window.__rl); window.__rl = setTimeout(() => location.reload(), 900); } });
    Chat();
    // live search on simple lists: <input data-live-search="#list">
    $$('[data-live-search]').forEach(inp => inp.addEventListener('input', () => { const q = inp.value.toLowerCase(); $$(inp.dataset.liveSearch + ' [data-search]').forEach(r => { r.style.display = r.dataset.search.toLowerCase().includes(q) ? '' : 'none'; }); }));
  });

  window.PRUI = { toast, confirmDialog, openModal, closeModal, Scheduler, Calendar, api };
})();
