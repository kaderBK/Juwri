// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ============================================================
   Fusion marketplace — mk-core.js (P0-5)
   Context client · cart client · sheets · toasts · SW/install.
   Lane modules register via window.MkModules (POS-V2 pattern) —
   parallel lanes NEVER edit this file; they add mk-<feature>.js.
   Bump ?v on every edit + keep sw.js precache in sync.
   ============================================================ */
(function () {
  'use strict';

  var Mk = {
    routes: (window.MkConfig && window.MkConfig.routes) || {},
    csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || '',

    /* ---------- context ---------- */
    ctx: (function () {
      try {
        var m = document.cookie.match(/(?:^|;\s*)mk_ctx=([^;]+)/);
        return m ? JSON.parse(decodeURIComponent(m[1])) : null;
      } catch (e) { return null; }
    })(),

    /* ---------- fetch helpers ---------- */
    get: function (url) {
      return fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); });
    },
    post: function (url, body) {
      return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': Mk.csrf },
        body: JSON.stringify(body || {})
      }).then(function (r) { return r.json(); });
    },

    /* ---------- toast ---------- */
    toast: function (msg) {
      var el = document.createElement('div');
      el.className = 'mk-toast';
      el.setAttribute('role', 'status');
      el.textContent = msg;
      document.body.appendChild(el);
      setTimeout(function () { el.remove(); }, 2600);
    },

    /* ---------- theme ---------- */
    theme: {
      apply: function (mode) {
        document.documentElement.setAttribute('data-mk-theme', mode);
        try { localStorage.setItem('mk_theme', mode); } catch (e) {}
      },
      init: function () {
        var saved = null;
        try { saved = localStorage.getItem('mk_theme'); } catch (e) {}
        /* No saved pref → the admin default (MkConfig.defaultTheme); 'auto'
           follows the device, everything else is taken literally. Crave's own
           default stays dark. */
        var def = (window.MkConfig && MkConfig.defaultTheme) || 'dark';
        if (def === 'auto') {
          def = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
        }
        var mode = saved || def;
        document.documentElement.setAttribute('data-mk-theme', mode);
      },
      toggle: function () {
        var cur = document.documentElement.getAttribute('data-mk-theme') === 'dark' ? 'light' : 'dark';
        Mk.theme.apply(cur);
      }
    },

    /* ---------- install prompt ---------- */
    install: {
      deferred: null,
      init: function () {
        window.addEventListener('beforeinstallprompt', function (e) {
          e.preventDefault();
          Mk.install.deferred = e;
          document.querySelectorAll('.mk-install').forEach(function (b) { b.classList.add('is-available'); });
        });
        document.addEventListener('click', function (e) {
          var b = e.target.closest('.mk-install');
          if (b && Mk.install.deferred) {
            Mk.install.deferred.prompt();
            Mk.install.deferred = null;
            b.classList.remove('is-available');
          }
        });
      }
    },

    /* ---------- service worker ---------- */
    sw: function () {
      if ('serviceWorker' in navigator && Mk.routes.sw) {
        navigator.serviceWorker.register(Mk.routes.sw, { scope: Mk.routes.scope || '/fusion' }).catch(function () {});
      }
    },

    /* ---------- module registry ---------- */
    modules: {},
    register: function (name, mod) {
      Mk.modules[name] = mod;
      if (typeof mod.init === 'function') { mod.init(Mk); }
    }
  };

  window.MkModules = Mk.modules;
  window.Mk = Mk;

  /* ---------- motion (Snoonu-skin) ---------- */
  Mk.motion = {
    reduced: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches,

    // Staggered fade-up: stamp .mk-anim children with --i, reveal on viewport entry.
    reveal: function () {
      var nodes = document.querySelectorAll('.mk-anim:not(.in)');
      if (!nodes.length) { return; }
      if (Mk.motion.reduced || !('IntersectionObserver' in window)) {
        nodes.forEach(function (n) { n.classList.add('in'); });
        return;
      }
      /* .mk-anim starts at opacity 0 but keeps its full height, so an
         unrevealed card is a hole in the page, not a missing one. Two things
         used to make that hole big and long-lived:

         1. rootMargin '0px 0px -8% 0px' DELAYED the reveal until a card was
            already 8% inside the viewport. On the explore results grid, where
            a card is ~420px tall on a phone, that meant scrolling into a blank
            band and waiting for it to fade in. A POSITIVE bottom margin starts
            the fade before the card reaches the fold instead.
         2. `seen` was a page-wide counter, so the stagger index of a card
            revealed on scroll depended on how many elements had ever been
            revealed — an arbitrary 0–275ms delay stacked on top of the 450ms
            fade. The index is now per-callback, so a late reveal is prompt. */
      var io = new IntersectionObserver(function (entries) {
        var i = 0;
        entries.forEach(function (e) {
          if (e.isIntersecting) {
            e.target.style.setProperty('--i', (i++ % 6));
            e.target.classList.add('in');
            io.unobserve(e.target);
          }
        });
      }, { rootMargin: '0px 0px 20% 0px' });
      nodes.forEach(function (n) { io.observe(n); });
    },

    // Topbar gains elevation once the page scrolls.
    topbar: function () {
      var bar = document.querySelector('.mk-topbar');
      if (!bar) { return; }
      var apply = function () { bar.classList.toggle('is-scrolled', window.scrollY > 8); };
      window.addEventListener('scroll', apply, { passive: true });
      apply();
    }
  };

  /* ---------- favorites hearts ([data-mk-fav]) ---------- */
  Mk.hearts = {
    /* Crave-era hearts are inline SVGs whose fill is driven by .is-on; the
       older ones are bare ♥/♡ text nodes. Writing textContent unconditionally
       would delete the SVG on first paint, so only glyph hearts get text. */
    paint: function (b, on) {
      if (b.firstElementChild) { return; }
      b.textContent = on ? '♥' : '♡';
    },
    markFromList: function (ids) {
      document.querySelectorAll('[data-mk-fav]').forEach(function (b) {
        var on = ids.indexOf(parseInt(b.dataset.mkFav, 10)) !== -1;
        b.classList.toggle('is-on', on);
        Mk.hearts.paint(b, on);
      });
    },
    init: function () {
      if (document.body.dataset.mkAuth === '1' && Mk.modules.favorites && Mk.modules.favorites.list) {
        /* favorites.list() resolves the ENDPOINT payload {ok, ids, stores}, not a
           bare array — so this never ran and hearts rendered empty on every page
           even for stores the shopper had favourited. Accept either shape. */
        Promise.resolve(Mk.modules.favorites.list()).then(function (res) {
          var ids = Array.isArray(res) ? res : (res && res.ids);
          if (Array.isArray(ids)) { Mk.hearts.markFromList(ids.map(Number)); }
        }).catch(function () {});
      }
      document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-mk-fav]');
        if (!b) { return; }
        e.preventDefault();
        e.stopPropagation();
        if (document.body.dataset.mkAuth !== '1') {
          var login = document.querySelector('[data-mk-login]');
          if (login) { login.click(); }
          else if (Mk.modules.account && Mk.modules.account.open) { Mk.modules.account.open(); }
          return;
        }
        var id = parseInt(b.dataset.mkFav, 10);
        var toggle = Mk.modules.favorites && Mk.modules.favorites.toggle;
        if (!toggle) { return; }
        var nowOn = !b.classList.contains('is-on');
        b.classList.toggle('is-on', nowOn);
        Mk.hearts.paint(b, nowOn);
        b.classList.remove('mk-pop'); void b.offsetWidth; b.classList.add('mk-pop');
        Promise.resolve(toggle(id)).catch(function () {
          b.classList.toggle('is-on', !nowOn);
          Mk.hearts.paint(b, !nowOn);
        });
      }, true);
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    Mk.theme.init();
    Mk.install.init();
    Mk.sw();
    Mk.motion.reveal();
    Mk.motion.topbar();
    Mk.hearts.init();

    var chip = document.getElementById('mk-citychip');
    if (chip) {
      chip.addEventListener('click', function () {
        if (window.mkCityPicker) { window.mkCityPicker.open(); }
      });
    }
    var themeBtn = document.getElementById('mk-themebtn');
    if (themeBtn) { themeBtn.addEventListener('click', Mk.theme.toggle); }

    /* Language picker dropdown. */
    var langPick = document.querySelector('[data-mk-langpick]');
    if (langPick) {
      var toggle = langPick.querySelector('[data-mk-langtoggle]');
      var menu = langPick.querySelector('[data-mk-langmenu]');
      var close = function () {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
      };
      toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = menu.hidden;
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.addEventListener('click', function (e) {
        if (!langPick.contains(e.target)) { close(); }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.hidden) { close(); toggle.focus(); }
      });
    }
  });

  // theme must not flash — apply before DOMContentLoaded too
  Mk.theme.init();
})();
