{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle: "New in" — a rail of product cards (the store's popular / daily-special picks) above the catalogue. --}}
@php
    $__ids = $vm['features']['featured'] ?? [];
    $__title = ($vm['theme']['texts']['featured_title'] ?? null) ?: $kit->s('New in');
@endphp
@if ($__ids && empty($listing['filtered']))
<section class="c-featured c-rail-block" data-section="featured" aria-labelledby="featured-title">
    <div class="c-sectionhead"><h2 class="c-sectionhead__title" id="featured-title">{{ $__title }}</h2></div>
    <ul class="c-related__rail">
    @foreach ($__ids as $__id)
        @continue(! isset($vm['catalog']['items'][$__id]))
        @include('store_templates.aisle.components.product-card', ['item' => $vm['catalog']['items'][$__id], 'first' => false])
    @endforeach
    </ul>
</section>
@endif
