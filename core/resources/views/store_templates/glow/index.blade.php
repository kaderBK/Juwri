{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       glow — glass & glow ordering theme · data preparation
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus   = collect($total_menus)->values();
    $items      = $allMenus->values();
    $categories = $store->menu_categories->values();

    // chips come from top-level categories only, but items are keyed by their own
    // category_id — so a parent whose items all sit in children matches nothing.
    // Map parent id => [child ids] so a chip can claim its subcategories' items.
    // 'subcategories' is already eager-loaded by the menu_categories relation.
    $catKids = $categories->mapWithKeys(fn($c) => [
        (string) $c->id => $c->subcategories->pluck('id')->map(fn($i) => (string) $i)->values(),
    ]);

    // own-item count per real category id (drives the secondary sub-chip row)
    $catItemCount = [];
    foreach ($items as $m) {
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;
    }

    // Secondary sub-chip row: per parent, only the subcategories that own items.
    // A parent whose items sit directly on it (no item-bearing child) gets no
    // second row — its items already show under the parent chip via $catKids.
    $subCatsOf = [];
    foreach ($categories as $cat) {
        $subs = collect($cat->subcategories ?? [])
            ->filter(fn($s) => ($catItemCount[$s->id] ?? 0) > 0)
            ->values();
        if ($subs->isNotEmpty()) {
            $subCatsOf[$cat->id] = $subs;
        }
    }

    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

    $curSign  = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos   = (string) currency_pos_effective($postOptions->id ?? null);
    $curGap   = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only
    $deliveryCharge  = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge       = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $taxType         = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');
    $taxLabel        = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
    $minOrderValue   = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched = !empty($postOptions->allow_order_scheduling);
    $allowCoupons    = @$postOptions->allow_coupons == 1;
    $allowTips       = @$postOptions->allow_tips == 1;
    $allowWaiter     = !empty($postOptions->allow_call_waiter);
    $allowDietary    = !isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;
    $isOpen          = store_open_now($store);

    // `default.png` is the PLATFORM placeholder, not a photo. Blowing it up to a 250px
    // glowing hero is worse than drawing nothing — a brand-new store gets a grey box.
    $featured   = $items->first(fn($m) => $m->image && $m->image !== 'default.png');
    $splashImgs = $items->filter(fn($m) => $m->image && $m->image !== 'default.png')->take(4)->values();

    // Same rule for the logo: a `default.png` logo 404s on this install, so every slot
    // that would render it falls back to a monogram instead.
    $hasLogo   = $store->main_image && $store->main_image !== 'default.png';
    $monogram  = Str::upper(Str::substr(trim((string) $store->title) ?: '?', 0, 1));

    /* helper: human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* helper: the restaurant's OWN name for the variant group ("Style", "Size", …).
       Without this the product page hardcodes "Choose an option" and throws away the
       label the owner typed, while choice groups right below it show theirs. */
    $variantGroupTitle = function ($menu) {
        foreach (($menu->variants ?? []) as $variant) {
            foreach ((array) ($variant->options ?? []) as $optId => $idx) {
                $vo = $menu->variantOptions->firstWhere('id', (int) $optId);
                if ($vo && trim((string) $vo->title) !== '') {
                    return trim((string) $vo->title);
                }
            }
        }
        return null;
    };

    /* JS data map — same contract as aurora (variants absolute, groups/extras additive) */
    $jsItems = [];
    foreach ($items as $m) {
        $itemAllergies = empty($m->allergies)
            ? collect()
            : $allergies->whereIn('id', explode(',', $m->allergies));

        $variants = [];
        if (isset($m->variants)) {
            foreach ($m->variants as $v) {
                $variants[] = ['id' => $v->id, 'label' => $variantLabel($m, $v), 'price' => (float) $v->price];
            }
        }
        $extras = [];
        if (isset($m->extras)) {
            foreach ($m->extras as $e) {
                $extras[] = ['id' => $e->id, 'title' => $e->title, 'price' => (float) $e->price, 'img' => $e->image ? media_url('storage/menu/', $e->image, $store->id ?? null) : ''];
            }
        }

        $jsItems[$m->id] = [
            'id'    => $m->id,
            'cat'   => $m->category_id,
            'name'  => $m->name,
            'desc'  => strip_tags((string) $m->description),
            'img'   => media_url('storage/menu/', $m->image, $store->id ?? null),
            'price' => (float) $m->price,
            'type'  => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'=> $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : '',
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyList($itemAllergies),
            'variants' => $variants,
            'variantTitle' => $variantGroupTitle($m),
            'extras'   => $extras,
            'is_vegan'         => (bool) $m->is_vegan,
            'is_halal'         => (bool) $m->is_halal,
            'is_gluten_free'   => (bool) $m->is_gluten_free,
            'is_popular'       => (bool) $m->is_popular,
            'is_daily_special' => (bool) ($m->is_daily_special ?? false),
            'spice_level'      => (int) $m->spice_level,
            'is_sold_out'      => (bool) $m->is_sold_out,
            'available_from'   => $m->available_from ?? null,
            'available_to'     => $m->available_to ?? null,
            'available_days'   => $m->available_days ?? null,
            'available_now'   => ($m->available_now ?? true),                 // T5: server-resolved (store clock, category window, order type)
            'available_from_label' => ($m->available_from_label ?? null),          // T5: "available from HH:MM"
            'original_price'   => ($m->original_price ?? null),               // T5: pre-happy-hour price, for a strike-through
            'schedule_label'   => ($m->schedule_label ?? null),               // T5: "Happy Hour" badge
            'calories'         => $m->calories ?? null,
            'protein'          => $m->protein ?? null,
            'carbs'            => $m->carbs ?? null,
            'fat'              => $m->fat ?? null,
            'gallery'          => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryUrls($m->gallery_images, $store->id ?? null)
                : [],
            'optionGroups'     => \Juwri\Core\Support\ViewHelpers::optionGroups($m->globalGroups ?? []),
        ];
    }

    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();
    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $customerDefaultAddress = $customerAddresses->first();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);
    // Coupons & Wallets P7-6a: pay-with-credit for a logged-in diner with balance.
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

    /* engagement extras (same backend contract as crave/aurora) */
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));

    /* already-joined / already-booked status for a signed-in diner (matched by phone) */
    $myWaitlist = storefront()->activeWaitlist($store, $customer);
    $myReservation = storefront()->upcomingReservation($store, $customer);

    /* ── theme-pack-blade features (F1–F7) — same contract as crave ──────
       Mint (and session-cache) a raw CustomerToken so the theme JS can call
       the F1/F2/F6 CustomerToken-guarded API on behalf of the session diner. */
    $customerApiToken = storefront()->customerApiToken($customer, 'glow');

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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- F4 · self-hosted Leaflet (nonce/CSP-safe). Only the multi-branch map uses it,
         so a single-branch store no longer pays for it on every page load. --}}
    @if(!empty($branches) && count($branches) > 1 && (@$postOptions->allow_multi_branch_switch ?? 1))
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    @endif
@endpush

