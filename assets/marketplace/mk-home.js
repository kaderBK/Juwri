// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* Fusion home module — crave/demo parity (PARITY.md H rows): segmented
   Delivery/Pickup + chips (All/Favorites/Top/Open/Offers) + filter sheet +
   client-side sort + banner carousel autoplay + first-visit splash.
   Pure DOM filtering off data attrs — no extra requests. */
(function () {
  'use strict';

  var mod = {
    init: function (Mk) {
      var seg = document.querySelector('.mk-seg');
      var chips = document.querySelectorAll('[data-mk-filter]');
      if (!seg && !chips.length) { return; }

      // Default segment = whichever mode tab is active in the DOM (the admin can
      // disable order types, so 'delivery' is not always present/first).
      var activeSeg = document.querySelector('.mk-seg__btn.is-active[data-mk-seg]');
      var state = { seg: activeSeg ? activeSeg.getAttribute('data-mk-seg') : 'delivery', favorites: false, top: false, open: false, offer: false, sort: 'default', verts: [] };
      var favIds = null;

      function cards() { return [].slice.call(document.querySelectorAll('.mk-storecard[data-store]')); }

      function apply() {
        var visible = 0;
        var unique = {};
        cards().forEach(function (card) {
          var modes = (card.dataset.modes || '').split(',');
          var ok = true;

          // Segments: delivery = any delivery mode (or untagged legacy store);
          // pickup + dine-in = every approved store serves at the store.
          if (state.seg === 'delivery') {
            ok = !card.dataset.modes || modes.indexOf('self') !== -1 || modes.indexOf('fleet') !== -1 || modes.indexOf('courier') !== -1;
          }
          if (ok && state.favorites) { ok = !!(favIds && favIds.indexOf(parseInt(card.dataset.store, 10)) !== -1); }
          if (ok && state.top) { ok = parseFloat(card.dataset.rating || '0') >= 4; }
          if (ok && state.open) { ok = card.dataset.open === '1'; }
          if (ok && state.offer) { ok = card.dataset.offer === '1'; }
          // Vertical filter (sheet): match when the store's menu carries ANY
          // selected category.
          if (ok && state.verts.length) {
            var cats = (card.dataset.cats || '').split('|');
            ok = state.verts.some(function (v) { return cats.indexOf(v) !== -1; });
          }

          card.classList.toggle('mk-hidden', !ok);
          if (ok) { visible++; unique[card.dataset.store] = 1; }
        });
        var count = document.getElementById('mk-filtercount');
        if (count) { count.textContent = '(' + Object.keys(unique).length + ')'; }

        // Hide sections that filtered down to nothing; global empty state.
        document.querySelectorAll('[data-mk-storesection]').forEach(function (sec) {
          var any = sec.querySelector('.mk-storecard:not(.mk-hidden)');
          sec.classList.toggle('mk-hidden', !any);
        });
        var empty = document.getElementById('mk-home-empty');
        if (empty) { empty.hidden = visible !== 0; }

        // "✦ All" chip reflects (and clears) the no-filter state; the sheet's
        // indicator dot mirrors whether anything is narrowing the list.
        var none = !state.favorites && !state.top && !state.open && !state.offer && !state.verts.length;
        var allChip = document.querySelector('[data-mk-filter="all"]');
        if (allChip) { allChip.classList.toggle('is-active', none); }
        var dot = document.getElementById('mk-filterdot');
        if (dot) { dot.hidden = none && state.sort === 'default'; }
      }

      function clearFilters() {
        state.favorites = state.top = state.open = state.offer = false;
        state.verts = [];
        chips.forEach(function (c) { if (c.dataset.mkFilter !== 'all') { c.classList.remove('is-active'); } });
        document.querySelectorAll('[data-mk-sheetfilter],[data-mk-vert]').forEach(function (c) { c.classList.remove('is-active'); });
        apply();
      }

      if (seg) {
        seg.addEventListener('click', function (e) {
          var b = e.target.closest('[data-mk-seg]');
          if (!b) { return; }
          state.seg = b.dataset.mkSeg;
          seg.querySelectorAll('.mk-seg__btn').forEach(function (x) {
            var on = x === b;
            x.classList.toggle('is-active', on);
            x.setAttribute('aria-selected', on ? 'true' : 'false');
          });
          apply();
        });
      }

      function toggleFilter(key, chip) {
        if (key === 'all') { clearFilters(); return; }
        if (key === 'favorites' && !state.favorites) {
          if (document.body.dataset.mkAuth !== '1') {
            var login = document.querySelector('[data-mk-login]');
            if (login) { login.click(); }
            return;
          }
          if (favIds === null && Mk.modules.favorites && Mk.modules.favorites.list) {
            Promise.resolve(Mk.modules.favorites.list()).then(function (res) {
              var ids = Array.isArray(res) ? res : (res && res.ids);
              favIds = Array.isArray(ids) ? ids.map(Number) : [];
              state.favorites = true;
              if (chip) { chip.classList.add('is-active'); }
              apply();
            }).catch(function () {});
            return;
          }
        }
        state[key] = !state[key];
        if (chip) { chip.classList.toggle('is-active', state[key]); }
        // Sheet + chip row mirror each other for shared keys.
        document.querySelectorAll('[data-mk-filter="' + key + '"],[data-mk-sheetfilter="' + key + '"]').forEach(function (c) {
          c.classList.toggle('is-active', state[key]);
        });
        apply();
      }

      chips.forEach(function (chip) {
        chip.addEventListener('click', function () { toggleFilter(chip.dataset.mkFilter, chip); });
      });

      /* ---------- client-side sort (grid only; rails keep admin order) ---------- */
      var grid = document.getElementById('mk-storegrid');
      var sortBtn = document.getElementById('mk-sortbtn');
      var sortLabels = null;
      function applySort(key) {
        state.sort = key;
        if (grid) {
          var list = [].slice.call(grid.querySelectorAll('.mk-storecard'));
          list.sort(function (a, b) {
            if (key === 'name') { return (a.dataset.title || '').localeCompare(b.dataset.title || ''); }
            return parseFloat(b.dataset.rating || '0') - parseFloat(a.dataset.rating || '0');
          });
          list.forEach(function (c) { grid.appendChild(c); });
        }
        document.querySelectorAll('[data-mk-sort]').forEach(function (c) {
          c.classList.toggle('is-active', c.dataset.mkSort === key);
        });
        if (sortBtn && sortLabels) { sortBtn.textContent = sortLabels[key] || sortLabels.default; }
        apply();
      }
      if (sortBtn) {
        // Localized labels come from the sheet's own sort chips — no JS strings.
        sortLabels = {};
        document.querySelectorAll('[data-mk-sort]').forEach(function (c) { sortLabels[c.dataset.mkSort] = c.textContent.trim(); });
        applySort('default');
        sortBtn.addEventListener('click', function () {
          applySort(state.sort === 'default' ? 'name' : 'default');
        });
      }

      /* ---------- filter sheet ---------- */
      var sheet = document.getElementById('mk-filtersheet');
      var openBtn = document.getElementById('mk-filterbtn');
      if (sheet && openBtn) {
        var close = function () { sheet.hidden = true; };
        openBtn.addEventListener('click', function () { sheet.hidden = false; });
        sheet.addEventListener('click', function (e) {
          if (e.target === sheet) { close(); return; }
          var s = e.target.closest('[data-mk-sort]');
          if (s) { applySort(s.dataset.mkSort); return; }
          var v = e.target.closest('[data-mk-vert]');
          if (v) {
            var name = v.dataset.mkVert;
            var i = state.verts.indexOf(name);
            if (i === -1) { state.verts.push(name); } else { state.verts.splice(i, 1); }
            v.classList.toggle('is-active', i === -1);
            apply();
            return;
          }
          var f = e.target.closest('[data-mk-sheetfilter]');
          if (f) { toggleFilter(f.dataset.mkSheetfilter, null); return; }
          if (e.target.closest('#mk-filterreset')) { clearFilters(); applySort('default'); return; }
          if (e.target.closest('#mk-filterdone')) { close(); }
        });
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && !sheet.hidden) { close(); }
        });
      }
      var clearBtn = document.getElementById('mk-clearchips');
      if (clearBtn) { clearBtn.addEventListener('click', clearFilters); }

      /* ---------- banner carousel: dots + autoplay, RTL-aware ---------- */
      var track = document.getElementById('mk-bannertrack');
      var dots = document.getElementById('mk-bannerdots');
      if (track && track.children.length > 1) {
        var idx = 0, n = track.children.length, timer = null;
        var rtl = document.documentElement.dir === 'rtl';
        var mark = function (i) {
          if (!dots) { return; }
          [].forEach.call(dots.children, function (d, k) { d.classList.toggle('is-on', k === i); });
        };
        var go = function (i) {
          idx = (i + n) % n;
          var w = track.firstElementChild ? track.firstElementChild.offsetWidth + 12 : 0;
          track.scrollTo({ left: (rtl ? -1 : 1) * idx * w, behavior: 'smooth' });
          mark(idx);
        };
        var arm = function () {
          clearInterval(timer);
          timer = setInterval(function () { go(idx + 1); }, 4200);
        };
        // Manual swipes retake ownership: resync the index from scroll position.
        var st;
        track.addEventListener('scroll', function () {
          clearTimeout(st);
          st = setTimeout(function () {
            var w = track.firstElementChild ? track.firstElementChild.offsetWidth + 12 : 1;
            idx = Math.round(Math.abs(track.scrollLeft) / w) % n;
            mark(idx);
          }, 120);
        }, { passive: true });
        if (!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) { arm(); }
        document.addEventListener('visibilitychange', function () {
          if (document.hidden) { clearInterval(timer); } else { arm(); }
        });
      }

      /* ---------- splash: first visit per session ---------- */
      var splash = document.getElementById('mk-splash');
      if (splash) {
        var KEY = 'mk-splash-seen';
        var seen = null;
        try { seen = sessionStorage.getItem(KEY); } catch (e) { seen = '1'; }
        if (!seen) {
          splash.hidden = false;
          try { sessionStorage.setItem(KEY, '1'); } catch (e) { /* private mode */ }
          var dismiss = function () { splash.classList.add('is-done'); };
          setTimeout(dismiss, 2300);
          var skip = document.getElementById('mk-splash-skip');
          if (skip) { skip.addEventListener('click', dismiss); }
        }
      }

      apply();
    }
  };

  if (window.Mk && window.Mk.register) { window.Mk.register('home', mod); }
  else { document.addEventListener('DOMContentLoaded', function () { window.Mk && window.Mk.register('home', mod); }); }
})();
