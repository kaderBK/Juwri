{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Purchase order').' #'.$po->id)
@section('page_title', ___('Purchase order').' #'.$po->id)
@section('page_sub', $po->supplier->name ?? ___('No supplier'))

@section('page_actions')
    <x-ui.button :href="route('inventory.purchaseOrders.index', ['store_id' => $po->store_id])" variant="secondary" icon="back">
        {{ ___('Back') }}
    </x-ui.button>
@endsection

@section('content')
@php
    $statusVariant = ['draft'=>'neutral','ordered'=>'info','received'=>'success','cancelled'=>'danger'];
    $editable = !in_array($po->status, ['received','cancelled']);
@endphp

<div class="ui-card ui-card--inset">
    <div class="ui-cluster ui-cluster--between ui-card__body">
        <div>
            <p class="ui-small ui-caps ui-text-accent">{{ ___('Status') }}</p>
            <h3 class="ui-section-title">{{ ___(ucfirst($po->status)) }}</h3>
            <p class="ui-text-2">{{ ___('Total :t', ['t' => price_symbol_format($po->total)]) }}</p>
        </div>
        <div class="ui-cluster">
            <div><span class="ui-muted ui-block">{{ ___('Lines') }}</span><strong class="ui-fs-lg">{{ $po->items->count() }}</strong></div>
            <div><span class="ui-muted ui-block">{{ ___('Received') }}</span><strong class="ui-fs-lg">{{ $po->received_at ? $po->received_at->format('Y-m-d') : '—' }}</strong></div>
        </div>
    </div>
</div>

{{-- Status actions --}}
<x-ui.card>
    <div class="ui-cluster">
        <x-ui.tag :tone="$statusVariant[$po->status] ?? 'neutral'">{{ ___(ucfirst($po->status)) }}</x-ui.tag>
        @if($po->status === 'draft')
            <form method="post" action="{{ route('inventory.purchaseOrders.ordered', $po->id) }}">@csrf
                <x-ui.button type="submit" variant="secondary" icon="check">{{ ___('Mark as ordered') }}</x-ui.button>
            </form>
        @endif
        @if($editable && $po->items->count())
            <form method="post" action="{{ route('inventory.purchaseOrders.receive', $po->id) }}"
                data-confirm="{{ ___('Receive this order? Stock will be added to inventory.') }}">@csrf
                <x-ui.button type="submit" icon="products">{{ ___('Receive stock') }}</x-ui.button>
            </form>
        @endif
        @if($editable)
            <form method="post" action="{{ route('inventory.purchaseOrders.cancel', $po->id) }}"
                data-confirm="{{ ___('Cancel this order?') }}">@csrf
                <x-ui.button type="submit" variant="ghost" icon="x">{{ ___('Cancel order') }}</x-ui.button>
            </form>
        @endif
    </div>
</x-ui.card>

{{-- Add line --}}
@if($editable)
    <x-ui.card :title="___('Add line')">
        @if($ingredients->isEmpty())
            <p class="ui-text-2">{{ ___('Add ingredients to this store first.') }}</p>
        @else
            <form method="post" action="{{ route('inventory.purchaseOrders.addItem', $po->id) }}" class="ui-form-grid">
                @csrf
                <div>
                    <label class="ui-label">{{ ___('Ingredient') }}</label>
                    <select class="ui-input" name="ingredient_id" required>
                        @foreach($ingredients as $ing)<option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>@endforeach
                    </select>
                </div>
                <div><label class="ui-label">{{ ___('Quantity') }}</label><input class="ui-input" name="quantity" type="number" step="0.001" min="0" required></div>
                <div><label class="ui-label">{{ ___('Unit cost') }}</label><input class="ui-input" name="unit_cost" type="number" step="0.01" min="0" required></div>
                <div><x-ui.button type="submit" icon="plus">{{ ___('Add') }}</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>
@endif

{{-- Lines --}}
<x-ui.card :title="___('Order lines')">
    @if($po->items->isEmpty())
        <x-ui.empty :title="___('No lines yet')" :body="___('Add ingredient lines above.')" />
    @else
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Ingredient') }}</th><th class="ui-end">{{ ___('Quantity') }}</th>
                    <th class="ui-end">{{ ___('Unit cost') }}</th><th class="ui-end">{{ ___('Line total') }}</th>
                    @if($editable)<th class="ui-end">{{ ___('Actions') }}</th>@endif
                </tr></thead>
                <tbody>
                    @foreach ($po->items as $line)
                        <tr>
                            <td data-label="{{ ___('Ingredient') }}"><strong>{{ $line->ingredient->name ?? '—' }}</strong> <small class="ui-text-2">{{ $line->ingredient->unit ?? '' }}</small></td>
                            <td class="ui-end" data-label="{{ ___('Quantity') }}">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }}</td>
                            <td class="ui-end" data-label="{{ ___('Unit cost') }}">{{ price_symbol_format($line->unit_cost) }}</td>
                            <td class="ui-end" data-label="{{ ___('Line total') }}">{{ price_symbol_format($line->line_total) }}</td>
                            @if($editable)
                                <td class="ui-end" data-label="{{ ___('Actions') }}">
                                    <form method="post" action="{{ route('inventory.purchaseOrders.removeItem', [$po->id, $line->id]) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm">{{ ___('Remove') }}</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr>
                    <td colspan="3" class="ui-end ui-strong">{{ ___('Total') }}</td>
                    <td class="ui-end ui-strong" data-label="{{ ___('Line total') }}">{{ price_symbol_format($po->total) }}</td>
                    @if($editable)<td data-label="{{ ___('Actions') }}"></td>@endif
                </tr></tfoot>
            </table>
        </div>
    @endif
</x-ui.card>
@endsection
