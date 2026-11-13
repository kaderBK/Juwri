{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Bio builder'))
@section('page_title', ___('Bio builder'))
@section('page_sub', ___('Add, reorder and schedule the blocks guests see on your bio page.'))

@section('page_actions')
    <div class="ui-cluster">
        <x-ui.button :href="route('biolink.index')" variant="secondary" icon="settings">{{ ___('Settings') }}</x-ui.button>
        <x-ui.button :href="route('biolink.insights')" variant="secondary" icon="trend-up">{{ ___('Insights') }}</x-ui.button>
    </div>
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
    // bio-t15 C3/C6: the original 17 (SPEC-frozen names) plus the Tier 1/2
    // catalog (SPEC §2 A1–A11). "hours", "reservation" and "reviews" REUSE the
    // pre-existing slug — Lane A deepens their config shape, no new BLOCK_TYPES
    // entry needed. "call", "delivery", "giftcard", "loyalty", "gallery", "faq",
    // "event" and "tipjar" are brand-new slugs (ORCHESTRATION's Lane A blade-file
    // list). A type not yet in BLOCK_TYPES (Lane A hasn't landed it in this tree)
    // simply never gets a chip below (array_intersect) — its FORM FIELDS still
    // render in the editor so the two lanes integrate the moment A lands.
    $typeLabels = [
        'link' => ___('Link'), 'menu' => ___('Menu'), 'menu_item' => ___('Menu item'),
        'hours' => ___('Opening hours'), 'map' => ___('Map'), 'whatsapp' => ___('WhatsApp'),
        'delivery_apps' => ___('Delivery apps'), 'reservation' => ___('Reservation'),
        'reviews' => ___('Reviews'), 'announcement' => ___('Announcement'), 'socials' => ___('Social links'),
        'capture' => ___('Email / phone capture'), 'video' => ___('Video'), 'featured_dish' => ___('Featured dish'),
        'text' => ___('Text'), 'image' => ___('Image'), 'divider' => ___('Divider'),
        // Tier 1 (A1–A7)
        'call' => ___('Call / WhatsApp / SMS'),
        'delivery' => ___('Delivery apps'),
        'giftcard' => ___('Gift card'),
        'loyalty' => ___('Loyalty / wallet pass'),
        // Tier 2 (A8–A11)
        'gallery' => ___('Photo gallery'),
        'faq' => ___('FAQ'),
        'event' => ___('Event / RSVP'),
        'tipjar' => ___('Tip jar'),
    ];
    $typeIcons = [
        'link' => 'link', 'menu' => 'utensils', 'menu_item' => 'hamburger', 'hours' => 'clock',
        'map' => 'marker', 'whatsapp' => 'whatsapp', 'delivery_apps' => 'moped', 'reservation' => 'calendar',
        'reviews' => 'star', 'announcement' => 'megaphone', 'socials' => 'share', 'capture' => 'envelope',
        'video' => 'play', 'featured_dish' => 'hat-chef',
        'text' => 'align-left', 'image' => 'picture', 'divider' => 'minus-small',
        'call' => 'phone-call', 'delivery' => 'moped', 'giftcard' => 'gift', 'loyalty' => 'wallet',
        'gallery' => 'layers', 'faq' => 'interrogation', 'event' => 'ticket', 'tipjar' => 'coins',
    ];
    // "Add a block" tray, grouped so 28 types stay scannable (C6 palette regroup).
    // Only types the store's plan exposes ($blockTypes, BioLinkController::
    // BLOCK_TYPES) are shown; the groups just order + label them.
    $blockGroups = [
        ___('Content') => ['link', 'text', 'image', 'announcement', 'divider'],
        ___('Menu & food') => ['menu', 'menu_item', 'featured_dish', 'gallery'],
        ___('Visit & contact') => ['hours', 'map', 'whatsapp', 'call', 'reservation', 'event', 'delivery_apps', 'delivery'],
        ___('Social & media') => ['socials', 'reviews', 'video', 'capture', 'faq', 'giftcard', 'loyalty', 'tipjar'],
    ];
    // bio-t15 C7: cosmetic PRO badge only — the sanitizer/block-store gate is the
    // real enforcement (SPEC §8). This view has no plan-entitlement signal passed
    // down from the controller (out of Lane C's file fence), so the badge shows
    // unconditionally rather than guessing a user's plan.
    $proBlockTypes = ['tipjar'];
    $atLimit = $blockLimit < 999 && $blockCount >= $blockLimit;
@endphp

<style nonce="{{ csp_nonce() }}">
    /* Bio builder — block rows + add tray. Theme-aware via Bootstrap tokens. */
    .bio-brow{display:flex;align-items:center;gap:.6rem;padding:.55rem .7rem;margin-bottom:.5rem;
        background:var(--bs-body-bg);border:1px solid var(--bs-border-color);border-radius:.85rem;
        transition:border-color .15s ease,box-shadow .15s ease,opacity .15s ease}
    .bio-brow:hover{border-color:var(--bs-primary);box-shadow:0 3px 12px rgba(0,0,0,.06)}
    .bio-brow--off{opacity:.55}
    .bio-brow.bio-brow--dragging{opacity:.4}
    .bio-brow.bio-brow--over{border-color:var(--bs-primary);box-shadow:0 0 0 2px rgba(var(--bs-primary-rgb),.28)}
    .bio-brow__grip{cursor:grab;color:var(--bs-secondary-color);display:flex;padding:.1rem;flex-shrink:0}
    .bio-brow__grip:active{cursor:grabbing}
    .bio-brow__nudge{display:flex;flex-direction:column;flex-shrink:0}
    .bio-brow__nudge-btn{border:0;background:none;color:var(--bs-secondary-color);line-height:.7;padding:0;cursor:pointer;font-size:.85rem}
    .bio-brow__nudge-btn:hover{color:var(--bs-primary)}
    .bio-brow__icon{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;flex-shrink:0;
        border-radius:.6rem;background:var(--bs-primary-bg-subtle);color:var(--bs-primary);font-size:1rem}
    .bio-brow__body{min-width:0;flex:1 1 auto}
    .bio-brow__label{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .bio-brow__meta{display:flex;flex-wrap:wrap;align-items:center;gap:.55rem;margin-top:.1rem;
        font-size:.72rem;color:var(--bs-secondary-color)}
    .bio-brow__chip{display:inline-flex;align-items:center;gap:.2rem}
    .bio-brow__chip i{font-size:.72rem}
    .bio-brow__chip--info{color:var(--bs-info)}
    .bio-brow__actions{display:flex;align-items:center;gap:.2rem;flex-shrink:0}
    .bio-brow__actions form{margin:0}
    .bio-brow__act{border:0;background:none;width:32px;height:32px;border-radius:.5rem;color:var(--bs-secondary-color);
        display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s,color .15s}
    .bio-brow__act:hover{background:var(--bs-secondary-bg);color:var(--bs-body-color)}
    .bio-brow__act--danger:hover{background:var(--bs-danger-bg-subtle);color:var(--bs-danger)}

    .bio-addtray{display:flex;flex-direction:column;gap:.9rem}
    .bio-addtray__head{font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
        color:var(--bs-secondary-color);margin-bottom:.4rem}
    .bio-addtray__chips{display:flex;flex-wrap:wrap;gap:.4rem}
    .bio-addchip{display:inline-flex;align-items:center;gap:.35rem;padding:.42rem .7rem;font-size:.82rem;font-weight:500;
        border:1px solid var(--bs-border-color);border-radius:.6rem;background:var(--bs-body-bg);color:var(--bs-body-color);
        cursor:pointer;transition:border-color .15s,background .15s,color .15s}
    .bio-addchip:hover:not(:disabled){border-color:var(--bs-primary);color:var(--bs-primary);background:var(--bs-primary-bg-subtle)}
    .bio-addchip:disabled{opacity:.5;cursor:not-allowed}
    .bio-addchip i{font-size:.9rem}
    .bio-addchip__pro{margin-inline-start:.3rem;font-size:.62rem;font-weight:700;letter-spacing:.04em;
        color:var(--bs-warning-emphasis,#7a5b00);background:var(--bs-warning-bg-subtle,#fff3cd);
        border-radius:999px;padding:.05rem .38rem}

    /* bio-t15 C2 — Blocks/Appearance tab pills (small footprint, matches the kit card look) */
    .bio-builder-tabs .nav-link{border-radius:.6rem;color:var(--bs-body-color);font-weight:500}
    .bio-builder-tabs .nav-link.active{background:var(--bs-primary);color:#fff}
    /* bio-t15 C7 — deferred (owner-setup-required) controls read as inert, not broken */
    .bio-ap-deferred{opacity:.65}
    .bio-ap-deferred input:disabled,.bio-ap-deferred select:disabled{background:var(--bs-secondary-bg)}
</style>

<div class="ui-form-grid">
    {{-- LEFT: blocks + add tray, or appearance ---------------------------------- --}}
    <div>

        {{-- bio-t15 C2 · Blocks / Appearance tab switcher. Appearance posts
             through the existing settings.save route and reloads; the switcher
             remembers which tab was open (sessionStorage, client-only — no
             server round trip needed) so the reload lands back on it. --}}
        <ul class="ui-chips bio-builder-tabs" data-builder-tabs role="tablist">
            <li role="presentation">
                <button type="button" class="ui-chip active" data-builder-tab-btn="blocks" role="tab" aria-selected="true" aria-current="true">
                    <x-ui.shell.nav-icon glyph="list-check" />{{ ___('Blocks') }}
                </button>
            </li>
            <li role="presentation">
                <button type="button" class="ui-chip" data-builder-tab-btn="appearance" role="tab" aria-selected="false">
                    <x-ui.shell.nav-icon glyph="palette" />{{ ___('Appearance') }}
                </button>
            </li>
        </ul>

        <div data-builder-panel="blocks">

        {{-- Limit meter --}}
        <div class="ui-cluster ui-cluster--between">
            <span class="ui-muted">
                {{ ___('Blocks') }}: <strong>{{ $blockCount }}</strong>@if($blockLimit < 999) / {{ $blockLimit }}@endif
            </span>
            @if($atLimit)
                <x-ui.tag tone="warning" icon-left="lock">{{ ___('Block limit reached') }}</x-ui.tag>
            @endif
        </div>

        {{-- Translate page (C4) — bulk-fills each block's config._i18n for every
             enabled locale via Lane B's AI translate job. Shown only when
             `ai_biolink` allows, the store has >1 locale, and there are blocks to
             translate. Renders nothing otherwise (SPEC T2-2). --}}
        @php $bioOverrideLocales = \Juwri\Core\Support\ViewHelpers::otherLocales($enabledLocales ?? [], $defaultLocale ?? 'en'); @endphp
        @if(($aiEnabled ?? false) && count($bioOverrideLocales) > 0 && $blockCount > 0)
            <div class="ui-cluster" data-biolink-translate
                 data-translate-url="{{ $translateUrl ?? '' }}" data-translate-status="{{ $translateStatusBase ?? '' }}">
                <button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" data-biolink-translate-start>
                    <x-ui.shell.nav-icon glyph="language" />{{ ___('Translate page') }}
                </button>
                <div class="ui-meter ui-grow ui-hidden" data-biolink-translate-progress>
                    <div class="ui-meter__bar" role="progressbar" style="width:0%" aria-valuemin="0" aria-valuemax="100" data-biolink-translate-bar></div>
                </div>
                <span class="ui-muted ui-hidden" data-biolink-translate-note></span>
            </div>
        @endif

        {{-- Block list --}}
        <div data-biolink-list data-reorder-url="{{ route('biolink.blocks.reorder') }}" data-csrf="{{ csrf_token() }}">
            @forelse($blocks as $block)
                @php
                    $bid = (int) ($block['id'] ?? 0);
                    $btype = (string) ($block['type'] ?? 'link');
                    $blabel = $block['label'] ?? ($typeLabels[$btype] ?? $btype);
                    $bactive = (bool) ($block['active'] ?? true);
                    $bscheduled = (bool) ($block['scheduled'] ?? false);
                    $bclicks = (int) ($block['clicks'] ?? 0);
                @endphp
                <div class="bio-brow bio-block-row @if(! $bactive) bio-brow--off @endif" data-block-id="{{ $bid }}" data-block='@json($block)' draggable="false">
                    <span class="bio-brow__grip" data-drag-handle title="{{ ___('Drag to reorder') }}" aria-hidden="true"><x-ui.shell.nav-icon glyph="menu-dots-vertical" /></span>
                    <div class="bio-brow__nudge">
                        <button type="button" class="bio-brow__nudge-btn" data-move="up" aria-label="{{ ___('Move up') }}"><x-ui.shell.nav-icon glyph="angle-small-up" /></button>
                        <button type="button" class="bio-brow__nudge-btn" data-move="down" aria-label="{{ ___('Move down') }}"><x-ui.shell.nav-icon glyph="angle-small-down" /></button>
                    </div>
                    <span class="bio-brow__icon" data-type="{{ $btype }}"><x-ui.shell.nav-icon :glyph="$typeIcons[$btype] ?? 'link'" /></span>
                    <div class="bio-brow__body">
                        <div class="bio-brow__label">{{ $blabel }}</div>
                        <div class="bio-brow__meta">
                            <span>{{ $typeLabels[$btype] ?? $btype }}</span>
                            @if($bscheduled)<span class="bio-brow__chip bio-brow__chip--info"><x-ui.shell.nav-icon glyph="clock" />{{ ___('Scheduled') }}</span>@endif
                            @if(! $bactive)<span class="bio-brow__chip"><x-ui.shell.nav-icon glyph="eye-crossed" />{{ ___('Hidden') }}</span>@endif
                            <span class="bio-brow__chip" title="{{ ___('Clicks') }}"><x-ui.shell.nav-icon glyph="cursor-finger" />{{ $bclicks }}</span>
                        </div>
                    </div>
                    <div class="bio-brow__actions">
                        <button type="button" class="bio-brow__act" data-edit-block aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"><x-ui.shell.nav-icon glyph="pencil" /></button>
                        <form action="{{ route('biolink.blocks.toggle', $bid) }}" method="post">
                            @csrf
                            <button type="submit" class="bio-brow__act" aria-label="{{ $bactive ? ___('Hide') : ___('Show') }}" title="{{ $bactive ? ___('Hide') : ___('Show') }}">
                                <x-ui.shell.nav-icon :glyph="$bactive ? 'eye' : 'eye-crossed'" />
                            </button>
                        </form>
                        <form action="{{ route('biolink.blocks.destroy', $bid) }}" method="post" data-confirm="{{ ___('Delete this block?') }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="bio-brow__act bio-brow__act--danger" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.shell.nav-icon glyph="trash" /></button>
                        </form>
                    </div>
                </div>
            @empty
                @if(! empty($starterCards))
                    @include('owner.biolink._chooser')
                    {{-- Revealed by “Start blank”: the plain empty state + add tray below. --}}
                    <div data-biolink-blank class="ui-hidden">
                        <x-ui.empty :title="___('No blocks yet')" :body="___('Add your first block from the tray below — a link, your menu, opening hours, WhatsApp and more.')" />
                    </div>
                @else
                    <x-ui.empty :title="___('No blocks yet')" :body="___('Add your first block from the tray below — a link, your menu, opening hours, WhatsApp and more.')" />
                @endif
            @endforelse
        </div>

        {{-- Add tray (bio-t15 C1: extracted to partials/_add_tray.blade.php) --}}
        @include('owner.biolink.partials._add_tray')
        </div>{{-- /data-builder-panel="blocks" --}}

        {{-- bio-t15 C2 · Appearance tab (partials/_appearance.blade.php) --}}
        <div data-builder-panel="appearance" class="ui-hidden">
            @include('owner.biolink.partials._appearance')
        </div>
    </div>

    {{-- RIGHT: live preview --------------------------------------------------- --}}
    <div>
        <x-ui.card :title="___('Live preview')">
            <x-slot:actions>
                <div class="ui-cluster ui-bio-devices" role="group" aria-label="{{ ___('Preview device') }}" data-biolink-devices>
                    <button type="button" class="ui-btn ui-btn--secondary active" data-device="mobile" aria-label="{{ ___('Mobile') }}"><x-ui.shell.nav-icon glyph="mobile-notch" /></button>
                    <button type="button" class="ui-btn ui-btn--secondary" data-device="desktop" aria-label="{{ ___('Desktop') }}"><x-ui.shell.nav-icon glyph="desktop" /></button>
                </div>
            </x-slot:actions>

            <div class="ui-center">
                <div data-preview-frame class="ui-inline ui-bg-inset" style="width:100%;max-width:360px;transition:max-width .2s;">
                    <iframe data-preview-iframe src="{{ $previewUrl }}" title="{{ ___('Bio page preview') }}"
                            style="width:100%;height:640px;border:0;border-radius:1rem;background:#fff;" loading="lazy"></iframe>
                </div>
                <div>
                    <button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" data-preview-reload><x-ui.shell.nav-icon glyph="refresh" />{{ ___('Refresh preview') }}</button>
                </div>
            </div>
        </x-ui.card>

        {{-- Design switcher: preview any of the 38 designs live on the page above,
             then apply. Applying changes the look only — blocks are never touched.
             Only shown once the page has blocks (an empty page is chosen from the
             starter chooser instead, which seeds the design's blocks). --}}
        @if(($blockCount ?? 0) > 0)
        @php
            $curPreset = $bioSettings['preset'] ?? 'classic_flat';
            $curPresetName = collect($presets ?? [])->firstWhere('slug', $curPreset)['name'] ?? ucwords(str_replace('_', ' ', $curPreset));
        @endphp
        <x-ui.card :title="___('Design')" data-biolink-design>
            <div class="ui-cluster ui-cluster--between">
                <div class="ui-small ui-truncate">
                    <span class="ui-text-2">{{ ___('Current design') }}:</span>
                    <span class="ui-strong" data-design-current>{{ $curPresetName }}</span>
                </div>
                <button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" data-design-toggle aria-expanded="false">
                    <x-ui.shell.nav-icon glyph="apps" />{{ ___('Change design') }}
                </button>
            </div>
            <div class="ui-hidden" data-design-panel>
                <p class="ui-muted">{{ ___('Tap a design to preview it on your page above, then apply. Applying changes the look only — your blocks stay.') }}</p>
                <div data-design-grid style="max-height:340px;overflow:auto;border:1px solid var(--bs-border-color);border-radius:.75rem;">
                    @include('bio.presets._gallery')
                </div>
                <div class="ui-hidden" data-design-apply-bar>
                    <button type="button" class="ui-btn ui-btn--sm" data-design-apply>{{ ___('Apply this design') }}</button>
                    <button type="button" class="ui-text-2 ui-btn ui-btn--link ui-btn--sm" data-design-cancel>{{ ___('Cancel') }}</button>
                    <span class="ui-muted" data-design-selected-name></span>
                </div>
            </div>
        </x-ui.card>
        @endif
    </div>
</div>

{{-- AI REVIEW PANEL (C2) — populated after "Generate with AI"; the owner reviews
     the proposed blocks and Applies or Discards. Rendered only when ai_biolink
     allows (SPEC T1-4): nothing here at all when the feature is off. --}}
@if($aiEnabled ?? false)
<div data-biolink-ai-review class="ui-card ui-card--accent ui-hidden">
    <div class="ui-card__body">
        <div class="ui-cluster ui-cluster--between">
            <h4 class="ui-section-title"><x-ui.shell.nav-icon glyph="sparkles" class="ui-text-accent" />{{ ___('AI draft') }}</h4>
            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-biolink-ai-discard aria-label="{{ ___('Discard') }}"><x-ui.icon name="x" size="18" /></button>
        </div>
        <p class="ui-muted" data-biolink-ai-summary></p>
        <div class="ui-hidden ui-text-danger ui-small" data-biolink-ai-error></div>
        <ul class="ui-list-box" data-biolink-ai-blocks></ul>
        <div class="ui-cluster ui-cluster--end">
            <button type="button" class="ui-btn ui-btn--secondary" data-biolink-ai-discard>{{ ___('Discard') }}</button>
            <x-ui.button type="button" icon="check" data-biolink-ai-apply>{{ ___('Apply this draft') }}</x-ui.button>
        </div>
    </div>
</div>
@endif

{{-- EDITOR PANEL (add / edit a block) ----------------------------------------- --}}
<div data-biolink-editor class="ui-card ui-hidden">
    <div class="ui-card__body">
        <div class="ui-cluster ui-cluster--between">
            <h6 data-editor-title>{{ ___('Block') }}</h6>
            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-editor-close aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
        </div>

        <form data-editor-form action="{{ route('biolink.blocks.store') }}" method="post" class="ui-form-grid">
            @csrf
            <input type="hidden" name="type" data-field="type" value="link">
            @if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif

            {{-- Editor form fields (bio-t15 C1: extracted to partials/_block_fields.blade.php) --}}
            @include('owner.biolink.partials._block_fields')

            {{-- Scheduling, pin & visibility (bio-t15 C1/C4: extracted to partials/_schedule_pin.blade.php) --}}
            @include('owner.biolink.partials._schedule_pin')

            <div class="ui-cluster ui-cluster--end ui-form-grid__full">
                <button type="button" class="ui-btn ui-btn--secondary" data-editor-close>{{ ___('Cancel') }}</button>
                <x-ui.button type="submit" icon="check">{{ ___('Save block') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    var list = document.querySelector('[data-biolink-list]');
    var editor = document.querySelector('[data-biolink-editor]');
    if (!editor) return;
    var form = editor.querySelector('[data-editor-form]');
    var storeUrl = {!! json_encode(route('biolink.blocks.store'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
    var updateBase = {!! json_encode(url('biolink/blocks'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
    var typeLabels = {!! json_encode($typeLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};

    // bio-t15 C3: the "Name | URL"-style pairs textarea is reused by five types
    // (delivery_apps/socials pre-existing; delivery/faq/gallery new — SPEC A5/A9/A8).
    // Each has its own label + placeholder/help copy even though the field/name
    // stays `config_pairs` (server-side parsePairs decides the target config key).
    var PAIRS_META = {
        delivery_apps: { label: {!! json_encode(___('Delivery apps'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, help: {!! json_encode(___('One per line, format: Name | https://link'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, ph: {!! json_encode(___('One per line — Name | https://link'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!} },
        socials:       { label: {!! json_encode(___('Social links'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!},  help: {!! json_encode(___('One per line, format: Name | https://link'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, ph: {!! json_encode(___('One per line — Name | https://link'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!} },
        delivery:      { label: {!! json_encode(___('Delivery apps'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, help: {!! json_encode(___('One per line, format: Name | https://link'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, ph: {!! json_encode(___('One per line — Name | https://link'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!} },
        faq:           { label: {!! json_encode(___('Questions & answers'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, help: {!! json_encode(___('One per line, format: Question | Answer'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, ph: {!! json_encode(___('One per line — Question | Answer'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!} },
        gallery:       { label: {!! json_encode(___('Photos'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, help: {!! json_encode(___('One per line, format: Caption | https://image-url'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}, ph: {!! json_encode(___('One per line — Caption | https://image-url'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!} }
    };

    function showGroupsFor(type) {
        form.querySelectorAll('[data-group]').forEach(function (g) {
            var types = g.getAttribute('data-types');
            g.classList.toggle('ui-hidden', !!types && types.split(' ').indexOf(type) === -1);
        });
        var pairsLabel = form.querySelector('[data-pairs-label]');
        var pairsHelp = form.querySelector('[data-pairs-help]');
        var pairsField = form.querySelector('[data-field="config_pairs"]');
        var meta = PAIRS_META[type];
        if (meta) {
            if (pairsLabel) pairsLabel.textContent = meta.label;
            if (pairsHelp) pairsHelp.textContent = meta.help;
            if (pairsField) pairsField.setAttribute('placeholder', meta.ph);
        }
    }

    function resetForm() {
        form.reset();
        form.querySelectorAll('[data-field]').forEach(function (el) {
            if (el.type === 'checkbox') { el.checked = (el.getAttribute('data-field') === 'active' || el.hasAttribute('data-default-checked')); }
            else if (el.name !== 'type') { el.value = ''; }
        });
        form.querySelectorAll('[data-i18n-field]').forEach(function (el) { el.value = ''; });
        clearHoursHidden();
    }

    // bio-t15 A1 hours: turn a day's free-text ranges ("09:00-17:00, 18:00-22:00")
    // into the frozen render contract `config.week.<day> = [["09:00","17:00"], …]`
    // as hidden bracket-notation inputs, injected right before the native form
    // submit (no fetch/preventDefault needed — the browser serialises whatever
    // inputs exist in the DOM at submit time).
    var HOURS_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    function clearHoursHidden() {
        form.querySelectorAll('[data-hours-hidden]').forEach(function (el) { el.remove(); });
    }
    function buildHoursHidden() {
        clearHoursHidden();
        if ((form.querySelector('[data-field="type"]') || {}).value !== 'hours') return;
        HOURS_DAYS.forEach(function (day) {
            var box = form.querySelector('[data-hours-day="' + day + '"]');
            var raw = box ? box.value.trim() : '';
            if (!raw) return;
            raw.split(',').forEach(function (chunk, i) {
                var parts = chunk.split('-');
                var start = (parts[0] || '').trim();
                var end = (parts[1] || '').trim();
                if (!start && !end) return;
                [start, end].forEach(function (val, j) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.setAttribute('data-hours-hidden', '1');
                    input.name = 'config[week][' + day + '][' + i + '][' + j + ']';
                    input.value = val;
                    form.appendChild(input);
                });
            });
        });
    }
    function fmtHoursRanges(ranges) {
        if (!Array.isArray(ranges)) return '';
        return ranges.map(function (r) { return (r[0] || '') + '-' + (r[1] || ''); }).join(', ');
    }
    function populateHoursWeek(cfg) {
        var week = cfg.week || {};
        HOURS_DAYS.forEach(function (day) {
            setField('config.week_text.' + day, fmtHoursRanges(week[day]));
        });
    }
    form.addEventListener('submit', buildHoursHidden);

    function populateI18n(block) {
        var i18n = (block && block.config && block.config._i18n) ? block.config._i18n : {};
        form.querySelectorAll('[data-i18n-field]').forEach(function (el) {
            var loc = el.getAttribute('data-i18n-loc');
            var key = el.getAttribute('data-i18n-key');
            el.value = (i18n[loc] && i18n[loc][key] != null) ? i18n[loc][key] : '';
        });
    }

    /* Translations: a locale tab bar swaps which single locale panel is visible,
       and a dot marks tabs that already carry an override. */
    var i18nTabs = editor.querySelector('[data-i18n-tabs]');
    function showI18nTab(loc) {
        if (!i18nTabs) return;
        i18nTabs.querySelectorAll('[data-i18n-tab]').forEach(function (b) {
            var on = b.getAttribute('data-i18n-tab') === loc;
            b.classList.toggle('ui-btn--secondary', !on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        editor.querySelectorAll('[data-i18n-panel]').forEach(function (p) {
            p.classList.toggle('ui-hidden', p.getAttribute('data-i18n-panel') !== loc);
        });
    }
    function updateI18nDots() {
        editor.querySelectorAll('[data-i18n-panel]').forEach(function (p) {
            var loc = p.getAttribute('data-i18n-panel');
            var has = Array.prototype.some.call(p.querySelectorAll('[data-i18n-field]'), function (el) { return el.value.trim() !== ''; });
            var dot = editor.querySelector('[data-i18n-dot="' + loc + '"]');
            if (dot) dot.classList.toggle('ui-hidden', !has);
        });
    }
    function resetI18nTab() {
        var b = i18nTabs && i18nTabs.querySelector('[data-i18n-tab]');
        if (b) showI18nTab(b.getAttribute('data-i18n-tab'));
        updateI18nDots();
    }
    if (i18nTabs) {
        i18nTabs.addEventListener('click', function (e) {
            var b = e.target.closest('[data-i18n-tab]');
            if (b) showI18nTab(b.getAttribute('data-i18n-tab'));
        });
        editor.querySelectorAll('[data-i18n-field]').forEach(function (el) {
            el.addEventListener('input', updateI18nDots);
        });
    }

    function openEditor(type, block) {
        resetForm();
        form.setAttribute('action', storeUrl);
        var title = editor.querySelector('[data-editor-title]');
        setField('type', type);

        if (block) {
            form.setAttribute('action', updateBase + '/' + block.id);
            setField('label', block.label || '');
            setField('url', block.url || '');
            var cfg = block.config || {};
            [
                'item_id', 'price_label', 'title', 'subtitle', 'schedule_label', 'text', 'address', 'phone', 'media', 'consent_text', 'image',
                // bio-t15 A1–A11 simple (non-nested) leaf keys:
                'tz', 'source', 'note', 'platform', 'rating', 'count', 'sms', 'whatsapp', 'party_note',
                'mode', 'provider', 'ratio', 'date', 'location', 'currency', 'highlight',
            ].forEach(function (k) {
                setField('config.' + k, cfg[k] != null ? cfg[k] : '');
            });
            ['show_status', 'show_stars', 'capture', 'pinned'].forEach(function (k) {
                setCheckbox('config.' + k, !!cfg[k]);
            });
            var show = cfg.show || {};
            ['call', 'whatsapp', 'sms'].forEach(function (k) {
                setCheckbox('config.show.' + k, show[k] !== false);
            });
            // bio-t15 A12: recurring schedule window (nested under cfg.schedule).
            var sched = cfg.schedule || {};
            setField('config.schedule.start_at', sched.start_at || '');
            setField('config.schedule.end_at', sched.end_at || '');
            var schedDays = Array.isArray(sched.days) ? sched.days.map(String) : [];
            [0, 1, 2, 3, 4, 5, 6].forEach(function (d) {
                setCheckbox('config.schedule.days.' + d, schedDays.indexOf(String(d)) !== -1);
            });
            var amounts = Array.isArray(cfg.amounts) ? cfg.amounts : [];
            [0, 1, 2, 3].forEach(function (i) {
                setField('config.amounts.' + i, amounts[i] != null ? amounts[i] : '');
            });
            populateHoursWeek(cfg);

            // bio-t15 A5/A8/A9: delivery→apps, gallery→images, faq→items — same
            // "Name | URL"-idiom textarea as delivery_apps/socials (PAIRS_META).
            var listKeyMap = { delivery_apps: 'apps', socials: 'socials', delivery: 'apps', gallery: 'images', faq: 'items' };
            var listKey = listKeyMap[type] || null;
            if (listKey && Array.isArray(cfg[listKey])) {
                setField('config_pairs', cfg[listKey].map(function (r) {
                    return listKey === 'items' ? ((r.q || '') + ' | ' + (r.a || '')) : ((r.name || '') + ' | ' + (r.url || ''));
                }).join('\n'));
            }
            setCheckbox('active', block.active !== false);
            setField('visible_from', toLocal(block.visible_from));
            setField('visible_until', toLocal(block.visible_until));
            populateI18n(block);
            if (title) title.textContent = {!! json_encode(___('Edit block'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!} + ' — ' + (typeLabels[type] || type);
        } else {
            if (title) title.textContent = {!! json_encode(___('Add block'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!} + ' — ' + (typeLabels[type] || type);
        }

        showGroupsFor(type);
        resetI18nTab();
        editor.classList.remove('ui-hidden');
        editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function setField(name, value) {
        var el = form.querySelector('[data-field="' + name + '"]');
        if (el && el.type !== 'checkbox') el.value = (value == null ? '' : value);
    }
    function setCheckbox(name, on) {
        var el = form.querySelector('[data-field="' + name + '"]');
        if (el && el.type === 'checkbox') el.checked = !!on;
    }
    function toLocal(v) {
        if (!v) return '';
        var s = String(v).replace(' ', 'T');
        return s.length >= 16 ? s.slice(0, 16) : s;
    }

    document.querySelectorAll('[data-add-block]').forEach(function (btn) {
        btn.addEventListener('click', function () { openEditor(btn.getAttribute('data-add-block'), null); });
    });

    /* bio-t15 C6 · Add-tray search filter — 28 possible types stay scannable.
       Client-side only; hides non-matching chips and, when a whole group has
       no match, the group header too. */
    var addtrayFilter = document.querySelector('[data-addtray-filter]');
    var addtray = document.querySelector('[data-addtray]');
    if (addtrayFilter && addtray) {
        addtrayFilter.addEventListener('input', function () {
            var q = addtrayFilter.value.trim().toLowerCase();
            var anyVisible = false;
            addtray.querySelectorAll('[data-addtray-group]').forEach(function (group) {
                var groupHasMatch = false;
                group.querySelectorAll('[data-addtray-chip]').forEach(function (chip) {
                    var match = !q || (chip.getAttribute('data-chip-label') || '').indexOf(q) !== -1;
                    chip.classList.toggle('ui-hidden', !match);
                    if (match) { groupHasMatch = true; anyVisible = true; }
                });
                group.classList.toggle('ui-hidden', !groupHasMatch);
            });
            var empty = addtray.querySelector('[data-addtray-empty]');
            if (empty) empty.classList.toggle('ui-hidden', anyVisible || !q);
        });
    }
    document.querySelectorAll('[data-edit-block]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('.bio-block-row');
            var data = row ? row.getAttribute('data-block') : null;
            var block = null;
            try { block = data ? JSON.parse(data) : null; } catch (e) {}
            if (block) openEditor(block.type || 'link', block);
        });
    });
    editor.querySelectorAll('[data-editor-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { editor.classList.add('ui-hidden'); });
    });

    /* Reorder via up/down — moves the row then POSTs the full id order. */
    if (list) {
        list.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-move]');
            if (!btn) return;
            var row = btn.closest('.bio-block-row');
            if (!row) return;
            if (btn.getAttribute('data-move') === 'up' && row.previousElementSibling) {
                row.parentNode.insertBefore(row, row.previousElementSibling);
            } else if (btn.getAttribute('data-move') === 'down' && row.nextElementSibling) {
                row.parentNode.insertBefore(row.nextElementSibling, row);
            }
            persistOrder();
        });

        /* Reorder via the grip handle — native drag-and-drop, then POST the order. */
        var dragRow = null;
        list.addEventListener('mousedown', function (e) {
            var h = e.target.closest('[data-drag-handle]');
            var row = h && h.closest('.bio-block-row');
            if (row) row.setAttribute('draggable', 'true');
        });
        list.addEventListener('dragstart', function (e) {
            var row = e.target.closest('.bio-block-row');
            if (!row || row.getAttribute('draggable') !== 'true') { e.preventDefault(); return; }
            dragRow = row;
            row.classList.add('bio-brow--dragging');
            try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); } catch (x) {}
        });
        list.addEventListener('dragover', function (e) {
            if (!dragRow) return;
            e.preventDefault();
            var over = e.target.closest('.bio-block-row');
            if (!over || over === dragRow) return;
            var r = over.getBoundingClientRect();
            var after = e.clientY > r.top + r.height / 2;
            list.querySelectorAll('.bio-brow--over').forEach(function (n) { n.classList.remove('bio-brow--over'); });
            over.classList.add('bio-brow--over');
            list.insertBefore(dragRow, after ? over.nextElementSibling : over);
        });
        function endDrag() {
            if (!dragRow) return;
            dragRow.classList.remove('bio-brow--dragging');
            dragRow.removeAttribute('draggable');
            list.querySelectorAll('.bio-brow--over').forEach(function (n) { n.classList.remove('bio-brow--over'); });
            dragRow = null;
            persistOrder();
        }
        list.addEventListener('drop', function (e) { e.preventDefault(); endDrag(); });
        list.addEventListener('dragend', endDrag);
    }
    function persistOrder() {
        var ids = Array.prototype.map.call(list.querySelectorAll('.bio-block-row'), function (r) {
            return r.getAttribute('data-block-id');
        });
        var body = new FormData();
        body.append('_token', list.getAttribute('data-csrf'));
        ids.forEach(function (id) { body.append('order[]', id); });
        fetch(list.getAttribute('data-reorder-url'), {
            method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
        });
    }

    /* Preview device toggle + reload */
    var devices = document.querySelector('[data-biolink-devices]');
    var frameWrap = document.querySelector('[data-preview-frame]');
    if (devices && frameWrap) {
        devices.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-device]');
            if (!btn) return;
            devices.querySelectorAll('[data-device]').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            frameWrap.style.maxWidth = btn.getAttribute('data-device') === 'desktop' ? '100%' : '360px';
        });
    }
    var reload = document.querySelector('[data-preview-reload]');
    var iframe = document.querySelector('[data-preview-iframe]');
    if (reload && iframe) {
        reload.addEventListener('click', function () { iframe.src = iframe.src; });
    }

    /* ── C1 empty-state chooser ──────────────────────────────────────────── */
    var chooser = document.querySelector('[data-biolink-chooser]');
    if (chooser) {
        var allowedPresets = {!! json_encode(collect($presets ?? [])->where('allowed', true)->pluck('slug')->values(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        var settingsUrl = {!! json_encode(route('biolink.index'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};

        // "Start blank" → dismiss the chooser and reveal the plain empty state.
        var startBlank = chooser.querySelector('[data-biolink-start-blank]');
        var blank = document.querySelector('[data-biolink-blank]');
        if (startBlank) {
            startBlank.addEventListener('click', function () {
                chooser.classList.add('ui-hidden');
                if (blank) blank.classList.remove('ui-hidden');
            });
        }

        // Browse-all expander.
        var browseToggle = chooser.querySelector('[data-biolink-browse-toggle]');
        var browse = chooser.querySelector('[data-biolink-browse]');
        if (browseToggle && browse) {
            browseToggle.addEventListener('click', function () {
                var open = browse.classList.toggle('ui-hidden') === false;
                browseToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        // Apply a design from the browse grid: allowed → submit the shared apply
        // form for that preset; locked → open the settings page (never a dead tap).
        var browseGrid = chooser.querySelector('[data-biolink-browse-grid]');
        var browseForm = chooser.querySelector('[data-biolink-browse-form]');
        if (browseGrid && browseForm) {
            browseGrid.addEventListener('click', function (e) {
                var thumb = e.target.closest('[data-preset]');
                if (!thumb) return;
                var slug = thumb.getAttribute('data-preset');
                if (allowedPresets.indexOf(slug) === -1) { window.location = settingsUrl; return; }
                var field = browseForm.querySelector('[data-browse-preset]');
                if (field) field.value = slug;
                browseForm.submit();
            });
        }
    }

    /* ── C2 AI generate → review → apply ─────────────────────────────────── */
    var aiReview = document.querySelector('[data-biolink-ai-review]');
    var aiGenBtn = document.querySelector('[data-biolink-ai-generate]');
    if (aiReview && aiGenBtn) {
        var aiGenerateUrl = {!! json_encode($aiGenerateUrl ?? null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        var aiApplyUrl = {!! json_encode($aiApplyUrl ?? null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        var token = (document.querySelector('input[name="_token"]') || {}).value || '';
        var typeLabelsAi = {!! json_encode($typeLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        var spinner = aiGenBtn.querySelector('[data-biolink-ai-spinner]');
        var proposal = null;

        function aiPost(url) {
            return fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                credentials: 'same-origin',
                body: JSON.stringify({})
            });
        }

        function aiError(msg) {
            var box = aiReview.querySelector('[data-biolink-ai-error]');
            if (box) { box.textContent = msg; box.classList.remove('ui-hidden'); }
            aiReview.classList.remove('ui-hidden');
        }

        function renderProposal(data) {
            proposal = data || {};
            var summary = aiReview.querySelector('[data-biolink-ai-summary]');
            var list = aiReview.querySelector('[data-biolink-ai-blocks]');
            var errBox = aiReview.querySelector('[data-biolink-ai-error]');
            if (errBox) errBox.classList.add('ui-hidden');
            if (summary) {
                var parts = [];
                if (proposal.archetype) parts.push(proposal.archetype);
                if (proposal.preset_suggestion) parts.push(proposal.preset_suggestion);
                summary.textContent = parts.join(' · ');
            }
            if (list) {
                list.innerHTML = '';
                (proposal.blocks || []).forEach(function (b) {
                    var li = document.createElement('li');
                    li.className = 'list-group-item d-flex align-items-center gap-2';
                    var badge = document.createElement('span');
                    badge.className = 'badge bg-secondary-subtle text-secondary';
                    badge.textContent = typeLabelsAi[b.type] || b.type || '';
                    var label = document.createElement('span');
                    label.className = 'text-truncate';
                    label.textContent = b.label || (b.config && (b.config.title || b.config.text)) || '';
                    li.appendChild(badge);
                    li.appendChild(label);
                    list.appendChild(li);
                });
            }
            aiReview.classList.remove('ui-hidden');
            aiReview.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        aiGenBtn.addEventListener('click', function () {
            if (!aiGenerateUrl) { aiError({!! json_encode(___('AI drafting is not available yet.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}); return; }
            if (spinner) spinner.classList.remove('ui-hidden');
            aiGenBtn.setAttribute('disabled', 'disabled');
            aiPost(aiGenerateUrl).then(function (r) { return r.json(); }).then(function (data) {
                if (data && (data.blocks || data.proposal)) { renderProposal(data.proposal || data); }
                else { aiError((data && data.message) || {!! json_encode(___('Could not draft a page. Please try again.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}); }
            }).catch(function () {
                aiError({!! json_encode(___('Could not draft a page. Please try again.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!});
            }).finally(function () {
                if (spinner) spinner.classList.add('ui-hidden');
                aiGenBtn.removeAttribute('disabled');
            });
        });

        var applyBtn = aiReview.querySelector('[data-biolink-ai-apply]');
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                if (!aiApplyUrl || !proposal) { aiError({!! json_encode(___('AI drafting is not available yet.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}); return; }
                applyBtn.setAttribute('disabled', 'disabled');
                fetch(aiApplyUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    credentials: 'same-origin',
                    body: JSON.stringify({ proposal: proposal })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.success) { window.location = {!! json_encode(route('biolink.builder'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}; }
                    else { aiError((data && data.message) || {!! json_encode(___('Could not apply the draft.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}); applyBtn.removeAttribute('disabled'); }
                }).catch(function () {
                    aiError({!! json_encode(___('Could not apply the draft.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}); applyBtn.removeAttribute('disabled');
                });
            });
        }

        aiReview.querySelectorAll('[data-biolink-ai-discard]').forEach(function (btn) {
            btn.addEventListener('click', function () { proposal = null; aiReview.classList.add('ui-hidden'); });
        });
    }

    /* ── C4 translate page → progress polling ────────────────────────────── */
    var translate = document.querySelector('[data-biolink-translate]');
    if (translate) {
        var trStart = translate.querySelector('[data-biolink-translate-start]');
        var trProgress = translate.querySelector('[data-biolink-translate-progress]');
        var trBar = translate.querySelector('[data-biolink-translate-bar]');
        var trNote = translate.querySelector('[data-biolink-translate-note]');
        var trUrl = translate.getAttribute('data-translate-url') || '';
        var trStatusBase = translate.getAttribute('data-translate-status') || '';
        var trToken = (document.querySelector('input[name="_token"]') || {}).value || '';
        var trPoll = null;

        function trSetNote(msg) { if (trNote) { trNote.textContent = msg; trNote.classList.remove('ui-hidden'); } }
        function trSetBar(pct) {
            if (trProgress) trProgress.classList.remove('ui-hidden');
            if (trBar) { trBar.style.width = Math.max(0, Math.min(100, pct)) + '%'; trBar.setAttribute('aria-valuenow', String(pct)); }
        }

        // Lane B's progress endpoint is store-keyed (no path param) and returns
        // { success, progress: { status:'running'|'done'|'error'|'idle', done, total } }.
        function trPollStatus() {
            if (!trStatusBase) return;
            trPoll = setInterval(function () {
                fetch(trStatusBase, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (s) {
                    var p = (s && s.progress) || {};
                    var total = +p.total || 0, done = +p.done || 0;
                    var pct = p.status === 'done' ? 100 : (total > 0 ? Math.round(done / total * 100) : 5);
                    trSetBar(pct);
                    if (p.status === 'done') {
                        clearInterval(trPoll);
                        trSetNote({!! json_encode(___('Done'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!});
                        setTimeout(function () { window.location = {!! json_encode(route('biolink.builder'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}; }, 600);
                    } else if (p.status === 'error') {
                        clearInterval(trPoll);
                        if (trStart) trStart.removeAttribute('disabled');
                        trSetNote(p.message || {!! json_encode(___('Could not translate. Please try again.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!});
                    }
                }).catch(function () { clearInterval(trPoll); });
            }, 1500);
        }

        if (trStart) {
            trStart.addEventListener('click', function () {
                if (!trUrl) { trSetNote({!! json_encode(___('Translation is not available yet.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}); return; }
                trStart.setAttribute('disabled', 'disabled');
                trSetBar(2);
                fetch(trUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': trToken },
                    credentials: 'same-origin', body: JSON.stringify({})
                }).then(function (r) { return r.json(); }).then(function (data) {
                    // Success = the background job was dispatched; poll the store-keyed
                    // progress endpoint until it reports done/error.
                    if (data && data.success) { trSetNote({!! json_encode(___('Translating…'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}); trPollStatus(); }
                    else { trStart.removeAttribute('disabled'); trSetNote((data && data.message) || {!! json_encode(___('Could not start translation.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}); }
                }).catch(function () {
                    trStart.removeAttribute('disabled'); trSetNote({!! json_encode(___('Could not start translation.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!});
                });
            });
        }
    }

    /* ── Design switcher: live-preview any design, apply skin only ─────────── */
    var design = document.querySelector('[data-biolink-design]');
    if (design) {
        var dToggle = design.querySelector('[data-design-toggle]');
        var dPanel = design.querySelector('[data-design-panel]');
        var dGrid = design.querySelector('[data-design-grid]');
        var dApplyBar = design.querySelector('[data-design-apply-bar]');
        var dApply = design.querySelector('[data-design-apply]');
        var dCancel = design.querySelector('[data-design-cancel]');
        var dSelName = design.querySelector('[data-design-selected-name]');
        var dIframe = document.querySelector('[data-preview-iframe]');
        var dBaseSrc = dIframe ? dIframe.getAttribute('src') : '';
        var dAllowed = {!! json_encode(collect($presets ?? [])->where('allowed', true)->pluck('slug')->values(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        var dSettingsUrl = {!! json_encode(route('biolink.index'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        var dSetUrl = {!! json_encode($presetSetUrl ?? null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        var dToken = (document.querySelector('input[name="_token"]') || {}).value || '';
        var dPicked = null;

        function dShowApplyBar(on) { if (dApplyBar) { dApplyBar.classList.toggle('ui-hidden', !on); dApplyBar.classList.toggle('ui-cluster', on); } }
        function dPreview(slug) {
            if (!dIframe) return;
            var sep = dBaseSrc.indexOf('?') === -1 ? '?' : '&';
            dIframe.src = dBaseSrc + sep + 'preview_preset=' + encodeURIComponent(slug);
        }
        function dReset() { dPicked = null; if (dIframe) dIframe.src = dBaseSrc; dShowApplyBar(false); }

        if (dToggle && dPanel) {
            dToggle.addEventListener('click', function () {
                var open = dPanel.classList.toggle('ui-hidden') === false;
                dToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (!open) dReset();
            });
        }
        if (dGrid) {
            dGrid.addEventListener('click', function (e) {
                var thumb = e.target.closest('[data-preset]');
                if (!thumb) return;
                var slug = thumb.getAttribute('data-preset');
                if (dAllowed.indexOf(slug) === -1) { window.location = dSettingsUrl; return; } // locked → upsell
                dPicked = slug;
                dPreview(slug);
                if (dSelName) dSelName.textContent = thumb.getAttribute('aria-label') || slug;
                dShowApplyBar(true);
            });
        }
        if (dCancel) dCancel.addEventListener('click', dReset);
        if (dApply) {
            dApply.addEventListener('click', function () {
                if (!dPicked || !dSetUrl) return;
                dApply.setAttribute('disabled', 'disabled');
                fetch(dSetUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': dToken },
                    credentials: 'same-origin', body: JSON.stringify({ preset: dPicked })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && (data.success || data.preset)) { window.location.reload(); }
                    else { dApply.removeAttribute('disabled'); }
                }).catch(function () { dApply.removeAttribute('disabled'); });
            });
        }
    }

    /* ── bio-t15 C2 · Blocks / Appearance tab switcher ──────────────────────
       The Appearance tab is a plain <form> that posts to settings.save and
       reloads the page (no fetch — see the partial's own header comment for
       why). sessionStorage remembers which tab was showing across that
       reload; it's per-viewer/per-browser only, never sent anywhere. */
    var TAB_KEY = 'bio-builder-tab';
    var tabsBar = document.querySelector('[data-builder-tabs]');
    var tabPanels = document.querySelectorAll('[data-builder-panel]');
    function showBuilderTab(name) {
        if (!tabsBar) return;
        tabsBar.querySelectorAll('[data-builder-tab-btn]').forEach(function (b) {
            var on = b.getAttribute('data-builder-tab-btn') === name;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) { b.setAttribute('aria-current', 'true'); } else { b.removeAttribute('aria-current'); }
        });
        tabPanels.forEach(function (p) {
            p.classList.toggle('ui-hidden', p.getAttribute('data-builder-panel') !== name);
        });
    }
    if (tabsBar) {
        tabsBar.addEventListener('click', function (e) {
            var b = e.target.closest('[data-builder-tab-btn]');
            if (!b) return;
            var name = b.getAttribute('data-builder-tab-btn');
            showBuilderTab(name);
            try { sessionStorage.setItem(TAB_KEY, name); } catch (err) {}
        });
        var appearanceForm = document.querySelector('[data-appearance-form]');
        if (appearanceForm) {
            appearanceForm.addEventListener('submit', function () {
                try { sessionStorage.setItem(TAB_KEY, 'appearance'); } catch (err) {}
            });
        }
        var savedTab = null;
        try { savedTab = sessionStorage.getItem(TAB_KEY); } catch (err) {}
        if (savedTab === 'appearance') { showBuilderTab('appearance'); }
    }

    /* ── bio-t15 C2 · Background type switcher (solid/gradient/pattern/image) ── */
    var bgSelect = document.querySelector('[data-bg-type-select]');
    function showBgFieldsFor(type) {
        document.querySelectorAll('[data-bg-group]').forEach(function (g) {
            var types = (g.getAttribute('data-bg-types') || '').split(' ');
            g.classList.toggle('ui-hidden', types.indexOf(type) === -1);
        });
    }
    if (bgSelect) {
        showBgFieldsFor(bgSelect.value);
        bgSelect.addEventListener('change', function () { showBgFieldsFor(bgSelect.value); });
    }

    /* ── bio-t15 C5 · Live-preview extend: pending (unsaved) appearance edits ──
       Reuses the SAME preview iframe the design switcher's `?preview_preset=`
       already drives, adding a sibling `?preview_appearance=<json>` param so a
       tweak is visible before "Save appearance" is even clicked. This is a NEW,
       additive query param — BioPublicController doesn't read it yet (Lane B),
       so today it's a harmless no-op; once B merges the token-merge (SPEC §3
       B2), the preview starts reflecting it with no change needed here. Nothing
       is ever persisted from this — only the saved copy (via the form POST)
       reaches bio_settings.appearance. */
    var apForm = document.querySelector('[data-appearance-form]');
    var apIframe = document.querySelector('[data-preview-iframe]');
    if (apForm && apIframe) {
        var apBaseSrc = apIframe.getAttribute('src');
        var apTimer = null;

        function collectAppearance() {
            var out = {};
            apForm.querySelectorAll('[data-appearance-field]').forEach(function (el) {
                if (el.type === 'radio' && !el.checked) return;
                var val = el.type === 'checkbox' ? el.checked : el.value;
                var path = el.getAttribute('data-appearance-field').split('.');
                var node = out;
                for (var i = 0; i < path.length - 1; i++) {
                    node[path[i]] = node[path[i]] || {};
                    node = node[path[i]];
                }
                node[path[path.length - 1]] = val;
            });
            return out;
        }

        function scheduleAppearancePreview() {
            clearTimeout(apTimer);
            apTimer = setTimeout(function () {
                var sep = apBaseSrc.indexOf('?') === -1 ? '?' : '&';
                apIframe.src = apBaseSrc + sep + 'preview_appearance=' + encodeURIComponent(JSON.stringify(collectAppearance()));
            }, 400);
        }

        apForm.addEventListener('input', scheduleAppearancePreview);
        apForm.addEventListener('change', scheduleAppearancePreview);
    }
})();
</script>

@endsection
