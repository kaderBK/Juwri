// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * turbo-compat.js — makes Turbo Drive coexist with the NexLink/Bootstrap/Alpine
 * stack. Loaded ONCE in <head> (never re-evaluated on Turbo visits).
 *
 * Strategy: the persistent library scripts carry data-turbo-eval="false" so they
 * load once; Bootstrap 5 dropdowns/tabs/collapse already use document-delegated
 * handlers and keep working across body swaps. This file (a) re-binds the few
 * per-element widgets NexLink attaches on DOMContentLoaded (tooltips, simplebar,
 * the sidebar toggler, Waves, owner tables) on every `turbo:load`, and (b) turns
 * Turbo form handling OFF so the app's hundreds of native POST/redirect forms
 * behave exactly as before in the browser.
 *
 * (c) Inside the owner's native app (Hotwire Native, <html data-owner-app="1">)
 * form handling is ON instead — see inAppForms() below and
 * rev/mobile-app/FORMS-AUDIT.md (server item S7).
 */
(function () {
  'use strict';

  var IN_APP = document.documentElement.getAttribute('data-owner-app') === '1';

  // Browser: forms stay native — only link navigation is accelerated. Set as
  // early as Turbo is available (it starts synchronously when its UMD script runs).
  function setFormMode() {
    var mode = IN_APP ? 'on' : 'off';
    try {
      if (window.Turbo && window.Turbo.config && window.Turbo.config.forms) {
        window.Turbo.config.forms.mode = mode;
      } else if (window.Turbo && typeof window.Turbo.setFormMode === 'function') {
        // Compatibility with Turbo releases that predate Turbo.config.forms.
        window.Turbo.setFormMode(mode);
      }
    } catch (e) { /* older/newer Turbo — ignore */ }
  }
  setFormMode();
  document.addEventListener('turbo:load', setFormMode, { once: true });

  // (c) In-app forms. Hotwire Native only follows navigations that go through
  // Turbo: a native (non-Turbo) form submission is a full page load the shell
  // does not track — iOS lets it load under the current native screen (wrong URL,
  // modal never dismissed), Android does not even see POSTs. So in the app every
  // form goes through Turbo (its redirect becomes a native visit proposal, and a
  // modal form's redirect dismisses the modal), except:
  //   - forms marked data-turbo="false" (downloads, off-site hand-offs) and
  //     target="_blank" / iframe targets — Turbo skips those itself;
  //   - JS / AJAX forms whose own submit handler calls preventDefault() (data-ajax,
  //     @submit.prevent, fetch) — unchanged.
  // The server answers Turbo form posts with a 303 or a 422 (TurboFormResponses).
  function inAppForms() {
    // form.submit() fires no submit event, so Turbo never sees it (old-theme.js
    // data-lm="submit", auto-submitting selects, the fleet-row switcher…). Route
    // it through Turbo too, still without a submit event — so a confirm handler
    // that calls form.submit() cannot loop — and fall back to the native submit
    // whenever Turbo would not handle the form.
    var nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
      var form = this;
      try {
        var session = window.Turbo && window.Turbo.session;
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        if (session && typeof session.willSubmitForm === 'function' && typeof session.formSubmitted === 'function'
            && form.isConnected && method !== 'dialog' && !targetsElsewhere(form) && session.willSubmitForm(form)) {
          preferReplaceForSamePageGet(form);
          session.formSubmitted(form);
          return;
        }
      } catch (e) { /* anything unexpected: behave like a browser */ }
      return nativeSubmit.call(form);
    };

    // Same rule as Turbo's own submit observer: _blank and iframe targets stay native.
    function targetsElsewhere(form) {
      var t = form.getAttribute('target');
      if (!t || t === '_self' || t === '_top' || t === '_parent') return false;
      if (t === '_blank') return true;
      var named = document.getElementsByName(t);
      for (var i = 0; i < named.length; i++) { if (named[i] instanceof HTMLIFrameElement) return true; }
      return false;
    }

    // A GET form that reloads its own page (filters, search, period pickers)
    // replaces the current native screen instead of pushing a new one per change.
    function preferReplaceForSamePageGet(form) {
      if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get' || form.hasAttribute('data-turbo-action')) return;
      try {
        var action = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        if (action.origin === window.location.origin && action.pathname === window.location.pathname) {
          form.setAttribute('data-turbo-action', 'replace');
        }
      } catch (e) { /* malformed action — leave it to Turbo */ }
    }
    document.addEventListener('submit', function (e) {
      if (e.target instanceof HTMLFormElement) preferReplaceForSamePageGet(e.target);
    }, true);

    // A 422 answer (validation errors) is rendered by Turbo without a turbo:load,
    // so re-run the per-page widget init after every render too (idempotent).
    document.addEventListener('turbo:render', function () { reinit(); });
  }
  if (IN_APP) inAppForms();

  // Excluded areas: the POS register (/pos*) and the Reseller portal (/reseller*)
  // render in their own app shells — swapping them into the dashboard <body>
  // breaks them. Force a full (native) navigation for these. NOTE: the negative
  // lookahead keeps /pos-bridge (a dashboard page) on Turbo.
  var EXCLUDE = /(^|\/)(pos(?!-)|reseller)(\/|$)/;
  document.addEventListener('turbo:before-visit', function (e) {
    try {
      var path = new URL(e.detail.url, window.location.origin).pathname;
      if (EXCLUDE.test(path)) {
        e.preventDefault();
        window.location.href = e.detail.url;
      }
    } catch (err) { /* malformed URL — let Turbo handle */ }
  });

  // Sidebar collapse toggler (single delegated listener, Turbo-safe). The vendor
  // shows the full labelled menu natively at >=1480px and an icon-rail + `.open`
  // flyout below that. The owner shell never wired the hamburger, so the menu was
  // stuck / unreachable. Wire ONE model:
  //  - wide (>=1480px): toggle the vendor's own data-app-sidebar="mini" (persisted).
  //    Default (absent) = full menu with content space reserved.
  //  - narrow (<1480px): toggle the `.open` flyout.
  var DASH_SB = 'dash.sidebar';
  var DASH_SB_OLD = 'lamar.sidebar'; // brand-alias: pre-P1-01 key, read once and migrated
  var navWide = function () { return window.matchMedia('(min-width: 1480px)').matches; };
  window.dashApplyNav = function () {
    var el = document.documentElement, pref = null;
    try { pref = localStorage.getItem(DASH_SB); if (pref === null) { pref = localStorage.getItem(DASH_SB_OLD); if (pref !== null) { localStorage.setItem(DASH_SB, pref); localStorage.removeItem(DASH_SB_OLD); } } } catch (e) {}
    // Our own class controls the >=1480px collapse (CSS-scoped to that width, and
    // immune to the base skin toggling data-app-sidebar). Default = full menu.
    el.classList.toggle('dash-nav-mini', pref === 'mini');
    if (navWide()) { var b = document.getElementById('appMenubar'); if (b) b.classList.remove('open'); }
  };
  // Capture-phase so we run BEFORE the base skin's own .app-toggler handler and
  // stop it — otherwise it toggles `.open`/data-app-sidebar too and cancels ours.
  document.addEventListener('click', function (e) {
    var toggler = e.target.closest && e.target.closest('.app-toggler');
    if (!toggler) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    var el = document.documentElement;
    if (navWide()) {
      var mini = el.classList.contains('dash-nav-mini');
      try { localStorage.setItem(DASH_SB, mini ? 'expanded' : 'mini'); } catch (err) {}
      window.dashApplyNav();
    } else {
      var bar = document.getElementById('appMenubar');
      if (bar) bar.classList.toggle('open');
    }
  }, true);
  // Clicking a rail TAB should reveal its menu, not silently switch a hidden pane.
  //  - narrow (<1480px): open the flyout (Bootstrap still switches the tab).
  //  - wide (>=1480px) while collapsed to the rail: expand back to the full menu.
  document.addEventListener('click', function (e) {
    var railTab = e.target.closest && e.target.closest('#appMenubarTabs .menu-link');
    if (!railTab) return;
    var el = document.documentElement;
    if (navWide()) {
      if (el.classList.contains('dash-nav-mini')) {
        try { localStorage.setItem(DASH_SB, 'expanded'); } catch (err) {}
        window.dashApplyNav();
      }
    } else {
      var b = document.getElementById('appMenubar');
      if (b) b.classList.add('open');
    }
  });
  // Close the narrow flyout when a menu item is chosen or on an outside click.
  document.addEventListener('click', function (e) {
    if (e.target.closest && (e.target.closest('.app-toggler') || e.target.closest('#appMenubarTabs .menu-link'))) return;
    var mb = document.getElementById('appMenubar');
    if (mb && mb.classList.contains('open')) {
      if (mb.contains(e.target)) { if (e.target.closest('.side-menubar .menu-link')) mb.classList.remove('open'); }
      else mb.classList.remove('open');
    }
  });
  var _navRt;
  window.addEventListener('resize', function () {
    clearTimeout(_navRt);
    _navRt = setTimeout(function () { window.dashApplyNav(); }, 150);
  });
  window.dashApplyNav();

  // Per-page widget (re)initialisation. Runs on the first paint and after every
  // Turbo navigation. All calls are idempotent / instance-guarded.
  function reinit() {
    // Bootstrap tooltips — attach to any trigger without an instance yet.
    try {
      if (window.bootstrap && bootstrap.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
          if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
        });
      }
    } catch (e) {}

    // SimpleBar custom scrollbars (sidebar, scrollable panes).
    try {
      if (window.SimpleBar) {
        document.querySelectorAll('[data-simplebar]').forEach(function (el) {
          var has = SimpleBar.instances && (typeof SimpleBar.instances.get === 'function'
            ? SimpleBar.instances.get(el) : SimpleBar.instances.has && SimpleBar.instances.has(el));
          if (!has && !el.classList.contains('simplebar-scrollable-y')) new SimpleBar(el);
        });
      }
    } catch (e) {}

    // Mini-sidebar hover expand (bound to #appMenubar, guarded so it binds once).
    try {
      var mb = document.getElementById('appMenubar');
      if (mb && !mb.dataset.hoverBound) {
        mb.dataset.hoverBound = '1';
        var el = document.documentElement;
        mb.addEventListener('mouseenter', function () {
          if (el.getAttribute('data-app-sidebar') === 'mini') el.setAttribute('data-app-sidebar', 'mini-hover');
        });
        mb.addEventListener('mouseleave', function () {
          if (el.getAttribute('data-app-sidebar') === 'mini-hover') el.setAttribute('data-app-sidebar', 'mini');
        });
      }
    } catch (e) {}

    // Re-apply the persisted sidebar expand/collapse after every Turbo swap.
    // (The toggler itself is a document-delegated handler bound once above.)
    try { if (window.dashApplyNav) window.dashApplyNav(); } catch (e) {}

    // owner table sort/select + Waves ripples for freshly swapped content.
    try { if (window.Dash && Dash.table) Dash.table.init(document); } catch (e) {}
    try { if (window.Waves) { Waves.init(); Waves.attach('.btn, .waves-effect'); } } catch (e) {}

    // P0-5: sidebar rail-tab memory. Persist the last-opened rail tab; on a page
    // with NO active menu item (the server falls back to Workspace) restore the
    // remembered tab instead so the pane doesn't snap back.
    try { railTabMemory(); } catch (e) {}
  }

  var RAIL_KEY = 'dash.nav.tab';
  var RAIL_KEY_OLD = 'lamar.nav.tab'; // brand-alias: pre-P1-01 key (read fallback)
  function railTabMemory() {
    var tabsRoot = document.getElementById('appMenubarTabs');
    if (!tabsRoot || tabsRoot.dataset.memoryBound === '1') { restoreRailTab(); return; }
    tabsRoot.dataset.memoryBound = '1';
    tabsRoot.addEventListener('click', function (e) {
      var link = e.target.closest && e.target.closest('.menu-link[href^="#tab-"]');
      if (!link) return;
      var key = link.getAttribute('href').replace('#tab-', '');
      try { localStorage.setItem(RAIL_KEY, key); localStorage.removeItem(RAIL_KEY_OLD); } catch (err) {}
    });
    restoreRailTab();
  }
  function restoreRailTab() {
    // A real active menu item means the server already chose the right tab — leave it.
    if (document.querySelector('.side-menubar .menu-link.active')) return;
    var key;
    try { key = localStorage.getItem(RAIL_KEY) || localStorage.getItem(RAIL_KEY_OLD); } catch (err) { key = null; }
    if (!key) return;
    var railLink = document.querySelector('#appMenubarTabs .menu-link[href="#tab-' + key + '"]');
    if (!railLink || railLink.classList.contains('active')) return;
    try {
      if (window.bootstrap && bootstrap.Tab) { bootstrap.Tab.getOrCreateInstance(railLink).show(); }
    } catch (err) {}
  }

  document.addEventListener('turbo:load', reinit);
})();
