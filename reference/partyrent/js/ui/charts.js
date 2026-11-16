/* =========================================================
   PartyRent — charts.js
   Lightweight SVG charts with hover tooltips.
   Single-series charts use one brand hue; categorical
   charts use a fixed, validated order with a legend + values.
   ========================================================= */
(function (g) {
  'use strict';
  const CAT = ['#7C3AED', '#E11D74', '#F59E0B', '#0D9488', '#2563EB'];
  let tt;
  function tip() { if (!tt) { tt = document.createElement('div'); tt.className = 'tt'; tt.style.cssText = 'position:fixed;pointer-events:none;background:#1E1433;color:#fff;font-size:.78rem;padding:6px 10px;border-radius:8px;z-index:3000;white-space:nowrap;display:none;box-shadow:0 6px 18px rgba(0,0,0,.2)'; document.body.appendChild(tt); } return tt; }
  function bindTips(el) {
    el.querySelectorAll('[data-tip]').forEach(n => {
      n.addEventListener('mousemove', e => { const t = tip(); t.innerHTML = n.dataset.tip; t.style.display = 'block'; t.style.left = Math.min(window.innerWidth - t.offsetWidth - 8, e.clientX + 12) + 'px'; t.style.top = (e.clientY - 34) + 'px'; });
      n.addEventListener('mouseleave', () => { tip().style.display = 'none'; });
    });
  }
  const fmt = (v, money) => money ? U.moneyShort(v) : String(Math.round(v * 10) / 10);
  function niceMax(v) { if (v <= 0) return 1; const p = Math.pow(10, Math.floor(Math.log10(v))); const n = v / p; return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p; }

  /** Vertical bars. data: [{label, value}] */
  function bars(el, data, o) {
    o = o || {};
    const W = 640, H = 240, pl = 48, pb = 28, pt = 12, pr = 8;
    const max = niceMax(Math.max.apply(null, data.map(d => d.value).concat([0])));
    const bw = (W - pl - pr) / Math.max(1, data.length);
    let s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + U.esc(o.label || 'Bar chart') + '">';
    for (let i = 0; i <= 4; i++) { const y = pt + (H - pt - pb) * (1 - i / 4); s += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + y + '" y2="' + y + '" stroke="#ECE4F1"/><text x="' + (pl - 6) + '" y="' + (y + 4) + '" font-size="11" fill="#6E6683" text-anchor="end">' + fmt(max * i / 4, o.money) + '</text>'; }
    data.forEach((d, i) => {
      const h = (H - pt - pb) * (d.value / max); const x = pl + i * bw + bw * 0.18; const w = Math.max(4, bw * 0.64); const y = H - pb - h;
      s += '<g data-tip="<b>' + U.esc(d.label) + '</b><br>' + fmt(d.value, o.money) + '"><rect x="' + (pl + i * bw) + '" y="' + pt + '" width="' + bw + '" height="' + (H - pt - pb) + '" fill="transparent"/>' +
        (h > 0 ? '<path d="M' + x + ',' + (H - pb) + 'V' + (y + Math.min(4, h)) + 'q0,-4 4,-4h' + (w - 8) + 'q4,0 4,4V' + (H - pb) + 'z" fill="' + (o.color || '#E11D74') + '"/>' : '') + '</g>';
      if (data.length <= 12 || i % 2 === 0) s += '<text x="' + (pl + i * bw + bw / 2) + '" y="' + (H - 9) + '" font-size="11" fill="#6E6683" text-anchor="middle">' + U.esc(d.label) + '</text>';
    });
    s += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + (H - pb) + '" y2="' + (H - pb) + '" stroke="#DED3E6"/></svg>';
    el.classList.add('chart'); el.innerHTML = s; bindTips(el);
  }

  /** Single-series line with crosshair tooltip. */
  function line(el, data, o) {
    o = o || {};
    const W = 640, H = 240, pl = 52, pb = 28, pt = 14, pr = 12;
    const max = niceMax(Math.max.apply(null, data.map(d => d.value).concat([0])));
    const step = (W - pl - pr) / Math.max(1, data.length - 1);
    const X = i => pl + i * step, Y = v => pt + (H - pt - pb) * (1 - v / max);
    let s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + U.esc(o.label || 'Line chart') + '">';
    for (let i = 0; i <= 4; i++) { const y = pt + (H - pt - pb) * (1 - i / 4); s += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + y + '" y2="' + y + '" stroke="#ECE4F1"/><text x="' + (pl - 6) + '" y="' + (y + 4) + '" font-size="11" fill="#6E6683" text-anchor="end">' + fmt(max * i / 4, o.money) + '</text>'; }
    const pts = data.map((d, i) => X(i) + ',' + Y(d.value)).join(' ');
    s += '<polygon points="' + pl + ',' + (H - pb) + ' ' + pts + ' ' + X(data.length - 1) + ',' + (H - pb) + '" fill="' + (o.color || '#7C3AED') + '" opacity=".08"/>';
    s += '<polyline points="' + pts + '" fill="none" stroke="' + (o.color || '#7C3AED') + '" stroke-width="2" stroke-linejoin="round"/>';
    data.forEach((d, i) => {
      s += '<g data-tip="<b>' + U.esc(d.label) + '</b><br>' + fmt(d.value, o.money) + '"><rect x="' + (X(i) - step / 2) + '" y="' + pt + '" width="' + step + '" height="' + (H - pt - pb) + '" fill="transparent"/><circle cx="' + X(i) + '" cy="' + Y(d.value) + '" r="4" fill="#fff" stroke="' + (o.color || '#7C3AED') + '" stroke-width="2"/></g>';
      if (data.length <= 12 || i % 2 === 0) s += '<text x="' + X(i) + '" y="' + (H - 9) + '" font-size="11" fill="#6E6683" text-anchor="middle">' + U.esc(d.label) + '</text>';
    });
    s += '</svg>'; el.classList.add('chart'); el.innerHTML = s; bindTips(el);
  }

  /** Donut with legend + values (categorical, fixed order). data: [{label,value}] */
  function donut(el, data, o) {
    o = o || {};
    const tot = U.sum(data, d => d.value) || 1; const R = 60, r = 38, C = 70;
    let a0 = -Math.PI / 2, s = '<svg viewBox="0 0 140 140" style="width:150px;flex:none" role="img" aria-label="' + U.esc(o.label || 'Distribution') + '">';
    const shown = data.filter(d => d.value > 0);
    shown.forEach((d, i) => {
      const a1 = a0 + 2 * Math.PI * d.value / tot; const big = a1 - a0 > Math.PI ? 1 : 0;
      const col = d.color || CAT[data.indexOf(d) % CAT.length];
      const p = (ang, rad) => (C + rad * Math.cos(ang)) + ',' + (C + rad * Math.sin(ang));
      if (shown.length === 1) s += '<circle cx="70" cy="70" r="' + ((R + r) / 2) + '" fill="none" stroke="' + col + '" stroke-width="' + (R - r) + '" data-tip="<b>' + U.esc(d.label) + '</b><br>' + d.value + '"/>';
      else s += '<path d="M' + p(a0, R) + 'A' + R + ',' + R + ' 0 ' + big + ' 1 ' + p(a1, R) + 'L' + p(a1, r) + 'A' + r + ',' + r + ' 0 ' + big + ' 0 ' + p(a0, r) + 'z" fill="' + col + '" stroke="#fff" stroke-width="2" data-tip="<b>' + U.esc(d.label) + '</b><br>' + fmt(d.value, o.money) + ' (' + Math.round(d.value / tot * 100) + '%)"/>';
      a0 = a1;
    });
    s += '<text x="70" y="68" text-anchor="middle" font-size="20" font-weight="800" fill="#1E1433">' + (o.money ? U.moneyShort(tot) : tot) + '</text><text x="70" y="85" text-anchor="middle" font-size="10" fill="#6E6683">' + U.esc(o.center || 'total') + '</text></svg>';
    const legend = '<div class="donut-legend">' + data.map((d, i) => '<span><i style="background:' + (d.color || CAT[i % CAT.length]) + '"></i>' + U.esc(d.label) + ' <b style="margin-left:auto;padding-left:10px">' + fmt(d.value, o.money) + '</b></span>').join('') + '</div>';
    el.innerHTML = '<div class="donut-wrap">' + s + legend + '</div>'; bindTips(el);
  }

  /** Horizontal ranked bars (HTML). */
  function hbars(el, data, o) {
    o = o || {};
    const max = Math.max.apply(null, data.map(d => d.value).concat([1]));
    el.innerHTML = data.length ? data.map(d => '<div class="hbar" title="' + U.esc(d.label) + ': ' + fmt(d.value, o.money) + '"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + U.esc(d.label) + '</span><div class="tr"><span style="width:' + (d.value / max * 100) + '%;background:' + (o.color || '#E11D74') + '"></span></div><b class="right">' + fmt(d.value, o.money) + '</b></div>').join('') : '<div class="muted small">No data yet</div>';
  }

  /** Helper: last N months labels + keys. */
  function months(n) { const out = []; const d = U.parse(U.today()); d.setDate(1); for (let i = n - 1; i >= 0; i--) { const x = new Date(d.getFullYear(), d.getMonth() - i, 1); out.push({ key: x.getFullYear() + '-' + U.pad(x.getMonth() + 1), label: U.MONTHS[x.getMonth()].slice(0, 3) }); } return out; }

  g.Charts = { bars, line, donut, hbars, months, CAT };
})(window);
