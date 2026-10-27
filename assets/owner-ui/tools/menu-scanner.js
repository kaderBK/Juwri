// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * AI menu-scanner teaser (SEO run W6.2).
 * TEASER ONLY: nothing is uploaded, read or stored. Any interaction — click,
 * or dragging a photo onto the drop zone — just reveals the canned before→after
 * sample and the sign-up CTA. CSP-safe (no inline handlers, no library).
 * Owner-ui kit page (WI-16f-2): hooks are data-ms-* on
 * core/resources/views/marketing/tools/menu-scanner.blade.php; .is-revealed on
 * the root hides the drop zone (site-tools.css).
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-ms-root]');
  if (!root) return;

  var trigger = root.querySelector('[data-ms-trigger]');
  var sample = root.querySelector('[data-ms-sample]');
  if (!trigger || !sample) return;

  function reveal() {
    if (!sample.hasAttribute('hidden')) return;
    sample.removeAttribute('hidden');
    root.classList.add('is-revealed');
    trigger.setAttribute('aria-expanded', 'true');
    var focusTarget = sample.querySelector('[data-ms-focus]');
    if (focusTarget) focusTarget.focus();
  }

  trigger.addEventListener('click', reveal);

  // A dropped file is never read or sent anywhere — we only preventDefault so
  // the browser does not navigate to the image, then show the sample.
  ['dragenter', 'dragover'].forEach(function (evt) {
    trigger.addEventListener(evt, function (e) {
      e.preventDefault();
      trigger.classList.add('is-drag');
    });
  });
  ['dragleave', 'dragend'].forEach(function (evt) {
    trigger.addEventListener(evt, function () {
      trigger.classList.remove('is-drag');
    });
  });
  trigger.addEventListener('drop', function (e) {
    e.preventDefault();
    trigger.classList.remove('is-drag');
    reveal();
  });
})();