@section('content')
<div class="glow" id="glow" style="--glow-brand: {{ theme_setting($store->id, 'colors.primary', '#22c37a') }}">
    <div class="app-aurora" aria-hidden="true"><i></i><i></i><i></i></div>

    @php
        // Cover (crave-parity fold-in). One F10-guarded cover URL for the home
        // hero + the splash blur. default.png = no cover; data-csp-error="hide".
        $__glowHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
        $glowCoverUrl = $__glowHasCover
            ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null)
            : null;
        $glowShowCover = $__glowHasCover && theme_setting($store->id, 'brand.show_cover', true);
        $glowSplashCover = $__glowHasCover && theme_setting($store->id, 'brand.cover_splash', true);
    @endphp

    {{-- ══════════ SPLASH (hero section) ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="splash{{ $glowSplashCover ? ' splash--cover' : '' }}" id="cvSplash">
        {{-- Concept 3: the store cover, blurred + darkened, as splash ambience. --}}
        @if($glowSplashCover)
        @if((string) ($glowCoverUrl) !== '')<img class="cv-splash-cover" src="{{ $glowCoverUrl }}" alt="" aria-hidden="true" data-csp-error="hide">@endif
        @endif
        <div class="splash-minis" aria-hidden="true">
            @foreach($splashImgs->slice(0, 4)->values() as $mi => $mImg)
                @if((string) (media_url('storage/menu/', $mImg->image, $store->id ?? null)) !== '')<img class="mini m-{{ $mi + 1 }}" src="{{ media_url('storage/menu/', $mImg->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
            @endforeach
        </div>
        <div class="splash-brand">
            <span class="brand-ring" data-initial="{{ $monogram }}">
                @if($hasLogo)<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-mono>@endif
            </span>
            <b class="brand-word">{{ Str::upper($store->title) }}</b>
        </div>
        <h1 class="splash-title"><span data-tc-text="texts.hero_title">{{ theme_setting($store->id, 'texts.hero_title', ___('Start fresh with')) }}</span><br><span class="accent">{{ $store->title }}</span></h1>
        <p class="splash-sub" data-tc-text="splash.subtitle">{{ theme_setting($store->id, 'splash.subtitle', ___('Join today to earn your way to free treats')) }}</p>
        <div class="splash-hero">
            <span class="hero-halo"></span>
            @if($featured)
                @if((string) (media_url('storage/menu/', $featured->image, $store->id ?? null)) !== '')<img class="hero-cup" src="{{ media_url('storage/menu/', $featured->image, $store->id ?? null) }}" alt="" data-mono>@endif
            @else
                {{-- no real photography yet: draw the theme's own cup instead of the
                     platform's grey placeholder --}}
                <svg class="sp-art" viewBox="0 0 200 230" aria-hidden="true">
                    <g class="sp-steam"><path d="M84 44c-9-11 9-19 0-30M100 40c-9-13 9-21 0-32M116 44c-9-11 9-19 0-30"/></g>
                    <rect class="sp-cap" x="62" y="66" width="76" height="16" rx="8"/>
                    <path class="sp-lid" d="M52 82h96l-6 16H58Z"/>
                    <path class="sp-body" d="M58 98h84l-10 108a12 12 0 0 1-12 11H80a12 12 0 0 1-12-11Z"/>
                    <path class="sp-band" d="M64 136h72l-3 30H67Z"/>
                </svg>
            @endif
        </div>
        <button class="splash-cta" id="cvSplashSkip" type="button">
            <span class="cta-orb"><svg class="ic" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></span>
            <span>{{ ___('Get started') }}</span>
            <span class="cta-chevs" aria-hidden="true">›››</span>
        </button>
    </div>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="cv-views">

        {{-- HOME --}}
        <section class="view is-active" data-view="home">
            {{-- Collapsing header: overlays the top of the home view (sibling of the
                 scroll area, never scrolls). Slides in once the top hero leaves the
                 scroll container; mirrors the top-chrome buttons (proxied). --}}
            <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
                <span class="cv-condensed-logo" data-initial="{{ $monogram }}">@if($hasLogo)<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-mono>@endif</span>
                <b class="cv-condensed-name">{{ $store->title }}</b>
                <span class="cv-condensed-actions">
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
                </span>
            </div>
            <div class="view-scroll">
                @if($glowShowCover)
                {{-- Concept 1 cover hero: cover img + scrim behind the top chrome +
                     greeting; the media layer is clipped, the chrome overlays it. --}}
                <div class="glow-cover">
                    <div class="glow-cover-media" aria-hidden="true">
                        @if((string) ($glowCoverUrl) !== '')<img class="glow-cover-img" src="{{ $glowCoverUrl }}" alt="" data-csp-error="hide">@endif
                        <span class="glow-cover-scrim"></span>
                    </div>
                @endif
                <header class="home-top">
                    @if($showBranches)
                        <button class="brandbtn" id="cvBranchBtn" type="button" aria-label="{{ ___('Choose branch') }}">@include('store_templates.glow.partials.logo-slot')</button>
                    @else
                        <span class="brandbtn">@include('store_templates.glow.partials.logo-slot')</span>
                    @endif
                    <span class="spacer"></span>
                    @if(count($menu_languages ?? []) > 1)
                        <button class="iconbtn" type="button" data-csp-open-sheet="cvLangSheet" data-csp-scrim="cvScrim" aria-label="{{ ___('Language') }}">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                        </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="iconbtn" id="cvCateringBtn" type="button" data-csp-open-sheet="cvCateringSheet" data-csp-scrim="cvScrim" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    <button class="iconbtn" data-go="profile" type="button" aria-label="{{ ___('Profile') }}">
                        <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="7" width="16" height="13" rx="3"/><path d="M4 11h16M9 7a3 3 0 0 1 6 0"/></svg>
                    </button>
                    {{-- layout toggle moved down beside the menu title (#cvGridBtn) --}}
                    <button class="iconbtn" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                        <i class="dot" id="mbNotifDot" hidden></i>
                    </button>
                </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


                <div class="greet">
                    <h1 class="greet-title" data-tc-text="texts.greeting">{{ theme_setting($store->id, 'texts.greeting', ___('Keep glowing')) }}</h1>
                    <p class="greet-sub" data-tc-text="texts.greeting_sub">{{ theme_setting($store->id, 'texts.greeting_sub', $store->description ? Str::limit(trim(html_entity_decode(strip_tags($store->description), ENT_QUOTES)), 90) : ___('Fresh drinks, made your way.')) }}</p>
                    <div class="status-strip">
                        <span class="open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                        {{-- B2: the "N viewing now" proof moved into the brand/follow row
                             (single source of proof — see the follower-count partial). --}}
                    </div>
                </div>
                @if($glowShowCover)
                </div>{{-- /.glow-cover --}}
                @endif

                {{-- F2 · brand / follow row (B10: customizer section, default on) --}}
                @if($sectionVisible['brand_row'] ?? true)
                <div class="brand-row" id="cvBrandRow" data-tc-section="brand_row" data-mb-hotel-chip-slot>
                    <span class="brand-logo" data-initial="{{ $monogram }}">
                        @if($hasLogo)<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-mono>@endif
                    </span>
                    <span class="brand-info">
                        <b class="brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="verified-badge" id="cvVerifiedBadge" role="img" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
                        @if(trim((string)($store->sub_title ?? '')) !== '')
                        <span class="brand-tagline">{{ $store->sub_title }}</span>
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

                {{-- F3 · stories rail (populated from /r/{slug}/stories) --}}
                <div class="stories-rail" id="cvStoriesRail" hidden></div>

                {{-- search moved down to sit directly above the grid (see the menu
                     section) so a query changes the food in view instead of filtering a
                     list a screen below the header. --}}


                {{-- banners{{-- banners (admin-managed) --}}
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
                                    @if((string) ($banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null)) !== '')<img class="banner-img" src="{{ $banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null) }}" alt="" data-csp-error="closest-class" data-csp-closest=".banner" data-csp-class="no-img" data-csp-remove="true">@endif
                                @endif
                            </a>
                        @endforeach
                    </div>
                    <div class="banner-dots" id="cvBannerDots"></div>
                </div>
                @endif

                {{-- today's specials --}}
                @if($sectionVisible['daily_specials'])
                <section class="rail-block" id="cvSpecialsBlock">
                    <div class="section-head">
                        <h2><span data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___('Seasonal')) }}</span> <span class="oval-tag">{{ ___('picks') }}</span></h2>
                    </div>
                    <div class="rail" id="cvSpecialsRail"></div>
                </section>
                @endif


                {{-- Menu heading sits ABOVE the category selector so the categories +
                     sub-categories read as belonging under this head, and the layout
                     toggle (#cvGridBtn) lives with the grid it controls rather than
                     orphaned up in the topbar. --}}
                @if($sectionVisible['menu'])
                <div class="section-head menu-head" data-mb-grid-lead>
                    <h2 id="cvGridTitle"><span class="gt-brand">{{ $store->title }}</span> <span class="oval-tag" data-tc-text="texts.menu_title">{{ theme_setting($store->id, 'texts.menu_title', ___('Explore')) }}</span></h2>
                    <button class="iconbtn" id="cvGridBtn" type="button" aria-label="{{ ___('Toggle layout') }}">
                        <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/></svg>
                    </button>
                </div>
                @endif

                {{-- category circles --}}
                @if($sectionVisible['catbar'])
                {{-- data-mb-grid-lead: the category selector must touch the items it filters —
     injected sections (offers strip) climb above these rows instead of wedging
     between categories and grid. --}}
                <nav class="cats" id="cvChips" data-mb-grid-lead data-mbt-anchor aria-label="{{ ___('Categories') }}">
                    <button class="cat is-on" data-cat="all" type="button">
                        <span class="cat-ring">
                            @if($hasLogo)
                                @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="replace-emoji" data-csp-text="✦">@endif
                            @else
                                <span class="emoji">✦</span>
                            @endif
                        </span>
                        <span>{{ ___('All') }}</span>
                    </button>
                    @foreach($categories as $cat)
                        <button class="cat" data-cat="{{ $cat->id }}"@if(!empty($subCatsOf[$cat->id])) data-has-sub="1"@endif type="button">
                            <span class="cat-ring">
                                @if($cat->image)
                                    @if((string) (media_url('storage/menu/', $cat->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $cat->image, $store->id ?? null) }}" alt="" loading="lazy" data-csp-error="replace-emoji" data-csp-text="☕">@endif
                                @else
                                    <span class="emoji">☕</span>
                                @endif
                            </span>
                            <span>{{ $cat->name }}</span>
                        </button>
                    @endforeach
                </nav>

                {{-- Secondary sub-category chips. One hidden group per parent that has
                     item-bearing subcategories; JS reveals the active parent's group.
                     "All" (data-sub="root") shows every descendant of the parent. --}}
                @if(!empty($subCatsOf))
                <div class="subchips" id="cvSubChips" data-mb-grid-lead hidden role="group" aria-label="{{ ___('Sub-categories') }}">
                    @foreach($subCatsOf as $parentId => $subs)
                        <div class="subgroup" data-parent="{{ $parentId }}" hidden>
                            <button class="subchip is-on" data-sub="root" type="button">{{ ___('All') }}</button>
                            @foreach($subs as $sub)
                                <button class="subchip" data-sub="{{ $sub->id }}" type="button">
                                    @if($sub->image)<img class="subchip-img" src="{{ media_url('storage/menu/', $sub->image, $store->id ?? null) }}" alt="" loading="lazy" data-csp-error="remove">@endif
                                    {{ $sub->name }}
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @endif
                @endif

                {{-- grid --}}
                @if($sectionVisible['menu'])
                <section class="grid-block">
                    {{-- menu title moved above the categories (see .menu-head) --}}
                    {{-- search + filter sit directly above the grid they act on, so a query
                         or diet filter changes the food in view. --}}
                    @if(theme_setting($store->id, 'search.enabled', true))
                    <div class="searchrow">
                        <label class="search">
                            <input id="cvSearchInput" type="search" aria-label="{{ ___('Search') }}" data-tc-text="texts.search_placeholder" placeholder="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search here')) }} “{{ $store->title }}”" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
                            <span class="search-btn"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg></span>
                        </label>
                        @if($allowDietary)
                        <button class="filterbtn" id="cvFilterBtn" type="button" aria-label="{{ ___('Filters') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M5 6h14M8 12h8M11 18h2"/></svg>
                            <i class="dot" id="cvFilterDot" hidden></i>
                        </button>
                        @endif
                        {{-- Reserve a Table — opens #cvResSheet via the shared
                             data-csp-open-sheet handler (layout.blade.php). --}}
                        @if($reservationsEnabled)
                        <button class="reserve-btn" type="button" data-csp-open-sheet="cvResSheet" data-csp-scrim="cvScrim" aria-label="{{ ___('Reserve a Table') }}" title="{{ ___('Reserve a Table') }}">
                            <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9 14l2 2 4-4"/></svg>
                            <span class="reserve-btn-label">{{ ___('Reserve') }}</span>
                        </button>
                        @endif
                    </div>
                    @endif
                    <div class="grid" id="cvGrid"></div>
                    <div class="grid-empty" id="cvGridEmpty" hidden>
                        <svg viewBox="0 0 64 64" class="empty-art"><circle cx="32" cy="32" r="22"/><path d="M24 28c2-3 6-3 8 0M36 28c2-3 6-3 8 0" stroke-linecap="round"/><path d="M24 42c4-4 12-4 16 0" stroke-linecap="round"/></svg>
                        <p id="cvGridEmptyCopy">{{ ___('Nothing matches — try clearing filters') }}</p>
                        <button class="ghostbtn" id="cvClearFilters" type="button">{{ ___('Clear filters') }}</button>
                    </div>
                </section>
                @endif
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
                {{-- shown by script.js (syncBadge) once the cart has items: a direct jump to the cart --}}
                <button class="p-cart-jump" id="cvCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M3 5h2.2l2.5 10.2a2 2 0 0 0 2 1.5h6.9a2 2 0 0 0 1.95-1.55L20.5 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
                    <i class="p-cart-jump-count" id="cvCartJumpCount" aria-hidden="true">0</i>
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
                            <input type="datetime-local" name="scheduled_at" id="cvScheduledAt" aria-label="{{ ___('Schedule for later') }}" class="when-time" min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}" max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
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
                            <input id="cvCouponInput" aria-label="{{ ___('Promo code') }}" placeholder="{{ ___('Promo code') }}">
                            <button class="minibtn" id="cvCouponApply" type="button">{{ ___('Apply') }}</button>
                        </label>
                        <p class="coupon-msg" id="cvCouponMsg" hidden></p>
                    </section>
                    @endif

                    @if(!empty($postOptions->gift_cards_enabled))
                    <section>
                        <label class="coupon-field">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                            <input id="cvGiftCardInput" name="gift_card_code" aria-label="{{ ___('Gift card code') }}" placeholder="{{ ___('Gift card code') }}">
                        </label>
                    </section>
                    @endif

                    <section class="ordernote">
                        <label for="cvOrderNote"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5V4Z"/><path d="M15 4v4h4M8.5 12h7M8.5 16h5"/></svg>{{ ___('Note') }} ({{ ___('optional') }})</label>
                        <textarea id="cvOrderNote" rows="2" aria-label="{{ ___('Note') }}" placeholder="{{ ___('Any special requests?') }}"></textarea>
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
                    {{-- one label treatment for the whole form, and a real <label for>
                         on every input: placeholder-as-label disappears the moment the
                         customer types and is invisible to a screen reader. --}}
                    <div>
                        <label class="field-label" for="cvCustName">{{ ___('Your Name') }}</label>
                        <input id="cvCustName" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}" autocomplete="name">
                    </div>
                    <div id="cvPhoneField">
                        <label class="field-label" for="cvCustPhone">{{ ___('Phone number') }}</label>
                        <input id="cvCustPhone" type="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
                    </div>
                    <div id="cvTableField" style="display:none">
                        <label class="field-label" for="cvTableInput">{{ ___('Table') }}</label>
                        @if($table)
                            <input id="cvTableInput" value="{{ $table->table_no }}" readonly>
                        @else
                            <input id="cvTableInput" type="number" placeholder="{{ ___('Table number') }}" inputmode="numeric">
                        @endif
                    </div>
                    @if($authMode !== 'guest_only' && !$customer)
                        <button class="linkbtn" id="cvLoginLink" type="button">{{ ___('Have an account?') }} {{ ___('Login') }}</button>
                    @endif
                </section>

                <section class="cv-block" id="cvAddrBlock" style="display:none">
                    <span class="cv-h3">{{ ___('Delivery address') }}</span>
                    {{-- Saved addresses: logged-in customers get their address book; guests
                         get a list JS builds from addresses used before on this device
                         (localStorage) — either way tapping a chip fills the field + coords. --}}
                    @if($customerAddresses->count())
                    <div class="saved-addrs" id="cvSavedAddrs">
                        @foreach($customerAddresses as $sa)
                        <button class="saved-addr{{ $loop->first ? ' is-on' : '' }}" type="button"
                                data-addr="{{ $addrLabel($sa) }}" data-lat="{{ $sa->lat }}" data-lng="{{ $sa->lng }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            <span class="sa-text"><b>{{ $sa->label ?: ___('Saved address') }}</b><small>{{ Str::limit($addrLabel($sa), 42) }}</small></span>
                        </button>
                        @endforeach
                    </div>
                    @else
                    <div class="saved-addrs" id="cvSavedAddrs" hidden></div>
                    @endif
                    {{-- F5 · address autocomplete + use-my-location --}}
                    <label class="field-label" for="cvCustAddr">{{ ___('Delivery address') }}</label>
                    <div class="addr-field" id="cvAddrField">
                        <input id="cvCustAddr" placeholder="{{ ___('Delivery address') }}" autocomplete="off" autocorrect="off" spellcheck="false"
                               value="{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}">
                        <input type="hidden" id="cvCustLat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                        <input type="hidden" id="cvCustLng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                        <button class="uselocbtn" id="cvUseLocation" type="button" title="{{ ___('Use my location') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                        </button>
                        <div class="addr-suggest" id="cvAddrSuggest" hidden></div>
                    </div>
                    <p class="geo-attrib" id="cvGeoAttrib" hidden></p>
                    {{-- Drop-a-pin address picker (shared window.mbMapPicker engine, glow-skinned sheet below). --}}
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
                    <input type="number" id="cvCustomTip" aria-label="{{ ___('Custom tip amount') }}" placeholder="{{ ___('Custom tip amount') }}" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}" hidden style="margin-top:9px">
                </section>
                @endif

                @if(!$customer)
                <section class="cv-block">
                    <label class="terms">
                        <input type="checkbox" id="cvConsentCb" data-csp-toggle="cvCaptureEmailWrap" data-csp-toggle-mode="hidden">
                        <span>{{ ___('Send me offers and updates from this restaurant') }}</span>
                    </label>
                    <div id="cvCaptureEmailWrap" hidden style="padding-top:9px">
                        <input type="email" id="cvCaptureEmail" aria-label="{{ ___('Email') }}" placeholder="{{ ___('your@email.com') }}" autocomplete="email">
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
                <div class="cup-art" aria-hidden="true">
                    <span class="cup-halo"></span>
                    <svg class="cup" viewBox="0 0 200 190">
                        <path class="cup-lid" d="M56 44h88l-5 14H61Z"/>
                        <rect class="cup-cap" x="66" y="30" width="68" height="14" rx="7"/>
                        <path class="cup-body" d="M61 58h78l-9 96a10 10 0 0 1-10 9H80a10 10 0 0 1-10-9Z"/>
                        <path class="cup-band" d="M66 92h68l-2.4 26H68.4Z"/>
                        <g class="check-badge">
                            <circle cx="146" cy="64" r="24"/>
                            <path class="check" d="M135 64.5l7.5 7.5L158 56" pathLength="1"/>
                        </g>
                    </svg>
                    <svg class="box-doodles" viewBox="0 0 240 200">
                        <path class="bd" d="M28 40l10 10M38 40l-10 10"/>
                        <circle class="bd" cx="206" cy="42" r="5"/>
                        <path class="bd" d="M210 150c8-2 12 4 8 10"/>
                        <path class="bd" d="M22 140l6 2-2 6"/>
                        <path class="bd bd-star" d="M120 6l2.2 5.4 5.8.5-4.4 3.8 1.3 5.7-4.9-3-4.9 3 1.3-5.7-4.4-3.8 5.8-.5Z"/>
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
                    <p>{{ ___('How easy was it to order?') }}</p>
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

                {{-- both rows are optional — without this guard the heading renders over an empty section --}}
                @php
                    $hasLangPref = count($menu_languages ?? []) > 1;
                    $hasCurrencyPref = !empty($postOptions->enable_multi_currency) && count($mcRates) > 0;
                @endphp
                @if($hasLangPref || $hasCurrencyPref)
                <section class="pref">
                    <h3 class="cv-h3">{{ ___('Preferences') }}</h3>
                    @if($hasLangPref)
                    <div class="pref-row">
                        <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>{{ ___('Language') }}</span>
                        <div class="seg mini">
                            @foreach($menu_languages as $language)
                                <button data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" type="button" class="{{ get_lang() === $language->code ? 'is-on' : '' }}">{{ $language->name }}</button>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    @if($hasCurrencyPref)
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
                @endif

                <section class="orders-block">
                    <h3 class="cv-h3">{{ ___('My Orders') }}</h3>
                    @if($customer)
                        <a class="order-row" href="{{ route('customer.profile', $store->id) }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                            <div><b>{{ ___('Profile & settings') }}</b><small>{{ ___('Details, addresses & preferences') }}</small></div>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
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
                            <input id="cvLookupInput" aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}">
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

                {{-- Share this menu — native profile row (like the WhatsApp .wa-line),
                     not the floating pill. Gated on the same share.fallback_button
                     toggle; data-web-share makes _partials/share stand down. --}}
                @if(theme_setting($store->id, 'share.fallback_button'))
                <button class="wa-line share-btn" id="mbShareRow" type="button" data-web-share
                        aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg>
                    <span>{{ ___('Share this menu') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                @endif

                {{-- Privacy utility: wipe the cart / favourites / saved addresses this
                     browser keeps (shared window.mbClearDeviceData engine). Lives on the
                     profile view, which guests can open from the tabbar. --}}
                <section class="device-data">
                    <button class="cv-cleardata" id="cvClearData" type="button">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>
                        <span>{{ ___('Clear saved data on this device') }}</span>
                    </button>
                    <p class="device-data-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
                </section>

                <div class="biz-card">
                    @if($store->address)<span><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</span>@endif
                    @if($store->phone)<span><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><a href="tel:{{ $store->phone }}" style="text-decoration:none">{{ $store->phone }}</a></span>@endif
                </div>
                <p class="demo-foot" data-tc-text="texts.footer_note">{{ theme_setting($store->id, 'texts.footer_note', '') ?: $store->title }}</p>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- Catering & Events now lives in #cvCateringSheet (opened from the header
             button); the form is included there with cateringInline=true so it does
             not self-relocate back to the bottom of the menu. --}}


    </main>

    {{-- ══════════ TABBAR ══════════ --}}
    <nav class="tabbar" id="cvTabbar" aria-label="{{ ___('Menu') }}">
        <button class="tab is-on" data-tab="home" type="button" aria-label="{{ ___('Home') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg></span></button>
        <button class="tab" data-tab="menu" type="button" aria-label="{{ ___('Menu') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></span></button>
        @if(theme_setting($store->id, 'search.enabled', true))
        <button class="tab" data-tab="search" type="button" aria-label="{{ ___('Search') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg></span></button>
        @endif
        @if($allow_order)
        <button class="tab" data-tab="cart" type="button" aria-label="{{ theme_setting($store->id, 'texts.cart_button', ___('Cart')) }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg><i class="badge" id="cvCartBadge" hidden>0</i></span></button>
        @endif
        <button class="tab" data-tab="favs" type="button" aria-label="{{ ___('Favorites') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></span></button>
        <button class="tab" data-tab="profile" type="button" aria-label="{{ ___('Profile') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg></span></button>
    </nav>

    {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
    <div class="scrim" id="cvScrim" hidden></div>

    {{-- Address map picker — glow-skinned bottom sheet driven by the shared window.mbMapPicker engine. --}}
    <div class="sheet map-sheet" id="cvMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" hidden>
        <div class="sheet-grab"></div>
        <button class="sheet-x" id="cvMapSheetClose" type="button" aria-label="{{ ___('Close') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        <h2>{{ ___('Choose on map') }}</h2>
        <div class="branchmap map-canvas" id="cvAddrMap"></div>
        <p class="map-preview" id="cvMapPreview">{{ ___('Move the map to place the pin') }}</p>
        <div class="sheet-actions">
            <button class="cta wide" id="cvMapConfirm" type="button"><span>{{ ___('Use this location') }}</span></button>
        </div>
    </div>

    @if($allowDietary)
    <div class="sheet" id="cvFilterSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Filters') }}</h2>
        <h4>{{ ___('Sort by') }}</h4>
        <div class="chiprow" id="cvSortRow">
            <button data-sort="featured" class="is-on" type="button">{{ ___('Featured') }}</button>
            <button data-sort="price_asc" type="button">{{ ___('Price: low to high') }}</button>
            <button data-sort="price_desc" type="button">{{ ___('Price: high to low') }}</button>
            <button data-sort="pop" type="button">{{ ___('Most popular') }}</button>
        </div>
        <h4>{{ ___('Dietary') }}</h4>
        <div class="chiprow" id="cvDietRow">
            <button data-diet="vegan" type="button">{{ ___('Vegan') }}</button>
            <button data-diet="halal" type="button">{{ ___('Halal') }}</button>
            <button data-diet="gluten_free" type="button">{{ ___('Gluten-free') }}</button>
        </div>
        {{-- the coloured dot on every card was never explained anywhere --}}
        <p class="diet-legend"><span class="badge-veg" aria-hidden="true"><i></i></span>{{ ___('Veg') }} <span class="badge-veg nv" aria-hidden="true"><i></i></span>{{ ___('Non-veg') }}</p>
        @if(count($allergies ?? []))
        <h4>{{ ___('Exclude allergens') }}</h4>
        <div class="chiprow" id="cvAllergenRow">
            @foreach($allergies as $a)
                <button data-al="{{ $a->id }}" type="button">{{ $a->title }}</button>
            @endforeach
        </div>
        @endif
        {{-- this control filters `spice_level`. Labelling it "Max caffeine / Decaf"
             (demo copy from the coffee-shop mock) mislabels it for every restaurant. --}}
        <h4>{{ ___('Spice level') }}</h4>
        <div class="spice-scale" id="cvSpiceScale">
            <button data-spice="3" class="is-on" type="button" aria-label="{{ ___('Any') }}">🌶🌶🌶</button>
            <button data-spice="2" type="button">🌶🌶</button>
            <button data-spice="1" type="button">🌶</button>
            <button data-spice="0" type="button">{{ ___('Not spicy') }}</button>
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

    @if(count($menu_languages ?? []) > 1)
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

    {{-- B6 · Following management sheet (opens when the follow button is already on) --}}
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
                <a class="about-row" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $store->phone) }}"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 5c0 8 7 15 15 15l1-4-5-2-2 2a13 13 0 0 1-5-5l2-2-2-5Z"/></svg><span dir="ltr">{{ $store->phone }}</span></a>
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

    @if(storefront_catering_enabled($store->id))
    <div class="sheet" id="cvCateringSheet" hidden>
        <div class="sheet-grab"></div>
        {{-- Explicit close (carries sheet-grab for the existing close wiring; the
             sheet-x class restyles it into a corner X button). --}}
        <button class="sheet-grab sheet-x" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
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
</div>

