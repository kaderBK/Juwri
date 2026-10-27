{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', $vocab['catalog'].' - '.$store->title)
@section('page_title', $vocab['items'])
@section('page_sub', $store->title)

@section('page_actions')
    @include('owner.catalog.partials.catalog-more', ['store' => $store, 'extra' => array_merge(Route::has('retail.brands.index') ? [[route('retail.brands.index', $store->id), 'products', ___('Brands')]] : [], [
        [route('stores.retail.sizeGuides', ['store_id' => $store->id]), 'list', ___('Size guides')],
        [route('stores.retail.shipping', ['store_id' => $store->id]), 'delivery', ___('Shipping')],
        [route('stores.retail.returns', ['store_id' => $store->id]), 'refresh', ___('Returns')],
    ]), 'export' => Route::has('retail.products.exportCsv') ? route('retail.products.exportCsv', $store->id) : null])
    @if(Route::has('retail.products.create'))
        <x-ui.button :href="route('retail.products.create', $store->id)" icon="plus" data-controller="bridge--button" :data-bridge-title="$vocab['create_item']" data-bridge-ios-image="plus" data-bridge-android-image="add">{{ $vocab['create_item'] }}</x-ui.button>
    @endif
@endsection

@section('content')
<div class="ui-scope ui-catalog">
    <x-ui.card :title="$vocab['items']" variant="flush" class="ui-catalog__main">
        <x-slot:actions>
            <form method="GET" class="ui-catalog__filters">
                <label class="ui-order__status">
                    <span class="ui-visually-hidden">{{ $vocab['category'] }}</span>
                    <select name="category_id" data-ui-autosubmit>
                        <option value="">{{ ___('All :label', ['label' => mb_strtolower($vocab['categories'])]) }}</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" @selected($filterCategoryId == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <x-ui.icon name="chevron-down" size="16" />
                </label>
                <label class="ui-order__status">
                    <span class="ui-visually-hidden">{{ ___('Brand') }}</span>
                    <select name="brand_id" data-ui-autosubmit>
                        <option value="">{{ ___('All brands') }}</option>
                        @foreach($brands as $b)
                            <option value="{{ $b->id }}" @selected($filterBrandId == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <x-ui.icon name="chevron-down" size="16" />
                </label>
            </form>
        </x-slot:actions>

        <table class="ui-table">
            <thead>
                <tr>
                    <th>{{ ___('Name') }}</th>
                    <th>{{ ___('Brand') }}</th>
                    <th>{{ $vocab['category'] }}</th>
                    <th class="ui-table__num">{{ ___('Price') }}</th>
                    <th class="ui-table__num">{{ ___('Variants') }}</th>
                    <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td data-primary>{{ $item->getRawOriginal('name') }}</td>
                        <td data-label="{{ ___('Brand') }}">{{ $item->brand->name ?? '—' }}</td>
                        <td data-label="{{ $vocab['category'] }}">{{ $item->category->name ?? '—' }}</td>
                        <td class="ui-table__num" data-label="{{ ___('Price') }}"><strong>{{ price_symbol_format($item->price) }}</strong></td>
                        <td class="ui-table__num" data-label="{{ ___('Variants') }}">{{ $item->variants->count() }}</td>
                        <td class="ui-catalog__row-actions">
                            <a href="{{ route('stores.retail.variants', ['menu' => $item->id, 'store_id' => $store->id]) }}" class="ui-icon-btn" aria-label="{{ ___('Variants') }}" title="{{ ___('Variants') }}"><x-ui.icon name="grip" /></a>
                            <a href="{{ route('retail.products.edit', [$store->id, $item->id]) }}" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"><x-ui.icon name="pencil" /></a>
                            <form method="POST" action="{{ route('retail.products.destroy', [$store->id, $item->id]) }}" data-confirm="{{ ___('Delete this item?') }}" data-confirm-danger>
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
                    @foreach($categories->where('parent', $c->id) as $sub)
                        <li data-sub>
                            <span>{{ $sub->name }}</span>
                            <form method="POST" action="{{ route('retail.categories.destroy', [$store->id, $sub->id]) }}" data-confirm="{{ ___('Delete this category?') }}" data-confirm-danger>
                                @csrf @method('DELETE')
                                <button class="ui-icon-btn ui-icon-btn--ghost ui-menu-item__delete" type="submit" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                            </form>
                        </li>
                    @endforeach
                @empty
                    <li class="ui-catalog__none">{{ ___('No records found.') }}</li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card :title="___('Import CSV')">
            <form method="POST" action="{{ route('retail.products.importCsv', $store->id) }}" enctype="multipart/form-data" class="ui-catalog__import">
                @csrf
                <input type="file" name="csv_file" accept=".csv,.txt" class="ui-input" aria-label="{{ ___('Import CSV') }}" required>
                <x-ui.button type="submit" variant="secondary" icon="upload">{{ ___('Import CSV') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>

@if(Route::has('stores.menu.aiGenerate'))
    @include('owner.menu.partials.tools', ['store' => $store])
@endif
@endsection
