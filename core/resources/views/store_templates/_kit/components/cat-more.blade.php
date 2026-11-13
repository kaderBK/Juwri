{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P4-16: a category of a very large catalogue that shows only part of its cards. The button loads the next cards in place (kit.js
     [data-kit-cat-load]); without JavaScript the link reloads the page with this category drawn in full. --}}
@php
    $__total = count($cat['item_ids']);
    $__href = '?'.http_build_query(array_merge($paging['query'] ?? [], ['category' => $cat['id']])).'#cat-'.$cat['id'];
@endphp
<div class="c-more" data-kit-cat-more data-category="{{ $cat['id'] }}" data-offset="{{ $have }}" data-total="{{ $__total }}">
    <p class="c-more__text" data-kit-cat-status role="status">{{ $kit->s('Showing :shown of :total', ['shown' => (string) $have, 'total' => (string) $__total]) }}</p>
    <progress class="c-more__bar" max="{{ $__total }}" value="{{ $have }}" aria-hidden="true"></progress>
    <a class="c-btn c-btn--secondary c-more__btn" href="{{ $__href }}" data-kit-cat-load>{{ $kit->s('Load more') }}</a>
</div>
