{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Orders'))
@section('header_buttons')
    {{-- H10: queue the order-totals backfill so the "missing totals" chip falls to 0. --}}
    <form method="POST" action="{{ route('admin.orders.backfill-totals') }}" data-ui-confirm="{{ ___('Queue the order totals backfill?') }}">
        @csrf
        <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('Backfill order totals') }}</x-ui.button>
    </form>
@endsection

@section('content')
<div class="ui-stack">
    <section class="ui-kpis" aria-label="{{ ___('Orders') }}">
        @foreach ([
            'today'    => [___('Orders today'), 'orders', null],
            'week'     => [___('Last 7 days'), 'calendar', null],
            'month'    => [___('Last 30 days'), 'chart', 'success'],
            'inflight' => [___('Deliveries in flight'), 'delivery', 'warning'],
            'unsnapshotted' => [___('Orders missing totals'), 'alert', 'danger'],
        ] as $key => [$label, $icon, $tone])
            <x-ui.stat :value="format_number_count($stats[$key] ?? 0)" :label="$label" :icon="$icon" :tone="$tone" />
        @endforeach
    </section>

    <form method="GET" class="ui-cluster">
        <select name="status" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Status') }}">
            <option value="">{{ ___('Any status') }}</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ___($status) }}</option>
            @endforeach
        </select>
        <select name="type" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Type') }}">
            <option value="">{{ ___('Any type') }}</option>
            @foreach (['on-table', 'takeaway', 'delivery'] as $type)
                <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ ___($type) }}</option>
            @endforeach
        </select>
        <select name="source" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Source') }}">
            <option value="">{{ ___('Any source') }}</option>
            @foreach (['qr', 'pos'] as $source)
                <option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ order_source_label($source) }}</option>
            @endforeach
        </select>
        <select name="days" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Date') }}">
            <option value="">{{ ___('All time') }}</option>
            @foreach ([1 => ___('Today'), 7 => ___('7 days'), 30 => ___('30 days')] as $d => $label)
                <option value="{{ $d }}" @selected((int) ($filters['days'] ?? 0) === $d)>{{ $label }}</option>
            @endforeach
        </select>
        <input type="search" name="store" value="{{ $filters['store'] ?? '' }}" class="ui-input ui-input--sm ui-input--auto"
               placeholder="{{ ___('Restaurant…') }}" aria-label="{{ ___('Restaurant') }}">
        <x-ui.button type="submit" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button>
    </form>

    <x-ui.card :title="___('All orders')" variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ ___('Restaurant') }}</th>
                        <th>{{ ___('Owner') }}</th>
                        <th>{{ ___('Type') }}</th>
                        <th>{{ ___('Source') }}</th>
                        <th>{{ ___('Items') }}</th>
                        <th>{{ ___('Total') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th>{{ ___('Placed') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        @php
                            $tone = match ($order->status) {
                                'completed' => 'success',
                                'cancelled' => 'danger',
                                default => 'warning',
                            };
                        @endphp
                        <tr>
                            <td data-label="#" class="ui-text-2">{{ $order->id }}</td>
                            <td data-primary data-label="{{ ___('Restaurant') }}">{{ $order->store->title ?? '—' }}</td>
                            <td data-label="{{ ___('Owner') }}">
                                @if ($order->store?->user)
                                    <a href="{{ route('admin.users.edit', $order->store->user->id) }}">{{ $order->store->user->name }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            {{-- Legacy food types keep their own words; the vertical modes (ship, pickup_slot, appointment, in_store) take the fulfillment catalog's translated label instead of printing the raw value. --}}
                            <td data-label="{{ ___('Type') }}">{{ \Illuminate\Support\Facades\Lang::has('lang.'.\Illuminate\Support\Str::slug((string) $order->type, '_')) ? ___($order->type) : app(\App\Services\Verticals\FulfillmentRegistry::class)->label((string) $order->type) }}</td>
                            <td data-label="{{ ___('Source') }}"><x-ui.tag :tone="$order->source === 'pos' ? 'accent' : 'neutral'">{{ order_source_label($order->source ?? 'qr') }}</x-ui.tag></td>
                            <td data-label="{{ ___('Items') }}">{{ $order->items->sum('quantity') }}</td>
                            <td data-label="{{ ___('Total') }}">{{ price_symbol_format($order->total) }}</td>
                            <td data-label="{{ ___('Status') }}">
                                <span class="ui-cell-tags">
                                    <x-ui.tag :tone="$tone">{{ ___($order->status) }}</x-ui.tag>
                                    @if ($order->delivery_status)
                                        <x-ui.tag tone="accent">{{ ___($order->delivery_status) }}</x-ui.tag>
                                    @endif
                                </span>
                            </td>
                            <td data-label="{{ ___('Placed') }}" class="ui-text-2" title="{{ $order->created_at }}">{{ $order->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="ui-center ui-text-2">{{ ___('No orders match these filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())
            <div class="ui-card__foot">{{ $orders->links() }}</div>
        @endif
    </x-ui.card>
</div>
@endsection
