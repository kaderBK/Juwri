{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Add New Store'))
@section('page_title', ___('Create store'))
@section('page_sub', ___('Set up the public menu profile, choose a template, and publish your first QR menu.'))

@section('page_actions')
    @if(Route::has('stores.index'))
        <x-ui.button :href="route('stores.index')" variant="secondary" data-web-only>
            <span class="ui-btn__icon"><x-ui.icon name="back" :flip="true" /></span>
            {{ ___('Stores') }}
        </x-ui.button>
    @endif
@endsection

@section('content')
{{-- F7-4: warn BEFORE the hard "Limit exceeded" error on submit. --}}
@include('owner.dashboard.partials.near-limit-banner', ['keys' => ['restaurant']])
{{-- Pricing build (PRICING.md §3): tell the owner at sign-up when the plan does not include e-invoicing (billing edition). --}}
{{ \Juwri\Core\Support\Hooks::first('owner.plan.einvoicing-notice', auth()->user()) }}
@include('owner.partials.store-limit-notice')
@php
    $defaultColor = old('color', $settings->theme_color ?? ('#'.'10b981'));
    // Preselect the admin-configured default template for new stores; the
    // service falls back to the first available template when none is set.
    $selectedTemplate = old('store_template', app(\App\Services\StorefrontThemeService::class)->defaultSlug());

    // rev/multi-vertical F0-5: store-type picker metadata. Drives which catalog,
    // item fields, and checkout the store gets (VerticalRegistry). Defined up
    // here (not inside the Step-1 section) because the x-data config below reads
    // the labels immediately for the live-preview eyebrow.
    $verticalTypes = (array) config('verticals.types', []);
    $selectedType = old('store_type', 'restaurant');
    $verticalHints = [
        'restaurant' => ___('Menu, dishes, dine-in / delivery.'),
        'retail' => ___('General products, ship or pickup.'),
        'cosmetic' => ___('Beauty products with brands & shades.'),
        'clothing' => ___('Apparel with size & colour variants.'),
        'carparts' => ___('Parts with vehicle fitment search.'),
        'laundry' => ___('Per-item / per-kg services with pickup.'),
        'pharmacy' => ___('Over-the-counter products (no prescriptions).'),
        'hotel' => ___('Rooms & bookings with charge-to-room.'),
        'cafe' => ___('Coffee, drinks and bakes, order ahead.'),
        'grocery' => ___('Groceries by weight or pack, delivery or pickup slot.'),
        'services' => ___('Appointments in store or at home.'),
    ];
    // Owner UI kit icon per vertical (only names that exist in x-ui.icon).
    $verticalIcons = [
        'restaurant' => 'dine-in', 'retail' => 'store',
        'cosmetic' => 'sparkle', 'clothing' => 'products',
        'carparts' => 'settings', 'laundry' => 'refresh', 'pharmacy' => 'plus',
        'hotel' => 'bed',
        'cafe' => 'flame', 'grocery' => 'delivery', 'services' => 'calendar',
    ];
    // Full, pre-translated per-vertical labels for the live-preview eyebrow and
    // the Alpine confirm/heading maps — never glued together client-side.
    $verticalLabels = \Juwri\Core\Support\Views\StoreViewHelpers::verticalLabels($verticalTypes);

    // rev/multi-vertical: step-2/3 copy is food-flavoured ("Menu template",
    // "menu items, QR tables") by default — these two maps give every vertical
    // its own full, properly translated phrasing (never a client-side
    // concatenation of translated fragments) so the wizard reads right no
    // matter which store type is picked in step 1.
    $verticalTemplateHeadings = [
        'restaurant' => ___('Menu template'),
        'retail' => ___('Product template'),
        'cosmetic' => ___('Product template'),
        'clothing' => ___('Product template'),
        'carparts' => ___('Product template'),
        'laundry' => ___('Service template'),
        'pharmacy' => ___('Product template'),
        'cafe' => ___('Menu template'),
        'grocery' => ___('Product template'),
        'services' => ___('Service template'),
    ];
    $verticalConfirmNotes = [
        'restaurant' => ___('After creation, you can add menu items, QR tables, branches, and ordering settings.'),
        'retail' => ___('After creation, you can add products, branches, and ordering settings.'),
        'cosmetic' => ___('After creation, you can add products, branches, and ordering settings.'),
        'clothing' => ___('After creation, you can add products, branches, and ordering settings.'),
        'carparts' => ___('After creation, you can add products, branches, and ordering settings.'),
        'laundry' => ___('After creation, you can add services, branches, and booking settings.'),
        'pharmacy' => ___('After creation, you can add products, branches, and ordering settings.'),
        'cafe' => ___('After creation, you can add menu items, QR tables, branches, and ordering settings.'),
        'grocery' => ___('After creation, you can add products, branches, and ordering settings.'),
        'services' => ___('After creation, you can add services, branches, and booking settings.'),
    ];

    $wizardSteps = [
        ['key' => 'basics',   'label' => ___('Basics'),   'desc' => ___('Store details')],
        ['key' => 'template', 'label' => ___('Template'), 'desc' => ___('Look & images')],
        ['key' => 'confirm',  'label' => ___('Confirm'),  'desc' => ___('Review & create')],
    ];
@endphp

{{-- Owner UI kit wizard. --create-brand follows the brand-color field live
     (a data-driven value, so it stays an inline style) and tints the preview
     and selection rings; everything else reads the kit tokens. --}}
<form
    method="post"
    action="{{ route('stores.store') }}"
    enctype="multipart/form-data"
    class="ui-scope ui-create"
    x-bind:style="'--create-brand:' + color"
    novalidate
    @submit="onSubmit($event)"
    @pageshow.window="submitting = false"
    x-data="createStorePage({
        {{-- After a server-side error, reopen at the step holding the first failing field. --}}
        step: @js(session('create_step', 'basics')),
        selectedTemplate: @js($selectedTemplate),
        templateNames: @js(\Juwri\Core\Support\Views\StoreViewHelpers::templateNamesMap($templates)),
        // rev/multi-vertical Lane C (C4): client-side template-grid filtering by
        // vertical — server-computed maps, no extra request.
        storeType: @js(old('store_type', 'restaurant')),
        templateSets: @js($templateSets ?? []),
        verticalThemeSets: @js($verticalThemeSets ?? []),
        // rev/multi-vertical: full, pre-translated per-vertical phrases (see the
        // php block above) — never built by gluing translated words together client-side.
        templateHeadings: @js($verticalTemplateHeadings),
        confirmNotes: @js($verticalConfirmNotes),
        typeLabels: @js($verticalLabels)
    })"
