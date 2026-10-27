{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Notification preferences'))
@section('page_title', ___('Notification preferences'))
@section('page_sub', ___('Choose which stores, branches and alerts light up your dashboard bell.'))

@section('content')
@php
    // The five bell counters (M1/M3). Branch toggles only affect the orders count
    // (orders is the only branch-scoped event — reviews/disputes/waitlist/webhooks
    // are store-level), so the branch section is labelled accordingly.
    $eventLabels = [
        'orders'   => ___('New orders'),
        'reviews'  => ___('Reviews'),
        'disputes' => ___('Disputes'),
        'waitlist' => ___('Waitlist'),
        'webhooks' => ___('Failed webhooks'),
    ];
@endphp
<div x-data="notifPrefs(@js($initial), @js(route('dashboard.notifPrefs.save')))" class="notif-prefs ui-scope ui-nprefs">

    <x-ui.banner>{{ ___('Everything is on until you turn it off. Changes apply within 30 seconds.') }}</x-ui.banner>

    @if (! count($initial))
        <div class="ui-card">
            <x-ui.empty icon="store" :title="___('You have no stores yet.')" />
        </div>
    @endif

    <template x-for="store in stores" :key="store.id">
        <section class="ui-card ui-nprefs__store">
            <label class="ui-switch-row ui-nprefs__master">
                <span class="ui-switch-row__text"><span class="ui-switch-row__label" x-text="store.title"></span></span>
                <span class="ui-switch">
                    <input type="checkbox" role="switch" class="ui-switch__input" x-model="store.enabled" aria-label="{{ ___('On') }}">
                    <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__knob"></span></span>
                </span>
            </label>
            <div class="ui-nprefs__body" :class="store.enabled ? '' : 'is-off'">
                <p class="ui-overline">{{ ___('Alerts') }}</p>
                <div class="ui-nprefs__grid">
                    @foreach ($eventLabels as $key => $label)
                        <label class="ui-switch-row">
                            <span class="ui-switch-row__text"><span class="ui-switch-row__label">{{ $label }}</span></span>
                            <span class="ui-switch">
                                <input type="checkbox" role="switch" class="ui-switch__input" :disabled="!store.enabled" x-model="store.events.{{ $key }}">
                                <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__knob"></span></span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <template x-if="store.branches.length">
                    <div>
                        <p class="ui-overline">{{ ___('Branches (new orders)') }}</p>
                        <div class="ui-nprefs__grid">
                            <template x-for="b in store.branches" :key="b.id">
                                <label class="ui-switch-row">
                                    <span class="ui-switch-row__text"><span class="ui-switch-row__label" x-text="b.name"></span></span>
                                    <span class="ui-switch">
                                        <input type="checkbox" role="switch" class="ui-switch__input" :disabled="!store.enabled" x-model="b.enabled">
                                        <span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__knob"></span></span>
                                    </span>
                                </label>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </section>
    </template>

    <div class="ui-nprefs__bar">
        <button type="button" class="ui-btn" :disabled="saving" @click="save()">
            <span x-show="!saving">{{ ___('Save preferences') }}</span>
            <span x-show="saving">{{ ___('Saving…') }}</span>
        </button>
        <x-ui.pill tone="success" x-show="saved" x-cloak>{{ ___('Saved') }}</x-ui.pill>
        <span class="ui-nprefs__err" x-show="errMsg" x-text="errMsg" x-cloak></span>
        <x-ui.button :href="route('dashboard.sounds')" variant="ghost" size="sm" icon="bell" class="ui-nprefs__sounds">{{ ___('Sound settings') }}</x-ui.button>
    </div>
</div>
@endsection

@push('scripts_at_bottom')
{{-- No data-turbo-eval="false": this script DEFINES window.notifPrefs, which the
     x-data factory needs. With the attribute, Turbo skips it on link-navigation to
     this page and Alpine throws "notifPrefs is not defined" (blank page). The body
     is a pure idempotent assignment, so re-evaluating on each Turbo visit is safe. --}}
<script nonce="{{ csp_nonce() }}">
    window.notifPrefs = function (stores, saveUrl) {
        return {
            stores: stores,
            saveUrl: saveUrl,
            saving: false, saved: false, errMsg: '',
            _csrf() { return document.querySelector('meta[name=csrf-token]').content; },
            save() {
                this.saving = true; this.saved = false; this.errMsg = '';
                var payload = { stores: {} };
                this.stores.forEach(function (s) {
                    var ev = {};
                    Object.keys(s.events).forEach(function (k) { ev[k] = !!s.events[k]; });
                    var br = null;
                    if (s.branches.length) {
                        br = {};
                        s.branches.forEach(function (b) { br[b.id] = !!b.enabled; });
                    }
                    payload.stores[s.id] = { enabled: !!s.enabled, events: ev, branches: br };
                });
                fetch(this.saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this._csrf() },
                    credentials: 'same-origin',
                    body: JSON.stringify({ prefs: payload }),
                }).then(function (r) { return r.ok ? r.json() : r.json().then(function (d) { return Promise.reject(d); }); })
                  .then(function () {
                      this.saved = true;
                      setTimeout(function () { this.saved = false; }.bind(this), 2500);
                  }.bind(this))
                  .catch(function (d) { this.errMsg = (d && d.message) || 'Error'; }.bind(this))
                  .finally(function () { this.saving = false; }.bind(this));
            },
        };
    };
</script>
@endpush
