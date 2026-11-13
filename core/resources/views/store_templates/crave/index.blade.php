{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       crave — dark app-style ordering theme · data preparation
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    /* the item map is built in data.php (shared with the lazy-load fragment, items-fragment.blade.php) */
    include resource_path('views/store_templates/crave/data.php');

    /* P4-16: a very large catalogue ships its first cards only; script.js fetches the rest on demand (`?fragment=crave&ids=`).
       `lazy` = id => [category id, top-level category id] for every item left out (the chips, counts and loaders need no more). */
    $cravePaged = $craveLazy !== null ? ['lazy' => $craveLazy, 'url' => '?fragment=crave'] : null;   // data.php (P6-24) maps only the shipped items

    // Primary chips: top-level parents that hold at least one descendant item
    // (so pure containers / empty parents drop).
    $categories = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);

    // Secondary chips: per parent, only the subcategories that own items. A parent
    // with no item-bearing subcategory gets no second row (its items live directly
    // on it and show under the parent chip alone).
    $subCatsOf = [];
    foreach ($categories as $c) {
        $subs = \Juwri\Core\Support\ViewHelpers::withItems($c->subcategories, $catItemCount);
        if ($subs->isNotEmpty()) {
            $subCatsOf[$c->id] = $subs;
        }
    }

    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();
    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $customerDefaultAddress = $customerAddresses->first();
    // Coupons & Wallets P7-6a: pay-with-credit for a logged-in diner with balance.
    // ($curSign is already defined above at the top of this block.)
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;

    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */

    /* menu_sections visibility (same contract as aurora) */
    $savedSections = isset($postOptions->menu_sections)
        ? json_decode(json_encode($postOptions->menu_sections), true)
        : null;
    $defaultSections = [
        ['id' => 'hero', 'visible' => true],
        ['id' => 'brand_row', 'visible' => true],
        ['id' => 'catbar', 'visible' => true],
        ['id' => 'daily_specials', 'visible' => true],
        ['id' => 'menu', 'visible' => true],
    ];
    $sectionVisible = collect($defaultSections)->pluck('visible', 'id')->all();
    if (is_array($savedSections)) {
        foreach ($savedSections as $saved) {
            if (isset($saved['id'], $sectionVisible[$saved['id']])) {
                $sectionVisible[$saved['id']] = isset($saved['visible']) ? (bool) $saved['visible'] : true;
            }
        }
    }
    /* Customizer v2: the theme customizer is the newer source of truth for
       section visibility — it overlays the legacy menu_sections row above. The
       splash is no longer a "section": it has one canonical switch. */
    $sectionVisible = array_replace($sectionVisible, theme_section_visible($store->id));
    $sectionVisible['hero'] = (bool) theme_setting($store->id, 'splash.enabled', true);

    $showBranches = !empty($branches) && count($branches) > 1 && (@$postOptions->allow_multi_branch_switch ?? 1);

    /* engagement extras (same backend contract as aurora) */
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));

    /* already-joined / already-booked status for a signed-in diner (matched by phone) */
    $myWaitlist = storefront()->activeWaitlist($store, $customer);
    $myReservation = storefront()->upcomingReservation($store, $customer);

    /* ── theme-pack-blade features (F1–F7) ──────────────────────────────
       F1/F2 hit the CustomerToken-guarded API; mint (and session-cache) a raw
       token for the signed-in session customer so the theme JS can call them. */
    $customerApiToken = storefront()->customerApiToken($customer, 'crave');

    /* F2 · follow / verified brand state */
    $isVerifiedStore = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
    $followerCount   = method_exists($store, 'follows') ? (int) $store->follows()->count() : 0;
    $isFollowing     = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;
    // B6: this customer's follow-notification opt-out state, for the "Following" sheet toggle.
    $followRow       = ($isFollowing && method_exists($store, 'follows')) ? $store->follows()->where('customer_id', $customer->id)->first() : null;
    $followNotify    = $followRow ? (bool) $followRow->notify : true;
    // B8: about-sheet data. Reviews render only when the directory/reviews module is
    // on (dark today) and the store has approved reviews — else the block is omitted.
    $aboutInstagram  = store_options($store->id, 'instagram_url');
    $aboutFacebook   = store_options($store->id, 'facebook_url');
    $aboutReviews    = collect();
    $aboutRating     = null;
    if (\Juwri\Modules\Storefront\Services\StoreReviewsDisplay::enabledFor($store->id)) {
        $approvedReviews = \App\Models\StoreReview::approved()->forStore($store->id)->latest()->get(['reviewer_name', 'stars', 'body']);
        if ($approvedReviews->isNotEmpty()) {
            $aboutRating  = round((float) $approvedReviews->avg('stars'), 1);
            $aboutCount   = $approvedReviews->count();
            $aboutReviews = $approvedReviews->take(6);
        }
    }

    /* F1 · meal-plan enable + cadences */
    $mealPlansEnabled  = \Juwri\Modules\Ordering\Services\MealPlanService::enabled($store->id);
    $mealPlanCadences  = $mealPlansEnabled ? \Juwri\Modules\Ordering\Services\MealPlanService::allowedCadences($store->id) : [];

    /* F7 · OTP checkout gate */
    $otpGateEnabled = \Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController::gateEnabled($store->id);
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,560&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- F4 · self-hosted Leaflet (nonce/CSP-safe; script-src is self+nonce). Tiles load as https imgs. --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
@endpush

