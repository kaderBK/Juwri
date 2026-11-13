{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Modifier library'))
@section('page_title', ___('Modifier library'))
@section('page_sub', ___('Reusable extras and option groups. Define once, attach to many items from the item’s Extras page.'))

@section('content')
<div class="ui-scope ui-mods" x-data="{ exEdit: null, ex: {}, grEdit: null, gr: {},
        switchLanguage(code) { document.cookie = 'Quick_user_lang_code=' + encodeURIComponent(code) + '; path=/'; window.location.reload(); } }">
    <form method="get" action="{{ route('modifiers.index') }}">
        @if($stores->count() > 1)
            <select name="store_id" class="ui-input ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
                <option value="">{{ ___('Select store…') }}</option>
                @foreach ($stores as $r)
                    <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
                @endforeach
            </select>
        @else
            <input type="hidden" name="store_id" value="{{ $storeId }}">
        @endif
    </form>

    @if($storeId && $menu_languages && $menu_languages->count())
        <x-ui.card :title="___('Editing language')">
            <x-slot:actions>
                <div class="ui-extras__langs" role="group">
                    @foreach($menu_languages as $lang)
                        <button type="button"
                            class="ui-chip" aria-pressed="{{ $default_menu_language && $default_menu_language->code === $lang->code ? 'true' : 'false' }}"
                            @click="switchLanguage(@js($lang->code))">
                            {{ strtoupper($lang->code) }}
                        </button>
                    @endforeach
                </div>
            </x-slot:actions>
            <p class="ui-mods__hint">{{ ___('Create new modifiers in your default language, then switch language and Edit to translate. Prices and images are shared across languages.') }}</p>
        </x-ui.card>
    @endif

    @if(!$storeId)
        <div class="ui-card"><x-ui.empty :title="___('Pick a store to manage its modifier library.')" icon="store" /></div>
    @else
        {{-- ===== Global extras ===== --}}
        <x-ui.card :title="___('Global extras')">
            <x-slot:actions>
                <x-ui.button type="button" size="sm" variant="secondary" icon="upload"
                    @click="$refs.bulkExDialog.showModal()">{{ ___('Bulk add') }}</x-ui.button>
            </x-slot:actions>
            <form method="post" action="{{ route('modifiers.extras.store') }}" class="ui-form-grid ui-mods__add"
                  x-data="imagePickerField({ target: 'gex-add', storeId: {{ $storeId }}, folder: 'extra' })">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <div>
                    <label class="ui-label">{{ ___('Title') }}</label>
                    <input class="ui-input" name="title" placeholder="{{ ___('Extra cheese') }}" required>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Price') }}</label>
                    <input class="ui-input" name="price" type="number" step="0.01" min="0" value="0" required>
                </div>
                <input type="hidden" name="image" :value="filename">
                <div class="ui-form-grid__full">
                    <div class="ui-cluster">
                        <img class="ui-mods__thumb ui-mods__thumb--lg" x-show="url" :src="url" alt="" x-cloak>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()">{{ ___('Image') }}</button>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
                    </div>
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.button type="submit" icon="plus">{{ ___('Add extra') }}</x-ui.button>
                </div>
            </form>

            @if($extras->isEmpty())
                <x-ui.empty :title="___('No global extras yet.')" icon="plus" />
            @else
                <div class="ui-mods__table">
                    <table class="ui-table">
                        <thead><tr>
                            <th>{{ ___('Extra') }}</th><th class="ui-table__num">{{ ___('Price') }}</th>
                            <th class="ui-table__num">{{ ___('Used by') }}</th><th>{{ ___('Status') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($extras as $e)
                                <tr>
                                    <td data-primary>
                                        <span class="ui-mods__name">
                                            @if($e->image)<img class="ui-mods__thumb" src="{{ $e->image_url }}" alt="" loading="lazy">@endif
                                            <strong>{{ $e->title }}</strong>
                                        </span>
                                    </td>
                                    <td class="ui-table__num" data-label="{{ ___('Price') }}"><strong>{{ price_symbol_format($e->price) }}</strong></td>
                                    <td class="ui-table__num" data-label="{{ ___('Used by') }}">{{ $e->menus_count }} {{ ___('items') }}</td>
                                    <td data-label="{{ ___('Status') }}">@if($e->active ?? true)<x-ui.pill tone="success">{{ ___('Active') }}</x-ui.pill>@else<x-ui.pill>{{ ___('Inactive') }}</x-ui.pill>@endif</td>
                                    <td class="ui-catalog__row-actions">
                                            <button type="button" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"
                                                @click="exEdit = {{ $e->id }}; ex = @js(['title'=>$e->title,'price'=>$e->price,'image'=>$e->image,'image_url'=>$e->image_url,'active'=>(bool)($e->active ?? true)]); $refs.exDialog.showModal()">
                                                <x-ui.icon name="pencil" />
                                            </button>
                                            <form method="post" action="{{ route('modifiers.extras.destroy', $e->id) }}" data-confirm="{{ ___('Delete this extra? It will be removed from all items.') }}" data-confirm-danger>
                                                @csrf @method('DELETE')
                                                <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                            </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        {{-- ===== Global variant-option groups ===== --}}
        <x-ui.card :title="___('Global option groups')">
            <form method="post" action="{{ route('modifiers.groups.store') }}" class="ui-form-grid ui-mods__add">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <div>
                    <label class="ui-label">{{ ___('Title') }}</label>
                    <input class="ui-input" name="title" placeholder="{{ ___('Size') }}" required>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Options (comma separated)') }}</label>
                    <input class="ui-input" name="options" placeholder="{{ ___('Small, Medium, Large') }}" required>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Prices (comma, aligned)') }}</label>
                    <input class="ui-input" name="option_prices" placeholder="{{ ___('0, 2, 4') }}">
                </div>
                <div>
                    <label class="ui-check"><input type="checkbox" name="required" value="1"><span>{{ ___('Required') }}</span></label>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Min') }}</label>
                    <input class="ui-input" name="min_select" type="number" min="0" max="50" value="0">
                </div>
                <div>
                    <label class="ui-label">{{ ___('Max') }}</label>
                    <input class="ui-input" name="max_select" type="number" min="0" max="50" value="1" placeholder="{{ ___('∞ = leave blank') }}">
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.button type="submit" icon="plus">{{ ___('Add group') }}</x-ui.button>
                </div>
            </form>

            @if($groups->isEmpty())
                <x-ui.empty :title="___('No global option groups yet.')" icon="list" />
            @else
                <div class="ui-mods__table">
                    <table class="ui-table">
                        <thead><tr>
                            <th>{{ ___('Group') }}</th><th>{{ ___('Options') }}</th><th>{{ ___('Rule') }}</th>
                            <th class="ui-table__num">{{ ___('Used by') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                        </tr></thead>
                        <tbody>
                            @foreach ($groups as $g)
                                @php $vals = collect($g->options ?? []); @endphp
                                <tr>
                                    <td data-primary><strong>{{ $g->title }}</strong></td>
                                    <td data-label="{{ ___('Options') }}"><span class="ui-mods__opts">{{ $vals->implode(', ') }}</span></td>
                                    <td data-label="{{ ___('Rule') }}">
                                        <span class="ui-mods__rule">
                                            @if($g->required)<x-ui.pill tone="warning">{{ ___('Required') }}</x-ui.pill>@endif
                                            <small class="ui-ltr">{{ $g->min_select ?? 0 }}–{{ $g->max_select ?? '∞' }}</small>
                                        </span>
                                    </td>
                                    <td class="ui-table__num" data-label="{{ ___('Used by') }}">{{ $g->menus_count }} {{ ___('items') }}</td>
                                    <td class="ui-catalog__row-actions">
                                            <button type="button" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"
                                                @click="grEdit = {{ $g->id }}; gr = @js(['title'=>$g->title,'options'=>$vals->implode(', '),'option_prices'=>implode(', ', $g->option_prices ?? []),'required'=>(bool)$g->required,'min_select'=>$g->min_select ?? 0,'max_select'=>$g->max_select,'active'=>(bool)($g->active ?? true)]); $refs.grDialog.showModal()">
                                                <x-ui.icon name="pencil" />
                                            </button>
                                            <form method="post" action="{{ route('modifiers.groups.destroy', $g->id) }}" data-confirm="{{ ___('Delete this group? It will be removed from all items.') }}" data-confirm-danger>
                                                @csrf @method('DELETE')
                                                <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                            </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        {{-- Edit dialogs --}}
        <dialog class="ui-dialog" x-ref="exDialog">
            <div class="ui-dialog__panel ui-card">
                <form method="post" :action="@js(url('modifiers/extras')) + '/' + exEdit"
                      x-data="imagePickerField({ target: 'gex-edit', storeId: {{ $storeId }}, folder: 'extra' })"
                      x-effect="filename = ex.image || ''; url = ex.image_url || ''">
                    @csrf @method('PUT')
                    <div class="ui-cluster ui-cluster--between ui-card__head">
                        <h4 class="ui-section-title">{{ ___('Edit extra') }}</h4>
                        <button type="button" class="ui-icon-btn" @click="$refs.exDialog.close()" aria-label="{{ ___('Close') }}">&times;</button>
                    </div>
                    <div class="ui-card__body ui-stack">
                        <div><label class="ui-label">{{ ___('Title') }}</label><input class="ui-input" name="title" x-model="ex.title" required></div>
                        <div><label class="ui-label">{{ ___('Price') }}</label><input class="ui-input" name="price" type="number" step="0.01" min="0" x-model="ex.price" required></div>
                        <input type="hidden" name="image" :value="filename">
                        <div class="ui-cluster">
                            <img x-show="url" :src="url" alt="" x-cloak style="width:44px;height:44px;object-fit:cover;">
                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="choose()">{{ ___('Image') }}</button>
                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-show="filename" @click="clear()" x-cloak>{{ ___('Remove') }}</button>
                        </div>
                        <label class="ui-check"><input type="checkbox" name="active" value="1" :checked="ex.active" @change="ex.active = $event.target.checked"><span>{{ ___('Active') }}</span></label>
                    </div>
                    <div class="ui-cluster ui-cluster--end ui-card__foot">
                        <x-ui.button type="button" variant="ghost" @click="$refs.exDialog.close()">{{ ___('Cancel') }}</x-ui.button>
                        <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </dialog>

        <dialog class="ui-dialog" x-ref="grDialog">
            <div class="ui-dialog__panel ui-card">
                <form method="post" :action="@js(url('modifiers/groups')) + '/' + grEdit">
                    @csrf @method('PUT')
                    <div class="ui-cluster ui-cluster--between ui-card__head">
                        <h4 class="ui-section-title">{{ ___('Edit group') }}</h4>
                        <button type="button" class="ui-icon-btn" @click="$refs.grDialog.close()" aria-label="{{ ___('Close') }}">&times;</button>
                    </div>
                    <div class="ui-card__body ui-stack">
                        <div><label class="ui-label">{{ ___('Title') }}</label><input class="ui-input" name="title" x-model="gr.title" required></div>
                        <div><label class="ui-label">{{ ___('Options (comma separated)') }}</label><input class="ui-input" name="options" x-model="gr.options" required></div>
                        <div><label class="ui-label">{{ ___('Prices (comma, aligned)') }}</label><input class="ui-input" name="option_prices" x-model="gr.option_prices" placeholder="{{ ___('0, 2, 4') }}"></div>
                        <label class="ui-check"><input type="checkbox" name="required" value="1" :checked="gr.required" @change="gr.required = $event.target.checked"><span>{{ ___('Required') }}</span></label>
                        <div class="ui-form-grid">
                            <div><label class="ui-label">{{ ___('Min select') }}</label><input class="ui-input" name="min_select" type="number" min="0" max="50" x-model="gr.min_select"></div>
                            <div><label class="ui-label">{{ ___('Max select') }}</label><input class="ui-input" name="max_select" type="number" min="0" max="50" x-model="gr.max_select"></div>
                        </div>
                        <label class="ui-check"><input type="checkbox" name="active" value="1" :checked="gr.active" @change="gr.active = $event.target.checked"><span>{{ ___('Active') }}</span></label>
                    </div>
                    <div class="ui-cluster ui-cluster--end ui-card__foot">
                        <x-ui.button type="button" variant="ghost" @click="$refs.grDialog.close()">{{ ___('Cancel') }}</x-ui.button>
                        <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </dialog>

        {{-- ===== Bulk add extras ===== --}}
        <dialog class="ui-dialog" x-ref="bulkExDialog">
            <div class="ui-dialog__panel ui-card" style="max-width:760px;width:96vw;"
                 x-data="bulkExtras({
                    storeId: {{ $storeId }},
                    aiTitleUrl: @js(route('modifiers.extras.aiTitle')),
                    bulkStoreUrl: @js(route('modifiers.extras.bulkStore')),
                    token: @js(csrf_token()),
                    hasVision: {{ $aiVision ? 'true' : 'false' }},
                    canTranslate: {{ ($translatable ?? false) ? 'true' : 'false' }},
                    needTitleText: @js(___('Add a title to at least one extra.')),
                    failText: @js(___('Unable to save. Please try again.'))
                 })">
                <div class="ui-cluster ui-cluster--between ui-card__head">
                    <h4 class="ui-section-title">{{ ___('Bulk add extras') }}</h4>
                    <button type="button" class="ui-icon-btn" @click="$el.closest('dialog').close()" aria-label="{{ ___('Close') }}">&times;</button>
                </div>
                <div class="ui-card__body ui-stack">
                    <div class="ui-cluster ui-cluster--bottom">
                        <x-ui.button type="button" variant="secondary" icon="upload" @click="pick()">{{ ___('Pick images') }}</x-ui.button>
                        <div>
                            <label class="ui-label">{{ ___('Default price') }}</label>
                            <div class="ui-input-group ui-input-group--sm" style="max-width:190px;">
                                <input class="ui-input" type="number" step="0.01" min="0" x-model="defaultPrice">
                                <button class="ui-btn ui-btn--secondary" type="button" @click="applyPriceToAll()" :disabled="!rows.length">{{ ___('Apply to all') }}</button>
                            </div>
                        </div>
                        <div>
                            <label class="ui-label">{{ ___('Auto-name') }}</label>
                            <select class="ui-input ui-input--sm" x-model="nameSource" @change="onSourceChange()" style="max-width:190px;">
                                <option value="filename">{{ ___('From file name') }}</option>
                                @if($aiVision)<option value="ai">{{ ___('AI from image') }}</option>@endif
                                <option value="off">{{ ___('Off') }}</option>
                            </select>
                        </div>
                        <label class="ui-check" x-show="canTranslate">
                            <input type="checkbox" x-model="translate">
                            <span>{{ ___('Translate to other languages') }}</span>
                        </label>
                    </div>
                    @if($aiVision)
                        {{-- AI transparency: auto-name reads the image, so the vision chain runs. --}}
                        <x-ai-badge :store-id="$storeId" :needs-vision="true" x-show="nameSource==='ai'" x-cloak />
                    @endif

                    <div class="ui-muted" x-show="naming" x-cloak>
                        {{ ___('Naming images…') }} <span x-text="namedCount"></span>/<span x-text="rows.length"></span>
                    </div>

                    <template x-if="!rows.length">
                        <div class="ui-text-2 ui-center">{{ ___('Pick one or more images to start. Each becomes an extra you can name and price.') }}</div>
                    </template>

                    <div class="ui-table-wrap" x-show="rows.length" x-cloak style="max-height:46vh;overflow-y:auto;">
                        <table class="ui-table">
                            <thead><tr>
                                <th style="width:52px;"></th><th>{{ ___('Title') }}</th>
                                <th style="width:120px;" class="ui-end">{{ ___('Price') }}</th><th style="width:40px;"></th>
                            </tr></thead>
                            <tbody>
                                <template x-for="(row, i) in rows" :key="row.image">
                                    <tr :class="isDupe(row) && 'table-warning'">
                                        <td><img :src="row.url" alt="" style="width:40px;height:40px;object-fit:cover;"></td>
                                        <td>
                                            <input class="ui-input ui-input--sm" type="text" x-model="row.title"
                                                   :placeholder="row.status==='naming' ? '{{ ___('Naming…') }}' : '{{ ___('Extra name') }}'">
                                            <span class="ui-text-warning ui-small" x-show="isDupe(row)" x-cloak>{{ ___('Duplicate title') }}</span>
                                            <span class="ui-muted" x-show="row.status==='failed'" x-cloak>{{ ___('Couldn’t auto-name — type one') }}</span>
                                        </td>
                                        <td class="ui-end"><input class="ui-input ui-input--sm ui-end" type="number" step="0.01" min="0" x-model="row.price"></td>
                                        <td class="ui-end"><button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" @click="remove(i)" aria-label="{{ ___('Remove') }}">&times;</button></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ui-cluster ui-cluster--between ui-card__foot">
                    <span class="ui-muted"><span x-text="readyCount()"></span> {{ ___('ready') }}</span>
                    <div class="ui-cluster">
                        <x-ui.button type="button" variant="ghost" @click="$el.closest('dialog').close()">{{ ___('Cancel') }}</x-ui.button>
                        <button type="button" class="ui-btn ui-btn--sm" :disabled="saving || naming || !readyCount()" @click="save()"
                                x-text="saving ? '{{ ___('Saving…') }}' : '{{ ___('Create extras') }}'"></button>
                    </div>
                </div>
            </div>
        </dialog>

        @include('owner.media._picker')
    @endif
</div>
@push('scripts')
<script nonce="{{ csp_nonce() }}">
    window.bulkExtras = function (cfg) {
        return {
            rows: [], defaultPrice: '0',
            nameSource: cfg.hasVision ? 'ai' : 'filename',
            translate: false, naming: false, namedCount: 0, saving: false,
            hasVision: cfg.hasVision, canTranslate: cfg.canTranslate,
            init() {
                var self = this;
                window.addEventListener('media-picker:picked', function (e) {
                    if (e.detail.target !== 'bulk-extras') return;
                    var added = [];
                    e.detail.assets.forEach(function (a) {
                        if (self.rows.some(function (r) { return r.image === a.filename; })) return;
                        var row = { image: a.filename, url: a.url, name: a.name || a.filename, title: '', price: self.defaultPrice, status: '' };
                        self.rows.push(row); added.push(row);
                    });
                    self.applySource(added);
                });
            },
            humanize(name) {
                var s = String(name || '').replace(/\.[a-z0-9]+$/i, '').replace(/[_\-]+/g, ' ').replace(/\s+/g, ' ').trim();
                return s.replace(/\b\w/g, function (c) { return c.toUpperCase(); }).slice(0, 255);
            },
            onSourceChange() { this.applySource(this.rows); },
            applySource(rows) {
                if (this.nameSource === 'filename') {
                    var self = this;
                    rows.forEach(function (r) { r.title = self.humanize(r.name); r.status = r.title ? 'done' : 'failed'; });
                } else if (this.nameSource === 'ai') {
                    this.runNaming(rows);
                }
            },
            pick() { window.openMediaPicker({ target: 'bulk-extras', storeId: cfg.storeId, folder: 'extra', multiple: true }); },
            remove(i) { this.rows.splice(i, 1); },
            applyPriceToAll() { var p = this.defaultPrice; this.rows.forEach(function (r) { r.price = p; }); },
            readyCount() { return this.rows.filter(function (r) { return String(r.title).trim() !== ''; }).length; },
            isDupe(row) {
                var t = String(row.title).trim().toLowerCase();
                if (!t) return false;
                return this.rows.filter(function (r) { return String(r.title).trim().toLowerCase() === t; }).length > 1;
            },
            async runNaming(rows) {
                rows = rows || this.rows;
                if (this.naming) return;
                this.naming = true; this.namedCount = 0;
                for (const row of rows) {
                    this.namedCount++;
                    row.status = 'naming';
                    try {
                        var fd = new FormData();
                        fd.append('store_id', cfg.storeId);
                        fd.append('image', row.image);
                        var res = await fetch(cfg.aiTitleUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': cfg.token, 'Accept': 'application/json' }, body: fd });
                        var d = await res.json();
                        if (d && d.success && d.title) { row.title = d.title; row.status = 'done'; }
                        else { row.title = this.humanize(row.name); row.status = row.title ? 'done' : 'failed'; } // AI blank → filename fallback
                    } catch (e) { row.title = this.humanize(row.name); row.status = row.title ? 'done' : 'failed'; }
                }
                this.naming = false;
            },
            async save() {
                var items = this.rows
                    .filter(function (r) { return String(r.title).trim() !== ''; })
                    .map(function (r) { return { image: r.image || '', title: String(r.title).trim(), price: (r.price === '' || r.price == null) ? 0 : r.price }; });
                if (!items.length) { window.dashToast(cfg.needTitleText, { type: 'warning' }); return; }
                this.saving = true;
                var fd = new FormData();
                fd.append('store_id', cfg.storeId);
                if (this.translate) fd.append('translate', '1');
                items.forEach(function (it, i) {
                    fd.append('items[' + i + '][image]', it.image);
                    fd.append('items[' + i + '][title]', it.title);
                    fd.append('items[' + i + '][price]', it.price);
                });
                try {
                    var res = await fetch(cfg.bulkStoreUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': cfg.token, 'Accept': 'application/json' }, body: fd });
                    var d = await res.json();
                    if (d && d.success) { window.location.reload(); return; }
                    window.dashToast((d && d.message) || cfg.failText, { type: 'error' }); this.saving = false;
                } catch (e) { window.dashToast(cfg.failText, { type: 'error' }); this.saving = false; }
            },
        };
    };
</script>
@endpush
@endsection
