{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ---------------------------------------------------------------------
       ember — glassmorphic dark burger app theme · data preparation
       (data contract identical to crave/aurora; UI is the ember demo)
       --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus   = collect($total_menus)->values();
    $items      = $allMenus->values();
    $categories = $store->menu_categories->values();

    /* $store->menu_categories is top-level-only (the relation applies whereNull('parent')),
       but an item keys off its OWN category_id — which may be a subcategory. A parent chip
       must therefore also claim its children's items, or it renders empty (e.g. an offer
       category whose only item lives in a child). Maps parent id → child ids for the JS
       chip filter, reusing the already-eager-loaded subcategories. */
    $catKids = [];
    foreach ($categories as $cat) {
        $kids = collect($cat->subcategories ?? [])->pluck('id')->map(fn($id) => (string) $id)->values()->all();
        if ($kids) { $catKids[(string) $cat->id] = $kids; }
    }

    /* own-item count per real category id (drives the secondary sub-chip row) */
    $catItemCount = [];
    foreach ($items as $m) {
        $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;
    }

    /* Secondary sub-chip row: per parent, only the subcategories that own items.
       A parent whose items live directly on it (no item-bearing child) gets no
       second row — its items already show under the parent chip via $catKids. */
    $subCatsOf = [];
    foreach ($categories as $cat) {
        $subs = collect($cat->subcategories ?? [])
            ->filter(fn($s) => ($catItemCount[$s->id] ?? 0) > 0)
            ->values();
        if ($subs->isNotEmpty()) {
            $subCatsOf[$cat->id] = $subs;
        }
    }

    /* multi-branch → nearest-branch Leaflet map (same contract as orbit/aurora) */
    $showBranches = !empty($branches) && count($branches) > 1 && (@$postOptions->allow_multi_branch_switch ?? 1);

    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

    $curSign  = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos   = (string) currency_pos_effective($postOptions->id ?? null);
    $curGap   = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only
    $deliveryCharge  = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge       = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    /* TOTALS CONTRACT — `restaurant_tax_type` has two modes ('percentage' | 'fixed').
       'fixed' is a FLAT amount, not a rate; a client that always multiplies bills a
       flat-tax restaurant a percentage of the basket. Mirrors Order::computeTax(). */
    $taxType         = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');
    $taxLabel        = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
    $minOrderValue   = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched = !empty($postOptions->allow_order_scheduling);
    $allowCoupons    = @$postOptions->allow_coupons == 1;
    $allowTips       = @$postOptions->allow_tips == 1;
    $allowWaiter     = !empty($postOptions->allow_call_waiter);
    $allowDietary    = !isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;
    $isOpen          = store_open_now($store);

    $featured   = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    $promoItem  = $items->first(fn($m) => (bool) ($m->is_daily_special ?? false)) ?? $featured;

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

        /* The owner's own name for the variant group ("Style", "Size", "Base"…).
           The product sheet used to print a hardcoded "SIZE" over every group, so a
           chicken shop's Regular/Spicy choice was headed SIZE. */
        $variantTitle = trim((string) (optional($m->variantOptions->first())->title ?? ''));

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
            'gallery' => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryUrls($m->gallery_images, $store->id ?? null)
                : [],
            'price' => (float) $m->price,
            'type'  => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'=> $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : '',
            'rating_count' => (int) ($m->rating_count ?? 0),
            'reviewList' => \Juwri\Core\Support\ViewHelpers::reviewList($reviewsByMenu->get($m->id, collect())),
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyListPlain($itemAllergies),
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
            'protein'          => $m->protein ?? null,
            'carbs'            => $m->carbs ?? null,
            'fat'              => $m->fat ?? null,
            'prep_time'        => $m->prep_time ?? ($m->preparation_time ?? null),
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

    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */

    /* section visibility */
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

    /* engagement extras */
    $loyaltyProgram      = storefront()->loyaltyProgram($store);
    $reservationsEnabled = !empty($postOptions->enable_reservations);
    $waitlistEnabled     = !empty($postOptions->enable_waitlist);
    $googleReviewUrl     = trim((string) (@$postOptions->google_review_url ?? ''));

    $myWaitlist = storefront()->activeWaitlist($store, $customer);
    $myReservation = storefront()->upcomingReservation($store, $customer);

    /* F2 · brand / follow row (crave cvBrandRow parity) */
    $isVerifiedStore  = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
    $followerCount    = method_exists($store, 'follows') ? (int) $store->follows()->count() : 0;
    $isFollowing      = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;

    /* B8 · store-review display (avg rating + up to 2 approved cards). Dormant
       until the directory/reviews module is on (dark today) AND the store has
       approved reviews — else both blocks are omitted, so this ships safely dark. */
    $aboutReviews = collect();
    $aboutRating  = null;
    $aboutCount   = 0;
    if (\Juwri\Modules\Storefront\Services\StoreReviewsDisplay::enabledFor($store->id)) {
        $approvedReviews = \App\Models\StoreReview::approved()->forStore($store->id)->latest()->get(['reviewer_name', 'stars', 'body']);
        if ($approvedReviews->isNotEmpty()) {
            $aboutRating  = round((float) $approvedReviews->avg('stars'), 1);
            $aboutCount   = $approvedReviews->count();
            $aboutReviews = $approvedReviews->take(6);
        }
    }

    $customerApiToken = storefront()->customerApiToken($customer, 'ember');

    $logoUrl = media_url('storage/store/logo/', $store->main_image, $store->id ?? null);
    $bgUrl   = $featured ? media_url('storage/menu/', $featured->image, $store->id ?? null) : $logoUrl;
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- The backdrop URL is per-store data, so it cannot live in the static
         stylesheet — but a `style="…"` ATTRIBUTE cannot be covered by the CSP
         nonce, whereas a nonced style ELEMENT can. (Keep the words apart:
         CspEnforceTest greps this file for un-nonced style tags and a literal
         one written out in a comment trips it.) --}}
    <style nonce="{{ csp_nonce() }}">.ember .bg-photo{background-image:url('{{ $bgUrl }}')}</style>
    @if($showBranches)
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    @endif
@endpush

