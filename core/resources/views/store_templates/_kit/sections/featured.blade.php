{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__ids = $vm['features']['featured'] ?? [];
    $__title = ($vm['theme']['texts']['featured_title'] ?? null) ?: $kit->s("Today's specials");
@endphp
@if ($__ids)
<section class="c-featured" data-section="featured" aria-labelledby="featured-title">
    <div class="c-sectionhead"><h2 class="c-sectionhead__title" id="featured-title">{{ $__title }}</h2><span class="c-sectionhead__count">{{ $kit->n('dish', count($__ids)) }}</span></div>
    <ul class="c-rail c-featured__rail">
    @foreach ($__ids as $__id)
        @php($__it = $vm['catalog']['items'][$__id] ?? null)
        @continue(! $__it)
        <li class="c-fcard{{ $__it['sold_out'] ? ' is-soldout' : '' }}">
            @if (! empty($__it['image']))<div class="c-fcard__media"><img class="c-fcard__img" src="{{ $__it['image']['src'] }}" alt="" width="232" height="132" decoding="async" loading="lazy"></div>@endif
            <div class="c-fcard__body"><h3 class="c-fcard__name"><a class="c-card__link" href="{{ $__it['url'] }}" @if (empty($__it['sold_out'])) data-kit-open-item="{{ $__it['id'] }}" @endif>{{ $__it['name'] }}</a></h3>
            <p class="c-fcard__note">{{ $__it['short'] ?? '' }}</p><p class="c-fcard__price">@include('store_templates._kit.components.price', ['item' => $__it])</p></div>
        </li>
    @endforeach
    </ul>
</section>
@endif
