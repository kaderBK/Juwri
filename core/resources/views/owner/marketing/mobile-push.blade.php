{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- PARITY-RUN T9 — Settings → Mobile app & push.
     Firebase BYOK (write-only), per-event toggles, test push, device list.
     Deliberately Alpine-free: this page is plain forms, so the single-quoted
     @click/@js trap that has bitten this repo cannot apply. Owner UI kit look. --}}
@extends('owner.layout')

@section('title', ___('Mobile App & Push'))
@section('page_title', ___('Mobile App & Push'))
@section('page_sub', ___('Send order alerts to the phones your team already carries.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
@php
    $eventLabels = [
        'new_order'    => ___('New order'),
        'order_status' => ___('Order status change'),
        'reservation'  => ___('New reservation'),
        'waitlist_ready' => ___('Table ready'),
    ];
@endphp

<div class="ui-scope ui-push">
    @if(session('success'))
        <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
    @endif

    @include('owner.pwa.install-card', ['persistent' => true])
    @if(isset($errors) && $errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <div class="ui-push__grid">
        <div class="ui-push__main">

            {{-- ── Firebase (BYOK) ────────────────────────────────────────────── --}}
            <x-ui.card :title="___('Firebase project')">
                <x-slot:actions>
                    @if($credentials['configured'])
                        <x-ui.pill tone="success">{{ ___('Firebase connected') }}</x-ui.pill>
                    @else
                        <x-ui.pill>{{ ___('Firebase not set up') }}</x-ui.pill>
                    @endif
                </x-slot:actions>
                <p class="ui-push__text">
                    {{ ___('Notifications are delivered through Firebase Cloud Messaging. Paste the service-account file from your own Firebase project — it is stored encrypted and never shown again.') }}
                    {{ ___("This file only powers your staff and driver apps. The juwri owner app uses the platform's own notification setup, so you don't need to add anything for it.") }}
                </p>

                @if($credentials['configured'])
                    <div class="ui-push__facts">
                        @if($credentials['project_id'])
                            <span><strong>{{ ___('Project') }}:</strong> <span class="ui-ltr">{{ $credentials['project_id'] }}</span></span>
                        @endif
                        @unless($credentials['own_credentials'])
                            <span>{{ ___('Using the platform default credentials.') }}</span>
                        @endunless
                    </div>
                @endif

                <form method="post" action="{{ route('stores.mobilepush.credentials') }}?store_id={{ $rid }}" class="ui-push__form">
                    @csrf
                    <label class="ui-label" for="mp-sa">{{ ___('Service account file') }}</label>
                    <textarea id="mp-sa" name="service_account" class="ui-input ui-mono" rows="5"
                              maxlength="8192" placeholder='{"type":"service_account","project_id":"…"}'></textarea>
                    <small class="ui-push__hint">{{ ___('Firebase console → Project settings → Service accounts → Generate new private key.') }}</small>
                    <div class="ui-push__buttons">
                        <x-ui.button type="submit">{{ ___('Save credentials') }}</x-ui.button>
                    </div>
                </form>

                @if($credentials['own_credentials'])
                    <form method="post" action="{{ route('stores.mobilepush.credentials.clear') }}?store_id={{ $rid }}" class="ui-push__form">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Remove credentials') }}</x-ui.button>
                    </form>
                @endif
            </x-ui.card>

            {{-- ── Events ─────────────────────────────────────────────────────── --}}
            <x-ui.card :title="___('Notification events')">
                <p class="ui-push__text">{{ ___('Choose what the app notifies about. Turning one off silences the app only — the staff web notifications are unaffected.') }}</p>

                <form method="post" action="{{ route('stores.mobilepush.settings') }}?store_id={{ $rid }}" class="ui-push__form">
                    @csrf
                    <div class="ui-push__switches">
                        @foreach($events as $ev)
                            <x-ui.switch name="events[]" :value="$ev" :id="'mp-ev-'.$ev" :checked="$toggles[$ev] ?? true" :label="$eventLabels[$ev] ?? $ev" />
                        @endforeach
                    </div>
                    <div class="ui-push__buttons">
                        <x-ui.button type="submit">{{ ___('Save') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            {{-- ── Devices ────────────────────────────────────────────────────── --}}
            <x-ui.card :title="___('Registered devices')" variant="flush">
                <x-slot:actions>
                    <form method="post" action="{{ route('stores.mobilepush.test') }}?store_id={{ $rid }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" size="sm" icon="bell" :disabled="$devices->isEmpty()">{{ ___('Send a test notification') }}</x-ui.button>
                    </form>
                </x-slot:actions>

                @if($devices->isEmpty())
                    <x-ui.empty :title="___('No app devices yet.')" :body="___('Install the app and sign in — the device registers itself and appears here.')" icon="phone" />
                @else
                    <table class="ui-table">
                        <thead><tr>
                            <th>{{ ___('Device') }}</th>
                            <th>{{ ___('App version') }}</th>
                            <th>{{ ___('Last seen') }}</th>
                            <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                        </tr></thead>
                        <tbody>
                        @foreach($devices as $d)
                            <tr>
                                <td data-primary>
                                    <span class="ui-push__device">
                                        {{ $d->displayName() }}
                                        @if($d->failed_at)
                                            <x-ui.pill tone="warning">{{ ___('Unreachable') }}</x-ui.pill>
                                        @endif
                                        @if($d->os_version)
                                            <small>{{ $d->os_version }}</small>
                                        @endif
                                    </span>
                                </td>
                                <td data-label="{{ ___('App version') }}"><span class="ui-ltr">{{ $d->app_version ?: '—' }}</span></td>
                                <td data-label="{{ ___('Last seen') }}"><span class="ui-ltr">{{ $d->last_seen_at ? $d->last_seen_at->format('Y-m-d H:i') : '—' }}</span></td>
                                <td class="ui-catalog__row-actions">
                                    <form method="post" action="{{ route('stores.mobilepush.devices.revoke', $d->id) }}?store_id={{ $rid }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger" size="sm">{{ ___('Revoke') }}</x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </x-ui.card>
        </div>

        {{-- ── Sidebar: how to get the app ─────────────────────────────────────── --}}
        <aside class="ui-push__side">
            <x-ui.card :title="___('Getting the app')" variant="inset">
                <ol class="ui-push__steps">
                    <li>{{ ___('Create a Firebase project and add an Android app and an iOS app to it.') }}</li>
                    <li>{{ ___('Upload your Apple push key (.p8) to Firebase so iOS notifications can be delivered.') }}</li>
                    <li>{{ ___('Paste the service-account file above.') }}</li>
                    <li>{{ ___('Install the app, sign in, and allow notifications when asked.') }}</li>
                </ol>
            </x-ui.card>

            @unless($webPushReady)
                <x-ui.banner tone="warning">
                    <strong>{{ ___('Browser notifications') }}</strong><br>
                    {{ ___('Web notifications for the staff app need their own keys and are configured by the platform administrator.') }}
                </x-ui.banner>
            @endunless
        </aside>
    </div>
</div>
@endsection
