{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       Vivid — data preparation
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus = collect($total_menus)->values();
    $items = $allMenus->values();

    $byCat      = $items->groupBy('category_id');
    /* Top-level only, ON PURPOSE — do NOT swap in $store->menu_categories_flat here.
       Vivid renders NESTED: one .vd-cat-section per parent, which draws that parent's
       own items and then a subsection per subcategory (see the menu section below), so
       subcategory items are already reached. The flat accessor exists for themes that
       iterate categories and match items by $cat->id only — those drop subcategory
       items; this one does not. Flattening here would emit every subcategory twice
       (once nested, once top-level), give each its own pill, and break the pill /
       scroll-spy contract in js/script.js, which keys off data-cat = PARENT id. */
    $categories = $store->menu_categories->values();

    /* Two-tier subcategory nav (parity #2). Vivid already NESTS subcategory items
       inside their parent's <section data-cat=PARENT>, so a parent pill shows every
       descendant item — that half of the contract holds by construction. What was
       missing is the secondary sub-pill row that narrows to one subcategory. Cards
       already carry their own category_id (data-cat on .vd-card), so narrowing is a
       card-level match; here we only need the map of which parents have item-bearing
       subcategories (drives data-has-sub + the swap-in sub-pill groups). */
    $catItemCount = [];                       // real category id => own item count
    foreach ($items as $m) {
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;
    }
    $subCatsOf = [];                          // parent id => item-bearing subcategories
    foreach ($categories as $c) {
        $subs = \Juwri\Core\Support\ViewHelpers::withItems($c->subcategories, $catItemCount);
        if ($subs->isNotEmpty()) {
            $subCatsOf[$c->id] = $subs;
        }
    }

    $curSign         = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos          = (string) currency_pos_effective($postOptions->id ?? null);
    $curGap          = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only

    /* #27 multi-currency display: parse "CODE=rate" lines (same contract as aurora) */
    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

    $deliveryCharge  = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge       = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $minOrderValue   = (float) (@$postOptions->restaurant_minimum_order ?: 0);
    $allowOrderSched = !empty($postOptions->allow_order_scheduling);
    $allowTips       = !empty($postOptions->allow_tips);
    $allowCoupons    = @$postOptions->allow_coupons == 1;
    $allowWaiter     = !empty($postOptions->allow_call_waiter);
    /* R4 · open/closed status (parity with crave). Manual store toggle: '1' or an
       unset value both mean "open"; only an explicit '0' closes the store. */
    $isOpen          = store_open_now($store);
    $dietFiltersOn   = !isset($postOptions->allow_dietary_filters) || @$postOptions->allow_dietary_filters == 1;

    /* featured = first item that has a real image */
    $featured = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    /* bespoke vivid splash imagery (first few real-photo dishes) */
    $splashImgs = \Juwri\Core\Support\ViewHelpers::imageItems($items, 3);
    /* today's-specials rail = daily specials first, fall back to popular, then first 8
       (same source contract as crave: is_daily_special → is_popular). */
    $specials = \Juwri\Core\Support\ViewHelpers::flagged($items, 'is_daily_special', 8);
    if ($specials->count() < 1) $specials = \Juwri\Core\Support\ViewHelpers::flagged($items, 'is_popular', 8);
    if ($specials->count() < 3) $specials = $items->take(8);

    /* helper: human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* build the JS data map (full detail incl. variants + extras + allergies) */
    $jsItems = [];
    foreach ($items as $m) {
        $itemAllergies = empty($m->allergies) ? collect() : $allergies->whereIn('id', explode(',', $m->allergies));
        $variants = [];
        if (isset($m->variants)) {
            foreach ($m->variants as $v) {
                $variants[] = [
                    'id'    => $v->id,
                    'label' => $variantLabel($m, $v),
                    'price' => (float) $v->price,
                ];
            }
        }
        $extras = [];
        if (isset($m->extras)) {
            foreach ($m->extras as $e) {
                $extras[] = ['id' => $e->id, 'title' => $e->title, 'price' => (float) $e->price, 'img' => $e->image ? media_url('storage/menu/', $e->image, $store->id ?? null) : ''];
            }
        }

        $isSoldOut = (bool) $m->is_sold_out
            || (!empty($m->track_inventory) && isset($m->stock_qty) && (int) $m->stock_qty <= 0);

        $jsItems[$m->id] = [
            'id'        => $m->id,
            'name'      => $m->name,
            'desc'      => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'img'       => media_url('storage/menu/', $m->image, $store->id ?? null),
            'price'     => (float) $m->price,
            'original_price' => ($m->original_price ?? null), // pre-happy-hour price, for a cart strike-through
            'type'      => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'    => $m->rating_avg && $m->rating_avg > 0
                                ? number_format($m->rating_avg, 1)
                                : '',
            'ratingCount' => (int) ($m->rating_count ?? 0),
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyList($itemAllergies),
            'variants'  => $variants,
            'extras'    => $extras,
            'is_vegan'  => (bool) $m->is_vegan,
            'is_halal'  => (bool) $m->is_halal,
            'is_gluten_free' => (bool) $m->is_gluten_free,
            'is_popular'     => (bool) $m->is_popular,
            'is_daily_special' => (bool) ($m->is_daily_special ?? false),
            'spice_level'    => (int) ($m->spice_level ?? 0),
            'is_sold_out'    => $isSoldOut,
            'stock_qty'      => isset($m->stock_qty) ? (int) $m->stock_qty : null,
            'track_inventory'=> (bool) ($m->track_inventory ?? false),
            'low_stock_threshold' => (int) ($m->low_stock_threshold ?? 0),
            'calories'   => $m->calories ?? null,
            'protein'    => $m->protein ?? null,
            'carbs'      => $m->carbs ?? null,
            'fat'        => $m->fat ?? null,
            'gallery'    => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryUrls($m->gallery_images, $store->id ?? null)
                : [],
            'optionGroups' => \Juwri\Core\Support\ViewHelpers::optionGroups($m->globalGroups ?? []),
        ];
    }

    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();
    // Coupons & Wallets: pay-with-credit for a logged-in diner with balance.
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;
    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $customerDefaultAddress = $customerAddresses->first();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);

    $logoUrl    = media_url('storage/store/logo/', $store->main_image, $store->id ?? null);
    $coverUrl   = media_url('storage/store/cover/', $store->cover_image, $store->id ?? null);
    $orderUrl   = route('store.submitOrder', $store->id);

    /* menu_sections visibility (same contract as crave/aurora) */
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

    /* engagement extras (same backend contract as crave/aurora) */
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));
    $myWaitlist = storefront()->activeWaitlist($store, $customer);
    $myReservation = storefront()->upcomingReservation($store, $customer);

    /* ── theme-pack-blade features (F1–F7) — same backend contract as crave ──
       Mint (and session-cache) a raw CustomerToken so the theme JS can call the
       CustomerToken-guarded feature APIs (follow, meal plans). */
    $customerApiToken = storefront()->customerApiToken($customer, 'vivid');

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
    $mealPlansEnabled = \Juwri\Modules\Ordering\Services\MealPlanService::enabled($store->id);
    $mealPlanCadences = $mealPlansEnabled ? \Juwri\Modules\Ordering\Services\MealPlanService::allowedCadences($store->id) : [];

    /* F7 · OTP checkout gate */
    $otpGateEnabled = \Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController::gateEnabled($store->id);

    /* F4 · nearest-branch map available when multi-branch */
    $vdShowBranches = !empty($branches) && $branches->count() > 1;
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- F4 · self-hosted Leaflet (CSP-safe; tiles load as https imgs) --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
@endpush

