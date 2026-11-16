/* =========================================================
   PartyRent — services.js
   Business workflows. Every state change in the system goes
   through here so that inventory, payments, notifications and
   the audit trail stay consistent across all roles.
   (API-ready: each function maps to one backend endpoint.)
   ========================================================= */
(function (g) {
  'use strict';
  const r2 = U.round2;

  const STATUS = {
    pending: ['Pending', 'b-gold'], accepted: ['Accepted', 'b-sky'], declined: ['Declined', 'b-red'], cancelled: ['Cancelled', 'b-gray'],
    in_progress: ['In Progress', 'b-purple'], ready_pickup: ['Ready for Pickup', 'b-teal'], out_delivery: ['Out for Delivery', 'b-teal'],
    ongoing: ['Ongoing Rental', 'b-pink'], return_pending: ['Return Pending', 'b-gold'], inspection: ['Under Inspection', 'b-purple'], completed: ['Completed', 'b-green']
  };
  const statusLabel = s => (STATUS[s] || [s])[0];
  const statusClass = s => (STATUS[s] || [, 'b-gray'])[1];

  function ownerOf(supplierId) { const s = Store.supplier(supplierId); return s ? s.ownerId : null; }
  function actor() { const s = Auth.session(); return s ? s.user : null; }
  function pushHistory(r, status, note) { const a = actor(); r.history = r.history || []; r.history.push({ status, at: new Date().toISOString(), by: a ? a.id : 'system', note: note || '' }); }
  /** Is the signed-in user allowed to do `perm` for this supplier (owner or permitted employee)? */
  function orgAllowed(supplierId, perm) { const a = actor(); return !!(a && a.supplierId === supplierId && Auth.orgCan(perm, a)); }
  /** Payment notifications go to the supplier's own cashiers (never to other suppliers). */
  function notifyFinance(supplierId, title, body, link) {
    Store.where('users', u => u.supplierId === supplierId && u.orgRole === 'cashier' && u.status === 'active').forEach(u => Store.notify(u.id, 'cashier', title, body, link || 'cashier/verification.html'));
  }
  function itemName(line) { if (line.type === 'package') { const k = Store.pkg(line.refId); return k ? k.name : 'Package'; } const p = Store.product(line.refId); return p ? p.name : 'Item'; }

  /* ================= CART (Rental List) ================= */
  function cart(uid) { const d = Store.load(); d.carts = d.carts || {}; return d.carts[uid] = d.carts[uid] || []; }
  function cartCount(uid) { return cart(uid).length; }
  /* ================= MAXIMUM RENTAL DURATION (per product, per booking) =================
     A product may set maxRentalDays (null/undefined = no limit). Within ONE booking, the
     period from the first to the last date on which the product is rented (as an item or
     inside a package) may not exceed that many days. Separate bookings are checked on
     their own, so a customer can still book other dates in another request. This is the
     service-layer (API) rule: every booking path — cart, checkout, request submission and
     acceptance — calls it, so it cannot be bypassed from the UI. */
  function maxDaysFor(type, refId) {
    if (type === 'package') {
      const k = Store.pkg(refId); if (!k) return null;
      const caps = k.items.map(it => (Store.product(it.productId) || {}).maxRentalDays).filter(n => n > 0);
      return caps.length ? Math.min.apply(null, caps) : null;
    }
    const p = Store.product(refId); return p && p.maxRentalDays > 0 ? p.maxRentalDays : null;
  }
  function durationIssues(lines) {
    const byProduct = {};
    (lines || []).forEach(l => Object.keys(l.days || {}).forEach(d => {
      if (!(Number(l.days[d]) > 0)) return;
      Object.keys(Inventory.unitsForLine(l, d)).forEach(pid => { (byProduct[pid] = byProduct[pid] || new Set()).add(d); });
    }));
    const out = [];
    Object.entries(byProduct).forEach(([pid, set]) => {
      const p = Store.product(pid); if (!p || !(p.maxRentalDays > 0)) return;
      const dates = Array.from(set).sort(); const first = dates[0], last = dates[dates.length - 1];
      const span = U.diffDays(first, last) + 1;
      if (span > p.maxRentalDays) out.push({ productId: pid, name: p.name, max: p.maxRentalDays, span, first, last, message: durationMessage(p.name, p.maxRentalDays, first, last) });
    });
    return out;
  }
  function durationMessage(name, max, first, last) {
    const span = U.diffDays(first, last) + 1;
    return name + ' can be rented for a maximum of ' + max + ' day' + (max > 1 ? 's' : '') + ' per booking. Your selected period (' + U.fmtRange(first, last) + ') is ' + span + ' days. Please shorten the rental period, or make a separate booking for the other dates.';
  }

  const timeKey = t => (t && t.start && t.end ? t.start + '-' + t.end : 'all');
  function addToCart(uid, line) {
    const c = cart(uid);
    const item = line.type === 'package' ? Store.pkg(line.refId) : Store.product(line.refId);
    if (!item || !isListed(line.type === 'package' ? 'package' : 'product', item)) return { ok: false, error: 'This listing is not available.' };
    if (item.supplierId !== line.supplierId) return { ok: false, error: 'Supplier mismatch.' };
    const time = line.time && line.time.start && line.time.end ? { start: line.time.start, end: line.time.end } : null;
    // the same item with the same rental time is merged; a different time slot is a separate line
    const existing = c.find(l => l.type === line.type && l.refId === line.refId && timeKey(l.time) === timeKey(time));
    const merged = existing ? Object.assign({}, existing.days) : {};
    Object.entries(line.days).forEach(([d, q]) => { merged[d] = (Number(merged[d]) || 0) + Number(q); });
    const cand = { id: '__new', type: line.type, refId: line.refId, days: merged, time };
    const tIssues = Slots.validateLine(cand);
    if (tIssues.length) return { ok: false, error: tIssues[0], timeIssues: tIssues };
    // all lines of this supplier form one booking, so validate them together
    const others = c.filter(l => l.supplierId === line.supplierId && l !== existing);
    const issues = durationIssues(others.concat([cand]));
    if (issues.length) {
      const inCart = existing || others.length;
      return { ok: false, error: issues[0].message + (inCart ? ' (This includes dates already in your Rental List for this supplier — submit that request first, then book the other dates separately.)' : ''), issues };
    }
    // date- and time-based stock recheck (same rule as checkout and acceptance), combined with the rest of this supplier's list
    const stock = Inventory.check(others.concat([cand]));
    if (!stock.ok) return { ok: false, error: 'Not enough stock: ' + stock.bad.map(x => (Store.product(x.productId) || {}).name + ' on ' + U.fmtDate(x.date) + ' (requested ' + x.requested + ', available ' + x.available + ' for the selected time)').join('; ') + '.', check: stock };
    if (existing) { existing.days = merged; existing.selected = true; }
    else c.push({ id: U.uid('c'), supplierId: line.supplierId, type: line.type, refId: line.refId, days: Object.assign({}, line.days), time, selected: true });
    Store.save();
    return { ok: true };
  }
  /** Customer ticks / unticks products in the Rental List (unselected items stay in the list). */
  function setCartSelected(uid, ids, on) { const set = new Set(ids); cart(uid).forEach(l => { if (set.has(l.id)) l.selected = !!on; }); Store.save(); }
  const selectedCart = uid => cart(uid).filter(l => l.selected !== false);
  /** Change a Rental List line's rental time (validated against the supplier's hours and stock). */
  function setCartTime(uid, lineId, time) {
    const l = cart(uid).find(x => x.id === lineId); if (!l) return { ok: false, error: 'Item not found.' };
    const cand = Object.assign({}, l, { time });
    const t = Slots.validateLine(cand); if (t.length) return { ok: false, error: t[0] };
    const chk = Inventory.check(cart(uid).filter(x => x.supplierId === l.supplierId && x.id !== l.id).concat([cand]));
    if (!chk.ok) return { ok: false, error: 'Not enough stock for that time: ' + chk.bad.map(b => (Store.product(b.productId) || {}).name + ' on ' + U.fmtDate(b.date) + ' (available ' + b.available + ')').join('; ') + '.' };
    l.time = time; Store.save(); return { ok: true };
  }
  function updateCartLine(uid, lineId, patch) { const l = cart(uid).find(x => x.id === lineId); if (l) { Object.assign(l, patch); Store.save(); } return l; }
  function removeCartLine(uid, lineId) { const d = Store.load(); d.carts[uid] = cart(uid).filter(l => l.id !== lineId); Store.save(); }
  function cartGroups(uid) { return U.groupBy(cart(uid), l => l.supplierId); }

  /* ================= RENTAL REQUESTS ================= */
  /* ---------- inventory source allocation (multi-location) ----------
     Each booking line is assigned to ONE inventory location. A single location is preferred;
     if none has enough units the line is split by quantity across locations. The supplier
     reviews (and may change) the proposed allocation before accepting. */
  function autoAllocate(lines, supplierId, excludeRequestId) {
    const locs = Inventory.locationsOf(supplierId);
    if (!locs.length) return { ok: true, lines: lines.map(l => Object.assign({}, l, { sourceId: null })) };
    const used = {}; const out = [];
    const perUnit = l => (l.type === 'package' ? (Store.pkg(l.refId) || { items: [] }).items.map(it => [it.productId, it.qty]) : [[l.refId, 1]]);
    const freeAt = (l, loc, d) => {
      const win = Inventory.lineWindow(l, d);
      return Math.min.apply(null, perUnit(l).map(([pid, k]) => Math.floor((Inventory.availableAt(pid, loc, d, { window: win, excludeRequestId }) - (used[pid + '|' + loc + '|' + d] || 0)) / k)));
    };
    const take = (l, loc, d, q) => perUnit(l).forEach(([pid, k]) => { const key = pid + '|' + loc + '|' + d; used[key] = (used[key] || 0) + q * k; });
    for (const l of lines) {
      const dates = Object.keys(l.days).filter(d => Number(l.days[d]) > 0).sort();
      const fits = locs.map(loc => ({ loc: loc.id, slack: Math.min.apply(null, dates.map(d => freeAt(l, loc.id, d) - Number(l.days[d]))) })).filter(x => x.slack >= 0).sort((x, y) => y.slack - x.slack);
      const pref = l.sourceId && fits.find(x => x.loc === l.sourceId);
      if (fits.length) { const loc = (pref || fits[0]).loc; dates.forEach(d => take(l, loc, d, Number(l.days[d]))); out.push(Object.assign({}, l, { sourceId: loc })); continue; }
      // split this line across locations, date by date
      const parts = {};
      for (const d of dates) {
        let rest = Number(l.days[d]);
        const order = locs.map(loc => ({ id: loc.id, free: freeAt(l, loc.id, d) })).sort((x, y) => y.free - x.free);
        for (const o of order) { if (rest <= 0) break; const q = Math.min(rest, Math.max(0, o.free)); if (q > 0) { (parts[o.id] = parts[o.id] || {})[d] = q; take(l, o.id, d, q); rest -= q; } }
        if (rest > 0) return { ok: false, error: 'Not enough ' + itemName(l) + ' across the supplier\'s inventory locations on ' + U.fmtDate(d) + '.' };
      }
      Object.entries(parts).forEach(([loc, days], i) => out.push(Object.assign({}, l, { id: l.id + (i ? '-' + (i + 1) : ''), days, sourceId: loc, splitFrom: l.id })));
    }
    return { ok: true, lines: out };
  }

  /** Sum quantities per item and date — used to prove the checkout did not change what was in the Rental List. */
  function qtyFingerprint(lines) {
    const m = {};
    lines.forEach(l => Object.entries(l.days || {}).forEach(([d, q]) => { q = Number(q) || 0; if (q) { const k = l.type + ':' + l.refId + ':' + d; m[k] = (m[k] || 0) + q; } }));
    return m;
  }

  /**
   * Validate one supplier order and build the request record (nothing is saved here).
   * o = { supplierId, cartLineIds, lines, eventName, eventType, eventDate, fulfillment, destinations,
   *       contactName, contactPhone, instructions, plan, method, services, installmentPlan }
   */
  function prepareOrder(uid, o) {
    const s = Store.supplier(o.supplierId);
    if (!s || s.status !== 'approved') return { ok: false, error: 'This supplier is not available.' };
    const u = Store.user(uid);
    if (u.supplierId === s.id) return { ok: false, error: 'You cannot rent from your own shop.' };
    const src = cart(uid).filter(l => (o.cartLineIds || []).includes(l.id) && l.supplierId === s.id);
    if (!src.length) return { ok: false, error: 'There are no selected items for ' + s.name + ' in your rental list.' };
    // the checkout may split items between delivery locations or change their time, but never the quantities
    let lines = (o.lines && o.lines.length ? o.lines : src).map((l, i) => ({ id: 'l' + (i + 1), type: l.type, refId: l.refId, days: Object.fromEntries(Object.entries(l.days || {}).filter(([, q]) => Number(q) > 0).map(([d, q]) => [d, Math.floor(Number(q))])), time: l.time && l.time.start ? { start: l.time.start, end: l.time.end } : null, destId: l.destId || null })).filter(l => Object.keys(l.days).length);
    if (!lines.length) return { ok: false, error: 'Set a quantity for at least one date.' };
    const fa = qtyFingerprint(src), fb = qtyFingerprint(lines);
    if (Object.keys(fa).length !== Object.keys(fb).length || Object.keys(fa).some(k => fa[k] !== fb[k])) return { ok: false, error: 'The quantities assigned to your delivery locations must add up to exactly what is in your Rental List for ' + s.name + '.' };
    for (const l of lines) { const it = l.type === 'package' ? Store.pkg(l.refId) : Store.product(l.refId); if (!it || it.supplierId !== s.id || !isListed(l.type === 'package' ? 'package' : 'product', it)) return { ok: false, error: (it ? it.name : 'An item') + ' is no longer available from ' + s.name + '.' }; }
    const tIssues = Slots.timeIssues(lines); if (tIssues.length) return { ok: false, error: tIssues[0], timeIssues: tIssues };
    const dur = durationIssues(lines); if (dur.length) return { ok: false, error: dur[0].message, durationIssues: dur };
    const chk = Inventory.check(lines);
    if (!chk.ok) return { ok: false, error: s.name + ': some quantities exceed the available stock for the selected dates and times. Please adjust them.', check: chk };
    // fulfillment & delivery locations
    const fulfillment = o.fulfillment === 'pickup' ? 'pickup' : 'delivery';
    if (fulfillment === 'delivery' && s.fulfillment === 'pickup') return { ok: false, error: s.name + ' offers pickup only.' };
    if (fulfillment === 'pickup' && s.fulfillment === 'delivery') return { ok: false, error: s.name + ' offers delivery only.' };
    let destinations = [];
    if (fulfillment === 'delivery') {
      destinations = (o.destinations || []).map((d, i) => ({ id: d.id || 'd' + (i + 1), label: String(d.label || 'Location ' + (i + 1)).trim(), address: String(d.address || '').trim(), zoneId: (s.deliveryZones || []).some(z => z.id === d.zoneId) ? d.zoneId : '', deliveryTime: d.deliveryTime || '', contactName: String(d.contactName || '').trim(), contactPhone: String(d.contactPhone || '').trim() }));
      if (!destinations.length) return { ok: false, error: 'Add a delivery address.' };
      const bad = destinations.find(d => d.address.length < 8); if (bad) return { ok: false, error: 'Enter the complete address for "' + bad.label + '".' };
      if (destinations.length === 1) lines.forEach(l => { if (!l.destId) l.destId = destinations[0].id; });
      if (lines.some(l => !destinations.some(d => d.id === l.destId))) return { ok: false, error: 'Assign every item to one of your delivery locations.' };
      const empty = destinations.find(d => !lines.some(l => l.destId === d.id)); if (empty) return { ok: false, error: '"' + empty.label + '" has no items assigned. Assign items to it or remove it.' };
    } else lines.forEach(l => { l.destId = null; });
    // payment plan & method
    const plans = Finance.plansOf(s);
    const plan = plans.includes(o.plan) ? o.plan : null;
    if (!plan) return { ok: false, error: s.name + ' does not offer the selected payment plan. Choose one of: ' + plans.map(k => Finance.PLAN_LABEL[k]).join(', ') + '.' };
    if (!(s.methods || []).includes(o.method)) return { ok: false, error: s.name + ' does not accept that payment method.' };
    const allDates = lines.flatMap(l => Object.keys(l.days)).sort();
    const eventDate = o.eventDate || allDates[0];
    if (eventDate < allDates[0] || eventDate > allDates[allDates.length - 1]) return { ok: false, error: s.name + ': the event date must fall within the rental dates (' + U.fmtRange(allDates[0], allDates[allDates.length - 1]) + ').' };
    const services = (o.services || []).filter(id => (s.services || []).some(x => x.id === id));
    const r = {
      customerId: uid, supplierId: s.id, eventName: String(o.eventName || '').trim(), eventType: o.eventType, eventDate,
      start: allDates[0], end: allDates[allDates.length - 1], fulfillment, destinations, address: destinations.length ? destinations[0].address : '',
      contactName: o.contactName, contactPhone: o.contactPhone, instructions: o.instructions || '',
      lines, plan, method: o.method, services, status: 'pending', history: [], supplierNotes: ''
    };
    if (r.eventName.length < 3) return { ok: false, error: 'Enter the event name.' };
    r.pricing = Pricing.quoteRequest(r);
    if (plan === 'installment') {
      const el = Finance.installmentEligibility(s, eventDate);
      if (!el.ok) return { ok: false, error: s.name + ': ' + el.message };
      const items = ((o.installmentPlan && o.installmentPlan.items) || []).map(x => ({ due: x.due, amount: U.round2(Number(x.amount)) }));
      const err = Finance.validateInstallments(items, r.pricing.finalBalance, eventDate, U.addDays(U.today(), 1), Finance.planConfig(s).installment.maxInstallments);
      if (err) return { ok: false, error: s.name + ': ' + err };
      r.installmentPlan = { status: 'proposed', items, proposedAt: new Date().toISOString() };
    }
    // propose inventory sources for every line (supplier reviews before accepting)
    const alloc = autoAllocate(lines, s.id);
    if (!alloc.ok) return { ok: false, error: alloc.error };
    r.lines = alloc.lines; r.allocation = { status: 'proposed' };
    return { ok: true, request: r, supplier: s, sourceIds: src.map(l => l.id) };
  }

  /** Submit one or more supplier orders from a single checkout. All are validated first; then each supplier gets its own request. */
  function submitOrders(uid, orders) {
    if (!orders || !orders.length) return { ok: false, error: 'Select at least one item to check out.' };
    const prepared = [];
    for (const o of orders) { const p = prepareOrder(uid, o); if (!p.ok) return Object.assign(p, { supplierId: o.supplierId }); prepared.push(p); }
    // stock across all orders is checked per supplier; orders from different suppliers never share stock
    const created = [];
    prepared.forEach(p => {
      const r = p.request;
      r.id = 'PR-' + String(Store.nextSeq('request')).padStart(4, '0');
      r.createdAt = new Date().toISOString();
      pushHistory(r, 'pending', 'Request submitted');
      Store.insert('requests', r);
      const d = Store.load(); d.carts[uid] = cart(uid).filter(l => !p.sourceIds.includes(l.id)); // unselected items stay in the list
      created.push(r);
      const cust = Store.user(uid);
      const conflicts = Inventory.check(r.lines).rows.filter(x => Inventory.pendingOn(x.productId, x.date, r.id) + x.requested > x.available);
      Store.notify(ownerOf(r.supplierId), 'supplier', 'New rental request', cust.name + ' sent ' + r.id + ' (' + r.eventName + ') for ' + U.fmtRange(r.start, r.end) + ' · ' + Finance.PLAN_LABEL[r.plan] + (r.destinations.length > 1 ? ' · ' + r.destinations.length + ' delivery locations' : '') + '.' + (conflicts.length ? ' ⚠ Overlaps other pending requests.' : '') + (r.installmentPlan ? ' Installment schedule needs your review.' : ''), 'supplier/request.html?id=' + r.id);
      Store.notify(uid, 'customer', 'Rental request submitted', r.id + ' was sent to ' + p.supplier.name + ' and is pending review. You will be notified once they respond.', 'customer/rental.html?id=' + r.id);
    });
    Store.save();
    return { ok: true, requests: created, request: created[0] };
  }
  /** Single-supplier submission (all of that supplier's items in the Rental List). */
  function submitRequest(uid, supplierId, form) {
    const ids = cart(uid).filter(l => l.supplierId === supplierId).map(l => l.id);
    if (!ids.length) return { ok: false, error: 'There are no items for this supplier in your rental list.' };
    const dest = form.destinations || (form.fulfillment === 'delivery' ? [{ id: 'd1', label: 'Delivery address', address: form.address, zoneId: form.zoneId || '' }] : []);
    return submitOrders(uid, [Object.assign({}, form, { supplierId, cartLineIds: ids, destinations: dest, plan: form.plan || form.arrangement })]);
  }

  /** Supplier changes the inventory source of one line (before the items are released). */
  function setLineSource(reqId, lineId, locId) {
    const r = Store.request(reqId); if (!r) return { ok: false, error: 'Request not found.' };
    if (!orgAllowed(r.supplierId, 'orders.manage') && !orgAllowed(r.supplierId, 'inventory.manage')) return { ok: false, error: 'You do not have permission to change inventory allocation.' };
    if (!['pending', 'accepted', 'in_progress'].includes(r.status)) return { ok: false, error: 'The allocation can only be changed before the items are released.' };
    if (!Inventory.locationsOf(r.supplierId).some(l => l.id === locId)) return { ok: false, error: 'Unknown inventory location.' };
    const l = r.lines.find(x => x.id === lineId); if (!l) return { ok: false, error: 'Line not found.' };
    const trial = r.lines.map(x => x.id === lineId ? Object.assign({}, x, { sourceId: locId }) : x);
    const chk = Inventory.check(trial, { excludeRequestId: r.status === 'pending' ? r.id : r.id });
    const locBad = chk.bad.filter(b => b.locationId === locId);
    if (locBad.length) return { ok: false, error: 'Not enough units at ' + locName(r.supplierId, locId) + ': ' + locBad.map(b => (Store.product(b.productId) || {}).name + ' on ' + U.fmtDate(b.date) + ' (available ' + b.available + ', needed ' + b.requested + ')').join('; ') + '.' };
    const from = l.sourceId; l.sourceId = locId; pushHistory(r, r.status, 'Inventory source for ' + itemName(l) + ' changed: ' + locName(r.supplierId, from) + ' → ' + locName(r.supplierId, locId)); Store.save();
    Store.audit('Changed inventory allocation', r.id + ' · ' + itemName(l) + ' → ' + locName(r.supplierId, locId));
    return { ok: true };
  }
  function locName(supplierId, id) { const l = Inventory.locationsOf(supplierId).find(x => x.id === id); return l ? l.name : '—'; }

  /** Customer (re)proposes an installment schedule; supplier approves or rejects it. */
  function proposeInstallmentPlan(reqId, items) {
    const r = Store.request(reqId); const a = actor();
    if (!r || !a || r.customerId !== a.id) return { ok: false, error: 'Request not found.' };
    if (r.plan !== 'installment') return { ok: false, error: 'This order does not use the Installment Payment Plan.' };
    if (r.installmentPlan && r.installmentPlan.status === 'approved') return { ok: false, error: 'The installment schedule is already approved.' };
    if (!['pending'].concat(Finance.ACTIVE).includes(r.status)) return { ok: false, error: 'This order is closed.' };
    const s = Store.supplier(r.supplierId);
    items = (items || []).map(x => ({ due: x.due, amount: U.round2(Number(x.amount)) }));
    const err = Finance.validateInstallments(items, r.pricing.finalBalance, r.eventDate, U.addDays(U.today(), 1), Finance.planConfig(s).installment.maxInstallments);
    if (err) return { ok: false, error: err };
    r.installmentPlan = { status: 'proposed', items, proposedAt: new Date().toISOString(), history: ((r.installmentPlan || {}).history || []).concat(r.installmentPlan ? [r.installmentPlan] : []).map(x => ({ status: x.status, reason: x.reason || '', at: x.reviewedAt || x.proposedAt })) };
    pushHistory(r, r.status, 'Installment schedule proposed (' + items.length + ' payments)'); Store.save();
    Store.notify(ownerOf(r.supplierId), 'supplier', 'Installment schedule to review', a.name + ' proposed ' + items.length + ' installment(s) for ' + r.id + '.', 'supplier/request.html?id=' + r.id);
    return { ok: true };
  }
  function reviewInstallmentPlan(reqId, approve, reason) {
    const r = Store.request(reqId); if (!r) return { ok: false, error: 'Request not found.' };
    if (!orgAllowed(r.supplierId, 'orders.manage')) return { ok: false, error: 'Only the supplier can review installment schedules.' };
    const ip = r.installmentPlan; if (!ip || ip.status !== 'proposed') return { ok: false, error: 'There is no installment schedule waiting for review.' };
    if (!approve && !String(reason || '').trim()) return { ok: false, error: 'Give the customer a reason so they can propose a new schedule.' };
    if (approve) { const err = Finance.validateInstallments(ip.items, r.pricing.finalBalance, r.eventDate, null, null); if (err) return { ok: false, error: err }; }
    ip.status = approve ? 'approved' : 'rejected'; ip.reason = approve ? '' : String(reason).trim(); ip.reviewedAt = new Date().toISOString(); ip.reviewedBy = actor().id;
    pushHistory(r, r.status, approve ? 'Installment schedule approved' : 'Installment schedule rejected: ' + ip.reason); Store.save();
    Store.notify(r.customerId, 'customer', approve ? 'Installment schedule approved' : 'Installment schedule rejected', approve ? Store.supplier(r.supplierId).name + ' approved your ' + ip.items.length + '-payment schedule for ' + r.id + '. The last installment is due ' + U.fmtDate(ip.items[ip.items.length - 1].due) + '.' : 'Your installment schedule for ' + r.id + ' was not approved: ' + ip.reason + ' Please propose a new one.', 'customer/rental.html?id=' + r.id);
    if (approve) notifyFinance(r.supplierId, 'Installment schedule approved', r.id + ' now has ' + ip.items.length + ' scheduled installments.', 'cashier/balances.html');
    Store.audit(approve ? 'Approved installment schedule' : 'Rejected installment schedule', r.id + (approve ? ' · ' + ip.items.length + ' installments' : ' — ' + ip.reason));
    return { ok: true };
  }

  /** Customer cancellation, following the supplier's cancellation policy. */
  function cancelPolicy(r) {
    const s = Store.supplier(r.supplierId);
    const days = (s.policy && s.policy.cancelDays) || 0;
    if (r.status === 'pending') return { allowed: true, free: true, note: 'This request has not been accepted yet, so it can be cancelled at no cost.' };
    if (['accepted', 'in_progress'].includes(r.status)) {
      const deadline = U.addDays(r.start, -days);
      const free = U.today() <= deadline;
      return { allowed: true, free, deadline, note: free ? 'Free cancellation until ' + U.fmtDate(deadline) + '. Verified payments will be refunded by the supplier.' : 'The free cancellation period ended on ' + U.fmtDate(deadline) + '. Per policy: ' + s.policy.cancellation };
    }
    return { allowed: false, note: 'Items have already been released or the rental is closed, so it can no longer be cancelled online. Please contact the supplier.' };
  }
  function cancelRequest(id, reason) {
    const r = Store.request(id); const pol = cancelPolicy(r);
    if (!pol.allowed) return { ok: false, error: pol.note };
    r.status = 'cancelled'; r.cancelReason = reason; r.cancelFree = pol.free; pushHistory(r, 'cancelled', reason); Store.save();
    const fin = Finance.summary(r);
    Store.notify(ownerOf(r.supplierId), 'supplier', 'Customer cancellation', Store.user(r.customerId).name + ' cancelled ' + r.id + '. ' + (fin.paidRental > 0 ? 'Verified payments of ' + U.money(fin.paidRental) + ' need refund review.' : 'No payments were made.'), 'supplier/request.html?id=' + r.id);
    Store.audit('Cancelled rental request', r.id + ' — ' + (pol.free ? 'within free period' : 'after free period') + (reason ? ' · ' + reason : ''));
    return { ok: true };
  }

  /** Acceptance re-checks inventory for EVERY date, validates pricing and policy, then reserves. */
  function acceptReview(id) {
    const r = Store.request(id);
    const chk = Inventory.check(r.lines, { excludeRequestId: r.id });
    const fresh = Pricing.quoteRequest(r);
    const priceChanged = Math.abs(fresh.total - r.pricing.total) > 0.009;
    const s = Store.supplier(r.supplierId);
    const policyIssues = [];
    if (!Finance.plansOf(s).includes(r.plan)) policyIssues.push('"' + Finance.PLAN_LABEL[r.plan] + '" is no longer enabled in your settings (the customer\'s quoted terms are still honored).');
    if (r.plan === 'installment' && (!r.installmentPlan || r.installmentPlan.status !== 'approved')) policyIssues.push('The installment schedule is ' + (r.installmentPlan ? r.installmentPlan.status : 'missing') + ' — review it so the customer knows the due dates.');
    if (!s.methods.includes(r.method)) policyIssues.push('Payment method "' + Finance.METHOD_LABEL[r.method] + '" is no longer accepted.');
    // Duration limits are checked when the request is submitted; a later change to the supplier's
    // limit does not invalidate requests that complied at submission, so it is only flagged here.
    const durationNotes = durationIssues(r.lines);
    return { check: chk, fresh, priceChanged, policyIssues, durationNotes, canAccept: chk.ok && r.status === 'pending' };
  }
  function acceptRequest(id, note) {
    const r = Store.request(id);
    if (!r) return { ok: false, error: 'Request not found.' };
    if (!orgAllowed(r.supplierId, 'orders.manage')) return { ok: false, error: 'You do not have permission to accept requests for this shop.' };
    if (r.status !== 'pending') return { ok: false, error: 'Only pending requests can be accepted.' };
    const rev = acceptReview(id);
    if (!rev.check.ok) {
      Store.notify(ownerOf(r.supplierId), 'supplier', 'Inventory conflict', r.id + ' cannot be accepted — stock is insufficient on ' + rev.check.bad.map(b => U.fmtDate(b.date)).join(', ') + '.', 'supplier/request.html?id=' + r.id);
      return { ok: false, error: 'Not enough stock on ' + rev.check.bad.map(b => U.fmtDate(b.date) + ' (' + Store.product(b.productId).name + (b.locationId ? ' at ' + locName(r.supplierId, b.locationId) : '') + ': need ' + b.requested + ', available ' + b.available + ')').join('; '), review: rev };
    }
    r.status = 'accepted'; r.acceptedAt = U.today(); r.supplierNotes = note || r.supplierNotes;
    r.allocation = { status: 'approved', at: new Date().toISOString(), by: actor().id }; // supplier approved the inventory allocation
    pushHistory(r, 'accepted', note || 'Request accepted'); Store.save();
    const fin = Finance.summary(r);
    const first = fin.schedule[0];
    Store.notify(r.customerId, 'customer', 'Request accepted 🎉', Store.supplier(r.supplierId).name + ' accepted ' + r.id + '. ' + (first ? first.label + ' of ' + U.money(first.amount) + ' is due by ' + U.fmtDate(first.due) + '.' : ''), 'customer/rental.html?id=' + r.id);
    Store.audit('Accepted rental request', r.id + ' · ' + U.money(r.pricing.total));
    return { ok: true };
  }
  function declineRequest(id, reason) {
    const r = Store.request(id);
    if (!r || !orgAllowed(r.supplierId, 'orders.manage')) return { ok: false, error: 'You do not have permission to decline requests for this shop.' };
    if (r.status !== 'pending') return { ok: false, error: 'Only pending requests can be declined.' };
    r.status = 'declined'; r.declineReason = reason; pushHistory(r, 'declined', reason); Store.save();
    Store.notify(r.customerId, 'customer', 'Request declined', Store.supplier(r.supplierId).name + ' declined ' + r.id + (reason ? ': ' + reason : '.'), 'customer/rental.html?id=' + r.id);
    Store.audit('Declined rental request', r.id + (reason ? ' — ' + reason : ''));
    return { ok: true };
  }

  /* Fulfillment workflow */
  const FLOW = {
    accepted: ['in_progress'],
    in_progress: ['ready_pickup', 'out_delivery'],
    ready_pickup: ['ongoing'],
    out_delivery: ['ongoing'],
    ongoing: ['return_pending'],
    return_pending: ['inspection'],
    inspection: ['completed']
  };
  const ACTION_LABEL = { in_progress: 'Start preparing items', ready_pickup: 'Mark ready for pickup', out_delivery: 'Mark out for delivery', ongoing: 'Record release to customer', return_pending: 'Mark return pending', inspection: 'Record returned items', completed: 'Complete rental' };
  function advance(id, to, data) {
    const r = Store.request(id);
    if (!r || !(orgAllowed(r.supplierId, 'fulfillment.update') || orgAllowed(r.supplierId, 'orders.manage'))) return { ok: false, error: 'You do not have permission to update this rental.' };
    if (!(FLOW[r.status] || []).includes(to)) return { ok: false, error: 'This step is not allowed from "' + statusLabel(r.status) + '".' };
    if (to === 'ready_pickup' && r.fulfillment !== 'pickup') return { ok: false, error: 'This rental is for delivery.' };
    if (to === 'out_delivery' && r.fulfillment !== 'delivery') return { ok: false, error: 'This rental is for pickup.' };
    r.fulfillmentLog = r.fulfillmentLog || {};
    if (to === 'ongoing') {
      if (!data || !data.receivedBy) return { ok: false, error: 'Enter the name of the person receiving the items.' };
      Object.assign(r.fulfillmentLog, { releaseDate: data.releaseDate || U.today(), releaseQty: data.releaseQty, receivedBy: data.receivedBy, method: r.fulfillment });
    }
    r.status = to; pushHistory(r, to, data && data.note); Store.save();
    const msgs = { in_progress: 'is being prepared', ready_pickup: 'is ready for pickup at ' + Store.supplier(r.supplierId).address, out_delivery: 'is out for delivery', ongoing: 'has been released — enjoy your event!', return_pending: 'is due for return. Please prepare the items for pickup/return.' };
    if (msgs[to]) Store.notify(r.customerId, 'customer', to === 'return_pending' ? 'Return reminder' : (r.fulfillment === 'delivery' ? 'Delivery update' : 'Pickup update'), r.id + ' ' + msgs[to] + '.', 'customer/rental.html?id=' + r.id);
    return { ok: true };
  }

  /* ================= DAMAGE, LOSS & COMPENSATION =================
     Compensation = Product value × Compensation % × Affected quantity
     The product's recorded replacement value (not the rental fee) is the basis. Each supplier sets
     its own percentages per category (optionally per product category); "Lost or not returned"
     depends on the product's condition before the rental. Nothing is charged until the supplier
     reviews and confirms the assessment. */
  const CONDITIONS = { brand_new: 'Brand New', good_as_new: 'Good as New', used: 'Used' };
  const DAMAGE_CATS = { repairable: 'Repairable Damage', nonRepairable: 'Non-Repairable Damage', lost: 'Lost or Not Returned' };
  const DEFAULT_DAMAGE = { repairable: { enabled: true, pct: 50 }, nonRepairable: { enabled: true, pct: 70 }, lost: { enabled: true, brand_new: 100, good_as_new: 100, used: 70 } };
  function damagePolicy(sup) { const d = (sup && sup.damagePolicy) || {}; return { repairable: Object.assign({}, DEFAULT_DAMAGE.repairable, d.repairable), nonRepairable: Object.assign({}, DEFAULT_DAMAGE.nonRepairable, d.nonRepairable), lost: Object.assign({}, DEFAULT_DAMAGE.lost, d.lost), categories: d.categories || {} }; }
  /** Rates that apply to one product: the category override if the supplier set one, otherwise the shop default. */
  function damageRates(sup, product) {
    const pol = damagePolicy(sup);
    const ov = product && pol.categories[product.categoryId];
    const pick = k => Object.assign({}, pol[k], ov && ov[k] ? ov[k] : {});
    const lost = pick('lost'); const cond = (product && product.condition) || 'used';
    return { source: ov ? 'category' : 'default', condition: cond, repairable: pick('repairable'), nonRepairable: pick('nonRepairable'), lost: { enabled: lost.enabled, pct: Number(lost[cond] != null ? lost[cond] : lost.used) || 0 } };
  }
  function validateDamagePolicy(dp) {
    const chk = (c, label) => { for (const [k, v] of Object.entries(c)) { if (k === 'enabled') continue; if (!(Number.isFinite(+v) && +v >= 0 && +v <= 100)) return label + ': percentages must be between 0% and 100%.'; } return null; };
    for (const k of Object.keys(DAMAGE_CATS)) { const e = chk(dp[k] || {}, DAMAGE_CATS[k]); if (e) return e; }
    for (const [cat, o] of Object.entries(dp.categories || {})) for (const k of Object.keys(DAMAGE_CATS)) { const e = chk((o || {})[k] || {}, ((Store.category(cat) || {}).name || cat) + ' – ' + DAMAGE_CATS[k]); if (e) return e; }
    return null;
  }
  /** Units of each product that went out on a rental (the busiest day). */
  function rentedUnits(r) { const peak = {}; Object.values(Inventory.demandMap(r.lines)).forEach(m => Object.entries(m).forEach(([pid, q]) => { peak[pid] = Math.max(peak[pid] || 0, q); })); return peak; }
  /** Compute a damage assessment (no side effects). input items: [{productId, returned, repairable, nonRepairable, description}] */
  function assessDamage(r, input) {
    const sup = Store.supplier(r.supplierId); const rented = rentedUnits(r); const errors = []; const items = [];
    Object.keys(rented).forEach(pid => {
      const p = Store.product(pid); const x = (input || []).find(i => i.productId === pid) || {};
      const n = k => Math.max(0, Math.floor(Number(x[k]) || 0));
      const returned = x.returned == null ? rented[pid] : n('returned');
      const repairable = n('repairable'), nonRepairable = n('nonRepairable');
      const lost = Math.max(0, rented[pid] - returned);
      if (returned > rented[pid]) errors.push(p.name + ': returned (' + returned + ') cannot exceed the quantity rented (' + rented[pid] + ').');
      if (repairable + nonRepairable > returned) errors.push(p.name + ': damaged units (' + (repairable + nonRepairable) + ') cannot exceed the units returned (' + returned + ').');
      const rates = damageRates(sup, p); const value = Number(p.value) || 0;
      const pct = { repairable: rates.repairable.enabled ? +rates.repairable.pct : 0, nonRepairable: rates.nonRepairable.enabled ? +rates.nonRepairable.pct : 0, lost: rates.lost.enabled ? +rates.lost.pct : 0 };
      const amounts = { repairable: r2(value * pct.repairable / 100 * repairable), nonRepairable: r2(value * pct.nonRepairable / 100 * nonRepairable), lost: r2(value * pct.lost / 100 * lost) };
      items.push({ productId: pid, rented: rented[pid], returned, repairable, nonRepairable, lost, condition: p.condition || 'used', value, pcts: pct, enabled: { repairable: rates.repairable.enabled, nonRepairable: rates.nonRepairable.enabled, lost: rates.lost.enabled }, rateSource: rates.source, amounts, total: r2(amounts.repairable + amounts.nonRepairable + amounts.lost), description: String(x.description || '').trim() });
    });
    items.filter(i => i.repairable + i.nonRepairable + i.lost > 0 && !i.description).forEach(i => errors.push(Store.product(i.productId).name + ': describe the damage or loss.'));
    return { ok: !errors.length, errors, items, total: r2(U.sum(items, i => i.total)) };
  }
  /** Record the CONFIRMED return inspection. Updates inventory by condition and creates one compensation record. */
  function recordInspection(id, data) {
    const r = Store.request(id);
    if (!r || !orgAllowed(r.supplierId, 'returns.inspect')) return { ok: false, error: 'You do not have permission to record inspections.' };
    if (r.status !== 'return_pending') return { ok: false, error: 'Inspection can only be recorded when the rental is Return Pending.' };
    if (r.inspection) return { ok: false, error: 'This rental has already been inspected — compensation cannot be charged twice.' };
    if (!data || data.confirmed !== true) return { ok: false, error: 'Review and confirm the damage assessment before it is finalized.' };
    const as = assessDamage(r, data.items);
    if (!as.ok) return { ok: false, error: as.errors[0], errors: as.errors };
    const who = actor().id; const now = new Date().toISOString();
    as.items.forEach(it => {
      const p = Store.product(it.productId); if (!p) return;
      p.damaged = (p.damaged || 0) + it.repairable;          // unavailable until marked repaired
      p.writtenOff = (p.writtenOff || 0) + it.nonRepairable; // permanently removed from usable stock
      p.missing = (p.missing || 0) + it.lost;                 // lost / not returned
      if (it.repairable) logMovement(p, 'damaged', it.repairable, null, null, r.id + ' — repairable damage (unavailable until repaired)');
      if (it.nonRepairable) logMovement(p, 'write_off', it.nonRepairable, null, null, r.id + ' — non-repairable');
      if (it.lost) logMovement(p, 'lost', it.lost, null, null, r.id + ' — lost / not returned');
    });
    r.fulfillmentLog = Object.assign(r.fulfillmentLog || {}, { returnDate: data.returnDate || U.today(), returnedQty: as.items.map(i => i.returned + '/' + i.rented + '× ' + Store.product(i.productId).name).join(', ') });
    r.inspection = { at: now, by: who, returnDate: data.returnDate || U.today(), items: as.items, notes: data.notes || '', photos: data.photos || [], compensation: as.total, settlement: as.total > 0 ? 'pending' : 'settled', confirmed: true, confirmedAt: now };
    r.status = 'inspection'; pushHistory(r, 'inspection', as.total > 0 ? 'Damage assessment confirmed: ' + U.money(as.total) + ' compensation' : 'All items returned in good condition'); Store.save();
    Store.notify(r.customerId, 'customer', as.total > 0 ? 'Damage report' : 'Return inspection done', as.total > 0 ? 'The supplier confirmed damaged or missing items for ' + r.id + '. Compensation: ' + U.money(as.total) + '. Open the rental to see the breakdown and evidence.' : 'All items for ' + r.id + ' passed inspection.', 'customer/rental.html?id=' + r.id);
    if (as.total > 0) notifyFinance(r.supplierId, 'Damage compensation due', r.id + ': ' + U.money(as.total) + ' compensation recorded.', 'cashier/balances.html');
    Store.audit('Confirmed return inspection', r.id + ' · compensation ' + U.money(as.total));
    return { ok: true, assessment: as };
  }
  /** Repaired units go back to available stock. */
  function markRepaired(pid, qty) {
    const p = Store.product(pid); qty = Math.floor(Number(qty) || 0);
    if (!p || !orgAllowed(p.supplierId, 'inventory.manage')) return { ok: false, error: 'You do not have permission to update inventory.' };
    if (!(qty > 0) || qty > (p.damaged || 0)) return { ok: false, error: 'Enter between 1 and ' + (p.damaged || 0) + ' repaired unit(s).' };
    p.damaged -= qty; logMovement(p, 'repaired', qty, null, null, 'Marked repaired and available'); Store.save();
    Store.audit('Marked units repaired', p.name + ' · ' + qty); return { ok: true };
  }

  /** Settle deposit vs. compensation. mode: deducted | paid | waived. Records the deposit refund. */
  function settleDeposit(id, mode) {
    const r = Store.request(id);
    if (!r || !(orgAllowed(r.supplierId, 'payments.record') || orgAllowed(r.supplierId, 'orders.manage'))) return { ok: false, error: 'You do not have permission to settle deposits.' };
    const f = Finance.summary(r);
    if (!r.inspection) return { ok: false, error: 'Record the return inspection first.' };
    if (mode === 'paid' && f.compensationDue > 0 && f.compPaidDirect < f.compensation) return { ok: false, error: 'Compensation has not been paid yet. The customer must pay ' + U.money(f.compensationDue) + ' (verified by the cashier).' };
    r.inspection.settlement = r.inspection.compensation > 0 ? mode : 'settled';
    r.inspection.settledAt = new Date().toISOString();
    Store.save();
    const after = Finance.summary(r);
    const refund = r2(after.depositHeld);
    if (refund > 0) {
      Store.insert('payments', { id: 'TXN-' + Store.nextSeq('payment'), requestId: r.id, type: 'deposit_refund', method: 'cash', amount: refund, date: U.today(), submittedAt: new Date().toISOString(), reference: 'RF-' + Date.now().toString().slice(-5), status: 'verified', verifiedBy: actor().id, verifiedAt: new Date().toISOString(), notes: mode === 'deducted' ? U.money(Math.min(f.compensation, f.depositPaid)) + ' deducted for damage' : 'Full deposit returned', recordedBy: 'supplier' });
    }
    Store.notify(r.customerId, 'customer', 'Refund update', 'Security deposit for ' + r.id + ' settled. ' + (refund > 0 ? U.money(refund) + ' returned to you.' : 'No refund due.') + (mode === 'deducted' && f.compensation ? ' ' + U.money(Math.min(f.compensation, f.depositPaid)) + ' was applied to damages.' : ''), 'customer/rental.html?id=' + r.id);
    Store.audit('Settled security deposit', r.id + ' · ' + mode + ' · refund ' + U.money(refund));
    return { ok: true, refund };
  }

  function completionChecklist(r) {
    const f = Finance.summary(r);
    return [
      { label: 'Returned items inspected', ok: !!r.inspection },
      { label: 'Rental balance fully paid', ok: f.balance <= 0, detail: f.balance > 0 ? U.money(f.balance) + ' outstanding' : '' },
      { label: 'Compensation settled', ok: !r.inspection || (r.inspection.settlement !== 'pending' && f.compensationDue <= 0), detail: f.compensationDue > 0 ? U.money(f.compensationDue) + ' due' : '' },
      { label: 'Security deposit resolved', ok: f.depositHeld <= 0, detail: f.depositHeld > 0 ? U.money(f.depositHeld) + ' still held' : '' }
    ];
  }
  function completeRental(id) {
    const r = Store.request(id);
    if (!r || !(orgAllowed(r.supplierId, 'orders.manage') || orgAllowed(r.supplierId, 'fulfillment.update'))) return { ok: false, error: 'You do not have permission to complete this rental.' };
    if (r.status !== 'inspection') return { ok: false, error: 'Record the return inspection before completing.' };
    const bad = completionChecklist(r).filter(c => !c.ok);
    if (bad.length) return { ok: false, error: 'Cannot complete yet: ' + bad.map(b => b.label + (b.detail ? ' (' + b.detail + ')' : '')).join('; ') };
    r.status = 'completed'; r.completedAt = U.today(); pushHistory(r, 'completed', 'Rental completed'); Store.save();
    r.lines.forEach(l => { if (l.type === 'item') { const p = Store.product(l.refId); if (p) p.rentCount = (p.rentCount || 0) + 1; } });
    Store.save();
    Store.notify(r.customerId, 'customer', 'Rental completed', r.id + ' is complete. Thank you for renting with ' + Store.supplier(r.supplierId).name + '! You can now leave a review.', 'customer/rental.html?id=' + r.id);
    Store.audit('Completed rental', r.id);
    return { ok: true };
  }

  /* ================= PAYMENTS ================= */
  function validatePayment(r, data) {
    const amt = Number(data.amount);
    if (!(amt > 0)) return 'Enter an amount greater than zero.';
    const f = Finance.summary(r);
    if (Finance.RENTAL_TYPES.includes(data.type) && amt > f.balance - f.pendingRental + 0.001) return 'Amount exceeds the remaining balance (' + U.money(Math.max(0, f.balance - f.pendingRental)) + ' after pending submissions).';
    if (data.type === 'deposit' && amt > f.depositRequired - f.depositPaid + 0.001) return 'Deposit required is ' + U.money(Math.max(0, f.depositRequired - f.depositPaid)) + '.';
    if (data.type === 'compensation' && amt > f.compensationDue + 0.001) return 'Compensation due is ' + U.money(f.compensationDue) + '.';
    if (['bank', 'debit'].includes(data.method) && !String(data.reference || '').trim()) return 'Reference number is required for bank transfers and debit payments.';
    // duplicate protection: one reference number can only ever be used for one payment
    const ref = String(data.reference || '').trim().toLowerCase();
    if (ref) { const dup = Store.where('payments', x => x.status !== 'rejected' && String(x.reference || '').trim().toLowerCase() === ref)[0]; if (dup) return 'This reference number was already used for ' + dup.id + ' (' + Finance.TYPE_LABEL[dup.type] + '). Duplicate payments are not accepted.'; }
    if (r.status === 'pending') return 'Payments can be made once the supplier accepts the request.';
    return null;
  }
  /** Customer (or supplier on behalf of customer) submits a payment — it stays PENDING until a cashier verifies it. */
  function submitPayment(reqId, data, source) {
    const r = Store.request(reqId);
    if (!Finance.ACTIVE.includes(r.status) && r.status !== 'completed') return { ok: false, error: 'Payments can be made once the request is accepted.' };
    const err = validatePayment(r, data); if (err) return { ok: false, error: err };
    const p = { id: 'TXN-' + Store.nextSeq('payment'), requestId: r.id, type: data.type, method: data.method, amount: r2(data.amount), date: data.date || U.today(), submittedAt: new Date().toISOString(), reference: (data.reference || '').trim(), proof: data.proof || null, proofName: data.proofName || '', notes: data.notes || '', status: 'pending', recordedBy: source || 'customer' };
    Store.insert('payments', p);
    const cust = Store.user(r.customerId);
    notifyFinance(r.supplierId, 'New payment submission', cust.name + ' · ' + U.money(p.amount) + ' (' + Finance.TYPE_LABEL[p.type] + ') for ' + r.id + ' — pending verification.', 'cashier/verification.html');
    Store.notify(ownerOf(r.supplierId), 'supplier', 'Payment submitted', cust.name + ' submitted ' + U.money(p.amount) + ' (' + Finance.TYPE_LABEL[p.type] + ') for ' + r.id + '. Review it in Payments → Verification queue.', 'supplier/payments.html?tab=verify');
    if (source === 'supplier') Store.notify(r.customerId, 'customer', 'Payment recorded', 'The supplier recorded your ' + U.money(p.amount) + ' payment for ' + r.id + '. It will count once verified.', 'customer/rental.html?id=' + r.id);
    return { ok: true, payment: p };
  }
  function issueReceipt(p) { p.receiptNo = 'OR-' + String(Store.nextSeq('receipt')).padStart(5, '0'); }
  function verifyPayment(payId) {
    const p = Store.get('payments', payId); if (!p || p.status !== 'pending') return { ok: false, error: 'Only pending payments can be verified.' };
    const r = Store.request(p.requestId);
    if (!Auth.canFinance(r.supplierId, 'payments.verify')) return { ok: false, error: 'You can only verify payments for your own shop (Organization Admin or its Cashier).' };
    const before = Finance.summary(r);
    if (Finance.RENTAL_TYPES.includes(p.type) && p.amount > before.balance + 0.001) return { ok: false, error: 'This payment exceeds the remaining balance of ' + U.money(before.balance) + '. Reject it or ask the customer to correct the amount.' };
    p.status = 'verified'; p.verifiedBy = actor().id; p.verifiedAt = new Date().toISOString(); issueReceipt(p); Store.save();
    const after = Finance.summary(r);
    Store.notify(r.customerId, 'customer', 'Payment verified ✅', U.money(p.amount) + ' for ' + r.id + ' was verified. Receipt ' + p.receiptNo + '. Payment status: ' + after.status + (after.balance > 0 ? ', balance ' + U.money(after.balance) : '') + '.', 'customer/rental.html?id=' + r.id);
    if (actor().id !== ownerOf(r.supplierId)) Store.notify(ownerOf(r.supplierId), 'supplier', 'Payment verified', U.money(p.amount) + ' for ' + r.id + ' verified by ' + actor().name + '. Status: ' + after.status + '.', 'supplier/request.html?id=' + r.id);
    Store.audit('Verified payment', p.id + ' · ' + U.money(p.amount) + ' · ' + r.id + ' · ' + p.receiptNo);
    return { ok: true, payment: p, summary: after };
  }
  function rejectPayment(payId, reason) {
    const p = Store.get('payments', payId); if (!p || p.status !== 'pending') return { ok: false, error: 'Only pending payments can be rejected.' };
    if (!Auth.canFinance(Store.request(p.requestId).supplierId, 'payments.verify')) return { ok: false, error: 'You can only review payments for your own shop.' };
    if (!reason) return { ok: false, error: 'Please give a reason so the customer can correct it.' };
    p.status = 'rejected'; p.rejectReason = reason; p.verifiedBy = actor().id; p.verifiedAt = new Date().toISOString(); Store.save();
    const r = Store.request(p.requestId);
    Store.notify(r.customerId, 'customer', 'Payment rejected', U.money(p.amount) + ' for ' + r.id + ' was rejected: ' + reason, 'customer/rental.html?id=' + r.id);
    if (actor().id !== ownerOf(r.supplierId)) Store.notify(ownerOf(r.supplierId), 'supplier', 'Payment rejected', 'A ' + U.money(p.amount) + ' submission for ' + r.id + ' was rejected by ' + actor().name + '.', 'supplier/request.html?id=' + r.id);
    Store.audit('Rejected payment', p.id + ' · ' + U.money(p.amount) + ' · ' + reason);
    return { ok: true };
  }
  /** Cashier records an authorized offline payment or refund — verified at once, receipt issued. */
  function cashierRecord(reqId, data) {
    const r = Store.request(reqId); if (!r) return { ok: false, error: 'Rental request not found.' };
    if (!Auth.canFinance(r.supplierId, 'payments.record')) return { ok: false, error: 'You can only record payments for your own shop (Organization Admin or its Cashier).' };
    const f = Finance.summary(r);
    if (data.type === 'refund' && Number(data.amount) > f.paidRental - f.refunded + 0.001) return { ok: false, error: 'Refund cannot exceed verified rental payments (' + U.money(f.paidRental - f.refunded) + ').' };
    if (data.type !== 'refund') { const err = validatePayment(r, Object.assign({}, data, { method: data.method === 'cash' ? 'cash' : data.method })); if (err) return { ok: false, error: err }; }
    if (!String(data.reference || '').trim()) return { ok: false, error: 'Enter the official reference / OR number.' };
    const p = { id: 'TXN-' + Store.nextSeq('payment'), requestId: r.id, type: data.type, method: data.method, amount: r2(data.amount), date: data.date || U.today(), submittedAt: new Date().toISOString(), reference: data.reference.trim(), notes: data.notes || '', status: 'verified', verifiedBy: actor().id, verifiedAt: new Date().toISOString(), recordedBy: 'cashier' };
    issueReceipt(p); Store.insert('payments', p);
    const after = Finance.summary(r);
    Store.notify(r.customerId, 'customer', data.type === 'refund' ? 'Refund update' : 'Payment verified ✅', U.money(p.amount) + ' ' + (data.type === 'refund' ? 'refunded' : 'received') + ' for ' + r.id + '. Receipt ' + p.receiptNo + '.', 'customer/rental.html?id=' + r.id);
    if (actor().id !== ownerOf(r.supplierId)) Store.notify(ownerOf(r.supplierId), 'supplier', 'Payment recorded', actor().name + ' recorded ' + U.money(p.amount) + ' (' + Finance.TYPE_LABEL[p.type] + ') for ' + r.id + '. Status: ' + after.status + '.', 'supplier/request.html?id=' + r.id);
    Store.audit('Recorded offline ' + (data.type === 'refund' ? 'refund' : 'payment'), p.id + ' · ' + U.money(p.amount) + ' · ' + r.id);
    return { ok: true, payment: p };
  }

  /** Detect newly overdue schedule items and notify everyone involved (runs on page load). */
  function scanOverdue() {
    const d = Store.load(); let changed = false;
    d.requests.forEach(r => {
      if (!Finance.ACTIVE.includes(r.status)) return;
      const f = Finance.summary(r);
      f.schedule.filter(s => s.state === 'overdue').forEach(s => {
        r.overdueFlags = r.overdueFlags || {};
        if (r.overdueFlags[s.key]) return;
        r.overdueFlags[s.key] = new Date().toISOString(); changed = true;
        const cust = Store.user(r.customerId);
        const msg = s.label + ' of ' + U.money(s.amount) + ' for ' + r.id + ' was due ' + U.fmtDate(s.due) + '. Outstanding: ' + U.money(f.overdueAmount) + '.';
        Store.notify(r.customerId, 'customer', 'Payment overdue', msg + ' Please pay as soon as possible.', 'customer/rental.html?id=' + r.id);
        Store.notify(ownerOf(r.supplierId), 'supplier', 'Overdue payment', cust.name + ': ' + msg, 'supplier/request.html?id=' + r.id);
        notifyFinance(r.supplierId, 'Overdue payment', cust.name + ': ' + msg, 'cashier/balances.html');
        Store.notify('admin', 'admin', 'Overdue payment', cust.name + ' · ' + msg, 'admin/transactions.html');
        d.audit.push({ id: 'AUD-' + Date.now().toString(36).toUpperCase() + Math.random().toString(36).slice(2, 4), at: new Date().toISOString(), actorId: 'system', role: 'system', action: 'Payment marked overdue', details: r.id + ' · ' + s.label + ' · ' + U.money(f.overdueAmount) });
      });
    });
    if (changed) Store.save();
  }

  /* ================= SUPPLIER ONBOARDING =================
     Two application routes with different requirements:
       Individual Supplier            – personal info, government ID (+ selfie), business info,
                                        DTI / barangay / mayor's permit only if applicable, payout
       Organization / Company Supplier – company info, registration document for its legal
                                        structure (SEC / DTI / CDA), BIR COR, business permit,
                                        authorized representative + proof of authority, payout
     Status: Pending Review → Approved / Rejected / Requires Revision. The applicant becomes the
     Organization Admin once approved and can then add employees. */
  const APP_STATUS = { pending: ['Pending Review', 'b-gold'], approved: ['Approved', 'b-green'], rejected: ['Rejected', 'b-red'], revision: ['Requires Revision', 'b-purple'] };
  const SUPPLIER_TYPES = { individual: 'Individual Supplier', organization: 'Organization / Company Supplier' };
  const COMPANY_TYPES = { corporation: 'Corporation', partnership: 'Partnership', cooperative: 'Cooperative', sole_proprietorship: 'Sole Proprietorship' };
  /** Document checklist. need: 'required' | 'conditional' (required when it applies) | 'recommended' | 'optional' */
  function applicationRequirements(type, companyType) {
    if (type === 'organization') {
      const reg = { corporation: ['sec_certificate', 'SEC Certificate of Incorporation'], partnership: ['sec_partnership', 'SEC Certificate of Recording and Articles of Partnership'], cooperative: ['cda_certificate', 'CDA Certificate of Registration'], sole_proprietorship: ['dti_registration', 'DTI Business Name Registration'] }[companyType] || ['sec_certificate', 'Business registration document'];
      return [
        { key: reg[0], label: reg[1], need: 'required', group: 'Business registration' },
        { key: 'bir_cor', label: 'BIR Certificate of Registration (COR)', need: 'required', group: 'Permits & tax' },
        { key: 'mayors_permit', label: "Mayor's / Business Permit", need: 'required', group: 'Permits & tax' },
        { key: 'barangay_clearance', label: 'Barangay Business Clearance', need: 'conditional', group: 'Permits & tax', note: 'if applicable' },
        { key: 'other_permits', label: 'Other operating permits and licenses', need: 'optional', group: 'Permits & tax' },
        { key: 'rep_id', label: "Authorized representative's government ID", need: 'required', group: 'Authorized representative' },
        { key: 'proof_of_authority', label: 'Proof of authority to represent the company', need: 'required', group: 'Authorized representative' },
        { key: 'board_resolution', label: companyType === 'corporation' ? "Board Resolution or Secretary's Certificate" : 'Special Power of Attorney (SPA) / authorization', need: 'conditional', group: 'Authorized representative', note: 'when applicable' },
        { key: 'establishment_photos', label: 'Photos of the establishment or rental inventory', need: 'recommended', group: 'Company verification' },
        { key: 'address_proof', label: 'Proof of business address', need: 'optional', group: 'Company verification', note: 'if needed' }
      ];
    }
    return [
      { key: 'gov_id', label: 'Valid government-issued ID (photo)', need: 'required', group: 'Identity verification', note: 'National ID, driver\'s license, passport or UMID' },
      { key: 'selfie_id', label: 'Selfie holding the ID', need: 'recommended', group: 'Identity verification' },
      { key: 'dti_registration', label: 'DTI Business Name Registration', need: 'conditional', group: 'Business documents', note: 'if registered as a sole proprietorship' },
      { key: 'barangay_clearance', label: 'Barangay Business Clearance', need: 'conditional', group: 'Business documents', note: 'if applicable' },
      { key: 'mayors_permit', label: "Mayor's / Business Permit", need: 'conditional', group: 'Business documents', note: 'if applicable' }
    ];
  }
  /** Required documents not attached (conditional ones only when the applicant said they apply). */
  function missingDocs(app) {
    const docs = (app && app.docs) || {};
    return applicationRequirements(app.type, app.company && app.company.companyType).filter(q => (q.need === 'required' || (q.need === 'conditional' && (app.applicable || []).includes(q.key))) && !docs[q.key]);
  }
  function validateApplication(app) {
    if (!SUPPLIER_TYPES[app.type]) return 'Choose a supplier type.';
    const req = (o, keys, label) => { const miss = keys.filter(k => !String((o || {})[k] || '').trim()); return miss.length ? label + ': please complete ' + miss.join(', ') + '.' : null; };
    let e;
    if (app.type === 'individual') {
      if ((e = req(app.personal, ['fullName', 'birthDate', 'address', 'phone', 'email'], 'Personal information'))) return e;
      if ((e = req(app.identity, ['idType', 'idNumber'], 'Identity verification'))) return e;
      if ((e = req(app.business, ['address', 'productTypes', 'phone'], 'Business information'))) return e;
    } else {
      if ((e = req(app.company, ['legalName', 'companyType', 'address', 'email', 'phone', 'natureOfBusiness', 'description'], 'Company information'))) return e;
      if (!COMPANY_TYPES[app.company.companyType]) return 'Choose the company type.';
      if ((e = req(app.representative, ['name', 'position', 'idType', 'email', 'phone'], 'Authorized representative'))) return e;
    }
    if ((e = req(app.payout, ['method', 'provider', 'accountName', 'accountNumber'], 'Payout information'))) return e;
    if (app.type === 'organization' && !String((app.payout || {}).financeContact || '').trim()) return 'Payout information: name the person responsible for financial transactions.';
    const miss = missingDocs(app);
    if (miss.length) return 'Missing required document(s): ' + miss.map(m => m.label).join(', ') + '.';
    return null;
  }
  function submitApplication(uid, data) {
    const u = Store.user(uid);
    let ex = u.supplierId ? Store.supplier(u.supplierId) : null;
    if (ex && ex.ownerId !== uid) return { ok: false, error: 'Your account belongs to another supplier organization.' };
    if (ex && !['rejected', 'revision'].includes(ex.status)) return { ok: false, error: 'You already have a supplier profile (' + APP_STATUS[ex.status][0] + ').' };
    if (data.application) { const err = validateApplication(data.application); if (err) return { ok: false, error: err }; }
    const needsReview = Store.settings().requireSupplierApproval;
    const resubmit = !!ex;
    const s = Object.assign(ex || { id: U.uid('s'), ownerId: uid, rating: 0 }, data, { status: needsReview ? 'pending' : 'approved', appliedAt: new Date().toISOString() });
    if (s.application) { s.type = s.application.type; s.application.submittedAt = s.appliedAt; s.application.history = ((ex && ex.application && ex.application.history) || []).concat(ex ? [{ status: ex.status, reason: ex.rejectReason || ex.revisionNotes || '', at: ex.reviewedAt }] : []); }
    s.locations = s.locations && s.locations.length ? s.locations : [{ id: 'L1', name: 'Main location', address: s.address, contact: (s.application && s.application.representative && s.application.representative.name) || u.name, phone: s.phone }];
    delete s.revisionNotes; delete s.rejectReason;
    if (!needsReview) s.approvedAt = s.appliedAt;
    if (!Store.supplier(s.id)) Store.insert('suppliers', s);
    u.supplierId = s.id; Store.save();
    const kind = SUPPLIER_TYPES[s.type] || 'Supplier';
    if (needsReview) {
      Store.notify('admin', 'admin', resubmit ? 'Supplier application resubmitted' : (s.type === 'organization' ? 'New organization application' : 'New supplier application'), u.name + (resubmit ? ' resubmitted ' : ' applied to open ') + s.name + ' (' + kind + ').', 'admin/applications.html');
      Store.notify(uid, 'customer', 'Application submitted', 'Your ' + kind + ' application for ' + s.name + ' is pending review. We will notify you once it is decided.', 'customer/start-selling.html');
    } else Store.notify(uid, 'customer', 'Your shop is live!', s.name + ' is approved. Switch to Supplier Mode to add your first product.', 'supplier/dashboard.html');
    Store.audit(resubmit ? 'Resubmitted supplier application' : 'Submitted supplier application', s.name + ' · ' + kind, u);
    return { ok: true, supplier: s };
  }
  /** decision: 'approve' | 'reject' | 'revision' (true/false accepted for approve/reject). */
  function reviewApplication(sid, decision, reason) {
    if (!Auth.can('applications.review')) return { ok: false, error: 'You do not have permission to review applications.' };
    if (decision === true) decision = 'approve'; if (decision === false) decision = 'reject';
    const s = Store.supplier(sid); if (!s || s.status !== 'pending') return { ok: false, error: 'This application is not pending review.' };
    reason = String(reason || '').trim();
    if (decision !== 'approve' && !reason) return { ok: false, error: decision === 'revision' ? 'List what the applicant needs to correct.' : 'Please state the reason for rejection.' };
    if (decision === 'approve' && s.application) { const miss = missingDocs(s.application); if (miss.length) return { ok: false, error: 'Required documents are missing (' + miss.map(m => m.label).join(', ') + '). Request a revision instead.' }; }
    s.status = { approve: 'approved', reject: 'rejected', revision: 'revision' }[decision];
    s.reviewedAt = new Date().toISOString(); s.reviewedBy = actor().id;
    s.rejectReason = decision === 'reject' ? reason : ''; s.revisionNotes = decision === 'revision' ? reason : '';
    if (decision === 'approve') s.approvedAt = s.reviewedAt;
    Store.save();
    const msg = { approve: [s.name + ' is approved! You are its ' + (s.type === 'organization' ? 'Organization Admin — add your cashier and staff under Team & Roles. ' : 'owner. ') + 'Use "Switch to Supplier Mode" in your profile menu to start listing items.', 'Supplier application approved 🎉'], reject: ['Your application for ' + s.name + ' was not approved: ' + reason, 'Supplier application rejected'], revision: ['Your application for ' + s.name + ' needs changes before it can be approved: ' + reason + ' Update it and resubmit.', 'Application requires revision'] }[decision];
    Store.notify(s.ownerId, 'customer', msg[1], msg[0], 'customer/start-selling.html');
    Store.audit({ approve: 'Approved supplier application', reject: 'Rejected supplier application', revision: 'Requested application revision' }[decision], s.name + ' (' + (SUPPLIER_TYPES[s.type] || 'Supplier') + ')' + (reason ? ' — ' + reason : ''));
    return { ok: true };
  }

  /* ================= TEAM: employees of a supplier organization ================= */
  const EMPLOYEE_ROLES = ['cashier', 'inventory', 'staff'];
  function orgMembers(supplierId) {
    const s = Store.supplier(supplierId); if (!s) return [];
    const owner = Store.user(s.ownerId);
    return (owner ? [Object.assign({ orgRoleKey: 'org_admin' }, { user: owner })] : []).concat(Store.where('users', u => u.supplierId === supplierId && u.id !== s.ownerId && EMPLOYEE_ROLES.includes(u.orgRole)).map(u => ({ orgRoleKey: u.orgRole, user: u })));
  }
  function addEmployee(supplierId, data) {
    if (!orgAllowed(supplierId, 'team.manage')) return { ok: false, error: 'Only the Organization Admin can add employees.' };
    if (!EMPLOYEE_ROLES.includes(data.role)) return { ok: false, error: 'Choose Cashier, Inventory Staff or Staff. Organization Admin rights stay with the account owner.' };
    const email = String(data.email || '').trim().toLowerCase();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return { ok: false, error: 'Enter a valid email address.' };
    if (Store.all('users').some(u => u.email.toLowerCase() === email)) return { ok: false, error: 'That email already has a PartyRent account.' };
    if (String(data.name || '').trim().length < 3) return { ok: false, error: 'Enter the employee\'s full name.' };
    if (String(data.password || '').length < 8) return { ok: false, error: 'The temporary password needs at least 8 characters.' };
    const u = { id: U.uid('u'), name: data.name.trim(), email, phone: String(data.phone || '').trim(), address: Store.supplier(supplierId).address, password: data.password, roles: [data.role === 'cashier' ? 'cashier' : 'employee'], supplierId, orgRole: data.role, status: 'active', createdAt: new Date().toISOString(), createdBy: actor().id };
    Store.insert('users', u);
    Store.audit('Added employee', Store.supplier(supplierId).name + ' · ' + u.name + ' (' + Auth.ORG_ROLES[data.role] + ')');
    return { ok: true, user: u };
  }
  function updateEmployee(supplierId, uid, patch) {
    if (!orgAllowed(supplierId, 'team.manage')) return { ok: false, error: 'Only the Organization Admin can manage employees.' };
    const u = Store.user(uid); const s = Store.supplier(supplierId);
    if (!u || u.supplierId !== supplierId || u.id === s.ownerId) return { ok: false, error: 'You can only manage your own organization\'s employees.' };
    if (patch.role) { if (!EMPLOYEE_ROLES.includes(patch.role)) return { ok: false, error: 'Invalid role.' }; u.orgRole = patch.role; u.roles = [patch.role === 'cashier' ? 'cashier' : 'employee']; }
    if (patch.status) { if (!['active', 'deactivated'].includes(patch.status)) return { ok: false, error: 'Invalid status.' }; u.status = patch.status; }
    if (patch.password) { if (String(patch.password).length < 8) return { ok: false, error: 'Password needs at least 8 characters.' }; u.password = patch.password; }
    Store.save();
    Store.audit('Updated employee', s.name + ' · ' + u.name + ' · ' + Object.keys(patch).map(k => k === 'password' ? 'password reset' : k + ': ' + patch[k]).join(', '));
    return { ok: true, user: u };
  }

  /* ================= SUPPLIER CATALOG / INVENTORY ================= */
  function saveProduct(supplierId, data) {
    if (actor() && actor().supplierId === supplierId && !Auth.orgCan('products.manage', actor())) return { ok: false, error: 'You do not have permission to manage products.' };
    if (!data.name || !data.categoryId) return { ok: false, error: 'Name and category are required.' };
    // stock is kept per inventory location; the total is their sum
    const locs = Inventory.locationsOf(supplierId);
    if (data.stockByLocation && locs.length) {
      const sbl = {};
      for (const l of locs) { const q = Number(data.stockByLocation[l.id] || 0); if (!Number.isInteger(q) || q < 0) return { ok: false, error: 'Stock at ' + l.name + ' must be a whole number (0 or more).' }; if (q) sbl[l.id] = q; }
      data.stockByLocation = sbl; data.totalStock = U.sum(Object.values(sbl), Number);
    } else if (locs.length) {
      if (data.id) { const cur = Store.product(data.id); if (cur && cur.totalStock !== data.totalStock) return { ok: false, error: 'Update stock per inventory location so the total always equals the sum of all locations.' }; data.stockByLocation = cur ? cur.stockByLocation : {}; }
      else data.stockByLocation = { [locs[0].id]: Number(data.totalStock) || 0 };
    }
    if (!(data.totalStock >= 0)) return { ok: false, error: 'Total stock must be zero or more.' };
    if (data.value != null) { data.value = Number(data.value); if (!(data.value > 0)) return { ok: false, error: 'Enter the product\'s replacement value (used to calculate damage compensation).' }; }
    if (data.condition && !CONDITIONS[data.condition]) return { ok: false, error: 'Choose the product condition.' };
    for (const k of ['minRentalHours', 'maxRentalHours']) { if (data[k] == null || data[k] === '') { data[k] = null; continue; } const v = Number(data[k]); if (!(v > 0 && v <= 720)) return { ok: false, error: 'Rental hour limits must be between 1 and 720 hours.' }; data[k] = v; }
    if (data.minRentalHours && data.maxRentalHours && data.minRentalHours > data.maxRentalHours) return { ok: false, error: 'The minimum rental duration cannot be longer than the maximum.' };
    const tiers = (data.tiers || []).filter(t => t.price > 0).sort((a, b) => a.min - b.min);
    if (!tiers.length) return { ok: false, error: 'Add at least one price.' };
    for (let i = 0; i < tiers.length; i++) {
      if (i === 0 && tiers[i].min !== 1) return { ok: false, error: 'The first pricing tier must start at quantity 1.' };
      if (i > 0 && tiers[i].min !== (tiers[i - 1].max || 0) + 1) return { ok: false, error: 'Pricing tiers must be continuous (tier ' + (i + 1) + ' should start at ' + ((tiers[i - 1].max || 0) + 1) + ').' };
      if (i < tiers.length - 1 && !(tiers[i].max >= tiers[i].min)) return { ok: false, error: 'Tier ' + (i + 1) + ' needs a maximum quantity.' };
    }
    tiers[tiers.length - 1].max = null;
    if (data.maxRentalDays != null && data.maxRentalDays !== '') {
      const m = Number(data.maxRentalDays);
      if (!Number.isInteger(m) || m < 1 || m > 365) return { ok: false, error: 'Maximum rental duration must be a whole number of days from 1 to 365, or choose "No maximum".' };
      data.maxRentalDays = m;
    } else data.maxRentalDays = null;
    // moderation fields can only be changed through the admin listing actions
    ['hiddenReason', 'hiddenBy', 'hiddenAt', 'deletedAt', 'deletedBy', 'review', 'reviewedBy', 'reviewedAt', 'removedByAdmin'].forEach(k => { delete data[k]; });
    if (data.id) {
      const p = Store.product(data.id);
      if (!p || p.supplierId !== supplierId) return { ok: false, error: 'You can only edit your own products.' };
      if (p.status === 'deleted') return { ok: false, error: 'This listing was deleted by an administrator.' };
      if (p.status === 'hidden') data.status = 'hidden'; // hidden by admin: cannot be republished by the supplier
      else if (!['published', 'draft', 'archived'].includes(data.status)) data.status = p.status;
      const conflicts = Inventory.stockChangeConflicts(p.id, data.totalStock);
      if (conflicts.length) return { ok: false, error: 'Total stock cannot be reduced to ' + data.totalStock + '. Accepted reservations need ' + conflicts.map(c => c.reserved + ' on ' + U.fmtDate(c.date)).join(', ') + '.', conflicts };
      for (const l of locs) { const nq = Number((data.stockByLocation || {})[l.id] || 0); const lc = Inventory.locationStockConflicts(p.id, l.id, nq); if (lc.length) return { ok: false, error: l.name + ' cannot go down to ' + nq + ' — bookings assigned to it need ' + lc.map(c => c.reserved + ' on ' + U.fmtDate(c.date)).join(', ') + '. Transfer stock or change their allocation first.' }; }
      locs.forEach(l => { const before = stockAt(p, l.id), after = Number((data.stockByLocation || {})[l.id] || 0); if (before !== after) logMovement(p, 'adjust', after - before, l.id, null, 'Stock count updated (' + before + ' → ' + after + ')'); });
      const priceChanged = JSON.stringify(p.tiers) !== JSON.stringify(tiers);
      if ((p.maxRentalDays || null) !== data.maxRentalDays) Store.audit('Updated maximum rental duration', p.name + ': ' + (data.maxRentalDays ? data.maxRentalDays + ' day(s) per booking' : 'no maximum'));
      Object.assign(p, data, { tiers }); Store.save();
      if (priceChanged) Store.audit('Updated product pricing', p.name + ' · ' + tiers.map(t => t.min + (t.max ? '–' + t.max : '+') + ': ' + U.money(t.price)).join(', '));
      return { ok: true, product: p };
    }
    // new listings are published immediately (post-publication moderation by admins)
    const p = Object.assign({ id: U.uid('p'), supplierId, damaged: 0, missing: 0, writtenOff: 0, rating: 0, rentCount: 0, createdAt: new Date().toISOString(), images: [], condition: 'good_as_new' }, data, { tiers, status: 'published' });
    Store.insert('products', p);
    Object.entries(p.stockByLocation || {}).forEach(([lid, q]) => logMovement(p, 'initial', q, lid, null, 'Initial stock'));
    Store.audit('Created product', p.name);
    return { ok: true, product: p };
  }
  function addHold(pid, h) {
    if (!(h.qty > 0)) return { ok: false, error: 'Quantity must be at least 1.' };
    if (h.end && h.end < h.start) return { ok: false, error: 'End date must be on or after the start date.' };
    const c = Inventory.holdConflicts(pid, h.qty, h.start, h.end);
    if (c.length) return { ok: false, error: 'Setting aside ' + h.qty + ' units would break accepted reservations on ' + c.map(x => U.fmtDate(x.date) + ' (' + x.reserved + ' reserved)').join(', ') + '.' };
    Store.insert('holds', Object.assign({ id: U.uid('h'), productId: pid }, h));
    return { ok: true };
  }
  /** Adjust damaged / missing counters (e.g. repaired or found units go back to available). */
  function adjustCondition(pid, field, newValue) {
    const p = Store.product(pid);
    if (newValue < 0) return { ok: false, error: 'Value cannot be negative.' };
    const c = Inventory.stockChangeConflicts(pid, p.totalStock, field === 'damaged' ? newValue : null, field === 'missing' ? newValue : null);
    if (c.length) return { ok: false, error: 'This would leave too few units for accepted reservations on ' + c.map(x => U.fmtDate(x.date)).join(', ') + '.' };
    p[field] = newValue; Store.save();
    Store.audit('Adjusted ' + field + ' units', p.name + ' → ' + newValue);
    return { ok: true };
  }
  function savePackage(supplierId, data) {
    if (!data.name) return { ok: false, error: 'Package name is required.' };
    if (!data.items || !data.items.length) return { ok: false, error: 'Add at least one item to the package.' };
    if (data.items.some(it => { const p = Store.product(it.productId); return !p || p.supplierId !== supplierId; })) return { ok: false, error: 'Packages can only contain your own items.' };
    if (data.items.some(it => !(it.qty > 0))) return { ok: false, error: 'Every package item needs a quantity of at least 1.' };
    if (!(data.price > 0)) return { ok: false, error: 'Set a package price.' };
    for (const k of ['minRentalHours', 'maxRentalHours']) { if (data[k] == null || data[k] === '') { data[k] = null; continue; } const v = Number(data[k]); if (!(v > 0 && v <= 720)) return { ok: false, error: 'Rental hour limits must be between 1 and 720 hours.' }; data[k] = v; }
    if (data.minRentalHours && data.maxRentalHours && data.minRentalHours > data.maxRentalHours) return { ok: false, error: 'The minimum rental duration cannot be longer than the maximum.' };
    ['hiddenByAdmin', 'hiddenReason', 'hiddenBy', 'hiddenAt', 'deleted', 'deletedAt', 'deletedBy', 'review', 'reviewedBy', 'reviewedAt'].forEach(k => { delete data[k]; });
    if (data.id) {
      const k = Store.pkg(data.id); if (!k || k.supplierId !== supplierId) return { ok: false, error: 'Not your package.' };
      if (k.deleted) return { ok: false, error: 'This package was deleted by an administrator.' };
      if (k.hiddenByAdmin) delete data.active; // hidden by admin: the supplier cannot switch it back on
      Object.assign(k, data); Store.save(); return { ok: true, package: k };
    }
    const k = Object.assign({ id: U.uid('k'), supplierId, images: [], createdAt: new Date().toISOString() }, data, { active: true }); // published immediately
    Store.insert('packages', k); return { ok: true, package: k };
  }

  /* ================= INVENTORY LOCATIONS, TRANSFERS & MOVEMENTS ================= */
  const stockAt = (p, lid) => Number(((p && p.stockByLocation) || {})[lid]) || 0;
  const MOVE_LABEL = { initial: 'Initial stock', adjust: 'Stock adjustment', transfer: 'Transfer', damaged: 'Damaged (repairable)', repaired: 'Repaired', write_off: 'Written off (non-repairable)', lost: 'Lost / not returned' };
  function logMovement(p, type, qty, locId, toLocId, note) {
    const d = Store.load(); d.movements = d.movements || [];
    d.movements.push({ id: 'MV-' + String(Store.nextSeq('movement')).padStart(5, '0'), at: new Date().toISOString(), by: actor() ? actor().id : 'system', supplierId: p.supplierId, productId: p.id, type, qty, from: locId || null, to: toLocId || null, note: note || '' });
  }
  function saveLocation(supplierId, data) {
    if (!orgAllowed(supplierId, 'locations.manage')) return { ok: false, error: 'You do not have permission to manage inventory locations.' };
    const s = Store.supplier(supplierId); s.locations = s.locations || [];
    const name = String(data.name || '').trim(), address = String(data.address || '').trim();
    if (name.length < 2) return { ok: false, error: 'Enter a location name (e.g. Main Warehouse).' };
    if (address.length < 8) return { ok: false, error: 'Enter the complete address of the location.' };
    if (s.locations.some(l => l.name.toLowerCase() === name.toLowerCase() && l.id !== data.id)) return { ok: false, error: 'You already have a location with that name.' };
    const rec = { name, address, contact: String(data.contact || '').trim(), phone: String(data.phone || '').trim() };
    if (data.id) { const l = s.locations.find(x => x.id === data.id); if (!l) return { ok: false, error: 'Location not found.' }; Object.assign(l, rec); }
    else { let n = s.locations.length + 1; while (s.locations.some(l => l.id === 'L' + n)) n++; s.locations.push(Object.assign({ id: 'L' + n }, rec)); }
    Store.save(); Store.audit(data.id ? 'Updated inventory location' : 'Added inventory location', s.name + ' · ' + name);
    return { ok: true };
  }
  function deleteLocation(supplierId, locId) {
    if (!orgAllowed(supplierId, 'locations.manage')) return { ok: false, error: 'You do not have permission to manage inventory locations.' };
    const s = Store.supplier(supplierId); const l = (s.locations || []).find(x => x.id === locId); if (!l) return { ok: false, error: 'Location not found.' };
    if (s.locations.length === 1) return { ok: false, error: 'Keep at least one inventory location.' };
    const stocked = Store.where('products', p => p.supplierId === supplierId && p.status !== 'deleted' && stockAt(p, locId) > 0);
    if (stocked.length) return { ok: false, error: l.name + ' still holds stock (' + stocked.map(p => p.name + ': ' + stockAt(p, locId)).join(', ') + '). Transfer it to another location first.' };
    const booked = Store.where('requests', r => r.supplierId === supplierId && ['pending'].concat(Inventory.RESERVING).includes(r.status) && r.lines.some(x => x.sourceId === locId));
    if (booked.length) return { ok: false, error: 'Bookings are allocated to ' + l.name + ' (' + booked.map(r => r.id).join(', ') + '). Change their allocation first.' };
    s.locations = s.locations.filter(x => x.id !== locId); Store.save(); Store.audit('Removed inventory location', s.name + ' · ' + l.name);
    return { ok: true };
  }
  /** Move units between two locations of the same supplier. Never breaks bookings allocated to the source. */
  function transferStock(pid, fromId, toId, qty, note) {
    const p = Store.product(pid); qty = Math.floor(Number(qty) || 0);
    if (!p || !orgAllowed(p.supplierId, 'locations.manage')) return { ok: false, error: 'You do not have permission to transfer stock.' };
    const locs = Inventory.locationsOf(p.supplierId);
    if (!locs.some(l => l.id === fromId) || !locs.some(l => l.id === toId)) return { ok: false, error: 'Choose two of your inventory locations.' };
    if (fromId === toId) return { ok: false, error: 'Choose a different destination location.' };
    if (!(qty > 0)) return { ok: false, error: 'Enter a quantity of at least 1.' };
    if (qty > stockAt(p, fromId)) return { ok: false, error: locName(p.supplierId, fromId) + ' only has ' + stockAt(p, fromId) + ' unit(s) of ' + p.name + '.' };
    const lc = Inventory.locationStockConflicts(pid, fromId, stockAt(p, fromId) - qty);
    if (lc.length) return { ok: false, error: 'Bookings allocated to ' + locName(p.supplierId, fromId) + ' still need ' + lc.map(c => c.reserved + ' on ' + U.fmtDate(c.date)).join(', ') + '. Transfer fewer units or change those allocations.' };
    p.stockByLocation = Object.assign({}, p.stockByLocation); p.stockByLocation[fromId] = stockAt(p, fromId) - qty; p.stockByLocation[toId] = stockAt(p, toId) + qty;
    if (!p.stockByLocation[fromId]) delete p.stockByLocation[fromId];
    logMovement(p, 'transfer', qty, fromId, toId, note || ''); Store.save();
    Store.audit('Transferred stock', p.name + ' · ' + qty + ' unit(s) ' + locName(p.supplierId, fromId) + ' → ' + locName(p.supplierId, toId));
    return { ok: true };
  }

  /* ================= SHOP SETTINGS (validated) ================= */
  const TIME_RE = /^\d{2}:\d{2}$/;
  function saveShopSettings(supplierId, section, data) {
    if (!orgAllowed(supplierId, 'shop.manage')) return { ok: false, error: 'Only the Organization Admin can change shop settings.' };
    const s = Store.supplier(supplierId);
    if (section === 'plans') {
      const err = Finance.validatePlanConfig(data.paymentPlans); if (err) return { ok: false, error: err };
      if (!(data.methods || []).length) return { ok: false, error: 'Keep at least one payment method.' };
      s.paymentPlans = JSON.parse(JSON.stringify(data.paymentPlans)); s.methods = data.methods.slice();
      Object.assign(s.policy, { depositType: data.depositType, depositValue: Number(data.depositValue) || 0 });
      Store.audit('Updated payment plans', s.name + ' · ' + Finance.plansOf(s).map(k => Finance.PLAN_LABEL[k]).join(', '));
    } else if (section === 'hours') {
      const t = data.timeSettings;
      if (!TIME_RE.test(t.open) || !TIME_RE.test(t.close) || Slots.toMin(t.close) <= Slots.toMin(t.open)) return { ok: false, error: 'Rental hours must end later than they start.' };
      if (![t.prepHours, t.recoveryHours].every(v => Number.isFinite(+v) && +v >= 0 && +v <= 24)) return { ok: false, error: 'Preparation and recovery time must be 0–24 hours.' };
      s.timeSettings = { open: t.open, close: t.close, prepHours: +t.prepHours, recoveryHours: +t.recoveryHours };
      Store.audit('Updated rental hours', s.name + ' · ' + Slots.fmt12(t.open) + '–' + Slots.fmt12(t.close) + ', prep ' + t.prepHours + 'h, recovery ' + t.recoveryHours + 'h');
    } else if (section === 'delivery') {
      const zones = (data.deliveryZones || []).map(z => ({ id: z.id || U.uid('z'), name: String(z.name || '').trim(), fee: Number(z.fee) }));
      if (zones.some(z => z.name.length < 2 || !(z.fee >= 0))) return { ok: false, error: 'Every delivery zone needs a name and a fee of ₱0 or more.' };
      if (!(Number(data.deliveryFee) >= 0)) return { ok: false, error: 'The standard delivery rate must be ₱0 or more.' };
      s.fulfillment = data.fulfillment; s.deliveryFee = Number(data.deliveryFee); s.deliveryZones = zones;
    } else if (section === 'services') {
      const list = (data.services || []).map(x => ({ id: x.id || U.uid('sv'), name: String(x.name || '').trim(), description: String(x.description || '').trim(), amount: Number(x.amount), per: x.per === 'location' ? 'location' : 'order', required: !!x.required }));
      if (list.some(x => x.name.length < 3 || !(x.amount >= 0))) return { ok: false, error: 'Every service needs a name (3+ characters) and an amount of ₱0 or more.' };
      s.services = list;
    } else if (section === 'damage') {
      const err = validateDamagePolicy(data.damagePolicy); if (err) return { ok: false, error: err };
      s.damagePolicy = JSON.parse(JSON.stringify(data.damagePolicy));
      Store.audit('Updated damage policy', s.name);
    } else return { ok: false, error: 'Unknown settings section.' };
    Store.save();
    return { ok: true };
  }

  /* ================= LISTING STATUS & ADMIN MODERATION =================
     Products and rental packages share three statuses:
       active   – live in the marketplace (product status 'published' / package active)
       hidden   – Hidden by admin: off the marketplace; the supplier keeps it but cannot republish it
       inactive – turned off by the supplier (product 'draft' or 'archived' / package not active)
     Listings are published automatically; admins moderate afterwards (Hide / Unhide / Delete).
     Deleted listings keep a minimal record so past rentals still show the item name. */
  const LISTING = {
    active: ['Active', 'b-green', 'This listing is currently visible in the customer marketplace.'],
    hidden: ['Hidden by admin', 'b-red', 'This listing has been hidden by an administrator and is no longer visible in the marketplace. The supplier cannot republish it.'],
    inactive: ['Inactive', 'b-gray', 'This listing has been turned off by the supplier and is not visible in the marketplace.'],
    deleted: ['Deleted', 'b-gray', 'This listing was deleted by an administrator.']
  };
  function listingStatus(kind, x) {
    if (!x) return 'deleted';
    if (kind === 'package') return x.deleted ? 'deleted' : x.hiddenByAdmin ? 'hidden' : x.active ? 'active' : 'inactive';
    return x.status === 'deleted' ? 'deleted' : x.status === 'hidden' ? 'hidden' : x.status === 'published' ? 'active' : 'inactive';
  }
  const isListed = (kind, x) => listingStatus(kind, x) === 'active';
  function listingRef(kind, id) { const x = kind === 'package' ? Store.pkg(id) : Store.product(id); return x; }
  const ACTIVE_TX = ['pending'].concat(Inventory.RESERVING, ['inspection']);
  /** Requests that still depend on a listing (pending, accepted, upcoming, ongoing, being returned/inspected). */
  function dependentRequests(kind, id) {
    return Store.where('requests', r => ACTIVE_TX.includes(r.status) && r.lines.some(l => kind === 'package' ? l.type === 'package' && l.refId === id : Object.keys(l.days).some(d => Inventory.unitsForLine(l, d)[id])));
  }
  function moderationGuard(kind, id) {
    if (!Auth.can('listings.manage')) return { error: 'You do not have permission to manage listings.' };
    const x = listingRef(kind, id);
    if (!x || listingStatus(kind, x) === 'deleted') return { error: 'Listing not found.' };
    return { x };
  }
  function notifyListing(kind, x, title, body) {
    const sup = Store.supplier(x.supplierId); if (!sup) return;
    Store.notify(sup.ownerId, 'supplier', title, body, kind === 'package' ? 'supplier/packages.html' : 'supplier/products.html');
  }
  const kindLabel = kind => (kind === 'package' ? 'Rental package' : 'Product');
  function adminHideListing(kind, id, reason) {
    const g0 = moderationGuard(kind, id); if (g0.error) return { ok: false, error: g0.error };
    const x = g0.x; reason = String(reason || '').trim();
    if (!reason) return { ok: false, error: 'Please enter a reason for hiding this listing.' };
    if (listingStatus(kind, x) === 'hidden') return { ok: false, error: 'This listing is already hidden.' };
    const meta = { hiddenReason: reason, hiddenBy: actor().id, hiddenAt: new Date().toISOString() };
    if (kind === 'package') Object.assign(x, meta, { hiddenByAdmin: true }); else Object.assign(x, meta, { status: 'hidden' });
    Store.save(); // accepted / upcoming rentals are untouched — they keep their own records
    notifyListing(kind, x, 'Listing hidden by admin', x.name + ' was hidden from the marketplace. Reason: ' + reason);
    Store.audit('Hid listing', kindLabel(kind) + ' ' + x.id + ' · ' + x.name + ' — Reason: ' + reason);
    return { ok: true };
  }
  function adminUnhideListing(kind, id) {
    const g0 = moderationGuard(kind, id); if (g0.error) return { ok: false, error: g0.error };
    const x = g0.x;
    if (listingStatus(kind, x) !== 'hidden') return { ok: false, error: 'Only listings hidden by an admin can be unhidden.' };
    ['hiddenReason', 'hiddenBy', 'hiddenAt'].forEach(k => { delete x[k]; });
    if (kind === 'package') { x.hiddenByAdmin = false; x.active = true; } else x.status = 'published';
    Store.save();
    notifyListing(kind, x, 'Listing restored', x.name + ' is visible in the marketplace again.');
    Store.audit('Unhid listing', kindLabel(kind) + ' ' + x.id + ' · ' + x.name);
    return { ok: true };
  }
  function adminDeleteListing(kind, id) {
    const g0 = moderationGuard(kind, id); if (g0.error) return { ok: false, error: g0.error };
    const x = g0.x;
    const deps = dependentRequests(kind, id);
    if (deps.length) return { ok: false, blocked: true, error: 'This listing cannot be deleted because it has active or upcoming reservations (' + deps.map(r => r.id).join(', ') + '). You can hide it instead.' };
    const meta = { deletedAt: new Date().toISOString(), deletedBy: actor().id };
    let affected = [];
    if (kind === 'package') Object.assign(x, meta, { deleted: true, active: false, hiddenByAdmin: false });
    else {
      Object.assign(x, meta, { status: 'deleted' });
      affected = Store.where('packages', k => !k.deleted && k.active && k.items.some(it => it.productId === x.id));
      affected.forEach(k => { k.active = false; }); // a package cannot be rented without one of its items
    }
    Store.save();
    notifyListing(kind, x, 'Listing deleted by admin', x.name + ' was permanently removed from PartyRent by an administrator.' + (affected.length ? ' Packages switched off: ' + affected.map(k => k.name).join(', ') + '.' : ''));
    Store.audit('Deleted listing', kindLabel(kind) + ' ' + x.id + ' · ' + x.name);
    return { ok: true, affectedPackages: affected };
  }
  /** Supplier turns their own listing on/off. Admin-hidden or deleted listings cannot be switched back on. */
  function supplierSetListingActive(supplierId, kind, id, on) {
    const x = listingRef(kind, id);
    if (!x || x.supplierId !== supplierId) return { ok: false, error: 'You can only manage your own listings.' };
    const st = listingStatus(kind, x);
    if (st === 'deleted') return { ok: false, error: 'This listing was deleted by an administrator.' };
    if (st === 'hidden') return { ok: false, error: 'This listing was hidden by a PartyRent administrator and cannot be republished. Reason: ' + (x.hiddenReason || '—') };
    if (kind === 'package') {
      if (on && x.items.some(it => (Store.product(it.productId) || {}).status !== 'published')) return { ok: false, error: 'All included items must be active before this package can be turned on.' };
      x.active = !!on;
    } else x.status = on ? 'published' : 'draft';
    Store.save();
    return { ok: true };
  }

  // Inventory-critical operations run as one locked check-and-write (see Store.transaction).
  const tx = fn => function () { const args = arguments; return Store.transaction(() => fn.apply(null, args)); };
  g.Services = { LISTING, listingStatus, isListed, dependentRequests, adminHideListing: tx(adminHideListing), adminUnhideListing: tx(adminUnhideListing), adminDeleteListing: tx(adminDeleteListing), supplierSetListingActive: tx(supplierSetListingActive), maxDaysFor, durationIssues, durationMessage, STATUS, statusLabel, statusClass, itemName, orgAllowed,
    // rental list & checkout
    cart, cartCount, addToCart: tx(addToCart), updateCartLine, removeCartLine, cartGroups, setCartSelected, selectedCart, setCartTime: tx(setCartTime),
    autoAllocate, prepareOrder, submitOrders: tx(submitOrders), submitRequest: tx(submitRequest), setLineSource: tx(setLineSource), locName,
    proposeInstallmentPlan: tx(proposeInstallmentPlan), reviewInstallmentPlan: tx(reviewInstallmentPlan),
    cancelPolicy, cancelRequest: tx(cancelRequest), acceptReview, acceptRequest: tx(acceptRequest), declineRequest: tx(declineRequest), FLOW, ACTION_LABEL, advance,
    // damage & loss
    CONDITIONS, DAMAGE_CATS, damagePolicy, damageRates, validateDamagePolicy, rentedUnits, assessDamage, recordInspection: tx(recordInspection), markRepaired: tx(markRepaired),
    settleDeposit, completionChecklist, completeRental: tx(completeRental),
    // payments (scoped to the supplier's own Organization Admin / Cashier)
    submitPayment: tx(submitPayment), verifyPayment: tx(verifyPayment), rejectPayment: tx(rejectPayment), cashierRecord: tx(cashierRecord), recordPayment: tx(cashierRecord), scanOverdue, notifyFinance,
    // onboarding & organizations
    APP_STATUS, SUPPLIER_TYPES, COMPANY_TYPES, applicationRequirements, missingDocs, validateApplication, submitApplication: tx(submitApplication), reviewApplication: tx(reviewApplication),
    EMPLOYEE_ROLES, orgMembers, addEmployee: tx(addEmployee), updateEmployee: tx(updateEmployee),
    // catalog, inventory locations & settings
    saveProduct: tx(saveProduct), addHold: tx(addHold), adjustCondition: tx(adjustCondition), savePackage: tx(savePackage), ownerOf,
    MOVE_LABEL, saveLocation: tx(saveLocation), deleteLocation: tx(deleteLocation), transferStock: tx(transferStock), saveShopSettings: tx(saveShopSettings) };
})(window);
