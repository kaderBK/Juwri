{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle: the next page of product cards ("Load more"); the theme script appends them and moves focus to the first. --}}
@php($__ids = array_keys($vm['catalog']['items']))
@foreach ($__ids as $__k => $__id)
@include('store_templates.aisle.components.product-card', ['item' => $vm['catalog']['items'][$__id], 'first' => false])
@endforeach
<li class="c-pgrid__next" hidden data-next-url="{{ $vm['catalog']['next_page_url'] ?? '' }}" data-shown="{{ count($__ids) }}"></li>
