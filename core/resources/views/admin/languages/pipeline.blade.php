{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Translate pipeline').' · '.$language->name)
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" :href="route('admin.languages.index')">{{ ___('All languages') }}</x-ui.button>
    <x-ui.button variant="secondary" size="sm" icon="pencil" :href="route('admin.languages.translates', $language->code)">{{ ___('Manual editor') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    @if ($isSource)
        <x-ui.banner>
            {{ ___(':language is the source language — its strings are the origin every other locale is translated from, so it is never machine-translated.', ['language' => $language->name]) }}
        </x-ui.banner>
    @endif

    {{-- Coverage strip for this locale. --}}
    <x-ui.card>
        <div class="ui-stack">
            <div class="ui-cluster ui-cluster--between">
                <h2 class="ui-section-title">{{ ___('Coverage') }} · {{ $language->name }} <small class="ui-text-2">({{ strtoupper($language->code) }})</small></h2>
                <x-ui.tag tone="accent">{{ $cov['pct_done'] }}% {{ ___('done') }}</x-ui.tag>
            </div>
            <div class="ui-meter" role="progressbar" aria-label="{{ ___('Translation coverage') }}" aria-valuenow="{{ $cov['pct_done'] }}" aria-valuemin="0" aria-valuemax="100">
                <div class="ui-meter__bar" style="inline-size: {{ $cov['pct_done'] }}%;"></div>
            </div>
            <span class="ui-cell-tags">
                <x-ui.tag tone="success">{{ ___('Human') }}: {{ $cov['human'] }}</x-ui.tag>
                <x-ui.tag tone="accent">{{ ___('Machine') }}: {{ $cov['machine'] }}</x-ui.tag>
                <x-ui.tag tone="warning">{{ ___('Review') }}: {{ $cov['review'] }}</x-ui.tag>
                <x-ui.tag>{{ ___('Placeholder') }}: {{ $cov['placeholder'] }}</x-ui.tag>
                <x-ui.tag tone="danger">{{ ___('Missing') }}: {{ $cov['absent'] }}</x-ui.tag>
                <x-ui.tag>{{ ___('Source keys') }}: {{ $enTotal }}</x-ui.tag>
            </span>
        </div>
    </x-ui.card>

    {{-- Auto-translate: kick off a machine run (ratelimit:ai on the POST) and
         watch progress. Hidden for the source locale, which is never a target. --}}
    @unless ($isSource)
        <x-ui.card>
            <div class="ui-stack">
                <div class="ui-cluster ui-cluster--between">
                    <div>
                        <h2 class="ui-section-title">{{ ___('Auto-translate') }}</h2>
                        <p class="ui-small ui-text-2">{{ ___('Machine-translate the missing and placeholder strings. Flagged candidates land in the review queue for a human decision.') }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.languages.pipeline.translate', $language->code) }}">
                        @csrf
                        <x-ui.button type="submit" icon="sparkle" data-ui-busy>{{ ___('Auto-translate missing') }}</x-ui.button>
                    </form>
                </div>
                <div class="ui-rule-top" id="lang-pipeline-progress"
                     data-progress-url="{{ route('admin.languages.pipeline.progress', $language->code) }}"
                     data-label-running="{{ ___('Translating') }}"
                     data-label-done="{{ ___('Done — refreshing…') }}"
                     data-label-error="{{ ___('Translation failed') }}"
                     hidden>
                    <div class="ui-cluster">
                        <div class="ui-meter ui-grow" role="progressbar" aria-label="{{ ___('Auto-translate progress') }}">
                            <div class="ui-meter__bar" id="lang-pipeline-bar" style="inline-size:0%;"></div>
                        </div>
                        <span class="ui-small ui-text-2" id="lang-pipeline-text" aria-live="polite"></span>
                    </div>
                </div>
            </div>
        </x-ui.card>
    @endunless

    {{-- Review queue: QA-failed machine candidates awaiting a human decision. --}}
    @php
        $whyLabel = [
            'placeholders' => ___('Placeholder mismatch'),
            'plural' => ___('Plural syntax mismatch'),
            'lowconf' => ___('Low confidence'),
        ];
    @endphp
    <x-ui.card :title="___('Review queue')">
        <x-slot:actions><x-ui.tag tone="warning">{{ count($review) }}</x-ui.tag></x-slot:actions>
        <div class="ui-stack">
            @forelse ($review as $item)
                <x-ui.card variant="inset" as="div">
                    <div class="ui-cluster ui-cluster--between ui-cluster--top">
                        <div class="ui-grow">
                            <div><code>{{ $item['key'] }}</code> <x-ui.tag tone="danger">{{ $whyLabel[$item['why']] ?? $item['why'] }}</x-ui.tag></div>
                            <div class="ui-small ui-text-2" dir="ltr">{{ ___('English') }}: {{ $item['en'] }}</div>
                            <div dir="{{ $language->direction }}"><strong>{{ ___('Candidate') }}:</strong> {{ $item['candidate'] }}</div>
                        </div>
                        <div class="ui-cluster">
                            <form method="POST" action="{{ route('admin.languages.pipeline.approve', $language->code) }}">
                                @csrf
                                <input type="hidden" name="key" value="{{ $item['key'] }}">
                                <x-ui.button type="submit" variant="success" size="sm" icon="check">{{ ___('Approve') }}</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('admin.languages.pipeline.reject', $language->code) }}">
                                @csrf
                                <input type="hidden" name="key" value="{{ $item['key'] }}">
                                <x-ui.button type="submit" variant="secondary" size="sm" icon="x" class="ui-text-danger">{{ ___('Reject') }}</x-ui.button>
                            </form>
                        </div>
                    </div>
                </x-ui.card>
            @empty
                <p class="ui-text-2">{{ ___('No candidates awaiting review.') }}</p>
            @endforelse
        </div>
    </x-ui.card>

    @php
        // Status → kit tag tone + display label (matches the coverage strip).
        $statusTone = [
            'human' => 'success', 'machine' => 'accent', 'review' => 'warning',
            'placeholder' => 'neutral', 'absent' => 'danger',
        ];
        $statusLabel = [
            'human' => ___('Human'), 'machine' => ___('Machine'), 'review' => ___('Review'),
            'placeholder' => ___('Placeholder'), 'absent' => ___('Missing'),
        ];
    @endphp

    {{-- Key browser: search (?q) + status filter pills (?status), both plain GET
         — no AJAX. Value cell is RTL-safe via the locale direction. --}}
    <x-ui.card variant="flush" :title="___('Strings')">
        <div class="ui-stack">
            <div class="ui-cluster ui-cluster--between" style="padding-inline: 20px">
                {{-- Status filter pills. "All" clears the status filter but keeps the query. --}}
                <div class="ui-chips">
                    <x-ui.chip :href="route('admin.languages.pipeline', ['code' => $language->code, 'q' => $q])" :active="! $status">{{ ___('All') }}</x-ui.chip>
                    @foreach ($statuses as $s)
                        <x-ui.chip :href="route('admin.languages.pipeline', ['code' => $language->code, 'q' => $q, 'status' => $s])" :active="$status === $s">{{ $statusLabel[$s] ?? ___(ucfirst($s)) }}</x-ui.chip>
                    @endforeach
                </div>
                <form method="GET" action="{{ route('admin.languages.pipeline', $language->code) }}" class="ui-cluster" role="search">
                    @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                    <input type="search" name="q" value="{{ $q }}" class="ui-input ui-input--sm" placeholder="{{ ___('Search key, English or value') }}"
                           aria-label="{{ ___('Search key, English or value') }}" style="min-inline-size: 16rem;">
                    <x-ui.button type="submit" size="sm" icon="search">{{ ___('Search') }}</x-ui.button>
                    @if ($q || $status)
                        <x-ui.button variant="ghost" size="sm" :href="route('admin.languages.pipeline', $language->code)">{{ ___('Reset') }}</x-ui.button>
                    @endif
                </form>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ ___('Key') }}</th>
                            <th>{{ ___('English') }}</th>
                            <th>{{ ___('Value') }}</th>
                            <th>{{ ___('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td data-primary data-label="{{ ___('Key') }}"><code>{{ $row['key'] }}</code></td>
                                <td data-label="{{ ___('English') }}" dir="ltr">{{ $row['en'] }}</td>
                                <td data-label="{{ ___('Value') }}" dir="{{ $language->direction }}">{{ $row['value'] }}</td>
                                <td data-label="{{ ___('Status') }}"><x-ui.tag :tone="$statusTone[$row['status']] ?? 'neutral'">{{ $statusLabel[$row['status']] ?? ___(ucfirst($row['status'])) }}</x-ui.tag></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="ui-center ui-text-2">
                                    @if ($q || $status)
                                        {{ ___('No strings match your filters.') }}
                                    @else
                                        {{ ___('No strings to show yet.') }}
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-ui.card>
</div>
@endsection

@push('scripts_at_bottom')
        {{-- Auto-translate progress poll: copies the menu ai-translate pattern —
             poll every 2s, stop on done/error. Nonced, no on*= handlers. --}}
        <script nonce="{{ csp_nonce() }}">
            (function () {
                "use strict";
                var box = document.getElementById('lang-pipeline-progress');
                if (!box) { return; }
                var url = box.getAttribute('data-progress-url');
                var bar = document.getElementById('lang-pipeline-bar');
                var text = document.getElementById('lang-pipeline-text');
                var running = false, timer = null, queuedPolls = 0;
                function stop() { if (timer) { clearInterval(timer); timer = null; } }
                function pct(p) {
                    var total = p.total || 0, done = p.done || 0;
                    if (total > 0) { return Math.min(100, Math.round(done / total * 100)); }
                    return p.status === 'done' ? 100 : 0;
                }
                function tick() {
                    fetch(url, { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (p) {
                            if (p.status === 'queued' || p.status === 'running') {
                                if (p.status === 'queued' && ++queuedPolls > 60) { stop(); box.hidden = true; return; }
                                if (p.status === 'running') { queuedPolls = 0; }
                                running = true;
                                box.hidden = false;
                                var v = pct(p);
                                bar.style.inlineSize = v + '%';
                                text.textContent = box.getAttribute('data-label-running') + ' ' + (p.done || 0) + '/' + (p.total || '?') + ' (' + v + '%)';
                            } else if (p.status === 'error') {
                                stop();
                                box.hidden = false;
                                bar.classList.add('ui-bg-danger');
                                text.textContent = p.message || box.getAttribute('data-label-error');
                            } else if (running && p.status === 'done') {
                                stop();
                                bar.style.inlineSize = '100%';
                                text.textContent = box.getAttribute('data-label-done');
                                setTimeout(function () { window.location.reload(); }, 1200);
                            } else {
                                stop();
                                box.hidden = true;
                            }
                        })
                        .catch(function () {});
                }
                tick();
                timer = setInterval(tick, 2000);
            })();
        </script>
@endpush