@section('content')
<div class="vd" id="vd">

    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="vd-splash" id="vd-splash" role="presentation" aria-hidden="true">
        <div class="vd-splash-inner">
            @if((string) ($logoUrl) !== '')<img class="vd-splash-logo" src="{{ $logoUrl }}" alt="" data-csp-error="hide">@endif
            <div class="vd-splash-hero" aria-hidden="true">
                <i class="vd-splash-ring"></i>
                @foreach($splashImgs as $si)
                    @if((string) (media_url('storage/menu/', $si->image, $store->id ?? null)) !== '')<img class="vd-splash-pop vd-splash-pop-{{ $loop->index }}"
                         src="{{ media_url('storage/menu/', $si->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                @endforeach
            </div>
            <h1 class="vd-splash-title">{{ $store->title }}</h1>
            <p class="vd-splash-tag">{{ $store->sub_title ?: ___('Digital Menu') }}</p>
        </div>
        <button class="vd-splash-skip" id="vd-splash-skip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- animated mesh backdrop --}}
    <div class="vd-mesh" aria-hidden="true">
        <span class="vd-blob vd-blob-1"></span>
        <span class="vd-blob vd-blob-2"></span>
        <span class="vd-blob vd-blob-3"></span>
    </div>

    {{-- B2: the "N viewing now" proof merged into the brand/follow row (single source
         of proof — see the follower-count partial). The old full-width social-proof
         banner is removed so the viewer count is not shown twice. --}}

    {{-- =========================================================== TOP BAR --}}
    <header class="vd-topbar" id="vd-topbar">
        <div class="vd-container">
            <a href="#vd" class="vd-brandmini" aria-label="{{ $store->title }}">
                @if((string) ($logoUrl) !== '')<img src="{{ $logoUrl }}" alt="{{ $store->title }}" data-csp-error="hide">@endif
                <span>{{ $store->title }}</span>
            </a>
            <div class="vd-topbar-actions">
                @if(!empty($menu_languages) && count($menu_languages) > 1)
                <div class="vd-dropdown" id="vd-lang-wrap">
                    <button class="vd-iconbtn" type="button" id="vd-lang-toggle" aria-label="{{ ___('Language') }}">
                        <i class="ico ico-world" aria-hidden="true"><x-ui.icon name="world" /></i>
                    </button>
                    <div class="vd-dropdown-menu">
                        @foreach($menu_languages as $language)
                        <button type="button" data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" data-code="{{ $language->code }}">{{ $language->name }}</button>
                        @endforeach
                    </div>
                </div>
                @endif
                @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
                <div class="vd-dropdown" id="vd-currency-wrap">
                    <button class="vd-iconbtn" type="button" id="vd-currency-toggle" aria-label="{{ ___('Currency') }}" style="font-size:12px;font-weight:700;min-width:40px">
                        <span id="vd-cur-label">{{ config('settings.currency_code', 'USD') }}</span>
                    </button>
                    <div class="vd-dropdown-menu">
                        <button type="button" data-cur="{{ config('settings.currency_code', 'USD') }}" data-rate="1">{{ config('settings.currency_code', 'USD') }}</button>
                        @foreach($mcRates as $code => $rate)
                            <button type="button" data-cur="{{ $code }}" data-rate="{{ $rate }}">{{ $code }}</button>
                        @endforeach
                    </div>
                </div>
                @endif
                <button class="vd-iconbtn" type="button" id="vd-theme-toggle" aria-label="{{ ___('Toggle light/dark mode') }}">
                    <i class="ico ico-moon" id="vd-theme-icon" aria-hidden="true"><x-ui.icon name="moon" /></i>
                </button>
                <button class="vd-iconbtn" type="button" id="vd-fav-toggle" aria-label="{{ ___('Favorites') }}">
                    <i class="ico ico-heart-filled" aria-hidden="true"><x-ui.icon name="heart-filled" /></i>
                    <span class="vd-badge-count" id="vd-fav-count">0</span>
                </button>
                @if(storefront_catering_enabled($store->id))
                {{-- vivid is a scrolling theme (no sheet framework); the catering form
                     stays inline and this header button jumps to it. data-catering-open
                     still fires the shared guest-autofill. --}}
                <a id="cvCateringBtn" class="vd-iconbtn" href="#catering" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                </a>
                @endif
                <button class="vd-iconbtn" type="button" id="mbNotifBell" aria-label="{{ ___('Notifications') }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                    <i id="mbNotifDot" hidden style="position:absolute;top:2px;inset-inline-end:2px;width:9px;height:9px;border-radius:50%;background:var(--vd-accent,#ff2d78);border:2px solid var(--vd-surface,#12121a)"></i>
                </button>
                @if($authMode !== 'guest_only')
                    @if($customer)
                    <div class="vd-dropdown" id="vd-account-menu">
                        <button class="vd-iconbtn" type="button" aria-label="{{ ___('Account') }}">
                            <i class="ico ico-user-check" style="color:var(--vd-accent)" aria-hidden="true"><x-ui.icon name="user-check" /></i>
                        </button>
                        <div class="vd-dropdown-menu">
                            <div class="vd-account-name">{{ $customer->name }}</div>
                            <a href="{{ route('customer.profile', $store->id) }}" class="vd-dropdown-link">{{ ___('Profile & settings') }}</a>
                            <a href="{{ route('customer.account', $store->id) }}" class="vd-dropdown-link">{{ ___('My Orders') }}</a>
                            <button type="button" id="vd-logout-btn" class="vd-dropdown-link">{{ ___('Logout') }}</button>
                        </div>
                    </div>
                    @else
                    <button class="vd-iconbtn" type="button" id="vd-login-toggle" aria-label="{{ ___('Login') }}">
                        <i class="ico ico-user" aria-hidden="true"><x-ui.icon name="user" /></i>
                    </button>
                    @endif
                @endif
                @if($allow_order)
                <button class="vd-iconbtn vd-cart-btn" type="button" id="vd-cart-toggle" aria-label="{{ ___('Your order') }}">
                    <i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i>
                    <span class="vd-badge-count" id="vd-cart-count">0</span>
                </button>
                @endif
            </div>
        </div>
    </header>

    {{-- ================================================================ HERO --}}
    <section class="vd-hero {{ $sectionVisible['hero'] ? '' : 'vd-hero-bare' }}" id="vd-hero">
        @if($sectionVisible['hero'])
        <div class="vd-hero-cover" id="vd-hero-cover">
            @if((string) ($coverUrl) !== '')<img src="{{ $coverUrl }}" alt="" data-csp-error="parent-hide">@endif
            <div class="vd-hero-scrim"></div>
        </div>
        @endif
        <div class="vd-container vd-hero-inner">
            @if($sectionVisible['hero'])
            @if((string) ($logoUrl) !== '')<img class="vd-hero-logo" src="{{ $logoUrl }}" alt="{{ $store->title }}" data-csp-error="hide">@endif
            <span class="vd-eyebrow"><span class="vd-dot"></span> {{ ___('Digital Menu') }}</span>
            <h1 class="vd-hero-title" data-tc-text="texts.hero_title">{{ theme_setting($store->id, 'texts.hero_title', '') ?: $store->title }}</h1>
            <p class="vd-hero-tag" data-tc-text="texts.hero_subtitle">{{ theme_setting($store->id, 'texts.hero_subtitle', '') ?: ($store->sub_title ?: (html_entity_decode(strip_tags((string) $store->description), ENT_QUOTES) ?: ___('Browse our menu and order in seconds.'))) }}</p>
            <div class="vd-hero-meta">
                {{-- R4 · open-now / closed status chip --}}
                <span class="vd-chip-meta vd-open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i class="ico ico-circle-filled" aria-hidden="true"><x-ui.icon name="circle-filled" /></i> <span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                {{-- dir="auto" so a Latin address inside an RTL page is not re-ordered
                     by the bidi algorithm: "1 Verify St" rendered as "Verify St 1" in
                     Arabic, i.e. a wrong street number on the customer's screen. --}}
                @if($store->address)<span class="vd-chip-meta"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i> <bdi dir="auto">{{ $store->address }}</bdi></span>@endif
                @if($store->phone)<span class="vd-chip-meta"><i class="ico ico-phone" aria-hidden="true"><x-ui.icon name="phone" /></i> <bdi dir="ltr">{{ $store->phone }}</bdi></span>@endif
                @if($table)<span class="vd-chip-meta"><i class="ico ico-armchair" aria-hidden="true"><x-ui.icon name="armchair" /></i> {{ ___('Table') }} {{ $table->table_no }}</span>@endif
            </div>
            @endif
            {{-- search + dietary filters moved out of the hero to the head of the menu
                 section (vd-menu-root) so a query/filter changes the food in view instead
                 of acting on a grid ~1100px below the header. --}}
        </div>
    </section>

    {{-- Promo banners slider: relocated below the hero (it was the page's first section). --}}
    <div style="max-inline-size:1100px;margin-inline:auto;padding-inline:16px">
        @include('store_templates.includes.menu-banners-slider')
    </div>

    {{-- =================================================== F2 BRAND / FOLLOW + F3 STORIES --}}
    <div class="vd-container">
        {{-- B10: brand/follow row is a toggleable customizer section (default on). --}}
        @if($sectionVisible['brand_row'] ?? true)
        <div class="vd-brand-row" id="vd-brand-row" data-tc-section="brand_row" data-mb-hotel-chip-slot>
            <span class="vd-brand-logo">
                @if((string) ($logoUrl) !== '')<img src="{{ $logoUrl }}" alt="" data-csp-error="hide">@endif
            </span>
            <span class="vd-brand-info">
                <b class="vd-brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="vd-verified-badge" role="img" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
                @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true), 'countId' => 'vd-follower-count'])
            </span>
            @if($authMode !== 'guest_only' || $customer)
            <button class="vd-followbtn {{ $isFollowing ? 'is-on' : '' }}" id="vd-follow-btn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
                <i class="ico ico-plus vd-follow-ic" aria-hidden="true"><x-ui.icon name="plus" /></i>
                <span class="vd-follow-label" data-tc-text="texts.follow_label">{{ theme_setting($store->id, 'texts.follow_label', ___('Follow')) }}</span>
                <span class="vd-following-label">{{ ___('Following') }}</span>
            </button>
            @endif
        </div>
        @endif
        {{-- F3 · stories rail (populated from /r/{slug}/stories) --}}
        <div class="vd-stories-rail" id="vd-stories-rail" data-story-label="{{ ___('View story') }}" hidden></div>
    </div>

    {{-- ====================================================== CATEGORY NAV --}}
    @if($specials->count() > 2 && $sectionVisible['daily_specials'])
    <section class="vd-section vd-specials-section" id="vd-specials-section">
        <div class="vd-container">
            <div class="vd-section-head">
                <span class="vd-kicker"><i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ ___('Handpicked') }}</span>
                <h2 data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___("Today's specials")) }}</h2>
            </div>
            <div class="vd-rail" id="vd-specials-rail" tabindex="0" role="region" aria-label="{{ theme_setting($store->id, 'texts.featured_title', ___("Today's specials")) }}">
                @foreach($specials as $sp)
                <div class="vd-spotlight vd-reveal" @if(!$jsItems[$sp->id]['is_sold_out']) data-open-item="{{ $sp->id }}" @else style="opacity:.7"@endif>
                    @if($jsItems[$sp->id]['is_sold_out'])
                    <span class="vd-badge vd-badge-soldout">{{ ___('Sold Out') }}</span>
                    @endif
                    <div class="vd-spotlight-img vd-shimmer">
                        @if((string) (media_url('storage/menu/', $sp->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $sp->image, $store->id ?? null) }}" alt="{{ $sp->name }}" loading="lazy"
                             data-csp-load="remove-parent-class" data-csp-load-class="vd-shimmer"
                             data-csp-error="shimmer-error" data-csp-shimmer="vd-shimmer" data-csp-class="vd-noimg" data-csp-remove="true">@endif
                    </div>
                    <div class="vd-spotlight-body">
                        <h4>{{ $sp->name }}</h4>
                        <div class="vd-spotlight-foot">
                            <span class="vd-price"><span class="vd-amt" data-vd-base="{{ $sp->price }}">{{ price_symbol_format($sp->price) }}</span></span>
                            <span class="vd-arrow-btn"><i class="ico ico-arrow-right" aria-hidden="true"><x-ui.icon name="arrow-right" /></i></span>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    @if($sectionVisible['catbar'])
    {{-- data-mb-grid-lead: the category selector must touch the items it
         filters — injected sections (offers strip, order-type bar) climb
         above these rows instead of wedging between categories and grid. --}}
    <nav class="vd-catnav" id="vd-catnav" data-mb-grid-lead data-mbt-anchor>
        <div class="vd-catnav-inner" id="vd-catnav-inner" data-mb-grid-lead>
            <button class="vd-cat-pill active" type="button" data-target="all">
                <span class="vd-cat-pill-ico">✦</span> {{ ___('All') }}
            </button>
            @foreach($categories as $cat)
                @php
                    $direct  = $byCat[$cat->id] ?? collect();
                    $subIds  = $cat->subcategories->pluck('id')->all();
                    $catCount = $direct->count();
                    foreach ($subIds as $sid) { $catCount += ($byCat[$sid] ?? collect())->count(); }
                @endphp
                @if($catCount > 0)
                <button class="vd-cat-pill" type="button" data-target="cat-{{ $cat->id }}"
                        @isset($subCatsOf[$cat->id]) data-has-sub="1" @endisset>{{ $cat->name }}</button>
                @endif
            @endforeach
            <span class="vd-cat-indicator" id="vd-cat-indicator"></span>
        </div>

        {{-- Secondary sub-category pills (two-tier nav). One hidden group per parent
             that owns item-bearing subcategories; JS reveals the active parent's group.
             "All" (data-sub="root") shows every descendant of the parent; a specific
             sub-pill narrows to that subcategory. --}}
        @if(!empty($subCatsOf))
        <div class="vd-subnav" id="vd-subnav" hidden role="group" aria-label="{{ ___('Sub-categories') }}" data-mb-grid-lead>
            <div class="vd-subnav-inner">
                @foreach($subCatsOf as $parentId => $subs)
                <div class="vd-subgroup" data-parent="{{ $parentId }}" hidden>
                    <button class="vd-sub-pill active" type="button" data-sub="root">{{ ___('All') }}</button>
                    @foreach($subs as $sub)
                    <button class="vd-sub-pill" type="button" data-sub="{{ $sub->id }}">{{ $sub->name }}</button>
                    @endforeach
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </nav>
    @endif

    {{-- ============================================== TODAY'S SPECIALS RAIL --}}

    {{-- ================================================================ MENU --}}
    <div class="vd-container" id="vd-menu-root">
        {{-- search + dietary filters, relocated here from the hero so a query or filter
             changes the food directly below, in view. --}}
        @if(theme_setting($store->id, 'search.enabled', true))
        <div class="vd-search-wrap">
            <div class="vd-search">
                <i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i>
                <input aria-label="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dishes or ingredients')) }}" type="text" id="vd-search-input" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock" data-tc-text="texts.search_placeholder"
                       placeholder="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dishes or ingredients')) }}">
                <button class="vd-search-clear" type="button" id="vd-search-clear" style="display:none" aria-label="{{ ___('Clear search') }}">
                    <i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i>
                </button>
            </div>
            @if($dietFiltersOn)
            <button class="vd-filters-toggle" type="button" id="vd-filters-toggle" aria-label="{{ ___('Filters') }}">
                <i class="ico ico-adjustments-horizontal" aria-hidden="true"><x-ui.icon name="adjustments-horizontal" /></i>
            </button>
            @endif
        </div>
        @endif
        @if($dietFiltersOn)
        <div class="vd-filters-panel" id="vd-filters-panel">
            <div class="vd-filters-section">
                <h4>{{ ___('Dietary Needs') }}</h4>
                <div class="vd-filter-chips">
                    <button class="vd-filter-chip" type="button" data-filter="vegan"><i class="ico ico-leaf" aria-hidden="true"><x-ui.icon name="leaf" /></i> {{ ___('Vegan') }}</button>
                    <button class="vd-filter-chip" type="button" data-filter="halal"><i class="ico ico-rosette-discount-check" aria-hidden="true"><x-ui.icon name="rosette-discount-check" /></i> {{ ___('Halal') }}</button>
                    <button class="vd-filter-chip" type="button" data-filter="gf"><i class="ico ico-wheat" aria-hidden="true"><x-ui.icon name="wheat" /></i> {{ ___('Gluten Free') }}</button>
                    <button class="vd-filter-chip" type="button" data-filter="popular"><i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ ___('Popular') }}</button>
                </div>
            </div>
            <div class="vd-filters-section">
                <h4>{{ ___('Spice Level') }}</h4>
                <div class="vd-filter-chips">
                    <button class="vd-filter-chip" type="button" data-filter="spice-0">{{ ___('Mild / None') }}</button>
                    <button class="vd-filter-chip" type="button" data-filter="spice-1">🌶</button>
                    <button class="vd-filter-chip" type="button" data-filter="spice-2">🌶🌶</button>
                    <button class="vd-filter-chip" type="button" data-filter="spice-3">🌶🌶🌶</button>
                </div>
            </div>
            @if(count($allergies) > 0)
            <div class="vd-filters-section">
                <h4>{{ ___('Exclude Allergens') }}</h4>
                <div class="vd-filter-chips">
                    @foreach($allergies as $al)
                    <button class="vd-filter-chip allergen-exclude-chip" type="button" data-allergen-id="{{ $al->id }}">
                        {{ !empty($al->translations->{get_lang()}->title) ? $al->translations->{get_lang()}->title : $al->title }}
                    </button>
                    @endforeach
                </div>
            </div>
            @endif
            <div class="vd-filters-foot">
                <button class="vd-filter-clear-btn" type="button" id="vd-filter-clear">
                    <i class="ico ico-rotate-clockwise" aria-hidden="true"><x-ui.icon name="rotate-clockwise" /></i> {{ ___('Clear Filters') }}
                </button>
            </div>
        </div>
        @endif
        @include('store_templates.partials.branch-switcher')
        @foreach($categories as $cat)
            @php
                $direct  = $byCat[$cat->id] ?? collect();
                $subcats = $cat->subcategories;
                $hasSub  = $subcats->contains(fn($s) => ($byCat[$s->id] ?? collect())->count() > 0);
            @endphp
            @if($direct->count() > 0 || $hasSub)
            <section class="vd-section vd-cat-section" id="cat-{{ $cat->id }}" data-cat="{{ $cat->id }}">
                <div class="vd-section-head">
                    <span class="vd-kicker" data-tc-text="texts.menu_title">{{ theme_setting($store->id, 'texts.menu_title', ___('Menu')) }}</span>
                    <h2>{{ $cat->name }}</h2>
                </div>
                @if($direct->count() > 0)
                {{-- data-subcat="root" = the parent's own direct items --}}
                <div class="vd-subwrap" data-subcat="root">
                    <div class="vd-grid">
                        @foreach($direct as $i => $menu)
                        @include('store_templates.vivid.includes.dish-card', ['menu' => $menu, 'cardIndex' => $i])
                        @endforeach
                    </div>
                </div>
                @endif
                @foreach($subcats as $sub)
                    @php $subItems = $byCat[$sub->id] ?? collect(); @endphp
                    @if($subItems->count() > 0)
                    {{-- head + grid share one wrapper so sub-pill narrowing / empty-hiding
                         drop the label together with its cards --}}
                    <div class="vd-subwrap" data-subcat="{{ $sub->id }}">
                        <div class="vd-section-head" style="margin-top:24px">
                            <span class="vd-kicker">{{ $sub->name }}</span>
                        </div>
                        <div class="vd-grid">
                            @foreach($subItems as $i => $menu)
                            @include('store_templates.vivid.includes.dish-card', ['menu' => $menu, 'cardIndex' => $i])
                            @endforeach
                        </div>
                    </div>
                    @endif
                @endforeach
            </section>
            @endif
        @endforeach

        {{-- no-results state --}}
        <div class="vd-empty" id="vd-empty">
            <div class="vd-empty-ico"><i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i></div>
            <h3>{{ ___('No dishes found') }}</h3>
            <p>{{ ___('Try a different search or category.') }}</p>
        </div>

        @if($items->count() === 0)
        <div class="vd-empty show">
            <div class="vd-empty-ico"><i class="ico ico-dine-in" aria-hidden="true"><x-ui.icon name="dine-in" /></i></div>
            <h3>{{ ___('Menu coming soon') }}</h3>
            <p>{{ ___('This restaurant has not added any dishes yet.') }}</p>
        </div>
        @endif
    </div>

    {{-- ============================================== CALL WAITER / BILL --}}
    @if($allowWaiter)
    <div class="vd-container" style="padding-bottom:24px">
        <div class="vd-waiter-row">
            <button type="button" id="vd-call-waiter-btn" class="vd-waiter-btn">
                <i class="ico ico-bell" aria-hidden="true"><x-ui.icon name="bell" /></i> {{ ___('Call Waiter') }}
            </button>
            <button type="button" id="vd-request-bill-btn" class="vd-waiter-btn vd-waiter-bill">
                <i class="ico ico-receipt" aria-hidden="true"><x-ui.icon name="receipt" /></i> {{ ___('Request Bill') }}
            </button>
        </div>
    </div>
    <div class="vd-scrim" id="vd-waiter-scrim"></div>
    <aside class="vd-sheet" id="vd-waiter-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3 id="vd-waiter-title">{{ ___('Call Waiter') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="waiter" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            <div id="vd-waiter-success" style="display:none;text-align:center;padding:30px 0">
                <div style="font-size:42px;margin-bottom:8px">✅</div>
                <h3>{{ ___('Request sent!') }}</h3>
                <p id="vd-waiter-sent-label" style="color:var(--vd-muted)"></p>
            </div>
            <form id="vd-waiter-form">
                {{-- The field had NO name, so FormData never carried it and every
                     "Request Bill" was filed by the server as a plain call_waiter —
                     the diner asked for the bill and the floor was told to come over
                     empty-handed. callTheWaiter() accepts call_waiter|request_bill. --}}
                <input type="hidden" name="type" id="vd-waiter-type-input" value="call_waiter">
                <p id="vd-waiter-context-label" style="color:var(--vd-muted);margin-bottom:18px">{{ ___('We will send a waiter to your table right away.') }}</p>
                <div class="vd-field">
                    <label>{{ ___('Table Number') }}</label>
                    {{-- callTheWaiter() validates `table` as an INTEGER, so the field
                         asks for digits and opens the numeric keypad on a phone. --}}
                    <input aria-label="{{ ___('Your table number') }}" type="text" inputmode="numeric" pattern="[0-9]*" id="vd-waiter-table" name="table" value="{{ $table->table_no ?? '' }}" {{ $table ? 'readonly' : '' }} placeholder="{{ ___('Your table number') }}" required>
                </div>
                <div class="vd-field">
                    <label>{{ ___('Note') }} ({{ ___('optional') }})</label>
                    <textarea aria-label="{{ ___('Any special note?') }}" name="message" placeholder="{{ ___('Any special note?') }}"></textarea>
                </div>
                <p class="vd-form-error" id="vd-waiter-error" style="display:none"></p>
                <button type="submit" class="vd-cta"><i class="ico ico-send" aria-hidden="true"><x-ui.icon name="send" /></i> {{ ___('Send Request') }}</button>
            </form>
        </div>
    </aside>
    @endif

    {{-- ================================================ B6 · Following management sheet --}}
    @if($authMode !== 'guest_only' || $customer)
    <div class="vd-scrim" id="vd-following-scrim"></div>
    <aside class="vd-sheet" id="vd-following-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ ___('Following') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="following" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            <label class="vd-follow-sheet-row">
                <span>{{ ___('Notifications') }}</span>
                <input type="checkbox" id="vd-follow-notify" role="switch">
            </label>
            <button class="vd-followsheet-unfollow" id="vd-follow-unfollow" type="button">{{ ___('Unfollow') }}</button>
            <button class="vd-followsheet-cancel" id="vd-follow-cancel" type="button">{{ ___('Cancel') }}</button>
        </div>
    </aside>
    @endif

    {{-- ================================================ B8 · About sheet --}}
    <div class="vd-scrim" id="vd-about-scrim"></div>
    <aside class="vd-sheet" id="vd-about-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ $store->title }}</h3>
            <button class="vd-close" type="button" data-close-sheet="about" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            @if($aboutRating !== null)
                <div class="vd-about-rating"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
            @endif
            <div class="vd-about-list">
                @if(!empty($store->timing))
                    <div class="vd-about-row"><i class="ico ico-clock" aria-hidden="true"><x-ui.icon name="clock" /></i><span>{{ $store->timing }}</span></div>
                @endif
                @if(!empty($store->address))
                    <a class="vd-about-row" href="https://maps.google.com/?q={{ urlencode($store->address) }}" target="_blank" rel="noopener"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i><span>{{ $store->address }}</span></a>
                @endif
                @if(!empty($store->phone))
                    <a class="vd-about-row" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $store->phone) }}"><i class="ico ico-phone" aria-hidden="true"><x-ui.icon name="phone" /></i><span dir="ltr">{{ $store->phone }}</span></a>
                @endif
                @if($aboutInstagram)
                    <a class="vd-about-row" href="{{ $aboutInstagram }}" target="_blank" rel="noopener"><i class="ico ico-brand-instagram" aria-hidden="true"><x-ui.icon name="brand-instagram" /></i><span>{{ ___('Instagram') }}</span></a>
                @endif
                @if($aboutFacebook)
                    <a class="vd-about-row" href="{{ $aboutFacebook }}" target="_blank" rel="noopener"><i class="ico ico-brand-facebook" aria-hidden="true"><x-ui.icon name="brand-facebook" /></i><span>{{ ___('Facebook') }}</span></a>
                @endif
            </div>
            @if($aboutReviews->isNotEmpty())
                <div class="vd-about-reviews">
                    @foreach($aboutReviews as $rev)
                        <div class="vd-about-review">
                            <div class="vd-about-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="vd-about-star"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ $rev->stars }}</span></div>
                            @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </aside>

    {{-- ================================================ AUTH (login/otp/register) --}}
    @if($authMode !== 'guest_only' && !$customer)
    <div class="vd-scrim" id="vd-auth-scrim"></div>
    <aside class="vd-sheet" id="vd-auth-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ ___('Login') }} / {{ ___('Register') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="auth" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            <div class="vd-auth-tabs" id="vd-auth-tabs">
                <button type="button" data-auth-tab="login" class="is-on">{{ ___('Login') }}</button>
                <button type="button" data-auth-tab="otp">{{ ___('Phone OTP') }}</button>
                <button type="button" data-auth-tab="register">{{ ___('Register') }}</button>
            </div>
            <p class="vd-auth-error" id="vd-auth-error" style="display:none"></p>

            <form class="vd-auth-form" data-auth="login">
                <div class="vd-field"><label>{{ ___('Email') }}</label><input aria-label="{{ ___('Email') }}" type="email" id="vd-login-email" autocomplete="email"></div>
                <div class="vd-field"><label>{{ ___('Password') }}</label><input aria-label="{{ ___('Password') }}" type="password" id="vd-login-pass" autocomplete="current-password"></div>
                <button type="button" id="vd-login-btn" class="vd-cta">{{ ___('Login') }}</button>
                @include('store_templates.partials.social-login-buttons', ['store' => $store])
                @include('store_templates.partials.passkey-login', ['store' => $store])
            </form>

            <form class="vd-auth-form" data-auth="otp" style="display:none">
                <div id="vd-otp-step1">
                    <div class="vd-field"><label>{{ ___('Phone Number') }}</label><input aria-label="{{ ___('Phone Number') }}" type="tel" id="vd-otp-phone" inputmode="tel" autocomplete="tel" placeholder="{{ ___('e.g. +123456789') }}"></div>
                    <button type="button" id="vd-otp-send" class="vd-cta">{{ ___('Send code') }}</button>
                </div>
                <div id="vd-otp-step2" style="display:none">
                    <p style="color:var(--vd-muted);font-size:13px;margin-bottom:12px">{{ ___('Enter the code we sent you') }}</p>
                    <div class="vd-field"><label>{{ ___('Verification code') }}</label><input aria-label="{{ ___('Verification code') }}" type="text" id="vd-otp-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="––––––"></div>
                    <button type="button" id="vd-otp-verify" class="vd-cta">{{ ___('Verify') }}</button>
                </div>
            </form>

            <form class="vd-auth-form" data-auth="register" style="display:none">
                <div class="vd-field"><label>{{ ___('Your Name') }}</label><input aria-label="{{ ___('Your Name') }}" type="text" id="vd-reg-name" autocomplete="name"></div>
                <div class="vd-field"><label>{{ ___('Email') }}</label><input aria-label="{{ ___('Email') }}" type="email" id="vd-reg-email" autocomplete="email"></div>
                <div class="vd-field"><label>{{ ___('Phone') }}</label><input aria-label="{{ ___('Phone') }}" type="tel" id="vd-reg-phone" autocomplete="tel"></div>
                <div class="vd-field"><label>{{ ___('Password') }}</label><input aria-label="{{ ___('Password') }}" type="password" id="vd-reg-pass" autocomplete="new-password" minlength="6"></div>
                <div class="vd-field"><label>{{ ___('Confirm Password') }}</label><input aria-label="{{ ___('Confirm Password') }}" type="password" id="vd-reg-pass2" autocomplete="new-password" minlength="6"></div>
                <button type="button" id="vd-register-btn" class="vd-cta">{{ ___('Register') }}</button>
                @include('store_templates.partials.social-login-buttons', ['store' => $store])
            </form>
        </div>
    </aside>
    @endif

    {{-- ============================================================= LOYALTY --}}
    @if($loyaltyProgram && $customer)
    <div class="vd-container" style="padding-bottom:24px">
        <div id="vd-loyalty" class="vd-loyalty-card">
            <div style="font-size:13px;font-weight:800;margin-bottom:6px"><i class="ico ico-star-filled vd-loyalty-star" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ ___('Your Loyalty Balance') }}</div>
            <div class="vd-loyalty-num"><span id="vd-loyalty-balance">–</span></div>
            <div style="font-size:11.5px;color:var(--vd-muted);margin-bottom:12px">
                {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }} / {{ $loyaltyProgram->reward_threshold }} · {{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }}
            </div>
            @if($loyaltyProgram->type === 'stamps')
            <div id="vd-stamp-dots" style="display:flex;flex-wrap:wrap;justify-content:center;gap:7px;margin-bottom:8px">
                @for($s = 0; $s < $loyaltyProgram->reward_threshold; $s++)
                    <span class="vd-stamp-dot" data-idx="{{ $s }}" ><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i></span>
                @endfor
            </div>
            @else
            <div class="vd-loyalty-track"><i id="vd-loyalty-bar" class="vd-loyalty-bar"></i></div>
            @endif
            <button id="vd-loyalty-redeem" type="button" class="vd-cta" style="display:none;margin-top:8px"><i class="ico ico-gift" aria-hidden="true"><x-ui.icon name="gift" /></i> {{ ___('Redeem Reward') }}</button>
            <div id="vd-loyalty-ok" style="display:none;color:#22c55e;font-weight:700;font-size:13px;margin-top:6px">{{ ___('Reward redeemed! Enjoy your reward.') }}</div>
        </div>
    </div>
    @endif

    {{-- ============================================== RESERVATIONS / WAITLIST --}}
    @if($reservationsEnabled || $waitlistEnabled)
    <div class="vd-container" style="padding-bottom:24px">
        <div class="vd-waiter-row">
            @if($reservationsEnabled)
            <button type="button" id="vd-res-open" class="vd-waiter-btn">
                <i class="ico ico-calendar-plus" aria-hidden="true"><x-ui.icon name="calendar-plus" /></i> {{ ___('Reserve a Table') }}
            </button>
            @endif
            @if($waitlistEnabled)
            <button type="button" id="vd-waitlist-open" class="vd-waiter-btn vd-waiter-bill">
                <i class="ico ico-users" aria-hidden="true"><x-ui.icon name="users" /></i> {{ ___('Join the Waitlist') }}
            </button>
            @endif
        </div>
    </div>
    @endif

    @if($reservationsEnabled)
    <div class="vd-scrim" id="vd-res-scrim"></div>
    <aside class="vd-sheet" id="vd-res-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ ___('Reserve a Table') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="res" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            @if($myReservation && !session('reservation_success'))
                <div style="background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.35);border-radius:12px;padding:12px 14px;margin-bottom:16px;text-align:center">
                    <b style="color:#818cf8">{{ $myReservation->status === 'confirmed' ? ___('Confirmed') : ___('Requested') }}</b>
                    <div style="color:var(--vd-muted);font-size:12.5px;margin-top:2px">{{ ___('Booked for :when', ['when' => \Illuminate\Support\Carbon::parse($myReservation->reserved_at)->format('D, M j · g:i A')]) }}</div>
                </div>
            @endif
            @if(session('reservation_success'))
                <div style="text-align:center;padding:24px 0">
                    <div style="font-size:42px">📅</div>
                    <h3 style="margin:10px 0 6px">{{ ___('Request received!') }}</h3>
                    <p style="color:var(--vd-muted)">{{ session('reservation_success') }}</p>
                </div>
            @else
                <form action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                    @csrf
                    <div class="vd-field"><label>{{ ___('Your Name') }}</label><input aria-label="{{ ___('Your Name') }}" type="text" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}"></div>
                    <div class="vd-field"><label>{{ ___('Phone') }}</label><input aria-label="{{ ___('Phone') }}" type="tel" name="phone" maxlength="30" value="{{ $customer->phone ?? '' }}"></div>
                    <div class="vd-field"><label>{{ ___('Email') }}</label><input aria-label="{{ ___('Email') }}" type="email" name="email" maxlength="120" value="{{ $customer->email ?? '' }}"></div>
                    <div class="vd-field"><label>{{ ___('Date & Time') }}</label><input aria-label="{{ ___('Date & Time') }}" type="datetime-local" name="reserved_at" required min="{{ now()->format('Y-m-d\TH:i') }}"></div>
                    <div class="vd-field"><label>{{ ___('Party Size') }}</label><input aria-label="{{ ___('Party Size') }}" type="number" name="party_size" min="1" max="50" value="2" required></div>
                    <div class="vd-field"><label>{{ ___('Note') }} ({{ ___('optional') }})</label><textarea aria-label="{{ ___('Any special requests?') }}" name="notes" maxlength="500" placeholder="{{ ___('Any special requests?') }}"></textarea></div>
                    @include('store_templates._partials.voice-note-recorder')
                    @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                    <button type="submit" class="vd-cta"><i class="ico ico-calendar-check" aria-hidden="true"><x-ui.icon name="calendar-check" /></i> {{ ___('Request Reservation') }}</button>
                </form>
            @endif
        </div>
    </aside>
    @endif

    @if($waitlistEnabled)
    <div class="vd-scrim" id="vd-waitlist-scrim"></div>
    <aside class="vd-sheet" id="vd-waitlist-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ ___('Join the Waitlist') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="waitlist" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            @if($myWaitlist && !session('waitlist_joined'))
                <div style="background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.3);border-radius:12px;padding:12px 14px;margin-bottom:16px;text-align:center">
                    <b style="color:#22c55e">{{ ___('On the list') }}</b>
                    <div style="color:var(--vd-muted);font-size:12.5px;margin-top:2px">{{ ___('Party of :n · joined :time', ['n' => $myWaitlist->party_size, 'time' => \Illuminate\Support\Carbon::parse($myWaitlist->created_at)->diffForHumans()]) }}</div>
                </div>
            @endif
            @if(session('waitlist_joined'))
                <div style="text-align:center;padding:24px 0">
                    <div style="font-size:42px">🎉</div>
                    <h3 style="margin:10px 0 6px">{{ ___("You're on the list!") }}</h3>
                    <p style="color:var(--vd-muted)">{{ ___('Position :pos · ~:min min wait', ['pos' => session('waitlist_joined')['position'], 'min' => session('waitlist_joined')['wait_minutes']]) }}</p>
                </div>
            @else
                <form action="{{ route('publicView.waitlist.join', $store->slug) }}" method="POST">
                    @csrf
                    <div class="vd-field"><label>{{ ___('Your Name') }}</label><input aria-label="{{ ___('Your Name') }}" type="text" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}"></div>
                    <div class="vd-field"><label>{{ ___('Phone') }} ({{ ___('optional') }})</label><input aria-label="{{ ___('Phone') }}" type="tel" name="phone" maxlength="30" value="{{ $customer->phone ?? '' }}"></div>
                    <div class="vd-field"><label>{{ ___('Party Size') }}</label><input aria-label="{{ ___('Party Size') }}" type="number" name="party_size" min="1" max="50" value="2" required></div>
                    <button type="submit" class="vd-cta"><i class="ico ico-user-plus" aria-hidden="true"><x-ui.icon name="user-plus" /></i> {{ ___('Join Waitlist') }}</button>
                </form>
            @endif
        </div>
    </aside>
    @endif

    {{-- ============================================ GUEST ORDER LOOKUP (P2) --}}
    @if(!$customer)
    @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
    <div class="vd-container" style="padding-bottom:8px">
        <section class="vd-lookup" id="vd-lookup">
            <p class="vd-lookup-q"><i class="ico ico-receipt" aria-hidden="true"><x-ui.icon name="receipt" /></i> {{ ___('Track a previous order') }}</p>
            <p style="margin:0 0 10px;font-size:12px;color:var(--vd-muted)">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
            <div class="vd-lookup-row">
                <input aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" type="text" id="vd-lookup-input" autocomplete="off" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}">
                <button type="button" id="vd-lookup-btn" class="vd-lookup-btn">{{ ___('Find') }}</button>
            </div>
            <div class="vd-lookup-results" id="vd-lookup-results"></div>
        </section>
    </div>
    @endif

    {{-- ================================================= NPS SURVEY (P2) --}}
    <div class="vd-container" style="padding-bottom:8px">
        <section class="vd-nps" id="vd-nps">
            <p class="vd-nps-q">{{ ___('How likely are you to recommend us?') }}</p>
            <div class="vd-nps-row" id="vd-nps-row">
                @for($n = 0; $n <= 10; $n++)
                    <button type="button" data-nps="{{ $n }}">{{ $n }}</button>
                @endfor
            </div>
            <div class="vd-nps-scale">
                <span>{{ ___('Not likely') }}</span>
                <span>{{ ___('Very likely') }}</span>
            </div>
            <p class="vd-nps-thanks" id="vd-nps-thanks" style="display:none">{{ ___('Thanks for the feedback!') }}</p>
        </section>
    </div>

    {{-- ============================================================== FOOTER --}}
    <footer class="vd-footer">
        <div class="vd-container">
            <div class="vd-footer-brand" data-tc-text="texts.footer_note">{{ theme_setting($store->id, 'texts.footer_note', '') ?: $store->title }}</div>
            @if($store->address)<div class="vd-footer-addr">{{ $store->address }}</div>@endif
            @if(! store_plan_allows($store, 'hide_branding'))
                <div class="vd-footer-powered">{{ ___('Powered by') }} <a href="{{ route('home') }}" class="vd-powered-link" style="color:inherit">{{ $settings->site_title }}</a></div>
            @endif
        </div>
    </footer>

    {{-- ===================================================== STICKY CART BAR --}}
    @if($allow_order)
    <div class="vd-cartbar" id="vd-cartbar">
        <span class="vd-cartbar-ico"><i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i></span>
        <div class="vd-cartbar-text">
            <span id="vd-cartbar-count">0 {{ ___('items') }}</span>
            <small data-tc-text="texts.cart_button">{{ theme_setting($store->id, 'texts.cart_button', ___('View your order')) }}</small>
        </div>
        <span class="vd-cartbar-total" id="vd-cartbar-total"></span>
    </div>
    @endif

    {{-- ================================================ SCRIMS & SHEETS --}}
    <div class="vd-scrim" id="vd-item-scrim"></div>
    <aside class="vd-sheet vd-sheet-lg" id="vd-item-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <button class="vd-close" type="button" data-close-sheet="item" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        <div class="vd-sheet-body" id="vd-item-body"></div>
        <div class="vd-sheet-foot" id="vd-item-foot" style="display:none"></div>
    </aside>

    @if($allow_order)
    <div class="vd-scrim" id="vd-cart-scrim"></div>
    <aside class="vd-sheet vd-sheet-lg" id="vd-cart-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ ___('Your Order') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="cart" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            <div id="vd-cart-list"></div>
            <div class="vd-cart-empty" id="vd-cart-empty">
                <div class="vd-empty-ico"><i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i></div>
                <h3>{{ ___('Your cart is empty') }}</h3>
                <p>{{ ___('Add a few dishes to get started.') }}</p>
            </div>

            <form id="vd-order-form" action="{{ $orderUrl }}" method="post" style="display:none">
                @csrf
                <label class="wa-optin" style="display:flex;align-items:center;gap:8px;margin:10px 0;font-size:.9em;">
                    <input type="checkbox" name="whatsapp_optin" value="1">
                    <span>{{ ___('Send me offers and updates on WhatsApp') }}</span>
                </label>
                @if(request()->filled('branch_id'))<input type="hidden" name="branch_id" value="{{ (int) request()->get('branch_id') }}">@endif
                <div class="vd-totals" id="vd-totals"></div>

                {{-- F1 · turn this cart into a recurring meal plan --}}
                @if($mealPlansEnabled)
                <button class="vd-mealplan-cta" id="vd-mealplan-cta" type="button">
                    <span class="vd-mp-ic"><i class="ico ico-calendar" aria-hidden="true"><x-ui.icon name="calendar" /></i></span>
                    <span class="vd-mp-copy"><b>{{ ___('Make this a meal plan') }}</b><small>{{ ___('Get these dishes delivered on a schedule') }}</small></span>
                    <i class="ico ico-chevron vd-mp-arr" aria-hidden="true"><x-ui.icon name="chevron" /></i>
                </button>
                @endif

                @if($allowCoupons)
                <div class="vd-field">
                    <label>{{ ___('Promo Code') }}</label>
                    <div class="vd-coupon-row">
                        <input aria-label="{{ ___('Enter promo code') }}" type="text" id="vd-coupon-code" name="coupon_code" placeholder="{{ ___('Enter promo code') }}">
                        <button type="button" id="vd-apply-coupon" class="vd-coupon-btn">{{ ___('Apply') }}</button>
                    </div>
                    <div id="vd-coupon-msg" class="vd-coupon-msg" style="display:none"></div>
                </div>
                @endif
                @if(!empty($postOptions->gift_cards_enabled))
                <div class="vd-field">
                    <label>{{ ___('Gift card code') }}</label>
                    <div class="vd-coupon-row">
                        <input aria-label="{{ ___('Enter gift card code') }}" type="text" id="vd-gift-card-code" name="gift_card_code" placeholder="{{ ___('Enter gift card code') }}">
                    </div>
                </div>
                @endif
                @if($customer && $storeCredit > 0)
                <label class="wa-optin" style="display:flex;align-items:center;gap:8px;margin:10px 0;font-size:.9em;">
                    <input type="checkbox" name="use_wallet" value="1" id="mbUseWallet">
                    <span>{{ ___('Pay with store credit') }} ({{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }})</span>
                </label>
                @endif

                <div class="vd-form-error" id="vd-form-error"></div>

                <div class="vd-field">
                    <label>{{ ___('Order type') }}</label>
                    <div class="vd-seg" id="vd-ordertype-seg">
                        @if($allow_on_table)<button type="button" data-type="on-table">{{ ___('On table') }}</button>@endif
                        @if($allow_takeaway)<button type="button" data-type="takeaway" data-tc-text="texts.pickup">{{ theme_setting($store->id, 'texts.pickup', ___('Takeaway')) }}</button>@endif
                        @if($allow_delivery)<button type="button" data-type="delivery" data-tc-text="texts.delivery">{{ theme_setting($store->id, 'texts.delivery', ___('Delivery')) }}</button>@endif
                        {{-- T1/H2 hotel room service. $allow_room_service is 1 only when hotel mode
                             is on AND the scanned QR resolved to an area_type='room' table, so this
                             button never renders for an ordinary restaurant. The script's field
                             branches are whitelists (phone shows only for takeaway/delivery, address
                             only for delivery), so room_service already behaves like on-table. --}}
                        @if($allow_room_service)<button type="button" data-type="room_service">{{ ___('Room service') }}</button>@endif
                    </div>
                    <input type="hidden" name="ordering-type" id="vd-ordering-type" value="">
                </div>

                @if($allowOrderSched)
                <div class="vd-field">
                    <label>{{ ___('Schedule for later') }} <small style="color:var(--vd-muted)">({{ ___('optional') }})</small></label>
                    <input aria-label="{{ ___('Schedule for later') }}" type="datetime-local" name="scheduled_at" id="vd-scheduled-at"
                           min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}"
                           max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
                </div>
                @endif

                {{-- T5: Curbside pickup (takeaway) --}}
                @if(!empty($postOptions->curbside_enabled))
                <div class="vd-field">
                    <label style="display:flex;align-items:center;gap:8px;font-size:.9em;cursor:pointer;">
                        <input type="checkbox" name="curbside" value="1" id="vd-curbside" data-csp-toggle="vd-curbside-fields">
                        <span>{{ ___("Curbside pickup — I'll wait in my car") }}</span>
                    </label>
                    <div id="vd-curbside-fields" style="display:none;margin-top:8px;">
                        <input aria-label="{{ ___('Car make/model') }}" type="text" name="vehicle_make" id="vd-vehicle-make" placeholder="{{ ___('Car make/model') }}" style="margin-bottom:8px;">
                        <input aria-label="{{ ___('Colour') }}" type="text" name="vehicle_color" id="vd-vehicle-color" placeholder="{{ ___('Colour') }}" style="margin-bottom:8px;">
                        <input aria-label="{{ ___('Plate') }}" type="text" name="vehicle_plate" id="vd-vehicle-plate" placeholder="{{ ___('Plate') }}">
                    </div>
                </div>
                @endif

                @if($authMode === 'required' && !$customer)
                <div class="vd-auth-required">
                    <i class="ico ico-lock" aria-hidden="true"><x-ui.icon name="lock" /></i>
                    <h4>{{ ___('Login Required') }}</h4>
                    <p>{{ ___('You must be logged in to place an order.') }}</p>
                    <button type="button" class="vd-cta" id="vd-checkout-login-btn">{{ ___('Login / Register') }}</button>
                </div>
                <div id="vd-guest-fields" style="display:none">
                @else
                    @if($authMode === 'optional' && !$customer)
                    <div class="vd-auth-optional">
                        <div>
                            <h4>{{ ___('Have an account?') }}</h4>
                            <p>{{ ___('Log in for faster checkout') }}</p>
                        </div>
                        <button type="button" class="vd-btn-secondary" id="vd-checkout-login-btn">{{ ___('Login') }}</button>
                    </div>
                    @endif
                    <div id="vd-guest-fields">
                @endif

                    @if($customer)
                        <input type="hidden" name="name" value="{{ $customer->name }}">
                        <div class="vd-field" data-field="table" style="display:none">
                            <label>{{ ___('Table') }}</label>
                            @if($table)<input aria-label="{{ ___('Table number') }}" type="text" name="table" value="{{ $table->table_no }}" readonly>
                            @else<input aria-label="{{ ___('Table number') }}" type="number" name="table" placeholder="{{ ___('Table number') }}">@endif
                        </div>
                        <div class="vd-field" data-field="phone" style="display:none">
                            <label>{{ ___('Phone number') }}</label>
                            <input aria-label="{{ ___('Phone number') }}" type="tel" name="phone-number" value="{{ $customer->phone }}" placeholder="{{ ___('Phone number') }}">
                        </div>
                        <div class="vd-field" data-field="address" style="display:none">
                            <label>{{ ___('Delivery address') }}</label>
                            {{-- Saved addresses (logged-in): tap to fill the field + coords. --}}
                            @if($customerAddresses->count())
                            <div class="vd-saved-addrs" id="vd-saved-addrs">
                                @foreach($customerAddresses as $sa)
                                <button class="vd-saved-addr{{ $loop->first ? ' is-on' : '' }}" type="button"
                                        data-addr="{{ $addrLabel($sa) }}" data-lat="{{ $sa->lat }}" data-lng="{{ $sa->lng }}">
                                    <i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i>
                                    <span class="vd-sa-text"><b>{{ $sa->label ?: ___('Saved address') }}</b><small>{{ Str::limit($addrLabel($sa), 42) }}</small></span>
                                </button>
                                @endforeach
                            </div>
                            @endif
                            <textarea aria-label="{{ ___('Delivery address') }}" name="address">{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}</textarea>
                            <input type="hidden" id="vd-addr-lat" name="address_lat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                            <input type="hidden" id="vd-addr-lng" name="address_lng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                        </div>
                    @else
                        <div class="vd-field">
                            <label>{{ ___('Your Name') }}</label>
                            <input aria-label="{{ ___('Your Name') }}" type="text" name="name" required placeholder="{{ ___('Your Name') }}">
                        </div>
                        <div class="vd-field" data-field="table" style="display:none">
                            <label>{{ ___('Table') }}</label>
                            @if($table)<input aria-label="{{ ___('Table number') }}" type="text" name="table" value="{{ $table->table_no }}" readonly>
                            @else<input aria-label="{{ ___('Table number') }}" type="number" name="table" placeholder="{{ ___('Table number') }}">@endif
                        </div>
                        <div class="vd-field" data-field="phone" style="display:none">
                            <label>{{ ___('Phone number') }}</label>
                            <input aria-label="{{ ___('Phone number') }}" type="tel" name="phone-number" placeholder="{{ ___('Phone number') }}">
                        </div>
                        <div class="vd-field" data-field="address" style="display:none">
                            <label>{{ ___('Delivery address') }}</label>
                            {{-- R6 · guest saved addresses (localStorage). Same spot + styling as
                                 the logged-in server chips above; JS fills these for guests. --}}
                            <div class="vd-saved-addrs" id="vd-saved-addrs" hidden></div>
                            <textarea aria-label="{{ ___('Delivery address') }}" name="address" placeholder="{{ ___('Delivery address') }}"></textarea>
                            <input type="hidden" name="address_lat">
                            <input type="hidden" name="address_lng">
                        </div>
                    @endif
                </div>

                @if($allow_payment)
                <div class="vd-field">
                    <label>{{ ___('Pay Via') }}</label>
                    <select aria-label="{{ ___('Pay Via') }}" name="pay_via" id="vd-payvia" required>
                        <option value="pay_on_counter">{{ ___('Pay On Counter') }}</option>
                        <option value="pay_online">{{ ___('Pay Online') }}</option>
                    </select>
                </div>
                @else
                <input type="hidden" name="pay_via" value="pay_on_counter">
                @endif

                @if($allowTips)
                <div class="vd-field">
                    <label>{{ ___('Tip') }} ({{ ___('optional') }})</label>
                    <div class="vd-tip-options">
                        <button type="button" class="vd-tip-btn" data-tip-pct="10">10%</button>
                        <button type="button" class="vd-tip-btn" data-tip-pct="15">15%</button>
                        <button type="button" class="vd-tip-btn" data-tip-pct="20">20%</button>
                        <button type="button" class="vd-tip-btn" data-tip-pct="custom">{{ ___('Custom') }}</button>
                    </div>
                    <input aria-label="{{ ___('Custom tip amount') }}" type="number" id="vd-custom-tip" placeholder="{{ ___('Custom tip amount') }}" style="display:none" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}">
                    <input type="hidden" name="tip_amount" id="vd-tip-amount" value="0">
                </div>
                @endif

                <div class="vd-field">
                    <label>{{ ___('Note') }} ({{ ___('optional') }})</label>
                    <textarea aria-label="{{ ___('Any special requests?') }}" name="message" placeholder="{{ ___('Any special requests?') }}"></textarea>
                </div>

                @if(!$customer)
                <div class="vd-field">
                    <div class="vd-field" style="display:none" id="vd-capture-email-wrap">
                        <label>{{ ___('Email') }} ({{ ___('optional') }})</label>
                        <input aria-label="{{ ___('Email') }}" type="email" name="capture_email" id="vd-capture-email" placeholder="your@email.com" autocomplete="email">
                    </div>
                    <label class="vd-consent-label">
                        <input type="checkbox" name="marketing_consent" value="1" id="vd-consent-cb"
                               data-csp-toggle="vd-capture-email-wrap">
                        <span>{{ ___('Send me offers and updates from this restaurant') }}
                            <a href="{{ route('publicView.unsubscribe.info', $store->slug) }}" target="_blank" class="vd-privacy-link">{{ ___('Privacy') }}</a>
                        </span>
                    </label>
                </div>
                @endif
            </form>

            <div class="vd-success" id="vd-order-success">
                <div class="vd-success-ico"><i class="ico ico-check" aria-hidden="true"><x-ui.icon name="check" /></i></div>
                <h3>{{ ___('Order placed!') }}</h3>
                <p>{{ ___('Thank you. Your order is on its way.') }}</p>
                @if($googleReviewUrl)
                <a href="{{ $googleReviewUrl }}" target="_blank" rel="noopener"
                    style="display:flex;align-items:center;gap:10px;max-width:320px;margin:20px auto 0;padding:13px 15px;border-radius:14px;text-decoration:none;background:rgba(242,169,59,.12);border:1px solid rgba(242,169,59,.35);color:inherit;text-align:start">
                    <span style="font-size:22px;line-height:1">⭐</span>
                    <span style="flex:1;min-width:0">
                        <b style="display:block;font-size:14px">{{ ___('Enjoying your meal?') }}</b>
                        <small style="opacity:.7;font-size:11.5px">{{ ___('Rate us on Google') }}</small>
                    </span>
                    <i class="ico ico-chevron" style="color:#f2a93b" aria-hidden="true"><x-ui.icon name="chevron" /></i>
                </a>
                @endif
            </div>
        </div>
        <div class="vd-sheet-foot" id="vd-cart-foot" style="display:none">
            @if($authMode === 'required' && !$customer)
            <button type="button" class="vd-cta" style="opacity:.5;cursor:not-allowed">
                <i class="ico ico-lock" aria-hidden="true"><x-ui.icon name="lock" /></i> {{ ___('Login Required') }}
            </button>
            @else
            <button type="submit" form="vd-order-form" class="vd-cta" id="vd-checkout-btn">
                <i class="ico ico-bolt" aria-hidden="true"><x-ui.icon name="bolt" /></i> <span data-tc-text="texts.checkout_button">{{ theme_setting($store->id, 'texts.checkout_button', ___('Place order')) }}</span>
                <span class="vd-cta-price" id="vd-checkout-total"></span>
            </button>
            @endif
        </div>
    </aside>
    @endif

    {{-- ═══════════════ theme-pack-blade feature overlays (F1/F3/F4/F6/F7) ═══════════════ --}}

    {{-- F3 · full-screen story viewer --}}
    <div class="vd-story-viewer" id="vd-story-viewer" hidden>
        <div class="vd-sv-progress" id="vd-sv-progress"></div>
        <button class="vd-sv-close" id="vd-sv-close" type="button" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        <div class="vd-sv-stage" id="vd-sv-stage">
            <img class="vd-sv-media" id="vd-sv-media" alt="">
            <button class="vd-sv-nav prev" id="vd-sv-prev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="vd-sv-nav next" id="vd-sv-next" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="vd-sv-foot">
                <p class="vd-sv-caption" id="vd-sv-caption"></p>
                <a class="vd-cta vd-sv-cta" id="vd-sv-cta" hidden><span></span></a>
            </div>
        </div>
    </div>

    {{-- F4 · nearest-branch map picker --}}
    @if($vdShowBranches)
    <div class="vd-scrim" id="vd-branchmap-scrim"></div>
    <aside class="vd-sheet vd-sheet-lg" id="vd-branchmap-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ ___('Nearest branch') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="branchmap" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            <div class="vd-branchmap" id="vd-branchmap"></div>
            <p class="vd-geo-attrib" id="vd-map-attrib"></p>
            <div class="vd-branch-geolist" id="vd-branch-geolist"></div>
        </div>
    </aside>
    @endif

    {{-- F6 · write-review sheet --}}
    <div class="vd-scrim" id="vd-review-scrim"></div>
    <aside class="vd-sheet" id="vd-review-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ ___('Write a review') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="review" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            <p class="vd-review-itemname" id="vd-review-itemname"></p>
            <div class="vd-rv-stars" id="vd-review-stars">
                @for($n = 1; $n <= 5; $n++)<button data-star="{{ $n }}" type="button" aria-label="{{ $n }}">★</button>@endfor
            </div>
            @if(!$customer)
            <div class="vd-field"><input aria-label="{{ ___('Your Name') }}" type="text" id="vd-review-name" placeholder="{{ ___('Your Name') }}" autocomplete="name"></div>
            @endif
            <div class="vd-field"><textarea aria-label="{{ ___('Tell others about this dish…') }}" id="vd-review-text" rows="3" placeholder="{{ ___('Tell others about this dish…') }}"></textarea></div>
            <p class="vd-auth-error" id="vd-review-err" style="display:none"></p>
            <button class="vd-cta" id="vd-review-submit" type="button">{{ ___('Submit review') }}</button>
        </div>
    </aside>

    {{-- F1 · start-a-meal-plan sheet --}}
    @if($mealPlansEnabled)
    <div class="vd-scrim" id="vd-mealplan-scrim"></div>
    <aside class="vd-sheet" id="vd-mealplan-sheet" aria-hidden="true" data-cadences='@json($mealPlanCadences)'>
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ ___('Create a meal plan') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="mealplan" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            @if($customer)
            <div id="vd-mp-form">
                <p class="vd-review-itemname" id="vd-mp-summary"></p>
                <div class="vd-field"><label>{{ ___('Plan name') }} ({{ ___('optional') }})</label><input aria-label="{{ ___('e.g. Weekly lunch') }}" type="text" id="vd-mp-name" placeholder="{{ ___('e.g. Weekly lunch') }}"></div>
                <div class="vd-field"><label>{{ ___('How often?') }}</label><div class="vd-seg" id="vd-mp-cadence"></div></div>
                <div class="vd-field" id="vd-mp-days-wrap" style="display:none">
                    <label>{{ ___('Which days?') }}</label>
                    <div class="vd-filter-chips" id="vd-mp-days">
                        @foreach(['Sun'=>0,'Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6] as $d => $i)
                            <button class="vd-filter-chip" data-day="{{ $i }}" type="button">{{ ___($d) }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="vd-field"><label>{{ ___('Start date') }}</label><input aria-label="{{ ___('Start date') }}" type="date" id="vd-mp-start" min="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}"></div>
                <p class="vd-auth-error" id="vd-mp-err" style="display:none"></p>
                <button class="vd-cta" id="vd-mp-submit" type="button">{{ ___('Create plan') }}</button>
            </div>
            <div id="vd-mp-done" style="display:none;text-align:center;padding:24px 0">
                <div style="font-size:42px">📅</div>
                <h3 style="margin:10px 0 6px">{{ ___('Meal plan created!') }}</h3>
                <p style="color:var(--vd-muted)" id="vd-mp-done-sub"></p>
                <a class="vd-ghostbtn" href="{{ route('customer.account', $store->id) }}">{{ ___('Manage plans') }}</a>
            </div>
            @else
            <p style="color:var(--vd-muted);margin-bottom:14px">{{ ___('Log in to create a recurring meal plan.') }}</p>
            <button class="vd-cta" id="vd-mp-login-btn" type="button">{{ ___('Login') }}</button>
            @endif
        </div>
    </aside>
    @endif

    {{-- F7 · OTP checkout gate sheet --}}
    <div class="vd-scrim" id="vd-checkoutotp-scrim"></div>
    <aside class="vd-sheet" id="vd-checkoutotp-sheet" aria-hidden="true">
        <div class="vd-sheet-handle"></div>
        <div class="vd-sheet-head">
            <h3>{{ ___('Verify your phone') }}</h3>
            <button class="vd-close" type="button" data-close-sheet="checkoutotp" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="vd-sheet-body">
            <p style="color:var(--vd-muted);margin-bottom:12px">{{ ___('Enter the code we sent to') }} <b id="vd-otp-gate-phone" dir="ltr"></b></p>
            <div class="vd-otp-boxes" id="vd-otp-gate-boxes">
                <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
            </div>
            <p class="vd-auth-error" id="vd-otp-gate-err" style="display:none"></p>
            <button class="vd-cta" id="vd-otp-gate-verify" type="button">{{ ___('Verify & place order') }}</button>
            <button class="vd-ghostbtn" id="vd-otp-gate-resend" type="button" style="margin-top:8px">{{ ___('Resend code') }}</button>
        </div>
    </aside>
</div>

{{-- TP: catering request form (gated) — opens as a shared modal from the header
     button; no longer sits inline below the footer. --}}
@include('store_templates._partials.catering-form', ['cateringModal' => true, 'cateringSurface' => 'var(--tc-surface, #ffffff)', 'cateringInk' => 'var(--tc-text, #1a1916)'])

{{-- Shared notification-center panel (bell lives in the topbar icon row) --}}
@include('store_templates._partials.notif-bell')

{{-- VIVID 3.0 · bottom dock (soiree-parity). Thin launchers over existing
     controls — scroll home / scroll menu / focus search / open the cart via
     the topbar toggle. Wired in script.js; badge mirrors #vd-cart-count. --}}
<nav class="vd-dock" id="vdDock" aria-label="{{ ___('Main') }}">
    <button class="vd-dock-btn is-on" data-vddock="home" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg>
        <span>{{ ___('Home') }}</span>
    </button>
    <button class="vd-dock-btn" data-vddock="menu" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1" fill="currentColor" stroke="none"/></svg>
        <span>{{ ___('Menu') }}</span>
    </button>
    @if(theme_setting($store->id, 'search.enabled', true))
    <button class="vd-dock-btn" data-vddock="search" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <span>{{ ___('Search') }}</span>
    </button>
    @endif
    @if($allow_order)
    <button class="vd-dock-btn" data-vddock="cart" type="button" style="position:relative">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
        <span>{{ ___('Cart') }}</span>
        <i class="vd-dock-badge" id="vdDockCartCount" hidden>0</i>
    </button>
    @endif
    <button class="vd-dock-btn" data-vddock="favs" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.35-9.5-8.5C1 8 2.5 4.5 6 4.5c2 0 3.2 1.2 4 2.3.8-1.1 2-2.3 4-2.3 3.5 0 5 3.5 3.5 7C19 15.65 12 20 12 20Z"/></svg>
        <span>{{ ___('Favorites') }}</span>
    </button>
    @if($authMode !== 'guest_only')
    <button class="vd-dock-btn" data-vddock="profile" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
        <span>{{ ___('Profile') }}</span>
    </button>
    @endif
</nav>

{{-- Favorites sheet — vivid has hearts on items but had no way to view them.
     Opened by the topbar heart and the dock Favorites button. --}}
<div class="vd-scrim" id="vd-fav-scrim"></div>
<aside class="vd-sheet vd-sheet-lg" id="vd-fav-sheet" aria-hidden="true">
    <div class="vd-sheet-handle"></div>
    <div class="vd-sheet-head">
        <h3>{{ ___('Favorites') }}</h3>
        <button class="vd-close" type="button" data-close-sheet="fav" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
    </div>
    <div class="vd-sheet-body">
        <div id="vd-fav-list"></div>
        <div class="vd-cart-empty" id="vd-fav-empty">
            <div class="vd-empty-ico"><i class="ico ico-heart-filled" aria-hidden="true"><x-ui.icon name="heart-filled" /></i></div>
            <h3>{{ ___('No favorites yet') }}</h3>
            <p>{{ ___('Tap the heart on any dish to save it here.') }}</p>
        </div>
    </div>
</aside>

@endsection

@push('scripts_at_bottom')
{{-- F4 · self-hosted Leaflet (loads before script.js) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
{{-- P3-13: totals from the server quote (THEME-CONTRACT §5.2). --}}
<script src="{{ asset('assets/store_templates/_shared/js/mb-quote.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-quote.js')) }}"></script>
<script nonce="{{ csp_nonce() }}">
    window.VIVID = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        orderUrl:       @json($orderUrl),
        currencySign:   @json($curSign),
        currencyGap:    @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:    @json($curPos),
        currencyDecimals: @json(currency_decimals()),
        moneyDecimals: @json(money_decimals()), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        multiCurrency:  @json(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0),
        currencyRates:  @json($mcRates),
        baseCurrency:   @json(config('settings.currency_code', 'USD')),
        deliveryCharge: @json($deliveryCharge),
        taxCharge:      @json($taxCharge),
        /* TOTALS CONTRACT: 'fixed' is a FLAT amount, not a rate — the cart has
           to know which, or it bills a flat-tax store a percentage of the basket. */
        taxType:        @json((string) (@$postOptions->restaurant_tax_type ?: 'percentage')),
        minOrderValue:  @json($minOrderValue),
        allowOrderSched:@json($allowOrderSched),
        allowOrder:     @json((bool) $allow_order),
        allowPayment:   @json((bool) $allow_payment),
        allowTips:      @json($allowTips),
        allowCoupons:   @json($allowCoupons),
        allowWaiter:    @json($allowWaiter),
        defaultTable:   @json($table->table_no ?? null),
        forceTable:     @json((bool) $table),
        authMode:       @json($authMode),
        isLoggedIn:     @json((bool) $customer),
        items:          @json((object) $jsItems),
        csrf:           @json(csrf_token()),
        openResSheet:      @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        loyaltyType:      @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json((int) ($loyaltyProgram->reward_threshold ?? 0)),
        /* theme-pack-blade features (F1–F7) */
        customerToken:    @json($customerApiToken),
        isFollowing:      @json((bool) $isFollowing),
        followerCount:    @json((int) $followerCount),
        followNotify:     @json((bool) $followNotify),
        mealPlansEnabled: @json((bool) $mealPlansEnabled),
        otpGateEnabled:   @json((bool) $otpGateEnabled),
        leafletImgPath:   @json(asset('assets/vendor/leaflet/images')),
        endpoints: {
            login:    @json(route('customer.login', $store->id)),
            register: @json(route('customer.register', $store->id)),
            logout:   @json(route('customer.logout', $store->id)),
            otpRequest: @json(route('customer.otp.request', $store->id)),
            otpVerify:  @json(route('customer.otp.verify', $store->id)),
            loyaltyBalance: @json($loyaltyProgram ? route('publicView.loyalty.balance', $store->id) : null),
            loyaltyRedeem:  @json($loyaltyProgram ? route('publicView.loyalty.redeem', $store->id) : null),
            /* The cart used to POST a hardcoded "/api/apply-coupon", which is not a
               route in this application — every coupon attempt 404'd and the theme
               reported "✗ Error". Named route, keyed by $store->id (NOT the slug —
               that is the 404-across-14-themes bug this run already fixed once). */
            coupon:         @json(route('store.applyCoupon', $store->id)),
            /* Same trap as the coupon: script.js built "/{slug}/call-waiter" by hand,
               but the route model-binds `Post $store` by PRIMARY KEY, so every
               request 404'd with "No query results for model [Post] theme-lane-d" and
               no WaiterCall row was ever written. Every other theme uses $store->id. */
            callWaiter:     @json(route('store.callTheWaiter', $store->id)),
            itemEvent:      @json(route('store.recordItemEvent', $store->slug)),
            nps:            @json(route('store.submitNps', $store->slug)),
            orderLookup:    @json(route('store.orderLookup', $store->slug)),
            review:         @json(route('store.submitReview', $store->slug)),
            follow:         @json(url('r/'.$store->slug.'/follow')),
            stories:        @json(route('stories.public', $store->slug)),
            branchesGeo:    @json(route('geo.branches', $store->slug)),
            geoAutocomplete:@json(route('geo.autocomplete')),
            geoReverse:     @json(route('geo.reverse')),
            reviewsBase:    @json((string) store_options($store->id, 'item_reviews_enabled', '1') !== '0' ? url('r/'.$store->id.'/item') : ''),
            mealPlan:       @json(url('customer/meal-plan')),
            otpSend:        @json(route('checkout.otp.send')),
            otpVerify2:     @json(route('checkout.otp.verify')),
        },
        i18n: {
            add:         @json(theme_setting($store->id, 'texts.add_to_cart', '') ?: ___('Add to order')),
            failed:      @json(___('Failed')),
            networkError: @json(___('Network error')),
            currencyNote: @json(___('Prices shown in :cur (approximate). Charged in base currency.')),
            customize:   @json(___('Customize')),
            choose:      @json(___('Choose an option')),
            extras:      @json(___('Add extras')),
            quantity:    @json(___('Quantity')),
            subtotal:    @json(___('Subtotal')),
            delivery:    @json(___('Delivery')),
            tax:         @json(___('Tax')),
            discount:    @json(___('Discount')),
            tip:         @json(___('Tip')),
            total:       @json(___('Total')),
            view_cart:   @json(___('View Cart')),
            remove:      @json(___('Remove')),
            items:       @json(___('items')),
            item:        @json(___('item')),
            note:        @json(___('Note')),
            note_placeholder: @json(___('Any special requests?')),
            selectType:  @json(___('Please choose an order type')),
            free:        @json(___('Free')),
            soldOut:     @json(___('Sold Out')),
            minOrder:    @json(___('Minimum order')),
            rate_this_item: @json(___('Rate this dish')),
            your_name:   @json(___('Your name (optional)')),
            your_review: @json(___('Share your experience...')),
            submit:      @json(___('Submit')),
            /* theme-pack-blade features */
            follow:      @json(___('Follow')),
            following:   @json(___('Following')),
            reviews:     @json(___('Reviews')),
            rv_write:    @json(___('Write')),
            rv_verified: @json(___('Verified')),
            rv_latest:   @json(___('Latest')),
            rv_detailed: @json(___('Detailed')),
            rv_empty:    @json(___('No reviews yet — be the first!')),
            cad_daily:   @json(___('Daily')),
            cad_weekly:  @json(___('Weekly')),
            cad_custom:  @json(___('Custom days')),
            mp_next:     @json(___('Next order')),
            mp_items:    @json(___('items')),
            use_location:@json(___('Use my location')),
            find_branch: @json(___('Find nearest branch on map')),
            enter_code:  @json(___('Enter the code')),
            thanks:      @json(___('Thanks!')),
            order_now:   @json(theme_setting($store->id, 'texts.order_now', ___('Order now'))),
            /* call-waiter sheet — script.js used to hardcode these in English and
               overwrite the blade's translated copy on open. */
            callWaiter:    @json(___('Call Waiter')),
            requestBill:   @json(___('Request Bill')),
            waiterContext: @json(___('We will send a waiter to your table right away.')),
            billContext:   @json(___('We will bring the bill to your table.')),
            waiterSent:    @json(___('Waiter called!')),
            billSent:      @json(___('Bill request sent!')),
            /* reorder handoff from the account page */
            reorderAdded:   @json(___('Your previous order is back in the cart.')),
            reorderPartial: @json(___('Some items were unavailable and were skipped.')),
            now_following:  @json(___('You are now following :store', ['store' => $store->title])),
            action_failed:  @json(___('Something went wrong, please try again.')),
            view_stories:   @json(___('View stories')),
            loc_unavailable: @json(___('Location unavailable')),
            loc_denied:     @json(___('Location denied')),
            no_address:     @json(___('No address found')),
            cart_empty:     @json(___('Cart is empty')),
            invalid_code:   @json(___('Invalid code')),
        }
    };
</script>
<script nonce="{{ csp_nonce() }}">
// Sticky table session
(function () {
    var slug = @json($store->slug);
    var storeKey = 'mb-table-' + slug;
    var urlTable = new URLSearchParams(location.search).get('table');
    if (urlTable) sessionStorage.setItem(storeKey, urlTable);
    var storedTable = sessionStorage.getItem(storeKey);
    if (storedTable) {
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('input[name="table"]:not([readonly])').forEach(function (inp) {
                if (!inp.value) inp.value = storedTable;
            });
            var wt = document.getElementById('vd-waiter-table');
            if (wt && !wt.value && !wt.readOnly) wt.value = storedTable;
        });
    }
})();
</script>
@endpush
@push('scripts_at_bottom')
@endpush

@push('scripts_at_bottom')
{{-- ZERO-TOUCH A3.1 — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endpush
