{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- P3-09: grocery orders to pick, soonest slot first. --}}
@section('title', ___('Picking'))
@section('page_title', ___('Picking'))
@section('page_sub', ___('Grocery orders to pick, soonest time first.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.picking.index') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
    <x-ui.button :href="route('stores.grocery.settings', ['store_id' => $rid])" variant="secondary" icon="settings">{{ ___('Grocery settings') }}</x-ui.button>
@endsection

@section('content')
@php
    $states = ['picking' => ___('Picking'), 'picked' => ___('Picked')];
@endphp
<div class="ui-scope ui-disp" data-picking-list>
@if($orders->isEmpty())
    <div class="ui-card">
        <x-ui.empty icon="check" :title="___('Nothing to pick.')" />
    </div>
@else
    @foreach($orders as $order)
        <article class="ui-card ui-disp__item" data-picking-order="{{ $order->id }}">
            <header class="ui-disp__head">
                <div class="ui-disp__amount">
                    <strong>{{ ___('Order') }} <span class="ui-ltr">#{{ $order->id }}</span></strong>
                    <span class="ui-disp__reason">{{ $order->name ?: ___('Guest') }} · {{ $order->type === 'delivery' ? ___('Delivery') : ___('Collection') }}</span>
                </div>
                <x-ui.pill :tone="$order->picking_status === 'picked' ? 'success' : ($order->picking_status === 'picking' ? 'accent' : 'neutral')">{{ $states[$order->picking_status] ?? ___('To pick') }}</x-ui.pill>
            </header>
            <dl class="ui-disp__facts">
                <div><dt>{{ ___('Time') }}</dt><dd class="ui-ltr">{{ $order->slot_starts_at ? date_formating($order->slot_starts_at, 'd M, h:i A').' – '.date_formating($order->slot_ends_at, 'h:i A') : '—' }}</dd></div>
                @if($order->express)<div><dt>{{ ___('Express') }}</dt><dd>{{ ___('Yes') }}</dd></div>@endif
            </dl>
            <div class="ui-disp__actions">
                <x-ui.button :href="route('stores.picking.show', $order->id)" size="sm" icon="check">{{ ___('Open') }}</x-ui.button>
            </div>
        </article>
    @endforeach
@endif
</div>
@endsection
