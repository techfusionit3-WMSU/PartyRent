/* =========================================================
   PartyRent — customer discovery pages
   Homepage, browse/search/categories, AI search & planner,
   suppliers, supplier shop, product details, packages.
   ========================================================= */
(function () {
  'use strict';
  const { esc, url, $, $$ } = U;
  const I = Icons.icon;
  const C = Pages.customer = Pages.customer || {};

  const live = () => Store.where('products', p => p.status === 'published' && (Store.supplier(p.supplierId) || {}).status === 'approved');
  const livePkgs = () => Store.where('packages', k => Services.isListed('package', k) && (Store.supplier(k.supplierId) || {}).status === 'approved');
  const liveSups = () => Store.where('suppliers', s => s.status === 'approved');
  const EVENT_THEMES = [['Birthday', '🎂', 'linear-gradient(135deg,#F472B6,#E11D74)'], ['Wedding', '💍', 'linear-gradient(135deg,#FBBF24,#B45309)'], ['Debut', '👑', 'linear-gradient(135deg,#C084FC,#7C3AED)'], ['Christening', '🕊️', 'linear-gradient(135deg,#67E8F9,#0E7490)'], ['Corporate', '🏢', 'linear-gradient(135deg,#60A5FA,#1D4ED8)'], ['Reunion', '👨‍👩‍👧‍👦', 'linear-gradient(135deg,#34D399,#0D9488)']];

  function requireLogin(s, msg) { if (s) return true; UI.toast(msg || 'Please log in first.', 'warn'); setTimeout(() => U.go('login.html', { next: location.pathname.split('/').slice(-2).join('/') + location.search }), 500); return false; }
  C.requireLogin = requireLogin;

  /* ================= HOME ================= */
  C.home = function (ctx) {
    const s = ctx.session;
    const prods = live();
    const featured = prods.slice().sort((a, b) => b.rating - a.rating || b.rentCount - a.rentCount).slice(0, 8);
    let recommended;
    if (s) {
      const past = Store.where('requests', r => r.customerId === s.user.id);
      const evs = new Set(past.map(r => r.eventType));
      recommended = prods.filter(p => (p.events || []).some(e => evs.has(e))).sort((a, b) => b.rentCount - a.rentCount).slice(0, 4);
    }
    if (!recommended || !recommended.length) recommended = prods.slice().sort((a, b) => b.rentCount - a.rentCount).slice(0, 4);
    const recent = prods.slice().sort((a, b) => b.createdAt.localeCompare(a.createdAt)).slice(0, 4);
    const offers = prods.filter(p => p.tiers.length > 1).sort((a, b) => (1 - Pricing.lowestPrice(b) / Pricing.basePrice(b)) - (1 - Pricing.lowestPrice(a) / Pricing.basePrice(a))).slice(0, 4);
    const cats = Store.where('categories', c => c.active);
    const notifs = s ? Store.notificationsFor(s.user, s.mode).filter(n => !n.readBy.includes(s.user.id)).slice(0, 3) : [];
    const active = s ? Store.where('requests', r => r.customerId === s.user.id && Finance.ACTIVE.concat(['pending']).includes(r.status)).sort((a, b) => a.start.localeCompare(b.start)) : [];

    ctx.el.innerHTML =
      '<section class="hero"><svg class="confetti" viewBox="0 0 400 400" preserveAspectRatio="none" style="width:100%;height:100%">' + Icons.confetti() + '</svg>' +
      '<div><span class="badge plain" style="background:rgba(255,255,255,.2);color:#fff">' + I('sparkle').replace('<svg', '<svg style="width:14px"') + ' AI-assisted party rentals</span><h1 class="mt-1">' + (s ? 'Hi ' + esc(s.user.name.split(' ')[0]) + ', what are we celebrating?' : 'Rent everything your party needs.') + '</h1><p>Chairs, tables, tents, sound, lights and décor from trusted local suppliers — with real-time availability for every date.</p>' +
      '<div class="hero-ai mt-2"><label for="heroAi" class="small strong" style="display:block;margin-bottom:6px">Describe your event — English or Filipino</label><textarea id="heroAi" placeholder="e.g. Outdoor birthday party for 50 guests, need chairs, tables, a tent, pink decorations and a sound system on ' + esc(U.fmtDate(U.addDays(U.today(), 13), { year: undefined })) + '"></textarea><div class="row wrap mt-1"><button class="btn btn-lg" style="background:#fff;color:var(--brand);border-color:#fff" id="heroGo">' + I('sparkle') + 'Plan with AI</button><a class="btn btn-lg" style="background:transparent;color:#fff;border-color:rgba(255,255,255,.6)" href="' + url('customer/browse.html') + '">Browse all items</a></div></div>' +
      '<div class="hero-stats"><div><b>' + liveSups().length + '</b><span>verified suppliers</span></div><div><b>' + prods.length + '</b><span>rental items</span></div><div><b>' + livePkgs().length + '</b><span>party packages</span></div></div></div>' +
      '<div class="hero-art">🎉<div style="font-size:3.4rem;margin-top:-10px">🎈🪑🎪</div></div></section>' +
      (active.length || notifs.length ? '<section class="section grid grid-2">' +
        (active.length ? '<div class="card"><div class="card-head"><h3>Your upcoming rentals</h3><a class="small strong" href="' + url('customer/rentals.html') + '">View all</a></div>' + active.slice(0, 3).map(r => { const f = Finance.summary(r); return '<a href="' + url('customer/rental.html', { id: r.id }) + '" class="row between" style="padding:10px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none"><div><div class="strong">' + esc(r.eventName) + '</div><div class="small muted">' + r.id + ' · ' + U.fmtRange(r.start, r.end) + '</div></div><div class="stack-sm" style="align-items:flex-end">' + UI.statusBadge(r.status) + UI.payBadge(f.status) + '</div></a>'; }).join('') + '</div>' : '') +
        (notifs.length ? '<div class="card"><div class="card-head"><h3>New for you</h3><a class="small strong" href="' + url('notifications.html') + '">All notifications</a></div>' + notifs.map(n => UI.notifItem(n, s.user.id)).join('') + '</div>' : '') + '</section>' : '') +
      '<section class="section"><div class="section-title"><h2>Shop by category</h2><a class="small strong" href="' + url('customer/browse.html') + '">See all</a></div><div class="cat-grid">' + cats.map(c => '<a class="cat-card" href="' + url('customer/browse.html', { category: c.id }) + '"><div class="ce" style="background:linear-gradient(135deg,' + c.grad[0] + ',' + c.grad[1] + ')">' + Icons.art(c.icon).replace('width:48%;height:48%', 'width:62%;height:62%') + '</div><div class="cn">' + esc(c.name) + '</div><div class="cc">' + prods.filter(p => p.categoryId === c.id).length + ' items</div></a>').join('') + '</div></section>' +
      '<section class="section"><div class="section-title"><h2>Shop by event</h2></div><div class="grid grid-3">' + EVENT_THEMES.map(([e, em, bg]) => '<a class="theme-card" style="background:' + bg + '" href="' + url('customer/browse.html', { event: e }) + '"><span class="te">' + em + '</span><b>' + esc(e) + '</b><span>' + prods.filter(p => (p.events || []).includes(e)).length + ' items · ' + livePkgs().filter(k => (k.events || []).includes(e)).length + ' packages</span></a>').join('') + '</div></section>' +
      section('Featured rentals', featured.map(p => UI.productCard(p)).join(''), url('customer/browse.html', { sort: 'rating' })) +
      section(s ? 'Recommended for you' : 'Most rented this season', recommended.map(p => UI.productCard(p)).join(''), url('customer/browse.html', { sort: 'popular' })) +
      section('Popular rental packages', livePkgs().slice(0, 4).map(k => UI.packageCard(k)).join(''), url('customer/packages.html')) +
      '<section class="section"><div class="section-title"><h2>Featured suppliers</h2><a class="small strong" href="' + url('customer/suppliers.html') + '">All suppliers</a></div><div class="grid grid-3">' + liveSups().sort((a, b) => b.rating - a.rating).slice(0, 3).map(UI.supplierCard).join('') + '</div></section>' +
      section('Special bulk offers 🎁', offers.map(p => UI.productCard(p)).join(''), url('customer/browse.html')) +
      section('Recently added', recent.map(p => UI.productCard(p)).join(''), url('customer/browse.html', { sort: 'recent' })) +
      (!s || !Auth.supplierOf(s.user) ? '<section class="section card soft row wrap between" style="padding:28px"><div><h2 class="mb-0">Have party supplies at home or a rental business?</h2><p class="muted mb-0">Start selling on PartyRent using your same account — no new sign-up needed.</p></div><a class="btn btn-primary btn-lg" href="' + url('customer/start-selling.html') + '">' + I('store') + 'Start Selling</a></section>' : '');
    $('#heroGo').onclick = () => { const q = $('#heroAi').value.trim(); U.go('customer/ai.html', q ? { q } : null); };
  };
  function section(title, inner, more) { return '<section class="section"><div class="section-title"><h2>' + title + '</h2>' + (more ? '<a class="small strong" href="' + more + '">See more</a>' : '') + '</div><div class="product-grid">' + inner + '</div></section>'; }

  /* ================= BROWSE / SEARCH / CATEGORY ================= */
  C.browse = function (ctx) {
    const p = ctx.params;
    const st = {
      q: p.q || '', category: p.category || '', supplier: p.supplier || '', city: p.city || '', event: p.event || '', color: p.color || '', theme: p.theme || '',
      min: p.min || '', max: p.max || '', start: '', end: '', qty: p.qty || '', onlyAvail: p.avail === '1', delivery: p.delivery === '1', pickup: p.pickup === '1', rating: p.rating || '', sort: p.sort || 'recommended', view: p.view || 'items'
    };
    // rental dates: from the link if given, otherwise the customer's selected rental period
    const urlOk = p.start && !RentalPeriod.validate(p.start, p.end && p.end >= p.start ? p.end : p.start);
    if (urlOk) { st.start = p.start; st.end = p.end && p.end >= p.start ? p.end : p.start; RentalPeriod.set(st.start, st.end); }
    else { const rp = RentalPeriod.get(); if (rp) { st.start = rp.start; st.end = rp.end; } }
    const compare = new Set();
    const cats = Store.where('categories', c => c.active);
    const sups = liveSups();
    const allColors = Array.from(new Set(live().flatMap(x => x.colors || []))).sort();
    const allThemes = Array.from(new Set(live().flatMap(x => x.themes || []))).sort();
    const events = Object.keys(AI.EVENTS);
    const opt = (v, l, cur) => '<option value="' + esc(v) + '"' + (v === cur ? ' selected' : '') + '>' + esc(l) + '</option>';

    ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('index.html') + '">Home</a> / <span id="crumbCat">Browse</span></div><div class="page-head"><div><h1 id="title">Browse party supplies</h1><p id="subtitle"></p></div><div class="row wrap"><div class="seg" id="viewSeg"><button data-v="items">Items</button><button data-v="packages">Packages</button></div><button class="btn btn-sm" id="filterBtn" aria-haspopup="dialog">' + I('filter') + 'Filters<span id="filterCount"></span></button><select class="input input-sm" id="sort" style="width:auto" aria-label="Sort">' + [['recommended', 'Recommended'], ['price_asc', 'Lowest price'], ['price_desc', 'Highest price'], ['popular', 'Most popular'], ['recent', 'Recently added'], ['rating', 'Highest rated']].map(([v, l]) => opt(v, l, st.sort)).join('') + '</select></div></div>' +
      '<section class="card date-bar mb-2" id="dateBar" aria-label="Rental dates"><span class="db-label">' + I('calendar') + 'Rental dates</span><div class="db-inputs"><input class="input input-sm" type="date" id="bs" min="' + U.today() + '" value="' + st.start + '" aria-label="Start date"><span class="muted">–</span><input class="input input-sm" type="date" id="be" min="' + U.today() + '" value="' + st.end + '" aria-label="End date"></div><div class="db-summary" id="dateSum" aria-live="polite"></div><button type="button" class="btn btn-sm btn-ghost" id="dateClear">' + I('x') + 'Clear dates</button></section>' +
      '<div id="layout"><div id="chips" class="row wrap mb-2" style="gap:6px"></div><div id="aiNote"></div><div id="results"></div></div><div id="compareBar"></div>';
    const filtersHtml = () => '<form id="ff">' +
      '<div class="field"><label>Keyword</label><input class="input input-sm" name="q" value="' + esc(st.q) + '" placeholder="e.g. tiffany, tent"></div>' +
      '<div class="field"><label>Quantity needed per day</label><input class="input input-sm" type="number" min="1" name="qty" value="' + esc(st.qty) + '" placeholder="Any"></div>' +
      '<label class="check mb-2"><input type="checkbox" name="onlyAvail"' + (st.onlyAvail && st.start ? ' checked' : '') + (st.start ? '' : ' disabled') + '> Available on selected dates</label>' + '<div class="hint mb-2' + (st.start ? ' hidden' : '') + '" style="margin-top:-8px">Select rental dates above the products to filter and see available stock.</div>' +
      '<div class="field"><label>Category</label><select class="input input-sm" name="category">' + opt('', 'All categories', st.category) + cats.map(c => opt(c.id, c.name, st.category)).join('') + '</select></div>' +
      '<div class="field"><label>Event type</label><select class="input input-sm" name="event">' + opt('', 'Any event', st.event) + events.map(e => opt(e, e, st.event)).join('') + '</select></div>' +
      '<div class="field"><label>Color</label><select class="input input-sm" name="color">' + opt('', 'Any color', st.color) + allColors.map(c => opt(c, c, st.color)).join('') + '</select></div>' +
      '<div class="field"><label>Theme</label><select class="input input-sm" name="theme">' + opt('', 'Any theme', st.theme) + allThemes.map(c => opt(c, c, st.theme)).join('') + '</select></div>' +
      '<div class="field"><label>Daily price (₱)</label><div class="range-fields"><input class="input input-sm" type="number" min="0" name="min" placeholder="Min" value="' + esc(st.min) + '" aria-label="Minimum price"><input class="input input-sm" type="number" min="0" name="max" placeholder="Max" value="' + esc(st.max) + '" aria-label="Maximum price"></div></div>' +
      '<div class="field"><label>Supplier</label><select class="input input-sm" name="supplier">' + opt('', 'All suppliers', st.supplier) + sups.map(x => opt(x.id, x.name, st.supplier)).join('') + '</select></div>' +
      '<div class="field"><label>Supplier location</label><select class="input input-sm" name="city">' + opt('', 'Anywhere', st.city) + Array.from(new Set(sups.map(x => x.city))).map(c => opt(c, c, st.city)).join('') + '</select></div>' +
      '<div class="field"><label>Fulfillment</label><label class="check"><input type="checkbox" name="delivery"' + (st.delivery ? ' checked' : '') + '> Delivery available</label><label class="check"><input type="checkbox" name="pickup"' + (st.pickup ? ' checked' : '') + '> Pickup available</label></div>' +
      '<div class="field"><label>Supplier rating</label><select class="input input-sm" name="rating">' + opt('', 'Any rating', st.rating) + opt('4.5', '4.5★ & up', st.rating) + opt('4.7', '4.7★ & up', st.rating) + '</select></div></form>';

    // Filters live in a side panel that is closed by default (all screen sizes).
    let panel = null;
    function bindFilters(root) {
      const f = $('#ff', root);
      f.addEventListener('change', () => { read(f); apply(); });
      f.q.addEventListener('keyup', e => { if (e.key === 'Enter') { read(f); apply(); } });
    }
    function read(f) { const d = UI.formData(f); Object.assign(st, d); if (!st.start) st.onlyAvail = false; const cb = f.onlyAvail; if (cb) { cb.disabled = !st.start; if (!st.start) cb.checked = false; const h = cb.closest('label').nextElementSibling; if (h && h.classList.contains('hint')) h.classList.toggle('hidden', !!st.start); } }
    function openFilters() {
      if (panel) return;
      panel = UI.drawer({ title: 'Filters', body: filtersHtml(), foot: '<button type="button" class="btn" id="clearF">Clear all</button><button type="button" class="btn btn-primary" data-close>Show results</button>', onOpen: el => bindFilters(el), onClose: () => { panel = null; } });
      $('#clearF', panel.el).onclick = () => { Object.assign(st, { q: '', category: '', supplier: '', city: '', event: '', color: '', theme: '', min: '', max: '', qty: '', onlyAvail: false, delivery: false, pickup: false, rating: '' }); $('.drawer-body', panel.el).innerHTML = filtersHtml(); bindFilters(panel.el); apply(); };
    }
    $('#filterBtn').onclick = openFilters;
    // rental date bar (separate from the filters)
    function setDates(a, b) {
      if (!a && !b) { st.start = ''; st.end = ''; RentalPeriod.clear(); }
      else {
        if (!a) a = b; if (!b || b < a) b = a;
        const err = RentalPeriod.validate(a, b);
        if (err) { UI.toast(err, 'error'); $('#bs').value = st.start; $('#be').value = st.end; return; }
        st.start = a; st.end = b; RentalPeriod.set(a, b);
      }
      if (!st.start) st.onlyAvail = false;
      $('#bs').value = st.start; $('#be').value = st.end;
      apply();
    }
    $('#bs').onchange = () => setDates($('#bs').value, $('#be').value && $('#be').value >= $('#bs').value ? $('#be').value : $('#bs').value);
    $('#be').onchange = () => setDates($('#bs').value || $('#be').value, $('#be').value);
    $('#dateClear').onclick = () => setDates('', '');
    function drawDateBar() {
      const n = st.start ? U.diffDays(st.start, st.end) + 1 : 0;
      $('#be').min = st.start || U.today();
      $('#dateSum').innerHTML = st.start ? '<span>' + esc(RentalPeriod.rangeLabel(st.start, st.end)) + '</span><span class="db-sep">|</span><span class="badge b-purple plain">' + esc(RentalPeriod.durationLabel(n)) + '</span>' : '<span class="small muted" style="font-weight:500">Select dates to see available stock for your rental period</span>';
      $('#dateClear').classList.toggle('hidden', !st.start);
      const active = ['q', 'category', 'supplier', 'city', 'event', 'color', 'theme', 'min', 'max', 'qty', 'onlyAvail', 'delivery', 'pickup', 'rating'].filter(k => st[k]).length;
      $('#filterCount').innerHTML = active ? ' <span class="filter-count">' + active + '</span>' : '';
    }
    $('#sort').onchange = e => { st.sort = e.target.value; apply(); };
    $$('#viewSeg button').forEach(b => b.onclick = () => { st.view = b.dataset.v; apply(); });

    function apply() {
      drawDateBar();
      $$('#viewSeg button').forEach(b => b.classList.toggle('active', b.dataset.v === st.view));
      const dates = st.start ? U.range(st.start, st.end || st.start) : [];
      const need = Number(st.qty) || 1;
      // keep URL shareable
      try { const q = Object.entries(st).filter(([k, v]) => v && v !== 'recommended' && !(k === 'view' && v === 'items')).map(([k, v]) => k + '=' + encodeURIComponent(v === true ? '1' : v)).join('&').replace('onlyAvail=', 'avail='); history.replaceState(null, '', location.pathname + (q ? '?' + q : '')); } catch (e) { /* ignore */ }
      const ai = st.q ? AI.interpret(st.q) : null;
      const words = st.q.toLowerCase().split(/\s+/).filter(w => w.length > 1);
      const matchText = (hay) => !words.length || words.every(w => hay.includes(w)) || (ai && ai.items.length && words.some(w => hay.includes(w)));
      let html = '', count = 0;
      if (st.view === 'packages') {
        let list = livePkgs().filter(k => {
          const sup = Store.supplier(k.supplierId);
          const hay = (k.name + ' ' + k.description + ' ' + sup.name + ' ' + (k.events || []).join(' ')).toLowerCase();
          if (!matchText(hay)) return false;
          if (st.event && !(k.events || []).includes(st.event)) return false;
          if (st.supplier && k.supplierId !== st.supplier) return false;
          if (st.city && sup.city !== st.city) return false;
          if (st.min && k.price < Number(st.min)) return false;
          if (st.max && k.price > Number(st.max)) return false;
          if (st.delivery && sup.fulfillment === 'pickup') return false;
          if (st.pickup && sup.fulfillment === 'delivery') return false;
          if (st.rating && sup.rating < Number(st.rating)) return false;
          if (st.category && !k.items.some(it => (Store.product(it.productId) || {}).categoryId === st.category)) return false;
          if (st.onlyAvail && dates.length && dates.some(d => Inventory.packageAvailableOn(k.id, d) < need)) return false;
          return true;
        });
        list.sort((a, b) => st.sort === 'price_asc' ? a.price - b.price : st.sort === 'price_desc' ? b.price - a.price : st.sort === 'recent' ? b.createdAt.localeCompare(a.createdAt) : Store.supplier(b.supplierId).rating - Store.supplier(a.supplierId).rating);
        count = list.length;
        html = list.length ? '<div class="product-grid">' + list.map(k => UI.packageCard(k, { dates })).join('') + '</div>' : UI.empty('📦', 'No packages match', 'Try clearing some filters.');
      } else {
        let list = live().filter(x => {
          const sup = Store.supplier(x.supplierId);
          const hay = (x.name + ' ' + x.description + ' ' + sup.name + ' ' + sup.city + ' ' + (Store.category(x.categoryId) || {}).name + ' ' + (x.colors || []).join(' ') + ' ' + (x.themes || []).join(' ') + ' ' + (x.events || []).join(' ')).toLowerCase();
          if (!matchText(hay)) { if (!(ai && ai.items.includes(x.categoryId))) return false; }
          if (st.category && x.categoryId !== st.category) return false;
          if (st.supplier && x.supplierId !== st.supplier) return false;
          if (st.city && sup.city !== st.city) return false;
          if (st.event && !(x.events || []).includes(st.event)) return false;
          if (st.color && !(x.colors || []).includes(st.color)) return false;
          if (st.theme && !(x.themes || []).includes(st.theme)) return false;
          const price = Pricing.basePrice(x);
          if (st.min && price < Number(st.min)) return false;
          if (st.max && price > Number(st.max)) return false;
          if (st.delivery && (sup.fulfillment === 'pickup' || x.delivery === false)) return false;
          if (st.pickup && sup.fulfillment === 'delivery') return false;
          if (st.rating && sup.rating < Number(st.rating)) return false;
          if (st.onlyAvail && dates.length && Inventory.minAvailable(x.id, dates) < need) return false;
          return true;
        });
        const score = x => (x.rating || 0) * 2 + Math.log10(1 + x.rentCount) + (ai ? (ai.colors.some(c => (x.colors || []).includes(c)) ? 2 : 0) : 0) + (dates.length ? (Inventory.minAvailable(x.id, dates) >= need ? 2 : -3) : 0);
        const sorters = { price_asc: (a, b) => Pricing.basePrice(a) - Pricing.basePrice(b), price_desc: (a, b) => Pricing.basePrice(b) - Pricing.basePrice(a), popular: (a, b) => b.rentCount - a.rentCount, recent: (a, b) => b.createdAt.localeCompare(a.createdAt), rating: (a, b) => b.rating - a.rating || b.rentCount - a.rentCount, recommended: (a, b) => score(b) - score(a) };
        list.sort(sorters[st.sort] || sorters.recommended);
        count = list.length;
        html = list.length ? '<div class="product-grid">' + list.map(x => UI.productCard(x, { dates, compare: true, compareOn: compare.has(x.id) })).join('') + '</div>' : UI.empty('🔍', 'No items match your filters', 'Try different dates, a wider price range, or ask the AI planner for alternatives.', '<a class="btn btn-primary" href="' + url('customer/ai.html', st.q ? { q: st.q } : null) + '">' + I('sparkle') + 'Ask AI for suggestions</a>');
      }
      const cat = Store.category(st.category);
      $('#title').textContent = st.q ? 'Results for “' + st.q + '”' : cat ? cat.name : st.event ? st.event + ' supplies' : st.view === 'packages' ? 'Rental packages' : 'Browse party supplies';
      $('#crumbCat').textContent = cat ? cat.name : st.q ? 'Search' : 'Browse';
      $('#subtitle').textContent = count + ' ' + (st.view === 'packages' ? 'packages' : 'items') + (dates.length ? ' · availability for ' + U.fmtRange(dates[0], dates[dates.length - 1]) + (st.qty ? ' (need ' + st.qty + '/day)' : '') : ' · select rental dates to see available stock');
      $('#results').innerHTML = html;
      // active chips
      const labels = { q: 'Keyword', category: 'Category', supplier: 'Supplier', city: 'Location', event: 'Event', color: 'Color', theme: 'Theme', min: 'Min ₱', max: 'Max ₱', qty: 'Qty', onlyAvail: 'Available only', delivery: 'Delivery', pickup: 'Pickup', rating: 'Rating ≥' };
      $('#chips').innerHTML = Object.keys(labels).filter(k => st[k]).map(k => { let v = st[k]; if (k === 'category') v = (Store.category(v) || {}).name; if (k === 'supplier') v = (Store.supplier(v) || {}).name; if (k === 'start' || k === 'end') v = U.fmtDate(v); return '<button class="chip" data-clear="' + k + '">' + esc(labels[k]) + (v === true ? '' : ': ' + esc(v)) + ' <span class="x">×</span></button>'; }).join('');
      $$('#chips [data-clear]').forEach(b => b.onclick = () => { st[b.dataset.clear] = typeof st[b.dataset.clear] === 'boolean' ? false : ''; apply(); });
      // AI hint
      $('#aiNote').innerHTML = ai && (ai.guests || ai.eventType || ai.start || ai.items.length > 1) ? '<div class="alert alert-ai mb-2">' + I('sparkle') + '<div>Looks like you\'re describing an event. <a href="' + url('customer/ai.html', { q: st.q }) + '" class="strong">Let the AI planner build a full checklist →</a></div></div>' : '';
      $$('[data-compare]').forEach(cb => cb.onchange = () => { if (cb.checked) { if (compare.size >= 3) { cb.checked = false; return UI.toast('You can compare up to 3 items.', 'warn'); } compare.add(cb.dataset.compare); } else compare.delete(cb.dataset.compare); compareBar(dates); });
      compareBar(dates);
    }
    function compareBar(dates) {
      const el = $('#compareBar');
      if (!compare.size) { el.innerHTML = ''; return; }
      el.innerHTML = '<div style="position:fixed;left:50%;transform:translateX(-50%);bottom:calc(var(--bottomnav-h) + 10px);z-index:880;background:var(--ink);color:#fff;border-radius:999px;padding:8px 8px 8px 18px;display:flex;gap:12px;align-items:center;box-shadow:var(--shadow-lg)"><span class="small strong">' + compare.size + ' selected</span><button class="btn btn-sm btn-primary" id="doCompare">Compare</button><button class="btn btn-sm btn-ghost" style="color:#fff" id="clearCompare">Clear</button></div>';
      $('#clearCompare').onclick = () => { compare.clear(); apply(); };
      $('#doCompare').onclick = () => {
        const items = Array.from(compare).map(id => Store.product(id));
        const rows = [['Supplier', p => esc(Store.supplier(p.supplierId).name)], ['Location', p => esc(Store.supplier(p.supplierId).city)], ['Daily price', p => U.money(Pricing.basePrice(p)) + (p.tiers.length > 1 ? '<div class="small muted">from ' + U.money(Pricing.lowestPrice(p)) + ' in bulk</div>' : '')], ['Available' + (dates.length ? ' ' + U.fmtRange(dates[0], dates[dates.length - 1]) : ''), p => dates.length ? '<b>' + Inventory.minAvailable(p.id, dates) + '</b> for your dates' : '<span class="muted">Select rental dates</span>'], ['Rental limit', p => esc(UI.maxRentalLabel(p))], ['Rating', p => '★ ' + p.rating + ' · ' + p.rentCount + ' rentals'], ['Fulfillment', p => UI.fulfillLabel(Store.supplier(p.supplierId).fulfillment)], ['Payment plans', p => Finance.plansOf(Store.supplier(p.supplierId)).map(a => Finance.PLAN_LABEL[a]).join(', ')], ['Colors', p => (p.colors || []).map(UI.swatch).join(' ') + ' ' + esc((p.colors || []).join(', '))], ['', p => '<a class="btn btn-sm btn-primary" href="' + url('customer/product.html', { id: p.id, start: dates[0], end: dates[dates.length - 1] }) + '">View</a>']];
        UI.modal({ title: 'Compare items', size: 'lg', body: '<div class="table-wrap"><table class="table"><thead><tr><th></th>' + items.map(p => '<th style="text-transform:none;font-size:.86rem;color:var(--ink)">' + esc(p.name) + '</th>').join('') + '</tr></thead><tbody>' + rows.map(([l, fn]) => '<tr><td class="muted strong">' + l + '</td>' + items.map(p => '<td>' + fn(p) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>' });
      };
    }
    apply();
  };

  /* ================= AI SEARCH & EVENT PLANNER ================= */
  C.ai = function (ctx) {
    const s = ctx.session;
    const examples = ['I am planning an outdoor birthday party for 50 guests. I need chairs, tables, a tent, pink decorations, and a sound system for ' + U.fmtDate(U.addDays(U.today(), 13), { year: undefined }) + ' to ' + U.fmtDate(U.addDays(U.today(), 15), { year: undefined }) + '.', 'Help me prepare for a wedding reception for 100 guests with an elegant white and gold theme on ' + U.fmtDate(U.addDays(U.today(), 30), { year: undefined }) + '.', 'Kailangan namin ng upuan at mesa para sa 80 bisita, binyag sa ' + U.MONTHS[U.parse(U.addDays(U.today(), 20)).getMonth()] + ' ' + U.parse(U.addDays(U.today(), 20)).getDate() + ', may delivery sa Quezon City.'];
    ctx.el.innerHTML = '<div class="ai-hero"><div class="row between wrap"><div><span class="ai-badge">' + I('sparkle') + 'PartyRent Assist</span><h1 class="mt-1 mb-0">AI Event Planner & Search</h1><p class="muted mt-1">Describe your event in English or Filipino. The assistant turns it into filters you can edit, then suggests items that are <b>actually listed and available</b> on PartyRent.</p></div></div>' +
      '<div class="field mt-2"><label for="aiq" class="sr-only">Describe your event</label><textarea class="input" id="aiq" rows="3" placeholder="e.g. Debut for 120 guests, pink and white, need Tiffany chairs, backdrop and lights…">' + esc(ctx.params.q || '') + '</textarea></div>' +
      '<div class="row wrap"><button class="btn btn-primary btn-lg" id="interpret">' + I('sparkle') + 'Interpret my event</button><span class="small muted">Try:</span>' + examples.map((e, i) => '<button class="chip" data-ex="' + i + '">' + ['Outdoor birthday', 'White & gold wedding', 'Binyag (Filipino)'][i] + '</button>').join('') + '</div>' +
      '<p class="demo-note mt-2">' + I('info').replace('<svg', '<svg style="width:14px"') + esc(AI.label) + '. It never books, prices or reserves anything — you stay in control.</p></div><div id="aiOut" class="mt-3"></div>';
    $$('[data-ex]').forEach(b => b.onclick = () => { $('#aiq').value = examples[+b.dataset.ex]; run(); });
    $('#interpret').onclick = run;
    let filters = null;
    function run() {
      const q = $('#aiq').value.trim();
      if (!q) return UI.toast('Describe your event first.', 'warn');
      $('#aiOut').innerHTML = '<div class="card"><div class="row"><div class="spinner"></div><span class="muted">Reading your description and checking real listings…</span></div><div class="skeleton mt-2" style="height:120px"></div></div>';
      setTimeout(() => { filters = AI.interpret(q); renderFilters(); }, 450);
    }
    function renderFilters() {
      const f = filters;
      const catOpts = Object.keys(AI.ITEMS);
      $('#aiOut').innerHTML = '<div class="card"><div class="card-head"><h2>1 · Review what I understood</h2><span class="badge b-purple plain">' + esc(f.language) + ' input</span></div><p class="small muted">Edit anything that\'s wrong — your edits are what I\'ll use.</p>' +
        '<form id="ef" class="form-grid"><div class="field"><label>Event type</label><select class="input" name="eventType"><option value="">Not specified</option>' + Object.keys(AI.EVENTS).map(e => '<option' + (e === f.eventType ? ' selected' : '') + '>' + e + '</option>').join('') + '</select></div>' +
        '<div class="field"><label>Number of guests</label><input class="input" type="number" min="1" max="2000" name="guests" value="' + (f.guests || '') + '" placeholder="e.g. 50"></div>' +
        '<div class="field"><label>Start date</label><input class="input" type="date" name="start" min="' + U.today() + '" value="' + (f.start || '') + '"></div><div class="field"><label>End date</label><input class="input" type="date" name="end" min="' + U.today() + '" value="' + (f.end || '') + '"></div>' +
        '<div class="field"><label>Location type</label><select class="input" name="locationType"><option value="">Not specified</option><option' + (f.locationType === 'Outdoor' ? ' selected' : '') + '>Outdoor</option><option' + (f.locationType === 'Indoor' ? ' selected' : '') + '>Indoor</option></select></div>' +
        '<div class="field"><label>Preferred supplier city</label><select class="input" name="city"><option value="">Anywhere</option>' + Array.from(new Set(liveSups().map(x => x.city))).map(c => '<option' + (c === f.city ? ' selected' : '') + '>' + c + '</option>').join('') + '</select></div>' +
        '<div class="field"><label>Budget (optional)</label><input class="input" type="number" min="0" name="budget" value="' + (f.budget || '') + '" placeholder="₱"></div>' +
        '<div class="field"><label>Fulfillment</label><select class="input" name="delivery"><option value="">Either</option><option value="1"' + (f.delivery === true ? ' selected' : '') + '>Delivery</option><option value="0"' + (f.delivery === false ? ' selected' : '') + '>Pickup</option></select></div>' +
        '<div class="field full"><label>Colors</label><div class="row wrap gap-sm">' + Object.keys(AI.COLORS).map(c => '<label class="chip ' + (f.colors.includes(c) ? 'active' : '') + '"><input type="checkbox" name="colors" data-multi value="' + c + '"' + (f.colors.includes(c) ? ' checked' : '') + ' hidden>' + UI.swatch(c) + ' ' + c + '</label>').join('') + '</div></div>' +
        '<div class="field full"><label>Theme</label><div class="row wrap gap-sm">' + Object.keys(AI.THEMES).map(c => '<label class="chip ' + (f.themes.includes(c) ? 'active' : '') + '"><input type="checkbox" name="themes" data-multi value="' + c + '"' + (f.themes.includes(c) ? ' checked' : '') + ' hidden>' + c + '</label>').join('') + '</div></div>' +
        '<div class="field full"><label>Items needed <span class="muted">(leave empty to let me suggest a checklist)</span></label><div class="row wrap gap-sm">' + catOpts.map(c => '<label class="chip ' + (f.items.includes(c) ? 'active' : '') + '"><input type="checkbox" name="items" data-multi value="' + c + '"' + (f.items.includes(c) ? ' checked' : '') + ' hidden>' + esc((Store.category(c) || {}).name || c) + '</label>').join('') + '</div></div>' +
        '<div class="full row wrap"><button type="button" class="btn btn-primary" id="doPlan">' + I('sparkle') + 'Suggest supplies</button><button type="button" class="btn" id="doSearch">' + I('search') + 'Just search listings with these filters</button></div></form></div><div id="planOut" class="mt-3"></div>';
      const ef = $('#ef');
      $$('label.chip input', ef).forEach(i => i.onchange = () => i.parentElement.classList.toggle('active', i.checked));
      const readF = () => { const d = UI.formData(ef); return Object.assign({}, f, { eventType: d.eventType || null, guests: Number(d.guests) || null, start: d.start || null, end: d.end || d.start || null, locationType: d.locationType || null, city: d.city || null, budget: Number(d.budget) || null, delivery: d.delivery === '' ? null : d.delivery === '1', colors: d.colors || [], themes: d.themes || [], items: d.items || [] }); };
      $('#doPlan').onclick = () => { const nf = readF(); if (nf.start && nf.end < nf.start) return UI.toast('End date must be after the start date.', 'error'); renderPlan(nf); };
      $('#doSearch').onclick = () => { const nf = readF(); U.go('customer/browse.html', { category: nf.items.length === 1 ? nf.items[0] : '', event: nf.eventType || '', color: nf.colors[0] || '', theme: nf.themes[0] || '', start: nf.start || '', end: nf.end || '', city: nf.city || '', delivery: nf.delivery ? '1' : '', avail: nf.start ? '1' : '' }); };
      if (f.eventType || f.items.length || f.guests) renderPlan(f);
    }
    function renderPlan(f) {
      const plan = AI.plan(f);
      const dates = plan.dates;
      const dateTxt = dates.length ? U.fmtRange(dates[0], dates[dates.length - 1]) + ' (' + dates.length + ' day' + (dates.length > 1 ? 's' : '') + ')' : 'no dates yet — availability shown for today';
      const rows = plan.rows.map((r, i) => {
        const cat = Store.category(r.category);
        if (!r.best) return '<div class="sugg-row"><div class="thumb sm">' + Icons.art(cat ? cat.icon : 'gift').replace('stroke="#fff"', 'stroke="#C2185F"') + '</div><div><b>' + esc(cat ? cat.name : r.category) + '</b><div class="small muted">No listings in this category yet.</div></div></div>';
        const b = r.best;
        return '<div class="sugg-row" data-row="' + i + '"><label class="check"><input type="checkbox" data-pick="' + i + '"' + (b.enough ? ' checked' : '') + ' aria-label="Select ' + esc(b.product.name) + '"></label>' +
          '<div class="row" style="gap:10px;min-width:0">' + UI.thumb(b.product, null, 'sm') + '<div style="min-width:0"><div class="small muted strong">' + esc(cat.name) + '</div><a class="strong" href="' + url('customer/product.html', { id: b.product.id, start: dates[0], end: dates[dates.length - 1] }) + '">' + esc(b.product.name) + '</a><div class="small muted">' + esc(b.reason) + '</div></div></div>' +
          '<div class="s-qty"><div class="small muted">Qty / day</div><input type="number" class="input input-sm" min="1" value="' + b.qty + '" data-q="' + i + '" style="width:90px" aria-label="Suggested quantity"></div>' +
          '<div class="s-sup small"><b>' + esc(b.supplier.name) + '</b> · ' + esc(b.supplier.city) + '<br>' + (!dates.length ? '<span class="badge b-gray">Set dates to check stock</span>' : b.enough ? '<span class="badge b-green">' + b.available + ' available</span>' : '<span class="badge b-red">Only ' + b.available + ' available</span>') + (r.alternatives.length ? ' <button class="btn btn-xs btn-ghost" data-alts="' + i + '">' + r.alternatives.length + ' other options</button>' : '') + '</div>' +
          '<div class="s-cost"><div class="small muted">Est. cost</div><b data-cost="' + i + '">' + U.money(b.cost) + '</b></div><div class="s-act small muted">' + U.money(Pricing.unitPrice(b.product, b.qty)) + '/' + esc(b.product.unit.split('/')[0].trim()) + '</div></div>';
      }).join('');
      $('#planOut').innerHTML = '<div class="card pad-0"><div class="card-head" style="padding:18px 18px 0"><div><h2>2 · Suggested checklist</h2><div class="small muted">' + esc(f.eventType || 'Event') + (f.guests ? ' · ' + f.guests + ' guests' : '') + ' · ' + dateTxt + '</div></div><div class="right"><div class="small muted">Estimated total (selected)</div><div class="price-lg" id="estTotal"></div></div></div>' +
        (plan.overBudget ? '<div class="alert alert-warn" style="margin:12px 18px 0">' + I('alert') + '<div>The suggested items exceed your budget of ' + U.money(f.budget) + '. Untick optional items or lower quantities.</div></div>' : '') +
        (!dates.length ? '<div class="alert alert-info" style="margin:12px 18px 0">' + I('info') + '<div>Add your event dates above to check real availability for those days.</div></div>' : '') +
        '<div class="mt-1">' + rows + '</div><div class="row wrap between" style="padding:16px 18px;border-top:1px solid var(--line)"><span class="small muted">Prices come from each supplier\'s listing. Estimates use the same quantity each day — you can change per-day quantities in your Rental List.</span><button class="btn btn-primary" id="addSel">' + I('cart') + 'Add selected to Rental List</button></div></div>' +
        (plan.packages.length ? '<div class="section"><div class="section-title"><h2>Or choose a ready-made package</h2></div><div class="product-grid">' + plan.packages.slice(0, 4).map(x => UI.packageCard(x.pkg, { dates })).join('') + '</div></div>' : '');
      const recalc = () => {
        let tot = 0;
        plan.rows.forEach((r, i) => { if (!r.best) return; const q = Number(($('[data-q="' + i + '"]') || {}).value) || 0; const c = U.round2(Pricing.unitPrice(r.best.product, q) * q * Math.max(1, dates.length)); const ce = $('[data-cost="' + i + '"]'); if (ce) ce.textContent = U.money(c); const pick = $('[data-pick="' + i + '"]'); if (pick && pick.checked) tot += c; });
        $('#estTotal').textContent = U.money(tot);
      };
      $$('[data-q],[data-pick]').forEach(x => x.addEventListener('input', recalc)); recalc();
      $$('[data-alts]').forEach(b => b.onclick = () => {
        const r = plan.rows[+b.dataset.alts];
        const m = UI.modal({ title: 'Other ' + Store.category(r.category).name.toLowerCase() + ' options', body: r.alternatives.map((a, j) => '<div class="row" style="padding:10px 0;border-bottom:1px solid var(--line)">' + UI.thumb(a.product, null, 'sm') + '<div class="grow"><b>' + esc(a.product.name) + '</b><div class="small muted">' + esc(a.supplier.name) + ' · ' + U.money(Pricing.unitPrice(a.product, a.qty)) + '/' + esc(a.product.unit.split('/')[0].trim()) + (dates.length ? ' · ' + (a.enough ? a.available + ' available' : 'only ' + a.available + ' available') : '') + '</div></div><button class="btn btn-sm" data-use="' + j + '"' + (a.enough || !dates.length ? '' : ' disabled') + '>Use this</button></div>').join('') });
        m.el.querySelectorAll('[data-use]').forEach(u => u.onclick = () => { const a = r.alternatives[+u.dataset.use]; r.alternatives = r.alternatives.filter(x => x !== a).concat([r.best]); r.best = a; m.close(); const keep = filters; renderPlanKeep(plan, f); filters = keep; });
      });
      $('#addSel').onclick = () => {
        if (!requireLogin(s, 'Log in to save items to your Rental List.')) return;
        if (!dates.length) return UI.toast('Please set your event dates first so availability can be checked.', 'warn');
        let added = 0, skipped = [], limited = [];
        plan.rows.forEach((r, i) => {
          const pick = $('[data-pick="' + i + '"]'); if (!r.best || !pick || !pick.checked) return;
          const q = Math.max(1, Number($('[data-q="' + i + '"]').value) || 1);
          if (Inventory.minAvailable(r.best.product.id, dates) < q) { skipped.push(r.best.product.name); return; }
          const days = {}; dates.forEach(d => { days[d] = q; });
          const res = Services.addToCart(s.user.id, { supplierId: r.best.product.supplierId, type: 'item', refId: r.best.product.id, days });
          if (res.ok) added++; else limited.push(r.best.product.name + ' (max ' + r.best.product.maxRentalDays + ' day' + (r.best.product.maxRentalDays > 1 ? 's' : '') + ' per booking)');
        });
        if (limited.length) UI.toast('Not added — rental period too long for: ' + limited.join(', ') + '. Shorten your dates or book these separately.', 'warn');
        App.refreshCart();
        if (skipped.length) UI.toast('Not enough stock for: ' + skipped.join(', ') + '. Choose another option for these.', 'warn');
        if (added) { UI.toast(added + ' item' + (added > 1 ? 's' : '') + ' added to your Rental List. Nothing has been requested yet.', 'success'); setTimeout(() => location.href = url('customer/cart.html'), 900); }
      };
      $('#planOut').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function renderPlanKeep(plan, f) { const orig = AI.plan; AI.plan = () => plan; try { renderPlan(f); } finally { AI.plan = orig; } }
    if (ctx.params.q) run();
  };

  /* ================= SUPPLIERS ================= */
  C.suppliers = function (ctx) {
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Party suppliers</h1><p>Verified local rental shops on PartyRent.</p></div><div class="row wrap"><input class="input input-sm" id="sq" placeholder="Search suppliers or city" style="width:220px" aria-label="Search suppliers"><select class="input input-sm" id="sf" style="width:auto" aria-label="Fulfillment"><option value="">All fulfillment</option><option value="delivery">Offers delivery</option><option value="pickup">Offers pickup</option></select></div></div><div class="grid grid-2" id="list"></div>';
    const draw = () => {
      const q = $('#sq').value.toLowerCase(), f = $('#sf').value;
      const list = liveSups().filter(s => (s.name + ' ' + s.city + ' ' + s.description).toLowerCase().includes(q) && (!f || s.fulfillment === 'both' || s.fulfillment === f)).sort((a, b) => b.rating - a.rating);
      $('#list').innerHTML = list.length ? list.map(s => {
        const items = live().filter(p => p.supplierId === s.id);
        return '<div class="card"><div class="row top"><div class="logo-tile" style="background:' + s.color + '1a">' + esc(s.logo) + '</div><div class="grow"><a class="strong" style="font-size:1.05rem;color:var(--ink)" href="' + url('customer/supplier.html', { id: s.id }) + '">' + esc(s.name) + '</a><div class="small muted">' + I('map').replace('<svg', '<svg style="width:13px;vertical-align:-2px"') + ' ' + esc(s.city) + ' · <span class="stars">★</span> ' + s.rating + ' · ' + UI.fulfillLabel(s.fulfillment) + '</div><p class="small mt-1 mb-1">' + esc(s.description) + '</p><div class="row wrap gap-sm">' + s.methods.map(m => '<span class="tag">' + Finance.METHOD_LABEL[m] + '</span>').join('') + '</div></div></div><div class="scroll-x mt-2">' + items.slice(0, 5).map(p => '<a href="' + url('customer/product.html', { id: p.id }) + '" title="' + esc(p.name) + '">' + UI.thumb(p) + '</a>').join('') + '</div><div class="row between mt-1"><span class="small muted">' + items.length + ' items · ' + livePkgs().filter(k => k.supplierId === s.id).length + ' packages</span><a class="btn btn-sm btn-outline" href="' + url('customer/supplier.html', { id: s.id }) + '">Visit shop</a></div></div>';
      }).join('') : UI.empty('🏪', 'No suppliers found', 'Try another search.');
    };
    $('#sq').oninput = draw; $('#sf').onchange = draw; draw();
  };

  /* ================= SUPPLIER SHOP PAGE ================= */
  C.supplier = function (ctx) {
    const s = Store.supplier(ctx.params.id);
    if (!s || (s.status !== 'approved' && !(ctx.session && ctx.session.user.supplierId === s.id))) { ctx.el.innerHTML = UI.empty('🏪', 'Shop not found', 'This supplier is not available.', '<a class="btn" href="' + url('customer/suppliers.html') + '">All suppliers</a>'); return; }
    const items = Store.where('products', p => p.supplierId === s.id && p.status === 'published');
    const pkgs = livePkgs().filter(k => k.supplierId === s.id);
    const reviews = Store.where('reviews', r => r.supplierId === s.id).sort((a, b) => b.at.localeCompare(a.at));
    const pol = s.policy;
    ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('customer/suppliers.html') + '">Suppliers</a> / ' + esc(s.name) + '</div>' +
      (s.status !== 'approved' ? '<div class="alert alert-warn mb-2">' + I('clock') + '<div>Preview only — your shop is <b>' + esc(s.status) + '</b> and is not visible to customers yet.</div></div>' : '') +
      '<div class="card" style="background:linear-gradient(135deg,' + s.color + '14,#fff)"><div class="row wrap top"><div class="logo-tile" style="width:84px;height:84px;font-size:2.6rem;background:#fff;box-shadow:var(--shadow)">' + (s.logoImg ? '<img src="' + s.logoImg + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:14px">' : esc(s.logo)) + '</div><div class="grow"><h1 class="mb-0">' + esc(s.name) + '</h1><div class="muted">' + esc(s.city) + ' · <span class="stars">' + U.stars(s.rating) + '</span> ' + (s.rating || 'New') + ' (' + reviews.length + ' reviews)</div><p class="mt-1 mb-1">' + esc(s.description) + '</p><div class="row wrap gap-sm"><span class="badge b-teal">' + I('truck').replace('<svg', '<svg style="width:12px"') + ' ' + UI.fulfillLabel(s.fulfillment) + (s.fulfillment !== 'pickup' ? ' · ₱' + s.deliveryFee + ' delivery' : '') + '</span>' + s.methods.map(m => '<span class="badge b-gray plain">' + Finance.METHOD_LABEL[m] + '</span>').join('') + '</div></div>' +
      '<dl class="kv" style="min-width:260px;max-width:340px"><dt>Address</dt><dd>' + esc(s.address) + '</dd><dt>Contact</dt><dd>' + esc(s.phone) + '<br>' + esc(s.email) + '</dd><dt>Hours</dt><dd>' + esc(s.hours) + '</dd></dl></div></div>' +
      U_tabs() + '<div id="tabBody"></div>';
    function U_tabs() { return '<div class="mt-3">' + UI.tabs([{ key: 'items', label: 'Products', count: items.length }, { key: 'packages', label: 'Packages', count: pkgs.length }, { key: 'policies', label: 'Policies & payments' }, { key: 'reviews', label: 'Reviews', count: reviews.length }], 'items') + '</div>'; }
    const body = {
      items: () => '<div class="row wrap mb-2"><select class="input input-sm" id="cf" style="width:auto" aria-label="Category"><option value="">All categories</option>' + Array.from(new Set(items.map(p => p.categoryId))).map(c => '<option value="' + c + '">' + esc(Store.category(c).name) + '</option>').join('') + '</select></div><div class="product-grid" id="pg">' + items.map(p => UI.productCard(p)).join('') + '</div>',
      packages: () => pkgs.length ? '<div class="product-grid">' + pkgs.map(k => UI.packageCard(k)).join('') + '</div>' : UI.empty('📦', 'No packages yet', ''),
      policies: () => { const cfg = Finance.planConfig(s); const ts = Slots.settings(s.id); return '<div class="grid grid-2"><div class="card"><h3>Payment plans</h3><dl class="kv"><dt>Accepted methods</dt><dd>' + s.methods.map(m => Finance.METHOD_LABEL[m]).join(', ') + '</dd>' + Finance.plansOf(s).map(k => '<dt>' + Finance.PLAN_LABEL[k] + '</dt><dd>' + esc(Finance.planSummary(s, k)) + '</dd>').join('') + '<dt>Security deposit</dt><dd>' + (pol.depositType === 'percent' ? pol.depositValue + '% of rental items' : U.money(pol.depositValue)) + ' (refundable)</dd></dl><p class="small muted mb-0">Reservation fees are non-refundable but always credited to the total. Nothing may stay unpaid after the event date.</p></div><div class="card"><h3>Business policies</h3><p><b>Rental hours:</b> ' + Slots.fmt12(ts.open) + ' – ' + Slots.fmt12(ts.close) + ' (' + ts.prepHours + 'h preparation and ' + ts.recoveryHours + 'h recovery between bookings)</p>' + (s.fulfillment !== 'pickup' ? '<p><b>Delivery fees:</b> ' + (s.deliveryZones || []).map(z => esc(z.name) + ' ' + U.money(z.fee)).concat(['other areas ' + U.money(s.deliveryFee || 0)]).join(' · ') + ' — charged per delivery location</p>' : '') + ((s.services || []).length ? '<p><b>Services:</b> ' + s.services.map(x => esc(x.name) + ' ' + U.money(x.amount) + (x.per === 'location' ? '/location' : '') + (x.required ? ' (included)' : '')).join(' · ') + '</p>' : '') + '<p><b>Cancellation:</b> ' + esc(pol.cancellation) + '</p><p><b>Returns:</b> ' + esc(pol.returns) + '</p><p class="mb-0"><b>Rental conditions:</b> ' + esc(pol.conditions) + '</p></div></div>'; },
      reviews: () => reviews.length ? '<div class="stack">' + reviews.map(r => { const u = Store.user(r.customerId); const p = Store.product(r.productId); return '<div class="card card-flat"><div class="row between"><div class="row"><span class="avatar">' + U.initials(u.name) + '</span><div><b>' + esc(u.name.split(' ')[0] + ' ' + u.name.split(' ').slice(-1)[0][0] + '.') + '</b><div class="small muted">' + (p ? esc(p.name) + ' · ' : '') + U.fmtDate(r.at.slice(0, 10)) + '</div></div></div><span class="stars">' + U.stars(r.rating) + '</span></div><p class="mt-1 mb-0">' + esc(r.comment) + '</p></div>'; }).join('') + '</div>' : UI.empty('⭐', 'No reviews yet', '')
    };
    const show = k => { $('#tabBody').innerHTML = body[k](); $$('[data-tab]').forEach(t => t.classList.toggle('active', t.dataset.tab === k)); const cf = $('#cf'); if (cf) cf.onchange = () => { $('#pg').innerHTML = items.filter(p => !cf.value || p.categoryId === cf.value).map(p => UI.productCard(p)).join(''); }; };
    $$('[data-tab]').forEach(t => t.onclick = () => show(t.dataset.tab)); show('items');
  };

  /* ================= PRODUCT DETAILS ================= */
  C.product = function (ctx) {
    const s = ctx.session;
    const p = Store.product(ctx.params.id);
    const adminPreview = p && Services.listingStatus('product', p) !== 'active' && Services.listingStatus('product', p) !== 'deleted' && s && Auth.isStaff(s.user) && Auth.can('listings.view');
    if (!p || (p.status !== 'published' && !adminPreview)) { ctx.el.innerHTML = UI.empty('🔎', 'Item not found', 'This listing may have been removed.', '<a class="btn" href="' + url('customer/browse.html') + '">Browse items</a>'); return; }
    const sup = Store.supplier(p.supplierId);
    const cat = Store.category(p.categoryId);
    const own = s && s.user.supplierId === sup.id;
    // No dates are pre-selected: stock is only shown for the period the customer chooses.
    // Dates come from the link, otherwise from the customer's selected rental period.
    const rp0 = RentalPeriod.get();
    let start = ctx.params.start && ctx.params.start >= U.today() ? ctx.params.start : (rp0 ? rp0.start : '');
    let end = start ? (ctx.params.start ? (ctx.params.end && ctx.params.end >= start ? ctx.params.end : start) : rp0.end) : '';
    let qty = Number(ctx.params.qty) || Math.min(10, p.totalStock) || 1;
    const opts0 = Slots.options(sup.id); const dt = Slots.defaultTime(sup.id);
    const time = { start: rp0 && opts0.includes(rp0.startTime) ? rp0.startTime : dt.start, end: rp0 && opts0.includes(rp0.endTime) ? rp0.endTime : dt.end };
    const line = { id: '__pd', type: 'item', refId: p.id, days: {}, time };
    const firstPlan = () => { const pl = Finance.plansOf(sup); return pl.includes('standard') ? 'standard' : pl[0] || 'full'; };
    const canDeliver = sup.fulfillment !== 'pickup' && p.delivery !== false;
    const resetDays = () => { line.days = {}; if (start) U.range(start, end || start).forEach(d => { line.days[d] = qty; }); };
    resetDays();
    const reviews = Store.where('reviews', r => r.productId === p.id);
    const related = live().filter(x => x.supplierId === p.supplierId && x.id !== p.id).slice(0, 4);
    const similar = live().filter(x => x.categoryId === p.categoryId && x.id !== p.id).sort((a, b) => b.rating - a.rating).slice(0, 4);

    ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('customer/browse.html') + '">Browse</a> / <a href="' + url('customer/browse.html', { category: cat.id }) + '">' + esc(cat.name) + '</a> / ' + esc(p.name) + '</div>' +
      (adminPreview ? '<div class="alert alert-warn mb-2">' + I('eye') + '<div><b>Admin preview.</b> This listing is not visible to customers (' + esc(Services.LISTING[Services.listingStatus('product', p)][0]) + ').</div></div>' : '') +
      '<div class="pd"><div class="pd-gallery"><div class="main" id="mainImg">' + UI.img(p) + '</div>' + (p.images.length > 1 ? '<div class="thumbs">' + p.images.map((im, i) => '<img src="' + im + '" data-img="' + i + '" alt="Photo ' + (i + 1) + '" style="cursor:pointer">').join('') + '</div>' : '') +
      '<div class="card mt-2"><h3>Availability calendar</h3><div id="cal"></div></div></div>' +
      '<div><span class="badge b-purple plain">' + esc(cat.name) + '</span> <span class="badge b-gray plain">Condition: ' + esc(Services.CONDITIONS[p.condition] || p.condition) + '</span><h1 class="mt-1">' + esc(p.name) + '</h1>' +
      '<div class="row wrap small muted"><span class="stars">' + U.stars(p.rating) + '</span><span>' + p.rating + ' · ' + reviews.length + ' reviews · ' + p.rentCount + ' rentals</span></div>' +
      '<div class="mt-2"><span class="price-lg">' + U.money(Pricing.basePrice(p)) + '</span> <span class="muted">/ ' + esc(p.unit) + '</span></div>' +
      '<div class="mt-1"><span class="badge ' + (p.maxRentalDays > 0 ? 'b-purple' : 'b-gray') + ' plain">' + I('clock').replace('<svg', '<svg style="width:13px;height:13px"') + ' ' + esc(UI.maxRentalLabel(p)) + '</span></div>' +
      (p.tiers.length > 1 ? '<div class="mt-1"><div class="small strong mb-1">Quantity-based pricing (per day)</div><div class="tiers" id="tiers"></div></div>' : '') +
      '<p class="mt-2">' + esc(p.description) + '</p><div class="row wrap gap-sm mb-2">' + (p.colors || []).map(c => '<span class="tag">' + UI.swatch(c) + ' ' + esc(c) + '</span>').join('') + (p.themes || []).map(t => '<span class="tag">' + esc(t) + '</span>').join('') + '</div>' +
      '<div class="card soft"><div class="form-grid"><div class="field"><label for="ps">Start date</label><input class="input" type="date" id="ps" min="' + U.today() + '" value="' + start + '"></div><div class="field"><label for="pe">End date</label><input class="input" type="date" id="pe" min="' + U.today() + '" value="' + end + '"></div><div class="field"><label for="pst">Start time</label>' + Slots.select('pst', time.start, sup.id, 'id="pst"') + '</div><div class="field"><label for="pet">End time</label>' + Slots.select('pet', time.end, sup.id, 'id="pet"') + '</div></div><div class="row wrap between mb-2" style="gap:6px"><span class="small">' + I('clock').replace('<svg', '<svg style="width:14px;vertical-align:-2px"') + ' Total rental duration: <b id="durTxt">—</b></span><span class="small muted">Rental hours ' + Slots.fmt12(Slots.settings(sup.id).open) + ' – ' + Slots.fmt12(Slots.settings(sup.id).close) + (Slots.hourLimitLabel(p) ? ' · ' + esc(Slots.hourLimitLabel(p)) : '') + '</span></div>' + (p.maxRentalDays > 0 ? '<div class="alert alert-info mb-2">' + I('info') + '<div class="small">The supplier allows a maximum of <b>' + p.maxRentalDays + ' day' + (p.maxRentalDays > 1 ? 's' : '') + '</b> per booking for this item.</div></div>' : '') + '<div id="durErr"></div>' +
      '<div class="row wrap between"><div><div class="label mb-1">Quantity per day</div><div class="qty" id="qbox"><button type="button" data-d>−</button><input type="number" id="pq" min="1" value="' + qty + '" aria-label="Quantity"><button type="button" data-i>+</button></div></div><div class="right"><div id="availTxt"></div></div></div>' +
      '<div id="stockErr"></div><details class="mt-2" id="perDay"><summary class="strong" style="cursor:pointer">Need different quantities per day? Customize each date</summary><div class="mt-1" id="sched"></div></details>' +
      '<div id="altBox"></div><div id="charges"></div>' +
      (own ? '<div class="alert alert-info">' + I('info') + '<div>This is your own listing. <a href="' + url('supplier/product-form.html', { id: p.id }) + '">Edit in Supplier Mode</a></div></div>' : '<div class="row wrap mt-1"><button class="btn btn-outline btn-lg grow" id="addBtn">' + I('cart') + 'Add to Rental List</button><button class="btn btn-primary btn-lg grow" id="reqBtn">Request Rental</button></div>') +
      '<p class="small muted mt-1 mb-0">Submitting a request does not reserve the items until the supplier accepts it.</p></div>' +
      '<div class="card mt-2"><div class="row"><div class="logo-tile" style="background:' + sup.color + '1a">' + esc(sup.logo) + '</div><div class="grow"><a class="strong" href="' + url('customer/supplier.html', { id: sup.id }) + '">' + esc(sup.name) + '</a><div class="small muted">' + esc(sup.city) + ' · ★ ' + sup.rating + '</div></div><a class="btn btn-sm" href="' + url('customer/supplier.html', { id: sup.id }) + '">View Supplier Profile</a></div><hr><dl class="kv"><dt>Delivery / pickup</dt><dd>' + UI.fulfillLabel(sup.fulfillment) + (p.delivery === false && sup.fulfillment !== 'pickup' ? ' (this item: pickup only)' : '') + (sup.fulfillment !== 'pickup' ? ' · delivery from ' + U.money(Math.min.apply(null, [sup.deliveryFee || 0].concat((sup.deliveryZones || []).map(z => z.fee)))) + ' per location' : '') + '</dd><dt>Payment plans</dt><dd>' + Finance.plansOf(sup).map(a => Finance.PLAN_LABEL[a]).join(', ') + '</dd><dt>Rental hours</dt><dd>' + Slots.fmt12(Slots.settings(sup.id).open) + ' – ' + Slots.fmt12(Slots.settings(sup.id).close) + ' · ' + Slots.settings(sup.id).prepHours + 'h preparation / ' + Slots.settings(sup.id).recoveryHours + 'h recovery between bookings</dd><dt>Payment methods</dt><dd>' + sup.methods.map(m => Finance.METHOD_LABEL[m]).join(', ') + '</dd><dt>Security deposit</dt><dd>' + (sup.policy.depositType === 'percent' ? sup.policy.depositValue + '% of items' : U.money(sup.policy.depositValue)) + ' (refundable)</dd><dt>Rental duration</dt><dd>' + esc(UI.maxRentalLabel(p)) + (p.maxRentalDays > 0 ? ' per booking' : '') + '</dd><dt>Cancellation</dt><dd>' + esc(sup.policy.cancellation) + '</dd><dt>Returns</dt><dd>' + esc(sup.policy.returns) + '</dd></dl></div></div></div>' +
      (reviews.length ? '<section class="section"><h2>Reviews</h2><div class="grid grid-2">' + reviews.map(r => '<div class="card card-flat"><div class="row between"><b>' + esc(Store.user(r.customerId).name.split(' ')[0]) + '</b><span class="stars">' + U.stars(r.rating) + '</span></div><p class="mb-0 mt-1">' + esc(r.comment) + '</p></div>').join('') + '</div></section>' : '') +
      (related.length ? section('More from ' + esc(sup.name), related.map(x => UI.productCard(x)).join('')) : '') +
      (similar.length ? section('Similar items from other shops', similar.map(x => UI.productCard(x)).join('')) : '');

    $$('[data-img]').forEach(t => t.onclick = () => { $('#mainImg').innerHTML = '<img src="' + p.images[+t.dataset.img] + '" alt="">'; });
    function refresh(fromSched) {
      const dates = Object.keys(line.days).sort();
      const chk = Inventory.check([line]);
      const dur = Services.durationIssues([line]).map(x => x.message).concat(dates.length ? Slots.validateLine(line) : []);
      $('#durErr').innerHTML = dur.length ? '<div class="alert alert-danger mb-2" role="alert">' + I('alert') + '<div>' + dur.map(esc).join('<br>') + '</div></div>' : '';
      $('#durTxt').textContent = dates.length ? Slots.duration(line).label + ' · ' + Slots.rangeLabel(line.time) : 'Select dates';
      if (!own) { $('#addBtn').disabled = !!dur.length; $('#reqBtn').disabled = !!dur.length; }
      $('#pe').max = p.maxRentalDays > 0 && $('#ps').value ? U.addDays($('#ps').value, p.maxRentalDays - 1) : '';
      const ok = chk.ok && dates.length && !dates.some(d => d < U.today());
      // Available for the period = the lowest daily availability across the selected dates
      const minA = dates.length ? Inventory.lineAvailable(line) : 0; // lowest availability during the selected dates AND times
      const custom = new Set(Object.values(line.days)).size > 1;
      const rangeTxt = dates.length ? U.fmtRange(dates[0], dates[dates.length - 1]) : '';
      $('#availTxt').innerHTML = !dates.length ? '<span class="badge b-gray">Select rental dates</span><div class="small muted">to see available stock</div>' : ok ? '<span class="badge b-green">' + minA + ' available</span><div class="small muted">for ' + esc(rangeTxt) + ', ' + esc(Slots.rangeLabel(line.time)) + '</div>' : '<span class="badge b-red">Not enough stock</span><div class="small muted">' + minA + ' available for ' + esc(rangeTxt) + '</div>';
      $('#qbox').classList.toggle('bad', !!dates.length && !ok);
      $('#stockErr').innerHTML = dates.length && !chk.ok ? '<div class="alert alert-danger mt-2" role="alert">' + I('alert') + '<div>' + chk.bad.map(b => 'You requested <b>' + b.requested + '</b> for ' + esc(U.fmtDay(b.date)) + ', but only <b>' + b.available + '</b> ' + (b.available === 1 ? 'is' : 'are') + ' available for ' + esc(Slots.rangeLabel(line.time)) + '.').join('<br>') + ' Please lower the quantity or choose other dates.</div></div>' : '';
      $('#charges').innerHTML = dates.length ? UI.rentalCharges(Pricing.quote([line], sup.id, canDeliver ? 'delivery' : 'pickup', firstPlan(), { destinations: canDeliver ? [{ id: 'd1', label: 'Delivery (standard rate)', zoneId: '' }] : [] }), { estimate: true, note: canDeliver ? 'Delivery at the standard rate; your delivery zone and any extra locations are chosen at checkout.' : 'Pickup at the shop — no delivery fee.' }) : '<div class="sum-line total"><span>Rental Charges</span><span class="price">Select dates</span></div>';
      if ($('#tiers')) $('#tiers').innerHTML = p.tiers.map(t => '<div class="tier' + (Object.values(line.days).some(q => q >= t.min && (t.max == null || q <= t.max)) ? ' on' : '') + '"><b>' + U.money(t.price) + '</b>' + t.min + (t.max ? '–' + t.max : '+') + ' pcs</div>').join('');
      Scheduler.calendar($('#cal'), 'item', p.id, (dates[0] || U.today()).slice(0, 7), { need: Math.max.apply(null, Object.values(line.days).concat([1])), selected: dates, onPick: d => { if (!start || d < start || start !== end) { start = d; end = d; } else end = d; $('#ps').value = start; $('#pe').value = end; RentalPeriod.set(start, end); resetDays(); refresh(); } });
      if (!fromSched) Scheduler.render($('#sched'), line, { onChange: () => refresh(true) });
      if (custom) $('#perDay').open = true;
      // AI alternatives when unavailable
      if (dates.length && !chk.ok) {
        const alts = AI.alternatives(p.id, line.days);
        $('#altBox').innerHTML = '<div class="alert alert-ai mt-2">' + I('sparkle') + '<div class="grow"><b>Not enough stock — PartyRent Assist found available alternatives:</b>' + (alts.length ? alts.map(a => a.pkg ? '<div class="row between mt-1"><span>📦 <a href="' + url('customer/package.html', { id: a.pkg.id }) + '">' + esc(a.pkg.name) + '</a> · ' + esc(a.supplier.name) + '<br><span class="small">' + esc(a.reason) + '</span></span><b>' + U.moneyShort(a.pkg.price) + '</b></div>' : '<div class="row between mt-1"><span><a href="' + url('customer/product.html', { id: a.product.id, start: dates[0], end: dates[dates.length - 1], qty: Math.max.apply(null, Object.values(line.days)) }) + '">' + esc(a.product.name) + '</a> · ' + esc(a.supplier.name) + '<br><span class="small">' + esc(a.reason) + '</span></span><b>' + U.money(a.unit) + '</b></div>').join('') : '<div class="small mt-1">No similar items are free on these dates. Try other dates or lower quantities.</div>') + '<div class="small mt-1" style="opacity:.8">Suggestions are checked against live availability. You decide what to rent.</div></div></div>';
      } else $('#altBox').innerHTML = '';
      return ok;
    }
    const onDates = () => {
      const a = $('#ps').value, b = $('#pe').value;
      if (!a) { start = ''; end = ''; $('#ps').value = ''; $('#pe').value = ''; RentalPeriod.clear(); resetDays(); refresh(); return; } // dates cleared → hide stock again
      if (a < U.today()) { UI.toast('Start date cannot be in the past.', 'error'); $('#ps').value = start; return; }
      start = a; end = b && b >= a ? b : a; $('#pe').value = end;
      if (U.diffDays(start, end) + 1 > Store.settings().maxRentalDays) { UI.toast('Maximum rental period is ' + Store.settings().maxRentalDays + ' days.', 'warn'); end = U.addDays(start, Store.settings().maxRentalDays - 1); $('#pe').value = end; }
      RentalPeriod.set(start, end); // keep the selected rental period in sync across pages
      resetDays(); refresh();
    };
    $('#ps').onchange = onDates; $('#pe').onchange = onDates;
    const onTime = () => { line.time = { start: $('#pst').value, end: $('#pet').value }; if (RentalPeriod.get()) RentalPeriod.setTimes(line.time.start, line.time.end); refresh(); };
    $('#pst').onchange = onTime; $('#pet').onchange = onTime;
    const setQ = v => { qty = Math.max(1, Math.floor(v) || 1); $('#pq').value = qty; Object.keys(line.days).forEach(d => { line.days[d] = qty; }); refresh(); };
    $('#pq').onchange = e => setQ(+e.target.value);
    $('#qbox [data-d]').onclick = () => setQ(qty - 1); $('#qbox [data-i]').onclick = () => setQ(qty + 1);
    const add = (thenGo) => {
      if (!requireLogin(s, 'Log in to add items to your Rental List.')) return;
      if (Auth.activeRole(s.user, s.mode) !== 'customer') return UI.toast('Switch to Customer Mode to rent items.', 'warn');
      if (!Object.keys(line.days).length) { $('#ps').focus(); return UI.toast('Select your rental start and end dates first.', 'warn'); }
      const days = Object.fromEntries(Object.entries(line.days).filter(([, q]) => q > 0));
      if (!Object.keys(days).length) return UI.toast('Set a quantity for at least one date.', 'warn');
      // check combined with what's already in the cart for this supplier
      const cartLines = Services.cart(s.user.id).filter(l => l.supplierId === p.supplierId);
      const tIss = Slots.validateLine({ type: 'item', refId: p.id, days, time: line.time });
      if (tIss.length) return UI.toast(tIss[0], 'error');
      const combined = Inventory.check(cartLines.concat([{ id: '__new', type: 'item', refId: p.id, days, time: line.time }]));
      if (!combined.ok) return UI.toast('Not enough stock on ' + combined.bad.map(b => U.fmtDate(b.date) + ' (available ' + b.available + ')').join(', ') + (cartLines.length ? ', including items already in your Rental List.' : '.'), 'error');
      const dur = Services.durationIssues([{ id: '__d', type: 'item', refId: p.id, days }]);
      if (dur.length) return UI.toast(dur[0].message, 'error');
      const added = Services.addToCart(s.user.id, { supplierId: p.supplierId, type: 'item', refId: p.id, days, time: line.time });
      if (!added.ok) return UI.toast(added.error, 'error');
      App.refreshCart();
      if (thenGo) { const c = Services.cart(s.user.id); Services.setCartSelected(s.user.id, c.map(l => l.id), false); Services.setCartSelected(s.user.id, c.filter(l => l.supplierId === p.supplierId && l.refId === p.id).map(l => l.id), true); U.go('customer/checkout.html', { supplier: p.supplierId }); }
      else UI.toast(p.name + ' added to your Rental List.', 'success');
    };
    if (!own) { $('#addBtn').onclick = () => add(false); $('#reqBtn').onclick = () => add(true); }
    refresh();
  };

  /* ================= PACKAGES ================= */
  C.packages = function (ctx) {
    const evs = Array.from(new Set(livePkgs().flatMap(k => k.events || [])));
    ctx.el.innerHTML = '<div class="page-head"><div><h1>Rental packages</h1><p>Ready-made bundles from our suppliers. Package stock comes from the real items inside each bundle.</p></div><div class="row wrap gap-sm" id="ev"><button class="chip active" data-e="">All</button>' + evs.map(e => '<button class="chip" data-e="' + esc(e) + '">' + esc(e) + '</button>').join('') + '</div></div><div class="product-grid" id="pk"></div>';
    const draw = e => { $('#pk').innerHTML = livePkgs().filter(k => !e || (k.events || []).includes(e)).map(k => UI.packageCard(k)).join('') || UI.empty('📦', 'No packages', ''); $$('#ev .chip').forEach(c => c.classList.toggle('active', c.dataset.e === e)); };
    $$('#ev .chip').forEach(c => c.onclick = () => draw(c.dataset.e)); draw('');
  };

  C.package = function (ctx) {
    const s = ctx.session;
    const k = Store.pkg(ctx.params.id);
    const pkgSt = Services.listingStatus('package', k);
    const adminPreview = k && pkgSt !== 'active' && pkgSt !== 'deleted' && s && Auth.isStaff(s.user) && Auth.can('listings.view');
    if (!k || (pkgSt !== 'active' && !adminPreview)) { ctx.el.innerHTML = UI.empty('📦', 'Package not found', '', '<a class="btn" href="' + url('customer/packages.html') + '">All packages</a>'); return; }
    const sup = Store.supplier(k.supplierId); const v = Pricing.packageValue(k);
    const rp0 = RentalPeriod.get();
    let start = ctx.params.start && ctx.params.start >= U.today() ? ctx.params.start : (rp0 ? rp0.start : ''), end = start ? (ctx.params.start ? (ctx.params.end && ctx.params.end >= start ? ctx.params.end : start) : rp0.end) : '';
    const dt = Slots.defaultTime(sup.id); const o0 = Slots.options(sup.id);
    const line = { id: '__pk', type: 'package', refId: k.id, days: {}, time: { start: rp0 && o0.includes(rp0.startTime) ? rp0.startTime : dt.start, end: rp0 && o0.includes(rp0.endTime) ? rp0.endTime : dt.end } };
    const reset = () => { line.days = {}; U.range(start, end).forEach(d => { line.days[d] = 1; }); };
    reset();
    ctx.el.innerHTML = '<div class="crumbs"><a href="' + url('customer/packages.html') + '">Packages</a> / ' + esc(k.name) + '</div>' + (adminPreview ? '<div class="alert alert-warn mb-2">' + I('eye') + '<div><b>Admin preview.</b> This package is not visible to customers (' + esc(Services.LISTING[pkgSt][0]) + ').</div></div>' : '') + '<div class="pd"><div><div class="pd-gallery"><div class="main">' + UI.img(k, 'package') + '</div></div><div class="card mt-2"><h3>Package availability</h3><p class="small muted">Based on the stock of every included item.</p><div id="cal"></div></div></div>' +
      '<div><span class="badge b-pink plain">' + esc(k.emoji) + ' Package</span>' + (k.events || []).map(e => ' <span class="badge b-gray plain">' + esc(e) + '</span>').join('') + '<h1 class="mt-1">' + esc(k.name) + '</h1><p>' + esc(k.description) + '</p><div><span class="price-lg">' + U.money(k.price) + '</span> <span class="muted">/ package / day</span>' + (v.savings > 0 ? ' <span class="badge b-gold">Save ' + U.money(v.savings) + ' vs. renting separately</span>' : '') + '</div>' +
      '<div class="card mt-2 pad-0"><div style="padding:14px 16px;border-bottom:1px solid var(--line)" class="strong">Included items</div>' + k.items.map(it => { const p = Store.product(it.productId); return '<div class="row" style="padding:10px 16px;border-bottom:1px solid var(--line)">' + UI.thumb(p, null, 'sm') + '<div class="grow"><a href="' + url('customer/product.html', { id: p.id }) + '">' + esc(p.name) + '</a></div><b>× ' + it.qty + '</b></div>'; }).join('') + '<div class="small muted" style="padding:10px 16px">' + esc(k.conditions) + '</div></div>' +
      '<div class="card soft mt-2"><div class="form-grid"><div class="field"><label for="ps">Start date</label><input type="date" class="input" id="ps" min="' + U.today() + '" value="' + start + '"></div><div class="field"><label for="pe">End date</label><input type="date" class="input" id="pe" min="' + U.today() + '" value="' + end + '"></div><div class="field"><label for="pst">Start time</label>' + Slots.select('pst', line.time.start, sup.id, 'id="pst"') + '</div><div class="field"><label for="pet">End time</label>' + Slots.select('pet', line.time.end, sup.id, 'id="pet"') + '</div></div><div class="small mb-1">' + I('clock').replace('<svg', '<svg style="width:14px;vertical-align:-2px"') + ' Total rental duration: <b id="durTxt">—</b>' + (Slots.hourLimitLabel(k) ? ' · <span class="muted">' + esc(Slots.hourLimitLabel(k)) + '</span>' : '') + '</div><div id="tErr"></div><div id="sched"></div><div id="charges"></div><div class="row wrap mt-2"><button class="btn btn-outline btn-lg grow" id="addBtn">' + I('cart') + 'Add to Rental List</button><button class="btn btn-primary btn-lg grow" id="reqBtn">Request Rental</button></div></div>' +
      '<div class="card mt-2"><div class="row"><div class="logo-tile" style="background:' + sup.color + '1a">' + esc(sup.logo) + '</div><div class="grow"><a class="strong" href="' + url('customer/supplier.html', { id: sup.id }) + '">' + esc(sup.name) + '</a><div class="small muted">' + esc(sup.city) + ' · ' + UI.fulfillLabel(sup.fulfillment) + '</div></div></div></div></div></div>';
    const firstPlan = () => { const pl = Finance.plansOf(sup); return pl.includes('standard') ? 'standard' : pl[0] || 'full'; };
    const extras = () => {
      const has = Object.keys(line.days).length; const ti = has ? Slots.validateLine(line) : [];
      $('#durTxt').textContent = has ? Slots.duration(line).label + ' · ' + Slots.rangeLabel(line.time) : 'Select dates';
      $('#tErr').innerHTML = ti.length ? '<div class="alert alert-danger mb-1">' + I('alert') + '<div>' + ti.map(esc).join('<br>') + '</div></div>' : '';
      const del = sup.fulfillment !== 'pickup';
      $('#charges').innerHTML = has ? UI.rentalCharges(Pricing.quote([line], sup.id, del ? 'delivery' : 'pickup', firstPlan(), { destinations: del ? [{ id: 'd1', label: 'Delivery (standard rate)', zoneId: '' }] : [] }), { estimate: true }) : '';
    };
    const refresh = () => { Scheduler.render($('#sched'), line, { onChange: () => { draw(); extras(); } }); draw(); extras(); };
    const onT = () => { line.time = { start: $('#pst').value, end: $('#pet').value }; if (RentalPeriod.get()) RentalPeriod.setTimes(line.time.start, line.time.end); refresh(); };
    const draw = () => Scheduler.calendar($('#cal'), 'package', k.id, (Object.keys(line.days).sort()[0] || U.today()).slice(0, 7), { need: 1, selected: Object.keys(line.days) });
    const onD = () => { start = $('#ps').value || ''; if (!start) $('#pe').value = ''; end = start ? ($('#pe').value >= start ? $('#pe').value : start) : ''; $('#ps').value = start; $('#pe').value = end; if (!start) RentalPeriod.clear(); else if (!RentalPeriod.set(start, end)) UI.toast(RentalPeriod.validate(start, end), 'warn'); reset(); refresh(); };
    $('#ps').onchange = onD; $('#pe').onchange = onD; $('#pst').onchange = onT; $('#pet').onchange = onT;
    const add = go => {
      if (!requireLogin(s, 'Log in to rent packages.')) return;
      if (Auth.activeRole(s.user, s.mode) !== 'customer') return UI.toast('Switch to Customer Mode to rent.', 'warn');
      if (s.user.supplierId === sup.id) return UI.toast('You cannot rent your own package.', 'warn');
      const days = Object.fromEntries(Object.entries(line.days).filter(([, q]) => q > 0));
      if (!Object.keys(days).length) return UI.toast('Add at least one date.', 'warn');
      const cartLines = Services.cart(s.user.id).filter(l => l.supplierId === sup.id);
      const chk = Inventory.check(cartLines.concat([{ id: '__n', type: 'package', refId: k.id, days, time: line.time }]));
      if (!chk.ok) return UI.toast('Not enough stock for ' + chk.bad.map(b => Store.product(b.productId).name + ' on ' + U.fmtDate(b.date)).join(', ') + '.', 'error');
      const added = Services.addToCart(s.user.id, { supplierId: sup.id, type: 'package', refId: k.id, days, time: line.time });
      if (!added.ok) return UI.toast(added.error, 'error');
      App.refreshCart();
      if (go) { const c = Services.cart(s.user.id); Services.setCartSelected(s.user.id, c.map(l => l.id), false); Services.setCartSelected(s.user.id, c.filter(l => l.supplierId === sup.id && l.refId === k.id).map(l => l.id), true); U.go('customer/checkout.html', { supplier: sup.id }); } else UI.toast(k.name + ' added to your Rental List.', 'success');
    };
    $('#addBtn').onclick = () => add(false); $('#reqBtn').onclick = () => add(true);
    refresh();
  };
})();
