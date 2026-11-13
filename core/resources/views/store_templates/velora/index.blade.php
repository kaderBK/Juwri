{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       velora — lime ordering-app post_template · data preparation
       Faithful rebuild of rev/velora/demo. Real data + real ordering.
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus   = collect($total_menus)->values();
    $items      = $allMenus->values();

    // Two-tier category nav (primary parent chips + a secondary sub-category chip
    // row that swaps per active parent). menu_categories() is top-level only, but a
    // menu item keys off its OWN category_id (possibly a subcategory), so each JS item
    // carries BOTH 'cat' (real → sub-chip filter) and 'root' (top-level ancestor →
    // parent-chip filter). $rootOf maps any category id → its top-level ancestor.
    $rootOf = [];
    foreach ($store->menu_categories as $parentCat) {
        $rootOf[$parentCat->id] = $parentCat->id;
        foreach ($parentCat->subcategories as $subCat) {
            $rootOf[$subCat->id] = $parentCat->id;
        }
    }
    $rootItemCount = [];   // top-level id => descendant item count (parent chips)
    $catItemCount  = [];   // real cat id  => own item count (sub-chips)

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

    $splashImgs = \Juwri\Core\Support\ViewHelpers::imageItems($items, 3);

    /* helper: human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* JS data map — variants absolute, groups/extras additive */
    $jsItems = [];
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
        if (isset($m->variants)) {
            foreach ($m->variants as $v) {
                $variants[] = ['id' => $v->id, 'label' => $variantLabel($m, $v), 'price' => (float) $v->price];
            }
        }
        // The store names its own variant group ("Style", "Size", …). Printing a
        // hardcoded "Select size" over a Regular/Spicy picker is simply wrong.
        $variantTitle = collect($m->variantOptions ?? [])
            ->map(fn($vo) => trim((string) ($vo->title ?? '')))
            ->filter()
            ->unique()
            ->implode(' · ');
        $extras = [];
        if (isset($m->extras)) {
            foreach ($m->extras as $e) {
                $extras[] = ['id' => $e->id, 'title' => $e->title, 'price' => (float) $e->price, 'img' => $e->image ? media_url('storage/menu/', $e->image, $store->id ?? null) : ''];
            }
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
            'name'  => $m->name,
            'desc'  => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'img'   => media_url('storage/menu/', $m->image, $store->id ?? null),
            'price' => (float) $m->price,
            'type'  => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'=> $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : '',
            'reviews'=> (int) ($m->rating_count ?? 0),
            // Written-review total for the Reviews SECTION badge. rating_count above
            // counts every star rating (quick taps included), so the section head
            // said "118" over a list of one written review — reads fabricated.
            'reviewTotal' => collect($reviewsByMenu->get($m->id, collect()))->count(),
            'reviewList' => \Juwri\Core\Support\ViewHelpers::reviewList($reviewsByMenu->get($m->id, collect())),
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyList($itemAllergies),
            'variants' => $variants,
            'variantTitle' => $variantTitle,
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

    // Primary chips: top-level parents that hold at least one descendant item
    // (pure containers / empty parents drop). $categories now = parent pills only.
    $categories = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);

    // Secondary chips: per parent, only the subcategories that own items. A parent
    // with no item-bearing subcategory gets no second row (its items live directly on
    // it and show under the parent chip alone).
    $subCatsOf = [];
    foreach ($categories as $c) {
        $subs = \Juwri\Core\Support\ViewHelpers::withItems($c->subcategories, $catItemCount);
        if ($subs->isNotEmpty()) {
            $subCatsOf[$c->id] = $subs;
        }
    }

    /* parent photo-pills (rendered in JS from CFG.categories) */
    $jsCats = [];
    foreach ($categories as $cat) {
        $jsCats[] = [
            'id'   => $cat->id,
            'name' => $cat->name,
            'img'  => $cat->image ? media_url('storage/menu/', $cat->image, $store->id ?? null) : '',
        ];
    }

    /* per-parent sub-category chips (rendered in JS from CFG.subCats[parentId]) */
    $jsSubCats = [];
    foreach ($subCatsOf as $parentId => $subs) {
        $jsSubCats[$parentId] = $subs->map(fn($s) => [
            'id'   => $s->id,
            'name' => $s->name,
            'img'  => $s->image ? media_url('storage/menu/', $s->image, $store->id ?? null) : '',
        ])->values();
    }

    /* promo banners (admin-managed → signature lime cards) */
    $jsBanners = [];
    if (!empty($menuBanners)) {
        foreach ($menuBanners as $banner) {
            $jsBanners[] = [
                'chip'  => $banner->subtitle ?: ___('Offer'),
                'title' => $banner->title,
                'note'  => '',
                'btn'   => ___('Order now'),
                'img'   => $banner->image ? ($banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null)) : '',
                'link'  => storefront_localized_link($banner->link_url) ?: '',
            ];
        }
    }

    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();

    /* F2 · brand / follow row (crave cvBrandRow / orbit cvBrandRow parity) */
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
    $customerApiToken = $customer ? storefront()->customerApiToken($customer, 'velora') : null;

    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $customerDefaultAddress = $customerAddresses->first();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;

    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */

    $showBranches = !empty($branches) && count($branches) > 1 && (@$postOptions->allow_multi_branch_switch ?? 1);

    /* menu_sections visibility (same contract as crave/aurora) */
    $savedSections = isset($postOptions->menu_sections)
        ? json_decode(json_encode($postOptions->menu_sections), true)
        : null;
    $defaultSections = [
        ['id' => 'brand_row', 'visible' => true],
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
       section visibility — it overlays the legacy menu_sections row above. */
    $sectionVisible = array_replace($sectionVisible, theme_section_visible($store->id));

    /* engagement extras */
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));

    $myWaitlist = storefront()->activeWaitlist($store, $customer);
    $myReservation = storefront()->upcomingReservation($store, $customer);

    $logoUrl = media_url('storage/store/logo/', $store->main_image, $store->id ?? null);

    // Real prep-time window for the product page's store row (replaces the demo's
    // fabricated "Following / Official restaurant" block). Falls back to nothing
    // rather than to an invented number.
    $prepEtaStatic = trim((string) (@$postOptions->prep_eta_static ?: ''));
    $prepEtaLine   = $prepEtaStatic !== '' ? $prepEtaStatic.' '.___('min') : '';
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,400..700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- self-hosted Leaflet (nearest-branch picker; nonce/CSP-safe, tiles load as https imgs) --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
@endpush

