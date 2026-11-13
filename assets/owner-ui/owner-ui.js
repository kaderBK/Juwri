// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Owner UI kit — behaviour. Delegated data-ui-* hooks only (no inline handlers:
 * the dashboard CSP sets script-src-attr 'none'). Loaded once in <head> with
 * data-turbo-eval="false"; listeners live on document, so they survive Turbo
 * visits without re-binding.
 *
 *   data-ui-open="sheet-id"   opens <dialog id="sheet-id" class="ui-sheet">
 *   data-ui-close             closes the enclosing dialog
 *   data-ui-persistent        on a dialog.ui-sheet: a backdrop click does NOT close it
 *                             (the page owns closing, e.g. while work is in flight)
 *   data-ui-autosubmit        on a form control: submit its form on change
 *   data-ui-busy              on a submit button: mark aria-busy while the form submits
 *   data-ui-toast="message"   shows a toast on click (data-ui-toast-tone="danger" for errors)
 *   data-ui-menu              opens the dashboard sidebar drawer (phone tab bar "More")
 *   ?order=<id> / ?booking=<id>  on page load: scroll to and flash every
 *                             [data-order-id] / [data-booking-id] with that id
 *                             (push-notification deep links; unknown ids are ignored)
 *   window.ownerUi.toast(message, { tone: 'success'|'danger', action: 'Undo', onAction: fn, duration: ms })
 */
(function () {
  'use strict';

  if (window.ownerUi) {
    return;
  }

  var SVG_NS = 'http://www.w3.org/2000/svg';

  function icon(pathD) {
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('width', '18');
    svg.setAttribute('height', '18');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2.6');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    var path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', pathD);
    svg.appendChild(path);
    return svg;
  }

  function toaster() {
    var region = document.querySelector('.ui-toaster');
    if (!region) {
      region = document.createElement('div');
      region.className = 'ui-toaster';
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      document.body.appendChild(region);
    }
    return region;
  }

  function dismiss(el) {
    if (!el || el.classList.contains('is-leaving')) {
      return;
    }
    el.classList.add('is-leaving');
    window.setTimeout(function () {
      if (el.parentNode) {
        el.parentNode.removeChild(el);
      }
    }, 160);
  }

  function toast(message, opts) {
    opts = opts || {};
    var tone = opts.tone === 'danger' ? 'danger' : 'success';
    var el = document.createElement('div');
    el.className = 'ui-toast';
    el.setAttribute('data-tone', tone);

    var ic = document.createElement('span');
    ic.className = 'ui-toast__icon';
    ic.appendChild(icon(tone === 'danger' ? 'M12 8v5M12 16v.1M3.5 12a8.5 8.5 0 1 0 17 0 8.5 8.5 0 0 0-17 0' : 'm5 12.5 4.5 4.5L19 7.5'));
    el.appendChild(ic);

    var msg = document.createElement('span');
    msg.className = 'ui-toast__msg';
    msg.textContent = String(message);
    el.appendChild(msg);

    if (opts.action && typeof opts.onAction === 'function') {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ui-toast__action';
      btn.textContent = String(opts.action);
      btn.addEventListener('click', function () {
        opts.onAction();
        dismiss(el);
      });
      el.appendChild(btn);
    }

    toaster().appendChild(el);
    window.setTimeout(function () { dismiss(el); }, opts.duration || 3200);
    return el;
  }

  document.addEventListener('click', function (e) {
    var target = e.target instanceof Element ? e.target : null;
    if (!target) {
      return;
    }

    var toastTrigger = target.closest('[data-ui-toast]');
    if (toastTrigger) {
      toast(toastTrigger.getAttribute('data-ui-toast'), { tone: toastTrigger.getAttribute('data-ui-toast-tone') || 'success' });
    }

    var menuTrigger = target.closest('[data-ui-menu]');
    if (menuTrigger) {
      // Reuse the top bar's drawer toggle so the sidebar keeps a single owner.
      // Deferred: the drawer's "close on outside click" listener also sees THIS
      // click (the tab bar is outside the drawer) and would close it again.
      var toggler = document.querySelector('.app-header .app-toggler, .app-toggler');
      if (toggler) {
        e.preventDefault();
        window.setTimeout(function () { toggler.click(); }, 0);
      }
      return;
    }

    var opener = target.closest('[data-ui-open]');
    if (opener) {
      var dlg = document.getElementById(opener.getAttribute('data-ui-open'));
      if (dlg && typeof dlg.showModal === 'function' && !dlg.open) {
        e.preventDefault();
        dlg.showModal();
      }
      return;
    }

    var closer = target.closest('[data-ui-close]');
    if (closer) {
      var parent = closer.closest('dialog');
      if (parent) {
        e.preventDefault();
        parent.close();
      }
      return;
    }

    // Click on the backdrop of a sheet (the dialog element itself) closes it.
    if (target.matches('dialog.ui-sheet[open]:not([data-ui-persistent])')) {
      var r = target.getBoundingClientRect();
      var inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
      if (!inside) {
        target.close();
      }
    }
  });

  document.addEventListener('change', function (e) {
    var el = e.target instanceof Element ? e.target.closest('[data-ui-autosubmit]') : null;
    if (el && el.form) {
      if (typeof el.form.requestSubmit === 'function') {
        el.form.requestSubmit();
      } else {
        el.form.submit();
      }
    }
  });

  document.addEventListener('submit', function (e) {
    var btn = e.submitter && e.submitter.matches('[data-ui-busy]') ? e.submitter : null;
    if (!btn && e.target instanceof HTMLFormElement) {
      btn = e.target.querySelector('button[type="submit"][data-ui-busy]');
    }
    if (btn) {
      btn.setAttribute('aria-busy', 'true');
    }
  });

  // Push deep links land on a list/board with ?order=<id> (or ?booking=<id> on the
  // stays calendar): bring that record into view and flash it. The food Orders
  // page runs its own focus (#order-rows), so it is skipped here.
  var FOCUS = [['order', 'data-order-id'], ['booking', 'data-booking-id']];
  var focusedUrl = null;

  function focusFromQuery() {
    if (focusedUrl === window.location.href) {
      return;
    }
    focusedUrl = window.location.href;
    var params = new URLSearchParams(window.location.search);
    FOCUS.forEach(function (pair) {
      var id = params.get(pair[0]);
      if (!id || !/^\d+$/.test(id)) {
        return;
      }
      var hits = Array.prototype.filter.call(
        document.querySelectorAll('[' + pair[1] + '="' + id + '"]'),
        function (el) { return !el.closest('#order-rows'); }
      );
      if (!hits.length) {
        return;
      }
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      hits[0].scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
      hits.forEach(function (el) { el.classList.add('ui-focus-flash'); });
      window.setTimeout(function () {
        hits.forEach(function (el) { el.classList.remove('ui-focus-flash'); });
      }, 3000);
    });
  }

  document.addEventListener('turbo:load', focusFromQuery);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', focusFromQuery);
  } else {
    focusFromQuery();
  }

  // Turbo snapshots the page before leaving: never cache an open sheet.
  document.addEventListener('turbo:before-cache', function () {
    document.querySelectorAll('dialog.ui-sheet[open]').forEach(function (d) { d.close(); });
    document.querySelectorAll('.ui-toast').forEach(function (t) { t.remove(); });
    document.querySelectorAll('.ui-btn[aria-busy="true"]').forEach(function (b) { b.removeAttribute('aria-busy'); });
    document.querySelectorAll('.ui-focus-flash').forEach(function (el) { el.classList.remove('ui-focus-flash'); });
  });

  window.ownerUi = { toast: toast };
})();
