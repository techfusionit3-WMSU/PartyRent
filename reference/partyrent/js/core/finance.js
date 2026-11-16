/* =========================================================
   PartyRent — finance.js
   Payment plans, due schedules, balances and statuses.
   Three plans (configured per supplier):
     Standard Payment Plan    – Reservation Fee + Down Payment + Final Balance
     Full Payment Plan        – 100% in one payment
     Installment Payment Plan – Reservation Fee + Down Payment + scheduled
                                installments proposed by the customer and
                                approved by the supplier
   The reservation fee and down payment are always CREDITED toward the
   order total (never added on top):
     Final Balance = Total − Reservation Fee − Down Payment
   Nothing may stay unpaid after the event date. Only VERIFIED payments
   count. Security deposits and damage compensation are separate.
   ========================================================= */
(function (g) {
  'use strict';
  const r2 = U.round2;
  const RENTAL_TYPES = ['reservation', 'down', 'installment', 'balance', 'full'];
  const TYPE_LABEL = { reservation: 'Reservation Fee', down: 'Down Payment', installment: 'Installment', balance: 'Final Balance', full: 'Full Payment', deposit: 'Security Deposit', deposit_refund: 'Deposit Refund', compensation: 'Damage Compensation', refund: 'Rental Refund' };
  const METHOD_LABEL = { cod: 'Cash on Delivery', cash: 'Cash (Over the Counter)', bank: 'Online Bank Transfer', debit: 'Debit Card' };
  const PLANS = ['standard', 'full', 'installment'];
  const PLAN_LABEL = { standard: 'Standard Payment Plan', full: 'Full Payment Plan', installment: 'Installment Payment Plan' };
  const ARR_LABEL = PLAN_LABEL; // older name kept for compatibility
  const ACTIVE = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery', 'ongoing', 'return_pending', 'inspection'];

  const DEFAULT_PLANS = {
    standard: { enabled: true, resPct: 10, resDays: 3, downPct: 30, downDays: 14 },
    full: { enabled: true, days: 7 },
    installment: { enabled: false, resPct: 10, resDays: 3, downPct: 20, downDays: 14, minMonths: 2, maxInstallments: 6 }
  };
  /** Supplier payment-plan settings merged with defaults. */
  function planConfig(s) {
    const c = (s && s.paymentPlans) || {};
    const out = {};
    PLANS.forEach(k => { out[k] = Object.assign({}, DEFAULT_PLANS[k], c[k] || {}); });
    return out;
  }
  const plansOf = s => PLANS.filter(k => planConfig(s)[k].enabled);
  /** Validate plan settings (percentages, deadlines, installment rules). Returns an error or null. */
  function validatePlanConfig(cfg) {
    if (!PLANS.some(k => cfg[k] && cfg[k].enabled)) return 'Enable at least one payment plan.';
    for (const k of ['standard', 'installment']) {
      const c = cfg[k]; if (!c || !c.enabled) continue;
      const n = PLAN_LABEL[k];
      if (![c.resPct, c.downPct].every(v => Number.isFinite(+v) && +v >= 0 && +v <= 100)) return n + ': percentages must be between 0% and 100%.';
      if (+c.resPct + +c.downPct > 100) return n + ': the reservation fee and down payment together cannot exceed 100% of the total rental amount.';
      if (![c.resDays, c.downDays].every(v => Number.isInteger(+v) && +v >= 0 && +v <= 365)) return n + ': payment deadlines must be whole days (0–365).';
    }
    if (cfg.installment.enabled) {
      if (!(Number.isInteger(+cfg.installment.minMonths) && +cfg.installment.minMonths >= 1 && +cfg.installment.minMonths <= 24)) return 'Installment Payment Plan: the minimum advance booking must be 1–24 months.';
      if (!(Number.isInteger(+cfg.installment.maxInstallments) && +cfg.installment.maxInstallments >= 1 && +cfg.installment.maxInstallments <= 24)) return 'Installment Payment Plan: the maximum number of installments must be 1–24.';
      if (+cfg.installment.resPct + +cfg.installment.downPct >= 100) return 'Installment Payment Plan: leave a balance to divide into installments (reservation fee + down payment must be below 100%).';
    }
    if (cfg.full.enabled && !(Number.isInteger(+cfg.full.days) && +cfg.full.days >= 0 && +cfg.full.days <= 365)) return 'Full Payment Plan: the payment deadline must be whole days (0–365).';
    return null;
  }
  /** Plan description shown to customers. */
  function planSummary(s, plan) {
    const c = planConfig(s)[plan]; const dd = n => n === 0 ? 'right after acceptance' : 'within ' + n + ' day' + (n > 1 ? 's' : '') + ' of acceptance';
    if (plan === 'full') return 'Pay 100% of the total in one payment ' + dd(c.days) + '. No separate reservation fee or down payment.';
    if (plan === 'standard') return 'Reservation Fee ' + c.resPct + '% (' + dd(c.resDays) + ') + Down Payment ' + c.downPct + '% (' + dd(c.downDays) + ') + Final Balance ' + (100 - c.resPct - c.downPct) + '% on or before the event date. Fees are credited to your total.';
    return 'Reservation Fee ' + c.resPct + '% + Down Payment ' + c.downPct + '%, then the remaining ' + (100 - c.resPct - c.downPct) + '% in installments you propose (up to ' + c.maxInstallments + '), approved by the supplier and finished by the event date. Requires booking at least ' + c.minMonths + ' month' + (c.minMonths > 1 ? 's' : '') + ' before the event.';
  }

  /** Installment eligibility: the event must be at least `minMonths` months away. */
  function installmentEligibility(s, eventDate) {
    const c = planConfig(s).installment;
    if (!c.enabled) return { ok: false, message: 'This supplier does not offer the Installment Payment Plan.' };
    const earliest = U.addMonths(U.today(), c.minMonths);
    if (!eventDate || eventDate < earliest) return { ok: false, minMonths: c.minMonths, earliest, message: 'Installments require booking at least ' + c.minMonths + ' month' + (c.minMonths > 1 ? 's' : '') + ' before the event (event on or after ' + U.fmtDate(earliest) + ').' };
    return { ok: true, minMonths: c.minMonths, earliest };
  }
  /** Evenly divided suggestion: n payments spread between `from` and the event date. */
  function suggestInstallments(balance, n, from, eventDate) {
    n = Math.max(1, n | 0); const span = Math.max(0, U.diffDays(from, eventDate));
    const each = Math.floor(balance / n * 100) / 100; const items = []; let acc = 0;
    for (let i = 1; i <= n; i++) { const amt = i === n ? r2(balance - acc) : each; acc = r2(acc + amt); items.push({ due: i === n ? eventDate : U.addDays(from, Math.round(span * i / n)), amount: amt }); }
    return items;
  }
  /** Validate a customer-proposed installment schedule. Returns an error or null. */
  function validateInstallments(items, balance, eventDate, earliestDue, maxN) {
    if (!items || !items.length) return 'Add at least one installment.';
    if (maxN && items.length > maxN) return 'This supplier allows at most ' + maxN + ' installments.';
    let prev = '';
    for (let i = 0; i < items.length; i++) {
      const it = items[i];
      if (!(Number(it.amount) > 0)) return 'Installment ' + (i + 1) + ' needs an amount greater than zero.';
      if (!it.due) return 'Installment ' + (i + 1) + ' needs a due date.';
      if (earliestDue && it.due < earliestDue) return 'Installment ' + (i + 1) + ' cannot be due before ' + U.fmtDate(earliestDue) + '.';
      if (it.due > eventDate) return 'Installment ' + (i + 1) + ' is due after the event date (' + U.fmtDate(eventDate) + '). All installments must be completed on or before the event date.';
      if (prev && it.due <= prev) return 'Installment due dates must be in order, one after another.';
      prev = it.due;
    }
    const sum = r2(U.sum(items, x => Number(x.amount)));
    if (Math.abs(sum - balance) > 0.009) return 'Installments add up to ' + U.money(sum) + ' but the installment balance is ' + U.money(balance) + '. They must match exactly.';
    return null;
  }

  const paymentsFor = reqId => Store.where('payments', p => p.requestId === reqId).sort((a, b) => (a.submittedAt || '').localeCompare(b.submittedAt || ''));

  /** Build the due schedule from acceptance date, event date and the price snapshot. */
  function schedule(req) {
    const p = req.pricing; if (!p || !req.acceptedAt) return [];
    const A = req.acceptedAt;
    const E = (req.eventDate || req.start) < A ? A : (req.eventDate || req.start);
    const due = days => { const d = U.addDays(A, Number(days) || 0); return d > E ? E : d; };
    const t = p.terms || {};
    const items = [];
    if (p.plan === 'full') items.push({ key: 'full', type: 'full', label: 'Full Payment', due: due(t.days), amount: p.total });
    else {
      if (p.reservationFee > 0) items.push({ key: 'res', type: 'reservation', label: 'Reservation Fee (' + t.resPct + '%)', due: due(t.resDays), amount: p.reservationFee });
      if (p.downPayment > 0) items.push({ key: 'down', type: 'down', label: 'Down Payment (' + t.downPct + '%)', due: due(t.downDays), amount: p.downPayment });
      if (p.plan === 'installment') {
        const ip = req.installmentPlan;
        if (ip && ip.status === 'approved') ip.items.forEach((it, i) => items.push({ key: 'inst' + (i + 1), type: 'installment', label: 'Installment ' + (i + 1) + ' of ' + ip.items.length, due: it.due > E ? E : (it.due < A ? A : it.due), amount: r2(it.amount) }));
        else if (p.finalBalance > 0) items.push({ key: 'instx', type: 'installment', label: 'Installment balance (schedule awaiting supplier approval)', due: E, amount: p.finalBalance });
      } else if (p.finalBalance > 0) items.push({ key: 'bal', type: 'balance', label: 'Final Balance', due: E, amount: p.finalBalance });
    }
    let cum = 0;
    items.forEach(it => { cum = r2(cum + it.amount); it.cumulative = cum; });
    return items;
  }

  function summary(req) {
    const p = req.pricing || Pricing.quoteRequest(req);
    const pays = paymentsFor(req.id);
    const ver = pays.filter(x => x.status === 'verified');
    const sumT = (list, types) => r2(U.sum(list.filter(x => types.includes(x.type)), x => x.amount));
    const paidRental = sumT(ver, RENTAL_TYPES);
    const pendingRental = sumT(pays.filter(x => x.status === 'pending'), RENTAL_TYPES);
    const refunded = sumT(ver, ['refund']);
    const depositPaid = sumT(ver, ['deposit']);
    const depositRefunded = sumT(ver, ['deposit_refund']);
    const insp = req.inspection;
    const compensation = insp && insp.confirmed !== false ? r2(insp.compensation || 0) : 0;
    const compPaidDirect = sumT(ver, ['compensation']);
    const depositApplied = insp && insp.settlement === 'deducted' ? Math.min(compensation, depositPaid) : 0;
    const compensationDue = r2(Math.max(0, compensation - compPaidDirect - depositApplied - (insp && insp.settlement === 'waived' ? compensation : 0)));
    const payable = p.total;
    const balance = r2(Math.max(0, payable - paidRental));
    const graceDays = Store.settings().overdueGraceDays || 0;
    const sched = schedule(req).map(it => {
      const paid = paidRental >= it.cumulative - 0.001;
      const overdue = !paid && ACTIVE.includes(req.status) && U.today() > U.addDays(it.due, graceDays);
      return Object.assign({}, it, { state: paid ? 'paid' : (overdue ? 'overdue' : 'upcoming') });
    });
    const next = sched.find(s => s.state !== 'paid') || null;
    const overdueItems = sched.filter(s => s.state === 'overdue');
    const overdueAmount = overdueItems.length ? r2(overdueItems[overdueItems.length - 1].cumulative - paidRental) : 0;
    // reservation confirmed once the first required payment (reservation fee / full payment) is verified
    const first = sched[0];
    const reservationConfirmed = !!(first && first.state === 'paid');

    let status;
    if (['declined', 'cancelled'].includes(req.status) && !paidRental) status = 'Closed';
    else if (refunded > 0) status = refunded >= paidRental ? 'Refunded' : 'Partially Refunded';
    else if (balance <= 0) status = 'Fully Paid';
    else if (overdueItems.length) status = 'Overdue';
    else if (paidRental > 0) status = 'Partially Paid';
    else status = 'Pending';

    const depositRequired = p.deposit || 0;
    let depositStatus = 'Not collected';
    if (depositPaid > 0) depositStatus = 'Held';
    if (depositRefunded > 0 || depositApplied > 0) depositStatus = (depositRefunded + depositApplied >= depositPaid - 0.001) ? (depositApplied > 0 ? 'Settled (partly applied)' : 'Refunded') : 'Partially refunded';
    const depositHeld = r2(Math.max(0, depositPaid - depositRefunded - depositApplied));

    return {
      pricing: p, payments: pays, payable, total: p.total, paidRental, pendingRental, balance, refunded, status, schedule: sched, next, overdueAmount, isOverdue: overdueItems.length > 0,
      plan: p.plan, reservationFee: p.reservationFee || 0, downPayment: p.downPayment || 0, finalBalance: p.finalBalance || 0, reservationConfirmed,
      installmentPlan: req.installmentPlan || null,
      depositRequired, depositPaid, depositRefunded, depositApplied, depositHeld, depositStatus,
      compensation, compensationDue, compPaidDirect
    };
  }

  /** Suggested amount for the customer's next payment (never more than what is left of that stage). */
  function suggestedNext(req) {
    const s = summary(req);
    if (s.next) return { type: s.next.type, stage: s.next.key, amount: r2(Math.max(0, s.next.cumulative - s.paidRental - s.pendingRental)), label: s.next.label, due: s.next.due };
    return { type: 'balance', amount: r2(Math.max(0, s.balance - s.pendingRental)), label: 'Remaining balance' };
  }

  const statusBadge = st => ({ 'Pending': 'b-gold', 'Partially Paid': 'b-sky', 'Fully Paid': 'b-green', 'Overdue': 'b-red', 'Refunded': 'b-purple', 'Partially Refunded': 'b-purple', 'Closed': 'b-gray' }[st] || 'b-gray');

  g.Finance = { RENTAL_TYPES, TYPE_LABEL, METHOD_LABEL, PLANS, PLAN_LABEL, ARR_LABEL, DEFAULT_PLANS, ACTIVE, planConfig, plansOf, validatePlanConfig, planSummary, installmentEligibility, suggestInstallments, validateInstallments, paymentsFor, schedule, summary, suggestedNext, statusBadge };
})(window);
