{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Theme Studio'))
@section('page_title', ___('Theme Studio'))
@section('body_class', 'ts-page')

@php
    /*
     * Theme Studio editor (spec rev/theme-studio §3 Phase C, contract
     * rev/theme-studio/EDITOR-CONTRACT.md). The controller hands over ONE array,
     * $studio. It ships to the editor as a JSON data block (not executed, so
     * CSP-neutral); behaviour lives in /assets/theme-studio/studio.js.
     *
     * The view adds a few UI-only keys under `ui` (picker choices, vertical group,
     * dashboard direction) and merges its own translated strings UNDER the
     * controller's `i18n` (the controller's wording wins).
     */
    $studio = is_array($studio ?? null) ? $studio : [];
    $tsStore = (array) ($studio['store'] ?? []);
    $tsStores = array_values((array) ($studio['stores'] ?? []));
    $tsTheme = is_array($studio['theme'] ?? null) ? $studio['theme'] : null;
    $tsThemes = array_values((array) ($studio['themes'] ?? []));
    $tsRecipes = array_values((array) ($studio['recipes'] ?? []));
    $tsLanguages = array_values(array_filter((array) ($tsStore['languages'] ?? []), 'is_array'));
    $tsDefaultLang = (string) ($tsStore['default_language'] ?? ($tsLanguages[0]['code'] ?? ''));
    $tsType = (string) ($tsStore['store_type'] ?? 'restaurant');
    $tsGroup = (string) config('verticals.types.'.$tsType.'.group', 'food');
    $tsDir = function_exists('current_language') && current_language() ? (string) current_language()->direction : 'ltr';
    $tsDir = $tsDir === 'rtl' ? 'rtl' : 'ltr';

    // Choices for the category / item_list / branch setting pickers — the
    // store's OWN rows (same source as the theme customizer). The controller may
    // pass them already; otherwise they are read here for the page's store.
    $tsPickers = $studio['pickers'] ?? null;
    if (! is_array($tsPickers)) {
        $tsPickers = ['category' => [], 'item_list' => [], 'branch' => []];
        $tsPickerStore = ! empty($tsStore['id']) ? \Juwri\Core\Models\Store::find((int) $tsStore['id']) : null;
        if ($tsPickerStore && method_exists(\App\Services\ThemeCustomizerService::class, 'pickerOptions')) {
            $tsPickers = \App\Services\ThemeCustomizerService::pickerOptions($tsPickerStore);
        }
    }

    $tsStrings = [
        // Top bar
        'Theme Studio', 'Store', 'Device', 'Phone', 'Tablet', 'Desktop', 'Language', 'Undo', 'Redo', 'Preview', 'Publish',
        'History', 'Saved', 'Saving…', 'Unsaved changes', 'Not saved', 'Draft',
        'Could not save. Check your connection and try again.', 'Some changes could not be saved.',
        'Some changes are not allowed for your account and were undone.',
        'Published. Your storefront now shows these changes.', 'Could not publish. Try again.',
        'Section', 'Hide section', 'Show section',
        // Panels
        'Sections', 'Theme', 'Settings', 'Edit section', 'Add section', 'Add section below', 'Hidden',
        'Duplicate', 'Remove', 'Move up', 'Move down', 'Drag to reorder', 'Close',
        'Remove this section?', 'The section and its content leave the draft. You can undo this.',
        'Remove this block?', 'Select a section in the preview or in the list to edit it.',
        'This section has no settings.', 'Blocks', 'Add block', 'Add :name', 'Back to :name',
        'You can have up to :max sections.', 'You can have up to :max blocks in a section.',
        'This section can be used once per page.', 'You can add up to :max of these.',
        'Sections are locked by your administrator.', 'Theme settings are locked by your administrator.',
        'Editing :lang. Leave a text blank to use :default.', 'Moved :name to position :n.', 'Removed :name.',
        'Added :name.', 'Undone.', 'Redone.', 'Unknown section', 'This section type is no longer available.',
        // Gallery
        'Add a section', 'Search sections', 'All', 'Recommended for your store', 'Only sections for my store type',
        'No sections match your search.', 'Chrome', 'Hero', 'Info', 'Engage', 'Discover', 'Catalogue', 'Compliance',
        'Trust', 'Content', 'Action', 'Custom', 'Other',
        // Fields
        'Upload image', 'Replace image', 'Remove image', 'Uploading…', 'Upload failed. Use a JPG, PNG or WebP under 5 MB.',
        'Choose…', 'None', 'Bold', 'Italic', 'Link', 'Link address', 'Use a full address (https://…), a page path (/…), a section (#…), an email (mailto:…) or a phone (tel:…).',
        'Selected :n of :max', 'Find an item', 'Must be a number.', 'Must be at least :min.', 'Must be at most :max.',
        'Primary', 'Background', 'Surface', 'Text', 'Accent',
        // Theme tab
        'Colours', 'Fonts', 'Layout', 'Presets', 'Contrast warnings', 'Low contrast: :pair (:ratio:1).',
        'Colours and fonts are saved with your draft and go live when you publish.',
        // History
        'Restore', 'Restore this version?', 'Your draft is replaced by this version. You can undo this.',
        'Restored.', 'No published versions yet.', 'Discard draft', 'Discard all unpublished changes?',
        'The draft goes back to what is live now.', 'Draft discarded.', 'Changes: :n',
        // Loading / errors
        'Loading…', 'Could not load the editor.', 'Retry', 'Storefront preview',
        // Theme picker
        'Choose a theme to start', 'Pick a Studio theme or a ready-made layout for your kind of store. You can change everything afterwards.',
        'Themes', 'Ready-made layouts', 'Use this theme', 'Use this layout', 'Start with :name?',
        'Your store switches to this theme as a draft. Customers keep seeing your current design until you publish.',
        'Could not start. Try again.', 'Recommended',
    ];
    $tsI18n = [];
    foreach ($tsStrings as $s) {
        $tsI18n[$s] = ___($s);
    }

    $tsConfig = $studio;
    $tsConfig['i18n'] = array_merge($tsI18n, (array) ($studio['i18n'] ?? []));
    $tsConfig['ui'] = [
        'group' => $tsGroup,
        'dir' => $tsDir,
        'pickers' => $tsPickers,
        'page' => url()->current(),
    ];

    $tsDevices = ['phone' => ___('Phone'), 'tablet' => ___('Tablet'), 'desktop' => ___('Desktop')];
    $tsLangOptions = [];
    foreach ($tsLanguages as $l) {
        $tsLangOptions[(string) ($l['code'] ?? '')] = (string) ($l['name'] ?? strtoupper((string) ($l['code'] ?? '')));
    }
    // Phase D — AI theme builder (contract rev/theme-studio/AI-BUILDER-CONTRACT.md):
    // only when the cap is on AND the backend handed over its endpoints.
    $tsUrls = (array) ($studio['urls'] ?? []);
    $tsAiOn = ! empty(((array) ($studio['caps'] ?? []))['ai_generate'])
        && ! empty($tsUrls['ai_generate']) && ! empty($tsUrls['ai_preview']) && ! empty($tsUrls['ai_apply']);
    $tsAiMeta = (array) ($studio['ai'] ?? []);
    $tsAiImageCost = isset($tsAiMeta['image_cost']) && is_numeric($tsAiMeta['image_cost']) ? (int) $tsAiMeta['image_cost'] : null;
    $tsAiRemaining = isset($tsAiMeta['remaining']) && is_numeric($tsAiMeta['remaining']) ? max(0, (int) $tsAiMeta['remaining']) : null;
@endphp

@section('page_sub', $tsTheme
    ? ___('Edit your storefront on the page itself. Changes stay in a draft until you publish.')
    : ___('Pick a Studio theme or a ready-made layout for your kind of store. You can change everything afterwards.'))

@push('styles')
<link rel="stylesheet" href="{{ \Juwri\Core\Support\Views\StoreViewHelpers::tsAsset('assets/theme-studio/studio.css') }}">
@if ($tsAiOn)
<link rel="stylesheet" href="{{ \Juwri\Core\Support\Views\StoreViewHelpers::tsAsset('assets/theme-studio/studio-ai.css') }}">
@endif
@endpush

@section('content')
<script type="application/json" id="theme-studio-config">@json($tsConfig)</script>

<div class="ui-scope ui-studio">
<div class="ts-app" id="theme-studio" dir="{{ $tsDir }}" data-mode="{{ $tsTheme ? 'editor' : 'picker' }}">

    {{-- ── Toolbar: store + theme · undo/redo · save state · actions ─────── --}}
    <header class="ui-card ts-top" aria-label="{{ ___('Theme Studio') }}">
        <div class="ts-top__start">
            @if (count($tsStores) > 1)
                <label class="ui-visually-hidden" for="ts-store">{{ ___('Store') }}</label>
                <div class="ui-field__control ts-top__store">
                    <x-ui.icon name="store" size="18" class="ts-top__store-icon" />
                    <select id="ts-store" data-ts-store>
                        @foreach ($tsStores as $s)
                            <option value="{{ (int) ($s['id'] ?? 0) }}" @selected((int) ($s['id'] ?? 0) === (int) ($tsStore['id'] ?? 0))>{{ $s['title'] ?? '' }}</option>
                        @endforeach
                    </select>
                    <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
                </div>
            @else
                <span class="ts-top__store ts-top__store--one"><x-ui.icon name="store" size="18" class="ts-top__store-icon" /><span>{{ $tsStore['title'] ?? '' }}</span></span>
            @endif
            @if ($tsTheme)
                <x-ui.tag tone="accent" class="ts-top__theme" title="{{ $tsTheme['template'] ?? '' }}">{{ $tsTheme['name'] ?? ($tsTheme['slug'] ?? '') }}</x-ui.tag>
            @endif
        </div>

        @if ($tsTheme)
            <div class="ts-top__end">
                <div class="ts-top__undo">
                    <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-ts-undo aria-label="{{ ___('Undo') }}" title="{{ ___('Undo') }}" disabled><span data-ts-icon="undo"></span></button>
                    <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-ts-redo aria-label="{{ ___('Redo') }}" title="{{ ___('Redo') }}" disabled><span data-ts-icon="redo"></span></button>
                </div>
                <span class="ui-pill ts-save" data-tone="neutral" data-ts-save role="status" aria-live="polite">{{ ___('Loading…') }}</span>
                <div class="ts-top__actions">
                    @if ($tsAiOn)
                        <x-ui.button variant="ghost" size="sm" icon="sparkle" class="ts-ai-open" data-ts-ai-open aria-haspopup="dialog" aria-controls="ts-ai" :title="___('Design with AI')"><span class="ts-top__label">{{ ___('Design with AI') }}</span></x-ui.button>
                    @endif
                    <x-ui.button variant="ghost" size="sm" icon="clock" data-ts-history :title="___('History')"><span class="ts-top__label">{{ ___('History') }}</span></x-ui.button>
                    <x-ui.button variant="secondary" size="sm" icon="external" data-ts-preview :title="___('Preview')"><span class="ts-top__label">{{ ___('Preview') }}</span></x-ui.button>
                    <x-ui.button size="sm" data-ts-publish>{{ ___('Publish') }}</x-ui.button>
                </div>
            </div>
        @endif
    </header>

    @if ($tsTheme)
        {{-- ── Workspace: left panel · canvas · right panel ─────────────── --}}
        <div class="ts-work">
            <aside class="ui-card ui-card--flush ts-panel ts-panel--left" data-ts-left-home aria-label="{{ ___('Sections') }}">
                <div class="ts-panel__body" data-ts-left>
                    <div class="ts-tabs" role="tablist" aria-label="{{ ___('Theme Studio') }}">
                        <button type="button" role="tab" id="ts-tab-sections" class="ts-tab" aria-selected="true" aria-controls="ts-pane-sections" data-ts-tab="sections">{{ ___('Sections') }}</button>
                        <button type="button" role="tab" id="ts-tab-theme" class="ts-tab" aria-selected="false" aria-controls="ts-pane-theme" data-ts-tab="theme" tabindex="-1">{{ ___('Theme') }}</button>
                    </div>
                    <div class="ts-pane" role="tabpanel" id="ts-pane-sections" aria-labelledby="ts-tab-sections" data-ts-pane="sections">
                        <x-ui.skeleton :rows="5" />
                    </div>
                    <div class="ts-pane" role="tabpanel" id="ts-pane-theme" aria-labelledby="ts-tab-theme" data-ts-pane="theme" hidden></div>
                </div>
            </aside>

            <section class="ts-canvas" aria-label="{{ ___('Storefront preview') }}">
                <div @class(['ts-canvas__bar', 'ts-canvas__bar--solo' => count($tsLangOptions) < 2])>
                    <div class="ts-canvas__group ts-canvas__group--device" data-ts-devices>
                        <x-ui.segmented name="ts-device" value="desktop" :label="___('Device')" :options="$tsDevices" />
                    </div>
                    @if (count($tsLangOptions) > 1)
                        <div class="ts-canvas__group" data-ts-langs>
                            <x-ui.segmented name="ts-lang" :value="$tsDefaultLang" :label="___('Language')" :options="$tsLangOptions" />
                        </div>
                    @endif
                </div>
                <div class="ts-canvas__stage" data-ts-stage data-device="desktop">
                    <iframe class="ts-canvas__frame" data-ts-frame title="{{ ___('Storefront preview') }}" src="about:blank"></iframe>
                </div>
            </section>

            <aside class="ui-card ui-card--flush ts-panel ts-panel--right" data-ts-right-home aria-label="{{ ___('Settings') }}">
                <div class="ts-panel__body" data-ts-right>
                    <x-ui.skeleton :rows="4" />
                </div>
            </aside>
        </div>

        {{-- Phones: canvas full width, panels open as bottom sheets. --}}
        <nav class="ts-mobilebar" aria-label="{{ ___('Theme Studio') }}">
            <button type="button" class="ui-btn ui-btn--secondary ts-mobilebar__btn" data-ts-open="left" data-ts-tab-target="sections"><span data-ts-icon="list"></span>{{ ___('Sections') }}</button>
            <button type="button" class="ui-btn ui-btn--secondary ts-mobilebar__btn" data-ts-open="left" data-ts-tab-target="theme"><span data-ts-icon="palette"></span>{{ ___('Theme') }}</button>
            <button type="button" class="ui-btn ts-mobilebar__btn" data-ts-open="right" data-ts-edit disabled><span data-ts-icon="edit"></span><span class="ts-mobilebar__label" data-ts-edit-label>{{ ___('Edit section') }}</span></button>
        </nav>

        <x-ui.sheet id="ts-sheet-left" class="ts-sheet ts-sheet--panel" :title="___('Sections')">
            <div data-ts-left-sheet></div>
            <x-slot:footer>
                <x-ui.button variant="secondary" data-ui-close block>{{ ___('Close') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.sheet>

        <x-ui.sheet id="ts-sheet-right" class="ts-sheet ts-sheet--panel" :title="___('Settings')">
            <div data-ts-right-sheet></div>
            <x-slot:footer>
                <x-ui.button variant="secondary" data-ui-close block>{{ ___('Close') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.sheet>

        <x-ui.sheet id="ts-gallery" class="ts-sheet ts-sheet--wide" :title="___('Add a section')">
            <div data-ts-gallery></div>
            <x-slot:footer>
                <x-ui.button variant="secondary" data-ui-close>{{ ___('Close') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.sheet>

        <x-ui.sheet id="ts-history" class="ts-sheet" :title="___('History')">
            <div data-ts-history-list><x-ui.skeleton :rows="3" /></div>
            <x-slot:footer>
                <x-ui.button variant="danger" size="sm" data-ts-discard>{{ ___('Discard draft') }}</x-ui.button>
                <x-ui.button variant="secondary" size="sm" data-ui-close>{{ ___('Close') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.sheet>

        <input type="file" accept="image/jpeg,image/png,image/webp" class="ui-visually-hidden" data-ts-file tabindex="-1" aria-hidden="true">
    @else
        {{-- ── Not on a Studio theme yet: theme / recipe picker ──────────── --}}
        <div class="ts-picker">
            @if ($tsAiOn)
                <section class="ts-ai-entry" aria-labelledby="ts-ai-entry-title">
                    <span class="ts-ai-entry__icon" aria-hidden="true"><x-ui.icon name="sparkle" size="24" /></span>
                    <div class="ts-ai-entry__text">
                        <h2 class="ts-ai-entry__title" id="ts-ai-entry-title">{{ ___('Build my theme with AI') }}</h2>
                        <p class="ts-ai-entry__lead">{{ ___('Describe your store in a few words and get 3 complete designs, with layout, colours, fonts and text, in about a minute.') }}</p>
                    </div>
                    <x-ui.button class="ts-ai-entry__btn" icon="sparkle" data-ts-ai-open aria-haspopup="dialog" aria-controls="ts-ai">{{ ___('Describe your store') }}</x-ui.button>
                </section>
            @endif

            @if (count($tsThemes))
                <section class="ts-picker__group" aria-labelledby="ts-picker-themes">
                    <h2 class="ui-section-title" id="ts-picker-themes">{{ ___('Themes') }}</h2>
                    <ul class="ts-cards" role="list">
                        @foreach ($tsThemes as $th)
                            @php $thGroups = array_values((array) ($th['vertical_groups'] ?? [])); @endphp
                            <li class="ts-card">
                                <button type="button" class="ts-card__btn" data-ts-start-theme="{{ $th['slug'] ?? '' }}" data-ts-start-name="{{ $th['name'] ?? '' }}">
                                    @if (! empty($th['thumb']))
                                        <img class="ts-card__thumb" src="{{ $th['thumb'] }}" alt="" loading="lazy" width="320" height="200">
                                    @else
                                        <span class="ts-card__thumb ts-card__thumb--blank" aria-hidden="true">{{ mb_substr((string) ($th['name'] ?? '?'), 0, 1) }}</span>
                                    @endif
                                    <span class="ts-card__head">
                                        <span class="ts-card__name">{{ $th['name'] ?? '' }}</span>
                                        @if (\Juwri\Core\Support\Views\StoreViewHelpers::tsRecommended($thGroups, $tsGroup, $tsType))
                                            <x-ui.tag tone="success" class="ts-card__tag">{{ ___('Recommended') }}</x-ui.tag>
                                        @endif
                                    </span>
                                    <span class="ts-card__cta">{{ ___('Use this theme') }}<x-ui.icon name="chevron" size="16" :flip="true" /></span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (count($tsRecipes))
                <section class="ts-picker__group" aria-labelledby="ts-picker-recipes">
                    <h2 class="ui-section-title" id="ts-picker-recipes">{{ ___('Ready-made layouts') }}</h2>
                    <ul class="ts-cards ts-cards--compact" role="list">
                        @foreach ($tsRecipes as $rc)
                            <li class="ts-card">
                                <button type="button" class="ts-card__btn" data-ts-start-recipe="{{ $rc['id'] ?? '' }}" data-ts-start-name="{{ $rc['name'] ?? '' }}">
                                    <span class="ts-card__head">
                                        <span class="ts-card__name">{{ $rc['name'] ?? '' }}</span>
                                        @if (($rc['vertical'] ?? '') === $tsType)
                                            <x-ui.tag tone="success" class="ts-card__tag">{{ ___('Recommended') }}</x-ui.tag>
                                        @endif
                                    </span>
                                    <span class="ts-card__cta">{{ ___('Use this layout') }}<x-ui.icon name="chevron" size="16" :flip="true" /></span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif

    <div class="ui-visually-hidden" aria-live="polite" data-ts-live></div>

    @if ($tsAiOn)
        @include('owner.design.studio-ai-panel', ['tsAiImageCost' => $tsAiImageCost, 'tsAiRemaining' => $tsAiRemaining, 'tsAiEditor' => (bool) $tsTheme])
    @endif
</div>
</div>
@endsection

@push('scripts')
<script src="{{ \Juwri\Core\Support\Views\StoreViewHelpers::tsAsset('assets/theme-studio/studio.js') }}" nonce="{{ csp_nonce() }}" defer></script>
@if ($tsAiOn)
<script src="{{ \Juwri\Core\Support\Views\StoreViewHelpers::tsAsset('assets/theme-studio/studio-ai.js') }}" nonce="{{ csp_nonce() }}" defer></script>
@endif
@endpush
