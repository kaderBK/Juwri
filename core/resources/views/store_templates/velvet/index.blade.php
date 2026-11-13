{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       Data preparation for Velvet Theme
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus = collect($total_menus)->values();

    // Branch availability/pricing is applied in the controller (new branch_id system).
    $items = $allMenus->values();
    $byCat = $items->groupBy('category_id');

    // Every category, flattened parent-then-children. Items are grouped by their own
    // category_id, so a subcategory's items are only reachable if the subcategory
    // itself is iterated — menu_categories is top-level only. See
    // Post::menu_categories_flat_filled (also drops empty categories so no chip
    // opens an empty grid).
    $categories = $store->menu_categories_flat_filled;

    /* Two-tier category nav (primary parent chips + a secondary sub-category chip
       row that swaps per active parent — the Talabat/UberEats pattern; ported from
       plate). velvet renders one server-side <section> per flat category, so a menu
       item lives in exactly one section keyed on its OWN category_id (which may be a
       subcategory id). $rootOf maps any category id → its top-level ancestor so a
       parent chip reveals every descendant section; $catNameOf keeps each category's
       own name. The parent chips / $subCatsOf / $jsCats are built AFTER the jsItems
       loop, once per-category item counts are known. */
    $rootOf    = [];
    $catNameOf = [];
    foreach ($store->menu_categories as $parentCat) {
        $rootOf[$parentCat->id]    = $parentCat->id;
        $catNameOf[$parentCat->id] = $parentCat->name;
        foreach ($parentCat->subcategories as $subCat) {
            $rootOf[$subCat->id]    = $parentCat->id;
            $catNameOf[$subCat->id] = $subCat->name;
        }
    }
    $rootItemCount = [];   // top-level id => descendant item count (parent chips)
    $catItemCount  = [];   // real cat id  => own item count (sub-chips)

    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $curGap  = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only

    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)
    $deliveryCharge    = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge         = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $minOrderValue     = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched   = !empty($postOptions->allow_order_scheduling);
    $allowCoupons      = @$postOptions->allow_coupons == 1;
    $allowTips         = @$postOptions->allow_tips == 1;
    $allowWaiter       = !empty($postOptions->allow_call_waiter);
    $allowDietary      = !isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;
    // R4 · open/closed status (owner toggle; unset defaults to open, matching crave).
    $isOpen            = store_open_now($store);

    // Human label for variants
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    // Prepare JS data mapping
    $jsItems = [];
    foreach ($items as $m) {
        $itemAllergies = empty($m->allergies)
            ? collect()
            : $allergies->whereIn('id', explode(',', $m->allergies));

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
                $extras[] = [
                    'id'    => $e->id,
                    'title' => $e->title,
                    'price' => (float) $e->price,
                    'img'   => $e->image && $e->image !== 'default.png' ? asset('storage/menu/'.$e->image) : '',
                ];
            }
        }

        // 'root' = the item's top-level ancestor (parent-chip filter); 'cat' stays the
        // item's real category id (sub-chip filter). Orphan → falls back to self.
        $rootCat = $rootOf[$m->category_id] ?? $m->category_id;
        $rootItemCount[$rootCat] = ($rootItemCount[$rootCat] ?? 0) + 1;
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;

        $jsItems[$m->id] = [
            'id'             => $m->id,
            'cat'            => $m->category_id,
            'root'           => $rootCat,
            'name'           => $m->name,
            // Descriptions may be stored as numeric HTML entities; strip_tags leaves
            // them intact and Blade re-escapes the `&`, printing the raw entity text.
            'desc'           => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'img'            => $m->image && $m->image !== 'default.png' ? asset('storage/menu/'.$m->image) : '',
            'price'          => (float) $m->price,
            // pre-schedule reference price (MenuScheduleService::decorate) — cart strike-through
            'original_price' => (!is_null($m->original_price ?? null) && (float) $m->original_price > (float) $m->price) ? (float) $m->original_price : null,
            'type'           => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'         => $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : null,
            'rating_count'   => $m->rating_count ?? 0,
            'allergies'      => \Juwri\Core\Support\ViewHelpers::allergyListAsset($itemAllergies),
            'variants'       => $variants,
            /* WAVE4-V4 · velvet headed the variant group with the CTA label, so the
               sheet read "Add to order" over Regular/Spicy and "Add to order Extras"
               over the extras — FEAST-F9 repeating. Use the store's real
               MenuVariantOption group name ("Style" here) and fall back to a neutral
               prompt when it has none. */
            'variantGroup'   => (string) (optional($m->variantOptions->first())->title ?: ''),
            'extras'         => $extras,
            'is_vegan'       => (bool) $m->is_vegan,
            'is_halal'       => (bool) $m->is_halal,
            'is_gluten_free' => (bool) $m->is_gluten_free,
            'is_popular'     => (bool) $m->is_popular,
            'is_daily_special' => (bool) ($m->is_daily_special ?? false),
            'spice_level'    => $m->spice_level ?? 'none',
            'is_sold_out'    => (bool) $m->is_sold_out || ($m->track_inventory && $m->stock_qty <= 0),
            'calories'       => $m->calories ?? null,
            'portion_size'   => $m->portion_size ?? null,
            'gallery'        => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryAssets($m->gallery_images)
                : [],
            'optionGroups'   => \Juwri\Core\Support\ViewHelpers::optionGroups($m->globalGroups ?? []),
        ];
    }

    /* Primary chips: top-level parents that hold at least one descendant item
       (pure containers / empty parents drop, so no chip opens an empty section). */
    $parentCats = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);

    /* Secondary chips: per parent, only the subcategories that own items. A parent
       with no item-bearing subcategory gets no second row. */
    $subCatsOf = [];
    foreach ($parentCats as $c) {
        $subs = \Juwri\Core\Support\ViewHelpers::withItems($c->subcategories, $catItemCount);
        if ($subs->isNotEmpty()) {
            $subCatsOf[$c->id] = $subs;
        }
    }

    /* JS chip data: each parent carries id + name + its item-bearing subs. */
    $jsCats = $parentCats->map(fn($c) => [
        'id'   => (string) $c->id,
        'name' => $c->name,
        'subs' => collect($subCatsOf[$c->id] ?? [])
            ->map(fn($s) => ['id' => (string) $s->id, 'name' => $s->name])->values(),
    ])->values();

    /* today's-specials rail = daily specials first, fall back to popular, then first 8
       (same source contract as crave/vivid: is_daily_special → is_popular). */
    $specials = \Juwri\Core\Support\ViewHelpers::flagged($items, 'is_daily_special', 8);
    if ($specials->count() < 1) $specials = \Juwri\Core\Support\ViewHelpers::flagged($items, 'is_popular', 8);
    if ($specials->count() < 3) $specials = $items->take(8);

    /* bespoke velvet splash imagery (first 3 real-photo dishes) */
    $splashImgs = \Juwri\Core\Support\ViewHelpers::imageItems($items, 3);

    /* menu_sections visibility (daily_specials rail can be hidden by the owner) */
    $savedSections = isset($postOptions->menu_sections)
        ? json_decode(json_encode($postOptions->menu_sections), true) : null;
    $sectionVisible = ['hero' => true, 'brand_row' => true, 'catbar' => true, 'daily_specials' => true, 'menu' => true];
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

    /* customer + engagement extras (parity build — velvet gains a customer layer) */
    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();
    // Saved delivery addresses (logged-in): tappable chips that fill the address field + coords.
    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $customerDefaultAddress = $customerAddresses->first();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);
    // WIRING-RUN G3 (coupons-wallets P7-6a): pay-with-credit for a logged-in diner with
    // a balance. use_wallet rides to submitOrder via the shared order-field forwarder.
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));
    $myWaitlist = storefront()->activeWaitlist($store, $customer);
    $myReservation = storefront()->upcomingReservation($store, $customer);

    /* ── theme-pack-blade features (F1–F7) — mirror crave's reference wiring ──
       F1/F2/F6 hit CustomerToken-guarded routes; mint (and session-cache) a raw
       token for the signed-in session customer so theme JS can call them. */
    $customerApiToken = storefront()->customerApiToken($customer, 'velvet');

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

    /* F4 · nearest-branch map is only meaningful with 2+ branches */
    $showBranches = !empty($branches) && count($branches) > 1;
@endphp

@push('style_at_top')
    <!-- Google Fonts for Velvet Theme (Playfair Display & Outfit) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- F4 · self-hosted Leaflet (CSP script-src is self+nonce; tiles load as https imgs) --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
@endpush

