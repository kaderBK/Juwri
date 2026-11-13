// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* DIRECTORY T2-1/T2-3: store reviews — progressive enhancement only.
 * The write form (mk-store.js's generic [data-mk-stars] + form[data-mk-ajax]
 * handlers already cover star-picking and busy-guarded AJAX submit for it —
 * see mk-store.js's `store` module) needs nothing here. The one interaction
 * that is JS-only by nature, same as the existing favorite/follow buttons,
 * is the helpful vote: no meaningful no-JS fallback exists for an
 * increment-in-place counter, so a visitor without JS simply doesn't see it
 * respond (the button itself still renders, SSR-first).
 */
(function () {
  'use strict';

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  }

  function initVotes(scope) {
    var root = scope || document;
    var buttons = root.querySelectorAll('[data-dir-vote]');
    for (var i = 0; i < buttons.length; i++) {
      (function (btn) {
        btn.addEventListener('click', function () {
          if (btn.disabled) { return; }
          var url = btn.getAttribute('data-url');
          if (!url) { return; }
          btn.disabled = true;
          fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin'
          }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j || !j.ok) { btn.disabled = false; return; }
            btn.classList.add('is-on');
            btn.setAttribute('aria-pressed', 'true');
            var countEl = btn.querySelector('[data-dir-vote-count]');
            if (countEl) {
              var label = countEl.getAttribute('data-label') || countEl.textContent.replace(/\(\d+\)/, '').trim();
              countEl.textContent = label + ' (' + j.helpful_count + ')';
            }
          }).catch(function () {
            btn.disabled = false;
          });
        });
      })(buttons[i]);
    }
  }

  var mod = {
    init: function () { initVotes(document); }
  };

  if (window.Mk && window.Mk.register) {
    window.Mk.register('dirReviews', mod);
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      if (window.Mk && window.Mk.register) { window.Mk.register('dirReviews', mod); }
    });
  }
})();
