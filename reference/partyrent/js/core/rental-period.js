/* =========================================================
   PartyRent — rental-period.js
   The customer's selected rental period (start/end dates),
   shared by browsing, product details, packages, Rental List
   and checkout so the same dates follow the customer around.
   Tied to the signed-in user (or the guest); cleared at login
   (the post-login prompt asks again) and at logout.
   Availability itself is always calculated by Inventory.
   ========================================================= */
(function (g) {
  'use strict';
  const KEY = 'partyrent_rental_period';
  const ASK = 'partyrent_ask_dates';
  let mem = null, askMem = null;

  function owner() { try { const s = JSON.parse(localStorage.getItem('partyrent_session') || 'null'); return s ? s.userId : null; } catch (e) { return null; } }
  function read() { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return mem; } }
  function write(v) { mem = v; try { if (v) localStorage.setItem(KEY, JSON.stringify(v)); else localStorage.removeItem(KEY); } catch (e) { /* memory only */ } }

  /** Validate a period. Returns an error message or null. */
  function validate(start, end) {
    if (!start || !end) return 'Select both a start date and an end date.';
    if (start < U.today()) return 'The start date cannot be in the past.';
    if (end < start) return 'The end date must be on or after the start date.';
    const max = (Store.settings() || {}).maxRentalDays || 30;
    if (U.diffDays(start, end) + 1 > max) return 'Rental periods can be at most ' + max + ' days.';
    return null;
  }
  /** Current period for this user, or null. Past/invalid periods are dropped. */
  function get() {
    const v = read();
    if (!v || v.owner !== owner() || !v.start || !v.end) return null;
    if (v.start < U.today()) { write(null); return null; }
    return { start: v.start, end: v.end, startTime: v.startTime || '', endTime: v.endTime || '' };
  }
  function set(start, end) { if (validate(start, end)) return false; const v = read(); write({ owner: owner(), start, end, startTime: v && v.owner === owner() ? v.startTime : '', endTime: v && v.owner === owner() ? v.endTime : '' }); return true; }
  /** Remember the customer's preferred rental start/end time (used as the default on product pages). */
  function setTimes(startTime, endTime) { const v = read(); if (!v || v.owner !== owner()) return false; v.startTime = startTime; v.endTime = endTime; write(v); return true; }
  function clear() { write(null); }
  const days = p => (p ? U.diffDays(p.start, p.end) + 1 : 0);
  const dates = () => { const p = get(); return p ? U.range(p.start, p.end) : []; };
  const durationLabel = n => n + ' Day' + (n === 1 ? '' : 's');
  /** "12/12/2026 - 12/15/2026" */
  const rangeLabel = (start, end) => U.fmtMDY(start) + ' - ' + U.fmtMDY(end);

  /* post-login "how do you want to browse?" prompt — shown once per login */
  function requestPrompt(userId, next) { const v = { userId, next: next || '' }; askMem = v; try { localStorage.setItem(ASK, JSON.stringify(v)); } catch (e) { /* memory */ } }
  function takePrompt(userId) {
    let v = askMem; try { v = JSON.parse(localStorage.getItem(ASK) || 'null'); } catch (e) { /* memory */ }
    if (!v || v.userId !== userId) return null;
    askMem = null; try { localStorage.removeItem(ASK); } catch (e) { /* ignore */ }
    return v;
  }
  function cancelPrompt() { askMem = null; try { localStorage.removeItem(ASK); } catch (e) { /* ignore */ } }

  g.RentalPeriod = { get, set, setTimes, clear, validate, days, dates, durationLabel, rangeLabel, requestPrompt, takePrompt, cancelPrompt };
})(window);
