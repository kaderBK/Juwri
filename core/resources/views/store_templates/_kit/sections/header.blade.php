{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__s = $vm['store'];
    $__other = \Juwri\Core\Support\ViewHelpers::otherLocale($__s['locales'] ?? [], $__s['locale']);
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__compact = ($vm['theme']['settings']['layout.header'] ?? 'cover') === 'compact';
@endphp
<header class="c-header" data-section="header">
    @if (! empty($__s['cover']) && ! $__compact)
    <div class="c-header__cover"><img class="c-header__img" src="{{ $__s['cover']['src'] }}" alt="" width="1200" height="675" decoding="async" fetchpriority="high"></div>
    @endif
    <div class="c-header__actions">
        @if ($__other)
        <a class="c-pill c-pill--lang" href="{{ $__other['url'] }}" lang="{{ $__other['code'] }}" hreflang="{{ $__other['code'] }}">{{ $kit->icon('globe') }}<span>{{ $__other['name'] }}</span></a>
        @endif
        @unless ($__kiosk)
        <button class="c-iconbtn c-iconbtn--glass c-header__share" type="button" data-kit-share aria-label="{{ $kit->s('Share') }}">{{ $kit->icon('share-2') }}</button>
        @if (! empty($vm['endpoints']['account']))
        <a class="c-pill c-pill--account" href="{{ $vm['endpoints']['account'] }}" aria-label="{{ $kit->s('Sign in') }}">{{ $kit->icon('user') }}<span class="c-pill__label">{{ $kit->s('Sign in') }}</span></a>
        @endif
        @endunless
    </div>
    <div class="c-header__id">@include('store_templates._kit.components.logo', ['cls' => 'c-logo']) @include('store_templates._kit.components.status-pill')<h1 class="c-header__name">{{ $__s['name'] }}</h1></div>
    <div class="c-header__info">
        @if (! empty($__s['tagline']))<p class="c-header__tagline">{{ $kit->flow($__s['tagline']) }}</p>@endif
        @include('store_templates._kit.components.meta-row')
        @if (! $__s['status']['open'] && ! $__kiosk)
        <div class="c-banner c-banner--warning c-header__closed" role="status">{{ $kit->icon('clock') }}<p><strong>{{ $kit->s('Store closed') }}</strong>@if (! empty($vm['context']['schedule_ahead'])) <span>{{ $kit->s('You can still order for later today.') }}</span>@endif</p></div>
        @endif
    </div>
</header>
