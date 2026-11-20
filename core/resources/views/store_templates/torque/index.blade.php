{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.torque.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalRegistry;
    use App\Services\Verticals\VerticalVocab;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;

    /* ---------------------------------------------------------------------
       torque — "Torque Garage" flagship car-parts storefront · data prep.

       Chassis borrowed from store_templates/shelf/index.blade.php (categories/
       currency/variant/cart/checkout plumbing) — see that file's own
       docblock for the original annotations. Renders through VerticalVocab,
       never hardcoding "Product"/"Order" (this theme is carparts-only, but
       the vocab call stays so a relabel never needs a template fork).
       --------------------------------------------------------------------- */
    $vocabSvc = app(VerticalVocab::class);
    $vocab    = $vocabSvc->map($store->store_type ?? null);

    $items = collect($total_menus)->values();

    // Two-tier-free category nav: top-level categories that own at least one
    // descendant item (own item OR a subcategory's item) — same "root"
    // mapping shelf/crave/aurora use, so nested categories still group under
    // one chip/tile/hotspot.
    $rootOf = [];
    foreach ($store->menu_categories as $parentCat) {
        $rootOf[$parentCat->id] = $parentCat->id;
        foreach ($parentCat->subcategories as $subCat) {
            $rootOf[$subCat->id] = $parentCat->id;
        }
    }
    $rootItemCount = [];
    foreach ($items as $m) {
        $root = $rootOf[$m->category_id] ?? $m->category_id;
        $rootItemCount[$root] = ($rootItemCount[$root] ?? 0) + 1;
    }
    $categories = \Juwri\Core\Support\ViewHelpers::withItems($store->menu_categories, $rootItemCount);

    /* Fitment capability (VerticalRegistry, consumed — never forked). Gates
       the vehicle HUD, VIN scan, manual wizard, garage and fitment gauge —
       the whole vehicle-intelligence layer — exactly like the fitment-filter
       block shelf/index.blade.php adds for the SAME `fitment` capability.
       Category browse (diagram hotspots, category tiles/chips, grid, cart,
       checkout) stays USABLE either way, so this template never hard-fails
       for a store where the capability isn't flipped on yet. */
    $fitment = app(VerticalRegistry::class)->has($store->store_type ?? null, 'fitment');

    // Defensive attribute readers — same shape as shelf's (Lane A columns may
    // not exist on every install yet; every read degrades to null/[] rather
    // than erroring).
    $attrsOf = \Juwri\Core\Support\ViewHelpers::attrsOf(...);
    $brandOf = \Juwri\Core\Support\ViewHelpers::brandOf(...);
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelAxes(...);

    $stockBadge = \Juwri\Core\Support\ViewHelpers::stockBadge(...);

    // P1 currency contract — same symbol/position/gap/decimals price_symbol_format()
    // already honours, so client-computed totals (cart subtotal, sheet
    // unit×qty, kit bundle total) render with the right symbol/side/decimals.
    $curSignRaw  = $postOptions->currency_sign ?? config('settings.currency_sign', '$');
    $curSign     = cur_isolate($curSignRaw);
    $curGap      = currency_symbol_gap($curSignRaw);
    $curPos      = (string) currency_pos_effective($store->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);

    // Language switcher — self-contained, storefront menu_languages
    $tqLanguages = collect($menu_languages ?? [])
        ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
        ->values();

    // Real tax contract (Order::computeTax) — exposed to the client cart/checkout
    // summary so the shown total mirrors the store's configured tax exactly.
    $taxCharge = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $taxType = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');

    $fulfillment = app(FulfillmentRegistry::class);
    $modes = \Juwri\Core\Support\ViewHelpers::fulfillmentModes($fulfillment, $store->store_type ?? null);

    $jsItems = [];
    foreach ($items as $m) {
        $variants = [];
        foreach ($m->variants ?? [] as $v) {
            $vLabel = $variantLabel($m, $v);
            $variants[] = [
                'id' => $v->id,
                'label' => $vLabel,
                'price' => (float) $v->price,
                'priceFormatted' => price_symbol_format($v->price),
                'sku' => $v->sku ?? null,
                'stockQty' => isset($v->stock_qty) ? (int) $v->stock_qty : null,
            ];
        }

        $attrs = $attrsOf($m);
        $root = $rootOf[$m->category_id] ?? $m->category_id;

        // Spec-sheet fields (Build checklist item 4) — read defensively off
        // `attributes`; a field simply doesn't render in the sheet when it's
        // absent (see index.blade.php's renderSpecs() below), never a
        // fabricated "—" for data that was never entered.
        $oemNumbers = array_values(array_filter((array) ($attrs['oem_numbers'] ?? [])));
        $crossRefs  = array_values(array_filter((array) ($attrs['cross_refs'] ?? [])));
        $coreCharge = isset($attrs['core_charge']) && is_numeric($attrs['core_charge']) ? (float) $attrs['core_charge'] : 0.0;

        // Gallery: primary image first, then every extra gallery image, all as
        // public URLs, deduped. Drives the detail-sheet thumbnail strip (only
        // rendered when there are ≥2 distinct images).
        $gallery = [];
        $primaryUrl = ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '';
        if ($primaryUrl !== '') {
            $gallery[] = $primaryUrl;
        }
        foreach ((array) $m->gallery_images as $gimg) {
            if (is_string($gimg) && $gimg !== '' && $gimg !== 'default.png') {
                $gurl = media_url('storage/menu/', $gimg, $store->id ?? null);
                if ($gurl !== '' && ! in_array($gurl, $gallery, true)) {
                    $gallery[] = $gurl;
                }
            }
        }

        $jsItems[$m->id] = [
            'id' => $m->id,
            'cat' => $m->category_id,
            'root' => $root,
            'name' => $m->name,
            'desc' => strip_tags((string) $m->description),
            'img' => ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '',
            'gallery' => $gallery,
            'price' => (float) $m->price,
            'priceFormatted' => price_symbol_format($m->price),
            'brand' => $brandOf($m),
            'pn' => $attrs['part_number'] ?? ($m->plu ?? $m->external_sku ?? null),
            'oem' => $oemNumbers,
            'crossRefs' => $crossRefs,
            'position' => $attrs['position'] ?? null,
            'torqueSpec' => $attrs['torque'] ?? null,
            'installMin' => isset($attrs['install_min']) && is_numeric($attrs['install_min']) ? (int) $attrs['install_min'] : null,
            'difficulty' => isset($attrs['difficulty']) && is_numeric($attrs['difficulty']) ? max(0, min(5, (int) $attrs['difficulty'])) : null,
            'tools' => $attrs['tools'] ?? null,
            'warrantyYr' => isset($attrs['warranty_yr']) && is_numeric($attrs['warranty_yr']) ? (float) $attrs['warranty_yr'] : null,
            'core' => $coreCharge,
            'coreFormatted' => $coreCharge > 0 ? price_symbol_format($coreCharge) : null,
            'soldOut' => (bool) $m->is_sold_out,
            'trackInventory' => (bool) ($m->track_inventory ?? false),
            'stockQty' => isset($m->stock_qty) ? (int) $m->stock_qty : null,
            'stockBadge' => $stockBadge((bool) ($m->track_inventory ?? false), isset($m->stock_qty) ? (int) $m->stock_qty : null),
            'variants' => $variants,
            // Native goods cart endpoint (Lane B) for THIS item, so add-to-cart
            // lands a real CartLine — same integration shape as shelf.
            'addRoute' => route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $m->id]),
        ];
    }

    $brands = collect($jsItems)->pluck('brand')->filter()->unique()->sort()->values();

    // Crave-parity features (same backend data; no controller change).
    $tqIsOpen = store_open_now($store);
    $tqHoursToday = storefront_hours_today($store);
    $tqBranches = collect($branches ?? []);
    $tqShowBranches = $tqBranches->count() > 1 && (($postOptions->allow_multi_branch_switch ?? 1) != '0');
    $tqActiveBranch = $activeBranch ?? null;
    $tqBanners = \Juwri\Core\Support\ViewHelpers::activeBanners($menuBanners ?? []);

    $checkoutRoute = route('storefront.checkout.place', ['slug' => $store->slug]);
    $cartLineRouteTemplate = route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => 999999999]);
    $slotRoute = route('store.scheduleSlots', $store->id);

    // Lane E2/E5 endpoints (FitmentPublicController) — registered
    // unconditionally in routes/verticals/public/lane-e.php, so route()
    // resolves regardless of whether THIS store has the capability; the
    // controller itself returns {enabled:false} for a non-carparts store.
    $fitmentOptionsRoute = route('storefront.fitment.options', $store->slug);
    $oemSearchRoute = route('storefront.fitment.oemSearch', $store->slug);

    // VIN decode is a pluggable provider seam that may not be wired up yet
    // (see this theme's handoff spec) — Route::has() guards the route()
    // call itself so an unregistered route name never fatals this view; the
    // client gets `null` and skips straight to the manual wizard.
    $vinDecodeRoute = Route::has('vin.decode') ? route('vin.decode', ['slug' => $store->slug]) : null;

    /* ---------------------------------------------------------------------
       Curated core features (loop/enrich): stories, reviews, loyalty. All
       three reuse existing, already-seeded/live platform endpoints — no
       backend changes made here. Search (VIN/fitment) already exists on
       this theme; wishlist is added client-side (localStorage) below.
       --------------------------------------------------------------------- */
    // Stories — public, ungated feed for this store.
    $storiesUrl = url('r/'.$store->id.'/stories');

    // Reviews — public list/write base; menu id appended client-side.
    $mbrBase = url('r/'.$store->id.'/item');

    // Loyalty — teaser is public (no auth); balance/redeem need auth:customer.
    $loy = store_options($store->id, 'loyalty_teaser');
    $loy = $loy ? (array) json_decode(json_encode($loy), true) : null;
    $loyaltyBalanceUrl = url('restaurant/'.$store->id.'/loyalty/balance');
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    /* dark-first "carbon instrument" palette — see /tmp/authoring/torque2.src.html
       for the reference tokens this ports 1:1 (amber actions, cyan telemetry,
       fit/verify/nofit traffic-light). Dark is the DEFAULT (no data-theme
       attr / prefers-color-scheme:dark); [data-theme="light"] is the toggle. */
    :root{
        --tq-ground:#0C0F12; --tq-card:#14181C; --tq-card-2:#1B2126; --tq-card-3:#232A30;
        --tq-ink:#EAEEF1; --tq-ink-2:#96A2AA; --tq-ink-3:#5C676E; --tq-hair:#232A30; --tq-hair-2:#2E373E;
        --tq-amber:{{ $store->color ?: '#F5A623' }}; --tq-amber-2:#FFC24D; --tq-amber-ink:#3A2A08;
        --tq-cyan:#27D3EE; --tq-cyan-dim:#0E8DA6;
        --tq-fit:#35D07E; --tq-fit-bg:rgba(53,208,126,.13); --tq-fit-ink:#7BEBAD;
        --tq-verify:#F5A623; --tq-verify-bg:rgba(245,166,35,.14);
        --tq-nofit:#F0553D; --tq-nofit-bg:rgba(240,85,61,.14); --tq-nofit-ink:#FF8975;
        --tq-grid:rgba(255,255,255,.035);
        --tq-radius:12px; --tq-radius-lg:18px;
        --tq-shadow-card:0 14px 30px -18px rgba(0,0,0,.6);
        --tq-f-head:"Archivo",system-ui,-apple-system,"Segoe UI",sans-serif;
        --tq-f-sans:"Saudi Riyal","IBM Plex Sans",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
        --tq-f-mono:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    }
    :root[data-theme="light"]{
        --tq-ground:#EEF1F3; --tq-card:#FFFFFF; --tq-card-2:#F3F6F8; --tq-card-3:#E9EEF1;
        --tq-ink:#0E1519; --tq-ink-2:#54626B; --tq-ink-3:#8A97A0; --tq-hair:#E4E9ED; --tq-hair-2:#D3DBE0;
        --tq-amber:{{ $store->color ?: '#E6910C' }}; --tq-amber-2:#C97A08; --tq-amber-ink:#fff;
        --tq-cyan:#0B93AE; --tq-cyan-dim:#0B93AE;
        --tq-fit:#1F9D5E; --tq-fit-bg:rgba(31,157,94,.12); --tq-fit-ink:#127045;
        --tq-verify:#B9770B; --tq-verify-bg:rgba(230,145,12,.14);
        --tq-nofit:#D23B24; --tq-nofit-bg:rgba(210,59,36,.1); --tq-nofit-ink:#D23B24;
        --tq-grid:rgba(0,0,0,.04);
        --tq-shadow-card:0 14px 30px -18px rgba(40,45,50,.28);
    }
    *{box-sizing:border-box}
    .tq-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:50}
    .tq-skip:focus{inset-inline-start:0}
    .tq-body{margin:0;background:var(--tq-ground);color:var(--tq-ink);font-family:var(--tq-f-sans);-webkit-font-smoothing:antialiased;
        background-image:linear-gradient(var(--tq-grid) 1px,transparent 1px),linear-gradient(90deg,var(--tq-grid) 1px,transparent 1px);
        background-size:26px 26px;background-attachment:fixed}
    .tq-body button{font-family:inherit}
    .mono,.tq-mono{font-family:var(--tq-f-mono)}
    :focus-visible{outline:2px solid var(--tq-amber);outline-offset:2px}
    .tq{--tq-pad:16px;max-width:1180px;margin:0 auto;padding:0 var(--tq-pad) 90px;overflow-x:clip}

    /* Scrollbars, torque-styled. Horizontal item rails hide their bar and rely on
       scroll-snap + swipe (crave's approach); the page and vertical panels keep a
       slim amber-on-dark bar so scroll position stays legible on desktop. */
    html{scrollbar-width:thin;scrollbar-color:var(--tq-hair-2) transparent}
    html::-webkit-scrollbar{width:12px;height:12px}
    html::-webkit-scrollbar-track{background:transparent}
    html::-webkit-scrollbar-thumb{background:var(--tq-hair-2);border-radius:999px;
        border:3px solid var(--tq-ground);background-clip:content-box}
    html::-webkit-scrollbar-thumb:hover{background:var(--tq-amber)}
    /* Touch hides the item rails' bar (swipe + snap, crave-style). A mouse has no
       swipe, so on hover/fine pointers give the rails a slim draggable bar back —
       otherwise an overflowing rail is unreachable on desktop. */
    @media (hover:hover) and (pointer:fine){
        #tqApp .tq-stories,#tqApp .tq-chips{scrollbar-width:thin;scrollbar-color:var(--tq-hair-2) transparent}
        #tqApp .tq-stories::-webkit-scrollbar,#tqApp .tq-chips::-webkit-scrollbar{display:block;height:8px}
        #tqApp .tq-stories::-webkit-scrollbar-thumb,#tqApp .tq-chips::-webkit-scrollbar-thumb{background:var(--tq-hair-2);border-radius:999px;border:2px solid transparent;background-clip:content-box}
        #tqApp .tq-stories::-webkit-scrollbar-thumb:hover,#tqApp .tq-chips::-webkit-scrollbar-thumb:hover{background:var(--tq-amber)}
        #tqApp .tq-stories::-webkit-scrollbar-track,#tqApp .tq-chips::-webkit-scrollbar-track{background:transparent}
    }
    .tq-sheet__body,.tq-wz-grid,.tq-wz-list,#tqWzOptions{scrollbar-width:thin;scrollbar-color:var(--tq-hair-2) transparent}
    .tq-sheet__body::-webkit-scrollbar,.tq-wz-grid::-webkit-scrollbar,.tq-wz-list::-webkit-scrollbar,#tqWzOptions::-webkit-scrollbar{width:10px;height:10px}
    .tq-sheet__body::-webkit-scrollbar-thumb,.tq-wz-grid::-webkit-scrollbar-thumb,.tq-wz-list::-webkit-scrollbar-thumb,#tqWzOptions::-webkit-scrollbar-thumb{
        background:var(--tq-hair-2);border-radius:999px;border:2px solid var(--tq-card);background-clip:content-box}
    .tq-sheet__body::-webkit-scrollbar-thumb:hover,.tq-wz-grid::-webkit-scrollbar-thumb:hover,.tq-wz-list::-webkit-scrollbar-thumb:hover,#tqWzOptions::-webkit-scrollbar-thumb:hover{background:var(--tq-amber)}
    .tq-sheet__body::-webkit-scrollbar-track,.tq-wz-grid::-webkit-scrollbar-track,.tq-wz-list::-webkit-scrollbar-track,#tqWzOptions::-webkit-scrollbar-track{background:transparent}

    /* header */
    .tq-head{position:sticky;top:0;z-index:6;display:flex;align-items:center;gap:12px;
        padding:14px 16px;background:color-mix(in srgb, var(--tq-ground) 88%, transparent);backdrop-filter:blur(10px);
        border-block-end:1px solid var(--tq-hair)}
    /* land story-CTA "Explore" scroll clear of the sticky header */
    #tqCatTiles,#tqGridHeading,#tqGrid{scroll-margin-top:76px}
    .tq-brand{display:flex;align-items:center;gap:10px;min-width:0;flex:1 1 auto}
    .tq-mark{width:30px;height:30px;flex:none;color:var(--tq-amber)}
    .tq-mark svg{width:100%;height:100%}
    .tq-brandtext{min-width:0}
    .tq-title{font-family:var(--tq-f-head);font-weight:800;font-size:1.05rem;letter-spacing:.04em;text-transform:uppercase;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
    .tq-tagline{font-family:var(--tq-f-mono);font-size:.68rem;color:var(--tq-ink-2);letter-spacing:.02em;margin:0;display:block}
    .tq-iconbtn{border:1px solid var(--tq-hair);background:var(--tq-card);color:inherit;cursor:pointer;padding:9px;border-radius:11px;
        display:inline-flex;align-items:center;justify-content:center;position:relative;transition:transform .14s,background .14s}
    .tq-iconbtn:hover{background:var(--tq-card-2)}
    .tq-iconbtn:active{transform:scale(.92)}
    .tq-iconbtn svg{width:19px;height:19px}
    .tq-langsel{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--tq-hair);border-radius:11px;
        padding:7px 10px;color:var(--tq-ink-2);background:var(--tq-card)}
    .tq-langsel svg{width:16px;height:16px;flex:none}
    .tq-langsel select{border:0;background:transparent;color:var(--tq-ink);font-family:var(--tq-f-mono);font-size:.72rem;
        font-weight:700;letter-spacing:.06em;cursor:pointer;text-transform:uppercase}
    .tq-langsel select:focus{outline:none}
    .tq-badge{position:absolute;top:-4px;inset-inline-end:-4px;background:var(--tq-amber);color:var(--tq-amber-ink);
        font-family:var(--tq-f-mono);font-size:.64rem;font-weight:700;min-width:17px;height:17px;border-radius:999px;display:flex;
        align-items:center;justify-content:center;padding:0 3px}
    .tq-badge[hidden]{display:none}

    /* bottom tabbar — hidden on wide screens; the topbar carries every action
       there. Root-level fixed bar, so no ancestor backdrop-filter/transform
       traps it. */
    .tq-tabbar{display:none}
    .tq-tab{position:relative;flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:center;
        gap:3px;border:0;background:transparent;color:var(--tq-ink-2);cursor:pointer;padding:8px 4px;font:inherit}
    .tq-tab svg{width:22px;height:22px}
    .tq-tab span{font-size:.6rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-family:var(--tq-f-mono);
        max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .tq-tab:active{color:var(--tq-amber)}
    .tq-tab-badge{position:absolute;top:2px;inset-inline-end:calc(50% - 22px);background:var(--tq-amber);color:var(--tq-amber-ink);
        font-family:var(--tq-f-mono);font-size:.6rem;font-weight:700;min-width:16px;height:16px;border-radius:999px;
        display:flex;align-items:center;justify-content:center;padding:0 3px}
    .tq-tab-badge[hidden]{display:none}
    @media (max-width: 620px){
        .tq-tabbar{display:flex;position:fixed;inset-inline:0;bottom:0;z-index:15;
            background:color-mix(in srgb, var(--tq-ground) 94%, transparent);backdrop-filter:blur(10px);
            border-block-start:1px solid var(--tq-hair);padding-block-end:env(safe-area-inset-bottom,0px)}
        /* topbar sheds its duplicated icons so it stays uncrowded */
        #tqSavedOpen,#tqAccountOpen,#tqCartOpen{display:none}
    }

    .tq-main{padding-block-start:18px}
    .tq-h1{font-family:var(--tq-f-head);font-weight:800;font-size:1.7rem;line-height:1.08;letter-spacing:.01em;
        text-transform:uppercase;margin:0 0 14px}
    .tq-h1 .dim{color:var(--tq-ink-2)}

    /* vehicle HUD */
    .tq-hud{display:flex;align-items:center;gap:12px;background:var(--tq-card);border:1px solid var(--tq-hair);
        border-radius:14px;padding:13px 15px;position:relative;overflow:hidden;margin-block-end:16px}
    .tq-hud::before{content:'';position:absolute;inset:0;background:linear-gradient(120deg,transparent,rgba(39,211,238,.06));pointer-events:none}
    .tq-hud .tq-vico{width:42px;height:42px;border-radius:10px;background:var(--tq-card-2);display:flex;align-items:center;
        justify-content:center;color:var(--tq-amber);flex:none}
    .tq-hud .tq-vico svg{width:22px;height:22px}
    .tq-hud .tq-vinfo{flex:1;min-width:0}
    .tq-hud .tq-vlabel{font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--tq-ink-3);font-weight:700}
    .tq-hud .tq-vname{font-family:var(--tq-f-head);font-weight:700;font-size:.94rem;letter-spacing:.01em;
        white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .tq-hud .tq-vspec{font-family:var(--tq-f-mono);font-size:.72rem;color:var(--tq-cyan)}
    .tq-hud .tq-change{font-size:.76rem;font-weight:700;color:var(--tq-amber);border:1px solid var(--tq-hair-2);
        border-radius:100px;padding:7px 13px;flex:none;background:transparent;cursor:pointer}
    .tq-hud .tq-change:hover{background:var(--tq-card-2)}

    /* search */
    /* crave-parity: status row + branch + banners */
    .tq-statusrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:12px 0 16px}
    .tq-openchip{display:inline-flex;align-items:center;gap:6px;font-size:.78rem;font-weight:600;color:#3bbb73;
        background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:100px;padding:6px 12px;font-family:var(--tq-f-mono)}
    .tq-openchip i{width:7px;height:7px;border-radius:50%;background:#3bbb73;flex:none}
    .tq-openchip.is-closed{color:#e2554e} .tq-openchip.is-closed i{background:#e2554e}
    .tq-branchsel{position:relative;display:inline-flex;align-items:center;height:32px;border-radius:100px;
        border:1px solid var(--tq-hair);background:var(--tq-card);color:var(--tq-ink);cursor:pointer}
    .tq-branchsel svg{position:absolute;inset-inline-start:10px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--tq-amber);pointer-events:none}
    .tq-branchsel::after{content:"";position:absolute;inset-inline-end:11px;top:50%;width:6px;height:6px;border-inline-end:1.5px solid var(--tq-ink-3);border-bottom:1.5px solid var(--tq-ink-3);transform:translateY(-65%) rotate(45deg);pointer-events:none}
    .tq-branchsel select{-webkit-appearance:none;appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-size:.78rem;font-weight:600;cursor:pointer;outline:none;height:100%;padding-inline-start:30px;padding-inline-end:24px;max-width:180px}
    .tq-branchsel select:focus-visible{outline:none}
    .tq-branchsel:focus-within{outline:2px solid var(--tq-amber);outline-offset:2px}
    /* full-bleed carousel: bleed to the screen edge, but keep its inner
       padding equal to the page gutter so the first/last card still lines up
       with the guttered sections above and below. */
    .tq-banners{margin:6px calc(-1 * var(--tq-pad)) 18px;padding-inline:var(--tq-pad)}
    .tq-banner-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none}
    .tq-banner-track::-webkit-scrollbar{display:none}
    .tq-banner{flex:0 0 86%;scroll-snap-align:center;position:relative;min-height:104px;aspect-ratio:5/2;border-radius:12px;overflow:hidden;display:flex;align-items:center;padding:18px 20px;text-decoration:none;background:var(--tq-amber);color:var(--tq-amber-ink)}
    .tq-banner:nth-child(even){background:var(--tq-card-2);color:var(--tq-ink)}
    .tq-banner-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.9}
    .tq-banner-copy{position:relative;display:flex;flex-direction:column;gap:3px;z-index:1}
    .tq-banner-kick{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;opacity:.85;font-family:var(--tq-f-mono)}
    .tq-banner-title{font-size:1.15rem;font-weight:700;line-height:1.15}
    .tq-banner-dots{display:flex;gap:6px;justify-content:center;margin-top:9px}
    .tq-banner-dots i{width:6px;height:6px;border-radius:50%;background:var(--tq-hair-2);transition:width .2s,background .2s}
    .tq-banner-dots i.on{background:var(--tq-amber);width:16px;border-radius:100px}

    .tq-search-row{display:flex;gap:10px;margin-block-end:18px}
    .tq-search-box{flex:1;min-width:0;height:48px;border-radius:12px;background:var(--tq-card);border:1px solid var(--tq-hair);
        display:flex;align-items:center;gap:10px;padding:0 15px}
    .tq-search-box svg{width:17px;height:17px;flex:none;color:var(--tq-ink-3)}
    .tq-search-input{border:0;background:transparent;color:inherit;font-size:.9rem;width:100%;font-family:var(--tq-f-sans)}
    .tq-search-input:focus{outline:none}
    .tq-scan-btn{width:48px;height:48px;border-radius:12px;background:var(--tq-amber);color:var(--tq-amber-ink);
        display:flex;align-items:center;justify-content:center;flex:none;border:0;cursor:pointer;transition:transform .14s}
    .tq-scan-btn:active{transform:scale(.92)}
    .tq-scan-btn svg{width:21px;height:21px}
    .tq-brand-select{height:48px;padding:0 12px;border-radius:12px;border:1px solid var(--tq-hair);background:var(--tq-card);
        color:inherit;font-size:.82rem;flex:0 0 150px}

    /* section headers */
    .tq-sect-h{display:flex;align-items:center;justify-content:space-between;margin:24px 0 12px;gap:10px}
    .tq-sect-h h2,.tq-sect-h h3{font-family:var(--tq-f-head);font-size:.82rem;font-weight:700;letter-spacing:.1em;
        text-transform:uppercase;margin:0;color:var(--tq-ink)}
    .tq-sect-h .tq-viewall{font-size:.78rem;color:var(--tq-amber);font-weight:700;background:none;border:0;cursor:pointer;font-family:var(--tq-f-sans)}

    /* exploded diagram hero */
    .tq-diagram{position:relative;border:1px solid var(--tq-hair);border-radius:var(--tq-radius-lg);
        background:radial-gradient(circle at 50% 25%,var(--tq-card-2),var(--tq-card));overflow:hidden;padding:16px 16px 12px;margin-block-end:16px}
    .tq-diagram .tq-eyebrow{font-family:var(--tq-f-mono);font-size:.68rem;color:var(--tq-cyan);letter-spacing:.08em;
        display:flex;align-items:center;gap:6px}
    .tq-diagram .tq-eyebrow svg{width:13px;height:13px}
    .tq-diagram h3{font-family:var(--tq-f-head);font-weight:700;text-transform:uppercase;letter-spacing:.03em;font-size:1rem;margin:4px 0 0}
    .tq-diagram p{font-size:.8rem;color:var(--tq-ink-2);margin:3px 0 0}
    .tq-carwrap{position:relative;margin-block-start:8px}
    .tq-carwrap svg{width:100%;height:auto;display:block;max-height:220px;margin:0 auto}
    .tq-hot{cursor:pointer}
    .tq-hot .ring{fill:none;stroke:var(--tq-amber);stroke-width:2;transform-origin:center;transform-box:fill-box}
    .tq-hot .core{fill:var(--tq-amber)}
    .tq-hot .pulse{fill:var(--tq-amber);opacity:.4;transform-origin:center;transform-box:fill-box;animation:tqPulseDot 2s ease-out infinite}
    @keyframes tqPulseDot{0%{transform:scale(1);opacity:.5}70%{transform:scale(3);opacity:0}100%{opacity:0}}
    .tq-hotlabel{position:absolute;transform:translate(-50%,-140%);font-family:var(--tq-f-mono);font-size:.62rem;color:var(--tq-ink);
        background:var(--tq-card-3);border:1px solid var(--tq-hair-2);padding:2px 8px;border-radius:100px;white-space:nowrap;pointer-events:none;
        opacity:0;transition:opacity .12s}
    .tq-hot:hover + .tq-hotlabel,.tq-hot:focus + .tq-hotlabel{opacity:1}

    /* maintenance / mileage */
    .tq-maint{background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:var(--tq-radius-lg);padding:16px;
        margin-block-end:16px;position:relative;overflow:hidden}
    .tq-maint .tq-mtop{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .tq-maint .tq-odo{font-family:var(--tq-f-mono);font-size:.76rem;color:var(--tq-cyan);display:flex;align-items:center;gap:6px}
    .tq-maint .tq-odo svg{width:14px;height:14px}
    .tq-odo-input{background:transparent;border:1px solid var(--tq-hair-2);border-radius:8px;color:var(--tq-cyan);
        font-family:var(--tq-f-mono);font-size:.76rem;width:84px;padding:4px 6px;text-align:end}
    .tq-maint h3{font-family:var(--tq-f-head);font-weight:700;text-transform:uppercase;letter-spacing:.03em;font-size:.9rem;margin:10px 0 0}
    .tq-track{position:relative;height:44px;margin:16px 6px 4px}
    .tq-track .tq-line{position:absolute;top:20px;inset-inline-start:0;inset-inline-end:0;height:3px;background:var(--tq-hair-2);border-radius:3px}
    .tq-track .tq-fill{position:absolute;top:20px;inset-inline-start:0;height:3px;background:var(--tq-amber);border-radius:3px;width:0;
        transition:width 1s ease}
    [dir="rtl"] .tq-track .tq-fill{inset-inline-start:auto;inset-inline-end:0}
    .tq-tick{position:absolute;top:12px;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;gap:6px}
    [dir="rtl"] .tq-tick{transform:translateX(50%)}
    .tq-tick i{width:12px;height:12px;border-radius:50%;background:var(--tq-card);border:2px solid var(--tq-hair-2);display:block}
    .tq-tick.is-done i{background:var(--tq-amber);border-color:var(--tq-amber)}
    .tq-tick.is-now i{border-color:var(--tq-cyan);background:var(--tq-cyan);box-shadow:0 0 0 4px rgba(39,211,238,.18)}
    .tq-tick.is-due i{border-color:var(--tq-amber);background:var(--tq-ground)}
    .tq-tick b{font-family:var(--tq-f-mono);font-size:.6rem;color:var(--tq-ink-3);font-weight:500}
    .tq-due{display:flex;align-items:center;gap:8px;margin-block-start:10px;padding-block-start:12px;border-block-start:1px solid var(--tq-hair);flex-wrap:wrap}
    .tq-due .tq-due-txt{flex:1;font-size:.8rem;color:var(--tq-ink-2);min-width:180px}
    .tq-due .tq-due-txt b{color:var(--tq-ink);font-weight:600}
    .tq-due button{background:var(--tq-amber);color:var(--tq-amber-ink);font-weight:700;font-size:.8rem;padding:9px 15px;
        border-radius:100px;flex:none;border:0;cursor:pointer;font-family:var(--tq-f-sans)}

    /* category tiles + chips */
    .tq-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:10px;margin-block-end:6px}
    .tq-cat{background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:14px;padding:13px 6px;display:flex;
        flex-direction:column;align-items:center;gap:8px;transition:transform .14s,border-color .14s;cursor:pointer;color:inherit}
    .tq-cat:active{transform:scale(.95)}
    .tq-cat:hover{border-color:var(--tq-hair-2)}
    .tq-cat .tq-ci{width:38px;height:38px;border-radius:10px;background:var(--tq-card-2);display:flex;align-items:center;
        justify-content:center;color:var(--tq-amber)}
    .tq-cat .tq-ci svg{width:19px;height:19px}
    .tq-cat span{font-size:.72rem;font-weight:600;color:var(--tq-ink-2);text-align:center;line-height:1.2;
        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .tq-chips{display:flex;gap:8px;overflow-x:auto;overflow-y:clip;padding:4px 0 12px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch;scrollbar-width:none}
    .tq-chips::-webkit-scrollbar{display:none}
    .tq-chip{flex:0 0 auto;height:36px;padding:0 15px;border-radius:100px;background:var(--tq-card);border:1px solid var(--tq-hair);scroll-snap-align:start;
        font-size:.8rem;font-weight:600;color:var(--tq-ink-2);cursor:pointer;font-family:var(--tq-f-sans)}
    .tq-chip.is-active{background:var(--tq-amber);color:var(--tq-amber-ink);border-color:var(--tq-amber)}

    /* Y/M/M/E + OEM search row */
    .tq-ymm{padding:2px 0 14px}
    .tq-ymm-row{display:flex;flex-wrap:wrap;gap:8px;margin-block-end:8px}
    .tq-ymm-select{flex:1 1 100px;min-width:90px;height:42px;padding:0 10px;border-radius:10px;border:1px solid var(--tq-hair);
        background:var(--tq-card);color:inherit;font-size:.8rem;font-family:var(--tq-f-sans)}
    .tq-ymm-select:disabled{opacity:.5}
    .tq-oem-search{flex:1;height:42px;border-radius:10px;border:1px solid var(--tq-hair);background:var(--tq-card);color:inherit;
        padding:0 12px;font-size:.8rem;font-family:var(--tq-f-mono)}

    /* product grid + card */
    .tq-results{font-family:var(--tq-f-mono);font-size:.72rem;color:var(--tq-ink-3)}
    .tq-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(168px,1fr));gap:14px;padding-block-start:2px}
    .tq-card{position:relative;background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:16px;padding:10px;
        display:flex;flex-direction:column;transition:transform .16s,border-color .16s;box-shadow:var(--tq-shadow-card);text-align:start}
    .tq-card:hover{border-color:var(--tq-hair-2)}
    .tq-card__imgwrap{position:relative;border-radius:11px;overflow:clip;aspect-ratio:1/1;background:var(--tq-ground)}
    .tq-card__img{width:100%;height:100%;object-fit:cover;display:block;opacity:.97}
    .tq-card__img--ph{display:flex;align-items:center;justify-content:center;color:var(--tq-ink-3);background:var(--tq-ground)}
    .tq-card__media-link{display:block;width:100%;height:100%}
    .tq-card__media-link:focus-visible{outline:2px solid var(--tq-amber);outline-offset:-2px}
    .tq-card__soldout{position:absolute;inset-block-start:8px;inset-inline-end:8px;background:var(--tq-nofit);color:#fff;
        font-family:var(--tq-f-mono);font-size:.62rem;font-weight:700;padding:3px 8px;border-radius:6px;z-index:2}
    .tq-fitchip{position:absolute;top:8px;inset-inline-start:8px;z-index:2;font-family:var(--tq-f-mono);font-size:.62rem;font-weight:600;
        padding:3px 8px;border-radius:100px;display:inline-flex;align-items:center;gap:4px;backdrop-filter:blur(6px)}
    .tq-fitchip svg{width:10px;height:10px}
    .tq-fitchip.fit{background:var(--tq-fit-bg);color:var(--tq-fit-ink);border:1px solid rgba(53,208,126,.3)}
    .tq-fitchip.verify{background:var(--tq-verify-bg);color:var(--tq-amber-2);border:1px solid rgba(245,166,35,.3)}
    .tq-fitchip.nofit{background:var(--tq-nofit-bg);color:var(--tq-nofit-ink);border:1px solid rgba(240,85,61,.3)}
    .tq-card__body{padding:10px 2px 2px;display:flex;flex-direction:column;gap:3px;flex:1}
    .tq-card__brand{font-size:.64rem;color:var(--tq-ink-3);text-transform:uppercase;letter-spacing:.05em;font-weight:600}
    .tq-card__name{font-family:var(--tq-f-head);font-weight:600;font-size:.86rem;letter-spacing:.005em;line-height:1.22;
        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:clip}
    .tq-card__pn{font-family:var(--tq-f-mono);font-size:.66rem;color:var(--tq-ink-3);margin-block-start:1px}
    .tq-stock{font-size:.66rem;font-weight:600;margin-block-start:1px}
    .tq-stock--low{color:var(--tq-verify)}
    .tq-stock--in{color:var(--tq-fit)}
    .tq-stock--out{color:var(--tq-nofit)}
    .tq-card__foot{margin-block-start:auto;display:flex;align-items:center;justify-content:space-between;gap:6px;padding-block-start:8px}
    .tq-price{font-family:var(--tq-f-head);font-weight:700;font-size:.98rem}
    .tq-qadd{width:33px;height:33px;border-radius:10px;background:var(--tq-amber);color:var(--tq-amber-ink);display:flex;
        align-items:center;justify-content:center;transition:transform .14s;border:0;cursor:pointer;flex:none}
    .tq-qadd:active{transform:scale(.85) rotate(-10deg)}
    .tq-qadd:disabled{opacity:.4;cursor:not-allowed}
    .tq-qadd svg{width:16px;height:16px}
    .tq-qadd.tq-click svg{animation:tqWrench .4s ease}
    @keyframes tqWrench{0%,100%{transform:rotate(0)}40%{transform:rotate(-28deg)}}
    .tq-empty{padding:60px 10px;text-align:center;color:var(--tq-ink-2)}
    .tq-empty .tq-eico{width:64px;height:64px;border-radius:16px;background:var(--tq-card);border:1px solid var(--tq-hair);
        display:flex;align-items:center;justify-content:center;color:var(--tq-ink-3);margin:0 auto 14px}
    .tq-empty .tq-eico svg{width:28px;height:28px}
    .tq-empty h3{font-family:var(--tq-f-head);color:var(--tq-ink);text-transform:uppercase;letter-spacing:.03em;font-size:1rem;margin:0 0 4px}

    /* off-canvas sheets (item / cart / garage) — same a11y chassis as shelf */
    .tq-sheet-scrim{position:fixed;inset:0;background:rgba(6,8,9,.6);z-index:19;opacity:0;pointer-events:none;transition:opacity .18s ease}
    .tq-sheet-scrim.is-open{opacity:1;pointer-events:auto}
    .tq-sheet{position:fixed;inset-block:0;inset-inline-end:0;width:min(440px,100%);background:var(--tq-ground);color:inherit;
        z-index:20;transform:translateX(100%);transition:transform .22s ease;display:flex;flex-direction:column;
        box-shadow:-8px 0 30px rgba(0,0,0,.4);border-inline-start:1px solid var(--tq-hair)}
    [dir="rtl"] .tq-sheet{transform:translateX(-100%)}
    .tq-sheet.is-open{transform:translateX(0)}
    .tq-sheet__head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-block-end:1px solid var(--tq-hair)}
    .tq-sheet__title{font-family:var(--tq-f-head);font-weight:700;text-transform:uppercase;letter-spacing:.03em;font-size:.86rem}
    .tq-sheet__body{flex:1;overflow-y:auto;overflow-x:clip;padding:16px}
    .tq-sheet__foot{padding:14px 16px;border-block-start:1px solid var(--tq-hair);background:var(--tq-ground)}

    /* full-screen modals (VIN / wizard) */
    .tq-modal-scrim{position:fixed;inset:0;background:var(--tq-ground);z-index:29;opacity:0;pointer-events:none;transition:opacity .22s ease}
    .tq-modal-scrim.is-open{opacity:1;pointer-events:auto}
    .tq-modal{position:fixed;inset:0;z-index:30;display:flex;flex-direction:column;background:var(--tq-ground);
        opacity:0;transform:translateY(10px);pointer-events:none;transition:opacity .22s ease,transform .22s ease;
        background-image:linear-gradient(var(--tq-grid) 1px,transparent 1px),linear-gradient(90deg,var(--tq-grid) 1px,transparent 1px);background-size:26px 26px}
    .tq-modal.is-open{opacity:1;transform:translateY(0);pointer-events:auto}
    .tq-modal__inner{max-width:520px;margin:0 auto;width:100%;flex:1;display:flex;flex-direction:column;padding:20px 20px calc(20px + env(safe-area-inset-bottom))}
    .tq-modal__top{display:flex;align-items:center;gap:12px;margin-block-end:6px}

    /* product detail */
    .tq-pd-img{position:relative;border-radius:var(--tq-radius-lg);overflow:clip;aspect-ratio:1/1;background:var(--tq-ground)}
    .tq-pd-img img{width:100%;height:100%;object-fit:cover}
    /* detail-sheet thumbnail strip — tap a thumb to swap the main image */
    .tq-thumbs{display:flex;gap:8px;margin-block:10px 2px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
    .tq-thumbs::-webkit-scrollbar{height:0}
    .tq-thumb{flex:0 0 auto;width:58px;height:58px;border-radius:10px;overflow:hidden;border:2px solid transparent;padding:0;background:var(--tq-card-2);cursor:pointer}
    .tq-thumb img{width:100%;height:100%;object-fit:cover;display:block}
    .tq-thumb[aria-current="true"]{border-color:var(--tq-amber)}
    .tq-pd-name{font-family:var(--tq-f-head);font-weight:800;font-size:1.25rem;letter-spacing:.01em;text-transform:uppercase;
        line-height:1.08;margin-block-start:14px}
    .tq-pd-pn{font-family:var(--tq-f-mono);font-size:.74rem;color:var(--tq-ink-2);margin-block-start:6px}
    .tq-pd-price{font-family:var(--tq-f-head);font-weight:800;font-size:1.4rem;margin-block-start:8px;display:flex;align-items:baseline;gap:8px}
    .tq-pd-price .tq-note{font-family:var(--tq-f-sans);font-weight:400;font-size:.7rem;color:var(--tq-ink-3)}

    /* OEM/aftermarket toggle */
    .tq-toggle{display:flex;background:var(--tq-card-2);border-radius:10px;padding:3px;margin-block-start:12px;border:1px solid var(--tq-hair)}
    .tq-toggle button{flex:1;height:36px;border-radius:8px;font-size:.76rem;font-weight:600;color:var(--tq-ink-2);
        font-family:var(--tq-f-mono);border:0;background:none;cursor:pointer}
    .tq-toggle button[aria-pressed="true"]{background:var(--tq-card-3);color:var(--tq-ink);box-shadow:var(--tq-shadow-card)}

    /* fitment gauge */
    .tq-gauge{background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:var(--tq-radius-lg);padding:15px;
        margin-block-start:14px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
    .tq-gauge svg{width:100px;height:60px;flex:none}
    [dir="rtl"] .tq-gauge svg{transform:scaleX(-1)}
    .tq-needle{transform-origin:60px 56px;transition:transform 1.1s cubic-bezier(.2,.8,.2,1)}
    .tq-gauge .tq-gtext{flex:1;min-width:160px}
    .tq-gauge .tq-gt{font-family:var(--tq-f-head);font-weight:700;font-size:.86rem;text-transform:uppercase;letter-spacing:.02em}
    .tq-gauge .tq-gs{font-size:.78rem;color:var(--tq-ink-2);margin-block-start:3px;line-height:1.4}
    .tq-gauge.fit .tq-gt{color:var(--tq-fit)}
    .tq-gauge.verify .tq-gt{color:var(--tq-verify)}
    .tq-gauge.nofit .tq-gt{color:var(--tq-nofit)}
    .tq-gauge.unknown .tq-gt{color:var(--tq-ink-2)}
    .tq-interchange{font-family:var(--tq-f-mono);font-size:.7rem;color:var(--tq-cyan);margin-block-start:8px;
        display:flex;align-items:flex-start;gap:5px;line-height:1.5;flex-basis:100%}
    .tq-interchange svg{width:12px;height:12px;flex:none;margin-block-start:3px}

    /* spec sheet */
    .tq-specs{background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:var(--tq-radius-lg);margin-block-start:14px;overflow:clip}
    .tq-specs .tq-sh{font-family:var(--tq-f-head);font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
        padding:12px 15px;border-block-end:1px solid var(--tq-hair);color:var(--tq-ink-2);display:flex;align-items:center;gap:8px}
    .tq-specs .tq-sh svg{width:14px;height:14px;color:var(--tq-amber)}
    .tq-spec{display:flex;align-items:center;justify-content:space-between;padding:10px 15px;border-block-end:1px solid var(--tq-hair);font-size:.8rem;gap:10px}
    .tq-spec:last-child{border-block-end:none}
    .tq-spec .tq-k{color:var(--tq-ink-2)}
    .tq-spec .tq-v{font-family:var(--tq-f-mono);font-weight:500;text-align:end}
    .tq-diff{display:inline-flex;gap:3px}
    .tq-diff i{width:7px;height:7px;border-radius:50%;background:var(--tq-hair-2);display:block}
    .tq-diff i.on{background:var(--tq-amber)}

    /* variants + quantity + fields */
    .tq-group{margin-block-end:14px}
    .tq-group__label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--tq-ink-2);margin-block-end:7px}
    .tq-opts{display:flex;flex-wrap:wrap;gap:8px}
    .tq-opt{border:1px solid var(--tq-hair);background:var(--tq-card);color:inherit;border-radius:8px;padding:7px 12px;
        font-size:.8rem;cursor:pointer;font-family:var(--tq-f-sans)}
    .tq-opt.is-active{border-color:var(--tq-amber);background:color-mix(in srgb, var(--tq-amber) 14%, transparent);font-weight:600}
    .tq-opt:disabled{opacity:.4;text-decoration:line-through;cursor:not-allowed}
    .tq-qty{display:flex;align-items:center;gap:12px}
    .tq-qty button{width:32px;height:32px;border-radius:9px;border:1px solid var(--tq-hair-2);background:var(--tq-card);color:inherit;cursor:pointer;font-size:1rem}
    .tq-qty span{font-family:var(--tq-f-mono);min-width:22px;text-align:center;font-weight:600}
    .tq-field{display:block;width:100%;margin-block-end:10px}
    .tq-field label{display:block;font-size:.74rem;color:var(--tq-ink-2);margin-block-end:4px}
    .tq-field input,.tq-field select{width:100%;padding:10px 11px;border-radius:9px;border:1px solid var(--tq-hair);
        background:var(--tq-card);color:inherit;font-size:.86rem;font-family:var(--tq-f-sans)}
    /* Delivery-address autocomplete (crave-style geocode suggest + use-my-location). */
    .tq-addr{position:relative}
    .tq-addr input{padding-inline-end:42px}
    .tq-addr-loc{position:absolute;inset-inline-end:6px;top:6px;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;
        border:1px solid var(--tq-hair);border-radius:9px;background:var(--tq-card-2);color:var(--tq-ink-2);cursor:pointer;padding:0}
    .tq-addr-loc:hover{background:var(--tq-card-3);color:var(--tq-amber);border-color:var(--tq-hair-2)}
    .tq-addr-loc.is-loading{opacity:.5;pointer-events:none}
    .tq-addr-loc svg{width:18px;height:18px}
    .tq-addr-suggest{position:absolute;inset-inline:0;top:calc(100% + 5px);z-index:40;background:var(--tq-card);
        border:1px solid var(--tq-hair);border-radius:11px;overflow:hidden;box-shadow:0 12px 30px rgba(0,0,0,.28)}
    .tq-addr-suggest .mb-addr-opt{display:block;width:100%;text-align:start;padding:10px 13px;background:none;border:0;
        border-block-end:1px solid var(--tq-hair);color:var(--tq-ink);font-size:.82rem;font-family:var(--tq-f-sans);cursor:pointer}
    .tq-addr-suggest .mb-addr-opt:last-child{border-block-end:0}
    .tq-addr-suggest .mb-addr-opt:hover{background:var(--tq-card-2);color:var(--tq-amber)}
    .tq-msg{font-size:.8rem;padding:9px 11px;border-radius:9px;margin-block-end:10px;font-family:var(--tq-f-sans)}
    .tq-msg--error{background:var(--tq-nofit-bg);color:var(--tq-nofit-ink)}
    .tq-msg--ok{background:var(--tq-fit-bg);color:var(--tq-fit-ink)}
    .tq-slots{display:flex;flex-wrap:wrap;gap:8px;margin-block:6px}

    /* buttons */
    .tq-btn{border:0;border-radius:11px;padding:12px 16px;font-size:.82rem;font-weight:700;cursor:pointer;
        font-family:var(--tq-f-head);text-transform:uppercase;letter-spacing:.02em;display:inline-flex;align-items:center;
        justify-content:center;gap:8px;transition:transform .14s}
    .tq-btn:active{transform:scale(.98)}
    .tq-btn--primary{background:var(--tq-amber);color:var(--tq-amber-ink)}
    .tq-btn--ghost{background:var(--tq-card);border:1px solid var(--tq-hair-2);color:inherit}
    .tq-btn:disabled{opacity:.5;cursor:not-allowed}
    .tq-btn svg{width:16px;height:16px}
    .tq-actionbar{display:flex;gap:10px}

    /* cart */
    .tq-cart-line{background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:14px;padding:12px;margin-block-end:12px;display:flex;gap:12px}
    .tq-cart-line img{width:56px;height:56px;border-radius:10px;object-fit:cover;background:var(--tq-ground);flex:none}
    .tq-cart-line__body{flex:1;min-width:0}
    .tq-cart-line__name{font-family:var(--tq-f-head);font-weight:600;font-size:.86rem}
    .tq-cart-line__pn{font-family:var(--tq-f-mono);font-size:.66rem;color:var(--tq-ink-3);margin-block-start:2px}
    .tq-cart-line__foot{display:flex;align-items:center;justify-content:space-between;margin-block-start:8px}
    .tq-stepper{display:flex;align-items:center;gap:8px}
    .tq-step{width:26px;height:26px;border-radius:8px;border:1px solid var(--tq-hair-2);background:var(--tq-card);color:inherit;
        display:flex;align-items:center;justify-content:center;font-size:.9rem;cursor:pointer}
    .tq-cart-line__price{font-family:var(--tq-f-head);font-weight:700}
    .tq-cart-line__remove{background:transparent;border:0;color:var(--tq-nofit);cursor:pointer;font-size:.72rem;padding:0;margin-block-start:6px;font-family:var(--tq-f-sans)}
    .tq-nb-proxy{position:fixed;top:0;inset-inline-start:0;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;opacity:0;pointer-events:none;border:0}
    .tq-notif-dot{position:absolute;top:6px;inset-inline-end:6px;width:8px;height:8px;border-radius:50%;background:var(--tq-amber);border:2px solid var(--tq-card);box-sizing:content-box}
    .tq-notif-dot[hidden]{display:none}
    .mb-nb-card{--nb-surface:var(--tq-card);--nb-surface-2:var(--tq-card-2);--nb-ink:var(--tq-ink);--nb-muted:var(--tq-ink-3);--nb-line:var(--tq-hair);--nb-accent:var(--tq-amber);--nb-radius:12px}
    #tqApp .mb-nb-panel{opacity:0}
    #tqApp .mb-nb-panel.is-open{opacity:1}
    .tq-orders-h{font-family:var(--tq-f-mono);font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--tq-ink-3);margin:20px 0 10px}
    .tq-order{border:1px solid var(--tq-hair);border-radius:12px;padding:13px 15px;margin-bottom:10px}
    .tq-order-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .tq-order-status{font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:100px;background:var(--tq-card-2);color:var(--tq-ink);font-family:var(--tq-f-mono)}
    .tq-order-meta{font-size:.78rem;color:var(--tq-ink-3);margin-top:6px}
    .tq-order-track{display:inline-block;margin-top:10px;font-size:.82rem;font-weight:700;color:var(--tq-amber);text-decoration:none}
    .tq-order-actions{display:flex;gap:8px;margin-top:12px}
    .tq-order-btn{flex:1;text-align:center;height:38px;line-height:38px;border-radius:11px;font-family:var(--tq-f-head);text-transform:uppercase;letter-spacing:.02em;font-size:.8rem;font-weight:700;text-decoration:none;color:var(--tq-ink);background:var(--tq-card);border:1px solid var(--tq-hair);cursor:pointer;transition:border-color .14s,transform .14s}
    .tq-order-btn:hover{border-color:var(--tq-amber)}
    .tq-order-btn:active{transform:scale(.98)}
    .tq-order-btn--p{background:var(--tq-amber);color:var(--tq-amber-ink);border-color:var(--tq-amber)}
    .tq-order-btn--p:hover{border-color:var(--tq-amber)}
    .tq-orders-empty{font-size:.82rem;color:var(--tq-ink-3);padding:4px 0}
    .tq-couponbox{margin-block:6px 4px}
    .tq-couponrow{display:flex;gap:8px}
    .tq-coupon-input{flex:1;height:48px;border-radius:12px;border:1px solid var(--tq-hair);background:var(--tq-card);padding:0 14px;font:inherit;font-size:.9rem;color:var(--tq-ink)}
    .tq-coupon-apply{flex:none}
    .tq-coupon-msg{font-size:.75rem;font-weight:600;margin:7px 0 0;color:#3bbb73;font-family:var(--tq-f-mono)}
    .tq-coupon-msg.is-bad{color:#e2554e}
    .tq-summary{background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:var(--tq-radius-lg);padding:15px;margin-block-start:6px}
    .tq-srow{display:flex;justify-content:space-between;font-size:.8rem;color:var(--tq-ink-2);padding:5px 0;font-family:var(--tq-f-mono)}
    .tq-srow.total{color:var(--tq-ink);font-weight:700;font-size:.94rem;border-block-start:1px solid var(--tq-hair);
        margin-block-start:6px;padding-block-start:11px;font-family:var(--tq-f-head)}
    .tq-core-note{font-size:.68rem;color:var(--tq-ink-3);margin:6px 0 0;font-family:var(--tq-f-mono);line-height:1.5}
    .tq-kit{background:var(--tq-card);border:1px dashed var(--tq-hair-2);border-radius:14px;padding:13px;margin-block-end:14px}
    .tq-kit h4{font-family:var(--tq-f-head);font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;margin:0 0 4px;color:var(--tq-amber)}
    .tq-kit p{font-size:.76rem;color:var(--tq-ink-2);margin:0 0 9px}

    /* checkout options */
    .tq-copt{width:100%;background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:12px;padding:12px;
        display:flex;align-items:center;gap:10px;margin-block-end:8px;text-align:start;cursor:pointer;color:inherit}
    .tq-copt[aria-pressed="true"]{border-color:var(--tq-amber)}
    .tq-copt .tq-oi{width:34px;height:34px;border-radius:9px;background:var(--tq-card-2);display:flex;align-items:center;
        justify-content:center;color:inherit;flex:none}
    .tq-copt .tq-oi svg{width:17px;height:17px}
    .tq-copt .tq-ob{flex:1;min-width:0}
    .tq-copt .tq-ot{font-weight:600;font-size:.82rem}
    .tq-copt .tq-os{font-family:var(--tq-f-mono);font-size:.68rem;color:var(--tq-ink-2);margin-block-start:1px}
    .tq-radio{width:18px;height:18px;border-radius:50%;border:2px solid var(--tq-hair-2);flex:none;position:relative}
    .tq-copt[aria-pressed="true"] .tq-radio{border-color:var(--tq-amber)}
    .tq-copt[aria-pressed="true"] .tq-radio::after{content:'';position:absolute;inset:3px;border-radius:50%;background:var(--tq-amber)}

    /* confirm */
    .tq-confwrap{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 10px}
    .tq-checkc{width:78px;height:78px}
    .tq-checkc circle{fill:none;stroke:var(--tq-amber);stroke-width:4;stroke-dasharray:283;stroke-dashoffset:283;animation:tqDraw .6s ease forwards}
    .tq-checkc path{fill:none;stroke:var(--tq-fit);stroke-width:5;stroke-linecap:round;stroke-linejoin:round;
        stroke-dasharray:60;stroke-dashoffset:60;animation:tqDraw .4s .5s ease forwards}
    @keyframes tqDraw{to{stroke-dashoffset:0}}
    .tq-confwrap h3{font-family:var(--tq-f-head);font-weight:800;text-transform:uppercase;font-size:1.1rem;margin:14px 0 4px}
    .tq-confwrap p{color:var(--tq-ink-2);font-size:.8rem;margin:0}
    .tq-orderno{font-family:var(--tq-f-mono);margin-block-start:8px;color:var(--tq-cyan);font-size:.82rem}
    .tq-conf-track{width:100%;margin-block-start:20px}

    /* VIN */
    .tq-vinframe{position:relative;border-radius:var(--tq-radius-lg);overflow:clip;aspect-ratio:16/10;background:var(--tq-card-2);
        display:flex;align-items:center;justify-content:center;border:1px solid var(--tq-hair)}
    .tq-vinframe svg.tq-car-silhouette{width:62%;height:auto;color:var(--tq-hair-2);opacity:.7}
    .tq-vin-corners{position:absolute;inset:22px;pointer-events:none}
    .tq-vin-corners span{position:absolute;width:26px;height:26px;border:3px solid var(--tq-amber)}
    .tq-vin-corners span:nth-child(1){top:0;inset-inline-start:0;border-inline-end:none;border-block-end:none}
    .tq-vin-corners span:nth-child(2){top:0;inset-inline-end:0;border-inline-start:none;border-block-end:none}
    .tq-vin-corners span:nth-child(3){bottom:0;inset-inline-start:0;border-inline-end:none;border-block-start:none}
    .tq-vin-corners span:nth-child(4){bottom:0;inset-inline-end:0;border-inline-start:none;border-block-start:none}
    .tq-vin-scan{position:absolute;inset-inline:22px;height:2px;background:var(--tq-amber);box-shadow:0 0 12px var(--tq-amber);animation:tqVscan 2s ease-in-out infinite}
    @keyframes tqVscan{0%{top:24px}50%{top:calc(100% - 24px)}100%{top:24px}}
    .tq-vin-input{width:100%;margin-block-start:14px;height:50px;border-radius:12px;border:1px solid var(--tq-hair);
        background:var(--tq-card);color:var(--tq-ink);font-family:var(--tq-f-mono);font-size:.95rem;letter-spacing:.08em;
        text-align:center;text-transform:uppercase;padding:0 12px}
    .tq-vin-read{font-family:var(--tq-f-mono);font-size:.76rem;color:var(--tq-cyan);text-align:center;margin-block-start:10px;
        letter-spacing:.04em;min-height:16px}
    .tq-vin-result{background:var(--tq-card);border:1px solid var(--tq-fit);border-radius:14px;padding:13px;margin-block-start:12px;
        display:none;align-items:center;gap:12px}
    .tq-vin-result.show{display:flex}
    .tq-vin-result .tq-vico{width:38px;height:38px;border-radius:10px;background:var(--tq-fit-bg);color:var(--tq-fit);
        display:flex;align-items:center;justify-content:center;flex:none}
    .tq-vin-result .tq-vico svg{width:19px;height:19px}

    /* wizard */
    .tq-wz-prog{display:flex;gap:6px;margin-block-end:16px}
    .tq-wz-prog i{flex:1;height:4px;border-radius:3px;background:var(--tq-hair-2);display:block}
    .tq-wz-prog i.on{background:var(--tq-amber)}
    .tq-wz-sub{color:var(--tq-ink-2);font-size:.8rem;margin:4px 0 16px}
    .tq-wz-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;overflow-y:auto;padding-block-end:6px}
    .tq-wz-opt{background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:12px;padding:15px 12px;
        font-family:var(--tq-f-head);font-weight:700;font-size:.88rem;text-align:center;transition:transform .12s,border-color .12s;
        color:inherit;cursor:pointer}
    .tq-wz-opt:active{transform:scale(.96)}
    .tq-wz-opt:hover{border-color:var(--tq-amber)}
    .tq-wz-list{display:flex;flex-direction:column;gap:10px;overflow-y:auto}
    .tq-wz-list .tq-wz-opt{display:flex;align-items:center;justify-content:space-between;text-align:start}
    .tq-wz-list .tq-wz-opt .tq-chev{color:var(--tq-ink-3)}

    /* garage */
    .tq-gcard{background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:14px;padding:13px;margin-block-end:12px;
        display:flex;gap:12px;align-items:center;width:100%;text-align:start;cursor:pointer;color:inherit}
    .tq-gcard[aria-pressed="true"]{border-color:var(--tq-amber)}
    .tq-gcard .tq-gico{width:52px;height:44px;border-radius:9px;background:var(--tq-card-2);flex:none;display:flex;
        align-items:center;justify-content:center;color:var(--tq-ink-2)}
    .tq-gcard .tq-gico svg{width:24px;height:24px}
    .tq-gcard .tq-gi{flex:1;min-width:0}
    .tq-gcard .tq-gn{font-family:var(--tq-f-head);font-weight:700;font-size:.86rem}
    .tq-gcard .tq-gs{font-family:var(--tq-f-mono);font-size:.68rem;color:var(--tq-cyan);margin-block-start:2px}
    .tq-active-b{font-family:var(--tq-f-mono);font-size:.62rem;color:var(--tq-fit);border:1px solid rgba(53,208,126,.3);
        background:var(--tq-fit-bg);padding:3px 8px;border-radius:100px;flex:none}
    .tq-gcard-remove{background:none;border:0;color:var(--tq-ink-3);cursor:pointer;padding:6px;flex:none}
    .tq-gcard-remove svg{width:15px;height:15px}

    /* toast */
    .tq-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);background:var(--tq-card-3);
        border:1px solid var(--tq-hair-2);color:var(--tq-ink);padding:12px 18px;border-radius:12px;font-size:.82rem;font-weight:600;
        opacity:0;transition:opacity .25s,transform .25s;z-index:40;white-space:nowrap;display:flex;align-items:center;gap:8px;
        font-family:var(--tq-f-mono)}
    .tq-toast svg{width:15px;height:15px;color:var(--tq-amber)}
    .tq-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

    [hidden]{display:none !important}
    @media (prefers-reduced-motion: reduce){
        *{animation-duration:.001s !important;transition-duration:.001s !important}
        .tq-hot .pulse,.tq-vin-scan{animation:none !important;opacity:0}
    }
    @media (max-width: 620px){
        .tq-sheet{width:100%}
    }

    /* =====================================================================
       Curated core features (loop/enrich) — stories, reviews, loyalty,
       wishlist. Every endpoint reused here is an existing, already-seeded
       platform route; nothing below adds or changes backend behaviour.
       ===================================================================== */

    /* ---- stories rail + full-screen viewer ---- */
    .tq-stories{display:flex;gap:14px;overflow-x:auto;overflow-y:clip;padding:4px 2px 14px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch;scrollbar-width:none}
    .tq-stories::-webkit-scrollbar{display:none}
    .tq-story{flex:0 0 auto;display:flex;flex-direction:column;align-items:center;gap:6px;width:70px;scroll-snap-align:start;
        background:transparent;border:0;padding:0;cursor:pointer;color:inherit;text-align:center;font:inherit}
    .tq-story__ring{width:62px;height:62px;border-radius:50%;padding:2.5px;flex:0 0 auto;
        background:linear-gradient(135deg,var(--tq-amber),var(--tq-cyan));display:flex;align-items:center;justify-content:center}
    .tq-story.is-viewed .tq-story__ring{background:var(--tq-hair-2)}
    .tq-story__thumb{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;
        border:2px solid var(--tq-ground);background:var(--tq-card-2)}
    .tq-story__label{font-size:.66rem;font-weight:600;color:var(--tq-ink-2);max-width:100%;overflow:clip;
        text-overflow:ellipsis;white-space:nowrap}

    .tq-story-viewer{position:fixed;inset:0;z-index:60;background:#000;display:none;flex-direction:column}
    .tq-story-viewer.is-open{display:flex}
    .tq-story-viewer__progress{display:flex;gap:4px;padding:12px 12px 0;flex:0 0 auto}
    .tq-story-viewer__bar{flex:1;height:3px;border-radius:999px;background:rgba(255,255,255,.3);overflow:clip}
    .tq-story-viewer__bar-fill{display:block;height:100%;background:var(--tq-amber);inline-size:0}
    .tq-story-viewer__bar.is-done .tq-story-viewer__bar-fill{inline-size:100%}
    .tq-story-viewer__bar.is-active .tq-story-viewer__bar-fill{transition:inline-size linear}
    .tq-story-viewer__head{display:flex;align-items:center;justify-content:flex-end;padding:10px 10px 0;flex:0 0 auto}
    .tq-story-viewer__close{border:0;background:rgba(255,255,255,.16);color:#fff;width:36px;height:36px;border-radius:50%;
        display:flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto}
    .tq-story-viewer__close svg{width:16px;height:16px}
    .tq-story-viewer__stage{position:relative;flex:1;display:flex;align-items:center;justify-content:center;overflow:clip;min-height:0}
    .tq-story-viewer__media{max-width:100%;max-height:100%;object-fit:contain}
    .tq-story-viewer__zone{position:absolute;inset-block:0;width:50%;cursor:pointer;background:transparent;border:0;padding:0}
    .tq-story-viewer__zone--prev{inset-inline-start:0}
    .tq-story-viewer__zone--next{inset-inline-end:0}
    .tq-story-viewer__foot{position:absolute;inset-inline:0;inset-block-end:0;padding:18px 18px calc(18px + env(safe-area-inset-bottom,0px));
        background:linear-gradient(to top,rgba(0,0,0,.75),transparent);color:#fff;display:flex;flex-direction:column;gap:12px;pointer-events:none}
    .tq-story-viewer__caption{font-size:.9rem;line-height:1.5;margin:0;text-shadow:0 1px 3px rgba(0,0,0,.5)}
    .tq-story-viewer__cta{align-self:flex-start;border:0;border-radius:9px;padding:11px 20px;font-weight:700;font-size:.82rem;
        font-family:var(--tq-f-head);text-transform:uppercase;letter-spacing:.02em;
        background:var(--tq-amber);color:var(--tq-amber-ink);cursor:pointer;text-decoration:none;display:inline-block;pointer-events:auto}

    /* ---- reviews (product detail sheet) ---- */
    .tq-rv-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-block-end:10px}
    .tq-rv-avg{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
    .tq-rv-avg b{font-family:var(--tq-f-head);font-size:1.4rem;font-weight:800}
    .tq-rv-stars{color:var(--tq-amber);letter-spacing:1px;font-size:.9rem;white-space:nowrap}
    .tq-rv-stars .is-off{color:var(--tq-hair-2)}
    .tq-rv-count{font-size:.78rem;color:var(--tq-ink-2)}
    .tq-rv-write-btn{border:1px solid var(--tq-hair-2);background:var(--tq-card);color:var(--tq-amber);
        border-radius:100px;padding:8px 14px;font-size:.76rem;font-weight:700;cursor:pointer;flex:0 0 auto;
        font-family:var(--tq-f-sans)}
    .tq-rv-dist{display:grid;gap:5px;margin-block-end:16px}
    .tq-rv-dist-row{display:grid;grid-template-columns:1.1em 1fr 1.6em;align-items:center;gap:8px;font-size:.72rem;
        color:var(--tq-ink-2);font-family:var(--tq-f-mono)}
    .tq-rv-dist-row i{display:block;height:6px;border-radius:999px;background:var(--tq-hair);overflow:clip}
    .tq-rv-dist-row i s{display:block;height:100%;background:var(--tq-amber);text-decoration:none}
    .tq-rv-list{display:flex;flex-direction:column;gap:14px;margin-block-end:6px}
    .tq-rv-item{padding-block-end:14px;border-block-end:1px solid var(--tq-hair)}
    .tq-rv-item:last-child{border-block-end:0;padding-block-end:0}
    .tq-rv-item__top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-block-end:4px;flex-wrap:wrap}
    .tq-rv-item__name{font-weight:700;font-size:.84rem;font-family:var(--tq-f-head)}
    .tq-rv-item__date{font-size:.7rem;color:var(--tq-ink-3);font-family:var(--tq-f-mono)}
    .tq-rv-item__body{font-size:.84rem;line-height:1.5;color:var(--tq-ink-2);margin:4px 0 0;overflow-wrap:anywhere}
    .tq-rv-verified{display:inline-flex;align-items:center;gap:4px;font-size:.64rem;font-weight:700;color:var(--tq-fit);
        margin-inline-start:6px;font-family:var(--tq-f-mono);text-transform:uppercase}
    .tq-rv-empty{padding:14px 0 18px;text-align:center;color:var(--tq-ink-2);font-size:.84rem}
    .tq-rv-form{display:none;gap:10px;margin-block-start:8px;padding-block-start:14px;border-block-start:1px solid var(--tq-hair)}
    .tq-rv-form.is-open{display:grid}
    .tq-rv-starpicker{display:flex;gap:6px}
    .tq-rv-starpicker button{border:0;background:none;font-size:1.5rem;line-height:1;padding:0;cursor:pointer;color:var(--tq-hair-2)}
    .tq-rv-starpicker button.is-on{color:var(--tq-amber)}

    /* ---- loyalty: Rewards card (account sheet) + checkout redeem row ---- */
    .tq-rewards-card{background:linear-gradient(135deg,var(--tq-card-2),var(--tq-card));
        border:1px solid var(--tq-hair-2);border-radius:var(--tq-radius-lg);padding:20px;margin-block-end:16px}
    .tq-rewards-card__top{display:flex;align-items:center;gap:12px;margin-block-end:4px}
    .tq-rewards-card__ico{width:44px;height:44px;border-radius:11px;background:var(--tq-card-3);color:var(--tq-amber);
        display:flex;align-items:center;justify-content:center;flex:0 0 auto}
    .tq-rewards-card__ico svg{width:21px;height:21px}
    .tq-rewards-card__program{font-family:var(--tq-f-head);font-weight:800;font-size:.98rem;text-transform:uppercase;letter-spacing:.02em}
    .tq-rewards-card__line{font-size:.8rem;color:var(--tq-ink-2);margin-block-start:2px}
    .tq-rewards-card__balance{font-family:var(--tq-f-head);font-size:1.6rem;font-weight:800;margin:12px 0 0;color:var(--tq-amber)}
    .tq-rewards-card__balance small{font-family:var(--tq-f-sans);font-size:.7rem;font-weight:600;color:var(--tq-ink-2);text-transform:none;letter-spacing:0}
    .tq-tierchips{display:flex;gap:8px;margin-block-start:14px;flex-wrap:wrap}
    .tq-tierchip{flex:1 1 0;min-width:80px;text-align:center;padding:8px 6px;border-radius:9px;font-size:.7rem;font-weight:700;
        background:var(--tq-card);border:1px solid var(--tq-hair-2);color:var(--tq-ink-2);font-family:var(--tq-f-mono);text-transform:uppercase}
    .tq-tierchip.is-current{background:var(--tq-amber);border-color:var(--tq-amber);color:var(--tq-amber-ink)}
    .tq-rewards-signin{font-size:.8rem;color:var(--tq-amber);margin-block-start:12px;display:flex;align-items:center;gap:6px}
    .tq-rewards-signin svg{width:16px;height:16px;flex:0 0 auto}
    .tq-rewards-progress{height:6px;border-radius:999px;background:var(--tq-hair);margin-block-start:14px;overflow:clip}
    .tq-rewards-progress s{display:block;height:100%;background:var(--tq-amber);text-decoration:none}

    .tq-loyalty-row{border:1px dashed var(--tq-hair-2);border-radius:12px;padding:12px 14px;margin-block-end:14px;font-size:.82rem}
    .tq-loyalty-row__teaser{color:var(--tq-ink-2);line-height:1.5}
    .tq-loyalty-row__signin{color:var(--tq-amber);font-weight:600;margin-block-start:4px}
    .tq-loyalty-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer;
        background:none;border:0;width:100%;padding:0;color:inherit;font:inherit;text-align:start}
    .tq-loyalty-toggle__label{font-weight:700}
    .tq-loyalty-toggle__switch{width:40px;height:24px;border-radius:999px;background:var(--tq-hair-2);position:relative;flex:0 0 auto;transition:background .15s ease}
    .tq-loyalty-toggle__switch::after{content:'';position:absolute;inset-block-start:3px;inset-inline-start:3px;width:18px;height:18px;
        border-radius:50%;background:#fff;transition:inset-inline-start .15s ease;box-shadow:0 1px 3px rgba(0,0,0,.35)}
    .tq-loyalty-toggle.is-on .tq-loyalty-toggle__switch{background:var(--tq-amber)}
    .tq-loyalty-toggle.is-on .tq-loyalty-toggle__switch::after{inset-inline-start:19px}

    /* ---- wishlist: card heart + "Saved" sheet ---- */
    .tq-fav-btn{position:absolute;inset-block-end:6px;inset-inline-end:6px;width:30px;height:30px;border-radius:50%;
        border:0;padding:0;cursor:pointer;display:flex;align-items:center;justify-content:center;
        background:color-mix(in srgb, var(--tq-ground) 78%, transparent);color:var(--tq-ink-2);z-index:2;backdrop-filter:blur(4px)}
    .tq-fav-btn svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2}
    .tq-fav-btn.is-fav{color:var(--tq-nofit)}
    .tq-fav-btn.is-fav svg{fill:currentColor}
    .tq-saved-line{display:flex;gap:12px;padding:12px 0;border-block-end:1px solid var(--tq-hair);align-items:center}
    .tq-saved-line:last-child{border-block-end:0}
    .tq-saved-line img{width:58px;height:58px;border-radius:11px;object-fit:cover;background:var(--tq-ground);flex:0 0 auto}
    .tq-saved-line__body{flex:1;min-width:0}
    .tq-saved-line__name{font-weight:700;font-size:.86rem;font-family:var(--tq-f-head)}
    .tq-saved-line__price{font-weight:700;font-size:.84rem;margin-block-start:2px;color:var(--tq-amber);font-family:var(--tq-f-head)}
    .tq-saved-line__actions{display:flex;flex-direction:column;gap:6px;flex:0 0 auto;align-items:stretch}
    .tq-saved-line__actions button{white-space:nowrap;font-size:.72rem;padding:7px 12px}

    /* =====================================================================
       DESKTOP RESPONSIVE LAYER — additive only. Nothing below 768px changes:
       every rule here lives inside a `min-width` media query, and the new
       `.tq-hero`/`.tq-hero-copy`/`.tq-browse`/`.tq-sidebar`/`.tq-catalog`
       wrapper <div>s added to the markup are plain unstyled blocks with no
       rule of their own outside these queries, so margin-collapsing passes
       straight through them and mobile/tablet layout is unaffected.
       All colors reuse existing --tq-* custom properties, so dark mode /
       [data-theme="light"] keep working with zero extra tokens. Grid column
       order (sidebar first, catalog second / copy first, visual second)
       flips automatically under [dir="rtl"] via CSS Grid's own direction-
       aware track placement — no extra RTL overrides needed here. */

    @media (min-width: 768px){
        .tq{--tq-pad:24px}
        .tq-grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px}
        .tq-cats{grid-template-columns:repeat(auto-fill,minmax(100px,1fr))}
    }

    @media (min-width: 1024px){
        /* shell + top nav */
        .tq{--tq-pad:32px}
        .tq-head{padding-inline:max(20px, calc((100% - 1180px) / 2));padding-block:16px}

        /* hero: copy/search left, exploded diagram right */
        .tq-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,420px);align-items:center;gap:40px;margin-block-end:8px}
        .tq-hero-copy{display:block;min-width:0}
        .tq-hero #tqDiagram{margin-block-end:0}
        .tq-diagram{padding:24px 24px 20px}
        .tq-carwrap svg{max-height:260px}

        /* maintenance band — a touch more breathing room at desktop */
        #tqMaint{padding:22px 26px}

        /* store browse: sticky left sidebar (categories) + catalog right */
        .tq-browse{display:grid;grid-template-columns:250px 1fr;column-gap:32px;align-items:start}
        .tq-sidebar{display:block;min-width:0;position:sticky;top:88px}
        .tq-catalog{display:block;min-width:0}
        .tq-sidebar #tqCatTiles{grid-template-columns:repeat(auto-fill,minmax(76px,1fr));gap:8px}
        .tq-sidebar #tqChips{flex-direction:column;overflow-x:visible;overflow-y:visible;align-items:stretch;gap:6px;padding:4px 0 0}
        .tq-sidebar #tqChips .tq-chip{width:100%;height:auto;padding:10px 14px;border-radius:10px;text-align:start}

        /* product grid: generous cards, not cramped */
        .tq-grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px}

        /* off-canvas sheets: a bit wider for desktop proportions */
        .tq-sheet{width:min(480px,92vw)}

        /* product detail sheet: gallery/image left (sticky), buy-box right */
        #tqItemSheet{width:min(760px,90vw)}
        #tqItemSheet .tq-sheet__body{display:grid;grid-template-columns:minmax(240px,320px) 1fr;column-gap:28px;align-items:start}
        /* one cell per column so the gallery's rows can't push the buy-box down
           (a bare image+thumbs in col1 advanced the auto-placement cursor, landing
           the title in row 2 with dead space above it) */
        #tqItemSheet .tq-sheet__body > #tqItemGallery{grid-column:1;position:sticky;top:0}
        #tqItemSheet .tq-sheet__body > #tqItemBuyBox{grid-column:2;min-width:0}
        #tqItemSheet .tq-pd-name{margin-block-start:0}

        /* cart sheet: line items left, sticky order-summary right */
        #tqCartSheet{width:min(720px,90vw)}
        #tqCartView{display:grid;grid-template-columns:1fr 320px;column-gap:24px;align-items:start}
        #tqCartView > #tqCartSummary{grid-column:2;position:sticky;top:0}
        #tqCartView > *:not(#tqCartSummary){grid-column:1;min-width:0}
        /* checkout form: name/phone side-by-side, everything else full-width */
        #tqCheckoutForm{display:grid;grid-template-columns:1fr 1fr;column-gap:14px}
        #tqCheckoutForm > .tq-field{grid-column:1 / -1}
        #tqCheckoutForm > .tq-field--half{grid-column:span 1}
        #tqCheckoutForm > #tqCheckoutMsg,#tqCheckoutForm > #tqLoyaltyRow,#tqCheckoutForm > #tqPlaceOrder{grid-column:1 / -1}
        /* confirm step: comfortable reading measure inside the wider sheet */
        #tqConfirmView .tq-confwrap{max-width:420px;margin-inline:auto}

        /* VIN scan + Y/M/M/E wizard modals: roomier column, generous tiles */
        .tq-modal__inner{max-width:600px}
        .tq-wz-grid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr))}
    }

    @media (min-width: 1280px){
        .tq{max-width:1320px;--tq-pad:40px}
        .tq-head{padding-inline:max(20px, calc((100% - 1320px) / 2))}
        .tq-browse{grid-template-columns:270px 1fr}
        .tq-grid{grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:20px}
    }
