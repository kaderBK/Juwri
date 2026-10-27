// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*!
 * juwri locked-template runtime additions for HTML-edition templates, loaded
 * AFTER the template's own main.js: lead forms post to the platform endpoint
 * (same contract as kit/kit.js — the template's forms were rewritten to
 * form[data-lt-form] by the converter). Disabled in the editor.
 */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;
  var editor = root.hasAttribute('data-lt-editor');

  doc.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('form[data-lt-form]')) {
      return;
    }
    e.preventDefault();
    if (editor || form.__ltBusy) {
      return;
    }
    form.__ltBusy = true;
    var btn = form.querySelector('[type=submit]');
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.ok; })
      .catch(function () { return false; })
      .then(function (ok) {
        form.__ltBusy = false;
        var msg = ok ? root.getAttribute('data-lt-thanks') : root.getAttribute('data-lt-failed');
        if (btn && msg) {
          btn.textContent = msg;
          btn.disabled = !!ok;
        }
        if (ok) {
          form.reset();
        }
      });
  });
})();
