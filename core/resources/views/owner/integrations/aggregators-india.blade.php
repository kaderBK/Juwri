{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@php
    /* PARITY-RUN T7 — Zomato / Swiggy connection page. One template serves both
       channels: the copy differs only in the brand name and the credential
       labels, and forking it would guarantee the two drift.

       Secrets are NEVER rendered. $masked carries a length-only placeholder so
       the owner can see that something is stored; an empty field on submit means
       "keep it", which is why no input is `required` once a value exists. */
    $brand = $provider === 'zomato' ? 'Zomato' : 'Swiggy';
    $keyLabel = $provider === 'zomato' ? ___('API key') : ___('Client ID');
    $secretLabel = $provider === 'zomato' ? ___('API secret') : ___('Client secret');
    $connected = (bool) $connection;
    $paused = $connected && $connection->isPaused();
@endphp

@section('title', $brand)
@section('page_title', $brand)
@section('page_sub', $store->title.' — '.___('Receive marketplace orders, publish your menu and keep stock in sync.'))

@section('page_actions')
    <x-ui.button :href="route('integrations.docs', $provider.'-partner-api')" target="_blank" rel="noopener" variant="secondary" icon="book-open">{{ ___('Setup guide') }}</x-ui.button>
    <x-ui.button :href="route('stores.aggregators.index')" variant="secondary" icon="delivery">{{ ___('Delivery channels') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-india">

    @unless($isLive)
        <x-ui.banner tone="accent">
            {{ ___('This channel is running against the sandbox. Orders, menu pushes and stock updates are simulated until your partner credentials are approved.') }}
        </x-ui.banner>
    @endunless

    {{-- ---------------------------------------------------------------- status --}}
    <div class="ui-india__stats">
            <x-ui.stat :label="___('Status')" :icon="$connected && ! $paused ? 'check' : 'pause'" :tone="$connected ? ($paused ? 'warning' : 'success') : null"
                           {{-- NOT ___('Not connected'): that slug already exists app-wide with
                                the lowercase value 'not connected', and ___() slugs
                                case-insensitively, so reusing it would render mid-sentence
                                casing in a stat tile. --}}
                           :value="$connected ? ($paused ? ___('Paused') : ___('Active')) : ___('Channel not connected')" />
            <x-ui.stat :label="___('Orders')" :value="number_format($stats['orders'])" icon="orders" />
            <x-ui.stat :label="___('Commission')" :value="number_format($stats['fees'], 2)" icon="wallet" />
            <x-ui.stat :label="___('Last menu push')" icon="refresh"
                       :value="$connection?->last_synced_at?->diffForHumans() ?? ___('Never')" />
    </div>

    @if($connected && $connection->sync_error)
        <x-ui.banner tone="warning">
            <strong>{{ ___('Menu push failed.') }}</strong> {{ $connection->sync_error }}
        </x-ui.banner>
    @endif

    {{-- ----------------------------------------------------------- connection --}}
    <x-ui.card :title="___('Connection')">
        @if($connected)
            <x-slot:actions>
                <x-ui.pill :tone="$paused ? 'warning' : 'success'">{{ $paused ? ___('Paused') : ___('Active') }}</x-ui.pill>
            </x-slot:actions>
        @endif

        <form action="{{ route('stores.'.$provider.'.connect', $store->id) }}" method="post">
            @csrf
            <div class="ui-india__form">
                <div class="ui-form-grid">
                    <div class="ui-form-grid__full">
                        <label class="ui-label" for="external_store_id">{{ ___('Outlet id') }}</label>
                        <input class="ui-input" id="external_store_id" name="external_store_id" required
                               value="{{ old('external_store_id', $connection->external_store_id ?? '') }}">
                        <div class="ui-field__help">{{ ___('The outlet identifier shown in your partner dashboard.') }}</div>
                    </div>
                    <div class="ui-form-grid__full">
                        <label class="ui-label" for="prep_minutes">{{ ___('Preparation time (minutes)') }}</label>
                        <input class="ui-input" id="prep_minutes" name="prep_minutes" type="number" min="1" max="120"
                               value="{{ old('prep_minutes', $connection->settings['prep_minutes'] ?? 20) }}">
                        <div class="ui-field__help">{{ ___('Sent with every order acknowledgement.') }}</div>
                    </div>
                </div>

                <div class="ui-form-grid">
                    <div class="ui-form-grid__full">
                        <label class="ui-label" for="webhook_secret">{{ ___('Webhook secret') }}</label>
                        <input class="ui-input" id="webhook_secret" name="webhook_secret" type="password"
                               autocomplete="new-password"
                               placeholder="{{ $masked['webhook_secret'] ?? ___('Not set') }}">
                    </div>
                    <div class="ui-form-grid__full">
                        <label class="ui-label" for="api_key">{{ $keyLabel }}</label>
                        <input class="ui-input" id="api_key" name="api_key" type="password"
                               autocomplete="new-password"
                               placeholder="{{ $masked['api_key'] ?? ___('Not set') }}">
                    </div>
                    <div class="ui-form-grid__full">
                        <label class="ui-label" for="api_secret">{{ $secretLabel }}</label>
                        <input class="ui-input" id="api_secret" name="api_secret" type="password"
                               autocomplete="new-password"
                               placeholder="{{ $masked['api_secret'] ?? ___('Not set') }}">
                    </div>
                    <div class="ui-form-grid__full">
                        <div class="ui-field__help">{{ ___('Credentials are stored encrypted and are never shown again. Leave a field empty to keep the stored value.') }}</div>
                    </div>
                </div>

                <div class="ui-check">
                    <input type="checkbox" role="switch" id="auto_accept" name="auto_accept" value="1"
                           @checked(old('auto_accept', $connection->auto_accept ?? true))>
                    <label for="auto_accept">{{ ___('Accept incoming orders automatically') }}</label>
                </div>
            </div>

            <div class="ui-india__save">
                <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- -------------------------------------------------------- webhook url --}}
    <x-ui.card :title="___('Webhook URL')">
        <div class="ui-india__webhook">
            <p class="ui-india__hint">{{ ___('Give this URL to your partner manager. Every order is signed with your webhook secret and rejected if the signature does not match.') }}</p>
            <div class="ui-india__copy">
                <input class="ui-input ui-ltr" id="india-webhook-url" value="{{ $webhookUrl }}" readonly
                       aria-label="{{ ___('Webhook URL') }}">
                <button class="ui-btn ui-btn--secondary" type="button"
                        data-ui-do="copy" data-ui-copy-target="india-webhook-url">
                    {{ ___('Copy') }}
                </button>
            </div>
            <p class="ui-india__hint">
                {{ ___('Signature header') }}:
                <code class="ui-ltr">{{ $provider === 'zomato' ? 'X-Zomato-Signature' : 'X-Swiggy-Signature' }}</code>
                — HMAC-SHA256
            </p>
        </div>
    </x-ui.card>

    {{-- ------------------------------------------------------------- actions --}}
    <x-ui.card :title="___('Channel actions')">
        @if(! $connected)
            <x-ui.empty :title="___('Not connected yet')" :body="___('Save your outlet id and secrets above to start receiving orders from this marketplace.')" icon="delivery" />
        @else
            <div class="ui-india__actions">
                <form action="{{ route('stores.'.$provider.'.test', $store->id) }}" method="post">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="refresh">{{ ___('Test connection') }}</x-ui.button>
                </form>

                <form action="{{ route('stores.'.$provider.'.menuPush', $store->id) }}" method="post">
                    @csrf
                    <x-ui.button type="submit" icon="upload">{{ ___('Push menu') }}</x-ui.button>
                </form>

                <form action="{{ route('stores.'.$provider.'.availability', $store->id) }}" method="post">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="refresh">{{ ___('Sync item availability') }}</x-ui.button>
                </form>

                <form action="{{ route('stores.'.$provider.'.outlet', $store->id) }}" method="post">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="store">
                        {{ $outletOnline ? ___('Close outlet on this channel') : ___('Reopen outlet on this channel') }}
                    </x-ui.button>
                </form>

                <form action="{{ route('stores.'.$provider.'.pause', $store->id) }}" method="post">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="pause">
                        {{ $paused ? ___('Resume') : ___('Pause') }}
                    </x-ui.button>
                </form>

                <form action="{{ route('stores.'.$provider.'.disconnect', $store->id) }}" method="post"
                      data-confirm="{{ ___('Disconnect this channel? Stored credentials will be deleted.') }}">
                    @csrf @method('DELETE')
                    <x-ui.button type="submit" variant="danger" icon="trash">{{ ___('Disconnect') }}</x-ui.button>
                </form>
            </div>
            <p class="ui-india__hint ui-india__note">
                {{ ___('Menus are published at your list prices. Scheduled and happy-hour prices apply to your own storefront and register only.') }}
            </p>
        @endif
    </x-ui.card>

    {{-- ------------------------------------------------------------ sync log --}}
    <x-ui.card :title="___('Menu sync log')" variant="flush">
        @if($syncLog->isEmpty())
            <x-ui.empty :title="___('Nothing synced yet')" :body="___('Menu pushes, availability syncs and connection tests are recorded here.')" icon="list" />
        @else
            <div>
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('When') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th>{{ ___('Detail') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($syncLog as $row)
                        <tr>
                            <td data-primary>{{ $row->created_at?->diffForHumans() }}</td>
                            <td data-label="{{ ___('Status') }}">
                                <x-ui.pill :tone="$row->status === 'ok' ? 'success' : 'danger'">{{ $row->status === 'ok' ? ___('Ok') : ___('Failed') }}</x-ui.pill>
                            </td>
                            <td data-label="{{ ___('Detail') }}"><span class="ui-india__detail">{{ $row->message }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

</div>
@endsection
