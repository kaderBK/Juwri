// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ============================================================
   Fusion marketplace — mk-stories.js
   STATUS 6.2 (fusion-crave): the home stories rail + fullscreen viewer.

   Owns: unseen/seen ring state, the fullscreen story viewer (progress
   bars, auto-advance, tap-to-page, swipe/Esc to close) and the link
   through to the store.

   Style follows mk-store.js / mk-track.js: one IIFE, ES5-ish, no
   framework, registered through window.Mk.register so mk-core.js is
   never edited. Every DOM hook is feature-detected — the blade renders
   nothing at all when there are no stories, so a missing element is a
   silent no-op, never a crash.

   ---- SEEN STATE (client only) --------------------------------------
   There is NO server table and NO write endpoint for "seen". The set of
   viewed story ids lives in localStorage under window.MkStories.seenKey
   ('mk_stories_seen'). Keying per STORY id (not per store) is what makes
   a newly published story re-ring a store the shopper already watched.
   The list is capped to the most recent SEEN_CAP ids so the key cannot
   grow without bound. localStorage is fully feature-detected: with it
   blocked, every ring simply renders unseen forever — degraded, working.

   ---- REDUCED MOTION -------------------------------------------------
   Under prefers-reduced-motion: reduce there is NO auto-advance and NO
   animated progress fill. Bars render discretely (0% or 100%) and the
   shopper pages manually. The media query is watched live, so toggling
   the OS setting mid-session takes effect without a reload.

   ---- RTL ------------------------------------------------------------
   Three surfaces, two of which CSS owns:
   * TAP ZONES — semantic (data-mk-storyview-nav="prev"|"next"). CSS pins
     --prev to inset-inline-start, so RTL relocates it to the visual right
     for free. JS must NOT flip these or the inversion happens twice.
   * PROGRESS BARS — fill from the inline start via the --mk-p custom
     property; direction is again CSS's, not ours.
   * KEYBOARD + SWIPE — these ARE physical (ArrowRight, "finger moved
     left") and have no logical equivalent, so dirFactor() inverts them:
     in RTL, ArrowRight and a leftward swipe both mean PREVIOUS.
   ============================================================ */
(function () {
  'use strict';

  /* ---------- tiny helpers ---------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }

  var cfg = window.MkStories || {};
  var TXT = cfg.txt || {};
  var DURATION = typeof cfg.durationMs === 'number' && cfg.durationMs > 0 ? cfg.durationMs : 5000;
  var SEEN_KEY = cfg.seenKey || 'mk_stories_seen';
  var SEEN_CAP = 400;
  var SWIPE_MIN = 60;   /* px before a drag counts as a swipe */

  function t(key, fallback) { return TXT[key] || fallback; }

  /* ---------- data ---------- */
  /* The rail payload is embedded by the blade as inert JSON — same shape
     the /fusion/stories endpoint returns: [{id,slug,title,logo,url,stories:[…]}]. */
  function readData() {
    var node = document.getElementById('mk-stories-data');
    if (!node) { return []; }
    try {
      var parsed = JSON.parse(node.textContent || '[]');
      return Object.prototype.toString.call(parsed) === '[object Array]' ? parsed : [];
    } catch (e) { return []; }
  }

  /* ---------- seen state (localStorage only) ---------- */
  var seen = {
    _ok: (function () {
      try { window.localStorage.setItem('__mk_t', '1'); window.localStorage.removeItem('__mk_t'); return true; }
      catch (e) { return false; }
    })(),
    _ids: null,

    all: function () {
      if (seen._ids) { return seen._ids; }
      seen._ids = [];
      if (seen._ok) {
        try {
          var raw = JSON.parse(window.localStorage.getItem(SEEN_KEY) || '[]');
          if (Object.prototype.toString.call(raw) === '[object Array]') {
            seen._ids = raw.map(Number).filter(function (n) { return isFinite(n); });
          }
        } catch (e) { seen._ids = []; }
      }
      return seen._ids;
    },

    has: function (id) { return seen.all().indexOf(Number(id)) !== -1; },

    add: function (id) {
      id = Number(id);
      if (!isFinite(id) || seen.has(id)) { return; }
      var ids = seen.all();
      ids.push(id);
      if (ids.length > SEEN_CAP) { ids = ids.slice(ids.length - SEEN_CAP); seen._ids = ids; }
      if (seen._ok) {
        try { window.localStorage.setItem(SEEN_KEY, JSON.stringify(ids)); } catch (e) {}
      }
    },

    /* A store bubble is "seen" only when EVERY one of its current stories is. */
    storeSeen: function (store) {
      for (var i = 0; i < store.stories.length; i++) {
        if (!seen.has(store.stories[i].id)) { return false; }
      }
      return true;
    }
  };

  /* ---------- reduced motion (watched live) ---------- */
  var reduced = (function () {
    var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var state = { on: !!(mq && mq.matches) };
    if (mq) {
      var onChange = function () {
        state.on = mq.matches;
        /* Re-sync a live viewer: entering reduced motion kills the timer. */
        if (viewer.isOpen()) { viewer.render(); }
      };
      if (mq.addEventListener) { mq.addEventListener('change', onChange); }
      else if (mq.addListener) { mq.addListener(onChange); }
    }
    return state;
  })();

  /* ---------- direction ---------- */
  /* +1 = LTR (left zone is prev), -1 = RTL (left zone is next). */
  function dirFactor() {
    var dir = document.documentElement.getAttribute('dir') ||
      (window.getComputedStyle ? window.getComputedStyle(document.documentElement).direction : 'ltr');
    return dir === 'rtl' ? -1 : 1;
  }

  /* ============================================================
     RAIL
     ============================================================ */
  var rail = {
    root: null,
    data: [],

    init: function (data) {
      rail.root = $('[data-mk-stories]');
      if (!rail.root) { return; }
      rail.data = data;
      rail.paint();

      rail.root.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('[data-mk-story-store]') : null;
        if (!btn) { return; }
        var id = Number(btn.getAttribute('data-mk-story-store'));
        var idx = rail.indexOf(id);
        if (idx === -1) { return; }
        viewer.open(idx, 0);
      });
    },

    indexOf: function (storeId) {
      for (var i = 0; i < rail.data.length; i++) {
        if (Number(rail.data[i].id) === Number(storeId)) { return i; }
      }
      return -1;
    },

    /* Ring state is applied here and ONLY here, so reopening/closing the
       viewer repaints from one source of truth. */
    paint: function () {
      if (!rail.root) { return; }
      $$('[data-mk-story-store]', rail.root).forEach(function (btn) {
        var idx = rail.indexOf(btn.getAttribute('data-mk-story-store'));
        if (idx === -1) { return; }
        var ring = $('.mk-stories__ring', btn);
        if (!ring) { return; }
        if (seen.storeSeen(rail.data[idx])) { ring.classList.add('is-seen'); }
        else { ring.classList.remove('is-seen'); }
      });
    }
  };

  /* ============================================================
     FULLSCREEN VIEWER
     ============================================================ */
  var viewer = {
    root: null,
    els: {},
    data: [],
    si: 0,          /* store index */
    ii: 0,          /* story index within store */
    open_: false,
    timer: null,
    startedAt: 0,
    raf: null,
    lastFocus: null,

    init: function (data) {
      viewer.root = document.getElementById('mk-storyview');
      if (!viewer.root) { return; }
      viewer.data = data;
      viewer.els = {
        bars: $('[data-mk-storyview-bars]', viewer.root),
        avatar: $('[data-mk-storyview-avatar]', viewer.root),
        store: $('[data-mk-storyview-store]', viewer.root),
        media: $('[data-mk-storyview-media]', viewer.root),
        caption: $('[data-mk-storyview-caption]', viewer.root),
        cta: $('[data-mk-storyview-cta]', viewer.root),
        close: $('[data-mk-storyview-close]', viewer.root)
      };

      if (viewer.els.close) {
        viewer.els.close.addEventListener('click', function () { viewer.close(); });
      }

      /* The zones are SEMANTIC (prev/next), not physical. CSS pins --prev to
         inset-inline-start, so under RTL the browser already moves it to the
         visual right — applying dirFactor() here too would double-invert. */
      $$('[data-mk-storyview-nav]', viewer.root).forEach(function (btn) {
        btn.addEventListener('click', function () {
          viewer.step(btn.getAttribute('data-mk-storyview-nav') === 'prev' ? -1 : 1);
        });
      });

      document.addEventListener('keydown', function (ev) {
        if (!viewer.open_) { return; }
        if (ev.key === 'Escape') { ev.preventDefault(); viewer.close(); return; }
        if (ev.key === 'ArrowRight') { ev.preventDefault(); viewer.step(dirFactor()); return; }
        if (ev.key === 'ArrowLeft') { ev.preventDefault(); viewer.step(-dirFactor()); }
      });

      /* Auto-advance must not burn through stories in a backgrounded tab. */
      document.addEventListener('visibilitychange', function () {
        if (!viewer.open_) { return; }
        if (document.hidden) { viewer.pause(); } else { viewer.resume(); }
      });

      viewer.bindSwipe();
    },

    isOpen: function () { return viewer.open_; },

    current: function () {
      var store = viewer.data[viewer.si];
      return store ? store.stories[viewer.ii] : null;
    },

    open: function (si, ii) {
      if (!viewer.root || !viewer.data[si]) { return; }
      viewer.si = si;
      viewer.ii = ii || 0;
      viewer.open_ = true;
      viewer.lastFocus = document.activeElement;
      viewer.root.hidden = false;
      viewer.root.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('mk-storyview-open');
      viewer.render();
      try { viewer.root.focus(); } catch (e) {}
    },

    close: function () {
      if (!viewer.root || !viewer.open_) { return; }
      viewer.pause();
      viewer.open_ = false;
      viewer.root.hidden = true;
      viewer.root.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('mk-storyview-open');
      rail.paint();
      if (viewer.lastFocus && viewer.lastFocus.focus) {
        try { viewer.lastFocus.focus(); } catch (e) {}
      }
    },

    /* delta -1 = back, +1 = forward. Walks across store boundaries and
       closes once the last story of the last store is passed. */
    step: function (delta) {
      if (!viewer.open_ || !delta) { return; }
      var store = viewer.data[viewer.si];
      if (!store) { viewer.close(); return; }

      var next = viewer.ii + delta;
      if (next >= 0 && next < store.stories.length) {
        viewer.ii = next;
        viewer.render();
        return;
      }

      var nsi = viewer.si + delta;
      if (nsi < 0) { viewer.ii = 0; viewer.render(); return; }   /* already at the very start */
      if (nsi >= viewer.data.length) { viewer.close(); return; }

      viewer.si = nsi;
      viewer.ii = delta > 0 ? 0 : viewer.data[nsi].stories.length - 1;
      viewer.render();
    },

    render: function () {
      var store = viewer.data[viewer.si];
      var story = viewer.current();
      if (!store || !story) { viewer.close(); return; }

      /* Mark seen the moment it is shown — matches every story UI. */
      seen.add(story.id);

      if (viewer.els.avatar) { viewer.els.avatar.src = store.logo || ''; }
      if (viewer.els.store) {
        viewer.els.store.textContent = store.title;
        viewer.els.store.href = store.url;
      }
      if (viewer.els.media) {
        viewer.els.media.src = story.media;
        viewer.els.media.alt = story.caption || store.title;
      }
      if (viewer.els.caption) {
        viewer.els.caption.textContent = story.caption || '';
        viewer.els.caption.hidden = !story.caption;
      }
      /* CTA: the story's own link when it has one, otherwise the store page —
         the bubble always leads somewhere real, never a dead control. */
      if (viewer.els.cta) {
        var href = story.cta_url || store.url;
        var label = story.cta_url ? (story.cta_label || t('viewStore', 'View store')) : t('viewStore', 'View store');
        viewer.els.cta.href = href;
        viewer.els.cta.textContent = label;
        viewer.els.cta.hidden = false;
      }

      viewer.paintBars();
      viewer.restart();
    },

    paintBars: function () {
      var store = viewer.data[viewer.si];
      var bars = viewer.els.bars;
      if (!bars || !store) { return; }

      /* Rebuild only when the store changed (bar count differs). */
      if (bars.children.length !== store.stories.length) {
        bars.innerHTML = '';
        for (var i = 0; i < store.stories.length; i++) {
          var bar = document.createElement('span');
          bar.className = 'mk-storyview__bar';
          bars.appendChild(bar);
        }
      }
      for (var j = 0; j < bars.children.length; j++) {
        var el = bars.children[j];
        var pct = j < viewer.ii ? 100 : 0;
        el.classList.toggle('is-done', j < viewer.ii);
        el.classList.toggle('is-active', j === viewer.ii);
        el.style.setProperty('--mk-p', pct);
      }
    },

    /* ---------- timing ---------- */
    restart: function () {
      viewer.pause();
      if (reduced.on) {
        /* No auto-advance, no animated fill: the active bar just reads full. */
        viewer.setProgress(100);
        return;
      }
      if (document.hidden) { viewer.setProgress(0); return; }
      viewer.startedAt = Date.now();
      viewer.tick();
    },

    tick: function () {
      if (!viewer.open_ || reduced.on) { return; }
      var elapsed = Date.now() - viewer.startedAt;
      var pct = Math.min(100, (elapsed / DURATION) * 100);
      viewer.setProgress(pct);
      if (pct >= 100) { viewer.step(1); return; }
      viewer.raf = window.requestAnimationFrame
        ? window.requestAnimationFrame(viewer.tick)
        : window.setTimeout(viewer.tick, 60);
    },

    setProgress: function (pct) {
      var bars = viewer.els.bars;
      if (!bars || !bars.children[viewer.ii]) { return; }
      bars.children[viewer.ii].style.setProperty('--mk-p', Math.round(pct));
    },

    pause: function () {
      if (viewer.raf) {
        if (window.cancelAnimationFrame) { window.cancelAnimationFrame(viewer.raf); }
        window.clearTimeout(viewer.raf);
        viewer.raf = null;
      }
      if (viewer.timer) { window.clearTimeout(viewer.timer); viewer.timer = null; }
      viewer.pausedAt = Date.now();
    },

    /* Resume where the story left off rather than restarting it. */
    resume: function () {
      if (!viewer.open_ || reduced.on) { return; }
      var bars = viewer.els.bars;
      var pct = 0;
      if (bars && bars.children[viewer.ii]) {
        pct = Number(bars.children[viewer.ii].style.getPropertyValue('--mk-p')) || 0;
      }
      viewer.startedAt = Date.now() - (pct / 100) * DURATION;
      viewer.tick();
    },

    bindSwipe: function () {
      var x0 = null, y0 = null;
      var root = viewer.root;
      if (!root || !('ontouchstart' in window || window.PointerEvent)) { return; }

      root.addEventListener('touchstart', function (ev) {
        var tch = ev.changedTouches && ev.changedTouches[0];
        if (!tch) { return; }
        x0 = tch.clientX; y0 = tch.clientY;
        viewer.pause();
      }, { passive: true });

      root.addEventListener('touchend', function (ev) {
        var tch = ev.changedTouches && ev.changedTouches[0];
        if (!tch || x0 === null) { viewer.resume(); return; }
        var dx = tch.clientX - x0;
        var dy = tch.clientY - y0;
        x0 = null; y0 = null;

        if (dy > SWIPE_MIN && Math.abs(dy) > Math.abs(dx)) { viewer.close(); return; }
        if (Math.abs(dx) > SWIPE_MIN && Math.abs(dx) > Math.abs(dy)) {
          /* Swiping toward the inline end advances; inverted under RTL. */
          viewer.step(dx < 0 ? dirFactor() : -dirFactor());
          return;
        }
        viewer.resume();
      }, { passive: true });
    }
  };

  /* ---------- module ---------- */
  var mod = {
    init: function () {
      var data = readData();
      if (!data.length) { return; }   /* blade renders nothing; nothing to wire */
      rail.init(data);
      viewer.init(data);
    }
  };

  if (window.Mk && window.Mk.register) {
    window.Mk.register('stories', mod);
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      if (window.Mk && window.Mk.register) { window.Mk.register('stories', mod); }
    });
  }
})();
