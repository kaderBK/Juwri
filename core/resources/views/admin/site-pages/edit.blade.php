{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Site Page Composer'))

@php
    /* HOME-PAGES HP-41: fork of the reseller Alpine pageComposer with admin
       endpoints, revisions panel and image upload. Component named
       sitePageComposer to avoid double-registration if both composers ever
       share a layout. */
    $reg = [];
    foreach ($registry as $type => $def) {
        $reg[$type] = [
            'label' => ___($def['label']),
            'icon' => $def['icon'],
            'max' => $def['max'],
            'settings' => $def['settings'],
            'content' => $def['content'],
        ];
    }
    $doc = $page->blocks && isset($page->blocks['blocks']) ? $page->blocks : ['version' => 1, 'blocks' => []];
@endphp

@section('content')
<div x-data="sitePageComposer({
        pageId: {{ $page->id }},
        registry: {{ \Illuminate\Support\Js::from($reg) }},
        doc: {{ \Illuminate\Support\Js::from($doc) }},
        locales: {{ \Illuminate\Support\Js::from($locales) }},
        defaultLocale: @js($defaultLocale),
        status: @js($page->status),
        urls: {
            save: '{{ route('admin.site-pages.save', $page->id) }}',
            publish: '{{ route('admin.site-pages.publish', $page->id) }}',
            preview: '{{ route('admin.site-pages.preview', $page->id) }}',
            translate: '{{ route('admin.site-pages.translate', $page->id) }}',
            revisions: '{{ route('admin.site-pages.revisions', $page->id) }}',
            restoreBase: '{{ url(route('admin.site-pages.restore', [$page->id, 0], false)) }}',
            upload: '{{ route('admin.site-pages.upload') }}',
        },
        csrf: '{{ csrf_token() }}',
        t: {
            saved: '{{ ___('Saved') }}', saving: '{{ ___('Saving…') }}', unsaved: '{{ ___('Unsaved changes') }}',
            confirmDelete: '{{ ___('Remove this block?') }}',
            confirmRestore: '{{ ___('Restore this revision into the draft?') }}',
        }
     })"
     @beforeunload.window="if(dirty){ $event.preventDefault(); $event.returnValue=''; }"
     class="ui-stack">

    {{-- Toolbar --}}
    <div class="ui-cluster ui-cluster--between">
        <div class="ui-cluster">
            <a href="{{ route('admin.site-pages.index') }}" class="ui-icon-btn" aria-label="{{ ___('Back') }}" title="{{ ___('Back') }}"><x-ui.icon name="back" flip /></a>
            <h2 class="ui-section-title">{{ $page->type === 'home' ? ___('Home page') : '/page/'.$page->slug }}</h2>
            <span class="ui-tag" :data-tone="status==='published' ? 'success' : 'neutral'"
                  x-text="status==='published' ? '{{ ___('Published') }}' : '{{ ___('Draft') }}'"></span>
            <small class="ui-text-2" x-text="statusText" aria-live="polite"></small>
        </div>
        <div class="ui-cluster">
            <x-ui.button variant="secondary" size="sm" icon="history" x-on:click="toggleRevisions()">{{ ___('History') }}</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="eye" x-on:click="openPreview()">{{ ___('Preview') }}</x-ui.button>
            <x-ui.button variant="secondary" size="sm" x-on:click="save()" ::disabled="!dirty">{{ ___('Save draft') }}</x-ui.button>
            <x-ui.button size="sm" x-on:click="publish()" x-show="status!=='published' || dirty">{{ ___('Publish') }}</x-ui.button>
            <x-ui.button variant="secondary" size="sm" class="ui-text-danger" x-on:click="publish(true)" x-show="status==='published'">{{ ___('Unpublish') }}</x-ui.button>
        </div>
    </div>

    {{-- Revisions panel --}}
    <x-ui.card :title="___('Publish history (last 20)')" x-show="showRevisions" style="display: none">
        <template x-if="revisions.length===0"><p class="ui-small ui-text-2">{{ ___('No publishes yet.') }}</p></template>
        <ul class="ui-plain-list ui-stack" style="--ui-gap: 6px">
            <template x-for="rev in revisions" :key="rev.id">
                <li class="ui-cluster ui-cluster--between ui-rule-top">
                    <span class="ui-small"><span x-text="rev.created_at"></span> <em x-text="rev.label"></em></span>
                    <x-ui.button variant="secondary" size="sm" x-on:click="restoreRevision(rev.id)">{{ ___('Restore to draft') }}</x-ui.button>
                </li>
            </template>
        </ul>
    </x-ui.card>

    <div class="ui-columns" style="--ui-col-min: 340px">
        {{-- Left: block list + picker --}}
        <x-ui.card :title="___('Blocks')">
            <x-slot:actions>
                <div class="ui-pop-host" data-ui-pop>
                    <x-ui.button size="sm" icon="plus" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true">{{ ___('Add block') }}</x-ui.button>
                    <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden x-ref="addPop" style="max-block-size: 320px; overflow-y: auto">
                        <template x-for="(def, type) in registry" :key="type">
                            <button type="button" class="ui-pop__item" @click="addBlock(type); $refs.addPop.hidden = true" :disabled="atMax(type)">
                                <span class="ui-pop__label" x-text="def.label"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </x-slot:actions>
            <template x-if="blocks.length===0">
                <p class="ui-small ui-text-2">{{ ___('No blocks yet — add your first block.') }}</p>
            </template>
            <ul class="ui-plain-list ui-stack" style="--ui-gap: 8px">
                <template x-for="(blk, i) in blocks" :key="blk.id">
                    <li class="ui-card ui-cluster" style="padding: 8px 10px; --ui-gap: 8px"
                        :class="selected===i ? 'ui-card--accent' : ''"
                        draggable="true"
                        @dragstart="dragI=i" @dragover.prevent @drop="moveBlock(dragI, i)">
                        <span class="ui-text-2" style="cursor: grab" aria-hidden="true"><x-ui.icon name="grip" size="18" /></span>
                        <button type="button" class="ui-grow ui-start" style="all: unset; cursor: pointer; flex: 1 1 auto; min-inline-size: 0" @click="selected=i">
                            <span class="ui-small ui-strong ui-block" x-text="registry[blk.type]?.label || blk.type"></span>
                            <small class="ui-text-2 ui-block" x-text="snippet(blk)"></small>
                        </button>
                        <span class="ui-switch">
                            <input type="checkbox" role="switch" class="ui-switch__input" x-model="blk.enabled" @change="touch()" aria-label="{{ ___('Enable/Disable') }}">
                            <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__knob"></span></span>
                        </span>
                        <button type="button" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" @click="removeBlock(i)" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="x" size="18" /></button>
                    </li>
                </template>
            </ul>
        </x-ui.card>

        {{-- Right: block editor --}}
        <x-ui.card>
            <template x-if="selected===null || !blocks[selected]">
                <p class="ui-text-2">{{ ___('Select a block to edit its content. Blocks without editable fields render their built-in section as-is.') }}</p>
            </template>
            <template x-if="selected!==null && blocks[selected]">
                <div class="ui-stack">
                    <div class="ui-cluster ui-cluster--between">
                        <h3 class="ui-section-title"><span x-text="registry[blocks[selected].type]?.label"></span></h3>
                        <x-ui.button variant="secondary" size="sm" icon="sparkle" x-on:click="translatePage()">{{ ___('AI translate') }}</x-ui.button>
                    </div>

                    <template x-if="registry[blocks[selected].type].settings.length===0 && registry[blocks[selected].type].content.length===0">
                        <p class="ui-small ui-text-2">{{ ___('This block renders its built-in section — reorder or toggle it; there is nothing to edit.') }}</p>
                    </template>

                    {{-- Settings (universal) --}}
                    <template x-for="f in registry[blocks[selected].type].settings" :key="f.name">
                        <div class="ui-field">
                            <span class="ui-field__label ui-capitalize" x-text="f.name.replace('_',' ')"></span>
                            <template x-if="f.type==='select'">
                                <select class="ui-input ui-input--sm" :aria-label="f.name.replace('_',' ')" x-model="blocks[selected].settings[f.name]" @change="touch()">
                                    <template x-for="opt in f.options" :key="opt"><option :value="opt" x-text="opt"></option></template>
                                </select>
                            </template>
                            <template x-if="f.type==='url'">
                                <input type="url" class="ui-input ui-input--sm" :aria-label="f.name.replace('_',' ')" x-model="blocks[selected].settings[f.name]" @input="touch()" placeholder="https://…">
                            </template>
                        </div>
                    </template>

                    {{-- Locale tabs --}}
                    <div class="ui-cluster" style="--ui-gap: 6px" role="group" aria-label="{{ ___('Language') }}" x-show="hasLocalized(blocks[selected].type)">
                        <template x-for="lc in locales" :key="lc">
                            <x-ui.button size="sm" ::class="activeLocale===lc ? '' : 'ui-btn--ghost'" ::aria-pressed="activeLocale===lc ? 'true' : 'false'" x-on:click="activeLocale=lc">
                                <span x-text="lc.toUpperCase()"></span>
                                <span x-show="lc===defaultLocale" title="{{ ___('Default') }}">★</span>
                                <span x-show="localeFilled(blocks[selected], lc)" aria-hidden="true">•</span>
                            </x-ui.button>
                        </template>
                    </div>

                    {{-- Content fields --}}
                    <template x-for="f in registry[blocks[selected].type].content" :key="f.name">
                        <div class="ui-field">
                            <span class="ui-field__label ui-capitalize" x-text="f.name.replace('_',' ')"></span>

                            <template x-if="f.localized && f.type==='text'">
                                <input type="text" class="ui-input ui-input--sm" :aria-label="f.name.replace('_',' ')" :maxlength="f.maxlen"
                                       :value="cval(f)" @input="setLoc(f, $event.target.value)"
                                       placeholder="{{ ___('Empty = built-in text') }}">
                            </template>
                            <template x-if="f.localized && f.type==='textarea'">
                                <textarea class="ui-input" :aria-label="f.name.replace('_',' ')" rows="3" :maxlength="f.maxlen"
                                       :value="cval(f)" @input="setLoc(f, $event.target.value)"
                                       placeholder="{{ ___('Empty = built-in text') }}"></textarea>
                            </template>
                            <template x-if="f.localized && f.type==='richtext'">
                                <textarea class="ui-input" :aria-label="f.name.replace('_',' ')" rows="6"
                                       :value="cval(f)" @input="setLoc(f, $event.target.value)"
                                       placeholder="{{ ___('Basic HTML allowed; scripts are stripped on save.') }}"></textarea>
                            </template>

                            {{-- universal (urls, flat) + upload helper --}}
                            <template x-if="!f.localized">
                                <span class="ui-cluster" style="--ui-gap: 8px">
                                    <input type="text" class="ui-input ui-input--sm ui-grow" :aria-label="f.name.replace('_',' ')" :maxlength="f.maxlen"
                                           x-model="blocks[selected].content[f.name]" @input="touch()">
                                    <span class="ui-btn ui-btn--secondary ui-btn--sm" x-show="f.type==='url'" role="button" tabindex="0"
                                          @click="$el.nextElementSibling.click()" @keydown.enter.prevent="$el.nextElementSibling.click()">
                                        <span class="ui-btn__icon"><x-ui.icon name="upload" size="16" /></span>{{ ___('Upload') }}
                                    </span>
                                    <input type="file" accept="image/*" class="ui-visually-hidden" tabindex="-1" aria-hidden="true" @change="uploadInto(f, $event)">
                                </span>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </x-ui.card>
    </div>

    {{-- Preview dialog --}}
    <dialog class="ui-dialog ui-dialog--xl" x-ref="previewModal" aria-labelledby="sp-preview-title">
        <div class="ui-dialog__panel ui-stack" style="block-size: 85vh; display: flex; flex-direction: column; padding: 0">
            <div class="ui-dialog__head" style="padding-block-end: var(--ui-space-3)">
                <div class="ui-cluster">
                    <h2 id="sp-preview-title">{{ ___('Preview (draft, noindex)') }}</h2>
                    <select class="ui-input ui-input--sm ui-input--auto" x-model="previewLocale" @change="reloadPreview()" aria-label="{{ ___('Language') }}">
                        <template x-for="lc in locales" :key="lc"><option :value="lc" x-text="lc.toUpperCase()"></option></template>
                    </select>
                </div>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.previewModal.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" /></button>
            </div>
            <iframe x-ref="previewFrame" title="{{ ___('Preview (draft, noindex)') }}" style="inline-size: 100%; flex: 1 1 auto; border: 0"></iframe>
        </div>
    </dialog>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
