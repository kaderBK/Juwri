// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*!
 * Locked-template editor (reseller portal).
 *
 * Three panes: Content (every field, section by section), Element (the element
 * selected in the page — BuilderJS-style typography / colour / alignment for text,
 * crop & focus / effects / size for images, size & colour for icons, background &
 * spacing for sections) and Theme (brand colours, light / dark).
 *
 * The preview iframe runs editor-bridge.js (postMessage, same origin only): users
 * type, format and select right in the page. Only what the user's capabilities allow
 * is rendered or sent; the server re-applies the same capability filter and every
 * validator on each save, so this file is a convenience, not the lock.
 */
(function () {
  'use strict';

  var doc = document;
  var cfg = JSON.parse(doc.getElementById('lt-config').textContent);
  var t = cfg.i18n;
  var caps = cfg.caps || {};
  var csrf = (doc.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var content = normalize(cfg.content);
  var sections = cfg.sections.slice();
  var slotIndex = {};
  var slotSection = {};
  var repIndex = {};            // repeater key → {rep, section}
  sections.forEach(function (s) {
    s.slots.forEach(function (slot) {
      slotIndex[slot.key] = slot;
      slotSection[slot.key] = s;
    });
    (s.repeaters || []).forEach(function (rep) { repIndex[rep.key] = { rep: rep, section: s }; });
  });

  /* ---- repeated items: refs are "<n>" (template item) or "n<id>:<n>" (new item) */
  function parseRef(ref) {
    var m = /^(?:(\d{1,2})|(n[a-z0-9]{1,8}):(\d{1,2}))$/.exec(String(ref));
    return m ? { src: +(m[1] !== undefined ? m[1] : m[3]), id: m[2] || null } : null;
  }

  function itemSlots(rep, ref) {
    var p = parseRef(ref);
    if (!p || !rep.items[p.src]) {
      return [];
    }
    return rep.items[p.src].slots.map(function (k) { return p.id ? k + '~' + p.id : k; });
  }

  function newId(prefix) {
    var id;
    do {
      id = prefix + Math.random().toString(36).slice(2, 8);
    } while (JSON.stringify(content).indexOf('~' + id) !== -1);
    return id;
  }

  function addSlotConfig(section, cfg) {
    if (!slotIndex[cfg.key]) {
      section.slots.push(cfg);
    }
    slotIndex[cfg.key] = cfg;
    slotSection[cfg.key] = section;
  }

  // Drop everything a removed item / section copy owned (keys with its suffix).
  function forgetSuffix(sfx) {
    [content.slots, content.styles, content.section_styles, content.items].forEach(function (map) {
      Object.keys(map).forEach(function (k) {
        if (k.indexOf(sfx) !== -1) {
          delete map[k];
        }
      });
    });
    sections.forEach(function (s) {
      s.slots = s.slots.filter(function (x) { return x.key.indexOf(sfx) === -1; });
    });
    Object.keys(slotIndex).forEach(function (k) {
      if (k.indexOf(sfx) !== -1) {
        delete slotIndex[k];
        delete slotSection[k];
      }
    });
  }

  function itemLocation(itemKey) {
    var i = String(itemKey).lastIndexOf('|');
    var entry = repIndex[String(itemKey).slice(0, i)];
    var ref = String(itemKey).slice(i + 1);
    return entry && entry.rep.refs.indexOf(ref) !== -1 ? { rep: entry.rep, section: entry.section, ref: ref } : null;
  }

  var anyStyleCap = !!(caps.text_style || caps.image_style || caps.image || caps.section_style);
  var frame = doc.querySelector('.lte-frame');
  var frameReady = false;
  var frameQueue = [];
  var dirty = false;
  var saving = null;
  var saveTimer = null;
  var hasUnpublished = !!cfg.hasUnpublished;
  var isLive = !!cfg.isLive;
  var current = null;           // {kind, key, section, link, rich, computed} — selected in the page

  function normalize(c) {
    c = c || {};
    return {
      slots: Object.assign({}, c.slots || {}),
      styles: JSON.parse(JSON.stringify(Array.isArray(c.styles) ? {} : (c.styles || {}))),
      section_styles: JSON.parse(JSON.stringify(Array.isArray(c.section_styles) ? {} : (c.section_styles || {}))),
      palette: Object.assign({}, c.palette || {}),
      settings: Object.assign({}, c.settings || {}),
      sections: (c.sections || []).slice(),
      items: JSON.parse(JSON.stringify(Array.isArray(c.items) ? {} : (c.items || {}))),
    };
  }

  function el(tag, attrs, children) {
    var node = doc.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') {
        node.textContent = attrs[k];
      } else if (k === 'hidden') {
        node.hidden = !!attrs[k];
      } else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
        node.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
      }
    });
    (children || []).forEach(function (c) {
      if (c) {
        node.appendChild(typeof c === 'string' ? doc.createTextNode(c) : c);
      }
    });
    return node;
  }

  function escHtml(s) {
    return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; });
  }

  // Visible text of a (canonical) rich value — parsed inertly, never inserted.
  function plainOf(html) {
    return new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html').body.textContent;
  }

  function hasMarks(html) {
    return /<[a-z]/i.test(html || '');
  }

  /* ------------------------------------------------------------ preview bridge */
  function send(msg) {
    if (!frameReady) {
      frameQueue.push(msg);
      return;
    }
    frame.contentWindow.postMessage(msg, location.origin);
  }

  function editableKeys() {
    return Object.keys(slotIndex).filter(function (k) { return slotIndex[k].editable; });
  }

  function selectableKeys() {
    var out = [];
    Object.keys(slotIndex).forEach(function (k) {
      var s = slotIndex[k];
      if (s.type === 'text' && (s.editable || caps.text_style)) {
        out.push('text:' + k);
      } else if (s.type === 'image' && (s.editable || caps.image_style)) {
        out.push('image:' + k);
      } else if (s.type === 'icon' && (s.editable || caps.text_style)) {
        out.push('icon:' + k);
      }
    });
    if (caps.section_style || caps.sections_toggle || caps.sections_reorder || caps.sections_duplicate || caps.sections_add) {
      sections.forEach(function (s) { out.push('section:' + s.key); });
    }
    return out;
  }

  function stylesByKind() {
    var out = { text: {}, image: {}, icon: {}, section: {} };
    Object.keys(content.styles).forEach(function (k) {
      var s = slotIndex[k];
      if (s && out[s.type]) {
        out[s.type][k] = content.styles[k];
      }
    });
    out.section = JSON.parse(JSON.stringify(content.section_styles));
    return out;
  }

  var STANDARD = ['#000000', '#434343', '#666666', '#999999', '#cccccc', '#ffffff', '#e03131', '#f08c00', '#fcc419', '#2f9e44', '#0c8599', '#1971c2', '#5f3dc4', '#c2255c'];

  function swatches() {
    var list = cfg.palette.map(function (p) { return (content.palette[p.key] || p.default).toLowerCase(); });
    STANDARD.forEach(function (c) {
      if (list.indexOf(c) === -1) {
        list.push(c);
      }
    });
    return list.slice(0, 24);
  }

  function sectionInfo() {
    var out = {};
    sections.forEach(function (sec, i) {
      out[sec.key] = { copy: !!sec.copy, copyable: !!sec.copyable, library: !!sec.library, canAdd: canAddAfter(i) };
    });
    return out;
  }

  function initFrame() {
    var labels = {};
    Object.keys(slotIndex).forEach(function (k) {
      labels[slotIndex[k].type + ':' + k] = slotIndex[k].kindLabel || slotIndex[k].kind;
    });
    sections.forEach(function (s) { labels['section:' + s.key] = s.label; });
    frame.contentWindow.postMessage({
      type: 'lt:init',
      editable: editableKeys(),
      selectable: selectableKeys(),
      caps: caps,
      tokens: cfg.tokens || {},
      styles: stylesByKind(),
      labels: labels,
      sections: sectionInfo(),
      swatches: swatches(),
      i18n: t.bridge,
    }, location.origin);
  }

  window.addEventListener('message', function (e) {
    if (e.source !== frame.contentWindow || e.origin !== location.origin || !e.data) {
      return;
    }
    var d = e.data;
    if (d.type === 'lt:ready') {
      frameReady = true;
      initFrame();
      var focusKey = takeFocusSection();
      if (focusKey) {
        restoreY = 0;
        frameQueue.push({ type: 'lt:scrollTo', section: focusKey });
        var det = contentPane.querySelector('details[data-section="' + focusKey.replace(/"/g, '') + '"]');
        if (det) {
          det.open = true;
          det.scrollIntoView({ block: 'nearest' });
        }
      }
      if (restoreY) {
        send({ type: 'lt:scrollY', y: restoreY });
        restoreY = 0;
      }
      frameQueue.splice(0).forEach(send);
      if (current) {
        send({ type: 'lt:selectKey', kind: current.kind, key: current.key });
      }
    } else if (d.type === 'lt:select') {
      var same = current && current.kind === d.kind && current.key === d.key;
      current = { kind: String(d.kind), key: String(d.key), section: d.section ? String(d.section) : null, link: d.link ? String(d.link) : null, item: d.item ? String(d.item) : null, rich: !!d.rich, computed: d.computed || {} };
      if (!same || !inspectorLive()) {
        renderInspector();
      }
      selectTab('element');
      setView('edit');
    } else if (d.type === 'lt:deselect') {
      current = null;
      renderInspector();
    } else if (d.type === 'lt:tool') {
      runTool(String(d.kind), String(d.key), String(d.tool));
    } else if (d.type === 'lt:itemAction') {
      itemAction(String(d.item), String(d.action));
    } else if (d.type === 'lt:sectionAction') {
      sectionAction(String(d.key), String(d.action));
    } else if (d.type === 'lt:edit') {
      // Typed (or formatted) directly in the page.
      var ek = String(d.key);
      var es = slotIndex[ek];
      if (es && es.editable && es.type === 'text') {
        var v = String(d.value);
        if (v === defaultOf(es)) {
          delete content.slots[ek];
        } else {
          content.slots[ek] = v;
        }
        syncTextInputs(ek);
        changed();
      }
    }
  });

  var restoreY = 0;

  function reloadFrame() {
    try {
      restoreY = frame.contentWindow.scrollY || 0;
    } catch (err) {
      restoreY = 0;
    }
    frameReady = false;
    frameQueue = [];
    frame.src = cfg.previewUrl + (cfg.previewUrl.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
  }

  /* ------------------------------------------------------------ values */
  // Rich slots store (escaped) inline HTML; their default is the escaped template text.
  function defaultOf(slot) {
    return slot.rich ? escHtml(slot.default) : slot.default;
  }

  function valueOf(key) {
    var v = content.slots[key];
    return v === undefined ? defaultOf(slotIndex[key]) : v;
  }

  function displayValue(key) {
    var slot = slotIndex[key];
    return slot.rich ? plainOf(valueOf(key)) : valueOf(key);
  }

  function setSlot(key, value) {
    var slot = slotIndex[key];
    if (value === defaultOf(slot)) {
      delete content.slots[key];
    } else {
      content.slots[key] = value;
    }
    if (slot.type === 'image') {
      send({ type: 'lt:image', key: key, value: value });
    } else if (slot.type === 'video') {
      send({ type: 'lt:video', key: key, value: value });
    } else if (slot.type === 'icon') {
      send({ type: 'lt:icon', key: key, svg: value ? iconSvg(value, slot) : (slot.defaultHtml || '') });
    } else if (slot.type === 'text') {
      send({ type: 'lt:text', key: key, value: value });
    }
    changed();
  }

  // Panel text → slot value (rich slots take escaped text; formatting is page-only).
  function setTextFromPanel(key, text) {
    setSlot(key, slotIndex[key].rich ? escHtml(text) : text);
  }

  var textInputs = {};          // key → [input, …] (Content pane + Element pane)

  function syncTextInputs(key) {
    (textInputs[key] || []).forEach(function (input) {
      if (!input.isConnected || input === doc.activeElement) {
        return;
      }
      var v = displayValue(key);
      if (input.value !== v) {
        input.value = v;
      }
      input.dispatchEvent(new CustomEvent('lt:external'));
    });
  }

  // Mirrors App\Services\LockedTemplates\Slots::cleanHref (server re-checks).
  function validHref(v) {
    v = v.trim();
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

  function sitePath(url) {
    return url.indexOf(cfg.origin + '/') === 0 ? url.slice(cfg.origin.length) : url;
  }

  /* ------------------------------------------------------------ styles */
  var NEUTRAL = { image: { zoom: 1, fx: 50, fy: 50, grayscale: 0, sepia: 0, invert: 0, blur: 0, brightness: 100, contrast: 100, saturate: 100, hue: 0, opacity: 100 } };

  function styleMap(kind) {
    return kind === 'section' ? content.section_styles : content.styles;
  }

  function styleOf(kind, key) {
    return styleMap(kind)[key] || {};
  }

  function setStyle(kind, key, patch) {
    var map = styleMap(kind);
    var props = Object.assign({}, map[key] || {});
    Object.keys(patch).forEach(function (p) {
      var v = patch[p];
      var neutral = NEUTRAL[kind] && Object.prototype.hasOwnProperty.call(NEUTRAL[kind], p) && +v === NEUTRAL[kind][p];
      if (v === null || v === undefined || v === '' || neutral) {
        delete props[p];
      } else {
        props[p] = v;
      }
    });
    if (Object.keys(props).length) {
      map[key] = props;
    } else {
      delete map[key];
    }
    send({ type: 'lt:style', kind: kind, key: key, props: map[key] || null });
    changed();
  }

  function clearStyle(kind, key, props) {
    var patch = {};
    props.forEach(function (p) { patch[p] = null; });
    setStyle(kind, key, patch);
  }

  /* ------------------------------------------------------------ small controls */
  var uid = 0;

  function field(label, control, opts) {
    opts = opts || {};
    var id = control.id || ('lti-' + (++uid));
    if (!control.id && /^(INPUT|SELECT|TEXTAREA)$/.test(control.nodeName)) {
      control.id = id;
    }
    return el('div', { class: 'lte-field' + (opts.row ? ' lte-row' : '') }, [
      el('div', { class: 'lte-field-head' }, [
        /^(INPUT|SELECT|TEXTAREA)$/.test(control.nodeName) ? el('label', { for: control.id, text: label }) : el('span', { class: 'lte-label', text: label }),
        opts.reset || null,
      ]),
      control,
      opts.help ? el('p', { class: 'lte-help', text: opts.help }) : null,
    ]);
  }

  function linkButton(text, onClick, hidden) {
    var b = el('button', { type: 'button', class: 'lte-link', text: text, hidden: !!hidden });
    b.addEventListener('click', onClick);
    return b;
  }

  // [–] [ 48 ] px [+]  — empty = not overridden (shows the page's value as a hint).
  function stepper(o) {
    var input = el('input', { type: 'number', inputmode: 'decimal', min: String(o.min), max: String(o.max), step: String(o.step || 1), placeholder: o.placeholder === null || o.placeholder === undefined ? '' : String(o.placeholder), 'aria-label': o.label });
    input.value = o.value === undefined || o.value === null ? '' : String(o.value);
    var clamp = function (v) {
      var n = Math.min(o.max, Math.max(o.min, v));
      var dec = String(o.step || 1).indexOf('.') === -1 ? 0 : String(o.step).split('.')[1].length;
      return Math.round(n * Math.pow(10, dec)) / Math.pow(10, dec);
    };
    var commit = function () {
      if (input.value.trim() === '') {
        o.onChange(null);
        return;
      }
      var n = parseFloat(input.value);
      if (!isNaN(n)) {
        o.onChange(clamp(n));
      }
    };
    var bump = function (dir) {
      var base = input.value.trim() === '' ? parseFloat(input.placeholder) : parseFloat(input.value);
      if (isNaN(base)) {
        base = o.min > 0 ? o.min : 0;
      }
      input.value = String(clamp(base + dir * (o.step || 1)));
      commit();
    };
    input.addEventListener('input', commit);
    var minus = el('button', { type: 'button', class: 'lte-step', 'aria-label': '−', text: '−' });
    var plus = el('button', { type: 'button', class: 'lte-step', 'aria-label': '+', text: '+' });
    minus.addEventListener('click', function () { bump(-1); });
    plus.addEventListener('click', function () { bump(1); });
    var box = el('div', { class: 'lte-stepper' }, [minus, input, o.unit ? el('span', { class: 'lte-unit', text: o.unit }) : null, plus]);
    box.id = input.id = 'lti-' + (++uid);
    input.id = box.id + '-i';
    return { node: box, input: input };
  }

  // Range + number, for effects / zoom / focus.
  function slider(o) {
    var val = o.value === undefined || o.value === null ? o.neutral : o.value;
    var range = el('input', { type: 'range', min: String(o.min), max: String(o.max), step: String(o.step || 1), 'aria-label': o.label });
    var num = el('input', { type: 'number', min: String(o.min), max: String(o.max), step: String(o.step || 1), 'aria-label': o.label });
    range.value = num.value = String(val);
    var apply = function (v, from) {
      var n = Math.min(o.max, Math.max(o.min, parseFloat(v)));
      if (isNaN(n)) {
        return;
      }
      if (from !== range) { range.value = String(n); }
      if (from !== num) { num.value = String(n); }
      o.onChange(n);
    };
    range.addEventListener('input', function () { apply(range.value, range); });
    num.addEventListener('input', function () { apply(num.value, num); });
    return {
      node: el('div', { class: 'lte-slider' }, [el('span', { class: 'lte-slider-label', text: o.label }), range, el('span', { class: 'lte-slider-num' }, [num, o.unit ? el('span', { class: 'lte-unit', text: o.unit }) : null])]),
      set: function (v) { range.value = num.value = String(v); },
    };
  }

  function colorControl(o) {
    var val = o.value || '';
    var picker = el('input', { type: 'color', value: val || o.placeholder || '#ffffff', 'aria-label': o.label });
    var hex = el('input', { type: 'text', value: val, placeholder: o.placeholder || '—', maxlength: '7', spellcheck: 'false', dir: 'ltr', 'aria-label': o.label + ' (hex)' });
    var reset = linkButton(t.reset, function () {
      hex.value = '';
      picker.value = o.placeholder || '#ffffff';
      reset.hidden = true;
      o.onChange(null);
    }, !val);
    var apply = function (v) {
      v = v.toLowerCase();
      if (!/^#([0-9a-f]{3}|[0-9a-f]{6})$/.test(v)) {
        return;
      }
      if (v.length === 4) {
        v = '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
      }
      reset.hidden = false;
      o.onChange(v);
    };
    picker.addEventListener('input', function () { hex.value = picker.value; apply(picker.value); });
    hex.addEventListener('input', function () {
      if (/^#[0-9a-f]{6}$/i.test(hex.value)) {
        picker.value = hex.value;
      }
      apply(hex.value);
    });
    return field(o.label, el('div', { class: 'lte-color' }, [picker, hex]), { reset: reset });
  }

  function segment(o) {
    var group = el('div', { class: 'lte-segment lte-segment-icons', role: 'radiogroup', 'aria-label': o.label });
    o.options.forEach(function (opt) {
      var b = el('button', { type: 'button', role: 'radio', 'aria-checked': String(o.value === opt.value), title: opt.label, 'aria-label': opt.label });
      if (opt.icon) {
        b.appendChild(svgPath(opt.icon));
      } else {
        b.textContent = opt.label;
      }
      b.addEventListener('click', function () {
        var next = o.value === opt.value && o.allowNone ? null : opt.value;
        o.value = next;
        group.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-checked', 'false'); });
        if (next !== null) {
          b.setAttribute('aria-checked', 'true');
        }
        o.onChange(next);
      });
      group.appendChild(b);
    });
    return group;
  }

  function svgPath(d, size) {
    var svg = doc.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 20 20');
    svg.setAttribute('width', String(size || 16));
    svg.setAttribute('height', String(size || 16));
    svg.setAttribute('aria-hidden', 'true');
    d.split(' M').forEach(function (part, i) {
      var p = doc.createElementNS('http://www.w3.org/2000/svg', 'path');
      p.setAttribute('d', (i ? 'M' : '') + part);
      p.setAttribute('fill', 'none');
      p.setAttribute('stroke', 'currentColor');
      p.setAttribute('stroke-width', '1.7');
      p.setAttribute('stroke-linecap', 'round');
      p.setAttribute('stroke-linejoin', 'round');
      svg.appendChild(p);
    });
    return svg;
  }

  var ALIGN = {
    left: 'M3 5h14 M3 9h9 M3 13h14 M3 17h9',
    center: 'M3 5h14 M5.5 9h9 M3 13h14 M5.5 17h9',
    right: 'M3 5h14 M8 9h9 M3 13h14 M8 17h9',
    justify: 'M3 5h14 M3 9h14 M3 13h14 M3 17h14',
  };

  /* ------------------------------------------------------------ content pane */
  var contentPane = doc.querySelector('[data-pane="content"]');

  // Slot labels come from the server as {kind (raw English, drives grouping),
  // kindLabel (translated, shown), detail (template text the slot started as)}.
  function kindOf(slot) {
    return slot.kind || slot.label;
  }

  function detailOf(slot) {
    return slot.detail || '';
  }

  function textField(slot, labelText, opts) {
    opts = opts || {};
    var shown = displayValue(slot.key);
    var long = (slot.default || '').length > 60 || slot.multiline;
    var input = el(long ? 'textarea' : 'input', {
      id: (opts.prefix || 'slot-') + slot.key,
      'data-slot': slot.key,
      maxlength: String(slot.max || 300),
      rows: long ? String(Math.min(6, Math.max(2, Math.ceil((slot.default || '').length / 42)))) : null,
      type: long ? null : 'text',
      dir: 'auto',
    });
    input.value = shown;
    (textInputs[slot.key] = textInputs[slot.key] || []).push(input);
    var reset = resetButton(slot.key, function () { syncTextInputs(slot.key); });
    var note = el('p', { class: 'lte-help', text: t.formattedHint, hidden: !(slot.rich && hasMarks(content.slots[slot.key])) });
    input.addEventListener('input', function () {
      setTextFromPanel(slot.key, input.value);
      reset.hidden = content.slots[slot.key] === undefined;
      note.hidden = true;
      syncTextInputs(slot.key);
    });
    input.addEventListener('lt:external', function () {
      reset.hidden = content.slots[slot.key] === undefined;
      note.hidden = !(slot.rich && hasMarks(content.slots[slot.key]));
    });
    input.addEventListener('focus', function () {
      send({ type: 'lt:selectKey', kind: 'text', key: slot.key });
    });
    return el('div', { class: 'lte-field' }, [
      el('div', { class: 'lte-field-head' }, [el('label', { for: input.id, text: labelText }), reset]),
      input,
      note,
    ]);
  }

  function linkField(slot, labelText, opts) {
    opts = opts || {};
    var input = el('input', { id: (opts.prefix || 'slot-') + slot.key, 'data-slot': slot.key, type: 'text', inputmode: 'url', dir: 'ltr', spellcheck: 'false', maxlength: '500' });
    input.value = valueOf(slot.key);
    var err = el('p', { class: 'lte-error', text: t.badLink, hidden: true, id: 'err-' + input.id });
    var reset = resetButton(slot.key, function () {
      input.value = slot.default;
      err.hidden = true;
      input.removeAttribute('aria-invalid');
    });
    input.addEventListener('input', function () {
      var ok = validHref(input.value);
      err.hidden = ok;
      if (ok) {
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
        setSlot(slot.key, input.value.trim());
      } else {
        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('aria-describedby', err.id);
      }
      reset.hidden = input.value.trim() === slot.default;
    });
    return el('div', { class: 'lte-field' }, [
      el('div', { class: 'lte-field-head' }, [el('label', { for: input.id, text: labelText }), reset]),
      input,
      err,
    ]);
  }

  var fileInputs = {};

  function uploadInput(slot, onDone) {
    var video = slot.type === 'video';
    var file = el('input', { type: 'file', accept: video ? 'video/mp4,video/webm' : 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml', class: 'lte-sr', 'data-slot': slot.key });
    file.addEventListener('change', function () {
      if (!file.files || !file.files[0]) {
        return;
      }
      var fd = new FormData();
      fd.append('file', file.files[0]);
      if (video) {
        fd.append('kind', 'video');
      }
      onDone('uploading');
      fetch(cfg.assetsUrl, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
        .then(function (j) {
          var url = sitePath(String(j.url || ''));
          setSlot(slot.key, url);
          onDone('done', url);
        })
        .catch(function () { onDone('failed'); })
        .then(function () { file.value = ''; });
    });
    return file;
  }

  function imageField(slot, opts) {
    opts = opts || {};
    var img = el('img', { src: valueOf(slot.key), alt: '' });
    var status = el('span', { class: 'lte-muted', 'aria-live': 'polite' });
    var reset = el('button', { type: 'button', class: 'lte-link', text: t.reset, hidden: valueOf(slot.key) === slot.default });
    var file = uploadInput(slot, function (state, url) {
      status.textContent = state === 'uploading' ? t.uploading : state === 'failed' ? t.uploadFailed : '';
      if (state === 'done') {
        img.src = url;
        reset.hidden = false;
      }
    });
    file.id = (opts.prefix || 'slot-') + slot.key;
    fileInputs[slot.key] = file;
    reset.addEventListener('click', function () {
      img.src = slot.default;
      setSlot(slot.key, slot.default);
      reset.hidden = true;
    });
    var label = slot.kind === 'Image' ? (detailOf(slot) || slot.kindLabel) : (slot.kindLabel + (detailOf(slot) ? ' · ' + detailOf(slot) : ''));
    return el('div', { class: 'lte-field lte-field-image' }, [
      el('div', { class: 'lte-field-head' }, [el('span', { class: 'lte-label', text: opts.label || label }), reset]),
      el('div', { class: 'lte-image' }, [
        el('div', { class: 'lte-thumb' }, [img]),
        el('div', { class: 'lte-image-actions' }, [el('label', { class: 'lte-btn lte-btn-small', for: file.id, text: t.replace }), file, status]),
      ]),
    ]);
  }

  // Video field (e.g. a hero background video): preview, replace (MP4/WebM), reset.
  function videoField(slot, opts) {
    opts = opts || {};
    var vid = el('video', { src: valueOf(slot.key), muted: true, playsinline: true, preload: 'metadata', class: 'lte-video' });
    vid.muted = true;
    var status = el('span', { class: 'lte-muted', 'aria-live': 'polite' });
    var reset = el('button', { type: 'button', class: 'lte-link', text: t.reset, hidden: valueOf(slot.key) === slot.default });
    var file = uploadInput(slot, function (state, url) {
      status.textContent = state === 'uploading' ? t.uploading : state === 'failed' ? t.videoUploadFailed : '';
      if (state === 'done') {
        vid.src = url;
        reset.hidden = false;
      }
    });
    file.id = (opts.prefix || 'slot-') + slot.key;
    fileInputs[slot.key] = file;
    reset.addEventListener('click', function () {
      vid.src = slot.default;
      setSlot(slot.key, slot.default);
      reset.hidden = true;
    });
    return el('div', { class: 'lte-field lte-field-image' }, [
      el('div', { class: 'lte-field-head' }, [el('span', { class: 'lte-label', text: opts.label || (slot.kindLabel + (detailOf(slot) ? ' · ' + detailOf(slot) : '')) }), reset]),
      el('div', { class: 'lte-image' }, [
        el('div', { class: 'lte-thumb' }, [vid]),
        el('div', { class: 'lte-image-actions' }, [el('label', { class: 'lte-btn lte-btn-small', for: file.id, text: t.replaceVideo }), file, el('span', { class: 'lte-muted lte-hint', text: t.videoHint }), status]),
      ]),
    ]);
  }

  /* ------------------------------------------------------------ icons */
  var iconLib = null;          // {icons: {name: inner}, tags: {name: "…"}} — loaded on first use
  var iconLibLoading = null;

  function loadIcons() {
    if (iconLib) {
      return Promise.resolve(iconLib);
    }
    if (!iconLibLoading) {
      iconLibLoading = fetch(cfg.iconsUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { iconLib = j; return j; });
    }
    return iconLibLoading;
  }

  // Same markup the server renders (App\Services\LockedTemplates\Icons::svg).
  function iconSvg(name, slot) {
    if (!iconLib || !iconLib.icons[name]) {
      return '';
    }
    var attrs = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
    var esc = function (v) { return String(v).replace(/[&"<>]/g, function (c) { return { '&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;' }[c]; }); };
    if (slot.iconClass) {
      attrs += ' class="' + esc(slot.iconClass) + '"';
    }
    if (slot.iconWidth) {
      attrs += ' width="' + esc(slot.iconWidth) + '"';
    }
    if (slot.iconHeight) {
      attrs += ' height="' + esc(slot.iconHeight) + '"';
    }
    return '<svg ' + attrs + '>' + iconLib.icons[name] + '</svg>';
  }

  function iconPreview(box, name) {
    box.textContent = '';
    if (!name) {
      box.appendChild(el('span', { class: 'lte-muted', text: t.originalIcon }));
      return;
    }
    loadIcons().then(function () {
      var parsed = new DOMParser().parseFromString(iconSvg(name, { iconWidth: '28', iconHeight: '28' }), 'image/svg+xml').documentElement;
      if (parsed && parsed.nodeName === 'svg') {
        box.textContent = '';
        box.appendChild(doc.importNode(parsed, true));
      }
    });
  }

  var iconFieldRefresh = {};

  function iconField(slot, opts) {
    opts = opts || {};
    var preview = el('div', { class: 'lte-icon-preview', 'aria-hidden': 'true' });
    var change = el('button', { type: 'button', class: 'lte-btn lte-btn-small', id: (opts.prefix || 'slot-') + slot.key, 'data-slot': slot.key, text: t.changeIcon });
    var reset = el('button', { type: 'button', class: 'lte-link', text: t.reset, hidden: !content.slots[slot.key] });
    var refresh = function () {
      iconPreview(preview, content.slots[slot.key] || '');
      reset.hidden = !content.slots[slot.key];
    };
    (iconFieldRefresh[slot.key] = iconFieldRefresh[slot.key] || []).push(refresh);
    change.addEventListener('click', function () { openIconPicker(slot.key); });
    reset.addEventListener('click', function () {
      setSlot(slot.key, '');
      refreshIcon(slot.key);
    });
    refresh();
    return el('div', { class: 'lte-field lte-field-icon' }, [
      el('div', { class: 'lte-field-head' }, [el('span', { class: 'lte-label', text: opts.label || ((slot.kindLabel || slot.kind) + (slot.detail ? ' · ' + slot.detail : '')) }), reset]),
      el('div', { class: 'lte-image' }, [preview, change]),
    ]);
  }

  function refreshIcon(key) {
    (iconFieldRefresh[key] || []).forEach(function (fn) { fn(); });
  }

  var pickerEl = null;

  function openIconPicker(key) {
    var slot = slotIndex[key];
    if (!slot || !slot.editable) {
      return;
    }
    closeIconPicker();
    var search = el('input', { type: 'search', class: 'lte-picker-search', placeholder: t.searchIcons, 'aria-label': t.searchIcons, dir: 'ltr' });
    var grid = el('div', { class: 'lte-picker-grid', role: 'listbox', 'aria-label': t.chooseIcon });
    var status = el('p', { class: 'lte-muted lte-picker-status', 'aria-live': 'polite', text: t.loading });
    var closeBtn = el('button', { type: 'button', class: 'lte-icon', 'aria-label': t.close, title: t.close, text: '✕' });
    var dialog = el('div', { class: 'lte-picker', role: 'dialog', 'aria-modal': 'true', 'aria-label': t.chooseIcon }, [
      el('div', { class: 'lte-picker-head' }, [el('strong', { text: t.chooseIcon }), closeBtn]),
      search,
      status,
      grid,
    ]);
    pickerEl = el('div', { class: 'lte-picker-backdrop' }, [dialog]);
    doc.body.appendChild(pickerEl);
    closeBtn.addEventListener('click', closeIconPicker);
    pickerEl.addEventListener('click', function (e) {
      if (e.target === pickerEl) {
        closeIconPicker();
      }
    });
    pickerEl.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeIconPicker();
      }
    });

    var render = function () {
      var q = search.value.trim().toLowerCase();
      var names = Object.keys(iconLib.icons).filter(function (n) {
        return !q || n.indexOf(q) !== -1 || ((iconLib.tags[n] || '').indexOf(q) !== -1);
      });
      grid.textContent = '';
      status.textContent = names.length ? (names.length > 240 ? t.iconsShown.replace(':n', '240').replace(':total', String(names.length)) : '') : t.noIcons;
      names.slice(0, 240).forEach(function (n) {
        var b = el('button', { type: 'button', class: 'lte-picker-item', role: 'option', title: n, 'aria-label': n, 'aria-selected': String(content.slots[key] === n) });
        var svg = new DOMParser().parseFromString(iconSvg(n, { iconWidth: '22', iconHeight: '22' }), 'image/svg+xml').documentElement;
        b.appendChild(doc.importNode(svg, true));
        b.addEventListener('click', function () {
          setSlot(key, n);
          refreshIcon(key);
          closeIconPicker();
        });
        grid.appendChild(b);
      });
    };
    var timer = null;
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(render, 120);
    });
    loadIcons().then(render).catch(function () { status.textContent = t.saveFailed; });
    search.focus();
  }

  function closeIconPicker() {
    if (pickerEl) {
      pickerEl.remove();
      pickerEl = null;
    }
  }

  function resetButton(key, after) {
    var btn = el('button', { type: 'button', class: 'lte-link', text: t.reset, hidden: content.slots[key] === undefined });
    btn.addEventListener('click', function () {
      setSlot(key, defaultOf(slotIndex[key]));
      btn.hidden = true;
      if (after) {
        after();
      }
    });
    return btn;
  }

  // Text / link / image / icon fields for a list of slots (button text + its link
  // side by side).
  function fieldsFor(list, prefix) {
    var o = prefix ? { prefix: prefix } : {};
    var box = doc.createDocumentFragment();
    var used = {};
    var texts = list.filter(function (s) { return s.type === 'text'; });
    var links = list.filter(function (s) { return s.type === 'link'; });
    var images = list.filter(function (s) { return s.type === 'image'; });
    var icons = list.filter(function (s) { return s.type === 'icon'; });
    var videos = list.filter(function (s) { return s.type === 'video'; });

    texts.forEach(function (s) {
      if (used[s.key]) {
        return;
      }
      var pairLink = null;
      if (kindOf(s) === 'Button text') {
        links.some(function (l) {
          if (!used[l.key] && detailOf(l) === detailOf(s)) {
            pairLink = l;
            return true;
          }
          return false;
        });
      }
      if (pairLink) {
        used[s.key] = used[pairLink.key] = true;
        box.appendChild(el('div', { class: 'lte-group' }, [textField(s, t.buttonText, o), linkField(pairLink, t.link, o)]));
      } else {
        used[s.key] = true;
        box.appendChild(textField(s, s.kindLabel || kindOf(s), o));
      }
    });
    links.forEach(function (l) {
      if (!used[l.key]) {
        used[l.key] = true;
        box.appendChild(linkField(l, t.link + ' · ' + detailOf(l), o));
      }
    });
    videos.forEach(function (v) { box.appendChild(videoField(v, o)); });
    images.forEach(function (i) { box.appendChild(imageField(i, o)); });
    icons.forEach(function (i) { box.appendChild(iconField(i, o)); });
    return box;
  }

  function sectionFields(section) {
    var box = el('div', { class: 'lte-fields' });
    var inItems = {};
    (section.repeaters || []).forEach(function (rep) {
      rep.refs.forEach(function (ref) {
        itemSlots(rep, ref).forEach(function (k) { inItems[k] = true; });
      });
    });
    var editable = section.slots.filter(function (s) { return s.editable && !inItems[s.key]; });
    box.appendChild(fieldsFor(editable));
    (section.repeaters || []).forEach(function (rep) { box.appendChild(itemsBlock(section, rep)); });
    if (!box.childNodes.length) {
      box.appendChild(el('p', { class: 'lte-locked', text: t.locked }));
    }
    return box;
  }

  // "Items" block: one card per item (its fields + move / duplicate / remove).
  function itemsBlock(section, rep) {
    var wrap = el('div', { class: 'lte-items' });
    var add = caps.items ? el('button', { type: 'button', class: 'lte-link', text: '+ ' + t.addItem, disabled: rep.refs.length >= rep.max }) : null;
    if (add) {
      add.addEventListener('click', function () { itemAction(rep.key + '|' + rep.refs[rep.refs.length - 1], 'duplicate'); });
    }
    wrap.appendChild(el('div', { class: 'lte-items-head' }, [
      el('span', { class: 'lte-label', text: rep.label + (rep.detail ? ' · ' + rep.detail : '') + ' (' + rep.refs.length + ')' }),
      add,
    ]));
    rep.refs.forEach(function (ref, i) {
      var keys = itemSlots(rep, ref);
      var slots = keys.map(function (k) { return slotIndex[k]; }).filter(function (x) { return x && x.editable; });
      var firstText = slots.filter(function (x) { return x.type === 'text'; })[0];
      var title = firstText ? displayValue(firstText.key) : (t.item + ' ' + (i + 1));
      var card = el('details', { class: 'lte-item', 'data-item': rep.key + '|' + ref });
      card.open = !!openItems[rep.key + '|' + ref];
      card.addEventListener('toggle', function () { openItems[rep.key + '|' + ref] = card.open; });
      card.appendChild(el('summary', { 'data-select-item': rep.key + '|' + ref }, [
        el('span', { class: 'lte-item-name', text: title }),
        itemTools(rep, ref, i),
      ]));
      var body = el('div', { class: 'lte-fields lte-item-body' });
      body.appendChild(fieldsFor(slots));
      card.appendChild(body);
      wrap.appendChild(card);
    });
    return wrap;
  }

  var openItems = {};

  // Only a user opening a section / item moves the canvas (re-renders re-open
  // <details> programmatically and must not fight the canvas scroll).
  contentPane.addEventListener('click', function (e) {
    var sum = e.target.closest('summary');
    if (!sum || e.target.closest('button') || sum.parentElement.open) {
      return;
    }
    if (sum.hasAttribute('data-select-item')) {
      send({ type: 'lt:selectKey', kind: 'item', key: sum.getAttribute('data-select-item') });
    } else if (sum.hasAttribute('data-scroll-section')) {
      send({ type: 'lt:scrollTo', section: sum.getAttribute('data-scroll-section') });
    }
  });

  function itemTools(rep, ref, i) {
    var tools = el('span', { class: 'lte-section-tools' });
    if (!caps.items) {
      return tools;
    }
    var k = rep.key + '|' + ref;
    tools.appendChild(iconButton(t.moveUp, UP, function () { itemAction(k, 'up'); }, i === 0));
    tools.appendChild(iconButton(t.moveDown, DOWN, function () { itemAction(k, 'down'); }, i === rep.refs.length - 1));
    tools.appendChild(iconButton(t.duplicate, COPY, function () { itemAction(k, 'duplicate'); }, rep.refs.length >= rep.max));
    tools.appendChild(iconButton(t.remove, TRASH, function () { itemAction(k, 'remove'); }, rep.refs.length <= rep.min));
    return tools;
  }

  function itemAction(itemKey, action) {
    var loc = itemLocation(itemKey);
    if (!caps.items || !loc) {
      return;
    }
    var rep = loc.rep;
    var i = rep.refs.indexOf(loc.ref);
    if (action === 'duplicate') {
      if (rep.refs.length >= rep.max) {
        toast(t.itemsMax.replace(':max', String(rep.max)));
        return;
      }
      var p = parseRef(loc.ref);
      var ref = newId('n') + ':' + p.src;
      var from = itemSlots(rep, loc.ref);
      itemSlots(rep, ref).forEach(function (key, n) {
        var src = slotIndex[from[n]] || slotIndex[rep.items[p.src].slots[n]];
        if (!src) {
          return;
        }
        addSlotConfig(loc.section, Object.assign({}, src, { key: key, origin: src.origin || src.key }));
        if (content.slots[from[n]] !== undefined) {
          content.slots[key] = content.slots[from[n]];
        }
        if (content.styles[from[n]]) {
          content.styles[key] = JSON.parse(JSON.stringify(content.styles[from[n]]));
        }
      });
      rep.refs.splice(i + 1, 0, ref);
      current = { kind: 'item', key: rep.key + '|' + ref, section: loc.section.key, computed: {} };
      openItems[rep.key + '|' + ref] = true;
    } else if (action === 'remove') {
      if (rep.refs.length <= rep.min) {
        toast(t.itemsMin.replace(':min', String(rep.min)));
        return;
      }
      var pr = parseRef(loc.ref);
      rep.refs.splice(i, 1);
      if (pr && pr.id) {
        forgetSuffix('~' + pr.id);
      }
      current = null;
      send({ type: 'lt:deselect' });
    } else if (action === 'up' || action === 'down') {
      var j = i + (action === 'up' ? -1 : 1);
      if (j < 0 || j >= rep.refs.length) {
        return;
      }
      rep.refs[i] = rep.refs[j];
      rep.refs[j] = loc.ref;
    } else {
      return;
    }
    content.items[rep.key] = rep.refs.slice();
    renderContent();
    renderInspector();
    save({ reload: true });
  }

  // Section copies: duplicate carries over everything the source shows (values,
  // styles, items); the editor then reloads so the server builds the copy's fields.
  function sectionAction(key, action) {
    var section = sectionByKey(key);
    if (!section) {
      return;
    }
    if (action === 'add') {
      openLibrary(key);
      return;
    }
    // Copies of page sections are `sections_duplicate`; library sections `sections_add`.
    var mayCopy = section.library ? caps.sections_add : caps.sections_duplicate;
    if (!mayCopy) {
      return;
    }
    if (action === 'duplicate' && section.copyable) {
      var cid = newId('c');
      var copyKey = section.base + '~' + cid;
      var rekey = function (k) {
        var parts = String(k).split('~').filter(function (part, n) { return n === 0 || part.charAt(0) !== 'c'; });
        parts.splice(1, 0, cid);
        return parts.join('~');
      };
      section.slots.forEach(function (slot) {
        var nk = rekey(slot.key);
        if (content.slots[slot.key] !== undefined) {
          content.slots[nk] = content.slots[slot.key];
        }
        if (content.styles[slot.key]) {
          content.styles[nk] = JSON.parse(JSON.stringify(content.styles[slot.key]));
        }
      });
      (section.repeaters || []).forEach(function (rep) {
        content.items[rekey(rep.key)] = rep.refs.slice();
      });
      if (content.section_styles[key]) {
        content.section_styles[copyKey] = JSON.parse(JSON.stringify(content.section_styles[key]));
      }
      sections.splice(sections.indexOf(section) + 1, 0, { key: copyKey, hidden: false, slots: [], repeaters: [] });
    } else if (action === 'delete' && section.copy) {
      if (!window.confirm(section.library ? t.removeSectionConfirm : t.deleteSectionConfirm)) {
        return;
      }
      sections.splice(sections.indexOf(section), 1);
      forgetSuffix(key.slice(key.lastIndexOf('~')));
      current = null;
    } else {
      return;
    }
    content.sections = sectionsPayload();
    save().then(function () {
      if (!dirty) {
        window.location.reload();
      }
    });
  }

  // ---- Section library: ready-made sections from the template's other pages.
  var library = cfg.library || [];

  function canAddAfter(index) {
    // Anywhere from after the header up to before the footer.
    return !!(caps.sections_add && library.length && index < sections.length - 1);
  }

  function lastMainIndex() {
    for (var i = sections.length - 1; i >= 0; i--) {
      if (!sections[i].required) {
        return i;
      }
    }
    return Math.max(0, sections.length - 2);
  }

  function rememberFocusSection(key) {
    try { sessionStorage.setItem('lte-focus-section', key); } catch (e) { /* storage off: no auto-scroll */ }
  }

  function takeFocusSection() {
    try {
      var k = sessionStorage.getItem('lte-focus-section');
      sessionStorage.removeItem('lte-focus-section');
      return k;
    } catch (e) {
      return null;
    }
  }

  var libraryEl = null;
  var libraryGroup = '';

  function openLibrary(afterKey) {
    if (!caps.sections_add || !library.length) {
      return;
    }
    closeLibrary();
    var groups = [];
    library.forEach(function (b) {
      if (groups.indexOf(b.group) === -1) {
        groups.push(b.group);
      }
    });
    var labelOf = {};
    library.forEach(function (b) { labelOf[b.group] = b.groupLabel || b.group; });
    var search = el('input', { type: 'search', class: 'lte-picker-search', placeholder: t.searchSections, 'aria-label': t.searchSections });
    var chips = el('div', { class: 'lte-lib-chips', role: 'tablist', 'aria-label': t.sectionTypes });
    var grid = el('div', { class: 'lte-lib-grid' });
    var status = el('p', { class: 'lte-muted lte-picker-status', 'aria-live': 'polite' });
    var closeBtn = el('button', { type: 'button', class: 'lte-icon', 'aria-label': t.close, title: t.close, text: '✕' });
    var dialog = el('div', { class: 'lte-picker lte-lib', role: 'dialog', 'aria-modal': 'true', 'aria-label': t.addSection }, [
      el('div', { class: 'lte-picker-head' }, [el('strong', { text: t.addSection }), closeBtn]),
      el('p', { class: 'lte-muted lte-lib-help', text: t.addSectionHelp }),
      search,
      chips,
      status,
      grid,
    ]);
    libraryEl = el('div', { class: 'lte-picker-backdrop' }, [dialog]);
    doc.body.appendChild(libraryEl);
    closeBtn.addEventListener('click', closeLibrary);
    libraryEl.addEventListener('click', function (e) {
      if (e.target === libraryEl) {
        closeLibrary();
      }
    });
    libraryEl.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeLibrary();
      }
    });

    var render = function () {
      var q = search.value.trim().toLowerCase();
      chips.textContent = '';
      [''].concat(groups).forEach(function (g) {
        var n = library.filter(function (b) { return !g || b.group === g; }).length;
        var chip = el('button', { type: 'button', class: 'lte-lib-chip', role: 'tab', 'aria-selected': String(libraryGroup === g), text: (g ? labelOf[g] : t.allSections) + ' ' + n });
        chip.addEventListener('click', function () {
          libraryGroup = g;
          render();
        });
        chips.appendChild(chip);
      });
      var list = library.filter(function (b) {
        return (!libraryGroup || b.group === libraryGroup) && (!q || b.label.toLowerCase().indexOf(q) !== -1 || (labelOf[b.group] || '').toLowerCase().indexOf(q) !== -1);
      });
      grid.textContent = '';
      status.textContent = list.length ? '' : t.noSections;
      list.forEach(function (b) {
        var card = el('button', { type: 'button', class: 'lte-lib-card', title: b.label }, [
          b.thumb ? el('img', { src: b.thumb, alt: '', loading: 'lazy', decoding: 'async' }) : el('span', { class: 'lte-lib-nothumb', text: labelOf[b.group] }),
          el('span', { class: 'lte-lib-name', dir: 'auto', text: b.label }),
          el('span', { class: 'lte-lib-group', text: labelOf[b.group] }),
        ]);
        card.addEventListener('click', function () { insertLibrary(b, afterKey); });
        grid.appendChild(card);
      });
    };
    var timer = null;
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(render, 120);
    });
    render();
    search.focus();
  }

  function closeLibrary() {
    if (libraryEl) {
      libraryEl.remove();
      libraryEl = null;
    }
  }

  // The server builds the section from the template's own markup (key "<block>~c<id>");
  // the editor only says which block and where.
  function insertLibrary(block, afterKey) {
    var after = sectionByKey(afterKey);
    var at = after ? sections.indexOf(after) : lastMainIndex();
    var key = block.key + '~' + newId('c');
    sections.splice(at + 1, 0, { key: key, hidden: false, slots: [], repeaters: [] });
    content.sections = sectionsPayload();
    closeLibrary();
    rememberFocusSection(key);
    save().then(function () {
      if (!dirty) {
        window.location.reload();
      }
    });
  }

  function iconButton(label, path, onClick, disabled) {
    var b = el('button', { type: 'button', class: 'lte-icon', title: label, 'aria-label': label, disabled: !!disabled }, [svgPath(path)]);
    b.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      onClick();
    });
    return b;
  }

  var EYE = 'M2 10s3-5.5 8-5.5S18 10 18 10s-3 5.5-8 5.5S2 10 2 10Z M10 12.3a2.3 2.3 0 1 0 0-4.6 2.3 2.3 0 0 0 0 4.6Z';
  var EYE_OFF = 'M3 3l14 14 M8.2 5A8.6 8.6 0 0 1 10 4.5c5 0 8 5.5 8 5.5a14 14 0 0 1-2.2 2.8 M5.2 6.8A13.5 13.5 0 0 0 2 10s3 5.5 8 5.5a8 8 0 0 0 3.2-.7';
  var UP = 'M6 12l4-4 4 4';
  var DOWN = 'M6 8l4 4 4-4';
  var COPY = 'M7 7h9v9H7z M4 13V4h9';
  var PLUS = 'M10 4v12 M4 10h12';
  var TRASH = 'M3.5 5.5h13 M8 5.5V3.5h4v2 M5 5.5l.8 11h8.4l.8-11';

  var openSections = {};

  function renderContent() {
    contentPane.textContent = '';
    textInputs = {};
    iconFieldRefresh = {};
    if (caps.text || caps.link) {
      contentPane.appendChild(el('p', { class: 'lte-tip', text: t.tokensHint }));
    }
    var movable = sections.filter(function (s) { return !s.required; });
    sections.forEach(function (section) {
      var details = el('details', { class: 'lte-section' + (section.hidden ? ' is-hidden' : ''), 'data-section': section.key });
      details.open = !!openSections[section.key];
      details.addEventListener('toggle', function () { openSections[section.key] = details.open; });
      details.appendChild(el('summary', { 'data-scroll-section': section.key }, [
        el('span', { class: 'lte-section-name', text: section.label + (section.copy && !section.library ? ' · ' + t.copyLabel : '') }),
        section.hidden ? el('span', { class: 'lte-pill', text: t.hidden }) : null,
        sectionTools(section, movable),
      ]));
      details.appendChild(sectionFields(section));
      contentPane.appendChild(details);
    });
    if (caps.sections_add && library.length) {
      var addBtn = el('button', { type: 'button', class: 'lte-add-section', text: '+ ' + t.addSection });
      addBtn.addEventListener('click', function () { openLibrary(sections[lastMainIndex()] ? sections[lastMainIndex()].key : null); });
      contentPane.appendChild(addBtn);
    }
    if (current) {
      renderInspector();
    }
  }

  function sectionTools(section, movable) {
    var controls = el('span', { class: 'lte-section-tools' });
    if (caps.sections_reorder && !section.required) {
      var idx = movable.indexOf(section);
      controls.appendChild(iconButton(t.moveUp, UP, function () { moveSection(section, -1); }, idx <= 0));
      controls.appendChild(iconButton(t.moveDown, DOWN, function () { moveSection(section, 1); }, idx === movable.length - 1));
    }
    if (caps.sections_toggle && !section.required) {
      controls.appendChild(iconButton(section.hidden ? t.show : t.hide, section.hidden ? EYE_OFF : EYE, function () { toggleSection(section); }));
    }
    if (caps.sections_duplicate && section.copyable) {
      controls.appendChild(iconButton(t.duplicateSection, COPY, function () { sectionAction(section.key, 'duplicate'); }));
    }
    if (canAddAfter(sections.indexOf(section))) {
      controls.appendChild(iconButton(t.addSectionBelow, PLUS, function () { sectionAction(section.key, 'add'); }));
    }
    if (section.copy && (section.library ? caps.sections_add : caps.sections_duplicate)) {
      controls.appendChild(iconButton(section.library ? t.removeSection : t.deleteSection, TRASH, function () { sectionAction(section.key, 'delete'); }));
    }
    return controls;
  }

  function sectionsPayload() {
    return sections.map(function (s) { return { key: s.key, hidden: !!s.hidden }; });
  }

  function toggleSection(section) {
    section.hidden = !section.hidden;
    content.sections = sectionsPayload();
    renderContent();
    save({ reload: true });
  }

  function moveSection(section, dir) {
    var movable = sections.filter(function (s) { return !s.required; });
    var i = movable.indexOf(section);
    var j = i + dir;
    if (j < 0 || j >= movable.length) {
      return;
    }
    var a = sections.indexOf(movable[i]);
    var b = sections.indexOf(movable[j]);
    sections[a] = movable[j];
    sections[b] = movable[i];
    content.sections = sectionsPayload();
    renderContent();
    save({ reload: true });
  }

  /* ------------------------------------------------------------ element pane */
  var inspector = doc.querySelector('[data-pane="element"]');

  function inspectorLive() {
    return inspector.contains(doc.activeElement);
  }

  function sectionByKey(key) {
    return sections.filter(function (s) { return s.key === key; })[0] || null;
  }

  function renderInspector() {
    inspector.textContent = '';
    textInputs = pruneInputs();
    if (!current) {
      inspector.appendChild(el('div', { class: 'lte-empty' }, [
        svgPath('M4 4l6.5 15 2.1-6.4L19 10.5z M13 13l5 5', 28),
        el('p', { text: t.pickElement }),
      ]));
      return;
    }
    var section = current.section ? sectionByKey(current.section) : null;
    var slot = current.kind === 'section' || current.kind === 'item' ? null : slotIndex[current.key];
    var crumbs = el('div', { class: 'lte-crumbs' });
    var crumb = function (text, onClick) {
      var b = el('button', { type: 'button', class: 'lte-crumb', text: text });
      b.addEventListener('click', onClick);
      crumbs.appendChild(b);
      crumbs.appendChild(el('span', { class: 'lte-crumb-sep', 'aria-hidden': 'true', text: '›' }));
    };
    if (section && current.kind !== 'section') {
      crumb(section.label, function () { send({ type: 'lt:selectKey', kind: 'section', key: section.key }); selectSectionInPanel(section.key); });
    }
    if (current.item && current.kind !== 'item' && itemLocation(current.item)) {
      var itemKey = current.item;
      crumb(t.item, function () {
        send({ type: 'lt:selectKey', kind: 'item', key: itemKey });
        current = { kind: 'item', key: itemKey, section: current.section, computed: {} };
        renderInspector();
      });
    }
    var title = current.kind === 'section' ? (section ? section.label : current.key) : current.kind === 'item' ? t.item : (slot ? (slot.kindLabel || slot.kind) : '');
    crumbs.appendChild(el('strong', { text: title }));
    var close = el('button', { type: 'button', class: 'lte-icon', title: t.close, 'aria-label': t.close, text: '✕' });
    close.addEventListener('click', function () {
      current = null;
      send({ type: 'lt:deselect' });
      renderInspector();
    });
    inspector.appendChild(el('div', { class: 'lte-inspector-head' }, [crumbs, close]));
    if (slot && slot.detail) {
      inspector.appendChild(el('p', { class: 'lte-inspector-detail', text: slot.detail }));
    }

    var body = el('div', { class: 'lte-inspector' });
    inspector.appendChild(body);
    if (current.kind === 'text' && slot) {
      inspectText(body, slot);
    } else if (current.kind === 'image' && slot) {
      inspectImage(body, slot);
    } else if (current.kind === 'icon' && slot) {
      inspectIcon(body, slot);
    } else if (current.kind === 'section' && section) {
      inspectSection(body, section);
    } else if (current.kind === 'item') {
      inspectItem(body);
    }
    if (!body.childNodes.length) {
      body.appendChild(el('p', { class: 'lte-locked', text: t.locked }));
    }
  }

  function pruneInputs() {
    var out = {};
    Object.keys(textInputs).forEach(function (k) {
      out[k] = textInputs[k].filter(function (i) { return i.isConnected && !inspector.contains(i); });
    });
    return out;
  }

  function selectSectionInPanel(key) {
    current = { kind: 'section', key: key, section: key, computed: {} };
    renderInspector();
  }

  function heading(text) {
    return el('h3', { class: 'lte-h', text: text });
  }

  function inspectText(body, slot) {
    var key = slot.key;
    if (slot.editable) {
      body.appendChild(textField(slot, t.textLabel, { prefix: 'el-' }));
      if (slot.rich && caps.text_format) {
        body.appendChild(el('p', { class: 'lte-help lte-help-tip', text: t.formatTip }));
      }
    }
    if (current.link && slotIndex[current.link] && slotIndex[current.link].editable) {
      body.appendChild(linkField(slotIndex[current.link], t.link, { prefix: 'el-' }));
    }
    if (!caps.text_style) {
      return;
    }
    var s = styleOf('text', key);
    var c = current.computed || {};
    var resetStyle = linkButton(t.resetStyle, function () {
      clearStyle('text', key, ['size', 'weight', 'line', 'tracking', 'color', 'color_dark', 'align']);
      renderInspector();
    }, !Object.keys(s).length);
    var onStyle = function (prop) {
      return function (v) {
        var patch = {};
        patch[prop] = v;
        setStyle('text', key, patch);
        resetStyle.hidden = !Object.keys(styleOf('text', key)).length;
      };
    };

    body.appendChild(el('div', { class: 'lte-h-row' }, [heading(t.typography), resetStyle]));
    var size = stepper({ label: t.size, value: s.size, placeholder: c.size, min: 8, max: 200, step: 1, unit: 'px', onChange: onStyle('size') });
    body.appendChild(field(t.size, size.node, { row: true }));

    var weight = el('select', { 'aria-label': t.weight });
    weight.appendChild(el('option', { value: '', text: t.auto + (c.weight ? ' (' + c.weight + ')' : '') }));
    [[100, t.w100], [200, t.w200], [300, t.w300], [400, t.w400], [500, t.w500], [600, t.w600], [700, t.w700], [800, t.w800], [900, t.w900]].forEach(function (w) {
      weight.appendChild(el('option', { value: String(w[0]), text: w[1] + ' · ' + w[0] }));
    });
    weight.value = s.weight ? String(s.weight) : '';
    weight.addEventListener('change', function () { onStyle('weight')(weight.value ? +weight.value : null); });
    body.appendChild(field(t.weight, weight, { row: true }));

    var line = stepper({ label: t.lineHeight, value: s.line, placeholder: c.line, min: 0.8, max: 3, step: 0.05, onChange: onStyle('line') });
    body.appendChild(field(t.lineHeight, line.node, { row: true }));
    var track = stepper({ label: t.letterSpacing, value: s.tracking, placeholder: c.tracking, min: -5, max: 40, step: 0.5, unit: 'px', onChange: onStyle('tracking') });
    body.appendChild(field(t.letterSpacing, track.node, { row: true }));

    body.appendChild(heading(t.colour));
    body.appendChild(colorControl({ label: t.textColour, value: s.color, placeholder: c.color, onChange: onStyle('color') }));
    body.appendChild(colorControl({ label: t.darkColour, value: s.color_dark, placeholder: s.color || c.color, onChange: onStyle('color_dark') }));

    body.appendChild(heading(t.alignment));
    body.appendChild(segment({
      label: t.alignment,
      value: s.align || null,
      allowNone: true,
      options: [
        { value: 'left', label: t.left, icon: ALIGN.left },
        { value: 'center', label: t.center, icon: ALIGN.center },
        { value: 'right', label: t.right, icon: ALIGN.right },
        { value: 'justify', label: t.justify, icon: ALIGN.justify },
      ],
      onChange: onStyle('align'),
    }));
  }

  var RATIOS = ['1:1', '16:9', '4:3', '3:2', '2:3', '21:9', '9:16'];
  var FILTER_SPEC = [
    ['grayscale', 0, 100, 1, '%'], ['sepia', 0, 100, 1, '%'], ['invert', 0, 100, 1, '%'], ['blur', 0, 20, 0.5, 'px'],
    ['brightness', 0, 200, 1, '%'], ['contrast', 0, 200, 1, '%'], ['saturate', 0, 300, 1, '%'], ['hue', 0, 360, 1, '°'], ['opacity', 0, 100, 1, '%'],
  ];
  var openTools = { crop: false, effects: false, size: false };

  function toolGroup(id, title, build) {
    var d = el('details', { class: 'lte-tool', 'data-tool': id });
    d.open = !!openTools[id];
    d.addEventListener('toggle', function () { openTools[id] = d.open; });
    d.appendChild(el('summary', { text: title }));
    var inner = el('div', { class: 'lte-tool-body' });
    build(inner);
    d.appendChild(inner);
    return d;
  }

  function inspectImage(body, slot) {
    var key = slot.key;
    if (slot.editable) {
      body.appendChild(imageField(slot, { prefix: 'el-', label: t.imageLabel }));
      var s0 = styleOf('image', key);
      var alt = el('input', { type: 'text', maxlength: '200', dir: 'auto', placeholder: (current.computed || {}).alt || '' });
      alt.value = s0.alt === undefined ? '' : s0.alt;
      alt.addEventListener('input', function () { setStyle('image', key, { alt: alt.value === '' ? null : alt.value }); });
      body.appendChild(field(t.altText, alt, { help: t.altHelp }));
    }
    if (!caps.image_style) {
      return;
    }
    var s = function () { return styleOf('image', key); };

    body.appendChild(toolGroup('crop', t.cropFocus, function (box) {
      var reset = linkButton(t.resetCrop, function () {
        clearStyle('image', key, ['ratio', 'zoom', 'fx', 'fy']);
        renderInspector();
      });
      var ratioBtns = el('div', { class: 'lte-chips', role: 'radiogroup', 'aria-label': t.aspectRatio });
      var mark = function () {
        var r = s().ratio || '';
        ratioBtns.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-checked', String(b.getAttribute('data-ratio') === r)); });
      };
      [''].concat(RATIOS).forEach(function (r) {
        var b = el('button', { type: 'button', role: 'radio', 'data-ratio': r, text: r || t.original });
        b.addEventListener('click', function () {
          setStyle('image', key, { ratio: r || null });
          mark();
          focus.refresh();
          cw.value = r ? r.split(':')[0] : '';
          chh.value = r ? r.split(':')[1] : '';
        });
        ratioBtns.appendChild(b);
      });
      mark();
      box.appendChild(el('div', { class: 'lte-h-row' }, [el('span', { class: 'lte-label', text: t.aspectRatio }), reset]));
      box.appendChild(ratioBtns);

      var cr = (s().ratio || '').split(':');
      var cw = el('input', { type: 'number', min: '1', max: '50', 'aria-label': t.customRatio + ' W', value: cr[1] ? cr[0] : '' });
      var chh = el('input', { type: 'number', min: '1', max: '50', 'aria-label': t.customRatio + ' H', value: cr[1] || '' });
      var applyCustom = function () {
        var w = parseInt(cw.value, 10);
        var hh = parseInt(chh.value, 10);
        if (w >= 1 && w <= 50 && hh >= 1 && hh <= 50) {
          setStyle('image', key, { ratio: w + ':' + hh });
          mark();
          focus.refresh();
        }
      };
      cw.addEventListener('input', applyCustom);
      chh.addEventListener('input', applyCustom);
      var swap = el('button', { type: 'button', class: 'lte-icon', title: t.swap, 'aria-label': t.swap }, [svgPath('M4 7h12 M13 4l3 3-3 3 M16 13H4 M7 10l-3 3 3 3')]);
      swap.addEventListener('click', function () {
        var a = cw.value;
        cw.value = chh.value;
        chh.value = a;
        applyCustom();
      });
      box.appendChild(field(t.customRatio, el('div', { class: 'lte-ratio' }, [cw, el('span', { text: ':' }), chh, swap]), { row: true }));

      var focus = focusPicker(slot, key);
      box.appendChild(focus.node);
      var zoom = slider({ label: t.zoom, value: s().zoom, neutral: 1, min: 1, max: 4, step: 0.05, unit: '×', onChange: function (v) { setStyle('image', key, { zoom: v }); focus.refresh(); } });
      var fx = slider({ label: t.focusX, value: s().fx, neutral: 50, min: 0, max: 100, step: 1, unit: '%', onChange: function (v) { setStyle('image', key, { fx: v }); focus.refresh(); } });
      var fy = slider({ label: t.focusY, value: s().fy, neutral: 50, min: 0, max: 100, step: 1, unit: '%', onChange: function (v) { setStyle('image', key, { fy: v }); focus.refresh(); } });
      focus.onPick = function (x, y) {
        fx.set(x);
        fy.set(y);
        setStyle('image', key, { fx: x, fy: y });
      };
      [zoom, fx, fy].forEach(function (c) { box.appendChild(c.node); });
    }));

    body.appendChild(toolGroup('effects', t.effects, function (box) {
      var ctrls = {};
      box.appendChild(el('div', { class: 'lte-h-row' }, [el('span', { class: 'lte-label', text: t.effects }), linkButton(t.resetEffects, function () {
        clearStyle('image', key, FILTER_SPEC.map(function (f) { return f[0]; }));
        FILTER_SPEC.forEach(function (f) { ctrls[f[0]].set(NEUTRAL.image[f[0]]); });
      })]));
      FILTER_SPEC.forEach(function (f) {
        ctrls[f[0]] = slider({ label: t['fx_' + f[0]], value: s()[f[0]], neutral: NEUTRAL.image[f[0]], min: f[1], max: f[2], step: f[3], unit: f[4], onChange: function (v) {
          var p = {};
          p[f[0]] = v;
          setStyle('image', key, p);
        } });
        box.appendChild(ctrls[f[0]].node);
      });
    }));

    body.appendChild(toolGroup('size', t.sizeTool, function (box) {
      box.appendChild(el('div', { class: 'lte-h-row' }, [el('span', { class: 'lte-label', text: t.sizeTool }), linkButton(t.resetSize, function () {
        clearStyle('image', key, ['width', 'align', 'radius']);
        renderInspector();
      })]));
      box.appendChild(slider({ label: t.width, value: s().width, neutral: (current.computed || {}).width || 100, min: 10, max: 100, step: 1, unit: '%', onChange: function (v) { setStyle('image', key, { width: v }); } }).node);
      box.appendChild(field(t.alignment, segment({
        label: t.alignment,
        value: s().align || null,
        allowNone: true,
        options: [{ value: 'left', label: t.left, icon: ALIGN.left }, { value: 'center', label: t.center, icon: ALIGN.center }, { value: 'right', label: t.right, icon: ALIGN.right }],
        onChange: function (v) { setStyle('image', key, { align: v }); },
      }), { row: true }));
      var radius = stepper({ label: t.radius, value: s().radius, placeholder: 0, min: 0, max: 200, step: 1, unit: 'px', onChange: function (v) { setStyle('image', key, { radius: v }); } });
      box.appendChild(field(t.radius, radius.node, { row: true }));
    }));
  }

  // Preview of the image with a draggable focus point (like BuilderJS Crop & Focus).
  function focusPicker(slot, key) {
    var api = { onPick: null };
    var img = el('img', { src: valueOf(key), alt: '', draggable: 'false' });
    var dot = el('span', { class: 'lte-focus-dot', 'aria-hidden': 'true' });
    var frameBox = el('div', { class: 'lte-focus' }, [img, dot]);
    var refresh = function () {
      var st = styleOf('image', key);
      var r = (st.ratio || '').split(':');
      frameBox.style.aspectRatio = r[1] ? r[0] + ' / ' + r[1] : '';
      img.style.objectFit = r[1] ? 'cover' : 'contain';
      var x = st.fx === undefined ? 50 : st.fx;
      var y = st.fy === undefined ? 50 : st.fy;
      img.style.objectPosition = x + '% ' + y + '%';
      img.style.transformOrigin = x + '% ' + y + '%';
      img.style.transform = st.zoom ? 'scale(' + st.zoom + ')' : '';
      dot.style.left = x + '%';
      dot.style.top = y + '%';
      img.src = valueOf(key);
    };
    var pick = function (e) {
      var rect = frameBox.getBoundingClientRect();
      var x = Math.round(Math.min(100, Math.max(0, ((e.clientX - rect.left) / rect.width) * 100)));
      var y = Math.round(Math.min(100, Math.max(0, ((e.clientY - rect.top) / rect.height) * 100)));
      if (api.onPick) {
        api.onPick(x, y);
      }
      refresh();
    };
    var dragging = false;
    frameBox.addEventListener('pointerdown', function (e) {
      dragging = true;
      frameBox.setPointerCapture(e.pointerId);
      pick(e);
    });
    frameBox.addEventListener('pointermove', function (e) {
      if (dragging) {
        pick(e);
      }
    });
    frameBox.addEventListener('pointerup', function () { dragging = false; });
    refresh();
    api.refresh = refresh;
    api.node = el('div', { class: 'lte-field' }, [frameBox, el('p', { class: 'lte-help', text: t.focusHelp })]);
    return api;
  }

  function inspectItem(body) {
    var loc = itemLocation(current.key);
    if (!loc) {
      return;
    }
    var i = loc.rep.refs.indexOf(loc.ref);
    if (caps.items) {
      var row = el('div', { class: 'lte-item-actions' });
      [
        [t.duplicate, 'duplicate', loc.rep.refs.length >= loc.rep.max],
        [t.moveUp, 'up', i === 0],
        [t.moveDown, 'down', i === loc.rep.refs.length - 1],
        [t.remove, 'remove', loc.rep.refs.length <= loc.rep.min],
      ].forEach(function (a) {
        var b = el('button', { type: 'button', class: 'lte-btn lte-btn-small' + (a[1] === 'remove' ? ' lte-btn-danger' : ''), text: a[0], disabled: a[2] });
        b.addEventListener('click', function () { itemAction(current.key, a[1]); });
        row.appendChild(b);
      });
      body.appendChild(row);
      body.appendChild(el('p', { class: 'lte-help', text: loc.rep.label + (loc.rep.detail ? ' · ' + loc.rep.detail : '') + ' — ' + (i + 1) + ' / ' + loc.rep.refs.length }));
    }
    var slots = itemSlots(loc.rep, loc.ref).map(function (k) { return slotIndex[k]; }).filter(function (x) { return x && x.editable; });
    if (slots.length) {
      var f = el('div', { class: 'lte-inspector-fields' });
      f.appendChild(fieldsFor(slots, 'el-'));
      body.appendChild(f);
    }
  }

  function inspectIcon(body, slot) {
    var key = slot.key;
    if (slot.editable) {
      body.appendChild(iconField(slot, { prefix: 'el-', label: t.iconLabel }));
    }
    if (!caps.text_style) {
      return;
    }
    var s = styleOf('icon', key);
    var c = current.computed || {};
    body.appendChild(el('div', { class: 'lte-h-row' }, [heading(t.iconStyle), linkButton(t.resetStyle, function () {
      clearStyle('icon', key, ['size', 'color', 'color_dark']);
      renderInspector();
    }, !Object.keys(s).length)]));
    var size = stepper({ label: t.size, value: s.size, placeholder: c.size, min: 8, max: 200, step: 1, unit: 'px', onChange: function (v) { setStyle('icon', key, { size: v }); } });
    body.appendChild(field(t.size, size.node, { row: true }));
    body.appendChild(colorControl({ label: t.iconColour, value: s.color, placeholder: c.color, onChange: function (v) { setStyle('icon', key, { color: v }); } }));
    body.appendChild(colorControl({ label: t.darkColour, value: s.color_dark, placeholder: s.color || c.color, onChange: function (v) { setStyle('icon', key, { color_dark: v }); } }));
    body.appendChild(el('p', { class: 'lte-help', text: t.iconColourHelp }));
  }

  function inspectSection(body, section) {
    var key = section.key;
    var tools = sectionTools(section, sections.filter(function (x) { return !x.required; }));
    if (tools.childNodes.length) {
      body.appendChild(el('div', { class: 'lte-h-row' }, [heading(t.sectionLabel), tools]));
    }
    if (caps.section_style) {
      var s = styleOf('section', key);
      var c = current.computed || {};
      body.appendChild(el('div', { class: 'lte-h-row' }, [heading(t.background), linkButton(t.resetStyle, function () {
        clearStyle('section', key, ['bg', 'bg_dark', 'pt', 'pb']);
        renderInspector();
      }, !Object.keys(s).length)]));
      body.appendChild(colorControl({ label: t.background, value: s.bg, placeholder: c.bg, onChange: function (v) { setStyle('section', key, { bg: v }); } }));
      body.appendChild(colorControl({ label: t.darkBackground, value: s.bg_dark, placeholder: s.bg || c.bg, onChange: function (v) { setStyle('section', key, { bg_dark: v }); } }));
      body.appendChild(heading(t.spacing));
      var pt = stepper({ label: t.paddingTop, value: s.pt, placeholder: c.pt, min: 0, max: 400, step: 4, unit: 'px', onChange: function (v) { setStyle('section', key, { pt: v }); } });
      body.appendChild(field(t.paddingTop, pt.node, { row: true }));
      var pb = stepper({ label: t.paddingBottom, value: s.pb, placeholder: c.pb, min: 0, max: 400, step: 4, unit: 'px', onChange: function (v) { setStyle('section', key, { pb: v }); } });
      body.appendChild(field(t.paddingBottom, pb.node, { row: true }));
    }
    var editableCount = section.slots.filter(function (x) { return x.editable; }).length;
    if (editableCount) {
      var jump = el('button', { type: 'button', class: 'lte-btn lte-btn-small', text: t.editSectionContent });
      jump.addEventListener('click', function () {
        openSections[key] = true;
        selectTab('content');
        var d = contentPane.querySelector('[data-section="' + key + '"]');
        if (d) {
          d.open = true;
          d.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
      });
      body.appendChild(el('div', { class: 'lte-field' }, [jump]));
    }
  }

  function runTool(kind, key, tool) {
    var slot = slotIndex[key];
    if (!slot) {
      return;
    }
    if (tool === 'replace') {
      if (!current || current.key !== key) {
        current = { kind: 'image', key: key, section: slotSection[key] ? slotSection[key].key : null, computed: {} };
        renderInspector();
      }
      selectTab('element');
      var input = fileInputs[key];
      if (input && input.isConnected) {
        input.click();            // user activation propagates from the iframe click
      }
      return;
    }
    if (tool === 'icon') {
      openIconPicker(key);
      return;
    }
    if (openTools[tool] !== undefined) {
      openTools[tool] = true;
      selectTab('element');
      var d = inspector.querySelector('[data-tool="' + tool + '"]');
      if (d) {
        d.open = true;
        d.scrollIntoView({ block: 'start', behavior: 'smooth' });
      }
    }
  }

  /* ------------------------------------------------------------ theme pane */
  var styleBox = doc.querySelector('[data-style-fields]');

  function rgbOf(hex) {
    var h = String(hex).replace('#', '');
    if (h.length === 3) {
      h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2);
    }
    return [0, 2, 4].map(function (i) { return parseInt(h.substr(i, 2), 16) || 0; }).join(',');
  }

  function paletteVars(entry, color) {
    var vars = {};
    Object.keys(entry.vars || {}).forEach(function (name) {
      vars[name] = color === null ? null : String(entry.vars[name]).split('{c}').join(color).split('{rgb}').join(rgbOf(color));
    });
    return vars;
  }

  function renderStyle() {
    styleBox.textContent = '';
    if (!caps.color && !caps.theme) {
      styleBox.appendChild(el('p', { class: 'lte-locked', text: t.lockedStyle }));
      return;
    }
    if (caps.color && cfg.palette.length) {
      styleBox.appendChild(el('h3', { class: 'lte-h', text: t.colours }));
      cfg.palette.forEach(function (p) {
        var cur = content.palette[p.key] || p.default;
        var picker = el('input', { type: 'color', id: 'pal-' + p.key, value: cur });
        var hex = el('input', { type: 'text', value: cur, maxlength: '7', spellcheck: 'false', dir: 'ltr', 'aria-label': p.label + ' (hex)' });
        var reset = el('button', { type: 'button', class: 'lte-link', text: t.reset, hidden: cur.toLowerCase() === p.default.toLowerCase() });
        var apply = function (v) {
          v = v.toLowerCase();
          if (!/^#([0-9a-f]{3}|[0-9a-f]{6})$/.test(v)) {
            return;
          }
          var isDefault = v === p.default.toLowerCase();
          if (isDefault) {
            delete content.palette[p.key];
          } else {
            content.palette[p.key] = v;
          }
          send({ type: 'lt:palette', vars: paletteVars(p, isDefault ? null : v) });
          reset.hidden = isDefault;
          changed();
        };
        picker.addEventListener('input', function () { hex.value = picker.value; apply(picker.value); });
        hex.addEventListener('input', function () {
          if (/^#([0-9a-f]{6})$/i.test(hex.value)) {
            picker.value = hex.value;
          }
          apply(hex.value);
        });
        reset.addEventListener('click', function () { picker.value = hex.value = p.default; apply(p.default); });
        styleBox.appendChild(el('div', { class: 'lte-field' }, [
          el('div', { class: 'lte-field-head' }, [el('label', { for: picker.id, text: p.label }), reset]),
          el('div', { class: 'lte-color' }, [picker, hex]),
        ]));
      });
    }
    if (caps.theme) {
      styleBox.appendChild(el('h3', { class: 'lte-h', text: t.appearance }));
      var theme = content.settings.theme || cfg.themeDefault;
      var group = el('div', { class: 'lte-segment', role: 'radiogroup', 'aria-label': t.appearance });
      ['light', 'dark'].forEach(function (mode) {
        var b = el('button', { type: 'button', role: 'radio', 'aria-checked': String(theme === mode), text: t[mode] });
        b.addEventListener('click', function () {
          content.settings.theme = mode;
          group.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-checked', String(x === b)); });
          send({ type: 'lt:theme', theme: mode });
          changed();
        });
        group.appendChild(b);
      });
      styleBox.appendChild(group);

      styleBox.appendChild(el('h3', { class: 'lte-h', text: t.direction }));
      var dir = content.settings.dir || cfg.dirDefault;
      var dirGroup = el('div', { class: 'lte-segment', role: 'radiogroup', 'aria-label': t.direction });
      [['ltr', t.ltr], ['rtl', t.rtl]].forEach(function (d) {
        var b = el('button', { type: 'button', role: 'radio', 'aria-checked': String(dir === d[0]), text: d[1] });
        b.addEventListener('click', function () {
          content.settings.dir = d[0];
          dirGroup.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-checked', String(x === b)); });
          save({ reload: true });
        });
        dirGroup.appendChild(b);
      });
      styleBox.appendChild(dirGroup);
      styleBox.appendChild(el('p', { class: 'lte-help lte-help-pad', text: t.directionHelp }));
    }
  }

  /* ------------------------------------------------------------ save / publish */
  var saveState = doc.querySelector('[data-save-state]');
  var statusEl = doc.querySelector('[data-status]');
  var publishBtn = doc.querySelector('[data-publish]');

  function renderStatus() {
    statusEl.className = 'lte-status ' + (isLive ? (hasUnpublished ? 'is-pending' : 'is-live') : 'is-draft');
    statusEl.textContent = isLive ? (hasUnpublished ? t.publishedChanges : t.published) : t.draft;
    publishBtn.textContent = isLive && hasUnpublished ? t.publishChanges : t.publish;
    publishBtn.disabled = isLive && !hasUnpublished && !dirty;
  }

  function changed() {
    dirty = true;
    hasUnpublished = true;
    saveState.textContent = t.unsaved;
    renderStatus();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () { save(); }, 1200);
  }

  function payload() {
    var out = {};
    var slots = {};
    // Only overrides travel; null resets a slot to its template default (defaults
    // themselves — e.g. a generated QR image — are never posted back).
    editableKeys().forEach(function (k) {
      slots[k] = Object.prototype.hasOwnProperty.call(content.slots, k) ? content.slots[k] : null;
    });
    // Values carried into a brand-new section copy (its fields load after the save).
    Object.keys(content.slots).forEach(function (k) {
      if (!slotIndex[k]) {
        slots[k] = content.slots[k];
      }
    });
    out.slots = slots;
    if (anyStyleCap) {
      // Full desired state; the server applies only what this user may change.
      out.styles = content.styles;
      out.section_styles = content.section_styles;
    }
    if (caps.color) {
      var pal = {};
      cfg.palette.forEach(function (p) { pal[p.key] = content.palette[p.key] || p.default; });
      out.palette = pal;
    }
    if (caps.theme && (content.settings.theme || content.settings.dir)) {
      out.settings = {};
      if (content.settings.theme) {
        out.settings.theme = content.settings.theme;
      }
      if (content.settings.dir) {
        out.settings.dir = content.settings.dir;
      }
    }
    if (caps.sections_toggle || caps.sections_reorder || caps.sections_duplicate || caps.sections_add) {
      out.sections = sectionsPayload();
    }
    if (caps.items) {
      var items = {};
      Object.keys(repIndex).forEach(function (k) { items[k] = repIndex[k].rep.refs.slice(); });
      Object.keys(content.items).forEach(function (k) {
        if (!items[k]) {
          items[k] = content.items[k];          // a new section copy's lists (config comes on reload)
        }
      });
      out.items = items;
    }
    return out;
  }

  function save(opts) {
    opts = opts || {};
    clearTimeout(saveTimer);
    if (saving) {
      return saving.then(function () { return dirty || opts.reload ? save(opts) : null; });
    }
    saveState.textContent = t.saving;
    dirty = false;
    saving = fetch(cfg.saveUrl, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ content: payload() }),
    })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
      .then(function (j) {
        saveState.textContent = t.saved;
        if (j.rejected && j.rejected.length) {
          content = normalize(j.content);
          toast(t.rejected);
          renderContent();
          renderStyle();
          renderInspector();
          reloadFrame();
        } else if (opts.reload) {
          reloadFrame();
        }
      })
      .catch(function () {
        dirty = true;
        saveState.textContent = t.saveFailed;
      })
      .then(function () { saving = null; });
    return saving;
  }

  function publish(unpublish) {
    publishBtn.disabled = true;
    publishBtn.textContent = t.publishing;
    return save()
      .then(function () {
        if (dirty) {
          return Promise.reject(new Error('unsaved'));
        }
        return fetch(cfg.publishUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          body: JSON.stringify(unpublish ? { unpublish: 1 } : {}),
        });
      })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
      .then(function (j) {
        isLive = j.status === 'published';
        hasUnpublished = !isLive;
        toast(isLive ? t.publishedToast : t.unpublishedToast);
      })
      .catch(function () { toast(t.saveFailed); })
      .then(renderStatus);
  }

  publishBtn.addEventListener('click', function () { publish(false); });

  doc.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
      e.preventDefault();
      save();
    }
  });

  window.addEventListener('beforeunload', function (e) {
    if (dirty || saving) {
      e.preventDefault();
      e.returnValue = t.leave;
    }
  });

  /* ------------------------------------------------------------ chrome */
  var toastEl = doc.querySelector('[data-toast]');
  var toastTimer = null;

  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3500);
  }

  function selectTab(name) {
    doc.querySelectorAll('[data-tab]').forEach(function (b) { b.setAttribute('aria-selected', String(b.getAttribute('data-tab') === name)); });
    doc.querySelectorAll('[data-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
  }

  doc.querySelectorAll('[data-tab]').forEach(function (b) {
    b.addEventListener('click', function () { selectTab(b.getAttribute('data-tab')); });
  });

  var wrap = doc.querySelector('[data-frame-wrap]');
  doc.querySelectorAll('[data-device]').forEach(function (b) {
    if (b === wrap) {
      return;
    }
    b.addEventListener('click', function () {
      var d = b.getAttribute('data-device');
      wrap.setAttribute('data-device', d);
      doc.querySelectorAll('.lte-devices [data-device]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
    });
  });

  var shell = doc.querySelector('.lte');
  function setView(v) {
    shell.setAttribute('data-view', v);
    doc.querySelectorAll('[data-view-btn]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-view-btn') === v)); });
  }
  doc.querySelectorAll('[data-view-btn]').forEach(function (b) {
    b.addEventListener('click', function () { setView(b.getAttribute('data-view-btn')); });
  });

  // Unpublish affordance lives on the status pill when the page is live.
  statusEl.setAttribute('role', 'button');
  statusEl.setAttribute('tabindex', '0');
  statusEl.title = t.unpublish;
  function maybeUnpublish() {
    if (isLive && window.confirm(t.unpublish + '?')) {
      publish(true);
    }
  }
  statusEl.addEventListener('click', maybeUnpublish);
  statusEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      maybeUnpublish();
    }
  });

  renderContent();
  renderInspector();
  renderStyle();
  renderStatus();
  saveState.textContent = '';
  // Open the first section with editable fields so the panel never starts empty-looking.
  var first = sections.filter(function (s) { return s.slots.some(function (x) { return x.editable; }); })[1] || sections[0];
  if (first) {
    openSections[first.key] = true;
    var d0 = contentPane.querySelector('[data-section="' + first.key + '"]');
    if (d0) {
      d0.open = true;
    }
  }
})();
