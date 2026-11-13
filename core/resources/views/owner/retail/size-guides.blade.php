{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- P3-08: size guides — a measurement table per store, attached to items on the item's variants page. --}}
@section('title', ___('Size guides'))
@section('page_title', ___('Size guides'))
@section('page_sub', ___('Measurement tables your customers see on the product page.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.retail.sizeGuides') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php($q = ['store_id' => $rid])
<div class="ui-scope" data-size-guides>
    @if($errors->any())
        <div class="ui-card"><p class="ui-text-danger">{{ $errors->first() }}</p></div>
    @endif
    @foreach($guides->concat([null]) as $guide)
        @php($fp = $guide ? 'g'.$guide->id.'-' : 'gn-')
        <section class="ui-card" @if($guide) data-guide="{{ $guide->id }}" @else data-guide-new @endif>
            <h3 class="ui-section-title">{{ $guide ? $guide->title : ___('Add a size guide') }}</h3>
            <form method="post" action="{{ $guide ? route('stores.retail.sizeGuides.update', ['guide' => $guide->id] + $q) : route('stores.retail.sizeGuides.store', $q) }}" class="ui-form-grid">
                @csrf
                <x-ui.field name="title" :id="$fp.'title'" :label="___('Title')" :value="$guide->title ?? ''" />
                <x-ui.field name="unit" :id="$fp.'unit'" type="select" :label="___('Unit')" :value="$guide->unit ?? 'cm'" :options="['cm' => ___('Centimetres'), 'in' => ___('Inches')]" />
                <div class="ui-form-grid__full">
                    <x-ui.field name="columns" :id="$fp.'columns'" :label="___('Column names, separated by commas')" :value="$guide ? implode(', ', (array) $guide->columns) : ''" placeholder="Size, Chest, Waist" />
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.field name="rows" :id="$fp.'rows'" type="textarea" rows="6" :label="___('One row per line, cells separated by commas')" :value="$guide ? implode(PHP_EOL, \Juwri\Core\Support\Views\StoreViewHelpers::rowsToCsv((array) $guide->rows)) : ''" />
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.field name="note" :id="$fp.'note'" type="textarea" rows="2" :label="___('Note (optional)')" :value="$guide->note ?? ''" />
                </div>
                <input type="hidden" name="active" value="0">
                <label class="ui-label"><input type="checkbox" name="active" value="1" @checked($guide->active ?? true)> {{ ___('Active') }}</label>
                <div class="ui-form-grid__full">
                    <x-ui.button type="submit" size="sm">{{ $guide ? ___('Save') : ___('Add size guide') }}</x-ui.button>
                </div>
            </form>
            @if($guide)
                <form method="post" action="{{ route('stores.retail.sizeGuides.delete', ['guide' => $guide->id] + $q) }}" data-confirm="{{ ___('Delete this size guide?') }}" data-confirm-danger>
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary">{{ ___('Delete') }}</x-ui.button>
                </form>
            @endif
        </section>
    @endforeach
</div>
@endsection
