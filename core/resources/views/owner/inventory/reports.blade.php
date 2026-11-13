{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Inventory reports'))
@section('page_title', ___('Inventory reports'))
@section('page_sub', ___('Food cost, stock value, waste and variance.'))

@section('content')
<div class="ui-scope ui-invrep">
<form method="get" action="{{ route('inventory.reports.index') }}" class="ui-invrep__context">
    <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
        <option value="">{{ ___('Select store…') }}</option>
        @foreach ($stores as $r)
            <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
        @endforeach
    </select>
    {{-- D9: per-branch on-hand view (BranchStock); "All branches" = shared figures. --}}
    @if(isset($branches) && $branches->count())
        <select name="branch_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Branch') }}">
            <option value="0">{{ ___('All branches') }}</option>
            @foreach ($branches as $b)
                <option value="{{ $b->id }}" @selected((int) ($branchId ?? 0) === (int) $b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
    @endif
</form>

@if(!$report)
    <div class="ui-card">
        <x-ui.empty :title="___('Select a store')" :body="___('Pick a store to see its inventory reports.')" icon="chart" />
    </div>
@else
    {{-- KPI row --}}
    <section class="ui-card ui-account__hero ui-invrep__hero">
        <div>
            <p class="ui-overline">{{ ___('Inventory health') }}</p>
            <h2 class="ui-account__name ui-ltr">{{ price_symbol_format($report['stockValue']) }}</h2>
            <p class="ui-account__row-sub">{{ ___('Total stock value on hand') }}</p>
        </div>
    </section>
    <section class="ui-invrep__stats" aria-label="{{ ___('Inventory health') }}">
        <x-ui.stat :value="$report['avgFoodCost'] !== null ? round($report['avgFoodCost'], 1).'%' : '—'" :label="___('Avg food cost')" icon="chart" />
        <x-ui.stat :value="price_symbol_format($report['wasteCost30'])" :label="___('Waste (30d)')" icon="trash" tone="danger" />
        <x-ui.stat :value="$report['lowStock']->count()" :label="___('Below par')" icon="alert" tone="warning" />
        <x-ui.stat :value="price_symbol_format(round($report['varianceValue'], 2))" :label="___('Last count variance')" icon="trend-up" />
    </section>

    {{-- Food cost per item --}}
    <x-ui.card :title="___('Food cost by menu item')" variant="flush">
        @if($report['menus']->isEmpty())
            <x-ui.empty :title="___('No recipes yet')" :body="___('No menu items have recipes yet. Build recipes to see food cost.')" icon="book-open" />
        @else
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Item') }}</th><th class="ui-table__num">{{ ___('Price') }}</th>
                    <th class="ui-table__num">{{ ___('Recipe cost') }}</th><th class="ui-table__num">{{ ___('Margin') }}</th>
                    <th class="ui-table__num">{{ ___('Food cost %') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($report['menus'] as $m)
                        <tr>
                            <td data-primary><strong>{{ $m->name }}</strong></td>
                            <td class="ui-table__num" data-label="{{ ___('Price') }}"><span class="ui-ltr">{{ price_symbol_format($m->price) }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Recipe cost') }}"><span class="ui-ltr">{{ price_symbol_format($m->cost) }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Margin') }}"><strong class="ui-ltr">{{ price_symbol_format(round($m->price - $m->cost, 2)) }}</strong></td>
                            <td class="ui-table__num" data-label="{{ ___('Food cost %') }}">
                                @if($m->food_cost === null)<span class="ui-invrep__none">—</span>
                                @else<x-ui.pill :tone="$m->food_cost <= 30 ? 'success' : ($m->food_cost <= 40 ? 'warning' : 'danger')"><span class="ui-ltr">{{ $m->food_cost }}%</span></x-ui.pill>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    {{-- Low stock --}}
    <x-ui.card :title="___('Below par — reorder')" variant="flush">
        @if($report['lowStock']->isEmpty())
            <x-ui.empty :title="___('All above par')" :body="___('Everything is above par. Nothing to reorder.')" icon="check" />
        @else
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Ingredient') }}</th><th class="ui-table__num">{{ ___('On hand') }}</th>
                    <th class="ui-table__num">{{ ___('Par') }}</th><th class="ui-table__num">{{ ___('Shortfall') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($report['lowStock'] as $ing)
                        <tr>
                            <td data-primary><span class="ui-invrep__name"><strong>{{ $ing->name }}</strong> <x-ui.tag>{{ $ing->unit }}</x-ui.tag></span></td>
                            <td class="ui-table__num" data-label="{{ ___('On hand') }}"><span class="ui-ltr">{{ rtrim(rtrim(number_format($ing->current_stock, 3), '0'), '.') }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Par') }}"><span class="ui-ltr">{{ rtrim(rtrim(number_format($ing->par_level, 3), '0'), '.') }}</span></td>
                            <td class="ui-table__num ui-invrep__short" data-label="{{ ___('Shortfall') }}"><strong class="ui-ltr">{{ rtrim(rtrim(number_format(max(0, $ing->par_level - $ing->current_stock), 3), '0'), '.') }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
@endif
</div>
@endsection
