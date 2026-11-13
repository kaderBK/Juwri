{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Webhooks'))
@section('content')
<div class="ui-stack">
    {{-- Stats --}}
    <section class="ui-kpis" aria-label="{{ ___('Webhooks') }}">
        <x-ui.stat :value="number_format($totalEndpoints)" :label="___('Total endpoints')" icon="link" />
        <x-ui.stat :value="number_format($activeEndpoints)" :label="___('Active endpoints')" icon="check" tone="success" />
        <x-ui.stat :value="number_format($deliveries7d)" :label="___('Deliveries (7 days)')" icon="send" />
        <x-ui.stat :value="$failureRate7d.'%'" :label="___('Failure rate (7 days)')" icon="alert" tone="danger" />
    </section>

    {{-- Filters --}}
    <form method="GET" class="ui-cluster">
        <select name="status" id="filter-status" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Endpoint status') }}">
            <option value="all" @selected($status === 'all')>{{ ___('All') }}</option>
            <option value="active" @selected($status === 'active')>{{ ___('Active') }}</option>
            <option value="disabled" @selected($status === 'disabled')>{{ ___('Disabled') }}</option>
        </select>
        <select name="result" id="filter-result" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Delivery result') }}">
            <option value="all" @selected($result === 'all')>{{ ___('All') }}</option>
            <option value="success" @selected($result === 'success')>{{ ___('Success') }}</option>
            <option value="failed" @selected($result === 'failed')>{{ ___('Failed') }}</option>
        </select>
        <x-ui.button type="submit" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button>
    </form>

    {{-- Endpoints --}}
    <x-ui.card variant="flush" :title="___('Endpoints')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('URL') }}</th>
                        <th>{{ ___('Owner') }}</th>
                        <th>{{ ___('Events') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th>{{ ___('Consecutive failures') }}</th>
                        <th>{{ ___('Created') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($endpoints as $endpoint)
                    <tr>
                        <td data-primary data-label="{{ ___('URL') }}" title="{{ $endpoint->url }}"><code class="ui-mono ui-break">{{ Str::limit($endpoint->url, 45) }}</code></td>
                        <td data-label="{{ ___('Owner') }}">
                            @php($owner = $owners->get($endpoint->user_id))
                            @if($owner)
                                <a href="{{ route('admin.users.edit', $owner->id) }}">{{ $owner->name }}</a>
                                <small class="ui-cell-sub">{{ $owner->email }}</small>
                            @else
                                <span class="ui-text-2">&mdash;</span>
                            @endif
                        </td>
                        <td data-label="{{ ___('Events') }}">
                            @php($events = array_values((array) $endpoint->events))
                            <div class="ui-cell-tags">
                                @foreach(array_slice($events, 0, 3) as $event)
                                    <x-ui.tag tone="accent">{{ $event }}</x-ui.tag>
                                @endforeach
                                @if(count($events) > 3)
                                    <x-ui.tag>+{{ count($events) - 3 }}</x-ui.tag>
                                @endif
                            </div>
                        </td>
                        <td data-label="{{ ___('Status') }}">
                            @if($endpoint->active)
                                <x-ui.tag tone="success">{{ ___('Active') }}</x-ui.tag>
                            @else
                                <x-ui.tag tone="danger" :title="$endpoint->disabled_reason ?: null">{{ ___('Disabled') }}</x-ui.tag>
                            @endif
                        </td>
                        <td data-label="{{ ___('Consecutive failures') }}">{{ number_format((int) $endpoint->consecutive_failures) }}</td>
                        <td data-label="{{ ___('Created') }}">{{ $endpoint->created_at?->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ui-center ui-text-2">{{ ___('No webhook endpoints registered yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($endpoints->hasPages())
            <div class="ui-card__foot">{{ $endpoints->withQueryString()->links() }}</div>
        @endif
    </x-ui.card>

    {{-- Recent deliveries --}}
    <x-ui.card variant="flush" :title="___('Recent deliveries')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Event') }}</th>
                        <th>{{ ___('Endpoint') }}</th>
                        <th>{{ ___('HTTP status') }}</th>
                        <th>{{ ___('Attempt') }}</th>
                        <th>{{ ___('Time') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($deliveries as $delivery)
                    <tr>
                        <td data-primary data-label="{{ ___('Event') }}"><x-ui.tag tone="accent">{{ $delivery->event }}</x-ui.tag></td>
                        <td data-label="{{ ___('Endpoint') }}" title="{{ $endpointUrls[$delivery->endpoint_id] ?? '' }}"><code class="ui-mono ui-break">{{ Str::limit($endpointUrls[$delivery->endpoint_id] ?? '—', 45) }}</code></td>
                        <td data-label="{{ ___('HTTP status') }}">
                            <x-ui.tag :tone="$delivery->success ? 'success' : 'danger'">{{ $delivery->http_status ?? ___('n/a') }}</x-ui.tag>
                        </td>
                        <td data-label="{{ ___('Attempt') }}">{{ (int) $delivery->attempt }}</td>
                        <td data-label="{{ ___('Time') }}">{{ $delivery->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ui-center ui-text-2">{{ ___('No deliveries match the current filter.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
