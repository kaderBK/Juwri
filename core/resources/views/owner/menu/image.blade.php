{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Manage menu').' - '.$store->title)
@section('page_title', ___('Image menu'))
@section('page_sub', $store->title.' - '.___('This store theme shows menu images only.'))

@section('page_actions')
    <x-ui.button type="button" icon="plus" x-data @click="$dispatch('img-add')">{{ ___('Add image') }}</x-ui.button>
@endsection

@php
    /* Folio (flip-book) stores get an Images | PDF source switch on top of the
       shared image editor. Option contract: rev/folio-theme/SPEC.md §3. */
    $isFolio = store_options($store->id, 'store_template') === 'folio';
    $folioSource = $isFolio && store_options($store->id, 'restaurant_folio_source') === 'pdf' ? 'pdf' : 'images';
    $folioPdf = $isFolio ? (string) (store_options($store->id, 'restaurant_folio_pdf') ?: '') : '';
    $folioPdfFile = $folioPdf !== '' ? public_path('storage/folio-pdf/'.basename($folioPdf)) : '';
    $folioPdfDate = ($folioPdfFile !== '' && is_file($folioPdfFile)) ? date('Y-m-d H:i', (int) filemtime($folioPdfFile)) : '';
    /* FP B4: hotspot editor feeds — the store's menu items (id+name) to tag, and
       any already-saved rects, keyed by imageMenuId. Both empty for non-folio. */
    $folioItems = $isFolio
        ? \Juwri\Core\Support\Views\OwnerOpsViewHelpers::menuIdNamePairs(\Juwri\Modules\Menu\Models\Menu::where('store_id', $store->id)->orderBy('name')->get(['id', 'name']))
        : [];
    $folioHotspots = $isFolio ? folio_json_option($store->id, 'restaurant_folio_hotspots') : [];
@endphp

@section('content')
<div class="ui-stack" x-data="linenImageMenu({{ \Illuminate\Support\Js::from([
        'tab' => $isFolio ? $folioSource : 'images',
        'items' => $folioItems,
        'hotspots' => (object) $folioHotspots,
        'saveUrl' => $isFolio ? route('theme.customizer.folioHotspots', $store->id) : '',
        'csrf' => csrf_token(),
    ]) }})" @img-add.window="openAdd()">
    @if($isFolio)
        <div class="ui-cluster" data-folio-source-tabs role="tablist" aria-label="{{ ___('Menu source') }}">
            <button type="button" class="ui-btn ui-btn--sm" :class="tab==='images' ? '' : 'ui-btn--secondary'" @click="tab='images'" role="tab" :aria-selected="tab==='images' ? 'true' : 'false'">{{ ___('Images') }}</button>
            <button type="button" class="ui-btn ui-btn--sm" :class="tab==='pdf' ? '' : 'ui-btn--secondary'" @click="tab='pdf'" role="tab" :aria-selected="tab==='pdf' ? 'true' : 'false'">{{ ___('PDF') }}</button>
            <span class="ui-muted ui-ms-auto">{{ ___('Guests currently see') }}: <strong>{{ $folioSource === 'pdf' ? ___('PDF') : ___('Images') }}</strong></span>
        </div>
    @endif

    <div @if($isFolio) x-show="tab==='images'" @endif class="ui-stack">
    <x-ui.banner tone="info">{{ ___('The current store theme supports images only.') }}</x-ui.banner>

    @if($store->image_menus->isEmpty())
        <x-ui.empty :title="___('No images yet')" :body="___('Upload your menu as images. Add your first below.')" icon="upload">
            <x-ui.button type="button" icon="plus" x-data @click="$dispatch('img-add')">{{ ___('Add image') }}</x-ui.button>
        </x-ui.empty>
    @else
        <div class="ui-form-grid">
            @foreach($store->image_menus as $menu)
                <div>
                    <article class="ui-fill meta-card img-menu-card ui-card">
                        <img class="img-menu-card__thumb" src="{{ asset('storage/menu/'.$menu->image) }}" alt="{{ $menu->name }}" data-ui-img-fallback="{{ asset('storage/menu/default.png') }}" loading="lazy" style="height:180px;object-fit:cover;">
                        <div class="img-menu-card__body ui-card__body">
                            <h3 class="ui-section-title">{{ $menu->name }}</h3>
                            <x-ui.tag :tone="$menu->active == '1' ? 'success' : 'neutral'">{{ $menu->active == '1' ? ___('Available') : ___('Unavailable') }}</x-ui.tag>
                        </div>
                        <div class="ui-cluster meta-card__footer ui-card__foot">
                            <x-ui.button type="button" variant="ghost" size="sm" icon="pencil"
                                @click="openEdit({{ \Illuminate\Support\Js::from(['id' => $menu->id, 'name' => $menu->name, 'active' => $menu->active]) }})">{{ ___('Edit') }}</x-ui.button>
                            @if($isFolio)
                                <x-ui.button type="button" variant="ghost" size="sm" icon="pin"
                                    @click="openHotspots({{ (int) $menu->id }}, {{ \Illuminate\Support\Js::from(asset('storage/menu/'.$menu->image)) }})">{{ ___('Hotspots') }}</x-ui.button>
                            @endif
                            <form action="{{ route('stores.deleteImageMenuItem', $store->id) }}" method="post" data-confirm="{{ ___('Delete this image?') }}" data-confirm-danger>
                                @csrf
                                <input type="hidden" name="id" value="{{ $menu->id }}">
                                <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Delete') }}</x-ui.button>
                            </form>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif
    </div>

    @if($isFolio)
        {{-- PDF source pane: current-PDF card, upload/replace, remove, and the guest-facing source switch --}}
        <div x-show="tab==='pdf'" x-cloak class="ui-stack" data-folio-pdf-pane>
            <x-ui.banner tone="info">{{ ___('Upload a print-ready PDF and Folio shows its pages as a flip book.') }}</x-ui.banner>

            @if($folioPdf !== '')
                <article class="meta-card ui-card">
                    <div class="ui-cluster ui-cluster--between ui-card__body">
                        <div>
                            <h3 class="ui-section-title">{{ basename($folioPdf) }}</h3>
                            @if($folioPdfDate !== '')
                                <p class="ui-muted">{{ ___('Uploaded') }}: {{ $folioPdfDate }}</p>
                            @endif
                        </div>
                        <div class="ui-cluster">
                            <a class="ui-btn ui-btn--sm ui-btn--secondary" href="{{ asset('storage/'.$folioPdf) }}" target="_blank" rel="noopener">{{ ___('View') }}</a>
                            <form action="{{ route('theme.customizer.folioPdf', $store->id) }}" method="post" data-confirm="{{ ___('Remove this PDF?') }}" data-confirm-danger>
                                @csrf
                                <input type="hidden" name="action" value="remove">
                                <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Remove') }}</x-ui.button>
                            </form>
                        </div>
                    </div>
                </article>
            @endif

            <form action="{{ route('theme.customizer.folioPdf', $store->id) }}" method="post" enctype="multipart/form-data" class="ui-stack">
                @csrf
                <div class="edit-field">
                    <label class="ui-label">{{ $folioPdf !== '' ? ___('Replace PDF') : ___('Upload PDF') }}</label>
                    <input type="file" name="pdf" class="ui-input" accept="application/pdf" required>
                    <p class="ui-muted">{{ ___('PDF up to 20 MB. Pages render in the viewer.') }}</p>
                </div>
                <input type="hidden" name="source" value="pdf">
                <div><x-busy-button type="submit" variant="primary" icon="check" :busy-label="___('Uploading…')">{{ ___('Upload') }}</x-busy-button></div>
            </form>

            <form action="{{ route('theme.customizer.folioPdf', $store->id) }}" method="post" class="ui-cluster ui-rule-top">
                @csrf
                <span class="ui-muted">{{ ___('Show guests') }}:</span>
                <label class="ui-check">
                    <input type="radio" name="source" value="images" @checked($folioSource !== 'pdf')>
                    <span>{{ ___('Images') }}</span>
                </label>
                <label class="ui-check">
                    <input type="radio" name="source" value="pdf" @checked($folioSource === 'pdf')>
                    <span>{{ ___('PDF') }}</span>
                </label>
                <x-ui.button type="submit" variant="ghost" size="sm" icon="check">{{ ___('Save source') }}</x-ui.button>
            </form>
        </div>
    @endif

    {{-- Add/Edit dialog --}}
    <dialog class="ui-dialog ui-dialog--flat" x-ref="dlg" style="max-width:480px;width:92vw;">
        <form :action="editId ? @js(route('stores.updateImageMenuItem', $store->id)) : @js(route('stores.addImageMenuItem', $store->id))" method="post" enctype="multipart/form-data">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-dialog__head ui-rule-bottom">
                <h3 class="h5" x-text="editId ? @js(___('Edit image menu')) : @js(___('Add image menu'))">{{ ___('Add image menu') }}</h3>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.dlg.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-dialog__body">
                <input type="hidden" name="id" x-model="editId">
                <div class="edit-field">
                    <label class="ui-label">{{ ___('Name') }}</label>
                    <input type="text" name="name" class="ui-input" x-model="name" required>
                </div>
                <div class="edit-field">
                    <label class="ui-label">{{ ___('Image') }}</label>
                    <input type="file" name="image" class="ui-input" accept="image/*" @change="previewFile($event)">
                    <img x-show="preview" :src="preview" x-cloak style="max-height:160px">
                </div>
                <label class="integration-toggle ui-check">
                    <input type="checkbox" name="active" value="1" :checked="active" @change="active = $event.target.checked">
                    <span>{{ ___('Available') }}</span>
                </label>
            </div>
            <div class="ui-cluster ui-cluster--end ui-dialog__foot ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.dlg.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-busy-button type="submit" variant="primary" icon="check" :busy-label="___('Saving…')">{{ ___('Save') }}</x-busy-button>
            </div>
        </form>
    </dialog>

    @if($isFolio)
        {{-- FP B4: hotspot editor — draw item rectangles on a page image --}}
        <dialog class="ui-dialog ui-dialog--flat" x-ref="hsDlg" style="max-width:640px;width:94vw;">
            <div class="ui-cluster ui-cluster--between ui-dialog__head ui-rule-bottom">
                <h3 class="ui-section-title">{{ ___('Menu hotspots') }}</h3>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.hsDlg.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-dialog__body">
                <p class="ui-muted">{{ ___('Drag on the page to draw a box over a dish, then pick the item it links to.') }}</p>
                <div class="ui-relative" x-ref="hsSurface" style="touch-action:none;user-select:none;cursor:crosshair;"
                     @pointerdown="hsDown($event)" @pointermove="hsMove($event)" @pointerup="hsUp($event)" @pointerleave="hsUp($event)">
                    <img :src="hsImage" alt="" class="ui-w-full ui-block" draggable="false" style="pointer-events:none;">
                    <template x-for="(r, i) in hsRects" :key="i">
                        <div class="ui-hotspot"
                             :style="`left:${r.x*100}%;top:${r.y*100}%;width:${r.w*100}%;height:${r.h*100}%;`">
                            <span class="ui-tag ui-hotspot__no" data-tone="accent" x-text="i+1"></span>
                        </div>
                    </template>
                    <div x-show="hsDrawing" x-cloak class="ui-hotspot ui-hotspot--draft"
                         :style="hsDrawing ? `left:${Math.min(hsDrawing.x0,hsDrawing.x1??hsDrawing.x0)*100}%;top:${Math.min(hsDrawing.y0,hsDrawing.y1??hsDrawing.y0)*100}%;width:${Math.abs((hsDrawing.x1??hsDrawing.x0)-hsDrawing.x0)*100}%;height:${Math.abs((hsDrawing.y1??hsDrawing.y0)-hsDrawing.y0)*100}%;` : ''"></div>
                </div>

                <template x-if="!hsRects.length">
                    <p class="ui-muted">{{ ___('No hotspots yet — draw one above.') }}</p>
                </template>
                <div class="ui-stack">
                    <template x-for="(r, i) in hsRects" :key="i">
                        <div class="ui-cluster">
                            <span class="ui-tag" data-tone="accent" x-text="i+1"></span>
                            <select class="ui-input ui-input--sm" x-model.number="r.item">
                                <option value="">{{ ___('Choose item…') }}</option>
                                <template x-for="it in items" :key="it.id">
                                    <option :value="it.id" x-text="it.name"></option>
                                </template>
                            </select>
                            <button type="button" class="ui-btn ui-btn--sm ui-btn--danger" @click="hsRects.splice(i,1)" aria-label="{{ ___('Remove') }}">&times;</button>
                        </div>
                    </template>
                </div>
            </div>
            <div class="ui-cluster ui-cluster--between ui-dialog__foot ui-rule-top">
                <span x-show="hsMsg" x-text="hsMsg" x-cloak class="ui-small ui-text-accent"></span>
                <div class="ui-cluster ui-ms-auto">
                    <x-ui.button type="button" variant="ghost" @click="$refs.hsDlg.close()">{{ ___('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" icon="check" ::disabled="hsSaving" @click="saveHotspots()">{{ ___('Save hotspots') }}</x-ui.button>
                </div>
            </div>
        </dialog>
    @endif
</div>

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    function linenImageMenu(cfg) {
        cfg = (typeof cfg === 'string') ? { tab: cfg } : (cfg || {});
        return {
            tab: cfg.tab || 'images',
            items: cfg.items || [],
            hotspots: cfg.hotspots || {},
            hsSaveUrl: cfg.saveUrl || '',
            hsCsrf: cfg.csrf || '',
            editId: '', name: '', active: true, preview: '',
            openAdd() { this.editId=''; this.name=''; this.active=true; this.preview=''; this.$refs.dlg.showModal(); },
            openEdit(d) { this.editId=d.id; this.name=d.name; this.active=(d.active==='1'||d.active===1||d.active===true); this.preview=''; this.$refs.dlg.showModal(); },
            previewFile(e) { const f=e.target.files[0]; if(f) this.preview=URL.createObjectURL(f); },

            // ── FP B4: hotspot editor ──────────────────────────────────────────
            hsPageId: null, hsImage: '', hsRects: [], hsDrawing: null, hsMsg: '', hsSaving: false,
            openHotspots(pageId, image) {
                this.hsPageId = pageId; this.hsImage = image || ''; this.hsMsg = '';
                this.hsRects = (this.hotspots[pageId] || []).map(r => ({ x:+r.x, y:+r.y, w:+r.w, h:+r.h, item:+r.item || '' }));
                this.$refs.hsDlg.showModal();
            },
            hsPoint(e) {
                const b = this.$refs.hsSurface.getBoundingClientRect();
                const cl = v => Math.min(1, Math.max(0, v));
                return { x: cl((e.clientX - b.left) / b.width), y: cl((e.clientY - b.top) / b.height) };
            },
            hsDown(e) { e.preventDefault(); const p = this.hsPoint(e); this.hsDrawing = { x0:p.x, y0:p.y, x1:p.x, y1:p.y }; },
            hsMove(e) { if (!this.hsDrawing) return; const p = this.hsPoint(e); this.hsDrawing.x1 = p.x; this.hsDrawing.y1 = p.y; },
            hsUp() {
                if (!this.hsDrawing) return;
                const d = this.hsDrawing; this.hsDrawing = null;
                const x = Math.min(d.x0, d.x1), y = Math.min(d.y0, d.y1);
                const w = Math.abs(d.x1 - d.x0), h = Math.abs(d.y1 - d.y0);
                if (w < 0.02 || h < 0.02) return;
                this.hsRects.push({ x:+x.toFixed(4), y:+y.toFixed(4), w:+w.toFixed(4), h:+h.toFixed(4), item:'' });
            },
            async saveHotspots() {
                this.hsSaving = true; this.hsMsg = '';
                const map = Object.assign({}, this.hotspots);
                const rects = this.hsRects.filter(r => r.item).map(r => ({ x:r.x, y:r.y, w:r.w, h:r.h, item:+r.item }));
                if (rects.length) map[this.hsPageId] = rects; else delete map[this.hsPageId];
                try {
                    const res = await fetch(this.hsSaveUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.hsCsrf, Accept: 'application/json' },
                        body: JSON.stringify({ hotspots: map }),
                    });
                    if (!res.ok) throw new Error('save');
                    this.hotspots = map;
                    this.$refs.hsDlg.close();
                } catch (e) {
                    this.hsMsg = @js(___('Save failed — check each box has an item.'));
                }
                this.hsSaving = false;
            },
        };
    }
</script>
@endpush
@endsection
