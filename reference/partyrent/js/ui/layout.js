/* =========================================================
   PartyRent — layout.js
   Page shells: marketplace (customer) and dashboard
   (supplier / admin / superadmin / cashier), floating
   bottom navigation, profile menu with mode switcher, and
   the AI assistant chat widget. Also the app bootstrapper.
   ========================================================= */
(function (g) {
  'use strict';
  const { esc, url, $ } = U;
  const I = Icons.icon;

  const brand = (tag) => '<a class="brand" href="' + url('index.html') + '"><span class="brand-mark">' + I('party') + '</span><span class="word">Party<b>Rent</b></span>' + (tag ? '<span class="role-tag">' + esc(tag) + '</span>' : '') + '</a>';

  /* ---------- profile menu (shared) ---------- */
  function profileMenu(s) {
    const u = s.user; const role = Auth.activeRole(u, s.mode);
    const sup = Auth.supplierOf(u);
    let items = '';
    if (role === 'customer') {
      items += '<a href="' + url('customer/rentals.html') + '">' + I('list') + 'My Rentals</a><a href="' + url('customer/payments.html') + '">' + I('card') + 'Payment History</a><a href="' + url('customer/profile.html') + '">' + I('user') + 'Profile & Settings</a><div class="sep"></div>';
      if (sup && sup.status === 'approved') items += '<button class="menu-item menu-switch" data-switch="supplier">' + I('swap') + 'Switch to Supplier Mode</button>';
      else if (sup && ['pending', 'revision'].includes(sup.status)) items += '<a href="' + url('customer/start-selling.html') + '">' + I('clock') + 'Supplier application: <span class="badge ' + Services.APP_STATUS[sup.status][1] + '">' + Services.APP_STATUS[sup.status][0] + '</span></a>';
      else items += '<a href="' + url('customer/start-selling.html') + '" class="menu-switch">' + I('store') + 'Start Selling / Become a Supplier</a>';
    } else if (role === 'supplier' && Auth.isEmployee(u)) {
      items += '<div class="menu-head small" style="padding-top:0">' + esc(Auth.ORG_ROLES[Auth.orgRole(u)]) + ' · ' + esc(sup ? sup.name : '') + '</div><a href="' + url('customer/supplier.html', { id: u.supplierId }) + '">' + I('eye') + 'View Public Shop</a><a href="' + url('account.html') + '">' + I('user') + 'Account</a>';
    } else if (role === 'supplier') {
      items += '<a href="' + url('supplier/shop.html') + '">' + I('store') + 'Shop Profile & Settings</a><a href="' + url('supplier/team.html') + '">' + I('users') + 'Team & Roles</a><a href="' + url('customer/supplier.html', { id: u.supplierId }) + '">' + I('eye') + 'View Public Shop</a><a href="' + url('account.html') + '">' + I('user') + 'Account</a><div class="sep"></div><button class="menu-item menu-switch" data-switch="customer">' + I('swap') + 'Switch to Customer Mode</button>';
    } else {
      items += '<a href="' + url('account.html') + '">' + I('user') + 'Account Settings</a>';
    }
    return '<div class="dropdown"><button class="user-btn" data-dropdown aria-label="Account menu"><span class="avatar">' + U.initials(u.name) + '</span><span class="uname hide-sm">' + esc(u.name.split(' ')[0]) + '</span>' + (sup && sup.status === 'approved' && !Auth.isStaff(u) && role !== 'cashier' ? '<span class="mode-pill ' + (role === 'supplier' ? 'sup' : '') + '">' + (role === 'supplier' ? 'Supplier' : 'Customer') + '</span>' : '') + '</button>' +
      '<div class="menu"><div class="menu-head"><div class="strong">' + esc(u.name) + '</div><div class="small muted">' + esc(u.email) + '</div>' + ((role === 'supplier' || role === 'cashier') && sup ? '<div class="small" style="margin-top:4px">' + esc(sup.logo) + ' ' + esc(sup.name) + '</div>' : '') + '</div><div class="sep"></div>' + items + '<div class="sep"></div><button class="menu-item" data-logout>' + I('logout') + 'Log out</button></div></div>';
  }
  document.addEventListener('click', async e => {
    const sw = e.target.closest('[data-switch]');
    if (sw) { e.preventDefault(); const m = sw.dataset.switch; if (Auth.setMode(m)) { UI.toast('Switched to ' + (m === 'supplier' ? 'Supplier' : 'Customer') + ' Mode', 'success'); setTimeout(() => location.href = url(m === 'supplier' ? 'supplier/dashboard.html' : 'index.html'), 250); } }
    if (e.target.closest('[data-logout]')) { e.preventDefault(); if (await UI.confirm({ title: 'Log out?', message: 'You will need to sign in again to manage your rentals.', confirmText: 'Log out' })) { Auth.logout(); location.href = url('login.html'); } }
  });

  /* ---------- marketplace (customer & public) ---------- */
  function mountMarket(s, active) {
    const cats = Store.where('categories', c => c.active);
    const cartN = s ? Services.cartCount(s.user.id) : 0;
    const head = document.createElement('header'); head.className = 'mk-header';
    head.innerHTML = '<div class="container bar">' + brand() +
      '<form class="mk-search" id="globalSearch" role="search"><span>' + I('search') + '</span><input name="q" placeholder="Search chairs, tents, suppliers…" aria-label="Search" value="' + esc(U.params().q || '') + '"><button type="button" class="ai-toggle" id="aiSearchBtn" title="Describe your event and let AI build the search">' + I('sparkle') + '<span>AI Search</span></button></form>' +
      '<nav class="mk-nav">' + [['browse', 'customer/browse.html', 'Browse'], ['packages', 'customer/packages.html', 'Packages'], ['suppliers', 'customer/suppliers.html', 'Suppliers'], ['rentals', 'customer/rentals.html', 'My Rentals']].map(([k, h, l]) => '<a href="' + url(h) + '" class="' + (active === k ? 'active' : '') + '">' + l + '</a>').join('') + '</nav>' +
      '<div class="mk-actions">' + (s ? UI.notifDropdown(s) + '<a class="icon-btn" href="' + url('customer/cart.html') + '" aria-label="Rental list">' + I('cart') + (cartN ? '<span class="dot-count" id="cartCount">' + cartN + '</span>' : '') + '</a>' + profileMenu(s) : '<a class="btn btn-ghost btn-sm hide-xs" href="' + url('login.html') + '">Log in</a><a class="btn btn-primary btn-sm" href="' + url('register.html') + '">Sign up</a>') + '</div></div>' +
      (active === 'home' || active === 'browse' ? '<div class="cat-strip"><div class="container scroll-x">' + cats.map(c => '<a href="' + url('customer/browse.html', { category: c.id }) + '" class="' + (U.params().category === c.id ? 'active' : '') + '">' + esc(c.name) + '</a>').join('') + '</div></div>' : '');
    document.body.prepend(head);
    head.querySelector('#globalSearch').addEventListener('submit', e => { e.preventDefault(); U.go('customer/browse.html', { q: e.target.q.value.trim() }); });
    head.querySelector('#aiSearchBtn').addEventListener('click', () => { const q = head.querySelector('[name=q]').value.trim(); U.go('customer/ai.html', q ? { q } : null); });

    const foot = document.createElement('footer'); foot.className = 'mk-footer';
    const st = Store.settings();
    foot.innerHTML = '<div class="container"><div class="cols"><div>' + brand() + '<p class="mt-2">The party supplies rental marketplace for Filipino celebrations — discover, schedule, and rent from trusted local suppliers.</p><p class="small">' + esc(st.supportEmail) + ' · ' + esc(st.supportPhone) + '</p></div>' +
      '<div><h4>Rent</h4><ul><li><a href="' + url('customer/browse.html') + '">Browse supplies</a></li><li><a href="' + url('customer/packages.html') + '">Rental packages</a></li><li><a href="' + url('customer/ai.html') + '">AI event planner</a></li></ul></div>' +
      '<div><h4>Sell</h4><ul><li><a href="' + url('customer/start-selling.html') + '">Start selling</a></li><li><a href="' + url('customer/suppliers.html') + '">Our suppliers</a></li></ul></div>' +
      '<div><h4>Demo</h4><ul><li><a href="' + url('login.html') + '">Switch demo account</a></li><li><a href="#" data-reset-demo>Reset demo data</a></li></ul></div></div>' +
      '<hr style="border-color:rgba(255,255,255,.1)"><div class="small">© ' + new Date().getFullYear() + ' PartyRent · Frontend demonstration. Payments, notifications and AI are simulated in your browser.</div></div>';
    document.body.appendChild(foot);

    // floating bottom nav (mobile)
    const nav = document.createElement('nav'); nav.className = 'bottom-nav'; nav.setAttribute('aria-label', 'Main');
    const pend = s ? Store.where('requests', r => r.customerId === s.user.id && ['accepted', 'pending'].includes(r.status)).length : 0;
    nav.innerHTML = [['browse', 'customer/browse.html', 'grid', 'Browse'], ['suppliers', 'customer/suppliers.html', 'store', 'Suppliers']].map(([k, h, ic, l]) => '<a href="' + url(h) + '" class="' + (active === k || (k === 'browse' && active === 'home') ? 'active' : '') + '">' + I(ic) + l + '</a>').join('') +
      '<a href="' + url('customer/ai.html') + '" class="fab ' + (active === 'ai' ? 'active' : '') + '" aria-label="AI Planner">' + I('sparkle') + '</a>' +
      '<a href="' + url('customer/rentals.html') + '" class="' + (active === 'rentals' ? 'active' : '') + '">' + I('list') + 'Requests' + (pend ? '<span class="bn-dot">' + pend + '</span>' : '') + '</a>' +
      '<a href="' + url(s ? 'customer/profile.html' : 'login.html') + '" class="' + (active === 'profile' ? 'active' : '') + '">' + I('user') + 'Profile</a>';
    document.body.appendChild(nav);
    if (Store.settings().aiEnabled) mountChat(s);
  }

  /* ---------- dashboards ---------- */
  const NAV = {
    supplier: { tag: 'Supplier', items: [
      ['Overview'], ['dashboard', 'supplier/dashboard.html', 'grid', 'Dashboard'], ['requests', 'supplier/requests.html', 'list', 'Rental Requests', s => Store.where('requests', r => r.supplierId === s.user.supplierId && r.status === 'pending').length, 'orders.view'], ['schedule', 'supplier/schedule.html', 'truck', 'Fulfillment & Returns', null, 'orders.view'],
      ['Catalog'], ['products', 'supplier/products.html', 'box', 'Products', null, 'products.manage'], ['packages', 'supplier/packages.html', 'package', 'Packages', null, 'products.manage'], ['inventory', 'supplier/inventory.html', 'layers', 'Inventory', null, 'inventory.manage'], ['locations', 'supplier/locations.html', 'map', 'Inventory Locations', null, 'locations.manage'], ['calendar', 'supplier/calendar.html', 'calendar', 'Inventory Calendar', null, 'orders.view'],
      ['Money & reports'], ['payments', 'supplier/payments.html', 'card', 'Payments', s => Store.where('payments', p => p.status === 'pending' && (Store.request(p.requestId) || {}).supplierId === s.user.supplierId).length, 'payments.view'], ['damages', 'supplier/damages.html', 'tool', 'Damage & Compensation', null, 'returns.inspect'], ['reports', 'supplier/reports.html', 'bars', 'Business Reports', null, 'reports.view'],
      ['Shop'], ['shop', 'supplier/shop.html', 'store', 'Shop Profile & Settings', null, 'shop.manage'], ['team', 'supplier/team.html', 'users', 'Team & Roles', null, 'team.manage'], ['notifications', 'notifications.html', 'bell', 'Notifications']],
      bottom: [['dashboard', 'supplier/dashboard.html', 'grid', 'Dashboard'], ['products', 'supplier/products.html', 'box', 'Products', 'products.manage'], ['requests', 'supplier/requests.html', 'list', 'Requests', 'orders.view'], ['payments', 'supplier/payments.html', 'card', 'Payments', 'payments.view']] },
    cashier: { tag: 'Cashier', items: [
      ['Payments'], ['dashboard', 'cashier/dashboard.html', 'grid', 'Dashboard'], ['verification', 'cashier/verification.html', 'check', 'Payment Verification', s => Store.where('payments', p => p.status === 'pending' && (Store.request(p.requestId) || {}).supplierId === s.user.supplierId).length], ['record', 'cashier/record.html', 'cash', 'Record Payment'], ['balances', 'cashier/balances.html', 'clock', 'Installments & Overdue'],
      ['Records'], ['history', 'cashier/history.html', 'list', 'Payment History'], ['receipts', 'cashier/receipts.html', 'receipt', 'Receipts'], ['reports', 'cashier/reports.html', 'bars', 'Financial Reports'], ['notifications', 'notifications.html', 'bell', 'Notifications']],
      bottom: [['dashboard', 'cashier/dashboard.html', 'grid', 'Home'], ['verification', 'cashier/verification.html', 'check', 'Verify'], ['record', 'cashier/record.html', 'cash', 'Record'], ['history', 'cashier/history.html', 'list', 'History']] },
    admin: { tag: 'Admin', items: [
      ['Operations'], ['dashboard', 'admin/dashboard.html', 'grid', 'Dashboard'], ['users', 'admin/users.html', 'users', 'Customers & Suppliers'], ['applications', 'admin/applications.html', 'file', 'Supplier Applications', () => Store.where('suppliers', s => s.status === 'pending').length], ['listings', 'admin/listings.html', 'box', 'Listings & Packages'], ['categories', 'admin/categories.html', 'tag', 'Categories'],
      ['Monitoring'], ['transactions', 'admin/transactions.html', 'activity', 'Transactions & Reservations'], ['complaints', 'admin/complaints.html', 'flag', 'Complaints & Issues', () => Store.where('complaints', c => c.status !== 'resolved').length], ['activity', 'admin/activity.html', 'clock', 'Activity Log'], ['reports', 'admin/reports.html', 'bars', 'Reports'], ['notifications', 'notifications.html', 'bell', 'Notifications']],
      bottom: [['dashboard', 'admin/dashboard.html', 'grid', 'Home'], ['users', 'admin/users.html', 'users', 'Users'], ['applications', 'admin/applications.html', 'file', 'Apps'], ['transactions', 'admin/transactions.html', 'activity', 'Rentals']] },
    superadmin: { tag: 'Superadmin', items: [
      ['Platform'], ['dashboard', 'superadmin/dashboard.html', 'grid', 'Dashboard'], ['monitoring', 'superadmin/monitoring.html', 'activity', 'Platform Monitoring'], ['users', 'superadmin/users.html', 'users', 'Platform Users'], ['reports', 'superadmin/reports.html', 'bars', 'Platform Reports'],
      ['Access control'], ['admins', 'superadmin/admins.html', 'shield', 'Administrators'], ['roles', 'superadmin/roles.html', 'lock', 'Roles & Permissions'],
      ['System'], ['audit', 'superadmin/audit.html', 'file', 'Audit Logs'], ['settings', 'superadmin/settings.html', 'settings', 'System Settings'], ['notifications', 'notifications.html', 'bell', 'Notifications']],
      bottom: [['dashboard', 'superadmin/dashboard.html', 'grid', 'Home'], ['monitoring', 'superadmin/monitoring.html', 'activity', 'Monitor'], ['admins', 'superadmin/admins.html', 'shield', 'Admins'], ['audit', 'superadmin/audit.html', 'file', 'Audit']] }
  };

  function mountDash(s, role, active, title) {
    const cfg = NAV[role];
    const root = $('#app');
    const shell = document.createElement('div'); shell.className = 'dash';
    const sup = (role === 'supplier' || role === 'cashier') ? Auth.supplierOf(s.user) : null;
    const emp = Auth.isEmployee(s.user);
    // supplier employees only see the sections their organization role allows
    const allowed = it => role !== 'supplier' || !it[5] || Auth.orgCan(it[5], s.user);
    const items = []; let label = null;
    cfg.items.forEach(it => { if (it.length === 1) { label = it; return; } if (!allowed(it)) return; if (label) { items.push(label); label = null; } items.push(it); });
    const tag = role === 'supplier' && emp ? Auth.ORG_ROLES[Auth.orgRole(s.user)] : role === 'supplier' && sup && sup.type === 'organization' ? 'Org Admin' : cfg.tag;
    shell.innerHTML = '<aside class="side" id="side">' + brand(tag) +
      (sup ? '<div style="display:flex;gap:10px;align-items:center;padding:0 8px 12px"><div class="logo-tile" style="width:40px;height:40px;font-size:1.3rem;background:rgba(255,255,255,.1)">' + esc(sup.logo) + '</div><div class="small" style="color:#fff;font-weight:700;line-height:1.25">' + esc(sup.name) + '<div style="color:#8E83A8;font-weight:500">' + esc(sup.city) + (sup.type === 'organization' ? ' · Organization' : '') + '</div></div></div>' + (emp ? '' : '<button class="mode-switch" data-switch="customer">' + I('swap') + 'Switch to Customer Mode</button>') : '') +
      items.map(it => {
        if (it.length === 1) return '<div class="nav-label">' + esc(it[0]) + '</div>';
        const [k, h, ic, l, cnt] = it; const c = cnt ? cnt(s) : 0;
        return '<a class="nav ' + (active === k ? 'active' : '') + '" href="' + url(h) + '">' + I(ic) + '<span>' + esc(l) + '</span>' + (c ? '<span class="cnt">' + c + '</span>' : '') + '</a>';
      }).join('') +
      '<div class="side-foot">Signed in as <b style="color:#fff">' + esc(s.user.name) + '</b><br>' + esc(s.user.email) + '<br><a href="#" data-reset-demo style="color:#C4B5FD">Reset demo data</a></div></aside><div class="side-backdrop" id="sideBd"></div>' +
      '<div class="dash-body"><header class="dash-top"><button class="icon-btn menu-toggle" id="menuBtn" aria-label="Open menu">' + I('menu') + '</button><div class="ttl grow">' + esc(title || '') + '</div>' + UI.notifDropdown(s) + profileMenu(s) + '</header><main class="dash-main" id="main"></main></div>';
    root.appendChild(shell);
    const side = $('#side'), bd = $('#sideBd');
    const toggle = open => { side.classList.toggle('open', open); bd.classList.toggle('open', open); };
    $('#menuBtn').onclick = () => toggle(true); bd.onclick = () => toggle(false);
    const nav = document.createElement('nav'); nav.className = 'bottom-nav';
    nav.innerHTML = cfg.bottom.filter(b => role !== 'supplier' || !b[4] || Auth.orgCan(b[4], s.user)).map(([k, h, ic, l]) => '<a href="' + url(h) + '" class="' + (active === k ? 'active' : '') + '">' + I(ic) + l + '</a>').join('') + '<button type="button" id="moreBtn">' + I('menu') + 'More</button>';
    document.body.appendChild(nav);
    $('#moreBtn').onclick = () => toggle(true);
    return $('#main');
  }

  /* ---------- AI assistant chat widget ---------- */
  function mountChat(s) {
    const fab = document.createElement('button'); fab.className = 'chat-fab'; fab.setAttribute('aria-label', 'Open PartyRent Assist'); fab.innerHTML = I('message');
    const panel = document.createElement('section'); panel.className = 'chat-panel'; panel.setAttribute('aria-label', 'AI rental assistant');
    panel.innerHTML = '<div class="chat-head"><span class="avatar" style="background:rgba(255,255,255,.2)">' + I('sparkle').replace('<svg', '<svg style="width:18px;height:18px"') + '</span><div><div class="strong">PartyRent Assist</div><div class="small" style="opacity:.85">Demo AI · answers from real listings only</div></div><button class="icon-btn" data-chat-close aria-label="Close">' + I('x') + '</button></div><div class="chat-body" id="chatBody"></div><div class="chat-sugg" id="chatSugg"></div><form class="chat-input" id="chatForm"><input class="input" name="m" placeholder="Ask about prices, availability, payments…" autocomplete="off" aria-label="Message"><button class="btn btn-primary btn-icon" aria-label="Send">' + I('send') + '</button></form>';
    document.body.appendChild(fab); document.body.appendChild(panel);
    const body = panel.querySelector('#chatBody'), sugg = panel.querySelector('#chatSugg');
    let hist = []; try { hist = JSON.parse(sessionStorage.getItem('pr_chat') || '[]'); } catch (e) { /* ignore */ }
    const add = (who, html) => { const d = document.createElement('div'); d.className = 'msg ' + who; d.innerHTML = html; body.appendChild(d); body.scrollTop = body.scrollHeight; };
    const chips = list => { sugg.innerHTML = (list || []).map(c => '<button class="chip" type="button">' + esc(c) + '</button>').join(''); };
    const persist = () => { try { sessionStorage.setItem('pr_chat', JSON.stringify(hist.slice(-30))); } catch (e) { /* ignore */ } };
    if (!hist.length) hist.push({ who: 'bot', html: 'Hi! 👋 I\'m <b>PartyRent Assist</b>. Ask me about products, prices, availability, payment policies, delivery, or your request status. I only use information listed on PartyRent and never make bookings for you.' });
    hist.forEach(h => add(h.who, h.html));
    chips(['Plan a birthday for 50 guests', 'Tiffany chair prices', 'My request status', 'How do I rent?']);
    const send = text => {
      if (!text.trim()) return;
      add('me', esc(text)); hist.push({ who: 'me', html: esc(text) });
      const typing = document.createElement('div'); typing.className = 'msg bot'; typing.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px"></span>'; body.appendChild(typing); body.scrollTop = body.scrollHeight;
      setTimeout(() => { typing.remove(); const r = AI.chat(text, Auth.session()); add('bot', r.html); hist.push({ who: 'bot', html: r.html }); chips(r.chips); persist(); }, 380);
    };
    panel.querySelector('#chatForm').addEventListener('submit', e => { e.preventDefault(); const i = e.target.m; send(i.value); i.value = ''; });
    sugg.addEventListener('click', e => { const b = e.target.closest('.chip'); if (b) send(b.textContent); });
    fab.onclick = () => { panel.classList.toggle('open'); if (panel.classList.contains('open')) panel.querySelector('input').focus(); };
    panel.querySelector('[data-chat-close]').onclick = () => panel.classList.remove('open');
    g.openChat = (q) => { panel.classList.add('open'); if (q) send(q); };
  }

  document.addEventListener('click', async e => {
    if (!e.target.closest('[data-reset-demo]')) return;
    e.preventDefault();
    if (await UI.confirm({ title: 'Reset demo data?', message: 'All changes you made (requests, payments, products, accounts) will be replaced with the original sample data and you will be signed out.', confirmText: 'Reset data', danger: true })) { Store.reset(); Auth.logout(); location.href = url('login.html'); }
  });

  /* ---------- post-login prompt: select rental dates or just browse ---------- */
  function showDatePrompt(ask) {
    const dest = () => { if (ask.next) location.reload(); else location.href = url('customer/browse.html'); };
    let chosen = false;
    const max = Store.settings().maxRentalDays || 30;
    const m = UI.modal({
      title: 'How would you like to browse PartyRent?', size: 'sm', noFocus: true,
      body: '<p class="muted small" style="margin-top:0">Pick your rental dates to see what is available for your event, or look around first.</p>' +
        '<div class="card card-flat soft" style="padding:16px"><div class="strong mb-1">' + I('calendar').replace('<svg', '<svg style="width:16px;vertical-align:-3px"') + ' Select rental dates</div>' +
        '<div class="range-fields"><div class="field mb-0"><label for="dpS" class="small">Start date</label><input class="input" type="date" id="dpS" min="' + U.today() + '"></div><div class="field mb-0"><label for="dpE" class="small">End date</label><input class="input" type="date" id="dpE" min="' + U.today() + '"></div></div>' +
        '<div class="row between mt-2" style="flex-wrap:wrap;gap:6px"><span class="small strong" id="dpRange" aria-live="polite">No dates selected</span><span class="badge b-purple plain" id="dpDays" style="display:none"></span></div>' +
        '<div class="small mt-1" id="dpErr" style="color:var(--red)" role="alert"></div>' +
        '<button type="button" class="btn btn-primary btn-block mt-2" id="dpGo" disabled>Continue with these dates</button></div>' +
        '<div class="divider-text">or</div><button type="button" class="btn btn-outline btn-block" id="dpBrowse">I\'m Just Browsing</button>',
      foot: false,
      onClose: () => { if (!chosen) RentalPeriod.clear(); }
    });
    const S = m.el.querySelector('#dpS'), E = m.el.querySelector('#dpE');
    const sync = () => {
      if (S.value) { E.min = S.value; E.max = U.addDays(S.value, max - 1); if (!E.value || E.value < S.value) E.value = S.value; }
      const err = S.value && E.value ? RentalPeriod.validate(S.value, E.value) : null;
      const ok = S.value && E.value && !err;
      m.el.querySelector('#dpRange').textContent = S.value && E.value ? RentalPeriod.rangeLabel(S.value, E.value) : 'No dates selected';
      const n = S.value && E.value && E.value >= S.value ? U.diffDays(S.value, E.value) + 1 : 0;
      const dd = m.el.querySelector('#dpDays'); dd.style.display = n ? '' : 'none'; dd.textContent = n ? RentalPeriod.durationLabel(n) : '';
      m.el.querySelector('#dpErr').textContent = err || '';
      m.el.querySelector('#dpGo').disabled = !ok;
    };
    S.addEventListener('change', sync); E.addEventListener('change', sync); S.addEventListener('input', sync); E.addEventListener('input', sync);
    m.el.querySelector('#dpGo').onclick = () => {
      const err = RentalPeriod.validate(S.value, E.value); if (err) { m.el.querySelector('#dpErr').textContent = err; return; }
      RentalPeriod.set(S.value, E.value); chosen = true; m.close(); dest();
    };
    m.el.querySelector('#dpBrowse').onclick = () => { RentalPeriod.clear(); chosen = true; m.close(); dest(); };
  }

  /* ---------- bootstrap ---------- */
  g.Pages = g.Pages || {};
  function start() {
    const b = document.body;
    const key = b.dataset.page; const role = b.dataset.role || 'public';
    Store.load();
    try { Services.scanOverdue(); } catch (e) { console.error(e); }
    let s;
    try { s = Auth.guard(role === 'auth' ? 'public' : role); } catch (e) { return; }
    const fn = key.split('.').reduce((o, k) => o && o[k], g.Pages);
    if (!fn) { $('#app').innerHTML = UI.empty('🧭', 'Page not found', key); return; }
    const params = U.params();
    const ctx = { session: s, params, role };
    const title = b.dataset.title || '';
    if (['supplier', 'admin', 'superadmin', 'cashier'].includes(role) || (role === 'any' && s && Auth.activeRole(s.user, s.mode) !== 'customer')) {
      const r = role === 'any' ? Auth.activeRole(s.user, s.mode) : role;
      ctx.el = mountDash(s, r, b.dataset.nav, title);
      ctx.dashRole = r;
    } else if (role !== 'auth') {
      mountMarket(s, b.dataset.nav);
      if (s && Auth.activeRole(s.user, s.mode) === 'customer') { const ask = RentalPeriod.takePrompt(s.user.id); if (ask) setTimeout(() => showDatePrompt(ask), 150); }
      const main = document.createElement('div'); main.className = 'mk-main'; const c = document.createElement('div'); c.className = 'container'; main.appendChild(c); $('#app').appendChild(main);
      ctx.el = c;
    } else ctx.el = $('#app');
    if (title) document.title = title + ' · PartyRent';
    if (role === 'supplier') {
      const nav = NAV.supplier.items.find(it => it[0] === b.dataset.nav && it.length > 1);
      const perm = { 'supplier.productForm': 'products.manage', 'supplier.request': 'orders.view' }[key] || (nav && nav[5]);
      if (perm && !Auth.orgCan(perm, s.user)) { ctx.el.innerHTML = '<div class="card">' + UI.empty('🔒', 'No access', 'Your role (' + esc(Auth.ORG_ROLES[Auth.orgRole(s.user)] || 'Supplier') + ') does not include this section. Ask your Organization Admin if you need access.', '<a class="btn" href="' + url('supplier/dashboard.html') + '">Back to dashboard</a>') + '</div>'; return; }
    }
    try { fn(ctx); } catch (err) { console.error(err); ctx.el.innerHTML = '<div class="alert alert-danger">' + I('alert') + '<div><b>Something went wrong while loading this page.</b><br>' + esc(err.message) + '</div></div>'; }
  }
  g.App = { start, mountMarket, mountDash, refreshCart() { const s = Auth.session(); if (!s) return; const n = Services.cartCount(s.user.id); let el = $('#cartCount'); const a = document.querySelector('a[aria-label="Rental list"]'); if (!a) return; if (!el && n) { el = document.createElement('span'); el.className = 'dot-count'; el.id = 'cartCount'; a.appendChild(el); } if (el) { if (n) el.textContent = n; else el.remove(); } } };
})(window);
