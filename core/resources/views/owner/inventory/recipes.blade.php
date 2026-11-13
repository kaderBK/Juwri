{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Recipes'))
@section('page_title', ___('Recipes & food cost'))
@section('page_sub', ___('Map each menu item to its ingredients. Sales deduct stock automatically.'))

@section('page_actions')
    <x-ui.button :href="route('inventory.ingredients.index')" variant="secondary" size="sm" icon="products">
        {{ ___('Ingredients') }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-recipes">
<form method="get" action="{{ route('inventory.recipes.index') }}" class="ui-recipes__context">
    <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
        <option value="">{{ ___('Select store…') }}</option>
        @foreach ($stores as $r)
            <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
        @endforeach
    </select>
</form>

@if($storeId)
    {{-- D10: auto-86 items when a recipe ingredient runs out (advanced inventory). --}}
    <form method="post" action="{{ route('inventory.recipes.auto86') }}" class="ui-card ui-recipes__auto">
        @csrf
        <input type="hidden" name="store_id" value="{{ $storeId }}">
        <input type="hidden" name="recipe_auto_86" value="0">
        <x-ui.switch id="autoRecipe86" name="recipe_auto_86" value="1" :checked="$autoRecipe86 ?? false" data-ui-do="submit"
            :label="___('Auto sold-out items when a recipe ingredient runs out')"
            :description="___('Restocking the ingredient brings the items back automatically. Manual sold-outs are never changed.')" />
    </form>
@endif

@if(!$storeId)
    <div class="ui-card">
        <x-ui.empty :title="___('Select a store')" :body="___('Pick a store to manage its recipes.')" icon="store" />
    </div>
@else
    <x-ui.card :title="___('Menu items')" variant="flush">
        <x-slot:actions><x-ui.tag>{{ $menus->count() }}</x-ui.tag></x-slot:actions>
        @if($menus->isEmpty())
            <x-ui.empty :title="___('No menu items')" :body="___('No menu items in this store yet.')" icon="products" />
        @else
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Item') }}</th>
                        <th class="ui-table__num">{{ ___('Price') }}</th>
                        <th class="ui-table__num">{{ ___('Recipe cost') }}</th>
                        <th class="ui-table__num">{{ ___('Food cost %') }}</th>
                        <th class="ui-table__num">{{ ___('Ingredients') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($menus as $m)
                        @php $fc = $m->food_cost_percent; @endphp
                        <tr>
                            <td data-primary><strong>{{ $m->getRawOriginal('name') }}</strong></td>
                            <td class="ui-table__num" data-label="{{ ___('Price') }}"><span class="ui-ltr">{{ price_symbol_format($m->price) }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Recipe cost') }}"><span class="ui-ltr">{{ $m->recipeItems->isEmpty() ? '—' : price_symbol_format($m->recipe_cost) }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Food cost %') }}">
                                @if($fc === null)
                                    <span class="ui-recipes__none">—</span>
                                @else
                                    <x-ui.pill :tone="$fc <= 30 ? 'success' : ($fc <= 40 ? 'warning' : 'danger')"><span class="ui-ltr">{{ $fc }}%</span></x-ui.pill>
                                @endif
                            </td>
                            <td class="ui-table__num" data-label="{{ ___('Ingredients') }}"><span class="ui-ltr">{{ $m->recipeItems->count() }}</span></td>
                            <td class="ui-catalog__row-actions">
                                <a class="ui-icon-btn" href="{{ route('inventory.recipes.edit', $m->id) }}" aria-label="{{ ___('Edit recipe') }}" title="{{ ___('Edit recipe') }}"><x-ui.icon name="pencil" /></a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
@endif
</div>
@endsection
