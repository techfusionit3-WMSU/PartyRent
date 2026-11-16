/* =========================================================
   PartyRent — Superadmin pages
   Platform-wide monitoring, administrator & role management,
   audit logs and system configuration.
   ========================================================= */
(function () {
  'use strict';
  const { esc, url, $, $$ } = U;
  const I = Icons.icon;
  const SA = Pages.superadmin = {};
  const AD = Pages.admin;

  function platformMoney() {
    const ver = Store.where('payments', p => p.status === 'verified');
    const gross = U.sum(ver.filter(p => Finance.RENTAL_TYPES.includes(p.type)), p => p.amount) - U.sum(ver.filter(p => p.type === 'refund'), p => p.amount);
    return { ver, gross, commission: gross * (Store.settings().commissionPct || 0) / 100 };
  }

  SA.dashboard = function (ctx) {
    const users = Store.all('users'); const reqs = Store.all('requests'); const sups = Store.all('suppliers');
    const m = platformMoney(); const months = Charts.months(6);
    const fins = reqs.filter(r => Finance.ACTIVE.includes(r.status)).map(r => Finance.summary(r));
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Platform overview</h1><p>Everything on PartyRent at a glance — no supplier dashboard needed.</p></div><div class="row wrap"><a class="btn" href="' + url('superadmin/audit.html') + '">' + I('file') + 'Audit logs</a><a class="btn btn-primary" href="' + url('superadmin/admins.html') + '">' + I('shield') + 'Manage admins</a></div></div>' +
      '<div class="stats mb-3">' + UI.stat('Total users', users.length, users.filter(u => u.status !== 'active').length + ' inactive / suspended', 'users', 'pink', url('superadmin/users.html')) + UI.stat('Customers', users.filter(u => u.roles.includes('customer')).length, users.filter(u => u.supplierId).length + ' also suppliers', 'user', 'purple') + UI.stat('Suppliers', sups.filter(s => s.status === 'approved').length, sups.filter(s => s.status === 'pending').length + ' pending', 'store', 'teal') + UI.stat('Administrators', users.filter(u => u.roles.includes('admin')).length, users.filter(u => u.roles.includes('cashier')).length + ' cashier(s)', 'shield', 'sky', url('superadmin/admins.html')) + UI.stat('Rental transactions', reqs.length, reqs.filter(r => Finance.ACTIVE.includes(r.status)).length + ' active', 'activity', 'purple', url('superadmin/monitoring.html')) + UI.stat('Gross rental value collected', U.moneyShort(m.gross), 'verified, net of refunds', 'chart', 'teal') + UI.stat('Est. platform commission', U.moneyShort(m.commission), Store.settings().commissionPct + '% setting', 'cash', 'gold') + UI.stat('Outstanding balances', U.moneyShort(U.sum(fins, f => f.balance)), fins.filter(f => f.isOverdue).length + ' overdue', 'alert', 'red') + '</div>' +
      '<div class="grid grid-2"><div class="card"><h3>Rental trends</h3><div id="s1"></div></div><div class="card"><h3>Collections per month</h3><div id="s2"></div></div><div class="card"><h3>Supplier performance</h3>' + UI.table([{ label: 'Supplier', render: s => esc(s.name) }, { label: 'Rentals', cls: 'num', render: s => reqs.filter(r => r.supplierId === s.id && !['pending', 'declined'].includes(r.status)).length }, { label: 'Value', cls: 'num', render: s => U.moneyShort(U.sum(reqs.filter(r => r.supplierId === s.id && !['pending', 'declined', 'cancelled'].includes(r.status)), r => r.pricing.payable)) }, { label: 'Rating', cls: 'num', render: s => s.rating || '—' }], sups.filter(s => s.status === 'approved').sort((a, b) => b.rating - a.rating), { noCards: true }) + '</div><div class="card"><div class="card-head"><h3>Latest system activity</h3><a class="small strong" href="' + url('superadmin/audit.html') + '">All logs</a></div>' + Store.all('audit').slice().sort((a, b) => b.at.localeCompare(a.at)).slice(0, 7).map(a => '<div style="padding:8px 0;border-bottom:1px solid var(--line)"><b class="small">' + esc(a.action) + '</b> <span class="badge b-gray plain">' + U.cap(a.role) + '</span><div class="small muted">' + esc(a.details) + ' · ' + U.timeAgo(a.at) + '</div></div>').join('') + '</div></div>';
    Charts.line($('#s1'), months.map(mm => ({ label: mm.label, value: reqs.filter(r => r.createdAt.slice(0, 7) === mm.key).length })), { color: '#7C3AED' });
    Charts.bars($('#s2'), months.map(mm => ({ label: mm.label, value: U.sum(m.ver.filter(p => p.date.slice(0, 7) === mm.key && Finance.RENTAL_TYPES.includes(p.type)), p => p.amount) })), { money: true, color: '#0D9488' });
  };

  SA.monitoring = function (ctx) {
    const reqs = Store.all('requests');
    const pays = Store.all('payments');
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Platform Monitoring</h1><p>Transactions, payments and inventory health across every supplier.</p></div></div><div class="stats mb-3">' + UI.stat('Pending requests', reqs.filter(r => r.status === 'pending').length, '', 'clock', 'gold') + UI.stat('Active rentals', reqs.filter(r => Finance.ACTIVE.includes(r.status)).length, '', 'calendar', 'pink') + UI.stat('Payments pending verification', pays.filter(p => p.status === 'pending').length, '', 'card', 'gold') + UI.stat('Rejected payments', pays.filter(p => p.status === 'rejected').length, '', 'x', 'red') + UI.stat('Overdue accounts', reqs.filter(r => Finance.ACTIVE.includes(r.status) && Finance.summary(r).isOverdue).length, '', 'alert', 'red') + UI.stat('Damaged / missing units', U.sum(Store.all('products'), p => (p.damaged || 0) + (p.missing || 0)), 'platform-wide', 'tool', 'purple') + '</div><div id="tx"></div>';
    const sub = { el: $('#tx'), params: ctx.params, session: ctx.session };
    AD.transactions(sub);
    const h = sub.el.querySelector('.page-head'); if (h) h.remove();
  };

  SA.users = function (ctx) {
    let q = '', role = '', st = '';
    const draw = () => {
      const list = Store.all('users').filter(u => (!role || (role === 'supplier' ? !!u.supplierId : u.roles.includes(role))) && (!st || u.status === st) && (!q || (u.name + ' ' + u.email).toLowerCase().includes(q)));
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Platform Users</h1><p>All accounts across every role.</p></div><div class="row wrap"><input class="input input-sm" id="q" placeholder="Search" style="width:200px" value="' + esc(q) + '" aria-label="Search"><select class="input input-sm" id="rl" style="width:auto" aria-label="Role"><option value="">All roles</option>' + ['customer', 'supplier', 'cashier', 'admin', 'superadmin'].map(r => '<option value="' + r + '"' + (r === role ? ' selected' : '') + '>' + U.cap(r) + '</option>').join('') + '</select><select class="input input-sm" id="st" style="width:auto" aria-label="Status"><option value="">All statuses</option>' + ['active', 'suspended', 'deactivated'].map(s => '<option' + (s === st ? ' selected' : '') + '>' + s + '</option>').join('') + '</select></div></div><div class="card pad-0">' + UI.table([{ label: 'User', render: u => '<div class="row"><span class="avatar">' + U.initials(u.name) + '</span><div><b>' + esc(u.name) + '</b><div class="small muted">' + esc(u.email) + '</div></div></div>' }, { label: 'Roles', render: u => u.roles.map(r => '<span class="badge b-pink plain">' + U.cap(r) + '</span>').join(' ') + (u.supplierId ? ' <span class="badge b-purple plain">Supplier (' + (Store.supplier(u.supplierId) || {}).status + ')</span>' : '') }, { label: 'Joined', render: u => U.fmtDate(u.createdAt.slice(0, 10)) }, { label: 'Last login', render: u => u.lastLogin ? U.timeAgo(u.lastLogin) : '—' }, { label: 'Status', render: u => AD.statusBadge(u.status) }, { label: '', render: u => '<div class="btn-group"><button class="btn btn-xs" data-view="' + u.id + '">View</button>' + (u.roles.includes('superadmin') ? '' : u.status === 'active' ? '<button class="btn btn-xs" data-sus="' + u.id + '">Suspend</button>' : '<button class="btn btn-xs btn-success" data-act="' + u.id + '">Activate</button>') + '</div>' }], list) + '</div>';
      $('#q').onchange = e => { q = e.target.value.toLowerCase(); draw(); }; $('#rl').onchange = e => { role = e.target.value; draw(); }; $('#st').onchange = e => { st = e.target.value; draw(); };
      $$('[data-view]').forEach(b => b.onclick = () => AD.viewUser(Store.user(b.dataset.view)));
      $$('[data-sus]').forEach(b => b.onclick = () => AD.setUserStatus(Store.user(b.dataset.sus), 'suspended', null, draw));
      $$('[data-act]').forEach(b => b.onclick = () => AD.setUserStatus(Store.user(b.dataset.act), 'active', null, draw));
    };
    draw();
  };

  SA.reports = function (ctx) {
    const users = Store.all('users'); const reqs = Store.all('requests'); const sups = Store.all('suppliers'); const months = Charts.months(6); const m = platformMoney();
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Platform Reports</h1><p>Growth, business activity and administrative activity.</p></div></div><div class="grid grid-2"><div class="card"><h3>User growth</h3><div id="p1"></div></div><div class="card"><h3>Supplier growth</h3><div id="p2"></div></div><div class="card"><h3>Rental trends (requests)</h3><div id="p3"></div></div><div class="card"><h3>Platform transaction value</h3><div id="p4"></div></div><div class="card"><h3>Rental status mix</h3><div id="p5"></div></div><div class="card"><h3>Administrative activity</h3><div id="p6"></div></div></div>';
    let cum = 0; const base = users.filter(u => u.createdAt.slice(0, 7) < months[0].key).length; cum = base;
    Charts.line($('#p1'), months.map(mm => { cum += users.filter(u => u.createdAt.slice(0, 7) === mm.key).length; return { label: mm.label, value: cum }; }), { color: '#E11D74' });
    let cs = sups.filter(s => s.status === 'approved' && (s.approvedAt || '').slice(0, 7) < months[0].key).length;
    Charts.line($('#p2'), months.map(mm => { cs += sups.filter(s => s.status === 'approved' && (s.approvedAt || '').slice(0, 7) === mm.key).length; return { label: mm.label, value: cs }; }), { color: '#0D9488' });
    Charts.bars($('#p3'), months.map(mm => ({ label: mm.label, value: reqs.filter(r => r.createdAt.slice(0, 7) === mm.key).length })), { color: '#7C3AED' });
    Charts.bars($('#p4'), months.map(mm => ({ label: mm.label, value: U.sum(m.ver.filter(p => p.date.slice(0, 7) === mm.key && Finance.RENTAL_TYPES.includes(p.type)), p => p.amount) })), { money: true, color: '#E11D74' });
    Charts.donut($('#p5'), [['Pending', ['pending']], ['Active', Finance.ACTIVE], ['Completed', ['completed']], ['Declined', ['declined']], ['Cancelled', ['cancelled']]].map(([l, s]) => ({ label: l, value: reqs.filter(r => s.includes(r.status)).length })), { center: 'requests' });
    const acts = {}; Store.where('audit', a => ['admin', 'superadmin', 'cashier'].includes(a.role)).forEach(a => { const n = (Store.user(a.actorId) || {}).name || a.actorId; acts[n] = (acts[n] || 0) + 1; });
    Charts.hbars($('#p6'), Object.entries(acts).map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value), { color: '#7C3AED' });
  };

  SA.admins = function (ctx) {
    const draw = () => {
      const staff = Store.where('users', u => u.roles.some(r => ['admin', 'superadmin'].includes(r)));
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Administrator Management</h1><p>Create and manage PartyRent platform Admin accounts. Admins never receive Superadmin privileges automatically. Cashiers and inventory staff are no longer platform accounts — each supplier\'s Organization Admin creates them for their own shop.</p></div><button class="btn btn-primary" id="add">' + I('plus') + 'Create staff account</button></div><div class="card pad-0">' + UI.table([{ label: 'Staff member', render: u => '<div class="row"><span class="avatar">' + U.initials(u.name) + '</span><div><b>' + esc(u.name) + '</b><div class="small muted">' + esc(u.email) + '</div></div></div>' }, { label: 'Role', render: u => '<span class="badge ' + (u.roles.includes('superadmin') ? 'b-purple' : u.roles.includes('admin') ? 'b-pink' : 'b-teal') + '">' + U.cap(u.roles[0]) + '</span>' }, { label: 'Permissions', render: u => u.roles.includes('superadmin') ? '<span class="small">All permissions</span>' : '<span class="small">' + (u.roles.includes('admin') ? (u.adminPerms ? u.adminPerms.length + ' custom' : 'Role default (' + Store.load().permissions.admin.length + ')') : (u.cashierPerms ? u.cashierPerms.length + ' custom' : 'Role default (' + Store.load().permissions.cashier.length + ')')) + '</span>' }, { label: 'Last login', render: u => u.lastLogin ? U.timeAgo(u.lastLogin) : '—' }, { label: 'Status', render: u => AD.statusBadge(u.status) }, { label: '', render: u => u.roles.includes('superadmin') ? '<span class="small muted">Protected</span>' : '<div class="btn-group"><button class="btn btn-xs" data-perm="' + u.id + '">Access</button>' + (u.status === 'active' ? '<button class="btn btn-xs btn-danger" data-deact="' + u.id + '">Deactivate</button>' : '<button class="btn btn-xs btn-success" data-act="' + u.id + '">Reactivate</button>') + '</div>' }], staff) + '</div>';
      $('#add').onclick = create;
      $$('[data-perm]').forEach(b => b.onclick = () => perms(Store.user(b.dataset.perm)));
      $$('[data-deact]').forEach(b => b.onclick = () => AD.setUserStatus(Store.user(b.dataset.deact), 'deactivated', null, draw));
      $$('[data-act]').forEach(b => b.onclick = () => AD.setUserStatus(Store.user(b.dataset.act), 'active', null, draw));
    };
    function create() {
      const m = UI.modal({ title: 'Create staff account', body: '<form id="af" novalidate><div class="field"><label>Full name</label><input class="input" name="name" required minlength="3"></div><div class="form-grid"><div class="field"><label>Email</label><input class="input" type="email" name="email" required></div><div class="field"><label>Contact number</label><input class="input" name="phone" data-phone required></div></div><input type="hidden" name="role" value="admin"><div class="alert alert-info">' + I('info') + '<div class="small">Platform Admin account. Supplier cashiers are created by the supplier\'s Organization Admin under Team &amp; Roles.</div></div><div class="field"><label>Temporary password</label><input class="input" name="password" required minlength="8" value="' + Math.random().toString(36).slice(2, 10) + 'A1"></div><p class="small muted mb-0">The new account starts with the role\'s default permissions. You can restrict them afterwards.</p></form>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="ok">Create account</button>' });
      m.el.querySelector('#ok').onclick = async () => {
        const f = m.el.querySelector('#af'); if (!UI.validate(f)) return; const d = UI.formData(f);
        if (Store.all('users').some(u => u.email.toLowerCase() === d.email.toLowerCase())) return UI.toast('That email is already registered.', 'error');
        if (!await UI.confirm({ title: 'Create ' + d.role + ' account?', html: '<b>' + esc(d.name) + '</b> (' + esc(d.email) + ') will get ' + d.role + ' access.', confirmText: 'Create' })) return;
        Store.insert('users', { id: U.uid('u'), name: d.name.trim(), email: d.email.trim().toLowerCase(), phone: d.phone, address: 'PartyRent HQ, Ortigas Center, Pasig', password: d.password, roles: [d.role], status: 'active', createdAt: new Date().toISOString() });
        Store.audit('Created ' + d.role + ' account', d.name + ' (' + d.email + ')');
        m.close(); UI.toast(U.cap(d.role) + ' account created. Share the temporary password securely.', 'success'); draw();
      };
    }
    function perms(u) {
      const isAdmin = u.roles.includes('admin');
      const all = isAdmin ? Auth.ALL_ADMIN_PERMS : Auth.ALL_CASHIER_PERMS;
      const cur = (isAdmin ? u.adminPerms : u.cashierPerms) || Store.load().permissions[isAdmin ? 'admin' : 'cashier'];
      const m = UI.modal({ title: 'Access for ' + u.name, body: '<p class="small muted">Permissions for this ' + u.roles[0] + '. Superadmin-only functions (admin management, roles, system settings, audit) can never be granted here.</p><div class="stack-sm">' + all.map(([k, l]) => '<label class="check"><input type="checkbox" value="' + k + '"' + (cur.includes(k) ? ' checked' : '') + '> ' + esc(l) + ' <span class="small muted">(' + k + ')</span></label>').join('') + '</div><label class="check mt-2"><input type="checkbox" id="useDef"' + ((isAdmin ? u.adminPerms : u.cashierPerms) ? '' : ' checked') + '> Use role defaults instead</label>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="ok">Save access</button>' });
      m.el.querySelector('#ok').onclick = async () => {
        const sel = $$('.stack-sm input:checked', m.el).map(i => i.value); const def = m.el.querySelector('#useDef').checked;
        if (!await UI.confirm({ title: 'Update access?', message: 'This changes what ' + u.name + ' can do immediately.', confirmText: 'Update' })) return;
        const key = isAdmin ? 'adminPerms' : 'cashierPerms';
        if (def) delete u[key]; else u[key] = sel; Store.save();
        Store.audit('Updated permissions', u.name + ': ' + (def ? 'role defaults' : sel.join(', ')));
        m.close(); UI.toast('Access updated.', 'success'); draw();
      };
    }
    draw();
  };

  SA.roles = function (ctx) {
    const draw = () => {
      const P = Store.load().permissions;
      const matrix = (role, all) => '<div class="card"><div class="card-head"><h3>' + U.cap(role) + ' role — default permissions</h3><button class="btn btn-sm btn-primary" data-save="' + role + '">Save</button></div><div class="table-wrap"><table class="table"><tbody>' + all.map(([k, l]) => '<tr><td><b>' + esc(l) + '</b><div class="small muted">' + k + '</div></td><td class="right"><input type="checkbox" data-r="' + role + '" value="' + k + '"' + (P[role].includes(k) ? ' checked' : '') + ' style="width:20px;height:20px;accent-color:var(--brand)" aria-label="' + esc(l) + '"></td></tr>').join('') + '</tbody></table></div></div>';
      const fixed = [['Customer', 'Browse, rent, pay, track own rentals, apply to sell (as an Individual or Organization supplier)'], ['Organization Admin (supplier owner)', 'Their own supplier account only: shop profile, employees & roles, products, inventory & locations, requests, payments and reports'], ['Cashier (supplier employee)', 'One supplier\'s payments, receipts, refunds and financial reports — never other suppliers, platform settings or customer accounts'], ['Inventory Staff (supplier employee)', 'One supplier\'s products, stock, locations and return inspections'], ['Staff (supplier employee)', 'Assigned operations: deliveries, pickups and returns'], ['Admin (platform)', 'Platform operations per the permissions below — never Superadmin functions'], ['Superadmin', 'Everything, including admin accounts, roles, audit logs and system settings']];
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Roles & Permissions</h1><p>Six roles with separated responsibilities. Individual staff can be further restricted on the Administrators page.</p></div></div><div class="card mb-3"><h3>Role overview</h3>' + UI.table([{ label: 'Role', render: x => '<b>' + x[0] + '</b>' }, { label: 'Scope', render: x => esc(x[1]) }], fixed) + '<p class="small muted mt-1 mb-0">Customer and Supplier share one account: approved suppliers switch modes without logging in again.</p></div><div class="grid grid-2">' + matrix('admin', Auth.ALL_ADMIN_PERMS) + matrix('cashier', Auth.ALL_CASHIER_PERMS) + '</div>';
      $$('[data-save]').forEach(b => b.onclick = async () => { const role = b.dataset.save; const sel = $$('[data-r="' + role + '"]:checked').map(i => i.value); if (!await UI.confirm({ title: 'Update ' + role + ' defaults?', message: 'Applies to every ' + role + ' using role defaults.', confirmText: 'Save' })) return; Store.load().permissions[role] = sel; Store.save(); Store.audit('Changed role permissions', U.cap(role) + ': ' + sel.join(', ')); UI.toast('Role permissions saved.', 'success'); draw(); });
    };
    draw();
  };

  SA.audit = function (ctx) { AD.activity(ctx, { title: 'Audit Logs', sub: 'Complete audit trail of financial, inventory and administrative actions.' }); };

  SA.settings = function (ctx) {
    const s = Store.settings();
    const draw = () => {
      ctx.el.innerHTML = '<div class="page-head"><div><h1>System Settings</h1><p>Platform-wide configuration.</p></div></div><div class="grid grid-2"><form class="card" id="sf" novalidate><h3>Platform</h3><div class="field"><label>Platform name</label><input class="input" name="platformName" required value="' + esc(s.platformName) + '"></div><div class="form-grid"><div class="field"><label>Support email</label><input class="input" type="email" name="supportEmail" required value="' + esc(s.supportEmail) + '"></div><div class="field"><label>Support phone</label><input class="input" name="supportPhone" required value="' + esc(s.supportPhone) + '"></div><div class="field"><label>Platform commission (%)</label><input class="input" type="number" min="0" max="30" step="0.5" name="commissionPct" value="' + s.commissionPct + '"></div><div class="field"><label>Overdue grace period (days)</label><input class="input" type="number" min="0" max="14" name="overdueGraceDays" value="' + s.overdueGraceDays + '"></div><div class="field"><label>Max rental dates per item</label><input class="input" type="number" min="1" max="90" name="maxRentalDays" value="' + s.maxRentalDays + '"></div></div><label class="check mb-1"><input type="checkbox" name="requireSupplierApproval"' + (s.requireSupplierApproval ? ' checked' : '') + '> Require admin approval for new suppliers</label><label class="check mb-2"><input type="checkbox" name="aiEnabled"' + (s.aiEnabled ? ' checked' : '') + '> Enable AI assistant (chat widget). Core rental features work without it.</label><button class="btn btn-primary">Save settings</button></form>' +
        '<div class="stack"><div class="card"><h3>Categories</h3><p class="small muted">Manage marketplace categories.</p><div class="row wrap gap-sm mb-2">' + Store.all('categories').map(c => '<span class="tag">' + esc(c.name) + (c.active ? '' : ' (hidden)') + '</span>').join('') + '</div><a class="btn btn-sm" href="' + url('admin/categories.html') + '" id="catLink">Open category manager</a></div>' +
        '<div class="card"><h3>Data & integrations</h3><dl class="kv small"><dt>Storage</dt><dd>' + (Store.isMemoryOnly() ? 'In-memory (browser storage unavailable)' : 'Browser localStorage (demo)') + '</dd><dt>Payment gateway</dt><dd>Not connected — payments are simulated and verified manually by cashiers</dd><dt>AI provider</dt><dd>Local rule-based demo engine (AI.provider) — API-ready</dd><dt>Notifications</dt><dd>In-app only (email/SMS not connected)</dd></dl></div>' +
        '<div class="card" style="border-color:#F8C4C4"><h3>Danger zone</h3><p class="small muted">Restore all original sample data. Every change made in this browser is lost.</p><button class="btn btn-danger btn-sm" data-reset-demo>Reset demo data</button></div></div></div>';
      $('#catLink').onclick = e => { e.preventDefault(); Pages.admin.categories(ctx); };
      $('#sf').onsubmit = async e => {
        e.preventDefault(); const f = e.target; if (!UI.validate(f)) return; const d = UI.formData(f);
        if (!await UI.confirm({ title: 'Save system settings?', message: 'These settings affect every user on the platform.', confirmText: 'Save' })) return;
        const changes = [];
        ['platformName', 'supportEmail', 'supportPhone'].forEach(k => { if (s[k] !== d[k]) { changes.push(k); s[k] = d[k]; } });
        ['commissionPct', 'overdueGraceDays', 'maxRentalDays'].forEach(k => { if (s[k] !== +d[k]) { changes.push(k + '=' + d[k]); s[k] = +d[k]; } });
        ['requireSupplierApproval', 'aiEnabled'].forEach(k => { if (s[k] !== !!d[k]) { changes.push(k + '=' + !!d[k]); s[k] = !!d[k]; } });
        Store.save(); if (changes.length) Store.audit('Changed platform settings', changes.join(', '));
        UI.toast(changes.length ? 'Settings saved.' : 'No changes.', 'success'); draw();
      };
    };
    draw();
  };
})();
