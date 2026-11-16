/* =========================================================
   PartyRent — customer rental pages
   Rental List (multi-day scheduler), checkout, confirmation,
   My Rentals, rental details & payments, payment history,
   profile, Start Selling onboarding wizard.
   ========================================================= */
(function () {
  'use strict';
  const { esc, url, $, $$ } = U;
  const I = Icons.icon;
  const C = Pages.customer = Pages.customer || {};
  const S = () => Pages.shared;

  /* ================= RENTAL LIST (CART) =================
     Products from several shops in one list, grouped by shop. Customers tick the items they
     want to check out now (per item, per shop, or Select All); unticked items stay in the list.
     Each shop keeps its own order, Rental Charges and payment plan. */
  const firstPlan = sup => { const pl = Finance.plansOf(sup); return pl.includes('standard') ? 'standard' : pl[0] || 'full'; };
  const estimateQuote = (sid, lines) => { const sup = Store.supplier(sid); const del = sup.fulfillment !== 'pickup'; return Pricing.quote(lines, sid, del ? 'delivery' : 'pickup', firstPlan(sup), { destinations: del ? [{ id: 'd1', label: 'Delivery (standard rate)', zoneId: '' }] : [] }); };
  C.cart = function (ctx) {
    const uid = ctx.session.user.id;
    function draw() {
      const all = Services.cart(uid);
      const groups = Services.cartGroups(uid);
      const sids = Object.keys(groups);
      if (!sids.length) { ctx.el.innerHTML = '<div class="page-head"><div><h1>Rental List</h1></div></div><div class="card">' + UI.empty('🛒', 'Your rental list is empty', 'Add items or packages, then set the dates, times and quantities for each day.', '<div class="row wrap" style="justify-content:center"><a class="btn btn-primary" href="' + url('customer/browse.html') + '">Browse supplies</a><a class="btn" href="' + url('customer/ai.html') + '">' + I('sparkle') + 'Plan with AI</a></div>') + '</div>'; return; }
      const sel = all.filter(l => l.selected !== false);
      const selSids = sids.filter(sid => groups[sid].some(l => l.selected !== false));
      let blocked = false; let grand = 0;
      const groupHtml = sids.map(sid => {
        const sup = Store.supplier(sid); const lines = groups[sid];
        const chosen = lines.filter(l => l.selected !== false);
        const chk = Inventory.check(lines);
        const dur = Services.durationIssues(lines);
        const tIss = Slots.timeIssues(lines);
        const chosenBad = chosen.length && (!Inventory.check(chosen).ok || Services.durationIssues(chosen).length || Slots.timeIssues(chosen).length);
        if (chosenBad) blocked = true;
        const q = chosen.length ? estimateQuote(sid, chosen) : null;
        if (q) grand += q.total;
        const allOn = chosen.length === lines.length, someOn = chosen.length > 0;
        return '<div class="card pad-0 cart-group" data-group="' + sid + '"><div class="cg-head"><div class="row" style="min-width:0"><input type="checkbox" class="sel-box" data-selshop="' + sid + '"' + (allOn ? ' checked' : '') + (someOn && !allOn ? ' data-indet="1"' : '') + ' aria-label="Select all items from ' + esc(sup.name) + '"><div class="logo-tile" style="width:42px;height:42px;font-size:1.3rem;background:' + sup.color + '1a">' + esc(sup.logo) + '</div><div style="min-width:0"><a class="strong" href="' + url('customer/supplier.html', { id: sid }) + '">' + esc(sup.name) + '</a><div class="small muted">' + esc(sup.city) + ' · ' + UI.fulfillLabel(sup.fulfillment) + ' · ' + Finance.plansOf(sup).map(k => Finance.PLAN_LABEL[k].replace(' Payment Plan', '')).join(' / ') + '</div></div></div><div class="row"><div class="right"><div class="small muted">' + chosen.length + ' of ' + lines.length + ' selected</div><b class="price">' + (q ? U.money(q.total) : '—') + '</b></div><button class="btn btn-sm" data-checkout="' + sid + '"' + (someOn && !chosenBad ? '' : ' disabled') + '>Check out this shop</button></div></div>' +
          (chk.ok ? '' : '<div class="alert alert-danger" style="margin:12px 18px 0">' + I('alert') + '<div>Some dates exceed the stock available for the selected time. Lower the highlighted quantities, change the time or remove those dates.</div></div>') +
          (dur.length ? '<div class="alert alert-danger" style="margin:12px 18px 0">' + I('alert') + '<div>' + dur.map(x => esc(x.message)).join('<br>') + '</div></div>' : '') +
          (tIss.length ? '<div class="alert alert-danger" style="margin:12px 18px 0">' + I('alert') + '<div>' + tIss.map(esc).join('<br>') + '</div></div>' : '') +
          lines.map(l => {
            const item = l.type === 'package' ? Store.pkg(l.refId) : Store.product(l.refId);
            if (!item) return '';
            const dates = Object.keys(l.days).sort();
            const t = l.time || Slots.defaultTime(sid);
            return '<div class="cart-line" data-line="' + l.id + '"><div class="cl-top"><input type="checkbox" class="sel-box" data-selline="' + l.id + '"' + (l.selected !== false ? ' checked' : '') + ' aria-label="Select ' + esc(item.name) + '">' + UI.thumb(item, l.type === 'package' ? 'package' : null) + '<div class="grow" style="min-width:0"><div class="row between top"><div style="min-width:0"><a class="strong" href="' + url(l.type === 'package' ? 'customer/package.html' : 'customer/product.html', { id: item.id }) + '">' + esc(item.name) + '</a>' + (l.type === 'package' ? ' <span class="badge b-pink plain">Package</span>' : '') + '<div class="small muted">' + (dates.length ? dates.length + ' date' + (dates.length > 1 ? 's' : '') + ' · ' + U.fmtRange(dates[0], dates[dates.length - 1]) : 'No dates') + (l.type === 'item' && item.tiers.length > 1 ? ' · tiered pricing applies per day' : '') + '</div>' + (S().outsidePeriod(l.days) ? '<div class="small" style="color:#B45309">' + I('info').replace('<svg', '<svg style="width:13px;vertical-align:-2px"') + ' These dates differ from your selected rental period.</div>' : '') + '</div><button class="btn btn-xs btn-ghost" data-rm="' + l.id + '">' + I('trash') + 'Remove</button></div>' +
              '<div class="row wrap mt-1" style="gap:8px;align-items:flex-end"><div class="time-pair" style="max-width:340px;flex:1 1 260px"><label class="small">Start time' + Slots.select('ts', t.start, sid, 'data-time="' + l.id + '" aria-label="Start time"') + '</label><label class="small">End time' + Slots.select('te', t.end, sid, 'data-time="' + l.id + '" aria-label="End time"') + '</label></div><div class="small"><span class="badge b-purple plain">' + I('clock').replace('<svg', '<svg style="width:13px;height:13px"') + ' ' + esc(l.time ? Slots.duration(l).label : 'All day') + '</span>' + (Slots.hourLimitLabel(item) ? '<div class="muted">' + esc(Slots.hourLimitLabel(item)) + '</div>' : '') + '</div></div></div></div><div class="mt-1" data-sched="' + l.id + '"></div></div>';
          }).join('') + (q ? '<div style="padding:0 18px 14px">' + UI.rentalCharges(q, { estimate: true, note: sup.fulfillment !== 'pickup' ? 'Delivery is shown at the standard rate. Choose your delivery zone (and add more delivery locations) at checkout.' : 'Pickup at the shop — no delivery fee.' }) + '</div>' : '') + '</div>';
      }).join('');
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Rental List</h1><p>Items from different shops stay grouped by shop. Tick what you want to check out now — unticked items stay in your list.</p></div><div class="row">' + (sids.length > 1 ? '<button class="btn btn-sm" id="cmp">' + I('layers') + 'Compare suppliers</button>' : '') + '<a class="btn btn-sm btn-ghost" href="' + url('customer/browse.html') + '">' + I('plus') + 'Add more items</a></div></div>' +
        S().periodBanner() + '<div class="card row wrap between mb-2" style="padding:12px 16px"><label class="check mb-0"><input type="checkbox" class="sel-box" id="selAll"' + (sel.length === all.length ? ' checked' : '') + (sel.length && sel.length < all.length ? ' data-indet="1"' : '') + '> <b>Select all</b> <span class="small muted">(' + all.length + ' item' + (all.length > 1 ? 's' : '') + ' from ' + sids.length + ' shop' + (sids.length > 1 ? 's' : '') + ')</span></label><span class="small muted">' + sel.length + ' selected</span></div>' +
        '<div class="grid grid-side"><div>' + groupHtml + '</div><aside class="card sticky-side"><h3>Selected for checkout</h3>' + (selSids.length ? selSids.map(sid => { const q = estimateQuote(sid, groups[sid].filter(l => l.selected !== false)); return '<div class="sum-line"><span>' + esc(Store.supplier(sid).name) + '<div class="small muted">' + groups[sid].filter(l => l.selected !== false).length + ' item(s) · own order & payment plan</div></span><span>' + U.money(q.total) + '</span></div>'; }).join('') + '<div class="sum-line total"><span>Combined total (' + selSids.length + ' shop' + (selSids.length > 1 ? 's' : '') + ')</span><span class="price">' + U.money(grand) + '</span></div>' : '<p class="muted small">No items selected yet.</p>') +
        '<button class="btn btn-primary btn-block btn-lg mt-1" id="goCheckout"' + (sel.length && !blocked ? '' : ' disabled') + '>Proceed to checkout' + (sel.length ? ' (' + sel.length + ')' : '') + '</button>' + (blocked ? '<p class="small mt-1 mb-0" style="color:var(--red)">Fix the highlighted issues in the selected items first.</p>' : '') +
        '<p class="small muted mt-1">Each shop receives its own rental request with its own payment plan, schedule and total. Payment percentages are always based on that shop\'s order total, not the combined total.</p><div class="alert alert-info">' + I('info') + '<div class="small">Items in your list are <b>not reserved</b>. Stock is only reserved when a supplier accepts your request.</div></div></aside></div>';
      $$('[data-indet]').forEach(x => { x.indeterminate = true; });
      sids.forEach(sid => groups[sid].forEach(l => {
        const el = ctx.el.querySelector('[data-sched="' + l.id + '"]'); if (!el) return;
        Scheduler.render(el, l, { siblings: groups[sid], onChange: () => { Store.save(); clearTimeout(draw._t); draw._t = setTimeout(draw, 10); } });
      }));
      $('#selAll').onchange = e => { Services.setCartSelected(uid, all.map(l => l.id), e.target.checked); draw(); };
      $$('[data-selshop]').forEach(b => b.onchange = () => { Services.setCartSelected(uid, groups[b.dataset.selshop].map(l => l.id), b.checked); draw(); });
      $$('[data-selline]').forEach(b => b.onchange = () => { Services.setCartSelected(uid, [b.dataset.selline], b.checked); draw(); });
      $$('[data-time]').forEach(sel => sel.onchange = () => {
        const id = sel.dataset.time; const box = ctx.el.querySelector('[data-line="' + id + '"]');
        const res = Services.setCartTime(uid, id, { start: box.querySelector('[name=ts]').value, end: box.querySelector('[name=te]').value });
        if (!res.ok) UI.toast(res.error, 'error'); else UI.toast('Rental time updated.', 'success');
        draw();
      });
      $$('[data-rm]').forEach(b => b.onclick = async () => { if (await UI.confirm({ title: 'Remove item?', message: 'Remove this item and its schedule from your rental list?', confirmText: 'Remove', danger: true })) { Services.removeCartLine(uid, b.dataset.rm); App.refreshCart(); draw(); } });
      $$('[data-checkout]').forEach(b => b.onclick = () => U.go('customer/checkout.html', { supplier: b.dataset.checkout }));
      $('#goCheckout').onclick = () => U.go('customer/checkout.html');
      const cmp = $('#cmp'); if (cmp) cmp.onclick = () => {
        const rows = sids.map(sid => { const sup = Store.supplier(sid); const q = estimateQuote(sid, groups[sid]); return { sup, q, ok: Inventory.check(groups[sid]).ok }; });
        UI.modal({ title: 'Compare suppliers in your list', size: 'lg', body: UI.table([{ label: 'Supplier', render: r => '<b>' + esc(r.sup.name) + '</b><div class="small muted">' + esc(r.sup.city) + ' · ★ ' + r.sup.rating + '</div>' }, { label: 'Rental charges', cls: 'num', render: r => U.money(r.q.total) }, { label: 'Delivery', render: r => UI.fulfillLabel(r.sup.fulfillment) + (r.sup.fulfillment !== 'pickup' ? '<div class="small muted">from ' + U.money(Math.min.apply(null, [r.sup.deliveryFee].concat((r.sup.deliveryZones || []).map(z => z.fee)))) + '</div>' : '') }, { label: 'Payment plans', render: r => Finance.plansOf(r.sup).map(a => Finance.PLAN_LABEL[a]).join('<br>') }, { label: 'Deposit', cls: 'num', render: r => U.money(r.q.deposit) }, { label: 'Availability', render: r => r.ok ? '<span class="badge b-green">All dates OK</span>' : '<span class="badge b-red">Conflicts</span>' }], rows, { noCards: false }) });
      };
    }
    draw();
  };

  /* ================= CHECKOUT =================
     Selected items are separated into one order per supplier. Each order has its own event date,
     delivery locations (several venues are possible), rental times, services, payment plan,
     payment schedule and Rental Charges. Everything is re-validated by Services.submitOrders. */
  C.checkout = function (ctx) {
    const s = ctx.session; const u = s.user;
    const only = ctx.params.supplier;
    const chosen = Services.selectedCart(u.id).filter(l => !only || l.supplierId === only);
    const sids = Array.from(new Set(chosen.map(l => l.supplierId)));
    if (!sids.length) { ctx.el.innerHTML = UI.empty('🧾', 'Nothing to check out', 'Select items in your Rental List first.', '<a class="btn btn-primary" href="' + url('customer/cart.html') + '">Go to Rental List</a>'); return; }
    if (sids.includes(u.supplierId)) { ctx.el.innerHTML = '<div class="alert alert-warn">' + I('alert') + '<div>You cannot rent from your own shop. Untick or remove those items in your Rental List.</div></div>'; return; }
    const shared = { eventName: '', eventType: '', contactName: u.name, contactPhone: u.phone, instructions: '' };
    const st = {};
    sids.forEach(sid => {
      const sup = Store.supplier(sid); const lines = chosen.filter(l => l.supplierId === sid);
      const dates = lines.flatMap(l => Object.keys(l.days)).sort();
      st[sid] = { sup, cartIds: lines.map(l => l.id), first: dates[0], last: dates[dates.length - 1], eventDate: dates[0],
        fulfillment: sup.fulfillment === 'pickup' ? 'pickup' : 'delivery', plan: firstPlan(sup), method: sup.methods[0], services: [],
        destinations: [{ id: 'd1', label: 'Event venue', address: u.address || '', zoneId: '', deliveryTime: Slots.settings(sid).open, contactName: '', contactPhone: '' }],
        lines: lines.map((l, i) => ({ key: l.id, type: l.type, refId: l.refId, days: Object.assign({}, l.days), time: l.time ? Object.assign({}, l.time) : Slots.defaultTime(sid), destId: 'd1' })),
        inst: { n: Math.min(3, Finance.planConfig(sup).installment.maxInstallments), items: [], auto: true } };
    });
    const orderOf = sid => { const o = st[sid]; return Object.assign({}, shared, { supplierId: sid, cartLineIds: o.cartIds, lines: o.lines, eventDate: o.eventDate, fulfillment: o.fulfillment, destinations: o.fulfillment === 'delivery' ? o.destinations : [], plan: o.plan, method: o.method, services: o.services, installmentPlan: o.plan === 'installment' ? { items: o.inst.items } : null }); };
    const quoteOf = sid => { const o = st[sid]; return Pricing.quoteOrder({ supplierId: sid, lines: o.lines, fulfillment: o.fulfillment, destinations: o.fulfillment === 'delivery' ? o.destinations : [], plan: o.plan, services: o.services }); };
    const itemOf = l => l.type === 'package' ? Store.pkg(l.refId) : Store.product(l.refId);
    const qtyTxt = l => { const v = Object.values(l.days).map(Number); const mn = Math.min.apply(null, v), mx = Math.max.apply(null, v); return (mn === mx ? mx : mn + '–' + mx) + '× · ' + Object.keys(l.days).length + ' day' + (Object.keys(l.days).length > 1 ? 's' : ''); };
    function resuggest(sid) { const o = st[sid]; if (o.plan !== 'installment' || !o.inst.auto) return; const q = quoteOf(sid); o.inst.items = Finance.suggestInstallments(q.finalBalance, o.inst.n, U.addDays(U.today(), 1), o.eventDate); }

    function shopCard(sid, idx) {
      const o = st[sid]; const sup = o.sup; const cfg = Finance.planConfig(sup);
      const zoneOpts = (sel) => (sup.deliveryZones || []).map(z => '<option value="' + z.id + '"' + (z.id === sel ? ' selected' : '') + '>' + esc(z.name) + ' — ' + U.money(z.fee) + '</option>').join('') + '<option value=""' + (!sel ? ' selected' : '') + '>Other areas (standard rate) — ' + U.money(sup.deliveryFee || 0) + '</option>';
      const elig = Finance.installmentEligibility(sup, o.eventDate);
      const plans = Finance.plansOf(sup);
      return '<div class="card" data-shop="' + sid + '"><div class="row between mb-1"><div class="row" style="min-width:0"><div class="logo-tile" style="width:42px;height:42px;font-size:1.3rem;background:' + sup.color + '1a">' + esc(sup.logo) + '</div><div style="min-width:0"><div class="label">Order ' + (idx + 1) + ' of ' + sids.length + '</div><h3 class="mb-0">' + esc(sup.name) + '</h3><div class="small muted">' + U.fmtRange(o.first, o.last) + ' · ' + o.lines.length + ' line(s)</div></div></div></div>' +
        '<div class="form-grid"><div class="field"><label>Event date</label><input class="input" type="date" data-k="eventDate" min="' + o.first + '" max="' + o.last + '" value="' + o.eventDate + '"><div class="hint">All payments to this shop are due on or before this date.</div></div><div class="field"><label>Payment method</label><select class="input" data-k="method">' + sup.methods.map(m => '<option value="' + m + '"' + (m === o.method ? ' selected' : '') + '>' + Finance.METHOD_LABEL[m] + '</option>').join('') + '</select></div></div>' +
        // fulfillment & locations
        '<div class="label mt-1 mb-1">Delivery or pickup</div><div class="row wrap gap-sm mb-2">' + ['delivery', 'pickup'].map(f => { const ok = sup.fulfillment === 'both' || sup.fulfillment === f; return '<label class="chip ' + (o.fulfillment === f ? 'active' : '') + '" style="' + (ok ? '' : 'opacity:.45;pointer-events:none') + '"><input type="radio" name="ful_' + sid + '" value="' + f + '" data-ful hidden' + (o.fulfillment === f ? ' checked' : '') + (ok ? '' : ' disabled') + '>' + I(f === 'delivery' ? 'truck' : 'store').replace('<svg', '<svg style="width:16px"') + (f === 'delivery' ? 'Delivery' : 'Pickup at shop') + '</label>'; }).join('') + '</div>' +
        (o.fulfillment === 'delivery' ? '<div class="stack-sm">' + o.destinations.map((d, i) => '<div class="dest-card" data-dest="' + d.id + '"><div class="row between mb-1"><b>' + I('map').replace('<svg', '<svg style="width:15px;vertical-align:-2px"') + ' Location ' + (i + 1) + '</b>' + (o.destinations.length > 1 ? '<button type="button" class="btn btn-xs btn-ghost" data-rmdest="' + d.id + '">' + I('trash') + 'Remove</button>' : '') + '</div><div class="form-grid"><div class="field"><label>Location name / event</label><input class="input" data-d="label" value="' + esc(d.label) + '" placeholder="e.g. Church – Wedding Ceremony"></div><div class="field"><label>Delivery zone</label><select class="input" data-d="zoneId">' + zoneOpts(d.zoneId) + '</select></div><div class="field full"><label>Complete address</label><input class="input" data-d="address" value="' + esc(d.address) + '" placeholder="House no., street, barangay, city"></div><div class="field"><label>Deliver by</label>' + Slots.select('dt', d.deliveryTime || Slots.settings(sid).open, sid, 'data-d="deliveryTime"') + '</div><div class="field"><label>On-site contact (optional)</label><input class="input" data-d="contactName" value="' + esc(d.contactName) + '" placeholder="Name · number"></div></div></div>').join('') + '</div><button type="button" class="btn btn-sm mt-1" data-adddest>' + I('plus') + 'Add another delivery location</button><p class="small muted mt-1 mb-0">Renting for several venues on the same day (e.g. church and reception)? Add a location for each and assign items or split quantities below. Each location has its own delivery fee.</p>'
          : '<div class="alert alert-info">' + I('map') + '<div>Pickup at <b>' + esc(sup.address) + '</b> · ' + esc(sup.hours) + '</div></div>') +
        // items, times & assignment
        '<div class="label mt-2 mb-1">Items, rental times' + (o.fulfillment === 'delivery' && o.destinations.length > 1 ? ' & locations' : '') + '</div>' + o.lines.map((l, i) => { const it = itemOf(l); return '<div class="dest-line" data-li="' + i + '"><div style="min-width:0"><b>' + esc(it.name) + '</b> <span class="small muted">' + esc(qtyTxt(l)) + '</span><div class="time-pair mt-1" style="max-width:360px">' + Slots.select('ls', l.time.start, sid, 'data-lt="start" aria-label="Start time"') + Slots.select('le', l.time.end, sid, 'data-lt="end" aria-label="End time"') + '</div><div class="small muted mt-1">' + esc(Slots.duration(l).label) + (Slots.hourLimitLabel(it) ? ' · ' + esc(Slots.hourLimitLabel(it)) : '') + '</div></div><div class="stack-sm" style="align-items:flex-end">' + (o.fulfillment === 'delivery' && o.destinations.length > 1 ? '<select class="input input-sm" data-ldest aria-label="Delivery location">' + o.destinations.map((d, k) => '<option value="' + d.id + '"' + (d.id === l.destId ? ' selected' : '') + '>Location ' + (k + 1) + ': ' + esc(d.label) + '</option>').join('') + '</select><button type="button" class="btn btn-xs" data-split="' + i + '">Split quantity</button>' : '') + '</div></div>'; }).join('') +
        // services
        ((sup.services || []).length ? '<div class="label mt-2 mb-1">Services</div>' + sup.services.map(x => '<label class="check"><input type="checkbox" data-svc="' + x.id + '"' + (x.required || o.services.includes(x.id) ? ' checked' : '') + (x.required ? ' disabled' : '') + '> <b>' + esc(x.name) + '</b> — ' + U.money(x.amount) + (x.per === 'location' ? ' per location' : '') + (x.required ? ' <span class="badge b-gray plain">included</span>' : '') + '<div class="small muted">' + esc(x.description) + '</div></label>').join('') : '') +
        // payment plan
        '<div class="label mt-2 mb-1">Payment plan</div><div class="stack-sm">' + plans.map(k => { const dis = k === 'installment' && !elig.ok; return '<label class="card card-flat row" style="padding:12px 14px;cursor:pointer;' + (dis ? 'opacity:.55' : '') + '"><input type="radio" name="plan_' + sid + '" value="' + k + '" data-plan' + (k === o.plan ? ' checked' : '') + (dis ? ' disabled' : '') + ' style="accent-color:var(--brand);width:18px;height:18px"><div class="grow"><b>' + Finance.PLAN_LABEL[k] + '</b><div class="small muted">' + esc(Finance.planSummary(sup, k)) + '</div>' + (dis ? '<div class="small" style="color:var(--red)">' + esc(elig.message) + '</div>' : '') + '</div></label>'; }).join('') + '</div>' +
        (o.plan === 'installment' ? '<div class="card card-flat soft mt-1" style="padding:14px" data-inst><div class="row between wrap"><b>Propose your installment schedule</b><label class="small row" style="gap:6px">Installments <select class="input input-sm" data-instn style="width:auto">' + Array.from({ length: cfg.installment.maxInstallments }, (_, i) => '<option' + (i + 1 === o.inst.n ? ' selected' : '') + '>' + (i + 1) + '</option>').join('') + '</select></label></div><p class="small muted">Divide the installment balance into payments of your choice. Every payment must be due on or before the event date. The supplier reviews and approves the schedule.</p><div data-instrows></div><div class="row between mt-1"><button type="button" class="btn btn-xs" data-even>Divide evenly</button><span class="small" data-instsum></span></div></div>' : '') +
        '<div class="mt-2" data-review></div></div>';
    }

    function draw() {
      ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('customer/cart.html') + '">Rental List</a> / Checkout</div><div class="page-head"><div><h1>Checkout</h1><p>' + chosen.length + ' selected item(s) from <b>' + sids.length + ' shop' + (sids.length > 1 ? 's' : '') + '</b>. Each shop gets its own rental order, payment plan and payment schedule.</p></div></div>' +
        S().periodBanner() + '<form id="cf" novalidate class="grid grid-side"><div class="stack">' +
        '<div class="card"><h3>Event & contact details</h3><div class="form-grid"><div class="field full"><label>Event name</label><input class="input" data-s="eventName" required minlength="3" value="' + esc(shared.eventName) + '" placeholder="e.g. Sofia\'s 7th Birthday"><div class="error-text">Enter the event name (at least 3 characters).</div></div><div class="field"><label>Event type</label><select class="input" data-s="eventType" required><option value="">Select…</option>' + Object.keys(AI.EVENTS).concat(['Community', 'Other']).map(e => '<option' + (e === shared.eventType ? ' selected' : '') + '>' + e + '</option>').join('') + '</select><div class="error-text">Choose the event type.</div></div><div class="field"><label>Contact person</label><input class="input" data-s="contactName" required value="' + esc(shared.contactName) + '"></div><div class="field"><label>Contact number</label><input class="input" data-s="contactPhone" data-phone required value="' + esc(shared.contactPhone) + '"></div><div class="field full"><label>Special instructions (optional)</label><textarea class="input" data-s="instructions" rows="2" placeholder="Setup location, gate codes…">' + esc(shared.instructions) + '</textarea></div></div></div>' +
        sids.map(shopCard).join('') + '</div>' +
        '<aside class="card sticky-side"><h3>Order summary</h3><div id="sum"></div><label class="check mt-2"><input type="checkbox" id="agree"> I agree to each shop\'s cancellation, return and rental policies.</label><button type="button" class="btn btn-primary btn-lg btn-block mt-1" id="submitBtn">Submit ' + (sids.length > 1 ? sids.length + ' rental requests' : 'rental request') + '</button><p class="small muted mt-1 mb-0">Requests stay <b>Pending</b> until each supplier reviews them. Payment deadlines start when a supplier accepts. Unselected items stay in your Rental List.</p></aside></form>';
      bind(); sids.forEach(refresh); summary();
    }
    function bind() {
      $$('[data-s]').forEach(el => el.oninput = el.onchange = () => { shared[el.dataset.s] = el.value; sids.forEach(refresh); summary(); });
      sids.forEach(sid => {
        const box = ctx.el.querySelector('[data-shop="' + sid + '"]'); const o = st[sid];
        box.querySelectorAll('[data-k]').forEach(el => el.onchange = () => { o[el.dataset.k] = el.value; if (el.dataset.k === 'eventDate') { if (o.plan === 'installment' && !Finance.installmentEligibility(o.sup, o.eventDate).ok) o.plan = firstPlan(o.sup); resuggest(sid); draw(); return; } refresh(sid); summary(); });
        box.querySelectorAll('[data-ful]').forEach(el => el.onchange = () => { o.fulfillment = el.value; resuggest(sid); draw(); });
        box.querySelectorAll('[data-dest]').forEach(card => card.querySelectorAll('[data-d]').forEach(el => el.oninput = el.onchange = () => { const d = o.destinations.find(x => x.id === card.dataset.dest); d[el.dataset.d] = el.value; if (el.dataset.d === 'zoneId') resuggest(sid); refresh(sid); summary(); }));
        const add = box.querySelector('[data-adddest]'); if (add) add.onclick = () => { let n = o.destinations.length + 1; while (o.destinations.some(d => d.id === 'd' + n)) n++; o.destinations.push({ id: 'd' + n, label: 'Location ' + (o.destinations.length + 1), address: '', zoneId: '', deliveryTime: Slots.settings(sid).open, contactName: '', contactPhone: '' }); resuggest(sid); draw(); };
        box.querySelectorAll('[data-rmdest]').forEach(b => b.onclick = () => { const id = b.dataset.rmdest; const keep = o.destinations.find(d => d.id !== id); o.lines.forEach(l => { if (l.destId === id) l.destId = keep.id; }); o.destinations = o.destinations.filter(d => d.id !== id); mergeSame(sid); resuggest(sid); draw(); });
        box.querySelectorAll('[data-li]').forEach(row => {
          const l = o.lines[+row.dataset.li];
          row.querySelectorAll('[data-lt]').forEach(sel => sel.onchange = () => { l.time[sel.dataset.lt] = sel.value; draw(); });
          const ds = row.querySelector('[data-ldest]'); if (ds) ds.onchange = () => { l.destId = ds.value; mergeSame(sid); draw(); };
          const sp = row.querySelector('[data-split]'); if (sp) sp.onclick = () => splitLine(sid, +sp.dataset.split);
        });
        box.querySelectorAll('[data-svc]').forEach(c => c.onchange = () => { o.services = Array.from(box.querySelectorAll('[data-svc]:checked:not([disabled])')).map(x => x.dataset.svc); resuggest(sid); refresh(sid); summary(); });
        box.querySelectorAll('[data-plan]').forEach(r => r.onchange = () => { o.plan = r.value; o.inst.auto = true; resuggest(sid); draw(); });
        const nsel = box.querySelector('[data-instn]'); if (nsel) nsel.onchange = () => { o.inst.n = +nsel.value; o.inst.auto = true; resuggest(sid); draw(); };
        const ev = box.querySelector('[data-even]'); if (ev) ev.onclick = () => { o.inst.auto = true; resuggest(sid); refresh(sid); summary(); };
      });
      $('#submitBtn').onclick = submit;
    }
    /** Lines of the same item, time and location are combined again. */
    function mergeSame(sid) {
      const o = st[sid]; const out = [];
      o.lines.forEach(l => { const m = out.find(x => x.type === l.type && x.refId === l.refId && x.destId === l.destId && x.time.start === l.time.start && x.time.end === l.time.end && x.key === l.key); if (m) Object.entries(l.days).forEach(([d, q]) => { m.days[d] = (Number(m.days[d]) || 0) + Number(q); }); else out.push(l); });
      o.lines = out;
    }
    function splitLine(sid, i) {
      const o = st[sid]; const l = o.lines[i]; const it = itemOf(l);
      const max = Math.max.apply(null, Object.values(l.days).map(Number));
      const m = UI.modal({ title: 'Split ' + it.name, body: '<p class="small muted">Move part of the quantity to another delivery location (for example 20 sets to the church and 20 to the reception). The total stays the same — nothing is charged twice.</p><div class="form-grid"><div class="field"><label>Quantity to move (per day)</label><input class="input" type="number" min="1" max="' + (max - 1) + '" id="spq" value="' + Math.max(1, Math.floor(max / 2)) + '"></div><div class="field"><label>Move to</label><select class="input" id="spd">' + o.destinations.filter(d => d.id !== l.destId).map(d => '<option value="' + d.id + '">' + esc(d.label) + '</option>').join('') + '</select></div><div class="field"><label>Start time there</label>' + Slots.select('sps', l.time.start, sid, 'id="sps"') + '</div><div class="field"><label>End time there</label>' + Slots.select('spe', l.time.end, sid, 'id="spe"') + '</div></div>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="spok">Split</button>' });
      m.el.querySelector('#spok').onclick = () => {
        const n = Math.floor(+m.el.querySelector('#spq').value || 0);
        if (!(n >= 1 && n < max)) return UI.toast('Enter a quantity between 1 and ' + (max - 1) + '.', 'error');
        const moved = {}; Object.keys(l.days).forEach(d => { const q = Math.min(Number(l.days[d]), n); if (q) { moved[d] = q; l.days[d] = Number(l.days[d]) - q; if (!l.days[d]) delete l.days[d]; } });
        o.lines.splice(i + 1, 0, { key: l.key, type: l.type, refId: l.refId, days: moved, time: { start: m.el.querySelector('#sps').value, end: m.el.querySelector('#spe').value }, destId: m.el.querySelector('#spd').value });
        if (!Object.keys(l.days).length) o.lines.splice(i, 1);
        m.close(); mergeSame(sid); draw();
      };
    }
    function instRows(sid) {
      const o = st[sid]; const box = ctx.el.querySelector('[data-shop="' + sid + '"] [data-instrows]'); if (!box) return;
      box.innerHTML = o.inst.items.map((it, i) => '<div class="row mb-1" style="gap:8px" data-ir="' + i + '"><span class="small muted" style="width:26px">#' + (i + 1) + '</span><input class="input input-sm" type="date" data-f="due" value="' + it.due + '" min="' + U.addDays(U.today(), 1) + '" max="' + o.eventDate + '" aria-label="Due date ' + (i + 1) + '" style="width:auto"><input class="input input-sm" type="number" step="0.01" min="1" data-f="amount" value="' + it.amount + '" aria-label="Amount ' + (i + 1) + '" style="width:130px"></div>').join('');
      box.querySelectorAll('[data-ir] [data-f]').forEach(inp => inp.onchange = () => { const it = o.inst.items[+inp.closest('[data-ir]').dataset.ir]; it[inp.dataset.f] = inp.dataset.f === 'amount' ? U.round2(+inp.value || 0) : inp.value; o.inst.auto = false; refresh(sid); summary(); });
    }
    function refresh(sid) {
      const o = st[sid]; const box = ctx.el.querySelector('[data-shop="' + sid + '"]'); if (!box) return;
      const q = quoteOf(sid);
      if (o.plan === 'installment') { if (!o.inst.items.length) resuggest(sid); instRows(sid); const sum = U.round2(U.sum(o.inst.items, x => +x.amount)); const el = box.querySelector('[data-instsum]'); if (el) el.innerHTML = 'Total ' + U.money(sum) + ' of ' + U.money(q.finalBalance) + (Math.abs(sum - q.finalBalance) > 0.009 ? ' <span style="color:var(--red)">· must match</span>' : ' <span style="color:var(--green)">✓</span>'); }
      const pr = Services.prepareOrder(u.id, Object.assign(orderOf(sid), { eventName: shared.eventName.length >= 3 ? shared.eventName : 'Event' }));
      const cfg = q.terms; const dd = n => (n ? 'within ' + n + ' day' + (n > 1 ? 's' : '') + ' after acceptance' : 'right after acceptance');
      const rows = q.plan === 'full' ? '<div class="sum-line"><span>Full Payment (100%)<div class="small muted">' + dd(cfg.days) + '</div></span><b>' + U.money(q.total) + '</b></div>'
        : (q.reservationFee ? '<div class="sum-line"><span>Reservation Fee (' + cfg.resPct + '%) <span class="badge b-teal plain">credited</span><div class="small muted">non-refundable · ' + dd(cfg.resDays) + '</div></span><b>' + U.money(q.reservationFee) + '</b></div>' : '') + (q.downPayment ? '<div class="sum-line"><span>Down Payment (' + cfg.downPct + '%) <span class="badge b-teal plain">credited</span><div class="small muted">' + dd(cfg.downDays) + '</div></span><b>' + U.money(q.downPayment) + '</b></div>' : '') + '<div class="sum-line"><span>' + (q.plan === 'installment' ? 'Installments (' + o.inst.items.length + ')' : 'Final Balance') + '<div class="small muted">on or before ' + U.fmtDate(o.eventDate) + '</div></span><b>' + U.money(q.finalBalance) + '</b></div>';
      box.querySelector('[data-review]').innerHTML = (pr.ok ? '' : '<div class="alert alert-danger mb-1">' + I('alert') + '<div>' + esc(pr.error) + (pr.check || pr.durationIssues || pr.timeIssues ? ' <a href="' + url('customer/cart.html') + '">Edit in Rental List</a>' : '') + '</div></div>') +
        UI.rentalCharges(q, { open: true }) + '<div class="label mt-1 mb-1">' + esc(Finance.PLAN_LABEL[q.plan]) + '</div>' + rows +
        '<div class="sum-line"><span class="muted">Security deposit <span class="badge b-purple plain">refundable</span></span><span>' + U.money(q.deposit) + '</span></div><div class="small muted">Collected separately from rental charges and returned after inspection.</div>';
      o.ok = pr.ok; o.error = pr.error; o.q = q;
    }
    function summary() {
      const tot = U.round2(U.sum(sids, sid => (st[sid].q || quoteOf(sid)).total));
      $('#sum').innerHTML = sids.map(sid => { const o = st[sid]; const q = o.q || quoteOf(sid); return '<div class="sum-line"><span>' + esc(o.sup.name) + '<div class="small muted">' + esc(Finance.PLAN_LABEL[o.plan]) + (o.fulfillment === 'delivery' ? ' · ' + o.destinations.length + ' location' + (o.destinations.length > 1 ? 's' : '') : ' · pickup') + '</div>' + (o.ok ? '' : '<div class="small" style="color:var(--red)">Needs attention</div>') + '</span><span>' + U.money(q.total) + '</span></div>'; }).join('') + '<div class="sum-line total"><span>Combined total</span><span class="price">' + U.money(tot) + '</span></div><p class="small muted mb-0">Each shop\'s reservation fee, down payment and balance are calculated from its own order total.</p>';
    }
    async function submit() {
      const f = $('#cf');
      if (!UI.validate(f)) return;
      if (!$('#agree').checked) return UI.toast('Please accept the shops\' policies.', 'warn');
      sids.forEach(refresh); summary();
      const bad = sids.find(sid => !st[sid].ok);
      if (bad) { ctx.el.querySelector('[data-shop="' + bad + '"]').scrollIntoView({ behavior: 'smooth' }); return UI.toast(st[bad].sup.name + ': ' + st[bad].error, 'error'); }
      const list = sids.map(sid => { const o = st[sid]; const q = o.q; return '<div class="sum-line"><span><b>' + esc(o.sup.name) + '</b><div class="small muted">' + o.lines.length + ' line(s) · ' + (o.fulfillment === 'delivery' ? o.destinations.length + ' delivery location(s)' : 'pickup') + ' · ' + esc(Finance.PLAN_LABEL[o.plan]) + ' · event ' + U.fmtDate(o.eventDate) + '</div></span><b>' + U.money(q.total) + '</b></div>'; }).join('');
      if (!await UI.confirm({ title: 'Submit ' + (sids.length > 1 ? sids.length + ' rental requests' : 'rental request') + '?', html: '<p class="small muted">Review each shop before sending. Nothing is reserved until a supplier accepts.</p>' + list, confirmText: 'Submit' })) return;
      const btn = $('#submitBtn'); btn.disabled = true; btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;border-top-color:#fff"></span> Submitting…';
      setTimeout(() => {
        const res = Services.submitOrders(u.id, sids.map(orderOf));
        if (!res.ok) { btn.disabled = false; btn.textContent = 'Submit'; UI.toast(res.error, 'error'); sids.forEach(refresh); summary(); return; }
        App.refreshCart();
        U.go('customer/confirmation.html', { ids: res.requests.map(r => r.id).join(',') });
      }, 400);
    }
    sids.forEach(resuggest);
    draw();
  };

  C.confirmation = function (ctx) {
    const ids = String(ctx.params.ids || ctx.params.id || '').split(',').filter(Boolean);
    const list = ids.map(id => Store.request(id)).filter(r => r && r.customerId === ctx.session.user.id);
    if (!list.length) { ctx.el.innerHTML = UI.empty('🧾', 'Request not found', ''); return; }
    ctx.el.innerHTML = '<div class="card center" style="max-width:680px;margin:20px auto;padding:36px 24px"><div style="font-size:3.4rem">🎉</div><h1>' + (list.length > 1 ? list.length + ' requests submitted!' : 'Request submitted!') + '</h1><p class="muted">Each shop received its own rental request.</p>' +
      '<div class="stack-sm" style="text-align:left">' + list.map(r => { const sup = Store.supplier(r.supplierId); return '<div class="card card-flat row between wrap" style="padding:12px 14px"><div><b class="price">' + r.id + '</b> · ' + esc(sup.name) + '<div class="small muted">' + U.fmtRange(r.start, r.end) + ' · ' + esc(Finance.PLAN_LABEL[r.plan]) + (r.destinations.length > 1 ? ' · ' + r.destinations.length + ' locations' : '') + '</div></div><div class="row">' + UI.statusBadge('pending') + '<b>' + U.money(r.pricing.total) + '</b><a class="btn btn-xs" href="' + url('customer/rental.html', { id: r.id }) + '">View</a></div></div>'; }).join('') + '</div>' +
      '<div class="card card-flat soft mt-2" style="text-align:left"><b>What happens next</b><ol class="small mb-0" style="padding-left:18px"><li>Each supplier reviews availability and its inventory allocation (status stays <b>Pending</b>).</li><li>Once accepted, that order\'s payment schedule appears in <b>My Rentals</b> — reservation fee first (or the full payment).</li>' + (list.some(r => r.plan === 'installment') ? '<li>Installment schedules are approved by the supplier before they take effect.</li>' : '') + '<li>Payments count after the shop verifies them. Everything is due on or before the event date.</li></ol></div>' +
      '<div class="row wrap mt-3" style="justify-content:center"><a class="btn btn-primary" href="' + url('customer/rentals.html') + '">My Rentals</a>' + (Services.cartCount(ctx.session.user.id) ? '<a class="btn" href="' + url('customer/cart.html') + '">Back to Rental List (' + Services.cartCount(ctx.session.user.id) + ' left)</a>' : '') + '<a class="btn btn-ghost" href="' + url('customer/browse.html') + '">Keep browsing</a></div></div>';
  };

  /* ================= MY RENTALS ================= */
  C.rentals = function (ctx) {
    const uid = ctx.session.user.id;
    const all = Store.where('requests', r => r.customerId === uid).sort((a, b) => b.createdAt.localeCompare(a.createdAt));
    const groups = { all: () => true, pending: r => r.status === 'pending', active: r => Finance.ACTIVE.includes(r.status), completed: r => r.status === 'completed', closed: r => ['declined', 'cancelled'].includes(r.status) };
    let tab = ctx.params.tab || 'all', q = '';
    ctx.el.innerHTML = '<div class="page-head"><div><h1>My Rentals</h1><p>Track requests, payments and returns.</p></div><input class="input input-sm" id="rq" placeholder="Search request no. or event" style="width:240px" aria-label="Search rentals"></div><div id="tabs"></div><div id="rl"></div>';
    const draw = () => {
      $('#tabs').innerHTML = UI.tabs([['all', 'All'], ['pending', 'Pending'], ['active', 'Active'], ['completed', 'Completed'], ['closed', 'Declined / Cancelled']].map(([k, l]) => ({ key: k, label: l, count: all.filter(groups[k]).length })), tab);
      $$('#tabs [data-tab]').forEach(t => t.onclick = () => { tab = t.dataset.tab; draw(); });
      const list = all.filter(groups[tab]).filter(r => !q || (r.id + ' ' + r.eventName + ' ' + Store.supplier(r.supplierId).name).toLowerCase().includes(q));
      $('#rl').innerHTML = list.length ? '<div class="stack">' + list.map(r => {
        const f = Finance.summary(r); const sup = Store.supplier(r.supplierId);
        return '<a class="card row wrap between" style="color:inherit;text-decoration:none" href="' + url('customer/rental.html', { id: r.id }) + '"><div class="row" style="min-width:0"><div class="logo-tile" style="background:' + sup.color + '1a">' + esc(sup.logo) + '</div><div style="min-width:0"><div class="strong">' + esc(r.eventName) + '</div><div class="small muted">' + r.id + ' · ' + esc(sup.name) + '</div><div class="small">' + I('calendar').replace('<svg', '<svg style="width:13px;vertical-align:-2px"') + ' ' + U.fmtRange(r.start, r.end) + ' · ' + (r.fulfillment === 'delivery' ? 'Delivery' : 'Pickup') + '</div></div></div><div class="row wrap"><div class="right"><div class="small muted">Total</div><b>' + U.money(f.payable) + '</b>' + (f.balance > 0 && Finance.ACTIVE.includes(r.status) ? '<div class="small" style="color:var(--brand)">Balance ' + U.money(f.balance) + '</div>' : '') + '</div><div class="stack-sm" style="align-items:flex-end">' + UI.statusBadge(r.status) + UI.payBadge(f.status) + '</div></div></a>';
      }).join('') + '</div>' : '<div class="card">' + UI.empty('📋', 'No rentals here', tab === 'all' ? 'When you submit a rental request it will appear here.' : 'Nothing in this tab yet.', '<a class="btn btn-primary" href="' + url('customer/browse.html') + '">Find party supplies</a>') + '</div>';
    };
    $('#rq').oninput = e => { q = e.target.value.toLowerCase(); draw(); };
    draw();
  };

  /* ================= RENTAL DETAILS ================= */
  C.rental = function (ctx) {
    const s = ctx.session;
    const r = Store.request(ctx.params.id);
    if (!r || r.customerId !== s.user.id) { ctx.el.innerHTML = UI.empty('🔒', 'Request not found', 'This request does not exist or belongs to another account.', '<a class="btn" href="' + url('customer/rentals.html') + '">My Rentals</a>'); return; }
    const draw = () => {
      const sup = Store.supplier(r.supplierId); const f = Finance.summary(r);
      const canPay = Finance.ACTIVE.includes(r.status) && (f.balance - f.pendingRental > 0.009 || f.depositPaid < f.depositRequired || f.compensationDue > 0);
      const pol = Services.cancelPolicy(r);
      const review = Store.where('reviews', x => x.requestId === r.id)[0];
      ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('customer/rentals.html') + '">My Rentals</a> / ' + r.id + '</div><div class="page-head"><div><h1>' + esc(r.eventName) + '</h1><p>' + r.id + ' · ' + esc(r.eventType) + ' · submitted ' + U.fmtDateTime(r.createdAt) + '</p></div><div class="row wrap">' + UI.statusBadge(r.status) + UI.payBadge(f.status) + '</div></div>' +
        '<div class="card mb-2">' + S().progress(r) + '</div>' +
        (f.isOverdue ? '<div class="alert alert-danger mb-2">' + I('alert') + '<div><b>Payment overdue.</b> Outstanding: ' + U.money(f.overdueAmount) + '. Please settle as soon as possible to keep your reservation. The supplier will review overdue accounts according to their policy.</div></div>' : '') +
        (r.status === 'pending' ? '<div class="alert alert-info mb-2">' + I('clock') + '<div>Waiting for <b>' + esc(sup.name) + '</b> to review your request. Items are not reserved until it is accepted.</div></div>' : '') +
        '<div class="grid grid-side"><div class="stack">' +
        '<div class="card"><div class="card-head"><h3>Items & daily quantities</h3><span class="small muted">' + U.fmtRange(r.start, r.end) + '</span></div>' + S().linesTable(r) + '</div>' +
        (r.fulfillment === 'delivery' ? '<div class="card"><h3>' + (r.destinations.length > 1 ? 'Delivery & event locations' : 'Delivery location') + '</h3>' + S().destinations(r) + '</div>' : '') +
        (r.plan === 'installment' ? installmentCard(r) : '') +
        '<div class="card"><div class="card-head"><h3>Payment history</h3>' + (canPay ? '<button class="btn btn-primary btn-sm" id="payBtn">' + I('card') + 'Make a payment</button>' : '') + '</div>' + S().paymentHistory(r) + '</div>' +
        (r.inspection ? S().inspectionReport(r) : '') +
        '<div class="card"><h3>Activity</h3>' + S().timeline(r) + '</div></div>' +
        '<aside class="stack"><div class="card">' + S().paymentSummary(r) + '</div>' +
        '<div class="card"><h3>' + (r.fulfillment === 'delivery' ? 'Delivery' : 'Pickup') + ' details</h3><dl class="kv"><dt>Supplier</dt><dd><a href="' + url('customer/supplier.html', { id: sup.id }) + '">' + esc(sup.name) + '</a><div class="small muted">' + esc(sup.phone) + '</div></dd>' + (r.fulfillment === 'delivery' ? '<dt>Deliver to</dt><dd>' + (r.destinations.length > 1 ? r.destinations.length + ' locations (see below)' : esc(r.address)) + '</dd>' : '<dt>Pickup at</dt><dd>' + esc(sup.address) + '<div class="small muted">' + esc(sup.hours) + '</div></dd>') + '<dt>Contact</dt><dd>' + esc(r.contactName || s.user.name) + ' · ' + esc(r.contactPhone || s.user.phone) + '</dd><dt>Payment</dt><dd>' + Finance.ARR_LABEL[r.arrangement] + ' · ' + Finance.METHOD_LABEL[r.method] + '</dd>' + (r.fulfillmentLog && r.fulfillmentLog.releaseDate ? '<dt>Released</dt><dd>' + U.fmtDate(r.fulfillmentLog.releaseDate) + ' to ' + esc(r.fulfillmentLog.receivedBy) + '</dd>' : '') + (r.fulfillmentLog && r.fulfillmentLog.returnDate ? '<dt>Returned</dt><dd>' + U.fmtDate(r.fulfillmentLog.returnDate) + '</dd>' : '') + '<dt>Return status</dt><dd>' + (r.status === 'completed' ? 'Returned & closed' : r.inspection ? 'Inspected' : r.status === 'return_pending' ? 'Awaiting return' : r.status === 'ongoing' ? 'Due ' + U.fmtDate(U.addDays(r.end, 1)) : '—') + '</dd></dl>' + (r.instructions ? '<p class="small mt-1 mb-0"><b>Your instructions:</b> ' + esc(r.instructions) + '</p>' : '') + (r.supplierNotes ? '<div class="alert alert-info mt-1">' + I('message') + '<div class="small"><b>Supplier notes:</b> ' + esc(r.supplierNotes) + '</div></div>' : '') + '</div>' +
        (pol.allowed && !['declined', 'cancelled', 'completed'].includes(r.status) ? '<div class="card"><h3>Need to cancel?</h3><p class="small muted">' + esc(pol.note) + '</p><button class="btn btn-danger btn-sm" id="cancelBtn">Cancel request</button></div>' : '') +
        (r.status === 'completed' && !review ? '<div class="card"><h3>Rate your rental</h3><div id="rv"></div></div>' : review ? '<div class="card"><h3>Your review</h3><div class="stars">' + U.stars(review.rating) + '</div><p class="mb-0">' + esc(review.comment) + '</p></div>' : '') + '</aside></div>';
      const pb = $('#payBtn'); if (pb) pb.onclick = () => payModal(r, draw);
      const ip = $('#propose'); if (ip) ip.onclick = () => proposeModal(r, draw);
      const cb = $('#cancelBtn'); if (cb) cb.onclick = async () => {
        const reason = await UI.prompt({ title: 'Cancel ' + r.id + '?', message: pol.note, label: 'Reason for cancelling', required: true, textarea: true, confirmText: 'Cancel request', danger: true });
        if (reason == null) return;
        const res = Services.cancelRequest(r.id, reason);
        if (!res.ok) return UI.toast(res.error, 'error');
        UI.toast('Request cancelled. The supplier has been notified.', 'success'); draw();
      };
      const rv = $('#rv'); if (rv) {
        rv.innerHTML = '<form id="rvf"><div class="row gap-sm mb-1" id="stars">' + [1, 2, 3, 4, 5].map(n => '<button type="button" class="btn btn-icon btn-sm" data-star="' + n + '" aria-label="' + n + ' stars">★</button>').join('') + '</div><div class="field"><textarea class="input" name="comment" required placeholder="How were the items and service?"></textarea></div><button class="btn btn-primary btn-sm">Submit review</button></form>';
        let rating = 5; const paint = () => $$('[data-star]').forEach(b => { b.style.color = +b.dataset.star <= rating ? 'var(--gold)' : ''; }); paint();
        $$('[data-star]').forEach(b => b.onclick = () => { rating = +b.dataset.star; paint(); });
        $('#rvf').onsubmit = e => { e.preventDefault(); if (!UI.validate(e.target)) return; const first = r.lines.find(l => l.type === 'item'); Store.insert('reviews', { id: U.uid('rv'), supplierId: r.supplierId, productId: first ? first.refId : null, requestId: r.id, customerId: s.user.id, rating, comment: e.target.comment.value.trim(), at: new Date().toISOString() }); const sp = Store.supplier(r.supplierId); const rs = Store.where('reviews', x => x.supplierId === sp.id); sp.rating = U.round2(U.sum(rs, x => x.rating) / rs.length); Store.save(); UI.toast('Thanks for your review!', 'success'); draw(); };
      }
    };
    draw();
  };

  /** Installment schedule (customer view): status, items, and re-proposal after a rejection. */
  function installmentCard(r) {
    const ip = r.installmentPlan; const open = ['pending'].concat(Finance.ACTIVE).includes(r.status);
    const badge = ip ? '<span class="badge ' + ({ approved: 'b-green', proposed: 'b-gold', rejected: 'b-red' }[ip.status]) + '">' + ({ approved: 'Approved', proposed: 'Awaiting supplier approval', rejected: 'Rejected' }[ip.status]) + '</span>' : '<span class="badge b-gray">Not proposed</span>';
    return '<div class="card"><div class="card-head"><h3>Installment schedule</h3>' + badge + '</div>' + (ip ? UI.table([{ label: 'Installment', render: x => 'Installment ' + x.n + ' of ' + ip.items.length }, { label: 'Due date', render: x => U.fmtDate(x.due) }, { label: 'Amount', cls: 'num', render: x => '<b>' + U.money(x.amount) + '</b>' }], ip.items.map((x, i) => Object.assign({ n: i + 1 }, x))) : '') +
      (ip && ip.status === 'rejected' ? '<div class="alert alert-danger mt-1">' + I('alert') + '<div><b>Not approved:</b> ' + esc(ip.reason || '') + '</div></div>' : '') +
      '<p class="small muted mt-1 mb-0">Installment balance ' + U.money(r.pricing.finalBalance) + ' · every installment must be paid on or before ' + U.fmtDate(r.eventDate) + '.</p>' +
      (open && (!ip || ip.status === 'rejected') ? '<button class="btn btn-primary btn-sm mt-1" id="propose">' + I('edit') + 'Propose a new schedule</button>' : '') + '</div>';
  }
  function proposeModal(r, done) {
    const sup = Store.supplier(r.supplierId); const max = Finance.planConfig(sup).installment.maxInstallments;
    let items = Finance.suggestInstallments(r.pricing.finalBalance, Math.min(3, max), U.addDays(U.today(), 1), r.eventDate);
    const m = UI.modal({ title: 'Propose installment schedule · ' + r.id, body: '<p class="small muted">Divide ' + U.money(r.pricing.finalBalance) + ' into up to ' + max + ' payments, all due on or before ' + U.fmtDate(r.eventDate) + '.</p><div class="row mb-1" style="gap:8px"><span class="small">Installments</span><select class="input input-sm" id="pn" style="width:auto">' + Array.from({ length: max }, (_, i) => '<option' + (i + 1 === items.length ? ' selected' : '') + '>' + (i + 1) + '</option>').join('') + '</select></div><div id="prow"></div><div class="small" id="psum"></div>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="pok">Send to supplier</button>' });
    const rows = () => { m.el.querySelector('#prow').innerHTML = items.map((it, i) => '<div class="row mb-1" style="gap:8px" data-i="' + i + '"><span class="small muted" style="width:26px">#' + (i + 1) + '</span><input class="input input-sm" type="date" data-f="due" value="' + it.due + '" max="' + r.eventDate + '" style="width:auto"><input class="input input-sm" type="number" step="0.01" min="1" data-f="amount" value="' + it.amount + '" style="width:130px"></div>').join(''); m.el.querySelectorAll('[data-i] [data-f]').forEach(inp => inp.onchange = () => { items[+inp.closest('[data-i]').dataset.i][inp.dataset.f] = inp.dataset.f === 'amount' ? U.round2(+inp.value || 0) : inp.value; sum(); }); sum(); };
    const sum = () => { const t = U.round2(U.sum(items, x => +x.amount)); m.el.querySelector('#psum').innerHTML = 'Total ' + U.money(t) + ' of ' + U.money(r.pricing.finalBalance) + (Math.abs(t - r.pricing.finalBalance) > 0.009 ? ' <span style="color:var(--red)">· must match</span>' : ' ✓'); };
    m.el.querySelector('#pn').onchange = e => { items = Finance.suggestInstallments(r.pricing.finalBalance, +e.target.value, U.addDays(U.today(), 1), r.eventDate); rows(); };
    rows();
    m.el.querySelector('#pok').onclick = () => { const res = Services.proposeInstallmentPlan(r.id, items); if (!res.ok) return UI.toast(res.error, 'error'); m.close(); UI.toast('Schedule sent to ' + sup.name + ' for approval.', 'success'); done(); };
  }

  /** Payment submission (customer). Creates a PENDING record for the shop's verification. */
  function payModal(r, done) {
    const sup = Store.supplier(r.supplierId); const f = Finance.summary(r); const sug = Finance.suggestedNext(r);
    const types = [];
    if (f.balance - f.pendingRental > 0.009) types.push([sug.type, sug.label + ' (suggested)', sug.amount]);
    if (f.balance - f.pendingRental > 0.009 && sug.amount < f.balance - f.pendingRental - 0.009) types.push(['balance', 'Full remaining balance', U.round2(f.balance - f.pendingRental)]);
    if (f.depositPaid < f.depositRequired) types.push(['deposit', 'Security deposit (refundable)', U.round2(f.depositRequired - f.depositPaid)]);
    if (f.compensationDue > 0) types.push(['compensation', 'Damage compensation', f.compensationDue]);
    const methods = sup.methods;
    const m = UI.modal({ title: 'Make a payment · ' + r.id, body: '<form id="pmf" novalidate><div class="field"><label>What are you paying for?</label><select class="input" name="kind">' + types.map((t, i) => '<option value="' + i + '">' + esc(t[1]) + ' — ' + U.money(t[2]) + '</option>').join('') + '</select></div>' +
      '<div class="form-grid"><div class="field"><label>Amount (₱)</label><input class="input" type="number" step="0.01" min="1" name="amount" required></div><div class="field"><label>Payment method</label><select class="input" name="method">' + methods.map(x => '<option value="' + x + '">' + Finance.METHOD_LABEL[x] + '</option>').join('') + '</select></div></div>' +
      '<div id="bankBox"><div class="alert alert-info mb-2">' + I('info') + '<div class="small">Transfer to <b>' + esc(sup.name) + '</b> · BDO 0012-3456-7890 (demo account). Then enter the reference number and upload your receipt.</div></div><div class="form-grid"><div class="field"><label>Reference number</label><input class="input" name="reference" placeholder="e.g. BDO-123456"></div><div class="field"><label>Payment date</label><input class="input" type="date" name="date" value="' + U.today() + '" max="' + U.today() + '"></div></div><div class="field"><label>Payment proof</label><label class="upload-box" for="proof">' + I('upload') + '<div class="small">Upload a screenshot or photo of the receipt (JPG/PNG)</div></label><input type="file" id="proof" accept="image/*" hidden><div class="thumbs" id="pv"></div></div></div>' +
      '<div id="codBox" class="alert alert-warn hidden">' + I('cash') + '<div class="small"><b>Cash on Delivery:</b> pay the supplier when the items arrive. The supplier will record it and our cashier will verify it. You can also log it here with the amount you handed over.</div></div>' +
      '<div class="field"><label>Notes (optional)</label><input class="input" name="notes"></div><p class="small muted mb-0">Your payment will show as <b>Pending Verification</b> until ' + esc(sup.name) + ' verifies it. It does not reduce your balance until verified. Each reference number can only be used once.</p></form>',
      foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="pmSubmit">Submit payment</button>' });
    const fm = m.el.querySelector('#pmf'); let proof = null, proofName = '';
    const setKind = () => { fm.amount.value = types[+fm.kind.value][2]; };
    const setMethod = () => { const bank = ['bank', 'debit'].includes(fm.method.value); m.el.querySelector('#bankBox').classList.toggle('hidden', !bank); m.el.querySelector('#codBox').classList.toggle('hidden', bank); fm.reference.required = bank; };
    fm.kind.onchange = setKind; fm.method.onchange = setMethod; setKind(); setMethod();
    m.el.querySelector('#proof').onchange = async e => { try { proof = await U.readImage(e.target.files[0], 900); proofName = e.target.files[0].name; m.el.querySelector('#pv').innerHTML = '<img src="' + proof + '" alt="Proof preview">'; } catch (err) { UI.toast(err.message, 'error'); } };
    m.el.querySelector('#pmSubmit').onclick = () => {
      if (!UI.validate(fm)) return;
      const t = types[+fm.kind.value];
      const bank = ['bank', 'debit'].includes(fm.method.value);
      if (bank && !proof) return UI.toast('Please upload your payment proof for bank/debit payments.', 'warn');
      const res = Services.submitPayment(r.id, { type: t[0], amount: Number(fm.amount.value), method: fm.method.value, reference: fm.reference.value, date: fm.date.value, proof, proofName, notes: fm.notes.value });
      if (!res.ok) return UI.toast(res.error, 'error');
      m.close(); UI.toast('Payment submitted (' + res.payment.id + '). Waiting for verification by the shop.', 'success'); done();
    };
  }
  C.payModal = payModal;

  /* ================= PAYMENT HISTORY ================= */
  C.payments = function (ctx) {
    const uid = ctx.session.user.id;
    const reqs = Store.where('requests', r => r.customerId === uid);
    const ids = new Set(reqs.map(r => r.id));
    const pays = Store.where('payments', p => ids.has(p.requestId)).sort((a, b) => (b.submittedAt || '').localeCompare(a.submittedAt || ''));
    const active = reqs.filter(r => Finance.ACTIVE.includes(r.status));
    const owed = U.sum(active, r => Finance.summary(r).balance);
    const paid = U.sum(pays.filter(p => p.status === 'verified' && Finance.RENTAL_TYPES.includes(p.type)), p => p.amount);
    const held = U.sum(reqs, r => Finance.summary(r).depositHeld);
    let f = 'all';
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Payment History</h1><p>All payments, receipts, deposits and refunds.</p></div></div><div class="stats mb-3">' + UI.stat('Total paid (verified)', U.moneyShort(paid), 'Rental payments', 'check', 'teal') + UI.stat('Outstanding balance', U.moneyShort(owed), active.length + ' active rentals', 'clock', 'pink') + UI.stat('Pending verification', pays.filter(p => p.status === 'pending').length, U.moneyShort(U.sum(pays.filter(p => p.status === 'pending'), p => p.amount)), 'alert', 'gold') + UI.stat('Deposits held', U.moneyShort(held), 'Refundable', 'shield', 'purple') + '</div><div class="card"><div id="tb"></div><div id="pl"></div></div>';
    const draw = () => {
      $('#tb').innerHTML = UI.tabs([{ key: 'all', label: 'All', count: pays.length }, { key: 'pending', label: 'Pending', count: pays.filter(p => p.status === 'pending').length }, { key: 'verified', label: 'Verified' }, { key: 'rejected', label: 'Rejected' }], f);
      $$('#tb [data-tab]').forEach(t => t.onclick = () => { f = t.dataset.tab; draw(); });
      $('#pl').innerHTML = UI.table([{ label: 'Date', render: p => U.fmtDate(p.date) }, { label: 'Transaction', render: p => '<b>' + p.id + '</b><div class="small muted"><a href="' + url('customer/rental.html', { id: p.requestId }) + '">' + p.requestId + '</a></div>' }, { label: 'Type', render: p => Finance.TYPE_LABEL[p.type] }, { label: 'Method', render: p => Finance.METHOD_LABEL[p.method] || p.method }, { label: 'Amount', cls: 'num', render: p => '<b>' + U.money(p.amount) + '</b>' }, { label: 'Status', render: p => UI.verBadge(p.status) }, { label: 'Receipt', render: p => p.receiptNo ? '<button class="btn btn-xs" data-pay="' + p.id + '">' + p.receiptNo + '</button>' : '<button class="btn btn-xs btn-ghost" data-pay="' + p.id + '">Details</button>' }], pays.filter(p => f === 'all' || p.status === f), { emptyText: 'No payments yet.' });
    };
    draw();
  };

  /* ================= PROFILE ================= */
  C.profile = function (ctx) {
    const s = ctx.session; const u = s.user; const sup = Auth.supplierOf(u);
    const reqs = Store.where('requests', r => r.customerId === u.id);
    ctx.el.innerHTML = (ctx.params.welcome ? '<div class="alert alert-success mb-2">' + I('party') + '<div><b>Welcome to PartyRent!</b> Your account is ready. <a href="' + url('customer/ai.html') + '">Plan your first event with AI →</a></div></div>' : '') +
      '<div class="card row wrap mb-3"><span class="avatar lg">' + U.initials(u.name) + '</span><div class="grow"><h1 class="mb-0">' + esc(u.name) + '</h1><div class="muted">' + esc(u.email) + ' · Member since ' + U.fmtDate(u.createdAt.slice(0, 10)) + '</div><div class="row wrap gap-sm mt-1"><span class="badge b-pink">Customer</span>' + (sup ? '<span class="badge ' + (sup.status === 'approved' ? 'b-purple' : Services.APP_STATUS[sup.status][1]) + '">Supplier · ' + Services.APP_STATUS[sup.status][0] + '</span>' : '') + '</div></div>' +
      '<div class="row wrap">' + (sup && sup.status === 'approved' ? '<button class="btn btn-primary" data-switch="supplier">' + I('swap') + 'Switch to Supplier Mode</button>' : '<a class="btn btn-primary" href="' + url('customer/start-selling.html') + '">' + I('store') + (sup ? 'View application' : 'Start Selling') + '</a>') + '<button class="btn" data-logout>' + I('logout') + 'Log out</button></div></div>' +
      '<div class="stats mb-3">' + UI.stat('Total requests', reqs.length, '', 'list', 'pink', url('customer/rentals.html')) + UI.stat('Active rentals', reqs.filter(r => Finance.ACTIVE.includes(r.status)).length, '', 'calendar', 'purple', url('customer/rentals.html', { tab: 'active' })) + UI.stat('Completed', reqs.filter(r => r.status === 'completed').length, '', 'check', 'teal', url('customer/rentals.html', { tab: 'completed' })) + UI.stat('Payments', '', '<a href="' + url('customer/payments.html') + '">View history →</a>', 'card', 'gold') + '</div>' +
      '<div class="grid grid-2">' + S().accountForms(u) + '</div>';
    S().bindAccountForms(ctx.el, u);
  };

  /* ================= START SELLING =================
     Two application routes: Individual Supplier and Organization / Company Supplier, each with
     its own information and document requirements. The applicant becomes the Organization
     Admin of the supplier account once approved. */
  C.startSelling = function (ctx) {
    const s = ctx.session; const u = s.user;
    const existing = Auth.supplierOf(u);
    if (existing && !['rejected', 'revision'].includes(existing.status) || (existing && !ctx.params.edit)) { statusView(existing); return; }
    const pre = existing ? JSON.parse(JSON.stringify(existing)) : {};
    const app0 = pre.application || {};
    const data = Object.assign({ name: '', description: '', logo: '🎈', color: '#E11D74', phone: u.phone, email: u.email, address: u.address, city: '', fulfillment: 'both', deliveryFee: 300, methods: ['cod', 'bank'], hours: 'Mon–Sat, 9:00 AM – 6:00 PM', offerPlans: ['standard', 'full'], policy: { depositType: 'fixed', depositValue: 500, cancelDays: 3, cancellation: 'Free cancellation up to 3 days before the rental start date. The reservation fee is non-refundable.', returns: 'Items must be returned clean and complete on the agreed date.', conditions: 'Renter is responsible for damaged or missing items, charged according to our damage policy.' } }, pre);
    if (pre.paymentPlans) data.offerPlans = Finance.plansOf(pre);
    const app = Object.assign({ type: pre.type || app0.type || '', personal: { fullName: u.name, birthDate: '', address: u.address, phone: u.phone, email: u.email }, identity: { idType: 'Philippine National ID', idNumber: '' }, business: { shopName: '', address: u.address, productTypes: '', phone: u.phone }, company: { legalName: '', companyType: 'corporation', address: '', email: '', phone: '', natureOfBusiness: 'Party and event equipment rental', description: '', branches: 1, website: '' }, representative: { name: u.name, position: '', idType: 'Philippine National ID', email: u.email, phone: u.phone }, docs: {}, applicable: [], payout: { method: 'bank', provider: '', accountName: '', accountNumber: '', financeContact: '' } }, JSON.parse(JSON.stringify(app0)));
    const STEPS = ['Supplier type', 'Applicant', 'Documents', 'Shop details', 'Delivery & payments', 'Payout', 'Policies', 'Review'];
    let step = existing ? 1 : 0;
    const LOGOS = ['🎈', '🎉', '✨', '🎪', '💍', '🥳', '🎀', '🎂', '👑', '🌸', '🪩', '🎵'];
    const cities = ['Quezon City', 'Manila', 'Makati City', 'Pasig City', 'Taguig City', 'Caloocan City', 'Marikina', 'Parañaque', 'Mandaluyong', 'Pasay', 'San Juan', 'Las Piñas', 'Muntinlupa', 'Valenzuela', 'Antipolo', 'Cavite', 'Laguna', 'Bulacan'];
    const IDS = ['Philippine National ID', "Driver's License", 'Passport', 'UMID', 'PRC ID', 'Postal ID'];
    const chk = (name, val, label, arr) => '<label class="check"><input type="checkbox" name="' + name + '" value="' + val + '" data-multi' + (arr.includes(val) ? ' checked' : '') + '> ' + label + '</label>';
    const fld = (label, name, val, attrs, cls) => '<div class="field ' + (cls || '') + '"><label>' + label + '</label><input class="input" name="' + name + '" value="' + esc(val || '') + '" ' + (attrs || 'required') + '></div>';
    const org = () => app.type === 'organization';
    function body() {
      const p = data.policy;
      switch (step) {
        case 0: return '<p class="muted">Choose how you want to register your rental business. Requirements differ for individuals and companies.</p><div class="grid grid-2">' +
          '<button type="button" class="type-card' + (app.type === 'individual' ? ' on' : '') + '" data-type="individual"><div style="font-size:2rem">🧑‍💼</div><h3 class="mt-1">Individual Supplier</h3><p class="small muted">For individual owners and small rental businesses — even if you have a few helpers.</p><ul class="small"><li>Valid government ID (+ selfie)</li><li>Personal and business information</li><li>DTI, barangay or mayor\'s permit <i>if applicable</i></li></ul><span class="badge b-pink">Single owner account</span></button>' +
          '<button type="button" class="type-card' + (app.type === 'organization' ? ' on' : '') + '" data-type="organization"><div style="font-size:2rem">🏢</div><h3 class="mt-1">Organization / Company Supplier</h3><p class="small muted">For corporations, partnerships, cooperatives and companies with employees or branches.</p><ul class="small"><li>Company registration documents (SEC / DTI / CDA)</li><li>BIR registration and business permits</li><li>Authorized representative\'s ID and proof of authority</li></ul><span class="badge b-purple">Multiple employee accounts</span></button></div><p class="small muted mt-2 mb-0">Applications are subject to PartyRent administrator verification. Employees (cashier, inventory staff) are added after approval — not during the application.</p>';
        case 1: return org()
          ? '<h3>Company information</h3><div class="form-grid">' + fld('Registered company / organization name', 'c_legalName', app.company.legalName, 'required minlength="3"', 'full') + '<div class="field"><label>Company type</label><select class="input" name="c_companyType">' + Object.entries(Services.COMPANY_TYPES).map(([k, l]) => '<option value="' + k + '"' + (k === app.company.companyType ? ' selected' : '') + '>' + l + '</option>').join('') + '</select></div>' + fld('Number of branches / rental locations', 'c_branches', app.company.branches, 'type="number" min="1" required') + fld('Complete business address', 'c_address', app.company.address, 'required minlength="8"', 'full') + fld('Official company email', 'c_email', app.company.email, 'type="email" required') + fld('Company contact number', 'c_phone', app.company.phone, 'required data-phone') + fld('Nature of business', 'c_natureOfBusiness', app.company.natureOfBusiness, 'required', 'full') + '<div class="field full"><label>Business description</label><textarea class="input" name="c_description" required minlength="20">' + esc(app.company.description) + '</textarea></div>' + fld('Website or social media page (optional)', 'c_website', app.company.website, '', 'full') + '</div>' +
            '<h3 class="mt-2">Authorized representative</h3><p class="small muted">The person submitting this application. Once approved, they become the <b>Organization Admin</b> of the supplier account.</p><div class="form-grid">' + fld('Full name', 'r_name', app.representative.name) + fld('Position / designation', 'r_position', app.representative.position) + '<div class="field"><label>Government ID type</label><select class="input" name="r_idType">' + IDS.map(x => '<option' + (x === app.representative.idType ? ' selected' : '') + '>' + x + '</option>').join('') + '</select></div>' + fld('Official email', 'r_email', app.representative.email, 'type="email" required') + fld('Contact number', 'r_phone', app.representative.phone, 'required data-phone') + '</div>'
          : '<h3>Personal information</h3><div class="form-grid">' + fld('Full name', 'p_fullName', app.personal.fullName, 'required minlength="3"') + fld('Date of birth', 'p_birthDate', app.personal.birthDate, 'type="date" required max="' + U.addDays(U.today(), -365 * 18) + '"') + fld('Complete address', 'p_address', app.personal.address, 'required minlength="8"', 'full') + fld('Contact number', 'p_phone', app.personal.phone, 'required data-phone') + fld('Email address', 'p_email', app.personal.email, 'type="email" required') + '</div><h3 class="mt-2">Identity verification</h3><div class="form-grid"><div class="field"><label>Government-issued ID</label><select class="input" name="i_idType">' + IDS.map(x => '<option' + (x === app.identity.idType ? ' selected' : '') + '>' + x + '</option>').join('') + '</select></div>' + fld('ID number', 'i_idNumber', app.identity.idNumber) + '</div><p class="small muted mb-0">Upload a clear photo of the ID and a selfie holding it in the next step.</p>';
        case 2: {
          const reqs = Services.applicationRequirements(app.type, app.company.companyType);
          const groups = Array.from(new Set(reqs.map(q => q.group)));
          const tag = q => ({ required: '<span class="badge b-red plain">Required</span>', conditional: '<span class="badge b-gold plain">If applicable</span>', recommended: '<span class="badge b-sky plain">Recommended</span>', optional: '<span class="badge b-gray plain">Optional</span>' }[q.need]);
          return '<p class="muted small">Upload clear scans or photos (PDF, JPG or PNG). Mark "If applicable" documents that apply to your business — they then become required. <b>Demo:</b> only the file name is kept in your browser; files are not uploaded anywhere.</p>' + groups.map(g => '<h3 class="mt-2">' + esc(g) + '</h3>' + reqs.filter(q => q.group === g).map(q => { const d = app.docs[q.key]; return '<div class="doc-row"><div class="grow"><b>' + esc(q.label) + '</b> ' + tag(q) + (q.note ? '<div class="small muted">' + esc(q.note) + '</div>' : '') + (q.need === 'conditional' ? '<label class="check small mt-1"><input type="checkbox" data-applies="' + q.key + '"' + (app.applicable.includes(q.key) ? ' checked' : '') + '> This applies to my business</label>' : '') + '</div><div class="row" style="gap:6px">' + (d ? '<span class="badge b-green">' + I('check').replace('<svg', '<svg style="width:12px;height:12px"') + ' ' + esc(d.name) + '</span><button type="button" class="btn btn-xs btn-ghost" data-rmdoc="' + q.key + '">' + I('trash') + '</button>' : '<label class="btn btn-sm" for="doc_' + q.key + '">' + I('upload') + 'Upload</label>') + '<input type="file" id="doc_' + q.key + '" data-doc="' + q.key + '" accept="image/*,.pdf" hidden></div></div>'; }).join('')).join('');
        }
        case 3: return '<div class="field"><label>' + (org() ? 'Shop / brand name on PartyRent' : 'Business or rental shop name') + '</label><input class="input" name="name" required minlength="3" value="' + esc(data.name || (org() ? app.company.legalName : '')) + '" placeholder="e.g. Santos Party Rentals"></div><div class="field"><label>Shop description</label><textarea class="input" name="description" required minlength="20" placeholder="What do you rent out, and for which events?">' + esc(data.description || (org() ? app.company.description : '')) + '</textarea></div>' + (org() ? '' : '<div class="field"><label>Types of rental products offered</label><input class="input" name="b_productTypes" required value="' + esc(app.business.productTypes) + '" placeholder="e.g. chairs, tables, tents, sound system"></div>') +
          '<div class="form-grid"><div class="field"><label>Business contact number</label><input class="input" name="phone" data-phone required value="' + esc(data.phone) + '"></div><div class="field"><label>Business email</label><input class="input" type="email" name="email" required value="' + esc(data.email) + '"></div><div class="field"><label>City / municipality</label><select class="input" name="city" required><option value="">Select…</option>' + cities.map(c => '<option' + (c === data.city ? ' selected' : '') + '>' + c + '</option>').join('') + '</select></div><div class="field"><label>Operating hours</label><input class="input" name="hours" required value="' + esc(data.hours) + '"></div><div class="field full"><label>Business address / operating location</label><input class="input" name="address" required minlength="8" value="' + esc(data.address) + '"></div></div>' +
          '<div class="field"><label>Business logo</label><div class="row wrap gap-sm">' + LOGOS.map(l => '<button type="button" class="btn btn-icon' + (l === data.logo && !data.logoImg ? ' btn-outline' : '') + '" data-logo="' + l + '" style="font-size:1.3rem">' + l + '</button>').join('') + '<label class="btn btn-sm" for="logoUp">' + I('upload') + 'Upload</label><input type="file" id="logoUp" accept="image/*" hidden></div>' + (data.logoImg ? '<div class="thumbs"><img src="' + data.logoImg + '" alt="Logo"></div>' : '') + '</div>';
        case 4: return '<div class="stack-sm">' + [['delivery', 'Delivery only', 'You bring items to the customer.'], ['pickup', 'Pickup only', 'Customers collect from your location.'], ['both', 'Both delivery and pickup', 'Let customers choose.']].map(([v, l, d]) => '<label class="card card-flat row" style="padding:12px 14px;cursor:pointer"><input type="radio" name="fulfillment" value="' + v + '"' + (data.fulfillment === v ? ' checked' : '') + ' style="accent-color:var(--brand);width:18px;height:18px"><div><b>' + l + '</b><div class="small muted">' + d + '</div></div></label>').join('') + '</div><div class="field mt-2" id="dfee"><label>Standard delivery fee (₱ per delivery location)</label><input class="input" type="number" min="0" name="deliveryFee" value="' + data.deliveryFee + '"><div class="hint">You can add delivery zones with different rates later in Shop Settings.</div></div>' +
          '<div class="field"><label>Accepted payment methods</label>' + chk('methods', 'cod', 'Cash on Delivery', data.methods) + chk('methods', 'bank', 'Online Bank Transfer', data.methods) + chk('methods', 'debit', 'Debit', data.methods) + '</div>' +
          '<div class="field"><label>Payment plans you offer</label>' + Finance.PLANS.map(k => chk('offerPlans', k, Finance.PLAN_LABEL[k] + ' <span class="small muted">— ' + esc(Finance.planSummary(null, k)) + '</span>', data.offerPlans)).join('') + '<div class="hint">Default percentages and deadlines can be changed in Shop Settings after approval.</div></div>' +
          '<div class="field"><label>Security deposit (refundable)</label><div class="row" style="gap:6px"><select class="input" name="depositType" style="width:120px"><option value="fixed"' + (p.depositType === 'fixed' ? ' selected' : '') + '>₱ fixed</option><option value="percent"' + (p.depositType === 'percent' ? ' selected' : '') + '>% of rental fee</option></select><input class="input" type="number" min="0" name="depositValue" value="' + p.depositValue + '"></div></div>';
        case 5: return '<p class="muted small">Where PartyRent sends your payouts.' + (org() ? ' Use the company\'s own account, registered in the company name.' : '') + '</p><div class="form-grid"><div class="field"><label>Preferred payout method</label><select class="input" name="o_method"><option value="bank"' + (app.payout.method === 'bank' ? ' selected' : '') + '>Bank account</option><option value="ewallet"' + (app.payout.method === 'ewallet' ? ' selected' : '') + '>E-wallet (GCash / Maya)</option></select></div>' + fld('Bank or e-wallet', 'o_provider', app.payout.provider, 'required placeholder="e.g. BDO, GCash"') + fld(org() ? "Account holder's registered company name" : 'Account holder name', 'o_accountName', app.payout.accountName || (org() ? app.company.legalName : app.personal.fullName)) + fld('Account / mobile number', 'o_accountNumber', app.payout.accountNumber) + (org() ? fld('Person responsible for financial transactions', 'o_financeContact', app.payout.financeContact || app.representative.name, 'required', 'full') : '') + '</div>';
        case 6: return '<div class="field"><label>Free cancellation window (days before rental)</label><input class="input" type="number" min="0" max="30" name="cancelDays" value="' + p.cancelDays + '"></div><div class="field"><label>Cancellation policy</label><textarea class="input" name="cancellation" required>' + esc(p.cancellation) + '</textarea></div><div class="field"><label>Return policy</label><textarea class="input" name="returns" required>' + esc(p.returns) + '</textarea></div><div class="field"><label>Rental conditions</label><textarea class="input" name="conditions" required>' + esc(p.conditions) + '</textarea></div>';
        case 7: {
          const miss = Services.missingDocs(app);
          const reqs = Services.applicationRequirements(app.type, app.company.companyType);
          return '<div class="row mb-2"><div class="logo-tile" style="background:var(--brand-50)">' + (data.logoImg ? '<img src="' + data.logoImg + '" style="width:100%;height:100%;object-fit:cover;border-radius:14px" alt="">' : esc(data.logo)) + '</div><div><h2 class="mb-0">' + esc(data.name) + '</h2><div class="muted small">' + esc(Services.SUPPLIER_TYPES[app.type]) + ' · ' + esc(data.city) + ' · ' + UI.fulfillLabel(data.fulfillment) + '</div></div></div>' +
            '<dl class="kv">' + (org() ? '<dt>Company</dt><dd>' + esc(app.company.legalName) + ' (' + esc(Services.COMPANY_TYPES[app.company.companyType]) + ') · ' + esc(app.company.branches) + ' location(s)</dd><dt>Representative</dt><dd>' + esc(app.representative.name) + ', ' + esc(app.representative.position) + ' · ' + esc(app.representative.email) + '</dd>' : '<dt>Applicant</dt><dd>' + esc(app.personal.fullName) + ' · born ' + (app.personal.birthDate ? U.fmtDate(app.personal.birthDate) : '—') + '</dd><dt>ID</dt><dd>' + esc(app.identity.idType) + ' · ' + esc(app.identity.idNumber) + '</dd>') +
            '<dt>Shop contact</dt><dd>' + esc(data.phone) + ' · ' + esc(data.email) + '</dd><dt>Address</dt><dd>' + esc(data.address) + '</dd><dt>Payment plans</dt><dd>' + data.offerPlans.map(k => Finance.PLAN_LABEL[k]).join(', ') + '</dd><dt>Methods</dt><dd>' + data.methods.map(m => Finance.METHOD_LABEL[m]).join(', ') + '</dd><dt>Payout</dt><dd>' + esc(app.payout.provider) + ' · ' + esc(app.payout.accountName) + ' · ' + esc(app.payout.accountNumber) + '</dd><dt>Documents</dt><dd>' + reqs.filter(q => app.docs[q.key]).map(q => '<span class="tag">' + esc(q.label) + '</span>').join(' ') + '</dd></dl>' +
            (miss.length ? '<div class="alert alert-danger mt-2">' + I('alert') + '<div><b>Missing required documents:</b> ' + miss.map(m => esc(m.label)).join(', ') + '. Go back to Documents to upload them.</div></div>' : '<div class="alert alert-info mt-2">' + I('info') + '<div>PartyRent verifies your information and documents. Your application will be <b>Pending Review</b> until an administrator approves it, rejects it or asks for corrections. You keep full access to your customer account meanwhile.</div></div>');
        }
      }
    }
    function read() {
      const f = $('#wf'); const d = UI.formData(f);
      const take = (prefix, obj) => Object.keys(d).filter(k => k.startsWith(prefix)).forEach(k => { obj[k.slice(prefix.length)] = typeof d[k] === 'string' ? d[k].trim() : d[k]; });
      if (step === 1) { if (org()) { take('c_', app.company); take('r_', app.representative); app.company.branches = Number(app.company.branches) || 1; } else { take('p_', app.personal); take('i_', app.identity); } }
      if (step === 2) app.applicable = $$('[data-applies]:checked').map(x => x.dataset.applies);
      if (step === 3) { Object.assign(data, { name: d.name.trim(), description: d.description.trim(), phone: d.phone, email: d.email, city: d.city, hours: d.hours, address: d.address }); if (!org()) { app.business.productTypes = d.b_productTypes; app.business.shopName = d.name.trim(); app.business.address = d.address; app.business.phone = d.phone; } }
      if (step === 4) { Object.assign(data, { fulfillment: d.fulfillment, deliveryFee: Number(d.deliveryFee) || 0, methods: d.methods || [], offerPlans: d.offerPlans || [] }); Object.assign(data.policy, { depositType: d.depositType, depositValue: Number(d.depositValue) || 0 }); }
      if (step === 5) take('o_', app.payout);
      if (step === 6) Object.assign(data.policy, { cancelDays: Number(d.cancelDays) || 0, cancellation: d.cancellation, returns: d.returns, conditions: d.conditions });
    }
    function extra() {
      if (step === 0 && !app.type) return 'Choose Individual Supplier or Organization / Company Supplier.';
      if (step === 2) { const miss = Services.missingDocs(app); if (miss.length) return 'Please upload: ' + miss.map(m => m.label).join(', ') + '.'; }
      if (step === 4) { if (!data.methods.length) return 'Select at least one payment method.'; if (!data.offerPlans.length) return 'Offer at least one payment plan.'; }
      return null;
    }
    function draw() {
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Apply as a Supplier</h1><p>Join PartyRent and start offering your rental products — using your existing account. ' + (existing && existing.status === 'rejected' ? '<span class="badge b-red">Previous application rejected: ' + esc(existing.rejectReason || '') + '</span>' : existing && existing.status === 'revision' ? '<span class="badge b-purple">Requires revision: ' + esc(existing.revisionNotes || '') + '</span>' : '') + '</p></div></div>' +
        '<div class="wizard-steps">' + STEPS.map((t, i) => '<div class="ws ' + (i === step ? 'on' : i < step ? 'done' : '') + '"><span>Step ' + (i + 1) + '</span>' + t + '</div>').join('') + '</div>' +
        '<form class="card" id="wf" novalidate style="max-width:820px"><h2>' + STEPS[step] + (step && app.type ? ' <span class="badge ' + (org() ? 'b-purple' : 'b-pink') + ' plain">' + esc(Services.SUPPLIER_TYPES[app.type]) + '</span>' : '') + '</h2>' + body() + '<div class="row between mt-3"><button type="button" class="btn" id="back"' + (step === 0 ? ' disabled' : '') + '>' + I('left') + 'Back</button><button class="btn btn-primary" id="next">' + (step === STEPS.length - 1 ? (existing ? 'Resubmit application' : 'Submit application') : 'Continue') + I('right') + '</button></div></form>';
      const f = $('#wf');
      $$('[data-type]').forEach(b => b.onclick = () => { if (app.type && app.type !== b.dataset.type) { app.docs = {}; app.applicable = []; } app.type = b.dataset.type; draw(); });
      $$('[data-logo]').forEach(b => b.onclick = () => { read(); data.logo = b.dataset.logo; delete data.logoImg; draw(); });
      const up = $('#logoUp'); if (up) up.onchange = async e => { try { read(); data.logoImg = await U.readImage(e.target.files[0], 240); draw(); } catch (err) { UI.toast(err.message, 'error'); } };
      $$('[data-doc]').forEach(inp => inp.onchange = () => { const file = inp.files[0]; if (!file) return; if (file.size > 10 * 1024 * 1024) return UI.toast('Files must be 10 MB or smaller.', 'error'); read(); app.docs[inp.dataset.doc] = { name: file.name, size: file.size, uploadedAt: new Date().toISOString() }; draw(); });
      $$('[data-rmdoc]').forEach(b => b.onclick = () => { read(); delete app.docs[b.dataset.rmdoc]; draw(); });
      $$('[data-applies]').forEach(c => c.onchange = () => { read(); draw(); });
      const df = $('#dfee'); if (df) { const sync = () => df.classList.toggle('hidden', (f.fulfillment && f.fulfillment.value) === 'pickup'); $$('[name=fulfillment]').forEach(r => r.onchange = sync); sync(); }
      $('#back').onclick = () => { read(); step--; draw(); };
      f.onsubmit = async e => {
        e.preventDefault();
        if (!UI.validate(f)) return;
        read(); const x = extra(); if (x) return UI.toast(x, 'error');
        if (step < STEPS.length - 1) { step++; draw(); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
        const plans = JSON.parse(JSON.stringify(Finance.DEFAULT_PLANS)); Finance.PLANS.forEach(k => { plans[k].enabled = data.offerPlans.includes(k); });
        const payload = Object.assign({}, data, { type: app.type, paymentPlans: plans, timeSettings: pre.timeSettings || Object.assign({}, Slots.DEFAULT), deliveryZones: pre.deliveryZones || [], services: pre.services || [], damagePolicy: pre.damagePolicy || Services.damagePolicy(null), application: JSON.parse(JSON.stringify(app)) });
        if (app.type === 'organization') payload.company = { legalName: app.company.legalName, companyType: app.company.companyType };
        delete payload.offerPlans; delete payload.status; delete payload.rejectReason; delete payload.revisionNotes;
        const err = Services.validateApplication(payload.application); if (err) return UI.toast(err, 'error');
        if (!await UI.confirm({ title: (existing ? 'Resubmit' : 'Submit') + ' supplier application?', message: 'Your ' + Services.SUPPLIER_TYPES[app.type] + ' profile will be linked to this account (' + u.email + ')' + (org() ? ' and you will become its Organization Admin once approved' : '') + '. You can keep renting as a customer.', confirmText: existing ? 'Resubmit' : 'Submit' })) return;
        const res = Services.submitApplication(u.id, payload);
        if (!res.ok) return UI.toast(res.error, 'error');
        UI.toast(res.supplier.status === 'approved' ? 'Your shop is approved!' : 'Application submitted for review.', 'success');
        statusView(res.supplier);
      };
    }
    function statusView(sp) {
      const map = { pending: ['🕒', 'b-gold', 'An administrator is verifying your information and documents. You will be notified once a decision is made. Meanwhile, you can keep renting as a customer.'], approved: ['🎉', 'b-green', 'Your shop is live. Switch to Supplier Mode to add products, packages and manage requests.' + (sp.type === 'organization' ? ' As Organization Admin you can add your cashier and staff under Team & Roles.' : '')], rejected: ['❌', 'b-red', 'Reason: ' + (sp.rejectReason || '—') + '. You may update your details and resubmit.'], revision: ['📝', 'b-purple', 'An administrator asked for changes: ' + (sp.revisionNotes || '—') + ' Update your application and resubmit it.'] };
      const [em, cls, txt] = map[sp.status];
      ctx.el.innerHTML = '<div class="card center" style="max-width:640px;margin:20px auto;padding:34px 24px"><div style="font-size:3.2rem">' + em + '</div><h1>Supplier application</h1><div class="row wrap" style="justify-content:center;gap:10px"><span class="logo-tile" style="width:44px;height:44px;font-size:1.4rem;background:var(--brand-50)">' + esc(sp.logo) + '</span><b>' + esc(sp.name) + '</b><span class="badge ' + cls + '">' + Services.APP_STATUS[sp.status][0] + '</span><span class="badge b-gray plain">' + esc(Services.SUPPLIER_TYPES[sp.type] || 'Supplier') + '</span></div><p class="mt-2">' + esc(txt) + '</p><p class="small muted">Submitted ' + U.fmtDateTime(sp.appliedAt) + (sp.reviewedAt ? ' · Reviewed ' + U.fmtDateTime(sp.reviewedAt) : '') + '</p>' +
        '<div class="row wrap mt-2" style="justify-content:center">' + (sp.status === 'approved' ? '<button class="btn btn-primary" data-switch="supplier">' + I('swap') + 'Switch to Supplier Mode</button>' : '') + (['rejected', 'revision'].includes(sp.status) ? '<a class="btn btn-primary" href="' + url('customer/start-selling.html', { edit: 1 }) + '">Update & resubmit</a>' : '') + (sp.status !== 'approved' ? '<a class="btn" href="' + url('customer/supplier.html', { id: sp.id }) + '">Preview shop page</a>' : '') + '<a class="btn btn-ghost" href="' + url('index.html') + '">Back to marketplace</a></div></div>';
    }
    draw();
  };
})();
