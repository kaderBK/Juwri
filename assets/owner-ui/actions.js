// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Owner UI kit — declarative page actions (WI-16c).
 *
 * The dashboard CSP forbids inline handlers (script-src-attr 'none'), so a
 * control says what it does with data-ui-do and this file performs it. One
 * delegated listener per event type on document: survives Turbo body swaps.
 *
 *   data-ui-do="submit"            submit the control's own form
 *   data-ui-do="submit-target"     submit form #data-ui-target (or the form around that element)
 *   data-ui-do="submit-closest"    submit the first <form> inside the control
 *   data-ui-do="set-submit"        form.elements[data-ui-field] = data-ui-value, then submit
 *   data-ui-do="nav-value"         go to the control's value (when it has one)
 *   data-ui-do="nav-tpl"           go to data-ui-tpl with "__" replaced by the value
 *   data-ui-do="print"             window.print()
 *   data-ui-do="select"            select the control's text
 *   data-ui-do="close-dialog"      close the nearest <dialog>
 *   data-ui-do="copy"              copy #data-ui-copy-target's value/text; data-ui-copied
 *                                  swaps the label (or [data-ui-copy-label]) for 1.5 s
 *   data-ui-do="reveal-secret"     show data-ui-secret in place and remove the blur
 *   data-ui-do="toggle"            show/hide #data-ui-target (hidden attribute) and
 *                                  keep aria-expanded on the control in step
 *   data-ui-do="drawer-open"       open the .ui-drawer #data-ui-target (+ its
 *                                  [data-ui-drawer-for="id"] backdrop); Esc closes
 *   data-ui-do="drawer-close"      close the enclosing (or #data-ui-target) .ui-drawer
 *   data-ui-call="fnName"          window.fnName(data-ui-arg ?? element[, data-ui-arg2])
 *   data-ui-img-fallback="URL"     on load error: swap src to URL once
 *   data-ui-do="hide-on-error"     on load error: display:none
 *   data-ui-do="invisible-on-error" on load error: visibility:hidden
 *
 * Selects, checkboxes, radios and file inputs act on "change" only, so opening
 * a select never submits before a choice is made. Forms are submitted with
 * form.submit() (no validation gate), matching the old onchange="this.form.submit()".
 */
(function () {
  'use strict';

  if (window.__uiActions) {
    return;
  }
  window.__uiActions = true;

  var SELECTOR = '[data-ui-do],[data-ui-call]';

  function byId(id) {
    return id ? document.getElementById(id) : null;
  }

  function formFor(el) {
    return el.form || (el.closest ? el.closest('form') : null);
  }

  function send(form) {
    if (form) {
      form.submit();
    }
  }

  function call(el) {
    var name = el.getAttribute('data-ui-call');
    // Only bare identifiers of page-global functions our own views define.
    if (!name || !/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(name) || typeof window[name] !== 'function') {
      return;
    }
    var args = [el.hasAttribute('data-ui-arg') ? el.getAttribute('data-ui-arg') : el];
    if (el.hasAttribute('data-ui-arg2')) {
      args.push(el.getAttribute('data-ui-arg2'));
    }
    window[name].apply(window, args);
  }

  function copy(el) {
    var source = byId(el.getAttribute('data-ui-copy-target'));
    var text = source ? (source.value != null ? source.value : source.textContent) : '';
    if (text && navigator.clipboard) {
      navigator.clipboard.writeText(text);
    }
    var done = el.getAttribute('data-ui-copied');
    if (!done) {
      return;
    }
    var label = el.querySelector('[data-ui-copy-label]') || el;
    var before = label.textContent;
    label.textContent = done;
    window.setTimeout(function () { label.textContent = before; }, 1500);
  }

  function perform(el) {
    var what = el.getAttribute('data-ui-do');
    var form;
    switch (what) {
      case 'submit':
        send(formFor(el));
        return;
      case 'submit-target':
        form = byId(el.getAttribute('data-ui-target'));
        send(form && form.tagName === 'FORM' ? form : (form ? formFor(form) : null));
        return;
      case 'submit-closest':
        send(el.querySelector('form'));
        return;
      case 'set-submit':
        form = formFor(el);
        if (form) {
          var field = form.elements[el.getAttribute('data-ui-field')];
          if (field) {
            field.value = el.getAttribute('data-ui-value') || '';
          }
          send(form);
        }
        return;
      case 'nav-value':
        if (el.value) {
          window.location.href = el.value;
        }
        return;
      case 'nav-tpl':
        if (el.value) {
          window.location.href = (el.getAttribute('data-ui-tpl') || '').replace('__', el.value);
        }
        return;
      case 'print':
        window.print();
        return;
      case 'select':
        if (el.select) {
          el.select();
        }
        return;
      case 'close-dialog':
        var dialog = el.closest('dialog');
        if (dialog && dialog.close) {
          dialog.close();
        }
        return;
      case 'copy':
        copy(el);
        return;
      case 'toggle':
        var panel = byId(el.getAttribute('data-ui-target'));
        if (panel) {
          panel.hidden = !panel.hidden;
          el.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        }
        return;
      case 'drawer-open':
        drawer(byId(el.getAttribute('data-ui-target')), true);
        return;
      case 'drawer-close':
        drawer(byId(el.getAttribute('data-ui-target')) || el.closest('.ui-drawer'), false);
        return;
      case 'reveal-secret':
        if (el.hasAttribute('data-ui-secret')) {
          el.textContent = el.getAttribute('data-ui-secret');
        }
        el.style.filter = 'none';
        el.style.cursor = 'text';
        return;
      default:
        if (el.hasAttribute('data-ui-call')) {
          call(el);
        }
    }
  }

  function drawer(panel, open) {
    if (!panel) {
      return;
    }
    panel.classList.toggle('is-open', open);
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    var scrim = document.querySelector('[data-ui-drawer-for="' + panel.id + '"]');
    if (scrim) {
      scrim.hidden = !open;
    }
    if (open) {
      panel.focus();
    }
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') {
      return;
    }
    var open = document.querySelector('.ui-drawer.is-open[id]');
    if (open) {
      drawer(open, false);
    }
  });

  document.addEventListener('click', function (e) {
    var scrim = e.target instanceof Element ? e.target.closest('[data-ui-drawer-for]') : null;
    if (scrim) {
      drawer(document.getElementById(scrim.getAttribute('data-ui-drawer-for')), false);
    }
  });

  function actsOnChange(el) {
    if (el.tagName === 'SELECT') {
      return true;
    }
    if (el.tagName !== 'INPUT') {
      return false;
    }
    var type = (el.type || '').toLowerCase();
    return type === 'checkbox' || type === 'radio' || type === 'file';
  }

  document.addEventListener('click', function (e) {
    var el = e.target instanceof Element ? e.target.closest(SELECTOR) : null;
    if (el && !actsOnChange(el)) {
      perform(el);
    }
  });

  ['change', 'search'].forEach(function (type) {
    document.addEventListener(type, function (e) {
      var el = e.target instanceof Element ? e.target.closest(SELECTOR) : null;
      if (el) {
        perform(el);
      }
    });
  });

  // Load errors do not bubble: listen in the capture phase.
  document.addEventListener('error', function (e) {
    var el = e.target;
    if (!(el instanceof Element)) {
      return;
    }
    var fallback = el.getAttribute('data-ui-img-fallback');
    if (fallback) {
      if (el.getAttribute('src') !== fallback) {
        el.setAttribute('src', fallback);
      }
      return;
    }
    var what = el.getAttribute('data-ui-do');
    if (what === 'hide-on-error') {
      el.style.display = 'none';
    } else if (what === 'invisible-on-error') {
      el.style.visibility = 'hidden';
    }
  }, true);
})();
