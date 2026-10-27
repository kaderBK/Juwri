{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- P3-08: an item's variant grid (colour × size): option group display + swatches, size guide, generated cells with price, "was" price, stock, SKU and GTIN. --}}
@section('title', ___('Variants'))
@section('page_title', ___('Variants').' · '.$item->getRawOriginal('name'))
@section('page_sub', ___('One variant per combination of options, each with its own price and stock.'))

@section('content')
@php
    $q = ['store_id' => $rid, 'menu' => $item->id];
    $exp = \Juwri\Core\Money\Currencies::exponent($currency);
    $major = fn ($minor) => $minor === null ? '' : number_format(\Juwri\Core\Money\Money::majorFromMinor((int) $minor, $currency), $exp, '.', '');
    $displays = ['' => ___('Default'), 'swatch' => ___('Colour swatches'), 'size' => ___('Size buttons'), 'chips' => ___('Chips')];
@endphp
<div class="ui-scope" data-retail-variants>
    @if($errors->any())
        <div class="ui-card"><p class="ui-text-danger">{{ $errors->first() }}</p></div>
    @endif

    <section class="ui-card">
        <h3 class="ui-section-title">{{ ___('Option groups') }}</h3>
        @if($groups->isEmpty())
            <x-ui.empty icon="list" :title="___('Add option groups (e.g. Colour, Size) to this item first.')" />
        @else
            <form method="post" action="{{ route('stores.retail.variants.groups', $q) }}" class="ui-form-grid">
                @csrf
                @foreach($groups as $group)
                    <fieldset class="ui-form-grid__full" data-group="{{ $group->id }}">
                        <legend class="ui-label">{{ $group->title }}</legend>
                        <select class="ui-input" name="display[{{ $group->id }}]" aria-label="{{ ___('How it is shown') }}">
                            @foreach($displays as $value => $label)
                                <option value="{{ $value }}" @selected((string) $group->display === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @foreach($group->modifiers as $option)
                            <label>{{ $option->title }}
                                <input type="text" class="ui-input ui-input--sm" name="swatch[{{ $option->id }}]" value="{{ $option->swatch }}" placeholder="#C2B280" pattern="#[0-9A-Fa-f]{6}" aria-label="{{ ___('Swatch colour for :option', ['option' => $option->title]) }}">
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="v-size-guide">{{ ___('Size guide') }}</label>
                    <select class="ui-input" id="v-size-guide" name="size_guide_id">
                        <option value="">—</option>
                        @foreach($guides as $guide)
                            <option value="{{ $guide->id }}" @selected($item->size_guide_id == $guide->id)>{{ $guide->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.button type="submit" size="sm">{{ ___('Save') }}</x-ui.button>
                </div>
            </form>

            <h4 class="ui-section-title">{{ ___('Create the grid') }}</h4>
            <form method="post" action="{{ route('stores.retail.variants.generate', $q) }}">
                @csrf
                @foreach($groups as $group)
                    <label><input type="checkbox" name="groups[]" value="{{ $group->id }}" checked> {{ $group->title }}</label>
                @endforeach
                <x-ui.button type="submit" size="sm" icon="plus">{{ ___('Add missing variants') }}</x-ui.button>
            </form>
        @endif
    </section>

    @if($cells->isNotEmpty())
        <section class="ui-card">
            <h3 class="ui-section-title">{{ ___('Variants') }}</h3>
            <form method="post" action="{{ route('stores.retail.variants.cells', $q) }}">
                @csrf
                <div style="overflow-x: auto;">
                <table class="ui-table">
                    <thead>
                    <tr>
                        <th scope="col">{{ ___('Variant') }}</th>
                        <th scope="col">{{ ___('Price') }} ({{ $currency }})</th>
                        <th scope="col">{{ ___('Was price') }}</th>
                        <th scope="col">{{ ___('Stock') }}</th>
                        <th scope="col">{{ ___('Low stock at') }}</th>
                        <th scope="col">{{ ___('SKU') }}</th>
                        <th scope="col">{{ ___('GTIN') }}</th>
                        <th scope="col">{{ ___('On sale') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($cells as $cell)
                        @php($label = \Juwri\Modules\Menu\Services\Retail\VariantMatrix::label($item, (array) $cell->cell()))
                        <tr data-cell="{{ $cell->id }}">
                            <th scope="row">{{ $label }}</th>
                            <td data-label="{{ ___('Variant') }}"><input class="ui-input ui-input--sm" type="number" step="any" min="0" name="cells[{{ $cell->id }}][price]" value="{{ $cell->price }}" aria-label="{{ ___('Price') }} {{ $label }}"></td>
                            <td data-label="{{ ___('Price') }} ({{ $currency }})"><input class="ui-input ui-input--sm" type="number" step="any" min="0" name="cells[{{ $cell->id }}][compare_at]" value="{{ $major($cell->compare_at_minor) }}" aria-label="{{ ___('Was price') }} {{ $label }}"></td>
                            <td data-label="{{ ___('Was price') }}"><input class="ui-input ui-input--sm" type="number" min="0" name="cells[{{ $cell->id }}][stock_qty]" value="{{ $cell->stock_qty }}" aria-label="{{ ___('Stock') }} {{ $label }}"></td>
                            <td data-label="{{ ___('Stock') }}"><input class="ui-input ui-input--sm" type="number" min="0" name="cells[{{ $cell->id }}][low_stock_threshold]" value="{{ $cell->low_stock_threshold }}" aria-label="{{ ___('Low stock at') }} {{ $label }}"></td>
                            <td data-label="{{ ___('Low stock at') }}"><input class="ui-input ui-input--sm" type="text" maxlength="64" name="cells[{{ $cell->id }}][sku]" value="{{ $cell->sku }}" aria-label="{{ ___('SKU') }} {{ $label }}"></td>
                            <td data-label="{{ ___('SKU') }}"><input class="ui-input ui-input--sm" type="text" maxlength="14" inputmode="numeric" name="cells[{{ $cell->id }}][gtin]" value="{{ $cell->gtin }}" aria-label="{{ ___('GTIN') }} {{ $label }}"></td>
                            <td data-label="{{ ___('GTIN') }}">
                                <input type="hidden" name="cells[{{ $cell->id }}][active]" value="0">
                                <input type="checkbox" name="cells[{{ $cell->id }}][active]" value="1" @checked($cell->active) aria-label="{{ ___('On sale') }} {{ $label }}">
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                <x-ui.button type="submit" size="sm">{{ ___('Save variants') }}</x-ui.button>
            </form>
        </section>
    @endif
</div>
@endsection
