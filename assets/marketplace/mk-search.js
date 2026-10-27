// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* T2-2: Fusion search module (lane L2 file — registered on MkModules). */
(function () {
  'use strict';

  function esc(s) { var d = document.createElement('span'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  var mod = {
    init: function (Mk) {
      var input = document.getElementById('mk-search-input');
      if (!input) { return; }

      var idle = document.getElementById('mk-search-idle');
      var skel = document.getElementById('mk-search-skel');
      var results = document.getElementById('mk-search-results');
      var elStores = document.getElementById('mk-sr-stores');
      var elItems = document.getElementById('mk-sr-items');
      var elDirectory = document.getElementById('mk-sr-directory');
      var elEmpty = document.getElementById('mk-sr-empty');
      var timer = null;
      var seq = 0;

      var RECENT_KEY = 'mk_recent_searches';
      function recent() {
        try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); } catch (e) { return []; }
      }
      function pushRecent(q) {
        var list = recent().filter(function (x) { return x !== q; });
        list.unshift(q);
        try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 8))); } catch (e) {}
      }
      function renderRecent() {
        var wrap = document.getElementById('mk-recent-wrap');
        var el = document.getElementById('mk-recent');
        var list = recent();
        if (!wrap || !el || !list.length) { return; }
        wrap.hidden = false;
        el.innerHTML = list.map(function (q) {
          return '<button type="button" class="mk-chip" data-q="' + esc(q) + '">' + esc(q) + '</button>';
        }).join('');
      }

      function storeRow(s) {
        return '<a class="mk-srow" href="/fusion/store/' + (s.slug||'') + '">' +
          '<img src="' + esc(s.logo) + '" alt="" data-csp-error="visibility">' +
          '<span class="mk-srow__name">' + esc(s.title) + '</span></a>';
      }
      function fmtPrice(it, symbol) {
        var n = Number(it.price);
        return (isFinite(n) ? n.toFixed(2).replace(/\.00$/, '') : esc(it.price)) + (symbol ? ' ' + esc(symbol) : '');
      }
      function itemRow(it, symbol) {
        return '<a class="mk-srow mk-srow--item" href="' + esc(it.link) + '">' +
          (it.image ? '<img src="' + esc(it.image) + '" alt="" data-csp-error="visibility">' : '<span class="mk-srow__ph"></span>') +
          '<span class="mk-srow__name">' + esc(it.name) + (it.sold_out ? ' <em>(' + (window.MkI18n && MkI18n.soldOut || 'sold out') + ')</em>' : '') + '</span>' +
          '<span class="mk-srow__price num">' + fmtPrice(it, symbol) + '</span></a>';
      }
      /* P4: item groups open with the demo's store head — logo chip + title. */
      function groupHead(store) {
        return '<a class="mk-srow mk-srow--grouphead" href="/fusion/store/' + (store.slug||'') + '">' +
          '<img src="' + esc(store.logo) + '" alt="" data-csp-error="visibility">' +
          '<span class="mk-srow__name">' + esc(store.title) + '</span></a>';
      }

      /* DIRECTORY T5-4: an unclaimed listing row — a fact card, never a
         store link (there is nothing to order from an unclaimed place). */
      function listingRow(l) {
        return '<a class="mk-srow" href="' + esc(l.url) + '">' +
          '<span class="mk-srow__ph"></span>' +
          '<span class="mk-srow__name">' + esc(l.name) + '</span></a>';
      }

      function render(json, q) {
        var dir = json.directory || { cuisine_matches: [], unclaimed: [] };
        // Cuisine-name matches are real stores — folded into the stores list
        // (deduped by id) rather than a separate section, since a match on
        // "Italian" should behave exactly like a match on a store's own name.
        var seen = {};
        var stores = (json.stores || []).slice();
        stores.forEach(function (s) { seen[s.id] = 1; });
        (dir.cuisine_matches || []).forEach(function (s) { if (!seen[s.id]) { seen[s.id] = 1; stores.push(s); } });

        var anyStores = stores.length;
        var anyItems = json.item_groups && json.item_groups.length;
        var anyUnclaimed = dir.unclaimed && dir.unclaimed.length;

        elStores.innerHTML = anyStores
          ? '<h2 class="mk-search__h">' + (window.MkI18n && MkI18n.stores || 'Stores') + '</h2>' + stores.map(storeRow).join('')
          : '';
        elItems.innerHTML = anyItems
          ? json.item_groups.map(function (g) {
              return groupHead(g.store) + g.items.map(function (it) { return itemRow(it, g.store.symbol); }).join('');
            }).join('')
          : '';
        elDirectory.innerHTML = anyUnclaimed
          ? '<h2 class="mk-search__h">' + (window.MkI18n && MkI18n.morePlaces || 'More places') + '</h2>' + dir.unclaimed.map(listingRow).join('')
          : '';
        elEmpty.hidden = !!(anyStores || anyItems || anyUnclaimed);
        var echo = document.getElementById('mk-sr-empty-q');
        if (echo) { echo.textContent = '“' + q + '”'; }
        idle.hidden = true; skel.hidden = true; results.hidden = false;
      }

      function run(q) {
        var mySeq = ++seq;
        idle.hidden = true; results.hidden = true; skel.hidden = false;
        Mk.get('/fusion/search/data?q=' + encodeURIComponent(q)).then(function (json) {
          if (mySeq !== seq) { return; }
          pushRecent(q);
          render(json, q);
        }).catch(function () {
          if (mySeq !== seq) { return; }
          skel.hidden = true; idle.hidden = false;
          Mk.toast((window.MkI18n && MkI18n.searchFailed) || 'Search failed — retry');
        });
      }

      input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) {
          seq++; skel.hidden = true; results.hidden = true; idle.hidden = false;
          return;
        }
        timer = setTimeout(function () { run(q); }, 280);
      });

      document.addEventListener('click', function (e) {
        var chip = e.target.closest('.mk-chip[data-q]');
        if (chip) { input.value = chip.dataset.q; run(chip.dataset.q); return; }
        if (e.target.closest('#mk-sr-clear')) {
          input.value = '';
          seq++; skel.hidden = true; results.hidden = true; idle.hidden = false;
          input.focus();
        }
      });

      renderRecent();

      // Deep-linked query (?q=) from home category circles: prefill + run.
      var preset = new URLSearchParams(location.search).get('q');
      if (preset && preset.trim().length >= 2) {
        input.removeAttribute('readonly');
        input.value = preset.trim();
        run(preset.trim());
      }
      input.focus();
    }
  };

  if (window.Mk && window.Mk.register) { window.Mk.register('search', mod); }
  else { document.addEventListener('DOMContentLoaded', function () { window.Mk && window.Mk.register('search', mod); }); }
})();
