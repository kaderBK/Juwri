{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       plate — editorial ordering-app post_template · data preparation
       DOM & motion match rev/plate/demo.*; data & ordering are the real app.
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $items      = collect($total_menus)->values();
    /* Two-tier category nav (primary parent chips + a secondary sub-category chip
       row that swaps per active parent — the Talabat/UberEats pattern; ported from
       soiree). menu_categories() is whereNull('parent') (top-level only), but a menu
       item keys off its OWN category_id, which may be a subcategory id. So each JS
       item carries BOTH ids: 'cat' = its real category_id (drives sub-chip filtering)
       and 'root' = its top-level ancestor (drives parent-chip filtering, so a parent
       chip shows every descendant item instead of dropping subcategory items).
       $rootOf maps any category id → its top-level ancestor; $catNameOf keeps every
       category's own name. $categories / $subCatsOf / $jsCats are built AFTER the
       jsItems loop, once the per-category item counts are known. */
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

    /* multi-currency (manual rate table) */
    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

    $curSign  = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos   = (string) currency_pos_effective($postOptions->id ?? null);
    $curGap   = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only
    /* Decimals follow the RESTAURANT's currency, exactly like Order::computeTax()
       and Order::getAmountDueAttribute(). A global currency_decimals() diverges the
       moment the store runs a 0- or 3-decimal currency. */
    $curDecimals     = currency_decimals(@$postOptions->currency_code ?: null);
    $deliveryCharge  = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge       = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    /* `restaurant_tax_type` is authoritative: 'fixed' is a FLAT amount, not a rate.
       Order::computeTax() switches on it, so the quote must too. */
    $taxType         = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');
    /* H8 hotel tray charge — auto-added by Order::trayCharge() to room_service
       orders only. Quoting without it under-charges the customer's own receipt. */
    $trayChargeCfg   = \app(\Juwri\Core\Contracts\Hotel\RoomCharge::class)->trayCharge((int) $store->id);
    $taxLabel        = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
    $minOrderValue   = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched = !empty($postOptions->allow_order_scheduling);
    $allowCoupons    = @$postOptions->allow_coupons == 1;
    $allowTips       = @$postOptions->allow_tips == 1;
    $allowWaiter     = !empty($postOptions->allow_call_waiter);
    $allowDietary    = !isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;
    $isOpen          = store_open_now($store);

    $featured   = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);

    /* daily special → promo banner */
    $dailySpecial = $items->first(fn($m) => (bool) ($m->is_daily_special ?? false))
        ?? $items->first(fn($m) => (bool) $m->is_popular);

    /* human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* JS data map — variants absolute, option-groups + extras additive */
    $jsItems = [];
    /* approved product reviews, grouped by menu — feeds the item view's reviews block */
    $reviewsByMenu = \App\Models\MenuReview::query()
        ->where('store_id', $store->id)
        ->where('status', 'approved')
        ->latest()
        ->get()
        ->groupBy('menu_id');
    foreach ($items as $m) {
        $itemAllergies = empty($m->allergies)
            ? collect()
            : $allergies->whereIn('id', explode(',', $m->allergies));

        $variants = [];
        foreach ($m->variants ?? [] as $v) {
            $variants[] = ['id' => $v->id, 'label' => $variantLabel($m, $v), 'price' => (float) $v->price];
        }
        $extras = [];
        foreach ($m->extras ?? [] as $e) {
            $extras[] = ['id' => $e->id, 'title' => $e->title, 'price' => (float) $e->price];
        }

        // 'root' = the item's top-level ancestor (parent-chip filter); 'cat' stays the
        // item's real category id (sub-chip filter). Orphan → falls back to self.
        $rootCat = $rootOf[$m->category_id] ?? $m->category_id;
        $rootItemCount[$rootCat] = ($rootItemCount[$rootCat] ?? 0) + 1;
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;

        $jsItems[$m->id] = [
            'id'    => $m->id,
            'cat'   => $m->category_id,
            'root'  => $rootCat,
            'sub'   => $catNameOf[$m->category_id] ?? '',
            'name'  => $m->name,
            'desc'  => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'img'   => media_url('storage/menu/', $m->image, $store->id ?? null),
            'price' => (float) $m->price,
            'type'  => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'=> $m->rating_avg && $m->rating_avg > 0 ? (float) $m->rating_avg : 0,
            'reviews' => (int) ($m->rating_count ?? 0),
            // Written-review total for the Reviews SECTION badge — 'reviews' above is
            // rating_count (every quick star tap), which over a list of one written
            // review reads as fabricated social proof.
            'reviewTotal' => collect($reviewsByMenu->get($m->id, collect()))->count(),
            'reviewList' => \Juwri\Core\Support\ViewHelpers::reviewList($reviewsByMenu->get($m->id, collect())),
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyListPlain($itemAllergies),
            'variants' => $variants,
            'extras'   => $extras,
            'is_vegan'         => (bool) $m->is_vegan,
            'is_halal'         => (bool) $m->is_halal,
            'is_gluten_free'   => (bool) $m->is_gluten_free,
            'is_popular'       => (bool) $m->is_popular,
            'is_daily_special' => (bool) ($m->is_daily_special ?? false),
            'spice_level'      => (int) $m->spice_level,
            'is_sold_out'      => (bool) $m->is_sold_out,
            'track_inventory'  => (bool) ($m->track_inventory ?? false),
            'stock_qty'        => $m->stock_qty ?? null,
            'available_from'   => $m->available_from ?? null,
            'available_to'     => $m->available_to ?? null,
            'available_days'   => $m->available_days ?? null,
            'available_now'   => ($m->available_now ?? true),                 // T5: server-resolved (store clock, category window, order type)
            'available_from_label' => ($m->available_from_label ?? null),          // T5: "available from HH:MM"
            'original_price'   => ($m->original_price ?? null),               // T5: pre-happy-hour price, for a strike-through
            'schedule_label'   => ($m->schedule_label ?? null),               // T5: "Happy Hour" badge
            'calories'         => $m->calories ?? null,
            'prep_time'        => $m->prep_time ?? null,
            'portion_size'     => $m->portion_size ?? null,
            'gallery'          => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryUrls($m->gallery_images, $store->id ?? null)
                : [],
            'optionGroups'     => \Juwri\Core\Support\ViewHelpers::optionGroups($m->globalGroups ?? []),
        ];
    }

    // Primary chips: top-level parents that hold at least one descendant item
    // (pure containers / empty parents drop, so no chip opens an empty grid).
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

    /* JS chip data: each parent carries id + name + its item-bearing subs. */
    $jsCats = $categories->map(fn($c) => [
        'id'   => (string) $c->id,
        'name' => $c->name,
        'subs' => collect($subCatsOf[$c->id] ?? [])
            ->map(fn($s) => ['id' => (string) $s->id, 'name' => $s->name])->values(),
    ])->values();

    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();
    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);
    /* Saved addresses → tappable chips at the delivery step (rendered by script.js). */
    $jsSavedAddresses = $customerAddresses->map(fn ($a) => [
        'label' => $a->label ?: ___('Saved address'),
        'addr'  => $addrLabel($a),
        'lat'   => $a->lat,
        'lng'   => $a->lng,
    ])->values();
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;
    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */

    /* F2 · brand / follow row (ported from crave): store mark, name + verified
       badge, follower proof line, and a follow button. */
    $isVerifiedStore  = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
    $followerCount    = method_exists($store, 'follows') ? (int) $store->follows()->count() : 0;
    $isFollowing      = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;

    /* B8 · store-review display (avg rating + up to 2 approved cards). Dormant
       until the directory/reviews module is on (dark today) AND the store has
       approved reviews — else both blocks are omitted, so this ships safely dark. */
    $aboutReviews = collect();
    $aboutRating  = null;
    if (\Juwri\Modules\Storefront\Services\StoreReviewsDisplay::enabledFor($store->id)) {
        $approvedReviews = \App\Models\StoreReview::approved()->forStore($store->id)->latest()->get(['reviewer_name', 'stars', 'body']);
        if ($approvedReviews->isNotEmpty()) {
            $aboutRating  = round((float) $approvedReviews->avg('stars'), 1);
            $aboutCount   = $approvedReviews->count();
            $aboutReviews = $approvedReviews->take(6);
        }
    }
    $customerApiToken = storefront()->customerApiToken($customer, 'plate');

    /* menu_sections visibility */
    $savedSections = isset($postOptions->menu_sections)
        ? json_decode(json_encode($postOptions->menu_sections), true) : null;
    $sectionVisible = ['hero' => true, 'catbar' => true, 'daily_specials' => true, 'menu' => true];
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

    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));

    $splashTitle = theme_setting($store->id, 'splash.title', $store->title);

    /* Real prep-time estimate for the confirmation screen. Server-computed
       (heuristic → owner's prep_eta_static), null when the store has neither —
       the theme then shows nothing rather than inventing a clock time. */
    $prepEtaLine = \Juwri\Modules\Ordering\Services\Orders\EtaEstimator::estimate($store->id)?->toArray()['line'] ?? null;
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="plate" id="plate" style="--plate-brand: {{ theme_setting($store->id, 'colors.primary', '#141414') }}">
    {{-- Appearance, applied at parse time so a dark-mode customer never sees the
         white storefront flash before script.js boots at the bottom of the page. --}}
    <script nonce="{{ csp_nonce() }}">(function(){try{var m=localStorage.getItem('plate-mode-'+@json($store->slug));if(m==='dark'||m==='light'){document.currentScript.parentElement.setAttribute('data-mode',m);}}catch(e){}})();</script>

    {{-- ══════════ SPLASH ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="splash" id="splash">
        <span class="cap sp-tag">{{ $store->sub_title ?: ___('Fine food · ordered simply') }}</span>
        <div class="sp-disc">
            @if((string) (media_url('storage/menu/', $featured->image ?? 'default.png', $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $featured->image ?? 'default.png', $store->id ?? null) }}" alt="" data-csp-error="fallback" data-csp-fallback="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">@endif
        </div>
        <h1 class="sp-brand"><span data-tc-text="splash.title" style="color:inherit">{{ $splashTitle }}</span><span>.</span></h1>
        <span class="sp-rule" aria-hidden="true"></span>
        <button class="sp-skip cap" id="splashSkip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="cv-views" id="views">

        {{-- MENU (home) --}}
        <section class="view is-active" data-view="home">
            @php
                $__plateHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
                $plateCoverUrl = $__plateHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
                $plateShowCover = $__plateHasCover && theme_setting($store->id, 'brand.show_cover', true);
            @endphp
            {{-- Collapsing header: overlays the top of the home view, slides in once the
                 editorial cover leaves the scroll container, hides again as the sticky
                 category bar reaches the top. Mirrors the top-chrome buttons (proxied). --}}
            <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
                <span class="cv-condensed-logo">@if(!empty($store->main_image) && $store->main_image !== 'default.png')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif</span>
                <b class="cv-condensed-name">{{ $store->title }}</b>
                <span class="cv-condensed-actions">
                    @if(count($menu_languages ?? []) > 1)
                    <button class="rbtn" type="button" data-cv-proxy="lang" aria-label="{{ ___('Language') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="rbtn" type="button" data-cv-proxy="catering" aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    <button class="rbtn" type="button" data-cv-proxy="notif" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                    </button>
                </span>
            </div>
            <div class="view-scroll" id="homeScroll">
                @if($plateShowCover)
                {{-- Editorial cover hero: full-bleed photo under a hairline frame; the
                     Menu title + chrome sit over it in white, fading to the paper. --}}
                <div class="plate-cover">
                    <div class="plate-cover-media" aria-hidden="true">
                        @if((string) ($plateCoverUrl) !== '')<img class="plate-cover-img" src="{{ $plateCoverUrl }}" alt="" data-csp-error="hide">@endif
                        <span class="plate-cover-scrim"></span>
                        <span class="plate-cover-frame"></span>
                    </div>
                @endif
                <header class="m-top">
                    <button class="rbtn" id="menuBtn" type="button" aria-label="{{ ___('Navigation') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                    </button>
                    <span class="m-top-space"></span>
                    @if(count($menu_languages ?? []) > 1)
                    {{-- Language: a globe opening the sheet below, so the header holds one
                         control whether the store runs two languages or eleven. The shared
                         language-switch partial delegates on [data-lang-code]. --}}
                    <button class="rbtn" id="langBtn" type="button" aria-label="{{ ___('Language') }}" aria-haspopup="dialog">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    {{-- Catering & Events: opens the dedicated #cvCateringSheet (JS-wired). --}}
                    @if(storefront_catering_enabled($store->id))
                    <button class="rbtn" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    {{-- Customizer v2: `search.enabled` gates the only way into the
                         search overlay. The overlay itself is gated by the same
                         setting, now that script.js null-checks `#searchPanel`. --}}
                    @if(theme_setting($store->id, 'search.enabled', true))
                    <button class="rbtn" id="searchBtn" type="button" aria-label="{{ ___('Search') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                    </button>
                    @endif
                    @if($allow_order)
                    <button class="rbtn" id="cartBtn" type="button" aria-label="{{ ___('Cart') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>
                        <i class="count" id="cartCount" hidden>0</i>
                    </button>
                    @endif
                    {{-- Notification-center bell (shared partial, rev/notif-center-spec.md).
                         Not gated: guests use recent-orders, logged-in diners the inbox. --}}
                    <button class="rbtn" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                        <i class="m-filter-dot" id="mbNotifDot" hidden></i>
                    </button>
                    {{-- Light/dark quick toggle (Lane B) — drives plate's native
                         appearance system (data-mode + plate-mode-<slug> key), same
                         store as the Auto/Light/Dark segment in Settings. --}}
                    <button class="rbtn" id="plateThemeToggle" type="button"
                            aria-pressed="false" aria-label="{{ ___('Toggle dark mode') }}">
                        <svg class="ic mb-th-moon" viewBox="0 0 24 24"><path d="M20.5 14.4A8.5 8.5 0 1 1 9.6 3.5a6.8 6.8 0 0 0 10.9 10.9Z"/></svg>
                        <svg class="ic mb-th-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.6 12h2.2M19.2 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
                    </button>
                </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


                <div class="m-titlerow">
                    <h1 class="m-title">{{ ___('Menu') }}</h1>
                    <div class="m-titletools">
                        @if($allowDietary)
                        <button class="m-filter" id="filterBtn" type="button" aria-label="{{ ___('Filters') }}">
                            <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 8h14M5 12h10M5 16h6"/></svg>
                            <span class="cap">{{ ___('Filters') }}</span>
                            <i class="m-filter-dot" id="filterDot" hidden></i>
                        </button>
                        @endif
                        <button class="m-sort" id="sortBtn" type="button">
                            <span class="cap"><span>{{ ___('Sort By') }}</span>
                                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></span>
                            <b id="sortVal">{{ ___('Featured') }}</b>
                        </button>
                    </div>
                </div>

                {{-- Open/closed status chip, driven by $isOpen (R4). --}}
                <div class="open-chip {{ $isOpen ? '' : 'is-closed' }}" data-mb-openchip data-mb-closed-class="is-closed">
                    <i aria-hidden="true"></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span>
                </div>
                @if($plateShowCover)
                </div>{{-- /.plate-cover --}}
                @endif

                {{-- F2 · brand / follow row (ported from crave cvBrandRow parity):
                     store mark, name + verified badge, follower proof line, follow button. --}}
                @if($sectionVisible['brand_row'] ?? true)
                <div class="brand-row" id="plateBrandRow" data-mb-hotel-chip-slot>
                    <span class="brand-logo">
                        @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                    </span>
                    <span class="brand-info">
                        <b class="brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="verified-badge" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
                        @if(trim((string)($store->sub_title ?? '')) !== '')
                        <span class="brand-tagline">{{ $store->sub_title }}</span>
                        @endif
                        @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true)])
                    </span>
                    {{-- Following needs a customer account: on a guest_only store the auth
                         sheet is never rendered, so a guest tap has nowhere to go. --}}
                    @if($authMode !== 'guest_only' || $customer)
                    <button class="followbtn {{ $isFollowing ? 'is-on' : '' }}" id="plateFollowBtn" type="button"
                            data-following="{{ $isFollowing ? '1' : '0' }}">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                        <span class="follow-label">{{ theme_setting($store->id, 'texts.follow_label', ___('Follow')) }}</span>
                        <span class="following-label">{{ ___('Following') }}</span>
                    </button>
                    @endif
                </div>
                @endif

                {{-- B8 · store-review display — avg rating + up to 2 approved cards.
                     Self-gated: renders only when the reviews module is on and the
                     store has approved reviews (dark today), so it ships dormant. --}}
                @if($aboutRating !== null)
                <div class="plate-reviews" id="plateReviews" data-mb-reviews-end>
                    <div class="plate-rating"><span class="plate-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
                    @if($aboutReviews->isNotEmpty())
                    <div class="plate-review-list">
                        @foreach($aboutReviews as $rev)
                        <div class="plate-review">
                            <div class="plate-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="plate-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                            @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endif

                @if($loyaltyProgram && $customer)
                <button class="loyalty-chip" id="loyaltyChip" type="button">
                    <svg class="ic ic-xs star-fill" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>
                    <b id="ptsVal">–</b>&nbsp;<span>{{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('pts') }}</span>
                    <span class="loyalty-link">{{ ___('view rewards') }}</span>
                </button>
                @endif

                {{-- Stories rail. Stays hidden (and contributes no gap — see the
                     .plate [hidden] guard) until the stories endpoint returns rows. --}}
                <div class="stories" id="storiesRail" aria-label="{{ ___('Stories') }}" hidden></div>


                {{-- admin banners (real, optional) --}}
                @if(!empty($menuBanners) && count($menuBanners))
                <div class="banners">
                    @foreach($menuBanners as $banner)
                        <a class="promo" href="{{ storefront_localized_link($banner->link_url) ?: '#' }}" @if($banner->link_url) target="_blank" rel="noopener" @endif>
                            <span class="promo-copy">
                                @if($banner->subtitle)<span class="cap gold">{{ $banner->subtitle }}</span>@endif
                                <b class="promo-title">{{ $banner->title }}</b>
                                <span class="promo-cta"><span>{{ ___('Order now') }}</span>
                                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></span>
                            </span>
                            @if($banner->image)
                            <span class="promo-img">@if((string) ($banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null)) !== '')<img src="{{ $banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null) }}" alt="">@endif</span>
                            @endif
                        </a>
                    @endforeach
                </div>
                @elseif($sectionVisible['daily_specials'] && $dailySpecial)
                <button class="promo" id="promo" data-open="{{ $dailySpecial->id }}" type="button">
                    <span class="promo-copy">
                        <span class="cap gold" data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___('Daily special · today')) }}</span>
                        <b class="promo-title">{{ $dailySpecial->name }}</b>
                        <span class="promo-cta"><span>{{ ___('View dish') }}</span>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></span>
                    </span>
                    <span class="promo-img">@if((string) (media_url('storage/menu/', $dailySpecial->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $dailySpecial->image, $store->id ?? null) }}" alt="" data-csp-error="closest-hide" data-csp-closest=".promo-img">@endif</span>
                </button>
                @endif

                @if($sectionVisible['catbar'])
                {{-- Sentinel: once this scrolls above the top, the sticky category bar
                     takes over the top and the collapsing header hides so they never stack. --}}
                <span class="cv-menu-sentinel" aria-hidden="true"></span>
                {{-- Primary (parent) category chips. A parent chip shows every
                     descendant item; data-has-sub marks parents that own a second row. --}}
                {{-- data-mb-grid-lead: the category selector must touch the items it
                     filters — injected sections (offers strip, order-type bar) climb
                     above these rows instead of wedging between categories and grid. --}}
                <nav class="cats" id="cats" aria-label="{{ ___('Categories') }}" data-mb-grid-lead data-mbt-anchor>
                    <button class="cat is-on" data-cat="all" type="button">{{ ___('All') }}</button>
                    @foreach($categories as $cat)
                        <button class="cat" data-cat="{{ $cat->id }}"
                                @isset($subCatsOf[$cat->id]) data-has-sub="1" @endisset type="button">{{ $cat->name }}</button>
                    @endforeach
                </nav>
                {{-- Secondary sub-category chips. One hidden group per parent that has
                     item-bearing subcategories; the active parent's group is revealed by
                     JS. "All" (data-sub="root") = every descendant of the parent. --}}
                @if(!empty($subCatsOf))
                <nav class="subcats" id="subCats" aria-label="{{ ___('Sub-categories') }}" hidden data-mb-grid-lead>
                    @foreach($subCatsOf as $parentId => $subs)
                        <div class="subgroup" data-parent="{{ $parentId }}" hidden>
                            <button class="subcat is-on" data-sub="root" type="button">{{ ___('All') }}</button>
                            @foreach($subs as $sub)
                                <button class="subcat" data-sub="{{ $sub->id }}" type="button">{{ $sub->name }}</button>
                            @endforeach
                        </div>
                    @endforeach
                </nav>
                @endif
                @endif

                @if($sectionVisible['menu'])
                <div class="m-grid" id="grid"></div>
                <div class="grid-empty" id="gridEmpty" hidden>
                    <svg viewBox="0 0 64 64" class="empty-art"><circle cx="32" cy="32" r="22"/><path d="M24 42c4-4 12-4 16 0" stroke-linecap="round"/></svg>
                    <p>{{ ___('Nothing on the menu matches') }}</p>
                    @if($allowDietary)
                    <button class="btn-hair" id="clearFilters" type="button" hidden>{{ ___('Clear filters') }}</button>
                    @endif
                </div>
                @endif

                @if($reservationsEnabled || $waitlistEnabled)
                <button class="strip" id="reserveStrip" type="button">
                    <span class="strip-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="4" y="6" width="16" height="14" rx="2.5"/><path d="M4 10.5h16M8 4v4M16 4v4"/></svg></span>
                    <span class="strip-copy"><b>{{ ___('Book a table') }}</b><small>{{ ___('Reservations & waitlist') }}</small></span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg>
                </button>
                @endif

                @if($waNumber)
                <a class="strip" id="waStrip" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                    <span class="strip-ic wa"><svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg></span>
                    <span class="strip-copy"><b>{{ ___('Order on WhatsApp') }}</b><small>{{ ___('We reply within minutes') }}</small></span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg>
                </a>
                @endif

                <p class="demo-foot">{{ $store->title }}</p>
                <div class="tail-space"></div>
            </div>
            <button class="totop" id="toTop" type="button" aria-label="{{ ___('Back to top') }}" hidden>
                <svg class="ic" viewBox="0 0 24 24"><path d="m6 15 6-6 6 6"/></svg>
            </button>
        </section>

        {{-- ITEM DETAIL --}}
        <section class="view view-sub" data-view="item">
            <div class="view-scroll" id="itemScroll">
                <header class="i-top">
                    <button class="rbtn" data-back type="button" aria-label="{{ ___('Back') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </button>
                    <span class="m-top-space"></span>
                    <button class="rbtn" id="itemFav" type="button" aria-label="{{ ___('Favorite') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
                    </button>
                </header>

                <div class="plate-hero">
                    <div class="plate-disc"><img id="plateImg" alt=""></div>
                    <div class="plate-chips">
                        <div class="pchip" id="chipTimeBox" hidden><span class="cap">{{ ___('Time') }}</span><b id="chipTime"></b></div>
                        <div class="pchip" id="chipServesBox" hidden><span class="cap">{{ ___('Serves') }}</span><b id="chipServes"></b></div>
                    </div>
                </div>

                {{-- per-item gallery: thumbnail strip that swaps the disc image (populated by JS) --}}
                <div class="plate-gallery" id="plateGallery" hidden></div>

                <div class="i-body">
                    <span class="i-flag" id="itemFlag" hidden></span>
                    <h1 class="i-title" id="itemTitle">—</h1>
                    <button class="i-stars" id="itemStars" type="button" aria-label="{{ ___('Reviews') }}"></button>
                    <p class="i-desc" id="itemDesc"></p>
                    <div class="i-tags" id="itemTags"></div>
                    <div class="i-vars" id="itemVars"></div>
                    <div class="i-extras" id="itemExtras"></div>

                    {{-- product reviews — rebuilt by setItem(); the shared
                         _partials/item-reviews layer hooks on .p-reviews to inject
                         the histogram + write button. Owner switch: omit the whole
                         section when item_reviews_enabled='0' (setItem's $("#itemReviews")
                         guard then no-ops). --}}
                    @if((string) store_options($store->id, 'item_reviews_enabled', '1') !== '0')
                    <section class="p-reviews" id="itemReviews"></section>
                    @endif
                </div>
                <div class="tail-space tall"></div>
            </div>

            @if($allow_order)
            <footer class="foot-cta">
                <div class="qty" id="iQtyBox">
                    <button class="qty-btn" data-iq="-1" type="button" aria-label="{{ ___('Decrease quantity') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
                    <output id="iQtyVal">1</output>
                    <button class="qty-btn" data-iq="1" type="button" aria-label="{{ ___('Increase quantity') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
                </div>
                <button class="btn-black" id="addBtn" type="button"><span>{{ ___('Add to Cart') }}</span><b id="addPrice"></b></button>
                {{-- shown by script.js once the cart has items: a direct jump to the cart --}}
                <button class="p-cart-jump" id="pCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>
                    <i class="p-cart-jump-count" id="pCartJumpCount" aria-hidden="true">0</i>
                </button>
            </footer>
            @endif
        </section>

        {{-- CART --}}
        <section class="view view-sub" data-view="cart">
            <div class="view-scroll">
                <header class="s-head">
                    <button class="rbtn" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1 class="s-title">{{ ___('Your Cart') }}</h1>
                    <button class="rbtn" id="clearCart" type="button" aria-label="{{ ___('Clear cart') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg></button>
                </header>

                <div id="cartLines" class="c-lines"></div>

                <div class="c-empty" id="cartEmpty" hidden>
                    <svg class="ce-art" viewBox="0 0 64 64"><circle cx="32" cy="32" r="22"/><circle cx="32" cy="32" r="13"/></svg>
                    <p>{{ ___('Your table is still empty') }}</p>
                    <button class="btn-hair" data-go="home" type="button">{{ ___('Browse the menu') }}</button>
                </div>

                @if($allow_order)
                <div id="cartTools" class="c-tools">
                    @if($allowCoupons)
                    <label class="coupon" id="couponBox">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/></svg>
                        <input id="couponInput" placeholder="{{ ___('Promo code') }}" autocomplete="off">
                        <button class="btn-mini" id="couponApply" type="button">{{ ___('Apply') }}</button>
                    </label>
                    <p class="coupon-msg" id="couponMsg" hidden></p>
                    @endif

                    <section class="c-block">
                        <h3 class="cap">{{ ___('Order type') }}</h3>
                        <div class="seg" id="otSeg">
                            @if($allow_on_table)<button data-ot="on-table" class="is-on">{{ ___('Dine-in') }}</button>@endif
                            @if($allow_room_service)<button data-ot="room_service">{{ ___('Room service') }}</button>@endif
                            @if($allow_takeaway)<button data-ot="takeaway">{{ ___('Pickup') }}</button>@endif
                            @if($allow_delivery)<button data-ot="delivery">{{ ___('Delivery') }}</button>@endif
                        </div>
                        <div class="ot-fields" id="otFields"></div>
                        @if($allowOrderSched)
                        <div class="when-row">
                            <span class="cap">{{ ___('When') }}</span>
                            <div class="seg mini" id="whenSeg">
                                <button data-when="now" class="is-on">{{ ___('Now') }}</button>
                                <button data-when="later">{{ ___('Later') }}</button>
                            </div>
                            <input aria-label="{{ ___('Schedule for later') }}" type="datetime-local" id="whenTime" class="when-time" hidden
                                   min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}"
                                   max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
                        </div>
                        @endif
                        @if($allowWaiter && $allow_on_table)
                        <button class="btn-hair wide" id="waiterBtn" type="button" hidden>
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg>
                            <span>{{ ___('Call the waiter') }}</span>
                        </button>
                        @endif
                    </section>

                    <section class="c-block">
                        <h3 class="cap">{{ ___('Note') }} ({{ ___('optional') }})</h3>
                        <textarea aria-label="{{ ___('Any special requests?') }}" id="orderNote" rows="2" placeholder="{{ ___('Any special requests?') }}"></textarea>
                    </section>

                    <section class="totals" id="cartTotals"></section>
                    <p class="minorder" id="minOrderMsg" hidden></p>
                </div>
                @endif
                <div class="tail-space tall"></div>
            </div>
            @if($allow_order)
            <footer class="foot-cta" id="cartCta" hidden>
                <button class="btn-black wide" id="checkoutBtn" type="button"><span>{{ ___('Checkout') }}</span><b id="cartTotalCta"></b></button>
            </footer>
            @endif
        </section>

        {{-- CHECKOUT --}}
        <section class="view view-sub" data-view="checkout">
            <div class="view-scroll">
                <header class="s-head">
                    <button class="rbtn" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1 class="s-title">{{ ___('Payment') }}</h1>
                    <span class="rbtn ghost-spot" aria-hidden="true"></span>
                </header>

                <section class="c-block">
                    <h3 class="cap">{{ ___('Contact') }}</h3>
                    <input aria-label="{{ ___('Your Name') }}" id="ckName" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}" autocomplete="name">
                    <div id="ckPhoneWrap">
                        <input aria-label="{{ ___('Phone number') }}" id="ckPhone" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
                    </div>
                    @if($authMode !== 'guest_only' && !$customer)
                        <button class="linkbtn" id="loginLink" type="button">{{ ___('Have an account?') }} {{ ___('Login') }}</button>
                    @endif
                </section>

                <section class="c-block">
                    <h3 class="cap">{{ ___('Pay with') }}</h3>
                    <div class="pay-list" id="payList">
                        <button class="pay is-on" data-pay="pay_on_counter" type="button">
                            <span class="pay-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.6"/></svg></span>
                            <b>{{ ___('Cash') }}<small>{{ ___('Pay at the counter or courier') }}</small></b>
                            <span class="pay-radio"></span>
                        </button>
                        @if($allow_payment)
                        <button class="pay" data-pay="pay_online" type="button">
                            <span class="pay-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h4"/></svg></span>
                            <b>{{ ___('Card') }}<small>{{ ___('Secure online payment') }}</small></b>
                            <span class="pay-radio"></span>
                        </button>
                        @endif
                    </div>
                    @if($customer && $storeCredit > 0)
                    <label class="wallet-row" id="walletRow">
                        <span class="w-copy"><b>{{ ___('Wallet credit') }}</b><small id="walletBal">{{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }}</small></span>
                        <span class="switch"><input type="checkbox" id="walletChk"><i></i></span>
                    </label>
                    @endif
                </section>

                @if($allowTips)
                <section class="c-block">
                    <h3 class="cap">{{ ___('Add a tip') }}</h3>
                    <div class="tip-row" id="tipRow">
                        <button data-tip="0" class="is-on">{{ ___('None') }}</button>
                        <button data-tip="10">10%</button>
                        <button data-tip="15">15%</button>
                        <button data-tip="20">20%</button>
                    </div>
                </section>
                @endif

                @if(!$customer)
                <section class="c-block">
                    <label class="terms">
                        <input type="checkbox" id="consentCb">
                        <span>{{ ___('Send me offers and updates from this restaurant') }}</span>
                    </label>
                    <div id="captureEmailWrap" hidden>
                        <input aria-label="{{ ___('your@email.com') }}" type="email" id="captureEmail" placeholder="{{ ___('your@email.com') }}" autocomplete="email">
                    </div>
                    <label class="terms">
                        <input type="checkbox" id="waOptin">
                        <span>{{ ___('Send me offers and updates on WhatsApp') }}</span>
                    </label>
                </section>
                @endif

                {{-- Order summary — the customer should see WHAT they are paying for at
                     the point of commitment, not just a number. Rendered by renderCheckout. --}}
                <section class="ck-items" id="ckItems" hidden></section>
                <section class="totals" id="ckTotals"></section>
                <p class="err-box" id="ckError" hidden style="margin-top:12px"></p>
                <div class="tail-space tall"></div>
            </div>
            <footer class="foot-cta">
                <button class="btn-black wide" id="placeBtn" type="button">
                    <span class="po-label">{{ ___('Place Order') }}</span>
                    <b id="ckTotalCta"></b>
                    <span class="spinner" hidden></span>
                </button>
            </footer>
        </section>

        {{-- CONFIRMED --}}
        <section class="view view-sub view-center" data-view="confirmed">
            <div class="conf-wrap">
                <svg class="conf-ring" viewBox="0 0 120 120" aria-hidden="true">
                    <circle class="cr-track" cx="60" cy="60" r="54"/>
                    <circle class="cr-prog" cx="60" cy="60" r="54" pathLength="1"/>
                    <path class="cr-check" d="M40 62l14 13 27-31" pathLength="1"/>
                </svg>
                <h1 class="conf-title">{{ ___('Order Confirmed') }}</h1>
                <p class="conf-sub"><span>{{ ___('Thank you — the kitchen has your order') }}</span><br><span id="confEta"></span></p>
                <span class="conf-code" id="confCode"></span>
                <div class="conf-actions">
                    <a class="btn-black wide" id="confTrack" href="#"><span>{{ ___('Track Order') }}</span></a>
                    @if($googleReviewUrl)
                    <a class="btn-hair" id="confGoogle" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener">{{ ___('Review us on Google') }}</a>
                    @endif
                    <button class="btn-hair" id="confBack" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span>{{ ___('Back to menu') }}</span></button>
                </div>
            </div>
        </section>

    </main>

    {{-- Notification-center panel (shared partial; bell lives in the home header). --}}
    @include('store_templates._partials.notif-bell')

    {{-- ══════════ VERTICAL CART RAIL (plate signature) ══════════ --}}
    @if($allow_order)
    <aside class="rail" id="rail" aria-label="{{ ___('Your Cart') }}">
        <button class="rail-x" id="railClose" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <span class="rail-label cap">{{ ___('Your Cart') }}</span>
        <div class="rail-thumbs" id="railThumbs"></div>
        <div class="rail-foot">
            <span class="cap rail-tl">{{ ___('Total') }}</span>
            <b class="rail-total" id="railTotal"></b>
            <button class="rail-go" id="railGo" type="button" aria-label="{{ ___('Go to cart') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>
            </button>
        </div>
    </aside>
    @endif

    {{-- ══════════ SEARCH OVERLAY ══════════ --}}
    {{-- Customizer v2: same `search.enabled` switch as the toolbar button above —
         with the button gone nothing can open this, so it is dropped too. --}}
    @if(theme_setting($store->id, 'search.enabled', true))
    <div class="search-panel" id="searchPanel" hidden>
        <div class="sp-top">
            <label class="sp-field">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input aria-label="{{ ___('Search the menu') }}" id="searchInput" type="search" placeholder="{{ ___('Search the menu') }}…" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
            </label>
            <button class="rbtn" id="searchClose" type="button" aria-label="{{ ___('Close') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="sp-results" id="searchResults"></div>
        <p class="sp-empty cap" id="searchEmpty" hidden>{{ ___('Nothing on the menu matches') }}</p>
    </div>
    @endif

    {{-- ══════════ STORY VIEWER ══════════ --}}
    <div class="story-view" id="storyView" hidden>
        <div class="sv-progress" id="svProgress"></div>
        <button class="sv-close" id="svClose" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="sv-stage" id="svStage">
            <img class="sv-media" id="svMedia" alt="">
            <button class="sv-nav prev" id="svPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="sv-nav next" id="svNext" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="sv-foot">
                <p class="sv-caption" id="svCaption"></p>
                <a class="btn-black wide sv-cta" id="svCta" target="_blank" rel="noopener" hidden><span></span></a>
            </div>
        </div>
    </div>

    {{-- ══════════ SHEETS ══════════ --}}
    <div class="scrim" id="scrim" hidden></div>

    <div class="sheet" id="navSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ $store->title }}</h2>
        <div class="nav-list">
            <button class="nav-row" data-nav="home"><span class="cap">{{ ___('Menu') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></button>
            @if($allow_order)<button class="nav-row" data-nav="cart"><span class="cap">{{ ___('Your cart') }}</span><b class="nav-val" id="navCartN">0</b></button>@endif
            @if($loyaltyProgram && $customer)<button class="nav-row" data-nav="rewards"><span class="cap">{{ ___('Rewards') }}</span><b class="nav-val gold" id="navPts">–</b></button>@endif
            @if($reservationsEnabled || $waitlistEnabled)<button class="nav-row" data-nav="reserve"><span class="cap">{{ ___('Reservations & waitlist') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></button>@endif
            @if($customer)
                <a class="nav-row" href="{{ route('customer.profile', $store->id) }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg><span class="cap">{{ ___('Profile & settings') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></a>
                <a class="nav-row" href="{{ route('customer.account', $store->id) }}"><span class="cap">{{ ___('My Orders') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></a>
            @elseif($authMode !== 'guest_only')
                <button class="nav-row" data-nav="login"><span class="cap">{{ ___('Login') }} / {{ ___('Register') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></button>
            @endif
            <button class="nav-row" data-nav="settings"><span class="cap">{{ ___('Settings') }}</span><b class="nav-val" id="navLang">{{ strtoupper(get_lang() ?: 'EN') }}</b></button>
            @if($waNumber)<a class="nav-row" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener"><span class="cap">{{ ___('Order on WhatsApp') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></a>@endif
            {{-- Share this menu — native profile row (like the WhatsApp .nav-row), not the floating pill.
                 Gated on the same share.fallback_button toggle; data-web-share stands down _partials/share. --}}
            @if(theme_setting($store->id, 'share.fallback_button'))<button class="nav-row share-btn" id="mbShareRow" type="button" data-web-share aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}"><span class="cap">{{ ___('Share this menu') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></button>@endif
        </div>
        {{-- Guest order history (R5): the last few orders kept in localStorage, so a
             guest with no account can still reach their tracking pages. --}}
        @if(!$customer)
        <div class="ro-list" id="plateRecentOrders" hidden></div>
        @endif
    </div>

    <div class="sheet" id="sortSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Sort By') }}</h2>
        <div class="nav-list" id="sortList"></div>
    </div>

    @if($loyaltyProgram && $customer)
    <div class="sheet" id="rewardsSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Rewards') }}</h2>
        <div class="pts-hero">
            <b class="pts-big" id="ptsBig">–</b>
            <span class="cap">{{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }}</span>
        </div>
        <div class="pts-bar"><i id="ptsBar"></i></div>
        <p class="pts-next cap" id="ptsNext"></p>
        <div class="reward-row">
            <span class="r-copy"><b>{{ $loyaltyProgram->reward_description ?: ___('Reward') }}</b><small>{{ $loyaltyProgram->reward_threshold }} {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('pts') }}</small></span>
            <button class="btn-black sm" id="redeemBtn" type="button" disabled>{{ ___('Redeem') }}</button>
        </div>
        <p class="rate-thanks cap" id="redeemOk" hidden>{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
        <p class="sheet-note cap">{{ ___('Earn points every time you order') }}</p>
    </div>
    @endif

    <div class="sheet" id="settingsSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Settings') }}</h2>
        {{-- Appearance. Always rendered: without it the Settings sheet is an empty
             shell on any store with one menu language, one branch and no
             multi-currency (i.e. most stores). "Auto" follows the OS. --}}
        <div class="set-row">
            <span class="cap">{{ ___('Appearance') }}</span>
            <div class="seg mini" id="modeSeg">
                <button data-mode="auto" class="is-on">{{ ___('Auto') }}</button>
                <button data-mode="light">{{ ___('Light') }}</button>
                <button data-mode="dark">{{ ___('Dark') }}</button>
            </div>
        </div>
        @if(count($menu_languages ?? []) > 1)
        <div class="set-row">
            <span class="cap">{{ ___('Language') }}</span>
            <div class="seg mini" id="langSeg">
                @foreach($menu_languages as $language)
                    <button data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" class="{{ get_lang() === $language->code ? 'is-on' : '' }}">{{ $language->name }}</button>
                @endforeach
            </div>
        </div>
        @endif
        @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
        <div class="set-row">
            <span class="cap">{{ ___('Currency') }}</span>
            <div class="seg mini" id="curSeg">
                <button data-cur="{{ config('settings.currency_code', 'USD') }}" data-rate="1" class="is-on">{{ $curSign }}</button>
                @foreach($mcRates as $code => $rate)
                    <button data-cur="{{ $code }}" data-rate="{{ $rate }}">{{ $code }}</button>
                @endforeach
            </div>
        </div>
        @endif
        @if($showBranches)
        <div class="set-row">
            <span class="cap">{{ ___('Branch') }}</span>
            <button class="btn-hair" data-open-sheet="branchSheet" type="button">{{ $activeBranch->name ?? ___('Choose branch') }}</button>
        </div>
        <button class="btn-hair wide" id="nearestBranchBtn" type="button" style="margin-top:8px">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <span>{{ ___('Find nearest branch on map') }}</span>
        </button>
        @endif
        <p class="sheet-note cap">{{ ___('the_qr_menu_for') }} {{ $store->title }}</p>
    </div>

    <div class="sheet" id="reviewSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Rate this dish') }}</h2>
        <div class="rate-stars" id="rvStars"></div>
        @if($googleReviewUrl)
        <a class="btn-black g-cta wide" id="rvGoogle" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener" hidden>
            <span class="g-badge">G</span><span>{{ ___('Review us on Google') }}</span>
        </a>
        @endif
        <p class="rate-thanks cap" id="rvThanks" hidden>{{ ___('Thank you for the feedback') }}</p>
    </div>

    {{-- FILTER sheet (dietary / allergens / spice) --}}
    @if($allowDietary)
    <div class="sheet" id="filterSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Filters') }}</h2>
        <h4>{{ ___('Dietary') }}</h4>
        <div class="chiprow" id="dietRow">
            <button data-diet="vegan" type="button">{{ ___('Vegan') }}</button>
            <button data-diet="halal" type="button">{{ ___('Halal') }}</button>
            <button data-diet="gluten_free" type="button">{{ ___('Gluten-free') }}</button>
        </div>
        <h4>{{ ___('Spice level') }}</h4>
        <div class="chiprow" id="spiceRow">
            <button data-spice="1" type="button">🌶</button>
            <button data-spice="2" type="button">🌶🌶</button>
            <button data-spice="3" type="button">🌶🌶🌶</button>
        </div>
        @if(count($allergies ?? []))
        <h4>{{ ___('Exclude allergens') }}</h4>
        <div class="chiprow" id="allergenRow">
            @foreach($allergies as $a)
                <button data-al="{{ $a->id }}" type="button">{{ $a->title }}</button>
            @endforeach
        </div>
        @endif
        <div class="sheet-actions">
            <button class="btn-hair" id="filterReset" type="button">{{ ___('Reset') }}</button>
            <button class="btn-black" id="filterDone" type="button"><span>{{ ___('Show dishes') }}</span><b id="filterCount"></b></button>
        </div>
    </div>
    @endif

    {{-- nearest-branch map picker (self-hosted Leaflet, geo branch switcher) --}}
    @if($showBranches)
    <div class="sheet" id="branchMapSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Nearest branch') }}</h2>
        <div id="branchMap" style="height:300px;border-radius:14px;overflow:hidden;margin:6px 0"></div>
        <p id="branchMapAttrib" style="font-size:11px;opacity:.55;margin:6px 2px"></p>
        <div class="branch-list" id="branchGeoList"></div>
    </div>
    @endif

    {{-- reservation / waitlist (real server forms) --}}
    @if($reservationsEnabled)
    <div class="sheet" id="reserveSheet" hidden>
        <div class="sheet-grab"></div>
        {{-- Explicit close (carries sheet-grab for the existing close wiring; the
             sheet-x class restyles it into a corner X button). --}}
        <button class="sheet-grab sheet-x" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2 class="sheet-title">{{ ___('Reserve a Table') }}</h2>
        @if(session('reservation_success'))
            <div class="sheet-done">
                <span class="sheet-done-emoji">📅</span>
                <b>{{ ___('Request received!') }}</b>
                <p>{{ session('reservation_success') }}</p>
            </div>
        @else
            <form class="sheet-form" action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                @csrf
                <span class="field-label">{{ ___('Your Name') }}</span>
                <input aria-label="{{ ___('Your Name') }}" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}">
                <span class="field-label">{{ ___('Phone number') }}</span>
                <input aria-label="{{ ___('Phone number') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}">
                <span class="field-label">{{ ___('Date & Time') }}</span>
                <input aria-label="{{ ___('Date & Time') }}" name="reserved_at" type="datetime-local" required min="{{ now()->format('Y-m-d\TH:i') }}">
                <span class="field-label">{{ ___('Party Size') }}</span>
                <input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                <span class="field-label">{{ ___('Note') }} ({{ ___('optional') }})</span>
                <textarea aria-label="{{ ___('Any special requests?') }}" name="notes" rows="2" maxlength="500" placeholder="{{ ___('Any special requests?') }}"></textarea>
                @include('store_templates._partials.voice-note-recorder')
                @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                <button class="btn-black wide" type="submit" style="margin-top:14px"><span>{{ ___('Request Reservation') }}</span></button>
            </form>
        @endif
    </div>
    @endif

    @if($waitlistEnabled)
    <div class="sheet" id="waitlistSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Join the Waitlist') }}</h2>
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
                <button class="btn-black wide" type="submit" style="margin-top:14px"><span>{{ ___('Join Waitlist') }}</span></button>
            </form>
        @endif
    </div>
    @endif

    @if(($reservationsEnabled && $waitlistEnabled))
    <div class="sheet" id="reserveHub" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Reservations & waitlist') }}</h2>
        <div class="nav-list">
            <button class="nav-row" data-open-sheet="reserveSheet"><span class="cap">{{ ___('Reserve a Table') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></button>
            <button class="nav-row" data-open-sheet="waitlistSheet"><span class="cap">{{ ___('Join the Waitlist') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></button>
        </div>
    </div>
    @endif

    @if($showBranches)
    <div class="sheet" id="branchSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Choose branch') }}</h2>
        <div class="branch-list">
            @foreach($branches as $branch)
                <a class="branch-row {{ ($activeBranch->id ?? null) === $branch->id ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    <b>{{ $branch->name }}</b><span class="branch-check"></span>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    @if($authMode !== 'guest_only')
    @if(count($menu_languages ?? []) > 1)
    <div class="sheet" id="langSheet" hidden>
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

    <div class="sheet" id="authSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Login') }} / {{ ___('Register') }}</h2>
        <div class="auth-tabs" id="authTabs">
            <button data-auth-tab="login" class="is-on" type="button">{{ ___('Login') }}</button>
            <button data-auth-tab="otp" type="button">{{ ___('Phone OTP') }}</button>
            <button data-auth-tab="register" type="button">{{ ___('Register') }}</button>
        </div>
        <p class="err-box" id="authErr" hidden style="margin-bottom:10px"></p>
        <div class="auth-form" data-auth="login">
            <input aria-label="{{ ___('Email') }}" id="loginEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Password') }}" id="loginPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="current-password">
            <button class="btn-black wide" id="loginBtn" type="button"><span>{{ ___('Login') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
            @include('store_templates.partials.passkey-login', ['store' => $store])
        </div>
        <div class="auth-form" data-auth="otp" hidden>
            <div id="otpStep1">
                <p class="sheet-sub">{{ ___("We'll text you a one-time code") }}</p>
                <input aria-label="{{ ___('Phone number') }}" id="otpPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel">
                <button class="btn-black wide" id="otpSend" type="button" style="margin-top:11px"><span>{{ ___('Send code') }}</span></button>
            </div>
            <div id="otpStep2" hidden>
                <p class="sheet-sub">{{ ___('Enter the code we sent you') }}</p>
                <div class="otp-boxes" id="otpBoxes">
                    <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                </div>
                <button class="btn-black wide" id="otpVerify" type="button" style="margin-top:11px"><span>{{ ___('Verify') }}</span></button>
            </div>
        </div>
        <div class="auth-form" data-auth="register" hidden>
            <input aria-label="{{ ___('Your Name') }}" id="regName" placeholder="{{ ___('Your Name') }}" autocomplete="name">
            <input aria-label="{{ ___('Email') }}" id="regEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Phone number') }}" id="regPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
            <input aria-label="{{ ___('Password') }}" id="regPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
            <input aria-label="{{ ___('Confirm Password') }}" id="regPass2" type="password" placeholder="{{ ___('Confirm Password') }}" autocomplete="new-password">
            <button class="btn-black wide" id="registerBtn" type="button"><span>{{ ___('Register') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
        </div>
    </div>
    @endif

    {{-- catering request form now lives in the #cvCateringSheet bottom-sheet below,
         opened from the header #cvCateringBtn (see script.js on("cvCateringBtn"…)). --}}
    @if(storefront_catering_enabled($store->id))
    <div class="sheet" id="cvCateringSheet" hidden>
        <div class="sheet-grab"></div>
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
    </div>
    @endif

    {{-- Floating bottom dock (standardized nav). Plate's primary nav was a
         slide-out sheet (#menuBtn → #navSheet); this adds the persistent bar. --}}
    <nav class="pl-dock" id="plDock" aria-label="{{ ___('Main') }}">
        <button class="pl-dock-btn is-on" data-dock="home" type="button" aria-label="{{ ___('Home') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg></button>
        <button class="pl-dock-btn" data-dock="menu" type="button" aria-label="{{ ___('Menu') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
        @if(theme_setting($store->id, 'search.enabled', true))
        <button class="pl-dock-btn" data-dock="search" type="button" aria-label="{{ ___('Search') }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg></button>
        @endif
        @if($allow_order)
        <button class="pl-dock-btn" data-dock="cart" type="button" aria-label="{{ ___('Cart') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><i class="pl-dock-badge" id="plDockCart" hidden>0</i></button>
        @endif
        <button class="pl-dock-btn" data-dock="favs" type="button" aria-label="{{ ___('Favorites') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
        <button class="pl-dock-btn" data-dock="profile" type="button" aria-label="{{ ___('Profile') }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg></button>
    </nav>

    {{-- Favorites sheet — plate hearts items but had no way to view the saved list. --}}
    <div class="sheet" id="favSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 class="sheet-title">{{ ___('Favorites') }}</h2>
        <div id="favList"></div>
        <div class="pl-fav-empty" id="favEmpty">
            <p>{{ ___('No favorites yet') }}</p>
            <span>{{ ___('Tap the heart on any dish to save it here.') }}</span>
        </div>
    </div>

    <div class="toast" id="toast" role="status"></div>
    <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>
</div>
@endsection

@push('scripts_at_bottom')
@php
    $plateI18n = [
        'add_to_cart'   => ___('Add to Cart'),
        'added'         => ___('added to your cart'),
        'added_item'    => ___('Added :item to your cart'),
        'removed'       => ___('Removed'),
        'remove'        => ___('Remove'),
        'decrease_qty'  => ___('Decrease quantity'),
        'increase_qty'  => ___('Increase quantity'),
        'cleared'       => ___('Cart cleared'),
        'fav_on'        => ___('Saved to favorites'),
        'fav_off'       => ___('Removed from favorites'),
        'sold_out'      => ___('Sold out'),
        'not_available' => ___('Not available now'),
        'popular'       => ___('Popular'),
        'order_now'     => ___('Order now'),
        'recent_orders' => ___('Recent orders'),
        'stories'       => ___('Stories'),
        'daily_special' => ___('Daily special'),
        'reviews'       => ___('Reviews'),
        'no_reviews'    => ___('No reviews yet.'),
        'from'          => ___('From'),
        'size'          => ___('Size'),
        'extras'        => ___('Extras'),
        'optional'      => ___('optional'),
        'required'      => ___('Required'),
        'min'           => ___('min'),
        'person'        => ___('person'),
        'people'        => ___('people'),
        'kcal'          => ___('kcal'),
        'tag_vegan'     => ___('Vegan'),
        'tag_veg'       => ___('Veggie'),
        'tag_halal'     => ___('Halal'),
        'tag_gf'        => ___('Gluten-free'),
        'your_order'    => ___('Your order'),
        'edit'          => ___('Edit'),
        'subtotal'      => ___('Subtotal'),
        'discount'      => ___('Discount'),
        'delivery_fee'  => ___('Delivery fee'),
        'tray_fee'      => ___('Room service charge'),
        'tip_label'     => ___('Tip'),
        'total'         => ___('Total'),
        'table'         => ___('Table'),
        'dinein_hint'   => ___('Served to your table'),
        'pickup_hint'   => ___('Ready for pickup shortly'),
        'delivery_hint' => ___('Delivered to your address'),
        'addr_ph'       => ___('Street, building, floor…'),
        'use_location'  => ___('Use my location'),
        'loc_unavail'   => ___('Location unavailable'),
        'loc_denied'    => ___('Location denied'),
        'no_address'    => ___('No address found'),
        'add_note'      => ___('+ Add a note'),
        'fill_addr'     => ___('Please add a delivery address'),
        'fill_contact'  => ___('Please add your name'),
        'select_type'   => ___('Please choose an order type'),
        'min_order'     => ___('Minimum order'),
        'coupon_bad'    => ___('Invalid promo code.'),
        'coupon_ok'     => ___('Coupon applied — you save'),
        'waiter_ok'     => ___('A waiter will be with you shortly!'),
        'waiter_table'  => ___('Please enter your table number'),
        'sort_featured' => ___('Featured'),
        'sort_price_asc'=> ___('Price · low to high'),
        'sort_price_desc'=>___('Price · high to low'),
        'sort_pop'      => ___('Most popular'),
        'sort_rated'    => ___('Top rated'),
        'est_ready'     => ___('Ready around'),
        'pts_earned'    => ___('points earned'),
        'redeemed'      => ___('Reward redeemed! Enjoy your reward.'),
        'no_pts'        => ___('Not enough points yet'),
        'to_reward'     => ___('to your next reward'),
        'reward_ready'  => ___('Reward unlocked — treat yourself'),
        'thanks'        => ___('Thank you for the feedback'),
        'network_err'   => ___('Network error. Please try again.'),
        'choose'        => ___('Choose an option'),
        'place_order'   => ___('Place Order'),
        'all'           => ___('All'),
        'show_dishes'   => ___('Show dishes'),
    ];
@endphp
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
<script nonce="{{ csp_nonce() }}">
    window.PLATE = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        currencySign:   @json($curSign),
        currencyGap:    @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:    @json($curPos),
        currencyDecimals: @json($curDecimals),
        moneyDecimals: @json(money_decimals(@$postOptions->currency_code ?: null)), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        baseCurrency:   @json(config('settings.currency_code', 'USD')),
        currencyRates:  @json($mcRates),
        taxCharge:      @json($taxCharge),
        taxType:        @json($taxType),
        taxLabel:       @json($taxLabel),
        trayCharge:     @json($trayChargeCfg),
        deliveryCharge: @json($deliveryCharge),
        prepEtaLine:    @json($prepEtaLine),
        minOrderValue:  @json($minOrderValue),
        allowOrder:     @json((bool) $allow_order),
        allowPayment:   @json((bool) $allow_payment),
        allowCallWaiter:@json((bool) ($allowWaiter && $allow_on_table)),
        forceTable:     @json((bool) $table),
        defaultTable:   @json($table->table_no ?? null),
        authMode:       @json($authMode),
        isLoggedIn:     @json((bool) $customer),
        customerName:   @json($customer->name ?? null),
        customerToken:  @json($customerApiToken),
        csrf:           @json(csrf_token()),
        loyaltyType:      @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json((int) ($loyaltyProgram->reward_threshold ?? 0)),
        googleReview:     @json($googleReviewUrl ?: null),
        openResSheet:      @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        savedAddresses: @json($jsSavedAddresses),
        cats:           @json($jsCats),
        items:          @json((object) $jsItems),
        endpoints: {
            submitOrder:   @json(route('store.submitOrder', $store->id)),
            coupon:      @json(route('store.applyCoupon', $store->id)),
            waiter:      @json(route('store.callTheWaiter', $store->id)),
            review:      @json(route('store.submitReview', $store->slug)),
            itemEvent:   @json(route('store.recordItemEvent', $store->slug)),
            stories:     @json(route('stories.public', $store->slug)),
            branchesGeo: @json($showBranches ? route('geo.branches', $store->slug) : null),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:      @json(route('geo.reverse')),
            login:       @json(route('customer.login', $store->id)),
            register:    @json(route('customer.register', $store->id)),
            otpRequest:  @json(route('customer.otp.request', $store->id)),
            otpVerify:   @json(route('customer.otp.verify', $store->id)),
            loyaltyBalance: @json($loyaltyProgram ? route('publicView.loyalty.balance', $store->id) : null),
            loyaltyRedeem:  @json($loyaltyProgram ? route('publicView.loyalty.redeem', $store->id) : null),
            follow:      @json(url('r/'.$store->slug.'/follow')),
        },
        i18n: @json($plateI18n),
    };
</script>
{{-- script.js is loaded once by store_templates.layout --}}
@endpush

@push('scripts_at_bottom')
@endpush

@push('scripts_at_bottom')
{{-- ZERO-TOUCH A3.1 — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endpush
