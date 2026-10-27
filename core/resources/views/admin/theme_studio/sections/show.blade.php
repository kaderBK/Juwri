{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('AI section author').' #'.$draft->id)
@php
    /*
     * Theme Studio phase E1: one AI-authored section — gate report, en/ar ×
     * mobile/desktop screenshots, the files the PR would add, per-round
     * diffs, approve/reject. Controller: Admin\AiSectionAuthorController@show.
     * Approving only marks the draft; the PR comes from `studio:section-pr`.
     */
    $tssTone = [
        'passed' => 'accent', 'failed' => 'danger', 'approved' => 'success',
        'rejected' => 'neutral', 'generating' => 'warning',
    ];
    $tssStatus = [
        'passed' => ___('Awaiting review'), 'failed' => ___('Gates failed'), 'approved' => ___('Approved'),
        'rejected' => ___('Rejected'), 'generating' => ___('Generating'),
    ];
    $gateTone = ['pass' => 'success', 'fail' => 'danger', 'skipped' => 'neutral'];
    $gateLabel = ['pass' => ___('passed'), 'fail' => ___('failed'), 'skipped' => ___('skipped')];
    $shotLabel = ['en-mobile' => ___('English · 360px'), 'ar-mobile' => ___('Arabic · 360px'), 'en-desktop' => ___('English · desktop'), 'ar-desktop' => ___('Arabic · desktop')];
    $report = is_array($draft->gate_report) ? $draft->gate_report : ['gates' => []];
@endphp

@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.section-author.index')">{{ ___('Back to the queue') }}</x-ui.button>
@endsection

