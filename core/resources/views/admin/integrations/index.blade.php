{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Integrations'))
@section('content')

<div class="ui-stack" data-testid="admin-integrations">

    {{-- Kill switches -------------------------------------------------- --}}
    <div class="ui-columns">
        @foreach ([
            'integrations' => ___('Integrations hub'),
            'aggregator_deliverect' => ___('Deliverect'),
        ] as $key => $label)
            <x-ui.card data-killswitch="{{ $key }}">
                <div class="ui-cluster ui-cluster--between">
                    <div class="ui-stack">
                        <span class="ui-small ui-text-2">{{ $label }}</span>
                        <span>
                            @if ($killSwitches[$key])
                                <x-ui.tag tone="success" data-state="on">{{ ___('Enabled') }}</x-ui.tag>
                            @else
                                <x-ui.tag data-state="off">{{ ___('Off (dark)') }}</x-ui.tag>
                            @endif
                        </span>
                    </div>
                    <x-ui.button variant="secondary" size="sm" icon="settings" :href="route('admin.settings.index').'#quick_feature_controls'">{{ ___('Flip in Feature Controls') }}</x-ui.button>
                </div>
            </x-ui.card>
        @endforeach
    </div>

    {{-- Webhook failure leaderboard ------------------------------------ --}}
    <x-ui.card variant="flush" :title="___('Webhook failures by tenant (last 7 days)')">
        <x-slot:actions>
            <x-ui.button variant="link" size="sm" :href="route('admin.webhooks.index')">{{ ___('All webhooks') }} &rarr;</x-ui.button>
        </x-slot:actions>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Tenant') }}</th><th class="ui-end">{{ ___('Failures') }}</th><th>{{ ___('Last failure') }}</th></tr></thead>
                <tbody>
                    @forelse ($leaderboard as $row)
                        <tr data-tenant="{{ $row['user_id'] }}">
                            <td data-primary data-label="{{ ___('Tenant') }}">{{ $row['name'] }}</td>
                            <td class="ui-end" data-label="{{ ___('Failures') }}"><x-ui.tag tone="danger">{{ number_format($row['failures']) }}</x-ui.tag></td>
                            <td data-label="{{ ___('Last failure') }}"><span class="ui-small ui-text-2">{{ $row['last_failure'] ? \Illuminate\Support\Carbon::parse($row['last_failure'])->diffForHumans() : '—' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="ui-center ui-text-2" data-testid="no-failures">{{ ___('No webhook failures in the last 7 days.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- Deliverect connections ----------------------------------------- --}}
    <x-ui.card variant="flush" :title="___('Deliverect locations')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Tenant') }}</th><th>{{ ___('Channel link') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Last sync') }}</th></tr></thead>
                <tbody>
                    @forelse ($connections as $conn)
                        <tr data-connection="{{ $conn->id }}">
                            <td data-primary data-label="{{ ___('Tenant') }}">{{ $conn->store->title ?? ('#'.$conn->store_id) }}</td>
                            <td data-label="{{ ___('Channel link') }}"><span class="ui-mono ui-small">{{ $conn->external_store_id ?: '—' }}</span></td>
                            <td data-label="{{ ___('Status') }}">
                                @php $st = $conn->status === 'active' ? 'success' : ($conn->status === 'paused' ? 'warning' : 'neutral'); @endphp
                                <x-ui.tag :tone="$st">{{ ___(ucfirst($conn->status ?: 'unknown')) }}</x-ui.tag>
                            </td>
                            <td data-label="{{ ___('Last sync') }}"><span class="ui-small ui-text-2">{{ $conn->last_synced_at ? $conn->last_synced_at->diffForHumans() : ___('Never') }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-center ui-text-2" data-testid="no-connections">{{ ___('No Deliverect locations connected yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

</div>
@endsection
