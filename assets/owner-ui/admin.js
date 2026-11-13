// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Owner UI kit — superadmin behaviour (WI-10). Loaded by admin.layout for the
 * screens rebuilt on the kit. Plain DOM, delegated listeners, no inline handlers.
 *
 *   [data-ui-dt]                  server-side list (<x-ui.data-table>): reads the
 *                                 JSON answer of App\Support\Admin\DataTable
 *                                 ({draw, recordsTotal, recordsFiltered, data[]},
 *                                 each row = cell HTML + DT_RowId). Sends draw,
 *                                 start, length, order[0][column|dir], search[value]
 *                                 and every [data-ui-dt-filter] field in the bar.
 *                                 data-reorder="url"  drag rows by .app-reorder-icon,
 *                                                     posts position[] (row ids in order)
 *                                 data-delete="url"   bulk delete of ticked .quick-check
 *                                                     rows, posts ids[]
 *   [data-toggle="slidePanel"][data-url] / [data-ui-panel="url"]
 *                                 loads the create/edit form into the side panel
 *   [data-ui-panel-save]          submits the panel form (fetch, JSON {success, message});
 *                                 success closes the panel and reloads the lists
 *   form[data-ui-ajax]            submits with fetch (incl. the pressed submit button's
 *                                 name/value) and toasts the JSON answer
 *   [data-ui-copy-text="…"]       copies the text and toasts "Copied"
 *   [data-ui-bulk="url"]          server-rendered lists: posts ids[] of the ticked .quick-check
 *                                 boxes inside the closest [data-ui-bulk-scope], then reloads;
 *                                 [data-ui-check-all] ticks every .quick-check in that scope;
 *                                 data-ui-bulk-id="id" posts just that one id (row delete);
 *                                 data-ui-bulk-ids="1,2,…" posts that list (selection across pages)
 *   [data-ui-tabs]                tab list (role=tab, aria-controls) + tabpanels; the open
 *                                 tab follows location.hash (#<panel id>)
 *   [data-ui-form-post="url"]     posts the enclosing form's fields to another url (e.g.
 *                                 "Test connection") and toasts the JSON answer
 *   tbody[data-ui-reorder="url"]  server-rendered rows drag by .ui-reorder, posts position[]
 *   [data-ui-post="url"]          posts {id} (data-ui-id) after data-ui-confirm, reloads lists
 *   select[multiple][data-ui-multi]  searchable chip multi-select (still posts name[])
 *   [data-ui-translate]           per-language field (<x-ui.translated-field>): the picker
 *                                 shows one language; the default language fills the base field
 *   input[type=file][data-ui-preview="img-id"]  previews the picked image
 *   textarea[data-ui-rich]        small rich-text editor (HTML kept in the textarea)
 *   form[data-ui-confirm]         asks before a plain (non-ajax) submit
 *   input[data-ui-table-search="table-id"]  filters a server-rendered table's rows as you type
 *   input[data-ui-code]           filled with a random 12-character code when empty;
 *                                 [data-ui-code-for="input-id"] makes a new one
 *   "/"                           focuses the top-bar user search
 *
 *   window.adminUi = { toast, confirm, reload, openPanel, closePanel, enhance }
 *   window.adminCharts = { bars(el, data), donut(el, slices) }
 */
