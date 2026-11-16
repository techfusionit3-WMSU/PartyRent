/* =========================================================
   PartyRent — pricing.js
   Rental Charges of one supplier order:
     Rental Fee   – tiered (quantity-based) daily prices and package prices
     Delivery Fee – per delivery location (supplier delivery zones)
     Service Fee  – supplier services (setup, preparation, dismantling…)
   Their sum is the order total, which is the basis for the payment plan
   (reservation fee, down payment, final balance / installments).
   Prices only ever come from supplier-configured data.
   ========================================================= */
(function (g) {
  'use strict';
  const r2 = U.round2;

  function tierFor(product, qty) {
    const tiers = (product.tiers || []).slice().sort((a, b) => a.min - b.min);
    let t = tiers[0];
    tiers.forEach(x => { if (qty >= x.min && (x.max == null || qty <= x.max)) t = x; });
    return t || { min: 1, max: null, price: 0 };
  }
  const unitPrice = (product, qty) => tierFor(product, Math.max(1, qty)).price;
  const basePrice = product => { const t = (product.tiers || []).slice().sort((a, b) => a.min - b.min); return t.length ? t[0].price : 0; };
  const lowestPrice = product => Math.min.apply(null, (product.tiers || [{ price: 0 }]).map(t => t.price));

  /** Combined daily quantity per product across the order's item lines. Splitting a product
   *  between locations/venues therefore never changes its price tier (no duplicated charges). */
  function combinedQty(lines) {
    const m = {};
    (lines || []).forEach(l => { if (l.type !== 'item') return; Object.entries(l.days || {}).forEach(([d, q]) => { q = Number(q) || 0; if (q) m[l.refId + '|' + d] = (m[l.refId + '|' + d] || 0) + q; }); });
    return m;
  }
  /** Price one line for each date. Item lines use the tier for that date's (combined) quantity. */
  function quoteLine(line, combined) {
    const daysOut = [];
    Object.keys(line.days || {}).sort().forEach(date => {
      const qty = Number(line.days[date]) || 0;
      if (!qty) return;
      let unit = 0;
      if (line.type === 'package') { const k = Store.pkg(line.refId); unit = k ? k.price : 0; }
      else { const p = Store.product(line.refId); unit = p ? unitPrice(p, combined ? combined[line.refId + '|' + date] || qty : qty) : 0; }
      daysOut.push({ date, qty, unit, subtotal: r2(unit * qty) });
    });
    return { lineId: line.id, type: line.type, refId: line.refId, destId: line.destId || null, sourceId: line.sourceId || null, time: line.time || null, days: daysOut, total: r2(U.sum(daysOut, d => d.subtotal)) };
  }

  /* ---------- delivery ---------- */
  const zones = s => (s && s.deliveryZones) || [];
  function zoneFee(s, zoneId) { const z = zones(s).find(x => x.id === zoneId); return z ? Number(z.fee) || 0 : Number((s && s.deliveryFee) || 0); }
  function zoneName(s, zoneId) { const z = zones(s).find(x => x.id === zoneId); return z ? z.name : 'Other areas (standard rate)'; }

  /* ---------- services ---------- */
  /** Required services are always included; optional ones only when the customer selects them. */
  function serviceLines(s, selected, destCount) {
    return ((s && s.services) || []).filter(x => x.active !== false && (x.required || (selected || []).includes(x.id))).map(x => {
      const qty = x.per === 'location' ? Math.max(1, destCount || 1) : 1;
      return { id: x.id, name: x.name, description: x.description || '', unit: Number(x.amount) || 0, qty, per: x.per || 'order', required: !!x.required, amount: r2((Number(x.amount) || 0) * qty) };
    });
  }

  /**
   * Quote one supplier order.
   * o = { supplierId, lines, fulfillment, destinations:[{id,label,zoneId}], plan, services:[ids] }
   */
  function quoteOrder(o) {
    const s = Store.supplier(o.supplierId);
    const combined = combinedQty(o.lines);
    const lq = (o.lines || []).map(l => quoteLine(l, combined));
    const rentalFee = r2(U.sum(lq, l => l.total));
    const dests = o.fulfillment === 'delivery' ? ((o.destinations && o.destinations.length) ? o.destinations : [{ id: 'd1', label: 'Delivery address', zoneId: '' }]) : [];
    const delivery = dests.map(d => ({ destId: d.id, label: d.label || 'Delivery address', zoneId: d.zoneId || '', zone: zoneName(s, d.zoneId), fee: zoneFee(s, d.zoneId) }));
    const deliveryFee = r2(U.sum(delivery, d => d.fee));
    const services = serviceLines(s, o.services, dests.length || 1);
    const serviceFee = r2(U.sum(services, x => x.amount));
    const total = r2(rentalFee + deliveryFee + serviceFee);
    const plan = o.plan || 'full';
    const cfg = s ? Finance.planConfig(s) : Finance.DEFAULT_PLANS;
    const terms = Object.assign({}, cfg[plan] || {});
    const reservationFee = plan === 'full' ? 0 : r2(total * (terms.resPct || 0) / 100);
    const downPayment = plan === 'full' ? 0 : r2(total * (terms.downPct || 0) / 100);
    const finalBalance = r2(total - reservationFee - downPayment);
    const pol = (s && s.policy) || {};
    const deposit = pol.depositType === 'percent' ? r2(rentalFee * (pol.depositValue || 0) / 100) : (pol.depositValue || 0);
    return { lines: lq, rentalFee, itemsSubtotal: rentalFee, delivery, deliveryFee, services, serviceFee, total, payable: total, plan, terms, reservationFee, downPayment, finalBalance, deposit, quotedAt: new Date().toISOString() };
  }
  /** Back-compatible: quote a set of lines for one supplier. */
  function quote(lines, supplierId, fulfillment, plan, extra) { return quoteOrder(Object.assign({ supplierId, lines, fulfillment, plan }, extra || {})); }
  const quoteRequest = r => quoteOrder({ supplierId: r.supplierId, lines: r.lines, fulfillment: r.fulfillment, destinations: r.destinations, plan: r.plan, services: r.services });

  /** Lowest per-day price of a package vs. its items rented separately (shows savings). */
  function packageValue(k) {
    const separate = U.sum(k.items, it => { const p = Store.product(it.productId); return p ? unitPrice(p, it.qty) * it.qty : 0; });
    return { separate: r2(separate), price: k.price, savings: r2(Math.max(0, separate - k.price)) };
  }

  g.Pricing = { tierFor, unitPrice, basePrice, lowestPrice, combinedQty, quoteLine, zoneFee, zoneName, serviceLines, quoteOrder, quote, quoteRequest, packageValue };
})(window);
