{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Courier accounts'))
@section('page_title', ___('Courier accounts'))
@section('page_sub', ___('Connect your own Uber Direct / DoorDash Drive accounts to dispatch orders.'))

@section('page_actions')
    <x-ui.button :href="route('integrations.docs', 'courier-dispatch')" variant="secondary" size="sm" icon="book-open" target="_blank" rel="noopener">{{ ___('Setup guide') }}</x-ui.button>
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('dispatch')])

<div class="ui-scope ui-couriers">
    @foreach($providers as $key => $p)
        <x-ui.card :title="$p['label']" class="ui-couriers__card">
            <x-slot:actions>
                {{-- Same status the shared integrations connection-status partial shows
                     (no test action on this page), drawn with the kit pill. --}}
                <span class="integration-connection-status">
                    @if($p['connected'])
                        <x-ui.pill tone="success">{{ ___('Connected') }}</x-ui.pill>
                    @else
                        <x-ui.pill>{{ ___('Not connected yet') }}</x-ui.pill>
                    @endif
                </span>
            </x-slot:actions>

            <form method="post" action="{{ route('stores.dispatch.couriers.save', $key) }}?store_id={{ $rid }}" class="ui-couriers__form">
                @csrf
                <div class="ui-couriers__fields">
                    @foreach($p['ids'] as $field => $f)
                        <div class="ui-couriers__field">
                            <label class="ui-label" for="cr-{{ $key }}-{{ $field }}">{{ $f['label'] }}</label>
                            <input id="cr-{{ $key }}-{{ $field }}" name="{{ $field }}" class="ui-input ui-ltr" value="{{ $f['value'] }}" required>
                        </div>
                    @endforeach
                    @foreach($p['has'] as $field => $f)
                        <div class="ui-couriers__field">
                            <label class="ui-label" for="cr-{{ $key }}-{{ $field }}">{{ $f['label'] }}</label>
                            <input id="cr-{{ $key }}-{{ $field }}" name="{{ $field }}" type="password" class="ui-input" autocomplete="off"
                                placeholder="{{ $f['stored'] ? '•••••••• '.___('leave blank to keep') : '' }}">
                        </div>
                    @endforeach
                </div>
                <div class="ui-couriers__actions">
                    <x-ui.button type="submit" icon="check">{{ ___('Save credentials') }}</x-ui.button>
                </div>
            </form>

            @if($p['connected'])
                <form method="post" action="{{ route('stores.dispatch.couriers.disconnect', $key) }}?store_id={{ $rid }}"
                      class="ui-couriers__disconnect" data-confirm="{{ ___('Disconnect') }} {{ $p['label'] }}?" data-confirm-danger>
                    @csrf
                    <x-ui.button type="submit" variant="danger" size="sm">{{ ___('Disconnect') }}</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    @endforeach

    <x-ui.card variant="inset" class="ui-couriers__hooks">
        <p class="ui-overline">{{ ___('Webhook URL for status updates') }}</p>
        <code class="ui-ltr">{{ url('/webhook/courier/uber_direct') }}</code>
        <code class="ui-ltr">{{ url('/webhook/courier/doordash') }}</code>
    </x-ui.card>
</div>
@endsection
