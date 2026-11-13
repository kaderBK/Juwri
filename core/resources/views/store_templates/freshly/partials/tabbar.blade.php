{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- freshly — fixed bottom tab bar: Home / Orders / center Book / Offers /
     Profile. Rendered on the four top-level screens only (matches TABS in
     the page script); drill-down screens (service detail/basket/schedule/
     checkout/confirm) use a back-header + sticky action bar instead.
     Expects: $active ('home'|'orders'|'offers'|'profile'), $T. --}}
<nav class="frs-tabbar">
    <button type="button" class="frs-tab" data-nav="home" aria-current="{{ $active === 'home' ? 'true' : 'false' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 001 1h10a1 1 0 001-1v-9"/></svg>
        <span>{{ $T['home'] }}</span>
    </button>
    <button type="button" class="frs-tab" data-nav="orders" aria-current="{{ $active === 'orders' ? 'true' : 'false' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5z"/><path d="M3 8v8l9 5 9-5V8"/></svg>
        <span>{{ $T['ordersTab'] }}</span>
    </button>
    <button type="button" class="frs-fab" data-nav="services" aria-label="{{ $T['bookTab'] }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
    <button type="button" class="frs-tab" data-nav="offers" aria-current="{{ $active === 'offers' ? 'true' : 'false' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="7.5" r="2"/><circle cx="16.5" cy="16.5" r="2"/><path d="M17 7L7 17"/></svg>
        <span>{{ $T['offersTab'] }}</span>
    </button>
    <button type="button" class="frs-tab" data-nav="profile" aria-current="{{ $active === 'profile' ? 'true' : 'false' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
        <span>{{ $T['profileTab'] }}</span>
    </button>
</nav>
