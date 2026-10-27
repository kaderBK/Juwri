{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       Data preparation for Feast Theme
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus = collect($total_menus)->values();

    // Branch availability/pricing is applied in the controller (new branch_id system).
    $items = $allMenus->values();
    $byCat = $items->groupBy('category_id');
    // Every category, flattened parent-then-children. Items are keyed by their own
    // category_id — which may be a subcategory id — so iterating menu_categories
    // (top-level only) would drop every subcategory item. _filled also drops the
    // categories with no active item, so no chip opens an empty grid.
    // See Post::menu_categories_flat_filled.
    $categories = $store->menu_categories_flat_filled;

    /* Two-tier category nav (parent chips + a per-parent sub-category row). Ported
       from plate. $categories (flat_filled) still drives the server-rendered menu
       sections; the NAV is built from top-level parents + their item-bearing subs.
       $rootOf maps any category id → its top-level ancestor (parent chip filter);
       each item's real category_id drives the sub-chip filter. */
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
    // Descendant item count per top-level ancestor (drives which parent chips show).
    $rootItemCount = [];
    foreach ($categories as $c) {
        $cnt = ($byCat[$c->id] ?? collect())->count();
        $r   = $rootOf[$c->id] ?? $c->id;
        $rootItemCount[$r] = ($rootItemCount[$r] ?? 0) + $cnt;
    }
    // Primary chips: top-level parents that hold at least one descendant item.
    $navCats = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);
    // Secondary chips: per parent, only the subcategories that own items.
    $subCatsOf = [];
    foreach ($navCats as $c) {
        $subs = $c->subcategories->filter(fn($s) => ($byCat[$s->id] ?? collect())->count() > 0)->values();
        if ($subs->isNotEmpty()) {
            $subCatsOf[$c->id] = $subs;
        }
    }

    /* checkout feature gates (parity with plate/crave) */
    $allowCoupons = @$postOptions->allow_coupons == 1;
    $allowTips    = @$postOptions->allow_tips == 1;
    $allowWaiter  = !empty($postOptions->allow_call_waiter);
    $allowDietary = !isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;
    /* R4 · open-now chip (mirrors crave): the store's manual open/closed switch.
       Empty/'' counts as open so a store that never touched the switch reads open. */
    $isOpen       = store_open_now($store);

    /* menu_sections visibility (specials rail + splash gate) */
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

    /* bespoke splash imagery (first 3 real-photo dishes) */
    $splashImgs = \Juwri\Core\Support\ViewHelpers::imageItems($items, 3);

    /* today's-specials rail = daily specials first, fall back to popular (crave/vivid contract) */
    $specials = \Juwri\Core\Support\ViewHelpers::flagged($items, 'is_daily_special', 8);
    if ($specials->count() < 1) $specials = \Juwri\Core\Support\ViewHelpers::flagged($items, 'is_popular', 8);

    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)
    $deliveryCharge    = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge         = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    /* TOTALS CONTRACT (FEAST-F1). `restaurant_tax_type` has two modes:
       'percentage' → charge is a rate applied to the RAW subtotal;
       'fixed'      → charge is a flat amount, NOT a rate.
       Rounding follows the STORE's currency, not the global one — Order::computeTax()
       uses currency_decimals($postOptions->currency_code). feast shipped neither:
       it never sent the tax to the client and never added it to the quoted total. */
    $taxType           = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');
    $taxLabel          = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
    $curDecimals       = currency_decimals(@$postOptions->currency_code ?: null);
    $minOrderValue     = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched   = !empty($postOptions->allow_order_scheduling);
    
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
                    'img'   => $e->image ? media_url('storage/menu/', $e->image, $store->id ?? null) : '',
                ];
            }
        }

        $jsItems[$m->id] = [
            'id'             => $m->id,
            'cat'            => $m->category_id,
            'root'           => $rootOf[$m->category_id] ?? $m->category_id,
            'name'           => $m->name,
            'desc'           => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'img'            => $m->image && $m->image !== 'default.png' ? asset('storage/menu/'.$m->image) : '',
            'price'          => (float) $m->price,
            'original_price' => ($m->original_price ?? null), // pre-happy-hour price, for a cart strike-through
            'type'           => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'         => $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : null,
            'rating_count'   => $m->rating_count ?? 0,
            'allergies'      => \Juwri\Core\Support\ViewHelpers::allergyListAsset($itemAllergies),
            'variants'       => $variants,
            /* FEAST-F9 · the variant group used to be headed with the CTA label
               ("Add to Order"). Use the real MenuVariantOption group name — "Style"
               on this store — and fall back to a neutral prompt when it has none. */
            'variantGroup'   => (string) (optional($m->variantOptions->first())->title ?: ''),
            'extras'         => $extras,
            'is_vegan'       => (bool) $m->is_vegan,
            'is_halal'       => (bool) $m->is_halal,
            'is_gluten_free' => (bool) $m->is_gluten_free,
            'is_popular'     => (bool) $m->is_popular,
            'is_daily_special' => (bool) ($m->is_daily_special ?? false),
            'spice_level'    => $m->spice_level ?? 'none',
            'spice_num'      => (int) $m->spice_level,
            'is_sold_out'    => (bool) $m->is_sold_out || ($m->track_inventory && $m->stock_qty <= 0),
            'calories'       => $m->calories ?? null,
            'portion_size'   => $m->portion_size ?? null,
            'gallery'        => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryAssets($m->gallery_images)
                : [],
            'optionGroups'   => \Juwri\Core\Support\ViewHelpers::optionGroups($m->globalGroups ?? []),
        ];
    }

    /* customer + engagement extras (parity build — feast gains a customer layer) */
    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();
    /* Store credit / wallet balance for the signed-in diner. Mirrors crave
       (index.blade.php:170) + plate (index.blade.php:206). Drives the
       "Pay with store credit" checkout control, which posts use_wallet=1 —
       the shared submitOrder concern applies the balance. 0 when guest. */
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;
    $customerAddresses = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        : collect();
    $customerDefaultAddress = $customerAddresses->first();
    $addrLabel = \Juwri\Core\Support\ViewHelpers::addressLabel(...);
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));
    $myWaitlist = storefront()->activeWaitlist($store, $customer);
    $myReservation = storefront()->upcomingReservation($store, $customer);

    /* ── theme-pack-blade features (F1–F7) ──────────────────────────────
       F1/F2 hit the CustomerToken-guarded API; mint (and session-cache) a raw
       token for the signed-in session customer so the theme JS can call them. */
    $customerApiToken = storefront()->customerApiToken($customer, 'feast');

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
@endphp

@push('style_at_top')
    <!-- Google Fonts for Feast Theme (Plus Jakarta Sans + Inter) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- F4 · self-hosted Leaflet (CSP script-src is self+nonce; tiles load as https imgs) --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    {{-- FEAST-F5 · apply the stored appearance BEFORE first paint so a dark-face
         customer never gets a white flash. Kept inline and tiny on purpose. --}}
    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var f = localStorage.getItem('ff_face_' + @json($store->id));
                if (f === 'dark' || f === 'light') document.documentElement.setAttribute('data-ff-face', f);
            } catch (e) {}
            /* R2 · splash session-gate. If the splash was already shown this session,
               mark the root BEFORE first paint so the full-screen intro never flashes
               on a repeat navigation; script.js sets the key on the first view. */
            try {
                if (sessionStorage.getItem('ff_splash_' + @json($store->slug)))
                    document.documentElement.classList.add('ff-splash-seen');
            } catch (e) {}
            /* FEAST-F16 · marks that JS is alive, which is what arms the
               scroll-reveal. Without it the menu renders plainly instead of
               staying at opacity 0 forever. */
            document.documentElement.classList.add('ff-js');
        })();
    </script>
@endpush

