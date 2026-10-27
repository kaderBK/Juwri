{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('QR code generator'))
@section('page_title', ___('QR code generator'))
@section('page_sub', $store->title.' - '.___('Generate and download your menu QR code.'))

@section('page_actions')
    {{-- PARITY-RUN T10: this page stays as the quick "give me the code" screen;
         branding, logo-in-QR, frames and bulk print sheets live in the Designer. --}}
    @if(Route::has('qr.designer') && \App\Services\FeatureAccess::storeAllows($store->id, 'qr_designer'))
        <x-ui.button :href="route('qr.designer').'?store_id='.$store->id" variant="secondary" icon="sparkle">{{ ___('QR Designer') }}</x-ui.button>
    @endif
@endsection

@section('content')
@php
    $qrUrl = store_public_qr_url($store, request()->get('table'), $store_options->qr_source_label ?? null);
    $fg = $store_options->qr_fg_color ?? '#0a0a0a';
    $bg = $store_options->qr_bg_color ?? '#ffffff';
    $qrSvgBase = route('stores.qrSvg', $store->id);
    $qrSvgUrl  = $qrSvgBase.'?'.http_build_query(\Juwri\Core\Support\Views\StoreViewHelpers::arrayFilterNotEmpty([
        'fg' => $fg, 'bg' => $bg,
        'padding' => $store_options->qr_padding ?? 2,
        'table' => request()->get('table'),
    ]));
    $tableNo = optional($store->tables->firstWhere('key', request()->get('table')))->table_no;
@endphp

<div class="ui-scope ui-qr">
    {{-- Preview: the code as it will print — store name, table, and the link. --}}
    <section class="ui-card ui-qr__preview" aria-label="{{ ___('QR code') }}">
        <div class="ui-qr__poster">
            <p class="ui-qr__store">{{ $store->title }}</p>
            @if($tableNo)<x-ui.tag tone="accent">{{ ___('Table') }} {{ $tableNo }}</x-ui.tag>@endif
            <div id="qr-code-wrapper" class="ui-qr__code" data-url="{{ $qrUrl }}" data-svg-base="{{ $qrSvgBase }}" data-table="{{ request()->get('table', '') }}">
                <img id="qr-img" src="{{ $qrSvgUrl }}" alt="{{ ___('QR code') }}" width="240" height="240"
                     style="border-radius:{{ max(0, min(50, (int) ($store_options->qr_radius ?? 0))) }}px">
            </div>
            <p class="ui-qr__url ui-ltr">{{ preg_replace('#^https?://#', '', $qrUrl) }}</p>
        </div>
        <div class="ui-qr__actions">
            <button type="button" id="qr-download" class="ui-btn"><span class="ui-btn__icon"><x-ui.icon name="download" /></span>{{ ___('Download PNG') }}</button>
            <button type="button" id="qr-copy" class="ui-btn ui-btn--secondary"><span class="ui-btn__icon"><x-ui.icon name="share" /></span><span class="btn__label">{{ ___('Copy') }}</span></button>
        </div>
    </section>

    {{-- Settings --}}
    <form method="post" action="{{ route('stores.qrDesigner', $store->id) }}" enctype="multipart/form-data" class="ui-qr__form">
        @csrf
        <x-ui.card :title="___('Settings')">
            <div class="ui-qr__fields">
                @if(count($store->tables))
                    <div class="ui-qr__field ui-qr__field--wide">
                        <label class="ui-label" for="qr-table">{{ ___('Table') }}</label>
                        <select id="qr-table" name="table" class="ui-input">
                            <option value="">{{ ___('Not specified') }}</option>
                            @foreach($store->tables as $table)
                                <option value="{{ $table->key }}" @selected($table->key == request()->get('table'))>{{ $table->table_no }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="ui-qr__field ui-qr__field--wide">
                    <label class="ui-label" for="qr-src">{{ ___('Source label') }}</label>
                    <input type="text" id="qr-src" name="qr_source_label" class="ui-input" maxlength="64" value="{{ $store_options->qr_source_label ?? '' }}" placeholder="front-door">
                    <div class="ui-field__help">{{ ___('Adds ?src=label so analytics can track placement.') }}</div>
                </div>
                <div class="ui-qr__field">
                    <label class="ui-label" for="qr-fg">{{ ___('Foreground color') }}</label>
                    <input type="color" name="qr_fg_color" id="qr-fg" class="ui-input" value="{{ $fg }}">
                </div>
                <div class="ui-qr__field">
                    <label class="ui-label" for="qr-bg">{{ ___('Background color') }}</label>
                    <input type="color" name="qr_bg_color" id="qr-bg" class="ui-input" value="{{ $bg }}">
                </div>
                <div class="ui-qr__field">
                    <label class="ui-label" for="qr-pad">{{ ___('Padding') }}</label>
                    <input type="number" id="qr-pad" name="qr_padding" class="ui-input" min="0" max="5" value="{{ $store_options->qr_padding ?? 2 }}">
                </div>
                <div class="ui-qr__field">
                    <label class="ui-label" for="qr-rad">{{ ___('Corner radius') }}</label>
                    <input type="number" id="qr-rad" name="qr_radius" class="ui-input" min="0" max="50" step="10" value="{{ $store_options->qr_radius ?? 0 }}">
                </div>
            </div>
            <div class="ui-qr__save">
                <x-busy-button type="submit" variant="primary" icon="check" :busy-label="___('Saving…')">{{ ___('Save settings') }}</x-busy-button>
            </div>
        </x-ui.card>
    </form>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    (function () {
        var wrap = document.getElementById('qr-code-wrapper');
        var img = document.getElementById('qr-img');
        if (!wrap || !img) return;

        // Live preview: rebuild the server-rendered SVG URL when colors change.
        // Debounced — a color-picker drag fires dozens of input events per
        // second, and each URL change is a full server render.
        function svgUrl() {
            var params = new URLSearchParams();
            var fg = document.getElementById('qr-fg');
            var bg = document.getElementById('qr-bg');
            var padding = document.querySelector('input[name="qr_padding"]');
            if (fg) params.set('fg', fg.value);
            if (bg) params.set('bg', bg.value);
            if (padding) params.set('padding', padding.value);
            if (wrap.dataset.table) params.set('table', wrap.dataset.table);
            return wrap.dataset.svgBase + '?' + params.toString();
        }
        var refreshTimer = null;
        function refreshPreview() {
            clearTimeout(refreshTimer);
            refreshTimer = setTimeout(function () { img.src = svgUrl(); }, 250);
        }
        ['qr-fg', 'qr-bg'].forEach(function (id) {
            document.getElementById(id)?.addEventListener('input', refreshPreview);
        });
        document.querySelector('input[name="qr_padding"]')?.addEventListener('change', refreshPreview);

        // Download: rasterise the SVG onto a canvas for a crisp PNG.
        document.getElementById('qr-download')?.addEventListener('click', function () {
            var size = 1024;
            var image = new Image();
            image.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width = size; canvas.height = size;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(image, 0, 0, size, size);
                var a = document.createElement('a');
                a.download = 'qr-code.png';
                a.href = canvas.toDataURL('image/png');
                a.click();
            };
            image.src = img.src;
        });

        document.getElementById('qr-copy')?.addEventListener('click', function () {
            var btn = this;
            navigator.clipboard.writeText(wrap.dataset.url).then(function () {
                if (window.ownerUi) { window.ownerUi.toast(@js(___('Copied'))); return; }
                var label = btn.querySelector('.btn__label') || btn;
                label.textContent = @js(___('Copied'));
            });
        });
    })();
</script>
@endpush
