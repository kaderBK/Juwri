{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', $vocab['catalog'].' - '.$store->title)
@section('page_title', $vocab['items'])
@section('page_sub', $store->title)

@section('page_actions')
    @include('owner.catalog.partials.catalog-more', ['store' => $store, 'extra' => Route::has('stores.service_board.index') ? [[route('stores.service_board.index').'?store_id='.$store->id, 'orders', ___('Service board')]] : [], 'export' => null])
    @if(Route::has('laundry.services.create'))
        <x-ui.button :href="route('laundry.services.create', $store->id)" icon="plus" data-controller="bridge--button" :data-bridge-title="$vocab['create_item']" data-bridge-ios-image="plus" data-bridge-android-image="add">{{ $vocab['create_item'] }}</x-ui.button>
    @endif
@endsection

@section('content')
<div class="ui-scope ui-catalog">
    <x-ui.card :title="$vocab['items']" variant="flush" class="ui-catalog__main">
        <table class="ui-table">
            <thead>
                <tr>
                    <th>{{ ___('Name') }}</th>
                    <th>{{ $vocab['category'] }}</th>
                    <th class="ui-table__num">{{ ___('Price') }}</th>
                    <th>{{ ___('Priced per') }}</th>
                    <th>{{ ___('Turnaround') }}</th>
                    <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td data-primary>{{ $item->getRawOriginal('name') }}</td>
                        <td data-label="{{ $vocab['category'] }}">{{ $item->category->name ?? '—' }}</td>
                        <td class="ui-table__num" data-label="{{ ___('Price') }}"><strong>{{ price_symbol_format($item->price) }}</strong></td>
                        <td data-label="{{ ___('Priced per') }}"><x-ui.tag>{{ $item->pricing_unit === 'kg' ? ___('Kilogram') : ___('Item') }}</x-ui.tag></td>
                        <td data-label="{{ ___('Turnaround') }}">{{ $item->turnaround_hours ? ___(':hours h', ['hours' => $item->turnaround_hours]) : '—' }}</td>
                        <td class="ui-catalog__row-actions">
                            <a href="{{ route('laundry.services.edit', [$store->id, $item->id]) }}" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"><x-ui.icon name="pencil" /></a>
                            <form method="POST" action="{{ route('laundry.services.destroy', [$store->id, $item->id]) }}"
                                  data-confirm="{{ ___('Delete this item?') }}" data-confirm-danger>
                                @csrf @method('DELETE')
                                <button class="ui-icon-btn ui-menu-item__delete" type="submit" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty :title="___('No records found.')" icon="products" /></td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="ui-catalog__pager">{{ $items->links() }}</div>
    </x-ui.card>

    {{-- The storefront groups services by category; a service without one is not listed, so the owner creates them here. --}}
    <div class="ui-catalog__side">
        <x-ui.card :title="$vocab['categories']">
            <form method="POST" action="{{ route('retail.categories.store', $store->id) }}" class="ui-catalog__add">
                @csrf
                <input type="text" name="name" class="ui-input" placeholder="{{ ___('New :label name', ['label' => mb_strtolower($vocab['category'])]) }}" aria-label="{{ ___('New :label name', ['label' => mb_strtolower($vocab['category'])]) }}" required>
                <x-ui.button type="submit" variant="dark" icon="plus">{{ ___('Add') }}</x-ui.button>
            </form>

            <ul class="ui-catalog__cats">
                @forelse($categories->whereNull('parent') as $c)
                    <li>
                        <span>{{ $c->name }}</span>
                        <form method="POST" action="{{ route('retail.categories.destroy', [$store->id, $c->id]) }}" data-confirm="{{ ___('Delete this category?') }}" data-confirm-danger>
                            @csrf @method('DELETE')
                            <button class="ui-icon-btn ui-icon-btn--ghost ui-menu-item__delete" type="submit" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                        </form>
                    </li>
                @empty
                    <li class="ui-catalog__none">{{ ___('No records found.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>
</div>

@if(Route::has('stores.menu.aiGenerate'))
    @include('owner.menu.partials.tools', ['store' => $store])
@endif
@endsection
