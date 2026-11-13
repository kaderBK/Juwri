// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ============================================================
   Directory module — dir-map.js (T5-3)
   Leaflet + OSM tiles, both vendored locally under
   public/assets/vendor/leaflet/ (no CDN — CSP + offline). Clustering via the
   also-vendored leaflet.markercluster plugin: claimed pins in the brand
   colour, unclaimed in grey, two independent cluster groups so a cluster
   bubble never mixes the two.

   Neither this file nor the libraries it loads are required for a complete
   page: `#dir-map` only exists when `?view=map`, and its own <noscript>
   fallback (explore.blade.php) tells a no-JS visitor to use the list. Every
   filter still applies to pins via the same DirQueryService the grid uses
   (server-side, GET /fusion/explore/pins/data).

   Exposes window.dirMap.reinit() so dir-explore.js can re-run this after an
   AJAX swap replaces #dir-map with a fresh DOM node (a Leaflet instance
   bound to the OLD node would otherwise be orphaned).
   Bump ?v (config('marketplace.asset_v')) on every edit + keep sw.js
   precache in sync.
   ============================================================ */
(function () {
  'use strict';

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var current = null; // { map, root } for the node currently bound, or null

  function loadCss(href) {
    if (document.querySelector('link[href="' + href + '"]')) { return; }
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    document.head.appendChild(link);
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var existing = document.querySelector('script[src="' + src + '"]');
      if (existing) { resolve(); return; }
      var s = document.createElement('script');
      s.src = src;
      s.onload = function () { resolve(); };
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function ensureLeaflet(root) {
    var d = root.dataset;
    loadCss(d.leafletCss);
    loadCss(d.clusterCss);
    loadCss(d.clusterCss2);
    var p = window.L ? Promise.resolve() : loadScript(d.leafletJs);

    return p.then(function () {
      if (window.L && !window.L.markerClusterGroup) {
        return loadScript(d.clusterJs);
      }
    }).then(function () {
      if (window.L) { window.L.Icon.Default.prototype.options.imagePath = d.leafletImages; }
    });
  }

  function pinIcon(kind) {
    return window.L.divIcon({
      className: '',
      html: '<span class="dir-pin-dot' + (kind === 'listing' ? ' dir-pin-dot--unclaimed' : '') + '"></span>',
      iconSize: [16, 16]
    });
  }

  function clusterIcon(kind) {
    return function (cluster) {
      var count = cluster.getChildCount();
      return window.L.divIcon({
        className: '',
        html: '<div class="dir-pin-cluster' + (kind === 'listing' ? ' dir-pin-cluster--unclaimed' : '') + '" style="width:' +
          (28 + Math.min(count, 40)) + 'px;height:' + (28 + Math.min(count, 40)) + 'px">' + count + '</div>',
        iconSize: [36, 36]
      });
    };
  }

  function esc(s) {
    var d = document.createElement('span');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function popupHtml(pin) {
    return '<div class="dir-popup">' +
      '<b class="dir-popup__title">' + esc(pin.name) + '</b>' +
      (pin.status === 'unclaimed' ? '<div class="mk-muted small">' + esc((window.MkI18n && MkI18n.dirUnclaimed) || 'Unclaimed') + '</div>' : '') +
      '<a class="dir-popup__link" href="' + esc(pin.url) + '">' + esc(pin.url) + '</a>' +
      '</div>';
  }

  function showError(root, msg) {
    var el = root.querySelector('.dir-maperror');
    if (!el) {
      el = document.createElement('div');
      el.className = 'dir-maperror';
      root.appendChild(el);
    }
    el.textContent = msg;
  }

  function clearError(root) {
    var el = root.querySelector('.dir-maperror');
    if (el) { el.remove(); }
  }

  function loadPins(root, map, groups) {
    var d = root.dataset;
    var bounds = map.getBounds();
    var bbox = [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()].join(',');
    var url = d.pinsUrl + '&bbox=' + encodeURIComponent(bbox);

    return fetch(url, { headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('bad status'); } return r.json(); })
      .then(function (json) {
        clearError(root);
        groups.claimed.clearLayers();
        groups.unclaimed.clearLayers();
        (json.pins || []).forEach(function (pin) {
          var group = pin.status === 'unclaimed' ? groups.unclaimed : groups.claimed;
          var marker = window.L.marker([pin.lat, pin.lng], { icon: pinIcon(pin.kind) });
          marker.bindPopup(popupHtml(pin));
          group.addLayer(marker);
        });
      })
      .catch(function () {
        showError(root, (window.MkI18n && MkI18n.dirMapLoadError) || 'Could not load the map — retry');
      });
  }


  /**
   * Ctrl/⌘ + wheel zooms; a plain wheel scrolls the page and says why.
   *
   * scrollWheelZoom:false alone leaves no way to zoom with a mouse, so the
   * modifier re-enables it for the duration of the gesture. The hint is only
   * shown for a PLAIN wheel — the user who already knows the shortcut never
   * sees it — and never on touch, where pinch already works and there is no
   * wheel to trap.
   */
  function zoomAffordance(root, map) {
    var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
    var hint = document.createElement('div');
    hint.className = 'dir-map-hint';
    hint.setAttribute('aria-hidden', 'true');
    hint.textContent = (window.MkI18n && (mac ? MkI18n.dirMapZoomHintMac : MkI18n.dirMapZoomHint))
      || (mac ? 'Hold ⌘ and scroll to zoom the map' : 'Hold Ctrl and scroll to zoom the map');
    root.appendChild(hint);

    var timer = null;

    /* Not passive: the modifier branch MUST preventDefault, or the browser's
       own page-zoom fires on ctrl+wheel. The plain branch never calls it, so
       page scrolling stays untouched.

       The zoom is applied here rather than by toggling map.scrollWheelZoom:
       Leaflet's handler only sees events that arrive while it is already
       enabled, so enabling it from inside the first ctrl+wheel swallowed that
       event and the first gesture did nothing. setZoomAround keeps the point
       under the cursor fixed, which is what wheel zoom is expected to do. */
    root.addEventListener('wheel', function (e) {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        root.classList.remove('is-hinting');
        clearTimeout(timer);
        var to = map.getZoom() + (e.deltaY < 0 ? 1 : -1);
        if (to < map.getMinZoom() || to > map.getMaxZoom()) { return; }
        try {
          map.setZoomAround(map.mouseEventToContainerPoint(e), to);
        } catch (err) {
          map.setZoom(to);
        }

        return;
      }

      root.classList.add('is-hinting');
      clearTimeout(timer);
      timer = setTimeout(function () { root.classList.remove('is-hinting'); }, 1400);
    }, { passive: false });

    root.addEventListener('pointerleave', function () {
      clearTimeout(timer);
      root.classList.remove('is-hinting');
    });
  }

  /**
   * Single-marker mode (biz page). One listing, no pins endpoint, no
   * clustering — but the SAME vendored Leaflet and OSM tiles as the explore
   * map, which is what let the biz page drop its third-party
   * openstreetmap.org iframe embed: that shipped every visitor's IP to a third
   * party on page load and could not be styled or themed.
   */
  function initSingle(root) {
    if (root.dataset.dirMapReady === '1') { return; }
    root.dataset.dirMapReady = '1';

    ensureLeaflet(root).then(function () {
      if (!window.L) { showError(root, (window.MkI18n && MkI18n.dirMapLoadError) || 'Could not load the map — retry'); return; }

      var d = root.dataset;
      var lat = parseFloat(d.lat);
      var lng = parseFloat(d.lng);
      if (isNaN(lat) || isNaN(lng)) { return; }

      var map = window.L.map(root, {
        zoomAnimation: !reduced,
        markerZoomAnimation: !reduced,
        fadeAnimation: !reduced,
        scrollWheelZoom: false      // a map inside a scrolling page must not steal the wheel
      }).setView([lat, lng], 15);

      window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(map);

      window.L.marker([lat, lng], { icon: pinIcon('listing') }).addTo(map);
    });
  }

  function init(root) {
    if (current && current.root === root) { return; } // already bound
    if (current) { try { current.map.remove(); } catch (e) {} current = null; }

    ensureLeaflet(root).then(function () {
      if (!window.L) { showError(root, (window.MkI18n && MkI18n.dirMapLoadError) || 'Could not load the map — retry'); return; }

      var d = root.dataset;
      var lat = parseFloat(d.cityLat) || 0;
      var lng = parseFloat(d.cityLng) || 0;

      /* The explore map is ~450px tall on a phone and ~560px on desktop, so a
         page scroll very often passes under the pointer. With Leaflet's default
         scrollWheelZoom the map eats that wheel and the page stops dead — the
         reader is trapped and has to fling around the map to get past it. The
         single-listing map above already opts out; this one never did.
         Ctrl/⌘ + wheel still zooms (and a trackpad pinch arrives as a
         ctrlKey wheel, so pinch-to-zoom keeps working untouched). */
      var map = window.L.map(root, {
        zoomAnimation: !reduced,
        markerZoomAnimation: !reduced,
        fadeAnimation: !reduced,
        scrollWheelZoom: false
      }).setView([lat, lng], 12);

      zoomAffordance(root, map);

      window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(map);

      var groups = {
        claimed: window.L.markerClusterGroup({ iconCreateFunction: clusterIcon('store'), animate: !reduced }),
        unclaimed: window.L.markerClusterGroup({ iconCreateFunction: clusterIcon('listing'), animate: !reduced })
      };
      map.addLayer(groups.claimed);
      map.addLayer(groups.unclaimed);

      var timer = null;
      map.on('moveend', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { loadPins(root, map, groups); }, 200);
      });

      loadPins(root, map, groups);
      current = { map: map, root: root };
    });
  }

  var api = {
    reinit: function () {
      var root = document.getElementById('dir-map');
      if (root && root.hasAttribute('data-dir-map')) {
        init(root);
      } else if (current) {
        try { current.map.remove(); } catch (e) {}
        current = null;
      }

      // Biz page: a single listing pin, independent of the explore map above.
      var one = document.getElementById('dir-biz-map');
      if (one && one.hasAttribute('data-dir-map-single')) { initSingle(one); }
    }
  };
  window.dirMap = api;

  document.addEventListener('DOMContentLoaded', function () { api.reinit(); });
})();
