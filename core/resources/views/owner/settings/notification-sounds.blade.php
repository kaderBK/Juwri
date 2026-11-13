{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Notification sounds'))
@section('page_title', ___('Notification sounds'))
@section('page_sub', ___('Pick a sound per alert, set the volume, and choose which alerts repeat until handled.'))

@section('content')
<div class="ui-scope ui-sounds">
@if(Route::has('dashboard.notifPrefs'))
    <p class="ui-sounds__muted">
        {{ ___('Looking to choose which stores or alerts notify you?') }}
        <a href="{{ route('dashboard.notifPrefs') }}">{{ ___('Notification preferences') }}</a>
    </p>
@endif
<div class="ui-sounds__stack" x-data="soundSettings(@js($configs ?? ['0' => $config]), @js([
        'save' => route('dashboard.sounds.save'),
        'upload' => route('dashboard.sounds.upload'),
        'delete' => route('dashboard.sounds.delete'),
    ]), @js(collect($library)->values()))">

    @if(!empty($branches) && count($branches))
    <x-ui.card :title="___('Applies to')">
        <select class="ui-input ui-sounds__narrow" x-model="branchId" @change="switchLayer()" aria-label="{{ ___('Applies to') }}">
            <option value="0">{{ ___('All branches (store default)') }}</option>
            @foreach ($branches as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
        <p class="ui-sounds__muted ui-sounds__after">{{ ___('A branch inherits the store defaults until you change something here.') }}</p>
    </x-ui.card>
    @endif

    <x-ui.card :title="___('Volume')">
        <label class="ui-switch-row ui-sounds__mute">
            <span class="ui-switch-row__text"><span class="ui-switch-row__label">{{ ___('Mute on this device') }}</span></span>
            <span class="ui-switch">
                <input type="checkbox" role="switch" class="ui-switch__input" x-model="deviceMuted" @change="toggleDeviceMute()">
                <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__knob"></span></span>
            </span>
        </label>
        <div class="ui-sounds__volume">
            <x-ui.icon name="bell" />
            <input type="range" class="ui-range" min="0" max="100" step="5" x-model.number="cfg.volume" aria-label="{{ ___('Volume') }}">
            <strong class="ui-ltr" x-text="cfg.volume + '%'"></strong>
        </div>
        <p class="ui-sounds__muted ui-sounds__after">{{ ___('Volume applies to every device; the mute switch only silences this one.') }}</p>
    </x-ui.card>

    <section class="ui-card ui-card--flush">
        <div class="ui-card__head">
            <h2 class="ui-card__title">{{ ___('Alerts') }}</h2>
        </div>
        <p class="ui-sounds__muted ui-sounds__pad">{{ ___('Repeating alerts ring again until the order is opened.') }}</p>
        <table class="ui-table ui-sounds__table">
            <thead><tr>
                <th>{{ ___('Event') }}</th><th>{{ ___('Sound') }}</th>
<th>{{ ___('Repeat') }}</th><th>{{ ___('Every (sec)') }}</th>
            </tr></thead>
            <tbody>
                @foreach ([
                    'order_new'   => ___('New order'),
                    'order_ready' => ___('Order ready (KDS)'),
                    'review'    => ___('New review'),
                    'dispute'   => ___('New dispute'),
                    'waitlist'  => ___('Waitlist guest'),
                    'webhooks'  => ___('Failed webhook'),
                ] as $key => $label)
                <tr>
                    <td data-primary>{{ $label }}</td>
                    <td data-label="{{ ___('Sound') }}">
                        <span class="ui-sounds__pick">
                        <select class="ui-input ui-input--sm" x-model="cfg.events['{{ $key }}'].sound" aria-label="{{ ___('Sound') }}">
                            <option value="">{{ ___('No sound') }}</option>
                            <template x-for="s in library" :key="s.id">
                                <option :value="s.id" x-text="s.label + (s.custom ? ' ★' : '')"></option>
                            </template>
                        </select>
                        <button type="button" class="ui-icon-btn" :disabled="!cfg.events['{{ $key }}'].sound"
                                @click="preview(cfg.events['{{ $key }}'].sound)" title="{{ ___('Preview') }}" aria-label="{{ ___('Preview') }}">
                            <svg class="ui-flip-rtl" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg>
                        </button>
                        </span>
                    </td>
                    <td data-label="{{ ___('Repeat') }}">
                        <span class="ui-switch">
                            <input type="checkbox" role="switch" class="ui-switch__input" x-model="cfg.events['{{ $key }}'].repeat" aria-label="{{ ___('Repeat') }}">
                            <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__knob"></span></span>
                        </span>
                    </td>
                    <td data-label="{{ ___('Every (sec)') }}">
                        <input type="number" class="ui-input ui-input--sm ui-sounds__num" min="5" max="120" step="5" aria-label="{{ ___('Every (sec)') }}"
                               x-model.number="cfg.events['{{ $key }}'].interval" :disabled="!cfg.events['{{ $key }}'].repeat">
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <x-ui.card :title="___('Quiet hours')">
        <label class="ui-switch-row">
            <span class="ui-switch-row__text"><span class="ui-switch-row__label">{{ ___('Silence all sounds during a window') }}</span></span>
            <span class="ui-switch">
                <input type="checkbox" role="switch" class="ui-switch__input" x-model="cfg.quiet.enabled">
                <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__knob"></span></span>
            </span>
        </label>
        <div class="ui-sounds__quiet" x-show="cfg.quiet.enabled" x-cloak>
            <label>{{ ___('From') }}
                <input type="time" class="ui-input" x-model="cfg.quiet.from"></label>
            <label>{{ ___('To') }}
                <input type="time" class="ui-input" x-model="cfg.quiet.to"></label>
            <span class="ui-sounds__muted">{{ ___('Wraps past midnight. Uses the device clock.') }}</span>
        </div>
    </x-ui.card>

    <x-ui.card :title="___('Custom sounds')">
        <p class="ui-sounds__muted">{{ ___('mp3 / ogg / wav · up to 1 MB') }}</p>
        <div class="ui-sounds__upload">
            <input type="file" class="ui-input" accept="audio/mpeg,audio/ogg,audio/wav" x-ref="upload" @change="upload()" aria-label="{{ ___('Custom sounds') }}">
            <span class="ui-sounds__err" x-show="uploadErr" x-cloak x-text="uploadErr"></span>
        </div>
        <div class="ui-sounds__custom">
            <template x-for="s in library.filter(x => x.custom)" :key="s.id">
                <div class="ui-sounds__custom-row">
                    <button type="button" class="ui-icon-btn" @click="preview(s.id)" aria-label="{{ ___('Preview') }}">
                        <svg class="ui-flip-rtl" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg>
                    </button>
                    <span x-text="s.label"></span>
                    <button type="button" class="ui-icon-btn ui-menu-item__delete" @click="removeSound(s.id)" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                </div>
            </template>
        </div>
        <p class="ui-sounds__muted ui-sounds__after" x-show="!library.some(x => x.custom)" x-cloak>{{ ___('No custom sounds yet.') }}</p>
    </x-ui.card>

    <div class="ui-sounds__bar">
        <button type="button" class="ui-btn" :disabled="saving" @click="save()"
                x-text="saving ? '…' : '{{ ___('Save') }}'"></button>
        <x-ui.pill tone="success" x-show="savedMsg" x-cloak>{{ ___('Saved ✓') }}</x-ui.pill>
        <span class="ui-sounds__err" x-show="errMsg" x-cloak x-text="errMsg"></span>
    </div>
</div>
</div>

@push('scripts_at_bottom')
<script src="{{ asset('assets/js/howler.min.js') }}?v={{ filemtime(public_path('assets/js/howler.min.js')) }}"></script>
<script src="{{ asset('assets/js/notify-sounds.js') }}?v={{ filemtime(public_path('assets/js/notify-sounds.js')) }}"></script>
<script nonce="{{ csp_nonce() }}" data-turbo-eval="false">
    window.soundSettings = function (configs, urls, library) {
        return {
            configs: configs,          // { '0': storeCfg, '<branchId>': branchCfg, … }
            urls: urls,
            library: library || [],
            branchId: '0',
            cfg: configs['0'],
            saving: false, savedMsg: false, errMsg: '',
            uploadErr: '',
            deviceMuted: false,
            init() {
                window.NotifySounds && NotifySounds.init({ volume: this.cfg.volume, events: this.cfg.events, quiet: this.cfg.quiet });
                this.deviceMuted = window.NotifySounds ? NotifySounds.isMuted() : false;
            },
            switchLayer() { this.cfg = this.configs[this.branchId] || this.configs['0']; },
            toggleDeviceMute() { window.NotifySounds && NotifySounds.setMuted(this.deviceMuted); },
            preview(id) { window.NotifySounds && NotifySounds.preview(id, this.cfg.volume); },
            _csrf() { return document.querySelector('meta[name=csrf-token]').content; },
            upload() {
                const f = this.$refs.upload.files[0];
                if (!f) return;
                this.uploadErr = '';
                const fd = new FormData(); fd.append('sound', f);
                fetch(this.urls.upload, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this._csrf() }, credentials: 'same-origin', body: fd })
                    .then(r => r.ok ? r.json() : r.json().then(d => Promise.reject(d)))
                    .then(d => { this.library = d.library; this.$refs.upload.value = ''; })
                    .catch(d => { this.uploadErr = (d && d.errors && Object.values(d.errors)[0][0]) || (d && d.message) || 'Upload failed'; });
            },
            removeSound(id) {
                fetch(this.urls.delete, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this._csrf() }, credentials: 'same-origin', body: JSON.stringify({ id: id }) })
                    .then(r => r.json()).then(d => { if (d.library) this.library = d.library; });
            },
            save() {
                this.saving = true; this.savedMsg = false; this.errMsg = '';
                const payload = Object.assign({ branch_id: this.branchId === '0' ? null : +this.branchId }, this.cfg);
                fetch(this.urls.save, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                               'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                }).then(r => r.ok ? r.json() : r.json().then(d => Promise.reject(d)))
                  .then(d => { if (d.config) { this.cfg = d.config; this.configs[this.branchId] = d.config; }
                              this.savedMsg = true; setTimeout(() => this.savedMsg = false, 2500); })
                  .catch(d => { this.errMsg = (d && d.message) || 'Error'; })
                  .finally(() => { this.saving = false; });
            },
        };
    };
</script>
@endpush
@endsection