{{-- Desktop-only companion panel. The app itself stays a 430px column (that IS the
     design), but 850px of bare gradient on a 1280px screen carried no logo, no name,
     no address and no hours. `display:none` below 1024px, so mobile pays nothing.
     Logical insets mirror it automatically in RTL. --}}
<aside class="glow-desk" aria-hidden="true">
    <span class="gd-logo" data-initial="{{ $monogram }}">
        @if($hasLogo)<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-mono>@endif
    </span>
    <h2 class="gd-name">{{ $store->title }}</h2>
    @if($store->description)
        <p class="gd-desc">{{ Str::limit(trim(html_entity_decode(strip_tags($store->description), ENT_QUOTES)), 150) }}</p>
    @endif
    <span class="gd-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
    <div class="gd-facts">
        @if($store->address)<span><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</span>@endif
        @if($store->phone)<span><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg>{{ $store->phone }}</span>@endif
    </div>
    <p class="gd-note">{{ ___('Scan the QR code at your table to order from your phone.') }}</p>
</aside>
@include('store_templates._partials.notif-bell')
@endsection

@push('scripts_at_bottom')
{{-- F4 · self-hosted Leaflet (loads before script.js; CSP script-src self+nonce safe) --}}
{{-- P3-13: totals from the server quote (THEME-CONTRACT §5.2). --}}
<script src="{{ asset('assets/store_templates/_shared/js/mb-quote.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-quote.js')) }}"></script>
@if($showBranches)
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
@endif
<script nonce="{{ csp_nonce() }}">
    window.GLOW = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        catKids:        @json($catKids),
        currencySign:   @json($curSign),
        currencyGap:    @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:    @json($curPos),
        currencyDecimals: @json(currency_decimals()),
        moneyDecimals: @json(money_decimals()), {{-- bug batch 9 · 6: order arithmetic (tip / tax / coupon) at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        baseCurrency:   @json(config('settings.currency_code', 'USD')),
        currencyRates:  @json($mcRates),
        taxCharge:      @json($taxCharge),
        {{-- `restaurant_tax_type` is authoritative: 'fixed' is a FLAT amount, not a rate.
             Without this the theme quotes a percentage of the basket on a flat-tax store. --}}
        taxType:        @json($taxType),
        taxLabel:       @json($taxLabel),
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
        /* theme-pack-blade features (F1–F7) */
        customerToken:     @json($customerApiToken),
        isFollowing:       @json((bool) $isFollowing),
        followerCount:     @json((int) $followerCount),
        followNotify:      @json((bool) $followNotify),
        mealPlansEnabled:  @json((bool) $mealPlansEnabled),
        otpGateEnabled:    @json((bool) $otpGateEnabled),
        leafletImgPath:    @json(asset('assets/vendor/leaflet/images')),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        items:          @json((object) $jsItems),
        endpoints: {
            follow:      @json(url('r/'.$store->slug.'/follow')),
            stories:     @json(route('stories.public', $store->slug)),
            branchesGeo: @json(route('geo.branches', $store->slug)),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:  @json(route('geo.reverse')),
            reviewsBase: @json((string) store_options($store->id, 'item_reviews_enabled', '1') !== '0' ? url('r/'.$store->id.'/item') : ''),
            mealPlan:    @json(url('customer/meal-plan')),
            otpGateSend:   @json(route('checkout.otp.send')),
            otpGateVerify: @json(route('checkout.otp.verify')),
            submitOrder:   @json(route('store.submitOrder', $store->id)),
            coupon:      @json(route('store.applyCoupon', $store->id)),
            waiter:      @json(route('store.callTheWaiter', $store->id)),
            review:      @json(route('store.submitReview', $store->slug)),
            nps:         @json(route('store.submitNps', $store->slug)),
            itemEvent:   @json(route('store.recordItemEvent', $store->slug)),
            orderLookup: @json(route('store.orderLookup', $store->slug)),
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
            added:         @json(___('Added to cart')),
            removed:       @json(___('Removed')),
            remove:        @json(___('Remove')),
            choose:        @json(___('Choose an option')),
            required:      @json(___('Required')),
            optional:      @json(___('Optional')),
            extras:        @json(___('Add extras')),
            subtotal:      @json(___('Subtotal')),
            discount:      @json(___('Discount')),
            delivery:      @json(___('Delivery')),
            tip:           @json(___('Tip')),
            total:         @json(___('Total')),
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
            veg:           @json(___('Veg')),
            nonveg:        @json(___('Non-veg')),
            reviews:       @json(___('Reviews')),
            rv_write:      @json(___('Write')),
            rv_verified:   @json(___('Verified')),
            rv_latest:     @json(___('Latest')),
            rv_detailed:   @json(___('Detailed')),
            rv_empty:      @json(___('No reviews yet — be the first!')),
            track:         @json(___('Track')),
            clear_confirm: @json(___('Tap again to clear')),
            no_match:      @json(___('Nothing matches — try clearing filters')),
            no_results:    @json(___('No dishes match your search')),
            clear_filters: @json(___('Clear filters')),
            clear_search:  @json(___('Clear search')),
            cad_daily:     @json(___('Daily')),
            cad_weekly:    @json(___('Weekly')),
            cad_custom:    @json(___('Custom days')),
            mp_next:       @json(___('Next order')),
            now_following: @json(___('You are now following :store', ['store' => $store->title])),
            action_failed: @json(___('Something went wrong, please try again.')),
            view_stories:  @json(___('View stories')),
            order_label:   @json(___('Order')),
            loc_unavailable: @json(___('Location unavailable')),
            loc_denied:      @json(___('Location denied')),
            no_address:      @json(___('No address found')),
            network_error:   @json(___('Network error')),
            cart_empty:      @json(___('Cart is empty')),
            no_branches:     @json(___('No branches')),
            delivery_address: @json(___('Delivery address')),
            use_my_location:  @json(___('Use my location')),
        },
    };
</script>
{{-- script.js is loaded once by store_templates.layout (avoids double-load) --}}
@endpush

@push('scripts_at_bottom')
@endpush

@push('scripts_at_bottom')
{{-- ZERO-TOUCH A3.1 — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endpush

@push('scripts_at_bottom')
{{-- Address map picker: glow-native sheet (scrim + slide, mirrors the theme's own
     sheet()) driven by the shared window.mbMapPicker engine. Fills the canonical
     checkout address (#cvCustAddr) + hidden coords; the cart's inline field mirrors on input. --}}
<script nonce="{{ csp_nonce() }}">
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
            keys: [
                'mb-cart-' + @json($store->slug ?? ''),
                'cv-favs-' + @json($store->slug ?? ''),
                'gl_recent_orders_' + @json($store->slug ?? ''),
                'gl_recent_addrs_' + @json($store->slug ?? ''),
                'cv_reorder_' + @json($store->slug ?? '')
            ],
            slug: @json($store->slug ?? ''),
            storeId: @json($store->id ?? ''),
            confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
        });
    });
})();
</script>
@endpush
