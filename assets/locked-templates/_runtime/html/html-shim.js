// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*!
 * juwri locked-template shim for templates converted from the HTML edition
 * (the template's own main.js + vendor libs run as-is). Loaded BEFORE them.
 *
 * 1. CSP: main.js injects a few <style> tags at runtime; the page CSP only allows
 *    nonced <style>, and the full-page cache can't carry a nonce. Those tags are
 *    turned into constructable stylesheets (CSSOM — not restricted by CSP) instead
 *    of being inserted.
 * 2. Counters: the vendor bakes the target into data-number; the visible text is the
 *    user-editable slot, so the target is re-read from the CURRENT text before
 *    main.js's own DOMContentLoaded handler runs (listeners run in order). In the
 *    editor the counters are switched off — the animation rewrites the element.
 */
(function () {
  'use strict';

  var doc = document;
  var editor = doc.documentElement.hasAttribute('data-lt-editor');

  // ---- 1. <style> injection → adoptedStyleSheets
  var canAdopt = 'adoptedStyleSheets' in Document.prototype && typeof CSSStyleSheet === 'function' && 'replaceSync' in CSSStyleSheet.prototype;
  if (canAdopt) {
    var adopt = function (node) {
      if (!node || node.nodeName !== 'STYLE' || node.nonce || node.hasAttribute('nonce')) {
        return false;
      }
      try {
        var sheet = new CSSStyleSheet();
        sheet.replaceSync(node.textContent || '');
        doc.adoptedStyleSheets = doc.adoptedStyleSheets.concat([sheet]);
      } catch (e) {
        // Unparseable CSS: dropped, as the CSP would have done anyway.
      }
      return true;
    };
    var append = Node.prototype.appendChild;
    var insert = Node.prototype.insertBefore;
    Node.prototype.appendChild = function (node) {
      return (this === doc.head || this === doc.body) && adopt(node) ? node : append.call(this, node);
    };
    Node.prototype.insertBefore = function (node, ref) {
      return (this === doc.head || this === doc.body) && adopt(node) ? node : insert.call(this, node, ref);
    };
  }

  // ---- 2. Counters read the current (edited) text
  doc.addEventListener('DOMContentLoaded', function () {
    var counters = doc.querySelectorAll('[data-counter]');
    for (var i = 0; i < counters.length; i++) {
      var el = counters[i];
      if (editor) {
        el.removeAttribute('data-counter');
        continue;
      }
      var digits = (el.textContent || '').replace(/[^0-9]/g, '');
      if (!digits) {
        el.removeAttribute('data-counter');     // not a number any more: leave the text alone
        continue;
      }
      el.setAttribute('data-number', String(parseInt(digits, 10)));
      el.setAttribute('data-rooms', String(digits.length));
    }
  });
})();
