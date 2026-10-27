{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-upload-progress> — file upload with a REAL determinate progress bar.

  Wraps a file input and uploads each selected file over XHR so the browser's
  upload-progress events drive a kit progress bar (ui-meter; filename + size + %).
  Multi-file queue, per-file error state. Owner UI kit classes + --ui-* tokens only.

  ATTRIBUTES
    url        POST endpoint that receives the upload            (required)
    name       form field name for the file                      (default: "file")
    accept     input accept filter (e.g. "image/*")              (optional)
    multiple   allow multiple files                               (default: false)
    field      extra hidden fields as an assoc array, e.g.
               :field="['store_id' => $store->id]"           (optional)
    button-label  trigger label                                  (default: "Choose file")
    auto       start uploading on select                          (default: true)

  EVENTS (DOM CustomEvents, bubble from the component root)
    upload-file-complete   detail: { name, response }   per file success
    upload-file-error      detail: { name, error }      per file failure
    upload-complete        detail: { results }          all files settled

  Listen with Alpine on a wrapper:
    <div x-on:upload-complete="location.reload()">
        <x-upload-progress :url="route('media.store')" accept="image/*" multiple />
    </div>

  NOTES
    - CSRF token is sent automatically (X-CSRF-TOKEN header).
    - On success calls window.dashToast('success', …); on failure 'danger'.
    - If you must keep a plain multipart <form> post instead, use <x-busy-button>
      as the submit — this component is for the async/XHR path.
--}}
@props([
    'url',
    'name' => 'file',
    'accept' => null,
    'multiple' => false,
    'field' => [],
    'buttonLabel' => null,
    'auto' => true,
    'dropzone' => false,
])
@php
    $btnText = $buttonLabel ?: ___('Choose file');
