{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §5: "Deals" — the store's offer as a banner (desktop) with its code chip, and the featured products as a rail. The offer
     code applies to the basket (shared script). Reads only $vm. --}}
@php
    $__offers = $vm['features']['offers'] ?? [];
    $__ids = $vm['features']['featured'] ?? [];
    $__title = ($vm['theme']['texts']['featured_title'] ?? null) ?: $kit->s('Deals');
    $__banner = $__offers[0] ?? null;
@endphp
@if ($__offers || $__ids)
<section class="c-deals" id="deals" data-section="offers" aria-labelledby="deals-title">
    <div class="c-sectionhead"><h2 class="c-sectionhead__title" id="deals-title">{{ $__title }}</h2></div>
    @if ($__banner)
    <div class="c-dealbanner"><div class="c-dealbanner__text"><p class="c-dealbanner__title">{{ $kit->flow($__banner['title']) }}</p>
        @if (! empty($__banner['code']))<button class="c-dealbanner__chip" type="button" data-kit-apply-offer="{{ $__banner['code'] }}">{{ $kit->icon('tag') }}<bdi dir="ltr" class="c-code">{{ $__banner['code'] }}</bdi></button>@endif</div></div>
    @endif
    @if ($__ids)
    <div class="c-deals__rail"><ul class="c-rail c-rail--deals">
    @foreach ($__ids as $__id)
        @php($__it = $vm['catalog']['items'][$__id] ?? null)
        @continue(! $__it)
        @include('store_templates.basket.components.item-card', ['item' => $__it, 'rail' => true])
    @endforeach
    </ul></div>
    @endif
</section>
@endif
