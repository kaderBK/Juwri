{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.maison.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;

    /* ---------------------------------------------------------------------
       maison — editorial luxe clothing storefront · data preparation.

       Clones store_templates/shelf/index.blade.php's data-prep block (same
       $vocab / $categories / $items / $jsItems / currency / $modes /
       $checkoutRoute / $cartLineRouteTemplate contract, so this theme wires
       into the SAME goods cart + checkout backend shelf already uses — Lane
       V integration fix 1) and adapts it for the clothing vertical:
         - no OTC disclaimer / age-gate (clothing's `age_gate` capability is
           false — see config/verticals.php — so shelf's PharmacyCompliance
           branch would always be a silent no-op here; dropped entirely
           rather than carried as dead code).
         - no carparts YMM/fitment filter bar (clothing's `fitment` is false
           too).
         - variants are split into COLOR swatches + SIZE pills (not a single
           combined label) for the product detail screen, per axes shape
           {"Size":"M","Color":"Red"} (MenuVariant::getAxesLabelAttribute).
         - a defensive, opt-in "compare-at price" reader for the editorial
           card's discount pill: no such column exists on `menus` today, so
           this reads it out of the same freeform `attributes` JSON Lane A's
           attrs column already carries (?? null throughout) — a silent
           no-op (no pill) for every store until a merchant/future migration
           actually sets one, exactly like shelf's own A1/A2 defensive reads.
       --------------------------------------------------------------------- */
    $vocabSvc = app(VerticalVocab::class);
    $vocab    = $vocabSvc->map($store->store_type ?? null);

    $items = collect($total_menus)->values();

    // Two-tier-free category nav: top-level categories that own at least one
    // descendant item (own item OR a subcategory's item) — same "root"
    // mapping shelf/crave/aurora use, so nested categories still group
    // correctly under one chip.
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

    // Defensive attribute readers (same shape as shelf's $attrsOf/$brandOf) —
    // `attributes` is a freeform JSON cell; degrades to null/[] rather than
    // erroring when the column or relation isn't there.
    $attrsOf = \Juwri\Core\Support\ViewHelpers::attrsOf(...);
    $brandOf = \Juwri\Core\Support\ViewHelpers::brandOf(...);

    // Best-effort real color for a swatch: parse a hex code or a common color
    // NAME out of the option label text ("Red", "Navy Blue", "#2563eb"...).
    // Unrecognised labels fall back to a deterministic pick from the
    // editorial "earth" palette (design spec) — keyed off the label's own
    // text so the same color name always renders the same swatch — rather
    // than a flat neutral dot, so a product's color rail always reads as
    // intentional even before real hex data exists per-variant.
    $namedSwatchColors = [
        'black' => '#111111', 'white' => '#ffffff', 'red' => '#d33333', 'blue' => '#2563eb',
        'green' => '#16a34a', 'yellow' => '#eab308', 'orange' => '#f97316', 'purple' => '#9333ea',
        'pink' => '#ec4899', 'brown' => '#8b5e3c', 'grey' => '#9ca3af', 'gray' => '#9ca3af',
        'navy' => '#1e3a8a', 'beige' => '#e8dcc8', 'gold' => '#d4af37', 'silver' => '#c0c0c0',
        'maroon' => '#7f1d1d', 'teal' => '#0d9488', 'cyan' => '#06b6d4', 'olive' => '#6b7a1a',
        'khaki' => '#c3b091', 'cream' => '#f5f0dc', 'camel' => '#a9784e', 'terracotta' => '#a9503b',
        'blush' => '#d9afae', 'charcoal' => '#3c3a38',
    ];
    $earthPalette = ['#A9784E', '#A9503B', '#6B7A4A', '#7C93A6', '#D9AFAE', '#3C3A38'];
    $swatchColor = function (?string $label) use ($namedSwatchColors, $earthPalette): ?string {
        if (! $label) {
            return null;
        }
        $l = Str::lower($label);
        if (preg_match('/#(?:[0-9a-f]{3}|[0-9a-f]{6})\b/i', $l, $m)) {
            return $m[0];
        }
        foreach ($namedSwatchColors as $name => $hex) {
            if (str_contains($l, $name)) {
                return $hex;
            }
        }

        return $earthPalette[crc32($l) % count($earthPalette)];
    };

    // Best-effort axis reader for ONE variant: returns ['Color' => 'Red',
    // 'Size' => 'M', ...]. Handles both the matrix-generator axes shape
    // (string keys) AND the legacy {variantOptionId: choiceIndex} pivot shape
    // (resolved through the option group's own title as the axis name) —
    // same dual-shape handling as shelf's $variantLabel, just split per-axis
    // instead of joined into one string.
    $axesOf = function ($menu, $variant): array {
        $opts = (array) ($variant->options ?? []);
        $out = [];
        $isAxes = false;
        foreach ($opts as $key => $val) {
            if (is_string($key) && ! ctype_digit((string) $key) && (is_string($val) || is_numeric($val))) {
                $isAxes = true;
                if ($val !== '' && $val !== null) {
                    $out[$key] = (string) $val;
                }
            }
        }
        if ($isAxes) {
            return $out;
        }
        foreach ($opts as $optId => $choiceIdx) {
            $vo = $menu->variantOptions->firstWhere('id', (int) $optId);
            if (! $vo) {
                continue;
            }
            $choices = (array) $vo->options;
            if (! isset($choices[$choiceIdx])) {
                continue;
            }
            $axisName = $vo->title ?? $vo->name ?? ___('Option');
            $out[$axisName] = $choices[$choiceIdx];
        }

        return $out;
    };
    // Case-insensitive "find the axis whose name looks like X" — a merchant's
    // axis might be typed "Colour"/"color"/"COLOR"; matches on a substring so
    // any of those still map to the Color swatches / Size pills UI.
    $axisPick = function (array $axes, string $needle): ?string {
        foreach ($axes as $name => $val) {
            if (str_contains(Str::lower((string) $name), $needle)) {
                return $val;
            }
        }

        return null;
    };

    $curSignRaw  = $postOptions->currency_sign ?? config('settings.currency_sign', '$');
    $curSign     = cur_isolate($curSignRaw);
    $curGap      = currency_symbol_gap($curSignRaw);
    $curPos      = (string) currency_pos_effective($store->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);

    $msLanguages = collect($menu_languages ?? [])
        ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
        ->values();

    $taxCharge = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $taxType = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');

    $fulfillment = app(FulfillmentRegistry::class);
    $modes = \Juwri\Core\Support\ViewHelpers::fulfillmentModes($fulfillment, $store->store_type ?? null);

    $jsItems = [];
    foreach ($items as $m) {
        $variants = [];
        $colorSeen = [];
        $colors = [];
        $sizeSeen = [];
        $sizes = [];
        foreach ($m->variants ?? [] as $v) {
            $axes = $axesOf($m, $v);
            $colorLabel = $axisPick($axes, 'colo');
            $sizeLabel = $axisPick($axes, 'size');
            $label = ! empty($axes) ? implode(' · ', $axes) : ___('Option');
            $hex = $colorLabel ? $swatchColor($colorLabel) : null;

            $variants[] = [
                'id' => $v->id,
                'label' => $label,
                'color' => $colorLabel,
                'colorHex' => $hex,
                'size' => $sizeLabel,
                'price' => (float) $v->price,
                'priceFormatted' => price_symbol_format($v->price),
                'sku' => $v->sku ?? null,
                'stockQty' => isset($v->stock_qty) ? (int) $v->stock_qty : null,
            ];
            if ($colorLabel && ! isset($colorSeen[$colorLabel])) {
                $colorSeen[$colorLabel] = true;
                $colors[] = ['label' => $colorLabel, 'hex' => $hex];
            }
            if ($sizeLabel && ! isset($sizeSeen[$sizeLabel])) {
                $sizeSeen[$sizeLabel] = true;
                $sizes[] = $sizeLabel;
            }
        }

        $attrs = $attrsOf($m);
        $root = $rootOf[$m->category_id] ?? $m->category_id;

        // Opt-in compare-at price (see docblock above) — null on every store
        // until this key is actually set somewhere upstream.
        $compareAtRaw = $attrs['compare_at_price'] ?? $attrs['was_price'] ?? $attrs['original_price'] ?? null;
        $compareAt = is_numeric($compareAtRaw) ? (float) $compareAtRaw : null;
        $discountPct = ($compareAt && $compareAt > (float) $m->price)
            ? (int) round((($compareAt - (float) $m->price) / $compareAt) * 100)
            : null;

        // Gallery: primary image first, then every extra gallery image, all
        // resolved to public URLs (deduped). Powers the thumbnail strip in the
        // product sheet; the showcase seeds ≥2 images per item.
        $primaryUrl = ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '';
        $gallery = [];
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

        $jsItems[$m->id] = [
            'id' => $m->id,
            'cat' => $m->category_id,
            'root' => $root,
            'name' => $m->name,
            'desc' => strip_tags((string) $m->description),
            // Only a REAL uploaded image — never a food-themed default.png.
            'img' => $primaryUrl,
            'gallery' => $gallery,
            'price' => (float) $m->price,
            'priceFormatted' => price_symbol_format($m->price),
            'compareAt' => $compareAt,
            'compareAtFormatted' => $compareAt ? price_symbol_format($compareAt) : null,
            'discountPct' => $discountPct,
            'brand' => $brandOf($m),
            'sku' => $m->plu ?? $m->external_sku ?? null,
            'soldOut' => (bool) $m->is_sold_out,
            'trackInventory' => (bool) ($m->track_inventory ?? false),
            'stockQty' => isset($m->stock_qty) ? (int) $m->stock_qty : null,
            'variants' => $variants,
            'colors' => $colors,
            'sizes' => $sizes,
            'createdAt' => optional($m->created_at)->timestamp ?? 0,
            'addRoute' => route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $m->id]),
        ];
    }

    $brands = collect($jsItems)->pluck('brand')->filter()->unique()->sort()->values();

    // Crave-parity features (same backend data the food themes read; no controller change).
    $msIsOpen = store_open_now($store);
    $msHoursToday = storefront_hours_today($store);
    $msBranches = collect($branches ?? []);
    $msShowBranches = $msBranches->count() > 1 && (($postOptions->allow_multi_branch_switch ?? 1) != '0');
    $msActiveBranch = $activeBranch ?? null;
    $msBanners = \Juwri\Core\Support\ViewHelpers::activeBanners($menuBanners ?? []);
    $minPrice = $items->isNotEmpty() ? (float) $items->min('price') : 0;
    $maxPrice = $items->isNotEmpty() ? (float) $items->max('price') : 0;

    // "New in" editorial teaser (Home) — real data (newest by created_at),
    // never a fabricated "Featured"/"Editorial" curation flag (no such
    // column exists on `menus`).
    $newInItems = $items->sortByDesc(fn ($m) => optional($m->created_at)->timestamp ?? 0)->values()->take(8);
    // Featured 2-up editorial banner: only when at least 2 real product
    // photos exist to feature — never a stock/placeholder image.
    $editorialPicks = $newInItems->filter(fn ($m) => ! empty($jsItems[$m->id]['img']))->values()->take(2);

    $checkoutRoute = route('storefront.checkout.place', ['slug' => $store->slug]);
    $cartLineRouteTemplate = route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => 999999999]);
    $orderTrackRouteTemplate = route('store.orderTrack', 'MS_TRACK_TOKEN');

    /* ---------------------------------------------------------------------
       Curated core features (loop/enrich pack): stories, item reviews, and
       the loyalty teaser/redeem contract — see
       scratchpad/loop/FEATURE-WIRING.md. Endpoints verified against the real
       controllers (StoryController::publicIndex, MenuReviewController,
       LoyaltyController::customerBalance), NOT copy-pasted from the brief:
       stories responds `{data:[...]}` with a `media_url` key (already a
       resolved URL, despite the name) rather than a bare array of
       `media_full_url`; reviews validate `rating`/`text`/`name` and present
       `name` (not `stars`/`body`/`reviewer_name`) in the list.
       --------------------------------------------------------------------- */
    $storiesRoute = url('r/'.$store->id.'/stories');
    $reviewsBase = url('r/'.$store->id.'/item');
    $loyaltyBalanceRoute = url('restaurant/'.$store->id.'/loyalty/balance');
    // store_options() decodes a JSON option value into a stdClass (or returns
    // `false` when the option is absent) — normalize to a plain array or null.
    $loyaltyTeaserRaw = store_options($store->id, 'loyalty_teaser');
    $loyaltyTeaser = (is_object($loyaltyTeaserRaw) || is_array($loyaltyTeaserRaw))
        ? (array) $loyaltyTeaserRaw
        : null;

    // AI stylist + visual search degrade gracefully to a curated static grid
    // when these routes don't exist yet (they may not — see build brief).
    // Wrapped in try/catch: Route::has() only confirms the NAME is
    // registered, not that route()'s required parameters match what THIS
    // theme would pass, so a signature mismatch still degrades cleanly
    // instead of a 500.
    $stylistRoute = null;
    if (Route::has('stylist.search')) {
        try {
            $stylistRoute = route('stylist.search', ['slug' => $store->slug]);
        } catch (\Throwable $e) {
            try {
                $stylistRoute = route('stylist.search');
            } catch (\Throwable $e2) {
                $stylistRoute = null;
            }
        }
    }
    $visualSearchRoute = null;
    if (Route::has('visual.search')) {
        try {
            $visualSearchRoute = route('visual.search', ['slug' => $store->slug]);
        } catch (\Throwable $e) {
            try {
                $visualSearchRoute = route('visual.search');
            } catch (\Throwable $e2) {
                $visualSearchRoute = null;
            }
        }
    }
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --ms-ground:#F2F1EE; --ms-canvas:#EDEBE7; --ms-card:#FFFFFF; --ms-card-2:#F4F2EE;
        --ms-ink:#0F0E0C; --ms-muted:#6B6762; --ms-muted-2:#9A958E; --ms-hair:#E6E2DC; --ms-hair-2:#DCD8D2;
        --ms-camel:#A9784E; --ms-sale:#A93B2C; --ms-sale-bg:#F7E4E1; --ms-ok:#1F7A4D;
        --ms-radius:20px; --ms-radius-lg:22px;
        --ms-shadow:0 22px 50px -28px rgba(40,32,24,.45); --ms-shadow-card:0 10px 26px -18px rgba(40,32,24,.32);
        --ms-font-serif:"Fraunces",Georgia,serif; --ms-font-sans:"Saudi Riyal","Hanken Grotesk",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
        --ms-nav-h:64px;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --ms-ground:#121110; --ms-canvas:#0B0A09; --ms-card:#1C1A17; --ms-card-2:#24211B;
            --ms-ink:#F3F0EB; --ms-muted:#ADA79E; --ms-muted-2:#77726B; --ms-hair:#2A2620; --ms-hair-2:#342F29;
            --ms-camel:#C79C6E; --ms-sale:#E27568; --ms-sale-bg:#3A211E; --ms-ok:#3FAE7B;
            --ms-shadow:0 26px 58px -22px rgba(0,0,0,.7); --ms-shadow-card:0 14px 30px -18px rgba(0,0,0,.55);
        }
    }
    :root[data-theme="dark"]{
        --ms-ground:#121110; --ms-canvas:#0B0A09; --ms-card:#1C1A17; --ms-card-2:#24211B;
        --ms-ink:#F3F0EB; --ms-muted:#ADA79E; --ms-muted-2:#77726B; --ms-hair:#2A2620; --ms-hair-2:#342F29;
        --ms-camel:#C79C6E; --ms-sale:#E27568; --ms-sale-bg:#3A211E; --ms-ok:#3FAE7B;
        --ms-shadow:0 26px 58px -22px rgba(0,0,0,.7); --ms-shadow-card:0 14px 30px -18px rgba(0,0,0,.55);
    }
    .ms-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:100}
    .ms-skip:focus{inset-inline-start:0}
    *{box-sizing:border-box}
    .ms-body{margin:0;background:var(--ms-canvas);color:var(--ms-ink);font-family:var(--ms-font-sans);-webkit-font-smoothing:antialiased}
    .maison button{font-family:inherit;cursor:pointer}
    .maison a{color:inherit;text-decoration:none}
    .maison img{display:block;max-width:100%}
    .maison :focus-visible{outline:2px solid var(--ms-camel);outline-offset:2px}
    .maison a{color:inherit}
    [hidden]{display:none !important}

    .ms-shell{max-width:1180px;margin:0 auto;padding:0 16px calc(var(--ms-nav-h) + 28px);overflow-x:clip}
    @media (min-width:900px){ .ms-shell{padding-bottom:36px} }

    /* ---------------- header ---------------- */
    .ms-head{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:12px;
        padding:14px 16px;background:color-mix(in srgb, var(--ms-ground) 92%, transparent);
        backdrop-filter:blur(10px);border-block-end:1px solid var(--ms-hair)}
    .ms-brand{display:flex;align-items:center;gap:10px;min-width:0;flex:0 1 auto;background:none;border:0;padding:0;color:inherit}
    .ms-brand img{width:34px;height:34px;border-radius:9px;object-fit:cover;background:var(--ms-hair);flex:0 0 auto}
    .ms-brand-mark{width:26px;height:26px;color:var(--ms-ink);flex:0 0 auto}
    .ms-brand-name{font-family:var(--ms-font-serif);font-weight:500;font-size:1.05rem;letter-spacing:.02em;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .ms-headsearch{flex:1 1 auto;display:flex;align-items:center;gap:9px;height:44px;border-radius:999px;
        background:var(--ms-card);border:1px solid var(--ms-hair);padding:0 16px;color:var(--ms-muted-2);font-size:.86rem;
        min-width:0}
    .ms-headsearch svg{width:17px;height:17px;flex:0 0 auto}
    .ms-headsearch span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    @media (max-width:860px){ #msSearchOpen{ display:none } }
    /* ≤860: push the header controls to the opposite edge from the nav/brand,
       so the two ends sit apart instead of bunching together */
    @media (max-width:860px){ .ms-headrow{ margin-inline-start:auto } }
    .ms-icbtn{width:42px;height:42px;border-radius:50%;background:var(--ms-card);border:1px solid var(--ms-hair);
        color:var(--ms-ink);display:flex;align-items:center;justify-content:center;flex:0 0 auto;position:relative;
        transition:transform .15s,background .15s}
    .ms-icbtn:hover{background:var(--ms-card-2)}
    .ms-icbtn:active{transform:scale(.92)}
    .ms-icbtn svg{width:19px;height:19px}
    .ms-icbtn .ms-badge{position:absolute;top:-4px;inset-inline-end:-4px;min-width:18px;height:18px;padding:0 4px;
        border-radius:9px;background:var(--ms-sale);color:#fff;font-size:.66rem;font-weight:700;
        display:flex;align-items:center;justify-content:center}
    .ms-icbtn .ms-badge[hidden]{display:none}
    .ms-headrow{display:flex;align-items:center;gap:8px;flex:0 0 auto}
    .ms-langsel{display:inline-flex;align-items:center;gap:5px;height:42px;padding:0 12px;border-radius:100px;border:1px solid var(--ms-hair);background:var(--ms-card);color:var(--ms-muted);flex:0 0 auto;transition:background .15s}
    .ms-langsel:hover{background:var(--ms-card-2)}
    .ms-langsel svg{width:16px;height:16px;color:var(--ms-camel)}
    .ms-langsel select{border:0;background:transparent;color:var(--ms-ink);font:inherit;font-size:.78rem;font-weight:600;letter-spacing:.03em;cursor:pointer;padding-inline-end:2px}
    .ms-langsel select:focus{outline:none}

    /* desktop nav (hidden on narrow viewports; tab bar takes over) */
    .ms-navlinks{display:none;align-items:center;gap:4px;flex:0 0 auto}
    .ms-navlink{height:38px;padding:0 14px;border-radius:999px;background:transparent;border:1px solid transparent;
        color:var(--ms-muted);font-size:.86rem;font-weight:600;display:inline-flex;align-items:center;gap:6px}
    .ms-navlink:hover{background:var(--ms-card)}
    .ms-navlink.is-active{color:var(--ms-ink);background:var(--ms-card);border-color:var(--ms-hair)}
    @media (min-width:860px){
        .ms-navlinks{display:flex}
        .ms-shell{padding-bottom:36px}
    }

    /* ---------------- bottom tab bar (mobile-first nav) ---------------- */
    .ms-tabbar{position:fixed;left:0;right:0;bottom:0;height:var(--ms-nav-h);background:var(--ms-card);
        border-top:1px solid var(--ms-hair);display:flex;align-items:center;justify-content:space-around;
        padding-inline:10px;padding-bottom:env(safe-area-inset-bottom);z-index:20}
    @media (min-width:860px){ .ms-tabbar{display:none} }
    .ms-tab{display:flex;flex-direction:column;align-items:center;gap:3px;font-size:.66rem;color:var(--ms-muted-2);
        font-weight:600;flex:1;background:none;border:0;padding:6px 2px}
    .ms-tab svg{width:21px;height:21px}
    .ms-tab.is-active{color:var(--ms-ink)}
    .ms-tab-fab{width:52px;height:52px;border-radius:50%;background:var(--ms-ink);color:var(--ms-ground);
        display:flex;align-items:center;justify-content:center;margin-top:-22px;box-shadow:var(--ms-shadow);
        flex:0 0 auto;position:relative;border:0}
    .ms-tab-fab svg{width:22px;height:22px}
    .ms-tab-fab .ms-badge{position:absolute;top:-2px;inset-inline-end:-2px;min-width:18px;height:18px;padding:0 4px;
        border-radius:9px;background:var(--ms-sale);color:#fff;font-size:.66rem;font-weight:700;
        display:flex;align-items:center;justify-content:center}
    .ms-tab-fab .ms-badge[hidden]{display:none}

    /* ---------------- screens ---------------- */
    .ms-screen{padding-top:18px}
    .ms-subhdr{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-block-end:14px}
    .ms-subhdr h2{font-family:var(--ms-font-serif);font-weight:500;font-size:1.3rem;margin:0;letter-spacing:-.01em}
    .ms-back{display:inline-flex;align-items:center;gap:6px;background:none;border:0;color:var(--ms-muted);
        font-size:.84rem;font-weight:600;padding:6px 0}
    .ms-back svg{width:16px;height:16px}

    .ms-eyebrow{font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--ms-muted);font-weight:700}
    .ms-display{font-family:var(--ms-font-serif);font-weight:500;line-height:1.04;letter-spacing:-.015em;font-size:2rem;margin:4px 0 0}
    .ms-display .muted{color:var(--ms-muted)}
    .ms-lede{color:var(--ms-muted);font-size:.98rem;line-height:1.5;margin:10px 0 0;max-width:44ch}
    .ms-herocta{align-self:flex-start;width:auto;margin-top:16px;height:46px;padding:0 24px;border-radius:999px;
        background:var(--ms-ink);color:var(--ms-ground);border:0;font:inherit;font-size:.9rem;font-weight:600;
        display:inline-flex;align-items:center;cursor:pointer;transition:filter .15s}
    .ms-herocta:hover{filter:brightness(1.08)}

    /* ---------------- chips ---------------- */
    .ms-chips{display:flex;gap:9px;overflow-x:auto;padding:14px 0 6px;scrollbar-width:none}
    .ms-chips::-webkit-scrollbar{height:0}
    .ms-chip{flex:0 0 auto;height:38px;padding:0 18px;border-radius:999px;background:var(--ms-card);
        border:1px solid var(--ms-hair);font-size:.84rem;font-weight:600;color:var(--ms-ink);
        display:inline-flex;align-items:center;gap:6px}
    .ms-chip[aria-pressed="true"]{background:var(--ms-ink);color:var(--ms-ground);border-color:var(--ms-ink)}

    /* ---------------- editorial grid + card (signature component) ---------------- */
    /* crave-parity: status row + branch + banners */
    .ms-statusrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0 4px}
    .ms-openchip{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:var(--ms-ok);
        background:var(--ms-card);border:1px solid var(--ms-hair);border-radius:100px;padding:6px 12px}
    .ms-openchip i{width:7px;height:7px;border-radius:50%;background:var(--ms-ok);flex:none}
    .ms-openchip.is-closed{color:var(--ms-sale)} .ms-openchip.is-closed i{background:var(--ms-sale)}
    .ms-branchsel{position:relative;display:inline-flex;align-items:center;height:32px;border-radius:100px;
        border:1px solid var(--ms-hair);background:var(--ms-card);color:var(--ms-ink);cursor:pointer}
    .ms-branchsel svg{position:absolute;inset-inline-start:10px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--ms-camel);pointer-events:none}
    .ms-branchsel::after{content:"";position:absolute;inset-inline-end:11px;top:50%;width:6px;height:6px;border-inline-end:1.5px solid var(--ms-muted);border-bottom:1.5px solid var(--ms-muted);transform:translateY(-65%) rotate(45deg);pointer-events:none}
    .ms-branchsel select{-webkit-appearance:none;appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;outline:none;height:100%;padding-inline-start:30px;padding-inline-end:24px;max-width:170px}
    .maison .ms-branchsel select:focus-visible{outline:none}
    .ms-branchsel:focus-within{outline:2px solid var(--ms-camel);outline-offset:2px}
    .ms-banners{margin:14px 0 4px}
    .ms-banner-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
    .ms-banner-track::-webkit-scrollbar{height:0}
    .ms-banner{flex:0 0 86%;scroll-snap-align:center;position:relative;min-height:110px;aspect-ratio:16/9;border-radius:var(--ms-radius-lg);overflow:hidden;display:flex;align-items:flex-end;padding:18px 20px;text-decoration:none;background:var(--ms-ink);color:#fff}
    .ms-banner:nth-child(even){background:var(--ms-camel)}
    .ms-banner-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.9}
    .ms-banner::after{content:'';position:absolute;inset:0;background:linear-gradient(0deg,rgba(0,0,0,.45) 0%,rgba(0,0,0,.12) 60%,transparent 100%);pointer-events:none}
    .ms-banner-copy{position:relative;display:flex;flex-direction:column;gap:3px;z-index:1}
    .ms-banner-kick{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;opacity:.85}
    .ms-banner-title{font-family:var(--ms-font-serif);font-size:1.3rem;font-weight:500;line-height:1.15}
    .ms-banner-dots{display:flex;gap:6px;justify-content:center;margin-top:9px}
    .ms-banner-dots i{width:6px;height:6px;border-radius:50%;background:var(--ms-hair-2);transition:width .2s,background .2s}
    .ms-banner-dots i.on{background:var(--ms-ink);width:16px;border-radius:100px}

    .ms-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-top:8px}
    @media (min-width:640px){ .ms-grid{grid-template-columns:repeat(3,1fr)} }
    @media (min-width:960px){ .ms-grid{grid-template-columns:repeat(4,1fr)} }
    .ms-card{position:relative}
    .ms-card__media{position:relative;border-radius:var(--ms-radius-lg);overflow:clip;aspect-ratio:3/4;
        box-shadow:var(--ms-shadow-card);background:var(--ms-card-2)}
    .ms-card__link{position:absolute;inset:0;display:block}
    .ms-card__img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    .ms-card__img--ph{display:flex;align-items:center;justify-content:center;color:var(--ms-muted-2);background:var(--ms-card-2)}
    .ms-card__scrim{position:absolute;inset:0;z-index:1;pointer-events:none;
        background:linear-gradient(to top,rgba(15,14,12,.78),rgba(15,14,12,.08) 46%,transparent 64%),
                    linear-gradient(to bottom,rgba(15,14,12,.28),transparent 22%)}
    .ms-pill{position:absolute;top:10px;inset-inline-start:10px;background:#fff;color:var(--ms-sale);
        font-size:.68rem;font-weight:700;padding:4px 10px;border-radius:999px;z-index:3}
    .ms-pill--soldout{color:var(--ms-ink)}
    .ms-heart{position:absolute;top:10px;inset-inline-end:10px;width:34px;height:34px;border-radius:50%;border:0;
        display:flex;align-items:center;justify-content:center;color:#fff;z-index:3;
        background:rgba(255,255,255,.18);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.32);
        transition:transform .15s}
    .ms-heart:active{transform:scale(.86)}
    .ms-heart svg{width:16px;height:16px}
    .ms-heart[aria-pressed="true"]{background:var(--ms-sale);border-color:var(--ms-sale)}
    .ms-heart[aria-pressed="true"] svg{fill:#fff}
    .ms-heart.ms-pop{animation:msHeartPop .4s}
    @keyframes msHeartPop{0%{transform:scale(1)}35%{transform:scale(1.3)}100%{transform:scale(1)}}
    .ms-bagbtn{position:absolute;bottom:12px;inset-inline-end:12px;width:38px;height:38px;border-radius:50%;
        background:#fff;color:#141210;border:0;display:flex;align-items:center;justify-content:center;z-index:4;
        box-shadow:0 6px 16px -4px rgba(0,0,0,.4);transition:transform .15s}
    .ms-bagbtn:active{transform:scale(.88)}
    .ms-bagbtn svg{width:17px;height:17px}
    .ms-bagbtn:disabled{opacity:.4;cursor:not-allowed}
    .ms-card__meta{position:absolute;left:0;right:0;bottom:0;z-index:2;padding:14px;padding-inline-end:56px;text-align:start}
    .ms-card__brand{display:block;font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
        color:rgba(255,255,255,.72);margin-block-end:2px}
    .ms-card__name{font-family:var(--ms-font-serif);font-size:.98rem;font-weight:500;letter-spacing:-.005em;
        color:#fff;text-shadow:0 1px 10px rgba(0,0,0,.35);line-height:1.18;display:-webkit-box;
        -webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:clip}
    .ms-card__row{display:flex;align-items:center;gap:7px;margin-top:5px;flex-wrap:wrap}
    .ms-price{font-weight:700;font-size:.92rem;font-variant-numeric:tabular-nums;color:#fff}
    .ms-price--was{color:rgba(255,255,255,.62);font-weight:400;text-decoration:line-through;font-size:.78rem}
    .ms-empty{padding:70px 12px;text-align:center;color:var(--ms-muted)}
    .ms-empty .ms-eico{width:70px;height:70px;margin:0 auto 14px;border-radius:50%;background:var(--ms-card);
        border:1px solid var(--ms-hair);display:flex;align-items:center;justify-content:center;color:var(--ms-muted-2)}
    .ms-empty .ms-eico svg{width:28px;height:28px}
    .ms-empty h3{font-family:var(--ms-font-serif);color:var(--ms-ink);font-weight:500;font-size:1.2rem;margin:0 0 6px}

    /* ---------------- editorial feature banner (Home) ---------------- */
    .ms-feature{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:18px 0 4px}
    @media (max-width:520px){ .ms-feature{grid-template-columns:1fr} }
    .ms-feature__card{position:relative;border-radius:var(--ms-radius-lg);overflow:clip;aspect-ratio:4/5;
        box-shadow:var(--ms-shadow-card);display:block}
    .ms-feature__card img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    .ms-feature__scrim{position:absolute;inset:0;background:linear-gradient(to top,rgba(15,14,12,.72),transparent 55%)}
    .ms-feature__cap{position:absolute;left:0;right:0;bottom:0;padding:18px;color:#fff}
    .ms-feature__eyebrow{font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.75);font-weight:700}
    .ms-feature__name{font-family:var(--ms-font-serif);font-size:1.3rem;font-weight:500;margin-top:4px}
</style>
@endpush

@push('style')
<style nonce="{{ csp_nonce() }}">
    /* ---------------- category / shop filter row ---------------- */
    .ms-catlist{margin-top:6px}
    .ms-catrow{display:flex;align-items:center;justify-content:space-between;width:100%;padding:16px 4px;
        border-block-end:1px solid var(--ms-hair);background:none;border-inline:0;border-block-start:0;
        color:inherit;font-size:.95rem;font-weight:500;text-align:start}
    .ms-catrow .ms-chev{color:var(--ms-muted-2)}
    .ms-catrow .ms-chev svg{width:17px;height:17px}
    .ms-promoban{position:relative;background:var(--ms-sale-bg);border-radius:var(--ms-radius-lg);padding:20px;margin:14px 0 4px}
    .ms-promoban .k{font-size:.72rem;font-weight:700;color:var(--ms-sale);letter-spacing:.03em;text-transform:uppercase}
    .ms-promoban h3{font-family:var(--ms-font-serif);font-weight:500;font-size:1.25rem;margin:6px 0 2px;color:var(--ms-ink)}
    .ms-promoban .big{font-size:1.9rem;font-weight:600;font-family:var(--ms-font-serif);color:var(--ms-ink)}
    .ms-promoban .shop{margin-top:12px;height:40px;padding:0 20px;border-radius:999px;background:var(--ms-ink);
        color:var(--ms-ground);font-weight:600;font-size:.84rem;border:0}

    .ms-filterrow{display:flex;align-items:center;gap:10px;padding:2px 0 10px;flex-wrap:wrap}
    .ms-pillbtn{height:40px;padding:0 16px;border-radius:999px;background:var(--ms-card);border:1px solid var(--ms-hair);
        font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:7px;color:inherit}
    .ms-pillbtn svg{width:15px;height:15px}
    .ms-results{margin-inline-start:auto;font-size:.78rem;color:var(--ms-muted)}
    .ms-filterpanel{background:var(--ms-card);border:1px solid var(--ms-hair);border-radius:var(--ms-radius);
        padding:16px;margin-block-end:14px;display:grid;gap:12px}
    .ms-filterpanel[hidden]{display:none}
    .ms-field-row{display:flex;gap:10px;flex-wrap:wrap}
    .ms-select,.ms-input{flex:1 1 140px;min-width:120px;padding:9px 12px;border-radius:10px;border:1px solid var(--ms-hair);
        background:var(--ms-ground);color:inherit;font-size:.84rem}
    .ms-range{width:100%}
    .ms-rangevals{display:flex;justify-content:space-between;font-size:.76rem;color:var(--ms-muted)}
    .ms-clearlink{align-self:flex-start;background:none;border:0;color:var(--ms-camel);font-weight:600;font-size:.82rem;padding:0}

    /* ---------------- off-canvas sheet (product / cart+checkout) ---------------- */
    .ms-scrim{position:fixed;inset:0;background:rgba(15,14,12,.5);z-index:39;opacity:0;pointer-events:none;transition:opacity .2s ease}
    .ms-scrim.is-open{opacity:1;pointer-events:auto}
    .ms-sheet{position:fixed;inset-block:0;inset-inline-end:0;width:min(460px,100%);background:var(--ms-ground);
        color:inherit;z-index:40;transform:translateX(100%);transition:transform .26s cubic-bezier(.22,.9,.3,1);
        display:flex;flex-direction:column;box-shadow:-10px 0 34px rgba(0,0,0,.28)}
    [dir="rtl"] .ms-sheet{transform:translateX(-100%)}
    .ms-sheet.is-open{transform:translateX(0)}
    .ms-sheet__head{display:flex;align-items:center;justify-content:space-between;padding:16px;
        border-block-end:1px solid var(--ms-hair);flex:0 0 auto}
    .ms-sheet__title{font-family:var(--ms-font-serif);font-weight:500;font-size:1.1rem}
    .ms-sheet__body{flex:1;overflow-y:auto;overflow-x:clip;padding:18px 20px calc(18px + env(safe-area-inset-bottom))}
    .ms-sheet__foot{padding:16px 20px calc(16px + env(safe-area-inset-bottom));border-block-start:1px solid var(--ms-hair);flex:0 0 auto}

    /* product detail (inside the sheet) */
    .ms-pd-img{position:relative;border-radius:var(--ms-radius-lg);overflow:clip;aspect-ratio:1/1.1;background:var(--ms-card-2);margin-block-end:16px}
    .ms-pd-img img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    .ms-pd-img--ph{display:flex;align-items:center;justify-content:center;color:var(--ms-muted-2)}
    .ms-pd-thumbs{display:flex;gap:8px;margin-block-end:16px;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-block-end:2px}
    .ms-pd-thumbs::-webkit-scrollbar{height:0}
    .ms-pd-thumb{flex:0 0 auto;width:58px;height:64px;border-radius:12px;overflow:clip;border:1px solid var(--ms-hair);
        background:var(--ms-card-2);padding:0;transition:border-color .15s}
    .ms-pd-thumb img{width:100%;height:100%;object-fit:cover}
    .ms-pd-thumb[aria-current="true"]{border-color:var(--ms-ink);border-width:2px}
    .ms-pd-title{font-family:var(--ms-font-serif);font-size:1.4rem;font-weight:500;line-height:1.08;letter-spacing:-.015em}
    .ms-pd-prices{display:flex;align-items:baseline;gap:10px;margin-top:6px}
    .ms-pd-price{font-size:1.2rem;font-weight:600;font-variant-numeric:tabular-nums}
    .ms-pd-desc{color:var(--ms-muted);font-size:.86rem;line-height:1.6;margin-top:10px}
    .ms-pd-stock{font-size:.76rem;font-weight:700;margin-top:8px;display:inline-block}
    .ms-pd-stock--low{color:#b45309} .ms-pd-stock--in{color:var(--ms-ok)} .ms-pd-stock--out{color:var(--ms-sale)}
    .ms-label{font-size:.76rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:var(--ms-muted);margin:18px 0 9px}
    .ms-swatches{display:flex;gap:11px;flex-wrap:wrap}
    .ms-sw{width:32px;height:32px;border-radius:50%;border:2px solid transparent;position:relative;padding:0;background:none}
    .ms-sw span{position:absolute;inset:3px;border-radius:50%;border:1px solid var(--ms-hair-2)}
    .ms-sw[aria-pressed="true"]{border-color:var(--ms-ink)}
    .ms-sizes{display:flex;gap:9px;flex-wrap:wrap}
    .ms-size{min-width:46px;height:42px;padding:0 12px;border-radius:11px;border:1px solid var(--ms-hair-2);
        background:var(--ms-card);font-weight:600;font-size:.86rem;display:flex;align-items:center;justify-content:center}
    .ms-size[aria-pressed="true"]{background:var(--ms-ink);color:var(--ms-ground);border-color:var(--ms-ink)}
    .ms-size:disabled{opacity:.4;text-decoration:line-through;cursor:not-allowed}
    .ms-qty{display:flex;align-items:center;gap:12px}
    .ms-qty button{width:34px;height:34px;border-radius:9px;border:1px solid var(--ms-hair-2);background:var(--ms-card);color:inherit}
    .ms-qty span{min-width:22px;text-align:center;font-weight:600;font-variant-numeric:tabular-nums}
    .ms-attrs{width:100%;border-collapse:collapse;font-size:.84rem}
    .ms-attrs td{padding:7px 0;border-block-end:1px solid var(--ms-hair)}
    .ms-attrs td:first-child{color:var(--ms-muted);padding-inline-end:12px;width:42%}
    .ms-actionbar{display:flex;gap:10px}
    .ms-btn{height:52px;border-radius:999px;font-size:.92rem;font-weight:600;display:flex;align-items:center;
        justify-content:center;gap:8px;border:0;flex:1}
    .ms-btn svg{width:18px;height:18px}
    .ms-btn--dark{background:var(--ms-ink);color:var(--ms-ground)}
    .ms-btn--ghost{background:var(--ms-card);color:var(--ms-ink);border:1px solid var(--ms-hair-2)}
    /* A button rendered as an <a> (e.g. the confirm "Track order" link) must keep
       its button text colour: `.maison a{color:inherit}` above is more specific
       than `.ms-btn--dark`, so without these the link text inherits the dark ink
       and vanishes on the dark button. */
    .maison a.ms-btn--dark{color:var(--ms-ground)}
    .maison a.ms-btn--ghost{color:var(--ms-ink)}
    .ms-btn--block{width:100%}
    .ms-btn:disabled{opacity:.5;cursor:not-allowed}

    /* cart lines / checkout / confirm (inside the cart sheet) */
    .ms-cline{display:flex;gap:12px;padding:14px 0;border-block-end:1px solid var(--ms-hair)}
    .ms-cline img{width:60px;height:72px;border-radius:10px;object-fit:cover;background:var(--ms-card-2);flex:0 0 auto}
    .ms-cline__body{flex:1;min-width:0}
    .ms-cline__name{font-weight:600;font-size:.9rem}
    .ms-cline__opt{font-size:.78rem;color:var(--ms-muted);margin-top:1px}
    .ms-cline__price{font-weight:700;font-variant-numeric:tabular-nums;margin-top:6px}
    .ms-cline__remove{background:none;border:0;color:var(--ms-sale);font-size:.78rem;padding:0;margin-top:6px;font-weight:600}
    .ms-summary{margin-top:6px}
    .ms-nb-proxy{position:fixed;top:0;inset-inline-start:0;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;opacity:0;pointer-events:none;border:0}
    .ms-notif-dot{position:absolute;top:6px;inset-inline-end:6px;width:8px;height:8px;border-radius:50%;background:var(--ms-sale);border:2px solid var(--ms-card);box-sizing:content-box}
    .ms-notif-dot[hidden]{display:none}
    .mb-nb-card{--nb-surface:var(--ms-card);--nb-surface-2:var(--ms-card-2);--nb-ink:var(--ms-ink);--nb-muted:var(--ms-muted);--nb-line:var(--ms-hair);--nb-accent:var(--ms-camel);--nb-radius:14px}
    .maison .mb-nb-panel{opacity:0}
    .maison .mb-nb-panel.is-open{opacity:1}
    .ms-order{border:1px solid var(--ms-hair);border-radius:12px;padding:13px 15px;margin-bottom:10px}
    .ms-order-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .ms-order-status{font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px;background:var(--ms-card-2);color:var(--ms-ink)}
    .ms-order-meta{font-size:12.5px;color:var(--ms-muted);margin-top:6px}
    .ms-order-track{display:inline-block;margin-top:10px;font-size:13px;font-weight:600;color:var(--ms-camel);text-decoration:none}
    .ms-order-actions{display:flex;gap:8px;margin-top:12px}
    .ms-order-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;text-align:center;height:38px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;color:var(--ms-ink);background:var(--ms-card);border:1px solid var(--ms-hair);cursor:pointer;font-family:inherit;transition:background .15s,border-color .15s}
    .ms-order-btn:hover{background:var(--ms-card-2)}
    .ms-order-btn--p{background:var(--ms-camel);color:#fff;border-color:var(--ms-camel)}
    .ms-order-btn--p:hover{background:var(--ms-camel);filter:brightness(1.05)}
    .ms-order-btn--block{flex:none;width:100%;margin-top:12px}
    .ms-orders-empty{font-size:13px;color:var(--ms-muted);padding:6px 0}
    .ms-couponrow{display:flex;gap:8px;margin-bottom:8px}
    .ms-coupon-input{flex:1;height:46px;border-radius:12px;border:1px solid var(--ms-hair);background:var(--ms-card);padding:0 14px;font:inherit;font-size:14px;color:var(--ms-ink)}
    .ms-coupon-apply{flex:none}
    .ms-coupon-msg{font-size:12px;font-weight:600;margin:0 0 8px;color:var(--ms-ok)}
    .ms-coupon-msg.is-bad{color:var(--ms-sale)}
    .ms-srow{display:flex;justify-content:space-between;font-size:.86rem;color:var(--ms-muted);padding:6px 0;font-variant-numeric:tabular-nums}
    .ms-srow.total{color:var(--ms-ink);font-weight:700;font-size:1rem;border-block-start:1px solid var(--ms-hair);margin-top:8px;padding-top:12px}
    .ms-field{display:block;width:100%;margin-block-end:12px}
    .ms-field label{display:block;font-size:.78rem;color:var(--ms-muted);margin-block-end:5px;font-weight:600}
    .ms-field input,.ms-field select{width:100%;padding:11px 12px;border-radius:11px;border:1px solid var(--ms-hair-2);
        background:var(--ms-card);color:inherit;font-size:.88rem}
    /* Delivery-address autocomplete (crave-style geocode suggest) */
    .ms-addr{position:relative}
    .ms-addr input{padding-inline-end:42px}
    .ms-addr-loc{position:absolute;inset-inline-end:6px;top:6px;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;
        border:0;background:transparent;color:var(--ms-muted);cursor:pointer;border-radius:9px}
    .ms-addr-loc:hover{background:var(--ms-card-2);color:var(--ms-camel)}
    .ms-addr-loc.is-loading{opacity:.5;pointer-events:none}
    .ms-addr-loc svg{width:19px;height:19px}
    .ms-addr-suggest{position:absolute;inset-inline:0;top:calc(100% + 5px);z-index:40;background:var(--ms-card);
        border:1px solid var(--ms-hair);border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,.14);overflow:hidden;max-height:248px;overflow-y:auto}
    .ms-addr-suggest .mb-addr-opt{display:block;width:100%;text-align:start;padding:11px 14px;background:none;border:0;
        border-block-end:1px solid var(--ms-hair-2);color:var(--ms-ink);font:inherit;font-size:.84rem;cursor:pointer;line-height:1.4}
    .ms-addr-suggest .mb-addr-opt:last-child{border-block-end:0}
    .ms-addr-suggest .mb-addr-opt:hover{background:var(--ms-card-2)}
    .ms-optcard{width:100%;background:var(--ms-card);border:1px solid var(--ms-hair);border-radius:var(--ms-radius);
        padding:13px 14px;display:flex;align-items:center;gap:12px;margin-block-end:9px;text-align:start}
    .ms-optcard[aria-pressed="true"]{border-color:var(--ms-camel)}
    .ms-optcard .oico{width:36px;height:36px;border-radius:9px;background:var(--ms-card-2);display:flex;
        align-items:center;justify-content:center;flex:0 0 auto;color:var(--ms-ink)}
    .ms-optcard .oico svg{width:18px;height:18px}
    .ms-optcard .obody{flex:1;min-width:0}
    .ms-optcard .ot{font-weight:600;font-size:.88rem}
    .ms-optcard .os{font-size:.76rem;color:var(--ms-muted);margin-top:1px}
    .ms-radio{width:20px;height:20px;border-radius:50%;border:2px solid var(--ms-hair-2);flex:0 0 auto;position:relative}
    .ms-optcard[aria-pressed="true"] .ms-radio{border-color:var(--ms-camel)}
    .ms-optcard[aria-pressed="true"] .ms-radio::after{content:'';position:absolute;inset:4px;border-radius:50%;background:var(--ms-camel)}
    .ms-slots{display:flex;flex-wrap:wrap;gap:8px;margin-block:6px}
    .ms-opt{border:1px solid var(--ms-hair);background:var(--ms-card);color:inherit;border-radius:9px;
        padding:8px 12px;font-size:.82rem}
    .ms-opt.is-active{border-color:var(--ms-camel);background:color-mix(in srgb, var(--ms-camel) 14%, transparent);font-weight:600}
    .ms-msg{font-size:.84rem;padding:9px 12px;border-radius:10px;margin-block-end:12px}
    .ms-msg--error{background:color-mix(in srgb, var(--ms-sale) 12%, transparent);color:var(--ms-sale)}
    .ms-msg--ok{background:color-mix(in srgb, var(--ms-ok) 14%, transparent);color:var(--ms-ok)}

    /* confirm */
    .ms-confwrap{display:flex;flex-direction:column;align-items:center;text-align:center;padding:26px 6px 8px}
    .ms-checkc{width:84px;height:84px}
    .ms-checkc circle{fill:none;stroke:var(--ms-camel);stroke-width:4;stroke-dasharray:283;stroke-dashoffset:283;animation:msDraw .6s ease forwards}
    .ms-checkc path{fill:none;stroke:var(--ms-ink);stroke-width:5;stroke-linecap:round;stroke-linejoin:round;
        stroke-dasharray:60;stroke-dashoffset:60;animation:msDraw .4s .5s ease forwards}
    @keyframes msDraw{to{stroke-dashoffset:0}}
    .ms-confwrap h3{font-family:var(--ms-font-serif);font-weight:500;font-size:1.3rem;margin:16px 0 4px}
    .ms-confwrap p{color:var(--ms-muted);font-size:.86rem;margin:0}
    .ms-orderno{margin-top:8px;font-weight:700;letter-spacing:.03em}
    .ms-track-mini{width:100%;margin-top:22px;background:var(--ms-card);border:1px solid var(--ms-hair);
        border-radius:var(--ms-radius);padding:16px 16px 4px;text-align:start}
    .ms-tstep{display:flex;gap:12px;position:relative;padding-bottom:18px}
    .ms-tstep:last-child{padding-bottom:2px}
    .ms-tstep .dot{width:24px;height:24px;border-radius:50%;flex:0 0 auto;display:flex;align-items:center;
        justify-content:center;border:2px solid var(--ms-hair-2);background:var(--ms-card);color:var(--ms-muted-2);z-index:2}
    .ms-tstep .dot svg{width:12px;height:12px}
    .ms-tstep::before{content:'';position:absolute;inset-inline-start:11px;top:24px;bottom:0;width:2px;background:var(--ms-hair-2)}
    .ms-tstep:last-child::before{display:none}
    .ms-tstep.done .dot{background:var(--ms-ink);border-color:var(--ms-ink);color:var(--ms-ground)}
    .ms-tstep.done::before{background:var(--ms-ink)}
    .ms-tstep .tt{font-weight:600;font-size:.86rem}
    .ms-tstep .td{font-size:.76rem;color:var(--ms-muted);margin-top:1px}

    /* ---------------- AI stylist ---------------- */
    .ms-aiwrap{display:flex;flex-direction:column;gap:14px}
    .ms-askbox{background:var(--ms-card);border:1px solid var(--ms-hair);border-radius:18px;padding:14px;box-shadow:var(--ms-shadow-card)}
    .ms-askbox .top{display:flex;align-items:center;gap:9px;color:var(--ms-muted-2);font-size:.86rem}
    .ms-askbox .top svg{width:18px;height:18px;flex:0 0 auto}
    .ms-aiacts{display:flex;gap:10px;margin-top:12px}
    .ms-aiact{flex:1;height:42px;border-radius:12px;border:1px solid var(--ms-hair-2);background:var(--ms-card);
        display:flex;align-items:center;justify-content:center;gap:7px;font-size:.8rem;font-weight:600;color:inherit}
    .ms-aiact svg{width:16px;height:16px}
    .ms-tryrow{display:flex;gap:8px;flex-wrap:wrap;margin-top:2px}
    .ms-aithread{display:flex;flex-direction:column;gap:12px}
    .ms-chatrow{display:flex;gap:9px;max-width:90%}
    .ms-chatrow .who{width:28px;height:28px;border-radius:50%;flex:0 0 auto;display:flex;align-items:center;
        justify-content:center;background:var(--ms-ink);color:var(--ms-ground)}
    .ms-chatrow .who svg{width:15px;height:15px}
    .ms-bubble{background:var(--ms-card);border:1px solid var(--ms-hair);border-radius:4px 14px 14px 14px;
        padding:10px 13px;font-size:.86rem;line-height:1.5}
    .ms-chatrow.me{margin-inline-start:auto;flex-direction:row-reverse}
    .ms-chatrow.me .who{background:var(--ms-camel)}
    .ms-chatrow.me .ms-bubble{background:var(--ms-ink);color:var(--ms-ground);border:none;border-radius:14px 4px 14px 14px}
    [dir="rtl"] .ms-bubble{border-radius:14px 4px 14px 14px}
    [dir="rtl"] .ms-chatrow.me .ms-bubble{border-radius:4px 14px 14px 14px}
    .ms-thinking{display:inline-flex;gap:5px;align-items:center}
    .ms-thinking i{width:6px;height:6px;border-radius:50%;background:var(--ms-muted-2);animation:msBlink 1.2s infinite;font-style:normal}
    .ms-thinking i:nth-child(2){animation-delay:.2s} .ms-thinking i:nth-child(3){animation-delay:.4s}
    @keyframes msBlink{0%,60%,100%{opacity:.25;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}

    /* ---------------- visual search ---------------- */
    .ms-vsframe{position:relative;margin-top:4px;border-radius:var(--ms-radius-lg);overflow:clip;aspect-ratio:3/4;background:#100f0d}
    .ms-vsframe svg.ph{position:absolute;inset:0;margin:auto;width:64px;height:64px;color:rgba(255,255,255,.5)}
    .ms-vsframe img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.92}
    .ms-vscorners{position:absolute;inset:22px;pointer-events:none}
    .ms-vscorners span{position:absolute;width:32px;height:32px;border:3px solid #fff}
    .ms-vscorners span:nth-child(1){top:0;left:0;border-right:none;border-bottom:none;border-radius:8px 0 0 0}
    .ms-vscorners span:nth-child(2){top:0;right:0;border-left:none;border-bottom:none;border-radius:0 8px 0 0}
    .ms-vscorners span:nth-child(3){bottom:0;left:0;border-right:none;border-top:none;border-radius:0 0 0 8px}
    .ms-vscorners span:nth-child(4){bottom:0;right:0;border-left:none;border-top:none;border-radius:0 0 8px 0}
    .ms-vsscan{position:absolute;left:22px;right:22px;height:3px;background:linear-gradient(90deg,transparent,var(--ms-camel),transparent);animation:msScan 2.2s ease-in-out infinite}
    @keyframes msScan{0%{top:24px}50%{top:calc(100% - 24px)}100%{top:24px}}
    .ms-vstag{position:absolute;top:12px;inset-inline-start:12px;background:rgba(16,15,13,.72);color:#fff;
        font-size:.72rem;font-weight:600;padding:6px 12px;border-radius:999px;backdrop-filter:blur(6px)}
    .ms-vsupload{margin-top:14px}
    .ms-matchcard{display:flex;align-items:center;gap:13px;background:var(--ms-card);border:1px solid var(--ms-hair);
        border-radius:var(--ms-radius);padding:12px;margin-top:16px;box-shadow:var(--ms-shadow-card)}
    .ms-matchcard .mimg{width:60px;height:70px;border-radius:11px;overflow:clip;background:var(--ms-card-2);flex:0 0 auto}
    .ms-matchcard .mimg img{width:100%;height:100%;object-fit:cover}
    .ms-matchcard .minfo{flex:1;min-width:0}
    .ms-matchcard .mn{font-family:var(--ms-font-serif);font-size:.94rem;font-weight:500}
    .ms-matchcard .ms{font-size:.76rem;color:var(--ms-muted)}
    .ms-matchcard .mp{font-weight:600;margin-top:3px;font-variant-numeric:tabular-nums}
    .ms-matchcard .madd{width:40px;height:40px;border-radius:50%;background:var(--ms-ink);color:var(--ms-ground);
        display:flex;align-items:center;justify-content:center;flex:0 0 auto;border:0}
    .ms-matchcard .madd svg{width:17px;height:17px}
    .ms-vsnote{font-size:.8rem;color:var(--ms-muted);margin-top:10px;line-height:1.5}

    /* ---------------- account ---------------- */
    .ms-acccard{display:flex;align-items:center;gap:13px;background:var(--ms-card);border:1px solid var(--ms-hair);
        border-radius:var(--ms-radius);padding:16px;margin-block-end:14px;box-shadow:var(--ms-shadow-card)}
    .ms-acccard .aico{width:42px;height:42px;border-radius:11px;background:var(--ms-card-2);display:flex;
        align-items:center;justify-content:center;flex:0 0 auto;color:var(--ms-ink)}
    .ms-acccard .aico svg{width:19px;height:19px}
    .ms-acccard .abody{flex:1;min-width:0}
    .ms-acccard .at{font-weight:600;font-size:.92rem}
    .ms-acccard .as{font-size:.78rem;color:var(--ms-muted);margin-top:1px}
    .ms-accrow{display:flex;align-items:center;gap:13px;padding:14px 4px;border-block-end:1px solid var(--ms-hair);
        width:100%;background:none;border-inline:0;border-block-start:0;text-align:start;color:inherit}
    .ms-accrow .aico{width:36px;height:36px;border-radius:9px;background:var(--ms-card-2);display:flex;
        align-items:center;justify-content:center;color:var(--ms-ink);flex:0 0 auto}
    .ms-accrow .aico svg{width:17px;height:17px}
    .ms-accrow .at{flex:1;font-weight:500;font-size:.9rem}
    .ms-lookup{display:flex;gap:8px;margin-top:8px}
    .ms-lookup input{flex:1}

    /* ---------------- toast ---------------- */
    .ms-toast{position:fixed;left:50%;bottom:calc(var(--ms-nav-h) + 18px);transform:translateX(-50%) translateY(16px);
        background:var(--ms-ink);color:var(--ms-ground);padding:11px 20px;border-radius:999px;font-size:.84rem;
        font-weight:600;opacity:0;transition:opacity .25s,transform .25s;z-index:60;white-space:nowrap;
        display:flex;align-items:center;gap:8px;pointer-events:none}
    @media (min-width:860px){ .ms-toast{bottom:22px} }
    .ms-toast svg{width:15px;height:15px}
    .ms-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

    @media (prefers-reduced-motion:reduce){
        .maison *{animation-duration:.001s !important;transition-duration:.001s !important}
    }
</style>
@endpush

{{-- =========================================================================
     Curated core features (loop/enrich pack): stories rail + full-screen
     viewer (F3), item reviews (F6), and the loyalty redeem row + Rewards
     card (F3b). Additive — reuses the same --ms-* tokens, .ms-btn/.ms-input/
     .ms-label/.ms-summary/.ms-srow components and radius/shadow scale as
     every other block above, so nothing here reads as bolted on.
     ========================================================================= --}}
@push('style')
<style nonce="{{ csp_nonce() }}">
    /* ---------------- stories rail (Category screen) + full-screen viewer ---------------- */
    .ms-stories{display:flex;gap:14px;overflow-x:auto;overflow-y:clip;padding:2px 2px 16px;scrollbar-width:none}
    .ms-stories::-webkit-scrollbar{height:0}
    /* Desktop mouse can't swipe a hidden rail — give the item rails a slim bar back
       on hover/fine pointers so an overflowing rail stays reachable; touch keeps the
       hidden-bar look. */
    @media (hover:hover) and (pointer:fine){
        #maison .ms-stories,#maison .ms-chips{scrollbar-width:thin;scrollbar-color:var(--ms-hair-2) transparent}
        #maison .ms-stories::-webkit-scrollbar,#maison .ms-chips::-webkit-scrollbar{display:block;height:8px}
        #maison .ms-stories::-webkit-scrollbar-thumb,#maison .ms-chips::-webkit-scrollbar-thumb{background:var(--ms-hair-2);border-radius:999px;border:2px solid transparent;background-clip:content-box}
        #maison .ms-stories::-webkit-scrollbar-thumb:hover,#maison .ms-chips::-webkit-scrollbar-thumb:hover{background:var(--ms-ink)}
        #maison .ms-stories::-webkit-scrollbar-track,#maison .ms-chips::-webkit-scrollbar-track{background:transparent}
    }
    .ms-story{flex:0 0 auto;width:68px;display:flex;flex-direction:column;align-items:center;gap:6px;
        background:none;border:0;color:inherit;padding:0}
    .ms-story__ring{width:62px;height:62px;border-radius:50%;padding:2.5px;flex:0 0 auto;
        background:linear-gradient(135deg,var(--ms-camel),var(--ms-sale))}
    .ms-story.is-viewed .ms-story__ring{background:var(--ms-hair-2)}
    .ms-story__img{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;border:2px solid var(--ms-ground)}
    .ms-story__label{font-size:.68rem;font-weight:600;max-width:100%;overflow:clip;text-overflow:ellipsis;white-space:nowrap}

    .ms-story-viewer{position:fixed;inset:0;z-index:70;background:#0b0a09;display:flex;flex-direction:column}
    .ms-story-viewer__bars{display:flex;gap:4px;padding:12px 12px 0;flex:0 0 auto}
    .ms-story-viewer__bar{flex:1;height:2.5px;border-radius:999px;background:rgba(255,255,255,.32);overflow:clip}
    .ms-story-viewer__bar>span{display:block;height:100%;width:0;background:#fff}
    .ms-story-viewer__bar.is-done>span{width:100%}
    .ms-story-viewer__bar.is-active>span{width:100%;transition:width 5s linear}
    .ms-story-viewer__head{display:flex;justify-content:flex-end;padding:10px 12px 0;flex:0 0 auto}
    .ms-story-viewer__close{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.16);border:0;
        color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;line-height:1}
    .ms-story-viewer__stage{position:relative;flex:1;min-height:0;display:flex;align-items:center;justify-content:center;overflow:clip}
    .ms-story-viewer__stage img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
    .ms-story-viewer__zone{position:absolute;inset-block:0;width:42%;background:none;border:0;padding:0}
    .ms-story-viewer__zone--prev{inset-inline-start:0}
    .ms-story-viewer__zone--next{inset-inline-end:0}
    .ms-story-viewer__foot{position:absolute;inset-inline:0;inset-block-end:0;
        padding:16px 18px calc(16px + env(safe-area-inset-bottom));
        background:linear-gradient(to top,rgba(0,0,0,.62),transparent);
        display:flex;flex-direction:column;align-items:flex-start;gap:12px}
    .ms-story-viewer__cap{color:#fff;font-size:.9rem;line-height:1.5;margin:0;text-shadow:0 1px 6px rgba(0,0,0,.4)}
    .ms-story-viewer__cta{border:0;border-radius:999px;padding:11px 20px;font-weight:700;font-size:.84rem;
        background:#fff;color:#0b0a09;text-decoration:none}

    /* ---------------- reviews (inside the product detail sheet) ---------------- */
    .ms-reviews__summary{display:flex;flex-direction:column;gap:12px;margin-block-end:6px}
    .ms-reviews__avg{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
    .ms-reviews__avgnum{font-family:var(--ms-font-serif);font-size:1.6rem;font-weight:500}
    .ms-reviews__avgstars{color:var(--ms-camel);display:inline-flex;gap:1px;vertical-align:middle}
    .ms-reviews__avgstars svg{width:14px;height:14px}
    .ms-reviews__count{font-size:.8rem;color:var(--ms-muted)}
    .ms-reviews__dist{display:flex;flex-direction:column;gap:5px}
    .ms-reviews__distrow{display:flex;align-items:center;gap:8px;font-size:.74rem;color:var(--ms-muted)}
    .ms-reviews__distrow span:first-child{width:10px;flex:0 0 auto}
    .ms-reviews__distbar{flex:1;height:5px;border-radius:4px;background:var(--ms-hair);overflow:clip}
    .ms-reviews__distbar>span{display:block;height:100%;background:var(--ms-camel)}
    .ms-reviews__distrow span:last-child{width:22px;flex:0 0 auto;text-align:end}
    .ms-review{padding:14px 0;border-block-end:1px solid var(--ms-hair)}
    .ms-review__top{display:flex;align-items:center;justify-content:space-between;gap:8px}
    .ms-review__stars{color:var(--ms-camel);display:inline-flex;gap:1px}
    .ms-review__stars svg{width:12px;height:12px}
    .ms-review__date{font-size:.72rem;color:var(--ms-muted-2)}
    .ms-review__name{font-weight:600;font-size:.86rem;margin-top:4px}
    .ms-review__body{font-size:.84rem;color:var(--ms-muted);line-height:1.6;margin:3px 0 0}
    .ms-reviews__empty{padding:18px 0;color:var(--ms-muted);font-size:.86rem;text-align:center}
    .ms-rate-picker{display:flex;gap:6px;margin-block-end:10px}
    .ms-rate-star{width:32px;height:32px;display:flex;align-items:center;justify-content:center;
        background:none;border:0;color:var(--ms-hair-2);padding:0}
    .ms-rate-star svg{fill:none;stroke:currentColor}
    .ms-rate-star.is-on{color:var(--ms-camel)}
    .ms-rate-star.is-on svg{fill:currentColor}

    /* ---------------- loyalty: checkout redeem row + account Rewards card ---------------- */
    .ms-loyrow{margin-block-end:14px}
    .ms-loyrow__teaser{font-size:.8rem;color:var(--ms-muted);line-height:1.5;background:var(--ms-sale-bg);
        border-radius:12px;padding:10px 12px}
    .ms-loyrow__teaser strong{display:block;color:var(--ms-ink);font-weight:700;margin-block-end:2px}
    .ms-loyrow__signin{display:block;color:var(--ms-camel);font-weight:600;margin-block-start:4px}
    .ms-loytoggle{display:flex;align-items:center;gap:10px;cursor:pointer;padding:2px 0}
    .ms-loytoggle input{position:absolute;opacity:0;width:1px;height:1px}
    .ms-loytoggle__switch{width:38px;height:22px;border-radius:999px;background:var(--ms-hair-2);
        position:relative;flex:0 0 auto;transition:background .15s}
    .ms-loytoggle__switch::after{content:'';position:absolute;inset-block-start:3px;inset-inline-start:3px;
        width:16px;height:16px;border-radius:50%;background:#fff;transition:inset-inline-start .15s}
    .ms-loytoggle input:checked ~ .ms-loytoggle__switch{background:var(--ms-camel)}
    .ms-loytoggle input:checked ~ .ms-loytoggle__switch::after{inset-inline-start:19px}
    .ms-loytoggle__label{font-size:.86rem;font-weight:600;flex:1}

    .ms-rewardscard{background:linear-gradient(135deg,var(--ms-card),var(--ms-card-2));border:1px solid var(--ms-hair);
        border-radius:var(--ms-radius-lg);padding:18px;margin-block-end:16px;box-shadow:var(--ms-shadow-card)}
    .ms-rewardscard__top{display:flex;align-items:center;gap:12px}
    .ms-rewardscard__ico{width:42px;height:42px;border-radius:50%;background:var(--ms-card);border:1px solid var(--ms-hair);
        display:flex;align-items:center;justify-content:center;color:var(--ms-camel);flex:0 0 auto}
    .ms-rewardscard__ico svg{width:20px;height:20px}
    .ms-rewardscard__program{font-family:var(--ms-font-serif);font-weight:500;font-size:1.02rem}
    .ms-rewardscard__earn{font-size:.8rem;color:var(--ms-muted);margin-top:1px}
    .ms-rewardscard__reward{font-size:.86rem;color:var(--ms-muted);margin:12px 0 0;line-height:1.5}
    .ms-rewardscard__tiers{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
    .ms-tierchip{padding:5px 12px;border-radius:999px;background:var(--ms-card);border:1px solid var(--ms-hair-2);
        font-size:.72rem;font-weight:700;color:var(--ms-muted)}
    .ms-tierchip.is-current{background:var(--ms-ink);color:var(--ms-ground);border-color:var(--ms-ink)}
    .ms-rewardscard__balance{margin-top:14px;font-size:1.3rem;font-weight:700;font-family:var(--ms-font-serif);color:var(--ms-ink)}
    .ms-rewardscard__balance small{display:block;font-size:.7rem;font-weight:600;color:var(--ms-muted);
        font-family:var(--ms-font-sans);margin-top:2px}
    .ms-rewardscard__signin{font-size:.8rem;color:var(--ms-camel);margin:12px 0 0;font-weight:600}

    @media (prefers-reduced-motion:reduce){
        .maison *{animation-duration:.001s !important;transition-duration:.001s !important}
    }
</style>
@endpush

{{-- =========================================================================
     DESKTOP LAYER (ADD-ONLY) — every rule above this block is the locked
     mobile design and is left byte-for-byte untouched. Everything below only
     ADDS composition inside min-width media queries (768 tablet / 1024
     desktop / 1280 wide, per the shared responsive contract) and reuses the
     same --ms-* custom properties everywhere, so light/dark + RTL keep
     working with no new color tokens. No class/id was renamed; JS hooks are
     unchanged — desktop-only layout is done purely via grid-column/grid-row
     placement of the existing children, never by moving them in the DOM.
     ========================================================================= --}}
@push('style')
<style nonce="{{ csp_nonce() }}">
    /* ---------------- tablet (≥768): scale up, no structural change ---------------- */
    @media (min-width:768px){
        .ms-shell{ padding-inline:24px }
        .ms-display{ font-size:2.3rem }
        .ms-grid{ gap:18px }
        .ms-card__meta{ padding:15px; padding-inline-end:58px }
        .ms-sheet{ width:min(520px,88vw) }
    }

    /* ---------------- desktop (≥1024): real composition ---------------- */
    @media (min-width:1024px){
        /* shell: lift the phone-column cap, real side padding, no dead grey gutters */
        .ms-shell{ max-width:min(1200px,92vw); padding-inline:clamp(20px,5vw,48px) }

        /* top nav: already a sticky bar with brand left / search / links+actions
           right, and .ms-navlinks / .ms-tabbar already swap over at 860px —
           this just gives it desktop breathing room and centers the search. */
        .ms-head{ padding-inline:clamp(20px,5vw,48px); gap:20px }
        .ms-headsearch{ max-width:480px; margin-inline:auto }
        .ms-navlink{ height:40px; padding-inline:16px }
        .ms-tabbar{ display:none }

        /* product grid already reaches 4-col at 960 — make it generous */
        .ms-grid{ gap:20px }
        .ms-card__meta{ padding:16px; padding-inline-end:60px }
        .ms-card__name{ font-size:1.05rem }
        .ms-price{ font-size:.98rem }

        /* ---- Home: 2-column editorial hero (copy/CTA left, lookbook right) ---- */
        [data-screen="home"]{
            display:grid;
            grid-template-columns:minmax(320px,1fr) minmax(360px,1.05fr);
            column-gap:48px;
            align-items:start;
        }
        [data-screen="home"] > *{ grid-column:1/-1 }
        /* left column: the editorial copy + CTA stack, top-aligned with the lookbook */
        [data-screen="home"] > .ms-eyebrow{ grid-column:1; grid-row:1 }
        [data-screen="home"] > .ms-display{ grid-column:1; grid-row:2; font-size:clamp(2.4rem,3.4vw,3.4rem) }
        [data-screen="home"] > .ms-lede{ grid-column:1; grid-row:3 }
        [data-screen="home"] > .ms-statusrow{ grid-column:1; grid-row:4; margin-block-start:16px }
        [data-screen="home"] > .ms-herocta{ grid-column:1; grid-row:5; justify-self:start; margin-block-start:22px }
        /* right column: the lookbook, spanning the whole copy stack */
        [data-screen="home"] > .ms-feature{ grid-column:2; grid-row:1 / span 5; align-self:stretch; margin:0 }
        /* full-width bands below the hero */
        [data-screen="home"] > .ms-banners{ grid-column:1/-1; grid-row:6; margin-block-start:26px }
        [data-screen="home"] > .ms-chips{ grid-column:1/-1; grid-row:7; margin-block-start:8px }
        /* no editorial picks to feature (< 2 real photos) — don't leave a dead gutter */
        [data-screen="home"]:not(:has(.ms-feature)) > .ms-eyebrow,
        [data-screen="home"]:not(:has(.ms-feature)) > .ms-display,
        [data-screen="home"]:not(:has(.ms-feature)) > .ms-lede,
        [data-screen="home"]:not(:has(.ms-feature)) > .ms-herocta{ grid-column:1/-1 }

        /* ---- Banners: 2-up side-by-side on desktop ---- */
        .ms-banner-track{flex-wrap:nowrap;overflow:visible;scroll-snap-type:none}
        .ms-banner{flex:1 1 0%;min-height:160px;aspect-ratio:21/9}
        .ms-banner-dots{display:none}

        /* ---- Shop: sticky left category rail + product grid to its right ---- */
        [data-screen="shop"]{
            display:grid;
            grid-template-columns:240px 1fr;
            column-gap:32px;
            align-items:start;
        }
        [data-screen="shop"] > .ms-subhdr{ grid-column:1/-1 }
        [data-screen="shop"] > #msShopChips{
            grid-column:1; grid-row:2 / span 20;
            display:flex; flex-direction:column; align-items:stretch; gap:6px;
            padding-block-start:2px;
            position:sticky; top:calc(var(--ms-nav-h) + 16px); align-self:start;
            max-height:calc(100vh - var(--ms-nav-h) - 32px); overflow-y:auto;
        }
        [data-screen="shop"] > #msShopChips .ms-chip{ width:100%; justify-content:flex-start; border-radius:12px; height:42px }
        /* (search input / filter row / filter panel / grid / no-results all fall
           through to the remaining column — no explicit rule needed: column 1
           is occupied by the spanning rail above, so auto-placement funnels
           every other child into column 2 in their existing DOM order.) */

        /* ---- Narrow-content screens: center in the column, don't stretch full-bleed ---- */
        [data-screen="category"],
        [data-screen="stylist"],
        [data-screen="visualsearch"],
        [data-screen="account"],
        [data-screen="wishlist"]{
            max-width:640px;
            margin-inline:auto;
        }

        /* ---- Product detail sheet: gallery left (sticky), buy-box right ---- */
        #msItemSheet{ width:min(920px,92vw) }
        #msItemSheet .ms-sheet__body{
            display:grid;
            grid-template-columns:minmax(280px,380px) 1fr;
            column-gap:36px;
            align-items:start;
            padding-inline:28px;
        }
        #msItemSheet .ms-sheet__body > #msItemGallery{
            grid-column:1;
            position:sticky; top:0; align-self:start;
        }
        #msItemSheet .ms-sheet__body > #msItemBuyBox{
            grid-column:2;
        }

        /* ---- Cart sheet: line items left, sticky order-summary card right ---- */
        #msCartSheet{ width:min(880px,92vw) }
        #msCartSheet .ms-sheet__body{ padding-inline:28px }
        #msCartView{
            display:grid;
            grid-template-columns:1fr 320px;
            column-gap:32px;
            align-items:start;
        }
        #msCartView > #msCartSummary{
            grid-column:2;
            position:sticky; top:calc(var(--ms-nav-h) + 16px);
            background:var(--ms-card); border:1px solid var(--ms-hair); border-radius:var(--ms-radius);
            padding:18px; box-shadow:var(--ms-shadow-card);
        }
        /* ---- Checkout form: fields left, sticky order-summary + CTA right ---- */
        #msCheckoutForm{
            display:grid;
            grid-template-columns:1fr 320px;
            column-gap:32px;
            align-items:start;
        }
        /* Every field group belongs in the LEFT column — without this they
           auto-flow into whichever cell is free (including column 2's rows next
           to the summary), which zig-zags the form across both columns. */
        #msCheckoutForm > *:not(.ms-summary):not(#msPlaceOrder){ grid-column:1; }
        #msCheckoutForm > .ms-summary{
            grid-column:2;
            position:sticky; top:calc(var(--ms-nav-h) + 16px);
            background:var(--ms-card); border:1px solid var(--ms-hair); border-radius:var(--ms-radius);
            padding:18px; box-shadow:var(--ms-shadow-card);
        }
        #msCheckoutForm > #msPlaceOrder{ grid-column:2; margin-block-start:14px }
    }

    /* ---------------- wide (≥1280): 5-col grid, a little more room ---------------- */
    @media (min-width:1280px){
        .ms-shell{ max-width:1240px }
        .ms-grid{ grid-template-columns:repeat(5,1fr); gap:24px }
    }
</style>
@endpush

@section('content')
<div class="maison" id="maison" data-store-id="{{ $store->id }}" data-theme-slug="maison">
    <header class="ms-head">
        <button type="button" class="ms-brand" id="msGoHome" aria-label="{{ ___('Home') }}">
            @if($store->main_image && $store->main_image !== 'default.png')
                @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="">@endif
            @else
                <svg class="ms-brand-mark" viewBox="0 0 40 40" aria-hidden="true"><path fill="currentColor" d="M20 3l2.9 11.2L34 11.3l-8.1 8.7L37 26l-11.9-1.1L26.5 37 20 27.4 13.5 37l1.4-12.1L3 26l11.1-6L6 11.3l11.1 2.9z"/></svg>
            @endif
            <span class="ms-brand-name">{{ $store->title }}</span>
        </button>

        <button type="button" class="ms-headsearch" id="msSearchOpen" aria-label="{{ ___('Search or ask the stylist') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
            <span>{{ ___('Search or ask the stylist…') }}</span>
        </button>

        <nav class="ms-navlinks" aria-label="{{ ___('Main') }}">
            <button type="button" class="ms-navlink is-active" data-nav="home">{{ ___('Home') }}</button>
            <button type="button" class="ms-navlink" data-nav="shop">{{ ___('Shop') }}</button>
            <button type="button" class="ms-navlink" data-nav="stylist">{{ ___('AI stylist') }}</button>
            <button type="button" class="ms-navlink" data-nav="account">{{ ___('Account') }}</button>
        </nav>

        <div class="ms-headrow">
            @if($msLanguages->count() > 1)
                <label class="ms-langsel" aria-label="{{ ___('Language') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
                    <select id="msLangSel">
                        @foreach($msLanguages as $lang)
                            <option value="{{ $lang->code }}" data-url="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}" @if(get_lang() === $lang->code) selected @endif>{{ strtoupper($lang->code) }}</option>
                        @endforeach
                    </select>
                </label>
                <script nonce="{{ csp_nonce() }}">
                    (function(){var s=document.getElementById('msLangSel');if(!s)return;s.addEventListener('change',function(e){var o=e.target.selectedOptions[0];if(!o)return;var code=o.value;try{document.cookie='Quick_user_lang_code='+code+';path=/;max-age=31536000;samesite=lax';document.cookie='qc_lang_choice='+code+';path=/;max-age=31536000;samesite=lax';}catch(err){}window.location.assign(o.getAttribute('data-url')||window.location.href);});})();
                </script>
            @endif
            <button type="button" class="ms-icbtn" id="msThemeToggle" aria-label="{{ ___('Toggle dark mode') }}" title="{{ ___('Toggle dark mode') }}">
                <svg id="msThemeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
            </button>
            <button type="button" class="ms-icbtn" data-nav="wishlist" aria-label="{{ ___('Wishlist') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
            </button>
            <button type="button" class="ms-icbtn ms-notifbell" id="msNotifBell" aria-label="{{ ___('Notifications') }}" style="position:relative">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                <i class="ms-notif-dot" hidden aria-hidden="true"></i>
            </button>
            <button type="button" class="ms-icbtn" id="msCartOpen" aria-label="{{ ___('Bag') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
                <i class="ms-badge" id="msCartBadge" hidden>0</i>
            </button>
        </div>
    </header>

    <main class="ms-shell" id="ms-main">

        {{-- ============================== HOME ============================== --}}
        <section class="ms-screen" data-screen="home">
            <div class="ms-eyebrow">{{ ___('Discover') }}</div>
            <h1 class="ms-display">{{ ___('Your best clothes') }}</h1>
            @if(!empty($store->sub_title))<p class="ms-lede">{{ $store->sub_title }}</p>@endif
            <button type="button" class="ms-herocta" data-nav="shop">{{ ___('Shop the collection') }}</button>

            {{-- Crave-parity status row: Open-Now chip + (multi-store) branch switch. --}}
            <div class="ms-statusrow">
                <span class="ms-openchip @if(! $msIsOpen) is-closed @endif" data-mb-openchip data-mb-closed-class="is-closed">
                    <i aria-hidden="true"></i><span data-mb-open-text>{{ $msIsOpen ? ___('Open now') : ___('Closed') }}@if($msHoursToday) · {{ $msHoursToday }}@endif</span>
                </span>
                @if($msShowBranches)
                    <label class="ms-branchsel" aria-label="{{ ___('Branch') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <select id="msBranchSel">
                            @foreach($msBranches as $branch)
                                <option value="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}" @if(($msActiveBranch->id ?? null) === $branch->id) selected @endif>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>

            @if($msBanners->count())
                <div class="ms-banners" id="msBanners">
                    <div class="ms-banner-track" id="msBannerTrack">
                        @foreach($msBanners as $b)
                            <a class="ms-banner" @if($b->link_url) href="{{ storefront_localized_link($b->link_url) }}" target="_blank" rel="noopener" @else href="#" data-banner-screen="shop" @endif>
                                @if($b->image)<img class="ms-banner-img" src="{{ $b->image_url ?? media_url('storage/menu_banners/', $b->image, $store->id ?? null) }}" alt="" data-csp-error="hide">@endif
                                <span class="ms-banner-copy">
                                    @if($b->subtitle)<span class="ms-banner-kick">{{ $b->subtitle }}</span>@endif
                                    <span class="ms-banner-title">{{ $b->title }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                    @if($msBanners->count() > 1)<div class="ms-banner-dots" id="msBannerDots" aria-hidden="true"></div>@endif
                </div>
            @endif

            <div class="ms-chips" role="tablist" aria-label="{{ $vocab['categories'] ?? ___('Categories') }}">
                <button type="button" class="ms-chip" aria-pressed="true" data-home-chip="all">{{ ___('New in') }}</button>
                @foreach($categories as $cat)
                    <button type="button" class="ms-chip" aria-pressed="false" data-home-chip-cat="{{ $cat->id }}">{{ $cat->name }}</button>
                @endforeach
                @if($categories->isNotEmpty())
                    <button type="button" class="ms-chip" data-nav="category">{{ $vocab['categories'] ?? ___('Categories') }}</button>
                @endif
            </div>

            @if($editorialPicks->count() === 2)
                <div class="ms-feature">
                    @foreach($editorialPicks as $m)
                        @php $jd = $jsItems[$m->id]; @endphp
                        <a href="?item={{ $jd['id'] }}" class="ms-feature__card" data-open-item-link="{{ $jd['id'] }}">
                            @if((string) ($jd['img']) !== '')<img src="{{ $jd['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
                            <span class="ms-feature__scrim" aria-hidden="true"></span>
                            <span class="ms-feature__cap">
                                <span class="ms-feature__eyebrow">{{ ___('Editorial') }}</span>
                                <span class="ms-feature__name">{{ $jd['name'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif

            @if($items->isEmpty())
                <p class="ms-empty">{{ ___('Nothing here yet.') }}</p>
            @else
                <div class="ms-grid" id="msHomeGrid">
                    @foreach($newInItems as $m)
                        @include('store_templates.maison.partials._card', ['jd' => $jsItems[$m->id]])
                    @endforeach
                </div>
                @if($items->count() > $newInItems->count())
                    <div style="text-align:center;margin-top:22px">
                        <button type="button" class="ms-btn ms-btn--ghost" style="display:inline-flex;padding:0 24px;width:auto;height:46px" data-nav="shop">{{ ___('Shop the collection') }}</button>
                    </div>
                @endif
            @endif
        </section>

        {{-- ============================== CATEGORY ============================== --}}
        <section class="ms-screen" data-screen="category" hidden>
            <div class="ms-subhdr">
                <button type="button" class="ms-back" data-back><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>{{ ___('Back') }}</button>
                <h2>{{ $vocab['categories'] ?? ___('Categories') }}</h2>
                <span style="width:52px"></span>
            </div>

            {{-- F3 · stories rail — public/ungated feed (Story::visible(), already
                 active + in-window + sorted); fetched on load and hidden entirely
                 when empty (see loadStories() below). --}}
            <div class="ms-stories" id="msStoriesRail" role="list" aria-label="{{ ___('Stories') }}" hidden></div>

            <div class="ms-promoban">
                <div class="k">{{ ___('New in') }}</div>
                <h3>{{ ___('Discover the latest arrivals') }}</h3>
                <button type="button" class="shop" data-nav="shop">{{ ___('Shop now') }}</button>
            </div>

            @if($categories->isEmpty())
                <p class="ms-empty">{{ ___('Nothing here yet.') }}</p>
            @else
                <div class="ms-catlist">
                    <button type="button" class="ms-catrow" data-nav-shop-cat="all">
                        <span>{{ ___('All') }} {{ $vocab['items'] ?? ___('Products') }}</span>
                        <span class="ms-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
                    </button>
                    @foreach($categories as $cat)
                        <button type="button" class="ms-catrow" data-nav-shop-cat="{{ $cat->id }}">
                            <span>{{ $cat->name }}</span>
                            <span class="ms-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ============================== SHOP (filters/sort/results) ============================== --}}
        <section class="ms-screen" data-screen="shop" hidden>
            <div class="ms-subhdr">
                <button type="button" class="ms-back" data-back><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>{{ ___('Back') }}</button>
                <h2 id="msShopTitle">{{ $vocab['items'] ?? ___('Products') }}</h2>
                <span style="width:52px"></span>
            </div>

            @if($items->isEmpty())
                <p class="ms-empty">{{ ___('Nothing here yet.') }}</p>
            @else
                <input type="search" class="ms-input" id="msShopSearch" autocomplete="off" style="width:100%;margin-block-end:10px;border-radius:999px;padding:11px 16px"
                       placeholder="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}"
                       aria-label="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}">
                <div class="ms-chips" role="tablist" aria-label="{{ $vocab['categories'] ?? ___('Categories') }}" id="msShopChips">
                    <button type="button" class="ms-chip" aria-pressed="true" data-shop-cat="all">{{ ___('All') }}</button>
                    @foreach($categories as $cat)
                        <button type="button" class="ms-chip" aria-pressed="false" data-shop-cat="{{ $cat->id }}">{{ $cat->name }}</button>
                    @endforeach
                </div>

                <div class="ms-filterrow">
                    <button type="button" class="ms-pillbtn" id="msFilterToggle" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h7M15 18h5"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="13" cy="18" r="2"/></svg>
                        {{ ___('Filters') }}
                    </button>
                    <select class="ms-select" id="msSort" style="flex:0 1 170px" aria-label="{{ ___('Sort by') }}">
                        <option value="newest">{{ ___('Newest') }}</option>
                        <option value="price_asc">{{ ___('Price: low to high') }}</option>
                        <option value="price_desc">{{ ___('Price: high to low') }}</option>
                        <option value="name_asc">{{ ___('Name: A to Z') }}</option>
                    </select>
                    <span class="ms-results" id="msResults"></span>
                </div>

                <div class="ms-filterpanel" id="msFilterPanel" hidden>
                    <div class="ms-field-row">
                        @if($brands->isNotEmpty())
                            <select class="ms-select" id="msBrandFilter" aria-label="{{ ___('Brand') }}">
                                <option value="">{{ ___('All brands') }}</option>
                                @foreach($brands as $b)
                                    <option value="{{ Str::lower($b) }}">{{ $b }}</option>
                                @endforeach
                            </select>
                        @endif
                        <label style="flex:1 1 100%;font-size:.78rem;color:var(--ms-muted);font-weight:600">
                            {{ ___('Price') }}
                            <input type="range" class="ms-range" id="msPriceRange" min="{{ (int) floor($minPrice) }}" max="{{ (int) ceil($maxPrice) }}" value="{{ (int) ceil($maxPrice) }}">
                            <span class="ms-rangevals"><span>{{ price_symbol_format($minPrice) }}</span><span id="msPriceMaxLabel">{{ price_symbol_format($maxPrice) }}</span></span>
                        </label>
                        @if(collect($jsItems)->contains(fn ($jd) => $jd['discountPct']))
                            <label style="flex:1 1 100%;font-size:.84rem;display:flex;align-items:center;gap:8px">
                                <input type="checkbox" id="msSaleOnly"> {{ ___('Sale') }}
                            </label>
                        @endif
                    </div>
                    <button type="button" class="ms-clearlink" id="msFilterClear">{{ ___('Clear filter') }}</button>
                </div>

                <div class="ms-grid" id="msShopGrid">
                    @foreach($items as $m)
                        @include('store_templates.maison.partials._card', ['jd' => $jsItems[$m->id]])
                    @endforeach
                </div>
                <p class="ms-empty" id="msShopNoResults" hidden>{{ ___('No results.') }}</p>
            @endif
        </section>

        {{-- ============================== AI STYLIST ============================== --}}
        <section class="ms-screen" data-screen="stylist" hidden>
            <div class="ms-subhdr">
                <button type="button" class="ms-back" data-back><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>{{ ___('Back') }}</button>
                <h2>{{ ___('AI Style Search') }}</h2>
                <span style="width:52px"></span>
            </div>

            <div class="ms-aiwrap">
                <div class="ms-askbox">
                    <div class="top">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="var(--ms-camel)"/></svg>
                        <span id="msAiIntro">{{ ___('Ask anything…') }}</span>
                    </div>
                    <form id="msAiForm" style="display:flex;gap:8px;margin-top:10px">
                        <input type="text" class="ms-input" id="msAiInput" maxlength="140" placeholder="{{ ___('I need a black oversized hoodie under $80') }}" aria-label="{{ ___('Ask the stylist') }}">
                        <button type="submit" class="ms-btn ms-btn--dark" style="flex:0 0 auto;width:auto;padding:0 18px;height:44px">{{ ___('Ask') }}</button>
                    </form>
                </div>
                <div class="ms-label" style="margin:0">{{ ___('Try asking') }}</div>
                <div class="ms-tryrow">
                    <button type="button" class="ms-chip" data-ai-ask="{{ ___('Office outfit') }}">{{ ___('Office outfit') }}</button>
                    <button type="button" class="ms-chip" data-ai-ask="{{ ___('Rain jacket') }}">{{ ___('Rain jacket') }}</button>
                    <button type="button" class="ms-chip" data-ai-ask="{{ ___('Formal look') }}">{{ ___('Formal look') }}</button>
                </div>
                <div class="ms-aithread" id="msAiThread">
                    <div class="ms-chatrow">
                        <span class="who"><svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="currentColor"/></svg></span>
                        <div class="ms-bubble">{{ ___('Hi! What are you shopping for today?') }}</div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============================== VISUAL SEARCH ============================== --}}
        <section class="ms-screen" data-screen="visualsearch" hidden>
            <div class="ms-subhdr">
                <button type="button" class="ms-back" data-back><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>{{ ___('Back') }}</button>
                <h2>{{ ___('Visual search') }}</h2>
                <span style="width:52px"></span>
            </div>

            <div class="ms-vsframe" id="msVsFrame">
                <svg class="ph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"/><circle cx="12" cy="13" r="3.5"/><path d="M8 7l1.5-3h5L16 7"/></svg>
                <div class="ms-vscorners"><span></span><span></span><span></span><span></span></div>
                <div class="ms-vsscan"></div>
            </div>
            <div class="ms-vsupload">
                <label class="ms-btn ms-btn--dark ms-btn--block" style="display:flex" for="msVsFile">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><circle cx="12" cy="13" r="3.5"/><path d="M8 7l1.5-3h5L16 7"/></svg>
                    {{ ___('Take or upload a photo') }}
                </label>
                <input type="file" id="msVsFile" accept="image/*" capture="environment" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">
            </div>
            <div id="msVsResult"></div>
        </section>

        {{-- ============================== WISHLIST ============================== --}}
        <section class="ms-screen" data-screen="wishlist" hidden>
            <div class="ms-subhdr">
                <h2>{{ ___('Wishlist') }}</h2>
                <span style="width:52px"></span>
            </div>
            <div id="msWishlistBody"></div>
        </section>

        {{-- ============================== ACCOUNT ============================== --}}
        <section class="ms-screen" data-screen="account" hidden>
            <div class="ms-subhdr">
                <h2>{{ ___('Account') }}</h2>
                <span style="width:52px"></span>
            </div>

            {{-- F3(a) · Rewards card — the loyalty_teaser option is public (no
                 auth); the live balance/tier (auth:customer) is layered on top
                 by loadRewardsBalance() whenever this screen is opened. --}}
            @if($loyaltyTeaser)
                <div class="ms-rewardscard" id="msRewardsCard">
                    <div class="ms-rewardscard__top">
                        <span class="ms-rewardscard__ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 5.3 5.8.8-4.2 4 1 5.7L12 15l-5 2.8 1-5.7-4.2-4 5.8-.8z"/></svg></span>
                        <div>
                            <div class="ms-rewardscard__program">{{ $loyaltyTeaser['program'] ?? ___('Rewards') }}</div>
                            @if(!empty($loyaltyTeaser['earn']))<div class="ms-rewardscard__earn">{{ $loyaltyTeaser['earn'] }}</div>@endif
                        </div>
                    </div>
                    @if(!empty($loyaltyTeaser['reward']))<p class="ms-rewardscard__reward">{{ $loyaltyTeaser['reward'] }}</p>@endif
                    <div class="ms-rewardscard__tiers">
                        <span class="ms-tierchip" data-tier="Silver">{{ ___('Silver') }}</span>
                        <span class="ms-tierchip" data-tier="Gold">{{ ___('Gold') }}</span>
                        <span class="ms-tierchip" data-tier="Platinum">{{ ___('Platinum') }}</span>
                    </div>
                    <div class="ms-rewardscard__balance" id="msRewardsBalance" hidden></div>
                    <p class="ms-rewardscard__signin" id="msRewardsSignin">{{ ___('Sign in to earn & redeem points') }}</p>
                </div>
            @endif

            @if($store->phone)
                <div class="ms-acccard">
                    <span class="aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg></span>
                    <span class="abody">
                        <span class="at">{{ ___('Help & support') }}</span>
                        <span class="as"><a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $store->phone) }}" style="text-decoration:none;color:inherit">{{ $store->phone }}</a></span>
                    </span>
                </div>
            @endif

            <div class="ms-field" style="margin-block-end:18px">
                <label for="msTrackInput">{{ ___('Track an order') }}</label>
                <div class="ms-lookup">
                    <input type="text" id="msTrackInput" class="ms-input" placeholder="{{ ___('Order tracking link or code') }}" style="border-radius:11px">
                    <button type="button" class="ms-btn ms-btn--dark" style="width:auto;padding:0 18px" id="msTrackGo">{{ ___('Track order') }}</button>
                </div>
            </div>

            @if(($postOptions->customer_auth_mode ?? '') !== 'demo')
                @if(auth('customer')->check())
                    @if(\App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
                        <button type="button" class="ms-accrow" data-mb-wallet-open>
                            <span class="aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M16 14h2"/></svg></span>
                            <span class="at">{{ ___('Wallet — add funds') }}</span>
                        </button>
                    @endif
                    <button type="button" class="ms-accrow" data-mb-logout data-logout-url="{{ route('customer.logout', $store->id) }}">
                        <span class="aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3M10 12h9M16 8l3 4-3 4"/></svg></span>
                        <span class="at">{{ ___('Log out') }}</span>
                    </button>
                @else
                    <button type="button" class="ms-accrow" data-mb-auth-open>
                        <span class="aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H6a2 2 0 00-2 2v12a2 2 0 002 2h3M14 12H4M11 8l-3 4 3 4"/></svg></span>
                        <span class="at">{{ ___('Sign in / Register') }}</span>
                    </button>
                @endif
            @endif
            <button type="button" class="ms-accrow" data-nav="wishlist">
                <span class="aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg></span>
                <span class="at">{{ ___('Wishlist') }}</span>
                <span class="ms-chev"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
            </button>
            <button type="button" class="ms-accrow" id="msAccShare" data-share-url="{{ url()->current() }}" data-share-title="{{ $store->title }}">
                <span class="aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg></span>
                <span class="at">{{ ___('Share this store') }}</span>
                <span class="ms-chev"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
            </button>
            <button type="button" class="ms-accrow" id="msAccThemeToggle">
                <span class="aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg></span>
                <span class="at" id="msAccThemeLabel">{{ ___('Dark mode') }}</span>
                <span class="ms-chev"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
            </button>
            <button type="button" class="ms-accrow" data-nav="home">
                <span class="aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-9"/></svg></span>
                <span class="at">{{ ___('Continue shopping') }}</span>
                <span class="ms-chev"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
            </button>
            {{-- Privacy: wipe cart / favourites / saved addresses this browser keeps
                 (shared window.mbClearDeviceData engine). --}}
            <button type="button" class="ms-accrow ms-accrow--danger" id="msClearData">
                <span class="aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg></span>
                <span class="at">{{ ___('Clear saved data on this device') }}</span>
                <span class="ms-chev"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
            </button>

            {{-- Order history — fetched from publicView.myOrders (auth:customer). --}}
            <h3 class="ms-label" style="margin-top:22px">{{ ___('My Orders') }}</h3>
            <div id="msOrdersList"></div>
            <p class="ms-orders-empty" id="msOrdersEmpty" hidden>{{ ___('No orders yet') }}</p>
            <p class="ms-orders-empty" id="msOrdersGuest" hidden>{{ ___('Sign in to see your orders') }}</p>
        </section>
    </main>

    <nav class="ms-tabbar" aria-label="{{ ___('Main') }}">
        <button type="button" class="ms-tab is-active" data-nav="home" aria-current="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-9"/></svg>
            <span>{{ ___('Home') }}</span>
        </button>
        <button type="button" class="ms-tab" data-nav="shop" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
            <span>{{ ___('Shop') }}</span>
        </button>
        <button type="button" class="ms-tab-fab" data-nav="stylist" aria-label="{{ ___('AI Style Search') }}">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="currentColor"/></svg>
        </button>
        <button type="button" class="ms-tab" data-nav="wishlist" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
            <span>{{ ___('Wishlist') }}</span>
        </button>
        <button type="button" class="ms-tab" data-nav="account" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
            <span>{{ ___('Account') }}</span>
        </button>
    </nav>

    {{-- F3 · full-screen story viewer: timed segmented progress bars, tap
         left/right or swipe to move prev/next, optional CTA, close (×). Kept
         as a dedicated fixed overlay (not one of the off-canvas .ms-sheet
         panels) since a story reel is full-bleed, not a side sheet. --}}
    <div class="ms-story-viewer" id="msStoryViewer" role="dialog" aria-modal="true" aria-label="{{ ___('Stories') }}" aria-hidden="true" hidden>
        <div class="ms-story-viewer__bars" id="msStoryBars"></div>
        <div class="ms-story-viewer__head">
            <button type="button" class="ms-story-viewer__close" id="msStoryClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="ms-story-viewer__stage" id="msStoryStage">
            <img id="msStoryImg" alt="" data-csp-error="hide">
            <button type="button" class="ms-story-viewer__zone ms-story-viewer__zone--prev" id="msStoryPrevZone" aria-label="{{ ___('Previous') }}"></button>
            <button type="button" class="ms-story-viewer__zone ms-story-viewer__zone--next" id="msStoryNextZone" aria-label="{{ ___('Next') }}"></button>
            <div class="ms-story-viewer__foot">
                <p class="ms-story-viewer__cap" id="msStoryCap"></p>
                <a class="ms-story-viewer__cta" id="msStoryCta" href="#" hidden></a>
            </div>
        </div>
    </div>

    {{-- Product detail sheet: color swatches + size pills from MenuVariant.options
         axes, quantity stepper, sticky add-to-bag/buy-now actionbar. --}}
    <div class="ms-scrim" id="msItemScrim"></div>
    <aside class="ms-sheet" id="msItemSheet" role="dialog" aria-modal="true" aria-label="{{ $vocab['item'] ?? ___('Product') }}" aria-hidden="true" inert>
        <div class="ms-sheet__head">
            <strong class="ms-sheet__title" id="msItemName" tabindex="-1"></strong>
            <button type="button" class="ms-icbtn" id="msItemClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="ms-sheet__body">
            <div id="msItemGallery">
                <div class="ms-pd-img" id="msItemImgWrap">
                    <img id="msItemImg" alt="" data-csp-error="hide">
                </div>
                <div class="ms-pd-thumbs" id="msItemThumbs" hidden></div>
            </div>
            <div id="msItemBuyBox">
                <div class="ms-pd-title" id="msItemTitle"></div>
                <div class="ms-pd-prices">
                    <span class="ms-pd-price" id="msItemPrice"></span>
                    <span class="ms-price ms-price--was" id="msItemWas" style="color:var(--ms-muted);font-size:.9rem" hidden></span>
                </div>
                <span class="ms-pd-stock" id="msItemStock" hidden></span>
                <p class="ms-pd-desc" id="msItemDesc"></p>

                <div id="msColorGroup" hidden>
                    <div class="ms-label">{{ ___('Color') }}</div>
                    <div class="ms-swatches" id="msSwatches"></div>
                </div>
                <div id="msSizeGroup" hidden>
                    <div class="ms-label">{{ ___('Size') }}</div>
                    <div class="ms-sizes" id="msSizes"></div>
                </div>

                <div class="ms-group" id="msAttrsGroup" hidden>
                    <div class="ms-label">{{ ___('Details') }}</div>
                    <table class="ms-attrs" id="msAttrsTable"></table>
                </div>

                <div class="ms-label">{{ ___('Quantity') }}</div>
                <div class="ms-qty">
                    <button type="button" id="msQtyMinus" aria-label="{{ ___('Decrease') }}">&minus;</button>
                    <span id="msQtyVal">1</span>
                    <button type="button" id="msQtyPlus" aria-label="{{ ___('Increase') }}">&plus;</button>
                </div>

                {{-- F6 · reviews --}}
                <div class="ms-reviews" id="msReviews">
                    <div class="ms-label" style="margin-block-start:26px">{{ ___('Reviews') }}</div>
                    <div class="ms-reviews__summary" id="msReviewsSummary" hidden>
                        <div class="ms-reviews__avg">
                            <span class="ms-reviews__avgnum" id="msReviewsAvgNum">0.0</span>
                            <span class="ms-reviews__avgstars" id="msReviewsAvgStars" aria-hidden="true"></span>
                            <span class="ms-reviews__count" id="msReviewsCount"></span>
                        </div>
                        <div class="ms-reviews__dist" id="msReviewsDist"></div>
                    </div>
                    <div class="ms-reviews__list" id="msReviewsList"></div>
                    <p class="ms-reviews__empty" id="msReviewsEmpty" hidden>{{ ___('No reviews yet — be the first to share your thoughts.') }}</p>

                    <div class="ms-label" style="margin-block-start:18px">{{ ___('Write a review') }}</div>
                    <form id="msReviewForm">
                        <div class="ms-rate-picker" id="msReviewStars" role="radiogroup" aria-label="{{ ___('Rating') }}">
                            @for ($msStar = 1; $msStar <= 5; $msStar++)
                                <button type="button" class="ms-rate-star" data-star="{{ $msStar }}" aria-label="{{ ___(':count stars', ['count' => $msStar]) }}" aria-pressed="false">
                                    <svg viewBox="0 0 24 24" width="22" height="22" stroke-width="1.5"><path d="M12 3l2.6 5.6 6.1.8-4.5 4.3 1.1 6-5.3-2.9-5.3 2.9 1.1-6-4.5-4.3 6.1-.8z"/></svg>
                                </button>
                            @endfor
                        </div>
                        <input type="text" class="ms-input" id="msReviewName" maxlength="80" placeholder="{{ ___('Your name (optional)') }}" style="margin-block-end:10px;width:100%">
                        <textarea class="ms-input" id="msReviewBody" maxlength="2000" rows="3" placeholder="{{ ___('Share your thoughts…') }}" style="margin-block-end:10px;resize:vertical;min-height:80px;width:100%"></textarea>
                        <div id="msReviewMsg"></div>
                        <button type="submit" class="ms-btn ms-btn--dark" style="width:auto;padding:0 22px">{{ ___('Submit review') }}</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="ms-sheet__foot">
            <div class="ms-actionbar">
                <button type="button" class="ms-btn ms-btn--ghost" id="msAddToCart">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
                    {{ ___('Add to cart') }}
                </button>
                <button type="button" class="ms-btn ms-btn--dark" id="msBuyNow">{{ ___('Buy now') }}</button>
            </div>
        </div>
    </aside>

    {{-- Cart / checkout / confirm sheet — posts straight to the native goods
         checkout (GoodsCheckoutController), the same store-side cart each
         item's `addRoute` fills. Same contract as store_templates/shelf's
         cart sheet (postAddToCart/CHECKOUT_ROUTE/CART_LINE_ROUTE_TEMPLATE),
         with an added "confirm" inner view for the order confirmation +
         track-order hand-off. --}}
    {{-- Address map picker — maison-skinned .ms-sheet driven by window.mbMapPicker. --}}
    <div class="ms-scrim" id="msMapScrim"></div>
    <aside class="ms-sheet ms-mapsheet" id="msMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" aria-hidden="true" inert>
        <div class="ms-sheet__head">
            <strong class="ms-sheet__title" id="msMapTitle" tabindex="-1">{{ ___('Choose on map') }}</strong>
            <button type="button" class="ms-icbtn" id="msMapClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="ms-sheet__body">
            <div class="ms-map-canvas" id="msMap"></div>
            <p class="ms-map-preview" id="msMapPreview">{{ ___('Move the map to place the pin') }}</p>
        </div>
        <div class="ms-sheet__foot">
            <button type="button" class="ms-btn ms-btn--dark" id="msMapConfirm">{{ ___('Use this location') }}</button>
        </div>
    </aside>

    <div class="ms-scrim" id="msCartScrim"></div>
    <aside class="ms-sheet" id="msCartSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Bag') }}" aria-hidden="true" inert>
        <div class="ms-sheet__head">
            <strong class="ms-sheet__title" id="msCartTitle" tabindex="-1">{{ ___('My bag') }}</strong>
            <button type="button" class="ms-icbtn" id="msCartClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="ms-sheet__body">
            <div id="msCartView">
                <div id="msCartLines"></div>
                <div class="ms-empty" id="msCartEmpty">
                    <span class="ms-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg></span>
                    <h3>{{ ___('Your bag is empty') }}</h3>
                    <p>{{ ___('Add a piece you love to get started.') }}</p>
                </div>
                <div class="ms-summary" id="msCartSummary" hidden>
                    <div class="ms-srow"><span>{{ ___('Subtotal') }}</span><span id="msCartSubtotal"></span></div>
                    <div class="ms-srow" id="msCartTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="msCartTax"></span></div>
                    <div class="ms-srow total"><span>{{ ___('Total') }}</span><span id="msCartTotal"></span></div>
                </div>
            </div>

            <form id="msCheckoutForm" hidden>
                <div id="msCheckoutMsg"></div>
                <div class="ms-field">
                    <label for="msName">{{ ___('Name') }}</label>
                    <input type="text" id="msName" name="name" required maxlength="255">
                </div>
                <div class="ms-field">
                    <label for="msPhone">{{ ___('Phone') }}</label>
                    <input type="tel" id="msPhone" name="phone" required maxlength="25">
                </div>
                @if($modes->count() > 1)
                <div class="ms-field">
                    <label for="msMode">{{ ___('Shipping method') }}</label>
                    <select id="msMode" name="mode">
                        @foreach($modes as $mode)
                            <option value="{{ $mode['value'] }}" data-needs-address="{{ $mode['needsAddress'] ? 1 : 0 }}" data-needs-slot="{{ $mode['needsSlot'] ? 1 : 0 }}">{{ $mode['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="ms-field" id="msAddressField" hidden>
                    <label for="msAddress">{{ ___('Deliver to') }}</label>
                    <div class="ms-addr">
                        <input type="text" id="msAddress" name="address" maxlength="255" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="{{ ___('Start typing your address…') }}">
                        <button type="button" class="ms-addr-loc" id="msAddrLoc" aria-label="{{ ___('Use my location') }}" title="{{ ___('Use my location') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                        </button>
                        <div class="ms-addr-suggest" id="msAddrSuggest" hidden></div>
                    </div>
                    {{-- Drop-a-pin picker (shared window.mbMapPicker engine, maison-skinned sheet). --}}
                    <button type="button" class="ms-maplink" id="msMapBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.4"/></svg>
                        {{ ___('Choose on map') }}
                    </button>
                </div>
                <div class="ms-field" id="msSlotField" hidden>
                    <label>{{ ___('Pick a time') }}</label>
                    <div class="ms-slots" id="msSlots"></div>
                </div>
                <div class="ms-summary" style="margin-block-end:16px">
                    {{-- Loyalty redeem row — balance is fetched (auth:customer) every
                         time checkout opens; guests / inactive programs / not-yet-
                         eligible balances fall back to the public teaser + a sign-in
                         hint. The hidden input is what the existing order submit
                         forwards; the server re-derives the actual discount. --}}
                    <div class="ms-loyrow" id="msLoyRow" hidden>
                        <div class="ms-loyrow__teaser" id="msLoyTeaser" hidden></div>
                        <label class="ms-loytoggle" id="msLoyToggleWrap" hidden>
                            <input type="checkbox" id="msLoyToggleCheckbox">
                            <span class="ms-loytoggle__label" id="msLoyToggleLabel"></span>
                            <span class="ms-loytoggle__switch" aria-hidden="true"></span>
                        </label>
                    </div>
                    <input type="hidden" name="loyalty_redeem" id="msLoyaltyRedeemInput" value="0">
                    {{-- Promo code + gift card — applied server-side via OrderPricing. --}}
                    <div class="ms-couponrow">
                        <input type="text" class="ms-coupon-input" id="msCouponInput" autocomplete="off" placeholder="{{ ___('Promo code') }}" aria-label="{{ ___('Promo code') }}">
                        <button type="button" class="ms-btn ms-btn--ghost ms-coupon-apply" id="msCouponApply">{{ ___('Apply') }}</button>
                    </div>
                    <p class="ms-coupon-msg" id="msCouponMsg" hidden></p>
                    <input type="hidden" name="coupon_code" id="msCouponField" value="">
                    <input type="text" class="ms-coupon-input" id="msGiftCardInput" name="gift_card_code" autocomplete="off" placeholder="{{ ___('Gift card code') }}" aria-label="{{ ___('Gift card code') }}" style="margin-top:8px">
                    <div class="ms-srow"><span>{{ ___('Subtotal') }}</span><span id="msCheckoutSubtotal"></span></div>
                    <div class="ms-srow" id="msCheckoutDiscountRow" hidden><span>{{ ___('Discount') }}</span><span id="msCheckoutDiscount"></span></div>
                    <div class="ms-srow" id="msCheckoutTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="msCheckoutTax"></span></div>
                    <div class="ms-srow total"><span>{{ ___('Total') }}</span><span id="msCheckoutTotal"></span></div>
                </div>
                <button type="button" class="ms-btn ms-btn--dark ms-btn--block" id="msPlaceOrder">{{ ___('Place order') }}</button>
            </form>

            <div id="msConfirmView" hidden>
                <div class="ms-confwrap">
                    <svg class="ms-checkc" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="45"/><path d="M30 52l14 14 26-30"/></svg>
                    <h3>{{ ___('Order placed') }}</h3>
                    <p>{{ ___('Your order is confirmed and on its way.') }}</p>
                    <div class="ms-orderno" id="msOrderNo"></div>
                    <div class="ms-track-mini">
                        <div class="ms-tstep done"><span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg></span><div><div class="tt">{{ ___('Order confirmed') }}</div><div class="td">{{ ___('Just now') }}</div></div></div>
                        <div class="ms-tstep"><span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5z"/><path d="M3 8v8l9 5 9-5V8"/></svg></span><div><div class="tt">{{ ___('Packed') }}</div><div class="td">{{ ___('Estimated soon') }}</div></div></div>
                        <div class="ms-tstep"><span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg></span><div><div class="tt">{{ ___('Shipped') }}</div><div class="td">&mdash;</div></div></div>
                        <div class="ms-tstep"><span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-9"/></svg></span><div><div class="tt">{{ ___('Delivered') }}</div><div class="td">&mdash;</div></div></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ms-sheet__foot" id="msCartFoot">
            <button type="button" class="ms-btn ms-btn--dark ms-btn--block" id="msCheckoutBtn" disabled>{{ ___('Checkout') }}</button>
        </div>
        <div class="ms-sheet__foot" id="msConfirmFoot" hidden>
            <div class="ms-actionbar">
                <button type="button" class="ms-btn ms-btn--ghost" id="msContinueShopping">{{ ___('Continue shopping') }}</button>
                <a class="ms-btn ms-btn--dark" id="msTrackOrderLink" href="#" style="text-decoration:none">{{ ___('Track order') }}</a>
            </div>
        </div>
    </aside>

    <div class="ms-toast" id="msToast"></div>

    {{-- Real notification centre — header bell forwards to a hidden proxy the
         shared engine binds (proxy pattern), panel outside the screens. --}}
    <button type="button" id="mbNotifBell" class="ms-nb-proxy" aria-hidden="true" tabindex="-1"></button>
    <i id="mbNotifDot" hidden></i>
    @include('store_templates._partials.notif-bell')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var proxy = document.getElementById('mbNotifBell');
            var srcDot = document.getElementById('mbNotifDot');
            var bell = document.getElementById('msNotifBell');
            if (bell) { bell.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (proxy && window.mbNotify) { proxy.click(); } }); }
            if (srcDot) {
                var mirror = function () { var d = bell && bell.querySelector('.ms-notif-dot'); if (d) { d.hidden = srcDot.hidden; } };
                new MutationObserver(mirror).observe(srcDot, { attributes: true, attributeFilter: ['hidden'] });
                mirror();
            }
            var shareBtn = document.getElementById('msAccShare');
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
        .mba-sheet{--mba-surface:var(--ms-card);--mba-ink:var(--ms-ink);--mba-muted:var(--ms-muted);
            --mba-border:var(--ms-hair);--mba-hair:var(--ms-hair);--mba-in-bg:var(--ms-card);
            --mba-accent:var(--ms-camel);--mba-on-accent:#fff;--mba-accent-ring:var(--ms-hair);--mba-radius:14px}
    </style>
    <script nonce="{{ csp_nonce() }}">
    (function () {
        ['msRewardsSignin', 'msOrdersGuest'].forEach(function (id) {
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
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
    /* Address map picker + clear-data (shared engines, maison-skinned) */
    .ms-maplink{display:inline-flex;align-items:center;gap:7px;margin-block-start:10px;padding:0;border:0;background:none;
        font:inherit;font-size:.82rem;letter-spacing:.01em;color:var(--ms-camel);cursor:pointer}
    .ms-maplink svg{width:16px;height:16px}
    .ms-maplink:hover{text-decoration:underline;text-underline-offset:3px}
    .ms-mapsheet .ms-sheet__body{display:flex;flex-direction:column;padding:0;overflow:hidden}
    .ms-map-canvas{position:relative;flex:1;min-height:340px;background:var(--ms-canvas)}
    .ms-map-canvas .leaflet-container{width:100%;height:100%;font:inherit;background:var(--ms-canvas)}
    .ms-map-preview{margin:0;padding:14px 20px;font-size:.82rem;line-height:1.45;color:var(--ms-muted);
        border-block-start:1px solid var(--ms-hair);text-align:center;min-height:20px}
    .mb-map-pin{position:absolute;left:50%;top:50%;width:28px;height:36px;transform:translate(-50%,-100%);
        z-index:500;pointer-events:none;filter:drop-shadow(0 4px 6px rgba(40,32,24,.4))}
    .mb-map-pin::before{content:"";position:absolute;left:50%;top:0;transform:translateX(-50%) rotate(45deg);
        width:24px;height:24px;border-radius:50% 50% 50% 0;background:var(--ms-camel);border:2px solid #fff}
    .mb-map-pin::after{content:"";position:absolute;left:50%;top:7px;transform:translateX(-50%);width:8px;height:8px;border-radius:50%;background:#fff}
    .ms-accrow--danger .at,.ms-accrow--danger .aico{color:var(--ms-sale)}
</style>
@endpush

@push('script')
{{-- Device-local guest order history helper (window.mbGuestOrders). Included
     once, before the main script that reads it. --}}
@include('store_templates._partials.guest-orders')
{{-- Delivery-address autocomplete engine (window.mbAddrAutocomplete). --}}
@include('store_templates._partials.address-autocomplete')
{{-- Shared map picker + clear-device-data engines (maison is a custom layout, so
     it includes these itself; theme supplies its own styled UI below). --}}
@include('store_templates._partials.map-picker')
@include('store_templates._partials.clear-device-data')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var ITEMS = @json($jsItems);
    var MODES = @json($modes);
    var SLOT_ROUTE = @json(route('store.scheduleSlots', $store->id));
    var CHECKOUT_ROUTE = @json($checkoutRoute);
    var COUPON_ROUTE = @json(\Illuminate\Support\Facades\Route::has('store.applyCoupon') ? route('store.applyCoupon', $store->id) : null);
    var MY_ORDERS_ROUTE = @json(\Illuminate\Support\Facades\Route::has('publicView.myOrders.index') ? route('publicView.myOrders.index', $store->id) : null);
    var CART_LINE_ROUTE_TEMPLATE = @json($cartLineRouteTemplate);
    var ORDER_TRACK_TEMPLATE = @json($orderTrackRouteTemplate);
    var STYLIST_ROUTE = @json($stylistRoute);
    var VISUAL_SEARCH_ROUTE = @json($visualSearchRoute);
    var STORIES_ROUTE = @json($storiesRoute);
    var REVIEWS_BASE = @json($reviewsBase);
    var LOYALTY_BALANCE_ROUTE = @json($loyaltyBalanceRoute);
    var LOY_TEASER = @json($loyaltyTeaser);
    function reviewsListUrl(menuId) { return REVIEWS_BASE + '/' + menuId + '/reviews?filter=latest'; }
    function reviewsPostUrl(menuId) { return REVIEWS_BASE + '/' + menuId + '/reviews'; }
    function cartLineRoute(lineId) { return CART_LINE_ROUTE_TEMPLATE.replace('999999999', String(lineId)); }
    function trackRoute(token) { return ORDER_TRACK_TEMPLATE.replace('MS_TRACK_TOKEN', encodeURIComponent(token)); }
    var CSRF = @json(csrf_token());
    var CUR = {
        sign: @json($curSign),
        gap: @json($curGap),
        pos: @json($curPos),
        decimals: @json($curDecimals),
    };
    var TAX = { charge: @json($taxCharge), type: @json($taxType) };
    function computeTax(subtotal) {
        if (TAX.charge <= 0 || subtotal <= 0) { return 0; }
        var raw = TAX.type === 'fixed' ? TAX.charge : subtotal * (TAX.charge / 100);
        var f = Math.pow(10, CUR.decimals);
        return Math.round(raw * f) / f;
    }
    var VOCAB_ADD = @json($vocab['add_to_cart'] ?? ___('Add to cart'));
    var T = {
        outOfStock: @json(___('Out of stock')),
        stockLeft: @json(___(':count left')),
        inStock: @json(___('In stock')),
        chooseColor: @json(___('Please choose a color.')),
        chooseSize: @json(___('Please choose a size.')),
        cartEmpty: @json(___('Your bag is empty')),
        placing: @json(___('Placing order…')),
        placed: @json(___('Order placed! Thank you.')),
        genericError: @json(___('Something went wrong. Please try again.')),
        nameRequired: @json(___('Please enter your name.')),
        addressRequired: @json(___('Please enter a delivery address.')),
        removeFav: @json(___('Remove from favorites')),
        addFav: @json(___('Add to favorites')),
        added: @json(___('Added to bag')),
        removed: @json(___('Removed')),
        saved: @json(___('Saved to wishlist')),
        remove: @json(___('Remove')),
        emptyWish: @json(___('No favourites yet')),
        emptyWishDesc: @json(___('Tap the heart on any piece to save it.')),
        aiThinking: @json(___('AI is thinking')),
        aiPicksTitle: @json(___('AI picks for you')),
        aiCurated: @json(___('Here are some picks curated from our collection:')),
        aiFallback: @json(___("Our AI stylist isn't connected yet — here are some picks from our collection in the meantime:")),
        aiNoMatch: @json(___("I couldn't find a match for that yet — here's what's new instead:")),
        vsScanning: @json(___('Scanning…')),
        vsFound: @json(___('Found a match')),
        vsFallback: @json(___("Visual search isn't connected yet — here's something from our new arrivals.")),
        trackNeedsValue: @json(___('Enter an order code or tracking link.')),
        viewStory: @json(___('View story')),
        reviewCountOne: @json(___('1 review')),
        reviewCount: @json(___(':count reviews')),
        reviewStarsRequired: @json(___('Please choose a star rating.')),
        reviewThanks: @json(___('Thanks! Your review will appear after a quick check.')),
        anonymousReviewer: @json(___('Anonymous')),
        loyApply: @json(___('Apply :discount')),
        loyDefaultProgram: @json(___('Rewards')),
        loySigninHint: @json(___('Sign in to earn & redeem points')),
        loyBalancePts: @json(___(':count pts')),
    };

    var root = document.getElementById('maison');
    if (!root) { return; }
    var storeId = root.getAttribute('data-store-id') || '';

    function formatMoney(n) {
        var amount = Number(n) || 0;
        var str;
        try {
            str = amount.toLocaleString(document.documentElement.lang || undefined, {
                minimumFractionDigits: CUR.decimals, maximumFractionDigits: CUR.decimals,
            });
        } catch (e) { str = amount.toFixed(CUR.decimals); }
        return '⁦' + (CUR.pos === '1' ? (CUR.sign + CUR.gap + str) : (str + CUR.gap + CUR.sign)) + '⁩';
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ---------------- dark mode (shared header + account row) ---------------- */
    var darkKey = 'maison-theme-' + storeId;
    function isDark() {
        var explicit = document.documentElement.getAttribute('data-theme');
        if (explicit) { return explicit === 'dark'; }
        try { return matchMedia('(prefers-color-scheme:dark)').matches; } catch (e) { return false; }
    }
    function paintThemeControls() {
        var dark = isDark();
        var icon = document.getElementById('msThemeIcon');
        if (icon) {
            icon.innerHTML = dark
                ? '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>'
                : '<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/>';
        }
        var accLabel = document.getElementById('msAccThemeLabel');
        if (accLabel) { accLabel.textContent = dark ? @json(___('Light mode')) : @json(___('Dark mode')); }
    }
    function setTheme(next) {
        if (next) { document.documentElement.setAttribute('data-theme', next); }
        else { document.documentElement.removeAttribute('data-theme'); }
        try { window.localStorage.setItem(darkKey, next || ''); } catch (e) { /* best-effort */ }
        paintThemeControls();
    }
    try {
        var savedTheme = window.localStorage.getItem(darkKey);
        if (savedTheme) { document.documentElement.setAttribute('data-theme', savedTheme); }
    } catch (e) { /* storage may be unavailable — default (system) stands */ }
    function toggleTheme() { setTheme(isDark() ? 'light' : 'dark'); }
    var themeBtn = document.getElementById('msThemeToggle');
    if (themeBtn) { themeBtn.addEventListener('click', toggleTheme); }
    var accThemeBtn = document.getElementById('msAccThemeToggle');
    if (accThemeBtn) { accThemeBtn.addEventListener('click', toggleTheme); }
    paintThemeControls();

    /* ---------------- client-side card template (Wishlist / AI picks / visual
       search match) — mirrors partials/_card.blade.php's markup 1:1 so the
       same CSS applies uniformly across every SSR'd and JS-rendered card. --}} */
    function cardHTML(item) {
        var media = item.img
            ? '<img class="ms-card__img" src="' + esc(item.img) + '" alt="" loading="lazy" data-csp-error="hide">'
            : '<span class="ms-card__img ms-card__img--ph" role="img" aria-label="' + esc(item.name) + '">'
                + '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M8 3l4 3 4-3 3 3-2 3v12H5V9L3 6z"/></svg></span>';
        var pill = item.discountPct
            ? '<span class="ms-pill ms-pill--sale">&minus;' + item.discountPct + '%</span>'
            : (item.soldOut ? '<span class="ms-pill ms-pill--soldout">' + esc(@json(___('Sold out'))) + '</span>' : '');
        var was = item.compareAtFormatted ? '<span class="ms-price ms-price--was">' + esc(item.compareAtFormatted) + '</span>' : '';
        var brand = item.brand ? '<span class="ms-card__brand">' + esc(item.brand) + '</span>' : '';
        return '<article class="ms-card" data-id="' + item.id + '" data-root="' + item.root + '"'
            + ' data-brand="' + esc((item.brand || '').toLowerCase()) + '" data-name="' + esc(item.name.toLowerCase()) + '"'
            + ' data-desc="' + esc((item.desc || '').toLowerCase()) + '" data-price="' + item.price + '" data-created="' + item.createdAt + '">'
            + '<div class="ms-card__media">' + pill
            + '<button type="button" class="ms-heart" data-fav-toggle="' + item.id + '" aria-pressed="false" aria-label="' + esc(T.addFav) + '">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg></button>'
            + '<a href="?item=' + item.id + '" class="ms-card__link" data-open-item-link="' + item.id + '" aria-label="' + esc(item.name) + '">'
            + media + '<span class="ms-card__scrim" aria-hidden="true"></span>'
            + '<span class="ms-card__meta">' + brand + '<span class="ms-card__name">' + esc(item.name) + '</span>'
            + '<span class="ms-card__row"><span class="ms-price">' + esc(item.priceFormatted) + '</span>' + was + '</span></span></a>'
            + '<button type="button" class="ms-bagbtn" data-open-item="' + item.id + '"' + (item.soldOut ? ' disabled' : '') + ' aria-label="' + esc(VOCAB_ADD) + '">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg></button>'
            + '</div></article>';
    }

    /* ---------------- screen navigation ---------------- */
    var TABS = ['home', 'shop', 'wishlist', 'account'];
    var stack = ['home'];
    function currentScreen() { return stack[stack.length - 1]; }
    function paintNav(screen) {
        document.querySelectorAll('.ms-tab[data-nav]').forEach(function (b) {
            var on = b.getAttribute('data-nav') === screen;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-current', on ? 'true' : 'false');
        });
        document.querySelectorAll('.ms-navlink[data-nav]').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-nav') === screen);
        });
    }
    function showScreen(screen) {
        document.querySelectorAll('.ms-screen').forEach(function (s) {
            s.hidden = s.getAttribute('data-screen') !== screen;
        });
        paintNav(screen);
        var mainEl = document.getElementById('ms-main');
        if (mainEl) { mainEl.scrollTo({ top: 0, behavior: 'auto' }); }
    }
    function go(screen) {
        if (!screen || !document.querySelector('.ms-screen[data-screen="' + screen + '"]')) { return; }
        if (currentScreen() !== screen) { stack.push(screen); }
        showScreen(screen);
        // Remember the active screen for THIS tab so a same-tab reload (a
        // language switch is a full navigation) lands back here, not on home.
        try { window.sessionStorage.setItem('mb-screen-' + storeId, screen); } catch (e) {}
        if (screen === 'wishlist') { renderWishlistScreen(); }
        if (screen === 'account') { loadRewardsBalance(); renderOrders(); }
    }
    var msOrdersLoaded = false;
    var MS_ORDER_LABELS = { unpaid: @json(___('Awaiting payment')), pending: @json(___('Received')), new: @json(___('Received')), preparing: @json(___('Preparing')), ready: @json(___('Ready')), out_for_delivery: @json(___('On the way')), delivered: @json(___('Delivered')), completed: @json(___('Completed')), cancelled: @json(___('Cancelled')) };
    function msOrderLabel(s) { return MS_ORDER_LABELS[s] || (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g, ' ') : ''); }
    function msEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    // Device-local guest history: render THIS device's recent orders for a guest
    // (no account) in maison's order-row style, each with a Track link and a
    // Reorder button when the items still exist, then a sign-in note to sync.
    // Returns the number of rows rendered so the caller can fall back to the
    // sign-in prompt when there is nothing on this device.
    function renderGuestLocalOrders(list) {
        var local = (window.mbGuestOrders ? window.mbGuestOrders.list(storeId) : []);
        if (!local.length) { list.innerHTML = ''; return 0; }
        list.innerHTML = local.map(function (o) {
            var d = o.at ? new Date(o.at) : null;
            var when = d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
            var canReorder = (o.itemIds || []).some(function (id) { return ITEMS[String(id)]; });
            var ref = o.token ? '#' + String(o.token).toUpperCase() : @json(___('Recent order'));
            return '<div class="ms-order"><div class="ms-order-top"><b>' + msEsc(ref) + '</b></div>'
                + '<div class="ms-order-meta">' + msEsc(when) + (o.itemCount ? ' · ' + o.itemCount : '') + (o.total ? ' · ' + msEsc(o.total) : '') + '</div>'
                + '<div class="ms-order-actions">'
                + (o.trackUrl ? '<a class="ms-order-btn" href="' + msEsc(o.trackUrl) + '">' + @json(___('Track')) + '</a>' : '')
                + (canReorder ? '<button type="button" class="ms-order-btn ms-order-btn--p" data-reorder="' + msEsc((o.itemIds || []).join(',')) + '">' + @json(___('Reorder')) + '</button>' : '')
                + '</div>'
                + '</div>';
        }).join('')
            + '<div class="ms-order ms-order--signin"><div class="ms-order-meta">' + msEsc(@json(___('Sign in to sync your orders across devices.'))) + '</div>'
            + '<button type="button" class="ms-order-btn ms-order-btn--p ms-order-btn--block" data-mb-auth-open>' + msEsc(@json(___('Sign in'))) + '</button></div>';
        return local.length;
    }
    // Reorder from a device-local guest row: re-add each item that still exists,
    // then open the bag. Bound via delegation since the rows are built at render.
    function msReorderLocal(ids) {
        var toAdd = (ids || []).filter(function (id) { var it = ITEMS[String(id)]; return it && !it.soldOut; });
        if (!toAdd.length) { return; }
        var chain = Promise.resolve();
        toAdd.forEach(function (id) {
            chain = chain.then(function () {
                return postAddToCart(id, null, 1).then(function (data) { addToCartLocal(id, null, 1, data.line_id); });
            });
        });
        chain.then(function () { toast(T.added); openCart(false); }).catch(function () { window.alert(T.genericError); });
    }
    (function () {
        var ol = document.getElementById('msOrdersList');
        if (!ol) { return; }
        ol.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('[data-reorder]');
            if (!b) { return; }
            var ids = (b.getAttribute('data-reorder') || '').split(',').filter(Boolean);
            msReorderLocal(ids);
        });
    })();
    function renderOrders() {
        var list = document.getElementById('msOrdersList');
        var empty = document.getElementById('msOrdersEmpty');
        var guest = document.getElementById('msOrdersGuest');
        if (!list) { return; }
        if (@json(! auth('customer')->check()) || !MY_ORDERS_ROUTE) {
            if (renderGuestLocalOrders(list)) { if (guest) { guest.hidden = true; } if (empty) { empty.hidden = true; } }
            else if (guest) { guest.hidden = false; }
            return;
        }
        if (msOrdersLoaded) { return; }
        fetch(MY_ORDERS_ROUTE, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (r.status === 401) { throw 'guest'; } return r.json(); })
            .then(function (res) {
                msOrdersLoaded = true;
                var orders = (res && res.orders) || [];
                if (!orders.length) { empty.hidden = false; return; }
                list.innerHTML = orders.map(function (o) {
                    var d = o.at ? new Date(o.at) : null;
                    var when = d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                    return '<div class="ms-order"><div class="ms-order-top"><b>' + msEsc(o.number) + '</b><span class="ms-order-status">' + msEsc(msOrderLabel(o.status)) + '</span></div>'
                        + '<div class="ms-order-meta">' + msEsc(when) + ' · ' + o.itemCount + ' · ' + msEsc(o.total) + '</div>'
                        + (o.trackUrl ? '<a class="ms-order-track" href="' + msEsc(o.trackUrl) + '">' + @json(___('Track')) + '</a>' : '')
                        + (o.canRequestRefund ? '<button type="button" class="ms-order-track" data-refund-request="' + msEsc(o.id) + '">' + @json(___('Request refund')) + '</button>' : '')
                        + (o.refundStatus ? '<span class="mb-refund mb-refund--' + msEsc(o.refundStatus) + '">' + mbRefundLabel(o.refundStatus, o.refundMethod) + '</span>' : '') + '</div>';
                }).join('');
            })
            .catch(function (e) { if (e === 'guest') { guest.hidden = false; } });
    }
    function back() {
        stack.pop();
        showScreen(stack[stack.length - 1] || 'home');
    }
    showScreen('home');
    loadStories();

    document.querySelectorAll('[data-nav]').forEach(function (b) {
        b.addEventListener('click', function () { go(b.getAttribute('data-nav')); });
    });
    // Promo-banner CTA: a banner with no real link_url would otherwise be a
    // dead href="#". Intercept the tap, close any open story viewer, and
    // deep-link to the catalog screen via the SPA screen-switch.
    document.querySelectorAll('.ms-banner[data-banner-screen]').forEach(function (b) {
        b.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof closeStoryViewer === 'function') { closeStoryViewer(); }
            go(b.getAttribute('data-banner-screen'));
        });
    });
    document.querySelectorAll('[data-back]').forEach(function (b) {
        b.addEventListener('click', back);
    });
    var goHomeBtn = document.getElementById('msGoHome');
    if (goHomeBtn) { goHomeBtn.addEventListener('click', function () { go('home'); }); }
    var searchOpenBtn = document.getElementById('msSearchOpen');
    if (searchOpenBtn) {
        searchOpenBtn.addEventListener('click', function () {
            go('stylist');
            var input = document.getElementById('msAiInput');
            if (input) { setTimeout(function () { input.focus(); }, 60); }
        });
    }

    /* Home per-category chips + Category screen rows both jump into Shop
       pre-filtered to that category; the "New in" chip is Home's own default
       state and never navigates. */
    function openShop(catId) {
        go('shop');
        var chip = document.querySelector('#msShopChips [data-shop-cat="' + catId + '"]');
        if (chip) { chip.click(); }
    }
    document.querySelectorAll('[data-home-chip-cat]').forEach(function (b) {
        b.addEventListener('click', function () { openShop(b.getAttribute('data-home-chip-cat')); });
    });
    document.querySelectorAll('[data-nav-shop-cat]').forEach(function (b) {
        b.addEventListener('click', function () { openShop(b.getAttribute('data-nav-shop-cat')); });
    });
    var homeAllChip = document.querySelector('[data-home-chip="all"]');
    if (homeAllChip) { homeAllChip.addEventListener('click', function () { homeAllChip.setAttribute('aria-pressed', 'true'); }); }

    /* ---------------- Shop: category chip + search + brand + price + sale +
       sort (filters/sort/results screen) ---------------- */
    var shopGrid = document.getElementById('msShopGrid');
    var shopCards = shopGrid ? Array.prototype.slice.call(shopGrid.querySelectorAll('.ms-card')) : [];
    var shopNoResults = document.getElementById('msShopNoResults');
    var shopResults = document.getElementById('msResults');
    var shopActiveCat = 'all';
    var shopQuery = '';
    var shopBrand = '';
    var shopMaxPrice = null;
    var shopSaleOnly = false;

    function applyShopFilter() {
        var visible = 0;
        shopCards.forEach(function (card) {
            var matchesCat = shopActiveCat === 'all' || card.getAttribute('data-root') === shopActiveCat;
            var matchesBrand = !shopBrand || card.getAttribute('data-brand') === shopBrand;
            var matchesQuery = !shopQuery
                || card.getAttribute('data-name').indexOf(shopQuery) !== -1
                || card.getAttribute('data-desc').indexOf(shopQuery) !== -1;
            var matchesPrice = shopMaxPrice === null || parseFloat(card.getAttribute('data-price')) <= shopMaxPrice;
            var matchesSale = !shopSaleOnly || !!ITEMS[card.getAttribute('data-id')].discountPct;
            var show = matchesCat && matchesBrand && matchesQuery && matchesPrice && matchesSale;
            card.hidden = !show;
            if (show) { visible++; }
        });
        if (shopNoResults) { shopNoResults.hidden = visible !== 0; }
        if (shopResults) { shopResults.textContent = visible + ' ' + (visible === 1 ? @json(___('result')) : @json(___('results'))); }
    }
    function applyShopSort() {
        var sortSel = document.getElementById('msSort');
        var by = sortSel ? sortSel.value : 'newest';
        var sorted = shopCards.slice().sort(function (a, b) {
            if (by === 'price_asc') { return parseFloat(a.getAttribute('data-price')) - parseFloat(b.getAttribute('data-price')); }
            if (by === 'price_desc') { return parseFloat(b.getAttribute('data-price')) - parseFloat(a.getAttribute('data-price')); }
            if (by === 'name_asc') { return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name')); }
            return parseInt(b.getAttribute('data-created'), 10) - parseInt(a.getAttribute('data-created'), 10);
        });
        sorted.forEach(function (card) { shopGrid.appendChild(card); });
    }

    document.querySelectorAll('#msShopChips .ms-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            document.querySelectorAll('#msShopChips .ms-chip').forEach(function (c) { c.setAttribute('aria-pressed', 'false'); });
            chip.setAttribute('aria-pressed', 'true');
            shopActiveCat = chip.getAttribute('data-shop-cat');
            applyShopFilter();
        });
    });
    var shopSearchInput = document.getElementById('msShopSearch');
    if (shopSearchInput) {
        shopSearchInput.addEventListener('input', function () {
            shopQuery = shopSearchInput.value.trim().toLowerCase();
            applyShopFilter();
        });
    }
    var shopSortSel = document.getElementById('msSort');
    if (shopSortSel) { shopSortSel.addEventListener('change', applyShopSort); }
    var filterToggle = document.getElementById('msFilterToggle');
    var filterPanel = document.getElementById('msFilterPanel');
    if (filterToggle && filterPanel) {
        filterToggle.addEventListener('click', function () {
            var open = filterPanel.hidden;
            filterPanel.hidden = !open;
            filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
    var brandFilterSel = document.getElementById('msBrandFilter');
    if (brandFilterSel) { brandFilterSel.addEventListener('change', function () { shopBrand = brandFilterSel.value; applyShopFilter(); }); }
    var priceRange = document.getElementById('msPriceRange');
    var priceMaxLabel = document.getElementById('msPriceMaxLabel');
    if (priceRange) {
        shopMaxPrice = parseFloat(priceRange.value);
        priceRange.addEventListener('input', function () {
            shopMaxPrice = parseFloat(priceRange.value);
            if (priceMaxLabel) { priceMaxLabel.textContent = formatMoney(shopMaxPrice); }
            applyShopFilter();
        });
    }
    var saleOnlyBox = document.getElementById('msSaleOnly');
    if (saleOnlyBox) { saleOnlyBox.addEventListener('change', function () { shopSaleOnly = saleOnlyBox.checked; applyShopFilter(); }); }
    var filterClearBtn = document.getElementById('msFilterClear');
    if (filterClearBtn) {
        filterClearBtn.addEventListener('click', function () {
            if (brandFilterSel) { brandFilterSel.value = ''; shopBrand = ''; }
            if (priceRange) { priceRange.value = priceRange.max; shopMaxPrice = parseFloat(priceRange.max); if (priceMaxLabel) { priceMaxLabel.textContent = formatMoney(shopMaxPrice); } }
            if (saleOnlyBox) { saleOnlyBox.checked = false; shopSaleOnly = false; }
            applyShopFilter();
        });
    }
    applyShopFilter();
    applyShopSort();

    /* ---------------- favorites / wishlist (localStorage) ---------------- */
    var FAV_KEY = 'maison-favs-' + storeId;
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
    function wireFavButtons(scope) {
        (scope || document).querySelectorAll('[data-fav-toggle]').forEach(function (btn) {
            if (btn.dataset.favWired) { return; }
            btn.dataset.favWired = '1';
            var id = btn.getAttribute('data-fav-toggle');
            paintFavButton(btn, !!favIds[id]);
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var isFav = !favIds[id];
                if (isFav) { favIds[id] = true; toast(T.saved, 'heart'); } else { delete favIds[id]; toast(T.removed, 'heart'); }
                paintFavButton(btn, isFav);
                btn.classList.add('ms-pop');
                setTimeout(function () { btn.classList.remove('ms-pop'); }, 400);
                saveFavs();
                if (currentScreen() === 'wishlist') { renderWishlistScreen(); }
            });
        });
    }
    wireFavButtons(document);

    function renderWishlistScreen() {
        var host = document.getElementById('msWishlistBody');
        if (!host) { return; }
        var ids = Object.keys(favIds).filter(function (id) { return ITEMS[id]; });
        if (!ids.length) {
            host.innerHTML = '<div class="ms-empty"><span class="ms-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg></span>'
                + '<h3>' + esc(T.emptyWish) + '</h3><p>' + esc(T.emptyWishDesc) + '</p></div>';
            return;
        }
        host.innerHTML = '<div class="ms-grid">' + ids.map(function (id) { return cardHTML(ITEMS[id]); }).join('') + '</div>';
        wireFavButtons(host);
    }

    /* Every card — SSR'd (Home/Shop) or JS-rendered (Wishlist/AI picks/visual
       search match) — shares the same data-open-item / data-open-item-link
       attributes, so ONE delegated listener on the root handles all of them,
       present and future, with no per-render re-wiring. */
    root.addEventListener('click', function (e) {
        var openLink = e.target.closest('[data-open-item-link]');
        if (openLink) { e.preventDefault(); openItem(parseInt(openLink.getAttribute('data-open-item-link'), 10)); return; }
        var openBtn = e.target.closest('[data-open-item]');
        if (openBtn && !openBtn.disabled) {
            var id = parseInt(openBtn.getAttribute('data-open-item'), 10);
            var item = ITEMS[id];
            if (item && item.colors.length === 0 && item.sizes.length === 0) { quickAdd(id, openBtn); }
            else { openItem(id); }
        }
    });

    /* ---------------- a11y sheet open/close (Escape / scrim / focus trap+restore,
       one-at-a-time, back-button-closes-it) — same pattern as
       store_templates/shelf/index.blade.php's sheets. ---------------- */
    var FOCUSABLE_SEL = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
    var a11yOpenEntry = null;
    var a11yNavGuard = false;
    var a11yPopping = false;
    function openA11ySheet(sheetEl, scrimEl, focusEl, url) {
        var trigger = document.activeElement;
        a11yOpenEntry = { el: sheetEl, scrim: scrimEl, trigger: trigger };
        sheetEl.setAttribute('aria-hidden', 'false');
        sheetEl.removeAttribute('inert');
        scrimEl.classList.add('is-open');
        sheetEl.classList.add('is-open');
        if (!a11yNavGuard) {
            a11yNavGuard = true;
            try { history.pushState({ msSheet: 1 }, '', url || undefined); } catch (e) { /* best-effort */ }
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

    /* ---------------- toast ---------------- */
    var toastTimer;
    function toast(msg) {
        var el = document.getElementById('msToast');
        if (!el) { return; }
        el.textContent = msg;
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('show'); }, 1700);
    }

    /* ---------------- client-side bag preview + real server-side cart
       (Lane V integration fix 1 contract — same as shelf's addToCart /
       postAddToCart / renderCart). ---------------- */
    var CART_KEY = 'maison-cart-' + storeId;
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
    function lineUnitPrice(line) {
        var item = ITEMS[line.id];
        if (!item) { return 0; }
        if (line.variantId) {
            var v = item.variants.filter(function (x) { return x.id === line.variantId; })[0];
            if (v) { return v.price; }
        }
        return item.price;
    }
    function cartCount() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }
    function renderCartBadges() {
        var n = cartCount();
        [document.getElementById('msCartBadge'), document.querySelector('.ms-tab-fab .ms-badge')].forEach(function (b) {
            if (!b) { return; }
            b.textContent = String(n);
            b.hidden = n === 0;
        });
        var fab = document.querySelector('.ms-tab-fab');
        if (fab && n && !fab.querySelector('.ms-badge')) {
            var badge = document.createElement('i');
            badge.className = 'ms-badge';
            badge.textContent = String(n);
            fab.appendChild(badge);
        }
    }
    function renderCart() {
        var linesEl = document.getElementById('msCartLines');
        var emptyEl = document.getElementById('msCartEmpty');
        var summaryEl = document.getElementById('msCartSummary');
        var totalEl = document.getElementById('msCartTotal');
        var checkoutBtn = document.getElementById('msCheckoutBtn');
        if (!linesEl) { return; }
        linesEl.innerHTML = '';
        renderCartBadges();
        if (!cart.length) {
            emptyEl.hidden = false;
            summaryEl.hidden = true;
            if (checkoutBtn) { checkoutBtn.disabled = true; }
            return;
        }
        emptyEl.hidden = true;
        summaryEl.hidden = false;
        if (checkoutBtn) { checkoutBtn.disabled = false; }
        var sum = 0;
        cart.forEach(function (line, idx) {
            var item = ITEMS[line.id];
            if (!item) { return; }
            var unit = lineUnitPrice(line);
            var lineTotal = unit * line.qty;
            sum += lineTotal;
            var optBits = [];
            if (line.color) { optBits.push(line.color); }
            if (line.size) { optBits.push(line.size); }
            var row = document.createElement('div');
            row.className = 'ms-cline';
            var img = document.createElement('img');
            img.alt = '';
            img.setAttribute('data-csp-error', 'hide');
            if (item.img) { img.src = item.img; } else { img.hidden = true; }
            var body = document.createElement('div');
            body.className = 'ms-cline__body';
            var nameEl = document.createElement('div');
            nameEl.className = 'ms-cline__name';
            nameEl.textContent = item.name + ' × ' + line.qty;
            var optEl = document.createElement('div');
            optEl.className = 'ms-cline__opt';
            optEl.textContent = optBits.join(' · ');
            optEl.hidden = !optBits.length;
            var priceEl = document.createElement('div');
            priceEl.className = 'ms-cline__price';
            priceEl.textContent = formatMoney(lineTotal);
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'ms-cline__remove';
            rm.textContent = T.remove;
            rm.addEventListener('click', function () {
                var removedLineId = line.lineId;
                cart.splice(idx, 1);
                saveCart();
                renderCart();
                if (removedLineId) {
                    var fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('quantity', '0');
                    fetch(cartLineRoute(removedLineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                        .catch(function () { /* best-effort */ });
                }
            });
            body.appendChild(nameEl);
            if (optBits.length) { body.appendChild(optEl); }
            body.appendChild(priceEl);
            body.appendChild(rm);
            row.appendChild(img);
            row.appendChild(body);
            linesEl.appendChild(row);
        });
        var tax = computeTax(sum);
        var subEl = document.getElementById('msCartSubtotal');
        if (subEl) { subEl.textContent = formatMoney(sum); }
        var taxRow = document.getElementById('msCartTaxRow');
        if (taxRow) { taxRow.hidden = tax <= 0; }
        var taxEl = document.getElementById('msCartTax');
        if (taxEl) { taxEl.textContent = formatMoney(tax); }
        totalEl.textContent = formatMoney(sum + tax);
    }
    function addToCartLocal(itemId, variantId, qty, lineId, color, size) {
        var existing = cart.filter(function (l) { return l.id === itemId && l.variantId === (variantId || null); })[0];
        if (existing) {
            existing.qty += qty;
            if (lineId) { existing.lineId = lineId; }
        } else {
            cart.push({ id: itemId, variantId: variantId || null, qty: qty, lineId: lineId || null, color: color || null, size: size || null });
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
    function quickAdd(id, btn) {
        var item = ITEMS[id];
        if (!item || item.soldOut) { return; }
        if (btn) { btn.disabled = true; }
        postAddToCart(id, null, 1)
            .then(function (data) { addToCartLocal(id, null, 1, data.line_id); toast(T.added); })
            .catch(function () { window.alert(T.genericError); })
            .then(function () { if (btn) { btn.disabled = false; } });
    }
    renderCart();

    /* ---------------- product detail sheet: color swatches + size pills
       from MenuVariant.options axes ---------------- */
    var itemScrim = document.getElementById('msItemScrim');
    var itemSheet = document.getElementById('msItemSheet');
    var currentItem = null;
    var currentColor = null;
    var currentSize = null;
    var currentQty = 1;

    function variantFor(item, color, size) {
        var hasColor = item.colors.length > 0;
        var hasSize = item.sizes.length > 0;
        return item.variants.filter(function (v) {
            return (!hasColor || v.color === color) && (!hasSize || v.size === size);
        })[0] || null;
    }
    function sizeAvailable(item, color, size) {
        var v = variantFor(item, color, size);
        if (!v || !item.trackInventory || v.stockQty === null || v.stockQty === undefined) { return true; }
        return v.stockQty > 0;
    }
    function renderSwatches() {
        var group = document.getElementById('msColorGroup');
        var wrap = document.getElementById('msSwatches');
        wrap.innerHTML = '';
        if (!currentItem.colors.length) { group.hidden = true; return; }
        group.hidden = false;
        currentItem.colors.forEach(function (c) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ms-sw';
            btn.setAttribute('aria-pressed', c.label === currentColor ? 'true' : 'false');
            btn.setAttribute('aria-label', c.label);
            btn.title = c.label;
            var dot = document.createElement('span');
            dot.style.background = c.hex || 'var(--ms-hair-2)';
            btn.appendChild(dot);
            btn.addEventListener('click', function () {
                currentColor = c.label;
                renderSwatches();
                renderSizes();
                updateSelection();
            });
            wrap.appendChild(btn);
        });
    }
    function renderSizes() {
        var group = document.getElementById('msSizeGroup');
        var wrap = document.getElementById('msSizes');
        wrap.innerHTML = '';
        if (!currentItem.sizes.length) { group.hidden = true; return; }
        group.hidden = false;
        currentItem.sizes.forEach(function (sz) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ms-size';
            btn.textContent = sz;
            btn.setAttribute('aria-pressed', sz === currentSize ? 'true' : 'false');
            if (!sizeAvailable(currentItem, currentColor, sz)) { btn.disabled = true; }
            btn.addEventListener('click', function () {
                currentSize = sz;
                renderSizes();
                updateSelection();
            });
            wrap.appendChild(btn);
        });
    }
    function updateSelection() {
        var v = variantFor(currentItem, currentColor, currentSize);
        var price = v ? v.price : currentItem.price;
        document.getElementById('msItemPrice').textContent = formatMoney(price * currentQty);
        var stockQty = v ? v.stockQty : currentItem.stockQty;
        var stockBadge = document.getElementById('msItemStock');
        if (!currentItem.trackInventory || stockQty === null || stockQty === undefined) {
            stockBadge.hidden = true;
        } else {
            stockBadge.hidden = false;
            stockBadge.className = 'ms-pd-stock';
            if (stockQty <= 0) { stockBadge.classList.add('ms-pd-stock--out'); stockBadge.textContent = T.outOfStock; }
            else if (stockQty <= 5) { stockBadge.classList.add('ms-pd-stock--low'); stockBadge.textContent = T.stockLeft.replace(':count', String(stockQty)); }
            else { stockBadge.classList.add('ms-pd-stock--in'); stockBadge.textContent = T.inStock; }
        }
    }
    function renderAttrs() {
        var group = document.getElementById('msAttrsGroup');
        var table = document.getElementById('msAttrsTable');
        table.innerHTML = '';
        var rows = [];
        if (currentItem.brand) { rows.push([@json(___('Brand')), currentItem.brand]); }
        if (currentItem.sku) { rows.push([@json(___('SKU')), currentItem.sku]); }
        if (!rows.length) { group.hidden = true; return; }
        group.hidden = false;
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            var td1 = document.createElement('td'); td1.textContent = r[0];
            var td2 = document.createElement('td'); td2.textContent = r[1];
            tr.appendChild(td1); tr.appendChild(td2);
            table.appendChild(tr);
        });
    }
    // Product gallery thumbnail strip — swaps the main image on tap. Only
    // rendered when the item carries ≥2 images (primary + gallery_images).
    function renderThumbs(item) {
        var wrap = document.getElementById('msItemThumbs');
        var imgs = (item && item.gallery) ? item.gallery : [];
        wrap.innerHTML = '';
        if (imgs.length < 2) { wrap.hidden = true; return; }
        wrap.hidden = false;
        var mainImg = document.getElementById('msItemImg');
        imgs.forEach(function (src, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ms-pd-thumb';
            b.setAttribute('aria-current', i === 0 ? 'true' : 'false');
            var im = document.createElement('img');
            im.src = src; im.alt = ''; im.setAttribute('data-csp-error', 'hide');
            b.appendChild(im);
            b.addEventListener('click', function () {
                mainImg.src = src; mainImg.hidden = false;
                document.getElementById('msItemImgWrap').classList.remove('ms-pd-img--ph');
                Array.prototype.forEach.call(wrap.children, function (c) {
                    c.setAttribute('aria-current', c === b ? 'true' : 'false');
                });
            });
            wrap.appendChild(b);
        });
    }
    function openItem(id) {
        var item = ITEMS[id];
        if (!item) { return; }
        currentItem = item;
        currentColor = item.colors.length === 1 ? item.colors[0].label : null;
        currentSize = item.sizes.length === 1 ? item.sizes[0] : null;
        currentQty = 1;
        document.getElementById('msQtyVal').textContent = '1';
        document.getElementById('msItemName').textContent = item.name;
        document.getElementById('msItemTitle').textContent = item.name;
        var imgWrap = document.getElementById('msItemImgWrap');
        var img = document.getElementById('msItemImg');
        var firstImg = (item.gallery && item.gallery.length) ? item.gallery[0] : item.img;
        if (firstImg) {
            img.src = firstImg; img.alt = item.name; img.hidden = false;
            imgWrap.classList.remove('ms-pd-img--ph');
        } else {
            img.removeAttribute('src'); img.hidden = true;
            imgWrap.classList.add('ms-pd-img--ph');
        }
        renderThumbs(item);
        document.getElementById('msItemDesc').textContent = item.desc;
        var wasEl = document.getElementById('msItemWas');
        if (item.compareAtFormatted) { wasEl.textContent = item.compareAtFormatted; wasEl.hidden = false; }
        else { wasEl.hidden = true; }
        renderSwatches();
        renderSizes();
        renderAttrs();
        updateSelection();
        var addBtn = document.getElementById('msAddToCart');
        var buyBtn = document.getElementById('msBuyNow');
        addBtn.disabled = !!item.soldOut;
        buyBtn.disabled = !!item.soldOut;
        loadReviews(id);

        var itemUrl = window.location.pathname + '?item=' + encodeURIComponent(String(id));
        openA11ySheet(itemSheet, itemScrim, document.getElementById('msItemName'), itemUrl);
    }
    function closeItem() { dismissA11ySheet(); }
    document.getElementById('msItemClose').addEventListener('click', closeItem);
    itemScrim.addEventListener('click', closeItem);

    document.getElementById('msQtyMinus').addEventListener('click', function () {
        currentQty = Math.max(1, currentQty - 1);
        document.getElementById('msQtyVal').textContent = String(currentQty);
        updateSelection();
    });
    document.getElementById('msQtyPlus').addEventListener('click', function () {
        currentQty = currentQty + 1;
        document.getElementById('msQtyVal').textContent = String(currentQty);
        updateSelection();
    });

    function addCurrentToCart(thenOpenCheckout) {
        if (!currentItem) { return; }
        if (currentItem.colors.length && !currentColor) { window.alert(T.chooseColor); return; }
        if (currentItem.sizes.length && !currentSize) { window.alert(T.chooseSize); return; }
        var v = variantFor(currentItem, currentColor, currentSize);
        var item = currentItem, qty = currentQty;
        var addBtn = document.getElementById('msAddToCart');
        var buyBtn = document.getElementById('msBuyNow');
        addBtn.disabled = true; buyBtn.disabled = true;
        postAddToCart(item.id, v ? v.id : null, qty)
            .then(function (data) {
                addToCartLocal(item.id, v ? v.id : null, qty, data.line_id, currentColor, currentSize);
                toast(T.added);
                rawCloseA11ySheet();
                openCart(thenOpenCheckout);
            })
            .catch(function () { window.alert(T.genericError); })
            .then(function () { addBtn.disabled = !!item.soldOut; buyBtn.disabled = !!item.soldOut; });
    }
    document.getElementById('msAddToCart').addEventListener('click', function () { addCurrentToCart(false); });
    document.getElementById('msBuyNow').addEventListener('click', function () { addCurrentToCart(false); });

    try {
        var params = new URLSearchParams(window.location.search);
        var deepId = parseInt(params.get('item'), 10);
        if (deepId && ITEMS[deepId]) { openItem(deepId); }
    } catch (e) { /* unsupported/old browser — no-op */ }

    /* Restore the screen across a same-tab reload (a language switch is a full
       navigation, which otherwise dumps the shopper back on home and makes their
       order history look "gone"). sessionStorage is per-tab, so a fresh visit
       still opens on home. Skipped when a ?item deep-link drives the screen. */
    (function restoreScreen() {
        try {
            if (new URLSearchParams(window.location.search).get('item')) { return; }
            var saved = window.sessionStorage.getItem('mb-screen-' + storeId);
            if (saved && saved !== 'home' && document.querySelector('.ms-screen[data-screen="' + saved + '"]')) { go(saved); }
        } catch (e) { /* best-effort */ }
    })();

    /* ---------------- cart / checkout / confirm sheet ---------------- */
    var cartScrim = document.getElementById('msCartScrim');
    var cartSheet = document.getElementById('msCartSheet');
    var cartView = document.getElementById('msCartView');
    var checkoutForm = document.getElementById('msCheckoutForm');
    var confirmView = document.getElementById('msConfirmView');
    var cartFoot = document.getElementById('msCartFoot');
    var confirmFoot = document.getElementById('msConfirmFoot');

    function showCartStage(stage) {
        cartView.hidden = stage !== 'cart';
        checkoutForm.hidden = stage !== 'checkout';
        confirmView.hidden = stage !== 'confirm';
        cartFoot.hidden = stage === 'confirm';
        confirmFoot.hidden = stage !== 'confirm';
        var checkoutBtn = document.getElementById('msCheckoutBtn');
        if (checkoutBtn) { checkoutBtn.hidden = stage !== 'cart'; }
    }
    function openCart(goToCheckout) {
        renderCart();
        showCartStage(goToCheckout ? 'checkout' : 'cart');
        if (goToCheckout) { updateModeFields(); }
        openA11ySheet(cartSheet, cartScrim, document.getElementById('msCartTitle'));
    }
    function closeCart() { dismissA11ySheet(); }
    document.getElementById('msCartOpen').addEventListener('click', function () { openCart(false); });
    document.getElementById('msCartClose').addEventListener('click', closeCart);
    cartScrim.addEventListener('click', closeCart);

    function selectedMode() {
        var select = document.getElementById('msMode');
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
        var wrap = document.getElementById('msSlots');
        wrap.innerHTML = '';
        chosenSlot = null;
        fetch(SLOT_ROUTE + '?type=' + encodeURIComponent(selectedMode().value), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.enabled || !data.slots || !data.slots.length) { return; }
                data.slots.slice(0, 8).forEach(function (slot) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'ms-opt';
                    btn.textContent = slot.label;
                    btn.disabled = !!slot.full;
                    btn.addEventListener('click', function () {
                        chosenSlot = slot.value;
                        wrap.querySelectorAll('.ms-opt').forEach(function (b) { b.classList.remove('is-active'); });
                        btn.classList.add('is-active');
                    });
                    wrap.appendChild(btn);
                });
            })
            .catch(function () { /* best-effort — checkout still works without it */ });
    }
    // Wire the delivery-address autocomplete once (crave-style geocode suggest
    // + use-my-location). Safe to call before the field is shown — the input
    // lives in the DOM the whole time.
    if (window.mbAddrAutocomplete) {
        window.mbAddrAutocomplete(document.getElementById('msAddress'), {
            suggest: document.getElementById('msAddrSuggest'),
            locBtn: document.getElementById('msAddrLoc')
        });
    }
    /* Address map picker — maison-native .ms-sheet, opened via the theme's own
       focus-trapping openA11ySheet/dismissA11ySheet; map logic is the shared engine. */
    if (window.mbMapPicker) {
        window.mbMapPicker({
            trigger: document.getElementById('msMapBtn'),
            modal:   document.getElementById('msMapSheet'),
            map:     document.getElementById('msMap'),
            input:   document.getElementById('msAddress'),
            preview: document.getElementById('msMapPreview'),
            confirm: document.getElementById('msMapConfirm'),
            close:   document.getElementById('msMapClose'),
            toggle:  function (open) {
                if (open) { openA11ySheet(document.getElementById('msMapSheet'), document.getElementById('msMapScrim'), document.getElementById('msMapTitle')); }
                else { dismissA11ySheet(); }
            },
            i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
        });
    } else { var _mbMapBtn = document.getElementById('msMapBtn'); if (_mbMapBtn) { _mbMapBtn.style.display = 'none'; } }
    /* Clear saved data on this device (account screen row) */
    (function () {
        var cd = document.getElementById('msClearData');
        if (!cd) { return; }
        if (!window.mbClearDeviceData) { cd.style.display = 'none'; return; }
        cd.addEventListener('click', function () {
            window.mbClearDeviceData({
                keys: ['maison-cart-' + storeId, 'maison-favs-' + storeId, 'maison-stories-viewed-' + storeId],
                storeId: storeId,
                slug: @json($store->slug ?? ''),
                confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
            });
        });
    })();
    function updateModeFields() {
        var mode = selectedMode();
        var addrField = document.getElementById('msAddressField');
        var slotField = document.getElementById('msSlotField');
        if (addrField) { addrField.hidden = !mode.needsAddress; }
        if (slotField) { slotField.hidden = !mode.needsSlot; }
        if (mode.needsSlot) { loadSlots(); }
        var sub = cart.reduce(function (s, l) { return s + lineUnitPrice(l) * l.qty; }, 0);
        var disc = Math.min(msCouponDiscount, sub);
        var subEl = document.getElementById('msCheckoutSubtotal');
        var totalEl = document.getElementById('msCheckoutTotal');
        var dRow = document.getElementById('msCheckoutDiscountRow');
        var dEl = document.getElementById('msCheckoutDiscount');
        var tax = computeTax(sub);
        var taxRow = document.getElementById('msCheckoutTaxRow');
        var taxEl = document.getElementById('msCheckoutTax');
        if (subEl) { subEl.textContent = formatMoney(sub); }
        if (dRow && dEl) { if (disc > 0) { dRow.hidden = false; dEl.textContent = '-' + formatMoney(disc); } else { dRow.hidden = true; } }
        if (taxRow) { taxRow.hidden = tax <= 0; }
        if (taxEl) { taxEl.textContent = formatMoney(tax); }
        if (totalEl) { totalEl.textContent = formatMoney(Math.max(0, sub + tax - disc)); }
        loadLoyaltyForCheckout();
    }
    var msCouponDiscount = 0;
    /* Bug batch 7 · 4: the cart lines go to the coupon check (submitOrder's `items` shape) — a buy-X-get-Y coupon is
       priced on them, exactly as the placed order is. */
    function couponLines() {
        return cart.map(function (l) {
            return { id: l.id, quantity: l.qty, variants: l.variantId || 0, extras: (l.extras || []).map(function (x) { return { id: (x && x.id) || x }; }) };
        });
    }
    (function () {
        var applyBtn = document.getElementById('msCouponApply');
        if (!applyBtn || !COUPON_ROUTE) { return; }
        applyBtn.addEventListener('click', function () {
            var code = (document.getElementById('msCouponInput').value || '').trim();
            var msgEl = document.getElementById('msCouponMsg');
            var field = document.getElementById('msCouponField');
            if (!code) { return; }
            var sub = cart.reduce(function (s, l) { return s + lineUnitPrice(l) * l.qty; }, 0);
            var fd = new FormData();
            fd.append('_token', CSRF); fd.append('code', code); fd.append('subtotal', sub);
            fd.append('items', JSON.stringify(couponLines()));
            applyBtn.disabled = true;
            fetch(COUPON_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    applyBtn.disabled = false; msgEl.hidden = false;
                    if (d && d.success) { field.value = code; msCouponDiscount = parseFloat(d.discount) || 0; msgEl.textContent = d.message || @json(___('Coupon applied')); msgEl.classList.remove('is-bad'); }
                    else { field.value = ''; msCouponDiscount = 0; msgEl.textContent = (d && d.message) || @json(___('Coupon code is invalid.')); msgEl.classList.add('is-bad'); }
                    updateModeFields();
                })
                .catch(function () { applyBtn.disabled = false; });
        });
    })();
    var modeSelect = document.getElementById('msMode');
    if (modeSelect) { modeSelect.addEventListener('change', updateModeFields); }

    document.getElementById('msCheckoutBtn').addEventListener('click', function () {
        showCartStage('checkout');
        updateModeFields();
    });

    document.getElementById('msPlaceOrder').addEventListener('click', function () {
        var msg = document.getElementById('msCheckoutMsg');
        msg.innerHTML = '';
        if (!cart.length) { msg.innerHTML = '<div class="ms-msg ms-msg--error">' + esc(T.cartEmpty) + '</div>'; return; }
        var name = document.getElementById('msName').value.trim();
        var phone = document.getElementById('msPhone').value.trim();
        if (!name) { msg.innerHTML = '<div class="ms-msg ms-msg--error">' + esc(T.nameRequired) + '</div>'; return; }
        var mode = selectedMode();
        if (mode.needsAddress && !document.getElementById('msAddress').value.trim()) {
            msg.innerHTML = '<div class="ms-msg ms-msg--error">' + esc(T.addressRequired) + '</div>';
            return;
        }
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('customer_name', name);
        fd.append('phone_number', phone);
        fd.append('type', mode.value);
        if (mode.needsAddress) { fd.append('address', document.getElementById('msAddress').value.trim()); }
        if (mode.needsSlot && chosenSlot) { fd.append('scheduled_at', chosenSlot); }
        var loyaltyRedeemInput = document.getElementById('msLoyaltyRedeemInput');
        fd.append('loyalty_redeem', loyaltyRedeemInput ? loyaltyRedeemInput.value : '0');
        var msCouponField = document.getElementById('msCouponField');
        if (msCouponField && msCouponField.value) { fd.append('coupon_code', msCouponField.value); }
        var msGiftField = document.getElementById('msGiftCardInput');
        if (msGiftField && msGiftField.value.trim()) { fd.append('gift_card_code', msGiftField.value.trim()); }

        msg.innerHTML = '<div class="ms-msg">' + esc(T.placing) + '</div>';
        var placeBtn = document.getElementById('msPlaceOrder');
        placeBtn.disabled = true;
        fetch(CHECKOUT_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                placeBtn.disabled = false;
                if (data && data.success) {
                    msg.innerHTML = '';
                    // Device-local guest history: remember this order on THIS device
                    // so a guest (no account) still sees it on the Orders screen.
                    // Snapshot BEFORE the cart is cleared.
                    try {
                        if (data.track_token && window.mbGuestOrders) {
                            var _sub = cart.reduce(function (s, l) { return s + lineUnitPrice(l) * l.qty; }, 0);
                            window.mbGuestOrders.save(storeId, {
                                token: data.track_token,
                                trackUrl: data.redirect || null,
                                total: formatMoney(_sub + computeTax(_sub)),
                                itemCount: cart.reduce(function (n, l) { return n + l.qty; }, 0),
                                itemIds: cart.map(function (l) { return l.id; })
                            });
                        }
                    } catch (e) {}
                    cart = [];
                    saveCart();
                    renderCart();
                    if (loyaltyRedeemInput) { loyaltyRedeemInput.value = '0'; }
                    var loyCheckbox = document.getElementById('msLoyToggleCheckbox');
                    if (loyCheckbox) { loyCheckbox.checked = false; }
                    document.getElementById('msOrderNo').textContent = '#' + String(data.track_token || '').toUpperCase();
                    var trackLink = document.getElementById('msTrackOrderLink');
                    trackLink.href = data.redirect || trackRoute(data.track_token || '');
                    showCartStage('confirm');
                } else {
                    msg.innerHTML = '<div class="ms-msg ms-msg--error">' + esc((data && data.message) || T.genericError) + '</div>';
                }
            })
            .catch(function () {
                placeBtn.disabled = false;
                msg.innerHTML = '<div class="ms-msg ms-msg--error">' + esc(T.genericError) + '</div>';
            });
    });
    document.getElementById('msContinueShopping').addEventListener('click', function () {
        showCartStage('cart');
        rawCloseA11ySheet();
        go('home');
    });

    /* ---------------- AI stylist ----------------
       POSTs to `stylist.search` ONLY when that route exists (see the
       $stylistRoute PHP guard above — it may not be built yet). Otherwise
       degrades to a curated grid pulled from THIS store's own real items
       (never fabricated products), with a message that is honest about the
       fallback rather than pretending it's AI-curated. */
    var aiThread = document.getElementById('msAiThread');
    function scrollAiThread() {
        var scroller = document.getElementById('ms-main');
        if (scroller) { scroller.scrollTo({ top: scroller.scrollHeight, behavior: 'smooth' }); }
    }
    function localStylistPicks(query) {
        var words = query.toLowerCase().split(/\s+/).filter(Boolean);
        var byIdDesc = Object.keys(ITEMS).map(function (k) { return ITEMS[k]; })
            .sort(function (a, b) { return b.createdAt - a.createdAt; });
        if (words.length) {
            var matched = byIdDesc.filter(function (it) {
                var hay = (it.name + ' ' + it.desc + ' ' + (it.brand || '')).toLowerCase();
                return words.some(function (w) { return hay.indexOf(w) !== -1; });
            });
            if (matched.length) { return { items: matched.slice(0, 4), matched: true }; }
        }
        return { items: byIdDesc.slice(0, 4), matched: false };
    }
    function aiRespond(query) {
        query = (query || '').trim();
        if (!query) { return; }
        aiThread.insertAdjacentHTML('beforeend',
            '<div class="ms-chatrow me"><span class="who"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg></span><div class="ms-bubble">' + esc(query) + '</div></div>');
        var think = document.createElement('div');
        think.className = 'ms-chatrow';
        think.innerHTML = '<span class="who"><svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="currentColor"/></svg></span>'
            + '<div class="ms-bubble"><span class="ms-thinking"><i>&middot;</i><i>&middot;</i><i>&middot;</i></span> ' + esc(T.aiThinking) + '</div>';
        aiThread.appendChild(think);
        scrollAiThread();

        function renderPicks(items, message) {
            think.innerHTML = '<span class="who"><svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="currentColor"/></svg></span><div class="ms-bubble">' + esc(message) + '</div>';
            if (items.length) {
                aiThread.insertAdjacentHTML('beforeend',
                    '<div class="ms-label" style="margin:6px 0 0">' + esc(T.aiPicksTitle) + '</div><div class="ms-grid">' + items.map(cardHTML).join('') + '</div>');
                wireFavButtons(aiThread);
            }
            scrollAiThread();
        }

        if (STYLIST_ROUTE) {
            var fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('query', query);
            fetch(STYLIST_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var ids = (data && data.items) ? data.items.map(function (x) { return (x && x.id) ? x.id : x; }) : [];
                    var picks = ids.map(function (id) { return ITEMS[id]; }).filter(Boolean);
                    if (picks.length) { renderPicks(picks, T.aiCurated); }
                    else { var fb = localStylistPicks(query); renderPicks(fb.items, T.aiNoMatch); }
                })
                .catch(function () { var fb = localStylistPicks(query); renderPicks(fb.items, T.aiFallback); });
        } else {
            setTimeout(function () {
                var fb = localStylistPicks(query);
                renderPicks(fb.items, fb.matched ? T.aiCurated : T.aiFallback);
            }, 700);
        }
    }
    var aiForm = document.getElementById('msAiForm');
    if (aiForm) {
        aiForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = document.getElementById('msAiInput');
            var q = input.value;
            input.value = '';
            aiRespond(q);
        });
    }
    document.querySelectorAll('[data-ai-ask]').forEach(function (b) {
        b.addEventListener('click', function () { aiRespond(b.getAttribute('data-ai-ask')); });
    });

    /* ---------------- visual search ----------------
       No fabricated camera "AI match" — this only ever calls a real backend
       (`visual.search`) when that route exists; otherwise it degrades to a
       single real item from this store's own catalog with an honest caption
       explaining visual search isn't wired up yet. */
    function matchCardHTML(item, caption) {
        var media = item.img
            ? '<img src="' + esc(item.img) + '" alt="" data-csp-error="hide">'
            : '';
        return '<div class="ms-matchcard">'
            + '<a class="mimg" href="?item=' + item.id + '" data-open-item-link="' + item.id + '">' + media + '</a>'
            + '<div class="minfo"><div class="mn">' + esc(item.name) + '</div><div class="ms">' + esc(caption || item.brand || '') + '</div><div class="mp">' + esc(item.priceFormatted) + '</div></div>'
            + '<button type="button" class="madd" data-open-item="' + item.id + '" aria-label="' + esc(VOCAB_ADD) + '">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></button></div>';
    }
    function fallbackVisualMatch() {
        var candidates = Object.keys(ITEMS).map(function (k) { return ITEMS[k]; })
            .filter(function (it) { return !it.soldOut; })
            .sort(function (a, b) { return b.createdAt - a.createdAt; });
        return candidates[0] || null;
    }
    var vsFile = document.getElementById('msVsFile');
    var vsFrame = document.getElementById('msVsFrame');
    var vsResult = document.getElementById('msVsResult');
    if (vsFile) {
        vsFile.addEventListener('change', function () {
            var file = vsFile.files && vsFile.files[0];
            if (!file) { return; }
            vsResult.innerHTML = '<p class="ms-vsnote">' + esc(T.vsScanning) + '</p>';
            vsFrame.classList.add('is-scanning');
            var finish = function (items, caption) {
                vsFrame.classList.remove('is-scanning');
                if (!items.length) { vsResult.innerHTML = '<p class="ms-vsnote">' + esc(T.vsFallback) + '</p>'; return; }
                vsResult.innerHTML = '<div class="ms-label" style="margin-top:0">' + esc(T.vsFound) + '</div>'
                    + items.map(function (it) { return matchCardHTML(it, caption); }).join('')
                    + (caption ? '<p class="ms-vsnote">' + esc(caption) + '</p>' : '');
                wireFavButtons(vsResult);
            };
            if (VISUAL_SEARCH_ROUTE) {
                var fd = new FormData();
                fd.append('_token', CSRF);
                fd.append('photo', file);
                fetch(VISUAL_SEARCH_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var ids = (data && data.items) ? data.items.map(function (x) { return (x && x.id) ? x.id : x; }) : [];
                        var picks = ids.map(function (id) { return ITEMS[id]; }).filter(Boolean);
                        if (picks.length) { finish(picks, null); return; }
                        var fb = fallbackVisualMatch();
                        finish(fb ? [fb] : [], T.vsFallback);
                    })
                    .catch(function () {
                        var fb = fallbackVisualMatch();
                        finish(fb ? [fb] : [], T.vsFallback);
                    });
            } else {
                setTimeout(function () {
                    var fb = fallbackVisualMatch();
                    finish(fb ? [fb] : [], T.vsFallback);
                }, 900);
            }
        });
    }

    /* ---------------- account: order lookup ---------------- */
    var trackGoBtn = document.getElementById('msTrackGo');
    if (trackGoBtn) {
        trackGoBtn.addEventListener('click', function () {
            var input = document.getElementById('msTrackInput');
            var val = (input.value || '').trim();
            if (!val) { window.alert(T.trackNeedsValue); return; }
            if (/^https?:\/\//i.test(val)) { window.location.href = val; return; }
            window.location.href = trackRoute(val);
        });
    }

    /* ---------------- shared: star icon + relative time helpers ---------------- */
    function starsHTML(n, max) {
        max = max || 5;
        n = Math.max(0, Math.min(max, Math.round(Number(n) || 0)));
        var out = '';
        for (var i = 1; i <= max; i++) {
            out += '<svg viewBox="0 0 24 24" width="13" height="13" fill="' + (i <= n ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="1.5">'
                + '<path d="M12 3l2.6 5.6 6.1.8-4.5 4.3 1.1 6-5.3-2.9-5.3 2.9 1.1-6-4.5-4.3 6.1-.8z"/></svg>';
        }
        return out;
    }
    function relTime(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso);
        if (isNaN(d.getTime())) { return ''; }
        var diffSec = Math.round((d.getTime() - Date.now()) / 1000);
        var abs = Math.abs(diffSec);
        var units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
        try {
            var rtf = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' });
            for (var i = 0; i < units.length; i++) {
                if (abs >= units[i][1] || i === units.length - 1) {
                    return rtf.format(Math.round(diffSec / units[i][1]), units[i][0]);
                }
            }
        } catch (e) { /* Intl.RelativeTimeFormat unsupported — fall through */ }
        try { return d.toLocaleDateString(document.documentElement.lang || undefined); } catch (e2) { return ''; }
    }
    function truncate(s, n) {
        s = String(s || '');
        return s.length > n ? s.slice(0, n - 1) + '…' : s;
    }

    /* ---------------- F3 · stories rail + full-screen viewer ----------------
       GET STORIES_ROUTE (r/{store}/stories) — public/ungated, already
       active + in-window + sorted (Story::visible()). Response is
       `{data:[{id, media_url, caption, cta_label, cta_url, sort}]}` — note
       `media_url` (already a resolved URL), NOT `media_full_url`. Fetched on
       load; the whole rail stays hidden when the feed is empty. Viewed
       bubbles are remembered in localStorage and shown with a dimmed ring. */
    var STORY_VIEWED_KEY = 'maison-stories-viewed-' + storeId;
    var storiesData = [];
    var viewedStoryIds = {};
    try {
        (JSON.parse(window.localStorage.getItem(STORY_VIEWED_KEY) || '[]') || []).forEach(function (id) { viewedStoryIds[id] = true; });
    } catch (e) { viewedStoryIds = {}; }
    function saveViewedStories() {
        try { window.localStorage.setItem(STORY_VIEWED_KEY, JSON.stringify(Object.keys(viewedStoryIds))); } catch (e) { /* best-effort */ }
    }
    function loadStories() {
        var rail = document.getElementById('msStoriesRail');
        if (!rail || !STORIES_ROUTE) { return; }
        fetch(STORIES_ROUTE, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (payload) {
                storiesData = Array.isArray(payload) ? payload : (payload && payload.data) || [];
                if (!storiesData.length) { rail.hidden = true; rail.innerHTML = ''; return; }
                rail.innerHTML = storiesData.map(function (s, idx) {
                    var viewed = !!viewedStoryIds[s.id];
                    var media = s.media_url || s.media_full_url || '';
                    return '<button type="button" class="ms-story' + (viewed ? ' is-viewed' : '') + '" data-story-idx="' + idx + '" role="listitem">'
                        + '<span class="ms-story__ring"><img class="ms-story__img" src="' + esc(media) + '" alt="" loading="lazy" data-csp-error="hide"></span>'
                        + '<span class="ms-story__label">' + esc(s.caption ? truncate(s.caption, 14) : T.viewStory) + '</span></button>';
                }).join('');
                rail.hidden = false;
            })
            .catch(function () { rail.hidden = true; });
    }
    var storyViewer = document.getElementById('msStoryViewer');
    var storyIdx = 0;
    var storyTimer = null;
    var STORY_DURATION = 5000;
    function isRtl() {
        return (document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';
    }
    function renderStoryBars() {
        var wrap = document.getElementById('msStoryBars');
        if (!wrap) { return; }
        wrap.innerHTML = storiesData.map(function (_, i) {
            return '<span class="ms-story-viewer__bar' + (i < storyIdx ? ' is-done' : '') + '" data-bar="' + i + '"><span></span></span>';
        }).join('');
        var activeBar = wrap.querySelector('[data-bar="' + storyIdx + '"]');
        if (activeBar) {
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { activeBar.classList.add('is-active'); });
            });
        }
    }
    function closeStoryViewer() {
        if (!storyViewer || storyViewer.hidden) { return; }
        storyViewer.hidden = true;
        storyViewer.setAttribute('aria-hidden', 'true');
        clearTimeout(storyTimer);
        document.removeEventListener('keydown', storyKeyHandler);
        if (storyOpenTrigger && typeof storyOpenTrigger.focus === 'function') {
            try { storyOpenTrigger.focus(); } catch (e) { /* best-effort */ }
        }
        storyOpenTrigger = null;
    }
    function nextStory() {
        if (storyIdx < storiesData.length - 1) { storyIdx++; renderStorySlide(); }
        else { closeStoryViewer(); }
    }
    function prevStory() {
        if (storyIdx > 0) { storyIdx--; }
        renderStorySlide();
    }
    function renderStorySlide() {
        clearTimeout(storyTimer);
        var s = storiesData[storyIdx];
        if (!s || !storyViewer) { closeStoryViewer(); return; }
        viewedStoryIds[s.id] = true;
        saveViewedStories();
        var bubble = document.querySelector('.ms-story[data-story-idx="' + storyIdx + '"]');
        if (bubble) { bubble.classList.add('is-viewed'); }
        var img = document.getElementById('msStoryImg');
        img.src = s.media_url || s.media_full_url || '';
        img.alt = s.caption || '';
        var capEl = document.getElementById('msStoryCap');
        capEl.textContent = s.caption || '';
        var ctaEl = document.getElementById('msStoryCta');
        if (s.cta_url) {
            ctaEl.hidden = false;
            ctaEl.textContent = s.cta_label || T.viewStory;
            ctaEl.href = s.cta_url;
            if (/^\//.test(s.cta_url)) { ctaEl.removeAttribute('target'); ctaEl.removeAttribute('rel'); }
            else { ctaEl.target = '_blank'; ctaEl.rel = 'noopener'; }
        } else {
            ctaEl.hidden = true;
        }
        renderStoryBars();
        storyTimer = setTimeout(nextStory, STORY_DURATION);
    }
    var storyOpenTrigger = null;
    function storyKeyHandler(e) {
        if (e.key === 'Escape') { closeStoryViewer(); }
        else if (e.key === 'ArrowRight') { isRtl() ? prevStory() : nextStory(); }
        else if (e.key === 'ArrowLeft') { isRtl() ? nextStory() : prevStory(); }
    }
    function openStoryViewer(idx) {
        if (!storyViewer || !storiesData.length) { return; }
        storyOpenTrigger = document.activeElement;
        storyIdx = Math.max(0, Math.min(storiesData.length - 1, idx));
        storyViewer.hidden = false;
        storyViewer.setAttribute('aria-hidden', 'false');
        document.addEventListener('keydown', storyKeyHandler);
        renderStorySlide();
        var closeBtn = document.getElementById('msStoryClose');
        setTimeout(function () { try { closeBtn.focus(); } catch (e) { /* best-effort */ } }, 30);
    }
    var storiesRailEl = document.getElementById('msStoriesRail');
    if (storiesRailEl) {
        storiesRailEl.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-story-idx]');
            if (btn) { openStoryViewer(parseInt(btn.getAttribute('data-story-idx'), 10)); }
        });
    }
    var storyCloseBtn = document.getElementById('msStoryClose');
    if (storyCloseBtn) { storyCloseBtn.addEventListener('click', closeStoryViewer); }
    var storyPrevZone = document.getElementById('msStoryPrevZone');
    var storyNextZone = document.getElementById('msStoryNextZone');
    if (storyPrevZone) { storyPrevZone.addEventListener('click', prevStory); }
    if (storyNextZone) { storyNextZone.addEventListener('click', nextStory); }
    var storyStage = document.getElementById('msStoryStage');
    if (storyStage) {
        var storyTouchStartX = null;
        storyStage.addEventListener('touchstart', function (e) {
            storyTouchStartX = e.touches[0].clientX;
        }, { passive: true });
        storyStage.addEventListener('touchend', function (e) {
            if (storyTouchStartX === null) { return; }
            var dx = e.changedTouches[0].clientX - storyTouchStartX;
            storyTouchStartX = null;
            if (Math.abs(dx) < 40) { return; }
            if (isRtl()) { dx = -dx; }
            if (dx < 0) { nextStory(); } else { prevStory(); }
        }, { passive: true });
    }

    /* ---------------- F6 · reviews (inside the product detail sheet) ----------------
       GET  <REVIEWS_BASE>/<menuId>/reviews?filter=latest ->
            {data:[{id,name,stars,body,is_verified,created_at,photos}], average, count, distribution:{5..1}}
       POST <REVIEWS_BASE>/<menuId>/reviews  JSON {rating, text, name}
       (MenuReviewController validates `rating`/`text`/`name` — NOT
       `stars`/`body`/`reviewer_name` — and a new review is created `pending`,
       so it won't appear in the very next list fetch until approved; the
       success message is honest about that instead of implying it's live.) */
    var reviewStarsVal = 0;
    var reviewStarsWrap = document.getElementById('msReviewStars');
    function paintReviewStars() {
        if (!reviewStarsWrap) { return; }
        reviewStarsWrap.querySelectorAll('[data-star]').forEach(function (b) {
            var on = parseInt(b.getAttribute('data-star'), 10) <= reviewStarsVal;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }
    if (reviewStarsWrap) {
        reviewStarsWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-star]');
            if (!btn) { return; }
            reviewStarsVal = parseInt(btn.getAttribute('data-star'), 10);
            paintReviewStars();
        });
    }
    function renderReviewsDist(dist, count) {
        var host = document.getElementById('msReviewsDist');
        if (!host) { return; }
        var rows = [5, 4, 3, 2, 1].map(function (star) {
            var n = (dist && dist[star]) || 0;
            var pct = count ? Math.round((n / count) * 100) : 0;
            return '<div class="ms-reviews__distrow"><span>' + star + '</span>'
                + '<span class="ms-reviews__distbar"><span style="width:' + pct + '%"></span></span>'
                + '<span>' + n + '</span></div>';
        });
        host.innerHTML = rows.join('');
    }
    function renderReviewsList(list) {
        var host = document.getElementById('msReviewsList');
        var emptyEl = document.getElementById('msReviewsEmpty');
        if (!host || !emptyEl) { return; }
        if (!list || !list.length) {
            host.innerHTML = '';
            emptyEl.hidden = false;
            return;
        }
        emptyEl.hidden = true;
        host.innerHTML = list.map(function (r) {
            return '<div class="ms-review">'
                + '<div class="ms-review__top">'
                + '<span class="ms-review__stars" aria-hidden="true">' + starsHTML(r.stars) + '</span>'
                + '<span class="ms-review__date">' + esc(relTime(r.created_at)) + '</span></div>'
                + '<div class="ms-review__name">' + esc(r.name || T.anonymousReviewer) + '</div>'
                + (r.body ? '<p class="ms-review__body">' + esc(r.body) + '</p>' : '')
                + '</div>';
        }).join('');
    }
    function loadReviews(menuId) {
        var summaryEl = document.getElementById('msReviewsSummary');
        if (!summaryEl || !REVIEWS_BASE) { return; }
        fetch(reviewsListUrl(menuId), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var count = (data && data.count) || 0;
                summaryEl.hidden = !count;
                if (count) {
                    document.getElementById('msReviewsAvgNum').textContent = (Number(data.average) || 0).toFixed(1);
                    document.getElementById('msReviewsAvgStars').innerHTML = starsHTML(data.average);
                    document.getElementById('msReviewsCount').textContent = count === 1 ? T.reviewCountOne : T.reviewCount.replace(':count', count);
                }
                renderReviewsDist(data && data.distribution, count);
                renderReviewsList(data && data.data);
            })
            .catch(function () { /* best-effort — the write form still works */ });
    }
    var reviewForm = document.getElementById('msReviewForm');
    if (reviewForm) {
        reviewForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var msg = document.getElementById('msReviewMsg');
            msg.innerHTML = '';
            if (!reviewStarsVal) {
                msg.innerHTML = '<div class="ms-msg ms-msg--error">' + esc(T.reviewStarsRequired) + '</div>';
                return;
            }
            if (!currentItem) { return; }
            var submitBtn = reviewForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            fetch(reviewsPostUrl(currentItem.id), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({
                    rating: reviewStarsVal,
                    text: document.getElementById('msReviewBody').value.trim() || null,
                    name: document.getElementById('msReviewName').value.trim() || null,
                }),
            })
                .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                .then(function (res) {
                    submitBtn.disabled = false;
                    if (res.ok) {
                        msg.innerHTML = '<div class="ms-msg ms-msg--ok">' + esc(T.reviewThanks) + '</div>';
                        reviewForm.reset();
                        reviewStarsVal = 0;
                        paintReviewStars();
                        loadReviews(currentItem.id);
                    } else {
                        var firstError = res.data && res.data.errors ? Object.values(res.data.errors)[0] : null;
                        var errText = (Array.isArray(firstError) ? firstError[0] : null) || (res.data && res.data.message) || T.genericError;
                        msg.innerHTML = '<div class="ms-msg ms-msg--error">' + esc(errText) + '</div>';
                    }
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    msg.innerHTML = '<div class="ms-msg ms-msg--error">' + esc(T.genericError) + '</div>';
                });
        });
    }

    /* ---------------- F3(b) · loyalty — checkout redeem row + account Rewards card ----------------
       Teaser (LOY_TEASER: program/earn/reward) is public. Balance is
       auth:customer — guests get a 401, which both call sites below treat as
       "not signed in", never as an error banner. */
    function renderLoyaltyRedeemRow(balance) {
        var wrap = document.getElementById('msLoyRow');
        var teaserEl = document.getElementById('msLoyTeaser');
        var toggleWrap = document.getElementById('msLoyToggleWrap');
        var checkbox = document.getElementById('msLoyToggleCheckbox');
        var labelEl = document.getElementById('msLoyToggleLabel');
        var hiddenInput = document.getElementById('msLoyaltyRedeemInput');
        if (!wrap) { return; }
        if (balance && balance.active && balance.ready_to_redeem) {
            wrap.hidden = false;
            teaserEl.hidden = true;
            toggleWrap.hidden = false;
            labelEl.textContent = T.loyApply.replace(':discount', balance.reward_discount_display || '');
        } else if (LOY_TEASER) {
            wrap.hidden = false;
            toggleWrap.hidden = true;
            teaserEl.hidden = false;
            teaserEl.innerHTML = '<strong>' + esc(LOY_TEASER.program || T.loyDefaultProgram) + '</strong>'
                + '<span>' + esc(LOY_TEASER.earn || '') + '</span>'
                + '<span class="ms-loyrow__signin">' + esc(T.loySigninHint) + '</span>';
            checkbox.checked = false;
            if (hiddenInput) { hiddenInput.value = '0'; }
        } else {
            wrap.hidden = true;
        }
    }
    var loyToggleCheckbox = document.getElementById('msLoyToggleCheckbox');
    if (loyToggleCheckbox) {
        loyToggleCheckbox.addEventListener('change', function () {
            var hiddenInput = document.getElementById('msLoyaltyRedeemInput');
            if (hiddenInput) { hiddenInput.value = loyToggleCheckbox.checked ? '1' : '0'; }
        });
    }
    function loadLoyaltyForCheckout() {
        if (!LOYALTY_BALANCE_ROUTE) { renderLoyaltyRedeemRow(null); return; }
        fetch(LOYALTY_BALANCE_ROUTE, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { renderLoyaltyRedeemRow(data); })
            .catch(function () { renderLoyaltyRedeemRow(null); });
    }
    function loadRewardsBalance() {
        var card = document.getElementById('msRewardsCard');
        if (!card || !LOYALTY_BALANCE_ROUTE) { return; }
        var balanceEl = document.getElementById('msRewardsBalance');
        var signinEl = document.getElementById('msRewardsSignin');
        fetch(LOYALTY_BALANCE_ROUTE, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.active) {
                    balanceEl.innerHTML = T.loyBalancePts.replace(':count', Math.round(data.balance || 0))
                        + (data.tier ? '<small>' + esc(data.tier) + '</small>' : '');
                    balanceEl.hidden = false;
                    signinEl.hidden = true;
                    card.querySelectorAll('.ms-tierchip').forEach(function (chip) {
                        chip.classList.toggle('is-current', data.tier && chip.getAttribute('data-tier') === data.tier);
                    });
                } else {
                    balanceEl.hidden = true;
                    signinEl.hidden = false;
                }
            })
            .catch(function () { balanceEl.hidden = true; signinEl.hidden = false; });
    }

    /* crave-parity: branch switch (reload with ?branch_id) */
    (function () {
        var bs = document.getElementById('msBranchSel');
        if (bs) { bs.addEventListener('change', function (e) { if (e.target.value) { window.location.assign(e.target.value); } }); }
    })();

    /* crave-parity: promo banner slider (dots + gentle auto-advance) */
    (function () {
        var track = document.getElementById('msBannerTrack');
        var dotsWrap = document.getElementById('msBannerDots');
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
})();
</script>
@endpush


