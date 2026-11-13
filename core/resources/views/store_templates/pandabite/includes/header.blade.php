{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<header class="pb-header">
    <div class="pb-header__top">
        <div class="pb-header__brand">
            <div class="pb-header__logo">
                @if($store->main_image && $store->main_image !== 'default.png')
                    @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="{{ $store->title }}" data-csp-error="hide-show" data-csp-show=".pb-header__logo-fallback">@endif
                    <span class="pb-header__logo-fallback" style="display:none">🍽️</span>
                @else
                    <span class="pb-header__logo-fallback" style="display:flex">🍽️</span>
                @endif
            </div>
            <div>
                <div class="pb-header__name">{{ $store->title }}</div>
                <div class="pb-header__meta">📍 {{ $store->address ?: ___('Welcome') }} @if($table)· {{ ___('Table') }} {{ $table }}@endif</div>
            </div>
        </div>
        <button type="button" class="pb-header__search" aria-label="{{ ___('Search') }}">🔍</button>
    </div>
    <div class="pb-search-pill">
        <span>🔍</span>
        <span>{{ ___('Search dishes, ingredients…') }}</span>
    </div>
</header>
