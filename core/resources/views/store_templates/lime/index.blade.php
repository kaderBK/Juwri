{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       lime — black + neon-lime snack-delivery theme · data preparation
       (faithful rebuild of rev/lime/demo.* wired to real menu data)
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus   = collect($total_menus)->values();
    $items      = $allMenus->values();

    /* Two-tier category nav (primary parent chips + a secondary sub-category chip
       row that swaps per active parent — the Talabat/UberEats pattern, ported from
       crave). menu_categories() is whereNull('parent') (top-level only), but a menu
       item keys off its OWN category_id, which may be a subcategory id. So each item
       carries BOTH ids to JS: 'cat' = its real category_id (drives sub-chip filtering)
       and 'root' = its top-level ancestor (drives parent-chip filtering). $rootOf maps
       any category id → its top-level ancestor. */
    $rootOf = [];
    foreach ($store->menu_categories as $parentCat) {
        $rootOf[$parentCat->id] = $parentCat->id;
        foreach ($parentCat->subcategories as $subCat) {
            $rootOf[$subCat->id] = $parentCat->id;
        }
    }
    $rootItemCount = [];   // top-level id  => descendant item count (parent chips)
    $catItemCount  = [];   // real cat id   => own item count (sub-chips)

    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

    $curSign  = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos   = (string) currency_pos_effective($postOptions->id ?? null);
    $curGap   = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only
    $deliveryCharge  = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge       = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    /* TOTALS CONTRACT: the tax TYPE decides whether the stored number is a rate or a
       flat amount — Order::computeTax() honours it, so the theme must too. */
    $taxType         = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');
    $taxLabel        = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
    if ($taxType === 'percentage' && $taxCharge > 0) {
        $taxLabel .= ' ('.rtrim(rtrim(number_format($taxCharge, 2, '.', ''), '0'), '.').'%)';
    }
    /* Rounding follows the RESTAURANT's currency, never the app default — a 0- or
       3-decimal store otherwise quotes a number the server never charges. */
    $storeCurrency   = (string) (@$postOptions->currency_code ?: config('settings.currency_code') ?: 'USD');
    $storeDecimals   = currency_decimals(@$postOptions->currency_code ?: null);
    $limePrice = function ($amount) use ($curSign, $curPos, $curGap, $storeDecimals) {
        $str = number_format((float) $amount, $storeDecimals, '.', '');
        return $curPos === '1' ? $curSign.$curGap.$str : $str.$curGap.$curSign;
    };
    $minOrderValue   = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched = !empty($postOptions->allow_order_scheduling);
    $allowCoupons    = @$postOptions->allow_coupons == 1;
    $allowTips       = @$postOptions->allow_tips == 1;
    $allowWaiter     = !empty($postOptions->allow_call_waiter);
    $allowDietary    = !isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;
    $isOpen          = store_open_now($store);

    $featured   = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    $splashImgs = \Juwri\Core\Support\ViewHelpers::imageItems($items, 3);

    /* helper: human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* JS data map — variants absolute price, groups/extras additive */
    $jsItems = [];
    /* approved product reviews, grouped per menu item for the product view */
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

        // 'root' = the item's top-level ancestor (parent-chip filter); 'cat' stays
        // the item's real category id (sub-chip filter). Orphan → falls back to self.
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
            // Written-review total for the Reviews SECTION badge — 'reviews' above is
            // rating_count (every quick star tap), which over a list of one written
            // review reads as fabricated social proof.
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
            'gallery'          => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryUrls($m->gallery_images, $store->id ?? null)
                : [],
            'optionGroups'     => \Juwri\Core\Support\ViewHelpers::optionGroups($m->globalGroups ?? []),
        ];
    }

    /* Primary chips: top-level parents that hold at least one descendant item (so
       pure containers / empty parents drop). Secondary chips: per parent, only the
       subcategories that own items. Each parent chip carries the full set of category
       ids it covers (itself + every descendant) so the existing catIds() filter still
       matches items filed under subcategories; the JS then narrows on the sub-chip. */
    $categories = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);

    // parentId => collection of item-bearing subcategories (empty => no second row)
    $subCatsOf = [];
    foreach ($categories as $c) {
        $subs = \Juwri\Core\Support\ViewHelpers::withItems($c->subcategories, $catItemCount);
        if ($subs->isNotEmpty()) {
            $subCatsOf[$c->id] = $subs;
        }
    }

    // every category id whose top-level ancestor is this parent (self + all descendants)
    $descendantsOf = [];
    foreach ($rootOf as $catId => $rootId) {
        $descendantsOf[$rootId][] = (int) $catId;
    }

    $jsCats = $categories->map(fn($c) => [
        'id'    => (int) $c->id,
        'ids'   => array_values(array_unique($descendantsOf[$c->id] ?? [(int) $c->id])),
        'name'  => $c->name,
        'image' => $c->image ? media_url('storage/menu/', $c->image, $store->id ?? null) : '',
        'subs'  => collect($subCatsOf[$c->id] ?? [])->map(fn($s) => [
            'id'    => (int) $s->id,
            'name'  => $s->name,
            'image' => $s->image ? media_url('storage/menu/', $s->image, $store->id ?? null) : '',
        ])->values(),
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

    $showBranches = !empty($branches) && count($branches) > 1 && (@$postOptions->allow_multi_branch_switch ?? 1);

    /* engagement extras (same backend contract as crave/aurora) */
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));

    /* F2 · follow / verified brand row (ported from crave) */
    $customerApiToken = storefront()->customerApiToken($customer, 'lime');
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

    $splashTitle = theme_setting($store->id, 'splash.title', ___('Our Menu'));
    $brandColor  = theme_setting($store->id, 'colors.primary', '#CDF34B');

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
    {{-- LIME-F7 · appearance: resolve the palette BEFORE first paint so there is no
         flash of the wrong theme. 'auto' follows the OS. --}}
    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var m = localStorage.getItem('lime-theme') || 'auto';
                var dark = m === 'dark' || (m === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-lime-theme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="lime" id="lime" style="--lime-brand: {{ \Juwri\Core\Support\HexColor::safe($brandColor, '#CDF34B') }}">
  <div class="app" id="app">

    {{-- ══════════ SPLASH ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if(theme_setting($store->id, 'splash.enabled', true) && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="splash" id="splash">
      <div class="splash-doodles" aria-hidden="true">
        <svg class="dd dd-1" viewBox="0 0 40 40"><path d="M20 4v10M20 26v10M4 20h10M26 20h10" stroke-linecap="round"/></svg>
        <svg class="dd dd-2" viewBox="0 0 40 40"><path d="M4 26c6-14 20-18 32-12" stroke-linecap="round"/></svg>
        <svg class="dd dd-3" viewBox="0 0 40 40"><circle cx="20" cy="20" r="13"/></svg>
        <svg class="dd dd-4" viewBox="0 0 40 40"><path d="M6 30 14 14l6 10 6-14 8 20" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </div>
      <h1 class="splash-word">{{ $store->title }}<span>.</span></h1>
      <p class="splash-tag" data-tc-text="splash.title">{{ $splashTitle }}</p>
      <div class="splash-hero" aria-hidden="true">
        <i class="splash-ring"></i>
        @if(isset($splashImgs[0]))
          @if((string) (media_url('storage/menu/', $splashImgs[0]->image, $store->id ?? null)) !== '')<img class="splash-pop" src="{{ media_url('storage/menu/', $splashImgs[0]->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
        @endif
        @if(isset($splashImgs[1]))
          @if((string) (media_url('storage/menu/', $splashImgs[1]->image, $store->id ?? null)) !== '')<img class="splash-mini mini-a" src="{{ media_url('storage/menu/', $splashImgs[1]->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
        @endif
        @if(isset($splashImgs[2]))
          @if((string) (media_url('storage/menu/', $splashImgs[2]->image, $store->id ?? null)) !== '')<img class="splash-mini mini-b" src="{{ media_url('storage/menu/', $splashImgs[2]->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
        @endif
      </div>
      <button class="splash-skip" id="splashSkip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="views" id="views">

      {{-- HOME --}}
      <section class="view is-active" data-view="home">
        @php
            $__limeHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
            $limeCoverUrl = $__limeHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
            $limeShowCover = $__limeHasCover && theme_setting($store->id, 'brand.show_cover', true);
        @endphp
        {{-- Collapsing header: slides in once the cover card leaves the scroll
             container, hides again as the sticky category bar reaches the top. --}}
        <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
          <span class="cv-condensed-logo">@if(!empty($store->main_image) && $store->main_image !== 'default.png')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif</span>
          <b class="cv-condensed-name">{{ $store->title }}</b>
          <span class="cv-condensed-actions">
            @if(count($menu_languages ?? []) > 1)
            <button class="iconbtn blk" type="button" data-cv-proxy="lang" aria-label="{{ ___('Language') }}">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
            </button>
            @endif
            @if(storefront_catering_enabled($store->id))
            <button class="iconbtn blk" type="button" data-cv-proxy="catering" aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
            </button>
            @endif
            <button class="iconbtn blk" type="button" data-cv-proxy="notif" aria-label="{{ ___('Notifications') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
            </button>
          </span>
        </div>
        <div class="view-scroll">
          @if($limeShowCover)
          {{-- Cover as a rounded card inset from the edges (lime cards everything);
               chrome sits over it in white, the brand row overlaps its bottom edge. --}}
          <div class="lime-cover">
            <div class="lime-cover-media" aria-hidden="true">
              @if((string) ($limeCoverUrl) !== '')<img class="lime-cover-img" src="{{ $limeCoverUrl }}" alt="" data-csp-error="hide">@endif
              <span class="lime-cover-scrim"></span>
            </div>
          @endif
          <header class="home-top">
            <button class="avatar" id="avatarBtn" type="button" aria-label="{{ ___('Profile') }}">
              @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
            </button>
            <button class="loc" id="locBtn" type="button">
              <span class="loc-label">{{ ___('Menu') }}</span>
              <span class="loc-value"><i class="godot"></i><b>{{ Str::limit($store->title, 20) }}</b>
                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></span>
            </button>
            @if(count($menu_languages ?? []) > 1)
            <button class="iconbtn blk" id="langBtn" type="button" aria-label="{{ ___('Language') }}">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
            </button>
            @endif
            {{-- Shared notification-center bell (rev/notif-center-spec.md); panel + JS from _partials/notif-bell. --}}
            <button class="iconbtn blk" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
              <i class="dot" id="mbNotifDot" hidden></i>
            </button>
            @if(storefront_catering_enabled($store->id))
            {{-- Catering & Events — opens #cvCateringSheet (JS-wired, see script.js). --}}
            <button class="iconbtn blk" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
            </button>
            @endif
            {{-- Light/dark quick toggle (Lane B) — drives lime's NATIVE appearance
                 system (data-lime-theme + `lime-theme` key), same store as the
                 Profile Auto/Light/Dark segment. --}}
            <button class="iconbtn blk" type="button" id="limeThemeToggle"
                    aria-pressed="false" aria-label="{{ ___('Toggle dark mode') }}">
              <svg class="ic mb-th-moon" viewBox="0 0 24 24"><path d="M20.5 14.4A8.5 8.5 0 1 1 9.6 3.5a6.8 6.8 0 0 0 10.9 10.9Z"/></svg>
              <svg class="ic mb-th-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.6 12h2.2M19.2 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
            </button>
          </header>
          @if($limeShowCover)
          </div>{{-- /.lime-cover — the top chrome floats over the photo card; the
               brand row, search and status sit below on the paper --}}
          @endif
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>

          {{-- search + status keep their original spot only when there is NO cover
               card; with a cover they move under the brand row (see below) so the
               photo card stays clean. --}}
          @unless($limeShowCover)
          @if(theme_setting($store->id, 'search.enabled', true))
          <button class="searchpill" id="searchGo" type="button">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            <span>{{ ___('Search here') }}…</span>
          </button>
          @endif

          <div class="status-strip">
            <span class="open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
            @if(!empty($scans_today) && $scans_today > 0)
              <span class="viewers">
                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <b>{{ $scans_today }}</b> {{ ___('viewing today') }}
              </span>
            @endif
          </div>
          @endunless

          {{-- F2 · brand / follow row (ported from crave) --}}
          @if($sectionVisible['brand_row'] ?? true)
          <div class="brand-row{{ $limeShowCover ? ' brand-row--hero' : '' }}" id="brandRow" data-tc-section="brand_row" data-mb-hotel-chip-slot>
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
            @if($authMode !== 'guest_only' || $customer)
            <button class="followbtn {{ $isFollowing ? 'is-on' : '' }}" id="followBtn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
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
          <div class="lime-reviews" id="limeReviews" data-mb-reviews-end>
            <div class="lime-rating"><span class="lime-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
            @if($aboutReviews->isNotEmpty())
            <div class="lime-review-list">
              @foreach($aboutReviews as $rev)
              <div class="lime-review">
                <div class="lime-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="lime-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
              </div>
              @endforeach
            </div>
            @endif
          </div>
          @endif

          {{-- cover on: search + status move under the brand row, off the photo card --}}
          @if($limeShowCover)
          @if(theme_setting($store->id, 'search.enabled', true))
          <button class="searchpill" id="searchGo" type="button">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            <span>{{ ___('Search here') }}…</span>
          </button>
          @endif

          <div class="status-strip">
            <span class="open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
            @if(!empty($scans_today) && $scans_today > 0)
              <span class="viewers">
                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <b>{{ $scans_today }}</b> {{ ___('viewing today') }}
              </span>
            @endif
          </div>
          @endif

          @if(!empty($menuBanners) && count($menuBanners))
          <div class="banners">
            <div class="banner-track" id="bannerTrack">
              @foreach($menuBanners as $bi => $banner)
                <a class="banner {{ $bi % 2 === 1 ? 'alt' : '' }}" href="{{ storefront_localized_link($banner->link_url) ?: '#' }}" @if($banner->link_url) target="_blank" rel="noopener" @endif>
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

          {{-- story rail — stays hidden unless the stories endpoint returns items --}}
          <div class="stories" id="storiesRail" hidden></div>

          {{-- The hero advertises a REAL dish (daily special, else most popular) and its
               real price, and opens that dish — it used to be a generic untranslated
               headline over a button that only scrolled the page.
               Customizer v2 §sections: daily_specials gates the featured blob (this
               hero) and the "Most popular" rail below. --}}
          @if($sectionVisible['daily_specials'] ?? true)
          @php
              $heroItem = $items->first(fn($m) => !empty($m->is_daily_special) && empty($m->is_sold_out))
                       ?? $items->first(fn($m) => !empty($m->is_popular) && empty($m->is_sold_out))
                       ?? $items->first();
          @endphp
          <div class="hero">
            <button class="daily" id="dailyBadge" type="button" hidden><i>⚡</i><span>{{ ___('Daily Special') }}</span></button>
            <div class="hero-copy">
              @if($heroItem)
                <p class="hero-kicker">{{ !empty($heroItem->is_daily_special) ? ___('Daily Special') : ___('Most popular') }}</p>
              @endif
              <h1>{{ $heroItem->name ?? $store->title }}</h1>
              <button class="hero-cta" id="heroCta" type="button" @if($heroItem) data-open="{{ $heroItem->id }}" @endif>
                <span>{{ $heroItem ? ___('Order now') : ___('Browse menu') }}</span>
                @if($heroItem)<b class="hero-price">{{ $limePrice($heroItem->price) }}</b>@endif
                <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
            </div>
            <div class="hero-art" aria-hidden="true">
              <i class="hero-ring"></i>
              @if($heroItem && $heroItem->image && $heroItem->image !== 'default.png')
                @if((string) (media_url('storage/menu/', $heroItem->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $heroItem->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
              @endif
            </div>
          </div>
          @endif

          {{-- Customizer v2 §sections: catbar — category chips + sub-chip row.
               renderChips() writes #chips unconditionally, so a hidden stub stays
               behind when the section is off (renderSubChips() null-guards). --}}

          {{-- Customizer v2 §sections: daily_specials (second half) — the "Most
               popular" rail. renderBS() writes #bsRail unconditionally, hence the
               hidden stub. --}}
          @if($sectionVisible['daily_specials'] ?? true)
          <div class="section-head">
            <h2 data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___('Most popular')) }}</h2>
            <span class="bs-dot"><i class="godot"></i><span>{{ ___('Popular') }}</span></span>
          </div>
          <div class="rail" id="bsRail"></div>
          @else
          <div class="rail" id="bsRail" hidden></div>
          @endif

          @if($sectionVisible['catbar'] ?? true)
          <span class="cv-menu-sentinel" aria-hidden="true"></span>
          <nav class="chips" id="chips" aria-label="{{ ___('Categories') }}" data-mb-grid-lead data-mbt-anchor></nav>
          {{-- Secondary sub-category chip row — JS reveals it only for a parent that
               has item-bearing subcategories; "All" (data-sub="root") shows every
               descendant, a sub-chip narrows to one real category. --}}
          <div class="subchips" id="subchips" aria-label="{{ ___('Sub-categories') }}" hidden data-mb-grid-lead></div>
          @else
          <nav id="chips" hidden></nav>
          @endif
          {{-- dietary filters: the store enables them; the theme had no filter UI at all --}}
          @if($allowDietary)
          <div class="dietrow" id="dietRow" aria-label="{{ ___('Dietary') }}" data-mb-grid-lead></div>
          @endif

          {{-- Customizer v2 §sections: menu — the All Menu grid. renderGrid() and
               skeletons() write #grid/#gridEmpty unconditionally, hence the stubs. --}}
          @if($sectionVisible['menu'] ?? true)
          <div class="section-head grid-head" data-mb-grid-lead>
            <h2 class="big-head" id="gridTitle">{{ ___('Our Menu') }}</h2>
          </div>
          <div class="grid" id="grid"></div>
          <div class="grid-empty" id="gridEmpty" hidden>
            <svg viewBox="0 0 64 64" class="empty-art"><circle cx="32" cy="32" r="22"/><path d="M24 28c2-3 6-3 8 0M36 28c2-3 6-3 8 0" stroke-linecap="round"/><path d="M24 42c4-4 12-4 16 0" stroke-linecap="round"/></svg>
            <p>{{ ___('Nothing matches — try clearing filters') }}</p>
            <button class="ghostbtn" id="homeReset" type="button">{{ ___('Reset') }}</button>
          </div>
          @else
          <div id="grid" hidden></div>
          <div id="gridEmpty" hidden></div>
          @endif
          <div class="tail-space"></div>
        </div>
      </section>

      {{-- SEARCH --}}
      {{-- Customizer v2: with `search.enabled` off there is no way into this view —
           the pill and the tab-bar entry are both gated — so drop the whole view.
           renderSearch() bails out when its nodes are missing. --}}
      @if(theme_setting($store->id, 'search.enabled', true))
      <section class="view" data-view="search">
        <div class="view-scroll">
          <header class="page-head"><h1 class="title-ul">{{ ___('Search') }}</h1></header>
          @if(theme_setting($store->id, 'search.enabled', true))
          <label class="searchpill live">
            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
            <input aria-label="{{ ___('Search here') }}" id="searchInput" type="search" placeholder="{{ ___('Search here') }}…" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
          </label>
          @endif
          <h3 class="block-label">{{ ___('Popular') }}</h3>
          <div class="chiprow" id="popChips"></div>
          <div class="grid" id="searchGrid"></div>
          <div class="grid-empty" id="searchEmpty" hidden>
            <svg viewBox="0 0 64 64" class="empty-art"><circle cx="28" cy="28" r="15"/><path d="m39 39 12 12" stroke-linecap="round"/></svg>
            <p>{{ ___('No dishes match your search') }}</p>
          </div>
          <div class="tail-space"></div>
        </div>
      </section>
      @endif

      {{-- ACTIVITY / FAVORITES --}}
      <section class="view" data-view="orders">
        <div class="view-scroll">
          <header class="page-head"><h1 class="title-ul">{{ ___('Activity') }}</h1></header>

          @if($loyaltyProgram)
          <div class="loyalty-card">
            <div class="lc-copy">
              <p class="lc-label">{{ $loyaltyProgram->type === 'stamps' ? ___('Stamps') : ___('Points') }}</p>
              <p class="lc-num"><b id="ptsNum">0</b><small>{{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('pts') }}</small></p>
            </div>
            <button class="lime-pill" id="redeemBtn" type="button">{{ ___('Redeem Reward') }}</button>
          </div>
          @endif

          @if($reservationsEnabled || $waitlistEnabled)
          <div class="duo">
            @if($reservationsEnabled)
            <button class="tilebtn" id="reserveBtn" type="button">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg>
              <span>{{ ___('Reserve a Table') }}</span>
            </button>
            @endif
            @if($waitlistEnabled)
            <button class="tilebtn" id="waitlistBtn" type="button">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>
              <span>{{ ___('Join waitlist') }}</span>
            </button>
            @endif
          </div>
          @endif

          <h3 class="block-label" id="favsLabel">{{ ___('Favorites') }}</h3>
          <div class="grid" id="favGrid"></div>
          <p class="orders-empty" id="favEmpty">{{ ___('Tap the heart on any dish to save it here') }}</p>

          <h3 class="block-label" id="ordersLabel">{{ ___('My Orders') }}</h3>
          <div id="ordersList" class="orders-list"></div>
          <p class="orders-empty" id="ordersEmpty">{{ ___('Orders you place appear here') }}</p>

          @if($customer)
          <a class="row-line" href="{{ route('customer.profile', $store->id) }}" style="margin-top:12px">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
            <span>{{ ___('Profile & settings') }}</span>
            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
          </a>
          <a class="row-line" href="{{ route('customer.account', $store->id) }}" style="margin-top:12px">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12v11.6a2.4 2.4 0 0 1-2.4 2.4H8.4A2.4 2.4 0 0 1 6 19.6Z"/><path d="M9.4 8V6.6a2.6 2.6 0 0 1 5.2 0V8"/></svg>
            <span>{{ ___('Order History') }}</span>
            <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
          </a>
          @endif
          <div class="tail-space"></div>
        </div>
      </section>

      {{-- CART --}}
      <section class="view" data-view="cart">
        <div class="view-scroll">
          <header class="page-head">
            <h1 class="title-ul">{{ ___('Cart') }}</h1>
            <button class="dots blk" id="cartMore" type="button" aria-label="{{ ___('Options') }}">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.8" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.8" fill="currentColor" stroke="none"/></svg>
            </button>
          </header>

          <div id="cartList" class="cart-list"></div>
          <div class="grid-empty" id="cartEmpty" hidden>
            <svg viewBox="0 0 64 64" class="empty-art"><path d="M18 22h28l-2.6 26a4 4 0 0 1-4 3.6H24.6a4 4 0 0 1-4-3.6L18 22Z"/><path d="M25 22a7 7 0 0 1 14 0"/></svg>
            <p>{{ ___('Your cart is empty') }}</p>
            <button class="ghostbtn" data-go="home" type="button">{{ ___('Browse menu') }}</button>
          </div>

          @if($allow_order)
          <div id="cartTools" class="cart-tools">
            <section>
              <h3>{{ ___('Order type') }}</h3>
              <div class="seg" id="otSeg">
                @if($allow_delivery)<button data-ot="delivery" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg><span>{{ ___('Delivery') }}</span></button>@endif
                @if($allow_takeaway)<button data-ot="takeaway" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12v11.6a2.4 2.4 0 0 1-2.4 2.4H8.4A2.4 2.4 0 0 1 6 19.6Z"/><path d="M9.4 8V6.6a2.6 2.6 0 0 1 5.2 0V8"/></svg><span>{{ ___('Pickup') }}</span></button>@endif
                @if($allow_on_table)<button data-ot="on-table" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg><span>{{ ___('Dine-in') }}</span></button>@endif
                @if($allow_room_service)<button data-ot="room_service" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 6v2M10.5 6h3"/></svg><span>{{ ___('Room service') }}</span></button>@endif
              </div>
              <div class="ot-fields" id="otFields"></div>
              @if($allowOrderSched)
              <div class="schedule-row">
                <span class="sched-label"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ ___('Schedule for later') }}</span>
                <input aria-label="{{ ___('Schedule for later') }}" type="datetime-local" id="scheduledAt" class="when-time" min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}" max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
              </div>
              @endif
            </section>

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

            <section class="ordernote">
              <label><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5V4Z"/><path d="M15 4v4h4M8.5 12h7M8.5 16h5"/></svg>{{ ___('Note') }} ({{ ___('optional') }})</label>
              <textarea aria-label="{{ ___('Any special requests?') }}" id="orderNote" rows="2" placeholder="{{ ___('Any special requests?') }}"></textarea>
            </section>

            <section class="totals" id="totalsBox"></section>
            <p class="minorder" id="minOrderMsg" hidden></p>

            @if($allowWaiter && $allow_on_table)
            <div class="waiter-row" id="waiterRow" hidden>
              <button class="ghostbtn" id="callWaiter" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span>{{ ___('Call waiter') }}</span></button>
              <button class="ghostbtn" id="requestBill" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg><span>{{ ___('Request bill') }}</span></button>
            </div>
            @endif
          </div>
          @endif
          <div class="tail-space tall"></div>
        </div>
        @if($allow_order)
        <footer class="p-cta" id="cartCta" hidden>
          <button class="blkbar" id="goCheckout" type="button">
            <span class="bb-copy"><small>{{ ___('Total') }}</small><b id="cartCtaTotal"></b></span>
            <span class="bb-label">{{ ___('Checkout') }}</span>
            <span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span>
          </button>
        </footer>
        @endif
      </section>

      {{-- PRODUCT / SNACK INFORMATION --}}
      <section class="view view-sub" data-view="product">
        <div class="view-scroll">
          <header class="page-head sub">
            <button class="dots ghost" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
            <h1 class="ph-title">{{ ___('Item details') }}</h1>
            <button class="dots blk" id="pMore" type="button" aria-label="{{ ___('Options') }}">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.8" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.8" fill="currentColor" stroke="none"/></svg>
            </button>
          </header>

          <div class="p-photo-wrap">
            <i class="p-blob"></i>
            <img id="pPhoto" alt="">
            <button class="fav-fab" id="pFav" type="button" aria-label="{{ ___('Favorite') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
            <span class="p-off" id="pOff" hidden></span>
          </div>

          @if($allow_order)
          <div class="giant" aria-label="{{ ___('Quantity') }}">
            <button class="g-btn" data-g="-1" type="button" aria-label="{{ ___('Decrease quantity') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
            <div class="g-mid">
              <output id="giantNum">1</output>
              <span class="g-price" id="pPricePill"></span>
            </div>
            <button class="g-btn" data-g="1" type="button" aria-label="{{ ___('Increase quantity') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
          </div>
          @endif

          <h2 class="p-name" id="pName"></h2>
          <p class="p-desc" id="pDesc"></p>
          <div class="p-tags" id="pTags"></div>
          <div class="p-groups" id="pGroups"></div>
          <div class="tail-space tall"></div>
        </div>
        @if($allow_order)
        <footer class="p-cta">
          <div class="total-banner" id="totalBanner">
            <span class="tb-copy"><small>{{ ___('Total') }}</small><b id="tbLine"></b></span>
            <b class="tb-price" id="tbPrice"></b>
            <svg class="tb-chevs" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg>
          </div>
          <button class="cart-pill" id="addBtn" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12v11.6a2.4 2.4 0 0 1-2.4 2.4H8.4A2.4 2.4 0 0 1 6 19.6Z"/><path d="M9.4 8V6.6a2.6 2.6 0 0 1 5.2 0V8"/></svg>
            <span>{{ ___('Cart') }}</span>
          </button>
          {{-- shown by script.js once the cart has items: a direct jump to the cart (mirrors orbit #pCartJump) --}}
          <button class="p-cart-jump" id="pCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12v11.6a2.4 2.4 0 0 1-2.4 2.4H8.4A2.4 2.4 0 0 1 6 19.6Z"/><path d="M9.4 8V6.6a2.6 2.6 0 0 1 5.2 0V8"/></svg>
            <i class="p-cart-jump-count" id="pCartJumpCount" aria-hidden="true">0</i>
          </button>
        </footer>
        @endif
      </section>

      {{-- CHECKOUT --}}
      <section class="view view-sub" data-view="checkout">
        <div class="view-scroll">
          <header class="page-head sub">
            <button class="dots ghost" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
            <h1 class="ph-title">{{ ___('Checkout') }}</h1>
            <span class="ph-spacer"></span>
          </header>

          <section class="ck-block">
            <h3>{{ ___('Contact') }}</h3>
            <div class="field-duo">
              <input aria-label="{{ ___('Your name') }}" id="custName" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}" autocomplete="name">
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

          <section class="ck-block" id="addrBlock" style="display:none">
            <h3>{{ ___('Delivery address') }}</h3>
            <div class="minimap" aria-hidden="true">
              <svg viewBox="0 0 320 110" preserveAspectRatio="xMidYMid slice">
                <rect class="mm-base" width="320" height="110"/>
                <rect class="mm-block" x="14" y="12" width="64" height="30" rx="7"/>
                <rect class="mm-block" x="14" y="66" width="64" height="30" rx="7"/>
                <rect class="mm-block" x="132" y="12" width="70" height="30" rx="7"/>
                <rect class="mm-block" x="132" y="66" width="70" height="30" rx="7"/>
                <rect class="mm-block" x="254" y="12" width="52" height="84" rx="7"/>
                <path class="mm-road" d="M-10 55H330"/>
                <path class="mm-road" d="M104 -10V120M228 -10V120"/>
                <circle class="mm-dot" cx="60" cy="55" r="3"/>
                <circle class="mm-dot" cx="260" cy="55" r="3"/>
              </svg>
              <span class="pin"><svg viewBox="0 0 24 24"><path d="M12 22s-7.5-6-7.5-11.5a7.5 7.5 0 1 1 15 0C19.5 16 12 22 12 22Z"/><circle cx="12" cy="10.4" r="2.8"/></svg><i class="pin-pulse"></i></span>
            </div>
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
            {{-- Guest recent delivery addresses (localStorage) — script.js fills this
                 same slot with .saved-addr chips, gated on !isLoggedIn. --}}
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
          </section>

          <section class="ck-block">
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
            </div>
          </section>

          @if($customer && $storeCredit > 0)
          <section class="ck-block">
            <label class="terms">
              <input type="checkbox" id="useWallet">
              <span>{{ ___('Pay with store credit') }} ({{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }})</span>
            </label>
          </section>
          @endif

          @if($allowTips)
          <section class="ck-block">
            <h3>{{ ___('Tip') }}</h3>
            <div class="tip-row" id="tipRow">
              <button data-tip="0" class="is-on" type="button">{{ ___('None') }}</button>
              <button data-tip="10" type="button">10%</button>
              <button data-tip="15" type="button">15%</button>
              <button data-tip="20" type="button">20%</button>
            </div>
          </section>
          @endif

          @if(!$customer)
          <section class="ck-block">
            <label class="terms">
              <input type="checkbox" id="consentCb">
              <span>{{ ___('Send me offers and updates from this restaurant') }}</span>
            </label>
            <div id="captureEmailWrap" hidden>
              <input aria-label="{{ ___('your@email.com') }}" type="email" id="captureEmail" placeholder="{{ ___('your@email.com') }}" autocomplete="email">
            </div>
          </section>
          @endif

          {{-- Order summary — the customer should see WHAT they are paying for at
               the point of commitment, not just a number. Rendered by renderCheckout. --}}
          <section class="ck-items" id="ckItems" hidden></section>
          <section class="totals" id="checkoutTotals" style="margin-top:16px"></section>
          <label class="terms"><input type="checkbox" id="termsChk" checked><span>{{ ___('Terms and Conditions') }}</span></label>
          <p class="err-box" id="formError" hidden style="margin-top:12px"></p>
          <div class="tail-space tall"></div>
        </div>
        <footer class="p-cta">
          <button class="blkbar" id="placeOrder" type="button">
            <span class="bb-copy"><small>{{ ___('Total') }}</small><b id="placeTotal"></b></span>
            <span class="bb-label po-label">{{ ___('Place Order') }}</span>
            <span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg><span class="spinner" hidden></span></span>
          </button>
        </footer>
      </section>

      {{-- CONFIRMED --}}
      <section class="view view-sub view-center" data-view="confirmed">
        <div class="confirm-wrap">
          <div class="confetti" id="confetti" aria-hidden="true"></div>
          <div class="cf-badge" aria-hidden="true">
            <i class="cf-ring"></i>
            <svg viewBox="0 0 60 60"><path class="cf-check" d="M17 31.5l9 9L44 21" pathLength="1"/></svg>
          </div>
          <h1 class="confirm-title">{{ ___('Order Confirmed') }}</h1>
          <p class="confirm-sub"><span>{{ ___('Your order has been placed successfully') }}</span><br><b class="confirm-eta" id="confirmEta"></b></p>
          <div class="confirm-amount"><small>{{ ___('Total') }}</small><b id="confirmTotal"></b></div>
          {{-- the reference chip only renders when the SERVER supplied one --}}
          <div class="confirm-code" id="confirmCode" hidden></div>
          <div class="confirm-actions">
            <button class="blkbar" data-go="track" type="button">
              <span class="bb-label">{{ ___('Track Order') }}</span>
              <span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span>
            </button>
            <a class="ghostbtn" id="confirmTrackFull" href="#" hidden>{{ ___('Track Order') }}</a>
            <button class="ghostbtn" id="backToMenu" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span>{{ ___('Back to menu') }}</span></button>
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

      {{-- TRACK / LIVE TRACKING (in-app courier map — lime spotlight) --}}
      <section class="view view-sub" data-view="track">
        <div class="view-scroll">
          <header class="page-head sub">
            <button class="dots ghost" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
            <h1 class="ph-title">{{ ___('Track Order') }}</h1>
            <span class="order-chip" id="trackCode" hidden></span>
          </header>

          <div class="mapcard" id="mapCard">
            <svg id="mapSvg" viewBox="0 0 336 232" preserveAspectRatio="xMidYMid slice">
              <rect class="m-base" width="336" height="232"/>
              <rect class="m-block" x="12" y="8" width="76" height="48" rx="10"/>
              <rect class="m-block" x="112" y="8" width="98" height="48" rx="10"/>
              <rect class="m-block" x="234" y="8" width="56" height="48" rx="10"/>
              <rect class="m-park" x="112" y="80" width="98" height="48" rx="10"/>
              <rect class="m-block" x="12" y="80" width="76" height="48" rx="10"/>
              <rect class="m-block" x="236" y="84" width="88" height="44" rx="10"/>
              <rect class="m-block" x="12" y="152" width="76" height="42" rx="10"/>
              <rect class="m-block" x="114" y="152" width="96" height="42" rx="10"/>
              <rect class="m-block" x="236" y="152" width="88" height="42" rx="10"/>
              <path class="m-road thin" d="M-10 68H346"/>
              <path class="m-road" d="M-10 140H346"/>
              <path class="m-road thin" d="M-10 206H346"/>
              <path class="m-road thin" d="M100 -10V242"/>
              <path class="m-road" d="M222 -10V242"/>
              <circle class="m-dot" cx="150" cy="104" r="3.4"/>
              <circle class="m-dot" cx="60" cy="176" r="3.4"/>
              <circle class="m-dot" cx="258" cy="36" r="3.4"/>
              <path id="routePath" class="m-route" d="M30 206H86Q100 206 100 192V154Q100 140 114 140H208Q222 140 222 126V82Q222 68 236 68H302"/>
              <g class="m-poi" transform="translate(30 206)"><rect x="-13" y="-13" width="26" height="26" rx="9"/><path class="poi-glyph" d="M-6.5 -2.5 -5 -6h10l1.5 3.5M-5 -2.5v7.5h10v-7.5M-1.6 5v-4h3.2v4"/></g>
              <g class="m-poi" transform="translate(302 68)"><rect x="-13" y="-13" width="26" height="26" rx="9"/><path class="poi-glyph" d="M-5.5 4.5v-6.5L0 -6.5 5.5 -2v6.5ZM-1.2 4.5v-3.4h2.4v3.4"/></g>
              <g id="courierG" transform="translate(30 206)"><circle class="c-pulse" r="14"/><circle class="c-body" r="12"/><g class="c-bike"><circle cx="-4.2" cy="2.8" r="2.6"/><circle cx="4.6" cy="2.8" r="2.6"/><path d="M-4.2 2.8 -1.6 -2h3M1.4 -2 4.6 2.8M-1.6 -2l-1.6-2.4h-1.6"/></g></g>
            </svg>
          </div>

          <div class="arrive-banner" id="arriveBanner">
            <span class="ab-ic"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg></span>
            <p id="arriveMsg"></p>
          </div>

          {{-- The demo shipped an invented courier ("Samuel Turner"). There is no courier
               identity in the data, so this card carries the REAL order status instead —
               the map above it is a stylised progress route, not a GPS feed. --}}
          <div class="courier-card" id="courierCard">
            <div class="cc-top">
              @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="visibility">@endif
              <div class="cc-who"><b>{{ $store->title }}</b><small id="ccStatus">{{ ___('Status') }}</small></div>
              <span class="cc-live" id="ccLive"><i class="godot"></i><span>{{ ___('Live') }}</span></span>
            </div>
            <div class="steps" id="stepsRow"></div>
          </div>

          @if($store->phone || $waNumber)
          <div class="contact-capsule" id="contactCapsule">
            @if($store->phone)
            <a href="tel:{{ $store->phone }}">
              <span class="ccap-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg></span>
              <span>{{ ___('Phone') }}</span>
            </a>
            @endif
            @if($waNumber)
            @if($store->phone)<i class="ccap-div"></i>@endif
            <a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
              <span class="ccap-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M21 12a8.5 8.5 0 0 1-8.5 8.5c-1.6 0-3-.4-4.3-1.1L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12Z"/></svg><i class="chat-dot"></i></span>
              <span>{{ ___('Chat') }}</span>
            </a>
            @endif
          </div>
          @endif

          <a class="ghostbtn" id="trackFullLink" href="#" hidden style="margin-top:14px;justify-self:center">{{ ___('Track Order') }}</a>
          @if($googleReviewUrl)
          <div class="review-block" id="reviewBlock" hidden>
            <p class="rv-q">{{ ___('Enjoying your meal?') }}</p>
            <a class="lime-pill google" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener">
              <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>
              <span>{{ ___('Rate us on Google') }}</span>
            </a>
          </div>
          @endif
          <div class="tail-space"></div>
        </div>
      </section>

    </main>

    @include('store_templates._partials.notif-bell')

    {{-- ══════════ BOTTOM NAV (black pill — lime signature) ══════════ --}}
    <nav class="tabbar" id="tabbar" aria-label="{{ ___('Main') }}">
      <button class="tab is-on" data-tab="home" type="button" aria-label="{{ ___('Home') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5"/><path d="M6 10v9h12v-9"/></svg></span></button>
      <button class="tab" data-tab="menu" type="button" aria-label="{{ ___('Menu') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></span></button>
      @if(theme_setting($store->id, 'search.enabled', true))
      <button class="tab" data-tab="search" type="button" aria-label="{{ ___('Search') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg></span></button>
      @endif
      @if($allow_order)
      <button class="tab" data-tab="cart" type="button" aria-label="{{ ___('Bag') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12v11.6a2.4 2.4 0 0 1-2.4 2.4H8.4A2.4 2.4 0 0 1 6 19.6Z"/><path d="M9.4 8V6.6a2.6 2.6 0 0 1 5.2 0V8"/></svg><i class="badge" id="bagBadge" hidden>0</i></span></button>
      @endif
      <button class="tab" data-tab="favs" type="button" aria-label="{{ ___('Favorites') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></span></button>
      <button class="tab" data-tab="profile" type="button" aria-label="{{ ___('Profile') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg></span></button>
      <button class="tab" data-tab="orders" type="button" aria-label="{{ ___('Activity') }}"><span class="tab-pill"><svg class="ic" viewBox="0 0 24 24"><path d="M4 8l8-4 8 4v8l-8 4-8-4Z"/><path d="M4 8l8 4 8-4M12 12v8"/></svg></span></button>
    </nav>

    {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
    <div class="scrim" id="scrim" hidden></div>

    {{-- PROFILE --}}
    <div class="sheet" id="profileSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Profile') }}</h2>
      <div class="me-card">
        <span class="me-avatar">{{ Str::upper(Str::substr($customer->name ?? $store->title, 0, 1)) }}</span>
        <div>
          <b>{{ $customer->name ?? ___('Guest') }}</b>
          <small>{{ $customer ? ($customer->phone ?? $customer->email) : ___('Order without an account') }}</small>
        </div>
        @if($authMode !== 'guest_only')
          @if($customer)
            <button class="minibtn" id="logoutBtn" type="button">{{ ___('Logout') }}</button>
          @else
            <button class="minibtn" id="meAuthBtn" type="button">{{ ___('Login') }}</button>
          @endif
        @endif
      </div>
      @if($customer && $storeCredit > 0)
      <div class="wallet-line"><span>{{ ___('Wallet') }}</span><b>{{ store_money_format($storeCredit, $store->id) }}</b></div>
      @endif

      {{-- LIME-F7 · appearance (Auto follows the device) --}}
      <div class="pref-row">
        <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.2 5.2l1.8 1.8M17 17l1.8 1.8M18.8 5.2 17 7M7 17l-1.8 1.8"/></svg>{{ ___('Appearance') }}</span>
        <div class="seg mini" id="themeSeg">
          <button data-theme-mode="auto" type="button">{{ ___('Auto') }}</button>
          <button data-theme-mode="light" type="button">{{ ___('Light') }}</button>
          <button data-theme-mode="dark" type="button">{{ ___('Dark') }}</button>
        </div>
      </div>

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
          <button data-cur="{{ $storeCurrency }}" data-rate="1" class="is-on" type="button">{{ $storeCurrency }}</button>
          @foreach($mcRates as $code => $rate)
            <button data-cur="{{ $code }}" data-rate="{{ $rate }}" type="button">{{ $code }}</button>
          @endforeach
        </div>
      </div>
      @endif

      @if($showBranches)
      <button class="row-line" id="branchLine" type="button">
        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
        <span>{{ ___('Choose branch') }}</span>
        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
      </button>
      @endif

      @if($googleReviewUrl)
      <a class="row-line" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener">
        <svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>
        <span>{{ ___('Rate us on Google') }}</span>
        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
      </a>
      @endif
      @if($waNumber)
      <a class="row-line" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
        <svg class="ic wa-ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
        <span>{{ ___('WhatsApp') }}</span>
        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
      </a>
      @endif

      {{-- Share this menu — native profile row (like the WhatsApp .row-line), not the
           floating pill. Gated on the same share.fallback_button toggle; data-web-share
           makes _partials/share stand down. --}}
      @if(theme_setting($store->id, 'share.fallback_button'))
      <button class="row-line share-btn" id="mbShareRow" type="button" data-web-share
              aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}">
        <svg class="ic" viewBox="0 0 24 24"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg>
        <span>{{ ___('Share this menu') }}</span>
        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
      </button>
      @endif
      <div class="biz-card">
        @if($store->address)<span><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</span>@endif
        @if($store->phone)<span><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><a href="tel:{{ $store->phone }}">{{ $store->phone }}</a></span>@endif
      </div>
      <p class="demo-foot">{{ $store->title }}</p>
    </div>

    {{-- MORE (product / cart options) --}}
    <div class="sheet" id="moreSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Options') }}</h2>
      <div class="sheet-list" id="moreBody"></div>
    </div>

    {{-- LOYALTY --}}
    @if($loyaltyProgram)
    <div class="sheet" id="loyaltySheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ $loyaltyProgram->type === 'stamps' ? ___('Stamps') : ___('Points') }}</h2>
      <div class="loy-big"><b id="loyPts">0</b> <span>{{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('pts') }}</span></div>
      <div class="loy-bar"><i id="loyBar"></i></div>
      <p class="sheet-sub">{{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }} ({{ $loyaltyProgram->reward_threshold }})</p>
      @if($customer)
      <button class="blkbar" id="loyRedeem" type="button" hidden>
        <span class="bb-label">{{ ___('Redeem Reward') }}</span>
        <span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span>
      </button>
      @else
      <p class="sheet-sub">{{ ___('Login to collect and redeem rewards.') }}</p>
      @endif
      <p class="loy-msg" id="loyMsg" hidden>{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
    </div>
    @endif

    {{-- RESERVATIONS (real server form) --}}
    @if($reservationsEnabled)
    <div class="sheet" id="reserveSheet" hidden>
      <div class="sheet-grab"></div>
      {{-- Explicit close (carries sheet-grab for the existing close wiring). --}}
      <button class="sheet-grab sheet-x" type="button" aria-label="{{ ___('Close') }}">
        <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
      <h2>{{ ___('Reserve a Table') }}</h2>
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
          <button class="blkbar" type="submit"><span class="bb-label">{{ ___('Reserve a Table') }}</span><span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span></button>
        </form>
      @endif
    </div>
    @endif

    {{-- WAITLIST (real server form) --}}
    @if($waitlistEnabled)
    <div class="sheet" id="waitlistSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Join the Waitlist') }}</h2>
      @if(session('waitlist_joined'))
        <div class="sheet-done">
          <span class="sheet-done-emoji">🎉</span>
          <b>{{ ___('Thank you!') }}</b>
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
          <button class="blkbar" type="submit"><span class="bb-label">{{ ___('Join waitlist') }}</span><span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span></button>
        </form>
      @endif
    </div>
    @endif

    {{-- PER-ITEM NOTE --}}
    <div class="sheet" id="noteSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Add note') }}</h2>
      <textarea aria-label="{{ ___('No onions, extra sauce…') }}" id="noteInput" rows="3" placeholder="{{ ___('No onions, extra sauce…') }}"></textarea>
      <button class="blkbar" id="noteSave" type="button"><span class="bb-label">{{ ___('Save') }}</span><span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span></button>
    </div>

    {{-- WHATSAPP ORDER --}}
    @if($waNumber)
    <div class="sheet" id="waSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('WhatsApp') }}</h2>
      <pre class="wa-preview" id="waPreview"></pre>
      <a class="blkbar wa-go" id="waOpen" href="#" target="_blank" rel="noopener">
        <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
        <span class="bb-label">{{ ___('WhatsApp') }}</span>
      </a>
    </div>
    @endif

    {{-- BRANCH PICKER --}}
    @if($showBranches)
    <div class="sheet" id="branchSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Choose branch') }}</h2>
      <button class="row-line" id="nearestBranchBtn" type="button">
        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
        <span>{{ ___('Find nearest branch on map') }}</span>
        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
      </button>
      <div class="branch-list" style="margin-top:9px">
        @foreach($branches as $branch)
          <a class="branch-row {{ ($activeBranch->id ?? null) === $branch->id ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <b>{{ $branch->name }}</b><span class="branch-check"></span>
          </a>
        @endforeach
      </div>
    </div>

    {{-- NEAREST-BRANCH MAP (self-hosted Leaflet geo picker) --}}
    <div class="sheet sheet-tall" id="branchMapSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Nearest branch') }}</h2>
      <div class="branchmap" id="branchMap"></div>
      <p class="geo-attrib" id="mapAttrib"></p>
      <div class="branch-list" id="branchGeoList"></div>
    </div>
    @endif

    {{-- LANGUAGE PICKER --}}
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

    {{-- CATERING & EVENTS — opened from the header #cvCateringBtn / condensed proxy;
         the shared form is included here with cateringInline=true so this sheet owns
         placement (no self-relocation to the menu bottom). --}}
    @if(storefront_catering_enabled($store->id))
    <div class="sheet" id="cvCateringSheet" hidden>
      <div class="sheet-grab"></div>
      @include('store_templates._partials.catering-form', ['cateringInline' => true])
    </div>
    @endif

    {{-- AUTH --}}
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
        <button class="blkbar" id="loginBtn" type="button"><span class="bb-label">{{ ___('Login') }}</span><span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span></button>
        @include('store_templates.partials.social-login-buttons', ['store' => $store])
        @include('store_templates.partials.passkey-login', ['store' => $store])
      </div>
      <div class="auth-form" data-auth="otp" hidden>
        <div id="otpStep1">
          <p class="sheet-sub">{{ ___("We will text you a one-time code. No password needed") }}</p>
          <input aria-label="{{ ___('Phone number') }}" id="otpPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel">
          <button class="blkbar" id="otpSend" type="button" style="margin-top:11px"><span class="bb-label">{{ ___('Send code') }}</span><span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span></button>
        </div>
        <div id="otpStep2" hidden>
          <p class="sheet-sub">{{ ___('Enter the code we sent you') }}</p>
          <div class="otp-boxes" id="otpBoxes">
            <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
          </div>
          <button class="blkbar" id="otpVerify" type="button" style="margin-top:11px"><span class="bb-label">{{ ___('Verify') }}</span><span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span></button>
        </div>
      </div>
      <div class="auth-form" data-auth="register" hidden>
        <input aria-label="{{ ___('Your Name') }}" id="regName" placeholder="{{ ___('Your Name') }}" autocomplete="name">
        <input aria-label="{{ ___('Email') }}" id="regEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
        <input aria-label="{{ ___('Phone number') }}" id="regPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
        <input aria-label="{{ ___('Password') }}" id="regPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
        {{-- the customer register endpoint validates password with `confirmed`, so a
             matching password_confirmation is required or every sign-up 422s. --}}
        <input aria-label="{{ ___('Confirm Password') }}" id="regPass2" type="password" placeholder="{{ ___('Confirm Password') }}" autocomplete="new-password">
        <button class="blkbar" id="registerBtn" type="button"><span class="bb-label">{{ ___('Register') }}</span><span class="bb-go"><svg class="ic" viewBox="0 0 28 24"><path d="M4 6l5.5 6L4 18M12 6l5.5 6L12 18M20 6l5.5 6L20 18"/></svg></span></button>
        @include('store_templates.partials.social-login-buttons', ['store' => $store])
      </div>
    </div>
    @endif

    {{-- full-screen story viewer --}}
    <div class="storyview" id="storyViewer" hidden>
      <div class="sv-progress" id="svProgress"></div>
      <button class="sv-close" id="svClose" type="button" aria-label="{{ ___('Close') }}">
        <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
      <div class="sv-stage" id="svStage">
        <img class="sv-media" id="svMedia" alt="">
        <button class="sv-nav prev" id="svPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
        <button class="sv-nav next" id="svNext" type="button" aria-label="{{ ___('Next') }}"></button>
        <div class="sv-foot">
          <p class="sv-caption" id="svCaption"></p>
          <a class="cta sv-cta" id="svCta" hidden><span>{{ ___('Order now') }}</span></a>
        </div>
      </div>
    </div>

    <div class="toast" id="toast" role="status"></div>
    <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>

    {{-- Catering & Events now lives in #cvCateringSheet (opened from the header
         button); the form is included there with cateringInline=true. --}}
  </div>{{-- /.app --}}
</div>{{-- /.lime --}}
@endsection

@push('scripts_at_bottom')
{{-- self-hosted Leaflet (nearest-branch map) — must load before script.js runs --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
<script nonce="{{ csp_nonce() }}">
    window.LIME = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        restaurantTitle:@json($store->title),
        currencySign:   @json($curSign),
        currencyGap:    @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:    @json($curPos),
        currencyDecimals: @json($storeDecimals),
        moneyDecimals: @json(money_decimals(@$postOptions->currency_code ?: null)), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode:   @json(currency_decimals_map()),
        baseCurrency:   @json($storeCurrency),
        currencyRates:  @json($mcRates),
        taxCharge:      @json($taxCharge),
        taxType:        @json($taxType),
        taxLabel:       @json($taxLabel),
        prepEta:        @json(trim((string) (@$postOptions->prep_eta_static ?: '')) ?: null),
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
        loyaltyType:      @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json((int) ($loyaltyProgram->reward_threshold ?? 0)),
        openResSheet:      @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        items:          @json((object) $jsItems),
        categories:     @json($jsCats),
        waNumber:       @json($waNumber),
        leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
        endpoints: {
            branchesGeo: @json(route('geo.branches', $store->slug)),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:      @json(route('geo.reverse')),
            submitOrder:   @json(route('store.submitOrder', $store->id)),
            coupon:      @json(route('store.applyCoupon', $store->id)),
            waiter:      @json(route('store.callTheWaiter', $store->id)),
            review:      @json(route('store.submitReview', $store->slug)),
            itemEvent:   @json(route('store.recordItemEvent', $store->slug)),
            stories:     @json(route('stories.public', $store->slug)),
            follow:      @json(url('r/'.$store->slug.'/follow')),
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
            sold_out:      @json(___('Sold Out')),
            not_available: @json(___('Not available now')),
            starting_from: @json(___('From')),
            added:         @json(___('Added to Cart')),
            removed:       @json(___('Removed')),
            now_following: @json(___('You are now following :store', ['store' => $store->title])),
            choose:        @json(___('Choose an option')),
            required:      @json(___('Required')),
            optional:      @json(___('Optional')),
            extras:        @json(___('Add extras')),
            subtotal:      @json(___('Subtotal')),
            discount:      @json(___('Discount')),
            delivery:      @json(___('Delivery')),
            tip:           @json(___('Tip')),
            total:         @json(___('Total')),
            your_order:    @json(___('Your order')),
            edit:          @json(___('Edit')),
            min_order:     @json(___('Minimum order')),
            select_type:   @json(___('Please choose an order type')),
            fill_contact:  @json(___('Please enter your name.')),
            fill_addr:     @json(___('Delivery address')),
            loc_unavailable: @json(___('Location unavailable')),
            loc_denied:    @json(___('Location denied')),
            no_address:    @json(___('No address found')),
            accept_terms:  @json(___('Required').': '.___('Terms and Conditions')),
            coupon_bad:    @json(___('Coupon code is invalid.')),
            coupon_ok:     @json(___('Coupon applied successfully.')),
            waiter_ok:     @json(___('Call waiter').' · '.___('Saved Successfully')),
            bill_ok:       @json(___('Request bill').' · '.___('Saved Successfully')),
            note_saved:    @json(___('Saved Successfully')),
            bag_cleared:   @json(___('Your cart is empty')),
            clear_bag:     @json(___('Clear cart')),
            share:         @json(___('Share')),
            share_ok:      @json(___('Copied!')),
            add_fav:       @json(___('Favorites')),
            rm_fav:        @json(___('Favorites')),
            wa_order:      @json(___('WhatsApp')),
            all:           @json(___('All')),
            no_branches:   @json(___('No branches nearby')),
            veg:           @json(___('Veg')),
            vegan:         @json(___('Vegan')),
            halal:         @json(___('Halal')),
            gf:            @json(___('Gluten-free')),
            kcal:          @json(___('kcal')),
            fresh_now:     @json(___('Popular')),
            table:         @json(___('Table')),
            choose_table:  @json(___('Table number')),
            delivery_hint: @json(___('Delivered to your door')),
            pickup_hint:   @json(___('Pickup at the counter')),
            room_hint:     @json(___('We will bring it to your room')),
            items_word:    @json(___('Items')),
            read_more:     @json(___('Read More')),
            read_less:     @json(___('Less')),
            /* order-status ladder — 1:1 with the server's own statuses */
            st_received:   @json(___('Received')),
            st_prep:       @json(___('Preparing')),
            st_onway:      @json(___('On the Way')),
            st_ready:      @json(___('Ready')),
            st_served:     @json(___('Served')),
            st_delivered:  @json(___('Delivered')),
            st_done:       @json(___('Completed')),
            cancelled:     @json(___('Cancelled')),
            prep_eta_prefix: @json(___('Ready in')),
            min_word:      @json(___('min')),
            reorder:       @json(___('Reorder')),
            allergens:     @json(___('Allergens')),
            note:          @json(___('Note')),
            max_word:      @json(___('Max')),
            network_error: @json(___('Network error.')),
            order_failed:  @json(___('Something went wrong.')),
            filters:       @json(___('Filters')),
            reset:         @json(___('Reset')),
            veg_only:      @json(___('Vegetarian')),
            no_results:    @json(___('No matches')),
            reviews_title: @json(___('Reviews')),
            reviews_empty: @json(___('No reviews yet.')),
            rate_dish:     @json(___('Rate this dish')),
            thanks:        @json(___('Thanks!')),
            addr_ph:       @json(___('Street, building, floor…')),
            use_my_location: @json(___('Use my location')),
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
