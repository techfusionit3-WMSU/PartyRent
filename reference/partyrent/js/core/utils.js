/* =========================================================
   PartyRent — utils.js
   Formatting, date helpers, DOM helpers, navigation params.
   ========================================================= */
(function (g) {
  'use strict';

  const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const pesoShort = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 });
  const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  /** Dates are stored as ISO "YYYY-MM-DD" strings (local calendar days). */
  function pad(n) { return String(n).padStart(2, '0'); }
  function iso(d) { d = d instanceof Date ? d : new Date(d); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { if (s instanceof Date) return new Date(s.getFullYear(), s.getMonth(), s.getDate()); const [y, m, d] = String(s).slice(0, 10).split('-').map(Number); return new Date(y, m - 1, d); }
  function today() { return iso(new Date()); }
  function addDays(s, n) { const d = parse(s); d.setDate(d.getDate() + n); return iso(d); }
  /** Same day n months later (clamped to the month's last day). */
  function addMonths(s, n) { const d = parse(s); const day = d.getDate(); d.setDate(1); d.setMonth(d.getMonth() + n); const last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate(); d.setDate(Math.min(day, last)); return iso(d); }
  function diffDays(a, b) { return Math.round((parse(b) - parse(a)) / 86400000); }
  function range(start, end) { const out = []; if (!start || !end) return out; let d = start; let guard = 0; while (d <= end && guard < 400) { out.push(d); d = addDays(d, 1); guard++; } return out; }
  function fmtDate(s, opts) { if (!s) return '—'; const d = parse(s); return d.toLocaleDateString('en-PH', Object.assign({ month: 'short', day: 'numeric', year: 'numeric' }, opts || {})); }
  /** MM/DD/YYYY */
  function fmtMDY(s) { if (!s) return ''; const d = parse(s); return pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + '/' + d.getFullYear(); }
  function fmtDay(s) { const d = parse(s); return DOW[d.getDay()] + ', ' + MONTHS[d.getMonth()].slice(0, 3) + ' ' + d.getDate(); }
  function fmtDateTime(ts) { if (!ts) return '—'; const d = new Date(ts); return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) + ' · ' + d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }); }
  function fmtRange(a, b) { if (!a) return '—'; if (!b || a === b) return fmtDate(a); const da = parse(a), db = parse(b); if (da.getFullYear() === db.getFullYear()) { if (da.getMonth() === db.getMonth()) return MONTHS[da.getMonth()].slice(0, 3) + ' ' + da.getDate() + '–' + db.getDate() + ', ' + da.getFullYear(); return fmtDate(a, { year: undefined }) + ' – ' + fmtDate(b); } return fmtDate(a) + ' – ' + fmtDate(b); }
  function timeAgo(ts) { const s = Math.floor((Date.now() - new Date(ts).getTime()) / 1000); if (s < 60) return 'just now'; const m = Math.floor(s / 60); if (m < 60) return m + 'm ago'; const h = Math.floor(m / 60); if (h < 24) return h + 'h ago'; const d = Math.floor(h / 24); if (d < 30) return d + 'd ago'; return fmtDate(iso(new Date(ts))); }
  function money(n) { return peso.format(Math.round((Number(n) || 0) * 100) / 100); }
  function moneyShort(n) { return pesoShort.format(Math.round(Number(n) || 0)); }
  function round2(n) { return Math.round((Number(n) || 0) * 100) / 100; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function initials(name) { return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join(''); }
  function uid(prefix) { return (prefix || 'id') + '-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
  function sum(arr, fn) { return arr.reduce((a, x) => a + (fn ? fn(x) : x), 0); }
  function groupBy(arr, fn) { return arr.reduce((m, x) => { const k = fn(x); (m[k] = m[k] || []).push(x); return m; }, {}); }
  function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }
  function cap(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); }
  function stars(r) { r = Math.round((r || 0) * 2) / 2; let out = ''; for (let i = 1; i <= 5; i++) out += r >= i ? '★' : (r >= i - .5 ? '⯪' : '☆'); return out.replace(/⯪/g, '★'); }

  /* DOM */
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  function on(root, evt, sel, fn) { root.addEventListener(evt, e => { const t = e.target.closest(sel); if (t && root.contains(t)) fn(e, t); }); }

  /* Navigation: links are built relative to the page's base (data-base on <body>). */
  function base() { return (document.body && document.body.dataset.base) || ''; }
  function url(path, params) {
    let u = base() + path;
    if (params) { const q = Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('&'); if (q) u += '?' + q; }
    return u;
  }
  /** Read page params: query string first, then a sessionStorage hand-off (for hosts that strip query strings). */
  function params() {
    const out = {};
    new URLSearchParams(location.search).forEach((v, k) => { out[k] = v; });
    if (!Object.keys(out).length) {
      try { const h = JSON.parse(sessionStorage.getItem('pr_nav') || 'null'); if (h && location.pathname.endsWith(h.page)) Object.assign(out, h.params); } catch (e) { /* storage unavailable */ }
    }
    if (location.hash && !out.id) out.hash = location.hash.slice(1);
    return out;
  }
  function go(path, p) {
    try { sessionStorage.setItem('pr_nav', JSON.stringify({ page: path.split('/').pop(), params: p || {} })); } catch (e) { /* ignore */ }
    location.href = url(path, p);
  }
  // Store params for plain <a> clicks too, so detail pages still work if the host strips the query string.
  document.addEventListener('click', e => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target === '_blank') return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || /^[a-z]+:/i.test(href)) return;
    const [path, q] = href.split('?');
    const p = {}; if (q) new URLSearchParams(q.split('#')[0]).forEach((v, k) => { p[k] = v; });
    try { sessionStorage.setItem('pr_nav', JSON.stringify({ page: path.split('/').pop(), params: p })); } catch (err) { /* ignore */ }
  }, true);

  /** Read a File into a compressed data URL (keeps localStorage small). */
  function readImage(file, maxW) {
    maxW = maxW || 640;
    return new Promise((resolve, reject) => {
      if (!file) return reject(new Error('No file'));
      if (!/^image\//.test(file.type)) return reject(new Error('Please choose an image file (JPG or PNG).'));
      if (file.size > 8 * 1024 * 1024) return reject(new Error('Image is larger than 8 MB.'));
      const fr = new FileReader();
      fr.onerror = () => reject(new Error('Could not read file'));
      fr.onload = () => {
        const img = new Image();
        img.onload = () => {
          const scale = Math.min(1, maxW / img.width);
          const c = document.createElement('canvas');
          c.width = Math.round(img.width * scale); c.height = Math.round(img.height * scale);
          c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
          resolve(c.toDataURL('image/jpeg', .72));
        };
        img.onerror = () => reject(new Error('Unsupported image'));
        img.src = fr.result;
      };
      fr.readAsDataURL(file);
    });
  }

  g.U = { fmtMDY, iso, parse, today, addDays, addMonths, diffDays, range, fmtDate, fmtDay, fmtDateTime, fmtRange, timeAgo, money, moneyShort, round2, esc, initials, uid, sum, groupBy, clamp, cap, stars, $, $$, on, url, params, go, base, readImage, MONTHS, DOW, pad };
})(window);