@section('content')
<div id="feast-app" class="feast-container {{ current_language()->direction === 'rtl' ? 'rtl-dir' : 'ltr-dir' }}">

    {{-- ===== Bespoke splash (auto-dismisses via CSS; skip button = progressive enhancement). ===== --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="ff-splash" id="ff-splash" role="presentation" aria-hidden="true">
        <div class="ff-splash-inner">
            @if($store->main_image)
                <img class="ff-splash-logo" src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="" data-csp-error="hide">
            @endif
            <div class="ff-splash-hero" aria-hidden="true">
                <i class="ff-splash-ring"></i>
                @foreach($splashImgs as $si)
                    <img class="ff-splash-pop ff-splash-pop-{{ $loop->index }}"
                         src="{{ asset('storage/menu/'.$si->image) }}" alt="" data-csp-error="remove">
                @endforeach
            </div>
            <h1 class="ff-splash-title">{{ $store->title }}</h1>
            <p class="ff-splash-tag">{{ $store->sub_title ?: ___('Digital Menu') }}</p>
        </div>
        <button class="ff-splash-skip" id="ff-splash-skip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- ===== App top bar: delivery address + account/cart ===== --}}
    <div class="ff-appbar" id="ff-appbar">
        <div class="ff-appbar-inner">
            <div class="ff-appbar-loc">
                <span class="ff-appbar-eyebrow">{{ ___('Delivering from') }}</span>
                <span class="ff-appbar-addr">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1116 0z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    <b>{{ Str::limit($store->address ?: $store->title, 26) }}</b>
                </span>
            </div>
            <div class="ff-appbar-actions">
                {{-- FEAST-F5/F15 · feast shipped no appearance control and no language
                     control at all, so the dark face was unreachable and a diner reading
                     Arabic had no way into it from the menu. Both live in the app bar so
                     they are reachable by guests too, and both persist across pages
                     (localStorage / the /lang session). --}}
                <button type="button" id="ff-face-toggle" class="ff-icon-btn" aria-label="{{ ___('Appearance') }}" aria-pressed="false">
                    <svg class="ff-face-ic-dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M20.5 14.4A8.5 8.5 0 1 1 9.6 3.5a6.8 6.8 0 0 0 10.9 10.9Z"/></svg>
                    <svg class="ff-face-ic-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.6 12h2.2M19.2 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></svg>
                </button>
                {{-- Show only the store's own menu languages (like every other theme),
                     not every active site language: offering a language the menu is not
                     translated into just drops the diner onto an untranslated page. --}}
                @if(!empty($menu_languages) && count($menu_languages) > 1)
                    <div class="ff-lang-wrap">
                        <button type="button" id="ff-lang-toggle" class="ff-icon-btn" aria-label="{{ ___('Language') }}" aria-expanded="false">
                            <span class="ff-lang-code">{{ strtoupper(get_lang()) }}</span>
                        </button>
                        <div class="ff-lang-menu" id="ff-lang-menu" hidden>
                            @foreach($menu_languages as $language)
                                <a href="{{ url('lang/'.$language->code) }}" data-lang-code="{{ $language->code }}" data-lang-name="{{ $language->name }}" class="ff-lang-item {{ get_lang() === $language->code ? 'is-on' : '' }}" dir="auto">{{ $language->name }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif
                {{-- Multi-currency switcher (parity with velora). Display-only: it
                     converts what the diner SEES; totals and the order payload stay in
                     the store's base currency. Guest-reachable, like the appbar's other
                     prefs. Choice persists store-scoped via _shared/js/mb-currency.js. --}}
                @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
                    <div class="ff-cur-wrap">
                        <button type="button" id="ff-cur-toggle" class="ff-icon-btn" aria-label="{{ ___('Currency') }}" aria-expanded="false">
                            <span class="ff-cur-code" id="ff-cur-code">{{ config('settings.currency_code', 'USD') }}</span>
                        </button>
                        <div class="ff-cur-menu" id="ff-cur-menu" hidden>
                            <div class="ff-seg-mini" id="curSeg">
                                <button data-cur="{{ config('settings.currency_code', 'USD') }}" data-rate="1" class="is-on" type="button">{{ config('settings.currency_code', 'USD') }}</button>
                                @foreach($mcRates as $code => $rate)<button data-cur="{{ $code }}" data-rate="{{ $rate }}" type="button">{{ $code }}</button>@endforeach
                            </div>
                        </div>
                    </div>
                @endif
                @if($authMode !== 'guest_only')
                    @if($customer)
                        <button type="button" id="ff-account-open" class="ff-icon-btn" aria-label="{{ ___('My Account') }}">
                            <span class="ff-account-avatar">{{ Str::upper(Str::substr($customer->name ?? 'G', 0, 1)) }}</span>
                        </button>
                    @else
                        <button type="button" id="ff-auth-open" class="ff-icon-btn" aria-label="{{ ___('Login') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </button>
                    @endif
                @endif
                @if(storefront_catering_enabled($store->id))
                {{-- feast is a scrolling theme (no sheet framework); the catering form
                     stays inline and this header button jumps to it. data-catering-open
                     still fires the shared guest-autofill. --}}
                <a id="cvCateringBtn" class="ff-icon-btn" href="#catering" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                </a>
                @endif
                <button type="button" id="mbNotifBell" class="ff-icon-btn" aria-label="{{ ___('Notifications') }}" style="position:relative">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                    <i id="mbNotifDot" hidden style="position:absolute;top:5px;inset-inline-end:5px;width:9px;height:9px;border-radius:50%;background:var(--ff-accent,#ff5a3c);border:2px solid var(--ff-surface,#12121a)"></i>
                </button>
            </div>
        </div>
    </div>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


    {{-- ===== Hero restaurant card ===== --}}
    <header class="ff-hero ff-reveal">
        <div class="ff-hero-cover">
            @if($store->cover_image)
                <img src="{{ asset('storage/store/cover/'.$store->cover_image) }}" alt="" class="ff-hero-cover-img" data-csp-error="hide">
            @endif
            <div class="ff-hero-scrim"></div>
        </div>
        <div class="ff-hero-body">
            <div class="ff-hero-top">
                {{-- FEAST-F17 · these stores carry `default.png`, which 404s, and the old
                     onerror just hid the <img> and left a blank circle. A monogram in the
                     brand colour is the designed fallback — the 404 IS the visible state
                     on most real stores. --}}
                <span class="ff-hero-logo ff-logo-mono" aria-hidden="true">{{ Str::upper(Str::substr($store->title, 0, 1)) }}
                    @if($store->main_image)
                        <img src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="{{ $store->title }}" data-csp-error="remove">
                    @endif
                </span>
                <div class="ff-hero-titles">
                    <h1 class="ff-hero-title" data-tc-text="texts.hero_title">{{ theme_setting($store->id, 'texts.hero_title', '') ?: $store->title }}</h1>
                    @if(theme_setting($store->id, 'texts.hero_subtitle', '') ?: $store->sub_title)
                        <p class="ff-hero-sub" data-tc-text="texts.hero_subtitle">{{ theme_setting($store->id, 'texts.hero_subtitle', '') ?: $store->sub_title }}</p>
                    @endif
                </div>
            </div>

            <div class="ff-hero-chips">
                {{-- R4 · open/closed status chip --}}
                <span class="ff-chip ff-open-chip {{ $isOpen ? '' : 'is-closed' }}" data-mb-openchip data-mb-closed-class="is-closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
                @if($store->address)
                    <span class="ff-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1116 0z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ Str::limit($store->address, 30) }}</span>
                @endif
                @if($store->phone)
                    <a class="ff-chip" href="tel:{{ $store->phone }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 5a2 2 0 012-2h3.3a1 1 0 01.95.73l.55 2.2a1 1 0 01-.32.99l-1.3.98a10.6 10.6 0 004.87 4.87l.98-1.3a1 1 0 01.99-.32l2.2.55a1 1 0 01.73.94V19a2 2 0 01-2 2h-1C9.7 21 3 14.3 3 6z"/></svg>{{ $store->phone }}</a>
                @endif
                {{-- B2: the "N viewing now" proof moved into the brand/follow row
                     (single source of proof — see the follower-count partial). --}}
            </div>

            @if($store->description)
                <p class="ff-hero-desc">{{ html_entity_decode(strip_tags($store->description), ENT_QUOTES) }}</p>
            @endif

            @include('store_templates.partials.branch-switcher')
        </div>
    </header>

    {{-- ===== F2 · brand / follow row (B10: customizer section, default on) ===== --}}
    @if($sectionVisible['brand_row'] ?? true)
    <div class="ff-brand-row" id="cvBrandRow" data-tc-section="brand_row" data-mb-hotel-chip-slot>
        <span class="ff-brand-logo ff-logo-mono">{{ Str::upper(Str::substr($store->title, 0, 1)) }}
            @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
        </span>
        <span class="ff-brand-info">
            <b class="ff-brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="ff-verified-badge" id="cvVerifiedBadge" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
            @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'scansToday' => $scans_today ?? 0, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true)])
        </span>
        {{-- Following needs a customer account: on a guest_only store the auth sheet is
             never rendered, so openAuth() does not exist and the click handler returns
             silently. Hide the control instead of shipping a button that cannot do
             anything. --}}
        @if($authMode !== 'guest_only' || $customer)
        <button class="ff-followbtn {{ $isFollowing ? 'is-on' : '' }}" id="cvFollowBtn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
            <svg class="ff-ic-xs" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
            <span class="ff-follow-label" data-tc-text="texts.follow_label">{{ theme_setting($store->id, 'texts.follow_label', ___('Follow')) }}</span>
            <span class="ff-following-label">{{ ___('Following') }}</span>
        </button>
        @endif
    </div>
    @endif

    {{-- ===== F3 · stories rail (populated from /r/{slug}/stories) ===== --}}
    <div class="ff-stories-rail" id="cvStoriesRail" hidden></div>

    {{-- ===== Promo banners ===== --}}
    <div class="ff-banners">
        @include('store_templates.includes.menu-banners-slider')
    </div>

    {{-- ===== Today's specials rail (is_daily_special → is_popular) ===== --}}
    @if($specials->count() > 2 && $sectionVisible['daily_specials'])
    <section class="ff-specials" id="ff-specials">
        <div class="ff-specials-head">
            <span class="ff-specials-kicker">✦ {{ ___('Handpicked') }}</span>
            <h2 class="ff-specials-title" data-tc-text="texts.featured_title">{{ theme_setting($store->id, 'texts.featured_title', ___("Today's specials")) }}</h2>
        </div>
        <div class="ff-specials-rail">
            @foreach($specials as $sp)
                @php $spSold = $jsItems[$sp->id]['is_sold_out']; @endphp
                <div class="ff-special-card ff-reveal {{ $spSold ? 'ff-sold-out' : '' }}"
                     @if(!$spSold) data-open-item="{{ $sp->id }}" role="button" tabindex="0" @endif>
                    <div class="ff-special-media">
                        @if($sp->image && $sp->image !== 'default.png')
                            <img src="{{ asset('storage/menu/'.$sp->image) }}" alt="{{ $sp->name }}" loading="lazy" data-csp-error="closest-class" data-csp-closest=".ff-special-media" data-csp-class="ff-special-media-empty" data-csp-remove="true">
                        @endif
                        @if($spSold)<span class="ff-special-soldout">{{ ___('Sold Out') }}</span>@endif
                    </div>
                    <div class="ff-special-body">
                        <h4 class="ff-special-name">{{ $sp->name }}</h4>
                        <span class="ff-special-price" data-ff-price="{{ $sp->price }}">{{ price_symbol_format($sp->price) }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    {{-- ===== Sticky search + two-tier category nav ===== --}}
    <div class="ff-nav-bar-sticky" id="ff-sticky-nav">
        <div class="ff-nav-bar-inner">
            @if(theme_setting($store->id, 'search.enabled', true))
            <div class="ff-search-box">
                <svg class="ff-search-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                {{-- SEARCH-RUN: orbit-style — this bar opens a full-screen search
                     overlay (markup below the tabbar) with its own live result rows. --}}
                <button type="button" class="ff-search-trigger" id="ff-search-open">
                    <span class="ff-search-ph" data-tc-text="texts.search_placeholder">{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dish, ingredients...')) }}</span>
                </button>
                @if($allowDietary)
                <button type="button" class="ff-filter-btn" id="ff-filter-btn" aria-label="{{ ___('Filters') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 8h14M5 12h10M5 16h6"/></svg>
                    <i class="ff-filter-dot" id="ff-filter-dot" hidden></i>
                </button>
                @endif
            </div>
            @endif

            @if($sectionVisible['catbar'] && $navCats->count() > 0)
                {{-- Primary (parent) category chips. A parent chip shows every descendant
                     item; data-has-sub marks parents that own a second sub-chip row. --}}
                {{-- data-mb-grid-lead: the category selector must touch the items it
                     filters — injected sections (offers strip, order-type bar) climb
                     above these rows instead of wedging between categories and grid. --}}
                <nav class="ff-categories-nav" aria-label="{{ ___('Categories') }}" data-mb-grid-lead data-mbt-anchor>
                    <div class="ff-categories-scroll" id="ff-cats" data-mb-grid-lead>
                        <button class="ff-cat-btn active" data-cat="all" type="button">
                            <span class="ff-cat-thumb"><span class="ff-cat-emoji">≡</span></span>
                            <span class="ff-cat-label">{{ ___('All') }}</span>
                        </button>
                        @foreach($navCats as $cat)
                            @php $catImg = $cat->image ?? null; @endphp
                            <button class="ff-cat-btn" data-cat="{{ $cat->id }}"
                                    @isset($subCatsOf[$cat->id]) data-has-sub="1" @endisset type="button">
                                {{-- FEAST-F8 · the old onerror only hid the <img> and coloured the
                                     empty tile, so a 404 category image left the whole rail as
                                     blank coral squares (all four categories on this store). The
                                     initial fallback is now always in the DOM behind the image and
                                     is simply revealed when the image fails. --}}
                                <span class="ff-cat-thumb">
                                    <span class="ff-cat-emoji">{{ Str::upper(Str::substr($cat->name, 0, 1)) }}</span>
                                    @if($catImg && $catImg !== 'default.png')
                                        @if((string) (media_url('storage/menu/', $catImg, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $catImg, $store->id ?? null) }}" alt="" loading="lazy" data-csp-error="remove">@endif
                                    @endif
                                </span>
                                <span class="ff-cat-label">{{ $cat->name }}</span>
                            </button>
                        @endforeach
                    </div>
                    {{-- Secondary sub-category chips: one hidden group per parent that owns
                         item-bearing subcategories; JS reveals the active parent's group.
                         data-sub="root" = every descendant of the parent. --}}
                    @if(!empty($subCatsOf))
                    <div class="ff-subcats" id="ff-subcats" aria-label="{{ ___('Sub-categories') }}" hidden data-mb-grid-lead>
                        @foreach($subCatsOf as $parentId => $subs)
                            <div class="ff-subgroup" data-parent="{{ $parentId }}" hidden>
                                <button class="ff-subcat is-on" data-sub="root" type="button">{{ ___('All') }}</button>
                                @foreach($subs as $sub)
                                    <button class="ff-subcat" data-sub="{{ $sub->id }}" type="button">{{ $sub->name }}</button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    @endif
                </nav>
            @endif
        </div>
    </div>

    {{-- ===== Menu ===== --}}
    <main class="ff-menu-main">
        @forelse($categories as $cat)
            @php $catItems = $byCat[$cat->id] ?? collect(); @endphp
            @if($catItems->count())
                <section class="ff-menu-section" data-section-category-id="{{ $cat->id }}"
                         data-cat="{{ $cat->id }}" data-root="{{ $rootOf[$cat->id] ?? $cat->id }}">
                    <div class="ff-section-head">
                        <h2 class="ff-category-title">{{ $cat->name }}</h2>
                        <span class="ff-section-count">{{ $catItems->count() }}</span>
                    </div>
                    <div class="ff-menu-grid">
                        @foreach($catItems as $menu)
                            @php
                                $isSoldOut = (bool) $menu->is_sold_out || ($menu->track_inventory && $menu->stock_qty <= 0);
                                $hasImg = $menu->image && $menu->image !== 'default.png';
                            @endphp
                            <div class="ff-menu-card ff-reveal {{ $isSoldOut ? 'ff-sold-out' : '' }}"
                                 data-id="{{ $menu->id }}">
                                <button class="ff-fav-btn" type="button" data-fffav="{{ $menu->id }}" aria-label="{{ ___('Save') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
                                <div class="ff-card-body">
                                    <div class="ff-card-labels">
                                        @if($menu->type === 'veg' || $menu->is_vegan)
                                            <span class="ff-label-tag ff-label-veg" title="{{ ___('Vegetarian') }}"></span>
                                        @elseif($menu->type === 'nonveg')
                                            <span class="ff-label-tag ff-label-nonveg" title="{{ ___('Non-Vegetarian') }}"></span>
                                        @endif
                                        @if($menu->is_popular)
                                            <span class="ff-tag ff-tag-pop">★ {{ ___('Popular') }}</span>
                                        @endif
                                        @if(!empty($menu->schedule_label))
                                            {{-- Happy-hour offer — the strike (ff-card-was) rendered but the
                                                 label was missing, so the discount had no caption. --}}
                                            <span class="ff-tag ff-tag-hh" style="background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff">{{ $menu->schedule_label }}</span>
                                        @endif
                                    </div>
                                    <h3 class="ff-card-name"><a href="?item={{ $menu->id }}" style="color:inherit;text-decoration:none">{{ $menu->name }}</a></h3>
                                    @if($menu->description)
                                        <p class="ff-card-desc">{{ Str::limit(html_entity_decode(strip_tags($menu->description), ENT_QUOTES), 90) }}</p>
                                    @endif
                                    <div class="ff-card-meta">
                                        <span class="ff-pricewrap">
                                        @if(!is_null($menu->original_price) && (float) $menu->original_price > (float) $menu->price)
                                            {{-- K8: day-part / happy-hour reference price (MenuScheduleService::decorate) --}}
                                            <del class="ff-card-was" style="opacity:.55;font-weight:400;margin-inline-end:.35em" data-ff-price="{{ $menu->original_price }}">{{ price_symbol_format($menu->original_price) }}</del>
                                        @endif
                                        <span class="ff-card-price" data-ff-price="{{ $menu->price }}">{{ price_symbol_format($menu->price) }}</span>
                                        </span>
                                        @if($menu->rating_avg && $menu->rating_avg > 0)
                                            <span class="ff-card-rating">★ {{ number_format($menu->rating_avg, 1) }}</span>
                                        @endif
                                        @if($menu->calories)
                                            <span class="ff-card-kcal">{{ $menu->calories }} kcal</span>
                                        @endif
                                    </div>
                                    <div class="ff-card-tags">
                                        @if($menu->is_halal)<span class="ff-tag">{{ ___('Halal') }}</span>@endif
                                        @if($menu->is_gluten_free)<span class="ff-tag">{{ ___('Gluten-Free') }}</span>@endif
                                    </div>
                                </div>

                                <div class="ff-card-media">
                                    @if($hasImg)
                                        <img src="{{ asset('storage/menu/'.$menu->image) }}" alt="{{ $menu->name }}" class="ff-card-img" loading="lazy" data-csp-error="closest-class" data-csp-closest=".ff-card-media" data-csp-class="ff-card-media-empty">
                                    @endif
                                    @if($isSoldOut)
                                        <span class="ff-soldout-label">{{ ___('Sold Out') }}</span>
                                    @else
                                        <button class="ff-quick-add-btn" data-add-id="{{ $menu->id }}" aria-label="{{ theme_setting($store->id, 'texts.add_to_cart', ___('Add to Order')) }}">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @empty
            <div class="ff-no-items">
                <p>{{ ___('No menu items available.') }}</p>
            </div>
        @endforelse
        {{-- shown by JS when a category/search/dietary filter matches nothing --}}
        <div class="ff-no-items" id="ff-no-match" hidden>
            <p>{{ ___('Nothing on the menu matches') }}</p>
        </div>
        <div class="ff-tab-spacer"></div>

        {{-- TP: catering request form (gated) — opens as a shared modal from the
             header button; no longer sits inline below the menu. --}}
        @include('store_templates._partials.catering-form', ['cateringModal' => true, 'cateringSurface' => 'var(--tc-surface, #ffffff)', 'cateringInk' => 'var(--tc-text, #14161c)'])

        {{-- Reserve / Join-the-waitlist CTA — kept at the end of the menu content so
             the shared info footer (mbInfoFoot, appended last by placeBottom) stays
             the last section on the page. --}}
        @if($reservationsEnabled || $waitlistEnabled)
        <div class="ff-booking-strip">
            @if($reservationsEnabled)
            <button type="button" id="ff-res-open" class="ff-booking-btn">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>
                <span>{{ ___('Reserve a Table') }}</span>
            </button>
            @endif
            @if($waitlistEnabled)
            <button type="button" id="ff-waitlist-open" class="ff-booking-btn">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <span>{{ ___('Join the Waitlist') }}</span>
            </button>
            @endif
        </div>
        @endif

    </main>

    {{-- ===== Glassy bottom tab bar ===== --}}
    <nav class="ff-tabbar" id="ff-tabbar">
        <button type="button" class="ff-tab is-on" data-tab="home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg>
            <span>{{ ___('Home') }}</span>
        </button>
        <button type="button" class="ff-tab" data-tab="menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16M4 12h16M4 19h10"/></svg>
            <span data-tc-text="texts.menu_title">{{ theme_setting($store->id, 'texts.menu_title', ___('Menu')) }}</span>
        </button>
        <button type="button" class="ff-tab" data-tab="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <span>{{ ___('Search') }}</span>
        </button>
        @if($allow_order)
        <button type="button" class="ff-tab" data-tab="cart">
            <span class="ff-tab-cart-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 01-8 0"/></svg>
                <span class="ff-tab-cart-badge" id="ff-tab-cart-badge" style="display:none">0</span>
            </span>
            <span data-tc-text="texts.cart_button">{{ theme_setting($store->id, 'texts.cart_button', ___('Cart')) }}</span>
        </button>
        @endif
        <button type="button" class="ff-tab" data-tab="favs">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
            <span>{{ ___('Favorites') }}</span>
        </button>
        <button type="button" class="ff-tab" data-tab="account">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>{{ ___('Account') }}</span>
        </button>
        @if($reservationsEnabled || $waitlistEnabled)
        <button type="button" class="ff-tab" data-tab="reserve">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/></svg>
            <span>{{ ___('Reserve') }}</span>
        </button>
        @endif
    </nav>

    {{-- ══════════ SEARCH OVERLAY (orbit-style) ══════════ --}}
    @if(theme_setting($store->id, 'search.enabled', true))
    <div class="ff-searchlayer" id="ff-search-layer" hidden>
        <div class="ff-sl-top">
            <label class="ff-sl-field">
                <svg class="ff-search-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input id="ff-sl-input" type="search" placeholder="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search dish, ingredients...')) }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
            </label>
            <button class="ff-sl-cancel" id="ff-sl-close" type="button">{{ ___('Cancel') }}</button>
        </div>
        <div class="ff-sl-results" id="ff-sl-results" data-none="{{ ___('No dishes match your search') }}"></div>
    </div>
    @endif

    {{-- Favorites drawer (feast shipped with no favourites feature; hearts feed this). --}}
    <div id="ff-fav-drawer" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="ff-fav-backdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <div class="ff-drawer-drag-handle"></div>
                <button class="ff-drawer-close" id="ff-fav-close" aria-label="Close">&times;</button>
            </div>
            <div class="ff-drawer-content">
                <h2 class="ff-fav-title">{{ ___('Favorites') }}</h2>
                <div id="ff-fav-list"></div>
                <div class="ff-fav-empty" id="ff-fav-empty">
                    <p>{{ ___('No favorites yet') }}</p>
                    <span>{{ ___('Tap the heart on any dish to save it here.') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Product Detail Drawer (Bottom Sheet) --}}
    <div id="ff-detail-drawer" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="ff-detail-backdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <div class="ff-drawer-drag-handle"></div>
                <button class="ff-drawer-close" id="ff-detail-close" aria-label="Close">&times;</button>
            </div>
            <div class="ff-drawer-content" id="ff-detail-content">
                {{-- Dynamic content injected via JS --}}
            </div>
        </div>
    </div>

    {{-- Floating Cart Toggle Button --}}
    @if($allow_order)
        <button id="ff-cart-toggle" class="ff-cart-floating-btn" style="display: none;" aria-label="View Cart">
            <span class="ff-cart-icon-wrap">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 01-8 0"></path>
                </svg>
                <span class="ff-cart-badge">0</span>
            </span>
            {{-- FEAST-F18 · this used to be a bare unlabelled number that a customer
                 reads as "the total" — it is the SUBTOTAL, before tax, delivery,
                 tip and discount. Say so. --}}
            <span class="ff-cart-btn-money">
                <small>{{ ___('Subtotal') }}</small>
                <span class="ff-cart-btn-price"></span>
            </span>
        </button>
    @endif

    {{-- Cart & Checkout Drawer --}}
    <div id="ff-cart-drawer" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="ff-cart-backdrop"></div>
        <div class="ff-drawer-sheet ff-cart-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('Your Selection') }}</h2>
                <button class="ff-drawer-close" id="ff-cart-close" aria-label="Close">&times;</button>
            </div>
            
            <div class="ff-drawer-content">
                {{-- R5 · guest order history: JS lists this browser's past orders (localStorage)
                     with track links, so a guest can find a recent order without logging in.
                     Signed-in customers have the account drawer's Order History instead. --}}
                @if(!$customer)
                <div class="ff-recent-orders" id="ff-recent-orders" hidden>
                    <span class="ff-recent-orders-title">{{ ___('Your recent orders') }}</span>
                    <div class="ff-recent-orders-list" id="cvLookupResults"></div>
                </div>
                @endif

                {{-- Cart Items --}}
                <div id="ff-cart-items-list" class="ff-cart-list"></div>

                {{-- Coupon / Discount (gated on allow_coupons) --}}
                @if($allowCoupons)
                <div class="ff-coupon-section">
                    <div class="ff-coupon-input-wrap">
                        <input aria-label="{{ ___('Coupon Code') }}" type="text" id="ff-coupon-code" placeholder="{{ ___('Coupon Code') }}" autocomplete="off">
                        <button id="ff-coupon-apply-btn" type="button">{{ ___('Apply') }}</button>
                    </div>
                    <div id="ff-coupon-msg" class="ff-coupon-message"></div>
                </div>
                @endif

                {{-- Tip (gated on allow_tips) --}}
                @if($allowTips)
                <div class="ff-tip-section">
                    <span class="ff-tip-label">{{ ___('Add a tip') }} ({{ ___('optional') }})</span>
                    <div class="ff-tip-row" id="ff-tip-row">
                        <button data-tip="0" class="is-on" type="button">{{ ___('None') }}</button>
                        <button data-tip="10" type="button">10%</button>
                        <button data-tip="15" type="button">15%</button>
                        <button data-tip="20" type="button">20%</button>
                        <button data-tip="custom" type="button">{{ ___('Custom') }}</button>
                    </div>
                    <input aria-label="{{ ___('Custom tip amount') }}" type="number" id="ff-custom-tip" placeholder="{{ ___('Custom tip amount') }}" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}" hidden>
                </div>
                @endif

                {{-- Store credit / wallet (self-gates: signed-in customer with balance>0).
                     Checkbox posts use_wallet=1 (crave/plate contract); the shared submitOrder
                     concern applies the balance server-side. Styled with feast's native
                     .ff-checkbox-option. --}}
                @if($customer && $storeCredit > 0)
                @php $ffCurGap = currency_symbol_gap($postOptions->currency_sign ?? '$'); @endphp
                <div class="ff-wallet-section" id="ff-wallet-section">
                    <label class="ff-checkbox-option ff-wallet-option">
                        <span class="ff-option-left">
                            <input type="checkbox" id="ff-use-wallet">
                            <span>{{ ___('Pay with store credit') }}</span>
                        </span>
                        <span class="ff-option-price">{{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }}</span>
                    </label>
                </div>
                @endif

                @if(!empty($postOptions->gift_cards_enabled))
                <div class="ff-coupon-section">
                    <div class="ff-coupon-input-wrap">
                        <input aria-label="{{ ___('Gift card code') }}" type="text" id="ff-gift-card-code" name="gift_card_code" placeholder="{{ ___('Gift card code') }}" autocomplete="off">
                    </div>
                </div>
                @endif

                {{-- Summary --}}
                <div class="ff-cart-summary">
                    <div class="ff-summary-row">
                        <span>{{ ___('Subtotal') }}</span>
                        <span id="ff-sum-subtotal">0.00</span>
                    </div>
                    <div class="ff-summary-row" id="ff-delivery-row" style="display:none;">
                        <span>{{ ___('Delivery Charge') }}</span>
                        <span id="ff-sum-delivery">0.00</span>
                    </div>
                    {{-- FEAST-F1 · the tax row. feast never had one, and never added tax to the
                         quoted total, so every order was under-quoted by the store's tax rate.
                         Order::getTotalAttribute() taxes the RAW subtotal, before the discount. --}}
                    <div class="ff-summary-row" id="ff-tax-row" style="display:none;">
                        <span>{{ $taxLabel }}</span>
                        <span id="ff-sum-tax">0.00</span>
                    </div>
                    <div class="ff-summary-row ff-sum-discount-row" id="ff-discount-row" style="display:none;">
                        <span>{{ ___('Discount') }}</span>
                        <span id="ff-sum-discount">0.00</span>
                    </div>
                    <div class="ff-summary-row" id="ff-tip-summary-row" style="display:none;">
                        <span>{{ ___('Tip') }}</span>
                        <span id="ff-sum-tip">0.00</span>
                    </div>
                    <div class="ff-summary-row ff-total-row">
                        <span>{{ ___('Total') }}</span>
                        <span id="ff-sum-total">0.00</span>
                    </div>
                </div>

                {{-- F1 · turn this cart into a recurring meal plan --}}
                @if($mealPlansEnabled)
                <button class="ff-mealplan-cta" id="cvMealPlanCta" type="button">
                    <span class="ff-mp-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M8 14h3M8 17h6"/></svg></span>
                    <span class="ff-mp-copy"><b>{{ ___('Make this a meal plan') }}</b><small>{{ ___('Get these dishes delivered on a schedule') }}</small></span>
                    <svg class="ff-mp-arr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                @endif

                {{-- Checkout Form --}}
                <form id="ff-checkout-form" class="ff-checkout-form">
                    @csrf
                    <label class="wa-optin" style="display:flex;align-items:center;gap:8px;margin:10px 0;font-size:.9em;">
                        <input type="checkbox" name="whatsapp_optin" value="1">
                        <span>{{ ___('Send me offers and updates on WhatsApp') }}</span>
                    </label>
                    <div class="ff-form-group">
                        <label for="ff-cust-name">{{ ___('Your Name') }} *</label>
                        <input type="text" id="ff-cust-name" name="name" required placeholder="{{ ___('e.g. John Doe') }}">
                    </div>

                    <div class="ff-form-group">
                        <label>{{ ___('Order Type') }} *</label>
                        <div class="ff-order-type-tabs">
                            @if($allow_on_table)
                                <button type="button" class="ff-tab-btn" data-type="on-table">{{ ___('Dine-In') }}</button>
                            @endif
                            @if($allow_takeaway)
                                <button type="button" class="ff-tab-btn" data-type="takeaway" data-tc-text="texts.pickup">{{ theme_setting($store->id, 'texts.pickup', ___('Takeaway')) }}</button>
                            @endif
                            @if($allow_delivery)
                                <button type="button" class="ff-tab-btn" data-type="delivery" data-tc-text="texts.delivery">{{ theme_setting($store->id, 'texts.delivery', ___('Delivery')) }}</button>
                            @endif
                            {{-- T1/H2 hotel room service. $allow_room_service is 1 only when hotel
                                 mode is on AND the scanned QR resolved to an area_type='room' table,
                                 so this tab never renders for an ordinary restaurant. --}}
                            @if($allow_room_service)
                                <button type="button" class="ff-tab-btn" data-type="room_service">{{ ___('Room service') }}</button>
                            @endif
                        </div>
                        <input type="hidden" id="ff-order-type-input" name="ordering-type" value="">
                    </div>

                    <div class="ff-form-group" id="ff-group-table" style="display:none;">
                        <label for="ff-table-num">{{ ___('Table Number') }} *</label>
                        {{-- $table is a StoreTable model, so echoing it directly stamps the
                             row's whole JSON into the box (and POSTs it as `table`). Print the
                             column, and lock the field when the QR already told us the table. --}}
                        <input type="text" id="ff-table-num" name="table" value="{{ $table->table_no ?? '' }}" {{ $table ? 'readonly' : '' }} placeholder="{{ ___('e.g. 5') }}">
                    </div>

                    {{-- Call the waiter (on-table only, gated on allow_call_waiter) --}}
                    @if($allowWaiter && $allow_on_table)
                    <div class="ff-waiter-row" id="ff-waiter-row" style="display:none;">
                        <button class="ff-waiter-btn" id="ff-call-waiter" type="button">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg>
                            <span>{{ ___('Call the waiter') }}</span>
                        </button>
                    </div>
                    @endif

                    <div class="ff-form-group" id="ff-group-phone" style="display:none;">
                        <label for="ff-phone-num">{{ ___('Phone Number') }} *</label>
                        <input type="tel" id="ff-phone-num" name="phone-number" placeholder="{{ ___('e.g. +123456789') }}">
                    </div>

                    <div class="ff-form-group" id="ff-group-address" style="display:none;">
                        <label for="ff-address">{{ ___('Delivery Address') }} *</label>
                        {{-- Saved addresses (logged-in): tap to fill the field + coords. --}}
                        @if($customerAddresses->count())
                        <div class="ff-saved-addrs" id="cvSavedAddrs">
                            @foreach($customerAddresses as $sa)
                            <button class="ff-saved-addr{{ $loop->first ? ' is-on' : '' }}" type="button"
                                    data-addr="{{ $addrLabel($sa) }}" data-lat="{{ $sa->lat }}" data-lng="{{ $sa->lng }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                                <span class="ff-sa-text"><b>{{ $sa->label ?: ___('Saved address') }}</b><small>{{ Str::limit($addrLabel($sa), 42) }}</small></span>
                            </button>
                            @endforeach
                        </div>
                        @else
                        {{-- R6 · guests get chips JS builds from addresses used before on this
                             device (localStorage) — renderRecentAddrs() fills this container. --}}
                        <div class="ff-saved-addrs" id="cvSavedAddrs" hidden></div>
                        @endif
                        {{-- F5 · address autocomplete + use-my-location --}}
                        <div class="ff-addr-field" id="cvAddrField">
                            <textarea id="ff-address" name="address" rows="2" placeholder="{{ ___('Your delivery address') }}" autocomplete="off" autocorrect="off" spellcheck="false">{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}</textarea>
                            <input type="hidden" id="cvCustLat" name="address_lat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                            <input type="hidden" id="cvCustLng" name="address_lng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                            <button class="ff-uselocbtn" id="cvUseLocation" type="button" title="{{ ___('Use my location') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                            </button>
                            <div class="ff-addr-suggest" id="cvAddrSuggest" hidden></div>
                        </div>
                        <p class="ff-geo-attrib" id="cvGeoAttrib" hidden></p>
                        <button class="ff-nearest-branch-btn" id="cvNearestBranchBtn" type="button">
                            <svg class="ff-ic-xs" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            {{ ___('Find nearest branch on map') }}
                        </button>
                    </div>

                    @if($allowOrderSched)
                    <div class="ff-form-group">
                        <label for="ff-scheduled-at">{{ ___('Schedule for later') }} ({{ ___('optional') }})</label>
                        <input type="datetime-local" id="ff-scheduled-at" name="scheduled_at"
                               min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}"
                               max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
                    </div>
                    @endif

                    {{-- T5: Curbside pickup (takeaway) --}}
                    @if(!empty($postOptions->curbside_enabled))
                    <div class="ff-form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="curbside" value="1" id="ff-curbside" data-csp-toggle="ff-curbside-fields">
                            <span>{{ ___("Curbside pickup — I'll wait in my car") }}</span>
                        </label>
                        <div id="ff-curbside-fields" style="display:none;margin-top:8px;">
                            <input aria-label="{{ ___('Car make/model') }}" type="text" name="vehicle_make" id="ff-vehicle-make" placeholder="{{ ___('Car make/model') }}" style="margin-bottom:8px;">
                            <input aria-label="{{ ___('Colour') }}" type="text" name="vehicle_color" id="ff-vehicle-color" placeholder="{{ ___('Colour') }}" style="margin-bottom:8px;">
                            <input aria-label="{{ ___('Plate') }}" type="text" name="vehicle_plate" id="ff-vehicle-plate" placeholder="{{ ___('Plate') }}">
                        </div>
                    </div>
                    @endif

                    <div class="ff-form-group">
                        <label for="ff-message">{{ ___('Notes / Special Instructions') }}</label>
                        <textarea id="ff-message" name="message" rows="2" placeholder="{{ ___('e.g. no onions, extra spicy') }}"></textarea>
                    </div>

                    <div class="ff-form-group">
                        <label>{{ ___('Payment Method') }} *</label>
                        <div class="ff-payment-options">
                            <label class="ff-payment-option">
                                <input type="radio" name="pay_via" value="pay_on_counter" checked>
                                <span class="ff-payment-box">
                                    <span class="ff-payment-title">{{ ___('Pay at Counter / Cash') }}</span>
                                </span>
                            </label>
                            @if($allow_payment)
                                <label class="ff-payment-option">
                                    <input type="radio" name="pay_via" value="pay_online">
                                    <span class="ff-payment-box">
                                        <span class="ff-payment-title">{{ ___('Pay Online') }}</span>
                                    </span>
                                </label>
                            @endif
                        </div>
                    </div>

                    <div class="ff-checkout-errors" id="ff-checkout-errors" style="display:none;"></div>

                    <button type="submit" id="ff-submit-order-btn" class="ff-btn-primary ff-btn-submit" data-tc-text="texts.checkout_button">
                        {{ theme_setting($store->id, 'texts.checkout_button', ___('Send Order')) }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- RESERVATIONS / WAITLIST (parity) — the trigger strip moved up to the end of
         ff-menu-main (above the info footer); the sheets it opens stay below. --}}

    @if($reservationsEnabled)
    <div id="ff-res-drawer" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="ff-res-backdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('Reserve a Table') }}</h2>
                <button class="ff-drawer-close" id="ff-res-close" aria-label="Close">&times;</button>
            </div>
            <div class="ff-drawer-content">
                @if($myReservation && !session('reservation_success'))
                    <div style="background:var(--ff-accent-0_15);border:1px solid var(--ff-border-gold-focus);border-radius:2px;padding:12px 14px;margin-bottom:16px;text-align:center">
                        <b style="color:var(--ff-accent)">{{ $myReservation->status === 'confirmed' ? ___('Confirmed') : ___('Requested') }}</b>
                        <div style="color:var(--ff-text-muted);font-size:12.5px;margin-top:2px">{{ ___('Booked for :when', ['when' => \Illuminate\Support\Carbon::parse($myReservation->reserved_at)->format('D, M j · g:i A')]) }}</div>
                    </div>
                @endif
                @if(session('reservation_success'))
                    <div style="text-align:center;padding:24px 0"><div style="font-size:42px">📅</div><h3 style="margin:10px 0 6px;font-family:var(--ff-font-headings)">{{ ___('Request received!') }}</h3><p style="color:var(--ff-text-muted)">{{ session('reservation_success') }}</p></div>
                @else
                    <form action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                        @csrf
                        <div class="ff-form-group"><label>{{ ___('Your Name') }} *</label><input aria-label="{{ ___('Your Name') }}" type="text" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}"></div>
                        <div class="ff-form-group"><label>{{ ___('Phone') }}</label><input aria-label="{{ ___('Phone') }}" type="tel" name="phone" maxlength="30" value="{{ $customer->phone ?? '' }}"></div>
                        <div class="ff-form-group"><label>{{ ___('Email') }}</label><input aria-label="{{ ___('Email') }}" type="email" name="email" maxlength="120" value="{{ $customer->email ?? '' }}"></div>
                        <div class="ff-form-group"><label>{{ ___('Date & Time') }} *</label><input aria-label="{{ ___('Date & Time') }}" type="datetime-local" name="reserved_at" required min="{{ now()->format('Y-m-d\TH:i') }}"></div>
                        <div class="ff-form-group"><label>{{ ___('Party Size') }} *</label><input aria-label="{{ ___('Party Size') }}" type="number" name="party_size" min="1" max="50" value="2" required></div>
                        <div class="ff-form-group"><label>{{ ___('Note') }} ({{ ___('optional') }})</label><textarea aria-label="{{ ___('Note') }}" name="notes" maxlength="500"></textarea></div>
                        @include('store_templates._partials.voice-note-recorder')
                        @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                        <button type="submit" class="ff-btn-primary" style="width:100%;margin-top:8px">{{ ___('Request Reservation') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if($waitlistEnabled)
    <div id="ff-waitlist-drawer" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="ff-waitlist-backdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('Join the Waitlist') }}</h2>
                <button class="ff-drawer-close" id="ff-waitlist-close" aria-label="Close">&times;</button>
            </div>
            <div class="ff-drawer-content">
                @if($myWaitlist && !session('waitlist_joined'))
                    <div style="background:var(--ff-accent-0_15);border:1px solid var(--ff-border-gold-focus);border-radius:2px;padding:12px 14px;margin-bottom:16px;text-align:center">
                        <b style="color:var(--ff-accent)">{{ ___('On the list') }}</b>
                        <div style="color:var(--ff-text-muted);font-size:12.5px;margin-top:2px">{{ ___('Party of :n · joined :time', ['n' => $myWaitlist->party_size, 'time' => \Illuminate\Support\Carbon::parse($myWaitlist->created_at)->diffForHumans()]) }}</div>
                    </div>
                @endif
                @if(session('waitlist_joined'))
                    <div style="text-align:center;padding:24px 0"><div style="font-size:42px">🎉</div><h3 style="margin:10px 0 6px;font-family:var(--ff-font-headings)">{{ ___("You're on the list!") }}</h3><p style="color:var(--ff-text-muted)">{{ ___('Position :pos · ~:min min wait', ['pos' => session('waitlist_joined')['position'], 'min' => session('waitlist_joined')['wait_minutes']]) }}</p></div>
                @else
                    <form action="{{ route('publicView.waitlist.join', $store->slug) }}" method="POST">
                        @csrf
                        <div class="ff-form-group"><label>{{ ___('Your Name') }} *</label><input aria-label="{{ ___('Your Name') }}" type="text" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}"></div>
                        <div class="ff-form-group"><label>{{ ___('Phone') }} ({{ ___('optional') }})</label><input aria-label="{{ ___('Phone') }}" type="tel" name="phone" maxlength="30" value="{{ $customer->phone ?? '' }}"></div>
                        <div class="ff-form-group"><label>{{ ___('Party Size') }} *</label><input aria-label="{{ ___('Party Size') }}" type="number" name="party_size" min="1" max="50" value="2" required></div>
                        <button type="submit" class="ff-btn-primary" style="width:100%;margin-top:8px">{{ ___('Join Waitlist') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- ===================================== AUTH (login / register) — parity --}}
    @if($authMode !== 'guest_only' && !$customer)
    <div id="ff-auth-drawer" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="ff-auth-backdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('Login') }} / {{ ___('Register') }}</h2>
                <button class="ff-drawer-close" id="ff-auth-close" aria-label="Close">&times;</button>
            </div>
            <div class="ff-drawer-content">
                <div class="ff-auth-tabs" id="ff-auth-tabs">
                    <button type="button" data-auth-tab="login" class="is-on">{{ ___('Login') }}</button>
                    <button type="button" data-auth-tab="otp">{{ ___('Phone OTP') }}</button>
                    <button type="button" data-auth-tab="register">{{ ___('Register') }}</button>
                </div>
                <p class="ff-auth-err" id="ff-auth-err" style="display:none"></p>

                <div class="ff-auth-form" data-auth="login">
                    <div class="ff-form-group"><label>{{ ___('Email') }}</label><input aria-label="{{ ___('Email') }}" type="email" id="ff-login-email" autocomplete="email"></div>
                    <div class="ff-form-group"><label>{{ ___('Password') }}</label><input aria-label="{{ ___('Password') }}" type="password" id="ff-login-pass" autocomplete="current-password"></div>
                    <button type="button" id="ff-login-btn" class="ff-btn-primary" style="width:100%;margin-top:6px">{{ ___('Login') }}</button>
                    @include('store_templates.partials.social-login-buttons', ['store' => $store])
                    @include('store_templates.partials.passkey-login', ['store' => $store])
                </div>

                <div class="ff-auth-form" data-auth="register" style="display:none">
                    <div class="ff-form-group"><label>{{ ___('Your Name') }}</label><input aria-label="{{ ___('Your Name') }}" type="text" id="ff-reg-name" autocomplete="name"></div>
                    <div class="ff-form-group"><label>{{ ___('Email') }}</label><input aria-label="{{ ___('Email') }}" type="email" id="ff-reg-email" autocomplete="email"></div>
                    <div class="ff-form-group"><label>{{ ___('Phone') }}</label><input aria-label="{{ ___('Phone') }}" type="tel" id="ff-reg-phone" autocomplete="tel"></div>
                    <div class="ff-form-group"><label>{{ ___('Password') }}</label><input aria-label="{{ ___('Password') }}" type="password" id="ff-reg-pass" autocomplete="new-password"></div>
                    <div class="ff-form-group"><label>{{ ___('Confirm Password') }}</label><input aria-label="{{ ___('Confirm Password') }}" type="password" id="ff-reg-pass2" autocomplete="new-password"></div>
                    <button type="button" id="ff-register-btn" class="ff-btn-primary" style="width:100%;margin-top:6px">{{ ___('Register') }}</button>
                    @include('store_templates.partials.social-login-buttons', ['store' => $store])
                </div>

                {{-- A3 · phone-OTP login (posts the collected code under `code`; distinct
                     ids from the checkout OTP gate #cvOtpGateBoxes) --}}
                <div class="ff-auth-form" data-auth="otp" style="display:none">
                    <div id="ff-otp-step1">
                        <div class="ff-form-group"><label>{{ ___('Phone number') }}</label><input aria-label="{{ ___('Phone number') }}" type="tel" id="ff-otp-phone" autocomplete="tel"></div>
                        <button type="button" id="ff-otp-send" class="ff-btn-primary" style="width:100%;margin-top:6px">{{ ___('Send code') }}</button>
                    </div>
                    <div id="ff-otp-step2" style="display:none">
                        <div class="ff-form-group"><label>{{ ___('Enter the code we sent you') }}</label></div>
                        <div class="ff-otp-boxes" id="ff-otp-boxes">
                            <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                        </div>
                        <button type="button" id="ff-otp-verify" class="ff-btn-primary" style="width:100%;margin-top:6px">{{ ___('Verify') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ===================================== ACCOUNT (loyalty + logout) — parity --}}
    @if($authMode !== 'guest_only' && $customer)
    <div id="ff-account-drawer" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="ff-account-backdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('My Account') }}</h2>
                <button class="ff-drawer-close" id="ff-account-close" aria-label="Close">&times;</button>
            </div>
            <div class="ff-drawer-content">
                <div class="ff-account-hello">
                    <span class="ff-account-avatar ff-account-avatar-lg">{{ Str::upper(Str::substr($customer->name ?? 'G', 0, 1)) }}</span>
                    <div>
                        <b style="font-family:var(--ff-font-headings);font-size:18px">{{ $customer->name ?? ___('Guest') }}</b>
                        <div style="color:var(--ff-text-muted);font-size:13px">{{ $customer->phone ?? $customer->email }}</div>
                    </div>
                </div>

                <a class="ff-account-link" href="{{ route('customer.profile', $store->id) }}">
                    <span>{{ ___('Profile & settings') }}</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m9 6 6 6-6 6"/></svg>
                </a>

                <a class="ff-account-link" href="{{ route('customer.account', $store->id) }}">
                    <span>{{ ___('Order History') }}</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m9 6 6 6-6 6"/></svg>
                </a>

                @if($loyaltyProgram)
                <section class="ff-loyalty" id="cvLoyaltyBlock">
                    <div class="ff-loyalty-head">
                        <span style="font-size:14px">✦</span>
                        <b>{{ ___('Your Loyalty Balance') }}</b>
                    </div>
                    <div class="ff-loyalty-num"><span id="cvLoyaltyBalance">–</span></div>
                    <p class="ff-loyalty-sub">
                        {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }}
                        / {{ $loyaltyProgram->reward_threshold }} · {{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }}
                    </p>
                    @if($loyaltyProgram->type === 'stamps')
                    <div class="ff-stamp-dots" id="cvStampDots">
                        @for($s = 0; $s < $loyaltyProgram->reward_threshold; $s++)
                            <span class="ff-stamp-dot" data-idx="{{ $s }}">✦</span>
                        @endfor
                    </div>
                    @else
                    <div class="ff-loyalty-bar"><i id="cvLoyaltyBar"></i></div>
                    @endif
                    <button class="ff-btn-primary" id="cvLoyaltyRedeem" type="button" style="width:100%;margin-top:12px;display:none">{{ ___('Redeem Reward') }}</button>
                    <p class="ff-loyalty-ok" id="cvLoyaltyOk" style="display:none">{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
                </section>
                @endif

                <button type="button" id="ff-logout-btn" class="ff-account-logout">{{ ___('Logout') }}</button>
            </div>
        </div>
    </div>
    @endif

    {{-- ===================================== ORDER SUCCESS (+ google review F1) — parity --}}
    <div id="ff-success-drawer" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="ff-success-backdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <div class="ff-drawer-drag-handle"></div>
                <button class="ff-drawer-close" id="ff-success-close" aria-label="Close">&times;</button>
            </div>
            <div class="ff-drawer-content" style="text-align:center;padding-bottom:30px">
                <div class="ff-success-check">✓</div>
                <h2 style="font-family:var(--ff-font-headings);font-size:24px;margin:14px 0 6px">{{ ___('Order Placed') }}</h2>
                <p id="ff-success-msg" style="color:var(--ff-text-muted);margin-bottom:14px">{{ ___('Thank you! Your order has been received.') }}</p>
                <p id="ff-success-order" class="ff-success-order" hidden></p>
                @if($googleReviewUrl)
                <a class="ff-greview" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener">
                    <span style="font-size:20px">⭐</span>
                    <span class="ff-greview-copy">
                        <b>{{ ___('Enjoying your meal?') }}</b>
                        <small>{{ ___('Rate us on Google') }}</small>
                    </span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m9 6 6 6-6 6"/></svg>
                </a>
                @endif
                <button type="button" id="ff-success-done" class="ff-btn-primary" style="width:100%;margin-top:18px">{{ ___('Done') }}</button>
            </div>
        </div>
    </div>

    {{-- ===== F3 · full-screen story viewer overlay ===== --}}
    <div class="ff-story-viewer" id="cvStoryViewer" hidden>
        <div class="ff-sv-progress" id="cvSvProgress"></div>
        <button class="ff-sv-close" id="cvSvClose" type="button" aria-label="{{ ___('Close') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="ff-sv-stage" id="cvSvStage">
            <img class="ff-sv-media" id="cvSvMedia" alt="">
            <button class="ff-sv-nav prev" id="cvSvPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="ff-sv-nav next" id="cvSvNext" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="ff-sv-foot">
                <p class="ff-sv-caption" id="cvSvCaption"></p>
                <a class="ff-btn-primary ff-sv-cta" id="cvSvCta" hidden><span></span></a>
            </div>
        </div>
    </div>

    {{-- ===== F4 · nearest-branch map picker ===== --}}
    {{-- ===== B6 · Following management drawer ===== --}}
    @if($authMode !== 'guest_only' || $customer)
    <div id="cvFollowingSheet" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="cvFollowingBackdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('Following') }}</h2>
                <button class="ff-drawer-close" id="cvFollowingClose" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="ff-drawer-content">
                <label class="ff-follow-sheet-row">
                    <span>{{ ___('Notifications') }}</span>
                    <input type="checkbox" id="cvFollowNotify" role="switch">
                </label>
                <button class="ff-followsheet-unfollow" id="cvFollowUnfollow" type="button">{{ ___('Unfollow') }}</button>
                <button class="ff-followsheet-cancel" id="cvFollowCancel" type="button">{{ ___('Cancel') }}</button>
            </div>
        </div>
    </div>
    @endif

    {{-- ===== B8 · About drawer (opens when the brand logo/name is tapped) ===== --}}
    <div id="cvAboutSheet" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="cvAboutBackdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ $store->title }}</h2>
                <button class="ff-drawer-close" id="cvAboutClose" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="ff-drawer-content">
                @if($aboutRating !== null)
                    <div class="ff-about-rating"><span class="ff-about-star">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
                @endif
                <div class="ff-about-list">
                    @if(!empty($store->timing))
                        <div class="ff-about-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>{{ $store->timing }}</span></div>
                    @endif
                    @if(!empty($store->address))
                        <a class="ff-about-row" href="https://maps.google.com/?q={{ urlencode($store->address) }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>{{ $store->address }}</span></a>
                    @endif
                    @if(!empty($store->phone))
                        <a class="ff-about-row" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $store->phone) }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5c0 8 7 15 15 15l1-4-5-2-2 2a13 13 0 0 1-5-5l2-2-2-5Z"/></svg><span dir="ltr">{{ $store->phone }}</span></a>
                    @endif
                    @if($aboutInstagram)
                        <a class="ff-about-row" href="{{ $aboutInstagram }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17" cy="7" r="1"/></svg><span>{{ ___('Instagram') }}</span></a>
                    @endif
                    @if($aboutFacebook)
                        <a class="ff-about-row" href="{{ $aboutFacebook }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v6h4v-6h3l1-4h-4V8Z"/></svg><span>{{ ___('Facebook') }}</span></a>
                    @endif
                </div>
                @if($aboutReviews->isNotEmpty())
                    <div class="ff-about-reviews">
                        @foreach($aboutReviews as $rev)
                            <div class="ff-about-review">
                                <div class="ff-about-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="ff-about-star">★ {{ $rev->stars }}</span></div>
                                @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div id="cvBranchMapSheet" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="cvBranchMapBackdrop"></div>
        <div class="ff-drawer-sheet ff-drawer-tall">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('Nearest branch') }}</h2>
                <button class="ff-drawer-close" id="cvBranchMapClose" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="ff-drawer-content">
                <div class="ff-branchmap" id="cvBranchMap"></div>
                <p class="ff-geo-attrib" id="cvMapAttrib"></p>
                <div class="ff-branch-list" id="cvBranchGeoList"></div>
            </div>
        </div>
    </div>

    {{-- ===== F1 · start-a-meal-plan sheet ===== --}}
    @if($mealPlansEnabled)
    <div id="cvMealPlanSheet" class="ff-drawer" aria-hidden="true" data-cadences='@json($mealPlanCadences)'>
        <div class="ff-drawer-backdrop" id="cvMealPlanBackdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('Create a meal plan') }}</h2>
                <button class="ff-drawer-close" id="cvMealPlanClose" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="ff-drawer-content">
                @if($customer)
                <div id="cvMealPlanForm">
                    <p class="ff-sheet-sub" id="cvMpItemsSummary"></p>
                    <div class="ff-form-group">
                        <label>{{ ___('Plan name') }} ({{ ___('optional') }})</label>
                        <input aria-label="{{ ___('e.g. Weekly lunch') }}" id="cvMpName" placeholder="{{ ___('e.g. Weekly lunch') }}">
                    </div>
                    <div class="ff-form-group">
                        <label>{{ ___('How often?') }}</label>
                        <div class="ff-seg-mini" id="cvMpCadence"></div>
                    </div>
                    <div class="ff-form-group" id="cvMpDaysWrap" hidden>
                        <label>{{ ___('Which days?') }}</label>
                        <div class="ff-chiprow" id="cvMpDays">
                            @foreach(['Sun'=>0,'Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6] as $d => $i)
                                <button data-day="{{ $i }}" type="button">{{ ___($d) }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="ff-form-group">
                        <label>{{ ___('Start date') }}</label>
                        <input aria-label="{{ ___('Start date') }}" id="cvMpStart" type="date" min="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}">
                    </div>
                    <p class="ff-checkout-errors" id="cvMpErr" style="display:none"></p>
                    <button class="ff-btn-primary" id="cvMpSubmit" type="button" style="width:100%;margin-top:8px"><span>{{ ___('Create plan') }}</span></button>
                </div>
                <div class="ff-mp-done" id="cvMpDone" hidden style="text-align:center;padding:16px 0">
                    <div style="font-size:42px">📅</div>
                    <b style="font-family:var(--ff-font-headings);font-size:18px">{{ ___('Meal plan created!') }}</b>
                    <p class="ff-sheet-sub" id="cvMpDoneSub"></p>
                    <a class="ff-ghostbtn" href="{{ route('customer.account', $store->id) }}">{{ ___('Manage plans') }}</a>
                </div>
                @else
                <p class="ff-sheet-sub">{{ ___('Log in to create a recurring meal plan.') }}</p>
                <button class="ff-btn-primary" id="cvMpLoginBtn" type="button" style="width:100%"><span>{{ ___('Login') }}</span></button>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- ===== F7 · OTP checkout gate sheet ===== --}}
    <div id="cvCheckoutOtpSheet" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="cvOtpGateBackdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('Verify your phone') }}</h2>
                <button class="ff-drawer-close" id="cvOtpGateClose" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="ff-drawer-content">
                <p class="ff-sheet-sub">{{ ___('Enter the code we sent to') }} <b id="cvOtpGatePhone" dir="ltr"></b></p>
                <div class="ff-otp-boxes" id="cvOtpGateBoxes">
                    <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                </div>
                <p class="ff-checkout-errors" id="cvOtpGateErr" style="display:none"></p>
                <button class="ff-btn-primary" id="cvOtpGateVerify" type="button" style="width:100%;margin-top:14px"><span>{{ ___('Verify & place order') }}</span></button>
                <button class="ff-linkbtn" id="cvOtpGateResend" type="button">{{ ___('Resend code') }}</button>
            </div>
        </div>
    </div>

    {{-- ===== Dietary / spice / allergen filter drawer (gated on allow_dietary_filters) ===== --}}
    @if($allowDietary)
    <div id="ff-filter-drawer" class="ff-drawer" aria-hidden="true">
        <div class="ff-drawer-backdrop" id="ff-filter-backdrop"></div>
        <div class="ff-drawer-sheet">
            <div class="ff-drawer-header">
                <h2 class="ff-drawer-title">{{ ___('Filters') }}</h2>
                <button class="ff-drawer-close" id="ff-filter-close" aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="ff-drawer-content">
                <h4 class="ff-filter-group-title">{{ ___('Dietary') }}</h4>
                <div class="ff-chiprow" id="ff-diet-row">
                    <button data-diet="vegan" type="button">{{ ___('Vegan') }}</button>
                    <button data-diet="halal" type="button">{{ ___('Halal') }}</button>
                    <button data-diet="gluten_free" type="button">{{ ___('Gluten-Free') }}</button>
                </div>
                <h4 class="ff-filter-group-title">{{ ___('Spice level') }}</h4>
                <div class="ff-chiprow" id="ff-spice-row">
                    <button data-spice="1" type="button">🌶</button>
                    <button data-spice="2" type="button">🌶🌶</button>
                    <button data-spice="3" type="button">🌶🌶🌶</button>
                </div>
                @if(count($allergies ?? []))
                <h4 class="ff-filter-group-title">{{ ___('Exclude allergens') }}</h4>
                <div class="ff-chiprow" id="ff-allergen-row">
                    @foreach($allergies as $a)
                        <button data-al="{{ $a->id }}" type="button">{{ $a->title }}</button>
                    @endforeach
                </div>
                @endif
                <div class="ff-filter-actions">
                    <button class="ff-btn-ghost" id="ff-filter-reset" type="button">{{ ___('Reset') }}</button>
                    <button class="ff-btn-primary" id="ff-filter-apply" type="button">{{ ___('Show dishes') }}</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="ff-toast" id="cvToast" role="status"></div>
</div>

{{-- Shared notification-center panel (bell lives in the app-bar icon row) --}}
@include('store_templates._partials.notif-bell')
@endsection

@push('scripts_at_bottom')
{{-- F4 · self-hosted Leaflet (loads before script.js; CSP script-src self+nonce safe) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
<script nonce="{{ csp_nonce() }}">
    window.FEAST = {
        theme: @json($theme),
        storeId: @json($store->id),
        storeId: @json($store->id),
        /* account.blade.php stashes the reorder handoff under `ff_reorder_<slug>`;
           script.js needs the same slug to read it back */
        restaurantSlug: @json($store->slug),
        orderUrl: @json(route('store.submitOrder', $store->id)),
        csrf: @json(csrf_token()),
        authMode: @json($authMode),
        isLoggedIn: @json((bool) $customer),
        allowCoupons: @json((bool) $allowCoupons),
        allowTips: @json((bool) $allowTips),
        allowCallWaiter: @json((bool) ($allowWaiter && $allow_on_table)),
        defaultTable: @json(is_object($table ?? null) ? ($table->table_no ?? null) : ($table ?? null)),
        loyaltyType: @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json($loyaltyProgram->reward_threshold ?? null),
        /* theme-pack-blade features (F1–F7) */
        customerToken:    @json($customerApiToken),
        isFollowing:      @json((bool) $isFollowing),
        followerCount:    @json((int) $followerCount),
        followNotify:     @json((bool) $followNotify),
        mealPlansEnabled: @json((bool) $mealPlansEnabled),
        otpGateEnabled:   @json((bool) $otpGateEnabled),
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
        currencyDecimals: @json($curDecimals),
        moneyDecimals: @json(money_decimals(@$postOptions->currency_code ?: null)), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        baseCurrency: @json(config('settings.currency_code', 'USD')),
        currencyRates: @json($mcRates),
        deliveryCharge: @json($deliveryCharge),
        taxCharge: @json($taxCharge),
        taxType: @json($taxType),
        taxLabel: @json($taxLabel),
        minOrderValue: @json($minOrderValue),
        branchId: @json(request()->get('branch_id') ?: ''),
        items: @json($jsItems),
        lang: {
            add: @json(___('Add to Order')),
            chooseOption: @json(___('Choose an option')),
            extras: @json(___('Extras')),
            locUnavailable: @json(___('Location unavailable')),
            locDenied: @json(___('Location denied')),
            locNoAddress: @json(___('No address found')),
            networkError: @json(___('Network error')),
            codeSent: @json(___('Code sent')),
            tableRequired: @json(___('Please enter your table number')),
            added: @json(___('Added!')),
            reorderAdded:   @json(___('Your previous order is back in the cart.')),
            reorderPartial: @json(___('Some items were unavailable and were skipped.')),
            soldOut: @json(___('Sold Out')),
            cartEmpty: @json(___('Your cart is empty')),
            item: @json(___('item')),
            items: @json(___('items')),
            couponApplied: @json(___('Coupon applied successfully')),
            couponInvalid: @json(___('Invalid or expired coupon code')),
            waiterOk:      @json(___('A waiter will be with you shortly!')),
            tip:           @json(___('Tip')),
            filtersApplied:@json(___('Show dishes')),
            minOrderError: @json(___('Minimum order amount is')),
            fieldRequired: @json(___('Please fill all required fields')),
            sending: @json(___('Sending order...')),
            orderLabel: @json(___('Order')),
            rateThisItem: @json(___('Rate this dish')),
            yourName: @json(___('Your name (optional)')),
            yourReview: @json(___('Share your experience...')),
            submit: @json(___('Submit')),
            reviewThanks: @json(___('Thank you for your review!')),
            reviews:      @json(___('Reviews')),
            rv_write:     @json(___('Write')),
            rv_verified:  @json(___('Verified')),
            rv_latest:    @json(___('Latest')),
            rv_detailed:  @json(___('Detailed')),
            rv_empty:     @json(___('No reviews yet — be the first!')),
            cad_daily:    @json(___('Daily')),
            cad_weekly:   @json(___('Weekly')),
            cad_custom:   @json(___('Custom days')),
            mp_next:      @json(___('Next order')),
            choose:       @json(___('Choose a rating')),
            thanks:       @json(___('Thanks!')),
            required:     @json(___('Enter the code')),
            popular:      @json(___('Popular')),
            cartEmpty:    @json(___('Cart is empty')),
            failed:       @json(___('Something went wrong.')),
            now_following: @json(___('You are now following :store', ['store' => $store->title])),
            action_failed: @json(___('Something went wrong, please try again.')),
            view_stories:  @json(___('View stories')),
            order_error:   @json(___('Error occurred while sending order.')),
            invalid_code:  @json(___('Invalid code')),
            remove:        @json(___('Remove')),
            removed:       @json(___('Removed')),
            recentAddress: @json(___('Recent address')),
            viewCart:      @json(___('View Cart'))
        }
    };
</script>
@endpush

@push('scripts_at_bottom')
@endpush

@push('scripts_at_bottom')
{{-- ZERO-TOUCH A3.1 — on-menu agent chat + voice widget (renders only when owner-enabled + plan) --}}
@include('store_templates.partials.agent-chat', ['store' => $store])
@include('store_templates._partials.account-claim-banner')
@endpush
