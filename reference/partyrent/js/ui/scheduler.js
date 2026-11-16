/* =========================================================
   PartyRent — scheduler.js
   Multi-day rental scheduler: different quantity per date,
   consecutive or non-consecutive dates, date-by-date
   availability and tiered pricing. Used by the product page,
   package page and the rental list.
   ========================================================= */
(function (g) {
  'use strict';
  const { esc } = U;
  const I = Icons.icon;

  /**
   * line: { id?, type: 'item'|'package', refId, days: {date: qty} }
   * opts: { onChange(line), excludeRequestId, compact }
   * Validation combines this line with `opts.siblings` (other lines of the same supplier)
   * so a package + loose chairs on the same date are checked together.
   */
  function render(el, line, opts) {
    opts = opts || {};
    const item = line.type === 'package' ? Store.pkg(line.refId) : Store.product(line.refId);
    const dates = Object.keys(line.days).sort();
    const lines = (opts.siblings || []).filter(l => l.id !== line.id).concat([Object.assign({ id: line.id || '__self' }, line)]);
    const chk = Inventory.check(lines, { excludeRequestId: opts.excludeRequestId });
    const mine = chk.byLine[line.id || '__self'] || {};
    const unitTxt = line.type === 'package' ? 'package' : (item.unit || 'piece').split('/')[0].trim();
    let total = 0;
    const rows = dates.map(d => {
      const q = Number(line.days[d]) || 0;
      const info = mine[d] || { ok: true, available: 0 };
      const unit = line.type === 'package' ? item.price : Pricing.unitPrice(item, q);
      const sub = U.round2(unit * q); total += sub;
      const past = d < U.today();
      const bad = !info.ok || past;
      const tier = line.type === 'item' && item.tiers.length > 1 ? Pricing.tierFor(item, q) : null;
      return '<div class="day-row ' + (bad ? 'bad' : '') + '" data-date="' + d + '">' +
        '<div class="dl">' + esc(U.fmtDay(d)) + (past ? ' <span class="badge b-red">Past date</span>' : '') + '</div>' +
        '<div data-l="Quantity"><div class="qty ' + (bad ? 'bad' : '') + '"><button type="button" data-dec aria-label="Decrease">−</button><input type="number" min="0" value="' + q + '" data-qty aria-label="Quantity for ' + esc(U.fmtDate(d)) + '"><button type="button" data-inc aria-label="Increase">+</button></div></div>' +
        '<div data-l="Available">' + (info.ok ? '<span class="avail-pill ' + (info.available - q < 5 && line.type === 'item' ? 'low' : 'ok') + '">' + info.available + ' ' + (line.type === 'package' ? 'pkg' : 'avail.') + '</span>' : '<span class="avail-pill none">Only ' + info.available + '</span>') + '</div>' +
        '<div data-l="Daily rate">' + U.money(unit) + '<div class="small muted">/' + esc(unitTxt) + (tier && tier.min > 1 ? ' · tier ' + tier.min + (tier.max ? '–' + tier.max : '+') : '') + '</div></div>' +
        '<div data-l="Subtotal" class="strong">' + U.money(sub) + '</div>' +
        '<div class="rm"><button type="button" class="icon-btn" data-remove aria-label="Remove date" style="width:34px;height:34px">' + I('trash').replace('<svg', '<svg style="width:17px"') + '</button></div></div>';
    }).join('');
    // per-product maximum rental duration (checked across this supplier's lines = one booking)
    const myPids = line.type === 'package' ? (item.items || []).map(it => it.productId) : [line.refId];
    const dur = Services.durationIssues(lines).filter(x => myPids.includes(x.productId));
    const cap = Services.maxDaysFor(line.type, line.refId);
    const okAll = chk.ok && !dates.some(d => d < U.today()) && !dur.length;
    el.innerHTML = (cap ? '<div class="small muted mb-1">' + I('info').replace('<svg', '<svg style="width:14px;vertical-align:-2px"') + ' Max rental duration: <b>' + cap + ' day' + (cap > 1 ? 's' : '') + '</b> per booking (first to last day).</div>' : '') + (dur.length ? '<div class="alert alert-danger mb-1" role="alert">' + I('alert') + '<div>' + dur.map(x => esc(x.message)).join('<br>') + '</div></div>' : '') + '<div class="day-editor"><div class="day-row head"><div>Date</div><div>Requested qty</div><div>Available</div><div>Daily rate</div><div>Daily subtotal</div><div></div></div>' + (rows || '<div class="empty small" style="padding:18px">No dates yet — add a rental date below.</div>') + '</div>' +
      '<div class="row wrap mt-1" style="gap:8px"><input type="date" class="input input-sm" style="width:auto" data-newdate min="' + U.today() + '" aria-label="Add a rental date"><button type="button" class="btn btn-sm" data-adddate>' + I('plus') + 'Add date</button>' +
      '<span class="grow"></span><div class="row" style="gap:6px"><span class="small muted">Same qty for all:</span><input type="number" min="1" class="input input-sm" style="width:80px" data-allqty value="' + (dates.length ? line.days[dates[0]] : 1) + '" aria-label="Quantity for all dates"><button type="button" class="btn btn-sm btn-outline" data-applyall>Apply</button></div></div>' +
      '<div class="row between mt-1"><div class="small">' + (okAll ? '<span style="color:var(--green)">' + I('check').replace('<svg', '<svg style="width:15px;vertical-align:-3px"') + ' All dates available</span>' : '<span style="color:var(--red)">' + I('alert').replace('<svg', '<svg style="width:15px;vertical-align:-3px"') + ' Fix the highlighted dates before requesting</span>') + '</div><div class="strong">Line total: <span class="price">' + U.money(total) + '</span></div></div>';

    const fire = () => { if (opts.onChange) opts.onChange(line, okAll); render(el, line, opts); };
    el.querySelectorAll('.day-row[data-date]').forEach(row => {
      const d = row.dataset.date; const inp = row.querySelector('[data-qty]');
      row.querySelector('[data-dec]').onclick = () => { line.days[d] = Math.max(0, (Number(line.days[d]) || 0) - 1); fire(); };
      row.querySelector('[data-inc]').onclick = () => { line.days[d] = (Number(line.days[d]) || 0) + 1; fire(); };
      inp.onchange = () => { line.days[d] = Math.max(0, Math.floor(Number(inp.value) || 0)); fire(); };
      row.querySelector('[data-remove]').onclick = () => { delete line.days[d]; fire(); };
    });
    el.querySelector('[data-adddate]').onclick = () => {
      const v = el.querySelector('[data-newdate]').value;
      if (!v) return UI.toast('Pick a date to add.', 'warn');
      if (v < U.today()) return UI.toast('You cannot rent for a past date.', 'error');
      if (line.days[v] != null) return UI.toast('That date is already in the schedule.', 'warn');
      if (Object.keys(line.days).length >= Store.settings().maxRentalDays) return UI.toast('Maximum of ' + Store.settings().maxRentalDays + ' rental dates per item.', 'warn');
      if (cap) { const all = Object.keys(line.days).concat([v]).sort(); if (U.diffDays(all[0], all[all.length - 1]) + 1 > cap) return UI.toast(Services.durationMessage(item.name, cap, all[0], all[all.length - 1]), 'error'); }
      line.days[v] = Number(el.querySelector('[data-allqty]').value) || 1; fire();
    };
    el.querySelector('[data-applyall]').onclick = () => { const q = Math.max(1, Math.floor(Number(el.querySelector('[data-allqty]').value) || 1)); Object.keys(line.days).forEach(d => { line.days[d] = q; }); fire(); };
    return { ok: okAll, total };
  }

  /** Mini month calendar showing availability for a product/package. */
  function calendar(el, kind, id, month, opts) {
    opts = opts || {};
    const first = U.parse(month + '-01');
    const y = first.getFullYear(), m = first.getMonth();
    const startPad = first.getDay(); const n = new Date(y, m + 1, 0).getDate();
    const need = opts.need || 1; const sel = opts.selected || [];
    let cells = U.DOW.map(d => '<div class="dow">' + d[0] + '</div>').join('');
    for (let i = 0; i < startPad; i++) cells += '<div class="d blank"></div>';
    for (let d = 1; d <= n; d++) {
      const ds = y + '-' + U.pad(m + 1) + '-' + U.pad(d);
      const a = kind === 'package' ? Inventory.packageAvailableOn(id, ds) : Inventory.availableOn(id, ds);
      const cls = ds < U.today() ? 'past' : a <= 0 ? 'none' : a < need ? 'low' : 'ok';
      cells += '<div class="d ' + cls + (sel.includes(ds) ? ' sel' : '') + (ds === U.today() ? ' today' : '') + '" title="' + U.fmtDate(ds) + ': ' + a + ' available"' + (ds >= U.today() ? ' data-pick="' + ds + '" style="cursor:pointer"' : '') + '><span class="n">' + d + '</span><span class="a">' + (ds < U.today() ? '' : a) + '</span></div>';
    }
    const prev = U.iso(new Date(y, m - 1, 1)).slice(0, 7), next = U.iso(new Date(y, m + 1, 1)).slice(0, 7);
    el.innerHTML = '<div class="row between mb-1"><button class="btn btn-sm btn-ghost btn-icon" data-m="' + prev + '" aria-label="Previous month"' + (prev < U.today().slice(0, 7) ? ' disabled' : '') + '>' + I('left') + '</button><b>' + U.MONTHS[m] + ' ' + y + '</b><button class="btn btn-sm btn-ghost btn-icon" data-m="' + next + '" aria-label="Next month">' + I('right') + '</button></div><div class="cal">' + cells + '</div>' +
      '<div class="legend mt-1"><span><i style="background:var(--green-50);border:1px solid #BCE6CB"></i>Enough for ' + need + '</span><span><i style="background:var(--gold-50);border:1px solid #FBDDA4"></i>Fewer than ' + need + '</span><span><i style="background:var(--red-50);border:1px solid #F8C4C4"></i>Fully booked</span></div>';
    el.querySelectorAll('[data-m]').forEach(b => b.onclick = () => calendar(el, kind, id, b.dataset.m, opts));
    el.querySelectorAll('[data-pick]').forEach(c => c.onclick = () => opts.onPick && opts.onPick(c.dataset.pick));
  }

  g.Scheduler = { render, calendar };
})(window);
