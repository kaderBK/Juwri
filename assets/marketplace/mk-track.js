// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ============================================================
   Fusion marketplace — mk-track.js
   STATUS 5.2 (fusion-crave): the multi-order live tracking page.

   Owns: order tabs, the 4-step lifecycle timeline, the courier card
   (call / WhatsApp / partner tracking), the live driver map, and the
   10s feed poll behind /fusion/orders/track/feed.

   Style follows mk-store.js / mk-discovery.js: one IIFE, ES5-ish, no
   framework, registered through window.Mk.register so mk-core.js is
   never edited. Every DOM hook is feature-detected — the blade is
   authored in parallel, so a missing element is a no-op, never a crash.

   ---- PRIVACY (mirrors TrackController::feedPayload) ----------------
   driver.lat/lng are withheld by the server at every stage except
   out_for_delivery, and courier.lat/lng except picked_up. This module
   therefore treats coordinates as ALWAYS OPTIONAL: the map element stays
   hidden and no marker is drawn until real numbers arrive, and the map
   is torn back down the moment they stop arriving. Nothing in the UI
   ever implies a position we were not given — a courier with only a
   name/phone renders as a name/phone, never as a dot on a map.

   ---- POLLING -------------------------------------------------------
   * every 10s while the page is visible;
   * paused entirely while document.hidden (a backgrounded tab makes zero
     requests) and resumed with one immediate fetch on becoming visible;
   * stopped for good once EVERY tracked order is terminal
     (status 'completed' or 'cancelled') — nothing left to watch.
   ============================================================ */
