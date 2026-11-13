{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: the catalogue. Grid cards (the cafe look) unless the owner chose list or compact, which use the shared card. --}}
@php($__v = $vm['theme']['settings']['layout.card'] ?? 'grid')
<div class="c-catalog" data-section="catalog" data-card="{{ $__v }}">
    <article class="c-state c-state--muted" data-kit-noresults hidden><span class="c-state__icon">{{ $kit->icon('search') }}</span><div class="c-state__body"><h2 class="c-state__title">{{ $kit->s('No search results') }}</h2><p class="c-state__text">{{ $kit->s('Check the spelling or try a shorter word.') }}</p></div></article>
@foreach ($vm['catalog']['categories'] as $__ci => $__cat)
    @continue($__cat['count'] === 0)
    @php($__have = ($paging['shown'][$__cat['id']] ?? null) ?? count($__cat['item_ids']))
    <section class="c-cat" id="cat-{{ $__cat['id'] }}" aria-labelledby="h-{{ $__cat['id'] }}">
        <div class="c-cat__head"><h2 class="c-cat__title" id="h-{{ $__cat['id'] }}">{{ $__cat['name'] }}</h2><span class="c-cat__count">{{ $kit->n('item', $__cat['count']) }}</span></div>
        <ul class="c-cards c-cards--{{ $__v }}" data-kit-cat-list{{ $__have === 0 ? ' hidden' : '' }}>
        @foreach (array_slice($__cat['item_ids'], 0, $__have) as $__k => $__id)
            @continue(! isset($vm['catalog']['items'][$__id]))
            @include($__v === 'grid' ? 'store_templates.brew.components.item-card' : 'store_templates._kit.components.item-card', ['item' => $vm['catalog']['items'][$__id], 'first' => $__ci === 0 && $__k === 0, 'variant' => $__v])
        @endforeach
        </ul>
        @if ($__have < count($__cat['item_ids']))
        @include('store_templates._kit.components.cat-more', ['cat' => $__cat, 'have' => $__have])
        @endif
    </section>
@endforeach
</div>