@section('content')
<div id="velvet-app" class="velvet-container {{ current_language()->direction === 'rtl' ? 'rtl-dir' : 'ltr-dir' }}">

    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="v-splash" id="v-splash" role="presentation" aria-hidden="true">
        <div class="v-splash-inner">
            @if($store->main_image)
                <img class="v-splash-logo" src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="" data-csp-error="hide">
            @endif
            <div class="v-splash-hero" aria-hidden="true">
                <i class="v-splash-ring"></i>
                @foreach($splashImgs as $si)
                    <img class="v-splash-pop v-splash-pop-{{ $loop->index }}" src="{{ asset('storage/menu/'.$si->image) }}" alt="" data-csp-error="remove">
                @endforeach
            </div>
            <h1 class="v-splash-title">{{ $store->title }}</h1>
            <p class="v-splash-tag">{{ $store->sub_title ?: ___('Digital Menu') }}</p>
        </div>
        <button class="v-splash-skip" id="v-splash-skip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- Ambient Decorative Backdrops --}}
    <div class="v-ambient-1"></div>
    <div class="v-ambient-2"></div>

    {{-- VELVET 3.0 · sticky topbar. The theme shipped with NO header chrome at
         all — the page opened on a bare cover photo, and the language/account/
         bell controls floated as loose pills mid-header. This is the app bar
         every other retained theme already has; the controls below MOVED here
         (same ids/hooks, single instances). --}}
    <header class="v-topbar" id="vTopbar">
        <span class="v-topbar-brand">
            @if($store->main_image)
                <img src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="" data-csp-error="hide">
            @endif
            <b>{{ theme_setting($store->id, 'texts.hero_title', '') ?: $store->title }}</b>
        </span>
        <span class="v-topbar-actions">
            @if(!empty($menu_languages) && count($menu_languages) > 1)
                <button type="button" class="v-lang-btn" id="v-lang-open" aria-label="{{ ___('Language') }}" aria-haspopup="dialog">
                    <svg class="v-lang-globe" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    <span>{{ strtoupper(get_lang()) }}</span>
                </button>
            @endif
            @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
                <button type="button" class="v-lang-btn v-cur-btn" id="v-cur-open" aria-label="{{ ___('Currency') }}" aria-haspopup="dialog">
                    <svg class="v-cur-glyph" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3v18M8 7.5C8 6 9.6 5 12 5s4 1 4 2.5S14.5 10 12 10s-4 1-4 2.5S9.5 15 12 15s4 1 4 2.5-1.6 2.5-4 2.5-4-1-4-2.5"/></svg>
                    <span id="v-cur-label">{{ config('settings.currency_code', 'USD') }}</span>
                </button>
            @endif
            @if($authMode !== 'guest_only')
                @if($customer)
                    <button type="button" id="v-account-open" class="v-account-chip">
                        <span class="v-account-avatar">{{ Str::upper(Str::substr($customer->name ?? 'G', 0, 1)) }}</span>
                        <span>{{ $customer->name ?? ___('My Account') }}</span>
                    </button>
                @else
                    <button type="button" id="v-auth-open" class="v-account-chip" aria-label="{{ ___('Login') }} / {{ ___('Register') }}">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>{{ ___('Login') }} / {{ ___('Register') }}</span>
                    </button>
                @endif
            @endif
            @if(storefront_catering_enabled($store->id))
            {{-- velvet is a scrolling theme (no sheet framework); the catering form
                 stays inline and this header button jumps to it. data-catering-open
                 still fires the shared guest-autofill. --}}
            <a id="cvCateringBtn" class="v-lang-btn" href="#catering" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
            </a>
            @endif
            <button type="button" id="mbNotifBell" class="v-lang-btn" aria-label="{{ ___('Notifications') }}" style="position:relative">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                <i id="mbNotifDot" hidden style="position:absolute;top:2px;inset-inline-end:2px;width:9px;height:9px;border-radius:50%;background:var(--v-accent,#d4af37);border:2px solid var(--v-bg-dark,#0f0e0c)"></i>
            </button>
        </span>
    </header>

    {{-- Luxury Header / Cover --}}
    <header class="v-header">
        @if($store->cover_image)
            <div class="v-cover-wrapper">
                <img src="{{ asset('storage/store/cover/'.$store->cover_image) }}" alt="Cover" class="v-cover-img" data-csp-error="hide">
                <div class="v-cover-overlay"></div>
            </div>
        @endif
        
        {{-- data-mb-hotel-book-slot / -chip-after: the hotel "Book a room" CTA and the
             room chip (shared partial) mount right AFTER this element — below the whole
             hero band's content, never over the cover art. velvet's brand row is nested
             inside this band, so the plain data-mb-hotel-chip-slot hook would nest the
             chip over the artwork. The two entries never coexist (the chip is the
             in-room layer, the CTA the no-room one). --}}
        <div class="v-header-content" data-mb-hotel-book-slot data-mb-hotel-chip-after>
            <div class="v-brand-row">
                @if($store->main_image)
                    <img src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="{{ $store->title }} logo" class="v-restaurant-logo" data-csp-error="hide">
                @endif
                <div class="v-brand-details">
                    <h1 class="v-restaurant-title">
                        <span data-tc-text="texts.hero_title">{{ theme_setting($store->id, 'texts.hero_title', '') ?: $store->title }}</span>@if($isVerifiedStore)<span class="v-verified-badge" id="cvVerifiedBadge" role="img" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg viewBox="0 0 24 24"><path d="m4.5 12.5 4 4 11-11" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>@endif
                    </h1>
                    @php $vHeroSubtitle = theme_setting($store->id, 'texts.hero_subtitle', '') ?: $store->sub_title; @endphp
                    @if($vHeroSubtitle)
                        <p class="v-restaurant-subtitle" data-tc-text="texts.hero_subtitle">{{ $vHeroSubtitle }}</p>
                    @endif
                    {{-- F2 · follow / verified brand row (B10: customizer section, default on) --}}
                    @if($sectionVisible['brand_row'] ?? true)
                    <div class="v-follow-row" id="cvBrandRow" data-tc-section="brand_row" data-mb-hotel-chip-slot>
                        {{-- Following needs a customer account: on a guest_only store the auth
                             sheet is never rendered, so openAuth() does not exist and the click
                             handler returns silently. Hide the control instead of shipping a
                             button that cannot do anything. --}}
                        @if($authMode !== 'guest_only' || $customer)
                        <button class="v-followbtn {{ $isFollowing ? 'is-on' : '' }}" id="cvFollowBtn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            <span class="v-follow-label" data-tc-text="texts.follow_label">{{ theme_setting($store->id, 'texts.follow_label', ___('Follow')) }}</span>
                            <span class="v-following-label">{{ ___('Following') }}</span>
                        </button>
                        @endif
                        @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true)])
                    </div>
                    @endif
                </div>
            </div>

            @if($store->description)
                <p class="v-restaurant-desc">{{ html_entity_decode(strip_tags((string) $store->description), ENT_QUOTES) }}</p>
            @endif

            <div class="v-restaurant-meta">
                {{-- R4 · open-now / closed status chip --}}
                <span class="v-open-chip {{ $isOpen ? '' : 'is-closed' }}" data-mb-openchip data-mb-closed-class="is-closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                @if($store->address)
                    <div class="v-meta-item">
                        <svg class="v-meta-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span>{{ $store->address }}</span>
                    </div>
                @endif
                @if($store->phone)
                    <div class="v-meta-item">
                        <svg class="v-meta-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.94.725l.548 2.2a1 1 0 01-.321.988l-1.305.98a10.582 10.582 0 004.872 4.872l.98-1.305a1 1 0 01.988-.321l2.2.548a1 1 0 01.725.94V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        <span>{{ $store->phone }}</span>
                    </div>
                @endif
                {{-- B2: the "N viewing now" proof moved into the brand/follow row
                     (single source of proof — see the follower-count partial). --}}
            </div>

            {{-- #76 Branch selector (new branch_id system) --}}
            @include('store_templates.partials.branch-switcher')

            {{-- Language / currency / account / bell moved to the sticky
                 .v-topbar above (VELVET 3.0) — same ids, single instances. --}}
        </div>
    </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


    {{-- F3 · stories rail (populated from /r/{slug}/stories) --}}
    <div class="v-stories-rail" id="cvStoriesRail" data-story-label="{{ ___('View story') }}" hidden></div>

    {{-- Promo banners slider: relocated below the hero header (it was the page's
         first section) and kept above the categories. --}}
    <div style="max-inline-size:1100px;margin-inline:auto;padding-inline:16px">
        @include('store_templates.includes.menu-banners-slider')
    </div>

    {{-- Sticky Navigation & Search Bar Container --}}
        {{-- Today's specials rail (is_daily_special → is_popular fallback), owner-gated --}}
        @if($specials->count() > 2 && $sectionVisible['daily_specials'])
        <section class="v-specials-section" id="v-specials-section">
            <div class="v-specials-head">
                <span class="v-specials-kicker">✦ {{ ___('Handpicked') }}</span>
                <h2 class="v-category-title v-specials-title" data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___("Today's specials")) }}</h2>
            </div>
            <div class="v-specials-rail" id="v-specials-rail">
                @foreach($specials as $sp)
                    @php $spSold = (bool) $sp->is_sold_out || ($sp->track_inventory && $sp->stock_qty <= 0); @endphp
                    <div class="v-special-card {{ $spSold ? 'v-sold-out' : '' }}" data-id="{{ $sp->id }}" role="button" tabindex="0">
                        <div class="v-special-img-wrap">
                            @if($sp->image && $sp->image !== 'default.png')
                                <img src="{{ asset('storage/menu/'.$sp->image) }}" alt="{{ $sp->name }}" class="v-special-img" loading="lazy" data-csp-error="parent-class" data-csp-class="v-special-noimg" data-csp-remove="true">
                            @endif
                            @if($spSold)
                                <span class="v-badge v-badge-soldout">{{ ___('Sold Out') }}</span>
                            @endif
                        </div>
                        <div class="v-special-body">
                            <h4 class="v-special-name">{{ $sp->name }}</h4>
                            <div class="v-special-price" data-base-price="{{ (float) $sp->price }}">{{ price_symbol_format($sp->price) }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

    {{-- data-mbt-anchor: velvet's category selector lives in this sticky bar, a
         SIBLING of <main> (not of the menu grid), so the shared placement helper
         cannot climb the injected order-type bar past it. Opt in to the shared
         anchor path — the home order-type bar lands directly above this bar (above
         the categories), never wedged between the categories and the items. The
         offers strip has no such hook and is lifted by the script at page end. --}}
    <div class="v-nav-bar-sticky" data-mbt-anchor>
        <div class="v-nav-bar-inner">
            @if(theme_setting($store->id, 'search.enabled', true))
            <div class="v-search-box">
                <svg class="v-search-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input aria-label="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dish, ingredients...')) }}" type="search" id="v-search-input" data-tc-text="texts.search_placeholder" placeholder="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dish, ingredients...')) }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
                <button id="v-search-clear" style="display: none;">&times;</button>
            </div>
            @endif

            @if($parentCats->count() > 0)
                {{-- Primary (parent) category chips. A parent chip reveals every
                     descendant section; data-has-sub marks parents that own a second row. --}}
                {{-- data-mb-grid-lead: the category selector must touch the items it
                     filters — injected sections (offers strip, order-type bar) climb
                     above these rows instead of wedging between categories and grid. --}}
                <nav class="v-categories-nav" aria-label="{{ ___('Categories') }}" data-mb-grid-lead>
                    <div class="v-categories-scroll" id="v-cats">
                        <button class="v-cat-btn active" data-cat="all" type="button">{{ ___('All') }}</button>
                        @foreach($parentCats as $cat)
                            <button class="v-cat-btn" data-cat="{{ $cat->id }}"
                                    @isset($subCatsOf[$cat->id]) data-has-sub="1" @endisset type="button">{{ $cat->name }}</button>
                        @endforeach
                    </div>
                    @if($allowDietary)
                    <button class="v-filter-btn" id="v-filter-btn" type="button" aria-label="{{ ___('Filters') }}">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 8h14M5 12h10M5 16h6"/></svg>
                        <i class="v-filter-dot" id="v-filter-dot" hidden></i>
                    </button>
                    @endif
                </nav>
                {{-- Secondary sub-category chips. One hidden group per parent that owns
                     item-bearing subcategories; JS reveals the active parent's group.
                     "All" (data-sub="root") = every descendant of the parent. --}}
                @if(!empty($subCatsOf))
                <nav class="v-subcats" id="v-subcats" aria-label="{{ ___('Sub-categories') }}" hidden data-mb-grid-lead>
                    @foreach($subCatsOf as $parentId => $subs)
                        <div class="v-subgroup" data-parent="{{ $parentId }}" hidden>
                            <button class="v-subcat active" data-sub="root" type="button">{{ ___('All') }}</button>
                            @foreach($subs as $sub)
                                <button class="v-subcat" data-sub="{{ $sub->id }}" type="button">{{ $sub->name }}</button>
                            @endforeach
                        </div>
                    @endforeach
                </nav>
                @endif
            @endif
        </div>
    </div>

    {{-- Main Menu Content --}}
    <main class="v-menu-main">


        @forelse($categories as $cat)
            @php $catItems = $byCat[$cat->id] ?? collect(); @endphp
            @if($catItems->count())
                <section class="v-menu-section" data-section-category-id="{{ $cat->id }}">
                    <h2 class="v-category-title">{{ $cat->name }}</h2>
                    <div class="v-menu-grid">
                        @foreach($catItems as $menu)
                            @php
                                $isSoldOut = (bool) $menu->is_sold_out || ($menu->track_inventory && $menu->stock_qty <= 0);
                            @endphp
                            <div class="v-menu-card {{ $isSoldOut ? 'v-sold-out' : '' }}"
                                 data-id="{{ $menu->id }}"
                                 data-cat="{{ $menu->category_id }}"
                                 data-root="{{ $rootOf[$menu->category_id] ?? $menu->category_id }}"
                                 role="group"
                                 aria-label="{{ $menu->name }}"
                                 tabindex="0">
                                <button class="v-fav-btn" type="button" data-vfav="{{ $menu->id }}" aria-label="{{ ___('Save') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
                                @if($menu->image && $menu->image !== 'default.png')
                                    <div class="v-card-image-wrap">
                                        <img src="{{ asset('storage/menu/'.$menu->image) }}" alt="{{ $menu->name }}" class="v-card-img" loading="lazy" data-csp-error="parent-hide">
                                        
                                    </div>
                                @endif

                                <div class="v-card-body">
                                    {{-- The Popular badge used to live ONLY inside .v-card-image-wrap,
                                         which is not rendered when an item has no photo — and every
                                         item on a fresh store carries default.png. So on the most
                                         common store in the product, neither "Popular" nor
                                         "Today's special" was ever visible on a card. These chips
                                         render from the data, not from whether a photo exists. --}}
                                    @if($menu->is_daily_special || $menu->is_popular || !empty($menu->schedule_label))
                                        <div class="v-card-flags">
                                            @if(!empty($menu->schedule_label))
                                                {{-- Happy-hour offer chip — v-card-was strike already rendered;
                                                     the flags row now also renders when ONLY the offer is live. --}}
                                                <span class="v-chip-flag v-chip-hh" style="background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff">{{ $menu->schedule_label }}</span>
                                            @endif
                                            @if($menu->is_daily_special)
                                                <span class="v-chip-flag v-chip-special">{{ ___("Today's special") }}</span>
                                            @endif
                                            @if($menu->is_popular)
                                                <span class="v-chip-flag v-chip-popular">{{ ___('Popular') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                    <div class="v-card-header">
                                        <h3 class="v-card-name"><a href="?item={{ $menu->id }}" style="color:inherit;text-decoration:none">{{ $menu->name }}</a></h3>
                                        <div class="v-card-price">
                                            @if(!is_null($menu->original_price) && (float) $menu->original_price > (float) $menu->price)
                                                {{-- K8: day-part / happy-hour reference price (MenuScheduleService::decorate) --}}
                                                <del class="v-card-was" style="opacity:.85;font-weight:400;margin-inline-end:.35em" data-base-price="{{ (float) $menu->original_price }}">{{ price_symbol_format($menu->original_price) }}</del>
                                            @endif
                                            {{-- data-base-price lets the #curSeg switcher repaint this server-rendered price --}}
                                            <span class="v-card-price-now" data-base-price="{{ (float) $menu->price }}">{{ price_symbol_format($menu->price) }}</span>
                                        </div>
                                    </div>

                                    @if($menu->description)
                                        <p class="v-card-desc">{{ Str::limit(html_entity_decode(strip_tags((string) $menu->description), ENT_QUOTES), 80) }}</p>
                                    @endif

                                    <div class="v-card-footer">
                                        <div class="v-card-labels">
                                            @if($menu->type === 'veg' || $menu->is_vegan)
                                                <span class="v-label-tag v-label-veg" title="{{ ___('Vegetarian') }}"></span>
                                            @elseif($menu->type === 'nonveg')
                                                <span class="v-label-tag v-label-nonveg" title="{{ ___('Non-Vegetarian') }}"></span>
                                            @endif

                                            @if($menu->is_halal)
                                                <span class="v-label-text">{{ ___('Halal') }}</span>
                                            @endif
                                            @if($menu->is_gluten_free)
                                                <span class="v-label-text">{{ ___('Gluten-Free') }}</span>
                                            @endif
                                            @if($menu->calories)
                                                <span class="v-label-text">{{ $menu->calories }} kcal</span>
                                            @endif
                                        </div>

                                        @if($isSoldOut)
                                            <span class="v-soldout-label">{{ ___('Sold Out') }}</span>
                                        @else
                                            <button class="v-quick-add-btn" data-add-id="{{ $menu->id }}" aria-label="Add to cart">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @empty
            <div class="v-no-items">
                <p>{{ ___('No menu items available.') }}</p>
            </div>
        @endforelse

        {{-- Searching or filtering to zero used to hide every section and leave a
             blank void between the sticky nav and the footer, with no explanation
             and no way back except guessing. Revealed by applyFilters(). --}}
        <div class="v-empty-results" id="v-empty-results" hidden>
            <span class="v-empty-mark" aria-hidden="true">✦</span>
            {{-- Deliberately built from keys that already carry a real translation in
                 every locale — a brand-new ___() string would auto-seed the ENGLISH
                 value into all 10 lang files and leak English into the Arabic
                 storefront, which is the exact defect this pass is closing. --}}
            <p class="v-empty-title">{{ ___('No items found') }}</p>
            <button type="button" class="v-ghostbtn v-empty-cta" id="v-empty-reset">{{ ___('Reset') }}</button>
        </div>

        {{-- TP: catering request form (gated) — opens as a shared modal from the
             header button; no longer sits inline below the menu/footer. --}}
        @include('store_templates._partials.catering-form', ['cateringModal' => true, 'cateringSurface' => 'var(--tc-surface, #1a1814)', 'cateringInk' => 'var(--tc-text, #f5f4f0)'])

        {{-- Reserve / Join-the-waitlist CTA — kept at the end of the menu content so
             the shared info footer (mbInfoFoot, appended last) stays the last section. --}}
        @if($reservationsEnabled || $waitlistEnabled)
        <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;padding:34px 20px 44px;max-width:640px;margin:0 auto">
            @if($reservationsEnabled)
            <button type="button" id="v-res-open" style="flex:1;min-width:200px;padding:15px 20px;background:transparent;border:1px solid var(--v-border-gold-focus);color:var(--v-accent);border-radius:2px;font-family:var(--v-font-headings);font-size:15px;letter-spacing:.04em;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px">
                <span style="font-size:12px">◆</span> {{ ___('Reserve a Table') }}
            </button>
            @endif
            @if($waitlistEnabled)
            <button type="button" id="v-waitlist-open" style="flex:1;min-width:200px;padding:15px 20px;background:transparent;border:1px solid var(--v-border-gold-focus);color:var(--v-accent-text, var(--v-accent));border-radius:2px;font-family:var(--v-font-headings);font-size:15px;letter-spacing:.04em;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px">
                <span style="font-size:12px">◆</span> {{ ___('Join the Waitlist') }}
            </button>
            @endif
        </div>
        @endif

    </main>

    {{-- Product Detail Drawer (Bottom Sheet) --}}
    <div id="v-detail-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-detail-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <div class="v-drawer-drag-handle"></div>
                <button class="v-drawer-close" id="v-detail-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content" id="v-detail-content">
                {{-- Dynamic content injected via JS --}}
            </div>
        </div>
    </div>

    {{-- Floating Cart Toggle Button --}}
    @if($allow_order)
        <button id="v-cart-toggle" class="v-cart-floating-btn" style="display: none;" aria-label="View Cart">
            <span class="v-cart-icon-wrap">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 01-8 0"></path>
                </svg>
                <span class="v-cart-badge">0</span>
            </span>
            <span class="v-cart-btn-price"></span>
        </button>
    @endif

    {{-- Cart & Checkout Drawer --}}
    <div id="v-cart-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-cart-backdrop"></div>
        <div class="v-drawer-sheet v-cart-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Your Selection') }}</h2>
                <button class="v-drawer-close" id="v-cart-close" aria-label="Close">&times;</button>
            </div>
            
            <div class="v-drawer-content">
                {{-- Cart Items --}}
                <div id="v-cart-items-list" class="v-cart-list"></div>

                {{-- R5 · guest recent orders (localStorage) — track links for orders placed
                     on this device; JS reveals the block only when there are any. Signed-in
                     diners use the account drawer's Order History link instead. --}}
                @if(!$customer)
                <div class="v-recent-orders" id="vRecentOrdersBlock" hidden>
                    <h3 class="v-recent-orders-title">{{ ___('Your recent orders') }}</h3>
                    <div class="v-recent-orders-list" id="vRecentOrders"></div>
                </div>
                @endif

                {{-- Coupon / Discount --}}
                @if($allowCoupons)
                <div class="v-coupon-section">
                    <div class="v-coupon-input-wrap">
                        <input aria-label="{{ ___('Coupon Code') }}" type="text" id="v-coupon-code" placeholder="{{ ___('Coupon Code') }}" autocomplete="off">
                        <button type="button" id="v-coupon-apply-btn">{{ ___('Apply') }}</button>
                    </div>
                    <div id="v-coupon-msg" class="v-coupon-message"></div>
                </div>
                @endif

                {{-- Store credit (G3) --}}
                @if($customer && $storeCredit > 0)
                <div class="v-coupon-section">
                    <label style="display:flex;align-items:center;gap:8px;font-size:.9em;">
                        <input type="checkbox" name="use_wallet" value="1" id="mbUseWallet">
                        <span>{{ ___('Pay with store credit') }} ({{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }})</span>
                    </label>
                </div>
                @endif

                {{-- Tips --}}
                @if($allowTips)
                <div class="v-tip-section">
                    {{-- `Add a tip` has no entry in any non-English locale (___() had
                         auto-seeded it with the English string), so the Arabic cart
                         read "Add a tip (اختياري)". `Tip` already carries a real
                         translation everywhere and labels the matching summary row. --}}
                    <span class="v-tip-label">{{ ___('Tip') }} ({{ ___('optional') }})</span>
                    <div class="v-tip-row" id="v-tip-btns">
                        {{-- `___('None')` resolves to the literal string "— None —" (em
                             dashes are baked into the shared lang value), which read as a
                             broken chip sitting next to 10% / 15% / 20%. `0%` is exact,
                             needs no translation, and completes the numeric row. --}}
                        <button type="button" data-tip="0" class="active">0%</button>
                        <button type="button" data-tip="10">10%</button>
                        <button type="button" data-tip="15">15%</button>
                        <button type="button" data-tip="20">20%</button>
                        <button type="button" data-tip="custom">{{ ___('Custom') }}</button>
                    </div>
                    <input aria-label="{{ ___('Custom tip amount') }}" type="number" id="v-custom-tip" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}" placeholder="{{ ___('Custom tip amount') }}" hidden>
                </div>
                @endif

                @if(!empty($postOptions->gift_cards_enabled))
                <div class="v-coupon-section">
                    <div class="v-coupon-input-wrap">
                        <input aria-label="{{ ___('Gift card code') }}" type="text" id="v-gift-card-code" name="gift_card_code" placeholder="{{ ___('Gift card code') }}" autocomplete="off">
                    </div>
                </div>
                @endif

                {{-- Summary --}}
                <div class="v-cart-summary">
                    <div class="v-summary-row">
                        <span>{{ ___('Subtotal') }}</span>
                        <span id="v-sum-subtotal">0.00</span>
                    </div>
                    <div class="v-summary-row" id="v-delivery-row" style="display:none;">
                        <span>{{ ___('Delivery Charge') }}</span>
                        <span id="v-sum-delivery">0.00</span>
                    </div>
                    {{-- Tax sits BETWEEN delivery and discount because it is charged on
                         the RAW subtotal — the discount is subtracted last and is never
                         taxed (TOTALS-CONTRACT trap #1). --}}
                    <div class="v-summary-row" id="v-tax-row" style="display:none;">
                        <span>{{ ___('Tax') }}</span>
                        <span id="v-sum-tax">0.00</span>
                    </div>
                    <div class="v-summary-row v-sum-discount-row" id="v-discount-row" style="display:none;">
                        <span>{{ ___('Discount') }}</span>
                        <span id="v-sum-discount">0.00</span>
                    </div>
                    <div class="v-summary-row" id="v-tip-summary-row" style="display:none;">
                        <span>{{ ___('Tip') }}</span>
                        <span id="v-sum-tip">0.00</span>
                    </div>
                    <div class="v-summary-row v-total-row">
                        <span>{{ ___('Total') }}</span>
                        <span id="v-sum-total">0.00</span>
                    </div>
                </div>

                {{-- F1 · turn this cart into a recurring meal plan --}}
                @if($mealPlansEnabled)
                <button class="v-mealplan-cta" id="cvMealPlanCta" type="button">
                    <span class="v-mp-ic"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M8 14h3M8 17h6"/></svg></span>
                    <span class="v-mp-copy"><b>{{ ___('Make this a meal plan') }}</b><small>{{ ___('Get these dishes delivered on a schedule') }}</small></span>
                    <svg class="v-mp-arr" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                @endif

                {{-- Checkout Form --}}
                <form id="v-checkout-form" class="v-checkout-form">
                    @csrf
                    <label class="wa-optin" style="display:flex;align-items:center;gap:8px;margin:10px 0;font-size:.9em;">
                        <input type="checkbox" name="whatsapp_optin" value="1">
                        <span>{{ ___('Send me offers and updates on WhatsApp') }}</span>
                    </label>
                    <div class="v-form-group">
                        <label for="v-cust-name">{{ ___('Your Name') }} *</label>
                        <input type="text" id="v-cust-name" name="name" required placeholder="{{ ___('e.g. John Doe') }}">
                    </div>

                    <div class="v-form-group">
                        <label>{{ ___('Order Type') }} *</label>
                        <div class="v-order-type-tabs">
                            @if($allow_on_table)
                                <button type="button" class="v-tab-btn" data-type="on-table">{{ ___('Dine-In') }}</button>
                            @endif
                            @if($allow_takeaway)
                                <button type="button" class="v-tab-btn" data-type="takeaway" data-tc-text="texts.pickup">{{ theme_setting($store->id, 'texts.pickup', ___('Takeaway')) }}</button>
                            @endif
                            @if($allow_delivery)
                                <button type="button" class="v-tab-btn" data-type="delivery" data-tc-text="texts.delivery">{{ theme_setting($store->id, 'texts.delivery', ___('Delivery')) }}</button>
                            @endif
                            {{-- T1/H2 hotel room service. $allow_room_service is 1 only when hotel
                                 mode is on AND the scanned QR resolved to an area_type='room' table,
                                 so this tab never renders for an ordinary restaurant. --}}
                            @if($allow_room_service)
                                <button type="button" class="v-tab-btn" data-type="room_service">{{ ___('Room service') }}</button>
                            @endif
                        </div>
                        <input type="hidden" id="v-order-type-input" name="ordering-type" value="">
                    </div>

                    <div class="v-form-group" id="v-group-table" style="display:none;">
                        <label for="v-table-num">{{ ___('Table Number') }} *</label>
                        {{-- $table is a StoreTable model, so echoing it directly stamps the
                             row's whole JSON into the box (and POSTs it as `table`). Print the
                             column, and lock the field when the QR already told us the table. --}}
                        <input type="text" id="v-table-num" name="table" value="{{ $table->table_no ?? '' }}" {{ $table ? 'readonly' : '' }} placeholder="{{ ___('e.g. 5') }}">
                    </div>

                    {{-- Call-waiter (dine-in only; shown by JS when order type is on-table) --}}
                    @if($allowWaiter && $allow_on_table)
                    <div class="v-waiter-row" id="v-waiter-row" style="display:none;">
                        <button type="button" id="v-call-waiter" class="v-waiter-btn">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2" fill="currentColor" stroke="none"/></svg>
                            <span>{{ ___('Call Waiter') }}</span>
                        </button>
                    </div>
                    @endif

                    <div class="v-form-group" id="v-group-phone" style="display:none;">
                        <label for="v-phone-num">{{ ___('Phone Number') }} *</label>
                        <input type="tel" id="v-phone-num" name="phone-number" placeholder="{{ ___('e.g. +123456789') }}">
                    </div>

                    <div class="v-form-group" id="v-group-address" style="display:none;">
                        <label for="v-address">{{ ___('Delivery Address') }} *</label>
                        {{-- Saved addresses (logged-in): tap to fill the field + coords. --}}
                        @if($customerAddresses->count())
                        <div class="v-saved-addrs" id="cvSavedAddrs">
                            @foreach($customerAddresses as $sa)
                            <button class="v-saved-addr{{ $loop->first ? ' is-on' : '' }}" type="button"
                                    data-addr="{{ $addrLabel($sa) }}" data-lat="{{ $sa->lat }}" data-lng="{{ $sa->lng }}">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                                <span class="v-sa-text"><b>{{ $sa->label ?: ___('Saved address') }}</b><small>{{ Str::limit($addrLabel($sa), 42) }}</small></span>
                            </button>
                            @endforeach
                        </div>
                        @elseif(!$customer)
                        {{-- R6 · guests get the same chips from localStorage (v_recent_addrs_<slug>),
                             rendered by JS into this hook; the F5 click handler fills the field + coords. --}}
                        <div class="v-saved-addrs" id="cvSavedAddrs" hidden></div>
                        @endif
                        {{-- F5 · address autocomplete + use-my-location --}}
                        <div class="v-addr-field" id="cvAddrField">
                            <textarea id="v-address" name="address" rows="2" placeholder="{{ ___('Your delivery address') }}" autocomplete="off" autocorrect="off" spellcheck="false">{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}</textarea>
                            <input type="hidden" id="cvCustLat" name="latitude" value="{{ $customerDefaultAddress->lat ?? '' }}">
                            <input type="hidden" id="cvCustLng" name="longitude" value="{{ $customerDefaultAddress->lng ?? '' }}">
                            <button class="v-useloc-btn" id="cvUseLocation" type="button" title="{{ ___('Use my location') }}">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                            </button>
                            <div class="v-addr-suggest" id="cvAddrSuggest" hidden></div>
                        </div>
                        <p class="v-geo-attrib" id="cvGeoAttrib" hidden></p>
                        @if($showBranches)
                        <button class="v-nearest-branch-btn" id="cvNearestBranchBtn" type="button">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            {{ ___('Find nearest branch on map') }}
                        </button>
                        @endif
                    </div>

                    @if($allowOrderSched)
                    <div class="v-form-group">
                        <label for="v-scheduled-at">{{ ___('Schedule for later') }} ({{ ___('optional') }})</label>
                        <input type="datetime-local" id="v-scheduled-at" name="scheduled_at"
                               min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}"
                               max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
                    </div>
                    @endif

                    {{-- T5: Curbside pickup (takeaway) --}}
                    @if(!empty($postOptions->curbside_enabled))
                    <div class="v-form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="curbside" value="1" id="v-curbside" data-csp-toggle="v-curbside-fields">
                            <span>{{ ___("Curbside pickup — I'll wait in my car") }}</span>
                        </label>
                        <div id="v-curbside-fields" style="display:none;margin-top:8px;">
                            <input aria-label="{{ ___('Car make/model') }}" type="text" name="vehicle_make" id="v-vehicle-make" placeholder="{{ ___('Car make/model') }}" style="margin-bottom:8px;">
                            <input aria-label="{{ ___('Colour') }}" type="text" name="vehicle_color" id="v-vehicle-color" placeholder="{{ ___('Colour') }}" style="margin-bottom:8px;">
                            <input aria-label="{{ ___('Plate') }}" type="text" name="vehicle_plate" id="v-vehicle-plate" placeholder="{{ ___('Plate') }}">
                        </div>
                    </div>
                    @endif

                    <div class="v-form-group">
                        <label for="v-message">{{ ___('Notes / Special Instructions') }}</label>
                        <textarea id="v-message" name="message" rows="2" placeholder="{{ ___('e.g. no onions, extra spicy') }}"></textarea>
                    </div>

                    <div class="v-form-group">
                        <label>{{ ___('Payment Method') }} *</label>
                        <div class="v-payment-options">
                            <label class="v-payment-option">
                                <input type="radio" name="pay_via" value="pay_on_counter" checked>
                                <span class="v-payment-box">
                                    <span class="v-payment-title">{{ ___('Pay at Counter / Cash') }}</span>
                                </span>
                            </label>
                            @if($allow_payment)
                                <label class="v-payment-option">
                                    <input type="radio" name="pay_via" value="pay_online">
                                    <span class="v-payment-box">
                                        <span class="v-payment-title">{{ ___('Pay Online') }}</span>
                                    </span>
                                </label>
                            @endif
                        </div>
                    </div>

                    <div class="v-checkout-errors" id="v-checkout-errors" style="display:none;"></div>

                    <button type="submit" id="v-submit-order-btn" class="v-btn-primary v-btn-submit" data-tc-text="texts.checkout_button">
                        {{ theme_setting($store->id, 'texts.checkout_button', ___('Send Order')) }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- RESERVATIONS / WAITLIST (parity) — the trigger strip moved up to the end of
         v-menu-main (above the info footer); the drawers it opens stay below. --}}

    @if($reservationsEnabled)
    <div id="v-res-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-res-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Reserve a Table') }}</h2>
                <button class="v-drawer-close" id="v-res-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content">
                @if($myReservation && !session('reservation_success'))
                    <div style="background:rgba(212,175,55,.10);border:1px solid var(--v-border-gold-focus);border-radius:2px;padding:12px 14px;margin-bottom:16px;text-align:center">
                        <b style="color:var(--v-accent)">{{ $myReservation->status === 'confirmed' ? ___('Confirmed') : ___('Requested') }}</b>
                        <div style="color:var(--v-text-muted);font-size:12.5px;margin-top:2px">{{ ___('Booked for :when', ['when' => \Illuminate\Support\Carbon::parse($myReservation->reserved_at)->format('D, M j · g:i A')]) }}</div>
                    </div>
                @endif
                @if(session('reservation_success'))
                    <div style="text-align:center;padding:24px 0"><div style="font-size:42px">📅</div><h3 style="margin:10px 0 6px;font-family:var(--v-font-headings)">{{ ___('Request received!') }}</h3><p style="color:var(--v-text-muted)">{{ session('reservation_success') }}</p></div>
                @else
                    <form action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                        @csrf
                        <div class="v-form-group"><label>{{ ___('Your Name') }} *</label><input aria-label="{{ ___('Your Name') }}" type="text" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}"></div>
                        <div class="v-form-group"><label>{{ ___('Phone') }}</label><input aria-label="{{ ___('Phone') }}" type="tel" name="phone" maxlength="30" value="{{ $customer->phone ?? '' }}"></div>
                        <div class="v-form-group"><label>{{ ___('Email') }}</label><input aria-label="{{ ___('Email') }}" type="email" name="email" maxlength="120" value="{{ $customer->email ?? '' }}"></div>
                        <div class="v-form-group"><label>{{ ___('Date & Time') }} *</label><input aria-label="{{ ___('Date & Time') }}" type="datetime-local" name="reserved_at" required min="{{ now()->format('Y-m-d\TH:i') }}"></div>
                        <div class="v-form-group"><label>{{ ___('Party Size') }} *</label><input aria-label="{{ ___('Party Size') }}" type="number" name="party_size" min="1" max="50" value="2" required></div>
                        <div class="v-form-group"><label>{{ ___('Note') }} ({{ ___('optional') }})</label><textarea aria-label="{{ ___('Note') }}" name="notes" maxlength="500"></textarea></div>
                        @include('store_templates._partials.voice-note-recorder')
                        @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                        <button type="submit" class="v-btn-primary" style="width:100%;margin-top:8px">{{ ___('Request Reservation') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if($waitlistEnabled)
    <div id="v-waitlist-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-waitlist-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Join the Waitlist') }}</h2>
                <button class="v-drawer-close" id="v-waitlist-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content">
                @if($myWaitlist && !session('waitlist_joined'))
                    <div style="background:rgba(212,175,55,.10);border:1px solid var(--v-border-gold-focus);border-radius:2px;padding:12px 14px;margin-bottom:16px;text-align:center">
                        <b style="color:var(--v-accent)">{{ ___('On the list') }}</b>
                        <div style="color:var(--v-text-muted);font-size:12.5px;margin-top:2px">{{ ___('Party of :n · joined :time', ['n' => $myWaitlist->party_size, 'time' => \Illuminate\Support\Carbon::parse($myWaitlist->created_at)->diffForHumans()]) }}</div>
                    </div>
                @endif
                @if(session('waitlist_joined'))
                    <div style="text-align:center;padding:24px 0"><div style="font-size:42px">🎉</div><h3 style="margin:10px 0 6px;font-family:var(--v-font-headings)">{{ ___("You're on the list!") }}</h3><p style="color:var(--v-text-muted)">{{ ___('Position :pos · ~:min min wait', ['pos' => session('waitlist_joined')['position'], 'min' => session('waitlist_joined')['wait_minutes']]) }}</p></div>
                @else
                    <form action="{{ route('publicView.waitlist.join', $store->slug) }}" method="POST">
                        @csrf
                        <div class="v-form-group"><label>{{ ___('Your Name') }} *</label><input aria-label="{{ ___('Your Name') }}" type="text" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}"></div>
                        <div class="v-form-group"><label>{{ ___('Phone') }} ({{ ___('optional') }})</label><input aria-label="{{ ___('Phone') }}" type="tel" name="phone" maxlength="30" value="{{ $customer->phone ?? '' }}"></div>
                        <div class="v-form-group"><label>{{ ___('Party Size') }} *</label><input aria-label="{{ ___('Party Size') }}" type="number" name="party_size" min="1" max="50" value="2" required></div>
                        <button type="submit" class="v-btn-primary" style="width:100%;margin-top:8px">{{ ___('Join Waitlist') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- ===================================== B6 · Following management drawer --}}
    @if($authMode !== 'guest_only' || $customer)
    <div id="cvFollowingSheet" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="cvFollowingBackdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <div class="v-drawer-drag-handle"></div>
                <h2 class="v-drawer-title">{{ ___('Following') }}</h2>
                <button class="v-drawer-close" id="cvFollowingClose" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="v-drawer-content">
                <label class="v-follow-sheet-row">
                    <span>{{ ___('Notifications') }}</span>
                    <input type="checkbox" id="cvFollowNotify" role="switch">
                </label>
                <button class="v-followsheet-unfollow" id="cvFollowUnfollow" type="button">{{ ___('Unfollow') }}</button>
                <button class="v-followsheet-cancel" id="cvFollowCancel" type="button">{{ ___('Cancel') }}</button>
            </div>
        </div>
    </div>
    @endif

    {{-- ===================================== B8 · About drawer --}}
    <div id="cvAboutSheet" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="cvAboutBackdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <div class="v-drawer-drag-handle"></div>
                <h2 class="v-drawer-title">{{ $store->title }}</h2>
                <button class="v-drawer-close" id="cvAboutClose" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="v-drawer-content">
                @if($aboutRating !== null)
                    <div class="v-about-rating"><span class="v-about-star">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
                @endif
                <div class="v-about-list">
                    @if(!empty($store->timing))
                        <div class="v-about-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>{{ $store->timing }}</span></div>
                    @endif
                    @if(!empty($store->address))
                        <a class="v-about-row" href="https://maps.google.com/?q={{ urlencode($store->address) }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>{{ $store->address }}</span></a>
                    @endif
                    @if(!empty($store->phone))
                        <a class="v-about-row" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $store->phone) }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 5c0 8 7 15 15 15l1-4-5-2-2 2a13 13 0 0 1-5-5l2-2-2-5Z"/></svg><span dir="ltr">{{ $store->phone }}</span></a>
                    @endif
                    @if($aboutInstagram)
                        <a class="v-about-row" href="{{ $aboutInstagram }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17" cy="7" r="1"/></svg><span>{{ ___('Instagram') }}</span></a>
                    @endif
                    @if($aboutFacebook)
                        <a class="v-about-row" href="{{ $aboutFacebook }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v6h4v-6h3l1-4h-4V8Z"/></svg><span>{{ ___('Facebook') }}</span></a>
                    @endif
                </div>
                @if($aboutReviews->isNotEmpty())
                    <div class="v-about-reviews">
                        @foreach($aboutReviews as $rev)
                            <div class="v-about-review">
                                <div class="v-about-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="v-about-star">★ {{ $rev->stars }}</span></div>
                                @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ===================================== AUTH (login / register) — parity --}}
    @if($authMode !== 'guest_only' && !$customer)
    <div id="v-auth-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-auth-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Login') }} / {{ ___('Register') }}</h2>
                <button class="v-drawer-close" id="v-auth-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content">
                <div class="v-auth-tabs" id="v-auth-tabs">
                    <button type="button" data-auth-tab="login" class="is-on">{{ ___('Login') }}</button>
                    <button type="button" data-auth-tab="otp">{{ ___('Phone OTP') }}</button>
                    <button type="button" data-auth-tab="register">{{ ___('Register') }}</button>
                </div>
                <p class="v-auth-err" id="v-auth-err" style="display:none"></p>

                <div class="v-auth-form" data-auth="login">
                    <div class="v-form-group"><label>{{ ___('Email') }}</label><input aria-label="{{ ___('Email') }}" type="email" id="v-login-email" autocomplete="email"></div>
                    <div class="v-form-group"><label>{{ ___('Password') }}</label><input aria-label="{{ ___('Password') }}" type="password" id="v-login-pass" autocomplete="current-password"></div>
                    <button type="button" id="v-login-btn" class="v-btn-primary" style="width:100%;margin-top:6px">{{ ___('Login') }}</button>
                    @include('store_templates.partials.social-login-buttons', ['store' => $store])
                    @include('store_templates.partials.passkey-login', ['store' => $store])
                </div>

                <div class="v-auth-form" data-auth="register" style="display:none">
                    <div class="v-form-group"><label>{{ ___('Your Name') }}</label><input aria-label="{{ ___('Your Name') }}" type="text" id="v-reg-name" autocomplete="name"></div>
                    <div class="v-form-group"><label>{{ ___('Email') }}</label><input aria-label="{{ ___('Email') }}" type="email" id="v-reg-email" autocomplete="email"></div>
                    <div class="v-form-group"><label>{{ ___('Phone') }}</label><input aria-label="{{ ___('Phone') }}" type="tel" id="v-reg-phone" autocomplete="tel"></div>
                    <div class="v-form-group"><label>{{ ___('Password') }}</label><input aria-label="{{ ___('Password') }}" type="password" id="v-reg-pass" autocomplete="new-password"></div>
                    <div class="v-form-group"><label>{{ ___('Confirm Password') }}</label><input aria-label="{{ ___('Confirm Password') }}" type="password" id="v-reg-pass2" autocomplete="new-password"></div>
                    <button type="button" id="v-register-btn" class="v-btn-primary" style="width:100%;margin-top:6px">{{ ___('Register') }}</button>
                    @include('store_templates.partials.social-login-buttons', ['store' => $store])
                </div>

                {{-- A3 · phone-OTP login (posts the collected code under `code`; distinct
                     ids from the checkout OTP gate #cvOtpGateBoxes) --}}
                <div class="v-auth-form" data-auth="otp" style="display:none">
                    <div id="v-otp-step1">
                        <div class="v-form-group"><label>{{ ___('Phone number') }}</label><input aria-label="{{ ___('Phone number') }}" type="tel" id="v-otp-phone" autocomplete="tel"></div>
                        <button type="button" id="v-otp-send" class="v-btn-primary" style="width:100%;margin-top:6px">{{ ___('Send code') }}</button>
                    </div>
                    <div id="v-otp-step2" style="display:none">
                        <div class="v-form-group"><label>{{ ___('Enter the code we sent you') }}</label></div>
                        <div class="v-otp-boxes" id="v-otp-boxes">
                            <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                        </div>
                        <button type="button" id="v-otp-verify" class="v-btn-primary" style="width:100%;margin-top:6px">{{ ___('Verify') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    @if(!empty($menu_languages) && count($menu_languages) > 1)
    <div id="v-lang-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-lang-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Language') }}</h2>
                <button class="v-drawer-close" id="v-lang-close" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="v-drawer-content">
                <div class="v-lang-list">
                    @foreach($menu_languages as $language)
                        <button type="button" class="v-lang-item {{ get_lang() === $language->code ? 'is-on' : '' }}"
                                data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}">
                            <span class="v-lang-code">{{ strtoupper($language->code) }}</span>
                            <b>{{ $language->name }}</b>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ===================================== CURRENCY (display switcher #curSeg) — MC --}}
    @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
    <div id="v-cur-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-cur-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Currency') }}</h2>
                <button class="v-drawer-close" id="v-cur-close" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="v-drawer-content">
                <p class="v-cur-note">{{ ___('Prices shown in other currencies are estimates — you pay in :cur.', ['cur' => config('settings.currency_code', 'USD')]) }}</p>
                {{-- id="curSeg" + <button data-cur data-rate> is the shared contract the
                     mb-currency.js helper (save / restore) keys off. Base rate = 1. --}}
                <div class="v-cur-seg" id="curSeg" role="radiogroup" aria-label="{{ ___('Currency') }}">
                    <button data-cur="{{ config('settings.currency_code', 'USD') }}" data-rate="1" class="is-on" type="button">{{ config('settings.currency_code', 'USD') }}</button>
                    @foreach($mcRates as $code => $rate)<button data-cur="{{ $code }}" data-rate="{{ $rate }}" type="button">{{ $code }}</button>@endforeach
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ===================================== ACCOUNT (loyalty + logout) — parity --}}
    @if($authMode !== 'guest_only' && $customer)
    <div id="v-account-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-account-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('My Account') }}</h2>
                <button class="v-drawer-close" id="v-account-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content">
                <div class="v-account-hello">
                    <span class="v-account-avatar v-account-avatar-lg">{{ Str::upper(Str::substr($customer->name ?? 'G', 0, 1)) }}</span>
                    <div>
                        <b style="font-family:var(--v-font-headings);font-size:18px">{{ $customer->name ?? ___('Guest') }}</b>
                        <div style="color:var(--v-text-muted);font-size:13px">{{ $customer->phone ?? $customer->email }}</div>
                    </div>
                </div>

                <a class="v-account-link" href="{{ route('customer.profile', $store->id) }}">
                    <span>{{ ___('Profile & settings') }}</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m9 6 6 6-6 6"/></svg>
                </a>

                <a class="v-account-link" href="{{ route('customer.account', $store->id) }}">
                    <span>{{ ___('Order History') }}</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m9 6 6 6-6 6"/></svg>
                </a>

                @if($loyaltyProgram)
                <section class="v-loyalty" id="cvLoyaltyBlock">
                    <div class="v-loyalty-head">
                        <span style="font-size:14px">✦</span>
                        <b>{{ ___('Your Loyalty Balance') }}</b>
                    </div>
                    <div class="v-loyalty-num"><span id="cvLoyaltyBalance">–</span></div>
                    <p class="v-loyalty-sub">
                        {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }}
                        / {{ $loyaltyProgram->reward_threshold }} · {{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }}
                    </p>
                    @if($loyaltyProgram->type === 'stamps')
                    <div class="v-stamp-dots" id="cvStampDots">
                        @for($s = 0; $s < $loyaltyProgram->reward_threshold; $s++)
                            <span class="v-stamp-dot" data-idx="{{ $s }}">✦</span>
                        @endfor
                    </div>
                    @else
                    <div class="v-loyalty-bar"><i id="cvLoyaltyBar"></i></div>
                    @endif
                    <button class="v-btn-primary" id="cvLoyaltyRedeem" type="button" style="width:100%;margin-top:12px;display:none">{{ ___('Redeem Reward') }}</button>
                    <p class="v-loyalty-ok" id="cvLoyaltyOk" style="display:none">{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
                </section>
                @endif

                <button type="button" id="v-logout-btn" class="v-account-logout">{{ ___('Logout') }}</button>
            </div>
        </div>
    </div>
    @endif

    {{-- ===================================== ORDER SUCCESS (+ google review F1) — parity --}}
    <div id="v-success-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-success-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <div class="v-drawer-drag-handle"></div>
                <button class="v-drawer-close" id="v-success-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content" style="text-align:center;padding-bottom:30px">
                <div class="v-success-check">✓</div>
                <h2 style="font-family:var(--v-font-headings);font-size:24px;margin:14px 0 6px">{{ ___('Order Placed') }}</h2>
                {{-- The order number the customer quotes / sees on the track page.
                     submitOrder returns res.order_id; filled in + revealed by JS. --}}
                <p class="v-success-orderno" id="v-success-orderno" hidden></p>
                <p id="v-success-msg" style="color:var(--v-text-muted);margin-bottom:22px">{{ ___('Thank you! Your order has been received.') }}</p>
                @if($googleReviewUrl)
                <a class="v-greview" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener">
                    <span style="font-size:20px">⭐</span>
                    <span class="v-greview-copy">
                        <b>{{ ___('Enjoying your meal?') }}</b>
                        <small>{{ ___('Rate us on Google') }}</small>
                    </span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m9 6 6 6-6 6"/></svg>
                </a>
                @endif
                <button type="button" id="v-success-done" class="v-btn-primary" style="width:100%;margin-top:18px">{{ ___('Done') }}</button>
            </div>
        </div>
    </div>

    {{-- F3 · full-screen story viewer overlay --}}
    <div class="v-story-viewer" id="cvStoryViewer" hidden>
        <div class="v-sv-progress" id="cvSvProgress"></div>
        <button class="v-sv-close" id="cvSvClose" type="button" aria-label="{{ ___('Close') }}">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="v-sv-stage" id="cvSvStage">
            <img class="v-sv-media" id="cvSvMedia" alt="">
            <button class="v-sv-nav prev" id="cvSvPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="v-sv-nav next" id="cvSvNext" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="v-sv-foot">
                <p class="v-sv-caption" id="cvSvCaption"></p>
                <a class="v-btn-primary v-sv-cta" id="cvSvCta" hidden><span></span></a>
            </div>
        </div>
    </div>

    {{-- F4 · nearest-branch map picker --}}
    @if($showBranches)
    <div id="v-branchmap-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-branchmap-backdrop"></div>
        <div class="v-drawer-sheet v-cart-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Nearest branch') }}</h2>
                <button class="v-drawer-close" id="v-branchmap-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content">
                <div class="v-branchmap" id="cvBranchMap"></div>
                <p class="v-geo-attrib" id="cvMapAttrib"></p>
                <div class="v-branch-geo-list" id="cvBranchGeoList"></div>
            </div>
        </div>
    </div>
    @endif

    {{-- F1 · start-a-meal-plan drawer --}}
    @if($mealPlansEnabled)
    <div id="v-mealplan-drawer" class="v-drawer" aria-hidden="true" data-cadences='@json($mealPlanCadences)'>
        <div class="v-drawer-backdrop" id="v-mealplan-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Create a meal plan') }}</h2>
                <button class="v-drawer-close" id="v-mealplan-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content">
                @if($customer)
                <div id="cvMealPlanForm">
                    <p class="v-mp-summary" id="cvMpItemsSummary"></p>
                    <div class="v-form-group"><label>{{ ___('Plan name') }} ({{ ___('optional') }})</label><input aria-label="{{ ___('e.g. Weekly lunch') }}" type="text" id="cvMpName" placeholder="{{ ___('e.g. Weekly lunch') }}"></div>
                    <div class="v-form-group"><label>{{ ___('How often?') }}</label><div class="v-mp-cadence" id="cvMpCadence"></div></div>
                    <div class="v-form-group" id="cvMpDaysWrap" hidden>
                        <label>{{ ___('Which days?') }}</label>
                        <div class="v-mp-days" id="cvMpDays">
                            @foreach(['Sun'=>0,'Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6] as $d => $i)
                                <button data-day="{{ $i }}" type="button">{{ ___($d) }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="v-form-group"><label>{{ ___('Start date') }}</label><input aria-label="{{ ___('Start date') }}" type="date" id="cvMpStart" min="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}"></div>
                    <p class="v-checkout-errors" id="cvMpErr" style="display:none"></p>
                    <button class="v-btn-primary" id="cvMpSubmit" type="button" style="width:100%;margin-top:10px">{{ ___('Create plan') }}</button>
                </div>
                <div class="v-mp-done" id="cvMpDone" hidden>
                    <div style="font-size:42px">📅</div>
                    <h3 style="font-family:var(--v-font-headings);margin:8px 0 4px">{{ ___('Meal plan created!') }}</h3>
                    <p id="cvMpDoneSub" style="color:var(--v-text-muted)"></p>
                    <a class="v-ghostbtn" href="{{ route('customer.account', $store->id) }}">{{ ___('Manage plans') }}</a>
                </div>
                @else
                <p style="color:var(--v-text-muted);text-align:center;padding:6px 0 14px">{{ ___('Log in to create a recurring meal plan.') }}</p>
                <button class="v-btn-primary" id="cvMpLoginBtn" type="button" style="width:100%">{{ ___('Login') }}</button>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- F6 · write-review drawer --}}
    <div id="v-review-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-review-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Write a review') }}</h2>
                <button class="v-drawer-close" id="v-review-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content">
                <p class="v-mp-summary" id="cvReviewItemName"></p>
                <div class="v-star-row v-rv-stars" id="cvReviewStars">
                    @for($n = 1; $n <= 5; $n++)
                        <button data-star="{{ $n }}" type="button" class="v-star-btn" aria-label="{{ $n }}">★</button>
                    @endfor
                </div>
                @if(!$customer)
                    <div class="v-form-group"><label>{{ ___('Your Name') }}</label><input aria-label="{{ ___('Your Name') }}" type="text" id="cvReviewName" autocomplete="name"></div>
                @endif
                <div class="v-form-group"><label>{{ ___('Your review') }}</label><textarea aria-label="{{ ___('Your review') }}" id="cvReviewText" rows="3" placeholder="{{ ___('Tell others about this dish…') }}"></textarea></div>
                <p class="v-checkout-errors" id="cvReviewErr" style="display:none"></p>
                <button class="v-btn-primary" id="cvReviewSubmit" type="button" style="width:100%;margin-top:6px">{{ ___('Submit review') }}</button>
            </div>
        </div>
    </div>

    {{-- F7 · OTP checkout gate drawer (dough-style auto-advance) --}}
    <div id="v-otp-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-otp-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Verify your phone') }}</h2>
                <button class="v-drawer-close" id="v-otp-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content">
                <p style="color:var(--v-text-muted);margin-bottom:14px">{{ ___('Enter the code we sent to') }} <b id="cvOtpGatePhone" dir="ltr"></b></p>
                <div class="v-otp-boxes" id="cvOtpGateBoxes">
                    <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                </div>
                <p class="v-checkout-errors" id="cvOtpGateErr" style="display:none"></p>
                <button class="v-btn-primary" id="cvOtpGateVerify" type="button" style="width:100%">{{ ___('Verify & place order') }}</button>
                <button class="v-linkbtn" id="cvOtpGateResend" type="button">{{ ___('Resend code') }}</button>
            </div>
        </div>
    </div>

    {{-- Dietary / spice / allergen filter drawer (gated on allow_dietary_filters) --}}
    @if($allowDietary)
    <div id="v-filter-drawer" class="v-drawer" aria-hidden="true">
        <div class="v-drawer-backdrop" id="v-filter-backdrop"></div>
        <div class="v-drawer-sheet">
            <div class="v-drawer-header">
                <h2 class="v-drawer-title">{{ ___('Filters') }}</h2>
                <button class="v-drawer-close" id="v-filter-close" aria-label="Close">&times;</button>
            </div>
            <div class="v-drawer-content">
                <h4 class="v-filter-h4">{{ ___('Dietary') }}</h4>
                <div class="v-chiprow" id="v-diet-row">
                    <button type="button" data-diet="vegan">{{ ___('Vegan') }}</button>
                    <button type="button" data-diet="halal">{{ ___('Halal') }}</button>
                    <button type="button" data-diet="gluten_free">{{ ___('Gluten-Free') }}</button>
                </div>
                <h4 class="v-filter-h4">{{ ___('Spice level') }}</h4>
                <div class="v-chiprow" id="v-spice-row">
                    <button type="button" data-spice="1">🌶</button>
                    <button type="button" data-spice="2">🌶🌶</button>
                    <button type="button" data-spice="3">🌶🌶🌶</button>
                </div>
                @if(count($allergies ?? []))
                <h4 class="v-filter-h4">{{ ___('Exclude allergens') }}</h4>
                <div class="v-chiprow" id="v-allergen-row">
                    @foreach($allergies as $a)
                        <button type="button" data-al="{{ $a->id }}">{{ $a->title }}</button>
                    @endforeach
                </div>
                @endif
                <div class="v-filter-actions">
                    <button class="v-ghostbtn" id="v-filter-reset" type="button">{{ ___('Reset') }}</button>
                    <button class="v-btn-primary" id="v-filter-done" type="button">{{ ___('Show dishes') }} <b id="v-filter-count"></b></button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="v-toast" id="cvToast" role="status"></div>
</div>

{{-- Shared notification-center panel (bell lives in the header pill row) --}}
@include('store_templates._partials.notif-bell')

{{-- VELVET 3.1 · bottom dock (soiree-parity). Thin launchers over existing
     controls — scroll home / scroll menu / focus search / open the cart sheet
     via #v-cart-toggle. Wired in script.js; badge mirrors .v-cart-badge. --}}
<nav class="v-dock" id="vDock" aria-label="{{ ___('Main') }}">
    <button class="v-dock-btn is-on" data-vdock="home" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg>
        <span>{{ ___('Home') }}</span>
    </button>
    <button class="v-dock-btn" data-vdock="menu" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1" fill="currentColor" stroke="none"/></svg>
        <span>{{ ___('Menu') }}</span>
    </button>
    @if(theme_setting($store->id, 'search.enabled', true))
    <button class="v-dock-btn" data-vdock="search" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <span>{{ ___('Search') }}</span>
    </button>
    @endif
    @if($allow_order)
    <button class="v-dock-btn" data-vdock="cart" type="button" style="position:relative">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
        <span>{{ ___('Cart') }}</span>
        <i class="v-dock-badge" id="vDockCartCount" hidden>0</i>
    </button>
    @endif
    <button class="v-dock-btn" data-vdock="favs" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.35-9.5-8.5C1 8 2.5 4.5 6 4.5c2 0 3.2 1.2 4 2.3.8-1.1 2-2.3 4-2.3 3.5 0 5 3.5 3.5 7C19 15.65 12 20 12 20Z"/></svg>
        <span>{{ ___('Favorites') }}</span>
    </button>
    @if($authMode !== 'guest_only')
    <button class="v-dock-btn" data-vdock="profile" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
        <span>{{ ___('Profile') }}</span>
    </button>
    @endif
</nav>

{{-- Favorites drawer (velvet had no favourites feature; hearts on cards feed this). --}}
<div id="v-fav-drawer" class="v-drawer" aria-hidden="true">
    <div class="v-drawer-backdrop" id="v-fav-backdrop"></div>
    <div class="v-drawer-sheet">
        <div class="v-drawer-header">
            <h2 class="v-drawer-title">{{ ___('Favorites') }}</h2>
            <button class="v-drawer-close" id="v-fav-close" aria-label="Close">&times;</button>
        </div>
        <div class="v-drawer-content" id="v-fav-content">
            <div id="v-fav-list"></div>
            <div class="v-fav-empty" id="v-fav-empty">
                <p>{{ ___('No favorites yet') }}</p>
                <span>{{ ___('Tap the heart on any dish to save it here.') }}</span>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts_at_bottom')
{{-- F4 · self-hosted Leaflet (loads before script.js; CSP script-src self+nonce safe) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
{{-- P3-13: totals from the server quote (THEME-CONTRACT §5.2). --}}
<script src="{{ asset('assets/store_templates/_shared/js/mb-quote.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-quote.js')) }}"></script>
<script nonce="{{ csp_nonce() }}">
    window.VELVET = {
        theme: @json($theme),
        storeId: @json($store->id),
        storeId: @json($store->id),
        restaurantSlug: @json($store->slug),
        orderUrl: @json(route('store.submitOrder', $store->id)),
        csrf: @json(csrf_token()),
        authMode: @json($authMode),
        isLoggedIn: @json((bool) $customer),
        loyaltyType: @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json($loyaltyProgram->reward_threshold ?? null),
        /* theme-pack-blade features */
        customerToken:    @json($customerApiToken),
        isFollowing:      @json((bool) $isFollowing),
        followerCount:    @json((int) $followerCount),
        followNotify:     @json((bool) $followNotify),
        mealPlansEnabled: @json((bool) $mealPlansEnabled),
        otpGateEnabled:   @json((bool) $otpGateEnabled),
        /* parity-2 wave-4 features */
        allowCoupons:     @json((bool) $allowCoupons),
        allowTips:        @json((bool) $allowTips),
        allowDietary:     @json((bool) $allowDietary),
        allowCallWaiter:  @json((bool) ($allowWaiter && $allow_on_table)),
        cats:             @json($jsCats),
        leafletImgPath:   @json(asset('assets/vendor/leaflet/images')),
        endpoints: {
            coupon:         @json(route('store.applyCoupon', $store->id)),
            waiter:         @json(route('store.callTheWaiter', $store->id)),
            login:          @json(route('customer.login', $store->id)),
            register:       @json(route('customer.register', $store->id)),
            otpRequest:     @json(route('customer.otp.request', $store->id)),
            otpVerifyLogin: @json(route('customer.otp.verify', $store->id)),
            logout:         @json(route('customer.logout', $store->id)),
            loyaltyBalance: @json($loyaltyProgram ? route('publicView.loyalty.balance', $store->id) : null),
            loyaltyRedeem:  @json($loyaltyProgram ? route('publicView.loyalty.redeem', $store->id) : null),
            review:         @json(route('store.submitReview', $store->slug)),
            follow:         @json(url('r/'.$store->slug.'/follow')),
            stories:        @json(route('stories.public', $store->slug)),
            branchesGeo:    @json(route('geo.branches', $store->slug)),
            geoAutocomplete:@json(route('geo.autocomplete')),
            geoReverse:     @json(route('geo.reverse')),
            reviewsBase:    @json((string) store_options($store->id, 'item_reviews_enabled', '1') !== '0' ? url('r/'.$store->id.'/item') : ''),
            mealPlan:       @json(url('customer/meal-plan')),
            otpSend:        @json(route('checkout.otp.send')),
            otpVerify:      @json(route('checkout.otp.verify')),
        },
        openResSheet: @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        currencySign: @json($curSign),
        currencyGap: @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos: @json($curPos),
        currencyDecimals: @json(currency_decimals($postOptions->currency_code ?? null)),
        moneyDecimals: @json(money_decimals($postOptions->currency_code ?? null)), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        {{-- MC · multi-currency display switcher (#curSeg). decimalsByCode drives the
             displayed decimals per code; currencyRates is the base->code table, base = 1. --}}
        decimalsByCode: @json(currency_decimals_map()),
        baseCurrency: @json(config('settings.currency_code', 'USD')),
        currencyRates: @json($mcRates),
        deliveryCharge: @json($deliveryCharge),
        /* MONEY · TOTALS-CONTRACT. velvet shipped with NO tax at all: the rate was
           read into $taxCharge above and then never handed to the client, the cart
           summary had no tax row, and the quoted total was
           subtotal - discount + delivery + tip. On this 5%-tax store a $5.00 basket
           quoted $5.00 and the server charged $5.25. Both the rate AND the mode ride
           across now, because Order::computeTax() treats 'fixed' as a flat amount
           rather than a rate — a theme that always multiplies bills a flat-tax
           restaurant a percentage of the basket. */
        taxCharge: @json($taxCharge),
        taxType: @json((string) (@$postOptions->restaurant_tax_type ?: 'percentage')),
        minOrderValue: @json($minOrderValue),
        branchId: @json(request()->get('branch_id') ?: ''),
        items: @json($jsItems),
        lang: {
            add: @json(theme_setting($store->id, 'texts.add_to_cart', ___('Add to Order'))),
            /* WAVE4-V4 — both keys already carry real translations in every locale
               (seeded by feast), so reusing them adds no English-into-Arabic leak. */
            remove: @json(___('Remove')),
            chooseOption: @json(___('Choose an option')),
            extras: @json(___('Extras')),
            {{-- `Added!` (with the bang) slugs to its own key which no locale has, so
                 the button flashed English on every Arabic store. `Added` and
                 `Added to cart` both carry real translations everywhere. --}}
            added: @json(___('Added')),
            addedToCart: @json(___('Added to cart')),
            soldOut: @json(___('Sold Out')),
            orderLabel: @json(___('Order')),
            cartEmpty: @json(___('Your cart is empty')),
            view_cart: @json(___('View Cart')),
            item: @json(___('item')),
            items: @json(___('items')),
            couponApplied: @json(___('Coupon applied successfully')),
            couponInvalid: @json(___('Invalid or expired coupon code')),
            couponBad: @json(___('Invalid promo code.')),
            waiterOk: @json(___('A waiter will be with you shortly!')),
            showDishes: @json(___('Show dishes')),
            minOrderError: @json(___('Minimum order amount is')),
            fieldRequired: @json(___('Please fill all required fields')),
            sending: @json(___('Sending order...')),
            rateThisItem: @json(___('Rate this dish')),
            yourName: @json(___('Your name (optional)')),
            yourReview: @json(___('Share your experience...')),
            submit: @json(___('Submit')),
            thankYou: @json(___('Thank you!')),
            reviews: @json(___('Reviews')),
            rvWrite: @json(___('Write')),
            rvVerified: @json(___('Verified')),
            rvLatest: @json(___('Latest')),
            rvDetailed: @json(___('Detailed')),
            rvEmpty: @json(___('No reviews yet — be the first!')),
            required: @json(___('Required')),
            choose: @json(___('Choose an option')),
            cadDaily: @json(___('Daily')),
            cadWeekly: @json(___('Weekly')),
            cadCustom: @json(___('Custom days')),
            mpNext: @json(___('Next order')),
            pause: @json(___('Pause')),
            skipNext: @json(___('Skip next')),
            cancel: @json(___('Cancel')),
            resume: @json(___('Resume')),
            active: @json(___('Active')),
            paused: @json(___('Paused')),
            cancelled: @json(___('Cancelled')),
            cartEmpty: @json(___('Your cart is empty')),
            failed: @json(___('Failed')),
            networkError: @json(___('Network error')),
            sent: @json(___('Sent')),
            locationUnavailable: @json(___('Location unavailable')),
            locationDenied: @json(___('Location denied')),
            noAddress: @json(___('No address found')),
            now_following: @json(___('You are now following :store', ['store' => $store->title])),
            action_failed: @json(___('Something went wrong, please try again.')),
            view_stories: @json(___('View stories'))
        }
    };
</script>
{{-- Category-adjacency for the injected OFFERS strip.
     velvet renders its category selector inside a position:sticky search bar
     (.v-nav-bar-sticky) that is a sibling of <main>, not of the menu grid. The
     shared placement helper (_partials/theme-anchor) climbs [data-mb-grid-lead]
     only among the grid's own siblings inside <main>, so it cannot lift the
     injected #mbOffersStrip past a selector that lives in a different container —
     the strip parks at the top of <main>, wedged between the categories and the
     items they filter. The order-type bar is handled by [data-mbt-anchor] above.
     Here we keep #mbOffersStrip directly above the sticky bar (and above the
     order-type bar when it is present). A MutationObserver re-asserts it whenever
     the shared helper's periodic re-runs (0/900/2500ms) move it back; because the
     observer callback runs as a microtask before paint, the wedged position is
     never shown. Velvet-only: no shared file changes. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    function lift() {
        var sticky = document.querySelector('.v-nav-bar-sticky');
        var strip  = document.getElementById('mbOffersStrip');
        if (!sticky || !sticky.parentElement || !strip || strip.contains(sticky)) return;
        /* Sit directly above the order-type bar when it has already been anchored
           just above the sticky bar, otherwise directly above the sticky bar. */
        var ot = document.getElementById('mbOrderType');
        var anchor = (ot && ot.parentElement === sticky.parentElement
                        && ot.nextElementSibling === sticky) ? ot : sticky;
        if (strip.nextElementSibling === anchor) return;   // already in place
        sticky.parentElement.insertBefore(strip, anchor);
    }
    function boot() {
        lift();
        var sticky = document.querySelector('.v-nav-bar-sticky');
        var wrap   = sticky && sticky.parentElement;
        var main   = document.querySelector('.v-menu-main');
        if (wrap && window.MutationObserver) {
            var obs = new MutationObserver(lift);
            obs.observe(wrap, { childList: true });              // where we lift it to
            if (main) obs.observe(main, { childList: true });    // where the helper parks it
        }
        // Belt-and-braces for the helper's late settle ticks.
        setTimeout(lift, 950);
        setTimeout(lift, 2600);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
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
