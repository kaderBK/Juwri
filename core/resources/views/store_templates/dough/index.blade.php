{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       dough — charcoal & cream pizza post_template · data preparation
       Faithful rebuild of rev/dough/demo.html wired to real menu data.
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $items      = collect($total_menus)->values();
    $categories = $store->menu_categories->values();

    /* manual multi-currency rates (CODE=rate lines) */
    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

    $curSign  = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos   = (string) currency_pos_effective($postOptions->id ?? null);
    $curGap   = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only
    /* config('settings.currency_code') is genuinely NULL on stores that never set one —
       the third argument of config() only fires on a MISSING key, not a null value, so
       the old line handed the JS the string "null" and the grand total rendered
       "null 9.00". Prefer the store's own code, then the app setting, then nothing at
       all (the JS falls back to the currency SIGN rather than inventing "USD"). */
    $curCode  = trim((string) (@$postOptions->currency_code ?: config('settings.currency_code') ?: ''));
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

    /* multi-branch → nearest-branch Leaflet map (same contract as plate/ember) */
    $showBranches = !empty($branches) && count($branches) > 1 && (@$postOptions->allow_multi_branch_switch ?? 1);

    $featured   = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    $special    = $items->first(fn($m) => (bool) ($m->is_daily_special ?? false));

    /* own-item count per real category id, then per-parent item-bearing subcategories.
       menu_categories is top-level only but an item's category_id can be a subcategory,
       so a parent chip claims its children via catKids while the sub-chip row narrows. */
    $catItemCount = [];
    foreach ($items as $m) {
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;
    }
    $subCatsOf = [];
    foreach ($categories as $cat) {
        $subs = collect($cat->subcategories ?? [])
            ->filter(fn($s) => ($catItemCount[$s->id] ?? 0) > 0)
            ->values();
        if ($subs->isNotEmpty()) {
            $subCatsOf[$cat->id] = $subs;
        }
    }

    /* helper: human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* JS data map — real menu, variants absolute, extras/mods additive */
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
        if (isset($m->variants)) {
            foreach ($m->variants as $v) {
                $variants[] = ['id' => $v->id, 'label' => $variantLabel($m, $v), 'price' => (float) $v->price];
            }
        }
        $extras = [];
        if (isset($m->extras)) {
            foreach ($m->extras as $e) {
                $extras[] = ['id' => $e->id, 'title' => $e->title, 'price' => (float) $e->price];
            }
        }

        $jsItems[$m->id] = [
            'id'    => $m->id,
            'cat'   => $m->category_id,
            'name'  => $m->name,
            'reviewList' => \Juwri\Core\Support\ViewHelpers::reviewList($reviewsByMenu->get($m->id, collect())),
            // descriptions can be stored as numeric HTML entities; decode after stripping
            // or the entity text renders literally instead of the Arabic.
            'desc'  => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'img'   => media_url('storage/menu/', $m->image, $store->id ?? null),
            'gallery' => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryUrls($m->gallery_images, $store->id ?? null)
                : [],
            'price' => (float) $m->price,
            // K8: pre-schedule reference price for a strike-through (MenuScheduleService::decorate)
            'original_price' => (!is_null($m->original_price ?? null) && (float) $m->original_price > (float) $m->price) ? (float) $m->original_price : null,
            // Happy-hour label for the card offer ribbon (was never serialized → badge could not render)
            'schedule_label' => $m->schedule_label ?? null,
            'rating'=> $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : '',
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyListPlain($itemAllergies),
            'variants' => $variants,
            'extras'   => $extras,
            'is_vegan'         => (bool) $m->is_vegan,
            'is_halal'         => (bool) $m->is_halal,
            'is_gluten_free'   => (bool) $m->is_gluten_free,
            'is_popular'       => (bool) $m->is_popular,
            'is_daily_special' => (bool) ($m->is_daily_special ?? false),
            'spice_level'      => (int) $m->spice_level,
            'is_sold_out'      => (bool) ($m->is_sold_out
                                    || (($m->track_inventory ?? false) && ($m->stock_qty ?? 1) <= 0)),
            'calories'         => $m->calories ?? null,
            'prep_time'        => $m->prep_time ?? null,
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
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;

    /* brand row (ported from crave) — mint (and session-cache) a raw customer
       token so the theme JS can call the CustomerToken-guarded follow endpoint. */
    $customerApiToken = storefront()->customerApiToken($customer, 'dough');
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

    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */

    /* engagement extras */
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));
    $otpGateEnabled      = \Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController::gateEnabled($store->id);

    $catEmojis = ['🍕','🥖','🍝','🍰','🥤','📦','🥗','🍔','🌮','🍗'];

    /* Missing-photo art in dough's OWN palette (cream disc + charcoal wheat mark).
       The platform default.png is a pale-blue plate on a pale-blue disc, which is
       off-brand on every card, the item hero, the promo card and the orange band —
       and it 404s on this install, so it is the *visible* state, not an edge case.
       Shared with script.js through CFG.placeholderImg so both sides draw the same
       thing. data: URIs are allowed by the app CSP (img-src 'self' data: https:). */
    $doughPlaceholder = 'data:image/svg+xml;charset=utf-8,'.rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120">'
        .'<rect width="120" height="120" fill="#E9E2D4"/>'
        .'<g fill="none" stroke="#1A1B1E" stroke-opacity=".38" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">'
        .'<circle cx="60" cy="60" r="30"/><path d="M60 40v40M60 48c-6-6-14-4-14-4s0 8 6 11M60 48c6-6 14-4 14-4s0 8-6 11"/>'
        .'<path d="M60 62c-6-6-14-4-14-4s0 8 6 11M60 62c6-6 14-4 14-4s0 8-6 11"/></g></svg>'
    );
    /* the featured/promo/band photo is decorative — only show a REAL one */
    $featuredImg = ($featured && $featured->image && $featured->image !== 'default.png')
        ? media_url('storage/menu/', $featured->image, $store->id ?? null)
        : null;

    /* Customizer v2 §sections — same contract as scoop: schema defaults first,
       overlay the legacy menu_sections rows, then the theme customizer (the newer
       source of truth) on top. */
    $savedSections = isset($postOptions->menu_sections)
        ? json_decode(json_encode($postOptions->menu_sections), true)
        : null;
    $sectionVisible = ['catbar' => true, 'daily_specials' => true, 'menu' => true];
    if (is_array($savedSections)) {
        foreach ($savedSections as $saved) {
            if (isset($saved['id'], $sectionVisible[$saved['id']])) {
                $sectionVisible[$saved['id']] = isset($saved['visible']) ? (bool) $saved['visible'] : true;
            }
        }
    }
    $sectionVisible = array_replace($sectionVisible, theme_section_visible($store->id));
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    @if($showBranches)
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    @endif
@endpush

