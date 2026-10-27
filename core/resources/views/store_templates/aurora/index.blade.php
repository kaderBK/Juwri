{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       Data preparation
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus = collect($total_menus)->values();
    $items = $allMenus->values();
    $byCat = $items->groupBy('category_id');
    /* Top-level only, ON PURPOSE — do NOT swap in $store->menu_categories_flat here.
       Aurora renders NESTED: one .au-cat-section per parent, which draws that parent's
       own items and then a subsection per subcategory (see the menu section below), so
       subcategory items are already reached. The flat accessor exists for themes that
       iterate categories and match items by $cat->id only — those drop subcategory
       items; this one does not. Flattening here would emit every subcategory twice
       (once nested, once top-level), give each its own chip, and break the chip /
       scroll-spy contract in js/script.js, which keys off data-cat = PARENT id. */
    $categories = $store->menu_categories->values();

    /* Two-tier subcategory nav (parity #2). Aurora already NESTS subcategory items
       inside their parent's <section data-cat=PARENT>, so a parent chip shows every
       descendant item — that half of the contract holds by construction. What was
       missing is the secondary sub-chip row that narrows to one subcategory. Cards
       already carry their own category_id (data-cat on .au-card), so narrowing is a
       card-level match; here we only need the map of which parents have item-bearing
       subcategories (drives data-has-sub + the swap-in sub-chip groups). */
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

    $mcDisplay = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id)); // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)
    $mcRates = $mcDisplay->rates;
    $mcAsOf = $mcDisplay->asOf; // MC-34: FX feed as-of date (null for manual rates)

    /* featured = first item that has a real image */
    $featured = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    /* chef's specials rail = a curated slice of the menu */
    $specials = $items->take(8);
    /* bespoke aurora splash imagery (first few real-photo dishes) */
    $splashImgs = \Juwri\Core\Support\ViewHelpers::imageItems($items, 3);

    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $curGap  = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only
    $deliveryCharge    = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge         = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    /* TOTALS CONTRACT: the client must reproduce Order::computeTax() exactly.
       `restaurant_tax_type` has two modes -- a percentage of subtotal, or a FLAT
       amount -- and a theme that always multiplies bills a flat-tax restaurant a
       percentage of the basket. Rounding follows the RESTAURANT's currency, not
       the platform default, or a 3-decimal store loses its fils. */
    $taxType           = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');
    $storeDecimals     = currency_decimals(@$postOptions->currency_code ?: null);
    $minOrderValue     = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched   = !empty($postOptions->allow_order_scheduling);
    /* R4 · open/closed status chip (parity with crave). The store is treated as
       open unless the owner has explicitly flipped the open/close switch off. */
    $isOpen            = store_open_now($store);

    /* helper: human label for a variant combination */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelLegacy(...);

    /* build the JS data map (full detail incl. variants + extras + allergies) */
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
                    'img'   => $e->image ? media_url('storage/menu/', $e->image, $store->id ?? null) : '',
                ];
            }
        }

        $jsItems[$m->id] = [
            'id'        => $m->id,
            'name'      => $m->name,
            'desc'      => strip_tags((string) $m->description),
            'img'       => media_url('storage/menu/', $m->image, $store->id ?? null),
            'price'     => (float) $m->price,
            'type'      => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'    => $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : '',
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyList($itemAllergies),
            'variants'  => $variants,
            'extras'    => $extras,
            'is_vegan'  => (bool) $m->is_vegan,
            'is_halal'  => (bool) $m->is_halal,
            'is_gluten_free' => (bool) $m->is_gluten_free,
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
    // Coupons & Wallets P7-6a: pay-with-credit for a logged-in diner with balance.
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;
    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $customerDefaultAddress = $customerAddresses->first();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);

    /* ── theme-pack-blade features (F1–F7) ──────────────────────────────
       F1/F2/F6 hit CustomerToken-guarded routes; mint (and session-cache) a
       raw token for the signed-in session customer so the theme JS can call them. */
    $customerApiToken = storefront()->customerApiToken($customer, 'aurora');

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

    /* F4 · nearest-branch map (only when the store actually has switchable branches) */
    $showBranches = !empty($branches) && count($branches) > 1 && (@$postOptions->allow_multi_branch_switch ?? 1);
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- F4 · self-hosted Leaflet (CSP-safe; tiles load as https imgs) --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
@endpush

