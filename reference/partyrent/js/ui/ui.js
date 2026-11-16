/* =========================================================
   PartyRent — ui.js
   Shared UI components: toasts, modals, confirm/prompt,
   form validation, product imagery, cards, status badges,
   notifications dropdown.
   ========================================================= */
(function (g) {
  'use strict';
  const { esc, $, $$ } = U;
  const I = Icons.icon;

  /* ---------- toasts ---------- */
  function toast(msg, type) {
    let box = $('.toasts'); if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('role', 'status'); document.body.appendChild(box); }
    const t = document.createElement('div'); t.className = 'toast ' + (type || '');
    t.innerHTML = I(type === 'error' ? 'alert' : type === 'warn' ? 'alert' : type === 'success' ? 'check' : 'info') + '<div>' + esc(msg) + '</div>';
    box.appendChild(t);
    setTimeout(() => { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, type === 'error' ? 6000 : 3600);
  }

  /* ---------- modal ---------- */
  function modal(o) {
    const bd = document.createElement('div'); bd.className = 'modal-backdrop';
    bd.innerHTML = '<div class="modal ' + (o.size || '') + '" role="dialog" aria-modal="true" aria-label="' + esc(o.title || '') + '"><div class="modal-head"><h3>' + (o.titleHtml || esc(o.title || '')) + '</h3><button class="icon-btn" data-close aria-label="Close">' + I('x') + '</button></div><div class="modal-body">' + (o.body || '') + '</div>' + (o.foot !== false ? '<div class="modal-foot">' + (o.foot || '<button class="btn" data-close>Close</button>') + '</div>' : '') + '</div>';
    document.body.appendChild(bd);
    document.body.style.overflow = 'hidden';
    const close = (val) => { bd.remove(); if (!$('.modal-backdrop')) document.body.style.overflow = ''; if (o.onClose) o.onClose(val); };
    bd.addEventListener('click', e => { if (e.target === bd || e.target.closest('[data-close]')) close(); });
    const esc_ = e => { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc_); } };
    document.addEventListener('keydown', esc_);
    const api = { el: bd.querySelector('.modal'), close };
    if (o.onOpen) o.onOpen(api.el, api);
    const f = bd.querySelector('input:not([type=hidden]),select,textarea'); if (f && !o.noFocus) setTimeout(() => f.focus(), 50);
    return api;
  }
  /** Side panel (drawer) — same look as modals, slides in from the left. */
  function drawer(o) {
    const bd = document.createElement('div'); bd.className = 'drawer-backdrop';
    const dr = document.createElement('aside'); dr.className = 'drawer'; dr.setAttribute('role', 'dialog'); dr.setAttribute('aria-modal', 'true'); dr.setAttribute('aria-label', o.title || '');
    dr.innerHTML = '<div class="drawer-head"><h3 class="mb-0">' + esc(o.title || '') + '</h3><button class="icon-btn" data-close aria-label="Close">' + I('x') + '</button></div><div class="drawer-body">' + (o.body || '') + '</div>' + (o.foot ? '<div class="drawer-foot">' + o.foot + '</div>' : '');
    document.body.appendChild(bd); document.body.appendChild(dr);
    document.body.style.overflow = 'hidden';
    const prevFocus = document.activeElement;
    let open = true;
    const onKey = e => { if (e.key === 'Escape') close(); };
    function close() { if (!open) return; open = false; document.removeEventListener('keydown', onKey); bd.remove(); dr.remove(); if (!$('.modal-backdrop') && !$('.drawer')) document.body.style.overflow = ''; if (prevFocus && prevFocus.focus) prevFocus.focus({ preventScroll: true }); if (o.onClose) o.onClose(); }
    bd.addEventListener('click', close);
    dr.addEventListener('click', e => { if (e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', onKey);
    const api = { el: dr, close };
    if (o.onOpen) o.onOpen(dr, api);
    setTimeout(() => { const c = dr.querySelector('[data-close]'); if (c) c.focus(); }, 50);
    return api;
  }
  function confirm(o) {
    return new Promise(res => {
      let done = false;
      const m = modal({ title: o.title || 'Please confirm', size: 'sm', body: '<p style="margin:0">' + (o.html || esc(o.message || 'Are you sure?')) + '</p>', foot: '<button class="btn" data-close>' + esc(o.cancelText || 'Cancel') + '</button><button class="btn ' + (o.danger ? 'btn-danger' : 'btn-primary') + '" data-ok>' + esc(o.confirmText || 'Confirm') + '</button>', onClose: () => { if (!done) res(false); }, noFocus: true });
      m.el.querySelector('[data-ok]').onclick = () => { done = true; m.close(); res(true); };
    });
  }
  function prompt(o) {
    return new Promise(res => {
      let done = false;
      const inp = o.textarea ? '<textarea class="input" id="pr-in" placeholder="' + esc(o.placeholder || '') + '">' + esc(o.value || '') + '</textarea>' : '<input class="input" id="pr-in" placeholder="' + esc(o.placeholder || '') + '" value="' + esc(o.value || '') + '">';
      const m = modal({ title: o.title, size: 'sm', body: (o.message ? '<p>' + esc(o.message) + '</p>' : '') + '<div class="field"><label for="pr-in">' + esc(o.label || '') + '</label>' + inp + '<div class="error-text">This field is required.</div></div>', foot: '<button class="btn" data-close>Cancel</button><button class="btn ' + (o.danger ? 'btn-danger' : 'btn-primary') + '" data-ok>' + esc(o.confirmText || 'Submit') + '</button>', onClose: () => { if (!done) res(null); } });
      m.el.querySelector('[data-ok]').onclick = () => {
        const v = m.el.querySelector('#pr-in').value.trim();
        if (o.required && !v) { m.el.querySelector('.field').classList.add('invalid'); return; }
        done = true; m.close(); res(v);
      };
    });
  }

  /* ---------- form validation ---------- */
  function validate(form) {
    let ok = true;
    $$('.field', form).forEach(f => f.classList.remove('invalid'));
    $$('input,select,textarea', form).forEach(inp => {
      if (inp.disabled || inp.type === 'hidden' || inp.closest('.hidden')) return;
      const v = (inp.value || '').trim(); let msg = '';
      if (inp.required && !v && inp.type !== 'checkbox' && inp.type !== 'file') msg = 'This field is required.';
      else if (inp.required && inp.type === 'checkbox' && !inp.checked) msg = 'Please check this box to continue.';
      else if (v && inp.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) msg = 'Enter a valid email address.';
      else if (v && inp.dataset.phone !== undefined && !/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/.test(v.replace(/\s+/g, ' '))) msg = 'Enter a valid PH mobile number (e.g. 0917 555 0101).';
      else if (v && inp.minLength > 0 && v.length < inp.minLength) msg = 'Must be at least ' + inp.minLength + ' characters.';
      else if (v && inp.type === 'number' && inp.min !== '' && Number(v) < Number(inp.min)) msg = 'Must be at least ' + inp.min + '.';
      else if (v && inp.type === 'number' && inp.max !== '' && Number(v) > Number(inp.max)) msg = 'Must be at most ' + inp.max + '.';
      else if (inp.dataset.match && v !== ($('#' + inp.dataset.match, form) || {}).value) msg = 'Passwords do not match.';
      else if (v && inp.type === 'date' && inp.min && v < inp.min) msg = 'Date cannot be earlier than ' + U.fmtDate(inp.min) + '.';
      if (msg) {
        ok = false; const f = inp.closest('.field');
        if (f) { f.classList.add('invalid'); let e = $('.error-text', f); if (!e) { e = document.createElement('div'); e.className = 'error-text'; f.appendChild(e); } e.textContent = msg; }
      }
    });
    const first = $('.field.invalid', form); if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return ok;
  }
  const formData = form => { const o = {}; $$('[name]', form).forEach(i => { if (i.type === 'checkbox') { if (i.dataset.multi !== undefined) { o[i.name] = o[i.name] || []; if (i.checked) o[i.name].push(i.value); } else o[i.name] = i.checked; } else if (i.type === 'radio') { if (i.checked) o[i.name] = i.value; } else o[i.name] = i.value; }); return o; };

  /* ---------- imagery ---------- */
  function catOf(p) { return Store.category(p.categoryId) || { icon: 'gift', grad: ['#FBCFE8', '#DB2777'] }; }
  function img(item, kind) {
    if (item.images && item.images.length) return '<img src="' + item.images[0] + '" alt="' + esc(item.name) + '" loading="lazy">';
    if (kind === 'package' || item.items) return '<div style="width:100%;height:100%;display:grid;place-items:center;background:linear-gradient(135deg,#F472B6,#8B5CF6)">' + Icons.art('package') + '</div>';
    const c = catOf(item);
    return '<div style="width:100%;height:100%;display:grid;place-items:center;background:linear-gradient(135deg,' + c.grad[0] + ',' + c.grad[1] + ')">' + Icons.art(c.icon) + '</div>';
  }
  function thumb(item, kind, cls) { return '<div class="thumb ' + (cls || '') + '">' + img(item, kind) + '</div>'; }
  const COLOR_HEX = { White: '#FFFFFF', Pink: '#F9A8D4', Gold: '#D4A017', Champagne: '#E8D3A8', Blue: '#60A5FA', Red: '#EF4444', Black: '#1F2937', Clear: '#E0F2FE', Multicolor: 'conic-gradient(#f43f5e,#f59e0b,#10b981,#3b82f6,#a855f7,#f43f5e)', 'Warm White': '#FFF4D6', Silver: '#CBD5E1' };
  const swatch = c => '<span class="swatch" title="' + esc(c) + '" style="background:' + (COLOR_HEX[c] || '#ddd') + '"></span>';

  function statusBadge(st) { return '<span class="badge ' + Services.statusClass(st) + '">' + esc(Services.statusLabel(st)) + '</span>'; }
  function payBadge(st) { return '<span class="badge ' + Finance.statusBadge(st) + '">' + esc(st) + '</span>'; }
  function verBadge(st) { return '<span class="badge ' + ({ pending: 'b-gold', verified: 'b-green', rejected: 'b-red' }[st] || 'b-gray') + '">' + esc({ pending: 'Pending Verification', verified: 'Verified', rejected: 'Rejected' }[st] || st) + '</span>'; }
  function availBadge(avail, need) { if (avail <= 0) return '<span class="badge b-red">Unavailable</span>'; if (need && avail < need) return '<span class="badge b-gold">Only ' + avail + ' left</span>'; return '<span class="badge b-green">' + avail + ' available</span>'; }
  const fulfillLabel = f => ({ both: 'Delivery & Pickup', delivery: 'Delivery only', pickup: 'Pickup only' }[f] || f);

  /**
   * Collapsible "Rental Charges" breakdown (Rental Fee, Delivery Fee per location, Service Fees).
   * q = a Pricing quote. The collapsed header always shows the subtotal; charges that do not
   * apply (₱0 / none) are left out.
   */
  function rentalCharges(q, opts) {
    opts = opts || {};
    const sm = 'style="width:14px;height:14px;vertical-align:-2px"';
    const dayCount = new Set((q.lines || []).flatMap(l => l.days.map(d => d.date))).size;
    let body = '<div class="sum-line"><span>Rental Fee<div class="small muted">' + (opts.feeNote || ((q.lines || []).length + ' item' + ((q.lines || []).length === 1 ? '' : 's') + (dayCount ? ' · ' + dayCount + ' rental day' + (dayCount > 1 ? 's' : '') : '') + ' · supplier daily rates')) + '</div></span><span>' + U.money(q.rentalFee) + '</span></div>';
    const del = (q.delivery || []).filter(d => d.fee > 0);
    if (del.length === 1) body += '<div class="sum-line"><span>Delivery Fee<div class="small muted">' + esc(del[0].label) + ' · ' + esc(del[0].zone) + '</div></span><span>' + U.money(del[0].fee) + '</span></div>';
    else if (del.length > 1) body += '<div class="sum-line"><span>Delivery Fee <span class="small muted">(' + del.length + ' locations)</span></span><span>' + U.money(q.deliveryFee) + '</span></div>' + del.map(d => '<div class="sum-line small ch-sub"><span>' + esc(d.label) + '<div class="muted">' + esc(d.zone) + '</div></span><span>' + U.money(d.fee) + '</span></div>').join('');
    (q.services || []).filter(x => x.amount > 0).forEach(x => { body += '<div class="sum-line"><span>Service Fee – ' + esc(x.name) + '<div class="small muted">' + esc(x.description) + (x.qty > 1 ? ' · ' + U.money(x.unit) + ' × ' + x.qty + ' locations' : '') + (x.required ? '' : ' · optional') + '</div></span><span>' + U.money(x.amount) + '</span></div>'; });
    body += '<div class="sum-line total"><span>Total Rental Charges</span><span class="price">' + U.money(q.total) + '</span></div>' + (opts.note ? '<div class="small muted">' + opts.note + '</div>' : '');
    return '<details class="charges"' + (opts.open ? ' open' : '') + '><summary><span class="ch-title">' + I('receipt').replace('<svg', '<svg ' + sm) + ' Rental Charges' + (opts.estimate ? ' <span class="badge b-gray plain">Estimate</span>' : '') + '</span><span class="ch-total">' + U.money(q.total) + '</span><span class="ch-chev" aria-hidden="true">' + I('down').replace('<svg', '<svg ' + sm) + '</span></summary><div class="ch-body">' + body + '</div></details>';
  }
  /** Payment plan badge. */
  const planBadge = plan => '<span class="badge ' + ({ standard: 'b-sky', full: 'b-green', installment: 'b-purple' }[plan] || 'b-gray') + ' plain">' + esc(Finance.PLAN_LABEL[plan] || plan) + '</span>';
  /** "6:00 AM – 11:00 AM · 5 Hours" for a line. */
  function timeLabel(line) { if (!line || !line.time) return 'All day'; return Slots.rangeLabel(line.time) + ' · ' + Slots.duration(line).label; }

  /** Customer-facing label for a product's optional maximum rental duration (always the live product setting). */
  function maxRentalLabel(p) { const n = p && p.maxRentalDays > 0 ? p.maxRentalDays : 0; return n ? 'Maximum Rental: ' + n + ' Day' + (n > 1 ? 's' : '') : 'No Rental Duration Limit'; }
  /** Marketplace product card. dates (optional) narrows availability. */
  function productCard(p, opts) {
    opts = opts || {};
    const s = Store.supplier(p.supplierId);
    const dates = opts.dates || (g.RentalPeriod ? RentalPeriod.dates() : []); // default: the customer's selected rental period
    // Stock is only shown for a specific rental period — never a "today" number on browse cards.
    const avail = dates.length ? Inventory.minAvailable(p.id, dates) : null;
    const link = U.url('customer/product.html', { id: p.id, start: dates[0], end: dates[dates.length - 1] });
    const isNew = U.diffDays(U.iso(new Date(p.createdAt)), U.today()) <= 14;
    return '<article class="p-card">' +
      (opts.compare ? '<label class="p-compare"><input type="checkbox" data-compare="' + p.id + '"' + (opts.compareOn ? ' checked' : '') + '> Compare</label>' : '') +
      '<a class="cover" href="' + link + '"><div class="p-img">' + img(p) + '<div class="ribbon">' + (avail !== null && avail <= 0 ? '<span class="badge b-red plain">Fully booked</span>' : isNew ? '<span class="badge b-purple plain">New</span>' : (p.tiers.length > 1 ? '<span class="badge b-gold plain">Bulk discount</span>' : '')) + '</div></div></a>' +
      '<div class="p-body"><a class="p-name" href="' + link + '">' + esc(p.name) + '</a>' +
      '<div class="p-sup">' + I('store', '') .replace('<svg', '<svg style="width:13px;height:13px"') + esc(s.name) + '</div>' +
      '<div class="p-sup">' + I('map').replace('<svg', '<svg style="width:13px;height:13px"') + esc(s.city) + ' · <span class="stars">★</span> ' + (p.rating || '—') + '</div>' +
      '<div class="p-sup p-dur">' + I('clock').replace('<svg', '<svg style="width:13px;height:13px"') + esc(maxRentalLabel(p)) + '</div>' +
      '<div class="p-meta"><div class="p-price">' + U.money(Pricing.basePrice(p)).replace('.00', '') + ' <small>/ ' + esc(p.unit.split('/')[0].trim()) + '</small></div>' + (avail === null ? '' : '<span class="small ' + (avail > 0 ? 'muted' : '') + '" style="color:' + (avail > 0 ? '' : 'var(--red)') + '" title="Available for ' + esc(U.fmtRange(dates[0], dates[dates.length - 1])) + '">' + avail + ' available</span>') + '</div></div></article>';
  }
  function packageCard(k, opts) {
    opts = opts || {};
    const s = Store.supplier(k.supplierId);
    const dates = opts.dates || (g.RentalPeriod ? RentalPeriod.dates() : []); // default: the customer's selected rental period
    const avail = dates.length ? Math.min.apply(null, dates.map(d => Inventory.packageAvailableOn(k.id, d))) : null;
    const v = Pricing.packageValue(k);
    const link = U.url('customer/package.html', { id: k.id });
    return '<article class="p-card"><a class="cover" href="' + link + '"><div class="p-img">' + img(k, 'package') + '<div class="ribbon">' + (v.savings > 0 ? '<span class="badge b-gold plain">Save ' + U.moneyShort(v.savings) + '</span>' : '') + '</div><div style="position:absolute;right:12px;bottom:8px;font-size:2rem">' + esc(k.emoji || '') + '</div></div></a>' +
      '<div class="p-body"><a class="p-name" href="' + link + '">' + esc(k.name) + '</a><div class="p-sup">' + esc(s.name) + ' · ' + esc(s.city) + '</div><div class="small muted">' + k.items.length + ' items included</div>' +
      '<div class="p-meta"><div class="p-price">' + U.moneyShort(k.price) + ' <small>/ day</small></div>' + (avail === null ? '' : avail > 0 ? '<span class="small muted">' + avail + ' available</span>' : '<span class="small" style="color:var(--red)">Fully booked</span>') + '</div></div></article>';
  }
  function supplierCard(s) {
    const count = Store.where('products', p => p.supplierId === s.id && p.status === 'published').length;
    return '<a class="sup-card" href="' + U.url('customer/supplier.html', { id: s.id }) + '"><div class="logo-tile" style="background:' + s.color + '1a">' + esc(s.logo) + '</div><div class="grow"><div class="strong">' + esc(s.name) + '</div><div class="small muted">' + esc(s.city) + ' · ' + fulfillLabel(s.fulfillment) + '</div><div class="small"><span class="stars">★</span> ' + s.rating + ' · ' + count + ' items</div></div>' + I('right').replace('<svg', '<svg style="width:18px;color:var(--muted)"') + '</a>';
  }
  function empty(emoji, title, text, action) { return '<div class="empty"><div class="em">' + emoji + '</div><h3>' + esc(title) + '</h3><p>' + esc(text || '') + '</p>' + (action || '') + '</div>'; }
  function loading() { return '<div class="page-loading"><div class="stack" style="align-items:center"><div class="spinner"></div><div class="muted small">Loading…</div></div></div>'; }
  function stat(label, value, sub, icon, color, href) { const tag = href ? 'a' : 'div'; return '<' + tag + ' class="stat c-' + (color || 'pink') + '"' + (href ? ' href="' + href + '"' : '') + '><div class="ico">' + I(icon || 'chart') + '</div><div class="k">' + esc(label) + '</div><div class="v">' + value + '</div>' + (sub ? '<div class="s">' + sub + '</div>' : '') + '</' + tag + '>'; }

  /** Generic table renderer. cols: [{label, key|render, cls}] ; rows: data. Becomes cards on mobile. */
  function table(cols, rows, o) {
    o = o || {};
    if (!rows.length) return o.empty || empty('🗂️', 'Nothing here yet', o.emptyText || 'No records match the current filters.');
    return '<div class="table-wrap"><table class="table ' + (o.noCards ? '' : 'cards') + '"><thead><tr>' + cols.map(c => '<th class="' + (c.cls || '') + '">' + esc(c.label) + '</th>').join('') + '</tr></thead><tbody>' +
      rows.map((r, i) => '<tr' + (o.rowAttr ? ' ' + o.rowAttr(r, i) : '') + '>' + cols.map(c => '<td data-label="' + esc(c.label) + '" class="' + (c.cls || '') + '">' + (c.render ? c.render(r, i) : esc(r[c.key])) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>';
  }
  function tabs(items, active, attr) { return '<div class="tabs" role="tablist">' + items.map(t => '<button class="tab' + (t.key === active ? ' active' : '') + '" ' + (attr || 'data-tab') + '="' + t.key + '" role="tab">' + esc(t.label) + (t.count != null ? '<span class="count">' + t.count + '</span>' : '') + '</button>').join('') + '</div>'; }

  /* ---------- dropdown behaviour ---------- */
  document.addEventListener('click', e => {
    const tg = e.target.closest('[data-dropdown]');
    $$('.dropdown.open').forEach(d => { if (!tg || d !== tg.closest('.dropdown')) { if (!e.target.closest('.menu') || e.target.closest('a,button.menu-item')) d.classList.remove('open'); } });
    if (tg) { tg.closest('.dropdown').classList.toggle('open'); e.preventDefault(); }
  });

  /* ---------- notifications ---------- */
  function notifDropdown(s) {
    const list = Store.notificationsFor(s.user, s.mode);
    const unread = list.filter(n => !n.readBy.includes(s.user.id)).length;
    return '<div class="dropdown"><button class="icon-btn" data-dropdown aria-label="Notifications">' + I('bell') + (unread ? '<span class="dot-count">' + (unread > 9 ? '9+' : unread) + '</span>' : '') + '</button>' +
      '<div class="menu notif-menu"><div class="nm-head"><b>Notifications</b><button class="btn btn-xs btn-ghost" data-mark-all>Mark all read</button></div><div class="notif-list">' +
      (list.length ? list.slice(0, 8).map(n => notifItem(n, s.user.id)).join('') : '<div class="empty small">No notifications yet</div>') +
      '</div><div style="padding:10px;text-align:center"><a href="' + U.url('notifications.html') + '" class="small strong">View all notifications</a></div></div></div>';
  }
  function notifItem(n, uid) { return '<a class="notif ' + (n.readBy.includes(uid) ? '' : 'unread') + '" href="' + (n.link ? U.url(n.link.split('?')[0], Object.fromEntries(new URLSearchParams(n.link.split('?')[1] || ''))) : '#') + '" data-notif="' + n.id + '"><span class="ndot"></span><div class="grow"><div class="nt">' + esc(n.title) + '</div><div class="nb">' + esc(n.body) + '</div><div class="nw">' + U.timeAgo(n.at) + '</div></div></a>'; }
  document.addEventListener('click', e => {
    const s = Auth.session(); if (!s) return;
    const n = e.target.closest('[data-notif]'); if (n) Store.markRead([n.dataset.notif], s.user.id);
    if (e.target.closest('[data-mark-all]')) { Store.markRead(Store.notificationsFor(s.user, s.mode).map(x => x.id), s.user.id); $$('.notif.unread').forEach(x => x.classList.remove('unread')); $$('.dot-count').forEach(x => { if (x.closest('.dropdown')) x.remove(); }); }
  });

  /* ---------- show / hide password (applied to every password input automatically) ----------
     The eye icon only appears while the field has at least one character. Clicking it toggles
     plain text / hidden without taking focus away from the field. Emptying the field hides the
     icon again and re-masks the password. */
  function setPwVisible(inp, btn, show) {
    inp.type = show ? 'text' : 'password';
    btn.innerHTML = I(show ? 'eyeOff' : 'eye');
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); btn.title = show ? 'Hide password' : 'Show password';
    btn.setAttribute('aria-pressed', String(show));
  }
  function syncPw(inp) {
    const wrap = inp.parentNode; if (!wrap || !wrap.classList || !wrap.classList.contains('pw-wrap')) return;
    const btn = wrap.querySelector('.pw-toggle');
    const has = inp.value.length > 0;
    wrap.classList.toggle('has-value', has);
    btn.tabIndex = has ? 0 : -1;
    if (!has && inp.type === 'text') setPwVisible(inp, btn, false);
  }
  function enhancePasswords(root) {
    $$('input[type="password"]:not([data-pw-ready])', root || document).forEach(inp => {
      inp.dataset.pwReady = '1';
      const wrap = document.createElement('span'); wrap.className = 'pw-wrap';
      inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'pw-toggle';
      setPwVisible(inp, btn, false);
      // keep focus (and the caret) in the input: never let the button take focus on pointer down
      btn.addEventListener('pointerdown', e => e.preventDefault());
      btn.addEventListener('mousedown', e => e.preventDefault());
      btn.addEventListener('click', e => {
        e.preventDefault();
        const n = inp.value.length;
        const caret = document.activeElement === inp && inp.selectionStart != null ? [inp.selectionStart, inp.selectionEnd] : [n, n];
        setPwVisible(inp, btn, inp.type === 'password');
        // switching type resets the caret in some browsers — put it back so typing continues where it was
        const restore = () => { if (document.activeElement !== inp) inp.focus({ preventScroll: true }); try { inp.setSelectionRange(caret[0], caret[1]); } catch (err) { /* ignore */ } };
        restore();
        // if the browser moves the caret after the type switch, fix it before the next keystroke
        inp._pwCaret = { caret, value: inp.value };
        requestAnimationFrame(() => { if (inp._pwCaret && inp.value === inp._pwCaret.value && document.activeElement === inp && inp.selectionStart !== caret[0]) restore(); });
      });
      wrap.appendChild(btn);
      inp.addEventListener('keydown', () => { const k = inp._pwCaret; inp._pwCaret = null; if (k && inp.value === k.value && inp.selectionStart !== k.caret[0]) { try { inp.setSelectionRange(k.caret[0], k.caret[1]); } catch (err) { /* ignore */ } } });
      inp.addEventListener('pointerdown', () => { inp._pwCaret = null; }); // user placed the caret themselves
      ['input', 'change', 'focus', 'keyup'].forEach(ev => inp.addEventListener(ev, () => syncPw(inp)));
      ['paste', 'cut', 'drop'].forEach(ev => inp.addEventListener(ev, () => setTimeout(() => syncPw(inp), 0)));
      syncPw(inp);
      setTimeout(() => syncPw(inp), 600); // browser autofill
    });
  }
  // when a form is reset, re-mask and hide the icon again
  document.addEventListener('reset', e => { setTimeout(() => $$('.pw-wrap input[data-pw-ready]', e.target).forEach(syncPw), 0); }, true);
  if (typeof MutationObserver !== 'undefined') {
    const start = () => { enhancePasswords(document); new MutationObserver(muts => { if (muts.some(m => m.addedNodes.length)) enhancePasswords(document); }).observe(document.body, { childList: true, subtree: true }); };
    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
  }

  g.UI = { rentalCharges, planBadge, timeLabel, drawer, maxRentalLabel, enhancePasswords, toast, modal, confirm, prompt, validate, formData, img, thumb, swatch, COLOR_HEX, statusBadge, payBadge, verBadge, availBadge, fulfillLabel, productCard, packageCard, supplierCard, empty, loading, stat, table, tabs, notifDropdown, notifItem, icon: I };
})(window);
