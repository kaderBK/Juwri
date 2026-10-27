{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ─────────────────────────────────────────────────────────────
       crumb — warm bakery-marketplace post_template · data prep
       Faithful rebuild of rev/crumb/demo.* wired to real menu data.
       ───────────────────────────────────────────────────────────── */
    use Illuminate\Support\Str;

    $items      = collect($total_menus)->values();
    $categories = $store->menu_categories->values();

    /* own-item count per real category id (drives the secondary sub-chip row).
       $store->menu_categories is top-level only, but an item keys off its OWN
       category_id — which may be a subcategory. */
    $catItemCount = [];
    foreach ($items as $m) {
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;
    }
    /* Secondary sub-chip row: per parent, only the subcategories that own items.
       A parent whose items live directly on it (no item-bearing child) gets no
       second row — its items already show under the parent chip. */
    $subCatsOf = [];
    foreach ($categories as $cat) {
        $subs = collect($cat->subcategories ?? [])
            ->filter(fn($s) => ($catItemCount[$s->id] ?? 0) > 0)
            ->values();
        if ($subs->isNotEmpty()) {
            $subCatsOf[$cat->id] = $subs;
        }
    }

    /* multi-branch → nearest-branch Leaflet map (same contract as ember/orbit/aurora) */
    $showBranches = !empty($branches) && count($branches) > 1 && (@$postOptions->allow_multi_branch_switch ?? 1);

    /* store status strip (CRUMB-F20) */
    $prepEta   = trim((string) (@$postOptions->prep_eta_static ?: ''));
    $storeOpen = store_open_now($store); // manual switch AND opening hours

    /* CRUMB-F5 — the offer slot is data-driven now.
       crumb used to hard-code a "Limited time! Get Special Offer / Up to 40% / Shop
       Now" hero banner (and a matching "Up to 40% off signature cakes" story) on every
       storefront, backed by nothing. It advertised a discount the store had never
       created. The slot now renders the store's own best live coupon, or nothing. */
    $liveCoupon = \App\Models\Coupon::query()
        ->where('user_id', $store->user_id)
        ->where('applies_to', 'food_order')
        ->where(fn ($q) => $q->whereNull('expiry_at')->orWhere('expiry_at', '>', now()))
        ->whereColumn('used', '<', 'limit')
        ->where('coupon_type', 'percentage') /* the slot says "Save N%" */
        ->orderByDesc('percentage_rate')
        ->first();
    $jsBanners = collect($menuBanners ?? [])->map(fn($banner) => [
        'title' => $banner->title,
        'subtitle' => $banner->subtitle,
        'img' => $banner->image_url,
        'link' => storefront_localized_link($banner->link_url) ?: url('/'.get_lang().'/'.$store->slug),
    ])->values();

    /* currency */
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);   // 1 = sign before number
    $curGap  = currency_symbol_gap($postOptions->currency_sign ?? '$');     // U+20C3 UAE Dirham only
    $baseCur = config('settings.currency_code', 'USD');

    /* manual multi-currency rates (CODE=rate per line) */
    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)
    $multiCurrency = !empty($postOptions->enable_multi_currency) && count($mcRates) > 0;

    /* charges / limits / flags */
    $deliveryCharge = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge      = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $taxLabel       = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('VAT');
    $minOrderValue  = (float) (@$postOptions->restaurant_minimum_order ?: 0);
    $allowCoupons   = !isset($postOptions->allow_coupons) || $postOptions->allow_coupons == 1;
    $allowTips      = @$postOptions->allow_tips == 1;
    $allowWaiter    = !empty($postOptions->allow_call_waiter);
    $allowOrderSched = !empty($postOptions->allow_order_scheduling);
    $allowDietary    = !isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;

    /* engagement backends */
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));

    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();
    /* Coupons & Wallets P7 — pay-with-credit for a logged-in diner with balance */
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;

    /* F2 · brand / follow row, ported from crave. customerApiToken hits the
       CustomerToken-guarded follow API; mint (and session-cache) a raw token
       for the signed-in session customer so the theme JS can call it. */
    $customerApiToken = storefront()->customerApiToken($customer, 'crumb');
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

    /* FIX-3c — a logged-in diner's saved ACCOUNT addresses, surfaced as tappable
       chips in the location picker. Distinct from the on-device recents crumb keeps
       in localStorage: these ride the customer's account, so they show on any device. */
    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);
    $customerDefaultAddress = $customerAddresses->first();

    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */

    /* dine-in table (scanned via ?table=) may arrive as a string or a model */
    $tableNo = is_object($table ?? null) ? ($table->table_no ?? null) : ($table ?? null);

    /* branches (crumb "Explore" map + pickup picker). Keys map to branch_number1..N */
    $branchList = [];
    if (!empty($branches) && count($branches)) {
        foreach ($branches->values() as $bi => $br) {
            // deterministic pin positions so the map reads well without geo data
            $px = 22 + (($br->id * 37) % 56);
            $py = 20 + (($br->id * 53) % 56);
            $branchList[] = [
                'key'  => 'branch_number'.($bi + 1),
                'name' => $br->name,
                'addr' => trim((string) ($br->address ?? $store->address ?? '')),
                'pin'  => ['x' => $px, 'y' => $py],
            ];
        }
    } else {
        $branchList[] = [
            'key'  => 'branch_number1',
            'name' => $store->title,
            'addr' => trim((string) ($store->address ?? '')),
            'pin'  => ['x' => 44, 'y' => 40],
        ];
    }

    /* variant human label */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* batch-load approved reviews for every item in ONE query (no N+1) */
    $allMenuIds = $items->pluck('id')->all();
    $reviewsByMenu = collect();
    if (!empty($allMenuIds)) {
        $reviewsByMenu = \App\Models\MenuReview::whereIn('menu_id', $allMenuIds)
            ->where('status', 'approved')
            ->latest()
            ->get()
            ->groupBy('menu_id');
    }

    /* JS item map (shaped for the demo renderer, fed with real data) */
    $jsItems = [];
    foreach ($items as $m) {
        $base = (float) $m->price;

        /* weight chips ← real variants (delta model keeps demo math: unit = base + delta) */
        $weights = [];
        $isWeighty = false;
        if (isset($m->variants) && count($m->variants)) {
            foreach ($m->variants as $v) {
                $lbl = $variantLabel($m, $v);
                if (preg_match('/kg|جم|كجم|\bg\b|gram|جرام/iu', $lbl)) { $isWeighty = true; }
                $weights[] = ['name' => $lbl, 'delta' => (float) $v->price - $base, 'vid' => $v->id];
            }
        }
        if (empty($weights)) {
            // Neutral default carrier for a no-variant item: vid:0 keeps weights[0]
            // defined (script.js:446 dereferences it) and hides the weight block, so
            // this name is never rendered — no phantom $m->portion_size, no fake label.
            $weights[] = ['name' => '', 'delta' => 0.0, 'vid' => 0];
        }

        /* paid extras (absolute add == delta in demo terms) */
        $extras = [];
        if (isset($m->extras)) {
            foreach ($m->extras as $e) {
                $extras[] = ['name' => $e->title, 'delta' => (float) $e->price, 'xid' => $e->id,
                    'img' => $e->image ? media_url('storage/menu/', $e->image, $store->id ?? null) : ''];
            }
        }

        /* dietary chips */
        $tags = [];
        if ($m->is_vegan) { $tags[] = 'vegan'; }
        $allergyNames = [];
        if (!empty($m->allergies)) {
            $allergyNames = $allergies->whereIn('id', explode(',', $m->allergies))->pluck('title')->values()->all();
        }

        /* gallery ← real images */
        $gallery = [];
        $img = media_url('storage/menu/', $m->image, $store->id ?? null);
        if ($m->image && $m->image !== 'default.png') { $gallery[] = $img; }
        if (!empty($m->gallery_images)) {
            foreach ((array) $m->gallery_images as $g) {
                $gallery[] = media_url('storage/menu/', $g, $store->id ?? null);
            }
        }
        if (empty($gallery)) { $gallery[] = $img; }

        /* reviews for this item */
        $revList = [];
        $dist = [0, 0, 0, 0, 0]; // 5★ → 1★
        $revCollection = $reviewsByMenu->get($m->id, collect());
        foreach ($revCollection as $rv) {
            $s = max(1, min(5, (int) $rv->stars));
            $dist[5 - $s]++;
            $revList[] = [
                'name'     => $rv->reviewer_name ?: ___('Guest'),
                'months'   => (int) round(max(0, now()->diffInDays(\Illuminate\Support\Carbon::parse($rv->created_at))) / 30),
                'rating'   => $s,
                'body'     => (string) ($rv->body ?? ''),
                'verified' => (bool) $rv->is_verified,
                'photos'   => [],
            ];
        }
        $ratingCount = $revCollection->count();
        $ratingAvg = $ratingCount ? round($revCollection->avg('stars'), 1)
                                  : ($m->rating_avg && $m->rating_avg > 0 ? round($m->rating_avg, 1) : null);

        $soldOut = (bool) $m->is_sold_out || ($m->track_inventory && (int) $m->stock_qty <= 0);

        $jsItems[$m->id] = [
            'id'         => $m->id,
            'cat'        => $m->category_id,
            'name'       => $m->name,
            /* decode after stripping: descriptions are stored as numeric HTML
               entities, and a bare strip_tags() leaves them encoded — the raw
               &#1605;… then feeds the search index, so items become unfindable
               by description (and render as literal entities in the sheet). */
            'desc'       => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'price'      => $base,
            // K8: pre-schedule reference price for a strike-through (MenuScheduleService::decorate)
            'original_price' => (!is_null($m->original_price ?? null) && (float) $m->original_price > $base) ? (float) $m->original_price : null,
            // Schedule badge ("Happy Hour") — set by decorate() only while a live schedule discounts the item.
            'saleLabel'  => ($m->schedule_label ?? null) && (!is_null($m->original_price ?? null) && (float) $m->original_price > $base) ? (string) $m->schedule_label : null,
            'rating'     => $ratingAvg,
            'reviews'    => $ratingCount,
            'time'       => $m->prep_time ?? null,
            'kcal'       => $m->calories ?? null,
            'tags'       => $tags,
            'allergens'  => $allergyNames,
            'inStock'    => !$soldOut,
            'featured'   => (bool) $m->is_popular,
            'special'    => (bool) ($m->is_daily_special ?? false),
            'gallery'    => $gallery,
            'weightType' => $isWeighty ? 'weight' : 'pack',
            /* CRUMB-F14: the product page used to print "Select Pack" / "Select Weight"
               from a client-side guess. The store names its own variant group ("Style"),
               so use that and fall back to a neutral heading. */
            'weightGroup' => (string) (optional($m->variantOptions->first())->title ?: ''),
            'weights'    => $weights,
            'extras'     => $extras,
            /* dietary + spice (dietary-filter sheet) */
            'spice'      => (int) $m->spice_level,
            'isVegan'    => (bool) $m->is_vegan,
            'isHalal'    => (bool) $m->is_halal,
            'isGf'       => (bool) $m->is_gluten_free,
            /* global modifier groups (additive; required-validated before add) */
            'optionGroups' => \Juwri\Core\Support\ViewHelpers::optionGroups($m->globalGroups ?? []),
            'reviewList' => $revList,
            'dist'       => $dist,
        ];
    }

    /* categories → tiles (emoji fallback cycle; use image when present).
       $store->menu_categories is TOP-LEVEL ONLY (Post.php applies whereNull('parent')
       and eager-loads subcategories), but every menu row keys off its OWN
       category_id. A parent whose items all live in subcategories would therefore
       match nothing, so each tile also carries its child ids ('kids') and the JS
       matches a chip against its own id OR any of its kids. $catNames covers
       parents AND children so a sub-filed item still shows a category eyebrow. */
    $catEmoji = ['🧁', '🍪', '🍩', '🥖', '🎂', '🥐', '🍰', '🥧'];
    $jsCats = [];
    $catNames = [];
    foreach ($categories as $ci => $c) {
        $catNames[$c->id] = $c->name;
        $kids = [];
        foreach ($c->subcategories as $sc) {
            $kids[] = $sc->id;
            $catNames[$sc->id] = $sc->name;
        }
        $jsCats[] = [
            'id'    => $c->id,
            'name'  => $c->name,
            'emoji' => $catEmoji[$ci % count($catEmoji)],
            'img'   => $c->image ? media_url('storage/menu/', $c->image, $store->id ?? null) : '',
            'kids'  => $kids,
        ];
    }

    /* featured banner / story image */
    $featured = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    $storyImg = $featured ? media_url('storage/menu/', $featured->image, $store->id ?? null) : '';

    /* CRUMB-F25: media_url() happily returns .../logo/default.png for a store with no
       logo, which 404s and left an empty ring behind onerror. Ask for nothing instead
       and let the initial stand in. */
    $sellerImg = ($store->main_image && $store->main_image !== 'default.png')
        ? media_url('storage/store/logo/', $store->main_image, $store->id ?? null)
        : '';
    $sellerInitial = mb_strtoupper(mb_substr(trim((string) $store->title), 0, 1));

    /* Customizer v2 §sections — same contract as scoop: schema defaults first,
       overlay the legacy menu_sections rows, then the theme customizer (the newer
       source of truth) on top. */
    $savedSections = isset($postOptions->menu_sections)
        ? json_decode(json_encode($postOptions->menu_sections), true)
        : null;
    $sectionVisible = ['brand_row' => true, 'catbar' => true, 'menu' => true];
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
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,400..900;1,6..12,400..800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    @if($showBranches)
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    @endif
@endpush

