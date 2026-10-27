{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', $menu->name.' - '.$store->title)
@section('page_title', ___('Extras & Variants'))
@section('page_sub', $menu->name)

@section('page_actions')
    <x-ui.button :href="route('stores.menu', $store->id)" variant="secondary" icon="back">
        {{ ___('Back') }}
    </x-ui.button>
@endsection

@section('content')
@php
    $variantOptionCount = $menu->variantOptions->count();
    $variantCount = count($variants);
    $extraCount = $menu->extras->count();
@endphp

<div
    class="extras-page ui-scope ui-extras"
    x-data="linenExtrasPage({
        token: @js(csrf_token()),
        failedText: @js(___('Unable to save changes.')),
        deleteConfirmText: @js(___('Delete this item?'))
    })"
>
    <section class="extras-summary ui-card">
        <div class="ui-cluster ui-cluster--between ui-card__body">
            <div>
                <p class="ui-muted ui-caps">{{ ___('Menu item') }}</p>
                <h2 class="ui-section-title">{{ $menu->name }}</h2>
                <span class="ui-text-2">{{ ___('Build purchasable choices, prices, and add-ons for this item.') }}</span>
            </div>
            <div class="ui-cluster extras-summary__stats ui-center">
                <div>
                    <span class="ui-muted ui-block">{{ ___('Options') }}</span>
                    <strong class="ui-fs-lg">{{ number_format($variantOptionCount) }}</strong>
                </div>
                <div>
                    <span class="ui-muted ui-block">{{ ___('Variants') }}</span>
                    <strong class="ui-fs-lg">{{ number_format($variantCount) }}</strong>
                </div>
                <div>
                    <span class="ui-muted ui-block">{{ ___('Extras') }}</span>
                    <strong class="ui-fs-lg">{{ number_format($extraCount) }}</strong>
                </div>
            </div>
        </div>
    </section>

    @if(!empty($menu_languages) && $default_menu_language)
        <section class="extras-language ui-card">
            <div class="ui-cluster ui-card__body">
                <span class="ui-muted">{{ ___('Editing language') }}</span>
                <div class="ui-extras__langs" role="group">
                    @foreach($menu_languages as $lang)
                        <button
                            type="button"
                            class="ui-chip {{ $default_menu_language->code === $lang->code ? 'is-active' : '' }}" aria-pressed="{{ $default_menu_language->code === $lang->code ? 'true' : 'false' }}"
                            @click="switchLanguage(@js($lang->code))"
                        >
                            {{ strtoupper($lang->code) }}
                        </button>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="extras-grid ui-form-grid">
        <article class="extras-panel">
            <div class="ui-fill ui-card">
                <div class="ui-card__body">
                    <header class="ui-cluster ui-cluster--between ui-cluster--top">
                        <div>
                            <p class="ui-muted ui-caps">{{ ___('Step 1') }}</p>
                            <h3 class="ui-section-title">{{ ___('Variant Options') }}</h3>
                            <span class="ui-muted">{{ ___('Create option groups like size, crust, or spice level.') }}</span>
                        </div>
                        <x-ui.tag>{{ ___(':count groups', ['count' => number_format($variantOptionCount)]) }}</x-ui.tag>
                    </header>

                    <details class="extras-create">
                        <summary class="ui-cluster ui-inline ui-small">
                            <x-ui.icon name="plus" size="14" />
                            <span>{{ ___('Add Variant Option') }}</span>
                        </summary>
                        <form method="post" action="{{ route('stores.menuAddVariantOption', ['store' => $store->id, 'menu' => $menu->id]) }}" class="extras-form">
                            @csrf
                            <div class="edit-field">
                                <label class="ui-label" for="vo-title">{{ ___('Title') }}</label>
                                <input class="ui-input" id="vo-title" name="title" placeholder="{{ ___('Title (Ex. Size)') }}" required>
                            </div>
                            <div class="edit-field">
                                <label class="ui-label" for="vo-options">{{ ___('Options') }}</label>
                                <input class="ui-input" id="vo-options" name="options" placeholder="{{ ___('Options (Ex. Small, Medium, Large)') }}" required>
                                <small class="ui-field__help">{{ ___('Enter options separated by comma.') }}</small>
                            </div>
                            <x-ui.button type="submit" size="sm" icon="check">
                                {{ ___('Save') }}
                            </x-ui.button>
                        </form>
                    </details>

                    <div class="ui-stack extras-list">
                        @forelse($menu->variantOptions as $option)
                            @php
                                $optionValues = collect($option->options ?? []);
                            @endphp
                            <article class="extras-row ui-bordered">
                                <header class="ui-cluster ui-cluster--between ui-cluster--top">
                                    <div>
                                        <strong class="ui-block">{{ $option->title }}</strong>
                                        <span class="ui-muted">{{ $optionValues->implode(', ') }}</span>
                                    </div>
                                    <div class="ui-cluster extras-row__actions">
                                        <x-ui.pill :tone="$option->active ? 'success' : 'neutral'">
                                            {{ $option->active ? ___('Available') : ___('Unavailable') }}
                                        </x-ui.pill>
                                        <button type="button" class="ui-icon-btn ui-menu-item__delete" @click="deleteRecord(@js(route('stores.menuDeleteVariantOption', ['store' => $store->id, 'menu' => $menu->id])), {{ $option->id }})" aria-label="{{ ___('Delete') }}">
                                            <x-ui.icon name="trash" size="16" />
                                        </button>
                                    </div>
                                </header>
                                <details>
                                    <summary class="ui-small">{{ ___('Edit') }}</summary>
                                    <form method="post" action="{{ route('stores.menuUpdateVariantOption', ['store' => $store->id, 'menu' => $menu->id]) }}" class="extras-form" @submit.prevent="submitAjax($event.target)">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $option->id }}">
                                        <div class="edit-field">
                                            <label class="ui-label" for="vo-title-{{ $option->id }}">{{ ___('Title') }}</label>
                                            <input class="ui-input" id="vo-title-{{ $option->id }}" name="title" value="{{ $option->title }}" required>
                                        </div>
                                        <div class="edit-field">
                                            <label class="ui-label" for="vo-options-{{ $option->id }}">{{ ___('Options') }}</label>
                                            <input class="ui-input" id="vo-options-{{ $option->id }}" name="options" value="{{ $optionValues->implode(', ') }}" required>
                                        </div>
                                        @php $optImages = $option->option_images ?? []; @endphp
                                        <div class="edit-field">
                                            <span class="ui-label ui-block">{{ ___('Option images') }}</span>
                                            <div class="ui-stack variant-img-list">
                                                @foreach($optionValues->values() as $i => $val)
                                                    @php $imgFile = $optImages[$i] ?? null; $imgUrl = $imgFile ? media_url('storage/menu/', $imgFile, $store->id) : ''; @endphp
                                                    <div class="ui-cluster variant-img-row" x-data="imagePickerField({ target: 'optimg-{{ $option->id }}-{{ $i }}', storeId: {{ $store->id }}, folder: 'variant', initial: @js($imgFile), url: @js($imgUrl) })">
                                                        <span class="variant-img-row__label ui-small ui-grow">{{ $val }}</span>
                                                        <img class="image-pick__thumb" x-show="url" :src="url" alt="" x-cloak style="width:36px;height:36px;object-fit:cover;">
                                                        <input type="hidden" name="option_images[]" :value="filename">
                                                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()">{{ ___('Image') }}</button>
                                                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                        <label class="ui-check">
                                            <input type="checkbox" name="active" value="1" @checked($option->active)>
                                            <span>{{ ___('Available') }}</span>
                                        </label>
                                        <x-ui.button type="submit" size="sm" icon="check">
                                            {{ ___('Save') }}
                                        </x-ui.button>
                                    </form>
                                </details>
                            </article>
                        @empty
                            <div class="ui-muted menu-items-empty">{{ ___('No items available.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </article>

        <article class="extras-panel">
            <div class="ui-fill ui-card">
                <div class="ui-card__body">
                    <header class="ui-cluster ui-cluster--between ui-cluster--top">
                        <div>
                            <p class="ui-muted ui-caps">{{ ___('Step 2') }}</p>
                            <h3 class="ui-section-title">{{ ___('Variants') }}</h3>
                            <span class="ui-muted">{{ ___('Combine option choices with final prices.') }}</span>
                        </div>
                        <x-ui.tag>{{ ___(':count variants', ['count' => number_format($variantCount)]) }}</x-ui.tag>
                    </header>

                    <details class="extras-create">
                        <summary class="ui-cluster ui-inline ui-small">
                            <x-ui.icon name="plus" size="14" />
                            <span>{{ ___('Add Variant') }}</span>
                        </summary>
                        @if($variantOptionCount)
                            <form method="post" action="{{ route('stores.menuAddVariant', ['store' => $store->id, 'menu' => $menu->id]) }}" class="extras-form">
                                @csrf
                                @foreach($menu->variantOptions as $option)
                                    <div class="edit-field">
                                        <label class="ui-label" for="v-opt-{{ $option->id }}">{{ $option->title }}</label>
                                        <select class="ui-input" id="v-opt-{{ $option->id }}" name="options[{{ $option->id }}]" required>
                                            @foreach($option->options as $key => $value)
                                                <option value="{{ $key }}">{{ $value }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endforeach
                                <div class="edit-field">
                                    <label class="ui-label" for="v-price">{{ ___('Price') }}</label>
                                    <input class="ui-input" id="v-price" type="number" step="0.01" name="price" required>
                                </div>
                                <x-ui.button type="submit" size="sm" icon="check">
                                    {{ ___('Save') }}
                                </x-ui.button>
                            </form>
                        @else
                            <div class="ui-muted menu-items-empty">{{ ___('Please create a variant option first.') }}</div>
                        @endif
                    </details>

                    <div class="ui-stack extras-list">
                        @forelse($variants as $variant)
                            <article class="extras-row ui-bordered">
                                <header class="ui-cluster ui-cluster--between ui-cluster--top">
                                    <div>
                                        <strong class="ui-block">{{ $variant->title ?: ___('Variant') }}</strong>
                                        <span class="ui-muted">{{ price_symbol_format($variant->price) }}</span>
                                    </div>
                                    <div class="ui-cluster extras-row__actions">
                                        <x-ui.pill :tone="$variant->active ? 'success' : 'neutral'">
                                            {{ $variant->active ? ___('Available') : ___('Unavailable') }}
                                        </x-ui.pill>
                                        <button type="button" class="ui-icon-btn ui-menu-item__delete" @click="deleteRecord(@js(route('stores.menuDeleteVariant', ['store' => $store->id, 'menu' => $menu->id])), {{ $variant->id }})" aria-label="{{ ___('Delete') }}">
                                            <x-ui.icon name="trash" size="16" />
                                        </button>
                                    </div>
                                </header>
                                <details>
                                    <summary class="ui-small">{{ ___('Edit') }}</summary>
                                    <form method="post" action="{{ route('stores.menuUpdateVariant', ['store' => $store->id, 'menu' => $menu->id]) }}" class="extras-form" @submit.prevent="submitAjax($event.target)">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $variant->id }}">
                                        @foreach($menu->variantOptions as $option)
                                            @php
                                                $selectedOption = data_get($variant->options, (string) $option->id);
                                            @endphp
                                            <div class="edit-field">
                                                <label class="ui-label" for="v-opt-{{ $variant->id }}-{{ $option->id }}">{{ $option->title }}</label>
                                                <select class="ui-input" id="v-opt-{{ $variant->id }}-{{ $option->id }}" name="options[{{ $option->id }}]" required>
                                                    @foreach($option->options as $key => $value)
                                                        <option value="{{ $key }}" @selected((string) $selectedOption === (string) $key)>{{ $value }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endforeach
                                        <div class="edit-field">
                                            <label class="ui-label" for="v-price-{{ $variant->id }}">{{ ___('Price') }}</label>
                                            <input class="ui-input" id="v-price-{{ $variant->id }}" type="number" step="0.01" name="price" value="{{ $variant->price }}" required>
                                        </div>
                                        <label class="ui-check">
                                            <input type="checkbox" name="active" value="1" @checked($variant->active)>
                                            <span>{{ ___('Available') }}</span>
                                        </label>
                                        <x-ui.button type="submit" size="sm" icon="check">
                                            {{ ___('Save') }}
                                        </x-ui.button>
                                    </form>
                                </details>
                            </article>
                        @empty
                            <div class="ui-muted menu-items-empty">{{ ___('No items available.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </article>

        <article class="extras-panel">
            <div class="ui-fill ui-card">
                <div class="ui-card__body">
                    <header class="ui-cluster ui-cluster--between ui-cluster--top">
                        <div>
                            <p class="ui-muted ui-caps">{{ ___('Step 3') }}</p>
                            <h3 class="ui-section-title">{{ ___('Extras') }}</h3>
                            <span class="ui-muted">{{ ___('Offer add-ons that can be selected with the item.') }}</span>
                        </div>
                        <x-ui.tag>{{ ___(':count extras', ['count' => number_format($extraCount)]) }}</x-ui.tag>
                    </header>

                    <details class="extras-create">
                        <summary class="ui-cluster ui-inline ui-small">
                            <x-ui.icon name="plus" size="14" />
                            <span>{{ ___('Add Extra') }}</span>
                        </summary>
                        <form method="post" action="{{ route('stores.menuAddExtra', ['store' => $store->id, 'menu' => $menu->id]) }}" class="extras-form"
                              x-data="imagePickerField({ target: 'extra-add', storeId: {{ $store->id }}, folder: 'extra' })">
                            @csrf
                            <div class="edit-field">
                                <label class="ui-label" for="ex-title">{{ ___('Title') }}</label>
                                <input class="ui-input" id="ex-title" name="title" placeholder="{{ ___('Title') }}" required>
                            </div>
                            <div class="edit-field">
                                <label class="ui-label" for="ex-price">{{ ___('Price') }}</label>
                                <input class="ui-input" id="ex-price" type="number" step="0.01" name="price" placeholder="{{ ___('Price') }}" required>
                            </div>
                            <input type="hidden" name="image" :value="filename">
                            <div class="ui-cluster image-pick">
                                <img class="image-pick__thumb" x-show="url" :src="url" alt="" x-cloak style="width:36px;height:36px;object-fit:cover;">
                                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()">{{ ___('Image') }}</button>
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
                            </div>
                            <x-ui.button type="submit" size="sm" icon="check">
                                {{ ___('Save') }}
                            </x-ui.button>
                        </form>
                    </details>

                    <div class="ui-stack extras-list">
                        @forelse($menu->extras as $extra)
                            <article class="extras-row ui-bordered">
                                <header class="ui-cluster ui-cluster--between ui-cluster--top">
                                    <div class="ui-cluster extras-row__title">
                                        @if($extra->image)<img class="extras-row__thumb" src="{{ $extra->image_url }}" alt="" loading="lazy" style="width:36px;height:36px;object-fit:cover;">@endif
                                        <div>
                                            <strong class="ui-block">{{ $extra->title }}</strong>
                                            <span class="ui-muted">{{ price_symbol_format($extra->price) }}</span>
                                        </div>
                                    </div>
                                    <div class="ui-cluster extras-row__actions">
                                        <x-ui.pill :tone="$extra->active ? 'success' : 'neutral'">
                                            {{ $extra->active ? ___('Available') : ___('Unavailable') }}
                                        </x-ui.pill>
                                        <button type="button" class="ui-icon-btn ui-menu-item__delete" @click="deleteRecord(@js(route('stores.menuDeleteExtra', ['store' => $store->id, 'menu' => $menu->id])), {{ $extra->id }})" aria-label="{{ ___('Delete') }}">
                                            <x-ui.icon name="trash" size="16" />
                                        </button>
                                    </div>
                                </header>
                                <details>
                                    <summary class="ui-small">{{ ___('Edit') }}</summary>
                                    <form method="post" action="{{ route('stores.menuUpdateExtra', ['store' => $store->id, 'menu' => $menu->id]) }}" class="extras-form" @submit.prevent="submitAjax($event.target)"
                                          x-data="imagePickerField({ target: 'extra-{{ $extra->id }}', storeId: {{ $store->id }}, folder: 'extra', initial: @js($extra->image), url: @js($extra->image_url) })">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $extra->id }}">
                                        <div class="edit-field">
                                            <label class="ui-label" for="ex-title-{{ $extra->id }}">{{ ___('Title') }}</label>
                                            <input class="ui-input" id="ex-title-{{ $extra->id }}" name="title" value="{{ $extra->title }}" required>
                                        </div>
                                        <div class="edit-field">
                                            <label class="ui-label" for="ex-price-{{ $extra->id }}">{{ ___('Price') }}</label>
                                            <input class="ui-input" id="ex-price-{{ $extra->id }}" type="number" step="0.01" name="price" value="{{ $extra->price }}" required>
                                        </div>
                                        <input type="hidden" name="image" :value="filename">
                                        <div class="ui-cluster image-pick">
                                            <img class="image-pick__thumb" x-show="url" :src="url" alt="" x-cloak style="width:36px;height:36px;object-fit:cover;">
                                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()">{{ ___('Image') }}</button>
                                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
                                        </div>
                                        <label class="ui-check">
                                            <input type="checkbox" name="active" value="1" @checked($extra->active)>
                                            <span>{{ ___('Available') }}</span>
                                        </label>
                                        <x-ui.button type="submit" size="sm" icon="check">
                                            {{ ___('Save') }}
                                        </x-ui.button>
                                    </form>
                                </details>
                            </article>
                        @empty
                            <div class="ui-muted menu-items-empty">{{ ___('No items available.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </article>
    </section>

    {{-- ===== Attach from the reusable library ===== --}}
    <section class="extras-grid ui-form-grid">
        <article class="extras-panel">
            <div class="ui-fill ui-card">
                <div class="ui-card__body">
                    <header class="ui-cluster ui-cluster--between ui-cluster--top">
                        <div>
                            <p class="ui-muted ui-caps">{{ ___('Library') }}</p>
                            <h3 class="ui-section-title">{{ ___('Global extras') }}</h3>
                            <span class="ui-muted">{{ ___('Attach reusable extras. Edits to the library apply everywhere.') }}</span>
                        </div>
                        <a class="ui-btn ui-btn--ghost ui-btn--sm" href="{{ route('modifiers.index', ['store_id' => $store->id]) }}">{{ ___('Manage library') }}</a>
                    </header>
                    <div class="ui-stack extras-list">
                        @forelse($globalExtras as $ge)
                            @php $isAttached = in_array($ge->id, $attachedExtraIds); @endphp
                            <article class="extras-row ui-bordered">
                                <header class="ui-cluster ui-cluster--between">
                                    <div class="ui-cluster extras-row__title">
                                        @if($ge->image)<img class="extras-row__thumb" src="{{ $ge->image_url }}" alt="" loading="lazy" style="width:36px;height:36px;object-fit:cover;">@endif
                                        <div><strong class="ui-block">{{ $ge->title }}</strong><span class="ui-muted">{{ price_symbol_format($ge->price) }}</span></div>
                                    </div>
                                    <div class="ui-cluster extras-row__actions">
                                        @if($isAttached)
                                            <x-ui.pill tone="success">{{ ___('Attached') }}</x-ui.pill>
                                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="detach(@js(route('modifiers.detachExtra', ['menu' => $menu->id, 'extra' => $ge->id])))">{{ ___('Detach') }}</button>
                                        @else
                                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="attach(@js(route('modifiers.attachExtra', $menu->id)), 'extra_id', {{ $ge->id }})">{{ ___('Attach') }}</button>
                                        @endif
                                    </div>
                                </header>
                            </article>
                        @empty
                            <div class="ui-muted menu-items-empty">{{ ___('No global extras. Create some in the Modifier library.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </article>

        <article class="extras-panel">
            <div class="ui-fill ui-card">
                <div class="ui-card__body">
                    <header>
                        <p class="ui-muted ui-caps">{{ ___('Library') }}</p>
                        <h3 class="ui-section-title">{{ ___('Global option groups') }}</h3>
                        <span class="ui-muted">{{ ___('Attach reusable option groups with their selection rules.') }}</span>
                    </header>
                    <div class="ui-stack extras-list">
                        @forelse($globalGroups as $gg)
                            @php $isAttached = in_array($gg->id, $attachedGroupIds); $vals = collect($gg->options ?? []); @endphp
                            <article class="extras-row ui-bordered">
                                <header class="ui-cluster ui-cluster--between">
                                    <div>
                                        <strong class="ui-block">{{ $gg->title }}</strong>
                                        <span class="ui-muted">{{ $vals->implode(', ') }} @if($gg->required)· {{ ___('required') }}@endif</span>
                                    </div>
                                    <div class="ui-cluster extras-row__actions">
                                        @if($isAttached)
                                            <x-ui.pill tone="success">{{ ___('Attached') }}</x-ui.pill>
                                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="detach(@js(route('modifiers.detachGroup', ['menu' => $menu->id, 'group' => $gg->id])))">{{ ___('Detach') }}</button>
                                        @else
                                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="attach(@js(route('modifiers.attachGroup', $menu->id)), 'group_id', {{ $gg->id }})">{{ ___('Attach') }}</button>
                                        @endif
                                    </div>
                                </header>
                            </article>
                        @empty
                            <div class="ui-muted menu-items-empty">{{ ___('No global option groups. Create some in the Modifier library.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </article>
    </section>

    @include('owner.media._picker')
</div>
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    window.linenExtrasPage = function (config) {
        return {
            submitAjax: function (form) {
                fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': config.token },
                    body: new FormData(form)
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
            deleteRecord: async function (url, id) {
                if (!(await window.dashConfirm({ body: config.deleteConfirmText, danger: true }))) {
                    return;
                }

                var payload = new FormData();
                payload.append('id', id);

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
            attach: function (url, key, id) {
                var payload = new FormData();
                payload.append(key, id);
                fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token, 'X-Requested-With': 'XMLHttpRequest' }, body: payload })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.success) { window.location.reload(); } else { window.dashToast(d.message || config.failedText, { type: 'error' }); } })
                    .catch(function () { window.dashToast(config.failedText, { type: 'error' }); });
            },
            detach: function (url) {
                var payload = new FormData();
                payload.append('_method', 'DELETE');
                fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token, 'X-Requested-With': 'XMLHttpRequest' }, body: payload })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.success) { window.location.reload(); } else { window.dashToast(d.message || config.failedText, { type: 'error' }); } })
                    .catch(function () { window.dashToast(config.failedText, { type: 'error' }); });
            },
            switchLanguage: function (code) {
                document.cookie = 'Quick_user_lang_code=' + encodeURIComponent(code) + '; path=/';
                window.location.reload();
            }
        };
    };
</script>
@endpush
