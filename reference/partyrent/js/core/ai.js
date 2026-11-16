/* =========================================================
   PartyRent — ai.js  (PartyRent Assist)
   ---------------------------------------------------------
   DEMO AI ENGINE: a local, rule-based natural-language layer.
   It is NOT a live AI service. It interprets English/Filipino
   event descriptions into filters and builds suggestions ONLY
   from existing listings, prices and Inventory availability.
   To connect a real model, replace AI.provider.interpret() /
   AI.provider.chat() with API calls; keep the guardrails below.

   Guardrails: AI may recommend, suggest, interpret, search,
   summarize, explain and assist. It never sets prices, accepts
   or declines requests, reserves stock, verifies payments,
   cancels transactions or changes any record.
   ========================================================= */
(function (g) {
  'use strict';

  const EVENTS = {
    Birthday: ['birthday', 'bday', 'b-day', 'kaarawan', 'kaarawan', 'birthday party', '1st birthday', 'kiddie party'],
    Debut: ['debut', '18th', 'debutante'],
    Wedding: ['wedding', 'kasal', 'kasalan', 'reception', 'bride'],
    Christening: ['christening', 'binyag', 'baptism', 'binyagan'],
    Corporate: ['corporate', 'company', 'office', 'team building', 'seminar', 'conference', 'launch'],
    Reunion: ['reunion', 'family gathering', 'salu-salo', 'salo-salo', 'handaan'],
    Anniversary: ['anniversary', 'anibersaryo'],
    'Baby Shower': ['baby shower', 'gender reveal']
  };
  const COLORS = { Pink: ['pink', 'rosas', 'blush', 'pastel pink'], White: ['white', 'puti'], Gold: ['gold', 'ginto', 'golden', 'champagne'], Blue: ['blue', 'asul'], Red: ['red', 'pula'], Black: ['black', 'itim'], Clear: ['clear', 'transparent', 'ghost'], Multicolor: ['colorful', 'makulay', 'rainbow', 'multicolor'], 'Warm White': ['warm white', 'warm light'] };
  const THEMES = { Elegant: ['elegant', 'classy', 'formal', 'sosyal'], Pastel: ['pastel'], Princess: ['princess', 'prinsesa'], Kids: ['kids', 'kiddie', 'bata', 'children', 'mga bata'], Modern: ['modern', 'minimal'], Glam: ['glam', 'glamorous'], Garden: ['garden', 'hardin'], Carnival: ['carnival', 'perya'], Romantic: ['romantic'], Rustic: ['rustic'] };
  const ITEMS = {
    chairs: ['chair', 'chairs', 'upuan', 'silya', 'monoblock', 'tiffany', 'chiavari', 'seat'],
    tables: ['table', 'tables', 'mesa', 'lamesa'],
    tents: ['tent', 'tents', 'canopy', 'tolda', 'trapal', 'shade'],
    sound: ['sound', 'speaker', 'speakers', 'mic', 'microphone', 'audio', 'videoke', 'karaoke', 'music', 'tugtog'],
    lighting: ['light', 'lights', 'ilaw', 'lighting', 'uplight', 'fairy'],
    decorations: ['decoration', 'decorations', 'decor', 'dekorasyon', 'balloon', 'balloons', 'lobo', 'centerpiece', 'centerpieces', 'garland'],
    backdrops: ['backdrop', 'arch', 'photo wall', 'stage'],
    tableware: ['tablecloth', 'tablecloths', 'table cloth', 'plates', 'utensils', 'pinggan', 'kubyertos', 'glasses', 'cutlery', 'tableware'],
    catering: ['chafing', 'catering', 'food warmer', 'popcorn', 'drink station', 'dispenser', 'buffet'],
    games: ['games', 'game', 'laro', 'bubble', 'palaro'],
    furniture: ['sofa', 'lounge', 'couch'],
    other: ['photo booth', 'photobooth', 'props']
  };
  const MONTHS = [['january', 'jan', 'enero'], ['february', 'feb', 'pebrero'], ['march', 'mar', 'marso'], ['april', 'apr', 'abril'], ['may', 'mayo'], ['june', 'jun', 'hunyo'], ['july', 'jul', 'hulyo'], ['august', 'aug', 'agosto'], ['september', 'sep', 'sept', 'setyembre'], ['october', 'oct', 'oktubre'], ['november', 'nov', 'nobyembre'], ['december', 'dec', 'disyembre']];
  const CITIES = ['Quezon City', 'Pasig City', 'Makati City', 'Taguig City', 'Manila', 'Caloocan City', 'Marikina', 'Parañaque', 'Mandaluyong', 'San Juan', 'Pasay'];

  const has = (t, words) => words.some(w => new RegExp('(^|[^a-z])' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '([^a-z]|$)', 'i').test(t));

  function monthIndex(word) { word = word.toLowerCase(); return MONTHS.findIndex(m => m.includes(word)); }
  function mkDate(mi, day) {
    const t = U.parse(U.today());
    let y = t.getFullYear();
    let d = new Date(y, mi, day);
    if (U.iso(d) < U.today()) d = new Date(y + 1, mi, day);
    return U.iso(d);
  }
  function parseDates(text) {
    const mw = MONTHS.flat().sort((a, b) => b.length - a.length).join('|');
    let m = new RegExp('(' + mw + ')\\.?\\s*(\\d{1,2})(?:st|nd|rd|th)?\\s*(?:-|–|to|hanggang|until|and|at)\\s*(?:(' + mw + ')\\.?\\s*)?(\\d{1,2})', 'i').exec(text);
    if (m) {
      const m1 = monthIndex(m[1]), m2 = m[3] ? monthIndex(m[3]) : m1;
      const s = mkDate(m1, +m[2]); let e = mkDate(m2, +m[4]); if (e < s) e = U.addDays(s, 0);
      return { start: s, end: e };
    }
    m = new RegExp('(' + mw + ')\\.?\\s*(\\d{1,2})', 'i').exec(text);
    if (m) { const s = mkDate(monthIndex(m[1]), +m[2]); return { start: s, end: s }; }
    m = /(\d{4}-\d{2}-\d{2})(?:\s*(?:to|-|–)\s*(\d{4}-\d{2}-\d{2}))?/.exec(text);
    if (m) return { start: m[1], end: m[2] || m[1] };
    if (/\b(tomorrow|bukas)\b/i.test(text)) { const s = U.addDays(U.today(), 1); return { start: s, end: s }; }
    if (/\b(this weekend|ngayong weekend|sa weekend)\b/i.test(text)) { const d = U.parse(U.today()); const add = (6 - d.getDay() + 7) % 7 || 7; const s = U.addDays(U.today(), add); return { start: s, end: U.addDays(s, 1) }; }
    const nd = /(\d+)\s*(?:days|araw)/i.exec(text);
    return nd ? { days: +nd[1] } : {};
  }

  /** Natural language → structured filters (all fields editable by the customer). */
  function interpret(text) {
    const t = ' ' + String(text || '').toLowerCase() + ' ';
    const out = { raw: text, eventType: null, guests: null, locationType: null, colors: [], themes: [], items: [], start: null, end: null, city: null, budget: null, delivery: null, language: /\b(ng|para|sa|mga|kailangan|gusto|namin|ako|bisita|upuan|mesa)\b/.test(t) ? 'Filipino' : 'English' };
    for (const [ev, words] of Object.entries(EVENTS)) if (has(t, words)) { out.eventType = ev; break; }
    const gm = /(\d{1,4})\s*(?:\+\s*)?(guests?|pax|people|persons?|bisita|katao|tao|visitors|attendees|heads)/i.exec(t) || /(?:for|para sa)\s+(\d{2,4})\b(?!\s*(?:am|pm|days|araw))/i.exec(t);
    if (gm) out.guests = +gm[1];
    if (has(t, ['outdoor', 'outdoors', 'garden', 'labas', 'open air', 'backyard', 'beach', 'poolside', 'bakuran', 'kalsada', 'street'])) out.locationType = 'Outdoor';
    else if (has(t, ['indoor', 'indoors', 'loob', 'hall', 'function room', 'clubhouse', 'ballroom'])) out.locationType = 'Indoor';
    for (const [c, words] of Object.entries(COLORS)) if (has(t, words)) out.colors.push(c);
    if (out.colors.includes('Warm White')) out.colors = out.colors.filter(c => c !== 'White' || /\bwhite\b(?!\s*light)/.test(t.replace('warm white', '')));
    for (const [th, words] of Object.entries(THEMES)) if (has(t, words)) out.themes.push(th);
    for (const [cat, words] of Object.entries(ITEMS)) if (has(t, words)) out.items.push(cat);
    const d = parseDates(t);
    if (d.start) { out.start = d.start; out.end = d.end; }
    const city = CITIES.find(c => t.includes(c.toLowerCase().replace(' city', '')));
    if (city) out.city = city;
    const bm = /(?:₱|php|p)\s?([\d,]+(?:\.\d+)?)\s*(k)?|([\d,]+)\s*(k)?\s*(?:pesos|piso|budget)|budget\s*(?:of|ay|na)?\s*₱?\s*([\d,]+)\s*(k)?/i.exec(t);
    if (bm) { const num = +(bm[1] || bm[3] || bm[5]).replace(/,/g, ''); const k = bm[2] || bm[4] || bm[6]; out.budget = k ? num * 1000 : num; }
    if (has(t, ['deliver', 'delivery', 'ihatid', 'hatid'])) out.delivery = true;
    if (has(t, ['pickup', 'pick up', 'pick-up', 'kukunin'])) out.delivery = false;
    return out;
  }

  /* ---------- listings helpers (existing data only) ---------- */
  function liveProducts() { return Store.where('products', p => p.status === 'published' && (Store.supplier(p.supplierId) || {}).status === 'approved'); }
  function datesOf(f) { if (f.start) return U.range(f.start, f.end || f.start); return []; }
  function score(p, f) {
    let s = (p.rating || 0);
    if (f.colors && f.colors.length) s += U.sum(f.colors, c => (p.colors || []).includes(c) ? 3 : 0);
    if (f.themes && f.themes.length) s += U.sum(f.themes, c => (p.themes || []).includes(c) ? 2 : 0);
    if (f.eventType && (p.events || []).includes(f.eventType)) s += 2;
    const kidsItem = /kid|kiddie/i.test(p.name) || ((p.themes || []).includes('Kids') && (p.themes || []).length === 1);
    if (kidsItem && !(f.themes || []).includes('Kids')) s -= 5;
    const sup = Store.supplier(p.supplierId);
    if (f.city && sup.city === f.city) s += 1.5;
    if (f.delivery === true && sup.fulfillment === 'pickup') s -= 4;
    if (f.delivery === false && sup.fulfillment === 'delivery') s -= 4;
    return s;
  }
  function estCost(p, qty, dates) { const n = Math.max(1, dates.length); return U.round2(Pricing.unitPrice(p, qty) * qty * n); }

  /** Suggested quantity per category based on guest count and event. */
  function qtyFor(cat, p, f) {
    const G = f.guests || 50;
    const tables = Math.ceil(G / 8);
    switch (cat) {
      case 'chairs': return [G, 'One seat per guest (' + G + ' guests).'];
      case 'tables': return /kiddie/i.test(p.name) ? [Math.ceil(G / 6), '6 kids per kiddie table.'] : /banquet|rectangular/i.test(p.name) ? [Math.ceil(G / 9), 'About 8–10 guests per long table.'] : [tables, '8 guests per round table.'];
      case 'tableware': return /tablecloth|cloth/i.test(p.name) ? [tables, 'One cloth for each of the ' + tables + ' tables.'] : [Math.ceil(G / 10), 'One set serves 10 guests.'];
      case 'decorations': return /centerpiece/i.test(p.name) ? [tables, 'One centerpiece per guest table.'] : [1, 'A focal décor piece for the venue.'];
      case 'tents': { const cap = /20×20/.test(p.name) ? 60 : 25; return [Math.ceil(G / cap), 'Each tent shelters about ' + cap + ' seated guests' + (f.locationType === 'Outdoor' ? ' (outdoor event).' : '.')]; }
      case 'sound': return [1, G > 150 ? 'A large crowd needs a pro system.' : 'For music, program and speeches.'];
      case 'lighting': return [/curtain/i.test(p.name) ? 2 : 1, 'Sets the mood for evening programs.'];
      case 'backdrops': return [1, 'Stage and photo area backdrop.'];
      case 'catering': return /chafing/i.test(p.name) ? [Math.max(2, Math.ceil(G / 25)), 'About one chafing dish per 25 guests.'] : [1, 'Keeps food and drinks flowing.'];
      case 'games': return [1, 'Entertainment for kids.'];
      case 'furniture': return [1, 'A lounge area for VIPs or elders.'];
      default: return [1, 'Popular add-on for this event.'];
    }
  }
  const DEFAULTS = {
    Birthday: ['chairs', 'tables', 'tableware', 'decorations', 'backdrops', 'sound'], Debut: ['chairs', 'tables', 'backdrops', 'lighting', 'decorations', 'sound'],
    Wedding: ['chairs', 'tables', 'tableware', 'decorations', 'backdrops', 'lighting', 'sound'], Christening: ['chairs', 'tables', 'tableware', 'decorations', 'catering'],
    Corporate: ['chairs', 'tables', 'sound', 'catering'], Reunion: ['chairs', 'tables', 'sound', 'catering'], Anniversary: ['chairs', 'tables', 'decorations', 'lighting', 'sound'], 'Baby Shower': ['chairs', 'tables', 'decorations', 'backdrops']
  };

  /** Ranked candidates for one category, with real date-by-date availability. */
  function candidates(cat, f, qtyOverride) {
    const dates = datesOf(f);
    return liveProducts().filter(p => p.categoryId === cat).map(p => {
      const [q, reason] = qtyFor(cat, p, f);
      const qty = qtyOverride || q;
      // without event dates there is no rental period to check, so stock is not judged yet
      const avail = dates.length ? Inventory.minAvailable(p.id, dates) : null;
      const enough = avail === null ? true : avail >= qty;
      return { product: p, supplier: Store.supplier(p.supplierId), qty, reason, available: avail, enough, cost: estCost(p, qty, dates), score: score(p, f) + (avail !== null && enough ? 5 : 0) };
    }).sort((a, b) => b.score - a.score || a.cost - b.cost);
  }

  /** Event checklist with suggested listings. Nothing is added to the cart automatically. */
  function plan(f) {
    let cats = f.items && f.items.length ? f.items.slice() : (DEFAULTS[f.eventType] || DEFAULTS.Birthday).slice();
    if (f.locationType === 'Outdoor' && !cats.includes('tents')) cats.push('tents');
    if (f.eventType === 'Birthday' && (f.themes || []).includes('Kids') && !cats.includes('games')) cats.push('games');
    const rows = cats.map(cat => {
      const list = candidates(cat, f);
      const best = list.find(c => c.enough) || list[0] || null;
      return { category: cat, best, alternatives: list.filter(c => c !== best).slice(0, 3), none: !list.length };
    });
    const dates = datesOf(f);
    const pkgs = Store.where('packages', k => Services.isListed('package', k) && (Store.supplier(k.supplierId) || {}).status === 'approved' && (!f.eventType || (k.events || []).includes(f.eventType))).map(k => {
      const avail = dates.length ? Math.min.apply(null, dates.map(d => Inventory.packageAvailableOn(k.id, d))) : null;
      return { pkg: k, supplier: Store.supplier(k.supplierId), available: avail, cost: k.price * Math.max(1, dates.length) };
    }).filter(x => x.available === null || x.available > 0);
    const total = U.sum(rows.filter(r => r.best && r.best.enough), r => r.best.cost);
    return { filters: f, dates, rows, packages: pkgs, total, overBudget: f.budget ? total > f.budget : false };
  }

  /** Similar AVAILABLE items when the chosen one cannot cover the requested quantity. */
  function alternatives(productId, days) {
    const p = Store.product(productId); if (!p) return [];
    const dates = Object.keys(days || {});
    const out = [];
    liveProducts().filter(x => x.id !== p.id && x.categoryId === p.categoryId).forEach(x => {
      const ok = dates.every(d => Inventory.availableOn(x.id, d) >= (Number(days[d]) || 0));
      if (!ok) return;
      const sameColor = (x.colors || []).some(c => (p.colors || []).includes(c));
      const sameTheme = (x.themes || []).some(c => (p.themes || []).includes(c));
      const reason = x.supplierId !== p.supplierId ? (sameColor ? 'Same color from another supplier' : 'Different style from another supplier') : (sameColor ? 'Similar item from the same shop' : 'Alternative design from the same shop');
      out.push({ product: x, supplier: Store.supplier(x.supplierId), reason: reason + (sameTheme ? ', matches your theme' : ''), score: (sameColor ? 3 : 0) + (sameTheme ? 2 : 0) + (x.rating || 0), unit: Pricing.unitPrice(x, Math.max.apply(null, dates.map(d => Number(days[d]) || 1))) });
    });
    Store.where('packages', k => Services.isListed('package', k) && k.items.some(it => (Store.product(it.productId) || {}).categoryId === p.categoryId)).forEach(k => {
      if (dates.every(d => Inventory.packageAvailableOn(k.id, d) >= 1)) out.push({ pkg: k, supplier: Store.supplier(k.supplierId), reason: 'Package that includes ' + (Store.category(p.categoryId) || {}).name.toLowerCase(), score: 2 });
    });
    return out.sort((a, b) => b.score - a.score).slice(0, 5);
  }

  /** Free-text marketplace search across existing listings, using interpreted filters. */
  function search(text) {
    const f = interpret(text);
    const words = String(text || '').toLowerCase().split(/\W+/).filter(w => w.length > 2);
    const dates = datesOf(f);
    const res = liveProducts().map(p => {
      const sup = Store.supplier(p.supplierId);
      const hay = (p.name + ' ' + p.description + ' ' + sup.name + ' ' + (p.colors || []).join(' ') + ' ' + (p.themes || []).join(' ')).toLowerCase();
      let s = U.sum(words, w => hay.includes(w) ? 1 : 0) + score(p, f);
      if (f.items.length) s += f.items.includes(p.categoryId) ? 6 : -6;
      return { p, s, avail: Inventory.minAvailable(p.id, dates) };
    }).filter(x => x.s > 4).sort((a, b) => b.s - a.s);
    return { filters: f, results: res };
  }

  /* ---------- Assistant chatbot ---------- */
  const FORBIDDEN = /(accept|approve|decline|cancel|reserve|book it|verify|discount|lower (the )?price|bawasan|tawad|change (the )?price|mark (as )?paid|refund me|i-accept|i-cancel)/i;
  function findProducts(t) {
    const cat = Object.entries(ITEMS).filter(([, w]) => has(t, w)).map(([c]) => c);
    const sup = Store.all('suppliers').find(s => s.status === 'approved' && t.includes(s.name.toLowerCase().split(' ')[0].toLowerCase()) && s.name.split(' ')[0].length > 3);
    let list = liveProducts();
    if (sup) list = list.filter(p => p.supplierId === sup.id);
    if (cat.length) list = list.filter(p => cat.includes(p.categoryId));
    const f = interpret(t);
    if (f.colors.length) { const c = list.filter(p => f.colors.some(x => (p.colors || []).includes(x))); if (c.length) list = c; }
    return { list: (cat.length || sup) ? list : [], sup, f };
  }
  function link(path, text) { return '<a href="' + U.url(path) + '">' + U.esc(text) + '</a>'; }

  function chat(message, session) {
    const t = ' ' + String(message || '').toLowerCase().trim() + ' ';
    const user = session && session.user;
    const reply = (html, chips) => ({ html, chips: chips || [] });
    if (!t.trim()) return reply('Please type a question. 😊');
    if (FORBIDDEN.test(t) && !/how|paano|policy|can i|pwede/.test(t)) return reply("I can't do that — I'm an assistant only. Accepting, cancelling, reserving, changing prices and verifying payments are always done by you, the supplier or our cashier. I can explain how to do it or show you where: " + link('customer/rentals.html', 'My Rentals') + '.');
    if (/^\s*(hi|hello|hey|kumusta|kamusta|good (morning|afternoon|evening))\b/.test(t)) return reply('Hi' + (user ? ' ' + U.esc(user.name.split(' ')[0]) : '') + '! 🎉 I can find party supplies, check availability, explain payments, or track your requests. What are you planning?', ['Plan a party for 50 guests', 'Monoblock chair prices', 'My request status']);

    // Request status — only the signed-in customer's own records.
    const prm = /pr-\d{3,5}/i.exec(t);
    if (prm || /(status|my request|my rental|request ko|order ko|track)/.test(t)) {
      if (!user) return reply('Please ' + link('login.html', 'log in') + ' so I can look up your own rental requests.');
      if (prm) {
        const r = Store.request(prm[0].toUpperCase());
        if (!r || r.customerId !== user.id) return reply("I can't find " + U.esc(prm[0].toUpperCase()) + ' in your account. For privacy I can only show your own requests.');
        const f = Finance.summary(r);
        return reply('<b>' + r.id + '</b> — ' + U.esc(r.eventName) + '<br>Status: <b>' + Services.statusLabel(r.status) + '</b><br>Payment: <b>' + f.status + '</b> · Balance ' + U.money(f.balance) + (f.next ? '<br>Next: ' + U.esc(f.next.label) + ' due ' + U.fmtDate(f.next.due) : '') + '<br>' + link('customer/rental.html?id=' + r.id, 'Open details →'));
      }
      const mine = Store.where('requests', r => r.customerId === user.id && !['completed', 'declined', 'cancelled'].includes(r.status)).sort((a, b) => a.start.localeCompare(b.start));
      if (!mine.length) return reply('You have no active rental requests right now. ' + link('customer/browse.html', 'Browse supplies →'));
      return reply('Your active requests:<br>' + mine.map(r => '• ' + link('customer/rental.html?id=' + r.id, r.id) + ' ' + U.esc(r.eventName) + ' — <b>' + Services.statusLabel(r.status) + '</b>, ' + Finance.summary(r).status).join('<br>'));
    }
    if (/(become a supplier|start selling|magbenta|sell my|mag-supplier|rent out my|open a shop)/.test(t)) return reply('You can become a supplier with the same account: open your profile menu → <b>Start Selling</b>, complete the 6-step form, and once approved you can switch between Customer and Supplier Mode. ' + link('customer/start-selling.html', 'Start Selling →'));
    if (/(how (do|to) (i )?rent|paano (mag)?renta|paano umupa|how does it work|steps)/.test(t)) return reply('Renting on PartyRent:<br>1. Find items or a package and pick dates & quantities (you can set a different quantity per day).<br>2. Add to your Rental List and submit a request.<br>3. The supplier reviews and accepts (it stays <b>Pending</b> until then).<br>4. Pay according to the supplier\'s payment policy — payments count once verified by our cashier.<br>5. Receive the items, enjoy, then return them for inspection.');
    if (/(plan|planning|prepare|help me|tulungan|mag-?organize).*(party|event|wedding|debut|birthday|kasal|binyag|reunion)|\d+\s*(guests|bisita|pax)/.test(t)) {
      return reply('Let me build a checklist from real listings. ' + link('customer/ai.html?q=' + encodeURIComponent(message), 'Open the AI Event Planner with your description →'));
    }
    const { list, sup, f } = findProducts(t);
    if (/(deposit|security)/.test(t)) return reply('A <b>security deposit</b> is refundable and kept separate from your rental charges. After the items are returned and inspected, the supplier refunds it in full or deducts repair/replacement fees for damaged or missing items — you will see the damage report first.' + (sup ? '<br>' + U.esc(sup.name) + ': ' + (sup.policy.depositType === 'percent' ? sup.policy.depositValue + '% of rental items' : U.money(sup.policy.depositValue)) + '.' : ''));
    if (/(pay|payment|bayad|down ?payment|dp|reservation fee|installment|hulugan|gcash|bank|cod|cash)/.test(t)) {
      if (sup) return reply('<b>' + U.esc(sup.name) + '</b> accepts ' + sup.methods.map(m => Finance.METHOD_LABEL[m]).join(', ') + '.<br>' + Finance.plansOf(sup).map(k => '• <b>' + Finance.PLAN_LABEL[k] + '</b>: ' + U.esc(Finance.planSummary(sup, k))).join('<br>'));
      return reply('Each supplier sets its own payment rules. PartyRent has three plans: the <b>Standard Payment Plan</b> (reservation fee + down payment + final balance), the <b>Full Payment Plan</b> (100% at once) and the <b>Installment Payment Plan</b> (reservation fee + down payment + installments you propose, approved by the supplier). The reservation fee and down payment are always credited to your total — never added on top — and everything must be paid by the event date. Bank transfers need a reference number and proof; they count once the shop verifies them. Ask me about a specific shop, e.g. "Happy Moments payment policy".', ['Happy Moments payment policy', 'Elegant Occasion payment policy']);
    }
    if (/(deliver|delivery|pickup|pick up|hatid|kunin)/.test(t)) {
      const show = sup ? [sup] : Store.where('suppliers', s => s.status === 'approved');
      const lbl = { both: 'Delivery & Pickup', delivery: 'Delivery only', pickup: 'Pickup only' };
      return reply(show.map(s => '• <b>' + U.esc(s.name) + '</b> (' + U.esc(s.city) + '): ' + lbl[s.fulfillment] + (s.fulfillment !== 'pickup' ? ', delivery from ' + U.money(Math.min.apply(null, [s.deliveryFee || 0].concat((s.deliveryZones || []).map(z => z.fee)))) + ' per location' : '')).join('<br>'));
    }
    if (/(cancel|cancellation|ikansela)/.test(t)) return reply('You can cancel a <b>Pending</b> request anytime. For accepted requests, each supplier has a free-cancellation window (for example, 3 days before the rental). Open the request in ' + link('customer/rentals.html', 'My Rentals') + ' and use <b>Cancel Request</b> to see the exact policy before confirming.');
    if (list.length && /(available|availability|may available|meron|libre|free on|in stock|stock)/.test(t)) {
      const dates = f.start ? U.range(f.start, f.end) : [U.today()];
      const q = f.guests || (/(\d+)\s*(pcs|pieces|piraso|units|chairs|tables|upuan|mesa)/.exec(t) || [])[1];
      return reply('Availability for ' + U.fmtRange(dates[0], dates[dates.length - 1]) + (q ? ' (need ' + q + ')' : '') + ':<br>' + list.slice(0, 5).map(p => { const a = Inventory.minAvailable(p.id, dates); return '• ' + link('customer/product.html?id=' + p.id, p.name) + ' — ' + U.esc(Store.supplier(p.supplierId).name) + ': <b>' + a + '</b> available' + (q ? (a >= q ? ' ✅' : ' ❌') : ''); }).join('<br>') + (f.start ? '' : '<br><span class="muted">Tip: add a date, e.g. "chairs available on Nov 20".</span>'));
    }
    if (list.length) {
      return reply('Here is what I found on PartyRent:<br>' + list.slice(0, 5).map(p => '• ' + link('customer/product.html?id=' + p.id, p.name) + ' — ' + U.esc(Store.supplier(p.supplierId).name) + ': <b>' + U.money(Pricing.basePrice(p)) + '</b>/' + U.esc(p.unit.split('/')[0].trim()) + (p.tiers.length > 1 ? ' (as low as ' + U.money(Pricing.lowestPrice(p)) + ' in bulk)' : '')).join('<br>') + (list.length > 5 ? '<br>' + link('customer/browse.html?category=' + list[0].categoryId, 'See all ' + list.length + ' →') : ''));
    }
    if (sup) return reply('<b>' + U.esc(sup.name) + '</b> — ' + U.esc(sup.city) + ', rated ' + sup.rating + '★. ' + U.esc(sup.description) + '<br>' + link('customer/supplier.html?id=' + sup.id, 'Visit shop →'));
    return reply("I'm not sure about that, and I don't want to guess. I can only answer using PartyRent's listings, supplier policies and your own requests. Try asking about prices, availability, payments, delivery or your request status — or contact support at " + U.esc(Store.settings().supportEmail) + '.', ['How do I rent?', 'Tiffany chair prices', 'Delivery options']);
  }

  g.AI = { interpret, plan, candidates, alternatives, search, chat, ITEMS, EVENTS, COLORS, THEMES, label: 'PartyRent Assist (demo AI · rule-based, runs in your browser)' };
})(window);