function sitePageComposer(cfg) {
    return {
        pageId: cfg.pageId,
        registry: cfg.registry,
        locales: cfg.locales,
        defaultLocale: cfg.defaultLocale,
        blocks: (cfg.doc.blocks || []).map(b => spNormalizeBlock(b, cfg.registry)),
        status: cfg.status,
        urls: cfg.urls,
        csrf: cfg.csrf,
        t: cfg.t,
        selected: null,
        activeLocale: cfg.defaultLocale,
        previewLocale: cfg.defaultLocale,
        dirty: false,
        statusText: '',
        dragI: null,
        _timer: null,
        showRevisions: false,
        revisions: [],

        touch() { this.dirty = true; this.statusText = this.t.unsaved; this.queueAutosave(); },
        queueAutosave() { clearTimeout(this._timer); this._timer = setTimeout(() => this.save(), 2000); },

        atMax(type) {
            const max = this.registry[type]?.max;
            if (!max) return false;
            return this.blocks.filter(b => b.type === type).length >= max;
        },
        hasLocalized(type) { return (this.registry[type]?.content || []).some(f => f.localized); },
        addBlock(type) {
            if (this.atMax(type)) return;
            this.blocks.push(spNewBlock(type, this.registry));
            this.selected = this.blocks.length - 1;
            this.touch();
        },
        async removeBlock(i) {
            if (!(await spConfirm(this.t.confirmDelete))) return;
            this.blocks.splice(i, 1);
            if (this.selected === i) this.selected = null;
            this.touch();
        },
        moveBlock(from, to) {
            if (from === null || from === to) return;
            const b = this.blocks.splice(from, 1)[0];
            this.blocks.splice(to, 0, b);
            this.selected = to;
            this.touch();
        },
        snippet(blk) {
            const c = blk.content || {};
            for (const k of ['headline','heading','body','title']) {
                if (c[k]) {
                    const v = typeof c[k] === 'object' ? (c[k][this.defaultLocale] || Object.values(c[k])[0]) : c[k];
                    if (v) return String(v).replace(/<[^>]+>/g,'').slice(0, 40);
                }
            }
            return '';
        },
        localeFilled(blk, lc) {
            const c = blk.content || {};
            return Object.values(c).some(v => v && typeof v === 'object' && v[lc]);
        },

        curField(f) {
            const c = this.blocks[this.selected].content;
            if (c[f.name] === undefined || (f.localized && Array.isArray(c[f.name]))) c[f.name] = f.localized ? {} : '';
            return c[f.name];
        },
        cval(f) {
            const v = this.curField(f);
            return (v && typeof v === 'object') ? (v[this.activeLocale] || '') : (v || '');
        },
        setLoc(f, val) {
            const c = this.blocks[this.selected].content;
            if (typeof c[f.name] !== 'object' || c[f.name] === null || Array.isArray(c[f.name])) c[f.name] = {};
            c[f.name][this.activeLocale] = val;
            this.touch();
        },

        payload() { return { version: 1, blocks: this.blocks }; },

        async save() {
            clearTimeout(this._timer);
            this.statusText = this.t.saving;
            const res = await fetch(this.urls.save, {
                method: 'PUT',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf,'Accept':'application/json'},
                body: JSON.stringify({ blocks: this.payload() })
            });
            if (res.ok) { this.dirty = false; this.statusText = this.t.saved; }
            else { this.statusText = 'Error'; }
        },
        async publish(unpublish = false) {
            if (this.dirty && !unpublish) await this.save();
            const res = await fetch(this.urls.publish, {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf,'Accept':'application/json'},
                body: JSON.stringify({ unpublish })
            });
            if (res.ok) { const j = await res.json(); this.status = j.status; if (this.showRevisions) this.loadRevisions(); }
        },
        async translatePage() {
            if (this.dirty) await this.save();
            const res = await fetch(this.urls.translate, {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf,'Accept':'application/json'},
                body: JSON.stringify({ mode: 'missing' })
            });
            const j = await res.json().catch(() => ({}));
            if (res.ok && j.blocks) { this.blocks = (j.blocks.blocks||[]).map(b => spNormalizeBlock(b, this.registry)); this.statusText = j.message || this.t.saved; }
            else { spToast(j.message || 'Translation unavailable'); }
        },
        toggleRevisions() {
            this.showRevisions = !this.showRevisions;
            if (this.showRevisions) this.loadRevisions();
        },
        async loadRevisions() {
            const res = await fetch(this.urls.revisions, {headers: {'Accept':'application/json'}});
            if (res.ok) { const j = await res.json(); this.revisions = j.revisions || []; }
        },
        async restoreRevision(id) {
            if (!(await spConfirm(this.t.confirmRestore))) return;
            const res = await fetch(this.urls.restoreBase.replace(/0$/, String(id)), {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf,'Accept':'application/json'},
            });
            const j = await res.json().catch(() => ({}));
            if (res.ok && j.blocks) { this.blocks = (j.blocks.blocks||[]).map(b => spNormalizeBlock(b, this.registry)); this.dirty = false; this.statusText = this.t.saved; }
        },
        async uploadInto(f, ev) {
            const file = ev.target.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('image', file);
            const res = await fetch(this.urls.upload, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json'},
                body: fd
            });
            const j = await res.json().catch(() => ({}));
            if (res.ok && j.url) { this.blocks[this.selected].content[f.name] = j.url; this.touch(); }
            else { spToast(j.message || 'Upload failed'); }
            ev.target.value = '';
        },
        openPreview() {
            this.reloadPreview();
            this.$refs.previewModal.showModal();
        },
        async reloadPreview() {
            if (this.dirty) await this.save();
            this.$refs.previewFrame.src = this.urls.preview + '?locale=' + this.previewLocale;
        },
    };
}
// Kit confirm dialog / toast (assets/owner-ui/admin.js), with the browser's as a fallback.
function spConfirm(message) {
    return window.adminUi ? window.adminUi.confirm('', { title: message, danger: true }) : Promise.resolve(window.confirm(message));
}
function spToast(message) {
    if (window.adminUi) { window.adminUi.toast(message, 'danger'); } else { window.alert(message); }
}
function spNormalizeBlock(b, registry) {
    const def = registry[b.type] || {settings:[],content:[]};
    // PHP empty assoc arrays arrive as JS ARRAYS ([]) — string props on arrays
    // are dropped by JSON.stringify, silently emptying every save. Coerce.
    if (!b.settings || Array.isArray(b.settings)) b.settings = {};
    if (!b.content || Array.isArray(b.content)) b.content = {};
    Object.keys(b.content).forEach(k => { if (Array.isArray(b.content[k]) && b.content[k].length === 0) b.content[k] = {}; });
    (def.settings||[]).forEach(f => { if (b.settings[f.name]===undefined) b.settings[f.name] = f.default ?? ''; });
    if (b.enabled === undefined) b.enabled = true;
    return b;
}
function spNewBlock(type, registry) {
    return spNormalizeBlock({ id: 'blk_'+Math.random().toString(16).slice(2,8), type, enabled: true, settings:{}, content:{} }, registry);
}
</script>
@php $alpineLocal = public_path('assets/vendor/alpinejs/alpine.min.js'); @endphp
{{-- Always the vendored build (checked by VendoredAlpineTest); the old CDN fallback loaded an unpinned
     Alpine 3.x range from a CDN with no SRI (ThirdPartyScriptIntegrityTest). --}}
<script nonce="{{ csp_nonce() }}" defer src="{{ asset('assets/vendor/alpinejs/alpine.min.js') }}?v={{ is_file($alpineLocal) ? filemtime($alpineLocal) : '1' }}"></script>
@endpush