>
    @csrf
    <input type="hidden" name="store_template" value="{{ $selectedTemplate }}" x-model="selectedTemplate">

    {{-- Progress rail. Steps are clickable; a completed step shows a check,
         the current one an accent ring. --}}
    <ol class="ui-create__steps" role="tablist" aria-label="{{ ___('Create store steps') }}">
        @foreach($wizardSteps as $i => $s)
            <li class="ui-create__step" :class="{ 'is-on': step === '{{ $s['key'] }}', 'is-done': stepDone('{{ $s['key'] }}') }">
                <button type="button" class="ui-create__step-btn" @click="step = '{{ $s['key'] }}'" :aria-current="step === '{{ $s['key'] }}' ? 'step' : null">
                    <span class="ui-create__step-dot">
                        <span class="ui-ltr" x-show="!stepDone('{{ $s['key'] }}')">{{ $i + 1 }}</span>
                        <span x-show="stepDone('{{ $s['key'] }}')" x-cloak><x-ui.icon name="check" size="16" /></span>
                    </span>
                    <span class="ui-create__step-text">
                        <span class="ui-create__step-label">{{ $s['label'] }}</span>
                        <span class="ui-create__step-desc">{{ $s['desc'] }}</span>
                    </span>
                </button>
            </li>
        @endforeach
    </ol>

    {{-- Server-side validation errors: say what to fix, right above the step that reopened. --}}
    @if ($errors->any())
        <x-ui.banner tone="danger" class="ui-create__errors">
            <strong>{{ ___('Your store was not created yet. Please fix the following and try again:') }}</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <span>{{ ___('For security, browsers clear selected photos after an error — please choose your logo and cover photo again.') }}</span>
        </x-ui.banner>
    @endif

    <div class="ui-create__grid">
        <div class="ui-create__main">
            {{-- Step 1: Basics --}}
            <section class="ui-card ui-card--lg ui-create__panel" data-step="basics" x-show="step === 'basics'" x-transition.opacity>
                <header class="ui-create__head">
                    <span class="ui-overline">{{ ___('Step 1') }}</span>
                    <h2 class="ui-create__title">{{ ___('Store basics') }}</h2>
                    <p class="ui-create__lead">{{ ___('Tell customers who you are. You can refine everything later.') }}</p>
                </header>

                {{-- Task 3: AI Studio entry point. If a Studio draft is pending for
                     this owner (studio_guest cookie), it's imported on submit; otherwise
                     offer Studio as a way to build the catalog before finishing here. --}}
                @if (! empty($studioDraftPending))
                    <x-ui.banner tone="success">
                        <strong>{{ ___('Your AI Studio menu is ready') }}</strong><br>
                        {{ ___('The catalog you built in AI Studio will be imported into this store when you finish.') }}
                    </x-ui.banner>
                @elseif (Route::has('studio.app.shell'))
                    <div class="ui-list ui-create__studio">
                        <x-ui.row :href="route('studio.app.shell')" icon="sparkle" :title="___('Start with AI Menu Studio')" :sub="___('Scan a photo or describe your business and let AI build the catalog — then finish creating your store here.')" />
                    </div>
                @endif

                {{-- rev/multi-vertical F0-5: store-type picker. Drives which catalog,
                     item fields, and checkout the store gets (VerticalRegistry). --}}
                <div class="ui-create__group">
                    <p class="ui-create__label">{{ ___('What are you selling?') }}</p>
                    <div class="ui-create__types" role="radiogroup" aria-label="{{ ___('Store type') }}">
                        @foreach ($verticalTypes as $typeKey => $typeCfg)
                            <label class="ui-create__type" :class="storeType === '{{ $typeKey }}' ? 'is-on' : ''" data-store-type="{{ $typeKey }}">
                                <input type="radio" name="store_type" value="{{ $typeKey }}" class="ui-create__type-input" x-model="storeType" @change="onStoreTypeChange()" @checked($selectedType === $typeKey)>
                                <span class="ui-create__type-icon"><x-ui.icon :name="$verticalIcons[$typeKey] ?? 'store'" size="20" /></span>
                                <span class="ui-create__type-body">
                                    <span class="ui-create__type-name">{{ ___($typeCfg['label'] ?? $typeKey) }}</span>
                                    <span class="ui-create__type-hint">{{ $verticalHints[$typeKey] ?? '' }}</span>
                                </span>
                                <span class="ui-create__tick" x-show="storeType === '{{ $typeKey }}'" x-cloak><x-ui.icon name="check" size="14" /></span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="ui-create__fields">
                    <div class="ui-field ui-create__color-field">
                        <label for="restaurant-color" class="ui-field__label">{{ ___('Brand color') }}</label>
                        <div class="ui-create__color">
                            <input
                                id="restaurant-color"
                                type="color"
                                name="color"
                                class="ui-create__color-input"
                                value="{{ $defaultColor }}"
                                x-model="color"
                                aria-describedby="restaurant-color-help"
                            >
                            <span class="ui-create__color-value ui-ltr" x-text="color"></span>
                        </div>
                        <span class="ui-field__help" id="restaurant-color-help">{{ ___('Used across your storefront and QR codes.') }}</span>
                    </div>

                    {{-- rev/multi-vertical: neutral wording — this field names ANY store type. --}}
                    <x-ui.field name="title" :label="___('Name')" :value="old('title')" :placeholder="___('Example: Olive Table')" required />

                    <x-ui.field name="slug" id="store-slug" :label="___('Slug')" :value="old('slug')" :placeholder="___('olive-table')" :help="___('Use letters, numbers, and hyphens. This becomes your store URL.')" dir="ltr" />

                    {{-- rev/multi-vertical: neutral placeholder — "Fresh Mediterranean
                         kitchen" only makes sense for a restaurant. --}}
                    <x-ui.field name="sub_title" :label="___('Subtitle')" :value="old('sub_title')" :placeholder="___('A short tagline for your store')" />

                    <x-ui.field name="timing" :label="___('Timing')" :value="old('timing')" :placeholder="___('Daily, 9 AM - 11 PM')" />

                    <x-ui.field name="phone" type="tel" :label="___('Phone')" :value="old('phone')" :placeholder="___('+1 555 0100')" dir="ltr" />

                    <div class="ui-create__full">
                        <x-ui.field name="address" :label="___('Address')" :value="old('address')" :placeholder="___('Street, city, country')" required />
                    </div>

                    <div class="ui-create__full">
                        <x-ui.field name="description" type="textarea" :label="___('Description')" :value="old('description')" :placeholder="___('Tell guests what makes this store special.')" required />
                    </div>
                </div>

                <div class="ui-create__actions ui-create__actions--end">
                    <x-ui.button type="button" @click="next('basics', 'template')">
                        {{ ___('Choose template') }}
                        <span class="ui-btn__icon"><x-ui.icon name="chevron" :flip="true" /></span>
                    </x-ui.button>
                </div>
            </section>

            {{-- Step 2: Template --}}
            <section class="ui-card ui-card--lg ui-create__panel" data-step="template" x-show="step === 'template'" x-cloak x-transition.opacity>
                <header class="ui-create__head">
                    <span class="ui-overline">{{ ___('Step 2') }}</span>
                    {{-- rev/multi-vertical: follows the step-1 store-type pick — a
                         retail/laundry/… store never sees "Menu template" here. --}}
                    <h2 class="ui-create__title" x-text="templateStepHeading">{{ $verticalTemplateHeadings[$selectedType] ?? ___('Menu template') }}</h2>
                    <p class="ui-create__lead">{{ ___('Add your logo and cover, then pick a storefront design.') }}</p>
                </header>

                <div class="ui-banner ui-create__error" data-tone="danger" role="alert" x-show="imageError" x-cloak>
                    <span class="ui-banner__icon"><x-ui.icon name="error" size="18" /></span>
                    <div class="ui-banner__body" x-text="imageError"></div>
                </div>

                <div class="ui-create__uploads">
                    <div class="ui-create__upload">
                        <div class="ui-create__upload-preview">
                            <img :src="logoPreview" alt="" x-show="logoPreview" x-cloak>
                            <span class="ui-create__imgph" x-show="!logoPreview"><x-ui.icon name="upload" size="24" /></span>
                        </div>
                        <div class="ui-create__upload-meta">
                            <label class="ui-field__label" for="create-main-image">{{ ___('Logo') }}</label>
                            <input
                                type="file"
                                id="create-main-image"
                                name="main_image"
                                class="ui-input ui-input--sm"
                                accept="image/*"
                                required
                                @change="previewFile($event, 'logoPreview')"
                            >
                            <span class="ui-field__help">{{ ___('A square image works best.') }}</span>
                        </div>
                    </div>

                    <div class="ui-create__upload">
                        <div class="ui-create__upload-preview ui-create__upload-preview--wide">
                            <img :src="coverPreview" alt="" x-show="coverPreview" x-cloak>
                            <span class="ui-create__imgph" x-show="!coverPreview"><x-ui.icon name="upload" size="24" /></span>
                        </div>
                        <div class="ui-create__upload-meta">
                            <label class="ui-field__label" for="create-cover-image">{{ ___('Cover image') }}</label>
                            <input
                                type="file"
                                id="create-cover-image"
                                name="cover_image"
                                class="ui-input ui-input--sm"
                                accept="image/*"
                                required
                                @change="previewFile($event, 'coverPreview')"
                            >
                            <span class="ui-field__help">{{ ___('Wide banner shown behind your logo.') }}</span>
                        </div>
                    </div>
                </div>

                <div class="ui-create__tpls-head">
                    <p class="ui-create__label">{{ ___('Storefront design') }}</p>
                    {{-- Descriptions are per-theme copy that can run long and clutter
                         the grid; let the owner collapse them. Preference is
                         per-viewer (localStorage), so it sticks across visits. --}}
                    <button type="button" class="ui-chip" @click="toggleTplDesc()" :aria-pressed="showTplDesc ? 'true' : 'false'">
                        <span x-show="showTplDesc">{{ ___('Hide descriptions') }}</span>
                        <span x-show="!showTplDesc" x-cloak>{{ ___('Show descriptions') }}</span>
                    </button>
                </div>
                <div class="ui-create__tpls" role="radiogroup" aria-label="{{ ___('Store Template') }}">
                    @foreach($templates as $template)
                        {{-- rev/multi-vertical Lane C (C4): only the cards matching the
                             step-1 store-type pick's theme set are shown — e.g. a
                             `retail` pick shows `shelf`, never `crave`. store() still
                             re-validates this server-side against the same set. --}}
                        <label
                            class="ui-create__tpl"
                            x-show="cardSet(@js($template['folder'])) === wantedSet"
                            x-cloak
                            :class="selectedTemplate === @js($template['folder']) ? 'is-on' : ''"
                        >
                            <input
                                type="radio"
                                class="ui-create__tpl-input"
                                value="{{ $template['folder'] }}"
                                @checked($selectedTemplate === $template['folder'])
                                @change="selectedTemplate = @js($template['folder'])"
                            >
                            <span class="ui-create__tpl-media">
                                <img src="{{ $template['image'] }}" alt="" loading="lazy">
                                <span class="ui-create__tick" x-show="selectedTemplate === @js($template['folder'])" x-cloak><x-ui.icon name="check" size="14" /></span>
                            </span>
                            <span class="ui-create__tpl-body">
                                <strong class="ui-create__tpl-name">{{ ___($template['name']) }}</strong>
                                {{-- rev/multi-vertical: neutral fallback (no "menu") for
                                     the rare template with no discovered description.
                                     Collapsible via the gallery-header toggle (showTplDesc). --}}
                                <small class="ui-create__tpl-desc" x-show="showTplDesc" x-cloak>{{ ___($template['description'] ?? 'A premium template for your storefront.') }}</small>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="ui-create__actions">
                    <x-ui.button type="button" variant="secondary" @click="step = 'basics'">
                        <span class="ui-btn__icon"><x-ui.icon name="back" :flip="true" /></span>
                        {{ ___('Back') }}
                    </x-ui.button>
                    <x-ui.button type="button" @click="review()" x-bind:disabled="processing > 0">
                        <span x-show="!processing">{{ ___('Review') }}</span>
                        <span x-show="processing" x-cloak>{{ ___('Processing…') }}</span>
                        <span class="ui-btn__icon"><x-ui.icon name="chevron" :flip="true" /></span>
                    </x-ui.button>
                </div>
            </section>

            {{-- Step 3: Confirm --}}
            <section class="ui-card ui-card--lg ui-create__panel" data-step="confirm" x-show="step === 'confirm'" x-cloak x-transition.opacity>
                <header class="ui-create__head">
                    <span class="ui-overline">{{ ___('Step 3') }}</span>
                    <h2 class="ui-create__title">{{ ___('Ready to create') }}</h2>
                    <p class="ui-create__lead">{{ ___('Give it a final look. You can edit any of this after publishing.') }}</p>
                </header>

                <ul class="ui-list ui-create__summary">
                    <li class="ui-row">
                        <span class="ui-row__icon"><x-ui.icon name="store" size="22" /></span>
                        <span class="ui-row__body">
                            <span class="ui-row__sub">{{ ___('Store') }}</span>
                            <span class="ui-row__title" x-text="previewTitle"></span>
                        </span>
                        <span class="ui-row__trail"><span class="ui-tag" data-tone="accent" x-text="storeTypeLabel"></span></span>
                    </li>
                    <li class="ui-row">
                        <span class="ui-row__icon"><x-ui.icon name="sparkle" size="22" /></span>
                        <span class="ui-row__body">
                            <span class="ui-row__sub">{{ ___('Selected template') }}</span>
                            <span class="ui-row__title" x-text="templateLabel"></span>
                        </span>
                        <span class="ui-row__trail"><span class="ui-create__swatch" :style="'background:' + color"></span></span>
                    </li>
                    <li class="ui-row">
                        <span class="ui-row__icon"><x-ui.icon name="qr" size="22" /></span>
                        <span class="ui-row__body">
                            <span class="ui-row__sub">{{ ___('Next steps') }}</span>
                            {{-- rev/multi-vertical: vocab-aware per the step-1 pick (see
                                 $verticalConfirmNotes above) — "QR tables" only applies
                                 to a restaurant, so a retail/laundry/… store gets its own note. --}}
                            <span class="ui-row__title ui-create__note" x-text="confirmNote">{{ $verticalConfirmNotes[$selectedType] ?? ___('After creation, you can add menu items, QR tables, branches, and ordering settings.') }}</span>
                        </span>
                    </li>
                </ul>

                <div class="ui-create__actions">
                    <x-ui.button type="button" variant="secondary" @click="step = 'template'">
                        <span class="ui-btn__icon"><x-ui.icon name="back" :flip="true" /></span>
                        {{ ___('Back') }}
                    </x-ui.button>
                    <x-ui.button type="submit" icon="check" x-bind:disabled="processing > 0 || submitting">
                        <span x-show="!submitting">{{ ___('Create') }}</span>
                        <span x-show="submitting" x-cloak>{{ ___('Creating your store…') }}</span>
                    </x-ui.button>
                </div>
                <p class="ui-create__submitting" role="status" aria-live="polite" x-show="submitting" x-cloak>
                    {{ ___('Uploading your photos and creating the store. This can take a few seconds on mobile — please keep this page open.') }}
                </p>
            </section>
        </div>

        {{-- Live preview --}}
        <aside class="ui-create__aside" aria-label="{{ ___('Store preview') }}">
            <div class="ui-card ui-card--flush ui-create__preview">
                <div class="ui-create__chrome" aria-hidden="true">
                    <span class="ui-create__chrome-dot"></span>
                    <span class="ui-create__chrome-dot"></span>
                    <span class="ui-create__chrome-dot"></span>
                    <span class="ui-create__chrome-url ui-ltr" x-text="previewSlug"></span>
                </div>
                <div class="ui-create__stage">
                    <div class="ui-create__cover">
                        {{-- No image yet → the brand-tinted background is the empty state. --}}
                        <img :src="coverPreview" alt="" x-show="coverPreview" x-cloak>
                    </div>
                    <div class="ui-create__logo">
                        <img :src="logoPreview" alt="" x-show="logoPreview" x-cloak>
                        <span class="ui-create__logo-ph" x-show="!logoPreview"><x-ui.icon name="store" size="26" /></span>
                    </div>
                    <div class="ui-create__info">
                        <span class="ui-create__eyebrow" x-text="storeTypeLabel"></span>
                        <h3 class="ui-create__ptitle" x-text="previewTitle"></h3>
                        <span class="ui-create__pslug ui-ltr" x-text="previewSlug"></span>
                    </div>
                    <div class="ui-create__meta">
                        <span class="ui-tag"><span x-text="templateLabel"></span></span>
                        <span class="ui-tag">
                            <span class="ui-create__swatch ui-create__swatch--sm" :style="'background:' + color"></span>
                            <span class="ui-ltr" x-text="color"></span>
                        </span>
                    </div>
                </div>
                <p class="ui-create__pnote">{{ ___('Live preview — this is how your storefront header will look.') }}</p>
            </div>
        </aside>
    </div>
</form>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.createStorePage = function (config) {
        // Max edge (px) + JPEG quality for the in-browser downscale. The server
        // re-resizes anyway (cover 300, logo 500²) — this only needs to yield a
        // compliant, small JPEG so a phone's HEIC / multi-MB photo never trips
        // the `mimes:png,jpg,jpeg,webp` + `max:5120` (5 MB) server rule.
        var MAX_EDGE = 2000;
        var JPEG_QUALITY = 0.85;
        var MAX_BYTES = 5 * 1024 * 1024;

        return {
            step: config.step || 'basics',
            // The ordered wizard steps — drives the progress rail's done/current state.
            steps: ['basics', 'template', 'confirm'],
            color: @js($defaultColor),
            // Empty until the owner picks a file. The previews used to fall back
            // to storage/restaurant/{logo,cover}/default.png, but that file is not
            // guaranteed to exist (404 → broken-image icon); a self-contained
            // placeholder renders on any environment instead.
            logoPreview: '',
            coverPreview: '',
            selectedTemplate: config.selectedTemplate,
            templateNames: config.templateNames || {},
            processing: 0,        // >0 while an image is being converted
            submitting: false,    // true once the form is posting (blocks double taps)
            imageError: '',       // shown on step 2 when a file can't be used
            // rev/multi-vertical Lane C (C4): storeType drives which template
            // cards step 2 shows (see cardSet()/wantedSet below).
            storeType: config.storeType || 'restaurant',
            templateSets: config.templateSets || {},
            verticalThemeSets: config.verticalThemeSets || {},
            templateHeadings: config.templateHeadings || {},
            confirmNotes: config.confirmNotes || {},
            typeLabels: config.typeLabels || {},
            // Whether the per-theme descriptions show in the step-2 gallery. A
            // per-viewer preference (localStorage), defaulting to shown; wrapped
            // in try/catch so a private window / blocked storage never breaks init.
            showTplDesc: (function () {
                try { return localStorage.getItem('sc_tpl_desc') !== '0'; } catch (e) { return true; }
            })(),
            toggleTplDesc: function () {
                this.showTplDesc = !this.showTplDesc;
                try { localStorage.setItem('sc_tpl_desc', this.showTplDesc ? '1' : '0'); } catch (e) { /* ignore */ }
            },
            // Progress rail: a step is "done" once the wizard has moved past it.
            get currentIndex() {
                return this.steps.indexOf(this.step);
            },
            stepDone: function (key) {
                var i = this.steps.indexOf(key);
                return i > -1 && i < this.currentIndex;
            },
            get templateLabel() {
                return this.templateNames[this.selectedTemplate] || this.selectedTemplate || '';
            },
            // The human label for the currently-picked vertical (live-preview eyebrow
            // + confirm badge) — pre-translated server-side, never glued client-side.
            get storeTypeLabel() {
                return this.typeLabels[this.storeType] || '';
            },
            // rev/multi-vertical: step-2 heading + step-3 confirmation note follow
            // whichever store type is currently picked in step 1.
            get templateStepHeading() {
                return this.templateHeadings[this.storeType] || this.templateHeadings.restaurant || '';
            },
            get confirmNote() {
                return this.confirmNotes[this.storeType] || this.confirmNotes.restaurant || '';
            },
            // The theme-set ('food'|'retail'|'service') a template slug belongs
            // to — mirrors App\Services\Theme\VerticalThemeSets::setFor() server-side.
            cardSet: function (slug) {
                return this.templateSets[slug] || 'food';
            },
            get wantedSet() {
                return this.verticalThemeSets[this.storeType] || 'food';
            },
            // Fired when a step-1 store-type radio changes. If the currently
            // selected template no longer matches the new vertical's set (its
            // card just got hidden), auto-pick the first visible one instead of
            // silently submitting a hidden, wrong-vertical template.
            onStoreTypeChange: function () {
                if (this.cardSet(this.selectedTemplate) === this.wantedSet) {
                    return;
                }
                var self = this;
                var next = Object.keys(this.templateSets).find(function (slug) {
                    return self.templateSets[slug] === self.wantedSet;
                });
                if (next) {
                    this.selectedTemplate = next;
                }
            },
            get previewTitle() {
                var field = document.querySelector('[name="title"]');
                return field && field.value ? field.value : @js(___('Store name'));
            },
            get previewSlug() {
                var field = document.querySelector('[name="slug"]');
                var slug = field && field.value ? field.value : @js(___('your-url'));
                return '/' + slug;
            },
            // Step 1 → 2: check this step's required fields first. Without this an
            // empty required field on a hidden step silently blocks the final submit.
            next: function (from, to) {
                var section = this.$root.querySelector('[data-step="' + from + '"]');
                var bad = section ? Array.prototype.find.call(section.querySelectorAll('input, textarea, select'), function (el) {
                    return !el.checkValidity();
                }) : null;
                if (bad) {
                    bad.reportValidity();

                    return;
                }
                this.step = to;
            },
            // Final submit: if any field (on any step) is invalid, open its step and
            // show the browser message; otherwise lock the form while it uploads.
            onSubmit: function (event) {
                if (this.submitting || this.processing) {
                    event.preventDefault();

                    return;
                }
                var form = event.target;
                var bad = Array.prototype.find.call(form.querySelectorAll('input, textarea, select'), function (el) {
                    return !el.checkValidity();
                });
                if (bad) {
                    event.preventDefault();
                    var section = bad.closest('[data-step]');
                    if (section) {
                        this.step = section.getAttribute('data-step');
                    }
                    this.$nextTick(function () { bad.reportValidity(); });

                    return;
                }
                this.submitting = true;
            },
            review: function () {
                if (this.processing) {
                    return; // still converting — the buttons are disabled anyway
                }

                var logo = document.querySelector('[name="main_image"]');
                var cover = document.querySelector('[name="cover_image"]');
                var missing = !logo.files.length ? logo : (!cover.files.length ? cover : null);

                if (missing) {
                    this.step = 'template';
                    this.$nextTick(function () {
                        missing.reportValidity();
                    });

                    return;
                }

                // Backstop: if conversion was skipped (e.g. a desktop browser that
                // can't decode HEIC) a still-oversized/unsupported file would only
                // fail server-side and bounce the whole wizard. Catch it here with
                // a clear message instead. (Converted files are always small JPEGs
                // and pass both checks.)
                var okTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
                var bad = [logo, cover].map(function (i) { return i.files[0]; })
                    .filter(function (f) { return f && (f.size > MAX_BYTES || (f.type && okTypes.indexOf(f.type) === -1)); });

                if (bad.length) {
                    this.step = 'template';
                    this.imageError = @js(___('Please choose a JPG, PNG or WEBP image under 5 MB (or a photo we can convert).'));

                    return;
                }

                this.imageError = '';
                this.step = 'confirm';
            },
            previewFile: function (event, target) {
                var input = event.target;
                var file = input.files && input.files[0];

                if (!file) {
                    return;
                }

                this.imageError = '';
                var self = this;
                this.processing++;

                this.compressImage(file).then(function (result) {
                    // Swap the compliant JPEG back into the file input so the form
                    // submits THAT, not the original HEIC/oversized photo.
                    if (result && window.DataTransfer) {
                        try {
                            var dt = new DataTransfer();
                            dt.items.add(result);
                            input.files = dt.files;
                            file = result;
                        } catch (e) { /* keep original file on any failure */ }
                    }
                    self[target] = URL.createObjectURL(file);
                }).catch(function () {
                    // Decode failed (e.g. desktop HEIC): keep the original; the
                    // step-2 backstop / server message will guide the user.
                    self[target] = URL.createObjectURL(file);
                }).finally(function () {
                    self.processing--;
                });
            },
            // Decode the picked file (via createImageBitmap — accepts a Blob/File
            // directly, no object-URL and so no dependency on the CSP img-src list;
            // iOS Safari decodes HEIC here), downscale it onto a canvas, and
            // re-encode as a JPEG File. Turns an iPhone HEIC / multi-MB photo into an
            // accepted, small JPEG in the browser. ALWAYS settles within TIMEOUT so
            // the `processing` gate can never wedge the wizard's buttons.
            compressImage: function (file) {
                var TIMEOUT = 15000; // safety valve for a genuinely stuck decode only
                var run = new Promise(function (resolve, reject) {
                    if (!window.createImageBitmap || !window.HTMLCanvasElement ||
                        !HTMLCanvasElement.prototype.toBlob) {
                        return reject(new Error('unsupported'));
                    }
                    createImageBitmap(file).then(function (bmp) {
                        var w = bmp.width, h = bmp.height;
                        if (!w || !h) { bmp.close && bmp.close(); return reject(new Error('empty')); }
                        var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
                        var cw = Math.round(w * scale), ch = Math.round(h * scale);
                        var canvas = document.createElement('canvas');
                        canvas.width = cw; canvas.height = ch;
                        var ctx = canvas.getContext('2d');
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, cw, ch); // flatten any alpha for JPEG
                        ctx.drawImage(bmp, 0, 0, cw, ch);
                        bmp.close && bmp.close();
                        canvas.toBlob(function (blob) {
                            if (!blob) { return reject(new Error('encode')); }
                            var base = (file.name || 'image').replace(/\.[^.]+$/, '');
                            resolve(new File([blob], base + '.jpg', { type: 'image/jpeg' }));
                        }, 'image/jpeg', JPEG_QUALITY);
                    }).catch(reject);
                });
                var guard = new Promise(function (_, reject) {
                    setTimeout(function () { reject(new Error('timeout')); }, TIMEOUT);
                });
                return Promise.race([run, guard]);
            }
        };
    };
</script>
@endpush
