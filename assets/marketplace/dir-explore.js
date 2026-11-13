// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ============================================================
   Directory module — dir-explore.js (T5-1/T5-3)
   Progressive enhancement over the SSR /fusion/explore page: every link
   and the filter form already work with zero JS (full querystring
   navigations — see explore.blade.php). This file only:
     1. turns the inline filter sheet into a bottom-sheet overlay
     2. intercepts same-page links/form submits to swap #dir-app via fetch
        (X-Dir-Partial: 1) instead of a full reload — chip toggles without
        a page flash, pushState keeps the URL shareable
     3. degrades to plain navigation on any fetch failure (never traps the
        user on a dead page)
   Registers via window.Mk.register when mk-core.js is present; runs
   standalone otherwise (dir.css/this file only ship while `directory` is
   on — see layout.blade.php — but mk-core.js is always on the page).
   Bump ?v (config('marketplace.asset_v')) on every edit + keep sw.js
   precache in sync.
   ============================================================ */
(function () {
  'use strict';

  function boot(Mk) {
    var app = document.getElementById('dir-app');
    if (!app) { return; }

    var toast = (Mk && Mk.toast) || function (msg) { /* no Mk on this page — silent */ };
    var errMsg = (window.MkI18n && MkI18n.dirLoadError) || 'Could not load — retry';

    /* ---------- filter sheet: inline (no-JS) → bottom sheet (JS) ---------- */
    function wireSheet() {
      var toggle = document.getElementById('dir-filtertoggle');
      var sheet = document.getElementById('dir-filtersheet');
      if (!toggle || !sheet) { return; }

      function close() {
        sheet.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      }
      function open() {
        sheet.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        var first = sheet.querySelector('input:not([type="hidden"])');
        if (first) { first.focus(); }
      }

      toggle.addEventListener('click', function () {
        var isOpen = sheet.classList.contains('is-open');
        if (isOpen) { close(); } else { open(); }
      });
      document.addEventListener('click', function (e) {
        if (sheet.classList.contains('is-open') && !sheet.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
          close();
        }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('is-open')) { close(); toggle.focus(); }
      });
    }

    /* ---------- AJAX swap ---------- */
    var inflight = null;

    function showSkeleton() {
      var grid = document.getElementById('dir-grid') || document.getElementById('dir-empty');
      if (!grid) { return null; }
      var skel = document.createElement('div');
      skel.className = 'dir-skel-grid';
      skel.setAttribute('data-dir-skel', '1');
      for (var i = 0; i < 6; i++) {
        var card = document.createElement('div');
        card.className = 'dir-skel-card mk-skel';
        skel.appendChild(card);
      }
      grid.insertAdjacentElement('afterend', skel);
      grid.hidden = true;
      return skel;
    }

    function swap(url, pushUrl) {
      if (inflight) { inflight.abort && inflight.abort(); }
      var controller = window.AbortController ? new AbortController() : null;
      inflight = controller;
      app.classList.add('dir-loading');
      var skel = showSkeleton();

      fetch(url, {
        headers: { 'X-Dir-Partial': '1', Accept: 'text/html' },
        signal: controller ? controller.signal : undefined
      }).then(function (r) {
        if (!r.ok) { throw new Error('bad status'); }
        return r.text();
      }).then(function (html) {
        app.innerHTML = html;
        app.classList.remove('dir-loading');
        wireSheet();
        if (window.Mk && Mk.hearts && Mk.hearts.init) {
          // Re-mark hearts from the shopper's favourite list on the fresh markup.
          document.querySelectorAll('#dir-app [data-mk-fav]').forEach(function (b) {});
          if (Mk.modules.favorites && Mk.modules.favorites.list) {
            Promise.resolve(Mk.modules.favorites.list()).then(function (res) {
              var ids = Array.isArray(res) ? res : (res && res.ids);
              if (Array.isArray(ids) && Mk.hearts) { Mk.hearts.markFromList(ids.map(Number)); }
            }).catch(function () {});
          }
        }
        if (pushUrl) { window.history.pushState({ dirExplore: true }, '', url); }
        if (window.dirMap && typeof window.dirMap.reinit === 'function') { window.dirMap.reinit(); }
      }).catch(function (e) {
        if (e && e.name === 'AbortError') { return; }
        app.classList.remove('dir-loading');
        if (skel && skel.parentNode) { skel.parentNode.removeChild(skel); }
        var grid = document.getElementById('dir-grid') || document.getElementById('dir-empty');
        if (grid) { grid.hidden = false; }
        toast(errMsg);
        // Never trap the visitor on a stale page — fall back to a real nav.
        window.location.href = url;
      });
    }

    function isEnhanceable(a) {
      if (!a || a.target === '_blank' || a.hasAttribute('download')) { return false; }
      var href = a.getAttribute('href');
      if (!href || href.charAt(0) === '#') { return false; }
      try {
        var u = new URL(href, window.location.href);
        return u.origin === window.location.origin && u.pathname.indexOf('/fusion/explore') === 0;
      } catch (e) { return false; }
    }

    app.addEventListener('click', function (e) {
      var a = e.target.closest('a');
      if (!a || !isEnhanceable(a)) { return; }
      // The map view's own noscript fallback / list-mode link still needs a
      // real nav feel (view swap), but it is a normal explore URL too — let
      // the generic swap handle it, same as every other link here.
      e.preventDefault();
      swap(a.getAttribute('href'), true);
    });

    app.addEventListener('submit', function (e) {
      var form = e.target.closest('form.dir-filterform');
      if (!form) { return; }
      e.preventDefault();
      var btn = form.querySelector('[data-dir-busyguard]');
      if (btn) {
        if (btn.disabled) { return; } // triple-tap guard
        btn.disabled = true;
        setTimeout(function () { btn.disabled = false; }, 1500);
      }
      var params = new URLSearchParams(new FormData(form));
      var url = form.getAttribute('action') + '?' + params.toString();
      swap(url, true);
    });

    window.addEventListener('popstate', function () {
      if (window.location.pathname.indexOf('/fusion/explore') === 0) {
        swap(window.location.href, false);
      }
    });

    wireSheet();
  }

  if (window.Mk && window.Mk.register) { window.Mk.register('direxplore', { init: boot }); }
  else { document.addEventListener('DOMContentLoaded', function () { boot(window.Mk || null); }); }
})();