@section('content')
<div class="dough" id="dough" data-dough-theme="light">
{{-- Paint the right palette during parse, before the first frame — reading the
     stored appearance choice in script.js (loaded at the end of body) would flash
     the cream UI at a customer who picked dark. Same storage key as LS.theme. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    try {
        var m = JSON.parse(localStorage.getItem("dough_" + @json($store->slug) + "_theme")) || "auto";
        var dark = m === "dark" || (m !== "light" && window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches);
        document.getElementById("dough").setAttribute("data-dough-theme", dark ? "dark" : "light");
    } catch (e) {}
})();
</script>

    {{-- ══════════ ONBOARDING (charcoal + doodles) ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if(theme_setting($store->id, 'splash.enabled', true) && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="onboard" id="onboard">
        <div class="doodle-field" aria-hidden="true">
            <svg class="dd dd-a" viewBox="0 0 100 100"><path d="M50 9C72 7 90 25 91 47 92 71 74 90 51 91 28 92 9 74 9 51 9 29 27 11 50 9Z"/></svg>
            <svg class="dd dd-b" viewBox="0 0 100 100"><path d="M20 24 84 42 30 88Z"/><path d="M13 19C38 8 66 16 88 34"/><circle cx="45" cy="45" r="4"/><circle cx="38" cy="63" r="4"/><circle cx="55" cy="58" r="3"/></svg>
            <svg class="dd dd-c" viewBox="0 0 100 100"><path d="M63 12a40 40 0 1 0 25 52A32 32 0 1 1 63 12Z"/></svg>
            <svg class="dd dd-d" viewBox="0 0 100 100"><path d="M6 54C18 38 30 70 42 54 54 38 66 70 78 54c6-8 12-4 16 0"/></svg>
            <svg class="dd dd-e" viewBox="0 0 100 100"><path d="M50 14v22M50 64v22M14 50h22M64 50h22"/></svg>
            <svg class="dd dd-f" viewBox="0 0 100 100"><path d="M50 50c-1-9 11-10 12 0 1 12-17 13-18 0-2-16 24-17 26 0 2 18-30 20-32 0"/></svg>
            <svg class="dd dd-g" viewBox="0 0 100 100"><circle cx="50" cy="50" r="6"/></svg>
        </div>
        <span class="ob-brand">{{ $store->title }}<i>.</i></span>
        <button class="ob-skip" id="obSkip" type="button">{{ ___('Skip') }}</button>
        <div class="ob-copy">
            <h1 class="ob-title" id="obTitle"></h1>
            <p class="ob-sub" id="obSub"></p>
        </div>
        <div class="ob-foot">
            <div class="ob-dots" id="obDots"><i class="is-on"></i><i></i><i></i></div>
            <button class="ob-next" id="obNext" type="button" aria-label="{{ ___('Next') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </div>
    </div>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="views" id="views">

        {{-- HOME (cream) --}}
        <section class="view is-active" data-view="home">
            @php
                $__doughHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
                $doughCoverUrl = $__doughHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
                $doughShowCover = $__doughHasCover && theme_setting($store->id, 'brand.show_cover', true);
            @endphp
            {{-- Collapsing header: overlays the top of the home view (sibling of the
                 scroll area, never scrolls). Slides in once the greeting leaves the
                 scroll container, and hides again when the sticky category bar reaches
                 the top. Mirrors the top-chrome buttons (proxied). --}}
            <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
                <span class="cv-condensed-logo">@if(!empty($store->main_image) && $store->main_image !== 'default.png')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif</span>
                <b class="cv-condensed-name">{{ $store->title }}</b>
                <span class="cv-condensed-actions">
                    @if(count($menu_languages ?? []) > 1)
                    <button class="circlebtn" type="button" data-cv-proxy="lang" aria-label="{{ ___('Language') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="circlebtn" type="button" data-cv-proxy="catering" aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    <button class="circlebtn" type="button" data-cv-proxy="notif" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                    </button>
                </span>
            </div>
            <div class="view-scroll">
                @if($doughShowCover)
                {{-- Concept 1 cover hero: cover img + scrim behind the greeting chrome. --}}
                <div class="dough-cover">
                    <div class="dough-cover-media" aria-hidden="true">
                        @if((string) ($doughCoverUrl) !== '')<img class="dough-cover-img" src="{{ $doughCoverUrl }}" alt="" data-csp-error="hide">@endif
                        <span class="dough-cover-scrim"></span>
                    </div>
                @endif
                <header class="home-top">
                    <button class="hello" data-go="profile" type="button">
                        <span class="hello-hi">{{ $customer ? ___('Hello') . ' ' . $customer->name : ___('Welcome to') }}</span>
                        <b class="hello-big">{{ $customer ? ___('Welcome Back') : $store->title }}</b>
                        {{-- open/closed status — $isOpen was computed but never surfaced --}}
                        <span class="open-chip{{ $isOpen ? '' : ' closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                    </button>
                    @if(count($menu_languages ?? []) > 1)
                    {{-- Language: a globe opening the sheet below, so the header holds one
                         control whether the store runs two languages or eleven. The shared
                         language-switch partial delegates on [data-lang-code]. --}}
                    <button class="circlebtn" id="langBtn" type="button" aria-label="{{ ___('Language') }}" aria-haspopup="dialog">
                      <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    {{-- Catering & Events: opens #cvCateringSheet (wired in script.js). --}}
                    <button class="circlebtn" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    @if($allowDietary)
                    <button class="circlebtn" id="filterBtn" type="button" aria-label="{{ ___('Filters') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                        <i class="flt-dot" id="filterDot" hidden></i>
                    </button>
                    @endif
                    {{-- Customizer v2: `search.enabled` hides the search trigger for menus
                         small enough that browsing beats searching. On by default. --}}
                    @if(theme_setting($store->id, 'search.enabled', true))
                    <button class="circlebtn" id="searchBtn" type="button" aria-label="{{ ___('Search') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                    </button>
                    @endif
                    @if($allow_order)
                    <button class="circlebtn" id="bagBtn" data-go="cart" type="button" aria-label="{{ ___('Cart') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-.9 11.4a2 2 0 0 1-2 1.85H8.5a2 2 0 0 1-2-1.85L5.6 8.4Z"/><path d="M9 8.4V6.6a3 3 0 0 1 6 0v1.8"/></svg>
                        <em class="bag-count" id="bagDot" hidden>0</em>
                    </button>
                    @endif
                    {{-- Notification-center bell (shared partial, rev/notif-center-spec.md).
                         Not gated: guests use recent-orders, logged-in diners the inbox. --}}
                    <button class="circlebtn" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                        <i class="flt-dot" id="mbNotifDot" hidden></i>
                    </button>
                    {{-- Light/dark quick toggle (Lane B) — drives dough's NATIVE appearance
                         system (data-dough-theme + LS "theme" key), same store as #themeSeg. --}}
                    <button class="circlebtn" type="button" id="doughThemeToggle"
                            aria-pressed="false" aria-label="{{ ___('Toggle dark mode') }}">
                        <svg class="ic mb-th-moon" viewBox="0 0 24 24"><path d="M20.5 14.4A8.5 8.5 0 1 1 9.6 3.5a6.8 6.8 0 0 0 10.9 10.9Z"/></svg>
                        <svg class="ic mb-th-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.6 12h2.2M19.2 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
                    </button>
                </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>
                @if($doughShowCover)
                </div>{{-- /.dough-cover --}}
                @endif


                {{-- brand / follow row (ported from crave's F2). Customizer v2
                     §sections: brand_row gates the block (default on). --}}
                @if($sectionVisible['brand_row'] ?? true)
                <div class="brand-row" id="brandRow" data-tc-section="brand_row" data-mb-hotel-chip-slot>
                    <span class="brand-logo">
                        @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                    </span>
                    <span class="brand-info">
                        <b class="brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="verified-badge" id="verifiedBadge" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
                        @if(trim((string)($store->sub_title ?? '')) !== '')
                        <span class="brand-tagline">{{ $store->sub_title }}</span>
                        @endif
                        @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true), 'countId' => 'followerCount'])
                    </span>
                    {{-- Following needs a customer account: on a guest_only store the
                         auth sheet is never rendered, so this would be a dead button —
                         hide it instead of shipping a control that cannot do anything. --}}
                    @if($authMode !== 'guest_only' || $customer)
                    <button class="followbtn {{ $isFollowing ? 'is-on' : '' }}" id="followBtn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
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
                <div class="dough-reviews" id="doughReviews" data-mb-reviews-end>
                    <div class="dough-rating"><span class="dough-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
                    @if($aboutReviews->isNotEmpty())
                    <div class="dough-review-list">
                        @foreach($aboutReviews as $rev)
                        <div class="dough-review">
                            <div class="dough-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="dough-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                            @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endif

                {{-- The promo card used to headline the STORE TITLE, which the header
                     already prints two lines above it, and its button opened an
                     arbitrary item. It now headlines the actual thing being promoted —
                     the daily special, else the most popular dish — and opens THAT.
                     Customizer v2 §sections: daily_specials gates this card
                     (script.js null-guards #promoCard). --}}
                @if($sectionVisible['daily_specials'] ?? true)
                @php
                    $promoItem = $special ?: ($items->first(fn($m) => (bool) ($m->is_popular ?? false)) ?: $featured);
                    $promoImg  = ($promoItem && $promoItem->image && $promoItem->image !== 'default.png')
                        ? media_url('storage/menu/', $promoItem->image, $store->id ?? null) : $featuredImg;
                    $promoKicker = theme_setting($store->id, 'texts.promo_kicker', $special ? ___('Daily special') : ___('Freshly baked, wood-fired daily'));
                @endphp
                @if($promoItem)
                <button class="promo" id="promoCard" type="button" data-promo-id="{{ $promoItem->id }}">
                    <span class="promo-copy">
                        <span class="promo-kicker" data-tc-text="texts.promo_kicker">{{ $promoKicker }}</span>
                        <b class="promo-off">{{ $promoItem->name }}</b>
                        <span class="promo-price">{{ store_money_format((float) $promoItem->price, $store->id) }}</span>
                        <span class="promo-btn">{{ $allow_order ? ___('Order Now') : ___('View') }}</span>
                    </span>
                    @if($promoImg)<img class="promo-pizza" src="{{ $promoImg }}" alt="" data-csp-error="remove">@endif
                </button>
                @endif
                @endif

                {{-- admin-managed promo banners (real, optional) --}}
                @if(!empty($menuBanners) && count($menuBanners))
                <div class="banners" id="banners">
                    @foreach($menuBanners as $bi => $banner)
                        <a class="banner {{ $bi % 2 === 1 ? 'alt' : '' }}" href="{{ storefront_localized_link($banner->link_url) ?: '#' }}" @if($banner->link_url) target="_blank" rel="noopener" @endif>
                            <span class="banner-copy">
                                @if($banner->subtitle)<span class="banner-kicker">{{ $banner->subtitle }}</span>@endif
                                <b class="banner-title">{{ $banner->title }}</b>
                                @if($banner->link_url)<span class="banner-btn">{{ ___('Order Now') }}</span>@endif
                            </span>
                            @if($banner->image)
                            @if((string) ($banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null)) !== '')<img class="banner-img" src="{{ $banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                            @endif
                        </a>
                    @endforeach
                </div>
                @endif

                {{-- stories rail — stays hidden unless /stories returns items --}}
                <div class="stories" id="storiesRail" hidden aria-label="{{ ___('Stories') }}"></div>

                {{-- Customizer v2 §sections: catbar — category pills + sub-chip rows.
                     renderCats() writes #cats unconditionally, so a hidden stub stays
                     behind when the section is off (subChips/seeAll are null-guarded). --}}
                @if($sectionVisible['catbar'] ?? true)
                {{-- Sentinel: once this scrolls above the top, the sticky category bar
                     takes over the top and the collapsing header hides so they never stack. --}}
                <span class="cv-menu-sentinel" aria-hidden="true"></span>
                {{-- data-mb-grid-lead: the category selector must touch the items it
                     filters — injected sections (offers strip, order-type bar) climb
                     above these rows instead of wedging between categories and grid. --}}
                <div class="cat-row" data-mb-grid-lead data-mbt-anchor>
                    <div class="cats" id="cats"></div>
                    <button class="seeall" id="seeAll" type="button">{{ ___('See All') }}</button>
                </div>

                {{-- Secondary sub-category chips. One hidden group per parent that has
                     item-bearing subcategories; JS reveals the active parent's group.
                     "All" (data-sub="root") shows every descendant of the parent. --}}
                @if(!empty($subCatsOf))
                <div class="subchips" id="subChips" hidden aria-label="{{ ___('Sub-categories') }}" data-mb-grid-lead>
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
                @else
                <div class="cats" id="cats" hidden data-mb-grid-lead></div>
                @endif

                {{-- Customizer v2 §sections: menu — the main item grid. renderGrid()
                     writes #grid/#gridEmpty/#gridTitle unconditionally, so hidden
                     stubs stay behind when the section is off. --}}
                @if($sectionVisible['menu'] ?? true)
                <div class="section-head" data-mb-grid-lead><h2 id="gridTitle">{{ ___('Popular') }}</h2></div>
                <div class="grid" id="grid"></div>
                <div class="empty" id="gridEmpty" hidden>
                    <svg class="empty-art" viewBox="0 0 100 100"><path d="M20 24 84 42 30 88Z"/><path d="M13 19C38 8 66 16 88 34"/><circle cx="45" cy="45" r="4"/><circle cx="38" cy="63" r="4"/></svg>
                    <p>{{ ___('Nothing here — try another category') }}</p>
                </div>
                @else
                <span id="gridTitle" hidden></span>
                <div id="grid" hidden></div>
                <div id="gridEmpty" hidden></div>
                @endif
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- ITEM (lavender hero + white sheet) --}}
        <section class="view view-sub" data-view="item">
            <div class="i-hero">
                <button class="circlebtn lav back" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                <button class="circlebtn lav" id="iFav" type="button" aria-label="{{ ___('Favorite') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
                {{-- no src= here: JS sets it when an item opens, and an empty src makes the browser refetch the page.
                     on a 404 fall back to the placeholder like every other image in the theme, not a blank box. --}}
                <img class="i-img" id="iImg" alt="" data-csp-error="fallback-class" data-csp-class="is-ph" data-csp-fallback="{{ $doughPlaceholder }}">
                <span class="i-badge" id="iBadge" hidden data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___('Daily special')) }}</span>
                {{-- per-item image gallery: thumbnails swap the hero image (source: items.gallery) --}}
                <div class="i-gallery" id="iGallery" hidden></div>
            </div>
            <div class="i-sheet" id="iSheet">
                <button class="i-notch" id="iNotch" type="button" aria-label="{{ ___('Expand details') }}"><i></i></button>
                <div class="i-scroll" id="iScroll">
                    <div class="i-titlerow">
                        <h1 id="iName" dir="auto"></h1>
                        <span class="i-rate" id="iRate" hidden></span>
                    </div>
                    <div class="i-meta" id="iMeta"></div>
                    <p class="i-ing" id="iIng" dir="auto"></p>

                    <div class="i-block" id="iSizeBlock" hidden>
                        <div class="i-bhead"><h3 id="iSizeTitle">{{ ___('Size') }}</h3><span class="i-bhint" id="iSizeHint"></span></div>
                        <div class="sizes" id="iSizes"></div>
                    </div>

                    <div id="iOptWrap"></div>

                    <div class="i-block" id="iExtraBlock" hidden>
                        <div class="i-bhead"><h3>{{ ___('Extras') }}</h3><span class="i-bhint">{{ ___('Optional') }}</span></div>
                        <div class="extras" id="iExtras"></div>
                    </div>

                    {{-- inline per-item quick-rate — the tapped star opens the
                         shared review write-sheet pre-selected, so a rating and a
                         written review are one flow through one endpoint. --}}
                    <div class="i-block i-quickrate" id="iQuickRate"></div>

                    {{-- product reviews — rebuilt by openItem(); the shared
                         _partials/item-reviews layer hooks on .p-reviews to inject
                         the histogram + write button. --}}
                    <section class="i-block p-reviews" id="iReviews"></section>

                    <div class="i-qtyrow">
                        <button class="qty-circle" data-iq="-1" type="button" aria-label="{{ ___('Decrease') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
                        <output id="iQty">1</output>
                        <button class="qty-circle" data-iq="1" type="button" aria-label="{{ ___('Increase') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
                    </div>
                    <div class="i-tail"></div>
                </div>
                @if($allow_order)
                <footer class="i-cta">
                    {{-- the CTA used to be a bag glyph + a number and no verb at all;
                         a bag that looks like a bin next to "$7.00" reads as "delete" --}}
                    <button class="pricepill" id="iAdd" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-.9 11.4a2 2 0 0 1-2 1.85H8.5a2 2 0 0 1-2-1.85L5.6 8.4Z"/><path d="M9 8.4V6.6a3 3 0 0 1 6 0v1.8"/></svg>
                        <span class="pp-verb">{{ ___('Add to cart') }}</span>
                        <b id="iTotal"></b>
                    </button>
                    {{-- shown by script.js (syncBag) once the cart has items: a direct
                         jump to the cart, mirroring orbit's #pCartJump. --}}
                    <button class="i-cart-jump" id="iCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-.9 11.4a2 2 0 0 1-2 1.85H8.5a2 2 0 0 1-2-1.85L5.6 8.4Z"/><path d="M9 8.4V6.6a3 3 0 0 1 6 0v1.8"/></svg>
                        <i class="i-cart-jump-count" id="iCartJumpCount" aria-hidden="true">0</i>
                    </button>
                </footer>
                @endif
            </div>
        </section>

        {{-- CART (white) --}}
        @if($allow_order)
        <section class="view" data-view="cart">
            <div class="view-scroll">
                <header class="page-head">
                    <h1>{{ ___('Cart') }}</h1>
                    <span class="count-badge" id="cartCount">0</span>
                </header>
                <div class="cart-list" id="cartList"></div>
                <div class="empty" id="cartEmpty" hidden>
                    <svg class="empty-art" viewBox="0 0 100 100"><path d="M50 9C72 7 90 25 91 47 92 71 74 90 51 91 28 92 9 74 9 51 9 29 27 11 50 9Z"/><circle cx="38" cy="42" r="4"/><circle cx="62" cy="42" r="4"/><path d="M36 66c8-7 20-7 28 0"/></svg>
                    <p>{{ ___('Your cart is empty — knead something?') }}</p>
                    <button class="ghostbtn" data-go="home" type="button">{{ ___('Browse menu') }}</button>
                </div>

                <div class="cart-tools" id="cartTools">
                    <section class="tool-block">
                        <h3>{{ ___('Order type') }}</h3>
                        <div class="seg" id="otSeg">
                            @if($allow_delivery)<button data-ot="delivery" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg><span>{{ ___('Delivery') }}</span></button>@endif
                            @if($allow_takeaway)<button data-ot="takeaway" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-.9 11.4a2 2 0 0 1-2 1.85H8.5a2 2 0 0 1-2-1.85L5.6 8.4Z"/><path d="M9 8.4V6.6a3 3 0 0 1 6 0v1.8"/></svg><span>{{ ___('Pickup') }}</span></button>@endif
                            @if($allow_on_table)<button data-ot="on-table" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg><span>{{ ___('Dine-in') }}</span></button>@endif
                            @if($allow_room_service)<button data-ot="room_service" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 6v2M10.5 6h3"/></svg><span>{{ ___('Room service') }}</span></button>@endif
                        </div>
                        <div class="ot-fields" id="otFields"></div>
                        @if($allowOrderSched)
                        <div class="when-row">
                            <span class="when-label"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg><span>{{ ___('When') }}</span></span>
                            <div class="seg mini" id="whenSeg">
                                <button data-when="asap" class="is-on" type="button">{{ ___('ASAP') }}</button>
                                <button data-when="later" type="button">{{ ___('Later') }}</button>
                            </div>
                            <input aria-label="{{ ___('Schedule for later') }}" type="datetime-local" id="whenTime" class="when-time" hidden
                                   min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}"
                                   max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
                        </div>
                        @endif
                    </section>

                    @if($allowCoupons)
                    <section class="tool-block">
                        <label class="coupon-field">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                            <input id="couponInput" placeholder="{{ ___('Promo code') }}">
                            <button class="minibtn" id="couponApply" type="button">{{ ___('Apply') }}</button>
                        </label>
                        <p class="coupon-msg" id="couponMsg" hidden></p>
                    </section>
                    @endif

                    @if($allowTips)
                    <section class="tool-block">
                        <h3>{{ ___('Tip') }}</h3>
                        <div class="tip-row" data-tiprow>
                            <button data-tip="0" class="is-on" type="button">{{ ___('No tip') }}</button>
                            <button data-tip="5" type="button">5%</button>
                            <button data-tip="10" type="button">10%</button>
                            <button data-tip="15" type="button">15%</button>
                        </div>
                    </section>
                    @endif

                    @if($loyaltyProgram && $customer)
                    <button class="loyalty-row" id="loyaltyBtn" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v9M8.8 10.2c0-1.5 1.4-2.2 3.2-2.2s3.2.7 3.2 2c0 2.8-6.4 1.6-6.4 4.4 0 1.3 1.4 2.1 3.2 2.1s3.2-.8 3.2-2.2"/></svg>
                        <b><span id="loyaltyPts">–</span> {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('pts') }}</b>
                        <span class="loy-cta">{{ ___('Redeem') }}</span>
                    </button>
                    @endif

                    @if($allowWaiter && $allow_on_table)
                    <div class="waiter-row" id="waiterRow" hidden>
                        <button class="ghostbtn" id="callWaiter" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span>{{ ___('Call waiter') }}</span></button>
                    </div>
                    @endif

                    @if($waNumber)
                    <a class="wa-order" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                        <span>{{ ___('Chat with us on WhatsApp') }}</span>
                        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </a>
                    @endif

                    {{-- Share this menu — native profile row (like the WhatsApp .wa-order),
                         not the floating pill. Gated on the same share.fallback_button
                         toggle; data-web-share makes _partials/share stand down. --}}
                    @if(theme_setting($store->id, 'share.fallback_button'))
                    <button class="wa-order share-btn" id="mbShareRow" type="button" data-web-share
                            aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg>
                        <span>{{ ___('Share this menu') }}</span>
                        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </button>
                    @endif

                    <section class="breakdown" id="breakdown"></section>
                    <p class="minorder" id="minOrderMsg" hidden></p>

                    <div class="band" id="band">
                        <div class="band-inner">
                            <div class="band-row" id="bandDelRow"><span>{{ ___('Delivery Amount') }}</span><b id="bandDel"></b></div>
                            <div class="band-total"><span>{{ ___('Total Amount') }}</span><b id="bandTotal"></b></div>
                        </div>
                        @if($featuredImg)<img class="band-pizza" src="{{ $featuredImg }}" alt="" data-csp-error="remove">@endif
                    </div>
                </div>
                <div class="tail-space tall"></div>
            </div>
            <footer class="foot-cta" id="cartCta" hidden>
                <button class="inkbar" id="goCheckout" type="button"><span>{{ ___('Make Payment') }}</span><b class="bar-total" id="ckTotal"></b><svg class="ic" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
            </footer>
        </section>

        {{-- CHECKOUT (cream) --}}
        <section class="view view-sub" data-view="checkout">
            <div class="view-scroll">
                <header class="page-head sub">
                    <button class="circlebtn back" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1>{{ ___('Checkout') }}</h1>
                </header>

                <div class="co-summary" id="coSummary"></div>
                {{-- The validation message used to live at the very bottom of a long
                     scroll, under the sticky footer: tapping "Place order" with an empty
                     name looked like nothing happened. It now sits directly above the
                     first field it can be about, and the field itself is focused. --}}
                <p class="err-box co-error" id="coError" hidden></p>

                <section class="tool-block">
                    <h3>{{ ___('Your details') }}</h3>
                    <div class="field-duo">
                        <input aria-label="{{ ___('Your name') }}" id="coName" dir="auto" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}" autocomplete="name">
                        <input aria-label="{{ ___('Phone') }}" id="coPhone" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}" inputmode="tel" autocomplete="tel">
                    </div>
                    <div id="coAddrWrap" class="co-addr" style="display:none">
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
                        @else
                        {{-- Guests: renderRecentAddrs() fills this with delivery addresses
                             remembered locally on this device (dough_recent_addrs_<slug>). --}}
                        <div class="saved-addrs" id="savedAddrs" hidden></div>
                        @endif
                        {{-- address autocomplete + use-my-location (geo.autocomplete / geo.reverse) --}}
                        <div class="addr-field" id="coAddrField">
                            <input aria-label="{{ ___('Street, building, floor…') }}" id="coAddr" dir="auto" placeholder="{{ ___('Street, building, floor…') }}" autocomplete="off" autocorrect="off" spellcheck="false"
                                   value="{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}">
                            <input type="hidden" id="coLat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                            <input type="hidden" id="coLng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                            <button class="uselocbtn" id="coUseLocation" type="button" title="{{ ___('Use my location') }}" aria-label="{{ ___('Use my location') }}">
                                <svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                            </button>
                            <div class="addr-suggest" id="coAddrSuggest" hidden></div>
                        </div>
                        <p class="geo-attrib" id="coGeoAttrib" hidden></p>
                    </div>
                    <div id="coTableWrap" class="co-addr" style="display:none">
                        <input aria-label="{{ ___('Table number') }}" id="coTable" type="{{ $table ? 'text' : 'number' }}" value="{{ $table->table_no ?? '' }}" {{ $table ? 'readonly' : '' }} placeholder="{{ ___('Table number') }}" inputmode="numeric">
                    </div>
                </section>

                <section class="tool-block">
                    <h3>{{ ___('Pay with') }}</h3>
                    <div class="pm-list" id="pmList">
                        <button class="pm is-on" data-pm="cash" data-pv="pay_on_counter" type="button">
                            <span class="pm-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.6"/></svg></span>
                            <b>{{ ___('Cash') }}<small>{{ ___('Pay on counter / to courier') }}</small></b>
                            <span class="pm-radio"></span>
                        </button>
                        @if($allow_payment)
                        <button class="pm" data-pm="card" data-pv="pay_online" type="button">
                            <span class="pm-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h4"/></svg></span>
                            <b>{{ ___('Card') }}<small>{{ ___('Card / wallet via secure page') }}</small></b>
                            <span class="pm-logos"><span>VISA</span><span>MC</span><span>MADA</span></span>
                            <span class="pm-radio"></span>
                        </button>
                        @endif
                        @if($customer && $storeCredit > 0)
                        <button class="pm" data-pm="wallet" data-pv="pay_on_counter" data-wallet="1" type="button">
                            <span class="pm-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7a2 2 0 0 1 2-2h12v3M4 7v10a2 2 0 0 0 2 2h13a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H4"/><circle cx="16.5" cy="14" r="1.3" fill="currentColor" stroke="none"/></svg></span>
                            <b>{{ ___('Wallet') }}<small>{{ ___('Balance') }} {{ store_money_format($storeCredit, $store->id) }}</small></b>
                            <span class="pm-radio"></span>
                        </button>
                        @endif
                    </div>
                </section>

                @if($allowTips)
                <section class="tool-block">
                    <h3>{{ ___('Tip') }}</h3>
                    <div class="tip-row" data-tiprow>
                        <button data-tip="0" class="is-on" type="button">{{ ___('No tip') }}</button>
                        <button data-tip="5" type="button">5%</button>
                        <button data-tip="10" type="button">10%</button>
                        <button data-tip="15" type="button">15%</button>
                    </div>
                </section>
                @endif

                {{-- Order summary — the customer should see WHAT they are paying for at
                     the point of commitment, not just a number. Rendered by renderCheckout. --}}
                <section class="ck-items" id="ckItems" hidden></section>
                <section class="breakdown" id="coBreakdown"></section>
                <div class="band" id="coBand">
                    <div class="band-inner">
                        <div class="band-row" id="coBandDelRow"><span>{{ ___('Delivery Amount') }}</span><b id="coBandDel"></b></div>
                        <div class="band-total"><span>{{ ___('Total Amount') }}</span><b id="coBandTotal"></b></div>
                    </div>
                    @if($featuredImg)<img class="band-pizza" src="{{ $featuredImg }}" alt="" data-csp-error="remove">@endif
                </div>
                @include('store_templates._partials.voice-note-recorder')
                <div class="tail-space tall"></div>
            </div>
            <footer class="foot-cta">
                <button class="inkbar" id="placeOrder" type="button"><span>{{ ___('Place Order') }}</span><b class="bar-total" id="poTotal"></b><svg class="ic" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg><span class="spinner" hidden></span></button>
            </footer>
        </section>

        {{-- OTP VERIFY / CONFIRMED (charcoal + doodles) --}}
        <section class="view view-sub view-otp" data-view="otp">
            <div class="doodle-field" aria-hidden="true">
                <svg class="dd dd-a" viewBox="0 0 100 100"><path d="M50 9C72 7 90 25 91 47 92 71 74 90 51 91 28 92 9 74 9 51 9 29 27 11 50 9Z"/></svg>
                <svg class="dd dd-b" viewBox="0 0 100 100"><path d="M20 24 84 42 30 88Z"/><path d="M13 19C38 8 66 16 88 34"/><circle cx="45" cy="45" r="4"/><circle cx="38" cy="63" r="4"/><circle cx="55" cy="58" r="3"/></svg>
                <svg class="dd dd-c" viewBox="0 0 100 100"><path d="M63 12a40 40 0 1 0 25 52A32 32 0 1 1 63 12Z"/></svg>
                <svg class="dd dd-d" viewBox="0 0 100 100"><path d="M6 54C18 38 30 70 42 54 54 38 66 70 78 54c6-8 12-4 16 0"/></svg>
                <svg class="dd dd-e" viewBox="0 0 100 100"><path d="M50 14v22M50 64v22M14 50h22M64 50h22"/></svg>
                <svg class="dd dd-g" viewBox="0 0 100 100"><circle cx="50" cy="50" r="6"/></svg>
            </div>
            <button class="circlebtn dark back" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
            <div class="otp-card">
                <div class="otp-main" id="otpMain" hidden>
                    <h2>{{ ___('Verify Phone Number') }}</h2>
                    <p class="otp-sub">{{ ___('We Have Sent Code To Your Phone Number') }}</p>
                    <b class="otp-phone" id="otpPhone" dir="ltr">+0000 0000 0000</b>
                    <div class="otp-boxes otp-6" id="otpBoxes">
                        <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                    </div>
                    <p class="otp-err" id="otpErr" hidden></p>
                    <button class="inkbar" id="otpConfirm" type="button"><span>{{ ___('Verify & place order') }}</span><span class="spinner" hidden></span></button>
                    <button class="otp-resend" id="otpResend" type="button">{{ ___('Resend code') }}</button>
                </div>
                <div class="otp-done" id="otpDone" hidden>
                    <svg class="tick" viewBox="0 0 80 80"><circle class="tick-ring" cx="40" cy="40" r="34" pathLength="1"/><path class="tick-mark" d="M26 41.5 36 51l18-20" pathLength="1"/></svg>
                    <h2>{{ ___('Order Confirmed') }}</h2>
                    <p class="otp-sub">{{ ___('Your order has been placed successfully') }}</p>
                    <b class="otp-code" id="otpOrderCode" hidden></b>
                    <div class="breakdown otp-recap" id="otpRecap" hidden></div>
                    <a class="inkbar" id="goTrack" href="#"><span>{{ ___('Track Order') }}</span></a>
                </div>
            </div>
        </section>
        @endif

        {{-- PROFILE (cream) --}}
        <section class="view" data-view="profile">
            <div class="view-scroll">
                <header class="page-head"><h1>{{ ___('Profile') }}</h1></header>
                <div class="me-card">
                    @if($customer)
                        <span class="me-avatar">{{ Str::upper(Str::substr($customer->name, 0, 1)) }}</span>
                        <div><b>{{ $customer->name }}</b><small>{{ $customer->phone ?? $customer->email }}</small></div>
                        <span class="me-badge">{{ ___('Member') }}</span>
                    @else
                        <span class="me-avatar">{{ Str::upper(Str::substr($store->title, 0, 1)) }}</span>
                        <div><b>{{ ___('Guest') }}</b><small>{{ ___('Order without an account') }}</small></div>
                        @if($authMode !== 'guest_only')<button class="minibtn ink" id="meAuthBtn" type="button">{{ ___('Login') }}</button>@endif
                    @endif
                </div>

                @if($loyaltyProgram && $customer)
                <div class="pts-card">
                    <div>
                        <small>{{ $loyaltyProgram->type === 'stamps' ? ___('Loyalty stamps') : ___('Loyalty points') }}</small>
                        <b class="pts-big" id="ptsBig">–</b>
                    </div>
                    <button class="minibtn ink" id="redeemBtn" type="button">{{ ___('Redeem') }}</button>
                </div>
                @endif

                <section class="tool-block">
                    <h3>{{ ___('Preferences') }}</h3>
                    {{-- dough shipped with no dark mode at all: no toggle, no
                         prefers-color-scheme, one palette. This follows the OS by
                         default and remembers an explicit choice per store. --}}
                    <div class="pref-row">
                        <span><svg class="ic" viewBox="0 0 24 24"><path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.6a8.7 8.7 0 1 0 11 10.9Z"/></svg><span>{{ ___('Appearance') }}</span></span>
                        <div class="seg mini" id="themeSeg">
                            <button data-theme-mode="auto" type="button">{{ ___('Auto') }}</button>
                            <button data-theme-mode="light" type="button">{{ ___('Light') }}</button>
                            <button data-theme-mode="dark" type="button">{{ ___('Dark') }}</button>
                        </div>
                    </div>
                    @if(count($menu_languages ?? []) > 1)
                    <div class="pref-row">
                        <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg><span>{{ ___('Language') }}</span></span>
                        <div class="seg mini" id="langSeg">
                            @foreach($menu_languages as $language)
                                <button data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" type="button" class="{{ get_lang() === $language->code ? 'is-on' : '' }}">{{ $language->name }}</button>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
                    <div class="pref-row">
                        <span><svg class="ic" viewBox="0 0 24 24"><path d="M12 3v18M8 7.5C8 6 9.6 5 12 5s4 1 4 2.5S14.5 10 12 10s-4 1-4 2.5S9.5 15 12 15s4 1 4 2.5-1.6 2.5-4 2.5-4-1-4-2.5"/></svg><span>{{ ___('Currency') }}</span></span>
                        <div class="seg mini" id="curSeg">
                            <button data-cur="{{ $curCode }}" data-rate="1" class="is-on" type="button">{{ $curCode ?: $curSign }}</button>
                            @foreach($mcRates as $code => $rate)
                                <button data-cur="{{ $code }}" data-rate="{{ $rate }}" type="button">{{ $code }}</button>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </section>

                @if($reservationsEnabled || $waitlistEnabled)
                <button class="line-row" id="reserveRow" type="button">
                    <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="3"/><path d="M8 3v4M16 3v4M4 10h16"/></svg>
                    <span>{{ $reservationsEnabled ? ___('Reserve a table') : ___('Join the Waitlist') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                @endif

                @if($showBranches)
                <button class="line-row" id="nearestBranchBtn" type="button">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    <span>{{ ___('Find nearest branch') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                {{-- Plain, server-rendered list of EVERY branch, reachable with no map,
                     no Leaflet and no geo round-trip — each row switches via ?branch_id=.
                     The nearest-branch map above depends on JS + Leaflet + a network hit;
                     a branch with no coordinates (or a failed map) was otherwise
                     unpickable. This list always works. --}}
                <section class="tool-block">
                    <h3>{{ ___('Our locations') }}</h3>
                    <div class="branch-list branch-all">
                        @foreach($branches as $b)
                        <a class="branch-row{{ (isset($activeBranch) && $activeBranch && $activeBranch->id == $b->id) ? ' is-on' : '' }}" href="?branch_id={{ $b->id }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            <b>{{ $b->name }}@if($b->address)<small>{{ $b->address }}</small>@endif</b>
                            <span class="branch-open{{ $b->isOpenNow() ? '' : ' closed' }}">{{ $b->isOpenNow() ? ___('Open now') : ___('Closed') }}</span>
                        </a>
                        @endforeach
                    </div>
                </section>
                @endif

                <section class="tool-block">
                    <h3>{{ ___('My orders') }}</h3>
                    @if($customer)
                        <a class="order-row" href="{{ route('customer.profile', $store->id) }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                            <div><b>{{ ___('Profile & settings') }}</b><small>{{ ___('Details, addresses & preferences') }}</small></div>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                        <a class="order-row" href="{{ route('customer.account', $store->id) }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-.9 11.4a2 2 0 0 1-2 1.85H8.5a2 2 0 0 1-2-1.85L5.6 8.4Z"/><path d="M9 8.4V6.6a3 3 0 0 1 6 0v1.8"/></svg>
                            <div><b>{{ ___('View order history') }}</b><small>{{ ___('All your orders in one place') }}</small></div>
                            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                    @else
                        {{-- Guest history. Orders placed on THIS device are remembered
                             locally the moment the server confirms them; the lookup
                             below re-hydrates them on any other device from the real
                             /order-lookup endpoint. Both feed the same rows, and every
                             row can be tracked or re-ordered. --}}
                        <div class="orders-list" id="ordersList"></div>
                        <p class="orders-empty" id="ordersEmpty">{{ ___('Orders you place appear here') }}</p>
                        {{-- owner-gated (default OFF): let a guest look an order up by its
                             order number, not only the checkout phone/email — wording only,
                             the actual gate is enforced server-side in guestOrderLookup(). --}}
                        @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
                        <p class="sheet-sub">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
                        <label class="coupon-field lookup-field">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                            <input id="lookupInput" autocomplete="off" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}">
                            <button class="minibtn" id="lookupBtn" type="button">{{ ___('Find') }}</button>
                        </label>
                        <p class="coupon-msg" id="lookupMsg" hidden></p>
                    @endif
                </section>

                @if($waNumber)
                <a class="line-row" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                    <span>{{ ___('Chat with us on WhatsApp') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </a>
                @endif

                @if($store->address || $store->phone)
                <div class="me-card store-card">
                    @if($store->address)
                    <span class="store-line">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <span><small>{{ ___('Address') }}</small><b>{{ $store->address }}</b></span>
                    </span>
                    @endif
                    @if($store->phone)
                    <a class="store-line" href="tel:{{ $store->phone }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6.5 3.5h3l1.5 4-2 1.4a12 12 0 0 0 6.1 6.1l1.4-2 4 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.7a2 2 0 0 1 2-2.2Z"/></svg>
                        <span><small>{{ ___('Phone') }}</small><b dir="ltr">{{ $store->phone }}</b></span>
                    </a>
                    @endif
                </div>
                @endif
                {{-- Catering & Events now lives in #cvCateringSheet (opened from the
                     header button); the form is included there with cateringInline=true
                     so it does not self-relocate back to the bottom of the menu. --}}

                <p class="demo-foot">{{ $store->title }}</p>
                <div class="tail-space"></div>
            </div>
        </section>

    </main>

    {{-- Notification-center panel (shared partial; bell lives in the home header). --}}
    @include('store_templates._partials.notif-bell')

    {{-- ══════════ FLOATING MENU PILL ══════════ --}}
    <div class="menupill" id="menuPill">
        <button class="mp-toggle" id="mpToggle" type="button"><i></i><i></i><span>{{ ___('Menu') }}</span></button>
        <nav class="mp-nav" id="mpNav" aria-label="{{ ___('Main') }}">
            <button class="mp-btn is-on" data-nav="home" type="button" aria-label="{{ ___('Home') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg></button>
            <button class="mp-btn" data-nav="menu" type="button" aria-label="{{ ___('Menu') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
            @if(theme_setting($store->id, 'search.enabled', true))
            <button class="mp-btn" data-nav="search" type="button" aria-label="{{ ___('Search') }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg></button>
            @endif
            @if($allow_order)
            <button class="mp-btn" data-nav="cart" type="button" aria-label="{{ ___('Cart') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-.9 11.4a2 2 0 0 1-2 1.85H8.5a2 2 0 0 1-2-1.85L5.6 8.4Z"/><path d="M9 8.4V6.6a3 3 0 0 1 6 0v1.8"/></svg><em class="mp-badge" id="mpBadge" hidden>0</em></button>
            @endif
            <button class="mp-btn" data-nav="favs" type="button" aria-label="{{ ___('Favorites') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
            <button class="mp-btn" data-nav="profile" type="button" aria-label="{{ ___('Profile') }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg></button>
        </nav>
    </div>

    {{-- ══════════ SEARCH OVERLAY ══════════ --}}
    <div class="search-overlay" id="searchOverlay" hidden>
        <div class="so-top">
            <label class="so-field">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input aria-label="{{ ___('Search pizza, sides, drinks…') }}" id="soInput" type="search" placeholder="{{ ___('Search pizza, sides, drinks…') }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
            </label>
            <button class="circlebtn" id="soClose" type="button" aria-label="{{ ___('Close') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </div>
        <div class="so-chips" id="soChips"></div>
        <div class="so-results" id="soResults"></div>
    </div>

    {{-- ══════════ SHEETS ══════════ --}}
    <div class="scrim" id="scrim" hidden></div>

    {{-- FILTER sheet (dietary / spice / allergens) — composes with the two-tier nav --}}
    @if($allowDietary)
    <div class="sheet" id="filterSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Filters') }}</h2>
        <h4>{{ ___('Dietary') }}</h4>
        <div class="chip-row" id="dietRow">
            <button data-diet="vegan" type="button">🌱 {{ ___('Vegan') }}</button>
            <button data-diet="halal" type="button">☪ {{ ___('Halal') }}</button>
            <button data-diet="gluten_free" type="button">🌾 {{ ___('Gluten-free') }}</button>
        </div>
        <h4>{{ ___('Spice level') }}</h4>
        <div class="chip-row" id="spiceRow">
            <button data-spice="1" type="button">🌶</button>
            <button data-spice="2" type="button">🌶🌶</button>
            <button data-spice="3" type="button">🌶🌶🌶</button>
        </div>
        @if(count($allergies ?? []))
        <h4>{{ ___('Exclude allergens') }}</h4>
        <div class="chip-row" id="allergenRow">
            @foreach($allergies as $a)
                <button data-al="{{ $a->id }}" type="button">{{ $a->title }}</button>
            @endforeach
        </div>
        @endif
        <div class="filter-actions">
            <button class="ghostbtn" id="filterReset" type="button">{{ ___('Reset') }}</button>
            <button class="inkbar" id="filterDone" type="button"><span>{{ ___('Show dishes') }}</span><b id="filterCount"></b></button>
        </div>
    </div>
    @endif

    {{-- CATERING & EVENTS sheet — opened from the header button (#cvCateringBtn).
         cateringInline=true keeps the shared form from self-relocating to the menu bottom. --}}
    @if(storefront_catering_enabled($store->id))
    <div class="sheet" id="cvCateringSheet" hidden>
        <div class="sheet-grab"></div>
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
    </div>
    @endif

    {{-- nearest-branch Leaflet map picker (self-hosted tiles/markers) --}}
    @if($showBranches)
    <div class="sheet" id="branchMapSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Nearest branch') }}</h2>
        <div class="branchmap" id="branchMap"></div>
        <p class="geo-attrib" id="branchMapAttrib"></p>
        <div class="branch-list" id="branchGeoList"></div>
    </div>
    @endif

    @if($loyaltyProgram && $customer)
    <div class="sheet" id="loyaltySheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Redeem Reward') }}</h2>
        <p class="sheet-sub">{{ ___('Balance') }} · <b id="loyBal">–</b> {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('pts') }}</p>
        <div class="tier-list">
            <button class="tier" id="loyRedeem" type="button">
                <b>{{ $loyaltyProgram->reward_description ?: ___('Reward') }}<small>{{ ___('at') }} {{ $loyaltyProgram->reward_threshold }} {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('pts') }}</small></b>
                <span class="tier-val" id="loyRedeemState">{{ ___('Redeem') }}</span>
            </button>
        </div>
        <p class="res-done" id="loyOk" hidden>{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
    </div>
    @endif

    @if($reservationsEnabled || $waitlistEnabled)
    <div class="sheet" id="reserveSheet" hidden>
        <div class="sheet-grab"></div>
        {{-- Explicit close (carries sheet-grab for the existing close wiring; the
             sheet-x class restyles it into a corner X button). --}}
        <button class="sheet-grab sheet-x" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2>{{ ___('Reservation') }}</h2>
        @if($reservationsEnabled && $waitlistEnabled)
        <div class="seg" id="resTabs">
            <button data-rtab="reserve" class="is-on" type="button">{{ ___('Reserve') }}</button>
            <button data-rtab="waitlist" type="button">{{ ___('Waitlist') }}</button>
        </div>
        @endif

        @if($reservationsEnabled)
        <div class="res-pane" id="resPaneR">
            @if(session('reservation_success'))
                <div class="sheet-done"><span class="sheet-done-emoji">📅</span><b>{{ ___('Request received!') }}</b><p>{{ session('reservation_success') }}</p></div>
            @else
                <form class="sheet-form" action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                    @csrf
                    <span class="field-label">{{ ___('Your Name') }}</span>
                    <input aria-label="{{ ___('Your Name') }}" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}">
                    <span class="field-label">{{ ___('Phone number') }}</span>
                    <input aria-label="{{ ___('Phone number') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}">
                    <span class="field-label">{{ ___('Date & Time') }}</span>
                    <input aria-label="{{ ___('Date & Time') }}" name="reserved_at" type="datetime-local" required min="{{ now()->format('Y-m-d\TH:i') }}">
                    <span class="field-label">{{ ___('Guests') }}</span>
                    <input aria-label="{{ ___('Guests') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                    @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                    <button class="inkbar" type="submit"><span>{{ ___('Confirm reservation') }}</span></button>
                </form>
            @endif
        </div>
        @endif

        @if($waitlistEnabled)
        <div class="res-pane" id="resPaneW" @if($reservationsEnabled) hidden @endif>
            @if(session('waitlist_joined'))
                <div class="sheet-done"><span class="sheet-done-emoji">🎉</span><b>{{ ___("You're on the list!") }}</b><p>{{ ___('Position :pos · ~:min min wait', ['pos' => session('waitlist_joined')['position'], 'min' => session('waitlist_joined')['wait_minutes']]) }}</p></div>
            @else
                <p class="sheet-sub">{{ ___('Tonight is full — join the waitlist') }}</p>
                <form class="sheet-form" action="{{ route('publicView.waitlist.join', $store->slug) }}" method="POST">
                    @csrf
                    <span class="field-label">{{ ___('Your Name') }}</span>
                    <input aria-label="{{ ___('Your Name') }}" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}">
                    <span class="field-label">{{ ___('Phone number') }}</span>
                    <input aria-label="{{ ___('Phone number') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}">
                    <span class="field-label">{{ ___('Guests') }}</span>
                    <input aria-label="{{ ___('Guests') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                    <button class="inkbar" type="submit"><span>{{ ___('Join waitlist') }}</span></button>
                </form>
            @endif
        </div>
        @endif
    </div>
    @endif

    {{-- Customer sign-in. The Profile card already rendered a "Login" button but
         nothing in the theme handled it, and dough was one of four themes missing
         partials.social-login-buttons entirely — so a customer on dough could not
         sign in at all, socially or otherwise. --}}
    @if(!$customer && $authMode !== 'guest_only')
    <div class="sheet" id="authSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Login') }}</h2>
        <div class="seg" id="authTabs">
            <button data-atab="login" class="is-on" type="button">{{ ___('Login') }}</button>
            <button data-atab="otp" type="button">{{ ___('Phone OTP') }}</button>
            <button data-atab="register" type="button">{{ ___('Register') }}</button>
        </div>
        <div class="sheet-form auth-pane" id="authPaneLogin">
            <span class="field-label">{{ ___('Email') }}</span>
            <input aria-label="{{ ___('Email') }}" id="auEmail" type="email" inputmode="email" autocomplete="email" placeholder="{{ ___('Email') }}">
            <span class="field-label">{{ ___('Password') }}</span>
            <input aria-label="{{ ___('Password') }}" id="auPass" type="password" autocomplete="current-password" placeholder="{{ ___('Password') }}">
            <button class="inkbar" id="auLogin" type="button"><span>{{ ___('Login') }}</span><span class="spinner" hidden></span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store, 'socialContext' => 'login'])
            @include('store_templates.partials.passkey-login', ['store' => $store])
        </div>
        <div class="sheet-form auth-pane" id="authPaneRegister" hidden>
            <span class="field-label">{{ ___('Your Name') }}</span>
            <input aria-label="{{ ___('Your name') }}" id="arName" autocomplete="name" placeholder="{{ ___('Your name') }}">
            <span class="field-label">{{ ___('Email') }}</span>
            <input aria-label="{{ ___('Email') }}" id="arEmail" type="email" inputmode="email" autocomplete="email" placeholder="{{ ___('Email') }}">
            <span class="field-label">{{ ___('Phone number') }}</span>
            <input aria-label="{{ ___('Phone number') }}" id="arPhone" inputmode="tel" autocomplete="tel" placeholder="{{ ___('Phone number') }}">
            <span class="field-label">{{ ___('Password') }}</span>
            <input aria-label="{{ ___('Password') }}" id="arPass" type="password" autocomplete="new-password" placeholder="{{ ___('Password') }}">
            <button class="inkbar" id="auRegister" type="button"><span>{{ ___('Register') }}</span><span class="spinner" hidden></span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store, 'socialContext' => 'register'])
        </div>
        {{-- A3 · phone-OTP login (posts the collected code under `code`; distinct
             ids from the checkout OTP gate #otpBoxes) --}}
        <div class="sheet-form auth-pane" id="authPaneOtp" hidden>
            <div id="doughOtpStep1">
                <span class="field-label">{{ ___('Phone number') }}</span>
                <input aria-label="{{ ___('Phone number') }}" id="doughOtpPhone" inputmode="tel" autocomplete="tel" placeholder="{{ ___('Phone number') }}">
                <button class="inkbar" id="doughOtpSend" type="button"><span>{{ ___('Send code') }}</span><span class="spinner" hidden></span></button>
            </div>
            <div id="doughOtpStep2" hidden>
                <span class="field-label">{{ ___('Enter the code we sent you') }}</span>
                <div class="otp-boxes otp-6" id="doughOtpBoxes">
                    <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                </div>
                <button class="inkbar" id="doughOtpVerify" type="button"><span>{{ ___('Verify') }}</span><span class="spinner" hidden></span></button>
            </div>
        </div>
        <p class="err-box auth-err" id="auErr" hidden></p>
    </div>
    @endif

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

    <div class="sheet" id="reviewSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('How was your order?') }}</h2>
        <p class="sheet-sub">{{ ___('Rate your last order') }}</p>
        <div class="stars" id="rvStars">
            <button data-star="1" type="button" aria-label="{{ ___('Rate :n out of 5', ['n' => 1]) }}">★</button><button data-star="2" type="button" aria-label="{{ ___('Rate :n out of 5', ['n' => 2]) }}">★</button><button data-star="3" type="button" aria-label="{{ ___('Rate :n out of 5', ['n' => 3]) }}">★</button><button data-star="4" type="button" aria-label="{{ ___('Rate :n out of 5', ['n' => 4]) }}">★</button><button data-star="5" type="button" aria-label="{{ ___('Rate :n out of 5', ['n' => 5]) }}">★</button>
        </div>
        @if($googleReviewUrl)
        <a class="inkbar" id="rvGoogle" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener" hidden>
            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>
            <span>{{ ___('Rate us on Google') }}</span>
        </a>
        @endif
        <p class="res-done" id="rvThanks" hidden>{{ ___('Thanks for your feedback!') }}</p>
    </div>

    {{-- full-screen story viewer (fed by the same /stories payload as .stories) --}}
    <div class="story-viewer" id="storyViewer" hidden>
        <div class="sv-progress" id="svProgress" aria-hidden="true"></div>
        <button class="sv-close" id="svClose" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="sv-stage" id="svStage">
            <img class="sv-media" id="svMedia" alt="" data-csp-error="opacity">
            <button class="sv-nav prev" id="svPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="sv-nav next" id="svNext" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="sv-foot">
                <p class="sv-caption" id="svCaption"></p>
                <a class="sv-cta" id="svCta" target="_blank" rel="noopener" hidden><span></span></a>
            </div>
        </div>
    </div>

    <div class="toast" id="toast" role="status"></div>
    <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.DOUGH = {
        slug:           @json($store->slug),
        storeId:   @json($store->id),
        restaurantName: @json($store->title),
        {{-- FEATURES-5 Track A — enabled social providers for the JS-built auth sheet --}}
        {{-- {!! !!} + json_encode instead of @json: Blade's compileJson comma-explodes multi-arg expressions (broke these 4 themes' pages) --}}
        socialLogin:    {!! json_encode(collect(app(\Juwri\Modules\Auth\Services\Social\SocialCredentialResolver::class)->enabledProviders($store->id))->map(fn($p)=>['provider'=>$p,'label'=>ucfirst($p),'url'=>route('customer.social.redirect',[$store->id,$p])])->values(), 15) !!},
        {{-- ZERO-TOUCH A3.2 — agent chat data seam (theme-built UI; no forced partial). enabled:false when owner/plan off --}}
        agentChat:      @json(\App\Support\AgentChatWidget::seam($store)),
        curSign:        @json($curSign),
        curGap:         @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        curPos:         @json($curPos),
        {{-- TOTALS-CONTRACT rule 2: rounding is the RESTAURANT's, not the app's.
             Order::computeTax() rounds with currency_decimals($postOptions->currency_code),
             so a 3-decimal store (BHD/KWD/OMR…) diverges by fils if the theme quantises
             at the app default. --}}
        currencyDecimals: @json(currency_decimals($postOptions->currency_code ?? null)),
        moneyDecimals: @json(money_decimals($postOptions->currency_code ?? null)), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        baseCurrency:   @json($curCode),
        currencyRates:  @json((object) $mcRates),
        taxCharge:      @json($taxCharge),
        taxType:        @json($taxType),
        taxLabel:       @json($taxLabel),
        deliveryCharge: @json($deliveryCharge),
        minOrderValue:  @json($minOrderValue),
        allowOrder:     @json((bool) $allow_order),
        allowPayment:   @json((bool) $allow_payment),
        allowDelivery:  @json((bool) $allow_delivery),
        allowTakeaway:  @json((bool) $allow_takeaway),
        allowOnTable:   @json((bool) $allow_on_table),
        forceTable:     @json((bool) $table),
        defaultTable:   @json($table->table_no ?? null),
        authMode:       @json($authMode),
        isLoggedIn:     @json((bool) $customer),
        customerName:   @json($customer->name ?? null),
        customerPhone:  @json($customer->phone ?? null),
        csrf:           @json(csrf_token()),
        otpGateEnabled: @json((bool) $otpGateEnabled),
        customerToken:  @json($customerApiToken),
        isFollowing:    @json((bool) $isFollowing),
        followerCount:  @json((int) $followerCount),
        loyaltyType:      @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json((int) ($loyaltyProgram->reward_threshold ?? 0)),
        googleReviewUrl:  @json($googleReviewUrl ?: null),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        placeholderImg: @json($doughPlaceholder),
        leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
        subParents:     @json(array_map('strval', array_keys($subCatsOf))),
        items:          @json((object) $jsItems),
        @php
            /* menu_categories is top-level only, but an item's category_id can point at a
               subcategory — so a parent chip must also claim its children's items or it
               renders empty (e.g. a Drinks category whose items all live in Hot/Cold). */
            $catList = [['id' => 'all', 'name' => ___('All'), 'emoji' => '✦']];
            $catKids = [];
            foreach ($categories as $ci => $cat) {
                $catList[] = ['id' => $cat->id, 'name' => $cat->name, 'emoji' => $catEmojis[$ci % count($catEmojis)]];
                $kids = collect($cat->subcategories ?? [])->pluck('id')->map(fn($id) => (string) $id)->values()->all();
                if ($kids) $catKids[(string) $cat->id] = $kids;
            }
            $catList[] = ['id' => 'favs', 'name' => ___('Favorites'), 'emoji' => '♥'];
            /* Hero headlines are ONE key each, not one key per stacked line. Per-word keys
               ('single', 'day') are generic and reused elsewhere, so no value can satisfy
               both this headline's grammar and its other call sites — every non-English
               locale rendered a half-translated hero. The '|' inside the translated value
               marks where the translator wants the line to break; the theme stacks each
               piece as its own animated span. No '|' simply yields one wrapping line, so a
               locale that pipes nothing still reads correctly. */
            $obLines = fn (string $s) => array_values(array_filter(array_map('trim', explode('|', $s)), 'strlen')) ?: [$s];
            $obSlides = [
                ['lines' => $obLines(theme_setting($store->id, 'splash.title', ___('Hand-stretched dough,|wood-fired daily'))), 'sub' => $store->title],
                ['lines' => $obLines(___('Order in a couple of taps')), 'sub' => ___('Delivery, pickup or served at your table.')],
                ['lines' => $obLines(___('Fresh every single day')), 'sub' => ___('Made to order, just for you.')],
            ];
        @endphp
        categories:     @json($catList),
        catKids:        @json((object) $catKids),
        obSlides:       @json($obSlides),
        endpoints: {
            submitOrder:  @json(route('store.submitOrder', $store->id)),
            orderLookup: @json(route('store.orderLookup', $store->id)),
            nps:         @json(route('store.submitNps', $store->slug)),
            login:       @json(route('customer.login', $store->id)),
            register:    @json(route('customer.register', $store->id)),
            otpRequest:  @json(route('customer.otp.request', $store->id)),
            otpVerifyLogin: @json(route('customer.otp.verify', $store->id)),
            coupon:     @json(route('store.applyCoupon', $store->id)),
            waiter:     @json(route('store.callTheWaiter', $store->id)),
            otpSend:    @json(route('checkout.otp.send')),
            otpVerify:  @json(route('checkout.otp.verify')),
            loyaltyBalance: @json($loyaltyProgram ? route('publicView.loyalty.balance', $store->id) : null),
            loyaltyRedeem:  @json($loyaltyProgram ? route('publicView.loyalty.redeem', $store->id) : null),
            branchesGeo:    @json($showBranches ? route('geo.branches', $store->slug) : null),
            stories:        @json(route('stories.public', $store->slug)),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:  @json(route('geo.reverse')),
            follow:      @json(url('r/'.$store->slug.'/follow')),
        },
        @php
            $i18n = [
                'popular' => ___('Popular'), 'favorites' => ___('Favorites'),
                'daily_special' => theme_setting($store->id, 'texts.featured_title', ___('Daily special')), 'no_results' => ___('Nothing here — try another category'),
                'size' => ___('Size'), 'sold_out' => ___('Sold out'), 'optional' => ___('Optional'),
                'choose' => ___('Please choose an option'), 'choose_one' => ___('Choose one'),
                'tax_label' => $taxLabel, 'total' => ___('Total'), 'reorder' => ___('Reorder'),
                'order_placed' => ___('Order placed'), 'view_order' => ___('View order'),
                'orders_none' => ___('No orders found for that phone/email.'),
                'popular_badge' => ___('Popular'), 'add_to_cart' => ___('Add to cart'),
                'order_failed' => ___('We could not place your order. Please try again.'),
                'rating_thanks' => ___('Thanks for your feedback!'),
                'added' => ___('Added to cart'), 'removed' => ___('Removed'), 'remove' => ___('Remove'),
                'kcal' => ___('kcal'), 'min' => ___('min'), 'vegan' => ___('Vegan'), 'halal' => ___('Halal'),
                'gluten_free' => ___('Gluten-free'), 'add_note' => ___('Add note'), 'note_ph' => ___('No onions please…'),
                'when' => ___('When'), 'asap' => ___('ASAP'), 'later' => ___('Later'), 'table' => ___('Table'),
                'addr_ph' => ___('Street, building, floor…'), 'delivery_hint' => ___('Delivered to your door'),
                'pickup_hint' => ___('Pickup at the counter'), 'table_hint' => ___('We will bring it to your table'),
                'coupon_ok' => ___('Coupon applied'), 'coupon_bad' => ___('That code does not work'),
                'your_order' => ___('Your order'), 'edit' => ___('Edit'),
                'subtotal' => ___('Subtotal'), 'discount' => ___('Discount'), 'tip_label' => ___('Tip'),
                'delivery' => ___('Delivery'), 'min_order' => ___('Minimum order'),
                'fill_contact' => ___('Add your name & phone first'), 'fill_addr' => ___('Add a delivery address'),
                'select_type' => ___('Please choose an order type'),
                'code_incomplete' => ___('Enter the code'), 'code_bad' => ___('Wrong code — please try again'),
                'sent' => ___('Code sent'), 'waiter_ok' => ___('A waiter is on the way'),
                'net_err' => ___('Network error. Please try again.'), 'points_earned' => ___('Points earned!'),
                'reward_redeemed' => ___('Reward redeemed!'), 'not_enough' => ___('Not enough points yet'),
                'thanks' => ___('Thanks for your feedback!'), 'search_none' => ___('No matches'),
                'order_now' => ___('Order Now'), 'stories' => ___('Stories'),
                'room_service' => ___('Room service'), 'room_hint' => ___('We will bring it to your room'),
                'use_location' => ___('Use my location'), 'loc_unavailable' => ___('Location unavailable'),
                'loc_denied' => ___('Location denied'), 'no_address' => ___('No address found'),
                'reviews_title' => ___('Reviews'), 'reviews_empty' => ___('No reviews yet.'),
                'rate_dish' => ___('Rate this dish'),
            ];
        @endphp
        i18n: @json($i18n),
    };
</script>
@if($showBranches)
{{-- self-hosted Leaflet — must load before script.js (layout appends script.js after this stack) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
@endif
@endpush

@push('scripts_at_bottom')
{{-- P6-301 — consume the previous-order payload the account page wrote to
     localStorage (cv_reorder_<slug>) and add each line to dough's cart. The
     shared helper (assets/store_templates/_shared/js/reorder-pickup.js) is
     loaded by store_templates.layout, but the call must run AFTER the theme's script.js (loaded by
     store_templates.layout) so window.__doughPickup + window.mbToast are
     defined. `load` fires after every sync script on the document. --}}
<script nonce="{{ csp_nonce() }}">
window.addEventListener('load', function () {
    if (!window.JuwriReorder || !window.JuwriReorder.pickup) return;
    window.JuwriReorder.pickup(@json($store->slug ?? ''), window.__doughPickup || function () { return false; }, {
        toast: window.mbToast || null,
    });
});
</script>
@endpush

@push('scripts_at_bottom')
{{-- ZERO-TOUCH A3.1 — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endpush
