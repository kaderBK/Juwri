{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: the compact header (round logo, name, status line, search, language + account). `layout.header = cover` keeps the
     owner's choice and draws the shared cover header instead. --}}
@if (($vm['theme']['settings']['layout.header'] ?? 'compact') !== 'compact')
@include('store_templates._kit.sections.header')
@else
@php
    $__s = $vm['store'];
    $__other = \Juwri\Core\Support\ViewHelpers::otherLocale($__s['locales'] ?? [], $__s['locale']);
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    // the header carries the search unless the owner turned the separate Search section on (one search field, one #q)
    $__search = ! $__kiosk && ($vm['theme']['settings']['search.enabled'] ?? true) && ! in_array('search', $vm['theme']['sections'], true);
@endphp
@php($__inner = ! empty($inner))
<header class="c-header{{ $__inner ? ' c-header--inner' : '' }}" data-section="header">
    @include('store_templates._kit.components.logo', ['cls' => 'c-logo'])
    <div class="c-header__id">@if ($__inner)<p class="c-header__name" title="{{ $__s['name'] }}">{{ $__s['name'] }}</p>@else<h1 class="c-header__name" title="{{ $__s['name'] }}">{{ $__s['name'] }}</h1>@endif@include('store_templates._kit.components.status-pill')</div>
    @if ($__search)
    @php($__ph = ($vm['theme']['texts']['search_placeholder'] ?? null) ?: $kit->s('Search the menu'))
    <form class="c-search c-header__search" role="search" method="get" action="{{ $vm['endpoints']['home'] ?? '/' }}" data-kit-search><label class="u-sr" for="q">{{ $kit->s('Search the menu') }}</label>{{ $kit->icon('search') }}<input class="c-search__input" id="q" type="search" name="q" placeholder="{{ $__ph }}" autocomplete="off" enterkeyhint="search"></form>
    @endif
    <div class="c-header__actions">
        @if ($__other)<a class="c-pill c-pill--lang" href="{{ $__other['url'] }}" lang="{{ $__other['code'] }}" hreflang="{{ $__other['code'] }}">{{ $kit->icon('globe') }}<span class="c-pill__label">{{ $__other['name'] }}</span></a>@endif
        @if (! $__kiosk && ! empty($vm['endpoints']['account']))<a class="c-pill c-pill--account" href="{{ $vm['endpoints']['account'] }}">{{ $kit->icon('user') }}<span class="c-pill__label">{{ $kit->s('Sign in') }}</span></a>@endif
    </div>
    @if (! $__s['status']['open'] && ! $__kiosk)
    <div class="c-banner c-banner--warning c-header__closed" role="status">{{ $kit->icon('clock') }}<p><strong>{{ $kit->s('Store closed') }}</strong>@if (! empty($vm['context']['schedule_ahead'])) <span>{{ $kit->s('You can still order for later today.') }}</span>@endif</p></div>
    @endif
</header>
@endif
