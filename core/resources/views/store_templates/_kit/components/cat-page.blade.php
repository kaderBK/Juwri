{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P4-16: the next cards of one category (kit.js appends them and moves focus to the first). `fragment=cat&category=<id>&offset=<n>`.
     The marker <li> carries the next offset ('' when the category is complete). --}}
@php
    $__cat = \Juwri\Core\Support\ViewHelpers::categoryById($vm['catalog']['categories'], $category) ?? ['item_ids' => []];
    $__ids = array_slice($__cat['item_ids'], $offset, $limit);
    $__v = $vm['theme']['settings']['layout.card'] ?? 'list';
@endphp
@foreach ($__ids as $__k => $__id)
@continue(! isset($vm['catalog']['items'][$__id]))
@include('store_templates._kit.components.item-card', ['item' => $vm['catalog']['items'][$__id], 'first' => false, 'variant' => $__v])
@endforeach
<li class="c-cat__next" hidden data-next-offset="{{ $offset + count($__ids) < count($__cat['item_ids']) ? $offset + count($__ids) : '' }}" data-loaded="{{ $offset + count($__ids) }}"></li>
