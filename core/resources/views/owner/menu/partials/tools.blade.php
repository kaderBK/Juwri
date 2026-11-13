{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Menu/catalog/service-list AI tools: CSV import (food only) + AI generator.
    Triggered via window events from page_actions. Shared by the food menu
    editor AND (vertical AI) the retail products / laundry services editors —
    $store is the only variable this partial requires; when the including view
    already computed a vocab map (retail/service controllers do), it is
    inherited automatically and reused instead of being recomputed.
--}}
@php
    $toolsItemForm = app(\App\Services\Verticals\VerticalRegistry::class)->itemForm($store->store_type ?? null);
    // Byte-identical gate: ANY store whose item form is 'food' (restaurant AND
    // hotel in-room dining) keeps today's exact wording/behaviour. Only the
    // NEW retail/service inclusions take the vocab-aware branch below.
    $toolsIsFood = $toolsItemForm === 'food';
    $toolsVocab = $vocab ?? app(\App\Services\Verticals\VerticalVocab::class)->map($store->store_type ?? null);
    $toolsCatalogLower = mb_strtolower($toolsVocab['catalog'] ?? ___('Menu'));
@endphp
<div x-data="linenMenuTools({
        importUrl: @js(Route::has('stores.menuImportCsv') ? route('stores.menuImportCsv', $store->id) : ''),
        aiGenerateUrl: @js(Route::has('stores.menu.aiGenerate') ? route('stores.menu.aiGenerate', $store->id) : ''),
        aiMergeUrl: @js(Route::has('stores.menu.aiMerge') ? route('stores.menu.aiMerge', $store->id) : ''),
        aiSaveUrl: @js(Route::has('stores.menu.aiSave') ? route('stores.menu.aiSave', $store->id) : ''),
        aiImagesEnabled: @js((bool) app(\Juwri\Modules\Ai\Services\Studio\GeminiImageProvider::class)->isConfigured()),
        token: @js(csrf_token()),
        failedText: @js(___('Something went wrong.')),
        needImageText: @js($toolsIsFood ? ___('Choose or take a photo of your menu first.') : ___('Choose or take a photo of your :label first.', ['label' => $toolsCatalogLower])),
        needTextText: @js(___('Describe your store first.')),
        badImageText: @js(___('That file is not an image or PDF.')),
        tooBigText: @js(___('A file is too large (max 15 MB each).')),
        pageWord: @js(___('page')),
        pagesWord: @js(___('pages')),
        readingText: @js(___('Reading')),
        mergingText: @js(___('Combining pages…')),
        imagesQueuedText: @js(___('Menu saved. AI photos are being generated in the background — they will appear on your items shortly.'))
    })"
    @menu-import.window="$refs.importDialog.showModal()"
    @menu-ai.window="$refs.aiDialog.showModal()">

    {{-- CSV import — food only. Retail has its own catalog CSV workflow
         (RetailItemController::importCsv, a different column format); laundry
         has none yet. Gating here avoids exposing a food-column CSV dialog
         (description/type/spice_level/availability/nutrition) on those editors. --}}
    @if($toolsIsFood)
    <dialog class="ui-dialog ui-dialog--flat" x-ref="importDialog" style="max-width:520px;width:92vw;">
        <form :action="importUrl" method="post" enctype="multipart/form-data">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-dialog__head ui-rule-bottom">
                <h3 class="ui-section-title">{{ ___('Import menu CSV') }}</h3>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.importDialog.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-dialog__body">
                <p class="ui-muted">
                    {{ ___('Upload a CSV with a header row. name and price are required; set the category with a category column (its name) or category_id. Other columns (description, type, spice_level, availability, nutrition) are optional.') }}
                    @if(Route::has('stores.menuExportCsv'))
                        <a href="{{ route('stores.menuExportCsv', $store->id) }}" data-busy-download data-busy-label="{{ ___('Preparing…') }}">{{ ___('Download current as template') }}</a>
                    @endif
                </p>
                <div class="edit-field">
                    <label class="ui-label">{{ ___('CSV file') }}</label>
                    <input type="file" name="csv_file" class="ui-input" accept=".csv,text/csv" required>
                </div>
            </div>
            <div class="ui-cluster ui-cluster--end ui-dialog__foot ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.importDialog.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-busy-button type="submit" variant="primary" icon="upload" :busy-label="___('Importing…')">{{ ___('Import') }}</x-busy-button>
            </div>
        </form>
    </dialog>
    @endif

    {{-- AI generator --}}
    <dialog class="ui-dialog ui-dialog--flat ui-dialog--lg" x-ref="aiDialog" style="max-width:720px;width:94vw;">
        <div class="ui-cluster ui-cluster--between ui-dialog__head ui-rule-bottom">
            <h3 class="ui-section-title">{{ $toolsIsFood ? ___('AI menu generator') : ___('AI :label generator', ['label' => $toolsVocab['catalog']]) }}</h3>
            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.aiDialog.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
        </div>
        <div class="ui-dialog__body">
            <template x-if="!result">
                <div>
                    {{-- AI transparency: vision chain (photo scan) is the driver for this modal. --}}
                    <x-ai-badge :store-id="$store->id" :needs-vision="true" />
                    <div role="tablist" aria-label="{{ $toolsIsFood ? ___('Menu source') : ___(':label source', ['label' => $toolsVocab['catalog']]) }}" class="ui-chips">
                        <button type="button" class="ui-chip" @click="mode='scan'" role="tab" :aria-selected="mode==='scan' ? 'true' : 'false'" :aria-current="mode==='scan' ? 'true' : null"><x-ui.icon name="upload" size="16" />{{ ___('Scan / upload') }}</button>
                        <button type="button" class="ui-chip" @click="mode='describe'" role="tab" :aria-selected="mode==='describe' ? 'true' : 'false'" :aria-current="mode==='describe' ? 'true' : null"><x-ui.icon name="sparkle" size="16" />{{ ___('Describe') }}</button>
                    </div>

                    <template x-if="mode==='scan'">
                        <div>
                            <style nonce="{{ csp_nonce() }}">
                                .ai-drop{display:flex;flex-direction:column;align-items:center;gap:.5rem;text-align:center;
                                    padding:2.5rem 1.5rem;border:2px dashed var(--bs-border-color);border-radius:.75rem;
                                    cursor:pointer;transition:border-color .15s,background .15s;background:var(--bs-body-bg)}
                                .ai-drop:hover,.ai-drop--over{border-color:var(--bs-primary);background:var(--bs-primary-bg-subtle)}
                                .ai-drop__ic{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;
                                    background:var(--bs-primary-bg-subtle);color:var(--bs-primary)}
                                .ai-drop__t{font-weight:600;font-size:1rem;line-height:1.3;color:var(--bs-body-color)}
                                .ai-drop__d{font-weight:400;font-size:.875rem;line-height:1.5;color:var(--bs-secondary-color);max-width:34ch}
                            </style>
                            <label class="ai-drop" :class="drag && 'ai-drop--over'"
                                   @dragover.prevent="drag=true" @dragleave.prevent="drag=false" @drop.prevent="dropFiles($event)">
                                <span class="ai-drop__ic" aria-hidden="true">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3.2"/></svg>
                                </span>
                                <span class="ai-drop__t">{{ ___('Tap to upload — or drop a photo/PDF here') }}</span>
                                <span class="ai-drop__d">{{ ___('Handwritten or printed. Multiple pages or a PDF work too — you review before saving.') }}</span>
                                <input type="file" accept="image/*,application/pdf" multiple @change="pickFiles($event)" hidden>
                            </label>
                            <p x-show="fileCount" x-text="fileLabel" class="ui-strong ui-small" x-cloak></p>
                            <template x-if="imagePreview">
                                <img :src="imagePreview" alt="{{ ___('Selected menu image') }}" style="max-width:100%;max-height:220px;">
                            </template>
                        </div>
                    </template>

                    <template x-if="mode==='describe'">
                        <div>
                            <div class="edit-field">
                                <label class="ui-label">{{ $toolsIsFood ? ___('Describe your store / cuisine') : ___('Describe your :label', ['label' => mb_strtolower($toolsVocab['items'])]) }}</label>
                                @php
                                    $toolsDescribePlaceholder = $toolsIsFood
                                        ? ___('e.g. A cozy Italian trattoria with pizzas, pastas, and desserts')
                                        : ($toolsItemForm === 'service'
                                            ? ___('e.g. A neighborhood laundry offering wash-and-fold, dry cleaning, and ironing')
                                            : ___('e.g. A cozy boutique selling candles, skincare, and home decor'));
                                @endphp
                                <textarea class="ui-input" x-model="prompt" rows="4" placeholder="{{ $toolsDescribePlaceholder }}"></textarea>
                            </div>
                            <label class="ui-cluster ui-check">
                                <input type="checkbox" x-model="withVariants">
                                <span>{{ ___('Also suggest variants (e.g. sizes) and paid extras') }}</span>
                            </label>
                        </div>
                    </template>

                    <p x-show="error" x-text="error" class="ui-text-danger ui-small" x-cloak></p>
                    <p x-show="loading && progress" x-text="progress" aria-live="polite" class="ui-text-accent ui-strong ui-small" x-cloak></p>
                </div>
            </template>
            <template x-if="result">
                <div class="ai-preview">
                    <style nonce="{{ csp_nonce() }}">
                        .ai-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;
                            background:var(--bs-primary-bg-subtle);color:var(--bs-body-color);
                            font-weight:400;font-size:.75rem;line-height:1.4}
                        .ai-chip--extra{background:var(--bs-success-bg-subtle)}
                        .ai-chip button{border:0;background:none;cursor:pointer;color:var(--bs-secondary-color);padding:0;line-height:1}
                    </style>
                    <p class="ui-text-2">{{ $toolsIsFood ? ___('Review and edit — then save to add these to your menu. Items with no price are highlighted; leave blank to save as free/0.') : ___('Review and edit — then save to add these to your :label. Items with no price are highlighted; leave blank to save as free/0.', ['label' => $toolsCatalogLower]) }}</p>
                    <template x-for="(cat, ci) in result.categories" :key="ci">
                        <div class="ai-preview__cat">
                            <input class="ui-input ui-strong" x-model="cat.name" aria-label="{{ ___('Category name') }}">
                            <ul class="ui-plain-list">
                                <template x-for="(item, ii) in (cat.items || [])" :key="ii">
                                    <li>
                                        <div class="ui-cluster">
                                            <input class="ui-input" x-model="item.name" aria-label="{{ ___('Item name') }}" style="flex:1">
                                            <input class="ui-input" x-model="item.price" :class="!hasPrice(item.price) && 'ui-input--warn'" style="width:92px" placeholder="{{ ___('price') }}" aria-label="{{ ___('Price') }}">
                                            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="cat.items.splice(ii, 1)" aria-label="{{ ___('Remove item') }}"><x-ui.icon name="x" size="18" /></button>
                                        </div>
                                        <div x-show="(item.variants || []).length || (item.extras || []).length" x-cloak
                                             class="ui-cluster">
                                            <template x-for="(v, vi) in (item.variants || [])" :key="'v'+vi">
                                                <span class="ai-chip">
                                                    <span x-text="variantLabel(v)"></span>
                                                    <button type="button" @click="item.variants.splice(vi, 1)" aria-label="{{ ___('Remove variant') }}">&times;</button>
                                                </span>
                                            </template>
                                            <template x-for="(ex, xi) in (item.extras || [])" :key="'x'+xi">
                                                <span class="ai-chip ai-chip--extra">
                                                    <span x-text="'+ ' + ex.title + (ex.price != null ? ' (' + ex.price + ')' : '')"></span>
                                                    <button type="button" @click="item.extras.splice(xi, 1)" aria-label="{{ ___('Remove extra') }}">&times;</button>
                                                </span>
                                            </template>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                            <x-ui.button type="button" variant="ghost" icon="plus" @click="(cat.items = cat.items || []).push({name:'',description:'',price:null})">{{ ___('Add item') }}</x-ui.button>
                        </div>
                    </template>
                    <p x-show="missingCount() > 0" aria-live="polite" class="ui-text-warning ui-small" x-cloak>
                        <span x-text="missingCount()"></span> {{ ___('item(s) have no price.') }}
                    </p>
                    <label x-show="aiImagesEnabled" x-cloak class="ui-cluster ui-check">
                        <input type="checkbox" x-model="genImages">
                        <span>{{ ___('Generate AI photos for these items (runs in the background after saving)') }}</span>
                    </label>
                </div>
            </template>
        </div>
        <div class="ui-cluster ui-cluster--end ui-dialog__foot ui-rule-top">
            <x-ui.button type="button" variant="ghost" @click="$refs.aiDialog.close()">{{ ___('Cancel') }}</x-ui.button>
            <x-ui.button type="button" variant="secondary" x-show="result" @click="result=null" x-cloak>{{ ___('Regenerate') }}</x-ui.button>
            <x-ui.button type="button" icon="sparkle" x-show="!result" @click="generate()" ::disabled="loading">
                <span x-text="loading ? @js(___('Generating…')) : @js(___('Generate'))"></span>
            </x-ui.button>
            <x-ui.button type="button" icon="check" x-show="result" x-cloak @click="save()" ::disabled="loading">
                <span x-text="loading ? @js(___('Saving…')) : @js($toolsIsFood ? ___('Save to menu') : ___('Save to :label', ['label' => $toolsCatalogLower]))"></span>
            </x-ui.button>
        </div>
    </dialog>
