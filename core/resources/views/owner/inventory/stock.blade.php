{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Inventory'))
@section('page_title', ___('Inventory tracking'))
@section('page_sub', ___('Stock levels per item. Set low-stock thresholds.'))

@section('page_actions')
    <form method="get" action="{{ route('inventory.index') }}" class="ui-inv__store">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            <option value="">{{ ___('All stores') }}</option>
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('inventory')])

<div class="ui-scope ui-inv">
{{-- Hero + stock health --}}
<section @class(['ui-card', 'ui-account__hero', 'ui-inv__hero', 'is-low' => $lowStockCount > 0])>
    <div class="ui-inv__intro">
        <p class="ui-overline">{{ ___('Inventory') }}</p>
        <h2 class="ui-account__name">{{ ___('Stock health') }}</h2>
        <p class="ui-account__row-sub">{{ ___('Items with tracking enabled show stock and auto-mark sold-out at 0.') }}</p>
    </div>
    <dl class="ui-account__facts ui-inv__facts">
        <div><dt>{{ ___('Tracked') }}</dt><dd>{{ $items->count() }}</dd></div>
        <div @class(['is-warning' => $lowStockCount > 0])><dt>{{ ___('Low stock') }}</dt><dd>{{ $lowStockCount }}</dd></div>
        {{-- H5: orders taken for stock the row did not have. 0 = healthy (normal
             style, no reset). Non-zero = danger + a Clear control. --}}
        <div @class(['is-danger' => ($oversellCount ?? 0) > 0])>
            <dt>{{ ___('Oversold') }}</dt>
            <dd>{{ $oversellCount ?? 0 }}</dd>
            @if (($oversellCount ?? 0) > 0)
                @if ($oversellAt ?? null)
                    <small class="ui-inv__when">{{ ___('Last: :when', ['when' => $oversellAt]) }}</small>
                @endif
                @if ($storeId)
                    <form action="{{ route('inventory.oversellReset') }}" method="post" class="ui-inv__clear">
                        @csrf
                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        <x-ui.button type="submit" variant="link" size="sm">{{ ___('Clear') }}</x-ui.button>
                    </form>
                @endif
            @endif
        </div>
    </dl>
</section>

@if ($storeId)
    <form action="{{ route('inventory.enableAll') }}" method="post" class="ui-card ui-account__row ui-inv__enable">
        @csrf
        <input type="hidden" name="store_id" value="{{ $storeId }}">
        <div><p class="ui-account__row-sub">{{ ___('Enable inventory tracking on every menu item for this store.') }}</p></div>
        <div class="ui-account__row-actions">
            <x-ui.button type="submit" variant="secondary" icon="check">{{ ___('Enable on all items') }}</x-ui.button>
        </div>
    </form>
@endif

<x-ui.card :title="___('Tracked items')" variant="flush">
    <x-slot:actions><x-ui.tag>{{ $items->count() }}</x-ui.tag></x-slot:actions>
    @if ($items->isEmpty())
        <x-ui.empty :title="___('No tracked items')" :body="___('No items with tracking enabled. Toggle &quot;Track inventory&quot; on a menu item to start.')" icon="products" />
    @else
        <table class="ui-table">
            <thead><tr><th>{{ ___('Item') }}</th><th class="ui-table__num">{{ ___('Stock') }}</th><th class="ui-table__num">{{ ___('Threshold') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Update') }}</th></tr></thead>
            <tbody>
                @foreach ($items as $item)
                    @php
                        $low = ($item->stock_qty ?? 0) <= $item->low_stock_threshold;
                        $state = $item->is_sold_out ? 'danger' : ($low ? 'warning' : 'success');
                        $label = $item->is_sold_out ? ___('Sold out') : ($low ? ___('Low') : ___('OK'));
                    @endphp
                    <tr>
                        <td data-primary><strong>{{ $item->name }}</strong></td>
                        <td class="ui-table__num" data-label="{{ ___('Stock') }}"><strong class="ui-ltr">{{ $item->stock_qty ?? 0 }}</strong></td>
                        <td class="ui-table__num" data-label="{{ ___('Threshold') }}"><span class="ui-ltr">{{ $item->low_stock_threshold }}</span></td>
                        <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$state">{{ $label }}</x-ui.pill></td>
                        <td data-label="{{ ___('Update') }}">
                            <form action="{{ route('inventory.update', $item->id) }}" method="post" class="ui-inv__update">
                                @csrf @method('PUT')
                                <input type="number" name="stock_qty" class="ui-input ui-input--sm" min="0" value="{{ $item->stock_qty ?? 0 }}" inputmode="numeric" aria-label="{{ ___('Stock') }}">
                                <button type="submit" class="ui-icon-btn" aria-label="{{ ___('Save') }}" title="{{ ___('Save') }}"><x-ui.icon name="check" /></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-ui.card>
</div>
@endsection
