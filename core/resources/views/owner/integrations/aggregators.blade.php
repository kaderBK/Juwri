{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Delivery channels'))
@section('page_title', ___('Delivery channels'))
@section('page_sub', ___('Connect marketplaces, pause channels, and track commission.'))

@section('page_actions')
    <x-ui.button :href="route('integrations.docs', 'aggregator-hub')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('Setup guide') }}</x-ui.button>
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-channels">
    @include('owner.integrations.partials.relay-notice')
<x-ui.card :title="___('Connect a channel')">
    <form method="post" action="{{ route('stores.aggregators.connect') }}?store_id={{ $rid }}" class="ui-channels__form">
        @csrf
        <div class="ui-channels__fields">
            <div>
                <label class="ui-label" for="agg-provider">{{ ___('Provider') }}</label>
                <select id="agg-provider" name="provider" class="ui-input" required>
                    @foreach($providers as $p)
                        <option value="{{ $p }}">{{ ucwords(str_replace('_', ' ', $p)) }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="ui-label" for="agg-store">{{ ___('Store / channel id') }}</label><input id="agg-store" name="external_store_id" class="ui-input" required></div>
            <div><label class="ui-label" for="agg-hook">{{ ___('Webhook secret') }}</label><input id="agg-hook" name="webhook_secret" class="ui-input" required autocomplete="off"></div>
            <div><label class="ui-label" for="agg-client">{{ ___('Client secret') }}</label><input id="agg-client" name="client_secret" class="ui-input" autocomplete="off"></div>
        </div>
        <div class="ui-channels__foot ui-channels__foot--end">
            <x-ui.button type="submit" icon="plus">{{ ___('Connect') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<section class="ui-card ui-card--flush">
    @if($connections->isEmpty())
        <x-ui.empty icon="delivery" :title="___('No channels connected yet.')" />
    @else
    <table class="ui-table">
        <thead><tr>
            <th>{{ ___('Provider') }}</th><th>{{ ___('Store id') }}</th><th>{{ ___('Status') }}</th>
            <th class="ui-table__num">{{ ___('Orders') }}</th><th class="ui-table__num">{{ ___('Commission') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
        </tr></thead>
        <tbody>
        @foreach($connections as $c)
            @php $stat = $report[$c->provider] ?? null; @endphp
            <tr>
                <td data-primary>
                    <span class="ui-channels__name">
                        {{ ucwords(str_replace('_', ' ', $c->provider)) }}
                        {{-- A6: driver=real but the live client class is missing → fail loud
                             so the merchant does not believe a phantom Fake push worked. --}}
                        @if(($driverStatus[$c->provider] ?? 'fake') === 'unavailable')
                            <x-ui.pill tone="danger" data-driver-unavailable>{{ ___('Driver not available') }}</x-ui.pill>
                        @endif
                    </span>
                </td>
                <td data-label="{{ ___('Store id') }}"><code>{{ $c->external_store_id }}</code></td>
                <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$c->status === 'active' ? 'success' : 'neutral'">{{ ___(ucfirst($c->status)) }}</x-ui.pill></td>
                <td class="ui-table__num" data-label="{{ ___('Orders') }}"><span class="ui-ltr">{{ $stat->orders ?? 0 }}</span></td>
                <td class="ui-table__num" data-label="{{ ___('Commission') }}"><span class="ui-ltr">{{ number_format((float) ($stat->fees ?? 0), 2) }}</span></td>
                <td class="ui-catalog__row-actions ui-channels__actions">
                    {{-- PARITY-RUN T7: the India channels have their own connection
                         page (menu push, availability sync, outlet toggle), so the
                         hub row links there instead of showing a partial control. --}}
                    @if(in_array($c->provider, ['zomato', 'swiggy'], true) && \Route::has('stores.'.$c->provider.'.index'))
                        <x-ui.button :href="route('stores.'.$c->provider.'.index', $rid)" size="sm">{{ ___('Manage') }}</x-ui.button>
                    @endif
                    @if(in_array($c->provider, $pushable ?? []))
                        <form method="post" action="{{ route('stores.aggregators.menuPush', $c->id) }}?store_id={{ $rid }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" icon="upload" data-keeta-push>{{ ___('Push menu') }}</x-ui.button>
                        </form>
                    @endif
                    <form method="post" action="{{ route('stores.aggregators.pause', $c->id) }}">
                        @csrf
                        <button type="submit" class="ui-icon-btn" aria-label="{{ $c->status === 'active' ? ___('Pause') : ___('Resume') }}" title="{{ $c->status === 'active' ? ___('Pause') : ___('Resume') }}"><x-ui.icon :name="$c->status === 'active' ? 'pause' : 'refresh'" /></button>
                    </form>
                    <form method="post" action="{{ route('stores.aggregators.disconnect', $c->id) }}" data-confirm="{{ ___('Disconnect this channel?') }}" data-confirm-danger>
                        @csrf @method('DELETE')
                        <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Disconnect') }}" title="{{ ___('Disconnect') }}"><x-ui.icon name="trash" /></button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</section>

@if(!empty($syncLog) && count($syncLog))
<x-ui.card :title="___('Menu sync log')" variant="flush" data-sync-log>
    <table class="ui-table">
        <thead><tr>
            <th>{{ ___('Channel') }}</th><th>{{ ___('When') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Detail') }}</th>
        </tr></thead>
        <tbody>
        @foreach($syncLog as $row)
            <tr>
                <td data-primary>{{ ucwords($row->provider) }}</td>
                <td data-label="{{ ___('When') }}">{{ $row->created_at?->diffForHumans() }}</td>
                <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$row->status === 'ok' ? 'success' : 'warning'">{{ $row->status }}</x-ui.pill></td>
                <td data-label="{{ ___('Detail') }}"><span class="ui-channels__muted">{{ $row->message }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-ui.card>
@endif
</div>
@endsection
