{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §4: category tiles (image, name) on a phone, the category list with counts as a sidebar on desktop. Anchors to the
     catalogue sections; the shared script marks the one in view. --}}
@php
    $__cats = \Juwri\Core\Support\ViewHelpers::populatedCategories($vm['catalog']['categories']);
    $__offers = ! empty($vm['features']['offers']) || ! empty($vm['features']['featured']);
    @endphp
<nav class="c-catnav" data-section="category_nav" aria-label="{{ $kit->s('Categories') }}">
    <p class="c-catnav__label" aria-hidden="true">{{ $kit->s('Categories') }}</p>
    <ul class="c-tiles">
        @if ($__offers)<li class="c-tile c-tile--deals"><a class="c-tile__link c-catnav__link" href="#deals"><span class="c-tile__name">{{ $kit->s('Deals') }}</span></a></li>@endif
        @foreach ($__cats as $__i => $__cat)
        @php($__src = \Juwri\Core\Support\ViewHelpers::firstItemImage($__cat['item_ids'], $vm['catalog']['items']))
        <li class="c-tile{{ $__i >= 7 ? ' c-tile--extra' : '' }}"><a class="c-tile__link c-catnav__link" href="{{ ! empty($listing['paged']) ? ($vm['endpoints']['home'] ?? '/').'?category='.$__cat['id'] : '#cat-'.$__cat['id'] }}" @if ($__i === 0 && ! $__offers) aria-current="true" @endif>
            @if ($__src)<img class="c-tile__img" src="{{ $__src }}" alt="" width="72" height="72" decoding="async" loading="lazy">@else<span class="c-tile__img c-tile__img--all" aria-hidden="true">{{ $kit->icon('package') }}</span>@endif
            <span class="c-tile__name">{{ $__cat['name'] }}</span><span class="c-tile__count"><bdi>{{ $__cat['count'] }}</bdi></span></a></li>
        @endforeach
    </ul>
</nav>
