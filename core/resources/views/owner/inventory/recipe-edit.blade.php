{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Recipe').' - '.$menu->getRawOriginal('name'))
@section('page_title', ___('Recipe'))
@section('page_sub', $menu->getRawOriginal('name'))

@section('page_actions')
    <x-ui.button :href="route('inventory.recipes.index', ['store_id' => $menu->store_id])" variant="secondary" icon="back">
        {{ ___('Back to recipes') }}
    </x-ui.button>
@endsection

@section('content')
@php
    $price = (float) $menu->price;
    $cost  = $menu->recipe_cost;
    $fc    = $menu->food_cost_percent;
    $margin = $price - $cost;
@endphp

<div class="ui-card {{ ($fc !== null && $fc > 40) ? 'ui-bg-danger' : 'ui-bg-accent' }}">
    <div class="ui-cluster ui-cluster--between ui-card__body">
        <div>
            <p class="ui-small ui-caps {{ ($fc !== null && $fc > 40) ? 'ui-text-danger' : 'ui-text-accent' }}">{{ ___('Menu item') }}</p>
            <h3 class="ui-section-title">{{ $menu->getRawOriginal('name') }}</h3>
            <p class="ui-text-2">{{ ___('Selling price :p', ['p' => price_symbol_format($price)]) }}</p>
        </div>
        <div class="ui-cluster">
            <div><span class="ui-muted ui-block">{{ ___('Recipe cost') }}</span><strong class="ui-fs-lg">{{ price_symbol_format($cost) }}</strong></div>
            <div><span class="ui-muted ui-block">{{ ___('Margin') }}</span><strong class="ui-fs-lg">{{ price_symbol_format($margin) }}</strong></div>
            <div><span class="ui-muted ui-block">{{ ___('Food cost') }}</span><strong class="ui-fs-lg">{{ $fc === null ? '—' : $fc.'%' }}</strong></div>
        </div>
    </div>
</div>

{{-- Add / update ingredient line --}}
<x-ui.card :title="___('Add ingredient')">
    @if($ingredients->isEmpty())
        <p class="ui-text-2">
            {{ ___('No ingredients defined for this store yet.') }}
            <a href="{{ route('inventory.ingredients.index', ['store_id' => $menu->store_id]) }}" class="ui-text-accent">{{ ___('Add ingredients first') }}</a>
        </p>
    @else
        <form method="post" action="{{ route('inventory.recipes.store', $menu->id) }}" class="ui-form-grid">
            @csrf
            <div>
                <label class="ui-label">{{ ___('Ingredient') }}</label>
                <select class="ui-input" name="ingredient_id" required>
                    @foreach($ingredients as $ing)
                        <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ui-label">{{ ___('Quantity per portion') }}</label>
                <input class="ui-input" name="quantity" type="number" step="0.001" min="0" required>
            </div>
            <div>
                <x-ui.button type="submit" icon="plus">{{ ___('Add to recipe') }}</x-ui.button>
            </div>
        </form>
    @endif
</x-ui.card>

{{-- Current recipe --}}
<x-ui.card :title="___('Recipe ingredients')">
    @if($menu->recipeItems->isEmpty())
        <x-ui.empty :title="___('No ingredients yet')" :body="___('No ingredients in this recipe yet. Selling this item will not deduct stock.')" />
    @else
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Ingredient') }}</th>
                        <th class="ui-end">{{ ___('Qty / portion') }}</th>
                        <th class="ui-end">{{ ___('Unit cost') }}</th>
                        <th class="ui-end">{{ ___('Line cost') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($menu->recipeItems as $line)
                        <tr>
                            <td data-label="{{ ___('Ingredient') }}"><strong>{{ $line->ingredient->name }}</strong> <small class="ui-text-2">{{ $line->ingredient->unit }}</small></td>
                            <td class="ui-end" data-label="{{ ___('Qty / portion') }}">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }}</td>
                            <td class="ui-end" data-label="{{ ___('Unit cost') }}">{{ price_symbol_format($line->ingredient->cost_per_unit) }}</td>
                            <td class="ui-end" data-label="{{ ___('Line cost') }}">{{ price_symbol_format($line->line_cost) }}</td>
                            <td class="ui-end" data-label="{{ ___('Actions') }}">
                                <form method="post" action="{{ route('inventory.recipes.destroy', [$menu->id, $line->ingredient_id]) }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm">{{ ___('Remove') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="ui-end ui-strong">{{ ___('Total recipe cost') }}</td>
                        <td class="ui-end ui-strong" data-label="{{ ___('Line cost') }}">{{ price_symbol_format($cost) }}</td>
                        <td data-label="{{ ___('Actions') }}"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</x-ui.card>
@endsection