@section('content')
<div class="ember" id="ember" data-slug="{{ $store->slug }}">
  <div class="app" id="app">

    {{-- ══════════ BLURRED FOOD BACKDROP ══════════ --}}
    <div class="bg" aria-hidden="true">
      <div class="bg-photo"></div>
      <div class="bg-bokeh"><i></i><i></i><i></i><i></i><i></i></div>
      <div class="bg-tint"></div>
    </div>

    {{-- ══════════ SPLASH ══════════ --}}
    {{-- Customizer v2: `splash.enabled` says whether there is a splash at all and
         `splash.style` picks which one, so the bespoke intro and the shared layer
         can never stack. Defaults (enabled + theme) render this one unchanged. --}}
    @if($sectionVisible['hero'] && theme_setting($store->id, 'splash.style', 'theme') === 'theme')
    <div class="splash" id="splash">
      <div class="embers" id="splashEmbers" aria-hidden="true"></div>
      <div class="splash-mark" aria-hidden="true">
        @if($store->main_image && $store->main_image !== 'default.png')
          @if((string) ($logoUrl) !== '')<img src="{{ $logoUrl }}" alt="" data-csp-error="remove">@endif
        @else
          <svg viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>
        @endif
      </div>
      <h1 class="splash-word">{{ $store->title }}</h1>
      <p class="splash-tag" data-tc-text="splash.title">{{ theme_setting($store->id, 'splash.title', ___('Good Food, Good Mood')) }}</p>
      <button class="splash-skip gl" id="splashSkip" type="button">{{ ___('Skip') }}</button>
    </div>
    {{-- The intro plays once per browser session, not on every reload — that repeat
         splash read as a "flash of an unstyled screen" on every refresh. This runs
         during parse (before first paint) so a reload never replays it; script.js
         honours the same flag for its auto-dismiss timing. --}}
    <script nonce="{{ csp_nonce() }}">
      try { if (sessionStorage.getItem('em_splash_seen') === '1') document.getElementById('splash').classList.add('is-done'); } catch (e) {}
    </script>
    @endif

    {{-- ══════════ VIEWS ══════════ --}}
    <main class="views" id="views">

      {{-- HOME --}}
      <section class="view is-active" data-view="home">
        @php
            $__emHasCover = !empty($store->cover_image) && $store->cover_image !== 'default.png';
            $emCoverUrl = $__emHasCover ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) : null;
            $emShowCover = $__emHasCover && theme_setting($store->id, 'brand.show_cover', true);
        @endphp
        {{-- Collapsing header: slides in once the cover header leaves the scroll
             container (ember's chips are not top-sticky, so no overlap guard). --}}
        <div class="cv-condensed" id="cvCondensed" aria-hidden="true">
          <span class="cv-condensed-logo">@if(!empty($store->main_image) && $store->main_image !== 'default.png')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif</span>
          <b class="cv-condensed-name">{{ $store->title }}</b>
          <span class="cv-condensed-actions">
            @if(count($menu_languages ?? []) > 1)
            <button class="iconbtn gl" type="button" data-cv-proxy="lang" aria-label="{{ ___('Language') }}">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
            </button>
            @endif
            @if(storefront_catering_enabled($store->id))
            <button class="iconbtn gl" type="button" data-cv-proxy="catering" aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
            </button>
            @endif
            <button class="iconbtn gl" type="button" data-cv-proxy="notif" aria-label="{{ ___('Notifications') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
            </button>
          </span>
        </div>
        <div class="view-scroll">
          @if($emShowCover)
          {{-- Full-bleed cover with a flame-tinted bottom scrim (steakhouse dark). --}}
          <div class="ember-cover">
            <div class="ember-cover-media" aria-hidden="true">
              @if((string) ($emCoverUrl) !== '')<img class="ember-cover-img" src="{{ $emCoverUrl }}" alt="" data-csp-error="hide">@endif
              <span class="ember-cover-scrim"></span>
            </div>
          @endif
          <header class="home-top">
            <button class="iconbtn gl" id="menuBtn" type="button" aria-label="{{ ___('Menu') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
            </button>
            @if($showBranches)
            {{-- crave-parity branch selector: active branch + chevron, opens the branch sheet --}}
            <button class="loc" type="button" data-csp-open-sheet="mbBranchSheet" data-csp-scrim="scrim" aria-label="{{ ___('Choose branch') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
              <b>{{ $activeBranch->name ?? $branches->first()->name ?? $store->title }}</b>
              <svg class="ic chev" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            @endif
            <span class="home-spacer"></span>
            @if(count($menu_languages ?? []) > 1)
            {{-- Language: a globe opening the sheet below, so the header holds one
                 control whether the store runs two languages or eleven. The shared
                 language-switch partial delegates on [data-lang-code]. --}}
            <button class="iconbtn gl" id="langBtn" type="button" aria-label="{{ ___('Language') }}" aria-haspopup="dialog">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14 0 18M12 3c-3 3.5-3 14 0 18"/></svg>
            </button>
            @endif
            @if(storefront_catering_enabled($store->id))
            {{-- Catering & Events: opens the dedicated #cvCateringSheet (wired in script.js). --}}
            <button class="iconbtn gl" id="cvCateringBtn" type="button" data-catering-open aria-label="{{ ___('Catering & Events') }}" title="{{ ___('Catering & Events') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M4 17h16"/><path d="M5 17a7 7 0 0 1 14 0"/><path d="M12 7V5"/><circle cx="12" cy="4" r="1"/><path d="M3 20h18"/></svg>
            </button>
            @endif
            {{-- crave-parity: the avatar opens the customer PROFILE — shows the customer
                 (initial signed-in, person glyph as guest), not the store logo. --}}
            <button class="avatar{{ $customer ? ' is-me' : '' }}" id="avatarBtn" type="button" aria-label="{{ ___('Profile') }}">
              @if($customer)
                <span class="avatar-initial">{{ Str::upper(Str::substr($customer->name ?: $customer->phone ?: '?', 0, 1)) }}</span>
              @else
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
              @endif
            </button>
            {{-- Notification-center bell (shared partial, rev/notif-center-spec.md).
                 Not gated: guests use recent-orders, logged-in diners the inbox. --}}
            <button class="iconbtn gl" id="mbNotifBell" type="button" aria-label="{{ ___('Notifications') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/></svg>
              <i class="dot" id="mbNotifDot" hidden></i>
            </button>
          </header>
{{-- MC-34: display-currency estimate notice mounts here (shared partial), a normal section right under the topbar --}}
<div class="qc-cen-slot" data-mb-cur-mount></div>


          <p class="hello">{{ ___('Welcome to') }} {{ $store->title }} 👋</p>
          @php
              /* WI-M1 (rev/TECH-LEAD-AUDIT-2026-08-05 §MEDIUM) — this was the ONLY
                 unescaped theme_setting() echo in the 17 kept templates: a merchant-typed
                 (or impersonating-reseller-typed) string rendered as raw HTML into every
                 diner's page, in the origin where they hold a `customer` session.

                 Why not a plain `{{ }}`: the shipped theme default IS markup — `<br>` plus
                 the `.hot` gradient span — and ThemeCustomizerService::defaults() copies
                 that exact string into theme_settings.settings the first time a store
                 saves the customizer. A blanket escape would print those tags as visible
                 text on every ember store that has ever opened the editor.

                 So: escape EVERYTHING with e(), then restore only the two fixed tag shapes
                 the shipped default uses. The restore runs on already-escaped text and
                 matches literal byte sequences, so no attribute, event handler, URL or
                 script can survive it: an injected image error-handler stays escaped, and a
                 single-quoted `<span class='hot'>` is NOT restored (documented, accepted).
                 Do not widen this list without a test for each addition. */
              $__emberHeroDefault = ___('Good Food').'<br><span class="hot">'.___('Good Mood!').'</span>';
              $__emberHeroRaw = theme_setting($store->id, 'texts.featured_title', $__emberHeroDefault);
              $__emberHeroRaw = is_string($__emberHeroRaw) ? $__emberHeroRaw : $__emberHeroDefault;
              $__emberHero = str_replace(
                  ['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;', '&lt;span class=&quot;hot&quot;&gt;', '&lt;/span&gt;'],
                  ['<br>', '<br>', '<br>', '<span class="hot">', '</span>'],
                  e($__emberHeroRaw)
              );
          @endphp
          {{-- raw-ok (P3-13 audit): e() first, then only <br> and <span class="hot">…</span> are restored (exact list
               above, EmberHeroEscapingTest); nothing else of the owner's text can become markup. --}}
          <h1 class="hero-line" data-tc-text="texts.featured_title">{!! $__emberHero !!}</h1>

          {{-- Open/Closed status — reads the store's open_close_store switch (same
               $isOpen the rest of the theme uses). Absent before; a diner had no way
               to tell a closed kitchen from an open one until checkout failed. --}}
          <div class="statusrow">
            <span class="open-chip {{ $isOpen ? 'is-open' : 'is-closed' }}" data-mb-openchip data-mb-closed-class="is-closed"><i></i><span data-mb-open-text>{{ $isOpen ? ___('Open now') : ___('Closed') }}@if($__h = storefront_hours_today($store)) · {{ $__h }}@endif</span></span>
            @if(!empty($scans_today) && $scans_today > 0)
              <span class="viewers"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><b>{{ $scans_today }}</b> {{ ___('viewing now') }}</span>
            @endif
          </div>
          @if($emShowCover)
          </div>{{-- /.ember-cover --}}
          @endif

          {{-- F2 · brand / follow row (crave parity): store mark, name + verified
               badge, follower proof line, and a follow button. --}}
          @if($sectionVisible['brand_row'] ?? true)
          {{-- data-mb-hotel-chip-after: the room chip (shared hotel partial) mounts right
               AFTER this row, as its own full-width row. The .home-top bar above sits
               INSIDE .ember-cover, so slotting the chip there put it over the cover art,
               wedged between the menu and profile buttons. --}}
          <div class="brand-row{{ $emShowCover ? ' brand-row--hero' : '' }}" id="cvBrandRow" data-mb-hotel-chip-after>
            <span class="brand-logo gl">
              @if((string) ($logoUrl) !== '')<img src="{{ $logoUrl }}" alt="" data-csp-error="remove">@endif
            </span>
            <span class="brand-info">
              <b class="brand-name">{{ $store->title }}@if($isVerifiedStore)<span class="verified-badge" role="img" title="{{ ___('Official store') }}" aria-label="{{ ___('Official store') }}"><svg class="vb-seal" viewBox="0 0 24 24" aria-hidden="true"><path class="vb-seal-fill" d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.19L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z"/><path class="vb-seal-check" d="M10.09 16.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48z"/></svg></span>@endif</b>
              @if(trim((string)($store->sub_title ?? '')) !== '')
              <span class="brand-tagline">{{ $store->sub_title }}</span>
              @endif
              @include('store_templates._partials.follower-count', ['store' => $store, 'followerCount' => $followerCount, 'showFollowerCount' => theme_setting($store->id, 'brand.show_follower_count', true)])
            </span>
            @if($authMode !== 'guest_only' || $customer)
            <button class="followbtn {{ $isFollowing ? 'is-on' : '' }}" id="cvFollowBtn" type="button" data-following="{{ $isFollowing ? '1' : '0' }}">
              <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
              <span class="follow-label">{{ theme_setting($store->id, 'texts.follow_label', ___('Follow')) }}</span>
              <span class="following-label">{{ ___('Following') }}</span>
            </button>
            @endif
          </div>
          @endif

          {{-- B8 · store-review display — avg rating + up to 2 approved cards.
               Self-gated: renders only when the reviews module is on and the
               store has approved reviews (dark today), so it ships dormant.
               Reuses the .gl glass surface for theme-consistent cards. --}}
          @if($aboutRating !== null)
          <div class="em-reviews" data-mb-reviews-end>
            <div class="em-rating gl"><span class="em-rating-star" aria-hidden="true">★</span> {{ $aboutRating }} <small>· {{ $aboutCount }} {{ ___('Reviews') }}</small></div>
            @if($aboutReviews->isNotEmpty())
            <div class="em-review-list">
              @foreach($aboutReviews as $rev)
              <div class="em-review gl">
                <div class="em-review-head"><b>{{ $rev->reviewer_name ?: ___('Guest') }}</b><span class="em-review-star" aria-hidden="true">★ {{ $rev->stars }}</span></div>
                @if(!empty($rev->body))<p>{{ $rev->body }}</p>@endif
              </div>
              @endforeach
            </div>
            @endif
          </div>
          @endif

          @if(theme_setting($store->id, 'search.enabled', true))
          <div class="searchrow">
            <button class="search gl" id="searchOpen" type="button">
              <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
              <span data-tc-text="texts.search_placeholder">{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search your favorite food')) }}</span>
            </button>
            @if($allowDietary)
            <button class="filterbtn gl" id="filterOpen" type="button" aria-label="{{ ___('Filters') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
              <i class="dot" id="filterDot" hidden></i>
            </button>
            @endif
          </div>
          @endif

          {{-- story rail — stays hidden unless the stories endpoint returns items --}}
          <div class="stories" id="storiesRail" hidden></div>

          {{-- Admin-managed promo banners (shared slider, frozen contract). Self-gates:
               renders nothing when $menuBanners is empty. Sits above the promo card /
               Popular-Now rail and the category bar, mirroring feast/velvet. --}}
          <div class="em-banners">
            @include('store_templates.includes.menu-banners-slider')
          </div>

          @if($promoItem)
          <button class="promo gl" id="promoCard" data-open="{{ $promoItem->id }}" type="button">
            <span class="promo-copy">
              <span class="promo-chip gl">⏳ {{ ___('Featured') }}</span>
              <span class="promo-title">{{ $promoItem->name }}</span>
              <span class="promo-row">
                <span class="promo-cta grad" data-tc-text="texts.order_now">{{ theme_setting($store->id, 'texts.order_now', ___('Order Now')) }}</span>
              </span>
            </span>
            @if((string) (media_url('storage/menu/', $promoItem->image, $store->id ?? null)) !== '')<img class="promo-img" src="{{ media_url('storage/menu/', $promoItem->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
          </button>
          @endif

          @if($sectionVisible['daily_specials'])
          <div class="section-head">
            <h2 data-tc-text="texts.popular_title">{{ theme_setting($store->id, 'texts.popular_title', ___('Popular Now')) }}</h2>
            <button class="viewall" id="viewAll" type="button">{{ ___('View All') }}</button>
          </div>
          <div class="rail" id="popRail"></div>
          @endif

          {{-- Category selector sits DIRECTLY above the menu it filters: the promo
               card and Popular-Now rail live above it now, so nothing (and no injected
               offers/order-type strip) wedges between the categories and the items.
               data-mb-grid-lead tells the shared anchor to drop injected sections
               ABOVE this row rather than between it and the grid. --}}
          @if($sectionVisible['catbar'])
          <nav class="cats" id="catRow" data-mb-grid-lead data-mbt-anchor aria-label="{{ ___('Categories') }}">
            <button class="cat is-on" data-cat="all" type="button"><span class="emoji"><svg class="ic cat-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.2 13.7 9c.2.6.7 1.1 1.3 1.3l5.8 1.7-5.8 1.7c-.6.2-1.1.7-1.3 1.3L12 20.8 10.3 15c-.2-.6-.7-1.1-1.3-1.3L3.2 12 9 10.3c.6-.2 1.1-.7 1.3-1.3Z"/></svg></span><small>{{ ___('All') }}</small></button>
            @foreach($categories as $cat)
              <button class="cat" data-cat="{{ $cat->id }}" type="button">
                {{-- The stroked-SVG dome is the base layer and the owner's category
                     image sits over it, so a 404 simply reveals the theme's own icon
                     instead of swapping in a full-colour burger emoji. --}}
                <span class="emoji">
                  <svg class="ic cat-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14a8 8 0 0 1 16 0"/><path d="M2.5 14h19"/><path d="M6.5 17.5h11"/></svg>
                  @if($cat->image)<img src="{{ media_url('storage/menu/', $cat->image, $store->id ?? null) }}" alt="" data-csp-error="remove">@endif
                </span>
                <small>{{ $cat->name }}</small>
              </button>
            @endforeach
          </nav>

          {{-- Secondary sub-category chips. One hidden group per parent that has
               item-bearing subcategories; JS reveals the active parent's group.
               "All" (data-sub="root") shows every descendant of the parent. --}}
          @if(!empty($subCatsOf))
          <div class="subchips" id="subChips" data-mb-grid-lead hidden role="group" aria-label="{{ ___('Sub-categories') }}">
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

          @if($sectionVisible['menu'])
          <div class="section-head">
            <h2 id="menuTitle">{{ ___('Menu') }}</h2>
          </div>
          <div class="mlist" id="menuList"></div>
          <div class="empty gl" id="menuEmpty" hidden>
            <svg class="empty-art" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14a8 8 0 0 1 16 0"/><path d="M2.5 14h19"/><path d="M6.5 17.5h11"/></svg>
            <p>{{ ___('Nothing here — try another category') }}</p>
          </div>
          @endif
          <div class="tail-space"></div>
        </div>
      </section>

      {{-- ITEM DETAIL --}}
      <section class="view view-sub" data-view="product">
        <div class="view-scroll" id="productScroll">
          <div class="p-hero">
            <button class="fab gl" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
            <button class="fab gl fav" id="pFav" type="button" aria-label="{{ ___('Favorite') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>
            <div class="embers" id="heroEmbers" aria-hidden="true"></div>
            <img class="p-img" id="pImg" alt="">
            {{-- per-item image gallery: thumbnails swap the hero image (source: jsItems.gallery) --}}
            <div class="p-gallery" id="pGallery" hidden></div>
          </div>
          <div class="p-sheet gl">
            <div class="p-toprow">
              <span class="chip-spicy" id="pSpice" hidden><svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 4c2 0 3 1 3 2 3 0 5 2 5 5 0 5-5 9-10 9-4 0-7-2-7-5 0-4 5-6 8-6 0-3 0-5 1-5Z"/></svg> <span>{{ ___('Spicy') }}</span></span>
              <span class="chip-soldout" id="pSoldout" hidden>{{ ___('Sold out') }}</span>
              <span class="spacer"></span>
              <button class="gridglyph is-on" id="pGridBtn" type="button" aria-label="{{ ___('Nutrition facts') }}">
                <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/></svg>
              </button>
            </div>
            <h1 class="p-title" id="pTitle">—</h1>
            <div class="p-meta-row">
              <span class="p-rate" id="pRate"></span>
              <b class="p-price" id="pPrice"></b>
            </div>
            <p class="p-desc" id="pDesc"></p>
            <div class="p-tags" id="pTags"></div>
            <div class="p-nutri" id="pNutri" hidden></div>

            <h3 class="p-sec" id="pCustomize" hidden>{{ ___('Customize') }}</h3>
            <div id="pSizeWrap" hidden>
              {{-- Filled per item from the owner's own MenuVariantOption title. --}}
              <p class="p-lbl" id="pSizeLabel">{{ ___('Options') }}</p>
              <div class="sizes" id="sizeRow"></div>
            </div>
            <div id="pGroups"></div>
            <div class="togs" id="togList"></div>
            <section class="product-reviews" id="pReviews"></section>
            <div class="tail-space tall"></div>
          </div>
        </div>
        @if($allow_order)
        <footer class="p-cta">
          <div class="qty gl" id="pQty">
            <button class="qty-btn" data-q="-1" type="button" aria-label="{{ ___('Decrease') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
            <output id="pQtyVal">1</output>
            <button class="qty-btn" data-q="1" type="button" aria-label="{{ ___('Increase') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button>
          </div>
          <button class="cta grad" id="addToCart" type="button"><span data-tc-text="texts.add_to_cart">{{ theme_setting($store->id, 'texts.add_to_cart', ___('Add to Cart')) }}</span><b id="pTotal"></b></button>
          {{-- shown by script.js once the cart has items: a direct jump to the cart --}}
          <button class="p-cart-jump gl" id="pCartJump" data-go="cart" type="button" hidden aria-label="{{ ___('View Cart') }}">
            <svg class="ic" viewBox="0 0 24 24"><path d="M3 5h2.2l2.5 10.2a2 2 0 0 0 2 1.5h6.9a2 2 0 0 0 1.95-1.55L20.5 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
            <i class="p-cart-jump-count" id="pCartJumpCount" aria-hidden="true">0</i>
          </button>
        </footer>
        @endif
      </section>

      {{-- ACCOUNT — Orders / Offers / Profile merged into ONE tab with a segmented
           sub-switcher (was three separate dock destinations). Each sub-tab's markup
           and ids are unchanged, so their existing handlers keep working. --}}
      <section class="view" data-view="profile">
        <div class="view-scroll">
          <header class="page-head"><h1>{{ ___('Profile') }}</h1></header>
          <div class="seg acct-seg" id="acctSeg" role="tablist">
            <button class="is-on" data-acct="orders" type="button">{{ ___('Orders') }}</button>
            <button data-acct="offers" type="button">{{ ___('Offers') }}</button>
            <button data-acct="settings" type="button">{{ ___('Profile') }}</button>
          </div>

          <div class="acct-panel" data-acct-panel="offers" hidden>
          @if($loyaltyProgram)
          <button class="loy-banner gl" id="loyaltyBanner" type="button">
            <svg class="loy-flame ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.2 13.7 9c.2.6.7 1.1 1.3 1.3l5.8 1.7-5.8 1.7c-.6.2-1.1.7-1.3 1.3L12 20.8 10.3 15c-.2-.6-.7-1.1-1.3-1.3L3.2 12 9 10.3c.6-.2 1.1-.7 1.3-1.3Z"/></svg>
            <span class="loy-copy"><b id="loyPts">—</b><small>{{ ___('Rewards — tap to redeem') }}</small></span>
            <span class="loy-go grad">{{ ___('Redeem') }}</span>
          </button>
          @endif
          <div class="offer-list">
            @if($allowCoupons)
            <div class="offer gl">
              <svg class="offer-art ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c3 3.5 5 6 5 9a5 5 0 0 1-10 0c0-1.6.7-3 2-4.4.4 1.4 1.2 2.2 2 2.4-.4-2.6.3-5 1-7Z"/></svg>
              <div class="offer-body">
                <b>{{ ___('Have a promo code?') }}</b>
                <small>{{ ___('Apply it in your cart at checkout') }}</small>
              </div>
              <button class="offer-apply grad" data-go="cart" type="button">{{ ___('Open cart') }}</button>
            </div>
            @endif
            @if($reservationsEnabled)
            <div class="offer gl">
              <svg class="offer-art ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v8h10V3"/><path d="M6 11h12l-1 4H7Z"/><path d="M8 15v6M16 15v6"/></svg>
              <div class="offer-body">
                <b>{{ ___('Reserve a table') }}</b>
                <small>{{ ___('Book a table ahead of time') }}</small>
              </div>
              <button class="offer-apply grad" id="offersReserve" type="button">{{ ___('Book') }}</button>
            </div>
            @endif
            @if($waitlistEnabled)
            <div class="offer gl">
              <svg class="offer-art ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10M7 21h10"/><path d="M8 3c0 4 8 5 8 9s-8 5-8 9"/><path d="M16 3c0 4-8 5-8 9s8 5 8 9"/></svg>
              <div class="offer-body">
                <b>{{ ___('Join the waitlist') }}</b>
                <small>{{ ___('Get a spot in the queue') }}</small>
              </div>
              <button class="offer-apply grad" id="offersWaitlist" type="button">{{ ___('Join') }}</button>
            </div>
            @endif
            @if($googleReviewUrl)
            <a class="offer gl" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener">
              <svg class="offer-art ic" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.7l5.9-.8Z"/></svg>
              <div class="offer-body">
                <b>{{ ___('Rate your experience') }}</b>
                <small>{{ ___('Rate us on Google') }}</small>
              </div>
              <span class="offer-apply grad">{{ ___('Go') }}</span>
            </a>
            @endif
            @if($waNumber)
            <a class="wa-line gl" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
              <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
              <span>{{ ___('Chat with us on WhatsApp') }}</span>
              <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            @endif

            {{-- Share this menu — native profile row (like the WhatsApp .wa-line),
                 not the floating pill. Gated on the same share.fallback_button
                 toggle; data-web-share makes _partials/share stand down. --}}
            @if(theme_setting($store->id, 'share.fallback_button'))
            <button class="wa-line gl share-btn" id="mbShareRow" type="button" data-web-share
                    aria-label="{{ ___('Share this menu') }}" data-copied="{{ ___('Link copied') }}">
              <svg class="ic" viewBox="0 0 24 24"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg>
              <span>{{ ___('Share this menu') }}</span>
              <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </button>
            @endif
          </div>
          </div>{{-- /offers panel --}}

          <div class="acct-panel" data-acct-panel="orders">
          @if($customer)
            <a class="order-row gl" href="{{ route('customer.profile', $store->id) }}">
              <span class="order-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg></span>
              <div><b>{{ ___('Profile & settings') }}</b><small>{{ ___('Details, addresses & preferences') }}</small></div>
              <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            <a class="order-row gl" href="{{ route('customer.account', $store->id) }}">
              <span class="order-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg></span>
              <div><b>{{ ___('View order history') }}</b><small>{{ ___('All your orders in one place') }}</small></div>
              <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </a>
          @else
            @php $lookupByNumber = (bool) ($postOptions->order_lookup_by_number ?? false); @endphp
            <p class="sheet-sub">{{ $lookupByNumber ? ___('Find your orders with the phone or email used at checkout, or your order number.') : ___('Find your orders with the phone or email used at checkout.') }}</p>
            <div class="panel gl">
              {{-- `lookup-field` is its OWN class. It used to reuse `.coupon-field`,
                   and because it sits earlier in the DOM the coupon code's bare
                   `document.querySelector('.coupon-field')` decorated THIS box as
                   "applied" while the real coupon field never changed. --}}
              <div class="lookup-field is-solid">
                <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                <input aria-label="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" id="lookupInput" type="search" placeholder="{{ $lookupByNumber ? ___('Phone, email, or order number') : ___('Phone or email used at checkout') }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
                <button class="minipill grad" id="lookupBtn" type="button">{{ ___('Find') }}</button>
              </div>
            </div>
            <div class="orders-list stack-sm" id="lookupResults"></div>
          @endif
          <div class="empty gl" id="ordersEmpty" @if($customer) hidden @endif>
            <svg class="empty-art" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6"/></svg>
            <p>{{ ___('Find an order you placed, or browse the menu') }}</p>
            <button class="minipill grad" data-go="home" type="button">{{ ___('Browse menu') }}</button>
          </div>
          </div>{{-- /orders panel --}}

          <div class="acct-panel" data-acct-panel="settings" hidden>
            <div class="me-card gl">
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
            <div class="pref-row stack">
              <span><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.6M12 18.9v2.6M21.5 12h-2.6M5.1 12H2.5M18.7 5.3l-1.8 1.8M7.1 16.9l-1.8 1.8M18.7 18.7l-1.8-1.8M7.1 7.1 5.3 5.3"/></svg>{{ ___('Appearance') }}</span>
              <div class="appearance-row" id="appearanceRow" role="group">
                <button type="button" data-appearance="dark" aria-pressed="true"><svg class="ic" viewBox="0 0 24 24"><path d="M20 13A8 8 0 1 1 11 4a6.5 6.5 0 0 0 9 9Z"/></svg><span>{{ ___('Dark') }}</span></button>
                <button type="button" data-appearance="light" aria-pressed="false"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.4M12 19.1v2.4M21.5 12h-2.4M5 12H2.5M18.4 5.6l-1.7 1.7M7.4 16.6l-1.7 1.7M18.4 18.4l-1.7-1.7M7.4 7.4 5.6 5.6"/></svg><span>{{ ___('Light') }}</span></button>
                <button type="button" data-appearance="system" aria-pressed="false"><svg class="ic" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8.5 20.5h7M12 17.5v3"/></svg><span>{{ ___('System') }}</span></button>
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
            {{-- Privacy utility: wipe the cart / favourites / saved addresses this
                 browser keeps (shared window.mbClearDeviceData engine). Sits in the
                 guest-reachable settings panel, mirroring the appearance/language
                 preference rows; the light/dark choice is preserved by the engine. --}}
            <div class="em-device-data">
              <button class="em-cleardata gl" id="emClearData" type="button">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M10 7V5a2 2 0 0 1 2-2h0a2 2 0 0 1 2 2v2M7 7l1 12a2 2 0 0 0 2 1.8h4a2 2 0 0 0 2-1.8L17 7M10 11v6M14 11v6"/></svg>
                <span>{{ ___('Clear saved data on this device') }}</span>
                <svg class="ic ic-xs arr" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
              </button>
              <p class="em-device-data-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
            </div>
            <div class="biz-card">
              @if($store->address)<span><svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $store->address }}</span>@endif
              @if($store->phone)<span><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg><a href="tel:{{ $store->phone }}">{{ $store->phone }}</a></span>@endif
            </div>
            <p class="demo-foot">{{ $store->title }}</p>
          </div>{{-- /settings panel --}}

          <div class="tail-space"></div>
        </div>
      </section>

      {{-- FAVORITES --}}
      <section class="view" data-view="favs">
        <div class="view-scroll">
          <header class="page-head"><h1>{{ ___('Favorite') }}</h1></header>
          <div class="mlist" id="favList"></div>
          <div class="empty gl" id="favEmpty" hidden>
            <svg class="empty-art" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.6-7 9-7 9Z"/></svg>
            <p>{{ ___('Tap the heart on any dish to save it here') }}</p>
          </div>
          <div class="tail-space"></div>
        </div>
      </section>

      {{-- CART --}}
      <section class="view view-sub" data-view="cart">
        <div class="view-scroll">
          <header class="page-head sub">
            <button class="fab gl inline" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
            <h1>{{ ___('My Cart') }}</h1>
            <button class="linkbtn" id="clearCart" type="button">{{ ___('Clear') }}</button>
          </header>
          <div class="cart-list" id="cartList"></div>
          <div class="empty gl" id="cartEmpty" hidden>
            <svg class="empty-art" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8h14l-1 12H6Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
            <p>{{ ___('Your cart is still hungry') }}</p>
            <button class="minipill grad" data-go="home" type="button">{{ ___('Browse menu') }}</button>
          </div>

          @if($allow_order)
          <div class="cart-tools" id="cartTools">
            <section class="panel gl">
              <h3>{{ ___('Order type') }}</h3>
              <div class="seg" id="otSeg">
                @if($allow_delivery)<button data-ot="delivery" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg><span data-tc-text="texts.delivery">{{ theme_setting($store->id, 'texts.delivery', ___('Delivery')) }}</span></button>@endif
                @if($allow_takeaway)<button data-ot="takeaway" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><span data-tc-text="texts.pickup">{{ theme_setting($store->id, 'texts.pickup', ___('Pickup')) }}</span></button>@endif
                @if($allow_on_table)<button data-ot="on-table" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg><span>{{ ___('Dine-in') }}</span></button>@endif
                {{-- Hotel in-room dining. $allow_room_service is 1 only when hotel mode is on
                     AND the scanned QR resolved to an area_type='room' table, so this never
                     renders for an ordinary restaurant. --}}
                @if($allow_room_service)<button data-ot="room_service" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M3 18h18M5 18a7 7 0 0 1 14 0M12 6v2M10.5 6h3"/></svg><span>{{ ___('Room service') }}</span></button>@endif
              </div>
              <div class="ot-fields" id="otFields"></div>
              @if($allowOrderSched)
              <div class="schedule-row">
                <span class="sched-label"><svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ ___('Schedule for later') }}</span>
                <input aria-label="{{ ___('Schedule for later') }}" type="datetime-local" id="scheduledAt" class="when-time" min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}" max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
              </div>
              @endif
              @if($allowWaiter && $allow_on_table)
              <div class="waiter-row" id="waiterRow" hidden>
                <button class="minipill gl" id="callWaiter" type="button"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 17h16M6 17a6 6 0 0 1 12 0M12 11V9"/><circle cx="12" cy="7.4" r="1.2"/></svg><span>{{ ___('Call waiter') }}</span></button>
                <button class="minipill gl" id="requestBill" type="button"><svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M6 3h12v18l-2-1.4L14 21l-2-1.4L10 21l-2-1.4L6 21V3Z"/><path d="M9 8h6M9 12h6"/></svg><span>{{ ___('Request bill') }}</span></button>
              </div>
              @endif
            </section>

            @if($allowCoupons)
            <section class="panel gl">
              <label class="coupon-field">
                <svg class="ic" viewBox="0 0 24 24"><path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.5 2.5 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.5 2.5 0 0 0 0-6Z"/><path d="M14 6.5v2M14 11v2M14 15.5v2" stroke-dasharray="1 3"/></svg>
                <input id="couponInput" placeholder="{{ ___('Promo code') }}">
                <button class="minipill grad" id="couponApply" type="button">{{ ___('Apply') }}</button>
                {{-- An applied coupon collapses the field into a removable chip.
                     Before this there was no way to take a code off except typing
                     over it. --}}
                <button class="coupon-clear" id="couponClear" type="button" hidden
                        aria-label="{{ ___('Remove coupon') }}">
                  <svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
              </label>
              <p class="coupon-msg" id="couponMsg" hidden></p>
            </section>
            @endif

            <section class="panel gl">
              <label class="cv-h3 block-label">{{ ___('Note') }} ({{ ___('optional') }})</label>
              <textarea aria-label="{{ ___('Any special requests?') }}" id="orderNote" rows="2" placeholder="{{ ___('Any special requests?') }}"></textarea>
              @include('store_templates._partials.voice-note-recorder')
            </section>

            <section class="panel gl totals" id="cartTotals"></section>
            <p class="minorder" id="minOrderMsg" hidden></p>

            @if($waNumber)
            <a class="wa-line gl" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener"><svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg><span>{{ ___('Order on WhatsApp instead') }}</span><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></a>
            @endif
          </div>
          @endif
          <div class="tail-space tall"></div>
        </div>
        @if($allow_order)
        <footer class="p-cta" id="cartCta" hidden>
          <button class="cta grad wide" id="goCheckout" type="button"><span data-tc-text="texts.checkout_button">{{ theme_setting($store->id, 'texts.checkout_button', ___('Checkout')) }}</span><b id="cartCtaTotal"></b></button>
        </footer>
        @endif
      </section>

      {{-- CHECKOUT --}}
      <section class="view view-sub" data-view="checkout">
        <div class="view-scroll">
          <header class="page-head sub">
            <button class="fab gl inline" data-back type="button" aria-label="{{ ___('Back') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
            <h1>{{ ___('Payment') }}</h1>
          </header>

          <section class="cv-block gl">
            <span class="cv-h3">{{ ___('Contact') }}</span>
            <input aria-label="{{ ___('Your name') }}" id="coName" value="{{ $customer->name ?? '' }}" placeholder="{{ ___('Your name') }}" autocomplete="name">
            <div id="phoneField">
              <input aria-label="{{ ___('Phone') }}" id="coPhone" value="{{ $customer->phone ?? '' }}" placeholder="{{ ___('Phone') }}" inputmode="tel" autocomplete="tel">
            </div>
            <div id="tableField" hidden>
              @if($table)
                <input aria-label="{{ ___('Table number') }}" id="coTable" value="{{ $table->table_no }}" readonly>
              @else
                <input aria-label="{{ ___('Table number') }}" id="coTable" type="number" placeholder="{{ ___('Table number') }}" inputmode="numeric">
              @endif
            </div>
            @if($authMode !== 'guest_only' && !$customer)
              <button class="linkbtn" id="loginLink" type="button">{{ ___('Have an account?') }} {{ ___('Login') }}</button>
            @endif
          </section>

          <section class="cv-block gl" id="addrBlock" hidden>
            <span class="cv-h3">{{ ___('Delivery address') }}</span>
            {{-- Saved addresses: logged-in customers get their address book; guests get
                 a list JS builds from addresses used before on this device, so a
                 returning guest can pick a previous address. Tap fills field + coords. --}}
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
            <div class="saved-addrs" id="savedAddrs" hidden></div>
            @endif
            {{-- address autocomplete + use-my-location (geo.autocomplete / geo.reverse) --}}
            <div class="addr-field" id="coAddrField">
              <input aria-label="{{ ___('Street, building, floor…') }}" id="coAddr" placeholder="{{ ___('Street, building, floor…') }}" autocomplete="off" autocorrect="off" spellcheck="false"
                     value="{{ $customerDefaultAddress ? $addrLabel($customerDefaultAddress) : '' }}">
              <input type="hidden" id="coLat" value="{{ $customerDefaultAddress->lat ?? '' }}">
              <input type="hidden" id="coLng" value="{{ $customerDefaultAddress->lng ?? '' }}">
              <button class="uselocbtn" id="coUseLocation" type="button" title="{{ ___('Use my location') }}" aria-label="{{ ___('Use my location') }}">
                <svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
              </button>
              <div class="addr-suggest" id="coAddrSuggest" hidden></div>
            </div>
            <p class="geo-attrib" id="coGeoAttrib" hidden></p>
            {{-- Drop-a-pin address picker (shared window.mbMapPicker engine, ember-skinned sheet below). --}}
            <button class="em-map-trigger gl" id="emAddrMapBtn" type="button">
              <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
              <span>{{ ___('Choose on map') }}</span>
            </button>
          </section>

          <section class="cv-block gl">
            <span class="cv-h3">{{ ___('Pay with') }}</span>
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
          <section class="cv-block gl">
            <span class="cv-h3">{{ ___('Store credit') }}</span>
            <label class="terms">
              <input type="checkbox" id="mbUseWallet">
              <span>{{ ___('Pay with store credit') }} ({{ store_money_format($storeCredit, $store->id) }} {{ ___('available') }})</span>
            </label>
          </section>
          @endif

          @if($allowTips)
          <section class="cv-block gl">
            <span class="cv-h3">{{ ___('Add a tip') }}</span>
            <div class="tip-row" id="tipRow">
              <button data-tip="0" class="is-on" type="button">{{ ___('None') }}</button>
              <button data-tip="5" type="button">5%</button>
              <button data-tip="10" type="button">10%</button>
              <button data-tip="15" type="button">15%</button>
            </div>
          </section>
          @endif

          @if(!$customer)
          <section class="cv-block gl">
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
          <section class="cv-block gl ck-items" id="ckItems" hidden></section>
          <section class="cv-block gl totals" id="coTotals"></section>
          <p class="err-box" id="formError" hidden></p>
          <div class="tail-space tall"></div>
        </div>
        <footer class="p-cta">
          <button class="cta grad wide" id="placeOrder" type="button"><span class="po-label">{{ ___('Place Order') }}</span><b id="placeTotal"></b><span class="spinner" hidden></span></button>
        </footer>
      </section>

      {{-- CONFIRMED --}}
      <section class="view view-sub view-center" data-view="confirmed">
        <div class="confirm-wrap">
          <div class="embers tall" id="confirmEmbers" aria-hidden="true"></div>
          <div class="confirm-ring grad" aria-hidden="true">
            <svg class="confirm-check" viewBox="0 0 24 24"><path d="m5 12.5 4.6 4.5L19 7" pathLength="1"/></svg>
          </div>
          <h1 class="confirm-title">{{ ___('Order Confirmed') }}</h1>
          <p class="confirm-sub">{{ ___('Your order is on the fire') }}<br><span class="confirm-eta">{{ ___('Redirecting you to live tracking…') }}</span></p>
          {{-- The order number the customer quotes / sees on the track page.
               submitOrder returns res.order_id; filled in by JS. --}}
          <p class="confirm-code" id="confirmOrderNo" hidden></p>
          <div class="confirm-actions">
            <a class="cta grad wide" id="confirmTrack" href="#"><span>{{ ___('Track Order') }}</span></a>
            <button class="ghostbtn" data-go="home" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span>{{ ___('Back to menu') }}</span></button>
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

    </main>

    {{-- Notification-center panel (shared partial; bell lives in the home header). --}}
    @include('store_templates._partials.notif-bell')

    {{-- ══════════ DOCK: CART PILL + GLASS NAV ══════════ --}}
    <div class="dock" id="dock">
      @if($allow_order)
      <button class="cartpill gl" id="cartPill" data-go="cart" type="button" hidden>
        <span class="cp-badge grad" id="cpCount">0</span>
        <span>{{ ___('View Cart') }}</span>
        <b id="cpTotal"></b>
        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
      </button>
      @endif
      <nav class="tabbar gl" id="tabbar" aria-label="{{ ___('Menu') }}">
        <button class="tab is-on" data-tab="home" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg><span class="tab-label">{{ ___('Home') }}</span></button>
        <button class="tab" data-tab="menu" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg><span class="tab-label">{{ ___('Menu') }}</span></button>
        @if(theme_setting($store->id, 'search.enabled', true))
        <button class="tab" data-tab="search" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/></svg><span class="tab-label">{{ ___('Search') }}</span></button>
        @endif
        @if($allow_order)
        <button class="tab" data-tab="cart" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><span class="tab-label">{{ ___('Cart') }}</span></button>
        @endif
        <button class="tab" data-tab="favs" type="button"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg><span class="tab-label">{{ ___('Favorite') }}</span></button>
        {{-- Orders, Offers and Profile are merged into one data-view="profile" with a
             segmented sub-switcher; this single tab opens it (was three buttons). --}}
        <button class="tab" data-tab="profile" type="button"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg><span class="tab-label">{{ ___('Profile') }}</span></button>
      </nav>
    </div>

    {{-- ══════════ SHEETS & OVERLAYS ══════════ --}}
    <div class="scrim" id="scrim" hidden></div>

    {{-- hamburger menu sheet --}}
    <div class="sheet" id="menuSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ $store->title }}</h2>
      <div class="menu-rows">
        @if($loyaltyProgram)
        <button class="mrow-link" data-msheet="loyalty" type="button"><span class="mr-ic grad"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.2 13.7 9c.2.6.7 1.1 1.3 1.3l5.8 1.7-5.8 1.7c-.6.2-1.1.7-1.3 1.3L12 20.8 10.3 15c-.2-.6-.7-1.1-1.3-1.3L3.2 12 9 10.3c.6-.2 1.1-.7 1.3-1.3Z"/></svg></span><b>{{ ___('Loyalty & Rewards') }}</b><small id="menuPts"></small></button>
        @endif
        @if($reservationsEnabled)
        <button class="mrow-link" data-msheet="reserve" type="button"><span class="mr-ic gl"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v8h10V3"/><path d="M6 11h12l-1 4H7Z"/><path d="M8 15v6M16 15v6"/></svg></span><b>{{ ___('Reserve a table') }}</b></button>
        @endif
        @if($waitlistEnabled)
        <button class="mrow-link" data-msheet="waitlist" type="button"><span class="mr-ic gl">⏳</span><b>{{ ___('Join the waitlist') }}</b></button>
        @endif
        @if($googleReviewUrl)
        <a class="mrow-link" href="{{ $googleReviewUrl }}" target="_blank" rel="noopener"><span class="mr-ic gl">⭐</span><b>{{ ___('Rate your experience') }}</b></a>
        @endif
        @if($waNumber)
        <a class="mrow-link" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener"><span class="mr-ic gl"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg></span><b>{{ ___('Order on WhatsApp') }}</b></a>
        @endif
        @if($showBranches)
        <button class="mrow-link" id="emberNearestBranchBtn" type="button"><span class="mr-ic gl"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg></span><b>{{ ___('Choose branch') }}</b></button>
        @endif
        <button class="mrow-link" data-acct-go="settings" type="button"><span class="mr-ic gl"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M21.5 12h-3M5.5 12h-3M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1M18.7 18.7l-2.1-2.1M7.4 7.4 5.3 5.3"/></svg></span><b>{{ ___('Settings') }}</b></button>
      </div>
    </div>

    {{-- profile / settings sheet --}}
    @if($loyaltyProgram)
    <div class="sheet" id="loyaltySheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Loyalty & Rewards') }}</h2>
      <div class="pts-card grad">
        <small>{{ $loyaltyProgram->type === 'stamps' ? ___('Your stamps') : ___('Your points') }}</small>
        <b id="ptsVal">–</b>
        <div class="pts-bar"><i id="ptsBar"></i></div>
        <small class="pts-hint">{{ ___('to earn:') }} {{ $loyaltyProgram->reward_description }}</small>
      </div>
      <button class="cta grad wide" id="loyaltyRedeem" type="button" hidden><span>{{ ___('Redeem Reward') }}</span></button>
      <p class="loyalty-ok" id="loyaltyOk" hidden>{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
    </div>
    @endif

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
          <svg class="sheet-done-art" viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="m9 15 2 2 4-4"/></svg>
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
          @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
          <button class="cta grad wide stack-sm" type="submit"><span>{{ ___('Request Reservation') }}</span></button>
        </form>
      @endif
    </div>
    @endif

    @if($waitlistEnabled)
    <div class="sheet" id="waitlistSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Join the Waitlist') }}</h2>
      @if(session('waitlist_joined'))
        <div class="sheet-done">
          <svg class="sheet-done-art" viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 5-13 8 8Z"/><path d="M14 4.5c1.2-1 2.6-.8 3.4.2M19 9c1-1.2.9-2.7-.2-3.5M15.5 10.5l1.5-1.5"/></svg>
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
          <button class="cta grad wide stack-sm" type="submit"><span>{{ ___('Join Waitlist') }}</span></button>
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

    {{-- nearest-branch Leaflet map picker (self-hosted tiles/markers) --}}
    @if($showBranches)
    <div class="sheet sheet-tall" id="branchMapSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Choose branch') }}</h2>
      {{-- Plain list of EVERY branch, always selectable via ?branch_id=. The geo list
           below only plots branches that carry coordinates, so a branch without
           lat/lng was invisible and switching felt unimplemented. --}}
      <div class="branch-list" id="emberBranchList">
        @foreach($branches as $branch)
          <a class="branch-row gl {{ ($activeBranch->id ?? null) === $branch->id ? 'is-on' : '' }}"
             href="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            <b>{{ $branch->name }}@if($branch->address)<small>{{ $branch->address }}</small>@endif</b>
          </a>
        @endforeach
      </div>
      {{-- Optional map + nearest-first list (geo). Enhancement only; the list above
           already covers every branch. --}}
      <div class="branchmap" id="emberBranchMap"></div>
      <p class="geo-attrib" id="emberMapAttrib"></p>
      <div class="branch-list" id="emberBranchGeoList"></div>
    </div>
    @endif

    {{-- Address map picker — ember-native bottom sheet driven by the shared
         window.mbMapPicker engine. Fills the SAME #coAddr the checkout submits,
         plus the hidden #coLat / #coLng coords. Always available (delivery is not
         gated on multi-branch), so it lives outside the $showBranches block. --}}
    <div class="sheet sheet-tall em-map-sheet" id="emMapSheet" hidden role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}">
      <div class="sheet-grab"></div>
      {{-- Explicit close (carries sheet-grab for the existing close wiring). --}}
      <button class="sheet-grab sheet-x" type="button" id="emMapClose" aria-label="{{ ___('Close') }}">
        <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
      <h2>{{ ___('Choose on map') }}</h2>
      <div class="branchmap em-map-canvas" id="emMapCanvas"></div>
      <p class="geo-attrib em-map-preview" id="emMapPreview">{{ ___('Move the map to place the pin') }}</p>
      <button class="cta grad wide stack-sm" id="emMapConfirm" type="button">
        <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
        <span>{{ ___('Use this location') }}</span>
      </button>
    </div>

    {{-- note sheet --}}
    <div class="sheet" id="noteSheet" hidden>
      <div class="sheet-grab"></div>
      <h2>{{ ___('Note for the kitchen') }}</h2>
      <p class="sheet-sub" id="noteItemName"></p>
      <textarea aria-label="{{ ___('No onions please…') }}" id="noteText" rows="3" placeholder="{{ ___('No onions please…') }}"></textarea>
      <button class="cta grad wide stack-sm" id="noteSave" type="button"><span>{{ ___('Save note') }}</span></button>
    </div>

    @if(storefront_catering_enabled($store->id))
    <div class="sheet" id="cvCateringSheet" hidden>
      <div class="sheet-grab"></div>
      @include('store_templates._partials.catering-form', ['cateringInline' => true])
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
      <h2>{{ ___('Login') }} / {{ ___('Register') }}</h2>
      <div class="seg stack-below" id="authTabs">
        <button data-auth-tab="login" class="is-on" type="button">{{ ___('Login') }}</button>
        <button data-auth-tab="otp" type="button">{{ ___('Phone OTP') }}</button>
        <button data-auth-tab="register" type="button">{{ ___('Register') }}</button>
      </div>
      <p class="err-box stack-below-sm" id="authErr" hidden></p>
      <div class="sheet-form" data-auth="login">
        <input aria-label="{{ ___('Email') }}" id="loginEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
        <input aria-label="{{ ___('Password') }}" id="loginPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="current-password">
        <button class="cta grad wide stack-xs" id="loginBtn" type="button"><span>{{ ___('Login') }}</span></button>
        @include('store_templates.partials.social-login-buttons', ['store' => $store])
        @include('store_templates.partials.passkey-login', ['store' => $store])
      </div>
      <div class="sheet-form" data-auth="register" hidden>
        <input aria-label="{{ ___('Your Name') }}" id="regName" placeholder="{{ ___('Your Name') }}" autocomplete="name">
        <input aria-label="{{ ___('Email') }}" id="regEmail" type="email" placeholder="{{ ___('Email') }}" autocomplete="email">
        <input aria-label="{{ ___('Phone number') }}" id="regPhone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
        <input aria-label="{{ ___('Password') }}" id="regPass" type="password" placeholder="{{ ___('Password') }}" autocomplete="new-password">
        <input aria-label="{{ ___('Confirm Password') }}" id="regPass2" type="password" placeholder="{{ ___('Confirm Password') }}" autocomplete="new-password">
        <button class="cta grad wide stack-xs" id="registerBtn" type="button"><span>{{ ___('Register') }}</span></button>
        @include('store_templates.partials.social-login-buttons', ['store' => $store])
      </div>
      {{-- A3 · phone-OTP login (posts the collected code under `code`) --}}
      <div class="sheet-form" data-auth="otp" hidden>
        <div id="emberOtpStep1">
          <span class="field-label">{{ ___('Phone number') }}</span>
          <input aria-label="{{ ___('Phone number') }}" id="emberOtpPhone" inputmode="tel" autocomplete="tel" placeholder="{{ ___('Phone number') }}">
          <button class="cta grad wide stack-xs" id="emberOtpSend" type="button"><span>{{ ___('Send code') }}</span></button>
        </div>
        <div id="emberOtpStep2" hidden>
          <span class="field-label">{{ ___('Enter the code we sent you') }}</span>
          <div class="otp-boxes" id="emberOtpBoxes">
            <input aria-label="{{ ___('Verification code') }} 1" maxlength="1" inputmode="numeric" autocomplete="one-time-code"><input aria-label="{{ ___('Verification code') }} 2" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 3" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 4" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 5" maxlength="1" inputmode="numeric"><input aria-label="{{ ___('Verification code') }} 6" maxlength="1" inputmode="numeric">
          </div>
          <button class="cta grad wide stack-xs" id="emberOtpVerify" type="button"><span>{{ ___('Verify') }}</span></button>
        </div>
      </div>
    </div>
    @endif

    {{-- search overlay --}}
    <div class="overlay" id="searchOverlay" hidden>
      <div class="ov-top">
        <label class="search gl grow">
          <svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
          <input id="searchInput" type="search" data-tc-text="texts.search_placeholder" placeholder="{{ theme_setting($store->id, 'texts.search_placeholder', ___('Search your favorite food')) }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
        </label>
        <button class="iconbtn gl" id="searchClose" type="button" aria-label="{{ ___('Close') }}"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
      </div>
      @if($allowDietary)
      <div class="diet-row" id="dietRow">
        <button data-diet="vegan" type="button" aria-pressed="false"><svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21c0-6 3-10 8-11 0 6-3 10-8 11Z"/><path d="M12 21c0-4-2-7-6-8"/></svg> <span>{{ ___('Vegan') }}</span></button>
        <button data-diet="halal" type="button" aria-pressed="false"><svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M16.5 4.5a8 8 0 1 0 0 15 6.5 6.5 0 0 1 0-15Z"/></svg> <span>{{ ___('Halal') }}</span></button>
        <button data-diet="gf" type="button" aria-pressed="false"><svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21V8"/><path d="M12 8c0-2.5 1.8-4.5 4-5 0 2.8-1.6 4.6-4 5Z"/><path d="M12 13c0-2.5-1.8-4.5-4-5 0 2.8 1.6 4.6 4 5Z"/></svg> <span>{{ ___('Gluten-free') }}</span></button>
        <button data-diet="spicy" type="button" aria-pressed="false"><svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 4c2 0 3 1 3 2 3 0 5 2 5 5 0 5-5 9-10 9-4 0-7-2-7-5 0-4 5-6 8-6 0-3 0-5 1-5Z"/></svg> <span>{{ ___('Spicy') }}</span></button>
      </div>
      @endif
      <div class="ov-results" id="searchResults"></div>
      {{-- Mode-aware: a failed SEARCH and a failed dietary FILTER need different
           copy and different escapes. It used to tell a searching customer to
           "try another category". The art is the theme's own stroked SVG, not an
           emoji dropped into a monochrome dark surface. --}}
      <div class="empty gl" id="searchEmpty" hidden>
        <svg class="empty-art" viewBox="0 0 24 24" aria-hidden="true">
          <circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>
        </svg>
        <p id="searchEmptyMsg">{{ ___('Nothing matches your search') }}</p>
        <button class="minipill" id="clearFilters" type="button" hidden>{{ ___('Clear filters') }}</button>
      </div>
    </div>

    {{-- full-screen story viewer --}}
    <div class="storyview" id="storyViewer" hidden>
      <div class="sv-progress" id="svProgress"></div>
      <button class="sv-close gl" id="svClose" type="button" aria-label="{{ ___('Close') }}">
        <svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
      <div class="sv-stage" id="svStage">
        <img class="sv-media" id="svMedia" alt="">
        <button class="sv-nav prev" id="svPrev" type="button" aria-label="{{ ___('Previous') }}"></button>
        <button class="sv-nav next" id="svNext" type="button" aria-label="{{ ___('Next') }}"></button>
        <div class="sv-foot">
          <p class="sv-caption" id="svCaption"></p>
          <a class="cta grad sv-cta" id="svCta" hidden><span>{{ ___('Order Now') }}</span></a>
        </div>
      </div>
    </div>

    <div class="toast gl" id="toast" role="status"></div>
    <div class="fly-layer" id="flyLayer" aria-hidden="true"></div>
  </div>{{-- /.app --}}
</div>{{-- /.ember --}}

{{-- Catering & Events now lives in #cvCateringSheet (opened from the header
     button); the form is included there with cateringInline=true so it does
     not self-relocate back to the bottom of the menu. --}}
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    @php
        $emI18n = [
            'welcome' => ___('Welcome to'), 'popular_now' => ___('Popular Now'), 'menu' => ___('Menu'),
            'full_menu' => ___('Full menu'), 'view_all' => ___('View All'), 'no_results' => ___('Nothing here — try another category'),
            'customize' => ___('Customize'), 'size' => ___('Size'), 'add_to_cart' => ___('Add to Cart'),
            'spicy' => ___('Spicy'), 'sold_out' => ___('Sold out'), 'starting_from' => ___('Starting from'),
            'my_cart' => ___('My Cart'), 'view_cart' => ___('View Cart'), 'cart_empty' => ___('Your cart is still hungry'),
            'added' => ___('Added to cart'), 'removed' => ___('Removed'), 'remove' => ___('Remove'), 'free_item' => ___('Free'),
            /* Neutral hints. These used to hardcode "Delivered in 25-35 min" and
               "Ready for pickup in ~15 min" — invented promises on a store whose
               real window is whatever the owner set. The real ETA is appended by
               the JS from `prepEta` when the owner has configured one. */
            'delivery_hint' => ___('Delivered to your address'), 'pickup_hint' => ___('Ready for pickup at the counter'),
            /* `___()` slugs its key, so this shares the existing `usually_ready_in_n_min`
               entry that already carries real translations — hence the {n} placeholder
               rather than a Laravel-style :n. */
            'eta_suffix' => ___('Usually ready in {n} min'),
            'choose_table' => ___('Your waiter brings it to the table'), 'table' => ___('Table'),
            'your_order' => ___('Your order'), 'edit' => ___('Edit'),
            'room_hint' => ___('Brought straight to your room'),
            'subtotal' => ___('Subtotal'), 'discount' => ___('Discount'), 'service' => ___('Service'),
            'tax' => $taxLabel, 'delivery_fee' => ___('Delivery fee'), 'freelbl' => ___('Free'),
            'tip_label' => ___('Tip'), 'total' => ___('Total'), 'min_order' => ___('Minimum order'),
            'select_type' => ___('Please choose an order type'), 'fill_contact' => ___('Please add your name'),
            'fill_addr' => ___('Please add a delivery address'), 'wallet_low' => ___('Not enough wallet balance'),
            'coupon_bad' => ___('Invalid promo code.'), 'waiter_ok' => ___('A waiter will be with you shortly!'),
            'bill_ok' => ___('Your bill is on its way!'), 'note_saved' => ___('Note saved'),
            'kcal' => ___('kcal'), 'min' => ___('min'), 'protein' => ___('Protein'), 'carbs' => ___('Carbs'), 'fat' => ___('Fat'),
            'd_vegan' => ___('Vegan'), 'd_halal' => ___('Halal'), 'd_gf' => ___('Gluten-free'),
            'daily' => ___('Daily special'), 'required' => ___('Required'), 'reviews' => ___('reviews'),
            'network_err' => ___('Network error. Please try again.'), 'something_wrong' => ___('Something went wrong.'),
            'pts' => ___('pts'), 'stamps' => ___('stamps'),
            'options' => ___('Options'),
            'no_search_results' => ___('Nothing matches your search'),
            'no_filter_results' => ___('No dishes match these filters'),
            'clear_filters' => ___('Clear filters'),
            'order_label' => ___('Order'),
            'use_location' => ___('Use my location'),
            'loc_unavailable' => ___('Location unavailable'),
            'loc_denied' => ___('Location denied'),
            'no_address' => ___('No address found'),
            'saved_address' => ___('Saved address'),
            'addr_ph' => ___('Street, building, floor…'),
            'reviews_title' => ___('Reviews'),
            'reviews_empty' => ___('No reviews yet.'),
            'rate_dish' => ___('Rate this dish'),
        ];
    @endphp
    window.EMBER = {
        restaurantSlug: @json($store->slug),
        storeId:   @json($store->id),
        currencySign:   @json($curSign),
        currencyGap:    @json(currency_symbol_gap($postOptions->currency_sign ?? '$')),
        currencyPos:    @json($curPos),
        {{-- TOTALS CONTRACT: the decimals must be THIS STORE's, not the global
             currency — Order::computeTax() rounds with
             currency_decimals($postOptions->currency_code), which diverges on any
             0- or 3-decimal currency (KWD/BHD/OMR/JOD/LYD/TND). --}}
        currencyDecimals: @json(currency_decimals($postOptions->currency_code ?? null)),
        moneyDecimals: @json(money_decimals($postOptions->currency_code ?? null)), {{-- bug batch 10 · 4: coupon / tax / tip arithmetic at the stored precision --}}
        decimalsByCode: @json(currency_decimals_map()),
        baseCurrency:   @json(config('settings.currency_code', 'USD')),
        currencyRates:  @json($mcRates),
        taxCharge:      @json($taxCharge),
        {{-- 'percentage' (default) or 'fixed' — a flat amount, NOT a rate. Without
             this the client always multiplied and quoted a flat-tax store a
             percentage of the basket. --}}
        taxType:        @json($taxType),
        taxLabel:       @json($taxLabel),
        {{-- The owner's real prep window (e.g. "25-35"), or null. Never invented. --}}
        prepEta:        @json(trim((string) (@$postOptions->prep_eta_static ?: '')) ?: null),
        deliveryCharge: @json($deliveryCharge),
        minOrderValue:  @json($minOrderValue),
        allowOrder:     @json((bool) $allow_order),
        allowPayment:   @json((bool) $allow_payment),
        allowTips:      @json((bool) $allowTips),
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
        openResSheet:      @json((bool) session('reservation_success')),
        openWaitlistSheet: @json((bool) session('waitlist_joined')),
        defaultImg:     @json(media_url('storage/menu/', 'default.png', $store->id ?? null)),
        leafletImgPath: @json(asset('assets/vendor/leaflet/images')),
        catKids:        @json((object) $catKids),
        items:          @json((object) $jsItems),
        endpoints: {
            submitOrder:   @json(route('store.submitOrder', $store->id)),
            coupon:      @json(route('store.applyCoupon', $store->id)),
            waiter:      @json(route('store.callTheWaiter', $store->id)),
            itemEvent:   @json(route('store.recordItemEvent', $store->slug)),
            orderLookup: @json(route('store.orderLookup', $store->slug)),
            follow:      @json(url('r/'.$store->slug.'/follow')),
            stories:     @json(route('stories.public', $store->slug)),
            login:       @json(route('customer.login', $store->id)),
            register:    @json(route('customer.register', $store->id)),
            otpRequest:  @json(route('customer.otp.request', $store->id)),
            otpVerifyLogin: @json(route('customer.otp.verify', $store->id)),
            logout:      @json(route('customer.logout', $store->id)),
            loyaltyBalance: @json($loyaltyProgram ? route('publicView.loyalty.balance', $store->id) : null),
            loyaltyRedeem:  @json($loyaltyProgram ? route('publicView.loyalty.redeem', $store->id) : null),
            branchesGeo:    @json($showBranches ? route('geo.branches', $store->slug) : null),
            geoAutocomplete: @json(route('geo.autocomplete')),
            geoReverse:      @json(route('geo.reverse')),
        },
        i18n: @json($emI18n),
    };
</script>
{{-- script.js is loaded once by store_templates.layout (avoids double-load) --}}
{{-- P3-13: totals from the server quote (THEME-CONTRACT §5.2). --}}
<script src="{{ asset('assets/store_templates/_shared/js/mb-quote.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-quote.js')) }}"></script>
@if($showBranches)
{{-- self-hosted Leaflet — must load before script.js (layout appends script.js after this stack) --}}
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
@endif
@endpush

@push('scripts_at_bottom')
{{-- Address map picker + clear-saved-data wiring. Ember is a shared-layout theme,
     so window.mbMapPicker / window.mbClearDeviceData are already global (the layout
     @includes the engines). We only drive them with ember's own sheet + controls. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var trigger = document.getElementById('emAddrMapBtn');
    if (!window.mbMapPicker) { if (trigger) { trigger.style.display = 'none'; } return; }
    var sheet = document.getElementById('emMapSheet');
    var hideT = null;
    /* Drive ember's native bottom sheet exactly like the theme's own sheet():
       toggle .is-open on the sheet + the shared #scrim, then hide after the 420ms
       slide-out. The scrim tap / Esc / drag-grab handlers in script.js already
       close any .sheet.is-open, so those affordances keep working for free. */
    function toggle(open) {
        if (!sheet) { return; }
        var scrim = document.getElementById('scrim');
        if (open) {
            clearTimeout(hideT);
            sheet.hidden = false; if (scrim) { scrim.hidden = false; }
            requestAnimationFrame(function () {
                sheet.classList.add('is-open'); if (scrim) { scrim.classList.add('is-open'); }
            });
        } else {
            sheet.classList.remove('is-open'); if (scrim) { scrim.classList.remove('is-open'); }
            clearTimeout(hideT);
            hideT = setTimeout(function () { sheet.hidden = true; if (scrim) { scrim.hidden = true; } }, 420);
        }
    }
    window.mbMapPicker({
        trigger: trigger,
        modal:   sheet,
        map:     document.getElementById('emMapCanvas'),
        input:   document.getElementById('coAddr'),
        lat:     document.getElementById('coLat'),
        lng:     document.getElementById('coLng'),
        preview: document.getElementById('emMapPreview'),
        confirm: document.getElementById('emMapConfirm'),
        close:   document.getElementById('emMapClose'),
        toggle:  toggle,
        i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
    });
})();
(function () {
    var btn = document.getElementById('emClearData');
    if (!btn) { return; }
    var wrap = btn.closest('.em-device-data');
    if (!window.mbClearDeviceData) { if (wrap) { wrap.style.display = 'none'; } return; }
    var slug = @json($store->slug ?? '');
    var id   = @json((string) ($store->id ?? ''));
    var scope = id || slug || 'x'; /* mirrors script.js: CFG.storeId || slug */
    btn.addEventListener('click', function () {
        window.mbClearDeviceData({
            keys: [
                'mb-cart-' + slug,            /* cart (shared cart key) */
                'em-favs-' + slug,            /* favourites */
                'em_recent_addrs_' + scope,   /* guest saved addresses */
                'em_recent_orders_' + scope   /* guest recent orders */
            ],
            slug: slug,
            storeId: id,
            confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
        });
    });
})();
</script>
@endpush

@push('scripts_at_bottom')
{{-- P6-301 — consume the previous-order payload the account page wrote to
     localStorage (cv_reorder_<slug>) and add each line to ember's cart. The
     shared helper (assets/store_templates/_shared/js/reorder-pickup.js) is
     loaded by store_templates.layout, but the call must run AFTER the theme's script.js (loaded by
     store_templates.layout) so window.__emberPickup + window.mbToast are
     defined. `load` fires after every sync script on the document. --}}
<script nonce="{{ csp_nonce() }}">
window.addEventListener('load', function () {
    if (!window.JuwriReorder || !window.JuwriReorder.pickup) return;
    window.JuwriReorder.pickup(@json($store->slug ?? ''), window.__emberPickup || function () { return false; }, {
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
