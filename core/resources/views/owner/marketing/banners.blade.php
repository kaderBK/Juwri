{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Storefront banners'))
@section('page_title', ___('Storefront banners'))
@section('page_sub', ___('Promo / hero images that rotate at the top of your customer menu.'))

@section('content')
<div class="ui-scope ui-banners" x-data="linenBanners({ token: @js(csrf_token()), reorderUrl: @js(route('banners.reorder')), failedText: @js(___('Unable to save.')), langs: @js(!empty($menu_languages) ? collect($menu_languages)->pluck('code')->all() : []) })">
    <form method="get" action="{{ route('banners.index') }}">
        @if($stores->count() > 1)
            <select name="store_id" class="ui-input ui-banners__store" data-ui-do="submit" aria-label="{{ ___('Select store…') }}">
                <option value="">{{ ___('Select store…') }}</option>
                @foreach ($stores as $r)
                    <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
                @endforeach
            </select>
        @else
            <input type="hidden" name="store_id" value="{{ $storeId }}">
        @endif
    </form>

    @if(!$storeId)
        <div class="ui-card">
            <x-ui.empty icon="store" :title="___('Pick a store to manage its banners.')" />
        </div>
    @else
        <x-ui.card :title="___('Add banner')">
            <form method="post" action="{{ route('banners.store') }}" class="ui-banners__form"
                  x-data="imagePickerField({ target: 'banner-add', storeId: {{ $storeId }}, folder: 'banner' })">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <input type="hidden" name="image" :value="filename">
                <div class="ui-banners__pick">
                    <img class="ui-banners__thumb" x-show="url" :src="url" alt="" x-cloak>
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()"><span class="ui-btn__icon"><x-ui.icon name="upload" /></span>{{ ___('Choose image') }} *</button>
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
                </div>
                @if(!empty($menu_languages) && count($menu_languages) > 1 && $default_menu_language)
                    {{-- Multi-language store: one title + subtitle per menu language so
                         banner text is stored per-locale (base = default language) and
                         localises on the storefront. Non-default locales are optional. --}}
                    <div class="ui-banners__langs">
                        @foreach($menu_languages as $lang)
                            @php($isDefLang = $default_menu_language->code === $lang->code)
                            <fieldset class="ui-banners__lang">
                                <legend class="ui-overline"><span class="ui-ltr">{{ strtoupper($lang->code) }}</span>@if($isDefLang) &middot; {{ ___('Default') }}@endif</legend>
                                <div class="ui-banners__pair">
                                    <div>
                                        <label class="ui-label">{{ ___('Title') }}</label>
                                        <input class="ui-input" name="{{ $isDefLang ? 'title' : 'translations['.$lang->code.'][title]' }}" placeholder="{{ ___('Happy hour') }}">
                                    </div>
                                    <div>
                                        <label class="ui-label">{{ ___('Subtitle') }}</label>
                                        <input class="ui-input" name="{{ $isDefLang ? 'subtitle' : 'translations['.$lang->code.'][subtitle]' }}">
                                    </div>
                                </div>
                            </fieldset>
                        @endforeach
                    </div>
                    <div class="ui-banners__fields">
                        <div>
                            <label class="ui-label" for="banner-link">{{ ___('Link (optional)') }}</label>
                            <input class="ui-input" id="banner-link" name="link_url" type="url" dir="ltr" placeholder="https://">
                        </div>
                    </div>
                @else
                    <div class="ui-banners__fields">
                        <div>
                            <label class="ui-label" for="banner-title">{{ ___('Title') }}</label>
                            <input class="ui-input" id="banner-title" name="title" placeholder="{{ ___('Happy hour') }}">
                        </div>
                        <div>
                            <label class="ui-label" for="banner-subtitle">{{ ___('Subtitle') }}</label>
                            <input class="ui-input" id="banner-subtitle" name="subtitle">
                        </div>
                        <div>
                            <label class="ui-label" for="banner-link">{{ ___('Link (optional)') }}</label>
                            <input class="ui-input" id="banner-link" name="link_url" type="url" dir="ltr" placeholder="https://">
                        </div>
                    </div>
                @endif
                <label class="ui-check"><input type="checkbox" name="active" value="1" checked><span>{{ ___('Active') }}</span></label>
                <div>
                    <x-ui.button type="submit" icon="plus" x-bind:disabled="!filename">{{ ___('Add banner') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <section class="ui-card">
            <div class="ui-card__head">
                <h2 class="ui-card__title">{{ ___('Banners') }}</h2>
                <div class="ui-card__actions"><x-ui.tag class="ui-ltr">{{ $banners->count() }}</x-ui.tag></div>
            </div>
            @if($banners->isEmpty())
                <x-ui.empty icon="upload" :title="___('No banners yet. Add one above.')" />
            @else
                <p class="ui-banners__muted">{{ ___('Drag to reorder. Only active banners show on the storefront.') }}</p>
                <div class="banner-list ui-list ui-banners__list" @dragover.prevent="dragOver($event)" @drop.prevent>
                    @foreach ($banners as $b)
                        <article class="banner-card ui-row ui-banners__row" data-drag-id="{{ $b->id }}" draggable="true"
                                 @dragstart="dragStart($event)" @dragend="dragEnd($event)">
                            <span class="banner-card__handle ui-banners__handle" aria-hidden="true">
                                <x-ui.icon name="grip" size="18" />
                            </span>
                            <img class="ui-banners__img" src="{{ $b->image_url }}" alt="{{ $b->title }}" loading="lazy"
                                 data-ui-img-fallback="{{ asset('storage/menu/default.png') }}">
                            <div class="ui-row__body">
                                <span class="ui-row__title">{{ $b->title ?: ___('(no title)') }}</span>
                                @if($b->subtitle)<span class="ui-row__sub">{{ $b->subtitle }}</span>@endif
                                @if($b->link_url)<span class="ui-row__sub ui-ltr">{{ $b->link_url }}</span>@endif
                            </div>
                            <div class="ui-banners__actions">
                                @if($b->active)<x-ui.pill tone="success">{{ ___('Active') }}</x-ui.pill>
                                @else<x-ui.pill>{{ ___('Hidden') }}</x-ui.pill>@endif
                                <button type="button" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"
                                    @click="openEdit(@js(['title'=>$b->title,'subtitle'=>$b->subtitle,'link_url'=>$b->link_url,'image'=>$b->image,'image_url'=>$b->image_url,'active'=>(bool)$b->active,'translations'=>$b->translations]), {{ $b->id }})">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <form method="post" action="{{ route('banners.destroy', $b->id) }}" data-confirm="{{ ___('Delete this banner?') }}" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Edit dialog (kit sheet: bottom sheet on phones, dialog on desktop). --}}
        <dialog class="ui-sheet ui-banners__sheet" x-ref="bannerDialog" aria-labelledby="banner-edit-title">
            <div class="ui-sheet__handle" aria-hidden="true"></div>
            <form method="post" :action="@js(url('banners')) + '/' + editId"
                  x-data="imagePickerField({ target: 'banner-edit', storeId: {{ $storeId }}, folder: 'banner' })"
                  x-effect="filename = edit.image || ''; url = edit.image_url || ''">
                @csrf @method('PUT')
                <div class="ui-banners__sheet-head">
                    <h2 class="ui-sheet__title" id="banner-edit-title">{{ ___('Edit banner') }}</h2>
                    <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.bannerDialog.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" /></button>
                </div>
                <div class="ui-sheet__body ui-banners__form">
                    <input type="hidden" name="image" :value="filename">
                    <div class="ui-banners__pick">
                        <img class="ui-banners__thumb" x-show="url" :src="url" alt="" x-cloak>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()"><span class="ui-btn__icon"><x-ui.icon name="upload" /></span>{{ ___('Choose image') }}</button>
                    </div>
                    @if(!empty($menu_languages) && count($menu_languages) > 1 && $default_menu_language)
                        @foreach($menu_languages as $lang)
                            @php($isDefLang = $default_menu_language->code === $lang->code)
                            <fieldset class="ui-banners__lang">
                                <legend class="ui-overline"><span class="ui-ltr">{{ strtoupper($lang->code) }}</span>@if($isDefLang) &middot; {{ ___('Default') }}@endif</legend>
                                <div><label class="ui-label">{{ ___('Title') }}</label>
                                    @if($isDefLang)
                                        <input class="ui-input" name="title" x-model="edit.title">
                                    @else
                                        <input class="ui-input" name="translations[{{ $lang->code }}][title]" x-model="edit.translations['{{ $lang->code }}'].title">
                                    @endif
                                </div>
                                <div><label class="ui-label">{{ ___('Subtitle') }}</label>
                                    @if($isDefLang)
                                        <input class="ui-input" name="subtitle" x-model="edit.subtitle">
                                    @else
                                        <input class="ui-input" name="translations[{{ $lang->code }}][subtitle]" x-model="edit.translations['{{ $lang->code }}'].subtitle">
                                    @endif
                                </div>
                            </fieldset>
                        @endforeach
                    @else
                        <div><label class="ui-label" for="banner-edit-title-input">{{ ___('Title') }}</label><input class="ui-input" id="banner-edit-title-input" name="title" x-model="edit.title"></div>
                        <div><label class="ui-label" for="banner-edit-subtitle">{{ ___('Subtitle') }}</label><input class="ui-input" id="banner-edit-subtitle" name="subtitle" x-model="edit.subtitle"></div>
                    @endif
                    <div><label class="ui-label" for="banner-edit-link">{{ ___('Link (optional)') }}</label><input class="ui-input" id="banner-edit-link" name="link_url" type="url" dir="ltr" x-model="edit.link_url"></div>
                    <label class="ui-check"><input type="checkbox" name="active" value="1" :checked="edit.active" @change="edit.active = $event.target.checked"><span>{{ ___('Active') }}</span></label>
                </div>
                <div class="ui-sheet__foot">
                    <x-ui.button variant="secondary" @click="$refs.bannerDialog.close()">{{ ___('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
                </div>
            </form>
        </dialog>

        @include('owner.media._picker')
    @endif
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.linenBanners = function (config) {
        // Blank per-locale scaffold so x-model bindings (edit.translations['xx'].title)
        // always resolve, even before a row is opened.
        var blankTranslations = function () {
            var t = {};
            (config.langs || []).forEach(function (code) { t[code] = { title: '', subtitle: '' }; });
            return t;
        };
        return {
            editId: null, edit: { title: '', subtitle: '', link_url: '', translations: blankTranslations() },
            openEdit: function (data, id) {
                // Merge the row's saved translations onto the blank scaffold so every
                // menu-language input has a backing entry to bind to.
                var t = blankTranslations();
                var src = (data && data.translations) || {};
                Object.keys(t).forEach(function (code) {
                    if (src[code]) {
                        t[code] = { title: src[code].title || '', subtitle: src[code].subtitle || '' };
                    }
                });
                data.translations = t;
                this.edit = data;
                this.editId = id;
                this.$refs.bannerDialog.showModal();
            },
            _drag: null, _container: null,
            dragStart(e) { this._drag = e.target.closest('[data-drag-id]'); this._container = this._drag.parentNode; this._drag.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); },
            dragOver(e) {
                const t = e.target.closest('[data-drag-id]');
                if (!t || t === this._drag || t.parentNode !== this._container) return;
                const r = t.getBoundingClientRect();
                this._container.insertBefore(this._drag, (e.clientY < r.top + r.height / 2) ? t : t.nextSibling);
            },
            dragEnd() {
                if (!this._drag) return;
                this._drag.classList.remove('is-dragging');
                const ids = Array.from(this._container.querySelectorAll(':scope > [data-drag-id]')).map(el => el.getAttribute('data-drag-id'));
                const fd = new FormData(); ids.forEach(id => fd.append('position[]', id));
                fetch(config.reorderUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token, 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).catch(() => {});
                this._drag = null; this._container = null;
            },
        };
    };
</script>
@endpush
@endsection
