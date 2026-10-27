// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * services.js — shared owner-dashboard behaviour (the bought theme's script,
 * rewritten and moved to assets/owner-ui in WI-16h). Keeps the table sort/select +
 * [data-ajax] form enhancement + Alpine toast/ordersPrefs stores (page callers
 * depend on the names), and the dashApp() shell component (theme toggle on the
 * dash.theme key). P1-01 renamed the globals to dash*; window.Lamar stays as a
 * deprecated alias (rename-map).
 */
(() => {
  window.Dash = window.Dash || {};
  window.Lamar = window.Dash; // brand-alias: deprecated pre-P1-01 global name (rename-map)

  // Global toast helper — one entry point for JS callers (and the shared
  // <x-busy-button>/<x-upload-progress>/<x-job-progress> components). Safe to
  // call before Alpine boots: messages are queued and flushed on alpine:init.
  // Usage: window.dashToast('success', 'Saved') | dashToast('danger', 'Failed').
  window.dashToast = window.dashToast || function (type, message, opts) {
    opts = opts || {};
    try {
      if (window.Alpine && Alpine.store && Alpine.store('toast')) {
        Alpine.store('toast').add(Object.assign({ type: type || 'info', message: message || '' }, opts));
        return true;
      }
    } catch (e) { /* store not ready */ }
    (window.__dashToastQueue = window.__dashToastQueue || []).push([type, message, opts]);
    return false;
  };

  window.Dash.table = {
    init(root = document) {
      root.querySelectorAll('[data-linen-table], [data-dash-table]').forEach((table) => {
        if (table.dataset.tableReady === 'true') return;
        table.dataset.tableReady = 'true';
        this.bindSort(table);
        this.bindSelection(table);
      });
    },
    bindSort(table) {
      table.querySelectorAll('th[data-sortable="true"]').forEach((header) => {
        const sort = () => this.sortBy(table, header);
        header.addEventListener('click', sort);
        header.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); }
        });
      });
    },
    sortBy(table, header) {
      const key = header.dataset.key;
      const tbody = table.querySelector('tbody');
      const rows = Array.from(tbody.querySelectorAll('tr[data-table-row]'));
      const dir = header.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
      table.querySelectorAll('th[aria-sort]').forEach((i) => i.setAttribute('aria-sort', 'none'));
      header.setAttribute('aria-sort', dir);
      rows.sort((l, r) => {
        const lt = l.querySelector(`td[data-key="${key}"]`)?.textContent.trim() || '';
        const rt = r.querySelector(`td[data-key="${key}"]`)?.textContent.trim() || '';
        const ln = Number(lt.replace(/[^0-9.-]/g, ''));
        const rn = Number(rt.replace(/[^0-9.-]/g, ''));
        const numeric = Number.isFinite(ln) && Number.isFinite(rn) && lt !== '' && rt !== '';
        const res = numeric ? ln - rn : lt.localeCompare(rt, undefined, { numeric: true, sensitivity: 'base' });
        return dir === 'ascending' ? res : -res;
      });
      rows.forEach((row) => tbody.appendChild(row));
    },
    bindSelection(table) {
      const all = table.querySelector('[data-table-select-all]');
      const rows = Array.from(table.querySelectorAll('[data-table-select-row]'));
      const bulk = table.querySelector('[data-table-bulk]');
      const count = table.querySelector('[data-table-selected-count]');
      if (!rows.length) return;
      const sync = () => {
        const sel = rows.filter((c) => c.checked);
        rows.forEach((c) => c.closest('[data-table-row]')?.setAttribute('data-selected', c.checked ? 'true' : 'false'));
        if (all) { all.checked = sel.length === rows.length; all.indeterminate = sel.length > 0 && sel.length < rows.length; }
        if (bulk) bulk.hidden = sel.length === 0;
        if (count) count.textContent = String(sel.length);
      };
      all?.addEventListener('change', () => { rows.forEach((c) => { c.checked = all.checked; }); sync(); });
      rows.forEach((c) => c.addEventListener('change', sync));
      sync();
    },
  };

  // Progressive [data-ajax] form submit → toast + inline 422 errors.
  window.Dash.ajaxForms = {
    init() {
      if (this._bound) return;
      this._bound = true;
      document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-ajax]')) return;
        e.preventDefault();
        this.submit(form);
      });
    },
    toast(type, message) {
      try { if (window.Alpine && Alpine.store('toast')) Alpine.store('toast').add({ type, message }); } catch (e) {}
    },
    submit(form) {
      const t = window.Dash.t || {};
      const action = form.getAttribute('action') || window.location.href;
      const method = (form.getAttribute('method') || 'post').toUpperCase();
      const btn = form.querySelector('[type="submit"]');
      if (btn) { btn.dataset.wasDisabled = btn.disabled ? '1' : ''; btn.disabled = true; btn.classList.add('disabled'); }
      form.querySelectorAll('[data-ajax-error]').forEach((el) => el.remove());
      form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
      fetch(action, {
        method, body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        credentials: 'same-origin', redirect: 'manual',
      })
        .then(async (res) => {
          if (res.type === 'opaqueredirect' || res.ok) {
            this.toast('success', form.dataset.ajaxSuccess || t.saved || 'Saved');
            form.dispatchEvent(new CustomEvent('ajax:success', { bubbles: true }));
            if (form.hasAttribute('data-ajax-reset')) form.reset();
          } else if (res.status === 422) {
            let data = {}; try { data = await res.json(); } catch (e) {}
            const errors = data.errors || {};
            const first = Object.values(errors)[0];
            this.toast('danger', (first && first[0]) || data.message || t.error || 'Error');
            Object.keys(errors).forEach((name) => {
              const field = form.querySelector('[name="' + name + '"]');
              if (!field) return;
              field.classList.add('is-invalid');
              const small = document.createElement('div');
              small.className = 'invalid-feedback d-block';
              small.setAttribute('data-ajax-error', '');
              small.textContent = errors[name][0];
              (field.closest('.mb-3, .form-group, .col') || field.parentNode).appendChild(small);
            });
          } else { this.toast('danger', t.error || 'Error'); }
        })
        .catch(() => this.toast('danger', t.error || 'Error'))
        .finally(() => {
          if (btn) { btn.disabled = btn.dataset.wasDisabled === '1'; btn.classList.remove('disabled'); delete btn.dataset.wasDisabled; }
        });
    },
  };

  // Alpine shell component + stores.
  document.addEventListener('alpine:init', () => {
    Alpine.data('dashApp', () => ({
      dark: document.documentElement.getAttribute('data-bs-theme') === 'dark',
      // nexlink-main.js re-applies data-bs-theme on jQuery ready, which can land
      // after Alpine inits. Without this re-read `dark` went stale and the first
      // click only re-synced state instead of flipping the theme.
      init() {
        const sync = () => {
          this.dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        };
        window.addEventListener('load', sync);
        new MutationObserver(sync).observe(document.documentElement, {
          attributes: true, attributeFilter: ['data-bs-theme'],
        });
      },
      toggleTheme() {
        // AL-31 tri-state: light → dark → system → … . "system" clears the stored
        // choice so the head pre-paint + its matchMedia listener follow the OS (and
        // nexlink-main.js's cookie-absent path resolves the same way on next load).
        let stored = null;
        try { stored = localStorage.getItem('dash.theme') || localStorage.getItem('lamar-theme'); } catch (e) {} // brand-alias: read the pre-P1-01 key
        const mode = (stored === 'light' || stored === 'dark') ? stored : 'system';
        const next = { light: 'dark', dark: 'system', system: 'light' }[mode];

        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const resolved = next === 'system' ? (mq.matches ? 'dark' : 'light') : next;
        const el = document.documentElement;
        el.setAttribute('data-bs-theme', resolved);
        el.setAttribute('data-theme', resolved);
        el.style.backgroundColor = resolved === 'dark' ? '#17181c' : '#fff';
        this.dark = resolved === 'dark';

        if (next === 'system') {
          document.cookie = 'theme=; path=/; max-age=0';           // clear → system
          try { localStorage.removeItem('dash.theme'); localStorage.removeItem('lamar-theme'); } catch (e) {} // brand-alias: drop the pre-P1-01 key
        } else {
          document.cookie = 'theme=' + next + '; path=/; max-age=31536000';
          try { localStorage.setItem('dash.theme', next); localStorage.removeItem('lamar-theme'); } catch (e) {} // brand-alias: migrate the pre-P1-01 key
        }
        window.dispatchEvent(new CustomEvent('dash:theme', { detail: next }));
      },
    }));

    Alpine.store('toast', {
      items: [],
      timers: {},
      // N2: does the viewer want motion suppressed? Checked per-dismiss so a
      // reduced-motion user skips the leave choreography entirely.
      _reduced() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
      },
      add(toast) {
        const id = toast.id || Date.now() + Math.random();
        // N2: cap the stack at 4 — a burst of flashes must not blanket the screen;
        // the oldest collapses to make room.
        while (this.items.length >= 4) { this.remove(this.items[0].id); }
        /* WI-B2 (upsell-visibility): a toast may carry ONE action link — "Support
           Desk is now active. Set up your inbox →". Additive and optional, so the
           85+ existing callers that pass only type+message are unchanged.
           spotlightKey, when present, lets the link report its own click through
           the same delegated handler every other spotlight surface uses. */
        const duration = toast.duration || 4000;
        this.items.push({
          id,
          type: toast.type || 'info',
          message: toast.message || '',
          href: toast.href || '',
          actionLabel: toast.actionLabel || '',
          spotlightKey: toast.spotlightKey || '',
          leaving: false,
          _ends: Date.now() + duration,
          _left: duration,
        });
        this._arm(id, duration);
      },
      _arm(id, ms) {
        window.clearTimeout(this.timers[id]);
        const item = this.items.find((t) => t.id === id);
        if (item) { item._ends = Date.now() + ms; }
        this.timers[id] = window.setTimeout(() => this.dismiss(id), ms);
      },
      // N2: pause-on-hover — SUSPEND the auto-dismiss timer (not just the visual),
      // banking the remaining time so a toast never vanishes mid-read.
      pause(id) {
        const item = this.items.find((t) => t.id === id);
        if (!item) { return; }
        window.clearTimeout(this.timers[id]);
        item._left = Math.max(0, item._ends - Date.now());
      },
      resume(id) {
        const item = this.items.find((t) => t.id === id);
        if (!item) { return; }
        this._arm(id, item._left || 1200);
      },
      // N2: play the slide/fade-out, THEN remove. Reduced-motion → remove at once.
      dismiss(id) {
        const item = this.items.find((t) => t.id === id);
        if (!item) { return; }
        if (this._reduced()) { this.remove(id); return; }
        item.leaving = true;
        window.setTimeout(() => this.remove(id), 220);
      },
      remove(id) {
        window.clearTimeout(this.timers[id]);
        delete this.timers[id];
        this.items = this.items.filter((t) => t.id !== id);
      },
    });

    // Flush any toasts queued via window.dashToast() before the store existed.
    if (window.__dashToastQueue && window.__dashToastQueue.length) {
      window.__dashToastQueue.splice(0).forEach((args) => window.dashToast.apply(null, args));
    }

    Alpine.store('ordersPrefs', {
      sound: (localStorage.getItem('dash.orders.sound') || localStorage.getItem('lamar.orders.sound')) !== 'off', // brand-alias: read the pre-P1-01 key
      toggleSound() { this.sound = !this.sound; localStorage.setItem('dash.orders.sound', this.sound ? 'on' : 'off'); localStorage.removeItem('lamar.orders.sound'); }, // brand-alias: migrate the pre-P1-01 key
    });
  });

  const boot = () => {
    window.Dash.table.init();
    window.Dash.ajaxForms.init();
    if (window.Waves) { window.Waves.init(); window.Waves.attach('.btn, .waves-effect'); }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

// Busy state for direct-download links (CSV/exports): disable + label swap,
// auto-reset — the browser downloads in-page so there is no completion event.
(function () {
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-busy-download]');
    if (!a) return;
    if (a.dataset.busyActive === '1') { e.preventDefault(); return; }
    a.dataset.busyActive = '1';
    a.setAttribute('aria-busy', 'true');
    a.style.opacity = '0.6';
    a.style.pointerEvents = 'none';
    var label = a.querySelector('.busy-dl-label') || a;
    var original = label.textContent;
    label.textContent = a.dataset.busyLabel || 'Preparing…';
    window.setTimeout(function () {
      a.dataset.busyActive = '';
      a.removeAttribute('aria-busy');
      a.style.opacity = '';
      a.style.pointerEvents = '';
      label.textContent = original;
    }, 5000);
  });
})();

