{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       orbit — ultra-clean iOS ordering theme · data preparation
       Faithful rebuild of rev/orbit/demo.* wired to real menu data.
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus   = collect($total_menus)->values();
    $items      = $allMenus->values();
    $categories = $store->menu_categories->values();

    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

    $curSign  = cur_isolate($postOptions->currency_sign ?? '$');
    $curGap   = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only
    $curPos   = (string) currency_pos_effective($postOptions->id ?? null);
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

    $brandColor = theme_setting($store->id, 'colors.primary', '#f4633c');

    /* helper: human label for a variant combination.
       `options` is normally a {menuVariantOptionId: choiceIndex} map whose labels
       live on MenuVariantOption. A legacy/imported variant can carry a plain
       scalar there instead — use it verbatim rather than falling through to the
       meaningless word "Option" for every choice in the picker (ORBIT-F4). */
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelRaw(...);

    /* JS data map — variant price ABSOLUTE, groups/extras additive (aurora contract) */
    $jsItems = [];
    $reviewsByMenu = \App\Models\MenuReview::query()
        ->where('store_id', $store->id)
        ->where('status', 'approved')
        ->latest()
        ->get()
        ->groupBy('menu_id');
    $catItemCount = [];   // real category id => own item count (drives the sub-chip row)
    foreach ($items as $m) {
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;
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

        $jsItems[$m->id] = [
            'id'    => $m->id,
            'cat'   => $m->category_id,
            'name'  => $m->name,
            'desc'  => html_entity_decode(strip_tags((string) $m->description), ENT_QUOTES),
            'img'   => media_url('storage/menu/', $m->image, $store->id ?? null),
            // F11: small thumb candidate for srcset — only when a generated thumb
            // actually exists on disk, else '' so the JS omits srcset (no 404).
            'thumb' => ($m->image && $m->image !== 'default.png'
                && is_file(public_path('storage/menu/thumb/'.pathinfo($m->image, PATHINFO_FILENAME).'.webp')))
                ? media_url('storage/menu/thumb/', pathinfo($m->image, PATHINFO_FILENAME).'.webp', $store->id ?? null)
                : '',
            'price' => (float) $m->price,
            'type'  => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'=> $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : '',
            'reviews'=> (int) ($m->rating_count ?? 0),
            // Written-review total for the Reviews SECTION badge. rating_count above
            // counts every star rating (quick taps included), so the section head
            // could say "118" over a list of one written review — reads fabricated.
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

    /* categories for the signature arc (real menu categories).
       `menu_categories` is top-level-only (whereNull('parent')), so an item filed
       under a SUBcategory carries a category_id no chip would ever match. Ship the
       child ids alongside each chip so the grid can match parent OR any child. */
    $jsCats = $categories->map(fn($c) => [
        'id'   => (string) $c->id,
        'name' => $c->name,
        'img'  => $c->image ? media_url('storage/menu/', $c->image, $store->id ?? null) : null,
        'kids' => collect($c->subcategories ?? [])->map(fn($k) => (string) $k->id)->values(),
    ])->values();

    /* Secondary sub-chip row: per parent, only the subcategories that own items.
       A parent whose items live directly on it (no item-bearing child) gets no
       second row — its items already show under the parent arc chip via `kids`. */
    $subCatsOf = [];
    foreach ($categories as $c) {
        $subs = collect($c->subcategories ?? [])
            ->filter(fn($s) => ($catItemCount[$s->id] ?? 0) > 0)
            ->values();
        if ($subs->isNotEmpty()) {
            $subCatsOf[$c->id] = $subs;
        }
    }

    /* admin-managed banners → promo slot */
    $jsBanners = collect($menuBanners ?? [])->map(fn($b) => [
        'title'    => $b->title,
        'subtitle' => $b->subtitle,
        'img'      => $b->image ? ($b->image_url ?? media_url('storage/menu_banners/', $b->image, $store->id ?? null)) : null,
        'link'     => storefront_localized_link($b->link_url) ?: null,
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

    /* number of enabled order types — drives the home order-type segment */
    $otCount = ($allow_delivery?1:0)+($allow_takeaway?1:0)+($allow_on_table?1:0)+($allow_room_service?1:0);

    /* engagement extras (same backend contract as crave/aurora) */
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);

    /* F2 · brand / follow row (crave cvBrandRow parity) */
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
    $customerApiToken = $customer ? storefront()->customerApiToken($customer, 'orbit') : null;
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));

    $splashTitle    = theme_setting($store->id, 'splash.title', ___('Hungry? Order & Eat.'));
    $featuredTitle  = theme_setting($store->id, 'texts.featured_title', ___('Daily special'));

    /* ORBIT-F24 — the demo's hero is two-tone ("Hungry?" ink / "Order & Eat."
       grey) and `.hh-gray` shipped as a dead rule because live printed one flat
       string. Split on the first sentence break, else at the middle word. */
    $heroLead = trim((string) $splashTitle);
    $heroTail = '';
    $heroBits = preg_split('/(?<=[?!.،؟])\s+/u', $heroLead, 2);
    if (is_array($heroBits) && count($heroBits) === 2 && $heroBits[1] !== '') {
        $heroLead = $heroBits[0];
        $heroTail = $heroBits[1];
    } else {
        $heroWords = preg_split('/\s+/u', $heroLead) ?: [];
        if (count($heroWords) > 2) {
            $cut = (int) ceil(count($heroWords) / 2);
            $heroLead = implode(' ', array_slice($heroWords, 0, $cut));
            $heroTail = implode(' ', array_slice($heroWords, $cut));
        }
    }

    /* ORBIT-F22 — a customer must be able to tell, on the landing surface,
       whether the restaurant is open and how long the food takes. */
    $prepEtaText = trim((string) (@$postOptions->prep_eta_static ?: ''));
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    @if($showBranches)
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    @endif
@endpush

@section('content')
<div class="orbit" id="orbit" style="--theme-color-1: {{ \Juwri\Core\Support\HexColor::safe($brandColor, '#f4633c') }}">
{{-- ORBIT-F6 — paint in the customer's saved appearance immediately (this runs
     during parse, before the app shell below is painted): no white flash. --}}
<script nonce="{{ csp_nonce() }}">try{var m=localStorage.getItem("mb_theme_"+@json((string) $store->id))||localStorage.getItem("orbit-theme-"+@json($store->slug));if(m==="dark"||m==="light")document.currentScript.parentNode.setAttribute("data-theme",m);}catch(e){}</script>

    {{-- ══════════ SPLASH ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if(theme_setting($store->id, 'splash.enabled', true) && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="splash" id="splash">
        <div class="orb-mark" aria-hidden="true">
            <span class="orb-ring r1"><i></i></span>
            <span class="orb-ring r2"><i></i></span>
            <span class="orb-core"></span>
        </div>
        @if($store->main_image && $store->main_image !== 'default.png')
            @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img class="splash-logo" src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
        @endif
        <div class="splash-word">{{ Str::limit($store->title, 16, '') }}<span>.</span></div>
        <p class="splash-tag" data-tc-text="splash.title">{{ $heroLead }}@if($heroTail) <span class="hh-gray">{{ $heroTail }}</span>@endif</p>
        <button class="splash-skip" id="splashSkip" type="button">{{ ___('Skip') }}</button>
    </div>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="cv-views" id="views">

        {{-- HOME --}}
        <section class="view is-active" data-view="home">
            @php
                $__orbHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
                $orbCoverUrl = $__orbHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
                $orbShowCover = $__orbHasCover && theme_setting($store->id, 'brand.show_cover', true);
            @endphp
            {{-- Collapsing header: slides in once the retro cover header leaves the
                 scroll container (orbit's chips are not top-sticky, so no guard). --}}
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
                @if($orbShowCover)
                {{-- Retro cover header: full-bleed cover behind the dark chrome pills +
                     hero title + store strip, fading to the page. --}}
                <div class="orbit-cover">
                    <div class="orbit-cover-media" aria-hidden="true">
                        @if((string) ($orbCoverUrl) !== '')<img class="orbit-cover-img" src="{{ $orbCoverUrl }}" alt="" data-csp-error="hide">@endif
                        <span class="orbit-cover-scrim"></span>
                    </div>
                @endif
                <header class="topbar">
                    {{-- Profile avatar (crave parity): the customer's initial when signed
                         in, a neutral person glyph as a guest. Order type is picked on the
                         order-type segment below, so the old topbar toggle is retired. --}}
                    <button class="avatar{{ $customer ? ' avatar--me' : '' }}" data-go="profile" type="button" aria-label="{{ ___('Profile') }}">
                        @if($customer)
                            {{ Str::upper(Str::substr($customer->name ?: $customer->phone ?: '?', 0, 1)) }}
                        @else
                            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                        @endif
                    </button>
                    {{-- crave cvBranchBtn parity. Multi-branch: a Branch button that
                         opens the branch switcher (see the addrBtn handler in script.js).
                         Dropping #addrMode here makes applyOrderMode() skip this button,
                         so the branch name is not clobbered by the order mode. Single
                         branch: the original order-mode / store button. --}}
                    @if($showBranches)
                    <button class="loc" id="addrBtn" type="button">
                        <span class="loc-label">{{ ___('Branch') }}</span>
                        <span class="loc-value">
                            <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            <b id="addrText">{{ $activeBranch->name ?? $branches->first()->name ?? $store->title }}</b>
                            <svg class="ic chev-d" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                        </span>
                    </button>
                    @else
                    <button class="loc" id="addrBtn" type="button">
                        <span class="loc-label" id="addrMode">{{ ___('Welcome to') }}</span>
                        <span class="loc-value">
                            <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            <b id="addrText">{{ $store->title }}</b>
                            <svg class="ic chev-d" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
                        </span>
                    </button>
                    @endif
                    @if(count($menu_languages ?? []) > 1)
                    <button class="iconbtn" id="langBtn" type="button" aria-label="{{ ___('Language') }}">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
                    </button>
                    @endif
                    @if(storefront_catering_enabled($store->id))
                    <button class="iconbtn" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
                    </button>
                    @endif
                    {{-- Dark/light toggle. Reuses the appearance mechanism (applyTheme):
                         flips between explicit light/dark; the icon is CSS-driven off the
                         effective mode. The Auto option still lives in the profile seg. --}}
                    <button class="iconbtn theme-toggle" id="themeToggle" type="button" aria-label="{{ ___('Toggle dark mode') }}">
                        <svg class="ic ic-moon" viewBox="0 0 24 24"><path d="M21 12.8A8 8 0 1 1 11.2 3a6 6 0 0 0 9.8 9.8Z"/></svg>
                        <svg class="ic ic-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.4"/><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6"/></svg>
                    </button>
                    <button class="iconbtn" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
                        <i class="dot" id="mbNotifDot" hidden></i>
                    </button>
                </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


                <h1 class="hero-h" data-tc-text="splash.title">{{ $heroLead }}@if($heroTail) <span class="hh-gray">{{ $heroTail }}</span>@endif</h1>

                {{-- ORBIT-F22 — open/closed, prep time and address on the landing surface.
                     Previously the only store info lived on the Profile tab. --}}
                <div class="store-strip">
                    <span class="ss-chip {{ $isOpen ? 'is-open' : 'is-closed' }}" data-mb-openchip data-mb-closed-class="is-closed">
                        <i class="ss-dot"></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span>
                    </span>
                    @if($prepEtaText)
                    <span class="ss-chip">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>
                        <span dir="ltr">{{ $prepEtaText }} {{ ___('min') }}</span>
                    </span>
                    @endif
                    @if($store->address)
                    <span class="ss-chip">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <span>{{ Str::limit($store->address, 34) }}</span>
                    </span>
                    @endif
                </div>
                @if($orbShowCover)
                </div>{{-- /.orbit-cover --}}
                @endif

                {{-- F2 · brand / follow row (crave cvBrandRow parity): store mark, name +
                     verified badge, follower proof line, and a follow button. --}}
                {{-- data-mb-hotel-chip-after: the room chip (shared hotel partial) mounts
                     right AFTER this row, as its own full-width row. The store-strip above
                     sits ON the cover art, so slotting the chip there put it over the
                     artwork among the dark-glass status pills. --}}
                <div class="brand-row{{ $orbShowCover ? ' brand-row--hero' : '' }}" id="cvBrandRow" data-mb-hotel-chip-after>
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
                        <span class="follow-label">{{ ___('Follow') }}</span>
                        <span class="following-label">{{ ___('Following') }}</span>
                    </button>
                    @endif
                </div>

                {{-- B8 · store-review display — avg rating + up to 2 approved cards.
                     Self-gated: renders only when the reviews module is on and the
                     store has approved reviews (dark today), so it ships dormant. --}}
                @if($aboutRating !== null)
                <div class="orb-reviews" id="orbReviews" data-mb-reviews-end>
                    <div class="orb-rating"><span class="orb-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
                    @if($aboutReviews->isNotEmpty())
                    <div class="orb-review-list">
                        @foreach($aboutReviews as $rev)
                        <div class="orb-review">
                            <div class="orb-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="orb-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                            @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endif

                @if($allow_order && $otCount > 1)
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

                @if(theme_setting($store->id, 'search.enabled', true))
                <div class="searchrow">
                    <button class="search" id="searchOpen" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                        <span>{{ ___('Search here') }}…</span>
                        @if($allowDietary)
                        <span class="search-filter" id="filterBtn" role="button" aria-label="{{ ___('Filters') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M8 12h8M11 17h2"/></svg>
                            <i class="dot" id="filterDot" hidden></i>
                        </span>
                        @endif
                    </button>
                </div>
                @endif

                {{-- stories strip — filled from the public stories endpoint.
                     Stays hidden (and contributes no height) when there is nothing to show. --}}
                <div class="stories" id="storyRail" hidden aria-label="{{ ___('Stories') }}"></div>

                {{-- promo slot (admin banners) --}}
                <div class="promos" id="promos">
                    <div class="promo-track" id="promoTrack"></div>
                    <div class="promo-dots" id="promoDots"></div>
                </div>

                {{-- signature arc category selector (built from real categories).
                     Kept directly above the items grid so the category selector and
                     the section it filters read as one unit. data-mb-grid-lead keeps
                     injected sections (the "On sale now" offers strip) ABOVE it
                     rather than wedged between the categories and the menu. --}}
                <div class="arc-wrap" data-mb-grid-lead data-mbt-anchor>
                    <div class="arc" id="arc" aria-label="{{ ___('Categories') }}"></div>
                </div>

                {{-- Secondary sub-category chips. One hidden group per parent that has
                     item-bearing subcategories; JS reveals the active parent's group.
                     "All" (data-sub="root") shows every descendant of the parent. --}}
                @if(!empty($subCatsOf))
                <div class="subchips" id="subChips" data-mb-grid-lead hidden aria-label="{{ ___('Sub-categories') }}">
                    @foreach($subCatsOf as $parentId => $subs)
                        <div class="subgroup" data-parent="{{ $parentId }}" hidden>
                            <button class="subchip is-on" data-sub="root" type="button">{{ ___('All') }}</button>
                            @foreach($subs as $sub)
                                <button class="subchip" data-sub="{{ $sub->id }}" type="button">
                                    @if($sub->image)
                                        @if((string) (media_url('storage/menu/', $sub->image, $store->id ?? null)) !== '')<img class="subchip-img" src="{{ media_url('storage/menu/', $sub->image, $store->id ?? null) }}" alt="" loading="lazy" data-csp-error="remove">@endif
                                    @endif
                                    {{ $sub->name }}
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @endif

                <section class="grid-block">
                    <div class="section-head">
                        <h2 id="gridTitle">{{ ___('Popular') }}</h2>
                        <button class="favlink" id="favsLink" type="button">
                            <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
                            <span>{{ ___('Favorites') }}</span>
                        </button>
                    </div>
                    <div class="grid" id="grid"></div>
                    <div class="empty" id="gridEmpty" hidden>
                        <svg viewBox="0 0 64 64" class="empty-art"><circle cx="32" cy="32" r="20"/><ellipse cx="32" cy="32" rx="30" ry="11" transform="rotate(-18 32 32)"/></svg>
                        <p>{{ ___('Nothing matches — try clearing filters') }}</p>
                        <button class="ghostbtn" id="clearFilters" type="button">{{ ___('Clear filters') }}</button>
                    </div>
                </section>

                @if($reservationsEnabled || $waitlistEnabled)
                <button class="reserve-row" id="reserveRow" type="button">
                    <span class="rr-ic"><svg class="ic" viewBox="0 0 24 24"><rect x="4" y="6" width="16" height="14" rx="3"/><path d="M8 3v5M16 3v5M4 11h16"/></svg></span>
                    <span class="rr-copy"><b>{{ ___('Reserve a Table') }}</b><small>{{ ___('Book ahead or join the waitlist') }}</small></span>
                    <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </button>
                @endif
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- OFFERS --}}
        {{-- Offers and Orders now live as sub-tabs INSIDE the Profile view below
             (data-view="profile", #acctSeg). Their markup moved there verbatim. --}}

        {{-- CART --}}
        <section class="view" data-view="cart">
            <div class="view-scroll">
                <header class="page-head">
                    <h1>{{ ___('My Cart') }}</h1>
                    <button class="linkbtn" id="clearCart" type="button">{{ ___('Clear') }}</button>
                </header>
                <div class="cart-list" id="cartList"></div>
                <div class="empty" id="cartEmpty" hidden>
                    <svg viewBox="0 0 64 64" class="empty-art"><path d="M8 13h6l6.6 27a5 5 0 0 0 4.9 3.8h18a5 5 0 0 0 4.9-4L52 21H17"/><circle cx="27" cy="53" r="3.4"/><circle cx="45" cy="53" r="3.4"/></svg>
                    <p>{{ ___('Your cart is empty') }}</p>
                    <button class="ghostbtn" data-go="home" type="button">{{ ___('Browse menu') }}</button>
                </div>

                @if($allow_order)
                <div class="cart-tools" id="cartTools">
                    <section>
                        <h3>{{ ___('Order type') }}</h3>
                        <div class="seg" id="orderTypeSeg">
                            @if($allow_delivery)<button data-ot="delivery" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M3 7h10v8H3zM13 10h4l3 3v2h-3"/><circle cx="7" cy="17.4" r="1.9"/><circle cx="16.6" cy="17.4" r="1.9"/></svg><span>{{ ___('Delivery') }}</span></button>@endif
                            @if($allow_takeaway)<button data-ot="takeaway" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><span>{{ ___('Pickup') }}</span></button>@endif
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
                    <section class="coupon">
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
                        <button class="ghostbtn" id="callWaiter" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span>{{ ___('Call Waiter') }}</span></button>
                        <button class="ghostbtn" id="requestBill" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg><span>{{ ___('Request Bill') }}</span></button>
                    </div>
                    @endif

                    @if($waNumber)
                    <a class="wa-order" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                        <span>{{ ___('Chat with us on WhatsApp') }}</span>
                        <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
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
                        <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </button>
                    @endif
                </div>
                @endif
                <div class="tail-space"></div>
            </div>
            @if($allow_order)
            <footer class="cta-bar" id="cartCta" hidden>
                <button class="cta wide" id="goCheckout" type="button"><span>{{ ___('Checkout') }}</span><b id="cartCtaTotal"></b></button>
            </footer>
            @endif
        </section>

        {{-- PROFILE --}}
        <section class="view" data-view="profile">
            <div class="view-scroll">
                <header class="page-head"><h1>{{ ___('Profile') }}</h1></header>
                {{-- Orders / Offers / Profile merged into ONE tab with a segmented
                     sub-switcher (was three separate dock destinations). --}}
                <div class="seg acct-seg" id="acctSeg" role="tablist">
                    <button class="is-on" data-acct="orders" type="button">{{ ___('Orders') }}</button>
                    <button data-acct="offers" type="button">{{ ___('Offers') }}</button>
                    <button data-acct="settings" type="button">{{ ___('Profile') }}</button>
                </div>

                <div class="acct-panel" data-acct-panel="offers" hidden>
                    @if($loyaltyProgram && $customer)
                    <div class="loy-card" id="loyBlock">
                        <div class="loy-top">
                            <span class="loy-orbit" aria-hidden="true"><i></i></span>
                            <div class="loy-copy">
                                <small>{{ ___('Your Loyalty Balance') }}</small>
                                <b><span id="ofPts">–</span> {{ $loyaltyProgram->type === 'stamps' ? ___('stamps') : ___('points') }}</b>
                            </div>
                            <button class="minibtn orange" id="ofRedeem" type="button" hidden>{{ ___('Redeem') }}</button>
                        </div>
                        <div class="loy-bar"><i id="ofBar"></i></div>
                        <p class="loy-next" id="ofNext">{{ $loyaltyProgram->reward_description }} · / {{ $loyaltyProgram->reward_threshold }}</p>
                        <p class="loy-ok" id="ofOk" hidden style="font-size:12px;color:#7CFFB2">{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
                    </div>
                    @elseif($loyaltyProgram)
                    <div class="loy-card">
                        <div class="loy-top">
                            <span class="loy-orbit" aria-hidden="true"><i></i></span>
                            <div class="loy-copy"><small>{{ ___('Loyalty Rewards') }}</small><b>{{ $loyaltyProgram->reward_description }}</b></div>
                        </div>
                        <p class="loy-next">{{ ___('Sign in to start earning') }}</p>
                    </div>
                    @endif

                    @if($allowCoupons)
                    <button class="offer-card dashed" data-go="cart" type="button">
                        <span class="oc-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg></span>
                        <span class="oc-copy"><b>{{ ___('Have a promo code?') }}</b><small>{{ ___('Apply it in your cart at checkout') }}</small></span>
                        <span class="minibtn">{{ ___('Apply') }}</span>
                    </button>
                    @endif

                    <div id="offersRailBlock">
                        <div class="section-head"><h2 id="offersRailTitle" data-label="{{ $featuredTitle }}">{{ $featuredTitle }}</h2></div>
                        <div class="rail" id="offersRail"></div>
                    </div>
                </div>{{-- /offers panel --}}

                <div class="acct-panel" data-acct-panel="orders">
                    @if($customer)
                        <a class="order-row" href="{{ route('customer.profile', $store->id) }}">
                            <span class="or-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg></span>
                            <div><b>{{ ___('Profile & settings') }}</b><small>{{ ___('Details, addresses & preferences') }}</small></div>
                            <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                        <a class="order-row" href="{{ route('customer.account', $store->id) }}">
                            <span class="or-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg></span>
                            <div><b>{{ ___('View order history') }}</b><small>{{ ___('All your orders in one place') }}</small></div>
                            <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                    @else
                        @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
                        <p class="sheet-sub" style="padding:8px 2px">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
                        <div class="lookup-field">
                            <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                            <input aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" id="lookupInput" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" autocomplete="off">
                            <button class="minibtn" id="lookupBtn" type="button">{{ ___('Find') }}</button>
                        </div>
                        <div class="orders-list" id="lookupResults" style="padding-top:12px">
                            <div class="empty sm">
                                <svg viewBox="0 0 64 64" class="empty-art"><path d="M18 8h28v46l-5-3.4-5 3.4-5-3.4-5 3.4-5-3.4L18 54V8Z"/><path d="M26 20h12M26 30h12"/></svg>
                                <p>{{ ___('Your past orders will show up here.') }}</p>
                            </div>
                        </div>
                    @endif
                </div>{{-- /orders panel --}}

                <div class="acct-panel" data-acct-panel="settings">
                {{-- ORBIT-F37: the stock logo 404s; hiding the <img> left an empty ring.
                     Fall back to the store initial instead of a hole. --}}
                <div class="me">
                    <span class="me-ava">
                        <span class="me-ava-fallback" aria-hidden="true">{{ Str::upper(Str::substr($customer->name ?? $store->title, 0, 1)) }}</span>
                        @if($store->main_image && $store->main_image !== 'default.png')
                        @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                        @endif
                        <span class="me-badge"><svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg></span>
                    </span>
                    <b class="me-name">{{ $customer->name ?? $store->title }}</b>
                    <p class="me-mail">{{ $customer ? ($customer->phone ?? $customer->email) : ___('Order without an account') }}</p>
                </div>

                <div class="list-card">
                    {{-- ORBIT-F6: appearance control. "Auto" follows the OS; the explicit
                         choice is persisted per store and re-applied before first paint. --}}
                    <div class="prow" role="group" aria-label="{{ ___('Appearance') }}">
                        <span class="prow-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M20 13A8 8 0 1 1 11 4a6.5 6.5 0 0 0 9 9Z"/></svg></span>
                        <span class="prow-label">{{ ___('Appearance') }}</span>
                        <span class="appearance-seg" id="appearanceSeg">
                            <button data-theme="auto" class="is-on" type="button">{{ ___('Auto') }}</button>
                            <button data-theme="light" type="button">{{ ___('Light') }}</button>
                            <button data-theme="dark" type="button">{{ ___('Dark') }}</button>
                        </span>
                    </div>
                    <button class="prow" data-acct-go="orders" type="button">
                        <span class="prow-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg></span>
                        <span class="prow-label">{{ ___('My Orders') }}</span>
                        <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </button>
                    @if(count($menu_languages ?? []) > 1 || (!empty($postOptions->enable_multi_currency) && count($mcRates) > 0))
                    <button class="prow" data-prow="settings" type="button">
                        <span class="prow-ic"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.2 5.2l2.1 2.1M16.7 16.7l2.1 2.1M18.8 5.2l-2.1 2.1M7.3 16.7l-2.1 2.1"/></svg></span>
                        <span class="prow-label">{{ ___('Language & Currency') }}</span>
                        <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </button>
                    @endif
                    @if($showBranches)
                    <button class="prow" data-prow="branch" type="button">
                        <span class="prow-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg></span>
                        <span class="prow-label">{{ ___('Choose branch') }}</span>
                        <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </button>
                    @endif
                    @if($waNumber)
                    <a class="prow" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                        <span class="prow-ic green"><svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg></span>
                        <span class="prow-label">{{ ___('Chat on WhatsApp') }}</span>
                        <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </a>
                    @endif
                    @if($store->phone)
                    <a class="prow" href="tel:{{ $store->phone }}">
                        <span class="prow-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg></span>
                        <span class="prow-label">{{ ___('Call the restaurant') }}</span>
                        <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </a>
                    @endif
                    @if($authMode !== 'guest_only')
                        @if($customer)
                        <button class="prow logout" id="logoutBtn" type="button">
                            <span class="prow-ic red"><svg class="ic" viewBox="0 0 24 24"><path d="M9 4H5v16h4M14 8l4 4-4 4M18 12H9"/></svg></span>
                            <span class="prow-label">{{ ___('Logout') }}</span>
                        </button>
                        @else
                        <button class="prow" id="meAuthBtn" type="button">
                            <span class="prow-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M15 4h4v16h-4M10 8l4 4-4 4M14 12H4"/></svg></span>
                            <span class="prow-label">{{ ___('Login') }} / {{ ___('Register') }}</span>
                            <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                        </button>
                        @endif
                    @endif
                    {{-- Privacy utility: wipe the cart / favourites / saved addresses this
                         browser keeps (shared window.mbClearDeviceData engine). Lives in the
                         guest-reachable Profile settings, mirroring the logout row. --}}
                    <button class="prow orbit-cleardata" id="orbitClearData" type="button">
                        <span class="prow-ic red"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13M10 11v6M14 11v6"/></svg></span>
                        <span class="prow-label">{{ ___('Clear saved data on this device') }}</span>
                    </button>
                </div>
                <p class="orbit-cleardata-hint" id="orbitClearDataHint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>

                <div class="biz-card">
                    @if($store->address)<span><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</span>@endif
                    @if($store->phone)<span><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><a href="tel:{{ $store->phone }}">{{ $store->phone }}</a></span>@endif
                </div>
                <p class="demo-foot">{{ $store->title }}</p>
                </div>{{-- /settings panel --}}
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- FAVORITES (sub) --}}
        <section class="view view-sub" data-view="favs">
            <div class="view-scroll">
                <header class="page-head sub"><button class="fab back inline" data-back aria-label="{{ ___('Back to Menu') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button><h1>{{ ___('Favorites') }}</h1></header>
                <div class="grid" id="favGrid"></div>
                <div class="empty" id="favEmpty" hidden>
                    <svg viewBox="0 0 64 64" class="empty-art"><path d="M32 50s-18-11-18-24c0-7 5.4-12 11.2-12 3.4 0 5.6 2 6.8 3.8C33.2 16 35.4 14 38.8 14 44.6 14 50 19 50 26c0 13-18 24-18 24Z"/></svg>
                    <p>{{ ___('Tap the heart on any dish to save it here') }}</p>
                </div>
                <div class="tail-space"></div>
            </div>
        </section>

        {{-- PRODUCT (sub) --}}
        <section class="view view-sub" data-view="product">
            <div class="view-scroll">
                <div class="p-hero">
                    <div class="p-gallery" id="pGallery"></div>
                    <div class="p-gallery-dots" id="pGalleryDots"></div>
                    {{-- R8 · prev/next arrows shown by script.js only for multi-image dishes --}}
                    <button class="p-gal-arrow prev" id="pGalPrev" type="button" hidden aria-label="{{ ___('Previous image') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <button class="p-gal-arrow next" id="pGalNext" type="button" hidden aria-label="{{ ___('Next image') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button>
                    <button class="fab back" data-back aria-label="{{ ___('Back') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <button class="fab fav" id="pFav" aria-label="{{ ___('Favorite') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
                    <span class="p-stock" id="pStock" hidden></span>
                </div>
                <div class="p-body" id="pBody"></div>
                <div class="rail-block p-related" id="relatedBlock">
                    <div class="section-head"><h2>{{ ___('You might also like') }}</h2></div>
                    <div class="rail" id="relatedRail"></div>
                </div>
                <div class="tail-space tall"></div>
            </div>
            @if($allow_order)
            <footer class="cta-bar">
                <div class="qty" id="pQty">
                    <button class="qty-btn" data-q="-1" aria-label="{{ ___('Decrease quantity') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
                    <output id="pQtyVal">1</output>
                    <button class="qty-btn" data-q="1" aria-label="{{ ___('Increase quantity') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
                </div>
                <button class="cta" id="addToCart" type="button"><span>{{ ___('Add to Cart') }}</span><b id="pTotal"></b></button>
                {{-- shown by script.js once the cart has items: a direct jump to the cart --}}
                <button class="p-cart-jump" id="pCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M3 5h2.2l2.5 10.2a2 2 0 0 0 2 1.5h6.9a2 2 0 0 0 1.95-1.55L20.5 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
                    <i class="p-cart-jump-count" id="pCartJumpCount" aria-hidden="true">0</i>
                </button>
            </footer>
            @endif
        </section>

        {{-- CHECKOUT (sub) --}}
        <section class="view view-sub" data-view="checkout">
            <div class="view-scroll">
                <header class="page-head sub">
                    <button class="fab back inline" data-back aria-label="{{ ___('Back') }}" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                    <h1>{{ ___('Checkout') }}</h1>
                </header>

                {{-- ORBIT-F15: ___('Contact') resolves to the shared all-caps "CONTACT"
                     against three Title-Case siblings — use a key of our own.
                     ORBIT-F33: every field gets a label; placeholders repeat, not replace. --}}
                <section class="co-block">
                    <h3 class="cv-h3">{{ ___('Contact details') }}</h3>
                    <div>
                        <span class="field-label">{{ ___('Your Name') }}</span>
                        <input aria-label="{{ ___('Your Name') }}" id="coName" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}" autocomplete="name">
                    </div>
                    <div id="phoneField">
                        <span class="field-label">{{ ___('Phone number') }}</span>
                        <input aria-label="{{ ___('Phone number') }}" id="coPhone" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
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
                        <button class="linkbtn2" id="loginLink" type="button" style="margin-top:9px">{{ ___('Have an account?') }} {{ ___('Login') }}</button>
                    @endif
                </section>

                <section class="co-block" id="addrBlock" style="display:none">
                    <h3 class="cv-h3">{{ ___('Delivery address') }}</h3>
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
                    {{-- R6 · guests get their device-remembered addresses filled here by renderRecentAddrs() --}}
                    <div class="saved-addrs" id="savedAddrs" hidden></div>
                    @endif
                    {{-- address autocomplete + use-my-location (geo.autocomplete / geo.reverse) --}}
                    <div class="addr-field" id="coAddrField">
                        <input aria-label="{{ ___('Delivery address') }}" id="coAddr" placeholder="{{ ___('Delivery address') }}" autocomplete="off" autocorrect="off" spellcheck="false"
                               value="{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}">
                        <input type="hidden" id="coLat" value="{{ $customerDefaultAddress->lat ?? '' }}">
                        <input type="hidden" id="coLng" value="{{ $customerDefaultAddress->lng ?? '' }}">
                        <button class="uselocbtn" id="coUseLocation" type="button" title="{{ ___('Use my location') }}" aria-label="{{ ___('Use my location') }}">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                        </button>
                        <div class="addr-suggest" id="coAddrSuggest" hidden></div>
                    </div>
                    <p class="geo-attrib" id="coGeoAttrib" hidden></p>
                    {{-- Drop-a-pin address picker — mirrors orbit's own nearest-branch map
                         button; opens the orbit-skinned sheet below (shared window.mbMapPicker
                         engine) and fills this same #coAddr + hidden #coLat/#coLng. --}}
                    <button class="nearest-branch-btn omap-trigger" id="orbitMapBtn" type="button">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <span>{{ ___('Choose on map') }}</span>
                        <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                    </button>
                </section>

                @if($allowTips)
                <section class="co-block">
                    <h3 class="cv-h3">{{ ___('Add a tip') }} ({{ ___('optional') }})</h3>
                    <div class="tip-row" id="tipRow">
                        {{-- ORBIT-F15: the shared ___('None') key carries the value "— None —" --}}
                        <button data-tip="0" class="is-on" type="button">{{ ___('No tip') }}</button>
                        <button data-tip="10" type="button">10%</button>
                        <button data-tip="15" type="button">15%</button>
                        <button data-tip="20" type="button">20%</button>
                        <button data-tip="custom" type="button">{{ ___('Custom') }}</button>
                    </div>
                    <input aria-label="{{ ___('Custom tip amount') }}" type="number" id="customTip" placeholder="{{ ___('Custom tip amount') }}" min="0" step="{{ \Juwri\Core\Money\AmountInput::step(null) }}" hidden style="margin-top:9px">
                </section>
                @endif

                <section class="co-block">
                    <h3 class="cv-h3">{{ ___('Pay with') }}</h3>
                    <div class="pm-list" id="pmList"></div>
                </section>

                @if($customer && $storeCredit > 0)
                <section class="co-block" id="creditBlock">
                    <label class="terms">
                        <input type="checkbox" id="mbUseWallet">
                        <span>{{ ___('Pay with store credit') }} ({{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }})</span>
                    </label>
                </section>
                @endif

                @if(!$customer)
                <section class="co-block">
                    <label class="terms">
                        <input type="checkbox" id="consentCb" data-csp-toggle="captureEmailWrap" data-csp-toggle-mode="hidden">
                        <span>{{ ___('Send me offers and updates from this restaurant') }}</span>
                    </label>
                    <div id="captureEmailWrap" hidden style="padding-top:9px">
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
                <section class="co-block ck-items" id="ckItems" hidden></section>
                <section class="totals co-block" id="checkoutTotals"></section>
                <p class="err-box" id="formError" hidden style="margin-top:12px"></p>
                <div class="tail-space tall"></div>
            </div>
            <footer class="cta-bar">
                <button class="cta wide" id="placeOrder" type="button">
                    <span class="po-label">{{ ___('Place Order') }}</span><b id="checkoutCtaTotal"></b><span class="spinner" hidden></span>
                </button>
            </footer>
        </section>

        {{-- CONFIRMED (sub) --}}
        <section class="view view-sub view-center" data-view="confirmed">
            <div class="confirm-wrap">
                <div class="confetti" id="confetti" aria-hidden="true"></div>
                <div class="ok-art" aria-hidden="true">
                    <span class="ok-ring o1"><i></i></span>
                    <span class="ok-ring o2"><i></i></span>
                    <span class="ok-core"><svg viewBox="0 0 24 24"><path class="ok-check" d="m6 12.5 4 4L18 8" pathLength="1"/></svg></span>
                </div>
                <h1 class="confirm-title">{{ ___('Order Confirmed') }}</h1>
                {{-- ORBIT-F12: this screen used to jump to /track after 3.4s, so the
                     review + Google-review + NPS block below could never be completed. --}}
                <p class="confirm-sub">{{ ___('Your order has been placed successfully') }}<br><span class="confirm-eta">{{ ___('Track it live whenever you are ready.') }}</span></p>
                {{-- The order number the customer quotes / sees on the track page.
                     submitOrder already returns res.order_id; filled in by JS. --}}
                <p class="confirm-code" id="confirmOrderNo" hidden></p>
                <p class="confirm-pts" id="confirmPts" hidden></p>
                <div class="confirm-actions">
                    <a class="cta wide" id="confirmTrack" href="#"><span>{{ ___('Track Order') }}</span></a>
                    <button class="ghostbtn" data-go="home" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span>{{ ___('Back to Menu') }}</span></button>
                </div>

                <div class="review-card">
                    <p class="rv-q">{{ ___('How was everything?') }}</p>
                    <div class="star-row" id="starRow">
                        @for($n = 1; $n <= 5; $n++)
                            <button type="button" data-star="{{ $n }}" aria-label="{{ $n }}"><svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg></button>
                        @endfor
                    </div>
                    @if($googleReviewUrl)
                    <div class="rv-pos" id="reviewPos" hidden>
                        <p class="rv-thanks">{{ ___('Thank you! Mind sharing it?') }}</p>
                        <a class="google-cta" id="googleCta" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener"><span class="g-badge">G</span><span>{{ ___('Review us on Google') }}</span></a>
                    </div>
                    @endif
                    <p class="rv-thanks" id="reviewDone" hidden>{{ ___('Thanks for the feedback!') }}</p>
                </div>

                <div class="nps">
                    <p>{{ ___('How likely are you to recommend us?') }}</p>
                    <div class="nps-row" id="npsRow">
                        @for($n = 0; $n <= 10; $n++)
                            <button type="button" data-nps="{{ $n }}">{{ $n }}</button>
                        @endfor
                    </div>
                    <p class="nps-thanks" id="npsThanks" hidden>{{ ___('Thanks for the feedback!') }}</p>
                </div>
            </div>
        </section>

        {{-- Catering & Events now lives in #cvCateringSheet (opened from the header
             button); the form is included there with cateringInline=true so it does
             not self-relocate back to the bottom of the menu. --}}
    </main>

    {{-- ══════════ FLOATING BLACK PILL NAV ══════════ --}}
    {{-- ORBIT-F1 — this element used to carry the class `navbar`, which the SHARED
         layout styles with `z-index:99999999`. That put the pill above every sheet,
         the scrim and the cart's Checkout CTA. Renaming it to a theme-owned class
         hands the stacking order back to orbit's own --z scale. --}}
    <nav class="orbit-nav" id="navbar" aria-label="{{ ___('Main') }}">
        <span class="navdot" id="navDot" aria-hidden="true"></span>
        <button class="nav-btn is-on" data-tab="home" type="button" aria-label="{{ ___('Home') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg></button>
        <button class="nav-btn" data-tab="menu" type="button" aria-label="{{ ___('Menu') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
        @if(theme_setting($store->id, 'search.enabled', true))
        <button class="nav-btn" data-tab="search" type="button" aria-label="{{ ___('Search') }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg></button>
        @endif
        @if($allow_order)
        <button class="nav-btn" data-tab="cart" type="button" aria-label="{{ ___('Cart') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M3 5h2.2l2.5 10.2a2 2 0 0 0 2 1.5h6.9a2 2 0 0 0 1.95-1.55L20.5 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg><i class="nav-badge" id="cartBadge" hidden>0</i></button>
        @endif
        <button class="nav-btn" data-tab="favs" type="button" aria-label="{{ ___('Favorites') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
        {{-- Orders and Offers merged into the Profile view (#acctSeg); one tab now. --}}
        <button class="nav-btn" data-tab="profile" type="button" aria-label="{{ ___('Profile') }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg></button>
    </nav>

    {{-- ══════════ SEARCH OVERLAY ══════════ --}}
    <div class="search-layer" id="searchLayer" hidden>
        <div class="sl-top">
            <label class="sl-field">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input aria-label="{{ ___('Search here') }}" id="searchInput" type="search" placeholder="{{ ___('Search here') }}…" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
            </label>
            <button class="linkbtn" id="searchClose" type="button">{{ ___('Cancel') }}</button>
        </div>
        <div class="sl-results" id="searchResults"></div>
    </div>

    {{-- ══════════ STORY VIEWER ══════════ --}}
    <div class="story-layer" id="storyViewer" hidden>
        <div class="sv-bars" id="svBars"></div>
        <button class="sv-x" id="svClose" type="button" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <div class="sv-stage" id="svStage">
            <img class="sv-shot" id="svShot" alt="">
            <button class="sv-tap prev" id="svPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
            <button class="sv-tap next" id="svNext" type="button" aria-label="{{ ___('Next') }}"></button>
            <div class="sv-foot">
                <p class="sv-cap" id="svCap"></p>
                <a class="cta wide sv-cta" id="svCta" hidden><span></span></a>
            </div>
        </div>
    </div>

    {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
    <div class="scrim" id="scrim" hidden></div>

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
        <h4>{{ ___('Max spice') }}</h4>
        <div class="spice-scale" id="spiceScale">
            <button data-spice="3" class="is-on" type="button">🌶🌶🌶</button>
            <button data-spice="2" type="button">🌶🌶</button>
            <button data-spice="1" type="button">🌶</button>
            <button data-spice="0" type="button">{{ ___('Mild') }}</button>
        </div>
        <div class="sheet-actions">
            <button class="ghostbtn" id="filterReset" type="button">{{ ___('Reset') }}</button>
            <button class="cta" id="filterDone" type="button"><span>{{ ___('Show dishes') }}</span><b id="filterCount"></b></button>
        </div>
    </div>
    @endif

    @if(count($menu_languages ?? []) > 1 || (!empty($postOptions->enable_multi_currency) && count($mcRates) > 0))
    @if(storefront_catering_enabled($store->id))
    <div class="sheet" id="cvCateringSheet" hidden>
        <div class="sheet-grab"></div>
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
    </div>
    @endif

    <div class="sheet" id="settingsSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Language & Currency') }}</h2>
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
    </div>
    @endif

    @if($showBranches)
    <div class="sheet" id="branchSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Choose branch') }}</h2>
        <button class="nearest-branch-btn" id="orbitNearestBranchBtn" type="button">
            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <span>{{ ___('Find nearest branch on map') }}</span>
            <svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
        </button>
        <div class="branch-list">
            @foreach($branches as $branch)
                <a class="branch-row {{ ($activeBranch->id ?? null) === $branch->id ? 'is-on' : '' }}"
                   href="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    <b>{{ $branch->name }}</b>
                    <span class="branch-check"></span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- nearest-branch Leaflet map picker (self-hosted tiles/markers) --}}
    <div class="sheet sheet-tall" id="orbitBranchMapSheet" hidden>
        <div class="sheet-grab"></div>
        <h2>{{ ___('Nearest branch') }}</h2>
        <div class="branchmap" id="orbitBranchMap"></div>
        <p class="geo-attrib" id="orbitMapAttrib"></p>
        <div class="branch-list" id="orbitBranchGeoList" style="margin-top:12px"></div>
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
        <p class="err-box" id="authErr" hidden></p>
        <div class="auth-form" data-auth="login">
            <input aria-label="{{ ___('Email') }}" id="loginEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Password') }}" id="loginPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="current-password">
            <button class="cta wide" id="loginBtn" type="button"><span>{{ ___('Login') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
            @include('store_templates.partials.passkey-login', ['store' => $store])
        </div>
        <div class="auth-form" data-auth="otp" hidden>
            <div id="otpStep1">
                <p class="sheet-sub">{{ ___("We'll text you a one-time code") }}</p>
                <input aria-label="{{ ___('Phone number') }}" id="otpPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel">
                <button class="cta wide" id="otpSend" type="button" style="margin-top:11px"><span>{{ ___('Send code') }}</span></button>
            </div>
            <div id="otpStep2" hidden>
                <p class="sheet-sub">{{ ___('Enter the code we sent you') }}</p>
                <div class="otp-boxes" id="otpBoxes">
                    <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
                </div>
                <button class="cta wide" id="otpVerify" type="button" style="margin-top:11px"><span>{{ ___('Verify') }}</span></button>
            </div>
        </div>
        <div class="auth-form" data-auth="register" hidden>
            <input aria-label="{{ ___('Your Name') }}" id="regName" placeholder="{{ ___('Your Name') }}" autocomplete="name">
            <input aria-label="{{ ___('Email') }}" id="regEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
            <input aria-label="{{ ___('Phone number') }}" id="regPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
            <input aria-label="{{ ___('Password') }}" id="regPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
            {{-- The register endpoint validates `password` with the `confirmed`
                 rule, so a matching password_confirmation is required — without
                 this field every sign-up failed "password confirmation does not
                 match". --}}
            <input aria-label="{{ ___('Confirm Password') }}" id="regPass2" type="password" placeholder="{{ ___('Confirm Password') }}" autocomplete="new-password">
            <button class="cta wide" id="registerBtn" type="button"><span>{{ ___('Register') }}</span></button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store])
        </div>
    </div>
    @endif

    @if($reservationsEnabled)
    <div class="sheet" id="resSheet" hidden>
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
                <p class="muted">{{ ___('We\'ll confirm your booking shortly.') }}</p>
            </div>
        @else
            <form class="sheet-form" action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                @csrf
                <span class="field-label">{{ ___('Your Name') }}</span>
                <input aria-label="{{ ___('Your Name') }}" name="name" required maxlength="80" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}">
                <span class="field-label">{{ ___('Phone number') }}</span>
                <input aria-label="{{ ___('Phone number') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}">
                <span class="field-label">{{ ___('Email') }}</span>
                <input aria-label="{{ ___('your@email.com') }}" name="email" type="email" maxlength="120" value="{{ $customer->email ?? '' }}" placeholder="{{ ___('your@email.com') }}">
                <span class="field-label">{{ ___('Date & Time') }}</span>
                <input aria-label="{{ ___('Date & Time') }}" name="reserved_at" type="datetime-local" required min="{{ now()->format('Y-m-d\TH:i') }}">
                <span class="field-label">{{ ___('Party Size') }}</span>
                <input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                <span class="field-label">{{ ___('Note') }} ({{ ___('optional') }})</span>
                <textarea aria-label="{{ ___('Any special requests?') }}" name="notes" rows="2" maxlength="500" placeholder="{{ ___('Any special requests?') }}"></textarea>
                @include('store_templates._partials.voice-note-recorder')
                @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                <button class="cta wide" type="submit" style="margin-top:14px"><span>{{ ___('Request Reservation') }}</span></button>
            </form>
        @endif
        @if($waitlistEnabled)
        {{-- Both reservations AND the waitlist are on, and "Reserve a Table" is
             the only entry row (its subtitle already promises "or join the
             waitlist"), so give the reservation sheet a path to the waitlist —
             otherwise the waitlist form is unreachable whenever reservations are
             also enabled. --}}
        <button class="rr-wl-switch" id="resToWl" type="button" style="display:block;width:100%;margin-top:14px;background:none;border:0;color:inherit;opacity:.75;font:inherit;font-size:13px;text-decoration:underline;text-underline-offset:2px;cursor:pointer">{{ ___('Fully booked? Join the waitlist') }}</button>
        @endif
    </div>
    @endif

    @if($waitlistEnabled)
    <div class="sheet" id="wlSheet" hidden>
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
                <span class="field-label">{{ ___('Your Name') }}</span>
                <input aria-label="{{ ___('Your Name') }}" name="name" required maxlength="120" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your Name') }}">
                <span class="field-label">{{ ___('Phone number') }} ({{ ___('optional') }})</span>
                <input aria-label="{{ ___('Phone number') }}" name="phone" maxlength="30" inputmode="tel" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone number') }}">
                <span class="field-label">{{ ___('Party Size') }}</span>
                <input aria-label="{{ ___('Party Size') }}" name="party_size" type="number" min="1" max="50" value="2" required inputmode="numeric">
                <button class="cta wide" type="submit" style="margin-top:14px"><span>{{ ___('Join Waitlist') }}</span></button>
            </form>
        @endif
    </div>
    @endif

    {{-- ============================================= ADDRESS MAP PICKER SHEET
         Orbit-native bottom sheet (.sheet) with its own scrim, driven by the
         shared window.mbMapPicker engine. Fills the same #coAddr the checkout
         submits, plus the hidden #coLat/#coLng coords. Its own scrim (#orbitMapScrim,
         not the shared #scrim) keeps it fully decoupled from the theme's native
         sheet state machine — the picker opens from the checkout view, not a sheet. --}}
    <div class="scrim omap-scrim" id="orbitMapScrim" hidden></div>
    <div class="sheet sheet-tall omap-sheet" id="orbitMapSheet" hidden role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}">
        {{-- decorative grab bar only (NOT class .sheet-grab, so the theme's native
             grab close-handler doesn't bind — this sheet is closed via #orbitMapClose
             + its own scrim through the engine's `close` array). --}}
        <div class="omap-grab" aria-hidden="true"></div>
        <button class="sheet-x" type="button" id="orbitMapClose" aria-label="{{ ___('Close') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
        <h2>{{ ___('Choose on map') }}</h2>
        <div class="omap-canvas" id="orbitMapCanvas"></div>
        <p class="omap-preview" id="orbitMapPreview">{{ ___('Move the map to place the pin') }}</p>
        <div class="sheet-actions">
            <button class="cta wide" id="orbitMapConfirm" type="button">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                <span>{{ ___('Use this location') }}</span>
            </button>
        </div>
    </div>

    <div class="toast" id="toast" role="status"></div>
    <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>
    @include('store_templates._partials.notif-bell')
</div>
@endsection

@push('scripts_at_bottom')
@if($showBranches)
{{-- self-hosted Leaflet (loads before script.js; CSP script-src self+nonce safe) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
@endif
<script nonce="{{ csp_nonce() }}">
    window.ORBIT = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        restaurantName: @json($store->title),
        currencySign:   @json($curSign),
        currencyGap:    @json($curGap),
        currencyPos:    @json($curPos),
        currencyDecimals: @json(currency_decimals()),
        moneyDecimals: @json(money_decimals()), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode:   @json(currency_decimals_map()),
        baseCurrency:   @json(config('settings.currency_code', 'USD')),
        currencyRates:  @json($mcRates),
        taxCharge:      @json($taxCharge),
        /* the server honours restaurant_tax_type; quoting a percentage against a
           "fixed" store (or taxing the discounted subtotal) diverges the quote
           from the charge — see totals() in script.js. */
        taxType:        @json((string) (@$postOptions->restaurant_tax_type ?: 'percentage')),
        taxLabel:       @json($taxLabel),
        deliveryCharge: @json($deliveryCharge),
        minOrderValue:  @json($minOrderValue),
        allowOrder:     @json((bool) $allow_order),
        allowPayment:   @json((bool) $allow_payment),
        allowCallWaiter:@json((bool) ($allowWaiter && $allow_on_table)),
        allowDelivery:  @json((bool) $allow_delivery),
        allowTakeaway:  @json((bool) $allow_takeaway),
        allowOnTable:   @json((bool) $allow_on_table),
        allowRoomService: @json((bool) $allow_room_service),
        forceTable:     @json((bool) $table),
        defaultTable:   @json($table->table_no ?? null),
        authMode:       @json($authMode),
        isLoggedIn:     @json((bool) $customer),
        customerName:   @json($customer->name ?? null),
        customerToken:  @json($customerApiToken),
        csrf:           @json(csrf_token()),
        loyaltyType:      @json($loyaltyProgram->type ?? null),
        loyaltyThreshold: @json((int) ($loyaltyProgram->reward_threshold ?? 0)),
        openResSheet:      @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        googleReviewUrl:  @json($googleReviewUrl ?: null),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
        items:          @json((object) $jsItems),
        cats:           @json($jsCats),
        banners:        @json($jsBanners),
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
            branchesGeo: @json($showBranches ? route('geo.branches', $store->slug) : null),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:  @json(route('geo.reverse')),
            login:       @json(route('customer.login', $store->id)),
            register:    @json(route('customer.register', $store->id)),
            logout:      @json(route('customer.logout', $store->id)),
            otpRequest:  @json(route('customer.otp.request', $store->id)),
            otpVerify:   @json(route('customer.otp.verify', $store->id)),
            loyaltyBalance: @json($loyaltyProgram ? route('publicView.loyalty.balance', $store->id) : null),
            loyaltyRedeem:  @json($loyaltyProgram ? route('publicView.loyalty.redeem', $store->id) : null),
        },
        i18n: {
            all:           @json(___('All')),
            popular:       @json(___('Popular')),
            sold_out:      @json(___('Sold out')),
            not_available: @json(___('Not available now')),
            starting_from: @json(___('From')),
            added:         @json(___('Added to cart')),
            removed:       @json(___('Removed')),
            choose:        @json(___('Choose an option')),
            required:      @json(___('Required')),
            optional:      @json(___('Optional')),
            extras:        @json(___('Add extras')),
            subtotal:      @json(___('Subtotal')),
            discount:      @json(___('Discount')),
            delivery:      @json(___('Delivery fee')),
            tip:           @json(___('Tip')),
            total:         @json(___('Total')),
            min_order:     @json(___('Minimum order')),
            select_type:   @json(___('Please choose an order type')),
            fill_contact:  @json(___('Please add your name')),
            fill_addr:     @json(___('Please add a delivery address')),
            addr_ph:       @json(___('Delivery address')),
            use_my_location: @json(___('Use my location')),
            coupon_bad:    @json(___('Invalid promo code.')),
            waiter_ok:     @json(___('A waiter will be with you shortly!')),
            bill_ok:       @json(___('Your bill is on its way!')),
            rate_dish:     @json(___('Rate this dish')),
            thanks:        @json(___('Thanks!')),
            add_to_cart:   @json(___('Add to Cart')),
            pay_counter:   @json(___('Pay On Counter')),
            pay_online:    @json(___('Pay Online')),
            pay_online_sub:@json(___('Card / wallet via secure page')),
            search_none:   @json(___('No dishes found')),
            checkout:      @json(___('Checkout')),
            no_orders:     @json(___('No orders found')),
            order_label:   @json(___('Order')),
            back_menu:     @json(___('Back to Menu')),
            favorite:      @json(___('Favorite')),
            reviews:       @json(___('Reviews')),
            no_reviews:    @json(___('No reviews yet.')),
            max_pick:      @json(___('Max')),
            points:        @json(___('points')),
            stamps:        @json(___('stamps')),
            track_order:   @json(___('Track Order')),
            failed:        @json(___('Something went wrong. Please try again.')),
            order_now:     @json(___('Order now')),
            loc_unavailable: @json(___('Location unavailable')),
            loc_denied:    @json(___('Location denied')),
            no_address:    @json(___('No address found')),
            mode_delivery: @json(___('Delivery')),
            mode_takeaway: @json(___('Pickup')),
            mode_ontable:  @json(___('Dine-in')),
            mode_room:     @json(___('Room service')),
            room:          @json(___('Room')),
            table:         @json(___('Table')),
            branch:        @json(___('Pickup at the counter')),
            your_order:    @json(___('Your order')),
            edit:          @json(___('Edit')),
            note_add:      @json(___('Add note')),
            note_ph:       @json(___('Any special requests?')),
            veg:           @json(___('Veg')),
            nonveg:        @json(___('Non-veg')),
            order_failed:  @json(___('We could not place your order. Please check your details and try again.')),
            order_unsure:  @json(___('Something went wrong on our side. Check My Orders before trying again so you are not charged twice.')),
            order_network: @json(___('Network error. Please try again.')),
            verify_phone:  @json(___('Verify phone to view orders')),
            clear_sure:    @json(___('Tap again to clear')),
            cart_cleared:  @json(___('Cart cleared')),
            recent_address: @json(___('Recent address')),
        },
    };
</script>
{{-- script.js is loaded once by store_templates.layout (avoids double-load) --}}
@endpush

@push('scripts_at_bottom')
@endpush

@push('scripts_at_bottom')
{{-- Address map picker + clear-saved-data wiring. Orbit is a shared-layout theme,
     so window.mbMapPicker / window.mbClearDeviceData are already global (the layout
     @includes the engines above @yield('content')). We only drive them with orbit's
     own bottom sheet + settings row. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var trigger = document.getElementById('orbitMapBtn');
    if (!window.mbMapPicker) { if (trigger) { trigger.style.display = 'none'; } return; }
    var sheet = document.getElementById('orbitMapSheet');
    var scrim = document.getElementById('orbitMapScrim');
    var mapT = null;
    /* Drive orbit's native bottom-sheet animation on this picker's OWN sheet + scrim
       (mirrors sheet()/rawCloseSheet in script.js: toggle [hidden] then .is-open across
       one frame, hide again after the 0.42s transition). Its own scrim keeps it clear of
       the theme's shared #scrim / openSheetId state, since it opens from the checkout view. */
    function toggle(open) {
        if (!sheet) { return; }
        clearTimeout(mapT);
        if (open) {
            sheet.hidden = false; if (scrim) { scrim.hidden = false; }
            sheet.setAttribute('aria-hidden', 'false');
            mapT = setTimeout(function () { sheet.classList.add('is-open'); if (scrim) { scrim.classList.add('is-open'); } }, 20);
        } else {
            sheet.classList.remove('is-open'); if (scrim) { scrim.classList.remove('is-open'); }
            sheet.setAttribute('aria-hidden', 'true');
            mapT = setTimeout(function () { sheet.hidden = true; if (scrim) { scrim.hidden = true; } }, 420);
        }
    }
    var api = window.mbMapPicker({
        trigger: trigger,
        modal:   sheet,
        map:     document.getElementById('orbitMapCanvas'),
        input:   document.getElementById('coAddr'),
        lat:     document.getElementById('coLat'),
        lng:     document.getElementById('coLng'),
        preview: document.getElementById('orbitMapPreview'),
        confirm: document.getElementById('orbitMapConfirm'),
        close:   [document.getElementById('orbitMapClose'), scrim],
        toggle:  toggle,
        i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
    });
    /* orbit's native Escape closer only knows its own sheets, so give the picker its own. */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && api && sheet && sheet.classList.contains('is-open')) { api.close(); }
    });
})();
(function () {
    var btn = document.getElementById('orbitClearData');
    if (!btn) { return; }
    var hint = document.getElementById('orbitClearDataHint');
    if (!window.mbClearDeviceData) { btn.style.display = 'none'; if (hint) { hint.style.display = 'none'; } return; }
    var slug = @json($store->slug ?? '');
    btn.addEventListener('click', function () {
        window.mbClearDeviceData({
            keys: [
                'mb-cart-' + slug,
                'cv-favs-' + slug,
                'orbit_recent_addrs_' + slug,
                'orbit_recent_orders_' + slug,
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
