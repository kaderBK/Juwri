// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Free QR-menu generator (SEO run W6.1).
 * CSP-safe (no inline handlers). The QR itself is rendered server-side by the
 * ONE QrRenderService (reuses bacon/bacon-qr-code) — this only builds the
 * request URL for the <img> preview and the download links, debounced so the
 * rate-limited endpoint is not hammered on every keystroke.
 * Owner-ui kit page (WI-16f-2): hooks are the data-qr-* attributes on
 * core/resources/views/marketing/tools/qr-menu-generator.blade.php; the chosen
 * swatch carries aria-pressed="true" (styled in site-tools.css). No library.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-qr-root]');
  if (!root) return;

  var endpoint = root.getAttribute('data-qr-endpoint');
  var sample = root.getAttribute('data-qr-sample') || '';
  if (!endpoint) return;

  var urlInput = root.querySelector('[data-qr-url]');
  var preview = root.querySelector('[data-qr-preview]');
  var dlPng = root.querySelector('[data-qr-dl-png]');
  var dlSvg = root.querySelector('[data-qr-dl-svg]');
  var swatches = Array.prototype.slice.call(root.querySelectorAll('[data-qr-color]'));

  var fg = (swatches[0] && swatches[0].getAttribute('data-qr-color')) || '#111827';

  function currentUrl() {
    var v = (urlInput && urlInput.value ? urlInput.value : '').trim();
    return v || sample;
  }

  function build(format, download) {
    var params = new URLSearchParams();
    params.set('url', currentUrl());
    params.set('fg', fg);
    if (format) params.set('format', format);
    if (download) params.set('download', '1');
    return endpoint + '?' + params.toString();
  }

  function refresh() {
    var url = currentUrl();
    var has = url !== '';
    if (preview) {
      if (has) {
        preview.src = build('svg', false);
      } else {
        preview.removeAttribute('src');
      }
    }
    if (dlPng) {
      dlPng.href = has ? build('png', true) : '#';
      dlPng.setAttribute('aria-disabled', has ? 'false' : 'true');
    }
    if (dlSvg) {
      dlSvg.href = has ? build('svg', true) : '#';
      dlSvg.setAttribute('aria-disabled', has ? 'false' : 'true');
    }
  }

  var timer = null;
  function debounced() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(refresh, 450);
  }

  if (urlInput) urlInput.addEventListener('input', debounced);

  swatches.forEach(function (s) {
    s.addEventListener('click', function () {
      fg = s.getAttribute('data-qr-color') || fg;
      swatches.forEach(function (o) {
        var on = o === s;
        o.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      refresh();
    });
  });

  // A disabled download link must not navigate to "#".
  [dlPng, dlSvg].forEach(function (a) {
    if (!a) return;
    a.addEventListener('click', function (e) {
      if (a.getAttribute('aria-disabled') === 'true') e.preventDefault();
    });
  });

  refresh();
})();
