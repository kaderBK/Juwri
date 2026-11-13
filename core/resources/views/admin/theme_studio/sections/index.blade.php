{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('AI section author'))
@php
    /*
     * Theme Studio phase E1 (rev/theme-studio/IMPLEMENTATION-SPEC.md §6): the
     * superadmin AI section author + review queue. Controller:
     * Admin\AiSectionAuthorController@index ($drafts, $verticals, $config).
     * Behaviour: the page script below (ported from the retired
     * theme-studio-sections.js; no native dialogs).
     */
    $tssStrings = [
        'Writing the section…', 'Running the gates…', 'Repairing and re-checking…', 'Almost there…',
        'Could not reach the server.', 'Please check the request.', 'Generation failed. Try again.', 'Daily limit reached.',
    ];
    $tssI18n = [];
    foreach ($tssStrings as $s) {
        $tssI18n[$s] = ___($s);
    }
    $tssTone = [
        'passed' => 'accent', 'failed' => 'danger', 'approved' => 'success',
        'rejected' => 'neutral', 'generating' => 'warning',
    ];
    $tssStatus = [
        'passed' => ___('Awaiting review'), 'failed' => ___('Gates failed'), 'approved' => ___('Approved'),
        'rejected' => ___('Rejected'), 'generating' => ___('Generating'),
    ];
@endphp

@push('styles')
<style nonce="{{ csp_nonce() }}">
    .tss-bar { block-size: .5rem; border-radius: 1rem; background: var(--ui-inset); overflow: hidden; }
    .tss-bar > span { display: block; block-size: 100%; inline-size: 4%; background: var(--ui-accent); }
    .tss-bar.is-running > span { animation: tss-fill 90s cubic-bezier(.1,.7,.3,1) forwards; }
    @keyframes tss-fill { to { inline-size: 92%; } }
    @media (prefers-reduced-motion: reduce) { .tss-bar.is-running > span { animation: none; inline-size: 60%; } }
</style>
@endpush

@section('content')
<script type="application/json" id="tss-config">@json(['urls' => $config['urls'] ?? [], 'csrf' => csrf_token(), 'i18n' => $tssI18n])</script>

<div class="ui-stack" id="tss-app">
    <x-ui.card>
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title">{{ ___('Describe a new library section') }}</h2>
                <p class="ui-text-2 ui-small">{{ ___('The AI writes the Liquid, scoped CSS, schema and English + Arabic strings. Automatic gates check it (up to 3 repair rounds); you review it here. Approving never changes the live library — a developer opens the PR with studio:section-pr.') }}</p>
            </div>
        </div>
        <form id="tss-form" method="post" action="{{ route('admin.section-author.store') }}" class="ui-stack" novalidate>
            @csrf
            <div class="ui-note ui-hidden" data-tone="danger" role="alert" data-tss-error></div>
            @if ($errors->any())
                <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
            @endif
            <div class="ui-form-grid">
                <div class="ui-form-grid__full">
                    <x-ui.field type="textarea" name="request" id="tss-request" :label="___('What should the section do?')" rows="4" minlength="10" maxlength="2000" required
                                :placeholder="___('e.g. A horizontal combo builder rail with step chips: pick a main, a side and a drink, then add all three to the cart.')" />
                </div>
                <fieldset class="ui-fieldset">
                    <legend class="ui-label" id="tss-verticals-label">{{ ___('Target verticals (none = all)') }}</legend>
                    <div class="ui-cluster">
                        @foreach ($verticals as $type => $label)
                            <x-ui.check name="verticals[]" :value="$type" id="tss-v-{{ $type }}" :label="$label" />
                        @endforeach
                    </div>
                </fieldset>
                <x-ui.field name="type" id="tss-type" :label="___('Section type (optional)')" maxlength="40" pattern="[a-z][a-z0-9]*(-[a-z0-9]+)*" placeholder="combo-builder"
                            :help="___('Lowercase letters, numbers and dashes. Blank = the AI names it.')" />
            </div>
            <div class="ui-cluster ui-cluster--end">
                <x-ui.button type="submit" icon="sparkle" data-tss-generate>{{ ___('Write the section') }}</x-ui.button>
            </div>
        </form>
        <div class="ui-stack ui-hidden" data-tss-progress>
            <div class="tss-bar" role="progressbar" aria-label="{{ ___('Generating') }}" aria-busy="true" data-tss-bar><span></span></div>
            <p class="ui-strong" role="status" aria-live="polite" data-tss-stage></p>
        </div>
    </x-ui.card>

    <x-ui.card variant="flush" :title="___('Review queue')">
        @if ($drafts->isEmpty())
            <x-ui.empty icon="list" :title="___('No sections yet.')" />
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>#</th><th>{{ ___('Section type') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Repair rounds') }}</th>
                        <th>{{ ___('Request') }}</th><th>{{ ___('Created') }}</th><th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($drafts as $d)
                        <tr>
                            <td data-label="#">{{ $d->id }}</td>
                            <td data-primary data-label="{{ ___('Section type') }}"><code class="ui-mono">{{ $d->type ?? '—' }}</code></td>
                            <td data-label="{{ ___('Status') }}"><x-ui.tag :tone="$tssTone[$d->status] ?? 'neutral'">{{ $tssStatus[$d->status] ?? $d->status }}</x-ui.tag></td>
                            <td data-label="{{ ___('Repair rounds') }}">{{ $d->repair_rounds }}</td>
                            <td data-label="{{ ___('Request') }}" class="ui-text-2 ui-truncate" title="{{ $d->prompt }}">{{ \Illuminate\Support\Str::limit($d->prompt, 80) }}</td>
                            <td data-label="{{ ___('Created') }}" class="ui-text-2">{{ optional($d->created_at)->diffForHumans() }}</td>
                            <td class="ui-end" data-label=""><x-ui.button variant="secondary" size="sm" :href="route('admin.section-author.show', $d->id)">{{ ___('Open') }}</x-ui.button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
