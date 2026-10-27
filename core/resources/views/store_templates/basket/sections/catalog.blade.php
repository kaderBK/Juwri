{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §6: the catalogue by category. Every card shows the price, the pack size or the price per kg, and Add (or a quantity stepper
     once it is in the basket). "Sort by" reorders each category in the page. Reads only $vm. --}}
@php
    $__multi = count(\Juwri\Core\Support\ViewHelpers::populatedCategories($vm['catalog']['categories'])) > 0;
    $__l = $listing ?? [];
    $__paged = ! empty($__l['paged']);
    $__total = (int) ($vm['catalog']['total'] ?? count($vm['catalog']['items']));
    $__shown = (int) ($__l['offset'] ?? 0) + count($vm['catalog']['items']);
    $__next = $vm['catalog']['next_page_url'] ?? null;
    $__sorts = ['position' => $kit->s('Popular'), 'price_asc' => $kit->s('Price: low to high'), 'price_desc' => $kit->s('Price: high to low'), 'newest' => $kit->s('Newest')];
@endphp
<div class="c-catalog" data-section="catalog">
    <article class="c-state c-state--muted" data-kit-noresults hidden><span class="c-state__icon">{{ $kit->icon('search') }}</span><div class="c-state__body"><h2 class="c-state__title">{{ $kit->s('No products found') }}</h2><p class="c-state__text">{{ $kit->s('Try a shorter word or another spelling.') }}</p></div></article>
@if ($__paged)
    @php($__cur = \Juwri\Core\Support\ViewHelpers::categoryByStrictId($vm['catalog']['categories'], $__l['category'] ?? null))
    <section class="c-cat" id="cat-all" aria-labelledby="h-all">
        <div class="c-cat__head"><h2 class="c-cat__title" id="h-all">{{ ! empty($__l['q']) ? $kit->s('Results for :query', ['query' => $__l['q']]) : ($__cur['name'] ?? $kit->s('All products')) }}</h2><span class="c-cat__count">{{ $kit->n('item', $__total) }}</span>
            <div class="c-sort"><label class="c-sort__label" for="sort">{{ $kit->s('Sort by') }}</label><span class="c-sort__wrap"><select class="c-sort__select" id="sort" name="sort" data-bk-sort-server data-url="{{ $vm['endpoints']['home'] ?? '/' }}" data-query="{{ $__l['query'] ?? '' }}">@foreach ($__sorts as $__v => $__t)<option value="{{ $__v }}" @selected(($__l['sort'] ?? 'position') === $__v)>{{ $__t }}</option>@endforeach</select>{{ $kit->icon('chevron-down') }}</span></div>
        </div>
        <ul class="c-pgrid" data-bk-grid>
        @foreach (array_keys($vm['catalog']['items']) as $__k => $__id)
            @include('store_templates.basket.components.item-card', ['item' => $vm['catalog']['items'][$__id], 'first' => $__k < 3, 'pos' => $__k])
        @endforeach
        </ul>
    </section>
@else
@foreach ($vm['catalog']['categories'] as $__ci => $__cat)
    @continue($__cat['count'] === 0)
    @php($__have = ($paging['shown'][$__cat['id']] ?? null) ?? count($__cat['item_ids']))
    <section class="c-cat" id="cat-{{ $__cat['id'] }}" aria-labelledby="h-{{ $__cat['id'] }}">
        <div class="c-cat__head"><h2 class="c-cat__title" id="h-{{ $__cat['id'] }}">{{ $__cat['name'] }}</h2><span class="c-cat__count">{{ $kit->n('item', $__cat['count']) }}</span>
            @if ($__ci === 0)
            <div class="c-sort"><label class="c-sort__label" for="sort">{{ $kit->s('Sort by') }}</label><span class="c-sort__wrap"><select class="c-sort__select" id="sort" name="sort" data-bk-sort><option value="popular" selected>{{ $kit->s('Popular') }}</option><option value="price_asc">{{ $kit->s('Price: low to high') }}</option><option value="price_desc">{{ $kit->s('Price: high to low') }}</option><option value="name">{{ $kit->s('Name A–Z') }}</option></select>{{ $kit->icon('chevron-down') }}</span></div>
            @endif
        </div>
        <ul class="c-pgrid" data-bk-grid data-kit-cat-list{{ $__have === 0 ? ' hidden' : '' }}>
        @foreach (array_slice($__cat['item_ids'], 0, $__have) as $__k => $__id)
            @continue(! isset($vm['catalog']['items'][$__id]))
            @include('store_templates.basket.components.item-card', ['item' => $vm['catalog']['items'][$__id], 'first' => $__ci === 0 && $__k < 3, 'pos' => $__k])
        @endforeach
        </ul>
        @if ($__have < count($__cat['item_ids']))
        @include('store_templates._kit.components.cat-more', ['cat' => $__cat, 'have' => $__have])
        @endif
    </section>
@endforeach
@endif
@if ($__paged && $__shown === 0)
    <article class="c-state c-state--muted" role="status"><span class="c-state__icon">{{ $kit->icon('search') }}</span><div class="c-state__body"><h2 class="c-state__title">{{ $kit->s('No products found') }}</h2></div></article>
@endif
@if ($__paged && $__next)
    <div class="c-more" data-bk-more><p class="c-more__text" role="status">{{ $kit->s('Showing :shown of :total', ['shown' => (string) $__shown, 'total' => (string) $__total]) }}</p><progress class="c-more__bar" max="{{ $__total }}" value="{{ $__shown }}" aria-hidden="true"></progress><a class="c-btn c-btn--secondary c-more__btn" href="{{ $__next }}" data-bk-load-more>{{ $kit->s('Load more') }}</a></div>
@endif
</div>