(function () {
  'use strict';

  /* ---------- tiny helpers ---------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }

  var cfg = window.MkTrackPage || null;
  var TXT = (cfg && cfg.txt) || {};
  var STAGES = (cfg && cfg.stages) || {};

  /* Localized strings come from the blade (window.MkTrackPage.txt); the
     fallbacks keep the page usable if a key was never rendered. */
  function t(key, fallback) {
    return (TXT && TXT[key]) || fallback;
  }

  /* The exact order lifecycle: ManagesOrders::trackOrder /
     store_templates/aurora/track.blade.php. Nothing here is invented. */
  var STEP_ORDER = ['received', 'preparing', 'ready', 'completed'];
  var TERMINAL = ['completed', 'cancelled'];

  /* status → the feed key holding that step's real timestamp. 'received'
     is server-rendered from the order's created_at, so it is not patched. */
  var STAMP_KEY = { preparing: 'preparing_at', ready: 'ready_at', completed: 'completed_at' };

  function isNum(v) {
    return typeof v === 'number' && isFinite(v);
  }
  /* The feed sends floats, but be liberal: a numeric string is still a
     real coordinate, anything else (null/undefined/'') is "withheld". */
  function coord(v) {
    if (isNum(v)) { return v; }
    if (typeof v === 'string' && v !== '') {
      var n = parseFloat(v);
      return isFinite(n) ? n : null;
    }
    return null;
  }
  function minutes(n) {
    return String(t('minutes', '%d min')).replace('%d', n);
  }
  function clock(iso) {
    if (!iso) { return null; }
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return null; }
    try {
      return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
      return null;
    }
  }
  /* wa.me wants bare digits. A number too short to be a real phone gets no
     link at all rather than a link that dials nothing. */
  function waDigits(phone) {
    var d = String(phone || '').replace(/\D/g, '');
    return d.length >= 6 ? d : '';
  }
  function show(el, on) {
    if (el) { el.hidden = !on; }
  }

  /* ---------- tabs ---------- */
  var tabs = {
    init: function () {
      var bar = $('[data-tk-tabs]');
      if (!bar) { return; }
      bar.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-tk-tab]') : null;
        if (!btn) { return; }
        tabs.select(btn.getAttribute('data-tk-tab'));
      });
    },
    select: function (token) {
      $$('[data-tk-tab]').forEach(function (b) {
        var on = b.getAttribute('data-tk-tab') === token;
        b.classList.toggle('is-on', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      $$('[data-mk-track]').forEach(function (panel) {
        var on = panel.getAttribute('data-mk-track') === token;
        panel.hidden = !on;
        /* A Leaflet map sized while hidden measures 0×0. */
        if (on) { maps.resize(token); }
      });
    }
  };

  /* ---------- driver map (only ever built from real coordinates) ---------- */
  var maps = {
    live: {},

    hasLeaflet: function () { return typeof window.L !== 'undefined'; },

    /* Build lazily: the map element exists in the DOM from first paint but
       stays hidden until the feed hands us a driver position. */
    ensure: function (card, token) {
      if (maps.live[token]) { return maps.live[token]; }
      if (!maps.hasLeaflet()) { return null; }
      var el = card.querySelector('[data-tk-map]');
      if (!el) { return null; }

      el.hidden = false;
      if (cfg && cfg.leafletImages) {
        window.L.Icon.Default.prototype.options.imagePath = cfg.leafletImages;
      }
      var map = window.L.map(el, { scrollWheelZoom: false, attributionControl: false, zoomControl: false });
      window.L.tileLayer((cfg && cfg.tileUrl) || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap'
      }).addTo(map);

      /* Context pins the shopper already knows about — the store they
         ordered from and the address they typed. Neither is private. */
      var pts = [];
      var sLat = coord(card.dataset.storeLat), sLng = coord(card.dataset.storeLng);
      var dLat = coord(card.dataset.dropLat), dLng = coord(card.dataset.dropLng);
      if (sLat !== null && sLng !== null) {
        window.L.marker([sLat, sLng], { title: t('store', 'Store') }).addTo(map);
        pts.push([sLat, sLng]);
      }
      if (dLat !== null && dLng !== null) {
        window.L.marker([dLat, dLng], { title: t('dropoff', 'Delivery address') }).addTo(map);
        pts.push([dLat, dLng]);
      }

      maps.live[token] = { map: map, el: el, pts: pts, marker: null };
      return maps.live[token];
    },

    /* Tear the map back down when coordinates stop arriving (delivered,
       failed, or simply a status that no longer exposes a position). */
    drop: function (token) {
      var entry = maps.live[token];
      if (!entry) { return; }
      if (entry.marker) { entry.map.removeLayer(entry.marker); entry.marker = null; }
      show(entry.el, false);
    },

    resize: function (token) {
      var entry = maps.live[token];
      if (entry && !entry.el.hidden) { entry.map.invalidateSize(); }
    },

    /* driver may be null, or present with lat/lng null — both mean
       "no position", and both must render as no map. */
    position: function (card, token, driver, courier) {
      var src = null;
      if (driver && coord(driver.lat) !== null && coord(driver.lng) !== null) { src = driver; }
      else if (courier && coord(courier.lat) !== null && coord(courier.lng) !== null) { src = courier; }

      if (!src) { maps.drop(token); return; }

      var entry = maps.ensure(card, token);
      if (!entry) { return; }
      show(entry.el, true);

      var ll = [coord(src.lat), coord(src.lng)];
      if (entry.marker) { entry.marker.setLatLng(ll); }
      else { entry.marker = window.L.marker(ll, { title: t('driver', 'Courier') }).addTo(entry.map); }
      entry.map.invalidateSize();
      entry.map.fitBounds(window.L.latLngBounds(entry.pts.concat([ll])).pad(0.3));
    }
  };

  /* ---------- one order panel ---------- */
  function renderCourier(card, o) {
    var box = card.querySelector('[data-tk-courier]');
    if (!box) { return; }
    var nameEl = box.querySelector('[data-tk-courier-name]');
    var stateEl = box.querySelector('[data-tk-courier-state]');
    var actions = box.querySelector('[data-tk-courier-actions]');
    var call = box.querySelector('[data-tk-call]');
    var wa = box.querySelector('[data-tk-wa]');
    var partner = box.querySelector('[data-tk-couriertrack]');

    /* Stash the blade's server-rendered "not assigned yet" copy on the very
       first pass, so a later teardown restores those exact localized words
       rather than whatever a driver left behind. */
    if (nameEl && nameEl.dataset.empty === undefined) { nameEl.dataset.empty = nameEl.textContent; }
    if (stateEl && stateEl.dataset.empty === undefined) { stateEl.dataset.empty = stateEl.textContent; }

    var anyAction = false;

    /* Own-fleet driver wins: a real person with a real phone. */
    if (o.driver && (o.driver.name || o.driver.phone)) {
      if (nameEl) { nameEl.textContent = o.driver.name || t('driver', 'Courier'); }
      if (stateEl) {
        if (o.delivery_status === 'out_for_delivery') {
          stateEl.textContent = t('driverOnWay', 'On the way with your order');
        } else if (o.delivery_status === 'delivered') {
          stateEl.textContent = t('driverDelivered', 'Delivered your order');
        } else {
          stateEl.textContent = t('driverAssigned', 'Assigned to your order');
        }
      }
      /* Both actions are gated on the SAME digit test: a phone field holding
         something undiallable ("n/a", "-") must produce no link at all rather
         than a tel: that dials nothing. */
      var digits = waDigits(o.driver.phone);
      if (digits && call) {
        call.href = 'tel:' + String(o.driver.phone).replace(/[^\d+]/g, '');
        show(call, true); anyAction = true;
      } else if (call) {
        call.removeAttribute('href'); show(call, false);
      }
      if (digits) {
        if (wa) { wa.href = 'https://wa.me/' + digits; show(wa, true); anyAction = true; }
      } else if (wa) {
        wa.removeAttribute('href'); show(wa, false);
      }
    } else if (o.courier) {
      /* Third-party courier: a company, not a person. No phone is exposed,
         so no call/WhatsApp button is drawn — only the partner's own link. */
      if (nameEl) { nameEl.textContent = t('courierPartner', 'Delivery partner'); }
      if (stateEl) {
        var line = (TXT.courier && TXT.courier[o.courier.status]) || o.courier.status || '';
        if (o.courier.eta_minutes) { line += ' · ~' + minutes(o.courier.eta_minutes); }
        stateEl.textContent = line;
      }
      if (call) { call.removeAttribute('href'); show(call, false); }
      if (wa) { wa.removeAttribute('href'); show(wa, false); }
    } else {
      /* Honest empty state — never a placeholder name. */
      if (nameEl) { nameEl.textContent = nameEl.dataset.empty; }
      if (stateEl) { stateEl.textContent = stateEl.dataset.empty; }
      if (call) { call.removeAttribute('href'); show(call, false); }
      if (wa) { wa.removeAttribute('href'); show(wa, false); }
    }

    if (partner) {
      if (o.courier && o.courier.tracking_url) {
        partner.href = o.courier.tracking_url; show(partner, true); anyAction = true;
      } else {
        partner.removeAttribute('href'); show(partner, false);
      }
    }
    show(actions, anyAction);
  }

  function renderSteps(card, o, stage) {
    var reached = stage.step ? STEP_ORDER.indexOf(stage.step) : -1;
    $$('[data-tk-steps] .mk-timeline__step', card).forEach(function (li, i) {
      li.classList.toggle('is-done', reached >= 0 && i <= reached);
      li.classList.toggle('is-now', i === reached);
      var key = li.getAttribute('data-step');
      var slot = li.querySelector('[data-tk-step-time]');
      if (!slot || !STAMP_KEY[key]) { return; }
      var stamped = clock(o[STAMP_KEY[key]]);
      slot.textContent = stamped || '—';
    });
  }

  function renderEta(card, o, stage) {
    /* Headline "arrives in": the courier's own promise beats our estimate. */
    var val = card.querySelector('[data-tk-etaval]');
    if (val) {
      if (stage.step === 'completed') { val.textContent = '✓'; }
      else if (o.courier && o.courier.eta_minutes) { val.textContent = '~' + minutes(o.courier.eta_minutes); }
      else if (o.eta && o.eta.text) { val.textContent = minutes(o.eta.text); }
      else { val.textContent = '—'; }
    }

    /* Prep-time line — the EtaRange::toArray()['line'] string, already
       localized server-side. Vanishes as soon as the kitchen stops
       estimating (the feed stops sending eta at 'ready'). */
    var prep = card.querySelector('[data-tk-prep]');
    var prepTxt = card.querySelector('[data-tk-prep-text]');
    if (prep && prepTxt) {
      if (o.eta && o.eta.line) { prepTxt.textContent = o.eta.line; show(prep, true); }
      else { prepTxt.textContent = ''; show(prep, false); }
    }
  }

  function render(card, token, o) {
    if (!o) { return; }
    var stage = STAGES[o.status] || STAGES.pending || { badge: o.status, desc: '', cls: '', step: 'received' };
    var badgeTxt = stage.badge;
    var descTxt = stage.desc;

    /* The 'ready' stage means two different things depending on fulfilment. */
    if (o.status === 'ready') {
      if (o.type === 'delivery') {
        badgeTxt = t('onTheWayBadge', 'On the way');
        descTxt = t('outForDelivery', descTxt);
      } else if (o.type === 'takeaway') {
        descTxt = t('readyAtCounter', descTxt);
      }
    }

    var badge = card.querySelector('[data-tk-badge]');
    if (badge) {
      badge.textContent = badgeTxt;
      badge.className = 'mk-orderstatus' + (stage.cls ? ' ' + stage.cls : '');
    }
    var desc = card.querySelector('[data-tk-desc]');
    if (desc) { desc.textContent = descTxt || ''; }

    renderEta(card, o, stage);
    renderSteps(card, o, stage);
    renderCourier(card, o);
    maps.position(card, token, o.driver, o.courier);
  }

  /* ---------- feed poll ---------- */
  var poll = {
    every: 10000,
    timer: null,
    stopped: false,

    tokens: function () {
      return $$('[data-mk-track]').map(function (c) { return c.getAttribute('data-mk-track'); });
    },

    apply: function (feed) {
      if (!feed) { return; }
      $$('[data-mk-track]').forEach(function (card) {
        render(card, card.getAttribute('data-mk-track'), feed[card.getAttribute('data-mk-track')]);
      });
      /* Nothing left to watch → stop for good (not merely pause). */
      var done = poll.tokens().every(function (tk) {
        var o = feed[tk];
        return !!o && TERMINAL.indexOf(o.status) !== -1;
      });
      if (done) { poll.stop(); }
    },

    stop: function () {
      poll.stopped = true;
      if (poll.timer) { clearTimeout(poll.timer); poll.timer = null; }
      var note = $('[data-tk-refreshnote]');
      if (note && TXT.allDone) { note.textContent = TXT.allDone; }
    },

    schedule: function () {
      if (poll.stopped || poll.timer || document.hidden) { return; }
      poll.timer = setTimeout(poll.tick, poll.every);
    },

    tick: function () {
      poll.timer = null;
      /* Backgrounded tabs make no requests at all; visibilitychange resumes. */
      if (poll.stopped || document.hidden || !cfg || !cfg.feedUrl) { return; }
      fetch(cfg.feedUrl, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) { if (j && j.orders) { poll.apply(j.orders); } })
        .catch(function () { /* transient blip — the next tick retries */ })
        .then(function () { poll.schedule(); });
    },

    init: function () {
      document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
          if (poll.timer) { clearTimeout(poll.timer); poll.timer = null; }
          return;
        }
        /* Back in view: one immediate catch-up fetch, then resume the cadence. */
        poll.tick();
      });
      poll.schedule();
    }
  };

  /* ---------- module ---------- */
  var mod = {
    init: function () {
      if (!cfg || !$('[data-mk-track]')) { return; }
      tabs.init();
      poll.apply(cfg.feed || {});
      poll.init();
    }
  };

  if (window.Mk && window.Mk.register) {
    window.Mk.register('track', mod);
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      if (window.Mk && window.Mk.register) { window.Mk.register('track', mod); }
    });
  }
})();