/*
 * Inline-action engine (CSP dashboard-enforce prep).
 * DashboardPolicy sets script-src-attr 'none', so inline event-handler
 * attributes (onclick/onchange/onerror…) are forbidden under enforcement.
 * Every such handler in the owner blades is rewritten to a data-lm-* hook and
 * handled here by ONE document-level delegated listener per event type — which
 * also survives Turbo body swaps (document persists), so no per-page rebind.
 *
 * Vocabulary (see the blade sweep):
 *   change/click/search:
 *     data-lm="submit"                 → submit the control's own form
 *     data-lm="submit-target" + data-lm-target="ID"   → submit form #ID (or the
 *                                        form owning element #ID)
 *     data-lm="submit-closest"         → submit the first <form> inside the control
 *     data-lm="set-submit" + data-lm-field + data-lm-value
 *                                      → set form.elements[field]=value, then submit
 *     data-lm="nav-value"              → if control has a value, go to it
 *     data-lm="nav-tpl" + data-lm-tpl="…__…"  → go to tpl with __ replaced by value
 *     data-lm="print"                  → window.print()
 *     data-lm="select"                 → select the control's text
 *     data-lm="close-dialog"           → close the nearest <dialog>
 *     data-lm="logout"                 → submit #dash-logout-form
 *     data-lm="copy" + data-lm-copy-target="ID" [+ data-lm-copied]
 *                                      → copy #ID's value to clipboard, brief label swap
 *     data-lm="reveal-secret"          → show data-lm-secret in place, un-blur
 *     data-lm-call="fnName" [+ data-lm-arg]
 *                                      → window.fnName(arg ?? element) — the arg is
 *                                        passed when present, else the element itself
 *   error (capture):
 *     data-lm-img-fallback="URL"       → set src to URL once
 *     data-lm="hide-on-error"          → display:none
 *     data-lm="invisible-on-error"     → visibility:hidden
 *
 * data-lm-call only invokes a page-global function whose name is a bare
 * identifier (defence-in-depth; the names are static, authored in our blades).
 */
