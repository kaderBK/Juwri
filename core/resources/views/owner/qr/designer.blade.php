{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('QR Designer'))
@section('page_title', ___('QR Designer'))
@section('page_sub', $store->title.' - '.___('One design, used by every QR code this store prints.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
@php
    /* The Alpine state is seeded from the SAVED design so a reload never shows a
       preview that differs from what the store's printed codes carry. */
    $seed = [
        'fg_color'      => $qrSettings['fg'],
        'bg_color'      => $qrSettings['bg'],
        'eye_color'     => $qrSettings['eye'],
        'gradient_on'   => (bool) $qrSettings['gradient_to'],
        'gradient_to'   => $qrSettings['gradient_to'] ?: '#4338ca',
        'gradient_type' => $qrSettings['gradient_type'],
        'dot_style'     => $qrSettings['dot_style'],
        'eye_style'     => $qrSettings['eye_style'],
        'logo_file'     => $qrSettings['logo_file'] ?: '',
        'logo_url'      => $qrSettings['logo_file'] ? media_url('storage/menu/', $qrSettings['logo_file'], $rid) : '',
        'logo_scale'    => $qrSettings['logo_scale'],
        'frame'         => $qrSettings['frame'],
        'frame_text'    => $qrSettings['frame_text'] ?: '',
        'quiet_zone'    => $qrSettings['quiet_zone'],
        'size'          => $qrSettings['size'],
        'preset'        => $design->preset ?? '',
        // The preview box is x-html-bound, so Alpine needs the server render to
        // adopt on boot or the page shows an empty frame until something changes.
        'initialSvg'    => $previewSvg,
    ];
    $dl = route('qr.designer.download').'?store_id='.$rid;
@endphp

{{-- Alpine 3 calls the component's init() itself; a second call from x-init ran it twice (double polling / listeners; the customizer's second load() overwrote edits made meanwhile). --}}
<div class="ui-scope ui-qrd" x-data="qrDesigner(@js($seed))">
    @if(session('success'))
        <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
    @endif
    @if(session('error'))
        <x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>
    @endif

    <div class="ui-qrd__grid">

        {{-- ─────────────────────────── Live preview ─────────────────────────── --}}
        <aside class="ui-qrd__side">
            <x-ui.card :title="___('Live preview')" class="ui-qrd__preview">
                <x-slot:actions>
                    <x-ui.tag x-text="ecLabel"></x-ui.tag>
                </x-slot:actions>
                {{-- The injected SVG carries its own width/height attributes, so it
                     would render at full size and spill out of the card. The lane
                     sheet constrains the child explicitly (.ui-qrd__canvas svg). --}}
                <div class="ui-qrd__stage">
                    <div class="ui-qrd__canvas" x-html="svg">{!! $previewSvg !!}</div>
                </div>

                <x-ui.banner tone="danger" x-show="!ok" x-cloak>
                    <strong>{{ ___('This code will not scan') }}</strong>
                    <span class="ui-qrd__msg" x-text="message"></span>
                </x-ui.banner>
                <p class="ui-qrd__hint" x-show="ok" x-cloak>
                    {{ ___('Checked against a test scan on the server before it can be saved.') }}
                </p>

                <div class="ui-qrd__save">
                    <x-ui.button type="button" icon="check" block ::disabled="!ok || saving" @click="save()">
                        <span x-text="saving ? @js(___('Saving…')) : @js(___('Save design'))">{{ ___('Save design') }}</span>
                    </x-ui.button>
                    <form method="post" action="{{ route('qr.designer.reset') }}?store_id={{ $rid }}"
                          data-confirm="{{ ___('Reset to the classic black QR code?') }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" icon="refresh" block>{{ ___('Reset') }}</x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        </aside>

        {{-- ─────────────────────────── Editor ─────────────────────────── --}}
        <div class="ui-qrd__main">

            {{-- Preset gallery --}}
            <x-ui.card :title="___('Start from a template')">
                <div class="ui-qrd__presets">
                    @foreach($presets as $preset)
                        <button type="button" class="ui-qrd__preset"
                                :aria-pressed="preset === @js($preset['key']) ? 'true' : 'false'"
                                @click="applyPreset(@js($preset['key']))">
                            <span class="ui-qrd__thumb" aria-hidden="true">{!! $preset['svg'] !!}</span>
                            <span class="ui-qrd__preset-label">{{ $preset['label'] }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="ui-qrd__hint">
                    {{ ___('Applies to all QR codes: menu, tables, rooms, branches, kiosk and print sheets.') }}
                </p>
            </x-ui.card>

            {{-- Colours --}}
            <x-ui.card :title="___('Colours')">
                <div class="ui-qrd__swatches">
                    <label class="ui-qrd__swatch">
                        <input type="color" class="ui-qrd__color" x-model="fg_color" @input="refresh()">
                        <span>{{ ___('Foreground color') }}</span>
                    </label>
                    <label class="ui-qrd__swatch">
                        <input type="color" class="ui-qrd__color" x-model="bg_color" @input="refresh()">
                        <span>{{ ___('Background color') }}</span>
                    </label>
                    <label class="ui-qrd__swatch">
                        <input type="color" class="ui-qrd__color" x-model="eye_color" @input="refresh()">
                        <span>{{ ___('Corner color') }}</span>
                    </label>
                </div>
                <div class="ui-qrd__switch">
                    <x-ui.switch id="qr-gradient" :label="___('Use a gradient')" x-model="gradient_on" @change="refresh()" />
                </div>
                <div class="ui-qrd__fields" x-show="gradient_on" x-cloak>
                    <label class="ui-qrd__swatch">
                        <input type="color" class="ui-qrd__color" x-model="gradient_to" @input="refresh()">
                        <span>{{ ___('Gradient end color') }}</span>
                    </label>
                    <div>
                        <label class="ui-label" for="qr-gradient-type">{{ ___('Gradient style') }}</label>
                        <select id="qr-gradient-type" class="ui-input" x-model="gradient_type" @change="refresh()">
                            <option value="linear">{{ ___('Linear') }}</option>
                            <option value="radial">{{ ___('Radial') }}</option>
                        </select>
                    </div>
                </div>
            </x-ui.card>

            {{-- Shape --}}
            <x-ui.card :title="___('Shape')">
                <div class="ui-qrd__fields ui-qrd__fields--3">
                    <div>
                        <label class="ui-label" for="qr-dot-style">{{ ___('Dot style') }}</label>
                        <select id="qr-dot-style" class="ui-input" x-model="dot_style" @change="refresh()">
                            <option value="square">{{ ___('Square') }}</option>
                            <option value="rounded">{{ ___('Rounded') }}</option>
                            <option value="dots">{{ ___('Dots') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="ui-label" for="qr-eye-style">{{ ___('Corner style') }}</label>
                        <select id="qr-eye-style" class="ui-input" x-model="eye_style" @change="refresh()">
                            <option value="square">{{ ___('Square') }}</option>
                            <option value="rounded">{{ ___('Rounded') }}</option>
                            <option value="circle">{{ ___('Circle') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="ui-label" for="qr-quiet">{{ ___('Quiet space') }}</label>
                        <input id="qr-quiet" type="number" class="ui-input" min="4" max="8" inputmode="numeric" x-model.number="quiet_zone" @change="refresh()">
                        <small class="ui-qrd__hint">{{ ___('Blank modules around the code. 4 is the standard.') }}</small>
                    </div>
                </div>
            </x-ui.card>

            {{-- Logo --}}
            <x-ui.card :title="___('Logo in the middle')">
                <div class="ui-qrd__logo">
                    <div class="ui-qrd__logo-box">
                        <template x-if="logo_url">
                            <img :src="logo_url" alt="">
                        </template>
                        <template x-if="!logo_url">
                            <span>{{ ___('None') }}</span>
                        </template>
                    </div>
                    <div class="ui-qrd__logo-actions">
                        <x-ui.button type="button" variant="secondary" size="sm" icon="upload" @click="pickLogo()">{{ ___('Choose image') }}</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" icon="trash" x-show="logo_file" x-cloak @click="clearLogo()">{{ ___('Remove') }}</x-ui.button>
                    </div>
                </div>
                <div class="ui-qrd__range" x-show="logo_file" x-cloak>
                    <label class="ui-label" for="qr-logo-scale">{{ ___('Logo size') }} — <span class="ui-ltr" x-text="logo_scale + '%'"></span></label>
                    <input id="qr-logo-scale" type="range" class="ui-range" min="5" max="25" x-model.number="logo_scale" @change="refresh()">
                    <small class="ui-qrd__hint">{{ ___('A logo forces the highest error correction, and may cover at most 25% of the code.') }}</small>
                </div>
            </x-ui.card>

            {{-- Frame --}}
            <x-ui.card :title="___('Frame')">
                <div class="ui-qrd__fields">
                    <div>
                        <label class="ui-label" for="qr-frame">{{ ___('Frame') }}</label>
                        <select id="qr-frame" class="ui-input" x-model="frame" @change="refresh()">
                            <option value="none">{{ ___('No frame') }}</option>
                            <option value="bottom">{{ ___('Label below') }}</option>
                            <option value="top">{{ ___('Label above') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="ui-label" for="qr-frame-text">{{ ___('Frame text') }}</label>
                        <input id="qr-frame-text" type="text" class="ui-input" maxlength="60" x-model="frame_text"
                               @input.debounce.400ms="refresh()" placeholder="{{ ___('Scan to order') }}">
                    </div>
                </div>
            </x-ui.card>

            {{-- Export + rotation --}}
            <x-ui.card :title="___('Download & print')" variant="flush">
                <x-ui.list class="ui-qrd__list">
                    <x-ui.row :href="$dl.'&format=png'" icon="download" :title="___('Menu QR (PNG)')" />
                    <x-ui.row :href="$dl.'&format=svg'" icon="download" :title="___('Menu QR (SVG)')" />
                    <x-ui.row :href="route('qr.designer.sheet').'?store_id='.$rid" icon="print" :title="___('A4 print sheet (PDF)')" />
                    <x-ui.row :href="route('qr.designer.zip').'?store_id='.$rid" icon="download" :title="___('Download all (ZIP)')" />
                </x-ui.list>
                <p class="ui-qrd__hint ui-qrd__pad">{{ ___('The print sheet lays every table QR out on A4 with cut marks.') }}</p>
            </x-ui.card>

            {{-- Bio page QR (biolink-tpl lane-d T2-1) — only when the bio page is live --}}
            @if(!empty($bioLive))
                <x-ui.card :title="___('Bio page QR code')">
                    <div class="ui-qrd__logo">
                        @if(!empty($bioSvg))
                            <div class="ui-qrd__bio">{!! $bioSvg !!}</div>
                        @endif
                        <div class="ui-qrd__logo-actions">
                            <x-ui.button :href="$dl.'&target=bio&format=png'" variant="secondary" size="sm" icon="download">{{ ___('Bio QR (PNG)') }}</x-ui.button>
                            <x-ui.button :href="$dl.'&target=bio&format=svg'" variant="ghost" size="sm" icon="download">{{ ___('Bio QR (SVG)') }}</x-ui.button>
                        </div>
                    </div>
                    <p class="ui-qrd__hint">
                        {{ ___('This code opens your bio page. A menu QR always goes straight to the menu — the only way to send a printed code to the bio page is this dedicated bio QR.') }}
                    </p>
                </x-ui.card>
            @endif

            {{-- Tables --}}
            <x-ui.card :title="___('Table QR codes')" variant="flush">
                @if($tables->count())
                    <x-slot:actions>
                        <form method="post" action="{{ route('qr.designer.rotateAll') }}?store_id={{ $rid }}"
                              data-confirm="{{ ___('Regenerate every table QR? Every printed code stops working.') }}" data-confirm-danger>
                            @csrf
                            <x-ui.button type="submit" variant="danger" size="sm" icon="refresh">{{ ___('Regenerate all') }}</x-ui.button>
                        </form>
                    </x-slot:actions>
                @endif
                @if($tables->isEmpty())
                    <x-ui.empty icon="qr" :title="___('No tables yet')"
                                :body="___('Add tables to generate a unique QR code and link for each one.')" />
                @else
                    <table class="ui-table ui-qrd__table">
                        <thead><tr><th>{{ ___('Table') }}</th><th><span class="ui-visually-hidden">{{ ___('QR') }}</span></th></tr></thead>
                        <tbody>
                            @foreach($tables as $table)
                                <tr>
                                    <td data-primary data-label="{{ ___('Table') }}"><strong class="ui-ltr">#{{ $table->table_no }}</strong> <span class="ui-qrd__sub">{{ $table->label }}</span></td>
                                    <td class="ui-catalog__row-actions" data-label="{{ ___('QR') }}">
                                        <a class="ui-btn ui-btn--ghost ui-btn--sm" href="{{ $dl.'&target=table&id='.$table->id.'&format=png' }}"><x-ui.icon name="download" /> <span class="ui-ltr">PNG</span></a>
                                        <a class="ui-btn ui-btn--ghost ui-btn--sm" href="{{ $dl.'&target=table&id='.$table->id.'&format=svg' }}"><x-ui.icon name="download" /> <span class="ui-ltr">SVG</span></a>
                                        <form method="post" action="{{ route('qr.designer.rotate') }}?store_id={{ $rid }}"
                                              data-confirm="{{ ___('Regenerate this QR? The printed code stops working.') }}" data-confirm-danger>
                                            @csrf
                                            <input type="hidden" name="table_id" value="{{ $table->id }}">
                                            <button type="submit" class="ui-icon-btn" aria-label="{{ ___('Regenerate') }}" title="{{ ___('Regenerate') }}"><x-ui.icon name="refresh" /></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-ui.card>

            @if($branches->count() > 1)
                <x-ui.card :title="___('Branch QR codes')" variant="flush">
                    <table class="ui-table ui-qrd__table">
                        <thead><tr><th>{{ ___('Branch') }}</th><th><span class="ui-visually-hidden">{{ ___('QR') }}</span></th></tr></thead>
                        <tbody>
                            @foreach($branches as $branch)
                                <tr>
                                    <td data-primary data-label="{{ ___('Branch') }}">{{ $branch->name }}</td>
                                    <td class="ui-catalog__row-actions" data-label="{{ ___('QR') }}">
                                        <a class="ui-btn ui-btn--ghost ui-btn--sm" href="{{ $dl.'&target=branch&id='.$branch->id.'&format=png' }}"><x-ui.icon name="download" /> <span class="ui-ltr">PNG</span></a>
                                        <a class="ui-btn ui-btn--ghost ui-btn--sm" href="{{ $dl.'&target=branch&id='.$branch->id.'&format=svg' }}"><x-ui.icon name="download" /> <span class="ui-ltr">SVG</span></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.card>
            @endif
        </div>
    </div>

    {{-- Hidden mirror of the editor state. Explicit inputs rather than an x-for
         over payload(): the form is submitted programmatically, and a loop that
         has not re-rendered yet would post a stale design. --}}
    <form method="post" action="{{ route('qr.designer.save') }}?store_id={{ $rid }}" x-ref="saveForm" hidden>
        @csrf
        <input type="hidden" name="fg_color" :value="fg_color">
        <input type="hidden" name="bg_color" :value="bg_color">
        <input type="hidden" name="eye_color" :value="eye_color">
        <input type="hidden" name="gradient_to" :value="gradient_on ? gradient_to : ''">
        <input type="hidden" name="gradient_type" :value="gradient_type">
        <input type="hidden" name="dot_style" :value="dot_style">
        <input type="hidden" name="eye_style" :value="eye_style">
        <input type="hidden" name="logo_file" :value="logo_file || ''">
        <input type="hidden" name="logo_scale" :value="logo_scale">
        <input type="hidden" name="frame" :value="frame">
        <input type="hidden" name="frame_text" :value="frame_text || ''">
        <input type="hidden" name="quiet_zone" :value="quiet_zone">
        <input type="hidden" name="size" :value="size">
        <input type="hidden" name="preset" :value="preset || ''">
    </form>
</div>

@include('owner.media._picker')
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function qrDesigner(seed) {
        return Object.assign({}, seed, {
            /* Seeded from the server render, NOT ''. The preview box is bound with
               x-html="svg", so an empty initial value wipes the server-rendered
               QR the moment Alpine boots and the page lands with a blank preview
               until the owner happens to change a control. */
            svg: seed.initialSvg || '',
            ok: true,
            message: '',
            ec: 'M',
            saving: false,
            timer: null,

            get ecLabel() {
                return @js(___('Error correction')) + ': ' + this.ec;
            },

            init() {
                // The server-rendered preview is already on screen; only refresh
                // once the owner actually changes something.
                window.addEventListener('media-picker:picked', (e) => {
                    if (!e.detail || e.detail.target !== 'qr-logo') return;
                    const asset = (e.detail.assets || [])[0];
                    if (!asset) return;
                    this.logo_file = asset.filename;
                    this.logo_url = asset.url;
                    if (this.logo_scale > 25) this.logo_scale = 20;
                    this.refresh();
                });
            },

            payload() {
                return {
                    fg_color: this.fg_color,
                    bg_color: this.bg_color,
                    eye_color: this.eye_color,
                    gradient_to: this.gradient_on ? this.gradient_to : '',
                    gradient_type: this.gradient_type,
                    dot_style: this.dot_style,
                    eye_style: this.eye_style,
                    logo_file: this.logo_file || '',
                    logo_scale: this.logo_scale,
                    frame: this.frame,
                    frame_text: this.frame_text || '',
                    quiet_zone: this.quiet_zone,
                    size: this.size,
                    preset: this.preset || '',
                };
            },

            pickLogo() {
                window.openMediaPicker({ target: 'qr-logo', storeId: {{ (int) $rid }}, folder: 'menu' });
            },

            clearLogo() {
                this.logo_file = '';
                this.logo_url = '';
                this.refresh();
            },

            applyPreset(key) {
                this.preset = key;
                fetch(@js(route('qr.designer.preview')) + '?store_id={{ (int) $rid }}&preset=' + encodeURIComponent(key), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then((r) => r.json())
                    .then((j) => {
                        // Adopt the preset's own values so the editor and the preview agree.
                        Object.assign(this, j.applied || {});
                        if (!this.logo_file) this.logo_url = '';
                        this.svg = j.svg;
                        this.ok = j.ok;
                        this.ec = j.ec;
                        this.message = j.message || '';
                    })
                    .catch(() => {});
            },

            /* Debounced: a colour-picker drag fires dozens of input events a
               second and every one of them is a full server render. */
            refresh() {
                this.preset = '';
                clearTimeout(this.timer);
                this.timer = setTimeout(() => {
                    const params = new URLSearchParams(Object.assign({ store_id: {{ (int) $rid }} }, this.payload()));
                    fetch(@js(route('qr.designer.preview')) + '?' + params.toString(), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    })
                        .then((r) => r.json())
                        .then((j) => {
                            this.svg = j.svg;
                            this.ok = j.ok;
                            this.ec = j.ec;
                            this.message = j.message || '';
                        })
                        .catch(() => {});
                }, 280);
            },

            save() {
                if (!this.ok || this.saving) return;
                this.saving = true;
                this.$nextTick(() => this.$refs.saveForm.submit());
            },
        });
    }
</script>
@endpush
