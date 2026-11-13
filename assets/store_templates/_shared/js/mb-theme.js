// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* mb-theme.js — shared storefront light/dark theme engine.
 *
 * Loaded by store_templates/layout.blade.php AFTER window.MB_STORE is set.
 * Themes own their palettes (dark tokens scoped `.theme[data-theme="dark"]` +
 * the auto media block `.theme:not([data-theme="light"])`); this engine only
 * persists the diner's choice, applies the attribute on the THEME ROOT element,
 * and wires any `[data-mb-theme-toggle]` buttons.
 *
 * Modes: 'auto' | 'light' | 'dark'. 'auto' = attribute removed (the theme's
 * prefers-color-scheme media block decides). Persisted store-scoped as
 * `mb_theme_<MB_STORE>`; legacy per-theme keys (orbit `orbit-theme-<slug>`,
 * aurora `au-theme-<slug>`) are read as fallbacks so existing diners keep
 * their choice, then migrated forward on the next set().
 *
 * FOUC contract: a tiny inline snippet in layout.blade.php stamps
 * `data-mb-theme` on <html> before first paint; every theme's dark block also
 * matches `html[data-mb-theme="dark"] .theme`. init() moves the choice onto
 * the theme root and clears the html attribute.
 *
 * API: window.mbTheme = { init({rootSel, legacyKeys}), set(mode), get(), effective() }
 */
(function (w) {
  'use strict';
  var d = w.document;
  var scope = String(w.MB_STORE || (w.location && w.location.pathname) || 'default');
  var KEY = 'mb_theme_' + scope;
  var root = null;          /* theme root element, resolved in init() */
  var legacy = ['theme'];   /* extra localStorage keys to read (never written) */
  var mq = w.matchMedia ? w.matchMedia('(prefers-color-scheme: dark)') : null;

  function load() {
    try {
      var v = w.localStorage.getItem(KEY);
      if (v === 'light' || v === 'dark' || v === 'auto') return v;
      for (var i = 0; i < legacy.length; i++) {
        v = w.localStorage.getItem(legacy[i]);
        if (v === 'light' || v === 'dark' || v === 'auto') return v;
      }
    } catch (e) { /* storage disabled — ignore */ }
    return 'auto';
  }

  function get() { return load(); }

  /* the mode actually in effect right now: explicit choice, else OS pref */
  function effective() {
    var m = load();
    if (m === 'light' || m === 'dark') return m;
    return (mq && mq.matches) ? 'dark' : 'light';
  }

  function apply(mode) {
    if (!root) return;
    if (mode === 'light' || mode === 'dark') root.setAttribute('data-theme', mode);
    else root.removeAttribute('data-theme');
    /* first-paint attr is superseded once the root carries the real one */
    d.documentElement.removeAttribute('data-mb-theme');
    refreshToggles();
  }

  function set(mode) {
    if (mode !== 'light' && mode !== 'dark' && mode !== 'auto') return;
    try { w.localStorage.setItem(KEY, mode); } catch (e) {}
    apply(mode);
  }

  function refreshToggles() {
    var dark = effective() === 'dark';
    var btns = d.querySelectorAll('[data-mb-theme-toggle]');
    for (var i = 0; i < btns.length; i++) btns[i].setAttribute('aria-pressed', dark ? 'true' : 'false');
  }

  function onToggleClick() { set(effective() === 'dark' ? 'light' : 'dark'); }

  function init(opts) {
    opts = opts || {};
    root = (opts.rootSel && d.querySelector(opts.rootSel)) || d.documentElement;
    if (opts.legacyKeys && opts.legacyKeys.length) legacy = opts.legacyKeys.concat(legacy);
    apply(load());
    var btns = d.querySelectorAll('[data-mb-theme-toggle]');
    for (var i = 0; i < btns.length; i++) {
      if (!btns[i].__mbTheme) {
        btns[i].__mbTheme = true;
        btns[i].addEventListener('click', onToggleClick);
      }
    }
    if (mq) {
      var onChange = function () { if (load() === 'auto') apply('auto'); };
      if (mq.addEventListener) mq.addEventListener('change', onChange);
      else if (mq.addListener) mq.addListener(onChange); /* old Safari */
    }
  }

  w.mbTheme = { init: init, set: set, get: get, effective: effective };
})(window);