</style>
@endpush

@section('content')
<div id="tqApp" data-store-id="{{ $store->id }}" data-theme-slug="torque">
    <header class="tq-head">
        <div class="tq-brand">
            <span class="tq-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/></svg>
            </span>
            <div class="tq-brandtext">
                <span class="tq-title">{{ $store->title }}</span>
            </div>
        </div>
        @if($tqLanguages->count() > 1)
            <label class="tq-langsel" aria-label="{{ ___('Language') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
                <select id="tqLangSel">
                    @foreach($tqLanguages as $lang)
                        <option value="{{ $lang->code }}" data-url="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}" @if(get_lang() === $lang->code) selected @endif>{{ strtoupper($lang->code) }}</option>
                    @endforeach
                </select>
            </label>
            <script nonce="{{ csp_nonce() }}">
                (function(){var s=document.getElementById('tqLangSel');if(!s)return;s.addEventListener('change',function(e){var o=e.target.selectedOptions[0];if(!o)return;var code=o.value;try{document.cookie='Quick_user_lang_code='+code+';path=/;max-age=31536000;samesite=lax';document.cookie='qc_lang_choice='+code+';path=/;max-age=31536000;samesite=lax';}catch(err){}window.location.assign(o.getAttribute('data-url')||window.location.href);});})();
            </script>
        @endif
        <button type="button" class="tq-iconbtn tq-notifbell" id="tqNotifBell" aria-label="{{ ___('Notifications') }}" title="{{ ___('Notifications') }}" style="position:relative">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
            <i class="tq-notif-dot" hidden aria-hidden="true"></i>
        </button>
        <button type="button" class="tq-iconbtn" id="tqShareBtn" aria-label="{{ ___('Share this store') }}" title="{{ ___('Share this store') }}" data-share-url="{{ url()->current() }}" data-share-title="{{ $store->title }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
        </button>
        <button type="button" class="tq-iconbtn" id="tqSavedOpen" aria-label="{{ ___('Saved parts') }}" title="{{ ___('Saved parts') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
            <i class="tq-badge" id="tqSavedBadge" hidden>0</i>
        </button>
        <button type="button" class="tq-iconbtn" id="tqAccountOpen" aria-label="{{ ___('Account') }}" title="{{ ___('Account') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
        </button>
        <button type="button" class="tq-iconbtn" id="tqDarkToggle" aria-label="{{ ___('Toggle dark mode') }}" title="{{ ___('Toggle dark mode') }}">
            <svg id="tqDarkIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/></svg>
        </button>
        <button type="button" class="tq-iconbtn" id="tqCartOpen" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg>
            <i class="tq-badge" id="tqCartBadge" hidden>0</i>
        </button>
    </header>

    <main class="tq" id="tq-main">
        {{-- Desktop hero band (≥1024px): copy/search left, exploded diagram
             right. `.tq-hero` / `.tq-hero-copy` are plain unstyled block <div>s
             below 1024px (margin-collapsing passes straight through them, so
             mobile/tablet flow stays byte-for-byte identical) and only become
             a 2-col grid at desktop — see the desktop CSS layer near the end
             of this style block. --}}

        {{-- Curated core feature: stories rail (public, ungated feed) — a
             circular bubble rail above the hero/VIN-search. Starts [hidden];
             JS fetches the feed and only reveals it when non-empty. --}}
        <div class="tq-stories" id="tqStories" role="list" aria-label="{{ ___('Stories') }}" hidden></div>

        <div class="tq-hero">
        <div class="tq-hero-copy">
        <h1 class="tq-h1">{{ ___('Find the right part') }}<br><span class="dim">{{ ___('for your ride') }}</span></h1>

        {{-- Crave-parity status row: Open-Now chip + (multi-store) branch switch. --}}
        <div class="tq-statusrow">
            <span class="tq-openchip @if(! $tqIsOpen) is-closed @endif" data-mb-openchip data-mb-closed-class="is-closed">
                <i aria-hidden="true"></i><span data-mb-open-text>{{ $tqIsOpen ? ___('Open now') : ___('Closed') }}@if($tqHoursToday) · {{ $tqHoursToday }}@endif</span>
            </span>
            @if($tqShowBranches)
                <label class="tq-branchsel" aria-label="{{ ___('Branch') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    <select id="tqBranchSel">
                        @foreach($tqBranches as $branch)
                            <option value="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}" @if(($tqActiveBranch->id ?? null) === $branch->id) selected @endif>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>

        @if($fitment)
        <div class="tq-hud" id="tqHud">
            <div class="tq-vico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 16l1.5-5A2 2 0 018.4 9.6h7.2a2 2 0 011.9 1.4L19 16M4 16h16v3h-2v-1H6v1H4z"/><circle cx="7.5" cy="16.5" r="1.2"/><circle cx="16.5" cy="16.5" r="1.2"/></svg>
            </div>
            <div class="tq-vinfo">
                <div class="tq-vlabel">{{ ___('Your vehicle') }}</div>
                <div class="tq-vname" id="tqHudName">{{ ___('No vehicle saved') }}</div>
                <div class="tq-vspec" id="tqHudSpec"></div>
            </div>
            <button type="button" class="tq-change" id="tqHudChange">{{ ___('Change') }}</button>
        </div>
        @endif

        <div class="tq-search-row">
            <div class="tq-search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
                <input type="search" class="tq-search-input" id="tqSearch" autocomplete="off"
                       placeholder="{{ ___('Search parts or part number') }}" aria-label="{{ ___('Search parts or part number') }}">
            </div>
            @if($brands->isNotEmpty())
            <select class="tq-brand-select" id="tqBrandFilter" aria-label="{{ ___('Brand') }}">
                <option value="">{{ ___('All brands') }}</option>
                @foreach($brands as $b)
                    <option value="{{ Str::lower($b) }}">{{ $b }}</option>
                @endforeach
            </select>
            @endif
            @if($fitment)
            <button type="button" class="tq-scan-btn" id="tqScanBtn" aria-label="{{ ___('Scan VIN') }}" title="{{ ___('Scan VIN') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V5a1 1 0 011-1h3M16 4h3a1 1 0 011 1v3M20 16v3a1 1 0 01-1 1h-3M8 20H5a1 1 0 01-1-1v-3"/><path d="M4 12h16"/></svg>
            </button>
            @endif
        </div>
        </div>

        <div class="tq-diagram" id="tqDiagram">
            <div class="tq-eyebrow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/></svg>
                {{ ___('Exploded view') }}
            </div>
            <h3>{{ ___('Shop by diagram') }}</h3>
            <p>{{ ___('Tap a zone to see its parts') }}</p>
            <div class="tq-carwrap" id="tqCarwrap">
                <svg viewBox="0 0 100 56" preserveAspectRatio="xMidYMid meet" role="img" aria-label="{{ ___('Vehicle diagram') }}">
                    <path d="M6 40 L14 40 C16 31 22 26 32 25 L44 25 C50 20 60 18 70 20 L82 24 C90 26 94 31 94 40 L88 40" fill="none" stroke="var(--tq-ink-3)" stroke-width="1.4"/>
                    <path d="M6 40 H94" stroke="var(--tq-hair-2)" stroke-width="1"/>
                    <path d="M34 25 L44 25 C49 21 56 19 63 20 L60 25 Z" fill="var(--tq-card-2)" stroke="var(--tq-ink-3)" stroke-width="0.8"/>
                    <circle cx="24" cy="41" r="7.5" fill="var(--tq-card-2)" stroke="var(--tq-ink-3)" stroke-width="1.4"/><circle cx="24" cy="41" r="3" fill="none" stroke="var(--tq-ink-3)" stroke-width="1"/>
                    <circle cx="78" cy="41" r="7.5" fill="var(--tq-card-2)" stroke="var(--tq-ink-3)" stroke-width="1.4"/><circle cx="78" cy="41" r="3" fill="none" stroke="var(--tq-ink-3)" stroke-width="1"/>
                    <g id="tqHotspots"></g>
                </svg>
            </div>
        </div>
        </div>

        @if($tqBanners->count())
            <div class="tq-banners" id="tqBanners">
                <div class="tq-banner-track" id="tqBannerTrack">
                    @foreach($tqBanners as $b)
                        @php
                            // A real external link opens in a new tab; anything else
                            // (empty, or an in-app "#…" marker) becomes a JS-intercepted
                            // jump to the catalog so a banner is never a dead tap.
                            $bLink = trim((string) $b->link_url);
                            $bIsUrl = $bLink !== '' && \Illuminate\Support\Str::startsWith($bLink, ['http://', 'https://']);
                        @endphp
                        <a class="tq-banner" @if($bIsUrl) href="{{ storefront_localized_link($bLink) }}" target="_blank" rel="noopener" @else href="#" data-banner-catalog="1" @endif>
                            @if($b->image)<img class="tq-banner-img" src="{{ $b->image_url ?? media_url('storage/menu_banners/', $b->image, $store->id ?? null) }}" alt="" data-csp-error="hide">@endif
                            <span class="tq-banner-copy">
                                @if($b->subtitle)<span class="tq-banner-kick">{{ $b->subtitle }}</span>@endif
                                <span class="tq-banner-title">{{ $b->title }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
                @if($tqBanners->count() > 1)<div class="tq-banner-dots" id="tqBannerDots" aria-hidden="true"></div>@endif
            </div>
        @endif

        <div class="tq-maint" id="tqMaint">
            <div class="tq-mtop">
                <span class="tq-odo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 13l4-4M4 14a8 8 0 1116 0"/><circle cx="12" cy="14" r="1"/></svg>
                    {{ ___('Odometer') }}: <input type="number" class="tq-odo-input" id="tqOdoInput" min="0" step="1" inputmode="numeric" aria-label="{{ ___('Odometer') }}"> {{ ___('mi') }}
                </span>
            </div>
            <h3>{{ ___('Maintenance') }}</h3>
            <div class="tq-track" id="tqMaintTrack">
                <div class="tq-line"></div>
                <div class="tq-fill" id="tqMaintFill"></div>
            </div>
            <div class="tq-due" id="tqMaintDue" hidden>
                <span class="tq-due-txt" id="tqMaintDueText"></span>
                <button type="button" id="tqAddKit">{{ ___('Add kit') }}</button>
            </div>
        </div>

        {{-- Desktop store layout (≥1024px): left sticky sidebar (category
             rail) + product catalog to its right. `.tq-browse` / `.tq-sidebar`
             / `.tq-catalog` are plain unstyled block <div>s below 1024px
             (no visual effect on mobile/tablet — see desktop CSS layer
             near the end of this style block). --}}
        <div class="tq-browse">
        <div class="tq-sidebar">
        @if($categories->isNotEmpty())
        <div class="tq-sect-h"><h2>{{ $vocab['categories'] ?? ___('Categories') }}</h2></div>
        <div class="tq-cats" id="tqCatTiles">
            @foreach($categories as $i => $cat)
                <button type="button" class="tq-cat" data-cat="{{ $cat->id }}">
                    <span class="tq-ci" data-cat-icon="{{ $i }}"></span>
                    <span>{{ $cat->name }}</span>
                </button>
            @endforeach
        </div>

        <div class="tq-chips" role="tablist" aria-label="{{ $vocab['categories'] ?? ___('Categories') }}" id="tqChips">
            <button type="button" class="tq-chip is-active" role="tab" aria-selected="true" data-cat="all">{{ ___('All') }}</button>
            <button type="button" class="tq-chip" role="tab" aria-selected="false" data-cat="__reco" id="tqRecoChip" hidden></button>
            @foreach($categories as $cat)
                <button type="button" class="tq-chip" role="tab" aria-selected="false" data-cat="{{ $cat->id }}">{{ $cat->name }}</button>
            @endforeach
        </div>
        @endif

        @if($fitment)
        <div class="tq-search-row" style="margin-block-end:12px">
            <div class="tq-search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                <input type="search" class="tq-search-input" id="tqOemSearch" autocomplete="off"
                       placeholder="{{ ___('Search by OEM or cross-reference number') }}"
                       aria-label="{{ ___('Search by OEM or cross-reference number') }}">
            </div>
        </div>
        @endif
        </div>
        <div class="tq-catalog">

        <div class="tq-sect-h">
            <h2 id="tqGridHeading">{{ $vocab['items'] ?? ___('Parts') }}</h2>
            <span class="tq-results" id="tqResultsCount"></span>
        </div>

        @if($items->isEmpty())
            <div class="tq-empty">
                <div class="tq-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 8l9-5 9 5-9 5z"/><path d="M3 8v8l9 5 9-5V8"/></svg></div>
                <h3>{{ ___('Nothing here yet.') }}</h3>
            </div>
        @else
            <div class="tq-grid" id="tqGrid">
                @foreach($items as $m)
                    @php $jd = $jsItems[$m->id]; @endphp
                    <article class="tq-card" data-id="{{ $m->id }}" data-root="{{ $jd['root'] }}"
                             data-brand="{{ Str::lower($jd['brand'] ?? '') }}"
                             data-name="{{ Str::lower($jd['name']) }}" data-desc="{{ Str::lower($jd['desc']) }}"
                             data-pn="{{ Str::lower(trim(($jd['pn'] ?? '').' '.implode(' ', $jd['oem']).' '.implode(' ', $jd['crossRefs']))) }}">
                        <div class="tq-card__imgwrap">
                            <span class="tq-fitchip" data-fitchip hidden></span>
                            @if($jd['soldOut'])
                                <span class="tq-card__soldout">{{ ___('Sold out') }}</span>
                            @endif
                            <a href="?item={{ $m->id }}" class="tq-card__media-link" data-open-item-link="{{ $m->id }}" aria-label="{{ $jd['name'] }}">
                                @if($jd['img'])
                                    @if((string) ($jd['img']) !== '')<img class="tq-card__img" src="{{ $jd['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
                                @else
                                    <div class="tq-card__img tq-card__img--ph" role="img" aria-label="{{ $jd['name'] }}">
                                        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 7l9-4 9 4v10l-9 4-9-4V7z"/><path d="M3 7l9 4 9-4M12 11v10"/></svg>
                                    </div>
                                @endif
                            </a>
                            <button type="button" class="tq-fav-btn" data-fav-toggle="{{ $m->id }}" aria-pressed="false" aria-label="{{ ___('Add to favorites') }}">
                                <svg viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                            </button>
                        </div>
                        <div class="tq-card__body">
                            @if($jd['brand'])
                                <span class="tq-card__brand">{{ $jd['brand'] }}</span>
                            @endif
                            <span class="tq-card__name">{{ $jd['name'] }}</span>
                            @if($jd['pn'])
                                <span class="tq-card__pn">{{ $jd['pn'] }}</span>
                            @endif
                            @if($jd['stockBadge'] && ! $jd['soldOut'])
                                <span class="tq-stock tq-stock--{{ $jd['stockBadge']['type'] }}">{{ $jd['stockBadge']['text'] }}</span>
                            @endif
                            <div class="tq-card__foot">
                                <span class="tq-price" data-role="price">{{ $jd['priceFormatted'] }}</span>
                                <button type="button" class="tq-qadd" data-add="{{ $m->id }}" @if($jd['soldOut']) disabled @endif aria-label="{{ $vocab['add_to_cart'] ?? ___('Add to cart') }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="tq-empty" id="tqNoResults" hidden>
                <div class="tq-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg></div>
                <h3>{{ ___('No results.') }}</h3>
            </div>
        @endif
        </div>
        </div>
    </main>

    {{-- Product detail sheet: fitment gauge + spec sheet + OEM/aftermarket
         toggle + interchange numbers, off-canvas like shelf's own item sheet. --}}
    <div class="tq-sheet-scrim" id="tqItemScrim"></div>
    <aside class="tq-sheet" id="tqItemSheet" role="dialog" aria-modal="true" aria-label="{{ $vocab['item'] ?? ___('Part') }}" aria-hidden="true" inert>
        <div class="tq-sheet__head">
            <strong class="tq-sheet__title" id="tqItemName" tabindex="-1"></strong>
            <button type="button" class="tq-iconbtn" id="tqItemClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="tq-sheet__body">
            <div id="tqItemGallery">
                <div class="tq-pd-img">
                    <span class="tq-fitchip" data-fitchip hidden></span>
                    <img id="tqItemImg" alt="" data-csp-error="hide" hidden>
                </div>
                <div class="tq-thumbs" id="tqItemThumbs" hidden></div>
            </div>
            <div id="tqItemBuyBox">
            <div class="tq-pd-name" id="tqItemNameFull"></div>
            <div class="tq-pd-pn" id="tqItemPn"></div>
            <div class="tq-pd-price"><span id="tqItemPrice"></span><span class="tq-note" id="tqItemPriceNote" hidden></span></div>
            <p id="tqItemDesc" style="color:var(--tq-ink-2);font-size:.84rem;margin-block-start:8px"></p>
            <span class="tq-stock" id="tqItemStock" hidden></span>

            <div class="tq-toggle" id="tqOemToggle" hidden>
                <button type="button" data-oem="0" aria-pressed="true">{{ ___('Aftermarket') }}</button>
                <button type="button" data-oem="1" aria-pressed="false">{{ ___('OEM reference') }}</button>
            </div>

            <div class="tq-gauge unknown" id="tqGauge" hidden>
                <svg viewBox="0 0 120 66" aria-hidden="true">
                    <path d="M12 58 A48 48 0 0 1 108 58" fill="none" stroke="var(--tq-hair-2)" stroke-width="7" stroke-linecap="round"/>
                    <path d="M12 58 A48 48 0 0 1 40 15" fill="none" stroke="var(--tq-nofit)" stroke-width="7" stroke-linecap="round" opacity=".55"/>
                    <path d="M46 12 A48 48 0 0 1 74 12" fill="none" stroke="var(--tq-verify)" stroke-width="7" opacity=".55"/>
                    <path d="M80 15 A48 48 0 0 1 108 58" fill="none" stroke="var(--tq-fit)" stroke-width="7" stroke-linecap="round" opacity=".7"/>
                    <g class="tq-needle" id="tqNeedle"><line x1="60" y1="56" x2="60" y2="20" stroke="var(--tq-ink)" stroke-width="2.6" stroke-linecap="round"/></g>
                    <circle cx="60" cy="56" r="5" fill="var(--tq-ink)"/>
                </svg>
                <div class="tq-gtext">
                    <div class="tq-gt" id="tqGaugeTitle"></div>
                    <div class="tq-gs" id="tqGaugeDesc"></div>
                </div>
                <div class="tq-interchange" id="tqInterchange" hidden>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                    <span id="tqInterchangeText"></span>
                </div>
            </div>

            <div id="tqVariantGroups"></div>

            <div class="tq-specs" id="tqSpecsGroup" hidden>
                <div class="tq-sh">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 7a4 4 0 00-5.5 4.5L3 17l4 4 5.5-5.5A4 4 0 0017 10l-3 3-3-3z"/></svg>
                    {{ ___('Specifications') }}
                </div>
                <div id="tqSpecsBody"></div>
            </div>

            <div class="tq-group" style="margin-block-start:16px">
                <div class="tq-group__label">{{ ___('Quantity') }}</div>
                <div class="tq-qty">
                    <button type="button" id="tqQtyMinus" aria-label="{{ ___('Decrease') }}">&minus;</button>
                    <span id="tqQtyVal">1</span>
                    <button type="button" id="tqQtyPlus" aria-label="{{ ___('Increase') }}">&plus;</button>
                </div>
            </div>

            {{-- Curated core feature: reviews — average/count header, 5→1
                 distribution, list, write-a-review form. Fetched fresh each
                 time a part opens (GET r/{store}/item/{menu}/reviews). --}}
            <div class="tq-group" id="tqReviewsGroup" hidden>
                <div class="tq-group__label">{{ ___('Reviews') }}</div>
                <div class="tq-rv-head" id="tqRvHead" hidden>
                    <div class="tq-rv-avg">
                        <b id="tqRvAvgNum">0.0</b>
                        <span class="tq-rv-stars" id="tqRvAvgStars" aria-hidden="true"></span>
                        <span class="tq-rv-count" id="tqRvCount"></span>
                    </div>
                    <button type="button" class="tq-rv-write-btn" id="tqRvWriteToggle">{{ ___('Write a review') }}</button>
                </div>
                <div class="tq-rv-dist" id="tqRvDist" hidden></div>
                <div class="tq-rv-list" id="tqRvList"></div>
                <p class="tq-rv-empty" id="tqRvEmpty" hidden>{{ ___('No reviews yet — be the first.') }}</p>
                <form class="tq-rv-form" id="tqRvForm">
                    <div class="tq-rv-starpicker" id="tqRvStarPicker" role="group" aria-label="{{ ___('Your rating') }}">
                        <button type="button" data-rv-star="1" aria-label="1">★</button>
                        <button type="button" data-rv-star="2" aria-label="2">★</button>
                        <button type="button" data-rv-star="3" aria-label="3">★</button>
                        <button type="button" data-rv-star="4" aria-label="4">★</button>
                        <button type="button" data-rv-star="5" aria-label="5">★</button>
                    </div>
                    <div class="tq-field">
                        <label for="tqRvName">{{ ___('Your name') }} ({{ ___('Optional') }})</label>
                        <input type="text" id="tqRvName" maxlength="80" autocomplete="name">
                    </div>
                    <div class="tq-field">
                        <label for="tqRvBody">{{ ___('Your review') }} ({{ ___('Optional') }})</label>
                        <input type="text" id="tqRvBody" maxlength="2000">
                    </div>
                    <div id="tqRvMsg"></div>
                    <button type="submit" class="tq-btn tq-btn--primary" id="tqRvSubmit">{{ ___('Submit review') }}</button>
                </form>
            </div>
            </div>{{-- /#tqItemBuyBox --}}
        </div>
        <div class="tq-sheet__foot">
            <button type="button" class="tq-btn tq-btn--primary" id="tqAddToCart" style="width:100%">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg>
                <span id="tqAddToCartLabel">{{ $vocab['add_to_cart'] ?? ___('Add to cart') }}</span>
                <b id="tqAddToCartPrice"></b>
            </button>
        </div>
    </aside>

    {{-- Cart / checkout sheet — posts to the native goods checkout
         (GoodsCheckoutController), same store-side cart each item's
         `addRoute` fills, exactly like shelf's own cart sheet. --}}
    <div class="tq-sheet-scrim" id="tqCartScrim"></div>
    <aside class="tq-sheet" id="tqCartSheet" role="dialog" aria-modal="true" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}" aria-hidden="true" inert>
        <div class="tq-sheet__head">
            <strong class="tq-sheet__title" id="tqCartTitle" tabindex="-1">{{ $vocab['orders'] ?? ___('Cart') }}</strong>
            <button type="button" class="tq-iconbtn" id="tqCartClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="tq-sheet__body">
            <div id="tqCartView">
                <div id="tqCartLines"></div>
                <div class="tq-empty" id="tqCartEmpty">
                    <div class="tq-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg></div>
                    <h3>{{ ___('Your cart is empty.') }}</h3>
                </div>
                <div class="tq-kit" id="tqCartKit" hidden>
                    <h4>{{ ___('Complete the job') }}</h4>
                    <p id="tqCartKitDesc"></p>
                    <button type="button" class="tq-btn tq-btn--ghost" style="width:100%" id="tqCartKitAdd">{{ ___('Add bundle') }}</button>
                </div>
                <div class="tq-summary" id="tqCartSummary" hidden>
                    <div class="tq-srow"><span>{{ ___('Subtotal') }}</span><span id="tqSumSubtotal"></span></div>
                    <div class="tq-srow" id="tqSumTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="tqSumTax"></span></div>
                    <div class="tq-srow total"><span>{{ ___('Total') }}</span><span id="tqSumTotal"></span></div>
                    <p class="tq-core-note" id="tqCoreNote" hidden></p>
                </div>
            </div>

            <form id="tqCheckoutForm" hidden>
                <div id="tqCheckoutMsg"></div>
                <div class="tq-field tq-field--half">
                    <label for="tqName">{{ ___('Name') }}</label>
                    <input type="text" id="tqName" name="name" required maxlength="255">
                </div>
                <div class="tq-field tq-field--half">
                    <label for="tqPhone">{{ ___('Phone') }}</label>
                    <input type="tel" id="tqPhone" name="phone" required maxlength="25">
                </div>
                @if($modes->count() > 1)
                <div class="tq-field">
                    <label for="tqMode">{{ ___('Fulfillment') }}</label>
                    <select id="tqMode" name="mode">
                        @foreach($modes as $mode)
                            <option value="{{ $mode['value'] }}" data-needs-address="{{ $mode['needsAddress'] ? 1 : 0 }}" data-needs-slot="{{ $mode['needsSlot'] ? 1 : 0 }}">{{ $mode['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="tq-field" id="tqAddressField" hidden>
                    <label for="tqAddress">{{ ___('Address') }}</label>
                    <div class="tq-addr">
                        <input type="text" id="tqAddress" name="address" maxlength="255" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="{{ ___('Start typing your address…') }}">
                        <button type="button" class="tq-addr-loc" id="tqAddrLoc" aria-label="{{ ___('Use my location') }}" title="{{ ___('Use my location') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                        </button>
                        <div class="tq-addr-suggest" id="tqAddrSuggest" hidden></div>
                    </div>
                    {{-- Drop-a-pin picker (shared window.mbMapPicker engine, torque-skinned .tq-sheet drawer). --}}
                    <button type="button" class="tq-maplink" id="tqMapBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.4"/></svg>
                        {{ ___('Choose on map') }}
                    </button>
                </div>
                <div class="tq-field" id="tqSlotField" hidden>
                    <label>{{ ___('Pick a time') }}</label>
                    <div class="tq-slots" id="tqSlots"></div>
                </div>

                {{-- Curated core feature: loyalty redeem row. Balance is
                     fetched (auth:customer) the moment the checkout form
                     opens; guests / stores without an active program fall
                     back to the public teaser + a sign-in hint. --}}
                <div class="tq-loyalty-row" id="tqLoyaltyRow" hidden>
                    <div class="tq-loyalty-row__teaser" id="tqLoyaltyTeaser" hidden></div>
                    <div class="tq-loyalty-row__signin" id="tqLoyaltySignin" hidden>{{ ___('Sign in to earn & redeem points') }}</div>
                    <button type="button" class="tq-loyalty-toggle" id="tqLoyaltyToggle" hidden aria-pressed="false">
                        <span class="tq-loyalty-toggle__label" id="tqLoyaltyToggleLabel"></span>
                        <span class="tq-loyalty-toggle__switch" aria-hidden="true"></span>
                    </button>
                    <input type="hidden" id="tqLoyaltyRedeemInput" name="loyalty_redeem" value="0">
                </div>

                {{-- Promo code + gift card — applied server-side via OrderPricing. --}}
                <div class="tq-couponbox">
                    <div class="tq-couponrow">
                        <input type="text" class="tq-coupon-input" id="tqCouponInput" autocomplete="off" placeholder="{{ ___('Promo code') }}" aria-label="{{ ___('Promo code') }}">
                        <button type="button" class="tq-btn tq-btn--ghost tq-coupon-apply" id="tqCouponApply">{{ ___('Apply') }}</button>
                    </div>
                    <p class="tq-coupon-msg" id="tqCouponMsg" hidden></p>
                    <input type="hidden" name="coupon_code" id="tqCouponField" value="">
                    <input type="text" class="tq-coupon-input" id="tqGiftCardInput" name="gift_card_code" autocomplete="off" placeholder="{{ ___('Gift card code') }}" aria-label="{{ ___('Gift card code') }}" style="margin-top:8px">
                </div>

                <button type="button" class="tq-btn tq-btn--primary" id="tqPlaceOrder" style="width:100%">{{ ___('Place order') }}</button>
            </form>

            <div id="tqConfirmView" hidden>
                <div class="tq-confwrap">
                    <svg class="tq-checkc" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="45"/><path d="M30 52l14 14 26-30"/></svg>
                    <h3>{{ ___('Order placed') }}</h3>
                    <p>{{ ___('Thank you — your parts are on the way.') }}</p>
                    <div class="tq-orderno" id="tqOrderNo"></div>
                </div>
                <a href="#" id="tqTrackLink" class="tq-btn tq-btn--ghost" style="width:100%;display:none;margin-block-end:10px">{{ ___('Track order') }}</a>
                <button type="button" class="tq-btn tq-btn--primary" id="tqConfirmDone" style="width:100%">{{ ___('Continue shopping') }}</button>
            </div>
        </div>
        <div class="tq-sheet__foot" id="tqCartFoot">
            <button type="button" class="tq-btn tq-btn--primary" id="tqCheckoutBtn" style="width:100%" disabled>{{ ___('Checkout') }}</button>
        </div>
    </aside>

    {{-- Address map picker — torque-skinned .tq-sheet drawer driven by the shared
         window.mbMapPicker engine. Opened from the checkout delivery-address field
         (which lives inside the cart sheet), so .tq-mapsheet bumps its z-index above
         the cart drawer (scrim 19 / sheet 20 → map scrim 21 / sheet 22). --}}
    <div class="tq-sheet-scrim tq-mapsheet-scrim" id="tqMapScrim"></div>
    <aside class="tq-sheet tq-mapsheet" id="tqMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" aria-hidden="true" inert>
        <div class="tq-sheet__head">
            <strong class="tq-sheet__title" id="tqMapTitle" tabindex="-1">{{ ___('Choose on map') }}</strong>
            <button type="button" class="tq-iconbtn" id="tqMapClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="tq-sheet__body tq-mapsheet__body">
            <div class="tq-map-canvas" id="tqMap"></div>
            <p class="tq-map-preview" id="tqMapPreview">{{ ___('Move the map to place the pin') }}</p>
        </div>
        <div class="tq-sheet__foot">
            <button type="button" class="tq-btn tq-btn--primary" id="tqMapConfirm" style="width:100%">{{ ___('Use this location') }}</button>
        </div>
    </aside>

    {{-- Curated core feature: Account sheet — the public loyalty teaser +
         tier chips, enhanced with a real balance/progress when the visitor
         is a signed-in customer (auth:customer) — otherwise a sign-in hint,
         never a fabricated balance. --}}
    <div class="tq-sheet-scrim" id="tqAccountScrim"></div>
    <aside class="tq-sheet" id="tqAccountSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Account') }}" aria-hidden="true" inert>
        <div class="tq-sheet__head">
            <strong class="tq-sheet__title" id="tqAccountTitle" tabindex="-1">{{ ___('Account') }}</strong>
            <button type="button" class="tq-iconbtn" id="tqAccountClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="tq-sheet__body">
            @if($loy)
                <div class="tq-rewards-card">
                    <div class="tq-rewards-card__top">
                        <span class="tq-rewards-card__ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 8h18M12 8v13M7.5 8a2.5 2.5 0 010-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 010 5"/></svg>
                        </span>
                        <div>
                            <div class="tq-rewards-card__program">{{ $loy['program'] ?? ___('Rewards') }}</div>
                            @if(!empty($loy['earn']))
                                <div class="tq-rewards-card__line">{{ $loy['earn'] }}</div>
                            @endif
                        </div>
                    </div>
                    @if(!empty($loy['reward']))
                        <div class="tq-rewards-card__line">{{ $loy['reward'] }}</div>
                    @endif
                    <div id="tqRewardsBalanceArea"></div>
                    <div class="tq-tierchips" id="tqTierChips">
                        <span class="tq-tierchip" data-tier="silver">{{ ___('Silver') }}</span>
                        <span class="tq-tierchip" data-tier="gold">{{ ___('Gold') }}</span>
                        <span class="tq-tierchip" data-tier="platinum">{{ ___('Platinum') }}</span>
                    </div>
                    <div class="tq-rewards-signin" id="tqRewardsSignin" hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                        <span>{{ ___('Sign in to earn & redeem points') }}</span>
                    </div>
                </div>
            @else
                <div class="tq-empty">
                    <div class="tq-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 8h18M12 8v13M7.5 8a2.5 2.5 0 010-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 010 5"/></svg></div>
                    <h3>{{ ___('No rewards program yet.') }}</h3>
                </div>
            @endif

            {{-- Order history — publicView.myOrders (auth:customer). --}}
            <h3 class="tq-orders-h">{{ ___('My Orders') }}</h3>
            <div id="tqOrdersList"></div>
            <p class="tq-orders-empty" id="tqOrdersEmpty" hidden>{{ ___('No orders yet') }}</p>
            <p class="tq-orders-empty" id="tqOrdersGuest" hidden>{{ ___('Sign in to see your orders') }}</p>
            @if(($postOptions->customer_auth_mode ?? '') !== 'demo')
                @if(auth('customer')->check())
                    @if(\App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
                        <button type="button" class="tq-btn tq-btn--ghost" data-mb-wallet-open style="width:100%;margin-top:10px">{{ ___('Wallet — add funds') }}</button>
                    @endif
                    <button type="button" class="tq-btn tq-btn--ghost" data-mb-logout data-logout-url="{{ route('customer.logout', $store->id) }}" style="width:100%;margin-top:10px">{{ ___('Log out') }}</button>
                @else
                    <button type="button" class="tq-btn" data-mb-auth-open style="width:100%;margin-top:10px">{{ ___('Sign in / Register') }}</button>
                @endif
            @endif

            {{-- Privacy: wipe the cart / favourites / saved vehicles this browser
                 keeps (shared window.mbClearDeviceData engine). The Account sheet is
                 guest-reachable (opens for everyone, not login-gated), so the control
                 lives here rather than in the checkout footer. --}}
            <button type="button" class="tq-btn tq-btn--ghost tq-cleardata" id="tqClearData" style="width:100%;margin-top:18px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>
                <span>{{ ___('Clear saved data on this device') }}</span>
            </button>
            <p class="tq-cleardata-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
        </div>
    </aside>

    {{-- Curated core feature: "Saved" wishlist sheet — lists parts favorited
         via the card heart (persisted in localStorage), with a quick
         view/remove per row. --}}
    <div class="tq-sheet-scrim" id="tqSavedScrim"></div>
    <aside class="tq-sheet" id="tqSavedSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Saved parts') }}" aria-hidden="true" inert>
        <div class="tq-sheet__head">
            <strong class="tq-sheet__title" id="tqSavedTitle" tabindex="-1">{{ ___('Saved parts') }}</strong>
            <button type="button" class="tq-iconbtn" id="tqSavedClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="tq-sheet__body">
            <div id="tqSavedLines"></div>
            <div class="tq-empty" id="tqSavedEmpty">
                <div class="tq-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg></div>
                <h3>{{ ___('Nothing saved yet — tap the heart on a part to save it here.') }}</h3>
            </div>
        </div>
    </aside>

    {{-- Curated core feature: full-screen story viewer (image, caption,
         timed progress bars, optional CTA, tap/swipe prev-next, close).
         Visibility is driven by the shared sheet a11y helper's `.is-open`
         class, so Escape / browser-back close it exactly like every other
         sheet. --}}
    <div class="tq-story-viewer" id="tqStoryViewer" role="dialog" aria-modal="true" aria-label="{{ ___('Stories') }}" aria-hidden="true" inert>
        <div class="tq-story-viewer__progress" id="tqStoryProgress"></div>
        <div class="tq-story-viewer__head">
            <button type="button" class="tq-story-viewer__close" id="tqStoryClose" aria-label="{{ ___('Close') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="tq-story-viewer__stage">
            <button type="button" class="tq-story-viewer__zone tq-story-viewer__zone--prev" id="tqStoryPrev" aria-label="{{ ___('Previous') }}"></button>
            <img class="tq-story-viewer__media" id="tqStoryMedia" alt="" data-csp-error="hide">
            <button type="button" class="tq-story-viewer__zone tq-story-viewer__zone--next" id="tqStoryNext" aria-label="{{ ___('Next') }}"></button>
        </div>
        <div class="tq-story-viewer__foot">
            <p class="tq-story-viewer__caption" id="tqStoryCaption" hidden></p>
            <a class="tq-story-viewer__cta" id="tqStoryCta" href="#" hidden rel="noopener"></a>
        </div>
    </div>

    @if($fitment)
    {{-- Garage: saved vehicles, active switch, add via VIN/manual. Persisted
         client-side (localStorage) — see the build note in the script below
         on why this stays client-only for now. --}}
    <div class="tq-sheet-scrim" id="tqGarageScrim"></div>
    <aside class="tq-sheet" id="tqGarageSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Garage') }}" aria-hidden="true" inert>
        <div class="tq-sheet__head">
            <strong class="tq-sheet__title" id="tqGarageTitle" tabindex="-1">{{ ___('Garage') }}</strong>
            <button type="button" class="tq-iconbtn" id="tqGarageClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="tq-sheet__body">
            <div id="tqGarageList"></div>
            <div class="tq-empty" id="tqGarageEmpty">
                <div class="tq-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10l9-6 9 6M5 9v11h14V9M8 20v-6h8v6"/></svg></div>
                <h3>{{ ___('No saved vehicles yet.') }}</h3>
            </div>
            <button type="button" class="tq-btn tq-btn--ghost" id="tqGarageAdd" style="width:100%;margin-block-start:6px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V5a1 1 0 011-1h3M16 4h3a1 1 0 011 1v3M20 16v3a1 1 0 01-1 1h-3M8 20H5a1 1 0 01-1-1v-3"/><path d="M4 12h16"/></svg>
                {{ ___('Add a vehicle') }}
            </button>
        </div>
    </aside>

    {{-- VIN scan (real input, decoded server-side when a decode provider is
         wired up — see script below); "Enter manually" always falls back to
         the Year→Make→Model→Engine wizard. --}}
    <div class="tq-modal-scrim" id="tqVinScrim"></div>
    <div class="tq-modal" id="tqVinModal" role="dialog" aria-modal="true" aria-label="{{ ___('Scan your VIN') }}" aria-hidden="true" inert>
        <div class="tq-modal__inner">
            <div class="tq-modal__top">
                <button type="button" class="tq-iconbtn" id="tqVinClose" aria-label="{{ ___('Close') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
                </button>
            </div>
            <h2 class="tq-h1" style="font-size:1.25rem;margin:6px 0 4px" tabindex="-1" id="tqVinHeading">{{ ___('Scan your VIN') }}</h2>
            <p style="color:var(--tq-ink-2);font-size:.84rem;margin:0 0 14px">{{ ___('Enter the 17-character VIN from the door jamb or windshield to identify your exact vehicle.') }}</p>
            <div class="tq-vinframe">
                <svg class="tq-car-silhouette" viewBox="0 0 100 56" aria-hidden="true">
                    <path d="M6 40 L14 40 C16 31 22 26 32 25 L44 25 C50 20 60 18 70 20 L82 24 C90 26 94 31 94 40 L88 40" fill="none" stroke="currentColor" stroke-width="1.6"/>
                    <circle cx="24" cy="41" r="7.5" fill="none" stroke="currentColor" stroke-width="1.6"/>
                    <circle cx="78" cy="41" r="7.5" fill="none" stroke="currentColor" stroke-width="1.6"/>
                </svg>
                <div class="tq-vin-corners"><span></span><span></span><span></span><span></span></div>
                <div class="tq-vin-scan" id="tqVinScanBar"></div>
            </div>
            <input type="text" class="tq-vin-input" id="tqVinInput" maxlength="17" autocomplete="off" spellcheck="false"
                   placeholder="{{ ___('e.g. 4T1B11HK5KU123456') }}" aria-label="{{ ___('VIN') }}" dir="ltr">
            <div class="tq-vin-read" id="tqVinRead" aria-live="polite"></div>
            <div class="tq-vin-result" id="tqVinResult">
                <div class="tq-vico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg></div>
                <div>
                    <div style="font-family:var(--tq-f-head);font-weight:700;font-size:.86rem">{{ ___('Vehicle identified') }}</div>
                    <div class="mono" style="font-size:.76rem;color:var(--tq-cyan)" id="tqVinResultText"></div>
                </div>
            </div>
            <div class="tq-msg tq-msg--error" id="tqVinError" hidden></div>
            <button type="button" class="tq-btn tq-btn--primary" id="tqVinDecodeBtn" style="width:100%;margin-block-start:12px">{{ ___('Decode VIN') }}</button>
            <div class="tq-actionbar" style="margin-block-start:10px">
                <button type="button" class="tq-btn tq-btn--ghost" id="tqVinManual" style="flex:1">{{ ___('Enter manually') }}</button>
                <button type="button" class="tq-btn tq-btn--primary" id="tqVinContinue" style="flex:1" disabled>{{ ___('Use this vehicle') }}</button>
            </div>
        </div>
    </div>

    {{-- Manual Year → Make → Model → Engine wizard — the always-on fallback,
         options derived client-side from the fitment-options feed. --}}
    <div class="tq-modal-scrim" id="tqWzScrim"></div>
    <div class="tq-modal" id="tqWzModal" role="dialog" aria-modal="true" aria-label="{{ ___('Add a vehicle') }}" aria-hidden="true" inert>
        <div class="tq-modal__inner">
            <div class="tq-wz-prog" id="tqWzProg"><i></i><i></i><i></i><i></i></div>
            <div class="tq-modal__top">
                <button type="button" class="tq-iconbtn" id="tqWzBack" aria-label="{{ ___('Back') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
                </button>
                <h2 class="tq-h1" style="font-size:1.15rem;margin:0" id="tqWzTitle" tabindex="-1"></h2>
            </div>
            <p class="tq-wz-sub" id="tqWzSub"></p>
            <div id="tqWzOptions" style="flex:1;overflow-y:auto"></div>
        </div>
    </div>
    @endif

    <div class="tq-toast" id="tqToast" role="status" aria-live="polite"></div>

    {{-- Real notification centre — header bell forwards to a hidden proxy. --}}
    <button type="button" id="mbNotifBell" class="tq-nb-proxy" aria-hidden="true" tabindex="-1"></button>
    <i id="mbNotifDot" hidden></i>
    @include('store_templates._partials.notif-bell')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var proxy = document.getElementById('mbNotifBell');
            var srcDot = document.getElementById('mbNotifDot');
            var bell = document.getElementById('tqNotifBell');
            if (bell) { bell.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (proxy && window.mbNotify) { proxy.click(); } }); }
            if (srcDot) {
                var mirror = function () { var d = bell && bell.querySelector('.tq-notif-dot'); if (d) { d.hidden = srcDot.hidden; } };
                new MutationObserver(mirror).observe(srcDot, { attributes: true, attributeFilter: ['hidden'] });
                mirror();
            }
            var shareBtn = document.getElementById('tqShareBtn');
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
        .mba-sheet{--mba-surface:var(--tq-card);--mba-ink:var(--tq-ink);--mba-muted:var(--tq-ink3,var(--tq-ink));
            --mba-border:var(--tq-hair);--mba-hair:var(--tq-hair);--mba-in-bg:var(--tq-card);
            --mba-accent:var(--tq-amber);--mba-on-accent:#151515;--mba-accent-ring:var(--tq-hair);--mba-radius:12px}
    </style>
    <script nonce="{{ csp_nonce() }}">
    (function () {
        ['tqRewardsSignin', 'tqLoyaltySignin', 'tqOrdersGuest'].forEach(function (id) {
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

    {{-- Bottom tabbar — a root-level sibling of the header (NOT a child of it):
         the header's backdrop-filter establishes a containing block that would
         otherwise trap this position:fixed bar inside the header. Shown only on
         small screens; the topbar drops its duplicated icons there so it stays
         uncrowded. Buttons proxy the existing topbar controls. --}}
    <nav class="tq-tabbar" aria-label="{{ ___('Primary') }}">
        <button type="button" class="tq-tab" id="tqTabHome" aria-label="{{ ___('Home') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/></svg>
            <span>{{ ___('Home') }}</span>
        </button>
        <button type="button" class="tq-tab" id="tqTabSaved" aria-label="{{ ___('Saved parts') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
            <span>{{ ___('Saved') }}</span>
            <i class="tq-tab-badge" id="tqTabSavedBadge" hidden>0</i>
        </button>
        <button type="button" class="tq-tab" id="tqTabCart" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg>
            <span>{{ $vocab['orders'] ?? ___('Cart') }}</span>
            <i class="tq-tab-badge" id="tqTabCartBadge" hidden>0</i>
        </button>
        <button type="button" class="tq-tab" id="tqTabAccount" aria-label="{{ ___('Account') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
            <span>{{ ___('Account') }}</span>
        </button>
    </nav>
</div>
@endsection

@push('style')
<style nonce="{{ csp_nonce() }}">
    /* Address map picker + clear-saved-data (shared engines, torque-skinned) */
    /* "Choose on map" — an instrument-panel affordance: mono, uppercase, amber. */
    .tq-maplink{display:inline-flex;align-items:center;gap:7px;margin-block-start:9px;padding:0;border:0;background:none;cursor:pointer;
        font-family:var(--tq-f-mono);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--tq-amber)}
    .tq-maplink svg{width:15px;height:15px}
    .tq-maplink:hover{color:var(--tq-amber-2);text-decoration:underline;text-underline-offset:3px}
    /* Sit above the cart drawer it is opened from (cart scrim/sheet = 19/20). */
    .tq-mapsheet-scrim{z-index:21}
    .tq-mapsheet{z-index:22}
    .tq-mapsheet__body{display:flex;flex-direction:column;padding:0;overflow:hidden}
    .tq-map-canvas{position:relative;flex:1;min-height:min(52vh,360px);background:var(--tq-card-2)}
    .tq-map-canvas .leaflet-container{width:100%;height:100%;font:inherit;background:var(--tq-card-2)}
    .tq-map-preview{margin:0;padding:13px 16px;font-family:var(--tq-f-mono);font-size:.76rem;line-height:1.45;color:var(--tq-ink-2);
        border-block-start:1px solid var(--tq-hair);text-align:center;min-height:20px;background:var(--tq-ground)}
    /* Teardrop pin dropped into the map centre (engine injects .mb-map-pin; theme skins it). */
    .mb-map-pin{position:absolute;left:50%;top:50%;width:28px;height:36px;transform:translate(-50%,-100%);
        z-index:500;pointer-events:none;filter:drop-shadow(0 4px 6px rgba(0,0,0,.5))}
    .mb-map-pin::before{content:"";position:absolute;left:50%;top:0;transform:translateX(-50%) rotate(45deg);
        width:24px;height:24px;border-radius:50% 50% 50% 0;background:var(--tq-amber);border:2px solid var(--tq-ground)}
    .mb-map-pin::after{content:"";position:absolute;left:50%;top:7px;transform:translateX(-50%);width:8px;height:8px;border-radius:50%;background:var(--tq-ground)}
    /* Clear-saved-data row: ghost button tinted with the theme's "no-fit" danger token + a muted hint. */
    .tq-cleardata{display:inline-flex;align-items:center;justify-content:center;gap:8px;color:var(--tq-nofit-ink);border-color:var(--tq-hair-2)}
    .tq-cleardata svg{width:17px;height:17px}
    .tq-cleardata:hover{background:var(--tq-nofit-bg);border-color:var(--tq-nofit)}
    .tq-cleardata-hint{margin:7px 0 0;font-size:.74rem;line-height:1.45;color:var(--tq-ink-3);text-align:center}
</style>
@endpush

@push('script')
{{-- Device-local guest order history helper (window.mbGuestOrders) — shared
     across themes; must load before the main script below uses it. --}}
@include('store_templates._partials.guest-orders')
{{-- Delivery-address autocomplete engine (window.mbAddrAutocomplete). --}}
@include('store_templates._partials.address-autocomplete')
{{-- Shared map picker + clear-device-data engines. torque is a custom layout, so
     it @includes these itself (store_templates/layout does NOT auto-wire them);
     the theme supplies its own torque-skinned UI + wiring below. --}}
@include('store_templates._partials.map-picker')
@include('store_templates._partials.clear-device-data')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var ITEMS = @json($jsItems);
    var CATEGORIES = @json($categories->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name])->values());
    var MODES = @json($modes);
    var SLOT_ROUTE = @json($slotRoute);
    var CHECKOUT_ROUTE = @json($checkoutRoute);
    var COUPON_ROUTE = @json(\Illuminate\Support\Facades\Route::has('store.applyCoupon') ? route('store.applyCoupon', $store->id) : null);
    var MY_ORDERS_ROUTE = @json(\Illuminate\Support\Facades\Route::has('publicView.myOrders.index') ? route('publicView.myOrders.index', $store->id) : null);
    var CART_LINE_ROUTE_TEMPLATE = @json($cartLineRouteTemplate);
    var FITMENT_ENABLED = @json($fitment);
    var OPTIONS_URL = @json($fitmentOptionsRoute);
    var OEM_URL = @json($oemSearchRoute);
    var VIN_DECODE_URL = @json($vinDecodeRoute);
    function cartLineRoute(lineId) { return CART_LINE_ROUTE_TEMPLATE.replace('999999999', String(lineId)); }
    var CSRF = @json(csrf_token());
    // Curated core features (loop/enrich) — endpoint bases + seeded teaser.
    var STORIES_URL = @json($storiesUrl);
    var MBR_BASE = @json($mbrBase);
    var LOYALTY_BALANCE_URL = @json($loyaltyBalanceUrl);
    var LOY_TEASER = @json($loy);
    var CUR = {
        sign: @json($curSign),
        gap: @json($curGap),
        pos: @json($curPos),
        decimals: @json($curDecimals),
    };
    // Mirrors Order::computeTax() exactly: a client-side estimate of the same
    // real tax the store charges, never a fabricated fee. 0 when no tax set.
    var TAX = { charge: @json($taxCharge), type: @json($taxType) };
    function computeTax(subtotal) {
        if (TAX.charge <= 0 || subtotal <= 0) { return 0; }
        var raw = TAX.type === 'fixed' ? TAX.charge : subtotal * (TAX.charge / 100);
        var f = Math.pow(10, CUR.decimals);
        return Math.round(raw * f) / f;
    }
    var T = {
        addToCart: @json($vocab['add_to_cart'] ?? ___('Add to cart')),
        outOfStock: @json(___('Out of stock')),
        chooseOption: @json(___('Please choose an option.')),
        cartEmpty: @json(___('Your cart is empty.')),
        placing: @json(___('Placing order…')),
        genericError: @json(___('Something went wrong. Please try again.')),
        nameRequired: @json(___('Please enter your name.')),
        addressRequired: @json(___('Please enter a delivery address.')),
        stockLeft: @json(___(':count left')),
        inStock: @json(___('In stock')),
        added: @json(___('Added to cart')),
        kitAdded: @json(___('Service kit added')),
        bundleAdded: @json(___('Bundle added')),
        vehicleAdded: @json(___('Vehicle added')),
        vehicleSwitched: @json(___('Vehicle switched')),
        removed: @json(___('Removed')),
        fits: @json(___('Fits')),
        verify: @json(___('Verify fit')),
        wontFit: @json(___('Won’t fit')),
        universal: @json(___('Universal fit')),
        fitReasonExact: @json(___('Confirmed for your :vehicle.')),
        fitReasonVerify: @json(___('Listed for your :model — confirm engine/trim before you buy.')),
        fitReasonNo: @json(___('This part does not appear to fit your :vehicle.')),
        fitReasonUniversal: @json(___('Fits most vehicles.')),
        fitReasonUnknown: @json(___('Add your vehicle to check fitment.')),
        interchangePrefix: @json(___('Interchange / OEM: :numbers')),
        recommendedFor: @json(___('Recommended for your :model')),
        min: @json(___('min')),
        yr: @json(___('yr')),
        decoding: @json(___('Decoding…')),
        vinInvalid: @json(___('Enter a valid 17-character VIN.')),
        vinNotFound: @json(___('We couldn’t decode that VIN automatically. Try again or enter your vehicle manually.')),
        vinUnavailable: @json(___('VIN decoding isn’t available yet — please enter your vehicle manually.')),
        pickYear: @json(___('Year')), pickMake: @json(___('Make')), pickModel: @json(___('Model')), pickEngine: @json(___('Engine')),
        chooseYear: @json(___('Choose the model year')), chooseMake: @json(___('Choose the manufacturer')),
        chooseModel: @json(___('Choose the model')), chooseEngine: @json(___('Choose the engine')),
        noVehicleSaved: @json(___('No vehicle saved')),
        serviceDueSoon: @json(___('Service due soon.')),
        serviceUpToDate: @json(___('You’re up to date.')),
        serviceDueItems: @json(___('Filters & fluids typically due at this mileage.')),
        confirmOrderNo: @json(___('Order #:id')),
        // Curated core features (loop/enrich)
        story: @json(___('Story')),
        reviewsWord: @json(___('reviews')),
        reviewWord: @json(___('review')),
        anonymous: @json(___('Anonymous')),
        verified: @json(___('Verified')),
        chooseRatingFirst: @json(___('Choose a rating first.')),
        reviewThanks: @json(___('Thank you! Your review will appear once approved.')),
        reviewFailed: @json(___('Could not send your review. Please try again.')),
        points: @json(___('points')),
        applyReward: @json(___('Apply :amount off')),
        view: @json(___('View')),
        remove: @json(___('Remove')),
        removeFav: @json(___('Remove from favorites')),
        addFav: @json(___('Add to favorites')),
    };
    var CAT_ICONS = [
        '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 4v3M12 17v3M4 12h3M17 12h3"/>',
        '<path d="M4 12h2V9h4l2-2h3v3h3l1 2v4h-2v2h-4v-2H8v2H6v-3H4z"/>',
        '<path d="M4 5h16l-6 8v6l-4-2v-4z"/>',
        '<rect x="3" y="8" width="16" height="10" rx="2"/><path d="M19 11h2v4h-2M7 6v2M14 6v2"/>',
        '<path d="M6 20h9a3 3 0 003-3v-3l-2-2h-4V9H6z"/><path d="M9 9V6h4"/>',
        '<path d="M4 12a8 6 0 018-6h4a4 4 0 010 12h-4a8 6 0 01-8-6z"/><path d="M18 9v6"/>',
        '<path d="M13 3L5 13h6l-1 8 8-11h-6z"/>',
        '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2.5"/><path d="M12 4v3M12 17v3M4 12h3M17 12h3M6.5 6.5l2 2M15.5 15.5l2 2M17.5 6.5l-2 2M8.5 15.5l-2 2"/>',
        '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/>',
    ];
    function catIcon(i) { return CAT_ICONS[i % CAT_ICONS.length]; }
    var ICONS = {
        car: '<path d="M5 16l1.5-5A2 2 0 018.4 9.6h7.2a2 2 0 011.9 1.4L19 16M4 16h16v3h-2v-1H6v1H4z"/><circle cx="7.5" cy="16.5" r="1.2"/><circle cx="16.5" cy="16.5" r="1.2"/>',
        check: '<path d="M5 12l4 4 10-10"/>', alert: '<path d="M12 3l10 18H2z"/><path d="M12 10v4M12 17h.01"/>', x: '<path d="M6 6l12 12M18 6L6 18"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>', chev: '<path d="M9 6l6 6-6 6"/>',
        trash: '<path d="M4 7h16M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m2 0v13a1 1 0 01-1 1H7a1 1 0 01-1-1V7h12z"/>',
    };
    function svgIcon(name, cls) { return '<svg class="' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || '') + '</svg>'; }
    function fitIcon(status) { return svgIcon(status === 'fit' || status === 'universal' ? 'check' : (status === 'verify' ? 'alert' : 'x')); }

    var root = document.getElementById('tqApp');
    if (!root) { return; }
    var storeId = root.getAttribute('data-store-id') || '';

    /* Remember the shopper's active view for THIS tab. Torque is a single home
       screen whose "places" (order history, saved, cart) are modal sheets, so a
       same-tab reload (a language switch is a full navigation) otherwise dumps
       the shopper back on a bare home and their order history "looks gone".
       Record which sheet is open here; restoreView() below re-opens it on load.
       sessionStorage is per-tab, so a genuinely fresh visit still opens home. */
    function tqRememberView(name) {
        try { window.sessionStorage.setItem('mb-screen-' + storeId, name); } catch (e) { /* best-effort */ }
    }

    /* ---------------- dark mode ---------------- */
    var darkKey = 'torque-theme-' + storeId;
    var darkIconMoon = '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>';
    var darkIconSun = '<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/>';
    function syncDarkIcon() {
        var icon = document.getElementById('tqDarkIcon');
        if (!icon) { return; }
        icon.innerHTML = document.documentElement.getAttribute('data-theme') === 'light' ? darkIconSun : darkIconMoon;
    }
    try {
        var savedTheme = window.localStorage.getItem(darkKey);
        if (savedTheme) { document.documentElement.setAttribute('data-theme', savedTheme); }
    } catch (e) { /* storage may be unavailable — default (dark-first) stands */ }
    syncDarkIcon();
    var darkBtn = document.getElementById('tqDarkToggle');
    if (darkBtn) {
        darkBtn.addEventListener('click', function () {
            var cur = document.documentElement.getAttribute('data-theme');
            var next = cur === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', next);
            try { window.localStorage.setItem(darkKey, next); } catch (e) { /* best-effort */ }
            syncDarkIcon();
        });
    }

    /* ---------------- toast ---------------- */
    var toastTimer = null;
    function toast(msg, icon) {
        var el = document.getElementById('tqToast');
        if (!el) { return; }
        el.innerHTML = (icon ? svgIcon(icon) : '') + '<span></span>';
        el.lastChild.textContent = msg == null ? '' : String(msg);
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('show'); }, 1800);
    }

    /* ---------------- P2-style sheet/modal a11y (Escape / back-close / focus
       trap+restore / aria-hidden+inert) — same single-tracked-entry pattern
       as shelf/index.blade.php's openA11ySheet, generalised to also drive the
       full-screen VIN/wizard modals (a "modal" is just a sheet whose scrim
       covers the whole viewport — same open/close/back-guard mechanics). ---- */
    var FOCUSABLE_SEL = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
    var a11yOpenEntry = null;
    var a11yNavGuard = false;
    var a11yPopping = false;

    function openA11ySheet(sheetEl, scrimEl, focusEl, url) {
        if (!sheetEl) { return; }
        var trigger = document.activeElement;
        a11yOpenEntry = { el: sheetEl, scrim: scrimEl, trigger: trigger };
        sheetEl.setAttribute('aria-hidden', 'false');
        sheetEl.removeAttribute('inert');
        if (scrimEl) { scrimEl.classList.add('is-open'); }
        sheetEl.classList.add('is-open');
        if (!a11yNavGuard) {
            a11yNavGuard = true;
            try { history.pushState({ tqSheet: 1 }, '', url || undefined); } catch (e) { /* best-effort */ }
        } else if (url) {
            try { history.replaceState(history.state, '', url); } catch (e) { /* best-effort */ }
        }
        setTimeout(function () { try { (focusEl || sheetEl).focus(); } catch (e) { /* best-effort */ } }, 60);
    }
    function rawCloseA11ySheet() {
        var entry = a11yOpenEntry;
        a11yOpenEntry = null;
        if (!entry) { return; }
        entry.el.classList.remove('is-open');
        if (entry.scrim) { entry.scrim.classList.remove('is-open'); }
        entry.el.setAttribute('aria-hidden', 'true');
        entry.el.setAttribute('inert', '');
        // Closing any sheet returns the shopper to the home view, so forget the
        // remembered place (a reload from here should land on home, not re-open).
        tqRememberView('home');
        if (entry.trigger && typeof entry.trigger.focus === 'function') {
            try { entry.trigger.focus(); } catch (e) { /* best-effort */ }
        }
    }
    function dismissA11ySheet() {
        if (!a11yOpenEntry) { return; }
        if (a11yNavGuard && !a11yPopping) {
            try { history.back(); return; } catch (e) { /* fall through to raw close */ }
        }
        rawCloseA11ySheet();
    }
    window.addEventListener('popstate', function () {
        if (!a11yOpenEntry) { a11yNavGuard = false; return; }
        a11yPopping = true;
        rawCloseA11ySheet();
        a11yNavGuard = false;
        a11yPopping = false;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && a11yOpenEntry) { dismissA11ySheet(); return; }
        if (e.key !== 'Tab' || !a11yOpenEntry) { return; }
        var focusables = Array.prototype.filter.call(
            a11yOpenEntry.el.querySelectorAll(FOCUSABLE_SEL),
            function (n) { return n.offsetParent !== null; }
        );
        if (!focusables.length) { return; }
        var first = focusables[0], last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    // Reassigned by the fitment block further below (only when FITMENT_ENABLED
    // and its markup exist) — kept as safe no-ops here so every call site
    // above/below this line works whether or not that block runs.
    var computeFit = function () { return null; }; // -> {status:'fit'|'verify'|'nofit'|'universal', reason, interchange}
    var getActiveVehicle = function () { return null; };
    var updateAllFitChips = function () { };
    var refreshGaugeForCurrentItem = function () { };

    /* ---------------- category filter + search (name/desc/brand/part#) ---------------- */
    var grid = document.getElementById('tqGrid');
    var cards = grid ? Array.prototype.slice.call(grid.querySelectorAll('.tq-card')) : [];
    var noResults = document.getElementById('tqNoResults');
    var resultsCount = document.getElementById('tqResultsCount');
    var activeCat = 'all';
    var activeBrand = '';
    var query = '';

    function applyFilter() {
        var visible = 0;
        cards.forEach(function (card) {
            var matchesCat = activeCat === 'all'
                || (activeCat === '__reco' && card.getAttribute('data-pf-fit') === '1')
                || card.getAttribute('data-root') === activeCat;
            var matchesBrand = !activeBrand || card.getAttribute('data-brand') === activeBrand;
            var matchesQuery = !query
                || card.getAttribute('data-name').indexOf(query) !== -1
                || card.getAttribute('data-desc').indexOf(query) !== -1
                || card.getAttribute('data-pn').indexOf(query) !== -1;
            var matchesOem = card.getAttribute('data-oem-hide') !== '1';
            var show = matchesCat && matchesBrand && matchesQuery && matchesOem;
            card.hidden = !show;
            if (show) { visible++; }
        });
        if (noResults) { noResults.hidden = visible !== 0; }
        if (resultsCount) { resultsCount.textContent = visible + ' ' + (visible === 1 ? @json(___('result')) : @json(___('results'))); }
    }

    function setActiveCategory(catId) {
        activeCat = catId;
        document.querySelectorAll('[data-cat]').forEach(function (c) {
            var on = c.getAttribute('data-cat') === catId;
            c.classList.toggle('is-active', on);
            if (c.hasAttribute('aria-selected')) { c.setAttribute('aria-selected', on ? 'true' : 'false'); }
        });
        applyFilter();
        var heading = document.getElementById('tqGridHeading');
        if (heading) { try { heading.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { /* older browser */ } }
    }
    document.querySelectorAll('.tq-chip[data-cat],.tq-cat[data-cat]').forEach(function (btn) {
        btn.addEventListener('click', function () { setActiveCategory(btn.getAttribute('data-cat')); });
    });
    document.querySelectorAll('[data-cat-icon]').forEach(function (el) {
        var i = parseInt(el.getAttribute('data-cat-icon'), 10) || 0;
        el.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' + catIcon(i) + '</svg>';
    });

    var searchInput = document.getElementById('tqSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            query = searchInput.value.trim().toLowerCase();
            applyFilter();
        });
    }
    var brandFilter = document.getElementById('tqBrandFilter');
    if (brandFilter) {
        brandFilter.addEventListener('change', function () {
            activeBrand = brandFilter.value;
            applyFilter();
        });
    }

    /* ---------------- exploded diagram hotspots -> category filter ---------------- */
    var ZONES = [
        { x: 20, y: 63 * 0.56 }, { x: 37, y: 40 * 0.56 }, { x: 30, y: 52 * 0.56 },
        { x: 26, y: 56 * 0.56 }, { x: 80, y: 63 * 0.56 }, { x: 66, y: 44 * 0.56 },
    ];
    var hotspotsG = document.getElementById('tqHotspots');
    if (hotspotsG && CATEGORIES.length) {
        var zoneCount = Math.min(ZONES.length, CATEGORIES.length);
        for (var zi = 0; zi < zoneCount; zi++) {
            var zone = ZONES[zi], zc = CATEGORIES[zi];
            var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.setAttribute('class', 'tq-hot');
            g.setAttribute('tabindex', '0');
            g.setAttribute('role', 'button');
            g.setAttribute('aria-label', zc.name);
            g.setAttribute('data-cat', zc.id);
            g.innerHTML = '<circle class="pulse" cx="' + zone.x + '" cy="' + zone.y + '" r="2.4"/>'
                + '<circle class="ring" cx="' + zone.x + '" cy="' + zone.y + '" r="4"/>'
                + '<circle class="core" cx="' + zone.x + '" cy="' + zone.y + '" r="2"/>';
            g.addEventListener('click', function () { setActiveCategory(this.getAttribute('data-cat')); }.bind(g));
            g.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setActiveCategory(this.getAttribute('data-cat')); } }.bind(g));
            hotspotsG.appendChild(g);
        }
    } else if (!CATEGORIES.length) {
        var diagramCard = document.getElementById('tqDiagram');
        if (diagramCard) { diagramCard.hidden = true; } // no categories at all — nothing for the diagram to browse into
    }

    /* ---------------- maintenance / mileage kit ---------------- */
    var KIT_KEYWORDS = ['filter', 'oil', 'fluid', 'pad', 'battery', 'wiper', 'spark plug'];
    function isKitCandidate(item) {
        var hay = (item.name + ' ' + item.desc).toLowerCase();
        return KIT_KEYWORDS.some(function (kw) { return hay.indexOf(kw) !== -1; });
    }
    function findKitCandidates(limit, excludeIds) {
        var out = [];
        Object.keys(ITEMS).forEach(function (id) {
            if (out.length >= limit) { return; }
            var item = ITEMS[id];
            if (item.soldOut || excludeIds[item.id]) { return; }
            if (!isKitCandidate(item)) { return; }
            var fit = computeFit(item.id);
            if (fit && fit.status === 'nofit') { return; } // never suggest a part flagged as not fitting
            out.push(item);
        });
        return out;
    }
    var ODO_KEY = 'torque-odo-' + storeId;
    var SERVICE_INTERVAL = 30000; // miles — a generic default; no per-vehicle schedule exists in this data model yet (see build note below)
    function renderMaintenance() {
        var input = document.getElementById('tqOdoInput');
        var fill = document.getElementById('tqMaintFill');
        var due = document.getElementById('tqMaintDue');
        var dueText = document.getElementById('tqMaintDueText');
        if (!input) { return; }
        var odo = parseInt(input.value, 10);
        if (!odo || odo < 0) { if (fill) { fill.style.width = '0%'; } if (due) { due.hidden = true; } return; }
        var nextService = Math.ceil((odo + 1) / SERVICE_INTERVAL) * SERVICE_INTERVAL;
        var prevService = nextService - SERVICE_INTERVAL;
        var pct = Math.min(100, Math.max(0, ((odo - prevService) / SERVICE_INTERVAL) * 100));
        if (fill) { fill.style.width = pct + '%'; }
        var milesLeft = nextService - odo;
        if (due) {
            due.hidden = false;
            var soon = milesLeft <= 3000;
            dueText.innerHTML = soon
                ? '<b>' + T.serviceDueSoon + '</b> ' + T.serviceDueItems
                : '<b>' + T.serviceUpToDate + '</b> ' + T.serviceDueItems;
        }
    }
    var odoInput = document.getElementById('tqOdoInput');
    if (odoInput) {
        try {
            var savedOdo = window.localStorage.getItem(ODO_KEY);
            if (savedOdo) { odoInput.value = savedOdo; }
        } catch (e) { /* best-effort */ }
        odoInput.addEventListener('input', function () {
            try { window.localStorage.setItem(ODO_KEY, odoInput.value); } catch (e) { /* best-effort */ }
            renderMaintenance();
        });
        renderMaintenance();
    }
    var addKitBtn = document.getElementById('tqAddKit');
    if (addKitBtn) {
        addKitBtn.addEventListener('click', function () {
            var picks = findKitCandidates(2, {});
            if (!picks.length) { toast(T.genericError, 'alert'); return; }
            addKitBtn.disabled = true;
            Promise.all(picks.map(function (p) { return postAddToCart(p.id, null, 1).then(function (d) { addToCart(p.id, null, 1, d.line_id); }).catch(function () {}); }))
                .then(function () { toast(T.kitAdded, 'check'); addKitBtn.disabled = false; });
        });
    }

    /* ---------------- cart state (client preview array; server truth lives
       in the goods cart the store-side addRoute below actually posts to) ---------------- */
    var CART_KEY = 'torque-cart-' + storeId;
    var cart = [];
    try {
        var storedCart = window.localStorage.getItem(CART_KEY);
        if (storedCart) { cart = JSON.parse(storedCart) || []; }
    } catch (e) { cart = []; }
    // Drop stored lines whose menu item no longer exists (removed/unavailable
    // since added) — else cartCount() counts a line renderCart() skips, so the
    // cart badge shows a number over an empty cart. Prune once on load.
    try { var _cbefore = cart.length; cart = cart.filter(function (l) { return l && ITEMS[l.id]; }); if (cart.length !== _cbefore) { window.localStorage.setItem(CART_KEY, JSON.stringify(cart)); } } catch (e) { /* best-effort */ }

    function saveCart() {
        try { window.localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) { /* best-effort */ }
    }
    function lineUnit(line) {
        var item = ITEMS[line.id];
        if (!item) { return 0; }
        if (line.variantId) {
            var v = item.variants.filter(function (x) { return x.id === line.variantId; })[0];
            if (v) { return v.price; }
        }
        return item.price;
    }
    function lineTotal(line) { return lineUnit(line) * line.qty; }
    function lineCore(line) {
        var item = ITEMS[line.id];
        return item ? (item.core || 0) * line.qty : 0;
    }
    function cartCount() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }
    function cartIds() {
        var ids = {};
        cart.forEach(function (l) { ids[l.id] = true; });
        return ids;
    }

    function formatMoney(n) {
        // Mirrors price_symbol_format() server-side: the store's real symbol,
        // side, gap and per-currency decimal count — see CUR above.
        var amount = Number(n) || 0;
        var str;
        try {
            str = amount.toLocaleString(document.documentElement.lang || undefined, {
                minimumFractionDigits: CUR.decimals, maximumFractionDigits: CUR.decimals,
            });
        } catch (e) { str = amount.toFixed(CUR.decimals); }
        return '⁦' + (CUR.pos === '1' ? (CUR.sign + CUR.gap + str) : (str + CUR.gap + CUR.sign)) + '⁩';
    }

    function renderCartBadge() {
        var badge = document.getElementById('tqCartBadge');
        if (!badge) { return; }
        var n = cartCount();
        badge.textContent = String(n);
        badge.hidden = n === 0;
    }

    function renderCart() {
        var linesEl = document.getElementById('tqCartLines');
        var emptyEl = document.getElementById('tqCartEmpty');
        var summaryEl = document.getElementById('tqCartSummary');
        var kitEl = document.getElementById('tqCartKit');
        var checkoutBtn = document.getElementById('tqCheckoutBtn');
        if (!linesEl) { return; }

        linesEl.innerHTML = '';
        if (!cart.length) {
            emptyEl.hidden = false;
            summaryEl.hidden = true;
            kitEl.hidden = true;
            if (checkoutBtn) { checkoutBtn.disabled = true; }
            renderCartBadge();
            return;
        }
        emptyEl.hidden = true;
        summaryEl.hidden = false;
        if (checkoutBtn) { checkoutBtn.disabled = false; }

        var sum = 0, coreSum = 0;
        cart.forEach(function (line, idx) {
            var item = ITEMS[line.id];
            if (!item) { return; }
            var lt = lineTotal(line);
            sum += lt;
            coreSum += lineCore(line);
            var variantLbl = '';
            if (line.variantId) {
                var v = item.variants.filter(function (x) { return x.id === line.variantId; })[0];
                if (v) { variantLbl = v.label; }
            }
            var row = document.createElement('div');
            row.className = 'tq-cart-line';
            var img = document.createElement('img');
            img.alt = '';
            img.setAttribute('data-csp-error', 'hide');
            if (item.img) { img.src = item.img; } else { img.hidden = true; }
            var body = document.createElement('div');
            body.className = 'tq-cart-line__body';
            var nameEl = document.createElement('div');
            nameEl.className = 'tq-cart-line__name';
            nameEl.textContent = item.name + (variantLbl ? ' · ' + variantLbl : '');
            var pnEl = document.createElement('div');
            pnEl.className = 'tq-cart-line__pn';
            pnEl.textContent = (item.pn ? item.pn + ' · ' : '') + (item.core ? '+' + formatMoney(item.core * line.qty) + ' ' + @json(___('core')) : '');
            var foot = document.createElement('div');
            foot.className = 'tq-cart-line__foot';
            var stepper = document.createElement('div');
            stepper.className = 'tq-stepper';
            var dec = document.createElement('button'); dec.type = 'button'; dec.className = 'tq-step'; dec.textContent = '−';
            dec.setAttribute('aria-label', @json(___('Decrease')));
            var qtyEl = document.createElement('span'); qtyEl.className = 'mono'; qtyEl.textContent = String(line.qty);
            var inc = document.createElement('button'); inc.type = 'button'; inc.className = 'tq-step'; inc.textContent = '+';
            inc.setAttribute('aria-label', @json(___('Increase')));
            dec.addEventListener('click', function () {
                if (line.qty > 1) { line.qty--; } else { cart.splice(idx, 1); zeroServerLine(line.lineId); }
                saveCart(); renderCart();
            });
            inc.addEventListener('click', function () { line.qty++; saveCart(); renderCart(); });
            stepper.appendChild(dec); stepper.appendChild(qtyEl); stepper.appendChild(inc);
            var priceEl = document.createElement('span');
            priceEl.className = 'tq-cart-line__price';
            priceEl.textContent = formatMoney(lt);
            foot.appendChild(stepper); foot.appendChild(priceEl);
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'tq-cart-line__remove';
            rm.textContent = @json(___('Remove'));
            rm.addEventListener('click', function () {
                var removedLineId = line.lineId;
                cart.splice(idx, 1);
                saveCart(); renderCart();
                zeroServerLine(removedLineId);
            });
            body.appendChild(nameEl); body.appendChild(pnEl); body.appendChild(foot); body.appendChild(rm);
            row.appendChild(img); row.appendChild(body);
            linesEl.appendChild(row);
        });

        var tax = computeTax(sum);
        var taxRow = document.getElementById('tqSumTaxRow');
        if (taxRow) { taxRow.hidden = tax <= 0; }
        var taxEl = document.getElementById('tqSumTax');
        if (taxEl) { taxEl.textContent = formatMoney(tax); }
        document.getElementById('tqSumSubtotal').textContent = formatMoney(sum);
        document.getElementById('tqSumTotal').textContent = formatMoney(sum + tax);
        var coreNote = document.getElementById('tqCoreNote');
        if (coreSum > 0) {
            coreNote.hidden = false;
            coreNote.textContent = @json(___('Includes :amount in refundable core charges, collected/returned in-store.')).replace(':amount', formatMoney(coreSum));
        } else {
            coreNote.hidden = true;
        }

        // "Complete the job" — a single best-effort complementary suggestion,
        // never fabricated: the card simply doesn't render when nothing
        // matches (see findKitCandidates' keyword heuristic above).
        var complementPicks = findKitCandidates(1, cartIds());
        if (complementPicks.length) {
            var pick = complementPicks[0];
            kitEl.hidden = false;
            document.getElementById('tqCartKitDesc').textContent = pick.name + ' — ' + pick.priceFormatted;
            var kitAddBtn = document.getElementById('tqCartKitAdd');
            kitAddBtn.onclick = function () {
                kitAddBtn.disabled = true;
                postAddToCart(pick.id, null, 1).then(function (d) {
                    addToCart(pick.id, null, 1, d.line_id);
                    toast(T.bundleAdded, 'check');
                }).catch(function () { toast(T.genericError, 'alert'); }).then(function () { kitAddBtn.disabled = false; });
            };
        } else {
            kitEl.hidden = true;
        }

        renderCartBadge();
    }

    function zeroServerLine(lineId) {
        if (!lineId) { return; }
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('quantity', '0');
        fetch(cartLineRoute(lineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .catch(function () { /* best-effort */ });
    }

    function addToCart(itemId, variantId, qty, lineId) {
        var existing = cart.filter(function (l) { return l.id === itemId && l.variantId === (variantId || null); })[0];
        if (existing) {
            existing.qty += qty;
            if (lineId) { existing.lineId = lineId; }
        } else {
            cart.push({ id: itemId, variantId: variantId || null, qty: qty, lineId: lineId || null });
        }
        saveCart();
        renderCart();
    }

    function postAddToCart(itemId, variantId, qty) {
        var item = ITEMS[itemId];
        var fd = new FormData();
        fd.append('_token', CSRF);
        if (variantId) { fd.append('variation', variantId); }
        fd.append('quantity', qty);
        return fetch(item.addRoute, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (data) {
                if (!data || !data.success) { throw new Error((data && data.message) || 'add-to-cart failed'); }
                return data;
            });
    }

    renderCart();

    /* ---------------- product detail sheet ---------------- */
    var itemScrim = document.getElementById('tqItemScrim');
    var itemSheet = document.getElementById('tqItemSheet');
    var currentItem = null;
    var currentVariantId = null;
    var currentQty = 1;
    var currentOem = false;
    var OEM_ESTIMATE_MULTIPLIER = 1.35; // display-only reference multiplier — the store has ONE authoritative price (item.price); see spec-sheet build note

    function currentVariant() {
        if (!currentItem || !currentVariantId) { return null; }
        return currentItem.variants.filter(function (v) { return v.id === currentVariantId; })[0] || null;
    }
    function currentUnitPrice() {
        var v = currentVariant();
        return v ? v.price : (currentItem ? currentItem.price : 0);
    }
    function updateSheetPrice() {
        var priceEl = document.getElementById('tqAddToCartPrice');
        priceEl.textContent = formatMoney(currentUnitPrice() * currentQty);
    }
    function updateOemUI() {
        if (!currentItem) { return; }
        var priceEl = document.getElementById('tqItemPrice');
        var noteEl = document.getElementById('tqItemPriceNote');
        if (currentOem) {
            priceEl.textContent = formatMoney(currentUnitPrice() * OEM_ESTIMATE_MULTIPLIER);
            noteEl.hidden = false;
            noteEl.textContent = @json(___('estimated OEM reference — this listing still charges the aftermarket price'));
        } else {
            priceEl.textContent = formatMoney(currentUnitPrice());
            noteEl.hidden = true;
        }
    }

    function renderItemStock() {
        var badge = document.getElementById('tqItemStock');
        if (!badge || !currentItem) { return; }
        var v = currentVariant();
        var qty = (v && v.stockQty !== null && v.stockQty !== undefined) ? v.stockQty : currentItem.stockQty;
        if (!currentItem.trackInventory || qty === null || qty === undefined) { badge.hidden = true; return; }
        badge.hidden = false;
        badge.className = 'tq-stock';
        if (qty <= 0) { badge.classList.add('tq-stock--out'); badge.textContent = T.outOfStock; }
        else if (qty <= 5) { badge.classList.add('tq-stock--low'); badge.textContent = T.stockLeft.replace(':count', String(qty)); }
        else { badge.classList.add('tq-stock--in'); badge.textContent = T.inStock; }
    }

    function renderVariantGroups() {
        var wrap = document.getElementById('tqVariantGroups');
        wrap.innerHTML = '';
        if (!currentItem.variants.length) { return; }
        var group = document.createElement('div');
        group.className = 'tq-group';
        var label = document.createElement('div');
        label.className = 'tq-group__label';
        label.textContent = @json(___('Choose an option'));
        var opts = document.createElement('div');
        opts.className = 'tq-opts';
        currentItem.variants.forEach(function (v) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tq-opt' + (v.id === currentVariantId ? ' is-active' : '');
            btn.textContent = v.label + ' — ' + v.priceFormatted;
            var outOfStock = (v.stockQty !== null && v.stockQty !== undefined && v.stockQty <= 0);
            if (outOfStock) { btn.disabled = true; btn.title = T.outOfStock; }
            btn.addEventListener('click', function () {
                currentVariantId = v.id;
                renderVariantGroups();
                updateSheetPrice();
                updateOemUI();
                renderItemStock();
            });
            opts.appendChild(btn);
        });
        group.appendChild(label);
        group.appendChild(opts);
        wrap.appendChild(group);
    }

    function specRow(label, value) {
        return '<div class="tq-spec"><span class="tq-k">' + label + '</span><span class="tq-v">' + value + '</span></div>';
    }
    function diffDots(n) {
        var out = '<span class="tq-diff" role="img" aria-label="' + n + '/5">';
        for (var i = 1; i <= 5; i++) { out += '<i class="' + (i <= n ? 'on' : '') + '"></i>'; }
        return out + '</span>';
    }
    function renderSpecs(item) {
        var group = document.getElementById('tqSpecsGroup');
        var body = document.getElementById('tqSpecsBody');
        var rows = '';
        if (item.pn) { rows += specRow(@json(___('Part number')), '<span class="mono">' + item.pn + '</span>'); }
        if (item.position) { rows += specRow(@json(___('Position')), item.position); }
        if (item.torqueSpec) { rows += specRow(@json(___('Torque spec')), '<span class="mono">' + item.torqueSpec + '</span>'); }
        if (item.installMin) { rows += specRow(@json(___('Install time')), item.installMin + ' ' + T.min); }
        if (item.difficulty) { rows += specRow(@json(___('Difficulty')), diffDots(item.difficulty)); }
        if (item.tools) { rows += specRow(@json(___('Tools needed')), item.tools); }
        if (item.warrantyYr) { rows += specRow(@json(___('Warranty')), item.warrantyYr + ' ' + T.yr); }
        if (item.coreFormatted) { rows += specRow(@json(___('Core charge')), item.coreFormatted); }
        var interchangeNums = (item.oem || []).concat(item.crossRefs || []);
        if (interchangeNums.length) {
            rows += specRow(@json(___('Interchange')), '<span class="mono" style="color:var(--tq-cyan)">' + interchangeNums.join(' / ') + '</span>');
        }
        if (!rows) { group.hidden = true; return; }
        group.hidden = false;
        body.innerHTML = rows;
    }

    function openItem(id) {
        var item = ITEMS[id];
        if (!item) { return; }
        currentItem = item;
        currentVariantId = item.variants.length === 1 ? item.variants[0].id : null;
        currentQty = 1;
        currentOem = false;
        document.getElementById('tqQtyVal').textContent = '1';
        document.getElementById('tqItemName').textContent = item.name;
        document.getElementById('tqItemNameFull').textContent = item.name;
        document.getElementById('tqItemPn').textContent = item.pn || '';
        document.getElementById('tqItemPn').hidden = !item.pn;
        var detailImg = document.getElementById('tqItemImg');
        if (item.img) { detailImg.src = item.img; detailImg.alt = item.name; detailImg.hidden = false; }
        else { detailImg.removeAttribute('src'); detailImg.hidden = true; }
        renderThumbs();
        document.getElementById('tqItemDesc').textContent = item.desc;

        var oemToggle = document.getElementById('tqOemToggle');
        oemToggle.hidden = !(item.oem && item.oem.length);
        oemToggle.querySelectorAll('[data-oem]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-oem') === '0' ? 'true' : 'false'); });

        updateOemUI();
        renderVariantGroups();
        renderSpecs(item);
        renderItemStock();
        updateSheetPrice();
        refreshGaugeForCurrentItem();
        loadReviews(id);

        var addBtn = document.getElementById('tqAddToCart');
        addBtn.disabled = !!item.soldOut;

        var itemUrl = window.location.pathname + '?item=' + encodeURIComponent(String(id));
        openA11ySheet(itemSheet, itemScrim, document.getElementById('tqItemName'), itemUrl);
    }
    function closeItem() { dismissA11ySheet(); }

    // Thumbnail strip: swap the sheet's main image on tap. Only shown when the
    // current part has ≥2 distinct images (primary + gallery_images).
    function renderThumbs() {
        var wrap = document.getElementById('tqItemThumbs');
        if (!wrap) { return; }
        var imgs = (currentItem && currentItem.gallery) ? currentItem.gallery : [];
        wrap.innerHTML = '';
        if (imgs.length < 2) { wrap.hidden = true; return; }
        wrap.hidden = false;
        var mainImg = document.getElementById('tqItemImg');
        imgs.forEach(function (src, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'tq-thumb';
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

    function quickAdd(id) {
        var item = ITEMS[id];
        if (!item || item.soldOut) { return; }
        var btn = document.querySelector('[data-add="' + id + '"]');
        if (btn) { btn.disabled = true; btn.classList.add('tq-click'); setTimeout(function () { btn.classList.remove('tq-click'); }, 420); }
        postAddToCart(id, null, 1)
            .then(function (data) { addToCart(id, null, 1, data.line_id); toast(T.added, 'check'); })
            .catch(function () { toast(T.genericError, 'alert'); })
            .then(function () { if (btn) { btn.disabled = false; } });
    }
    document.querySelectorAll('[data-add]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-add'), 10);
            var item = ITEMS[id];
            if (item && item.variants.length <= 1) { quickAdd(id); } else { openItem(id); }
        });
    });
    document.getElementById('tqItemClose').addEventListener('click', closeItem);
    if (itemScrim) { itemScrim.addEventListener('click', closeItem); }
    document.querySelectorAll('[data-open-item-link]').forEach(function (link) {
        link.addEventListener('click', function (e) { e.preventDefault(); openItem(parseInt(link.getAttribute('data-open-item-link'), 10)); });
    });
    document.querySelectorAll('#tqOemToggle [data-oem]').forEach(function (b) {
        b.addEventListener('click', function () {
            currentOem = b.getAttribute('data-oem') === '1';
            document.querySelectorAll('#tqOemToggle [data-oem]').forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
            updateOemUI();
        });
    });
    document.getElementById('tqQtyMinus').addEventListener('click', function () {
        currentQty = Math.max(1, currentQty - 1);
        document.getElementById('tqQtyVal').textContent = String(currentQty);
        updateSheetPrice();
    });
    document.getElementById('tqQtyPlus').addEventListener('click', function () {
        currentQty = currentQty + 1;
        document.getElementById('tqQtyVal').textContent = String(currentQty);
        updateSheetPrice();
    });
    document.getElementById('tqAddToCart').addEventListener('click', function () {
        if (!currentItem) { return; }
        if (currentItem.variants.length > 1 && !currentVariantId) { toast(T.chooseOption, 'alert'); return; }
        var addBtn = document.getElementById('tqAddToCart');
        var item = currentItem, variantId = currentVariantId, qty = currentQty;
        addBtn.disabled = true;
        postAddToCart(item.id, variantId, qty)
            .then(function (data) {
                addToCart(item.id, variantId, qty, data.line_id);
                rawCloseA11ySheet();
                openCart();
            })
            .catch(function () { toast(T.genericError, 'alert'); })
            .then(function () { addBtn.disabled = false; });
    });

    try {
        var params = new URLSearchParams(window.location.search);
        var deepId = parseInt(params.get('item'), 10);
        // Defer to the next tick: openItem() calls render helpers (gauge/specs/
        // reviews) whose module vars are still being assigned further down this
        // IIFE, so calling it inline here throws — silently, via this catch — and
        // the ?item deep link never opens. Running it after the script finishes
        // guarantees every dependency is initialised (a card click already works
        // for the same reason: it fires long after load).
        if (deepId) { setTimeout(function () { try { if (ITEMS[deepId]) { openItem(deepId); } } catch (e2) { /* no-op */ } }, 0); }
    } catch (e) { /* no-op on an unsupported/old browser */ }

    /* ---------------- cart sheet + checkout ---------------- */
    var cartScrim = document.getElementById('tqCartScrim');
    var cartSheet = document.getElementById('tqCartSheet');

    function resetCheckoutUI() {
        document.getElementById('tqCartView').hidden = false;
        document.getElementById('tqCheckoutForm').hidden = true;
        document.getElementById('tqConfirmView').hidden = true;
        document.getElementById('tqCartFoot').hidden = false;
    }
    function openCart() {
        renderCart();
        tqRememberView('cart');
        openA11ySheet(cartSheet, cartScrim, document.getElementById('tqCartTitle'));
    }
    function closeCart() { dismissA11ySheet(); }
    document.getElementById('tqCartOpen').addEventListener('click', openCart);
    document.getElementById('tqCartClose').addEventListener('click', closeCart);
    if (cartScrim) { cartScrim.addEventListener('click', closeCart); }

    function selectedMode() {
        var select = document.getElementById('tqMode');
        if (select) {
            var opt = select.options[select.selectedIndex];
            return {
                value: select.value,
                needsAddress: opt && opt.getAttribute('data-needs-address') === '1',
                needsSlot: opt && opt.getAttribute('data-needs-slot') === '1',
            };
        }
        var only = MODES[0] || { value: 'in_store', needsAddress: false, needsSlot: false };
        return { value: only.value, needsAddress: only.needsAddress, needsSlot: only.needsSlot };
    }

    var chosenSlot = null;
    function loadSlots() {
        var wrap = document.getElementById('tqSlots');
        wrap.innerHTML = '';
        chosenSlot = null;
        fetch(SLOT_ROUTE + '?type=' + encodeURIComponent(selectedMode().value), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.enabled || !data.slots || !data.slots.length) { return; }
                data.slots.slice(0, 8).forEach(function (slot) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'tq-opt';
                    btn.textContent = slot.label;
                    btn.disabled = !!slot.full;
                    btn.addEventListener('click', function () {
                        chosenSlot = slot.value;
                        wrap.querySelectorAll('.tq-opt').forEach(function (b) { b.classList.remove('is-active'); });
                        btn.classList.add('is-active');
                    });
                    wrap.appendChild(btn);
                });
            })
            .catch(function () { /* slot picker is best-effort display; checkout still works without it */ });
    }
    // Wire the delivery-address autocomplete once (crave-style geocode suggest
    // + use-my-location). Safe to call before the field is shown — the input
    // stays in the DOM the whole time.
    if (window.mbAddrAutocomplete) {
        window.mbAddrAutocomplete(document.getElementById('tqAddress'), {
            suggest: document.getElementById('tqAddrSuggest'),
            locBtn: document.getElementById('tqAddrLoc')
        });
    }
    /* Address map picker — torque-native .tq-sheet drawer, opened via the theme's
       own focus-trapping openA11ySheet/dismissA11ySheet; map logic is the shared
       window.mbMapPicker engine. Fills the same #tqAddress the checkout submits. */
    if (window.mbMapPicker) {
        window.mbMapPicker({
            trigger: document.getElementById('tqMapBtn'),
            modal:   document.getElementById('tqMapSheet'),
            map:     document.getElementById('tqMap'),
            input:   document.getElementById('tqAddress'),
            preview: document.getElementById('tqMapPreview'),
            confirm: document.getElementById('tqMapConfirm'),
            close:   document.getElementById('tqMapClose'),
            toggle:  function (open) {
                if (open) { openA11ySheet(document.getElementById('tqMapSheet'), document.getElementById('tqMapScrim'), document.getElementById('tqMapTitle')); }
                else { dismissA11ySheet(); }
            },
            i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
        });
        document.getElementById('tqMapScrim').addEventListener('click', function () { dismissA11ySheet(); });
    } else { var _tqMapBtn = document.getElementById('tqMapBtn'); if (_tqMapBtn) { _tqMapBtn.style.display = 'none'; } }
    function updateModeFields() {
        var mode = selectedMode();
        document.getElementById('tqAddressField').hidden = !mode.needsAddress;
        document.getElementById('tqSlotField').hidden = !mode.needsSlot;
        if (mode.needsSlot) { loadSlots(); }
    }
    var modeSelect = document.getElementById('tqMode');
    if (modeSelect) { modeSelect.addEventListener('change', updateModeFields); }

    document.getElementById('tqCheckoutBtn').addEventListener('click', function () {
        document.getElementById('tqCartView').hidden = true;
        document.getElementById('tqCheckoutForm').hidden = false;
        document.getElementById('tqCartFoot').hidden = true;
        updateModeFields();
        loadLoyaltyRow();
    });

    document.getElementById('tqPlaceOrder').addEventListener('click', function () {
        var msg = document.getElementById('tqCheckoutMsg');
        msg.innerHTML = '';
        if (!cart.length) { msg.innerHTML = '<div class="tq-msg tq-msg--error">' + T.cartEmpty + '</div>'; return; }
        var name = document.getElementById('tqName').value.trim();
        var phone = document.getElementById('tqPhone').value.trim();
        if (!name) { msg.innerHTML = '<div class="tq-msg tq-msg--error">' + T.nameRequired + '</div>'; return; }
        var mode = selectedMode();
        if (mode.needsAddress && !document.getElementById('tqAddress').value.trim()) {
            msg.innerHTML = '<div class="tq-msg tq-msg--error">' + T.addressRequired + '</div>';
            return;
        }
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('customer_name', name);
        fd.append('phone_number', phone);
        fd.append('type', mode.value);
        if (mode.needsAddress) { fd.append('address', document.getElementById('tqAddress').value.trim()); }
        if (mode.needsSlot && chosenSlot) { fd.append('scheduled_at', chosenSlot); }
        // Curated core feature: forwards whatever the redeem toggle set (0
        // unless the shopper explicitly opted in); the server re-derives
        // the discount.
        var loyaltyInput = document.getElementById('tqLoyaltyRedeemInput');
        if (loyaltyInput) { fd.append('loyalty_redeem', loyaltyInput.value); }
        var tqCouponField = document.getElementById('tqCouponField');
        if (tqCouponField && tqCouponField.value) { fd.append('coupon_code', tqCouponField.value); }
        var tqGiftField = document.getElementById('tqGiftCardInput');
        if (tqGiftField && tqGiftField.value.trim()) { fd.append('gift_card_code', tqGiftField.value.trim()); }

        msg.innerHTML = '<div class="tq-msg">' + T.placing + '</div>';
        fetch(CHECKOUT_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    // Device-local guest history: remember this order on THIS
                    // device so a guest (no account) still sees it on the Orders
                    // screen with a Track link. Snapshot BEFORE the cart is
                    // cleared. torque's checkout returns tracking_url (+ order
                    // id), not a bare token, so derive the token from the
                    // tracking URL's last path segment, falling back to the id.
                    try {
                        var _trackUrl = data.tracking_url || null;
                        var _tok = data.track_token || data.order_id || data.id || data.order || '';
                        if (!_tok && _trackUrl) {
                            _tok = String(_trackUrl).split('?')[0].split('#')[0].replace(/\/+$/, '').split('/').pop();
                        }
                        if (_tok && window.mbGuestOrders) {
                            var _sub = cart.reduce(function (s, l) { return s + lineTotal(l); }, 0);
                            window.mbGuestOrders.save(storeId, {
                                token: String(_tok),
                                trackUrl: _trackUrl,
                                total: formatMoney(_sub + computeTax(_sub)),
                                itemCount: cart.reduce(function (n, l) { return n + l.qty; }, 0),
                                itemIds: cart.map(function (l) { return l.id; })
                            });
                        }
                    } catch (e) {}
                    cart = [];
                    saveCart();
                    document.getElementById('tqCheckoutForm').hidden = true;
                    document.getElementById('tqCartFoot').hidden = true;
                    var confirmView = document.getElementById('tqConfirmView');
                    confirmView.hidden = false;
                    var orderId = data.order_id || data.id || data.order || '';
                    document.getElementById('tqOrderNo').textContent = orderId ? T.confirmOrderNo.replace(':id', String(orderId)) : '';
                    var trackLink = document.getElementById('tqTrackLink');
                    if (data.tracking_url) { trackLink.href = data.tracking_url; trackLink.style.display = 'block'; }
                    else { trackLink.style.display = 'none'; }
                } else {
                    msg.innerHTML = '<div class="tq-msg tq-msg--error"></div>'; msg.firstChild.textContent = (data && data.message) || T.genericError;
                }
            })
            .catch(function () {
                msg.innerHTML = '<div class="tq-msg tq-msg--error">' + T.genericError + '</div>';
            });
    });
    document.getElementById('tqConfirmDone').addEventListener('click', function () {
        resetCheckoutUI();
        renderCart();
        closeCart();
    });

    /* ---------------------------------------------------------------------
       Vehicle-intelligence layer (fitment gauge, VIN scan, manual wizard,
       garage) — gated on the SAME `fitment` capability as the markup above
       (the "if fitment" blocks in this file), so this whole block is a
       no-op for any store where FITMENT_ENABLED is false (its DOM simply
       doesn't exist).

       Build note: garage vehicles + odometer live in localStorage only —
       there is no `customer_vehicles` table/route yet (see this theme's
       handoff spec, "Data model → Laravel"). A signed-in customer's garage
       therefore doesn't yet follow them across devices; wiring that up is a
       backend seam for Opus, not a view concern.
       --------------------------------------------------------------------- */
    if (FITMENT_ENABLED && document.getElementById('tqHud')) {
        var GAUGE_TITLE = {
            fit: @json(___('Guaranteed fit')),
            universal: @json(___('Universal fit')),
            verify: @json(___('Verify fitment')),
            nofit: @json(___('Not compatible')),
            unknown: @json(___('Fitment unknown')),
        };
        var T_VEHICLE_REQUIRED = @json(___('Please enter year, make and model.'));
        var GARAGE_KEY = 'torque-garage-' + storeId;
        var FITMENTS = [];

        function eqi(a, b) { return String(a || '').toLowerCase() === String(b || '').toLowerCase(); }
        function vehicleLabel(v) { return [v.year, v.make, v.model].filter(Boolean).join(' '); }
        function escapeHtml(s) { var d = document.createElement('div'); d.textContent = (s === null || s === undefined) ? '' : String(s); return d.innerHTML; }
        function interchangeNums(item) { return (item.oem || []).concat(item.crossRefs || []); }
        function fitLabel(status) {
            return status === 'fit' ? T.fits : status === 'universal' ? T.universal : status === 'verify' ? T.verify : T.wontFit;
        }

        /* ---------------- garage (localStorage) ---------------- */
        function getGarage() {
            try { return JSON.parse(window.localStorage.getItem(GARAGE_KEY) || '[]') || []; } catch (e) { return []; }
        }
        function saveGarage(list) {
            try { window.localStorage.setItem(GARAGE_KEY, JSON.stringify(list)); } catch (e) { /* best-effort */ }
        }
        getActiveVehicle = function () { return getGarage().filter(function (v) { return v.active; })[0] || null; };

        function renderGarageList() {
            var list = document.getElementById('tqGarageList');
            var emptyEl = document.getElementById('tqGarageEmpty');
            if (!list) { return; }
            var garage = getGarage();
            list.innerHTML = '';
            if (!garage.length) { emptyEl.hidden = false; return; }
            emptyEl.hidden = true;
            garage.forEach(function (v) {
                var card = document.createElement('button');
                card.type = 'button';
                card.className = 'tq-gcard';
                card.setAttribute('aria-pressed', v.active ? 'true' : 'false');
                card.innerHTML = '<div class="tq-gico">' + svgIcon('car') + '</div>'
                    + '<div class="tq-gi"><div class="tq-gn">' + escapeHtml(vehicleLabel(v)) + '</div>'
                    + '<div class="tq-gs">' + escapeHtml((v.engine || '') + (v.vin ? ' · VIN ' + v.vin : '')) + '</div></div>'
                    + (v.active ? '<span class="tq-active-b">' + @json(___('Active')) + '</span>' : '');
                card.addEventListener('click', function () { setActiveVehicle(v.id); });
                var rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'tq-gcard-remove';
                rm.setAttribute('aria-label', @json(___('Remove vehicle')));
                rm.innerHTML = svgIcon('trash');
                rm.addEventListener('click', function (e) { e.stopPropagation(); removeVehicle(v.id); });
                card.appendChild(rm);
                list.appendChild(card);
            });
        }
        function updateHud() {
            var nameEl = document.getElementById('tqHudName');
            var specEl = document.getElementById('tqHudSpec');
            if (!nameEl) { return; }
            var v = getActiveVehicle();
            if (!v) { nameEl.textContent = T.noVehicleSaved; specEl.textContent = ''; return; }
            nameEl.textContent = vehicleLabel(v);
            specEl.textContent = v.engine || '';
        }
        function setActiveVehicle(id) {
            var list = getGarage();
            list.forEach(function (v) { v.active = (v.id === id); });
            saveGarage(list);
            renderGarageList(); updateHud(); updateAllFitChips(); refreshGaugeForCurrentItem();
            var v = list.filter(function (x) { return x.id === id; })[0];
            if (v) { toast(vehicleLabel(v), 'car'); }
        }
        function removeVehicle(id) {
            var list = getGarage();
            var wasActive = false;
            list = list.filter(function (v) { if (v.id === id && v.active) { wasActive = true; } return v.id !== id; });
            if (wasActive && list.length) { list[0].active = true; }
            saveGarage(list);
            renderGarageList(); updateHud(); updateAllFitChips(); refreshGaugeForCurrentItem();
        }
        function addVehicle(data) {
            var list = getGarage();
            list.forEach(function (v) { v.active = false; });
            var vehicle = {
                id: 'v' + Date.now(), year: data.year, make: data.make, model: data.model,
                submodel: data.submodel || null, engine: data.engine || null, vin: data.vin || null, active: true,
            };
            list.push(vehicle);
            saveGarage(list);
            renderGarageList(); updateHud(); updateAllFitChips(); refreshGaugeForCurrentItem();
            toast(T.vehicleAdded, 'check');
            return vehicle;
        }

        var garageScrim = document.getElementById('tqGarageScrim');
        var garageSheet = document.getElementById('tqGarageSheet');
        function openGarage() { renderGarageList(); openA11ySheet(garageSheet, garageScrim, document.getElementById('tqGarageTitle')); }
        function closeGarage() { dismissA11ySheet(); }
        var hudChangeBtn = document.getElementById('tqHudChange');
        if (hudChangeBtn) { hudChangeBtn.addEventListener('click', function () { if (getGarage().length) { openGarage(); } else { openVin(); } }); }
        document.getElementById('tqGarageClose').addEventListener('click', closeGarage);
        if (garageScrim) { garageScrim.addEventListener('click', closeGarage); }
        document.getElementById('tqGarageAdd').addEventListener('click', function () { rawCloseA11ySheet(); openVin(); });

        /* ---------------- fitment computation (menu_id x active vehicle) ---------------- */
        computeFit = function (itemId) {
            var item = ITEMS[itemId];
            var rows = FITMENTS.filter(function (f) { return String(f.menu_id) === String(itemId); });
            if (!rows.length) { return { status: 'universal', reason: T.fitReasonUniversal, interchange: interchangeNums(item).join(', ') }; }
            var vehicle = getActiveVehicle();
            if (!vehicle) { return { status: 'unknown', reason: T.fitReasonUnknown, interchange: interchangeNums(item).join(', ') }; }
            var ymm = rows.filter(function (f) { return String(f.year) === String(vehicle.year) && eqi(f.make, vehicle.make) && eqi(f.model, vehicle.model); });
            if (!ymm.length) { return { status: 'nofit', reason: T.fitReasonNo.replace(':vehicle', vehicleLabel(vehicle)), interchange: interchangeNums(item).join(', ') }; }
            var exact = ymm.filter(function (f) { return (!f.engine || eqi(f.engine, vehicle.engine)) && (!f.submodel || eqi(f.submodel, vehicle.submodel)); });
            if (exact.length) { return { status: 'fit', reason: T.fitReasonExact.replace(':vehicle', vehicleLabel(vehicle)), interchange: interchangeNums(item).join(', ') }; }
            return { status: 'verify', reason: T.fitReasonVerify.replace(':model', vehicleLabel(vehicle)), interchange: interchangeNums(item).join(', ') };
        };

        function updateRecoChip() {
            var chip = document.getElementById('tqRecoChip');
            if (!chip) { return; }
            var vehicle = getActiveVehicle();
            var anyFit = cards.some(function (c) { return c.getAttribute('data-pf-fit') === '1'; });
            if (!vehicle || !anyFit) { chip.hidden = true; if (activeCat === '__reco') { setActiveCategory('all'); } return; }
            chip.hidden = false;
            chip.textContent = T.recommendedFor.replace(':model', vehicle.model);
        }

        updateAllFitChips = function () {
            cards.forEach(function (card) {
                var id = parseInt(card.getAttribute('data-id'), 10);
                var fit = computeFit(id);
                var chip = card.querySelector('[data-fitchip]');
                if (!chip) { return; }
                if (!fit || fit.status === 'unknown') { chip.hidden = true; card.removeAttribute('data-pf-fit'); return; }
                chip.hidden = false;
                chip.className = 'tq-fitchip ' + (fit.status === 'universal' ? 'fit' : fit.status);
                chip.innerHTML = fitIcon(fit.status) + '<span>' + fitLabel(fit.status) + '</span>';
                card.setAttribute('data-pf-fit', (fit.status === 'fit' || fit.status === 'universal') ? '1' : '0');
            });
            applyFilter();
            updateRecoChip();
        };

        refreshGaugeForCurrentItem = function () {
            var gaugeEl = document.getElementById('tqGauge');
            if (!gaugeEl || !currentItem) { return; }
            var fit = computeFit(currentItem.id);
            gaugeEl.hidden = false;
            gaugeEl.className = 'tq-gauge ' + (fit.status === 'universal' ? 'fit' : fit.status);
            var deg = (fit.status === 'fit' || fit.status === 'universal') ? 70 : (fit.status === 'verify' ? 0 : (fit.status === 'nofit' ? -70 : -20));
            var needle = document.getElementById('tqNeedle');
            if (needle) { needle.style.transform = 'rotate(' + deg + 'deg)'; }
            document.getElementById('tqGaugeTitle').textContent = GAUGE_TITLE[fit.status] || GAUGE_TITLE.unknown;
            document.getElementById('tqGaugeDesc').textContent = fit.reason;
            var interchangeEl = document.getElementById('tqInterchange');
            if (fit.interchange) {
                interchangeEl.hidden = false;
                document.getElementById('tqInterchangeText').textContent = T.interchangePrefix.replace(':numbers', fit.interchange);
            } else {
                interchangeEl.hidden = true;
            }
        };

        /* ---------------- OEM / cross-reference search (E5) — narrows the
           grid alongside the active-vehicle fit filter above (AND, not
           replace), same combination rule as shelf's own YMM+OEM bar. ---------------- */
        var oemSearchInput = document.getElementById('tqOemSearch');
        var oemTimer = null;
        if (oemSearchInput) {
            oemSearchInput.addEventListener('input', function () {
                var q = oemSearchInput.value.trim();
                if (oemTimer) { clearTimeout(oemTimer); }
                if (!q) { cards.forEach(function (c) { c.removeAttribute('data-oem-hide'); }); applyFilter(); return; }
                oemTimer = setTimeout(function () {
                    fetch(OEM_URL + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            var ids = {};
                            ((data && data.menu_ids) || []).forEach(function (id) { ids[String(id)] = true; });
                            cards.forEach(function (c) {
                                var id = c.getAttribute('data-id');
                                if (ids[id]) { c.removeAttribute('data-oem-hide'); } else { c.setAttribute('data-oem-hide', '1'); }
                            });
                            applyFilter();
                        })
                        .catch(function () { /* best-effort — search degrades to no-op */ });
                }, 250);
            });
        }

        /* ---------------- manual Year→Make→Model→Engine wizard (fallback) ---------------- */
        var wzScrim = document.getElementById('tqWzScrim');
        var wzModal = document.getElementById('tqWzModal');
        var wzState = {};
        var WZ_YEARS = [];
        function uniqueSorted(values) {
            var seen = {}, out = [];
            values.forEach(function (v) { var k = String(v); if (!seen[k]) { seen[k] = true; out.push(v); } });
            return out.sort(function (a, b) { return a > b ? 1 : (a < b ? -1 : 0); });
        }
        function wzMakesFor(year) { return uniqueSorted(FITMENTS.filter(function (f) { return String(f.year) === String(year); }).map(function (f) { return f.make; })); }
        function wzModelsFor(year, make) { return uniqueSorted(FITMENTS.filter(function (f) { return String(f.year) === String(year) && eqi(f.make, make); }).map(function (f) { return f.model; })); }
        function wzEnginesFor(year, make, model) { return uniqueSorted(FITMENTS.filter(function (f) { return String(f.year) === String(year) && eqi(f.make, make) && eqi(f.model, model); }).map(function (f) { return f.engine; }).filter(Boolean)); }

        function renderWizardStep() {
            var titles = [[T.pickYear, T.chooseYear], [T.pickMake, T.chooseMake], [T.pickModel, T.chooseModel], [T.pickEngine, T.chooseEngine]];
            var step = wzState.step || 1;
            document.getElementById('tqWzTitle').textContent = titles[step - 1][0];
            document.getElementById('tqWzSub').textContent = titles[step - 1][1];
            document.querySelectorAll('#tqWzProg i').forEach(function (i, idx) { i.classList.toggle('on', idx < step); });
            var optsWrap = document.getElementById('tqWzOptions');
            var values, isList = false;
            if (step === 1) { values = WZ_YEARS; }
            else if (step === 2) { values = wzMakesFor(wzState.year); }
            else if (step === 3) { values = wzModelsFor(wzState.year, wzState.make); }
            else { values = wzEnginesFor(wzState.year, wzState.make, wzState.model); isList = true; }

            if (!values.length && step === 4) { finishWizard(); return; } // engine is optional — finish without it when none are on file
            if (!values.length) { optsWrap.className = ''; optsWrap.innerHTML = '<p style="color:var(--tq-ink-2);font-size:.84rem">' + T.genericError + '</p>'; return; }

            optsWrap.className = isList ? 'tq-wz-list' : 'tq-wz-grid';
            optsWrap.innerHTML = values.map(function (v) {
                return '<button type="button" class="tq-wz-opt" data-pick="' + escapeHtml(String(v)) + '">' + escapeHtml(String(v)) + (isList ? svgIcon('chev', 'tq-chev') : '') + '</button>';
            }).join('');
            optsWrap.querySelectorAll('[data-pick]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var v = btn.getAttribute('data-pick');
                    if (step === 1) { wzState.year = v; wzState.step = 2; }
                    else if (step === 2) { wzState.make = v; wzState.step = 3; }
                    else if (step === 3) { wzState.model = v; wzState.step = 4; }
                    else { wzState.engine = v; finishWizard(); return; }
                    renderWizardStep();
                });
            });
        }
        function renderWizardFreeform() {
            wzState = { freeform: true };
            document.getElementById('tqWzTitle').textContent = @json(___('Add your vehicle'));
            document.getElementById('tqWzSub').textContent = @json(___('No vehicle data is on file yet — enter your vehicle details.'));
            document.querySelectorAll('#tqWzProg i').forEach(function (i) { i.classList.add('on'); });
            var wrap = document.getElementById('tqWzOptions');
            wrap.className = '';
            wrap.innerHTML = '<div class="tq-field"><label>' + T.pickYear + '</label><input type="number" id="tqWzFYear" min="1980" max="2100"></div>'
                + '<div class="tq-field"><label>' + T.pickMake + '</label><input type="text" id="tqWzFMake"></div>'
                + '<div class="tq-field"><label>' + T.pickModel + '</label><input type="text" id="tqWzFModel"></div>'
                + '<div class="tq-field"><label>' + T.pickEngine + '</label><input type="text" id="tqWzFEngine"></div>'
                + '<button type="button" class="tq-btn tq-btn--primary" id="tqWzFSave" style="width:100%">' + @json(___('Save vehicle')) + '</button>';
            document.getElementById('tqWzFSave').addEventListener('click', function () {
                var y = document.getElementById('tqWzFYear').value.trim();
                var mk = document.getElementById('tqWzFMake').value.trim();
                var md = document.getElementById('tqWzFModel').value.trim();
                var en = document.getElementById('tqWzFEngine').value.trim();
                if (!y || !mk || !md) { toast(T_VEHICLE_REQUIRED, 'alert'); return; }
                addVehicle({ year: y, make: mk, model: md, engine: en || null });
                closeWizard();
            });
        }
        function finishWizard() {
            addVehicle({ year: wzState.year, make: wzState.make, model: wzState.model, engine: wzState.engine || null });
            closeWizard();
        }
        function openWizard() {
            WZ_YEARS = uniqueSorted(FITMENTS.map(function (f) { return f.year; })).sort(function (a, b) { return b - a; });
            if (!WZ_YEARS.length) { renderWizardFreeform(); } else { wzState = { step: 1 }; renderWizardStep(); }
            openA11ySheet(wzModal, wzScrim, document.getElementById('tqWzTitle'));
        }
        function closeWizard() { dismissA11ySheet(); }
        document.getElementById('tqWzBack').addEventListener('click', function () {
            if (wzState.freeform) { closeWizard(); return; }
            if ((wzState.step || 1) > 1) { wzState.step--; renderWizardStep(); } else { rawCloseA11ySheet(); openVin(); }
        });
        if (wzScrim) { wzScrim.addEventListener('click', closeWizard); }

        /* ---------------- VIN scan (real decode when a provider is wired
           up; always degrades to the manual wizard) ---------------- */
        var vinScrim = document.getElementById('tqVinScrim');
        var vinModal = document.getElementById('tqVinModal');
        var vinDecodedVehicle = null;
        function openVin() {
            document.getElementById('tqVinInput').value = '';
            document.getElementById('tqVinRead').textContent = '';
            document.getElementById('tqVinResult').classList.remove('show');
            document.getElementById('tqVinError').hidden = true;
            document.getElementById('tqVinContinue').disabled = true;
            vinDecodedVehicle = null;
            openA11ySheet(vinModal, vinScrim, document.getElementById('tqVinInput'));
        }
        function closeVin() { dismissA11ySheet(); }
        document.getElementById('tqVinClose').addEventListener('click', closeVin);
        if (vinScrim) { vinScrim.addEventListener('click', closeVin); }
        var scanBtn = document.getElementById('tqScanBtn');
        if (scanBtn) { scanBtn.addEventListener('click', openVin); }

        document.getElementById('tqVinDecodeBtn').addEventListener('click', function () {
            var vinInput = document.getElementById('tqVinInput');
            var vin = vinInput.value.trim().toUpperCase();
            var errEl = document.getElementById('tqVinError');
            var readEl = document.getElementById('tqVinRead');
            errEl.hidden = true;
            if (vin.length < 11) { errEl.hidden = false; errEl.textContent = T.vinInvalid; return; }
            if (!VIN_DECODE_URL) { errEl.hidden = false; errEl.textContent = T.vinUnavailable; return; }
            var btn = this;
            btn.disabled = true;
            readEl.textContent = T.decoding;
            var fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('vin', vin);
            fetch(VIN_DECODE_URL, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) { throw new Error('bad status'); } return r.json(); })
                .then(function (data) {
                    if (!data || !data.year || !data.make || !data.model) { throw new Error('incomplete'); }
                    vinDecodedVehicle = { year: data.year, make: data.make, model: data.model, engine: data.engine || null, vin: vin };
                    readEl.textContent = 'VIN ' + vin;
                    document.getElementById('tqVinResult').classList.add('show');
                    document.getElementById('tqVinResultText').textContent = vehicleLabel(vinDecodedVehicle) + (vinDecodedVehicle.engine ? ' · ' + vinDecodedVehicle.engine : '');
                    document.getElementById('tqVinContinue').disabled = false;
                })
                .catch(function () {
                    readEl.textContent = '';
                    errEl.hidden = false;
                    errEl.textContent = T.vinNotFound;
                })
                .then(function () { btn.disabled = false; });
        });
        document.getElementById('tqVinContinue').addEventListener('click', function () {
            if (!vinDecodedVehicle) { return; }
            addVehicle(vinDecodedVehicle);
            closeVin();
        });
        document.getElementById('tqVinManual').addEventListener('click', function () { rawCloseA11ySheet(); openWizard(); });

        /* ---------------- boot: HUD + fitment-options fetch ---------------- */
        updateHud();
        fetch(OPTIONS_URL, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.enabled) { return; }
                FITMENTS = data.fitments || [];
                updateAllFitChips();
                refreshGaugeForCurrentItem();
            })
            .catch(function () { /* fitment features degrade to hidden/no-op */ });
    }

    /* =====================================================================
       Curated core features (loop/enrich) — stories, reviews, loyalty,
       wishlist. Every endpoint here is an existing, already-seeded
       platform route; nothing below adds or changes backend behaviour.
       ===================================================================== */

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ---------------- stories rail + full-screen viewer ---------------- */
    var storiesRail = document.getElementById('tqStories');
    var storyViewer = document.getElementById('tqStoryViewer');
    var storyProgress = document.getElementById('tqStoryProgress');
    var storyMedia = document.getElementById('tqStoryMedia');
    var storyCaption = document.getElementById('tqStoryCaption');
    var storyCta = document.getElementById('tqStoryCta');
    var stories = [];
    var storyIndex = 0;
    var storyTimeout = null;
    var STORY_VIEWED_KEY = 'torque-stories-viewed-' + storeId;
    var storyViewedIds = {};
    try {
        (JSON.parse(window.localStorage.getItem(STORY_VIEWED_KEY) || '[]') || []).forEach(function (id) { storyViewedIds[id] = true; });
    } catch (e) { storyViewedIds = {}; }

    function markStoryViewed(id) {
        if (storyViewedIds[id]) { return; }
        storyViewedIds[id] = true;
        try { window.localStorage.setItem(STORY_VIEWED_KEY, JSON.stringify(Object.keys(storyViewedIds))); } catch (e) { /* best-effort */ }
        var bubble = storiesRail.children[stories.map(function (s) { return s.id; }).indexOf(id)];
        if (bubble) { bubble.classList.add('is-viewed'); }
    }

    function renderStoryBubbles() {
        storiesRail.innerHTML = '';
        stories.forEach(function (s, idx) {
            var url = s.media_url || s.media_full_url || '';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tq-story' + (storyViewedIds[s.id] ? ' is-viewed' : '');
            btn.setAttribute('role', 'listitem');
            btn.setAttribute('aria-label', s.caption || (T.story + ' ' + (idx + 1)));
            var ring = document.createElement('span');
            ring.className = 'tq-story__ring';
            var img = document.createElement('img');
            img.className = 'tq-story__thumb';
            img.alt = '';
            img.setAttribute('data-csp-error', 'hide');
            if (url) { img.src = url; }
            ring.appendChild(img);
            btn.appendChild(ring);
            if (s.caption) {
                var label = document.createElement('span');
                label.className = 'tq-story__label';
                label.textContent = s.caption;
                btn.appendChild(label);
            }
            btn.addEventListener('click', function () { openStoryViewer(idx); });
            storiesRail.appendChild(btn);
        });
        storiesRail.hidden = stories.length === 0;
    }

    function buildStoryProgressBars() {
        storyProgress.innerHTML = '';
        stories.forEach(function () {
            var bar = document.createElement('span');
            bar.className = 'tq-story-viewer__bar';
            var fill = document.createElement('span');
            fill.className = 'tq-story-viewer__bar-fill';
            bar.appendChild(fill);
            storyProgress.appendChild(bar);
        });
    }

    var STORY_DURATION = 5000;

    function showStory(idx) {
        clearTimeout(storyTimeout);
        if (idx < 0) { idx = 0; }
        if (idx >= stories.length) { closeStoryViewer(); return; }
        storyIndex = idx;
        var s = stories[idx];
        markStoryViewed(s.id);

        var bars = storyProgress.children;
        for (var i = 0; i < bars.length; i++) {
            var fill = bars[i].querySelector('.tq-story-viewer__bar-fill');
            bars[i].classList.remove('is-active');
            bars[i].classList.toggle('is-done', i < idx);
            fill.style.transition = 'none';
            fill.style.inlineSize = i < idx ? '100%' : '0%';
        }

        var url = s.media_url || s.media_full_url || '';
        storyMedia.src = url;
        storyMedia.alt = s.caption || '';
        if (s.caption) { storyCaption.textContent = s.caption; storyCaption.hidden = false; }
        else { storyCaption.hidden = true; }
        if (s.cta_url) {
            storyCta.textContent = s.cta_label || '';
            storyCta.href = s.cta_url;
            var external = /^[a-z][a-z0-9+.-]*:\/\//i.test(s.cta_url) && s.cta_url.indexOf(window.location.origin) !== 0;
            if (external) { storyCta.target = '_blank'; storyCta.setAttribute('rel', 'noopener'); }
            else { storyCta.removeAttribute('target'); storyCta.removeAttribute('rel'); }
            storyCta.hidden = false;
        } else {
            storyCta.hidden = true;
        }

        var curBar = bars[idx];
        if (curBar) {
            var curFill = curBar.querySelector('.tq-story-viewer__bar-fill');
            curBar.classList.add('is-active');
            // Force reflow so the transition from 0 -> 100 actually animates.
            void curFill.offsetWidth;
            curFill.style.transition = 'inline-size ' + STORY_DURATION + 'ms linear';
            curFill.style.inlineSize = '100%';
        }
        storyTimeout = setTimeout(function () {
            if (storyViewer.classList.contains('is-open')) { showStory(idx + 1); }
        }, STORY_DURATION);
    }

    function openStoryViewer(startIndex) {
        if (!stories.length) { return; }
        buildStoryProgressBars();
        openA11ySheet(storyViewer, storyViewer, document.getElementById('tqStoryClose'));
        showStory(startIndex || 0);
    }
    function closeStoryViewer() {
        clearTimeout(storyTimeout);
        dismissA11ySheet();
    }

    document.getElementById('tqStoryClose').addEventListener('click', closeStoryViewer);
    document.getElementById('tqStoryPrev').addEventListener('click', function () { showStory(storyIndex - 1); });
    document.getElementById('tqStoryNext').addEventListener('click', function () { showStory(storyIndex + 1); });
    // Tapping the CTA must always dismiss the viewer, otherwise the destination
    // (or the storefront beneath) stays hidden behind the open overlay and the
    // button "does nothing". A link to the page we're already on (the common
    // demo case, cta_url = the store home) would only reload onto the same view;
    // on this single-page storefront that reads as a dead button. So suppress the
    // navigation and instead take the shopper to the parts catalog — the point of
    // an "Explore" CTA — scrolling it clear of the sticky header.
    storyCta.addEventListener('click', function (e) {
        var href = storyCta.getAttribute('href') || '';
        var dest = href.split('#')[0].split('?')[0].replace(/\/+$/, '');
        var here = window.location.pathname.replace(/\/+$/, '');
        var samePage = !href || href.charAt(0) === '#' || dest === here;
        closeStoryViewer();
        if (samePage) {
            e.preventDefault();
            var target = document.getElementById('tqCatTiles')
                || document.getElementById('tqGridHeading')
                || document.getElementById('tqGrid');
            if (target) {
                setTimeout(function () { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 60);
            }
        }
    });

    fetch(STORIES_URL, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (payload) {
            var list = payload && (Array.isArray(payload) ? payload : payload.data);
            stories = Array.isArray(list) ? list.slice().sort(function (a, b) { return (a.sort || 0) - (b.sort || 0); }) : [];
            if (stories.length) { renderStoryBubbles(); }
        })
        .catch(function () { /* leave the rail hidden — never an empty shell */ });

    /* ---------------- reviews (product detail sheet) ---------------- */
    var rvGroup = document.getElementById('tqReviewsGroup');
    var rvHead = document.getElementById('tqRvHead');
    var rvAvgNum = document.getElementById('tqRvAvgNum');
    var rvAvgStars = document.getElementById('tqRvAvgStars');
    var rvCount = document.getElementById('tqRvCount');
    var rvDist = document.getElementById('tqRvDist');
    var rvList = document.getElementById('tqRvList');
    var rvEmpty = document.getElementById('tqRvEmpty');
    var rvForm = document.getElementById('tqRvForm');
    var rvWriteToggle = document.getElementById('tqRvWriteToggle');
    var rvMsg = document.getElementById('tqRvMsg');
    var rvSubmit = document.getElementById('tqRvSubmit');
    var rvChosenStars = 0;
    var rvCurrentMenuId = null;
    var rvSeq = 0;

    function starGlyphs(n) {
        n = Math.round(Number(n) || 0);
        var out = '';
        for (var i = 1; i <= 5; i++) { out += '<span' + (i > n ? ' class="is-off"' : '') + '>★</span>'; }
        return out;
    }

    function relativeTime(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso);
        if (isNaN(d.getTime())) { return ''; }
        var diffSec = Math.round((d.getTime() - Date.now()) / 1000);
        var units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
        try {
            var rtf = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' });
            for (var i = 0; i < units.length; i++) {
                if (Math.abs(diffSec) >= units[i][1]) { return rtf.format(Math.round(diffSec / units[i][1]), units[i][0]); }
            }
            return rtf.format(diffSec, 'second');
        } catch (e) {
            try { return d.toLocaleDateString(); } catch (e2) { return ''; }
        }
    }

    function paintRvStarPicker() {
        Array.prototype.forEach.call(document.querySelectorAll('#tqRvStarPicker [data-rv-star]'), function (b) {
            b.classList.toggle('is-on', Number(b.getAttribute('data-rv-star')) <= rvChosenStars);
        });
    }
    document.querySelectorAll('#tqRvStarPicker [data-rv-star]').forEach(function (b) {
        b.addEventListener('click', function () {
            rvChosenStars = Number(b.getAttribute('data-rv-star'));
            paintRvStarPicker();
        });
    });

    function resetRvForm() {
        rvChosenStars = 0;
        paintRvStarPicker();
        document.getElementById('tqRvName').value = '';
        document.getElementById('tqRvBody').value = '';
        rvMsg.innerHTML = '';
        rvSubmit.disabled = false;
        rvForm.classList.remove('is-open');
    }

    function renderReviews(payload) {
        var list = (payload && payload.data) || [];
        var count = Number((payload && payload.count) || 0);
        var avg = Number((payload && payload.average) || 0);
        var dist = (payload && payload.distribution) || {};

        rvHead.hidden = count === 0;
        if (count > 0) {
            rvAvgNum.textContent = avg.toFixed(1);
            rvAvgStars.innerHTML = starGlyphs(avg);
            rvCount.textContent = count + ' ' + (count === 1 ? T.reviewWord : T.reviewsWord);
        }

        rvDist.hidden = count === 0;
        rvDist.innerHTML = '';
        if (count > 0) {
            [5, 4, 3, 2, 1].forEach(function (star) {
                var c = Number(dist[star] || 0);
                var pct = count ? Math.round((c / count) * 100) : 0;
                var row = document.createElement('div');
                row.className = 'tq-rv-dist-row';
                row.innerHTML = '<span>' + star + '★</span><i><s style="width:' + pct + '%"></s></i><span>' + c + '</span>';
                rvDist.appendChild(row);
            });
        }

        rvList.innerHTML = '';
        list.forEach(function (r) {
            var item = document.createElement('div');
            item.className = 'tq-rv-item';
            var name = r.name || r.reviewer_name || T.anonymous;
            item.innerHTML = '<div class="tq-rv-item__top">'
                + '<span class="tq-rv-stars">' + starGlyphs(r.stars) + '</span>'
                + '<span class="tq-rv-item__date">' + esc(relativeTime(r.created_at)) + '</span>'
                + '</div>'
                + '<div class="tq-rv-item__name">' + esc(name) + (r.is_verified ? '<span class="tq-rv-verified">' + esc(T.verified) + '</span>' : '') + '</div>'
                + (r.body ? '<p class="tq-rv-item__body">' + esc(r.body) + '</p>' : '');
            rvList.appendChild(item);
        });

        rvEmpty.hidden = count !== 0;
        if (count === 0) { rvForm.classList.add('is-open'); rvWriteToggle.hidden = true; }
        else { rvWriteToggle.hidden = false; }
    }

    function loadReviews(menuId) {
        rvCurrentMenuId = menuId;
        var mySeq = ++rvSeq;
        resetRvForm();
        if (!MBR_BASE || !rvGroup) { if (rvGroup) { rvGroup.hidden = true; } return; }
        rvGroup.hidden = false;
        rvHead.hidden = true;
        rvDist.hidden = true;
        rvList.innerHTML = '';
        rvEmpty.hidden = true;
        fetch(MBR_BASE + '/' + menuId + '/reviews?filter=latest', { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (payload) {
                if (mySeq !== rvSeq) { return; }
                if (!payload) { rvGroup.hidden = true; return; }
                renderReviews(payload);
            })
            .catch(function () { if (mySeq === rvSeq) { rvGroup.hidden = true; } });
    }

    rvWriteToggle.addEventListener('click', function () {
        rvForm.classList.toggle('is-open');
        if (rvForm.classList.contains('is-open')) {
            var firstStar = rvForm.querySelector('[data-rv-star="1"]');
            if (firstStar) { firstStar.focus(); }
        }
    });

    rvForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!rvCurrentMenuId) { return; }
        if (!rvChosenStars) {
            rvMsg.innerHTML = '<div class="tq-msg tq-msg--error">' + T.chooseRatingFirst + '</div>';
            return;
        }
        rvSubmit.disabled = true;
        rvMsg.innerHTML = '';
        fetch(MBR_BASE + '/' + rvCurrentMenuId + '/reviews', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({
                rating: rvChosenStars,
                text: document.getElementById('tqRvBody').value.trim() || null,
                name: document.getElementById('tqRvName').value.trim() || null,
            }),
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.ok) { throw new Error('review-store-failed'); }
                rvMsg.innerHTML = '<div class="tq-msg tq-msg--ok">' + T.reviewThanks + '</div>';
                rvChosenStars = 0;
                paintRvStarPicker();
                document.getElementById('tqRvName').value = '';
                document.getElementById('tqRvBody').value = '';
                rvSubmit.disabled = false;
            })
            .catch(function () {
                rvMsg.innerHTML = '<div class="tq-msg tq-msg--error">' + T.reviewFailed + '</div>';
                rvSubmit.disabled = false;
            });
    });

    /* ---------------- loyalty (Rewards card + checkout redeem row) ---------------- */
    function loyaltyTeaserLine() {
        if (!LOY_TEASER) { return ''; }
        return [LOY_TEASER.program, LOY_TEASER.earn, LOY_TEASER.reward].filter(Boolean).join(' · ');
    }

    function renderRewardsCardBalance(data) {
        var area = document.getElementById('tqRewardsBalanceArea');
        var signin = document.getElementById('tqRewardsSignin');
        if (!area) { return; }
        if (data && data.active) {
            signin.hidden = true;
            var pct = Math.max(0, Math.min(100, Number(data.progress_pct) || 0));
            area.innerHTML = '<div class="tq-rewards-card__balance">' + esc(String(data.balance)) + ' <small>' + esc(T.points) + '</small></div>'
                + '<div class="tq-rewards-progress"><s style="width:' + pct + '%"></s></div>';
            document.querySelectorAll('#tqTierChips .tq-tierchip').forEach(function (chip) {
                chip.classList.toggle('is-current', String(data.tier || '').toLowerCase() === chip.getAttribute('data-tier'));
            });
        } else {
            area.innerHTML = '';
            signin.hidden = false;
        }
    }

    function loadRewardsBalance() {
        if (!document.getElementById('tqRewardsBalanceArea')) { return; }
        fetch(LOYALTY_BALANCE_URL, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : { active: false }; })
            .then(renderRewardsCardBalance)
            .catch(function () { renderRewardsCardBalance({ active: false }); });
    }

    function renderLoyaltyRow(data) {
        var row = document.getElementById('tqLoyaltyRow');
        var teaserEl = document.getElementById('tqLoyaltyTeaser');
        var signinEl = document.getElementById('tqLoyaltySignin');
        var toggle = document.getElementById('tqLoyaltyToggle');
        var input = document.getElementById('tqLoyaltyRedeemInput');
        if (!row) { return; }

        var active = !!(data && data.active);
        var readyToRedeem = active && !!data.ready_to_redeem;
        row.hidden = !LOY_TEASER && !active;
        if (row.hidden) { return; }

        toggle.hidden = !readyToRedeem;
        if (!readyToRedeem) {
            input.value = '0';
            toggle.classList.remove('is-on');
            toggle.setAttribute('aria-pressed', 'false');
        } else {
            document.getElementById('tqLoyaltyToggleLabel').textContent = T.applyReward.replace(':amount', data.reward_discount_display || '');
        }

        var teaserText = loyaltyTeaserLine();
        teaserEl.hidden = readyToRedeem || !teaserText;
        if (!teaserEl.hidden) { teaserEl.textContent = teaserText; }
        signinEl.hidden = active || readyToRedeem;
    }

    function loadLoyaltyRow() {
        fetch(LOYALTY_BALANCE_URL, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : { active: false }; })
            .then(renderLoyaltyRow)
            .catch(function () { renderLoyaltyRow({ active: false }); });
    }

    var loyaltyToggleBtn = document.getElementById('tqLoyaltyToggle');
    if (loyaltyToggleBtn) {
        loyaltyToggleBtn.addEventListener('click', function () {
            var on = !this.classList.contains('is-on');
            this.classList.toggle('is-on', on);
            this.setAttribute('aria-pressed', on ? 'true' : 'false');
            document.getElementById('tqLoyaltyRedeemInput').value = on ? '1' : '0';
        });
    }

    var accountScrim = document.getElementById('tqAccountScrim');
    var accountSheet = document.getElementById('tqAccountSheet');
    document.getElementById('tqAccountOpen').addEventListener('click', function () {
        loadRewardsBalance();
        tqRememberView('orders');
        openA11ySheet(accountSheet, accountScrim, document.getElementById('tqAccountTitle'));
    });
    document.getElementById('tqAccountClose').addEventListener('click', function () { dismissA11ySheet(); });
    if (accountScrim) { accountScrim.addEventListener('click', function () { dismissA11ySheet(); }); }

    /* Clear saved data on this device — Account-sheet row (the Account sheet is
       guest-reachable). Wipes torque's own cart/favourites/stories keys plus its
       saved-vehicle "garage" + odometer (its saved-address analog); the shared
       engine keeps the light/dark preference (torque-theme-* matches /theme/i). */
    (function () {
        var cd = document.getElementById('tqClearData');
        if (!cd) { return; }
        if (!window.mbClearDeviceData) { cd.style.display = 'none'; var h = document.querySelector('.tq-cleardata-hint'); if (h) { h.style.display = 'none'; } return; }
        cd.addEventListener('click', function () {
            window.mbClearDeviceData({
                keys: ['torque-cart-' + storeId, 'torque-favs-' + storeId, 'torque-stories-viewed-' + storeId, 'torque-garage-' + storeId, 'torque-odo-' + storeId],
                storeId: storeId,
                slug: @json($store->slug ?? ''),
                confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
            });
        });
    })();

    /* ---------------- wishlist: card heart + "Saved" view ---------------- */
    var FAV_KEY = 'torque-favs-' + storeId;
    var favIds = {};
    try {
        (JSON.parse(window.localStorage.getItem(FAV_KEY) || '[]') || []).forEach(function (id) { favIds[id] = true; });
    } catch (e) { favIds = {}; }
    function saveFavs() {
        try { window.localStorage.setItem(FAV_KEY, JSON.stringify(Object.keys(favIds))); } catch (e) { /* best-effort */ }
    }
    function paintFavButton(btn, isFav) {
        btn.classList.toggle('is-fav', isFav);
        btn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
        btn.setAttribute('aria-label', isFav ? T.removeFav : T.addFav);
    }
    document.querySelectorAll('[data-fav-toggle]').forEach(function (btn) {
        var id = btn.getAttribute('data-fav-toggle');
        paintFavButton(btn, !!favIds[id]);
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var isFav = !favIds[id];
            if (isFav) { favIds[id] = true; } else { delete favIds[id]; }
            paintFavButton(btn, isFav);
            saveFavs();
            renderSavedBadge();
        });
    });

    var savedScrim = document.getElementById('tqSavedScrim');
    var savedSheet = document.getElementById('tqSavedSheet');

    function renderSavedBadge() {
        var badge = document.getElementById('tqSavedBadge');
        if (!badge) { return; }
        var n = Object.keys(favIds).length;
        badge.textContent = String(n);
        badge.hidden = n === 0;
    }

    function renderSaved() {
        var wrap = document.getElementById('tqSavedLines');
        var empty = document.getElementById('tqSavedEmpty');
        wrap.innerHTML = '';
        var ids = Object.keys(favIds);
        if (!ids.length) { empty.hidden = false; renderSavedBadge(); return; }
        empty.hidden = true;
        ids.forEach(function (idStr) {
            var item = ITEMS[idStr];
            if (!item) { return; }
            var row = document.createElement('div');
            row.className = 'tq-saved-line';
            var img = document.createElement('img');
            img.alt = '';
            img.setAttribute('data-csp-error', 'hide');
            if (item.img) { img.src = item.img; } else { img.hidden = true; }
            var body = document.createElement('div');
            body.className = 'tq-saved-line__body';
            var name = document.createElement('div');
            name.className = 'tq-saved-line__name';
            name.textContent = item.name;
            var price = document.createElement('div');
            price.className = 'tq-saved-line__price';
            price.textContent = item.priceFormatted;
            body.appendChild(name);
            body.appendChild(price);
            var actions = document.createElement('div');
            actions.className = 'tq-saved-line__actions';
            var viewBtn = document.createElement('button');
            viewBtn.type = 'button';
            viewBtn.className = 'tq-btn tq-btn--ghost';
            viewBtn.textContent = T.view;
            viewBtn.addEventListener('click', function () { rawCloseA11ySheet(); openItem(item.id); });
            var rmBtn = document.createElement('button');
            rmBtn.type = 'button';
            rmBtn.className = 'tq-btn tq-btn--ghost';
            rmBtn.textContent = T.remove;
            rmBtn.addEventListener('click', function () {
                delete favIds[idStr];
                saveFavs();
                var cardBtn = document.querySelector('[data-fav-toggle="' + idStr + '"]');
                if (cardBtn) { paintFavButton(cardBtn, false); }
                renderSaved();
            });
            actions.appendChild(viewBtn);
            actions.appendChild(rmBtn);
            row.appendChild(img);
            row.appendChild(body);
            row.appendChild(actions);
            wrap.appendChild(row);
        });
        renderSavedBadge();
    }

    document.getElementById('tqSavedOpen').addEventListener('click', function () {
        renderSaved();
        tqRememberView('saved');
        openA11ySheet(savedSheet, savedScrim, document.getElementById('tqSavedTitle'));
    });
    document.getElementById('tqSavedClose').addEventListener('click', function () { dismissA11ySheet(); });
    if (savedScrim) { savedScrim.addEventListener('click', function () { dismissA11ySheet(); }); }

    renderSavedBadge();

    /* crave-parity: order history (in the account sheet) */
    (function () {
        var list = document.getElementById('tqOrdersList');
        if (!list || !MY_ORDERS_ROUTE) { return; }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        // Device-local guest history: a guest (no account) can't reach the
        // auth:customer orders endpoint, so render THIS device's recent orders
        // (Track + Reorder) plus a sign-in-to-sync note. Falls back to the
        // existing sign-in prompt when this device has none.
        function renderGuestLocal(listEl) {
            var local = (window.mbGuestOrders ? window.mbGuestOrders.list(storeId) : []);
            if (!local.length) { listEl.innerHTML = ''; return 0; }
            listEl.innerHTML = local.map(function (o) {
                var d = o.at ? new Date(o.at) : null;
                var when = d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                var ids = (o.itemIds || []);
                var canReorder = ids.some(function (id) { return ITEMS[id] || ITEMS[String(id)]; });
                return '<div class="tq-order"><div class="tq-order-top"><b>' + @json(___('Recent order')) + '</b></div>'
                    + '<div class="tq-order-meta">' + esc(when) + (o.itemCount ? ' · ' + o.itemCount : '') + (o.total ? ' · ' + esc(o.total) : '') + '</div>'
                    + ((o.trackUrl || canReorder) ? '<div class="tq-order-actions">'
                        + (o.trackUrl ? '<a class="tq-order-btn" href="' + esc(o.trackUrl) + '">' + @json(___('Track')) + '</a>' : '')
                        + (canReorder ? '<button type="button" class="tq-order-btn tq-order-btn--p" data-reorder="' + esc(ids.join(',')) + '">' + @json(___('Reorder')) + '</button>' : '')
                        + '</div>' : '')
                    + '</div>';
            }).join('')
                + '<div class="tq-order tq-order--signin"><div class="tq-order-meta">' + @json(___('Sign in to sync your orders across devices.')) + '</div>'
                + '<button type="button" class="tq-btn" data-mb-auth-open style="width:100%;margin-top:8px">' + @json(___('Sign in')) + '</button></div>';
            return local.length;
        }
        // Reorder from a device-local guest row: re-add each still-available
        // item to the cart, then open it. Delegated so it survives re-renders.
        list.addEventListener('click', function (e) {
            var rb = e.target.closest && e.target.closest('[data-reorder]');
            if (!rb) { return; }
            var ids = (rb.getAttribute('data-reorder') || '').split(',').filter(Boolean);
            if (!ids.length) { return; }
            rb.disabled = true;
            Promise.all(ids.map(function (id) {
                var k = ITEMS[id] ? id : (ITEMS[String(id)] ? String(id) : null);
                if (!k) { return Promise.resolve(); }
                return postAddToCart(k, null, 1).then(function (d) { addToCart(k, null, 1, d.line_id); }).catch(function () {});
            })).then(function () {
                rb.disabled = false;
                if (typeof toast === 'function') { toast(T.added, 'check'); }
                if (typeof openCart === 'function') { openCart(); }
            });
        });
        if (@json(! auth('customer')->check())) {
            if (!renderGuestLocal(list)) { var g = document.getElementById('tqOrdersGuest'); if (g) { g.hidden = false; } }
            return;
        }
        var LBL = { unpaid: @json(___('Awaiting payment')), pending: @json(___('Received')), new: @json(___('Received')), preparing: @json(___('Preparing')), ready: @json(___('Ready')), out_for_delivery: @json(___('On the way')), delivered: @json(___('Delivered')), completed: @json(___('Completed')), cancelled: @json(___('Cancelled')) };
        function lbl(s) { return LBL[s] || (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g, ' ') : ''); }
        fetch(MY_ORDERS_ROUTE, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (r.status === 401) { throw 'guest'; } return r.json(); })
            .then(function (res) {
                var orders = (res && res.orders) || [];
                if (!orders.length) { document.getElementById('tqOrdersEmpty').hidden = false; return; }
                list.innerHTML = orders.map(function (o) {
                    var d = o.at ? new Date(o.at) : null;
                    var when = d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                    return '<div class="tq-order"><div class="tq-order-top"><b>' + esc(o.number) + '</b><span class="tq-order-status">' + esc(lbl(o.status)) + '</span></div><div class="tq-order-meta">' + esc(when) + ' · ' + o.itemCount + ' · ' + esc(o.total) + '</div>' + (o.trackUrl ? '<a class="tq-order-track" href="' + esc(o.trackUrl) + '">' + @json(___('Track')) + '</a>' : '') + (o.canRequestRefund ? '<button type="button" class="tq-order-track" data-refund-request="' + esc(o.id) + '">' + @json(___('Request refund')) + '</button>' : '') + (o.refundStatus ? '<span class="mb-refund mb-refund--' + esc(o.refundStatus) + '">' + mbRefundLabel(o.refundStatus, o.refundMethod) + '</span>' : '') + '</div>';
                }).join('');
            })
            .catch(function (e) { if (e === 'guest') { document.getElementById('tqOrdersGuest').hidden = false; } });
    })();

    /* crave-parity: coupon apply preview */
    /* Bug batch 7 · 4: the cart lines go to the coupon check (submitOrder's `items` shape) — a buy-X-get-Y coupon is
       priced on them, exactly as the placed order is. */
    function couponLines() {
        return cart.map(function (l) {
            return { id: l.id, quantity: l.qty, variants: l.variantId || 0, extras: (l.extras || []).map(function (x) { return { id: (x && x.id) || x }; }) };
        });
    }
    (function () {
        var applyBtn = document.getElementById('tqCouponApply');
        if (!applyBtn || !COUPON_ROUTE) { return; }
        applyBtn.addEventListener('click', function () {
            var code = (document.getElementById('tqCouponInput').value || '').trim();
            var msgEl = document.getElementById('tqCouponMsg');
            var field = document.getElementById('tqCouponField');
            if (!code) { return; }
            var sub = cart.reduce(function (s, l) { return s + lineTotal(l); }, 0);
            var fd = new FormData();
            fd.append('_token', CSRF); fd.append('code', code); fd.append('subtotal', sub);
            fd.append('items', JSON.stringify(couponLines()));
            applyBtn.disabled = true;
            fetch(COUPON_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    applyBtn.disabled = false; msgEl.hidden = false;
                    if (d && d.success) { field.value = code; msgEl.textContent = (d.message || @json(___('Coupon applied'))) + ' · -' + (d.discount || ''); msgEl.classList.remove('is-bad'); }
                    else { field.value = ''; msgEl.textContent = (d && d.message) || @json(___('Coupon code is invalid.')); msgEl.classList.add('is-bad'); }
                })
                .catch(function () { applyBtn.disabled = false; });
        });
    })();

    /* crave-parity: branch switch */
    (function () {
        var bs = document.getElementById('tqBranchSel');
        if (bs) { bs.addEventListener('change', function (e) { if (e.target.value) { window.location.assign(e.target.value); } }); }
    })();

    /* Promo-banner CTA: a banner without a real external link jumps to the
       catalog grid instead of being a dead href="#". Any open story/overlay
       viewer is closed first so the scroll target is actually visible. */
    Array.prototype.forEach.call(document.querySelectorAll('.tq-banner[data-banner-catalog]'), function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var viewer = document.getElementById('tqStoryViewer');
            if (viewer && viewer.classList.contains('is-open') && typeof closeStoryViewer === 'function') { closeStoryViewer(); }
            var target = document.getElementById('tqGridHeading') || document.getElementById('tqGrid') || document.getElementById('tqChips');
            if (target && target.scrollIntoView) { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    });

    /* Bottom tabbar (small screens): proxy the existing topbar controls so
       there's a single source of truth for each action. Home scrolls to top
       and closes any open overlay. Cart/Saved badges are mirrored from the
       topbar badges. */
    (function () {
        function proxy(tabId, targetId) {
            var tab = document.getElementById(tabId), target = document.getElementById(targetId);
            if (tab && target) { tab.addEventListener('click', function () { target.click(); }); }
        }
        proxy('tqTabSaved', 'tqSavedOpen');
        proxy('tqTabCart', 'tqCartOpen');
        proxy('tqTabAccount', 'tqAccountOpen');
        var home = document.getElementById('tqTabHome');
        if (home) {
            home.addEventListener('click', function () {
                var viewer = document.getElementById('tqStoryViewer');
                if (viewer && viewer.classList.contains('is-open') && typeof closeStoryViewer === 'function') { closeStoryViewer(); }
                else if (typeof dismissA11ySheet === 'function') { dismissA11ySheet(); }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
        function mirror(srcId, dstId) {
            var src = document.getElementById(srcId), dst = document.getElementById(dstId);
            if (!src || !dst) { return; }
            function sync() { dst.textContent = src.textContent; dst.hidden = src.hidden; }
            sync();
            new MutationObserver(sync).observe(src, { attributes: true, childList: true, characterData: true, subtree: true });
        }
        mirror('tqCartBadge', 'tqTabCartBadge');
        mirror('tqSavedBadge', 'tqTabSavedBadge');
    })();

    /* crave-parity: promo banner slider */
    (function () {
        var track = document.getElementById('tqBannerTrack');
        var dotsWrap = document.getElementById('tqBannerDots');
        if (!track) { return; }
        var slides = track.children;
        if (slides.length < 2) { return; }
        function scrollToSlide(i) {
            var s = slides[i]; if (!s) { return; }
            // Scroll the track horizontally only. scrollIntoView() would scroll the
            // whole PAGE up to reveal the banner whenever it is out of view (the
            // auto-advance timer then yanks the shopper back to the top). A relative
            // scrollBy delta from the visual centers is RTL-safe.
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

    /* Restore the shopper's place across a same-tab reload (a language switch is
       a full navigation, which otherwise drops them on a bare home and makes
       their order history "look gone"). Runs once, after the ?item deep-link
       handling above; skipped when a ?item deep-link is driving the page, and a
       no-op for the default home view. Re-opens the remembered sheet through the
       same paths a click would, so its close/back still behave normally. */
    (function restoreView() {
        try {
            if (new URLSearchParams(window.location.search).get('item')) { return; }
            var saved = window.sessionStorage.getItem('mb-screen-' + storeId);
            if (!saved || saved === 'home') { return; }
            if (saved === 'orders' && accountSheet) {
                loadRewardsBalance();
                openA11ySheet(accountSheet, accountScrim, document.getElementById('tqAccountTitle'));
            } else if (saved === 'saved' && savedSheet) {
                renderSaved();
                openA11ySheet(savedSheet, savedScrim, document.getElementById('tqSavedTitle'));
            } else if (saved === 'cart' && cartSheet) {
                openCart();
            }
        } catch (e) { /* best-effort */ }
    })();
})();
</script>
@endpush
