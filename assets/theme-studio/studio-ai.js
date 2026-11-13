// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Theme Studio — "Describe your store" AI theme builder (owner side).
 * Spec rev/theme-studio/IMPLEMENTATION-SPEC.md §4 Phase D (D1 inputs, D2 step 9
 * "Present"); contract rev/theme-studio/AI-BUILDER-CONTRACT.md.
 *
 *   brief     → POST urls.ai_generate {brief:{text,vibes,priority,colors}, images}
 *               (can take ~60 s: staged progress, cancellable via AbortController)
 *   proposals → 3 cards, each a lazy phone-frame iframe of urls.ai_preview
 *               (__SET__/__P__); on phones only the card in view keeps its iframe
 *   use this  → POST urls.ai_apply → follow {redirect}
 *
 * Reads the editor config (#theme-studio-config: caps, urls, csrf, store) and its
 * own strings (#theme-studio-ai-i18n). Separate from studio.js on purpose; when
 * the editor is open it asks window.ThemeStudio to flush pending edits before an
 * apply so a late autosave can never overwrite the AI layout.
 *
 * Turbo Drive: global listeners are installed once; boot() is idempotent (one
 * instance per #ts-ai dialog node) and torn down on turbo:before-cache.
 */
(function () {
  'use strict';

  if (window.ThemeStudioAi && typeof window.ThemeStudioAi.boot === 'function') {
    window.ThemeStudioAi.boot();
    return;
  }

  var MAX_TEXT = 500;
  var MAX_COLORS = 3;
  var SLOW_AFTER_MS = 75000;
  var GIVE_UP_MS = 150000;
  var STAGES = [
    [0, 'Reading your store and menu…'],
    [7000, 'Choosing layouts for your kind of store…'],
    [17000, 'Picking colours and fonts…'],
    [28000, 'Writing your headlines…'],
    [42000, 'Checking that every design works on phones…'],
    [56000, 'Almost there…']
  ];
  var DEFAULT_COLORS = ['#c2410c', '#1f2937', '#f5f0e6'];
  var HEX = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;
  var PHONE_W = 390;
  var PHONE_H = 844;

  function isObj(o) { return !!o && typeof o === 'object' && !Array.isArray(o); }

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) { return; }
      if (k === 'text') { el.textContent = String(v); } else { el.setAttribute(k, v === true ? '' : String(v)); }
    });
    (kids || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) { return; }
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
  }

  function readJson(id) {
    var el = document.getElementById(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || 'null'); } catch (e) { return null; }
  }

  /* Tokens may arrive flat ({"colors.primary": "#…"}) or nested ({colors:{primary}}). */
  function flatten(obj, prefix, out) {
    out = out || {};
    Object.keys(obj || {}).forEach(function (k) {
      var key = prefix ? prefix + '.' + k : k;
      if (isObj(obj[k])) { flatten(obj[k], key, out); } else { out[key] = obj[k]; }
    });
    return out;
  }
  function palette(tokens) {
    var flat = flatten(isObj(tokens) ? tokens : {}, '', {});
    var seen = {};
    return Object.keys(flat).filter(function (k) {
      var v = String(flat[k] || '').trim();
      if (!HEX.test(v) || seen[v.toLowerCase()]) { return false; }
      seen[v.toLowerCase()] = 1;
      return true;
    }).slice(0, 6).map(function (k) {
      return { key: k.split('.').pop(), value: String(flat[k]).trim() };
    });
  }
  function fontName(fp) {
    if (!fp) { return ''; }
    if (typeof fp === 'string') { return fp; }
    if (!isObj(fp)) { return ''; }
    if (fp.name || fp.label) { return String(fp.name || fp.label); }
    var parts = [fp.heading, fp.body].filter(function (x) { return typeof x === 'string' && x; });
    return parts.join(' / ');
  }

  function createAi(dlg, cfg, ai) {
    var urls = isObj(cfg.urls) ? cfg.urls : {};
    var strings = isObj(ai && ai.strings) ? ai.strings : {};
    var editorMode = !!(ai && ai.editor);
    var store = isObj(cfg.store) ? cfg.store : {};
    var lang = String(store.default_language || '');
    var cleanups = [];
    var destroyed = false;

    var S = {
      view: 'form',
      busy: false,
      ctrl: null,
      timers: [],
      opener: null,
      brief: null,
      images: false,
      setId: null,
      proposals: [],
      active: 0,
      armed: null,        // proposal id awaiting "Replace my draft" (editor mode)
      remaining: ai && typeof ai.remaining === 'number' ? ai.remaining : null,
      io: null,
      ro: null
    };

    function t(key, rep) {
      var v = Object.prototype.hasOwnProperty.call(strings, key) ? String(strings[key]) : key;
      Object.keys(rep || {}).forEach(function (k) { v = v.split(':' + k).join(String(rep[k])); });
      return v;
    }
    function q(sel) { return dlg.querySelector(sel); }
    function qa(sel) { return Array.prototype.slice.call(dlg.querySelectorAll(sel)); }
    function on(el, ev, fn) {
      if (!el) { return; }
      el.addEventListener(ev, fn);
      cleanups.push(function () { el.removeEventListener(ev, fn); });
    }
    function toast(msg, type) {
      if (window.dashToast) { window.dashToast(msg, { type: type || 'success' }); }
    }
    function isPhone() {
      return !!(window.matchMedia && window.matchMedia('(max-width: 899.98px)').matches);
    }
    function go(url) {
      if (window.Turbo && typeof window.Turbo.visit === 'function') { window.Turbo.visit(url); } else { location.href = url; }
    }
    function fill(tpl, set, pid) {
      return String(tpl || '').split('__SET__').join(encodeURIComponent(set)).split('__P__').join(encodeURIComponent(pid));
    }
    function withLang(url) {
      if (!lang) { return url; }
      try {
        var u = new URL(url, location.origin);
        u.searchParams.set('lang', lang);
        return u.origin === location.origin ? u.pathname + u.search + u.hash : u.toString();
      } catch (e) { return url; }
    }

    var els = {
      form: q('[data-ts-ai-form]'),
      text: q('[data-ts-ai-text]'),
      count: q('[data-ts-ai-count]'),
      colors: q('[data-ts-ai-colors]'),
      addColor: q('[data-ts-ai-add-color]'),
      images: q('[data-ts-ai-images]'),
      error: q('[data-ts-ai-error]'),
      errorText: q('[data-ts-ai-error-text]'),
      generate: q('[data-ts-ai-generate]'),
      stage: q('[data-ts-ai-stage]'),
      bar: q('[data-ts-ai-bar]'),
      cancel: q('[data-ts-ai-cancel]'),
      cards: q('[data-ts-ai-cards]'),
      pager: q('[data-ts-ai-pager]'),
      fallback: q('[data-ts-ai-fallback]'),
      back: q('[data-ts-ai-back]'),
      regenerate: q('[data-ts-ai-regenerate]')
    };

    /* ------------------------------------------------------------ views */
    function show(view, focus) {
      S.view = view;
      qa('[data-ts-ai-view]').forEach(function (v) { v.hidden = v.getAttribute('data-ts-ai-view') !== view; });
      dlg.setAttribute('data-view', view);
      if (view === 'progress') { dlg.setAttribute('data-ui-persistent', ''); } else { dlg.removeAttribute('data-ui-persistent'); }
      if (focus === false) { return; }
      var pane = q('[data-ts-ai-view="' + view + '"]');
      var target = focus || (pane && (pane.querySelector('[data-ts-ai-focus]') || pane.querySelector('textarea, button, input')));
      if (target && typeof target.focus === 'function') { setTimeout(function () { if (!destroyed) { target.focus(); } }, 30); }
    }

    function open(opener) {
      if (typeof dlg.showModal !== 'function') { return; }
      S.opener = opener || document.activeElement;
      if (!dlg.open) { dlg.showModal(); }
      if (S.view === 'progress') { show('progress'); return; }
      show(S.proposals.length ? 'results' : 'form');
      renderRemaining();
      if (S.view === 'results') { observeCards(); }
    }
    on(dlg, 'close', function () {
      // Escape / close while working = cancel.
      if (S.busy) { abort(true); }
      unloadFrames();
      var back = S.opener;
      S.opener = null;
      if (back && back.isConnected && typeof back.focus === 'function') { setTimeout(function () { back.focus(); }, 0); }
    });

    /* ------------------------------------------------------------ brief */
    function updateCount() {
      if (!els.text || !els.count) { return; }
      var n = els.text.value.length;
      els.count.textContent = n + ' / ' + MAX_TEXT;
      els.count.setAttribute('aria-label', t(':n of :max characters', { n: n, max: MAX_TEXT }));
      els.count.classList.toggle('is-near', n >= MAX_TEXT - 50);
    }
    on(els.text, 'input', updateCount);
    updateCount();

    qa('[data-ts-ai-vibe]').forEach(function (b) {
      on(b, 'click', function () {
        b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
      });
    });

    function colorRows() { return els.colors ? Array.prototype.slice.call(els.colors.querySelectorAll('[data-ts-ai-color]')) : []; }
    function renumberColors() {
      colorRows().forEach(function (row, i) {
        var input = row.querySelector('input');
        var rm = row.querySelector('button');
        if (input) { input.setAttribute('aria-label', t('Colour :n', { n: i + 1 })); }
        if (rm) { rm.setAttribute('aria-label', t('Remove colour :n', { n: i + 1 })); }
      });
      if (els.addColor) { els.addColor.hidden = colorRows().length >= MAX_COLORS; }
    }
    function addColor(value, focus) {
      if (!els.colors || colorRows().length >= MAX_COLORS) { return; }
      var input = h('input', { type: 'color', value: value || DEFAULT_COLORS[colorRows().length] || '#333333', class: 'ts-ai__color-input' });
      var code = h('span', { class: 'ts-ai__color-code', 'aria-hidden': 'true', text: input.value.toUpperCase() });
      var rm = h('button', { type: 'button', class: 'ui-icon-btn ts-ai__color-rm' }, ['×']);
      var row = h('div', { class: 'ts-ai__color', 'data-ts-ai-color': '' }, [input, code, rm]);
      input.addEventListener('input', function () { code.textContent = input.value.toUpperCase(); });
      rm.addEventListener('click', function () {
        var rows = colorRows();
        var idx = rows.indexOf(row);
        row.remove();
        renumberColors();
        var next = colorRows()[Math.max(0, idx - 1)];
        (next ? next.querySelector('input') : els.addColor).focus();
      });
      els.colors.appendChild(row);
      renumberColors();
      if (focus) { input.focus(); }
    }
    on(els.addColor, 'click', function () { addColor(null, true); });
    renumberColors();

    function readBrief() {
      var brief = {};
      var text = els.text ? els.text.value.trim().slice(0, MAX_TEXT) : '';
      if (text) { brief.text = text; }
      var vibes = qa('[data-ts-ai-vibe][aria-pressed="true"]').map(function (b) { return b.getAttribute('data-ts-ai-vibe'); });
      if (vibes.length) { brief.vibes = vibes; }
      var pr = q('[data-ts-ai-priority]:checked');
      if (pr && pr.value) { brief.priority = pr.value; }
      var colors = colorRows().map(function (r) { var i = r.querySelector('input'); return i ? i.value.toLowerCase() : ''; })
        .filter(function (c) { return HEX.test(c); });
      if (colors.length) { brief.colors = colors.slice(0, MAX_COLORS); }
      return brief;
    }

    /* ------------------------------------------------------------ errors */
    function clearErrors() {
      if (els.error) { els.error.hidden = true; }
      qa('[data-ts-ai-field]').forEach(function (f) { f.classList.remove('ui-field--error'); });
      qa('[data-ts-ai-field-error]').forEach(function (e) {
        if (e.hasAttribute('data-default')) { e.textContent = e.getAttribute('data-default'); }
      });
      if (els.text) { els.text.removeAttribute('aria-invalid'); }
    }
    function showError(msg, fieldErrors) {
      show('form', false);
      if (els.error && els.errorText) {
        els.error.hidden = false;
        els.errorText.textContent = msg;
      }
      var firstBad = null;
      Object.keys(fieldErrors || {}).forEach(function (key) {
        // brief.text / brief.colors.0 / images → the matching field
        var name = String(key).replace(/^brief\./, '').split('.')[0];
        var field = q('[data-ts-ai-field="' + name + '"]');
        var slot = q('[data-ts-ai-field-error="' + name + '"]');
        var text = fieldErrors[key];
        if (Array.isArray(text)) { text = text[0]; }
        if (field) { field.classList.add('ui-field--error'); }
        if (slot) {
          if (!slot.hasAttribute('data-default')) { slot.setAttribute('data-default', slot.textContent); }
          slot.textContent = String(text || '');
        }
        if (name === 'text' && els.text) { els.text.setAttribute('aria-invalid', 'true'); }
        if (!firstBad && field) { firstBad = field.querySelector('textarea, input, button'); }
      });
      var target = firstBad || els.generate;
      if (target) { setTimeout(function () { if (!destroyed) { target.focus(); } }, 30); }
    }

    function renderRemaining() {
      qa('[data-ts-ai-remaining]').forEach(function (p) {
        if (typeof S.remaining !== 'number') { p.hidden = true; return; }
        p.hidden = false;
        p.textContent = S.remaining > 0 ? t('You can create :n more designs today.', { n: S.remaining }) : t('This was your last AI design for today.');
      });
      if (els.regenerate) { els.regenerate.disabled = S.remaining === 0; }
    }

    /* ------------------------------------------------------------ network */
    function api(method, url, body, signal) {
      var headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
      if (cfg.csrf) { headers['X-CSRF-TOKEN'] = cfg.csrf; }
      var init = { method: method, headers: headers, credentials: 'same-origin', signal: signal };
      if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
      }
      return fetch(url, init).then(function (res) {
        if (window.LivePoll && typeof window.LivePoll.guard === 'function' && !window.LivePoll.guard(res)) {
          var err = new Error('session');
          err.session = true;
          throw err;
        }
        var ct = res.headers.get('content-type') || '';
        var read = ct.indexOf('json') !== -1 ? res.json().catch(function () { return null; }) : res.text().then(function () { return null; });
        return read.then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
      });
    }
    function remainingFrom(data) {
      if (!isObj(data)) { return; }
      var r = data.remaining;
      if (r === undefined && isObj(data.limits)) { r = data.limits.remaining; }
      if (typeof r === 'number' && isFinite(r)) { S.remaining = Math.max(0, Math.floor(r)); }
    }

    /* ------------------------------------------------------------ generate */
    function stopTimers() {
      S.timers.forEach(clearTimeout);
      S.timers = [];
    }
    function startProgress() {
      stopTimers();
      if (els.bar) {
        els.bar.classList.remove('is-running');
        void els.bar.offsetWidth; // restart the CSS fill animation
        els.bar.classList.add('is-running');
      }
      STAGES.forEach(function (st) {
        S.timers.push(setTimeout(function () {
          if (els.stage) { els.stage.textContent = t(st[1]); }
          if (els.bar) { els.bar.setAttribute('aria-valuetext', t(st[1])); }
        }, st[0]));
      });
      S.timers.push(setTimeout(function () {
        if (els.stage) { els.stage.textContent = t('This is taking longer than usual. You can keep waiting or cancel.'); }
      }, SLOW_AFTER_MS));
      S.timers.push(setTimeout(function () { abort(false); }, GIVE_UP_MS));
    }

    function abort(byUser) {
      if (!S.busy) { return; }
      S.cancelled = byUser ? 'user' : 'timeout';
      if (S.ctrl) { try { S.ctrl.abort(); } catch (e) { /* ignore */ } }
    }

    function generate(brief, images) {
      if (S.busy || !urls.ai_generate) { return; }
      clearErrors();
      S.busy = true;
      S.cancelled = null;
      S.brief = brief;
      S.images = !!images;
      S.ctrl = typeof AbortController === 'function' ? new AbortController() : null;
      show('progress');
      startProgress();
      var payload = { brief: brief };
      if (images) { payload.images = true; }

      api('POST', urls.ai_generate, payload, S.ctrl ? S.ctrl.signal : undefined).then(function (res) {
        finish();
        if (destroyed) { return; }
        remainingFrom(res.data);
        renderRemaining();
        var data = isObj(res.data) ? res.data : {};
        if (res.ok && Array.isArray(data.proposals) && data.proposals.length) {
          S.setId = String(data.set_id || '');
          S.proposals = data.proposals.filter(isObj).slice(0, 3);
          S.active = 0;
          S.armed = null;
          renderResults(!!data.fallback);
          return;
        }
        if (res.status === 429) {
          S.remaining = 0;
          renderRemaining();
          showError(data.message ? String(data.message) : t('You have reached today’s limit for AI designs. Try again tomorrow.'));
          return;
        }
        if (res.status === 422) {
          showError(data.message ? String(data.message) : t('Please check your answers.'), isObj(data.errors) ? data.errors : null);
          return;
        }
        if (res.status === 401 || res.status === 419) {
          showError(t('Your session has ended. Reload the page and sign in again.'));
          return;
        }
        showError(t('Something went wrong while creating your designs. Try again.'));
      }).catch(function (err) {
        var why = S.cancelled;
        finish();
        if (destroyed) { return; }
        if (why === 'user') {
          show('form', dlg.open ? undefined : false);
          toast(t('Cancelled. Nothing was changed.'), 'info');
          return;
        }
        if (err && err.session) {
          showError(t('Your session has ended. Reload the page and sign in again.'));
          return;
        }
        showError(why === 'timeout' ? t('Something went wrong while creating your designs. Try again.') : t('Could not reach the server. Check your connection and try again.'));
      });
    }
    function finish() {
      S.busy = false;
      S.ctrl = null;
      stopTimers();
      if (els.bar) { els.bar.classList.remove('is-running'); }
    }

    on(els.form, 'submit', function (e) {
      e.preventDefault();
      generate(readBrief(), !!(els.images && els.images.checked));
    });
    on(els.cancel, 'click', function () { abort(true); });
    on(els.back, 'click', function () { unloadFrames(); show('form'); });
    on(els.regenerate, 'click', function () {
      unloadFrames();
      generate(S.brief || readBrief(), S.images);
    });

    /* ------------------------------------------------------------ proposals */
    function previewUrl(p) { return withLang(fill(urls.ai_preview, S.setId, String(p.id || ''))); }

    function renderResults(fallback) {
      if (els.fallback) { els.fallback.hidden = !fallback; }
      if (!els.cards) { return; }
      unloadFrames();
      els.cards.textContent = '';
      S.proposals.forEach(function (p, i) {
        var name = String(p.name || t('Design :n of :total', { n: i + 1, total: S.proposals.length }));
        var sw = palette(p.tokens);
        var swatches = h('ul', { class: 'ts-ai-card__swatches', role: 'list', 'aria-label': t('Colours') }, sw.map(function (c) {
          var dot = h('span', { class: 'ts-ai-card__swatch', 'aria-hidden': 'true' });
          dot.style.backgroundColor = c.value;
          return h('li', { title: c.key + ' ' + c.value }, [dot, h('span', { class: 'ui-visually-hidden', text: c.key + ' ' + c.value })]);
        }));
        var font = fontName(p.font_pair);
        var hero = isObj(p.hero_image) && p.hero_image.source === 'ai';
        var frame = h('div', { class: 'ts-ai-card__phone', 'data-ts-ai-phone': '' }, [
          h('div', { class: 'ts-ai-card__placeholder', 'aria-hidden': 'true' }, [h('span', { class: 'ts-ai-card__spinner' }), t('Loading preview…')])
        ]);
        var use = h('button', { type: 'button', class: 'ui-btn ts-ai-card__use', 'data-ts-ai-use': String(p.id || '') }, [
          h('span', { class: 'ui-btn__spinner', 'aria-hidden': 'true' }), t('Use this')
        ]);
        var full = h('a', { class: 'ts-ai-card__full', href: previewUrl(p), target: '_blank', rel: 'noopener', text: t('Open full preview') });
        var card = h('li', {
          class: 'ts-ai-card', 'data-ts-ai-card': String(i), 'aria-roledescription': 'slide',
          'aria-label': t('Design :n of :total', { n: i + 1, total: S.proposals.length })
        }, [
          frame,
          h('div', { class: 'ts-ai-card__body' }, [
            h('h4', { class: 'ts-ai-card__name', text: name }),
            p.rationale ? h('p', { class: 'ts-ai-card__why', text: String(p.rationale) }) : null,
            sw.length ? swatches : null,
            font ? h('p', { class: 'ts-ai-card__font' }, [h('span', { class: 'ts-ai-card__meta', text: t('Fonts') + ': ' }), font]) : null,
            hero ? h('p', { class: 'ts-ai-card__meta', text: t('AI hero image included') }) : null,
            h('div', { class: 'ts-ai-card__confirm', 'data-ts-ai-confirm': '', role: 'group', hidden: true }, [
              h('p', { text: t('Your current draft is replaced by this design. Your live storefront does not change until you publish.') }),
              h('div', { class: 'ts-ai-card__confirm-actions' }, [
                h('button', { type: 'button', class: 'ui-btn ui-btn--secondary ui-btn--sm', 'data-ts-ai-keep': '' }, [t('Keep my draft')]),
                h('button', { type: 'button', class: 'ui-btn ui-btn--sm', 'data-ts-ai-confirm-use': String(p.id || '') }, [
                  h('span', { class: 'ui-btn__spinner', 'aria-hidden': 'true' }), t('Replace my draft')
                ])
              ])
            ]),
            h('div', { class: 'ts-ai-card__actions' }, [use, full])
          ])
        ]);
        card.__tsAiName = name;
        els.cards.appendChild(card);
      });
      show('results');
      toast(t('Your 3 designs are ready.'), 'success');
      observeCards();
      updatePager();
    }

    function loadFrame(card) {
      var i = Number(card.getAttribute('data-ts-ai-card'));
      var p = S.proposals[i];
      var phone = card.querySelector('[data-ts-ai-phone]');
      if (!p || !phone || phone.querySelector('iframe')) { return; }
      var f = h('iframe', {
        class: 'ts-ai-card__frame', src: previewUrl(p), title: t('Preview of :name', { name: card.__tsAiName || '' }),
        loading: 'lazy', referrerpolicy: 'same-origin', tabindex: '-1', width: String(PHONE_W), height: String(PHONE_H)
      });
      f.addEventListener('load', function () { phone.classList.add('is-loaded'); });
      phone.appendChild(f);
      scalePhone(phone);
    }
    function unloadFrame(card) {
      var phone = card.querySelector('[data-ts-ai-phone]');
      var f = phone && phone.querySelector('iframe');
      if (f) { f.remove(); phone.classList.remove('is-loaded'); }
    }
    function unloadFrames() {
      if (S.io) { S.io.disconnect(); S.io = null; }
      if (S.ro) { S.ro.disconnect(); S.ro = null; }
      if (els.cards) { Array.prototype.slice.call(els.cards.children).forEach(unloadFrame); }
    }
    function scalePhone(phone) {
      var w = phone.clientWidth;
      if (w > 0) { phone.style.setProperty('--ts-ai-scale', String(w / PHONE_W)); }
    }
    function cards() { return els.cards ? Array.prototype.slice.call(els.cards.children) : []; }

    /*
     * Desktop: every card's iframe loads once it scrolls into view. Phones: the
     * cards are a swipe row and only the card most in view keeps an iframe (the
     * others are unloaded) — three live storefronts at once is too heavy there.
     */
    function observeCards() {
      if (S.io) { S.io.disconnect(); }
      if (S.ro) { S.ro.disconnect(); }
      var list = cards();
      if (typeof ResizeObserver === 'function') {
        S.ro = new ResizeObserver(function (entries) { entries.forEach(function (e) { scalePhone(e.target); }); });
        list.forEach(function (c) { var ph = c.querySelector('[data-ts-ai-phone]'); if (ph) { S.ro.observe(ph); } });
      }
      if (typeof IntersectionObserver !== 'function') {
        list.forEach(loadFrame);
        return;
      }
      var ratios = {};
      S.io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { ratios[e.target.getAttribute('data-ts-ai-card')] = e.intersectionRatio; });
        if (!isPhone()) {
          entries.forEach(function (e) { if (e.isIntersecting) { loadFrame(e.target); } });
          return;
        }
        var best = null;
        Object.keys(ratios).forEach(function (k) { if (ratios[k] >= 0.6 && (best === null || ratios[k] > ratios[best])) { best = k; } });
        if (best === null) { return; }
        S.active = Number(best);
        list.forEach(function (c) {
          if (c.getAttribute('data-ts-ai-card') === best) { loadFrame(c); } else { unloadFrame(c); }
        });
        updatePager();
      }, { root: null, threshold: [0, 0.6, 0.9] });
      list.forEach(function (c) { S.io.observe(c); });
    }
    function updatePager() {
      if (!els.pager) { return; }
      els.pager.textContent = isPhone() && S.proposals.length > 1 ? t('Design :n of :total', { n: S.active + 1, total: S.proposals.length }) : '';
    }

    /* ------------------------------------------------------------ apply */
    function setBusy(btn, busy) {
      if (!btn) { return; }
      btn.disabled = !!busy;
      if (busy) { btn.setAttribute('aria-busy', 'true'); } else { btn.removeAttribute('aria-busy'); }
    }
    function flushEditor() {
      var studio = window.ThemeStudio && typeof window.ThemeStudio.current === 'function' ? window.ThemeStudio.current() : null;
      if (!studio || typeof studio.flush !== 'function') { return Promise.resolve(); }
      try { return Promise.resolve(studio.flush()).catch(function () { /* the apply replaces the draft anyway */ }); } catch (e) { return Promise.resolve(); }
    }
    function apply(pid, btn) {
      if (!urls.ai_apply || !S.setId) { return; }
      qa('[data-ts-ai-use], [data-ts-ai-confirm-use]').forEach(function (b) { b.disabled = true; });
      setBusy(btn, true);
      flushEditor().then(function () {
        return api('POST', fill(urls.ai_apply, S.setId, pid));
      }).then(function (res) {
        if (res.ok && isObj(res.data) && res.data.redirect) {
          toast(t('Opening the editor…'), 'success');
          dlg.close();
          go(String(res.data.redirect));
          return;
        }
        var err = new Error('apply');
        err.status = res.status;
        err.data = res.data;
        throw err;
      }).catch(function (err) {
        if (destroyed) { return; }
        qa('[data-ts-ai-use], [data-ts-ai-confirm-use]').forEach(function (b) { b.disabled = false; });
        setBusy(btn, false);
        var msg = t('Could not use this design. Try again.');
        if (err && (err.status === 404 || err.status === 410)) { msg = t('These designs have expired. Generate new ones.'); }
        if (err && (err.status === 401 || err.status === 419 || err.session)) { msg = t('Your session has ended. Reload the page and sign in again.'); }
        if (err && err.status === 422 && isObj(err.data) && err.data.message) { msg = String(err.data.message); }
        if (err && err.name === 'TypeError') { msg = t('Could not reach the server. Check your connection and try again.'); }
        toast(msg, 'error');
        if (els.pager) { els.pager.textContent = msg; }
      });
    }
    on(els.cards, 'click', function (e) {
      var use = e.target.closest('[data-ts-ai-use]');
      var yes = e.target.closest('[data-ts-ai-confirm-use]');
      var keep = e.target.closest('[data-ts-ai-keep]');
      if (use) {
        if (editorMode) {
          // Two-step, inline: the dashboard's confirm modal would sit under this dialog.
          var card = use.closest('[data-ts-ai-card]');
          qa('[data-ts-ai-confirm]').forEach(function (c) { c.hidden = true; });
          var box = card && card.querySelector('[data-ts-ai-confirm]');
          if (box) {
            box.hidden = false;
            var btn = box.querySelector('[data-ts-ai-confirm-use]');
            if (btn) { btn.focus(); }
          }
          return;
        }
        apply(use.getAttribute('data-ts-ai-use'), use);
      } else if (yes) {
        apply(yes.getAttribute('data-ts-ai-confirm-use'), yes);
      } else if (keep) {
        var c2 = keep.closest('[data-ts-ai-card]');
        var box2 = keep.closest('[data-ts-ai-confirm]');
        if (box2) { box2.hidden = true; }
        var u = c2 && c2.querySelector('[data-ts-ai-use]');
        if (u) { u.focus(); }
      }
    });

    show('form', false);
    renderRemaining();

    return {
      dlg: dlg,
      open: open,
      destroy: function () {
        destroyed = true;
        abort(true);
        finish();
        unloadFrames();
        if (dlg.open) { dlg.close(); }
        cleanups.forEach(function (fn) { try { fn(); } catch (e) { /* ignore */ } });
        cleanups = [];
      }
    };
  }

  /* ============================================================ bootstrap */
  var current = null;

  function boot() {
    var dlg = document.getElementById('ts-ai');
    if (!dlg) {
      if (current) { current.destroy(); current = null; }
      return;
    }
    if (current && current.dlg === dlg) { return; }
    if (current) { current.destroy(); current = null; }
    var cfg = readJson('theme-studio-config');
    var ai = readJson('theme-studio-ai-i18n');
    if (!isObj(cfg) || !isObj(cfg.caps) || !cfg.caps.ai_generate || !isObj(cfg.urls) || !cfg.urls.ai_generate) { return; }
    current = createAi(dlg, cfg, isObj(ai) ? ai : {});
  }

  window.ThemeStudioAi = {
    boot: boot,
    current: function () { return current; }
  };

  document.addEventListener('click', function (e) {
    var opener = e.target && e.target.closest ? e.target.closest('[data-ts-ai-open]') : null;
    if (!opener) { return; }
    if (!current) { boot(); }
    if (!current) { return; }
    e.preventDefault();
    current.open(opener);
  });
  document.addEventListener('turbo:load', boot);
  document.addEventListener('DOMContentLoaded', boot);
  document.addEventListener('turbo:before-cache', function () {
    if (current) { current.destroy(); current = null; }
  });

  if (document.readyState !== 'loading') { boot(); }
})();
