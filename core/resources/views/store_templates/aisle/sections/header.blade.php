{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle: the retail header — the store name (h1 on the home page only), the main menu (top categories) and the search field on
     desktop, then the icon buttons: search (phone), wishlist, account, language and the bag with its count. The bag opens the
     bag dialog (a full-height sheet on a phone, a drawer from the inline end on desktop). Reads only $vm. --}}
@php
    $__s = $vm['store'];
    $__home = $vm['endpoints']['home'] ?? '/';
    $__other = \Juwri\Core\Support\ViewHelpers::otherLocale($__s['locales'] ?? [], $__s['locale']);
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__cats = array_slice(\Juwri\Core\Support\ViewHelpers::populatedCategories($vm['catalog']['categories'] ?? []), 0, 7);
    $__active = $listing['category'] ?? null;
    $__h1 = $h1 ?? true;
    $__wish = ! empty($vm['features']['wishlist']) && ! empty($vm['endpoints']['wishlist']);
@endphp
<header class="c-header" data-section="header">
    @if ($__wish)<span hidden data-kit-wish-texts data-saved="{{ $kit->s('Saved to wishlist') }}" data-removed="{{ $kit->s('Removed from wishlist') }}" data-failed="{{ $kit->s('The wishlist could not be updated.') }}"></span>@endif
    <div class="c-header__bar">
        <{{ $__h1 ? 'h1' : 'p' }} class="c-header__brand"><a class="c-header__link" href="{{ $__home }}">{{ $__s['name'] }}</a></{{ $__h1 ? 'h1' : 'p' }}>
        @unless ($__kiosk)
        @if ($__cats)
        <nav class="c-header__nav" aria-label="{{ $kit->s('Main menu') }}"><ul class="c-nav">
            @foreach ($__cats as $__c)<li><a class="c-nav__link" href="{{ $__home }}?category={{ $__c['id'] }}" @if ($__active === $__c['id']) aria-current="page" @endif>{{ $__c['name'] }}</a></li>@endforeach
        </ul></nav>
        @endif
        <form class="c-header__search" id="header-search" role="search" method="get" action="{{ $__home }}">
            <label class="u-sr" for="q">{{ $kit->s('Search products') }}</label>{{ $kit->icon('search') }}
            <input class="c-header__input" id="q" type="search" name="q" value="{{ $listing['q'] ?? '' }}" placeholder="{{ ($vm['theme']['texts']['search_placeholder'] ?? null) ?: $kit->s('Search products') }}" autocomplete="off" enterkeyhint="search">
        </form>
        @endunless
        <div class="c-header__actions">
            @unless ($__kiosk)
            <button class="c-iconbtn c-header__searchbtn" type="button" data-kit-search-toggle aria-controls="header-search" aria-expanded="false" aria-label="{{ $kit->s('Search') }}">{{ $kit->icon('search') }}</button>
            @if ($__other)<a class="c-header__lang" href="{{ $__other['url'] }}" lang="{{ $__other['code'] }}" hreflang="{{ $__other['code'] }}">{{ $__other['name'] }}</a>@endif
            @if ($__wish)<a class="c-iconbtn" href="{{ $vm['endpoints']['wishlist'] }}" aria-label="{{ $kit->s('Wishlist') }}">{{ $kit->icon('heart') }}</a>@endif
            @if (! empty($vm['endpoints']['account']))<a class="c-iconbtn" href="{{ $vm['endpoints']['account'] }}" aria-label="{{ $kit->s('Account') }}">{{ $kit->icon('user') }}</a>@endif
            <button class="c-bagbtn c-bagbtn--page" type="button" data-juwri-open="cart" aria-controls="cart" aria-haspopup="dialog" aria-expanded="false">{{ $kit->icon('shopping-bag') }}<span class="u-sr">{{ $kit->s('Bag') }}</span><span class="c-bagbtn__count" data-juwri-count>0</span></button>
            <button class="c-bagbtn c-bagbtn--drawer" type="button" data-juwri-open="cart" aria-controls="cart" aria-haspopup="dialog" aria-expanded="false">{{ $kit->icon('shopping-bag') }}<span class="c-bagbtn__label">{{ $kit->s('Bag') }} (<bdi data-juwri-count>0</bdi>)</span></button>
            @endunless
        </div>
    </div>
</header>
