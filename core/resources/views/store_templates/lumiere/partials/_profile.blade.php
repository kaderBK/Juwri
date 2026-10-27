{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $lmCanEditProfile = $customer && Route::has('customer.profile');
    $lmCanLogout = $customer && Route::has('customer.logout');
@endphp
<section class="lm-screen has-tabbar" data-screen="profile" hidden>
    <div class="lm-hdr">
        <span class="lm-title">{{ ___('Profile') }}</span>
        <span style="width:42px"></span>
    </div>

    <div class="lm-profile">
        @if($customer && $customer->avatar_url)
            @if((string) ($customer->avatar_url) !== '')<img class="lm-avatar" src="{{ $customer->avatar_url }}" alt="" data-csp-error="hide">@endif
        @else
            <span class="lm-avatar-fallback" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($customer->name ?? $store->title, 0, 1)) }}</span>
        @endif
        <div>
            <div class="lm-pn">{{ $customer->name ?? ___('Guest') }}</div>
            <div class="lm-pe">{{ $customer->phone ?? $customer->email ?? ___('Not signed in') }}</div>
        </div>
    </div>

    {{-- K3 · loyalty Rewards card — public teaser (store_options loyalty_teaser),
         readable with no auth so it renders for guest + signed-in alike. Tier
         chips are the program's own Silver/Gold/Platinum ladder as a visual;
         when signed in, lm-boot.js best-effort-fetches the real balance and
         highlights the diner's current tier (see loyalty/balance in
         partials/_script.blade.php) — never fabricated if that call fails. --}}
    @if(! empty($loyTeaser))
        <div class="lm-rewards-card">
            <div class="lm-rw-program">{{ $loyTeaser['program'] ?? ___('Rewards') }}</div>
            @if(!empty($loyTeaser['earn']))
                <div class="lm-rw-line">{{ $loyTeaser['earn'] }}</div>
            @endif
            @if(!empty($loyTeaser['reward']))
                <div class="lm-rw-line">{{ $loyTeaser['reward'] }}</div>
            @endif
            <div class="lm-rw-tiers" aria-hidden="true">
                <span class="lm-rw-tier" id="lmRwTierSilver">{{ ___('Silver') }}</span>
                <span class="lm-rw-tier" id="lmRwTierGold">{{ ___('Gold') }}</span>
                <span class="lm-rw-tier" id="lmRwTierPlatinum">{{ ___('Platinum') }}</span>
            </div>
        </div>
    @endif

    @if($lmLanguages->count() > 1)
        <div class="lm-label">{{ ___('Language') }}</div>
        <div class="lm-langlist">
            @foreach($lmLanguages as $lang)
                <a class="{{ get_lang() === $lang->code ? 'is-current' : '' }}"
                   data-lang-code="{{ $lang->code }}" data-lang-name="{{ $lang->name }}"
                   href="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}"
                   @if(get_lang() === $lang->code) aria-current="true" @endif>{{ $lang->name }}</a>
            @endforeach
        </div>
    @endif

    <div class="lm-acclist">
        <button type="button" class="lm-accrow" data-nav="favorites">
            <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.5-9.5-9A5 5 0 0112 5a5 5 0 019.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg></span>
            <span class="lm-at">{{ ___('Favorites') }}</span>
            <i class="lm-fav-count" id="lmFavCount" hidden></i>
            <span class="lm-chev" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
        </button>

        <button type="button" class="lm-accrow" data-nav="calendar">
            <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg></span>
            <span class="lm-at">{{ ___('Checklist') }}</span>
            <span class="lm-chev" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
        </button>

        <button type="button" class="lm-accrow" data-nav="orders">
            <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg></span>
            <span class="lm-at">{{ ___('My Orders') }}</span>
            <span class="lm-chev" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
        </button>

        <button type="button" class="lm-accrow" id="lmShareBtn" data-share-url="{{ url()->current() }}" data-share-title="{{ $store->title }}">
            <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg></span>
            <span class="lm-at">{{ ___('Share this store') }}</span>
            <span class="lm-chev" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
        </button>

        @if($lmCanEditProfile)
            <a class="lm-accrow" href="{{ route('customer.profile', $store->id) }}">
                <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg></span>
                <span class="lm-at">{{ ___('Edit profile') }}</span>
                <span class="lm-chev" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
            </a>
        @endif

        <div class="lm-accrow" role="group" aria-label="{{ ___('Dark mode') }}">
            <span class="lm-aico" aria-hidden="true" id="lmProfileThemeIcon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>
            </span>
            <span class="lm-at">{{ ___('Dark mode') }}</span>
            <button type="button" class="lm-switch" id="lmThemeSwitch" role="switch" aria-checked="false" aria-label="{{ ___('Toggle dark mode') }}"><i></i></button>
        </div>

        <button type="button" class="lm-accrow" data-nb-open>
            <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0112 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 004 0"/></svg></span>
            <span class="lm-at">{{ ___('Notifications') }}</span>
            <i class="lm-bell-dot" hidden aria-hidden="true" style="position:static;margin-inline-start:auto"></i>
        </button>

        @if($customer && \App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
            <button type="button" class="lm-accrow" data-mb-wallet-open>
                <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M16 14h2"/></svg></span>
                <span class="lm-at">{{ ___('Wallet — add funds') }}</span>
            </button>
        @endif

        @if($lmCanLogout)
            <button type="button" class="lm-accrow" id="lmLogoutBtn" data-logout-url="{{ route('customer.logout', $store->id) }}">
                <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3M10 12h9M16 8l3 4-3 4"/></svg></span>
                <span class="lm-at">{{ ___('Log out') }}</span>
            </button>
        @elseif(! $customer)
            <button type="button" class="lm-accrow" id="lmProfileSigninBtn">
                <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3M10 12h9M16 8l3 4-3 4"/></svg></span>
                <span class="lm-at">{{ ___('Sign in') }}</span>
            </button>
        @endif

        {{-- Privacy: wipe the cart / favourites / saved addresses this browser
             keeps (shared window.mbClearDeviceData engine). The profile screen is
             a guest-reachable tab, so the control lives here rather than in the
             checkout footer; the engine keeps the light/dark preference. --}}
        <button type="button" class="lm-accrow lm-accrow--danger" id="lmClearData">
            <span class="lm-aico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg></span>
            <span class="lm-at">{{ ___('Clear saved data on this device') }}</span>
        </button>
    </div>
    <p class="lm-cleardata-hint" id="lmClearDataHint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
</section>
