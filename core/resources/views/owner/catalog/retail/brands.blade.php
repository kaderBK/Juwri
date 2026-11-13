{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Brands').' - '.$store->title)
@section('page_title', ___('Brands'))
@section('page_sub', $store->title)

@section('page_actions')
    @if(Route::has('retail.products.index'))
        <x-ui.button :href="route('retail.products.index', $store->id)" variant="secondary" icon="back">
            {{ ___('Back to :label', ['label' => $vocab['items']]) }}
        </x-ui.button>
    @endif
@endsection

@section('content')
<div class="ui-scope ui-brands">
    <x-ui.card :title="___('Add brand')">
        <form method="POST" action="{{ route('retail.brands.store', $store->id) }}" class="ui-brands__add">
            @csrf
            <div class="ui-brands__field">
                <label class="ui-label" for="brand-new-name">{{ ___('Name') }}</label>
                <input id="brand-new-name" type="text" name="name" class="ui-input" required>
            </div>
            <x-ui.button type="submit" icon="plus">{{ ___('Add') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="___('Brands')" variant="flush">
        <x-slot:actions><x-ui.tag>{{ count($brands) }}</x-ui.tag></x-slot:actions>
        {{-- One <form> per row, defined OUTSIDE the list and wired to its
             row's controls via the HTML5 `form="id"` attribute — a <form>
             cannot be a valid child of <tr>/<td> in the DOM. --}}
        @foreach($brands as $brand)
            <form id="brand-update-{{ $brand->id }}" method="POST" action="{{ route('retail.brands.update', [$store->id, $brand->id]) }}"></form>
            <form id="brand-delete-{{ $brand->id }}" method="POST" action="{{ route('retail.brands.destroy', [$store->id, $brand->id]) }}" data-confirm="{{ ___('Delete this brand?') }}" data-confirm-danger></form>
        @endforeach

        @if(count($brands) === 0)
            <x-ui.empty :title="___('No records found.')" icon="gift" />
        @else
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Name') }}</th>
                        <th class="ui-table__num">{{ ___('Products') }}</th>
                        <th>{{ ___('Active') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($brands as $brand)
                        <tr>
                            <td data-primary>
                                <input type="text" name="name" form="brand-update-{{ $brand->id }}" class="ui-input ui-input--sm" value="{{ $brand->name }}" aria-label="{{ ___('Name') }}">
                                <input type="hidden" name="_token" value="{{ csrf_token() }}" form="brand-update-{{ $brand->id }}">
                                <input type="hidden" name="_method" value="PUT" form="brand-update-{{ $brand->id }}">
                            </td>
                            <td class="ui-table__num" data-label="{{ ___('Products') }}">{{ $brand->items_count }}</td>
                            <td data-label="{{ ___('Active') }}">
                                <span class="ui-check">
                                    <input type="checkbox" role="switch" name="active" value="1" form="brand-update-{{ $brand->id }}" @checked($brand->active) aria-label="{{ ___('Active') }}">
                                </span>
                            </td>
                            <td class="ui-catalog__row-actions">
                                <button type="submit" form="brand-update-{{ $brand->id }}" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Save') }}</button>
                                <input type="hidden" name="_token" value="{{ csrf_token() }}" form="brand-delete-{{ $brand->id }}">
                                <input type="hidden" name="_method" value="DELETE" form="brand-delete-{{ $brand->id }}">
                                <button type="submit" form="brand-delete-{{ $brand->id }}" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</div>
@endsection
