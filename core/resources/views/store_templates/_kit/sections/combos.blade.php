{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php($__ids = $vm['features']['combos'] ?? [])
@if ($__ids)
<section class="c-cat c-combos" id="cat-combos" aria-labelledby="h-combos"><div class="c-cat__head"><h2 class="c-cat__title" id="h-combos">{{ $kit->s('Meal deals') }}</h2></div><ul class="c-cards c-cards--list">
@foreach ($__ids as $__id)
    @continue(! isset($vm['catalog']['items'][$__id]))
    @include('store_templates._kit.components.item-card', ['item' => $vm['catalog']['items'][$__id], 'first' => false, 'variant' => 'list'])
@endforeach
</ul></section>
@endif