@endsection

@push('scripts_at_bottom')
{{-- POST urls.generate {request, verticals[], type?} → {id, status, type, url} (then navigate to url).
     Errors render inline. Without JS the form still posts normally. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
  'use strict';

  var app = document.getElementById('tss-app');
  if (!app || app.__tss) { return; }
  app.__tss = true;

  var cfg = {};
  var cfgEl = document.getElementById('tss-config');
  if (cfgEl) {
    try { cfg = JSON.parse(cfgEl.textContent || '{}') || {}; } catch (e) { cfg = {}; }
  }
  var strings = cfg.i18n || {};
  function t(key) { return Object.prototype.hasOwnProperty.call(strings, key) ? String(strings[key]) : key; }

  var form = document.getElementById('tss-form');
  if (!form || !cfg.urls || !cfg.urls.generate) { return; }
  var errorBox = form.querySelector('[data-tss-error]');
  var submit = form.querySelector('[data-tss-generate]');
  var progress = app.querySelector('[data-tss-progress]');
  var bar = app.querySelector('[data-tss-bar]');
  var stage = app.querySelector('[data-tss-stage]');
  var STAGES = [[0, 'Writing the section…'], [15000, 'Running the gates…'], [35000, 'Repairing and re-checking…'], [80000, 'Almost there…']];
  var timers = [];

  function showError(msg) {
    errorBox.textContent = msg;
    errorBox.classList.remove('ui-hidden');
    errorBox.setAttribute('tabindex', '-1');
    errorBox.focus();
  }
  function running(on) {
    submit.disabled = on;
    progress.classList.toggle('ui-hidden', !on);
    bar.classList.toggle('is-running', on);
    timers.forEach(clearTimeout);
    timers = [];
    if (on) {
      STAGES.forEach(function (s) { timers.push(setTimeout(function () { stage.textContent = t(s[1]); }, s[0])); });
    }
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    errorBox.classList.add('ui-hidden');
    var text = form.querySelector('[name="request"]').value.trim();
    if (text.length < 10) { showError(t('Please check the request.')); return; }
    var verticals = Array.prototype.map.call(form.querySelectorAll('[name="verticals[]"]:checked'), function (el) { return el.value; });
    var type = (form.querySelector('[name="type"]').value || '').trim();
    var body = { request: text, verticals: verticals };
    if (type) { body.type = type; }

    running(true);
    fetch(cfg.urls.generate, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) { return { res: res, json: json }; });
    }).then(function (r) {
      if (r.res.ok && r.json && r.json.url) { window.location.assign(r.json.url); return; }
      running(false);
      if (r.res.status === 429) { showError(t('Daily limit reached.')); return; }
      if (r.res.status === 422) { showError((r.json && r.json.message) || t('Please check the request.')); return; }
      showError(t('Generation failed. Try again.'));
    }).catch(function () {
      running(false);
      showError(t('Could not reach the server.'));
    });
  });
})();
</script>
@endpush
