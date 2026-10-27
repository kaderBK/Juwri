{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §5-6: the catalogue — filters sidebar (desktop; a sheet on a phone), the toolbar (title, result count in a polite live
     region, Filters button, Sort) and the product grid. The server lists it (search, facets, sort, cursor): this view only draws what
     $vm['catalog'] holds. "Load more" is a link to the next page (the script appends it in place). --}}
@php
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__l = $listing ?? [];
    $__home = $vm['endpoints']['home'] ?? '/';
    $__ids = array_keys($vm['catalog']['items']);
    $__total = (int) ($vm['catalog']['total'] ?? count($__ids));
    $__offset = (int) ($__l['offset'] ?? 0);
    $__shown = $__offset + count($__ids);
    $__next = $vm['catalog']['next_page_url'] ?? null;
    $__filters = (int) ($__l['filters'] ?? 0);
    $__cat = \Juwri\Core\Support\ViewHelpers::categoryByStrictId($vm['catalog']['categories'] ?? [], $__l['category'] ?? null);
    $__title = ! empty($__l['q']) ? $kit->s('Results for :query', ['query' => $__l['q']]) : ($__cat['name'] ?? $kit->s('All products'));
    $__sorts = ['position' => $kit->s('Featured'), 'newest' => $kit->s('Newest'), 'price_asc' => $kit->s('Price: Low to high'), 'price_desc' => $kit->s('Price: High to low')];
    $__sort = $__l['sort'] ?? 'position';
@endphp
@if ($__kiosk)
{{-- P3-06 kiosk: the products by category in the kiosk grid (no filters, no sort); a product opens as a sheet --}}
<div class="c-catalog" data-section="catalog" data-card="grid">
@foreach ($vm['catalog']['categories'] as $__ci => $__cat)
    @continue($__cat['count'] === 0)
    <section class="c-cat" id="cat-{{ $__cat['id'] }}" aria-labelledby="h-{{ $__cat['id'] }}">
        <div class="c-cat__head"><h2 class="c-cat__title" id="h-{{ $__cat['id'] }}">{{ $__cat['name'] }}</h2></div>
        <ul class="c-pgrid">
        @foreach ($__cat['item_ids'] as $__k => $__id)
            @continue(! isset($vm['catalog']['items'][$__id]))
            @include('store_templates.aisle.components.product-card', ['item' => $vm['catalog']['items'][$__id], 'first' => $__ci === 0 && $__k < 2])
        @endforeach
        </ul>
    </section>
@endforeach
</div>
@else
<div class="c-shop" id="shop" data-section="catalog">
    <aside class="c-shop__filters" aria-labelledby="filters-title-a">@include('store_templates.aisle.components.filters', ['idp' => 'a', 'sheet' => false])</aside>
    <section class="c-shop__main" aria-labelledby="shop-title">
        <div class="c-toolbar">
            <div class="c-toolbar__title"><h2 class="c-toolbar__h" id="shop-title">{{ $__title }}</h2><p class="c-toolbar__count" role="status" aria-live="polite" data-kit-count>{{ $kit->join([$kit->n('product', $__total), $__filters > 0 ? $kit->n('filter on', $__filters) : '']) }}</p></div>
            <button class="c-btn c-btn--secondary c-toolbar__filters" type="button" data-juwri-open="filters" aria-controls="filters" aria-haspopup="dialog" aria-expanded="false">{{ $kit->icon('sliders') }}<span>{{ $kit->s('Filters') }}</span></button>
            <div class="c-sort"><label class="c-sort__label" for="sort">{{ $kit->s('Sort by') }}</label><span class="c-select-wrap"><select class="c-select" id="sort" name="sort" form="filters-form" data-kit-sort>@foreach ($__sorts as $__v => $__t)<option value="{{ $__v }}" @selected($__sort === $__v)>{{ $__t }}</option>@endforeach</select>{{ $kit->icon('chevron-down') }}</span></div>
        </div>
        @if ($__ids === [])
        <article class="c-state c-state--muted" role="status"><span class="c-state__icon">{{ $kit->icon('search-x') }}</span><div class="c-state__body"><h3 class="c-state__title">{{ $kit->s('No products found') }}</h3><p class="c-state__text">{{ $kit->s('Try fewer filters or a different search.') }}</p><div class="c-state__actions"><a class="c-btn c-btn--secondary" href="{{ $__home }}">{{ $kit->s('Clear all') }}</a></div></div></article>
        @else
        <ul class="c-pgrid" data-kit-grid data-card="{{ $vm['theme']['settings']['layout.card'] ?? 'grid' }}">
        @foreach ($__ids as $__k => $__id)
            @include('store_templates.aisle.components.product-card', ['item' => $vm['catalog']['items'][$__id], 'first' => $__k < 2])
        @endforeach
        </ul>
        @if ($__next)
        <div class="c-more" data-kit-more><p class="c-more__text">{{ $kit->s('Showing :shown of :total', ['shown' => (string) $__shown, 'total' => (string) $__total]) }}</p><progress class="c-more__bar" max="{{ $__total }}" value="{{ $__shown }}" aria-hidden="true"></progress><a class="c-btn c-btn--secondary c-more__btn" href="{{ $__next }}" data-kit-load-more>{{ $kit->s('Load more') }}</a></div>
        @endif
        @endif
    </section>
</div>
@endif
