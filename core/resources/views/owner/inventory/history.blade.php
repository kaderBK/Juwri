{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Stock history').' - '.$ingredient->name)
@section('page_title', ___('Stock history'))
@section('page_sub', $ingredient->name)

@section('page_actions')
    <x-ui.button :href="route('inventory.ingredients.index')" variant="secondary" icon="back">
        {{ ___('Back to ingredients') }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-card ui-card--inset">
    <div class="ui-card__body">
        <p class="ui-small ui-caps ui-text-accent">{{ ___('Ingredient') }}</p>
        <h3 class="ui-section-title">{{ $ingredient->name }}</h3>
        <p class="ui-text-2">{{ ___('Current on-hand: :qty :unit', ['qty' => rtrim(rtrim(number_format($ingredient->current_stock, 3), '0'), '.'), 'unit' => $ingredient->unit]) }}</p>
    </div>
</div>

<x-ui.card :title="___('Movement ledger')">
    @if($ingredient->movements->isEmpty())
        <x-ui.empty :title="___('No movements yet')" :body="___('No stock movements recorded yet.')" />
    @else
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('When') }}</th>
                        <th>{{ ___('Type') }}</th>
                        <th class="ui-end">{{ ___('Change') }}</th>
                        <th class="ui-end">{{ ___('Balance') }}</th>
                        <th>{{ ___('Reason') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ingredient->movements as $m)
                        @php
                            $variant = match($m->type) {
                                'purchase' => 'success',
                                'sale' => 'info',
                                'waste' => 'danger',
                                'count' => 'warning',
                                default => 'neutral',
                            };
                            $qty = (float) $m->quantity;
                        @endphp
                        <tr>
                            <td data-label="{{ ___('When') }}">{{ optional($m->created_at)->format('Y-m-d H:i') }}</td>
                            <td data-label="{{ ___('Type') }}"><x-ui.tag :tone="$variant">{{ ___(ucfirst($m->type)) }}</x-ui.tag></td>
                            <td class="ui-end {{ $qty < 0 ? 'ui-text-danger' : 'ui-text-success' }}" data-label="{{ ___('Change') }}">
                                {{ $qty > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($qty, 3), '0'), '.') }}
                            </td>
                            <td class="ui-end" data-label="{{ ___('Balance') }}">{{ rtrim(rtrim(number_format($m->balance_after, 3), '0'), '.') }}</td>
                            <td data-label="{{ ___('Reason') }}">{{ $m->reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>
@endsection