@endphp
@once
{{-- Dropzone + [x-cloak] styles, on the kit tokens. Inline (nonce'd) so they
     apply before Alpine starts, not after the page's bottom scripts. --}}
<style nonce="{{ csp_nonce() }}">
    .dash-upload-progress [x-cloak] { display: none !important; }
    .dash-dropzone {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: var(--ui-space-2);
        inline-size: 100%;
        min-block-size: 148px;
        margin: 0;
        padding: var(--ui-space-6) var(--ui-space-4);
        text-align: center;
        cursor: pointer;
        border: 2px dashed var(--ui-line);
        border-radius: var(--ui-r-card);
        background: var(--ui-inset);
        color: var(--ui-ink);
        transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
    }
    .dash-dropzone:hover { border-color: var(--ui-accent); }
    .dash-dropzone:focus-within { border-color: var(--ui-accent); box-shadow: 0 0 0 4px var(--ui-accent-ring); }
    .dash-dropzone.is-dragging {
        border-color: var(--ui-accent);
        background: var(--ui-accent-soft);
        box-shadow: 0 0 0 4px var(--ui-accent-ring);
    }
    .dash-dropzone.is-uploading { cursor: progress; }
    .dash-dropzone-icon { display: inline-flex; color: var(--ui-accent-text); line-height: 1; }
    .dash-dropzone.is-dragging .dash-dropzone-icon { transform: translateY(-2px); }
    .dash-dropzone-icon .ui-spin { font-size: 1.75rem; }
    .dash-upload-progress .ui-btn:focus-within { outline: 3px solid var(--ui-accent-ring); outline-offset: 2px; }
    @media (prefers-reduced-motion: reduce) { .dash-dropzone { transition: none; } }
</style>
@endonce
<div
    {{ $attributes->merge(['class' => 'dash-upload-progress']) }}
    x-data="dashUploadProgress({
        url: @js($url),
        field: @js($field),
        token: @js(csrf_token()),
        auto: {{ $auto ? 'true' : 'false' }},
        strings: {
            uploading: @js(___('Uploading')),
            done: @js(___('Uploaded')),
            failed: @js(___('Upload failed')),
        },
    })"
>
    @if($dropzone)
        <label class="dash-dropzone"
               :class="{ 'is-dragging': dragging, 'is-uploading': uploading }"
               x-on:dragover.prevent="dragging = true"
               x-on:dragenter.prevent="dragging = true"
               x-on:dragleave.prevent="dragging = false"
               x-on:drop.prevent="onDrop($event)">
            <span class="dash-dropzone-icon" aria-hidden="true">
                <span class="ui-spin" x-cloak x-show="uploading"></span>
                <span x-show="!uploading"><x-ui.icon name="upload" size="26" /></span>
            </span>
            <span class="dash-dropzone-title ui-strong" x-text="uploading ? strings.uploading + '…' : @js($btnText)"></span>
            <span class="dash-dropzone-hint ui-muted" x-cloak x-show="!uploading">{{ ___('Drag & drop images here, or click to browse') }}</span>
            <input
                type="file"
                name="{{ $name }}"
                class="ui-visually-hidden"
                @if($accept) accept="{{ $accept }}" @endif
                @if($multiple) multiple @endif
                x-ref="input"
                x-on:change="onSelect($event)"
            >
        </label>
    @else
    <label class="ui-btn ui-btn--secondary ui-btn--sm">
        <span class="ui-spin" aria-hidden="true" x-cloak x-show="uploading"></span>
        <span x-text="uploading ? strings.uploading + '…' : @js($btnText)"></span>
        <input
            type="file"
            name="{{ $name }}"
            class="ui-visually-hidden"
            @if($accept) accept="{{ $accept }}" @endif
            @if($multiple) multiple @endif
            x-ref="input"
            x-on:change="onSelect($event)"
        >
    </label>
    @endif

    <ul class="ui-plain-list dash-upload-list" style="margin-block-start: var(--ui-space-2)" x-cloak x-show="items.length">
        <template x-for="item in items" :key="item.id">
            <li style="margin-block-end: var(--ui-space-2)">
                <div class="ui-cluster ui-cluster--between ui-small" style="flex-wrap: nowrap; --ui-gap: var(--ui-space-2)">
                    <span class="ui-truncate" style="max-inline-size: 70%;" x-text="item.name"></span>
                    <span class="ui-muted" x-text="item.sizeLabel + (item.state === 'error' ? '' : ' · ' + item.pct + '%')"></span>
                </div>
                <div class="ui-meter" style="margin-block-start: 4px;">
                    <div class="ui-meter__bar"
                         role="progressbar"
                         :aria-label="item.name"
                         :style="'inline-size: ' + (item.state === 'error' ? 100 : item.pct) + '%;'
                             + (item.state === 'done' ? ' background: var(--ui-success);' : '')
                             + (item.state === 'error' ? ' background: var(--ui-danger);' : '')"
                         :aria-valuenow="item.pct" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div class="ui-small ui-text-danger" style="margin-block-start: 4px" x-cloak x-show="item.state === 'error'" x-text="item.error"></div>
            </li>
        </template>
    </ul>
</div>

@once
@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    // Alpine factory for x-upload-progress. Registered once per page.
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('dashUploadProgress', (cfg) => ({
            url: cfg.url,
            field: cfg.field || {},
            token: cfg.token,
            auto: cfg.auto !== false,
            strings: cfg.strings || {},
            items: [],
            uploading: false,
            dragging: false,
            _seq: 0,
            onSelect(e) {
                const files = Array.from(e.target.files || []);
                if (!files.length) return;
                if (this.auto) this.uploadAll(files).finally(() => { this.$refs.input.value = ''; });
            },
            onDrop(e) {
                this.dragging = false;
                const dropped = Array.from((e.dataTransfer && e.dataTransfer.files) || []);
                const files = dropped.filter((f) => f.type.startsWith('image/'));
                if (!files.length) return;
                if (this.auto) this.uploadAll(files);
            },
            humanSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / 1048576).toFixed(1) + ' MB';
            },
            async uploadAll(files) {
                this.uploading = true;
                const results = [];
                for (const file of files) {
                    try {
                        const res = await this.uploadOne(file);
                        results.push({ name: file.name, ok: true, response: res });
                    } catch (err) {
                        results.push({ name: file.name, ok: false, error: String(err && err.message || err) });
                    }
                }
                this.uploading = false;
                this.$root.dispatchEvent(new CustomEvent('upload-complete', { bubbles: true, detail: { results } }));
                return results;
            },
            uploadOne(file) {
                const item = {
                    id: ++this._seq,
                    name: file.name,
                    sizeLabel: this.humanSize(file.size),
                    pct: 0,
                    state: 'uploading',
                    error: '',
                };
                this.items.push(item);
                return new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', this.url);
                    xhr.setRequestHeader('X-CSRF-TOKEN', this.token);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.upload.addEventListener('progress', (ev) => {
                        if (ev.lengthComputable) item.pct = Math.round((ev.loaded / ev.total) * 100);
                    });
                    xhr.addEventListener('load', () => {
                        let data = null;
                        try { data = JSON.parse(xhr.responseText); } catch (e) {}
                        if (xhr.status >= 200 && xhr.status < 300) {
                            item.pct = 100; item.state = 'done';
                            this.$root.dispatchEvent(new CustomEvent('upload-file-complete', { bubbles: true, detail: { name: item.name, response: data } }));
                            if (window.dashToast) window.dashToast('success', this.strings.done + ' — ' + item.name);
                            resolve(data);
                        } else {
                            item.state = 'error';
                            item.error = (data && (data.message || data.error)) || (this.strings.failed);
                            this.$root.dispatchEvent(new CustomEvent('upload-file-error', { bubbles: true, detail: { name: item.name, error: item.error } }));
                            if (window.dashToast) window.dashToast('danger', item.error);
                            reject(new Error(item.error));
                        }
                    });
                    xhr.addEventListener('error', () => {
                        item.state = 'error';
                        item.error = this.strings.failed;
                        this.$root.dispatchEvent(new CustomEvent('upload-file-error', { bubbles: true, detail: { name: item.name, error: item.error } }));
                        if (window.dashToast) window.dashToast('danger', item.error);
                        reject(new Error(item.error));
                    });
                    const fd = new FormData();
                    fd.append(@js($name), file);
                    Object.keys(this.field).forEach((k) => fd.append(k, this.field[k]));
                    xhr.send(fd);
                });
            },
        }));
    });
</script>
@endpush
@endonce
