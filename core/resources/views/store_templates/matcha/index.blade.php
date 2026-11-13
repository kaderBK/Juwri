{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       matcha — light green-wave ordering theme · data preparation
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus   = collect($total_menus)->values();
    $items      = $allMenus->values();
    $categories = $store->menu_categories->values();

    // menu_categories is top-level only (whereNull('parent')) but items carry their own
    // category_id — so a parent whose items all sit in children matches nothing.
    // Map parent id => [child ids] so a chip can claim its subcategories' items.
    // 'subcategories' is already eager-loaded by the menu_categories relation.
    $catKids = $categories->mapWithKeys(fn($c) => [
        (string) $c->id => $c->subcategories->pluck('id')->map(fn($i) => (string) $i)->values(),
    ]);

    /* own-item count per real category id (drives the secondary sub-chip row) */
    $catItemCount = [];
    foreach ($items as $m) {
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;
    }

    /* Secondary sub-chip row: per parent, only the subcategories that own items.
       A parent whose items live directly on it (no item-bearing child) gets no
       second row — its items already show under the parent chip via $catKids. */
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
    $taxLabel        = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
    $minOrderValue   = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched = !empty($postOptions->allow_order_scheduling);
    $allowCoupons    = @$postOptions->allow_coupons == 1;
    $allowTips       = @$postOptions->allow_tips == 1;
    $allowWaiter     = !empty($postOptions->allow_call_waiter);
    $allowDietary    = !isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;
    $isOpen          = store_open_now($store);

    $featured = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    $splashImgs = \Juwri\Core\Support\ViewHelpers::imageItems($items, 3);

    /* Monogram fallback for every logo slot. A store whose logo 404s (the default
       state of a new store) used to be left with empty white discs/squares; the
       initial is painted UNDER the <img> so it simply shows through. */
    $storeInitial = Str::upper(Str::substr(trim((string) $store->title) ?: '·', 0, 1));
    /* 'default.png' is the placeholder the platform hands back for a store that has
       never uploaded a logo — and it 404s. Rendering the <img> anyway leaves a broken
       -image glyph sitting on top of the monogram, so don't render it at all. */
    $hasLogo = !empty($store->main_image) && $store->main_image !== 'default.png';
    $logoUrl = media_url('storage/store/logo/', $store->main_image, $store->id ?? null);

    /* helper: human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* The restaurant names its own modifier group ("Style", "Size", "Roast"…) on the
       MenuVariantOption row. Carry that name to the JS so the product sheet stops
       heading a REQUIRED choice with the generic "Choose an option". */
    $variantGroupTitle = function ($menu) {
        $titles = [];
        foreach (($menu->variants ?? []) as $v) {
            foreach ((array) ($v->options ?? []) as $optId => $idx) {
                $vo = optional($menu->variantOptions)->firstWhere('id', (int) $optId);
                $t = trim((string) ($vo->title ?? ''));
                if ($t !== '' && !in_array($t, $titles, true)) { $titles[] = $t; }
            }
        }
        return implode(' · ', $titles);
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

    /* ── theme-pack-blade features (F1–F7) ──────────────────────────────
       F1/F2 hit the CustomerToken-guarded API; mint (and session-cache) a raw
       token for the signed-in session customer so the theme JS can call them. */
    $customerApiToken = storefront()->customerApiToken($customer, 'matcha');

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
    <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- F4 · self-hosted Leaflet (nonce/CSP-safe; script-src is self+nonce). Tiles load as https imgs.
         Only the nearest-branch picker uses it, so single-branch stores never pay for it. --}}
    @if($showBranches)
        <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    @endif
@endpush

