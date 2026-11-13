{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<nav class="pb-bottom-nav" id="pb-bottom-nav" aria-label="{{ ___('Menu navigation') }}">
    <button type="button" class="pb-bottom-nav__item active" data-tab="menu" aria-label="{{ ___('Menu') }}">
        <span class="pb-bottom-nav__icon">🍴</span>
        <span class="pb-bottom-nav__label">{{ ___('Menu') }}</span>
    </button>
    <button type="button" class="pb-bottom-nav__item" data-tab="search" aria-label="{{ ___('Search') }}">
        <span class="pb-bottom-nav__icon">🔍</span>
        <span class="pb-bottom-nav__label">{{ ___('Search') }}</span>
    </button>
    <button type="button" class="pb-bottom-nav__item" data-tab="cart" aria-label="{{ ___('Cart') }}">
        <span class="pb-bottom-nav__icon">🛒</span>
        <span class="pb-bottom-nav__label">{{ ___('Cart') }}</span>
        <span class="pb-bottom-nav__badge" id="pb-nav-badge" style="display:none;">0</span>
    </button>
    <button type="button" class="pb-bottom-nav__item" data-tab="account" aria-label="{{ ___('Account') }}">
        <span class="pb-bottom-nav__icon">👤</span>
        <span class="pb-bottom-nav__label">{{ ___('Account') }}</span>
    </button>
</nav>
