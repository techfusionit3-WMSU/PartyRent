/* =========================================================
   PartyRent — Cashier workspace
   Monitor, verify and record payments; issue receipts.
   Cashiers cannot edit prices, inventory or settings.
   ========================================================= */
(function () {
  'use strict';
  const { esc, url, $, $$ } = U;
  const I = Icons.icon;
  const CS = Pages.cashier = {};
  const Sh = () => Pages.shared;

  // A cashier works for ONE supplier organization and only ever sees its records.
  const org = () => Auth.session().user.supplierId;
  const minePay = p => { const r = Store.request(p.requestId); return !!r && r.supplierId === org(); };
  const payments = () => Store.all('payments').filter(minePay);
  const payRow = p => { const r = Store.request(p.requestId); return { p, r, c: Store.user(r.customerId), s: Store.supplier(r.supplierId) }; };
  const cols = (extra) => [
    { label: 'Transaction', render: x => '<b>' + x.p.id + '</b><div class="small muted">' + U.fmtDateTime(x.p.submittedAt) + '</div>' },
    { label: 'Customer', render: x => esc(x.c.name) },
    { label: 'Rental / Supplier', render: x => x.r.id + '<div class="small muted">' + esc(x.s.name) + '</div>' },
    { label: 'Type', render: x => Finance.TYPE_LABEL[x.p.type] },
    { label: 'Method · Ref', render: x => (Finance.METHOD_LABEL[x.p.method] || x.p.method) + '<div class="small muted">' + esc(x.p.reference || '—') + '</div>' },
    { label: 'Amount', cls: 'num', render: x => '<b>' + U.money(x.p.amount) + '</b>' },
    { label: 'Status', render: x => UI.verBadge(x.p.status) }
  ].concat(extra || []);

  CS.dashboard = function (ctx) {
    const T = U.today();
    const pays = payments();
    const today = pays.filter(p => (p.verifiedAt || '').slice(0, 10) === T && p.status === 'verified');
    const pend = pays.filter(p => p.status === 'pending');
    const fins = Store.where('requests', r => r.supplierId === org() && Finance.ACTIVE.includes(r.status)).map(r => ({ r, f: Finance.summary(r) }));
    const overdue = fins.filter(x => x.f.isOverdue);
    const partial = fins.filter(x => x.f.status === 'Partially Paid');
    const receipts = pays.filter(p => p.receiptNo).sort((a, b) => (b.verifiedAt || '').localeCompare(a.verifiedAt || ''));
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Payment overview</h1><p>' + U.fmtDate(T) + ' · Cashier ' + esc(ctx.session.user.name) + ' · <b>' + esc(Store.supplier(org()).name) + '</b></p></div><div class="row wrap"><a class="btn" href="' + url('cashier/record.html') + '">' + I('cash') + 'Record payment</a><a class="btn btn-primary" href="' + url('cashier/verification.html') + '">' + I('check') + 'Verify payments (' + pend.length + ')</a></div></div>' +
      '<div class="stats mb-3">' + UI.stat("Today's verified payments", U.moneyShort(U.sum(today, p => p.amount)), today.length + ' transactions', 'check', 'teal') + UI.stat('Pending verification', pend.length, U.moneyShort(U.sum(pend, p => p.amount)), 'clock', 'gold', url('cashier/verification.html')) + UI.stat('Total verified', U.moneyShort(U.sum(pays.filter(p => p.status === 'verified' && Finance.RENTAL_TYPES.includes(p.type)), p => p.amount)), 'all rental payments', 'card', 'sky', url('cashier/history.html')) + UI.stat('Partial payments', partial.length, U.moneyShort(U.sum(partial, x => x.f.balance)) + ' remaining', 'cash', 'purple', url('cashier/balances.html')) + UI.stat('Overdue balances', overdue.length, U.moneyShort(U.sum(overdue, x => x.f.overdueAmount)) + ' overdue', 'alert', 'red', url('cashier/balances.html', { tab: 'overdue' })) + UI.stat('Reservation fees (verified)', U.moneyShort(U.sum(pays.filter(p => p.status === 'verified' && p.type === 'reservation'), p => p.amount)), '', 'tag', 'pink') + UI.stat('Down payments (verified)', U.moneyShort(U.sum(pays.filter(p => p.status === 'verified' && p.type === 'down'), p => p.amount)), '', 'tag', 'purple') + UI.stat('Deposits held', U.moneyShort(U.sum(Store.where('requests', r => r.supplierId === org()), r => Finance.summary(r).depositHeld)), 'refundable', 'shield', 'sky') + '</div>' +
      '<div class="grid grid-2"><div class="card"><div class="card-head"><h3>Pending verifications</h3><a class="small strong" href="' + url('cashier/verification.html') + '">Open queue</a></div>' + (pend.length ? pend.map(payRow).map(x => '<div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line)"><div><b>' + U.money(x.p.amount) + '</b> · ' + Finance.TYPE_LABEL[x.p.type] + '<div class="small muted">' + esc(x.c.name) + ' · ' + x.r.id + ' · ' + esc(x.s.name) + '</div></div><button class="btn btn-sm btn-primary" data-review="' + x.p.id + '">Review</button></div>').join('') : '<p class="muted">Queue is clear 🎉</p>') + '</div>' +
      '<div class="card"><div class="card-head"><h3>Overdue accounts</h3><a class="small strong" href="' + url('cashier/balances.html', { tab: 'overdue' }) + '">View all</a></div>' + (overdue.length ? overdue.map(x => '<div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line)"><div><b>' + x.r.id + '</b> · ' + esc(Store.user(x.r.customerId).name) + '<div class="small muted">' + esc(Store.supplier(x.r.supplierId).name) + ' · ' + esc((x.f.schedule.find(s => s.state === 'overdue') || {}).label || '') + '</div></div><b style="color:var(--red)">' + U.money(x.f.overdueAmount) + '</b></div>').join('') : '<p class="muted">No overdue accounts.</p>') + '</div>' +
      '<div class="card"><h3>Payments by method (verified)</h3><div id="ch1"></div></div><div class="card"><div class="card-head"><h3>Recent receipts</h3><a class="small strong" href="' + url('cashier/receipts.html') + '">All receipts</a></div>' + receipts.slice(0, 6).map(p => '<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--line)"><div><b>' + p.receiptNo + '</b> · ' + U.money(p.amount) + '<div class="small muted">' + p.requestId + ' · ' + U.fmtDate(p.date) + '</div></div><button class="btn btn-xs" data-receipt="' + p.id + '">View</button></div>').join('') + '</div></div>';
    const by = {}; pays.filter(p => p.status === 'verified' && Finance.RENTAL_TYPES.includes(p.type)).forEach(p => { const k = Finance.METHOD_LABEL[p.method] || p.method; by[k] = (by[k] || 0) + p.amount; });
    Charts.donut($('#ch1'), Object.entries(by).map(([label, value]) => ({ label, value })), { money: true, center: 'verified' });
    bindReview(ctx, () => CS.dashboard(ctx));
  };

  function bindReview(ctx, redraw) {
    $$('[data-review]').forEach(b => b.onclick = () => reviewModal(Store.get('payments', b.dataset.review), redraw));
    $$('[data-receipt]').forEach(b => b.onclick = () => Sh().showReceipt(Store.get('payments', b.dataset.receipt)));
  }
  function reviewModal(p, redraw) { Sh().reviewPayment(p, redraw); }

  CS.verification = function (ctx) {
    let q = '';
    const draw = () => {
      const list = payments().filter(p => p.status === 'pending').map(payRow).filter(x => !q || (x.p.id + ' ' + x.p.reference + ' ' + x.c.name + ' ' + x.r.id + ' ' + x.s.name).toLowerCase().includes(q)).sort((a, b) => a.p.submittedAt.localeCompare(b.p.submittedAt));
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Payment Verification</h1><p>Review payment proof and reference numbers. Unverified submissions never count toward balances.</p></div><input class="input input-sm" id="q" placeholder="Search name, ref, request" style="width:240px" value="' + esc(q) + '" aria-label="Search"></div><div class="card pad-0">' + UI.table(cols([{ label: 'Proof', render: x => x.p.proof ? '<img src="' + x.p.proof + '" style="width:44px;height:44px;object-fit:cover;border-radius:8px" alt="proof">' : x.p.proofName ? '<span class="small">' + I('file').replace('<svg', '<svg style="width:14px;vertical-align:-2px"') + ' ' + esc(x.p.proofName) + '</span>' : '<span class="small muted">' + (x.p.recordedBy === 'supplier' ? 'Recorded by supplier' : 'None') + '</span>' }, { label: '', render: x => '<button class="btn btn-sm btn-primary" data-review="' + x.p.id + '">Review</button>' }]), list, { emptyText: 'No payments waiting for verification.' }) + '</div>';
      const qi = $('#q'); qi.oninput = e => { q = e.target.value.toLowerCase(); const pos = qi.selectionStart; draw(); const n = $('#q'); n.focus(); n.setSelectionRange(pos, pos); };
      bindReview(ctx, draw);
    };
    draw();
  };

  CS.record = function (ctx) {
    const reqs = Store.where('requests', r => r.supplierId === org() && (Finance.ACTIVE.includes(r.status) || r.status === 'completed' || r.status === 'cancelled')).sort((a, b) => b.createdAt.localeCompare(a.createdAt));
    let sel = ctx.params.request || '';
    const draw = () => {
      const r = sel ? Store.request(sel) : null; const f = r ? Finance.summary(r) : null;
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Record Payment</h1><p>Record authorized offline payments (cash at the office, bank deposit) or refunds. Recorded payments are verified immediately and a receipt is issued.</p></div></div><div class="grid grid-side"><form class="card" id="rf" novalidate><div class="field"><label>Rental request</label><select class="input" name="request" id="rs" required><option value="">Select a rental request…</option>' + reqs.map(x => '<option value="' + x.id + '"' + (x.id === sel ? ' selected' : '') + '>' + x.id + ' · ' + esc(Store.user(x.customerId).name) + ' · ' + esc(Store.supplier(x.supplierId).name) + '</option>').join('') + '</select></div>' +
        (r ? '<div class="form-grid"><div class="field"><label>Payment type</label><select class="input" name="type">' + ['reservation', 'down', 'installment', 'balance', 'full', 'deposit', 'compensation', 'refund'].map(t => '<option value="' + t + '"' + (t === Finance.suggestedNext(r).type ? ' selected' : '') + '>' + Finance.TYPE_LABEL[t] + '</option>').join('') + '</select></div><div class="field"><label>Amount (₱)</label><input class="input" type="number" step="0.01" min="1" name="amount" required value="' + (Finance.suggestedNext(r).amount || '') + '"></div><div class="field"><label>Method</label><select class="input" name="method"><option value="cash">Cash (over the counter)</option><option value="bank">Bank deposit / transfer</option><option value="debit">Debit</option></select></div><div class="field"><label>Payment date</label><input class="input" type="date" name="date" value="' + U.today() + '" max="' + U.today() + '" required></div><div class="field full"><label>Official reference / OR no.</label><input class="input" name="reference" required placeholder="e.g. CASH-1023"></div><div class="field full"><label>Notes</label><input class="input" name="notes" placeholder="Received by front desk…"></div></div><button class="btn btn-primary btn-lg">' + I('check') + 'Record & issue receipt</button>' : '<p class="muted">Choose a request to see its balance.</p>') + '</form>' +
        '<aside class="card">' + (r ? '<div class="small muted">Customer</div><b>' + esc(Store.user(r.customerId).name) + '</b><div class="small muted mt-1">Supplier</div><b>' + esc(Store.supplier(r.supplierId).name) + '</b><hr>' + Sh().paymentSummary(r) : '<p class="muted">Balance details appear here.</p>') + '</aside></div>';
      $('#rs').onchange = e => { sel = e.target.value; draw(); };
      const fm = $('#rf');
      if (r) {
        fm.type.onchange = () => { const t = fm.type.value; fm.amount.value = t === 'deposit' ? U.round2(f.depositRequired - f.depositPaid) : t === 'compensation' ? f.compensationDue : t === 'refund' ? U.round2(f.paidRental - f.refunded) : t === 'balance' ? U.round2(f.balance - f.pendingRental) : Finance.suggestedNext(r).amount; };
        fm.onsubmit = async e => { e.preventDefault(); if (!UI.validate(fm)) return; const d = UI.formData(fm); if (!await UI.confirm({ title: 'Record ' + Finance.TYPE_LABEL[d.type].toLowerCase() + '?', html: '<b>' + U.money(+d.amount) + '</b> for ' + r.id + ' via ' + (Finance.METHOD_LABEL[d.method] || d.method) + '. This is recorded as verified and added to the audit trail.', confirmText: 'Record' })) return; const res = Services.cashierRecord(r.id, Object.assign(d, { amount: +d.amount })); if (!res.ok) return UI.toast(res.error, 'error'); UI.toast('Recorded · receipt ' + res.payment.receiptNo, 'success'); Sh().showReceipt(res.payment); draw(); };
      }
    };
    draw();
  };

  CS.balances = function (ctx) {
    let tab = ctx.params.tab || 'installments';
    const draw = () => {
      const fins = Store.where('requests', r => r.supplierId === org() && Finance.ACTIVE.includes(r.status)).map(r => ({ r, f: Finance.summary(r) }));
      const lists = { installments: fins.filter(x => x.r.plan === 'installment' || x.r.plan === 'standard'), balances: fins.filter(x => x.f.balance > 0), overdue: fins.filter(x => x.f.isOverdue) };
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Installments & Overdue</h1><p>Monitor payment schedules and outstanding balances. Overdue rentals are never cancelled automatically — the supplier or an admin decides.</p></div></div>' + UI.tabs([{ key: 'installments', label: 'Installment schedules', count: lists.installments.length }, { key: 'balances', label: 'Remaining balances', count: lists.balances.length }, { key: 'overdue', label: 'Overdue', count: lists.overdue.length }], tab) + '<div id="bl"></div>';
      $$('[data-tab]').forEach(t => t.onclick = () => { tab = t.dataset.tab; draw(); });
      const list = lists[tab];
      if (tab === 'installments') $('#bl').innerHTML = list.length ? '<div class="grid grid-2">' + list.map(x => '<div class="card"><div class="row between mb-1"><div><b>' + x.r.id + '</b> · ' + esc(Store.user(x.r.customerId).name) + '<div class="small muted">' + esc(Store.supplier(x.r.supplierId).name) + ' · ' + Finance.PLAN_LABEL[x.r.plan] + '</div></div>' + UI.payBadge(x.f.status) + '</div>' + x.f.schedule.map(s => '<div class="sum-line small"><span>' + (s.state === 'paid' ? '✅' : s.state === 'overdue' ? '⚠️' : '🕒') + ' ' + esc(s.label) + ' · ' + U.fmtDate(s.due) + '</span><b style="color:' + (s.state === 'overdue' ? 'var(--red)' : '') + '">' + U.money(s.amount) + '</b></div>').join('') + '<div class="progress mt-1"><span style="width:' + Math.min(100, x.f.paidRental / x.f.payable * 100) + '%"></span></div><div class="row between mt-1 small"><span>' + U.money(x.f.paidRental) + ' of ' + U.money(x.f.payable) + '</span><a href="' + url('cashier/record.html', { request: x.r.id }) + '">Record payment →</a></div></div>').join('') + '</div>' : '<div class="card">' + UI.empty('📆', 'No installment plans', '') + '</div>';
      else $('#bl').innerHTML = '<div class="card pad-0">' + UI.table([{ label: 'Request', render: x => '<b>' + x.r.id + '</b><div class="small muted">' + esc(x.r.eventName) + '</div>' }, { label: 'Customer', render: x => esc(Store.user(x.r.customerId).name) + '<div class="small muted">' + esc(Store.user(x.r.customerId).phone) + '</div>' }, { label: 'Supplier', render: x => esc(Store.supplier(x.r.supplierId).name) }, { label: 'Total', cls: 'num', render: x => U.money(x.f.payable) }, { label: 'Paid', cls: 'num', render: x => U.money(x.f.paidRental) }, { label: tab === 'overdue' ? 'Overdue amount' : 'Balance', cls: 'num', render: x => '<b style="color:' + (tab === 'overdue' ? 'var(--red)' : '') + '">' + U.money(tab === 'overdue' ? x.f.overdueAmount : x.f.balance) + '</b>' }, { label: 'Due', render: x => { const s = tab === 'overdue' ? x.f.schedule.find(y => y.state === 'overdue') : x.f.next; return s ? esc(s.label) + '<div class="small muted">' + U.fmtDate(s.due) + (tab === 'overdue' ? ' · ' + U.diffDays(s.due, U.today()) + ' days late' : '') + '</div>' : '—'; } }, { label: 'Status', render: x => UI.payBadge(x.f.status) }, { label: '', render: x => '<div class="btn-group"><a class="btn btn-xs" href="' + url('cashier/record.html', { request: x.r.id }) + '">Record</a>' + (tab === 'overdue' ? '<button class="btn btn-xs btn-ghost" data-remind="' + x.r.id + '">Send reminder</button>' : '') + '</div>' }], list.sort((a, b) => b.f.balance - a.f.balance), { emptyText: 'Nothing here.' }) + '</div>';
      $$('[data-remind]').forEach(b => b.onclick = () => { const r = Store.request(b.dataset.remind); const f = Finance.summary(r); Store.notify(r.customerId, 'customer', 'Payment reminder', 'Your payment of ' + U.money(f.overdueAmount) + ' for ' + r.id + ' is overdue. Please settle it soon to keep your reservation.', 'customer/rental.html?id=' + r.id); Store.audit('Sent overdue reminder', r.id + ' · ' + U.money(f.overdueAmount)); UI.toast('Reminder sent to ' + Store.user(r.customerId).name + '.', 'success'); });
    };
    draw();
  };

  CS.history = function (ctx) {
    let q = '', st = '', type = '', from = '', to = '';
    const draw = () => {
      const list = payments().map(payRow).filter(x => (!st || x.p.status === st) && (!type || x.p.type === type) && (!from || x.p.date >= from) && (!to || x.p.date <= to) && (!q || (x.p.id + ' ' + x.p.reference + ' ' + x.c.name + ' ' + x.r.id + ' ' + x.s.name + ' ' + (x.p.receiptNo || '')).toLowerCase().includes(q))).sort((a, b) => (b.p.submittedAt || '').localeCompare(a.p.submittedAt || ''));
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Payment History</h1><p>' + list.length + ' records · ' + U.money(U.sum(list.filter(x => x.p.status === 'verified'), x => x.p.amount)) + ' verified</p></div></div><div class="card mb-2"><div class="row wrap"><input class="input input-sm" id="q" placeholder="Search ID, ref, customer, OR no." style="width:240px" value="' + esc(q) + '" aria-label="Search"><select class="input input-sm" id="st" style="width:auto" aria-label="Status"><option value="">All statuses</option><option value="pending"' + (st === 'pending' ? ' selected' : '') + '>Pending</option><option value="verified"' + (st === 'verified' ? ' selected' : '') + '>Verified</option><option value="rejected"' + (st === 'rejected' ? ' selected' : '') + '>Rejected</option></select><select class="input input-sm" id="ty" style="width:auto" aria-label="Type"><option value="">All types</option>' + Object.entries(Finance.TYPE_LABEL).map(([k, v]) => '<option value="' + k + '"' + (k === type ? ' selected' : '') + '>' + v + '</option>').join('') + '</select><span class="range-inline"><input type="date" class="input input-sm" id="fr" value="' + from + '" aria-label="From"><input type="date" class="input input-sm" id="to" value="' + to + '" aria-label="To"></span></div></div><div class="card pad-0">' + UI.table(cols([{ label: 'Receipt', render: x => x.p.receiptNo ? '<button class="btn btn-xs" data-receipt="' + x.p.id + '">' + x.p.receiptNo + '</button>' : '—' }, { label: '', render: x => '<button class="btn btn-xs btn-ghost" data-review="' + x.p.id + '">Details</button>' }]), list) + '</div>';
      $('#q').onchange = e => { q = e.target.value.toLowerCase(); draw(); };
      $('#st').onchange = e => { st = e.target.value; draw(); }; $('#ty').onchange = e => { type = e.target.value; draw(); };
      $('#fr').onchange = e => { from = e.target.value; draw(); }; $('#to').onchange = e => { to = e.target.value; draw(); };
      bindReview(ctx, draw);
    };
    draw();
  };

  CS.receipts = function (ctx) {
    let q = '';
    const draw = () => {
      const list = payments().filter(p => p.receiptNo).map(payRow).filter(x => !q || (x.p.receiptNo + ' ' + x.c.name + ' ' + x.r.id).toLowerCase().includes(q)).sort((a, b) => (b.p.receiptNo).localeCompare(a.p.receiptNo));
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Receipt Management</h1><p>' + list.length + ' receipts issued</p></div><input class="input input-sm" id="q" placeholder="Search OR no., customer" style="width:240px" value="' + esc(q) + '" aria-label="Search"></div><div class="card pad-0">' + UI.table([{ label: 'Receipt no.', render: x => '<b>' + x.p.receiptNo + '</b>' }, { label: 'Issued', render: x => U.fmtDateTime(x.p.verifiedAt) }, { label: 'Customer', render: x => esc(x.c.name) }, { label: 'Request', render: x => x.r.id }, { label: 'Type', render: x => Finance.TYPE_LABEL[x.p.type] }, { label: 'Amount', cls: 'num', render: x => U.money(x.p.amount) }, { label: 'Issued by', render: x => esc((Store.user(x.p.verifiedBy) || {}).name || '—') }, { label: '', render: x => '<button class="btn btn-xs" data-receipt="' + x.p.id + '">' + I('receipt') + 'View / print</button>' }], list) + '</div>';
      $('#q').onchange = e => { q = e.target.value.toLowerCase(); draw(); };
      bindReview(ctx, draw);
    };
    draw();
  };

  CS.reports = function (ctx) {
    const pays = payments();
    const ver = pays.filter(p => p.status === 'verified');
    const days = U.range(U.addDays(U.today(), -29), U.today());
    const months = Charts.months(6);
    const fins = Store.where('requests', r => r.supplierId === org() && Finance.ACTIVE.includes(r.status)).map(r => ({ r, f: Finance.summary(r) }));
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Financial Reports</h1><p>Payment collections and outstanding accounts.</p></div><button class="btn btn-sm" id="csv">' + I('copy') + 'Copy CSV</button></div><div class="stats mb-3">' + UI.stat('Verified payments', ver.length, U.moneyShort(U.sum(ver.filter(p => Finance.RENTAL_TYPES.includes(p.type)), p => p.amount)), 'check', 'teal') + UI.stat('Unverified', pays.filter(p => p.status === 'pending').length, U.moneyShort(U.sum(pays.filter(p => p.status === 'pending'), p => p.amount)), 'clock', 'gold') + UI.stat('Rejected', pays.filter(p => p.status === 'rejected').length, '', 'x', 'red') + UI.stat('Installment collections', U.moneyShort(U.sum(ver.filter(p => p.type === 'installment'), p => p.amount)), '', 'cash', 'purple') + UI.stat('Overdue accounts', fins.filter(x => x.f.isOverdue).length, U.moneyShort(U.sum(fins.filter(x => x.f.isOverdue), x => x.f.overdueAmount)), 'alert', 'red') + UI.stat('Receipts issued', pays.filter(p => p.receiptNo).length, '', 'receipt', 'sky') + '</div>' +
      '<div class="grid grid-2"><div class="card"><h3>Daily payment records · last 30 days</h3><div id="r1"></div></div><div class="card"><h3>Monthly collections</h3><div id="r2"></div></div><div class="card"><h3>Collections by payment type</h3><div id="r3"></div></div><div class="card"><h3>Overdue accounts</h3>' + UI.table([{ label: 'Request', render: x => x.r.id }, { label: 'Customer', render: x => esc(Store.user(x.r.customerId).name) }, { label: 'Overdue', cls: 'num', render: x => U.money(x.f.overdueAmount) }], fins.filter(x => x.f.isOverdue), { emptyText: 'None' }) + '</div></div>';
    Charts.bars($('#r1'), days.map(d => ({ label: String(U.parse(d).getDate()), value: U.sum(ver.filter(p => p.date === d), p => p.amount) })), { money: true, color: '#0D9488' });
    Charts.line($('#r2'), months.map(m => ({ label: m.label, value: U.sum(ver.filter(p => p.date.slice(0, 7) === m.key && Finance.RENTAL_TYPES.includes(p.type)), p => p.amount) })), { money: true });
    const byT = {}; ver.forEach(p => { byT[Finance.TYPE_LABEL[p.type]] = (byT[Finance.TYPE_LABEL[p.type]] || 0) + p.amount; });
    Charts.hbars($('#r3'), Object.entries(byT).map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value), { money: true, color: '#7C3AED' });
    $('#csv').onclick = async () => { const csv = [['Transaction', 'Request', 'Customer', 'Supplier', 'Type', 'Method', 'Amount', 'Date', 'Reference', 'Status', 'Receipt']].concat(pays.map(p => { const x = payRow(p); return [p.id, p.requestId, x.c.name, x.s.name, Finance.TYPE_LABEL[p.type], p.method, p.amount, p.date, p.reference, p.status, p.receiptNo || '']; })).map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n'); try { await navigator.clipboard.writeText(csv); UI.toast('CSV copied to clipboard.', 'success'); } catch (e) { UI.modal({ title: 'CSV export', body: '<textarea class="input" rows="12" readonly>' + esc(csv) + '</textarea>' }); } };
  };
})();
