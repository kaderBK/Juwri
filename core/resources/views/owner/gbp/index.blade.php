{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Google ordering link'))
@section('page_title', ___('Google Business Profile'))
@section('page_sub', ___('Connect your profile, link a location, sync your menu, and set your "Order online" button.'))

@section('page_actions')
    <x-ui.button :href="route('integrations.docs', 'google-business-ordering')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('Setup guide') }}</x-ui.button>
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-gbp">

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif
@if(isset($errors) && $errors->any())
    <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
@endif

{{-- Connection state machine: disconnected → connected-no-location → linked --}}
@php($tone = ['disconnected' => 'neutral', 'connected-no-location' => 'warning', 'linked' => 'success'][$state] ?? 'neutral')
@php($label = ['disconnected' => ___('not connected'), 'connected-no-location' => ___('choose a location'), 'linked' => ___('linked')][$state] ?? ___('not connected'))
<x-ui.card :title="___('Connection')">
    <x-slot:actions><x-ui.pill :tone="$tone">{{ $label }}</x-ui.pill></x-slot:actions>

    @if($state === 'disconnected')
        <p class="ui-gbp__text">{{ ___('Connect your Google account to manage your Business Profile from here.') }}</p>
        <x-ui.button :href="route('stores.gbp.oauth.connect').'?store_id='.$rid" icon="external">{{ ___('Connect Google') }}</x-ui.button>
    @elseif($state === 'connected-no-location')
        <p class="ui-gbp__text">{{ ___('Your Google account is connected. Choose which location to link.') }}</p>
        <form method="post" action="{{ route('stores.gbp.location') }}?store_id={{ $rid }}" class="ui-gbp__inline">
            @csrf
            <div class="ui-gbp__grow">
                <label class="ui-label" for="gbp-location">{{ ___('Location') }}</label>
                @if(count($pickerOptions))
                    {{-- Location resource names embed the account (accounts/*/locations/*);
                         the controller derives account_id server-side — no inline JS (CSP). --}}
                    <select id="gbp-location" name="location_id" class="ui-input" required>
                        <option value="">{{ ___('Select a location…') }}</option>
                        @foreach($pickerOptions as $opt)
                            <option value="{{ $opt['location'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                @else
                    <input id="gbp-location" name="location_id" class="ui-input" placeholder="{{ ___('Location id') }}" required>
                    <input type="hidden" name="account_id" value="{{ $accountId }}">
                    <small class="ui-gbp__hint">{{ ___('No locations were returned automatically. Paste your location id.') }}</small>
                @endif
            </div>
            <x-ui.button type="submit">{{ ___('Link location') }}</x-ui.button>
        </form>
        <form method="post" action="{{ route('stores.gbp.disconnect') }}?store_id={{ $rid }}" class="ui-gbp__after">
            @csrf<x-ui.button type="submit" variant="ghost" size="sm" class="ui-gbp__danger">{{ ___('Disconnect') }}</x-ui.button>
        </form>
    @else
        <p class="ui-gbp__text">{{ ___('Linked location') }}: <code>{{ $locationId }}</code></p>
        <form method="post" action="{{ route('stores.gbp.disconnect') }}?store_id={{ $rid }}" class="ui-gbp__after">
            @csrf<x-ui.button type="submit" variant="ghost" size="sm" class="ui-gbp__danger">{{ ___('Disconnect') }}</x-ui.button>
        </form>
    @endif
</x-ui.card>

{{-- Ordering link --}}
<x-ui.card :title="___('Ordering link')">
    <dl class="ui-gbp__facts">
        <div><dt>{{ ___('Your storefront URL') }}</dt><dd><code>{{ $orderingUrl }}</code></dd></div>
        @if($currentLink)
            <div class="is-ok"><dt>{{ ___('Current Google link') }}</dt><dd><code>{{ $currentLink }}</code></dd></div>
        @endif
    </dl>
    <form method="post" action="{{ route('stores.gbp.setLink') }}?store_id={{ $rid }}" class="ui-gbp__form">
        @csrf
        {{-- biolink-tpl lane-d (T2-4b): point the Google ordering link at the
             storefront or the bio page. Shown only when the bio page is live. --}}
        @if(!empty($bioOrderingUrl))
            <fieldset class="ui-gbp__choices">
                <legend class="ui-label">{{ ___('Where should the ordering link go?') }}</legend>
                <label class="ui-gbp__choice" for="orderingTargetStore">
                    <input type="radio" name="ordering_target" id="orderingTargetStore" value="storefront" @checked(($orderingTarget ?? 'storefront') !== 'bio')>
                    <span><strong>{{ ___('Storefront') }}</strong> <code>{{ $orderingUrl }}</code></span>
                </label>
                <label class="ui-gbp__choice" for="orderingTargetBio">
                    <input type="radio" name="ordering_target" id="orderingTargetBio" value="bio" @checked(($orderingTarget ?? 'storefront') === 'bio')>
                    <span><strong>{{ ___('Bio page') }}</strong> <code>{{ $bioOrderingUrl }}</code></span>
                </label>
            </fieldset>
        @endif
        <div>
            <x-ui.button type="submit" variant="success" :disabled="! $connected">{{ ___('Set my ordering link on Google') }}</x-ui.button>
            @unless($connected)<small class="ui-gbp__hint">{{ ___('Link a location first.') }}</small>@endunless
        </div>
    </form>
</x-ui.card>

{{-- Menu sync (A5) — only meaningful once a location is linked --}}
<x-ui.card :title="___('Menu sync')">
    <p class="ui-gbp__text">{{ ___('Keep your Google food menu in step with the menu you manage here.') }}</p>
    <form method="post" action="{{ route('stores.gbp.menuSync') }}?store_id={{ $rid }}" class="ui-gbp__sync">
        @csrf
        <input type="hidden" name="gbp_menu_sync_enabled" value="0">
        <x-ui.switch name="gbp_menu_sync_enabled" id="gbpMenuSync" :checked="(bool) $menuSyncEnabled" :disabled="$state !== 'linked'" :label="___('Nightly menu sync')" />
        <x-ui.button type="submit" variant="secondary" size="sm" :disabled="$state !== 'linked'">{{ ___('Save') }}</x-ui.button>
    </form>
    @if($lastSyncedAt)
        <small class="ui-gbp__hint">{{ ___('Last synced') }}: <span class="ui-ltr">{{ $lastSyncedAt }}</span></small>
    @endif
    @if($syncError)
        <small class="ui-gbp__hint ui-gbp__hint--danger">{{ ___('Last sync error') }}: {{ $syncError }}</small>
    @endif
    <form method="post" action="{{ route('stores.gbp.syncNow') }}?store_id={{ $rid }}" class="ui-gbp__after">
        @csrf<x-ui.button type="submit" size="sm" icon="refresh" :disabled="$state !== 'linked'">{{ ___('Sync now') }}</x-ui.button>
    </form>
</x-ui.card>

{{-- Manual fallback (collapsed; native <details>, no Bootstrap JS) --}}
<details class="ui-card ui-gbp__manual">
    <summary class="ui-gbp__summary">
        <span>{{ ___('Set it manually (no API access needed)') }}</span>
        <x-ui.icon name="chevron-down" size="18" class="ui-gbp__caret" />
    </summary>
    <div class="ui-gbp__manual-body" id="gbpManual">
        <ol class="ui-push__steps">
            <li>{{ ___('Open your Google Business Profile → Edit profile → Ordering.') }}</li>
            <li>{{ ___('Add a link and paste your storefront URL above.') }}</li>
            <li>{{ ___('Mark it as your preferred link so it shows as the highlighted button.') }}</li>
        </ol>
        <form method="post" action="{{ route('stores.gbp.connect') }}?store_id={{ $rid }}" class="ui-gbp__form">
            @csrf
            <div class="ui-gbp__fields">
                <div><label class="ui-label" for="gbp-m-location">{{ ___('Location id') }}</label><input id="gbp-m-location" name="location_id" class="ui-input" value="{{ $locationId }}" required></div>
                <div><label class="ui-label" for="gbp-m-access">{{ ___('OAuth access token') }}</label><input id="gbp-m-access" name="access_token" class="ui-input" autocomplete="off" required></div>
                <div><label class="ui-label" for="gbp-m-refresh">{{ ___('Refresh token') }}</label><input id="gbp-m-refresh" name="refresh_token" class="ui-input" autocomplete="off"></div>
            </div>
            <div><x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Save manual connection') }}</x-ui.button></div>
        </form>
        <small class="ui-gbp__hint">{{ ___('One-click needs Google Business API access (verified profile 60+ days + approval). Until then, use the manual steps.') }}</small>
    </div>
</details>

</div>
@endsection
