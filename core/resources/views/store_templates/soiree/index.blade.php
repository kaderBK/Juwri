{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       soirée — dark drinks-lounge ordering theme · data preparation
       Faithful rebuild of rev/soiree/demo.html wired to real menu data.
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus   = collect($total_menus)->values();
    $items      = $allMenus->values();
    /* Two-tier category nav (primary parent chips + a secondary sub-category chip
       row that swaps per active parent — the Talabat/UberEats pattern; ported from
       crave). menu_categories() is whereNull('parent') (top-level only), but a menu
       item keys off its OWN category_id, which may be a subcategory id. So each JS
       item carries BOTH ids: 'cat' = its real category_id (drives sub-chip filtering)
       and 'root' = its top-level ancestor (drives parent-chip filtering, so a parent
       chip shows every descendant item instead of dropping subcategory items).
       $rootOf maps any category id → its top-level ancestor; $catNameOf keeps every
       category's own name for the item card subtitle + JS search index ('sub'). */
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

    /* helper: human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* JS data map — variants absolute, groups/extras additive (crave/aurora contract) */
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
            'sub'   => $catNameOf[$m->category_id] ?? '',
            /* entities survive strip_tags and Blade re-escapes the '&', so a bare
               strip_tags renders literal '&#1605;…' AND corrupts the JS search index
               (script.js matches against `desc`). Decode, per orbit/feast. */
            'desc'  => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'img'   => media_url('storage/menu/', $m->image, $store->id ?? null),
            'price' => (float) $m->price,
            'type'  => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'=> $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : '',
            'rating_count' => (int) ($m->rating_count ?? 0),
            // Written-review total for the Reviews SECTION badge — rating_count above
            // counts every quick star tap, so the section head read "118" over a list
            // of one written review. rating_count stays for the star-average meta.
            'reviewTotal' => collect($reviewsByMenu->get($m->id, collect()))->count(),
            'reviewList' => \Juwri\Core\Support\ViewHelpers::reviewList($reviewsByMenu->get($m->id, collect())),
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyList($itemAllergies),
            'variants' => $variants,
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
            'prep'             => $m->prep_time ?? ($m->preparation_time ?? null),
            'gallery'          => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryUrls($m->gallery_images, $store->id ?? null)
                : [],
            'optionGroups'     => \Juwri\Core\Support\ViewHelpers::optionGroups($m->globalGroups ?? []),
        ];
    }

    // Primary chips: top-level parents that hold at least one descendant item
    // (pure containers / empty parents drop).
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

    /* JS chip data: each parent carries id + name + its item-bearing subs (parent/sub
       structure for the two-tier browse nav). */
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
    $customerDefaultAddress = $customerAddresses->first();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;

    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */

    /* menu_sections visibility */
    $savedSections = isset($postOptions->menu_sections)
        ? json_decode(json_encode($postOptions->menu_sections), true)
        : null;
    $defaultSections = [
        ['id' => 'hero', 'visible' => true],
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

    $myWaitlist = storefront()->activeWaitlist($store, $customer);
    $myReservation = storefront()->upcomingReservation($store, $customer);

    /* brand / follow row (crave F2 parity): mint a raw CustomerToken for the
       signed-in session customer so the theme JS can call the follow endpoint. */
    $customerApiToken = storefront()->customerApiToken($customer, 'soiree');
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
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Mulish:ital,wght@0,200..900;1,200..900&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- self-hosted Leaflet (nonce/CSP-safe; script-src is self+nonce). Tiles load as https imgs. --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
@endpush

@section('content')
<div class="soiree" id="app">

    {{-- ══════════ SPLASH ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="splash" id="splash">
        <div class="splash-bubbles" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
        @if($store->main_image && $store->main_image !== 'default.png')
        @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img class="splash-logo" src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
        @endif
        {{-- neutral serving-dome line art (was a cocktail coupe — soiree ships to
             restaurants, not bars). Same stroke-draw animation, same visual language. --}}
        <svg class="splash-coupe" viewBox="0 0 200 176" aria-hidden="true">
            <path class="sc-line" d="M32 118a68 68 0 0 1 136 0Z" pathLength="1"/>
            <path class="sc-line" d="M18 118h164" pathLength="1"/>
            <path class="sc-line" d="M100 50V38" pathLength="1"/>
            <path class="sc-line" d="M44 140h112" pathLength="1"/>
            <circle class="sc-dot" cx="100" cy="32" r="4.5"/>
            <circle class="sc-dot d2" cx="74" cy="24" r="3"/>
            <circle class="sc-dot d3" cx="126" cy="20" r="2.5"/>
        </svg>
        <h1 class="splash-brand">{{ $store->title }}<span>.</span></h1>
        <p class="splash-tag" data-tc-text="splash.title">{{ theme_setting($store->id, 'splash.title', ___('Order in a few taps')) }}</p>
        <button class="splash-skip" id="splashSkip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="views" id="views">

        {{-- HOME · "Tonight" --}}
        <section class="view is-active" data-view="home">
            @php
                $__soHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
                $soCoverUrl = $__soHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
                $soShowCover = $__soHasCover && theme_setting($store->id, 'brand.show_cover', true);
            @endphp
            {{-- Collapsing header: slides in once the moody cover header leaves the
                 scroll container (soirée's chips are not top-sticky, so no guard). --}}
            <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
                <span class="cv-condensed-logo">@if(!empty($store->main_image) && $store->main_image !== 'default.png')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif</span>
                <b class="cv-condensed-name">{{ $store->title }}</b>
                <span class="cv-condensed-actions">
                    @if(count($menu_languages ?? []) > 1)
                    <button class="cv-cbtn" type="button" data-cv-proxy="lang" aria-label="{{ ___('Language') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="cv-cbtn" type="button" data-cv-proxy="catering" aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    <button class="cv-cbtn" type="button" data-cv-proxy="notif" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                    </button>
                </span>
            </div>
            <div class="view-scroll">
                @if($soShowCover)
                {{-- Moody cover fading into the plum ground; the store name headline +
                     chrome sit over it (already light). --}}
                <div class="soiree-cover">
                    <div class="soiree-cover-media" aria-hidden="true">
                        @if((string) ($soCoverUrl) !== '')<img class="soiree-cover-img" src="{{ $soCoverUrl }}" alt="" data-csp-error="hide">@endif
                        <span class="soiree-cover-scrim"></span>
                    </div>
                @endif
                <header class="home-top">
                    <div class="tonight">
                        {{-- the store's own name headlines its own storefront (this used to
                             read the literal word "Tonight" on every restaurant). --}}
                        <h1>{{ $store->title }}</h1>
                        <p class="tonight-date" id="tonightDate"></p>
                    </div>
                    {{-- The language picker and Profile & Settings used to live only
                         on the BROWSE header, so a diner who never left home could
                         not switch language or reach their profile. Same `.ph-actions`
                         row, `.seg.mini` and `.avatarbtn` the rest of the theme uses,
                         so it reads as one design rather than a bolt-on. --}}
                    <div class="ph-actions">
                        @if($allow_order)
                        <button class="cartchip" data-go="cart" type="button" aria-label="{{ ___('Your order') }}">
                            <span class="cc-ic">
                                <svg class="ic" viewBox="0 0 24 24"><path d="M21 3 3 10.4l6.4 2.7L12 20l2.6-5.4L21 3Z"/><path d="M21 3 9.4 13.1"/></svg>
                                <i class="badge cc-badge" hidden>0</i>
                            </span>
                            <span class="cc-col"><b class="cc-total">{{ store_money_format(0, $store->id) }}</b><small>{{ ___('Total Price') }}</small></span>
                        </button>
                        @endif
                        @php
                            /* One control at every store size: a globe carrying the active
                               code, opening the language sheet. Stores run anywhere from two
                               to eleven languages, and a single affordance means the header
                               never reflows and there is only one layout to reason about. */
                            $__soLangs = collect($menu_languages ?? []);
                            $__soLangSheet = $__soLangs->count() > 1;
                        @endphp
                        @if($__soLangSheet)
                        <button class="langbtn-top" id="homeLangBtn" type="button"
                                aria-label="{{ ___('Language') }}" aria-haspopup="dialog">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                            <b>{{ strtoupper(get_lang()) }}</b>
                        </button>
                        @endif
                        @if(storefront_catering_enabled($store->id))
                        <button class="cv-cbtn" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                        </button>
                        @endif
                        {{-- Shared notification-center bell (rev/notif-center-spec.md); panel + JS from _partials/notif-bell. --}}
                        <button class="avatarbtn" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                            <i id="mbNotifDot" hidden></i>
                        </button>
                        <style nonce="{{ csp_nonce() }}">
                            .soiree #mbNotifBell{position:relative;overflow:visible}
                            .soiree #mbNotifDot{position:absolute;top:7px;inset-inline-end:7px;width:9px;height:9px;border-radius:50%;background:var(--hi);outline:2px solid var(--card)}
                            .soiree #mbNotifDot[hidden]{display:none}
                        </style>
                    </div>
                </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


                <div class="status-strip">
                    @if($showBranches)
                    {{-- crave-parity branch selector: active branch + chevron, opens the branch sheet --}}
                    <button class="loc" type="button" data-csp-open-sheet="mbBranchSheet" data-csp-scrim="scrim" aria-label="{{ ___('Choose branch') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <b>{{ $activeBranch->name ?? $branches->first()->name ?? $store->title }}</b>
                        <svg class="ic chev" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    @endif
                    <span class="open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                    @if(!empty($scans_today) && $scans_today > 0)
                        <span class="viewers"><b>{{ $scans_today }}</b> {{ ___('scans today') }}</span>
                    @endif
                </div>
                @if($soShowCover)
                </div>{{-- /.soiree-cover --}}
                @endif

                {{-- brand / follow row (crave F2 parity): store mark, name + verified
                     badge, follower proof line, and a follow button. --}}
                @if($sectionVisible['brand_row'] ?? true)
                <div class="brand-row{{ $soShowCover ? ' brand-row--hero' : '' }}" id="soBrandRow" data-mb-hotel-chip-slot>
                    <span class="brand-logo">
                        @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                    </span>
                    <span class="brand-info">
                        <b class="brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="verified-badge" id="soVerifiedBadge" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
                        @if(trim((string)($store->sub_title ?? '')) !== '')
                        <span class="brand-tagline">{{ $store->sub_title }}</span>
                        @endif
                        @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true), 'countId' => 'soFollowerCount'])
                    </span>
                    {{-- Following needs a customer account: on a guest_only store the auth
                         sheet is never rendered, so a guest tap opens nothing to sign into.
                         Hide the control rather than ship a button that cannot do anything. --}}
                    @if($authMode !== 'guest_only' || $customer)
                    <button class="followbtn {{ $isFollowing ? 'is-on' : '' }}" id="soFollowBtn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
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
                <div class="so-reviews" id="soReviews" data-mb-reviews-end>
                    <div class="so-rating"><span class="so-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
                    @if($aboutReviews->isNotEmpty())
                    <div class="so-review-list">
                        @foreach($aboutReviews as $rev)
                        <div class="so-review">
                            <div class="so-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="so-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                            @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endif

                <nav class="hometabs" id="homeTabs" aria-label="{{ ___('Home tabs') }}">
                    <button class="htab is-on" data-htab="promo" type="button">{{ ___('Promotion') }}</button>
                    @if($loyaltyProgram)
                    <button class="htab" data-htab="free" type="button">{{ ___('Rewards') }}</button>
                    @endif
                    <button class="htab" data-htab="happy" type="button" id="happyTab" hidden>{{ ___('Featured') }}</button>
                </nav>

                {{-- panel · PROMOTION --}}
                <div class="hpanel is-on" data-hpanel="promo">
                    {{-- stories rail — JS-populated from the public stories feed. Stays
                         hidden (and contributes no gap) when the feed is empty/errors. --}}
                    <div class="storyrail" id="storyRail" hidden></div>

                    @if($sectionVisible['hero'])
                    <div class="hero-wrap">
                        <div class="htrack" id="heroTrack"></div>
                    </div>
                    @endif

                    @if($sectionVisible['daily_specials'])
                    {{-- Filled and unhidden by script.js (renderDaily); hidden until then so it is never an empty tab stop. --}}
                    <button class="daily-card" id="dailyCard" type="button" hidden></button>
                    @endif

                    {{-- banners (admin-managed) --}}
                    @if(!empty($menuBanners) && count($menuBanners))
                    <div class="banners">
                        <div class="banner-track" id="bannerTrack">
                            @foreach($menuBanners as $bi => $banner)
                                <a class="banner" href="{{ storefront_localized_link($banner->link_url) ?: '#' }}" @if($banner->link_url) target="_blank" rel="noopener" @endif>
                                    <span class="banner-bg"></span>
                                    <span class="banner-copy">
                                        @if($banner->subtitle)<span class="banner-kicker">{{ $banner->subtitle }}</span>@endif
                                        <span class="banner-title">{{ $banner->title }}</span>
                                        <span class="banner-btn">{{ ___('Order now') }}</span>
                                    </span>
                                    @if($banner->image)
                                        @if((string) ($banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null)) !== '')<img class="banner-img" src="{{ $banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null) }}" alt="">@endif
                                    @endif
                                </a>
                            @endforeach
                        </div>
                        <div class="banner-dots" id="bannerDots"></div>
                    </div>
                    @endif

                    @if($sectionVisible['menu'])
                    <div class="section-head" data-mb-grid-lead>
                        <h2>{{ ___('The menu') }}</h2>
                        <div class="sh-actions">
                            <div class="view-toggle" role="group" aria-label="{{ ___('Layout') }}">
                                <button data-vm="list" type="button" aria-label="{{ ___('List view') }}" aria-pressed="false"><svg class="ic" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg></button>
                                <button data-vm="grid" type="button" aria-label="{{ ___('Grid view') }}" aria-pressed="true"><svg class="ic" viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/></svg></button>
                            </div>
                        </div>
                    </div>
                    @if($sectionVisible['catbar'])
                    {{-- data-mbt-anchor: the shared order-type bar docks directly above these
                         chips instead of following the offers strip up the page. --}}
                    <nav class="catchips" id="catChips" aria-label="{{ ___('Categories') }}" data-mb-grid-lead data-mbt-anchor></nav>
                    @endif
                    <div class="mlist" id="menuList"></div>
                    {{-- The home list is a 12-item preview; the "See all" CTA sits BELOW it so
                         the cut-off is obvious and the diner knows the full menu is one tap away. --}}
                    <button class="menu-more" data-go="browse" type="button">{{ ___('See all') }}<svg class="ic" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
                    @endif

                    @if($reservationsEnabled || $waitlistEnabled)
                    <section class="reserve-card">
                        <span class="rc-art" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg></span>
                        <span class="rc-copy"><b>{{ ___('Book a table') }}</b><small>{{ ___('Reserve your spot') }}</small></span>
                        <button class="rc-btn" id="reserveBtn" type="button">{{ ___('Reserve') }}</button>
                    </section>
                    @endif
                    <div class="res-chips" id="resChips">
                        @if($myReservation)
                        <div class="res-chip"><svg class="ic" viewBox="0 0 24 24"><rect x="4" y="6" width="16" height="14" rx="3"/><path d="M4 10h16M8 4v4M16 4v4"/></svg><b>{{ \Illuminate\Support\Carbon::parse($myReservation->reserved_at)->format('D, M j') }}</b><span>· {{ \Illuminate\Support\Carbon::parse($myReservation->reserved_at)->format('g:i A') }}</span><span class="wl-tag">{{ $myReservation->status === 'confirmed' ? ___('Confirmed') : ___('Requested') }}</span></div>
                        @endif
                        @if($myWaitlist)
                        <div class="res-chip"><svg class="ic" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M2 20a7 7 0 0 1 14 0"/></svg><b>{{ ___('Waitlist') }}</b><span>· {{ ___('party of :n', ['n' => $myWaitlist->party_size]) }}</span><span class="wl-tag">{{ ___('On the list') }}</span></div>
                        @endif
                    </div>

                    <div class="tail-space"></div>
                </div>

                {{-- panel · FREE DRINK (loyalty rewards) --}}
                @if($loyaltyProgram)
                <div class="hpanel" data-hpanel="free">
                    <div class="pts-hero">
                        <b id="ptsBig">0</b><small>{{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }}</small>
                    </div>
                    <div class="pts-bar" aria-hidden="true"><i id="ptsFill"></i></div>
                    <p class="pts-next" id="ptsNext"></p>
                    @if(!$customer)
                        <p class="loyalty-empty">{{ ___('Sign in to see your rewards balance and redeem.') }}</p>
                        @if($authMode !== 'guest_only')
                        <button class="cta redeem-cta" id="loyaltyLogin" type="button"><span>{{ ___('Login') }}</span></button>
                        @endif
                    @else
                        <p class="loyalty-empty" id="loyaltyReward">{{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }}</p>
                        @if($loyaltyProgram->type === 'stamps')
                        <div class="stamp-dots" id="stampDots">
                            @for($s = 0; $s < $loyaltyProgram->reward_threshold; $s++)
                                <span class="stamp-dot" data-idx="{{ $s }}"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m12 3 2.5 5.5 6 .5-4.5 4 1.4 5.9L12 20.8 6.6 18.9 8 13 3.5 9l6-.5L12 3Z"/></svg></span>
                            @endfor
                        </div>
                        @endif
                        <button class="cta redeem-cta" id="loyaltyRedeem" type="button" hidden><span>{{ ___('Redeem Reward') }}</span></button>
                        <p class="loyalty-ok" id="loyaltyOk" hidden>{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
                    @endif
                    <div class="tail-space"></div>
                </div>
                @endif

                {{-- panel · HAPPY HOUR (discounted / popular picks) --}}
                <div class="hpanel" data-hpanel="happy">
                    <div class="hh-banner">
                        <span class="hh-copy"><b>{{ ___('Featured') }}</b><small>{{ ___("Today's featured picks") }}</small></span>
                        <span class="hh-chip">★</span>
                    </div>
                    <p class="hh-sub">{{ ___('Our most-loved dishes right now') }}</p>
                    <div class="mlist as-cards" id="hhList"></div>
                    <div class="tail-space"></div>
                </div>
            </div>
        </section>

        {{-- BROWSE / MENU --}}
        <section class="view" data-view="browse">
            <div class="view-scroll">
                <header class="page-head">
                    <h1>{{ ___('Menu') }}</h1>
                    <div class="ph-actions">
                        @if($allow_order)
                        <button class="cartchip sm" data-go="cart" type="button" aria-label="{{ ___('Your order') }}">
                            <span class="cc-ic">
                                <svg class="ic" viewBox="0 0 24 24"><path d="M21 3 3 10.4l6.4 2.7L12 20l2.6-5.4L21 3Z"/><path d="M21 3 9.4 13.1"/></svg>
                                <i class="badge cc-badge" hidden>0</i>
                            </span>
                            <span class="cc-col"><b class="cc-total">{{ store_money_format(0, $store->id) }}</b><small>{{ ___('Total Price') }}</small></span>
                        </button>
                        @endif
                        <button class="avatarbtn" id="profileBtn" type="button" aria-label="{{ ___('Profile') }}">
                            @if($store->main_image && $store->main_image !== 'default.png')
                            @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="replace-parent-text" data-csp-text="☰">@endif
                            @else
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/></svg>
                            @endif
                        </button>
                    </div>
                </header>
                <div class="seg" id="browseSeg">
                    <button data-bm="menu" class="is-on" type="button">{{ ___('Menu') }}</button>
                    <button data-bm="favs" type="button">{{ ___('Favorites') }}</button>
                </div>
                <div class="chiprow" id="browseTools">
                    @if($allowDietary)
                    <button id="browseFilter" type="button"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 8h14M5 12h10M5 16h6"/></svg>{{ ___('Filters') }}<i class="wl-tag" id="filterDot" hidden></i></button>
                    @endif
                    <div class="view-toggle" role="group" aria-label="{{ ___('Layout') }}">
                        <button data-vm="list" type="button" aria-label="{{ ___('List view') }}" aria-pressed="false"><svg class="ic" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg></button>
                        <button data-vm="grid" type="button" aria-label="{{ ___('Grid view') }}" aria-pressed="true"><svg class="ic" viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/></svg></button>
                    </div>
                </div>
                <nav class="catchips" id="browseChips" aria-label="{{ ___('Categories') }}"></nav>
                {{-- Secondary sub-category row: JS-populated + revealed only for a parent
                     with item-bearing subcategories. "All" (data-sub="root") = every
                     descendant of the active parent. Reuses .catchips styling. --}}
                <nav class="catchips subchips" id="browseSubChips" aria-label="{{ ___('Sub-categories') }}" hidden></nav>
                <div class="mlist" id="browseList"></div>
                <div class="grid-empty" id="browseEmpty" data-favs-copy="{{ ___('Tap the heart on anything to keep it here') }}" hidden>
                    <svg class="empty-art" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
                    <p>{{ ___('Tap the heart on anything to keep it here') }}</p>
                    {{-- a filter that matches nothing used to leave a blank void --}}
                    <button class="ghostbtn" id="browseEmptyClear" type="button" hidden>{{ ___('Clear filters') }}</button>
                </div>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- PRODUCT --}}
        <section class="view view-sub" data-view="product">
            <div class="view-scroll" id="pScroll">
                <div class="p-hero" id="pHero">
                    {{-- no src attribute: an empty src makes the browser refetch the page
                         URL. script.js assigns .src when a product is opened. --}}
                    <div class="p-heroimg"><img id="pHeroImg" alt=""></div>
                    <button class="fab back" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <button class="fab fav" id="pFav" type="button" aria-label="{{ ___('Favorite') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
                    <span class="p-stock" id="pStock" hidden></span>
                    @if($allow_order)
                    {{-- soiree signature: vertical qty column straddling the item hero --}}
                    <div class="qty-col" id="qtyCol" aria-label="{{ ___('Quantity') }}">
                        <button data-q="1" type="button" aria-label="{{ ___('Increase') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
                        <output id="pQtyVal"><span>1</span></output>
                        <button data-q="-1" type="button" aria-label="{{ ___('Decrease') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
                    </div>
                    @endif
                </div>

                <div class="p-body">
                    <h1 class="p-title" id="pTitle"></h1>
                    <p class="p-sub" id="pSub"></p>
                    <p class="p-metaline" id="pMeta"></p>
                    <div class="specs" id="pSpecs"></div>
                    <p class="p-desc" id="pDesc"></p>
                    <div class="p-allergens" id="pAllergens"></div>
                    <div class="p-groups" id="pGroups"></div>
                    @if($allow_order)
                    {{-- a slim, passive unit-price readout stays inline for transparency
                         (base × qty × extras); the ADD action itself lives in the sticky
                         bar below so a diner can never scroll past it. --}}
                    <div class="p-unitline"><b id="pUnit"></b><small>{{ ___('Unit price') }}</small></div>
                    @endif
                    <section class="product-reviews" id="pReviews"></section>
                    <div class="tail-space tall"></div>
                </div>
            </div>

            @if($allow_order)
            {{-- Product view: the sticky bar's job is ADD, not checkout. A full-width
                 "Add to order · <line total>" button (impossible to scroll past), with a
                 compact cart shortcut carrying the running count for a quick jump to the
                 order. Was a Total/Checkout band, which read as the wrong primary action
                 on a page whose whole purpose is adding the item. --}}
            <footer class="order-band pbar">
                <div class="ob-row">
                    <button class="ob-mini" data-go="cart" type="button" aria-label="{{ ___('View order') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>
                        <i class="cc-badge ob-mini-badge" hidden>0</i>
                    </button>
                    <button class="price-add pab" id="pAddBtn" type="button">
                        <span class="pa-ic" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></span>
                        <span class="pa-col"><small>{{ ___('Add to order') }}</small><b id="pLineTotal"></b></span>
                    </button>
                </div>
            </footer>
            @endif
        </section>

        {{-- CART --}}
        <section class="view view-sub" data-view="cart">
            <div class="view-scroll">
                <header class="page-head sub">
                    <button class="fab back inline" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1>{{ ___('Your order') }}</h1>
                    <button class="ghostbtn sm" id="clearCart" type="button">{{ ___('Clear') }}</button>
                </header>

                <div id="cartList" class="cart-list"></div>
                <div class="grid-empty" id="cartEmpty" hidden>
                    <svg class="empty-art" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>
                    <p>{{ ___('Your order is empty') }}</p>
                    <button class="ghostbtn" data-go="home" type="button">{{ ___('Browse the menu') }}</button>
                </div>

                @if($allow_order)
                <div id="cartTools" class="cart-tools">
                    <section class="ordertype">
                        <h3>{{ ___('Order type') }}</h3>
                        <div class="seg" id="orderTypeSeg">
                            @if($allow_on_table)<button data-ot="on-table" type="button" aria-label="{{ ___('Dine-in') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg><span>{{ ___('Dine-in') }}</span></button>@endif
                            @if($allow_takeaway)<button data-ot="takeaway" type="button" aria-label="{{ ___('Pickup') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><span>{{ ___('Pickup') }}</span></button>@endif
                            @if($allow_delivery)<button data-ot="delivery" type="button" aria-label="{{ ___('Delivery') }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg><span>{{ ___('Delivery') }}</span></button>@endif
                            {{-- Hotel in-room dining. $allow_room_service is 1 only when hotel mode is on
                                 AND the scanned QR resolved to an area_type='room' table, so this never
                                 renders for an ordinary restaurant. --}}
                            @if($allow_room_service)<button data-ot="room_service" type="button" aria-label="{{ ___('Room service') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 6v2M10.5 6h3"/></svg><span>{{ ___('Room service') }}</span></button>@endif
                        </div>
                        <div class="ot-fields" id="otFields"></div>
                        @if($allowWaiter && $allow_on_table)
                        <div class="waiter-row" id="waiterRow" hidden>
                            <button class="ghostbtn" id="callWaiter" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span>{{ ___('Call waiter') }}</span></button>
                            <button class="ghostbtn" id="requestBill" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg><span>{{ ___('Request bill') }}</span></button>
                        </div>
                        @endif
                        @if($allowOrderSched)
                        <div class="schedule-row">
                            <span class="sched-label"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ ___('Schedule for later') }}</span>
                            <input aria-label="{{ ___('Schedule for later') }}" type="datetime-local" id="scheduledAt" class="when-time" min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}" max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
                        </div>
                        @endif
                    </section>

                    @if($allowCoupons)
                    <section class="coupon">
                        <label class="coupon-field" id="couponField">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                            <input id="couponInput" placeholder="{{ ___('Promo code') }}" autocapitalize="characters" autocomplete="off" spellcheck="false">
                            <button class="minibtn" id="couponApply" type="button">{{ ___('Apply') }}</button>
                        </label>
                        {{-- F19: an applied coupon is a removable chip, not a one-way field --}}
                        <div class="coupon-applied" id="couponChip" hidden>
                            <b id="couponChipCode"></b>
                            <small id="couponChipAmt"></small>
                            <button class="coupon-clear" id="couponClear" type="button" aria-label="{{ ___('Remove') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                        </div>
                        <p class="coupon-msg" id="couponMsg" hidden></p>
                    </section>
                    @endif

                    <section class="ordernote">
                        <label><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5V4Z"/><path d="M15 4v4h4M8.5 12h7M8.5 16h5"/></svg>{{ ___('Note') }} ({{ ___('optional') }})</label>
                        <textarea aria-label="{{ ___('Any special requests?') }}" id="orderNote" rows="2" placeholder="{{ ___('Any special requests?') }}"></textarea>
                        @include('store_templates._partials.voice-note-recorder')
                    </section>

                    <section class="totals" id="totalsBox"></section>
                    <p class="minorder" id="minOrderMsg" hidden></p>

                    @if($waNumber)
                    <a class="wa-line" id="waOrder" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
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
                </div>
                @endif
                <div class="tail-space tall"></div>
            </div>

            @if($allow_order)
            <footer class="order-band" id="cartBand" hidden>
                <small class="ob-label">{{ ___('Total Order') }}</small>
                <div class="ob-row">
                    <span class="ob-cells">
                        <span class="ob-cell"><span class="ob-art" aria-hidden="true"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg></span><span class="ob-col"><b class="band-count">0</b><small>{{ ___('Items') }}</small></span></span>
                        <span class="ob-cell"><span class="ob-col"><b class="band-total">{{ store_money_format(0, $store->id) }}</b><small>{{ ___('Total Price') }}</small></span></span>
                    </span>
                    <button class="paytile" data-paytile type="button" aria-label="{{ ___('Checkout') }}">
                        @if($allow_payment)
                        <span class="mc" aria-hidden="true"><i></i><i></i></span>
                        @else
                        <svg class="ic pt-arr" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        @endif
                        <span class="pt-label">{{ ___('Checkout') }}</span>
                    </button>
                </div>
            </footer>
            @endif
        </section>

        {{-- CHECKOUT --}}
        <section class="view view-sub" data-view="checkout">
            <div class="view-scroll">
                <header class="page-head sub">
                    <button class="fab back inline" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1>{{ ___('Checkout') }}</h1>
                </header>

                <section class="contact">
                    <h3>{{ ___('Your details') }}</h3>
                    <input aria-label="{{ ___('Your name') }}" id="custName" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}" autocomplete="name">
                    <div id="phoneField">
                        <input aria-label="{{ ___('Phone') }}" id="custPhone" type="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}" inputmode="tel" autocomplete="tel">
                    </div>
                    {{-- F17: the cart already asks for the table. This is the same value, so
                         it is a read-only echo of what was entered there (script.js mirrors
                         it) — never a second, contradictory field to fill in. --}}
                    <div id="tableField" class="is-hidden">
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

                <section class="addr" id="addrBlock" hidden>
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
                    @elseif(!$customer)
                    {{-- R6: guests have no server address book, so their last-typed
                         addresses are remembered on-device and painted here as
                         tap-to-fill chips by script.js (renderRecentAddrs). --}}
                    <div class="saved-addrs" id="savedAddrs" hidden></div>
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
                    {{-- Drop-a-pin address picker (shared window.mbMapPicker engine, soirée-skinned sheet below). --}}
                    <button class="ghostbtn block so-map-trigger" id="soMapBtn" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <span>{{ ___('Choose on map') }}</span>
                    </button>
                </section>

                @if($allowTips)
                <section class="tips">
                    <h3>{{ ___('Add a tip') }}</h3>
                    <div class="tip-row" id="tipRow">
                        <button data-tip="0" class="is-on" type="button">{{ ___('None') }}</button>
                        <button data-tip="5" type="button">5%</button>
                        <button data-tip="10" type="button">10%</button>
                        <button data-tip="15" type="button">15%</button>
                        <button data-tip="custom" type="button">{{ ___('Custom') }}</button>
                    </div>
                    <input aria-label="{{ ___('Custom tip amount') }}" type="number" id="customTip" class="stack-sm" placeholder="{{ ___('Custom tip amount') }}" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}" hidden>
                </section>
                @endif

                <section class="paymethods">
                    <h3>{{ ___('Pay with') }}</h3>
                    <div class="pm-list" id="pmList">
                        <button class="pm is-on" data-pm="pay_on_counter" type="button">
                            <span class="pm-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.6"/></svg></span>
                            <b>{{ ___('Pay on counter') }}</b>
                            <span class="pm-radio"></span>
                        </button>
                        @if($allow_payment)
                        <button class="pm" data-pm="pay_online" type="button">
                            <span class="pm-ic"><span class="mc" aria-hidden="true"><i></i><i></i></span></span>
                            <b>{{ ___('Card / wallet') }}<small>{{ ___('Secure payment page') }}</small></b>
                            <span class="pm-radio"></span>
                        </button>
                        @endif
                    </div>
                    @if($customer && $storeCredit > 0)
                    <label class="terms stack-md">
                        <input type="checkbox" id="mbUseWallet">
                        <span>{{ ___('Pay with store credit') }} ({{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }})</span>
                    </label>
                    @endif
                </section>

                @if(!$customer)
                <section class="cv-block">
                    <label class="terms">
                        <input type="checkbox" id="consentCb" data-csp-toggle="captureEmailWrap" data-csp-toggle-mode="hidden">
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
                <section class="cv-block ck-items" id="ckItems" hidden></section>
                <section class="totals" id="checkoutTotals"></section>
                <div class="tail-space tall"></div>
            </div>
            {{-- F2: this used to render at y=674 in a 667px viewport with no
                 scroll-into-view, so a failed "Place order" looked like a dead
                 button. It now lives INSIDE the always-visible order band, directly
                 above the control that produced it. --}}
            <footer class="order-band slim">
                <p class="err-box" id="formError" role="alert" aria-live="assertive" tabindex="-1" hidden></p>
                <button class="cta-band" id="placeOrder" type="button">
                    <span class="po-label">{{ ___('Place Order') }}</span>
                    <b id="checkoutCtaTotal">{{ store_money_format(0, $store->id) }}</b>
                    <span class="spinner" hidden></span>
                </button>
            </footer>
        </section>

        {{-- CONFIRMED --}}
        <section class="view view-sub view-center" data-view="confirmed">
            <div class="confirm-wrap">
                <div class="bubbles" id="bubbles" aria-hidden="true"></div>
                {{-- `.ca-line` has no stroke rule in style.css, so the old coupe rendered as
                     solid black paths on a dark background (invisible). Redrawn as the
                     neutral serving dome, on the `.cup-*` classes that ARE styled. --}}
                <svg class="cup" viewBox="0 0 200 176" aria-hidden="true">
                    <path class="cup-body" d="M40 116a60 60 0 0 1 120 0Z"/>
                    <path class="cup-lid" d="M24 116h152"/>
                    <path class="cup-cap" d="M100 50V40"/>
                    <path class="cup-band" d="M52 136h96"/>
                    <g class="check-badge"><circle cx="152" cy="34" r="22"/><path class="check" d="M142 34.5l7 7L163 27" pathLength="1"/></g>
                </svg>
                <h1 class="confirm-title">{{ ___('Order Confirmed') }}</h1>
                <p class="confirm-sub"><span>{{ ___('The kitchen has your order') }}</span><br><span class="confirm-eta" id="confirmEta"></span></p>
                <div class="confirm-code" id="confirmCode" hidden></div>
                <div class="confirm-pts" id="confirmPts" hidden></div>
                <div class="confirm-actions">
                    <a class="cta-band" id="confirmTrack" href="#"><span>{{ ___('Track Order') }}</span></a>
                    <button class="ghostbtn" data-go="home" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span>{{ ___('Back to Menu') }}</span></button>
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

        {{-- TRACK (in-app light view) --}}
        <section class="view view-sub" data-view="track">
            <div class="view-scroll">
                <header class="page-head sub">
                    <button class="fab back inline" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1>{{ ___('Track Order') }}</h1>
                </header>
                <div class="track-card">
                    <div class="track-top">
                        <div><p class="tk-label">{{ ___('Order') }}</p><b id="trackCode">#—</b></div>
                        <div class="tk-eta"><p class="tk-label">{{ ___('Ready in') }}</p><b id="trackEta">—</b></div>
                    </div>
                    <ol class="timeline" id="timeline"></ol>
                </div>
                {{-- F20: the journey used to carry THREE rating widgets — 5★ in the item
                     sheet (before you had eaten it), NPS 0–10 on the confirmation, and 5★
                     here. The confirmation one was dead by construction: that screen
                     auto-redirects to the order-tracking page after 3.4s, so nobody could
                     ever answer it. Exactly one rating question per surface now: 5★ here,
                     and the 0-10 NPS on the tracking page every confirmation lands on. --}}
                <div class="review-card" id="reviewCard">
                    <p class="rev-q">{{ ___('How was your order?') }}</p>
                    <div class="stars-row" id="revStars"></div>

                    @if($googleReviewUrl)
                    <a class="cta-band google" id="revGoogle" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener" hidden><span class="g-badge" aria-hidden="true">G</span><span>{{ ___('Rate us on Google') }}</span></a>
                    @endif
                    <p class="rev-thanks" id="revThanks" hidden>{{ ___('Thanks for the feedback!') }}</p>
                </div>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- TP: catering request form (gated) now lives in #cvCateringSheet,
             opened from the header catering button — no bottom-of-menu include. --}}

    </main>

    @include('store_templates._partials.notif-bell')

    {{-- ══════════ FLOATING PILL NAV ══════════ --}}
    <nav class="pillnav" id="pillnav" aria-label="{{ ___('Main') }}">
        <button class="pn-slot is-on" data-tab="home" type="button">
            <span class="pn-chip">
                <svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg>
                <span class="pn-label">{{ ___('Home') }}</span>
            </span>
        </button>
        <button class="pn-slot" data-tab="browse" type="button">
            <span class="pn-chip">
                <svg class="ic" viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1" fill="currentColor" stroke="none"/></svg>
                <span class="pn-label">{{ ___('Menu') }}</span>
            </span>
        </button>
        {{-- Customizer v2: `search.enabled` hides the search affordance. The overlay
             below is gated by the same setting, now that script.js null-checks
             `#searchOv` before reading it. --}}
        @if(theme_setting($store->id, 'search.enabled', true))
        <button class="pn-slot" id="navSearch" type="button" aria-label="{{ ___('Search') }}">
            <span class="pn-chip">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            </span>
        </button>
        @endif
        @if($allow_order)
        {{-- F12: the cart was reachable only via a small readout inside the order
             band; it is a real nav destination now, with the live line count. --}}
        <button class="pn-slot" data-go="cart" type="button" aria-label="{{ ___('Your order') }}">
            <span class="pn-chip">
                <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>
                <i class="badge cc-badge" hidden>0</i>
            </span>
        </button>
        @endif
        <button class="pn-slot" id="navFavs" type="button" aria-label="{{ ___('Favorites') }}">
            <span class="pn-chip">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
            </span>
        </button>
        @if($authMode !== 'guest_only')
        <button class="pn-slot" id="navProfile" type="button" aria-label="{{ ___('Profile') }}">
            <span class="pn-chip">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
            </span>
        </button>
        @endif
    </nav>

    {{-- ══════════ SEARCH OVERLAY ══════════ --}}
    {{-- Customizer v2: same `search.enabled` switch as the pill-nav trigger above —
         with the trigger gone nothing can open this, so it is dropped too. --}}
    @if(theme_setting($store->id, 'search.enabled', true))
    <div class="search-ov" id="searchOv" hidden>
        <div class="so-bar">
            <label class="so-field">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input aria-label="{{ ___('Search the menu') }}" id="soInput" type="search" placeholder="{{ ___('Search the menu') }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
            </label>
            <button class="so-cancel" id="soClose" type="button">{{ ___('Cancel') }}</button>
        </div>
        <div class="so-suggest" id="soSuggest">
            <p>{{ ___('Try one of these') }}</p>
            <div class="chiprow" id="soChips"></div>
        </div>
        <div class="so-results" id="soResults"></div>
    </div>
    @endif

    {{-- ══════════ STORY VIEWER ══════════ --}}
    <div class="story-viewer" id="storyViewer" hidden>
        <div class="sv-progress" id="svProgress"></div>
        <button class="sv-close" id="svClose" type="button" aria-label="{{ ___('Close') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        <div class="sv-stage" id="svStage">
            {{-- no src: script.js assigns it when a story is opened --}}
            <img class="sv-media" id="svMedia" alt="">
            <button class="sv-nav prev" id="svPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="sv-nav next" id="svNext" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="sv-foot">
                <p class="sv-caption" id="svCaption"></p>
                <a class="cta sv-cta" id="svCta" href="#" target="_blank" rel="noopener" hidden><span>{{ ___('Order now') }}</span></a>
            </div>
        </div>
    </div>

    {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
    <div class="scrim" id="scrim" hidden></div>

    {{-- LANGUAGE sheet — one row per language, same list styling as the branch
         picker. Rendered whenever the store offers more than one language. --}}
    @if($__soLangSheet ?? false)
    <div class="sheet" id="langSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Language') }}</h2>
        <div class="branch-list">
            @foreach($__soLangs as $language)
                <button class="branch-row {{ get_lang() === $language->code ? 'is-on' : '' }}" type="button"
                        data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}">
                    <span class="lang-code">{{ strtoupper($language->code) }}</span>
                    <b>{{ $language->name }}</b><span class="branch-check"></span>
                </button>
            @endforeach
        </div>
        <div class="tail-space"></div>
    </div>
    @endif

    {{-- CATERING & EVENTS sheet — holds the shared catering request form,
         opened from the header catering button (see #cvCateringBtn). --}}
    @if(storefront_catering_enabled($store->id))
    <div class="sheet" id="cvCateringSheet" hidden>
        <div class="sheet-grab"></div>
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
    </div>
    @endif

    {{-- PROFILE sheet --}}
    <div class="sheet" id="profileSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Profile') }} &amp; {{ ___('settings') }}</h2>
        <div class="me-card">
            <span class="me-avatar">{{ Str::upper(Str::substr($customer->name ?? $store->title, 0, 1)) }}</span>
            <div>
                <b>{{ $customer->name ?? ___('Guest') }}</b>
                <p class="tk-label">{{ $customer ? ($customer->phone ?? $customer->email) : ___('Order without an account') }}</p>
            </div>
            @if($authMode !== 'guest_only')
                @if($customer)
                    <button class="minibtn" id="logoutBtn" type="button">{{ ___('Logout') }}</button>
                @else
                    <button class="minibtn" id="meAuthBtn" type="button">{{ ___('Login') }}</button>
                @endif
            @endif
        </div>

        {{-- F6: soirée had no light mode and no appearance control at all. Dark stays
             the default face; a diner in daylight can pin the light one, and the
             choice persists (localStorage, applied in script.js). --}}
        <div class="pref-row">
            <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"/></svg>{{ ___('Appearance') }}</span>
            <div class="seg mini" id="appearanceSeg">
                <button data-appearance="dark" type="button" class="is-on">{{ ___('Dark') }}</button>
                <button data-appearance="light" type="button">{{ ___('Light') }}</button>
                <button data-appearance="auto" type="button">{{ ___('System') }}</button>
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
                @foreach($mcRates as $code => $rate)
                    <button data-cur="{{ $code }}" data-rate="{{ $rate }}" type="button">{{ $code }}</button>
                @endforeach
            </div>
        </div>
        @endif

        @if($showBranches)
        <h4>{{ ___('Branch') }}</h4>
        <button class="ghostbtn block" id="nearestBranchBtn" type="button">
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
        @endif

        @if($customer)
            <a class="order-row" href="{{ route('customer.profile', $store->id) }}">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                <div><b>{{ ___('Profile & settings') }}</b><small>{{ ___('Your details, addresses and preferences') }}</small></div>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        @endif

        <h4>{{ ___('My orders') }}</h4>
        @if($customer)
            <a class="order-row" href="{{ route('customer.account', $store->id) }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>
                <div><b>{{ ___('View order history') }}</b><small>{{ ___('All your orders in one place') }}</small></div>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        @else
            {{-- R5: locally-remembered guest orders, painted by script.js
                 (renderRecentOrders) above the cross-device phone/email lookup. --}}
            <div class="orders-list stack-sm" id="soRecentOrders" hidden></div>
            @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
            <p class="sheet-sub">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
            <div class="coupon-field is-solid">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" id="lookupInput" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}">
                <button class="minibtn" id="lookupBtn" type="button">{{ ___('Find') }}</button>
            </div>
            <div class="orders-list stack-sm" id="lookupResults"></div>
        @endif

        @if($reservationsEnabled || $waitlistEnabled)
        <h4>{{ ___('Reservations') }}</h4>
        <div class="res-list">
            @if($reservationsEnabled)
            <button class="order-row" id="resOpen" type="button">
                <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9 14l2 2 4-4"/></svg>
                <div><b>{{ ___('Reserve a Table') }}</b><small>{{ ___('Book a table ahead of time') }}</small></div>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </button>
            @endif
            @if($waitlistEnabled)
            <button class="order-row" id="waitlistOpen" type="button">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M2 20a7 7 0 0 1 14 0M17 11h5M19.5 8.5v5"/></svg>
                <div><b>{{ ___('Join the Waitlist') }}</b><small>{{ ___('Get a spot in the queue') }}</small></div>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </button>
            @endif
        </div>
        @endif

        @if($waNumber)
        <a class="wa-line stack-lg" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
            <span>{{ ___('Chat with us on WhatsApp') }}</span>
            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
        </a>
        @endif

        <div class="biz-card">
            @if($store->address)<span><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</span>@endif
            @if($store->phone)<span><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><a href="tel:{{ $store->phone }}">{{ $store->phone }}</a></span>@endif
        </div>

        {{-- Privacy: wipe the cart / favourites / saved addresses this browser keeps
             (shared window.mbClearDeviceData engine). The profile sheet is soirée's
             account surface and is reachable to guests, so the control lives here. --}}
        <section class="so-device-data">
            <button class="ghostbtn sm block so-cleardata" id="soClearData" type="button">
                <svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 4h4M9 7l.6 12a2 2 0 0 0 2 1.9h.8a2 2 0 0 0 2-1.9L17 7"/></svg>
                <span>{{ ___('Clear saved data on this device') }}</span>
            </button>
            <p class="so-device-data-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
        </section>
        <p class="demo-foot">{{ $store->title }}</p>
    </div>

    {{-- FILTER sheet --}}
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
            <button data-diet="vegan" type="button">{{ ___('Vegan') }}</button>
            <button data-diet="halal" type="button">{{ ___('Halal') }}</button>
            <button data-diet="gluten_free" type="button">{{ ___('Gluten-free') }}</button>
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
            <button class="ghostbtn" id="filterReset" type="button">{{ ___('Reset') }}</button>
            <button class="cta" id="filterDone" type="button"><span>{{ ___('Show dishes') }}</span><b id="filterCount"></b></button>
        </div>
    </div>
    @endif

    {{-- RESERVE sheet (real form) --}}
    @if($reservationsEnabled)
    <div class="sheet" id="reserveSheet" hidden>
        <div class="sheet-grab"></div>
        {{-- Explicit close (carries sheet-grab for the existing close wiring). --}}
        <button class="sheet-grab sheet-x" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2>{{ ___('Book a table') }}</h2>
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
                <span class="field-label">{{ ___('Your name') }}</span>
                <input aria-label="{{ ___('Your name') }}" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}">
                <span class="field-label">{{ ___('Phone') }}</span>
                <input aria-label="{{ ___('Phone') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}">
                <span class="field-label">{{ ___('Date & Time') }}</span>
                <input aria-label="{{ ___('Date & Time') }}" name="reserved_at" type="datetime-local" required min="{{ now()->format('Y-m-d\TH:i') }}">
                <span class="field-label">{{ ___('Party size') }}</span>
                <div class="party">
                    <button class="party-btn" type="button" id="resMinus" aria-label="{{ ___('Fewer') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
                    <output id="resParty">2</output>
                    <button class="party-btn" type="button" id="resPlus" aria-label="{{ ___('More') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
                </div>
                <input type="hidden" name="party_size" id="resPartyInput" value="2">
                @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                <button class="cta" type="submit"><span>{{ ___('Confirm reservation') }}</span></button>
            </form>
        @endif
    </div>
    @endif

    {{-- WAITLIST sheet (real form) --}}
    @if($waitlistEnabled)
    <div class="sheet" id="waitlistSheet" hidden>
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
                <span class="field-label">{{ ___('Your name') }}</span>
                <input aria-label="{{ ___('Your name') }}" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}">
                <span class="field-label">{{ ___('Phone') }} ({{ ___('optional') }})</span>
                <input aria-label="{{ ___('Phone') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}">
                <span class="field-label">{{ ___('Party size') }}</span>
                <input aria-label="{{ ___('Party size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                <button class="cta" type="submit"><span>{{ ___('Join Waitlist') }}</span></button>
            </form>
        @endif
    </div>
    @endif

    {{-- lean branch picker opened by the header .loc button (crave parity, no map) --}}
    @if($showBranches)
    <div class="sheet" id="mbBranchSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Choose branch') }}</h2>
        <div class="branch-list">
            @foreach($branches as $branch)
                <a class="branch-row {{ ($activeBranch->id ?? null) === $branch->id ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}">
                    <b>{{ $branch->name }}</b><span class="branch-check"></span>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- nearest-branch map picker (self-hosted Leaflet, geo branch switcher) --}}
    @if($showBranches)
    <div class="sheet" id="branchMapSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Nearest branch') }}</h2>
        <div id="branchMap"></div>
        <p id="branchMapAttrib"></p>
        <div class="branch-list" id="branchGeoList"></div>
    </div>
    @endif

    {{-- NOTE sheet --}}
    <div class="sheet" id="noteSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Item note') }}</h2>
        <textarea aria-label="{{ ___('No onions, extra sauce…') }}" id="noteText" rows="3" placeholder="{{ ___('No onions, extra sauce…') }}"></textarea>
        <button class="cta" id="noteSave" type="button"><span>{{ ___('Save') }}</span></button>
    </div>

    {{-- ADDRESS MAP PICKER — soirée-native bottom sheet (.sheet) + its own dedicated
         scrim, driven by the shared window.mbMapPicker engine. Fills the same #custAddr
         the checkout submits, plus the hidden #custLat / #custLng coords. Isolated from
         soirée's own sheet() system: its own scrim + a .so-open state class (never
         .is-open), so the global scrim / grab / Escape close wiring never touches it. --}}
    <div class="scrim so-map-scrim" id="soMapScrim"></div>
    <div class="sheet so-map-sheet" id="soMapSheet" role="dialog" aria-modal="true" aria-hidden="true" aria-label="{{ ___('Choose on map') }}">
        <div class="so-map-grab"></div>
        <button class="so-map-x" type="button" id="soMapClose" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2>{{ ___('Choose on map') }}</h2>
        <div class="so-map-canvas" id="soMapCanvas"></div>
        <p class="so-map-preview" id="soMapPreview">{{ ___('Move the map to place the pin') }}</p>
        <button class="cta" id="soMapConfirm" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <span>{{ ___('Use this location') }}</span>
        </button>
    </div>

    {{-- AUTH sheet --}}
    @if($authMode !== 'guest_only')
    <div class="sheet" id="authSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Login') }} / {{ ___('Register') }}</h2>
        <div class="auth-tabs" id="authTabs">
            <button data-auth-tab="login" class="is-on" type="button">{{ ___('Login') }}</button>
            <button data-auth-tab="otp" type="button">{{ ___('Phone OTP') }}</button>
            <button data-auth-tab="register" type="button">{{ ___('Register') }}</button>
        </div>
        <p class="err-box" id="authErr" hidden></p>
        <div class="auth-form" data-auth="login">
            <input aria-label="{{ ___('Email') }}" id="loginEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Password') }}" id="loginPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="current-password">
            <button class="cta" id="loginBtn" type="button"><span>{{ ___('Login') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
            @include('store_templates.partials.passkey-login', ['store' => $store])
        </div>
        <div class="auth-form" data-auth="otp" hidden>
            <div id="otpStep1">
                <p class="sheet-sub">{{ ___("We'll text you a one-time code") }}</p>
                <input aria-label="{{ ___('Phone') }}" id="otpPhone" placeholder="{{ ___('Phone') }}" inputmode="tel">
                <button class="cta stack-md" id="otpSend" type="button"><span>{{ ___('Send code') }}</span></button>
            </div>
            <div id="otpStep2" hidden>
                <p class="sheet-sub">{{ ___('Enter the code we sent you') }}</p>
                <div class="otp-boxes" id="otpBoxes">
                    <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                </div>
                <button class="cta stack-md" id="otpVerify" type="button"><span>{{ ___('Verify') }}</span></button>
            </div>
        </div>
        <div class="auth-form" data-auth="register" hidden>
            <input aria-label="{{ ___('Your name') }}" id="regName" placeholder="{{ ___('Your name') }}" autocomplete="name">
            <input aria-label="{{ ___('Email') }}" id="regEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Phone') }}" id="regPhone" placeholder="{{ ___('Phone') }}" inputmode="tel" autocomplete="tel">
            <input aria-label="{{ ___('Password') }}" id="regPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
            <input aria-label="{{ ___('Confirm Password') }}" id="regPass2" type="password" placeholder="{{ ___('Confirm Password') }}" autocomplete="new-password">
            <button class="cta" id="registerBtn" type="button"><span>{{ ___('Register') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
        </div>
    </div>
    @endif

    <div class="toast" id="toast" role="status"></div>
    <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>
</div>
@endsection

@push('scripts_at_bottom')
{{-- self-hosted Leaflet (loads before script.js; CSP script-src self+nonce safe) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
<script nonce="{{ csp_nonce() }}">
    window.SOIREE = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        restaurantName: @json($store->title),
        currencySign:   @json($curSign),
        currencyGap:    @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:    @json($curPos),
        /* TOTALS CONTRACT §2 — rounding is per-RESTAURANT. `currency_decimals()` with
           no argument resolves the GLOBAL currency, but Order::computeTax() rounds with
           `currency_decimals($postOptions->currency_code)`. On a 3-decimal store (KWD,
           BHD, OMR…) that mismatch silently under-quotes the tax by up to a fil. */
        currencyDecimals: @json(currency_decimals(@$postOptions->currency_code ?: null)),
        moneyDecimals: @json(money_decimals(@$postOptions->currency_code ?: null)), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
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
        customerToken:  @json($customerApiToken),
        isFollowing:    @json((bool) $isFollowing),
        followerCount:  @json((int) $followerCount),
        hasLoyalty:       @json((bool) $loyaltyProgram),
        loyaltyType:      @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json((int) ($loyaltyProgram->reward_threshold ?? 0)),
        openResSheet:      @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        cats:           @json($jsCats),
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
            branchesGeo: @json(route('geo.branches', $store->slug)),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:  @json(route('geo.reverse')),
            stories:     @json(route('stories.public', $store->slug)),
            login:       @json(route('customer.login', $store->id)),
            register:    @json(route('customer.register', $store->id)),
            logout:      @json(route('customer.logout', $store->id)),
            otpRequest:  @json(route('customer.otp.request', $store->id)),
            otpVerify:   @json(route('customer.otp.verify', $store->id)),
            loyaltyBalance: @json($loyaltyProgram ? route('publicView.loyalty.balance', $store->id) : null),
            loyaltyRedeem:  @json($loyaltyProgram ? route('publicView.loyalty.redeem', $store->id) : null),
        },
        @php
            $i18n = [
                'popular' => ___('Popular'), 'sold_out' => ___('Sold out'),
                'not_available' => ___('Not available now'), 'starting_from' => ___('from'),
                'added' => ___('Added to your order'), 'removed' => ___('Removed'), 'remove' => ___('Remove'),
                'choose' => ___('Choose an option'), 'required' => ___('Required'), 'optional' => ___('Optional'),
                'extras' => ___('Extras'), 'serve' => ___('Serve'),
                'subtotal' => ___('Subtotal'), 'discount' => ___('Discount'), 'delivery' => ___('Delivery'),
                'tip' => ___('Tip'), 'total' => ___('Total'), 'min_order' => ___('Minimum order'),
                'select_type' => ___('Please choose an order type'), 'fill_contact' => ___('Please add your name'),
                'fill_addr' => ___('Please add a delivery address'), 'coupon_bad' => ___('Invalid promo code.'),
                'waiter_ok' => ___('A waiter is on the way'), 'bill_ok' => ___('The bill is on its way'),
                'thanks' => ___('Thanks for the feedback!'), 'note_saved' => ___('Note saved'),
                'cart_empty' => ___('Your order is empty'), 'reward_tag' => ___('Reward'), 'free_lbl' => ___('Free'),
                'kcal' => ___('kcal'), 'min' => ___('min'), 'reviews' => ___('reviews'),
                'total_drinks' => ___('Items'), 'total_price' => ___('Total Price'),
                'pick_table' => ___('Pick your table'), 'table' => ___('Table'),
                'pickup_hint' => ___('Ready for pickup shortly'), 'delivery_hint' => ___('At your door soon'),
                'room_hint' => ___('Brought straight to your room'),
                'st_received' => ___('Received'), 'st_preparing' => ___('Preparing'), 'st_ready' => ___('Ready'),
                'st_served' => ___('Served to your table'), 'st_onway' => ___('Out for delivery'),
                'st_delivered' => ___('Delivered'), 'st_picked' => ___('Picked up'),
                'est_ready' => ___('Ready around'), 'confirm_pts' => ___('You earned :n points'),
                'pts_next' => ___(':n to your next reward'), 'pts_all' => ___('Reward ready to redeem'),
                'search_none' => ___('Nothing matches your search'), 'clear' => ___('Clear filters'),
                'max' => ___('Max'), 'all' => ___('All'),
                'remove_coupon' => ___('Remove'), 'coupon_applied' => ___('Promo code applied'),
                'daily_special' => ___('Daily special'),
                'add_favorite' => ___('Add to favorites'), 'remove_favorite' => ___('Remove from favorites'),
                'order_failed' => ___('We could not place your order. Please try again.'),
                'network_error' => ___('Network error. Please try again.'),
                'add_to_order' => ___('Add to order'),
                'order_label' => ___('Order'),
                'loc_unavailable' => ___('Location unavailable'),
                'loc_denied' => ___('Location denied'),
                'no_address' => ___('No address found'),
                'addr_ph' => ___('Street, building, floor…'),
                'use_my_location' => ___('Use my location'),
                'your_order' => ___('Your order'), 'edit' => ___('Edit'),
                'reviews_title' => ___('Reviews'),
                'reviews_empty' => ___('No reviews yet.'),
                'rate_dish' => ___('Rate this dish'),
                'action_failed' => ___('Something went wrong, please try again.'),
            ];
        @endphp
        i18n: @json($i18n),
    };
</script>
{{-- script.js is loaded once by store_templates.layout (avoids double-load) --}}
@endpush

@push('scripts_at_bottom')
{{-- Address map picker + clear-saved-data wiring. soirée is a shared-layout theme,
     so window.mbMapPicker / window.mbClearDeviceData are already global (the layout
     @includes the engines). We only drive them with soirée's own sheet + controls. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var trigger = document.getElementById('soMapBtn');
    if (!window.mbMapPicker) { if (trigger) { trigger.style.display = 'none'; } return; }
    var sheet = document.getElementById('soMapSheet');
    var scrim = document.getElementById('soMapScrim');
    /* soirée-native bottom sheet + its own scrim. We use a private .so-open state
       class (not soirée's .is-open), so the theme's global scrim / sheet-grab /
       Escape wiring never mutates this sheet — no split-brain, no hide-timer race. */
    function toggle(open) {
        if (!sheet) { return; }
        sheet.classList.toggle('so-open', open);
        sheet.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (scrim) { scrim.classList.toggle('so-open', open); }
    }
    /* Escape closes the picker (soirée's global Escape handler keys off .is-open,
       which this sheet deliberately never carries). */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet && sheet.classList.contains('so-open')) { toggle(false); }
    });
    window.mbMapPicker({
        trigger: trigger,
        modal:   sheet,
        map:     document.getElementById('soMapCanvas'),
        input:   document.getElementById('custAddr'),
        lat:     document.getElementById('custLat'),
        lng:     document.getElementById('custLng'),
        preview: document.getElementById('soMapPreview'),
        confirm: document.getElementById('soMapConfirm'),
        close:   [document.getElementById('soMapClose'), scrim],
        toggle:  toggle,
        i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
    });
})();
(function () {
    var btn = document.getElementById('soClearData');
    if (!btn) { return; }
    var wrap = btn.closest('.so-device-data');
    if (!window.mbClearDeviceData) { if (wrap) { wrap.style.display = 'none'; } return; }
    var slug = @json($store->slug ?? '');
    btn.addEventListener('click', function () {
        window.mbClearDeviceData({
            keys: [
                'mb-cart-' + slug,
                'cv-favs-' + slug,
                'soiree_recent_addrs_' + slug,
                'soiree_recent_orders_' + slug,
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
@endpush

@push('scripts_at_bottom')
{{-- ZERO-TOUCH A3.1 — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endpush
