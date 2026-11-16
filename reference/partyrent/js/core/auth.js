/* =========================================================
   PartyRent — auth.js
   Mock authentication (demo only — passwords are stored in
   the browser and NOT secure). Sessions, roles, the
   Customer ⇄ Supplier mode switch, and permission checks.
   ========================================================= */
(function (g) {
  'use strict';
  const SKEY = 'partyrent_session';
  let mem = null;

  const ALL_ADMIN_PERMS = [
    ['customers.view', 'View customer accounts'], ['customers.manage', 'Activate / suspend customers'],
    ['suppliers.view', 'View supplier accounts'], ['suppliers.manage', 'Activate / suspend suppliers'],
    ['applications.review', 'Approve / reject supplier applications'], ['listings.view', 'View product listings'],
    ['listings.manage', 'Hide / restore listings'], ['categories.manage', 'Manage categories'],
    ['transactions.view', 'View transactions & reservations'], ['complaints.manage', 'Handle complaints'], ['reports.view', 'View reports']
  ];
  const ALL_CASHIER_PERMS = [['payments.view', 'View payments'], ['payments.verify', 'Verify / reject payments'], ['payments.record', 'Record offline payments & refunds'], ['receipts.issue', 'Issue receipts'], ['reports.view', 'View financial reports']];

  /* ---------- supplier organizations: Organization Admin + employees (multi-tenant RBAC) ----------
     Every supplier (individual or organization) has one main supplier account (supplier.id = the
     organization ID). Its owner is the Organization Admin. Employees (Cashier, Inventory Staff, Staff)
     are separate logins tied to the same supplier ID and only ever see that supplier's data. */
  const ORG_ROLES = { org_admin: 'Organization Admin', cashier: 'Cashier', inventory: 'Inventory Staff', staff: 'Staff' };
  const ORG_PERM_LIST = [
    ['orders.view', 'View rental requests & schedules'], ['orders.manage', 'Accept / decline rental requests'], ['fulfillment.update', 'Update delivery, pickup & return status'],
    ['products.manage', 'Add & update products and packages'], ['inventory.manage', 'Manage stock, maintenance & damaged units'], ['locations.manage', 'Manage inventory locations & transfers'], ['returns.inspect', 'Record return inspections & damage'],
    ['payments.view', 'View payments & balances'], ['payments.verify', 'Verify / reject payments'], ['payments.record', 'Record payments & refunds'], ['receipts.issue', 'Issue receipts'], ['reports.view', 'View business & financial reports'],
    ['shop.manage', 'Edit shop profile, payment plans & policies'], ['team.manage', 'Manage employees & roles']
  ];
  const ORG_PERMS = {
    org_admin: ORG_PERM_LIST.map(x => x[0]),
    cashier: ['payments.view', 'payments.verify', 'payments.record', 'receipts.issue', 'reports.view'],
    inventory: ['orders.view', 'products.manage', 'inventory.manage', 'locations.manage', 'returns.inspect'],
    staff: ['orders.view', 'fulfillment.update', 'returns.inspect']
  };
  /** Role of a user inside their supplier organization (null if none). */
  function orgRole(u) {
    if (!u || !u.supplierId) return null;
    const sp = Store.supplier(u.supplierId);
    if (sp && sp.ownerId === u.id) return 'org_admin';
    return ORG_PERMS[u.orgRole] ? u.orgRole : null;
  }
  const isEmployee = u => !!(u && u.orgRole && u.roles.some(r => r === 'cashier' || r === 'employee'));
  /** Organization permission check (only for the user's own approved supplier). */
  function orgCan(perm, u) {
    if (!u) { const s = session(); u = s && s.user; }
    const role = orgRole(u); if (!role) return false;
    const sp = Store.supplier(u.supplierId); if (!sp || sp.status !== 'approved') return false;
    return ORG_PERMS[role].includes(perm);
  }
  /** May the current user act on this supplier's money (verify / record payments, issue receipts)? */
  function canFinance(supplierId, perm, u) {
    if (!u) { const s = session(); u = s && s.user; }
    return !!(u && u.status === 'active' && u.supplierId === supplierId && orgCan(perm || 'payments.verify', u));
  }

  function readSession() { try { return JSON.parse(localStorage.getItem(SKEY) || 'null'); } catch (e) { return mem; } }
  function writeSession(s) { mem = s; try { if (s) localStorage.setItem(SKEY, JSON.stringify(s)); else localStorage.removeItem(SKEY); } catch (e) { /* memory */ } }

  function session() {
    const s = readSession(); if (!s) return null;
    const user = Store.user(s.userId);
    if (!user || user.status !== 'active') { writeSession(null); return null; }
    return { user, mode: s.mode || defaultMode(user) };
  }
  const isStaff = u => u.roles.some(r => ['admin', 'superadmin', 'cashier', 'employee'].includes(r));
  function defaultMode(u) { if (u.roles.includes('superadmin')) return 'superadmin'; if (u.roles.includes('admin')) return 'admin'; if (u.roles.includes('cashier')) return 'cashier'; if (u.roles.includes('employee')) return 'supplier'; return 'customer'; }
  /** Supplier profile of a user if the application was approved. */
  function supplierOf(u) { if (!u || !u.supplierId) return null; return Store.supplier(u.supplierId); }
  const isApprovedSupplier = u => { const s = supplierOf(u); return !!(s && s.status === 'approved'); };
  /** Role the interface is currently acting as. */
  function activeRole(u, mode) { if (!u) return null; if (isStaff(u)) return defaultMode(u); return mode === 'supplier' && isApprovedSupplier(u) ? 'supplier' : 'customer'; }

  function home(u, mode) {
    const r = activeRole(u, mode);
    return { customer: 'index.html', supplier: 'supplier/dashboard.html', admin: 'admin/dashboard.html', superadmin: 'superadmin/dashboard.html', cashier: 'cashier/dashboard.html' }[r];
  }

  function login(email, password) {
    const u = Store.all('users').find(x => x.email.toLowerCase() === String(email).trim().toLowerCase());
    if (!u || u.password !== password) return { ok: false, error: 'Incorrect email or password.' };
    if (u.status !== 'active') return { ok: false, error: 'This account is ' + u.status + '. Please contact PartyRent support.' };
    const mode = defaultMode(u);
    writeSession({ userId: u.id, mode, at: Date.now() });
    u.lastLogin = new Date().toISOString(); Store.save();
    if (isStaff(u)) Store.audit('Signed in', u.email, u);
    // customers choose their rental dates (or just browse) right after logging in
    if (g.RentalPeriod) { RentalPeriod.clear(); if (!isStaff(u)) RentalPeriod.requestPrompt(u.id); }
    return { ok: true, user: u, redirect: home(u, mode) };
  }
  function logout() { writeSession(null); if (g.RentalPeriod) { RentalPeriod.clear(); RentalPeriod.cancelPrompt(); } }

  function register(data) {
    const email = data.email.trim().toLowerCase();
    if (Store.all('users').some(u => u.email.toLowerCase() === email)) return { ok: false, error: 'An account with this email already exists.' };
    const u = { id: U.uid('u'), name: data.name.trim(), email, phone: data.phone.trim(), address: data.address.trim(), password: data.password, roles: ['customer'], status: 'active', createdAt: new Date().toISOString() };
    Store.insert('users', u);
    Store.notify(u.id, 'customer', 'Welcome to PartyRent! 🎉', 'Browse party supplies, or describe your event to our AI planner to get started.', 'customer/ai.html');
    writeSession({ userId: u.id, mode: 'customer', at: Date.now() });
    return { ok: true, user: u };
  }

  function setMode(mode) {
    const s = session(); if (!s) return false;
    if (mode === 'supplier' && !isApprovedSupplier(s.user)) return false;
    if (isStaff(s.user)) return false; // employees and staff have no customer / supplier mode switch
    writeSession({ userId: s.user.id, mode, at: Date.now() });
    return true;
  }

  /** Permission check for staff roles (Superadmin has every permission). */
  function can(perm, u) {
    const s = session(); u = u || (s && s.user); if (!u) return false;
    if (u.roles.includes('superadmin')) return true;
    if (u.roles.includes('admin')) return (u.adminPerms || Store.load().permissions.admin).includes(perm);
    if (u.roles.includes('cashier')) return orgCan(perm, u); // a supplier's cashier: only within their own organization
    return false;
  }

  /**
   * Guard a page. role: 'public' | 'customer' | 'supplier' | 'admin' | 'superadmin' | 'cashier' | 'any'
   * Redirects when the current session cannot use the page. Returns the session.
   */
  function guard(role) {
    const s = session();
    if (role === 'public') return s;
    if (!s) { U.go('login.html', { next: location.pathname.split('/').slice(-2).join('/') }); throw new Error('redirect'); }
    if (role === 'any') return s;
    const active = activeRole(s.user, s.mode);
    if (role === 'customer' && isStaff(s.user)) { location.href = U.url(home(s.user)); throw new Error('redirect'); }
    if (role === 'customer' && active === 'supplier') { setMode('customer'); return session(); }
    if (role === 'supplier' && active === 'supplier' && isEmployee(s.user) && !isApprovedSupplier(s.user)) { writeSession(null); U.go('login.html'); throw new Error('redirect'); }
    if (role === 'supplier' && active !== 'supplier') {
      if (isApprovedSupplier(s.user)) { setMode('supplier'); return session(); }
      U.go('customer/start-selling.html'); throw new Error('redirect');
    }
    if (['admin', 'superadmin', 'cashier'].includes(role) && active !== role) { location.href = U.url(home(s.user, s.mode)); throw new Error('redirect'); }
    return s;
  }

  g.Auth = { ORG_ROLES, ORG_PERM_LIST, ORG_PERMS, orgRole, orgCan, canFinance, isEmployee, session, login, logout, register, setMode, activeRole, home, guard, can, isStaff, supplierOf, isApprovedSupplier, ALL_ADMIN_PERMS, ALL_CASHIER_PERMS };
})(window);