</div>

@push('scripts')
<script src="{{ asset('assets/global/pdfjs/render-pdf.js') }}"></script>
<script nonce="{{ csp_nonce() }}">
    window.linenMenuTools = function (config) {
        return {
            importUrl: config.importUrl, // exposed for the CSV form's :action binding
            mode: 'scan', prompt: '', files: [], fileCount: 0, fileLabel: '', imagePreview: '',
            progress: '', loading: false, error: '', result: null, drag: false,
            withVariants: false, genImages: false, aiImagesEnabled: config.aiImagesEnabled,

            variantLabel: function (v) {
                var opts = (v.options || []).map(function (o, i) {
                    var p = (v.prices || [])[i];
                    return o + (p != null && p !== '' ? ' ' + p : '');
                });
                return v.title + ': ' + opts.join(' / ');
            },

            dropFiles: function (e) {
                this.drag = false;
                this.pickFiles({ target: { files: e.dataTransfer.files } });
            },

            pickFiles: function (e) {
                var list = Array.prototype.slice.call(e.target.files || []);
                this.error = ''; this.files = []; this.imagePreview = ''; this.fileCount = 0; this.fileLabel = '';
                if (!list.length) return;
                for (var i = 0; i < list.length; i++) {
                    var f = list[i];
                    var okType = /^image\//.test(f.type) || f.type === 'application/pdf' || /\.pdf$/i.test(f.name || '');
                    if (!okType) { this.error = config.badImageText; return; }
                    if (f.size > 15 * 1024 * 1024) { this.error = config.tooBigText; return; }
                }
                this.files = list;
                this.fileCount = list.length;
                this.fileLabel = list.length + ' ' + (list.length === 1 ? config.pageWord : config.pagesWord);
                var firstImg = list.filter(function (f) { return /^image\//.test(f.type); })[0];
                if (firstImg) {
                    var self = this, reader = new FileReader();
                    reader.onload = function (ev) { self.imagePreview = ev.target.result; };
                    reader.readAsDataURL(firstImg);
                }
            },

            // Extract one draft per image, sequentially, updating progress. A page
            // that fails is skipped (not fatal) so one bad page can't kill the batch.
            _extractSequential: function (images) {
                var self = this, drafts = [], total = images.length;
                return images.reduce(function (chain, blob, idx) {
                    return chain.then(function () {
                        self.progress = config.readingText + ' ' + config.pageWord + ' ' + (idx + 1) + '/' + total;
                        var fd = new FormData();
                        fd.append('image', blob, 'page-' + (idx + 1) + '.png');
                        return fetch(config.aiGenerateUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token }, body: fd })
                            .then(function (r) { return r.json(); })
                            .then(function (d) { if (d.success && d.data) drafts.push(d.data); })
                            .catch(function () { /* skip this page */ });
                    });
                }, Promise.resolve()).then(function () { return drafts; });
            },

            generate: function () {
                if (!config.aiGenerateUrl || this.loading) return;
                var self = this;

                if (this.mode === 'describe') {
                    if (!this.prompt.trim()) { this.error = config.needTextText; return; }
                    this.loading = true; this.error = ''; this.progress = '';
                    var fd = new FormData(); fd.append('text', this.prompt);
                    fd.append('mode', 'describe');
                    if (this.withVariants) fd.append('with_variants', '1');
                    fetch(config.aiGenerateUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': config.token }, body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (d) { self.loading = false; if (d.success && d.data) { self.result = d.data; } else { self.error = d.error || config.failedText; } })
                        .catch(function () { self.loading = false; self.error = config.failedText; });
                    return;
                }

                // Scan mode: render (images pass-through, PDF -> page PNGs), extract each, merge.
                if (!this.files.length) { this.error = config.needImageText; return; }
                if (typeof window.renderFilesToImages !== 'function') { this.error = config.failedText; return; }
                this.loading = true; this.error = ''; this.progress = '';
                window.renderFilesToImages(this.files, {
                    maxPages: 15,
                    onProgress: function (p, n) { self.progress = config.readingText + ' ' + config.pageWord + ' ' + p + '/' + n; }
                }).then(function (images) {
                    if (!images.length) { throw new Error('no pages'); }
                    return self._extractSequential(images);
                }).then(function (drafts) {
                    if (!drafts || !drafts.length) { self.loading = false; self.progress = ''; self.error = config.failedText; return; }
                    if (drafts.length === 1) { self.loading = false; self.progress = ''; self.result = drafts[0]; return; }
                    self.progress = config.mergingText;
                    return fetch(config.aiMergeUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': config.token, 'Content-Type': 'application/json' },
                        body: JSON.stringify({ drafts: drafts })
                    }).then(function (r) { return r.json(); })
                      .then(function (d) { self.loading = false; self.progress = ''; if (d.success && d.data) { self.result = d.data; } else { self.error = d.error || config.failedText; } });
                }).catch(function () { self.loading = false; self.progress = ''; self.error = config.failedText; });
            },
            hasPrice: function (v) {
                return v !== null && v !== '' && v !== undefined && !isNaN(parseFloat(String(v).replace(',', '.')));
            },
            missingCount: function () {
                var self = this, n = 0;
                ((this.result && this.result.categories) || []).forEach(function (c) {
                    ((c.items) || []).forEach(function (it) { if (!self.hasPrice(it.price)) n++; });
                });
                return n;
            },
            save: function () {
                if (!this.result || !config.aiSaveUrl) return;
                this.loading = true;
                var self = this;
                fetch(config.aiSaveUrl, {
                    method:'POST',
                    headers:{'X-CSRF-TOKEN':config.token,'Content-Type':'application/json'},
                    body: JSON.stringify({ categories: this.result.categories, generate_images: this.genImages })
                }).then(function(r){ return r.json(); })
                  .then(function(d){
                      self.loading=false;
                      if(d.success){
                          if (d.images_queued) window.dashToast('success', config.imagesQueuedText);
                          window.location.reload();
                      } else { self.error=d.error||config.failedText; }
                  })
                  .catch(function(){ self.loading=false; self.error=config.failedText; });
            }
        };
    };
</script>
@endpush
