{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<nav class="lm-tabbar" id="lmTabbar" aria-label="{{ ___('Main') }}">
    <div class="lm-tabbar__inner">
        <button type="button" class="lm-tab" data-nav="home" aria-current="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 001 1h10a1 1 0 001-1v-9"/></svg>
            <span>{{ ___('Home') }}</span>
        </button>
        <button type="button" class="lm-tab" data-nav="scan" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V6a2 2 0 012-2h2M20 8V6a2 2 0 00-2-2h-2M4 16v2a2 2 0 002 2h2M20 16v2a2 2 0 01-2 2h-2"/><circle cx="12" cy="12" r="3"/></svg>
            <span>{{ ___('Scan') }}</span>
        </button>
        <button type="button" class="lm-fab" data-nav="basket" aria-label="{{ $vocab['orders'] ?? ___('Basket') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
            <i class="lm-badge" id="lmCartBadge" hidden>0</i>
        </button>
        <button type="button" class="lm-tab" data-nav="calendar" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
            <span>{{ ___('Calendar') }}</span>
        </button>
        <button type="button" class="lm-tab" data-nav="profile" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
            <span>{{ ___('Profile') }}</span>
        </button>
    </div>
</nav>