@section('content')
<div class="crave" id="crave">
    @php
        // CC run — cover + subtitle. One F10-guarded cover URL, reused by the
        // splash blur (Concept 3), the home hero band (Concept 1) and the
        // collapsing header (phase 2). default.png is "no cover" (live media is
        // damaged — every cover img still carries data-csp-error="hide").
        $__craveHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
        $__craveCoverUrl = $__craveHasCover
            ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null)
            : null;
        // Hero band only when a cover exists, the owner left it on, AND the brand
        // row itself is shown (the cover is part of the brand presentation).
        $craveCoverHero = $__craveHasCover
            && ($sectionVisible['brand_row'] ?? true)
            && theme_setting($store->id, 'brand.show_cover', true);
        $craveSplashCover = $__craveHasCover
            && theme_setting($store->id, 'brand.cover_splash', true);
        // Hero tagline is the store's own subtitle; the splash line lets a
        // customizer text override win first (Concept 2).
        $craveHeroTagline = trim((string) ($store->sub_title ?? ''));
        $craveSplashSub = trim((string) (theme_setting($store->id, 'texts.hero_subtitle', '') ?: ($store->sub_title ?? '')));
    @endphp

    {{-- ══════════ SPLASH (hero section) ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="splash{{ $craveSplashCover ? ' splash--cover' : '' }}" id="cvSplash">
        {{-- Concept 3: the store's cover, blurred + darkened, as splash ambience.
             An <img> (not a background) keeps it CSP-clean and lets data-csp-error
             pull it if the media 404s; sits under the doodles + content (z-index). --}}
        @if($craveSplashCover)
        @if((string) ($__craveCoverUrl) !== '')<img class="cv-splash-cover" src="{{ $__craveCoverUrl }}" alt="" aria-hidden="true" data-csp-error="hide">@endif
        @endif
        <div class="splash-doodles" aria-hidden="true">
            <svg class="dd dd-1" viewBox="0 0 40 40"><path d="M20 4v10M20 26v10M4 20h10M26 20h10" stroke-linecap="round"/></svg>
            <svg class="dd dd-2" viewBox="0 0 40 40"><path d="M8 30C14 10 28 8 34 12c-8 2-14 10-14 20" stroke-linecap="round"/></svg>
            <svg class="dd dd-3" viewBox="0 0 40 40"><circle cx="20" cy="20" r="12"/></svg>
            <svg class="dd dd-4" viewBox="0 0 40 40"><path d="M6 20c6-8 22-8 28 0" stroke-linecap="round"/></svg>
        </div>
        <h1 class="splash-title">
            <span class="st-line">{{ ___('We') }}
                <span class="st-badge">@if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="parent-hide">@endif</span>
            </span>
            <span class="st-line">{{ ___('Serve') }} <span class="st-rings" aria-hidden="true"><i></i><i></i><i></i></span></span>
            <span class="st-line st-accent" data-tc-text="texts.hero_title">{{ theme_setting($store->id, 'texts.hero_title', ___('Fresh Food')) }}</span>
        </h1>
        {{-- Concept 2: the store subtitle fills the dead space under the title.
             texts.hero_subtitle overrides live (data-tc-text); falls back to the
             store's own sub_title; renders nothing when both are empty. --}}
        @if($craveSplashSub !== '')
        <p class="splash-sub" data-tc-text="texts.hero_subtitle">{{ $craveSplashSub }}</p>
        @endif
        <div class="splash-hero">
            <div class="splash-blob"></div>
            @if($featured)
                @if((string) (media_url('storage/menu/', $featured->image, $store->id ?? null)) !== '')<img class="splash-dish" src="{{ media_url('storage/menu/', $featured->image, $store->id ?? null) }}" alt="">@endif
            @endif
            @if(isset($splashImgs[1]))
                @if((string) (media_url('storage/menu/', $splashImgs[1]->image, $store->id ?? null)) !== '')<img class="splash-chip chip-a" src="{{ media_url('storage/menu/', $splashImgs[1]->image, $store->id ?? null) }}" alt="">@endif
            @endif
            @if(isset($splashImgs[2]))
                @if((string) (media_url('storage/menu/', $splashImgs[2]->image, $store->id ?? null)) !== '')<img class="splash-chip chip-b" src="{{ media_url('storage/menu/', $splashImgs[2]->image, $store->id ?? null) }}" alt="">@endif
            @endif
        </div>
        <button class="splash-skip" id="cvSplashSkip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="cv-views">

        {{-- HOME --}}
        <section class="view is-active" data-view="home">
            @if($craveCoverHero)
            {{-- Phase-2 collapsing header: overlays the top of the home view (a
                 sibling of the scroll area, so it never scrolls). Hidden until JS
                 sees the cover band leave the scroll container. Cover stores only. --}}
            <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
                <span class="cv-condensed-logo">@if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif</span>
                <b class="cv-condensed-name">{{ $store->title }}</b>
                {{-- The same top-chrome buttons the header scrolled away — proxied to
                     the real controls (single-id-bound, so they cannot be duplicated).
                     Only the buttons the top bar actually shows are mirrored here. --}}
                <div class="cv-condensed-actions">
                    @if(count($menu_languages ?? []) > 1)
                    <button class="iconbtn" type="button" data-cv-proxy="lang" aria-label="{{ ___('Language') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="iconbtn" type="button" data-cv-proxy="catering" aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    <button class="iconbtn" type="button" data-cv-proxy="notif" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                    </button>
                </div>
            </div>
            @endif
            <div class="view-scroll">
                @if($craveCoverHero)
                {{-- Concept 1: cover hero. Wraps the top chrome only; the cover img
                     + scrim live in an absolute media layer (clipped), the chrome
                     overlays it, and the brand row below pulls up over the edge. --}}
                <div class="cv-cover" data-cv-cover>
                    <div class="cv-cover-media" aria-hidden="true">
                        @if((string) ($__craveCoverUrl) !== '')<img class="cv-cover-img" src="{{ $__craveCoverUrl }}" alt="" data-csp-error="hide">@endif
                        <span class="cv-cover-scrim"></span>
                    </div>
                @endif
                <header class="home-top">
                    {{-- The top-left button opens the customer's profile, so it shows
                         the CUSTOMER — their initial when signed in, a neutral person
                         glyph as a guest — not the store logo. The store's mark still
                         lives once on the brand/follow row below (F2). --}}
                    <button class="avatar{{ $customer ? ' avatar--me' : '' }}" data-go="profile" aria-label="{{ ___('Profile') }}" type="button">
                        @if($customer)
                            {{ Str::upper(Str::substr($customer->name ?: $customer->phone ?: '?', 0, 1)) }}
                        @else
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                        @endif
                    </button>
                    @if(count($menu_languages ?? []) > 1)
                        <button class="iconbtn" type="button" data-csp-open-sheet="cvLangSheet" data-csp-scrim="cvScrim" aria-label="{{ ___('Language') }}">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                        </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="iconbtn" id="cvCateringBtn" type="button"
                            data-csp-open-sheet="cvCateringSheet" data-csp-scrim="cvScrim" data-catering-open
                            aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    <button class="iconbtn" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                        <i class="dot" id="mbNotifDot" hidden></i>
                    </button>
                </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>
                @if($craveCoverHero)
                </div>{{-- /.cv-cover --}}
                @endif

                {{-- F2 · brand / follow row (B10: customizer section, default on) --}}
                @if($sectionVisible['brand_row'] ?? true)
                <div class="brand-row{{ $craveCoverHero ? ' brand-row--hero' : '' }}" id="cvBrandRow" data-tc-section="brand_row">
                    <span class="brand-logo">
                        @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                    </span>
                    <span class="brand-info">
                        <b class="brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="verified-badge" id="cvVerifiedBadge" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
                        @if($craveCoverHero && $craveHeroTagline !== '')
                        <span class="cv-tagline">{{ $craveHeroTagline }}</span>
                        @endif
                        @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true)])
                    </span>
                    {{-- Following needs a customer account: on a guest_only store the auth
                         sheet is never rendered, so openAuth() does not exist and the click
                         handler returns silently. Hide the control instead of shipping a
                         button that cannot do anything. --}}
                    @if($authMode !== 'guest_only' || $customer)
                    <button class="followbtn {{ $isFollowing ? 'is-on' : '' }}" id="cvFollowBtn" type="button"
                            data-following="{{ $isFollowing ? '1' : '0' }}">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                        <span class="follow-label" data-tc-text="texts.follow_label">{{ theme_setting($store->id, 'texts.follow_label', ___('Follow')) }}</span>
                        <span class="following-label">{{ ___('Following') }}</span>
                    </button>
                    @endif
                </div>
                @endif

                {{-- Open/closed status strip — the first thing under the brand row,
                     above the branch selector. --}}
                <div class="status-strip">
                    <span class="open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                </div>

                {{-- Branch selector on its own row under the status strip. --}}
                <div class="cv-metarow" data-mb-hotel-chip-slot>
                    @if($showBranches)
                        <button class="loc" id="cvBranchBtn" type="button">
                            <span class="loc-label">{{ ___('Branch') }}</span>
                            <span class="loc-value">
                                <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                                <b>{{ $activeBranch->name ?? $branches->first()->name ?? $store->title }}</b>
                                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                            </span>
                        </button>
                    @else
                        <span class="loc">
                            <span class="loc-label">{{ ___('Welcome to') }}</span>
                            <span class="loc-value"><b>{{ $store->title }}</b></span>
                        </span>
                    @endif
                </div>

                {{-- F3 · stories rail (populated from /r/{slug}/stories) --}}
                <div class="stories-rail" id="cvStoriesRail" hidden></div>

                {{-- Search sits under the brand row: it opens a full-screen overlay with
                     its own results, so it does not depend on being next to the grid the
                     way an inline filter would. The diet filter still lives down on the
                     grid's tools row, where what it changes is in view. --}}
                @php $__craveSearchOn = theme_setting($store->id, 'search.enabled', true); @endphp
                @if($__craveSearchOn || $reservationsEnabled)
                <div class="searchrow">
                    @if($__craveSearchOn)
                    <button class="search" id="cvSearchOpen" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                        <span class="search-ph" data-tc-text="texts.search_placeholder">{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dishes or ingredients')) }}</span>
                    </button>
                    @endif
                    {{-- Reserve a Table — opens the reservation sheet via the shared
                         data-csp-open-sheet handler (layout.blade.php). --}}
                    @if($reservationsEnabled)
                    <button class="reserve-btn" type="button" data-csp-open-sheet="cvResSheet" data-csp-scrim="cvScrim" aria-label="{{ ___('Reserve a Table') }}" title="{{ ___('Reserve a Table') }}">
                        <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9 14l2 2 4-4"/></svg>
                        <span class="reserve-btn-label">{{ ___('Reserve') }}</span>
                    </button>
                    @endif
                </div>
                @endif

                {{-- banners (admin-managed) --}}
                @if(!empty($menuBanners) && count($menuBanners))
                <div class="banners">
                    <div class="banner-track" id="cvBannerTrack">
                        @foreach($menuBanners as $bi => $banner)
                            <a class="banner {{ $bi % 2 === 1 ? 'alt' : '' }}" href="{{ storefront_localized_link($banner->link_url) ?: '#' }}" @if($banner->link_url) target="_blank" rel="noopener" @endif>
                                <span class="banner-bg"></span>
                                <span class="banner-copy">
                                    @if($banner->subtitle)<span class="banner-kicker">{{ $banner->subtitle }}</span>@endif
                                    <span class="banner-title">{{ $banner->title }}</span>
                                    <span class="banner-btn" data-tc-text="texts.order_now">{{ theme_setting($store->id, 'texts.order_now', ___('Order now')) }}</span>
                                </span>
                                @if($banner->image)
                                    @if((string) ($banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null)) !== '')<img class="banner-img" src="{{ $banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null) }}" alt="">@endif
                                @endif
                            </a>
                        @endforeach
                    </div>
                    <div class="banner-dots" id="cvBannerDots"></div>
                </div>
                @endif

                {{-- Customizer v2 · WI-L2 — the specials / categories / menu blocks
                     render in the owner's chosen order (theme_sections reads the
                     saved sections._order; default is the source order below). Each
                     block still emits once, gated by its own visibility, so this is
                     a reorder only — the markup is unchanged. --}}
                @foreach(theme_sections($store->id) as $__craveSec)
                @php $__cs = $__craveSec['id']; @endphp

                {{-- today's specials --}}
                @if($__cs === 'daily_specials' && $sectionVisible['daily_specials'])
                <section class="rail-block" id="cvSpecialsBlock" data-tc-section="daily_specials">
                    <div class="section-head">
                        <h2 data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___("Today's specials")) }}</h2>
                        <span class="flame"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg></span>
                    </div>
                    <div class="rail" id="cvSpecialsRail"></div>
                </section>
                @endif

                {{-- category chips (two-tier: parent row + swap-in subcategory row) --}}
                @if($__cs === 'catbar' && $sectionVisible['catbar'])
                {{-- Sentinel: once this scrolls above the top, the sticky category bar
                     is about to take over the top — the collapsing header hides so the
                     two never stack. --}}
                <span class="cv-menu-sentinel" aria-hidden="true"></span>
                {{-- Menu heading sits ABOVE the categories so the category + sub-category
                     rows read as belonging under it (was below them, in the grid block).
                     The grid keeps its own tools row below. --}}
                @if($sectionVisible['menu'])
                <div class="section-head cv-menu-head" data-mb-grid-lead>
                    <h2 id="cvGridTitle" data-tc-text="texts.menu_title">{{ theme_setting($store->id, 'texts.menu_title', ___('Popular')) }}</h2>
                </div>
                @endif
                {{-- data-mb-grid-lead: the category selector must touch the items it filters —
     injected sections (offers strip) climb above these rows instead of wedging
     between categories and grid. --}}
                <nav class="chips" id="cvChips" data-mb-grid-lead data-mbt-anchor data-tc-section="catbar" aria-label="{{ ___('Categories') }}">
                    <button class="chip is-on" data-cat="all" type="button">✦ {{ ___('All') }}</button>
                    @foreach($categories as $cat)
                        <button class="chip" data-cat="{{ $cat->id }}"
                                @isset($subCatsOf[$cat->id]) data-has-sub="1" @endisset type="button">
                            @if($cat->image)
                                @if((string) (media_url('storage/menu/', $cat->image, $store->id ?? null)) !== '')<img class="chip-img" src="{{ media_url('storage/menu/', $cat->image, $store->id ?? null) }}" alt="" loading="lazy" data-csp-error="remove">@endif
                            @endif
                            {{ $cat->name }}
                        </button>
                    @endforeach
                </nav>
                {{-- Secondary sub-category chips. One hidden group per parent that has
                     item-bearing subcategories; the active parent's group is revealed
                     by JS. "All" (data-sub="root") shows every descendant of the parent. --}}
                @if(!empty($subCatsOf))
                <div class="subchips" id="cvSubChips" data-mb-grid-lead hidden aria-label="{{ ___('Sub-categories') }}">
                    @foreach($subCatsOf as $parentId => $subs)
                        <div class="subgroup" data-parent="{{ $parentId }}" hidden>
                            <button class="subchip is-on" data-sub="root" type="button">{{ ___('All') }}</button>
                            @foreach($subs as $sub)
                                <button class="subchip" data-sub="{{ $sub->id }}" type="button">
                                    @if($sub->image)
                                        @if((string) (media_url('storage/menu/', $sub->image, $store->id ?? null)) !== '')<img class="chip-img" src="{{ media_url('storage/menu/', $sub->image, $store->id ?? null) }}" alt="" loading="lazy" data-csp-error="remove">@endif
                                    @endif
                                    {{ $sub->name }}
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @endif
                @endif

                {{-- grid --}}
                @if($__cs === 'menu' && $sectionVisible['menu'])
                <section class="grid-block" data-tc-section="menu">
                    <div class="section-head grid-tools-head" data-mb-grid-lead>
                        {{-- title moved above the categories (see .cv-menu-head); the grid's
                             own sort + layout tools stay here, aligned to the end. --}}
                        {{-- Filter opens the row from the leading edge (margin-auto below),
                             sort and layout hold the trailing edge — one row, two ends. --}}
                        @if($allowDietary)
                        <button class="filterbtn" id="cvFilterBtn" type="button" aria-label="{{ ___('Filters') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                            <i class="dot" id="cvFilterDot" hidden></i>
                        </button>
                        @endif
                        <div class="head-tools">
                            <button class="sortbtn" id="cvSortBtn" type="button">
                                <span id="cvSortLabel">{{ ___('Featured') }}</span>
                                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M7 4v13M7 17l-3-3M7 17l3-3M17 20V7M17 7l-3 3M17 7l3 3"/></svg>
                            </button>
                            <button class="iconbtn sm" id="cvGridBtn" type="button" aria-label="{{ ___('Toggle layout') }}">
                                <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="grid" id="cvGrid"></div>
                    @if ($cravePaged)
                    {{-- P4-16: reserves the "Load more" row from the first paint so loading cards never moves the footer (script.js fills it) --}}
                    <div class="cv-more" id="cvGridMore"><button class="ghostbtn" id="cvGridMoreBtn" type="button">{{ ___('Load more') }}</button></div>
                    @endif
                    <div class="grid-empty" id="cvGridEmpty" hidden>
                        <svg viewBox="0 0 64 64" class="empty-art"><circle cx="32" cy="32" r="22"/><path d="M24 28c2-3 6-3 8 0M36 28c2-3 6-3 8 0" stroke-linecap="round"/><path d="M24 42c4-4 12-4 16 0" stroke-linecap="round"/></svg>
                        <p>{{ ___('Nothing matches — try clearing filters') }}</p>
                        <button class="ghostbtn" id="cvClearFilters" type="button">{{ ___('Clear filters') }}</button>
                    </div>
                </section>
                @endif
                @endforeach
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- PRODUCT --}}
        <section class="view view-sub" data-view="product">
            <div class="view-scroll">
                <div class="p-hero">
                    <div class="p-gallery" id="pGallery"></div>
                    {{-- Desktop/pointer users can't swipe the gallery — these arrows step
                         through a multi-image item. Hidden until JS finds >1 image. --}}
                    <button class="gal-nav prev" id="pGalPrev" type="button" hidden aria-label="{{ ___('Previous') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <button class="gal-nav next" id="pGalNext" type="button" hidden aria-label="{{ ___('Next') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button>
                    <div class="p-gallery-dots" id="pGalleryDots"></div>
                    <button class="fab back" data-back aria-label="{{ ___('Back') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <button class="fab fav" id="pFav" aria-label="{{ ___('Favorite') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
                    <span class="p-stock" id="pStock" hidden></span>
                </div>
                <div class="p-body" id="pBody"></div>
                {{-- F6 · rich reviews (populated on product open) --}}
                <section class="p-reviews" id="cvReviews" hidden></section>
                <div class="rail-block p-related" id="cvRelatedBlock" style="padding:0 20px">
                    <div class="section-head"><h2>{{ ___('You might also like') }}</h2></div>
                    <div class="rail" id="relatedRail"></div>
                </div>
                <div class="tail-space tall"></div>
            </div>
            @if($allow_order)
            <footer class="p-cta">
                <div class="qty" id="pQty">
                    <button class="qty-btn" data-q="-1" aria-label="{{ ___('Decrease quantity') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
                    <output id="pQtyVal">01</output>
                    <button class="qty-btn" data-q="1" aria-label="{{ ___('Increase quantity') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
                </div>
                <button class="cta" id="addToCart" type="button"><span data-tc-text="texts.add_to_cart">{{ theme_setting($store->id, 'texts.add_to_cart', ___('Add to Cart')) }}</span><b id="pTotal"></b></button>
                {{-- Once the bag has something in it, a direct route to the cart appears
                     beside Add to Cart so the guest doesn't have to hunt for the tab. --}}
                <button class="cta-view-cart" id="pViewCart" data-go="cart" type="button" hidden aria-label="{{ ___('View cart') }}">
                    <svg class="ic" viewBox="0 0 24 24"><circle cx="9.5" cy="19.5" r="1.6"/><circle cx="17" cy="19.5" r="1.6"/><path d="M3 4h2l2.3 11h10.4L20 8H6.1"/></svg>
                    <i class="pvc-badge" id="pViewCartCount">0</i>
                </button>
            </footer>
            @endif
        </section>

        {{-- FAVORITES --}}
        <section class="view" data-view="favs">
            <div class="view-scroll">
                <header class="page-head"><h1>{{ ___('Favorites') }}</h1></header>
                <div class="grid" id="cvFavGrid"></div>
                <div class="grid-empty" id="cvFavEmpty" hidden>
                    <svg viewBox="0 0 64 64" class="empty-art"><path d="M32 50s-18-11-18-24c0-7 5.4-12 11.2-12 3.4 0 5.6 2 6.8 3.8C33.2 16 35.4 14 38.8 14 44.6 14 50 19 50 26c0 13-18 24-18 24Z"/></svg>
                    <p>{{ ___('Tap the heart on any dish to save it here') }}</p>
                </div>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- CART --}}
        <section class="view" data-view="cart">
            <div class="view-scroll">
                <header class="page-head">
                    <h1>{{ ___('My Cart') }}</h1>
                    <button class="ghostbtn sm" id="cvClearCart" type="button">{{ ___('Clear') }}</button>
                </header>
                <div id="cvCartList" class="cart-list"></div>
                <div class="grid-empty" id="cvCartEmpty" hidden>
                    <svg viewBox="0 0 64 64" class="empty-art"><path d="M14 18h6l5 24h22l5-18H24"/><circle cx="28" cy="50" r="3.4"/><circle cx="44" cy="50" r="3.4"/></svg>
                    <p>{{ ___('Your cart is empty') }}</p>
                    <button class="ghostbtn" data-go="home" type="button" data-tc-text="texts.view_menu">{{ theme_setting($store->id, 'texts.view_menu', ___('Browse menu')) }}</button>
                </div>

                @if($allow_order)
                <div id="cvCartTools" class="cart-tools">
                    <section>
                        <h3>{{ ___('Order type') }}</h3>
                        <div class="seg" id="cvOrderTypeSeg">
                            @if($allow_delivery)<button data-ot="delivery" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg><span data-tc-text="texts.delivery">{{ theme_setting($store->id, 'texts.delivery', ___('Delivery')) }}</span></button>@endif
                            @if($allow_takeaway)<button data-ot="takeaway" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><span data-tc-text="texts.pickup">{{ theme_setting($store->id, 'texts.pickup', ___('Takeaway')) }}</span></button>@endif
                            @if($allow_on_table)<button data-ot="on-table" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg><span>{{ ___('On table') }}</span></button>@endif
                            @if($allow_room_service)<button data-ot="room_service" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 6v2M10.5 6h3"/></svg><span>{{ ___('Room service') }}</span></button>@endif
                        </div>
                        {{-- per-order-type inline fields (delivery address, rebuilt by JS on every switch) --}}
                        <div class="ot-fields" id="cvOtFields"></div>
                        @if($allowOrderSched)
                        <div class="schedule-row">
                            <span class="sched-label"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ ___('Schedule for later') }}</span>
                            <input aria-label="{{ ___('Schedule for later') }}" type="datetime-local" name="scheduled_at" id="cvScheduledAt" class="when-time" min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}" max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
                        </div>
                        @endif
                    </section>

                    {{-- F1 · turn this cart into a recurring meal plan --}}
                    @if($mealPlansEnabled)
                    <section>
                        <button class="mealplan-cta" id="cvMealPlanCta" type="button">
                            <span class="mp-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M8 14h3M8 17h6"/></svg></span>
                            <span class="mp-copy"><b>{{ ___('Make this a meal plan') }}</b><small>{{ ___('Get these dishes delivered on a schedule') }}</small></span>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </button>
                    </section>
                    @endif

                    {{-- T5: Curbside pickup (takeaway) --}}
                    @if(!empty($postOptions->curbside_enabled))
                    <section>
                        <label class="curbside-field" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="curbside" value="1" id="cvCurbside" data-csp-toggle="cvCurbsideFields">
                            <span>{{ ___("Curbside pickup — I'll wait in my car") }}</span>
                        </label>
                        <div id="cvCurbsideFields" style="display:none;margin-top:8px;">
                            <input aria-label="{{ ___('Car make/model') }}" type="text" name="vehicle_make" id="cvVehicleMake" class="when-time" placeholder="{{ ___('Car make/model') }}" style="margin-bottom:8px;width:100%;">
                            <input aria-label="{{ ___('Colour') }}" type="text" name="vehicle_color" id="cvVehicleColor" class="when-time" placeholder="{{ ___('Colour') }}" style="margin-bottom:8px;width:100%;">
                            <input aria-label="{{ ___('Plate') }}" type="text" name="vehicle_plate" id="cvVehiclePlate" class="when-time" placeholder="{{ ___('Plate') }}" style="width:100%;">
                        </div>
                    </section>
                    @endif

                    @if($allowCoupons)
                    <section>
                        <label class="coupon-field">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                            <input id="cvCouponInput" placeholder="{{ ___('Promo code') }}">
                            <button class="minibtn" id="cvCouponApply" type="button">{{ ___('Apply') }}</button>
                        </label>
                        <p class="coupon-msg" id="cvCouponMsg" hidden></p>
                    </section>
                    @endif

                    @if(!empty($postOptions->gift_cards_enabled))
                    <section>
                        <label class="coupon-field">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                            <input aria-label="{{ ___('Gift card code') }}" id="cvGiftCardInput" name="gift_card_code" placeholder="{{ ___('Gift card code') }}">
                        </label>
                    </section>
                    @endif

                    <section class="ordernote">
                        <label><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5V4Z"/><path d="M15 4v4h4M8.5 12h7M8.5 16h5"/></svg>{{ ___('Note') }} ({{ ___('optional') }})</label>
                        <textarea aria-label="{{ ___('Any special requests?') }}" id="cvOrderNote" rows="2" placeholder="{{ ___('Any special requests?') }}"></textarea>
                    </section>

                    <section class="totals" id="cvTotalsBox"></section>
                    <p class="minorder" id="cvMinOrderMsg" hidden></p>

                    @if($allowWaiter && $allow_on_table)
                    <div class="waiter-row" id="cvWaiterRow" hidden>
                        <button class="ghostbtn" id="cvCallWaiter" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span>{{ ___('Call Waiter') }}</span></button>
                        <button class="ghostbtn" id="cvRequestBill" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg><span>{{ ___('Request Bill') }}</span></button>
                    </div>
                    @endif
                </div>
                @endif
                {{-- Privacy utility: wipe the cart / favourites / saved addresses this
                     browser keeps (shared window.mbClearDeviceData engine). --}}
                <section class="device-data">
                    <button class="ghostbtn sm cv-cleardata" id="cvClearData" type="button">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>
                        <span>{{ ___('Clear saved data on this device') }}</span>
                    </button>
                    <p class="device-data-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
                </section>
                <div class="tail-space tall"></div>
            </div>
            @if($allow_order)
            <footer class="p-cta" id="cvCartCta" hidden>
                <button class="cta wide" id="cvGoCheckout" type="button"><span data-tc-text="texts.checkout_button">{{ theme_setting($store->id, 'texts.checkout_button', ___('Checkout')) }}</span><b id="cvCartCtaTotal"></b></button>
            </footer>
            @endif
        </section>

        {{-- CHECKOUT --}}
        <section class="view view-sub" data-view="checkout">
            <div class="view-scroll">
                <header class="page-head sub">
                    <button class="fab back inline" data-back aria-label="{{ ___('Back') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1>{{ ___('Checkout') }}</h1>
                </header>

                <section class="cv-block">
                    <span class="cv-h3">{{ ___('Contact') }}</span>
                    @if($customer)
                        <input aria-label="{{ ___('Your Name') }}" id="cvCustName" value="{{ $customer->name }}" placeholder="{{ ___('Your Name') }}" autocomplete="name">
                    @else
                        <input aria-label="{{ ___('Your Name') }}" id="cvCustName" placeholder="{{ ___('Your Name') }}" autocomplete="name">
                    @endif
                    <div id="cvPhoneField">
                        <span class="field-label">{{ ___('Phone number') }}</span>
                        <input aria-label="{{ ___('Phone number') }}" id="cvCustPhone" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
                    </div>
                    <div id="cvTableField" style="display:none">
                        <span class="field-label">{{ ___('Table') }}</span>
                        @if($table)
                            <input aria-label="{{ ___('Table number') }}" id="cvTableInput" value="{{ $table->table_no }}" readonly>
                        @else
                            <input aria-label="{{ ___('Table number') }}" id="cvTableInput" type="number" placeholder="{{ ___('Table number') }}" inputmode="numeric">
                        @endif
                    </div>
                    @if($authMode !== 'guest_only' && !$customer)
                        <button class="linkbtn" id="cvLoginLink" type="button">{{ ___('Have an account?') }} {{ ___('Login') }}</button>
                    @endif
                </section>

                <section class="cv-block" id="cvAddrBlock" style="display:none">
                    <span class="cv-h3">{{ ___('Delivery address') }}</span>
                    {{-- Saved addresses: logged-in customers get their address book;
                         guests get a list JS builds from addresses they've used before
                         on this device (localStorage) — so a returning guest can pick a
                         previous delivery address instead of retyping it. Either way,
                         tapping a chip fills the field + coords (handler wired once). --}}
                    @if($customerAddresses->count())
                    <div class="saved-addrs" id="cvSavedAddrs">
                        @foreach($customerAddresses as $sa)
                        <button class="saved-addr{{ $loop->first ? ' is-on' : '' }}" type="button"
                                data-addr="{{ \Juwri\Core\Support\ViewHelpers::addressLabel($sa) }}" data-lat="{{ $sa->lat }}" data-lng="{{ $sa->lng }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            <span class="sa-text"><b>{{ $sa->label ?: ___('Saved address') }}</b><small>{{ Str::limit(\Juwri\Core\Support\ViewHelpers::addressLabel($sa), 42) }}</small></span>
                        </button>
                        @endforeach
                    </div>
                    @else
                    <div class="saved-addrs" id="cvSavedAddrs" hidden></div>
                    @endif
                    {{-- F5 · address autocomplete + use-my-location --}}
                    <div class="addr-field" id="cvAddrField">
                        <input aria-label="{{ ___('Delivery address') }}" id="cvCustAddr" placeholder="{{ ___('Delivery address') }}" autocomplete="off" autocorrect="off" spellcheck="false"
                               value="{{ $customerDefaultAddress ? \Juwri\Core\Support\ViewHelpers::addressLabel($customerDefaultAddress) : '' }}">
                        <input type="hidden" id="cvCustLat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                        <input type="hidden" id="cvCustLng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                        <button class="uselocbtn" id="cvUseLocation" type="button" title="{{ ___('Use my location') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                        </button>
                        <div class="addr-suggest" id="cvAddrSuggest" hidden></div>
                    </div>
                    <p class="geo-attrib" id="cvGeoAttrib" hidden></p>
                    {{-- Drop-a-pin address picker (shared window.mbMapPicker engine, crave-skinned sheet below). --}}
                    <button class="nearest-branch-btn cv-mapbtn" id="cvAddrMapBtn" type="button">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        {{ ___('Choose on map') }}
                    </button>
                    @if($showBranches)
                    <button class="nearest-branch-btn" id="cvNearestBranchBtn" type="button">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        {{ ___('Find nearest branch on map') }}
                    </button>
                    @endif
                </section>

                <section class="cv-block">
                    <span class="cv-h3">{{ ___('Pay Via') }}</span>
                    <div class="pm-list">
                        <button class="pm is-on" data-pm="pay_on_counter" type="button">
                            <span class="pm-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.6"/></svg></span>
                            <b>{{ ___('Pay On Counter') }}</b>
                            <span class="pm-radio"></span>
                        </button>
                        @if($allow_payment)
                        <button class="pm" data-pm="pay_online" type="button">
                            <span class="pm-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h4"/></svg></span>
                            <b>{{ ___('Pay Online') }}<small>{{ ___('Card / wallet via secure page') }}</small></b>
                            <span class="pm-radio"></span>
                        </button>
                        @endif
                    </div>
                </section>

                @if($customer && $storeCredit > 0)
                <section class="cv-block" id="cvCreditBlock">
                    <span class="cv-h3">{{ ___('Store credit') }}</span>
                    <label class="terms">
                        <input type="checkbox" id="mbUseWallet">
                        <span>{{ ___('Pay with store credit') }} ({{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }})</span>
                    </label>
                </section>
                @endif

                @if($allowTips)
                <section class="cv-block">
                    <span class="cv-h3">{{ ___('Tip') }} ({{ ___('optional') }})</span>
                    <div class="tip-row" id="cvTipRow">
                        <button data-tip="0" class="is-on" type="button">{{ ___('None') }}</button>
                        <button data-tip="10" type="button">10%</button>
                        <button data-tip="15" type="button">15%</button>
                        <button data-tip="20" type="button">20%</button>
                        <button data-tip="custom" type="button">{{ ___('Custom') }}</button>
                    </div>
                    <input aria-label="{{ ___('Custom tip amount') }}" type="number" id="cvCustomTip" placeholder="{{ ___('Custom tip amount') }}" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}" hidden style="margin-top:9px">
                </section>
                @endif

                @if(!$customer)
                <section class="cv-block">
                    <label class="terms">
                        <input type="checkbox" id="cvConsentCb" data-csp-toggle="cvCaptureEmailWrap" data-csp-toggle-mode="hidden">
                        <span>{{ ___('Send me offers and updates from this restaurant') }}</span>
                    </label>
                    <div id="cvCaptureEmailWrap" hidden style="padding-top:9px">
                        <input aria-label="{{ ___('your@email.com') }}" type="email" id="cvCaptureEmail" placeholder="{{ ___('your@email.com') }}" autocomplete="email">
                    </div>
                    <label class="terms">
                        <input type="checkbox" id="cvWaOptin">
                        <span>{{ ___('Send me offers and updates on WhatsApp') }}</span>
                    </label>
                </section>
                @endif

                {{-- Order summary — the customer should see WHAT they are paying for at
                     the point of commitment, not just a number. Rendered by renderCheckout. --}}
                <section class="ck-items" id="cvCkItems" hidden style="margin-top:16px"></section>
                <section class="totals cv-block" id="cvCheckoutTotals" style="margin-top:16px"></section>
                <p class="err-box" id="cvFormError" hidden style="margin-top:12px"></p>
                <div class="tail-space tall"></div>
            </div>
            <footer class="p-cta">
                <button class="cta wide" id="cvPlaceOrder" type="button">
                    <span>{{ ___('Place Order') }}</span><b id="cvCheckoutCtaTotal"></b><span class="spinner" hidden></span>
                </button>
            </footer>
        </section>

        {{-- CONFIRMED --}}
        <section class="view view-sub view-center" data-view="confirmed">
            <div class="confirm-wrap">
                <div class="confetti" id="cvConfetti" aria-hidden="true"></div>
                <div class="box-art" aria-hidden="true">
                    <svg class="box-doodles" viewBox="0 0 240 200">
                        <path class="bd" d="M28 40l10 10M38 40l-10 10"/>
                        <circle class="bd" cx="206" cy="36" r="5"/>
                        <path class="bd" d="M210 150c8-2 12 4 8 10"/>
                        <path class="bd" d="M22 140l6 2-2 6"/>
                        <path class="bd bd-star" d="M120 8l2.2 5.4 5.8.5-4.4 3.8 1.3 5.7-4.9-3-4.9 3 1.3-5.7-4.4-3.8 5.8-.5Z"/>
                        <circle class="bd" cx="52" cy="86" r="2.6" fill="currentColor" stroke="none"/>
                        <circle class="bd" cx="196" cy="96" r="2.6" fill="currentColor" stroke="none"/>
                    </svg>
                    <svg class="cbox" viewBox="0 0 200 170">
                        <g class="box-flaps">
                            <path class="flap" d="M40 70 12 44l52-14 24 26Z"/>
                            <path class="flap" d="M160 70l28-26-52-14-24 26Z"/>
                        </g>
                        <path class="box-body" d="M40 70h120v58a8 8 0 0 1-8 8H48a8 8 0 0 1-8-8Z"/>
                        <path class="box-front" d="M40 70 100 84l60-14v14l-60 14-60-14Z"/>
                        <g class="check-badge">
                            <circle cx="100" cy="52" r="26"/>
                            <path class="check" d="M88 52.5l8.5 8.5L114 44" pathLength="1"/>
                        </g>
                    </svg>
                </div>
                <h1 class="confirm-title">{{ ___('Order Confirmed') }}</h1>
                <p class="confirm-sub">{{ ___('Your order has been placed successfully') }}<br>{{ ___('Redirecting you to live tracking…') }}</p>
                {{-- The order number the customer quotes / sees on the track page.
                     submitOrder already returns res.order_id; filled in by JS. --}}
                <p class="confirm-code" id="cvConfirmOrderNo" hidden></p>
                <div class="confirm-actions">
                    <a class="cta wide" id="cvConfirmTrack" href="#"><span>{{ ___('Track Order') }}</span></a>
                    <button class="ghostbtn" data-go="home" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span>{{ ___('Back to Menu') }}</span></button>
                </div>
                <div class="nps">
                    <p>{{ ___('How likely are you to recommend us?') }}</p>
                    <div class="nps-row" id="cvNpsRow">
                        @for($n = 0; $n <= 10; $n++)
                            <button type="button" data-nps="{{ $n }}">{{ $n }}</button>
                        @endfor
                    </div>
                    <p class="nps-thanks" id="cvNpsThanks" hidden>{{ ___('Thanks for the feedback!') }}</p>
                </div>
                @if($googleReviewUrl)
                <a class="greview" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener">
                    <span class="greview-star">⭐</span>
                    <span class="greview-copy">
                        <b>{{ ___('Enjoying your meal?') }}</b>
                        <small>{{ ___('Rate us on Google') }}</small>
                    </span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </a>
                @endif
            </div>
        </section>

        {{-- PROFILE --}}
        <section class="view" data-view="profile">
            <div class="view-scroll">
                <header class="page-head"><h1>{{ ___('Profile') }}</h1></header>
                <div class="me-card">
                    <span class="me-avatar">{{ Str::upper(Str::substr($customer->name ?? $store->title, 0, 1)) }}</span>
                    <div>
                        <b>{{ $customer->name ?? ___('Guest') }}</b>
                        <p class="tk-label">{{ $customer ? $customer->phone ?? $customer->email : ___('Order without an account') }}</p>
                    </div>
                    @if($authMode !== 'guest_only')
                        @if($customer)
                            <button class="minibtn" id="cvLogoutBtn" type="button">{{ ___('Logout') }}</button>
                        @else
                            <button class="minibtn" id="cvMeAuthBtn" type="button">{{ ___('Login') }}</button>
                        @endif
                    @endif
                </div>

                @if($loyaltyProgram && $customer)
                <section class="loyalty" id="cvLoyaltyBlock">
                    <div class="loyalty-head">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 3l2.5 5.5 6 .5-4.5 4 1.4 5.9L12 20.8 6.6 18.9 8 13 3.5 9l6-.5L12 3Z"/></svg>
                        <b>{{ ___('Your Loyalty Balance') }}</b>
                    </div>
                    <div class="loyalty-num"><span id="cvLoyaltyBalance">–</span></div>
                    <p class="loyalty-sub">
                        {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }}
                        / {{ $loyaltyProgram->reward_threshold }} · {{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }}
                    </p>
                    @if($loyaltyProgram->type === 'stamps')
                    <div class="stamp-dots" id="cvStampDots">
                        @for($s = 0; $s < $loyaltyProgram->reward_threshold; $s++)
                            <span class="stamp-dot" data-idx="{{ $s }}"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 3l2.5 5.5 6 .5-4.5 4 1.4 5.9L12 20.8 6.6 18.9 8 13 3.5 9l6-.5L12 3Z"/></svg></span>
                        @endfor
                    </div>
                    @else
                    <div class="loyalty-bar"><i id="cvLoyaltyBar"></i></div>
                    @endif
                    <button class="cta wide" id="cvLoyaltyRedeem" type="button" hidden><span>{{ ___('Redeem Reward') }}</span></button>
                    <p class="loyalty-ok" id="cvLoyaltyOk" hidden>{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
                </section>
                @endif

                @if($reservationsEnabled || $waitlistEnabled)
                <section class="orders-block">
                    <h3 class="cv-h3">{{ ___('Reservations') }}</h3>
                    @if($reservationsEnabled)
                    <button class="order-row" id="cvResOpen" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9 14l2 2 4-4"/></svg>
                        @if($myReservation)
                        <div><b>{{ ___('Reserve a Table') }}</b><small>{{ ___('Booked for :when', ['when' => \Illuminate\Support\Carbon::parse($myReservation->reserved_at)->format('D, M j · g:i A')]) }}</small></div>
                        <span class="row-status ok">{{ $myReservation->status === 'confirmed' ? ___('Confirmed') : ___('Requested') }}</span>
                        @else
                        <div><b>{{ ___('Reserve a Table') }}</b><small>{{ ___('Book a table ahead of time') }}</small></div>
                        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        @endif
                    </button>
                    @endif
                    @if($waitlistEnabled)
                    <button class="order-row" id="cvWaitlistOpen" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M2 20a7 7 0 0 1 14 0M17 11h5M19.5 8.5v5"/></svg>
                        @if($myWaitlist)
                        <div><b>{{ ___('Join the Waitlist') }}</b><small>{{ ___('Party of :n · joined :time', ['n' => $myWaitlist->party_size, 'time' => \Illuminate\Support\Carbon::parse($myWaitlist->created_at)->diffForHumans()]) }}</small></div>
                        <span class="row-status ok">{{ ___('On the list') }}</span>
                        @else
                        <div><b>{{ ___('Join the Waitlist') }}</b><small>{{ ___('Get a spot in the queue') }}</small></div>
                        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        @endif
                    </button>
                    @endif
                </section>
                @endif

                <section class="pref">
                    <h3 class="cv-h3">{{ ___('Preferences') }}</h3>
                    @if(count($menu_languages ?? []) > 1)
                    <div class="pref-row">
                        <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>{{ ___('Language') }}</span>
                        <div class="seg mini">
                            @foreach($menu_languages as $language)
                                <button data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" type="button" class="{{ get_lang() === $language->code ? 'is-on' : '' }}">{{ $language->name }}</button>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
                    <div class="pref-row">
                        <span><svg class="ic" viewBox="0 0 24 24"><path d="M12 3v18M8 7.5C8 6 9.6 5 12 5s4 1 4 2.5S14.5 10 12 10s-4 1-4 2.5S9.5 15 12 15s4 1 4 2.5-1.6 2.5-4 2.5-4-1-4-2.5"/></svg>{{ ___('Currency') }}</span>
                        <div class="seg mini" id="curSeg">
                            <button data-cur="{{ config('settings.currency_code', 'USD') }}" data-rate="1" class="is-on" type="button">{{ config('settings.currency_code', 'USD') }}</button>
                            @foreach($mcRates as $code => $rate)
                                <button data-cur="{{ $code }}" data-rate="{{ $rate }}" type="button">{{ $code }}</button>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </section>

                {{-- Account settings are not order history. They shared the "My Orders"
                     block, so the way to your own details was filed under a heading
                     that does not describe them. --}}
                @if($customer)
                <section class="orders-block">
                    <h3 class="cv-h3">{{ ___('Account') }}</h3>
                    <a class="order-row" href="{{ route('customer.profile', $store->id) }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                        <div><b>{{ ___('Profile & settings') }}</b><small>{{ ___('Details, addresses & preferences') }}</small></div>
                        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </a>
                </section>
                @endif

                <section class="orders-block">
                    <h3 class="cv-h3">{{ ___('My Orders') }}</h3>
                    @if($customer)
                        <a class="order-row" href="{{ route('customer.account', $store->id) }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>
                            <div><b>{{ ___('View order history') }}</b><small>{{ ___('All your orders in one place') }}</small></div>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                    @else
                        @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
                        <p class="sheet-sub">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
                        <div class="coupon-field" style="border-style:solid">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                            <input aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" id="cvLookupInput" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}">
                            <button class="minibtn" id="cvLookupBtn" type="button">{{ ___('Find') }}</button>
                        </div>
                        <div class="orders-list" id="cvLookupResults" style="padding-top:10px"></div>
                    @endif
                </section>

                @if($waNumber)
                <a class="wa-line" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                    <span>{{ ___('Chat with us on WhatsApp') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </a>
                @endif

                {{-- Share this menu — a native profile row instead of a floating pill.
                     Gated on the same share.fallback_button toggle that governs the
                     shared floating fallback (_partials/share); the `share-btn` class
                     is one of the native-share signals that partial stands down on, so
                     the pill removes itself here and this row is the sole share surface.
                     Wired in script.js (navigator.share, or copy-link + toast). --}}
                @if(theme_setting($store->id, 'share.fallback_button'))
                <button class="wa-line share-btn" id="cvShareBtn" type="button"
                        aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg>
                    <span>{{ ___('Share this menu') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                @endif

                {{-- The owner's Terms / Privacy / About / Help. Public pages, so this
                     block is identical for a guest and a signed-in diner. --}}
                <section class="orders-block">
                    <h3 class="cv-h3">{{ ___('Information') }}</h3>
                    @include('store_templates._partials.info-links-inline')
                </section>

                <div class="biz-card">
                    @if($store->address)<span><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</span>@endif
                    @if($store->phone)<span><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><a href="tel:{{ $store->phone }}" style="text-decoration:none">{{ $store->phone }}</a></span>@endif
                </div>
                <p class="demo-foot" data-tc-text="texts.footer_note">{{ theme_setting($store->id, 'texts.footer_note', '') ?: $store->title }}</p>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- B8: catering now lives in a sheet (see #cvCateringSheet below), opened
             from the header — a full request form is a detour from browsing the
             menu, not a section of it. --}}

    </main>

    {{-- ══════════ TABBAR ══════════ --}}
    <nav class="tabbar" id="cvTabbar" aria-label="{{ ___('Menu') }}">
        <button class="tab is-on" data-tab="home" type="button"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg></span><span class="tab-label">{{ ___('Home') }}</span></button>
        <button class="tab" data-tab="menu" type="button"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></span><span class="tab-label">{{ ___('Menu') }}</span></button>
        @if(theme_setting($store->id, 'search.enabled', true))
        <button class="tab" data-tab="search" type="button"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg></span><span class="tab-label">{{ ___('Search') }}</span></button>
        @endif
        @if($allow_order)
        <button class="tab" data-tab="cart" type="button"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg><i class="badge" id="cvCartBadge" hidden>0</i></span><span class="tab-label" data-tc-text="texts.cart_button">{{ theme_setting($store->id, 'texts.cart_button', ___('Cart')) }}</span></button>
        @endif
        <button class="tab" data-tab="favs" type="button"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></span><span class="tab-label">{{ ___('Saved') }}</span></button>
        <button class="tab" data-tab="profile" type="button"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg></span><span class="tab-label">{{ ___('Profile') }}</span></button>
    </nav>

    {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
    <div class="scrim" id="cvScrim" hidden></div>

    {{-- Address map picker — crave-skinned sheet driven by the shared window.mbMapPicker engine. --}}
    <div class="sheet map-sheet" id="cvMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" hidden>
        <div class="sheet-grab"></div>
        <button class="sheet-x" id="cvMapSheetClose" type="button" aria-label="{{ ___('Close') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        <h2>{{ ___('Choose on map') }}</h2>
        <div class="map-canvas" id="cvAddrMap"></div>
        <p class="map-preview" id="cvMapPreview">{{ ___('Move the map to place the pin') }}</p>
        <div class="sheet-actions">
            <button class="cta" id="cvMapConfirm" type="button">{{ ___('Use this location') }}</button>
        </div>
    </div>

    {{-- ══════════ SEARCH OVERLAY (orbit-style) ══════════
         Root-level so position:fixed is not trapped by a transformed SPA view. --}}
    @if(theme_setting($store->id, 'search.enabled', true))
    <div class="cv-searchlayer" id="cvSearchLayer" hidden>
        <div class="cv-sl-top">
            <label class="cv-sl-field">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input id="cvSearchOverlayInput" type="search" placeholder="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dishes or ingredients')) }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
            </label>
            <button class="cv-sl-cancel" id="cvSearchClose" type="button">{{ ___('Cancel') }}</button>
        </div>
        <div class="cv-sl-results" id="cvSearchResults"></div>
    </div>
    @endif

    @if($allowDietary)
    <div class="sheet" id="cvFilterSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Filters') }}</h2>
        <h4>{{ ___('Dietary') }}</h4>
        <div class="chiprow" id="cvDietRow">
            <button data-diet="vegan" type="button">{{ ___('Vegan') }}</button>
            <button data-diet="halal" type="button">{{ ___('Halal') }}</button>
            <button data-diet="gluten_free" type="button">{{ ___('Gluten-free') }}</button>
        </div>
        @if(count($allergies ?? []))
        <h4>{{ ___('Exclude allergens') }}</h4>
        <div class="chiprow" id="cvAllergenRow">
            @foreach($allergies as $a)
                <button data-al="{{ $a->id }}" type="button">{{ $a->title }}</button>
            @endforeach
        </div>
        @endif
        <h4>{{ ___('Max spice') }}</h4>
        <div class="spice-scale" id="cvSpiceScale">
            <button data-spice="3" class="is-on" type="button">🌶🌶🌶</button>
            <button data-spice="2" type="button">🌶🌶</button>
            <button data-spice="1" type="button">🌶</button>
            <button data-spice="0" type="button">{{ ___('Mild') }}</button>
        </div>
        <div class="sheet-actions">
            <button class="ghostbtn" id="cvFilterReset" type="button">{{ ___('Reset') }}</button>
            <button class="cta" id="cvFilterDone" type="button"><span>{{ ___('Show dishes') }}</span><b id="cvFilterCount"></b></button>
        </div>
    </div>
    @endif

    @if($showBranches)
    <div class="sheet" id="cvBranchSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Choose branch') }}</h2>
        <div class="branch-list">
            @foreach($branches as $branch)
                <a class="branch-row {{ ($activeBranch->id ?? null) === $branch->id ? 'is-on' : '' }}"
                   href="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    <b>{{ $branch->name }}</b>
                    <span class="branch-check"></span>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    <div class="sheet" id="cvSortSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Sort by') }}</h2>
        <div class="branch-list" id="cvSortList">
            <button class="branch-row is-on" data-sort="featured" type="button"><b>{{ ___('Featured') }}</b><span class="branch-check"></span></button>
            <button class="branch-row" data-sort="price_asc" type="button"><b>{{ ___('Price: low to high') }}</b><span class="branch-check"></span></button>
            <button class="branch-row" data-sort="price_desc" type="button"><b>{{ ___('Price: high to low') }}</b><span class="branch-check"></span></button>
            <button class="branch-row" data-sort="pop" type="button"><b>{{ ___('Most popular') }}</b><span class="branch-check"></span></button>
        </div>
    </div>

    @if(count($menu_languages ?? []) > 1)
    {{-- B6 · "Following" management sheet (opens when an is-on Follow button is tapped) --}}
    @if($authMode !== 'guest_only' || $customer)
    <div class="sheet" id="cvFollowingSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Following') }}</h2>
        <label class="follow-sheet-row">
            <span>{{ ___('Notifications') }}</span>
            <input type="checkbox" id="cvFollowNotify" role="switch">
        </label>
        <div class="sheet-actions">
            <button class="cta wide danger" id="cvFollowUnfollow" type="button"><span>{{ ___('Unfollow') }}</span></button>
            <button class="cta wide ghost" id="cvFollowCancel" type="button"><span>{{ ___('Cancel') }}</span></button>
        </div>
    </div>
    @endif

    {{-- B8 · About sheet (opens when the brand logo/name is tapped) --}}
    {{-- Catering request, opened from the header button. `cateringInline` tells the
         shared partial not to relocate itself to the bottom of the menu — here the
         sheet is the placement. --}}
    @if(storefront_catering_enabled($store->id))
    <div class="sheet" id="cvCateringSheet" hidden>
        <div class="sheet-grab"></div>
        {{-- Explicit close. Carries `sheet-grab` so the theme's existing
             "grab dismisses its sheet" handler wires it with no JS change; the
             `sheet-x` class restyles it from a drag bar into a corner button. --}}
        <button class="sheet-grab sheet-x" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
    </div>
    @endif

    <div class="sheet" id="cvAboutSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ $store->title }}</h2>
        @if($aboutRating !== null)
            <div class="about-rating"><span class="about-star">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
        @endif
        <div class="about-list">
            @if(!empty($store->timing))
                <div class="about-row"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>{{ $store->timing }}</span></div>
            @endif
            @if(!empty($store->address))
                <a class="about-row" href="https://maps.google.com/?q={{ urlencode($store->address) }}" target="_blank" rel="noopener"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>{{ $store->address }}</span></a>
            @endif
            @if(!empty($store->phone))
                <a class="about-row" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $store->phone) }}"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 5c0 8 7 15 15 15l1-4-5-2-2 2a13 13 0 0 1-5-5l2-2-2-5Z"/></svg><span>{{ $store->phone }}</span></a>
            @endif
            @if($aboutInstagram)
                <a class="about-row" href="{{ $aboutInstagram }}" target="_blank" rel="noopener"><svg class="ic ic-xs" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17" cy="7" r="1"/></svg><span>{{ ___('Instagram') }}</span></a>
            @endif
            @if($aboutFacebook)
                <a class="about-row" href="{{ $aboutFacebook }}" target="_blank" rel="noopener"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v6h4v-6h3l1-4h-4V8Z"/></svg><span>{{ ___('Facebook') }}</span></a>
            @endif
        </div>
        @if($aboutReviews->isNotEmpty())
            <div class="about-reviews">
                @foreach($aboutReviews as $rev)
                    <div class="about-review">
                        <div class="about-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="about-star">★ {{ $rev->stars }}</span></div>
                        @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="sheet" id="cvLangSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Language') }}</h2>
        <div class="branch-list">
            @foreach($menu_languages as $language)
                <button class="branch-row {{ get_lang() === $language->code ? 'is-on' : '' }}" data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" type="button">
                    <b>{{ $language->name }}</b><span class="branch-check"></span>
                </button>
            @endforeach
        </div>
    </div>
    @endif

    @if($authMode !== 'guest_only')
    <div class="sheet" id="cvAuthSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Login') }} / {{ ___('Register') }}</h2>
        <div class="auth-tabs" id="cvAuthTabs">
            <button data-auth-tab="login" class="is-on" type="button">{{ ___('Login') }}</button>
            <button data-auth-tab="otp" type="button">{{ ___('Phone OTP') }}</button>
            <button data-auth-tab="register" type="button">{{ ___('Register') }}</button>
        </div>
        <p class="err-box" id="cvAuthErr" hidden></p>
        <div class="auth-form" data-auth="login">
            <input aria-label="{{ ___('Email') }}" id="cvLoginEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Password') }}" id="cvLoginPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="current-password">
            <button class="cta wide" id="cvLoginBtn" type="button"><span>{{ ___('Login') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
            @include('store_templates.partials.passkey-login', ['store' => $store])
        </div>
        <div class="auth-form" data-auth="otp" hidden>
            <div id="cvOtpStep1">
                <p class="sheet-sub">{{ ___("We'll text you a one-time code") }}</p>
                <input aria-label="{{ ___('Phone number') }}" id="cvOtpPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel">
                <button class="cta wide" id="cvOtpSend" type="button" style="margin-top:11px"><span>{{ ___('Send code') }}</span></button>
            </div>
            <div id="cvOtpStep2" hidden>
                <p class="sheet-sub">{{ ___('Enter the code we sent you') }}</p>
                <div class="otp-boxes otp-6" id="cvOtpBoxes">
                    <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                </div>
                <button class="cta wide" id="cvOtpVerify" type="button" style="margin-top:11px"><span>{{ ___('Verify') }}</span></button>
            </div>
        </div>
        <div class="auth-form" data-auth="register" hidden>
            <input aria-label="{{ ___('Your Name') }}" id="cvRegName" placeholder="{{ ___('Your Name') }}" autocomplete="name">
            <input aria-label="{{ ___('Email') }}" id="cvRegEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Phone number') }}" id="cvRegPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
            <input aria-label="{{ ___('Password') }}" id="cvRegPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
            <input aria-label="{{ ___('Confirm Password') }}" id="cvRegPassConfirm" type="password" placeholder="{{ ___('Confirm Password') }}" autocomplete="new-password">
            <button class="cta wide" id="cvRegisterBtn" type="button"><span>{{ ___('Register') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
        </div>
    </div>
    @endif

    @if($reservationsEnabled)
    <div class="sheet" id="cvResSheet" hidden>
        <div class="sheet-grab"></div>
        {{-- Explicit close (carries sheet-grab for the existing close wiring; the
             sheet-x class restyles it into a corner X button). --}}
        <button class="sheet-grab sheet-x" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2>{{ ___('Reserve a Table') }}</h2>
        @if(session('reservation_success'))
            <div class="sheet-done">
                <span class="sheet-done-emoji">📅</span>
                <b>{{ ___('Request received!') }}</b>
                <p>{{ session('reservation_success') }}</p>
                <p class="muted">{{ ___('We\'ll confirm your booking shortly.') }}</p>
            </div>
        @else
            <form class="sheet-form" action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                @csrf
                <span class="field-label">{{ ___('Your Name') }}</span>
                <input aria-label="{{ ___('Your Name') }}" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}">
                <span class="field-label">{{ ___('Phone number') }}</span>
                <input aria-label="{{ ___('Phone number') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}">
                <span class="field-label">{{ ___('Email') }}</span>
                <input aria-label="{{ ___('your@email.com') }}" name="email" type="email" maxlength="120" value="{{ $customer->email ?? '' }}" placeholder="{{ ___('your@email.com') }}">
                <span class="field-label">{{ ___('Date & Time') }}</span>
                <input aria-label="{{ ___('Date & Time') }}" name="reserved_at" type="datetime-local" required min="{{ now()->format('Y-m-d\TH:i') }}">
                <span class="field-label">{{ ___('Party Size') }}</span>
                <input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                <span class="field-label">{{ ___('Note') }} ({{ ___('optional') }})</span>
                <textarea aria-label="{{ ___('Any special requests?') }}" name="notes" rows="2" maxlength="500" placeholder="{{ ___('Any special requests?') }}"></textarea>
                @include('store_templates._partials.voice-note-recorder')
                @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                <button class="cta wide" type="submit" style="margin-top:14px"><span>{{ ___('Request Reservation') }}</span></button>
            </form>
        @endif
    </div>
    @endif

    @if($waitlistEnabled)
    <div class="sheet" id="cvWaitlistSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Join the Waitlist') }}</h2>
        @if(session('waitlist_joined'))
            <div class="sheet-done">
                <span class="sheet-done-emoji">🎉</span>
                <b>{{ ___("You're on the list!") }}</b>
                <p>{{ ___('Position :pos · ~:min min wait', ['pos' => session('waitlist_joined')['position'], 'min' => session('waitlist_joined')['wait_minutes']]) }}</p>
            </div>
        @else
            <form class="sheet-form" action="{{ route('publicView.waitlist.join', $store->slug) }}" method="POST">
                @csrf
                <span class="field-label">{{ ___('Your Name') }}</span>
                <input aria-label="{{ ___('Your Name') }}" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}">
                <span class="field-label">{{ ___('Phone number') }} ({{ ___('optional') }})</span>
                <input aria-label="{{ ___('Phone number') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}">
                <span class="field-label">{{ ___('Party Size') }}</span>
                <input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                <button class="cta wide" type="submit" style="margin-top:14px"><span>{{ ___('Join Waitlist') }}</span></button>
            </form>
        @endif
    </div>
    @endif

    {{-- F3 · full-screen story viewer overlay --}}
    <div class="story-viewer" id="cvStoryViewer" hidden>
        <div class="sv-progress" id="cvSvProgress"></div>
        <button class="sv-close" id="cvSvClose" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="sv-stage" id="cvSvStage">
            <img class="sv-media" id="cvSvMedia" alt="">
            <button class="sv-nav prev" id="cvSvPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="sv-nav next" id="cvSvNext" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="sv-foot">
                <p class="sv-caption" id="cvSvCaption"></p>
                <a class="cta sv-cta" id="cvSvCta" hidden><span></span></a>
            </div>
        </div>
    </div>

    {{-- F4 · nearest-branch map picker --}}
    @if($showBranches)
    <div class="sheet sheet-tall" id="cvBranchMapSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Nearest branch') }}</h2>
        <div class="branchmap" id="cvBranchMap"></div>
        <p class="geo-attrib" id="cvMapAttrib"></p>
        <div class="branch-list" id="cvBranchGeoList"></div>
    </div>
    @endif

    {{-- F6 · write-review sheet --}}
    <div class="sheet" id="cvReviewSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Write a review') }}</h2>
        <p class="sheet-sub" id="cvReviewItemName"></p>
        <div class="rv-stars" id="cvReviewStars">
            @for($n = 1; $n <= 5; $n++)
                <button data-star="{{ $n }}" type="button" aria-label="{{ $n }}"><svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg></button>
            @endfor
        </div>
        @if(!$customer)
            <input aria-label="{{ ___('Your Name') }}" id="cvReviewName" placeholder="{{ ___('Your Name') }}" autocomplete="name">
        @endif
        <textarea aria-label="{{ ___('Tell others about this dish…') }}" id="cvReviewText" rows="3" placeholder="{{ ___('Tell others about this dish…') }}"></textarea>
        <p class="err-box" id="cvReviewErr" hidden></p>
        <button class="cta wide" id="cvReviewSubmit" type="button"><span>{{ ___('Submit review') }}</span></button>
    </div>

    {{-- F1 · start-a-meal-plan sheet --}}
    @if($mealPlansEnabled)
    <div class="sheet" id="cvMealPlanSheet" hidden data-cadences='@json($mealPlanCadences)'>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Create a meal plan') }}</h2>
        @if($customer)
        <div id="cvMealPlanForm">
            <p class="sheet-sub" id="cvMpItemsSummary"></p>
            <span class="field-label">{{ ___('Plan name') }} ({{ ___('optional') }})</span>
            <input aria-label="{{ ___('e.g. Weekly lunch') }}" id="cvMpName" placeholder="{{ ___('e.g. Weekly lunch') }}">
            <span class="field-label">{{ ___('How often?') }}</span>
            <div class="seg mini" id="cvMpCadence"></div>
            <div id="cvMpDaysWrap" hidden>
                <span class="field-label">{{ ___('Which days?') }}</span>
                <div class="chiprow" id="cvMpDays">
                    @foreach(['Sun'=>0,'Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6] as $d => $i)
                        <button data-day="{{ $i }}" type="button">{{ ___($d) }}</button>
                    @endforeach
                </div>
            </div>
            <span class="field-label">{{ ___('Start date') }}</span>
            <input aria-label="{{ ___('Start date') }}" id="cvMpStart" type="date" min="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}">
            <p class="err-box" id="cvMpErr" hidden></p>
            <button class="cta wide" id="cvMpSubmit" type="button" style="margin-top:12px"><span>{{ ___('Create plan') }}</span></button>
        </div>
        <div class="sheet-done" id="cvMpDone" hidden>
            <span class="sheet-done-emoji">📅</span>
            <b>{{ ___('Meal plan created!') }}</b>
            <p id="cvMpDoneSub"></p>
            <a class="ghostbtn" href="{{ route('customer.account', $store->id) }}">{{ ___('Manage plans') }}</a>
        </div>
        @else
        <p class="sheet-sub">{{ ___('Log in to create a recurring meal plan.') }}</p>
        <button class="cta wide" id="cvMpLoginBtn" type="button"><span>{{ ___('Login') }}</span></button>
        @endif
    </div>
    @endif

    {{-- F7 · OTP checkout gate sheet (dough-style auto-advance) --}}
    <div class="sheet" id="cvCheckoutOtpSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Verify your phone') }}</h2>
        <p class="sheet-sub">{{ ___('Enter the code we sent to') }} <b id="cvOtpGatePhone" dir="ltr"></b></p>
        <div class="otp-boxes otp-6" id="cvOtpGateBoxes">
            <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
        </div>
        <p class="err-box" id="cvOtpGateErr" hidden></p>
        <button class="cta wide" id="cvOtpGateVerify" type="button"><span>{{ ___('Verify & place order') }}</span><span class="spinner" hidden></span></button>
        <button class="linkbtn" id="cvOtpGateResend" type="button">{{ ___('Resend code') }}</button>
    </div>

    <div class="toast" id="cvToast" role="status"></div>
    <div class="fly-layer" id="cvFlyLayer" aria-hidden="true"></div>
    @include('store_templates._partials.notif-bell')
</div>
@endsection

@push('scripts_at_bottom')
{{-- F4 · self-hosted Leaflet (loads before script.js; CSP script-src self+nonce safe) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
<script nonce="{{ csp_nonce() }}">
    window.CRAVE = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        currencySign:   @json($curSign),
        currencyGap:    @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:    @json($curPos),
        currencyDecimals: @json(currency_decimals()),
        moneyDecimals: @json(money_decimals()), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        baseCurrency:   @json(config('settings.currency_code', 'USD')),
        currencyRates:  @json($mcRates),
        taxCharge:      @json($taxCharge),
        taxLabel:       @json($taxLabel),
        /* H9: `restaurant_tax_type` has two modes — a percentage of subtotal, or a
           FLAT amount. Order::computeTax (Order.php:414-429) is the authority and
           honours both; crave used to assume percentage, so a fixed-tax store was
           quoted a percentage of the basket. Every other kept theme already ships
           this key (e.g. orbit/index.blade.php:1151). */
        taxType:        @json((string) (@$postOptions->restaurant_tax_type ?: 'percentage')),
        deliveryCharge: @json($deliveryCharge),
        minOrderValue:  @json($minOrderValue),
        allowOrder:     @json((bool) $allow_order),
        allowPayment:   @json((bool) $allow_payment),
        allowCallWaiter:@json((bool) ($allowWaiter && $allow_on_table)),
        forceTable:     @json((bool) $table),
        defaultTable:   @json($table->table_no ?? null),
        authMode:       @json($authMode),
        isLoggedIn:     @json((bool) $customer),
        customerName:   @json($customer->name ?? null),
        csrf:           @json(csrf_token()),
        loyaltyType:      @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json((int) ($loyaltyProgram->reward_threshold ?? 0)),
        openResSheet:      @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        /* theme-pack-blade features */
        customerToken:     @json($customerApiToken),
        isFollowing:       @json((bool) $isFollowing),
        followNotify:      @json((bool) $followNotify),
        followerCount:     @json((int) $followerCount),
        mealPlansEnabled:  @json((bool) $mealPlansEnabled),
        otpGateEnabled:    @json((bool) $otpGateEnabled),
        leafletImgPath:    @json(asset('assets/vendor/leaflet/images')),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        items:          @json((object) $jsItems),
        lazy:           @json((object) ($cravePaged['lazy'] ?? [])),
        lazyUrl:        @json($cravePaged['url'] ?? ''),
        endpoints: {
            submitOrder:   @json(route('store.submitOrder', $store->id)),
            coupon:      @json(route('store.applyCoupon', $store->id)),
            waiter:      @json(route('store.callTheWaiter', $store->id)),
            review:      @json(route('store.submitReview', $store->slug)),
            nps:         @json(route('store.submitNps', $store->slug)),
            itemEvent:   @json(route('store.recordItemEvent', $store->slug)),
            orderLookup: @json(route('store.orderLookup', $store->slug)),
            follow:      @json(url('r/'.$store->slug.'/follow')),
            stories:     @json(route('stories.public', $store->slug)),
            branchesGeo: @json(route('geo.branches', $store->slug)),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:  @json(route('geo.reverse')),
            reviewsBase: @json((string) store_options($store->id, 'item_reviews_enabled', '1') !== '0' ? url('r/'.$store->id.'/item') : ''),
            mealPlan:    @json(url('customer/meal-plan')),
            otpSend:     @json(route('checkout.otp.send')),
            otpVerify2:  @json(route('checkout.otp.verify')),
            login:       @json(route('customer.login', $store->id)),
            register:    @json(route('customer.register', $store->id)),
            logout:      @json(route('customer.logout', $store->id)),
            otpRequest:  @json(route('customer.otp.request', $store->id)),
            otpVerify:   @json(route('customer.otp.verify', $store->id)),
            loyaltyBalance: @json($loyaltyProgram ? route('publicView.loyalty.balance', $store->id) : null),
            loyaltyRedeem:  @json($loyaltyProgram ? route('publicView.loyalty.redeem', $store->id) : null),
        },
        i18n: {
            popular:       @json(___('Popular')),
            sold_out:      @json(___('Sold out')),
            not_available: @json(___('Not available now')),
            starting_from: @json(___('Starting from')),
            load_more:     @json(___('Load more')),
            added:         @json(___('Added to cart')),
            removed:       @json(___('Removed')),
            remove:        @json(___('Remove')),
            now_following: @json(___('You are now following :store', ['store' => $store->title])),
            action_failed: @json(___('Something went wrong, please try again.')),
            view_stories:  @json(___('View stories')),
            choose:        @json(___('Choose an option')),
            required:      @json(___('Required')),
            optional:      @json(___('Optional')),
            extras:        @json(___('Add extras')),
            subtotal:      @json(___('Subtotal')),
            discount:      @json(___('Discount')),
            delivery:      @json(___('Delivery')),
            tip:           @json(___('Tip')),
            total:         @json(___('Total')),
            order_label:   @json(___('Order')),
            min_order:     @json(___('Minimum order')),
            select_type:   @json(___('Please choose an order type')),
            fill_contact:  @json(___('Please add your name')),
            fill_addr:     @json(___('Please add a delivery address')),
            your_order:    @json(___('Your order')),
            edit:          @json(___('Edit')),
            coupon_bad:    @json(___('Invalid promo code.')),
            waiter_ok:     @json(___('A waiter will be with you shortly!')),
            bill_ok:       @json(___('Your bill is on its way!')),
            rate_dish:     @json(___('Rate this dish')),
            thanks:        @json(___('Thanks!')),
            reviews:       @json(___('Reviews')),
            rv_write:      @json(___('Write')),
            rv_verified:   @json(___('Verified')),
            rv_latest:     @json(___('Latest')),
            rv_detailed:   @json(___('Detailed')),
            rv_empty:      @json(___('No reviews yet — be the first!')),
            cad_daily:     @json(___('Daily')),
            cad_weekly:    @json(___('Weekly')),
            cad_custom:    @json(___('Custom days')),
            mp_next:       @json(___('Next order')),
            loc_unavailable: @json(___('Location unavailable')),
            loc_denied:      @json(___('Location denied')),
            no_address:      @json(___('No address found')),
            network_error:   @json(___('Network error')),
            cart_empty:      @json(___('Cart is empty')),
            search_none:     @json(___('No dishes match your search')),
            no_branches:     @json(___('No branches')),
            delivery_address: @json(___('Delivery address')),
            use_my_location:  @json(___('Use my location')),
        },
    };
</script>
{{-- script.js is loaded once by store_templates.layout (avoids double-load) --}}
@endpush

@push('scripts_at_bottom')
{{-- ZERO-TOUCH A3.1 — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endpush

@push('scripts_at_bottom')
{{-- Address map picker: crave-native sheet (scrim + slide, mirrors the theme's own
     sheet()) driven by the shared window.mbMapPicker engine. Fills the canonical
     checkout address + hidden coords; the cart's inline field mirrors on submit. --}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    if (!window.mbMapPicker) { var b = document.getElementById('cvAddrMapBtn'); if (b) { b.style.display = 'none'; } return; }
    var sheet = document.getElementById('cvMapSheet');
    var scrim = document.getElementById('cvScrim');
    function toggle(open) {
        if (!sheet) { return; }
        if (open) {
            sheet.hidden = false; if (scrim) { scrim.hidden = false; }
            requestAnimationFrame(function () { sheet.classList.add('is-open'); if (scrim) { scrim.classList.add('is-open'); } });
        } else {
            sheet.classList.remove('is-open'); if (scrim) { scrim.classList.remove('is-open'); }
            setTimeout(function () { sheet.hidden = true; if (scrim) { scrim.hidden = true; } }, 420);
        }
    }
    var picker = window.mbMapPicker({
        trigger: document.getElementById('cvAddrMapBtn'),
        modal:   sheet,
        map:     document.getElementById('cvAddrMap'),
        input:   document.getElementById('cvCustAddr'),
        lat:     document.getElementById('cvCustLat'),
        lng:     document.getElementById('cvCustLng'),
        preview: document.getElementById('cvMapPreview'),
        confirm: document.getElementById('cvMapConfirm'),
        close:   document.getElementById('cvMapSheetClose'),
        toggle:  toggle,
        i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
    });
    if (scrim && picker) { scrim.addEventListener('click', function () { if (sheet && sheet.classList.contains('is-open')) { picker.close(); } }); }
    /* keep the cart's inline delivery field in sync with the picked address */
    var custAddr = document.getElementById('cvCustAddr');
    if (custAddr) { custAddr.addEventListener('input', function () { var c = document.getElementById('cvCartAddr'); if (c) { c.value = custAddr.value; } }); }
})();
(function () {
    var btn = document.getElementById('cvClearData');
    if (!btn) { return; }
    if (!window.mbClearDeviceData) { btn.style.display = 'none'; return; }
    btn.addEventListener('click', function () {
        window.mbClearDeviceData({
            slug: @json($store->slug ?? ''),
            storeId: @json($store->id ?? ''),
            confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
        });
    });
})();
</script>
@endpush
