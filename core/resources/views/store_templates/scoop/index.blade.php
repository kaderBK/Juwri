{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       scoop — light green-wave ordering theme · data preparation
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus   = collect($total_menus)->values();
    $items      = $allMenus->values();

    /* Two-tier category nav (primary parent chips + a secondary sub-category chip row
       that swaps per active parent). menu_categories() is top-level only, but a menu
       item keys off its OWN category_id (possibly a subcategory), so each JS item
       carries BOTH 'cat' (real → sub-chip filter) and 'root' (top-level ancestor →
       parent-chip filter). $rootOf maps any category id → its top-level ancestor. */
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
    $deliveryCharge  = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge       = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    /* TOTALS CONTRACT — `restaurant_tax_type` has two modes ('percentage' | 'fixed').
       The client must mirror Order::computeTax() exactly, so the mode has to reach it. */
    $taxType         = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');
    $taxLabel        = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
    /* the owner's real prep window ("25-35"), used for honest order-type hints
       instead of the demo's invented "delivered chilled in 20–30 min" */
    $prepEtaText     = trim((string) (@$postOptions->prep_eta_static ?: ''));
    $minOrderValue   = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched = !empty($postOptions->allow_order_scheduling);
    $allowCoupons    = @$postOptions->allow_coupons == 1;
    $allowTips       = @$postOptions->allow_tips == 1;
    $allowWaiter     = !empty($postOptions->allow_call_waiter);
    $allowDietary    = !isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;
    $isOpen          = store_open_now($store);

    $featured = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    $splashImgs = \Juwri\Core\Support\ViewHelpers::imageItems($items, 3);

    /* helper: human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* JS data map — same contract as aurora (variants absolute, groups/extras additive) */
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
        /* the owner's own name for the variant axis ("Style", "Size"…). The demo
           hardcoded "Base ice cream" here, which is nonsense on any other menu. */
        $variantGroupLabel = collect($m->variantOptions ?? [])
            ->map(fn($vo) => trim((string) $vo->title))->filter()->implode(' · ');
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
            /* descriptions are stored as numeric HTML entities — decode, or the
               literal &#1605;… leaks into the card text AND the search index */
            'desc'  => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'img'   => media_url('storage/menu/', $m->image, $store->id ?? null),
            'price' => (float) $m->price,
            'type'  => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'=> $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : '',
            'rating_count' => (int) ($m->rating_count ?? 0),
            'reviewList' => \Juwri\Core\Support\ViewHelpers::reviewList($reviewsByMenu->get($m->id, collect())),
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyList($itemAllergies),
            'variants' => $variants,
            'variantGroupLabel' => $variantGroupLabel,
            'extras'   => $extras,
            'is_vegan'         => (bool) $m->is_vegan,
            'is_halal'         => (bool) $m->is_halal,
            'is_gluten_free'   => (bool) $m->is_gluten_free,
            'is_popular'       => (bool) $m->is_popular,
            'is_daily_special' => (bool) ($m->is_daily_special ?? false),
            'spice_level'      => (int) $m->spice_level,
            'is_sold_out'      => (bool) $m->is_sold_out,
            'track_inventory'  => (bool) ($m->track_inventory ?? false),
            'stock_qty'        => isset($m->stock_qty) && $m->stock_qty !== null ? (int) $m->stock_qty : null,
            'low_stock_threshold' => isset($m->low_stock_threshold) && $m->low_stock_threshold !== null ? (int) $m->low_stock_threshold : null,
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
    // (pure containers / empty parents drop). $catList = parent pills only.
    $parentCats = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);
    $catList = $parentCats
        ->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name])
        ->values();

    // Secondary chips: per parent, only the subcategories that own items. A parent
    // with no item-bearing subcategory gets no second row (its items live directly on
    // it and show under the parent chip alone).
    $jsSubCats = [];
    foreach ($parentCats as $c) {
        $subs = \Juwri\Core\Support\ViewHelpers::withItems($c->subcategories, $catItemCount);
        if ($subs->isNotEmpty()) {
            $jsSubCats[(string) $c->id] = $subs->map(fn ($s) => [
                'id'   => (string) $s->id,
                'name' => $s->name,
            ])->values();
        }
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

    /* F2 · follow / verified brand (crave parity). Mint (and session-cache) a raw
       CustomerToken for the signed-in session customer so the theme JS can call the
       Bearer-guarded follow endpoint. */
    $customerApiToken = storefront()->customerApiToken($customer, 'scoop');
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
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400..800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- self-hosted Leaflet (nearest-branch picker; nonce/CSP-safe, tiles load as https imgs) --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
@endpush

@section('content')
<div class="stage scoop" id="scoop" style="--scoop-brand: {{ theme_setting($store->id, 'colors.primary', '#F5C518') }}">
  <div class="phone" id="phone" data-mode="dark">
    <div class="app" id="app">

      {{-- ══════════ SPLASH ══════════ --}}
      {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
           `splash.style` picks which one, so the bespoke intro and the shared layer
           can never stack. Defaults (enabled + theme) render this one unchanged. --}}
      @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
      <div class="splash" id="splash">
        <span class="dotgrid sp-a" aria-hidden="true"></span>
        <span class="dotgrid sp-b" aria-hidden="true"></span>
        <h1 class="splash-brand">{{ $store->title ?: 'scoop' }}<span>.</span></h1>
        <p class="splash-tag">{{ $store->sub_title ?: ___('Order in a few taps') }}</p>
        <div class="splash-hero" aria-hidden="true">
          <div class="splash-blob"></div>
          @if($featured && $featured->image)
            @if((string) (media_url('storage/menu/', $featured->image, $store->id ?? null)) !== '')<img class="splash-cup" src="{{ media_url('storage/menu/', $featured->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
          @endif
          @if(isset($splashImgs[1]) && $splashImgs[1]->image)
            @if((string) (media_url('storage/menu/', $splashImgs[1]->image, $store->id ?? null)) !== '')<img class="splash-mini m-a" src="{{ media_url('storage/menu/', $splashImgs[1]->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
          @endif
          @if(isset($splashImgs[2]) && $splashImgs[2]->image)
            @if((string) (media_url('storage/menu/', $splashImgs[2]->image, $store->id ?? null)) !== '')<img class="splash-mini m-b" src="{{ media_url('storage/menu/', $splashImgs[2]->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
          @endif
        </div>
        <button class="splash-skip" id="splashSkip" data-i18n="skip">{{ ___('Skip') }}</button>
      </div>
      {{-- The intro plays once per browser session, not on every reload. This runs
           during parse (before first paint), so a reload or an in-session revisit
           never flashes the splash again; script.js honours the same flag for its
           auto-dismiss timing. --}}
      <script nonce="{{ csp_nonce() }}">
        try { if (sessionStorage.getItem('scoop_splash_seen') === '1') document.getElementById('splash').classList.add('is-done'); } catch (e) {}
      </script>
      @endif

      {{-- ══════════ VIEWS ══════════ --}}
      <main class="views" id="views">

        {{-- HOME · yellow catalog --}}
        <section class="view is-active" data-view="home" data-mode="yellow">
          @php
              $__scHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
              $scCoverUrl = $__scHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
              $scShowCover = $__scHasCover && theme_setting($store->id, 'brand.show_cover', true);
          @endphp
          {{-- Collapsing header: slides in once the arc cover leaves the scroll
               container (scoop's chips are not top-sticky, so no overlap guard). --}}
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
            @if($scShowCover)
            {{-- Cover clipped by one big convex arc (like looking over a scoop); the
                 header title + tools sit over it in white, fading to the yellow. --}}
            <div class="scoop-cover">
              <div class="scoop-cover-media" aria-hidden="true">
                @if((string) ($scCoverUrl) !== '')<img class="scoop-cover-img" src="{{ $scCoverUrl }}" alt="" data-csp-error="hide">@endif
                <span class="scoop-cover-scrim"></span>
              </div>
            @endif
            <header class="y-head">
              {{-- Profile / account — sits where the section title used to, opens the
                   profile view through the shared [data-go] click delegate (no JS). --}}
              <button class="toolbtn" id="avatarBtn" type="button" data-go="profile" aria-label="{{ ___('Profile') }}">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
              </button>
              <div class="y-tools">
                @if(count($menu_languages ?? []) > 1)
                {{-- Language: a globe opening the sheet below, so the header holds one
                     control whether the store runs two languages or eleven. The shared
                     language-switch partial delegates on [data-lang-code]. --}}
                <button class="toolbtn" id="langBtn" type="button" aria-label="{{ ___('Language') }}" aria-haspopup="dialog">
                  <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                </button>
                @endif
                @if(storefront_catering_enabled($store->id))
                {{-- Catering & Events: opens #cvCateringSheet (wired in script.js). --}}
                <button class="toolbtn" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                </button>
                @endif
                <button class="toolbtn" id="filterBtn" aria-label="{{ ___('Filter') }}">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M4 5h16l-6.2 7.2V18l-3.6 2.2v-8L4 5Z"/></svg>
                  <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                  <i class="dot" id="filterDot" hidden></i>
                </button>
                <button class="toolbtn" id="gridBtn" aria-label="{{ ___('Toggle layout') }}">
                  <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg>
                </button>
                {{-- Customizer v2: `search.enabled` gates the buttons that reveal the
                     search field, and the collapsed `#searchPop` block below, now that
                     script.js null-checks those nodes before reading them. --}}
                @if(theme_setting($store->id, 'search.enabled', true))
                <button class="toolbtn" id="searchBtn" aria-label="{{ ___('Search') }}">
                  <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                </button>
                @endif
                {{-- Shared notification-center bell (rev/notif-center-spec.md); panel + JS from _partials/notif-bell. --}}
                <button class="toolbtn" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                  <i class="dot" id="mbNotifDot" hidden></i>
                </button>
              </div>
            </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


            {{-- R4 · live open/closed status, driven by $isOpen (computed from
                 open_close_store at the top of this blade). Sits under the header
                 on the yellow home so a diner sees the store state before ordering. --}}
            <div class="status-strip">
              <span class="open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
            </div>
            @if($scShowCover)
            </div>{{-- /.scoop-cover --}}
            @endif

            {{-- F2 · brand / follow row (crave parity; B10: customizer section, default on) --}}
            @if($sectionVisible['brand_row'] ?? true)
            <div class="brand-row{{ $scShowCover ? ' brand-row--hero' : '' }}" id="cvBrandRow" data-tc-section="brand_row" data-mb-hotel-chip-slot>
              <span class="brand-logo">
                @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
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

            {{-- B8 · store-review display — avg rating + up to 2 approved cards.
                 Self-gated: renders only when the reviews module is on and the
                 store has approved reviews (dark today), so it ships dormant. --}}
            @if($aboutRating !== null)
            <div class="sc-reviews" id="scReviews" data-mb-reviews-end>
              <div class="sc-rating"><span class="sc-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
              @if($aboutReviews->isNotEmpty())
              <div class="sc-review-list">
                @foreach($aboutReviews as $rev)
                <div class="sc-review">
                  <div class="sc-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="sc-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                  @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                </div>
                @endforeach
              </div>
              @endif
            </div>
            @endif

            {{-- SEARCH-RUN: the search popup was relocated to the app root (below
                 </main>) and upgraded to an orbit-style full-screen overlay with
                 live result rows, so position:fixed is never trapped by a view. --}}

            {{-- stories rail — stays hidden (and takes no space, see the global
                 [hidden] guard) unless the stories endpoint returns rows --}}
            <div class="stories" id="storyRail" hidden aria-label="{{ ___('Stories') }}"></div>

            @if(!empty($menuBanners) && count($menuBanners))
            {{-- admin-managed promo banners --}}
            <div class="banners" id="bannerRail">
              @foreach($menuBanners as $bi => $banner)
                @php $bLink = storefront_localized_link($banner->link_url) ?: ''; @endphp
                <a class="banner {{ $bi % 2 === 1 ? 'alt' : '' }}" @if($bLink) href="{{ $bLink }}" target="_blank" rel="noopener" @else role="img" @endif>
                  <span class="banner-copy">
                    @if($banner->subtitle)<span class="banner-kicker">{{ $banner->subtitle }}</span>@endif
                    <span class="banner-title">{{ $banner->title }}</span>
                    @if($bLink)<span class="banner-btn" data-tc-text="texts.order_now">{{ theme_setting($store->id, 'texts.order_now', ___('Order now')) }}</span>@endif
                  </span>
                  @if($banner->image)
                    @if((string) ($banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null)) !== '')<img class="banner-img" src="{{ $banner->image_url ?? media_url('storage/menu_banners/', $banner->image, $store->id ?? null) }}" alt="">@endif
                  @endif
                </a>
              @endforeach
            </div>
            @endif

            {{-- Customizer v2: `daily_specials` section toggle — the promo card's
                 click handler is already `?.`-bound, so the block can vanish whole. --}}
            @if($sectionVisible['daily_specials'] ?? true)
            <button class="promo" id="promoCard" @if($featured) data-open="{{ $featured->id }}" @endif>
              <span class="promo-copy">
                <span class="promo-kicker" data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___("Today’s pick")) }}</span>
                <span class="promo-title">{{ $featured?->name ?: ___('Explore the menu') }}</span>
                <span class="promo-code">{{ ___('Tap to customise & order') }}</span>
              </span>
              @if($featured && $featured->image)
                @if((string) (media_url('storage/menu/', $featured->image, $store->id ?? null)) !== '')<img class="promo-img" src="{{ media_url('storage/menu/', $featured->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
              @endif
            </button>
            @endif

            {{-- Category selector sits DIRECTLY above the menu it filters: promo card,
                 banners and stories all live above it now, so nothing (and no injected
                 offers/order-type strip) wedges between the categories and the items.
                 data-mb-grid-lead tells the shared anchor to drop injected sections
                 ABOVE this row rather than between it and the grid. --}}
            @if($sectionVisible['catbar'] ?? true)
            <nav class="tabs" id="homeTabs" data-mb-grid-lead data-mbt-anchor aria-label="{{ ___('Categories') }}"></nav>
            {{-- secondary sub-category chips — revealed by JS only when the active
                 parent has item-bearing subcategories --}}
            <nav class="subtabs" id="homeSubTabs" data-mb-grid-lead hidden aria-label="{{ ___('Sub-categories') }}"></nav>
            @endif

            {{-- Customizer v2: `menu` section toggle — renderGrid() bails when
                 #grid is gone, and #gridEmpty ships `hidden` so nothing surfaces. --}}
            @if($sectionVisible['menu'] ?? true)
            <div class="stagger" id="grid"></div>
            @endif

            <div class="empty" id="gridEmpty" hidden>
              <svg viewBox="0 0 64 64" class="empty-art"><path d="M22 26a10 10 0 0 1 20 0"/><path d="M20 26h24L32 52 20 26Z"/><path d="M27 33h10M30 40h4"/></svg>
              <p data-i18n="no_results">{{ ___('Nothing matches — try clearing the filters') }}</p>
              <button class="blackpill sm" id="clearAll" data-i18n="clear_filters">{{ ___('Clear filters') }}</button>
            </div>
            <div class="tail-space"></div>
          </div>
        </section>

        {{-- PRODUCT · dark item detail --}}
        <section class="view view-sub" data-view="product" data-mode="dark">
          <div class="view-scroll">
            <header class="d-head">
              <h1 class="d-title" id="pName"></h1>
              @if(theme_setting($store->id, 'search.enabled', true))
              <button class="toolbtn dark" id="pSearchBtn" aria-label="{{ ___('Search') }}">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
              </button>
              @endif
            </header>

            <nav class="tabs dark" id="pTabs" aria-label="{{ ___('Breadcrumbs') }}"></nav>

            <div class="p-stagewrap">
              <span class="dotgrid p-dots" aria-hidden="true"></span>
              <span class="p-ring" aria-hidden="true"></span>
              {{-- no src= here on purpose: an empty src makes the browser refetch the page. JS sets it. --}}
              <img class="p-photo" id="pImg" alt="">
              <aside class="p-rail">
                <button class="rail-fav" id="pFav">
                  <span data-i18n="favourite">{{ ___('Favourite') }}</span>
                  <svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
                </button>
                <button class="rail-btn" id="pShare" aria-label="{{ ___('Share') }}">
                  <svg class="ic" viewBox="0 0 24 24"><path d="m14 5 5 5-5 5"/><path d="M19 10H10a6 6 0 0 0-6 6v3"/></svg>
                </button>
              </aside>
            </div>

            <div class="p-buy">
              <small class="p-stock" id="pStock"></small>
              <div class="p-price" id="pPrice"></div>
            </div>

            <div class="p-meta" id="pMeta"></div>

            <section class="optcard">
              <header class="optcard-head">
                <span class="optcard-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M8 10a4.5 4.5 0 0 1 9 0"/><path d="M6.5 10h12l-6 11-6-11Z"/><path d="M10 13.5h5"/></svg></span>
                {{-- filled by JS with the OWNER's own variant-group name ("Style", "Size"…);
                     the demo hardcoded "Base ice cream" on every menu in the product. --}}
                <b id="baseLabel">{{ ___('Choose an option') }}</b>
              </header>
              <div class="swatches" id="baseSwatches"></div>
            </section>

            {{-- #global-modifiers: one optcard per option group, rendered by JS --}}
            <div id="pGroups"></div>

            <section class="optcard" id="extrasCard">
              <header class="optcard-head">
                <span class="optcard-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M12 4l1.6 4.4L18 10l-4.4 1.6L12 16l-1.6-4.4L6 10l4.4-1.6L12 4Z"/><path d="m18.5 15 .7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7.7-2Z"/></svg></span>
                <b data-i18n="toppings">{{ ___('Extras') }}</b>
                <span class="optcard-tag" data-i18n="optional">{{ ___('optional') }}</span>
              </header>
              <div class="extras" id="extraList"></div>
            </section>

            <p class="p-desc"><b data-i18n="whats_inside">{{ ___('Description') }}</b><span id="pDesc"></span></p>
            <section class="optcard product-reviews" id="pReviews"></section>
            <div class="tail-space tall"></div>
          </div>

          <footer class="p-cta">
            <button class="roundback" data-back aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
            <div class="qty" id="pQty">
              <button class="qty-btn" data-q="-1" aria-label="{{ ___('Decrease') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
              <output id="pQtyVal">1</output>
              <button class="qty-btn" data-q="1" aria-label="{{ ___('Increase') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
            </div>
            <button class="tocart" id="addToCart">
              <span data-i18n="to_cart">{{ ___('To cart') }}</span>
              <svg class="ic" viewBox="0 0 24 24"><circle cx="9.5" cy="19.5" r="1.6"/><circle cx="17" cy="19.5" r="1.6"/><path d="M3 4h2l2.3 11h10.4L20 8H6.1"/></svg>
              <b id="pTotal"></b>
            </button>
            {{-- shown by script.js once the cart has items: a direct jump to the cart --}}
            <button class="p-cart-jump" id="pCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="9.5" cy="19.5" r="1.6"/><circle cx="17" cy="19.5" r="1.6"/><path d="M3 4h2l2.3 11h10.4L20 8H6.1"/></svg>
              <i class="p-cart-jump-count" id="pCartJumpCount" aria-hidden="true">0</i>
            </button>
          </footer>
        </section>

        {{-- FAVOURITES · yellow --}}
        <section class="view view-sub" data-view="favs" data-mode="yellow">
          <div class="view-scroll">
            <header class="y-head sub">
              <button class="roundback onyellow" data-back aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
              <h1 class="y-title sm" data-i18n="favourites">{{ ___('Favourites') }}</h1>
            </header>
            <div class="stagger" id="favGrid"></div>
            <div class="empty" id="favEmpty" hidden>
              <svg viewBox="0 0 64 64" class="empty-art"><path d="M32 50s-18-11-18-24c0-7 5.4-12 11.2-12 3.4 0 5.6 2 6.8 3.8C33.2 16 35.4 14 38.8 14 44.6 14 50 19 50 26c0 13-18 24-18 24Z"/></svg>
              <p data-i18n="fav_empty">{{ ___('Tap the heart on any dish to keep it here') }}</p>
            </div>
            <div class="tail-space"></div>
          </div>
        </section>

        {{-- CART · dark --}}
        <section class="view" data-view="cart" data-mode="dark">
          <div class="view-scroll">
            <header class="d-head">
              <h1 class="d-title sm" data-i18n="cart_title">{{ ___('Cart') }}</h1>
              <button class="ghostbtn sm" id="clearCart" data-i18n="clear">{{ ___('Clear') }}</button>
            </header>

            <div class="cart-list" id="cartList"></div>
            <div class="empty dark" id="cartEmpty" hidden>
              <svg viewBox="0 0 64 64" class="empty-art"><circle cx="26" cy="52" r="4"/><circle cx="46" cy="52" r="4"/><path d="M10 12h6l6 30h28l6-22H20"/></svg>
              <p data-i18n="cart_empty">{{ ___('Your cart is empty') }}</p>
              <button class="yellowpill sm" data-go="home" data-i18n="browse_menu">{{ ___('Browse the menu') }}</button>
            </div>

            <div class="cart-tools" id="cartTools">
              <section class="block">
                <h3 data-i18n="order_type">{{ ___('Order type') }}</h3>
                <div class="seg" id="orderTypeSeg">
                  <button data-ot="delivery" class="is-on"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg><span data-i18n="ot_delivery">{{ ___('Delivery') }}</span></button>
                  <button data-ot="pickup"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><span data-i18n="ot_pickup">{{ ___('Pickup') }}</span></button>
                  <button data-ot="dinein"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg><span data-i18n="ot_dinein">{{ ___('Dine-in') }}</span></button>
                  {{-- Hotel in-room dining. $allow_room_service is 1 only when hotel mode is on
                       AND the scanned QR resolved to an area_type='room' table, so this never
                       renders for an ordinary restaurant. data-ot is the theme-local name;
                       OT_SERVER in script.js sends it to the server as `room_service`.
                       Like its siblings it is ALSO gated at runtime by applyConfigGates()
                       via CFG.allowRoomservice / OT_ALLOWED. --}}
                  @if($allow_room_service)<button data-ot="roomservice"><svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 6v2M10.5 6h3"/></svg><span data-i18n="ot_roomservice">{{ ___('Room service') }}</span></button>@endif
                </div>
                <div class="ot-fields" id="otFields"></div>
                {{-- Order scheduling is an owner option; every sibling theme gates
                     this row on it — an ASAP/Later picker on a store that never
                     enabled scheduling promises a feature the server will ignore. --}}
                @if($allowOrderSched)
                <div class="schedule-row">
                  <span class="sched-label"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg><span data-i18n="when">{{ ___('When') }}</span></span>
                  <div class="seg mini" id="whenSeg">
                    <button data-when="asap" class="is-on" data-i18n="asap">{{ ___('ASAP') }}</button>
                    <button data-when="later" data-i18n="later">{{ ___('Later') }}</button>
                  </div>
                  <select aria-label="{{ ___('Schedule for later') }}" id="whenTime" class="when-time" hidden></select>
                </div>
                @endif
                <div class="waiter-row" id="waiterRow" hidden>
                  <button class="ghostbtn" id="callWaiter"><svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span data-i18n="call_waiter">{{ ___('Call waiter') }}</span></button>
                </div>
              </section>

              <section class="block">
                <label class="coupon-field">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                  <input id="couponInput" data-i18n-ph="coupon_ph" placeholder="{{ ___('Promo code') }}" enterkeyhint="done">
                  <button class="minibtn" id="couponApply" data-i18n="apply">{{ ___('Apply') }}</button>
                  {{-- an applied coupon must be removable without retyping over the field --}}
                  <button class="minibtn coupon-clear" id="couponClear" hidden aria-label="{{ ___('Remove coupon') }}">
                    <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                  </button>
                </label>
                <p class="coupon-msg" id="couponMsg" hidden></p>
              </section>

              <section class="totals" id="totalsBox"></section>

              @if($waNumber)
              <button class="wa-order" id="waOrder">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/><path d="M9 8.5c-.5 2.5 3 6 5.5 6.5.9.2 1.8-.4 2-1.2l.1-.6-2-1.2-1 1c-1-.4-2.3-1.7-2.7-2.7l1-1-1.2-2-.6.1c-.7.2-1 .6-1.1 1.1Z" fill="none"/></svg>
                <span data-i18n="wa_order">{{ ___('Order via WhatsApp instead') }}</span>
              </button>
              @else
              <button class="wa-order" id="waOrder" hidden></button>
              @endif
            </div>
            <div class="tail-space"></div>
          </div>
          <footer class="p-cta lift" id="cartCta" hidden>
            <button class="tocart wide" id="goCheckout"><span data-i18n="checkout">{{ ___('Checkout') }}</span><b id="cartCtaTotal"></b></button>
          </footer>
        </section>

        {{-- CHECKOUT · dark --}}
        <section class="view view-sub" data-view="checkout" data-mode="dark">
          <div class="view-scroll">
            <header class="d-head sub">
              <button class="roundback" data-back aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
              <h1 class="d-title sm" data-i18n="payment">{{ ___('Payment') }}</h1>
            </header>

            <section class="block">
              <h3 data-i18n="contact">{{ ___('Your details') }}</h3>
              <div class="field-duo">
                <input aria-label="{{ ___('Your name') }}" id="custName" data-i18n-ph="your_name" placeholder="{{ ___('Your name') }}" autocomplete="name">
                <input aria-label="{{ ___('Phone') }}" id="custPhone" data-i18n-ph="phone" placeholder="{{ ___('Phone') }}" inputmode="tel" autocomplete="tel">
              </div>
            </section>

            <section class="block" id="addrBlock">
              <h3 data-i18n="delivery_address">{{ ___('Delivery address') }}</h3>
              {{-- saved addresses (logged-in): tap to fill the field + coords --}}
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
              {{-- R6 · guest saved addresses — chips from localStorage
                   (scoop_recent_addrs_<slug>), rendered by renderRecentAddrs() for
                   guests only; a tap fills #custAddr + the hidden #custLat/#custLng. --}}
              @if(!$customer)
              <div class="saved-addrs" id="guestAddrs" hidden></div>
              @endif
              {{-- address autocomplete + use-my-location (geo.autocomplete / geo.reverse) --}}
              <div class="addr-wrap" id="custAddrField">
                <label class="addr-field">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                  <input aria-label="{{ ___('Street, building, floor…') }}" id="custAddr" data-i18n-ph="addr_ph" placeholder="{{ ___('Street, building, floor…') }}" autocomplete="off" autocorrect="off" spellcheck="false"
                         value="{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}">
                </label>
                <button class="uselocbtn" id="custUseLocation" type="button" title="{{ ___('Use my location') }}" aria-label="{{ ___('Use my location') }}">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                </button>
                <input type="hidden" id="custLat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                <input type="hidden" id="custLng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                <div class="addr-suggest" id="custAddrSuggest" hidden></div>
              </div>
              {{-- Drop-a-pin address picker (shared window.mbMapPicker engine, scoop-skinned sheet below). --}}
              <button class="ghostbtn sm map-trigger" id="scoopMapBtn" type="button">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                <span>{{ ___('Choose on map') }}</span>
              </button>
              <p class="geo-attrib" id="custGeoAttrib" hidden></p>
            </section>

            <section class="block">
              <h3 data-i18n="pay_with">{{ ___('Pay with') }}</h3>
              <div class="pm-list" id="pmList"></div>
              <div class="cardform" id="cardForm" hidden>
                <input aria-label="{{ ___('Cardholder name') }}" data-i18n-ph="card_name" placeholder="{{ ___('Cardholder name') }}" autocomplete="cc-name">
                <div class="field-duo">
                  <input aria-label="{{ ___('Expiry Date') }}" placeholder="MM/YY" inputmode="numeric" autocomplete="cc-exp">
                  <input aria-label="CVC" placeholder="CVC" inputmode="numeric" autocomplete="cc-csc">
                </div>
                <input aria-label="{{ ___('Card number') }}" placeholder="4242 4242 4242 4242" inputmode="numeric" autocomplete="cc-number">
              </div>
            </section>

            <section class="block">
              <h3 data-i18n="tip">{{ ___('Add a tip') }}</h3>
              <div class="tip-row" id="tipRow">
                <button data-tip="0" class="is-on" data-i18n="no_tip">{{ ___('None') }}</button>
                <button data-tip="5">5%</button>
                <button data-tip="10">10%</button>
                <button data-tip="15">15%</button>
              </div>
            </section>

            @include('store_templates._partials.voice-note-recorder')
            {{-- Order summary — the customer should see WHAT they are paying for at
                 the point of commitment, not just a number. Rendered by renderCheckout
                 (no data-i18n attrs inside, so the applyLang sweep can't clobber it). --}}
            <section class="ck-items" id="ckItems" hidden></section>
            <section class="totals" id="checkoutTotals"></section>
            <div class="tail-space tall"></div>
          </div>
          <footer class="p-cta">
            <button class="tocart wide" id="placeOrder"><span class="po-label" data-i18n="place_order">{{ ___('Place order') }}</span><b id="checkoutCtaTotal"></b><span class="spinner" hidden></span></button>
          </footer>
        </section>

        {{-- CONFIRMED · yellow --}}
        <section class="view view-sub view-center" data-view="confirmed" data-mode="yellow">
          <div class="confirm-wrap">
            <div class="confetti" id="confetti" aria-hidden="true"></div>
            <div class="bigcheck" aria-hidden="true">
              <svg viewBox="0 0 96 96"><circle cx="48" cy="48" r="44"/><path class="check" d="M31 49.5 43 61l22-24" pathLength="1"/></svg>
            </div>
            <h1 class="confirm-title" data-i18n="order_confirmed">{{ ___('Order confirmed') }}</h1>
            <p class="confirm-sub" data-i18n="confirm_sub">{{ ___('Your order has reached the kitchen') }}</p>
            {{-- hidden unless the server actually returns an order number: the demo
                 minted a random "#SCP-1234" that existed nowhere (see BLOCKED-SHARED). --}}
            <div class="confirm-code" id="confirmCode" hidden></div>
            {{-- the server's real prep estimate, not an invented countdown --}}
            <div class="pts-chip" id="confirmEta" hidden></div>
            <div class="confirm-actions">
              {{-- a real link to the server-rendered tracking page (real id, real status) --}}
              <a class="blackpill" id="trackLink" hidden><span data-i18n="track_order">{{ ___('Track order') }}</span></a>
              <button class="ghostlink" id="backToMenu"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span data-i18n="back_menu">{{ ___('Back to Menu') }}</span></button>
            </div>
          </div>
        </section>

        {{-- The in-app "track" view is GONE on purpose. It rendered a client-side
             timeline that advanced itself every 7 seconds and an ETA computed from a
             counter — a customer was told "Delivered" 28s after ordering, regardless of
             the kitchen. Tracking now goes to track.blade.php, which reads the real
             order status. The rating prompt lives there too (mk-rating-prompt), so the
             duplicate 5-star widget went with it. --}}

        {{-- PROFILE / USER · dark --}}
        <section class="view" data-view="profile" data-mode="dark">
          <div class="view-scroll">
            <header class="d-head"><h1 class="d-title sm" data-i18n="profile_title">{{ ___('Profile') }}</h1></header>

            {{-- identity card — the customer-auth entry. Guest gets the sign-in pitch
                 + Login pill (opens #authSheet); a signed-in customer sees their name
                 and a Logout. Hidden behind guest_only mode like every other theme. --}}
            <div class="me-card" id="meCard">
              <span class="me-avatar">@if($customer){{ Str::upper(Str::substr($customer->name, 0, 1)) }}@else<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>@endif</span>
              <div class="me-meta">
                <b>{{ $customer->name ?? ___('Guest') }}</b>
                <small>{{ $customer ? ($customer->phone ?? $customer->email) : ($authMode !== 'guest_only' ? ___('Sign in to keep your orders and addresses together.') : ___('Order without an account')) }}</small>
              </div>
              @if($authMode !== 'guest_only')
                @if($customer)<button class="ghostbtn sm" id="logoutBtn" type="button" data-i18n="logout">{{ ___('Logout') }}</button>
                @else<button class="yellowpill sm" id="meAuthBtn" type="button" data-i18n="login">{{ ___('Login') }}</button>@endif
              @endif
            </div>

            {{-- the loyalty card renders only for a store that actually runs a program;
                 the demo showed an invented localStorage points balance to everyone --}}
            @if($loyaltyProgram)
            <div class="points-card">
              <div>
                <p class="tk-label" data-i18n="points">{{ ___('Loyalty points') }}</p>
                <b class="pts-amt" id="ptsAmt">0</b>
              </div>
              <button class="blackpill sm" id="redeemBtn" data-i18n="redeem">{{ ___('Redeem') }}</button>
            </div>
            @endif

            <section class="block">
              <h3 data-i18n="preferences">{{ ___('Preferences') }}</h3>
              @if(count($menu_languages ?? []) > 1)
              {{-- a REAL language switch: /lang/{code} persists in the session, so the
                   account + tracking pages and the menu data follow. The demo's EN/AR
                   toggle only re-labelled the chrome and forgot itself on the next page. --}}
              <div class="pref-row">
                <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg><span data-i18n="language">{{ ___('Language') }}</span></span>
                <div class="seg mini" id="langSeg">
                  @foreach($menu_languages as $language)
                    {{-- P1-G: data-lang-code hands this to the shared language switch, which
                         rewrites the URL locale segment — under prefix-mode locales the segment
                         is what actually decides the UI language. The /lang/{code} href stays as
                         the no-JS fallback (it sets the session locale server-side). --}}
                    <a href="{{ url('/lang/' . $language->code) }}"
                       data-lang-code="{{ $language->code }}"
                       data-lang-name="{{ $language->name ?? strtoupper($language->code) }}"
                       class="{{ get_lang() === $language->code ? 'is-on' : '' }}">{{ strtoupper($language->code) }}</a>
                  @endforeach
                </div>
              </div>
              @endif
              <div class="pref-row">
                <span><svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0 0 18 7 7 0 0 1 0-18Z"/></svg><span data-i18n="appearance">{{ ___('Appearance') }}</span></span>
                <div class="seg mini" id="apSeg">
                  <button data-ap="dark" data-i18n="ap_dark">{{ ___('Dark') }}</button>
                  <button data-ap="light" data-i18n="ap_light">{{ ___('Light') }}</button>
                  <button data-ap="system" data-i18n="ap_system">{{ ___('System') }}</button>
                </div>
              </div>
              @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
              <div class="pref-row">
                <span><svg class="ic" viewBox="0 0 24 24"><path d="M12 3v18M8 7.5C8 6 9.6 5 12 5s4 1 4 2.5S14.5 10 12 10s-4 1-4 2.5S9.5 15 12 15s4 1 4 2.5-1.6 2.5-4 2.5-4-1-4-2.5"/></svg><span data-i18n="currency">{{ ___('Currency') }}</span></span>
                <div class="seg mini" id="curSeg"></div>
              </div>
              @endif
            </section>

            @php
                $socialProviders = app(\Juwri\Modules\Auth\Services\Social\SocialCredentialResolver::class)->enabledProviders($store->id);
            @endphp
            @if($authMode !== 'guest_only' && !$customer && !empty($socialProviders))
            {{-- parity gap closed: scoop was 1 of 4 themes with no social sign-in at all.
                 The partial renders nothing when the owner has enabled no provider. --}}
            <section class="block" id="signinBlock">
              @include('store_templates.partials.social-login-buttons', ['store' => $store])
            </section>
            @endif

            <section class="block">
              @if($customer)
              <a class="prow" href="{{ route('customer.profile', $store->id) }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                <b>{{ ___('Profile & settings') }}</b>
                <small>{{ ___('Details, addresses & preferences') }}</small>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </a>
              @endif
              <button class="prow" data-go="favs">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
                <b data-i18n="my_favs">{{ ___('My favourites') }}</b>
                <small id="favCount">0</small>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              {{-- "Saved for later" removed: it was a second, identical favourites list
                   whose profile row only fired a toast and led nowhere. --}}
              <button class="prow" id="bookRow">
                <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="16" rx="3"/><path d="M8 3v4M16 3v4M4 10h16"/></svg>
                <b data-i18n="reservations">{{ ___('Reservations') }}</b>
                <small id="bookSub" data-i18n="book_table">{{ ___('Book a table') }}</small>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              @if($showBranches)
              <button class="prow" id="nearestBranchBtn">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                <b data-i18n="nearest_branch">{{ ___('Find nearest branch') }}</b>
                <small data-i18n="on_map">{{ ___('On the map') }}</small>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              @endif
            </section>

            @if($showBranches)
            {{-- R3 · plain branch list — every $branches entry is pickable via
                 ?branch_id, so a branch with no coordinates (invisible on the geo
                 map above, which needs lat/lng + Leaflet) is still reachable. The
                 map picker stays as the "nearest to me" affordance. --}}
            <section class="block" id="branchPickBlock">
              <h3>{{ ___('Choose branch') }}</h3>
              <div class="branch-list">
                @foreach($branches as $b)
                <a class="branch-row {{ (string) request('branch_id') === (string) $b->id ? 'is-on' : '' }}"
                   href="{{ request()->fullUrlWithQuery(['branch_id' => $b->id]) }}">
                  <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                  <b>{{ $b->name }}@unless($b->isOpenNow())<small>{{ ___('Closed') }}</small>@endunless</b>
                  <span class="branch-check"></span>
                </a>
                @endforeach
              </div>
            </section>
            @endif

            <section class="block">
              <h3 data-i18n="my_orders">{{ ___('My orders') }}</h3>
              <div id="ordersList" class="orders-list"></div>
              <p class="orders-empty" id="ordersEmpty" data-i18n="orders_empty">{{ ___('Orders you place appear here') }}</p>
              @if(!$customer)
              {{-- Find my orders — a guest who ordered on another device/browser pulls
                   their history by the phone/email used at checkout. Posts to the shared
                   restaurant.orderLookup endpoint (already in SCOOP.endpoints). --}}
              @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
              <div class="order-lookup" id="orderLookup">
                <p class="sheet-sub">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
                <label class="searchfield lookup-field">
                  <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                  <input aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" id="lookupInput" type="text" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" autocomplete="off">
                </label>
                <button class="blackpill sm" id="lookupBtn" type="button" data-i18n="find_orders">{{ ___('Find my orders') }}</button>
                <div id="lookupResults" class="orders-list"></div>
                <p class="orders-empty" id="lookupEmpty" hidden>{{ ___('Your past orders will show up here.') }}</p>
              </div>
              @endif
            </section>

            @if($waNumber)
            <a class="wa-order line" id="waUs" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
              <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
              <span data-i18n="wa_us">{{ ___('Chat with us on WhatsApp') }}</span>
              <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            @else
            <button class="wa-order line" id="waUs" hidden></button>
            @endif

            {{-- Share this menu — native profile row (like the WhatsApp .wa-order),
                 not the floating pill. Gated on the same share.fallback_button
                 toggle; data-web-share makes _partials/share stand down. --}}
            @if(theme_setting($store->id, 'share.fallback_button'))
            <button class="wa-order line share-btn" id="mbShareRow" type="button" data-web-share
                    aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg>
              <span>{{ ___('Share this menu') }}</span>
              <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </button>
            @endif

            {{-- Privacy utility: wipe the cart / favourites / saved addresses this
                 browser keeps (shared window.mbClearDeviceData engine). Scoop's profile
                 view is guest-reachable, so the control lives here in the account surface
                 — styled as one of the profile's own full-width rows (.wa-order.line). --}}
            <button class="wa-order line cleardata" id="scoopClearData" type="button">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/></svg>
              <span>{{ ___('Clear saved data on this device') }}</span>
            </button>
            <p class="device-data-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>

            <p class="demo-foot">{{ $store->title }}</p>
            <div class="tail-space"></div>
          </div>
        </section>

        {{-- Catering & Events now lives in #cvCateringSheet (opened from the header
             button); the form is included there with cateringInline=true so it does
             not self-relocate back to the bottom of the menu. --}}

      </main>

      {{-- ══════════ SEARCH OVERLAY (orbit-style) ══════════ --}}
      @if(theme_setting($store->id, 'search.enabled', true))
      <div class="searchpop" id="searchPop">
        <div class="sp-top">
          <label class="searchfield">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            <input aria-label="{{ ___('Search the menu…') }}" id="searchInput" type="search" data-i18n-ph="search_ph" placeholder="{{ ___('Search the menu…') }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
          </label>
          <button class="searchclose" id="searchClose" aria-label="{{ ___('Close search') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </div>
        <div class="sp-results" id="searchResults" data-none="{{ ___('No dishes match your search') }}"></div>
      </div>
      @endif

      @include('store_templates._partials.notif-bell')

      {{-- ══════════ BOTTOM NAV · black pill ══════════ --}}
      <nav class="navbar" id="navbar" aria-label="{{ ___('Main') }}">
        <button class="nav-item is-on" data-tab="home">
          <span class="nav-chip">
            <svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg>
            <span class="nav-label" data-i18n="n_home">{{ ___('Home') }}</span>
          </span>
        </button>
        <button class="nav-item" data-tab="menu">
          <span class="nav-chip">
            <svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
            <span class="nav-label" data-i18n="n_menu">{{ ___('menu') }}</span>
          </span>
        </button>
        @if(theme_setting($store->id, 'search.enabled', true))
        <button class="nav-item" data-tab="search">
          <span class="nav-chip">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg>
            <span class="nav-label" data-i18n="n_search">{{ ___('Search') }}</span>
          </span>
        </button>
        @endif
        @if($allow_order)
        <button class="nav-item" data-tab="cart">
          <span class="nav-chip">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="9.5" cy="19.5" r="1.6"/><circle cx="17" cy="19.5" r="1.6"/><path d="M3 4h2l2.3 11h10.4L20 8H6.1"/></svg>
            <span class="nav-label" data-i18n="n_cart">{{ ___('cart') }}</span>
            <i class="badge" id="cartBadge" hidden>0</i>
          </span>
        </button>
        @endif
        <button class="nav-item" data-tab="favs">
          <span class="nav-chip">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
            <span class="nav-label" data-i18n="n_favs">{{ ___('Saved') }}</span>
            <i class="badge" id="favNavBadge" hidden>0</i>
          </span>
        </button>
        <button class="nav-item" data-tab="profile">
          <span class="nav-chip">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
            <span class="nav-label" data-i18n="n_user">{{ ___('user') }}</span>
          </span>
        </button>
      </nav>

      {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
      <div class="scrim" id="scrim" hidden></div>

      {{-- full-screen story viewer --}}
      <div class="storyview" id="storyViewer" hidden>
        <div class="sv-progress" id="svProgress"></div>
        <button class="sv-close" id="svClose" aria-label="{{ ___('Close') }}">
          <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="sv-stage" id="svStage">
          {{-- no src= here on purpose: an empty src makes the browser refetch the page. JS sets it. --}}
          <img class="sv-media" id="svMedia" alt="" data-csp-error="scoop-image-fail">
          <button class="sv-nav prev" id="svPrev" aria-label="{{ ___('Previous') }}"></button>
          <button class="sv-nav next" id="svNext" aria-label="{{ ___('Next') }}"></button>
          <div class="sv-foot">
            <p class="sv-caption" id="svCaption"></p>
            <a class="tocart sv-cta" id="svCta" target="_blank" rel="noopener" hidden><span></span></a>
          </div>
        </div>
      </div>

      <div class="sheet" id="filterSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 data-i18n="filters">{{ ___('Filters') }}</h2>
        <h4 data-i18n="dietary">{{ ___('Dietary') }}</h4>
        <div class="chiprow" id="dietRow">
          <button data-diet="vegan" data-i18n="tag_vegan">{{ ___('Vegan') }}</button>
          <button data-diet="gf" data-i18n="tag_gf">{{ ___('Gluten-free') }}</button>
        </div>
        <h4 data-i18n="sort_by">{{ ___('Sort by') }}</h4>
        <div class="rowlist" id="sortList"></div>
        <div class="sheet-actions">
          <button class="ghostbtn" id="filterReset" data-i18n="reset">{{ ___('Reset') }}</button>
          <button class="tocart" id="filterDone"><span data-i18n="show_items">{{ ___('Show scoops') }}</span><b id="filterCount"></b></button>
        </div>
      </div>

      @if($loyaltyProgram)
      <div class="sheet" id="redeemSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 data-i18n="redeem_title">{{ ___('Redeem points') }}</h2>
        <p class="sheet-sub"><span data-i18n="your_balance">{{ ___('Your balance') }}</span> · <b id="redeemPts">0</b> <span data-i18n="pts">{{ ___('pts') }}</span></p>
        <div class="rowlist" id="rewardList"></div>
      </div>
      @endif

      @if($authMode !== 'guest_only' && !$customer)
      {{-- customer auth sheet — Login / Phone OTP / Register. The endpoints have
           shipped in SCOOP.endpoints since day one; this is the UI that finally
           uses them. Tabs reuse the .seg pill, forms reuse the .book-form idiom. --}}
      <div class="sheet" id="authSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Login') }} / {{ ___('Register') }}</h2>
        <div class="seg mini center" id="authTabs">
          <button data-auth-tab="login" class="is-on" type="button">{{ ___('Login') }}</button>
          <button data-auth-tab="otp" type="button">{{ ___('Phone OTP') }}</button>
          <button data-auth-tab="register" type="button">{{ ___('Register') }}</button>
        </div>
        <p class="err-box" id="authErr" hidden></p>
        <div class="book-form auth-form" data-auth="login">
          <h4>{{ ___('Email') }}</h4>
          <input aria-label="{{ ___('Email') }}" id="loginEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
          <h4>{{ ___('Password') }}</h4>
          <input aria-label="{{ ___('Password') }}" id="loginPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="current-password">
          <div class="sheet-actions">
            <button class="tocart wide" id="loginBtn" type="button"><span>{{ ___('Login') }}</span></button>
          </div>
          @include('store_templates.partials.social-login-buttons', ['store' => $store])
          @include('store_templates.partials.passkey-login', ['store' => $store])
        </div>
        <div class="book-form auth-form" data-auth="otp" hidden>
          <div id="otpStep1">
            <p class="sheet-sub">{{ ___("We'll text you a one-time code") }}</p>
            <h4>{{ ___('Phone number') }}</h4>
            <input aria-label="{{ ___('Phone number') }}" id="otpPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
            <div class="sheet-actions">
              <button class="tocart wide" id="otpSend" type="button"><span>{{ ___('Send code') }}</span></button>
            </div>
          </div>
          <div id="otpStep2" hidden>
            <p class="sheet-sub">{{ ___('Enter the code we sent you') }}</p>
            <div class="otp-boxes" id="otpBoxes"><input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric"></div>
            <div class="sheet-actions">
              <button class="tocart wide" id="otpVerify" type="button"><span>{{ ___('Verify') }}</span></button>
            </div>
          </div>
        </div>
        <div class="book-form auth-form" data-auth="register" hidden>
          <h4>{{ ___('Your name') }}</h4>
          <input aria-label="{{ ___('Your name') }}" id="regName" placeholder="{{ ___('Your name') }}" autocomplete="name">
          <h4>{{ ___('Email') }}</h4>
          <input aria-label="{{ ___('Email') }}" id="regEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
          <h4>{{ ___('Phone number') }}</h4>
          <input aria-label="{{ ___('Phone number') }}" id="regPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
          <h4>{{ ___('Password') }}</h4>
          <input aria-label="{{ ___('Password') }}" id="regPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
          <h4>{{ ___('Confirm Password') }}</h4>
          <input aria-label="{{ ___('Confirm Password') }}" id="regPass2" type="password" placeholder="{{ ___('Confirm Password') }}" autocomplete="new-password">
          <div class="sheet-actions">
            <button class="tocart wide" id="registerBtn" type="button"><span>{{ ___('Register') }}</span></button>
          </div>
          @include('store_templates.partials.social-login-buttons', ['store' => $store])
        </div>
      </div>
      @endif

      {{-- Reservations & waitlist are REAL server features. The demo's sheet stored the
           choice in localStorage and toasted "Table reserved" — nobody at the restaurant
           ever heard about it, and the waitlist told every guest "3 parties ahead of you"
           from a hardcoded string. Both now post to the public endpoints. --}}
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

      @if(storefront_catering_enabled($store->id))
      <div class="sheet" id="cvCateringSheet" hidden>
        <div class="sheet-grab"></div>
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
      </div>
      @endif

      @if($reservationsEnabled || $waitlistEnabled)

      <div class="sheet" id="bookSheet" hidden>
        <div class="sheet-grab"></div>
        {{-- Explicit close (top corner); wired in JS (scoop closes via the scrim). --}}
        <button class="sheet-x" type="button" aria-label="{{ ___('Close') }}">
          <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2 data-i18n="book_title">{{ ___('Book a table') }}</h2>
        @if($reservationsEnabled && $waitlistEnabled)
        <div class="seg mini center" id="bookSeg">
          <button data-book="reserve" class="is-on" data-i18n="reserve">{{ ___('Reserve') }}</button>
          <button data-book="waitlist" data-i18n="waitlist">{{ ___('Waitlist') }}</button>
        </div>
        @endif

        @if($reservationsEnabled)
        <div class="book-panel" id="reservePanel">
          @if(session('reservation_success'))
            <p class="wait-info">{{ session('reservation_success') }}</p>
          @elseif($myReservation)
            <p class="wait-info">{{ ___('Reserved') }} · {{ date_formating($myReservation->reserved_at, 'd M · h:i A') }}</p>
          @else
          <form class="book-form" method="POST" action="{{ route('publicView.reservation.book', $store->slug) }}">
            @csrf
            <h4>{{ ___('Your name') }}</h4>
            <input aria-label="{{ ___('Your name') }}" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}">
            <h4>{{ ___('Phone') }}</h4>
            <input aria-label="{{ ___('Phone') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}">
            <h4>{{ ___('Date & Time') }}</h4>
            <input aria-label="{{ ___('Date & Time') }}" name="reserved_at" type="datetime-local" required min="{{ now()->format('Y-m-d\TH:i') }}">
            <h4>{{ ___('Party Size') }}</h4>
            <input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
            <div class="sheet-actions">
              @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
              <button class="tocart wide" type="submit"><span>{{ ___('Request Reservation') }}</span></button>
            </div>
          </form>
          @endif
        </div>
        @endif

        @if($waitlistEnabled)
        <div class="book-panel" id="waitPanel" @if($reservationsEnabled) hidden @endif>
          @if(session('waitlist_joined'))
            <p class="wait-info">{{ ___('You are on the list') }}</p>
          @elseif($myWaitlist)
            <p class="wait-info">{{ ___('You are on the list') }}</p>
          @else
          <form class="book-form" method="POST" action="{{ route('publicView.waitlist.join', $store->slug) }}">
            @csrf
            <h4>{{ ___('Your name') }}</h4>
            <input aria-label="{{ ___('Your name') }}" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}">
            <h4>{{ ___('Phone') }}</h4>
            <input aria-label="{{ ___('Phone') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}">
            <h4>{{ ___('Party Size') }}</h4>
            <input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
            <div class="sheet-actions">
              <button class="tocart wide" type="submit"><span>{{ ___('Join Waitlist') }}</span></button>
            </div>
          </form>
          @endif
        </div>
        @endif
      </div>
      @endif

      @if($showBranches)
      {{-- nearest-branch map picker (self-hosted Leaflet) --}}
      <div class="sheet sheet-tall" id="branchMapSheet" hidden>
        <div class="sheet-grab"></div>
        <h2 data-i18n="nearest_branch_h">{{ ___('Nearest branch') }}</h2>
        <div class="branchmap" id="branchMap"></div>
        <p class="geo-attrib" id="branchMapAttrib"></p>
        <div class="branch-list" id="branchGeoList"></div>
      </div>
      @endif

      {{-- Address map picker — scoop-native bottom sheet (.sheet.sheet-tall, mirrors
           #branchMapSheet + the theme's own scrim/slide) driven by the shared
           window.mbMapPicker engine. Fills the same #custAddr the checkout submits,
           plus the hidden #custLat / #custLng coords. --}}
      <div class="sheet sheet-tall map-sheet" id="scoopMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" hidden>
        <div class="sheet-grab"></div>
        <button class="sheet-x" id="scoopMapClose" type="button" aria-label="{{ ___('Close') }}">
          <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2>{{ ___('Choose on map') }}</h2>
        <div class="branchmap map-canvas" id="scoopAddrMap"></div>
        <p class="map-preview" id="scoopMapPreview">{{ ___('Move the map to place the pin') }}</p>
        <div class="sheet-actions">
          <button class="tocart wide" id="scoopMapConfirm" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <span>{{ ___('Use this location') }}</span>
          </button>
        </div>
      </div>

      <div class="toast" id="toast" role="status"></div>
      <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>
    </div>{{-- /.app --}}
  </div>{{-- /.phone --}}
</div>
@endsection


@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    window.SCOOP = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        currencySign:   @json($curSign),
        currencyPos:    @json($curPos),
        {{-- TOTALS CONTRACT: decimals must follow the RESTAURANT's currency, not the
             global one — Order::computeTax() rounds with currency_decimals($postOptions
             ->currency_code), so a 0/3-decimal store diverged from the quoted total. --}}
        currencyDecimals: @json(currency_decimals($postOptions->currency_code ?? null)),
        moneyDecimals: @json(money_decimals($postOptions->currency_code ?? null)), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        baseCurrency:   @json(config('settings.currency_code', 'USD')),
        currencyRates:  @json($mcRates),
        dir:            @json(current_language()->direction ?? 'ltr'),
        lang:           @json(get_lang()),
        taxCharge:      @json($taxCharge),
        taxType:        @json($taxType),
        taxLabel:       @json($taxLabel),
        deliveryCharge: @json($deliveryCharge),
        prepEta:        @json($prepEtaText ?: null),
        minOrderValue:  @json($minOrderValue),
        allowOrder:     @json((bool) $allow_order),
        allowPayment:   @json((bool) $allow_payment),
        allowDelivery:  @json((bool) $allow_delivery),
        allowPickup:    @json((bool) $allow_takeaway),
        allowDinein:    @json((bool) $allow_on_table),
        allowRoomservice:@json((bool) $allow_room_service),
        allowCallWaiter:@json((bool) ($allowWaiter && $allow_on_table)),
        forceTable:     @json((bool) $table),
        defaultTable:   @json($table->table_no ?? null),
        authMode:       @json($authMode),
        {{-- FEATURES-5 Track A — enabled social providers for the JS-built auth sheet --}}
        {{-- {!! !!} + json_encode instead of @json: Blade's compileJson comma-explodes multi-arg expressions (broke these 4 themes' pages) --}}
        socialLogin:    {!! json_encode(collect(app(\Juwri\Modules\Auth\Services\Social\SocialCredentialResolver::class)->enabledProviders($store->id))->map(fn($p)=>['provider'=>$p,'label'=>ucfirst($p),'url'=>route('customer.social.redirect',[$store->id,$p])])->values(), 15) !!},
        {{-- ZERO-TOUCH A3.2 — agent chat data seam (theme-built UI; no forced partial). enabled:false when owner/plan off --}}
        agentChat:      @json(\App\Support\AgentChatWidget::seam($store)),
        isLoggedIn:     @json((bool) $customer),
        customerName:   @json($customer->name ?? null),
        {{-- F2 · Bearer token for the CustomerToken-guarded follow endpoint --}}
        customerToken:  @json($customerApiToken),
        storeCredit:    @json((float) $storeCredit),
        waNumber:       @json($waNumber ?: null),
        googleReviewUrl:@json($googleReviewUrl ?: null),
        reservationsEnabled: @json((bool) $reservationsEnabled),
        waitlistEnabled:     @json((bool) $waitlistEnabled),
        csrf:           @json(csrf_token()),
        loyaltyType:      @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json((int) ($loyaltyProgram->reward_threshold ?? 0)),
        openResSheet:      @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        cats:           @json($catList),
        subCats:        @json((object) $jsSubCats),
        leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
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
        {{-- Every customer-visible chrome string, server-translated through ___() so it
             works in ALL locales. The theme shipped its own hardcoded en/ar dictionary,
             which (a) forced English on a French/Turkish/Urdu storefront and (b) carried
             the standalone demo's ice-cream vocabulary onto real restaurants. script.js
             now reads this map FIRST and only falls back to the bundled dictionary. --}}
        strings: {
            skip:            @json(___('Skip')),
            all_products:    @json(___('All')),
            search_ph:       @json(___('Search the menu…')),
            daily_special:   @json(___('Daily special')),
            popular:         @json(___('Popular')),
            in_stock:        @json(___('In stock')),
            left_n:          @json(___('Only {n} left')),
            stock_n:         @json(___('{n} in stock')),
            sold_out:        @json(___('Sold out')),
            to_cart:         @json(___('Add')),
            no_results:      @json(___('Nothing matches — try clearing the filters')),
            clear_filters:   @json(___('Clear filters')),
            favourite:       @json(___('Favourite')),
            favourites:      @json(___('Favourites')),
            fav_empty:       @json(___('Tap the heart on any dish to keep it here')),
            fav_on:          @json(___('Saved to favourites')),
            fav_off:         @json(___('Removed from favourites')),
            pin_on:          @json(___('Saved for later')),
            pin_off:         @json(___('Removed from saved')),
            share:           @json(___('Share')),
            share_ok:        @json(___('Link copied to clipboard')),
            share_fail:      @json(___('Could not copy the link')),
            your_balance:    @json(___('Your balance')),
            redeemed:        @json(___('Reward unlocked')),
            not_enough:      @json(___('Not enough points yet')),
            base_group:      @json(___('Choose an option')),
            toppings:        @json(___('Extras')),
            optional:        @json(___('optional')),
            required:        @json(___('required')),
            pick_upto:       @json(___('Pick up to {n}')),
            whats_inside:    @json(___('Description')),
            kcal_u:          @json(___('kcal')),
            min_u:           @json(___('min')),
            added:           @json(___('Added to cart')),
            removed:         @json(___('Removed')),
            only_left:       @json(___('Only {n} left')),
            cart_title:      @json(___('Cart')),
            clear:           @json(___('Clear')),
            cart_empty:      @json(___('Your cart is empty')),
            browse_menu:     @json(___('Browse the menu')),
            order_type:      @json(___('Order type')),
            ot_delivery:     @json(___('Delivery')),
            ot_pickup:       @json(___('Pickup')),
            ot_dinein:       @json(___('Dine-in')),
            ot_roomservice:  @json(___('Room service')),
            room_hint:       @json(___('Brought straight to your room')),
            table:           @json(___('Table')),
            table_ph:        @json(___('Table number')),
            delivery_hint:   @json(___('Delivered to your address')),
            pickup_hint:     @json(___('Ready at the counter')),
            eta_hint:        @json(___('Usually ready in {n} min')),
            when:            @json(___('When')),
            asap:            @json(___('ASAP')),
            later:           @json(___('Later')),
            coupon_ph:       @json(___('Promo code')),
            apply:           @json(___('Apply')),
            coupon_ok:       @json(___('Coupon applied')),
            coupon_bad:      @json(___('Invalid promo code.')),
            coupon_remove:   @json(___('Remove coupon')),
            note_add:        @json(___('Add note')),
            your_order:      @json(___('Your order')),
            edit:            @json(___('Edit')),
            note_ph:         @json(___('No onions, extra sauce…')),
            call_waiter:     @json(___('Call waiter')),
            waiter_ok:       @json(___('A waiter will be with you shortly!')),
            wa_order:        @json(___('Order via WhatsApp instead')),
            wa_stub:         @json(___('WhatsApp is not set up for this store')),
            redeem_title:    @json(___('Redeem points')),
            wa_us:           @json(___('Chat with us on WhatsApp')),
            nearest_branch:  @json(___('Find nearest branch')),
            on_map:          @json(___('On the map')),
            nearest_branch_h:@json(___('Nearest branch')),
            no_branches:     @json(___('No branches')),
            km_away:         @json(___('km')),
            checkout:        @json(___('Checkout')),
            payment:         @json(___('Payment')),
            contact:         @json(___('Your details')),
            your_name:       @json(___('Your name')),
            phone:           @json(___('Phone')),
            delivery_address:@json(___('Delivery address')),
            addr_ph:         @json(___('Street, building, floor…')),
            use_location:    @json(___('Use my location')),
            loc_unavailable: @json(___('Location unavailable')),
            loc_denied:      @json(___('Location denied')),
            no_address:      @json(___('No address found')),
            reviews_title:   @json(___('Reviews')),
            reviews_empty:   @json(___('No reviews yet.')),
            pay_with:        @json(___('Pay with')),
            pm_cash:         @json(___('Cash')),
            pm_card:         @json(___('Card')),
            pm_wallet:       @json(___('Wallet')),
            pay_on_handover: @json(___('Pay on handover')),
            card_name:       @json(___('Cardholder name')),
            wallet_bal:      @json(___('Balance')),
            tip:             @json(___('Add a tip')),
            no_tip:          @json(___('None')),
            place_order:     @json(___('Place order')),
            fill_contact:    @json(___('Please add your name')),
            fill_addr:       @json(___('Please add a delivery address')),
            order_confirmed: @json(___('Order confirmed')),
            confirm_sub:     @json(___('Your order has reached the kitchen')),
            track_order:     @json(___('Track order')),
            back_menu:       @json(___('Back to Menu')),
            order_no:        @json(___('Order')),
            eta:             @json(___('Ready in')),
            rate_q:          @json(___('How was your order?')),
            rate_google:     @json(___('Continue on Google Reviews')),
            rate_bad_ph:     @json(___('Tell us what went wrong…')),
            send:            @json(___('Send')),
            rate_thanks:     @json(___('Thank you!')),
            profile_title:   @json(___('Profile')),
            points:          @json(___('Loyalty points')),
            pts:             @json(___('pts')),
            redeem:          @json(___('Redeem')),
            my_favs:         @json(___('My favourites')),
            saved_later:     @json(___('Saved for later')),
            reservations:    @json(___('Reservations')),
            book_table:      @json(___('Book a table')),
            preferences:     @json(___('Preferences')),
            language:        @json(___('Language')),
            currency:        @json(___('Currency')),
            appearance:      @json(___('Appearance')),
            ap_dark:         @json(___('Dark')),
            ap_light:        @json(___('Light')),
            ap_system:       @json(___('System')),
            my_orders:       @json(___('My orders')),
            orders_empty:    @json(___('Orders you place appear here')),
            n_home:          @json(___('Home')),
            n_menu:          @json(___('menu')),
            n_search:        @json(___('Search')),
            n_cart:          @json(___('cart')),
            n_favs:          @json(___('Saved')),
            n_user:          @json(___('user')),
            filters:         @json(___('Filters')),
            dietary:         @json(___('Dietary')),
            sort_by:         @json(___('Sort by')),
            reset:           @json(___('Reset')),
            show_items:      @json(___('Show items')),
            tag_vegan:       @json(___('Vegan')),
            tag_gf:          @json(___('Gluten-free')),
            s_featured:      @json(___('Featured')),
            s_price_asc:     @json(___('Price · low to high')),
            s_price_desc:    @json(___('Price · high to low')),
            s_rating:        @json(___('Top rated')),
            book_title:      @json(___('Book a table')),
            reserve:         @json(___('Reserve')),
            waitlist:        @json(___('Waitlist')),
            subtotal:        @json(___('Subtotal')),
            discount:        @json(___('Discount')),
            delivery_fee:    @json(___('Delivery fee')),
            free:            @json(___('Free')),
            tip_label:       @json(___('Tip')),
            total:           @json(___('Total')),
            min_order:       @json(___('Minimum order')),
            net_err:         @json(___('Network error — please try again')),
        },
        i18n: {
            popular:       @json(___('Popular')),
            stories:       @json(___('Stories')),
            order_now:     @json(___('Order now')),
            sold_out:      @json(___('Sold out')),
            not_available: @json(___('Not available now')),
            starting_from: @json(___('Starting from')),
            added:         @json(___('Added to cart')),
            removed:       @json(___('Removed')),
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
            coupon_bad:    @json(___('Invalid promo code.')),
            waiter_ok:     @json(___('A waiter will be with you shortly!')),
            bill_ok:       @json(___('Your bill is on its way!')),
            rate_dish:     @json(___('Rate this dish')),
            thanks:        @json(___('Thanks!')),
            veg:           @json(___('Veg')),
            nonveg:        @json(___('Non-veg')),
        },
    };
</script>
{{-- self-hosted Leaflet — loaded before script.js (which layout loads after this stack) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
{{-- script.js is loaded once by store_templates.layout (avoids double-load) --}}
@endpush

@push('scripts_at_bottom')
@endpush

@push('scripts_at_bottom')
{{-- Address map picker + clear-saved-data wiring. Scoop is a shared-layout theme,
     so window.mbMapPicker / window.mbClearDeviceData are already global (layout
     @includes the engines above this stack). We only drive them with scoop's own
     sheet + controls. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var trigger = document.getElementById('scoopMapBtn');
    if (!trigger) { return; }
    if (!window.mbMapPicker) { trigger.style.display = 'none'; return; }
    var sheet = document.getElementById('scoopMapSheet');
    var scrim = document.getElementById('scrim');
    /* Drive scoop's native bottom sheet: mirror the theme's own sheet() —
       toggles [hidden] + .is-open on the sheet and the shared #scrim, 420ms slide.
       The picker only opens from the checkout view (openSheet is null there), so
       reusing the shared scrim is safe; we add our own scrim-click close below. */
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
        trigger: trigger,
        modal:   sheet,
        map:     document.getElementById('scoopAddrMap'),
        input:   document.getElementById('custAddr'),
        lat:     document.getElementById('custLat'),
        lng:     document.getElementById('custLng'),
        preview: document.getElementById('scoopMapPreview'),
        confirm: document.getElementById('scoopMapConfirm'),
        close:   document.getElementById('scoopMapClose'),
        toggle:  toggle,
        i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
    });
    if (scrim && picker) {
        scrim.addEventListener('click', function () {
            if (sheet && sheet.classList.contains('is-open')) { picker.close(); }
        });
    }
})();
(function () {
    var btn = document.getElementById('scoopClearData');
    if (!btn) { return; }
    var hint = document.querySelector('.device-data-hint');
    if (!window.mbClearDeviceData) { btn.style.display = 'none'; if (hint) { hint.style.display = 'none'; } return; }
    var slug = @json($store->slug ?? '');
    btn.addEventListener('click', function () {
        window.mbClearDeviceData({
            keys: [
                'scoop_cart',
                'scoop_favs',
                'scoop_orders',
                'scoop_recent_addrs_' + slug
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
{{-- P6-301 — consume the previous-order payload the account page wrote to
     localStorage (cv_reorder_<slug>) and add each line to scoop's cart. The
     shared helper (assets/store_templates/_shared/js/reorder-pickup.js) is
     loaded by store_templates.layout, but the call must run AFTER the theme's script.js (loaded by
     store_templates.layout) so window.__scoopPickup + window.mbToast are
     defined. `load` fires after every sync script on the document. --}}
<script nonce="{{ csp_nonce() }}">
window.addEventListener('load', function () {
    if (!window.JuwriReorder || !window.JuwriReorder.pickup) return;
    window.JuwriReorder.pickup(@json($store->slug ?? ''), window.__scoopPickup || function () { return false; }, {
        toast: window.mbToast || null,
    });
});
</script>
@endpush

@push('scripts_at_bottom')
{{-- ZERO-TOUCH — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endpush
