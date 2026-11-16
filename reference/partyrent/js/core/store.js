/* =========================================================
   PartyRent — store.js
   Single data layer for the whole frontend. Persists to
   localStorage (falls back to memory). Swap these methods for
   fetch() calls to connect a real backend — every page reads
   and writes data only through Store / Services.
   ========================================================= */
(function (g) {
  'use strict';
  const KEY = 'partyrent_db_v4';
  let db = null;
  let memoryOnly = false;

  function load() {
    if (db) return db;
    try {
      const raw = localStorage.getItem(KEY);
      if (raw) db = JSON.parse(raw);
    } catch (e) { memoryOnly = true; }
    if (!db || !db.meta) { db = Seed.build(); finalizeSeed(db); save(); }
    else if (migrate(db)) save();
    return db;
  }
  /** Fill computed snapshots on seeded requests (price snapshot, due schedule). */
  /** Remove the obsolete listing review fields from saved data (listing approval workflow was removed). */
  function migrate(d) {
    let changed = false;
    (d.products || []).forEach(p => {
      ['review', 'reviewedBy', 'reviewedAt'].forEach(k => { if (k in p) { delete p[k]; changed = true; } });
      if (p.removedByAdmin) { p.status = 'deleted'; p.deletedAt = p.removedAt; p.deletedBy = p.removedBy; delete p.removedByAdmin; delete p.removedAt; delete p.removedBy; changed = true; }
    });
    (d.packages || []).forEach(k => { ['review', 'reviewedBy', 'reviewedAt'].forEach(f => { if (f in k) { delete k[f]; changed = true; } }); });
    return changed;
  }
  function finalizeSeed(d) {
    db = d;
    d.requests.forEach(r => {
      const sup = d.suppliers.find(x => x.id === r.supplierId);
      const def = sup && sup.locations && sup.locations.length ? sup.locations[0].id : null;
      r.lines.forEach(l => { if (!l.sourceId) l.sourceId = def; });   // inventory source of each booking line
      r.allocation = r.allocation || (r.status === 'pending' ? { status: 'proposed' } : { status: 'approved', at: r.acceptedAt || null });
      if (!r.pricing) r.pricing = Pricing.quoteRequest(r);
      const ip = r.installmentPlan;
      if (ip && ip.split) { const items = Finance.suggestInstallments(r.pricing.finalBalance, ip.split.length, ip.split[0], ip.split[ip.split.length - 1]); items.forEach((it, i) => { it.due = ip.split[i]; }); ip.items = items; delete ip.split; }
    });
    // seeded payments refer to schedule stages; fill in their amounts and who verified them
    d.payments.forEach(p => {
      const r = d.requests.find(x => x.id === p.requestId);
      if (p.stage) { const sc = Finance.schedule(r).find(x => x.key === p.stage); if (sc) p.amount = sc.amount; delete p.stage; }
      if (p.amount == null) p.amount = 0;
      if (p.status !== 'pending' && !p.verifiedBy) { const sup = d.suppliers.find(x => x.id === r.supplierId); const cashier = d.users.find(u => u.orgRole === 'cashier' && u.supplierId === sup.id); p.verifiedBy = cashier ? cashier.id : sup.ownerId; }
    });
  }
  function save() {
    if (memoryOnly) return;
    db.meta.rev = (db.meta.rev || 0) + 1;   // revision counter: lets other tabs/transactions detect changes
    try { localStorage.setItem(KEY, JSON.stringify(db)); }
    catch (e) {
      memoryOnly = true;
      if (g.UI) UI.toast('Browser storage is full or unavailable — changes will last until you close this tab.', 'warn');
    }
  }
  /* ---------- transactions (demo stand-in for a database transaction + row lock) ----------
     Inventory-critical writes (submitting, accepting, cancelling bookings, stock changes) run
     inside transaction(): it takes a short exclusive lock shared by every open tab, reloads the
     newest saved data if another tab changed it, runs the check-and-write as one synchronous
     step, saves, and releases the lock. Two tabs therefore cannot both confirm the same units.
     A real backend must do the same with a DB transaction + SELECT … FOR UPDATE (see README). */
  const LOCK = 'partyrent_lock';
  const TAB = Math.random().toString(36).slice(2);
  let changedElsewhere = false;
  function storedRev() { try { const raw = localStorage.getItem(KEY); return raw ? (JSON.parse(raw).meta || {}).rev || 0 : null; } catch (e) { return null; } }
  function acquire() {
    if (memoryOnly) return true;
    try {
      const now = Date.now();
      const cur = JSON.parse(localStorage.getItem(LOCK) || 'null');
      if (cur && cur.owner !== TAB && cur.exp > now) return false;
      localStorage.setItem(LOCK, JSON.stringify({ owner: TAB, exp: now + 4000 }));
      return JSON.parse(localStorage.getItem(LOCK)).owner === TAB;
    } catch (e) { return true; }
  }
  function release() { try { const cur = JSON.parse(localStorage.getItem(LOCK) || 'null'); if (cur && cur.owner === TAB) localStorage.removeItem(LOCK); } catch (e) { /* ignore */ } }
  function transaction(fn) {
    load();
    if (!acquire()) return { ok: false, busy: true, error: 'Another booking for these items is being confirmed right now. Please try again in a moment.' };
    try {
      const rev = storedRev();
      let refreshed = false;
      if (rev !== null && rev !== (db.meta.rev || 0)) { db = JSON.parse(localStorage.getItem(KEY)); refreshed = true; }
      const res = fn();
      if (refreshed) markChangedElsewhere();
      return res;
    } finally { release(); }
  }
  /* other tabs: keep this tab's copy current and tell the user the page view may be outdated */
  function markChangedElsewhere() {
    if (changedElsewhere || typeof document === 'undefined' || !document.body) return;
    changedElsewhere = true;
    const bar = document.createElement('div');
    bar.className = 'alert alert-info'; bar.setAttribute('role', 'status');
    bar.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);top:12px;z-index:2100;max-width:min(520px,calc(100vw - 24px));box-shadow:var(--shadow-lg)';
    bar.innerHTML = '<div class="grow small">Bookings or stock were updated in another tab. Refresh to see the latest availability.</div><button class="btn btn-sm btn-primary" type="button">Refresh</button>';
    bar.querySelector('button').onclick = () => location.reload();
    document.body.appendChild(bar);
  }
  if (typeof window !== 'undefined') window.addEventListener('storage', e => {
    if (e.key !== KEY || !e.newValue || !db) return;
    try { const fresh = JSON.parse(e.newValue); if ((fresh.meta || {}).rev !== (db.meta || {}).rev) { db = fresh; markChangedElsewhere(); } } catch (err) { /* ignore */ }
  });

  function reset() { try { localStorage.removeItem(KEY); sessionStorage.clear(); } catch (e) { /* ignore */ } db = null; load(); }

  const all = (c) => load()[c] || [];
  const get = (c, id) => all(c).find(x => x.id === id) || null;
  const where = (c, fn) => all(c).filter(fn);
  function insert(c, obj) { load()[c].push(obj); save(); return obj; }
  function update(c, id, patch) { const o = get(c, id); if (!o) return null; Object.assign(o, typeof patch === 'function' ? patch(o) || {} : patch); save(); return o; }
  function remove(c, id) { const d = load(); d[c] = d[c].filter(x => x.id !== id); save(); }
  function nextSeq(k) { const d = load(); d.meta.seq[k] = (d.meta.seq[k] || 0) + 1; save(); return d.meta.seq[k]; }

  /* ---------- convenience lookups ---------- */
  const user = id => get('users', id);
  const supplier = id => get('suppliers', id);
  const product = id => get('products', id);
  const pkg = id => get('packages', id);
  const category = id => get('categories', id);
  const request = id => get('requests', id);
  const settings = () => load().settings;
  function supplierByOwner(uid) { return all('suppliers').find(s => s.ownerId === uid) || null; }

  /* ---------- notifications ---------- */
  /** to: userId or role name ('admin' | 'superadmin' | 'cashier'); scope selects the mode it appears in. */
  function notify(to, scope, title, body, link) {
    const n = { id: U.uid('n'), to, scope, title, body, link: link || '', at: new Date().toISOString(), readBy: [] };
    insert('notifications', n);
    return n;
  }
  function notificationsFor(sessionUser, mode) {
    if (!sessionUser) return [];
    const role = Auth.activeRole(sessionUser, mode);
    return all('notifications').filter(n => {
      if (n.to === sessionUser.id) return n.scope === role;
      return n.to === role || (role === 'superadmin' && n.to === 'admin'); // superadmin also sees admin alerts
    }).sort((a, b) => b.at.localeCompare(a.at));
  }
  function markRead(ids, uid) { ids.forEach(id => { const n = get('notifications', id); if (n && !n.readBy.includes(uid)) n.readBy.push(uid); }); save(); }

  /* ---------- audit trail ---------- */
  function audit(action, details, actor) {
    const s = g.Auth ? Auth.session() : null;
    const a = actor || (s && s.user);
    insert('audit', { id: 'AUD-' + Date.now().toString(36).toUpperCase(), at: new Date().toISOString(), actorId: a ? a.id : 'system', role: a ? Auth.activeRole(a, s && s.mode) : 'system', action, details });
  }

  g.Store = { load, save, reset, transaction, all, get, where, insert, update, remove, nextSeq, user, supplier, product, pkg, category, request, settings, supplierByOwner, notify, notificationsFor, markRead, audit, isMemoryOnly: () => memoryOnly };
})(window);
