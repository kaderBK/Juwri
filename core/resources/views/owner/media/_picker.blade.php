{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Reusable media picker dialog. Include ONCE per page:
        @include('owner.media._picker')
    Open from anywhere:
        openMediaPicker({ target:'gallery', storeId: 123, folder:'menu', multiple:true })
    Listen for the result on window:
        window.addEventListener('media-picker:picked', e => {
            if (e.detail.target !== 'gallery') return;
            e.detail.assets // [{ id, filename, name, url, folder }]
        });

    CONTRACT — DO NOT rename/drop (openers across the app rely on these):
      • Alpine factory:  linenMediaPicker()   (x-data on the dialog)
      • Public factories: window.imagePickerField(), window.galleryPickerField()
      • Global opener:    window.openMediaPicker()
      • Events:           media-picker:open  (window) → open dialog
                          media-picker:picked (window) → selection result
      • Dialog ref:       x-ref="mediaPickerDialog" (opened via showModal())
      • target-key convention: detail.target routes results to the right field

    FEATURES-5 Track B: source tabs — My library · Public library · Stock photos ·
    Google image search (BYOK, unlicensed) · AI Generate. Every non-library source
    COPIES the file into the owner's own MediaAsset (quota enforced) then selects it.
--}}
<dialog class="ui-sheet ui-mpick" x-ref="mediaPickerDialog" x-data="linenMediaPicker()" aria-labelledby="media-picker-title"
        @media-picker:open.window="open($event.detail)">
    <div class="ui-mpick__head">
        <h2 class="ui-sheet__title" id="media-picker-title">{{ ___('Pick an image') }}</h2>
        <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.mediaPickerDialog.close()" aria-label="{{ ___('Close') }}">
            <x-ui.icon name="x" size="18" />
        </button>
    </div>

    {{-- Source tabs --}}
    <nav class="ui-chips ui-mpick__tabs" aria-label="{{ ___('Image source') }}">
        <button type="button" class="ui-chip" :aria-current="tab==='mine' ? 'true' : null" @click="switchTab('mine')">{{ ___('My library') }}</button>
        <button type="button" class="ui-chip" :aria-current="tab==='public' ? 'true' : null" @click="switchTab('public')">{{ ___('Public library') }}</button>
        <button type="button" class="ui-chip" :aria-current="tab==='stock' ? 'true' : null" @click="switchTab('stock')">{{ ___('Stock photos') }}</button>
        <button type="button" class="ui-chip" :aria-current="tab==='google' ? 'true' : null" @click="switchTab('google')">{{ ___('Google') }}</button>
        <button type="button" class="ui-chip" :aria-current="tab==='ai' ? 'true' : null" @click="switchTab('ai')">{{ ___('AI Generate') }}</button>
    </nav>

    {{-- The body scrolls; the footer's "Use selected" stays on screen however long the grid. --}}
    <div class="ui-mpick__body">
        {{-- ===================== MY LIBRARY ===================== --}}
        <div x-show="tab==='mine'">
            <div class="ui-mpick__bar">
                <input class="ui-input ui-input--sm ui-mpick__grow" type="search" x-model.debounce.300ms="q" @input="load()" placeholder="{{ ___('Search file name…') }}" aria-label="{{ ___('Search file name…') }}">
                <label class="ui-btn ui-btn--secondary ui-btn--sm ui-mpick__upload">
                    <span class="ui-btn__icon"><x-ui.icon name="upload" size="16" /></span><span>{{ ___('Upload') }}</span>
                    <input type="file" accept="image/*" multiple class="ui-visually-hidden" @change="upload($event)">
                </label>
            </div>
            <div class="ui-mpick__grid" x-show="!loading">
                <template x-for="a in assets" :key="a.id">
                    <figure class="ui-mpick__tile" :class="{ 'is-picked': isSelected(a) }" @click="toggle(a)">
                        <img :src="a.url" :alt="a.name" loading="lazy">
                        <span class="ui-mpick__tick" x-show="isSelected(a)">✓</span>
                        <figcaption x-text="a.name || a.filename"></figcaption>
                    </figure>
                </template>
            </div>
            <p class="ui-mpick__note" x-show="!loading && assets.length === 0">{{ ___('No images in this folder yet. Upload above.') }}</p>
            <p class="ui-mpick__note" x-show="loading">{{ ___('Loading…') }}</p>
        </div>

        {{-- ===================== PUBLIC LIBRARY ===================== --}}
        <div x-show="tab==='public'">
            <div class="ui-mpick__bar">
                <input class="ui-input ui-input--sm ui-mpick__grow" type="search" x-model.debounce.300ms="pub.q" @input="loadPublic()" placeholder="{{ ___('Search title or tag…') }}" aria-label="{{ ___('Search title or tag…') }}">
                <select class="ui-input ui-input--sm ui-mpick__select" x-model="pub.category" @change="loadPublic()" aria-label="{{ ___('All categories') }}">
                    <option value="">{{ ___('All categories') }}</option>
                    @foreach (['food','drink','dessert','banner','interior','other'] as $c)
                        <option value="{{ $c }}">{{ ___(ucfirst($c)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-mpick__grid" x-show="!pub.loading">
                <template x-for="r in pub.results" :key="r.id">
                    <figure class="ui-mpick__tile" @click="copyPublic(r)">
                        <img :src="r.thumb" :alt="r.title" loading="lazy">
                        <figcaption x-text="r.title"></figcaption>
                    </figure>
                </template>
            </div>
            <p class="ui-mpick__note" x-show="!pub.loading && pub.results.length === 0">{{ ___('No images found.') }}</p>
            <p class="ui-mpick__note" x-show="pub.loading" x-text="busyText"></p>
        </div>

        {{-- ===================== STOCK / GOOGLE ===================== --}}
        <template x-for="src in ['stock','google']" :key="src">
          <div x-show="tab===src">
            <div class="ui-mpick__bar">
                <select class="ui-input ui-input--sm ui-mpick__select" x-model="ext.provider" x-show="tab==='stock'" aria-label="{{ ___('Stock photos') }}">
                    <template x-for="p in stockProviderOptions()" :key="p"><option :value="p" x-text="providerLabel(p)"></option></template>
                </select>
                <input class="ui-input ui-input--sm ui-mpick__grow" type="search" x-model="ext.q" @keydown.enter.prevent="searchExt()" :placeholder="'{{ ___('Search images…') }}'" aria-label="{{ ___('Search images…') }}">
                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="searchExt()">{{ ___('Search') }}</button>
            </div>
            <x-ui.banner tone="warning" class="ui-mpick__msg" x-show="tab==='google'">
                {{ ___('Google returns arbitrary web images that are NOT licensed. Verify you have the rights to use an image before adding it.') }}
            </x-ui.banner>
            <x-ui.banner class="ui-mpick__msg" x-show="tab==='stock' && stockProviderOptions().length === 0">
                {{ ___('No stock provider is configured. Add a Pexels or Unsplash key in image-source settings.') }}
            </x-ui.banner>
            <x-ui.banner class="ui-mpick__msg" x-show="tab==='google' && !googleConfigured">
                {{ ___('Google image search needs your own API key (key + search-engine ID) in image-source settings.') }}
            </x-ui.banner>
            <p class="ui-mpick__error" x-show="ext.error" x-text="ext.error"></p>
            <div class="ui-mpick__grid" x-show="!ext.loading">
                <template x-for="r in ext.results" :key="r.id">
                    <figure class="ui-mpick__tile" @click="copyExternal(r)">
                        <img :src="r.thumb" :alt="r.author" loading="lazy">
                        <figcaption x-text="r.author || r.source"></figcaption>
                    </figure>
                </template>
            </div>
            <p class="ui-mpick__note" x-show="ext.loading" x-text="busyText"></p>
            <p class="ui-mpick__note" x-show="!ext.loading && ext.results.length === 0 && ext.searched">{{ ___('No images found.') }}</p>
          </div>
        </template>

        {{-- ===================== AI GENERATE ===================== --}}
        <div x-show="tab==='ai'">
            <textarea class="ui-input ui-mpick__prompt" rows="2" x-model="ai.prompt" placeholder="{{ ___('Describe the image, e.g. a gourmet cheeseburger on a wooden board') }}" aria-label="{{ ___('AI Generate') }}"></textarea>
            <div class="ui-mpick__bar">
                <select class="ui-input ui-input--sm ui-mpick__select" x-model="ai.size" aria-label="{{ ___('Square') }}">
                    <option value="1024x1024">{{ ___('Square') }}</option>
                    <option value="1792x1024">{{ ___('Landscape') }}</option>
                    <option value="1024x1792">{{ ___('Portrait') }}</option>
                </select>
                <button type="button" class="ui-btn ui-btn--sm" @click="generateAi()" :disabled="ai.loading || !ai.prompt">
                    <span x-show="!ai.loading">{{ ___('Generate') }}</span>
                    <span x-show="ai.loading">{{ ___('Generating…') }}</span>
                </button>
            </div>
            <p class="ui-mpick__error" x-show="ai.error" x-text="ai.error"></p>
            <div x-show="ai.preview" class="ui-mpick__preview">
                <img :src="ai.preview" alt="{{ ___('Preview') }}">
                <div>
                    <button type="button" class="ui-btn ui-btn--success ui-btn--sm" @click="saveAi()" :disabled="ai.saving">{{ ___('Save to my library') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="ui-sheet__foot ui-mpick__foot">
        <span class="ui-muted ui-mpick__count" x-show="multiple && tab==='mine'" x-text="selected.length + ' {{ ___('selected') }}'"></span>
        <x-ui.button type="button" variant="ghost" @click="$refs.mediaPickerDialog.close()">{{ ___('Cancel') }}</x-ui.button>
        <x-ui.button type="button" icon="check" @click="confirmSelection()" ::disabled="selected.length === 0">{{ ___('Use selected') }}</x-ui.button>
    </div>
</dialog>

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    window.openMediaPicker = function (opts) {
        window.dispatchEvent(new CustomEvent('media-picker:open', { detail: opts || {} }));
    };

    // Reusable single-image field. Renders a hidden input (name=image by default) bound to
    // the chosen library filename, a thumbnail, and choose/clear buttons.
    window.imagePickerField = function (cfg) {
        return {
            filename: cfg.initial || '',
            url: cfg.url || '',
            target: cfg.target,
            storeId: cfg.storeId,
            folder: cfg.folder || 'other',
            init() {
                window.addEventListener('media-picker:picked', (e) => {
                    if (e.detail.target !== this.target) return;
                    const a = e.detail.assets[0];
                    if (a) { this.filename = a.filename; this.url = a.url; }
                });
            },
            choose() {
                window.openMediaPicker({ target: this.target, storeId: this.storeId, folder: this.folder, multiple: false });
            },
            clear() { this.filename = ''; this.url = ''; },
        };
    };

    // Reusable multi-image gallery field (up to `max`). Renders hidden name=gallery[] inputs.
    window.galleryPickerField = function (cfg) {
        return {
            items: (cfg.initial || []).slice(0, cfg.max || 5), // [{filename,url}]
            target: cfg.target,
            storeId: cfg.storeId,
            folder: cfg.folder || 'menu',
            max: cfg.max || 5,
            init() {
                window.addEventListener('media-picker:picked', (e) => {
                    if (e.detail.target !== this.target) return;
                    e.detail.assets.forEach((a) => {
                        if (this.items.length >= this.max) return;
                        if (this.items.some((i) => i.filename === a.filename)) return;
                        this.items.push({ filename: a.filename, url: a.url });
                    });
                });
            },
            add() {
                window.openMediaPicker({ target: this.target, storeId: this.storeId, folder: this.folder, multiple: true });
            },
            remove(filename) { this.items = this.items.filter((i) => i.filename !== filename); },
            reset(list) { this.items = (list || []).slice(0, this.max); },
        };
    };
    function linenMediaPicker() {
        return {
            target: '', storeId: 0, folder: '', multiple: false,
            q: '', assets: [], selected: [], loading: false,
            tab: 'mine',
            busyText: @js(___('Loading…')),
            // provider availability
            providersLoaded: false, availableProviders: [], googleConfigured: false,
            // public library
            pub: { q: '', category: '', results: [], loading: false },
            // stock/google
            ext: { provider: 'pexels', q: '', results: [], loading: false, error: '', searched: false },
            // ai
            ai: { prompt: '', size: '1024x1024', preview: '', loading: false, saving: false, error: '' },

            csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; },
            open(detail) {
                this.target = detail.target || '';
                this.storeId = detail.storeId || 0;
                this.folder = detail.folder || '';
                this.multiple = !!detail.multiple;
                this.q = ''; this.selected = []; this.assets = [];
                this.tab = 'mine';
                this.pub = { q: '', category: '', results: [], loading: false };
                this.ext = { provider: 'pexels', q: '', results: [], loading: false, error: '', searched: false };
                this.ai = { prompt: '', size: '1024x1024', preview: '', loading: false, saving: false, error: '' };
                this.$refs.mediaPickerDialog.showModal();
                this.load();
                this.loadProviders();
            },
            switchTab(t) {
                this.tab = t;
                if (t === 'public' && this.pub.results.length === 0) this.loadPublic();
                if (t === 'google') this.ext.provider = 'google_cse';
                if (t === 'stock' && !['pexels','unsplash'].includes(this.ext.provider)) {
                    this.ext.provider = this.stockProviderOptions()[0] || 'pexels';
                }
            },
            async loadProviders() {
                if (this.providersLoaded) return;
                try {
                    const res = await fetch(`{{ route('media.sources.providers') }}?store_id=` + this.storeId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const j = await res.json();
                    this.availableProviders = j.available || [];
                    this.googleConfigured = this.availableProviders.includes('google_cse');
                    const stock = this.stockProviderOptions();
                    if (stock.length) this.ext.provider = stock[0];
                    this.providersLoaded = true;
                } catch (e) {}
            },
            stockProviderOptions() { return this.availableProviders.filter(p => p === 'pexels' || p === 'unsplash'); },
            providerLabel(p) { return { pexels: 'Pexels', unsplash: 'Unsplash' }[p] || p; },

            // ----- my library -----
            async load() {
                if (!this.storeId) return;
                this.loading = true;
                const params = new URLSearchParams({ store_id: this.storeId, folder: this.folder, q: this.q });
                try {
                    const res = await fetch(`{{ route('media.list') }}?` + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const json = await res.json();
                    this.assets = json.assets || [];
                } catch (e) { this.assets = []; }
                this.loading = false;
            },
            isSelected(a) { return this.selected.some(s => s.id === a.id); },
            toggle(a) {
                if (this.multiple) {
                    this.isSelected(a) ? this.selected = this.selected.filter(s => s.id !== a.id) : this.selected.push(a);
                } else {
                    this.selected = this.isSelected(a) ? [] : [a];
                }
            },
            async upload(e) {
                const files = e.target.files;
                if (!files || !files.length) return;
                const fd = new FormData();
                fd.append('store_id', this.storeId);
                fd.append('folder', this.folder || 'other');
                for (const f of files) fd.append('images[]', f);
                this.loading = true;
                try {
                    const res = await fetch(`{{ route('media.store') }}`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd,
                    });
                    const json = await res.json();
                    (json.assets || []).forEach(a => { this.assets.unshift(a); this.toggle(a); });
                } catch (err) {}
                e.target.value = '';
                this.loading = false;
            },

            // ----- shared: a freshly-copied MediaAsset lands in "mine" + is selected -----
            adopt(asset) {
                this.assets.unshift(asset);
                this.toggle(asset);
                this.tab = 'mine';
                window.dashToast && window.dashToast('success', @js(___('Added to your library.')));
            },

            // ----- public library -----
            async loadPublic() {
                this.pub.loading = true;
                const params = new URLSearchParams({ q: this.pub.q, category: this.pub.category });
                try {
                    const res = await fetch(`{{ route('media.sources.public') }}?` + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const j = await res.json();
                    this.pub.results = j.results || [];
                } catch (e) { this.pub.results = []; }
                this.pub.loading = false;
            },
            async copyPublic(r) {
                this.pub.loading = true;
                try {
                    const res = await fetch(`{{ route('media.sources.public.copy') }}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ store_id: this.storeId, public_id: r.id, folder: this.folder || 'menu' }),
                    });
                    const j = await res.json();
                    if (res.ok && j.asset) this.adopt(j.asset);
                    else window.dashToast && window.dashToast('danger', j.error || @js(___('Could not add.')));
                } catch (e) {}
                this.pub.loading = false;
            },

            // ----- stock / google -----
            async searchExt() {
                if (!this.ext.q.trim()) return;
                this.ext.loading = true; this.ext.error = ''; this.ext.searched = true;
                const provider = this.tab === 'google' ? 'google_cse' : this.ext.provider;
                const params = new URLSearchParams({ store_id: this.storeId, provider, q: this.ext.q });
                try {
                    const res = await fetch(`{{ route('media.sources.stock.search') }}?` + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const j = await res.json();
                    this.ext.results = j.results || [];
                    this.ext.error = j.error || '';
                } catch (e) { this.ext.results = []; this.ext.error = @js(___('Search failed.')); }
                this.ext.loading = false;
            },
            async copyExternal(r) {
                if (this.tab === 'google' && !(await window.dashConfirm(@js(___('Google images are not licensed. Confirm you have the rights to use this image.'))))) return;
                this.ext.loading = true;
                const provider = this.tab === 'google' ? 'google_cse' : this.ext.provider;
                try {
                    const res = await fetch(`{{ route('media.sources.stock.copy') }}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({
                            store_id: this.storeId, provider, url: r.full, folder: this.folder || 'menu',
                            author: r.author, author_url: r.author_url, source_url: r.source_url, download: r.download,
                        }),
                    });
                    const j = await res.json();
                    if (res.ok && j.asset) this.adopt(j.asset);
                    else window.dashToast && window.dashToast('danger', j.error || @js(___('Could not add.')));
                } catch (e) {}
                this.ext.loading = false;
            },

            // ----- ai -----
            async generateAi() {
                if (!this.ai.prompt.trim()) return;
                this.ai.loading = true; this.ai.error = ''; this.ai.preview = '';
                try {
                    const res = await fetch(`{{ route('media.sources.ai.generate') }}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ store_id: this.storeId, prompt: this.ai.prompt, size: this.ai.size }),
                    });
                    const j = await res.json();
                    if (res.ok && j.preview) this.ai.preview = j.preview;
                    else this.ai.error = j.error || @js(___('Generation failed.'));
                } catch (e) { this.ai.error = @js(___('Generation failed.')); }
                this.ai.loading = false;
            },
            async saveAi() {
                if (!this.ai.preview) return;
                this.ai.saving = true;
                try {
                    const res = await fetch(`{{ route('media.sources.ai.save') }}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ store_id: this.storeId, preview: this.ai.preview, prompt: this.ai.prompt, folder: this.folder || 'menu' }),
                    });
                    const j = await res.json();
                    if (res.ok && j.asset) { this.ai.preview = ''; this.ai.prompt = ''; this.adopt(j.asset); }
                    else window.dashToast && window.dashToast('danger', j.error || @js(___('Could not save.')));
                } catch (e) {}
                this.ai.saving = false;
            },

            confirmSelection() {
                if (!this.selected.length) return;
                window.dispatchEvent(new CustomEvent('media-picker:picked', {
                    detail: { target: this.target, assets: this.selected.slice() }
                }));
                this.$refs.mediaPickerDialog.close();
            },
        };
    }
</script>
@endpush
