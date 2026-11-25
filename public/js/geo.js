/* =========================================================
   PartyRent — location services shared by checkout (delivery address) and browsing (where you rent / stores map).
   One implementation for both:
     PRGeo.search(q)            address / landmark / city search (server: /api/geo/search, cached)
     PRGeo.reverse(lat, lng)    the address at a pin (server: /api/geo/reverse, cached; nearest city if unsure)
     PRGeo.locate()             the device location, only after the customer asks for it
     PRGeo.attachSearch(input, onPick)   autocomplete list under an input (debounced, keyboard, loading, errors)
     PRGeo.pinMap(el, opts)     Leaflet map with one draggable pin; tap / drag moves it and calls opts.onMove
   Needs window.PR (routes, map) and, for maps, Leaflet (partials/leaflet).
   ========================================================= */
(function () {
  'use strict';
  const R = () => window.PR.routes;
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const revCache = new Map();
  let ctl = null;

  async function search(q) {
    if (ctl) ctl.abort(); // only the latest query matters
    ctl = new AbortController();
    const r = await fetch(R().geoSearch + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: ctl.signal });
    if (r.status === 429) throw new Error('Too many searches in a moment. Wait a few seconds and try again.');
    if (!r.ok) throw new Error('Address search is not available right now. Choose your city below or move the pin.');
    return r.json();
  }

  async function reverse(lat, lng) {
    const k = (+lat).toFixed(5) + ',' + (+lng).toFixed(5);
    if (revCache.has(k)) return revCache.get(k); // the pin did not move: no new request
    const r = await fetch(R().geoReverse + '?lat=' + lat + '&lng=' + lng, { headers: { Accept: 'application/json' } });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.error || 'Could not look up this spot. Fill in the address below.');
    revCache.set(k, d);
    return d;
  }

  /** Device location; the caller explains why before calling. Friendly errors for denied / unavailable / slow. */
  function locate() {
    return new Promise((ok, no) => {
      if (!navigator.geolocation) return no(new Error('This browser cannot share its location. Search for your address instead.'));
      navigator.geolocation.getCurrentPosition(p => ok({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }), e => no(new Error(
        e.code === 1 ? 'Location permission was not given. You can search for your address instead.'
          : e.code === 3 ? 'Finding your location took too long. Try again, or search for your address.'
            : 'Your location is not available right now. Search for your address instead.')), { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
    });
  }

  /** Autocomplete under an input: results from search(); onPick(result). */
  function attachSearch(input, onPick) {
    const wrap = input.closest('.place-search') || input.parentNode;
    let list = wrap.querySelector('.place-sugg');
    if (!list) { list = document.createElement('ul'); list.className = 'place-sugg'; list.setAttribute('role', 'listbox'); list.hidden = true; wrap.appendChild(list); }
    let hits = [], cur = -1, timer = null;
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); cur = -1; };
    const show = html => { list.innerHTML = html; list.hidden = false; input.setAttribute('aria-expanded', 'true'); };
    const pick = h => { close(); input.value = h.label; onPick(h); };
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) return close();
      show('<li class="muted small" aria-disabled="true"><span class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:-2px"></span> Searching…</li>');
      timer = setTimeout(async () => {
        try {
          const d = await search(q);
          hits = d.results || []; cur = -1;
          if (input.value.trim() !== q) return;
          show(hits.length ? hits.map((h, i) => '<li role="option" data-i="' + i + '">' + esc(h.label) + '<span class="small muted">' + (h.precise ? 'Exact place' : 'City / area') + '</span></li>').join('')
            + (d.service === false ? '<li class="muted small" aria-disabled="true">Street search is unavailable right now; cities are still listed.</li>' : '')
            : '<li class="muted small" aria-disabled="true">No place matches "' + esc(q) + '". Try a landmark, street or barangay with the city.</li>');
        } catch (e) { if (e.name !== 'AbortError') show('<li class="muted small" aria-disabled="true">' + esc(e.message) + '</li>'); }
      }, 350);
    });
    input.addEventListener('keydown', e => {
      if (list.hidden || !hits.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); cur = (cur + (e.key === 'ArrowDown' ? 1 : -1) + hits.length) % hits.length; list.querySelectorAll('li[data-i]').forEach((li, i) => li.classList.toggle('on', i === cur)); }
      if (e.key === 'Enter') { e.preventDefault(); pick(hits[cur < 0 ? 0 : cur]); }
      if (e.key === 'Escape') close();
    });
    list.addEventListener('mousedown', e => { const li = e.target.closest('li[data-i]'); if (li) { e.preventDefault(); pick(hits[+li.dataset.i]); } });
    input.addEventListener('blur', () => setTimeout(close, 180));
  }

  /** Map with one pin. opts: { lat, lng, zoom, onMove(lat, lng) } — onMove after a drag or a tap on the map. */
  function pinMap(el, opts) {
    if (!window.L) { el.innerHTML = '<div class="small muted" style="padding:16px">The map is unavailable right now. Use the search and the fields below.</div>'; return null; }
    const cfg = window.PR.map;
    const map = window.L.map(el, { maxBounds: cfg.bounds, maxBoundsViscosity: 0.8, minZoom: 5 }).setView(opts.lat != null ? [opts.lat, opts.lng] : cfg.center, opts.lat != null ? (opts.zoom || 16) : cfg.zoom);
    window.L.tileLayer(cfg.tiles, { attribution: cfg.attribution, maxZoom: 19 }).addTo(map);
    if (window.ResizeObserver) new ResizeObserver(() => map.invalidateSize()).observe(el);
    let marker = null;
    const place = (lat, lng) => {
      if (!marker) { marker = window.L.marker([lat, lng], { draggable: true, autoPan: true }).addTo(map); marker.on('dragend', () => { const p = marker.getLatLng(); opts.onMove && opts.onMove(p.lat, p.lng); }); }
      else marker.setLatLng([lat, lng]);
    };
    if (opts.lat != null) place(opts.lat, opts.lng);
    map.on('click', e => { place(e.latlng.lat, e.latlng.lng); opts.onMove && opts.onMove(e.latlng.lat, e.latlng.lng); });
    return {
      map,
      setPin(lat, lng, zoom) { place(lat, lng); map.setView([lat, lng], zoom || Math.max(map.getZoom(), 16)); },
      setFaded(on) { if (marker) marker.setOpacity(on ? 0.45 : 1); },
      remove() { map.remove(); },
    };
  }

  window.PRGeo = { search, reverse, locate, attachSearch, pinMap, esc };
})();