@push('styles')
<style nonce="{{ csp_nonce() }}">
    .tss-code { max-block-size: 32rem; overflow: auto; margin: 0; padding: .5rem 0; border-radius: var(--ui-r-input); background: var(--ui-inset); font-family: var(--ui-font-mono); font-size: .78rem; line-height: 1.45; direction: ltr; text-align: start; }
    .tss-code span { display: block; padding-inline: .75rem; white-space: pre; }
    .tss-code .is-add { background: var(--ui-success-bg); }
    .tss-code .is-del { background: var(--ui-danger-bg); }
    .tss-shots { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(220px, 300px)); align-items: start; }
    .tss-shots figure.is-desktop { grid-column: 1 / -1; max-inline-size: 960px; }
    .tss-shots figure { margin: 0; }
    .tss-shots img { display: block; inline-size: 100%; block-size: auto; border: 1px solid var(--ui-line); border-radius: var(--ui-r-input); background: #fff; }
    .tss-json { direction: ltr; text-align: start; }
</style>
@endpush

@section('content')
<div class="ui-stack" id="tss-app">
    {{-- session('success') is shown as a kit toast by the layout. --}}
    @if ($errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <x-ui.card>
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title"><code class="ui-mono">{{ $draft->type ?? '—' }}</code>
                    <x-ui.tag :tone="$tssTone[$draft->status] ?? 'neutral'">{{ $tssStatus[$draft->status] ?? $draft->status }}</x-ui.tag>
                </h2>
                <p class="ui-text-2 ui-small">{{ ___('Library :v', ['v' => $draft->library_version]) }} · {{ ___('Verticals') }}: {{ implode(', ', $draft->verticals ?? ['all']) }} · {{ ___('Repair rounds') }}: {{ $draft->repair_rounds }}</p>
            </div>
        </div>
        <div class="ui-stack">
            <p>{{ $draft->prompt }}</p>
            @if ($draft->error)
                <x-ui.banner tone="warning">{{ $draft->error }}</x-ui.banner>
            @endif
            @if ($draft->fixture_notes)
                <p class="ui-text-2 ui-small"><strong>{{ ___('Fixture notes') }}:</strong> {{ $draft->fixture_notes }}</p>
            @endif
        </div>
    </x-ui.card>

    <x-ui.card :title="___('Decision')">
        <div class="ui-stack">
            @if ($draft->status === 'approved')
                <x-ui.banner tone="success">
                    {{ ___('Approved — nothing was written to the live library. From a clean developer checkout run:') }}
                    <pre class="tss-code tss-json"><span>{{ $command }}</span></pre>
                </x-ui.banner>
                <p class="ui-small ui-text-2">{{ ___('The command writes the section, its CSS source and en/ar strings, rebuilds library.css, runs studio:library-check, then opens a PR on branch studio/ai-section-:type. If the library version is frozen (pinned by a published third-party theme or marked FROZEN), it writes into the next minor version instead.', ['type' => $draft->type]) }}</p>
            @elseif ($draft->status === 'rejected')
                <p>{{ ___('Rejected.') }}</p>
            @elseif ($draft->status === 'failed')
                <p class="ui-text-danger">{{ ___('This draft still fails the gates after the repair rounds, so it cannot be approved. Refine the request and write it again.') }}</p>
            @endif
            @if ($draft->review_notes)
                <p class="ui-small"><strong>{{ ___('Notes') }}:</strong> {{ $draft->review_notes }}</p>
            @endif

            <div class="ui-columns">
                @if ($draft->reviewable())
                    <form method="post" action="{{ route('admin.section-author.approve', $draft->id) }}" class="ui-stack" data-ui-confirm="{{ ___('Are you sure?') }}">
                        @csrf
                        <x-ui.field type="textarea" name="notes" id="tss-approve-notes" :label="___('Approval notes (optional)')" rows="2" maxlength="2000" />
                        <div><x-ui.button type="submit" variant="success" icon="check">{{ ___('Approve') }}</x-ui.button></div>
                    </form>
                @endif
                @if (! in_array($draft->status, ['rejected', 'generating'], true))
                    <form method="post" action="{{ route('admin.section-author.reject', $draft->id) }}" class="ui-stack" data-ui-confirm="{{ ___('Are you sure?') }}" data-ui-danger>
                        @csrf
                        <x-ui.field type="textarea" name="notes" id="tss-reject-notes" :label="___('Why reject? (required)')" rows="2" minlength="3" maxlength="2000" required />
                        <div><x-ui.button type="submit" variant="danger">{{ ___('Reject') }}</x-ui.button></div>
                    </form>
                @endif
            </div>
        </div>
    </x-ui.card>

    <x-ui.card variant="flush" :title="___('Gate report')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Gate') }}</th><th>{{ ___('Result') }}</th><th>{{ ___('Details') }}</th></tr></thead>
                <tbody>
                @foreach (($report['gates'] ?? []) as $gate)
                    <tr>
                        <td data-primary data-label="{{ ___('Gate') }}" class="ui-nowrap">{{ $gate['label'] ?? $gate['id'] }}</td>
                        <td data-label="{{ ___('Result') }}"><x-ui.tag :tone="$gateTone[$gate['status']] ?? 'neutral'">{{ $gateLabel[$gate['status']] ?? $gate['status'] }}</x-ui.tag></td>
                        <td data-label="{{ ___('Details') }}" class="ui-small">
                            @if (! empty($gate['meta']['reason']))<div class="ui-text-2">{{ $gate['meta']['reason'] }}</div>@endif
                            @foreach (($gate['errors'] ?? []) as $e)<div class="ui-text-danger ui-break">{{ $e }}</div>@endforeach
                            @foreach (($gate['warnings'] ?? []) as $w)<div class="ui-text-warning ui-break">{{ $w }}</div>@endforeach
                            @if (isset($gate['meta']['renders']))<div class="ui-text-2">{{ ___(':n renders, slowest :ms ms (budget :budget ms)', ['n' => $gate['meta']['renders'], 'ms' => $gate['meta']['slowest_ms'] ?? 0, 'budget' => $gate['meta']['budget_ms'] ?? 0]) }}</div>@endif
                            @if (isset($gate['meta']['bytes']))<div class="ui-text-2">{{ ___(':bytes of :budget bytes', ['bytes' => $gate['meta']['bytes'], 'budget' => $gate['meta']['budget']]) }}</div>@endif
                            @if (! empty($gate['meta']['axe']))<div class="ui-text-2">a11y: {{ $gate['meta']['axe'] }}</div>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.card :title="___('Screenshots')">
        @if ($shots === [])
            <p class="ui-text-2">{{ ___('No screenshots — the browser gate did not run on this host (see the gate report).') }}</p>
        @else
            <div class="tss-shots">
                @foreach ($shots as $key => $url)
                    <figure @class(['is-desktop' => str_ends_with($key, 'desktop')])>
                        <img src="{{ $url }}" alt="{{ $shotLabel[$key] ?? $key }}" loading="lazy">
                        <figcaption class="ui-small ui-text-2">{{ $shotLabel[$key] ?? $key }}</figcaption>
                    </figure>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    @if ($draft->type)
    <x-ui.card :title="___('Files the PR adds')">
        <div class="ui-stack">
            @foreach ($files as $path => $lines)
                <div>
                    <p class="ui-small ui-strong"><code class="ui-mono">{{ $path }}</code></p>
                    <pre class="tss-code">@foreach ($lines as $l)<span class="{{ $l['op'] === '+' ? 'is-add' : ($l['op'] === '-' ? 'is-del' : '') }}">{{ $l['op'] }} {{ $l['text'] }}</span>@endforeach</pre>
                </div>
            @endforeach
            <div class="ui-columns">
                @foreach (['en', 'ar'] as $lang)
                    <div>
                        <p class="ui-small ui-strong ui-break"><code class="ui-mono">core/resources/liquid-library/{{ $draft->library_version }}/locales/{{ $lang }}/ai-{{ $draft->type }}.json</code></p>
                        <pre class="tss-code tss-json"><span>{{ json_encode($draft->locales[$lang] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</span></pre>
                    </div>
                @endforeach
            </div>
        </div>
    </x-ui.card>
    @endif

    <x-ui.card :title="___('Attempts')">
        <div class="ui-stack">
            @foreach ($rounds as $round)
                @php $attempt = $round['attempt']; $failed = collect($attempt['report']['gates'] ?? [])->where('status', 'fail'); @endphp
                <details class="ui-disclosure" @if ($loop->last) open @endif>
                    <summary class="ui-disclosure__head">
                        <span>
                            {{ ___('Attempt :n', ['n' => $attempt['n'] ?? $loop->iteration]) }}
                            @if (! empty($attempt['error']))
                                — <span class="ui-text-danger">{{ $attempt['error'] }}</span>
                            @elseif ($failed->isEmpty())
                                — <span class="ui-text-success">{{ ___('all gates passed') }}</span>
                            @else
                                — <span class="ui-text-danger">{{ ___(':n gate(s) failed', ['n' => $failed->count()]) }}</span>
                            @endif
                        </span>
                    </summary>
                    <div class="ui-disclosure__body ui-stack">
                        @foreach ($failed as $gate)
                            <div class="ui-small"><strong>{{ $gate['label'] ?? $gate['id'] }}</strong>
                                @foreach ($gate['errors'] as $e)<div class="ui-text-danger ui-break">{{ $e }}</div>@endforeach
                            </div>
                        @endforeach
                        @foreach (['liquid_diff' => 'liquid', 'css_diff' => 'css'] as $k => $label)
                            @if (is_array($round[$k]) && collect($round[$k])->where('op', '!=', ' ')->isNotEmpty())
                                <p class="ui-small ui-strong">{{ ___('Changes in :file since the previous attempt', ['file' => $label]) }}</p>
                                <pre class="tss-code">@foreach ($round[$k] as $l)@if ($l['op'] !== ' ')<span class="{{ $l['op'] === '+' ? 'is-add' : 'is-del' }}">{{ $l['op'] }} {{ $l['text'] }}</span>@endif @endforeach</pre>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
    </x-ui.card>
</div>
@endsection