(function () {
  'use strict';

  if (window.adminUi) {
    return;
  }

  var doc = document;
  var I18N = window.ADMIN_I18N || {};
  var tables = [];

  function t(key, fallback, params) {
    var s = I18N[key] || fallback;
    return !params ? s : s.replace(/:([A-Za-z_]+)/g, function (m, k) { return Object.prototype.hasOwnProperty.call(params, k) ? String(params[k]) : m; });
  }

  function csrf() {
    var m = doc.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  /**
   * Server messages may join several lines with <br>; show them as plain text.
   * Parsed in an inert document: markup in a message (exception text, names)
   * never loads or runs anything, unlike innerHTML on an element of this page.
   */
  function plain(message) {
    var html = String(message == null ? '' : message).replace(/<br\s*\/?>/gi, '\n');
    var parsed = new DOMParser().parseFromString(html, 'text/html');
    return (parsed.body ? parsed.body.textContent : '').trim();
  }

  function toast(message, tone) {
    var text = plain(message);
    if (!text) { return; }
    if (window.ownerUi && window.ownerUi.toast) {
      window.ownerUi.toast(text, { tone: tone === 'danger' || tone === 'error' ? 'danger' : 'success', duration: tone === 'danger' || tone === 'error' ? 6000 : 3200 });
    }
  }

  // "Log in as user" asks why; the reason is kept in the impersonation audit row.
  doc.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-impersonate')) { return; }
    var field = form.querySelector('input[name="reason"]');
    var reason = window.prompt(form.getAttribute('data-impersonate') || '', '');
    if (!field || !reason || reason.trim().length < 5) { e.preventDefault(); return; }
    field.value = reason.trim().slice(0, 200);
  });

  // Older inline page scripts call app_alert(message, 'error').
  window.app_alert = window.app_alert || function (message, type) { toast(message, type === 'error' ? 'danger' : 'success'); };

  function failure(res) {
    if (res && res.status === 419) { return t('expired', 'Your session expired. Please reload the page and try again.'); }
    return t('error', 'Something went wrong. Please try again.');
  }

  function request(url, opts) {
    opts = opts || {};
    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf(), Accept: opts.accept || 'application/json' };
    return fetch(url, { method: opts.method || 'GET', body: opts.body, headers: headers, credentials: 'same-origin' });
  }

  /** POST and read a {success, message} answer; resolves with the parsed JSON. */
  function postJson(url, body) {
    return request(url, { method: 'POST', body: body }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (json) {
        if (!json || typeof json !== 'object') {
          throw new Error(failure(res));
        }
        if (!res.ok && !json.message) {
          throw new Error(failure(res));
        }
        return json;
      });
    });
  }

  function busy(el, on) {
    if (!el) { return; }
    el.disabled = !!on;
    if (on) { el.setAttribute('aria-busy', 'true'); } else { el.removeAttribute('aria-busy'); }
  }

  // ── Confirm dialog (kit sheet) ────────────────────────────────────────
  function confirmBox(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var dlg = doc.createElement('dialog');
      dlg.className = 'ui-sheet';
      dlg.setAttribute('aria-labelledby', 'ui-admin-confirm-title');
      var title = doc.createElement('h2');
      title.className = 'ui-sheet__title';
      title.id = 'ui-admin-confirm-title';
      title.textContent = opts.title || t('confirm', 'Are you sure?');
      dlg.appendChild(title);
      if (message) {
        var p = doc.createElement('p');
        p.className = 'ui-sheet__desc';
        p.textContent = message;
        dlg.appendChild(p);
      }
      var foot = doc.createElement('div');
      foot.className = 'ui-sheet__foot';
      var no = doc.createElement('button');
      no.type = 'button';
      no.className = 'ui-btn ui-btn--secondary';
      no.textContent = t('cancel', 'Cancel');
      var yes = doc.createElement('button');
      yes.type = 'button';
      yes.className = 'ui-btn ' + (opts.danger ? 'ui-btn--danger' : 'ui-btn--dark');
      yes.textContent = opts.ok || t('ok', 'Confirm');
      foot.appendChild(no);
      foot.appendChild(yes);
      dlg.appendChild(foot);
      doc.body.appendChild(dlg);
      var answered = false;
      function done(value) {
        if (answered) { return; }
        answered = true;
        try { dlg.close(); } catch (e) { /* already closed */ }
        dlg.remove();
        resolve(value);
      }
      no.addEventListener('click', function () { done(false); });
      yes.addEventListener('click', function () { done(true); });
      dlg.addEventListener('cancel', function () { done(false); });
      dlg.addEventListener('click', function (e) { if (e.target === dlg) { done(false); } });
      if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
      yes.focus();
    });
  }

  // ── Data tables ───────────────────────────────────────────────────────
  var TD = /^\s*<td(?:\s[^>]*)?>([\s\S]*)<\/td>\s*$/i;

  function DataTable(root) {
    this.root = root;
    this.table = root.querySelector('table');
    this.body = this.table.tBodies[0] || this.table.appendChild(doc.createElement('tbody'));
    this.source = root.getAttribute('data-source');
    this.start = 0;
    this.length = parseInt(root.getAttribute('data-length') || '25', 10) || 25;
    this.orderCol = parseInt(root.getAttribute('data-order-col') || '0', 10) || 0;
    this.orderDir = root.getAttribute('data-order-dir') === 'asc' ? 'asc' : 'desc';
    this.search = '';
    this.draw = 0;
    this.total = 0;
    this.filtered = 0;
    this.labels = Array.prototype.map.call(this.table.tHead.rows[0].cells, function (c) { return c.textContent.trim(); });
    this.bind();
    this.paintSort();
    this.load();
  }

  DataTable.prototype.bind = function () {
    var self = this;
    var root = this.root;
    this.info = root.querySelector('[data-ui-dt-info]');
    this.pages = root.querySelector('[data-ui-dt-pages]');
    this.count = root.querySelector('[data-ui-dt-count]');

    var search = root.querySelector('[data-ui-dt-search]');
    if (search) {
      var timer = null;
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { self.search = search.value.trim(); self.start = 0; self.load(); }, 300);
      });
    }
    var size = root.querySelector('[data-ui-dt-size]');
    if (size) {
      size.value = String(this.length);
      size.addEventListener('change', function () { self.length = parseInt(size.value, 10) || 25; self.start = 0; self.load(); });
    }
    root.querySelectorAll('[data-ui-dt-filter]').forEach(function (f) {
      f.addEventListener('change', function () { self.start = 0; self.load(); });
    });
    Array.prototype.forEach.call(this.table.tHead.rows[0].cells, function (th, i) {
      var btn = th.querySelector('.ui-dt__sort');
      if (!btn) { return; }
      btn.addEventListener('click', function () {
        if (self.orderCol === i) { self.orderDir = self.orderDir === 'asc' ? 'desc' : 'asc'; } else { self.orderCol = i; self.orderDir = 'asc'; }
        self.start = 0;
        self.paintSort();
        self.load();
      });
    });
    var all = root.querySelector('[data-ui-dt-all]');
    if (all) {
      all.addEventListener('change', function () {
        self.body.querySelectorAll('.quick-check').forEach(function (c) { c.checked = all.checked; });
        self.syncSelection();
      });
    }
    this.body.addEventListener('change', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('quick-check')) { self.syncSelection(); }
    });
    var del = root.querySelector('[data-ui-dt-delete]');
    if (del) {
      del.addEventListener('click', function () { self.deleteSelected(del); });
    }
    if (root.getAttribute('data-reorder')) { this.bindReorder(); }
  };

  DataTable.prototype.paintSort = function () {
    var self = this;
    Array.prototype.forEach.call(this.table.tHead.rows[0].cells, function (th, i) {
      if (!th.querySelector('.ui-dt__sort')) { return; }
      th.setAttribute('aria-sort', i === self.orderCol ? (self.orderDir === 'asc' ? 'ascending' : 'descending') : 'none');
    });
  };

  DataTable.prototype.url = function () {
    var url = new URL(this.source, window.location.href);
    var p = url.searchParams;
    this.draw += 1;
    p.set('draw', String(this.draw));
    p.set('start', String(this.start));
    p.set('length', String(this.length));
    p.set('order[0][column]', String(this.orderCol));
    p.set('order[0][dir]', this.orderDir);
    p.set('search[value]', this.search);
    this.root.querySelectorAll('[data-ui-dt-filter]').forEach(function (f) {
      if (!f.name) { return; }
      if (f.value === '' || f.value == null) { p.delete(f.name); } else { p.set(f.name, f.value); }
    });
    return url.toString();
  };

  DataTable.prototype.load = function () {
    var self = this;
    var url = this.url();
    var draw = this.draw;
    this.root.classList.add('is-loading');
    this.root.setAttribute('aria-busy', 'true');
    return request(url).then(function (res) {
      if (!res.ok) { throw new Error(failure(res)); }
      return res.json();
    }).then(function (json) {
      if (draw !== self.draw) { return; }
      self.total = json.recordsTotal || 0;
      self.filtered = json.recordsFiltered != null ? json.recordsFiltered : self.total;
      if (self.start > 0 && self.start >= self.filtered) {
        self.start = Math.max(0, Math.floor((self.filtered - 1) / self.length) * self.length);
        self.load();
        return;
      }
      self.render(json.data || []);
    }).catch(function (err) {
      if (draw === self.draw) { toast(err.message || failure(null), 'danger'); }
    }).then(function () {
      if (draw === self.draw) {
        self.root.classList.remove('is-loading');
        self.root.removeAttribute('aria-busy');
      }
    });
  };

  DataTable.prototype.render = function (rows) {
    var self = this;
    var frag = doc.createDocumentFragment();
    rows.forEach(function (row) {
      var tr = doc.createElement('tr');
      if (row.DT_RowId != null) { tr.id = String(row.DT_RowId); tr.setAttribute('data-id', String(row.DT_RowId)); }
      var cells = Array.isArray(row) ? row : Object.keys(row).filter(function (k) { return /^\d+$/.test(k); })
        .sort(function (a, b) { return a - b; }).map(function (k) { return row[k]; });
      cells.forEach(function (html, i) {
        var td = doc.createElement('td');
        var m = TD.exec(String(html == null ? '' : html));
        td.innerHTML = m ? m[1] : String(html == null ? '' : html);
        if (self.labels[i]) { td.setAttribute('data-label', self.labels[i]); }
        tr.appendChild(td);
      });
      frag.appendChild(tr);
    });
    if (!rows.length) {
      var empty = doc.createElement('tr');
      empty.className = 'ui-dt__empty';
      var cell = doc.createElement('td');
      cell.colSpan = this.labels.length || 1;
      cell.textContent = t('empty', 'No records found');
      empty.appendChild(cell);
      frag.appendChild(empty);
    }
    this.body.textContent = '';
    this.body.appendChild(frag);
    var all = this.root.querySelector('[data-ui-dt-all]');
    if (all) { all.checked = false; }
    this.syncSelection();
    this.paintFoot(rows.length);
    enhance(this.body);
  };

  DataTable.prototype.paintFoot = function (shown) {
    var self = this;
    if (this.info) {
      var from = shown ? this.start + 1 : 0;
      var to = this.start + shown;
      var text = t('info', 'Showing :from–:to of :total', { from: from, to: to, total: this.filtered });
      if (this.filtered !== this.total) { text += ' ' + t('filtered', '(filtered from :total)', { total: this.total }); }
      this.info.textContent = text;
    }
    if (!this.pages) { return; }
    this.pages.textContent = '';
    var pageCount = Math.max(1, Math.ceil(this.filtered / this.length));
    var current = Math.floor(this.start / this.length);
    function add(label, page, opts) {
      var b = doc.createElement('button');
      b.type = 'button';
      b.className = 'ui-dt__page';
      b.textContent = label;
      if (opts && opts.aria) { b.setAttribute('aria-label', opts.aria); }
      if (page === current && !(opts && opts.nav)) { b.setAttribute('aria-current', 'page'); }
      if (page < 0 || page >= pageCount || (opts && opts.disabled)) { b.disabled = true; }
      b.addEventListener('click', function () { self.start = page * self.length; self.load(); });
      self.pages.appendChild(b);
    }
    add('‹', current - 1, { nav: true, aria: t('prev', 'Previous') });
    var pages = [];
    for (var i = 0; i < pageCount; i++) {
      if (i === 0 || i === pageCount - 1 || Math.abs(i - current) <= 1) { pages.push(i); }
    }
    var last = -1;
    pages.forEach(function (p) {
      if (p - last > 1) { add('…', -1, { disabled: true }); }
      add(String(p + 1), p);
      last = p;
    });
    add('›', current + 1, { nav: true, aria: t('next', 'Next') });
  };

  DataTable.prototype.selected = function () {
    return Array.prototype.map.call(this.body.querySelectorAll('.quick-check:checked'), function (c) { return c.value; });
  };

  DataTable.prototype.syncSelection = function () {
    var n = this.selected().length;
    this.root.classList.toggle('has-selection', n > 0);
    if (this.count) { this.count.textContent = t('selected', ':count selected', { count: n }); }
  };

  DataTable.prototype.deleteSelected = function (btn) {
    var self = this;
    var ids = this.selected();
    if (!ids.length) { return; }
    confirmBox(t('confirmDelete', 'Delete the selected items? This cannot be undone.'), { danger: true, ok: t('delete', 'Delete') }).then(function (yes) {
      if (!yes) { return; }
      var body = new FormData();
      ids.forEach(function (id) { body.append('ids[]', id); });
      busy(btn, true);
      postJson(self.root.getAttribute('data-delete'), body).then(function (json) {
        toast(json.message, json.success ? 'success' : 'danger');
        if (json.success) { self.load(); }
      }).catch(function (err) { toast(err.message, 'danger'); }).then(function () { busy(btn, false); });
    });
  };

  DataTable.prototype.bindReorder = function () {
    var self = this;
    sortableRows(this.body, function () { self.savePositions(); });
  };

  DataTable.prototype.savePositions = function () {
    savePositions(this.body, this.root.getAttribute('data-reorder'));
  };

  // Rows of `body` drag by their .app-reorder-icon / .ui-reorder handle;
  // onDrop runs after a row moved.
  function sortableRows(body, onDrop) {
    var dragged = null;
    function clearMarks() {
      body.querySelectorAll('.is-drop-before, .is-drop-after').forEach(function (r) { r.classList.remove('is-drop-before', 'is-drop-after'); });
    }
    body.addEventListener('pointerdown', function (e) {
      var handle = e.target.closest && e.target.closest('.app-reorder-icon, .ui-reorder');
      var tr = handle && handle.closest('tr');
      if (tr) { tr.draggable = true; }
    });
    body.addEventListener('dragstart', function (e) {
      var tr = e.target.closest && e.target.closest('tr');
      if (!tr || !tr.draggable) { e.preventDefault(); return; }
      dragged = tr;
      tr.classList.add('is-dragging');
      try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', tr.id); } catch (err) { /* old browsers */ }
    });
    body.addEventListener('dragover', function (e) {
      var tr = e.target.closest && e.target.closest('tr');
      if (!dragged || !tr || tr === dragged) { return; }
      e.preventDefault();
      clearMarks();
      var box = tr.getBoundingClientRect();
      tr.classList.add(e.clientY < box.top + box.height / 2 ? 'is-drop-before' : 'is-drop-after');
    });
    body.addEventListener('drop', function (e) {
      var tr = e.target.closest && e.target.closest('tr');
      if (!dragged || !tr || tr === dragged) { return; }
      e.preventDefault();
      var before = tr.classList.contains('is-drop-before');
      tr.parentNode.insertBefore(dragged, before ? tr : tr.nextSibling);
      clearMarks();
      onDrop();
    });
    body.addEventListener('dragend', function () {
      if (dragged) { dragged.classList.remove('is-dragging'); dragged.draggable = false; }
      dragged = null;
      clearMarks();
    });
  }

  // Posts position[] (the row ids in their current order) to url.
  function savePositions(body, url) {
    var data = new FormData();
    Array.prototype.forEach.call(body.rows, function (r) { if (r.id) { data.append('position[]', r.id); } });
    postJson(url, data).then(function (json) {
      toast(json.message, json.success ? 'success' : 'danger');
    }).catch(function (err) { toast(err.message, 'danger'); });
  }

  function reloadTables() {
    if (!tables.length) { return false; }
    tables.forEach(function (tb) { tb.load(); });
    return true;
  }

  // ── Side panel ────────────────────────────────────────────────────────
  var panel = null;
  var scrim = null;
  var opener = null;

  function ensurePanel() {
    if (panel) { return panel; }
    scrim = doc.createElement('div');
    scrim.className = 'ui-drawer-backdrop';
    scrim.hidden = true;
    scrim.addEventListener('click', closePanel);
    panel = doc.createElement('aside');
    panel.className = 'ui-drawer ui-panel';
    panel.id = 'ui-admin-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-labelledby', 'ui-admin-panel-title');
    doc.body.appendChild(scrim);
    doc.body.appendChild(panel);
    return panel;
  }

  function runScripts(root) {
    root.querySelectorAll('script').forEach(function (old) {
      var src = old.getAttribute('src');
      if (src && doc.querySelector('script[src="' + src.replace(/"/g, '\\"') + '"]:not([data-ui-panel-script])')) { old.remove(); return; }
      var s = doc.createElement('script');
      Array.prototype.forEach.call(old.attributes, function (a) { s.setAttribute(a.name, a.value); });
      s.setAttribute('data-ui-panel-script', '');
      s.text = old.textContent;
      old.replaceWith(s);
    });
  }

  function openPanel(url, trigger) {
    var el = ensurePanel();
    opener = trigger || doc.activeElement;
    el.innerHTML = '<div class="ui-panel__loading" role="status">' + t('loading', 'Loading…').replace(/</g, '&lt;') + '</div>';
    scrim.hidden = false;
    el.classList.add('is-open');
    doc.body.style.overflow = 'hidden';
    request(url, { accept: 'text/html' }).then(function (res) {
      if (!res.ok) { throw new Error(failure(res)); }
      return res.text();
    }).then(function (html) {
      el.innerHTML = html;
      runScripts(el);
      enhance(el);
      var first = el.querySelector('.ui-panel__body input:not([type="hidden"]), .ui-panel__body select, .ui-panel__body textarea');
      if (first) { first.focus({ preventScroll: true }); }
    }).catch(function (err) {
      closePanel();
      toast(err.message, 'danger');
    });
  }

  function closePanel() {
    if (!panel || !panel.classList.contains('is-open')) { return; }
    panel.classList.remove('is-open');
    scrim.hidden = true;
    doc.body.style.overflow = '';
    setTimeout(function () { if (panel && !panel.classList.contains('is-open')) { panel.innerHTML = ''; } }, 300);
    if (opener && opener.focus && doc.contains(opener)) { opener.focus({ preventScroll: true }); }
    opener = null;
  }

  function syncRich(form) {
    form.querySelectorAll('textarea[data-ui-rich]').forEach(function (a) { if (a.__richSync && a.hidden) { a.__richSync(); } });
  }

  function savePanel(btn) {
    var form = panel && panel.querySelector('form');
    if (!form) { return; }
    syncRich(form);
    // No browser validation gate: the server answers {success:false, message} (as the old panels did).
    busy(btn, true);
    postJson(form.getAttribute('data-action') || form.getAttribute('action'), new FormData(form)).then(function (json) {
      if (json.success) {
        toast(json.message, 'success');
        closePanel();
        if (!reloadTables()) { window.location.reload(); }
      } else {
        toast(json.message, 'danger');
      }
    }).catch(function (err) { toast(err.message, 'danger'); }).then(function () { busy(btn, false); });
  }

  // ── Tabs ──────────────────────────────────────────────────────────────
  function showTab(tab, updateHash) {
    var box = tab.closest('[data-ui-tabs]');
    if (!box) { return; }
    box.querySelectorAll('[role="tab"]').forEach(function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      var pane = doc.getElementById(t.getAttribute('aria-controls'));
      if (pane) { pane.hidden = !on; }
    });
    if (updateHash && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', '#' + tab.getAttribute('aria-controls'));
    }
  }

  function bootTabs() {
    doc.querySelectorAll('[data-ui-tabs]').forEach(function (box) {
      var id = window.location.hash.replace(/^#/, '');
      var tab = id ? box.querySelector('[role="tab"][aria-controls="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]') : null;
      tab = tab || box.querySelector('[role="tab"][aria-selected="true"]') || box.querySelector('[role="tab"]');
      if (tab) { showTab(tab, false); }
    });
  }

  // ── Ajax forms and one-off posts ──────────────────────────────────────
  function formData(form, submitter) {
    var data;
    try { data = submitter ? new FormData(form, submitter) : new FormData(form); } catch (e) {
      data = new FormData(form);
      if (submitter && submitter.name) { data.append(submitter.name, submitter.value || ''); }
    }
    return data;
  }

  function submitAjaxForm(form, submitter) {
    syncRich(form);
    var btn = submitter || form.querySelector('[type="submit"]');
    busy(btn, true);
    postJson(form.getAttribute('data-action') || form.getAttribute('action') || window.location.href, formData(form, submitter)).then(function (json) {
      toast(json.message || '', json.success ? 'success' : 'danger');
      if (json.success && form.hasAttribute('data-ui-reload')) { window.location.reload(); }
    }).catch(function (err) { toast(err.message, 'danger'); }).then(function () { busy(btn, false); });
  }

  function postAction(el) {
    var go = function () {
      var body = new FormData();
      if (el.getAttribute('data-ui-id')) { body.append('id', el.getAttribute('data-ui-id')); }
      busy(el, true);
      postJson(el.getAttribute('data-ui-post'), body).then(function (json) {
        toast(json.message, json.success ? 'success' : 'danger');
        if (json.success && !reloadTables()) { window.location.reload(); }
      }).catch(function (err) { toast(err.message, 'danger'); }).then(function () { busy(el, false); });
    };
    var question = el.getAttribute('data-ui-confirm');
    if (question) {
      confirmBox('', { title: question, danger: el.hasAttribute('data-ui-danger') }).then(function (yes) { if (yes) { go(); } });
    } else {
      go();
    }
  }

  // ── Multi-select ──────────────────────────────────────────────────────
  function multiSelect(select) {
    if (select.getAttribute('data-ui-multi-ready')) { return; }
    select.setAttribute('data-ui-multi-ready', '1');
    var wrap = doc.createElement('div');
    wrap.className = 'ui-multi';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    var box = doc.createElement('div');
    box.className = 'ui-multi__box';
    var input = doc.createElement('input');
    input.type = 'text';
    input.className = 'ui-multi__input';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('autocomplete', 'off');
    input.placeholder = select.getAttribute('data-placeholder') || t('search', 'Search');
    if (select.id) {
      var label = doc.querySelector('label[for="' + select.id + '"]');
      if (label) { input.id = select.id + '-search'; label.setAttribute('for', input.id); }
    }
    var list = doc.createElement('ul');
    list.className = 'ui-multi__list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-multiselectable', 'true');
    list.id = 'ui-multi-' + Math.random().toString(36).slice(2, 9);
    list.hidden = true;
    input.setAttribute('aria-controls', list.id);
    wrap.appendChild(box);
    wrap.appendChild(list);
    var cursor = 0;

    function options() { return Array.prototype.slice.call(select.options); }
    function paintChips() {
      box.querySelectorAll('.ui-multi__chip').forEach(function (c) { c.remove(); });
      options().filter(function (o) { return o.selected; }).forEach(function (o) {
        var chip = doc.createElement('button');
        chip.type = 'button';
        chip.className = 'ui-multi__chip';
        chip.textContent = o.textContent;
        chip.setAttribute('aria-label', o.textContent + ' ×');
        chip.addEventListener('click', function (e) { e.stopPropagation(); o.selected = false; paintChips(); paintList(); select.dispatchEvent(new Event('change', { bubbles: true })); });
        box.insertBefore(chip, input);
      });
    }
    function matches() {
      var q = input.value.trim().toLowerCase();
      return options().filter(function (o) { return !q || o.textContent.toLowerCase().indexOf(q) !== -1; });
    }
    function paintList() {
      list.textContent = '';
      var found = matches();
      if (!found.length) {
        var none = doc.createElement('li');
        none.className = 'ui-multi__opt';
        none.textContent = t('noMatches', 'No matches');
        list.appendChild(none);
        return;
      }
      cursor = Math.min(cursor, found.length - 1);
      found.slice(0, 200).forEach(function (o, i) {
        var li = doc.createElement('li');
        li.className = 'ui-multi__opt' + (i === cursor ? ' is-active' : '');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', o.selected ? 'true' : 'false');
        li.textContent = o.textContent;
        li.addEventListener('mousedown', function (e) { e.preventDefault(); toggle(o); });
        list.appendChild(li);
      });
    }
    function toggle(o) {
      o.selected = !o.selected;
      input.value = '';
      paintChips();
      paintList();
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function open(v) { list.hidden = !v; input.setAttribute('aria-expanded', v ? 'true' : 'false'); if (v) { paintList(); } }
    box.addEventListener('click', function () { input.focus(); open(true); });
    input.addEventListener('focus', function () { open(true); });
    input.addEventListener('blur', function () { setTimeout(function () { open(false); }, 120); });
    input.addEventListener('input', function () { cursor = 0; open(true); });
    input.addEventListener('keydown', function (e) {
      var found = matches();
      if (e.key === 'ArrowDown') { e.preventDefault(); cursor = Math.min(cursor + 1, found.length - 1); open(true); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); cursor = Math.max(cursor - 1, 0); open(true); }
      else if (e.key === 'Enter') { e.preventDefault(); if (found[cursor]) { toggle(found[cursor]); } }
      else if (e.key === 'Escape') { if (!list.hidden) { e.stopPropagation(); open(false); } }
      else if (e.key === 'Backspace' && input.value === '') {
        var picked = options().filter(function (o) { return o.selected; });
        if (picked.length) { toggle(picked[picked.length - 1]); }
      }
    });
    box.appendChild(input);
    paintChips();
  }


  // ── Translated fields ─────────────────────────────────────────────────
  doc.addEventListener('change', function (e) {
    var all = e.target.closest && e.target.closest('[data-ui-check-all]');
    if (all) {
      var sc = all.closest('[data-ui-bulk-scope]') || doc;
      sc.querySelectorAll('.quick-check').forEach(function (c) { c.checked = all.checked; });
      return;
    }
    var pick = e.target.closest && e.target.closest('[data-ui-translate-pick]');
    if (pick) {
      var box = pick.closest('[data-ui-translate]');
      box.querySelectorAll('[data-ui-translate-lang]').forEach(function (el) {
        el.hidden = el.getAttribute('data-ui-translate-lang') !== pick.value;
      });
      return;
    }
    var file = e.target.closest && e.target.closest('input[type="file"][data-ui-preview]');
    if (file && file.files && file.files[0]) {
      var img = doc.getElementById(file.getAttribute('data-ui-preview'));
      if (img) {
        var reader = new FileReader();
        reader.onload = function (ev) { img.src = ev.target.result; };
        reader.readAsDataURL(file.files[0]);
      }
    }
  });
  doc.addEventListener('input', function (e) {
    var q = e.target.closest && e.target.closest('[data-ui-table-search]');
    if (q) {
      var table = doc.getElementById(q.getAttribute('data-ui-table-search'));
      var needle = q.value.trim().toLowerCase();
      if (table) {
        Array.prototype.forEach.call(table.tBodies[0] ? table.tBodies[0].rows : [], function (row) {
          var hay = (row.getAttribute('data-key') || row.textContent).toLowerCase();
          row.hidden = needle !== '' && hay.indexOf(needle) === -1;
        });
      }
      return;
    }
    var def = e.target.closest && e.target.closest('[data-ui-translate-default]');
    if (!def) { return; }
    var base = def.closest('[data-ui-translate]').querySelector('[data-ui-translate-base]');
    if (base) { base.value = def.value; }
  });

  // ── Rich text (textarea[data-ui-rich]) ────────────────────────────────
  var RICH_TOOLS = [
    ['bold', 'B', 'bold'], ['italic', 'I', 'italic'], ['underline', 'U', 'underline'],
    ['formatBlock:h2', 'H2', 'heading'], ['formatBlock:h3', 'H3', 'subheading'], ['formatBlock:p', '¶', 'paragraph'],
    ['insertUnorderedList', '•', 'bullets'], ['insertOrderedList', '1.', 'numbers'],
    ['createLink', '🔗', 'link'], ['unlink', '⛓', 'unlink'], ['removeFormat', '⌫', 'clear'], ['source', '</>', 'source'],
  ];

  function richEditor(area) {
    if (area.getAttribute('data-ui-rich-ready')) { return; }
    area.setAttribute('data-ui-rich-ready', '1');
    var wrap = doc.createElement('div');
    wrap.className = 'ui-rich';
    var bar = doc.createElement('div');
    bar.className = 'ui-rich__bar';
    bar.setAttribute('role', 'toolbar');
    var edit = doc.createElement('div');
    edit.className = 'ui-rich__edit';
    edit.contentEditable = 'true';
    edit.setAttribute('role', 'textbox');
    edit.setAttribute('aria-multiline', 'true');
    if (area.id) {
      var label = doc.querySelector('label[for="' + area.id + '"]');
      if (label) { label.id = label.id || area.id + '-label'; edit.setAttribute('aria-labelledby', label.id); }
    }
    edit.innerHTML = area.value;
    area.parentNode.insertBefore(wrap, area);
    wrap.appendChild(bar);
    wrap.appendChild(edit);
    wrap.appendChild(area);
    area.classList.add('ui-rich__source');
    area.hidden = true;
    var sync = function () { area.value = edit.innerHTML; };
    edit.addEventListener('input', sync);
    edit.addEventListener('blur', sync);
    area.addEventListener('input', function () { edit.innerHTML = area.value; });
    RICH_TOOLS.forEach(function (tool) {
      var b = doc.createElement('button');
      b.type = 'button';
      b.className = 'ui-rich__tool';
      b.textContent = tool[1];
      var name = t('rich_' + tool[2], tool[2]);
      b.title = name;
      b.setAttribute('aria-label', name);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        if (tool[0] === 'source') {
          var showing = area.hidden;
          if (showing) { sync(); }
          area.hidden = !showing;
          edit.hidden = showing;
          b.setAttribute('aria-pressed', showing ? 'true' : 'false');
          (showing ? area : edit).focus();
          return;
        }
        edit.focus();
        var parts = tool[0].split(':');
        if (parts[0] === 'createLink') {
          var url = window.prompt(t('rich_url', 'Link address'), 'https://');
          if (!url) { return; }
          doc.execCommand('createLink', false, url);
        } else {
          doc.execCommand(parts[0], false, parts[1] ? '<' + parts[1] + '>' : null);
        }
        sync();
      });
      bar.appendChild(b);
    });
    // The form reads the textarea; make sure it holds the latest HTML.
    if (area.form) { area.form.addEventListener('submit', sync, true); area.form.addEventListener('formdata', sync); }
    area.__richSync = sync;
  }

  function randomCode(n) {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    var out = '';
    var bytes = new Uint32Array(n);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    for (var i = 0; i < n; i++) { out += chars.charAt(bytes[i] % chars.length); }
    return out;
  }

  function enhance(root) {
    (root || doc).querySelectorAll('input[data-ui-code]').forEach(function (i) { if (!i.value) { i.value = randomCode(12); } });
    (root || doc).querySelectorAll('select[multiple][data-ui-multi]').forEach(multiSelect);
    (root || doc).querySelectorAll('textarea[data-ui-rich]').forEach(richEditor);
  }

  // ── Delegated events ──────────────────────────────────────────────────
  doc.addEventListener('click', function (e) {
    var el = e.target instanceof Element ? e.target : null;
    if (!el) { return; }
    var trigger = el.closest('[data-toggle="slidePanel"][data-url], [data-ui-panel]');
    if (trigger) {
      e.preventDefault();
      openPanel(trigger.getAttribute('data-ui-panel') || trigger.getAttribute('data-url'), trigger);
      return;
    }
    var save = el.closest('[data-ui-panel-save]');
    if (save) { e.preventDefault(); savePanel(save); return; }
    if (el.closest('[data-ui-panel-close]')) { e.preventDefault(); closePanel(); return; }
    var copy = el.closest('[data-ui-copy-text]');
    if (copy) {
      e.preventDefault();
      var text = copy.getAttribute('data-ui-copy-text');
      var done = function () { toast(t('copied', 'Copied'), 'success'); };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); }
      return;
    }
    var tabBtn = el.closest('[data-ui-tabs] [role="tab"]');
    if (tabBtn) { e.preventDefault(); showTab(tabBtn, true); return; }
    var formPost = el.closest('[data-ui-form-post]');
    if (formPost && formPost.form) {
      e.preventDefault();
      busy(formPost, true);
      postJson(formPost.getAttribute('data-ui-form-post'), new FormData(formPost.form)).then(function (json) {
        toast(json.message || '', json.success ? 'success' : 'danger');
      }).catch(function (err) { toast(err.message, 'danger'); }).then(function () { busy(formPost, false); });
      return;
    }
    var bulk = el.closest('[data-ui-bulk]');
    if (bulk) {
      e.preventDefault();
      var scope = bulk.closest('[data-ui-bulk-scope]') || doc;
      var ids = bulk.hasAttribute('data-ui-bulk-id') ? [bulk.getAttribute('data-ui-bulk-id')]
        : (bulk.getAttribute('data-ui-bulk-ids') ? bulk.getAttribute('data-ui-bulk-ids').split(',')
          : Array.prototype.map.call(scope.querySelectorAll('.quick-check:checked'), function (c) { return c.value; }));
      if (!ids.length) { toast(t('pickOne', 'Select at least one row.'), 'danger'); return; }
      confirmBox(t('confirmDelete', 'Delete the selected items? This cannot be undone.'), { danger: true, ok: t('delete', 'Delete') }).then(function (yes) {
        if (!yes) { return; }
        var body = new FormData();
        ids.forEach(function (id) { body.append('ids[]', id); });
        busy(bulk, true);
        postJson(bulk.getAttribute('data-ui-bulk'), body).then(function (json) {
          toast(json.message, json.success ? 'success' : 'danger');
          if (json.success) { window.location.reload(); }
        }).catch(function (err) { toast(err.message, 'danger'); }).then(function () { busy(bulk, false); });
      });
      return;
    }
    var gen = el.closest('[data-ui-code-for]');
    if (gen) {
      var target = doc.getElementById(gen.getAttribute('data-ui-code-for'));
      if (target) { target.value = randomCode(12); target.dispatchEvent(new Event('input', { bubbles: true })); }
      return;
    }
    var post = el.closest('[data-ui-post]');
    if (post) { e.preventDefault(); postAction(post); }
  });

  doc.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) { return; }
    if (panel && panel.contains(form)) {
      e.preventDefault();
      savePanel(panel.querySelector('[data-ui-panel-save]'));
      return;
    }
    if (form.hasAttribute('data-ui-ajax')) {
      e.preventDefault();
      submitAjaxForm(form, e.submitter || null);
      return;
    }
    if (form.hasAttribute('data-ui-confirm') && !form.__confirmed) {
      e.preventDefault();
      confirmBox('', { title: form.getAttribute('data-ui-confirm'), danger: form.hasAttribute('data-ui-danger') }).then(function (yes) {
        if (yes) { form.__confirmed = true; form.submit(); }
      });
    }
  });

  doc.addEventListener('keydown', function (e) {
    var tabKey = e.target.closest && e.target.closest('[data-ui-tabs] [role="tab"]');
    if (tabKey && /^(ArrowDown|ArrowUp|ArrowLeft|ArrowRight|Home|End)$/.test(e.key)) {
      var tabs = Array.prototype.slice.call(tabKey.closest('[data-ui-tabs]').querySelectorAll('[role="tab"]'));
      var i = tabs.indexOf(tabKey);
      var rtl = doc.documentElement.dir === 'rtl';
      var step = { ArrowDown: 1, ArrowUp: -1, ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[e.key];
      var next = e.key === 'Home' ? tabs[0] : e.key === 'End' ? tabs[tabs.length - 1] : tabs[(i + step + tabs.length) % tabs.length];
      e.preventDefault();
      showTab(next, true);
      next.focus();
      return;
    }
    if (e.key === 'Escape' && panel && panel.classList.contains('is-open') && !doc.querySelector('dialog[open]')) {
      closePanel();
      return;
    }
    if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
      var a = doc.activeElement;
      if (a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT' || a.isContentEditable)) { return; }
      var box = doc.querySelector('[data-admin-search]');
      if (box) { e.preventDefault(); box.focus(); }
    }
  });

  // ── Charts (small SVG, no library) ────────────────────────────────────
  var SVG = 'http://www.w3.org/2000/svg';
  function svgEl(name, attrs) {
    var n = doc.createElementNS(SVG, name);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    return n;
  }
  function niceMax(v) {
    if (v <= 0) { return 1; }
    var p = Math.pow(10, Math.floor(Math.log10(v)));
    var n = v / p;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
  }

  /**
   * Stacked daily bars. data = {labels: [], series: [{label, values: [], color}]},
   * opts.format(value) for the axis and tooltip.
   */
  function bars(el, data, opts) {
    opts = opts || {};
    var fmt = opts.format || function (v) { return Number(v).toLocaleString(); };
    var labels = data.labels || [];
    var series = data.series || [];
    // Draw in the box's own pixel size so text is never stretched; redrawn on resize.
    el.__bars = [data, opts];
    var W = Math.max(280, el.clientWidth || 640), H = Math.max(160, el.clientHeight || 260), L = 64, B = 24, T = 8;
    var totals = labels.map(function (_, i) { return series.reduce(function (s, se) { return s + (Number(se.values[i]) || 0); }, 0); });
    var max = niceMax(Math.max.apply(null, totals.concat([0])));
    var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none', role: 'img', 'aria-label': el.getAttribute('aria-label') || '' });
    for (var g = 0; g <= 4; g++) {
      var y = T + (H - B - T) * (1 - g / 4);
      svg.appendChild(svgEl('line', { x1: L, x2: W, y1: y, y2: y, class: 'ui-bars__grid' }));
      var tx = svgEl('text', { x: L - 8, y: y + 4, 'text-anchor': 'end', class: 'ui-bars__axis' });
      tx.textContent = fmt(max * g / 4);
      svg.appendChild(tx);
    }
    var slot = (W - L) / Math.max(1, labels.length);
    var bw = Math.min(18, slot * 0.7);
    var every = Math.max(1, Math.ceil(labels.length / 10));
    var tip = el.querySelector('.ui-chart-tip');
    if (!tip) { tip = doc.createElement('div'); tip.className = 'ui-chart-tip'; tip.hidden = true; el.appendChild(tip); }
    labels.forEach(function (label, i) {
      var x = L + slot * i + (slot - bw) / 2;
      var col = svgEl('g', { class: 'ui-bars__col', tabindex: '0' });
      var base = H - B;
      var lines = [label];
      series.forEach(function (se, s) {
        var v = Number(se.values[i]) || 0;
        var h = (H - B - T) * v / max;
        if (h > 0) {
          col.appendChild(svgEl('rect', { x: x, y: base - h, width: bw, height: h, rx: 2, fill: se.color || 'var(--ui-series-' + (s + 1) + ')' }));
        }
        base -= h;
        lines.push(se.label + ': ' + fmt(v));
      });
      col.appendChild(svgEl('rect', { x: L + slot * i, y: T, width: slot, height: H - B - T, fill: 'transparent' }));
      var show = function () {
        tip.textContent = lines.join('\n');
        tip.style.whiteSpace = 'pre';
        tip.hidden = false;
        var r = el.getBoundingClientRect();
        var px = (L + slot * i + slot / 2) / W * r.width;
        tip.style.left = Math.max(0, Math.min(r.width - tip.offsetWidth, px - tip.offsetWidth / 2)) + 'px';
        tip.style.top = '0px';
      };
      col.addEventListener('mouseenter', show);
      col.addEventListener('focus', show);
      col.addEventListener('mouseleave', function () { tip.hidden = true; });
      col.addEventListener('blur', function () { tip.hidden = true; });
      svg.appendChild(col);
      if (i % every === 0) {
        var lx = svgEl('text', { x: L + slot * i + slot / 2, y: H - 6, 'text-anchor': 'middle', class: 'ui-bars__axis' });
        lx.textContent = label;
        svg.appendChild(lx);
      }
    });
    var old = el.querySelector('svg');
    if (old) { old.replaceWith(svg); } else { el.insertBefore(svg, el.firstChild); }
  }

  /** Donut of slices [{label, count}] coloured --ui-series-1..6. */
  function donut(el, slices) {
    var total = slices.reduce(function (s, x) { return s + (Number(x.count) || 0); }, 0) || 1;
    var r = 15.915, c = 2 * Math.PI * r, offset = 0;
    var svg = svgEl('svg', { viewBox: '0 0 42 42', role: 'img', 'aria-label': el.getAttribute('aria-label') || '' });
    svg.appendChild(svgEl('circle', { cx: 21, cy: 21, r: r, fill: 'none', stroke: 'var(--ui-inset)', 'stroke-width': 6 }));
    slices.forEach(function (s, i) {
      var len = (Number(s.count) || 0) / total * c;
      var arc = svgEl('circle', { cx: 21, cy: 21, r: r, fill: 'none', stroke: 'var(--ui-series-' + (i % 6 + 1) + ')', 'stroke-width': 6,
        'stroke-dasharray': len + ' ' + (c - len), 'stroke-dashoffset': -offset });
      var title = svgEl('title');
      title.textContent = s.label + ': ' + Number(s.count).toLocaleString();
      arc.appendChild(title);
      svg.appendChild(arc);
      offset += len;
    });
    el.textContent = '';
    el.appendChild(svg);
  }

  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      doc.querySelectorAll('.ui-bars').forEach(function (el) { if (el.__bars) { bars(el, el.__bars[0], el.__bars[1]); } });
    }, 150);
  });

  function boot() {
    doc.querySelectorAll('[data-ui-dt]').forEach(function (root) {
      if (!root.__dt) { root.__dt = new DataTable(root); tables.push(root.__dt); }
    });
    doc.querySelectorAll('tbody[data-ui-reorder]').forEach(function (body) {
      if (body.__sortable) { return; }
      body.__sortable = true;
      sortableRows(body, function () { savePositions(body, body.getAttribute('data-ui-reorder')); });
    });
    bootTabs();
    enhance(doc);
  }

  window.adminUi = { toast: toast, confirm: confirmBox, reload: reloadTables, openPanel: openPanel, closePanel: closePanel, enhance: enhance };
  window.adminCharts = { bars: bars, donut: donut };

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
