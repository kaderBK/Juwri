{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Bio page'))
@section('page_title', ___('Bio page'))
@section('page_sub', ___('Your Linktree-style landing page — the link in your Instagram, TikTok and printed QR.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif
@if(session('error'))
    <x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>
@endif
@if(isset($errors) && $errors->any())
    <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
@endif

@php
    $modeLabels = [
        'menu_first' => ___('Menu first — guests land on the menu; the bio page is optional.'),
        'bio_first'  => ___('Bio first — guests see the bio page once, then can jump to the menu.'),
        'bio_only'   => ___('Bio only — the bio page replaces the menu as the store home.'),
    ];
    $sectionLabels = [
        'hours'   => ___('Opening hours'),
        'socials' => ___('Social links'),
        'capture' => ___('Email / phone capture'),
        'map'     => ___('Map & directions'),
        'whatsapp'=> ___('WhatsApp button'),
        'reviews' => ___('Reviews'),
    ];
@endphp

<style nonce="{{ csp_nonce() }}">
    /* Selection state is driven by the real radio via :has(), so the highlight,
       ring, check and caption always agree — even before the form is saved. */
    .bio-option { transition: border-color .15s, background-color .15s; }
    .bio-option:has(input:checked) { border-color: var(--bs-primary) !important; background: rgba(var(--bs-primary-rgb), .08); }
    .bio-preset-card { transition: border-color .15s, box-shadow .15s; }
    .bio-preset-card:hover { border-color: var(--bs-primary); }
    .bio-preset-card:has(input:checked) { border-color: var(--bs-primary) !important; box-shadow: 0 0 0 .18rem rgba(var(--bs-primary-rgb), .28); }
    .bio-preset-card:has(input:disabled) { cursor: not-allowed; }
    .bio-preset-card .bio-preset-check { display: none; }
    .bio-preset-card:has(input:checked) .bio-preset-check { display: inline-flex; }
    .bio-preset-card:has(input:checked) .bio-preset-name { color: var(--bs-primary); font-weight: 600; }
</style>

<div class="ui-cluster">
    <x-ui.button :href="route('biolink.builder')" variant="secondary" icon="more">{{ ___('Open builder') }}</x-ui.button>
    <x-ui.button :href="route('biolink.insights')" variant="secondary" icon="trend-up">{{ ___('Insights') }}</x-ui.button>
</div>

{{-- Editor mode: block builder vs Advanced HTML (Builder.js). Additive — switching
     to Advanced HTML keeps the store's blocks; switching back is non-destructive. --}}
<x-ui.card>
    <div class="ui-cluster">
        <div class="ui-me-auto">
            <div class="ui-strong">{{ ___('Bio editor') }}</div>
            <p class="ui-muted">
                {{ ___('Build your bio page with drag-and-drop blocks, or switch to a freeform Advanced HTML page designed in the visual builder.') }}
            </p>
        </div>
        @if(($bioBuilder ?? 'blocks') === 'html')
            <span class="ui-tag" data-tone="accent">{{ ___('Advanced HTML') }}</span>
            <x-ui.button :href="route('biolink.builder', ['store_id' => $store->id])" icon="more">{{ ___('Open HTML editor') }}</x-ui.button>
            <form action="{{ route('biolink.html.disable') }}" method="post">
                @csrf
                <input type="hidden" name="store_id" value="{{ $store->id }}">
                <x-ui.button type="submit" variant="secondary" icon="more">{{ ___('Switch to block editor') }}</x-ui.button>
            </form>
        @else
            <span class="ui-tag" data-tone="neutral">{{ ___('Block editor') }}</span>
            <form action="{{ route('biolink.html.enable') }}" method="post">
                @csrf
                <input type="hidden" name="store_id" value="{{ $store->id }}">
                <x-ui.button type="submit" icon="list">{{ ___('Switch to Advanced HTML') }}</x-ui.button>
            </form>
        @endif
    </div>
</x-ui.card>

<form action="{{ route('biolink.settings.save') }}" method="post" class="ui-form-grid" data-biolink-settings>
    @csrf

    {{-- Enable + share -------------------------------------------------------- --}}
    <div>
        <x-ui.card :title="___('Publish')" class="ui-fill">

            <div class="ui-check">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" role="switch" id="bio-enabled" name="enabled" value="1" @checked($bioSettings['enabled'])>
                <label for="bio-enabled">{{ ___('Bio page is live') }}</label>
            </div>
            <p class="ui-muted">{{ ___('When live, guests can open your bio page and it can act as your store home (see Routing below).') }}</p>

            <label class="ui-muted ui-label">{{ ___('Public link') }}</label>
            <div class="ui-input-group ui-input-group--sm">
                <input type="text" class="ui-input" value="{{ $bioUrl }}" readonly data-biolink-url aria-label="{{ ___('Public link') }}">
                <button type="button" class="ui-btn ui-btn--secondary" data-biolink-copy>{{ ___('Copy') }}</button>
            </div>
            <div class="ui-cluster">
                <a href="{{ $bioUrl }}" target="_blank" rel="noopener" class="ui-btn ui-btn--sm ui-btn--secondary">
                    <x-ui.shell.nav-icon glyph="arrow-up-right-from-square" />{{ ___('Preview') }}</a>
                @if(\Route::has('qr.designer'))
                    <a href="{{ route('qr.designer') }}" class="ui-btn ui-btn--sm ui-btn--secondary"><x-ui.shell.nav-icon glyph="qrcode" />{{ ___('QR designer') }}</a>
                @endif
            </div>
        </x-ui.card>
    </div>

    {{-- Routing mode --------------------------------------------------------- --}}
    <div>
        <x-ui.card :title="___('Routing')" class="ui-fill">

            <div class="ui-stack" role="radiogroup" aria-label="{{ ___('Routing mode') }}">
                @foreach($modes as $mode)
                    @php
                        $disabled = ($mode === 'bio_only' && ! $canBioOnly);
                        $selected = $bioSettings['mode'] === $mode;
                        $parts = explode(' — ', $modeLabels[$mode] ?? $mode, 2);
                        $mTitle = $parts[0]; $mDesc = $parts[1] ?? '';
                    @endphp
                    <label for="mode-{{ $mode }}" class="ui-cluster ui-cluster--top bio-option ui-bordered"
                           style="cursor:{{ $disabled ? 'not-allowed' : 'pointer' }};{{ $disabled ? 'opacity:.7;' : '' }}">
                        <input type="radio" name="mode" id="mode-{{ $mode }}" value="{{ $mode }}"
                               data-biolink-mode @checked($selected) @disabled($disabled)>
                        <span class="min-w-0">
                            <span class="ui-block ui-strong ui-small">{{ $mTitle }}
                                @if($disabled)<x-ui.tag tone="warning" icon-left="lock">{{ ___('Upgrade') }}</x-ui.tag>@endif
                            </span>
                            @if($mDesc)<span class="ui-muted ui-block">{{ $mDesc }}</span>@endif
                        </span>
                    </label>
                @endforeach
            </div>

            @if(! $canBioOnly)
                <p class="ui-muted">{{ ___('“Bio only” mode is available on higher plans.') }}</p>
            @endif

            <hr>

            <div class="ui-check">
                <input type="hidden" name="branch_pages" value="0">
                <input type="checkbox" role="switch" id="branch-pages" name="branch_pages" value="1" @checked($bioSettings['branch_pages'])>
                <label for="branch-pages">{{ ___('Enable per-branch bio pages') }}</label>
            </div>
            <p class="ui-muted">{{ ___('Each branch can show its own hours, map and WhatsApp on ?branch= links.') }}</p>
        </x-ui.card>
    </div>

    {{-- Section toggles ------------------------------------------------------ --}}
    <div>
        <x-ui.card :title="___('Sections')" class="ui-fill">
            <p class="ui-muted">{{ ___('Show or hide the standard blocks. Custom blocks are managed in the builder.') }}</p>
            @foreach($sections as $key)
                <div class="ui-check">
                    <input type="hidden" name="sections[{{ $key }}]" value="0">
                    <input type="checkbox" role="switch" id="section-{{ $key }}" name="sections[{{ $key }}]" value="1"
                           @checked($bioSettings['sections'][$key] ?? false)>
                    <label for="section-{{ $key }}">{{ $sectionLabels[$key] ?? $key }}</label>
                </div>
            @endforeach
        </x-ui.card>
    </div>

    {{-- Preset picker -------------------------------------------------------- --}}
    <div>
        <x-ui.card :title="___('Design preset')" class="ui-fill">
            @if(empty($presets))
                <x-ui.empty :title="___('Presets are loading')" :body="___('Design presets will appear here. Your page uses the Classic Flat look until then.')" />
            @else
                <div class="ui-form-grid" role="radiogroup" aria-label="{{ ___('Design preset') }}">
                    @foreach($presets as $preset)
                        @php
                            $slug = $preset['slug'] ?? '';
                            $allowed = (bool) ($preset['allowed'] ?? false);
                            $selected = ($bioSettings['preset'] ?? '') === $slug;
                        @endphp
                        <div>
                            <label class="bio-preset-card ui-block ui-fill ui-relative ui-bordered ui-center"
                                   style="cursor:{{ $allowed ? 'pointer' : 'not-allowed' }};{{ $allowed ? '' : 'opacity:.9;' }}">
                                <input type="radio" class="ui-visually-hidden" name="preset" value="{{ $slug }}"
                                       @checked($selected) @disabled(! $allowed) data-biolink-preset>
                                <div class="ui-relative">
                                    @includeIf('bio.presets.thumbs.'.$slug)
                                    @unless(view()->exists('bio.presets.thumbs.'.$slug))
                                        <div class="ui-cluster ui-cluster--center ui-text-2 ui-mx-auto"
                                             style="max-width:150px;aspect-ratio:118/168;background:var(--bs-tertiary-bg);border-radius:16px;">
                                            <x-ui.shell.nav-icon glyph="palette" style="font-size:1.6rem;" />
                                        </div>
                                    @endunless
                                    <span class="ui-tag bio-preset-check ui-absolute ui-top-0 ui-end-0" data-tone="accent"
                                          style="width:22px;height:22px;transform:translate(20%,-20%);"><x-ui.shell.nav-icon glyph="check" /></span>
                                    @unless($allowed)
                                        <span class="ui-tag ui-absolute ui-top-0 ui-end-0" data-tone="neutral"
                                              style="transform:translate(10%,-10%);"><x-ui.shell.nav-icon glyph="lock" />{{ ___('Pro') }}</span>
                                    @endunless
                                </div>
                                <div class="bio-preset-name ui-small ui-truncate">{{ $preset['name'] ?? $slug }}</div>
                            </label>
                        </div>
                    @endforeach
                </div>
                <p class="ui-muted">{{ ___('Locked presets are available on higher plans. Your live page always falls back to a preset you can use.') }}</p>
            @endif
        </x-ui.card>
    </div>

    <div class="ui-cluster ui-cluster--end ui-form-grid__full">
        <x-ui.button type="submit" icon="check">{{ ___('Save settings') }}</x-ui.button>
    </div>
</form>

{{-- Copy to another store (C5) — its own form, listing only the owner's OTHER
     stores; hidden entirely when the owner has just one store. Replaces the
     target's bio blocks after an explicit confirm; the target stays unpublished. --}}
@if(! empty($copyTargets))
    <div class="ui-form-grid">
        <div class="ui-form-grid__full">
            <x-ui.card :title="___('Copy to another store')" data-biolink-copy>
                <p class="ui-muted">{{ ___('Copy this page’s design, sections and blocks onto one of your other stores. The target stays unpublished until you turn it on there.') }}</p>
                <form action="{{ route('biolink.copy-to') }}" method="post" class="ui-form-grid"
                      data-confirm="{{ ___('This replaces the target store’s current bio blocks. Continue?') }}" data-confirm-danger>
                    @csrf
                    <div>
                        <label class="ui-muted ui-label" for="bio-copy-target">{{ ___('Target store') }}</label>
                        <select class="ui-input" id="bio-copy-target" name="target" required>
                            <option value="">{{ ___('— Choose a store —') }}</option>
                            @foreach($copyTargets as $t)
                                <option value="{{ $t['id'] }}">{{ $t['title'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="ui-btn ui-btn--secondary" data-biolink-copy-submit>
                            <x-ui.shell.nav-icon glyph="copy" />{{ ___('Copy page') }}
                        </button>
                    </div>
                </form>
                <p class="ui-text-danger ui-small"><x-ui.shell.nav-icon glyph="triangle-warning" />{{ ___('This replaces the target store’s current bio blocks.') }}</p>
            </x-ui.card>
        </div>
    </div>
@endif

<script nonce="{{ csp_nonce() }}">
(function () {
    var root = document.querySelector('[data-biolink-settings]');
    if (!root) return;

    var copyBtn = root.querySelector('[data-biolink-copy]');
    var urlInput = root.querySelector('[data-biolink-url]');
    if (copyBtn && urlInput) {
        copyBtn.addEventListener('click', function () {
            urlInput.removeAttribute('readonly');
            urlInput.select();
            var done = false;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(urlInput.value);
                    done = true;
                }
            } catch (e) {}
            if (!done) { try { document.execCommand('copy'); } catch (e) {} }
            urlInput.setAttribute('readonly', 'readonly');
            var original = copyBtn.getAttribute('data-label') || copyBtn.textContent;
            copyBtn.setAttribute('data-label', original);
            copyBtn.textContent = {!! json_encode(___('Copied'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
            setTimeout(function () { copyBtn.textContent = original; }, 1500);
        });
    }

    // C5 · confirm before replacing another store's blocks is now declarative
    // (data-confirm on the form → shared dashConfirm in partials/toast.blade.php).
})();
</script>

@endsection
