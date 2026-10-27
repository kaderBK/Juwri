{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the store header — brand + search + language, the cover, the identity (logo, name, tagline, rating, open / closed) --}}
@php
    $__s = $vm['store'];
    $__other = \Juwri\Core\Support\ViewHelpers::otherLocale($__s['locales'] ?? [], $__s['locale']);
    $__st = $__s['status'];
    $__accepting = $__st['accepting_bookings'] ?? true;
    $__label = ($__st['paused_reason'] ?? null) ?: ($__st['label'] ?? null) ?: ($__st['open'] ? $kit->s('Open Now') : $kit->s('Closed'));
@endphp
<header class="c-bkhead" data-section="header">
    <div class="c-topnav">
        <a class="c-topnav__brand" href="{{ $vm['endpoints']['home'] }}">@include('store_templates.book.components.logo', ['cls' => 'c-bklogo c-bklogo--sm'])<span class="c-topnav__name">{{ $__s['name'] }}</span></a>
        <form class="c-search c-topnav__search" role="search" data-book-search><label class="u-sr" for="q">{{ $kit->s('Search services') }}</label>{{ $kit->icon('search') }}<input class="c-search__input" id="q" type="search" name="q" placeholder="{{ $kit->s('Search services') }}" autocomplete="off" enterkeyhint="search"></form>
        @if ($__other)<a class="c-topnav__lang" href="{{ $__other['url'] }}" lang="{{ $__other['code'] }}" hreflang="{{ $__other['code'] }}">{{ $__other['name'] }}</a>@endif
    </div>
    <div class="c-bkhead__stage">
        <div class="c-cover">
            @if (! empty($__s['cover']))<img class="c-cover__img" src="{{ $__s['cover']['src'] }}" alt="" width="1440" height="240" decoding="async" fetchpriority="high">@endif
            <div class="c-cover__actions">
                @if ($__other)<a class="c-pill c-pill--lang" href="{{ $__other['url'] }}" lang="{{ $__other['code'] }}" hreflang="{{ $__other['code'] }}">{{ $kit->icon('globe') }}<span>{{ $__other['name'] }}</span></a>@endif
                @if (! empty($vm['endpoints']['account']))<a class="c-iconbtn c-iconbtn--glass" href="{{ $vm['endpoints']['account'] }}" aria-label="{{ $kit->s('Sign in') }}">{{ $kit->icon('user') }}</a>@endif
            </div>
        </div>
        <div class="c-bkid">
            @include('store_templates.book.components.logo', ['cls' => 'c-bklogo'])
            <div class="c-bkid__text">
                <h1 class="c-bkid__name">{{ $__s['name'] }}</h1>
                @if (! empty($__s['tagline']))<p class="c-bkid__tagline">{{ $kit->flow($__s['tagline']) }}</p>@endif
                <ul class="c-bkmeta">
                    @if (! empty($__s['rating']))<li class="c-bkmeta__item c-bkmeta__item--rating">{{ $kit->icon('star') }}<span>{{ $__s['rating']['avg'] }} ({{ number_format($__s['rating']['count']) }})</span></li>@endif
                    <li class="c-bkmeta__item"><span class="c-bkstatus c-bkstatus--{{ $__st['open'] ? 'open' : 'closed' }}"><span class="c-bkstatus__dot" aria-hidden="true"></span><span>{{ $kit->flow($__label) }}</span></span></li>
                </ul>
                @if (! $__accepting)
                <p class="c-bkclosed" role="status">{{ $kit->icon('clock') }}<span><strong>{{ $kit->s('Not taking bookings now') }}</strong> {{ $kit->s('Please come back during opening hours.') }}</span></p>
                @elseif (! $__st['open'])
                <p class="c-bkclosed" role="status">{{ $kit->icon('clock') }}<span><strong>{{ $kit->s('Closed now') }}</strong> {{ $kit->s('You can still book a time for later.') }}</span></p>
                @endif
            </div>
        </div>
    </div>
</header>
