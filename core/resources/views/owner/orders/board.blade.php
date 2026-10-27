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
    $stateLabels = [
        'new' => ___('New'),
        'packing' => ___('Packing'),
        'ready' => ___('Ready'),
        'shipped' => ___('Shipped'),
        'collected' => ___('Collected'),
        'done' => ___('Done'),
    ];
@endphp

<div class="ui-scope ui-board-page">
    @if(session('success'))
        <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
    @endif

    {{-- Goods order board (owner UI kit): one column per state, scrolling sideways
         on phones. Cards keep data-order-id so push deep links (?order=) focus them. --}}
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
                            <x-ui.tag>{{ $typeLabels[$order->type] ?? ___(':type order', ['type' => $order->type]) }}</x-ui.tag>
                            <form method="POST" action="{{ route('stores.orders.board.advance', $order->id) }}?store_id={{ $store->id }}" class="ui-board-card__form">
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