@section('content')
@include('store_templates.crumb._brand')
<div class="app crumb" id="app">

  {{-- ══════════ SPLASH ══════════ --}}
  {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
       `splash.style` picks which one, so the bespoke intro and the shared layer
       can never stack. Defaults (enabled + theme) render this one unchanged. --}}
  @if(theme_setting($store->id, 'splash.enabled', true) && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
  <div class="splash" id="splash">
    <div class="splash-crumbs" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
    <div class="splash-mark">
      <span class="splash-ring">@if((string) ($sellerImg) !== '')<img src="{{ $sellerImg }}" alt="" data-csp-error="closest-hide" data-csp-closest=".splash-ring">@endif</span>
      <h1 class="splash-word">{{ $store->title }}<span>.</span></h1>
      <p class="splash-tag" data-tc-text="splash.title">{{ theme_setting($store->id, 'splash.title', ___('Fresh from the kitchen')) }}</p>
    </div>
    @if($storyImg)<img class="splash-cake" src="{{ $storyImg }}" alt="" data-csp-error="remove">@endif
    <button class="splash-skip" id="splashSkip" type="button">{{ ___('Skip') }}</button>
  </div>
  @endif

  {{-- ══════════ VIEWS ══════════ --}}
  <main class="views" id="views">

    {{-- HOME --}}
    <section class="view is-active" data-view="home">
      @php
          $__crumbHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
          $crumbCoverUrl = $__crumbHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
          $crumbShowCover = $__crumbHasCover && theme_setting($store->id, 'brand.show_cover', true);
      @endphp
      {{-- Collapsing header: slides in once the brown head leaves the scroll
           container (crumb's category tiles are not top-sticky, so no guard). --}}
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
      <div class="view-scroll flush">
        <header class="brown-head{{ $crumbShowCover ? ' has-cover' : '' }}" id="brownHead">
          @if($crumbShowCover)
          {{-- Cover photo fills the brown head; the brown becomes a warm scrim over
               it, keeping crumb's rounded-bottom band + a soft scalloped edge. --}}
          @if((string) ($crumbCoverUrl) !== '')<img class="crumb-cover-img" src="{{ $crumbCoverUrl }}" alt="" aria-hidden="true" data-csp-error="hide">@endif
          <span class="crumb-cover-scrim"></span>
          @endif
          <div class="bh-top">
            @if($showBranches)
            {{-- Multi-branch store: the header selector is a BRANCH picker (crave's
                 cvBranchBtn parity), not a delivery-address field. Label + value are
                 the active branch; tapping opens the branch list (explore view). --}}
            <button class="bh-loc" id="locBtn" type="button" data-branch-open="1">
              <span class="bh-loc-label">{{ ___('Branch') }}</span>
              <span class="bh-loc-value"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg><b id="locText">{{ $branches->first()->name ?? $store->title }}</b><svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></span>
            </button>
            @else
            <button class="bh-loc" id="locBtn" type="button">
              <span class="bh-loc-label">{{ ___('Location') }}</span>
              <span class="bh-loc-value"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg><b id="locText">{{ $store->address ?: $store->title }}</b><svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></span>
            </button>
            @endif
            @if(count($menu_languages ?? []) > 1)
            <button class="bh-icon bh-icon-labeled" id="homeLangBtn" type="button" aria-label="{{ ___('Language') }}">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
              <span>{{ strtoupper(get_lang()) }}</span>
            </button>
            @endif
            <button class="bh-icon" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
              <i class="dot" id="mbNotifDot" hidden></i>
            </button>
            @if(storefront_catering_enabled($store->id))
            <button class="bh-icon" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
            </button>
            @endif
            {{-- Light/dark quick toggle (Lane B) — drives crumb's NATIVE appearance
                 system (data-crumb-theme + store "appearance" key), same store as
                 the #appearanceSeg segment in Profile. --}}
            <button class="bh-icon" type="button" id="crumbThemeToggle"
                    aria-pressed="false" aria-label="{{ ___('Toggle dark mode') }}">
              <svg class="ic mb-th-moon" viewBox="0 0 24 24"><path d="M20.5 14.4A8.5 8.5 0 1 1 9.6 3.5a6.8 6.8 0 0 0 10.9 10.9Z"/></svg>
              <svg class="ic mb-th-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.6 12h2.2M19.2 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
            </button>
          </div>
          {{-- Customizer v2: the storefront search bar is switchable from the theme
               customizer, so a store that browses by category only can drop it. This
               row is the only entry point into the search overlay below, and it is
               enabled by default, which keeps the theme unchanged. --}}
          @if(theme_setting($store->id, 'search.enabled', true))
          <div class="bh-search">
            <button class="bh-search-field" id="homeSearchBtn" type="button">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
              <span>{{ ___('Search the menu…') }}</span>
            </button>
            <button class="bh-filter" id="homeFilterBtn" type="button" aria-label="{{ ___('Filters') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
            </button>
          </div>
          @endif
        </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


        <div class="body-pad">
          @if($allow_order)
          {{-- Hidden, kept in the DOM: the shared #mbOrderType bar (anchored above the
               categories) is the visible picker now, and proxies its clicks to these
               buttons for the actual order-type state. --}}
          <div class="seg home-order-types" style="display:none" aria-label="{{ ___('Order type') }}">
            @if($allow_delivery)<button data-ot="delivery" type="button">{{ ___('Delivery') }}</button>@endif
            @if($allow_takeaway)<button data-ot="takeaway" type="button">{{ ___('Pickup') }}</button>@endif
            @if($allow_on_table)<button data-ot="on-table" type="button">{{ ___('Dine-in') }}</button>@endif
            @if($allow_room_service)<button data-ot="room_service" type="button">{{ ___('Room service') }}</button>@endif
          </div>
          @endif
          @if(!empty($scans_today) && $scans_today > 0)
          <div class="scan-strip"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><b>{{ $scans_today }}</b> {{ ___('people viewing today') }}</div>
          @endif

          {{-- CRUMB-F20 — a customer could not tell whether the shop was open, or how
               long an order takes, anywhere in the theme. --}}
          <div class="store-strip {{ $storeOpen ? '' : 'is-closed' }}">
            <span class="ss-dot" aria-hidden="true"></span>
            <b class="ss-state" data-mb-openchip data-mb-closed-class="ss-state--closed"><span data-mb-open-text>{{ $storeOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></b>
            @if($prepEta)
              <span class="ss-sep" aria-hidden="true">·</span>
              <span>{{ $prepEta }} {{ ___('min') }}</span>
            @endif
            @if($store->address)
              <span class="ss-sep" aria-hidden="true">·</span>
              <span class="ss-addr">{{ $store->address }}</span>
            @endif
          </div>

          {{-- F2 · brand / follow row, ported from crave (Customizer v2 §section, default on) --}}
          @if($sectionVisible['brand_row'] ?? true)
          <div class="brand-row" id="crumbBrandRow" data-mb-hotel-chip-slot>
            <span class="me-avatar sm" aria-hidden="true">{{ $sellerInitial }}@if($sellerImg)<img src="{{ $sellerImg }}" alt="" data-csp-error="remove">@endif</span>
            <span class="brand-info">
              <b class="brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="verified-badge" id="crumbVerifiedBadge" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
              @if(trim((string)($store->sub_title ?? '')) !== '')
              <span class="brand-tagline">{{ $store->sub_title }}</span>
              @endif
              @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true), 'countId' => 'crumbFollowerCount'])
            </span>
            {{-- Following needs a customer account: on a guest_only store the auth sheet
                 is never rendered, so a guest tap opens the login/register sheet instead. --}}
            @if($authMode !== 'guest_only' || $customer)
            <button class="followbtn {{ $isFollowing ? 'is-on' : '' }}" id="crumbFollowBtn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
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
          <div class="crumb-reviews" id="crumbReviews" data-mb-reviews-end>
            <div class="crumb-rating"><span class="crumb-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
            @if($aboutReviews->isNotEmpty())
            <div class="crumb-review-list">
              @foreach($aboutReviews as $rev)
              <div class="crumb-review">
                <div class="crumb-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="crumb-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
              </div>
              @endforeach
            </div>
            @endif
          </div>
          @endif

          {{-- stories rail — stays hidden unless /stories returns items --}}
          <div class="reel-rail" id="reelRail" hidden aria-label="{{ ___('Stories') }}"></div>

          <div class="crumb-banners" id="crumbBanners" hidden aria-label="{{ ___('Offers') }}"></div>

          {{-- CRUMB-F5 — real coupon, or no offer slot at all. --}}
          @if($liveCoupon && $allowCoupons)
          <button class="offer-strip" id="offerStrip" type="button" data-copy-coupon="{{ $liveCoupon->code }}">
            <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/></svg>
            <span>
              <b>{{ ___('Save') }} {{ $liveCoupon->percentage }}%</b>
              <small>{{ ___('Apply it in your cart at checkout') }}</small>
            </span>
            <code>{{ $liveCoupon->code }}</code>
          </button>
          @endif

          {{-- Customizer v2 §sections: catbar — the category tiles + sub-chip rows.
               script.js already null-guards #catRow/#subChips (CRUMB-F30 pattern). --}}
          @if($sectionVisible['catbar'] ?? true)
          <div class="section-head" data-mb-grid-lead data-mbt-anchor>
            <h2>{{ ___('Categories') }}</h2>
            <button class="seeall" id="catSeeAll" type="button">{{ ___('See All') }}</button>
          </div>
          <div class="cat-row" id="catRow" data-mb-grid-lead></div>

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
          @endif

          {{-- Customizer v2 §sections: menu — crumb's single item grid (featured-first,
               "See All" expands to the whole menu). renderFeatured() bails when
               #featGrid is absent (CRUMB-F30), so hiding it is safe. --}}
          @if($sectionVisible['menu'] ?? true)
          <div class="section-head" data-mb-grid-lead>
            <h2 data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___('Featured Products')) }}</h2>
          </div>
          <div class="fgrid" id="featGrid"></div>
          {{-- The home grid is a preview; the "See All" CTA sits BELOW it (soiree parity)
               so the cut-off is obvious and the diner knows the full menu is one tap on. --}}
          <button class="menu-more" id="featSeeAll" type="button"><span class="mm-label">{{ ___('See All') }}</span><svg class="ic" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
          @endif
        </div>
        <div class="tail-space"></div>
      </div>

      {{-- search overlay --}}
      <div class="search-overlay" id="searchOverlay" hidden>
        <div class="so-bar">
          <button class="round-btn" id="soClose" type="button" aria-label="{{ ___('Close') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
          <label class="so-field">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            <input id="soInput" type="search" placeholder="{{ ___('Search the menu…') }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
            <button class="so-clear" id="soClear" type="button" aria-label="{{ ___('Clear') }}">&#215;</button>
          </label>
        </div>
        @if($allowDietary)
        <div class="diet-row" id="dietRow" aria-label="{{ ___('Dietary filters') }}">
          <button data-diet="vegan" type="button">🌱 <span>{{ ___('Vegan') }}</span></button>
          <button data-diet="halal" type="button">☪ <span>{{ ___('Halal') }}</span></button>
          <button data-diet="gf" type="button">🌾 <span>{{ ___('Gluten-free') }}</span></button>
          <span class="diet-spice">
            <span class="diet-spice-lbl">🌶 {{ ___('Max spice') }}</span>
            <button data-spice="0" type="button">{{ ___('Mild') }}</button>
            <button data-spice="1" type="button">🌶</button>
            <button data-spice="2" type="button">🌶🌶</button>
            <button data-spice="3" class="is-on" type="button">🌶🌶🌶</button>
          </span>
        </div>
        @endif
        <p class="so-label" id="soLabel" hidden>{{ ___('POPULAR') }}</p>
        <div class="so-recent" id="soRecent" hidden></div>
        <div class="so-results" id="soResults"></div>
        <p class="so-empty" id="soEmpty" hidden>{{ ___('Nothing matches — try another word') }}</p>
      </div>
    </section>

    {{-- EXPLORE / branches (CRUMB-F15)
         The demo shipped a hand-drawn SVG city map — roads, a park, a river and a
         pulsing "you are here" pin — plus a "Search Bakeries" field that searched
         nothing. None of it corresponded to any geography and it was a whole
         bottom-nav destination showing invented data. It is gone; the tab now
         renders only for a multi-branch store, and only real branches, backed by
         the theme's existing self-hosted Leaflet map sheet. --}}
    @if($showBranches)
    <section class="view" data-view="explore">
      <div class="view-scroll">
        <header class="page-head"><h1>{{ ___('Our branches') }}</h1></header>
        <button class="nearest-branch-btn" id="crumbNearestBranchBtn" type="button">
          <span class="loc-ic"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg></span>
          <span>{{ ___('Find nearest branch') }}</span>
        </button>
        <div class="branch-cards" id="branchCards"></div>
        <div class="tail-space"></div>
      </div>
    </section>
    @endif

    {{-- WISHLIST --}}
    <section class="view" data-view="wishlist">
      <div class="view-scroll">
        <header class="page-head"><h1>{{ ___('Wishlist') }}</h1></header>
        <div class="fgrid" id="wishGrid"></div>
        <div class="empty" id="wishEmpty" hidden>
          <svg viewBox="0 0 64 64" class="empty-art"><path d="M32 50s-18-11-18-24c0-7 5.4-12 11.2-12 3.4 0 5.6 2 6.8 3.8C33.2 16 35.4 14 38.8 14 44.6 14 50 19 50 26c0 13-18 24-18 24Z"/></svg>
          <p>{{ ___('Tap the heart on any dish to save it here') }}</p>
        </div>
        <div class="tail-space"></div>
      </div>
    </section>

    {{-- CHAT (CRUMB-F4) — REMOVED.
         crumb shipped a top-level bottom-nav "Chat" destination with a message list,
         a text input and a send button. sendChat() posted to nothing at all; a canned
         botReply() answered "A baker will reply shortly". A customer typing "I have a
         peanut allergy, please omit peanuts" was told the restaurant had been
         reached, and it never was. The real, owner-gated AI chat widget is included
         at the bottom of this file (store_templates.partials.agent-chat); WhatsApp is
         one tap away on the Profile tab. Nothing here pretends to be either. --}}

    {{-- PROFILE --}}
    <section class="view" data-view="profile">
      <div class="view-scroll">
        <header class="page-head"><h1>{{ ___('Profile') }}</h1></header>
        <div class="me-card">
          <span class="me-avatar" aria-hidden="true">{{ $sellerInitial }}@if($sellerImg)<img src="{{ $sellerImg }}" alt="" data-csp-error="remove">@endif</span>
          <div><b id="meName">{{ $customer->name ?? ___('Guest') }}</b>
            <p class="sub-label">{{ $customer
                ? ($customer->phone ?? $customer->email)
                : ($authMode !== 'guest_only'
                    ? ___('Sign in to keep your orders and addresses together.')
                    : ___('Welcome to').' '.$store->title) }}</p>
          </div>
          {{-- The sign-in entry belongs on the identity card itself, not as a
               stray ghost button at the bottom of the view. --}}
          @if($authMode !== 'guest_only' && !$customer)
          <button class="minibtn brown" id="crumbAuthBtn" type="button">{{ ___('Login') }}</button>
          @elseif($allow_order)
          <button class="round-btn beige" data-go="cart" type="button" aria-label="{{ ___('Cart') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg></button>
          @endif
        </div>

        @if($loyaltyProgram && $customer)
        <button class="loyalty-card" id="redeemBtn" type="button">
          <div><p class="sub-label light">{{ ___('Loyalty points') }}</p><b class="loy-amt"><span id="pointsVal">–</span> <small>{{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('pts') }}</small></b></div>
          <span class="loy-cta">{{ ___('Redeem') }}</span>
        </button>
        @endif

        @if($multiCurrency || count($menu_languages ?? []) > 1)
        <section class="pref">
          <h3>{{ ___('Preferences') }}</h3>
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
          @if($multiCurrency)
          <div class="pref-row">
            <span><svg class="ic" viewBox="0 0 24 24"><path d="M12 3v18M8 7.5C8 6 9.6 5 12 5s4 1 4 2.5S14.5 10 12 10s-4 1-4 2.5S9.5 15 12 15s4 1 4 2.5-1.6 2.5-4 2.5-4-1-4-2.5"/></svg><span>{{ ___('Currency') }}</span></span>
            <div class="seg mini" id="curSeg">
              <button data-cur="{{ $baseCur }}" data-rate="1" class="is-on" type="button">{{ $baseCur }}</button>
              @foreach($mcRates as $code => $rate)
                <button data-cur="{{ $code }}" data-rate="{{ $rate }}" type="button">{{ $code }}</button>
              @endforeach
            </div>
          </div>
          @endif
        </section>
        @endif

        @if($reservationsEnabled || $waitlistEnabled)
        <section class="resv-block">
          <h3>{{ $reservationsEnabled ? ___('Table reservation') : ___('Waitlist') }}</h3>
          <div class="resv-row">
            <p class="resv-info" id="resvInfo">{{ ___('No booking yet') }}</p>
            {{-- reserveBtn only opens #reserveSheet, which renders only when
                 reservations are on; give the waitlist its own button so it is
                 reachable both alongside reservations and in a waitlist-only
                 store (otherwise #waitlistSheet has no entry point). --}}
            @if($reservationsEnabled)
            <button class="minibtn brown" id="reserveBtn" type="button">{{ ___('Book a table') }}</button>
            @endif
            @if($waitlistEnabled)
            <button class="minibtn brown" id="waitlistBtn" type="button">{{ ___('Join the Waitlist') }}</button>
            @endif
          </div>
        </section>
        @endif

        {{-- CRUMB-F7 — crumb had no dark mode at all. --}}
        <section class="pref appearance-row">
          <h3>{{ ___('Appearance') }}</h3>
          <div class="pref-row">
            <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"/></svg><span>{{ ___('Theme') }}</span></span>
            <div class="seg mini" id="appearanceSeg">
              <button data-appearance="auto" type="button">{{ ___('Auto') }}</button>
              <button data-appearance="light" type="button">{{ ___('Light') }}</button>
              <button data-appearance="dark" type="button">{{ ___('Dark') }}</button>
            </div>
          </div>
        </section>

        <section class="orders-block">
          <h3>{{ ___('My orders') }}</h3>
          @if($customer)
            <a class="order-row link" href="{{ route('customer.profile', $store->id) }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
              <div><b>{{ ___('Profile & settings') }}</b><small>{{ ___('Details, addresses & preferences') }}</small></div>
              <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            <a class="order-row link" href="{{ route('customer.account', $store->id) }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>
              <div><b>{{ ___('View order history') }}</b><small>{{ ___('All your orders in one place') }}</small></div>
              <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
          @else
            @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
            <p class="sheet-sub">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
            <div class="coupon-field order-lookup">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
              <input aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" id="lookupInput" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" autocomplete="email">
              <button class="minibtn brown" id="lookupBtn" type="button">{{ ___('Find') }}</button>
            </div>
          @endif
          <div id="ordersList" class="orders-list"></div>
          <p class="orders-empty" id="ordersEmpty">{{ ___('Orders you place appear here') }}</p>
        </section>

        @if($waNumber)
        <a class="wa-line" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener" id="waLine"><svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg><span>{{ ___('Chat with us on WhatsApp') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></a>
        @endif
        {{-- Share this menu — native profile row (like the WhatsApp .wa-line), not the floating pill.
             Gated on the same share.fallback_button toggle; data-web-share stands down _partials/share. --}}
        @if(theme_setting($store->id, 'share.fallback_button'))
        <button class="wa-line share-btn" id="mbShareRow" type="button" data-web-share aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg><span>{{ ___('Share this menu') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></button>
        @endif
        @if($authMode !== 'guest_only' && $customer)
          <a class="ghost-line" href="{{ route('customer.logout', $store->id) }}">{{ ___('Logout') }}</a>
        @elseif($authMode !== 'guest_only')
          {{-- Email/password entry lives on the me-card at the top (#crumbAuthBtn);
               the social providers keep their own block down here. --}}
          {{-- CRUMB-F17: crumb was one of 4 themes missing this include, so its customers
               could not social-sign-in at all. --}}
          <section class="social-block">
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
            @include('store_templates.partials.passkey-login', ['store' => $store])
          </section>
        @endif
        {{-- Privacy — wipe the cart / favourites / saved addresses this browser
             keeps for this store (shared window.mbClearDeviceData engine). Lives in
             the profile surface, which is guest-reachable (Appearance + order lookup
             render for guests too), so it never hides behind a login. --}}
        <button class="wa-line crumb-cleardata" id="crumbClearData" type="button">
          <svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M6 7l1 12a2 2 0 0 0 2 1.8h6a2 2 0 0 0 2-1.8L18 7M10 11v6M14 11v6"/></svg>
          <span>{{ ___('Clear saved data on this device') }}</span>
        </button>
        <p class="crumb-cleardata-hint" id="crumbClearDataHint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
        <p class="demo-foot">{{ $store->title }}</p>
        <div class="tail-space"></div>
      </div>
    </section>

    {{-- PRODUCT --}}
    <section class="view view-sub" data-view="product">
      <div class="view-scroll flush" id="productScroll">
        <div class="p-hero">
          {{-- no src= until JS sets one: an empty src refetches the page URL --}}
          <img id="pHeroImg" alt="" data-csp-error="opacity">
          <button class="fab back" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
          <span class="p-hero-actions">
            <button class="fab" id="pFav" type="button" aria-label="{{ ___('Favorite') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
            <button class="fab" id="pShare" type="button" aria-label="{{ ___('Share') }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="12" r="2.6"/><circle cx="17.5" cy="5.5" r="2.6"/><circle cx="17.5" cy="18.5" r="2.6"/><path d="m8.4 10.8 6.8-4M8.4 13.2l6.8 4"/></svg></button>
          </span>
          <div class="p-thumbs" id="pThumbs"></div>
        </div>
        <div class="p-body">
          <div class="p-eyebrow-row"><span class="p-eyebrow" id="pEyebrow"></span><span class="p-rate" id="pRating"></span></div>
          <h1 class="p-title"><span id="pTitle"></span> <i class="stock-dot" id="pStockDot"></i></h1>

          <div class="seller-row" id="pSeller"></div>
          <div class="p-meta" id="pMeta"></div>

          <div class="p-desc-block">
            <h3>{{ ___('Description') }}</h3>
            <p class="p-desc clamp" id="pDesc"></p>
            <button class="readmore" id="pReadMore" type="button">{{ ___('Read more') }}</button>
          </div>

          <div class="p-weight-block">
            <h3 id="pWeightLabel">{{ ___('Select Weight') }}</h3>
            <div class="wchips" id="pWeights"></div>
          </div>

          {{-- global modifier groups (required/min/max, additive pricing) --}}
          <div class="p-groups" id="pGroups"></div>

          <div class="p-extras-block" id="pExtrasBlock">
            <h3>{{ ___('Extras') }}</h3>
            <div class="opt-list" id="pExtras"></div>
          </div>

          @if($allow_order)
          <div class="p-qty-row">
            <h3>{{ ___('Quantity') }}</h3>
            <div class="qty" id="pQty">
              <button class="qty-btn" data-q="-1" type="button" aria-label="{{ ___('Decrease') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
              <output id="pQtyVal">01</output>
              <button class="qty-btn" data-q="1" type="button" aria-label="{{ ___('Increase') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
            </div>
          </div>
          @endif

          <button class="p-reviews-row" data-go="reviews" id="pRevRow" type="button">
            <span class="prr-star"><svg class="ic star-f" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg></span>
            <b>{{ ___('Reviews') }} <span id="pRevCount">(0)</span></b>
            <span class="prr-avg" id="pRevAvg"></span>
            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
          </button>
        </div>
        <div class="tail-space tall"></div>
      </div>
      @if($allow_order)
      <footer class="p-cta">
        <div class="p-total"><small>{{ ___('Total Price') }}</small><b id="pTotal"></b></div>
        <button class="cta" id="addToCart" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><span>{{ ___('Add to Cart') }}</span></button>
        {{-- shown by script.js once the cart has items: a direct jump to the cart --}}
        <button class="p-cart-jump" id="pCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
          <svg class="ic" viewBox="0 0 24 24"><path d="M3 5h2.2l2.5 10.2a2 2 0 0 0 2 1.5h6.9a2 2 0 0 0 1.95-1.55L20.5 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
          <i class="p-cart-jump-count" id="pCartJumpCount" aria-hidden="true">0</i>
        </button>
      </footer>
      @endif
    </section>

    {{-- REVIEWS --}}
    <section class="view view-sub" data-view="reviews">
      <div class="view-scroll">
        <header class="page-head sub">
          <button class="round-btn" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
          <h1>{{ ___('Review') }}</h1>
        </header>

        @if($googleReviewUrl)
        <div class="rv-google" id="rvGoogle" hidden>
          <span>💛</span>
          <p><b>{{ ___('Thanks for your review!') }}</b><br><span>{{ ___('Loved it? Rate us on Google too') }}</span></p>
          <a class="minibtn brown" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener" id="rvGoogleBtn">Google ↗</a>
        </div>
        @endif

        <div class="rv-summary">
          <div class="rv-score">
            <b id="rvAvg">–</b>
            <span class="rv-stars" id="rvStars"></span>
            <small id="rvCount"></small>
          </div>
          <div class="rv-bars" id="rvBars"></div>
        </div>

        <label class="so-field cream slim">
          <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
          <input aria-label="{{ ___('Search in reviews') }}" id="rvSearch" type="search" placeholder="{{ ___('Search in reviews') }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
        </label>

        <div class="rv-chips" id="rvChips">
          <button class="rvc" id="rvFilterBtn" type="button"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></svg><span>{{ ___('Filter') }}</span> ▾</button>
          <button class="rvc" data-rvf="verified" type="button">{{ ___('Verified') }}</button>
          <button class="rvc is-dark" data-rvf="latest" type="button">{{ ___('Latest') }}</button>
          <button class="rvc" data-rvf="detailed" type="button">{{ ___('Detailed Reviews') }}</button>
        </div>

        <div class="rv-list" id="rvList"></div>
        <p class="so-empty" id="rvEmpty" hidden>{{ ___('No reviews match') }}</p>
        <div class="tail-space tall"></div>
      </div>
      <footer class="p-cta">
        <button class="cta wide" id="writeReviewBtn" type="button"><span>{{ ___('Write Review') }}</span></button>
      </footer>
    </section>

    {{-- LEAVE REVIEW --}}
    <section class="view view-sub" data-view="review-new">
      <div class="view-scroll">
        <header class="page-head sub">
          <button class="round-btn" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
          <h1>{{ ___('Write Review') }}</h1>
        </header>

        <div class="wr-summary">
          <img id="wrSumImg" alt="" data-csp-error="visibility">
          <div>
            <b id="wrSumName"></b>
            <p class="sub-label" id="wrSumMeta"></p>
            <b class="wr-price" id="wrSumPrice"></b>
          </div>
        </div>

        <h2 class="wr-q">{{ ___('How is your order?') }}</h2>
        <p class="wr-sub">{{ ___('Your overall rating') }}</p>
        <div class="wr-stars" id="wrStars" role="radiogroup" aria-label="{{ ___('Rating') }}"></div>

        @if(!$customer)
        <input aria-label="{{ ___('Your name') }}" id="wrName" placeholder="{{ ___('Your name') }}" autocomplete="name" style="margin-top:14px">
        @endif
        <h3 class="wr-label">{{ ___('Add detailed review') }}</h3>
        <textarea aria-label="{{ ___('Enter here') }}" id="wrText" rows="4" placeholder="{{ ___('Enter here') }}"></textarea>
        <p class="err-box" id="wrErr" hidden></p>
        <div class="tail-space tall"></div>
      </div>
      <footer class="p-cta duo">
        <button class="ghost-cta" id="wrCancel" type="button">{{ ___('Cancel') }}</button>
        <button class="cta" id="wrSubmit" type="button"><span>{{ ___('Submit') }}</span><span class="spinner" hidden></span></button>
      </footer>
    </section>

    {{-- LOCATION PICKER --}}
    <section class="view view-sub" data-view="location">
      <div class="view-scroll flush noscroll loc-layout">
        <header class="page-head sub pad">
          <button class="round-btn" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
          <h1>{{ ___('Enter Your Location') }}</h1>
        </header>
        <div class="loc-pad">
          {{-- FIX-3c — the diner's saved account addresses; tap to fill + pick. --}}
          @if($customerAddresses->count())
          <div class="saved-addrs" id="savedAddrs" aria-label="{{ ___('Saved address') }}">
            @foreach($customerAddresses as $sa)
            <button class="saved-addr{{ $loop->first ? ' is-on' : '' }}" type="button"
                    data-address="{{ $addrLabel($sa) }}" data-lat="{{ $sa->lat }}" data-lng="{{ $sa->lng }}">
              <span class="loc-ic"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg></span>
              <span class="sa-text"><b>{{ $sa->label ?: ___('Saved address') }}</b><small>{{ Str::limit($addrLabel($sa), 42) }}</small></span>
            </button>
            @endforeach
          </div>
          @endif
          <label class="so-field cream">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            <input id="locInput" value="" placeholder="{{ ___('Street, area, city…') }}" autocomplete="off">
            <button class="so-clear" id="locClear" type="button" aria-label="{{ ___('Clear') }}">&#215;</button>
          </label>
          <button class="use-current" id="useCurrent" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <span>{{ ___('Use my current location') }}</span>
          </button>
          {{-- Drop-a-pin address picker (shared window.mbMapPicker engine; crumb-skinned
               bottom sheet below). Fills the same #locInput the checkout submits. --}}
          <button class="use-current crumb-map-trigger" id="crumbMapBtn" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M9 4 3 6.5v13L9 17l6 2.5 6-2.5v-13L15 6.5 9 4Z"/><path d="M9 4v13M15 6.5v13"/></svg>
            <span>{{ ___('Choose on map') }}</span>
          </button>
          <p class="so-label">{{ ___('SAVED') }}</p>
          <div class="loc-results" id="locResults"></div>
          <p class="geo-attrib" id="locGeoAttrib" hidden></p>
        </div>
        <div class="kb" id="kb" dir="ltr" aria-hidden="true" hidden></div>
      </div>
    </section>

    {{-- STORY (CRUMB-F5/F16) — REMOVED.
         A single hard-coded "story" whose caption advertised "Up to 40% off signature
         cakes" and whose like counter was seeded at "2.4k" and incremented locally on
         tap. Real, owner-published stories still render — the #reelRail on the home
         surface and its #reelViewer below are fed by the /stories endpoint and show
         nothing when the store has published nothing. --}}

    {{-- CART --}}
    @if($allow_order)
    <section class="view view-sub" data-view="cart">
      <div class="view-scroll">
        <header class="page-head sub">
          <button class="round-btn" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
          <h1>{{ ___('My Cart') }}</h1>
          <button class="seeall danger" id="clearCart" type="button">{{ ___('Clear') }}</button>
        </header>
        <div id="cartList" class="cart-list"></div>
        <div class="empty" id="cartEmpty" hidden>
          <svg viewBox="0 0 64 64" class="empty-art"><path d="M14 18h6l5 24h22l5-18H24"/><circle cx="28" cy="50" r="3.4"/><circle cx="44" cy="50" r="3.4"/></svg>
          <p>{{ ___('Your cart is empty') }}</p>
          <button class="minibtn brown" data-go="home" type="button">{{ ___('Browse the menu') }}</button>
        </div>

        <div id="cartTools" class="cart-tools">
          <section>
            <h3>{{ ___('Order type') }}</h3>
            <div class="seg" id="orderTypeSeg">
              @if($allow_delivery)<button data-ot="delivery" class="is-on" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg><span>{{ ___('Delivery') }}</span></button>@endif
              @if($allow_takeaway)<button data-ot="takeaway" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><span>{{ ___('Pickup') }}</span></button>@endif
              @if($allow_on_table)<button data-ot="on-table" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg><span>{{ ___('Dine-in') }}</span></button>@endif
              @if($allow_room_service)<button data-ot="room_service" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 6v2M10.5 6h3"/></svg><span>{{ ___('Room service') }}</span></button>@endif
            </div>
            <div class="ot-fields" id="otFields"></div>
          </section>

          @if($allowCoupons)
          <section>
            <label class="coupon-field">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
              <input id="couponInput" placeholder="{{ ___('Promo code') }}">
              <button class="minibtn brown" id="couponApply" type="button">{{ ___('Apply') }}</button>
            </label>
            <p class="coupon-msg" id="couponMsg" hidden></p>
          </section>
          @endif

          <section class="ordernote">
            <label><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5V4Z"/><path d="M15 4v4h4M8.5 12h7M8.5 16h5"/></svg><span>{{ ___('Note for the kitchen') }}</span></label>
            <textarea aria-label="{{ ___('Anything we should know? Allergies, doorbell…') }}" id="orderNote" rows="2" placeholder="{{ ___('Anything we should know? Allergies, doorbell…') }}"></textarea>
            @include('store_templates._partials.voice-note-recorder')
          </section>

          <section class="totals" id="totalsBox"></section>
          <p class="minorder" id="minOrderMsg" hidden></p>

          @if($allowWaiter && $allow_on_table)
          <div class="waiter-row" id="waiterRow" hidden>
            <button class="ghost-cta sm" id="callWaiter" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span>{{ ___('Call waiter') }}</span></button>
            <button class="ghost-cta sm" id="requestBill" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg><span>{{ ___('Request bill') }}</span></button>
          </div>
          @endif
        </div>
        <div class="tail-space tall"></div>
      </div>
      <footer class="p-cta" id="cartCta" hidden>
        <button class="cta wide" id="goCheckout" type="button"><span>{{ ___('Checkout') }}</span><b id="cartCtaTotal"></b></button>
      </footer>
    </section>

    {{-- CHECKOUT --}}
    <section class="view view-sub" data-view="checkout">
      <div class="view-scroll">
        <header class="page-head sub">
          <button class="round-btn" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
          <h1>{{ ___('Checkout') }}</h1>
        </header>

        <section class="ck-block">
          <h3>{{ ___('Contact') }}</h3>
          <div class="field-duo">
            <input aria-label="{{ ___('Your name') }}" id="custName" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}" autocomplete="name">
            <input aria-label="{{ ___('Phone') }}" id="custPhone" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}" inputmode="tel" autocomplete="tel">
          </div>
        </section>

        <section class="ck-block" id="ckContext"></section>

        <section class="ck-block">
          <h3>{{ ___('Pay with') }}</h3>
          <div class="pm-list" id="pmList"></div>
          <div class="cardform" id="cardForm" hidden>
            <p class="card-note">{{ ___('You will be redirected to a secure payment page.') }}</p>
          </div>
        </section>

        @if($customer && $storeCredit > 0)
        <section class="ck-block">
          <h3>{{ ___('Store credit') }}</h3>
          <label class="terms">
            <input type="checkbox" id="mbUseWallet">
            <span>{{ ___('Pay with store credit') }} ({{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }})</span>
          </label>
        </section>
        @endif

        @if($allowOrderSched)
        <section class="ck-block">
          <h3>{{ ___('Schedule for later') }}</h3>
          <div class="schedule-row">
            <span class="sched-label"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ ___('Pick a time') }}</span>
            <input aria-label="{{ ___('Pick a time') }}" type="datetime-local" name="scheduled_at" id="scheduledAt" class="when-time" min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}" max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
          </div>
        </section>
        @endif

        @if($allowTips)
        <section class="ck-block">
          <h3>{{ ___('Add a tip') }}</h3>
          <div class="tip-row" id="tipRow">
            <button data-tip="0" class="is-on" type="button">{{ ___('None') }}</button>
            <button data-tip="5" type="button">5%</button>
            <button data-tip="10" type="button">10%</button>
            <button data-tip="15" type="button">15%</button>
          </div>
        </section>
        @endif

        {{-- Order summary — the customer should see WHAT they are paying for at
             the point of commitment, not just a number. Rendered by renderCheckout. --}}
        <section class="ck-items" id="ckItems" hidden></section>
        <section class="totals" id="checkoutTotals"></section>
        <label class="terms"><input type="checkbox" id="termsChk" checked><span>{{ ___('I accept the terms of use and privacy policy') }}</span></label>
        <p class="err-box" id="checkoutErr" hidden></p>
        <div class="tail-space tall"></div>
      </div>
      <footer class="p-cta">
        <button class="cta wide" id="placeOrder" type="button"><span class="po-label">{{ ___('Place Order') }}</span><b id="checkoutCtaTotal"></b><span class="spinner" hidden></span></button>
      </footer>
    </section>

    {{-- CONFIRMED --}}
    <section class="view view-sub view-center" data-view="confirmed">
      <div class="confirm-wrap">
        <div class="confetti" id="confetti" aria-hidden="true"></div>
        <div class="confirm-badge" aria-hidden="true">
          <svg viewBox="0 0 96 96"><circle cx="48" cy="48" r="40"/><path class="check" d="M32 49l11 11 21-24" pathLength="1"/></svg>
        </div>
        <h1 class="confirm-title">{{ ___('Order Confirmed') }}</h1>
        <p class="confirm-sub"><span>{{ ___('Your order is being prepared') }}</span><br><span class="confirm-eta" id="confirmEta"></span></p>
        <div class="confirm-code" id="confirmCode"></div>
        <div class="confirm-pts" id="confirmPts"></div>
        <div class="confirm-actions">
          {{-- CRUMB-F2/F3: opens the REAL server-rendered track page (the order id,
               status and ETA all come from the order row), not an in-app fake. --}}
          <a class="cta wide" id="confirmTrack" href="#" hidden><span>{{ ___('Track Order') }}</span></a>
          <button class="ghost-cta" id="backToMenu" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span>{{ ___('Back to menu') }}</span></button>
        </div>
      </div>
    </section>

    {{-- TRACK (CRUMB-F3) — REMOVED.
         The in-app timeline advanced on a setInterval and reached "Delivered" 24s
         after load without ever contacting the server. Order status is now only ever
         shown by the server-rendered track page (track.blade.php) reached through the
         `track_url` the order response returns, which prints the real orders.id, the
         real status and the real ETA. --}}
    @endif

  </main>

  {{-- ══════════ BOTTOM NAV ══════════ --}}
  @include('store_templates._partials.notif-bell')

  <nav class="tabbar" id="tabbar" aria-label="{{ ___('Menu') }}">
    <button class="tab is-on" data-tab="home" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span class="tab-label">{{ ___('Home') }}</span></button>
    <button class="tab" data-tab="menu" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg><span class="tab-label">{{ ___('Menu') }}</span></button>
    @if(theme_setting($store->id, 'search.enabled', true))
    <button class="tab" data-tab="search" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg><span class="tab-label">{{ ___('Search') }}</span></button>
    @endif
    @if($allow_order)
    {{-- CRUMB-F4: the slot the fake chatbot used to occupy now goes to a real destination. --}}
    <button class="tab tab--cart" data-tab="cart" type="button"><span class="tab-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><i class="badge" id="cartBadge" hidden>0</i></span><span class="tab-label">{{ ___('My Cart') }}</span></button>
    @endif
    <button class="tab" data-tab="wishlist" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg><span class="tab-label">{{ ___('Wishlist') }}</span></button>
    <button class="tab" data-tab="profile" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg><span class="tab-label">{{ ___('Profile') }}</span></button>
    @if($showBranches)
    <button class="tab" data-tab="explore" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg><span class="tab-label">{{ ___('Branches') }}</span></button>
    @endif
  </nav>

  {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
  <div class="scrim" id="scrim" hidden></div>

  @if(storefront_catering_enabled($store->id))
  <div class="sheet" id="cvCateringSheet" hidden>
    <div class="sheet-grab"></div>
    @include('store_templates._partials.catering-form', ['cateringInline' => true])
  </div>
  @endif

  @if(count($menu_languages ?? []) > 1)
  <div class="sheet" id="homeLangSheet" hidden>
    <div class="sheet-grab"></div>
    <h2>{{ ___('Choose language') }}</h2>
    <div class="seg language-sheet-options" id="homeLangSeg">
      @foreach($menu_languages as $language)
        <button data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" type="button" class="{{ get_lang() === $language->code ? 'is-on' : '' }}">{{ $language->name }}</button>
      @endforeach
    </div>
  </div>
  @endif

  @if($authMode !== 'guest_only' && !$customer)
  <div class="sheet sheet-tall" id="crumbAuthSheet" hidden>
    <div class="sheet-grab"></div>
    <h2>{{ ___('Your account') }}</h2>
    <p class="sheet-sub">{{ ___('Sign in to keep your orders and addresses together.') }}</p>
    <div class="seg" id="crumbAuthTabs">
      <button class="is-on" data-crumb-auth-tab="login" type="button">{{ ___('Login') }}</button>
      <button data-crumb-auth-tab="otp" type="button">{{ ___('Phone OTP') }}</button>
      <button data-crumb-auth-tab="register" type="button">{{ ___('Register') }}</button>
    </div>
    <div class="sheet-form" data-crumb-auth-form="login">
      <input aria-label="{{ ___('Email') }}" id="crumbLoginEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
      <input aria-label="{{ ___('Password') }}" id="crumbLoginPassword" type="password" placeholder="{{ ___('Password') }}" autocomplete="current-password">
      <button class="cta wide" id="crumbLoginSubmit" type="button">{{ ___('Login') }}</button>
    </div>
    <div class="sheet-form" data-crumb-auth-form="register" hidden>
      <input aria-label="{{ ___('Your name') }}" id="crumbRegisterName" placeholder="{{ ___('Your name') }}" autocomplete="name">
      <input aria-label="{{ ___('Email') }}" id="crumbRegisterEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
      <input aria-label="{{ ___('Phone') }}" id="crumbRegisterPhone" placeholder="{{ ___('Phone') }}" inputmode="tel" autocomplete="tel">
      <input aria-label="{{ ___('Password') }}" id="crumbRegisterPassword" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
      <input aria-label="{{ ___('Confirm password') }}" id="crumbRegisterPasswordConfirm" type="password" placeholder="{{ ___('Confirm password') }}" autocomplete="new-password">
      <button class="cta wide" id="crumbRegisterSubmit" type="button">{{ ___('Create account') }}</button>
    </div>
    {{-- A3 · phone-OTP login (posts the collected code under `code`) --}}
    <div class="sheet-form" data-crumb-auth-form="otp" hidden>
      <div id="crumbOtpStep1">
        <p class="sheet-sub">{{ ___("We'll text you a one-time code") }}</p>
        <input aria-label="{{ ___('Phone number') }}" id="crumbOtpPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
        <button class="cta wide" id="crumbOtpSend" type="button" style="margin-top:8px">{{ ___('Send code') }}</button>
      </div>
      <div id="crumbOtpStep2" hidden>
        <p class="sheet-sub">{{ ___('Enter the code we sent you') }}</p>
        <div class="otp-boxes" id="crumbOtpBoxes">
          <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
        </div>
        <button class="cta wide" id="crumbOtpVerify" type="button" style="margin-top:8px">{{ ___('Verify') }}</button>
      </div>
    </div>
    <p class="err-box" id="crumbAuthError" hidden></p>
  </div>
  @endif

  @if($allow_order)
  <div class="sheet" id="removeSheet" hidden>
    <div class="sheet-grab"></div>
    <h2>{{ ___('Remove from Cart?') }}</h2>
    <div class="rm-row">
      <img id="rmThumb" alt="" data-csp-error="visibility">
      <div><b id="rmName"></b><p class="sub-label" id="rmMeta"></p></div>
      <b class="rm-price" id="rmPrice"></b>
    </div>
    <div class="sheet-actions">
      <button class="ghost-cta" id="rmCancel" type="button">{{ ___('Cancel') }}</button>
      <button class="cta danger" id="rmConfirm" type="button">{{ ___('Remove') }}</button>
    </div>
  </div>
  @endif

  @if($loyaltyProgram && $customer)
  <div class="sheet" id="loyaltySheet" hidden>
    <div class="sheet-grab"></div>
    <h2>{{ ___('Loyalty rewards') }}</h2>
    <p class="sheet-sub">{{ ___('You have') }} <b id="loyPts">–</b> {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('pts') }} · {{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }}</p>
    <div class="reward-list" id="rewardList"></div>
    <button class="cta wide" id="loyaltyRedeem" type="button" hidden><span>{{ ___('Redeem Reward') }}</span></button>
    <p class="loyalty-ok" id="loyaltyOk" hidden>{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
  </div>
  @endif

  @if($reservationsEnabled)
  <div class="sheet" id="reserveSheet" hidden>
    <div class="sheet-grab"></div>
    {{-- Explicit close (top corner); wired in JS (crumb closes via the scrim). --}}
    <button class="sheet-x" type="button" aria-label="{{ ___('Close') }}">
      <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
    </button>
    <h2>{{ ___('Book a table') }}</h2>
    @if(session('reservation_success'))
      <div class="sheet-done"><span class="sheet-done-emoji">📅</span><b>{{ ___('Request received!') }}</b><p>{{ session('reservation_success') }}</p></div>
    @else
      <form class="sheet-form" id="reserveForm" action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
        @csrf
        <h4>{{ ___('Your Name') }}</h4>
        <input aria-label="{{ ___('Your name') }}" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}">
        <h4>{{ ___('Phone number') }}</h4>
        <input aria-label="{{ ___('Phone') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}">
        <h4>{{ ___('Date & Time') }}</h4>
        <input aria-label="{{ ___('Date & Time') }}" name="reserved_at" type="datetime-local" required min="{{ now()->format('Y-m-d\TH:i') }}">
        <h4>{{ ___('Guests') }}</h4>
        <div class="rs-guests">
          <div class="qty">
            <button class="qty-btn" type="button" id="rsGm" aria-label="{{ ___('Fewer') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
            <output id="rsGuestsVal">2</output>
            <button class="qty-btn" type="button" id="rsGp" aria-label="{{ ___('More') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
          </div>
        </div>
        <input type="hidden" name="party_size" id="rsPartySize" value="2">
        <div class="sheet-actions">
          @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
          <button class="cta wide" type="submit">{{ ___('Reserve') }}</button>
        </div>
      </form>
    @endif
  </div>
  @endif

  @if($waitlistEnabled)
  <div class="sheet" id="waitlistSheet" hidden>
    <div class="sheet-grab"></div>
    <h2>{{ ___('Join the Waitlist') }}</h2>
    @if(session('waitlist_joined'))
      <div class="sheet-done"><span class="sheet-done-emoji">🎉</span><b>{{ ___("You're on the list!") }}</b></div>
    @else
      <form class="sheet-form" action="{{ route('publicView.waitlist.join', $store->slug) }}" method="POST">
        @csrf
        <h4>{{ ___('Your Name') }}</h4>
        <input aria-label="{{ ___('Your name') }}" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}">
        <h4>{{ ___('Phone number') }}</h4>
        <input aria-label="{{ ___('Phone') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}">
        <h4>{{ ___('Party Size') }}</h4>
        <input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
        <div class="sheet-actions"><button class="cta wide" type="submit">{{ ___('Join Waitlist') }}</button></div>
      </form>
    @endif
  </div>
  @endif

  <div class="sheet" id="ratefilterSheet" hidden>
    <div class="sheet-grab"></div>
    <h2>{{ ___('Filter by rating') }}</h2>
    <div class="reward-list" id="rfList"></div>
  </div>

  {{-- nearest-branch Leaflet map picker (self-hosted tiles/markers) --}}
  @if($showBranches)
  <div class="sheet sheet-tall" id="branchMapSheet" hidden>
    <div class="sheet-grab"></div>
    <h2>{{ ___('Nearest branch') }}</h2>
    <div class="branchmap" id="crumbBranchMap"></div>
    <p class="geo-attrib" id="crumbMapAttrib"></p>
    <div class="branch-list" id="crumbBranchGeoList"></div>
  </div>
  @endif

  {{-- ============================================ ADDRESS MAP PICKER SHEET
       Crumb-native bottom sheet (.sheet.sheet-tall — same shell as the
       nearest-branch map) with its own scrim, driven by the shared
       window.mbMapPicker engine. On confirm the engine fills #locInput; the
       wiring re-fires the theme's own Enter-to-pick so the picked address
       commits to the order exactly like tapping a search result. --}}
  <div class="scrim crumb-map-scrim" id="crumbMapScrim" hidden></div>
  <div class="sheet sheet-tall crumb-map-sheet" id="crumbMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" hidden>
    <div class="sheet-grab"></div>
    <button class="sheet-x" type="button" id="crumbMapClose" aria-label="{{ ___('Close') }}">
      <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
    </button>
    <h2>{{ ___('Choose on map') }}</h2>
    <div class="crumb-map-canvas" id="crumbMapCanvas"></div>
    <p class="crumb-map-preview" id="crumbMapPreview">{{ ___('Move the map to place the pin') }}</p>
    <div class="sheet-actions">
      <button class="cta wide" id="crumbMapConfirm" type="button">
        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
        <span>{{ ___('Use this location') }}</span>
      </button>
    </div>
  </div>

  {{-- full-screen story viewer (fed by the same /stories payload as .reel-rail) --}}
  <div class="reel-viewer" id="reelViewer" hidden>
    <div class="reel-progress" id="reelProgress" aria-hidden="true"></div>
    <button class="reel-close" id="reelClose" type="button" aria-label="{{ ___('Close') }}">
      <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
    </button>
    <div class="reel-stage" id="reelStage">
      <img class="reel-media" id="reelMedia" alt="" data-csp-error="opacity">
      <button class="reel-nav prev" id="reelPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
      <button class="reel-nav next" id="reelNext" type="button" aria-label="{{ ___('Next') }}"></button>
      <div class="reel-foot">
        <p class="reel-caption" id="reelCaption"></p>
        <a class="minibtn cream reel-cta" id="reelCta" hidden><span></span></a>
      </div>
    </div>
  </div>

  <div class="toast" id="toast" role="status"></div>
  <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>
</div>

{{-- Catering & Events now lives in #cvCateringSheet (opened from the header
     button); the form is included there with cateringInline=true so it does
     not self-relocate back to the bottom of the menu. --}}
@endsection

@push('scripts_at_bottom')
@php
    $i18n = [
        'skip' => ___('Skip'),
        'your_order' => ___('Your order'), 'edit' => ___('Edit'),
        'see_all' => ___('See All'), 'see_less' => ___('See Less'),
        'daily_special' => ___('Daily Special'), 'in_stock' => ___('In stock'), 'out_stock' => ___('Sold out'),
        'read_more' => ___('Read more'), 'read_less' => ___('Read less'),
        'select_weight' => ___('Select Weight'), 'select_pack' => ___('Choose an option'),
        'free' => ___('Free'), 'min' => ___('min'), 'kcal' => ___('kcal'),
        'vegan' => ___('Vegan'), 'contains' => ___('Contains'),
        'reviews' => ___('Reviews'), 'review' => ___('Review'), 'verified' => ___('Verified'),
        'rate_dish' => ___('Rate this dish'),
        'no_reviews' => ___('No reviews yet — be the first!'), 'you' => ___('You'),
        'months_ago' => ___(':n months ago'), 'month_ago' => ___('1 month ago'), 'just_now' => ___('Just now'),
        'all_ratings' => ___('All ratings'),
        'rate_first' => ___('Tap a star first'), 'review_thanks' => ___('Thank you! Your review will appear once approved.'),
        'review_fail' => ___('Could not submit your review'), 'qty' => ___('Qty'),
        'added' => ___('Added to cart'), 'removed_toast' => ___('Removed from cart'), 'remove' => ___('Remove'),
        'subtotal' => ___('Subtotal'), 'discount' => ___('Discount'), 'delivery_fee' => ___('Delivery fee'),
        'tip_label' => ___('Tip'), 'total' => ___('Total'), 'vat' => ___('VAT'),
        'min_order_msg' => ___('Minimum order is'),
        'coupon_ok' => ___('Coupon applied — you save'), 'coupon_bad' => ___('That code doesn’t work'),
        'add_address' => ___('Add delivery address'), 'change' => ___('Change'),
        'loc_unavailable' => ___('Location unavailable'), 'loc_denied' => ___('Location denied'), 'no_address' => ___('No address found'),
        'delivery_addr' => ___('Delivery address'), 'pickup_branch' => ___('Pickup branch'), 'table' => ___('Table'),
        'pickup_hint' => ___('Ready for pickup soon'), 'delivery_hint' => ___('Delivered to your address'),
        'dinein_hint' => ___('Served to your table'),
        'fill_contact' => ___('Add your name & phone first'), 'fill_addr' => ___('Add a delivery address'),
        'accept_terms' => ___('Please accept the terms'), 'select_table' => ___('Enter your table number'),
        'cart_empty' => ___('Your cart is empty'),
        'order_error' => ___('Something went wrong. Please try again.'),
        'waiter_ok' => ___('A waiter is on the way'), 'bill_ok' => ___('Bill requested — one moment'),
        'notif_none' => ___('No new notifications'), 'share_stub' => ___('Link copied — share away!'),
        'reorder' => ___('Re-Order'),
        'arrives_in' => ___('Arrives in'), 'track_order' => ___('Track Order'),
        'branch_set' => ___('Branch selected'), 'addr_set' => ___('Address updated'),
        'saved_address' => ___('Saved address'),
        'loc_min_chars' => ___('Enter at least 3 characters'),
        'loc_searching' => ___('Searching maps…'),
        'loc_no_results' => ___('No map results found. Try a street, area, and city.'),
        'map_result' => ___('Map result'),
        'loc_error' => ___('Map search is temporarily unavailable.'),
        'selected_branch' => ___('Selected branch'), 'pick_branch' => ___('Pick this branch'),
        'ot_delivery' => ___('Delivery'), 'ot_takeaway' => ___('Pickup'), 'ot_table' => ___('Dine-in'),
        'ot_room_service' => ___('Room service'), 'room' => ___('Room'),
        'room_hint' => ___('Delivered to your room'), 'stage_room' => ___('Served to your room'),
        'popular' => ___('POPULAR'),
        'points' => ___('pts'), 'redeem' => ___('Redeem'), 'need_pts' => ___('Not enough points yet'),
        'redeemed' => ___('Reward applied'),
        'clear_cart_confirm' => ___('Tap again to clear'),
        'appearance' => ___('Appearance'), 'auto' => ___('Auto'), 'light' => ___('Light'), 'dark' => ___('Dark'),
        'coupon_copied' => ___('Code copied — paste it in your cart'),
        'pay_counter' => ___('Pay on counter'), 'pay_online' => ___('Pay online'),
        'no_match' => ___('No reviews match'), 'in_stock' => ___('In stock'),
        'choose' => ___('Choose'), 'required' => ___('Required'), 'optional' => ___('optional'),
        'max_sel' => ___('Max'), 'add_ons' => ___('Add-ons'),
        'km' => ___('km'), 'no_branches' => ___('No branches found'),
        'store_credit' => ___('Store credit'),
        'order_now' => ___('Order Now'), 'stories' => ___('Stories'),
    ];
@endphp
<script nonce="{{ csp_nonce() }}">
window.CRUMB = {
    storeId:   @json($store->id),
    restaurantSlug: @json($store->slug),
    customer:       @json((bool) $customer),
    csrf:           @json(csrf_token()),
    {{-- FEATURES-5 Track A — enabled social providers for the JS-built auth sheet --}}
    {{-- {!! !!} + json_encode instead of @json: Blade's compileJson comma-explodes multi-arg expressions (broke these 4 themes' pages) --}}
    socialLogin:    {!! json_encode(collect(app(\Juwri\Modules\Auth\Services\Social\SocialCredentialResolver::class)->enabledProviders($store->id))->map(fn($p)=>['provider'=>$p,'label'=>ucfirst($p),'url'=>route('customer.social.redirect',[$store->id,$p])])->values(), 15) !!},
    {{-- ZERO-TOUCH A3.2 — agent chat data seam (theme-built UI; no forced partial). enabled:false when owner/plan off --}}
    agentChat:      @json(\App\Support\AgentChatWidget::seam($store)),
    currencySign:   @json($curSign),
    currencyPos:    @json($curPos),
    currencyDecimals: @json(currency_decimals()),
    moneyDecimals: @json(money_decimals()), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
    decimalsByCode: @json(currency_decimals_map()),
    baseCurrency:   @json($baseCur),
    currencyRates:  @json((object) $mcRates),
    deliveryCharge: @json($deliveryCharge),
    taxCharge:      @json($taxCharge),
    {{-- CRUMB-F1: 'fixed' is a FLAT amount, not a rate — Order::computeTax() honours
         both modes, so the theme must too or a flat-tax store is billed a percentage. --}}
    taxType:        @json((string) (@$postOptions->restaurant_tax_type ?: 'percentage')),
    taxLabel:       @json($taxLabel),
    prepEta:        @json($prepEta ?: null),
    minOrderValue:  @json($minOrderValue),
    allowOrder:     @json((bool) $allow_order),
    allowPayment:   @json((bool) $allow_payment),
    allowDelivery:  @json((bool) $allow_delivery),
    allowTakeaway:  @json((bool) $allow_takeaway),
    allowOnTable:   @json((bool) $allow_on_table),
    allowRoomService: @json((bool) $allow_room_service),
    allowCoupons:   @json((bool) $allowCoupons),
    allowTips:      @json((bool) $allowTips),
    allowDietary:   @json((bool) $allowDietary),
    allowOrderSched:@json((bool) $allowOrderSched),
    hasStoreCredit: @json((bool) ($customer && $storeCredit > 0)),
    storeCredit:    @json((float) $storeCredit),
    leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
    forceTable:     @json((bool) $tableNo),
    defaultTable:   @json($tableNo),
    customerName:   @json($customer->name ?? null),
    defaultAddress: @json($customerDefaultAddress ? $addrLabel($customerDefaultAddress) : null),
    isLoggedIn:     @json((bool) $customer),
    {{-- F2 · brand / follow row --}}
    customerToken:  @json($customerApiToken),
    isFollowing:    @json((bool) $isFollowing),
    followerCount:  @json((int) $followerCount),
    hasWhatsapp:    @json((bool) $waNumber),
    loyaltyEnabled: @json((bool) ($loyaltyProgram && $customer)),
    loyaltyType:    @json($loyaltyProgram->type ?? null),
    reservations:   @json((bool) $reservationsEnabled),
    waitlist:       @json((bool) $waitlistEnabled),
    googleReviewUrl:@json($googleReviewUrl ?: null),
    defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
    sellerName:     @json($store->title),
    sellerAddr:     @json(trim((string) ($store->address ?? ''))),
    sellerImg:      @json($sellerImg),
    items:          @json((object) $jsItems),
    cats:           @json($jsCats),
    catNames:       @json((object) $catNames),
    branches:       @json($branchList),
    banners:        @json($jsBanners),
    endpoints: {
        customerLogin: @json(route('customer.login', $store->id)),
        customerRegister: @json(route('customer.register', $store->id)),
        otpRequest: @json(route('customer.otp.request', $store->id)),
        otpVerifyLogin: @json(route('customer.otp.verify', $store->id)),
        submitOrder:  @json(route('store.submitOrder', $store->id)),
        coupon:     @json(route('store.applyCoupon', $store->id)),
        waiter:     @json(route('store.callTheWaiter', $store->id)),
        review:     @json(route('store.submitReview', $store->slug)),
        orderLookup:@json(route('store.orderLookup', $store->slug)),
        loyaltyBalance: @json($loyaltyProgram && $customer ? route('publicView.loyalty.balance', $store->id) : null),
        loyaltyRedeem:  @json($loyaltyProgram && $customer ? route('publicView.loyalty.redeem', $store->id) : null),
        branchesGeo:    @json($showBranches ? route('geo.branches', $store->slug) : null),
        stories:        @json(route('stories.public', $store->slug)),
        geoAutocomplete:@json(route('geo.autocomplete')),
        geoReverse:     @json(route('geo.reverse')),
        follow:         @json(url('r/'.$store->slug.'/follow')),
    },
    i18n: @json($i18n),
};
</script>
{{-- script.js is auto-loaded by store_templates.layout --}}
@if($showBranches)
{{-- self-hosted Leaflet — must load before script.js (layout appends script.js after this stack) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
@endif
@endpush

@push('scripts_at_bottom')
{{-- Address map picker + clear-saved-data wiring. Crumb is a shared-layout theme
     (@extends store_templates.layout), so window.mbMapPicker / window.mbClearDeviceData
     are already global (the layout @includes the engines above the content). We only
     drive them with crumb's own bottom sheet + profile controls. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var trigger = document.getElementById('crumbMapBtn');
    if (!window.mbMapPicker) { if (trigger) { trigger.style.display = 'none'; } return; }
    var sheet = document.getElementById('crumbMapSheet');
    var scrim = document.getElementById('crumbMapScrim');
    var input = document.getElementById('locInput');
    var hideTimer = null;
    /* Drive crumb's native bottom sheet — mirrors the theme's own sheet(): toggle
       .is-open on the sheet + a dedicated scrim, with the same 420ms exit before
       re-hiding. The picker owns its own scrim so it never fights the theme's
       private openSheet bookkeeping. */
    function toggle(open) {
        if (!sheet || !scrim) { return; }
        if (open) {
            clearTimeout(hideTimer);
            sheet.hidden = false; scrim.hidden = false;
            void sheet.offsetWidth;
            sheet.classList.add('is-open'); scrim.classList.add('is-open');
        } else {
            sheet.classList.remove('is-open'); scrim.classList.remove('is-open');
            clearTimeout(hideTimer);
            hideTimer = setTimeout(function () { sheet.hidden = true; scrim.hidden = true; }, 420);
        }
    }
    window.mbMapPicker({
        trigger: trigger,
        modal:   sheet,
        map:     document.getElementById('crumbMapCanvas'),
        input:   input,
        preview: document.getElementById('crumbMapPreview'),
        confirm: document.getElementById('crumbMapConfirm'),
        close:   [document.getElementById('crumbMapClose'), scrim],
        toggle:  toggle,
        i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
    });
    /* The engine fills #locInput and fires an input event, but crumb submits its
       OWN private S.address, set only via the theme's pickAddress(). Re-use crumb's
       native Enter-to-pick (script.js: #locInput keydown Enter to pickAddress(value))
       so a map-picked address commits + persists exactly like tapping a search
       result. Registered after mbMapPicker, so it runs after the engine set value. */
    var confirmBtn = document.getElementById('crumbMapConfirm');
    if (confirmBtn && input) {
        confirmBtn.addEventListener('click', function () {
            if (!input.value.trim()) { return; }
            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        });
    }
})();
(function () {
    var btn = document.getElementById('crumbClearData');
    if (!btn) { return; }
    var hint = document.getElementById('crumbClearDataHint');
    if (!window.mbClearDeviceData) { btn.style.display = 'none'; if (hint) { hint.style.display = 'none'; } return; }
    /* crumb persists every store-scoped value under crumb_<storeId>_<key>. */
    var pfx = 'crumb_' + @json((string) ($store->id ?? '')) + '_';
    btn.addEventListener('click', function () {
        window.mbClearDeviceData({
            keys: [pfx + 'cart', pfx + 'favs', pfx + 'address', pfx + 'savedAddrs', pfx + 'branch', pfx + 'orders'],
            slug: @json($store->slug ?? ''),
            storeId: @json($store->id ?? ''),
            confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
        });
    });
})();
</script>
@endpush

@push('scripts_at_bottom')
{{-- P6-301 — consume the previous-order payload the account page wrote to
     localStorage (cv_reorder_<slug>) and add each line to crumb's cart.
     The shared helper (assets/store_templates/_shared/js/reorder-pickup.js) is
     loaded here, but the actual call must run AFTER the theme's script.js
     (loaded by store_templates.layout) so window.__crumbPickup + window.mbToast
     are defined. `load` fires after every sync script on the document. --}}
<script nonce="{{ csp_nonce() }}">
window.addEventListener('load', function () {
    if (!window.JuwriReorder || !window.JuwriReorder.pickup) return;
    window.JuwriReorder.pickup(@json($store->slug ?? ''), window.__crumbPickup || function () { return false; }, {
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
