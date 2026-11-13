{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Manage Menu').' - '.$store->title)
@section('page_title', ___('Menu items'))
@section('page_sub', $store->title)

@php
    $user = request()->user();
    $categoryLimit = effective_limit('category', $user);
    $menuLimit = effective_limit('menu', $user);
    // Direct MenuCategory query, NOT $store->menu_categories(): that relation
    // is storefront-scoped (drops inactive categories) and the editor must
    // still show hidden buckets so owners can unhide or re-file them.
    $categories = \App\Models\MenuCategory::query()
        ->where('store_id', $store->id)
        ->whereNull('parent')
        ->with(['subcategories.menus', 'menus'])
        ->orderBy('position')
        ->get();
    $menus = \Juwri\Core\Support\Views\OwnerOpsViewHelpers::flatMapCategoriesMenus($categories);
    $totalCategories = $categories->count() + $categories->flatMap->subcategories->count();
    $totalMenuItems = $menus->count();
    /* F7-6: sentinel comparisons go through the resolver, never a raw `< 999`. */
    $categoryUnlimited = \App\Services\Entitlements::isUnlimitedSentinel($categoryLimit);
    $menuUnlimited = \App\Services\Entitlements::isUnlimitedSentinel($menuLimit);
    $reachedCategoryLimit = !$categoryUnlimited && $totalCategories >= $categoryLimit;
    $reachedMenuLimit = !$menuUnlimited && $totalMenuItems >= $menuLimit;
    $firstCategory = $categories->first();
    // P1-17: default language of AI description drafts (store language; AR or EN).
    $aiCopyLang = \Juwri\Modules\Ai\Data\CopyLanguage::storeDefault((int) $store->id)->code;
@endphp

@section('page_actions')
    @php $trashedCount = Route::has('stores.menu.trash') ? \Juwri\Modules\Menu\Models\Menu::onlyTrashed()->where('store_id', $store->id)->count() : 0; @endphp
    <div class="ui-pop-host ui-menu-more" data-ui-pop>
        <button type="button" class="ui-btn ui-btn--secondary" data-ui-pop-toggle aria-haspopup="true" aria-expanded="false">
            <span class="ui-btn__icon"><x-ui.icon name="dots" /></span>{{ ___('More') }}
        </button>
        <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
            @if(Route::has('stores.edit'))
                <a class="ui-pop__item" href="{{ route('stores.edit', $store->id) }}"><x-ui.icon name="settings" size="18" /> {{ ___('Settings') }}</a>
            @endif
            @if(Route::has('stores.tables'))
                <a class="ui-pop__item" href="{{ route('stores.tables', $store->id) }}"><x-ui.icon name="qr" size="18" /> {{ ___('Tables & QR') }}</a>
            @endif
            @if(Route::has('stores.menuExportCsv'))
                <a class="ui-pop__item" href="{{ route('stores.menuExportCsv', $store->id) }}" data-busy-download data-busy-label="{{ ___('Preparing…') }}"><x-ui.icon name="download" size="18" /> {{ ___('Export CSV') }}</a>
            @endif
            @if(Route::has('stores.menuImportCsv'))
                <button type="button" class="ui-pop__item" x-data @click="$dispatch('menu-import')"><x-ui.icon name="upload" size="18" /> {{ ___('Import CSV') }}</button>
            @endif
            @if(Route::has('stores.menu.aiGenerate'))
                <button type="button" class="ui-pop__item" x-data @click="$dispatch('menu-ai')"><x-ui.icon name="sparkle" size="18" /> {{ ___('AI generate') }}</button>
            @endif
            @if($trashedCount > 0)
                <hr class="ui-pop__sep">
                <a class="ui-pop__item" href="{{ route('stores.menu.trash', $store->id) }}"><x-ui.icon name="trash" size="18" /> {{ ___('Trash') }} ({{ $trashedCount }})</a>
            @endif
        </div>
    </div>
    @if(Route::has('stores.menu.aiTranslate') && !empty($menu_languages) && count($menu_languages) > 1)
        @php
            /* $menu_languages is a collection of Language models (ManagesMenu::menu) */
            $trDefault   = (string) store_options($store->id, 'default_language');
            $trMenuLangs = collect($menu_languages)->pluck('code')->filter()->values()->all();
            $trCodes     = array_values(array_unique(array_filter(array_merge($trMenuLangs, [$trDefault]))));
            $trLangNames = \Juwri\Core\Models\Language::whereIn('code', $trCodes)->pluck('name', 'code')->toArray();
        @endphp
        <div x-data="{
            busy: false, label: '', queuedPolls: 0,
            source: @js($trDefault ?: ($trMenuLangs[0] ?? '')),
            menuLangs: @js($trMenuLangs),
            langNames: @js($trLangNames),
            url: @js(route('stores.menu.aiTranslate', $store->id)),
            progressUrl: @js(route('stores.menu.aiTranslateProgress', $store->id)),
            token: @js(csrf_token()),
            timer: null,
            name(code) { return this.langNames[code] || code; },
            targets() { return this.menuLangs.filter(c => c && c !== this.source); },
            targetNames() { return this.targets().map(c => this.name(c)).join('، '); },
            open() { this.$refs.trDialog.showModal(); },
            async run() {
                if (this.busy) return;
                if (!this.source) { window.dashToast('danger', @js(___('Choose the language your menu is currently written in.'))); return; }
                this.$refs.trDialog.close();
                this.busy = true; this.queuedPolls = 0; this.label = @js(___('Starting…'));
                try {
                    const body = new FormData(); body.append('source', this.source);
                    const r = await fetch(this.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.token, 'Accept': 'application/json' }, body });
                    const d = await r.json();
                    if (!r.ok) { window.dashToast('danger', d.error || 'Error'); this.busy = false; return; }
                    {{-- L3: hand off polling to the shared <x-job-progress> component --}}
                    this.$root.querySelector('.dash-job-progress').dispatchEvent(new CustomEvent('job-start'));
                } catch (e) {
                    this.busy = false; window.dashToast('danger', @js(___('Could not start translation. Please try again.')));
                }
            }
        }" class="dx-inline ui-inline"
           x-on:job-complete="window.location.reload()"
           x-on:job-failed="busy = false">
            <button type="button" class="ui-btn ui-btn--secondary" x-bind:disabled="busy" @click="open()">
                <span class="ui-btn__icon"><x-ui.icon name="sparkle" /></span>
                <span x-show="!busy">{{ ___('Translate missing') }}</span>
                <span x-show="busy" x-cloak x-text="label || @js(___('Translating…'))"></span>
            </button>

            <x-job-progress
                            :url="route('stores.menu.aiTranslateProgress', $store->id)"
                            :unit="___('items')" />

            <dialog class="ui-dialog ui-dialog--flat" x-ref="trDialog" style="max-width:480px;width:92vw;">
                <div class="ui-cluster ui-cluster--between ui-dialog__head ui-rule-bottom">
                    <h3 class="ui-section-title">{{ ___('Translate menu with AI') }}</h3>
                    <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.trDialog.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
                </div>
                <div class="ui-dialog__body">
                    <div class="edit-field">
                        <label class="ui-label" for="tr-source">{{ ___('My menu is currently written in') }}</label>
                        <select class="ui-input" id="tr-source" x-model="source">
                            @foreach($trCodes as $code)
                                <option value="{{ $code }}">{{ $trLangNames[$code] ?? $code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="ui-muted">
                        {{ ___('Missing translations will be filled into:') }} <strong x-text="targetNames()"></strong>
                    </p>
                    <p class="ui-text-warning ui-small">
                        {{ ___('Important: pick the language your items are actually written in. Translating from a language your menu is not written in (or from an empty menu) gives wrong or empty results.') }}
                    </p>
                    <x-ai-badge :store-id="$store->id" />
                </div>
                <div class="ui-cluster ui-cluster--end ui-dialog__foot ui-rule-top">
                    <x-ui.button type="button" variant="ghost" @click="$refs.trDialog.close()">{{ ___('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" icon="chat" @click="run()" x-bind:disabled="targets().length === 0">
                        {{ ___('Start translation') }}
                    </x-ui.button>
                </div>
            </dialog>
        </div>
        @if(Route::has('stores.translations.review'))
            <x-ui.button :href="route('stores.translations.review', $store->id)" variant="secondary" icon="check">{{ ___('Review translations') }}</x-ui.button>
        @endif
    @endif
@endsection

@section('content')
{{-- F7-4: warn BEFORE the hard "Limit exceeded" error on add-item / add-category. --}}
@include('owner.dashboard.partials.near-limit-banner', ['keys' => ['menu', 'category']])

{{-- C7 (hotel-pms) — starter-menu seed result. --}}
@if(session('starter_success'))
    <x-ui.banner tone="success" class="ui-menu-flash">{{ session('starter_success') }}</x-ui.banner>
@endif
@if(session('starter_error'))
    <x-ui.banner tone="warning" class="ui-menu-flash">{{ session('starter_error') }}</x-ui.banner>
@endif
<div
    class="menu-page ui-scope ui-menu"
    x-data="linenMenuIndex({
        addItemUrl: @js(route('stores.addMenuItem', $store->id)),
        deleteCategoryUrl: @js(route('stores.deleteCategory', $store->id)),
        token: @js(csrf_token()),
        storeId: @js($store->id),
        defaultCategory: @js($firstCategory?->id),
        savedText: @js(___('Saved Successfully')),
        failedText: @js(___('Unable to save item.')),
        deleteConfirmText: @js(___('Delete this category?')),
        subDeleteTitle: @js(___('This category has sub-categories')),
        subDeleteBody: @js(___('What should happen to them?')),
        subDeleteCascade: @js(___('Delete everything')),
        subDeleteReparent: @js(___('Move sub-categories up')),
        subDeleteCancel: @js(___('Cancel')),
        bulkUrl: @js(Route::has('stores.menuBulk') ? route('stores.menuBulk', $store->id) : ''),
        bulkDeleteConfirm: @js(___('Delete selected items?')),
        restoreUrl: @js(Route::has('stores.menu.restore') ? route('stores.menu.restore', $store->id) : ''),
        undoText: @js(___('items deleted')),
        undoAction: @js(___('Undo')),
        editItemUrl: @js(Route::has('stores.updateMenuItem') ? route('stores.updateMenuItem', $store->id) : ''),
        quickImageUrl: @js(Route::has('stores.quickImageMenuItem') ? route('stores.quickImageMenuItem', $store->id) : ''),
        quickEditUrl: @js(Route::has('stores.quickEditMenuItem') ? route('stores.quickEditMenuItem', $store->id) : ''),
        allergenUrl: @js(Route::has('stores.storeAllergen') ? route('stores.storeAllergen', $store->id) : ''),
        deleteAllergenConfirm: @js(___('Delete this allergen? It will be removed from all items.')),
        deleteItemUrl: @js(Route::has('stores.deleteMenuItem') ? route('stores.deleteMenuItem', $store->id) : ''),
        itemDeleteConfirm: @js(___('Delete this item?')),
        reorderCategoryUrl: @js(Route::has('stores.reorderCategory') ? route('stores.reorderCategory', $store->id) : ''),
        reorderItemUrl: @js(Route::has('stores.reorderMenuItem') ? route('stores.reorderMenuItem', $store->id) : ''),
        aiDescribeUrl: @js(Route::has('stores.menu.aiDescribe') ? route('stores.menu.aiDescribe', $store->id) : ''),
        aiNeedName: @js(___('Enter the item name first.')),
        aiDraftReady: @js(___('Draft ready. Check it, then save the item.')),
        aiFailed: @js(___('The AI service is unavailable right now. Please try again in a moment.'))
    })"
>
    @if(Route::has('stores.menu.aiImagesProgress'))
        <div x-data="{
                show: false, error: false, label: '', wasRunning: false, timer: null, queuedPolls: 0,
                url: @js(route('stores.menu.aiImagesProgress', $store->id)),
                start() { this.tick(); this.timer = setInterval(() => this.tick(), 4000); },
                async tick() {
                    try {
                        const p = await (await fetch(this.url, { headers: { 'Accept': 'application/json' } })).json();
                        if (p.status === 'running' || p.status === 'queued') {
                            {{-- Cap stale 'queued' entries (job never picked up) at ~2 min --}}
                            if (p.status === 'queued' && ++this.queuedPolls > 30) { this.show = false; clearInterval(this.timer); return; }
                            if (p.status === 'running') this.queuedPolls = 0;
                            this.wasRunning = true; this.show = true; this.error = false;
                            this.label = @js(___('Generating AI photos')) + ' ' + (p.done || 0) + '/' + (p.total || '?') + '…';
                        } else if (p.status === 'error' && p.message) {
                            clearInterval(this.timer);
                            this.show = true; this.error = true;
                            this.label = p.message;
                        } else if (this.wasRunning && p.status === 'done') {
                            clearInterval(this.timer);
                            this.label = @js(___('AI photos ready — refreshing…'));
                            setTimeout(() => window.location.reload(), 1200);
                        } else {
                            this.show = false;
                            clearInterval(this.timer);
                        }
                    } catch (e) {}
                }
            }"
            x-init="start()" x-show="show" x-cloak
            {{-- No Bootstrap d-flex here: its display:flex !important beat x-show and
                 left an empty strip on every visit. --}}
            class="ui-banner ui-menu-progress" :data-tone="error ? 'danger' : null">
            <span class="ui-menu-progress__dot" aria-hidden="true"></span>
            <span x-text="label" aria-live="polite"></span>
        </div>
    @endif

    <section class="menu-summary ui-menu-summary">
        <div class="ui-menu-summary__lead">
            <p class="ui-overline">{{ ___('Menu workspace') }}</p>
            <h2 class="ui-menu-summary__title">{{ ___('Categories and items') }}</h2>
            <span class="ui-menu-summary__sub">{{ ___(':items items across :categories categories', ['items' => number_format($totalMenuItems), 'categories' => number_format($totalCategories)]) }}</span>
        </div>
        <div class="menu-summary__meters ui-menu-summary__meters">
            @foreach([
                [___('Categories'), $totalCategories, $categoryUnlimited, $categoryLimit, $reachedCategoryLimit],
                [___('Menu items'), $totalMenuItems, $menuUnlimited, $menuLimit, $reachedMenuLimit],
            ] as [$mLabel, $mUsed, $mUnlimited, $mLimit, $mFull])
                <div class="ui-menu-summary__meter">
                    <span class="ui-menu-summary__meter-head">
                        <span>{{ $mLabel }}</span>
                        <strong class="ui-ltr">{{ number_format($mUsed) }} / {{ $mUnlimited ? ___('Unlimited') : number_format($mLimit) }}</strong>
                    </span>
                    <span @class(['ui-ov-meter', 'is-unlimited' => $mUnlimited, 'is-exhausted' => $mFull]) aria-hidden="true"><span style="inline-size: {{ $mUnlimited ? 100 : min(100, round(($mUsed / max(1, $mLimit)) * 100)) }}%"></span></span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="menu-toolbar ui-menu-toolbar">
        <label class="restaurants-search ui-orders-search__field ui-menu-toolbar__search">
            <x-ui.icon name="search" size="18" />
            <span class="ui-visually-hidden">{{ ___('Search menu items') }}</span>
            <input type="search" x-model.debounce.200ms="query" placeholder="{{ ___('Search items or categories') }}">
        </label>

        @if(!empty($menu_languages) && $default_menu_language)
            <div class="menu-language ui-menu-toolbar__lang">
                <span class="ui-orders-muted">{{ ___('Language') }}</span>
                <div class="menu-language__buttons ui-chips ui-periodbar__presets" role="group" aria-label="{{ ___('Language') }}">
                    @foreach($menu_languages as $lang)
                        <button type="button" @class(['ui-chip', 'is-active' => $default_menu_language->code === $lang->code])
                            @if($default_menu_language->code === $lang->code) aria-current="true" @endif
                            @click="switchLanguage(@js($lang->code))">{{ strtoupper($lang->code) }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        @if(!$reachedMenuLimit && $firstCategory)
            <x-ui.button icon="plus" class="ui-menu-toolbar__add" x-on:click="openAddItem({{ $firstCategory->id }})" data-controller="bridge--button" data-bridge-title="{{ ___('Add item') }}" data-bridge-ios-image="plus" data-bridge-android-image="add">{{ ___('Add item') }}</x-ui.button>
        @elseif($reachedMenuLimit && Route::has('subscription'))
            <x-ui.button :href="route('subscription')" icon="sparkle" class="ui-menu-toolbar__add">{{ ___('Upgrade plan') }}</x-ui.button>
        @endif
    </section>

    <section class="menu-create-category ui-menu-newcat">
        <form method="post" action="{{ route('stores.addCategory', $store->id) }}" class="ui-menu-newcat__form"
              x-data="imagePickerField({ target: 'cat-add', storeId: {{ $store->id }}, folder: 'category' })">
            @csrf
            <div class="ui-menu-newcat__name">
                <label class="ui-label" for="new-category-name">{{ ___('New category') }}<span class="ui-menu-req" aria-hidden="true">*</span></label>
                <input class="ui-input" id="new-category-name" name="name" placeholder="{{ ___('Lunch specials') }}" required>
            </div>
            <input type="hidden" name="image" :value="filename">
            <div class="image-pick ui-menu-newcat__image">
                <img class="image-pick__thumb" x-show="url" :src="url" alt="" x-cloak>
                <button type="button" class="ui-btn ui-btn--secondary" @click="choose()"><span class="ui-btn__icon"><x-ui.icon name="plus" /></span>{{ ___('Image') }}</button>
                <button type="button" class="ui-btn ui-btn--ghost" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
            </div>
            @if($reachedCategoryLimit && Route::has('subscription'))
                <x-ui.button :href="route('subscription')" icon="sparkle">{{ ___('Upgrade plan') }}</x-ui.button>
            @else
                <x-ui.button type="submit" variant="dark" icon="plus">{{ ___('Add Category') }}</x-ui.button>
            @endif
        </form>
    </section>

    <section class="menu-categories ui-menu-cats" aria-label="{{ ___('Menu categories') }}" @dragover.prevent="dragOver($event, 'category')" @drop.prevent>
        @forelse($categories as $category)
            @include('owner.menu.category', [
                'category' => $category,
                'store' => $store,
                'reachedMenuLimit' => $reachedMenuLimit,
                'reachedCategoryLimit' => $reachedCategoryLimit,
            ])
        @empty
            {{-- C7 (hotel-pms) — start from a template. Owner-only (a staff member gets the
                 plain empty state), shown only while the menu has zero categories. Each preset
                 is a submit button carrying the pinned `preset` field; no JS. --}}
            @if(Route::has('menu.starter.seed') && (int) $store->user_id === (int) auth()->id())
            <x-ui.card :title="___('Start from a template')" class="starter-menu">
                <p class="ui-orders-muted">{{ ___('Pick a menu to get going in seconds, then edit anything you like.') }}</p>
                <form method="post" action="{{ route('menu.starter.seed', $store) }}" class="ui-cluster ui-menu-starter">
                    @csrf
                    @foreach(\Juwri\Modules\Menu\Services\StarterMenuService::PRESETS as $preset)
                        <button type="submit" name="preset" value="{{ $preset }}" class="ui-chip">{{ ___(ucfirst($preset)) }}</button>
                    @endforeach
                </form>
            </x-ui.card>
            @endif
            <div class="restaurants-empty ui-card">
                <x-ui.empty :title="___('No categories yet')" :body="___('Create a category first, then add menu items under it.')" icon="book-open" />
            </div>
        @endforelse
    </section>

    {{-- Bulk actions: a dark floating bar while items are selected. --}}
    <div class="menu-bulkbar ui-menu-bulk" x-show="selected.length" x-transition x-cloak>
        <div class="ui-menu-bulk__inner">
            <span class="menu-bulkbar__count ui-menu-bulk__count" x-text="selected.length + ' {{ ___('selected') }}'"></span>
            <div class="menu-bulkbar__actions ui-menu-bulk__actions">
                <button type="button" class="ui-menu-bulk__btn" @click="bulk('activate')">{{ ___('Activate') }}</button>
                <button type="button" class="ui-menu-bulk__btn" @click="bulk('deactivate')">{{ ___('Deactivate') }}</button>
                <button type="button" class="ui-menu-bulk__btn" @click="bulk('duplicate')">{{ ___('Duplicate') }}</button>
                <select class="menu-bulkbar__move ui-menu-bulk__select" x-model="moveTarget" @change="if(moveTarget) bulk('move')">
                    <option value="">{{ ___('Move to…') }}</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
                {{-- D5: bulk price + tag ops --}}
                <span class="ui-menu-bulk__group">
                    <span class="ui-menu-bulk__label">{{ ___('Set price') }}</span>
                    <input type="number" step="0.001" min="0" class="ui-menu-bulk__input" x-model="bulkPrice" placeholder="0.00" aria-label="{{ ___('Set price') }}">
                    <button type="button" class="ui-menu-bulk__btn" @click="if(bulkPrice!=='') bulk('price_set', {price: bulkPrice})">{{ ___('Apply') }}</button>
                </span>
                <span class="ui-menu-bulk__group">
                    <span class="ui-menu-bulk__label">{{ ___('Adjust %') }}</span>
                    <input type="number" step="1" class="ui-menu-bulk__input" x-model="bulkPercent" placeholder="10" aria-label="{{ ___('Adjust %') }}">
                    <button type="button" class="ui-menu-bulk__btn" @click="if(bulkPercent!=='') window.dashConfirm(@js(___('Apply this percentage change to all selected prices?'))).then(ok => { if (ok) bulk('price_adjust_percent', {percent: bulkPercent}) })">{{ ___('Apply') }}</button>
                </span>
                <button type="button" class="ui-menu-bulk__btn" @click="bulk('tag_set', {tag: 'is_popular'})">{{ ___('Popular on') }}</button>
                <button type="button" class="ui-menu-bulk__btn" @click="bulk('tag_clear', {tag: 'is_popular'})">{{ ___('Popular off') }}</button>
                <button type="button" class="ui-menu-bulk__btn ui-menu-bulk__btn--danger" @click="bulk('delete')">{{ ___('Delete') }}</button>
                <button type="button" class="ui-menu-bulk__btn ui-menu-bulk__btn--ghost" @click="selected = []">{{ ___('Clear') }}</button>
            </div>
        </div>
    </div>

    <dialog class="menu-dialog ui-menu-dialog" x-ref="addItemDialog" @close="resetForm()" style="max-width:640px;width:94vw;">
        <form method="dialog" class="menu-dialog__shell">
            <header class="ui-menu-dialog__head">
                <div>
                    <p class="ui-overline" x-text="editId ? @js(___('Edit item')) : @js(___('New item'))">{{ ___('New item') }}</p>
                    <h3 class="ui-menu-dialog__title" x-text="editId ? @js(___('Edit Menu Item')) : @js(___('Add Menu Item'))">{{ ___('Add Menu Item') }}</h3>
                </div>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.addItemDialog.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" /></button>
            </header>
        </form>

        <form class="menu-dialog__body" enctype="multipart/form-data" x-ref="itemForm" @submit.prevent="submitItem($event)">
            <input type="hidden" name="category_id" :value="form.category_id">
            <input type="hidden" name="id" :value="editId">

            <div class="ui-form-grid">
                <div>
                    <label class="ui-label" for="item-name">{{ ___('Name') }} *</label>
                    <input class="ui-input" id="item-name" name="name" required>
                </div>
                <div>
                    <label class="ui-label" for="item-price">{{ ___('Price') }} *</label>
                    <input class="ui-input" id="item-price" name="price" type="number" step="0.01" required>
                </div>
            </div>
            <div class="ui-form-grid">
                <div>
                    <label class="ui-label" for="item-cost">{{ ___('Cost') }}</label>
                    <input class="ui-input" id="item-cost" name="cost" type="number" step="0.01" min="0" placeholder="{{ ___('e.g. food cost per item') }}">
                    <small class="ui-field__help">{{ ___('Used by the menu-engineering report to work out profit margin. Optional.') }}</small>
                </div>
            </div>
            <div>
                <label class="ui-label" for="item-description">{{ ___('Description') }}</label>
                <textarea class="ui-input" id="item-description" name="description" dir="auto"></textarea>
                @if(Route::has('stores.menu.aiDescribe'))
                {{-- P1-17 (J27-B): AI description draft. Fills the box only; the owner saves the item as usual. --}}
                <div class="ui-ai-draft">
                    <input type="text" class="ui-input ui-input--sm ui-ai-draft__notes" x-ref="aiDescNotes" maxlength="500" placeholder="{{ ___('Notes for the AI (optional), e.g. best seller, served hot') }}" aria-label="{{ ___('Notes for the AI (optional)') }}">
                    <select class="ui-input ui-input--sm ui-ai-draft__lang" x-ref="aiDescLang" data-ai-keep aria-label="{{ ___('Language') }}">
                        <option value="ar" lang="ar" @selected($aiCopyLang === 'ar')>العربية</option>
                        <option value="en" lang="en" @selected($aiCopyLang === 'en')>English</option>
                    </select>
                    <x-ui.button variant="secondary" size="sm" icon="sparkle" x-on:click="aiDescribe()" x-bind:aria-busy="aiBusy ? 'true' : 'false'" x-bind:disabled="aiBusy">{{ ___('Write with AI') }}</x-ui.button>
                </div>
                <small class="ui-field__help">{{ ___('The AI writes a draft from the item details. Check it, then save the item.') }}</small>
                @endif
            </div>

            <div class="ui-form-grid">
                <div>
                    <label class="ui-label" for="item-type">{{ ___('Type') }}</label>
                    <select class="ui-input" id="item-type" name="type">
                        <option value="veg">{{ ___('Veg') }}</option>
                        <option value="nonveg">{{ ___('Non Veg') }}</option>
                    </select>
                </div>
                <div>
                    <label class="ui-label" for="item-spice-level">{{ ___('Spice level') }} (0–3)</label>
                    <input class="ui-input" id="item-spice-level" name="spice_level" type="number" min="0" max="3" value="0">
                </div>
            </div>

            <div>
                <div class="ui-label ui-block">{{ ___('Image') }}</div>
                <input type="hidden" name="image" :value="mainImage.filename">
                <div class="ui-cluster image-pick">
                    <img class="image-pick__thumb" x-show="mainImage.url" :src="mainImage.url" alt="" x-cloak style="width:64px;height:64px;object-fit:cover;">
                    <button type="button" class="ui-cluster ui-btn ui-btn--secondary ui-btn--sm" @click="imageChoose()">
                        <x-ui.icon name="upload" size="16" />
                        <span x-text="mainImage.filename ? '{{ ___('Change image') }}' : '{{ ___('Choose from library') }}'"></span>
                    </button>
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-show="mainImage.filename" @click="imageRemove()" x-cloak>{{ ___('Remove') }}</button>
                </div>
            </div>

            <fieldset class="menu-dialog__fieldset ui-bordered">
                <legend class="ui-strong">{{ ___('Gallery (up to 5)') }}</legend>
                <div class="ui-cluster gallery-manager">
                    <template x-for="g in gallery" :key="g.filename">
                        <figure class="gallery-thumb ui-relative">
                            <img :src="g.url" alt="" loading="lazy" style="width:72px;height:72px;object-fit:cover;">
                            <button type="button" class="gallery-thumb__rm ui-icon-btn ui-icon-btn--ghost ui-absolute ui-top-0 ui-end-0" @click="galleryRemove(g.filename)" aria-label="{{ ___('Remove') }}"><x-ui.icon name="x" size="14" /></button>
                            <input type="hidden" name="gallery[]" :value="g.filename">
                        </figure>
                    </template>
                    <button type="button" class="ui-stack gallery-add ui-btn ui-btn--secondary" style="width:72px;height:72px;" x-show="gallery.length < 5" @click="galleryChoose()">
                        <x-ui.icon name="upload" size="18" />
                        <span class="ui-small">{{ ___('Add from library') }}</span>
                    </button>
                </div>
            </fieldset>

            <fieldset class="menu-dialog__fieldset ui-bordered">
                <legend class="ui-strong">{{ ___('Dietary') }}</legend>
                <div class="ui-cluster menu-dialog__checks">
                    <label class="ui-check"><input type="checkbox" name="is_vegan" value="1"><span>{{ ___('Vegan') }}</span></label>
                    <label class="ui-check"><input type="checkbox" name="is_halal" value="1"><span>{{ ___('Halal') }}</span></label>
                    <label class="ui-check"><input type="checkbox" name="is_gluten_free" value="1"><span>{{ ___('Gluten free') }}</span></label>
                    <label class="ui-check"><input type="checkbox" name="is_popular" value="1"><span>{{ ___('Popular') }}</span></label>
                    <label class="ui-check"><input type="checkbox" name="is_daily_special" value="1"><span>{{ ___('Daily special') }}</span></label>
                    <label class="ui-check"><input type="checkbox" name="is_sold_out" value="1"><span>{{ ___('Sold out (86)') }}</span></label>
                </div>
            </fieldset>

            <fieldset class="menu-dialog__fieldset ui-bordered">
                <legend class="ui-strong">{{ ___('Allergens') }}</legend>
                <div class="ui-cluster menu-dialog__checks" x-ref="allergenList">
                    @foreach(($allergies ?? collect()) as $a)
                        <label class="ui-cluster ui-inline ui-check"><input type="checkbox" name="allergies[]" value="{{ $a->id }}"><span>{{ $a->title }}</span>@if($a->store_id)<button type="button" class="ui-menu-x" @click.stop.prevent="deleteAllergen({{ $a->id }}, $event)" title="{{ ___('Delete allergen') }}" aria-label="{{ ___('Delete allergen') }}">×</button>@endif</label>
                    @endforeach
                </div>
                @if(!empty($menu_languages) && count($menu_languages) > 1 && $default_menu_language)
                {{-- Multi-language store: one input per menu language so an owner-added
                     allergen is stored per-locale (base = default language) and the chip
                     localises on the storefront, instead of the owner cramming languages
                     into one field. Only the default-language name is required. --}}
                <div style="max-width:360px;" x-ref="allergenAdd">
                    @foreach($menu_languages as $lang)
                        @php($isDefLang = $default_menu_language->code === $lang->code)
                        <div class="ui-input-group ui-input-group--sm">
                            <span class="ui-input-group__text" style="min-width:46px;justify-content:center;">{{ strtoupper($lang->code) }}</span>
                            <input type="text" class="ui-input allergen-lang-input" data-alang="{{ $lang->code }}"
                                   @if($isDefLang) x-ref="newAllergen" @endif maxlength="100"
                                   placeholder="{{ $isDefLang ? ___('Add your own allergen…') : ___('Translation (optional)') }}"
                                   @keydown.enter.prevent="addAllergen()">
                        </div>
                    @endforeach
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="addAllergen()">{{ ___('Add') }}</button>
                </div>
                @else
                <div class="ui-input-group ui-input-group--sm" style="max-width:320px;">
                    <input type="text" class="ui-input" x-ref="newAllergen" maxlength="100" placeholder="{{ ___('Add your own allergen…') }}" @keydown.enter.prevent="addAllergen()">
                    <button type="button" class="ui-btn ui-btn--secondary" @click="addAllergen()">{{ ___('Add') }}</button>
                </div>
                @endif
            </fieldset>

            {{-- P6-99b: the item's own VAT (used when the store has VAT per line on) --}}
            <fieldset class="menu-dialog__fieldset ui-bordered">
                <legend class="ui-strong">{{ ___('VAT (optional)') }}</legend>
                <div class="ui-form-grid">
                    <div><label class="ui-label" for="item-tax-rate">{{ ___('VAT rate') }}</label><input class="ui-input" id="item-tax-rate" name="tax_rate" type="number" min="0" max="100" step="0.01" placeholder="{{ ___('Store rate') }}"></div>
                    <div><label class="ui-label" for="item-tax-category">{{ ___('VAT type') }}</label>
                        <select class="ui-input" id="item-tax-category" name="tax_category">
                            <option value="">{{ ___('Standard') }}</option>
                            <option value="Z">{{ ___('Zero-rated') }}</option>
                            <option value="E">{{ ___('Exempt') }}</option>
                        </select>
                    </div>
                </div>
            </fieldset>

            <fieldset class="menu-dialog__fieldset ui-bordered">
                <legend class="ui-strong">{{ ___('Nutrition (optional)') }}</legend>
                <div class="ui-form-grid">
                    <div><label class="ui-label" for="item-calories">{{ ___('Calories') }}</label><input class="ui-input" id="item-calories" name="calories" type="number" min="0"></div>
                    <div><label class="ui-label" for="item-protein">{{ ___('Protein (g)') }}</label><input class="ui-input" id="item-protein" name="protein" type="number" min="0"></div>
                    <div><label class="ui-label" for="item-carbs">{{ ___('Carbs (g)') }}</label><input class="ui-input" id="item-carbs" name="carbs" type="number" min="0"></div>
                    <div><label class="ui-label" for="item-fat">{{ ___('Fat (g)') }}</label><input class="ui-input" id="item-fat" name="fat" type="number" min="0"></div>
                </div>
            </fieldset>

            <fieldset class="menu-dialog__fieldset ui-bordered">
                <legend class="ui-strong">{{ ___('Scheduled availability (optional)') }}</legend>
                <div class="ui-form-grid">
                    <div><label class="ui-label" for="item-available-from">{{ ___('From') }}</label><input class="ui-input" id="item-available-from" name="available_from" type="time"></div>
                    <div><label class="ui-label" for="item-available-to">{{ ___('To') }}</label><input class="ui-input" id="item-available-to" name="available_to" type="time"></div>
                </div>
                <div class="ui-cluster menu-dialog__checks">
                    @foreach(['mon'=>___('Mon'),'tue'=>___('Tue'),'wed'=>___('Wed'),'thu'=>___('Thu'),'fri'=>___('Fri'),'sat'=>___('Sat'),'sun'=>___('Sun')] as $d => $label)
                        <label class="ui-check"><input type="checkbox" name="available_days[]" value="{{ $d }}"><span>{{ $label }}</span></label>
                    @endforeach
                </div>
                {{-- PARITY-RUN T5: order-type scope — "dine-in only at lunch". --}}
                <div class="ui-field__help">{{ ___('Order types') }}</div>
                <div class="ui-cluster menu-dialog__checks">
                    @foreach(['on-table'=>___('Dine-in'),'takeaway'=>___('Takeaway'),'delivery'=>___('Delivery'),'room_service'=>___('Room service')] as $ot => $otLabel)
                        <label class="ui-check"><input type="checkbox" name="available_order_types[]" value="{{ $ot }}"><span>{{ $otLabel }}</span></label>
                    @endforeach
                </div>
                <div class="ui-field__help">{{ ___('No type selected means every order type.') }}</div>
            </fieldset>

            <fieldset class="menu-dialog__fieldset ui-bordered">
                <legend class="ui-strong">{{ ___('Kitchen (optional)') }}</legend>
                <div class="ui-form-grid">
                    <div><label class="ui-label" for="item-course">{{ ___('Course') }}</label><input class="ui-input" id="item-course" name="course" type="number" min="1" max="9" value="1"></div>
                    <div><label class="ui-label" for="item-prep-minutes">{{ ___('Prep time (min)') }}</label><input class="ui-input" id="item-prep-minutes" name="prep_minutes" type="number" min="0" max="240"></div>
                </div>
            </fieldset>

            <fieldset class="menu-dialog__fieldset ui-bordered">
                <legend class="ui-strong">{{ ___('Inventory (optional)') }}</legend>
                <label class="ui-check">
                    <input type="checkbox" name="track_inventory" value="1">
                    <span>{{ ___('Track inventory') }}</span>
                </label>
                <div class="ui-form-grid">
                    <div><label class="ui-label" for="item-stock-qty">{{ ___('Stock quantity') }}</label><input class="ui-input" id="item-stock-qty" name="stock_qty" type="number" min="0"></div>
                    <div><label class="ui-label" for="item-low-stock-threshold">{{ ___('Low stock alert') }}</label><input class="ui-input" id="item-low-stock-threshold" name="low_stock_threshold" type="number" min="0"></div>
                </div>
            </fieldset>

            <label class="ui-check">
                <input type="checkbox" name="active" value="1" checked>
                <span>{{ ___('Available') }}</span>
            </label>

            <div class="menu-dialog__actions ui-menu-dialog__foot">
                <button type="button" class="ui-btn ui-btn--secondary" @click="$refs.addItemDialog.close()">{{ ___('Cancel') }}</button>
                <button type="submit" class="ui-btn"><span class="ui-btn__icon"><x-ui.icon name="check" /></span>{{ ___('Save') }}</button>
            </div>
        </form>
    </dialog>

    @include('owner.menu.partials.tools', ['store' => $store])

    @include('owner.media._picker')

    {{-- Subcategory delete picker: shown when the owner deletes a category that
         still has sub-categories, so they choose rather than silently orphaning
         them. Backs MenuAdminService::deleteCategory's 'cascade' / 'reparent'. --}}
    {{-- x-show.important, NOT plain x-show: the overlay centers with Bootstrap
         .d-flex, which is `display:flex !important`. A plain x-show sets inline
         `display:none` (no !important), which .d-flex overrides — so the overlay
         never hid and popped on every page load (initial count 0 → the phantom
         "(0)" dialog). .important makes x-show write `display:none !important`,
         which wins, while d-flex still centers it when shown. --}}
    <div x-show.important="subDelete.open" x-cloak
         class="ui-cluster ui-cluster--center ui-fixed ui-top-0 ui-start-0 ui-w-full ui-fill"
         style="background:rgba(0,0,0,.45); z-index:1080;"
         @keydown.escape.window="subDelete.open = false"
         role="dialog" aria-modal="true" aria-labelledby="subDeleteTitle">
        <div class="ui-card" style="max-width:26rem; width:calc(100% - 2rem);" @click.outside="subDelete.open = false">
            <div class="ui-card__body">
                <h5 class="ui-card__title" id="subDeleteTitle" x-text="subDeleteText.title"></h5>
                <p class="ui-text-2"
                   x-text="subDeleteText.body + ' (' + subDelete.count + ')'"></p>
                <div class="ui-stack">
                    <button type="button" class="ui-btn ui-btn--danger" @click="confirmSubDelete('cascade')"
                            x-text="subDeleteText.cascade"></button>
                    <button type="button" class="ui-btn ui-btn--secondary" @click="confirmSubDelete('reparent')"
                            x-text="subDeleteText.reparent"></button>
                    <button type="button" class="ui-btn ui-btn--secondary" @click="subDelete.open = false"
                            x-text="subDeleteText.cancel"></button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    window.linenMenuIndex = function (config) {
        return {
            query: '',
            selected: [],
            moveTarget: '',
            bulkPrice: '',
            bulkPercent: '',
            subDelete: { open: false, id: null, count: 0 },
            // config is a closure var, not on the instance, so expose the modal's
            // strings as state for x-text to reach.
            subDeleteText: {
                title: config.subDeleteTitle,
                body: config.subDeleteBody,
                cascade: config.subDeleteCascade,
                reparent: config.subDeleteReparent,
                cancel: config.subDeleteCancel,
            },
            gallery: [],
            mainImage: { filename: '', url: '' },
            pendingQuickImage: null,
            init: function () {
                var self = this;
                window.addEventListener('media-picker:picked', function (e) {
                    if (e.detail.target === 'item-quickimg') {
                        var a = e.detail.assets[0];
                        if (a && self.pendingQuickImage) self._saveQuickImage(a.filename);
                        return;
                    }
                    if (e.detail.target === 'item-image') {
                        var a = e.detail.assets[0];
                        if (a) self.mainImage = { filename: a.filename, url: a.url };
                        return;
                    }
                    if (e.detail.target !== 'item-gallery') return;
                    e.detail.assets.forEach(function (a) {
                        if (self.gallery.length >= 5) return;
                        if (self.gallery.some(function (g) { return g.filename === a.filename; })) return;
                        self.gallery.push({ filename: a.filename, url: a.url });
                    });
                });
            },
            imageChoose: function () {
                window.openMediaPicker({ target: 'item-image', storeId: config.storeId, folder: 'menu', multiple: false });
            },
            imageRemove: function () {
                this.mainImage = { filename: '', url: '' };
            },
            quickImage: function (item) {
                this.pendingQuickImage = item;
                window.openMediaPicker({ target: 'item-quickimg', storeId: config.storeId, folder: 'menu', multiple: false });
            },
            _saveQuickImage: function (filename) {
                var item = this.pendingQuickImage;
                if (!item || !config.quickImageUrl) return;
                var fd = new FormData();
                fd.append('id', item.id);
                fd.append('image', filename);
                var img = document.getElementById('menu-row-img-' + item.id);
                fetch(config.quickImageUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token }, body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) { if (img && data.url) { img.src = data.url; } }
                        else { window.dashToast(data.message || config.failedText, { type: 'error' }); }
                    })
                    .catch(function () { window.dashToast(config.failedText, { type: 'error' }); });
                this.pendingQuickImage = null;
            },
            // inline quick-edit of a single field (name/price); returns a promise
            quickSaveField: function (id, field, value) {
                if (!config.quickEditUrl) return Promise.resolve({ ok: false });
                var fd = new FormData();
                fd.append('id', id);
                fd.append(field, value);
                return fetch(config.quickEditUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token }, body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) { window.dashToast(data.message || config.failedText, { type: 'error' }); return { ok: false }; }
                        return { ok: true, name: data.name, price_formatted: data.price_formatted };
                    })
                    .catch(function () { window.dashToast(config.failedText, { type: 'error' }); return { ok: false }; });
            },
            addAllergen: function () {
                var input = this.$refs.newAllergen;
                var title = (input.value || '').trim();
                if (!title || !config.allergenUrl) return;
                var self = this;
                var fd = new FormData();
                fd.append('title', title);
                /* per-locale translations from the multi-language add row (default
                   language is the base title, so it is skipped here) */
                if (self.$refs.allergenAdd) {
                    self.$refs.allergenAdd.querySelectorAll('.allergen-lang-input[data-alang]').forEach(function (el) {
                        if (el === input) return;
                        var code = el.getAttribute('data-alang');
                        var val = (el.value || '').trim();
                        if (code && val) fd.append('translations[' + code + '][title]', val);
                    });
                }
                fetch(config.allergenUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token, 'Accept': 'application/json' }, body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.success) { window.dashToast(d.message || config.failedText, { type: 'error' }); return; }
                        var list = self.$refs.allergenList;
                        var cb = list.querySelector('[name="allergies[]"][value="' + d.id + '"]');
                        if (!cb) {
                            var label = document.createElement('label');
                            label.className = 'ui-cluster ui-inline ui-check';
                            var box = document.createElement('input');
                            box.type = 'checkbox'; box.name = 'allergies[]'; box.value = d.id;
                            var span = document.createElement('span'); span.textContent = d.title;
                            var del = document.createElement('button');
                            del.type = 'button'; del.className = 'ui-menu-x'; del.textContent = '×';
                            del.title = '{{ ___('Delete allergen') }}'; del.setAttribute('aria-label', del.title);
                            del.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); self.deleteAllergen(d.id, e); });
                            label.appendChild(box); label.appendChild(span); label.appendChild(del); list.appendChild(label);
                            cb = box;
                        }
                        cb.checked = true;
                        input.value = '';
                        if (self.$refs.allergenAdd) self.$refs.allergenAdd.querySelectorAll('.allergen-lang-input').forEach(function (el) { el.value = ''; });
                    })
                    .catch(function () { window.dashToast(config.failedText, { type: 'error' }); });
            },
            deleteAllergen: async function (id, ev) {
                if (!config.allergenUrl) return;
                if (!(await window.dashConfirm({ body: config.deleteAllergenConfirm || 'Delete?', danger: true }))) return;
                var label = ev.target.closest('label');
                fetch(config.allergenUrl + '/' + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': config.token, 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.success) { if (label) label.remove(); } else { window.dashToast(d.message || config.failedText, { type: 'error' }); } })
                    .catch(function () { window.dashToast(config.failedText, { type: 'error' }); });
            },
            galleryChoose: function () {
                window.openMediaPicker({ target: 'item-gallery', storeId: config.storeId, folder: 'menu', multiple: true });
            },
            galleryRemove: function (filename) {
                this.gallery = this.gallery.filter(function (g) { return g.filename !== filename; });
            },
            toggleSelect: function (id) {
                var i = this.selected.indexOf(id);
                if (i === -1) { this.selected.push(id); } else { this.selected.splice(i, 1); }
            },
            bulk: async function (action, extra) {
                if (!this.selected.length || !config.bulkUrl) return;
                if (action === 'delete' && !(await window.dashConfirm({ body: config.bulkDeleteConfirm, danger: true }))) return;
                var payload = new FormData();
                payload.append('action', action);
                this.selected.forEach(function (id) { payload.append('ids[]', id); });
                if (action === 'move') { payload.append('category_id', this.moveTarget); }
                // D5: bulk price + tag params
                if (extra) { Object.keys(extra).forEach(function (k) { payload.append(k, extra[k]); }); }
                var self = this;
                fetch(config.bulkUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token }, body: payload })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.success) { window.dashToast(d.message || config.failedText, { type: 'error' }); return; }
                        // D4: a soft delete offers an Undo before reloading.
                        if (action === 'delete' && d.deleted_ids && d.deleted_ids.length && config.restoreUrl) {
                            self._offerUndo(d.deleted_ids);
                        } else {
                            window.location.reload();
                        }
                    })
                    .catch(function () { window.dashToast(config.failedText, { type: 'error' }); });
            },
            _offerUndo: function (ids) {
                var bar = document.createElement('div');
                bar.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:1080;background:#212529;color:#fff;padding:10px 16px;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.3);display:flex;gap:14px;align-items:center;font-size:.9rem;';
                bar.innerHTML = '<span>' + ids.length + ' ' + config.undoText + '</span>';
                var btn = document.createElement('button');
                btn.textContent = config.undoAction;
                btn.style.cssText = 'background:none;border:none;color:#8ab4f8;font-weight:600;cursor:pointer;';
                bar.appendChild(btn);
                document.body.appendChild(bar);
                var done = false;
                var reload = function () { if (!done) { done = true; window.location.reload(); } };
                var t = setTimeout(reload, 6000);
                btn.addEventListener('click', function () {
                    clearTimeout(t);
                    var fd = new FormData();
                    ids.forEach(function (id) { fd.append('ids[]', id); });
                    fetch(config.restoreUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token, 'Accept': 'application/json' }, body: fd })
                        .then(function () { reload(); })
                        .catch(function () { reload(); });
                });
            },
            editId: '',
            form: {
                category_id: config.defaultCategory || ''
            },
            matches: function (text) {
                var query = this.query.trim().toLowerCase();
                return !query || String(text || '').toLowerCase().indexOf(query) !== -1;
            },
            _resetFields: function () {
                var f = this.$refs.itemForm;
                if (!f) return;
                f.querySelectorAll('input, textarea, select').forEach(function (el) {
                    if (el.name === 'category_id' || el.name === 'id') return;
                    if (el.hasAttribute('data-ai-keep')) return; // P1-17: the AI language pick stays as chosen
                    if (el.type === 'checkbox') { el.checked = (el.name === 'active'); }
                    else if (el.type !== 'hidden') { el.value = (el.name === 'type') ? 'veg' : (el.name === 'spice_level' ? '0' : ''); }
                });
                var desc = f.querySelector('[name="description"]');
                if (desc) { desc.setAttribute('dir', 'auto'); desc.removeAttribute('lang'); }
            },
            openAddItem: function (categoryId) {
                this.editId = '';
                this.form.category_id = categoryId;
                this.gallery = [];
                this.mainImage = { filename: '', url: '' };
                this.$nextTick(function () { this._resetFields(); }.bind(this));
                this.$refs.addItemDialog.showModal();
            },
            openEditItem: function (data) {
                this.editId = data.id;
                this.form.category_id = data.category_id;
                this.gallery = Array.isArray(data.gallery) ? data.gallery.slice(0, 5) : [];
                this.mainImage = (data.image && data.image_url) ? { filename: data.image, url: data.image_url } : { filename: '', url: '' };
                var f = this.$refs.itemForm;
                this.$nextTick(function () {
                    this._resetFields();
                    var setVal = function (name, val) { var el = f.querySelector('[name="' + name + '"]'); if (el) el.value = (val === null || val === undefined) ? '' : val; };
                    var setChk = function (name, val) { var el = f.querySelector('[name="' + name + '"]'); if (el) el.checked = (val == 1 || val === true); };
                    setVal('name', data.name); setVal('price', data.price); setVal('cost', data.cost); setVal('description', data.description);
                    setVal('type', data.type || 'veg'); setVal('spice_level', data.spice_level || 0);
                    setVal('tax_rate', data.tax_rate); setVal('tax_category', data.tax_category || '');
                    setVal('calories', data.calories); setVal('protein', data.protein); setVal('carbs', data.carbs); setVal('fat', data.fat);
                    // TIME column returns "HH:MM:SS"; <input type=time> needs "HH:MM"
                    var hm = function (t) { return t ? String(t).slice(0, 5) : ''; };
                    setVal('available_from', hm(data.available_from)); setVal('available_to', hm(data.available_to));
                    // restore scheduled-availability day checkboxes from CSV ("sun,mon")
                    var days = String(data.available_days || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                    Array.prototype.forEach.call(f.querySelectorAll('[name="available_days[]"]'), function (cb) { cb.checked = days.indexOf(cb.value) !== -1; });
                    // T5: restore order-type scope from CSV ("on-table,delivery")
                    var otypes = String(data.available_order_types || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                    Array.prototype.forEach.call(f.querySelectorAll('[name="available_order_types[]"]'), function (cb) { cb.checked = otypes.indexOf(cb.value) !== -1; });
                    // restore allergen checkboxes from CSV of ids ("2,5")
                    var allg = String(data.allergies || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                    Array.prototype.forEach.call(f.querySelectorAll('[name="allergies[]"]'), function (cb) { cb.checked = allg.indexOf(cb.value) !== -1; });
                    setChk('is_vegan', data.is_vegan); setChk('is_halal', data.is_halal); setChk('is_gluten_free', data.is_gluten_free);
                    setChk('is_popular', data.is_popular); setChk('is_daily_special', data.is_daily_special); setChk('active', data.active);
                    setChk('is_sold_out', data.is_sold_out);
                    setChk('track_inventory', data.track_inventory); setVal('stock_qty', data.stock_qty); setVal('low_stock_threshold', data.low_stock_threshold);
                    setVal('course', data.course || 1); setVal('prep_minutes', data.prep_minutes);
                    this.$refs.addItemDialog.showModal();
                }.bind(this));
            },
            resetForm: function () {
                this.editId = '';
                this._resetFields();
            },
            // P1-17 (J27-B): ask for an AI description draft; it only fills the textarea (never saves).
            aiBusy: false,
            aiDescribe: function () {
                var f = this.$refs.itemForm;
                if (!f || !config.aiDescribeUrl || this.aiBusy) return;
                var name = (f.querySelector('[name="name"]').value || '').trim();
                if (!name) { window.dashToast(config.aiNeedName, { type: 'error' }); return; }
                var fd = new FormData();
                if (this.editId) fd.append('item_id', this.editId);
                fd.append('name', name);
                if (this.form.category_id) fd.append('category_id', this.form.category_id);
                fd.append('notes', this.$refs.aiDescNotes ? this.$refs.aiDescNotes.value : '');
                fd.append('lang', this.$refs.aiDescLang ? this.$refs.aiDescLang.value : '');
                var self = this;
                self.aiBusy = true;
                fetch(config.aiDescribeUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token, 'Accept': 'application/json' }, body: fd })
                    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d || {} }; }); })
                    .then(function (res) {
                        if (res.ok && res.d.success) {
                            var box = f.querySelector('[name="description"]');
                            box.value = res.d.description;
                            box.setAttribute('dir', res.d.dir || 'auto');
                            box.setAttribute('lang', res.d.lang || '');
                            window.dashToast(config.aiDraftReady, { type: 'success' });
                            return;
                        }
                        window.dashToast(res.d.error || res.d.message || config.aiFailed, { type: 'error' });
                    })
                    .catch(function () { window.dashToast(config.aiFailed, { type: 'error' }); })
                    .finally(function () { self.aiBusy = false; });
            },
            submitItem: function (event) {
                var payload = new FormData(event.target);
                payload.set('category_id', this.form.category_id);
                var url = this.editId ? config.editItemUrl : config.addItemUrl;
                if (this.editId) { payload.set('id', this.editId); }

                fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': config.token },
                    body: payload
                }).then(function (response) {
                    return response.json();
                }).then(function (data) {
                    if (data.success) {
                        window.location.reload();
                        return;
                    }
                    window.dashToast(data.message || config.failedText, { type: 'error' });
                }).catch(function () {
                    window.dashToast(config.failedText, { type: 'error' });
                });
            },
            deleteItem: async function (id) {
                if (!config.deleteItemUrl) return;
                if (!(await window.dashConfirm({ body: config.itemDeleteConfirm, danger: true }))) return;
                var payload = new FormData();
                payload.append('id', id);
                fetch(config.deleteItemUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token }, body: payload })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.success) { window.location.reload(); } else { window.dashToast(d.message || config.failedText, { type: 'error' }); } })
                    .catch(function () { window.dashToast(config.failedText, { type: 'error' }); });
            },
            deleteCategory: async function (categoryId) {
                if (!(await window.dashConfirm({ body: config.deleteConfirmText, danger: true }))) {
                    return;
                }
                this._postDeleteCategory(categoryId, null);
            },
            // Second leg of a subcategory delete: the owner picked from the modal.
            confirmSubDelete: function (mode) {
                var id = this.subDelete.id;
                this.subDelete = { open: false, id: null, count: 0 };
                this._postDeleteCategory(id, mode);
            },
            _postDeleteCategory: function (categoryId, mode) {
                var self = this;
                var payload = new FormData();
                payload.append('id', categoryId);
                if (mode) {
                    payload.append('mode', mode);
                }

                fetch(config.deleteCategoryUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': config.token },
                    body: payload
                }).then(function (response) {
                    return response.json();
                }).then(function (data) {
                    if (data.success) {
                        window.location.reload();
                        return;
                    }
                    // The category has subcategories and no mode was chosen yet —
                    // open the picker instead of silently orphaning them.
                    if (data.needs_choice) {
                        self.subDelete = { open: true, id: categoryId, count: data.subcategories || 0 };
                        return;
                    }
                    window.dashToast(data.message || config.failedText, { type: 'error' });
                }).catch(function () {
                    window.dashToast(config.failedText, { type: 'error' });
                });
            },
            switchLanguage: function (code) {
                document.cookie = 'Quick_user_lang_code=' + encodeURIComponent(code) + '; path=/';
                window.location.reload();
            },
            _dragEl: null,
            _dragType: null,
            _dragContainer: null,
            dragStart: function (event, type) {
                this._dragEl = event.target.closest('[data-drag-id]');
                this._dragType = type;
                this._dragContainer = this._dragEl.parentNode;
                this._dragEl.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', '');
            },
            dragOver: function (event, type) {
                if (this._dragType !== type) return;
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                var target = event.target.closest('[data-drag-id]');
                if (!target || target === this._dragEl || target.parentNode !== this._dragContainer) return;
                var rect = target.getBoundingClientRect();
                var mid = rect.top + rect.height / 2;
                if (event.clientY < mid) {
                    this._dragContainer.insertBefore(this._dragEl, target);
                } else {
                    this._dragContainer.insertBefore(this._dragEl, target.nextSibling);
                }
            },
            dragEnd: function (event, type) {
                if (!this._dragEl) return;
                this._dragEl.classList.remove('is-dragging');
                var container = this._dragContainer;
                var ids = Array.from(container.querySelectorAll(':scope > [data-drag-id]')).map(function (el) { return el.getAttribute('data-drag-id'); });
                var url = type === 'category' ? config.reorderCategoryUrl : config.reorderItemUrl;
                if (!url) { this._dragEl = null; return; }
                var payload = new FormData();
                ids.forEach(function (id) { payload.append('position[]', id); });
                fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token }, body: payload })
                    .catch(function () {});
                this._dragEl = null;
                this._dragType = null;
                this._dragContainer = null;
            }
        };
    };

    /* D8: command-palette / permalink focus — ?item=ID scrolls to and briefly
       highlights the item row (mirrors D2's ?order= on the orders page). */
    (function () {
        var params = new URLSearchParams(window.location.search);
        var itemId = params.get('item');
        if (!itemId) { return; }
        window.addEventListener('load', function () {
            var el = document.getElementById('menu-item-' + itemId);
            if (!el) { return; }
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.add('ui-focus-flash');
            el.style.boxShadow = '0 0 0 3px rgba(13,110,253,.35)';
            setTimeout(function () { el.style.boxShadow = ''; }, 3000);
        });
    })();
</script>
@endpush
