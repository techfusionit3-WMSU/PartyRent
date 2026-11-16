/* =========================================================
   PartyRent — inventory.js
   THE single source of truth for date-, time- and
   location-based availability. Every page (marketplace,
   product page, cart, checkout, supplier inventory, calendar,
   request review, AI) calls these.

   Available(date, time) = Total − Peak reserved during the overlapping time
                           − Damaged − Missing − Written-off
                           − Maintenance(date) − SetAside(date)
   Peak reserved = the HIGHEST combined quantity of accepted bookings that
     overlap the requested time (including the supplier's preparation and
     recovery buffers) — not the sum of every booking on that date. A morning
     booking therefore never reduces afternoon stock unless they overlap.
   Location: every product has one dedicated quantity per inventory location
     (stockByLocation, summing to totalStock). A booking line is assigned to one
     source location and only uses that location's stock.
   Pending requests never consume stock; they are reported as potential conflicts.
   ========================================================= */
(function (g) {
  'use strict';

  const RESERVING = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery', 'ongoing', 'return_pending'];

  /** Expand a request/cart line into { productId: qty } for one date. */
  function unitsForLine(line, date) {
    const q = Number(line.days && line.days[date]) || 0;
    if (!q) return {};
    if (line.type === 'package') {
      const k = Store.pkg(line.refId); if (!k) return {};
      const out = {};
      k.items.forEach(it => { out[it.productId] = (out[it.productId] || 0) + it.qty * q; });
      return out;
    }
    return { [line.refId]: q };
  }
  /** Expand many lines into { date: { productId: qty } } (whole-day totals, used for summaries). */
  function demandMap(lines) {
    const map = {};
    (lines || []).forEach(line => {
      Object.keys(line.days || {}).forEach(date => {
        const u = unitsForLine(line, date);
        Object.entries(u).forEach(([pid, q]) => { map[date] = map[date] || {}; map[date][pid] = (map[date][pid] || 0) + q; });
      });
    });
    return map;
  }

  function supplierOfLine(line) { const it = line.type === 'package' ? Store.pkg(line.refId) : Store.product(line.refId); return it ? it.supplierId : null; }
  function bufFor(line) { const st = Slots.settings(supplierOfLine(line)); return { prep: Math.round((st.prepHours || 0) * 60), rec: Math.round((st.recoveryHours || 0) * 60) }; }
  /** Occupied intervals of a line for ONE product (units), with buffers. */
  function productIntervals(line, pid) {
    return Slots.lineIntervals(line, bufFor(line)).map(iv => Object.assign(iv, { qty: unitsForLine(line, iv.date)[pid] || 0 })).filter(iv => iv.qty > 0);
  }
  /** The occupied window (minutes of the day, may extend past 0/1440 with buffers) of a line on a date. */
  function lineWindow(line, date) {
    const iv = Slots.lineIntervals(line, bufFor(line)).find(x => x.date === date);
    if (!iv) return null;
    const base = Slots.dayNum(date) * Slots.DAY;
    return { from: iv.from - base, to: iv.to - base };
  }
  /** Intervals of existing requests in the given statuses (optionally only one source location). */
  function existingIntervals(pid, statuses, excludeId, locId) {
    const out = [];
    Store.all('requests').forEach(r => {
      if (r.id === excludeId || !statuses.includes(r.status)) return;
      r.lines.forEach(l => {
        if (locId && l.sourceId !== locId) return;
        productIntervals(l, pid).forEach(iv => out.push(Object.assign(iv, { requestId: r.id, status: r.status, sourceId: l.sourceId || null })));
      });
    });
    return out;
  }
  function absWindow(date, win) { const base = Slots.dayNum(date) * Slots.DAY; return win ? [base + win.from, base + win.to] : [base, base + Slots.DAY]; }

  /** Peak demand of one product on a date (within a time window), plus the requests involved. */
  function demandOn(productId, date, statuses, excludeId, win, locId) {
    const [A, B] = absWindow(date, win);
    const ivs = existingIntervals(productId, statuses, excludeId, locId).filter(iv => iv.from < B && iv.to > A);
    const byReq = {};
    ivs.forEach(iv => { const k = iv.requestId; byReq[k] = byReq[k] || { requestId: k, qty: 0, status: iv.status, from: iv.rawFrom, to: iv.rawTo }; byReq[k].qty = Math.max(byReq[k].qty, iv.qty); });
    return { total: Slots.peak(ivs, A, B), refs: Object.values(byReq) };
  }
  const reservedOn = (pid, date, excl, win) => demandOn(pid, date, RESERVING, excl, win).total;
  const pendingOn = (pid, date, excl, win) => demandOn(pid, date, ['pending'], excl, win).total;

  function holdsOn(pid, date) {
    const out = { maintenance: 0, setaside: 0, list: [] };
    Store.all('holds').forEach(h => {
      if (h.productId !== pid) return;
      if (date < h.start) return;
      if (h.end && date > h.end) return;
      out[h.type === 'setaside' ? 'setaside' : 'maintenance'] += h.qty;
      out.list.push(h);
    });
    return out;
  }
  function unusableOn(p, date) { const h = holdsOn(p.id, date); return (p.damaged || 0) + (p.missing || 0) + (p.writtenOff || 0) + h.maintenance + h.setaside; }

  /** Full breakdown for one product on one date (opts.window = time window in minutes of the day). */
  function breakdown(pid, date, opts) {
    opts = opts || {};
    const p = Store.product(pid);
    if (!p) return null;
    const res = demandOn(pid, date, RESERVING, opts.excludeRequestId, opts.window);
    const pen = demandOn(pid, date, ['pending'], opts.excludeRequestId, opts.window);
    const h = holdsOn(pid, date);
    const unusable = (p.damaged || 0) + (p.missing || 0) + (p.writtenOff || 0) + h.maintenance + h.setaside;
    const available = Math.max(0, p.totalStock - res.total - unusable);
    return { date, total: p.totalStock, reserved: res.total, reservedRefs: res.refs, pending: pen.total, pendingRefs: pen.refs, damaged: p.damaged || 0, missing: p.missing || 0, writtenOff: p.writtenOff || 0, maintenance: h.maintenance, setaside: h.setaside, holds: h.list, available };
  }
  const availableOn = (pid, date, opts) => { const b = breakdown(pid, date, opts); return b ? b.available : 0; };

  /* ---------- inventory locations ---------- */
  function locationsOf(supplierId) { const s = Store.supplier(supplierId); return (s && s.locations) || []; }
  const stockAt = (p, locId) => Number(((p && p.stockByLocation) || {})[locId]) || 0;
  /** Available units at one location = min(location stock − peak bookings from that location, product-wide availability). */
  function availableAt(pid, locId, date, opts) {
    opts = opts || {};
    const p = Store.product(pid); if (!p) return 0;
    const locPeak = demandOn(pid, date, RESERVING, opts.excludeRequestId, opts.window, locId).total;
    return Math.max(0, Math.min(stockAt(p, locId) - locPeak, availableOn(pid, date, opts)));
  }
  /** Per-location view of one product on a date. */
  function byLocation(pid, date, opts) {
    const p = Store.product(pid); if (!p) return [];
    return locationsOf(p.supplierId).map(loc => ({ loc, stock: stockAt(p, loc.id), reserved: demandOn(pid, date, RESERVING, opts && opts.excludeRequestId, opts && opts.window, loc.id).total, available: availableAt(pid, loc.id, date, opts) }));
  }

  /** How many whole packages can be rented on a date (limited by the scarcest component). */
  function packageAvailableOn(pkgId, date, opts) {
    const k = Store.pkg(pkgId); if (!k || !k.items.length) return 0;
    return Math.min.apply(null, k.items.map(it => Math.floor(availableOn(it.productId, date, opts) / it.qty)));
  }

  /** Availability of a product over a date range: min over the dates. */
  function minAvailable(pid, dates, opts) { if (!dates.length) return availableOn(pid, U.today(), opts); return Math.min.apply(null, dates.map(d => availableOn(pid, d, opts))); }
  /** Availability of a line (its own dates and time window): min over its dates. */
  function lineAvailable(line, opts) {
    const dates = Object.keys(line.days || {}).filter(d => Number(line.days[d]) > 0).sort();
    if (!dates.length) return 0;
    return Math.min.apply(null, dates.map(d => { const o = Object.assign({}, opts, { window: lineWindow(line, d) }); return line.type === 'package' ? packageAvailableOn(line.refId, d, o) : availableOn(line.refId, d, o); }));
  }

  /**
   * Validate a set of lines (cart group or request) against stock, date by date and time by time.
   * Lines for the same product (including via packages) are combined; overlapping times add up,
   * non-overlapping times share the same units. When lines carry a sourceId, the quantity at that
   * inventory location is checked as well.
   * Returns { ok, rows:[{date, productId, requested, available, ok, locationId?}], bad, byLine }
   */
  function check(lines, opts) {
    opts = opts || {};
    lines = lines || [];
    const cand = {};
    lines.forEach(l => {
      const pids = new Set();
      Object.keys(l.days || {}).forEach(d => Object.keys(unitsForLine(l, d)).forEach(p => pids.add(p)));
      pids.forEach(pid => productIntervals(l, pid).forEach(iv => (cand[pid] = cand[pid] || []).push(Object.assign(iv, { lineId: l.id, sourceId: l.sourceId || null }))));
    });
    const rows = [];
    const over = (list, c) => list.filter(x => x.from < c.to && x.to > c.from);
    Object.entries(cand).forEach(([pid, civs]) => {
      const p = Store.product(pid); if (!p) return;
      const exist = existingIntervals(pid, RESERVING, opts.excludeRequestId);
      const all = exist.concat(civs);
      Array.from(new Set(civs.map(c => c.date))).sort().forEach(date => {
        const mine = civs.filter(c => c.date === date);
        const cap = p.totalStock - unusableOn(p, date);
        let ok = true, req = 0, avail = Infinity;
        mine.forEach(c => {
          if (Slots.peak(over(all, c), c.from, c.to) > cap) ok = false;
          req = Math.max(req, Slots.peak(over(civs, c), c.from, c.to));
          avail = Math.min(avail, Math.max(0, cap - Slots.peak(over(exist, c), c.from, c.to)));
        });
        rows.push({ date, productId: pid, requested: req, available: avail === Infinity ? 0 : avail, ok });
        // location-level check for lines already assigned to an inventory source
        Array.from(new Set(mine.map(c => c.sourceId).filter(Boolean))).forEach(loc => {
          const exL = exist.filter(x => x.sourceId === loc), cL = civs.filter(x => x.sourceId === loc), allL = exL.concat(cL);
          const capL = stockAt(p, loc);
          let okL = true, reqL = 0, avL = Infinity;
          mine.filter(c => c.sourceId === loc).forEach(c => {
            if (Slots.peak(over(allL, c), c.from, c.to) > capL) okL = false;
            reqL = Math.max(reqL, Slots.peak(over(cL, c), c.from, c.to));
            avL = Math.min(avL, Math.max(0, capL - Slots.peak(over(exL, c), c.from, c.to)));
          });
          if (!okL) rows.push({ date, productId: pid, locationId: loc, requested: reqL, available: avL === Infinity ? 0 : avL, ok: false });
        });
      });
    });
    const bad = rows.filter(r => !r.ok);
    const byLine = {};
    lines.forEach(l => {
      byLine[l.id] = {};
      Object.keys(l.days || {}).forEach(date => {
        const pids = Object.keys(unitsForLine(l, date));
        const conflict = pids.some(pid => bad.some(b => b.date === date && b.productId === pid));
        const o = Object.assign({}, opts, { window: lineWindow(l, date) || undefined });
        const avail = l.type === 'package' ? packageAvailableOn(l.refId, date, o) : availableOn(l.refId, date, o);
        byLine[l.id][date] = { ok: !conflict, available: avail, requested: Number(l.days[date]) || 0 };
      });
    });
    return { ok: bad.length === 0, rows, bad, byLine };
  }

  /** Future dates (today or later) on which this product has accepted reservations (optionally from one location). */
  function reservedDates(pid, locId) {
    const set = new Set(); const t = U.today();
    Store.all('requests').forEach(r => {
      if (!RESERVING.includes(r.status)) return;
      r.lines.forEach(l => { if (locId && l.sourceId !== locId) return; Object.keys(l.days).forEach(d => { if (d >= t && unitsForLine(l, d)[pid]) set.add(d); }); });
    });
    return Array.from(set).sort();
  }

  /** Can total stock be changed to newTotal (and damaged/missing to new values) without breaking accepted reservations? */
  function stockChangeConflicts(pid, newTotal, newDamaged, newMissing) {
    const p = Store.product(pid); if (!p) return [];
    const dmg = newDamaged == null ? p.damaged || 0 : newDamaged;
    const mis = newMissing == null ? p.missing || 0 : newMissing;
    return reservedDates(pid).map(d => {
      const h = holdsOn(pid, d);
      const reserved = reservedOn(pid, d);
      const capacity = newTotal - dmg - mis - (p.writtenOff || 0) - h.maintenance - h.setaside;
      return { date: d, reserved, capacity, ok: capacity >= reserved };
    }).filter(x => !x.ok);
  }
  /** Can one location's quantity be changed to newQty without breaking reservations allocated to it? */
  function locationStockConflicts(pid, locId, newQty) {
    return reservedDates(pid, locId).map(d => ({ date: d, reserved: demandOn(pid, d, RESERVING, null, null, locId).total, capacity: newQty })).filter(x => x.reserved > x.capacity).map(x => Object.assign(x, { ok: false }));
  }

  /** Can a new maintenance / set-aside hold be added? */
  function holdConflicts(pid, qty, start, end) {
    const p = Store.product(pid); if (!p) return [];
    const last = end || U.addDays(start, 60);
    return reservedDates(pid).filter(d => d >= start && d <= last).map(d => {
      const h = holdsOn(pid, d);
      const reserved = reservedOn(pid, d);
      const capacity = p.totalStock - (p.damaged || 0) - (p.missing || 0) - (p.writtenOff || 0) - h.maintenance - h.setaside - qty;
      return { date: d, reserved, capacity, ok: capacity >= reserved };
    }).filter(x => !x.ok);
  }

  /** Snapshot for "today": used by inventory tables and dashboards. */
  function todaySnapshot(pid) { return breakdown(pid, U.today()); }

  g.Inventory = { RESERVING, unitsForLine, demandMap, productIntervals, lineWindow, existingIntervals, demandOn, reservedOn, pendingOn, holdsOn, breakdown, availableOn, locationsOf, stockAt, availableAt, byLocation, packageAvailableOn, minAvailable, lineAvailable, check, reservedDates, stockChangeConflicts, locationStockConflicts, holdConflicts, todaySnapshot };
})(window);
