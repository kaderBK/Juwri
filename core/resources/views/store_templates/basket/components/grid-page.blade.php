{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: the next page of product cards ("Load more"); the theme script appends them to the category section they belong to
     (or the first one) and moves focus to the first new card. --}}
@php($__ids = array_keys($vm['catalog']['items']))
@foreach ($__ids as $__k => $__id)
@include('store_templates.basket.components.item-card', ['item' => $vm['catalog']['items'][$__id], 'first' => false, 'pos' => $__k])
@endforeach
<li class="c-pgrid__next" hidden data-next-url="{{ $vm['catalog']['next_page_url'] ?? '' }}" data-shown="{{ count($__ids) }}"></li>
