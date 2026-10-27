{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P4-16: basket's next cards of one category (see _kit/components/cat-page). --}}
@php
    $__cat = \Juwri\Core\Support\ViewHelpers::categoryById($vm['catalog']['categories'], $category) ?? ['item_ids' => []];
    $__ids = array_slice($__cat['item_ids'], $offset, $limit);
@endphp
@foreach ($__ids as $__k => $__id)
@continue(! isset($vm['catalog']['items'][$__id]))
@include('store_templates.basket.components.item-card', ['item' => $vm['catalog']['items'][$__id], 'first' => false, 'pos' => $offset + $__k])
@endforeach
<li class="c-cat__next" hidden data-next-offset="{{ $offset + count($__ids) < count($__cat['item_ids']) ? $offset + count($__ids) : '' }}" data-loaded="{{ $offset + count($__ids) }}"></li>
