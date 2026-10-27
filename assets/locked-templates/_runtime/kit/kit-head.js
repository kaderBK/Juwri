// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*!
 * Loaded synchronously in <head> (external file, no inline script → CSP/cache safe).
 * Marks the document as JS-capable so reveal targets may start hidden; without this
 * class (no JS, reduced motion, or the editor preview) they are always visible.
 */
(function () {
  var r = document.documentElement;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduced && !r.hasAttribute('data-lt-editor')) {
    r.classList.add('lt-js');
  }
})();
