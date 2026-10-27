// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Owner UI kit — page shell behaviour (<x-ui.layout>, WI-16a).
 *
 *   [data-ui-drawer-toggle]   opens/closes the sidebar drawer (below 1200px)
 *   [data-ui-drawer-close]    closes it (its × and the scrim)
 *   [data-ui-menu]            phone tab bar "More" → opens the drawer
 *   [data-ui-side-tab="key"]  shows that sidebar section's panel
 *   [data-ui-pop] … [data-ui-pop-toggle] + [data-ui-pop-panel]
 *                             small pop-over menus; one open at a time, Esc and
 *                             outside click close, focus returns to the toggle
 *   [data-ui-theme-toggle]    light → dark → follow the system → light
 *   select[data-ui-autosubmit][data-ui-action]
 *                             sets the form's context_action and submits on change
 *
 *   Alpine components (the top bar): topbarSearch(items, url), notifBell(url, seenUrl, token)
 *
 * Every listener is delegated on document, so Turbo body swaps need no re-binding.
 */
(function () {
  'use strict';

  if (window.__uiShell) {
    return;
  }
  window.__uiShell = true;

  var doc = document;

  // ── Drawer ───────────────────────────────────────────────────────────
  function side() { return doc.getElementById('appMenubar'); }

  function setDrawer(open) {
    var el = side();
    if (!el) { return; }
    el.classList.toggle('is-open', open);
    doc.querySelectorAll('.ui-side-scrim').forEach(function (s) { s.hidden = !open; });
    doc.querySelectorAll('[data-ui-drawer-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    if (open) {
      var first = el.querySelector('.ui-side__section[aria-selected="true"], a, button');
      if (first) { first.focus({ preventScroll: true }); }
    }
  }

  // ── Pop-overs ────────────────────────────────────────────────────────
  function closePops(except) {
    doc.querySelectorAll('[data-ui-pop]').forEach(function (host) {
      if (host === except) { return; }
      var panel = host.querySelector('[data-ui-pop-panel]');
      var toggle = host.querySelector('[data-ui-pop-toggle]');
      if (panel && !panel.hidden) {
        panel.hidden = true;
        if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
      }
    });
  }

  function togglePop(host) {
    var panel = host.querySelector('[data-ui-pop-panel]');
    var toggle = host.querySelector('[data-ui-pop-toggle]');
    if (!panel) { return; }
    var open = panel.hidden;
    closePops(host);
    panel.hidden = !open;
    if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    if (open) {
      var first = panel.querySelector('select, a[href], button:not([disabled])');
      if (first && first.tagName !== 'SELECT') { first.focus({ preventScroll: true }); }
    }
  }

  // ── Theme ────────────────────────────────────────────────────────────
  function themeMode() {
    var m = doc.cookie.match(/(?:^|; )theme=(light|dark)(?:;|$)/);
    return m ? m[1] : 'system';
  }

  function syncThemeButtons() {
    var dark = doc.documentElement.getAttribute('data-theme') === 'dark';
    doc.querySelectorAll('[data-ui-theme-toggle]').forEach(function (b) {
      b.setAttribute('aria-pressed', dark ? 'true' : 'false');
      var label = b.getAttribute(dark ? 'data-label-light' : 'data-label-dark');
      if (label) { b.setAttribute('aria-label', label); }
    });
  }

  function cycleTheme() {
    var next = { light: 'dark', dark: 'system', system: 'light' }[themeMode()];
    if (next === 'system') {
      doc.cookie = 'theme=; path=/; max-age=0';
    } else {
      doc.cookie = 'theme=' + next + '; path=/; max-age=31536000';
    }
    // Pages outside the kit still read this older key when no cookie is set;
    // clear it so "system" really follows the OS everywhere.
    try { localStorage.removeItem('dash.theme'); localStorage.removeItem('lamar-theme'); } catch (e) {} // brand-alias: pre-P1-01 key
    if (window.uiShellPaintTheme) { window.uiShellPaintTheme(); }
    syncThemeButtons();
    window.dispatchEvent(new CustomEvent('ui:theme', { detail: next }));
  }

  new MutationObserver(syncThemeButtons).observe(doc.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  // ── Sidebar sections ─────────────────────────────────────────────────
  function showSection(key) {
    doc.querySelectorAll('[data-ui-side-tab]').forEach(function (t) {
      t.setAttribute('aria-selected', t.getAttribute('data-ui-side-tab') === key ? 'true' : 'false');
    });
    doc.querySelectorAll('[data-ui-side-panel]').forEach(function (p) {
      p.hidden = p.getAttribute('data-ui-side-panel') !== key;
    });
  }

  // ── Delegated events ─────────────────────────────────────────────────
  doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) { return; }

    var popToggle = t.closest('[data-ui-pop-toggle]');
    if (popToggle) {
      var host = popToggle.closest('[data-ui-pop]');
      if (host) { e.preventDefault(); togglePop(host); }
      return;
    }
    if (!t.closest('[data-ui-pop-panel]')) { closePops(null); }

    if (t.closest('[data-ui-drawer-toggle]')) {
      e.preventDefault();
      var el = side();
      setDrawer(!(el && el.classList.contains('is-open')));
      return;
    }
    if (t.closest('[data-ui-drawer-close]')) { e.preventDefault(); setDrawer(false); return; }
    if (t.closest('[data-ui-menu]') && !doc.querySelector('.app-toggler')) { e.preventDefault(); setDrawer(true); return; }

    var tab = t.closest('[data-ui-side-tab]');
    if (tab) { e.preventDefault(); showSection(tab.getAttribute('data-ui-side-tab')); return; }

    if (t.closest('[data-ui-theme-toggle]')) { e.preventDefault(); cycleTheme(); }
  });

  doc.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    var open = doc.querySelector('[data-ui-pop-panel]:not([hidden])');
    if (open) {
      var host = open.closest('[data-ui-pop]');
      closePops(null);
      var toggle = host && host.querySelector('[data-ui-pop-toggle]');
      if (toggle) { toggle.focus(); }
      return;
    }
    var el = side();
    if (el && el.classList.contains('is-open')) {
      setDrawer(false);
      var btn = doc.querySelector('[data-ui-drawer-toggle]');
      if (btn) { btn.focus(); }
    }
  });

  doc.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel || !sel.matches || !sel.matches('select[data-ui-autosubmit]') || !sel.form) { return; }
    var field = sel.form.elements.context_action;
    if (field) { field.value = sel.getAttribute('data-ui-action') || ''; }
    if (typeof sel.form.requestSubmit === 'function') { sel.form.requestSubmit(); } else { sel.form.submit(); }
  });

  // Turbo keeps the <html> element: close whatever was open before the swap.
  doc.addEventListener('turbo:before-render', function () { closePops(null); setDrawer(false); });
  doc.addEventListener('turbo:load', syncThemeButtons);
  doc.addEventListener('DOMContentLoaded', syncThemeButtons);

  // ── Alpine: notification bell ────────────────────────────────────────
  window.notifBell = function (url, seenUrl, token) {
    return {
      url: url,
      seenUrl: seenUrl || '',
      token: token || '',
      loaded: false,
      timer: null,
      prevOrders: null, // sound baseline; null until the first load
      prevTotal: null,  // motion baseline; null until the first load
      pop: false,       // badge pop + bell shake, only on a real rise
      counts: { orders: 0, reviews: 0, disputes: 0, waitlist: 0, webhooks: 0, total: 0, badge: false },
      init: function () {
        var self = this;
        this.fetch(true);
        clearInterval(this.timer);
        this.timer = setInterval(function () { self.fetch(false); }, 30000);
      },
      destroy: function () { clearInterval(this.timer); },
      pulse: function () {
        var self = this;
        this.pop = false;
        requestAnimationFrame(function () {
          self.pop = true;
          setTimeout(function () { self.pop = false; }, 560);
        });
      },
      ensure: function () { if (!this.loaded) { this.fetch(true); } },
      // Opening the bell means the owner has seen it: silence the new-order chime
      // (a later order re-arms it) and stamp the read watermark so the badge clears.
      markSeen: function () {
        var self = this;
        if (window.NotifySounds) { NotifySounds.stopRepeat('order_new'); }
        if (!this.seenUrl) { return; }
        fetch(this.seenUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.token, 'Accept': 'application/json' }, credentials: 'same-origin' })
          .then(function () { self.counts.badge = false; })
          .catch(function () {});
      },
      fetch: function (silent) {
        fetch(this.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
          .then(function (r) {
            if (window.LivePoll && !window.LivePoll.guard(r)) { return null; }
            return r.ok ? r.json() : null;
          })
          .then((d) => {
            if (!d) { return; }
            // Sounds only when a count rises after the first load (owner-configured per event).
            if (window.NotifySounds) {
              var orders = d.orders || 0;
              if (!silent && this.prevOrders !== null && orders > this.prevOrders) { NotifySounds.startRepeat('order_new'); }
              if (orders === 0) { NotifySounds.stopRepeat('order_new'); }
              ['review', 'dispute', 'waitlist', 'webhooks'].forEach((k) => {
                var key = k === 'dispute' ? 'disputes' : k;
                if (!silent && this.prevOrders !== null && (d[key] || 0) > (this.counts[key] || 0)) { NotifySounds.play(k); }
              });
            }
            // Motion only on a real rise: never on page load or a Turbo visit.
            var total = d.total || 0;
            if (!silent && this.prevTotal !== null && total > this.prevTotal) { this.pulse(); }
            this.prevTotal = total;
            this.prevOrders = d.orders || 0;
            this.counts = d;
            this.loaded = true;
          })
          .catch(function () {});
      },
    };
  };

  // ── Alpine: page search (Cmd/Ctrl-K) ─────────────────────────────────
  var RECENT_KEY = 'ui-nav-recent';

  function readRecent() {
    var raw = null;
    try { raw = localStorage.getItem(RECENT_KEY) || localStorage.getItem('lamar_nav_recent'); } catch (e) {} // brand-alias: pre-WI-16 key (read fallback)
    try { var list = JSON.parse(raw || '[]'); return Array.isArray(list) ? list : []; } catch (e) { return []; }
  }

  window.topbarSearch = function (items, searchUrl) {
    return {
      items: Array.isArray(items) ? items : [],
      searchUrl: searchUrl || '',
      q: '',
      open: false,
      cursor: 0,
      results: [],
      entity: [], // server hits: orders, items, customers, actions, docs
      _t: null,
      init: function () { this.results = this.compute(); },
      recents: function () {
        var byUrl = {};
        this.items.forEach(function (i) { byUrl[i.url] = i; });
        return readRecent().map(function (u) { return byUrl[u]; }).filter(Boolean).slice(0, 5);
      },
      // Same tokenising and scoring shape as the server search, so the instant
      // page list and the server list never reorder against each other.
      terms: function (q) {
        var raw = q.toLowerCase().split(/[^\p{L}\p{N}_]+/u).filter(Boolean);
        return raw.filter(function (t, i) { return raw.indexOf(t) === i && (t.length >= 2 || /^[0-9]+$/.test(t)); });
      },
      score: function (hay, terms) {
        var score = 0, hits = 0;
        terms.forEach(function (term) {
          var at = hay.indexOf(term);
          if (at === -1) { return; }
          var s = 3;
          if (at === 0 || /[^\p{L}\p{N}]/u.test(hay[at - 1])) { s += 2; }
          hits++;
          score += s;
        });
        if (!hits) { return 0; }
        if (hits === terms.length) { score += 5; }
        if (hay === terms.join(' ')) { score += 10; }
        return hits * 100 + score;
      },
      compute: function () {
        var self = this;
        var q = this.q.trim().toLowerCase();
        if (!q) {
          var r = this.recents();
          return r.length ? r : this.items.slice(0, 5);
        }
        var terms = this.terms(q);
        var scored = [];
        this.items.forEach(function (i) {
          var hay = (i.label + ' ' + (i.group || '')).toLowerCase();
          var s = terms.length ? self.score(hay, terms) : (hay.indexOf(q) !== -1 ? 1 : 0);
          if (s > 0) { scored.push({ i: i, s: s }); }
        });
        scored.sort(function (a, b) { return b.s - a.s; });
        return scored.slice(0, 8).map(function (x) { return x.i; }).concat(this.entity);
      },
      fetchEntities: function () {
        var self = this;
        var q = this.q.trim();
        if (!this.searchUrl || q.length < 2) { this.entity = []; this.results = this.compute(); return; }
        clearTimeout(this._t);
        this._t = setTimeout(function () {
          fetch(self.searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
              if (!d || self.q.trim() !== q) { return; } // a newer query is on its way
              var flat = [];
              (d.groups || []).forEach(function (g) {
                if (g.key === 'pages') { return; } // pages are matched locally
                (g.items || []).forEach(function (it) { flat.push({ label: it.label, url: it.url, group: g.label }); });
              });
              self.entity = flat;
              self.results = self.compute();
            })
            .catch(function () {});
        }, 180);
      },
      onFocus: function () { this.open = true; this.results = this.compute(); },
      onInput: function () { this.cursor = 0; this.open = true; this.results = this.compute(); this.fetchEntities(); },
      focusInput: function () { if (this.$refs.input) { this.$refs.input.focus(); } },
      move: function (d) {
        if (!this.open) { this.open = true; this.results = this.compute(); }
        var n = this.results.length;
        if (!n) { return; }
        this.cursor = (this.cursor + d + n) % n;
      },
      choose: function () {
        var item = this.results[this.cursor];
        if (!item || !item.url) { return; }
        this.remember(item);
        window.location.href = item.url;
      },
      remember: function (item) {
        if (!item || !item.url) { return; }
        var urls = [item.url].concat(readRecent().filter(function (u) { return u !== item.url; })).slice(0, 5);
        try { localStorage.setItem(RECENT_KEY, JSON.stringify(urls)); } catch (e) {}
      },
      close: function () { this.open = false; },
    };
  };
})();
