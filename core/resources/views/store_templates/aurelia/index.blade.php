{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.aurelia.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;
    use Illuminate\Support\Facades\Route;

    /* ---------------------------------------------------------------------
       aurelia — premium hotel super-app storefront · data preparation.

       VIEWS ONLY. Reuses the platform's existing hotel engine end to end:
         - the Hotel module's BookingService (date-range availability + the
           night-row race guard) and RoomType / RatePlan / HotelBooking are
           the real domain objects — this theme never re-implements booking
           math, it only renders around them.
         - In-room dining + spa/amenities are REAL: the exact same
           $total_menus / menu_categories binding + goods commerce pipeline
           store_templates/shelf already ships (storefront.goods.cart.add /
           storefront.checkout.place), split into two tabs by category name.
         - hotel.rooms.availability / hotel.rooms.book are NEW endpoints this
           theme calls only when Route::has() finds them. Until a backend
           agent ships them, Rooms/Booking degrade to a clearly labelled
           sample experience — never a broken page, never data presented as
           live when it is not.
       --------------------------------------------------------------------- */
    $vocab = app(VerticalVocab::class)->map($store->store_type ?? null);
    $items = collect($total_menus)->values();

    // Two-tier-free category rollup, same "root" mapping crave/shelf use so a
    // nested subcategory's items still group under their parent chip.
    $rootOf = [];
    foreach ($store->menu_categories as $parentCat) {
        $rootOf[$parentCat->id] = $parentCat->id;
        foreach ($parentCat->subcategories as $subCat) {
            $rootOf[$subCat->id] = $parentCat->id;
        }
    }

    // Dining vs. Spa & Amenities split: a category whose name reads as
    // spa/amenity/experience/wellness routes its items to the Spa screen;
    // everything else is in-room dining. A store with no such category has
    // an honestly-empty Spa screen — never a fabricated one.
    $amenityRootIds = [];
    foreach ($store->menu_categories as $parentCat) {
        if (preg_match('/spa|amenit|experience|wellness|leisure/i', (string) $parentCat->name)) {
            $amenityRootIds[$parentCat->id] = true;
        }
    }

    $rootItemCount = [];
    foreach ($items as $m) {
        $root = $rootOf[$m->category_id] ?? $m->category_id;
        $rootItemCount[$root] = ($rootItemCount[$root] ?? 0) + 1;
    }
    $allCategories = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);
    $diningCategories = $allCategories->reject(fn ($c) => isset($amenityRootIds[$c->id]))->values();
    $amenityCategories = $allCategories->filter(fn ($c) => isset($amenityRootIds[$c->id]))->values();

    // P1 currency — the store's own symbol/side/gap/decimals contract
    // (price_symbol_format() already honours this for every priceFormatted
    // below), reused client-side so room-rate math + cart totals agree with
    // every server-rendered price (same shape as shelf's own CUR block).
    $curSignRaw  = $postOptions->currency_sign ?? config('settings.currency_sign', '$');
    $curSign     = cur_isolate($curSignRaw);
    $curGap      = currency_symbol_gap($curSignRaw);
    $curPos      = (string) currency_pos_effective($store->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);

    // Language switcher — the store's enabled menu_languages, exposed to the
    // header control (self-contained, same shape lumiere ships).
    $auLanguages = collect($menu_languages ?? [])
        ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
        ->values();

    // Real tax contract (Order::computeTax) exposed to the dining/spa cart so
    // its Total matches the store's ACTUAL charge — same restaurant_taxx_charge/
    // restaurant_tax_type the track-page receipt uses. 0 for stores with no tax.
    $taxCharge = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $taxType   = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');

    $fulfillment = app(FulfillmentRegistry::class);
    $modes = \Juwri\Core\Support\ViewHelpers::fulfillmentModes($fulfillment, $store->store_type ?? null);
    // room_service — ordering to your room — is this theme's signature mode;
    // pre-select it whenever the store actually offers it.
    $defaultModeValue = optional($modes->firstWhere('value', 'room_service'))['value'] ?? optional($modes->first())['value'] ?? 'room_service';

    $jsItems = [];
    foreach ($items as $m) {
        $variants = [];
        foreach ($m->variants ?? [] as $v) {
            $label = trim((string) ($v->axes_label ?? ''));
            $variants[] = [
                'id' => $v->id,
                'label' => $label !== '' ? $label : ___('Option'),
                'price' => (float) $v->price,
                'priceFormatted' => price_symbol_format($v->price),
            ];
        }
        // Gallery: primary image first, then every extra gallery image, all
        // resolved to public URLs (deduped). Guarantees the ≥2-image galleries
        // the showcase seeds render as a swipeable strip in the item sheet.
        $gallery = [];
        $primaryUrl = ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '';
        if ($primaryUrl !== '') {
            $gallery[] = $primaryUrl;
        }
        foreach ((array) $m->gallery_images as $gimg) {
            if ($gimg && $gimg !== 'default.png') {
                $gurl = media_url('storage/menu/', $gimg, $store->id ?? null);
                if ($gurl !== '' && ! in_array($gurl, $gallery, true)) {
                    $gallery[] = $gurl;
                }
            }
        }
        // Add-ons (MenuExtra) offered on this item — id/title/price snapshot for
        // the sheet's selectable add-on list. Empty for non-food items.
        $extras = [];
        foreach ($m->resolved_extras as $ex) {
            if (! $ex->active) {
                continue;
            }
            $extras[] = [
                'id' => (int) $ex->id,
                'title' => (string) $ex->title,
                'price' => (float) $ex->price,
                'priceFormatted' => price_symbol_format($ex->price),
            ];
        }
        $root = $rootOf[$m->category_id] ?? $m->category_id;
        $jsItems[$m->id] = [
            'id' => $m->id,
            'cat' => $m->category_id,
            'root' => $root,
            'isAmenity' => isset($amenityRootIds[$root]),
            'name' => $m->name,
            'desc' => strip_tags((string) $m->description),
            'img' => $primaryUrl,
            'gallery' => $gallery,
            'extras' => $extras,
            'price' => (float) $m->price,
            'priceFormatted' => price_symbol_format($m->price),
            'soldOut' => (bool) $m->is_sold_out,
            'variants' => $variants,
            // rev/multi-vertical Lane V — the native goods cart endpoint
            // (Lane B), the SAME pipeline store_templates/shelf uses, reused
            // here for both in-room dining and spa/amenities.
            'addRoute' => route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $m->id]),
        ];
    }
    $diningItems = collect($jsItems)->reject(fn ($i) => $i['isAmenity'])->values();
    $amenityItems = collect($jsItems)->filter(fn ($i) => $i['isAmenity'])->values();
    $firstDiningImg = optional($diningItems->first(fn ($i) => $i['img'] !== ''))['img'] ?? null;
    $firstAmenityImg = optional($amenityItems->first(fn ($i) => $i['img'] !== ''))['img'] ?? null;

    $checkoutRoute = route('storefront.checkout.place', ['slug' => $store->slug]);
    // A route "template" for a dynamic cart-line id (the real id only exists
    // after the server creates it) — same convention as shelf.
    $cartLineRouteTemplate = route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => 999999999]);

    /* ------------------------------------------------------------------
       Hotel room-stay booking — reuse-first, sample-second (see file
       docblock). Both endpoints are new surface area a backend agent owns;
       Route::has() means this theme renders richly today and picks the real
       thing up automatically the moment it ships, with zero changes here.
       ------------------------------------------------------------------ */
    $auAvailRoute = Route::has('hotel.rooms.availability') ? route('hotel.rooms.availability', ['slug' => $store->slug]) : null;
    $auBookRoute  = Route::has('hotel.rooms.book') ? route('hotel.rooms.book', ['slug' => $store->slug]) : null;

    /* My Stay / folio — REAL when this visit resolves to an occupied room
       (the same room-QR + active-stay resolution the shared hotel-guest
       partial and HotelGuestController::folio already use), a presentational
       sample otherwise. Every read is guarded — a folio lookup must never
       break the storefront. */
    $auRoom = (isset($table) && $table && ($table->area_type ?? null) === 'room') ? $table : null;
    $auStay = null;
    $auFolio = null;
    $auFolioLink = null;
    /* Security (J52b review, SF2): the current occupant's statement, name, bill lines, balance and folio link are
       shown ONLY when the visitor's room token verified for this room AND the visitor holds the CURRENT stay's
       token (both computed once by the page controller: $roomVerified, $guestStayVerified). The printed room key
       or room token alone (permanent, known to every past guest) shows the sample panel. */
    if ($auRoom && ($roomVerified ?? false) === true && ($guestStayVerified ?? false) === true) {
        try {
            $auStay = \app(\Juwri\Core\Contracts\Hotel\RoomCharge::class)->activeStayForRoom((int) $store->id, (int) $auRoom->id);
            if ($auStay) {
                $auFolio = \app(\Juwri\Core\Contracts\Hotel\RoomCharge::class)->statement($auStay);
                if (Route::has('hotel.guest.folio')) {
                    $auFolioLink = route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $auStay->token]);
                }
            }
        } catch (\Throwable $e) {
            $auStay = null;
            $auFolio = null;
        }
    }
    $auRoomLabel = $auRoom ? ($auRoom->label ?: (string) $auRoom->table_no) : null;

    // Sample room set — used only while hotel.rooms.availability has not
    // shipped yet, or a live call errors. Real, freely-licensed photography;
    // every price/availability number here is illustrative and the UI marks
    // it "Sample availability" wherever it appears — never presented as live.
    $auSampleRooms = [
        ['id' => 'sample-standard', 'name' => ___('Standard Room'), 'bed' => ___('1 King or 2 Twin Beds'), 'occupancy' => 2, 'rate' => 129, 'image' => 'https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=1200&q=80'],
        ['id' => 'sample-deluxe', 'name' => ___('Deluxe Room'), 'bed' => ___('1 King Bed'), 'occupancy' => 2, 'rate' => 189, 'image' => 'https://images.unsplash.com/photo-1591088398332-8a7791972843?auto=format&fit=crop&w=1200&q=80'],
        ['id' => 'sample-suite', 'name' => ___('Executive Suite'), 'bed' => ___('1 King Bed + Living Area'), 'occupancy' => 3, 'rate' => 329, 'image' => 'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=1200&q=80'],
        ['id' => 'sample-family', 'name' => ___('Family Room'), 'bed' => ___('2 Queen Beds'), 'occupancy' => 4, 'rate' => 249, 'image' => 'https://images.unsplash.com/photo-1595576508898-0ad5c879a061?auto=format&fit=crop&w=1200&q=80'],
    ];

    $auHasCover = ! empty($store->cover_image) && $store->cover_image !== 'default.png';
    $auHeroImg = $auHasCover
        ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null)
        : 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1600&q=80';
    $auDiningTeaserImg = $firstDiningImg ?: 'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?auto=format&fit=crop&w=800&q=80';
    $auSpaTeaserImg = $firstAmenityImg ?: 'https://images.unsplash.com/photo-1600334129128-685c5582fd35?auto=format&fit=crop&w=800&q=80';
    $auExperienceImg = 'https://images.unsplash.com/photo-1571003123894-1f0594d2b5d9?auto=format&fit=crop&w=800&q=80';
    $auLobbyImg = 'https://images.unsplash.com/photo-1445019980597-93fa8acb246c?auto=format&fit=crop&w=800&q=80';

    /* ------------------------------------------------------------------
       Feature-wiring pack (scratchpad FEATURE-WIRING.md): stories rail,
       item reviews and loyalty all reuse existing public/auth:customer
       JSON endpoints — nothing here is a new backend surface, same
       convention every other themed pack in this loop uses. ------------ */
    $auStoriesRoute = url('r/'.$store->id.'/stories');
    // Item-reviews base: url('r/'.$store->id.'/item')
    $auMbrBase = url('r/'.$store->id.'/item');
    $auLoyaltyBalanceRoute = url('restaurant/'.$store->id.'/loyalty/balance');
    // Public, no-auth teaser (program/earn/reward strings) — store_options()
    // decodes JSON into a stdClass, normalised to a plain array here; null
    // when the store has no loyalty program configured yet.
    $auLoy = store_options($store->id, 'loyalty_teaser');
    $auLoyTeaser = $auLoy ? (array) $auLoy : null;

    // Crave-parity features (same backend data; no controller change).
    $auIsOpen = store_open_now($store);
    $auHoursToday = storefront_hours_today($store);
    $auBranches = collect($branches ?? []);
    $auShowBranches = $auBranches->count() > 1 && (($postOptions->allow_multi_branch_switch ?? 1) != '0');
    $auActiveBranch = $activeBranch ?? null;
    $auBanners = \Juwri\Core\Support\ViewHelpers::activeBanners($menuBanners ?? []);
    // P3-13 axe: ink on the owner's accent is picked from the accent (white on a deep green, dark on the default gold)
    // instead of a fixed dark brown, which measured 1.7:1 on a deep brand colour.
    $auOnAccent = \Juwri\Core\Support\OwnerUi::onAccent($store->color ?: '#b08d57');
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --au-bg:#faf7f2; --au-surface:#ffffff; --au-surface-2:#f4eee2; --au-ink:#221c14; --au-muted:#6e6453;
        --au-line:#e8ddc9; --au-accent:{{ $store->color ?: '#b08d57' }}; --au-accent-ink:{{ $auOnAccent }};
        /* the accent used as TEXT: pulled toward the ink so any brand colour reads on the page (P3-13 axe) */
        --au-accent-text:color-mix(in oklab, var(--au-accent) 70%, #221c14);
        --au-accent-soft:color-mix(in srgb, var(--au-accent) 14%, transparent);
        --au-danger:#a3342c; --au-ok:#2f6d4f; --au-radius:18px; --au-radius-sm:12px;
        --au-shadow:0 14px 34px rgba(30,22,8,.10);
        --au-serif:'Cormorant Garamond', Georgia, 'Times New Roman', serif;
        --au-sans:'Saudi Riyal', 'Jost', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --au-bg:#100e09; --au-surface:#1a160e; --au-surface-2:#221c11; --au-ink:#f4ecdc;
            --au-muted:#b6a98e; --au-line:#332a19; --au-shadow:0 14px 34px rgba(0,0,0,.45);
            --au-accent-text:color-mix(in oklab, var(--au-accent) 50%, #f4ecdc); --au-ok:#5fb98c;
            color-scheme:dark;
        }
    }
    :root[data-theme="dark"]{
        --au-bg:#100e09; --au-surface:#1a160e; --au-surface-2:#221c11; --au-ink:#f4ecdc;
        --au-muted:#b6a98e; --au-line:#332a19; --au-shadow:0 14px 34px rgba(0,0,0,.45);
            --au-accent-text:color-mix(in oklab, var(--au-accent) 50%, #f4ecdc); --au-ok:#5fb98c;
        color-scheme:dark;
    }
    :root[data-theme="light"]{ color-scheme:light; }
    *{box-sizing:border-box}
    .au-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:40}
    .au-skip:focus{inset-inline-start:0}
    .au-body{margin:0;background:var(--au-bg);color:var(--au-ink);font-family:var(--au-sans);
        -webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
    .aurelia{--au-pad:16px;max-width:1180px;margin:0 auto;padding-block-end:84px;position:relative;overflow-x:clip}
    .au-serif{font-family:var(--au-serif)}
    [hidden]{display:none !important}
    .au-btn{border:0;border-radius:999px;padding:12px 22px;font:600 .92rem var(--au-sans);cursor:pointer;
        display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;white-space:nowrap}
    .au-btn--primary{background:var(--au-accent);color:var(--au-accent-ink)}
    .au-btn--primary:disabled{opacity:.45;cursor:not-allowed}
    .au-btn--ghost{background:transparent;border:1px solid var(--au-line);color:inherit}
    .au-btn--block{width:100%}
    .au-btn--sm{padding:8px 16px;font-size:.8rem}
    .au-link{color:var(--au-accent-text);font-weight:600;text-decoration:none;cursor:pointer;background:none;border:0;font:inherit;padding:0}

    /* ---------------- topbar + nav ---------------- */
    .au-topbar{position:sticky;top:0;z-index:12;display:flex;align-items:center;gap:10px;padding:10px 16px;
        background:color-mix(in srgb, var(--au-surface) 92%, transparent);backdrop-filter:blur(10px);
        border-block-end:1px solid var(--au-line)}
    .au-brand{display:flex;align-items:center;gap:10px;min-width:0;flex:1 1 auto}
    .au-logo{width:34px;height:34px;border-radius:9px;object-fit:cover;flex:0 0 auto;background:var(--au-surface-2)}
    .au-brandname{font-family:var(--au-serif);font-weight:600;font-size:1.15rem;overflow:hidden;
        text-overflow:ellipsis;white-space:nowrap;letter-spacing:.01em}
    .au-iconbtn{border:0;background:transparent;color:inherit;cursor:pointer;padding:8px;border-radius:10px;
        display:inline-flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto}
    .au-iconbtn:hover{background:var(--au-surface-2)}
    .au-iconbtn svg{width:20px;height:20px}
    .au-badge{position:absolute;top:1px;inset-inline-end:1px;background:var(--au-accent);color:var(--au-accent-ink);
        font-size:.62rem;font-weight:700;min-width:16px;height:16px;border-radius:999px;display:flex;
        align-items:center;justify-content:center;padding:0 3px}
    .au-langsel{display:inline-flex;align-items:center;gap:6px;flex:0 0 auto;border:1px solid var(--au-line);
        border-radius:10px;padding:6px 9px;color:var(--au-muted);background:var(--au-surface)}
    .au-langsel:hover{background:var(--au-surface-2)}
    .au-langsel svg{width:16px;height:16px}
    .au-langsel select{border:0;background:transparent;color:var(--au-ink);font:600 .8rem var(--au-sans);
        cursor:pointer;padding-inline-end:2px}
    .au-langsel select:focus{outline:none}

    .au-nav{position:fixed;inset-inline:0;bottom:0;z-index:12;display:flex;justify-content:space-around;
        background:color-mix(in srgb, var(--au-surface) 96%, transparent);backdrop-filter:blur(10px);
        border-block-start:1px solid var(--au-line);padding:6px 4px calc(6px + env(safe-area-inset-bottom))}
    .au-nav.is-drilldown{display:none}
    .au-navbtn{position:relative;border:0;background:none;color:var(--au-muted);display:flex;flex-direction:column;align-items:center;
        gap:3px;font:600 .66rem var(--au-sans);padding:6px 8px;cursor:pointer;flex:1;border-radius:12px}
    .au-navbtn svg{width:21px;height:21px}
    .au-navbtn[aria-current="true"]{color:var(--au-accent-text)}

    /* ---------------- generic screen chrome ---------------- */
    .au-screen{padding:0 var(--au-pad) 28px}
    .au-back-hdr{display:flex;align-items:center;gap:10px;padding:14px 0 6px;position:sticky;top:53px;
        background:var(--au-bg);z-index:5}
    .au-back-hdr h1{font-family:var(--au-serif);font-size:1.3rem;font-weight:600;margin:0;flex:1;min-width:0;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .au-section-hdr{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin:26px 0 12px}
    .au-section-hdr h2{font-family:var(--au-serif);font-size:1.5rem;font-weight:600;margin:0}
    .au-section-hdr p{margin:2px 0 0;color:var(--au-muted);font-size:.86rem}
    .au-kicker{font-size:.72rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--au-accent-text)}
    .au-sample-note{display:flex;align-items:center;gap:8px;background:var(--au-surface-2);border:1px dashed var(--au-line);
        border-radius:var(--au-radius-sm);padding:9px 13px;font-size:.78rem;color:var(--au-muted);margin:10px 0}
    .au-sample-note svg{width:16px;height:16px;flex:0 0 auto;color:var(--au-accent)}

    /* ---------------- home / hero ---------------- */
    .au-hero{position:relative;margin:0 calc(-1 * var(--au-pad));border-radius:0 0 26px 26px;overflow:clip;min-height:64vh;
        display:flex;align-items:flex-end}
    .au-hero img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    .au-hero::after{content:'';position:absolute;inset:0;
        background:linear-gradient(180deg, rgba(10,8,4,.15) 0%, rgba(10,8,4,.35) 55%, rgba(10,8,4,.86) 100%)}
    .au-hero-inner{position:relative;z-index:1;padding:26px 22px 100px;color:#fdf9f0;width:100%}
    .au-hero .au-kicker{color:#e8cd9a}
    .au-hero h1{font-family:var(--au-serif);font-size:clamp(2.1rem,7vw,3.2rem);line-height:1.05;margin:8px 0 8px;font-weight:600}
    .au-hero p{margin:0;font-size:1rem;color:#f1e9d8;max-width:32rem}
    /* crave-parity: status row + branch + banners (home section has 0 side padding for the full-bleed hero, so these carry their own gutter) */
    .au-statusrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding-inline:16px;margin:16px 0 4px}
    .au-openchip{display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;color:var(--au-ok);
        background:var(--au-surface);border:1px solid var(--au-line);border-radius:100px;padding:6px 12px}
    .au-openchip i{width:7px;height:7px;border-radius:50%;background:var(--au-ok);flex:none}
    .au-openchip.is-closed{color:#c0563f} .au-openchip.is-closed i{background:#c0563f}
    .au-branchsel{position:relative;display:inline-flex;align-items:center;height:32px;border-radius:100px;
        border:1px solid var(--au-line);background:var(--au-surface);color:var(--au-ink);cursor:pointer}
    .au-branchsel svg{position:absolute;inset-inline-start:10px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--au-accent);pointer-events:none}
    .au-branchsel::after{content:"";position:absolute;inset-inline-end:11px;top:50%;width:6px;height:6px;border-inline-end:1.5px solid var(--au-muted);border-bottom:1.5px solid var(--au-muted);transform:translateY(-65%) rotate(45deg);pointer-events:none}
    .au-branchsel select{-webkit-appearance:none;appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-size:.8rem;font-weight:600;cursor:pointer;outline:none;height:100%;padding-inline-start:30px;padding-inline-end:24px;max-width:180px}
    .au-banners{padding-inline:var(--au-pad);margin:10px calc(-1 * var(--au-pad)) 4px}
    .au-banner-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
    .au-banner-track::-webkit-scrollbar{height:0}
    .au-banner{flex:0 0 86%;scroll-snap-align:center;position:relative;min-height:104px;aspect-ratio:16/9;border-radius:var(--au-radius);overflow:hidden;display:flex;align-items:flex-end;padding:18px 20px;text-decoration:none;background:var(--au-accent);color:var(--au-accent-ink)}
    .au-banner:nth-child(even){background:var(--au-ink);color:var(--au-surface)}
    .au-banner-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.9;z-index:0}
    /* Legibility scrim: a banner with a photo darkens toward the text corner so
       the copy reads on any image; the text goes white for that case. */
    .au-banner:has(.au-banner-img)::after{content:"";position:absolute;inset:0;z-index:1;
        background:linear-gradient(to top,rgba(15,17,26,.6),rgba(15,17,26,.15) 55%,transparent)}
    .au-banner:has(.au-banner-img) .au-banner-copy{color:#fff}
    .au-banner-copy{position:relative;display:flex;flex-direction:column;gap:3px;z-index:2}
    .au-banner-kick{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;opacity:.85}
    .au-banner-title{font-size:1.2rem;font-weight:600;line-height:1.15}
    .au-banner-dots{display:flex;gap:6px;justify-content:center;margin-top:9px}
    .au-banner-dots i{width:6px;height:6px;border-radius:50%;background:var(--au-line);transition:width .2s,background .2s}
    .au-banner-dots i.on{background:var(--au-accent);width:16px;border-radius:100px}

    .au-search-card{position:relative;margin:-78px 2px 0;background:var(--au-surface);border-radius:var(--au-radius);
        box-shadow:var(--au-shadow);padding:16px;display:grid;gap:10px;z-index:2}
    .au-search-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .au-field{display:flex;flex-direction:column;gap:4px}
    .au-field label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--au-muted)}
    .au-field input[type="date"],.au-field input[type="text"],.au-field input[type="tel"],.au-field input[type="email"],
    .au-field textarea,.au-field select{width:100%;padding:11px 12px;border-radius:10px;border:1px solid var(--au-line);
        background:var(--au-surface);color:inherit;font:500 .92rem var(--au-sans);font-variant-numeric:tabular-nums}
    .au-field textarea{resize:vertical;min-height:70px;font-family:var(--au-sans)}
    .au-field input:focus,.au-field textarea:focus,.au-field select:focus{outline:2px solid var(--au-accent);outline-offset:1px}
    .au-addr{position:relative}
    .au-addr input{padding-inline-end:42px}
    .au-addr-loc{position:absolute;inset-inline-end:6px;top:6px;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;
        border:0;background:transparent;color:var(--au-muted);cursor:pointer;border-radius:9px}
    .au-addr-loc:hover{background:var(--au-surface-2);color:var(--au-accent)}
    .au-addr-loc.is-loading{opacity:.5;pointer-events:none}
    .au-addr-loc svg{width:19px;height:19px}
    .au-addr-suggest{position:absolute;inset-inline:0;top:calc(100% + 5px);z-index:40;background:var(--au-surface);
        border:1px solid var(--au-line);border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,.14);overflow:hidden;max-height:248px;overflow-y:auto}
    .au-addr-suggest .mb-addr-opt{display:block;width:100%;text-align:start;padding:11px 14px;background:none;border:0;
        border-block-end:1px solid var(--au-line);color:var(--au-ink);font:inherit;font-size:.84rem;cursor:pointer;line-height:1.4}
    .au-addr-suggest .mb-addr-opt:last-child{border-block-end:0}
    .au-addr-suggest .mb-addr-opt:hover{background:var(--au-surface-2)}
    .au-stepper{display:flex;align-items:center;justify-content:space-between;border:1px solid var(--au-line);
        border-radius:10px;padding:6px 10px}
    .au-stepper button{width:28px;height:28px;border-radius:8px;border:1px solid var(--au-line);background:var(--au-surface);
        color:inherit;font-size:1rem;cursor:pointer;line-height:1}
    .au-stepper button:disabled{opacity:.35;cursor:not-allowed}
    .au-stepper span{font-weight:600;font-variant-numeric:tabular-nums}
    .au-search-card .au-btn{margin-top:2px}

    .au-rail{display:flex;gap:14px;overflow-x:auto;padding:4px 2px 10px;scroll-snap-type:x proximity}
    .au-rail::-webkit-scrollbar{height:0}
    .au-rail-card{flex:0 0 auto;width:230px;scroll-snap-align:start;background:var(--au-surface);
        border:1px solid var(--au-line);border-radius:var(--au-radius);overflow:clip;cursor:pointer;text-align:start}
    .au-rail-card img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;background:var(--au-surface-2)}
    .au-rail-card__body{padding:12px 13px 14px}
    .au-rail-card__name{font-weight:600;font-size:.94rem;margin:0 0 3px}
    .au-rail-card__meta{color:var(--au-muted);font-size:.76rem;margin:0 0 8px}
    .au-rail-card__price{font-weight:700;font-size:.92rem}
    .au-rail-card__price small{font-weight:500;color:var(--au-muted);font-size:.72rem}

    .au-teaser-row{display:grid;grid-template-columns:1fr;gap:14px}
    .au-teaser{position:relative;border-radius:var(--au-radius);overflow:clip;min-height:150px;display:flex;
        align-items:flex-end;cursor:pointer;border:0;padding:0;text-align:start;width:100%}
    .au-teaser img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    .au-teaser::after{content:'';position:absolute;inset:0;background:linear-gradient(0deg, rgba(10,8,4,.78), rgba(10,8,4,.05))}
    .au-teaser-body{position:relative;z-index:1;padding:16px;color:#fdf9f0}
    .au-teaser-body h3{font-family:var(--au-serif);font-size:1.3rem;margin:0 0 3px;font-weight:600}
    .au-teaser-body p{margin:0;font-size:.82rem;color:#efe6d3}
    .au-teaser-body .au-link{color:#fff;margin-top:8px;display:inline-block}

    .au-trust-strip{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:22px 0 6px}
    .au-trust{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid var(--au-line);
        border-radius:var(--au-radius-sm);background:var(--au-surface)}
    .au-trust svg{width:22px;height:22px;color:var(--au-accent);flex:0 0 auto}
    .au-trust span{font-size:.78rem;font-weight:600;line-height:1.25}

    /* ---------------- rooms ---------------- */
    .au-rooms-summary{display:flex;align-items:center;gap:10px;justify-content:space-between;background:var(--au-surface-2);
        border-radius:var(--au-radius-sm);padding:11px 14px;margin:6px 0 4px;font-size:.84rem}
    .au-rooms-summary b{font-weight:700}
    .au-rooms-grid{display:grid;grid-template-columns:1fr;gap:16px;margin-top:14px}
    .au-room-card{background:var(--au-surface);border:1px solid var(--au-line);border-radius:var(--au-radius);
        overflow:clip;display:flex;flex-direction:column}
    .au-room-card__imgwrap{position:relative;aspect-ratio:16/10;background:var(--au-surface-2)}
    .au-room-card__imgwrap img,.au-room-card__img{width:100%;height:100%;object-fit:cover;display:block}
    .au-room-card__ph{display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:var(--au-muted)}
    .au-avail{position:absolute;top:10px;inset-inline-start:10px;padding:4px 11px;border-radius:999px;font-size:.68rem;
        font-weight:700;letter-spacing:.02em;background:color-mix(in srgb, #2f6d4f 88%, transparent);color:#fff}
    .au-avail.is-low{background:color-mix(in srgb, #b45309 90%, transparent)}
    .au-avail.is-sold{background:color-mix(in srgb, var(--au-danger) 90%, transparent)}
    .au-room-card__body{padding:15px 16px 16px;display:flex;flex-direction:column;gap:4px}
    .au-room-card__name{font-family:var(--au-serif);font-size:1.28rem;font-weight:600;margin:0}
    .au-room-card__meta{color:var(--au-muted);font-size:.8rem}
    .au-room-card__foot{margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:10px}
    .au-room-card__price{font-weight:700}
    .au-room-card__price small{font-weight:500;color:var(--au-muted);font-size:.72rem;display:block}

    /* ---------------- room detail ---------------- */
    .au-gallery{display:grid;grid-template-columns:1fr;gap:6px;margin:8px 0 14px;border-radius:var(--au-radius);overflow:clip}
    .au-gallery img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;background:var(--au-surface-2)}
    .au-gallery-strip{display:grid;grid-template-columns:1fr 1fr;gap:6px}
    .au-gallery-strip img{aspect-ratio:4/3}
    .au-room-detail-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:6px}
    .au-room-detail-head h2{font-family:var(--au-serif);font-size:1.6rem;font-weight:600;margin:0}
    .au-room-detail-head .au-muted-line{color:var(--au-muted);font-size:.86rem}
    .au-desc{color:var(--au-ink);line-height:1.6;font-size:.92rem;margin:10px 0 18px}
    .au-amenity-list{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 20px}
    .au-amenity{display:flex;align-items:center;gap:8px;font-size:.82rem}
    .au-amenity svg{width:18px;height:18px;color:var(--au-accent);flex:0 0 auto}
    .au-plan{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid var(--au-line);
        border-radius:var(--au-radius-sm);padding:13px 14px;margin-bottom:10px;cursor:pointer}
    .au-plan.is-active{border-color:var(--au-accent);background:var(--au-accent-soft)}
    .au-plan input{margin:0}
    .au-plan-copy{flex:1}
    .au-plan-copy b{display:block;font-size:.9rem}
    .au-plan-copy span{display:block;color:var(--au-muted);font-size:.76rem;margin-top:2px}
    .au-plan-price{font-weight:700;font-size:.94rem;white-space:nowrap}
    .au-recap{background:var(--au-surface-2);border-radius:var(--au-radius-sm);padding:14px;margin:16px 0;font-size:.86rem}
    .au-recap-row{display:flex;justify-content:space-between;padding:4px 0}
    .au-recap-row.is-total{font-weight:700;border-top:1px dashed var(--au-line);margin-top:6px;padding-top:10px}
    .au-recap-row .au-muted-line{color:var(--au-muted)}
    .au-sticky-foot{position:sticky;bottom:0;background:var(--au-bg);padding:12px 0 16px;display:flex;align-items:center;
        justify-content:space-between;gap:14px;border-top:1px solid var(--au-line);margin-top:6px}
    .au-sticky-foot .au-price{font-family:var(--au-serif);font-size:1.3rem;font-weight:600}
    .au-sticky-foot .au-price small{display:block;font-family:var(--au-sans);font-size:.68rem;color:var(--au-muted);font-weight:600}

    /* ---------------- booking ---------------- */
    .au-steps{display:flex;align-items:center;gap:6px;margin:8px 0 18px}
    .au-step{flex:1;height:4px;border-radius:99px;background:var(--au-line)}
    .au-step.is-active,.au-step.is-done{background:var(--au-accent)}
    .au-step-labels{display:flex;justify-content:space-between;font-size:.7rem;color:var(--au-muted);margin:-12px 0 18px;font-weight:600}
    .au-msg{font-size:.84rem;padding:10px 12px;border-radius:10px;margin:10px 0}
    .au-msg--error{background:color-mix(in srgb, var(--au-danger) 12%, transparent);color:var(--au-danger)}
    .au-msg--ok{background:color-mix(in srgb, var(--au-ok) 14%, transparent);color:var(--au-ok)}
    .au-confirm-card{text-align:center;padding:34px 18px;border-radius:var(--au-radius);background:var(--au-surface);
        border:1px solid var(--au-line)}
    .au-confirm-card.is-demo{border-style:dashed;border-color:#b45309;background:color-mix(in srgb, #b45309 6%, var(--au-surface))}
    .au-confirm-icon{width:56px;height:56px;border-radius:50%;background:var(--au-accent-soft);color:var(--au-accent);
        display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
    .au-confirm-icon svg{width:28px;height:28px}
    .au-confirm-card h2{font-family:var(--au-serif);font-size:1.5rem;margin:0 0 6px}
    .au-confirm-code{font-family:var(--au-serif);font-size:1.7rem;letter-spacing:.08em;font-weight:600;margin:14px 0}
    .au-confirm-note{font-size:.78rem;color:var(--au-muted);max-width:26rem;margin:10px auto 0}
    .au-confirm-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:20px}

    /* ---------------- dining / spa (shared "shop" classes) ---------------- */
    .au-shop-toolbar{display:flex;flex-wrap:wrap;gap:8px;padding:4px 0 8px}
    .au-search{flex:1 1 200px;padding:10px 14px;border-radius:999px;border:1px solid var(--au-line);
        background:var(--au-surface);color:inherit;font-size:.9rem}
    .au-search:focus{outline:2px solid var(--au-accent);outline-offset:1px}
    .au-cats{display:flex;gap:8px;overflow-x:auto;overflow-y:clip;padding:8px 0;scrollbar-width:thin}
    .au-chip{flex:0 0 auto;border:1px solid var(--au-line);background:var(--au-surface);color:inherit;
        padding:7px 15px;border-radius:999px;font-size:.82rem;cursor:pointer;white-space:nowrap}
    .au-chip.is-active{background:var(--au-accent);border-color:var(--au-accent);color:var(--au-accent-ink);font-weight:600}
    .au-shop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(158px,1fr));gap:14px;padding-top:6px}
    .au-shop-card{background:var(--au-surface);border:1px solid var(--au-line);border-radius:var(--au-radius-sm);
        overflow:clip;display:flex;flex-direction:column;text-align:start;cursor:pointer;padding:0}
    .au-shop-card__imgwrap{aspect-ratio:1/1;background:var(--au-surface-2);position:relative}
    .au-shop-card__img{width:100%;height:100%;object-fit:cover;display:block}
    .au-shop-card__ph{display:flex;align-items:center;justify-content:center;height:100%;color:var(--au-muted)}
    .au-shop-card__soldout{position:absolute;top:8px;inset-inline-start:8px;background:var(--au-danger);color:#fff;
        font-size:.64rem;font-weight:700;padding:3px 8px;border-radius:6px}
    .au-shop-card__body{padding:10px 12px 12px;display:flex;flex-direction:column;gap:4px;flex:1}
    .au-shop-card__name{font-weight:600;font-size:.9rem;line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;
        -webkit-box-orient:vertical;overflow:clip}
    .au-shop-card__foot{margin-top:auto;padding-top:6px;display:flex;align-items:center;justify-content:space-between;gap:6px}
    .au-shop-card__price{font-weight:700;font-size:.9rem}
    .au-add{border:0;background:var(--au-accent);color:var(--au-accent-ink);border-radius:9px;padding:6px 12px;
        font-size:.78rem;font-weight:700;cursor:pointer}
    .au-add:disabled{opacity:.4;cursor:not-allowed}
    .au-empty{padding:50px 10px;text-align:center;color:var(--au-muted);font-size:.9rem}
    .au-empty svg{width:34px;height:34px;color:var(--au-line);margin-bottom:10px}

    /* ---------------- sheets (search / item / cart) ---------------- */
    .au-sheet-scrim{position:fixed;inset:0;background:rgba(12,9,4,.5);z-index:19;opacity:0;pointer-events:none;
        transition:opacity .18s ease}
    .au-sheet-scrim.is-open{opacity:1;pointer-events:auto}
    .au-sheet{position:fixed;inset-block:0;inset-inline-end:0;width:min(440px,100%);background:var(--au-surface);
        color:inherit;z-index:20;transform:translateX(100%);transition:transform .22s ease;display:flex;flex-direction:column;
        box-shadow:-10px 0 34px rgba(0,0,0,.28)}
    [dir="rtl"] .au-sheet{transform:translateX(-100%)}
    .au-sheet.is-open{transform:translateX(0)}
    .au-sheet__head{display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid var(--au-line)}
    .au-sheet__title{font-family:var(--au-serif);font-weight:600;font-size:1.15rem}
    .au-sheet__body{flex:1;overflow-y:auto;overflow-x:clip;padding:16px}
    .au-sheet__foot{padding:16px;border-top:1px solid var(--au-line)}
    .au-detail-img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:12px;background:var(--au-surface-2);margin-bottom:10px}
    .au-thumbs{display:flex;gap:8px;margin-bottom:12px;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:2px}
    .au-thumbs::-webkit-scrollbar{height:0}
    .au-thumb{flex:0 0 auto;width:60px;height:60px;border-radius:9px;overflow:hidden;border:2px solid transparent;padding:0;background:var(--au-surface-2);cursor:pointer}
    .au-thumb img{width:100%;height:100%;object-fit:cover;display:block}
    .au-thumb[aria-current="true"]{border-color:var(--au-accent)}
    .au-addons #auItemAddons{display:flex;flex-direction:column;gap:8px}
    .au-addon{display:flex;align-items:center;gap:10px;padding:11px 13px;border:1px solid var(--au-line);border-radius:12px;cursor:pointer}
    .au-addon:has(input:checked){border-color:var(--au-accent);background:var(--au-accent-soft)}
    .au-addon input{width:19px;height:19px;accent-color:var(--au-accent);flex:0 0 auto;margin:0}
    .au-addon-name{flex:1;font-size:.9rem;font-weight:500}
    .au-addon-price{font-size:.86rem;font-weight:600;color:var(--au-accent-text)}
    .au-group{margin-bottom:16px}
    .au-group__label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--au-muted);margin-bottom:7px}
    .au-opts{display:flex;flex-wrap:wrap;gap:8px}
    .au-opt{border:1px solid var(--au-line);background:var(--au-surface);color:inherit;border-radius:9px;
        padding:7px 12px;font-size:.84rem;cursor:pointer}
    .au-opt.is-active{border-color:var(--au-accent);background:var(--au-accent-soft);font-weight:600}
    .au-qty{display:flex;align-items:center;gap:12px}
    .au-qty button{width:32px;height:32px;border-radius:9px;border:1px solid var(--au-line);background:var(--au-surface);
        color:inherit;cursor:pointer;font-size:1rem}
    .au-cart-line{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--au-line)}
    .au-cart-line img{width:54px;height:54px;border-radius:9px;object-fit:cover;background:var(--au-surface-2);flex:0 0 auto}
    .au-cart-line__body{flex:1;min-width:0}
    .au-cart-line__remove{background:none;border:0;color:var(--au-danger);cursor:pointer;font-size:.78rem;padding:0}
    .au-totalrow{display:flex;justify-content:space-between;font-weight:700;padding:10px 0}
    .au-totalrow--stack{flex-direction:column;gap:6px;font-weight:400}
    .au-trow{display:flex;justify-content:space-between;width:100%}
    .au-trow--total{font-weight:700}
    .au-trow[hidden]{display:none}
    .au-nb-proxy{position:fixed;top:0;inset-inline-start:0;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;opacity:0;pointer-events:none;border:0}
    .au-notif-dot{position:absolute;top:6px;inset-inline-end:6px;width:8px;height:8px;border-radius:50%;background:var(--au-accent);border:2px solid var(--au-surface);box-sizing:content-box}
    .au-notif-dot[hidden]{display:none}
    .mb-nb-card{--nb-surface:var(--au-surface);--nb-surface-2:var(--au-surface-2);--nb-ink:var(--au-ink);--nb-muted:var(--au-muted);--nb-line:var(--au-line);--nb-accent:var(--au-accent);--nb-radius:14px}
    .aurelia .mb-nb-panel{opacity:0}
    .aurelia .mb-nb-panel.is-open{opacity:1}
    .au-order{border:1px solid var(--au-line);border-radius:12px;padding:13px 15px;margin-bottom:10px}
    .au-order-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .au-order-status{font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:100px;background:var(--au-surface-2);color:var(--au-ink)}
    .au-order-meta{font-size:.78rem;color:var(--au-muted);margin-top:6px}
    .au-order-track{display:inline-block;margin-top:10px;font-size:.82rem;font-weight:600;color:var(--au-accent-text);text-decoration:none}
    .au-order-actions{display:flex;gap:8px;margin-top:12px}
    .au-order-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;height:38px;border-radius:999px;font-size:.82rem;font-weight:600;text-decoration:none;color:var(--au-ink);background:var(--au-surface);border:1px solid var(--au-line);cursor:pointer;transition:background .15s,border-color .15s}
    .au-order-btn:hover{background:var(--au-surface-2)}
    .au-order-btn--p{background:var(--au-accent);color:var(--au-accent-ink);border-color:var(--au-accent)}
    .au-order-btn--p:hover{background:color-mix(in srgb, var(--au-accent) 88%, #000);border-color:color-mix(in srgb, var(--au-accent) 88%, #000)}
    .au-couponbox{margin:8px 0 4px}
    .au-couponrow{display:flex;gap:8px}
    .au-coupon-input{flex:1;height:46px;border-radius:10px;border:1px solid var(--au-line);background:var(--au-surface);padding:0 14px;font:inherit;font-size:.9rem;color:var(--au-ink)}
    .au-coupon-apply{flex:none}
    .au-coupon-msg{font-size:.78rem;font-weight:600;margin:7px 0 0;color:var(--au-ok)}
    .au-coupon-msg.is-bad{color:#c0563f}
    .au-charge{display:flex;align-items:center;gap:10px;background:var(--au-accent-soft);border:1px solid var(--au-line);
        border-radius:var(--au-radius-sm);padding:12px;margin:12px 0;cursor:pointer}
    .au-charge input{width:18px;height:18px;flex:0 0 auto}
    .au-charge span b{display:block;font-size:.86rem}
    .au-charge span small{display:block;color:var(--au-muted);font-size:.74rem;margin-top:1px}
    .au-modes{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}

    /* ---------------- my stay / folio ---------------- */
    .au-card{background:var(--au-surface);border:1px solid var(--au-line);border-radius:var(--au-radius);padding:16px;margin-bottom:16px}
    .au-card h3{font-family:var(--au-serif);font-size:1.15rem;margin:0 0 8px;font-weight:600}
    .au-card p.au-muted-line{color:var(--au-muted);font-size:.84rem;margin:0 0 10px}
    .au-folio-row{display:flex;justify-content:space-between;padding:6px 0;font-size:.86rem;border-bottom:1px dashed var(--au-line)}
    .au-folio-row.is-total{font-weight:700;border-bottom:0;border-top:1px solid var(--au-line);margin-top:4px;padding-top:10px}
    .au-tag{display:inline-block;font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
        padding:2px 8px;border-radius:999px;background:var(--au-surface-2);color:var(--au-muted);margin-inline-start:6px}
    .au-tag.is-live{background:color-mix(in srgb, var(--au-ok) 16%, transparent);color:var(--au-ok)}

    /* ---------------- toast ---------------- */
    .au-toast{position:fixed;bottom:78px;inset-inline:16px;margin:0 auto;max-width:360px;background:var(--au-ink);
        color:var(--au-bg);padding:11px 16px;border-radius:12px;font-size:.84rem;text-align:center;z-index:30;
        opacity:0;pointer-events:none;transform:translateY(8px);transition:opacity .2s ease, transform .2s ease}
    .au-toast.is-show{opacity:1;transform:translateY(0)}

    /* ---------------- stories rail + full-screen viewer ---------------- */
    .au-stories{display:flex;gap:16px;overflow-x:auto;overflow-y:clip;padding:14px 2px 4px;scrollbar-width:none}
    .au-stories::-webkit-scrollbar{height:0}
    /* Desktop mouse can't swipe a hidden rail — restore a slim bar on hover/fine so
       overflowing rails stay reachable; touch keeps the hidden-bar look. */
    @media (hover:hover) and (pointer:fine){
        #aurelia .au-stories,#aurelia .au-rail{scrollbar-width:thin;scrollbar-color:var(--au-line) transparent}
        #aurelia .au-stories::-webkit-scrollbar,#aurelia .au-rail::-webkit-scrollbar{display:block;height:8px}
        #aurelia .au-stories::-webkit-scrollbar-thumb,#aurelia .au-rail::-webkit-scrollbar-thumb{background:var(--au-line);border-radius:999px;border:2px solid transparent;background-clip:content-box}
        #aurelia .au-stories::-webkit-scrollbar-thumb:hover,#aurelia .au-rail::-webkit-scrollbar-thumb:hover{background:var(--au-accent)}
        #aurelia .au-stories::-webkit-scrollbar-track,#aurelia .au-rail::-webkit-scrollbar-track{background:transparent}
    }
    .au-story-item{display:flex;flex-direction:column;align-items:center;gap:6px;flex:0 0 auto;width:66px;
        border:0;background:none;cursor:pointer;color:inherit;font:inherit}
    .au-story-ring{width:62px;height:62px;border-radius:50%;padding:2.5px;flex:0 0 auto;
        background:linear-gradient(135deg, var(--au-accent), #e8cd9a);display:flex;transition:transform .12s ease}
    .au-story-item:active .au-story-ring{transform:scale(.94)}
    .au-story-ring.is-viewed{background:var(--au-line)}
    .au-story-thumb{width:100%;height:100%;border-radius:50%;overflow:clip;background:var(--au-surface-2);
        border:2px solid var(--au-bg);display:flex;align-items:center;justify-content:center;color:var(--au-muted)}
    .au-story-thumb img{width:100%;height:100%;object-fit:cover;display:block}
    .au-story-label{font-size:.68rem;font-weight:600;max-width:66px;overflow:hidden;text-overflow:ellipsis;
        white-space:nowrap;color:var(--au-muted)}
    .au-storyviewer{position:fixed;inset:0;background:#0b0905;z-index:40;display:flex;flex-direction:column}
    .au-sv-bars{display:flex;gap:4px;padding:12px 12px 0;flex:0 0 auto}
    .au-sv-seg{flex:1;height:3px;border-radius:2px;background:rgba(255,255,255,.3);overflow:clip}
    .au-sv-seg i{display:block;height:100%;width:0%;background:#fff}
    .au-sv-top{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;flex:0 0 auto}
    .au-sv-meta{display:flex;align-items:center;gap:8px;font-size:.86rem;font-weight:600;color:#fdf9f0}
    .au-sv-avatar{width:26px;height:26px;border-radius:50%;background:var(--au-accent);color:var(--au-accent-ink);
        font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
    .au-sv-close{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;border:0;
        display:flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto}
    .au-sv-close svg{width:17px;height:17px}
    .au-sv-stage{position:relative;flex:1;min-height:0;display:flex;align-items:center;justify-content:center;cursor:pointer}
    .au-sv-media{max-width:100%;max-height:100%;object-fit:contain}
    .au-sv-caption{position:absolute;inset-inline:0;bottom:0;padding:22px 16px;color:#fdf9f0;font-size:.9rem;
        line-height:1.5;background:linear-gradient(0deg, rgba(0,0,0,.65), transparent)}
    .au-sv-foot{padding:14px 16px calc(14px + env(safe-area-inset-bottom));flex:0 0 auto}
    .au-sv-cta{width:100%;text-decoration:none}

    /* ---------------- item reviews ---------------- */
    .au-rv{margin-top:6px;padding-top:16px;border-top:1px solid var(--au-line)}
    .au-rv-summary{display:flex;gap:22px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
    .au-rv-avgblock{display:flex;flex-direction:column;align-items:center;min-width:60px;flex:0 0 auto}
    .au-rv-avgnum{font-family:var(--au-serif);font-size:2rem;font-weight:600;line-height:1}
    .au-rv-avgstars{margin-top:4px;display:flex;gap:1px}
    .au-rv-avgcount{font-size:.72rem;color:var(--au-muted);margin-top:3px}
    .au-rv-dist{flex:1;min-width:160px;display:grid;gap:5px}
    .au-rv-bar{display:grid;grid-template-columns:12px 1fr 20px;align-items:center;gap:8px;font-size:.72rem;color:var(--au-muted)}
    .au-rv-bar i{display:block;height:6px;border-radius:99px;background:var(--au-surface-2);overflow:clip;font-style:normal}
    .au-rv-bar i s{display:block;height:100%;background:var(--au-accent);text-decoration:none}
    .au-rv-bar b{text-align:end;font-weight:700;color:var(--au-ink)}
    .au-star-ic{font-size:.82rem;color:var(--au-line);line-height:1}
    .au-star-ic.is-on{color:var(--au-accent)}
    .au-rv-empty{padding:18px 14px;text-align:center;color:var(--au-muted);background:var(--au-surface-2);
        border-radius:var(--au-radius-sm);font-size:.84rem;margin:0 0 12px}
    .au-rv-item{padding:12px 0;border-top:1px solid var(--au-line)}
    .au-rv-item:first-child{border-top:0;padding-top:0}
    .au-rv-item-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px}
    .au-rv-item-top .au-rv-stars{display:flex;gap:1px}
    .au-rv-name{font-weight:600;font-size:.84rem}
    .au-rv-date{font-size:.72rem;color:var(--au-muted);margin-inline-start:auto}
    .au-rv-body{font-size:.84rem;color:var(--au-muted);line-height:1.55;margin:0}
    .au-rv-writebtn{margin-top:4px}
    .au-rv-form{margin-top:14px;display:grid;gap:2px}
    .au-rv-starsinput{display:flex;gap:8px;margin-bottom:14px}
    .au-rv-starbtn{font-size:1.5rem;line-height:1;color:var(--au-line);padding:0;border:0;background:none;
        cursor:pointer;transition:transform .1s ease}
    .au-rv-starbtn.is-on{color:var(--au-accent)}
    .au-rv-starbtn:active{transform:scale(1.15)}
    .au-rv-formrow{display:flex;gap:10px;margin-top:4px}

    /* ---------------- loyalty: checkout / booking redeem row + rewards card ---------------- */
    .au-loyalty-block{margin:6px 0 14px}
    .au-loyalty-teaser{background:var(--au-accent-soft);border-radius:var(--au-radius-sm);padding:13px 14px}
    .au-loyalty-teaser .au-lt-program{font-weight:700;font-size:.9rem}
    .au-loyalty-teaser p{margin:3px 0 0;font-size:.8rem;color:var(--au-muted)}
    .au-loyalty-teaser .au-loyalty-hint{color:var(--au-muted);font-style:italic}
    .au-card--rewards{background:linear-gradient(135deg, var(--au-accent-ink) 0%, #2b220f 100%);color:#f4ecdc;
        border:0;padding:18px}
    .au-card--rewards h3{font-family:var(--au-serif);font-weight:600;font-size:1.2rem;margin:0;color:#f4ecdc}
    .au-card--rewards .au-muted-line{color:#e8dcc2;opacity:.85}
    .au-rw-tiers{display:flex;gap:8px;margin-top:16px}
    .au-rw-tier{flex:1;text-align:center;background:rgba(255,255,255,.12);border-radius:10px;padding:8px 4px;
        font-size:.72rem;font-weight:700;letter-spacing:.02em}
    .au-rw-tier.is-current{background:var(--au-accent);color:var(--au-accent-ink)}
    .au-rw-signin{color:#e8dcc2 !important;opacity:.85;font-style:italic}

    /* ---------------- wishlist / saved ---------------- */
    .au-heart{position:absolute;top:8px;inset-inline-end:8px;z-index:2;width:30px;height:30px;border-radius:50%;
        border:0;background:rgba(20,16,8,.4);backdrop-filter:blur(2px);color:#fff;display:flex;align-items:center;
        justify-content:center;cursor:pointer;transition:transform .15s ease;padding:0}
    .au-heart svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2}
    .au-heart.is-fav{color:var(--au-accent)}
    .au-heart.is-fav svg{fill:var(--au-accent)}
    .au-heart.au-pop{transform:scale(1.22)}
    .au-heart-inline svg{fill:none;stroke:currentColor;stroke-width:1.8}
    .au-heart-inline.is-fav{color:var(--au-accent)}
    .au-heart-inline.is-fav svg{fill:var(--au-accent)}

    @media (min-width: 860px){
        .au-nav{position:static;background:transparent;backdrop-filter:none;border:0;padding:0;justify-content:flex-start;
            gap:4px;max-width:1180px;margin:0 auto;padding:0 16px}
        .au-nav.is-drilldown{display:flex}
        .au-navbtn{flex:0 0 auto;flex-direction:row;padding:9px 14px;border-radius:999px}
        .au-navbtn[aria-current="true"]{background:var(--au-accent-soft)}
        .aurelia{padding-bottom:30px}
        .au-search-row{grid-template-columns:1fr 1fr 1fr}
        .au-teaser-row{grid-template-columns:1fr 1fr}
        .au-rooms-grid{grid-template-columns:1fr 1fr}
        .au-amenity-list{grid-template-columns:1fr 1fr 1fr}
        .au-gallery-strip{grid-template-columns:1fr 1fr 1fr}
        .au-trust-strip{grid-template-columns:repeat(4,1fr)}
    }

    /* ================================================================
       DESKTOP LAYER (additive-only). Nothing below 768px changes and
       the min-width:860px block above keeps working untouched — these
       three breakpoints (the shared platform contract) build on top of
       it: more generous grids, a proper 2-column room detail with a
       sticky booking widget, capped shop grids so cards stay generous,
       and a taller, more editorial hero. Every surface reuses the
       existing --au-* custom properties, so dark mode extends for free;
       every rule uses logical properties or lets CSS Grid's own
       direction-aware column flow handle RTL — no left/right, so
       [dir="rtl"] mirrors automatically. ---------------------------- */
    @media (min-width: 768px){
        .au-rail-card{width:250px}
        .au-shop-grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr))}
        /* Promo banners: the 86%-wide scroll-snap carousel is a phone pattern —
           on wider screens let the banners share the row instead of forcing a
           horizontal scroll where only one oversized card shows. */
        .au-banner-track{overflow-x:visible;scroll-snap-type:none}
        .au-banner{flex:1 1 0;min-width:0;scroll-snap-align:none;aspect-ratio:21/9}
        .au-banner-dots{display:none}
    }

    @media (min-width: 1024px){
        /* shell — real horizontal breathing room instead of the phone
           gutter, hero's bleed widened to match */
        .aurelia{--au-pad:32px}
        .au-hero{max-height:640px}
        .au-hero-inner{padding-inline:32px;padding-block:48px 150px;max-width:900px}
        .au-hero h1{font-size:clamp(2.6rem,4vw,3.4rem)}
        .au-hero p{font-size:1.05rem}
        .au-search-card{max-width:920px;margin-inline:auto;margin-block-start:-100px;padding:22px}
        .au-rail-card{width:270px}
        .au-search{max-width:360px}

        /* top nav already goes static/inline at 860px above — nothing
           left fixed at the bottom to clear at this width */
        .au-toast{bottom:24px}

        /* rooms grid — richer cards (still 2-col; 3-col kicks in @1280) */
        .au-room-card{transition:box-shadow .2s ease,transform .2s ease}
        .au-room-card:hover{box-shadow:var(--au-shadow);transform:translateY(-2px)}
        .au-room-card__body{padding:18px 20px 20px}
        .au-room-card__name{font-size:1.4rem}

        /* dining / spa — cap the auto-fill grid so cards read as
           generous, not a wall of tiny tiles */
        .au-shop-grid{grid-template-columns:repeat(auto-fill,minmax(225px,1fr))}
        .au-shop-card__body{padding:14px 16px 16px}
        .au-shop-card__name{font-size:.98rem}

        /* room detail — gallery (spanning the booking widget's height)
           on one side, a sticky booking widget (name/availability, rate
           plan, dates/total recap, Reserve CTA) on the other. Flat CSS
           Grid placement of the section's existing children — no DOM
           restructuring, so the JS (which only ever does
           getElementById) is untouched. */
        #au-room-detail{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,380px);
            column-gap:44px;row-gap:16px;align-items:start}
        #au-room-detail > *{min-width:0}
        #au-room-detail > .au-back-hdr{grid-column:1 / -1;grid-row:1}
        #au-room-detail #auRoomGallery{grid-column:1;grid-row:2 / 6}
        #au-room-detail .au-room-detail-head{grid-column:2;grid-row:2}
        #au-room-detail .au-room-detail__plan{grid-column:2;grid-row:3}
        #au-room-detail > .au-recap{grid-column:2;grid-row:4;margin:0}
        #au-room-detail > .au-sticky-foot{grid-column:2;grid-row:5;align-self:end;
            position:sticky;top:100px;bottom:auto;margin-top:0;border-top:0;
            background:var(--au-surface);border:1px solid var(--au-line);
            border-radius:var(--au-radius-sm);padding:16px;box-shadow:var(--au-shadow)}
        #au-room-detail > .au-desc{grid-column:1;grid-row:6;margin-block-start:14px}
        #au-room-detail .au-room-detail__amenities{grid-column:1;grid-row:7}

        /* booking wizard + confirmation — a centered form column so
           inputs don't stretch edge-to-edge across the wide container */
        #au-booking > .au-back-hdr,#au-booking .au-steps,#au-booking .au-step-labels,
        #au-booking #auStepDetails,#au-booking #auStepReview,#au-booking #auStepConfirm{
            max-width:640px;margin-inline:auto;width:100%}

        /* my stay — folio statement full width, lookup + guest profile
           side by side underneath */
        #au-stay{display:grid;grid-template-columns:1fr 1fr;column-gap:20px;align-items:start}
        #au-stay > .au-section-hdr{grid-column:1 / -1}
        #au-stay > .au-card--folio{grid-column:1 / -1}
        #au-stay > .au-card--rewards{grid-column:1 / -1}

        /* stories — slightly larger bubbles once there's room; saved/
           wishlist reuses .au-rooms-grid/.au-shop-grid as-is, so it already
           inherits their responsive column counts above for free */
        .au-story-item{width:74px}
        .au-story-ring{width:70px;height:70px}
        .au-story-label{max-width:74px}

        /* cart / item / date sheets stay the drawer pattern (they are
           overlays, not dedicated pages) — just a little roomier */
        .au-sheet{width:min(480px,92vw)}
    }

    @media (min-width: 1280px){
        .aurelia{max-width:1240px;--au-pad:44px}
        .au-hero{min-height:66vh;max-height:680px}
        .au-hero-inner{padding-inline:44px;padding-block:56px 170px}
        .au-search-card{max-width:1000px;margin-block-start:-110px}
        .au-rooms-grid{grid-template-columns:repeat(3,1fr)}
        .au-shop-grid{grid-template-columns:repeat(auto-fill,minmax(245px,1fr))}
    }
</style>
@endpush

@section('content')
@php
    // Feature vars re-declared in section scope: a child view's top-level php-block
    // locals render in the layout scope at yield time and do not reliably reach the
    // section body, so define them here where the markup below consumes them.
    $auStoriesRoute = url('r/'.$store->id.'/stories');
    $auMbrBase = url('r/'.$store->id.'/item');
    $auLoyaltyBalanceRoute = url('restaurant/'.$store->id.'/loyalty/balance');
    $auLoyRaw = store_options($store->id, 'loyalty_teaser');
    $auLoyTeaser = $auLoyRaw ? (array) $auLoyRaw : null;
    $auLanguages = collect($menu_languages ?? [])
        ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
        ->values();
@endphp
<div class="aurelia" id="aurelia" data-store-id="{{ $store->id }}" data-theme-slug="aurelia">

    <header class="au-topbar">
        <div class="au-brand">
            @if($store->main_image && $store->main_image !== 'default.png')
                @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img class="au-logo" src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="hide">@endif
            @endif
            <span class="au-brandname">{{ $store->title }}</span>
        </div>
        <button type="button" class="au-iconbtn" id="auDarkToggle" aria-label="{{ ___('Toggle dark mode') }}" title="{{ ___('Toggle dark mode') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
        </button>
        <button type="button" class="au-iconbtn au-notifbell" id="auNotifBell" aria-label="{{ ___('Notifications') }}" title="{{ ___('Notifications') }}" style="position:relative">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
            <i class="au-notif-dot" hidden aria-hidden="true"></i>
        </button>
        <button type="button" class="au-iconbtn" id="auSavedOpen" aria-label="{{ ___('Saved') }}" title="{{ ___('Saved') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
            <i class="au-badge" id="auSavedBadge" hidden>0</i>
        </button>
        @if($auLanguages->count() > 1)
            <label class="au-langsel" aria-label="{{ ___('Language') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
                <select id="auLangSel">
                    @foreach($auLanguages as $lang)
                        <option value="{{ $lang->code }}" data-url="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}" @if(get_lang() === $lang->code) selected @endif>{{ strtoupper($lang->code) }}</option>
                    @endforeach
                </select>
            </label>
            <script nonce="{{ csp_nonce() }}">
                (function(){var s=document.getElementById('auLangSel');if(!s)return;s.addEventListener('change',function(e){var o=e.target.selectedOptions[0];if(!o)return;var code=o.value;try{document.cookie='Quick_user_lang_code='+code+';path=/;max-age=31536000;samesite=lax';document.cookie='qc_lang_choice='+code+';path=/;max-age=31536000;samesite=lax';}catch(err){}window.location.assign(o.getAttribute('data-url')||window.location.href);});})();
            </script>
        @endif
        <button type="button" class="au-iconbtn" id="auCartOpen" aria-label="{{ ___('Cart') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg>
            <i class="au-badge" id="auCartBadge" hidden>0</i>
        </button>
    </header>

    {{-- Bottom tabbar — root-level (a sibling of the header, NOT a child of it):
         the header's backdrop-filter establishes a containing block that would
         trap this fixed element inside the header. At ≥860px it flips to a static
         inline strip. --}}
    <nav class="au-nav" id="auNav" aria-label="{{ ___('Main') }}">
        <button type="button" class="au-navbtn" data-nav="home" aria-current="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20v-7a2 2 0 0 1 2-2h1a2 2 0 0 1 2 2v2h6v-2a2 2 0 0 1 2-2h1a2 2 0 0 1 2 2v7"/><path d="M2.5 17h19M4 13V8.2a1 1 0 0 1 .5-.87l7-4a1 1 0 0 1 1 0l7 4a1 1 0 0 1 .5.87V13"/></svg>
            <span>{{ ___('Stay') }}</span>
        </button>
        <button type="button" class="au-navbtn" data-nav="rooms" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V4.6a1 1 0 0 1 1.2-1L17 5.8a1 1 0 0 1 .8 1v14.2"/><path d="M5 21h14M14 13h.01"/></svg>
            <span>{{ ___('Rooms') }}</span>
        </button>
        <button type="button" class="au-navbtn" data-nav="dining" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.5v7a1.8 1.8 0 0 0 1.8 1.8V21M6 2.5v6M8.4 2.5v6M17.8 2.5c-1.5 0-2.7 1.6-2.7 4.6V11h2.7M17.8 2.5V21"/></svg>
            <span>{{ ___('Dining') }}</span>
            <i class="au-badge" id="auNavCartBadge" hidden>0</i>
        </button>
        <button type="button" class="au-navbtn" data-nav="spa" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.2S18 8.6 18 13.4A6 6 0 1 1 6 13.4C6 8.6 12 3.2 12 3.2Z"/></svg>
            <span>{{ ___('Spa') }}</span>
        </button>
        <button type="button" class="au-navbtn" data-nav="stay" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l3.5 3.5V21H6z"/><path d="M9.5 9.5h6M9.5 13h6M9.5 16.5h4"/></svg>
            <span>{{ ___('My Stay') }}</span>
        </button>
    </nav>

    <main id="aurelia-main">

        {{-- ═══════════════════════════ HOME ═══════════════════════════ --}}
        <section class="au-screen" id="au-home" data-screen="home">
            {{-- ---------- stories rail — hotel highlights (Suite of the season,
                 Spa ritual, Chef's table…) as a circular bubble rail, Instagram-
                 style. Populated client-side from GET {{ url('r/'.$store->id.'/stories') }};
                 ships empty + hidden so a slow/failed fetch never flashes a
                 broken shell, and the whole rail hides itself if the feed is
                 empty. --------------------------------------------------- --}}
            <div class="au-stories" id="auStoriesRail" hidden></div>

            <div class="au-hero">
                @if((string) ($auHeroImg) !== '')<img src="{{ $auHeroImg }}" alt="" data-csp-error="hide">@endif
                <div class="au-hero-inner">
                    <span class="au-kicker">{{ ___('Five-star hotel') }}</span>
                    <h1 tabindex="-1">{{ $store->title }}</h1>
                    @if(trim((string) ($store->sub_title ?? '')) !== '')
                        <p>{{ $store->sub_title }}</p>
                    @else
                        <p>{{ ___('Rooms, in-room dining, spa and experiences — all in one place, charged to your room.') }}</p>
                    @endif
                </div>
            </div>

            <div class="au-search-card">
                <div class="au-search-row">
                    <div class="au-field">
                        <label for="auCheckIn">{{ ___('Check-in') }}</label>
                        <input type="date" id="auCheckIn">
                    </div>
                    <div class="au-field">
                        <label for="auCheckOut">{{ ___('Check-out') }}</label>
                        <input type="date" id="auCheckOut">
                    </div>
                    <div class="au-field">
                        <label id="auGuestsLabel">{{ ___('Guests') }}</label>
                        <div class="au-stepper" role="group" aria-labelledby="auGuestsLabel">
                            <button type="button" id="auGuestsMinus" aria-label="{{ ___('Decrease') }}">&minus;</button>
                            <span id="auGuestsVal">2</span>
                            <button type="button" id="auGuestsPlus" aria-label="{{ ___('Increase') }}">&plus;</button>
                        </div>
                    </div>
                </div>
                <button type="button" class="au-btn au-btn--primary au-btn--block" id="auSearchBtn">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    {{ ___('Search availability') }}
                </button>
            </div>

            {{-- Crave-parity status row: Open-Now chip + (multi-store) branch switch. --}}
            <div class="au-statusrow">
                <span class="au-openchip @if(! $auIsOpen) is-closed @endif" data-mb-openchip data-mb-closed-class="is-closed">
                    <i aria-hidden="true"></i><span data-mb-open-text>{{ $auIsOpen ? ___('Open now') : ___('Closed') }}@if($auHoursToday) · {{ $auHoursToday }}@endif</span>
                </span>
                @if($auShowBranches)
                    <label class="au-branchsel" aria-label="{{ ___('Branch') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <select id="auBranchSel">
                            @foreach($auBranches as $branch)
                                <option value="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}" @if(($auActiveBranch->id ?? null) === $branch->id) selected @endif>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>

            @if($auBanners->count())
                <div class="au-banners" id="auBanners">
                    <div class="au-banner-track" id="auBannerTrack">
                        @foreach($auBanners as $b)
                            @php
                                $auBannerLink = trim((string) $b->link_url);
                                // A "#screen" link is an in-app deep-link handled by the SPA
                                // (banner-click handler → go(screen)); anything else is a
                                // real outbound URL. An empty link falls back to the rooms
                                // screen so a banner is never a dead tap.
                                $auBannerScreen = ($auBannerLink !== '' && str_starts_with($auBannerLink, '#')) ? ltrim($auBannerLink, '#') : '';
                                $auBannerExternal = ($auBannerLink !== '' && $auBannerScreen === '');
                            @endphp
                            <a class="au-banner"
                               @if($auBannerExternal) href="{{ storefront_localized_link($auBannerLink) }}" target="_blank" rel="noopener"
                               @else href="#" data-banner-screen="{{ $auBannerScreen ?: 'rooms' }}" @endif>
                                @if($b->image)<img class="au-banner-img" src="{{ $b->image_url ?? media_url('storage/menu_banners/', $b->image, $store->id ?? null) }}" alt="" data-csp-error="hide">@endif
                                <span class="au-banner-copy">
                                    @if($b->subtitle)<span class="au-banner-kick">{{ $b->subtitle }}</span>@endif
                                    <span class="au-banner-title">{{ $b->title }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                    @if($auBanners->count() > 1)<div class="au-banner-dots" id="auBannerDots" aria-hidden="true"></div>@endif
                </div>
            @endif

            <div class="au-section-hdr">
                <div>
                    <h2>{{ ___('Featured rooms') }}</h2>
                    <p>{{ ___('Handpicked for tonight’s stay') }}</p>
                </div>
                <button type="button" class="au-link" data-nav="rooms">{{ ___('See all') }}</button>
            </div>
            <div class="au-rail" id="auFeaturedRail" aria-live="polite"></div>

            <div class="au-teaser-row">
                <button type="button" class="au-teaser" data-nav="dining">
                    @if((string) ($auDiningTeaserImg) !== '')<img src="{{ $auDiningTeaserImg }}" alt="" data-csp-error="hide">@endif
                    <span class="au-teaser-body">
                        <h3>{{ ___('In-Room Dining') }}</h3>
                        <p>{{ ___('Fresh dishes delivered straight to your door') }}</p>
                        <span class="au-link">{{ ___('View menu') }} &rsaquo;</span>
                    </span>
                </button>
                <button type="button" class="au-teaser" data-nav="spa">
                    @if((string) ($auSpaTeaserImg) !== '')<img src="{{ $auSpaTeaserImg }}" alt="" data-csp-error="hide">@endif
                    <span class="au-teaser-body">
                        <h3>{{ ___('Spa & Amenities') }}</h3>
                        <p>{{ ___('Unwind with treatments made for you') }}</p>
                        <span class="au-link">{{ ___('Explore') }} &rsaquo;</span>
                    </span>
                </button>
            </div>

            <div class="au-section-hdr">
                <div>
                    <h2>{{ ___('Experiences') }}</h2>
                    <p>{{ ___('Curated moments beyond the room') }}</p>
                </div>
            </div>
            <div class="au-teaser-row">
                <button type="button" class="au-teaser" data-nav="spa" style="min-height:130px">
                    @if((string) ($auExperienceImg) !== '')<img src="{{ $auExperienceImg }}" alt="" data-csp-error="hide">@endif
                    <span class="au-teaser-body">
                        <h3>{{ ___('Signature experiences') }}</h3>
                        <p>{{ ___('From sunset tastings to guided city tours') }}</p>
                    </span>
                </button>
                <button type="button" class="au-teaser" data-nav="stay" style="min-height:130px">
                    @if((string) ($auLobbyImg) !== '')<img src="{{ $auLobbyImg }}" alt="" data-csp-error="hide">@endif
                    <span class="au-teaser-body">
                        <h3>{{ ___('Concierge, always on') }}</h3>
                        <p>{{ ___('Everything about your stay, one tap away') }}</p>
                    </span>
                </button>
            </div>

            <div class="au-trust-strip">
                <div class="au-trust">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5z"/></svg>
                    <span>{{ ___('Secure booking') }}</span>
                </div>
                <div class="au-trust">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                    <span>{{ ___('Best available rate') }}</span>
                </div>
                <div class="au-trust">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/></svg>
                    <span>{{ ___('24/7 concierge') }}</span>
                </div>
                <div class="au-trust">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7 9 18l-5-5"/></svg>
                    <span>{{ ___('Flexible cancellation') }}</span>
                </div>
            </div>
        </section>

        {{-- ═══════════════════════════ ROOMS ═══════════════════════════ --}}
        <section class="au-screen" id="au-rooms" data-screen="rooms" hidden>
            <div class="au-section-hdr" style="margin-top:20px">
                <div><h2>{{ ___('Rooms & Suites') }}</h2></div>
            </div>
            <div class="au-rooms-summary">
                <span id="auRoomsSummaryText">&nbsp;</span>
                <button type="button" class="au-link" id="auRoomsEditBtn">{{ ___('Edit') }}</button>
            </div>
            <div class="au-sample-note" id="auRoomsSampleNote" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
                <span>{{ ___('Sample availability — connect the booking engine for live rooms and rates.') }}</span>
            </div>
            <div class="au-rooms-grid" id="auRoomsGrid" aria-live="polite"></div>
            <p class="au-empty" id="auRoomsEmpty" hidden>{{ ___('No rooms match those dates. Try a different range.') }}</p>
        </section>

        {{-- ═══════════════════════ ROOM DETAIL (drill-down) ═══════════════════════ --}}
        <section class="au-screen" id="au-room-detail" data-screen="room-detail" hidden>
            <div class="au-back-hdr">
                <button type="button" class="au-iconbtn" id="auRoomDetailBack" aria-label="{{ ___('Back') }}">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <h1 id="auRoomDetailTitle">&nbsp;</h1>
                <button type="button" class="au-iconbtn au-heart-inline" id="auRoomDetailFavBtn" aria-label="{{ ___('Save') }}" aria-pressed="false">
                    <svg viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                </button>
            </div>

            <div class="au-gallery" id="auRoomGallery"></div>

            <div class="au-room-detail-head">
                <div>
                    <h2 id="auRoomDetailName">&nbsp;</h2>
                    <div class="au-muted-line" id="auRoomDetailMeta"></div>
                </div>
                <span class="au-avail" id="auRoomDetailAvail"></span>
            </div>

            <p class="au-desc" id="auRoomDetailDesc"></p>

            <div class="au-group au-room-detail__amenities">
                <div class="au-group__label">{{ ___('What’s in the room') }}</div>
                <div class="au-amenity-list">
                    <span class="au-amenity"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5a7 7 0 0 1 14 0"/><path d="M3 19h18M5 19v-3.5M19 19v-3.5"/></svg>{{ ___('Free Wi-Fi') }}</span>
                    <span class="au-amenity"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h20M12 2v20"/><circle cx="12" cy="12" r="9"/></svg>{{ ___('Air conditioning') }}</span>
                    <span class="au-amenity"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="13" rx="1.5"/><path d="M8 21h8"/></svg>{{ ___('Smart TV') }}</span>
                    <span class="au-amenity"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="1.5"/><path d="M9 8h6M9 12h6"/></svg>{{ ___('Minibar') }}</span>
                    <span class="au-amenity"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h4v6H4zM4 4v16M8 10v10"/><path d="M14 6c3 0 6 2.5 6 7"/></svg>{{ ___('Rain shower') }}</span>
                    <span class="au-amenity"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="9" width="16" height="9" rx="1.5"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg>{{ ___('In-room safe') }}</span>
                </div>
            </div>

            <div class="au-group au-room-detail__plan">
                <div class="au-group__label">{{ ___('Rate plan') }}</div>
                <label class="au-plan is-active" id="auPlanFlexible" data-plan="flexible">
                    <input type="radio" name="auPlan" value="flexible" checked>
                    <span class="au-plan-copy"><b>{{ ___('Flexible') }}</b><span>{{ ___('Free cancellation up to 24h before check-in') }}</span></span>
                    <span class="au-plan-price" id="auPlanFlexiblePrice"></span>
                </label>
                <label class="au-plan" id="auPlanNonrefundable" data-plan="non_refundable">
                    <input type="radio" name="auPlan" value="non_refundable">
                    <span class="au-plan-copy"><b>{{ ___('Non-refundable') }}</b><span>{{ ___('Save 10% — pay in full now, no changes') }}</span></span>
                    <span class="au-plan-price" id="auPlanNonrefundablePrice"></span>
                </label>
            </div>

            <div class="au-recap">
                <div class="au-recap-row"><span class="au-muted-line">{{ ___('Dates') }}</span><span id="auRoomDetailDates"></span></div>
                <div class="au-recap-row"><span class="au-muted-line">{{ ___('Guests') }}</span><span id="auRoomDetailGuests"></span></div>
                <div class="au-recap-row"><span class="au-muted-line" id="auRoomDetailNightsLabel"></span><span id="auRoomDetailNightsCalc"></span></div>
                <div class="au-recap-row is-total"><span>{{ ___('Total') }}</span><span id="auRoomDetailTotalInline"></span></div>
                <button type="button" class="au-link" id="auRoomDetailChangeDates" style="margin-top:8px">{{ ___('Change dates') }}</button>
            </div>

            <div class="au-sticky-foot">
                <span class="au-price" id="auRoomDetailTotal"><small>{{ ___('Total') }}</small>&nbsp;</span>
                <button type="button" class="au-btn au-btn--primary" id="auReserveBtn">{{ ___('Reserve') }}</button>
            </div>
        </section>

        {{-- ═══════════════════════ BOOKING (drill-down) ═══════════════════════ --}}
        <section class="au-screen" id="au-booking" data-screen="booking" hidden>
            <div class="au-back-hdr">
                <button type="button" class="au-iconbtn" id="auBookingBack" aria-label="{{ ___('Back') }}">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <h1>{{ ___('Reserve your stay') }}</h1>
            </div>

            <div class="au-steps" id="auBookingSteps">
                <div class="au-step is-active" data-step="details"></div>
                <div class="au-step" data-step="review"></div>
                <div class="au-step" data-step="confirm"></div>
            </div>
            <div class="au-step-labels">
                <span>{{ ___('Details') }}</span><span>{{ ___('Review') }}</span><span>{{ ___('Confirmed') }}</span>
            </div>

            <div id="auStepDetails">
                <div class="au-recap" id="auBookingRecap1"></div>
                <div class="au-field" style="margin-bottom:10px">
                    <label for="auGuestName">{{ ___('Full name') }}</label>
                    <input type="text" id="auGuestName" maxlength="120" autocomplete="name">
                </div>
                <div class="au-field" style="margin-bottom:10px">
                    <label for="auGuestPhone">{{ ___('Phone') }}</label>
                    <input type="tel" id="auGuestPhone" maxlength="40" autocomplete="tel">
                </div>
                <div class="au-field" style="margin-bottom:10px">
                    <label for="auGuestEmail">{{ ___('Email') }}</label>
                    <input type="email" id="auGuestEmail" maxlength="190" autocomplete="email">
                </div>
                <div class="au-field" style="margin-bottom:6px">
                    <label for="auGuestNotes">{{ ___('Special requests (optional)') }}</label>
                    <textarea id="auGuestNotes" maxlength="600"></textarea>
                </div>
                @if($auLoyTeaser)
                {{-- K3 · loyalty redeem row — teaser is public/no-auth
                     (store_options loyalty_teaser); the live balance + "ready to
                     redeem" toggle is auth:customer (guests get 401 — see
                     loadBookingLoyalty() below) and only appears once the
                     balance response says ready_to_redeem. --}}
                <div class="au-loyalty-block" id="auBookingLoyaltySect">
                    <div class="au-loyalty-teaser" id="auBookingLoyaltyTeaser">
                        <div class="au-lt-program">{{ $auLoyTeaser['program'] ?? ___('Rewards') }}</div>
                        @if(!empty($auLoyTeaser['earn']))<p>{{ $auLoyTeaser['earn'] }}</p>@endif
                        @if(!empty($auLoyTeaser['reward']))<p>{{ $auLoyTeaser['reward'] }}</p>@endif
                        <p class="au-loyalty-hint" id="auBookingLoyaltyHint">{{ ___('Sign in to earn & redeem points') }}</p>
                    </div>
                    <label class="au-charge" id="auBookingLoyaltyRedeemBox" hidden>
                        <input type="checkbox" id="auBookingLoyaltyToggle">
                        <span><b id="auBookingLoyOfferVal"></b><small id="auBookingLoyBalanceLine"></small></span>
                    </label>
                    <input type="hidden" name="loyalty_redeem" id="auBookingLoyaltyRedeemField" value="0">
                </div>
                @endif
                <div id="auDetailsMsg"></div>
                <button type="button" class="au-btn au-btn--primary au-btn--block" id="auContinueReview" style="margin-top:12px">{{ ___('Continue to review') }}</button>
            </div>

            <div id="auStepReview" hidden>
                <div class="au-recap" id="auBookingRecap2"></div>
                <p class="au-confirm-note" style="margin:0 0 16px">{{ ___('By confirming, you agree these details are correct. This reservation is subject to availability confirmation.') }}</p>
                <div id="auBookingError"></div>
                <button type="button" class="au-btn au-btn--primary au-btn--block" id="auConfirmBooking">{{ ___('Confirm reservation') }}</button>
                <button type="button" class="au-link" id="auEditDetails" style="display:block;text-align:center;margin-top:12px">{{ ___('Edit details') }}</button>
            </div>

            <div id="auStepConfirm" hidden>
                <div class="au-confirm-card" id="auConfirmCard">
                    <div class="au-confirm-icon" id="auConfirmIcon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7 9 18l-5-5"/></svg>
                    </div>
                    <h2 id="auConfirmTitle"></h2>
                    <p class="au-confirm-note" id="auConfirmSub"></p>
                    <div class="au-confirm-code" id="auConfirmCode"></div>
                    <p class="au-confirm-note" id="auConfirmNote"></p>
                    <div class="au-confirm-actions">
                        <a href="#" class="au-btn au-btn--ghost" id="auConfirmOpenLink" hidden target="_blank" rel="noopener">{{ ___('Open full confirmation') }}</a>
                        <button type="button" class="au-btn au-btn--ghost" id="auViewStayBtn">{{ ___('View my stay') }}</button>
                        <button type="button" class="au-btn au-btn--primary" id="auBookAnotherBtn">{{ ___('Book another stay') }}</button>
                    </div>
                </div>
            </div>
        </section>

        {{-- ═══════════════════════════ DINING ═══════════════════════════ --}}
        <section class="au-screen" id="au-dining" data-screen="dining" hidden>
            <div class="au-section-hdr" style="margin-top:20px">
                <div>
                    <h2>{{ ___('In-Room Dining') }}</h2>
                    <p>{{ ___('Fresh dishes delivered straight to your door') }}</p>
                </div>
            </div>
            <div class="au-shop-toolbar">
                <input type="search" class="au-search" id="auDiningSearch" autocomplete="off"
                       placeholder="{{ ___('Search dishes') }}" aria-label="{{ ___('Search dishes') }}">
            </div>
            @if($diningCategories->isNotEmpty())
            <nav class="au-cats" role="tablist" aria-label="{{ ___('Categories') }}">
                <button type="button" class="au-chip is-active" role="tab" aria-selected="true" data-cat="all" data-scope="dining">{{ ___('All') }}</button>
                @foreach($diningCategories as $cat)
                    <button type="button" class="au-chip" role="tab" aria-selected="false" data-cat="{{ $cat->id }}" data-scope="dining">{{ $cat->name }}</button>
                @endforeach
            </nav>
            @endif
            @if($diningItems->isEmpty())
                <p class="au-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2.5v7a1.8 1.8 0 0 0 1.8 1.8V21M6 2.5v6M8.4 2.5v6M17.8 2.5c-1.5 0-2.7 1.6-2.7 4.6V11h2.7M17.8 2.5V21"/></svg><br>
                    {{ ___('Nothing here yet.') }}
                </p>
            @else
                <div class="au-shop-grid" id="auDiningGrid"></div>
                <p class="au-empty" id="auDiningNoResults" hidden>{{ ___('No results.') }}</p>
            @endif
        </section>

        {{-- ═══════════════════════════ SPA & AMENITIES ═══════════════════════════ --}}
        <section class="au-screen" id="au-spa" data-screen="spa" hidden>
            <div class="au-section-hdr" style="margin-top:20px">
                <div>
                    <h2>{{ ___('Spa & Amenities') }}</h2>
                    <p>{{ ___('Treatments, experiences and hotel amenities') }}</p>
                </div>
            </div>
            @if($amenityItems->isEmpty())
                <p class="au-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 3.2S18 8.6 18 13.4A6 6 0 1 1 6 13.4C6 8.6 12 3.2 12 3.2Z"/></svg><br>
                    {{ ___('Spa and amenity booking opens once a Spa, Amenities or Experiences category is added to the menu.') }}
                </p>
            @else
                <div class="au-shop-toolbar">
                    <input type="search" class="au-search" id="auSpaSearch" autocomplete="off"
                           placeholder="{{ ___('Search spa & amenities') }}" aria-label="{{ ___('Search spa & amenities') }}">
                </div>
                @if($amenityCategories->count() > 1)
                <nav class="au-cats" role="tablist" aria-label="{{ ___('Categories') }}">
                    <button type="button" class="au-chip is-active" role="tab" aria-selected="true" data-cat="all" data-scope="spa">{{ ___('All') }}</button>
                    @foreach($amenityCategories as $cat)
                        <button type="button" class="au-chip" role="tab" aria-selected="false" data-cat="{{ $cat->id }}" data-scope="spa">{{ $cat->name }}</button>
                    @endforeach
                </nav>
                @endif
                <div class="au-shop-grid" id="auSpaGrid"></div>
                <p class="au-empty" id="auSpaNoResults" hidden>{{ ___('No results.') }}</p>
            @endif
        </section>

        {{-- ═══════════════════════════ MY STAY / FOLIO ═══════════════════════════ --}}
        <section class="au-screen" id="au-stay" data-screen="stay" hidden>
            <div class="au-section-hdr" style="margin-top:20px">
                <div><h2>{{ ___('My Stay') }}</h2></div>
            </div>

            <div class="au-card au-card--folio">
                <h3>
                    {{ ___('Folio') }}
                    @if($auFolio)
                        <span class="au-tag is-live">{{ ___('Live') }}</span>
                    @else
                        <span class="au-tag">{{ ___('Sample') }}</span>
                    @endif
                </h3>
                @if($auFolio)
                    <p class="au-muted-line">{{ ___('Room :room · Guest :name', ['room' => $auRoomLabel ?? '—', 'name' => $auFolio['guest_name'] ?? '']) }}</p>
                    @foreach(array_slice($auFolio['charges'], -6) as $line)
                        <div class="au-folio-row"><span>{{ $line['reason'] }}</span><span>{{ price_symbol_format($line['amount']) }}</span></div>
                    @endforeach
                    <div class="au-folio-row"><span>{{ ___('Payments') }}</span><span>-{{ price_symbol_format($auFolio['total_payments']) }}</span></div>
                    <div class="au-folio-row is-total"><span>{{ ___('Balance due') }}</span><span>{{ price_symbol_format($auFolio['balance']) }}</span></div>
                    @if($auFolioLink)
                        <a class="au-link" href="{{ $auFolioLink }}" style="display:block;margin-top:12px">{{ ___('Open full statement') }}</a>
                    @endif
                @else
                    <p class="au-muted-line">{{ ___('Your folio consolidates room charges, dining and spa into one bill, settled at checkout.') }}</p>
                    <div class="au-folio-row"><span>{{ ___('Room charges') }}</span><span>{{ price_symbol_format(0) }}</span></div>
                    <div class="au-folio-row"><span>{{ ___('Dining') }}</span><span>{{ price_symbol_format(0) }}</span></div>
                    <div class="au-folio-row"><span>{{ ___('Spa & amenities') }}</span><span>{{ price_symbol_format(0) }}</span></div>
                    <div class="au-folio-row is-total"><span>{{ ___('Balance due') }}</span><span>{{ price_symbol_format(0) }}</span></div>
                    <p class="au-muted-line" style="margin-top:10px">{{ ___('Scan the room QR code during your stay to see your real folio here.') }}</p>
                @endif
            </div>

            {{-- K3 · loyalty Rewards card — public teaser (store_options
                 loyalty_teaser), readable with no auth so it renders for guest
                 + signed-in alike. Tier chips are the program's own
                 Silver/Gold/Platinum ladder as a visual; when a real balance
                 fetch succeeds (see loadStayLoyalty() below) the diner's
                 current tier is highlighted — never fabricated if that call
                 fails, the card just stays a tasteful teaser. --}}
            @if($auLoyTeaser)
                <div class="au-card au-card--rewards">
                    <h3>{{ $auLoyTeaser['program'] ?? ___('Rewards') }}</h3>
                    @if(!empty($auLoyTeaser['earn']))
                        <p class="au-muted-line">{{ $auLoyTeaser['earn'] }}</p>
                    @endif
                    @if(!empty($auLoyTeaser['reward']))
                        <p class="au-muted-line">{{ $auLoyTeaser['reward'] }}</p>
                    @endif
                    <div class="au-rw-tiers" aria-hidden="true">
                        <span class="au-rw-tier" id="auRwTierSilver">{{ ___('Silver') }}</span>
                        <span class="au-rw-tier" id="auRwTierGold">{{ ___('Gold') }}</span>
                        <span class="au-rw-tier" id="auRwTierPlatinum">{{ ___('Platinum') }}</span>
                    </div>
                    <p class="au-muted-line au-rw-signin" id="auRwSigninHint" style="margin-top:10px">{{ ___('Sign in to earn & redeem points') }}</p>
                </div>
            @endif

            <div class="au-card">
                <h3>{{ ___('Find a reservation') }}</h3>
                <p class="au-muted-line">{{ ___('Look up a booking made from this device.') }}</p>
                <div class="au-field" style="margin-bottom:10px">
                    <label for="auLookupCode">{{ ___('Confirmation code') }}</label>
                    <input type="text" id="auLookupCode" maxlength="12" style="text-transform:uppercase">
                </div>
                <button type="button" class="au-btn au-btn--ghost au-btn--block" id="auLookupBtn">{{ ___('Find my reservation') }}</button>
                <div id="auLookupResult"></div>
            </div>

            <div class="au-card">
                <h3>{{ ___('My Orders') }}</h3>
                <div id="auOrdersList"></div>
                <p class="au-muted-line" id="auOrdersEmpty" hidden>{{ ___('No orders yet') }}</p>
                <p class="au-muted-line" id="auOrdersGuest" hidden>{{ ___('Sign in to see your orders') }}</p>
                @if(($postOptions->customer_auth_mode ?? '') !== 'demo')
                    @if(auth('customer')->check())
                        @if(\App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
                            <button type="button" class="au-btn au-btn--ghost au-btn--block" data-mb-wallet-open style="margin-top:10px">{{ ___('Wallet — add funds') }}</button>
                        @endif
                        <button type="button" class="au-btn au-btn--ghost au-btn--block" data-mb-logout data-logout-url="{{ route('customer.logout', $store->id) }}" style="margin-top:10px">{{ ___('Log out') }}</button>
                    @else
                        <button type="button" class="au-btn au-btn--primary au-btn--block" data-mb-auth-open style="margin-top:10px">{{ ___('Sign in / Register') }}</button>
                    @endif
                @endif
            </div>

            <div class="au-card">
                <h3>{{ ___('Guest profile') }}</h3>
                <p class="au-muted-line">{{ ___('Saved on this device to speed up your next booking or order.') }}</p>
                <div class="au-field" style="margin-bottom:10px">
                    <label for="auProfileName">{{ ___('Full name') }}</label>
                    <input type="text" id="auProfileName" maxlength="120">
                </div>
                <div class="au-field" style="margin-bottom:10px">
                    <label for="auProfilePhone">{{ ___('Phone') }}</label>
                    <input type="tel" id="auProfilePhone" maxlength="40">
                </div>
                <button type="button" class="au-btn au-btn--ghost au-btn--block" id="auProfileSave">{{ ___('Save') }}</button>
                <button type="button" class="au-btn au-btn--ghost au-btn--block" id="auShareBtn" style="margin-top:10px" aria-label="{{ ___('Share this store') }}" data-share-url="{{ url()->current() }}" data-share-title="{{ $store->title }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" style="vertical-align:-3px;margin-inline-end:6px" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>{{ ___('Share this store') }}
                </button>
                {{-- Privacy: wipe the cart / favourites / saved addresses & profile this
                     browser keeps (shared window.mbClearDeviceData engine). The Guest
                     profile card is guest-reachable ("My Stay" is an always-on tab), so
                     the control lives here rather than in the checkout footer. --}}
                <button type="button" class="au-btn au-btn--ghost au-btn--block au-cleardata" id="auClearData" style="margin-top:10px">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>
                    <span>{{ ___('Clear saved data on this device') }}</span>
                </button>
                <p class="au-cleardata-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
            </div>
        </section>

        {{-- ═══════════════════════════ SAVED / WISHLIST ═══════════════════════════ --}}
        <section class="au-screen" id="au-saved" data-screen="saved" hidden>
            <div class="au-back-hdr">
                <button type="button" class="au-iconbtn" id="auSavedBack" aria-label="{{ ___('Back') }}">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <h1>{{ ___('Saved') }}</h1>
            </div>

            <div class="au-section-hdr" style="margin-top:6px">
                <div><h2>{{ ___('Rooms & suites') }}</h2></div>
            </div>
            <div class="au-rooms-grid" id="auSavedRoomsGrid" aria-live="polite"></div>
            <p class="au-empty" id="auSavedRoomsEmpty" hidden>{{ ___('No saved rooms yet — tap the heart on any room to keep it here.') }}</p>

            <div class="au-section-hdr">
                <div><h2>{{ ___('Dining & spa') }}</h2></div>
            </div>
            <div class="au-shop-grid" id="auSavedItemsGrid" aria-live="polite"></div>
            <p class="au-empty" id="auSavedItemsEmpty" hidden>{{ ___('No saved dishes or treatments yet.') }}</p>
        </section>

    </main>

    {{-- ---------- search sheet (date range + guests editor) ---------- --}}
    <div class="au-sheet-scrim" id="auSearchScrim"></div>
    <aside class="au-sheet" id="auSearchSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Edit dates') }}" aria-hidden="true" inert>
        <div class="au-sheet__head">
            <strong class="au-sheet__title">{{ ___('Edit dates') }}</strong>
            <button type="button" class="au-iconbtn" id="auSearchSheetClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="au-sheet__body">
            <div class="au-field" style="margin-bottom:10px">
                <label for="auCheckIn2">{{ ___('Check-in') }}</label>
                <input type="date" id="auCheckIn2">
            </div>
            <div class="au-field" style="margin-bottom:10px">
                <label for="auCheckOut2">{{ ___('Check-out') }}</label>
                <input type="date" id="auCheckOut2">
            </div>
            <div class="au-field">
                <label id="auGuests2Label">{{ ___('Guests') }}</label>
                <div class="au-stepper" role="group" aria-labelledby="auGuests2Label">
                    <button type="button" id="auGuests2Minus" aria-label="{{ ___('Decrease') }}">&minus;</button>
                    <span id="auGuests2Val">2</span>
                    <button type="button" id="auGuests2Plus" aria-label="{{ ___('Increase') }}">&plus;</button>
                </div>
            </div>
        </div>
        <div class="au-sheet__foot">
            <button type="button" class="au-btn au-btn--primary au-btn--block" id="auSearchApply">{{ ___('Apply') }}</button>
        </div>
    </aside>

    {{-- ---------- item detail sheet (dining + spa share this) ---------- --}}
    <div class="au-sheet-scrim" id="auItemScrim"></div>
    <aside class="au-sheet" id="auItemSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Item') }}" aria-hidden="true" inert>
        <div class="au-sheet__head">
            <strong class="au-sheet__title" id="auItemName" tabindex="-1"></strong>
            <button type="button" class="au-iconbtn au-heart-inline" id="auItemFavBtn" aria-label="{{ ___('Save') }}" aria-pressed="false">
                <svg viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
            </button>
            <button type="button" class="au-iconbtn" id="auItemClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="au-sheet__body">
            <img class="au-detail-img" id="auItemImg" alt="" data-csp-error="hide">
            <div class="au-thumbs" id="auItemThumbs" hidden></div>
            <p id="auItemDesc" style="color:var(--au-muted);font-size:.88rem"></p>
            <div id="auItemVariants"></div>
            <div class="au-group au-addons" id="auItemAddonsGroup" hidden>
                <div class="au-group__label">{{ ___('Add-ons') }}</div>
                <div id="auItemAddons"></div>
            </div>
            <div class="au-group">
                <div class="au-group__label">{{ ___('Quantity') }}</div>
                <div class="au-qty">
                    <button type="button" id="auItemQtyMinus" aria-label="{{ ___('Decrease') }}">&minus;</button>
                    <span id="auItemQtyVal">1</span>
                    <button type="button" id="auItemQtyPlus" aria-label="{{ ___('Increase') }}">&plus;</button>
                </div>
            </div>

            {{-- ---------- reviews — average/count + 5→1 distribution + list +
                 write form. Fetched per-item on openItem() from
                 GET <mbrBase>/<menuId>/reviews?filter=latest and posted back
                 to the same URL. Ships hidden/empty so it never flashes stale
                 data from a previously-open item before the fetch resolves. --}}
            <div class="au-group au-rv" id="auReviewsSection">
                <div class="au-group__label">{{ ___('Reviews') }}</div>

                <div id="auRvSummary" hidden>
                    <div class="au-rv-summary">
                        <div class="au-rv-avgblock">
                            <span class="au-rv-avgnum" id="auRvAvgNum">0.0</span>
                            <span class="au-rv-avgstars" id="auRvAvgStars" aria-hidden="true"></span>
                            <span class="au-rv-avgcount" id="auRvAvgCount"></span>
                        </div>
                        <div class="au-rv-dist" id="auRvDist"></div>
                    </div>
                </div>

                <p class="au-rv-empty" id="auReviewsEmpty" hidden>{{ ___('No reviews yet — be the first') }}</p>

                <div class="au-rv-list" id="auReviewsList"></div>

                <button type="button" class="au-btn au-btn--ghost au-btn--sm au-rv-writebtn" id="auReviewsWriteBtn">{{ ___('Write a review') }}</button>

                <form class="au-rv-form" id="auReviewForm" hidden>
                    <div class="au-group__label">{{ ___('Your rating') }}</div>
                    <div class="au-rv-starsinput" id="auReviewStars" role="group" aria-label="{{ ___('Your rating') }}">
                        @for($auRvS = 1; $auRvS <= 5; $auRvS++)
                            <button type="button" class="au-rv-starbtn" data-star="{{ $auRvS }}" aria-label="{{ $auRvS }}">★</button>
                        @endfor
                    </div>
                    <div class="au-field" style="margin-bottom:10px">
                        <label for="auReviewName">{{ ___('Your name') }}</label>
                        <input type="text" id="auReviewName" maxlength="80" autocomplete="name">
                    </div>
                    <div class="au-field" style="margin-bottom:6px">
                        <label for="auReviewText">{{ ___('Your review') }}</label>
                        <textarea id="auReviewText" maxlength="2000" rows="3"></textarea>
                    </div>
                    <div id="auReviewMsg"></div>
                    <div class="au-rv-formrow">
                        <button type="button" class="au-btn au-btn--ghost" id="auReviewCancelBtn">{{ ___('Cancel') }}</button>
                        <button type="submit" class="au-btn au-btn--primary" id="auReviewSubmitBtn">{{ ___('Submit review') }}</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="au-sheet__foot">
            <button type="button" class="au-btn au-btn--primary au-btn--block" id="auAddToCart">
                <span id="auAddToCartLabel"></span><b id="auAddToCartPrice"></b>
            </button>
        </div>
    </aside>

    {{-- ---------- cart / checkout sheet ---------- --}}
    <div class="au-sheet-scrim" id="auCartScrim"></div>
    <aside class="au-sheet" id="auCartSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Cart') }}" aria-hidden="true" inert>
        <div class="au-sheet__head">
            <strong class="au-sheet__title" id="auCartTitle" tabindex="-1">{{ ___('Cart') }}</strong>
            <button type="button" class="au-iconbtn" id="auCartClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="au-sheet__body">
            <div id="auCartView">
                <div id="auCartLines"></div>
                <p class="au-empty" id="auCartEmpty">{{ ___('Your cart is empty.') }}</p>
                <div class="au-totalrow au-totalrow--stack" id="auCartTotalRow" hidden>
                    <div class="au-trow"><span>{{ ___('Subtotal') }}</span><span id="auCartSubtotal"></span></div>
                    <div class="au-trow" id="auCartTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="auCartTax"></span></div>
                    <div class="au-trow au-trow--total"><span>{{ ___('Total') }}</span><span id="auCartTotal"></span></div>
                </div>
            </div>

            <form id="auCheckoutForm" hidden>
                <div id="auCheckoutMsg"></div>
                <div class="au-field" style="margin-bottom:10px">
                    <label for="auCkName">{{ ___('Name') }}</label>
                    <input type="text" id="auCkName" maxlength="255">
                </div>
                <div class="au-field" style="margin-bottom:10px">
                    <label for="auCkPhone">{{ ___('Phone') }}</label>
                    <input type="tel" id="auCkPhone" maxlength="25">
                </div>
                @if($modes->count() > 1)
                <div class="au-group">
                    <div class="au-group__label">{{ ___('Fulfillment') }}</div>
                    <div class="au-modes" id="auCkModes">
                        @foreach($modes as $mode)
                            <button type="button" class="au-chip{{ $mode['value'] === $defaultModeValue ? ' is-active' : '' }}"
                                    data-mode="{{ $mode['value'] }}"
                                    data-needs-address="{{ $mode['needsAddress'] ? 1 : 0 }}">{{ $mode['label'] }}</button>
                        @endforeach
                    </div>
                </div>
                @endif
                <div class="au-field" id="auCkAddressField" hidden style="margin-bottom:10px">
                    <label for="auCkAddress">{{ ___('Address') }}</label>
                    <div class="au-addr">
                        <input type="text" id="auCkAddress" maxlength="255" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="{{ ___('Start typing your address…') }}">
                        <button type="button" class="au-addr-loc" id="auAddrLoc" aria-label="{{ ___('Use my location') }}" title="{{ ___('Use my location') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                        </button>
                        <div class="au-addr-suggest" id="auAddrSuggest" hidden></div>
                    </div>
                    {{-- Drop-a-pin picker (shared window.mbMapPicker engine, aurelia-skinned .au-sheet drawer). --}}
                    <button type="button" class="au-maplink" id="auMapBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.4"/></svg>
                        {{ ___('Choose on map') }}
                    </button>
                </div>

                <label class="au-charge" id="auChargeRow">
                    <input type="checkbox" id="auChargeToggle">
                    <span><b>{{ ___('Charge to my room') }}</b><small>{{ ___('Add this order to your folio, settle at checkout.') }}</small></span>
                </label>
                <div class="au-field" id="auChargeNameField" hidden style="margin-bottom:10px">
                    <label for="auChargeNameInput">{{ ___('Name on the reservation') }}</label>
                    <input type="text" id="auChargeNameInput" maxlength="120" placeholder="{{ ___('Name on the reservation') }}">
                </div>

                {{-- Promo code + gift card — applied server-side via OrderPricing. --}}
                <div class="au-couponbox">
                    <div class="au-couponrow">
                        <input type="text" class="au-coupon-input" id="auCouponInput" autocomplete="off" placeholder="{{ ___('Promo code') }}" aria-label="{{ ___('Promo code') }}">
                        <button type="button" class="au-btn au-btn--ghost au-coupon-apply" id="auCouponApply">{{ ___('Apply') }}</button>
                    </div>
                    <p class="au-coupon-msg" id="auCouponMsg" hidden></p>
                    <input type="hidden" name="coupon_code" id="auCouponField" value="">
                    <input type="text" class="au-coupon-input" id="auGiftCardInput" name="gift_card_code" autocomplete="off" placeholder="{{ ___('Gift card code') }}" aria-label="{{ ___('Gift card code') }}" style="margin-top:8px">
                </div>

                <button type="button" class="au-btn au-btn--primary au-btn--block" id="auPlaceOrder">{{ ___('Place order') }}</button>
            </form>
        </div>
        <div class="au-sheet__foot" id="auCartFoot">
            <button type="button" class="au-btn au-btn--primary au-btn--block" id="auCheckoutBtn" disabled>{{ ___('Checkout') }}</button>
        </div>
    </aside>

    {{-- ---------- address map picker — aurelia-skinned .au-sheet drawer driven by
         the shared window.mbMapPicker engine. Opened from the checkout address
         field; sits above the cart drawer via the .au-mapsheet z-index bump. ---------- --}}
    <div class="au-sheet-scrim au-mapsheet-scrim" id="auMapScrim"></div>
    <aside class="au-sheet au-mapsheet" id="auMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" aria-hidden="true" inert>
        <div class="au-sheet__head">
            <strong class="au-sheet__title" id="auMapTitle" tabindex="-1">{{ ___('Choose on map') }}</strong>
            <button type="button" class="au-iconbtn" id="auMapClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="au-sheet__body au-mapsheet__body">
            <div class="au-map-canvas" id="auMap"></div>
            <p class="au-map-preview" id="auMapPreview">{{ ___('Move the map to place the pin') }}</p>
        </div>
        <div class="au-sheet__foot">
            <button type="button" class="au-btn au-btn--primary au-btn--block" id="auMapConfirm">{{ ___('Use this location') }}</button>
        </div>
    </aside>

    {{-- ---------- stories: full-screen viewer overlay ---------- --}}
    <div class="au-storyviewer" id="auStoryViewer" hidden role="dialog" aria-modal="true" aria-label="{{ ___('Stories') }}">
        <div class="au-sv-bars" id="auSvBars"></div>
        <div class="au-sv-top">
            <div class="au-sv-meta">
                <span class="au-sv-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($store->title, 0, 1)) }}</span>
                <span class="au-sv-name">{{ $store->title }}</span>
            </div>
            <button type="button" class="au-sv-close" id="auSvClose" aria-label="{{ ___('Close') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="au-sv-stage">
            <img class="au-sv-media" id="auSvMedia" alt="" data-csp-error="hide">
            <div class="au-sv-caption" id="auSvCaption" hidden></div>
        </div>
        <div class="au-sv-foot">
            <a class="au-btn au-btn--primary au-btn--block au-sv-cta" id="auSvCta" href="#" hidden></a>
        </div>
    </div>

    <div class="au-toast" id="auToast" role="status" aria-live="polite"></div>

    {{-- Real notification centre — header bell forwards to a hidden proxy. --}}
    <button type="button" id="mbNotifBell" class="au-nb-proxy" aria-hidden="true" tabindex="-1"></button>
    <i id="mbNotifDot" hidden></i>
    @include('store_templates._partials.notif-bell')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var proxy = document.getElementById('mbNotifBell');
            var srcDot = document.getElementById('mbNotifDot');
            var bell = document.getElementById('auNotifBell');
            if (bell) { bell.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (proxy && window.mbNotify) { proxy.click(); } }); }
            if (srcDot) {
                var mirror = function () { var d = bell && bell.querySelector('.au-notif-dot'); if (d) { d.hidden = srcDot.hidden; } };
                new MutationObserver(mirror).observe(srcDot, { attributes: true, attributeFilter: ['hidden'] });
                mirror();
            }
            var shareBtn = document.getElementById('auShareBtn');
            if (shareBtn) { shareBtn.addEventListener('click', function () { var url = shareBtn.getAttribute('data-share-url') || location.href; var title = shareBtn.getAttribute('data-share-title') || document.title; if (navigator.share) { navigator.share({ title: title, url: url }).catch(function () {}); } else if (navigator.clipboard) { navigator.clipboard.writeText(url).catch(function () {}); } }); }
        })();
    </script>

@if(($postOptions->customer_auth_mode ?? '') !== 'demo')
    @include('store_templates._partials.mb-auth-sheet', ['store' => $store])
    @if(auth('customer')->check() && \App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
        @include('store_templates._partials.mb-wallet-sheet', ['store' => $store])
    @endif
    @include('store_templates._partials.mb-refund', ['store' => $store])
    <style nonce="{{ csp_nonce() }}">
        .mba-sheet{--mba-surface:var(--au-surface);--mba-ink:var(--au-ink);--mba-muted:var(--au-muted);
            --mba-border:var(--au-line);--mba-hair:var(--au-line);--mba-in-bg:var(--au-surface);
            --mba-accent:var(--au-accent);--mba-on-accent:#fff;--mba-accent-ring:var(--au-line);--mba-radius:14px}
    </style>
    <script nonce="{{ csp_nonce() }}">
    (function () {
        ['auRwSigninHint', 'auBookingLoyaltyHint', 'auOrdersGuest'].forEach(function (id) {
            var e = document.getElementById(id);
            if (e) { e.setAttribute('data-mb-auth-open', ''); e.style.cursor = 'pointer'; }
        });
        var CSRF = @json(csrf_token());
        document.querySelectorAll('[data-mb-logout]').forEach(function (b) {
            b.addEventListener('click', function () {
                fetch(b.getAttribute('data-logout-url'), { method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function () { location.reload(); }).catch(function () { location.reload(); });
            });
        });
    })();
    </script>
@endif
</div>
@endsection

@push('style')
<style nonce="{{ csp_nonce() }}">
    /* Address map picker + clear-saved-data (shared engines, aurelia-skinned) */
    .au-maplink{display:inline-flex;align-items:center;gap:7px;margin-block-start:9px;padding:0;border:0;background:none;
        font:inherit;font-size:.82rem;font-weight:600;letter-spacing:.01em;color:var(--au-accent-text);cursor:pointer}
    .au-maplink svg{width:16px;height:16px}
    .au-maplink:hover{text-decoration:underline;text-underline-offset:3px}
    /* Sit above the cart drawer it is opened from (cart scrim/sheet = 19/20). */
    .au-mapsheet-scrim{z-index:21}
    .au-mapsheet{z-index:22}
    .au-mapsheet__body{display:flex;flex-direction:column;padding:0;overflow:hidden}
    .au-map-canvas{position:relative;flex:1;min-height:min(52vh,360px);background:var(--au-surface-2)}
    .au-map-canvas .leaflet-container{width:100%;height:100%;font:inherit;background:var(--au-surface-2)}
    .au-map-preview{margin:0;padding:13px 16px;font-size:.82rem;line-height:1.45;color:var(--au-muted);
        border-block-start:1px solid var(--au-line);text-align:center;min-height:20px}
    /* Teardrop pin dropped into the map centre (styled by the theme; engine injects .mb-map-pin). */
    .mb-map-pin{position:absolute;left:50%;top:50%;width:28px;height:36px;transform:translate(-50%,-100%);
        z-index:500;pointer-events:none;filter:drop-shadow(0 4px 6px rgba(30,22,8,.4))}
    .mb-map-pin::before{content:"";position:absolute;left:50%;top:0;transform:translateX(-50%) rotate(45deg);
        width:24px;height:24px;border-radius:50% 50% 50% 0;background:var(--au-accent);border:2px solid var(--au-surface)}
    .mb-map-pin::after{content:"";position:absolute;left:50%;top:7px;transform:translateX(-50%);width:8px;height:8px;border-radius:50%;background:var(--au-surface)}
    /* Clear-saved-data row: ghost button tinted with the theme's danger token + a muted hint. */
    .au-cleardata{gap:8px;color:var(--au-danger)}
    .au-cleardata svg{width:17px;height:17px}
    .au-cleardata-hint{margin:7px 0 0;font-size:.76rem;line-height:1.45;color:var(--au-muted);text-align:center}
</style>
@endpush

@push('script')
{{-- Shared map picker + clear-device-data engines. aurelia is a custom layout, so
     it @includes these itself (they are NOT auto-wired by store_templates/layout);
     the theme supplies its own aurelia-skinned UI + wiring below. --}}
@include('store_templates._partials.map-picker')
@include('store_templates._partials.clear-device-data')
@include('store_templates._partials.guest-orders')
{{-- Delivery-address autocomplete engine (window.mbAddrAutocomplete). --}}
@include('store_templates._partials.address-autocomplete')
@php($auLookupFound = ___('Found it — :room, :dates', ['room' => '__ROOM__', 'dates' => '__DATES__']))
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var ITEMS = @json($jsItems);
    var MODES = @json($modes);
    var DEFAULT_MODE = @json($defaultModeValue);
    var CHECKOUT_ROUTE = @json($checkoutRoute);
    var ROOM_KEY = @json($auRoom ? (string) $auRoom->key : ''); // the scanned room QR key (charge-to-room names its room by key)
    var CART_QUOTE_ROUTE = @json(route('store.cartQuote', $store->slug));
    var COUPON_ROUTE = @json(\Illuminate\Support\Facades\Route::has('store.applyCoupon') ? route('store.applyCoupon', $store->id) : null);
    var MY_ORDERS_ROUTE = @json(\Illuminate\Support\Facades\Route::has('publicView.myOrders.index') ? route('publicView.myOrders.index', $store->id) : null);
    (function () {
        var list = document.getElementById('auOrdersList');
        if (!list || !MY_ORDERS_ROUTE) { return; }
        if (@json(! auth('customer')->check())) {
            // Device-local guest history: a guest (no account) never gets the
            // server list, so show THIS device's recent orders (Track + optional
            // Reorder) plus a sign-in note to sync. Falls back to the existing
            // sign-in prompt when there is nothing on this device.
            var guestStoreId = (document.getElementById('aurelia') || document.createElement('div')).getAttribute('data-store-id') || '';
            var localOrders = (window.mbGuestOrders ? window.mbGuestOrders.list(guestStoreId) : []);
            var g = document.getElementById('auOrdersGuest');
            if (!localOrders.length) { if (g) { g.hidden = false; } return; }
            list.innerHTML = localOrders.map(function (o) {
                var od = o.at ? new Date(o.at) : null;
                var owhen = od ? od.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                var canReorder = (o.itemIds || []).some(function (id) { return ITEMS[String(id)]; });
                var acts = (o.trackUrl ? '<a class="au-order-btn" href="' + esc(o.trackUrl) + '">' + @json(___('Track')) + '</a>' : '') + (canReorder ? '<button type="button" class="au-order-btn au-order-btn--p" data-au-reorder="' + esc((o.itemIds || []).join(',')) + '">' + @json(___('Reorder')) + '</button>' : '');
                return '<div class="au-order"><div class="au-order-top"><b>' + @json(___('Recent order')) + '</b></div><div class="au-order-meta">' + esc(owhen) + (o.itemCount ? ' · ' + o.itemCount : '') + (o.total ? ' · ' + esc(o.total) : '') + '</div>' + (acts ? '<div class="au-order-actions">' + acts + '</div>' : '') + '</div>';
            }).join('') + '<div class="au-order au-order--signin"><div class="au-order-meta">' + @json(___('Sign in to sync your orders across devices.')) + '</div><button type="button" class="au-btn au-btn--primary au-btn--block au-guest-signin" data-mb-auth-open style="margin-top:8px">' + @json(___('Sign in')) + '</button></div>';
            list.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('[data-au-reorder]') : null;
                if (!b) { return; }
                var ids = (b.getAttribute('data-au-reorder') || '').split(',').filter(Boolean);
                var added = 0;
                ids.forEach(function (id) {
                    var item = ITEMS[String(id)];
                    if (!item || item.soldOut || (item.variants || []).length) { return; }
                    added++;
                    postAddToCart(String(id), null, 1, []).then(function (data) {
                        addToCart(String(id), null, 1, data.line_id, []);
                    }).catch(function () {});
                });
                if (added) { var co = document.getElementById('auCartOpen'); if (co) { co.click(); } }
            });
            return;
        }
        var LBL = { unpaid: @json(___('Awaiting payment')), pending: @json(___('Received')), new: @json(___('Received')), preparing: @json(___('Preparing')), ready: @json(___('Ready')), out_for_delivery: @json(___('On the way')), delivered: @json(___('Delivered')), completed: @json(___('Completed')), cancelled: @json(___('Cancelled')) };
        function lbl(s) { return LBL[s] || (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g, ' ') : ''); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        fetch(MY_ORDERS_ROUTE, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (r.status === 401) { throw 'guest'; } return r.json(); })
            .then(function (res) {
                var orders = (res && res.orders) || [];
                if (!orders.length) { document.getElementById('auOrdersEmpty').hidden = false; return; }
                list.innerHTML = orders.map(function (o) {
                    var d = o.at ? new Date(o.at) : null;
                    var when = d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                    return '<div class="au-order"><div class="au-order-top"><b>' + esc(o.number) + '</b><span class="au-order-status">' + esc(lbl(o.status)) + '</span></div><div class="au-order-meta">' + esc(when) + ' · ' + o.itemCount + ' · ' + esc(o.total) + '</div>' + (o.trackUrl ? '<a class="au-order-track" href="' + esc(o.trackUrl) + '">' + @json(___('Track')) + '</a>' : '') + (o.canRequestRefund ? '<button type="button" class="au-order-track" data-refund-request="' + esc(o.id) + '">' + @json(___('Request refund')) + '</button>' : '') + (o.refundStatus ? '<span class="mb-refund mb-refund--' + esc(o.refundStatus) + '">' + mbRefundLabel(o.refundStatus, o.refundMethod) + '</span>' : '') + '</div>';
                }).join('');
            })
            .catch(function (e) { if (e === 'guest') { document.getElementById('auOrdersGuest').hidden = false; } });
    })();
    var CART_LINE_ROUTE_TEMPLATE = @json($cartLineRouteTemplate);
    var AVAIL_ROUTE = @json($auAvailRoute);
    var BOOK_ROUTE = @json($auBookRoute);
    var SAMPLE_ROOMS = @json($auSampleRooms);
    var STORIES_ROUTE = @json($auStoriesRoute);
    var MBR_BASE = @json($auMbrBase);
    var LOYALTY_BALANCE_ROUTE = @json($auLoyaltyBalanceRoute);
    var CSRF = @json(csrf_token());
    var CUR = {
        sign: @json($curSign),
        gap: @json($curGap),
        pos: @json($curPos),
        decimals: @json($curDecimals),
    };
    // Mirrors Order::computeTax() exactly — client estimate of the same tax the
    // store charges, never a fabricated fee. 0 when no tax is configured.
    var TAX = { charge: @json($taxCharge), type: @json($taxType) };
    function computeTax(subtotal) {
        if (TAX.charge <= 0 || subtotal <= 0) { return 0; }
        var raw = TAX.type === 'fixed' ? TAX.charge : subtotal * (TAX.charge / 100);
        var f = Math.pow(10, CUR.decimals);
        return Math.round(raw * f) / f;
    }
    var T = {
        addToCart: @json($vocab['add_to_cart'] ?? ___('Add to cart')),
        outOfStock: @json(___('Sold out')),
        cartEmpty: @json(___('Your cart is empty.')),
        placing: @json(___('Placing order…')),
        genericError: @json(___('Something went wrong. Please try again.')),
        nameRequired: @json(___('Please enter your name.')),
        phoneRequired: @json(___('Please enter your phone number.')),
        emailRequired: @json(___('Please enter your email.')),
        addressRequired: @json(___('Please enter a delivery address.')),
        night: @json(___('night')),
        nights: @json(___('nights')),
        guest: @json(___('guest')),
        guestsLbl: @json(___('guests')),
        available: @json(___('Available')),
        onlyLeft: @json(___('Only :count left')),
        soldOut: @json(___('Sold out')),
        sampleTag: @json(___('Sample')),
        confirmedTitle: @json(___('Reservation received')),
        confirmedSub: @json(___('A confirmation has been sent. Present your code at check-in.')),
        demoTitle: @json(___('Demo reservation')),
        demoSub: @json(___('This is a sample confirmation — no reservation was created.')),
        demoNote: @json(___('Connect the booking engine (hotel.rooms.book) to accept real reservations.')),
        bookingFailed: @json(___('We could not confirm that reservation. Please try again.')),
        lookupNone: @json(___('We can look up reservations made from this device. From another device, use the confirmation link sent to you, or contact the front desk.')),
        lookupFound: @json($auLookupFound),
        savedProfile: @json(___('Saved.')),
        nightsCalc: @json(___(':nights nights × :rate')),
        storyFallback: @json(___('Story')),
        storyCtaFallback: @json(___('Learn more')),
        reviewThanks: @json(___('Thank you! Your review will appear once approved.')),
        reviewFailed: @json(___('Could not send your review. Please try again.')),
        chooseRatingFirst: @json(___('Please choose a star rating.')),
        anonymousReviewer: @json(___('Guest')),
        today: @json(___('Today')),
        yesterday: @json(___('Yesterday')),
        daysAgo: @json(___(':count days ago')),
        loyaltyPoints: @json(___('points')),
        loyaltyStamps: @json(___('stamps')),
        loyaltyApply: @json(___('Apply :amount off')),
        savedToWishlist: @json(___('Saved to your wishlist')),
        removedFromWishlist: @json(___('Removed from your wishlist')),
        addFav: @json(___('Save')),
        removeFav: @json(___('Remove from saved')),
    };

    var root = document.getElementById('aurelia');
    if (!root) { return; }
    var STORE_ID = root.getAttribute('data-store-id') || '';

    /* ---------------- money + date helpers ---------------- */
    function fmtMoney(n) {
        var amount = Number(n) || 0;
        var str;
        try {
            str = amount.toLocaleString(document.documentElement.lang || undefined, {
                minimumFractionDigits: CUR.decimals, maximumFractionDigits: CUR.decimals,
            });
        } catch (e) { str = amount.toFixed(CUR.decimals); }
        return '⁦' + (CUR.pos === '1' ? (CUR.sign + CUR.gap + str) : (str + CUR.gap + CUR.sign)) + '⁩';
    }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function toISO(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
    function parseISO(s) { var p = String(s || '').split('-'); return new Date(+p[0], (+p[1] || 1) - 1, +p[2] || 1); }
    function addDays(iso, n) { var d = parseISO(iso); d.setDate(d.getDate() + n); return toISO(d); }
    function diffNights(inIso, outIso) {
        var a = parseISO(inIso), b = parseISO(outIso);
        return Math.max(1, Math.round((b - a) / 86400000));
    }
    function humanDate(iso) {
        try {
            return parseISO(iso).toLocaleDateString(document.documentElement.lang || undefined, { month: 'short', day: 'numeric' });
        } catch (e) { return iso; }
    }

    /* ---------------- dark mode ---------------- */
    var darkKey = 'aurelia-theme-' + STORE_ID;
    try {
        var savedTheme = window.localStorage.getItem(darkKey);
        if (savedTheme) { document.documentElement.setAttribute('data-theme', savedTheme); }
    } catch (e) { /* storage may be unavailable — default (system) stands */ }
    var darkBtn = document.getElementById('auDarkToggle');
    if (darkBtn) {
        darkBtn.addEventListener('click', function () {
            var cur = document.documentElement.getAttribute('data-theme');
            var next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { window.localStorage.setItem(darkKey, next); } catch (e) { /* best-effort */ }
        });
    }

    /* ---------------- toast ---------------- */
    var toastTimer;
    function toast(msg) {
        var el = document.getElementById('auToast');
        if (!el) { return; }
        el.textContent = msg;
        el.classList.add('is-show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('is-show'); }, 2000);
    }

    /* ---------------- screen navigation — every screen is a real,
       server-rendered <section data-screen="KEY">; JS only toggles [hidden]
       (freshly/bookla convention). Drill-down screens (room-detail, booking)
       hide the tab bar and show a back header instead. ---------------- */
    var screens = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-screen]'), function (el) {
        screens[el.getAttribute('data-screen')] = el;
    });
    var TOPLEVEL = { home: 1, rooms: 1, dining: 1, spa: 1, stay: 1 };
    var navEl = document.getElementById('auNav');
    var state = { screen: 'home', stack: ['home'] };

    // ≥860px: move #auNav inside .au-topbar. Below 860px move it back to its
    // original root-level spot (a fixed bottom tabbar must not live inside the
    // header, whose backdrop-filter would trap it).
    (function () {
        if (!navEl) { return; }
        var bar = root.querySelector('.au-topbar');
        var brand = bar && bar.querySelector('.au-brand');
        var homeParent = navEl.parentNode, anchor = navEl.nextSibling;
        var mq = window.matchMedia('(min-width: 860px)');
        function place() {
            if (mq.matches) {
                if (bar && brand && navEl.parentNode !== bar) { brand.insertAdjacentElement('afterend', navEl); }
            } else if (navEl.parentNode !== homeParent) {
                homeParent.insertBefore(navEl, anchor);
            }
        }
        place();
        if (mq.addEventListener) { mq.addEventListener('change', place); } else if (mq.addListener) { mq.addListener(place); }
    })();

    function syncNav(key) {
        Array.prototype.forEach.call(root.querySelectorAll('.au-navbtn'), function (b) {
            b.setAttribute('aria-current', b.getAttribute('data-nav') === key ? 'true' : 'false');
        });
        if (navEl) { navEl.classList.toggle('is-drilldown', !TOPLEVEL[key]); }
    }
    function activate(key) {
        Object.keys(screens).forEach(function (k) { screens[k].hidden = (k !== key); });
        state.screen = key;
        // Remember the active screen for THIS tab so a same-tab reload (a language
        // switch is a full page navigation) lands back here, not on home — the
        // shopper's My Stay / order history no longer "looks gone".
        try { window.sessionStorage.setItem('mb-screen-' + STORE_ID, key); } catch (e) { /* best-effort */ }
        window.scrollTo(0, 0);
        syncNav(TOPLEVEL[key] ? key : state.lastTop || 'home');
        var focusTarget = screens[key] && screens[key].querySelector('h1, h2');
        if (focusTarget) {
            focusTarget.setAttribute('tabindex', '-1');
            try { focusTarget.focus({ preventScroll: true }); } catch (e) { focusTarget.focus(); }
        }
        onEnterScreen(key);
    }
    function go(key) {
        if (!key || !screens[key]) { return; }
        if (TOPLEVEL[key]) { state.lastTop = key; state.stack = [key]; }
        else { state.stack.push(key); }
        activate(key);
    }
    function back() {
        state.stack.pop();
        activate(state.stack[state.stack.length - 1] || 'home');
    }
    function onEnterScreen(key) {
        if (key === 'rooms') { renderRoomsGrid(); }
        else if (key === 'dining') { renderShopGrid('dining'); }
        else if (key === 'spa') { renderShopGrid('spa'); }
        else if (key === 'stay') { renderLookupProfile(); loadStayLoyalty(); }
        else if (key === 'saved') { renderSavedScreen(); }
    }
    Array.prototype.forEach.call(root.querySelectorAll('[data-nav]'), function (el) {
        el.addEventListener('click', function () { go(el.getAttribute('data-nav')); });
    });
    // Promo-banner CTA: an in-app "#screen" banner deep-links to its SPA screen
    // (an empty link_url would otherwise be a dead href="#").
    Array.prototype.forEach.call(root.querySelectorAll('.au-banner[data-banner-screen]'), function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            go(el.getAttribute('data-banner-screen'));
        });
    });
    document.getElementById('auRoomDetailBack').addEventListener('click', back);
    document.getElementById('auBookingBack').addEventListener('click', back);
    document.getElementById('auSavedBack').addEventListener('click', back);
    document.getElementById('auSavedOpen').addEventListener('click', function () { go('saved'); });

    /* ---------------- a11y sheet handling (Escape / scrim / focus trap,
       same convention as shelf/orbit — one tracked entry, since aurelia's
       sheets each carry their own full-screen scrim). ---------------- */
    var FOCUSABLE_SEL = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
    var openSheetEntry = null;
    function openSheet(sheetEl, scrimEl, focusEl) {
        var trigger = document.activeElement;
        openSheetEntry = { el: sheetEl, scrim: scrimEl, trigger: trigger };
        sheetEl.setAttribute('aria-hidden', 'false');
        sheetEl.removeAttribute('inert');
        scrimEl.classList.add('is-open');
        sheetEl.classList.add('is-open');
        setTimeout(function () { try { (focusEl || sheetEl).focus(); } catch (e) { /* best-effort */ } }, 60);
    }
    function closeSheet() {
        var entry = openSheetEntry;
        openSheetEntry = null;
        if (!entry) { return; }
        entry.el.classList.remove('is-open');
        if (entry.scrim) { entry.scrim.classList.remove('is-open'); }
        entry.el.setAttribute('aria-hidden', 'true');
        entry.el.setAttribute('inert', '');
        if (entry.trigger && typeof entry.trigger.focus === 'function') {
            try { entry.trigger.focus(); } catch (e) { /* best-effort */ }
        }
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && openSheetEntry) { closeSheet(); return; }
        if (e.key !== 'Tab' || !openSheetEntry) { return; }
        var focusables = Array.prototype.filter.call(
            openSheetEntry.el.querySelectorAll(FOCUSABLE_SEL),
            function (n) { return n.offsetParent !== null; }
        );
        if (!focusables.length) { return; }
        var first = focusables[0], last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    /* ================= search state (dates + guests) ================= */
    var today = toISO(new Date());
    var search = { checkIn: today, checkOut: addDays(today, 1), guests: 2 };

    function writeSearchUI() {
        ['auCheckIn', 'auCheckIn2'].forEach(function (id) { var n = document.getElementById(id); if (n) { n.value = search.checkIn; n.min = today; } });
        ['auCheckOut', 'auCheckOut2'].forEach(function (id) { var n = document.getElementById(id); if (n) { n.value = search.checkOut; n.min = addDays(search.checkIn, 1); } });
        ['auGuestsVal', 'auGuests2Val'].forEach(function (id) { var n = document.getElementById(id); if (n) { n.textContent = String(search.guests); } });
        var summary = document.getElementById('auRoomsSummaryText');
        if (summary) {
            var nights = diffNights(search.checkIn, search.checkOut);
            summary.innerHTML = '<b>' + humanDate(search.checkIn) + ' → ' + humanDate(search.checkOut) + '</b> · ' +
                nights + ' ' + (nights === 1 ? T.night : T.nights) + ' · ' + search.guests + ' ' + (search.guests === 1 ? T.guest : T.guestsLbl);
        }
    }
    function bindGuestStepper(minusId, plusId, onChange) {
        var minus = document.getElementById(minusId), plus = document.getElementById(plusId);
        if (minus) { minus.addEventListener('click', function () { search.guests = Math.max(1, search.guests - 1); onChange(); }); }
        if (plus) { plus.addEventListener('click', function () { search.guests = Math.min(10, search.guests + 1); onChange(); }); }
    }
    bindGuestStepper('auGuestsMinus', 'auGuestsPlus', writeSearchUI);
    bindGuestStepper('auGuests2Minus', 'auGuests2Plus', writeSearchUI);

    function bindDateInputs(inId, outId) {
        var inEl = document.getElementById(inId), outEl = document.getElementById(outId);
        if (inEl) {
            inEl.addEventListener('change', function () {
                search.checkIn = inEl.value || today;
                if (parseISO(search.checkOut) <= parseISO(search.checkIn)) { search.checkOut = addDays(search.checkIn, 1); }
                writeSearchUI();
            });
        }
        if (outEl) {
            outEl.addEventListener('change', function () {
                search.checkOut = outEl.value || addDays(search.checkIn, 1);
                if (parseISO(search.checkOut) <= parseISO(search.checkIn)) { search.checkOut = addDays(search.checkIn, 1); writeSearchUI(); }
            });
        }
    }
    bindDateInputs('auCheckIn', 'auCheckOut');
    bindDateInputs('auCheckIn2', 'auCheckOut2');

    document.getElementById('auSearchBtn').addEventListener('click', function () {
        go('rooms');
        loadRooms();
    });
    document.getElementById('auRoomsEditBtn').addEventListener('click', function () {
        writeSearchUI();
        openSheet(document.getElementById('auSearchSheet'), document.getElementById('auSearchScrim'), document.getElementById('auCheckIn2'));
    });
    document.getElementById('auSearchSheetClose').addEventListener('click', closeSheet);
    document.getElementById('auSearchScrim').addEventListener('click', closeSheet);
    document.getElementById('auSearchApply').addEventListener('click', function () {
        closeSheet();
        writeSearchUI();
        loadRooms();
    });

    /* ================= rooms: availability (real → sample degrade) ================= */
    var rooms = [];
    var roomsAreSample = true;

    function normalizeRealRoom(r) {
        return {
            id: r.id, real: true,
            name: r.name || '', bed: r.bed || '', occupancy: r.occupancy || 2,
            rate: Number(r.rate) || 0, rateFormatted: r.rate_formatted || null,
            available: (typeof r.available === 'number') ? r.available : null,
            image: r.image || '',
        };
    }
    function normalizeSampleRoom(r, idx) {
        var nights = diffNights(search.checkIn, search.checkOut);
        return {
            id: r.id, real: false,
            name: r.name, bed: r.bed, occupancy: r.occupancy, rate: r.rate, rateFormatted: null,
            available: Math.max(0, 5 - idx - Math.min(3, nights - 1)),
            image: r.image,
        };
    }
    function loadRooms() {
        var note = document.getElementById('auRoomsSampleNote');
        function useSample() {
            rooms = SAMPLE_ROOMS.map(normalizeSampleRoom);
            roomsAreSample = true;
            if (note) { note.hidden = false; }
            renderRoomsGrid();
            renderFeaturedRail();
        }
        if (!AVAIL_ROUTE) { useSample(); return; }
        var url = AVAIL_ROUTE + '?check_in=' + encodeURIComponent(search.checkIn) +
            '&check_out=' + encodeURIComponent(search.checkOut) + '&guests=' + encodeURIComponent(search.guests);
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('bad status'); } return r.json(); })
            .then(function (data) {
                if (!data || !Array.isArray(data.rooms) || !data.rooms.length) { throw new Error('empty'); }
                rooms = data.rooms.map(normalizeRealRoom);
                roomsAreSample = false;
                if (note) { note.hidden = true; }
                renderRoomsGrid();
                renderFeaturedRail();
            })
            .catch(function () { useSample(); });
    }

    // {cls, text} for an availability count, or null when the count is unknown
    // (a real endpoint may not report one). Shared by the room-card markup
    // string AND the persistent #auRoomDetailAvail element below, so updating
    // that element never has to reconstruct (and risk losing) its own id.
    function availInfo(n) {
        if (n === null || n === undefined) { return null; }
        if (n <= 0) { return { cls: 'is-sold', text: T.soldOut }; }
        if (n <= 3) { return { cls: 'is-low', text: T.onlyLeft.replace(':count', n) }; }
        return { cls: '', text: T.available };
    }
    function availBadge(n) {
        var info = availInfo(n);
        return info ? ('<span class="au-avail ' + info.cls + '">' + info.text + '</span>') : '';
    }
    function applyAvailBadge(el, n) {
        if (!el) { return; }
        var info = availInfo(n);
        el.className = 'au-avail' + (info && info.cls ? ' ' + info.cls : '');
        el.textContent = info ? info.text : '';
        el.hidden = !info;
    }
    function roomImageOrPh(room, cls) {
        if (room.image) { return '<img class="' + cls + '" src="' + room.image + '" alt="" loading="lazy" data-csp-error="hide">'; }
        return '<div class="au-room-card__ph" style="width:100%;height:100%"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 12h18M3 12V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5M3 12v6M21 12v6"/></svg></div>';
    }

    function renderRoomsGrid() {
        var grid = document.getElementById('auRoomsGrid');
        var empty = document.getElementById('auRoomsEmpty');
        if (!grid) { return; }
        grid.innerHTML = '';
        if (!rooms.length) { empty.hidden = false; return; }
        empty.hidden = true;
        rooms.forEach(function (room) {
            var card = document.createElement('article');
            card.className = 'au-room-card';
            card.innerHTML =
                '<div class="au-room-card__imgwrap">' + roomImageOrPh(room, 'au-room-card__img') + availBadge(room.available) + '</div>' +
                '<div class="au-room-card__body">' +
                    '<h3 class="au-room-card__name">' + escapeHtml(room.name) + '</h3>' +
                    '<div class="au-room-card__meta">' + escapeHtml(room.bed) + ' · ' + room.occupancy + ' ' + T.guestsLbl + '</div>' +
                    '<div class="au-room-card__foot">' +
                        '<span class="au-room-card__price">' + (room.rateFormatted || fmtMoney(room.rate)) + '<small>' + T.night + '</small></span>' +
                        '<button type="button" class="au-btn au-btn--primary au-btn--sm" ' + (room.available === 0 ? 'disabled' : '') + '>' + @json(___('View room')) + '</button>' +
                    '</div>' +
                '</div>';
            var btn = card.querySelector('button');
            if (btn) { btn.addEventListener('click', function () { openRoomDetail(room.id); }); }
            var imgwrap = card.querySelector('.au-room-card__imgwrap');
            imgwrap.addEventListener('click', function () { openRoomDetail(room.id); });
            imgwrap.appendChild(buildRoomHeart(room));
            grid.appendChild(card);
        });
    }

    function renderFeaturedRail() {
        var rail = document.getElementById('auFeaturedRail');
        if (!rail) { return; }
        rail.innerHTML = '';
        rooms.slice(0, 4).forEach(function (room) {
            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'au-rail-card';
            card.innerHTML =
                roomImageOrPh(room, '') +
                '<div class="au-rail-card__body">' +
                    '<p class="au-rail-card__name">' + escapeHtml(room.name) + '</p>' +
                    '<p class="au-rail-card__meta">' + escapeHtml(room.bed) + '</p>' +
                    '<p class="au-rail-card__price">' + (room.rateFormatted || fmtMoney(room.rate)) + ' <small>/ ' + T.night + '</small></p>' +
                '</div>';
            card.addEventListener('click', function () { go('rooms'); openRoomDetail(room.id); });
            rail.appendChild(card);
        });
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ================= wishlist (client-side, localStorage — no single-
       store backend for this, same convention every other themed pack in
       this loop uses). Rooms are snapshotted (id + display fields) since
       the `rooms` array only ever holds the CURRENT search's results —
       a saved room must still render on the Saved screen after the diner
       navigates away or changes dates. Dining/spa items just store the id:
       ITEMS is a static, server-rendered dictionary that never changes. ==== */
    var ROOM_FAV_KEY = 'aurelia-fav-rooms-' + STORE_ID;
    var roomFavs = {};
    try {
        var storedRoomFavs = JSON.parse(window.localStorage.getItem(ROOM_FAV_KEY) || '{}');
        if (storedRoomFavs && typeof storedRoomFavs === 'object') { roomFavs = storedRoomFavs; }
    } catch (e) { roomFavs = {}; }
    function saveRoomFavs() { try { window.localStorage.setItem(ROOM_FAV_KEY, JSON.stringify(roomFavs)); } catch (e) { /* best-effort */ } }
    function isRoomFav(id) { return !!roomFavs[id]; }
    function toggleRoomFav(room) {
        if (roomFavs[room.id]) {
            delete roomFavs[room.id];
            toast(T.removedFromWishlist);
        } else {
            roomFavs[room.id] = {
                id: room.id, name: room.name, bed: room.bed, occupancy: room.occupancy,
                rate: room.rate, rateFormatted: room.rateFormatted, image: room.image, real: room.real,
            };
            toast(T.savedToWishlist);
        }
        saveRoomFavs();
        refreshSavedBadge();
    }

    var ITEM_FAV_KEY = 'aurelia-fav-items-' + STORE_ID;
    var itemFavs = {};
    try {
        (JSON.parse(window.localStorage.getItem(ITEM_FAV_KEY) || '[]') || []).forEach(function (id) { itemFavs[id] = true; });
    } catch (e) { itemFavs = {}; }
    function saveItemFavs() { try { window.localStorage.setItem(ITEM_FAV_KEY, JSON.stringify(Object.keys(itemFavs))); } catch (e) { /* best-effort */ } }
    function isItemFav(id) { return !!itemFavs[id]; }
    function toggleItemFav(id) {
        if (itemFavs[id]) { delete itemFavs[id]; toast(T.removedFromWishlist); }
        else { itemFavs[id] = true; toast(T.savedToWishlist); }
        saveItemFavs();
        refreshSavedBadge();
    }

    function refreshSavedBadge() {
        var badge = document.getElementById('auSavedBadge');
        if (!badge) { return; }
        var n = Object.keys(roomFavs).length + Object.keys(itemFavs).length;
        badge.textContent = String(n);
        badge.hidden = n === 0;
    }
    function heartSvgPath() {
        return '<svg viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>';
    }
    function paintHeart(btn, isFav) {
        btn.classList.toggle('is-fav', isFav);
        btn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
        btn.setAttribute('aria-label', isFav ? T.removeFav : T.addFav);
    }
    function popHeart(btn) {
        btn.classList.add('au-pop');
        setTimeout(function () { btn.classList.remove('au-pop'); }, 400);
    }
    function buildRoomHeart(room) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'au-heart';
        btn.innerHTML = heartSvgPath();
        paintHeart(btn, isRoomFav(room.id));
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleRoomFav(room);
            paintHeart(btn, isRoomFav(room.id));
            popHeart(btn);
            if (state.screen === 'saved') { renderSavedRooms(); }
        });
        return btn;
    }
    function buildItemHeart(it) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'au-heart';
        btn.innerHTML = heartSvgPath();
        paintHeart(btn, isItemFav(it.id));
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleItemFav(it.id);
            paintHeart(btn, isItemFav(it.id));
            popHeart(btn);
            if (state.screen === 'saved') { renderSavedItems(); }
        });
        return btn;
    }

    function renderSavedRooms() {
        var grid = document.getElementById('auSavedRoomsGrid');
        var empty = document.getElementById('auSavedRoomsEmpty');
        if (!grid) { return; }
        var list = Object.keys(roomFavs).map(function (id) { return roomFavs[id]; });
        grid.innerHTML = '';
        if (!list.length) { empty.hidden = false; return; }
        empty.hidden = true;
        list.forEach(function (room) {
            var card = document.createElement('article');
            card.className = 'au-room-card';
            card.innerHTML =
                '<div class="au-room-card__imgwrap">' + roomImageOrPh(room, 'au-room-card__img') + '</div>' +
                '<div class="au-room-card__body">' +
                    '<h3 class="au-room-card__name">' + escapeHtml(room.name) + '</h3>' +
                    '<div class="au-room-card__meta">' + escapeHtml(room.bed || '') + ' · ' + room.occupancy + ' ' + T.guestsLbl + '</div>' +
                    '<div class="au-room-card__foot">' +
                        '<span class="au-room-card__price">' + (room.rateFormatted || fmtMoney(room.rate)) + '<small>' + T.night + '</small></span>' +
                    '</div>' +
                '</div>';
            card.querySelector('.au-room-card__imgwrap').appendChild(buildRoomHeart(room));
            card.addEventListener('click', function (e) { if (!e.target.closest('.au-heart')) { go('rooms'); } });
            grid.appendChild(card);
        });
    }
    function renderSavedItems() {
        var grid = document.getElementById('auSavedItemsGrid');
        var empty = document.getElementById('auSavedItemsEmpty');
        if (!grid) { return; }
        var list = Object.keys(itemFavs).map(function (id) { return ITEMS[id]; }).filter(Boolean);
        grid.innerHTML = '';
        if (!list.length) { empty.hidden = false; return; }
        empty.hidden = true;
        list.forEach(function (it) {
            var card = document.createElement('article');
            card.className = 'au-shop-card';
            card.innerHTML =
                '<div class="au-shop-card__imgwrap">' +
                    (it.soldOut ? '<span class="au-shop-card__soldout">' + T.outOfStock + '</span>' : '') +
                    (it.img
                        ? '<img class="au-shop-card__img" src="' + it.img + '" alt="" loading="lazy" data-csp-error="hide">'
                        : '<div class="au-shop-card__ph"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16v16H4z"/><path d="m4 15 4-4 4 4 4-5 4 4"/></svg></div>') +
                '</div>' +
                '<div class="au-shop-card__body">' +
                    '<span class="au-shop-card__name">' + escapeHtml(it.name) + '</span>' +
                    '<div class="au-shop-card__foot">' +
                        '<span class="au-shop-card__price">' + it.priceFormatted + '</span>' +
                    '</div>' +
                '</div>';
            card.querySelector('.au-shop-card__imgwrap').appendChild(buildItemHeart(it));
            card.addEventListener('click', function (e) { if (!e.target.closest('.au-heart')) { openItem(it.id); } });
            grid.appendChild(card);
        });
    }
    function renderSavedScreen() { renderSavedRooms(); renderSavedItems(); }

    /* ================= room detail + rate plans ================= */
    var currentRoom = null;
    var currentPlan = 'flexible';
    var GENERIC_DESC = @json(___('A refined retreat designed for comfort, with thoughtful amenities and warm hospitality throughout your stay.'));
    var SAMPLE_GALLERY_EXTRA = [
        'https://images.unsplash.com/photo-1445019980597-93fa8acb246c?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1571896349842-33c89424de2d?auto=format&fit=crop&w=800&q=80',
    ];

    function planPrice(room, plan, nights) {
        var base = room.rate * nights;
        return plan === 'non_refundable' ? Math.round(base * 0.9 * 100) / 100 : base;
    }
    function openRoomDetail(id) {
        currentRoom = rooms.filter(function (r) { return String(r.id) === String(id); })[0];
        if (!currentRoom) { return; }
        currentPlan = 'flexible';
        document.getElementById('auPlanFlexible').classList.add('is-active');
        document.getElementById('auPlanNonrefundable').classList.remove('is-active');
        document.querySelector('input[name="auPlan"][value="flexible"]').checked = true;

        document.getElementById('auRoomDetailTitle').textContent = currentRoom.name;
        document.getElementById('auRoomDetailName').textContent = currentRoom.name;
        document.getElementById('auRoomDetailMeta').textContent = currentRoom.bed + ' · ' + currentRoom.occupancy + ' ' + T.guestsLbl;
        applyAvailBadge(document.getElementById('auRoomDetailAvail'), currentRoom.available);
        paintHeart(document.getElementById('auRoomDetailFavBtn'), isRoomFav(currentRoom.id));
        document.getElementById('auRoomDetailDesc').textContent = GENERIC_DESC;

        var gallery = document.getElementById('auRoomGallery');
        gallery.innerHTML = roomImageOrPh(currentRoom, '');
        if (!currentRoom.real) {
            var strip = document.createElement('div');
            strip.className = 'au-gallery-strip';
            SAMPLE_GALLERY_EXTRA.forEach(function (src) {
                var img = document.createElement('img');
                img.src = src; img.alt = ''; img.loading = 'lazy'; img.setAttribute('data-csp-error', 'hide');
                strip.appendChild(img);
            });
            gallery.appendChild(strip);
        }

        updateRoomDetailTotals();
        go('room-detail');
    }
    function updateRoomDetailTotals() {
        if (!currentRoom) { return; }
        var nights = diffNights(search.checkIn, search.checkOut);
        var flexPrice = planPrice(currentRoom, 'flexible', nights);
        var nrPrice = planPrice(currentRoom, 'non_refundable', nights);
        document.getElementById('auPlanFlexiblePrice').textContent = fmtMoney(flexPrice);
        document.getElementById('auPlanNonrefundablePrice').textContent = fmtMoney(nrPrice);
        document.getElementById('auRoomDetailDates').textContent = humanDate(search.checkIn) + ' → ' + humanDate(search.checkOut);
        document.getElementById('auRoomDetailGuests').textContent = search.guests + ' ' + (search.guests === 1 ? T.guest : T.guestsLbl);
        document.getElementById('auRoomDetailNightsLabel').textContent = nights + ' ' + (nights === 1 ? T.night : T.nights) + ' × ' + fmtMoney(currentRoom.rate);
        var total = currentPlan === 'non_refundable' ? nrPrice : flexPrice;
        document.getElementById('auRoomDetailNightsCalc').textContent = fmtMoney(nights * currentRoom.rate);
        document.getElementById('auRoomDetailTotalInline').textContent = fmtMoney(total);
        document.getElementById('auRoomDetailTotal').innerHTML = '<small>' + @json(___('Total')) + '</small>' + fmtMoney(total);
    }
    Array.prototype.forEach.call(document.querySelectorAll('.au-plan'), function (label) {
        label.addEventListener('click', function () {
            currentPlan = label.getAttribute('data-plan');
            document.querySelectorAll('.au-plan').forEach(function (l) { l.classList.toggle('is-active', l === label); });
            var radio = label.querySelector('input'); if (radio) { radio.checked = true; }
            updateRoomDetailTotals();
        });
    });
    document.getElementById('auRoomDetailFavBtn').addEventListener('click', function () {
        if (!currentRoom) { return; }
        toggleRoomFav(currentRoom);
        paintHeart(this, isRoomFav(currentRoom.id));
        popHeart(this);
        renderRoomsGrid();
    });
    document.getElementById('auRoomDetailChangeDates').addEventListener('click', function () {
        writeSearchUI();
        openSheet(document.getElementById('auSearchSheet'), document.getElementById('auSearchScrim'), document.getElementById('auCheckIn2'));
    });
    var oldApply = document.getElementById('auSearchApply');
    oldApply.addEventListener('click', function () { if (currentRoom) { updateRoomDetailTotals(); } });

    /* ================= booking wizard ================= */
    var booking = null;
    document.getElementById('auReserveBtn').addEventListener('click', function () {
        if (!currentRoom) { return; }
        var nights = diffNights(search.checkIn, search.checkOut);
        booking = {
            room: currentRoom, plan: currentPlan, nights: nights,
            checkIn: search.checkIn, checkOut: search.checkOut, guests: search.guests,
            total: planPrice(currentRoom, currentPlan, nights),
        };
        try {
            var p = JSON.parse(window.localStorage.getItem('aurelia-profile-' + STORE_ID) || '{}');
            if (p.name) { document.getElementById('auGuestName').value = p.name; }
            if (p.phone) { document.getElementById('auGuestPhone').value = p.phone; }
        } catch (e) { /* best-effort */ }
        document.getElementById('auStepDetails').hidden = false;
        document.getElementById('auStepReview').hidden = true;
        document.getElementById('auStepConfirm').hidden = true;
        setStep('details');
        renderBookingRecap('auBookingRecap1', false);
        loadBookingLoyalty();
        go('booking');
    });

    /* ---------------- K3: loyalty redeem (booking step 1) ---------------- */
    var auBookingLoyToggle = document.getElementById('auBookingLoyaltyToggle');
    var auBookingLoyField = document.getElementById('auBookingLoyaltyRedeemField');
    if (auBookingLoyToggle && auBookingLoyField) {
        auBookingLoyToggle.addEventListener('change', function () {
            auBookingLoyField.value = auBookingLoyToggle.checked ? '1' : '0';
        });
    }
    var bookingLoyaltyChecked = false;
    function loadBookingLoyalty() {
        // Balance is auth:customer (guests get 401) — aurelia has no sign-in
        // flow of its own, so this is a best-effort upgrade for a diner who
        // is already signed in via another surface; a guest just keeps
        // seeing the public teaser + sign-in hint. Fetched once per app load.
        if (bookingLoyaltyChecked || !LOYALTY_BALANCE_ROUTE || !document.getElementById('auBookingLoyaltySect')) { return; }
        bookingLoyaltyChecked = true;
        fetch(LOYALTY_BALANCE_ROUTE, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                var hint = document.getElementById('auBookingLoyaltyHint');
                if (!data || !data.active) { return; }
                if (hint) { hint.hidden = true; }
                if (!data.ready_to_redeem || !data.reward_discount_display) { return; }
                var teaser = document.getElementById('auBookingLoyaltyTeaser');
                var box = document.getElementById('auBookingLoyaltyRedeemBox');
                if (teaser) { teaser.hidden = true; }
                if (box) {
                    box.hidden = false;
                    var unit = data.type === 'stamps' ? T.loyaltyStamps : T.loyaltyPoints;
                    document.getElementById('auBookingLoyOfferVal').textContent = T.loyaltyApply.replace(':amount', data.reward_discount_display);
                    document.getElementById('auBookingLoyBalanceLine').textContent = String(data.balance) + ' ' + unit + (data.tier ? ' · ' + data.tier : '');
                }
            })
            .catch(function () { /* best-effort — teaser stays as-is */ });
    }

    function setStep(step) {
        Array.prototype.forEach.call(document.querySelectorAll('#auBookingSteps .au-step'), function (el) {
            var s = el.getAttribute('data-step');
            el.classList.toggle('is-active', s === step);
            el.classList.toggle('is-done', (step === 'review' && s === 'details') || (step === 'confirm' && s !== 'confirm'));
        });
    }
    function renderBookingRecap(elId, full) {
        if (!booking) { return; }
        var el = document.getElementById(elId);
        var planLabel = booking.plan === 'non_refundable' ? @json(___('Non-refundable')) : @json(___('Flexible'));
        var html =
            '<div class="au-recap-row"><span class="au-muted-line">' + @json(___('Room')) + '</span><span>' + escapeHtml(booking.room.name) + '</span></div>' +
            '<div class="au-recap-row"><span class="au-muted-line">' + @json(___('Dates')) + '</span><span>' + humanDate(booking.checkIn) + ' → ' + humanDate(booking.checkOut) + '</span></div>' +
            '<div class="au-recap-row"><span class="au-muted-line">' + @json(___('Guests')) + '</span><span>' + booking.guests + '</span></div>' +
            '<div class="au-recap-row"><span class="au-muted-line">' + @json(___('Rate plan')) + '</span><span>' + planLabel + '</span></div>';
        if (full) {
            html += '<div class="au-recap-row"><span class="au-muted-line">' + @json(___('Guest')) + '</span><span>' + escapeHtml(document.getElementById('auGuestName').value) + '</span></div>';
        }
        html += '<div class="au-recap-row is-total"><span>' + @json(___('Total')) + '</span><span>' + fmtMoney(booking.total) + '</span></div>';
        el.innerHTML = html;
    }

    document.getElementById('auContinueReview').addEventListener('click', function () {
        var msg = document.getElementById('auDetailsMsg');
        msg.innerHTML = '';
        var name = document.getElementById('auGuestName').value.trim();
        var phone = document.getElementById('auGuestPhone').value.trim();
        var email = document.getElementById('auGuestEmail').value.trim();
        if (!name) { msg.innerHTML = '<div class="au-msg au-msg--error">' + T.nameRequired + '</div>'; return; }
        if (!phone) { msg.innerHTML = '<div class="au-msg au-msg--error">' + T.phoneRequired + '</div>'; return; }
        if (!email) { msg.innerHTML = '<div class="au-msg au-msg--error">' + T.emailRequired + '</div>'; return; }
        document.getElementById('auStepDetails').hidden = true;
        document.getElementById('auStepReview').hidden = false;
        setStep('review');
        renderBookingRecap('auBookingRecap2', true);
    });
    document.getElementById('auEditDetails').addEventListener('click', function () {
        document.getElementById('auStepDetails').hidden = false;
        document.getElementById('auStepReview').hidden = true;
        setStep('details');
    });

    function saveLocalBooking(entry) {
        try {
            var key = 'aurelia-bookings-' + STORE_ID;
            var list = JSON.parse(window.localStorage.getItem(key) || '[]');
            list.unshift(entry);
            window.localStorage.setItem(key, JSON.stringify(list.slice(0, 20)));
        } catch (e) { /* best-effort */ }
    }
    function renderConfirm(kind, code, note, openUrl) {
        var card = document.getElementById('auConfirmCard');
        card.classList.toggle('is-demo', kind === 'demo');
        document.getElementById('auConfirmTitle').textContent = kind === 'demo' ? T.demoTitle : T.confirmedTitle;
        document.getElementById('auConfirmSub').textContent = kind === 'demo' ? T.demoSub : T.confirmedSub;
        document.getElementById('auConfirmCode').textContent = code;
        document.getElementById('auConfirmNote').textContent = kind === 'demo' ? T.demoNote : '';
        var link = document.getElementById('auConfirmOpenLink');
        if (openUrl) { link.href = openUrl; link.hidden = false; } else { link.hidden = true; }
        document.getElementById('auStepReview').hidden = true;
        document.getElementById('auStepConfirm').hidden = false;
        setStep('confirm');
    }
    document.getElementById('auConfirmBooking').addEventListener('click', function () {
        var errBox = document.getElementById('auBookingError');
        errBox.innerHTML = '';
        var name = document.getElementById('auGuestName').value.trim();
        var phone = document.getElementById('auGuestPhone').value.trim();
        var email = document.getElementById('auGuestEmail').value.trim();
        var notes = document.getElementById('auGuestNotes').value.trim();
        try { window.localStorage.setItem('aurelia-profile-' + STORE_ID, JSON.stringify({ name: name, phone: phone })); } catch (e) { /* best-effort */ }

        // A sample room, or no live booking endpoint yet, is an honest demo —
        // never a fabricated network "success". See file docblock.
        if (!booking.room.real || !BOOK_ROUTE) {
            var demoCode = 'DEMO-' + Math.random().toString(36).slice(2, 7).toUpperCase();
            saveLocalBooking({ code: demoCode, demo: true, room: booking.room.name, checkIn: booking.checkIn, checkOut: booking.checkOut });
            renderConfirm('demo', demoCode, null, null);
            return;
        }

        var btn = this;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('room_type_id', booking.room.id);
        fd.append('check_in', booking.checkIn);
        fd.append('check_out', booking.checkOut);
        fd.append('adults', booking.guests);
        fd.append('children', 0);
        fd.append('rate_plan', booking.plan);
        fd.append('guest_name', name);
        fd.append('guest_phone', phone);
        fd.append('guest_email', email);
        if (notes) { fd.append('notes', notes); }
        // K3: forward the loyalty redeem toggle — the server re-derives the
        // discount from the diner's real balance, no client amount is trusted.
        fd.append('loyalty_redeem', (auBookingLoyField && auBookingLoyField.value === '1') ? '1' : '0');

        fetch(BOOK_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (data) {
                btn.disabled = false;
                if (data && data.success) {
                    saveLocalBooking({ code: data.code || '', redirect: data.redirect || null, room: booking.room.name, checkIn: booking.checkIn, checkOut: booking.checkOut });
                    renderConfirm('real', data.code || '', null, data.redirect || null);
                } else {
                    errBox.innerHTML = '<div class="au-msg au-msg--error">' + ((data && data.message) || T.bookingFailed) + '</div>';
                }
            })
            .catch(function () {
                btn.disabled = false;
                errBox.innerHTML = '<div class="au-msg au-msg--error">' + T.genericError + '</div>';
            });
    });
    document.getElementById('auViewStayBtn').addEventListener('click', function () { go('stay'); });
    document.getElementById('auBookAnotherBtn').addEventListener('click', function () { go('rooms'); });

    /* ================= dining + spa: shared shop rendering ================= */
    var filters = {
        dining: { cat: 'all', query: '' },
        spa: { cat: 'all', query: '' },
    };
    function itemsForScope(scope) {
        return Object.keys(ITEMS).map(function (k) { return ITEMS[k]; }).filter(function (it) {
            return scope === 'spa' ? it.isAmenity : !it.isAmenity;
        });
    }
    function renderShopGrid(scope) {
        var grid = document.getElementById(scope === 'spa' ? 'auSpaGrid' : 'auDiningGrid');
        var noResults = document.getElementById(scope === 'spa' ? 'auSpaNoResults' : 'auDiningNoResults');
        if (!grid) { return; }
        var f = filters[scope];
        var list = itemsForScope(scope).filter(function (it) {
            var matchesCat = f.cat === 'all' || String(it.root) === String(f.cat);
            var matchesQuery = !f.query || it.name.toLowerCase().indexOf(f.query) !== -1;
            return matchesCat && matchesQuery;
        });
        grid.innerHTML = '';
        list.forEach(function (it) {
            var card = document.createElement('article');
            card.className = 'au-shop-card';
            card.innerHTML =
                '<div class="au-shop-card__imgwrap">' +
                    (it.soldOut ? '<span class="au-shop-card__soldout">' + T.outOfStock + '</span>' : '') +
                    (it.img
                        ? '<img class="au-shop-card__img" src="' + it.img + '" alt="" loading="lazy" data-csp-error="hide">'
                        : '<div class="au-shop-card__ph"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16v16H4z"/><path d="m4 15 4-4 4 4 4-5 4 4"/></svg></div>') +
                '</div>' +
                '<div class="au-shop-card__body">' +
                    '<span class="au-shop-card__name">' + escapeHtml(it.name) + '</span>' +
                    '<div class="au-shop-card__foot">' +
                        '<span class="au-shop-card__price">' + it.priceFormatted + '</span>' +
                        '<button type="button" class="au-add" ' + (it.soldOut ? 'disabled' : '') + '>' + (it.variants.length ? @json(___('Choose')) : T.addToCart) + '</button>' +
                    '</div>' +
                '</div>';
            var openIt = function () { openItem(it.id); };
            var imgwrap = card.querySelector('.au-shop-card__imgwrap');
            imgwrap.addEventListener('click', openIt);
            imgwrap.appendChild(buildItemHeart(it));
            card.querySelector('.au-shop-card__name').addEventListener('click', openIt);
            // Target the add/reserve button specifically — NOT card.querySelector('button'),
            // which returns the heart button appended to the imgwrap just above (first in
            // DOM order), leaving the real .au-add ("حجز") button unwired → a dead click.
            var btn = card.querySelector('.au-add');
            if (btn) { btn.addEventListener('click', openIt); }
            grid.appendChild(card);
        });
        if (noResults) { noResults.hidden = list.length !== 0; }
    }
    Array.prototype.forEach.call(document.querySelectorAll('.au-chip[data-scope]'), function (chip) {
        chip.addEventListener('click', function () {
            var scope = chip.getAttribute('data-scope');
            Array.prototype.forEach.call(document.querySelectorAll('.au-chip[data-scope="' + scope + '"]'), function (c) {
                c.classList.remove('is-active'); c.setAttribute('aria-selected', 'false');
            });
            chip.classList.add('is-active'); chip.setAttribute('aria-selected', 'true');
            filters[scope].cat = chip.getAttribute('data-cat');
            renderShopGrid(scope);
        });
    });
    var diningSearchEl = document.getElementById('auDiningSearch');
    if (diningSearchEl) { diningSearchEl.addEventListener('input', function () { filters.dining.query = diningSearchEl.value.trim().toLowerCase(); renderShopGrid('dining'); }); }
    var spaSearchEl = document.getElementById('auSpaSearch');
    if (spaSearchEl) { spaSearchEl.addEventListener('input', function () { filters.spa.query = spaSearchEl.value.trim().toLowerCase(); renderShopGrid('spa'); }); }

    /* ================= item detail sheet ================= */
    var currentItem = null, currentVariantId = null, currentQty = 1, selectedExtras = [];
    function currentVariant() {
        if (!currentItem || !currentVariantId) { return null; }
        return currentItem.variants.filter(function (v) { return v.id === currentVariantId; })[0] || null;
    }
    function currentExtrasTotal() {
        if (!currentItem || !currentItem.extras) { return 0; }
        return currentItem.extras.reduce(function (sum, ex) {
            return sum + (selectedExtras.indexOf(ex.id) !== -1 ? ex.price : 0);
        }, 0);
    }
    function currentUnitPrice() {
        var v = currentVariant();
        var base = v ? v.price : (currentItem ? currentItem.price : 0);
        return base + currentExtrasTotal();
    }
    function renderThumbs() {
        var wrap = document.getElementById('auItemThumbs');
        var imgs = (currentItem && currentItem.gallery) ? currentItem.gallery : [];
        wrap.innerHTML = '';
        if (imgs.length < 2) { wrap.hidden = true; return; }
        wrap.hidden = false;
        var mainImg = document.getElementById('auItemImg');
        imgs.forEach(function (src, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'au-thumb';
            b.setAttribute('aria-current', i === 0 ? 'true' : 'false');
            var im = document.createElement('img');
            im.src = src; im.alt = ''; im.setAttribute('data-csp-error', 'hide');
            b.appendChild(im);
            b.addEventListener('click', function () {
                mainImg.src = src; mainImg.hidden = false;
                Array.prototype.forEach.call(wrap.children, function (c) { c.setAttribute('aria-current', c === b ? 'true' : 'false'); });
            });
            wrap.appendChild(b);
        });
    }
    function renderAddons() {
        var grp = document.getElementById('auItemAddonsGroup');
        var wrap = document.getElementById('auItemAddons');
        var extras = (currentItem && currentItem.extras) ? currentItem.extras : [];
        wrap.innerHTML = '';
        if (!extras.length) { grp.hidden = true; return; }
        grp.hidden = false;
        extras.forEach(function (ex) {
            var label = document.createElement('label');
            label.className = 'au-addon';
            var cb = document.createElement('input');
            cb.type = 'checkbox'; cb.value = ex.id;
            cb.checked = selectedExtras.indexOf(ex.id) !== -1;
            cb.addEventListener('change', function () {
                if (cb.checked) { if (selectedExtras.indexOf(ex.id) === -1) { selectedExtras.push(ex.id); } }
                else { selectedExtras = selectedExtras.filter(function (x) { return x !== ex.id; }); }
                updateItemPrice();
            });
            var name = document.createElement('span');
            name.className = 'au-addon-name'; name.textContent = ex.title;
            var price = document.createElement('span');
            price.className = 'au-addon-price'; price.textContent = '+ ' + ex.priceFormatted;
            label.appendChild(cb); label.appendChild(name); label.appendChild(price);
            wrap.appendChild(label);
        });
    }
    function renderVariants() {
        var wrap = document.getElementById('auItemVariants');
        wrap.innerHTML = '';
        if (!currentItem.variants.length) { return; }
        var group = document.createElement('div');
        group.className = 'au-group';
        var label = document.createElement('div');
        label.className = 'au-group__label';
        label.textContent = @json(___('Choose an option'));
        var opts = document.createElement('div');
        opts.className = 'au-opts';
        currentItem.variants.forEach(function (v) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'au-opt' + (v.id === currentVariantId ? ' is-active' : '');
            btn.textContent = v.label + ' — ' + v.priceFormatted;
            btn.addEventListener('click', function () { currentVariantId = v.id; renderVariants(); updateItemPrice(); });
            opts.appendChild(btn);
        });
        group.appendChild(label); group.appendChild(opts); wrap.appendChild(group);
    }
    function updateItemPrice() {
        document.getElementById('auAddToCartPrice').textContent = fmtMoney(currentUnitPrice() * currentQty);
    }
    function openItem(id) {
        currentItem = ITEMS[id];
        if (!currentItem) { return; }
        currentVariantId = currentItem.variants.length ? currentItem.variants[0].id : null;
        currentQty = 1;
        selectedExtras = [];
        document.getElementById('auItemName').textContent = currentItem.name;
        document.getElementById('auItemDesc').textContent = currentItem.desc;
        var img = document.getElementById('auItemImg');
        var first = (currentItem.gallery && currentItem.gallery.length) ? currentItem.gallery[0] : currentItem.img;
        if (first) { img.src = first; img.hidden = false; } else { img.hidden = true; }
        document.getElementById('auItemQtyVal').textContent = '1';
        document.getElementById('auAddToCartLabel').textContent = T.addToCart;
        renderThumbs();
        renderVariants();
        renderAddons();
        updateItemPrice();
        var addBtn = document.getElementById('auAddToCart');
        addBtn.disabled = currentItem.soldOut;
        paintHeart(document.getElementById('auItemFavBtn'), isItemFav(currentItem.id));
        loadReviews(currentItem.id);
        openSheet(document.getElementById('auItemSheet'), document.getElementById('auItemScrim'), document.getElementById('auItemName'));
    }
    document.getElementById('auItemFavBtn').addEventListener('click', function () {
        if (!currentItem) { return; }
        toggleItemFav(currentItem.id);
        paintHeart(this, isItemFav(currentItem.id));
        popHeart(this);
        renderShopGrid(currentItem.isAmenity ? 'spa' : 'dining');
    });
    document.getElementById('auItemClose').addEventListener('click', closeSheet);
    document.getElementById('auItemScrim').addEventListener('click', closeSheet);
    document.getElementById('auItemQtyMinus').addEventListener('click', function () { currentQty = Math.max(1, currentQty - 1); document.getElementById('auItemQtyVal').textContent = String(currentQty); updateItemPrice(); });
    document.getElementById('auItemQtyPlus').addEventListener('click', function () { currentQty = Math.min(20, currentQty + 1); document.getElementById('auItemQtyVal').textContent = String(currentQty); updateItemPrice(); });

    /* ================= item reviews — dining + spa share this sheet =================
       GET/POST <mbrBase>/<menuId>/reviews (see file docblock for the exact
       contract). Fetched per-item on openItem(); the write form posts back
       to the same URL and re-fetches on success. */
    function relativeDay(iso) {
        try {
            var then = new Date(iso + 'T00:00:00');
            var days = Math.round((new Date(new Date().toDateString()) - then) / 86400000);
            if (days <= 0) { return T.today; }
            if (days === 1) { return T.yesterday; }
            return T.daysAgo.replace(':count', String(days));
        } catch (e) { return ''; }
    }
    function starsMarkup(n) {
        n = Math.max(0, Math.min(5, Math.round(Number(n) || 0)));
        var out = '';
        for (var i = 1; i <= 5; i++) { out += '<span class="au-star-ic' + (i <= n ? ' is-on' : '') + '">★</span>'; }
        return out;
    }
    var reviewStars = 0;
    var reviewStarsWrap = document.getElementById('auReviewStars');
    function paintReviewStars() {
        if (!reviewStarsWrap) { return; }
        Array.prototype.forEach.call(reviewStarsWrap.querySelectorAll('[data-star]'), function (b) {
            b.classList.toggle('is-on', Number(b.getAttribute('data-star')) <= reviewStars);
        });
    }
    if (reviewStarsWrap) {
        Array.prototype.forEach.call(reviewStarsWrap.querySelectorAll('[data-star]'), function (b) {
            b.addEventListener('click', function () { reviewStars = Number(b.getAttribute('data-star')); paintReviewStars(); });
        });
    }
    var reviewsWriteBtn = document.getElementById('auReviewsWriteBtn');
    var reviewForm = document.getElementById('auReviewForm');
    function resetReviewForm() {
        reviewStars = 0;
        paintReviewStars();
        if (reviewForm) {
            reviewForm.hidden = true;
            document.getElementById('auReviewName').value = '';
            document.getElementById('auReviewText').value = '';
            var msgEl = document.getElementById('auReviewMsg');
            if (msgEl) { msgEl.innerHTML = ''; }
        }
        if (reviewsWriteBtn) { reviewsWriteBtn.hidden = false; }
    }
    if (reviewsWriteBtn && reviewForm) {
        reviewsWriteBtn.addEventListener('click', function () {
            reviewForm.hidden = false;
            reviewsWriteBtn.hidden = true;
            var firstStar = reviewStarsWrap && reviewStarsWrap.querySelector('[data-star="1"]');
            if (firstStar) { firstStar.focus(); }
        });
    }
    var reviewCancelBtn = document.getElementById('auReviewCancelBtn');
    if (reviewCancelBtn) { reviewCancelBtn.addEventListener('click', function (e) { e.preventDefault(); resetReviewForm(); }); }
    if (reviewForm) {
        reviewForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var msgEl = document.getElementById('auReviewMsg');
            msgEl.innerHTML = '';
            if (!reviewStars) { msgEl.innerHTML = '<div class="au-msg au-msg--error">' + T.chooseRatingFirst + '</div>'; return; }
            if (!currentItem || !MBR_BASE) { return; }
            var submitBtn = document.getElementById('auReviewSubmitBtn');
            submitBtn.disabled = true;
            var forId = currentItem.id;
            fetch(MBR_BASE + '/' + forId + '/reviews', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({
                    rating: reviewStars,
                    text: document.getElementById('auReviewText').value.trim() || null,
                    name: document.getElementById('auReviewName').value.trim() || null,
                }),
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    submitBtn.disabled = false;
                    if (!data || !data.ok) { msgEl.innerHTML = '<div class="au-msg au-msg--error">' + T.reviewFailed + '</div>'; return; }
                    msgEl.innerHTML = '<div class="au-msg au-msg--ok">' + T.reviewThanks + '</div>';
                    setTimeout(function () {
                        resetReviewForm();
                        if (currentItem && currentItem.id === forId) { loadReviews(forId); }
                    }, 1600);
                })
                .catch(function () { submitBtn.disabled = false; msgEl.innerHTML = '<div class="au-msg au-msg--error">' + T.reviewFailed + '</div>'; });
        });
    }
    function loadReviews(id) {
        var listEl = document.getElementById('auReviewsList');
        var summaryEl = document.getElementById('auRvSummary');
        var emptyEl = document.getElementById('auReviewsEmpty');
        if (!listEl || !MBR_BASE) { return; }
        listEl.innerHTML = '';
        if (summaryEl) { summaryEl.hidden = true; }
        if (emptyEl) { emptyEl.hidden = true; }
        resetReviewForm();
        fetch(MBR_BASE + '/' + id + '/reviews?filter=latest', { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (payload) {
                if (!payload || !currentItem || currentItem.id !== id) { return; }
                var count = Number(payload.count || 0);
                if (count > 0 && summaryEl) {
                    summaryEl.hidden = false;
                    document.getElementById('auRvAvgNum').textContent = Number(payload.average || 0).toFixed(1);
                    document.getElementById('auRvAvgStars').innerHTML = starsMarkup(payload.average || 0);
                    document.getElementById('auRvAvgCount').textContent = '(' + count + ')';
                    var distEl = document.getElementById('auRvDist');
                    distEl.innerHTML = '';
                    [5, 4, 3, 2, 1].forEach(function (star) {
                        var c = Number((payload.distribution || {})[star] || 0);
                        var pct = count ? Math.round((c / count) * 100) : 0;
                        var row = document.createElement('div');
                        row.className = 'au-rv-bar';
                        var barTrack = document.createElement('i');
                        var barFill = document.createElement('s');
                        barFill.style.width = pct + '%';
                        barTrack.appendChild(barFill);
                        var starLabel = document.createElement('span');
                        starLabel.textContent = String(star);
                        var countLabel = document.createElement('b');
                        countLabel.textContent = String(c);
                        row.appendChild(starLabel); row.appendChild(barTrack); row.appendChild(countLabel);
                        distEl.appendChild(row);
                    });
                } else if (emptyEl) {
                    emptyEl.hidden = false;
                }
                (payload.data || []).forEach(function (rv) {
                    var card = document.createElement('div');
                    card.className = 'au-rv-item';
                    var top = document.createElement('div');
                    top.className = 'au-rv-item-top';
                    var starsSpan = document.createElement('span');
                    starsSpan.className = 'au-rv-stars';
                    starsSpan.innerHTML = starsMarkup(rv.stars);
                    var nameSpan = document.createElement('span');
                    nameSpan.className = 'au-rv-name';
                    nameSpan.textContent = rv.name || T.anonymousReviewer;
                    var dateSpan = document.createElement('span');
                    dateSpan.className = 'au-rv-date';
                    dateSpan.textContent = relativeDay(String(rv.created_at || '').slice(0, 10));
                    top.appendChild(starsSpan); top.appendChild(nameSpan); top.appendChild(dateSpan);
                    var body = document.createElement('p');
                    body.className = 'au-rv-body';
                    body.textContent = rv.body || '';
                    card.appendChild(top); card.appendChild(body);
                    listEl.appendChild(card);
                });
            })
            .catch(function () { /* best-effort — section stays hidden-empty */ });
    }

    /* ================= stories rail + full-screen viewer =================
       GET {{ url('r/'.$store->id.'/stories') }} → { data:[{id, media_url,
       caption, cta_label, cta_url, sort}] }. Ships hidden+empty in the
       markup so a slow/failed fetch never flashes a broken shell; the rail
       hides itself when the feed is empty. Viewed bubbles get a dimmed
       ring (localStorage), Instagram-style. */
    var STORY_VIEWED_KEY = 'aurelia-stories-viewed-' + STORE_ID;
    var storiesData = [];
    var storyIndex = 0;
    var storyTimer = null;
    var STORY_SEG_MS = 5000;
    function readViewedStories() {
        try { return JSON.parse(window.localStorage.getItem(STORY_VIEWED_KEY) || '[]') || []; } catch (e) { return []; }
    }
    function markStoryViewed(id) {
        var seen = readViewedStories();
        if (seen.indexOf(id) === -1) {
            seen.push(id);
            try { window.localStorage.setItem(STORY_VIEWED_KEY, JSON.stringify(seen)); } catch (e) { /* best-effort */ }
        }
    }
    var storiesRailEl = document.getElementById('auStoriesRail');
    function renderStoriesRail() {
        if (!storiesRailEl) { return; }
        if (!storiesData.length) { storiesRailEl.hidden = true; storiesRailEl.innerHTML = ''; return; }
        var viewed = readViewedStories();
        storiesRailEl.innerHTML = '';
        storiesData.forEach(function (s, i) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'au-story-item';
            btn.setAttribute('data-story-open', String(i));
            var isViewed = viewed.indexOf(s.id) !== -1;
            var thumb = s.media_url ? '<img src="' + s.media_url + '" alt="" data-csp-error="hide">' : '';
            btn.innerHTML =
                '<span class="au-story-ring' + (isViewed ? ' is-viewed' : '') + '"><span class="au-story-thumb">' + thumb + '</span></span>' +
                '<span class="au-story-label"></span>';
            btn.querySelector('.au-story-label').textContent = s.caption ? String(s.caption).slice(0, 16) : T.storyFallback;
            storiesRailEl.appendChild(btn);
        });
        storiesRailEl.hidden = false;
    }
    function fetchStories() {
        if (!STORIES_ROUTE) { return; }
        fetch(STORIES_ROUTE, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (payload) {
                var list = payload && Array.isArray(payload.data) ? payload.data : (Array.isArray(payload) ? payload : []);
                storiesData = list;
                renderStoriesRail();
            })
            .catch(function () { /* rail stays hidden — never an empty shell */ });
    }
    fetchStories();

    var storyViewerEl = document.getElementById('auStoryViewer');
    function stopStoryTimer() { if (storyTimer) { clearInterval(storyTimer); storyTimer = null; } }
    function closeStoryViewer() {
        stopStoryTimer();
        if (storyViewerEl) { storyViewerEl.hidden = true; }
        renderStoriesRail(); // repaint viewed rings
    }
    function paintStorySegments() {
        var bars = document.getElementById('auSvBars');
        if (!bars) { return; }
        bars.innerHTML = '';
        storiesData.forEach(function (s, i) {
            var seg = document.createElement('span');
            seg.className = 'au-sv-seg';
            var fill = document.createElement('i');
            fill.style.width = (i < storyIndex ? '100' : '0') + '%';
            seg.appendChild(fill);
            bars.appendChild(seg);
        });
    }
    function runStoryTimer() {
        stopStoryTimer();
        var bars = document.getElementById('auSvBars');
        var segEl = bars ? bars.children[storyIndex] : null;
        var fillEl = segEl ? segEl.querySelector('i') : null;
        var start = Date.now();
        storyTimer = setInterval(function () {
            var pct = Math.min(100, ((Date.now() - start) / STORY_SEG_MS) * 100);
            if (fillEl) { fillEl.style.width = pct + '%'; }
            if (pct >= 100) { nextStory(); }
        }, 60);
    }
    function paintStory() {
        var story = storiesData[storyIndex];
        if (!story) { closeStoryViewer(); return; }
        var media = document.getElementById('auSvMedia');
        var cap = document.getElementById('auSvCaption');
        var cta = document.getElementById('auSvCta');
        if (media) { media.src = story.media_url || ''; }
        if (cap) {
            if (story.caption) { cap.textContent = story.caption; cap.hidden = false; } else { cap.hidden = true; }
        }
        if (cta) {
            if (story.cta_url) {
                cta.hidden = false;
                cta.textContent = story.cta_label || T.storyCtaFallback;
                cta.setAttribute('href', story.cta_url);
                if (/^\//.test(story.cta_url)) { cta.removeAttribute('target'); cta.removeAttribute('rel'); }
                else { cta.setAttribute('target', '_blank'); cta.setAttribute('rel', 'noopener'); }
            } else {
                cta.hidden = true;
            }
        }
        markStoryViewed(story.id);
        paintStorySegments();
        runStoryTimer();
    }
    function nextStory() {
        if (storyIndex < storiesData.length - 1) { storyIndex += 1; paintStory(); }
        else { closeStoryViewer(); }
    }
    function prevStory() {
        storyIndex = storyIndex > 0 ? storyIndex - 1 : 0;
        paintStory();
    }
    function openStoryViewer(index) {
        if (!storiesData.length || !storyViewerEl) { return; }
        storyIndex = Math.max(0, Math.min(storiesData.length - 1, index));
        storyViewerEl.hidden = false;
        paintStory();
    }
    if (storiesRailEl) {
        storiesRailEl.addEventListener('click', function (e) {
            var b = e.target.closest('[data-story-open]');
            if (b) { openStoryViewer(Number(b.getAttribute('data-story-open'))); }
        });
    }
    if (storyViewerEl) {
        var svClose = document.getElementById('auSvClose');
        if (svClose) { svClose.addEventListener('click', closeStoryViewer); }
        // Story CTA: always close the fixed viewer first (otherwise it stays on
        // top and covers wherever the CTA points). A "#<screen>" target switches
        // the SPA screen in place; any real URL is left to navigate normally.
        var svCta = document.getElementById('auSvCta');
        if (svCta) {
            svCta.addEventListener('click', function (e) {
                var href = svCta.getAttribute('href') || '';
                var m = href.match(/^#(?:screen=)?([a-z-]+)$/i);
                closeStoryViewer();
                if (m && screens[m[1]]) {
                    e.preventDefault();
                    go(m[1]);
                }
            });
        }
        var svStage = storyViewerEl.querySelector('.au-sv-stage');
        if (svStage) {
            svStage.addEventListener('click', function (e) {
                if (e.target.closest('#auSvCta')) { return; }
                var rect = svStage.getBoundingClientRect();
                var x = e.clientX - rect.left;
                if (x < rect.width / 2) { prevStory(); } else { nextStory(); }
            });
            var svTouchX = null;
            svStage.addEventListener('touchstart', function (e) { svTouchX = e.changedTouches[0].clientX; }, { passive: true });
            svStage.addEventListener('touchend', function (e) {
                if (svTouchX === null) { return; }
                var dx = e.changedTouches[0].clientX - svTouchX;
                svTouchX = null;
                if (Math.abs(dx) < 40) { return; }
                if (dx > 0) { prevStory(); } else { nextStory(); }
            }, { passive: true });
        }
        document.addEventListener('keydown', function (e) {
            if (storyViewerEl.hidden) { return; }
            if (e.key === 'Escape') { closeStoryViewer(); }
            else if (e.key === 'ArrowLeft') { prevStory(); }
            else if (e.key === 'ArrowRight') { nextStory(); }
        });
    }

    /* ================= cart (client mirror of the real store-side goods
       cart — same convention shelf/freshly use) ================= */
    var CART_KEY = 'aurelia-cart-' + STORE_ID;
    var cart = [];
    try { var storedCart = window.localStorage.getItem(CART_KEY); if (storedCart) { cart = JSON.parse(storedCart) || []; } } catch (e) { cart = []; }
    function saveCart() { try { window.localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) { /* best-effort */ } }
    // Drop any stored line whose menu item no longer exists (removed / made
    // unavailable since it was added). cartCount() counts every line but
    // renderCart() skips missing items, so a stale line would light the cart
    // badge while the cart renders empty. Prune once on load so the badge,
    // the list and the totals always agree.
    (function () { var before = cart.length; cart = cart.filter(function (l) { return l && ITEMS[l.id]; }); if (cart.length !== before) { saveCart(); } })();
    /* Bug batch 7 · 4: the cart lines go to the coupon check (submitOrder's `items` shape) — a buy-X-get-Y coupon is
       priced on them, exactly as the placed order is. */
    function couponLines() {
        return cart.map(function (l) {
            return { id: l.id, quantity: l.qty, variants: l.variantId || 0, extras: (l.extras || []).map(function (x) { return { id: (x && x.id) || x }; }) };
        });
    }
    (function () {
        var applyBtn = document.getElementById('auCouponApply');
        if (!applyBtn || !COUPON_ROUTE) { return; }
        applyBtn.addEventListener('click', function () {
            var code = (document.getElementById('auCouponInput').value || '').trim();
            var msgEl = document.getElementById('auCouponMsg');
            var field = document.getElementById('auCouponField');
            if (!code) { return; }
            var sub = cart.reduce(function (s, l) { return s + lineTotal(l); }, 0);
            var fd = new FormData();
            fd.append('_token', CSRF); fd.append('code', code); fd.append('subtotal', sub);
            fd.append('items', JSON.stringify(couponLines())); fd.append('ordering-type', selectedMode.value || '');
            applyBtn.disabled = true;
            fetch(COUPON_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    applyBtn.disabled = false; msgEl.hidden = false;
                    if (d && d.success) { field.value = code; msgEl.textContent = (d.message || @json(___('Coupon applied'))) + ' · -' + (d.discount || ''); msgEl.classList.remove('is-bad'); }
                    else { field.value = ''; msgEl.textContent = (d && d.message) || @json(___('Coupon code is invalid.')); msgEl.classList.add('is-bad'); }
                    serverQuote();
                })
                .catch(function () { applyBtn.disabled = false; });
        });
    })();
    function extrasSig(ids) { return (ids || []).slice().sort(function (a, b) { return a - b; }).join(','); }
    function lineExtrasTotal(item, ids) {
        if (!item.extras || !ids || !ids.length) { return 0; }
        return item.extras.reduce(function (s, ex) { return s + (ids.indexOf(ex.id) !== -1 ? ex.price : 0); }, 0);
    }
    function lineExtrasLabel(item, ids) {
        if (!item.extras || !ids || !ids.length) { return ''; }
        return item.extras.filter(function (ex) { return ids.indexOf(ex.id) !== -1; }).map(function (ex) { return ex.title; }).join(', ');
    }
    function lineTotal(line) {
        var item = ITEMS[line.id]; if (!item) { return 0; }
        var unit = item.price;
        if (line.variantId) { var v = item.variants.filter(function (x) { return x.id === line.variantId; })[0]; if (v) { unit = v.price; } }
        return (unit + lineExtrasTotal(item, line.extras)) * line.qty;
    }
    function cartCount() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }
    function refreshCartBadges() {
        var n = cartCount();
        [document.getElementById('auCartBadge'), document.getElementById('auNavCartBadge')].forEach(function (b) {
            if (!b) { return; }
            b.textContent = String(n); b.hidden = n === 0;
        });
    }
    function renderCart() {
        var linesEl = document.getElementById('auCartLines');
        var emptyEl = document.getElementById('auCartEmpty');
        var totalRow = document.getElementById('auCartTotalRow');
        var totalEl = document.getElementById('auCartTotal');
        var subEl = document.getElementById('auCartSubtotal');
        var taxRow = document.getElementById('auCartTaxRow');
        var taxEl = document.getElementById('auCartTax');
        var checkoutBtn = document.getElementById('auCheckoutBtn');
        linesEl.innerHTML = '';
        if (!cart.length) {
            emptyEl.hidden = false; totalRow.hidden = true; checkoutBtn.disabled = true;
            refreshCartBadges(); return;
        }
        emptyEl.hidden = true; totalRow.hidden = false; checkoutBtn.disabled = false;
        var sum = 0;
        cart.forEach(function (line, idx) {
            var item = ITEMS[line.id]; if (!item) { return; }
            var lt = lineTotal(line); sum += lt;
            var variantLabel = '';
            if (line.variantId) { var v = item.variants.filter(function (x) { return x.id === line.variantId; })[0]; if (v) { variantLabel = v.label; } }
            var row = document.createElement('div');
            row.className = 'au-cart-line';
            var img = document.createElement('img'); img.alt = ''; img.setAttribute('data-csp-error', 'hide');
            if (item.img) { img.src = item.img; } else { img.hidden = true; }
            var body = document.createElement('div'); body.className = 'au-cart-line__body';
            var nameEl = document.createElement('div');
            var addonLabel = lineExtrasLabel(item, line.extras);
            nameEl.textContent = item.name + (variantLabel ? ' · ' + variantLabel : '') + (addonLabel ? ' · ' + addonLabel : '') + ' × ' + line.qty;
            var priceEl = document.createElement('div'); priceEl.style.fontWeight = '700'; priceEl.textContent = fmtMoney(lt);
            var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'au-cart-line__remove';
            rm.textContent = @json(___('Remove'));
            rm.addEventListener('click', function () {
                var removedLineId = line.lineId;
                cart.splice(idx, 1); saveCart(); renderCart();
                if (removedLineId) {
                    var fd = new FormData(); fd.append('_token', CSRF); fd.append('quantity', '0');
                    fetch(CART_LINE_ROUTE_TEMPLATE.replace('999999999', String(removedLineId)), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } }).then(function () { serverQuote(); }).catch(function () {});
                }
            });
            body.appendChild(nameEl); body.appendChild(priceEl); body.appendChild(rm);
            row.appendChild(img); row.appendChild(body); linesEl.appendChild(row);
        });
        var tax = computeTax(sum);
        if (subEl) { subEl.textContent = fmtMoney(sum); }
        if (taxRow) { taxRow.hidden = tax <= 0; }
        if (taxEl) { taxEl.textContent = fmtMoney(tax); }
        totalEl.textContent = fmtMoney(sum + tax);
        refreshCartBadges();
        serverQuote();
    }

    /* P3-13 (THEME-CONTRACT §5.2): the totals are the server quote of the goods cart the checkout will place (the
       chosen service mode and coupon); the sum above is only the estimate shown until it answers. */
    function serverQuote() {
        var box = document.getElementById('auCartTotalRow');
        if (!window.mbQuote || !box || !cart.length) { return; }
        var couponEl = document.getElementById('auCouponField');
        window.mbQuote.update('cart', CART_QUOTE_ROUTE, {
            _token: CSRF,
            type: selectedMode.value,
            coupon_code: couponEl ? couponEl.value : '',
            flow: 'goods'
        }, function (q) {
            var e = window.mbQuote.esc;
            var hasTax = false;
            var html = window.mbQuote.rows(q).map(function (r) {
                if (r.key === 'subtotal') { return '<div class="au-trow"><span>' + e(r.label) + '</span><span id="auCartSubtotal">' + e(r.formatted) + '</span></div>'; }
                if (r.key === 'tax') { hasTax = true; return '<div class="au-trow" id="auCartTaxRow"><span>' + e(r.label) + '</span><span id="auCartTax">' + e(r.formatted) + '</span></div>'; }
                return '<div class="au-trow"><span>' + e(r.label) + '</span><span>' + e(r.formatted) + '</span></div>';
            }).join('');
            if (!hasTax) { html += '<div class="au-trow" id="auCartTaxRow" hidden><span></span><span id="auCartTax"></span></div>'; }
            box.innerHTML = html + '<div class="au-trow au-trow--total"><span>' + e(q.total_label) + '</span><span id="auCartTotal">' + e(q.total.formatted) + '</span></div>';
        }, [box]);
    }
    function addToCart(itemId, variantId, qty, lineId, extras) {
        extras = extras || [];
        var sig = extrasSig(extras);
        var existing = cart.filter(function (l) { return l.id === itemId && l.variantId === (variantId || null) && extrasSig(l.extras) === sig; })[0];
        if (existing) { existing.qty += qty; if (lineId) { existing.lineId = lineId; } }
        else { cart.push({ id: itemId, variantId: variantId || null, qty: qty, lineId: lineId || null, extras: extras }); }
        saveCart(); renderCart();
    }
    function postAddToCart(itemId, variantId, qty, extras) {
        var item = ITEMS[itemId];
        var fd = new FormData();
        fd.append('_token', CSRF);
        if (variantId) { fd.append('variation', variantId); }
        fd.append('quantity', qty);
        (extras || []).forEach(function (id) { fd.append('extras[]', id); });
        return fetch(item.addRoute, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (data) {
                if (!data || !data.success) { throw new Error((data && data.message) || 'add-to-cart failed'); }
                return data;
            });
    }
    renderCart();

    document.getElementById('auAddToCart').addEventListener('click', function () {
        if (!currentItem || currentItem.soldOut) { return; }
        var vId = currentVariantId, qty = currentQty, ex = selectedExtras.slice();
        postAddToCart(currentItem.id, vId, qty, ex)
            .then(function (data) { addToCart(currentItem.id, vId, qty, data.line_id, ex); toast(@json(___('Added to cart.'))); closeSheet(); })
            .catch(function () { toast(T.genericError); });
    });

    document.getElementById('auCartOpen').addEventListener('click', function () {
        document.getElementById('auCartView').hidden = false;
        document.getElementById('auCheckoutForm').hidden = true;
        document.getElementById('auCartFoot').hidden = false;
        renderCart();
        openSheet(document.getElementById('auCartSheet'), document.getElementById('auCartScrim'), document.getElementById('auCartTitle'));
    });
    document.getElementById('auCartClose').addEventListener('click', closeSheet);
    document.getElementById('auCartScrim').addEventListener('click', closeSheet);

    /* ---------------- checkout (+ charge-to-room tender) ---------------- */
    var selectedMode = { value: DEFAULT_MODE, needsAddress: false };
    (MODES || []).forEach(function (m) { if (m.value === DEFAULT_MODE) { selectedMode = { value: m.value, needsAddress: m.needsAddress }; } });
    Array.prototype.forEach.call(document.querySelectorAll('#auCkModes .au-chip'), function (chip) {
        chip.addEventListener('click', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#auCkModes .au-chip'), function (c) { c.classList.remove('is-active'); });
            chip.classList.add('is-active');
            selectedMode = { value: chip.getAttribute('data-mode'), needsAddress: chip.getAttribute('data-needs-address') === '1' };
            serverQuote();
            document.getElementById('auCkAddressField').hidden = !selectedMode.needsAddress;
        });
    });
    if (window.mbAddrAutocomplete) {
        window.mbAddrAutocomplete(document.getElementById('auCkAddress'), {
            suggest: document.getElementById('auAddrSuggest'),
            locBtn: document.getElementById('auAddrLoc')
        });
    }

    /* Address map picker — aurelia-native .au-sheet drawer, opened via the theme's
       own focus-trapping openSheet/closeSheet; map logic is the shared
       window.mbMapPicker engine. Fills the same #auCkAddress the checkout submits. */
    if (window.mbMapPicker) {
        window.mbMapPicker({
            trigger: document.getElementById('auMapBtn'),
            modal:   document.getElementById('auMapSheet'),
            map:     document.getElementById('auMap'),
            input:   document.getElementById('auCkAddress'),
            preview: document.getElementById('auMapPreview'),
            confirm: document.getElementById('auMapConfirm'),
            close:   document.getElementById('auMapClose'),
            toggle:  function (open) {
                if (open) { openSheet(document.getElementById('auMapSheet'), document.getElementById('auMapScrim'), document.getElementById('auMapTitle')); }
                else { closeSheet(); }
            },
            i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
        });
        document.getElementById('auMapScrim').addEventListener('click', function () { closeSheet(); });
    } else { var _auMapBtn = document.getElementById('auMapBtn'); if (_auMapBtn) { _auMapBtn.style.display = 'none'; } }

    /* Clear saved data on this device — Guest profile card row (guest-reachable via
       the "My Stay" tab). Wipes aurelia's own cart/favourites/stories/profile/bookings
       keys + store-scoped shared keys; the shared engine keeps the light/dark choice. */
    (function () {
        var cd = document.getElementById('auClearData');
        if (!cd) { return; }
        if (!window.mbClearDeviceData) { cd.style.display = 'none'; var h = document.querySelector('.au-cleardata-hint'); if (h) { h.style.display = 'none'; } return; }
        cd.addEventListener('click', function () {
            window.mbClearDeviceData({
                keys: [
                    'aurelia-cart-' + STORE_ID,
                    'aurelia-fav-rooms-' + STORE_ID,
                    'aurelia-fav-items-' + STORE_ID,
                    'aurelia-stories-viewed-' + STORE_ID,
                    'aurelia-profile-' + STORE_ID,
                    'aurelia-bookings-' + STORE_ID
                ],
                storeId: STORE_ID,
                slug: @json($store->slug ?? ''),
                confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
            });
        });
    })();

    var chargeToggle = document.getElementById('auChargeToggle');
    var chargeNameField = document.getElementById('auChargeNameField');
    if (chargeToggle) {
        chargeToggle.addEventListener('change', function () {
            chargeNameField.hidden = !chargeToggle.checked;
            if (chargeToggle.checked) { try { document.getElementById('auChargeNameInput').focus(); } catch (e) {} }
        });
    }

    document.getElementById('auCheckoutBtn').addEventListener('click', function () {
        document.getElementById('auCartView').hidden = true;
        document.getElementById('auCheckoutForm').hidden = false;
        document.getElementById('auCartFoot').hidden = true;
        document.getElementById('auCkAddressField').hidden = !selectedMode.needsAddress;
        try {
            var p = JSON.parse(window.localStorage.getItem('aurelia-profile-' + STORE_ID) || '{}');
            if (p.name && !document.getElementById('auCkName').value) { document.getElementById('auCkName').value = p.name; }
            if (p.phone && !document.getElementById('auCkPhone').value) { document.getElementById('auCkPhone').value = p.phone; }
        } catch (e) { /* best-effort */ }
    });

    document.getElementById('auPlaceOrder').addEventListener('click', function () {
        var msg = document.getElementById('auCheckoutMsg');
        msg.innerHTML = '';
        if (!cart.length) { msg.innerHTML = '<div class="au-msg au-msg--error">' + T.cartEmpty + '</div>'; return; }
        var name = document.getElementById('auCkName').value.trim();
        var phone = document.getElementById('auCkPhone').value.trim();
        if (!name) { msg.innerHTML = '<div class="au-msg au-msg--error">' + T.nameRequired + '</div>'; return; }
        if (selectedMode.needsAddress && !document.getElementById('auCkAddress').value.trim()) {
            msg.innerHTML = '<div class="au-msg au-msg--error">' + T.addressRequired + '</div>'; return;
        }
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('customer_name', name);
        fd.append('phone_number', phone);
        fd.append('type', selectedMode.value);
        if (selectedMode.needsAddress) { fd.append('address', document.getElementById('auCkAddress').value.trim()); }
        if (chargeToggle && chargeToggle.checked) {
            fd.append('payment_method', 'charge_to_room');
            fd.append('charge_room_name', document.getElementById('auChargeNameInput').value.trim());
            if (ROOM_KEY) { fd.append('room', ROOM_KEY); }
        }
        var auCouponField = document.getElementById('auCouponField');
        if (auCouponField && auCouponField.value) { fd.append('coupon_code', auCouponField.value); }
        var auGiftField = document.getElementById('auGiftCardInput');
        if (auGiftField && auGiftField.value.trim()) { fd.append('gift_card_code', auGiftField.value.trim()); }
        msg.innerHTML = '<div class="au-msg">' + T.placing + '</div>';
        fetch(CHECKOUT_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    // Device-local guest history: remember this order on THIS
                    // device so a guest (no account) still sees it on the Orders
                    // screen. Snapshot BEFORE the cart is cleared.
                    try {
                        if (data.track_token && window.mbGuestOrders) {
                            var _sub = cart.reduce(function (s, l) { return s + lineTotal(l); }, 0);
                            window.mbGuestOrders.save(STORE_ID, {
                                token: data.track_token,
                                trackUrl: data.redirect || null,
                                total: fmtMoney(_sub + computeTax(_sub)),
                                itemCount: cart.reduce(function (n, l) { return n + l.qty; }, 0),
                                itemIds: cart.map(function (l) { return l.id; })
                            });
                        }
                    } catch (e) { /* best-effort device cache */ }
                    cart = []; saveCart();
                    if (data.redirect) { window.location.href = data.redirect; return; }
                    msg.innerHTML = '<div class="au-msg au-msg--ok">' + @json(___('Order placed! Thank you.')) + '</div>';
                    renderCart();
                } else {
                    msg.innerHTML = '<div class="au-msg au-msg--error">' + ((data && data.message) || T.genericError) + '</div>';
                }
            })
            .catch(function () { msg.innerHTML = '<div class="au-msg au-msg--error">' + T.genericError + '</div>'; });
    });

    /* ================= my stay: loyalty rewards card ================= */
    // Card is already server-rendered from the public teaser — this only
    // ever adds a tier highlight on top when a real balance fetch succeeds,
    // never blocks/replaces anything if it fails (best-effort, once).
    var stayLoyaltyChecked = false;
    function loadStayLoyalty() {
        if (stayLoyaltyChecked || !LOYALTY_BALANCE_ROUTE || !document.querySelector('.au-rw-tiers')) { return; }
        stayLoyaltyChecked = true;
        fetch(LOYALTY_BALANCE_ROUTE, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                var hint = document.getElementById('auRwSigninHint');
                if (!data || !data.active) { return; }
                if (hint) { hint.hidden = true; }
                if (!data.tier) { return; }
                var tierEl = document.getElementById('auRwTier' + String(data.tier).replace(/[^a-zA-Z]/g, ''));
                if (tierEl) { tierEl.classList.add('is-current'); }
            })
            .catch(function () { /* best-effort — card stays a teaser */ });
    }

    /* ================= my stay: lookup + profile ================= */
    function renderLookupProfile() {
        try {
            var p = JSON.parse(window.localStorage.getItem('aurelia-profile-' + STORE_ID) || '{}');
            if (p.name) { document.getElementById('auProfileName').value = p.name; }
            if (p.phone) { document.getElementById('auProfilePhone').value = p.phone; }
        } catch (e) { /* best-effort */ }
    }
    document.getElementById('auProfileSave').addEventListener('click', function () {
        var name = document.getElementById('auProfileName').value.trim();
        var phone = document.getElementById('auProfilePhone').value.trim();
        try { window.localStorage.setItem('aurelia-profile-' + STORE_ID, JSON.stringify({ name: name, phone: phone })); } catch (e) {}
        toast(T.savedProfile);
    });
    document.getElementById('auLookupBtn').addEventListener('click', function () {
        var code = document.getElementById('auLookupCode').value.trim().toUpperCase();
        var resultEl = document.getElementById('auLookupResult');
        resultEl.innerHTML = '';
        var list = [];
        try { list = JSON.parse(window.localStorage.getItem('aurelia-bookings-' + STORE_ID) || '[]'); } catch (e) { list = []; }
        var found = list.filter(function (b) { return (b.code || '').toUpperCase() === code; })[0];
        if (found) {
            var text = T.lookupFound.replace('__ROOM__', found.room || '').replace('__DATES__', humanDate(found.checkIn) + ' → ' + humanDate(found.checkOut));
            var html = '<div class="au-msg au-msg--ok">' + escapeHtml(text) + '</div>';
            resultEl.innerHTML = html;
            if (found.redirect) {
                var a = document.createElement('a');
                a.href = found.redirect; a.className = 'au-link'; a.style.display = 'block'; a.style.marginTop = '8px';
                a.textContent = @json(___('Open reservation'));
                resultEl.appendChild(a);
            }
        } else {
            resultEl.innerHTML = '<div class="au-msg">' + T.lookupNone + '</div>';
        }
    });

    /* ================= init ================= */
    writeSearchUI();
    loadRooms();
    renderShopGrid('dining');
    renderShopGrid('spa');
    renderLookupProfile();
    refreshSavedBadge();

    /* crave-parity: branch switch */
    (function () {
        var bs = document.getElementById('auBranchSel');
        if (bs) { bs.addEventListener('change', function (e) { if (e.target.value) { window.location.assign(e.target.value); } }); }
    })();

    /* crave-parity: promo banner slider */
    (function () {
        var track = document.getElementById('auBannerTrack');
        var dotsWrap = document.getElementById('auBannerDots');
        if (!track) { return; }
        var slides = track.children;
        if (slides.length < 2) { return; }
        function scrollToSlide(i) {
            var s = slides[i]; if (!s) { return; }
            // Scroll the track horizontally only. scrollIntoView() scrolls the whole
            // PAGE up to the banner whenever it is out of view, so the 5s auto-advance
            // yanks the shopper back to the top. A relative scrollBy delta is RTL-safe.
            var sr = s.getBoundingClientRect(), tr = track.getBoundingClientRect();
            track.scrollBy({ left: (sr.left + sr.width / 2) - (tr.left + tr.width / 2), behavior: 'smooth' });
        }
        var dots = [];
        if (dotsWrap) {
            for (var i = 0; i < slides.length; i++) {
                var d = document.createElement('i');
                if (i === 0) { d.className = 'on'; }
                (function (idx) { d.addEventListener('click', function () { scrollToSlide(idx); }); })(i);
                dotsWrap.appendChild(d); dots.push(d);
            }
        }
        var cur = 0, raf = null;
        function paint(i) { cur = i; dots.forEach(function (d, j) { d.className = j === i ? 'on' : ''; }); }
        track.addEventListener('scroll', function () {
            if (raf) { return; }
            raf = requestAnimationFrame(function () { raf = null; var tc = track.getBoundingClientRect().left + track.clientWidth / 2, bi = 0, bd = Infinity; for (var k = 0; k < slides.length; k++) { var r = slides[k].getBoundingClientRect(), d = Math.abs(r.left + r.width / 2 - tc); if (d < bd) { bd = d; bi = k; } } if (bi !== cur && dots[bi]) { paint(bi); } });
        }, { passive: true });
        var timer = null, touched = false;
        function next() { if (touched || document.hidden) { return; } scrollToSlide((cur + 1) % slides.length); }
        ['touchstart', 'pointerdown', 'wheel'].forEach(function (ev) { track.addEventListener(ev, function () { touched = true; if (timer) { clearInterval(timer); timer = null; } }, { passive: true, once: true }); });
        timer = setInterval(next, 5000);
    })();

    /* Restore the active screen across a same-tab reload (a language switch is a
       full page navigation, which otherwise dumps the shopper back on home and
       makes their My Stay / order history "look gone"). sessionStorage is
       per-tab, so a fresh visit still opens on home. Skipped when a ?item
       deep-link would drive the screen, so it never fights that. Runs once. */
    /* Deep link: ?item=<id> opens the detail sheet on load. restoreScreen (below)
       already yields to it. Deferred to the next tick so every render helper this
       IIFE defines is initialised before openItem() runs. */
    try {
        var _deepItemId = parseInt(new URLSearchParams(window.location.search).get('item'), 10);
        if (_deepItemId) { setTimeout(function () { try { if (ITEMS[_deepItemId]) { openItem(_deepItemId); } } catch (e2) { /* no-op */ } }, 0); }
    } catch (e) { /* no-op on an unsupported/old browser */ }

    (function restoreScreen() {
        try {
            if (new URLSearchParams(window.location.search).get('item')) { return; }
            var saved = window.sessionStorage.getItem('mb-screen-' + STORE_ID);
            if (saved && saved !== 'home' && screens[saved]) { go(saved); }
        } catch (e) { /* best-effort */ }
    })();
})();
</script>
@endpush