@section('content')
<div class="velora" id="velora" style="--velora-brand: {{ theme_setting($store->id, 'colors.primary', '#b5d336') }}">
    {{-- Appearance bootstrap — runs during parse, before first paint, so a customer
         who chose Dark never sees a white flash. `auto` (or no choice) leaves the
         attribute off and the OS `prefers-color-scheme` rule decides. --}}
    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var k = 'velora-theme-' + @json($store->slug);
                var v = localStorage.getItem(k);
                if (v === 'dark' || v === 'light') {
                    document.getElementById('velora').setAttribute('data-velora-theme', v);
                }
            } catch (e) {}
        })();
    </script>


    {{-- ══════════ SPLASH ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if(theme_setting($store->id, 'splash.enabled', true) && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="splash" id="splash">
        <svg class="splash-waves" viewBox="0 0 400 820" preserveAspectRatio="none" aria-hidden="true">
            <path d="M-20 90 q 30 -24 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0"/>
            <path d="M-40 210 q 30 -24 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0"/>
            <path d="M-20 330 q 30 -24 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0"/>
            <path d="M-40 450 q 30 -24 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0"/>
            <path d="M-20 570 q 30 -24 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0"/>
            <path d="M-40 690 q 30 -24 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0 t 60 0"/>
        </svg>
        <div class="splash-core">
            <span class="splash-logo">
                <svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-1 11.6a2.1 2.1 0 0 1-2.1 1.9H8.7a2.1 2.1 0 0 1-2.1-1.9L5.6 8.4Z"/><path d="M9 10.4V6.9a3 3 0 0 1 6 0v3.5"/></svg>
                @if((string) ($logoUrl) !== '')<img src="{{ $logoUrl }}" alt="" style="position:absolute;inset:0" data-csp-error="remove">@endif
            </span>
            <h1 class="splash-name">{{ $store->title }}</h1>
            <p class="splash-tag" data-tc-text="splash.title">{{ theme_setting($store->id, 'splash.title', ___('Fresh food, zero fuss')) }}</p>
        </div>
        <button class="splash-skip" id="splashSkip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="views" id="views">

        {{-- HOME --}}
        <section class="view is-active" data-view="home">
            @php
                $__velHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
                $velCoverUrl = $__velHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
                $velShowCover = $__velHasCover && theme_setting($store->id, 'brand.show_cover', true);
            @endphp
            {{-- Collapsing header: slides in once the cover header leaves the scroll
                 container (velora's chips are not top-sticky, so no overlap guard). --}}
            <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
                <span class="cv-condensed-logo">@if(!empty($store->main_image) && $store->main_image !== 'default.png')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif</span>
                <b class="cv-condensed-name">{{ $store->title }}</b>
                <span class="cv-condensed-actions">
                    @if(count($menu_languages ?? []) > 1)
                    <button class="cbtn" type="button" data-cv-proxy="lang" aria-label="{{ ___('Language') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="cbtn" type="button" data-cv-proxy="catering" aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    <button class="cbtn" type="button" data-cv-proxy="notif" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                    </button>
                </span>
            </div>
            <div class="view-scroll">
                @if($velShowCover)
                {{-- Cover under an olive veil (duotone) + hairline inner frame — linen/
                     print feel, distinctly Mediterranean; chrome over it in white. --}}
                <div class="velora-cover">
                    <div class="velora-cover-media" aria-hidden="true">
                        @if((string) ($velCoverUrl) !== '')<img class="velora-cover-img" src="{{ $velCoverUrl }}" alt="" data-csp-error="hide">@endif
                        <span class="velora-cover-scrim"></span>
                        <span class="velora-cover-frame"></span>
                    </div>
                @endif
                <header class="home-top">
                    <button class="cbtn" id="menuBtn" type="button" aria-label="{{ ___('Menu') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h10M4 17h16"/></svg>
                    </button>
                    <span class="home-mid" aria-hidden="true"></span>
                    @if(count($menu_languages ?? []) > 1)
                    <button class="cbtn" id="langBtn" type="button" aria-label="{{ ___('Language') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    @if($allow_order)
                    <button class="cbtn bagbtn" data-go="cart" type="button" aria-label="{{ ___('Cart') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-1 11.6a2.1 2.1 0 0 1-2.1 1.9H8.7a2.1 2.1 0 0 1-2.1-1.9L5.6 8.4Z"/><path d="M9 10.4V6.9a3 3 0 0 1 6 0v3.5"/></svg>
                        <i class="bagcount" hidden>0</i>
                    </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="cbtn" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    {{-- Notification-center bell (shared partial, rev/notif-center-spec.md).
                         Not gated: guests use recent-orders, logged-in diners the inbox.
                         velora's .dot is scoped to .sb-filter, so the header bell dot is
                         styled by id to match velora's dot visual. --}}
                    <button class="cbtn" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                        <i id="mbNotifDot" hidden></i>
                    </button>
                    {{-- Light/dark quick toggle (Lane B) — drives velora's native
                         appearance system (data-velora-theme + velora-theme-<slug> key),
                         same store as the Auto/Light/Dark segment in Profile. --}}
                    <button class="cbtn" id="veloraThemeToggle" type="button"
                            aria-pressed="false" aria-label="{{ ___('Toggle dark mode') }}">
                        <svg class="ic mb-th-moon" viewBox="0 0 24 24"><path d="M20.5 14.4A8.5 8.5 0 1 1 9.6 3.5a6.8 6.8 0 0 0 10.9 10.9Z"/></svg>
                        <svg class="ic mb-th-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.6 12h2.2M19.2 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
                    </button>
                    <style nonce="{{ csp_nonce() }}">.velora .cbtn #mbNotifDot{position:absolute;top:12px;inset-inline-end:9px;width:8px;height:8px;border-radius:50%;background:var(--lime-2);outline:2.5px solid var(--card)}</style>
                </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


                <div class="status-strip">
                    <span class="open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                    @if($showBranches)
                        {{-- Multi-branch store: a crave cvBranchBtn-style selector (label + active
                             branch + chevron) opening the branch sheet, in place of the address link. --}}
                        <button class="home-branch" data-menu="branch" type="button">
                            <span class="hb-label">{{ ___('Branch') }}</span>
                            <span class="hb-value">
                                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                                <b>{{ $activeBranch->name ?? optional($branches->first())->name ?? $store->title }}</b>
                                <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                            </span>
                        </button>
                    @elseif($allow_order && $allow_delivery)
                        <button class="home-address" data-go="cart" type="button">
                            <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            <span>{{ $customerDefaultAddress ? ___('Change delivery address') : ___('Set delivery address') }}</span>
                        </button>
                    @endif
                    @if(!empty($scans_today) && $scans_today > 0)
                        <span class="viewers"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><b>{{ $scans_today }}</b> {{ ___('viewing today') }}</span>
                    @endif
                </div>
                @if($velShowCover)
                </div>{{-- /.velora-cover --}}
                @endif

                {{-- F2 · brand / follow row (crave cvBrandRow parity, ported via orbit):
                     store mark, name + verified badge, follower proof line, and a
                     follow button. B10: customizer section, default on. --}}
                @if($sectionVisible['brand_row'] ?? true)
                {{-- data-mb-hotel-chip-after: the room chip (shared hotel partial) mounts
                     right AFTER this row, as its own full-width row. The status-strip above
                     sits ON the cover art, so slotting the chip there put it over the
                     artwork, washed out against the image. --}}
                <div class="brand-row{{ $velShowCover ? ' brand-row--hero' : '' }}" id="cvBrandRow" data-tc-section="brand_row" data-mb-hotel-chip-after>
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
                    @if($authMode !== 'guest_only' || $customer)
                    <button class="followbtn {{ $isFollowing ? 'is-on' : '' }}" id="cvFollowBtn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
                        <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                        <span class="follow-label" data-tc-text="texts.follow_label">{{ theme_setting($store->id, 'texts.follow_label', ___('Follow')) }}</span>
                        <span class="following-label">{{ ___('Following') }}</span>
                    </button>
                    @endif
                </div>
                @endif

                {{-- B8 · store-review display — avg rating + up to 2 approved cards.
                     Self-gated: renders only when the reviews module is on and the
                     store has approved reviews (dark today), so it ships dormant. --}}
                @if($aboutRating !== null)
                <div class="vel-reviews" id="velReviews" data-mb-reviews-end>
                    <div class="vel-rating"><span class="vel-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
                    @if($aboutReviews->isNotEmpty())
                    <div class="vel-review-list">
                        @foreach($aboutReviews as $rev)
                        <div class="vel-review">
                            <div class="vel-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="vel-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                            @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endif

                {{-- Search + order-type seg sit directly under the brand row (cvBrandRow). --}}
                @if(theme_setting($store->id, 'search.enabled', true))
                <div class="searchbar">
                    <button class="sb-main" id="searchOpen" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                        <span class="sb-ph">{{ ___('Search here') }}…</span>
                    </button>
                    @if($allowDietary)
                    <span class="sb-div" aria-hidden="true"></span>
                    <button class="sb-filter" id="filterBtn" type="button" aria-label="{{ ___('Filters') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M8 12h8M10.5 17h3"/><circle cx="9" cy="7" r="1.6" fill="var(--card)"/><circle cx="14.5" cy="12" r="1.6" fill="var(--card)"/></svg>
                        <i class="dot" id="filterDot" hidden></i>
                    </button>
                    @endif
                </div>
                @endif

                @if($allow_order)
                {{-- Hidden, kept in the DOM: the shared #mbOrderType bar (anchored above
                     the categories) is the visible picker now, and proxies its clicks to
                     these buttons for the actual order-type state. --}}
                <div class="seg home-order-types" style="display:none" aria-label="{{ ___('Order type') }}">
                    @if($allow_delivery)<button data-ot="delivery" type="button">{{ ___('Delivery') }}</button>@endif
                    @if($allow_takeaway)<button data-ot="takeaway" type="button">{{ ___('Pickup') }}</button>@endif
                    @if($allow_on_table)<button data-ot="on-table" type="button">{{ ___('Dine-in') }}</button>@endif
                    @if($allow_room_service)<button data-ot="room_service" type="button">{{ ___('Room service') }}</button>@endif
                </div>
                @endif

                {{-- stories rail — JS-populated from the public stories feed. Stays
                     hidden (and contributes no gap) when the feed is empty/errors. --}}
                <div class="story-rail" id="storyRail" hidden></div>

                <div class="banners" id="bannersWrap" hidden>
                    <div class="banner-track" id="bannerTrack"></div>
                    <div class="banner-dots" id="bannerDots"></div>
                </div>

                {{-- data-mb-grid-lead: the category selector must touch the items it
                     filters — injected sections (offers strip, order-type bar) climb
                     above these rows instead of wedging between categories and grid. --}}
                <div class="section-head" data-mb-grid-lead data-mbt-anchor>
                    <h2>{{ ___('Categories') }}</h2>
                    <button class="seeall" id="catSeeAll" type="button">{{ ___('See all') }}</button>
                </div>
                <div class="cat-rail" id="catRail" data-mb-grid-lead></div>
                {{-- secondary sub-category chips — revealed by JS only when the active
                     parent has item-bearing subcategories --}}
                <div class="subchips" id="catSubRail" hidden aria-label="{{ ___('Sub-categories') }}" data-mb-grid-lead></div>

                <div class="section-head">
                    <h2 id="gridTitle">{{ ___('New Arrivals') }}</h2>
                    <button class="seeall" id="gridSeeAll" type="button">{{ ___('See all') }}</button>
                </div>
                <div class="grid" id="grid"></div>
                <div class="grid-empty" id="gridEmpty" hidden>
                    <svg viewBox="0 0 64 64" class="empty-art"><circle cx="32" cy="32" r="22"/><path d="M24 28c2-3 6-3 8 0M36 28c2-3 6-3 8 0" stroke-linecap="round"/><path d="M24 42c4-4 12-4 16 0" stroke-linecap="round"/></svg>
                    <p>{{ ___('Nothing matches — try clearing filters') }}</p>
                    <button class="ghostbtn" id="clearFilters" type="button">{{ ___('Clear filters') }}</button>
                </div>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- PRODUCT --}}
        <section class="view view-sub" data-view="product">
            <div class="view-scroll" id="productScroll">
                <header class="sub-head">
                    <button class="cbtn" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1>{{ ___('Details') }}</h1>
                    @if($allow_order)
                    <button class="cbtn bagbtn" data-go="cart" type="button" aria-label="{{ ___('Cart') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-1 11.6a2.1 2.1 0 0 1-2.1 1.9H8.7a2.1 2.1 0 0 1-2.1-1.9L5.6 8.4Z"/><path d="M9 10.4V6.9a3 3 0 0 1 6 0v3.5"/></svg><i class="bagcount" hidden>0</i></button>
                    @else
                    <span class="cbtn ghostslot" aria-hidden="true"></span>
                    @endif
                </header>
                <div class="p-photo">
                    <div class="p-gallery" id="pGallery"></div>
                    {{-- desktop/pointer paging for multi-image items; hidden for single-image --}}
                    <button class="p-gal-arr prev" id="pGalPrev" type="button" hidden aria-label="{{ ___('Previous') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <button class="p-gal-arr next" id="pGalNext" type="button" hidden aria-label="{{ ___('Next') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button>
                </div>
                <div class="p-dots" id="pGalleryDots"></div>
                <span class="p-stock" id="pStock" hidden></span>
                <div class="p-body" id="pBody"></div>
                <div class="tail-space tall"></div>
            </div>
            @if($allow_order)
            <footer class="buybar">
                <div class="buy-total"><span>{{ ___('Total price') }}</span><b id="pTotal">{{ store_money_format(0, $store->id) }}</b></div>
                <button class="buy-cta" id="addToCart" type="button">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-1 11.6a2.1 2.1 0 0 1-2.1 1.9H8.7a2.1 2.1 0 0 1-2.1-1.9L5.6 8.4Z"/><path d="M9 10.4V6.9a3 3 0 0 1 6 0v3.5"/></svg>
                    <span>{{ ___('Add to Cart') }}</span>
                </button>
                {{-- shown by script.js once the cart has items: a direct jump to the
                     cart, beside Add to Cart (orbit #pCartJump parity). --}}
                <button class="p-cart-jump" id="pCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M3 5h2.2l2.5 10.2a2 2 0 0 0 2 1.5h6.9a2 2 0 0 0 1.95-1.55L20.5 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
                    <i class="p-cart-jump-count" id="pCartJumpCount" aria-hidden="true">0</i>
                </button>
            </footer>
            @endif
        </section>

        {{-- FAVORITES --}}
        <section class="view" data-view="favs">
            <div class="view-scroll">
                <header class="page-head"><h1>{{ ___('Favorites') }}</h1></header>
                <div class="grid" id="favGrid"></div>
                <div class="grid-empty" id="favEmpty" hidden>
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
                    <button class="ghostbtn sm" id="clearCart" type="button">{{ ___('Clear') }}</button>
                </header>
                <div class="cart-list" id="cartList"></div>
                <div class="grid-empty" id="cartEmpty" hidden>
                    <svg viewBox="0 0 64 64" class="empty-art"><path d="M20 22h24l-2.4 26.4a4 4 0 0 1-4 3.6H26.4a4 4 0 0 1-4-3.6L20 22Z"/><path d="M26 22a6 6 0 0 1 12 0"/></svg>
                    <p>{{ ___('Your bag is feeling light') }}</p>
                    <button class="ghostbtn" data-go="home" type="button">{{ ___('Browse menu') }}</button>
                </div>

                @if($allow_order)
                <div class="cart-tools" id="cartTools">
                    <section>
                        <h3>{{ ___('Order type') }}</h3>
                        <div class="seg" id="orderTypeSeg">
                            @if($allow_delivery)<button data-ot="delivery" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg><span>{{ ___('Delivery') }}</span></button>@endif
                            @if($allow_takeaway)<button data-ot="takeaway" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-1 11.6a2.1 2.1 0 0 1-2.1 1.9H8.7a2.1 2.1 0 0 1-2.1-1.9L5.6 8.4Z"/><path d="M9 10.4V6.9a3 3 0 0 1 6 0v3.5"/></svg><span>{{ ___('Pickup') }}</span></button>@endif
                            @if($allow_on_table)<button data-ot="on-table" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg><span>{{ ___('Dine-in') }}</span></button>@endif
                            @if($allow_room_service)<button data-ot="room_service" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 6v2M10.5 6h3"/></svg><span>{{ ___('Room service') }}</span></button>@endif
                        </div>
                        <div class="ot-fields" id="otFields"></div>
                        @if($allowOrderSched)
                        <div class="when-row">
                            <span class="when-label"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ ___('When') }}</span>
                            <div class="seg mini" id="whenSeg">
                                <button data-when="asap" class="is-on" type="button">{{ ___('ASAP') }}</button>
                                <button data-when="later" type="button">{{ ___('Later') }}</button>
                            </div>
                            <input aria-label="{{ ___('Schedule for later') }}" type="datetime-local" name="scheduled_at" id="whenTime" class="when-time" hidden
                                   min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}"
                                   max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
                        </div>
                        @endif
                    </section>

                    @if(!empty($postOptions->curbside_enabled))
                    <section>
                        <label class="terms"><input type="checkbox" name="curbside" value="1" data-csp-toggle="vlCurbFields" data-csp-display="grid"><span>{{ ___("Curbside pickup — I'll wait in my car") }}</span></label>
                        <div id="vlCurbFields" style="display:none;gap:8px;margin-top:8px">
                            <input aria-label="{{ ___('Car make/model') }}" type="text" name="vehicle_make" placeholder="{{ ___('Car make/model') }}">
                            <input aria-label="{{ ___('Colour') }}" type="text" name="vehicle_color" placeholder="{{ ___('Colour') }}">
                            <input aria-label="{{ ___('Plate') }}" type="text" name="vehicle_plate" placeholder="{{ ___('Plate') }}">
                        </div>
                    </section>
                    @endif

                    @if($allowCoupons)
                    <section>
                        <label class="coupon-field">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                            <input id="couponInput" placeholder="{{ ___('Promo code') }}">
                            <button class="minibtn" id="couponApply" type="button">{{ ___('Apply') }}</button>
                        </label>
                        <p class="coupon-msg" id="couponMsg" hidden></p>
                    </section>
                    @endif

                    @if(!empty($postOptions->gift_cards_enabled))
                    <section>
                        <label class="coupon-field">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/></svg>
                            <input aria-label="{{ ___('Gift card code') }}" name="gift_card_code" placeholder="{{ ___('Gift card code') }}">
                        </label>
                    </section>
                    @endif

                    <section class="ordernote">
                        <label><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5V4Z"/><path d="M15 4v4h4M8.5 12h7M8.5 16h5"/></svg>{{ ___('Note') }} ({{ ___('optional') }})</label>
                        <textarea aria-label="{{ ___('Any special requests?') }}" id="orderNote" rows="2" placeholder="{{ ___('Any special requests?') }}"></textarea>
                    </section>

                    @if($allowWaiter && $allow_on_table)
                    <div class="waiter-row" id="waiterRow" hidden>
                        <button class="ghostbtn" id="callWaiter" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span>{{ ___('Call waiter') }}</span></button>
                        <button class="ghostbtn" id="requestBill" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg><span>{{ ___('Request bill') }}</span></button>
                    </div>
                    @endif

                    @if($waNumber)
                    <a class="wa-order" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                        <span>{{ ___('Order via WhatsApp') }}</span>
                        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </a>
                    @endif

                    <section class="totals" id="totalsBox"></section>
                    <p class="minorder" id="minOrderMsg" hidden></p>
                </div>
                @endif
                <div class="tail-space tall2"></div>
            </div>
            @if($allow_order)
            <footer class="buybar" id="cartCta" hidden>
                <div class="buy-total"><span>{{ ___('Total') }}</span><b id="cartCtaTotal"></b></div>
                <button class="buy-cta" id="goCheckout" type="button"><span>{{ ___('Checkout') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></button>
            </footer>
            @endif
        </section>

        {{-- CHECKOUT --}}
        <section class="view view-sub" data-view="checkout">
            <div class="view-scroll">
                <header class="sub-head">
                    <button class="cbtn" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1>{{ ___('Checkout') }}</h1>
                    <span class="cbtn ghostslot" aria-hidden="true"></span>
                </header>

                <section class="blk">
                    <h3>{{ ___('Contact details') }}</h3>
                    <div>
                        <span class="field-label">{{ ___('Your name') }}</span>
                        <input aria-label="{{ ___('Your name') }}" id="custName" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}" autocomplete="name">
                    </div>
                    <div id="phoneField">
                        <span class="field-label">{{ ___('Phone number') }}</span>
                        <input aria-label="{{ ___('Phone') }}" id="custPhone" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}" inputmode="tel" autocomplete="tel">
                    </div>
                    <div id="tableField" style="display:none">
                        <span class="field-label">{{ ___('Table') }}</span>
                        @if($table)
                            <input aria-label="{{ ___('Table number') }}" id="tableInput" value="{{ $table->table_no }}" readonly>
                        @else
                            <input aria-label="{{ ___('Table number') }}" id="tableInput" type="number" placeholder="{{ ___('Table number') }}" inputmode="numeric">
                        @endif
                    </div>
                    @if($authMode !== 'guest_only' && !$customer)
                        <button class="linkbtn" id="loginLink" type="button">{{ ___('Have an account?') }} {{ ___('Login') }}</button>
                    @endif
                </section>

                <section class="blk" id="addrBlock" style="display:none">
                    <h3>{{ ___('Delivery address') }}</h3>
                    {{-- Saved addresses (logged-in): tap to fill the field + coords. --}}
                    @if($customerAddresses->count())
                    <div class="saved-addrs" id="savedAddrs">
                        @foreach($customerAddresses as $sa)
                        <button class="saved-addr{{ $loop->first ? ' is-on' : '' }}" type="button"
                                data-addr="{{ $addrLabel($sa) }}" data-lat="{{ $sa->lat }}" data-lng="{{ $sa->lng }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            <span class="sa-text"><b>{{ $sa->label ?: ___('Saved address') }}</b><small>{{ Str::limit($addrLabel($sa), 42) }}</small></span>
                        </button>
                        @endforeach
                    </div>
                    @endif
                    {{-- Guest recent addresses (localStorage): populated by JS for non-logged-in
                         customers; sits in the same spot as the server address book above. --}}
                    @if(!$customer)
                    <div class="saved-addrs" id="savedAddrsGuest" hidden></div>
                    @endif
                    {{-- address autocomplete + use-my-location (geo.autocomplete / geo.reverse) --}}
                    <div class="addr-field" id="custAddrField">
                        <input aria-label="{{ ___('Street, building, floor…') }}" id="custAddr" placeholder="{{ ___('Street, building, floor…') }}" autocomplete="off" autocorrect="off" spellcheck="false"
                               value="{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}">
                        <input type="hidden" id="custLat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                        <input type="hidden" id="custLng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                        <button class="uselocbtn" id="custUseLocation" type="button" title="{{ ___('Use my location') }}" aria-label="{{ ___('Use my location') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                        </button>
                        <div class="addr-suggest" id="custAddrSuggest" hidden></div>
                    </div>
                    <p class="geo-attrib" id="custGeoAttrib" hidden></p>
                    {{-- Drop-a-pin address picker — opens velora's own bottom sheet
                         (shared window.mbMapPicker engine, wired at the foot of this file). --}}
                    <button class="ghostbtn wide vl-map-trigger" id="vlAddrMapBtn" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <span>{{ ___('Choose on map') }}</span>
                    </button>
                </section>

                @if($allowTips)
                <section class="blk">
                    <h3>{{ ___('Add a tip') }}</h3>
                    <div class="tip-row" id="tipRow">
                        <button data-tip="0" class="is-on" type="button">{{ ___('No tip') }}</button>
                        <button data-tip="5" type="button">5%</button>
                        <button data-tip="10" type="button">10%</button>
                        <button data-tip="15" type="button">15%</button>
                        <button data-tip="custom" type="button">{{ ___('Custom') }}</button>
                    </div>
                    <input aria-label="{{ ___('Custom tip amount') }}" type="number" id="customTip" placeholder="{{ ___('Custom tip amount') }}" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}" hidden style="margin-top:9px">
                </section>
                @endif

                <section class="blk">
                    <h3>{{ ___('Pay with') }}</h3>
                    <div class="pm-list" id="pmList">
                        <button class="pm is-on" data-pm="pay_on_counter" type="button">
                            <span class="pm-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.6"/></svg></span>
                            <b>{{ ___('Cash') }}<small>{{ ___('Pay on arrival') }}</small></b>
                            <span class="pm-radio"></span>
                        </button>
                        @if($allow_payment)
                        <button class="pm" data-pm="pay_online" type="button">
                            <span class="pm-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h4"/></svg></span>
                            <b>{{ ___('Card') }}<small>{{ ___('Card / wallet via secure page') }}</small></b>
                            <span class="pm-radio"></span>
                        </button>
                        @endif
                        @if($customer && $storeCredit > 0)
                        <button class="pm" data-pm="wallet" type="button">
                            <span class="pm-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7a2 2 0 0 1 2-2h12v3M4 7v10a2 2 0 0 0 2 2h13a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H4"/><circle cx="16.5" cy="14" r="1.3" fill="currentColor" stroke="none"/></svg></span>
                            <b>{{ ___('Wallet') }}<small>{{ ___('Balance') }} {{ $curPos==='1' ? $curSign.$curGap.number_format($storeCredit,2) : number_format($storeCredit,2).$curGap.' '.$curSign }}</small></b>
                            <span class="pm-radio"></span>
                        </button>
                        @endif
                    </div>
                </section>

                @if(!$customer)
                <section class="blk">
                    <label class="terms"><input type="checkbox" id="consentCb" data-csp-toggle="captureEmailWrap" data-csp-toggle-mode="hidden"><span>{{ ___('Send me offers and updates from this restaurant') }}</span></label>
                    <div id="captureEmailWrap" hidden style="padding-top:9px"><input aria-label="{{ ___('your@email.com') }}" type="email" id="captureEmail" placeholder="{{ ___('your@email.com') }}" autocomplete="email"></div>
                    <label class="terms" style="margin-top:9px"><input type="checkbox" id="waOptin"><span>{{ ___('Send me offers and updates on WhatsApp') }}</span></label>
                </section>
                @endif

                {{-- Order summary — the customer should see WHAT they are paying for at
                     the point of commitment, not just a number. Rendered by renderCheckout. --}}
                <section class="blk ck-items" id="ckItems" hidden></section>
                <section class="totals" id="checkoutTotals"></section>
                <p class="err-box" id="formError" hidden style="margin-top:12px"></p>
                <div class="tail-space tall"></div>
            </div>
            <footer class="buybar">
                <div class="buy-total"><span>{{ ___('Total') }}</span><b id="checkoutCtaTotal"></b></div>
                <button class="buy-cta" id="placeOrder" type="button"><span class="po-label">{{ ___('Place Order') }}</span><span class="spinner" hidden></span></button>
            </footer>
        </section>

        {{-- CONFIRMED --}}
        <section class="view view-sub view-center" data-view="confirmed">
            <div class="confirm-wrap">
                <div class="confetti" id="confetti" aria-hidden="true"></div>
                <div class="confirm-art" aria-hidden="true">
                    <svg class="ca-ring" viewBox="0 0 150 150"><circle cx="75" cy="75" r="66"/></svg>
                    <div class="ca-circle"><svg viewBox="0 0 48 48"><path class="ca-check" d="M14 25l7 7 14-16" pathLength="1"/></svg></div>
                </div>
                <h1 class="confirm-title">{{ ___('Order Confirmed') }}</h1>
                <p class="confirm-sub"><span>{{ ___('Your order is in — we’re on it') }}</span><br><span class="confirm-eta" id="confirmEta"></span></p>
                <div class="confirm-code" id="confirmCode" hidden></div>
                <p class="confirm-pts" id="confirmPts"></p>
                <div class="confirm-actions">
                    <a class="pill dark wide" id="confirmTrack" href="#"><span>{{ ___('Track Order') }}</span></a>
                    <button class="ghostbtn" data-go="home" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span>{{ ___('Back to menu') }}</span></button>
                </div>
                <div class="nps">
                    <p>{{ ___('How likely are you to recommend us?') }}</p>
                    <div class="nps-row" id="npsRow">@for($n = 0; $n <= 10; $n++)<button type="button" data-nps="{{ $n }}">{{ $n }}</button>@endfor</div>
                    <p class="nps-thanks" id="npsThanks" hidden>{{ ___('Thanks for the feedback!') }}</p>
                </div>
                @if($googleReviewUrl)
                <a class="greview" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener">
                    <span class="greview-star">⭐</span>
                    <span class="greview-copy"><b>{{ ___('Enjoying your meal?') }}</b><small>{{ ___('Rate us on Google') }}</small></span>
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
                    <span class="me-avatar">@if($customer){{ Str::upper(Str::substr($customer->name, 0, 1)) }}@else<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.9-3.7 3.7-5.6 7-5.6s6.1 1.9 7 5.6"/></svg>@endif</span>
                    <div>
                        <b>{{ $customer->name ?? ___('Guest') }}</b>
                        <p class="tk-label">{{ $customer ? ($customer->phone ?? $customer->email) : ___('Order without an account') }}</p>
                    </div>
                    @if($authMode !== 'guest_only')
                        @if($customer)<button class="minibtn" id="logoutBtn" type="button">{{ ___('Logout') }}</button>
                        @else<button class="minibtn" id="meAuthBtn" type="button">{{ ___('Login') }} / {{ ___('Register') }}</button>@endif
                    @endif
                </div>

                @if($loyaltyProgram && $customer)
                <section class="loyalty" id="loyaltyBlock">
                    <div class="loyalty-head"><svg class="ic" viewBox="0 0 24 24"><path d="M12 3l2.5 5.5 6 .5-4.5 4 1.4 5.9L12 20.8 6.6 18.9 8 13 3.5 9l6-.5L12 3Z"/></svg><b>{{ ___('Your Loyalty Balance') }}</b></div>
                    <div class="loyalty-num"><span id="loyaltyBalance">–</span></div>
                    <p class="loyalty-sub">{{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }} / {{ $loyaltyProgram->reward_threshold }} · {{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }}</p>
                    @if($loyaltyProgram->type === 'stamps')
                    <div class="stamp-dots" id="stampDots">@for($s = 0; $s < $loyaltyProgram->reward_threshold; $s++)<span class="stamp-dot" data-idx="{{ $s }}"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 3l2.5 5.5 6 .5-4.5 4 1.4 5.9L12 20.8 6.6 18.9 8 13 3.5 9l6-.5L12 3Z"/></svg></span>@endfor</div>
                    @else
                    <div class="loyalty-bar"><i id="loyaltyBar"></i></div>
                    @endif
                    <button class="cta buy-cta wide" id="loyaltyRedeem" type="button" hidden><span>{{ ___('Redeem Reward') }}</span></button>
                    <p class="loyalty-ok" id="loyaltyOk" hidden>{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
                </section>
                @endif

                <section class="blk">
                    <h3 class="cv-h3">{{ ___('Quick actions') }}</h3>
                    <div class="prow-list">
                        @if($reservationsEnabled)
                        <button class="prow" id="rowReserve" type="button">
                            <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="3"/><path d="M8 3v4M16 3v4M4 10h16"/></svg>
                            <span class="prow-txt"><b>{{ ___('Reserve a table') }}</b><small class="{{ $myReservation ? '' : 'muted' }}">{{ $myReservation ? ___('Booked for :when', ['when' => \Illuminate\Support\Carbon::parse($myReservation->reserved_at)->format('D, M j · g:i A')]) : '' }}</small></span>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </button>
                        @endif
                        @if($waitlistEnabled)
                        <button class="prow" id="rowWaitlist" type="button">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>
                            <span class="prow-txt"><b>{{ ___('Join the waitlist') }}</b><small class="{{ $myWaitlist ? '' : 'muted' }}">{{ $myWaitlist ? ___('On the list') : '' }}</small></span>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </button>
                        @endif
                        @if($waNumber)
                        <a class="prow" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                            <span class="prow-txt"><b>{{ ___('Order via WhatsApp') }}</b></span>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                        @endif

                        {{-- Share this menu — native profile row (like the WhatsApp .prow),
                             not the floating pill. Gated on the same share.fallback_button
                             toggle; data-web-share makes _partials/share stand down. --}}
                        @if(theme_setting($store->id, 'share.fallback_button'))
                        <button class="prow share-btn" id="mbShareRow" type="button" data-web-share
                                aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg>
                            <span class="prow-txt"><b>{{ ___('Share this menu') }}</b></span>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </button>
                        @endif
                        @if($googleReviewUrl)
                        <a class="prow" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener">
                            <svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>
                            <span class="prow-txt"><b>{{ ___('Rate your experience') }}</b></span>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                        @endif
                    </div>
                </section>

                <section class="pref">
                    <h3 class="cv-h3">{{ ___('Preferences') }}</h3>
                    {{-- Appearance is unconditional: both other rows are gated on
                         multi-language / multi-currency, so on a single-language store
                         this section used to render as a heading over empty space. --}}
                    <div class="pref-row">
                        <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 12H2M22 12h-2.2M6.3 6.3 4.8 4.8M19.2 19.2l-1.5-1.5M17.7 6.3l1.5-1.5M4.8 19.2l1.5-1.5"/></svg>{{ ___('Appearance') }}</span>
                        <div class="seg mini" id="themeSeg" role="radiogroup" aria-label="{{ ___('Appearance') }}">
                            <button type="button" role="radio" data-appearance="auto">{{ ___('Auto') }}</button>
                            <button type="button" role="radio" data-appearance="light">{{ ___('Light') }}</button>
                            <button type="button" role="radio" data-appearance="dark">{{ ___('Dark') }}</button>
                        </div>
                    </div>
                    @if(count($menu_languages ?? []) > 1)
                    <div class="pref-row">
                        <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>{{ ___('Language') }}</span>
                        <div class="seg mini" id="langSeg">
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
                            @foreach($mcRates as $code => $rate)<button data-cur="{{ $code }}" data-rate="{{ $rate }}" type="button">{{ $code }}</button>@endforeach
                        </div>
                    </div>
                    @endif
                </section>

                <section class="orders-block">
                    <h3 class="cv-h3">{{ ___('My orders') }}</h3>
                    @if($customer)
                        <a class="order-row" href="{{ route('customer.profile', $store->id) }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                            <div><b>{{ ___('Profile & settings') }}</b><small>{{ ___('Details, addresses & preferences') }}</small></div>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                        <a class="order-row" href="{{ route('customer.account', $store->id) }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-1 11.6a2.1 2.1 0 0 1-2.1 1.9H8.7a2.1 2.1 0 0 1-2.1-1.9L5.6 8.4Z"/><path d="M9 10.4V6.9a3 3 0 0 1 6 0v3.5"/></svg>
                            <div><b>{{ ___('View order history') }}</b><small>{{ ___('All your orders in one place') }}</small></div>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                    @else
                        @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
                        <p class="sheet-sub">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
                        <div class="coupon-field" style="border-style:solid">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                            <input aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" id="lookupInput" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" autocomplete="off">
                            <button class="minibtn" id="lookupBtn" type="button">{{ ___('Find') }}</button>
                        </div>
                        <div class="orders-list" id="lookupResults" style="padding-top:10px"></div>
                        <div class="grid-empty sm" id="lookupEmpty">
                            <svg viewBox="0 0 64 64" class="empty-art"><path d="M18 8h28v48l-5-4-4.7 4-4.6-4-4.7 4-4.6-4-4.4 4V8Z"/><path d="M25 21h14M25 30h14M25 39h9"/></svg>
                            <p>{{ ___('Your past orders will show up here.') }}</p>
                        </div>
                    @endif
                </section>

                {{-- Privacy: wipe the cart / favourites / saved addresses this browser
                     keeps for this store (shared window.mbClearDeviceData engine). Placed
                     in the profile view because it is guest-reachable via the bottom nav
                     (not login-gated), beside the other account rows. --}}
                <section class="blk vl-device-data">
                    <button class="ghostbtn wide vl-cleardata" id="vlClearData" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5a2 2 0 0 1 2-2h0a2 2 0 0 1 2 2v2M7 7l1 12a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2l1-12M10 11v6M14 11v6"/></svg>
                        <span>{{ ___('Clear saved data on this device') }}</span>
                    </button>
                    <p class="vl-device-data-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
                </section>

                <div class="biz-card">
                    @if($store->address)<span><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</span>@endif
                    @if($store->phone)<span><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><a href="tel:{{ $store->phone }}">{{ $store->phone }}</a></span>@endif
                </div>
                <p class="store-foot">{{ $store->title }}</p>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- Catering & Events now lives in #cvCateringSheet (opened from the header
             button / condensed proxy); the form is included there with
             cateringInline=true so it stays in the sheet, not at the menu bottom. --}}

    </main>

    {{-- Notification-center panel (shared partial; bell lives in the home header). --}}
    @include('store_templates._partials.notif-bell')

    {{-- ══════════ BOTTOM NAV ══════════ --}}
    <nav class="navbar" id="navbar" aria-label="{{ ___('Main') }}">
        <span class="nav-glider" id="navGlider" aria-hidden="true"></span>
        <button class="nav-tab is-on" data-tab="home" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg>
            <span class="nt-label">{{ ___('Home') }}</span>
        </button>
        <button class="nav-tab" data-tab="menu" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            <span class="nt-label">{{ ___('Menu') }}</span>
        </button>
        @if(theme_setting($store->id, 'search.enabled', true))
        <button class="nav-tab" data-tab="search" type="button">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg>
            <span class="nt-label">{{ ___('Search') }}</span>
        </button>
        @endif
        @if($allow_order)
        <button class="nav-tab" data-tab="cart" type="button">
            <span class="nt-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-1 11.6a2.1 2.1 0 0 1-2.1 1.9H8.7a2.1 2.1 0 0 1-2.1-1.9L5.6 8.4Z"/><path d="M9 10.4V6.9a3 3 0 0 1 6 0v3.5"/></svg><i class="bagcount nav-badge" hidden>0</i></span>
            <span class="nt-label">{{ ___('Cart') }}</span>
        </button>
        @endif
        <button class="nav-tab" data-tab="favs" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
            <span class="nt-label">{{ ___('Saved') }}</span>
        </button>
        <button class="nav-tab" data-tab="profile" type="button">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
            <span class="nt-label">{{ ___('Profile') }}</span>
        </button>
    </nav>

    {{-- ══════════ SEARCH OVERLAY ══════════ --}}
    {{-- Customizer v2: same `search.enabled` switch as the search bar above — with the
         bar gone nothing can open this, so it is dropped rather than left dormant. --}}
    @if(theme_setting($store->id, 'search.enabled', true))
    <div class="search-overlay" id="searchOverlay" hidden>
        <div class="so-head">
            <button class="cbtn" id="soBack" type="button" aria-label="{{ ___('Close') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
            <label class="searchbar so-bar">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input aria-label="{{ ___('Search here') }}" id="soInput" type="search" placeholder="{{ ___('Search here') }}…" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
            </label>
        </div>
        <h4 class="so-title">{{ ___('Popular searches') }}</h4>
        <div class="chiprow so-chips" id="soChips"></div>
        <div class="so-results" id="soResults"></div>
        <div class="grid-empty" id="soEmpty" hidden>
            <svg viewBox="0 0 64 64" class="empty-art"><circle cx="28" cy="28" r="16"/><path d="m40 40 10 10"/></svg>
            <p>{{ ___('No dishes match your search') }}</p>
        </div>
    </div>
    @endif

    {{-- ══════════ STORY VIEWER ══════════ --}}
    <div class="story-viewer" id="storyViewer" hidden>
        <div class="sv-bars" id="svBars"></div>
        <button class="sv-x" id="svClose" type="button" aria-label="{{ ___('Close') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        <div class="sv-stage" id="svStage">
            {{-- no src: script.js assigns it when a story is opened --}}
            <img class="sv-media" id="svMedia" alt="">
            <button class="sv-tap prev" id="svPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="sv-tap next" id="svNext" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="sv-foot">
                <p class="sv-caption" id="svCaption"></p>
                <a class="buy-cta sv-cta" id="svCta" href="#" target="_blank" rel="noopener" hidden><span>{{ ___('Order now') }}</span></a>
            </div>
        </div>
    </div>

    {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
    <div class="scrim" id="scrim" hidden></div>

    @if(storefront_catering_enabled($store->id))
    <div class="sheet" id="cvCateringSheet" hidden>
        <div class="sheet-grab"></div>
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
    </div>
    @endif

    <div class="sheet" id="menuSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Menu') }}</h2>
        <div class="prow-list">
            @if($loyaltyProgram && $customer)
            <button class="prow" data-menu="loyalty" type="button">
                <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="8" width="16" height="12" rx="2.5"/><path d="M12 8v12M4 12h16M8 8c-2 0-3-1.2-3-2.5S6.2 3 7.5 3C9.5 3 12 5 12 8c0-3 2.5-5 4.5-5C17.8 3 19 4.2 19 5.5S18 8 16 8"/></svg>
                <span class="prow-txt"><b>{{ ___('Loyalty & rewards') }}</b></span>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </button>
            @endif
            @if($reservationsEnabled)
            <button class="prow" data-menu="reserve" type="button">
                <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="3"/><path d="M8 3v4M16 3v4M4 10h16"/></svg>
                <span class="prow-txt"><b>{{ ___('Reserve a table') }}</b></span>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </button>
            @endif
            @if($waitlistEnabled)
            <button class="prow" data-menu="waitlist" type="button">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>
                <span class="prow-txt"><b>{{ ___('Join the waitlist') }}</b></span>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </button>
            @endif
            @if($showBranches)
            <button class="prow" data-menu="branch" type="button">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                <span class="prow-txt"><b>{{ ___('Choose branch') }}</b></span>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </button>
            @endif
            @if($waNumber)
            <a class="prow" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                <span class="prow-txt"><b>{{ ___('Order via WhatsApp') }}</b></span>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            @endif
        </div>
    </div>

    @if($allowDietary)
    <div class="sheet" id="filterSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Filters') }}</h2>
        <h4>{{ ___('Sort by') }}</h4>
        <div class="chiprow" id="sortRow">
            <button data-sort="featured" class="is-on" type="button">{{ ___('Featured') }}</button>
            <button data-sort="price_asc" type="button">{{ ___('Price: low to high') }}</button>
            <button data-sort="price_desc" type="button">{{ ___('Price: high to low') }}</button>
            <button data-sort="pop" type="button">{{ ___('Most popular') }}</button>
        </div>
        <h4>{{ ___('Dietary') }}</h4>
        <div class="chiprow" id="dietRow">
            <button data-diet="vegan" type="button"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg> {{ ___('Vegan') }}</button>
            <button data-diet="halal" type="button">{{ ___('Halal') }}</button>
            <button data-diet="gluten_free" type="button">{{ ___('Gluten-free') }}</button>
        </div>
        @if(count($allergies ?? []))
        <h4>{{ ___('Exclude allergens') }}</h4>
        <div class="chiprow" id="allergenRow">@foreach($allergies as $a)<button data-al="{{ $a->id }}" type="button">{{ $a->title }}</button>@endforeach</div>
        @endif
        <h4>{{ ___('Max spice') }}</h4>
        <div class="spice-scale" id="spiceScale">
            <button data-spice="3" class="is-on" type="button">🌶🌶🌶</button>
            <button data-spice="2" type="button">🌶🌶</button>
            <button data-spice="1" type="button">🌶</button>
            <button data-spice="0" type="button">{{ ___('Mild') }}</button>
        </div>
        <div class="sheet-actions">
            <button class="ghostbtn" id="filterReset" type="button">{{ ___('Reset') }}</button>
            <button class="buy-cta" id="filterDone" type="button"><span>{{ ___('Show dishes') }}</span><b id="filterCount"></b></button>
        </div>
    </div>
    @endif

    @if($loyaltyProgram && $customer)
    <div class="sheet" id="loyaltySheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Loyalty & rewards') }}</h2>
        <div class="loy-balance"><span class="loy-spark">✦</span><b id="loySheetBal">–</b><span>{{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }}</span></div>
        <p class="sheet-sub">{{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }} · {{ ___('at') }} {{ $loyaltyProgram->reward_threshold }}</p>
        <div class="reward-list">
            <div class="reward">
                <span class="rw-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M4 4h7l9 9-7 7-9-9V4Z"/><circle cx="8.5" cy="8.5" r="1.4"/></svg></span>
                <div><b>{{ $loyaltyProgram->reward_description }}</b><small id="loySheetSub">{{ ___('Keep ordering to unlock') }}</small></div>
                <button class="minibtn" id="loySheetRedeem" type="button" hidden>{{ ___('Redeem') }}</button>
            </div>
        </div>
    </div>
    @endif

    @if($showBranches)
    <div class="sheet" id="branchSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Choose branch') }}</h2>
        <button class="ghostbtn wide" id="nearestBranchBtn" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <span>{{ ___('Find nearest branch on map') }}</span>
        </button>
        <div class="branch-list">
            @foreach($branches as $branch)
                <a class="branch-row {{ ($activeBranch->id ?? null) === $branch->id ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    <b>{{ $branch->name }}</b><span class="branch-check"></span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- nearest-branch map picker (self-hosted Leaflet) --}}
    <div class="sheet" id="branchMapSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Nearest branch') }}</h2>
        <div class="branchmap" id="branchMap"></div>
        <p class="geo-attrib" id="branchMapAttrib"></p>
        <div class="branch-list" id="branchGeoList"></div>
    </div>
    @endif

    {{-- ══════════ ADDRESS MAP PICKER SHEET ══════════
         velora-native bottom sheet (.sheet + the shared #scrim) driven by the shared
         window.mbMapPicker engine; fills the same #custAddr the checkout submits, plus
         the hidden #custLat / #custLng coords. Mirrors the #branchMapSheet chrome. --}}
    <div class="sheet vl-map-sheet" id="vlMapSheet" hidden role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}">
        <div class="sheet-grab"></div>
        <h2>{{ ___('Choose on map') }}</h2>
        <div class="vl-map-canvas" id="vlMapCanvas"></div>
        <p class="vl-map-preview" id="vlMapPreview">{{ ___('Move the map to place the pin') }}</p>
        <button class="buy-cta wide" id="vlMapConfirm" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <span>{{ ___('Use this location') }}</span>
        </button>
    </div>

    @if(count($menu_languages ?? []) > 1)
    <div class="sheet" id="langSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Language') }}</h2>
        <div class="branch-list">
            @foreach($menu_languages as $language)
                <button class="branch-row {{ get_lang() === $language->code ? 'is-on' : '' }}" data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" type="button"><b>{{ $language->name }}</b><span class="branch-check"></span></button>
            @endforeach
        </div>
    </div>
    @endif

    @if($authMode !== 'guest_only')
    <div class="sheet" id="authSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Login') }} / {{ ___('Register') }}</h2>
        <div class="auth-tabs" id="authTabs">
            <button data-auth-tab="login" class="is-on" type="button">{{ ___('Login') }}</button>
            <button data-auth-tab="otp" type="button">{{ ___('Phone OTP') }}</button>
            <button data-auth-tab="register" type="button">{{ ___('Register') }}</button>
        </div>
        <p class="err-box" id="authErr" hidden style="margin:0 0 10px"></p>
        <div class="auth-form" data-auth="login">
            <input aria-label="{{ ___('Email') }}" id="loginEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Password') }}" id="loginPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="current-password">
            <button class="buy-cta wide" id="loginBtn" type="button"><span>{{ ___('Login') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
            @include('store_templates.partials.passkey-login', ['store' => $store])
        </div>
        <div class="auth-form" data-auth="otp" hidden>
            <div id="otpStep1">
                <p class="sheet-sub">{{ ___("We'll text you a one-time code") }}</p>
                <input aria-label="{{ ___('Phone number') }}" id="otpPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel">
                <button class="buy-cta wide" id="otpSend" type="button" style="margin-top:11px"><span>{{ ___('Send code') }}</span></button>
            </div>
            <div id="otpStep2" hidden>
                <p class="sheet-sub">{{ ___('Enter the code we sent you') }}</p>
                <div class="otp-boxes" id="otpBoxes"><input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric"></div>
                <button class="buy-cta wide" id="otpVerify" type="button" style="margin-top:11px"><span>{{ ___('Verify') }}</span></button>
            </div>
        </div>
        <div class="auth-form" data-auth="register" hidden>
            <input aria-label="{{ ___('Your name') }}" id="regName" placeholder="{{ ___('Your name') }}" autocomplete="name">
            <input aria-label="{{ ___('Email') }}" id="regEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Phone number') }}" id="regPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
            <input aria-label="{{ ___('Password') }}" id="regPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
            <input aria-label="{{ ___('Confirm Password') }}" id="regPass2" type="password" placeholder="{{ ___('Confirm Password') }}" autocomplete="new-password">
            <button class="buy-cta wide" id="registerBtn" type="button"><span>{{ ___('Register') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
        </div>
    </div>
    @endif

    @if($reservationsEnabled)
    <div class="sheet" id="reserveSheet" hidden>
        <div class="sheet-grab"></div>
        {{-- Explicit close (carries sheet-grab for the existing close wiring). --}}
        <button class="sheet-grab sheet-x" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2>{{ ___('Reserve a table') }}</h2>
        @if(session('reservation_success'))
            <div class="sheet-done"><span class="sheet-done-emoji">📅</span><b>{{ ___('Request received!') }}</b><p>{{ session('reservation_success') }}</p><p class="muted">{{ ___('We\'ll confirm your booking shortly.') }}</p></div>
        @else
            <form class="sheet-form" action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                @csrf
                <span class="field-label">{{ ___('Your name') }}</span><input aria-label="{{ ___('Your name') }}" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}">
                <span class="field-label">{{ ___('Phone number') }}</span><input aria-label="{{ ___('Phone number') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}">
                <span class="field-label">{{ ___('Date & Time') }}</span><input aria-label="{{ ___('Date & Time') }}" name="reserved_at" type="datetime-local" required min="{{ now()->format('Y-m-d\TH:i') }}">
                <span class="field-label">{{ ___('Party Size') }}</span><input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                <span class="field-label">{{ ___('Note') }} ({{ ___('optional') }})</span><textarea aria-label="{{ ___('Any special requests?') }}" name="notes" rows="2" maxlength="500" placeholder="{{ ___('Any special requests?') }}"></textarea>
                @include('store_templates._partials.voice-note-recorder')
                @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                <button class="buy-cta wide" type="submit" style="margin-top:14px"><span>{{ ___('Request Reservation') }}</span></button>
            </form>
        @endif
    </div>
    @endif

    @if($waitlistEnabled)
    <div class="sheet" id="waitlistSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Join the waitlist') }}</h2>
        @if(session('waitlist_joined'))
            <div class="sheet-done"><span class="sheet-done-emoji">🎉</span><b>{{ ___("You're on the list!") }}</b><p>{{ ___('Position :pos · ~:min min wait', ['pos' => session('waitlist_joined')['position'], 'min' => session('waitlist_joined')['wait_minutes']]) }}</p></div>
        @else
            <form class="sheet-form" action="{{ route('publicView.waitlist.join', $store->slug) }}" method="POST">
                @csrf
                <span class="field-label">{{ ___('Your name') }}</span><input aria-label="{{ ___('Your name') }}" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}">
                <span class="field-label">{{ ___('Phone number') }} ({{ ___('optional') }})</span><input aria-label="{{ ___('Phone number') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}">
                <span class="field-label">{{ ___('Party Size') }}</span><input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                <button class="buy-cta wide" type="submit" style="margin-top:14px"><span>{{ ___('Join Waitlist') }}</span></button>
            </form>
        @endif
    </div>
    @endif

    <div class="toast" id="toast" role="status"></div>
    <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.VELORA = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        restaurantName: @json($store->title),
        logo:           @json($logoUrl),
        currencySign:   @json($curSign),
        currencyGap:    @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:    @json($curPos),
        currencyDecimals: @json(currency_decimals()),
        moneyDecimals: @json(money_decimals()), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode:   @json(currency_decimals_map()),
        baseCurrency:   @json(config('settings.currency_code', 'USD')),
        currencyRates:  @json($mcRates),
        taxCharge:      @json($taxCharge),
        {{-- 'fixed' = a flat amount, not a rate. Order::computeTax() honours both;
             a theme that always multiplies bills a flat-tax store a percentage. --}}
        taxType:        @json((string) (@$postOptions->restaurant_tax_type ?: 'percentage')),
        taxLabel:       @json($taxLabel),
        deliveryCharge: @json($deliveryCharge),
        minOrderValue:  @json($minOrderValue),
        allowOrder:     @json((bool) $allow_order),
        allowPayment:   @json((bool) $allow_payment),
        allowCallWaiter:@json((bool) ($allowWaiter && $allow_on_table)),
        allowScheduling:@json((bool) $allowOrderSched),
        {{-- Read the client-computed open state (mb:store-open) when available so a
             cached page never reports a stale open/closed; falls back to the server
             value before the layout script runs. --}}
        get storeOpen() { return (typeof window !== 'undefined' && typeof window.MB_STORE_OPEN === 'boolean') ? window.MB_STORE_OPEN : @json((bool) $isOpen); },
        prepEta:        @json($prepEtaLine),
        forceTable:     @json((bool) $table),
        defaultTable:   @json($table->table_no ?? null),
        authMode:       @json($authMode),
        isLoggedIn:     @json((bool) $customer),
        customerName:   @json($customer->name ?? null),
        customerToken:  @json($customerApiToken),
        waNumber:       @json($waNumber),
        googleReviewUrl:@json($googleReviewUrl),
        csrf:           @json(csrf_token()),
        loyaltyType:      @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json((int) ($loyaltyProgram->reward_threshold ?? 0)),
        openResSheet:      @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        items:          @json((object) $jsItems),
        categories:     @json($jsCats),
        subCats:        @json((object) $jsSubCats),
        banners:        @json($jsBanners),
        leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
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
            login:       @json(route('customer.login', $store->id)),
            register:    @json(route('customer.register', $store->id)),
            logout:      @json(route('customer.logout', $store->id)),
            otpRequest:  @json(route('customer.otp.request', $store->id)),
            otpVerify:   @json(route('customer.otp.verify', $store->id)),
            loyaltyBalance: @json($loyaltyProgram ? route('publicView.loyalty.balance', $store->id) : null),
            loyaltyRedeem:  @json($loyaltyProgram ? route('publicView.loyalty.redeem', $store->id) : null),
            branchesGeo:    @json($showBranches ? route('geo.branches', $store->slug) : null),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:      @json(route('geo.reverse')),
        },
        i18n: {
            popular:       @json(___('Popular')),
            new_badge:     @json(___('New')),
            daily_special: @json(___('Daily Special')),
            sold_out:      @json(___('Sold out')),
            not_available: @json(___('Not available now')),
            starting_from: @json(___('From')),
            added:         @json(___('Added to bag')),
            removed:       @json(___('Removed')),
            remove:        @json(___('Remove')),
            options:       @json(___('Options')),
            qty:           @json(___('Qty')),
            choose:        @json(___('Choose an option')),
            required:      @json(___('Required')),
            optional:      @json(___('Optional')),
            extras:        @json(___('Extras')),
            description:   @json(___('Description')),
            open_now:      @json(___('Open now')),
            closed:        @json(___('Closed')),
            subtotal:      @json(___('Subtotal')),
            discount:      @json(___('Discount')),
            delivery:      @json(___('Delivery fee')),
            tip:           @json(___('Tip')),
            total:         @json(___('Total')),
            min_order:     @json(___('Minimum order')),
            free:          @json(___('Free')),
            select_type:   @json(___('Please choose an order type')),
            fill_contact:  @json(___('Add your name & phone first')),
            fill_addr:     @json(___('Add a delivery address in the cart')),
            coupon_ok:     @json(___('Coupon applied — you save')),
            coupon_bad:    @json(___('That code doesn’t work')),
            waiter_ok:     @json(___('A waiter is on the way')),
            bill_ok:       @json(___('Bill requested — one moment')),
            wa_open:       @json(___('Opening WhatsApp…')),
            thanks:        @json(___('Thanks!')),
            rate_dish:     @json(___('Rate this dish')),
            choose_table:  @json(___('Pick your table number')),
            table:         @json(___('Table')),
            {{-- V-phase: these three cart hints were the SAME invented-window defect
                 the four eta_* strings below were fixed for, one screen earlier. The
                 cart promised "Delivered in 30–45 min" and "Ready for pickup in ~15 min"
                 on a store whose configured window is 25-35, contradicting both the
                 confirmation screen and the track page. Use the store's own window
                 when it has one; otherwise state the fact without inventing a time. --}}
            pickup_hint:   @json($prepEtaLine ? ___('Ready for pickup in :eta', ['eta' => $prepEtaLine]) : ___('Ready for pickup')),
            delivery_hint: @json($prepEtaLine ? ___('Delivered to your address in :eta', ['eta' => $prepEtaLine]) : ___('Delivered to your address')),
            room_hint:     @json(___('Delivered to your room')),
            addr_ph:       @json(___('Street, building, floor…')),
            {{-- These were four HARDCODED windows. On a store whose prep ETA is
                 "10-15" the confirmation still promised "Arrives in 25–35 min" —
                 a number invented by the theme and contradicted by the track page.
                 When the store has configured a window, use it; otherwise say
                 nothing rather than make one up. --}}
            eta_delivery:  @json($prepEtaLine ? ___('Arrives in :eta', ['eta' => $prepEtaLine]) : ''),
            eta_takeaway:  @json($prepEtaLine ? ___('Ready in :eta', ['eta' => $prepEtaLine]) : ''),
            eta_on_table:  @json($prepEtaLine ? ___('At your table in :eta', ['eta' => $prepEtaLine]) : ''),
            eta_room_service: @json($prepEtaLine ? ___('At your room in :eta', ['eta' => $prepEtaLine]) : ''),
            popular_search:@json(___('Popular searches')),
            search_empty:  @json(___('No dishes match your search')),
            new_arrivals:  @json(___('New Arrivals')),
            all:           @json(___('All')),
            clear:         @json(___('Clear')),
            tap_again:     @json(___('Tap again')),
            cart_cleared:  @json(___('Bag emptied')),
            paid_total:    @json(___('Total')),
            order_failed:  @json(___('We couldn’t place that order. Please try again.')),
            order_failed_check: @json(___('Something went wrong on our side. Check your orders before trying again so you are not charged twice.')),
            network_error: @json(___('Network error. Please try again.')),
            no_orders_yet: @json(___('Your past orders will show up here.')),
            appearance:    @json(___('Appearance')),
            auto:          @json(___('Auto')),
            light:         @json(___('Light')),
            dark:          @json(___('Dark')),
            order_label:     @json(___('Order')),
            loc_unavailable: @json(___('Location unavailable')),
            track_order:     @json(___('Track Order')),
            back_to_menu:    @json(___('Back to menu')),
            use_my_location: @json(___('Use my location')),
            your_order:      @json(___('Your order')),
            edit:            @json(___('Edit')),
            note_add:        @json(___('Add note')),
            note_edit:       @json(___('Edit note')),
            note_ph:         @json(___('No onions please…')),
            reviews_title:   @json(___('Reviews')),
            reviews_empty:   @json(___('No reviews yet.')),
            loc_denied:      @json(___('Location denied')),
            no_address:      @json(___('No address found')),
        },
    };
</script>
{{-- self-hosted Leaflet — must define L before script.js runs (this stack renders
     ahead of the layout's script.js tag) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
{{-- script.js is loaded once by store_templates.layout (avoids double-load) --}}
@endpush

@push('scripts_at_bottom')
{{-- Address map picker + clear-saved-data wiring. velora is a shared-layout theme,
     so window.mbMapPicker / window.mbClearDeviceData are already global (the layout
     includes the engines) — we only drive them with velora's own sheet + rows. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var trigger = document.getElementById('vlAddrMapBtn');
    if (!window.mbMapPicker) { if (trigger) { trigger.style.display = 'none'; } return; }
    var sheet = document.getElementById('vlMapSheet');
    var scrim = document.getElementById('scrim');
    var hideTimer = null;
    /* Drive velora's native bottom sheet + the shared scrim (mirrors the theme's own
       sheet() open/close: toggle .is-open on both, slide out, then hide). The theme's
       global scrim / .sheet-grab / Escape handlers also close this sheet because it
       carries .sheet + .sheet-grab, so every close path stays native. */
    function toggle(open) {
        if (!sheet || !scrim) { return; }
        if (open) {
            clearTimeout(hideTimer);
            sheet.hidden = false; scrim.hidden = false;
            requestAnimationFrame(function () { sheet.classList.add('is-open'); scrim.classList.add('is-open'); });
        } else {
            sheet.classList.remove('is-open'); scrim.classList.remove('is-open');
            clearTimeout(hideTimer);
            hideTimer = setTimeout(function () { sheet.hidden = true; scrim.hidden = true; }, 420);
        }
    }
    window.mbMapPicker({
        trigger: trigger,
        modal:   sheet,
        map:     document.getElementById('vlMapCanvas'),
        input:   document.getElementById('custAddr'),
        lat:     document.getElementById('custLat'),
        lng:     document.getElementById('custLng'),
        preview: document.getElementById('vlMapPreview'),
        confirm: document.getElementById('vlMapConfirm'),
        toggle:  toggle,
        i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
    });
})();
(function () {
    var btn = document.getElementById('vlClearData');
    if (!btn) { return; }
    var wrap = btn.closest('.vl-device-data');
    if (!window.mbClearDeviceData) { if (wrap) { wrap.style.display = 'none'; } return; }
    var slug = @json($store->slug ?? '');
    btn.addEventListener('click', function () {
        window.mbClearDeviceData({
            keys: [
                'mb-cart-' + slug,
                'cv-favs-' + slug,
                'velora_recent_addrs_' + slug,
                'velora_recent_orders_' + slug,
                'cv_reorder_' + slug
            ],
            slug: slug,
            storeId: @json($store->id ?? ''),
            confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
        });
    });
})();
</script>
@endpush

@push('scripts_at_bottom')
{{-- ZERO-TOUCH A3.1 — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endpush
