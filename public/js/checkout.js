/* =========================================================
   PartyRent — checkout (port of C.checkout in the reference js/pages/customer-rent.js).
   One card per shop: event date, delivery/pickup, delivery locations (zone + address + time),
   items with rental times (assign / split between locations), services, payment plan and the
   installment proposal. Prices and validation come from the server (POST data-quote).
   Change list: delivery zone per location; switching Delivery → Pickup keeps the per-location
   split lines (no regrouping); Rental Charges sit collapsed in the Order Summary.
   ========================================================= */
(function () {
  'use strict';
  const form = document.getElementById('cf'); if (!form) return;
  const { toast, confirmDialog, esc } = window.PRUI;
  const $ = (s, el) => (el || document).querySelector(s);
  const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));
  const S = JSON.parse(form.dataset.checkout);
  const shared = S.shared;
  const orders = S.orders;
  const today = form.dataset.today;
  const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content;
  const money = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const addDays = (iso, n) => { const d = new Date(iso + 'T00:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
  const fmt12 = t => { const [h, m] = t.split(':').map(Number); if (h >= 24) return '12:00 AM (midnight)'; return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM'); };
  const svg = n => (window.PRUI.icon ? window.PRUI.icon(n) : '');
  const sel = (o, value, attrs) => { const opts = o.shop.times.slice(); if (value && !opts.includes(value)) opts.push(value); opts.sort(); return '<select class="input input-sm" ' + attrs + '>' + opts.map(t => '<option value="' + t + '"' + (t === value ? ' selected' : '') + '>' + fmt12(t) + '</option>').join('') + '</select>'; };
  const qtyTxt = l => { const v = Object.values(l.days).map(Number); const mn = Math.min(...v), mx = Math.max(...v); return (mn === mx ? mx : mn + '–' + mx) + '× · ' + v.length + ' day' + (v.length > 1 ? 's' : ''); };
  let lastQuote = {};

  /** Lines of the same cart item, time and location are combined again (never across locations). */
  function mergeSame(o) {
    const out = [];
    o.lines.forEach(l => { const m = out.find(x => x.cart_id === l.cart_id && x.destId === l.destId && x.time.start === l.time.start && x.time.end === l.time.end); if (m) Object.entries(l.days).forEach(([d, q]) => { m.days[d] = (Number(m.days[d]) || 0) + Number(q); }); else out.push(l); });
    o.lines = out;
  }

  function shopCard(o, idx) {
    const sh = o.shop; const sid = o.supplier_id;
    const zoneOpts = selz => sh.zones.map(z => '<option value="' + esc(z.id) + '"' + (z.id === selz ? ' selected' : '') + '>' + esc(z.name) + ' — ' + money(z.fee) + '</option>').join('') + '<option value=""' + (!selz ? ' selected' : '') + '>Other areas (standard rate) — ' + money(sh.deliveryFee) + '</option>';
    const q = lastQuote[sid] || {};
    const multi = o.fulfillment === 'delivery' && o.destinations.length > 1;
    return '<div class="card" data-shop="' + sid + '"><div class="row between mb-1"><div class="row" style="min-width:0"><div class="logo-tile" style="width:42px;height:42px;font-size:1.3rem;background:' + sh.color + '1a">' + esc(sh.logo) + '</div><div style="min-width:0"><div class="label">Order ' + (idx + 1) + ' of ' + orders.length + '</div><h3 class="mb-0">' + esc(sh.name) + '</h3><div class="small muted">' + esc(o.range) + ' · ' + o.lines.length + ' line(s)</div></div></div></div>' +
      '<div class="form-grid"><div class="field"><label>Event date</label><input class="input" type="date" data-k="eventDate" min="' + o.first + '" max="' + o.last + '" value="' + o.eventDate + '"><div class="hint">All payments to this shop are due on or before this date.</div></div><div class="field"><label>Payment</label><div class="input" style="display:flex;align-items:center;gap:8px;background:var(--surface-2)">' + svg('card') + 'Online payment through PartyRent (Xendit)</div><div class="hint">You pay after the supplier accepts. Each payment is confirmed automatically.</div></div></div>' +
      '<div class="label mt-1 mb-1">Delivery or pickup</div><div class="row wrap gap-sm mb-2">' + ['delivery', 'pickup'].map(f => { const ok = sh.fulfillment === 'both' || sh.fulfillment === f; return '<label class="chip ' + (o.fulfillment === f ? 'active' : '') + '" style="' + (ok ? '' : 'opacity:.45;pointer-events:none') + '"><input type="radio" name="ful_' + sid + '" value="' + f + '" data-ful hidden' + (o.fulfillment === f ? ' checked' : '') + (ok ? '' : ' disabled') + '>' + svg(f === 'delivery' ? 'truck' : 'store') + (f === 'delivery' ? 'Delivery' : 'Pickup at shop') + '</label>'; }).join('') + '</div>' +
      (o.fulfillment === 'delivery'
        ? '<div class="stack-sm">' + o.destinations.map((d, i) => '<div class="dest-card" data-dest="' + d.id + '"><div class="row between mb-1"><b>' + svg('map') + ' Location ' + (i + 1) + '</b>' + (o.destinations.length > 1 ? '<button type="button" class="btn btn-xs btn-ghost" data-rmdest="' + d.id + '">' + svg('trash') + 'Remove</button>' : '') + '</div><div class="form-grid"><div class="field"><label>Delivery zone</label><select class="input" data-d="zoneId">' + zoneOpts(d.zoneId) + '</select><div class="hint">The zone sets this location\'s delivery fee.</div></div><div class="field"><label>Location name / event</label><input class="input" data-d="label" value="' + esc(d.label) + '" placeholder="e.g. Church – Wedding Ceremony"></div><div class="field full"><label>Complete address</label><input class="input" data-d="address" value="' + esc(d.address) + '" placeholder="House no., street, barangay, city"></div><div class="field"><label>Deliver by</label>' + sel(o, d.deliveryTime || sh.times[0], 'data-d="deliveryTime"') + '</div><div class="field"><label>On-site contact (optional)</label><input class="input" data-d="contactName" value="' + esc(d.contactName) + '" placeholder="Name · number"></div></div></div>').join('') + '</div><button type="button" class="btn btn-sm mt-1" data-adddest>' + svg('plus') + 'Add another delivery location</button><p class="small muted mt-1 mb-0">Renting for several venues on the same day (e.g. church and reception)? Add a location for each and assign items or split quantities below. Each location has its own delivery fee.</p>'
        : '<div class="alert alert-info">' + svg('map') + '<div>Pickup at <b>' + esc(sh.address) + '</b> · ' + esc(sh.hours) + '</div></div>') +
      '<div class="label mt-2 mb-1">Items, rental times' + (multi ? ' & locations' : '') + '</div>' + o.lines.map((l, i) => '<div class="dest-line" data-li="' + i + '"><div style="min-width:0"><b>' + esc(l.name) + '</b> <span class="small muted">' + esc(qtyTxt(l)) + '</span>' + (o.fulfillment === 'pickup' && o.destinations.length > 1 && o.lines.filter(x => x.cart_id === l.cart_id).length > 1 ? ' <span class="badge b-gray plain">part ' + (o.lines.filter(x => x.cart_id === l.cart_id).indexOf(l) + 1) + '</span>' : '') + '<div class="time-pair mt-1" style="max-width:360px">' + sel(o, l.time.start, 'data-lt="start" aria-label="Start time"') + sel(o, l.time.end, 'data-lt="end" aria-label="End time"') + '</div></div><div class="stack-sm" style="align-items:flex-end">' + (multi ? '<select class="input input-sm" data-ldest aria-label="Delivery location">' + o.destinations.map((d, k) => '<option value="' + d.id + '"' + (d.id === l.destId ? ' selected' : '') + '>Location ' + (k + 1) + ': ' + esc(d.label) + '</option>').join('') + '</select><button type="button" class="btn btn-xs" data-split="' + i + '">Split quantity</button>' : '') + '</div></div>').join('') +
      (sh.services.length ? '<div class="label mt-2 mb-1">Services</div>' + sh.services.map(x => '<label class="check"><input type="checkbox" data-svc="' + esc(x.id) + '"' + (x.required || o.services.includes(x.id) ? ' checked' : '') + (x.required ? ' disabled' : '') + '> <b>' + esc(x.name) + '</b> — ' + money(x.amount) + (x.per === 'location' ? ' per visit' : '') + (x.required ? ' <span class="badge b-gray plain">included</span>' : '') + '<div class="small muted">' + esc(x.description || '') + '</div></label>').join('') + '<p class="small muted mb-0">Per-visit services are charged once for consecutive days at the same location, and again for each location change or separate date block.</p>' : '') +
      '<div class="label mt-2 mb-1">Payment plan</div><div class="stack-sm">' + sh.plans.map(p => { const dis = p.key === 'installment' && q.installmentOk === false; return '<label class="card card-flat row" style="padding:12px 14px;cursor:pointer;' + (dis ? 'opacity:.55' : '') + '"><input type="radio" name="plan_' + sid + '" value="' + p.key + '" data-plan' + (p.key === o.plan ? ' checked' : '') + (dis ? ' disabled' : '') + ' style="accent-color:var(--brand);width:18px;height:18px"><div class="grow"><b>' + esc(p.label) + '</b><div class="small muted">' + esc(p.summary) + '</div>' + (dis ? '<div class="small" style="color:var(--red)">' + esc(q.installmentMsg) + '</div>' : '') + '</div></label>'; }).join('') + '</div>' +
      (o.plan === 'installment' ? '<div class="card card-flat soft mt-1" style="padding:14px" data-inst><div class="row between wrap"><b>Propose your installment schedule</b><label class="small row" style="gap:6px">Installments <select class="input input-sm" data-instn style="width:auto">' + Array.from({ length: sh.maxInstallments }, (_, i) => '<option' + (i + 1 === o.inst.n ? ' selected' : '') + '>' + (i + 1) + '</option>').join('') + '</select></label></div><p class="small muted">Divide the installment balance into payments of your choice. Every payment must be due on or before the event date. The supplier reviews and approves the schedule.</p><div data-instrows>' + o.inst.items.map((it, i) => '<div class="row mb-1" style="gap:8px" data-ir="' + i + '"><span class="small muted" style="width:26px">#' + (i + 1) + '</span><input class="input input-sm" type="date" data-f="due" value="' + it.due + '" min="' + addDays(today, 1) + '" max="' + o.eventDate + '" aria-label="Due date ' + (i + 1) + '" style="width:auto"><input class="input input-sm" type="number" step="0.01" min="1" data-f="amount" value="' + it.amount + '" aria-label="Amount ' + (i + 1) + '" style="width:130px"></div>').join('') + '</div><div class="row between mt-1"><button type="button" class="btn btn-xs" data-even>Divide evenly</button><span class="small" data-instsum></span></div></div>' : '') +
      '<div class="mt-2" data-review>' + (q.html || '') + '</div></div>';
  }

  function draw() {
    $('#shops').innerHTML = orders.map(shopCard).join('');
    bind();
  }

  function payload() {
    return { shared, orders: orders.map(o => ({ supplier_id: o.supplier_id, cart_line_ids: o.cart_line_ids, eventDate: o.eventDate, fulfillment: o.fulfillment, destinations: o.fulfillment === 'delivery' ? o.destinations : [], plan: o.plan, method: o.method, services: o.services, lines: o.lines.map(l => ({ cart_id: l.cart_id, type: l.type, days: l.days, time: l.time, destId: o.fulfillment === 'delivery' ? l.destId : null })), inst: o.inst })) };
  }

  let qTimer = null, qSeq = 0;
  function requote(redraw) {
    clearTimeout(qTimer);
    qTimer = setTimeout(async () => {
      const seq = ++qSeq;
      try {
        const res = await fetch(form.dataset.quote, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(payload()) });
        const data = await res.json(); if (seq !== qSeq) return;
        let needDraw = !!redraw;
        data.orders.forEach(r => {
          lastQuote[r.supplier_id] = r;
          const o = orders.find(x => x.supplier_id === r.supplier_id); if (!o) return;
          if (o.plan === 'installment' && o.inst.auto && JSON.stringify(o.inst.items) !== JSON.stringify(r.suggest)) { o.inst.items = r.suggest; needDraw = true; }
          if (o.plan === 'installment' && r.installmentOk === false) { o.plan = o.shop.plans.find(p => p.key !== 'installment').key; needDraw = true; }
        });
        $('#sum').innerHTML = data.summaryHtml;
        if (needDraw) draw();
        else data.orders.forEach(r => { const box = $('[data-shop="' + r.supplier_id + '"] [data-review]'); if (box) box.innerHTML = r.html; instSum(r.supplier_id); });
      } catch (e) { toast('Could not update the prices. Check your connection.', 'error'); }
    }, 150);
  }
  function instSum(sid) {
    const o = orders.find(x => x.supplier_id === sid); const el = $('[data-shop="' + sid + '"] [data-instsum]'); if (!el || !lastQuote[sid]) return;
    const sum = Math.round(o.inst.items.reduce((a, x) => a + Number(x.amount || 0), 0) * 100) / 100; const fb = lastQuote[sid].finalBalance;
    el.innerHTML = 'Total ' + money(sum) + ' of ' + money(fb) + (Math.abs(sum - fb) > 0.009 ? ' <span style="color:var(--red)">· must match</span>' : ' <span style="color:var(--green)">✓</span>');
  }

  function bind() {
    orders.forEach(o => {
      const box = $('[data-shop="' + o.supplier_id + '"]'); if (!box) return;
      $$('[data-k]', box).forEach(el => el.onchange = () => { o[el.dataset.k] = el.value; o.inst.auto = true; requote(true); });
      // Delivery ↔ Pickup keeps every line (and its per-location split) as it is.
      $$('[data-ful]', box).forEach(el => el.onchange = () => { o.fulfillment = el.value; draw(); requote(); });
      $$('[data-dest]', box).forEach(card => $$('[data-d]', card).forEach(el => el.oninput = el.onchange = () => { const d = o.destinations.find(x => x.id === card.dataset.dest); d[el.dataset.d] = el.value; requote(); }));
      const add = $('[data-adddest]', box); if (add) add.onclick = () => { let n = o.destinations.length + 1; while (o.destinations.some(d => d.id === 'd' + n)) n++; o.destinations.push({ id: 'd' + n, label: 'Location ' + (o.destinations.length + 1), address: '', zoneId: '', deliveryTime: o.shop.times[0], contactName: '', contactPhone: '' }); draw(); requote(); };
      $$('[data-rmdest]', box).forEach(b => b.onclick = () => { const id = b.dataset.rmdest; const keep = o.destinations.find(d => d.id !== id); o.lines.forEach(l => { if (l.destId === id) l.destId = keep.id; }); o.destinations = o.destinations.filter(d => d.id !== id); mergeSame(o); draw(); requote(); });
      $$('[data-li]', box).forEach(row => {
        const l = o.lines[+row.dataset.li];
        $$('[data-lt]', row).forEach(s => s.onchange = () => { l.time[s.dataset.lt] = s.value; requote(); });
        const ds = $('[data-ldest]', row); if (ds) ds.onchange = () => { l.destId = ds.value; mergeSame(o); draw(); requote(); };
        const sp = $('[data-split]', row); if (sp) sp.onclick = () => splitLine(o, +sp.dataset.split);
      });
      $$('[data-svc]', box).forEach(c => c.onchange = () => { o.services = $$('[data-svc]:checked:not([disabled])', box).map(x => x.dataset.svc); o.inst.auto = true; requote(); });
      $$('[data-plan]', box).forEach(r => r.onchange = () => { o.plan = r.value; o.inst.auto = true; o.inst.items = []; requote(true); });
      const n = $('[data-instn]', box); if (n) n.onchange = () => { o.inst.n = +n.value; o.inst.auto = true; requote(true); };
      const ev = $('[data-even]', box); if (ev) ev.onclick = () => { o.inst.auto = true; requote(true); };
      $$('[data-ir] [data-f]', box).forEach(inp => inp.onchange = () => { const it = o.inst.items[+inp.closest('[data-ir]').dataset.ir]; it[inp.dataset.f] = inp.dataset.f === 'amount' ? Math.round((+inp.value || 0) * 100) / 100 : inp.value; o.inst.auto = false; instSum(o.supplier_id); requote(); });
      instSum(o.supplier_id);
    });
  }

  function splitLine(o, i) {
    const l = o.lines[i]; const max = Math.max(...Object.values(l.days).map(Number));
    if (max < 2) return toast('There is only 1 per day — nothing to split.', 'warn');
    const bd = document.createElement('div'); bd.className = 'modal-backdrop';
    bd.innerHTML = '<div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>Split ' + esc(l.name) + '</h3><button class="icon-btn" data-x aria-label="Close">' + svg('x') + '</button></div><div class="modal-body"><p class="small muted">Move part of the quantity to another delivery location (for example 20 sets to the church and 20 to the reception). The total stays the same — nothing is charged twice.</p><div class="form-grid"><div class="field"><label>Quantity to move (per day)</label><input class="input" type="number" min="1" max="' + (max - 1) + '" id="spq" value="' + Math.max(1, Math.floor(max / 2)) + '"></div><div class="field"><label>Move to</label><select class="input" id="spd">' + o.destinations.filter(d => d.id !== l.destId).map(d => '<option value="' + d.id + '">' + esc(d.label) + '</option>').join('') + '</select></div><div class="field"><label>Start time there</label>' + sel(o, l.time.start, 'id="sps"') + '</div><div class="field"><label>End time there</label>' + sel(o, l.time.end, 'id="spe"') + '</div></div></div><div class="modal-foot"><button class="btn" data-x>Cancel</button><button class="btn btn-primary" id="spok">Split</button></div></div>';
    document.body.appendChild(bd);
    const close = () => bd.remove();
    bd.addEventListener('click', e => { if (e.target === bd || e.target.closest('[data-x]')) close(); });
    $('#spok', bd).onclick = () => {
      const n = Math.floor(+$('#spq', bd).value || 0);
      if (!(n >= 1 && n < max)) return toast('Enter a quantity between 1 and ' + (max - 1) + '.', 'error');
      const moved = {}; Object.keys(l.days).forEach(d => { const q = Math.min(Number(l.days[d]), n); if (q) { moved[d] = q; l.days[d] = Number(l.days[d]) - q; if (!l.days[d]) delete l.days[d]; } });
      o.lines.splice(i + 1, 0, { cart_id: l.cart_id, name: l.name, type: l.type, group: l.group, days: moved, time: { start: $('#sps', bd).value, end: $('#spe', bd).value }, destId: $('#spd', bd).value });
      if (!Object.keys(l.days).length) o.lines.splice(i, 1);
      close(); mergeSame(o); draw(); requote();
    };
  }

  $$('[data-s]', form).forEach(el => el.oninput = el.onchange = () => { shared[el.dataset.s] = el.value; el.closest('.field').classList.remove('invalid'); requote(); });
  $('#submitBtn').onclick = async () => {
    let bad = false;
    $$('[data-s][required]', form).forEach(el => { const ok = el.value.trim().length >= (+el.getAttribute('minlength') || 1); el.closest('.field').classList.toggle('invalid', !ok); if (!ok) bad = true; });
    if (bad) return toast('Please complete the event details.', 'error');
    if (!$('#agree').checked) return toast('Please accept the shops\' policies.', 'warn');
    const notOk = orders.find(o => lastQuote[o.supplier_id] && !lastQuote[o.supplier_id].ok);
    if (notOk) { $('[data-shop="' + notOk.supplier_id + '"]').scrollIntoView({ behavior: 'smooth' }); return toast(notOk.shop.name + ': ' + lastQuote[notOk.supplier_id].error, 'error'); }
    const list = orders.map(o => o.shop.name + ' — ' + money((lastQuote[o.supplier_id] || {}).total)).join(' · ');
    if (!await confirmDialog({ title: 'Submit ' + (orders.length > 1 ? orders.length + ' rental requests' : 'rental request') + '?', message: 'Nothing is reserved until a supplier accepts. ' + list, ok: 'Submit' })) return;
    const btn = $('#submitBtn'); const label = btn.textContent; btn.disabled = true; btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;border-top-color:#fff"></span> Submitting…';
    try {
      const res = await fetch(form.dataset.submit, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(Object.assign(payload(), { agree: 1 })) });
      const data = await res.json();
      if (res.ok && data.ok) { location.href = data.redirect; return; }
      toast(data.error || data.message || 'Please check the form.', 'error'); requote();
    } catch (e) { toast('Could not submit. Check your connection.', 'error'); }
    btn.disabled = false; btn.textContent = label;
  };

  draw(); requote(true);
})();
