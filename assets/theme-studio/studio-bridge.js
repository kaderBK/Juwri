// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Theme Studio — canvas bridge (rev/theme-studio/EDITOR-CONTRACT.md).
 *
 * Loaded by the storefront shell ONLY in studio mode (`?studio=1`, the store's
 * owner signed in), inside the editor's same-origin iframe. It:
 *   - outlines the hovered section and names it; a click selects it;
 *   - shows an action toolbar for the selected section (add below, duplicate,
 *     move up/down, remove — as far as the editor's caps allow);
 *   - makes [data-studio-text] editable in place (plain text, committed on blur);
 *   - swaps a section wrapper with fresh server HTML (st:replace) and applies
 *     token CSS variables to :root (st:vars);
 *   - E2: shows the editor's per-section AI style variants instantly (st:css —
 *     the full, server-sanitised variant stylesheet; it replaces the shell's
 *     `#mb-section-styles` block in a <style> carrying this script's nonce).
 *
 * All chrome lives in a shadow root on <mb-studio-ui>; theme markup is never
 * touched except `contenteditable` on the text being edited. Links, buttons
 * and forms INSIDE sections don't navigate/submit while editing; everything
 * outside the sections (commerce sheets, overlays) is left alone.
 */
(function () {
  'use strict';

  if (window.parent === window || window.__mbStudioBridge) {
    return;
  }
  window.__mbStudioBridge = true;

  var doc = document;
  var me = doc.currentScript;
  var caps = {};
  var labels = {};
  var sel = null;        // selected section wrapper
  var hoverEl = null;
  var editing = null;    // {el, sid, key, before}
  var ui = {};
  var raf = 0;
  var rtl = false;
  var SCROLL_KEY = 'mbStudioScroll:' + location.pathname;
  var variantStyle = null; // E2 st:css target
  var plaintext = (function () {
    var probe = doc.createElement('span');
    try { probe.setAttribute('contenteditable', 'plaintext-only'); } catch (e) { return false; }
    return probe.contentEditable === 'plaintext-only';
  })();

  function post(msg) {
    try { window.parent.postMessage(msg, location.origin); } catch (e) { /* editor gone */ }
  }

  function sections() {
    return Array.prototype.slice.call(doc.querySelectorAll('[data-studio-section]'));
  }
  function sidOf(el) { return el ? el.getAttribute('data-studio-section') : null; }
  function typeOf(el) { return el ? el.getAttribute('data-studio-type') || '' : ''; }
  function findSection(sid) {
    if (!sid) { return null; }
    var list = sections();
    for (var i = 0; i < list.length; i++) {
      if (sidOf(list[i]) === sid) { return list[i]; }
    }
    return null;
  }
  function sectionOf(node) {
    var el = node && node.nodeType === 1 ? node : node && node.parentElement;
    return el && el.closest ? el.closest('[data-studio-section]') : null;
  }
  function inChrome(node) {
    return !!(node && node.closest && node.closest('mb-studio-ui'));
  }
  function name(el) {
    var ty = typeOf(el);
    return (labels.types && labels.types[ty]) || ty || labels.section || 'Section';
  }
  function t(key, fallback) { return (labels && labels[key]) || fallback; }

  /* ================================================================ overlay */
  var ICONS = {
    plus: 'M12 5v14 M5 12h14',
    copy: 'M8 8h11a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1z M16 8V5a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h4',
    up: 'M12 19V5 M5 12l7-7 7 7',
    down: 'M12 5v14 M19 12l-7 7-7-7',
    trash: 'M4 7h16 M10 11v6 M14 11v6 M6 7l1 12.5a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5L18 7 M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7'
  };
  function h(tag, attrs, kids) {
    var el = doc.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { el.textContent = attrs[k]; } else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
        el.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
      }
    });
    (kids || []).forEach(function (c) { if (c) { el.appendChild(c); } });
    return el;
  }
  function icon(nameKey) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = doc.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    ICONS[nameKey].split(' M').forEach(function (d, i) {
      var p = doc.createElementNS(ns, 'path');
      p.setAttribute('d', (i ? 'M' : '') + d);
      svg.appendChild(p);
    });
    return svg;
  }
  function btn(label, ic, action) {
    var b = h('button', { type: 'button', title: label, 'aria-label': label, 'data-action': action }, [icon(ic)]);
    b.addEventListener('mousedown', function (e) { e.preventDefault(); });
    b.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (sel) { post({ type: 'st:action', action: action, sid: sidOf(sel) }); }
    });
    return b;
  }

  function buildUi() {
    if (ui.host && ui.host.isConnected) { return; }
    var host = doc.createElement('mb-studio-ui');
    // CSSOM, not a style attribute: never subject to the storefront's CSP.
    host.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2147483646;display:block;';
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    if (me && me.src) {
      root.appendChild(h('link', { rel: 'stylesheet', href: me.src.replace(/studio-bridge\.js(\?.*)?$/, 'studio-bridge.css$1') }));
    }
    ui.host = host;
    ui.hover = h('div', { class: 'mbst-box mbst-hover' });
    ui.hoverTag = h('div', { class: 'mbst-tag' });
    ui.sel = h('div', { class: 'mbst-box mbst-sel' });
    ui.bar = h('div', { class: 'mbst-bar', role: 'toolbar' });
    [ui.hover, ui.hoverTag, ui.sel, ui.bar].forEach(function (n) { root.appendChild(n); });
    doc.body.appendChild(host);
  }

  function renderBar() {
    ui.bar.textContent = '';
    if (!sel) { return; }
    ui.bar.setAttribute('aria-label', name(sel));
    ui.bar.appendChild(h('span', { class: 'mbst-label', text: name(sel) }));
    var list = sections();
    var i = list.indexOf(sel);
    if (caps.sections_add !== false) { ui.bar.appendChild(btn(t('add-after', 'Add section below'), 'plus', 'add-after')); }
    if (caps.sections_add !== false) { ui.bar.appendChild(btn(t('duplicate', 'Duplicate'), 'copy', 'duplicate')); }
    if (caps.sections_reorder !== false) {
      var up = btn(t('move-up', 'Move up'), 'up', 'move-up');
      var down = btn(t('move-down', 'Move down'), 'down', 'move-down');
      if (i <= 0) { up.disabled = true; }
      if (i === list.length - 1) { down.disabled = true; }
      ui.bar.appendChild(up);
      ui.bar.appendChild(down);
    }
    if (caps.sections_remove !== false) { ui.bar.appendChild(btn(t('remove', 'Remove'), 'trash', 'remove')); }
  }

  function place(box, el) {
    if (!el || !el.isConnected) {
      box.style.display = 'none';
      return null;
    }
    var r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) {
      box.style.display = 'none';
      return null;
    }
    box.style.display = 'block';
    box.style.transform = 'translate(' + Math.round(r.left) + 'px,' + Math.round(r.top) + 'px)';
    box.style.width = Math.round(r.width) + 'px';
    box.style.height = Math.round(r.height) + 'px';
    return r;
  }
  function layout() {
    raf = 0;
    if (!ui.host) { return; }
    var vw = doc.documentElement.clientWidth;
    var vh = doc.documentElement.clientHeight;
    var hr = hoverEl && hoverEl !== sel ? place(ui.hover, hoverEl) : place(ui.hover, null);
    if (hr) {
      ui.hoverTag.style.display = 'block';
      ui.hoverTag.textContent = name(hoverEl);
      var tx = rtl ? Math.max(4, hr.right - ui.hoverTag.offsetWidth) : Math.max(4, hr.left);
      ui.hoverTag.style.transform = 'translate(' + Math.round(tx) + 'px,' + Math.round(Math.max(4, hr.top + 4)) + 'px)';
    } else {
      ui.hoverTag.style.display = 'none';
    }
    var sr = place(ui.sel, sel);
    if (sr && sel) {
      ui.bar.style.display = 'flex';
      var bw = ui.bar.offsetWidth;
      var bh = ui.bar.offsetHeight;
      var x = rtl ? sr.left + 8 : sr.right - bw - 8;
      x = Math.min(Math.max(4, x), vw - bw - 4);
      var y = sr.top - bh - 6;
      if (y < 4) { y = Math.min(Math.max(4, sr.top + 6), vh - bh - 4); }
      ui.bar.style.transform = 'translate(' + Math.round(x) + 'px,' + Math.round(y) + 'px)';
    } else {
      ui.bar.style.display = 'none';
    }
  }
  function schedule() {
    if (!raf) { raf = window.requestAnimationFrame(layout); }
  }

  function select(el, opts) {
    opts = opts || {};
    if (el === sel) {
      schedule();
      return;
    }
    sel = el;
    renderBar();
    schedule();
    if (!opts.silent) {
      post({ type: 'st:select', sid: sidOf(el), blockId: opts.blockId || undefined });
    }
  }

  function scrollToSection(el) {
    if (!el) { return; }
    var r = el.getBoundingClientRect();
    var vh = doc.documentElement.clientHeight;
    if (r.top < 0 || r.top > vh * 0.7) {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  /* ============================================================ text edits */
  function startEdit(el) {
    if (editing && editing.el === el) { return; }
    if (editing) { finishEdit(true); }
    var ref = String(el.getAttribute('data-studio-text') || '');
    var dot = ref.indexOf('.');
    if (dot <= 0) { return; }
    editing = { el: el, sid: ref.slice(0, dot), key: ref.slice(dot + 1), before: el.innerText };
    el.setAttribute('contenteditable', plaintext ? 'plaintext-only' : 'true');
    el.setAttribute('spellcheck', 'true');
    el.focus();
  }
  function finishEdit(commit) {
    if (!editing) { return; }
    var e = editing;
    editing = null;
    var value = e.el.innerText.replace(/ /g, ' ');
    if (!commit) { e.el.textContent = e.before; }
    e.el.removeAttribute('contenteditable');
    e.el.removeAttribute('spellcheck');
    if (commit && value !== e.before) {
      post({ type: 'st:text', sid: e.sid, key: e.key, value: value });
    }
  }
  doc.addEventListener('keydown', function (e) {
    if (!editing || e.target !== editing.el) { return; }
    if (e.key === 'Escape') {
      e.preventDefault();
      var el = editing.el;
      finishEdit(false);
      el.blur();
    } else if (e.key === 'Enter' && !e.shiftKey && editing.el.tagName !== 'DIV' && editing.el.tagName !== 'P') {
      e.preventDefault();
      editing.el.blur();
    }
    e.stopPropagation();
  }, true);
  doc.addEventListener('focusout', function (e) {
    if (editing && e.target === editing.el) { finishEdit(true); }
  }, true);
  doc.addEventListener('paste', function (e) {
    if (!editing || !editing.el.contains(e.target)) { return; }
    if (plaintext) { return; }
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text/plain');
    doc.execCommand('insertText', false, text);
  }, true);

  /* ================================================================ pointer */
  doc.addEventListener('mouseover', function (e) {
    if (inChrome(e.target)) { return; }
    var el = sectionOf(e.target);
    if (el === hoverEl) { return; }
    hoverEl = el;
    schedule();
    if (el) { post({ type: 'st:hover', sid: sidOf(el) }); }
  }, true);
  doc.documentElement.addEventListener('mouseleave', function () {
    hoverEl = null;
    schedule();
  });

  // Inside a section, a click selects it (and starts text editing on a
  // [data-studio-text]); links, buttons and forms don't act. Clicks anywhere
  // else (commerce sheets, overlays) pass through untouched.
  doc.addEventListener('click', function (e) {
    if (inChrome(e.target)) { return; }
    var el = sectionOf(e.target);
    if (!el) { return; }
    var text = e.target.closest ? e.target.closest('[data-studio-text]') : null;
    if (text && editing && editing.el === text) {
      return; // caret placement inside the text being edited
    }
    e.preventDefault();
    e.stopPropagation();
    var block = e.target.closest ? e.target.closest('[data-studio-block]') : null;
    var blockId = block && el.contains(block) ? block.getAttribute('data-studio-block') : undefined;
    if (el !== sel) {
      select(el, { blockId: blockId });
    } else if (blockId) {
      post({ type: 'st:select', sid: sidOf(el), blockId: blockId });
    }
    if (text && el.contains(text)) { startEdit(text); }
  }, true);
  doc.addEventListener('submit', function (e) {
    if (sectionOf(e.target)) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);
  doc.addEventListener('dragstart', function (e) {
    if (sectionOf(e.target) && !inChrome(e.target)) { e.preventDefault(); }
  }, true);

  window.addEventListener('scroll', schedule, { passive: true, capture: true });
  window.addEventListener('resize', schedule);

  /* =============================================================== messages */
  function replaceSection(sid, html) {
    var old = findSection(sid) || doc.getElementById('mb-section-' + sid);
    if (!old || typeof html !== 'string') { return false; }
    var tpl = doc.createElement('template');
    tpl.innerHTML = html.trim();
    var fresh = null;
    var kids = tpl.content.children;
    for (var i = 0; i < kids.length; i++) {
      if (kids[i].getAttribute('data-studio-section') === sid || kids[i].id === 'mb-section-' + sid) { fresh = kids[i]; break; }
    }
    if (!fresh) { fresh = tpl.content.querySelector('[data-studio-section="' + (window.CSS && CSS.escape ? CSS.escape(sid) : sid) + '"]'); }
    if (!fresh) { return false; }
    if (editing && old.contains(editing.el)) { editing = null; }
    var wasSel = sel === old;
    var wasHover = hoverEl === old;
    old.parentNode.replaceChild(fresh, old);
    if (wasSel) { sel = fresh; renderBar(); }
    if (wasHover) { hoverEl = fresh; }
    try {
      fresh.dispatchEvent(new CustomEvent('mb:studio:replaced', { bubbles: true, detail: { sid: sid } }));
    } catch (e) { /* old browsers */ }
    schedule();
    return true;
  }

  window.addEventListener('message', function (e) {
    if (e.source !== window.parent || e.origin !== location.origin) { return; }
    var m = e.data;
    if (!m || typeof m.type !== 'string' || m.type.indexOf('st:') !== 0) { return; }
    switch (m.type) {
      case 'st:init':
        caps = m.caps && typeof m.caps === 'object' ? m.caps : {};
        labels = m.labels && typeof m.labels === 'object' ? m.labels : {};
        renderBar();
        schedule();
        break;
      case 'st:select': {
        var el = findSection(m.sid);
        select(el, { silent: true });
        break;
      }
      case 'st:scrollTo':
        scrollToSection(findSection(m.sid));
        break;
      case 'st:replace':
        if (typeof m.sid === 'string' && !replaceSection(m.sid, m.html)) {
          reload();
        }
        break;
      case 'st:vars':
        if (m.vars && typeof m.vars === 'object') {
          Object.keys(m.vars).forEach(function (k) {
            if (/^--[a-z0-9-]+$/i.test(k) && (typeof m.vars[k] === 'string' || typeof m.vars[k] === 'number')) {
              doc.documentElement.style.setProperty(k, String(m.vars[k]));
            }
          });
        }
        break;
      case 'st:css':
        if (typeof m.css === 'string' && m.css.length <= 131072 && m.css.indexOf('<') === -1) { applyCss(m.css); }
        break;
      case 'st:reload':
        reload();
        break;
    }
  });

  /* E2: the storefront CSP is nonce-based, so the preview style reuses the
     nonce this (shell-written) script was loaded with. */
  function applyCss(css) {
    var server = doc.getElementById('mb-section-styles');
    if (server && server.sheet) { server.sheet.disabled = true; }
    if (!variantStyle) {
      variantStyle = doc.createElement('style');
      variantStyle.id = 'mb-studio-section-styles';
      var nonce = (me && (me.nonce || me.getAttribute('nonce'))) || '';
      if (nonce) { variantStyle.setAttribute('nonce', nonce); }
      (doc.head || doc.documentElement).appendChild(variantStyle);
    }
    variantStyle.textContent = css;
  }

  function reload() {
    try { sessionStorage.setItem(SCROLL_KEY, String(window.scrollY || 0)); } catch (x) { /* private mode */ }
    location.reload();
  }

  function list() {
    return sections().map(function (el) { return { sid: sidOf(el), type: typeOf(el) }; });
  }

  function init() {
    rtl = (doc.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';
    buildUi();
    try {
      var y = sessionStorage.getItem(SCROLL_KEY);
      if (y !== null) {
        sessionStorage.removeItem(SCROLL_KEY);
        window.scrollTo(0, parseInt(y, 10) || 0);
      }
    } catch (x) { /* private mode */ }
    post({ type: 'st:ready', sections: list() });
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