@section('content')
<div class="aurora" id="aurora">
    <div class="au-ambient"></div>

    {{-- ============================================================ SPLASH
         Bespoke aurora intro overlay. Auto-dismisses via pure CSS animation
         (CSP-safe); the skip button is a progressive enhancement. --}}
    @php
        $auHeroVisible = (bool) (collect($sectionsToRender ?? [])->firstWhere('id', 'hero')['visible'] ?? true);
    @endphp
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($auHeroVisible && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="au-splash" id="au-splash" role="presentation" aria-hidden="true">
        <div class="au-splash-inner">
            @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img class="au-splash-logo" src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}"
                 alt="" data-csp-error="hide">@endif
            <div class="au-splash-hero" aria-hidden="true">
                <i class="au-splash-ring"></i>
                @foreach($splashImgs as $si)
                    @if((string) (media_url('storage/menu/', $si->image, $store->id ?? null)) !== '')<img class="au-splash-pop au-splash-pop-{{ $loop->index }}"
                         src="{{ media_url('storage/menu/', $si->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                @endforeach
            </div>
            <h1 class="au-splash-title"><span class="au-grad">{{ $store->title }}</span></h1>
            <p class="au-splash-tag">{{ $store->sub_title ?: ___('Digital Menu') }}</p>
        </div>
        <button class="au-splash-skip" id="au-splash-skip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- B2: the "N viewing now" proof merged into the brand/follow row (single source
         of proof — see the follower-count partial). The old #32 full-width banner is
         removed so the viewer count is not shown twice. --}}

    {{-- ============================================================ TOP BAR --}}
    <header class="au-topbar" id="au-topbar">
        <div class="au-wrap">
            <a href="#aurora" class="au-brand-mini">
                @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="{{ $store->title }}"
                     data-csp-error="hide">@endif
                <b>{{ $store->title }}</b>
            </a>
            <div class="au-topbar-actions">
                @if(!empty($menu_languages) && count($menu_languages) > 1)
                    <div class="au-lang" id="au-lang">
                        <button class="au-icon-btn" type="button" id="au-lang-toggle" aria-label="{{ ___('Language') }}">
                            <i class="ico ico-world" aria-hidden="true"><x-ui.icon name="world" /></i>
                        </button>
                        <div class="au-lang-menu">
                            @foreach($menu_languages as $language)
                                <button type="button" data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" data-code="{{ $language->code }}" data-lang="{{ $language->name }}">
                                    {{ $language->name }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
                {{-- #27: Multi-currency toggle --}}
                @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
                <div class="au-lang" id="au-currency-wrap">
                    <button class="au-icon-btn" type="button" id="au-currency-toggle" aria-label="{{ ___('Currency') }}" title="{{ ___('Display currency') }}" style="font-size:11px;font-weight:700;min-width:36px">
                        <span id="au-cur-label">{{ config('settings.currency_code', '—') }}</span>
                    </button>
                    <div class="au-lang-menu">
                        <button type="button" data-cur="{{ config('settings.currency_code', 'USD') }}" data-rate="1">{{ config('settings.currency_code', 'USD') }}</button>
                        @foreach($mcRates as $code => $rate)
                            <button type="button" data-cur="{{ $code }}" data-rate="{{ $rate }}">{{ $code }}</button>
                        @endforeach
                    </div>
                </div>
                @endif
                {{-- #39/#40: Light/dark theme toggle --}}
                <button class="au-theme-toggle" type="button" id="au-theme-toggle"
                        aria-label="{{ ___('Toggle light/dark mode') }}" title="{{ ___('Toggle theme') }}">
                    <i class="ico ico-moon" id="au-theme-icon" aria-hidden="true"><x-ui.icon name="moon" /></i>
                </button>
                <button class="au-icon-btn" type="button" id="au-fav-toggle" aria-label="{{ ___('Favorites') }}">
                    <i class="ico ico-heart-filled" aria-hidden="true"><x-ui.icon name="heart-filled" /></i>
                    <span class="au-badge-count" id="au-fav-count">0</span>
                </button>
                @if(storefront_catering_enabled($store->id))
                {{-- aurora is a scrolling theme (no sheet framework); the catering form
                     stays inline and this header button jumps to it. data-catering-open
                     still fires the shared guest-autofill. --}}
                <a id="cvCateringBtn" class="au-icon-btn" href="#catering" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                </a>
                @endif
                <button class="au-icon-btn" type="button" id="mbNotifBell" aria-label="{{ ___('Notifications') }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                    <i id="mbNotifDot" hidden style="position:absolute;top:6px;inset-inline-end:6px;width:9px;height:9px;border-radius:50%;background:var(--au-accent,#ff4d6d);box-shadow:0 0 0 2px var(--au-surface,#12121a)"></i>
                </button>
                @if($authMode !== 'guest_only')
                    @if($customer)
                        <div class="au-lang" id="au-account-menu">
                            <button class="au-icon-btn" type="button" aria-label="{{ ___('Account') }}">
                                <i class="ico ico-user-check" style="color: var(--au-fx)" aria-hidden="true"><x-ui.icon name="user-check" /></i>
                            </button>
                            <div class="au-lang-menu">
                                <div style="padding: 10px; font-weight: 600; border-bottom: 1px solid var(--au-border);">{{ $customer->name }}</div>
                                <a href="{{ route('customer.account', $store->id) }}" style="display:block; padding:8px 10px; color:inherit; text-decoration:none;">{{ ___('My Orders') }}</a>
                                <button type="button" id="au-logout-btn">{{ ___('Logout') }}</button>
                            </div>
                        </div>
                    @else
                        <button class="au-icon-btn" type="button" id="au-login-toggle" aria-label="{{ ___('Login') }}">
                            <i class="ico ico-user" aria-hidden="true"><x-ui.icon name="user" /></i>
                        </button>
                    @endif
                @endif
                @if($allow_order)
                    <button class="au-icon-btn" type="button" id="au-cart-toggle" aria-label="{{ ___('Your order') }}">
                        <i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i>
                        <span class="au-badge-count" id="au-cart-count">0</span>
                    </button>
                @endif
            </div>
        </div>
    </header>
    @php
        $savedSections = isset($postOptions->menu_sections) 
            ? json_decode(json_encode($postOptions->menu_sections), true) 
            : null;
        $defaultSections = [
            ['id' => 'hero', 'visible' => true],
            // catbar renders just before 'menu' so the category selector sits directly
            // above the items it filters — the daily-specials + specials rails (and the
            // injected offers strip, which climbs over the grid-lead catbar) stay above
            // the categories instead of wedging between the categories and the menu.
            ['id' => 'daily_specials', 'visible' => true],
            ['id' => 'specials', 'visible' => true],
            ['id' => 'catbar', 'visible' => true],
            ['id' => 'menu', 'visible' => true],
        ];

        $sectionsToRender = [];
        if ($savedSections && is_array($savedSections)) {
            foreach ($savedSections as $saved) {
                if (collect($defaultSections)->contains('id', $saved['id'])) {
                    $sectionsToRender[] = [
                        'id' => $saved['id'],
                        'visible' => isset($saved['visible']) ? (bool)$saved['visible'] : true
                    ];
                }
            }
            // Append missing ones
            foreach ($defaultSections as $def) {
                if (!collect($sectionsToRender)->contains('id', $def['id'])) {
                    $sectionsToRender[] = $def;
                }
            }
        } else {
            $sectionsToRender = $defaultSections;
        }

        // Customizer v2: the theme customizer is the newer source of truth for section
        // visibility, so its overrides win over the legacy menu_sections row above. Ids
        // the theme's schema does not declare keep whatever menu_sections said, so an
        // untouched store renders exactly as before.
        $auCustomizerVisible = theme_section_visible($store->id);
        $sectionsToRender = array_map(function ($section) use ($auCustomizerVisible) {
            if (array_key_exists($section['id'], $auCustomizerVisible)) {
                $section['visible'] = $auCustomizerVisible[$section['id']];
            }

            return $section;
        }, $sectionsToRender);

        // B10: the brand/follow row is a toggleable section (anchored under the header,
        // not orderable). Customizer visibility wins over the legacy menu_sections row.
        $auBrandRowSaved = collect($savedSections ?: [])->firstWhere('id', 'brand_row');
        $auBrandRowVisible = array_key_exists('brand_row', $auCustomizerVisible)
            ? (bool) $auCustomizerVisible['brand_row']
            : (isset($auBrandRowSaved['visible']) ? (bool) $auBrandRowSaved['visible'] : true);
    @endphp

    @foreach($sectionsToRender as $sect)
        @if($sect['visible'])
            @if($sect['id'] === 'hero')
                {{-- ================================================================= HERO --}}
                <section class="au-hero">
                    <div class="au-wrap">
                        <div class="au-hero-card">
                            <div class="au-hero-cover">
                                @if((string) (media_url('storage/store/cover/', $store->cover_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) }}" alt=""
                                     data-csp-error="parent-hide">@endif
                            </div>
                            <div class="au-hero-inner">
                                <div>
                                    @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img class="au-hero-logo" src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}"
                                         alt="{{ $store->title }}" data-csp-error="hide">@endif
                                    <span class="au-eyebrow"><span class="au-dot"></span> {{ ___('Digital Menu') }}</span>
                                    <h1><span class="au-grad" data-tc-text="texts.hero_title">{{ theme_setting($store->id, 'texts.hero_title', '') ?: $store->title }}</span></h1>
                                    <p class="au-hero-tag" data-tc-text="texts.hero_subtitle">{{ theme_setting($store->id, 'texts.hero_subtitle', '') ?: ($store->sub_title ?: strip_tags((string) $store->description) ?: ___('A next-generation dining experience. Browse, customize and order in seconds.')) }}</p>
                                    <div class="au-hero-meta">
                                        {{-- R4 · open-now / closed status chip --}}
                                        <span class="au-chip-meta au-open-chip {{ $isOpen ? '' : 'closed' }}" data-mb-openchip data-mb-closed-class="closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                                        @if($store->address)
                                            <span class="au-chip-meta"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i> {{ $store->address }}</span>
                                        @endif
                                        @if($store->phone)
                                            <span class="au-chip-meta"><i class="ico ico-phone" aria-hidden="true"><x-ui.icon name="phone" /></i> {{ $store->phone }}</span>
                                        @endif
                                        {{-- A store-wide "Highly rated" badge used to render here
                                             unconditionally, on every restaurant, with nothing behind
                                             it. Show the real average when the menu actually has
                                             ratings; otherwise show nothing. --}}
                                        @php
                                            $auRated = $items->filter(fn($m) => (float) ($m->rating_avg ?? 0) > 0);
                                            $auAvg = $auRated->count() ? $auRated->avg('rating_avg') : null;
                                        @endphp
                                        @if($auAvg)
                                            <span class="au-chip-meta"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ number_format($auAvg, 1) }}</span>
                                        @endif
                                        @if($table)
                                            <span class="au-chip-meta"><i class="ico ico-armchair" aria-hidden="true"><x-ui.icon name="armchair" /></i> {{ ___('Table') }} {{ $table->table_no }}</span>
                                        @endif
                                    </div>

                                    {{-- F2 · brand / follow row (B10: customizer section, default on) --}}
                                    @if($auBrandRowVisible)
                                    <div class="au-brand-row" id="au-brand-row" data-tc-section="brand_row" data-mb-hotel-chip-slot>
                                        <span class="au-brand-info" id="au-brand-info">
                                            <b class="au-brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="au-verified-badge" role="img" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
                                            @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true), 'countId' => 'au-follower-count'])
                                        </span>
                                        {{-- Following needs a customer account: on a guest_only store the
                                             auth sheet is never rendered, so openAuth() does not exist and
                                             the click handler returns silently. Hide the control instead of
                                             shipping a button that cannot do anything. --}}
                                        @if($authMode !== 'guest_only' || $customer)
                                        <button class="au-follow-btn {{ $isFollowing ? 'is-on' : '' }}" id="au-follow-btn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
                                            <i class="ico ico-plus au-follow-ic" aria-hidden="true"><x-ui.icon name="plus" /></i>
                                            <span class="au-follow-label" data-tc-text="texts.follow_label">{{ theme_setting($store->id, 'texts.follow_label', ___('Follow')) }}</span>
                                            <span class="au-following-label">{{ ___('Following') }}</span>
                                        </button>
                                        @endif
                                    </div>
                                    @endif

                                    {{-- F3 · stories rail (populated from /r/{slug}/stories) --}}
                                    <div class="au-stories-rail" id="au-stories-rail" data-story-label="{{ ___('View story') }}" hidden></div>
                                    {{-- Customizer v2: the storefront search bar is switchable from the
                                         theme customizer, so a store that browses by category only can
                                         drop it. Enabled by default, which keeps the theme unchanged. --}}
                                    {{-- search + dietary filters moved out of the hero to the head of the
                                         menu section (au-menu-root) so a query/filter changes the food in
                                         view instead of acting on a grid ~1300px below the header. --}}
                                </div>
                                @if($featured)
                                    <div class="au-featured" @if(!$featured->is_sold_out) data-open-item="{{ $featured->id }}" @else style="opacity: 0.7;" @endif>
                                        <span class="au-featured-tag">{{ ___("Chef's Pick") }}</span>
                                        @if($featured->is_sold_out)
                                            <span class="au-badge" style="position:absolute;top:15px;inset-inline-end:15px;background:#ff4757;color:#fff;z-index:10;font-size:11px;padding:4px 8px;border-radius:12px;"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i> {{ ___('Sold Out') }}</span>
                                        @endif
                                        @if((string) (media_url('storage/menu/', $featured->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $featured->image, $store->id ?? null) }}" alt="{{ $featured->name }}"
                                             data-csp-error="opacity">@endif
                                        <div class="au-featured-body">
                                            <h3>{{ $featured->name }}</h3>
                                            <div class="au-price">{{ price_symbol_format($featured->price) }}</div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>
            @elseif($sect['id'] === 'catbar')
                {{-- Promo banners slider: relocated below the hero (it was the page's
                     first section), directly above the categories. --}}
                <div class="au-wrap">@include('store_templates.includes.menu-banners-slider')</div>
                {{-- ====================================================== CATEGORY CHIPS --}}
                {{-- data-mb-grid-lead: the category selector must touch the items it
                     filters — injected sections (offers strip, order-type bar) climb
                     above these rows instead of wedging between categories and grid. --}}
                <div class="au-catbar" id="au-catbar" data-mb-grid-lead data-mbt-anchor>
                    <div class="au-wrap">
                        <div class="au-chips" id="au-chips" data-mb-grid-lead>
                            <button class="au-chip active" type="button" data-target="all">
                                <span class="au-chip-ico">✦</span> {{ ___('All') }}
                            </button>
                            @foreach($categories as $cat)
                                @php
                                    $subIds   = $cat->subcategories->pluck('id')->all();
                                    $catCount = ($byCat[$cat->id] ?? collect())->count();
                                    foreach ($subIds as $sid) { $catCount += ($byCat[$sid] ?? collect())->count(); }
                                @endphp
                                @if($catCount > 0)
                                    <button class="au-chip" type="button" data-target="cat-{{ $cat->id }}"
                                            @isset($subCatsOf[$cat->id]) data-has-sub="1" @endisset>
                                        {{ $cat->name }}
                                    </button>
                                @endif
                            @endforeach
                        </div>

                        {{-- Secondary sub-category chips (two-tier nav). One hidden group per
                             parent that owns item-bearing subcategories; JS reveals the active
                             parent's group. "All" (data-sub="root") shows every descendant of
                             the parent; a specific sub-chip narrows to that subcategory. --}}
                        @if(!empty($subCatsOf))
                        <div class="au-subchips" id="au-subchips" hidden role="group" aria-label="{{ ___('Sub-categories') }}" data-mb-grid-lead>
                            @foreach($subCatsOf as $parentId => $subs)
                                <div class="au-subgroup" data-parent="{{ $parentId }}" hidden>
                                    <button class="au-subchip active" type="button" data-sub="root">{{ ___('All') }}</button>
                                    @foreach($subs as $sub)
                                        <button class="au-subchip" type="button" data-sub="{{ $sub->id }}">{{ $sub->name }}</button>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>

                {{-- #28: Sort toolbar --}}
                <div class="au-wrap" style="padding-top:0;padding-bottom:0;margin-top:-4px;margin-bottom:6px;">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <span style="font-size:12px;color:var(--au-muted,#aaa);margin-inline-end:4px;">{{ ___('Sort') }}:</span>
                        <button class="au-sort-btn active" type="button" data-sort="default" style="font-size:12px;padding:5px 12px;border-radius:20px;border:1px solid var(--au-border,#2a2a3a);background:var(--au-fx);color:#12121a;cursor:pointer;font-weight:600;">
                            {{ ___('Default') }}
                        </button>
                        <button class="au-sort-btn" type="button" data-sort="price-asc" style="font-size:12px;padding:5px 12px;border-radius:20px;border:1px solid var(--au-border,#2a2a3a);background:transparent;color:inherit;cursor:pointer;">
                            {{ ___('Price') }} ↑
                        </button>
                        <button class="au-sort-btn" type="button" data-sort="price-desc" style="font-size:12px;padding:5px 12px;border-radius:20px;border:1px solid var(--au-border,#2a2a3a);background:transparent;color:inherit;cursor:pointer;">
                            {{ ___('Price') }} ↓
                        </button>
                        <button class="au-sort-btn" type="button" data-sort="popular" style="font-size:12px;padding:5px 12px;border-radius:20px;border:1px solid var(--au-border,#2a2a3a);background:transparent;color:inherit;cursor:pointer;">
                            <i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ ___('Popular') }}
                        </button>
                    </div>
                </div>
            @elseif($sect['id'] === 'daily_specials')
                {{-- #31: Today's Daily Specials rail --}}
                @php $dailySpecials = \Juwri\Core\Support\ViewHelpers::flagged($items, 'is_daily_special'); @endphp
                @if($dailySpecials->count() > 0)
                <section class="au-section" id="au-daily-specials">
                    <div class="au-wrap">
                        <div class="au-section-head">
                            <div>
                                <span class="au-kicker">⭐ {{ ___("Today's Specials") }}</span>
                                <h2>{{ ___("Chef's Daily Selection") }}</h2>
                            </div>
                        </div>
                        <div class="au-rail" tabindex="0" role="region" aria-label="{{ ___("Chef's Daily Selection") }}">
                            @foreach($dailySpecials->take(8) as $sp)
                                <div class="au-spotlight" @if(!$sp->is_sold_out) data-open-item="{{ $sp->id }}" @else style="opacity: 0.7;" @endif
                                     style="border:1.5px solid rgba(167,139,250,.35);">
                                    @if((string) (media_url('storage/menu/', $sp->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $sp->image, $store->id ?? null) }}" alt="{{ $sp->name }}" loading="lazy"
                                         data-csp-error="opacity">@endif
                                    <div class="au-spotlight-body">
                                        <span class="au-rank" style="background:rgba(167,139,250,.2);color:#a78bfa;">⭐ {{ ___('Today Only') }}</span>
                                        <h4>{{ $sp->name }}</h4>
                                        <div class="au-spotlight-foot">
                                            <span class="au-price">{{ price_symbol_format($sp->price) }}</span>
                                            @if(!empty($sp->calories))
                                                <span style="font-size:11px;color:var(--au-muted);">{{ $sp->calories }} kcal</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
                @endif
            @elseif($sect['id'] === 'specials')
                {{-- ============================================== CHEF'S SPECIALS RAIL --}}
                @if($specials->count() > 2)
                    <section class="au-section" id="au-specials">
                        <div class="au-wrap">
                            <div class="au-section-head">
                                <div>
                                    <span class="au-kicker">{{ ___('Handpicked') }}</span>
                                    <h2 data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___("Chef's Specials")) }}</h2>
                                </div>
                            </div>
                            <div class="au-rail" tabindex="0" role="region" aria-label="{{ theme_setting($store->id, 'texts.featured_title', ___("Chef's Specials")) }}">
                                @foreach($specials as $rank => $sp)
                                    <div class="au-spotlight" @if(!$sp->is_sold_out) data-open-item="{{ $sp->id }}" @else style="opacity: 0.7;" @endif>
                                        @if($sp->is_sold_out)
                                            <span class="au-badge" style="position:absolute;top:10px;right:10px;background:#ff4757;color:#fff;z-index:10;font-size:10px;padding:3px 6px;border-radius:10px;"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i> {{ ___('Sold Out') }}</span>
                                        @endif
                                        @if((string) (media_url('storage/menu/', $sp->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $sp->image, $store->id ?? null) }}" alt="{{ $sp->name }}" loading="lazy"
                                             data-csp-error="opacity">@endif
                                        <div class="au-spotlight-body">
                                            <span class="au-rank">#{{ $rank + 1 }} {{ ___('Popular') }}</span>
                                            <h4>{{ $sp->name }}</h4>
                                            <div class="au-spotlight-foot">
                                                <span class="au-price">{{ price_symbol_format($sp->price) }}</span>
                                                <span class="au-add is-ghost" style="padding:7px 12px"><i class="ico ico-arrow-right" aria-hidden="true"><x-ui.icon name="arrow-right" /></i></span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif
            @elseif($sect['id'] === 'menu')
                {{-- ============================================================ MENU --}}
                <div class="au-wrap" id="au-menu-root">
                    {{-- search + dietary filters, relocated here from the hero so a query
                         or filter changes the food directly below, in view. --}}
                    @if(theme_setting($store->id, 'search.enabled', true))
                    <div class="au-search-wrapper" style="display:flex;align-items:center;gap:12px;max-width:none;margin-bottom:18px">
                        <div class="au-search" style="flex:1;max-width:none">
                            <i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i>
                            <input aria-label="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dishes or ingredients')) }}" type="text" id="au-search-input" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock" data-tc-text="texts.search_placeholder"
                                   placeholder="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dishes or ingredients')) }}">
                            <button class="au-search-clear" type="button" id="au-search-clear" aria-label="{{ ___('Clear') }}">
                                <i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i>
                            </button>
                        </div>
                        @if(!isset($postOptions->allow_dietary_filters) || @$postOptions->allow_dietary_filters == 1)
                        <button class="au-filters-toggle" type="button" id="au-filters-toggle" aria-label="{{ ___('Filters') }}">
                            <i class="ico ico-adjustments-horizontal" aria-hidden="true"><x-ui.icon name="adjustments-horizontal" /></i>
                        </button>
                        @endif
                    </div>
                    @endif
                    @if(!isset($postOptions->allow_dietary_filters) || @$postOptions->allow_dietary_filters == 1)
                    <div class="au-filters-panel" id="au-filters-panel">
                        <div class="au-filters-grid">
                            <div>
                                <h4>{{ ___('Dietary Needs') }}</h4>
                                <div class="au-filters-chips">
                                    <button class="au-filter-chip" type="button" data-filter="vegan">
                                        <i class="ico ico-leaf" aria-hidden="true"><x-ui.icon name="leaf" /></i> {{ ___('Vegan') }}
                                    </button>
                                    <button class="au-filter-chip" type="button" data-filter="halal">
                                        <i class="ico ico-rosette-discount-check" aria-hidden="true"><x-ui.icon name="rosette-discount-check" /></i> {{ ___('Halal') }}
                                    </button>
                                    <button class="au-filter-chip" type="button" data-filter="gf">
                                        <i class="ico ico-wheat" aria-hidden="true"><x-ui.icon name="wheat" /></i> {{ ___('Gluten Free') }}
                                    </button>
                                    <button class="au-filter-chip" type="button" data-filter="popular">
                                        <i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ ___('Popular') }}
                                    </button>
                                </div>
                            </div>
                            <div>
                                <h4>{{ ___('Spice Level') }}</h4>
                                <div class="au-filters-chips">
                                    <button class="au-filter-chip" type="button" data-filter="spice-0">
                                        {{ ___('Mild / None') }}
                                    </button>
                                    <button class="au-filter-chip" type="button" data-filter="spice-1">
                                        🌶️
                                    </button>
                                    <button class="au-filter-chip" type="button" data-filter="spice-2">
                                        🌶️🌶️
                                    </button>
                                    <button class="au-filter-chip" type="button" data-filter="spice-3">
                                        🌶️🌶️🌶️
                                    </button>
                                </div>
                            </div>
                            @if(count($allergies) > 0)
                            <div>
                                <h4>{{ ___('Exclude Allergens') }}</h4>
                                <div class="au-filters-chips">
                                    @foreach($allergies as $al)
                                        <button class="au-filter-chip allergen-exclude-chip" type="button" data-allergen-id="{{ $al->id }}">
                                            {{ !empty($al->translations->{get_lang()}->title) ? $al->translations->{get_lang()}->title : $al->title }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </div>
                        <div class="au-filters-foot">
                            <button class="au-filter-clear-btn" type="button" id="au-filter-clear">
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
                            <section class="au-section au-cat-section" id="cat-{{ $cat->id }}" data-cat="{{ $cat->id }}">
                                <div class="au-section-head">
                                    <div>
                                        <span class="au-kicker" data-tc-text="texts.menu_title">{{ theme_setting($store->id, 'texts.menu_title', ___('Menu')) }}</span>
                                        <h2>{{ $cat->name }}</h2>
                                    </div>
                                </div>

                                @if($direct->count() > 0)
                                    {{-- data-subcat="root" = the parent's own direct items --}}
                                    <div class="au-subwrap" data-subcat="root">
                                        <div class="au-grid">
                                            @foreach($direct as $i => $menu)
                                                @include('store_templates.aurora.includes.dish-card', ['menu' => $menu, 'cardIndex' => $i])
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @foreach($subcats as $sub)
                                    @php $subItems = $byCat[$sub->id] ?? collect(); @endphp
                                    @if($subItems->count() > 0)
                                        {{-- head + grid share one wrapper so sub-chip narrowing /
                                             empty-hiding drop the label together with its cards --}}
                                        <div class="au-subwrap" data-subcat="{{ $sub->id }}">
                                            <div class="au-section-head" style="margin-top:28px">
                                                <div><h2 style="font-size:20px">{{ $sub->name }}</h2></div>
                                            </div>
                                            <div class="au-grid">
                                                @foreach($subItems as $i => $menu)
                                                    @include('store_templates.aurora.includes.dish-card', ['menu' => $menu, 'cardIndex' => $i])
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </section>
                        @endif
                    @endforeach

                    {{-- no-results state for search --}}
                    <div class="au-empty" id="au-empty">
                        <div class="au-empty-ico"><i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i></div>
                        <h3>{{ ___('No dishes found') }}</h3>
                        <p>{{ ___('Try a different search or category.') }}</p>
                    </div>
                </div>
            @endif
        @endif
    @endforeach

        @if($items->count() === 0)
            <div class="au-empty show">
                <div class="au-empty-ico"><i class="ico ico-dine-in" aria-hidden="true"><x-ui.icon name="dine-in" /></i></div>
                <h3>{{ ___('Menu coming soon') }}</h3>
                <p>{{ ___('This restaurant has not added any dishes yet.') }}</p>
            </div>
        @endif

    {{-- #30: Call Waiter / Request Bill --}}
    @if(@$postOptions->allow_call_waiter)
    <div class="au-wrap" style="padding-top:0;padding-bottom:24px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" id="au-call-waiter-btn"
                style="flex:1;min-width:120px;padding:12px 18px;border-radius:14px;border:1.5px solid var(--au-fx);background:transparent;color:var(--au-fx);font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                <i class="ico ico-bell" aria-hidden="true"><x-ui.icon name="bell" /></i> {{ ___('Call Waiter') }}
            </button>
            <button type="button" id="au-request-bill-btn"
                style="flex:1;min-width:120px;padding:12px 18px;border-radius:14px;border:1.5px solid var(--au-veg);background:transparent;color:var(--au-veg);font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                <i class="ico ico-receipt" aria-hidden="true"><x-ui.icon name="receipt" /></i> {{ ___('Request Bill') }}
            </button>
        </div>
    </div>

    {{-- Call Waiter modal sheet --}}
    <div class="au-scrim" id="au-waiter-scrim"></div>
    <aside class="au-sheet" id="au-waiter-sheet" aria-hidden="true">
        <div class="au-sheet-head">
            <h3 id="au-waiter-title">{{ ___('Call Waiter') }}</h3>
            <button class="au-close" type="button" data-close-sheet="waiter" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body">
            <div id="au-waiter-success" style="display:none;text-align:center;padding:30px 0;">
                <div style="font-size:48px;margin-bottom:12px;">✅</div>
                <h3>{{ ___('Request sent!') }}</h3>
                <p id="au-waiter-sent-label" style="color:var(--au-muted);margin-top:6px;"></p>
            </div>
            <form id="au-waiter-form">
                <input type="hidden" id="au-waiter-type-input" value="call_waiter">
                <p id="au-waiter-context-label" style="color:var(--au-muted);margin-bottom:18px;">{{ ___('We will send a waiter to your table right away.') }}</p>
                <div class="au-field">
                    <label>{{ ___('Table Number') }}</label>
                    <input aria-label="{{ ___('Table Number') }}" type="text" id="au-waiter-table" name="table"
                        value="{{ $table->table_no ?? '' }}"
                        {{ $table ? 'readonly' : '' }}
                        placeholder="{{ ___('Your table number') }}" required>
                </div>
                <div class="au-field">
                    <label>{{ ___('Note') }} ({{ ___('optional') }})</label>
                    <textarea aria-label="{{ ___('Any special note?') }}" name="message" placeholder="{{ ___('Any special note?') }}"></textarea>
                </div>
                <button type="submit" class="au-cta">
                    <i class="ico ico-send" aria-hidden="true"><x-ui.icon name="send" /></i> {{ ___('Send Request') }}
                </button>
            </form>
        </div>
    </aside>
    @endif

    {{-- #22: Guest order history lookup --}}
    <div class="au-wrap" style="padding-top:0;padding-bottom:28px;">
        <details style="background:var(--au-glass);border:1px solid var(--au-border);border-radius:14px;padding:16px;">
            <summary style="cursor:pointer;font-weight:600;font-size:14px;list-style:none;display:flex;align-items:center;gap:8px;">
                <i class="ico ico-history" style="color:var(--au-fx)" aria-hidden="true"><x-ui.icon name="history" /></i> {{ ___('Track my previous orders') }}
            </summary>
            <div style="margin-top:16px;">
                {{-- R5 · guest order history (localStorage); populated by script.js
                     on order-success and at boot. Sits above the server lookup so a
                     phone/email lookup never clobbers the remembered list. --}}
                <div id="au-recent-orders" hidden style="margin-bottom:14px;"></div>
                @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
                <p style="margin:0 0 10px;font-size:12px;color:var(--au-muted);">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" type="text" id="au-order-lookup-input" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}"
                           style="flex:1;min-width:180px;background:var(--au-input-bg);border:1px solid var(--au-border-hi);border-radius:10px;color:inherit;padding:10px 14px;">
                    <button type="button" id="au-order-lookup-btn" class="au-cta" style="padding:10px 18px;font-size:13px;flex-shrink:0;">
                        <i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i> {{ ___('Look up') }}
                    </button>
                </div>
                <div id="au-order-lookup-results" style="margin-top:14px;"></div>
            </div>
        </details>
    </div>

    {{-- ========================================================== FOOTER --}}
    <footer class="au-foot">
        <div class="au-wrap">
            <div style="font-family:var(--au-display);font-weight:700;color:var(--au-text);font-size:18px;margin-bottom:8px">
                {{ $store->title }}
            </div>
            @if($store->address)<div>{{ $store->address }}</div>@endif
            {{-- Customizer: optional footer note ("Thanks for visiting"). Renders
                 nothing when the owner has not set one, so uncustomized stores
                 look exactly as before. --}}
            @php
                $auFooterNote = (string) theme_setting($store->id, 'texts.footer_note', '');
            @endphp
            @if($auFooterNote !== '')<div style="margin-top:6px" data-tc-text="texts.footer_note">{{ $auFooterNote }}</div>@endif
            @if(! store_plan_allows($store, 'hide_branding'))
                <div style="margin-top:14px;opacity:.7">{{ ___('Powered by') }} <a href="{{ route('home') }}" style="color:inherit">{{ $settings->site_title }}</a></div>
            @endif
        </div>
    </footer>

    {{-- =================================================== STICKY CART BAR --}}
    @if($allow_order)
        <div class="au-cartbar" id="au-cartbar">
            <span class="cb-ico"><i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i></span>
            <div class="cb-text"><span id="au-cartbar-count">{{ ___n('item', 0) }}</span><small data-tc-text="texts.cart_button">{{ theme_setting($store->id, 'texts.cart_button', ___('View your order')) }}</small></div>
            <span class="cb-total" id="au-cartbar-total"></span>
        </div>

        {{-- =================================================== RESUME ORDER BANNER --}}
        <div class="au-resume-banner" id="au-resume-banner" style="display:none">
            <div class="au-resume-content">
                <i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i>
                <span id="au-resume-text">{{ ___('You have items in your cart from your last visit.') }}</span>
            </div>
            <div class="au-resume-actions">
                <button class="au-resume-btn" id="au-resume-btn" type="button">{{ ___('Resume') }}</button>
                <button class="au-resume-clear-btn" id="au-resume-clear-btn" type="button">{{ ___('Clear') }}</button>
            </div>
        </div>
    @endif

    {{-- ====================================================== ITEM SHEET --}}
    <div class="au-scrim" id="au-item-scrim"></div>
    <aside class="au-sheet" id="au-item-sheet" aria-hidden="true">
        <div class="au-sheet-head">
            <h3>{{ ___('Dish details') }}</h3>
            <button class="au-close" type="button" data-close-sheet="item" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body" id="au-item-body"></div>
        @if($allow_order)
            <div class="au-sheet-foot" id="au-item-foot"></div>
        @endif
    </aside>

    {{-- ====================================================== CART SHEET --}}
    @if($allow_order)
        <div class="au-scrim" id="au-cart-scrim"></div>
        <aside class="au-sheet" id="au-cart-sheet" aria-hidden="true">
            <div class="au-sheet-head">
                <h3>{{ ___('Your order') }}</h3>
                <button class="au-close" type="button" data-close-sheet="cart" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
            </div>
            <div class="au-sheet-body">
                <div id="au-cart-list"></div>

                <div class="au-cart-empty" id="au-cart-empty">
                    <div class="au-empty-ico"><i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i></div>
                    <h3 style="color:var(--au-text);margin-bottom:6px">{{ ___('Your cart is empty') }}</h3>
                    <p>{{ ___('Add a few dishes to get started.') }}</p>
                </div>

                <form id="au-order-form" action="{{ route('store.submitOrder', $store->id) }}" method="post" style="display:none">
                    @csrf
                    <label class="wa-optin" style="display:flex;align-items:center;gap:8px;margin:10px 0;font-size:.9em;">
                        <input type="checkbox" name="whatsapp_optin" value="1">
                        <span>{{ ___('Send me offers and updates on WhatsApp') }}</span>
                    </label>
                    <div class="au-totals" id="au-totals"></div>

                    {{-- F1 · turn this cart into a recurring meal plan --}}
                    @if($mealPlansEnabled)
                    <button class="au-mealplan-cta" id="au-mealplan-cta" type="button">
                        <span class="au-mp-ic"><i class="ico ico-calendar" aria-hidden="true"><x-ui.icon name="calendar" /></i></span>
                        <span class="au-mp-copy"><b>{{ ___('Make this a meal plan') }}</b><small>{{ ___('Get these dishes delivered on a schedule') }}</small></span>
                        <i class="ico ico-chevron au-mp-arr" aria-hidden="true"><x-ui.icon name="chevron" /></i>
                    </button>
                    @endif

                    @if(@$postOptions->allow_coupons == 1)
                    <div class="au-field">
                        <label>{{ ___('Promo Code') }}</label>
                        <div class="au-coupon-row">
                            <input aria-label="{{ ___('Enter promo code') }}" type="text" id="au-coupon-code" name="coupon_code" placeholder="{{ ___('Enter promo code') }}" style="flex:1; margin-bottom:0;">
                            <button type="button" id="au-apply-coupon" class="au-coupon-btn">{{ ___('Apply') }}</button>
                        </div>
                        <div id="au-coupon-msg" class="au-coupon-msg" style="display:none"></div>
                    </div>
                    @endif

                    @if(!empty($postOptions->gift_cards_enabled))
                    <div class="au-field">
                        <label>{{ ___('Gift card code') }}</label>
                        <div class="au-coupon-row">
                            <input aria-label="{{ ___('Enter gift card code') }}" type="text" id="au-gift-card-code" name="gift_card_code" placeholder="{{ ___('Enter gift card code') }}" style="flex:1; margin-bottom:0;">
                        </div>
                    </div>
                    @endif

                    @if($customer && $storeCredit > 0)
                    <label class="wa-optin" style="display:flex;align-items:center;gap:8px;margin:10px 0;font-size:.9em;">
                        <input type="checkbox" name="use_wallet" value="1" id="mbUseWallet">
                        <span>{{ ___('Pay with store credit') }} ({{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }})</span>
                    </label>
                    @endif

                    <div class="au-form-error" id="au-form-error"></div>

                    <div class="au-field">
                        <label>{{ ___('Order type') }}</label>
                        <div class="au-seg" id="au-ordertype-seg">
                            @if($allow_on_table)<button type="button" data-type="on-table">{{ ___('On table') }}</button>@endif
                            @if($allow_takeaway)<button type="button" data-type="takeaway" data-tc-text="texts.pickup">{{ theme_setting($store->id, 'texts.pickup', ___('Takeaway')) }}</button>@endif
                            @if($allow_delivery)<button type="button" data-type="delivery" data-tc-text="texts.delivery">{{ theme_setting($store->id, 'texts.delivery', ___('Delivery')) }}</button>@endif
                            {{-- T1/H2 hotel room service. $allow_room_service is 1 only when hotel
                                 mode is on AND the scanned QR resolved to an area_type='room' table,
                                 so this button never renders for an ordinary restaurant. --}}
                            @if($allow_room_service)<button type="button" data-type="room_service">{{ ___('Room service') }}</button>@endif
                        </div>
                        <input type="hidden" name="ordering-type" id="au-ordering-type" value="">
                    </div>

                    {{-- #18: Order scheduling (pre-order) --}}
                    @if(!empty($postOptions->allow_order_scheduling))
                    <div class="au-field">
                        <label>{{ ___('Schedule for later') }} <small style="color:var(--au-muted)">({{ ___('optional') }})</small></label>
                        <input aria-label="{{ ___('Schedule for later') }}" type="datetime-local" name="scheduled_at" id="au-scheduled-at"
                               min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}"
                               max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}"
                               style="background:var(--au-glass-2);border:1px solid var(--au-border);border-radius:12px;padding:10px 14px;color:inherit;width:100%;">
                    </div>
                    @endif

                    {{-- T5: Curbside pickup (takeaway) --}}
                    @if(!empty($postOptions->curbside_enabled))
                    <div class="au-field">
                        <label class="wa-optin" style="display:flex;align-items:center;gap:8px;margin:10px 0;font-size:.9em;">
                            <input type="checkbox" name="curbside" value="1" id="au-curbside" data-csp-toggle="au-curbside-fields">
                            <span>{{ ___("Curbside pickup — I'll wait in my car") }}</span>
                        </label>
                        <div id="au-curbside-fields" style="display:none;">
                            <input aria-label="{{ ___('Car make/model') }}" type="text" name="vehicle_make" id="au-vehicle-make" placeholder="{{ ___('Car make/model') }}" style="background:var(--au-glass-2);border:1px solid var(--au-border);border-radius:12px;padding:10px 14px;color:inherit;font-size:14px;width:100%;margin-bottom:8px;">
                            <input aria-label="{{ ___('Colour') }}" type="text" name="vehicle_color" id="au-vehicle-color" placeholder="{{ ___('Colour') }}" style="background:var(--au-glass-2);border:1px solid var(--au-border);border-radius:12px;padding:10px 14px;color:inherit;font-size:14px;width:100%;margin-bottom:8px;">
                            <input aria-label="{{ ___('Plate') }}" type="text" name="vehicle_plate" id="au-vehicle-plate" placeholder="{{ ___('Plate') }}" style="background:var(--au-glass-2);border:1px solid var(--au-border);border-radius:12px;padding:10px 14px;color:inherit;font-size:14px;width:100%;">
                        </div>
                    </div>
                    @endif

                    @if($authMode === 'required' && !$customer)
                        <div class="au-auth-required-notice" style="background: var(--au-card); border: 1px solid var(--au-border); padding: 20px; border-radius: 12px; text-align: center; margin-bottom: 20px;">
                            <i class="ico ico-lock" style="font-size: 24px; color: var(--au-fx); margin-bottom: 10px;" aria-hidden="true"><x-ui.icon name="lock" /></i>
                            <h4 style="margin-bottom: 8px;">{{ ___('Login Required') }}</h4>
                            <p style="color: var(--au-muted); margin-bottom: 16px; font-size: 14px;">{{ ___('You must be logged in to place an order.') }}</p>
                            <button type="button" class="au-cta" id="au-checkout-login-btn" style="padding: 10px;">{{ ___('Login / Register') }}</button>
                        </div>
                        <div id="au-guest-fields" style="display:none;">
                    @else
                        @if($authMode === 'optional' && !$customer)
                            <div class="au-auth-optional-notice" style="background: var(--au-card); border: 1px solid var(--au-border); padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h4 style="font-size: 14px; margin-bottom: 4px;">{{ ___('Have an account?') }}</h4>
                                    <p style="color: var(--au-muted); font-size: 12px; margin: 0;">{{ ___('Log in for faster checkout') }}</p>
                                </div>
                                <button type="button" class="au-btn" id="au-checkout-login-btn" style="background: var(--au-bg); color: var(--au-text); border: 1px solid var(--au-border); padding: 6px 12px; border-radius: 6px; font-weight: 600;">{{ ___('Login') }}</button>
                            </div>
                        @endif
                        <div id="au-guest-fields">
                    @endif

                    @if($customer)
                        <input type="hidden" name="name" value="{{ $customer->name }}">
                        <div class="au-field" data-field="table" style="display:none">
                            <label>{{ ___('Table') }}</label>
                            @if($table)
                                <input aria-label="{{ ___('Table number') }}" type="text" name="table" value="{{ $table->table_no }}" readonly>
                            @else
                                <input aria-label="{{ ___('Table number') }}" type="number" name="table" placeholder="{{ ___('Table number') }}">
                            @endif
                        </div>
                        <div class="au-field" data-field="phone" style="display:none">
                            <label>{{ ___('Phone number') }}</label>
                            <input aria-label="{{ ___('Phone number') }}" type="tel" name="phone-number" value="{{ $customer->phone }}" placeholder="{{ ___('Phone number') }}">
                        </div>
                        <div class="au-field" data-field="address" style="display:none">
                            <label>{{ ___('Delivery address') }}</label>
                            {{-- Saved addresses (logged-in): tap to fill the field + coords. --}}
                            @if($customerAddresses->count())
                            <div class="au-saved-addrs" id="au-saved-addrs">
                                @foreach($customerAddresses as $sa)
                                <button class="au-saved-addr{{ $loop->first ? ' is-on' : '' }}" type="button"
                                        data-addr="{{ $addrLabel($sa) }}" data-lat="{{ $sa->lat }}" data-lng="{{ $sa->lng }}">
                                    <i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i>
                                    <span class="sa-text"><b>{{ $sa->label ?: ___('Saved address') }}</b><small>{{ Str::limit($addrLabel($sa), 42) }}</small></span>
                                </button>
                                @endforeach
                            </div>
                            @endif
                            {{-- F5 · address autocomplete + use-my-location --}}
                            <div class="au-addr-field" id="au-addr-field">
                                <textarea aria-label="{{ ___('Delivery address') }}" name="address" id="au-cust-addr" placeholder="{{ ___('Delivery address') }}" autocomplete="off" autocorrect="off" spellcheck="false">{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}</textarea>
                                <input type="hidden" id="au-cust-lat" name="address_lat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                                <input type="hidden" id="au-cust-lng" name="address_lng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                                <button class="au-useloc-btn" id="au-use-location" type="button" title="{{ ___('Use my location') }}"><i class="ico ico-location" aria-hidden="true"><x-ui.icon name="location" /></i></button>
                                <div class="au-addr-suggest" id="au-addr-suggest" hidden></div>
                            </div>
                            <p class="au-geo-attrib" id="au-geo-attrib" hidden></p>
                            {{-- Drop-a-pin address picker (shared window.mbMapPicker engine, aurora-skinned drawer below). --}}
                            <button class="au-nearest-branch-btn au-map-trigger" id="au-addr-map-btn" type="button"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i> {{ ___('Choose on map') }}</button>
                            @if($showBranches)
                            <button class="au-nearest-branch-btn" id="au-nearest-branch-btn" type="button"><i class="ico ico-map-2" aria-hidden="true"><x-ui.icon name="map-2" /></i> {{ ___('Find nearest branch on map') }}</button>
                            @endif
                        </div>
                    @else
                        <div class="au-field">
                            <label>{{ ___('Your Name') }}</label>
                            <input aria-label="{{ ___('Your Name') }}" type="text" name="name" required placeholder="{{ ___('Your Name') }}">
                        </div>

                        <div class="au-field" data-field="table" style="display:none">
                            <label>{{ ___('Table') }}</label>
                            @if($table)
                                <input aria-label="{{ ___('Table number') }}" type="text" name="table" value="{{ $table->table_no }}" readonly>
                            @else
                                <input aria-label="{{ ___('Table number') }}" type="number" name="table" placeholder="{{ ___('Table number') }}">
                            @endif
                        </div>

                        <div class="au-field" data-field="phone" style="display:none">
                            <label>{{ ___('Phone number') }}</label>
                            <input aria-label="{{ ___('Phone number') }}" type="tel" name="phone-number" placeholder="{{ ___('Phone number') }}">
                        </div>

                        <div class="au-field" data-field="address" style="display:none">
                            <label>{{ ___('Delivery address') }}</label>
                            {{-- R6 · guest saved addresses (localStorage). Rendered by
                                 script.js into the SAME spot logged-in diners see their
                                 server address book; guests only (gated on !isLoggedIn). --}}
                            <div class="au-saved-addrs" id="au-saved-addrs" hidden></div>
                            {{-- F5 · address autocomplete + use-my-location --}}
                            <div class="au-addr-field" id="au-addr-field">
                                <textarea aria-label="{{ ___('Delivery address') }}" name="address" id="au-cust-addr" placeholder="{{ ___('Delivery address') }}" autocomplete="off" autocorrect="off" spellcheck="false"></textarea>
                                <input type="hidden" id="au-cust-lat" name="address_lat">
                                <input type="hidden" id="au-cust-lng" name="address_lng">
                                <button class="au-useloc-btn" id="au-use-location" type="button" title="{{ ___('Use my location') }}"><i class="ico ico-location" aria-hidden="true"><x-ui.icon name="location" /></i></button>
                                <div class="au-addr-suggest" id="au-addr-suggest" hidden></div>
                            </div>
                            <p class="au-geo-attrib" id="au-geo-attrib" hidden></p>
                            {{-- Drop-a-pin address picker (shared window.mbMapPicker engine, aurora-skinned drawer below). --}}
                            <button class="au-nearest-branch-btn au-map-trigger" id="au-addr-map-btn" type="button"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i> {{ ___('Choose on map') }}</button>
                            @if($showBranches)
                            <button class="au-nearest-branch-btn" id="au-nearest-branch-btn" type="button"><i class="ico ico-map-2" aria-hidden="true"><x-ui.icon name="map-2" /></i> {{ ___('Find nearest branch on map') }}</button>
                            @endif
                        </div>
                    @endif
                    </div>

                    @if($allow_payment)
                        <div class="au-field">
                            <label>{{ ___('Pay Via') }}</label>
                            <select aria-label="{{ ___('Pay Via') }}" name="pay_via" id="au-payvia" required>
                                <option value="pay_on_counter">{{ ___('Pay On Counter') }}</option>
                                <option value="pay_online">{{ ___('Pay Online') }}</option>
                            </select>
                        </div>
                    @else
                        <input type="hidden" name="pay_via" value="pay_on_counter">
                    @endif

                    @if(@$postOptions->allow_tips == 1)
                    <div class="au-field">
                        <label>{{ ___('Tip') }} ({{ ___('optional') }})</label>
                        <div class="au-tip-options" style="display:flex;gap:8px;margin-bottom:8px;">
                            <button type="button" class="au-tip-btn" data-tip-pct="10">10%</button>
                            <button type="button" class="au-tip-btn" data-tip-pct="15">15%</button>
                            <button type="button" class="au-tip-btn" data-tip-pct="20">20%</button>
                            <button type="button" class="au-tip-btn" data-tip-pct="custom">{{ ___('Custom') }}</button>
                        </div>
                        <input aria-label="{{ ___('Custom tip amount') }}" type="number" id="au-custom-tip" placeholder="{{ ___('Custom tip amount') }}" style="display:none;margin-bottom:0;" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}">
                        <input type="hidden" name="tip_amount" id="au-tip-amount" value="0">
                    </div>
                    @endif

                    <div class="au-field">
                        <label>{{ ___('Note') }} ({{ ___('optional') }})</label>
                        <textarea aria-label="{{ ___('Any special requests?') }}" name="message" placeholder="{{ ___('Any special requests?') }}"></textarea>
                        @include('store_templates._partials.voice-note-recorder')
                    </div>

                    {{-- #56: Customer capture — explicit opt-in consent --}}
                    @if(!$customer)
                    <div class="au-field" style="margin-top:4px;">
                        <div class="au-field" style="display:none" id="au-capture-email-wrap">
                            <label>{{ ___('Email') }} ({{ ___('optional') }})</label>
                            <input aria-label="{{ ___('your@email.com') }}" type="email" name="capture_email" id="au-capture-email" placeholder="{{ ___('your@email.com') }}" autocomplete="email">
                        </div>
                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;color:var(--au-muted);font-weight:400;margin-top:4px;">
                            <input type="checkbox" name="marketing_consent" value="1" id="au-consent-cb"
                                   style="width:16px;height:16px;flex-shrink:0;margin-top:2px;accent-color:var(--au-fx);"
                                   data-csp-toggle="au-capture-email-wrap">
                            <span>{{ ___('Send me offers and updates from this restaurant') }}
                                <a href="{{ route('publicView.unsubscribe.info', $store->slug) }}" target="_blank" style="color:var(--au-fx);font-size:11px;margin-left:4px;">{{ ___('Privacy') }}</a>
                            </span>
                        </label>
                    </div>
                    @endif
                </form>

                <div class="au-success" id="au-order-success">
                    <div class="au-ok"><i class="ico ico-check" aria-hidden="true"><x-ui.icon name="check" /></i></div>
                    <h3>{{ ___('Order placed!') }}</h3>
                    <p style="color:var(--au-muted);margin-top:6px">{{ ___('Thank you. Your order is on its way.') }}</p>
                    {{-- The order number the customer quotes / sees on the track page.
                         submitOrder already returns res.order_id; filled in by JS. --}}
                    <p class="au-order-no" id="au-order-no" hidden></p>
                    {{-- #60: Google Review prompt --}}
                    @if(!empty($postOptions->google_review_url))
                    <div style="margin-top:22px;padding:18px;background:rgba(245,181,74,.08);border:1px solid rgba(245,181,74,.25);border-radius:14px;text-align:center;">
                        <div style="font-size:22px;margin-bottom:6px;">⭐</div>
                        <p style="font-weight:600;color:var(--au-text);margin-bottom:10px;">{{ ___('Enjoying your meal? Leave us a review!') }}</p>
                        <a href="{{ $postOptions->google_review_url }}" target="_blank" rel="noopener"
                            style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#f5b54a;color:#12121a;border-radius:10px;font-weight:700;text-decoration:none;font-size:14px;">
                            <i class="ico ico-brand-google" aria-hidden="true"><x-ui.icon name="brand-google" /></i> {{ ___('Rate us on Google') }}
                        </a>
                    </div>
                    @endif
                    {{-- #57: Loyalty stamp card --}}
                    @php $loyaltyProgram = storefront()->loyaltyProgram($store); @endphp
                    @if($loyaltyProgram && $customer)
                    <div id="au-loyalty-block" style="margin-top:22px;padding:18px;background:var(--au-glass);border:1px solid var(--au-border);border-radius:14px;">
                        <div style="font-weight:700;font-size:14px;margin-bottom:10px;text-align:center">
                            <i class="ico ico-award" style="color:var(--au-fx);margin-right:4px" aria-hidden="true"><x-ui.icon name="award" /></i> {{ ___('Your Loyalty Balance') }}
                        </div>
                        <div id="au-loyalty-content" style="text-align:center">
                            <div id="au-loyalty-balance" style="font-size:28px;font-weight:900;color:var(--au-fx)">–</div>
                            <div style="font-size:12px;color:var(--au-muted);margin-bottom:10px">
                                {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }} / {{ $loyaltyProgram->reward_threshold }} {{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }}
                            </div>
                            {{-- stamp dots for stamps type --}}
                            @if($loyaltyProgram->type === 'stamps')
                            <div id="au-stamp-dots" style="display:flex;flex-wrap:wrap;justify-content:center;gap:6px;margin-bottom:12px">
                                @for($s=0; $s<$loyaltyProgram->reward_threshold; $s++)
                                    <div class="au-stamp-dot" data-idx="{{ $s }}"
                                        style="width:28px;height:28px;border-radius:50%;border:2px solid var(--au-border-hi);display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--au-faint)">
                                        <i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i>
                                    </div>
                                @endfor
                            </div>
                            @else
                            {{-- progress bar for points --}}
                            <div style="height:8px;background:var(--au-glass-hi);border-radius:99px;overflow:hidden;margin-bottom:12px">
                                <div id="au-loyalty-bar" style="height:100%;width:0%;background:var(--au-fx);border-radius:99px;transition:width .6s ease"></div>
                            </div>
                            @endif
                            <button id="au-loyalty-redeem-btn" type="button" class="au-cta" style="display:none;font-size:13px;padding:8px 20px;background:var(--au-fx)">
                                <i class="ico ico-gift" aria-hidden="true"><x-ui.icon name="gift" /></i> {{ ___('Redeem Reward') }}
                            </button>
                            <div id="au-loyalty-redeem-ok" style="display:none;color:#22c55e;font-weight:700;font-size:13px">{{ ___('Reward redeemed! Enjoy your reward.') }}</div>
                        </div>
                    </div>
                    @endif

                    {{-- #62: NPS survey --}}
                    <div id="au-nps-block" style="margin-top:22px;padding:18px;background:var(--au-glass);border:1px solid var(--au-border);border-radius:14px;">
                        <p style="font-weight:600;margin-bottom:12px;text-align:center;">{{ ___('How likely are you to recommend us?') }}</p>
                        <div style="display:flex;justify-content:center;gap:4px;flex-wrap:wrap;" id="au-nps-scores">
                            @for($n=0;$n<=10;$n++)
                            <button type="button" data-nps="{{ $n }}" class="au-nps-btn"
                                style="min-width:32px;height:32px;border-radius:8px;border:1px solid var(--au-border-hi);background:var(--au-glass);color:inherit;cursor:pointer;font-size:13px;font-weight:600;">{{ $n }}</button>
                            @endfor
                        </div>
                        <p style="display:flex;justify-content:space-between;font-size:11px;color:var(--au-muted);margin-top:6px;"><span>{{ ___('Not likely') }}</span><span>{{ ___('Very likely') }}</span></p>
                    </div>
                </div>
                {{-- Privacy utility: wipe the cart / favourites / saved addresses this
                     browser keeps (shared window.mbClearDeviceData engine). Lives in the
                     always-reachable cart drawer since aurora has no guest settings surface. --}}
                <section class="au-device-data">
                    <button class="au-linkbtn au-cleardata" id="au-clear-data" type="button">
                        <i class="ico ico-trash" aria-hidden="true"><x-ui.icon name="trash" /></i> {{ ___('Clear saved data on this device') }}
                    </button>
                    <p class="au-device-data-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
                </section>
            </div>
            <div class="au-sheet-foot" id="au-cart-foot" style="display:none">
                @if($authMode === 'required' && !$customer)
                    <button type="button" class="au-cta" style="opacity: 0.5; cursor: not-allowed;">
                        <i class="ico ico-lock" aria-hidden="true"><x-ui.icon name="lock" /></i> {{ ___('Login Required') }}
                        <span class="au-cta-price" id="au-checkout-total"></span>
                    </button>
                @else
                    <button type="submit" form="au-order-form" class="au-cta" id="au-checkout-btn">
                        <i class="ico ico-bolt" aria-hidden="true"><x-ui.icon name="bolt" /></i> <span data-tc-text="texts.checkout_button">{{ theme_setting($store->id, 'texts.checkout_button', ___('Place order')) }}</span>
                        <span class="au-cta-price" id="au-checkout-total"></span>
                    </button>
                @endif
            </div>
        </aside>

        {{-- ============================================== ADDRESS MAP PICKER SHEET
             Aurora-native right-edge drawer (.au-sheet) driven by the shared
             window.mbMapPicker engine; fills the same #au-cust-addr the checkout
             submits, plus the hidden #au-cust-lat / #au-cust-lng coords. --}}
        <div class="au-scrim au-map-scrim" id="au-map-scrim"></div>
        <aside class="au-sheet au-map-sheet" id="au-map-sheet" aria-hidden="true" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}">
            <div class="au-sheet-head">
                <h3>{{ ___('Choose on map') }}</h3>
                <button class="au-close" type="button" id="au-map-close" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
            </div>
            <div class="au-sheet-body">
                <div class="au-map-canvas" id="au-map-canvas"></div>
                <p class="au-map-preview" id="au-map-preview">{{ ___('Move the map to place the pin') }}</p>
            </div>
            <div class="au-sheet-foot">
                <button class="au-cta" id="au-map-confirm" type="button"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i> {{ ___('Use this location') }}</button>
            </div>
        </aside>
    @endif

    {{-- ====================================================== #81: WAITLIST SHEET --}}
    @php
        $waitlistEnabled = !empty($postOptions->enable_waitlist);
        $myWaitlist = storefront()->activeWaitlist($store, $customer);
    @endphp
    @if($waitlistEnabled)
    {{-- Positioning/skin live in style.css (.au-fab): pinned with LOGICAL insets so
         the pair mirrors in Arabic, and lifted above the cart bar / resume prompt
         and clear of the shared WhatsApp bubble. As inline styles they could do
         none of that. --}}
    <button id="au-waitlist-fab" class="au-fab au-fab--ghost" type="button">
        <i class="ico ico-users" aria-hidden="true"><x-ui.icon name="users" /></i> {{ ___('Join Waitlist') }}
    </button>
    <aside class="au-sheet" id="au-waitlist-sheet" aria-hidden="true" style="z-index:1000003">
        <div class="au-sheet-head">
            <h3>{{ ___('Join the Waitlist') }}</h3>
            <button class="au-close" type="button" data-close-sheet="waitlist" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body">
            @if($myWaitlist && !session('waitlist_joined'))
                <div style="background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.3);border-radius:12px;padding:12px 14px;margin-bottom:14px;text-align:center">
                    <b style="color:#22c55e">{{ ___('On the list') }}</b>
                    <div style="color:var(--au-muted);font-size:12.5px;margin-top:2px">{{ ___('Party of :n · joined :time', ['n' => $myWaitlist->party_size, 'time' => \Illuminate\Support\Carbon::parse($myWaitlist->created_at)->diffForHumans()]) }}</div>
                </div>
            @endif
            @if(session('waitlist_joined'))
                <div style="text-align:center;padding:24px 0">
                    <div style="font-size:48px">🎉</div>
                    <h3 style="margin:12px 0 6px">{{ ___("You're on the list!") }}</h3>
                    <p style="color:var(--au-muted)">{{ ___('Position :pos · ~:min min wait', ['pos' => session('waitlist_joined')['position'], 'min' => session('waitlist_joined')['wait_minutes']]) }}</p>
                </div>
            @else
                <form id="au-waitlist-form" action="{{ route('publicView.waitlist.join', $store->slug) }}" method="POST">
                    @csrf
                    <div class="au-field">
                        <label>{{ ___('Your Name') }}</label>
                        <input aria-label="{{ ___('Your Name') }}" type="text" name="name" required maxlength="120">
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Phone') }} <span style="color:var(--au-muted);font-weight:400">({{ ___('optional, for SMS notification') }})</span></label>
                        <input aria-label="{{ ___('Phone') }}" type="tel" name="phone" maxlength="30">
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Party Size') }}</label>
                        <input aria-label="{{ ___('Party Size') }}" type="number" name="party_size" min="1" max="50" value="2" required>
                    </div>
                    <button type="submit" class="au-cta" style="margin-top:10px">{{ ___('Join Waitlist') }}</button>
                </form>
            @endif
        </div>
    </aside>
    @endif

    {{-- ====================================================== #80: RESERVATION SHEET --}}
    @php
        $reservationsEnabled = !empty($postOptions->enable_reservations);
        $myReservation = storefront()->upcomingReservation($store, $customer);
    @endphp
    @if($reservationsEnabled)
    <button id="au-res-fab" class="au-fab au-fab--primary" type="button">
        <i class="ico ico-calendar-plus" aria-hidden="true"><x-ui.icon name="calendar-plus" /></i> {{ ___('Reserve a Table') }}
    </button>
    <aside class="au-sheet" id="au-res-sheet" aria-hidden="true" style="z-index:1000002">
        <div class="au-sheet-head">
            <h3>{{ ___('Reserve a Table') }}</h3>
            <button class="au-close" type="button" data-close-sheet="res" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body">
            @if($myReservation && !session('reservation_success'))
                <div style="background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.35);border-radius:12px;padding:12px 14px;margin-bottom:14px;text-align:center">
                    <b style="color:#818cf8">{{ $myReservation->status === 'confirmed' ? ___('Confirmed') : ___('Requested') }}</b>
                    <div style="color:var(--au-muted);font-size:12.5px;margin-top:2px">{{ ___('Booked for :when', ['when' => \Illuminate\Support\Carbon::parse($myReservation->reserved_at)->format('D, M j · g:i A')]) }}</div>
                </div>
            @endif
            @if(session('reservation_success'))
                <div style="text-align:center;padding:24px 0">
                    <div style="font-size:48px">📅</div>
                    <h3 style="margin:12px 0 6px">{{ ___('Request received!') }}</h3>
                    <p style="color:var(--au-muted)">{{ session('reservation_success') }}</p>
                    <p style="color:var(--au-muted);font-size:13px">{{ ___('We\'ll confirm your booking shortly.') }}</p>
                </div>
            @else
                <form action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                    @csrf
                    <div class="au-field">
                        <label>{{ ___('Your Name') }}</label>
                        <input aria-label="{{ ___('Your Name') }}" type="text" name="name" required maxlength="80">
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Phone') }}</label>
                        <input aria-label="{{ ___('Phone') }}" type="tel" name="phone" maxlength="30">
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Email') }}</label>
                        <input aria-label="{{ ___('Email') }}" type="email" name="email" maxlength="120">
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Date & Time') }}</label>
                        <input aria-label="{{ ___('Date & Time') }}" type="datetime-local" name="reserved_at" required min="{{ now()->format('Y-m-d\TH:i') }}">
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Party Size') }}</label>
                        <input aria-label="{{ ___('Party Size') }}" type="number" name="party_size" min="1" max="50" value="2" required>
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Notes') }} <span style="color:var(--au-muted);font-size:12px">({{ ___('optional') }})</span></label>
                        <textarea aria-label="{{ ___('Notes') }}" name="notes" rows="2" maxlength="500"></textarea>
                    </div>
                    {{-- WAVE4-V2: the hardcoded #6366f1 indigo AURORA-F13 removed from the
                         reserve FAB survived here, so the reservation sheet's submit button
                         was the one off-brand primary on the page. `.au-cta` already carries
                         the accent fill. --}}
                    @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                    <button type="submit" class="au-cta" style="margin-top:10px">{{ ___('Request Reservation') }}</button>
                </form>
            @endif
        </div>
    </aside>
    @endif

    {{-- ====================================================== AUTH SHEET --}}
    @if($authMode !== 'guest_only')
        <aside class="au-sheet" id="au-auth-sheet" aria-hidden="true" style="z-index: 1000002;">
            <div class="au-sheet-head">
                <h3 id="au-auth-title">{{ ___('Login') }}</h3>
                <button class="au-close" type="button" data-close-sheet="auth" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
            </div>
            <div class="au-sheet-body">
                <div class="au-seg" id="au-auth-seg" style="margin-bottom: 20px;">
                    <button type="button" data-tab="login" class="active">{{ ___('Login') }}</button>
                    <button type="button" data-tab="otp">{{ ___('Phone OTP') }}</button>
                    <button type="button" data-tab="register">{{ ___('Register') }}</button>
                </div>

                <div id="au-auth-error" class="au-form-error" style="display:none; margin-bottom: 16px;"></div>

                <form id="au-login-form" style="display:block;">
                    <div class="au-field">
                        <label>{{ ___('Email Address') }}</label>
                        <input aria-label="{{ ___('Email Address') }}" type="email" name="email" required>
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Password') }}</label>
                        <input aria-label="{{ ___('Password') }}" type="password" name="password" required>
                    </div>
                    <button type="submit" class="au-cta" style="margin-top: 10px;">{{ ___('Login') }}</button>
                    @include('store_templates.partials.social-login-buttons', ['store' => $store])
                    @include('store_templates.partials.passkey-login', ['store' => $store])
                </form>

                <form id="au-otp-form" style="display:none;">
                    <div id="au-otp-step1">
                        <div class="au-field">
                            <label>{{ ___('Phone Number') }}</label>
                            <input aria-label="{{ ___('Phone Number') }}" type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="{{ ___('e.g. +123456789') }}">
                        </div>
                        <button type="button" id="au-otp-send" class="au-cta" style="margin-top: 10px;">{{ ___('Send code') }}</button>
                    </div>
                    <div id="au-otp-step2" style="display:none;">
                        <p style="color: var(--au-muted, #888); font-size: 13px; margin-bottom: 12px;">{{ ___('Enter the code we sent you') }}</p>
                        <div class="au-field">
                            <label>{{ ___('Verification code') }}</label>
                            <input aria-label="{{ ___('Verification code') }}" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="––––––">
                        </div>
                        <button type="button" id="au-otp-verify" class="au-cta" style="margin-top: 10px;">{{ ___('Verify') }}</button>
                    </div>
                </form>

                <form id="au-register-form" style="display:none;">
                    <div class="au-field">
                        <label>{{ ___('Full Name') }}</label>
                        <input aria-label="{{ ___('Full Name') }}" type="text" name="name" required>
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Email Address') }}</label>
                        <input aria-label="{{ ___('Email Address') }}" type="email" name="email" required>
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Phone Number') }} ({{ ___('optional') }})</label>
                        <input aria-label="{{ ___('Phone Number') }}" type="tel" name="phone">
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Password') }}</label>
                        <input aria-label="{{ ___('Password') }}" type="password" name="password" required minlength="6">
                    </div>
                    <div class="au-field">
                        <label>{{ ___('Confirm Password') }}</label>
                        <input aria-label="{{ ___('Confirm Password') }}" type="password" name="password_confirmation" required minlength="6">
                    </div>
                    <button type="submit" class="au-cta" style="margin-top: 10px;">{{ ___('Register') }}</button>
                    @include('store_templates.partials.social-login-buttons', ['store' => $store])
                </form>
            </div>
        </aside>
    @endif

    {{-- ══════════════ theme-pack-blade feature surfaces (F1/F3/F4/F6/F7) ══════════════ --}}

    {{-- B6 · Following management sheet (opens when the follow button is already on) --}}
    @if($authMode !== 'guest_only' || $customer)
    <div class="au-scrim" id="au-following-scrim"></div>
    <aside class="au-sheet" id="au-following-sheet" aria-hidden="true" style="z-index:1000002">
        <div class="au-sheet-head">
            <h3>{{ ___('Following') }}</h3>
            <button class="au-close" type="button" data-close-sheet="following" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body">
            <label class="au-follow-sheet-row">
                <span>{{ ___('Notifications') }}</span>
                <input type="checkbox" id="au-follow-notify" role="switch">
            </label>
            <button class="au-cta au-follow-unfollow" id="au-follow-unfollow" type="button" style="margin-top:16px">{{ ___('Unfollow') }}</button>
            <button class="au-btn-ghost" id="au-follow-cancel" type="button" style="margin-top:10px;width:100%">{{ ___('Cancel') }}</button>
        </div>
    </aside>
    @endif

    {{-- B8 · About sheet (opens when the brand name/info is tapped) --}}
    <div class="au-scrim" id="au-about-scrim"></div>
    <aside class="au-sheet" id="au-about-sheet" aria-hidden="true" style="z-index:1000002">
        <div class="au-sheet-head">
            <h3>{{ $store->title }}</h3>
            <button class="au-close" type="button" data-close-sheet="about" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body">
            @if($aboutRating !== null)
                <div class="au-about-rating"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
            @endif
            <div class="au-about-list">
                @if(!empty($store->timing))
                    <div class="au-about-row"><i class="ico ico-clock" aria-hidden="true"><x-ui.icon name="clock" /></i><span>{{ $store->timing }}</span></div>
                @endif
                @if(!empty($store->address))
                    <a class="au-about-row" href="https://maps.google.com/?q={{ urlencode($store->address) }}" target="_blank" rel="noopener"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i><span>{{ $store->address }}</span></a>
                @endif
                @if(!empty($store->phone))
                    <a class="au-about-row" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $store->phone) }}"><i class="ico ico-phone" aria-hidden="true"><x-ui.icon name="phone" /></i><span dir="ltr">{{ $store->phone }}</span></a>
                @endif
                @if($aboutInstagram)
                    <a class="au-about-row" href="{{ $aboutInstagram }}" target="_blank" rel="noopener"><i class="ico ico-brand-instagram" aria-hidden="true"><x-ui.icon name="brand-instagram" /></i><span>{{ ___('Instagram') }}</span></a>
                @endif
                @if($aboutFacebook)
                    <a class="au-about-row" href="{{ $aboutFacebook }}" target="_blank" rel="noopener"><i class="ico ico-brand-facebook" aria-hidden="true"><x-ui.icon name="brand-facebook" /></i><span>{{ ___('Facebook') }}</span></a>
                @endif
            </div>
            @if($aboutReviews->isNotEmpty())
                <div class="au-about-reviews">
                    @foreach($aboutReviews as $rev)
                        <div class="au-about-review">
                            <div class="au-about-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="au-about-star"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ $rev->stars }}</span></div>
                            @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </aside>

    {{-- F3 · full-screen story viewer overlay --}}
    <div class="au-story-viewer" id="au-story-viewer" hidden>
        <div class="au-sv-progress" id="au-sv-progress"></div>
        <button class="au-sv-close" id="au-sv-close" type="button" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        <div class="au-sv-stage" id="au-sv-stage">
            <img class="au-sv-media" id="au-sv-media" alt="">
            <button class="au-sv-nav prev" id="au-sv-prev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="au-sv-nav next" id="au-sv-next" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="au-sv-foot">
                <p class="au-sv-caption" id="au-sv-caption"></p>
                <a class="au-cta au-sv-cta" id="au-sv-cta" hidden><span></span></a>
            </div>
        </div>
    </div>

    {{-- F4 · nearest-branch map picker --}}
    @if($showBranches)
    <div class="au-scrim" id="au-branchmap-scrim"></div>
    <aside class="au-sheet" id="au-branchmap-sheet" aria-hidden="true">
        <div class="au-sheet-head">
            <h3>{{ ___('Nearest branch') }}</h3>
            <button class="au-close" type="button" data-close-sheet="branchmap" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body">
            <div class="au-branchmap" id="au-branchmap"></div>
            <p class="au-geo-attrib" id="au-map-attrib"></p>
            <div class="au-branch-list" id="au-branch-geo-list"></div>
        </div>
    </aside>
    @endif

    {{-- F6 · write-review sheet --}}
    <div class="au-scrim" id="au-review-scrim"></div>
    <aside class="au-sheet" id="au-review-sheet" aria-hidden="true">
        <div class="au-sheet-head">
            <h3>{{ ___('Write a review') }}</h3>
            <button class="au-close" type="button" data-close-sheet="review" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body">
            <p class="au-sheet-sub" id="au-review-item-name"></p>
            <div class="au-rv-stars" id="au-review-stars">
                @for($n = 1; $n <= 5; $n++)
                    <button data-star="{{ $n }}" type="button" aria-label="{{ $n }}">★</button>
                @endfor
            </div>
            @if(!$customer)
                <input aria-label="{{ ___('Your Name') }}" class="au-input" id="au-review-name-in" placeholder="{{ ___('Your Name') }}" autocomplete="name" style="width:100%;margin-bottom:10px">
            @endif
            <textarea aria-label="{{ ___('Tell others about this dish…') }}" class="au-input" id="au-review-text" rows="3" placeholder="{{ ___('Tell others about this dish…') }}" style="width:100%"></textarea>
            <p class="au-form-error" id="au-review-err" style="display:none"></p>
            <button class="au-cta" id="au-review-submit" type="button" style="margin-top:12px;width:100%">{{ ___('Submit review') }}</button>
        </div>
    </aside>

    {{-- F1 · start-a-meal-plan sheet --}}
    @if($mealPlansEnabled)
    <div class="au-scrim" id="au-mealplan-scrim"></div>
    <aside class="au-sheet" id="au-mealplan-sheet" aria-hidden="true" data-cadences='@json($mealPlanCadences)'>
        <div class="au-sheet-head">
            <h3>{{ ___('Create a meal plan') }}</h3>
            <button class="au-close" type="button" data-close-sheet="mealplan" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body">
            @if($customer)
            <div id="au-mp-form">
                <p class="au-sheet-sub" id="au-mp-items-summary"></p>
                <label class="au-mp-label">{{ ___('Plan name') }} ({{ ___('optional') }})</label>
                <input aria-label="{{ ___('e.g. Weekly lunch') }}" class="au-input" id="au-mp-name" placeholder="{{ ___('e.g. Weekly lunch') }}" style="width:100%;margin-bottom:10px">
                <label class="au-mp-label">{{ ___('How often?') }}</label>
                <div class="au-seg-mini" id="au-mp-cadence"></div>
                <div id="au-mp-days-wrap" hidden>
                    <label class="au-mp-label">{{ ___('Which days?') }}</label>
                    <div class="au-chiprow" id="au-mp-days">
                        @foreach(['Sun'=>0,'Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6] as $d => $i)
                            <button data-day="{{ $i }}" type="button">{{ ___($d) }}</button>
                        @endforeach
                    </div>
                </div>
                <label class="au-mp-label">{{ ___('Start date') }}</label>
                <input aria-label="{{ ___('Start date') }}" class="au-input" id="au-mp-start" type="date" min="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}" style="width:100%">
                <p class="au-form-error" id="au-mp-err" style="display:none"></p>
                <button class="au-cta" id="au-mp-submit" type="button" style="margin-top:14px;width:100%">{{ ___('Create plan') }}</button>
            </div>
            <div class="au-mp-done" id="au-mp-done" hidden>
                <div style="font-size:44px">📅</div>
                <b>{{ ___('Meal plan created!') }}</b>
                <p id="au-mp-done-sub" style="color:var(--au-muted);font-size:13px"></p>
                <a class="au-btn au-ghostbtn" href="{{ route('customer.account', $store->id) }}">{{ ___('Manage plans') }}</a>
            </div>
            @else
            <p class="au-sheet-sub">{{ ___('Log in to create a recurring meal plan.') }}</p>
            <button class="au-cta" id="au-mp-login-btn" type="button" style="width:100%">{{ ___('Login') }}</button>
            @endif
        </div>
    </aside>
    @endif

    {{-- F7 · OTP checkout gate sheet (auto-advance) --}}
    <div class="au-scrim" id="au-checkoutotp-scrim"></div>
    <aside class="au-sheet" id="au-checkoutotp-sheet" aria-hidden="true">
        <div class="au-sheet-head">
            <h3>{{ ___('Verify your phone') }}</h3>
            <button class="au-close" type="button" data-close-sheet="checkoutotp" aria-label=\"{{ ___('Close') }}\"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="au-sheet-body">
            <p class="au-sheet-sub">{{ ___('Enter the code we sent to') }} <b id="au-otp-gate-phone" dir="ltr"></b></p>
            <div class="au-otp-boxes" id="au-otp-gate-boxes">
                <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
            </div>
            <p class="au-form-error" id="au-otp-gate-err" style="display:none"></p>
            <button class="au-cta" id="au-otp-gate-verify" type="button" style="width:100%">{{ ___('Verify & place order') }}</button>
            <button class="au-linkbtn" id="au-otp-gate-resend" type="button">{{ ___('Resend code') }}</button>
        </div>
    </aside>

    <div class="au-toast" id="au-toast" role="status"></div>
</div>

{{-- TP: catering request form (gated) — opens as a shared modal from the header
     button; no longer sits inline below the footer. --}}
@include('store_templates._partials.catering-form', ['cateringModal' => true, 'cateringSurface' => 'var(--tc-surface, #0d0d15)', 'cateringInk' => 'var(--tc-text, #f5f5fa)'])

{{-- Shared notification-center panel (bell lives in the topbar icon row) --}}
@include('store_templates._partials.notif-bell')

{{-- AURORA 3.0 · bottom dock (soiree-parity). Thin launchers over existing
     controls — scroll home / scroll menu / focus search / open the cart via
     the topbar toggle. Wired in script.js; badge mirrors #au-cart-count. --}}
<nav class="au2-dock" id="auDock" aria-label="{{ ___('Main') }}">
    <button class="au2-dock-btn is-on" data-au2dock="home" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg>
        <span>{{ ___('Home') }}</span>
    </button>
    <button class="au2-dock-btn" data-au2dock="menu" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1" fill="currentColor" stroke="none"/></svg>
        <span>{{ ___('Menu') }}</span>
    </button>
    @if(theme_setting($store->id, 'search.enabled', true))
    <button class="au2-dock-btn" data-au2dock="search" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <span>{{ ___('Search') }}</span>
    </button>
    @endif
    @if($allow_order)
    <button class="au2-dock-btn" data-au2dock="cart" type="button" style="position:relative">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
        <span>{{ ___('Cart') }}</span>
        <i class="au2-dock-badge" id="auDockCartCount" hidden>0</i>
    </button>
    @endif
    <button class="au2-dock-btn" data-au2dock="favs" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.35-9.5-8.5C1 8 2.5 4.5 6 4.5c2 0 3.2 1.2 4 2.3.8-1.1 2-2.3 4-2.3 3.5 0 5 3.5 3.5 7C19 15.65 12 20 12 20Z"/></svg>
        <span>{{ ___('Favorites') }}</span>
    </button>
    @if($authMode !== 'guest_only')
    <button class="au2-dock-btn" data-au2dock="profile" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
        <span>{{ ___('Profile') }}</span>
    </button>
    @endif
</nav>

@endsection

@push('scripts_at_bottom')
{{-- F4 · self-hosted Leaflet — must load before script.js (which layout loads after this stack) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
{{-- P3-13: totals from the server quote (THEME-CONTRACT §5.2). --}}
<script src="{{ asset('assets/store_templates/_shared/js/mb-quote.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-quote.js')) }}"></script>
@endpush

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    // ── #28: Sort items ──────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        var sortBtns = document.querySelectorAll('.au-sort-btn');
        sortBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                sortBtns.forEach(function (b) {
                    b.style.background = 'transparent';
                    b.style.color = 'inherit';
                    b.style.fontWeight = '500';
                });
                this.style.background = 'var(--au-fx)';
                this.style.color = '#12121a';
                this.style.fontWeight = '600';
                var sort = this.dataset.sort;
                var cards = Array.from(document.querySelectorAll('[data-item-card]'));
                if (sort === 'default') {
                    cards.forEach(function (c, i) { c.style.order = i; });
                } else if (sort === 'price-asc') {
                    cards.sort(function (a, b) {
                        return parseFloat(a.dataset.amount || 0) - parseFloat(b.dataset.amount || 0);
                    });
                    cards.forEach(function (c, i) { c.style.order = i; });
                } else if (sort === 'price-desc') {
                    cards.sort(function (a, b) {
                        return parseFloat(b.dataset.amount || 0) - parseFloat(a.dataset.amount || 0);
                    });
                    cards.forEach(function (c, i) { c.style.order = i; });
                } else if (sort === 'popular') {
                    cards.sort(function (a, b) {
                        return (parseInt(b.dataset.popular) || 0) - (parseInt(a.dataset.popular) || 0);
                    });
                    cards.forEach(function (c, i) { c.style.order = i; });
                }
            });
        });

        // ── #61: Share dish cards ─────────────────────────────────────────────
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.share-dish-btn');
            if (!btn) return;
            var name = btn.dataset.name, price = btn.dataset.price, url = btn.dataset.url;
            var text = name + ' — ' + price;
            if (navigator.share) {
                navigator.share({ title: name, text: text, url: url }).catch(function(){});
            } else {
                navigator.clipboard.writeText(url).then(function () {
                    btn.innerHTML = '<i class="ico ico-check" style="font-size:12px;" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></i>';
                    setTimeout(function () { btn.innerHTML = '<i class="ico ico-share" style="font-size:12px;" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="m8.2 10.8 7.6-4.1M8.2 13.2l7.6 4.1"/></svg></i>'; }, 2000);
                }).catch(function () { prompt('{{ ___("Copy link:") }}', url); });
            }
        });

        // ── #30: Call Waiter / Request Bill ───────────────────────────────────
        var waiterBtn = document.getElementById('au-call-waiter-btn');
        var billBtn = document.getElementById('au-request-bill-btn');
        var waiterSheet = document.getElementById('au-waiter-sheet');
        var waiterScrim = document.getElementById('au-waiter-scrim');
        var waiterTitle = document.getElementById('au-waiter-title');
        var waiterContext = document.getElementById('au-waiter-context-label');
        var waiterTypeInput = document.getElementById('au-waiter-type-input');
        var waiterForm = document.getElementById('au-waiter-form');
        var waiterSuccess = document.getElementById('au-waiter-success');
        var waiterSentLabel = document.getElementById('au-waiter-sent-label');

        function openWaiterSheet(type) {
            if (!waiterSheet) return;
            var isCall = type === 'call_waiter';
            waiterTitle.textContent = isCall ? '{{ ___("Call Waiter") }}' : '{{ ___("Request Bill") }}';
            waiterContext.textContent = isCall
                ? '{{ ___("We will send a waiter to your table right away.") }}'
                : '{{ ___("We will bring your bill to the table shortly.") }}';
            if (waiterTypeInput) waiterTypeInput.value = type;
            waiterForm.style.display = '';
            waiterSuccess.style.display = 'none';
            waiterSheet.classList.add('au-sheet--open');
            waiterSheet.setAttribute('aria-hidden', 'false');
            if (waiterScrim) { waiterScrim.classList.add('au-scrim--visible'); }
        }
        function closeWaiterSheet() {
            if (!waiterSheet) return;
            waiterSheet.classList.remove('au-sheet--open');
            waiterSheet.setAttribute('aria-hidden', 'true');
            if (waiterScrim) waiterScrim.classList.remove('au-scrim--visible');
        }
        if (waiterBtn) waiterBtn.addEventListener('click', function () { openWaiterSheet('call_waiter'); });
        if (billBtn) billBtn.addEventListener('click', function () { openWaiterSheet('request_bill'); });
        if (waiterScrim) waiterScrim.addEventListener('click', closeWaiterSheet);
        document.querySelectorAll('[data-close-sheet="waiter"]').forEach(function(b){ b.addEventListener('click', closeWaiterSheet); });

        function showWaiterError(msg) {
            if (!waiterForm) return;
            var box = waiterForm.querySelector('.au-waiter-error');
            if (!box) {
                box = document.createElement('p');
                box.className = 'au-waiter-error';
                waiterForm.appendChild(box);
            }
            box.textContent = msg;   /* server string -> text, never markup */
            box.style.display = 'block';
        }

        if (waiterForm) {
            waiterForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var prevErr = waiterForm.querySelector('.au-waiter-error');
                if (prevErr) prevErr.style.display = 'none';
                var payload = {
                    _token: '{{ csrf_token() }}',
                    table: document.getElementById('au-waiter-table') ? document.getElementById('au-waiter-table').value : '',
                    type: waiterTypeInput ? waiterTypeInput.value : 'call_waiter',
                    message: waiterForm.querySelector('[name="message"]') ? waiterForm.querySelector('[name="message"]').value : '',
                };
                fetch('{{ route("store.callTheWaiter", $store->id) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify(payload)
                }).then(function (r) { return r.json(); }).then(function (data) {
                    /* The server VALIDATES this: `table` is required|integer and
                       `type` must be call_waiter|request_bill, so a guest who is
                       not seated at a table gets success:false and no WaiterCall
                       row. Announcing "a waiter will be with you shortly" on that
                       response told the diner help was coming when nothing had
                       been written. Only the server's own answer may claim it. */
                    if (!data || !data.success) {
                        showWaiterError((data && data.message) || '{{ ___("Could not send your request. Please try again.") }}');
                        return;
                    }
                    waiterForm.style.display = 'none';
                    waiterSuccess.style.display = '';
                    waiterSentLabel.textContent = payload.type === 'request_bill'
                        ? '{{ ___("Your bill is on its way!") }}'
                        : '{{ ___("A waiter will be with you shortly!") }}';
                    setTimeout(closeWaiterSheet, 3500);
                }).catch(function () {
                    showWaiterError('{{ ___("Network error. Please try again.") }}');
                });
            });
        }
    });
</script>
<script nonce="{{ csp_nonce() }}">
    window.AURORA = {
        restaurantSlug: @json($store->slug),
        currencySign: @json($curSign),
        currencyGap:  @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:  @json($curPos),
        currencyDecimals: @json($storeDecimals),
        moneyDecimals: @json(money_decimals(@$postOptions->currency_code ?: null)), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        deliveryCharge:    @json($deliveryCharge),
        taxCharge:         @json($taxCharge),
        taxType:           @json($taxType),
        minOrderValue:     @json($minOrderValue),
        allowOrderSched:   @json($allowOrderSched),
        allowOrder:   @json((bool) $allow_order),
        allowPayment: @json((bool) $allow_payment),
        defaultTable: @json($table->table_no ?? null),
        forceTable:   @json((bool) $table),
        authMode:     @json($authMode),
        isLoggedIn:   @json((bool) $customer),
        storeId: @json($store->id),
        /* theme-pack-blade features */
        customerToken:    @json($customerApiToken),
        isFollowing:      @json((bool) $isFollowing),
        followerCount:    @json((int) $followerCount),
        followNotify:     @json((bool) $followNotify),
        mealPlansEnabled: @json((bool) $mealPlansEnabled),
        otpGateEnabled:   @json((bool) $otpGateEnabled),
        leafletImgPath:   @json(asset('assets/vendor/leaflet/images')),
        endpoints: {
            login: @json(route('customer.login', $store->id)),
            register: @json(route('customer.register', $store->id)),
            logout: @json(route('customer.logout', $store->id)),
            otpRequest: @json(route('customer.otp.request', $store->id)),
            otpVerify: @json(route('customer.otp.verify', $store->id)),
            follow:      @json(url('r/'.$store->slug.'/follow')),
            stories:     @json(route('stories.public', $store->slug)),
            branchesGeo: @json(route('geo.branches', $store->slug)),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:  @json(route('geo.reverse')),
            reviewsBase: @json((string) store_options($store->id, 'item_reviews_enabled', '1') !== '0' ? url('r/'.$store->id.'/item') : ''),
            mealPlan:    @json(url('customer/meal-plan')),
            otpSend:     @json(route('checkout.otp.send')),
            otpVerify2:  @json(route('checkout.otp.verify')),
        },
        items: @json((object) $jsItems),
        csrf: @json(csrf_token()),
        // #27: multi-currency
        multiCurrency: @json(!empty($postOptions->enable_multi_currency)),
        currencyRates: @json($mcRates),
        baseCurrency: @json(config('settings.currency_code', 'USD')),
        // MC-34: shopper guardrail — the display currency is an estimate; the
        // charge is always in the store base. asOf is the FX feed date (null for
        // manual rates → the notice omits the date clause rather than inventing one).
        currencyAsOf: @json($mcAsOf),
        currencyEstimateNotice: @json(___('Prices in :cur are estimates. You will be charged in :base.', ['cur' => '__CUR__', 'base' => '__BASE__'])),
        reviewUrl: @json(route('store.submitReview', $store->slug)),
        npsUrl: @json(route('store.submitNps', $store->slug)),
        i18n: {
            add: @json(theme_setting($store->id, 'texts.add_to_cart', '') ?: ___('Add to order')),
            update: @json(___('Update order')),
            customize: @json(___('Customize')),
            choose: @json(___('Choose an option')),
            extras: @json(___('Add extras')),
            quantity: @json(___('Quantity')),
            subtotal: @json(___('Subtotal')),
            delivery: @json(___('Delivery')),
            tax: @json(___('Tax')),
            discount: @json(___('Discount')),
            tip: @json(___('Tip')),
            total: @json(___('Total')),
            remove: @json(___('Remove')),
            items: @json(___('items')),
            resumeSuffix: @json(___('in your cart from your last visit.')),
            note: @json(___('Order Note')),
            note_placeholder: @json(___('Any special requests?')),
            item: @json(___('item')),
            {{-- I5-1: CLDR plural forms for the cart-item count, so ar/ru/… pick the
                 right form client-side (the flat item/items pair above is 2-form only). --}}
            itemForms: @json(explode('|', (string) trans('lang.n_item'))),
            pluralLocale: @json(get_lang()),
            selectType: @json(___('Please choose an order type')),
            free: @json(___('Free')),
            rate_this_item: @json(___('Rate this dish')),
            your_name: @json(___('Your name (optional)')),
            your_review: @json(___('Share your experience...')),
            submit: @json(___('Submit')),
            min_order: @json(___('Minimum order')),
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
            thanks:      @json(___('Thanks!')),
            popular:     @json(___('Popular')),
            enter_code:  @json(___('Enter the code')),
            sent:        @json(___('Sent')),
            failed:      @json(___('Failed')),
            reviewThanks: @json(___('Thank you! Your review will appear once approved.')),
            reviewFailed: @json(___('Could not save your review. Please try again.')),
            networkError: @json(___('Network error. Please try again.')),
            now_following: @json(___('You are now following :store', ['store' => $store->title])),
            action_failed: @json(___('Something went wrong, please try again.')),
            order_label: @json(___('Order')),
            recent_orders: @json(___('Your recent orders')),
            loc_unavailable: @json(___('Location unavailable')),
            loc_denied: @json(___('Location denied')),
            no_address: @json(___('No address found')),
            promo_enter: @json(___('Please enter a promo code.')),
            promo_invalid: @json(___('Invalid promo code.')),
            promo_error: @json(___('Error validating promo code.')),
            cart_empty: @json(___('Cart is empty')),
            otp_send_failed: @json(___('Could not send the code.')),
            otp_invalid: @json(___('Invalid or expired code.')),
            view_cart: @json(___('View Cart')),
        }
    };
</script>
<script nonce="{{ csp_nonce() }}">
// ── #35: Sticky table session ─────────────────────────────────────────────────
(function () {
    var slug = @json($store->slug);
    var storeKey = 'mb-table-' + slug;
    // Read from URL param ?table=X and persist in sessionStorage
    var urlTable = new URLSearchParams(location.search).get('table');
    if (urlTable) {
        sessionStorage.setItem(storeKey, urlTable);
    }
    var storedTable = sessionStorage.getItem(storeKey);
    if (storedTable) {
        // Pre-fill any visible table inputs
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('input[name="table"]:not([readonly])').forEach(function (inp) {
                if (!inp.value) inp.value = storedTable;
            });
            // Also pre-fill waiter sheet table input
            var wt = document.getElementById('au-waiter-table');
            if (wt && !wt.value && !wt.readOnly) wt.value = storedTable;
        });
    }
})();

// ── #4: Hide menu items outside their scheduled availability window ───────────
document.addEventListener('DOMContentLoaded', function () {
    var now = new Date();
    var curMin = now.getHours() * 60 + now.getMinutes();
    var curDay = now.getDay(); // 0=Sun

    function timeToMin(t) {
        if (!t) return null;
        var parts = t.split(':');
        return parseInt(parts[0]) * 60 + parseInt(parts[1]);
    }

    document.querySelectorAll('.au-avail-badge').forEach(function (badge) {
        var fromMin = timeToMin(badge.dataset.from);
        var toMin   = timeToMin(badge.dataset.to);
        var days    = badge.dataset.days ? badge.dataset.days.split(',').map(Number) : null;

        var inWindow = true;
        if (fromMin !== null && toMin !== null) {
            inWindow = curMin >= fromMin && curMin <= toMin;
        }
        if (days && days.length > 0) {
            inWindow = inWindow && days.indexOf(curDay) !== -1;
        }

        if (!inWindow) {
            var card = badge.closest('[data-item-card]');
            if (card) {
                card.style.opacity = '0.35';
                card.style.pointerEvents = 'none';
                badge.style.color = '#fb7185';
                badge.textContent = '⏰ ' + '{{ ___("Not available now") }}';
            }
        }
    });

        // ── #22: Guest order history lookup ───────────────────────────────────
    var lookupBtn = document.getElementById('au-order-lookup-btn');
    if (lookupBtn) {
        lookupBtn.addEventListener('click', function () {
            var val = document.getElementById('au-order-lookup-input').value.trim();
            if (!val) return;
            var resultsDiv = document.getElementById('au-order-lookup-results');
            resultsDiv.innerHTML = '<i class="ico ico-loader-2 ico-spin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 9 9"/></svg></i>';
            fetch('{{ route("store.orderLookup", $store->slug) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ identifier: val })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data.success) {
                    resultsDiv.innerHTML = '<p style="color:var(--au-muted);font-size:13px;">' + (data.message || '') + '</p>';
                    return;
                }
                var html = '<div style="display:flex;flex-direction:column;gap:8px;">';
                data.orders.forEach(function (o) {
                    html += '<a href="' + o.track_url + '" target="_blank" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--au-glass);border-radius:10px;text-decoration:none;color:inherit;gap:8px;">';
                    html += '<span style="font-size:13px;">#' + o.id + ' · ' + o.date + '</span>';
                    html += '<span style="display:flex;gap:6px;align-items:center;">';
                    html += '<span style="font-size:12px;background:var(--au-glass-hi);padding:2px 8px;border-radius:6px;">' + o.status + '</span>';
                    html += '<b>' + o.total + '</b>';
                    html += '</span></a>';
                });
                html += '</div>';
                resultsDiv.innerHTML = html;
            }).catch(function () {
                resultsDiv.innerHTML = '';
            });
        });
    }

    // ── #62: NPS survey submission ────────────────────────────────────────
    (function() {
        var npsBlock = document.getElementById('au-nps-block');
        if (!npsBlock) return;
        npsBlock.querySelectorAll('.au-nps-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var score = Number(btn.getAttribute('data-nps'));
                npsBlock.querySelectorAll('.au-nps-btn').forEach(function (b) { b.disabled = true; });
                /* The thank-you used to be painted straight from the click
                   handler, so a rejected or failed POST still told the guest
                   their score was recorded. Wait for the server. */
                fetch('{{ route("store.submitNps", $store->slug) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ score: score })
                }).then(function (r) { return r.json().catch(function () { return { success: r.ok }; }); })
                  .then(function (d) {
                    if (!d || d.success === false) { throw new Error('nps'); }
                    var p = document.createElement('p');
                    p.className = 'au-nps-done';
                    p.textContent = score >= 9 ? '{{ ___("Thank you! We love your support 🙏") }}' :
                                    score >= 7 ? '{{ ___("Thanks for the feedback!") }}' :
                                                 '{{ ___("We appreciate your honesty. We\'ll do better!") }}';
                    npsBlock.replaceChildren(p);
                  }).catch(function () {
                    npsBlock.querySelectorAll('.au-nps-btn').forEach(function (b) { b.disabled = false; });
                    var e = npsBlock.querySelector('.au-nps-error');
                    if (!e) { e = document.createElement('p'); e.className = 'au-nps-error'; npsBlock.appendChild(e); }
                    e.textContent = '{{ ___("Could not send your rating. Please try again.") }}';
                  });
            });
        });
    })();

    // ── #39/#40: Light/dark theme toggle ────────────────────────────────────
    (function() {
        var auroraEl = document.querySelector('.aurora');
        var toggleBtn = document.getElementById('au-theme-toggle');
        var icon = document.getElementById('au-theme-icon');
        if (!toggleBtn || !auroraEl) return;

        var ownerTheme = @json(@$postOptions->public_theme_mode ?? 'auto'); // 'dark','light','auto'
        // Persistence is delegated to the shared engine (_shared/js/mb-theme.js,
        // key mb_theme_<store-id>); the legacy per-slug key is a read-only
        // fallback so existing diners keep their choice.
        var mbT = window.mbTheme || null;
        if (mbT) mbT.init({ rootSel: '.aurora', legacyKeys: ['au-theme-' + @json($store->slug)] });
        var stored = mbT ? mbT.get() : localStorage.getItem('au-theme-' + @json($store->slug));
        var effectiveTheme = (stored === 'light' || stored === 'dark') ? stored
            : (ownerTheme !== 'auto' ? ownerTheme : null);

        function applyTheme(t) {
            auroraEl.setAttribute('data-theme', t);
            // Also reflect on <html> so out-of-tree surfaces (sheets, scrims, body) inherit the theme vars.
            document.documentElement.setAttribute('data-theme', t);
            if (icon) { icon.className = 'ico ico-' + (t === 'light' ? 'sun' : 'moon'); icon.innerHTML = t === 'light' ? "<svg width=\"1em\" height=\"1em\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.9\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M8 12a4 4 0 1 0 8 0a4 4 0 1 0 -8 0\"/><path d=\"M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7\"/></svg>" : "<svg width=\"1em\" height=\"1em\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.9\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454l0 .008\"/></svg>"; }
        }

        if (effectiveTheme) {
            applyTheme(effectiveTheme);
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
            applyTheme('light');
        } else {
            applyTheme('dark');
        }

        toggleBtn.addEventListener('click', function() {
            var current = auroraEl.getAttribute('data-theme') || 'dark';
            var next = current === 'dark' ? 'light' : 'dark';
            if (mbT) mbT.set(next);
            else { try { localStorage.setItem('au-theme-' + @json($store->slug), next); } catch (e) {} }
            applyTheme(next);
        });

        // #42: Seasonal skin based on current date
        @php
            $month = date('n'); $day = date('j');
            $season = '';
            if ($month === 12 || ($month === 1 && $day <= 6)) $season = 'christmas';
            elseif ($month === 4 && $day <= 10) $season = 'ramadan'; // approx — Ramadan varies by year
        @endphp
        var season = @json($season);
        if (season && auroraEl) auroraEl.setAttribute('data-season', season);
    })();

    /* #24 abandoned-cart nudge: intentionally NOT implemented here.
       script.js already owns this banner and drives it from aurora's real cart
       (`aurora_cart_<slug>`). A second copy used to live at this spot reading a
       `mb-cart-<slug>` key that aurora never writes, so it force-showed the
       prompt over an empty cart, mis-pluralised the count ("1 items"), skipped
       the `.au-resume-open` class that slides the cart bar and the FABs out of
       the way — producing the four-way pile-up at the bottom of the screen —
       and wired a second Clear handler that emptied the wrong key, so the
       banner came straight back on the next load. */
});

// ── #57: Loyalty stamp card ──────────────────────────────────────────────────
(function() {
    var loyaltyBlock = document.getElementById('au-loyalty-block');
    if (!loyaltyBlock) return;
    var balanceUrl = @json(route('publicView.loyalty.balance', $store->id));
    var redeemUrl  = @json(route('publicView.loyalty.redeem', $store->id));
    var token      = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    var threshold  = {{ $loyaltyProgram?->reward_threshold ?? 0 }};
    var type       = @json($loyaltyProgram?->type ?? 'stamps');

    function updateUI(data) {
        var bal = data.balance;
        document.getElementById('au-loyalty-balance').textContent = bal;
        if (type === 'stamps') {
            document.querySelectorAll('.au-stamp-dot').forEach(function(dot, i) {
                var active = i < bal;
                dot.style.background = active ? 'var(--au-fx)' : '';
                dot.style.border     = active ? '2px solid var(--au-fx)' : '';
                dot.style.color      = active ? '#fff' : '';
            });
        } else {
            var bar = document.getElementById('au-loyalty-bar');
            if (bar) bar.style.width = Math.min(100, data.progress_pct) + '%';
        }
        var redeemBtn = document.getElementById('au-loyalty-redeem-btn');
        if (redeemBtn) redeemBtn.style.display = data.ready_to_redeem ? '' : 'none';
    }

    fetch(balanceUrl).then(function(r){ return r.json(); }).then(function(d) {
        if (d.active) updateUI(d);
    }).catch(function(){});

    var redeemBtn = document.getElementById('au-loyalty-redeem-btn');
    if (redeemBtn) {
        redeemBtn.addEventListener('click', async function() {
            redeemBtn.disabled = true;
            var res = await fetch(redeemUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: '{}',
            });
            var data = await res.json();
            if (data.success) {
                redeemBtn.style.display = 'none';
                document.getElementById('au-loyalty-redeem-ok').style.display = '';
                updateUI({ balance: data.new_balance, progress_pct: 0, ready_to_redeem: false });
            } else {
                redeemBtn.disabled = false;
                alert(data.error || 'Redemption failed.');
            }
        });
    }
})();

// ── #80: Reservation sheet ───────────────────────────────────────────────────
(function() {
    var fab   = document.getElementById('au-res-fab');
    var sheet = document.getElementById('au-res-sheet');
    if (!fab || !sheet) return;
    fab.addEventListener('click', function() {
        sheet.classList.add('is-open');
        sheet.setAttribute('aria-hidden', 'false');
    });
    var closeBtn = sheet.querySelector('[data-close-sheet="res"]');
    if (closeBtn) closeBtn.addEventListener('click', function() {
        sheet.classList.remove('is-open');
        sheet.setAttribute('aria-hidden', 'true');
    });
    @if(session('reservation_success'))
    sheet.classList.add('is-open');
    sheet.setAttribute('aria-hidden', 'false');
    @endif
})();

// ── #81: Waitlist sheet ───────────────────────────────────────────────────────
(function() {
    var fab   = document.getElementById('au-waitlist-fab');
    var sheet = document.getElementById('au-waitlist-sheet');
    if (!fab || !sheet) return;
    fab.addEventListener('click', function() {
        sheet.classList.add('is-open');
        sheet.setAttribute('aria-hidden', 'false');
    });
    var closeBtn = sheet.querySelector('[data-close-sheet="waitlist"]');
    if (closeBtn) closeBtn.addEventListener('click', function() {
        sheet.classList.remove('is-open');
        sheet.setAttribute('aria-hidden', 'true');
    });
    // Auto-open if just joined
    @if(session('waitlist_joined'))
    sheet.classList.add('is-open');
    sheet.setAttribute('aria-hidden', 'false');
    @endif
})();

// ── #27: Multi-currency display switcher ─────────────────────────────────────
(function() {
    var wrap = document.getElementById('au-currency-wrap');
    if (!wrap || !window.AURORA.multiCurrency) return;

    var toggleBtn = document.getElementById('au-currency-toggle');
    var menu = wrap.querySelector('.au-lang-menu');
    var curLabel = document.getElementById('au-cur-label');

    // Read saved currency from session storage
    var activeCur = sessionStorage.getItem('au_currency') || AURORA.baseCurrency;
    var activeRate = parseFloat(sessionStorage.getItem('au_currency_rate') || '1');

    toggleBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        menu.classList.toggle('is-open');
    });
    document.addEventListener('click', function() { menu.classList.remove('is-open'); });

    menu.querySelectorAll('button[data-cur]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var code = btn.dataset.cur;
            var rate = parseFloat(btn.dataset.rate);
            activeCur = code;
            activeRate = rate;
            sessionStorage.setItem('au_currency', code);
            sessionStorage.setItem('au_currency_rate', rate);
            curLabel.textContent = code;
            applyRates(code, rate);
            menu.classList.remove('is-open');
        });
    });

    function formatMoney(value, sign, pos, code) {
        var dp;
        if (!code || code === AURORA.baseCurrency) dp = AURORA.currencyDecimals;
        else dp = (AURORA.decimalsByCode || {})[code];
        if (dp == null) dp = 2;
        var str = value.toFixed(dp);
        var out = pos === 'right' ? str + sign : sign + str;
        // MC-34: prefix converted (non-base) prices with ≈ so a shopper reads them
        // as approximate, never the exact amount they will be charged.
        return (code && code !== AURORA.baseCurrency) ? '≈ ' + out : out;
    }

    var DISCLAIMER_ID = 'au-currency-disclaimer';
    function showDisclaimer(code) {
        var el = document.getElementById(DISCLAIMER_ID);
        if (!el) {
            el = document.createElement('div');
            el.id = DISCLAIMER_ID;
            el.setAttribute('role', 'status');
            /* styled in aurora's style.css (#au-currency-disclaimer) — inline styles
               here would beat the stylesheet and un-theme the notice */
            document.querySelector('.au-topbar')?.insertAdjacentElement('afterend', el);
        }
        if (code === AURORA.baseCurrency) { el.remove(); return; }
        // MC-34: "Prices in {CODE} are estimates[ ({date})]. You will be charged in {BASE}."
        var msg = AURORA.currencyEstimateNotice.replace('__CUR__', code).replace('__BASE__', AURORA.baseCurrency);
        if (AURORA.currencyAsOf) msg = msg.replace('.', ' (' + AURORA.currencyAsOf + ').'); // real date only
        el.textContent = msg;
    }

    function applyRates(code, rate) {
        showDisclaimer(code);
        var sign = rate === 1 ? AURORA.currencySign : code + ' ';
        var pos  = rate === 1 ? AURORA.currencyPos : 'left';
        // Update all price elements that have data-base-price
        document.querySelectorAll('[data-base-price]').forEach(function(el) {
            var base = parseFloat(el.dataset.basePrice);
            el.textContent = formatMoney(base * rate, sign, pos, rate === 1 ? null : code);
        });
    }

    // Mark all price elements and apply initial state
    document.querySelectorAll('.au-item-price, .au-price').forEach(function(el) {
        if (!el.dataset.basePrice) {
            // Extract numeric value from text
            var txt = el.textContent.replace(/[^0-9.]/g, '');
            if (txt) el.dataset.basePrice = txt;
        }
    });

    if (activeCur !== AURORA.baseCurrency) {
        applyRates(activeCur, activeRate);
        curLabel.textContent = activeCur;
    }
})();
</script>
@endpush

