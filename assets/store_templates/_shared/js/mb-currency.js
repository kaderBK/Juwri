// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* mb-currency.js — shared storefront currency-choice persistence.
 *
 * Companion to mb-money.js. Loaded by store_templates/layout.blade.php BEFORE the
 * theme bundle. Themes keep their own price-render logic; this only persists and
 * replays the diner's chosen display currency, store-scoped so one store's choice
 * never leaks onto another on the same origin (the bug scoop's un-scoped
 * "scoop_" key had, and vivid avoided with a slug-scoped key).
 *
 * Contract each theme follows (see rev/currency-switcher-parity.md):
 *   - the switcher lives in a container with id="curSeg"
 *   - each option is a <button data-cur="CODE" data-rate="N">
 *   - the theme's existing click handler applies the currency AND calls
 *       window.mbCurrency.save(code, rate)
 *   - after the first price render, the theme calls
 *       window.mbCurrency.restore('#curSeg')
 *     which clicks the saved option, replaying the theme's own handler.
 *
 * Store scope comes from window.MB_STORE (the post id, set in layout). Falls back
 * to the path so it still scopes if that global is ever absent.
 */
(function (w) {
  'use strict';
  var scope = String(w.MB_STORE || (w.location && w.location.pathname) || 'default');
  var KEY = 'mb_cur_' + scope;
  var RKEY = 'mb_curRate_' + scope;

  function save(code, rate) {
    if (!code) return;
    try {
      w.localStorage.setItem(KEY, String(code));
      w.localStorage.setItem(RKEY, String(rate == null ? 1 : rate));
    } catch (e) { /* storage disabled / private mode — ignore */ }
  }

  function load() {
    try {
      var code = w.localStorage.getItem(KEY);
      if (!code) return null;
      var rate = parseFloat(w.localStorage.getItem(RKEY));
      return { code: code, rate: isNaN(rate) ? 1 : rate };
    } catch (e) { return null; }
  }

  function clear() {
    try { w.localStorage.removeItem(KEY); w.localStorage.removeItem(RKEY); } catch (e) {}
  }

  /* Replay the saved choice by clicking its option, so the theme's own handler
   * does the apply. No-op when nothing saved or the option isn't present
   * (e.g. the store dropped that currency). Returns true if a click fired. */
  function restore(containerSel) {
    var saved = load();
    if (!saved) return false;
    var root = typeof containerSel === 'string'
      ? document.querySelector(containerSel)
      : (containerSel || document);
    if (!root) return false;
    var btn = root.querySelector('[data-cur="' + (w.CSS && CSS.escape ? CSS.escape(saved.code) : saved.code) + '"]');
    if (!btn) return false;
    btn.click();
    return true;
  }

  w.mbCurrency = { save: save, load: load, clear: clear, restore: restore, scope: scope };
})(window);
