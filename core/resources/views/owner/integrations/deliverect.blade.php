{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Deliverect'))
@section('page_title', ___('Deliverect'))
@section('page_sub', ___('Manage your delivery channels, menu sync and stock.'))

@section('content')
<div class="ui-stack" data-testid="deliverect-panel">
    @include('owner.integrations.partials.relay-notice')

    @if(session('success'))
        <div class="ui-note" data-tone="success">{{ session('success') }}</div>
    @endif

    {{-- Location links --------------------------------------------------- --}}
    <div class="ui-card">
        <div class="ui-card__body">
            <h3 class="ui-section-title">{{ ___('Connected locations') }}</h3>
            @if($connections->isEmpty())
                <p class="ui-text-2" data-testid="no-locations">
                    {{ ___('No Deliverect location is linked yet. Add your juwri POS from the Deliverect console to create the link.') }}
                </p>
            @else
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr class="ui-muted ui-caps">
                                <th>{{ ___('Channel link') }}</th>
                                <th>{{ ___('Status') }}</th>
                                <th>{{ ___('Last menu sync') }}</th>
                                <th class="ui-end">{{ ___('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($connections as $conn)
                                <tr data-location="{{ $conn->id }}">
                                    <td class="ui-mono ui-small" data-label="{{ ___('Channel link') }}">{{ $conn->external_store_id ?: '—' }}</td>
                                    <td data-label="{{ ___('Status') }}">
                                        @php $st = $conn->status === 'active' ? 'success' : ($conn->status === 'paused' ? 'warning' : 'secondary'); @endphp
                                        <span class="ui-tag" data-tone="{{ $st === 'secondary' ? 'neutral' : $st }}" data-status="{{ $conn->status }}">{{ ___(ucfirst($conn->status ?: 'unknown')) }}</span>
                                    </td>
                                    <td class="ui-muted" data-label="{{ ___('Last menu sync') }}">
                                        {{ $conn->last_synced_at ? $conn->last_synced_at->diffForHumans() : ___('Never') }}
                                        @if($conn->menu_sync_state)
                                            <span class="ui-tag ui-bg-inset" data-tone="neutral">{{ $conn->menu_sync_state }}</span>
                                        @endif
                                    </td>
                                    <td class="ui-end" data-label="{{ ___('Actions') }}">
                                        <form method="POST" action="{{ route('integrations.deliverect.sync', $conn->id) }}" class="ui-inline">
                                            @csrf
                                            <button type="submit" class="ui-btn ui-btn--sm ui-btn--secondary">{{ ___('Sync now') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Busy mode -------------------------------------------------------- --}}
    <div class="ui-card">
        <div class="ui-cluster ui-cluster--between ui-card__body">
            <div>
                <h3 class="ui-section-title">{{ ___('Busy mode') }}</h3>
                <p class="ui-muted">{{ ___('Pause all channels temporarily when the kitchen is slammed.') }}</p>
            </div>
            <form method="POST" action="{{ route('integrations.deliverect.busy') }}" data-testid="busy-form">
                @csrf
                <input type="hidden" name="store_id" value="{{ $rid }}">
                <input type="hidden" name="busy" value="{{ $busy ? 0 : 1 }}">
                <button type="submit" class="ui-btn ui-btn--sm {{ $busy ? 'ui-btn--dark' : 'ui-btn--secondary' }}" data-busy="{{ $busy ? 1 : 0 }}">
                    {{ $busy ? ___('Busy — resume channels') : ___('Set busy') }}
                </button>
            </form>
        </div>
    </div>

    {{-- Snoozed items --------------------------------------------------- --}}
    @if($snoozed->isNotEmpty())
    <div class="ui-card">
        <div class="ui-card__body">
            <h3 class="ui-section-title">{{ ___('Snoozed (86’d) items') }}</h3>
            <div class="ui-stack" data-testid="snoozed-list">
                @foreach($snoozed as $item)
                    <div class="ui-cluster ui-cluster--between ui-bordered" data-snoozed="{{ $item->id }}">
                        <span>{{ $item->name }}</span>
                        <form method="POST" action="{{ route('integrations.deliverect.unsnooze') }}">
                            @csrf
                            <input type="hidden" name="store_id" value="{{ $rid }}">
                            <input type="hidden" name="menu_id" value="{{ $item->id }}">
                            <button type="submit" class="ui-btn ui-btn--sm ui-btn--success">{{ ___('Un-snooze') }}</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- PLU health ------------------------------------------------------ --}}
    <div class="ui-card">
        <div class="ui-card__body">
            <div class="ui-cluster ui-cluster--between">
                <h3 class="ui-section-title">{{ ___('PLU health') }}</h3>
                <span class="ui-tag" data-tone="{{ $missing->isEmpty() ? 'success' : 'warning' }}" data-testid="plu-health">
                    {{ $missing->count() }} / {{ $totalItems }} {{ ___('unmapped') }}
                </span>
            </div>
            @if($missing->isEmpty())
                <p class="ui-muted">{{ ___('Every menu item has a partner SKU. Deliverect can match orders exactly.') }}</p>
            @else
                <p class="ui-muted">{{ ___('These items have no partner SKU. Deliverect falls back to the item id — map a SKU to match the channel’s codes exactly.') }}</p>
                <div class="ui-stack">
                    @foreach($missing as $item)
                        <form method="POST" action="{{ route('integrations.deliverect.sku') }}" class="ui-form-grid" data-unmapped="{{ $item->id }}">
                            @csrf
                            <input type="hidden" name="menu_id" value="{{ $item->id }}">
                            <div class="ui-form-grid__full"><span>{{ $item->name }}</span></div>
                            <div>
                                <input type="text" name="external_sku" class="ui-input ui-input--sm" placeholder="{{ ___('Partner SKU') }}" maxlength="191">
                            </div>
                            <div class="ui-end">
                                <button type="submit" class="ui-btn ui-btn--sm ui-btn--secondary ui-w-full">{{ ___('Save') }}</button>
                            </div>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div>
        <a href="{{ route('integrations.index') }}" class="ui-btn ui-btn--sm ui-btn--link">&larr; {{ ___('Back to Integrations') }}</a>
    </div>
</div>
@endsection
