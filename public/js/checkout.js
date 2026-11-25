/* =========================================================
   PartyRent — checkout (port of C.checkout in the reference js/pages/customer-rent.js).
   One card per shop: event date, delivery/pickup, delivery fee zone and time, items with rental times, services and
   payment plan. Then ONE "Delivery information" card: the delivery address (search, region / province / city,
   barangay, street, map pin), contact and General Instructions, used by every shop that delivers. The server picks
   each shop's delivery branch from that address (customers cannot override it).
   Older notes: one card per shop: event date, delivery/pickup, delivery locations (zone + address + time),
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
  // date-only math in UTC: local midnight + toISOString() moved the date back a day in UTC+ zones (Philippines),
  // so "next day" stayed on the same day (the event-day list repeated one date 400 times)
  const addDays = (iso, n) => { const d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  const fmt12 = t => { const [h, m] = t.split(':').map(Number); if (h >= 24) return '12:00 AM (midnight)'; return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM'); };
  const svg = n => (window.PRUI.icon ? window.PRUI.icon(n) : '');
  const sel = (o, value, attrs) => { const opts = o.shop.times.slice(); if (value && !opts.includes(value)) opts.push(value); opts.sort(); return '<select class="input input-sm" ' + attrs + '>' + opts.map(t => '<option value="' + t + '"' + (t === value ? ' selected' : '') + '>' + fmt12(t) + '</option>').join('') + '</select>'; };
  const qtyTxt = l => { const v = Object.values(l.days).map(Number); const mn = Math.min(...v), mx = Math.max(...v); return (mn === mx ? mx : mn + '–' + mx) + '× · ' + v.length + ' day' + (v.length > 1 ? 's' : ''); };
  let lastQuote = {};

  /* ---------- delivery: ONE address section for the whole checkout ----------
     G.locs = the delivery locations (one by default; more only with "Deliver to more than one location").
     Every store set to Delivery uses them: its order's destinations mirror G.locs and each item says which location
     it goes to. The customer enters the address; the SERVER works out, per store and location, the delivery zone,
     the fee and the branch that delivers (Rentals::prepareOrder → Pricing::zoneForAddress, Branches::choose).
     Here they are only shown, read-only, under each location. */
  const delivEl = $('#deliv');
  const KEY = 'pr.checkout.delivery';
  const store = { get() { try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }, set(v) { try { sessionStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* private mode: nothing kept */ } } };
  const anyDelivery = () => orders.some(o => o.fulfillment === 'delivery');
  const newLoc = n => ({ id: 'd' + n, label: '', addr: { regionCode: '', provinceCode: '', cityCode: '', barangay: '', street: '' }, text: '', lat: null, lng: null, contactName: '' });
  const G = { multi: false, locs: [newLoc(1)] };
  const saveState = () => store.set({ multi: G.multi, locs: G.locs, instructions: shared.instructions || '' });
  const locName = (g, i) => G.multi ? (g.label || 'Location ' + (i + 1)) : 'Delivery address';

  let T = null, treeP = null;
  const getTree = () => treeP || (treeP = fetch(window.PR.routes.locations, { headers: { Accept: 'application/json' } }).then(r => r.json()).then(rows => {
    const byCode = {}, kids = {};
    rows.forEach(r => { const x = { code: r[0], name: r[1], type: r[2], parent: r[3] || '', lat: r[4] != null ? r[4] : null, lng: r[5] != null ? r[5] : null }; byCode[x.code] = x; (kids[x.parent] = kids[x.parent] || []).push(x); });
    Object.values(kids).forEach(l => l.sort((a, b) => a.name.localeCompare(b.name)));
    const cities = Object.values(byCode).filter(x => x.type === 'city' || x.type === 'municipality');
    cities.forEach(c => { const pr = byCode[c.parent]; c.label = c.name + (pr ? ', ' + pr.name : ''); c.key = c.name.toLowerCase(); });
    T = { byCode, kids, cities };
    return T;
  }));
  /** region / province / city codes above a city (the selects follow them). */
  function chainOf(code) {
    const c = T && T.byCode[code]; if (!c) return null;
    const up = []; let x = c; while (x && x.parent && T.byCode[x.parent]) { x = T.byCode[x.parent]; up.unshift(x); }
    return { regionCode: up[0] ? up[0].code : '', provinceCode: up.length > 1 ? up[up.length - 1].code : '', cityCode: code };
  }
  const nearestCity = (lat, lng) => { let best = null, bd = Infinity; (T ? T.cities : []).forEach(c => { if (c.lat == null) return; const d = (c.lat - lat) ** 2 + ((c.lng - lng) * Math.cos(lat * Math.PI / 180)) ** 2; if (d < bd) { bd = d; best = c; } }); return best; };
  function textOf(g) {
    const a = g.addr, c = T && T.byCode[a.cityCode], p = T && c && T.byCode[c.parent];
    const b = (a.barangay || '').trim();
    return [(a.street || '').trim(), b ? (/^(brgy|barangay)\b/i.test(b) ? b : 'Brgy. ' + b) : '', c ? c.name + (p ? ', ' + p.name : '') : ''].filter(Boolean).join(', ');
  }
  /** Every delivering store's order mirrors the locations; items without a valid location go to the first. */
  function syncOrders() {
    G.locs.forEach(g => { g.text = textOf(g); });
    orders.forEach(o => {
      o.dt = o.dt || {};
      if (o.fulfillment !== 'delivery') return;
      o.destinations = G.locs.map((g, i) => Object.assign({ id: g.id, label: locName(g, i), address: g.text, addr: Object.assign({}, g.addr), contactName: g.contactName || '', contactPhone: '',
        deliveryTime: o.dt[g.id] || o.shop.times[0] }, g.lat != null ? { lat: g.lat, lng: g.lng } : {}));
      const ids = G.locs.map(g => g.id);
      o.lines.forEach(l => { if (!G.multi || !ids.includes(l.destId)) l.destId = ids[0]; });
      if (!G.multi) mergeSame(o);
    });
    saveState();
  }
  const opt = (list, val, ph) => '<option value="">' + esc(ph) + '</option>' + list.map(x => '<option value="' + esc(x.code) + '"' + (x.code === val ? ' selected' : '') + '>' + esc(x.name) + '</option>').join('');

  /** One location: address first (search / current location / saved address, then the fields, then the map pin). */
  function locCard(g, i) {
    const a = g.addr, k = T ? T.kids : {}, pinned = g.lat != null;
    const regions = (k[''] || []).filter(x => x.type === 'region' || !x.type || x.type === 'reg');
    const f = (n, label, inner, cls) => '<div class="field ' + (cls || '') + '" data-f="' + n + '"><label for="' + g.id + '-' + n + '">' + label + '</label>' + inner + '</div>';
    return '<div class="loc-card" data-loc="' + g.id + '">' +
      '<div class="loc-head"><b>' + svg('map') + ' ' + esc(locName(g, i)) + '</b>' + (G.multi && i > 0 ? '<button type="button" class="btn btn-xs btn-ghost" data-rmloc>' + svg('trash') + 'Remove</button>' : '') + '</div>' +
      (G.multi ? f('label', 'Name of this location <span class="muted small">(optional)</span>', '<input class="input" id="' + g.id + '-label" data-lf="label" value="' + esc(g.label) + '" placeholder="e.g. Church, Reception hall">') : '') +
      '<div class="field place-find"><label for="' + g.id + '-q">Find your city or municipality</label><div class="place-search"><input class="input" id="' + g.id + '-q" type="search" autocomplete="off" placeholder="Type to search, e.g. Zamboanga City" data-place aria-expanded="false"><ul class="place-sugg" role="listbox" hidden></ul></div>' +
        '<div class="row wrap mt-1" style="gap:6px"><button type="button" class="btn btn-sm" data-locate>' + svg('map') + 'Use my current location</button>' + (i === 0 && S.saved && S.saved.cityCode ? '<button type="button" class="btn btn-sm" data-saved>' + svg('user') + 'Use my saved address</button>' : '') + '</div></div>' +
      '<div class="form-grid">' +
        f('regionCode', 'Region', '<select class="input" id="' + g.id + '-regionCode" data-af="regionCode">' + opt(regions, a.regionCode, 'Select region…') + '</select>') +
        f('provinceCode', 'Province', '<select class="input" id="' + g.id + '-provinceCode" data-af="provinceCode"' + (a.regionCode ? '' : ' disabled') + '>' + opt(k[a.regionCode] || [], a.provinceCode, 'Select province…') + '</select>') +
        f('cityCode', 'City / municipality', '<select class="input" id="' + g.id + '-cityCode" data-af="cityCode"' + (a.provinceCode || a.regionCode ? '' : ' disabled') + '>' + opt(k[a.provinceCode || a.regionCode] || [], a.cityCode, 'Select city / municipality…') + '</select>') +
        f('barangay', 'Barangay', '<input class="input" id="' + g.id + '-barangay" data-af="barangay" maxlength="120" value="' + esc(a.barangay) + '" placeholder="e.g. Tetuan">') +
        f('street', 'House no., building and street', '<input class="input" id="' + g.id + '-street" data-af="street" maxlength="180" value="' + esc(a.street) + '" placeholder="e.g. 23 Mabini St." autocomplete="street-address">', 'full') +
      '</div>' +
      '<div class="row wrap mt-1" style="gap:8px;align-items:center"><button type="button" class="btn btn-sm' + (pinned ? ' btn-outline' : '') + '" data-pinloc>' + svg('map') + (pinned ? 'Pinned · move pin' : 'Pin on map') + '</button><span class="small muted">' + (pinned ? 'Pinned at ' + (+g.lat).toFixed(5) + ', ' + (+g.lng).toFixed(5) + '. The driver gets this exact spot.' : 'Optional: pin the exact spot. It also finds the zone and branch more precisely.') + '</span></div>' +
      f('contactName', 'On-site contact <span class="muted small">(optional)</span>', '<input class="input" id="' + g.id + '-contactName" data-lf="contactName" value="' + esc(g.contactName) + '" placeholder="Name and number of who receives the items">', 'mt-1') +
      '<div class="drops" data-drops="' + g.id + '">' + dropsHtml(g) + '</div>' +
    '</div>';
  }

  /** What the system found for this location, per store delivering there: zone, fee, branch, and the time to deliver by. */
  function dropsHtml(g) {
    const list = orders.filter(o => o.fulfillment === 'delivery' && o.lines.some(l => l.destId === g.id));
    if (!list.length) return G.multi ? '<p class="small muted mb-0">No items go here yet. In the store sections above, choose this location for the items that should be delivered here.</p>' : '';
    const ready = g.addr.cityCode && (g.addr.barangay || '').trim() && (g.addr.street || '').trim().length >= 3;
    return '<div class="label mt-2 mb-1">Delivery details for this address</div>' + list.map(o => {
      const q = lastQuote[o.supplier_id] || {}, d = (q.drops || []).find(x => x.destId === g.id);
      let info;
      if (!ready) info = '<span class="muted">Complete the address to see the delivery zone, fee and branch.</span>';
      else if (q.ok === false && q.error) info = '<span class="drop-bad">' + svg('alert') + ' ' + esc(q.error) + '</span>';
      else if (!d) info = '<span class="muted">Checking the delivery zone…</span>';
      else info = '<span>Zone: <b>' + esc(d.basis === 'distance' ? 'By distance (' + d.zone + ')' : d.zone) + '</b> · Delivery fee <b>' + money(d.fee) + '</b></span>' +
        (d.matched ? '' : '<span class="drop-warn">' + svg('info') + ' This address is not in any of this store\'s delivery zones, so its standard rate applies. If that looks wrong, check the barangay and city.</span>') +
        (q.branchName ? '<span class="muted">Delivered by ' + esc(q.branchName) + ' (its delivery area covers this address)</span>' : '');
      return '<div class="drop-row"><div class="drop-store"><b>' + esc(o.shop.name) + '</b></div><div class="drop-info">' + info + '</div>' +
        '<label class="drop-time"><span class="small">Deliver by</span>' + sel(o, (o.dt || {})[g.id] || o.shop.times[0], 'data-dt="' + o.supplier_id + '|' + g.id + '"') + '</label></div>';
    }).join('');
  }
  const refreshDrops = () => { G.locs.forEach(g => { const el = delivEl && $('[data-drops="' + g.id + '"]', delivEl); if (el) el.innerHTML = dropsHtml(g); }); };

  function drawDeliv() {
    if (!delivEl) return;
    const on = anyDelivery();
    $('#delivWrap').hidden = !on;
    if (!on) return;
    delivEl.innerHTML = '<label class="switch mb-2"><input type="checkbox" data-multi' + (G.multi ? ' checked' : '') + '><span class="sw"></span>Deliver to more than one location</label>' +
      (G.multi ? '<p class="small muted mt-0">Add each place, then in the store sections above choose where each item goes (or split an item\'s quantity). Each location gets its own delivery zone and fee.</p>' : '') +
      '<div class="stack-sm">' + G.locs.map(locCard).join('') + '</div>' +
      (G.multi ? '<button type="button" class="btn btn-sm mt-1" data-addloc>' + svg('plus') + 'Add another location</button>' : '');
  }
  let aTimer = null;
  const addrChanged = now => { syncOrders(); clearTimeout(aTimer); aTimer = setTimeout(() => { draw(); requote(); }, now ? 0 : 450); };
  async function setCityOf(g, code) { await getTree(); const ch = chainOf(code); if (!ch) return; Object.assign(g.addr, ch); }

  if (delivEl) {
    delivEl.addEventListener('change', async e => {
      const t = e.target, card = t.closest('[data-loc]');
      if (t.matches('[data-multi]')) {
        if (t.checked) { G.multi = true; if (G.locs.length < 2) G.locs.push(newLoc(Math.max(...G.locs.map(x => +x.id.slice(1))) + 1)); }
        else {
          if (G.locs.length > 1 && !await confirmDialog({ title: 'Deliver to one location only?', message: 'Every item goes to the first address and the other locations are removed.', ok: 'Use one location' })) { t.checked = true; return; }
          G.multi = false; G.locs = [G.locs[0]];
        }
        syncOrders(); drawDeliv(); draw(); requote(); return;
      }
      if (!card) return;
      const g = G.locs.find(x => x.id === card.dataset.loc);
      if (t.dataset.af) {
        const n = t.dataset.af; g.addr[n] = t.value;
        await getTree();
        if (n === 'regionCode') { g.addr.provinceCode = ''; g.addr.cityCode = ''; }
        if (n === 'provinceCode') g.addr.cityCode = '';
        if (n === 'regionCode' || n === 'provinceCode') { const keep = document.activeElement && document.activeElement.id; drawDeliv(); const el = keep && document.getElementById(keep); if (el) el.focus(); }
        t.closest('.field') && t.closest('.field').classList.remove('invalid');
        addrChanged(true);
      }
      if (t.dataset.lf) { g[t.dataset.lf] = t.value; addrChanged(true); }
      if (t.dataset.dt) { const [sid, gid] = t.dataset.dt.split('|'); const o = orders.find(x => String(x.supplier_id) === sid); o.dt[gid] = t.value; syncOrders(); requote(); }
    });
    delivEl.addEventListener('input', e => {
      const t = e.target, card = t.closest('[data-loc]'); if (!card) return;
      const g = G.locs.find(x => x.id === card.dataset.loc);
      if (t.dataset.af && t.tagName === 'INPUT') { g.addr[t.dataset.af] = t.value; t.closest('.field').classList.remove('invalid'); addrChanged(false); }
      if (t.dataset.lf) { g[t.dataset.lf] = t.value; if (t.dataset.lf === 'label') { const h = $('.loc-head b', card); if (h) h.lastChild.textContent = ' ' + (t.value || 'Location ' + (G.locs.indexOf(g) + 1)); } addrChanged(false); }
      if (t.matches('[data-place]')) placeSearch(t, g);
    });
    delivEl.addEventListener('click', async e => {
      const b = e.target.closest('button, li[data-i]'); if (!b) return;
      const card = b.closest('[data-loc]'), g = card && G.locs.find(x => x.id === card.dataset.loc);
      if (b.matches('[data-addloc]')) { G.locs.push(newLoc(Math.max(...G.locs.map(x => +x.id.slice(1))) + 1)); syncOrders(); drawDeliv(); draw(); requote(); return; }
      if (!g) return;
      if (b.matches('[data-rmloc]')) { G.locs = G.locs.filter(x => x !== g); if (G.locs.length < 2) G.multi = false; syncOrders(); drawDeliv(); draw(); requote(); return; }
      if (b.matches('[data-saved]')) { await setCityOf(g, S.saved.cityCode); g.addr.barangay = S.saved.barangay || ''; g.addr.street = S.saved.street || ''; g.lat = null; g.lng = null; syncOrders(); drawDeliv(); draw(); requote(); return; }
      if (b.matches('[data-locate]')) {
        if (!navigator.geolocation) return toast('Your browser cannot share its location. Search your city instead.', 'warn');
        b.disabled = true;
        navigator.geolocation.getCurrentPosition(async p => {
          await getTree(); g.lat = +p.coords.latitude.toFixed(7); g.lng = +p.coords.longitude.toFixed(7);
          const c = nearestCity(g.lat, g.lng); if (c) Object.assign(g.addr, chainOf(c.code));
          syncOrders(); drawDeliv(); draw(); requote(); toast('Pinned at your location' + (c ? ' in ' + c.name : '') + '. Add the barangay and street.', 'success');
        }, err => { b.disabled = false; toast('Could not get your location (' + err.message + '). Search your city instead.', 'warn'); }, { enableHighAccuracy: true, timeout: 10000 });
        return;
      }
      if (b.matches('[data-pinloc]')) {
        pinDialog(g, async pos => {
          await getTree(); g.lat = +pos[0].toFixed(7); g.lng = +pos[1].toFixed(7);
          if (!g.addr.cityCode) { const c = nearestCity(g.lat, g.lng); if (c) Object.assign(g.addr, chainOf(c.code)); }
          syncOrders(); drawDeliv(); draw(); requote();
        });
        return;
      }
      if (b.matches('li[data-i]')) { e.preventDefault(); const c = (b.closest('.place-search')._hits || [])[+b.dataset.i]; if (c) pickPlace(g, c); }
    });
    delivEl.addEventListener('keydown', e => {
      const t = e.target; if (!t.matches('[data-place]')) return;
      const box = t.closest('.place-search'), list = $('.place-sugg', box), hits = box._hits || [];
      if (list.hidden || !hits.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); box._cur = ((box._cur == null ? -1 : box._cur) + (e.key === 'ArrowDown' ? 1 : -1) + hits.length) % hits.length; $$('li', list).forEach((li, i) => li.classList.toggle('on', i === box._cur)); }
      if (e.key === 'Enter') { e.preventDefault(); const g = G.locs.find(x => x.id === t.closest('[data-loc]').dataset.loc); pickPlace(g, hits[box._cur == null || box._cur < 0 ? 0 : box._cur]); }
      if (e.key === 'Escape') { list.hidden = true; }
    });
    delivEl.addEventListener('focusout', e => { if (e.target.matches('[data-place]')) setTimeout(() => { const l = $('.place-sugg', e.target.closest('.place-search')); if (l) l.hidden = true; }, 180); });
  }
  /** Search: type part of a city or municipality and pick it; region, province and city fill in by themselves. */
  async function placeSearch(input, g) {
    const box = input.closest('.place-search'), list = $('.place-sugg', box), v = input.value.trim().toLowerCase();
    if (v.length < 2) { list.hidden = true; return; }
    await getTree();
    const hits = T.cities.filter(c => c.key.includes(v) || c.label.toLowerCase().includes(v)).sort((a, b) => (b.key.startsWith(v) - a.key.startsWith(v)) || a.name.localeCompare(b.name)).slice(0, 8);
    box._hits = hits; box._cur = null;
    list.innerHTML = hits.length ? hits.map((c, i) => '<li role="option" data-i="' + i + '">' + esc(c.name) + '<span class="small muted">' + esc(c.label.slice(c.name.length + 2)) + '</span></li>').join('') : '<li class="muted small" aria-disabled="true">No city or municipality matches "' + esc(input.value) + '"</li>';
    list.hidden = false;
  }
  function pickPlace(g, c) {
    Object.assign(g.addr, chainOf(c.code));
    syncOrders(); drawDeliv(); draw(); requote();
    const el = document.getElementById(g.id + '-barangay'); if (el) el.focus();
  }
  async function initDeliv() {
    await getTree();
    const kept = store.get();
    if (kept && Array.isArray(kept.locs) && kept.locs.length && kept.locs[0].addr && kept.locs[0].addr.cityCode) {
      G.multi = !!kept.multi && kept.locs.length > 1; G.locs = kept.locs.map(l => Object.assign(newLoc(1), l, { addr: Object.assign(newLoc(1).addr, l.addr || {}) }));
      if (!G.multi) G.locs = [G.locs[0]];
    } else if (S.saved && S.saved.cityCode) {
      Object.assign(G.locs[0].addr, chainOf(S.saved.cityCode) || {}, { barangay: S.saved.barangay || '', street: S.saved.street || '' });
    }
    if (kept && kept.instructions) { shared.instructions = kept.instructions; const ta = $('#ev-in'); if (ta) ta.value = kept.instructions; }
    syncOrders(); drawDeliv(); draw(); requote();
  }

  /** Lines of the same cart item, time and location are combined again (never across locations). */
  function mergeSame(o) {
    const out = [];
    o.lines.forEach(l => { const m = out.find(x => x.cart_id === l.cart_id && x.destId === l.destId && x.time.start === l.time.start && x.time.end === l.time.end); if (m) Object.entries(l.days).forEach(([d, q]) => { m.days[d] = (Number(m.days[d]) || 0) + Number(q); }); else out.push(l); });
    o.lines = out;
  }

  const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const fmtDay = iso => { const d = new Date(iso + 'T00:00:00'); return DOW[d.getDay()] + ', ' + MON[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); };
  // the days something is actually rented (the main event day is one of them), else every day of the period
  const rentalDays = o => {
    const used = [...new Set(o.lines.flatMap(l => Object.entries(l.days || {}).filter(([, q]) => Number(q) > 0).map(([d]) => d)))].sort();
    if (used.length) return used;
    const out = []; let d = o.first; while (d <= o.last && out.length < 400) { out.push(d); d = addDays(d, 1); } return out;
  };
  const multiLoc = o => o.fulfillment === 'delivery' && G.multi && G.locs.length > 1;

  /** Event date: one rental day = that day (fixed); several days = pick the main event day among them. */
  function eventField(o) {
    if (o.first === o.last) return '<div class="field"><label>Event date</label><div class="input" style="display:flex;align-items:center;gap:8px;background:var(--surface-2)">' + svg('calendar') + esc(fmtDay(o.first)) + '</div><div class="hint">Your rental date. All payments to this shop are due on or before it.</div></div>';
    return '<div class="field"><label>Main event day</label><select class="input" data-k="eventDate">' + rentalDays(o).map(d => '<option value="' + d + '"' + (d === o.eventDate ? ' selected' : '') + '>' + esc(fmtDay(d)) + '</option>').join('') + '</select><div class="hint">Your rental runs ' + esc(o.range) + '. Pick the day of the event itself; all payments are due on or before it.</div></div>';
  }

  /* ---------- items: quantity (checked against stock) + where each part goes ----------
     A "group" is one selected item (cart line). Its total per date is what the customer rents; with several
     delivery locations the group has one part per location. The FIRST part always takes "the rest", so the
     parts can never add up to more or less than the total. */
  const qtyErr = {}, qtyTimer = {}, qtyBusy = {};
  const shortDay = iso => { const d = new Date(iso + 'T00:00:00'); return MON[d.getMonth()] + ' ' + d.getDate(); };
  function groupsOf(o) {
    const gs = [];
    o.lines.forEach((l, i) => { let g = gs.find(x => x.cart_id === l.cart_id); if (!g) { g = { cart_id: l.cart_id, name: l.name, parts: [], idx: [] }; gs.push(g); } g.parts.push(l); g.idx.push(i); });
    gs.forEach(g => {
      g.total = {}; g.parts.forEach(l => Object.entries(l.days).forEach(([d, q]) => { g.total[d] = (g.total[d] || 0) + Number(q); }));
      g.dates = Object.keys(g.total).sort();
      g.uniform = new Set(g.dates.map(d => g.total[d])).size <= 1;
    });
    return gs;
  }
  const stepper = (v, attrs, label) => '<div class="qty-step"><button type="button" data-step="-1" aria-label="One less">−</button><input type="number" min="1" step="1" inputmode="numeric" value="' + v + '" ' + attrs + ' aria-label="' + esc(label) + '"><button type="button" data-step="1" aria-label="One more">+</button></div>';

  function qtyBlock(o, g) {
    const av = ((lastQuote[o.supplier_id] || {}).avail || {})[g.cart_id] || {};
    const perDay = g.dates.length > 1 && (!g.uniform || (o.perDay || {})[g.cart_id]);
    const tag = d => { const a = av[d]; if (!a) return ''; return '<span class="qa ' + (a.ok ? 'ok' : 'bad') + '">' + (a.ok ? a.available + ' available' : 'only ' + a.available + ' available') + '</span>'; };
    let h = '<div class="qty-row">';
    if (!perDay) {
      const bad = g.dates.filter(d => av[d] && !av[d].ok), min = g.dates.reduce((m, d) => Math.min(m, av[d] ? av[d].available : Infinity), Infinity);
      h += '<label class="qty-field"><span>' + (g.dates.length > 1 ? 'Quantity (each of the ' + g.dates.length + ' days)' : 'Quantity') + '</span>' + stepper(g.total[g.dates[0]], 'data-qall', 'Quantity of ' + g.name) + '</label>';
      h += '<div class="qty-info">' + (bad.length ? '<span class="qa bad">only ' + av[bad[0]].available + ' available on ' + esc(shortDay(bad[0])) + '</span>' : (isFinite(min) ? '<span class="qa ok">up to ' + min + ' available</span>' : '')) +
        (g.dates.length > 1 ? '<button type="button" class="link-btn" data-perday>Different quantity per day</button>' : '') + '</div>';
    } else {
      h += '<div class="qty-days">' + g.dates.map(d => '<label class="qty-field"><span>' + esc(shortDay(d)) + '</span>' + stepper(g.total[d], 'data-qday="' + d + '"', 'Quantity of ' + g.name + ' on ' + shortDay(d)) + tag(d) + '</label>').join('') + '</div>';
      if (g.uniform) h += '<button type="button" class="link-btn" data-sameall>Same quantity every day</button>';
    }
    h += '</div>';
    const e = qtyErr[g.cart_id];
    if (e) h += '<div class="qty-err" role="alert">' + svg('alert') + '<span>' + esc(e.msg) + '</span>' + (e.fix ? '<button type="button" class="btn btn-xs" data-usefix>Use ' + esc(e.fixLabel) + '</button>' : '') + '</div>';
    return h;
  }

  function allocBlock(o, g) {
    const multi = multiLoc(o);
    const free = o.destinations.filter(d => !g.parts.some(l => l.destId === d.id));
    const rows = g.parts.map((l, k) => {
      const i = g.idx[k];
      const dest = multi ? '<select class="input input-sm" data-pdest aria-label="Location">' + o.destinations.map((d, n) => '<option value="' + d.id + '"' + (d.id === l.destId ? ' selected' : '') + '>' + (n + 1) + '. ' + esc(d.label) + '</option>').join('') + '</select>' : '';
      let q = '';
      if (multi && g.parts.length > 1) {
        q = g.uniform
          ? (k === 0 ? '<div class="alloc-rest"><b>' + (Number(l.days[g.dates[0]]) || 0) + '</b><small>the rest</small></div>' : '<label class="alloc-day"><small>how many</small><input class="input input-sm alloc-in" type="number" min="0" step="1" inputmode="numeric" data-pq value="' + (Number(l.days[g.dates[0]]) || 0) + '" aria-label="How many go to this location"></label>')
          : '<div class="alloc-days">' + g.dates.map(d => k === 0 ? '<div class="alloc-rest"><small>' + esc(shortDay(d)) + '</small><b>' + (Number(l.days[d]) || 0) + '</b></div>' : '<label class="alloc-day"><small>' + esc(shortDay(d)) + '</small><input class="input input-sm" type="number" min="0" step="1" data-pq="' + d + '" value="' + (Number(l.days[d]) || 0) + '"></label>').join('') + '</div>';
      }
      return '<div class="alloc-row" data-li="' + i + '">' + dest + q + '<div class="time-pair">' + sel(o, l.time.start, 'data-lt="start" aria-label="Start time"') + sel(o, l.time.end, 'data-lt="end" aria-label="End time"') + '</div>' +
        (multi && k > 0 ? '<button type="button" class="icon-btn" data-prm title="Send these back to the first location" aria-label="Remove this location">' + svg('x') + '</button>' : '') + '</div>';
    }).join('');
    const head = multi ? '<div class="alloc-head small"><span>' + (g.parts.length > 1 ? 'Where they go' : 'Location') + '</span><span>Rental time</span></div>' : '<div class="alloc-head small"><span>Rental time</span></div>';
    const foot = multi ? '<div class="row between wrap mt-1" style="gap:6px">' + (g.parts.length > 1 ? '<span class="small qa ok">' + svg('check') + ' All ' + esc(qtyTxt({ days: g.total })) + ' assigned</span>' : '<span></span>') +
      (free.length && Math.max(...g.dates.map(d => g.total[d])) > 1 ? '<button type="button" class="btn btn-xs" data-padd>' + svg('plus') + 'Send some to another location</button>' : '') + '</div>' : '';
    return '<div class="alloc">' + head + rows + foot + '</div>';
  }

  /** Items & rental times, one card per item: quantity (stock-checked) and, with several locations, how many go where. */
  function itemsBlock(o) {
    return groupsOf(o).map(g => '<div class="item-card" data-item="' + g.cart_id + '"><div class="row between wrap" style="gap:6px"><b>' + esc(g.name) + '</b><span class="small muted">' + esc(qtyTxt({ days: g.total })) + '</span></div>' + qtyBlock(o, g) + allocBlock(o, g) + '</div>').join('');
  }

  /** New total per date for an item: checked against the stock on the server (and saved), then spread over the parts. */
  async function setTotal(o, cartId, want) {
    const g = groupsOf(o).find(x => x.cart_id === cartId); if (!g) { delete qtyBusy[cartId]; return; }
    for (const d of g.dates) {
      if (!Number.isInteger(want[d]) || want[d] < 1) { delete qtyBusy[cartId]; qtyErr[cartId] = { msg: 'Enter a whole number of at least 1. To drop this item, remove it from your Rental List.' }; draw(); return; }
    }
    if (g.dates.every(d => want[d] === g.total[d])) { delete qtyBusy[cartId]; if (qtyErr[cartId]) { delete qtyErr[cartId]; draw(); } return; }
    qtyBusy[cartId] = true;
    let data = {}, res = null;
    try {
      res = await fetch(form.dataset.qty, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ cart_id: cartId, days: want }) });
      data = await res.json().catch(() => ({}));
    } catch (e) { data = { error: 'Could not check the stock. Check your connection and try again.' }; }
    delete qtyBusy[cartId];
    if (!res || !res.ok || !data.ok) {
      const avail = data.available || {};
      const fixDays = {}; let fix = false;
      g.dates.forEach(d => { const a = avail[d]; fixDays[d] = a !== undefined && a < want[d] ? a : want[d]; if (fixDays[d] !== want[d]) fix = true; });
      const usable = fix && g.dates.every(d => fixDays[d] >= 1);
      const vals = [...new Set(g.dates.map(d => fixDays[d]))];
      const firstErr = data.errors ? Object.values(data.errors)[0][0] : null;
      qtyErr[cartId] = { msg: data.error || firstErr || data.message || 'Could not change the quantity.', fix: usable ? fixDays : null, fixLabel: vals.length === 1 ? String(vals[0]) : 'what is available' };
      draw(); return;
    }
    delete qtyErr[cartId];
    g.dates.forEach(d => {
      const parts = g.parts; parts[0].days[d] = (Number(parts[0].days[d]) || 0) + (want[d] - g.total[d]);
      for (let k = parts.length - 1; parts[0].days[d] < 0 && k > 0; k--) { const take = Math.min(Number(parts[k].days[d]) || 0, -parts[0].days[d]); parts[k].days[d] = (Number(parts[k].days[d]) || 0) - take; parts[0].days[d] += take; }
    });
    draw(); requote();
  }
  function queueTotal(o, cartId, want) { clearTimeout(qtyTimer[cartId]); qtyBusy[cartId] = true; qtyTimer[cartId] = setTimeout(() => setTotal(o, cartId, want), 450); }

  /** How many of an item go to an extra location (part k > 0); the first location keeps the rest. */
  function setPart(o, g, k, date, v) {
    const dates = date ? [date] : g.dates; let capped = null;
    dates.forEach(d => {
      const others = g.parts.reduce((s, l, j) => s + (j === 0 || j === k ? 0 : Number(l.days[d]) || 0), 0);
      const max = g.total[d] - others, vv = Math.max(0, Math.min(Number.isFinite(v) ? v : 0, max));
      if (v > max) capped = max;
      g.parts[k].days[d] = vv; g.parts[0].days[d] = max - vv;
    });
    if (capped !== null) toast('Only ' + capped + ' left to send there (the total is ' + qtyTxt({ days: g.total }) + '). Change the quantity above to rent more.', 'warn');
    draw(); requote();
  }

  /** The shop's branch that fulfils this order: the recommended one, or the customer's pick among eligible branches. */
  const branchOf = o => { const q = lastQuote[o.supplier_id] || {}; return (q.branches || []).find(b => b.id === (o.branch_id || q.branchId)) || null; };
  /**
   * The branch line at checkout: "Rented from Pasig · 8 km away", picked for the customer (has everything, serves their
   * area, cheapest / nearest). "Change branch" lists only the other branches that can do the whole order; branches
   * outside the customer's area are never offered. A combined order says which branch brings some items in.
   */
  function branchBlock(o) {
    const q = lastQuote[o.supplier_id] || {}, list = q.branches || [], chosen = o.branch_id || q.branchId;
    const cur = list.find(b => b.id === chosen);
    if (!cur) return '';
    const del = o.fulfillment === 'delivery', pinned = o.destinations.some(d => d.lat != null && d.lat !== '');
    const lenders = q.lenders || [];
    const facts = b => [b.km != null ? b.km + ' km away' : null, del ? 'Delivery ' + money(b.fee) : 'Pick up here' + (b.hours ? ' · ' + b.hours : '')].filter(Boolean).join(' · ');
    const opt = b => {
      const on = b.id === chosen;
      return '<label class="branch-opt' + (on ? ' on' : '') + '"><input type="radio" name="br_' + o.supplier_id + '" value="' + b.id + '" data-branch' + (on ? ' checked' : '') + '>' +
        '<div class="grow" style="min-width:0"><div class="branch-top"><b>' + esc(b.name) + '</b>' + (b.recommended ? '<span class="badge b-green plain">Best for you</span>' : '') + (b.main ? '<span class="badge b-gray plain">Main</span>' : '') + '</div>' +
        '<div class="small muted">' + esc(b.address + (b.city && !String(b.address).toLowerCase().includes(String(b.city).split(',')[0].replace(/ city$/i, '').toLowerCase()) ? ', ' + b.city : '')) + '</div><div class="small">' + esc(facts(b)) + '</div></div></label>';
    };
    let html = del
      ? '<div class="branch-one small mt-2">' + svg('truck') + '<span>Delivered by <b>' + esc(cur.name) + '</b>' + (cur.km != null ? ' · ' + cur.km + ' km away' : '') + ' · assigned from your address (its delivery area covers it)</span></div>'
      : '<div class="branch-one small mt-2">' + svg('store') + '<span>Pick up at <b>' + esc(cur.name) + '</b>' + (cur.km != null ? ' · ' + cur.km + ' km away' : '') + (cur.address ? ' · ' + esc(cur.address) : '') + ' · ' + esc(cur.hours || o.shop.hours) + '</span></div>';
    if (lenders.length) html += '<div class="small muted mt-1">Part of your order is brought in from our ' + esc(lenders.join(', ')) + ' branch. Nothing changes for you: one rental, one price, ' + (del ? 'delivered from ' : 'picked up at ') + esc(cur.name) + '.</div>';
    const others = del ? [] : list.filter(b => b.id !== cur.id && b.ok && b.deliverable); // delivery: never chosen by hand
    if (others.length) {
      html += '<details class="branch-change mt-1"' + (o.branch_id ? ' open' : '') + '><summary class="link-btn small">Pick up somewhere else</summary>' +
        '<p class="small muted mt-1 mb-1">Only branches near you that have everything for your dates are listed.</p>' +
        '<div class="branch-list">' + [cur].concat(others).map(opt).join('') + '</div>' +
        (o.branch_id ? '<button type="button" class="link-btn small mt-1" data-branchauto>Use the nearest branch</button>' : '') + '</details>';
    }
    return html;
  }

  /** One section per store: its name, its items (quantities, dates, times), its services and payment plan, and its
      subtotal. How the items reach the customer is in the single Delivery information section below the stores. */
  function shopCard(o, idx) {
    const sh = o.shop; const sid = o.supplier_id;
    const q = lastQuote[sid] || {};
    const n = groupsOf(o).length;
    return '<section class="card shop-sec" data-shop="' + sid + '" style="--shop:' + esc(sh.color) + '">' +
      '<header class="shop-sec-head"><div class="logo-tile" style="width:42px;height:42px;font-size:1.3rem;background:' + sh.color + '1a">' + esc(sh.logo) + '</div>' +
      '<div class="grow" style="min-width:0"><div class="label">Store ' + (idx + 1) + ' of ' + orders.length + '</div><h3 class="mb-0">' + esc(sh.name) + '</h3><div class="small muted">' + esc(o.range) + ' · ' + n + ' item' + (n === 1 ? '' : 's') + '</div></div>' +
      '<div class="shop-sec-sub"><span class="small muted">Store subtotal</span><b data-subtotal>' + (q.total != null ? money(q.total) : '…') + '</b></div></header>' +
      (o.first !== o.last ? '<div class="form-grid mt-1">' + eventField(o) + '</div>' : '') +
      '<div class="label mt-2 mb-1">How you get this store\'s items</div><div class="row wrap gap-sm">' + ['delivery', 'pickup'].map(f => { const ok = sh.fulfillment === 'both' || sh.fulfillment === f; return '<label class="chip ' + (o.fulfillment === f ? 'active' : '') + '" style="' + (ok ? '' : 'opacity:.45;pointer-events:none') + '"><input type="radio" name="ful_' + sid + '" value="' + f + '" data-ful hidden' + (o.fulfillment === f ? ' checked' : '') + (ok ? '' : ' disabled') + '>' + svg(f === 'delivery' ? 'truck' : 'store') + (f === 'delivery' ? 'Delivery' : 'Pickup at shop') + '</label>'; }).join('') + '</div>' +
      (o.fulfillment === 'delivery' ? '<p class="small muted mt-1 mb-0">Delivered to ' + (G.multi ? 'the locations' : 'the address') + ' in <a href="#delivCard" data-goaddr>Delivery information</a> below; the zone, fee and branch are worked out from it.</p>' : branchBlock(o)) +
      '<div class="label mt-2 mb-1">Items &amp; rental times</div><div class="stack-sm">' + itemsBlock(o) + '</div>' +
      (sh.services.length ? '<div class="label mt-2 mb-1">Services</div>' + sh.services.map(x => '<label class="check"><input type="checkbox" data-svc="' + esc(x.id) + '"' + (x.required || o.services.includes(x.id) ? ' checked' : '') + (x.required ? ' disabled' : '') + '> <b>' + esc(x.name) + '</b> — ' + money(x.amount) + (x.per === 'location' ? ' per visit' : '') + (x.required ? ' <span class="badge b-gray plain">included</span>' : '') + '<div class="small muted">' + esc(x.description || '') + '</div></label>').join('') + '<p class="small muted mb-0">Per-visit services are charged once for consecutive days at the same location, and again for each location change or separate date block.</p>' : '') +
      '<div class="label mt-2 mb-1">Payment plan</div><div class="stack-sm">' + sh.plans.map(p => { const dis = p.key === 'installment' && q.installmentOk === false; return '<label class="card card-flat row" style="padding:12px 14px;cursor:pointer;' + (dis ? 'opacity:.55' : '') + '"><input type="radio" name="plan_' + sid + '" value="' + p.key + '" data-plan' + (p.key === o.plan ? ' checked' : '') + (dis ? ' disabled' : '') + ' style="accent-color:var(--brand);width:18px;height:18px"><div class="grow"><b>' + esc(p.label) + '</b><div class="small muted">' + esc(p.summary) + '</div>' + (dis ? '<div class="small" style="color:var(--red)">' + esc(q.installmentMsg) + '</div>' : '') + '</div></label>'; }).join('') + '</div>' +
      (o.plan === 'installment' ? '<div class="card card-flat soft mt-1" style="padding:14px" data-inst><div class="row between wrap"><b>Propose your installment schedule</b><label class="small row" style="gap:6px">Installments <select class="input input-sm" data-instn style="width:auto">' + Array.from({ length: sh.maxInstallments }, (_, i) => '<option' + (i + 1 === o.inst.n ? ' selected' : '') + '>' + (i + 1) + '</option>').join('') + '</select></label></div><p class="small muted">Divide the installment balance into payments of your choice. Every payment must be due on or before the event date. The supplier reviews and approves the schedule.</p><div data-instrows>' + o.inst.items.map((it, i) => '<div class="row mb-1" style="gap:8px" data-ir="' + i + '"><span class="small muted" style="width:26px">#' + (i + 1) + '</span><input class="input input-sm" type="date" data-f="due" value="' + it.due + '" min="' + addDays(today, 1) + '" max="' + o.eventDate + '" aria-label="Due date ' + (i + 1) + '" style="width:auto"><input class="input input-sm" type="number" step="0.01" min="1" data-f="amount" value="' + it.amount + '" aria-label="Amount ' + (i + 1) + '" style="width:130px"></div>').join('') + '</div><div class="row between mt-1"><button type="button" class="btn btn-xs" data-even>Divide evenly</button><span class="small" data-instsum></span></div></div>' : '') +
      '<div data-shoperr>' + shopErr(q) + '</div>' +
      '<details class="mt-2 shop-charges"><summary class="link-btn small">See this store\'s charges</summary><div data-review>' + (q.html || '') + '</div></details></section>';
  }
  const shopErr = q => q.ok === false && q.error ? '<div class="alert alert-danger mt-2">' + svg('alert') + '<div class="small">' + esc(q.error) + '</div></div>' : '';

  /** Map popup to pin a delivery address (Leaflet; window.PR.map has the centre / bounds / tiles). */
  function pinDialog(d, done) {
    if (!window.L || !window.PR || !window.PR.map) return toast('The map could not load. You can still type the full address.', 'warn');
    const cfg = window.PR.map;
    const bd = document.createElement('div'); bd.className = 'modal-backdrop';
    bd.innerHTML = '<div class="modal lg" role="dialog" aria-modal="true"><div class="modal-head"><h3>Pin the delivery spot</h3><button class="icon-btn" data-x aria-label="Close">' + svg('x') + '</button></div><div class="modal-body"><p class="small muted mb-1">Tap the map at the exact gate or venue entrance, or use your current location. Drag the pin to adjust.</p><div class="pr-map tall" data-pinmap></div><div class="row between wrap mt-1" style="gap:8px"><button type="button" class="btn btn-sm" data-locate>' + svg('map') + 'Use my current location</button><span class="small muted" data-pinst>No pin yet.</span></div></div><div class="modal-foot"><button class="btn" data-x>Cancel</button><button class="btn btn-primary" data-ok>Save pin</button></div></div>';
    document.body.appendChild(bd); document.body.style.overflow = 'hidden';
    const el = $('[data-pinmap]', bd), st = $('[data-pinst]', bd);
    const map = window.L.map(el, { maxBounds: cfg.bounds, maxBoundsViscosity: 0.8, minZoom: 5 }).setView(cfg.center, cfg.zoom);
    window.L.tileLayer(cfg.tiles, { attribution: cfg.attribution, maxZoom: 19 }).addTo(map);
    setTimeout(() => map.invalidateSize(), 60);
    let marker = null, pos = null;
    const set = (lat, lng, zoom) => {
      pos = [+lat, +lng];
      if (!marker) { marker = window.L.marker(pos, { draggable: true }).addTo(map); marker.on('dragend', () => { const p = marker.getLatLng(); set(p.lat, p.lng, false); }); } else marker.setLatLng(pos);
      if (zoom) map.setView(pos, Math.max(map.getZoom(), 16));
      st.textContent = 'Pinned at ' + pos[0].toFixed(5) + ', ' + pos[1].toFixed(5);
    };
    if (d.lat != null && d.lat !== '') set(d.lat, d.lng, true);
    map.on('click', e => set(e.latlng.lat, e.latlng.lng, false));
    $('[data-locate]', bd).onclick = () => {
      if (!navigator.geolocation) return toast('Your browser cannot share its location. Tap the map instead.', 'warn');
      navigator.geolocation.getCurrentPosition(p => set(p.coords.latitude, p.coords.longitude, true), err => toast('Could not get your location (' + err.message + '). Tap the map instead.', 'warn'), { enableHighAccuracy: true, timeout: 10000 });
    };
    const close = () => { map.remove(); bd.remove(); if (!$$('.modal-backdrop:not(.hidden)').length) document.body.style.overflow = ''; };
    bd.addEventListener('click', e => {
      if (e.target === bd || e.target.closest('[data-x]')) close();
      if (e.target.closest('[data-ok]')) { if (!pos) return toast('Tap the map to place the pin first.', 'warn'); done(pos); close(); }
    });
  }

  /** Turning on "more than one location": explain what it does first. */
  let drawing = false, drawAgain = false;
  function draw() {
    // replacing the cards blurs a focused box, whose change handler may ask for another draw: do that one afterwards
    if (drawing) { drawAgain = true; return; }
    drawing = true;
    try { render(); } finally { drawing = false; }
    if (drawAgain) { drawAgain = false; draw(); }
  }
  function render() {
    // keep the cursor in a quantity box when the cards are redrawn
    const a = document.activeElement, card = a && a.closest && a.closest('[data-item]');
    const key = card && (a.matches('[data-qall]') ? '[data-qall]' : a.matches('[data-qday]') ? '[data-qday="' + a.dataset.qday + '"]' : null);
    $('#shops').innerHTML = orders.map(shopCard).join('');
    if (delivEl && $('#delivWrap').hidden === anyDelivery()) drawDeliv(); // a store switched delivery on / off
    refreshDrops();
    bind();
    if (key) { const el = $('[data-item="' + card.dataset.item + '"] ' + key); if (el) { el.focus(); const n = el.value.length; try { el.type = 'text'; el.setSelectionRange(n, n); el.type = 'number'; } catch (e) { /* number inputs can't place the caret everywhere */ } } }
  }

  function payload() {
    return { shared, orders: orders.map(o => ({ supplier_id: o.supplier_id, cart_line_ids: o.cart_line_ids, eventDate: o.eventDate, fulfillment: o.fulfillment, destinations: o.fulfillment === 'delivery' ? o.destinations.filter(d => o.lines.some(l => l.destId === d.id)) : [], branch_id: o.branch_id || null, plan: o.plan, method: o.method, services: o.services, lines: o.lines.map(l => ({ cart_id: l.cart_id, type: l.type, days: l.days, time: l.time, destId: o.fulfillment === 'delivery' ? l.destId : null })), inst: o.inst })) };
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
          const prev = lastQuote[r.supplier_id];
          if (!prev || JSON.stringify(prev.branches) !== JSON.stringify(r.branches) || prev.branchId !== r.branchId) needDraw = true; // branch list / choice changed
          lastQuote[r.supplier_id] = r;
          const o = orders.find(x => x.supplier_id === r.supplier_id); if (!o) return;
          if (o.plan === 'installment' && o.inst.auto && JSON.stringify(o.inst.items) !== JSON.stringify(r.suggest)) { o.inst.items = r.suggest; needDraw = true; }
          if (o.plan === 'installment' && r.installmentOk === false) { o.plan = o.shop.plans.find(p => p.key !== 'installment').key; needDraw = true; }
        });
        $('#sum').innerHTML = data.summaryHtml;
        refreshDrops();
        if (needDraw) draw();
        else data.orders.forEach(r => {
          const sec = $('[data-shop="' + r.supplier_id + '"]'); if (!sec) return;
          const rv = $('[data-review]', sec); if (rv) rv.innerHTML = r.html;
          const st = $('[data-subtotal]', sec); if (st) st.textContent = money(r.total);
          const er = $('[data-shoperr]', sec); if (er) er.innerHTML = shopErr(r);
          instSum(r.supplier_id);
          const row = $('[data-fulshop="' + r.supplier_id + '"]'); const prevOk = row && row.dataset.ok; if (row && String(r.ok) !== prevOk) { row.dataset.ok = String(r.ok); draw(); }
        });
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
      const boxes = [$('[data-shop="' + o.supplier_id + '"]'), $('[data-fulshop="' + o.supplier_id + '"]')].filter(Boolean); if (!boxes.length) return;
      const pick = q => boxes.flatMap(b => $$(q, b));
      pick('[data-k]').forEach(el => el.onchange = () => { o[el.dataset.k] = el.value; o.inst.auto = true; requote(true); });
      // Delivery ↔ Pickup keeps every line (and its per-location split) as it is.
      pick('[data-ful]').forEach(el => el.onchange = () => { o.fulfillment = el.value; syncOrders(); drawDeliv(); draw(); requote(); });
      pick('[data-goaddr]').forEach(b => b.onclick = e => { e.preventDefault(); $('#delivCard').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
      // the delivery locations themselves (address, pin, add / remove) live in the Delivery information section
      pick('[data-item]').forEach(card => {
        const cid = +card.dataset.item, grp = () => groupsOf(o).find(x => x.cart_id === cid);
        $$('[data-step]', card).forEach(b => b.onclick = () => { const inp = b.parentNode.querySelector('input'); inp.value = Math.max(1, (parseInt(inp.value, 10) || 0) + +b.dataset.step); inp.dispatchEvent(new Event('input', { bubbles: true })); });
        const qa = $('[data-qall]', card);
        if (qa) qa.oninput = () => { const v = parseInt(qa.value, 10), want = {}; grp().dates.forEach(d => { want[d] = v; }); queueTotal(o, cid, want); };
        $$('[data-qday]', card).forEach(inp => inp.oninput = () => { const want = Object.assign({}, grp().total); want[inp.dataset.qday] = parseInt(inp.value, 10); queueTotal(o, cid, want); });
        const pd = $('[data-perday]', card); if (pd) pd.onclick = () => { o.perDay = Object.assign(o.perDay || {}, { [cid]: true }); draw(); };
        const sa = $('[data-sameall]', card); if (sa) sa.onclick = () => { delete (o.perDay || {})[cid]; draw(); };
        const fx = $('[data-usefix]', card); if (fx) fx.onclick = () => setTotal(o, cid, Object.assign({}, qtyErr[cid].fix));
        const ad = $('[data-padd]', card); if (ad) ad.onclick = () => {
          const g = grp(), d = o.destinations.find(x => !g.parts.some(l => l.destId === x.id)); if (!d) return;
          const zero = {}; g.dates.forEach(x => { zero[x] = 0; });
          o.lines.splice(g.idx[g.idx.length - 1] + 1, 0, { cart_id: cid, name: g.name, type: g.parts[0].type, group: g.parts[0].group, days: zero, time: Object.assign({}, g.parts[0].time), destId: d.id });
          draw();
        };
      });
      pick('[data-li]').forEach(row => {
        const i = +row.dataset.li, l = o.lines[i], g = groupsOf(o).find(x => x.cart_id === l.cart_id), k = g.idx.indexOf(i);
        $$('[data-lt]', row).forEach(s => s.onchange = () => { l.time[s.dataset.lt] = s.value; requote(); });
        const ds = $('[data-pdest]', row); if (ds) ds.onchange = () => { l.destId = ds.value; mergeSame(o); draw(); requote(); };
        $$('[data-pq]', row).forEach(inp => inp.onchange = () => setPart(o, g, k, inp.dataset.pq || null, parseInt(inp.value, 10)));
        const rm = $('[data-prm]', row); if (rm) rm.onclick = () => { g.dates.forEach(d => { g.parts[0].days[d] = (Number(g.parts[0].days[d]) || 0) + (Number(l.days[d]) || 0); }); o.lines.splice(i, 1); draw(); requote(); };
      });
      pick('[data-branch]').forEach(r => r.onchange = () => { o.branch_id = +r.value; requote(true); });
      const ba = pick('[data-branchauto]')[0]; if (ba) ba.onclick = () => { o.branch_id = null; requote(true); };
      pick('[data-svc]').forEach(c => c.onchange = () => { o.services = pick('[data-svc]:checked:not([disabled])').map(x => x.dataset.svc); o.inst.auto = true; requote(); });
      pick('[data-plan]').forEach(r => r.onchange = () => { o.plan = r.value; o.inst.auto = true; o.inst.items = []; requote(true); });
      const n = pick('[data-instn]')[0]; if (n) n.onchange = () => { o.inst.n = +n.value; o.inst.auto = true; requote(true); };
      const ev = pick('[data-even]')[0]; if (ev) ev.onclick = () => { o.inst.auto = true; requote(true); };
      pick('[data-ir] [data-f]').forEach(inp => inp.onchange = () => { const it = o.inst.items[+inp.closest('[data-ir]').dataset.ir]; it[inp.dataset.f] = inp.dataset.f === 'amount' ? Math.round((+inp.value || 0) * 100) / 100 : inp.value; o.inst.auto = false; instSum(o.supplier_id); requote(); });
      instSum(o.supplier_id);
    });
  }

  $$('[data-s]', form).forEach(el => el.oninput = el.onchange = () => { shared[el.dataset.s] = el.value; el.closest('.field').classList.remove('invalid'); if (el.dataset.s === 'instructions') saveState(); requote(); });
  $('#submitBtn').onclick = async () => {
    let bad = null;
    $$('[data-s][required]', form).forEach(el => { const ok = el.value.trim().length >= (+el.getAttribute('minlength') || 1); el.closest('.field').classList.toggle('invalid', !ok); if (!ok && !bad) bad = el; });
    if (!bad && anyDelivery()) {
      const need = [['cityCode', 'City / municipality', 1], ['barangay', 'Barangay', 1], ['street', 'House no., building and street', 3]];
      for (const g of G.locs) {
        if (G.multi && !orders.some(o => o.fulfillment === 'delivery' && o.lines.some(l => l.destId === g.id))) continue;
        for (const [n, label, min] of need) {
          const ok = (g.addr[n] || '').trim().length >= min, el = document.getElementById(g.id + '-' + n);
          if (el) el.closest('.field').classList.toggle('invalid', !ok);
          if (!ok && !bad) { bad = el; if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.focus({ preventScroll: true }); } toast('Complete the ' + (G.multi ? '"' + locName(g, G.locs.indexOf(g)) + '" ' : 'delivery ') + 'address: ' + label + ' is missing. We use it to find the delivery zone, fee and branch.', 'error'); }
        }
      }
      if (bad) return;
    }
    if (bad) {
      // say which field, and take the customer there
      const name = ((bad.closest('.field').querySelector('label') || {}).textContent || 'this field').replace(/\s*\(optional\)\s*/i, '').trim();
      bad.scrollIntoView({ behavior: 'smooth', block: 'center' }); bad.focus({ preventScroll: true });
      return toast('Please fill in "' + name + '" so the shop can reach you about this rental.', 'error');
    }
    if (Object.keys(qtyBusy).length) return toast('Checking the new quantity… try again in a moment.', 'info');
    const qe = Object.keys(qtyErr)[0]; if (qe) { const c = $('[data-item="' + qe + '"]'); if (c) c.scrollIntoView({ behavior: 'smooth', block: 'center' }); return toast('Fix the quantity first: ' + qtyErr[qe].msg, 'error'); }
    if (!$('#agree').checked) { $('#agree').scrollIntoView({ behavior: 'smooth', block: 'center' }); return toast('Please read and tick "I agree" to the shop\'s rental policies first.', 'warn'); }
    const notOk = orders.find(o => lastQuote[o.supplier_id] && !lastQuote[o.supplier_id].ok);
    if (notOk) { $('[data-shop="' + notOk.supplier_id + '"]').scrollIntoView({ behavior: 'smooth' }); return toast(notOk.shop.name + ': ' + lastQuote[notOk.supplier_id].error, 'error'); }
    const list = orders.map(o => o.shop.name + ' — ' + money((lastQuote[o.supplier_id] || {}).total)).join(' · ');
    if (!await confirmDialog({ title: 'Submit ' + (orders.length > 1 ? orders.length + ' rental requests' : 'rental request') + '?', message: 'Nothing is charged now. The shop checks your request first; once it accepts, you have 24 hours to pay the first payment, which reserves the items. ' + list, ok: 'Send request' })) return;
    const btn = $('#submitBtn'); const label = btn.textContent; btn.disabled = true; btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;border-top-color:#fff"></span> Submitting…';
    try {
      const res = await fetch(form.dataset.submit, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(Object.assign(payload(), { agree: 1 })) });
      const data = await res.json();
      if (res.ok && data.ok) { window.PR.leaveOk = true; location.href = data.redirect; return; }
      toast(data.error || data.message || 'Please check the form.', 'error'); requote();
    } catch (e) { toast('Could not submit. Check your connection.', 'error'); }
    btn.disabled = false; btn.textContent = label;
  };

  /* ---------- rental period: change the dates of every item without leaving the page ---------- */
  const pd = $('#coDates');
  if (pd) {
    const f = $('[data-pform]', pd), err = $('[data-perr]', pd), ps = $('[data-pstart]', pd), pe = $('[data-pend]', pd), mm = $('[data-pmulti]', pd);
    const multiUi = () => { const on = mm.checked; $('[data-pendwrap]', pd).hidden = !on; $('[data-plbl-one]', pd).hidden = on; $('[data-plbl-many]', pd).hidden = !on; if (!on) pe.value = ps.value; else if (pe.value < ps.value) pe.value = ps.value; pe.min = ps.value || pd.dataset.today; };
    const showErr = m => { err.textContent = m; err.classList.toggle('hidden', !m); };
    $('[data-pedit]', pd).onclick = () => { f.classList.toggle('hidden'); showErr(''); if (!f.classList.contains('hidden')) ps.focus(); };
    $('[data-pcancel]', pd).onclick = () => { f.classList.add('hidden'); showErr(''); };
    mm.onchange = multiUi; ps.onchange = multiUi;
    $('[data-papply]', pd).onclick = async () => {
      const start = ps.value, end = mm.checked ? pe.value : ps.value;
      if (!start) return showErr('Pick a date.');
      if (end < start) return showErr('The end date must be on or after the start date.');
      const btn = $('[data-papply]', pd); btn.disabled = true; showErr('');
      let data = {}, res = null;
      try {
        res = await fetch(pd.dataset.url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ cart_ids: [...new Set(orders.flatMap(o => o.lines.map(l => l.cart_id)))], start, end }) });
        data = await res.json().catch(() => ({}));
      } catch (e) { data = { error: 'Could not reach PartyRent. Check your connection and try again.' }; }
      btn.disabled = false;
      if (!res || !res.ok || !data.ok) { const m = data.error || (data.errors ? Object.values(data.errors)[0][0] : data.message) || 'Could not change the dates.'; showErr(m); return; }
      data.orders.forEach(r => {
        const o = orders.find(x => x.supplier_id === r.supplier_id); if (!o) return;
        // each item keeps its rental time and first location; a split between locations starts over
        const keep = {}; o.lines.forEach(l => { if (!keep[l.cart_id]) keep[l.cart_id] = l; });
        o.lines = r.lines.map(l => Object.assign({}, l, keep[l.cart_id] ? { time: keep[l.cart_id].time, destId: keep[l.cart_id].destId } : {}));
        Object.assign(o, { first: r.first, last: r.last, range: r.range, eventDate: r.eventDate });
        o.inst.auto = true;
      });
      Object.keys(qtyErr).forEach(k => delete qtyErr[k]);
      $('[data-plabel]', pd).textContent = data.label; $('[data-pdays]', pd).textContent = data.duration;
      f.classList.add('hidden');
      draw(); requote(true);
      toast('Dates changed to ' + data.label + '. Prices updated.', 'success');
    };
  }

  draw(); requote(true);
  if (delivEl) initDeliv();
})();
