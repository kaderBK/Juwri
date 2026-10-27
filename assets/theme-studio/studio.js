// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Theme Studio — owner editor (rev/theme-studio/IMPLEMENTATION-SPEC.md §3 Phase C,
 * contract rev/theme-studio/EDITOR-CONTRACT.md).
 *
 * Plain JS, no framework. Config ships as a JSON data block
 * (#theme-studio-config) printed by owner/design/studio.blade.php.
 *
 *   state    = the layout document from GET urls.state
 *   edit     = mutate a local copy → undo snapshot → debounced (800 ms) PUT draft
 *              with the FULL layout. 422 → inline field errors + roll back to the
 *              last saved layout. 200 → the edited sections are re-rendered via
 *              GET section/{sid} and swapped in the canvas (st:replace); structural
 *              changes reload the canvas (st:reload).
 *   tokens   = colours / fonts through the existing customizer endpoints
 *              (urls.tokens_draft; urls.tokens_vars for the computed CSS vars) and
 *              previewed instantly with st:vars.
 *
 * The canvas is a same-origin iframe running studio-bridge.js; every message is
 * checked against the frame's window and this origin.
 *
 * Turbo Drive: this file may be executed on every visit. It installs its global
 * listeners once, and boot() is idempotent (one editor per #theme-studio node).
 */
(function () {
  'use strict';

  if (window.ThemeStudio && typeof window.ThemeStudio.boot === 'function') {
    window.ThemeStudio.boot();
    return;
  }

  var TEXT_TYPES = { text: 1, textarea: 1, richtext: 1 };
  var GROUPS = ['chrome', 'hero', 'info', 'engage', 'discover', 'catalogue', 'compliance', 'trust', 'content', 'action', 'custom'];
  var GROUP_LABELS = {
    chrome: 'Chrome', hero: 'Hero', info: 'Info', engage: 'Engage', discover: 'Discover', catalogue: 'Catalogue',
    compliance: 'Compliance', trust: 'Trust', content: 'Content', action: 'Action', custom: 'Custom'
  };
  var COLOR_TOKENS = ['primary', 'background', 'surface', 'text', 'accent'];
  var MAX_UPLOAD = 5 * 1024 * 1024;
  var UPLOAD_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  var UNDO_MAX = 50;

  /* Line icons (24px grid, currentColor) — the owner kit set lacks a few the
     editor needs (undo, eye, copy, devices). */
  var ICONS = {
    undo: 'M9 14 4 9l5-5 M4 9h10.5a5.5 5.5 0 0 1 0 11H11',
    redo: 'M15 14l5-5-5-5 M20 9H9.5a5.5 5.5 0 0 0 0 11H13',
    up: 'M12 19V5 M5 12l7-7 7 7',
    down: 'M12 5v14 M19 12l-7 7-7-7',
    copy: 'M8 8h11a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1z M16 8V5a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h4',
    trash: 'M4 7h16 M10 11v6 M14 11v6 M6 7l1 12.5a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5L18 7 M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7',
    eye: 'M2.5 12s3.5-6.5 9.5-6.5 9.5 6.5 9.5 6.5-3.5 6.5-9.5 6.5S2.5 12 2.5 12z M12 9.2a2.8 2.8 0 1 0 0 5.6 2.8 2.8 0 0 0 0-5.6z',
    eyeoff: 'M3 3l18 18 M10.6 5.6A9.6 9.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-3.1 3.9 M6.4 6.9C3.9 8.6 2.5 12 2.5 12s3.5 6.5 9.5 6.5a9 9 0 0 0 4.3-1.1 M9.9 9.9a2.8 2.8 0 0 0 4 4',
    plus: 'M12 5v14 M5 12h14',
    grip: 'M9 6h.01 M15 6h.01 M9 12h.01 M15 12h.01 M9 18h.01 M15 18h.01',
    back: 'M15 6l-6 6 6 6',
    chevron: 'M9 6l6 6-6 6',
    chevdown: 'M6 9l6 6 6-6',
    list: 'M9 6h11 M9 12h11 M9 18h11 M4.5 6h.01 M4.5 12h.01 M4.5 18h.01',
    palette: 'M12 3a9 9 0 1 0 0 18c1.1 0 1.6-.8 1.6-1.6 0-.9-.7-1.4-.7-2.3 0-.9.7-1.6 1.6-1.6H17a4 4 0 0 0 4-4C21 6.6 17 3 12 3z M7.5 11h.01 M10 7.5h.01 M14.5 7.5h.01',
    edit: 'M4 20h4L19 9l-4-4L4 16z M13.5 6.5l4 4',
    upload: 'M12 20V9 M7 13.5l5-5 5 5 M4.5 4.5h15',
    x: 'M6 6l12 12 M18 6 6 18',
    search: 'M11 4.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13z M20 20l-4.2-4.2',
    bold: 'M7 5h6a3.5 3.5 0 0 1 0 7H7z M7 12h7a3.5 3.5 0 0 1 0 7H7z',
    italic: 'M10 5h8 M6 19h8 M14 5l-4 14',
    link: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7 M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
    alert: 'M12 3.5 2.5 20h19z M12 10v4.5 M12 17.2v.1',
    clock: 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17z M12 7.5V12l3 2',
    sparkle: 'M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9z M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z',
    translate: 'M4 5h9 M8.5 3v2 M11 5c-.8 3.8-3.2 6.7-6.5 8.5 M6.5 8.5c1 2 2.6 3.6 4.5 4.6 M12.5 21l4-9 4 9 M13.9 18h5.2'
  };
  var FLIP = { back: 1, chevron: 1, undo: 0, redo: 0 };

  function svgIcon(name, size) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', String(size || 18));
    svg.setAttribute('height', String(size || 18));
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('class', 'ts-icon' + (FLIP[name] ? ' ui-flip-rtl' : ''));
    (ICONS[name] || '').split(' M').forEach(function (d, i) {
      if (!d) { return; }
      var p = document.createElementNS(ns, 'path');
      p.setAttribute('d', (i ? 'M' : '') + d);
      p.setAttribute('fill', 'none');
      p.setAttribute('stroke', 'currentColor');
      p.setAttribute('stroke-width', '1.9');
      p.setAttribute('stroke-linecap', 'round');
      p.setAttribute('stroke-linejoin', 'round');
      svg.appendChild(p);
    });
    return svg;
  }

  /* h('div', {class, text, on:{click}, ...attrs}, [children]) */
  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) { return; }
      if (k === 'text') { el.textContent = String(v); } else if (k === 'on') {
        Object.keys(v).forEach(function (ev) { el.addEventListener(ev, v[ev]); });
      } else if (k === 'value') { el.value = v; } else if (k === 'checked') { el.checked = !!v; } else {
        el.setAttribute(k, v === true ? '' : String(v));
      }
    });
    (kids || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) { return; }
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
  }

  function clone(o) { return o === undefined ? undefined : JSON.parse(JSON.stringify(o)); }
  function isObj(o) { return !!o && typeof o === 'object' && !Array.isArray(o); }
  function rid(prefix) {
    return (prefix || 's') + '_' + Math.random().toString(36).slice(2, 8);
  }
  function slugPart(s) { return String(s || 'section').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 24) || 'section'; }

  /* ================================================================= editor */
  function createStudio(root, cfg) {
    var I18N = isObj(cfg.i18n) ? cfg.i18n : {};
    var urls = isObj(cfg.urls) ? cfg.urls : {};
    var caps = Object.assign({
      sections_add: true, sections_remove: true, sections_reorder: true, blocks_edit: true,
      tokens: true, fonts: true, custom_block: false, ai_generate: false
    }, isObj(cfg.caps) ? cfg.caps : {});
    var limits = Object.assign({ max_sections: 25, max_blocks: 50 }, isObj(cfg.limits) ? cfg.limits : {});
    var store = isObj(cfg.store) ? cfg.store : {};
    var ui = isObj(cfg.ui) ? cfg.ui : {};
    var languages = Array.isArray(store.languages) ? store.languages : [];
    var defaultLang = String(store.default_language || (languages[0] && languages[0].code) || '');
    var cleanups = [];
    var destroyed = false;

    function t(s, rep) {
      var v = Object.prototype.hasOwnProperty.call(I18N, s) && I18N[s] ? String(I18N[s]) : String(s);
      if (rep) {
        Object.keys(rep).forEach(function (k) { v = v.split(':' + k).join(String(rep[k])); });
      }
      return v;
    }

    function q(sel, scope) { return (scope || root).querySelector(sel); }
    function qa(sel, scope) { return Array.prototype.slice.call((scope || root).querySelectorAll(sel)); }
    function on(el, ev, fn, opt) {
      if (!el) { return; }
      el.addEventListener(ev, fn, opt);
      cleanups.push(function () { el.removeEventListener(ev, fn, opt); });
    }

    // Static icon slots in the Blade markup.
    qa('[data-ts-icon]').forEach(function (slot) {
      if (!slot.firstChild) { slot.appendChild(svgIcon(slot.getAttribute('data-ts-icon'))); }
    });

    var live = q('[data-ts-live]');
    function announce(msg) {
      if (!live) { return; }
      live.textContent = '';
      setTimeout(function () { live.textContent = msg; }, 30);
    }
    function toast(msg, type) {
      if (window.dashToast) { window.dashToast(msg, { type: type || 'success' }); } else { announce(msg); }
    }
    /*
     * The dashboard's confirm/prompt modals are plain fixed overlays, so they sit
     * UNDER a showModal() sheet (top layer) and the sheet makes them inert. Close
     * our open sheets for the question, then bring them back.
     */
    function aside(ask) {
      var open = [els.sheetLeft, els.sheetRight, els.sheetGallery, els.sheetHistory].filter(function (d) { return d && d.open; });
      open.forEach(function (d) { d.__tsQuiet = true; d.close(); });
      return ask().then(function (r) {
        open.forEach(function (d) {
          d.__tsQuiet = false;
          if (!destroyed && d.isConnected && !d.open && d.__tsReopen !== false) { d.showModal(); }
          d.__tsReopen = true;
        });
        return r;
      });
    }
    function confirmBox(opts) {
      if (!window.dashConfirm) { return Promise.resolve(true); }
      return aside(function () { return window.dashConfirm(opts); });
    }
    function promptBox(opts) {
      if (!window.dashPrompt) { return Promise.resolve(null); }
      return aside(function () { return window.dashPrompt(opts); });
    }

    function api(method, url, body, opts) {
      var headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
      if (cfg.csrf) { headers['X-CSRF-TOKEN'] = cfg.csrf; }
      var payload;
      if (typeof FormData !== 'undefined' && body instanceof FormData) {
        payload = body;
      } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
      }
      return fetch(url, {
        method: method, headers: headers, body: payload, credentials: 'same-origin',
        keepalive: !!(opts && opts.keepalive)
      }).then(function (res) {
        if (window.LivePoll && typeof window.LivePoll.guard === 'function' && !window.LivePoll.guard(res)) {
          throw new Error('session');
        }
        var ct = res.headers.get('content-type') || '';
        var read = ct.indexOf('json') !== -1 ? res.json().catch(function () { return null; }) : res.text();
        return read.then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
      });
    }

    function withParams(url, params) {
      var u = new URL(url, location.origin);
      Object.keys(params).forEach(function (k) {
        if (params[k] === null || params[k] === undefined || params[k] === '') { u.searchParams.delete(k); } else { u.searchParams.set(k, params[k]); }
      });
      return u.origin === location.origin ? u.pathname + u.search + u.hash : u.toString();
    }
    function sectionUrl(sid) {
      return withParams(String(urls.section || '').replace('__SID__', encodeURIComponent(sid)), { draft: 1, lang: S.lang || null });
    }

    /* ---------------------------------------------------------------- state */
    var S = {
      layout: null,
      saved: null,          // JSON of the last layout the server accepted
      hasDraft: false,
      library: {},          // type → def
      libList: [],
      tokens: { fields: [], values: {} },
      tokenDirty: {},
      tokenErrors: {},
      contrast: [],
      sel: null,            // {sid, bid}
      lang: defaultLang,
      device: 'desktop',
      tab: 'sections',
      undo: [],
      redo: [],
      coalesce: null,
      errors: {},
      saveState: 'loading',
      saveTimer: null,
      inflight: null,
      queued: false,
      pendingReplace: {},
      pendingReload: false,
      frameReady: false,
      insertAfter: null,
      media: {},
      gallery: { q: '', group: '', mine: true },
      drag: null,
      formGen: 0,
      themeGen: 0,
      restyle: null,        // E2 preview {sid, css, prompt, created_at} (not saved yet)
      restyleBusy: false,
      restylePrompt: {}     // sid → prompt being typed
    };

    var els = {
      left: q('[data-ts-left]'),
      right: q('[data-ts-right]'),
      leftHome: q('[data-ts-left-home]'),
      rightHome: q('[data-ts-right-home]'),
      leftSheet: q('[data-ts-left-sheet]'),
      rightSheet: q('[data-ts-right-sheet]'),
      paneSections: q('[data-ts-pane="sections"]'),
      paneTheme: q('[data-ts-pane="theme"]'),
      frame: q('[data-ts-frame]'),
      stage: q('[data-ts-stage]'),
      save: q('[data-ts-save]'),
      undo: q('[data-ts-undo]'),
      redo: q('[data-ts-redo]'),
      publish: q('[data-ts-publish]'),
      gallery: q('[data-ts-gallery]'),
      history: q('[data-ts-history-list]'),
      file: q('[data-ts-file]'),
      edit: q('[data-ts-edit]'),
      editLabel: q('[data-ts-edit-label]'),
      sheetLeft: q('#ts-sheet-left'),
      sheetRight: q('#ts-sheet-right'),
      sheetGallery: q('#ts-gallery'),
      sheetHistory: q('#ts-history')
    };

    /* -------------------------------------------------------- layout helpers */
    function normalize(l) {
      l = isObj(l) ? l : {};
      if (!isObj(l.sections)) { l.sections = {}; }
      if (!Array.isArray(l.order)) { l.order = Object.keys(l.sections); }
      var seen = {};
      l.order = l.order.filter(function (sid) {
        if (seen[sid] || !l.sections[sid]) { return false; }
        seen[sid] = 1;
        return true;
      });
      Object.keys(l.sections).forEach(function (sid) {
        var s = l.sections[sid];
        if (!isObj(s)) { delete l.sections[sid]; return; }
        if (!isObj(s.settings)) { s.settings = {}; }
        if (s.blocks !== undefined || s.block_order !== undefined) {
          if (!isObj(s.blocks)) { s.blocks = {}; }
          if (!Array.isArray(s.block_order)) { s.block_order = Object.keys(s.blocks); }
          s.block_order = s.block_order.filter(function (b) { return !!s.blocks[b]; });
          Object.keys(s.blocks).forEach(function (b) {
            if (!isObj(s.blocks[b])) { delete s.blocks[b]; return; }
            if (!isObj(s.blocks[b].settings)) { s.blocks[b].settings = {}; }
            if (s.block_order.indexOf(b) === -1) { s.block_order.push(b); }
          });
        }
      });
      if (!isObj(l.theme)) { l.theme = {}; }
      if (!isObj(l.i18n)) { l.i18n = {}; }
      if (l.schema === undefined) { l.schema = 1; }
      return l;
    }
    function sec(sid, l) { return (l || S.layout).sections[sid] || null; }
    function defOf(type) { return S.library[type] || null; }
    function sectionName(sid) {
      var s = sec(sid);
      if (!s) { return ''; }
      var d = defOf(s.type);
      return d && d.name ? String(d.name) : (s.type || t('Unknown section'));
    }
    function blockDef(sectionDef, type) {
      return sectionDef && Array.isArray(sectionDef.blocks)
        ? sectionDef.blocks.filter(function (b) { return b && b.type === type; })[0] || null
        : null;
    }
    function settingDefs(def) { return def && Array.isArray(def.settings) ? def.settings.filter(isObj) : []; }
    function defaultsFor(defs) {
      var out = {};
      settingDefs({ settings: defs }).forEach(function (d) {
        if (d.id && d.default !== undefined) { out[d.id] = clone(d.default); }
      });
      return out;
    }
    function countType(type, l) {
      l = l || S.layout;
      return l.order.filter(function (sid) { return l.sections[sid] && l.sections[sid].type === type; }).length;
    }
    function i18nKey(sid, bid, key) { return bid ? sid + '.' + bid + '.' + key : sid + '.' + key; }
    function readValue(sid, bid, d) {
      var s = sec(sid);
      if (!s) { return undefined; }
      var holder = bid ? (s.blocks && s.blocks[bid]) : s;
      if (!holder) { return undefined; }
      if (S.lang && S.lang !== defaultLang && TEXT_TYPES[d.type]) {
        var tr = S.layout.i18n[S.lang];
        var k = i18nKey(sid, bid, d.id);
        return tr && Object.prototype.hasOwnProperty.call(tr, k) ? tr[k] : '';
      }
      if (Object.prototype.hasOwnProperty.call(holder.settings, d.id)) { return holder.settings[d.id]; }
      return d.default;
    }
    function defaultLangValue(sid, bid, d) {
      var s = sec(sid);
      var holder = s && (bid ? (s.blocks && s.blocks[bid]) : s);
      if (!holder) { return ''; }
      return Object.prototype.hasOwnProperty.call(holder.settings, d.id) ? holder.settings[d.id] : (d.default || '');
    }

    /* ------------------------------------------------------------- commits */
    function commit(mutate, o) {
      o = o || {};
      if (!S.layout) { return false; }
      var before = JSON.stringify(S.layout);
      var next = clone(S.layout);
      if (mutate(next) === false) { return false; }
      normalize(next);
      if (JSON.stringify(next) === before) { return false; }
      var now = Date.now();
      var merge = o.coalesce && S.coalesce && S.coalesce.key === o.coalesce && now - S.coalesce.at < 1500;
      if (!merge) {
        S.undo.push(before);
        if (S.undo.length > UNDO_MAX) { S.undo.shift(); }
      }
      S.coalesce = o.coalesce ? { key: o.coalesce, at: now } : null;
      S.redo = [];
      S.layout = next;
      if (o.reload) { S.pendingReload = true; }
      (o.replace || []).forEach(function (sid) { S.pendingReplace[sid] = 1; });
      if (o.keepForm) {
        renderSections();
        renderToolbar();
      } else {
        renderAll();
      }
      scheduleSave();
      pushCss();
      return true;
    }

    function restore(json, msg) {
      S.layout = normalize(JSON.parse(json));
      S.coalesce = null;
      S.pendingReload = true;
      if (S.sel && !sec(S.sel.sid)) { S.sel = null; }
      if (S.sel && S.sel.bid && !(sec(S.sel.sid).blocks || {})[S.sel.bid]) { S.sel.bid = null; }
      renderAll();
      scheduleSave();
      pushCss();
      if (msg) { announce(msg); }
    }
    function undo() {
      if (!S.undo.length) { return; }
      S.redo.push(JSON.stringify(S.layout));
      restore(S.undo.pop(), t('Undone.'));
    }
    function redo() {
      if (!S.redo.length) { return; }
      S.undo.push(JSON.stringify(S.layout));
      restore(S.redo.pop(), t('Redone.'));
    }

    /* ---------------------------------------------------------------- save */
    function setSave(state) {
      S.saveState = state;
      renderToolbar();
    }
    function hasUnsaved() {
      return !!S.layout && (S.saveTimer !== null || !!S.inflight || JSON.stringify(S.layout) !== S.saved || Object.keys(S.tokenDirty).length > 0);
    }
    function scheduleSave() {
      setSave('dirty');
      clearTimeout(S.saveTimer);
      S.saveTimer = setTimeout(function () { S.saveTimer = null; save(); }, 800);
    }
    function mapErrors(errors) {
      var out = {};
      if (!isObj(errors)) { return out; }
      Object.keys(errors).forEach(function (k) {
        var v = errors[k];
        out[k] = Array.isArray(v) ? String(v[0]) : String(v);
      });
      return out;
    }
    function save(opts) {
      clearTimeout(S.saveTimer);
      S.saveTimer = null;
      if (!S.layout || !urls.draft) { return Promise.resolve(); }
      if (S.inflight) {
        S.queued = true;
        return S.inflight;
      }
      var sent = JSON.stringify(S.layout);
      if (sent === S.saved) {
        var rep0 = S.pendingReplace;
        var rel0 = S.pendingReload;
        S.pendingReplace = {};
        S.pendingReload = false;
        setSave(Object.keys(S.tokenDirty).length ? 'dirty' : 'saved');
        flushPreview(rep0, rel0);
        return Promise.resolve();
      }
      var replace = S.pendingReplace;
      var reload = S.pendingReload;
      S.pendingReplace = {};
      S.pendingReload = false;
      setSave('saving');
      S.inflight = api('PUT', urls.draft, { layout: JSON.parse(sent) }, opts).then(function (res) {
        if (destroyed) { return; }
        if (res.ok && isObj(res.data) && isObj(res.data.layout)) {
          var server = JSON.stringify(normalize(res.data.layout));
          S.saved = server;
          S.hasDraft = res.data.has_draft !== false;
          var hadErrors = Object.keys(S.errors).length > 0;
          S.errors = {};
          var adopt = JSON.stringify(S.layout) === sent && server !== sent;
          if (adopt) { S.layout = JSON.parse(server); }
          if (Array.isArray(res.data.rejected) && res.data.rejected.length) {
            toast(t('Some changes are not allowed for your account and were undone.'), 'warning');
          }
          setSave(JSON.stringify(S.layout) === S.saved ? 'saved' : 'dirty');
          if (adopt || hadErrors) {
            // Don't rebuild the form under the owner's caret.
            if (els.right && els.right.contains(document.activeElement)) { renderSections(); renderToolbar(); } else { renderAll(); }
          }
          flushPreview(replace, reload || adopt);
        } else if (res.status === 422) {
          S.errors = mapErrors(res.data && res.data.errors);
          var msg = (res.data && res.data.message) || t('Some changes could not be saved.');
          if (S.saved) {
            S.layout = normalize(JSON.parse(S.saved));
            if (S.sel && !sec(S.sel.sid)) { S.sel = null; }
          }
          toast(msg, 'error');
          setSave('error');
          renderAll();
        } else {
          Object.keys(replace).forEach(function (sid) { S.pendingReplace[sid] = 1; });
          S.pendingReload = S.pendingReload || reload;
          setSave('error');
          toast(t('Could not save. Check your connection and try again.'), 'error');
        }
      }).catch(function () {
        if (destroyed) { return; }
        Object.keys(replace).forEach(function (sid) { S.pendingReplace[sid] = 1; });
        S.pendingReload = S.pendingReload || reload;
        setSave('error');
      }).then(function () {
        S.inflight = null;
        if (S.queued && !destroyed) {
          S.queued = false;
          return save();
        }
      });
      return S.inflight;
    }
    function flushAll(opts) {
      return Promise.all([save(opts), saveTokens(opts)]);
    }

    /* ------------------------------------------------------------- canvas */
    function postFrame(msg) {
      var w = els.frame && els.frame.contentWindow;
      if (!w || !S.frameReady) { return; }
      try { w.postMessage(msg, location.origin); } catch (e) { /* frame navigated away */ }
    }
    function previewUrl(studio) {
      if (!urls.preview) { return 'about:blank'; }
      var p = { preview_lang: S.lang && S.lang !== defaultLang ? S.lang : null };
      if (!studio) { p.studio = null; }
      return withParams(urls.preview, p);
    }
    function loadFrame() {
      if (!els.frame) { return; }
      S.frameReady = false;
      els.frame.src = previewUrl(true);
    }
    function frameLabels() {
      var types = {};
      S.libList.forEach(function (d) { types[d.type] = d.name || d.type; });
      return {
        types: types,
        section: t('Section'),
        hidden: t('Hidden'),
        'add-after': t('Add section below'),
        duplicate: t('Duplicate'),
        remove: t('Remove'),
        'move-up': t('Move up'),
        'move-down': t('Move down'),
        edit: t('Edit section')
      };
    }
    /* E2: every visible section's AI style (or the unsaved preview of one) as
       ONE stylesheet for the canvas (st:css). The CSS was sanitised and scoped
       under #mb-section-<sid> by the server (restyle + every draft write). */
    function variantCss() {
      if (!S.layout) { return ''; }
      return S.layout.order.map(function (sid) {
        var s = sec(sid);
        if (!s || s.disabled) { return ''; }
        if (S.restyle && S.restyle.sid === sid) { return S.restyle.css; }
        return isObj(s.style) && typeof s.style.css === 'string' ? s.style.css : '';
      }).join('');
    }
    function pushCss() {
      postFrame({ type: 'st:css', css: variantCss() });
    }
    function flushPreview(replace, reload) {
      if (!els.frame) { return; }
      if (reload || !S.frameReady) {
        if (S.frameReady) { postFrame({ type: 'st:reload' }); S.frameReady = false; } else if (reload) { loadFrame(); }
        return;
      }
      Object.keys(replace || {}).forEach(function (sid) {
        var s = sec(sid);
        if (!s || s.disabled || !urls.section) { return; }
        api('GET', sectionUrl(sid)).then(function (res) {
          if (destroyed) { return; }
          if (res.ok && typeof res.data === 'string' && res.data.indexOf('data-studio-section') !== -1) {
            postFrame({ type: 'st:replace', sid: sid, html: res.data });
          } else {
            postFrame({ type: 'st:reload' });
            S.frameReady = false;
          }
        }).catch(function () { postFrame({ type: 'st:reload' }); S.frameReady = false; });
      });
    }
    function onMessage(e) {
      if (!els.frame || e.source !== els.frame.contentWindow || e.origin !== location.origin) { return; }
      var m = e.data;
      if (!m || typeof m.type !== 'string' || m.type.indexOf('st:') !== 0) { return; }
      switch (m.type) {
        case 'st:ready':
          S.frameReady = true;
          postFrame({ type: 'st:init', caps: caps, labels: frameLabels() });
          pushCss();
          if (S.sel) { postFrame({ type: 'st:select', sid: S.sel.sid }); }
          break;
        case 'st:select':
          if (typeof m.sid === 'string' && sec(m.sid)) {
            select(m.sid, typeof m.blockId === 'string' ? m.blockId : null, { fromFrame: true });
          } else if (!m.sid) {
            select(null, null, { fromFrame: true });
          }
          break;
        case 'st:hover':
          qa('.ts-row').forEach(function (r) { r.classList.toggle('is-hover', r.getAttribute('data-sid') === m.sid); });
          break;
        case 'st:action':
          if (typeof m.sid === 'string' && sec(m.sid)) { sectionAction(m.action, m.sid, null); }
          break;
        case 'st:text':
          if (typeof m.sid === 'string' && typeof m.key === 'string' && typeof m.value === 'string') { applyText(m.sid, m.key, m.value); }
          break;
      }
    }
    function applyText(sid, key, value) {
      var s = sec(sid);
      if (!s) { return; }
      var def = defOf(s.type);
      var bid = null;
      var k = key;
      if (key.indexOf('.') !== -1 && s.blocks) {
        var parts = key.split('.');
        if (s.blocks[parts[0]]) { bid = parts[0]; k = parts.slice(1).join('.'); }
      }
      var defs = bid ? settingDefs(blockDef(def, s.blocks[bid].type)) : settingDefs(def);
      var d = defs.filter(function (x) { return x.id === k; })[0];
      if (!d || !TEXT_TYPES[d.type]) { return; }
      if (bid && !caps.blocks_edit) { return; }
      var v = value.slice(0, 5000);
      if (d.type === 'richtext') {
        v = v.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }).replace(/\n/g, '<br>');
      }
      writeSetting(sid, bid, d, v, { replace: [sid] });
      if (S.sel && S.sel.sid === sid) { renderRight(); }
    }

    /* ------------------------------------------------------------ actions */
    function select(sid, bid, o) {
      o = o || {};
      if (sid && !sec(sid)) { sid = null; }
      var s = sid ? sec(sid) : null;
      S.sel = sid ? { sid: sid, bid: bid && s.blocks && s.blocks[bid] ? bid : null } : null;
      renderSections();
      renderRight();
      renderToolbar();
      if (!o.fromFrame && sid) {
        postFrame({ type: 'st:select', sid: sid });
        postFrame({ type: 'st:scrollTo', sid: sid });
      }
      if (o.openSheet && isMobile()) {
        closeSheet(els.sheetLeft);
        openSheet(els.sheetRight, o.opener);
      }
    }

    function canAddType(type, l) {
      var d = defOf(type);
      if (!d) { return { ok: false, why: t('This section type is no longer available.') }; }
      l = l || S.layout;
      if (!caps.sections_add) { return { ok: false, why: t('Sections are locked by your administrator.') }; }
      if (type === 'custom-block' && !caps.custom_block) { return { ok: false, why: t('Sections are locked by your administrator.') }; }
      if (l.order.length >= limits.max_sections) { return { ok: false, why: t('You can have up to :max sections.', { max: limits.max_sections }) }; }
      var max = parseInt(d.max_per_page, 10);
      if (max > 0 && countType(type, l) >= max) {
        return { ok: false, why: max === 1 ? t('This section can be used once per page.') : t('You can add up to :max of these.', { max: max }) };
      }
      return { ok: true };
    }

    function newSid(type, l) {
      var base = slugPart(type);
      var sid;
      do { sid = rid(base); } while (l.sections[sid]);
      return sid;
    }

    function addSection(type, after) {
      var check = canAddType(type);
      if (!check.ok) { toast(check.why, 'warning'); return; }
      var d = defOf(type);
      var sidOut = null;
      commit(function (l) {
        var sid = newSid(type, l);
        var preset = Array.isArray(d.presets) && isObj(d.presets[0]) ? d.presets[0] : {};
        var entry = { type: type, settings: Object.assign(defaultsFor(d.settings), isObj(preset.settings) ? clone(preset.settings) : {}) };
        var pblocks = Array.isArray(preset.blocks) ? preset.blocks : [];
        if ((Array.isArray(d.blocks) && d.blocks.length) || pblocks.length) {
          entry.blocks = {};
          entry.block_order = [];
          pblocks.slice(0, limits.max_blocks).forEach(function (pb) {
            if (!isObj(pb) || !pb.type) { return; }
            var bd = blockDef(d, pb.type);
            if (!bd) { return; }
            var bid = rid('b');
            entry.blocks[bid] = { type: pb.type, settings: Object.assign(defaultsFor(bd.settings), isObj(pb.settings) ? clone(pb.settings) : {}) };
            entry.block_order.push(bid);
          });
        }
        l.sections[sid] = entry;
        var at = after ? l.order.indexOf(after) : -1;
        if (at === -1) { l.order.push(sid); } else { l.order.splice(at + 1, 0, sid); }
        sidOut = sid;
      }, { reload: true });
      if (sidOut) {
        S.sel = { sid: sidOut, bid: null };
        renderAll();
        announce(t('Added :name.', { name: d.name || type }));
      }
    }

    function moveSection(sid, delta) {
      if (!caps.sections_reorder) { return; }
      var name = sectionName(sid);
      var pos = null;
      commit(function (l) {
        var i = l.order.indexOf(sid);
        var j = i + delta;
        if (i === -1 || j < 0 || j >= l.order.length) { return false; }
        l.order.splice(i, 1);
        l.order.splice(j, 0, sid);
        pos = j + 1;
      }, { reload: true });
      if (pos) { announce(t('Moved :name to position :n.', { name: name, n: pos })); }
    }
    function moveSectionTo(sid, target, before) {
      if (!caps.sections_reorder || sid === target) { return; }
      commit(function (l) {
        var i = l.order.indexOf(sid);
        if (i === -1 || l.order.indexOf(target) === -1) { return false; }
        l.order.splice(i, 1);
        var j = l.order.indexOf(target) + (before ? 0 : 1);
        l.order.splice(j, 0, sid);
      }, { reload: true });
      announce(t('Moved :name to position :n.', { name: sectionName(sid), n: S.layout.order.indexOf(sid) + 1 }));
    }
    function duplicateSection(sid) {
      var s = sec(sid);
      if (!s) { return; }
      var check = canAddType(s.type);
      if (!check.ok) { toast(check.why, 'warning'); return; }
      var copyId = null;
      commit(function (l) {
        var nid = newSid(s.type, l);
        l.sections[nid] = clone(l.sections[sid]);
        l.order.splice(l.order.indexOf(sid) + 1, 0, nid);
        Object.keys(l.i18n).forEach(function (lang) {
          var tr = l.i18n[lang];
          if (!isObj(tr)) { return; }
          Object.keys(tr).forEach(function (k) {
            if (k.indexOf(sid + '.') === 0) { tr[nid + k.slice(sid.length)] = tr[k]; }
          });
        });
        copyId = nid;
      }, { reload: true });
      if (copyId) { select(copyId); }
    }
    function removeSection(sid) {
      if (!caps.sections_remove || !sec(sid)) { return; }
      var name = sectionName(sid);
      confirmBox({ title: t('Remove this section?'), body: t('The section and its content leave the draft. You can undo this.'), confirmLabel: t('Remove'), danger: true }).then(function (ok) {
        if (!ok || destroyed) { return; }
        commit(function (l) {
          delete l.sections[sid];
          l.order = l.order.filter(function (x) { return x !== sid; });
          Object.keys(l.i18n).forEach(function (lang) {
            var tr = l.i18n[lang];
            if (!isObj(tr)) { return; }
            Object.keys(tr).forEach(function (k) { if (k.indexOf(sid + '.') === 0) { delete tr[k]; } });
          });
        }, { reload: true });
        if (S.sel && S.sel.sid === sid) { S.sel = null; renderAll(); }
        announce(t('Removed :name.', { name: name }));
      });
    }
    function toggleHidden(sid) {
      commit(function (l) {
        var s = l.sections[sid];
        if (!s) { return false; }
        if (s.disabled) { delete s.disabled; } else { s.disabled = true; }
      }, { reload: true });
    }
    function sectionAction(action, sid, opener) {
      switch (action) {
        case 'add-after': openGallery(sid, opener); break;
        case 'duplicate': duplicateSection(sid); break;
        case 'remove': removeSection(sid); break;
        case 'move-up': moveSection(sid, -1); break;
        case 'move-down': moveSection(sid, 1); break;
        case 'hide': toggleHidden(sid); break;
        case 'select': select(sid, null, { openSheet: true, opener: opener }); break;
      }
    }

    /* Blocks */
    function blockCount(s) { return s && s.block_order ? s.block_order.length : 0; }
    function canAddBlock(sid, btype) {
      var s = sec(sid);
      var bd = blockDef(defOf(s.type), btype);
      if (!caps.blocks_edit || !bd) { return { ok: false, why: '' }; }
      if (blockCount(s) >= limits.max_blocks) { return { ok: false, why: t('You can have up to :max blocks in a section.', { max: limits.max_blocks }) }; }
      var lim = parseInt(bd.limit, 10);
      if (lim > 0) {
        var n = (s.block_order || []).filter(function (b) { return s.blocks[b].type === btype; }).length;
        if (n >= lim) { return { ok: false, why: t('You can add up to :max of these.', { max: lim }) }; }
      }
      return { ok: true };
    }
    function addBlock(sid, btype) {
      var check = canAddBlock(sid, btype);
      if (!check.ok) { if (check.why) { toast(check.why, 'warning'); } return; }
      var bd = blockDef(defOf(sec(sid).type), btype);
      var bidOut = null;
      commit(function (l) {
        var s = l.sections[sid];
        if (!isObj(s.blocks)) { s.blocks = {}; }
        if (!Array.isArray(s.block_order)) { s.block_order = []; }
        var bid;
        do { bid = rid('b'); } while (s.blocks[bid]);
        s.blocks[bid] = { type: btype, settings: defaultsFor(bd.settings) };
        s.block_order.push(bid);
        bidOut = bid;
      }, { replace: [sid] });
      if (bidOut) { select(sid, bidOut); }
    }
    function moveBlock(sid, bid, delta) {
      if (!caps.blocks_edit) { return; }
      commit(function (l) {
        var o = l.sections[sid].block_order;
        var i = o.indexOf(bid);
        var j = i + delta;
        if (i === -1 || j < 0 || j >= o.length) { return false; }
        o.splice(i, 1);
        o.splice(j, 0, bid);
      }, { replace: [sid] });
    }
    function moveBlockTo(sid, bid, target, before) {
      if (!caps.blocks_edit || bid === target) { return; }
      commit(function (l) {
        var o = l.sections[sid].block_order;
        var i = o.indexOf(bid);
        if (i === -1 || o.indexOf(target) === -1) { return false; }
        o.splice(i, 1);
        o.splice(o.indexOf(target) + (before ? 0 : 1), 0, bid);
      }, { replace: [sid] });
    }
    function removeBlock(sid, bid) {
      if (!caps.blocks_edit) { return; }
      confirmBox({ title: t('Remove this block?'), body: t('The section and its content leave the draft. You can undo this.'), confirmLabel: t('Remove'), danger: true }).then(function (ok) {
        if (!ok || destroyed) { return; }
        commit(function (l) {
          var s = l.sections[sid];
          delete s.blocks[bid];
          s.block_order = s.block_order.filter(function (b) { return b !== bid; });
          Object.keys(l.i18n).forEach(function (lang) {
            var tr = l.i18n[lang];
            if (!isObj(tr)) { return; }
            Object.keys(tr).forEach(function (k) { if (k.indexOf(sid + '.' + bid + '.') === 0) { delete tr[k]; } });
          });
        }, { replace: [sid] });
        if (S.sel && S.sel.bid === bid) { S.sel.bid = null; }
        renderRight();
      });
    }

    function writeSetting(sid, bid, d, value, o) {
      o = o || {};
      return commit(function (l) {
        var s = l.sections[sid];
        if (!s) { return false; }
        var holder = bid ? s.blocks && s.blocks[bid] : s;
        if (!holder) { return false; }
        if (S.lang && S.lang !== defaultLang && TEXT_TYPES[d.type]) {
          if (!isObj(l.i18n[S.lang])) { l.i18n[S.lang] = {}; }
          var k = i18nKey(sid, bid, d.id);
          if (value === '' || value === null) { delete l.i18n[S.lang][k]; } else { l.i18n[S.lang][k] = value; }
          if (!Object.keys(l.i18n[S.lang]).length) { delete l.i18n[S.lang]; }
        } else {
          holder.settings[d.id] = value;
        }
      }, { replace: o.replace || [sid], coalesce: o.coalesce, keepForm: o.keepForm });
    }

    /* ------------------------------------------------------------- fields */
    function optionList(d) {
      var opts = Array.isArray(d.options) ? d.options : [];
      var labels = isObj(d.option_labels) ? d.option_labels : {};
      return opts.map(function (o) {
        if (isObj(o)) { return { value: String(o.value), label: String(o.label || o.value) }; }
        return { value: String(o), label: String(labels[o] || o) };
      });
    }
    function validUrl(v) {
      return v === '' || /^(https?:\/\/[^\s]+|\/[^\s]*|#[^\s]*|mailto:[^\s]+|tel:[+0-9()\s-]+)$/i.test(v);
    }
    var uid = 0;

    /*
     * One field. ctx = {value, placeholder, error, onChange(value, final), disabled}.
     * onChange(v, false) while typing (coalesced), onChange(v, true) on commit.
     */
    function renderField(d, ctx) {
      var id = 'ts-f-' + (++uid);
      var help = d.info || d.help || '';
      var err = ctx.error || '';
      var wrap = h('div', { class: 'ui-field ts-field' + (err ? ' ui-field--error' : ''), 'data-setting': d.id });
      var describedBy = (err || help) ? id + '-help' : null;
      var label = h('label', { class: 'ui-field__label', for: id, text: d.label || d.id });
      var control = h('div', { class: 'ui-field__control' });
      var value = ctx.value;
      var input;
      function a11y(el) {
        el.id = id;
        if (describedBy) { el.setAttribute('aria-describedby', describedBy); }
        if (err) { el.setAttribute('aria-invalid', 'true'); }
        if (ctx.disabled) { el.disabled = true; }
        return el;
      }

      switch (d.type) {
        case 'header':
          return h('h4', { class: 'ui-overline ts-field-header', text: d.content || d.label || '' });
        case 'paragraph':
          return h('p', { class: 'ts-field-note', text: d.content || d.label || '' });
        case 'checkbox':
        case 'toggle': {
          var sw = a11y(h('input', { type: 'checkbox', role: 'switch', class: 'ui-switch__input', checked: !!value && value !== '0' }));
          sw.addEventListener('change', function () { ctx.onChange(sw.checked, true); });
          var row = h('label', { class: 'ui-switch-row ts-switch-row', for: id }, [
            h('span', { class: 'ui-switch-row__text' }, [h('span', { class: 'ui-switch-row__label', text: d.label || d.id })]),
            h('span', { class: 'ui-switch' }, [sw, h('span', { class: 'ui-switch__track', 'aria-hidden': 'true' }, [h('span', { class: 'ui-switch__knob' })])])
          ]);
          wrap.className = 'ts-field ts-field--switch' + (err ? ' ui-field--error' : '');
          wrap.appendChild(row);
          if (err || help) { wrap.appendChild(h('span', { class: 'ui-field__help', id: id + '-help', 'data-help': help || null, text: err || help })); }
          return wrap;
        }
        case 'select':
        case 'font_pair': {
          var opts = optionList(d);
          if (d.type === 'font_pair' && !opts.length) {
            input = a11y(h('input', { type: 'text', value: value == null ? '' : String(value) }));
            input.addEventListener('change', function () { ctx.onChange(input.value.trim(), true); });
            control.appendChild(input);
            break;
          }
          var short = opts.reduce(function (n, o) { return n + o.label.length; }, 0) <= 24;
          if (opts.length && opts.length <= 3 && short && d.type === 'select') {
            var fs = h('fieldset', { class: 'ui-seg ts-seg', 'aria-labelledby': id + '-lbl' });
            opts.forEach(function (o) {
              var r = h('input', { type: 'radio', class: 'ui-seg__input', name: id, value: o.value, checked: String(value) === o.value });
              if (ctx.disabled) { r.disabled = true; }
              r.addEventListener('change', function () { if (r.checked) { ctx.onChange(o.value, true); } });
              fs.appendChild(h('label', { class: 'ui-seg__opt' }, [r, h('span', { class: 'ui-seg__label', text: o.label })]));
            });
            wrap.appendChild(h('span', { class: 'ui-field__label', id: id + '-lbl', text: d.label || d.id }));
            wrap.appendChild(fs);
            if (err || help) { wrap.appendChild(h('span', { class: 'ui-field__help', id: id + '-help', 'data-help': help || null, text: err || help })); }
            return wrap;
          }
          input = a11y(h('select', {}));
          var found = false;
          opts.forEach(function (o) {
            var op = h('option', { value: o.value, text: o.label });
            if (String(value) === o.value) { op.selected = true; found = true; }
            input.appendChild(op);
          });
          if (!found && value !== undefined && value !== null && value !== '') {
            input.insertBefore(h('option', { value: String(value), text: String(value), selected: true }), input.firstChild);
          }
          input.addEventListener('change', function () { ctx.onChange(input.value, true); });
          control.appendChild(input);
          control.appendChild(h('span', { class: 'ui-field__chevron', 'aria-hidden': 'true' }, [svgIcon('chevdown', 16)]));
          break;
        }
        case 'textarea':
          input = a11y(h('textarea', { rows: 3, placeholder: ctx.placeholder || null }));
          input.value = value == null ? '' : String(value);
          input.addEventListener('input', function () { ctx.onChange(input.value, false); });
          input.addEventListener('change', function () { ctx.onChange(input.value, true); });
          control.appendChild(input);
          break;
        case 'richtext':
          return renderRichtext(d, ctx, wrap, label, id, help, err);
        case 'range':
        case 'number': {
          var min = d.min !== undefined ? Number(d.min) : null;
          var max = d.max !== undefined ? Number(d.max) : null;
          input = a11y(h('input', {
            type: d.type === 'range' ? 'range' : 'number', min: min, max: max, step: d.step !== undefined ? d.step : null,
            value: value == null || value === '' ? (d.type === 'range' ? (min || 0) : '') : String(value)
          }));
          var out = d.type === 'range' ? h('output', { class: 'ts-range-out', for: id, text: String(input.value) + (d.unit || '') }) : null;
          var coerce = function () {
            if (input.value === '') { return ''; }
            var n = Number(input.value);
            return isFinite(n) ? n : input.value;
          };
          var bad = function (n) {
            if (n === '') { return ''; }
            if (typeof n !== 'number') { return t('Must be a number.'); }
            if (min !== null && n < min) { return t('Must be at least :min.', { min: min }); }
            if (max !== null && n > max) { return t('Must be at most :max.', { max: max }); }
            return '';
          };
          input.addEventListener('input', function () {
            if (out) { out.textContent = input.value + (d.unit || ''); }
            var v = coerce();
            if (!bad(v)) { ctx.onChange(v, false); }
          });
          input.addEventListener('change', function () {
            var v = coerce();
            var why = bad(v);
            setFieldError(wrap, why);
            if (!why) { ctx.onChange(v, true); }
          });
          control.appendChild(input);
          if (out) { control.appendChild(out); }
          if (d.unit && d.type === 'number') { control.appendChild(h('span', { class: 'ts-unit', text: d.unit })); }
          break;
        }
        case 'color': {
          var hex = /^#[0-9a-f]{6}$/i.test(String(value || '')) ? String(value) : '#000000';
          var picker = h('input', { type: 'color', class: 'ts-color', value: hex, 'aria-label': d.label || d.id });
          input = a11y(h('input', { type: 'text', value: value == null ? '' : String(value), maxlength: 7, spellcheck: 'false', dir: 'ltr', class: 'ts-hex' }));
          picker.addEventListener('input', function () { input.value = picker.value; ctx.onChange(picker.value, false); });
          picker.addEventListener('change', function () { ctx.onChange(picker.value, true); });
          input.addEventListener('change', function () {
            var v = input.value.trim();
            if (v !== '' && !/^#[0-9a-f]{6}$/i.test(v)) { setFieldError(wrap, '#RRGGBB'); return; }
            setFieldError(wrap, '');
            if (v) { picker.value = v; }
            ctx.onChange(v, true);
          });
          if (ctx.disabled) { picker.disabled = true; }
          control.appendChild(picker);
          control.appendChild(input);
          break;
        }
        case 'color_token': {
          var tokens = Array.isArray(d.options) && d.options.length ? d.options.map(String) : COLOR_TOKENS;
          var names = { primary: t('Primary'), background: t('Background'), surface: t('Surface'), text: t('Text'), accent: t('Accent') };
          var group = h('div', { class: 'ts-swatches', role: 'radiogroup', 'aria-labelledby': id + '-lbl' });
          tokens.forEach(function (tok) {
            var isOn = String(value) === tok;
            var b = h('button', { type: 'button', role: 'radio', class: 'ui-chip ts-swatch', 'aria-checked': isOn ? 'true' : 'false', title: names[tok] || tok }, [
              h('span', { class: 'ts-swatch__dot', style: '--sw:' + tokenColor(tok) }),
              h('span', { class: 'ts-swatch__name', text: names[tok] || tok })
            ]);
            if (ctx.disabled) { b.disabled = true; }
            b.addEventListener('click', function () { ctx.onChange(tok, true); });
            group.appendChild(b);
          });
          wrap.appendChild(h('span', { class: 'ui-field__label', id: id + '-lbl', text: d.label || d.id }));
          wrap.appendChild(group);
          if (err || help) { wrap.appendChild(h('span', { class: 'ui-field__help', id: id + '-help', 'data-help': help || null, text: err || help })); }
          return wrap;
        }
        case 'image_picker':
          return renderImage(d, ctx, wrap, id, help, err);
        case 'url':
          input = a11y(h('input', { type: 'text', inputmode: 'url', dir: 'ltr', value: value == null ? '' : String(value), placeholder: ctx.placeholder || 'https://', spellcheck: 'false' }));
          input.addEventListener('change', function () {
            var v = input.value.trim();
            if (!validUrl(v)) { setFieldError(wrap, t('Use a full address (https://…), a page path (/…), a section (#…), an email (mailto:…) or a phone (tel:…).')); return; }
            setFieldError(wrap, '');
            ctx.onChange(v, true);
          });
          control.appendChild(input);
          break;
        case 'category':
        case 'branch': {
          var rows = (ui.pickers && Array.isArray(ui.pickers[d.type])) ? ui.pickers[d.type] : [];
          input = a11y(h('select', {}));
          input.appendChild(h('option', { value: '', text: t('Choose…') }));
          rows.forEach(function (r) {
            input.appendChild(h('option', { value: String(r.id), text: String(r.name), selected: String(value) === String(r.id) }));
          });
          input.addEventListener('change', function () { ctx.onChange(input.value === '' ? '' : Number(input.value), true); });
          control.appendChild(input);
          control.appendChild(h('span', { class: 'ui-field__chevron', 'aria-hidden': 'true' }, [svgIcon('chevdown', 16)]));
          break;
        }
        case 'item_list':
          return renderItemList(d, ctx, wrap, id, help, err);
        default:
          input = a11y(h('input', { type: 'text', value: value == null ? '' : String(value), placeholder: ctx.placeholder || null }));
          input.addEventListener('input', function () { ctx.onChange(input.value, false); });
          input.addEventListener('change', function () { ctx.onChange(input.value, true); });
          control.appendChild(input);
      }
      wrap.appendChild(label);
      wrap.appendChild(control);
      if (err || help) { wrap.appendChild(h('span', { class: 'ui-field__help', id: id + '-help', 'data-help': help || null, text: err || help })); }
      return wrap;
    }
    function setFieldError(wrap, msg) {
      var help = wrap.querySelector('.ui-field__help');
      wrap.classList.toggle('ui-field--error', !!msg);
      if (!help && msg) {
        help = h('span', { class: 'ui-field__help', role: 'alert' });
        wrap.appendChild(help);
      }
      if (help) { help.textContent = msg || help.getAttribute('data-help') || ''; }
    }
    function tokenColor(tok) {
      var v = S.tokens.values || {};
      var c = v['colors.' + tok] || v[tok] || v['color_' + tok];
      return /^#[0-9a-f]{3,8}$/i.test(String(c || '')) ? c : 'var(--ui-ink-3)';
    }

    function renderRichtext(d, ctx, wrap, label, id, help, err) {
      var ed = h('div', {
        class: 'ts-rich', id: id, contenteditable: ctx.disabled ? 'false' : 'true', role: 'textbox', 'aria-multiline': 'true',
        'aria-labelledby': id + '-lbl', 'data-placeholder': ctx.placeholder || ''
      });
      if (err || help) { ed.setAttribute('aria-describedby', id + '-help'); }
      ed.innerHTML = sanitizeRich(ctx.value == null ? '' : String(ctx.value));
      var emit = function (final) { ctx.onChange(sanitizeRich(ed.innerHTML), final); };
      ed.addEventListener('input', function () { emit(false); });
      ed.addEventListener('blur', function () { emit(true); });
      ed.addEventListener('paste', function (e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, text);
      });
      var bar = h('div', { class: 'ts-rich__bar', role: 'toolbar', 'aria-label': d.label || d.id });
      [['bold', 'Bold'], ['italic', 'Italic'], ['link', 'Link']].forEach(function (b) {
        var btn = h('button', { type: 'button', class: 'ui-icon-btn ui-icon-btn--ghost ts-mini', 'aria-label': t(b[1]), title: t(b[1]) }, [svgIcon(b[0], 16)]);
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function () {
          ed.focus();
          if (b[0] !== 'link') {
            document.execCommand(b[0], false);
            emit(true);
            return;
          }
          var range = window.getSelection && window.getSelection().rangeCount ? window.getSelection().getRangeAt(0) : null;
          promptBox({ title: t('Link'), body: t('Link address'), placeholder: 'https://' }).then(function (href) {
            if (href === null) { return; }
            href = href.trim();
            if (!href || !validUrl(href)) { toast(t('Use a full address (https://…), a page path (/…), a section (#…), an email (mailto:…) or a phone (tel:…).'), 'error'); return; }
            ed.focus();
            if (range) { var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range); }
            document.execCommand('createLink', false, href);
            emit(true);
          });
        });
        bar.appendChild(btn);
      });
      wrap.appendChild(h('span', { class: 'ui-field__label', id: id + '-lbl', text: d.label || d.id }));
      wrap.appendChild(bar);
      wrap.appendChild(ed);
      if (err || help) { wrap.appendChild(h('span', { class: 'ui-field__help', id: id + '-help', 'data-help': help || null, text: err || help })); }
      return wrap;
    }
    /* Client-side tidy only — the server rebuilds rich text from its allow-list. */
    function sanitizeRich(html) {
      var tpl = document.createElement('template');
      tpl.innerHTML = html;
      var allowed = { B: 1, STRONG: 1, I: 1, EM: 1, A: 1, BR: 1, P: 1, U: 1 };
      (function walk(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (c) {
          if (c.nodeType === 1) {
            walk(c);
            if (!allowed[c.nodeName]) {
              while (c.firstChild) { c.parentNode.insertBefore(c.firstChild, c); }
              c.parentNode.removeChild(c);
              return;
            }
            Array.prototype.slice.call(c.attributes).forEach(function (a) {
              if (!(c.nodeName === 'A' && a.name === 'href' && validUrl(a.value))) { c.removeAttribute(a.name); }
            });
          } else if (c.nodeType !== 3) {
            c.parentNode.removeChild(c);
          }
        });
      })(tpl.content);
      var div = document.createElement('div');
      div.appendChild(tpl.content.cloneNode(true));
      return div.innerHTML;
    }

    function renderImage(d, ctx, wrap, id, help, err) {
      var val = ctx.value == null ? '' : String(ctx.value);
      var src = S.media[val] || (/^(https?:)?\/\//.test(val) || val.charAt(0) === '/' ? val : '');
      var thumb = src
        ? h('img', { class: 'ts-img__thumb', src: src, alt: '', width: 96, height: 64, loading: 'lazy' })
        : h('span', { class: 'ts-img__thumb ts-img__thumb--blank', 'aria-hidden': 'true' }, [svgIcon('upload', 20)]);
      var up = h('button', { type: 'button', class: 'ui-btn ui-btn--secondary ui-btn--sm', id: id }, [val ? t('Replace image') : t('Upload image')]);
      if (ctx.disabled) { up.disabled = true; }
      up.addEventListener('click', function () {
        pickFile(function (file) {
          up.setAttribute('aria-busy', 'true');
          up.textContent = t('Uploading…');
          upload(file).then(function (r) {
            if (r) { ctx.onChange(r.filename, true); renderRight(); }
          }).then(function () {
            up.removeAttribute('aria-busy');
            up.textContent = val ? t('Replace image') : t('Upload image');
          });
        });
      });
      var kids = [thumb, h('div', { class: 'ts-img__meta' }, [
        h('span', { class: 'ts-img__name', text: val || t('None') }),
        h('div', { class: 'ts-img__btns' }, [
          up,
          val && !ctx.disabled ? h('button', { type: 'button', class: 'ui-btn ui-btn--ghost ui-btn--sm', on: { click: function () { ctx.onChange('', true); renderRight(); } } }, [t('Remove image')]) : null
        ])
      ])];
      wrap.appendChild(h('span', { class: 'ui-field__label', text: d.label || d.id }));
      wrap.appendChild(h('div', { class: 'ts-img' }, kids));
      if (err || help) { wrap.appendChild(h('span', { class: 'ui-field__help', id: id + '-help', 'data-help': help || null, text: err || help })); }
      return wrap;
    }

    function renderItemList(d, ctx, wrap, id, help, err) {
      var rows = (ui.pickers && Array.isArray(ui.pickers.item_list)) ? ui.pickers.item_list : [];
      var max = Math.max(1, Math.min(parseInt(d.max, 10) || 24, 24));
      var chosen = Array.isArray(ctx.value) ? ctx.value.map(Number) : [];
      var count = h('span', { class: 'ts-count', text: t('Selected :n of :max', { n: chosen.length, max: max }) });
      var search = h('input', { type: 'search', placeholder: t('Find an item'), 'aria-label': t('Find an item') });
      var list = h('ul', { class: 'ts-checklist', role: 'list', id: id });
      function draw() {
        list.textContent = '';
        var needle = search.value.trim().toLowerCase();
        rows.forEach(function (r) {
          if (needle && String(r.name).toLowerCase().indexOf(needle) === -1) { return; }
          var cb = h('input', { type: 'checkbox', checked: chosen.indexOf(Number(r.id)) !== -1 });
          if (ctx.disabled || (!cb.checked && chosen.length >= max)) { cb.disabled = true; }
          cb.addEventListener('change', function () {
            if (cb.checked) { chosen.push(Number(r.id)); } else { chosen = chosen.filter(function (x) { return x !== Number(r.id); }); }
            count.textContent = t('Selected :n of :max', { n: chosen.length, max: max });
            ctx.onChange(chosen.slice(), true);
            draw();
          });
          list.appendChild(h('li', {}, [h('label', { class: 'ui-check' }, [cb, h('span', { text: r.name })])]));
        });
      }
      search.addEventListener('input', draw);
      draw();
      wrap.appendChild(h('div', { class: 'ts-field-head' }, [h('span', { class: 'ui-field__label', text: d.label || d.id }), count]));
      if (rows.length > 8) { wrap.appendChild(h('div', { class: 'ui-field__control ts-searchbox' }, [svgIcon('search', 18), search])); }
      wrap.appendChild(list);
      if (err || help) { wrap.appendChild(h('span', { class: 'ui-field__help', id: id + '-help', 'data-help': help || null, text: err || help })); }
      return wrap;
    }

    function pickFile(cb) {
      var input = els.file;
      if (!input) { return; }
      var handler = function () {
        input.removeEventListener('change', handler);
        var f = input.files && input.files[0];
        input.value = '';
        if (f) { cb(f); }
      };
      input.addEventListener('change', handler);
      input.click();
    }
    function upload(file) {
      if (!urls.media || UPLOAD_TYPES.indexOf(file.type) === -1 || file.size > MAX_UPLOAD) {
        toast(t('Upload failed. Use a JPG, PNG or WebP under 5 MB.'), 'error');
        return Promise.resolve(null);
      }
      var fd = new FormData();
      fd.append('file', file);
      return api('POST', urls.media, fd).then(function (res) {
        if (res.ok && isObj(res.data) && res.data.filename) {
          if (res.data.url) { S.media[res.data.filename] = res.data.url; }
          return res.data;
        }
        toast((res.data && res.data.message) || t('Upload failed. Use a JPG, PNG or WebP under 5 MB.'), 'error');
        return null;
      }).catch(function () {
        toast(t('Upload failed. Use a JPG, PNG or WebP under 5 MB.'), 'error');
        return null;
      });
    }

    /* ------------------------------------------------------------- render */
    function iconBtn(icon, label, onClick, extra) {
      var b = h('button', Object.assign({ type: 'button', class: 'ui-icon-btn ui-icon-btn--ghost ts-mini', 'aria-label': label, title: label }, extra || {}), [svgIcon(icon, 16)]);
      b.addEventListener('click', function (e) { e.stopPropagation(); onClick(e, b); });
      return b;
    }

    function renderToolbar() {
      if (els.undo) { els.undo.disabled = !S.undo.length; }
      if (els.redo) { els.redo.disabled = !S.redo.length; }
      if (els.save) {
        var map = {
          loading: t('Loading…'), dirty: t('Unsaved changes'), saving: t('Saving…'), error: t('Not saved'),
          saved: S.hasDraft ? t('Saved') + ' · ' + t('Draft') : t('Saved')
        };
        var tones = { loading: 'neutral', dirty: 'warning', saving: 'warning', error: 'danger', saved: 'success' };
        els.save.textContent = map[S.saveState] || '';
        els.save.setAttribute('data-state', S.saveState);
        els.save.setAttribute('data-tone', tones[S.saveState] || 'neutral');
      }
      if (els.edit) {
        els.edit.disabled = !S.sel;
        if (els.editLabel) { els.editLabel.textContent = S.sel ? sectionName(S.sel.sid) : t('Edit section'); }
      }
    }

    function renderAll() {
      renderToolbar();
      renderSections();
      renderRight();
      renderTheme();
    }

    function renderSections(focus) {
      var pane = els.paneSections;
      if (!pane || !S.layout) { return; }
      pane.textContent = '';
      var list = h('ol', { class: 'ts-tree', 'aria-label': t('Sections') });
      var n = S.layout.order.length;
      S.layout.order.forEach(function (sid, i) {
        var s = sec(sid);
        var d = defOf(s.type);
        var selected = S.sel && S.sel.sid === sid;
        var row = h('li', {
          class: 'ts-row' + (selected ? ' is-selected' : '') + (s.disabled ? ' is-hidden' : '') + (!d ? ' is-unknown' : ''),
          'data-sid': sid,
          draggable: caps.sections_reorder ? 'true' : null
        });
        if (caps.sections_reorder) {
          row.appendChild(h('span', { class: 'ts-row__grip', title: t('Drag to reorder'), 'aria-hidden': 'true' }, [svgIcon('grip', 16)]));
        }
        var main = h('button', { type: 'button', class: 'ts-row__main', 'aria-current': selected ? 'true' : null, 'data-act': 'select' }, [
          h('span', { class: 'ts-row__name', text: sectionName(sid) }),
          s.disabled ? h('span', { class: 'ui-tag ts-row__tag', 'data-tone': 'neutral', text: t('Hidden') }) : null,
          !d ? h('span', { class: 'ui-tag ts-row__tag', 'data-tone': 'warning', text: t('Unknown section') }) : null
        ]);
        main.addEventListener('click', function () { sectionAction('select', sid, main); });
        row.appendChild(main);
        var tools = h('span', { class: 'ts-row__tools' });
        if (caps.sections_reorder) {
          tools.appendChild(iconBtn('up', t('Move up') + ' — ' + sectionName(sid), function () { moveSection(sid, -1); renderSectionsFocus(sid, 'up'); }, { disabled: i === 0 ? 'disabled' : null, 'data-act': 'up' }));
          tools.appendChild(iconBtn('down', t('Move down') + ' — ' + sectionName(sid), function () { moveSection(sid, 1); renderSectionsFocus(sid, 'down'); }, { disabled: i === n - 1 ? 'disabled' : null, 'data-act': 'down' }));
        }
        tools.appendChild(iconBtn(s.disabled ? 'eye' : 'eyeoff', (s.disabled ? t('Show section') : t('Hide section')) + ' — ' + sectionName(sid), function () { toggleHidden(sid); renderSectionsFocus(sid, 'hide'); }, { 'aria-pressed': s.disabled ? 'true' : 'false', 'data-act': 'hide' }));
        if (caps.sections_add && d) {
          var dupOk = canAddType(s.type).ok;
          tools.appendChild(iconBtn('copy', t('Duplicate') + ' — ' + sectionName(sid), function () { duplicateSection(sid); }, { disabled: dupOk ? null : 'disabled', 'data-act': 'dup' }));
        }
        if (caps.sections_remove) {
          tools.appendChild(iconBtn('trash', t('Remove') + ' — ' + sectionName(sid), function () { removeSection(sid); }, { 'data-act': 'del' }));
        }
        row.appendChild(tools);
        wireDrag(row, sid, list, function (from, to, before) { moveSectionTo(from, to, before); });
        list.appendChild(row);
      });
      pane.appendChild(list);
      if (caps.sections_add) {
        var full = n >= limits.max_sections;
        var add = h('button', { type: 'button', class: 'ui-btn ui-btn--secondary ui-btn--block ts-add', disabled: full ? 'disabled' : null }, [svgIcon('plus', 18), t('Add section')]);
        add.addEventListener('click', function () { openGallery(S.sel ? S.sel.sid : null, add); });
        pane.appendChild(add);
        if (full) { pane.appendChild(h('p', { class: 'ts-note', text: t('You can have up to :max sections.', { max: limits.max_sections }) })); }
      } else {
        pane.appendChild(h('p', { class: 'ts-note', text: t('Sections are locked by your administrator.') }));
      }
      if (focus) {
        var target = pane.querySelector('.ts-row[data-sid="' + cssEsc(focus.sid) + '"] [data-act="' + focus.act + '"]');
        if (target && target.disabled) { target = pane.querySelector('.ts-row[data-sid="' + cssEsc(focus.sid) + '"] .ts-row__main'); }
        if (target) { target.focus(); }
      }
    }
    function renderSectionsFocus(sid, act) { renderSections({ sid: sid, act: act }); }
    function cssEsc(s) { return window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/["\\]/g, '\\$&'); }

    /* HTML5 drag & drop for a list row (keyboard users have the arrow buttons). */
    function wireDrag(row, id, list, onDrop) {
      if (row.getAttribute('draggable') !== 'true') { return; }
      row.addEventListener('dragstart', function (e) {
        S.drag = { id: id, list: list };
        row.classList.add('is-dragging');
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', id); } catch (x) { /* old browsers */ }
      });
      row.addEventListener('dragend', function () {
        S.drag = null;
        row.classList.remove('is-dragging');
        qa('.is-drop-before, .is-drop-after', list).forEach(function (r) { r.classList.remove('is-drop-before', 'is-drop-after'); });
      });
      row.addEventListener('dragover', function (e) {
        if (!S.drag || S.drag.list !== list || S.drag.id === id) { return; }
        e.preventDefault();
        var r = row.getBoundingClientRect();
        var before = e.clientY < r.top + r.height / 2;
        row.classList.toggle('is-drop-before', before);
        row.classList.toggle('is-drop-after', !before);
      });
      row.addEventListener('dragleave', function () { row.classList.remove('is-drop-before', 'is-drop-after'); });
      row.addEventListener('drop', function (e) {
        if (!S.drag || S.drag.list !== list) { return; }
        e.preventDefault();
        var before = row.classList.contains('is-drop-before');
        row.classList.remove('is-drop-before', 'is-drop-after');
        var from = S.drag.id;
        S.drag = null;
        if (from !== id) { onDrop(from, id, before); }
      });
    }

    function errorFor(sid, bid, key) {
      var paths = bid
        ? ['sections.' + sid + '.blocks.' + bid + '.settings.' + key, 'sections.' + sid + '.blocks.' + bid + '.' + key, 'sections.' + sid + '.' + bid + '.' + key]
        : ['sections.' + sid + '.settings.' + key, 'sections.' + sid + '.' + key];
      for (var i = 0; i < paths.length; i++) { if (S.errors[paths[i]]) { return S.errors[paths[i]]; } }
      return '';
    }
    function sectionErrors(sid) {
      var pre = 'sections.' + sid;
      return Object.keys(S.errors).filter(function (k) { return k === pre || k.indexOf(pre + '.') === 0; });
    }

    function renderSettingsForm(sid, bid, defs, into) {
      var gen = S.formGen;
      if (!defs.length) {
        into.appendChild(h('p', { class: 'ts-note', text: t('This section has no settings.') }));
        return;
      }
      var disabled = !!bid && !caps.blocks_edit;
      defs.forEach(function (d) {
        if (!d.id && d.type !== 'header' && d.type !== 'paragraph') { return; }
        var translated = S.lang && S.lang !== defaultLang && TEXT_TYPES[d.type];
        var field = renderField(d, {
          value: readValue(sid, bid, d),
          placeholder: translated ? String(defaultLangValue(sid, bid, d) || '') : (d.placeholder || ''),
          error: d.id ? errorFor(sid, bid, d.id) : '',
          disabled: disabled,
          onChange: function (v, final) {
            // A field that was re-rendered away (rollback, undo) can still fire
            // change/blur on its way out — ignore stale closures.
            if (gen !== S.formGen) { return; }
            writeSetting(sid, bid, d, v, { coalesce: sid + '/' + (bid || '') + '/' + d.id + '/' + S.lang, keepForm: true });
            if (final) { S.coalesce = null; }
          }
        });
        into.appendChild(field);
      });
    }

    function langName(code) {
      var l = languages.filter(function (x) { return x && x.code === code; })[0];
      return l ? (l.name || code) : code;
    }

    function renderRight() {
      var box = els.right;
      if (!box || !S.layout) { return; }
      S.formGen++;
      box.textContent = '';
      if (!S.sel || !sec(S.sel.sid)) {
        box.appendChild(h('div', { class: 'ui-empty ts-empty' }, [
          h('span', { class: 'ui-empty__icon' }, [svgIcon('edit', 28)]),
          h('p', { class: 'ui-empty__title', text: t('Settings') }),
          h('p', { class: 'ui-empty__body', text: t('Select a section in the preview or in the list to edit it.') })
        ]));
        return;
      }
      var sid = S.sel.sid;
      var s = sec(sid);
      var d = defOf(s.type);
      var name = sectionName(sid);
      if (S.lang && S.lang !== defaultLang) {
        box.appendChild(h('div', { class: 'ui-banner ts-langnote', role: 'status' }, [
          h('span', { class: 'ui-banner__icon' }, [svgIcon('translate', 18)]),
          h('div', { class: 'ui-banner__body', text: t('Editing :lang. Leave a text blank to use :default.', { lang: langName(S.lang), default: langName(defaultLang) }) })
        ]));
      }
      var errs = sectionErrors(sid);
      if (errs.length) {
        box.appendChild(h('div', { class: 'ui-banner', 'data-tone': 'danger', role: 'alert' }, [
          h('span', { class: 'ui-banner__icon' }, [svgIcon('alert', 18)]),
          h('div', { class: 'ui-banner__body', text: errs.map(function (k) { return S.errors[k]; }).join(' ') })
        ]));
      }

      if (S.sel.bid && s.blocks && s.blocks[S.sel.bid]) {
        var bid = S.sel.bid;
        var b = s.blocks[bid];
        var bd = blockDef(d, b.type);
        var back = h('button', { type: 'button', class: 'ui-btn ui-btn--link ui-btn--sm ts-back' }, [svgIcon('back', 16), t('Back to :name', { name: name })]);
        back.addEventListener('click', function () { select(sid, null); });
        box.appendChild(back);
        box.appendChild(h('h3', { class: 'ui-card__title ts-panel__title', text: bd && bd.name ? bd.name : b.type }));
        renderSettingsForm(sid, bid, settingDefs(bd), box);
        if (caps.blocks_edit) {
          box.appendChild(h('button', { type: 'button', class: 'ui-btn ui-btn--danger ui-btn--sm ts-remove-block', on: { click: function () { removeBlock(sid, bid); } } }, [svgIcon('trash', 16), t('Remove')]));
        }
        return;
      }

      var head = h('div', { class: 'ts-panel__head' }, [
        h('h3', { class: 'ui-card__title ts-panel__title', text: name }),
        d && d.group ? h('span', { class: 'ui-tag', 'data-tone': 'accent', text: t(GROUP_LABELS[d.group] || 'Other') }) : null
      ]);
      box.appendChild(head);
      if (!d) {
        box.appendChild(h('p', { class: 'ts-note', text: t('This section type is no longer available.') }));
        return;
      }
      var vis = renderField({ id: '__visible', type: 'checkbox', label: t('Show section') }, {
        value: !s.disabled,
        onChange: function (v) { if (!!s.disabled === !!v) { toggleHidden(sid); } }
      });
      box.appendChild(vis);
      renderSettingsForm(sid, null, settingDefs(d), box);
      if (Array.isArray(d.blocks) && d.blocks.length) { box.appendChild(renderBlocks(sid, s, d)); }
      if (caps.ai_generate && urls.ai_restyle) { box.appendChild(renderRestyle(sid, s)); }
    }

    /* ------------------------------------------------ E2 restyle this section */
    function renderRestyle(sid, s) {
      if (S.restyle && S.restyle.sid !== sid) { S.restyle = null; pushCss(); }
      var preview = S.restyle;
      var id = 'ts-restyle-' + sid;
      var box = h('section', { class: 'ts-restyle', 'aria-labelledby': id + '-title', 'data-ts-restyle': sid });
      box.appendChild(h('h4', { class: 'ui-overline ts-blocks__title', id: id + '-title' }, [svgIcon('sparkle', 16), t('Restyle with AI')]));
      if (isObj(s.style) && !preview) {
        box.appendChild(h('p', { class: 'ts-note', text: t('This section uses an AI style.') + (s.style.prompt ? ' “' + s.style.prompt + '”' : '') }));
      }
      var input = h('textarea', {
        class: 'ts-restyle__prompt', id: id, rows: '3', maxlength: '300',
        'aria-describedby': id + '-help', disabled: S.restyleBusy ? 'disabled' : null
      });
      input.value = S.restylePrompt[sid] || '';
      input.addEventListener('input', function () { S.restylePrompt[sid] = input.value; });
      box.appendChild(h('div', { class: 'ui-field ts-field' }, [
        h('label', { class: 'ui-field__label', for: id, text: t('Restyle with AI') }),
        h('div', { class: 'ui-field__control' }, [input]),
        h('span', { class: 'ui-field__help', id: id + '-help', text: t('Describe the look you want, for example: darker background, rounder cards, bigger heading.') })
      ]));
      var actions = h('div', { class: 'ts-restyle__actions' });
      var gen = h('button', {
        type: 'button', class: 'ui-btn ui-btn--sm', 'data-ts-restyle-generate': '1',
        disabled: S.restyleBusy ? 'disabled' : null, 'aria-busy': S.restyleBusy ? 'true' : null
      }, [S.restyleBusy ? t('Generating…') : t('Generate style')]);
      gen.addEventListener('click', function () { generateRestyle(sid, input.value); });
      actions.appendChild(gen);
      box.appendChild(actions);
      if (preview) {
        box.appendChild(h('p', { class: 'ts-note', role: 'status', text: t('Previewing the new style. Keep it or discard it.') }));
        var keep = h('button', { type: 'button', class: 'ui-btn ui-btn--sm', 'data-ts-restyle-keep': '1' }, [t('Keep this style')]);
        keep.addEventListener('click', function () { keepRestyle(sid); });
        var drop = h('button', { type: 'button', class: 'ui-btn ui-btn--ghost ui-btn--sm', 'data-ts-restyle-discard': '1' }, [t('Discard')]);
        drop.addEventListener('click', function () { S.restyle = null; pushCss(); renderRight(); });
        box.appendChild(h('div', { class: 'ts-restyle__actions' }, [keep, drop]));
      }
      if (isObj(s.style)) {
        var reset = h('button', { type: 'button', class: 'ui-btn ui-btn--ghost ui-btn--sm', 'data-ts-restyle-reset': '1' }, [t('Reset to default style')]);
        reset.addEventListener('click', function () {
          S.restyle = null;
          commit(function (l) { if (!l.sections[sid]) { return false; } delete l.sections[sid].style; });
          announce(t('Style removed.'));
        });
        box.appendChild(h('div', { class: 'ts-restyle__actions' }, [reset]));
      }
      return box;
    }
    function generateRestyle(sid, prompt) {
      prompt = String(prompt || '').trim();
      if (S.restyleBusy || prompt.length < 3 || !urls.ai_restyle) { return; }
      S.restyleBusy = true;
      renderRight();
      api('POST', urls.ai_restyle, { sid: sid, prompt: prompt.slice(0, 300) }).then(function (res) {
        if (destroyed) { return; }
        if (res.ok && isObj(res.data) && typeof res.data.css === 'string' && res.data.css) {
          S.restyle = { sid: sid, css: res.data.css, prompt: String(res.data.prompt || prompt), created_at: res.data.created_at || null };
          pushCss();
          postFrame({ type: 'st:scrollTo', sid: sid });
          announce(t('Previewing the new style. Keep it or discard it.'));
        } else {
          toast((res.data && res.data.message) || t('Could not create a style. Try a different description.'), 'error');
        }
      }).catch(function () {
        toast(t('Could not create a style. Try a different description.'), 'error');
      }).then(function () {
        S.restyleBusy = false;
        if (!destroyed) { renderRight(); }
      });
    }
    function keepRestyle(sid) {
      var p = S.restyle;
      if (!p || p.sid !== sid) { return; }
      S.restyle = null;
      commit(function (l) {
        if (!l.sections[sid]) { return false; }
        l.sections[sid].style = { css: p.css, prompt: p.prompt, created_at: p.created_at };
      });
      S.restylePrompt[sid] = '';
      toast(t('Style kept. Publish to show it to customers.'), 'success');
    }

    function blockLabel(s, bid, bd) {
      var b = s.blocks[bid];
      var name = bd && bd.name ? bd.name : b.type;
      var firstText = settingDefs(bd).filter(function (x) { return x.type === 'text' && b.settings[x.id]; })[0];
      return firstText ? name + ': ' + String(b.settings[firstText.id]).slice(0, 40) : name;
    }

    function renderBlocks(sid, s, d) {
      var box = h('section', { class: 'ts-blocks', 'aria-label': t('Blocks') });
      box.appendChild(h('h4', { class: 'ui-overline ts-blocks__title', text: t('Blocks') }));
      var list = h('ol', { class: 'ts-tree ts-tree--blocks' });
      var order = s.block_order || [];
      order.forEach(function (bid, i) {
        var bd = blockDef(d, s.blocks[bid].type);
        var row = h('li', { class: 'ts-row', 'data-bid': bid, draggable: caps.blocks_edit ? 'true' : null });
        if (caps.blocks_edit) { row.appendChild(h('span', { class: 'ts-row__grip', 'aria-hidden': 'true' }, [svgIcon('grip', 16)])); }
        var main = h('button', { type: 'button', class: 'ts-row__main' }, [h('span', { class: 'ts-row__name', text: blockLabel(s, bid, bd) })]);
        main.addEventListener('click', function () { select(sid, bid); });
        row.appendChild(main);
        if (caps.blocks_edit) {
          var tools = h('span', { class: 'ts-row__tools' });
          tools.appendChild(iconBtn('up', t('Move up'), function () { moveBlock(sid, bid, -1); focusBlockTool(bid, 'up'); }, { disabled: i === 0 ? 'disabled' : null, 'data-act': 'up' }));
          tools.appendChild(iconBtn('down', t('Move down'), function () { moveBlock(sid, bid, 1); focusBlockTool(bid, 'down'); }, { disabled: i === order.length - 1 ? 'disabled' : null, 'data-act': 'down' }));
          tools.appendChild(iconBtn('trash', t('Remove'), function () { removeBlock(sid, bid); }));
          row.appendChild(tools);
        }
        wireDrag(row, bid, list, function (from, to, before) { moveBlockTo(sid, from, to, before); });
        list.appendChild(row);
      });
      box.appendChild(list);
      if (caps.blocks_edit) {
        var types = d.blocks.filter(function (b) { return b && b.type; });
        var full = order.length >= limits.max_blocks;
        var adds = h('div', { class: 'ts-blocks__add' });
        types.forEach(function (bd) {
          var ok = canAddBlock(sid, bd.type);
          adds.appendChild(h('button', {
            type: 'button', class: 'ui-btn ui-btn--secondary ui-btn--sm', disabled: ok.ok ? null : 'disabled', title: ok.why || null,
            on: { click: function () { addBlock(sid, bd.type); } }
          }, [svgIcon('plus', 16), types.length === 1 ? t('Add block') : t('Add :name', { name: bd.name || bd.type })]));
        });
        box.appendChild(adds);
        if (full) { box.appendChild(h('p', { class: 'ts-note', text: t('You can have up to :max blocks in a section.', { max: limits.max_blocks }) })); }
      }
      return box;
    }
    function focusBlockTool(bid, act) {
      var b = els.right && els.right.querySelector('.ts-row[data-bid="' + cssEsc(bid) + '"] [data-act="' + act + '"]');
      if (b && !b.disabled) { b.focus(); }
    }

    /* ---------------------------------------------------------- gallery */
    function openGallery(after, opener) {
      if (!caps.sections_add) { return; }
      S.insertAfter = after || null;
      renderGallery();
      if (isMobile()) { closeSheet(els.sheetLeft); }
      openSheet(els.sheetGallery, opener);
      var s = els.gallery && els.gallery.querySelector('input[type="search"]');
      if (s) { setTimeout(function () { s.focus(); }, 30); }
    }
    function matchesStore(d) {
      var v = Array.isArray(d.verticals) ? d.verticals : [];
      return !v.length || v.indexOf('all') !== -1 || v.indexOf(ui.group) !== -1 || v.indexOf(store.store_type) !== -1;
    }
    function galleryDefs() {
      return S.libList.filter(function (d) {
        if (d.hidden_from_gallery) { return false; }
        if (d.type === 'custom-block' && !caps.custom_block) { return false; }
        return true;
      });
    }
    function renderGallery() {
      var box = els.gallery;
      if (!box) { return; }
      box.textContent = '';
      var g = S.gallery;
      var search = h('input', { type: 'search', value: g.q, placeholder: t('Search sections'), 'aria-label': t('Search sections') });
      search.addEventListener('input', function () { g.q = search.value; drawGrid(); });
      var mine = h('label', { class: 'ui-check' }, [
        h('input', { type: 'checkbox', checked: g.mine, on: { change: function (e) { g.mine = e.target.checked; drawChips(); drawGrid(); } } }),
        h('span', { text: t('Only sections for my store type') })
      ]);
      var chips = h('div', { class: 'ui-chips ts-chips', role: 'group', 'aria-label': t('Sections') });
      var rec = h('div', { class: 'ts-gallery__rec' });
      var grid = h('ul', { class: 'ts-gallery', role: 'list' });
      box.appendChild(h('div', { class: 'ts-gallery__tools' }, [h('div', { class: 'ui-field__control ts-searchbox' }, [svgIcon('search', 18), search]), mine]));
      box.appendChild(chips);
      box.appendChild(rec);
      box.appendChild(grid);

      function pool() {
        return galleryDefs().filter(function (d) { return !g.mine || matchesStore(d); });
      }
      function drawChips() {
        chips.textContent = '';
        var present = {};
        pool().forEach(function (d) { present[d.group || 'other'] = 1; });
        var groups = GROUPS.filter(function (x) { return present[x]; });
        if (present.other) { groups.push('other'); }
        if (g.group && !present[g.group]) { g.group = ''; }
        [''].concat(groups).forEach(function (grp) {
          var b = h('button', { type: 'button', class: 'ui-chip', 'aria-pressed': g.group === grp ? 'true' : 'false', text: grp ? t(GROUP_LABELS[grp] || 'Other') : t('All') });
          b.addEventListener('click', function () { g.group = grp; drawChips(); drawGrid(); });
          chips.appendChild(b);
        });
      }
      function card(d) {
        var check = canAddType(d.type);
        var thumb = d.thumb
          ? h('img', { class: 'ts-gcard__thumb', src: d.thumb, alt: '', loading: 'lazy', width: 240, height: 150 })
          : h('span', { class: 'ts-gcard__thumb ts-gcard__thumb--blank', 'aria-hidden': 'true', text: String(d.name || d.type).charAt(0) });
        var descId = 'ts-g-' + (++uid);
        var b = h('button', {
          type: 'button', class: 'ts-gcard', disabled: check.ok ? null : 'disabled', 'aria-describedby': check.ok ? null : descId
        }, [
          thumb,
          h('span', { class: 'ts-gcard__name', text: d.name || d.type }),
          h('span', { class: 'ts-gcard__group', text: t(GROUP_LABELS[d.group] || 'Other') }),
          check.ok ? null : h('span', { class: 'ts-gcard__why', id: descId, text: check.why })
        ]);
        b.addEventListener('click', function () {
          closeSheet(els.sheetGallery);
          addSection(d.type, S.insertAfter);
        });
        return h('li', {}, [b]);
      }
      function drawGrid() {
        grid.textContent = '';
        rec.textContent = '';
        var needle = g.q.trim().toLowerCase();
        var items = pool().filter(function (d) {
          if (g.group && (d.group || 'other') !== g.group) { return false; }
          if (needle && String(d.name || d.type).toLowerCase().indexOf(needle) === -1 && String(d.type).indexOf(needle) === -1) { return false; }
          return true;
        });
        if (!needle && !g.group) {
          var picks = galleryDefs().filter(function (d) {
            var v = Array.isArray(d.verticals) ? d.verticals : [];
            return v.indexOf(ui.group) !== -1 || v.indexOf(store.store_type) !== -1;
          }).slice(0, 6);
          if (picks.length) {
            rec.appendChild(h('h4', { class: 'ui-overline ts-gallery__h', text: t('Recommended for your store') }));
            var rl = h('ul', { class: 'ts-gallery ts-gallery--rec', role: 'list' });
            picks.forEach(function (d) { rl.appendChild(card(d)); });
            rec.appendChild(rl);
          }
        }
        if (!items.length) {
          grid.appendChild(h('li', { class: 'ts-note', text: t('No sections match your search.') }));
          return;
        }
        items.forEach(function (d) { grid.appendChild(card(d)); });
      }
      drawChips();
      drawGrid();
    }

    /* ------------------------------------------------------------ theme tab */
    var tokenTimer = null;
    var varsTimer = null;
    function tokenAllowed(f) {
      if (f.type === 'font_pair' || /font/i.test(String(f.id))) { return !!caps.fonts; }
      return !!caps.tokens;
    }
    function renderTheme() {
      var pane = els.paneTheme;
      if (!pane) { return; }
      var gen = ++S.themeGen;
      pane.textContent = '';
      var fields = (Array.isArray(S.tokens.fields) ? S.tokens.fields : []).filter(isObj);
      var allowed = fields.filter(tokenAllowed);
      if (!allowed.length) {
        pane.appendChild(h('p', { class: 'ts-note', text: t('Theme settings are locked by your administrator.') }));
        return;
      }
      pane.appendChild(h('p', { class: 'ts-note', text: t('Colours and fonts are saved with your draft and go live when you publish.') }));
      if (S.contrast.length) {
        pane.appendChild(h('div', { class: 'ui-banner', 'data-tone': 'warning', role: 'status' }, [
          h('span', { class: 'ui-banner__icon' }, [svgIcon('alert', 18)]),
          h('div', { class: 'ui-banner__body' }, [h('strong', { text: t('Contrast warnings') }), h('ul', { class: 'ts-contrast' }, S.contrast.map(function (w) {
            return h('li', { text: t('Low contrast: :pair (:ratio:1).', { pair: w.pair || w.label || '', ratio: w.ratio || '?' }) });
          }))])
        ]));
      }
      if (Array.isArray(S.tokens.presets) && S.tokens.presets.length && caps.tokens) {
        var ps = h('div', { class: 'ts-presets' });
        S.tokens.presets.forEach(function (p) {
          if (!isObj(p) || !isObj(p.values)) { return; }
          ps.appendChild(h('button', { type: 'button', class: 'ts-preset', on: { click: function () {
            Object.keys(p.values).forEach(function (k) { setToken(k, p.values[k]); });
            renderTheme();
          } } }, [
            h('span', { class: 'ts-preset__dots', 'aria-hidden': 'true' }, Object.keys(p.values).filter(function (k) { return /^#/.test(String(p.values[k])); }).slice(0, 4).map(function (k) {
              return h('span', { class: 'ts-swatch__dot', style: '--sw:' + p.values[k] });
            })),
            h('span', { text: p.name || p.id || '' })
          ]));
        });
        pane.appendChild(h('h4', { class: 'ui-overline ts-blocks__title', text: t('Presets') }));
        pane.appendChild(ps);
      }
      var groups = {};
      var order = [];
      allowed.forEach(function (f) {
        var g = f.group || (f.type === 'color' ? t('Colours') : f.type === 'font_pair' ? t('Fonts') : t('Layout'));
        if (!groups[g]) { groups[g] = []; order.push(g); }
        groups[g].push(f);
      });
      order.forEach(function (g) {
        var box = h('section', { class: 'ts-tgroup' }, [h('h4', { class: 'ui-overline ts-blocks__title', text: g })]);
        groups[g].forEach(function (f) {
          box.appendChild(renderField(f, {
            value: Object.prototype.hasOwnProperty.call(S.tokens.values, f.id) ? S.tokens.values[f.id] : f.default,
            error: S.tokenErrors[f.id] ? String(S.tokenErrors[f.id]) : '',
            onChange: function (v) { if (gen === S.themeGen) { setToken(f.id, v); } }
          }));
        });
        pane.appendChild(box);
      });
    }
    function setToken(id, v) {
      S.tokens.values[id] = v;
      S.tokenDirty[id] = v;
      setSave('dirty');
      clearTimeout(varsTimer);
      varsTimer = setTimeout(previewTokens, 180);
      clearTimeout(tokenTimer);
      tokenTimer = setTimeout(function () { tokenTimer = null; saveTokens(); }, 800);
    }
    function naiveVars() {
      var out = {};
      (S.tokens.fields || []).forEach(function (f) {
        var v = S.tokens.values[f.id];
        if (f.type === 'color' && /^#[0-9a-f]{3,8}$/i.test(String(v || ''))) {
          out['--tc-' + String(f.id).split('.').pop().replace(/_/g, '-')] = v;
        }
      });
      return out;
    }
    function previewTokens() {
      if (urls.tokens_vars) {
        api('POST', urls.tokens_vars, { values: S.tokens.values }).then(function (res) {
          var vars = res.ok && isObj(res.data) && isObj(res.data.vars) ? res.data.vars : naiveVars();
          postFrame({ type: 'st:vars', vars: vars });
        }).catch(function () { postFrame({ type: 'st:vars', vars: naiveVars() }); });
      } else {
        postFrame({ type: 'st:vars', vars: naiveVars() });
      }
    }
    function saveTokens(opts) {
      clearTimeout(tokenTimer);
      tokenTimer = null;
      var dirty = S.tokenDirty;
      if (!Object.keys(dirty).length || !urls.tokens_draft) { return Promise.resolve(); }
      S.tokenDirty = {};
      return api('POST', urls.tokens_draft, { values: dirty }, opts).then(function (res) {
        if (destroyed) { return; }
        if (res.ok) {
          S.tokenErrors = {};
          S.hasDraft = true;
          if (!S.saveTimer && !S.inflight && JSON.stringify(S.layout) === S.saved) { setSave('saved'); }
          checkContrast();
        } else if (res.status === 422) {
          S.tokenErrors = mapErrors(res.data && res.data.errors);
          toast((res.data && res.data.message) || t('Some changes could not be saved.'), 'error');
          setSave('error');
          renderTheme();
        } else {
          Object.keys(dirty).forEach(function (k) { if (!(k in S.tokenDirty)) { S.tokenDirty[k] = dirty[k]; } });
          setSave('error');
        }
      }).catch(function () {
        Object.keys(dirty).forEach(function (k) { if (!(k in S.tokenDirty)) { S.tokenDirty[k] = dirty[k]; } });
        setSave('error');
      });
    }
    function checkContrast() {
      if (!urls.tokens_contrast) { return; }
      api('POST', urls.tokens_contrast, { values: S.tokens.values }).then(function (res) {
        if (destroyed || !res.ok || !isObj(res.data)) { return; }
        S.contrast = Array.isArray(res.data.contrast) ? res.data.contrast : [];
        if (S.tab === 'theme') { renderTheme(); }
      }).catch(function () { /* advisory only */ });
    }

    /* ------------------------------ C4 translate all · C5 update notices */
    function wireTranslate() {
      var group = q('[data-ts-langs]');
      if (!group || !cfg.translate || !urls.translate || languages.length < 2) { return; }
      var label = t('Translate to other languages');
      var btn = h('button', { type: 'button', class: 'ui-icon-btn', 'data-ts-translate': true, 'aria-label': label, title: label }, [svgIcon('translate')]);
      group.appendChild(btn);
      on(btn, 'click', function () { translateAll(btn); });
    }
    function translateAll(btn) {
      confirmBox({
        title: t('Translate to other languages'),
        body: t('Translate the texts of this page into your other menu languages? Texts you already translated are kept.'),
        confirmLabel: t('Translate')
      }).then(function (ok) {
        if (!ok || destroyed) { return; }
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        announce(t('Translating…'));
        flushAll().then(function () {
          if (S.saveState === 'error') { throw new Error('save'); }
          return api('POST', urls.translate, {});
        }).then(function (res) {
          if (res.ok && isObj(res.data) && isObj(res.data.layout)) {
            var n = Number(res.data.translated) || 0;
            S.hasDraft = res.data.has_draft !== false;
            adoptServerLayout(res.data.layout, n ? t('Translated :n texts. Review them in each language.', { n: n }) : t('Everything is already translated.'));
          } else {
            toast((res.data && res.data.message) || t('Translation failed. Please try again.'), 'error');
          }
        }).catch(function () {
          toast(t('Translation failed. Please try again.'), 'error');
        }).then(function () {
          btn.removeAttribute('aria-busy');
          btn.disabled = false;
        });
      });
    }
    /* A theme update dropped sections / blocks the new code no longer ships:
       one dismissible banner at the top of the left panel. Dismissing tells the
       server (PUT draft {dismiss_notices}) so it does not come back. */
    function renderNotices(list) {
      var old = q('[data-ts-notices]');
      if (old && old.parentNode) { old.parentNode.removeChild(old); }
      list = list.filter(function (n) { return isObj(n) && n.message; });
      if (!list.length || !els.left) { return; }
      var box = h('div', { class: 'ui-banner', 'data-tone': 'warning', role: 'status', 'data-ts-notices': true }, [
        h('span', { class: 'ui-banner__icon' }, [svgIcon('alert', 18)]),
        h('div', { class: 'ui-banner__body' }, [
          h('strong', { text: t('Your theme was updated') }),
          h('ul', { class: 'ts-notices' }, list.map(function (n) { return h('li', { text: String(n.message) }); }))
        ]),
        h('button', { type: 'button', class: 'ui-icon-btn ui-icon-btn--ghost ts-mini', 'aria-label': t('Dismiss'), title: t('Dismiss'), on: { click: dismissNotices } }, [svgIcon('x', 16)])
      ]);
      els.left.insertBefore(box, els.left.firstChild);
    }
    function dismissNotices() {
      renderNotices([]);
      if (!urls.draft || !S.layout) { return; }
      flushAll().then(function () {
        return api('PUT', urls.draft, { layout: JSON.parse(S.saved || JSON.stringify(S.layout)), dismiss_notices: true });
      }).catch(function () { /* the banner comes back on the next load */ });
    }

    /* ---------------------------------------------------- publish / history */
    function publish(btn) {
      if (!urls.publish) { return; }
      var body = t('Your storefront will show these changes to customers.');
      if (cfg.pending_theme) { body += ' ' + t('Your store will switch to :theme.', { theme: String(cfg.pending_theme) }); }
      confirmBox({ title: t('Publish your changes?'), body: body, confirmLabel: t('Publish now') }).then(function (ok) {
        if (ok && !destroyed) { doPublish(btn); }
      });
    }
    function doPublish(btn) {
      btn.setAttribute('aria-busy', 'true');
      btn.disabled = true;
      flushAll().then(function () {
        if (S.saveState === 'error') { throw new Error('save'); }
        return api('POST', urls.publish);
      }).then(function (res) {
        if (res.ok && isObj(res.data) && res.data.ok !== false) {
          S.hasDraft = false;
          toast(t('Published. Your storefront now shows these changes.'));
          renderToolbar();
        } else {
          toast((res.data && res.data.message) || t('Could not publish. Try again.'), 'error');
        }
      }).catch(function () {
        toast(t('Could not publish. Try again.'), 'error');
      }).then(function () {
        btn.removeAttribute('aria-busy');
        btn.disabled = false;
      });
    }
    function adoptServerLayout(layout, msg) {
      S.undo.push(JSON.stringify(S.layout));
      if (S.undo.length > UNDO_MAX) { S.undo.shift(); }
      S.redo = [];
      S.layout = normalize(layout);
      S.saved = JSON.stringify(S.layout);
      S.errors = {};
      if (S.sel && !sec(S.sel.sid)) { S.sel = null; }
      setSave('saved');
      renderAll();
      loadFrame();
      if (msg) { toast(msg); }
    }
    function openHistory(opener) {
      openSheet(els.sheetHistory, opener);
      var box = els.history;
      if (!box || !urls.versions) { return; }
      box.textContent = t('Loading…');
      api('GET', urls.versions).then(function (res) {
        box.textContent = '';
        var list = res.ok && isObj(res.data) && Array.isArray(res.data.versions) ? res.data.versions : [];
        if (!list.length) {
          box.appendChild(h('div', { class: 'ui-empty' }, [
            h('span', { class: 'ui-empty__icon' }, [svgIcon('clock', 28)]),
            h('p', { class: 'ui-empty__title', text: t('No published versions yet.') })
          ]));
          return;
        }
        var fmt = null;
        try { fmt = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { fmt = null; }
        var ul = h('ul', { class: 'ui-list ts-versions', role: 'list' });
        list.forEach(function (v) {
          var when = v.at ? new Date(v.at) : null;
          var meta = [];
          if (when && !isNaN(when.getTime())) { meta.push(fmt ? fmt.format(when) : when.toLocaleString()); }
          if (typeof v.changed === 'number') { meta.push(t('Changes: :n', { n: v.changed })); } else if (Array.isArray(v.changed) && v.changed.length) { meta.push(v.changed.slice(0, 4).join(', ')); }
          var restoreBtn = h('button', { type: 'button', class: 'ui-btn ui-btn--secondary ui-btn--sm', text: t('Restore') });
          restoreBtn.addEventListener('click', function () { revert(v.id, restoreBtn); });
          ul.appendChild(h('li', { class: 'ui-row ts-version' }, [
            h('span', { class: 'ui-row__icon', 'data-tone': 'neutral' }, [svgIcon('clock', 22)]),
            h('span', { class: 'ui-row__body' }, [h('span', { class: 'ui-row__title', text: v.label || ('#' + v.id) }), h('span', { class: 'ui-row__sub', text: meta.join(' · ') })]),
            urls.revert ? h('span', { class: 'ui-row__trail' }, [restoreBtn]) : null
          ]));
        });
        box.appendChild(ul);
      }).catch(function () {
        box.textContent = t('Could not load the editor.');
      });
    }
    function revert(id, btn) {
      confirmBox({ title: t('Restore this version?'), body: t('Your draft is replaced by this version. You can undo this.'), confirmLabel: t('Restore') }).then(function (ok) {
        if (!ok || destroyed) { return; }
        btn.setAttribute('aria-busy', 'true');
        save().then(function () {
          return api('POST', String(urls.revert).replace('__ID__', encodeURIComponent(id)));
        }).then(function (res) {
          btn.removeAttribute('aria-busy');
          if (res.ok && isObj(res.data) && isObj(res.data.layout)) {
            S.hasDraft = true;
            closeSheet(els.sheetHistory);
            adoptServerLayout(res.data.layout, t('Restored.'));
          } else {
            toast((res.data && res.data.message) || t('Some changes could not be saved.'), 'error');
          }
        }).catch(function () { btn.removeAttribute('aria-busy'); });
      });
    }
    function discard(btn) {
      confirmBox({ title: t('Discard all unpublished changes?'), body: t('The draft goes back to what is live now.'), confirmLabel: t('Discard draft'), danger: true }).then(function (ok) {
        if (!ok || destroyed || !urls.discard) { return; }
        clearTimeout(S.saveTimer);
        S.saveTimer = null;
        S.tokenDirty = {};
        btn.setAttribute('aria-busy', 'true');
        (S.inflight || Promise.resolve()).then(function () { return api('POST', urls.discard); }).then(function (res) {
          btn.removeAttribute('aria-busy');
          if (res.ok && isObj(res.data)) {
            S.hasDraft = false;
            closeSheet(els.sheetHistory);
            // Discarding a not-yet-published theme choice returns to the picker / live theme.
            if (res.data && res.data.redirect) {
              if (window.Turbo && typeof window.Turbo.visit === 'function') { window.Turbo.visit(res.data.redirect); } else { location.href = res.data.redirect; }
              return;
            }
            if (isObj(res.data.layout)) { adoptServerLayout(res.data.layout, t('Draft discarded.')); } else { loadState(); }
          } else {
            toast(t('Could not save. Check your connection and try again.'), 'error');
          }
        }).catch(function () { btn.removeAttribute('aria-busy'); });
      });
    }

    /* ------------------------------------------------- sheets / responsive */
    var mq = window.matchMedia ? window.matchMedia('(max-width: 899.98px)') : null;
    function isMobile() { return !!(mq && mq.matches); }
    function placePanels() {
      if (!els.left || !els.right) { return; }
      if (isMobile()) {
        if (els.leftSheet && els.left.parentNode !== els.leftSheet) { els.leftSheet.appendChild(els.left); }
        if (els.rightSheet && els.right.parentNode !== els.rightSheet) { els.rightSheet.appendChild(els.right); }
      } else {
        closeSheet(els.sheetLeft);
        closeSheet(els.sheetRight);
        if (els.leftHome && els.left.parentNode !== els.leftHome) { els.leftHome.appendChild(els.left); }
        if (els.rightHome && els.right.parentNode !== els.rightHome) { els.rightHome.appendChild(els.right); }
      }
    }
    function openSheet(dlg, opener) {
      if (!dlg || typeof dlg.showModal !== 'function') { return; }
      dlg.__tsOpener = opener || document.activeElement;
      if (!dlg.open) { dlg.showModal(); }
    }
    function closeSheet(dlg) {
      if (dlg && dlg.open) { dlg.close(); }
    }
    [els.sheetLeft, els.sheetRight, els.sheetGallery, els.sheetHistory].forEach(function (dlg) {
      on(dlg, 'close', function () {
        if (dlg.__tsQuiet) { return; }
        var back = dlg.__tsOpener;
        dlg.__tsOpener = null;
        if (back && back.isConnected && typeof back.focus === 'function') { setTimeout(function () { back.focus(); }, 0); }
      });
    });
    if (mq) {
      var mqFn = function () { placePanels(); };
      if (mq.addEventListener) { mq.addEventListener('change', mqFn); cleanups.push(function () { mq.removeEventListener('change', mqFn); }); }
    }

    function setTab(tab) {
      S.tab = tab === 'theme' ? 'theme' : 'sections';
      qa('[data-ts-tab]').forEach(function (b) {
        var onTab = b.getAttribute('data-ts-tab') === S.tab;
        b.setAttribute('aria-selected', onTab ? 'true' : 'false');
        b.tabIndex = onTab ? 0 : -1;
      });
      if (els.paneSections) { els.paneSections.hidden = S.tab !== 'sections'; }
      if (els.paneTheme) { els.paneTheme.hidden = S.tab !== 'theme'; }
      var title = els.sheetLeft && els.sheetLeft.querySelector('.ui-sheet__title');
      if (title) { title.textContent = S.tab === 'theme' ? t('Theme') : t('Sections'); }
    }

    /* --------------------------------------------------------------- wiring */
    function wire() {
      qa('[data-ts-tab]').forEach(function (b) {
        on(b, 'click', function () { setTab(b.getAttribute('data-ts-tab')); });
        on(b, 'keydown', function (e) {
          if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' && e.key !== 'Home' && e.key !== 'End') { return; }
          e.preventDefault();
          var next = S.tab === 'sections' ? 'theme' : 'sections';
          if (e.key === 'Home') { next = 'sections'; }
          if (e.key === 'End') { next = 'theme'; }
          setTab(next);
          var nb = q('[data-ts-tab="' + next + '"]');
          if (nb) { nb.focus(); }
        });
      });
      qa('input[name="ts-device"]').forEach(function (r) {
        on(r, 'change', function () {
          if (!r.checked) { return; }
          S.device = r.value;
          if (els.stage) { els.stage.setAttribute('data-device', r.value); }
        });
      });
      qa('input[name="ts-lang"]').forEach(function (r) {
        on(r, 'change', function () {
          if (!r.checked) { return; }
          S.lang = r.value;
          S.coalesce = null;
          var go = function () { loadFrame(); renderRight(); };
          if (S.saveTimer || S.inflight) { save().then(go); } else { go(); }
        });
      });
      wireTranslate();
      on(els.undo, 'click', undo);
      on(els.redo, 'click', redo);
      var pub = q('[data-ts-publish]');
      on(pub, 'click', function () { publish(pub); });
      var prev = q('[data-ts-preview]');
      on(prev, 'click', function () {
        flushAll().then(function () { window.open(previewUrl(false), '_blank', 'noopener'); });
      });
      var hist = q('[data-ts-history]');
      on(hist, 'click', function () { openHistory(hist); });
      var disc = q('[data-ts-discard]');
      on(disc, 'click', function () { discard(disc); });
      qa('[data-ts-open]').forEach(function (b) {
        on(b, 'click', function () {
          if (b.getAttribute('data-ts-tab-target')) { setTab(b.getAttribute('data-ts-tab-target')); }
          openSheet(b.getAttribute('data-ts-open') === 'right' ? els.sheetRight : els.sheetLeft, b);
        });
      });
      // The frame is "ready" only once its bridge says st:ready (that happens
      // before the frame's load event); a frame without the bridge (storefront
      // error, signed out) simply stays a plain preview.
    }

    function onKey(e) {
      if (!(e.metaKey || e.ctrlKey) || e.altKey) { return; }
      var k = String(e.key || '').toLowerCase();
      var tgt = e.target;
      var typing = tgt && (tgt.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(tgt.nodeName));
      if (k === 's') {
        e.preventDefault();
        flushAll();
        return;
      }
      if (typing) { return; }
      if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); } else if ((k === 'z' && e.shiftKey) || k === 'y') { e.preventDefault(); redo(); }
    }

    /* ---------------------------------------------------------------- load */
    function loadState() {
      if (!urls.state) { return; }
      setSave('loading');
      api('GET', urls.state).then(function (res) {
        if (destroyed) { return; }
        if (!res.ok || !isObj(res.data)) { throw new Error('state'); }
        var d = res.data;
        S.library = {};
        S.libList = (Array.isArray(d.library) ? d.library : []).filter(function (x) { return isObj(x) && x.type; });
        S.libList.forEach(function (x) { S.library[x.type] = x; });
        S.layout = normalize(isObj(d.layout) ? d.layout : (isObj(d.published_layout) ? clone(d.published_layout) : {}));
        S.saved = JSON.stringify(S.layout);
        S.hasDraft = !!d.has_draft;
        if (isObj(d.tokens)) {
          S.tokens = { fields: Array.isArray(d.tokens.fields) ? d.tokens.fields : [], values: isObj(d.tokens.values) ? d.tokens.values : {}, presets: d.tokens.presets };
        }
        S.undo = [];
        S.redo = [];
        setSave('saved');
        renderAll();
        renderNotices(Array.isArray(d.notices) ? d.notices : []);
        loadFrame();
      }).catch(function () {
        if (destroyed) { return; }
        setSave('error');
        if (els.paneSections) {
          els.paneSections.textContent = '';
          var retry = h('button', { type: 'button', class: 'ui-btn ui-btn--secondary ui-btn--sm', text: t('Retry'), on: { click: loadState } });
          els.paneSections.appendChild(h('div', { class: 'ui-banner', 'data-tone': 'danger', role: 'alert' }, [
            h('span', { class: 'ui-banner__icon' }, [svgIcon('alert', 18)]),
            h('div', { class: 'ui-banner__body' }, [t('Could not load the editor.'), ' ', retry])
          ]));
        }
      });
    }

    /* Theme / recipe picker (store not on a Studio theme yet). */
    function wirePicker() {
      qa('[data-ts-start-theme], [data-ts-start-recipe]').forEach(function (b) {
        on(b, 'click', function () {
          var body = b.hasAttribute('data-ts-start-theme') ? { theme_slug: b.getAttribute('data-ts-start-theme') } : { recipe: b.getAttribute('data-ts-start-recipe') };
          confirmBox({
            title: t('Start with :name?', { name: b.getAttribute('data-ts-start-name') || '' }),
            body: t('Your store switches to this theme as a draft. Customers keep seeing your current design until you publish.'),
            confirmLabel: body.theme_slug ? t('Use this theme') : t('Use this layout')
          }).then(function (ok) {
            if (!ok || destroyed || !urls.start) { return; }
            b.setAttribute('aria-busy', 'true');
            b.disabled = true;
            api('POST', urls.start, body).then(function (res) {
              if (res.ok && isObj(res.data) && res.data.redirect) {
                if (window.Turbo && typeof window.Turbo.visit === 'function') { window.Turbo.visit(res.data.redirect); } else { location.href = res.data.redirect; }
                return;
              }
              throw new Error('start');
            }).catch(function () {
              b.removeAttribute('aria-busy');
              b.disabled = false;
              toast(t('Could not start. Try again.'), 'error');
            });
          });
        });
      });
    }

    function wireStoreSwitch() {
      var sel = q('[data-ts-store]');
      on(sel, 'change', function () {
        var target = withParams(ui.page || location.pathname, { store: sel.value });
        var go = function () {
          if (window.Turbo && typeof window.Turbo.visit === 'function') { window.Turbo.visit(target); } else { location.href = target; }
        };
        if (S.layout) { flushAll().then(go, go); } else { go(); }
      });
    }

    wireStoreSwitch();
    if (root.getAttribute('data-mode') === 'picker') {
      wirePicker();
    } else {
      wire();
      placePanels();
      setTab('sections');
      loadState();
    }

    return {
      root: root,
      onMessage: onMessage,
      onKey: onKey,
      hasUnsaved: hasUnsaved,
      flush: function (keepalive) { return flushAll(keepalive ? { keepalive: true } : undefined); },
      state: function () { return S; },
      destroy: function () {
        destroyed = true;
        clearTimeout(S.saveTimer);
        clearTimeout(tokenTimer);
        clearTimeout(varsTimer);
        [els.sheetLeft, els.sheetRight, els.sheetGallery, els.sheetHistory].forEach(closeSheet);
        // Put the panels back where the page markup had them (Turbo caches the DOM).
        if (els.left && els.leftHome && els.left.parentNode !== els.leftHome) { els.leftHome.appendChild(els.left); }
        if (els.right && els.rightHome && els.right.parentNode !== els.rightHome) { els.rightHome.appendChild(els.right); }
        cleanups.forEach(function (fn) { try { fn(); } catch (e) { /* ignore */ } });
        cleanups = [];
      }
    };
  }

  /* ============================================================ bootstrap */
  var current = null;

  function boot() {
    var root = document.getElementById('theme-studio');
    var cfgEl = document.getElementById('theme-studio-config');
    if (!root || !cfgEl) {
      if (current) { current.destroy(); current = null; }
      return;
    }
    if (current && current.root === root) { return; }
    if (current) { current.destroy(); current = null; }
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent || '{}'); } catch (e) { return; }
    current = createStudio(root, isObj(cfg) ? cfg : {});
  }

  window.ThemeStudio = {
    boot: boot,
    current: function () { return current; }
  };

  document.addEventListener('turbo:load', boot);
  document.addEventListener('DOMContentLoaded', boot);
  document.addEventListener('turbo:before-cache', function () {
    if (current) { current.destroy(); current = null; }
  });
  document.addEventListener('turbo:before-visit', function () {
    if (current && current.hasUnsaved()) { current.flush(true); }
  });
  window.addEventListener('message', function (e) { if (current) { current.onMessage(e); } });
  window.addEventListener('beforeunload', function (e) {
    if (current && current.hasUnsaved()) {
      current.flush(true);
      e.preventDefault();
      e.returnValue = '';
    }
  });
  document.addEventListener('keydown', function (e) { if (current) { current.onKey(e); } });

  if (document.readyState !== 'loading') { boot(); }
})();
