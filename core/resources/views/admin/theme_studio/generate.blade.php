{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('AI theme generator'))
@php
    /*
     * Superadmin AI theme generator (spec rev/theme-studio §4 D4, contract
     * rev/theme-studio/AI-BUILDER-CONTRACT.md). The controller passes $config =
     * {verticals:[{type,label}], urls:{generate, preview, save}}; preview carries
     * __SET__ / __P__ placeholders. Behaviour: the page script below (plain DOM,
     * ported from the retired theme-studio-generate.js).
     */
    $tsgConfig = is_array($config ?? null) ? $config : [];
    $tsgVerticals = array_values(array_filter((array) ($tsgConfig['verticals'] ?? []), 'is_array'));
    $tsgVibes = [
        'warm' => ___('Warm'), 'minimal' => ___('Minimal'), 'bold' => ___('Striking'), 'luxury' => ___('Luxury'),
        'playful' => ___('Playful'), 'traditional' => ___('Traditional'), 'modern' => ___('Modern'), 'family' => ___('Family'),
    ];
    $tsgPriorities = [
        '' => ___('Let the AI decide'), 'fast_ordering' => ___('Fast ordering'), 'brand_story' => ___('Brand story'),
        'browse' => ___('Browse the catalogue'), 'bookings' => ___('Bookings'),
    ];
    $tsgStrings = [
        'Reading the demo store…', 'Choosing layouts for this vertical…', 'Picking colours and fonts…', 'Writing headlines…',
        'Checking that every design renders…', 'Almost there…', 'Cancelled.', 'Design :n', 'Preview of :name', 'Open full preview',
        'Save as theme', 'Saving…', 'Saved. The theme is in the review queue.', 'Open review', 'Could not save this theme.',
        'Use lowercase letters, numbers and dashes.', 'Enter a name.', 'Rule-based fallback was used (AI unavailable).',
        'Daily limit reached.', 'Please check the brief.', 'Could not reach the server.', 'Generation failed. Try again.',
        'Colour :n', 'Remove colour :n', 'Fonts', 'Slug', 'Theme name', ':n / :max',
    ];
    $tsgI18n = [];
    foreach ($tsgStrings as $s) {
        $tsgI18n[$s] = ___($s);
    }
@endphp

