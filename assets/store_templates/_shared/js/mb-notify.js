// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* mb-notify.js — shared storefront notification-center bell engine.
 *
 * Companion to the _partials/notif-bell.blade.php panel. One bell UI, two data
 * sources chosen by auth state (rev/notif-center-spec.md):
 *   - logged-in diner  → fetch the account inbox endpoint (server history).
 *   - guest            → build from the theme's recent orders (localStorage),
 *                        which the theme hands in via cfg.recent.
 *
 * The theme supplies a bell <button> (cfg.bellSel) and an optional dot element
 * (cfg.dotSel) in its own topbar; this file owns the panel, fetching, unread
 * badge, and mark-read. Push opt-in stays on the track page (per order token) —
 * the bell does not duplicate it.
 *
 * Init (from the partial, after DOM ready):
 *   window.mbNotify.init({
 *     bellSel, dotSel, panelSel,
 *     isLoggedIn, listUrl, readUrl, csrf,
 *     recent: [{title, body, url, at, read}],   // guest source (optional)
 *     strings: { title, empty, markRead }
 *   })
 */
(function (w, d) {
  'use strict';

  // Leading status glyphs (stroke inherits the theme's --nb-accent via .mb-nb-ic).
  var A = ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
  var SVG = {
    bag:     '<svg' + A + '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="m9 13 2 2 4-4"/></svg>',
    clock:   '<svg' + A + '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    check:   '<svg' + A + '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>',
    truck:   '<svg' + A + '<path d="M3 6h11v9H3z"/><path d="M14 9h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.6"/><circle cx="17" cy="17.5" r="1.6"/></svg>',
    xc:      '<svg' + A + '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/></svg>',
    receipt: '<svg' + A + '<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v4h4"/><path d="M9 12h6M9 16h6"/></svg>',
    bell:    '<svg' + A + '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
    bellOff: '<svg' + A + '<path d="M18 8a6 6 0 0 0-9.3-5"/><path d="M6 8c0 7-3 9-3 9h13"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/><path d="m3 3 18 18"/></svg>'
  };
  var ICONS = {
    ready: SVG.bag, completed: SVG.check, delivered: SVG.check,
    preparing: SVG.clock, 'new': SVG.clock, pending: SVG.clock, unpaid: SVG.clock,
    pending_approval: SVG.clock, call_bill: SVG.receipt,
    out_for_delivery: SVG.truck, cancelled: SVG.xc,
    receipt: SVG.receipt, bell: SVG.bell, bellOff: SVG.bellOff
  };

  var cfg = null, bell, dot, panel, listEl, titleEl, markBtn, seenKey, opened = false, items = [], hideT = null;
  var cardEl = null, lastFocus = null, baseLabel = '';

  function init(c) {
    if (!c || !c.bellSel) return;
    cfg = c;
    bell = d.querySelector(c.bellSel);
    if (!bell) return;                                   // theme didn't mount a bell — nothing to do
    dot = c.dotSel ? d.querySelector(c.dotSel) : null;
    panel = c.panelSel ? d.querySelector(c.panelSel) : null;
    listEl = panel ? panel.querySelector('[data-mb-nb-list]') : null;
    titleEl = panel ? panel.querySelector('[data-mb-nb-title]') : null;
    markBtn = panel ? panel.querySelector('[data-mb-nb-mark]') : null;
    seenKey = 'mb_nb_seen_' + String(w.MB_STORE || 'default');

    // A5: SR affordances on the theme-owned bell. Keep the theme's own
    // aria-label as the base text when it set one.
    baseLabel = bell.getAttribute('aria-label') || (c.strings && c.strings.title) || 'Notifications';
    bell.setAttribute('aria-haspopup', 'dialog');
    bell.setAttribute('aria-expanded', 'false');
    if (!bell.getAttribute('aria-label')) bell.setAttribute('aria-label', baseLabel);
    cardEl = panel ? panel.querySelector('.mb-nb-card') : null;

    bell.addEventListener('click', toggle);
    if (panel) {
      var close = panel.querySelector('[data-mb-nb-close]');
      if (close) close.addEventListener('click', hide);
      if (markBtn) markBtn.addEventListener('click', function (e) { e.stopPropagation(); markSeen(); });
      panel.addEventListener('click', function (e) { if (e.target === panel) hide(); });
      if (listEl) listEl.addEventListener('click', onItemClick);
      d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && opened) hide(); });
      d.addEventListener('keydown', trapTab);
    }
    // A4: the inbox goes stale while the menu tab sits open — refetch when the
    // tab becomes visible again. Guests no-op (localStorage is already fresh).
    d.addEventListener('visibilitychange', function () {
      if (!d.hidden && cfg.isLoggedIn) refresh();
    });
    refresh();
  }

  function refresh() {
    if (cfg.isLoggedIn && cfg.listUrl) fetchInbox();
    else buildGuest();
  }

  function fetchInbox() {
    fetch(cfg.listUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j) return;
        items = (j.notifications || []).map(function (n) {
          return { id: n.id, title: n.title, body: n.body, url: n.url, at: n.at, read: !!n.read };
        });
        paintBadge(typeof j.unread === 'number' ? j.unread : items.filter(function (n) { return !n.read; }).length);
        if (opened) renderList();
      })
      .catch(function () { /* offline / not-authed — leave bell quiet */ });
  }

  function buildGuest() {
    // Guest source: the theme's own cfg.recent if it handed one in, else the shared
    // store-scoped list the order-track page writes (mb_recent_orders_<store>).
    var recent = (cfg.recent && cfg.recent.length) ? cfg.recent : readRecentStore();
    recent = recent.slice(0, 20);
    var seen = readSeen();
    items = recent.map(function (n) {
      var sig = (n.url || n.title || '') + '|' + (n.body || '');
      return {
        title: n.title, body: n.body, url: n.url, at: n.at, status: n.status, type: n.type,
        read: n.read === true || seen.indexOf(sig) !== -1, _sig: sig
      };
    });
    paintBadge(items.filter(function (n) { return !n.read; }).length);
    if (opened) renderList();
  }

  function paintBadge(unread) {
    if (dot) dot.hidden = !(unread > 0);
    if (bell) {
      bell.setAttribute('data-mb-unread', unread > 0 ? String(unread) : '');
      // A5: unread count for screen readers via the bell's accessible name.
      var tpl = cfg.strings && cfg.strings.unreadLabel;
      bell.setAttribute('aria-label', (unread > 0 && tpl) ? tpl.replace(':count', String(unread)) : baseLabel);
    }
    if (markBtn) markBtn.hidden = !(unread > 0);
  }

  function toggle() { opened ? hide() : show(); }

  /* Open reveals the panel WITH unread items still highlighted (so the diner can
   * see what's new); the badge + rows are marked seen on close, not on open. */
  function show() {
    if (!panel) return;
    if (hideT) { clearTimeout(hideT); hideT = null; }
    opened = true;
    if (cfg.isLoggedIn) refresh();                       // A4: panel-open refetch (renders stale list now, repaints on response)
    renderList();
    panel.hidden = false;
    // A5: move focus into the dialog, remember where it came from.
    lastFocus = d.activeElement;
    bell.setAttribute('aria-expanded', 'true');
    if (cardEl && cardEl.focus) cardEl.focus();
    // next frame so the enter transition actually plays from the off state
    (w.requestAnimationFrame || setTimeout)(function () { panel.classList.add('is-open'); });
  }

  function hide() {
    if (!panel) return;
    opened = false;
    panel.classList.remove('is-open');
    bell.setAttribute('aria-expanded', 'false');
    markSeen();                                          // seen once dismissed
    hideT = setTimeout(function () { if (!opened) panel.hidden = true; }, 200);
    // A5: hand focus back to where it was (the bell, normally).
    var back = (lastFocus && d.contains(lastFocus)) ? lastFocus : bell;
    lastFocus = null;
    if (back && back.focus) back.focus();
  }

  /* A5: keep Tab inside the open dialog (simple wrap-around trap). */
  function trapTab(e) {
    if (e.key !== 'Tab' || !opened || !panel) return;
    var f = panel.querySelectorAll('a[href], button:not([hidden]), [tabindex]:not([tabindex="-1"])');
    if (!f.length) { e.preventDefault(); return; }
    var first = f[0], last = f[f.length - 1], a = d.activeElement;
    if (e.shiftKey && (a === first || a === cardEl || !panel.contains(a))) {
      e.preventDefault(); last.focus();
    } else if (!e.shiftKey && a === last) {
      e.preventDefault(); first.focus();
    }
  }

  function iconFor(n) {
    // Guest rows carry a raw status; account-inbox rows are order updates.
    var k = n.status || (n.type === 'order_status' ? 'receipt' : 'bell');
    return ICONS[k] || ICONS.bell;
  }

  function renderList() {
    if (!listEl) return;
    var s = cfg.strings || {};
    if (titleEl && s.title) titleEl.textContent = s.title;
    if (markBtn && s.markRead) markBtn.textContent = s.markRead;
    if (!items.length) {
      listEl.innerHTML = '<div class="mb-nb-empty">' + ICONS.bellOff + '<span>' + esc(s.empty || 'No notifications') + '</span></div>';
      return;
    }
    listEl.innerHTML = items.map(function (n) {
      var tag = n.url ? 'a' : 'div';
      var href = n.url ? ' href="' + esc(n.url) + '"' : '';
      return '<' + tag + ' class="mb-nb-item' + (n.read ? '' : ' is-unread') + '" data-nb-i="' + items.indexOf(n) + '"' + href + '>'
        + '<span class="mb-nb-ic">' + iconFor(n) + '</span>'
        + '<span class="mb-nb-tx">'
        + '<b class="mb-nb-item-t">' + esc(n.title || '') + '</b>'
        + (n.body ? '<span class="mb-nb-item-b">' + esc(n.body) + '</span>' : '')
        + (n.at ? '<time class="mb-nb-item-a">' + esc(fmtTime(n.at)) + '</time>' : '')
        + '</span>'
        + '</' + tag + '>';
    }).join('');
  }

  /* A3: clicking an item marks THAT item read before the navigation happens.
   * Items are <a> — navigation kills the page, so the inbox POST uses
   * keepalive to survive it. Never preventDefault: the click must still go
   * through to the order-track page. */
  function onItemClick(e) {
    var el = e.target && e.target.closest ? e.target.closest('.mb-nb-item') : null;
    if (!el || !listEl.contains(el)) return;
    var i = parseInt(el.getAttribute('data-nb-i'), 10);
    var n = (i >= 0 && i < items.length) ? items[i] : null;
    if (!n || n.read) return;
    n.read = true;
    el.classList.remove('is-unread');
    paintBadge(items.filter(function (x) { return !x.read; }).length);
    if (cfg.isLoggedIn && cfg.readUrl && n.id) {
      fetch(cfg.readUrl, {
        method: 'POST',
        keepalive: true,
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': cfg.csrf || '',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ ids: [n.id] })
      }).catch(function () {});
    } else if (!cfg.isLoggedIn && n._sig) {
      var seen = readSeen();
      if (seen.indexOf(n._sig) === -1) { seen.push(n._sig); writeSeen(seen); }
    }
  }

  /* Dismissing the panel clears unread — server-side for the inbox, localStorage
   * for guests. Also fired by the explicit "mark all read" button. */
  function markSeen() {
    var hadUnread = items.some(function (n) { return !n.read; });
    if (!hadUnread) return;
    items.forEach(function (n) { n.read = true; });
    paintBadge(0);
    if (opened) renderList();
    if (cfg.isLoggedIn && cfg.readUrl) {
      fetch(cfg.readUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': cfg.csrf || '' },
        credentials: 'same-origin'
      }).catch(function () {});
    } else {
      writeSeen(items.map(function (n) { return n._sig; }).filter(Boolean));
    }
  }

  function readRecentStore() {
    try {
      var key = 'mb_recent_orders_' + String(w.MB_STORE || 'default');
      return JSON.parse(w.localStorage.getItem(key) || '[]') || [];
    } catch (e) { return []; }
  }

  function readSeen() {
    try { return JSON.parse(w.localStorage.getItem(seenKey) || '[]') || []; } catch (e) { return []; }
  }
  function writeSeen(sigs) {
    try { w.localStorage.setItem(seenKey, JSON.stringify(sigs.slice(-50))); } catch (e) {}
  }

  function fmtTime(iso) {
    try {
      var dt = new Date(iso), now = new Date(), ms = now - dt;
      if (ms < 60000) return 'now';
      if (ms < 3600000) return Math.floor(ms / 60000) + 'm';
      if (ms < 86400000) return Math.floor(ms / 3600000) + 'h';
      return dt.toLocaleDateString();
    } catch (e) { return ''; }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  w.mbNotify = { init: init, refresh: refresh };
})(window, document);