(function () {
  function formOf(el) {
    return el.form || (el.closest && el.closest('form')) || null;
  }
  function byId(id) { return id ? document.getElementById(id) : null; }
  // Raw .submit() on purpose: it matches the inline onchange="this.form.submit()"
  // these controls were converted from — no constraint-validation gate (requestSubmit
  // would silently refuse to submit a form with an empty `required` field) and no
  // dependence on a submit button existing.
  function doSubmit(f) { if (f) { f.submit(); } }

  function invokeCall(el) {
    var name = el.getAttribute('data-lm-call');
    if (!name || !/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(name)) { return; }
    var fn = window[name];
    if (typeof fn !== 'function') { return; }
    // First arg: the literal data-lm-arg when present (e.g. a record id), else the
    // element itself (fn(this) callers). data-lm-arg2 appends a fixed second arg
    // (e.g. linenPreviewAvatar(el, 'avatar-preview')).
    var args = [el.hasAttribute('data-lm-arg') ? el.getAttribute('data-lm-arg') : el];
    if (el.hasAttribute('data-lm-arg2')) { args.push(el.getAttribute('data-lm-arg2')); }
    fn.apply(window, args);
  }

  function runAction(el, e) {
    var action = el.getAttribute('data-lm');
    switch (action) {
      case 'submit': { doSubmit(formOf(el)); break; }
      case 'submit-target': {
        var t = byId(el.getAttribute('data-lm-target'));
        var f2 = t && t.tagName === 'FORM' ? t : (t ? formOf(t) : null);
        doSubmit(f2);
        break;
      }
      case 'submit-closest': { doSubmit(el.querySelector('form')); break; }
      case 'set-submit': {
        var f4 = formOf(el);
        if (f4) {
          var field = f4.elements[el.getAttribute('data-lm-field')];
          if (field) field.value = el.getAttribute('data-lm-value') || '';
          doSubmit(f4);
        }
        break;
      }
      case 'nav-value': { if (el.value) window.location.href = el.value; break; }
      case 'nav-tpl': {
        var tpl = el.getAttribute('data-lm-tpl') || '';
        if (el.value) window.location.href = tpl.replace('__', el.value);
        break;
      }
      case 'print': window.print(); break;
      case 'select': if (el.select) el.select(); break;
      case 'close-dialog': { var d = el.closest('dialog'); if (d && d.close) d.close(); break; }
      case 'logout': {
        if (e) e.preventDefault();
        var lf = byId('dash-logout-form'); if (lf) lf.submit();
        break;
      }
      case 'copy': {
        var src = byId(el.getAttribute('data-lm-copy-target'));
        var val = src ? (src.value != null ? src.value : src.textContent) : '';
        if (navigator.clipboard && val) { navigator.clipboard.writeText(val); }
        var done = el.getAttribute('data-lm-copied');
        if (done) {
          var lbl = el.querySelector('[data-lm-copy-label]') || el;
          var was = lbl.textContent;
          lbl.textContent = done;
          window.setTimeout(function () { lbl.textContent = was; }, 1500);
        }
        break;
      }
      case 'reveal-secret': {
        if (el.dataset.lmSecret != null) { el.textContent = el.dataset.lmSecret; }
        el.style.filter = 'none';
        el.style.cursor = 'text';
        break;
      }
      default:
        if (el.hasAttribute('data-lm-call')) { invokeCall(el); }
    }
  }

  // Value controls fire their action from 'change' (they were converted from
  // onchange=). A CLICK on them only opens/toggles the control and must NOT run the
  // action — otherwise a <select data-lm="submit"> submits the instant you click to
  // open it (before choosing), and a checkbox/file would double-fire (click + change).
  function isChangeDriven(el) {
    if (el.tagName === 'SELECT') { return true; }
    if (el.tagName === 'INPUT') {
      var t = (el.type || '').toLowerCase();
      return t === 'checkbox' || t === 'radio' || t === 'file';
    }
    return false;
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-lm],[data-lm-call]') : null;
    if (el && !isChangeDriven(el)) { runAction(el, e); }
  });
  ['change', 'search'].forEach(function (type) {
    document.addEventListener(type, function (e) {
      var el = e.target.closest ? e.target.closest('[data-lm],[data-lm-call]') : null;
      if (el) { runAction(el, e); }
    });
  });

  // Image / element load-error fallbacks (capture phase — error does not bubble).
  document.addEventListener('error', function (e) {
    var el = e.target;
    if (!el || !el.getAttribute) { return; }
    if (el.hasAttribute('data-lm-img-fallback')) {
      var url = el.getAttribute('data-lm-img-fallback');
      if (el.src !== url) { el.src = url; }
      return;
    }
    var act = el.getAttribute('data-lm');
    if (act === 'hide-on-error') { el.style.display = 'none'; }
    else if (act === 'invisible-on-error') { el.style.visibility = 'hidden'; }
  }, true);
})();
