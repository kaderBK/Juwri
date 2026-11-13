{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: the grocery header — the store (home link) and what the order is (Delivery / Pickup) on one caption line, where it comes
     from / goes to, the ready-time chip, then on desktop the search field and the language / account buttons. A kiosk gets the
     big brand bar. Reads only $vm. --}}
@php
    $__s = $vm['store'];
    $__ctx = $vm['context'];
    $__home = $vm['endpoints']['home'] ?? '/';
    $__other = \Juwri\Core\Support\ViewHelpers::otherLocale($__s['locales'] ?? [], $__s['locale']);
    $__kiosk = ! empty($__ctx['kiosk']['on']);
    $__sel = $__ctx['selected_order_type'] ?? '';
    $__types = collect($__ctx['order_types'] ?? []);
    $__mode = $__types->firstWhere('key', $__sel)['label'] ?? null;
    $__delivery = $__sel === 'delivery';
    $__where = $__delivery ? ($__ctx['delivery_address']['label'] ?? null) : null;
    $__where ??= $__s['contact']['address'] ?? ($vm['branch']['current']['address'] ?? null);
    $__prep = $__ctx['prep_time_min'] ?? null;
    $__search = ! $__kiosk && ($vm['theme']['settings']['search.enabled'] ?? true);
    $__ph = ($vm['theme']['texts']['search_placeholder'] ?? null) ?: $kit->s('Search products');
    $__h1 = $h1 ?? true;
@endphp
@if ($__kiosk)
<header class="c-header c-header--kiosk" data-section="header">
    <div class="c-header__bar">
        <div class="c-kbrand">@include('store_templates._kit.components.logo', ['cls' => 'c-logo'])<h1 class="c-kbrand__name">{{ $__s['name'] }}</h1></div>
        @if ($__other)<a class="c-pill c-pill--lang" href="{{ $__other['url'] }}" lang="{{ $__other['code'] }}" hreflang="{{ $__other['code'] }}">{{ $kit->icon('globe') }}<span>{{ $__other['name'] }}</span></a>@endif
    </div>
</header>
@else
<header class="c-header" data-section="header">
    <div class="c-header__bar">
        <div class="c-header__where">
            <div class="c-header__caption"><{{ $__h1 ? 'h1' : 'p' }} class="c-header__name"><a class="c-header__home" href="{{ $__home }}">{{ $kit->icon('shopping-basket') }}<span>{{ $__s['name'] }}</span></a></{{ $__h1 ? 'h1' : 'p' }}>@if ($__mode)<span class="c-header__mode">· {{ $__mode }}</span>@endif</div>
            @if ($__where)
            <p class="c-addr"><span class="c-addr__cap">{{ $__delivery ? $kit->s('Delivering from') : $kit->s('Pick up at') }}</span><span class="c-addr__row">{{ $kit->icon('map-pin') }}<span class="c-addr__val">{{ $__where }}</span></span></p>
            @endif
        </div>
        @if ($__prep)<span class="c-eta">{{ $kit->icon('zap') }}<span class="c-eta__short">{{ $kit->s(':minutes min', ['minutes' => $__prep]) }}</span><span class="c-eta__long">{{ $kit->s('Ready in about :minutes min', ['minutes' => $__prep]) }}</span></span>@endif
        @if ($__search)
        <form class="c-search c-search--header" role="search" method="get" action="{{ $__home }}" @unless (! empty($listing['paged'])) data-kit-search @endunless><label class="u-sr" for="q-header">{{ $kit->s('Search products') }}</label>{{ $kit->icon('search') }}<input class="c-search__input" id="q-header" type="search" name="q" @if (! empty($listing['q'])) value="{{ $listing['q'] }}" @endif placeholder="{{ $__ph }}" autocomplete="off" enterkeyhint="search"></form>
        @endif
        @if ($__other)<a class="c-hbtn c-hbtn--lang" href="{{ $__other['url'] }}" lang="{{ $__other['code'] }}" hreflang="{{ $__other['code'] }}">{{ $kit->icon('globe') }}<span class="c-hbtn__label">{{ $__other['name'] }}</span></a>@endif
        @if (! empty($vm['endpoints']['account']))<a class="c-hbtn" href="{{ $vm['endpoints']['account'] }}">{{ $kit->icon('user') }}<span class="c-hbtn__label">{{ $kit->s('Sign in') }}</span></a>@endif
    </div>
    @if (! $__s['status']['open'])
    <div class="c-banner c-banner--warning c-header__closed" role="status">{{ $kit->icon('clock') }}<p><strong>{{ $kit->s('Store closed') }}</strong>@if (! empty($__ctx['schedule_ahead'])) <span>{{ $kit->s('You can still order for later today.') }}</span>@endif</p></div>
    @endif
</header>
@endif
