// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * juwri owner-ui — marketing site behaviour (WI-16f). Delegated, no globals.
 *
 *   [data-hero-rotator][data-words] hero: cycles the highlighted noun every 3 s
 *                                   (kept still for reduced motion)
 *   .mk-pricing [data-cycle]        pricing: switches every price on the page
 *                                   between monthly / yearly / lifetime, reading
 *                                   data-{cycle}-price and data-{cycle}-period
 *   .mk-pricing .pc-group           compare table: click (or Enter/Space) folds
 *                                   the rows of that feature group
 */
(function () {
  'use strict';

  if (window.__mkSite) { return; }
  window.__mkSite = true;

  var doc = document;
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function startRotator(el) {
    if (el.dataset.rotating) { return; } // never two intervals on one headline
    var words;
    try { words = JSON.parse(el.getAttribute('data-words') || '[]'); } catch (e) { return; }
    if (!Array.isArray(words) || words.length < 2 || calm) { return; }
    el.dataset.rotating = '1';
    var word = el.querySelector('.mk-rotator__word') || el;
    var at = 0;
    setInterval(function () {
      at = (at + 1) % words.length;
      el.classList.add('is-swapping');
      setTimeout(function () {
        word.textContent = words[at];
        el.classList.remove('is-swapping');
      }, 240);
    }, 3000);
  }

  function setCycle(cycle) {
    doc.querySelectorAll('[data-cycle]').forEach(function (b) {
      var on = b.getAttribute('data-cycle') === cycle;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    doc.querySelectorAll('[data-' + cycle + '-price]').forEach(function (el) {
      el.textContent = el.getAttribute('data-' + cycle + '-price');
    });
    doc.querySelectorAll('[data-' + cycle + '-period]').forEach(function (el) {
      el.textContent = el.getAttribute('data-' + cycle + '-period');
    });
  }

  function toggleGroup(row) {
    var btn = row.querySelector('.pc-toggle');
    var open = btn.getAttribute('aria-expanded') !== 'false';
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    var key = row.getAttribute('data-pc-group');
    doc.querySelectorAll('[data-pc-rows="' + key + '"]').forEach(function (r) { r.hidden = open; });
  }

  doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof Element)) { return; }
    // Scoped to the kit pricing block: an old landing page body still runs
    // its own toggle script on its own markup.
    var cycle = t.closest('.mk-pricing [data-cycle]');
    if (cycle) { setCycle(cycle.getAttribute('data-cycle')); return; }
    var group = t.closest('.mk-pricing .pc-group');
    if (group) { toggleGroup(group); }
  });

  function init() {
    doc.querySelectorAll('[data-hero-rotator]').forEach(startRotator);
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
