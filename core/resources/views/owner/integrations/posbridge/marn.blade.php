{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', 'Marn')
@section('page_title', 'Marn POS')
@section('page_sub', ___('Two-way sync: import your Marn menu and push paid orders into Marn.'))

@section('page_actions')
    <x-ui.button :href="route('integrations.docs', 'marn-pos')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('Setup guide') }}</x-ui.button>
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-pb" data-marn-page>
    @include('owner.integrations.partials.relay-notice')

    <x-ui.card :title="___('Connection')">
        @include('owner.integrations.partials.connection-status', [
            'connected'  => $connected,
            'lastSynced' => optional($connection?->last_push_at)->diffForHumans(),
            'testAction' => null,
            'rid'        => $rid,
            'label'      => 'Marn',
        ])

        @php $secrets = $connected ? (\Juwri\Core\Models\Credential::where('store_id',$rid)->where('feature','marn')->first()?->secrets ?? []) : []; @endphp
        <form method="post" action="{{ route('stores.marn.connect') }}?store_id={{ $rid }}" class="ui-pb__form">
            @csrf
            <div class="ui-pb__fields ui-pb__fields--2">
                <div>
                    <label class="ui-label" for="marn-client-id">{{ ___('Client ID') }}</label>
                    <input id="marn-client-id" name="client_id" class="ui-input ui-ltr" value="{{ $secrets['client_id'] ?? '' }}" required>
                </div>
                <div>
                    <label class="ui-label" for="marn-client-secret">{{ ___('Client Secret') }}</label>
                    <input id="marn-client-secret" name="client_secret" type="password" class="ui-input" autocomplete="off"
                        placeholder="{{ !empty($secrets['client_secret']) ? '•••••••• '.___('leave blank to keep') : '' }}">
                </div>
                <div>
                    <label class="ui-label" for="marn-brand">{{ ___('Brand Key') }}</label>
                    <input id="marn-brand" name="brand_key" class="ui-input ui-ltr" value="{{ $connection->settings['brand_key'] ?? '' }}" required>
                </div>
                <div>
                    <label class="ui-label" for="marn-location">{{ ___('Location Key') }}</label>
                    <input id="marn-location" name="location_key" class="ui-input ui-ltr" value="{{ $connection->external_location_id ?? '' }}" required>
                </div>
            </div>
            <x-ui.switch name="hold_mode" id="marn-hold" :checked="(bool) ($connection->settings['hold_mode'] ?? false)" :label="___('Hold mode (pay at counter)')" />
            <div><x-ui.button type="submit" icon="check" data-marn-connect>{{ ___('Save connection') }}</x-ui.button></div>
        </form>

        @if($connected)
            @if(($connection->settings['menu_drift'] ?? false))
                <x-ui.banner tone="warning" data-menu-drift class="ui-pb__gap">{{ ___('Menu changed in Marn — re-import suggested.') }}</x-ui.banner>
            @endif
            <div class="ui-pb__actions ui-pb__gap">
                <form method="post" action="{{ route('stores.marn.import') }}?store_id={{ $rid }}">
                    @csrf
                    <x-ui.button type="submit" icon="download" data-marn-import data-ui-busy>{{ ___('Import menu from Marn') }}</x-ui.button>
                </form>
                <form method="post" action="{{ route('stores.marn.disconnect') }}?store_id={{ $rid }}" data-confirm="{{ ___('Disconnect Marn?') }}" data-confirm-danger>
                    @csrf
                    <x-ui.button type="submit" variant="danger" size="sm">{{ ___('Disconnect') }}</x-ui.button>
                </form>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card :title="___('Item mapping')" variant="flush">
        @if($maps->isEmpty())
            <x-ui.empty icon="products" :title="___('No items mapped yet — run an import.')" />
        @else
            <table class="ui-table">
                <thead><tr><th>{{ ___('Menu item') }}</th><th>{{ ___('Marn product key') }}</th></tr></thead>
                <tbody>
                @foreach($maps as $m)
                    <tr><td data-primary>{{ $m->external_name }}</td><td data-label="{{ ___('Marn product key') }}"><code>{{ $m->external_id }}</code></td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    <x-ui.card :title="___('Push log')" variant="flush">
        @if($log->isEmpty())
            <x-ui.empty icon="list" :title="___('No pushes yet.')" />
        @else
            <table class="ui-table">
                <thead><tr><th>{{ ___('Order') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Marn OrderNo') }}</th><th>{{ ___('Order status') }}</th></tr></thead>
                <tbody>
                @foreach($log as $row)
                    <tr>
                        <td data-primary><strong class="ui-ltr">#{{ $row->order_id }}</strong></td>
                        <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$row->status === 'pushed' ? 'success' : 'warning'">{{ $row->status }}</x-ui.pill></td>
                        <td data-label="{{ ___('Marn OrderNo') }}"><code>{{ $row->external_id }}</code></td>
                        <td data-label="{{ ___('Order status') }}">{{ $row->provider_meta['marn_state'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</div>
@endsection