@push('styles')
<style nonce="{{ csp_nonce() }}">
    .tsg-colors { display: flex; flex-wrap: wrap; gap: var(--ui-space-2); }
    .tsg-color { display: inline-flex; align-items: center; gap: 4px; padding: 4px; border: 1px solid var(--ui-line); border-radius: 2rem; }
    .tsg-color input[type=color] { inline-size: 2rem; block-size: 2rem; padding: 0; border: 0; background: none; }
    .tsg-bar { block-size: .5rem; border-radius: 1rem; background: var(--ui-inset); overflow: hidden; }
    .tsg-bar > span { display: block; block-size: 100%; inline-size: 4%; background: var(--ui-accent); }
    .tsg-bar.is-running > span { animation: tsg-fill 60s cubic-bezier(.1,.7,.3,1) forwards; }
    @keyframes tsg-fill { to { inline-size: 92%; } }
    .tsg-phone { position: relative; box-sizing: content-box; inline-size: calc(100% - 12px); max-inline-size: 240px; margin-inline: auto; aspect-ratio: 390 / 844; border: 6px solid #111; border-radius: 28px; overflow: hidden; background: var(--ui-inset); }
    .tsg-phone iframe { position: absolute; inset-block-start: 0; inset-inline-start: 0; inline-size: 390px; block-size: 844px; border: 0; background: #fff; transform: scale(var(--tsg-scale, .6)); transform-origin: top left; }
    [dir="rtl"] .tsg-phone iframe { transform-origin: top right; }
    .tsg-swatch { display: inline-block; inline-size: 1.25rem; block-size: 1.25rem; border-radius: 50%; border: 1px solid var(--ui-line); }
    @media (prefers-reduced-motion: reduce) { .tsg-bar.is-running > span { animation: none; inline-size: 60%; } }
</style>
@endpush

@section('content')
<script type="application/json" id="tsg-config">@json(['urls' => (array) ($tsgConfig['urls'] ?? []), 'csrf' => csrf_token(), 'i18n' => $tsgI18n])</script>

<div class="ui-stack" id="tsg-app">
    <x-ui.card>
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title">{{ ___('Brief') }}</h2>
                <p class="ui-text-2 ui-small">{{ ___('Generates 3 proposals against the vertical\'s demo store. Save one as a Studio theme to send it to the review queue.') }}</p>
            </div>
        </div>
        <form id="tsg-form" class="ui-stack" novalidate>
            <div class="ui-note ui-hidden" data-tone="danger" role="alert" data-tsg-error></div>

            <div class="ui-form-grid">
                <div class="ui-field">
                    <label class="ui-field__label" for="tsg-vertical">{{ ___('Vertical') }}</label>
                    <div class="ui-field__control">
                        <select id="tsg-vertical" name="vertical" required>
                            @foreach ($tsgVerticals as $v)
                                <option value="{{ $v['type'] ?? '' }}">{{ $v['label'] ?? ($v['type'] ?? '') }}</option>
                            @endforeach
                        </select>
                        <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
                    </div>
                </div>
                <x-ui.field type="select" name="priority" id="tsg-priority" :label="___('What matters most?')" :options="$tsgPriorities" />
                <div class="ui-form-grid__full ui-field">
                    <label class="ui-field__label" for="tsg-text">{{ ___('Describe the store (optional)') }}</label>
                    <div class="ui-field__control">
                        <textarea id="tsg-text" name="text" rows="3" maxlength="500" aria-describedby="tsg-text-count"></textarea>
                    </div>
                    <span class="ui-field__help ui-end" id="tsg-text-count" data-tsg-count>0 / 500</span>
                </div>
                <div class="ui-form-grid__full">
                    <span class="ui-label" id="tsg-vibes-label">{{ ___('Feel (optional)') }}</span>
                    <div class="ui-chips" role="group" aria-labelledby="tsg-vibes-label">
                        @foreach ($tsgVibes as $key => $label)
                            <button type="button" class="ui-chip" aria-pressed="false" data-tsg-vibe="{{ $key }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="ui-form-grid__full ui-stack" style="--ui-gap:var(--ui-space-2)">
                    <span class="ui-label" id="tsg-colors-label">{{ ___('Brand colours (optional, up to 3)') }}</span>
                    <div class="tsg-colors" data-tsg-colors aria-labelledby="tsg-colors-label" role="group"></div>
                    <div><x-ui.button variant="secondary" size="sm" icon="plus" data-tsg-add-color>{{ ___('Add a colour') }}</x-ui.button></div>
                </div>
            </div>

            <div class="ui-cluster ui-cluster--end">
                <x-ui.button type="submit" icon="sparkle" data-tsg-generate>{{ ___('Generate 3 proposals') }}</x-ui.button>
            </div>
        </form>

        <div class="ui-stack ui-hidden" data-tsg-progress>
            <div class="tsg-bar" role="progressbar" aria-label="{{ ___('Generating') }}" aria-busy="true" data-tsg-bar><span></span></div>
            <p class="ui-strong" role="status" aria-live="polite" data-tsg-stage></p>
            <div><x-ui.button variant="secondary" size="sm" data-tsg-cancel>{{ ___('Cancel') }}</x-ui.button></div>
        </div>
    </x-ui.card>

    <x-ui.card class="ui-hidden" data-tsg-results>
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title" tabindex="-1" data-tsg-results-title>{{ ___('Proposals') }}</h2>
                <p class="ui-text-2 ui-small" data-tsg-fallback></p>
            </div>
        </div>
        <div class="ui-grid" style="--ui-grid-min:260px" data-tsg-cards></div>
    </x-ui.card>
</div>
@endsection

@push('scripts_at_bottom')
{{-- POST urls.generate {vertical, brief} → {set_id, fallback, proposals}
     GET  urls.preview (__SET__/__P__, ?lang=) → HTML (iframe)
     POST urls.save {set, proposal, slug, name} → {ok, theme_id, version_id, review_url}
     No native dialogs; errors render inline. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
  'use strict';

  var app = document.getElementById('tsg-app');
  var cfgEl = document.getElementById('tsg-config');
  if (!app || !cfgEl || app.__tsg) { return; }
  app.__tsg = true;

  var cfg;
  try { cfg = JSON.parse(cfgEl.textContent || '{}') || {}; } catch (e) { return; }
  var urls = cfg.urls || {};
  var strings = cfg.i18n || {};
  var HEX = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;
  var SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
  var STAGES = [
    [0, 'Reading the demo store…'], [7000, 'Choosing layouts for this vertical…'], [17000, 'Picking colours and fonts…'],
    [28000, 'Writing headlines…'], [42000, 'Checking that every design renders…'], [56000, 'Almost there…']
  ];
  var PHONE_W = 390;

  function t(key, rep) {
    var v = Object.prototype.hasOwnProperty.call(strings, key) ? String(strings[key]) : key;
    Object.keys(rep || {}).forEach(function (k) { v = v.split(':' + k).join(String(rep[k])); });
    return v;
  }
  function q(sel) { return app.querySelector(sel); }
  function qa(sel) { return Array.prototype.slice.call(app.querySelectorAll(sel)); }
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
  function isObj(o) { return !!o && typeof o === 'object' && !Array.isArray(o); }
  function flatten(obj, prefix, out) {
    Object.keys(obj || {}).forEach(function (k) {
      var key = prefix ? prefix + '.' + k : k;
      if (isObj(obj[k])) { flatten(obj[k], key, out); } else { out[key] = obj[k]; }
    });
    return out;
  }
  function slugify(s) {
    return String(s || '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
  }
  function invalid(input, on) {
    input.classList.toggle('ui-input--error', on);
    if (on) { input.setAttribute('aria-invalid', 'true'); } else { input.removeAttribute('aria-invalid'); }
  }
  function tone(el, name) {
    el.className = 'ui-small' + (name ? ' ui-text-' + name : ' ui-text-2');
  }

  var els = {
    form: document.getElementById('tsg-form'),
    vertical: document.getElementById('tsg-vertical'),
    priority: document.getElementById('tsg-priority'),
    text: document.getElementById('tsg-text'),
    count: q('[data-tsg-count]'),
    colors: q('[data-tsg-colors]'),
    addColor: q('[data-tsg-add-color]'),
    error: q('[data-tsg-error]'),
    generate: q('[data-tsg-generate]'),
    progress: q('[data-tsg-progress]'),
    bar: q('[data-tsg-bar]'),
    stage: q('[data-tsg-stage]'),
    cancel: q('[data-tsg-cancel]'),
    results: q('[data-tsg-results]'),
    resultsTitle: q('[data-tsg-results-title]'),
    fallback: q('[data-tsg-fallback]'),
    cards: q('[data-tsg-cards]')
  };
  var S = { busy: false, ctrl: null, timers: [], cancelled: false, setId: null, vertical: '' };

  function api(method, url, body, signal) {
    var headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' };
    var meta = document.querySelector('meta[name="csrf-token"]');
    headers['X-CSRF-TOKEN'] = cfg.csrf || (meta ? meta.getAttribute('content') : '');
    return fetch(url, { method: method, headers: headers, credentials: 'same-origin', body: JSON.stringify(body), signal: signal })
      .then(function (res) {
        return res.json().catch(function () { return null; }).then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
      });
  }

  /* ---- brief -------------------------------------------------------- */
  function updateCount() {
    if (els.text && els.count) { els.count.textContent = t(':n / :max', { n: els.text.value.length, max: 500 }); }
  }
  if (els.text) { els.text.addEventListener('input', updateCount); }
  updateCount();
  qa('[data-tsg-vibe]').forEach(function (b) {
    b.addEventListener('click', function () { b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true'); });
  });
  function colorRows() { return els.colors ? Array.prototype.slice.call(els.colors.children) : []; }
  function renumber() {
    colorRows().forEach(function (r, i) {
      r.querySelector('input').setAttribute('aria-label', t('Colour :n', { n: i + 1 }));
      r.querySelector('button').setAttribute('aria-label', t('Remove colour :n', { n: i + 1 }));
    });
    if (els.addColor) { els.addColor.classList.toggle('ui-hidden', colorRows().length >= 3); }
  }
  if (els.addColor) {
    els.addColor.addEventListener('click', function () {
      if (colorRows().length >= 3) { return; }
      var input = h('input', { type: 'color', value: ['#c2410c', '#1f2937', '#f5f0e6'][colorRows().length] });
      var rm = h('button', { type: 'button', class: 'ui-icon-btn ui-icon-btn--ghost' }, ['×']);
      var row = h('span', { class: 'tsg-color' }, [input, rm]);
      rm.addEventListener('click', function () { row.remove(); renumber(); els.addColor.focus(); });
      els.colors.appendChild(row);
      renumber();
      input.focus();
    });
  }
  function readBrief() {
    var brief = {};
    var text = els.text ? els.text.value.trim().slice(0, 500) : '';
    if (text) { brief.text = text; }
    var vibes = qa('[data-tsg-vibe][aria-pressed="true"]').map(function (b) { return b.getAttribute('data-tsg-vibe'); });
    if (vibes.length) { brief.vibes = vibes; }
    if (els.priority && els.priority.value) { brief.priority = els.priority.value; }
    var colors = colorRows().map(function (r) { return r.querySelector('input').value.toLowerCase(); }).filter(function (c) { return HEX.test(c); });
    if (colors.length) { brief.colors = colors; }
    return brief;
  }

  /* ---- generate ----------------------------------------------------- */
  function setError(msg) {
    if (!els.error) { return; }
    els.error.textContent = msg || '';
    els.error.classList.toggle('ui-hidden', !msg);
  }
  function working(on) {
    S.busy = on;
    els.generate.disabled = on;
    els.progress.classList.toggle('ui-hidden', !on);
    S.timers.forEach(clearTimeout);
    S.timers = [];
    els.bar.classList.remove('is-running');
    if (!on) { return; }
    void els.bar.offsetWidth;
    els.bar.classList.add('is-running');
    STAGES.forEach(function (st) {
      S.timers.push(setTimeout(function () { els.stage.textContent = t(st[1]); }, st[0]));
    });
    S.timers.push(setTimeout(function () { if (S.ctrl) { S.ctrl.abort(); } }, 150000));
  }

  els.form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (S.busy || !urls.generate) { return; }
    setError('');
    S.cancelled = false;
    S.vertical = els.vertical ? els.vertical.value : '';
    S.ctrl = typeof AbortController === 'function' ? new AbortController() : null;
    working(true);
    els.cancel.focus();
    api('POST', urls.generate, { vertical: S.vertical, brief: readBrief() }, S.ctrl ? S.ctrl.signal : undefined).then(function (res) {
      working(false);
      var data = isObj(res.data) ? res.data : {};
      if (res.ok && Array.isArray(data.proposals) && data.proposals.length) {
        S.setId = String(data.set_id || '');
        render(data.proposals.filter(isObj).slice(0, 3), !!data.fallback);
        return;
      }
      var msg = t('Generation failed. Try again.');
      if (res.status === 429) { msg = data.message ? String(data.message) : t('Daily limit reached.'); }
      if (res.status === 422) {
        msg = data.message ? String(data.message) : t('Please check the brief.');
        if (isObj(data.errors)) {
          msg += ' ' + Object.keys(data.errors).map(function (k) { var v = data.errors[k]; return Array.isArray(v) ? v[0] : v; }).join(' ');
        }
      }
      setError(msg);
      els.generate.focus();
    }).catch(function () {
      working(false);
      setError(S.cancelled ? t('Cancelled.') : t('Could not reach the server.'));
      els.generate.focus();
    });
  });
  els.cancel.addEventListener('click', function () {
    S.cancelled = true;
    if (S.ctrl) { S.ctrl.abort(); }
  });

  /* ---- proposals ---------------------------------------------------- */
  function previewUrl(pid) {
    return String(urls.preview || '').split('__SET__').join(encodeURIComponent(S.setId)).split('__P__').join(encodeURIComponent(pid));
  }
  function scale(phone) {
    if (phone.clientWidth > 0) { phone.style.setProperty('--tsg-scale', String(phone.clientWidth / PHONE_W)); }
  }
  var ro = typeof ResizeObserver === 'function' ? new ResizeObserver(function (en) { en.forEach(function (e) { scale(e.target); }); }) : null;

  function field(id, label, input) {
    return h('div', { class: 'ui-field' }, [h('label', { class: 'ui-field__label', for: id, text: label }), input]);
  }

  function render(proposals, fallback) {
    if (ro) { ro.disconnect(); }
    els.cards.textContent = '';
    els.fallback.textContent = fallback ? t('Rule-based fallback was used (AI unavailable).') : '';
    proposals.forEach(function (p, i) {
      var pid = String(p.id || '');
      var name = String(p.name || t('Design :n', { n: i + 1 }));
      var flat = flatten(isObj(p.tokens) ? p.tokens : {}, '', {});
      var swatches = Object.keys(flat).filter(function (k) { return HEX.test(String(flat[k] || '')); }).slice(0, 6).map(function (k) {
        var s = h('span', { class: 'tsg-swatch', title: k + ' ' + flat[k] });
        s.style.backgroundColor = String(flat[k]);
        return s;
      });
      var fp = p.font_pair;
      var font = typeof fp === 'string' ? fp : (isObj(fp) ? String(fp.name || fp.label || [fp.heading, fp.body].filter(Boolean).join(' / ')) : '');
      var phone = h('div', { class: 'tsg-phone' }, [
        h('iframe', { src: previewUrl(pid), title: t('Preview of :name', { name: name }), loading: 'lazy', width: '390', height: '844', tabindex: '-1' })
      ]);
      var slugId = 'tsg-slug-' + i;
      var nameId = 'tsg-name-' + i;
      var slug = h('input', { class: 'ui-input ui-input--sm', id: slugId, name: 'slug', required: true, pattern: '[a-z0-9]+(-[a-z0-9]+)*', maxlength: '60', value: slugify(slugify(name).indexOf(slugify(S.vertical)) === -1 ? name + '-' + S.vertical : name) || ('studio-' + S.vertical + '-' + (i + 1)), dir: 'ltr' });
      var nm = h('input', { class: 'ui-input ui-input--sm', id: nameId, name: 'name', required: true, maxlength: '80', value: name });
      var status = h('p', { class: 'ui-small ui-text-2', role: 'status', 'aria-live': 'polite' });
      var save = h('button', { type: 'submit', class: 'ui-btn ui-btn--success ui-btn--sm' }, [t('Save as theme')]);
      var form = h('form', { class: 'ui-stack ui-rule-top', novalidate: true }, [
        field(slugId, t('Slug'), slug),
        field(nameId, t('Theme name'), nm),
        h('div', {}, [save]), status
      ]);
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var s = slug.value.trim();
        var n = nm.value.trim();
        invalid(slug, !SLUG.test(s));
        invalid(nm, !n);
        if (!SLUG.test(s)) { tone(status, 'danger'); status.textContent = t('Use lowercase letters, numbers and dashes.'); slug.focus(); return; }
        if (!n) { tone(status, 'danger'); status.textContent = t('Enter a name.'); nm.focus(); return; }
        save.disabled = true;
        tone(status, null);
        status.textContent = t('Saving…');
        api('POST', urls.save, { set: S.setId, proposal: pid, slug: s, name: n }).then(function (res) {
          var data = isObj(res.data) ? res.data : {};
          if (res.ok && data.ok) {
            tone(status, 'success');
            status.textContent = t('Saved. The theme is in the review queue.') + ' ';
            if (data.review_url) {
              status.appendChild(h('a', { href: String(data.review_url), text: t('Open review') }));
            }
            return;
          }
          var msg = data.message ? String(data.message) : t('Could not save this theme.');
          if (isObj(data.errors)) {
            msg += ' ' + Object.keys(data.errors).map(function (k) { var v = data.errors[k]; return Array.isArray(v) ? v[0] : v; }).join(' ');
            if (data.errors.slug) { invalid(slug, true); }
          }
          throw new Error(msg);
        }).catch(function (err) {
          save.disabled = false;
          tone(status, 'danger');
          status.textContent = err && err.message && err.message !== 'Failed to fetch' ? err.message : t('Could not reach the server.');
        });
      });
      var card = h('div', { class: 'ui-card ui-card--inset ui-stack' }, [
        phone,
        h('h3', { class: 'ui-card__title', text: name }),
        p.rationale ? h('p', { class: 'ui-small ui-text-2', text: String(p.rationale) }) : null,
        swatches.length ? h('div', { class: 'ui-cluster' }, swatches) : null,
        font ? h('p', { class: 'ui-small', text: t('Fonts') + ': ' + font }) : null,
        h('a', { class: 'ui-small', href: previewUrl(pid), target: '_blank', rel: 'noopener', text: t('Open full preview') }),
        form
      ]);
      els.cards.appendChild(card);
      scale(phone);
      if (ro) { ro.observe(phone); }
    });
    els.results.classList.remove('ui-hidden');
    els.resultsTitle.focus();
  }
})();
</script>
@endpush
