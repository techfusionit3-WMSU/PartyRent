/* =========================================================
   PartyRent — Admin pages
   Every action checks the admin's assigned permissions
   (Auth.can). Admins do not have Superadmin privileges.
   ========================================================= */
(function () {
  'use strict';
  const { esc, url, $, $$ } = U;
  const I = Icons.icon;
  const AD = Pages.admin = {};
  const Sh = () => Pages.shared;

  const denied = perm => '<div class="card">' + UI.empty('🔒', 'Access restricted', 'Your administrator account does not have the "' + perm + '" permission. Ask a Superadmin to grant it.') + '</div>';
  AD.denied = denied;
  const guardPerm = (ctx, perm) => { if (Auth.can(perm)) return true; ctx.el.innerHTML = denied(perm); return false; };
  const statusBadge = s => '<span class="badge ' + ({ active: 'b-green', suspended: 'b-red', deactivated: 'b-gray', approved: 'b-green', pending: 'b-gold', rejected: 'b-red' }[s] || 'b-gray') + '">' + U.cap(s) + '</span>';
  AD.statusBadge = statusBadge;

  /** Change a user's account status with confirmation + audit. */
  AD.setUserStatus = async function (u, status, perm, redraw) {
    if (perm && !Auth.can(perm)) return UI.toast('You do not have permission to do this.', 'error');
    if (u.roles.includes('superadmin')) return UI.toast('Superadmin accounts cannot be changed here.', 'error');
    if (u.roles.includes('admin') && !Auth.session().user.roles.includes('superadmin')) return UI.toast('Only a Superadmin can change administrator accounts.', 'error');
    const reason = status === 'active' ? '' : await UI.prompt({ title: (status === 'suspended' ? 'Suspend ' : 'Deactivate ') + u.name + '?', message: 'They will be signed out and unable to log in until reactivated.', label: 'Reason (recorded in audit log)', required: true, textarea: true, confirmText: status === 'suspended' ? 'Suspend' : 'Deactivate', danger: true });
    if (status !== 'active' && reason == null) return;
    if (status === 'active' && !await UI.confirm({ title: 'Activate ' + u.name + '?', message: 'They will be able to log in again.', confirmText: 'Activate' })) return;
    u.status = status; u.statusReason = reason || ''; Store.save();
    Store.audit((status === 'active' ? 'Activated' : status === 'suspended' ? 'Suspended' : 'Deactivated') + ' account', u.name + ' (' + u.email + ')' + (reason ? ' — ' + reason : ''));
    UI.toast(u.name + ' is now ' + status + '.', 'success'); redraw();
  };

  AD.viewUser = function (u) {
    const reqs = Store.where('requests', r => r.customerId === u.id);
    const sup = u.supplierId ? Store.supplier(u.supplierId) : null;
    UI.modal({ title: u.name, size: 'lg', body: '<div class="row mb-2"><span class="avatar lg">' + U.initials(u.name) + '</span><div><b>' + esc(u.email) + '</b><div class="small muted">' + esc(u.phone) + ' · ' + esc(u.address) + '</div><div class="row gap-sm mt-1">' + u.roles.map(r => '<span class="badge b-pink plain">' + U.cap(r) + '</span>').join('') + (sup ? '<span class="badge b-purple plain">Supplier: ' + esc(sup.name) + ' (' + sup.status + ')</span>' : '') + statusBadge(u.status) + '</div></div></div><dl class="kv"><dt>Joined</dt><dd>' + U.fmtDateTime(u.createdAt) + '</dd><dt>Last login</dt><dd>' + (u.lastLogin ? U.fmtDateTime(u.lastLogin) : '—') + '</dd>' + (u.statusReason ? '<dt>Status reason</dt><dd>' + esc(u.statusReason) + '</dd>' : '') + '</dl>' + (reqs.length ? '<h4 class="mt-2">Rental requests (' + reqs.length + ')</h4>' + UI.table([{ label: 'Request', key: 'id' }, { label: 'Supplier', render: r => esc(Store.supplier(r.supplierId).name) }, { label: 'Dates', render: r => U.fmtRange(r.start, r.end) }, { label: 'Status', render: r => UI.statusBadge(r.status) }, { label: 'Payment', render: r => UI.payBadge(Finance.summary(r).status) }], reqs) : '') });
  };

  /* ================= DASHBOARD ================= */
  AD.dashboard = function (ctx) {
    const users = Store.all('users'); const custs = users.filter(u => u.roles.includes('customer'));
    const sups = Store.all('suppliers'); const reqs = Store.all('requests');
    const audit = Store.all('audit').slice().sort((a, b) => b.at.localeCompare(a.at)).slice(0, 8);
    const months = Charts.months(6);
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Platform operations</h1><p>Welcome, ' + esc(ctx.session.user.name) + '. Here\'s what\'s happening on PartyRent.</p></div></div>' +
      '<div class="stats mb-3">' + UI.stat('Registered customers', custs.length, custs.filter(u => u.status !== 'active').length + ' inactive/suspended', 'users', 'pink', url('admin/users.html')) + UI.stat('Total suppliers', sups.length, '', 'store', 'purple', url('admin/users.html', { tab: 'suppliers' })) + UI.stat('Active suppliers', sups.filter(s => s.status === 'approved' && (Store.user(s.ownerId) || {}).status === 'active').length, '', 'check', 'teal') + UI.stat('Pending applications', sups.filter(s => s.status === 'pending').length, 'awaiting review', 'file', 'gold', url('admin/applications.html')) + UI.stat('Rental listings', Store.where('products', p => p.status === 'published').length, Store.where('packages', k => Services.isListed('package', k)).length + ' active packages', 'box', 'sky', url('admin/listings.html')) + UI.stat('Rental requests', reqs.length, reqs.filter(r => r.status === 'pending').length + ' pending', 'list', 'purple', url('admin/transactions.html')) + UI.stat('Active reservations', reqs.filter(r => Finance.ACTIVE.includes(r.status)).length, '', 'calendar', 'pink', url('admin/transactions.html', { tab: 'reservations' })) + UI.stat('Completed rentals', reqs.filter(r => r.status === 'completed').length, '', 'check', 'teal') + UI.stat('Declined requests', reqs.filter(r => r.status === 'declined').length, reqs.filter(r => r.status === 'cancelled').length + ' cancelled', 'x', 'red') + UI.stat('Reported issues', Store.where('complaints', c => c.status !== 'resolved').length, 'open complaints', 'flag', 'red', url('admin/complaints.html')) + '</div>' +
      '<div class="grid grid-2"><div class="card"><h3>Rental requests per month</h3><div id="a1"></div></div><div class="card"><h3>Request status</h3><div id="a2"></div></div><div class="card"><div class="card-head"><h3>Recent activity</h3><a class="small strong" href="' + url('admin/activity.html') + '">View all</a></div>' + audit.map(a => '<div style="padding:8px 0;border-bottom:1px solid var(--line)"><b class="small">' + esc(a.action) + '</b><div class="small muted">' + esc(a.details) + '</div><div class="small muted">' + esc((Store.user(a.actorId) || { name: 'System' }).name) + ' · ' + U.timeAgo(a.at) + '</div></div>').join('') + '</div><div class="card"><div class="card-head"><h3>Pending supplier applications</h3><a class="small strong" href="' + url('admin/applications.html') + '">Review</a></div>' + (sups.filter(s => s.status === 'pending').map(s => '<div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line)"><div class="row"><span class="logo-tile" style="width:40px;height:40px;font-size:1.2rem;background:var(--brand-50)">' + esc(s.logo) + '</span><div><b>' + esc(s.name) + '</b><div class="small muted">' + esc(Store.user(s.ownerId).name) + ' · ' + esc(s.city) + ' · ' + U.timeAgo(s.appliedAt) + '</div></div></div><a class="btn btn-sm" href="' + url('admin/applications.html') + '">Review</a></div>').join('') || '<p class="muted">No pending applications.</p>') + '</div></div>';
    Charts.bars($('#a1'), months.map(m => ({ label: m.label, value: reqs.filter(r => r.createdAt.slice(0, 7) === m.key).length })), { color: '#7C3AED' });
    const grp = [['Pending', ['pending']], ['Active', Finance.ACTIVE], ['Completed', ['completed']], ['Declined', ['declined']], ['Cancelled', ['cancelled']]];
    Charts.donut($('#a2'), grp.map(([l, st]) => ({ label: l, value: reqs.filter(r => st.includes(r.status)).length })), { center: 'requests' });
  };

  /* ================= USERS (customers & suppliers) ================= */
  AD.users = function (ctx) {
    let tab = ctx.params.tab || 'customers', q = '', st = '';
    const draw = () => {
      const canC = Auth.can('customers.view'), canS = Auth.can('suppliers.view');
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Customers & Suppliers</h1><p>View and manage account status.</p></div><div class="row wrap"><input class="input input-sm" id="q" placeholder="Search name, email, shop" style="width:220px" value="' + esc(q) + '" aria-label="Search"><select class="input input-sm" id="st" style="width:auto" aria-label="Status"><option value="">All statuses</option><option value="active"' + (st === 'active' ? ' selected' : '') + '>Active</option><option value="suspended"' + (st === 'suspended' ? ' selected' : '') + '>Suspended</option><option value="deactivated"' + (st === 'deactivated' ? ' selected' : '') + '>Deactivated</option></select></div></div>' + UI.tabs([{ key: 'customers', label: 'Customers' }, { key: 'suppliers', label: 'Suppliers' }], tab) + '<div id="ul"></div>';
      $$('[data-tab]').forEach(t => t.onclick = () => { tab = t.dataset.tab; draw(); });
      $('#q').onchange = e => { q = e.target.value.toLowerCase(); draw(); }; $('#st').onchange = e => { st = e.target.value; draw(); };
      if (tab === 'customers') {
        if (!canC) { $('#ul').innerHTML = denied('customers.view'); return; }
        const list = Store.where('users', u => u.roles.includes('customer')).filter(u => (!st || u.status === st) && (!q || (u.name + ' ' + u.email).toLowerCase().includes(q)));
        $('#ul').innerHTML = '<div class="card pad-0">' + UI.table([{ label: 'Customer', render: u => '<div class="row"><span class="avatar">' + U.initials(u.name) + '</span><div><b>' + esc(u.name) + '</b><div class="small muted">' + esc(u.email) + '</div></div></div>' }, { label: 'Contact', render: u => esc(u.phone) }, { label: 'Requests', cls: 'num', render: u => Store.where('requests', r => r.customerId === u.id).length }, { label: 'Supplier?', render: u => u.supplierId ? '<span class="badge b-purple plain">' + esc((Store.supplier(u.supplierId) || {}).name || '') + '</span>' : '—' }, { label: 'Joined', render: u => U.fmtDate(u.createdAt.slice(0, 10)) }, { label: 'Status', render: u => statusBadge(u.status) }, { label: '', render: u => '<div class="btn-group"><button class="btn btn-xs" data-view="' + u.id + '">View</button>' + (Auth.can('customers.manage') ? (u.status === 'active' ? '<button class="btn btn-xs" data-suspend="' + u.id + '">Suspend</button><button class="btn btn-xs btn-ghost" data-deact="' + u.id + '">Deactivate</button>' : '<button class="btn btn-xs btn-success" data-act="' + u.id + '">Activate</button>') : '') + '</div>' }], list) + '</div>';
      } else {
        if (!canS) { $('#ul').innerHTML = denied('suppliers.view'); return; }
        const list = Store.where('suppliers', s => !['pending', 'revision'].includes(s.status)).filter(s => { const o = Store.user(s.ownerId); return (!st || o.status === st) && (!q || (s.name + ' ' + o.name + ' ' + o.email + ' ' + s.city).toLowerCase().includes(q)); });
        $('#ul').innerHTML = '<div class="card pad-0">' + UI.table([{ label: 'Supplier', render: s => '<div class="row"><span class="logo-tile" style="width:40px;height:40px;font-size:1.2rem;background:' + s.color + '1a">' + esc(s.logo) + '</span><div><b>' + esc(s.name) + '</b><div class="small muted">' + esc(s.city) + ' · ' + (s.type === 'organization' ? 'Organization' : 'Individual') + ' · ' + Services.orgMembers(s.id).length + ' account(s)</div></div></div>' }, { label: 'Owner / Org Admin', render: s => esc(Store.user(s.ownerId).name) + '<div class="small muted">' + esc(Store.user(s.ownerId).email) + '</div>' }, { label: 'Listings', cls: 'num', render: s => Store.where('products', p => p.supplierId === s.id && p.status === 'published').length }, { label: 'Rentals', cls: 'num', render: s => Store.where('requests', r => r.supplierId === s.id).length }, { label: 'Rating', render: s => '★ ' + (s.rating || '—') }, { label: 'Application', render: s => statusBadge(s.status) }, { label: 'Account', render: s => statusBadge(Store.user(s.ownerId).status) }, { label: '', render: s => { const o = Store.user(s.ownerId); return '<div class="btn-group"><a class="btn btn-xs" href="' + url('customer/supplier.html', { id: s.id }) + '">Shop</a><button class="btn btn-xs" data-view="' + o.id + '">Owner</button>' + (Auth.can('suppliers.manage') ? (o.status === 'active' ? '<button class="btn btn-xs" data-suspend="' + o.id + '" data-perm="suppliers.manage">Suspend</button>' : '<button class="btn btn-xs btn-success" data-act="' + o.id + '" data-perm="suppliers.manage">Activate</button>') : '') + '</div>'; } }], list) + '</div>';
      }
      $$('[data-view]').forEach(b => b.onclick = () => AD.viewUser(Store.user(b.dataset.view)));
      $$('[data-suspend]').forEach(b => b.onclick = () => AD.setUserStatus(Store.user(b.dataset.suspend), 'suspended', b.dataset.perm || 'customers.manage', draw));
      $$('[data-deact]').forEach(b => b.onclick = () => AD.setUserStatus(Store.user(b.dataset.deact), 'deactivated', 'customers.manage', draw));
      $$('[data-act]').forEach(b => b.onclick = () => AD.setUserStatus(Store.user(b.dataset.act), 'active', b.dataset.perm || 'customers.manage', draw));
    };
    draw();
  };

  /* ================= SUPPLIER APPLICATIONS ================= */
  AD.applications = function (ctx) {
    let tab = 'pending', type = '';
    const A = Services.APP_STATUS;
    const docsHtml = s => { const app = s.application; if (!app) return '<p class="small muted">No documents on file (legacy application).</p>'; const docs = app.docs || {}; const tag = { required: 'b-red', conditional: 'b-gold', recommended: 'b-sky', optional: 'b-gray' }; return Services.applicationRequirements(app.type, app.company && app.company.companyType).map(q => { const d = docs[q.key]; const needed = q.need === 'required' || (q.need === 'conditional' && (app.applicable || []).includes(q.key)); return '<div class="doc-row"><div class="grow"><b class="small">' + esc(q.label) + '</b> <span class="badge ' + tag[q.need] + ' plain">' + ({ required: 'Required', conditional: (app.applicable || []).includes(q.key) ? 'Applies' : 'If applicable', recommended: 'Recommended', optional: 'Optional' })[q.need] + '</span></div>' + (d ? '<span class="badge b-green">' + I('file').replace('<svg', '<svg style="width:12px;height:12px"') + ' ' + esc(d.name) + ' · ' + Math.round((d.size || 0) / 1024) + ' KB</span>' : needed ? '<span class="badge b-red">Missing</span>' : '<span class="small muted">Not provided</span>') + '</div>'; }).join(''); };
    const info = s => { const app = s.application || {}; const o = Store.user(s.ownerId);
      if (app.type === 'organization') { const c = app.company || {}, r = app.representative || {}; return '<dl class="kv small"><dt>Company</dt><dd><b>' + esc(c.legalName) + '</b> · ' + esc(Services.COMPANY_TYPES[c.companyType] || '') + '<br>' + esc(c.natureOfBusiness || '') + ' · ' + esc(c.branches || 1) + ' location(s)</dd><dt>Address</dt><dd>' + esc(c.address || s.address) + '</dd><dt>Company contact</dt><dd>' + esc(c.email || '') + ' · ' + esc(c.phone || '') + (c.website ? '<br>' + esc(c.website) : '') + '</dd><dt>Authorized representative</dt><dd>' + esc(r.name) + ', ' + esc(r.position) + '<br>' + esc(r.idType) + ' · ' + esc(r.email) + ' · ' + esc(r.phone) + '</dd><dt>Applicant account</dt><dd>' + esc(o.name) + ' · ' + esc(o.email) + '</dd><dt>Payout</dt><dd>' + esc((app.payout || {}).provider) + ' · ' + esc((app.payout || {}).accountName) + ' · ' + esc((app.payout || {}).accountNumber) + '<br>Finance contact: ' + esc((app.payout || {}).financeContact || '—') + '</dd></dl>'; }
      const p = app.personal || {}, id = app.identity || {}, b = app.business || {};
      return '<dl class="kv small"><dt>Applicant</dt><dd><b>' + esc(p.fullName || o.name) + '</b>' + (p.birthDate ? ' · born ' + U.fmtDate(p.birthDate) : '') + '<br>' + esc(p.email || o.email) + ' · ' + esc(p.phone || o.phone) + '<br>Customer since ' + U.fmtDate(o.createdAt.slice(0, 10)) + ' · ' + Store.where('requests', r => r.customerId === o.id).length + ' rentals</dd><dt>Address</dt><dd>' + esc(p.address || s.address) + '</dd><dt>Identity</dt><dd>' + esc(id.idType || '—') + ' · ' + esc(id.idNumber || '') + '</dd><dt>Business</dt><dd>' + esc(b.shopName || s.name) + '<br>' + esc(b.productTypes || '') + '<br>' + esc(b.address || s.address) + '</dd><dt>Payout</dt><dd>' + esc((app.payout || {}).provider) + ' · ' + esc((app.payout || {}).accountName) + ' · ' + esc((app.payout || {}).accountNumber) + '</dd></dl>'; };
    function view(s) {
      const miss = s.application ? Services.missingDocs(s.application) : [];
      const m = UI.modal({ title: s.name + ' · ' + (Services.SUPPLIER_TYPES[s.type] || 'Supplier'), size: 'lg', body: '<div class="row wrap gap-sm mb-2"><span class="badge ' + A[s.status][1] + '">' + A[s.status][0] + '</span><span class="badge ' + (s.type === 'organization' ? 'b-purple' : 'b-pink') + ' plain">' + esc(Services.SUPPLIER_TYPES[s.type] || 'Supplier') + '</span><span class="small muted">Submitted ' + U.fmtDateTime(s.appliedAt) + '</span></div><p class="small">' + esc(s.description) + '</p>' + info(s) + '<h3 class="mt-2">Documents</h3>' + docsHtml(s) + (miss.length ? '<div class="alert alert-danger mt-1">' + I('alert') + '<div>Missing required document(s): ' + miss.map(x => esc(x.label)).join(', ') + '. Request a revision instead of approving.</div></div>' : '') + '<h3 class="mt-2">Shop setup</h3><dl class="kv small"><dt>Fulfillment</dt><dd>' + UI.fulfillLabel(s.fulfillment) + '</dd><dt>Payment plans</dt><dd>' + Finance.plansOf(s).map(k => Finance.PLAN_LABEL[k]).join(', ') + ' · ' + (s.methods || []).map(x => Finance.METHOD_LABEL[x]).join(', ') + '</dd><dt>Policies</dt><dd>' + esc((s.policy || {}).cancellation || '') + '</dd></dl>' + (((s.application || {}).history || []).length ? '<h3 class="mt-2">History</h3>' + s.application.history.map(h => '<div class="small">' + U.fmtDateTime(h.at) + ' · ' + esc((A[h.status] || [h.status])[0]) + (h.reason ? ' — ' + esc(h.reason) : '') + '</div>').join('') : '') + (s.reviewedBy ? '<p class="small muted mt-1">Last reviewed by ' + esc((Store.user(s.reviewedBy) || {}).name || '') + ' · ' + U.fmtDateTime(s.reviewedAt) + (s.rejectReason ? ' — ' + esc(s.rejectReason) : s.revisionNotes ? ' — ' + esc(s.revisionNotes) : '') + '</p>' : ''),
        foot: s.status === 'pending' && Auth.can('applications.review') ? '<button class="btn btn-danger" data-x="reject">Reject</button><button class="btn" data-x="revision">' + I('edit') + 'Request revision</button><button class="btn btn-success" data-x="approve"' + (miss.length ? ' disabled title="Required documents are missing"' : '') + '>' + I('check') + 'Approve</button>' : '<button class="btn" data-close>Close</button>' });
      m.el.querySelectorAll('[data-x]').forEach(b => b.onclick = async () => {
        const d = b.dataset.x; let reason = '';
        if (d === 'approve') { if (!await UI.confirm({ title: 'Approve ' + s.name + '?', message: Store.user(s.ownerId).name + ' becomes the ' + (s.type === 'organization' ? 'Organization Admin' : 'owner') + ' of this supplier account and can switch to Supplier Mode. Their customer access is unchanged.', confirmText: 'Approve' })) return; }
        else { reason = await UI.prompt({ title: (d === 'reject' ? 'Reject ' : 'Request revision · ') + s.name, label: d === 'reject' ? 'Reason (sent to the applicant)' : 'What must the applicant correct or add?', required: true, textarea: true, confirmText: d === 'reject' ? 'Reject application' : 'Send back for revision', danger: d === 'reject' }); if (reason == null) return; }
        const r = Services.reviewApplication(s.id, d, reason); if (!r.ok) return UI.toast(r.error, 'error');
        m.close(); UI.toast({ approve: s.name + ' approved.', reject: 'Application rejected.', revision: 'Sent back for revision.' }[d] + ' The applicant has been notified.', 'success'); draw();
      });
    }
    const draw = () => {
      const all = Store.all('suppliers').slice().sort((a, b) => (b.appliedAt || '').localeCompare(a.appliedAt || ''));
      const list = all.filter(s => s.status === tab && (!type || s.type === type));
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Supplier Applications</h1><p>Individual and Organization / Company applications. Check the information and documents, then approve, reject or request a revision.</p></div><div class="seg" id="ty"><button data-t="" class="' + (!type ? 'active' : '') + '">All types</button><button data-t="individual" class="' + (type === 'individual' ? 'active' : '') + '">Individual</button><button data-t="organization" class="' + (type === 'organization' ? 'active' : '') + '">Organization</button></div></div>' + (Store.settings().requireSupplierApproval ? '' : '<div class="alert alert-info mb-2">' + I('info') + '<div>Supplier approval is currently turned off by the Superadmin — new suppliers are approved automatically.</div></div>') + UI.tabs(['pending', 'revision', 'approved', 'rejected'].map(k => ({ key: k, label: A[k][0], count: all.filter(s => s.status === k && (!type || s.type === type)).length })), tab) +
        (list.length ? '<div class="grid grid-2">' + list.map(s => { const o = Store.user(s.ownerId); const miss = s.application ? Services.missingDocs(s.application) : []; const nDocs = Object.keys((s.application || {}).docs || {}).length; return '<div class="card"><div class="row top"><span class="logo-tile" style="background:' + s.color + '1a">' + (s.logoImg ? '<img src="' + s.logoImg + '" style="width:100%;height:100%;object-fit:cover;border-radius:14px" alt="">' : esc(s.logo)) + '</span><div class="grow" style="min-width:0"><div class="row between"><b>' + esc(s.name) + '</b><span class="badge ' + A[s.status][1] + '">' + A[s.status][0] + '</span></div><div class="small"><span class="badge ' + (s.type === 'organization' ? 'b-purple' : 'b-pink') + ' plain">' + esc(Services.SUPPLIER_TYPES[s.type] || 'Supplier') + '</span> ' + (s.type === 'organization' && s.company ? esc(Services.COMPANY_TYPES[s.company.companyType] || '') : '') + '</div><div class="small muted">Applied ' + U.fmtDateTime(s.appliedAt) + ' by ' + esc(o.name) + '</div></div></div><p class="small mt-1">' + esc(s.description) + '</p><div class="row wrap gap-sm small"><span class="badge b-gray plain">' + nDocs + ' document(s)</span>' + (miss.length ? '<span class="badge b-red plain">' + miss.length + ' required missing</span>' : '<span class="badge b-green plain">Required documents complete</span>') + '<span class="badge b-gray plain">' + esc(s.city || '') + '</span></div>' + (s.revisionNotes ? '<div class="small mt-1" style="color:#6D28D9">Revision requested: ' + esc(s.revisionNotes) + '</div>' : '') + (s.rejectReason ? '<div class="small mt-1" style="color:var(--red)">Rejected: ' + esc(s.rejectReason) + '</div>' : '') + '<div class="row end mt-2"><button class="btn btn-sm ' + (s.status === 'pending' ? 'btn-primary' : '') + '" data-view="' + s.id + '">' + (s.status === 'pending' && Auth.can('applications.review') ? 'Review application' : 'View details') + '</button></div></div>'; }).join('') + '</div>' : '<div class="card">' + UI.empty('📄', 'No applications here', '') + '</div>');
      $$('[data-tab]').forEach(t => t.onclick = () => { tab = t.dataset.tab; draw(); });
      $$('#ty [data-t]').forEach(b => b.onclick = () => { type = b.dataset.t; draw(); });
      $$('[data-view]').forEach(b => b.onclick = () => view(Store.supplier(b.dataset.view)));
    };
    if (!guardPerm(ctx, 'suppliers.view')) return;
    draw();
  };

  /* ================= LISTINGS ================= */
  /** Single status badge (Active / Hidden by admin / Inactive) for a product or package. */
  AD.listingBadge = function (kind, x) { const st = Services.listingStatus(kind, x); const L = Services.LISTING[st]; return '<span class="badge ' + L[1] + '">' + L[0] + '</span>'; };
  /** Hide (reason required) / Unhide, shared by the listing table and the Manage page. */
  AD.hideListing = async function (kind, x, done) {
    let reason = '';
    while (!reason) {
      const v = await UI.prompt({ title: 'Hide ' + x.name + '?', message: 'The listing will be removed from the customer marketplace immediately. Accepted and upcoming rentals continue normally. The supplier keeps the listing but cannot republish it.', label: 'Reason for hiding (sent to the supplier)', required: true, textarea: true, confirmText: 'Hide', danger: true });
      if (v == null) return;
      reason = v.trim();
      if (!reason) UI.toast('Please enter a reason for hiding this listing.', 'error');
    }
    const r = Services.adminHideListing(kind, x.id, reason);
    if (!r.ok) return UI.toast(r.error, 'error');
    UI.toast(x.name + ' is now hidden. The supplier has been notified.', 'success'); done();
  };
  AD.unhideListing = async function (kind, x, done) {
    if (!await UI.confirm({ title: 'Unhide ' + x.name + '?', message: 'The listing will be restored to Active and become visible in the marketplace again, subject to normal availability. The supplier will be notified.', confirmText: 'Unhide' })) return;
    const r = Services.adminUnhideListing(kind, x.id);
    if (!r.ok) return UI.toast(r.error, 'error');
    UI.toast(x.name + ' is active again.', 'success'); done();
  };
  AD.listings = function (ctx) {
    if (!guardPerm(ctx, 'listings.view')) return;
    let tab = ctx.params.tab === 'packages' ? 'packages' : 'products', q = '', cat = '';
    const draw = () => {
      const products = Store.all('products').filter(p => Services.listingStatus('product', p) !== 'deleted');
      const packages = Store.all('packages').filter(k => Services.listingStatus('package', k) !== 'deleted');
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Listings & Packages</h1><p>Listings go live as soon as suppliers publish them. Hidden listings disappear from the marketplace but suppliers keep their records.</p></div><div class="row wrap"><input class="input input-sm" id="q" placeholder="Search listings" style="width:220px" value="' + esc(q) + '" aria-label="Search"><select class="input input-sm" id="cat" style="width:auto" aria-label="Category"><option value="">All categories</option>' + Store.all('categories').map(c => '<option value="' + c.id + '"' + (c.id === cat ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('') + '</select></div></div>' + UI.tabs([{ key: 'products', label: 'Products', count: products.length }, { key: 'packages', label: 'Rental packages', count: packages.length }], tab) + '<div class="card pad-0" id="ll"></div>';
      $$('[data-tab]').forEach(t => t.onclick = () => { tab = t.dataset.tab; draw(); });
      $('#q').onchange = e => { q = e.target.value.toLowerCase(); draw(); }; $('#cat').onchange = e => { cat = e.target.value; draw(); };
      const manage = Auth.can('listings.manage');
      const actions = (kind, x) => '<div class="btn-group"><a class="btn btn-xs" href="' + url(kind === 'package' ? 'customer/package.html' : 'customer/product.html', { id: x.id }) + '" title="Preview as customers see it">' + I('eye') + 'View</a><a class="btn btn-xs btn-outline" href="' + url('admin/edit-listing.html', kind === 'package' ? { type: 'package', id: x.id } : { id: x.id }) + '" title="Admin listing management">' + I('settings') + 'Manage</a>' + (manage ? (Services.listingStatus(kind, x) === 'hidden' ? '<button class="btn btn-xs btn-success" data-unhide="' + kind + ':' + x.id + '">Unhide</button>' : '<button class="btn btn-xs btn-danger" data-hide="' + kind + ':' + x.id + '">Hide</button>') : '') + '</div>';
      const statusCol = (kind, x) => AD.listingBadge(kind, x) + (Services.listingStatus(kind, x) === 'hidden' && x.hiddenReason ? '<div class="small muted">' + esc(x.hiddenReason) + '</div>' : '');
      if (tab === 'products') {
        const list = products.filter(p => (!cat || p.categoryId === cat) && (!q || (p.name + ' ' + Store.supplier(p.supplierId).name).toLowerCase().includes(q)));
        $('#ll').innerHTML = UI.table([{ label: 'Listing', render: p => '<div class="row">' + UI.thumb(p, null, 'sm') + '<div><b>' + esc(p.name) + '</b><div class="small muted">' + p.id + ' · ' + esc((Store.category(p.categoryId) || {}).name || '') + '</div></div></div>' }, { label: 'Supplier', render: p => esc(Store.supplier(p.supplierId).name) }, { label: 'Price', render: p => U.money(Pricing.basePrice(p)) }, { label: 'Stock', cls: 'num', render: p => p.totalStock }, { label: 'Rentals', cls: 'num', render: p => p.rentCount }, { label: 'Status', render: p => statusCol('product', p) }, { label: '', render: p => actions('product', p) }], list);
      } else {
        const list = packages.filter(k => (!cat || k.items.some(it => (Store.product(it.productId) || {}).categoryId === cat)) && (!q || (k.name + ' ' + Store.supplier(k.supplierId).name).toLowerCase().includes(q)));
        $('#ll').innerHTML = UI.table([{ label: 'Package', render: k => '<b>' + esc(k.emoji || '') + ' ' + esc(k.name) + '</b><div class="small muted">' + k.id + ' · ' + k.items.map(it => it.qty + '× ' + esc((Store.product(it.productId) || { name: 'item' }).name)).join(', ') + '</div>' }, { label: 'Supplier', render: k => esc(Store.supplier(k.supplierId).name) }, { label: 'Price', cls: 'num', render: k => U.money(k.price) }, { label: 'Available today', cls: 'num', render: k => Inventory.packageAvailableOn(k.id, U.today()) }, { label: 'Status', render: k => statusCol('package', k) }, { label: '', render: k => actions('package', k) }], list);
      }
      const ref = v => { const [kind, id] = v.split(':'); return [kind, kind === 'package' ? Store.pkg(id) : Store.product(id)]; };
      $$('[data-hide]').forEach(b => b.onclick = () => { const [kind, x] = ref(b.dataset.hide); AD.hideListing(kind, x, draw); });
      $$('[data-unhide]').forEach(b => b.onclick = () => { const [kind, x] = ref(b.dataset.unhide); AD.unhideListing(kind, x, draw); });
    };
    draw();
  };

  /* ================= LISTING MANAGEMENT (admin) =================
     Admin-only view of one listing with moderation and management controls.
     Frontend demo: changes are kept in this browser's PartyRent data (localStorage). */
  /** Listing Status card: badge, explanation, Hide/Unhide, Delete. Same for products and packages. */
  AD.statusCard = function (kind, x, manage) {
    const st = Services.listingStatus(kind, x); const L = Services.LISTING[st];
    return '<div class="card" id="statusCard"><h3>Listing status</h3><div class="mb-1">' + AD.listingBadge(kind, x) + '</div><p class="small" id="statusText">' + esc(L[2]) + (st === 'hidden' && x.hiddenReason ? '<br><b>Reason:</b> ' + esc(x.hiddenReason) : '') + '</p>' +
      (manage ? '<div class="stack-sm">' + (st === 'hidden' ? '<button class="btn btn-success btn-block" id="unhideBtn">' + I('eye') + 'Unhide</button>' : '<button class="btn btn-danger btn-block" id="hideBtn">' + I('lock') + 'Hide</button>') +
        '<button class="btn btn-ghost btn-block" id="deleteBtn" style="color:var(--red)">' + I('trash') + 'Delete Listing</button></div>' : '') + '</div>';
  };
  AD.bindStatusCard = function (kind, x, redraw) {
    const h = $('#hideBtn'); if (h) h.onclick = () => AD.hideListing(kind, x, redraw);
    const u = $('#unhideBtn'); if (u) u.onclick = () => AD.unhideListing(kind, x, redraw);
    const d = $('#deleteBtn'); if (d) d.onclick = () => AD.deleteListing(kind, x);
  };
  AD.deleteListing = async function (kind, x) {
    const deps = Services.dependentRequests(kind, x.id);
    if (deps.length) {
      UI.modal({ title: 'This listing cannot be deleted', body: '<div class="alert alert-danger mb-2">' + I('alert') + '<div><b>' + esc(x.name) + '</b> has active or upcoming reservations, so it cannot be deleted. Reservations are never cancelled to allow a deletion. You can hide the listing instead.</div></div>' + UI.table([{ label: 'Request', render: r => '<b>' + r.id + '</b>' }, { label: 'Dates', render: r => U.fmtRange(r.start, r.end) }, { label: 'Status', render: r => UI.statusBadge(r.status) }], deps, { noCards: true }), foot: '<button class="btn btn-primary" data-close>OK</button>' });
      return;
    }
    const pk = kind === 'product' ? Store.where('packages', k => !k.deleted && k.active && k.items.some(it => it.productId === x.id)) : [];
    if (!await UI.confirm({ title: 'Permanently delete ' + x.name + '?', html: '<b>Deleting this listing is permanent and cannot be undone.</b> It will be removed from the marketplace, from the admin listings and from the supplier\'s catalog. Past rental history keeps the item name.' + (pk.length ? '<br><br>These packages include it and will be switched off: <b>' + esc(pk.map(k => k.name).join(', ')) + '</b>.' : ''), cancelText: 'Go back', confirmText: 'Delete permanently', danger: true })) return;
    const r = Services.adminDeleteListing(kind, x.id); // re-checks reservations and permissions
    if (!r.ok) return UI.toast(r.error, 'error');
    UI.toast(x.name + ' was deleted.', 'success');
    setTimeout(() => { location.href = url('admin/listings.html', kind === 'package' ? { tab: 'packages' } : null); }, 600);
  };
  AD.editListing = function (ctx) {
    if (!guardPerm(ctx, 'listings.view')) return;
    const id = ctx.params.id;
    const notFound = () => { ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('admin/listings.html') + '">Listings & Packages</a> / Not found</div>' + UI.empty('🔎', 'Listing not found', 'No listing matches "' + (id || '') + '", or it has been deleted.', '<a class="btn" href="' + url('admin/listings.html') + '">Back to listings</a>'); };
    const kind = ctx.params.type === 'package' || (!Store.product(id) && Store.pkg(id)) ? 'package' : 'product';
    if (kind === 'package') return AD.editPackage(ctx);
    if (Services.listingStatus('product', Store.product(id)) === 'deleted') return notFound();
    let checkFrom = U.today(), checkTo = U.today(), imgIdx = 0;
    const notifySupplier = (p, title, body) => Store.notify(Store.supplier(p.supplierId).ownerId, 'supplier', title, body, 'supplier/product-form.html?id=' + p.id);

    function draw() {
      const p = Store.product(id); if (Services.listingStatus('product', p) === 'deleted') return notFound();
      const sup = Store.supplier(p.supplierId); const owner = Store.user(sup.ownerId); const cat = Store.category(p.categoryId) || { name: p.categoryId };
      const manage = Auth.can('listings.manage');
      const today = Inventory.breakdown(p.id, U.today());
      const range = U.range(checkFrom, checkTo < checkFrom ? checkFrom : checkTo);
      const per = range.map(d => Inventory.breakdown(p.id, d));
      const busiest = per.reduce((a, b) => (b.available < a.available ? b : a), per[0]);
      const next30 = U.range(U.today(), U.addDays(U.today(), 29));
      const bookings = Store.all('requests').filter(r => Inventory.RESERVING.concat(['pending']).includes(r.status) && r.end >= U.today() && r.lines.some(l => next30.some(d => Inventory.unitsForLine(l, d)[p.id]))).sort((a, b) => a.start.localeCompare(b.start));
      const inPkgs = Store.where('packages', k => k.items.some(it => it.productId === p.id));
      const history = Store.all('audit').filter(a => (a.details || '').includes(p.id) || (a.details || '').startsWith(p.name)).sort((a, b) => b.at.localeCompare(a.at)).slice(0, 8);
      const imgs = p.images || [];
      if (imgIdx >= imgs.length) imgIdx = 0;
      ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('admin/listings.html') + '">Listings & Packages</a> / ' + esc(p.name) + '</div>' +
        '<div class="page-head"><div><h1>' + esc(p.name) + '</h1><p>' + p.id + ' · ' + esc(cat.name) + ' · ' + esc(sup.name) + '</p></div><div class="row wrap">' + (p.status !== 'archived' ? '<a class="btn btn-sm" href="' + url('customer/product.html', { id: p.id }) + '">' + I('eye') + 'View as customer</a>' : '') + (manage ? '<button class="btn btn-sm btn-primary" id="editBtn">' + I('edit') + 'Edit listing</button>' : '') + '</div></div>' +
        (!manage ? '<div class="alert alert-info mb-2">' + I('lock') + '<div>You can view this listing, but your account does not have the "listings.manage" permission, so management actions are disabled.</div></div>' : '') +
        '<div class="grid grid-side"><div class="stack">' +
        // gallery + details
        '<div class="card"><div class="grid grid-2" style="align-items:start"><div><div class="pd-gallery"><div class="main" style="aspect-ratio:4/3;font-size:5rem">' + (imgs.length ? '<img src="' + imgs[imgIdx] + '" alt="' + esc(p.name) + '">' : UI.img(p)) + '</div></div>' + (imgs.length > 1 ? '<div class="thumbs">' + imgs.map((im, i) => '<img src="' + im + '" alt="Photo ' + (i + 1) + '" data-img="' + i + '" style="cursor:pointer;' + (i === imgIdx ? 'outline:3px solid var(--brand)' : '') + '">').join('') + '</div>' : '') + '<div class="small muted mt-1">' + (imgs.length ? imgs.length + ' photo(s) uploaded by the supplier' : 'No photos uploaded — category illustration shown to customers') + '</div></div>' +
        '<div><h3>Details</h3><p class="mb-2">' + esc(p.description || '—') + '</p><dl class="kv"><dt>Category</dt><dd>' + esc(cat.name) + '</dd><dt>Condition</dt><dd>' + esc(p.condition) + '</dd><dt>Pricing unit</dt><dd>' + esc(p.unit) + '</dd><dt>Colors</dt><dd>' + (esc((p.colors || []).join(', ')) || '—') + '</dd><dt>Themes</dt><dd>' + (esc((p.themes || []).join(', ')) || '—') + '</dd><dt>Events</dt><dd>' + (esc((p.events || []).join(', ')) || '—') + '</dd><dt>Delivery</dt><dd>' + (p.delivery === false ? 'Pickup only' : 'Available') + '</dd><dt>Created</dt><dd>' + U.fmtDate(p.createdAt.slice(0, 10)) + '</dd><dt>Rentals</dt><dd>' + p.rentCount + ' · ★ ' + (p.rating || '—') + '</dd></dl></div></div></div>' +
        // pricing
        '<div class="card"><div class="card-head"><h3>Rental price & pricing</h3><span class="badge ' + (p.maxRentalDays > 0 ? 'b-purple' : 'b-gray') + ' plain">' + I('clock').replace('<svg', '<svg style="width:13px;height:13px"') + ' ' + esc(UI.maxRentalLabel(p)) + '</span></div>' + UI.table([{ label: 'Quantity per day', render: t => t.min + (t.max ? '–' + t.max : '+') }, { label: 'Daily price', cls: 'num', render: t => '<b>' + U.money(t.price) + '</b> / ' + esc(p.unit.split('/')[0].trim()) }], p.tiers, { noCards: true }) + (inPkgs.length ? '<p class="small muted mt-1 mb-0">Included in packages: ' + inPkgs.map(k => esc(k.name) + (k.active ? '' : ' (inactive)')).join(', ') + '</p>' : '') + '</div>' +
        // availability
        '<div class="card"><div class="card-head"><h3>Stock & availability</h3><span class="small muted">Same calculation customers and suppliers see</span></div>' +
        '<div class="stats mb-2">' + UI.stat('Total stock', today.total, 'owned by supplier', 'layers', 'sky') + UI.stat('Available today', today.available, U.fmtDate(U.today()), 'check', 'teal') + UI.stat('Reserved today', today.reserved, 'accepted rentals', 'calendar', 'pink') + UI.stat('Not rentable', today.damaged + today.missing + today.maintenance + today.setaside, today.damaged + ' damaged · ' + today.missing + ' missing · ' + today.maintenance + ' maint. · ' + today.setaside + ' set aside', 'tool', 'gold') + '</div>' +
        '<div class="row wrap mb-1" style="gap:8px"><span class="small strong">Check a rental period</span><span class="range-inline"><input type="date" class="input input-sm" id="cf" value="' + checkFrom + '" aria-label="From"><input type="date" class="input input-sm" id="ct" value="' + checkTo + '" aria-label="To"></span></div>' +
        '<div class="alert alert-info">' + I('info') + '<div class="small"><b>' + busiest.available + ' available</b> for ' + esc(RentalPeriod.rangeLabel(range[0], range[range.length - 1])) + ' (' + RentalPeriod.durationLabel(range.length) + ') — busiest day ' + esc(U.fmtDay(busiest.date)) + ': ' + busiest.reserved + ' reserved, ' + busiest.pending + ' in pending requests.</div></div>' +
        '<h4 class="mt-2">Bookings in the next 30 days</h4>' + UI.table([{ label: 'Request', render: r => '<b>' + r.id + '</b><div class="small muted">' + esc(r.eventName) + '</div>' }, { label: 'Customer', render: r => esc(Store.user(r.customerId).name) }, { label: 'Dates', render: r => U.fmtRange(r.start, r.end) }, { label: 'Peak units', cls: 'num', render: r => Math.max.apply(null, U.range(r.start, r.end).map(d => U.sum(r.lines, l => Inventory.unitsForLine(l, d)[p.id] || 0))) }, { label: 'Status', render: r => UI.statusBadge(r.status) }], bookings, { emptyText: 'No upcoming bookings for this item.' }) + '</div>' +
        '<div class="card"><h3>Admin activity</h3>' + (history.length ? history.map(a => '<div style="padding:8px 0;border-bottom:1px solid var(--line)"><b class="small">' + esc(a.action) + '</b><div class="small muted">' + esc(a.details) + ' · ' + esc((Store.user(a.actorId) || { name: 'System' }).name) + ' · ' + U.timeAgo(a.at) + '</div></div>').join('') : '<p class="small muted mb-0">No admin actions recorded for this listing yet.</p>') + '</div>' +
        '</div><aside class="stack">' +
        // status
        AD.statusCard('product', p, manage) +
        // supplier
        '<div class="card"><h3>Supplier</h3><div class="row mb-1"><span class="logo-tile" style="width:44px;height:44px;font-size:1.3rem;background:' + sup.color + '1a">' + esc(sup.logo) + '</span><div><b>' + esc(sup.name) + '</b><div class="small muted">' + esc(sup.city) + ' · ★ ' + (sup.rating || '—') + '</div></div></div><dl class="kv small"><dt>Owner</dt><dd>' + esc(owner.name) + '<br>' + esc(owner.email) + '</dd><dt>Contact</dt><dd>' + esc(sup.phone) + '</dd><dt>Status</dt><dd>' + AD.statusBadge(sup.status) + ' ' + AD.statusBadge(owner.status) + '</dd><dt>Listings</dt><dd>' + Store.where('products', x => x.supplierId === sup.id && x.status === 'published').length + ' active</dd></dl><a class="btn btn-sm btn-block mt-1" href="' + url('customer/supplier.html', { id: sup.id }) + '">View shop</a></div>' +
        // stock
        '<div class="card"><h3>Stock</h3><dl class="kv small"><dt>Total stock</dt><dd>' + p.totalStock + '</dd><dt>Damaged</dt><dd>' + (p.damaged || 0) + '</dd><dt>Missing</dt><dd>' + (p.missing || 0) + '</dd><dt>Max rental</dt><dd>' + esc(UI.maxRentalLabel(p)) + '</dd></dl>' + (manage ? '<button class="btn btn-sm btn-block mt-1" id="stockBtn">' + I('layers') + 'Manage stock</button>' : '') + '</div>' +
        '</aside></div>';

      $$('[data-img]').forEach(t => t.onclick = () => { imgIdx = +t.dataset.img; draw(); });
      $('#cf').onchange = e => { checkFrom = e.target.value || U.today(); if (checkTo < checkFrom) checkTo = checkFrom; draw(); };
      $('#ct').onchange = e => { checkTo = e.target.value || checkFrom; if (checkTo < checkFrom) checkTo = checkFrom; draw(); };
      AD.bindStatusCard('product', p, draw);
      if (!manage) return;
      const done = (msg) => { UI.toast(msg, 'success'); draw(); };
      $('#editBtn').onclick = () => editModal(p);
      $('#stockBtn').onclick = () => stockModal(p);
    }

    function editModal(p) {
      const cats = Store.where('categories', c => c.active || c.id === p.categoryId);
      const d = { images: (p.images || []).slice(), tiers: p.tiers.map(t => Object.assign({}, t)) };
      const m = UI.modal({ title: 'Edit listing · ' + p.name, size: 'lg', body: '<form id="ef" novalidate><div class="form-grid"><div class="field full"><label>Item name</label><input class="input" name="name" required minlength="3" value="' + esc(p.name) + '"></div><div class="field"><label>Category</label><select class="input" name="categoryId" required>' + cats.map(c => '<option value="' + c.id + '"' + (c.id === p.categoryId ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('') + '</select></div><div class="field"><label>Condition</label><select class="input" name="condition">' + ['Brand New', 'Excellent', 'Good', 'Fair'].map(c => '<option' + (c === p.condition ? ' selected' : '') + '>' + c + '</option>').join('') + '</select></div><div class="field full"><label>Description</label><textarea class="input" name="description" required minlength="15" rows="3">' + esc(p.description) + '</textarea></div></div>' +
        '<div class="label mb-1">Price tiers (per day)</div><div id="et"></div><p class="small muted">Price changes apply to new requests only; accepted rentals keep their quoted price.</p>' +
        '<div class="field"><label>Maximum rental duration</label><label class="check mb-1"><input type="checkbox" id="eNoMax"' + (p.maxRentalDays > 0 ? '' : ' checked') + '> No maximum</label><div class="row" style="gap:8px"><input class="input" type="number" min="1" max="365" id="eMax" value="' + (p.maxRentalDays > 0 ? p.maxRentalDays : '') + '"' + (p.maxRentalDays > 0 ? '' : ' disabled') + ' style="max-width:110px"><span class="small muted">day(s) per booking</span></div></div>' +
        '<div class="field"><label>Photos</label><div class="thumbs" id="eImgs"></div><label class="btn btn-sm mt-1" for="eUp">' + I('upload') + 'Add photo</label><input type="file" id="eUp" accept="image/*" hidden></div><div id="eErr"></div></form>',
        foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="eSave">Save changes</button>' });
      const el = m.el;
      const drawTiers = () => {
        el.querySelector('#et').innerHTML = d.tiers.map((t, i) => '<div class="row wrap mb-1" data-t="' + i + '" style="gap:8px"><span class="small muted" style="width:46px">Tier ' + (i + 1) + '</span><input class="input input-sm" type="number" value="' + t.min + '" readonly style="width:80px" aria-label="From"><span class="small">to</span>' + (i === d.tiers.length - 1 ? '<span class="small strong" style="width:80px">and up</span>' : '<input class="input input-sm" type="number" min="1" data-k="max" value="' + (t.max || '') + '" style="width:80px" aria-label="To">') + '<span class="small">→ ₱</span><input class="input input-sm" type="number" min="0" step="0.5" data-k="price" value="' + t.price + '" style="width:100px" aria-label="Price"></div>').join('');
        el.querySelectorAll('[data-t] [data-k]').forEach(inp => inp.onchange = () => { const i = +inp.closest('[data-t]').dataset.t; d.tiers[i][inp.dataset.k] = Number(inp.value) || 0; if (inp.dataset.k === 'max' && d.tiers[i + 1]) d.tiers[i + 1].min = (Number(inp.value) || 0) + 1; drawTiers(); });
      };
      const drawImgs = () => { el.querySelector('#eImgs').innerHTML = d.images.length ? d.images.map((im, i) => '<div style="position:relative"><img src="' + im + '" alt=""><button type="button" class="btn btn-xs btn-danger" data-rm="' + i + '" style="position:absolute;top:-6px;right:-6px;padding:0 6px;min-height:22px" aria-label="Remove photo">×</button></div>').join('') : '<span class="small muted">No photos</span>'; el.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { d.images.splice(+b.dataset.rm, 1); drawImgs(); }); };
      drawTiers(); drawImgs();
      el.querySelector('#eNoMax').onchange = e => { const x = el.querySelector('#eMax'); x.disabled = e.target.checked; if (e.target.checked) x.value = ''; else x.value = x.value || 1; };
      el.querySelector('#eUp').onchange = async e => { if (d.images.length >= 4) return UI.toast('Up to 4 photos.', 'warn'); try { d.images.push(await U.readImage(e.target.files[0], 700)); drawImgs(); } catch (err) { UI.toast(err.message, 'error'); } };
      el.querySelector('#eSave').onclick = () => {
        const f = el.querySelector('#ef'); if (!UI.validate(f)) return; const fd = UI.formData(f);
        const noMax = el.querySelector('#eNoMax').checked;
        // reuse the supplier-side validation (tiers, stock rules, max rental duration)
        const res = Services.saveProduct(p.supplierId, Object.assign({}, p, { name: fd.name.trim(), categoryId: fd.categoryId, condition: fd.condition, description: fd.description.trim(), tiers: d.tiers.map(t => ({ min: +t.min, max: t.max ? +t.max : null, price: +t.price })), images: d.images, maxRentalDays: noMax ? null : el.querySelector('#eMax').value }));
        if (!res.ok) { el.querySelector('#eErr').innerHTML = '<div class="alert alert-danger">' + I('alert') + '<div>' + esc(res.error) + '</div></div>'; return; }
        notifySupplier(res.product, 'Listing edited by admin', res.product.name + ' was updated by a PartyRent administrator.'); Store.audit('Admin edited listing', res.product.id + ' · ' + res.product.name);
        m.close(); UI.toast('Listing updated.', 'success'); draw();
      };
    }

    function stockModal(p) {
      const dates = Inventory.reservedDates(p.id);
      const peak = Math.max.apply(null, [0].concat(dates.map(d => Inventory.reservedOn(p.id, d))));
      const m = UI.modal({ title: 'Manage stock · ' + p.name, size: 'sm', body: '<p class="small muted">Highest accepted reservation on one upcoming day: <b>' + peak + '</b> units. Stock cannot be set below what accepted reservations need.</p><div class="field"><label>Total stock</label><input class="input" type="number" min="0" id="sT" value="' + p.totalStock + '"></div><div class="form-grid"><div class="field"><label>Damaged units</label><input class="input" type="number" min="0" id="sD" value="' + (p.damaged || 0) + '"></div><div class="field"><label>Missing units</label><input class="input" type="number" min="0" id="sM" value="' + (p.missing || 0) + '"></div></div><div id="sE"></div>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="sOk">Save stock</button>' });
      m.el.querySelector('#sOk').onclick = () => {
        const T = Math.floor(+m.el.querySelector('#sT').value), Dm = Math.floor(+m.el.querySelector('#sD').value), Ms = Math.floor(+m.el.querySelector('#sM').value);
        if (!(T >= 0 && Dm >= 0 && Ms >= 0)) { m.el.querySelector('#sE').innerHTML = '<div class="alert alert-danger">' + I('alert') + '<div>Enter whole numbers of zero or more.</div></div>'; return; }
        if (Dm + Ms > T) { m.el.querySelector('#sE').innerHTML = '<div class="alert alert-danger">' + I('alert') + '<div>Damaged + missing cannot exceed total stock.</div></div>'; return; }
        const res = Store.transaction(() => {
          const c = Inventory.stockChangeConflicts(p.id, T, Dm, Ms);
          if (c.length) return { ok: false, conflicts: c };
          const cur = Store.product(p.id); const before = cur.totalStock + '/' + (cur.damaged || 0) + '/' + (cur.missing || 0);
          Object.assign(cur, { totalStock: T, damaged: Dm, missing: Ms }); Store.save();
          Store.audit('Admin updated stock', cur.id + ' · ' + cur.name + ' · total/damaged/missing ' + before + ' → ' + T + '/' + Dm + '/' + Ms); return { ok: true, product: cur };
        });
        if (res.busy) return UI.toast(res.error, 'warn');
        if (!res.ok) { m.el.querySelector('#sE').innerHTML = '<div class="alert alert-danger">' + I('alert') + '<div><b>Update blocked.</b> Accepted reservations would be affected:<br>' + res.conflicts.map(x => U.fmtDate(x.date) + ': ' + x.reserved + ' reserved, only ' + Math.max(0, x.capacity) + ' would remain usable').join('<br>') + '</div></div>'; return; }
        notifySupplier(res.product, 'Stock updated by admin', res.product.name + ': total ' + T + ', damaged ' + Dm + ', missing ' + Ms + '.');
        m.close(); UI.toast('Stock updated.', 'success'); draw();
      };
    }
    draw();
  };

  /* ================= PACKAGE MANAGEMENT (admin) ================= */
  AD.editPackage = function (ctx) {
    const id = ctx.params.id;
    const notFound = () => { ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('admin/listings.html', { tab: 'packages' }) + '">Listings & Packages</a> / Not found</div>' + UI.empty('📦', 'Package not found', 'No rental package matches "' + (id || '') + '", or it has been deleted.', '<a class="btn" href="' + url('admin/listings.html', { tab: 'packages' }) + '">Back to packages</a>'); };
    let checkFrom = U.today(), checkTo = U.today();
    function draw() {
      const k = Store.pkg(id); if (Services.listingStatus('package', k) === 'deleted') return notFound();
      const sup = Store.supplier(k.supplierId); const owner = Store.user(sup.ownerId);
      const manage = Auth.can('listings.manage');
      const v = Pricing.packageValue(k);
      const cap = Services.maxDaysFor('package', k.id);
      const range = U.range(checkFrom, checkTo < checkFrom ? checkFrom : checkTo);
      const perDay = range.map(d => ({ d, a: Inventory.packageAvailableOn(k.id, d) }));
      const low = perDay.reduce((x, y) => (y.a < x.a ? y : x), perDay[0]);
      const limiting = k.items.map(it => ({ it, a: Math.floor(Inventory.availableOn(it.productId, low.d) / it.qty) })).sort((a, b) => a.a - b.a)[0];
      const next30 = U.range(U.today(), U.addDays(U.today(), 29));
      const bookings = Store.all('requests').filter(r => ['pending'].concat(Inventory.RESERVING).includes(r.status) && r.end >= U.today() && r.lines.some(l => l.type === 'package' && l.refId === k.id && Object.keys(l.days).some(d => next30.includes(d)))).sort((a, b) => a.start.localeCompare(b.start));
      const history = Store.all('audit').filter(a => (a.details || '').includes(k.id)).sort((a, b) => b.at.localeCompare(a.at)).slice(0, 8);
      ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('admin/listings.html', { tab: 'packages' }) + '">Listings & Packages</a> / ' + esc(k.name) + '</div>' +
        '<div class="page-head"><div><h1>' + esc(k.emoji || '') + ' ' + esc(k.name) + '</h1><p>' + k.id + ' · Rental package · ' + esc(sup.name) + '</p></div><div class="row wrap"><a class="btn btn-sm" href="' + url('customer/package.html', { id: k.id }) + '">' + I('eye') + 'View as customer</a>' + (manage ? '<button class="btn btn-sm btn-primary" id="editBtn">' + I('edit') + 'Edit package</button>' : '') + '</div></div>' +
        (!manage ? '<div class="alert alert-info mb-2">' + I('lock') + '<div>You can view this package, but your account does not have the "listings.manage" permission, so management actions are disabled.</div></div>' : '') +
        '<div class="grid grid-side"><div class="stack">' +
        '<div class="card"><div class="grid grid-2" style="align-items:start"><div><div class="pd-gallery"><div class="main" style="aspect-ratio:4/3;font-size:5rem">' + UI.img(k, 'package') + '</div></div><div class="small muted mt-1">' + ((k.images || []).length ? 'Package photo uploaded by the supplier' : 'No photo uploaded — package illustration shown to customers') + '</div></div>' +
        '<div><h3>Details</h3><p class="mb-2">' + esc(k.description || '—') + '</p><dl class="kv"><dt>Events</dt><dd>' + (esc((k.events || []).join(', ')) || '—') + '</dd><dt>Conditions</dt><dd>' + esc(k.conditions || '—') + '</dd><dt>Items</dt><dd>' + k.items.length + ' item types</dd><dt>Created</dt><dd>' + U.fmtDate((k.createdAt || '').slice(0, 10)) + '</dd></dl></div></div></div>' +
        '<div class="card"><h3>Included items</h3>' + UI.table([{ label: 'Item', render: it => { const p = Store.product(it.productId); return p ? '<div class="row">' + UI.thumb(p, null, 'sm') + '<a href="' + url('admin/edit-listing.html', { id: p.id }) + '">' + esc(p.name) + '</a></div>' : '—'; } }, { label: 'Qty per package', cls: 'num', render: it => '<b>' + it.qty + '</b>' }, { label: 'Item status', render: it => AD.listingBadge('product', Store.product(it.productId)) }, { label: 'Item stock today', cls: 'num', render: it => Inventory.availableOn(it.productId, U.today()) + ' / ' + (Store.product(it.productId) || {}).totalStock }], k.items) + '</div>' +
        '<div class="card"><div class="card-head"><h3>Rental price</h3><span class="badge ' + (cap ? 'b-purple' : 'b-gray') + ' plain">' + I('clock').replace('<svg', '<svg style="width:13px;height:13px"') + ' ' + (cap ? 'Maximum Rental: ' + cap + ' Day' + (cap > 1 ? 's' : '') + ' (from its items)' : 'No Rental Duration Limit') + '</span></div><dl class="kv"><dt>Package price</dt><dd class="price">' + U.money(k.price) + ' / day</dd><dt>Items rented separately</dt><dd>' + U.money(v.separate) + ' / day</dd><dt>Customer saves</dt><dd>' + U.money(v.savings) + ' / day</dd></dl></div>' +
        '<div class="card"><div class="card-head"><h3>Availability</h3><span class="small muted">Based on the stock of every included item</span></div><div class="stats mb-2">' + UI.stat('Available today', Inventory.packageAvailableOn(k.id, U.today()), 'whole packages', 'check', 'teal') + UI.stat('Upcoming bookings', bookings.length, 'next 30 days', 'calendar', 'pink') + '</div>' +
        '<div class="row wrap mb-1" style="gap:8px"><span class="small strong">Check a rental period</span><span class="range-inline"><input type="date" class="input input-sm" id="cf" value="' + checkFrom + '" aria-label="From"><input type="date" class="input input-sm" id="ct" value="' + checkTo + '" aria-label="To"></span></div>' +
        '<div class="alert alert-info">' + I('info') + '<div class="small"><b>' + low.a + ' package(s) available</b> for ' + esc(RentalPeriod.rangeLabel(range[0], range[range.length - 1])) + ' (' + RentalPeriod.durationLabel(range.length) + ')' + (limiting ? ' — limited by ' + esc((Store.product(limiting.it.productId) || {}).name || '') + ' on ' + esc(U.fmtDay(low.d)) : '') + '.</div></div>' +
        '<h4 class="mt-2">Bookings in the next 30 days</h4>' + UI.table([{ label: 'Request', render: r => '<b>' + r.id + '</b><div class="small muted">' + esc(r.eventName) + '</div>' }, { label: 'Customer', render: r => esc(Store.user(r.customerId).name) }, { label: 'Dates', render: r => U.fmtRange(r.start, r.end) }, { label: 'Status', render: r => UI.statusBadge(r.status) }], bookings, { emptyText: 'No upcoming bookings for this package.' }) + '</div>' +
        '<div class="card"><h3>Admin activity</h3>' + (history.length ? history.map(a => '<div style="padding:8px 0;border-bottom:1px solid var(--line)"><b class="small">' + esc(a.action) + '</b><div class="small muted">' + esc(a.details) + ' · ' + esc((Store.user(a.actorId) || { name: 'System' }).name) + ' · ' + U.timeAgo(a.at) + '</div></div>').join('') : '<p class="small muted mb-0">No admin actions recorded for this package yet.</p>') + '</div>' +
        '</div><aside class="stack">' + AD.statusCard('package', k, manage) +
        '<div class="card"><h3>Supplier</h3><div class="row mb-1"><span class="logo-tile" style="width:44px;height:44px;font-size:1.3rem;background:' + sup.color + '1a">' + esc(sup.logo) + '</span><div><b>' + esc(sup.name) + '</b><div class="small muted">' + esc(sup.city) + ' · ★ ' + (sup.rating || '—') + '</div></div></div><dl class="kv small"><dt>Owner</dt><dd>' + esc(owner.name) + '<br>' + esc(owner.email) + '</dd><dt>Contact</dt><dd>' + esc(sup.phone) + '</dd><dt>Status</dt><dd>' + AD.statusBadge(sup.status) + ' ' + AD.statusBadge(owner.status) + '</dd></dl><a class="btn btn-sm btn-block mt-1" href="' + url('customer/supplier.html', { id: sup.id }) + '">View shop</a></div>' +
        '</aside></div>';
      $('#cf').onchange = e => { checkFrom = e.target.value || U.today(); if (checkTo < checkFrom) checkTo = checkFrom; draw(); };
      $('#ct').onchange = e => { checkTo = e.target.value || checkFrom; if (checkTo < checkFrom) checkTo = checkFrom; draw(); };
      AD.bindStatusCard('package', k, draw);
      if (manage) $('#editBtn').onclick = () => editModal(k);
    }
    function editModal(k) {
      const m = UI.modal({ title: 'Edit package · ' + k.name, body: '<form id="pf" novalidate><div class="form-grid"><div class="field"><label>Package name</label><input class="input" name="name" required minlength="3" value="' + esc(k.name) + '"></div><div class="field"><label>Emoji / icon</label><input class="input" name="emoji" maxlength="4" value="' + esc(k.emoji || '') + '"></div><div class="field full"><label>Description</label><textarea class="input" name="description" required minlength="10">' + esc(k.description) + '</textarea></div><div class="field"><label>Package price (₱ / day)</label><input class="input" type="number" min="1" name="price" required value="' + k.price + '"></div><div class="field"><label>Events</label><input class="input" name="events" value="' + esc((k.events || []).join(', ')) + '"></div><div class="field full"><label>Conditions</label><input class="input" name="conditions" value="' + esc(k.conditions || '') + '"></div></div><p class="small muted mb-0">Included items are managed by the supplier. Price changes apply to new requests only.</p><div id="pErr"></div></form>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="pSave">Save changes</button>' });
      m.el.querySelector('#pSave').onclick = () => {
        const f = m.el.querySelector('#pf'); if (!UI.validate(f)) return; const d = UI.formData(f);
        const res = Services.savePackage(k.supplierId, Object.assign({}, k, { name: d.name.trim(), emoji: d.emoji, description: d.description.trim(), price: +d.price, conditions: d.conditions, events: d.events.split(',').map(x => x.trim()).filter(Boolean) }));
        if (!res.ok) { m.el.querySelector('#pErr').innerHTML = '<div class="alert alert-danger mt-1">' + I('alert') + '<div>' + esc(res.error) + '</div></div>'; return; }
        Store.notify(Store.supplier(k.supplierId).ownerId, 'supplier', 'Package edited by admin', res.package.name + ' was updated by a PartyRent administrator.', 'supplier/packages.html');
        Store.audit('Admin edited package', 'Rental package ' + res.package.id + ' · ' + res.package.name);
        m.close(); UI.toast('Package updated.', 'success'); draw();
      };
    }
    if (!guardPerm(ctx, 'listings.view')) return;
    draw();
  };

  /* ================= CATEGORIES ================= */
  AD.categories = function (ctx) {
    if (!guardPerm(ctx, 'categories.manage')) return;
    const draw = () => {
      const cats = Store.all('categories');
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Category Management</h1><p>Categories organize the marketplace and AI suggestions.</p></div><button class="btn btn-primary" id="add">' + I('plus') + 'Add category</button></div><div class="card pad-0">' + UI.table([{ label: 'Category', render: c => '<div class="row"><div class="thumb sm" style="background:linear-gradient(135deg,' + c.grad[0] + ',' + c.grad[1] + ')">' + Icons.art(c.icon) + '</div><div><b>' + esc(c.name) + '</b><div class="small muted">' + c.id + '</div></div></div>' }, { label: 'Listings', cls: 'num', render: c => Store.where('products', p => p.categoryId === c.id).length }, { label: 'Status', render: c => '<span class="badge ' + (c.active ? 'b-green' : 'b-gray') + '">' + (c.active ? 'Visible' : 'Hidden') + '</span>' }, { label: '', render: c => '<div class="btn-group"><button class="btn btn-xs" data-edit="' + c.id + '">Rename</button><button class="btn btn-xs" data-tog="' + c.id + '">' + (c.active ? 'Hide' : 'Show') + '</button></div>' }], cats) + '</div>';
      $('#add').onclick = () => edit(null);
      $$('[data-edit]').forEach(b => b.onclick = () => edit(Store.category(b.dataset.edit)));
      $$('[data-tog]').forEach(b => b.onclick = async () => { const c = Store.category(b.dataset.tog); const n = Store.where('products', p => p.categoryId === c.id && p.status === 'published').length; if (c.active && n && !await UI.confirm({ title: 'Hide ' + c.name + '?', message: n + ' published listings use this category. They stay published but the category link disappears from navigation.', confirmText: 'Hide' })) return; c.active = !c.active; Store.save(); Store.audit((c.active ? 'Showed' : 'Hid') + ' category', c.name); draw(); });
    };
    function edit(c) {
      const icons = Object.keys(Icons.ART);
      const m = UI.modal({ title: c ? 'Edit category' : 'Add category', size: 'sm', body: '<form id="cf" novalidate><div class="field"><label>Name</label><input class="input" name="name" required value="' + esc(c ? c.name : '') + '"></div><div class="field"><label>Illustration</label><select class="input" name="icon">' + icons.map(i => '<option' + (c && c.icon === i ? ' selected' : '') + '>' + i + '</option>').join('') + '</select></div></form>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="ok">Save</button>' });
      m.el.querySelector('#ok').onclick = () => { const f = m.el.querySelector('#cf'); if (!UI.validate(f)) return; const name = f.name.value.trim(); if (Store.all('categories').some(x => x.name.toLowerCase() === name.toLowerCase() && x !== c)) return UI.toast('A category with this name exists.', 'error'); if (c) { c.name = name; c.icon = f.icon.value; } else Store.insert('categories', { id: name.toLowerCase().replace(/[^a-z0-9]+/g, '-'), name, icon: f.icon.value, grad: ['#FBCFE8', '#DB2777'], active: true }); Store.save(); Store.audit(c ? 'Renamed category' : 'Created category', name); m.close(); draw(); };
    }
    draw();
  };

  /* ================= TRANSACTIONS & RESERVATIONS ================= */
  AD.transactions = function (ctx) {
    if (!guardPerm(ctx, 'transactions.view')) return;
    let tab = ctx.params.tab || 'transactions', q = '', st = '', sup = '';
    const draw = () => {
      const reqs = Store.all('requests').slice().sort((a, b) => b.createdAt.localeCompare(a.createdAt));
      const base = tab === 'reservations' ? reqs.filter(r => Finance.ACTIVE.includes(r.status)).sort((a, b) => a.start.localeCompare(b.start)) : reqs;
      const list = base.filter(r => (!st || r.status === st) && (!sup || r.supplierId === sup) && (!q || (r.id + ' ' + r.eventName + ' ' + Store.user(r.customerId).name).toLowerCase().includes(q)));
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Transactions & Reservations</h1><p>Read-only monitoring of every rental on the platform.</p></div></div><div class="row wrap mb-2"><input class="input input-sm" id="q" placeholder="Search request, customer" style="width:220px" value="' + esc(q) + '" aria-label="Search"><select class="input input-sm" id="st" style="width:auto" aria-label="Status"><option value="">All statuses</option>' + Object.keys(Services.STATUS).map(k => '<option value="' + k + '"' + (k === st ? ' selected' : '') + '>' + Services.statusLabel(k) + '</option>').join('') + '</select><select class="input input-sm" id="sp" style="width:auto" aria-label="Supplier"><option value="">All suppliers</option>' + Store.all('suppliers').map(s => '<option value="' + s.id + '"' + (s.id === sup ? ' selected' : '') + '>' + esc(s.name) + '</option>').join('') + '</select></div>' + UI.tabs([{ key: 'transactions', label: 'All transactions', count: reqs.length }, { key: 'reservations', label: 'Active reservations', count: reqs.filter(r => Finance.ACTIVE.includes(r.status)).length }], tab) + '<div class="card pad-0">' + UI.table([{ label: 'Request', render: r => '<b>' + r.id + '</b><div class="small muted">' + esc(r.eventName) + '</div>' }, { label: 'Customer', render: r => esc(Store.user(r.customerId).name) }, { label: 'Supplier', render: r => esc(Store.supplier(r.supplierId).name) }, { label: 'Dates', render: r => U.fmtRange(r.start, r.end) }, { label: 'Amount', cls: 'num', render: r => U.money(r.pricing.payable) }, { label: 'Rental status', render: r => UI.statusBadge(r.status) }, { label: 'Payment', render: r => UI.payBadge(Finance.summary(r).status) }, { label: '', render: r => '<button class="btn btn-xs" data-open="' + r.id + '">Details</button>' }], list) + '</div>';
      $$('[data-tab]').forEach(t => t.onclick = () => { tab = t.dataset.tab; draw(); });
      $('#q').onchange = e => { q = e.target.value.toLowerCase(); draw(); }; $('#st').onchange = e => { st = e.target.value; draw(); }; $('#sp').onchange = e => { sup = e.target.value; draw(); };
      $$('[data-open]').forEach(b => b.onclick = () => AD.requestModal(Store.request(b.dataset.open)));
    };
    draw();
  };
  AD.requestModal = function (r) {
    const c = Store.user(r.customerId), s = Store.supplier(r.supplierId);
    UI.modal({ title: r.id + ' · ' + r.eventName, size: 'lg', body: '<div class="row wrap mb-2">' + UI.statusBadge(r.status) + UI.payBadge(Finance.summary(r).status) + '</div><dl class="kv mb-2"><dt>Customer</dt><dd>' + esc(c.name) + ' · ' + esc(c.email) + '</dd><dt>Supplier</dt><dd>' + esc(s.name) + '</dd><dt>Dates</dt><dd>' + U.fmtRange(r.start, r.end) + ' · ' + (r.fulfillment === 'delivery' ? 'Delivery to ' + esc(r.address) : 'Pickup') + '</dd><dt>Arrangement</dt><dd>' + Finance.PLAN_LABEL[r.plan] + ' · ' + Finance.METHOD_LABEL[r.method] + '</dd></dl><div class="grid grid-2"><div>' + Sh().linesTable(r) + '</div><div class="card card-flat">' + Sh().paymentSummary(r) + '</div></div><h4 class="mt-2">Payments</h4>' + Sh().paymentHistory(r) + (r.inspection ? '<div class="mt-2">' + Sh().inspectionReport(r) + '</div>' : '') + '<h4 class="mt-2">Activity</h4>' + Sh().timeline(r) });
  };

  /* ================= COMPLAINTS ================= */
  AD.complaints = function (ctx) {
    let tab = 'open';
    const draw = () => {
      const all = Store.all('complaints').slice().sort((a, b) => b.at.localeCompare(a.at));
      const grp = { open: ['open'], in_review: ['in_review'], resolved: ['resolved'] };
      const can = Auth.can('complaints.manage');
      ctx.el.innerHTML = '<div class="page-head"><div><h1>Complaints & Issues</h1><p>Reports from customers and suppliers.</p></div><button class="btn btn-sm" id="newC">' + I('plus') + 'Log an issue</button></div>' + UI.tabs([{ key: 'open', label: 'Open', count: all.filter(c => c.status === 'open').length }, { key: 'in_review', label: 'In review', count: all.filter(c => c.status === 'in_review').length }, { key: 'resolved', label: 'Resolved', count: all.filter(c => c.status === 'resolved').length }], tab) +
        '<div class="stack">' + (all.filter(c => grp[tab].includes(c.status)).map(c => { const rep = Store.user(c.reporterId); const ag = Store.supplier(c.against); return '<div class="card"><div class="row between"><div><b>' + esc(c.subject) + '</b> <span class="badge ' + ({ high: 'b-red', normal: 'b-gold', low: 'b-gray' }[c.priority]) + '">' + U.cap(c.priority) + '</span><div class="small muted">' + c.id + ' · ' + esc(rep ? rep.name : '') + (ag ? ' about ' + esc(ag.name) : '') + (c.requestId ? ' · ' + c.requestId : '') + ' · ' + U.timeAgo(c.at) + '</div></div><span class="badge ' + ({ open: 'b-red', in_review: 'b-gold', resolved: 'b-green' }[c.status]) + '">' + U.cap(c.status.replace('_', ' ')) + '</span></div><p class="mt-1">' + esc(c.body) + '</p>' + (c.updates.length ? '<ul class="timeline">' + c.updates.map(u => '<li class="small"><b>' + esc((Store.user(u.by) || {}).name || '') + '</b> · ' + U.fmtDateTime(u.at) + '<div>' + esc(u.note) + '</div></li>').join('') + '</ul>' : '') + (can && c.status !== 'resolved' ? '<div class="btn-group mt-1">' + (c.requestId ? '<button class="btn btn-xs" data-req="' + c.requestId + '">View rental</button>' : '') + '<button class="btn btn-xs" data-note="' + c.id + '">Add update</button>' + (c.status === 'open' ? '<button class="btn btn-xs" data-review="' + c.id + '">Mark in review</button>' : '') + '<button class="btn btn-xs btn-success" data-resolve="' + c.id + '">Resolve</button></div>' : '') + '</div>'; }).join('') || '<div class="card">' + UI.empty('✅', 'No complaints here', '') + '</div>') + '</div>';
      $$('[data-tab]').forEach(t => t.onclick = () => { tab = t.dataset.tab; draw(); });
      const upd = async (id, status, title) => { const note = await UI.prompt({ title, label: 'Update note', required: true, textarea: true, confirmText: 'Save' }); if (note == null) return; const c = Store.get('complaints', id); c.updates.push({ at: new Date().toISOString(), by: ctx.session.user.id, note }); if (status) c.status = status; Store.save(); Store.audit('Updated complaint', c.id + ' · ' + (status || 'note') + ' · ' + note); if (status === 'resolved') Store.notify(c.reporterId, 'customer', 'Your report was resolved', c.subject + ': ' + note, ''); UI.toast('Complaint updated.', 'success'); draw(); };
      $$('[data-note]').forEach(b => b.onclick = () => upd(b.dataset.note, null, 'Add update'));
      $$('[data-review]').forEach(b => b.onclick = () => upd(b.dataset.review, 'in_review', 'Mark in review'));
      $$('[data-resolve]').forEach(b => b.onclick = () => upd(b.dataset.resolve, 'resolved', 'Resolve complaint'));
      $$('[data-req]').forEach(b => b.onclick = () => AD.requestModal(Store.request(b.dataset.req)));
      $('#newC').onclick = () => {
        const m = UI.modal({ title: 'Log an issue', body: '<form id="nf" novalidate><div class="field"><label>Reported by</label><select class="input" name="reporterId" required>' + Store.where('users', u => u.roles.includes('customer')).map(u => '<option value="' + u.id + '">' + esc(u.name) + '</option>').join('') + '</select></div><div class="field"><label>About supplier (optional)</label><select class="input" name="against"><option value="">—</option>' + Store.all('suppliers').map(s => '<option value="' + s.id + '">' + esc(s.name) + '</option>').join('') + '</select></div><div class="field"><label>Subject</label><input class="input" name="subject" required></div><div class="field"><label>Details</label><textarea class="input" name="body" required></textarea></div><div class="field"><label>Priority</label><select class="input" name="priority"><option>normal</option><option>high</option><option>low</option></select></div></form>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="ok">Save</button>' });
        m.el.querySelector('#ok').onclick = () => { const f = m.el.querySelector('#nf'); if (!UI.validate(f)) return; const d = UI.formData(f); Store.insert('complaints', Object.assign(d, { id: 'CMP-' + String(Store.nextSeq('complaint')).padStart(3, '0'), status: 'open', at: new Date().toISOString(), updates: [], requestId: null })); Store.audit('Logged complaint', d.subject); m.close(); tab = 'open'; draw(); };
      };
    };
    draw();
  };

  /* ================= ACTIVITY ================= */
  AD.activity = function (ctx, opts) {
    opts = opts || {};
    let q = '', role = '';
    const draw = () => {
      const list = Store.all('audit').slice().sort((a, b) => b.at.localeCompare(a.at)).filter(a => (!role || a.role === role) && (!q || (a.action + ' ' + a.details + ' ' + ((Store.user(a.actorId) || {}).name || '')).toLowerCase().includes(q)));
      ctx.el.innerHTML = '<div class="page-head"><div><h1>' + (opts.title || 'Activity Monitoring') + '</h1><p>' + (opts.sub || 'Important financial and administrative actions across the platform.') + '</p></div><div class="row wrap"><input class="input input-sm" id="q" placeholder="Search actions" style="width:220px" value="' + esc(q) + '" aria-label="Search"><select class="input input-sm" id="rl" style="width:auto" aria-label="Role"><option value="">All roles</option>' + ['customer', 'supplier', 'cashier', 'admin', 'superadmin', 'system'].map(r => '<option value="' + r + '"' + (r === role ? ' selected' : '') + '>' + U.cap(r) + '</option>').join('') + '</select></div></div><div class="card pad-0">' + UI.table([{ label: 'When', render: a => U.fmtDateTime(a.at) }, { label: 'Actor', render: a => esc((Store.user(a.actorId) || { name: 'System' }).name) + '<div class="small muted">' + U.cap(a.role) + '</div>' }, { label: 'Action', render: a => '<b>' + esc(a.action) + '</b>' }, { label: 'Details', render: a => '<span class="small">' + esc(a.details) + '</span>' }, { label: 'ID', render: a => '<span class="small muted">' + a.id + '</span>' }], list) + '</div>';
      $('#q').onchange = e => { q = e.target.value.toLowerCase(); draw(); }; $('#rl').onchange = e => { role = e.target.value; draw(); };
    };
    draw();
  };

  /* ================= REPORTS ================= */
  AD.reports = function (ctx) {
    if (!guardPerm(ctx, 'reports.view')) return;
    const users = Store.all('users'); const reqs = Store.all('requests'); const months = Charts.months(6);
    const sups = Store.all('suppliers');
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Reports & Analytics</h1><p>User, supplier, rental and listing statistics.</p></div></div><div class="grid grid-2"><div class="card"><h3>New users per month</h3><div id="r1"></div></div><div class="card"><h3>Rental transactions per month</h3><div id="r2"></div></div><div class="card"><h3>Supplier statistics</h3>' + UI.table([{ label: 'Supplier', render: s => esc(s.name) }, { label: 'Listings', cls: 'num', render: s => Store.where('products', p => p.supplierId === s.id && p.status === 'published').length }, { label: 'Requests', cls: 'num', render: s => reqs.filter(r => r.supplierId === s.id).length }, { label: 'Accept rate', cls: 'num', render: s => { const d = reqs.filter(r => r.supplierId === s.id && r.status !== 'pending'); return d.length ? Math.round(d.filter(r => r.status !== 'declined').length / d.length * 100) + '%' : '—'; } }, { label: 'Rating', cls: 'num', render: s => s.rating || '—' }], sups.filter(s => s.status === 'approved'), { noCards: true }) + '</div><div class="card"><h3>Listings by category</h3><div id="r3"></div></div><div class="card"><h3>Users by role</h3><div id="r4"></div></div><div class="card"><h3>Complaints & issues</h3><div id="r5"></div></div></div>';
    Charts.bars($('#r1'), months.map(m => ({ label: m.label, value: users.filter(u => u.createdAt.slice(0, 7) === m.key).length })), { color: '#E11D74' });
    Charts.bars($('#r2'), months.map(m => ({ label: m.label, value: reqs.filter(r => r.createdAt.slice(0, 7) === m.key).length })), { color: '#7C3AED' });
    Charts.hbars($('#r3'), Store.all('categories').map(c => ({ label: c.name, value: Store.where('products', p => p.categoryId === c.id && p.status === 'published').length })).sort((a, b) => b.value - a.value));
    Charts.donut($('#r4'), [{ label: 'Customers only', value: users.filter(u => u.roles.includes('customer') && !u.supplierId).length }, { label: 'Customer + Supplier', value: users.filter(u => u.supplierId).length }, { label: 'Cashiers', value: users.filter(u => u.roles.includes('cashier')).length }, { label: 'Admins', value: users.filter(u => u.roles.includes('admin')).length }, { label: 'Superadmins', value: users.filter(u => u.roles.includes('superadmin')).length }], { center: 'users' });
    const cs = Store.all('complaints');
    Charts.donut($('#r5'), [{ label: 'Open', value: cs.filter(c => c.status === 'open').length }, { label: 'In review', value: cs.filter(c => c.status === 'in_review').length }, { label: 'Resolved', value: cs.filter(c => c.status === 'resolved').length }], { center: 'issues' });
  };
})();
