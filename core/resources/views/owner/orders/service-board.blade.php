{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', $boardLabel)
@section('page_title', $boardLabel)
@section('page_sub', $store->title)

@section('content')
@php
    // Readable order types; unknown ones fall back to the old ":type" wording.
    $typeLabels = ['delivery' => ___('Delivery'), 'takeaway' => ___('Takeaway'), 'pickup_slot' => ___('Pickup'), 'pickup' => ___('Pickup'), 'on-table' => ___('Dine-in'), 'appointment' => ___('Appointment'), 'at_home' => ___('At home')];
    $stateLabels = $stateLabels ?? [];
@endphp

<div class="ui-scope ui-board-page">
    @if(session('success'))
        <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
    @endif

    {{-- Service (laundry) board on the owner UI kit — same columns as the goods
         board, plus the per-item weight re-price form on kilogram-priced items. --}}
    <div class="ui-board" role="list" aria-label="{{ $boardLabel }}">
        @foreach($states as $state)
            @php $column = $ordersByState[$state] ?? collect(); @endphp
            <section class="ui-board__col" data-state="{{ $state }}" role="listitem" aria-labelledby="board-col-{{ $state }}">
                <header class="ui-board__head">
                    <h2 class="ui-board__title" id="board-col-{{ $state }}">{{ $stateLabels[$state] ?? $state }}</h2>
                    <x-ui.count :value="$column->count()" tone="neutral" :show-zero="true" />
                </header>
                <div class="ui-board__cards">
                    @forelse($column as $order)
                        <article class="ui-board-card" data-order-id="{{ $order->id }}" data-order-status="{{ $order->status }}">
                            <div class="ui-board-card__top">
                                <span class="ui-board-card__id ui-ltr">#{{ $order->id }}</span>
                                @if($order->created_at)
                                    <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->isToday() ? date_formating($order->created_at, 'h:i A') : date_formating($order->created_at, 'd M') }}</time>
                                @endif
                            </div>
                            <strong class="ui-board-card__name">{{ $order->customer_name ?: ___('Guest') }}</strong>
                            <x-ui.tag>{{ $typeLabels[$order->type] ?? ___(':type booking', ['type' => $order->type]) }}</x-ui.tag>

                            @foreach($order->items as $item)
                                @if($item->weight_kg !== null)
                                    <form method="POST" action="{{ route('stores.service_board.weight', [$order->id, $item->id]) }}?store_id={{ $store->id }}" class="ui-board-card__weight">
                                        @csrf
                                        <label class="ui-board-card__weight-label" for="w-{{ $order->id }}-{{ $item->id }}">{{ $item->menu->getRawOriginal('name') ?? ___('Item') }}</label>
                                        <span class="ui-board-card__weight-row">
                                            <input id="w-{{ $order->id }}-{{ $item->id }}" type="number" step="0.01" min="0.01" name="weight_kg" value="{{ $item->weight_kg }}" class="ui-input ui-input--sm">
                                            <button type="submit" class="ui-btn ui-btn--ghost ui-btn--sm">{{ ___('Save weight') }}</button>
                                        </span>
                                    </form>
                                @endif
                            @endforeach

                            <form method="POST" action="{{ route('stores.service_board.advance', $order->id) }}?store_id={{ $store->id }}" class="ui-board-card__form">
                                @csrf
                                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--block">
                                    {{ ___('Advance') }} <x-ui.icon name="chevron" size="16" class="ui-flip-rtl" />
                                </button>
                            </form>
                        </article>
                    @empty
                        <p class="ui-board__empty">{{ ___('No orders here.') }}</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</div>
@endsection