@push('scripts_at_bottom')
@endpush

@push('scripts_at_bottom')
{{-- Address map picker + clear-saved-data wiring. Aurora is a shared-layout theme,
     so window.mbMapPicker / window.mbClearDeviceData are already global (layout
     @includes the engines). We only drive them with aurora's own sheet + controls. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var trigger = document.getElementById('au-addr-map-btn');
    if (!window.mbMapPicker) { if (trigger) { trigger.style.display = 'none'; } return; }
    var sheet = document.getElementById('au-map-sheet');
    var scrim = document.getElementById('au-map-scrim');
    /* Drive aurora's native right-edge drawer (mirrors the theme's own openSheet/closeSheet:
       toggles .open on sheet + scrim). Locks body scroll on open; leaves it to the cart
       drawer to restore on close, since the picker only opens from inside the open cart. */
    function toggle(open) {
        if (!sheet) { return; }
        if (open) {
            sheet.classList.add('open'); sheet.setAttribute('aria-hidden', 'false');
            if (scrim) { scrim.classList.add('open'); }
            document.body.style.overflow = 'hidden';
        } else {
            sheet.classList.remove('open'); sheet.setAttribute('aria-hidden', 'true');
            if (scrim) { scrim.classList.remove('open'); }
        }
    }
    window.mbMapPicker({
        trigger: trigger,
        modal:   sheet,
        map:     document.getElementById('au-map-canvas'),
        input:   document.getElementById('au-cust-addr'),
        lat:     document.getElementById('au-cust-lat'),
        lng:     document.getElementById('au-cust-lng'),
        preview: document.getElementById('au-map-preview'),
        confirm: document.getElementById('au-map-confirm'),
        close:   [document.getElementById('au-map-close'), scrim],
        toggle:  toggle,
        i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
    });
})();
(function () {
    var btn = document.getElementById('au-clear-data');
    if (!btn) { return; }
    var wrap = btn.closest('.au-device-data');
    if (!window.mbClearDeviceData) { if (wrap) { wrap.style.display = 'none'; } return; }
    var slug = @json($store->slug ?? '');
    btn.addEventListener('click', function () {
        window.mbClearDeviceData({
            keys: [
                'aurora_cart_' + slug,
                'aurora_favorites_' + slug,
                'au_recent_addrs_' + slug,
                'aurora_reorder_pending_' + slug,
                'aurora_reorder_notice_' + slug,
                'au_recent_orders_' + slug
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
