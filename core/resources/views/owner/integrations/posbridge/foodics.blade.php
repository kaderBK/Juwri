{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', 'Foodics')
@section('page_title', 'Foodics POS')
@section('page_sub', ___('Two-way sync: import your Foodics menu and push paid orders into Foodics.'))

@section('page_actions')
    <x-ui.button :href="route('integrations.docs', 'foodics-verification')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('Setup guide') }}</x-ui.button>
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-pb" data-foodics-page>
    @include('owner.integrations.partials.relay-notice')

    <x-ui.card :title="___('Connection')">
        @include('owner.integrations.partials.connection-status', [
            'connected'  => $connected,
            'lastSynced' => optional($connection?->last_push_at)->diffForHumans(),
            'testAction' => null,
            'rid'        => $rid,
            'label'      => 'Foodics',
        ])

        @if($connected)
            @if(($connection->settings['menu_drift'] ?? false))
                <x-ui.banner tone="warning" data-menu-drift>{{ ___('Menu changed in Foodics — re-import suggested.') }}</x-ui.banner>
            @endif
            <div class="ui-pb__actions">
                <form method="post" action="{{ route('stores.foodics.import') }}?store_id={{ $rid }}">
                    @csrf
                    <x-ui.button type="submit" icon="download" data-foodics-import data-ui-busy>{{ ___('Import menu from Foodics') }}</x-ui.button>
                </form>
                <form method="post" action="{{ route('stores.foodics.disconnect') }}?store_id={{ $rid }}" data-confirm="{{ ___('Disconnect Foodics?') }}" data-confirm-danger>
                    @csrf
                    <x-ui.button type="submit" variant="danger" size="sm">{{ ___('Disconnect') }}</x-ui.button>
                </form>
            </div>
        @else
            <form method="post" action="{{ route('stores.foodics.connect') }}?store_id={{ $rid }}" class="ui-pb__form">
                @csrf
                <x-ui.switch name="sandbox" id="foodics-sandbox" :label="___('Use sandbox')" />
                <div><x-ui.button type="submit" icon="share" data-foodics-connect>{{ ___('Connect Foodics') }}</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="___('Item mapping')" variant="flush">
        @if($maps->isEmpty())
            <x-ui.empty icon="products" :title="___('No items mapped yet — run an import.')" />
        @else
            <table class="ui-table">
                <thead><tr><th>{{ ___('Menu item') }}</th><th>{{ ___('Foodics product id') }}</th></tr></thead>
                <tbody>
                @foreach($maps as $m)
                    <tr><td data-primary>{{ $m->external_name }}</td><td data-label="{{ ___('Foodics product id') }}"><code>{{ $m->external_id }}</code></td></tr>
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
                <thead><tr><th>{{ ___('Order') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Foodics id') }}</th><th class="ui-table__num">{{ ___('Their total') }}</th></tr></thead>
                <tbody>
                @foreach($log as $row)
                    <tr>
                        <td data-primary><strong class="ui-ltr">#{{ $row->order_id }}</strong></td>
                        <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$row->status === 'pushed' ? 'success' : 'warning'">{{ $row->status }}</x-ui.pill></td>
                        <td data-label="{{ ___('Foodics id') }}"><code>{{ $row->external_id }}</code></td>
                        <td class="ui-table__num" data-label="{{ ___('Their total') }}"><span class="ui-ltr">{{ $row->provider_meta['foodics_totals']['total'] ?? '—' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</div>
@endsection