@section('content')
{{-- F12 · desktop surround. Below 1024px this is display:none and costs nothing;
     above it, the ~66% of the screen that was empty gradient carries the
     restaurant's own identity instead. --}}
<aside class="matcha-desk">
    <span class="md-logo" data-initial="{{ $storeInitial }}">
        @if($hasLogo)<img src="{{ $logoUrl }}" alt="" data-mono>@endif
    </span>
    <h2 class="md-title">{{ $store->title }}</h2>
    @if($store->description)
        <p class="md-sub">{{ \Illuminate\Support\Str::limit(strip_tags((string) $store->description), 140) }}</p>
    @endif
    <ul class="md-facts">
        <li><span class="md-open {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span></li>
        @if($store->address)
        <li><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</li>
        @endif
        @if($store->phone)
        <li><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><span dir="ltr">{{ $store->phone }}</span></li>
        @endif
    </ul>
    <p class="md-hint">{{ ___('Browse and order right here — or scan the QR code at your table to carry the menu on your phone.') }}</p>
</aside>
<div class="matcha" id="matcha" style="--matcha-brand: {{ theme_setting($store->id, 'colors.primary', '#067655') }}">

    @php
        // Cover + subtitle (crave-parity fold-in). One F10-guarded cover URL, reused
        // by the wave-header photo and the splash blur. default.png = no cover; every
        // cover img carries data-csp-error="hide" (live media is damaged).
        $__matchaHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
        $matchaCoverUrl = $__matchaHasCover
            ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null)
            : null;
        // Owner toggles (crave-parity): show the cover in the header / behind the splash.
        $matchaShowCover = $__matchaHasCover && theme_setting($store->id, 'brand.show_cover', true);
        $matchaSplashCover = $__matchaHasCover && theme_setting($store->id, 'brand.cover_splash', true);
        $matchaHeroTagline = trim((string) ($store->sub_title ?? ''));
        $matchaSplashSub = trim((string) (theme_setting($store->id, 'texts.hero_subtitle', '') ?: ($store->sub_title ?? '')));
    @endphp

    {{-- ══════════ SPLASH (hero section) ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="splash{{ $matchaSplashCover ? ' splash--cover' : '' }}" id="cvSplash">
        {{-- Concept 3: the store cover, blurred + green-tinted, as splash ambience. --}}
        @if($matchaSplashCover)
        @if((string) ($matchaCoverUrl) !== '')<img class="cv-splash-cover" src="{{ $matchaCoverUrl }}" alt="" aria-hidden="true" data-csp-error="hide">@endif
        @endif
        <span class="brand-badge" data-initial="{{ $storeInitial }}">
            @if($hasLogo)<img src="{{ $logoUrl }}" alt="" data-mono>@endif
        </span>
        <div class="splash-panel">
            @if(count($splashImgs))
                @if(isset($splashImgs[0]))
                    @if((string) (media_url('storage/menu/', $splashImgs[0]->image, $store->id ?? null)) !== '')<img class="sp-cup sp-green" src="{{ media_url('storage/menu/', $splashImgs[0]->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                @endif
                @if(isset($splashImgs[1]))
                    @if((string) (media_url('storage/menu/', $splashImgs[1]->image, $store->id ?? null)) !== '')<img class="sp-cup sp-pink" src="{{ media_url('storage/menu/', $splashImgs[1]->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                @endif
                <span class="ice i1"></span><span class="ice i2"></span><span class="ice i3"></span>
            @else
                {{-- the collage is driven entirely by real dish photos; a store that has
                     not uploaded any used to get a blank green rectangle here --}}
                <svg class="sp-art" viewBox="0 0 200 200" aria-hidden="true">
                    <path class="sa-fill" d="M52 74h96l-9 88a18 18 0 0 1-18 16H79a18 18 0 0 1-18-16Z"/>
                    <path class="sa-line" d="M52 74h96l-9 88a18 18 0 0 1-18 16H79a18 18 0 0 1-18-16Z"/>
                    <path class="sa-line" d="M148 92h13a17 17 0 0 1 0 34h-16"/>
                    <path class="sa-line" d="M74 46c0-10 8-12 8-22M100 44c0-12 9-14 9-24M126 46c0-10 8-12 8-22"/>
                    <path class="sa-leaf" d="M86 112c0-16 13-29 29-29 0 16-13 29-29 29Z" opacity=".85"/>
                    <path class="sa-line" d="M86 112c4-10 11-17 21-21" stroke-width="3.5"/>
                </svg>
            @endif
        </div>
        <h1 class="splash-title" data-tc-text="splash.title">{{ theme_setting($store->id, 'splash.title', ___('Amazing taste, made fresh')) }}</h1>
        {{-- the store subtitle fills the space under the title; texts.hero_subtitle
             overrides it, falling back to the store's own sub_title. --}}
        @if($matchaSplashSub !== '')
        <p class="splash-sub" data-tc-text="texts.hero_subtitle">{{ $matchaSplashSub }}</p>
        @endif
        <button class="pill-black" id="cvSplashSkip" type="button" data-tc-text="texts.view_menu">{{ theme_setting($store->id, 'texts.view_menu', ___('Get started')) }}</button>
    </div>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="cv-views">

        {{-- HOME --}}
        <section class="view is-active" data-view="home">
            {{-- Collapsing header: overlays the top of the home view (sibling of the
                 scroll area, so it never scrolls). Hidden until JS sees the wave leave
                 the scroll container; mirrors the wave's utility buttons (proxied). --}}
            <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
                <span class="cv-condensed-logo" data-initial="{{ $storeInitial }}">@if($hasLogo)<img src="{{ $logoUrl }}" alt="" data-mono>@endif</span>
                <b class="cv-condensed-name">{{ $store->title }}</b>
                <span class="cv-condensed-actions">
                    @if(count($menu_languages ?? []) > 1)
                    <button class="wave-ic" type="button" data-cv-proxy="lang" aria-label="{{ ___('Language') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="wave-ic" type="button" data-cv-proxy="catering" aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    <button class="wave-ic" type="button" data-cv-proxy="notif" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                    </button>
                </span>
            </div>
            <div class="view-scroll">
                <header class="wave{{ $matchaShowCover ? ' wave--cover' : '' }}" aria-label="Top">
                    @if($matchaShowCover)
                    {{-- Cover photo fills the header; the green wave becomes a
                         translucent scrim over it (keeps matcha's signature curve). --}}
                    @if((string) ($matchaCoverUrl) !== '')<img class="wave-cover" src="{{ $matchaCoverUrl }}" alt="" aria-hidden="true" data-csp-error="hide">@endif
                    @endif
                    <svg class="wave-bg" viewBox="0 0 400 150" preserveAspectRatio="none" aria-hidden="true">
                        <path d="M0 0h400v86c-46 26-96 8-150 22s-96 44-150 26C60 121 22 128 0 108Z"/>
                    </svg>
                    {{-- crave-parity: the avatar opens the customer's PROFILE, so it shows
                         the customer (initial signed-in, person glyph as guest) — not the
                         store logo (which still lives on the brand row below). --}}
                    <button class="wave-avatar{{ $customer ? ' is-me' : '' }}" data-go="profile" type="button" aria-label="{{ ___('Profile') }}" data-initial="{{ $customer ? Str::upper(Str::substr($customer->name ?: $customer->phone ?: '?', 0, 1)) : '' }}">
                        @if(!$customer)
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                        @endif
                    </button>
                    @if($showBranches)
                    {{-- branch selector (crave .loc parity) — opens the plain branch sheet --}}
                    <button class="wave-loc" type="button" data-csp-open-sheet="cvBranchSheet" data-csp-scrim="cvScrim" aria-label="{{ ___('Choose branch') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <b>{{ $activeBranch->name ?? $branches->first()->name ?? $store->title }}</b>
                        <svg class="ic chev" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    @endif
                    <span class="wave-actions">
                        {{-- filters moved down next to the search, directly above the grid --}}
                        @if(count($menu_languages ?? []) > 1)
                        <button class="wave-ic" id="cvLangBtn" type="button" aria-label="{{ ___('Language') }}">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                        </button>
                        @endif
                        @if(storefront_catering_enabled($store->id))
                        <button class="wave-ic" id="cvCateringBtn" type="button" data-csp-open-sheet="cvCateringSheet" data-csp-scrim="cvScrim" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                        </button>
                        @endif
                        <button class="wave-ic" data-go="favs" type="button" aria-label="{{ ___('Favorites') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
                        </button>
                        @if($allow_order)
                        <button class="wave-ic" data-go="cart" type="button" aria-label="{{ ___('Cart') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg>
                            <i class="badge" id="cvCartBadge" hidden>0</i>
                        </button>
                        @endif
                        <style nonce="{{ csp_nonce() }}">#mbNotifDot{position:absolute;top:5px;inset-inline-end:5px;width:8px;height:8px;border-radius:50%;background:var(--gold);outline:2px solid var(--green)}</style>
                        <button class="wave-ic" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                            <i class="dot" id="mbNotifDot" hidden></i>
                        </button>
                        {{-- Light/dark toggle (Lane B) — bound by the shared engine (_shared/js/mb-theme.js) --}}
                        <button class="wave-ic" type="button" data-mb-theme-toggle
                                aria-pressed="false" aria-label="{{ ___('Toggle dark mode') }}">
                            <svg class="ic mb-th-moon" viewBox="0 0 24 24"><path d="M20.5 14.4A8.5 8.5 0 1 1 9.6 3.5a6.8 6.8 0 0 0 10.9 10.9Z"/></svg>
                            <svg class="ic mb-th-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.6 12h2.2M19.2 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
                        </button>
                    </span>
                </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


                <div class="home-pad">
                {{-- search moved down to sit directly above the grid (see the menu
                     section) so a query changes the food in view instead of filtering a
                     list two screens below the header. --}}
                <div class="status-strip">
                    <span class="open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                    {{-- B2: the "N viewing now" proof moved into the brand/follow row
                         (single source of proof — see the follower-count partial). --}}
                </div>

                {{-- F2 · brand / follow row (B10: customizer section, default on) --}}
                @if($sectionVisible['brand_row'] ?? true)
                <div class="brand-row" id="cvBrandRow" data-tc-section="brand_row" data-mb-hotel-chip-slot>
                    <span class="brand-logo" data-initial="{{ $storeInitial }}">
                        @if($hasLogo)<img src="{{ $logoUrl }}" alt="" data-mono>@endif
                    </span>
                    <span class="brand-info">
                        <b class="brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="verified-badge" id="cvVerifiedBadge" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
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
                                    {{-- a 404 banner image would otherwise leave a shadowed blob over the copy — drop it and let the copy reflow --}}
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
                        <h2>{{ ___('New Menu') }}</h2>
                        <button class="viewall" id="cvViewAllNew" type="button">{{ ___('View All') }}</button>
                    </div>
                    <div class="rail" id="cvSpecialsRail"></div>
                </section>
                @endif


                </div>{{-- /.home-pad --}}

                {{-- featured quick-add blob --}}
                <section class="blob" id="cvFeatBlob" hidden>
                    <div class="blob-head">
                        <h2 data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___('Special for you')) }}</h2>
                        <button class="viewall light" id="cvViewAllSpecial" type="button">{{ ___('View All') }}</button>
                    </div>
                    <div class="blob-body">
                        <div class="blob-thumbs" id="cvFeatThumbs"></div>
                        <div class="blob-main">
                            {{-- no src= until initFeat() fills it: an empty src makes the browser refetch the page URL --}}
                            <img class="blob-img" id="cvFeatImg" alt="">
                            <div class="blob-info">
                                <b id="cvFeatName"></b>
                                <span class="blob-price" id="cvFeatPrice"></span>
                            </div>
                        </div>
                    </div>
                    @if($allow_order)
                    <div class="blob-actions">
                        <div class="qty light" id="cvFeatQty">
                            <button class="qty-btn" data-fq="-1" type="button" aria-label="{{ ___('Decrease quantity') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
                            <output id="cvFeatQtyVal">1</output>
                            <button class="qty-btn" data-fq="1" type="button" aria-label="{{ ___('Increase quantity') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
                        </div>
                        <button class="pill-black" id="cvFeatAdd" type="button">{{ ___('Add To Order') }}</button>
                    </div>
                    @endif
                </section>

                <div class="home-pad">
                {{-- Category selector sits DIRECTLY above the menu it filters (per the
                     4-theme request: nothing — no stories/banners/specials/offers strip —
                     wedged between the categories and the items). data-mb-grid-lead keeps
                     injected sections above this row rather than between it and the grid.
                     (Supersedes the earlier "keep categories above the fold" placement.) --}}
                {{-- Menu heading sits ABOVE the category selector so the categories +
                     sub-categories read as belonging under this head (was below them,
                     inside the grid block). --}}
                @if($sectionVisible['menu'])
                <div class="section-head" data-mb-grid-lead>
                    <h2 id="cvGridTitle" data-tc-text="texts.menu_title">{{ theme_setting($store->id, 'texts.menu_title', ___('All Menu')) }}</h2>
                </div>
                @endif
                @if($sectionVisible['catbar'])
                <nav class="cats" id="cvChips" data-mb-grid-lead data-mbt-anchor aria-label="{{ ___('Categories') }}">
                    <button class="cat is-on" data-cat="all" type="button">
                        <span class="cat-ring">
                            @if($hasLogo)<img src="{{ $logoUrl }}" alt="" data-mono>@endif
                            <span class="emoji">✦</span>
                        </span>
                        <span>{{ ___('All') }}</span>
                    </button>
                    @foreach($categories as $cat)
                        <button class="cat" data-cat="{{ $cat->id }}" @if(!empty($subCatsOf[$cat->id])) data-has-sub="1" @endif type="button">
                            <span class="cat-ring">
                                @if($cat->image && $cat->image !== 'default.png')
                                    @if((string) (media_url('storage/menu/', $cat->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $cat->image, $store->id ?? null) }}" alt="" loading="lazy" data-mono>@endif
                                @endif
                                <span class="emoji">☕</span>
                            </span>
                            <span>{{ $cat->name }}</span>
                        </button>
                    @endforeach
                </nav>
                @if(!empty($subCatsOf))
                <nav class="subcats" id="cvSubChips" data-mb-grid-lead hidden aria-label="{{ ___('Sub-categories') }}">
                    @foreach($subCatsOf as $parentId => $subs)
                        <div class="subgroup" data-parent="{{ $parentId }}" hidden>
                            <button class="subchip is-on" data-sub="root" type="button">{{ ___('All') }}</button>
                            @foreach($subs as $sub)
                                <button class="subchip" data-sub="{{ $sub->id }}" type="button">
                                    @if($sub->image)<img class="subchip-img" src="{{ media_url('storage/menu/', $sub->image, $store->id ?? null) }}" alt="" loading="lazy" data-mono>@endif
                                    {{ $sub->name }}
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </nav>
                @endif
                @endif

                {{-- grid --}}
                @if($sectionVisible['menu'])
                <section class="grid-block">
                    {{-- menu title moved above the categories (see the section-head above) --}}
                    {{-- search + filter sit directly above the grid they act on, so a query
                         or diet filter changes the food in view. --}}
                    @if(theme_setting($store->id, 'search.enabled', true))
                    <div class="searchrow">
                        <label class="search">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                            <span class="sr-only">{{ ___('Search') }}</span>
                            <input id="cvSearchInput" type="search" data-tc-text="texts.search_placeholder" placeholder="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search here')) }}…" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
                        </label>
                        @if($allowDietary)
                        <button class="filterbtn" id="cvFilterBtn" type="button" aria-label="{{ ___('Filters') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M5 8h14M5 12h10M5 16h6"/></svg>
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
                        <p>{{ ___('Nothing matches — try clearing filters') }}</p>
                        <button class="ghostbtn" id="cvClearFilters" type="button">{{ ___('Clear filters') }}</button>
                    </div>
                </section>
                @endif
                </div>{{-- /.home-pad --}}
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- PRODUCT --}}
        <section class="view view-sub" data-view="product">
            <div class="view-scroll">
                <div class="p-hero">
                    <div class="p-gallery" id="pGallery"></div>
                    {{-- Desktop/pointer users can't swipe — these arrows step through a
                         multi-image item. Hidden until JS finds >1 image. --}}
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
                <div class="rail-block p-related" id="cvRelatedBlock">
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
                     beside Add to Cart so the guest doesn't have to hunt for it. --}}
                <button class="cta-view-cart" id="pViewCart" data-go="cart" type="button" hidden aria-label="{{ ___('View cart') }}">
                    <svg class="ic" viewBox="0 0 24 24"><circle cx="9.5" cy="19.5" r="1.6"/><circle cx="17" cy="19.5" r="1.6"/><path d="M3 4h2l2.3 11h10.4L20 8H6.1"/></svg>
                    <i class="pvc-badge" id="pViewCartCount">0</i>
                </button>
            </footer>
            @endif
        </section>

        {{-- FAVORITES --}}
        <section class="view view-sub" data-view="favs">
            <div class="view-scroll">
                <header class="page-head sub"><button class="fab back inline" data-back aria-label="{{ ___('Back to Menu') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button><h1>{{ ___('Favorites') }}</h1></header>
                <div class="grid" id="cvFavGrid"></div>
                <div class="grid-empty" id="cvFavEmpty" hidden>
                    <svg viewBox="0 0 64 64" class="empty-art"><path d="M32 50s-18-11-18-24c0-7 5.4-12 11.2-12 3.4 0 5.6 2 6.8 3.8C33.2 16 35.4 14 38.8 14 44.6 14 50 19 50 26c0 13-18 24-18 24Z"/></svg>
                    <p>{{ ___('Tap the heart on any dish to save it here') }}</p>
                </div>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- CART --}}
        <section class="view view-sub" data-view="cart">
            <div class="view-scroll">
                <header class="page-head sub">
                    <button class="fab back inline" data-back aria-label="{{ ___('Back to Menu') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1>{{ ___('My Cart') }}</h1>
                    <span class="spacer"></span>
                    <button class="ghostbtn sm" id="cvClearCart" type="button">{{ ___('Clear') }}</button>
                </header>
                <div id="cvCartList" class="cart-list"></div>
                <div class="grid-empty" id="cvCartEmpty" hidden>
                    <svg viewBox="0 0 64 64" class="empty-art"><path d="M14 18h6l5 24h22l5-18H24"/><circle cx="28" cy="50" r="3.4"/><circle cx="44" cy="50" r="3.4"/></svg>
                    <p>{{ ___('Your cart is empty') }}</p>
                    <button class="ghostbtn" data-go="home" type="button">{{ ___('Browse menu') }}</button>
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
                        <label class="curbside-field">
                            <input type="checkbox" name="curbside" value="1" id="cvCurbside">
                            <span>{{ ___("Curbside pickup — I'll wait in my car") }}</span>
                        </label>
                        <div id="cvCurbsideFields" class="curbside-fields" hidden>
                            <input type="text" name="vehicle_make" id="cvVehicleMake" placeholder="{{ ___('Car make/model') }}" aria-label="{{ ___('Car make/model') }}">
                            <input type="text" name="vehicle_color" id="cvVehicleColor" placeholder="{{ ___('Colour') }}" aria-label="{{ ___('Colour') }}">
                            <input type="text" name="vehicle_plate" id="cvVehiclePlate" placeholder="{{ ___('Plate') }}" aria-label="{{ ___('Plate') }}">
                        </div>
                    </section>
                    @endif

                    @if($allowCoupons)
                    <section>
                        <label class="coupon-field">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                            <input id="cvCouponInput" placeholder="{{ ___('Promo code') }}" aria-label="{{ ___('Promo code') }}">
                            <button class="minibtn" id="cvCouponApply" type="button">{{ ___('Apply') }}</button>
                        </label>
                        <p class="coupon-msg" id="cvCouponMsg" hidden></p>
                    </section>
                    @endif

                    @if(!empty($postOptions->gift_cards_enabled))
                    <section>
                        <label class="coupon-field">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                            <input id="cvGiftCardInput" name="gift_card_code" placeholder="{{ ___('Gift card code') }}" aria-label="{{ ___('Gift card code') }}">
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
                    <label class="field-label" for="cvCustName">{{ ___('Your Name') }}</label>
                    <input id="cvCustName" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}" autocomplete="name">
                    <div id="cvPhoneField">
                        <label class="field-label" for="cvCustPhone">{{ ___('Phone number') }}</label>
                        <input id="cvCustPhone" type="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
                    </div>
                    <div id="cvTableField" class="is-off">
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

                <section class="cv-block is-off" id="cvAddrBlock">
                    <span class="cv-h3">{{ ___('Delivery address') }}</span>
                    {{-- Saved addresses: logged-in customers get their address book;
                         guests get a list JS builds from addresses used before on this
                         device (localStorage), so a returning guest can pick a previous
                         address instead of retyping. Tapping a chip fills field + coords. --}}
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
                    <label class="field-label" for="cvCustAddr">{{ ___('Street, building, city') }}</label>
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
                    {{-- Drop-a-pin address picker (shared window.mbMapPicker engine, matcha-skinned sheet below). --}}
                    <button class="nearest-branch-btn cv-mapbtn" id="cvAddrMapBtn" type="button">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        {{ ___('Choose on map') }}
                    </button>
                    @if($showBranches)
                    {{-- Opens the plain "Choose branch" picker (#cvBranchSheet), which
                         lists EVERY branch and switches via ?branch_id=. The geo-map path
                         only plotted branches that carry coordinates, so a branch without
                         lat/lng was invisible and the switch felt unimplemented. --}}
                    <button class="nearest-branch-btn" id="cvBranchBtn" type="button">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        {{ ___('Choose branch') }}
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
                    <label class="field-label" for="cvCustomTip" id="cvCustomTipLabel" hidden>{{ ___('Custom tip amount') }}</label>
                    <input type="number" id="cvCustomTip" placeholder="{{ ___('Custom tip amount') }}" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}" hidden>
                </section>
                @endif

                @if(!$customer)
                <section class="cv-block">
                    <label class="terms">
                        <input type="checkbox" id="cvConsentCb">
                        <span>{{ ___('Send me offers and updates from this restaurant') }}</span>
                    </label>
                    <div id="cvCaptureEmailWrap" class="capture-email" hidden>
                        <label class="field-label" for="cvCaptureEmail">{{ ___('Email') }}</label>
                        <input type="email" id="cvCaptureEmail" placeholder="{{ ___('your@email.com') }}" autocomplete="email">
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
                <section class="totals cv-block checkout-totals" id="cvCheckoutTotals"></section>
                <p class="err-box checkout-err" id="cvFormError" hidden></p>
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
        <section class="view view-sub" data-view="profile">
            <div class="view-scroll">
                <header class="page-head sub"><button class="fab back inline" data-back aria-label="{{ ___('Back to Menu') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button><h1>{{ ___('Profile') }}</h1></header>
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

                @php $hasPrefs = count($menu_languages ?? []) > 1 || (!empty($postOptions->enable_multi_currency) && count($mcRates) > 0); @endphp
                @if($hasPrefs)
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
                        <div class="coupon-field lookup-field">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                            <input id="cvLookupInput" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}">
                            <button class="minibtn" id="cvLookupBtn" type="button">{{ ___('Find') }}</button>
                        </div>
                        <div class="orders-list lookup-results" id="cvLookupResults"></div>
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
                     toggle; data-web-share makes _partials/share stand down. matcha's
                     .ic is fill-based, so this uses a filled share glyph. --}}
                @if(theme_setting($store->id, 'share.fallback_button'))
                <button class="wa-line share-btn" id="mbShareRow" type="button" data-web-share
                        aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81a3 3 0 1 0-3-3c0 .24.04.47.09.7L8.04 9.81A3 3 0 1 0 6 15c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65a2.92 2.92 0 1 0 2.92-2.92z"/></svg>
                    <span>{{ ___('Share this menu') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                @endif

                {{-- Privacy utility: wipe the cart / favourites / saved addresses this
                     browser keeps (shared window.mbClearDeviceData engine). Lives on the
                     profile surface, which guests can open from the header avatar. --}}
                <section class="device-data">
                    <button class="cv-cleardata" id="cvClearData" type="button">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>
                        <span>{{ ___('Clear saved data on this device') }}</span>
                    </button>
                    <p class="device-data-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
                </section>

                <div class="biz-card">
                    @if($store->address)<span><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</span>@endif
                    @if($store->phone)<span><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><a href="tel:{{ $store->phone }}" class="plain-link">{{ $store->phone }}</a></span>@endif
                </div>
                <p class="demo-foot" data-tc-text="texts.footer_note">{{ theme_setting($store->id, 'texts.footer_note', '') ?: $store->title }}</p>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- Catering & Events now lives in #cvCateringSheet (opened from the header
             button); the form is included there with cateringInline=true so it does
             not self-relocate back to the bottom of the menu. --}}


    </main>

    {{-- ══════════ FLOATING DOCK ══════════ --}}
    {{-- Standardized floating navbar: home · menu · search · cart · favs · profile.
         menu/search are not views — they scroll the home grid / focus the search
         input (handled in script.js). home/cart/favs/profile route through show().
         The dock lives OUTSIDE .cv-views so it floats over the home scroll; it hides
         on every sub-view (product/cart/favs/checkout/profile) which carry their own
         back button + footer CTA. --}}
    <nav class="mc-dock" id="mcDock" aria-label="{{ ___('Menu') }}">
        <button class="mc-dock-btn is-on" data-dock="home" type="button" aria-label="{{ ___('Home') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h5v-6h2v6h5v-9"/></svg>
            <span>{{ ___('Home') }}</span>
        </button>
        <button class="mc-dock-btn" data-dock="menu" type="button" aria-label="{{ ___('Menu') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span>{{ ___('Menu') }}</span>
        </button>
        <button class="mc-dock-btn" data-dock="search" type="button" aria-label="{{ ___('Search') }}">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            <span>{{ ___('Search') }}</span>
        </button>
        @if($allow_order)
        <button class="mc-dock-btn" data-dock="cart" type="button" aria-label="{{ ___('Cart') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg>
            <i class="mc-dock-badge" id="mcDockCartBadge" hidden>0</i>
            <span>{{ ___('Cart') }}</span>
        </button>
        @endif
        <button class="mc-dock-btn" data-dock="favs" type="button" aria-label="{{ ___('Favorites') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
            <span>{{ ___('Favorites') }}</span>
        </button>
        <button class="mc-dock-btn" data-dock="profile" type="button" aria-label="{{ ___('Profile') }}">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
            <span>{{ ___('Profile') }}</span>
        </button>
    </nav>

    {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
    <div class="scrim" id="cvScrim" hidden></div>

    {{-- Address map picker — matcha-skinned bottom sheet driven by the shared window.mbMapPicker engine. --}}
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
        {{-- F30: the 8px dot on every card was an unexplained colour. Name it. --}}
        <p class="diet-legend"><span><i></i>{{ ___('Veg') }}</span><span><i class="nv"></i>{{ ___('Non-veg') }}</span></p>
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
        <h4>{{ ___('Max caffeine') }}</h4>
        <div class="spice-scale" id="cvSpiceScale">
            <button data-spice="3" class="is-on" type="button">☕☕☕</button>
            <button data-spice="2" type="button">☕☕</button>
            <button data-spice="1" type="button">☕</button>
            <button data-spice="0" type="button">{{ ___('Decaf') }}</button>
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
                <button class="cta wide mt-md" id="cvOtpSend" type="button"><span>{{ ___('Send code') }}</span></button>
            </div>
            <div id="cvOtpStep2" hidden>
                <p class="sheet-sub">{{ ___('Enter the code we sent you') }}</p>
                <div class="otp-boxes otp-6" id="cvOtpBoxes">
                    <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                </div>
                <button class="cta wide mt-md" id="cvOtpVerify" type="button"><span>{{ ___('Verify') }}</span></button>
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
                <button class="cta wide mt-lg" type="submit"><span>{{ ___('Request Reservation') }}</span></button>
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
                <button class="cta wide mt-lg" type="submit"><span>{{ ___('Join Waitlist') }}</span></button>
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
            <button class="cta wide mt-md" id="cvMpSubmit" type="button"><span>{{ ___('Create plan') }}</span></button>
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
@if($showBranches)
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
@endif
<script nonce="{{ csp_nonce() }}">
    window.MATCHA = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        currencySign:   @json($curSign),
        currencyGap:    @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:    @json($curPos),
        currencyDecimals: @json(currency_decimals()),
        moneyDecimals: @json(money_decimals()), {{-- bug batch 9 · 6: order arithmetic (tip / tax / coupon) at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        baseCurrency:   @json(config('settings.currency_code', 'USD')),
        currencyRates:  @json($mcRates),
        taxCharge:      @json($taxCharge),
        taxType:        @json((string) (@$postOptions->restaurant_tax_type ?: 'percentage')),
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
        /* theme-pack-blade features */
        customerToken:     @json($customerApiToken),
        isFollowing:       @json((bool) $isFollowing),
        followerCount:     @json((int) $followerCount),
        followNotify:      @json((bool) $followNotify),
        mealPlansEnabled:  @json((bool) $mealPlansEnabled),
        otpGateEnabled:    @json((bool) $otpGateEnabled),
        leafletImgPath:    @json(asset('assets/vendor/leaflet/images')),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        catKids:        @json($catKids),
        items:          @json((object) $jsItems),
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
            confirm_clear: @json(___('Tap again to clear')),
            track:         @json(___('Track')),
            order_label:   @json(___('Order')),
            saved_address: @json(___('Saved address')),
            use_my_location: @json(___('Use my location')),
            loc_unavailable: @json(___('Location unavailable')),
            loc_denied:    @json(___('Location denied')),
            no_address:    @json(___('No address found')),
            network_error: @json(___('Network error')),
            cart_empty:    @json(___('Cart is empty')),
            no_branches:   @json(___('No branches')),
            delivery_address: @json(___('Delivery address')),
            veg:           @json(___('Veg')),
            nonveg:        @json(___('Non-veg')),
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
            now_following: @json(___('You are now following :store', ['store' => $store->title])),
            action_failed: @json(___('Something went wrong, please try again.')),
            view_stories:  @json(___('View stories')),
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
{{-- Address map picker: matcha-native sheet (scrim + slide, mirrors the theme's own
     sheet()) driven by the shared window.mbMapPicker engine. Fills the canonical
     checkout address + hidden coords; the cart's inline field mirrors on input. --}}
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
                'cv_recent_orders_' + @json($store->slug ?? ''),
                'cv_reorder_' + @json($store->slug ?? ''),
                'cv_recent_addrs_' + @json($store->slug ?? '')
            ],
            slug: @json($store->slug ?? ''),
            storeId: @json($store->id ?? ''),
            confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
        });
    });
})();
</script>
@endpush
