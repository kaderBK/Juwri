// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*!
 * Locked-template editor bridge — runs INSIDE the preview iframe (editor mode only).
 *
 * The preview is the editing surface:
 *   - text slots (<span data-lt-edit="key">) are contenteditable in place; slots the
 *     template allows to be formatted (data-lt-rich) get a bold / italic / underline /
 *     strike / link / highlight / colour toolbar
 *   - clicking any text, image, icon or section SELECTS it (outline + a small toolbar)
 *     and the editor's side panel shows its controls (typography, crop, effects…)
 *   - style changes arrive as validated values and are applied exactly the way the
 *     server renders them (CSS custom properties + data-lt-sty markers, see
 *     lt-styles.css), so the preview matches the published page
 *
 * Messages are accepted only from the parent window on the same origin. Text is
 * written as text; formatted text is rebuilt from an allow-list (never innerHTML);
 * icon markup comes from the platform's own icon library. The server re-validates
 * everything on save.
 */
(function () {
  'use strict';

  if (window.parent === window) {
    return;
  }

  var doc = document;
  var me = doc.currentScript;
  var editable = {};          // slot key → user may change its value
  var selectable = {};        // "kind:key" → user may select it (value or style)
  var caps = {};
  var tokens = {};            // merge tag → resolved value (chips)
  var styles = { text: {}, image: {}, icon: {}, section: {} };
  var t = {};
  var labels = {};            // "kind:key" → what the chip calls it (Heading, Logo…)
  var sectionInfo = {};       // section key → {copy, copyable}
  var swatchColors = [];
  var sel = null;             // {kind, key, el}
  var hoverEl = null;
  var plaintext = (function () {
    var probe = doc.createElement('span');
    probe.setAttribute('contenteditable', 'plaintext-only');
    return probe.contentEditable === 'plaintext-only';
  })();

  function post(msg) {
    parent.postMessage(msg, location.origin);
  }

  function all(selector, root) {
    return Array.prototype.slice.call((root || doc).querySelectorAll(selector));
  }

  function keysOf(el) {
    return (el.getAttribute('data-lt-t') || '').split(/\s+/).filter(Boolean);
  }

  /* ================================================================ styles */
  // Mirror of App\Services\LockedTemplates\Styles::vars() — keep in step.
  function fluidSize(px) {
    if (px <= 20) {
      return px + 'px';
    }
    var min = Math.max(16, Math.round(px * 0.6));
    var vw = String(Math.round((px / 10.24) * 1000) / 1000);
    return 'clamp(' + min + 'px, ' + vw + 'vw, ' + px + 'px)';
  }

  function pct(v) {
    return String(Math.round(v * 1000) / 1000) + '%';
  }

  var FILTERS = [['grayscale', 'grayscale(%)'], ['sepia', 'sepia(%)'], ['invert', 'invert(%)'], ['blur', 'blur(px)'], ['brightness', 'brightness(%)'], ['contrast', 'contrast(%)'], ['saturate', 'saturate(%)'], ['hue', 'hue-rotate(deg)'], ['opacity', 'opacity(%)']];

  function vars(kind, p) {
    var css = [];
    var mk = [];
    function set(name, value, marker) {
      css.push([name, value]);
      if (marker) {
        mk.push(marker);
      }
    }
    var has = function (k) { return p[k] !== undefined && p[k] !== null && p[k] !== ''; };
    if (kind === 'text') {
      if (has('size')) { set('--lt-fs', fluidSize(+p.size), 'fs'); }
      if (has('weight')) { set('--lt-fw', String(p.weight), 'fw'); }
      if (has('color')) { set('--lt-c', p.color, 'c'); }
      if (has('color_dark')) { set('--lt-cd', p.color_dark, 'cd'); }
      if (has('align')) { set('--lt-ta', p.align, 'ta'); }
      if (has('line')) { set('--lt-lh', String(p.line), 'lh'); }
      if (has('tracking')) { set('--lt-ls', p.tracking + 'px', 'ls'); }
    } else if (kind === 'image') {
      var fx = has('fx') ? +p.fx : 50;
      var fy = has('fy') ? +p.fy : 50;
      var zoom = has('zoom') ? +p.zoom : 1;
      if (has('ratio') || zoom > 1 || has('fx') || has('fy')) {
        set('--lt-op', fx + '% ' + fy + '%');
      }
      if (has('ratio')) {
        var r = String(p.ratio).split(':');
        set('--lt-ar', r[0] + ' / ' + r[1], 'crop');
      }
      if (zoom > 1) {
        var k = 1 - 1 / zoom;
        set('--lt-z', String(zoom));
        set('--lt-clip', 'inset(' + pct(fy * k) + ' ' + pct((100 - fx) * k) + ' ' + pct((100 - fy) * k) + ' ' + pct(fx * k) + ')', 'zoom');
      }
      var f = [];
      FILTERS.forEach(function (d) {
        if (has(d[0])) {
          f.push(d[1].replace(/\((.*)\)/, '(' + p[d[0]] + '$1)'));
        }
      });
      if (f.length) { set('--lt-filter', f.join(' '), 'filter'); }
      if (has('width')) { set('--lt-w', p.width + '%', 'w'); }
      if (has('align')) {
        set('--lt-ml', p.align === 'left' ? '0' : 'auto');
        set('--lt-mr', p.align === 'right' ? '0' : 'auto', 'al');
      }
      if (has('radius')) { set('--lt-rad', p.radius + 'px', 'rad'); }
    } else if (kind === 'icon') {
      if (has('size')) { set('--lt-isz', p.size + 'px', 'isz'); }
      if (has('color')) { set('--lt-ic', p.color, 'ic'); }
      if (has('color_dark')) { set('--lt-icd', p.color_dark, 'icd'); }
    } else if (kind === 'section') {
      if (has('bg')) { set('--lt-bg', p.bg, 'bg'); }
      if (has('bg_dark')) { set('--lt-bgd', p.bg_dark, 'bgd'); }
      if (has('pt')) { set('--lt-pt', p.pt + 'px', 'pt'); }
      if (has('pb')) { set('--lt-pb', p.pb + 'px', 'pb'); }
    }
    return { css: css, markers: mk };
  }

  // Re-derive one element's custom properties from every hook it carries.
  function restyle(el) {
    var css = [];
    var markers = [];
    var alt;
    function take(kind, key) {
      var p = styles[kind][key];
      if (!p) {
        return;
      }
      var v = vars(kind, p);
      css = css.concat(v.css);
      markers = markers.concat(v.markers);
      if (kind === 'image' && p.alt !== undefined && p.alt !== null) {
        alt = String(p.alt);
      }
    }
    keysOf(el).forEach(function (k) { take('text', k); });
    if (el.hasAttribute('data-lt-img')) { take('image', el.getAttribute('data-lt-img')); }
    if (el.hasAttribute('data-lt-icon')) { take('icon', el.getAttribute('data-lt-icon')); }
    if (el.hasAttribute('data-lt-section')) { take('section', el.getAttribute('data-lt-section')); }

    for (var i = el.style.length - 1; i >= 0; i--) {
      if (el.style[i].indexOf('--lt-') === 0) {
        el.style.removeProperty(el.style[i]);
      }
    }
    css.forEach(function (d) { el.style.setProperty(d[0], d[1]); });
    var uniq = markers.filter(function (m, i) { return markers.indexOf(m) === i; });
    if (uniq.length) {
      el.setAttribute('data-lt-sty', uniq.join(' '));
    } else {
      el.removeAttribute('data-lt-sty');
    }
    if (el.hasAttribute('data-lt-img')) {
      if (el.__ltAlt === undefined) {
        el.__ltAlt = el.getAttribute('alt');
      }
      if (alt !== undefined) {
        el.setAttribute('alt', alt);
      } else if (el.__ltAlt !== null) {
        el.setAttribute('alt', el.__ltAlt);
      }
    }
  }

  function hooksFor(kind, key) {
    if (kind === 'text') {
      return all('[data-lt-t]').filter(function (el) { return keysOf(el).indexOf(key) !== -1; });
    }
    var attr = { image: 'data-lt-img', icon: 'data-lt-icon', section: 'data-lt-section', item: 'data-lt-item' }[kind];
    return attr ? all('[' + attr + ']').filter(function (el) { return el.getAttribute(attr) === key; }) : [];
  }

  function setStyle(kind, key, props) {
    if (!styles[kind]) {
      return;
    }
    if (props && Object.keys(props).length) {
      styles[kind][key] = props;
    } else {
      delete styles[kind][key];
    }
    hooksFor(kind, key).forEach(restyle);
    schedule();
  }

  /* ================================================================ rich text */
  function esc(s) {
    return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; });
  }

  function escAttr(s) {
    return String(s).replace(/[&"<>]/g, function (c) { return { '&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;' }[c]; });
  }

  // Mirrors App\Services\LockedTemplates\Slots::cleanHref (server re-checks).
  function validHref(v) {
    v = String(v || '').trim();
    if (/^tel:\+?[0-9 ().-]{3,32}$/i.test(v)) {
      return true;
    }
    if (!v || v.length > 500 || /[\s"'<>`\\]/.test(v)) {
      return false;
    }
    return /^https?:\/\/[a-z0-9.-]+(:\d+)?([/?#].*)?$/i.test(v)
      || /^mailto:[^@\s]+@[^@\s]+$/i.test(v)
      || /^#[A-Za-z0-9_-]*$/.test(v)
      || /^\/(?!\/)[A-Za-z0-9._~/%?=&#-]*$/.test(v);
  }

  function toHex(c) {
    if (!c) {
      return null;
    }
    c = String(c).trim().toLowerCase();
    var m = c.match(/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,/]+([\d.]+%?))?\s*\)$/);
    if (m) {
      if (m[4] !== undefined && parseFloat(m[4]) < (m[4].indexOf('%') > -1 ? 5 : 0.05)) {
        return null;
      }
      return '#' + [m[1], m[2], m[3]].map(function (n) { return ('0' + Math.min(255, +n).toString(16)).slice(-2); }).join('');
    }
    if (/^#[0-9a-f]{6}$/.test(c)) {
      return c;
    }
    if (/^#[0-9a-f]{3}$/.test(c)) {
      return '#' + c[1] + c[1] + c[2] + c[2] + c[3] + c[3];
    }
    return null;
  }

  var MARKS = { b: 'strong', strong: 'strong', i: 'em', em: 'em', u: 'u', ins: 'u', s: 's', strike: 's', del: 's' };

  // Canonical form — mirrors App\Services\LockedTemplates\RichText::clean().
  function serialize(node, marks, inLink) {
    var out = '';
    Array.prototype.forEach.call(node.childNodes, function (c) {
      if (c.nodeType === 3) {
        out += esc(c.nodeValue.replace(/ /g, ' ').replace(/[\x00-\x1F\x7F]+/g, ' '));
        return;
      }
      if (c.nodeType !== 1) {
        return;
      }
      if (c.hasAttribute('data-lt-token')) {
        out += '{{' + c.getAttribute('data-lt-token') + '}}';
        return;
      }
      var tag = c.nodeName.toLowerCase();
      if (tag === 'br') {
        out += ' ';
        return;
      }
      if (/^(script|style|template|svg|iframe|object)$/.test(tag)) {
        return;
      }
      if (out && /^(div|p|li|h[1-6])$/.test(tag)) {
        out += ' ';
      }
      var inner = serialize(c, marks, inLink || tag === 'a');
      if (!marks || !inner || !inner.replace(/<[^>]*>/g, '').trim()) {
        out += inner;
        return;
      }
      var canon = MARKS[tag] || (/^(a|span|font|mark)$/.test(tag) ? tag : null);
      if (!canon) {
        out += inner;
        return;
      }
      // Same nesting order as the server: mark › span colour › s › u › em › strong › a.
      var st = c.style;
      var color = tag === 'font' ? toHex(c.getAttribute('color')) : toHex(st.color);
      var bg = toHex(st.backgroundColor);
      if (tag === 'mark' || bg) {
        inner = '<mark' + (bg ? ' style="background-color:' + bg + '"' : '') + '>' + inner + '</mark>';
      }
      if (color) {
        inner = '<span style="color:' + color + '">' + inner + '</span>';
      }
      var deco = (st.textDecorationLine || st.textDecoration || '').toLowerCase();
      var w = st.fontWeight;
      if (canon === 's' || deco.indexOf('line-through') !== -1) { inner = '<s>' + inner + '</s>'; }
      if (canon === 'u' || deco.indexOf('underline') !== -1) { inner = '<u>' + inner + '</u>'; }
      if (canon === 'em' || st.fontStyle === 'italic') { inner = '<em>' + inner + '</em>'; }
      if (canon === 'strong' || w === 'bold' || w === 'bolder' || (+w >= 600)) { inner = '<strong>' + inner + '</strong>'; }
      if (tag === 'a') {
        var href = c.getAttribute('href');
        if (!inLink && validHref(href)) {
          inner = '<a href="' + escAttr(href.trim()) + '"' + (c.getAttribute('target') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + inner + '</a>';
        }
      }
      out += inner;
    });
    return out;
  }

  function trimRich(html) {
    return html.replace(/^((?:<[^>]+>)*)\s+/, '$1').replace(/\s+((?:<\/[^>]+>)*)$/, '$1');
  }

  function valueOf(span) {
    var rich = span.hasAttribute('data-lt-rich');
    if (rich) {
      return trimRich(serialize(span, !!caps.text_format, false));
    }
    return serializePlain(span).replace(/\s*\n\s*/g, ' ');
  }

  function serializePlain(node) {
    var out = '';
    Array.prototype.forEach.call(node.childNodes, function (c) {
      if (c.nodeType === 3) {
        out += c.nodeValue;
      } else if (c.nodeType === 1) {
        out += c.hasAttribute('data-lt-token') ? '{{' + c.getAttribute('data-lt-token') + '}}' : serializePlain(c);
      }
    });
    return out;
  }

  // Build DOM for a (canonical) rich value from an allow-list — never innerHTML on
  // the live page. Merge tags become chips showing their live value.
  function buildRich(target, html, marks) {
    var parsed = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html').body.firstChild;
    target.textContent = '';
    (function copy(from, to, inLink) {
      Array.prototype.forEach.call(from.childNodes, function (c) {
        if (c.nodeType === 3) {
          appendText(to, c.nodeValue);
          return;
        }
        if (c.nodeType !== 1) {
          return;
        }
        var tag = c.nodeName.toLowerCase();
        var node = null;
        if (marks && MARKS[tag]) {
          node = doc.createElement(MARKS[tag]);
        } else if (marks && tag === 'a' && !inLink && validHref(c.getAttribute('href'))) {
          node = doc.createElement('a');
          node.setAttribute('href', c.getAttribute('href').trim());
          if (c.getAttribute('target') === '_blank') {
            node.setAttribute('target', '_blank');
            node.setAttribute('rel', 'noopener noreferrer');
          }
        } else if (marks && (tag === 'span' || tag === 'mark')) {
          var color = toHex(c.style.color);
          var bg = toHex(c.style.backgroundColor);
          node = doc.createElement(tag);
          if (tag === 'span' && color) { node.style.color = color; }
          if (bg) { node.style.backgroundColor = bg; }
        }
        if (node) {
          copy(c, node, inLink || tag === 'a');
          to.appendChild(node);
        } else {
          copy(c, to, inLink);
        }
      });
    })(parsed, target, false);
  }

  function appendText(to, text) {
    var parts = String(text).split(/(\{\{[a-z.]+\}\})/);
    parts.forEach(function (part, i) {
      var m = i % 2 === 1 ? part.match(/^\{\{([a-z.]+)\}\}$/) : null;
      if (m && Object.prototype.hasOwnProperty.call(tokens, m[1])) {
        var chip = doc.createElement('span');
        chip.setAttribute('data-lt-token', m[1]);
        chip.setAttribute('contenteditable', 'false');
        chip.textContent = tokens[m[1]];
        to.appendChild(chip);
      } else if (part) {
        to.appendChild(doc.createTextNode(part));
      }
    });
  }

  /* ================================================================ inline text */
  function armEditables() {
    all('[data-lt-edit]').forEach(function (el) {
      var key = el.getAttribute('data-lt-edit');
      // Text inside a <button> can't take a caret in browsers → edited in the panel.
      if (!editable[key] || el.closest('button')) {
        el.removeAttribute('contenteditable');
        return;
      }
      var rich = el.hasAttribute('data-lt-rich') && caps.text_format;
      el.setAttribute('contenteditable', rich || !plaintext ? 'true' : 'plaintext-only');
      el.setAttribute('spellcheck', 'false');
    });
    all('[data-lt-token]').forEach(function (chip) {
      chip.style.textDecoration = 'underline dotted';
      chip.style.textUnderlineOffset = '0.2em';
    });
    all('a').forEach(function (a) { a.setAttribute('draggable', 'false'); });
  }

  function emitEdit(span) {
    var key = span.getAttribute('data-lt-edit');
    var value = valueOf(span);
    all('[data-lt-edit]').forEach(function (el) {
      if (el !== span && el.getAttribute('data-lt-edit') === key) {
        paint(el, value);
      }
    });
    post({ type: 'lt:edit', key: key, value: value });
  }

  function paint(span, value) {
    if (span.hasAttribute('data-lt-rich')) {
      buildRich(span, value, true);
    } else {
      span.textContent = '';
      appendText(span, value);
    }
  }

  doc.addEventListener('input', function (e) {
    var span = e.target.closest && e.target.closest('[data-lt-edit]');
    if (span) {
      emitEdit(span);
      schedule();
    }
  });

  doc.addEventListener('keydown', function (e) {
    var span = e.target.closest && e.target.closest('[data-lt-edit][contenteditable]');
    if (span && (e.key === 'Enter' || e.key === 'Escape')) {
      e.preventDefault();
      span.blur();
      return;
    }
    if (span && (e.metaKey || e.ctrlKey) && span.hasAttribute('data-lt-rich') && caps.text_format) {
      var k = e.key.toLowerCase();
      var cmd = { b: 'bold', i: 'italic', u: 'underline' }[k];
      if (cmd) {
        e.preventDefault();
        format(cmd);
      } else if (k === 'k') {
        e.preventDefault();
        openLink();
      }
    }
    if (!span && e.key === 'Escape' && sel) {
      select(null);
    }
  });

  // Paste is always plain text (no foreign markup, no styles from other sites).
  doc.addEventListener('paste', function (e) {
    var span = e.target.closest && e.target.closest('[data-lt-edit][contenteditable]');
    if (!span || span.getAttribute('contenteditable') === 'plaintext-only') {
      return;
    }
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text/plain').replace(/\s*\n\s*/g, ' ');
    doc.execCommand('insertText', false, text);
  });

  doc.addEventListener('focusin', function (e) {
    var span = e.target.closest && e.target.closest('[data-lt-edit][contenteditable]');
    if (span) {
      var host = span.closest('[data-lt-t]') || span;
      select({ kind: 'text', key: span.getAttribute('data-lt-edit'), el: host, span: span });
    }
  });

  doc.addEventListener('focusout', function () {
    setTimeout(updateFormatBar, 0);
  });

  doc.addEventListener('selectionchange', function () {
    updateFormatBar();
  });

  /* ================================================================ selection */
  function sectionOf(el) {
    var s = el && el.closest('[data-lt-section]');
    return s ? s.getAttribute('data-lt-section') : null;
  }

  function targetFor(node) {
    if (!node || !node.closest || node.closest('lt-editor-ui')) {
      return null;
    }
    var icon = node.closest('[data-lt-icon]');
    if (icon && selectable['icon:' + icon.getAttribute('data-lt-icon')]) {
      return { kind: 'icon', key: icon.getAttribute('data-lt-icon'), el: icon };
    }
    var img = node.closest('img[data-lt-img]');
    if (img && selectable['image:' + img.getAttribute('data-lt-img')]) {
      return { kind: 'image', key: img.getAttribute('data-lt-img'), el: img };
    }
    var span = node.closest('[data-lt-edit]');
    if (span && selectable['text:' + span.getAttribute('data-lt-edit')]) {
      return { kind: 'text', key: span.getAttribute('data-lt-edit'), el: span.closest('[data-lt-t]') || span, span: span };
    }
    var host = node.closest('[data-lt-t]');
    if (host) {
      var key = keysOf(host).filter(function (k) { return selectable['text:' + k]; })[0];
      if (key) {
        return { kind: 'text', key: key, el: host };
      }
    }
    var item = node.closest('[data-lt-item]');
    if (item && caps.items) {
      return { kind: 'item', key: item.getAttribute('data-lt-item'), el: item };
    }
    var sec = node.closest('[data-lt-section]');
    if (sec && selectable['section:' + sec.getAttribute('data-lt-section')]) {
      return { kind: 'section', key: sec.getAttribute('data-lt-section'), el: sec };
    }
    return null;
  }

  function itemOf(el) {
    var it = el && el.closest('[data-lt-item]');
    return it ? it.getAttribute('data-lt-item') : null;
  }

  function boxOf(el) {
    // Icon hooks are display:contents (no box) — measure what they wrap.
    var target = el && el.hasAttribute('data-lt-icon') ? el.firstElementChild || el : el;
    if (!target) {
      return null;
    }
    var r = target.getBoundingClientRect();
    // A zoomed image is scaled around its focus point and clipped back to its own
    // box: outline that box, not the scaled one.
    var cs = getComputedStyle(target);
    var z = parseFloat(cs.scale);
    if (z && z !== 1 && target.offsetWidth) {
      var o = cs.transformOrigin.split(' ').map(parseFloat);
      var left = r.left + o[0] * (z - 1);
      var top = r.top + o[1] * (z - 1);
      return { left: left, top: top, width: target.offsetWidth, height: target.offsetHeight, right: left + target.offsetWidth, bottom: top + target.offsetHeight };
    }
    return r;
  }

  function linkOf(el) {
    var a = el && el.closest('[data-lt-a]');
    return a ? a.getAttribute('data-lt-a') : null;
  }

  function computed(kind, el) {
    var target = kind === 'icon' ? (el.firstElementChild || el) : el;
    var cs = getComputedStyle(target);
    if (kind === 'text') {
      var fs = parseFloat(cs.fontSize) || 16;
      var lh = cs.lineHeight === 'normal' ? null : parseFloat(cs.lineHeight) / fs;
      var align = cs.textAlign;
      if (align === 'start') { align = cs.direction === 'rtl' ? 'right' : 'left'; }
      if (align === 'end') { align = cs.direction === 'rtl' ? 'left' : 'right'; }
      if (align.indexOf('center') !== -1) { align = 'center'; }
      return {
        size: Math.round(fs),
        weight: parseInt(cs.fontWeight, 10) || 400,
        color: toHex(cs.color),
        align: align,
        line: lh ? Math.round(lh * 100) / 100 : null,
        tracking: cs.letterSpacing === 'normal' ? 0 : Math.round(parseFloat(cs.letterSpacing) * 10) / 10,
      };
    }
    if (kind === 'image') {
      var r = el.getBoundingClientRect();
      var pr = el.parentElement ? el.parentElement.getBoundingClientRect() : r;
      return {
        src: el.currentSrc || el.src,
        naturalWidth: el.naturalWidth,
        naturalHeight: el.naturalHeight,
        width: pr.width ? Math.min(100, Math.round((r.width / pr.width) * 100)) : 100,
        alt: el.getAttribute('alt') || '',
      };
    }
    if (kind === 'icon') {
      var ir = target.getBoundingClientRect();
      return { size: Math.round(ir.height), color: toHex(cs.color) };
    }
    if (kind === 'item') {
      return {};
    }
    return { bg: toHex(cs.backgroundColor), pt: Math.round(parseFloat(cs.paddingTop) || 0), pb: Math.round(parseFloat(cs.paddingBottom) || 0) };
  }

  function select(target, opts) {
    opts = opts || {};
    sel = target;
    if (!target) {
      ui.sel.classList.remove('on');
      ui.chip.classList.remove('on');
      updateFormatBar();
      if (!opts.silent) {
        post({ type: 'lt:deselect' });
      }
      return;
    }
    ui.sel.classList.toggle('section', target.kind === 'section');
    var active = doc.activeElement;
    if (target.kind !== 'text' && active && active.hasAttribute && active.hasAttribute('data-lt-edit')) {
      active.blur();              // selecting a card / image / section ends text editing
    }
    renderChip();
    place();
    if (!opts.silent) {
      reportSelection();
    }
    updateFormatBar();
  }

  function reportSelection() {
    if (!sel) {
      return;
    }
    post({
      type: 'lt:select',
      kind: sel.kind,
      key: sel.key,
      section: sel.kind === 'section' ? sel.key : sectionOf(sel.el),
      link: sel.kind === 'text' ? linkOf(sel.el) : (sel.kind === 'image' ? linkOf(sel.el) : null),
      rich: !!(sel.span && sel.span.hasAttribute('data-lt-rich')),
      item: sel.kind === 'item' ? sel.key : (sel.kind === 'section' ? null : (sel.el.parentElement ? itemOf(sel.el) : null)),
      computed: computed(sel.kind, sel.el),
    });
  }

  function selectKey(kind, key) {
    var el = null;
    if (kind === 'text') {
      var span = all('[data-lt-edit]').filter(function (s) { return s.getAttribute('data-lt-edit') === key; })[0];
      el = span ? (span.closest('[data-lt-t]') || span) : hooksFor('text', key)[0];
      if (el) {
        scrollToEl(el, 'center');
        select({ kind: 'text', key: key, el: el, span: span || null }, { silent: true });
      }
      return;
    }
    el = hooksFor(kind, key)[0];
    if (el) {
      scrollToEl(boxOf(el) && el.hasAttribute('data-lt-icon') ? el.firstElementChild : el, kind === 'section' ? 'start' : 'center');
      select({ kind: kind, key: key, el: el }, { silent: true });
    }
  }

  doc.addEventListener('mouseover', function (e) {
    var target = targetFor(e.target);
    var el = target ? target.el : null;
    if (el === hoverEl) {
      return;
    }
    hoverEl = el;
    schedule();
  });

  doc.documentElement.addEventListener('mouseleave', function () {
    hoverEl = null;
    schedule();
  });

  // Editing canvas: links/forms never navigate or submit; a click selects.
  doc.addEventListener('click', function (e) {
    if (!e.target.closest || e.target.closest('lt-editor-ui')) {
      return;
    }
    if (e.target.closest('a, button, form, input, textarea, select, label')) {
      e.preventDefault();
    }
    var target = targetFor(e.target);
    // Typing into editable text: the caret works (no preventDefault); focusin usually
    // selected it already.
    if (target && target.kind === 'text' && e.target.closest('[data-lt-edit][contenteditable]')) {
      if (!sel || sel.el !== target.el || sel.kind !== 'text') {
        select(target);
      }
      return;
    }
    if (target) {
      e.preventDefault();
    }
    select(target);
  }, true);
  doc.addEventListener('submit', function (e) { e.preventDefault(); }, true);

  /* ================================================================ overlay UI */
  var ui = {};

  function h(tag, attrs, kids) {
    var node = doc.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') {
        node.textContent = attrs[k];
      } else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
        node.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
      }
    });
    (kids || []).forEach(function (c) { if (c) { node.appendChild(c); } });
    return node;
  }

  var ICONS = {
    up: 'M12 19V5 M5 12l7-7 7 7',
    upload: 'M12 16V4 M7 9l5-5 5 5 M4 20h16',
    crop: 'M6 2v14a2 2 0 0 0 2 2h14 M18 22V8a2 2 0 0 0-2-2H2',
    effects: 'M15 4V2 M15 16v-2 M8 9h2 M20 9h2 M17.8 11.8 19 13 M15 9h.01 M17.8 6.2 19 5 M3 21l9-9 M12.2 6.2 11 5',
    resize: 'M15 3h6v6 M9 21H3v-6 M21 3l-7 7 M3 21l7-7',
    icon: 'M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.5 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z',
    link: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7 M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
    highlight: 'M9 11l-6 6v3h9l3-3 M22 12l-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4',
    color: 'M4 20h16 M6 16l6-12 6 12 M8.5 11h7',
    clear: 'M4 7V4h16v3 M5 20h6 M13 4 8 20 M15 15l5 5 M20 15l-5 5',
    duplicate: 'M8 8h11a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1z M16 8V5a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h4',
    trash: 'M3 6h18 M8 6V4h8v2 M19 6l-1 14H6L5 6 M10 11v6 M14 11v6',
    plus: 'M12 5v14 M5 12h14',
    prev: 'M15 18l-6-6 6-6',
    next: 'M9 18l6-6-6-6',
  };

  function svgIcon(name) {
    var svg = doc.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    ICONS[name].split(' M').forEach(function (d, i) {
      var p = doc.createElementNS('http://www.w3.org/2000/svg', 'path');
      p.setAttribute('d', (i ? 'M' : '') + d);
      p.setAttribute('fill', 'none');
      p.setAttribute('stroke', 'currentColor');
      p.setAttribute('stroke-width', '2');
      p.setAttribute('stroke-linecap', 'round');
      p.setAttribute('stroke-linejoin', 'round');
      svg.appendChild(p);
    });
    return svg;
  }

  function btn(label, content, onClick, extra) {
    var b = h('button', Object.assign({ type: 'button', title: label, 'aria-label': label }, extra || {}));
    if (typeof content === 'string' && ICONS[content]) {
      b.appendChild(svgIcon(content));
    } else if (typeof content === 'string') {
      b.textContent = content;
    } else if (content) {
      b.appendChild(content);
    }
    // mousedown: keep the text selection inside the editable while clicking.
    b.addEventListener('mousedown', function (e) { e.preventDefault(); });
    b.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      onClick(e);
    });
    return b;
  }

  function buildUi() {
    var hostEl = doc.createElement('lt-editor-ui');
    hostEl.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2147483646;display:block;';
    var root = hostEl.attachShadow({ mode: 'open' });
    if (me && me.src) {
      root.appendChild(h('link', { rel: 'stylesheet', href: me.src.replace(/editor-bridge\.js/, 'editor-bridge.css') }));
    }
    ui.hover = h('div', { class: 'box hover' });
    ui.sel = h('div', { class: 'box sel' });
    ui.chip = h('div', { class: 'bar', role: 'toolbar' });
    ui.fmt = h('div', { class: 'bar', role: 'toolbar' });
    ui.pop = h('div', { class: 'pop', role: 'dialog' });
    [ui.hover, ui.sel, ui.chip, ui.fmt, ui.pop].forEach(function (n) { root.appendChild(n); });
    ui.pop.addEventListener('mousedown', function (e) {
      if (e.target.nodeName !== 'INPUT') {
        e.preventDefault();
      }
    });
    doc.body.appendChild(hostEl);
  }

  function renderChip() {
    ui.chip.textContent = '';
    if (!sel) {
      return;
    }
    var names = { text: t.text || 'Text', image: t.image || 'Image', icon: t.icon || 'Icon', section: t.section || 'Section', item: t.item || 'Item' };
    ui.chip.appendChild(h('span', { class: 'label', text: labels[sel.kind + ':' + sel.key] || names[sel.kind] }));
    var tools = [];
    var key = sel.key;
    if (sel.kind === 'item') {
      // Previous / next follow reading order (the page may be right-to-left).
      var rtl = getComputedStyle(sel.el).direction === 'rtl';
      tools.push(btn(t.duplicate || 'Duplicate', 'duplicate', function () { post({ type: 'lt:itemAction', item: key, action: 'duplicate' }); }));
      tools.push(btn(t.moveBack || 'Move back', rtl ? 'next' : 'prev', function () { post({ type: 'lt:itemAction', item: key, action: 'up' }); }));
      tools.push(btn(t.moveForward || 'Move forward', rtl ? 'prev' : 'next', function () { post({ type: 'lt:itemAction', item: key, action: 'down' }); }));
      tools.push(btn(t.remove || 'Remove', 'trash', function () { post({ type: 'lt:itemAction', item: key, action: 'remove' }); }));
    }
    if (sel.kind === 'section' && sectionInfo[key]) {
      var info = sectionInfo[key];
      if (caps.sections_duplicate && info.copyable) {
        tools.push(btn(t.duplicateSection || 'Duplicate section', 'duplicate', function () { post({ type: 'lt:sectionAction', key: key, action: 'duplicate' }); }));
      }
      if (info.canAdd) {
        tools.push(btn(t.addSectionBelow || 'Add a section below', 'plus', function () { post({ type: 'lt:sectionAction', key: key, action: 'add' }); }));
      }
      if (info.copy && (info.library ? caps.sections_add : caps.sections_duplicate)) {
        tools.push(btn(info.library ? (t.removeSection || 'Remove section') : (t.deleteSection || 'Delete this copy'), 'trash', function () { post({ type: 'lt:sectionAction', key: key, action: 'delete' }); }));
      }
    }
    if (sel.kind === 'image') {
      if (caps.image) { tools.push(btn(t.replace || 'Replace', 'upload', function () { post({ type: 'lt:tool', kind: 'image', key: sel.key, tool: 'replace' }); })); }
      if (caps.image_style) {
        tools.push(btn(t.crop || 'Crop', 'crop', function () { post({ type: 'lt:tool', kind: 'image', key: sel.key, tool: 'crop' }); }));
        tools.push(btn(t.effects || 'Effects', 'effects', function () { post({ type: 'lt:tool', kind: 'image', key: sel.key, tool: 'effects' }); }));
        tools.push(btn(t.size || 'Size', 'resize', function () { post({ type: 'lt:tool', kind: 'image', key: sel.key, tool: 'size' }); }));
      }
    } else if (sel.kind === 'icon' && caps.icon) {
      tools.push(btn(t.changeIcon || 'Change icon', 'icon', function () { post({ type: 'lt:tool', kind: 'icon', key: sel.key, tool: 'icon' }); }));
    }
    // One level up: the item an element sits in, else its section.
    var parentItem = sel.kind !== 'item' && sel.kind !== 'section' && caps.items && sel.el.parentElement ? itemOf(sel.el) : null;
    if (parentItem) {
      tools.push(btn(t.selectItem || 'Select item', 'up', function () { selectKey('item', parentItem); reportSelection(); }));
    } else if (sel.kind !== 'section') {
      var sk = sectionOf(sel.el);
      if (sk && selectable['section:' + sk]) {
        tools.push(btn(t.selectSection || 'Select section', 'up', function () { selectKey('section', sk); reportSelection(); }));
      }
    }
    if (tools.length) {
      ui.chip.appendChild(h('span', { class: 'sep' }));
      tools.forEach(function (b) { ui.chip.appendChild(b); });
    }
    ui.chip.classList.add('on');
  }

  /* ---- formatting toolbar (rich text slots) */
  var FORMAT = [
    ['bold', 'B', 'bold', { style: 'font-weight:800' }],
    ['italic', 'I', 'italic', { style: 'font-style:italic;font-family:Georgia,serif' }],
    ['underline', 'U', 'underline', { style: 'text-decoration:underline' }],
    ['strikeThrough', 'S', 'strike', { style: 'text-decoration:line-through' }],
  ];
  var savedRange = null;

  function activeSpan() {
    var a = doc.activeElement;
    return a && a.hasAttribute && a.hasAttribute('data-lt-edit') && a.hasAttribute('data-lt-rich') && a.isContentEditable && caps.text_format ? a : null;
  }

  function buildFormatBar() {
    ui.fmt.textContent = '';
    ui.fmtButtons = {};
    FORMAT.forEach(function (f) {
      var b = btn(t[f[2]] || f[0], f[1], function () { format(f[0]); }, { 'aria-pressed': 'false', style: f[3].style });
      ui.fmtButtons[f[0]] = b;
      ui.fmt.appendChild(b);
    });
    ui.fmt.appendChild(h('span', { class: 'sep' }));
    ui.fmt.appendChild(btn(t.link || 'Link', 'link', openLink));
    ui.fmt.appendChild(btn(t.highlight || 'Highlight', 'highlight', function () { openColor('hiliteColor'); }));
    ui.fmt.appendChild(btn(t.textColor || 'Text colour', 'color', function () { openColor('foreColor'); }));
    ui.fmt.appendChild(h('span', { class: 'sep' }));
    ui.fmt.appendChild(btn(t.clearFormat || 'Clear formatting', 'clear', function () {
      restoreRange();
      doc.execCommand('removeFormat');
      doc.execCommand('unlink');
      afterCommand();
    }));
  }

  function updateFormatBar() {
    var span = activeSpan();
    if (!span) {
      if (!ui.pop.classList.contains('on')) {
        ui.fmt.classList.remove('on');
      }
      return;
    }
    Object.keys(ui.fmtButtons || {}).forEach(function (cmd) {
      var on = false;
      try {
        on = doc.queryCommandState(cmd);
      } catch (err) {
        on = false;
      }
      ui.fmtButtons[cmd].setAttribute('aria-pressed', String(!!on));
    });
    ui.fmt.classList.add('on');
    schedule();
  }

  function saveRange() {
    var s = window.getSelection();
    var span = activeSpan();
    savedRange = s && s.rangeCount && span && span.contains(s.anchorNode) ? { span: span, range: s.getRangeAt(0).cloneRange() } : savedRange;
  }

  function restoreRange() {
    if (!savedRange || !savedRange.span.isConnected) {
      return;
    }
    savedRange.span.focus({ preventScroll: true });
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(savedRange.range);
  }

  function afterCommand() {
    var span = savedRange && savedRange.span.isConnected ? savedRange.span : activeSpan();
    if (span) {
      emitEdit(span);
    }
    updateFormatBar();
  }

  function format(cmd) {
    saveRange();
    doc.execCommand('styleWithCSS', false, false);
    doc.execCommand(cmd);
    afterCommand();
  }

  function closePop() {
    ui.pop.classList.remove('on');
    ui.pop.textContent = '';
  }

  function openLink() {
    saveRange();
    if (!savedRange) {
      return;
    }
    var current = savedRange.range.commonAncestorContainer;
    var a = (current.nodeType === 1 ? current : current.parentNode).closest('a');
    var input = h('input', { type: 'url', dir: 'ltr', placeholder: 'https://…', 'aria-label': t.linkAddress || 'Link address', spellcheck: 'false' });
    input.value = a ? a.getAttribute('href') : '';
    var blank = h('input', { type: 'checkbox' });
    blank.checked = !!(a && a.getAttribute('target') === '_blank');
    var err = h('p', { class: 'err', text: t.badLink || 'Use a full address (https://…), mailto:, tel:, /page or #section.' });
    var apply = function () {
      var v = input.value.trim();
      if (!validHref(v)) {
        err.classList.add('on');
        input.setAttribute('aria-invalid', 'true');
        return;
      }
      restoreRange();
      doc.execCommand('createLink', false, v);
      var span = savedRange.span;
      all('a', span).forEach(function (link) {
        if (link.getAttribute('href') === v) {
          if (blank.checked) {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');
          } else {
            link.removeAttribute('target');
            link.removeAttribute('rel');
          }
        }
      });
      closePop();
      afterCommand();
    };
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        apply();
      } else if (e.key === 'Escape') {
        closePop();
        restoreRange();
      }
    });
    ui.pop.textContent = '';
    ui.pop.appendChild(h('div', { class: 'pop-head' }, [
      h('span', { text: t.link || 'Link' }),
      a ? btn(t.removeLink || 'Remove link', t.removeLink || 'Remove', function () { restoreRange(); doc.execCommand('unlink'); closePop(); afterCommand(); }) : null,
    ]));
    ui.pop.appendChild(h('div', { class: 'row' }, [input, btn(t.apply || 'Apply', t.apply || 'Apply', apply, { class: 'primary' })]));
    ui.pop.appendChild(h('label', { class: 'check' }, [blank, h('span', { text: t.newTab || 'Open in a new tab' })]));
    ui.pop.appendChild(err);
    showPop();
    input.focus();
  }

  function openColor(cmd) {
    saveRange();
    if (!savedRange) {
      return;
    }
    var hex = h('input', { type: 'text', dir: 'ltr', placeholder: '#RRGGBB', maxlength: '7', 'aria-label': 'Hex', spellcheck: 'false' });
    var applyColor = function (v) {
      restoreRange();
      doc.execCommand('styleWithCSS', false, true);
      doc.execCommand(cmd, false, v);
      doc.execCommand('styleWithCSS', false, false);
      closePop();
      afterCommand();
    };
    var grid = h('div', { class: 'swatches' });
    swatchColors.forEach(function (c) {
      var s = btn(c, '', function () { applyColor(c); }, { class: 'swatch', style: 'background:' + c });
      grid.appendChild(s);
    });
    hex.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var v = toHex(hex.value.trim());
        if (v) {
          applyColor(v);
        } else {
          hex.setAttribute('aria-invalid', 'true');
        }
      } else if (e.key === 'Escape') {
        closePop();
        restoreRange();
      }
    });
    ui.pop.textContent = '';
    ui.pop.appendChild(h('div', { class: 'pop-head' }, [
      h('span', { text: cmd === 'hiliteColor' ? (t.highlight || 'Highlight') : (t.textColor || 'Text colour') }),
      btn(t.none || 'None', t.none || 'None', function () { applyColor(cmd === 'hiliteColor' ? 'transparent' : 'inherit'); }),
    ]));
    ui.pop.appendChild(grid);
    ui.pop.appendChild(h('div', { class: 'row' }, [hex, btn(t.apply || 'Apply', t.apply || 'Apply', function () {
      var v = toHex(hex.value.trim());
      if (v) {
        applyColor(v);
      } else {
        hex.setAttribute('aria-invalid', 'true');
      }
    }, { class: 'primary' })]));
    showPop();
  }

  function showPop() {
    ui.pop.classList.add('on');
    var r = ui.fmt.getBoundingClientRect();
    var w = 248;
    var left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left));
    var top = r.bottom + 6;
    if (top + 220 > window.innerHeight) {
      top = Math.max(8, r.top - 6 - ui.pop.offsetHeight);
    }
    ui.pop.style.left = left + 'px';
    ui.pop.style.top = top + 'px';
  }

  doc.addEventListener('mousedown', function (e) {
    if (!e.target.closest || e.target.closest('lt-editor-ui')) {
      return;
    }
    if (ui.pop && ui.pop.classList.contains('on')) {
      closePop();
    }
    // Outside editable text a press only selects: no caret dropped into nearby text,
    // no native drag of images/links.
    if (!e.target.closest('[data-lt-edit][contenteditable]')) {
      e.preventDefault();
      var a = doc.activeElement;
      if (a && a.hasAttribute && a.hasAttribute('data-lt-edit')) {
        a.blur();
      }
    }
  }, true);

  /* ---- positioning (one rAF per change; scroll/resize/animations keep it glued) */
  var queued = false;

  function schedule() {
    if (!queued) {
      queued = true;
      requestAnimationFrame(function () {
        queued = false;
        place();
      });
    }
  }

  function setBox(node, r) {
    if (!r || (!r.width && !r.height)) {
      node.classList.remove('on');
      return;
    }
    node.style.left = r.left + 'px';
    node.style.top = r.top + 'px';
    node.style.width = r.width + 'px';
    node.style.height = r.height + 'px';
    node.classList.add('on');
  }

  function place() {
    if (!ui.sel) {
      return;
    }
    var selRect = sel && sel.el && sel.el.isConnected ? boxOf(sel.el) : null;
    setBox(ui.sel, selRect);
    setBox(ui.hover, hoverEl && (!sel || hoverEl !== sel.el) ? boxOf(hoverEl) : null);

    if (selRect && ui.chip.classList.contains('on')) {
      var cw = ui.chip.offsetWidth;
      var ch = ui.chip.offsetHeight;
      var top = sel.kind === 'section' ? Math.max(6, selRect.top + 6) : selRect.top - ch - 8;
      if (top < 6) {
        top = Math.min(window.innerHeight - ch - 6, selRect.bottom + 8);
      }
      ui.chip.style.left = Math.max(6, Math.min(window.innerWidth - cw - 6, selRect.left + (sel.kind === 'section' ? 6 : 0))) + 'px';
      ui.chip.style.top = top + 'px';
    }
    if (ui.fmt.classList.contains('on')) {
      var span = activeSpan() || (savedRange && savedRange.span);
      var r = span ? (span.closest('[data-lt-t]') || span).getBoundingClientRect() : null;
      if (r) {
        var fw = ui.fmt.offsetWidth;
        var fh = ui.fmt.offsetHeight;
        var ft = r.top - fh - 8 - (selRect && ui.chip.classList.contains('on') && Math.abs(selRect.top - r.top) < 4 ? ui.chip.offsetHeight + 6 : 0);
        if (ft < 6) {
          ft = Math.min(window.innerHeight - fh - 6, r.bottom + 8);
        }
        ui.fmt.style.left = Math.max(6, Math.min(window.innerWidth - fw - 6, r.left + r.width / 2 - fw / 2)) + 'px';
        ui.fmt.style.top = ft + 'px';
      }
    }
  }

  window.addEventListener('scroll', schedule, { passive: true, capture: true });
  window.addEventListener('resize', schedule);
  // Runtime animations and Swiper move things without scroll events: follow while something is shown.
  (function follow() {
    if (sel || hoverEl) {
      place();
    }
    setTimeout(function () { requestAnimationFrame(follow); }, 120);
  })();

  /* ================================================================ panel → canvas */
  function setText(key, value) {
    all('[data-lt-edit]').forEach(function (el) {
      if (el.getAttribute('data-lt-edit') === key && el !== doc.activeElement) {
        paint(el, value);
      }
    });
    all('[data-lt-ph]').forEach(function (el) {
      if (el.getAttribute('data-lt-ph') === key) {
        el.setAttribute('placeholder', value);
      }
    });
    schedule();
  }

  function setImage(key, value) {
    all('[data-lt-img]').forEach(function (img) {
      if (img.getAttribute('data-lt-img') === key) {
        img.removeAttribute('srcset');
        img.src = value;
      }
    });
    schedule();
  }

  function setIcon(key, markup) {
    var node = null;
    if (markup) {
      // Library icons are SVG; the template's original may be an icon-font <span>.
      // Parse inertly (never innerHTML on the live page) and import the element.
      var isSvg = markup.indexOf('<svg') === 0;
      var parsedDoc = new DOMParser().parseFromString(markup, isSvg ? 'image/svg+xml' : 'text/html');
      node = isSvg ? parsedDoc.documentElement : parsedDoc.body.firstElementChild;
      if (!node || node.nodeName === 'parsererror' || node.querySelector('script, foreignObject')) {
        return;
      }
    }
    all('[data-lt-icon]').forEach(function (wrap) {
      if (wrap.getAttribute('data-lt-icon') === key) {
        wrap.textContent = '';
        if (node) {
          wrap.appendChild(doc.importNode(node, true));
        }
      }
    });
    schedule();
  }

  /* ================================================================ messages */
  // Templates running Lenis (the HTML edition's own main.js) swallow native SMOOTH
  // scrolling, and Lenis's own scrollTo clamps to a page height measured before the
  // page finished growing — an instant native scroll lands exactly (Lenis follows).
  function scrollToEl(el, block) {
    block = block || 'start';
    var lenisOn = doc.documentElement.classList.contains('lenis');
    el.scrollIntoView(lenisOn ? { block: block } : { behavior: 'smooth', block: block });
  }

  // Video slot: <video src> or <source src> carrying data-lt-video; reload to play.
  function setVideo(key, url) {
    all('[data-lt-video]').forEach(function (v) {
      if ((v.getAttribute('data-lt-video') || '').split(' ').indexOf(key) === -1) {
        return;
      }
      v.setAttribute('src', url);
      var player = v.tagName === 'SOURCE' ? v.parentElement : v;
      if (player && typeof player.load === 'function') {
        player.load();
        if (player.autoplay) {
          var p = player.play();
          if (p && p.catch) {
            p.catch(function () {});
          }
        }
      }
    });
  }

  window.addEventListener('message', function (e) {
    if (e.source !== parent || e.origin !== location.origin || !e.data || typeof e.data.type !== 'string') {
      return;
    }
    var d = e.data;
    switch (d.type) {
      case 'lt:init':
        editable = {};
        selectable = {};
        (d.editable || []).forEach(function (k) { editable[k] = true; });
        (d.selectable || []).forEach(function (k) { selectable[k] = true; });
        caps = d.caps || {};
        tokens = d.tokens || {};
        t = d.i18n || {};
        labels = d.labels || {};
        sectionInfo = d.sections || {};
        swatchColors = (d.swatches || []).filter(function (c) { return /^#[0-9a-f]{6}$/i.test(c); });
        ['text', 'image', 'icon', 'section'].forEach(function (k) {
          styles[k] = (d.styles && d.styles[k]) || {};
        });
        if (!ui.sel) {
          buildUi();
        }
        buildFormatBar();
        armEditables();
        break;
      case 'lt:text':
        setText(String(d.key), String(d.value));
        break;
      case 'lt:image':
        setImage(String(d.key), String(d.value));
        break;
      case 'lt:video':
        setVideo(String(d.key), String(d.value));
        break;
      case 'lt:icon':
        setIcon(String(d.key), typeof d.svg === 'string' ? d.svg : '');
        break;
      case 'lt:style':
        setStyle(String(d.kind), String(d.key), d.props || null);
        break;
      case 'lt:selectKey':
        selectKey(String(d.kind), String(d.key));
        break;
      case 'lt:deselect':
        select(null, { silent: true });
        break;
      case 'lt:palette':
        Object.keys(d.vars || {}).forEach(function (name) {
          if (/^--[a-z0-9-]+$/.test(name)) {
            var v = d.vars[name];
            if (v === null || v === '') {
              doc.documentElement.style.removeProperty(name);
            } else {
              doc.documentElement.style.setProperty(name, String(v));
            }
          }
        });
        break;
      case 'lt:theme':
        doc.documentElement.classList.toggle('dark', d.theme === 'dark');
        doc.documentElement.style.colorScheme = d.theme === 'dark' ? 'dark' : 'light';
        schedule();
        break;
      case 'lt:scrollY':
        window.scrollTo({ top: Math.max(0, +d.y || 0), behavior: 'instant' });
        break;
      case 'lt:scrollTo':
        var el = doc.querySelector('[data-lt-section="' + String(d.section).replace(/"/g, '') + '"]');
        if (el) {
          scrollToEl(el);
        }
        break;
    }
  });

  post({ type: 'lt:ready' });
})();
