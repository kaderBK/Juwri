{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.zevoa.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;
    use Illuminate\Support\Str;

    /* ---------------------------------------------------------------------
       zevoa — grocery quick-commerce super-app shell · data preparation
       (rev/multi-vertical, retail vertical, "goods" theme family).

       Adapted from store_templates/shelf/index.blade.php: same self-contained
       theme shape (no shared store_templates/layout.blade.php or script.js —
       see zevoa/layout.blade.php's own docblock), same defensive reads for
       columns that may not exist yet on every install, same real "goods"
       cart/checkout wiring (rev/multi-vertical Lane V). Never hardcodes a
       food noun — always $vocab (C5 grep-guard).

       SIGNATURE SPLIT (see the design spec this mirrors,
       scratchpad/handoff/02-retail-zevoa.md): only the "store" screen below
       (ZeMart) binds to $store/$menu. The "home" screen is a search-first
       supermarket shell built from the store's REAL categories and deals. The
       account screen's Settings / Addresses / Payment methods / Help are real
       sub-screens (localStorage-backed appearance + notifications + addresses;
       payment info + contact wired to the store's own phone/email/WhatsApp),
       and the footer social + app-store links render only for the channels the
       store actually configured. */
    $vocabSvc = app(VerticalVocab::class);
    $vocab    = $vocabSvc->map($store->store_type ?? null);

    $items = collect($total_menus)->values();

    // Two-tier-free category nav: identical "root" mapping to shelf/crave/
    // aurora — a top-level category counts if IT or any of its subcategories
    // owns an item, so nested categories still group correctly under one chip.
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

    /* Defensive attribute readers — same posture as shelf: every accessor
       degrades to null/[] rather than erroring on a column or relation that
       does not exist yet on this install, and simply "lights up" once it
       does, with no further changes needed here. */
    $attrsOf = \Juwri\Core\Support\ViewHelpers::attrsOf(...);
    $brandOf = \Juwri\Core\Support\ViewHelpers::brandOf(...);
    // Grocery "unit" sub-label (`/kg`, `/1L`, `/loaf`…). No dedicated column
    // exists yet on `menus`, so this reads a `unit` attribute directly off
    // the item first, falling back to the JSON attributes bag — renders
    // nothing (the card just omits the sub-line) until either is populated.
    $unitOf = function ($m) use ($attrsOf): ?string {
        $direct = $m->unit ?? null;
        if ($direct !== null && $direct !== '') {
            return (string) $direct;
        }
        $attrs = $attrsOf($m);

        return (isset($attrs['unit']) && $attrs['unit'] !== '') ? (string) $attrs['unit'] : null;
    };
    // "Compare-at" (was-price) for the sale ribbon + strikethrough. Same
    // defensive posture: no such column exists yet either, so this reads a
    // `compare_at_price` attribute (direct or JSON) and only shows a badge
    // once it is BOTH populated and genuinely higher than the live price.
    $compareAtOf = function ($m) use ($attrsOf): ?float {
        $direct = $m->compare_at_price ?? null;
        if ($direct === null) {
            $attrs = $attrsOf($m);
            $direct = $attrs['compare_at_price'] ?? null;
        }

        return ($direct !== null && (float) $direct > (float) $m->price) ? (float) $direct : null;
    };
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelAxes(...);

    // Best-effort real color for a variant swatch dot: parse a hex code or a
    // common color NAME out of the option label text ("Red", "Navy Blue",
    // "#2563eb"...). Anything unrecognised returns null so the dot keeps the
    // neutral line color the CSS already defaults to — never a wrong guess.
    $namedSwatchColors = [
        'black' => '#111111', 'white' => '#ffffff', 'red' => '#d33333', 'blue' => '#2563eb',
        'green' => '#16a34a', 'yellow' => '#eab308', 'orange' => '#f97316', 'purple' => '#9333ea',
        'pink' => '#ec4899', 'brown' => '#8b5e3c', 'grey' => '#9ca3af', 'gray' => '#9ca3af',
        'navy' => '#1e3a8a', 'beige' => '#e8dcc8', 'gold' => '#d4af37', 'silver' => '#c0c0c0',
        'maroon' => '#7f1d1d', 'teal' => '#0d9488', 'cyan' => '#06b6d4', 'olive' => '#6b7a1a',
        'khaki' => '#c3b091', 'cream' => '#f5f0dc',
    ];
    $swatchColor = function (?string $label) use ($namedSwatchColors): ?string {
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

        return null;
    };

    // "Out of stock" / ":count left" / "In stock" — shown only when this item
    // actually tracks inventory AND a count is known.
    $stockBadge = \Juwri\Core\Support\ViewHelpers::stockBadge(...);

    // Currency contract — the SAME symbol/position/gap/decimals contract
    // price_symbol_format() (used for every priceFormatted below) honours,
    // so client-computed totals (cart subtotal, presentational fee lines,
    // sheet unit×qty) render with the right symbol/side/decimals too.
    $curSignRaw  = $postOptions->currency_sign ?? config('settings.currency_sign', '$');
    $curSign     = cur_isolate($curSignRaw);
    $curGap      = currency_symbol_gap($curSignRaw);
    $curPos      = (string) currency_pos_effective($store->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);

    // Language switch — self-contained, mirrors the store's menu_languages.
    $zvLanguages = collect($menu_languages ?? [])
        ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
        ->values();

    // Real tax contract (Order::computeTax) — exposed to the client cart so the
    // presentational summary shows the SAME tax the store actually charges.
    $taxCharge = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $taxType = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');

    $fulfillment = app(FulfillmentRegistry::class);
    $modes = collect($fulfillment->modesFor($store->store_type ?? null))->map(fn ($mode) => [
        'value' => $mode,
        // In-theme relabel only — the confirmed FulfillmentRegistry contract
        // (config('verticals.fulfillment').ship => needs_address=true) is
        // untouched; a grocery quick-commerce shopper reads "Ship to
        // address" as parcel shipping, so this renders it as "Delivery"
        // per the design spec, exactly like shelf relabels nothing but
        // zevoa's own grocery vocabulary calls for it here.
        'label' => $mode === 'ship' ? ($vocab['delivery'] ?? ___('Delivery')) : $fulfillment->label($mode),
        'needsAddress' => $fulfillment->needsAddress($mode),
        'needsSlot' => $fulfillment->needsSlot($mode),
    ])->values();

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
                'barcode' => $v->barcode ?? null,
                'stockQty' => isset($v->stock_qty) ? (int) $v->stock_qty : null,
                'color' => $swatchColor($vLabel),
            ];
        }

        $attrs = $attrsOf($m);
        $root = $rootOf[$m->category_id] ?? $m->category_id;
        $compareAt = $compareAtOf($m);

        // Gallery: primary image first, then every extra gallery image, all
        // resolved to public URLs (deduped). Powers the swipeable thumbnail
        // strip in the product screen when an item has ≥2 images.
        $gallery = [];
        $primaryImg = ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '';
        if ($primaryImg !== '') {
            $gallery[] = $primaryImg;
        }
        foreach ((array) ($m->gallery_images ?? []) as $gimg) {
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
            // Only a REAL uploaded image — never the food-themed default.png,
            // which would render a fork-and-plate glyph on a grocery card.
            'img' => $primaryImg,
            'gallery' => $gallery,
            'price' => (float) $m->price,
            'priceFormatted' => price_symbol_format($m->price),
            'compareAt' => $compareAt,
            'compareAtFormatted' => $compareAt ? price_symbol_format($compareAt) : null,
            'discountPct' => $compareAt ? (int) round((1 - ((float) $m->price / $compareAt)) * 100) : 0,
            'unit' => $unitOf($m),
            'brand' => $brandOf($m),
            'weight' => $m->weight ?? null,
            'dimensions' => $m->dimensions ?? null,
            'attrs' => $attrs,
            'sku' => $m->plu ?? $m->external_sku ?? null,
            'soldOut' => (bool) $m->is_sold_out,
            'trackInventory' => (bool) ($m->track_inventory ?? false),
            'stockQty' => isset($m->stock_qty) ? (int) $m->stock_qty : null,
            'stockBadge' => $stockBadge((bool) ($m->track_inventory ?? false), isset($m->stock_qty) ? (int) $m->stock_qty : null),
            'ageRestricted' => (bool) ($m->age_restricted ?? false),
            'variants' => $variants,
            // rev/multi-vertical Lane V — the native goods cart endpoint for
            // THIS item, so quick-add lands a real CartLine, not only a
            // client-side preview array.
            'addRoute' => route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $m->id]),
        ];
    }

    $brands = collect($jsItems)->pluck('brand')->filter()->unique()->sort()->values();

    /* Supermarket reframe (home): a representative product image per top-level
       category (first item that owns a real photo), for the Lulu/Carrefour-style
       category tile row; and the subset of items that are genuinely on sale
       (compareAt populated & higher), for the "Deals" carousel. Both degrade to
       nothing — a category with no photographed item falls back to a glyph tile,
       and the Deals section hides entirely when nothing is discounted. */
    $catImageOf = [];
    foreach ($items as $m) {
        $root = $rootOf[$m->category_id] ?? $m->category_id;
        if (! isset($catImageOf[$root]) && ! empty($jsItems[$m->id]['img'])) {
            $catImageOf[$root] = $jsItems[$m->id]['img'];
        }
    }
    $dealItems = $items->filter(fn ($m) => ($jsItems[$m->id]['discountPct'] ?? 0) > 0)->values();

    /* Supermarket density (home): items grouped under their top-level category,
       so the home can stack a themed product carousel per aisle (Lulu "Farm
       Fresh / Fresh Food", Carrefour "عروض الألبان / عروض اللحوم") instead of a
       single thin "popular" rail. Preserves category order; only categories that
       actually own items appear (already true of $categories). */
    $itemsByRoot = [];
    foreach ($items as $m) {
        $root = $rootOf[$m->category_id] ?? $m->category_id;
        $itemsByRoot[$root][] = $m;
    }
    // Biggest-discount item per deal, for the flash-strip ordering (best offers first).
    $flashDeals = $dealItems->sortByDesc(fn ($m) => $jsItems[$m->id]['discountPct'] ?? 0)->values();

    // Crave-parity features (same backend data the food themes read; no controller change).
    $zvIsOpen = store_open_now($store);
    $zvHoursToday = storefront_hours_today($store);
    $zvBranches = collect($branches ?? []);
    $zvShowBranches = $zvBranches->count() > 1 && (($postOptions->allow_multi_branch_switch ?? 1) != '0');
    $zvActiveBranch = $activeBranch ?? null;
    $zvBanners = \Juwri\Core\Support\ViewHelpers::activeBanners($menuBanners ?? []);

    // Native checkout (Lane B's GoodsCheckoutController). Server-side cart
    // state (added to via each item's `addRoute` above) is what actually
    // gets ordered; this theme's own `cart` array is a client-side preview
    // of the same lines, same as shelf.
    $checkoutRoute = route('storefront.checkout.place', ['slug' => $store->slug]);
    // Route "template" for a dynamic cart-line id (the cart-screen quantity
    // stepper PATCHes an existing line's quantity for real) — 999999999 is a
    // placeholder swapped client-side once a real line id exists.
    $cartLineRouteTemplate = route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => 999999999]);
    $slotRoute = route('store.scheduleSlots', $store->id);

    // ---- Curated core features (loop/enrich): stories, reviews, loyalty ----
    // Public, ungated feeds — same "build the URL from $store" posture as the
    // routes above. Reviews' base is per the shared contract's own snippet.
    $storiesRoute = url('r/'.$store->id.'/stories');
    $mbrBase = url('r/'.$store->id.'/item');
    $loyaltyBalanceRoute = url('restaurant/'.$store->id.'/loyalty/balance');
    // store_options() decodes a JSON option value to a stdClass — normalize to
    // a plain array (or null if the store never set a teaser) so the Blade
    // reads below (and the @json() handed to the script) are all array-shaped.
    $loyaltyTeaserRaw = store_options($store->id, 'loyalty_teaser');
    $loyaltyTeaser = $loyaltyTeaserRaw ? (array) $loyaltyTeaserRaw : null;
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --zv-ground:#F6F7F4; --zv-canvas:#EAEEE7; --zv-card:#FFFFFF; --zv-card2:#F2F5EF;
        --zv-ink:#16201A; --zv-ink2:#66746A; --zv-ink3:#93A198; --zv-hair:#E5EAE1; --zv-hair2:#D6DDD0;
        --zv-brand:{{ $store->color ?: '#128A4B' }}; --zv-brand2:#0C6E3B; --zv-brand-ink:#ffffff;
        --zv-brand-bg:#E7F4EC; --zv-amber:#F5A623; --zv-amber-bg:#FEF3DD; --zv-sale:#E23B32;
        --zv-tile:#FFFFFF;
        --zv-radius:14px; --zv-radius-lg:22px; --zv-radius-card:20px;
        /* Layered ambient shadows (a soft near shadow + a diffuse far one) read
           as machined depth, never a harsh single drop. */
        --zv-shadow-card:0 1px 2px rgba(16,24,20,.04), 0 10px 24px -14px rgba(16,24,20,.20);
        --zv-shadow-hover:0 2px 6px rgba(16,24,20,.06), 0 22px 44px -18px rgba(16,24,20,.30);
        --zv-ease:cubic-bezier(.22,.9,.3,1);
        --zv-font:"Saudi Riyal","IBM Plex Sans Arabic",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
        --zv-navh:70px;
        /* Desktop-only sizing tokens (see @media blocks near the end of this
           style block) — sizes only, never colors, so nothing here needs a
           dark-mode mirror. */
        --zv-dtopnav-h:76px; --zv-tablet-max:760px; --zv-desk-max:min(1600px,94vw);
        --zv-desk-pad:clamp(20px,5vw,48px); --zv-desk-side:360px; --zv-desk-buybox:480px;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --zv-ground:#0E120F; --zv-canvas:#080A08; --zv-card:#161B17; --zv-card2:#1E241F;
            --zv-ink:#EDF1EC; --zv-ink2:#9AA79E; --zv-ink3:#67736A; --zv-hair:#262C26; --zv-hair2:#313931;
            --zv-brand2:#28A25A; --zv-brand-bg:#132A1D; --zv-amber:#F5A623; --zv-amber-bg:#2C2412; --zv-sale:#FF6B5E;
            --zv-tile:#F4F6F1;
            --zv-shadow-card:0 14px 30px -18px rgba(0,0,0,.55);
        }
    }
    :root[data-theme="dark"]{
        --zv-ground:#0E120F; --zv-canvas:#080A08; --zv-card:#161B17; --zv-card2:#1E241F;
        --zv-ink:#EDF1EC; --zv-ink2:#9AA79E; --zv-ink3:#67736A; --zv-hair:#262C26; --zv-hair2:#313931;
        --zv-brand2:#28A25A; --zv-brand-bg:#132A1D; --zv-amber:#F5A623; --zv-amber-bg:#2C2412; --zv-sale:#FF6B5E;
        --zv-tile:#F4F6F1;
        --zv-shadow-card:0 14px 30px -18px rgba(0,0,0,.55);
    }
    .zv-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:99}
    .zv-skip:focus{inset-inline-start:0}
    *{box-sizing:border-box}
    .zv-body{margin:0;background:var(--zv-canvas);color:var(--zv-ink);font-family:var(--zv-font);-webkit-font-smoothing:antialiased}
    button{font-family:inherit}
    img{display:block;max-width:100%}
    [hidden]{display:none !important}
    :focus-visible{outline:2px solid var(--zv-brand);outline-offset:2px}

    .zevoa{display:flex;justify-content:center}
    .zv-shell{position:relative;width:100%;max-width:520px;min-height:100vh;background:var(--zv-ground);overflow-x:clip;box-shadow:0 0 0 1px var(--zv-hair)}

    /* ---------------- header / icon buttons ---------------- */
    .zv-hdr{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 20px 8px}
    .zv-hdr-title{font-size:17px;font-weight:700;letter-spacing:-.01em}
    .zv-iconbtn{width:42px;height:42px;border-radius:50%;background:var(--zv-card);border:1px solid var(--zv-hair);
        color:var(--zv-ink);display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;cursor:pointer;transition:transform .15s,background .15s}
    .zv-iconbtn:hover{background:var(--zv-card2)}
    .zv-iconbtn:active{transform:scale(.92)}
    .zv-iconbtn svg{width:19px;height:19px}
    .zv-avatar{width:44px;height:44px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
        background:var(--zv-card2);border:1px solid var(--zv-hair);color:var(--zv-ink2);flex:0 0 auto}
    .zv-avatar--lg{width:58px;height:58px}
    .zv-avatar svg{width:20px;height:20px}
    .zv-badge{position:absolute;top:-4px;inset-inline-end:-4px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;
        background:var(--zv-sale);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}

    /* ---------------- screens ---------------- */
    .zv-screen{position:relative;min-height:100vh;padding-bottom:calc(var(--zv-navh) + 10px);background:var(--zv-ground)}
    .zv-screen[hidden]{display:none}
    .zv-scroll{padding:0 20px 24px}
    .zv-haspad{padding-bottom:calc(var(--zv-navh) + 18px)}
    @keyframes zvPushIn{from{transform:translateX(3%);opacity:0}to{transform:translateX(0);opacity:1}}
    @keyframes zvPushInRtl{from{transform:translateX(-3%);opacity:0}to{transform:translateX(0);opacity:1}}
    @keyframes zvFade{from{opacity:0}to{opacity:1}}
    .zv-anim-push{animation:zvPushIn .28s cubic-bezier(.22,.9,.3,1)}
    [dir="rtl"] .zv-anim-push{animation-name:zvPushInRtl}
    .zv-anim-fade{animation:zvFade .22s ease}
    @media (prefers-reduced-motion: reduce){.zv-anim-push,.zv-anim-fade{animation:none}}

    /* ---------------- home: greeting / wallet / service grid / promo ---------------- */
    .zv-greetrow{display:flex;align-items:center;justify-content:space-between;padding:14px 20px 4px}
    .zv-who{display:flex;align-items:center;gap:11px}
    .zv-hi{font-size:12.5px;color:var(--zv-ink2)}
    .zv-nm{font-size:18px;font-weight:800;letter-spacing:-.01em}

    .zv-walletcard{position:relative;background:linear-gradient(135deg,var(--zv-brand),var(--zv-brand2));border-radius:var(--zv-radius-lg);
        padding:20px;color:#fff;margin-top:16px;box-shadow:0 18px 34px -16px color-mix(in srgb, var(--zv-brand) 60%, transparent);overflow:clip}
    .zv-walletcard::after{content:"";position:absolute;inset-inline-end:-30px;top:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.09)}
    .zv-wk{font-size:12.5px;opacity:.85;position:relative}
    .zv-wamt{font-weight:800;font-size:28px;margin-top:5px;letter-spacing:-.01em;position:relative;font-variant-numeric:tabular-nums}
    .zv-wacts{display:flex;gap:10px;margin-top:16px;position:relative}
    .zv-wact{flex:1;height:44px;border-radius:100px;background:rgba(255,255,255,.16);backdrop-filter:blur(6px);display:flex;
        align-items:center;justify-content:center;gap:6px;font-size:12.5px;font-weight:700;color:#fff;border:1px solid rgba(255,255,255,.28);
        cursor:pointer;transition:transform .15s}
    .zv-wact:active{transform:scale(.95)}
    .zv-wact svg{width:15px;height:15px}

    .zv-promoban{position:relative;border-radius:var(--zv-radius-lg);padding:20px;margin-top:18px;overflow:clip;background:var(--zv-brand-bg)}
    .zv-promoban--amber{background:var(--zv-amber-bg)}
    .zv-promok{font-size:12px;font-weight:700;letter-spacing:.02em;color:var(--zv-brand2)}
    .zv-promoban--amber .zv-promok{color:var(--zv-amber)}
    .zv-promoban h3{font-weight:800;font-size:20px;margin:6px 0 2px;color:var(--zv-ink);letter-spacing:-.01em}
    .zv-promoban p{margin:0;color:var(--zv-ink2);font-size:13px}
    .zv-shopnow{margin-top:14px;height:42px;padding:0 22px;border-radius:100px;background:var(--zv-ink);color:var(--zv-ground);
        font-weight:700;font-size:13.5px;cursor:pointer;border:0}

    .zv-sectlabel{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:28px 0 14px}
    .zv-sectlabel h2{font-size:18px;font-weight:800;margin:0;letter-spacing:-.015em;display:flex;align-items:center;gap:10px;min-width:0}
    .zv-sectlabel h2::before{content:"";width:4px;height:18px;border-radius:100px;background:var(--zv-brand);flex:0 0 auto}
    .zv-seeall{display:inline-flex;align-items:center;gap:5px;background:var(--zv-card);border:1px solid var(--zv-hair);
        color:var(--zv-ink2);font-family:inherit;font-weight:700;font-size:12px;cursor:pointer;padding:7px 13px;border-radius:100px;
        flex:0 0 auto;box-shadow:var(--zv-shadow-card);transition:transform .2s var(--zv-ease),color .2s,border-color .2s}
    .zv-seeall::after{content:"";width:6px;height:6px;border-inline-end:1.7px solid currentColor;border-block-start:1.7px solid currentColor;
        transform:rotate(45deg);opacity:.7}
    [dir="rtl"] .zv-seeall::after{transform:rotate(-135deg)}
    .zv-seeall:hover{color:var(--zv-brand2);border-color:var(--zv-brand);transform:translateY(-1px)}

    /* ---------------- supermarket reframe: home search + category tiles ---------------- */
    .zv-homesearch{margin:16px 0 2px}
    /* Category strip = the artifact's pastel rounded EMOJI discs (Lulu/Carrefour
       "shop by category"): a soft tinted square, a big product emoji, a short
       name — NOT a photo tile. Pastel rotates per position. */
    .zv-cattiles{display:flex;gap:22px;overflow-x:auto;padding:4px 2px 12px;margin:2px -2px 0;scrollbar-width:none;justify-content:flex-start}
    .zv-cattiles::-webkit-scrollbar{height:0}
    .zv-cattile{flex:0 0 auto;width:78px;display:flex;flex-direction:column;align-items:center;gap:9px;background:none;border:0;
        padding:0;cursor:pointer}
    .zv-cattile-img{width:64px;height:64px;border-radius:20px;display:grid;place-items:center;font-size:30px;line-height:1;
        background:var(--zv-card2);color:var(--zv-brand2);
        transition:transform .3s var(--zv-ease),box-shadow .3s var(--zv-ease)}
    .zv-cattile-img svg{width:28px;height:28px}
    .zv-cattile:hover .zv-cattile-img{transform:translateY(-3px);box-shadow:var(--zv-shadow-card)}
    .zv-cattile:active .zv-cattile-img{transform:translateY(-1px) scale(.97)}
    .zv-cattile:nth-child(8n+1) .zv-cattile-img{background:#E7F4EC}
    .zv-cattile:nth-child(8n+2) .zv-cattile-img{background:#FEF3DD}
    .zv-cattile:nth-child(8n+3) .zv-cattile-img{background:#F6EAD8}
    .zv-cattile:nth-child(8n+4) .zv-cattile-img{background:#E4F1FA}
    .zv-cattile:nth-child(8n+5) .zv-cattile-img{background:#F3ECFA}
    .zv-cattile:nth-child(8n+6) .zv-cattile-img{background:#FDECEA}
    .zv-cattile:nth-child(8n+7) .zv-cattile-img{background:#E9F5F3}
    .zv-cattile:nth-child(8n+8) .zv-cattile-img{background:#EEF2E6}
    .zv-cattile-name{font-size:11.5px;font-weight:600;color:var(--zv-ink);text-align:center;line-height:1.25;max-width:100%;
        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

    /* Persistent category bar attached to the header (artifact): a full-bleed
       WHITE band with a hairline under it, discs centered in the page column.
       Desktop chrome only (the mobile layout keeps its own in-screen nav). */
    /* Category bar — shown at ALL widths (the artifact has it on mobile too).
       Mobile: a compact scrollable emoji strip under the header. Desktop:
       roomier discs, centered in the page column, sticky under the nav. */
    .zv-cats{display:block;background:var(--zv-card);border-block-end:1px solid var(--zv-hair)}
    .zv-cats-inner{display:flex;gap:16px;overflow-x:auto;padding:10px clamp(12px,4vw,16px);scrollbar-width:none;justify-content:flex-start}
    .zv-cats-inner::-webkit-scrollbar{height:0}
    .zv-cats-inner .zv-cattile{width:auto;gap:7px}
    .zv-cats-inner .zv-cattile-img{width:52px;height:52px;border-radius:16px;font-size:24px}
    .zv-cats-inner .zv-cattile-name{font-size:11px;white-space:nowrap;-webkit-line-clamp:1}
    @media (min-width:1024px){
        .zv-cats{position:sticky;top:var(--zv-dtopnav-h);z-index:39}
        .zv-cats-inner{max-width:var(--zv-desk-max);margin-inline:auto;padding-inline:var(--zv-desk-pad);gap:30px;padding-block:13px}
        .zv-cats-inner .zv-cattile-img{width:58px;height:58px;border-radius:18px;font-size:27px}
        .zv-cats-inner .zv-cattile-name{font-size:12px}
    }

    /* flash-deal countdown pill (urgency) */
    .zv-flashtimer{font-size:12.5px;font-weight:800;color:#fff;background:var(--zv-sale);border-radius:100px;padding:5px 12px;
        font-variant-numeric:tabular-nums;letter-spacing:.02em;flex:0 0 auto}

    /* coupon / savings strip */
    .zv-couponban{position:relative;display:flex;align-items:center;gap:14px;margin-top:20px;padding:18px 20px;
        border-radius:var(--zv-radius-lg);overflow:clip;background:linear-gradient(120deg,var(--zv-brand2),var(--zv-brand));color:#fff}
    .zv-couponban-burst{position:absolute;inset-inline-end:-6px;top:-24px;font-size:104px;font-weight:800;opacity:.14;line-height:1;pointer-events:none}
    .zv-couponban-copy{flex:1;min-width:0;position:relative}
    .zv-couponban-kick{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.92}
    .zv-couponban-title{font-size:18px;font-weight:800;line-height:1.15;margin-top:3px;letter-spacing:-.01em}
    .zv-couponcode{flex:0 0 auto;position:relative;background:#fff;color:var(--zv-brand2);font-weight:800;font-size:13.5px;
        letter-spacing:.08em;padding:9px 14px;border-radius:10px;border:2px dashed color-mix(in srgb, var(--zv-brand2) 40%, #fff)}

    .zv-hrow{display:flex;gap:14px;overflow-x:auto;padding:2px 2px 10px;margin:0 -2px;scrollbar-width:thin}
    .zv-hrow .zv-pcard{width:162px;flex:0 0 auto}
    /* carousel arrows over the rails (Lulu/Carrefour-style prev/next) */
    .zv-railwrap{position:relative}
    .zv-railnav{position:absolute;top:35%;transform:translateY(-50%);width:44px;height:44px;border-radius:50%;background:var(--zv-card);
        border:1px solid var(--zv-hair);box-shadow:var(--zv-shadow-card);color:var(--zv-ink);display:grid;place-items:center;z-index:6;cursor:pointer;
        transition:background .2s,color .2s,border-color .2s,opacity .2s}
    .zv-railnav:hover{background:var(--zv-brand);color:#fff;border-color:var(--zv-brand)}
    .zv-railnav svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2.4}
    .zv-railnav.prev{inset-inline-end:-14px}
    .zv-railnav.next{inset-inline-start:-14px}
    .zv-railnav[disabled]{opacity:0;pointer-events:none}
    @media (hover:none),(max-width:640px){.zv-railnav{display:none}}

    /* ---------------- product grid / card ----------------
       Matched to the reference hypermarkets (Carrefour / Lulu): a BRIGHT,
       crisp, dense tile — product-on-white image, a thin hairline for
       definition (not a moody float), tight body, a prominent size line and a
       bold price. Energy comes from clarity + colour signals (red sale, brand
       add-button), not from soft shadows. */
    .zv-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:6px}
    .zv-pcard{position:relative;border-radius:14px;overflow:clip;background:var(--zv-card);border:1px solid var(--zv-hair);
        display:flex;flex-direction:column;transition:border-color .2s var(--zv-ease),box-shadow .2s var(--zv-ease)}
    @media (hover:hover){.zv-pcard:hover{border-color:var(--zv-hair2);box-shadow:var(--zv-shadow-card)}}
    .zv-pcard:active{transform:scale(.99)}
    /* Packshot-on-radial-disc (the reference / artifact signature): a tinted
       radial ground + a soft brand disc behind a product image rendered
       CONTAINED (not a full-bleed moody photo). This is what makes the tile
       read "bright product-forward", not "lifestyle stock photo". */
    .zv-imgwrap{position:relative;aspect-ratio:1/1;overflow:clip;display:grid;place-items:center;
        background:radial-gradient(115% 92% at 50% 14%,var(--zv-card2) 0%,var(--zv-card) 74%)}
    .zv-imgwrap::before{content:"";position:absolute;width:66%;aspect-ratio:1;border-radius:50%;pointer-events:none;
        background:radial-gradient(circle at 38% 32%,color-mix(in srgb,var(--zv-brand) 22%,transparent),color-mix(in srgb,var(--zv-brand) 6%,transparent) 70%)}
    .zv-imgwrap img{position:relative;z-index:1;width:80%;height:80%;object-fit:contain;
        filter:drop-shadow(0 12px 15px rgba(16,24,20,.16));transition:transform .4s var(--zv-ease)}
    @media (hover:hover){.zv-pcard:hover .zv-imgwrap img{transform:scale(1.06) rotate(-3deg)}}
    .zv-img-ph{position:relative;z-index:1;width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--zv-ink3)}
    .zv-card-media-link{display:grid;place-items:center;width:100%;height:100%}
    .zv-card-media-link:focus-visible{outline:2px solid var(--zv-brand);outline-offset:-2px}
    .zv-disc{position:absolute;top:8px;inset-inline-start:8px;background:var(--zv-sale);color:#fff;font-size:11px;font-weight:800;
        padding:3px 8px;border-radius:7px;z-index:2;letter-spacing:.01em}
    .zv-soldout{position:absolute;top:8px;inset-inline-start:8px;background:var(--zv-ink);color:var(--zv-ground);font-size:11px;
        font-weight:800;padding:3px 8px;border-radius:7px;z-index:2}
    .zv-heart{position:absolute;top:7px;inset-inline-end:7px;width:28px;height:28px;border-radius:50%;border:0;padding:0;cursor:pointer;
        display:flex;align-items:center;justify-content:center;background:color-mix(in srgb,#fff 82%,transparent);color:var(--zv-ink3);
        z-index:2;transition:color .18s}
    .zv-heart:hover{color:var(--zv-sale)}
    .zv-heart svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2}
    .zv-heart[aria-pressed="true"]{color:var(--zv-sale)}
    .zv-heart[aria-pressed="true"] svg{fill:var(--zv-sale)}
    .zv-heart.is-pop{animation:zvHeartPop .4s}
    @keyframes zvHeartPop{0%{transform:scale(1)}35%{transform:scale(1.3)}100%{transform:scale(1)}}
    /* Circular quick-add at the image corner — the reference pattern. */
    .zv-addbag{position:absolute;bottom:8px;inset-inline-end:8px;width:34px;height:34px;border-radius:50%;
        background:var(--zv-brand);color:#fff;display:flex;align-items:center;justify-content:center;
        z-index:3;border:0;cursor:pointer;box-shadow:0 4px 12px -3px color-mix(in srgb,var(--zv-brand) 60%,transparent);
        transition:transform .18s var(--zv-ease),background .18s}
    .zv-addbag:hover{background:var(--zv-brand2)}
    .zv-addbag:active{transform:scale(.86)}
    .zv-addbag:disabled{opacity:.4;cursor:not-allowed;box-shadow:none}
    .zv-addbag svg{width:18px;height:18px}
    .zv-pbody{padding:11px 12px 12px;display:flex;flex-direction:column;gap:2px;flex:1}
    .zv-pbrand{font-size:10px;color:var(--zv-ink3);text-transform:uppercase;letter-spacing:.05em;font-weight:700}
    .zv-pname{font-size:13px;font-weight:600;line-height:1.32;color:var(--zv-ink);display:-webkit-box;-webkit-line-clamp:2;
        -webkit-box-orient:vertical;overflow:clip;min-height:34px}
    .zv-punit{font-size:12px;color:var(--zv-ink2);margin-top:2px;font-weight:500}
    .zv-stock{font-size:10.5px;font-weight:700;margin-top:3px}
    .zv-stock--low{color:#b45309}
    .zv-stock--in{color:#1a8f4c}
    .zv-stock--out{color:var(--zv-sale)}
    .zv-prow{display:flex;align-items:baseline;gap:7px;margin-top:auto;padding-top:8px;flex-wrap:wrap}
    .zv-price{font-weight:800;font-size:18px;color:var(--zv-ink);font-variant-numeric:tabular-nums;letter-spacing:-.02em}
    .zv-price--was{font-weight:500;font-size:12px;color:var(--zv-ink3);text-decoration:line-through;letter-spacing:0}

    /* ---------------- store screen ---------------- */
    .zv-locrow{display:flex;align-items:center;gap:10px}
    .zv-lic{width:36px;height:36px;border-radius:50%;background:var(--zv-brand-bg);color:var(--zv-brand2);display:flex;
        align-items:center;justify-content:center;flex:0 0 auto}
    .zv-lic svg{width:17px;height:17px}
    .zv-loclabel{font-size:11px;color:var(--zv-ink2)}
    .zv-locval{font-size:14.5px;font-weight:700;display:flex;align-items:center;gap:3px}
    .zv-locval svg{width:14px;height:14px;color:var(--zv-ink3)}

    .zv-searchrow{margin:16px 0 14px;position:relative}
    .zv-search-ic{position:absolute;inset-inline-start:18px;top:50%;transform:translateY(-50%);color:var(--zv-ink3);pointer-events:none;display:flex}
    .zv-search-ic svg{width:19px;height:19px}
    .zv-search{width:100%;height:52px;border-radius:100px;background:var(--zv-card);border:1px solid var(--zv-hair);
        padding-inline-start:48px;padding-inline-end:18px;color:var(--zv-ink);font-size:14.5px;font-family:inherit}
    .zv-search::placeholder{color:var(--zv-ink3)}
    .zv-search:focus{outline:2px solid var(--zv-brand);outline-offset:1px}

    /* crave-parity: status row (open-now + branch) */
    .zv-statusrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0 2px}
    .zv-openchip{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:#2f9e63;
        background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:100px;padding:6px 12px}
    .zv-openchip i{width:7px;height:7px;border-radius:50%;background:#2f9e63;flex:none}
    .zv-openchip.is-closed{color:#e2445c}
    .zv-openchip.is-closed i{background:#e2445c}
    .zv-metachip{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--zv-ink2);
        background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:100px;padding:6px 12px;box-shadow:var(--zv-shadow-card)}
    .zv-metachip svg{width:14px;height:14px;color:var(--zv-brand2);flex:0 0 auto}
    .zv-metachip--free{color:var(--zv-brand2);background:var(--zv-brand-bg);border-color:transparent}
    .zv-branchsel{position:relative;display:inline-flex;align-items:center;height:32px;border-radius:100px;
        border:1px solid var(--zv-hair);background:var(--zv-card);color:var(--zv-ink);cursor:pointer}
    .zv-branchsel svg{position:absolute;inset-inline-start:10px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--zv-brand);pointer-events:none}
    .zv-branchsel::after{content:"";position:absolute;inset-inline-end:11px;top:50%;width:6px;height:6px;
        border-inline-end:1.5px solid var(--zv-ink3);border-bottom:1.5px solid var(--zv-ink3);transform:translateY(-65%) rotate(45deg);pointer-events:none}
    .zv-branchsel select{-webkit-appearance:none;appearance:none;border:0;background:transparent;color:inherit;
        font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;outline:none;height:100%;padding-inline-start:30px;padding-inline-end:24px;max-width:170px}
    .zevoa .zv-branchsel select:focus-visible{outline:none}
    .zv-branchsel:focus-within{outline:2px solid var(--zv-brand);outline-offset:2px}

    /* crave-parity: promo banners */
    .zv-banners{margin:14px 0 4px}
    .zv-banner-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
    .zv-banner-track::-webkit-scrollbar{height:0}
    .zv-banner{flex:0 0 88%;scroll-snap-align:center;position:relative;min-height:150px;aspect-ratio:16/8;border-radius:var(--zv-radius-lg);overflow:hidden;
        display:flex;align-items:flex-end;padding:20px 22px;text-decoration:none;background:var(--zv-brand);color:#fff;box-shadow:var(--zv-shadow-card)}
    .zv-banner:nth-child(even){background:var(--zv-ink)}
    .zv-banner-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:1}
    /* legibility scrim — kept light so the product photo stays bright (a real
       hypermarket banner is bright), just enough to seat the copy at the base */
    .zv-banner::after{content:"";position:absolute;inset:0;z-index:0;background:linear-gradient(to top,rgba(8,12,9,.6) 0%,rgba(8,12,9,.12) 34%,transparent 62%)}
    .zv-banner-copy{position:relative;display:flex;flex-direction:column;gap:5px;z-index:1;max-width:78%}
    .zv-banner-kick{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.14em;opacity:.95}
    .zv-banner-title{font-size:23px;font-weight:800;line-height:1.12;letter-spacing:-.02em;text-shadow:0 2px 12px rgba(0,0,0,.3)}
    .zv-banner-dots{display:flex;gap:6px;justify-content:center;margin-top:9px}
    .zv-banner-dots i{width:6px;height:6px;border-radius:50%;background:var(--zv-hair);transition:width .2s,background .2s}
    .zv-banner-dots i.on{background:var(--zv-brand);width:16px;border-radius:100px}
    .zv-brand-select{width:100%;height:48px;border-radius:100px;background:var(--zv-card);border:1px solid var(--zv-hair);
        padding:0 18px;color:var(--zv-ink);font-size:13.5px;font-family:inherit;margin-bottom:10px}

    .zv-chips{display:flex;gap:9px;overflow-x:auto;padding:2px 20px 6px;margin:6px -20px 0;scrollbar-width:thin}
    .zv-chip{flex:0 0 auto;height:38px;padding:0 18px;border-radius:100px;background:var(--zv-card);border:1px solid var(--zv-hair);
        font-size:13.5px;font-weight:600;color:var(--zv-ink);display:flex;align-items:center;gap:7px;cursor:pointer;transition:transform .12s}
    .zv-chip:active{transform:scale(.95)}
    .zv-chip.is-active{background:var(--zv-ink);color:var(--zv-ground);border-color:var(--zv-ink)}

    .zv-hoursban{display:flex;align-items:center;gap:9px;background:var(--zv-brand-bg);color:var(--zv-brand2);border-radius:100px;
        padding:11px 16px;font-size:12.5px;font-weight:700;margin:4px 0 16px}
    .zv-hoursban svg{width:16px;height:16px;flex:0 0 auto}
    .zv-etaban{display:flex;align-items:center;gap:9px;background:var(--zv-amber-bg);color:var(--zv-amber);border-radius:100px;
        padding:11px 16px;font-size:12.5px;font-weight:700;margin:16px 0 0;justify-content:center}
    .zv-etaban svg{width:16px;height:16px;flex:0 0 auto}
    .zv-empty-text{padding:50px 10px;text-align:center;color:var(--zv-ink2)}
    /* Catalog breadcrumb + head (title · count · sort) — artifact catalog. */
    .zv-crumb{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--zv-ink2);margin:2px 0 14px}
    .zv-crumb button{background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer}
    .zv-crumb button:hover{color:var(--zv-brand2)}
    .zv-crumb .sep{color:var(--zv-ink3)}
    .zv-crumb b{color:var(--zv-ink);font-weight:600}
    .zv-catmain-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px}
    .zv-catmain-head h1{font-size:22px;font-weight:800;letter-spacing:-.02em;margin:0;display:flex;align-items:baseline;gap:10px}
    .zv-catmain-head h1 .zv-cnt2{font-size:13px;color:var(--zv-ink2);font-weight:500}
    .zv-sortsel{height:40px;border-radius:100px;border:1px solid var(--zv-hair);background:var(--zv-card);color:var(--zv-ink);
        font:inherit;font-size:13px;font-weight:600;padding:0 16px;cursor:pointer}

    /* ---------------- tabbar ---------------- */
    .zv-tabbar{position:sticky;bottom:0;left:0;right:0;height:var(--zv-navh);background:var(--zv-card);border-block-start:1px solid var(--zv-hair);
        display:flex;align-items:center;justify-content:space-around;padding:0 14px 8px;z-index:50;max-width:520px;margin:0 auto}
    .zv-tab{display:flex;flex-direction:column;align-items:center;gap:4px;font-size:11px;color:var(--zv-ink3);font-weight:600;flex:1;
        cursor:pointer;background:none;border:0}
    .zv-tab svg{width:22px;height:22px}
    .zv-tab[aria-current="true"]{color:var(--zv-brand)}
    .zv-fab{width:56px;height:56px;border-radius:50%;background:var(--zv-brand);color:#fff;display:flex;align-items:center;
        justify-content:center;margin-top:-26px;box-shadow:0 14px 26px -8px color-mix(in srgb, var(--zv-brand) 75%, transparent);
        transition:transform .15s;flex:0 0 auto;position:relative;border:0;cursor:pointer}
    .zv-fab:active{transform:scale(.9)}
    .zv-fab svg{width:24px;height:24px}
    .zv-fab.is-bump{animation:zvBump .3s ease}
    @keyframes zvBump{0%{transform:scale(1)}50%{transform:scale(1.22)}100%{transform:scale(1)}}

    /* ---------------- product screen ---------------- */
    .zv-pd-img{position:relative;border-radius:var(--zv-radius-lg);overflow:clip;aspect-ratio:1/1;margin-top:6px;border:1px solid var(--zv-hair);
        background:radial-gradient(120% 95% at 50% 12%,var(--zv-card2),var(--zv-card) 76%);
        display:flex;align-items:center;justify-content:center;padding:26px}
    .zv-pd-img::before{content:"";position:absolute;width:58%;aspect-ratio:1;border-radius:50%;pointer-events:none;
        background:radial-gradient(circle at 38% 30%,color-mix(in srgb,var(--zv-brand) 20%,transparent),transparent 70%)}
    .zv-pd-img img{position:relative;z-index:1;width:100%;height:100%;object-fit:contain;filter:drop-shadow(0 18px 24px rgba(16,24,20,.18))}
    .zv-pd-img .zv-disc{top:14px;inset-inline-start:14px;font-size:12px;padding:4px 10px}
    .zv-pd-thumbs{display:flex;gap:9px;margin-top:12px;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:2px;scrollbar-width:thin}
    .zv-pd-thumbs::-webkit-scrollbar{height:0}
    .zv-pd-thumb{flex:0 0 auto;width:62px;height:62px;border-radius:12px;overflow:clip;border:2px solid transparent;padding:0;cursor:pointer;background:var(--zv-tile)}
    .zv-pd-thumb img{width:100%;height:100%;object-fit:cover;display:block}
    .zv-pd-thumb[aria-current="true"]{border-color:var(--zv-brand)}
    .zv-pd-title{font-size:22px;font-weight:800;line-height:1.15;letter-spacing:-.01em;margin:18px 0 0}
    .zv-pd-unit{font-size:13px;color:var(--zv-ink2);margin-top:3px}
    .zv-pd-prices{display:flex;align-items:baseline;gap:10px;margin-top:10px}
    .zv-pd-price{font-size:21px;font-weight:800;font-variant-numeric:tabular-nums}
    .zv-pd-desc{color:var(--zv-ink2);font-size:13.5px;line-height:1.55;margin:12px 0 0}
    /* red "you save" badge beside the price (artifact .pd-save) */
    .zv-pd-save{background:var(--zv-sale-bg,#FDECEA);color:var(--zv-sale);font-size:12px;font-weight:800;padding:4px 10px;border-radius:8px;white-space:nowrap}
    /* assurances block (artifact .pd-assure): delivery / returns / secure / fresh */
    .zv-pd-assure{margin-top:20px;display:flex;flex-direction:column;border:1px solid var(--zv-hair);border-radius:var(--zv-radius);overflow:clip}
    .zv-assure{display:flex;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--zv-hair);font-size:13px}
    .zv-assure:last-child{border-bottom:0}
    .zv-assure svg{width:20px;height:20px;stroke:var(--zv-brand2);fill:none;stroke-width:1.8;flex:0 0 auto}
    .zv-assure b{font-weight:700}.zv-assure span{color:var(--zv-ink2)}
    .zv-group{margin-top:20px}
    .zv-group-label{font-size:13px;font-weight:700;letter-spacing:.01em;margin:0 0 10px}
    .zv-opts{display:flex;flex-wrap:wrap;gap:8px}
    .zv-opt{border:1px solid var(--zv-hair2);background:var(--zv-card);color:var(--zv-ink);border-radius:8px;padding:8px 13px;
        font-size:13.5px;cursor:pointer}
    .zv-opt.is-active{border-color:var(--zv-brand);background:color-mix(in srgb, var(--zv-brand) 14%, transparent);font-weight:600}
    .zv-opt:disabled{opacity:.4;text-decoration:line-through;cursor:not-allowed}
    .zv-attrs{width:100%;border-collapse:collapse;font-size:13.5px}
    .zv-attrs td{padding:7px 0;border-block-end:1px solid var(--zv-hair)}
    .zv-attrs td:first-child{color:var(--zv-ink2);padding-inline-end:10px;width:40%}
    .zv-agegate{border:1px solid var(--zv-sale);background:color-mix(in srgb, var(--zv-sale) 8%, transparent);border-radius:10px;
        padding:12px;font-size:13px;margin-top:16px}
    .zv-agegate-actions{display:flex;gap:8px;margin-top:10px}

    .zv-qtyrow{display:flex;align-items:center;justify-content:space-between;background:var(--zv-card);border:1px solid var(--zv-hair);
        border-radius:var(--zv-radius);padding:14px 16px;margin-top:20px}
    .zv-ql{font-weight:600;font-size:14.5px}
    .zv-stepper{display:flex;align-items:center;gap:6px}
    .zv-step{width:30px;height:30px;border-radius:9px;border:1px solid var(--zv-hair2);background:var(--zv-card);color:var(--zv-ink);
        display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;cursor:pointer}
    .zv-step:disabled{opacity:.4;cursor:not-allowed}
    .zv-qty{min-width:22px;text-align:center;font-weight:700;font-variant-numeric:tabular-nums}

    .zv-actionbar{position:sticky;bottom:0;left:0;right:0;padding:14px 20px calc(14px + env(safe-area-inset-bottom));
        background:linear-gradient(to top,var(--zv-ground) 72%,transparent);display:flex;gap:12px;z-index:30;max-width:520px;margin:0 auto}
    .zv-btn{height:56px;border-radius:100px;font-size:15px;font-weight:700;display:flex;align-items:center;justify-content:center;
        gap:9px;border:0;cursor:pointer;transition:transform .14s;padding:0 20px}
    .zv-btn:active{transform:scale(.98)}
    .zv-btn:disabled{opacity:.5;cursor:not-allowed}
    .zv-btn--brand{background:var(--zv-brand);color:var(--zv-brand-ink);flex:1}
    .zv-btn--ghost{background:var(--zv-card);color:var(--zv-ink);border:1px solid var(--zv-hair2);flex:1}
    .zv-btn--block{width:100%}
    .zv-btn svg{width:19px;height:19px}

    /* ---------------- cart screen ---------------- */
    .zv-citem{background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);padding:14px;margin-top:14px;
        box-shadow:var(--zv-shadow-card)}
    .zv-citem .zv-top{display:flex;gap:12px;align-items:flex-start}
    .zv-cthumb{width:64px;height:64px;border-radius:12px;background:var(--zv-tile);flex:0 0 auto;overflow:clip;border:1px solid var(--zv-hair)}
    .zv-cthumb img{width:100%;height:100%;object-fit:cover}
    .zv-cinfo{flex:1;min-width:0}
    .zv-cname{font-weight:700;font-size:14.5px;line-height:1.3}
    .zv-ccol{font-size:12px;color:var(--zv-ink2);margin-top:2px}
    .zv-cprice{font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
    .zv-cfoot{display:flex;align-items:center;justify-content:space-between;border-block-start:1px solid var(--zv-hair);margin-top:12px;padding-top:12px}
    .zv-cremove{background:none;border:0;color:var(--zv-sale);font-size:12.5px;font-weight:600;cursor:pointer;padding:0}

    .zv-nb-proxy{position:fixed;top:0;inset-inline-start:0;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;opacity:0;pointer-events:none;border:0}
    .zv-notif-dot{position:absolute;top:7px;inset-inline-end:8px;width:9px;height:9px;border-radius:50%;background:var(--zv-brand);border:2px solid var(--zv-card);box-sizing:content-box}
    .zv-notif-dot[hidden]{display:none}
    .mb-nb-card{--nb-surface:var(--zv-card);--nb-surface-2:var(--zv-hair);--nb-ink:var(--zv-ink);--nb-muted:var(--zv-ink3);--nb-line:var(--zv-hair);--nb-accent:var(--zv-brand);--nb-radius:18px}
    /* zevoa scopes an opacity rule that beats the shared panel's own is-open — re-assert it */
    .zevoa .mb-nb-panel{opacity:0}
    .zevoa .mb-nb-panel.is-open{opacity:1}
    .zv-giftcardbox{margin-top:12px}
    .zv-coupon-input{width:100%;height:48px;border-radius:12px;border:1px solid var(--zv-hair);background:var(--zv-card);padding:0 16px;font:inherit;font-size:14px;color:var(--zv-ink)}
    .zv-summary{background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);padding:18px;margin-top:18px;
        box-shadow:var(--zv-shadow-card)}
    .zv-summary h3{font-weight:800;font-size:17px;margin:0 0 12px}
    .zv-srow{display:flex;justify-content:space-between;font-size:14px;color:var(--zv-ink2);padding:6px 0;font-variant-numeric:tabular-nums}
    .zv-srow--total{color:var(--zv-ink);font-weight:800;font-size:16px;border-block-start:1px solid var(--zv-hair);margin-top:8px;padding-top:12px}
    .zv-promo{display:flex;gap:8px;margin-top:12px}
    .zv-promo input{flex:1;height:46px;border-radius:100px;border:1px solid var(--zv-hair2);background:var(--zv-card2);padding:0 18px;
        font-family:inherit;font-size:14px;color:var(--zv-ink)}
    .zv-promo input::placeholder{color:var(--zv-ink3)}
    .zv-promo button{height:46px;padding:0 20px;border-radius:100px;background:var(--zv-ink);color:var(--zv-ground);font-weight:700;
        font-size:14px;flex:0 0 auto;border:0;cursor:pointer}

    .zv-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;padding:56px 24px;color:var(--zv-ink2)}
    .zv-eico{width:74px;height:74px;border-radius:50%;background:var(--zv-card);border:1px solid var(--zv-hair);display:flex;
        align-items:center;justify-content:center;color:var(--zv-ink3)}
    .zv-eico svg{width:30px;height:30px}
    .zv-empty h3{color:var(--zv-ink);font-weight:800;font-size:18px;margin:4px 0 0}

    /* ---------------- checkout screen ---------------- */
    .zv-sect{margin-top:18px}
    .zv-label{font-size:13px;font-weight:700;letter-spacing:.01em;margin:0 0 10px}
    .zv-field{display:block;width:100%;margin-bottom:12px}
    .zv-field label{display:block;font-size:12.5px;color:var(--zv-ink2);margin-bottom:5px}
    .zv-field input{width:100%;height:48px;padding:0 14px;border-radius:12px;border:1px solid var(--zv-hair2);background:var(--zv-card);
        color:var(--zv-ink);font-size:14.5px;font-family:inherit}
    .zv-addr{position:relative}
    .zv-addr input{padding-inline-end:42px}
    .zv-addr-loc{position:absolute;inset-inline-end:7px;top:7px;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;
        border:0;border-radius:10px;background:var(--zv-card2);color:var(--zv-ink2);cursor:pointer;transition:background .15s,color .15s}
    .zv-addr-loc:hover{background:var(--zv-brand-bg);color:var(--zv-brand)}
    .zv-addr-loc.is-loading{opacity:.5;pointer-events:none}
    .zv-addr-loc svg{width:19px;height:19px}
    .zv-addr-suggest{position:absolute;inset-inline:0;top:calc(100% + 6px);z-index:40;background:var(--zv-card);
        border:1px solid var(--zv-hair);border-radius:14px;box-shadow:0 18px 34px -16px rgba(0,0,0,.28);overflow:hidden;max-height:264px;overflow-y:auto}
    .zv-addr-suggest .mb-addr-opt{display:block;width:100%;text-align:start;padding:12px 15px;background:none;border:0;
        border-block-end:1px solid var(--zv-hair);color:var(--zv-ink);font-size:14px;font-family:inherit;cursor:pointer}
    .zv-addr-suggest .mb-addr-opt:last-child{border-block-end:0}
    .zv-addr-suggest .mb-addr-opt:hover{background:var(--zv-card2)}
    .zv-optcard{width:100%;background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);padding:15px 16px;
        display:flex;align-items:center;gap:13px;margin-bottom:10px;transition:border-color .15s;text-align:start;cursor:pointer}
    .zv-optcard[aria-pressed="true"]{border-color:var(--zv-brand)}
    .zv-oico{width:40px;height:40px;border-radius:10px;background:var(--zv-card2);display:flex;align-items:center;justify-content:center;
        flex:0 0 auto;color:var(--zv-ink)}
    .zv-oico svg{width:20px;height:20px}
    .zv-obody{flex:1;min-width:0}
    .zv-ot{font-weight:700;font-size:14.5px}
    .zv-os{font-size:12.5px;color:var(--zv-ink2);margin-top:1px}
    .zv-radio{width:22px;height:22px;border-radius:50%;border:2px solid var(--zv-hair2);flex:0 0 auto;position:relative}
    .zv-optcard[aria-pressed="true"] .zv-radio{border-color:var(--zv-brand)}
    .zv-optcard[aria-pressed="true"] .zv-radio::after{content:"";position:absolute;inset:4px;border-radius:50%;background:var(--zv-brand)}
    .zv-slots{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0}
    .zv-msg{font-size:13.5px;padding:10px 12px;border-radius:10px;margin-bottom:12px}
    .zv-msg--error{background:color-mix(in srgb, var(--zv-sale) 12%, transparent);color:var(--zv-sale)}
    .zv-msg--ok{background:color-mix(in srgb, var(--zv-brand) 14%, transparent);color:var(--zv-brand2)}
    .zv-msg--info{background:var(--zv-card2);color:var(--zv-ink2)}

    /* ---------------- confirm screen ---------------- */
    .zv-confwrap{display:flex;flex-direction:column;align-items:center;text-align:center;padding:36px 20px 24px}
    .zv-checkc{width:88px;height:88px}
    .zv-checkc circle{fill:none;stroke:var(--zv-brand);stroke-width:4;stroke-dasharray:283;stroke-dashoffset:283;animation:zvDraw .6s ease forwards}
    .zv-checkc path{fill:none;stroke:var(--zv-ink);stroke-width:5;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;
        stroke-dashoffset:60;animation:zvDraw .4s .5s ease forwards}
    @keyframes zvDraw{to{stroke-dashoffset:0}}
    .zv-confwrap h2{font-weight:800;font-size:23px;margin:16px 0 4px;letter-spacing:-.01em}
    .zv-confwrap p{color:var(--zv-ink2);font-size:14px;margin:0}
    .zv-orderno{margin-top:8px;font-weight:700;letter-spacing:.03em;color:var(--zv-ink2);font-size:13.5px;font-variant-numeric:tabular-nums}
    .zv-track{width:100%;margin-top:20px;background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);
        padding:18px 18px 6px;text-align:start;box-shadow:var(--zv-shadow-card)}
    .zv-tprogress{height:6px;border-radius:100px;background:var(--zv-hair);overflow:clip;margin:2px 0 20px}
    .zv-tprogress i{display:block;height:100%;background:var(--zv-brand);border-radius:100px;width:0%;transition:width .8s ease}
    .zv-tstep{display:flex;gap:14px;position:relative;padding-bottom:22px}
    .zv-tstep:last-child{padding-bottom:6px}
    .zv-tdot{width:26px;height:26px;border-radius:50%;flex:0 0 auto;display:flex;align-items:center;justify-content:center;
        border:2px solid var(--zv-hair2);background:var(--zv-card);color:var(--zv-ink3);z-index:2}
    .zv-tdot svg{width:13px;height:13px}
    .zv-tstep::before{content:"";position:absolute;inset-inline-start:12px;top:26px;bottom:0;width:2px;background:var(--zv-hair2)}
    .zv-tstep:last-child::before{display:none}
    .zv-tstep.is-done .zv-tdot{background:var(--zv-brand);border-color:var(--zv-brand);color:#fff}
    .zv-tstep.is-done::before{background:var(--zv-brand)}
    .zv-tstep.is-active .zv-tdot{border-color:var(--zv-brand);color:var(--zv-brand);animation:zvPulse 1.6s infinite}
    @keyframes zvPulse{0%{box-shadow:0 0 0 0 color-mix(in srgb, var(--zv-brand) 40%, transparent)}70%{box-shadow:0 0 0 8px transparent}100%{box-shadow:0 0 0 0 transparent}}
    .zv-tt{font-weight:700;font-size:14.5px}
    .zv-td{font-size:12.5px;color:var(--zv-ink2);margin-top:1px}

    .zv-drivercard{display:flex;align-items:center;gap:12px;background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);
        padding:14px;margin-top:16px;box-shadow:var(--zv-shadow-card);width:100%;text-align:start}
    .zv-davatar{width:52px;height:52px;border-radius:50%;flex:0 0 auto;border:1px solid var(--zv-hair);background:var(--zv-card2);
        display:flex;align-items:center;justify-content:center;color:var(--zv-ink2)}
    .zv-davatar svg{width:24px;height:24px}
    .zv-dinfo{flex:1;min-width:0}
    .zv-dname{font-weight:700;font-size:15px}
    .zv-dsub{font-size:12px;color:var(--zv-ink2);margin-top:1px}
    .zv-dacts{display:flex;gap:8px}
    .zv-dbtn{width:40px;height:40px;border-radius:50%;background:var(--zv-brand-bg);color:var(--zv-brand2);display:flex;
        align-items:center;justify-content:center;transition:transform .15s;text-decoration:none}
    .zv-dbtn:active{transform:scale(.88)}
    .zv-dbtn svg{width:18px;height:18px}

    .zv-ratebox{margin-top:16px;background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);padding:18px;
        text-align:center;width:100%}
    .zv-rt{font-weight:700;font-size:14.5px}
    .zv-stars{display:flex;justify-content:center;gap:8px;margin-top:12px}
    .zv-stars button{width:32px;height:32px;color:var(--zv-hair2);transition:transform .15s;background:none;border:0;cursor:pointer;padding:0}
    .zv-stars button:active{transform:scale(.82)}
    .zv-stars button.is-on{color:var(--zv-amber)}
    .zv-stars svg{width:28px;height:28px;fill:currentColor;stroke:none}

    /* ---------------- activity screen ---------------- */
    .zv-actcard{background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);padding:16px;margin-top:14px;
        box-shadow:var(--zv-shadow-card);display:block;text-decoration:none;color:inherit}
    .zv-actcard .zv-actwhen{font-size:12px;color:var(--zv-ink2)}
    .zv-actcard .zv-acttitle{font-weight:700;font-size:14.5px;margin-top:4px}
    .zv-actcard .zv-actlink{color:var(--zv-brand2);font-weight:700;font-size:13px;margin-top:8px;display:inline-block}
    .zv-order-actions{display:flex;gap:8px;margin-top:10px}
    .zv-order-btn{flex:1;text-align:center;height:38px;line-height:38px;border-radius:100px;font-size:13px;font-weight:700;
        text-decoration:none;color:var(--zv-ink);background:var(--zv-card);border:1px solid var(--zv-hair);cursor:pointer;
        padding:0;font-family:inherit;transition:background .15s,border-color .15s}
    .zv-order-btn:hover{background:var(--zv-card2)}
    .zv-order-btn--p{background:var(--zv-brand);color:var(--zv-brand-ink);border-color:var(--zv-brand)}
    .zv-order-btn--p:hover{background:var(--zv-brand2);border-color:var(--zv-brand2)}
    .zv-order-btn--full{flex:none;width:100%}

    /* ---------------- account screen ---------------- */
    .zv-profile{display:flex;align-items:center;gap:14px;background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);
        padding:16px;margin-top:8px;box-shadow:var(--zv-shadow-card)}
    .zv-pn{font-weight:800;font-size:18px}
    .zv-pe{font-size:13px;color:var(--zv-ink2)}
    .zv-acclist{margin-top:16px}
    .zv-accrow{display:flex;align-items:center;gap:14px;padding:15px 4px;border-block-end:1px solid var(--zv-hair);width:100%;
        text-align:start;background:none;border-inline:0;border-block-start:0;cursor:pointer;color:inherit}
    .zv-aico{width:38px;height:38px;border-radius:10px;background:var(--zv-card2);display:flex;align-items:center;justify-content:center;color:var(--zv-ink)}
    .zv-aico svg{width:18px;height:18px}
    .zv-at{flex:1;font-weight:600;font-size:15px}
    .zv-chev{color:var(--zv-ink3);display:flex}
    .zv-chev svg{width:18px;height:18px}

    /* ---------------- toast / fly-to-cart ---------------- */
    .zv-toast{position:fixed;left:50%;bottom:calc(var(--zv-navh) + 20px);transform:translateX(-50%) translateY(20px);background:var(--zv-ink);
        color:var(--zv-ground);padding:12px 20px;border-radius:100px;font-size:13.5px;font-weight:600;opacity:0;
        transition:opacity .25s,transform .25s;z-index:200;white-space:nowrap;display:flex;align-items:center;gap:8px;pointer-events:none}
    [dir="rtl"] .zv-toast{transform:translateX(50%) translateY(20px)}
    .zv-toast.is-show{opacity:1;transform:translateX(-50%) translateY(0)}
    [dir="rtl"] .zv-toast.is-show{transform:translateX(50%) translateY(0)}
    .zv-fly{position:fixed;z-index:999;width:48px;height:56px;border-radius:12px;overflow:clip;pointer-events:none;
        box-shadow:0 12px 24px -8px rgba(0,0,0,.35);background:var(--zv-tile)}
    .zv-fly img{width:100%;height:100%;object-fit:cover}

    /* ================================================================
       CURATED CORE FEATURES (loop/enrich) — ADD-ONLY, reuses every --zv-*
       token above so dark mode / RTL need no extra overrides here either.
       ================================================================ */

    /* ---------------- stories rail (home) ---------------- */
    .zv-stories{display:flex;gap:14px;overflow-x:auto;padding:4px 2px 8px;margin:14px -2px 0;scrollbar-width:thin}
    .zv-story-bubble{flex:0 0 auto;width:68px;display:flex;flex-direction:column;align-items:center;gap:6px;
        background:none;border:0;padding:0;cursor:pointer;transition:transform .15s}
    .zv-story-bubble:active{transform:scale(.94)}
    .zv-story-ring{width:60px;height:60px;border-radius:50%;padding:2.5px;flex:0 0 auto;
        background:linear-gradient(135deg,var(--zv-brand),var(--zv-amber));display:flex;align-items:center;justify-content:center}
    .zv-story-bubble.is-viewed .zv-story-ring{background:var(--zv-hair2)}
    .zv-story-ring img{width:100%;height:100%;border-radius:50%;object-fit:cover;border:2px solid var(--zv-ground)}
    .zv-story-cap{font-size:10.5px;font-weight:600;color:var(--zv-ink2);max-width:100%;overflow:hidden;
        text-overflow:ellipsis;white-space:nowrap}

    /* ---------------- story viewer (full-screen overlay) ---------------- */
    .zv-viewer{position:fixed;inset:0;background:#000;z-index:500;display:flex;flex-direction:column}
    .zv-viewer[hidden]{display:none}
    .zv-viewer-bars{display:flex;gap:5px;padding:12px 12px 0;position:relative;z-index:3}
    .zv-viewer-bar{flex:1;height:3px;border-radius:100px;background:rgba(255,255,255,.35);overflow:clip}
    .zv-viewer-bar i{display:block;height:100%;width:0%;background:#fff;border-radius:100px}
    .zv-viewer-bar.is-done i{width:100% !important}
    .zv-viewer-close{position:absolute;top:16px;inset-inline-end:14px;z-index:4;width:36px;height:36px;border-radius:50%;
        background:rgba(0,0,0,.4);color:#fff;border:0;display:flex;align-items:center;justify-content:center;cursor:pointer}
    .zv-viewer-close svg{width:18px;height:18px}
    .zv-viewer-media{position:relative;flex:1;display:flex;align-items:center;justify-content:center;overflow:clip;min-height:0}
    .zv-viewer-media img{max-width:100%;max-height:100%;object-fit:contain}
    .zv-viewer-caption{position:absolute;inset-inline:0;bottom:0;padding:22px 20px calc(64px + env(safe-area-inset-bottom));
        background:linear-gradient(to top,rgba(0,0,0,.75),transparent);color:#fff;font-size:14px;line-height:1.4}
    .zv-viewer-caption[hidden]{display:none}
    .zv-viewer-cta{position:absolute;bottom:calc(18px + env(safe-area-inset-bottom));inset-inline-start:50%;transform:translateX(-50%);
        background:#fff;color:#111;font-weight:700;font-size:13.5px;padding:11px 26px;border-radius:100px;text-decoration:none;z-index:3;white-space:nowrap}
    [dir="rtl"] .zv-viewer-cta{transform:translateX(50%)}
    .zv-viewer-navzone{position:absolute;inset-block:0;width:35%;background:transparent;border:0;z-index:2;cursor:pointer}
    .zv-viewer-navzone--prev{inset-inline-start:0}
    .zv-viewer-navzone--next{inset-inline-end:0}

    /* ---------------- reviews (product screen) ---------------- */
    .zv-reviews{margin-top:4px}
    .zv-rv-summary{display:flex;gap:22px;align-items:center;padding:14px 0 8px}
    .zv-rv-avgcol{display:flex;flex-direction:column;align-items:center;flex:0 0 auto;min-width:78px}
    .zv-rv-avgnum{font-size:34px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
    .zv-rv-avgstars{display:flex;gap:2px;margin-top:2px;color:var(--zv-amber)}
    .zv-rv-avgstars svg{width:14px;height:14px;fill:currentColor;stroke:none}
    .zv-rv-avgcount{font-size:11.5px;color:var(--zv-ink2);margin-top:4px;text-align:center}
    .zv-rv-dist{flex:1;display:flex;flex-direction:column;gap:6px;min-width:0}
    .zv-rv-bar{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--zv-ink2)}
    .zv-rv-bar span:first-child{width:9px;text-align:end;flex:0 0 auto}
    .zv-rv-bar-track{flex:1;height:6px;border-radius:100px;background:var(--zv-hair);overflow:clip}
    .zv-rv-bar-track i{display:block;height:100%;background:var(--zv-amber);border-radius:100px}
    .zv-rv-bar span:last-child{width:18px;flex:0 0 auto;font-variant-numeric:tabular-nums}
    .zv-rv-list{margin-top:4px;border-block-start:1px solid var(--zv-hair)}
    .zv-rv-item{padding:14px 0;border-block-end:1px solid var(--zv-hair)}
    .zv-rv-item-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .zv-rv-item-stars{display:flex;gap:1px;color:var(--zv-amber);flex:0 0 auto}
    .zv-rv-item-stars svg{width:12px;height:12px;fill:currentColor;stroke:none}
    .zv-rv-item-name{font-weight:700;font-size:13px}
    .zv-rv-item-date{font-size:11.5px;color:var(--zv-ink3);margin-inline-start:auto}
    .zv-rv-item-body{margin:6px 0 0;font-size:13px;color:var(--zv-ink2);line-height:1.55}
    .zv-rv-empty{padding:20px 0 8px;color:var(--zv-ink2);font-size:13.5px}
    .zv-rv-form{margin-top:18px;padding-top:16px;border-block-start:1px solid var(--zv-hair)}
    .zv-stars--pick{justify-content:flex-start;margin-bottom:14px}
    .zv-rv-avgstars svg,.zv-rv-item-stars svg{opacity:.3}
    .zv-rv-avgstars svg.is-full,.zv-rv-item-stars svg.is-full{opacity:1}
    .zv-sectlabel--sm h2{font-size:14.5px}
    .zv-field textarea{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--zv-hair2);background:var(--zv-card);
        color:var(--zv-ink);font-size:14.5px;font-family:inherit;resize:vertical;min-height:76px}

    /* ---------------- loyalty: rewards card (account) ---------------- */
    .zv-rewardscard{position:relative;background:linear-gradient(135deg,var(--zv-brand),var(--zv-brand2));border-radius:var(--zv-radius-lg);
        padding:20px;color:#fff;margin-top:16px;box-shadow:0 18px 34px -16px color-mix(in srgb, var(--zv-brand) 60%, transparent);overflow:clip}
    .zv-rewardscard::after{content:"";position:absolute;inset-inline-end:-30px;bottom:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.09)}
    .zv-rewardscard-head{display:flex;align-items:center;gap:12px;position:relative}
    .zv-rewardscard-badge{width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;
        align-items:center;justify-content:center;flex:0 0 auto}
    .zv-rewardscard-badge svg{width:20px;height:20px}
    .zv-rewardscard-title{font-weight:800;font-size:15.5px}
    .zv-rewardscard-sub{font-size:12.5px;opacity:.9;margin-top:2px}
    .zv-rewardscard-reward{margin-top:12px;font-size:13px;background:rgba(255,255,255,.16);border-radius:10px;padding:10px 12px;position:relative}
    .zv-tierrow{display:flex;gap:8px;margin-top:14px;position:relative;flex-wrap:wrap}
    .zv-tierchip{font-size:11px;font-weight:700;padding:6px 12px;border-radius:100px;background:rgba(255,255,255,.14);opacity:.6}
    .zv-tierchip.is-current{opacity:1;background:#fff;color:var(--zv-brand2)}
    .zv-rewardscard-hint{margin-top:12px;font-size:12px;opacity:.9;position:relative}

    /* ---------------- loyalty: redeem toggle (checkout) ---------------- */
    .zv-swrow{display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--zv-card);
        border:1px solid var(--zv-hair);border-radius:var(--zv-radius);padding:14px 16px}
    .zv-switch{width:46px;height:28px;border-radius:100px;background:var(--zv-hair2);border:0;padding:2px;cursor:pointer;
        flex:0 0 auto;transition:background .15s;display:inline-flex;align-items:center}
    .zv-switch[aria-checked="true"]{background:var(--zv-brand)}
    .zv-switch-knob{width:24px;height:24px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);
        transition:transform .15s;display:block}
    .zv-switch[aria-checked="true"] .zv-switch-knob{transform:translateX(18px)}
    [dir="rtl"] .zv-switch[aria-checked="true"] .zv-switch-knob{transform:translateX(-18px)}

    /* ================================================================
       DESKTOP RESPONSIVE LAYER — ADD-ONLY (see the shared contract at
       scratchpad/loop/RESPONSIVE-SPEC.md). Nothing above this comment is
       touched: everything below only ADDS rules, either as a new,
       desktop-hidden-by-default class, or inside a min-width media query.
       Breakpoints: 768 tablet, 1024 desktop, 1280 wide.

       Every new rule below reuses the existing --zv-* custom properties
       (colors) already mirrored by the @media(prefers-color-scheme:dark)
       and :root[data-theme="dark"] blocks above, so dark mode already
       covers every new surface with zero extra color rules needed. All
       new alignment uses logical properties (inline-start/end, margin/
       padding-inline) or CSS Grid's own automatic RTL mirroring, so [dir]
       needs no extra overrides either. */
    /* Header — shown at ALL widths. Mobile: icon + pill-search + green-cart bar
       (brand and the Explore/Activity links stay desktop-only; the bottom tabbar
       carries nav on phones). Desktop (≥1024) adds brand + nav links + larger
       controls in the block below. */
    .zv-dtopnav{display:flex;position:sticky;top:0;z-index:60;background:var(--zv-card);border-block-end:1px solid var(--zv-hair)}
    .zv-dtopnav-inner{display:flex;align-items:center;gap:9px;width:100%;padding:9px clamp(12px,4vw,16px)}
    .zv-dbrand{display:none}
    .zv-dnavlinks{display:none}
    .zv-langsel{display:none}
    .zv-dsearch{position:relative;flex:1 1 auto;min-width:0}
    .zv-dsearch .zv-search-ic{position:absolute;inset-inline-start:14px;top:50%;transform:translateY(-50%);color:var(--zv-ink3);pointer-events:none;display:flex}
    .zv-dsearch .zv-search-ic svg{width:18px;height:18px}
    .zv-dsearch-input{width:100%;height:44px;border-radius:100px;background:var(--zv-card2);border:1px solid var(--zv-hair);padding-inline-start:42px;padding-inline-end:16px;color:var(--zv-ink);font-family:inherit;font-size:14px}
    .zv-dsearch-input::placeholder{color:var(--zv-ink3)}
    .zv-dsearch-input:focus{outline:none;border-color:var(--zv-brand)}
    .zv-dactions{display:flex;align-items:center;gap:7px;flex:0 0 auto}
    .zv-dactions .zv-iconbtn{width:40px;height:40px;border-radius:12px;background:var(--zv-card2);border:1px solid transparent;color:var(--zv-ink);display:flex;align-items:center;justify-content:center;position:relative}
    .zv-dactions .zv-iconbtn svg{width:19px;height:19px}
    .zv-dactions .zv-dcartbtn{background:var(--zv-brand);color:#fff}
    /* Header pill search now shows at all widths → the in-body home/store search
       fields and the store phone header (location + its own bell) are redundant
       everywhere; hide globally so mobile shows ONE search, no stray bell. */
    .zv-homesearch,#zvScreenHome > .zv-scroll > .zv-statusrow,#zvScreenStore .zv-scroll > .zv-searchrow,#zvScreenStore > .zv-hdr{display:none}
    .zv-store-sidebar{display:none}

    /* ---------------- tablet (≥768px): breathing room, no new chrome ---------------- */
    @media (min-width:768px){
        .zv-shell{max-width:var(--zv-tablet-max)}
        .zv-tabbar{max-width:var(--zv-tablet-max)}
        .zv-actionbar{max-width:var(--zv-tablet-max)}
        .zv-grid{grid-template-columns:repeat(3,1fr)}
    }

    /* ---------------- desktop (≥1024px): real top nav + multi-column screens ---------------- */
    @media (min-width:1024px){
        /* Shell becomes full-bleed desktop chrome; .zv-content is the real
           centered column every screen renders inside (kills the "phone
           card floating in grey" look on a wide monitor). */
        .zv-shell{max-width:none;box-shadow:none}
        .zv-content{max-width:var(--zv-desk-max);margin-inline:auto;padding-inline:var(--zv-desk-pad)}
        .zv-screen{padding-bottom:24px}
        .zv-haspad{padding-bottom:24px}

        .zv-dtopnav{
            display:flex;position:sticky;top:0;z-index:60;height:var(--zv-dtopnav-h);
            background:var(--zv-card);border-block-end:1px solid var(--zv-hair);
        }
        .zv-dtopnav-inner{
            display:flex;align-items:center;gap:24px;width:100%;height:100%;
            max-width:var(--zv-desk-max);margin-inline:auto;padding-inline:var(--zv-desk-pad);
        }
        .zv-dbrand{display:flex;align-items:center;gap:10px;background:none;border:0;cursor:pointer;color:var(--zv-ink);padding:0;flex:0 0 auto}
        /* Filled green brand mark (artifact) — not a pale tinted tile. */
        .zv-dbrand-mark{width:42px;height:42px;border-radius:13px;background:var(--zv-brand);color:#fff;display:flex;align-items:center;justify-content:center;flex:0 0 auto;box-shadow:0 6px 14px -6px var(--zv-brand)}
        .zv-dbrand-mark svg{width:22px;height:22px;stroke:#fff}
        .zv-dbrand-name{font-weight:700;font-size:17px;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}
        .zv-dnavlinks{display:flex;align-items:center;gap:4px;flex:0 0 auto}
        .zv-dlink{background:none;border:0;font:inherit;font-weight:600;font-size:14px;color:var(--zv-ink2);cursor:pointer;padding:9px 12px;border-radius:100px;transition:background .15s,color .15s;white-space:nowrap}
        .zv-dlink:hover{background:var(--zv-card2);color:var(--zv-ink)}
        .zv-dlink.is-active{color:var(--zv-brand2);background:var(--zv-brand-bg)}
        .zv-dsearch{position:relative;flex:1 1 360px;max-width:560px;margin-inline:auto}
        .zv-dsearch-input{height:48px;border-radius:100px;background:var(--zv-card2)}
        .zv-dactions{display:flex;align-items:center;gap:8px;flex:0 0 auto}
        /* Artifact icon tiles: rounded squares on a soft tint, not pale circles. */
        .zv-dactions .zv-iconbtn{width:46px;height:46px;border-radius:14px;background:var(--zv-card2);border-color:transparent}
        .zv-dactions .zv-iconbtn:hover{background:var(--zv-brand-bg);color:var(--zv-brand2)}
        /* Prominent green cart (artifact) */
        .zv-dactions .zv-dcartbtn{background:var(--zv-brand);color:#fff}
        .zv-dactions .zv-dcartbtn:hover{background:var(--zv-brand2);color:#fff}
        .zv-langsel{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--zv-hair);border-radius:100px;padding:6px 12px;color:var(--zv-ink2);background:var(--zv-card)}
        .zv-langsel svg{width:16px;height:16px}
        .zv-langsel select{border:0;background:transparent;color:var(--zv-ink);font:inherit;font-size:.8rem;font-weight:700;cursor:pointer}
        .zv-langsel select:focus{outline:none}
        .zv-dcartbtn{position:relative}
        .zv-tabbar{display:none}

        /* Sticky bottom CTA bars no longer pinned to a 520px phone column —
           each hugs the same column its screen's "primary" content sits in. */
        #zvScreenProduct .zv-actionbar{max-width:var(--zv-desk-buybox);margin-inline:auto 0}
        #zvScreenCheckout .zv-actionbar{max-width:var(--zv-desk-side);margin-inline:auto 0}
        #zvScreenConfirm .zv-actionbar,
        #zvScreenConfirm .zv-confwrap{max-width:560px;margin-inline:auto}

        /* -------- home (supermarket reframe): single full-width column of
           stacked sections (search, hero banners, category tiles, deal +
           popular carousels), each carousel expanding into a wide product
           grid at desktop — no more phone-width scroll rails on a monitor. -------- */
        #zv-main .zv-homesearch{max-width:640px}
        /* Hero fills the full content width as one clean slide (no phone-style
           peek), at a fixed cinematic height instead of a 16:8 ratio that would
           be absurdly tall across a 1500px column. */
        #zv-main .zv-banner{flex-basis:100%;aspect-ratio:auto;height:360px;padding:32px 40px}
        #zv-main .zv-banner-title{font-size:32px}
        /* Emoji-disc strip: a centered single row (artifact posture), roomier
           discs on the wider canvas. */
        .zv-cattiles{gap:26px;overflow-x:auto;padding:8px 0 14px;justify-content:flex-start}
        .zv-cattile{width:92px}
        .zv-cattile-img{width:78px;height:78px;border-radius:22px;font-size:30px}
        .zv-cattile-img svg{width:30px;height:30px}
        .zv-cattile-name{font-size:13px}
        /* Home carousels stay single-row rails, larger cards to match the width. */
        #zv-main .zv-hrow{gap:16px}
        #zv-main .zv-hrow .zv-pcard{width:210px}
        .zv-promoban{padding:28px 32px}
        .zv-promoban h3{font-size:24px}

        /* -------- store: sticky left category sidebar + wide product grid -------- */
        #zvScreenStore{display:grid;grid-template-columns:240px 1fr;column-gap:28px;align-items:start}
        #zvScreenStore .zv-scroll{padding-inline:0}
        #zvScreenStore .zv-chips{display:none}
        .zv-store-sidebar{display:block;grid-column:1;align-self:start;position:sticky;top:calc(var(--zv-dtopnav-h) + 20px)}
        .zv-store-sidebar-inner{background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);padding:16px;box-shadow:var(--zv-shadow-card)}
        .zv-sidebar-title{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--zv-ink2);margin:0 0 10px;padding:0 4px}
        .zv-store-sidebar .zv-chip{display:flex;width:100%;height:auto;justify-content:flex-start;text-align:start;padding:10px 14px;border-radius:10px;margin-bottom:4px;background:transparent;border-color:transparent}
        .zv-store-sidebar .zv-chip.is-active{background:var(--zv-brand-bg);color:var(--zv-brand2);border-color:transparent}
        .zv-brand-select{max-width:280px}
        /* Catalog sidebar panels (artifact): departments+counts, brand checks, price range. */
        .zv-panel{background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);padding:16px;margin-bottom:14px}
        .zv-panel h4{margin:0 0 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--zv-ink2)}
        .zv-catlink{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;padding:9px 12px;border:0;background:none;border-radius:10px;
            font-size:13.5px;font-weight:600;color:var(--zv-ink);text-align:start;cursor:pointer;margin-bottom:2px}
        .zv-catlink:hover{background:var(--zv-card2)}
        .zv-catlink.is-active{background:var(--zv-brand-bg);color:var(--zv-brand2)}
        .zv-catlink .zv-cnt{font-size:11.5px;color:var(--zv-ink3);font-weight:500}
        .zv-catlink.is-active .zv-cnt{color:var(--zv-brand2)}
        .zv-check{display:flex;align-items:center;gap:9px;padding:6px 2px;font-size:13px;color:var(--zv-ink);cursor:pointer}
        .zv-check input{width:17px;height:17px;accent-color:var(--zv-brand);flex:0 0 auto}
        .zv-range{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--zv-ink2);font-variant-numeric:tabular-nums}
        .zv-range input{flex:1;accent-color:var(--zv-brand)}

        /* -------- product / goods grid: 2 (mobile) → 3 (@768, above) → 4 here -------- */
        .zv-grid{grid-template-columns:repeat(4,1fr)}

        /* -------- product detail: sticky image left, buy-box right -------- */
        #zvScreenProduct .zv-scroll{display:grid;grid-template-columns:minmax(320px,460px) 1fr;column-gap:40px;align-items:start}
        #zvScreenProduct .zv-pd-img{grid-column:1;grid-row:1/span 15;align-self:start;margin-top:0;position:sticky;top:calc(var(--zv-dtopnav-h) + 20px)}

        /* -------- cart: line items left, sticky order-summary right -------- */
        #zvCartView{display:grid;grid-template-columns:1fr var(--zv-desk-side);column-gap:32px;align-items:start}
        #zvCartSummary{grid-column:2;grid-row:1/span 10;align-self:start;margin-top:0;position:sticky;top:calc(var(--zv-dtopnav-h) + 20px)}

        /* -------- checkout: form left, sticky order-summary right -------- */
        #zvScreenCheckout .zv-scroll{display:grid;grid-template-columns:1fr var(--zv-desk-side);column-gap:32px;align-items:start}
        #zvScreenCheckout .zv-summary{grid-column:2;grid-row:1/span 10;align-self:start;margin-top:0;position:sticky;top:calc(var(--zv-dtopnav-h) + 20px)}

        /* -------- account / activity: settings-style narrower centered column -------- */
        #zvScreenAccount .zv-scroll,
        #zvScreenActivity .zv-scroll{max-width:640px;margin-inline:auto}
    }

    /* ---------------- wide (≥1280px) ---------------- */
    @media (min-width:1280px){
        .zv-grid{grid-template-columns:repeat(5,1fr)}
        #zv-main .zv-hrow .zv-pcard{width:220px}
    }
    @media (min-width:1600px){
        .zv-grid{grid-template-columns:repeat(6,1fr)}
    }

    /* ══ value props (home) ══ */
    .zv-values{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:30px}
    .zv-vp{display:flex;align-items:center;gap:13px;background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius);padding:16px 18px}
    .zv-vp-ic{width:44px;height:44px;border-radius:13px;background:var(--zv-brand-bg);color:var(--zv-brand2);display:grid;place-items:center;flex:0 0 auto}
    .zv-vp-ic svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:1.8}
    .zv-vp b{display:block;font-size:13.5px;font-weight:700}.zv-vp span{font-size:11.5px;color:var(--zv-ink2)}
    @media (max-width:760px){.zv-values{grid-template-columns:1fr 1fr}}@media (max-width:440px){.zv-values{grid-template-columns:1fr}}

    /* ══ footer (sidalih-style, full-bleed) ══ */
    .zv-footer{border-top:1px solid var(--zv-hair);background:var(--zv-card);color:var(--zv-ink2);margin-top:40px}
    .zv-footer-in{max-width:var(--zv-desk-max,1240px);margin-inline:auto;padding-inline:clamp(16px,3.5vw,40px)}
    .zv-ft-grid{display:grid;grid-template-columns:1.2fr 1fr 1.5fr 1.2fr;gap:38px;padding-block:44px}
    .zv-ft-h{color:var(--zv-ink);font-weight:700;font-size:15px;margin:0 0 16px}
    .zv-ft-links{display:flex;flex-direction:column}
    .zv-ft-links button{background:none;border:0;font:inherit;text-align:start;padding:7px 0;font-size:13.5px;color:var(--zv-ink2);cursor:pointer;transition:color .15s}
    .zv-ft-links button:hover{color:var(--zv-brand2)}
    .zv-ft-news p{font-size:13px;line-height:1.7;margin:0 0 14px}
    .zv-news-field{position:relative;max-width:340px}
    .zv-news-field input{width:100%;height:50px;border-radius:14px;border:1.5px solid var(--zv-hair);background:var(--zv-card2);padding-inline:16px 56px;font:inherit;font-size:13.5px;color:var(--zv-ink)}
    .zv-news-field input:focus{outline:none;border-color:var(--zv-brand)}
    .zv-news-field button{position:absolute;inset-inline-start:6px;top:6px;width:38px;height:38px;border-radius:10px;border:0;background:var(--zv-brand);color:#fff;display:grid;place-items:center;cursor:pointer}
    .zv-news-field button svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2}
    .zv-ft-social{display:flex;gap:9px;margin-top:18px}
    .zv-ft-social a{width:38px;height:38px;border-radius:50%;background:var(--zv-card2);color:var(--zv-ink2);display:grid;place-items:center;transition:background .2s,color .2s}
    .zv-ft-social a:hover{background:var(--zv-brand);color:#fff}.zv-ft-social svg{width:17px;height:17px;fill:currentColor}
    .zv-ft-contact{margin-top:20px;display:flex;flex-direction:column;gap:12px}
    .zv-ft-contact a{display:flex;align-items:center;gap:11px;font-size:13.5px;color:var(--zv-ink)}
    .zv-ft-contact a:hover{color:var(--zv-brand2)}
    .zv-ft-contact .cic{width:36px;height:36px;border-radius:10px;background:var(--zv-brand-bg);color:var(--zv-brand2);display:grid;place-items:center;flex:0 0 auto}
    .zv-ft-contact .cic svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.8}.zv-ft-contact span{direction:ltr}
    .zv-ft-apps{display:flex;flex-direction:column;gap:12px;max-width:200px}
    .zv-appbadge{display:flex;align-items:center;gap:11px;background:#111;color:#fff;border-radius:13px;padding:9px 16px;transition:transform .18s}
    .zv-appbadge:hover{transform:translateY(-2px)}.zv-appbadge svg{width:26px;height:26px;fill:#fff;flex:0 0 auto}
    .zv-appbadge .ab{display:flex;flex-direction:column;line-height:1.15;text-align:start}
    .zv-appbadge .ab small{font-size:9.5px;opacity:.8}.zv-appbadge .ab b{font-size:15px;font-weight:600}
    .zv-ft-pays{border-top:1px solid var(--zv-hair);padding-block:20px;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}
    .zv-pay{height:34px;min-width:52px;padding:0 10px;border-radius:8px;background:var(--zv-card);border:1px solid var(--zv-hair);display:grid;place-items:center;font-size:10.5px;font-weight:800;color:var(--zv-ink)}
    .zv-pay.mada{color:#0a9b4e}.zv-pay.visa{color:#1a1f71}.zv-pay.mc{color:#eb001b}.zv-pay.stc{color:#4f008c}.zv-pay.tabby{background:#3cceb6;border-color:#3cceb6;color:#053b34}
    .zv-ft-bottom{border-top:1px solid var(--zv-hair)}
    .zv-ft-bottom-in{max-width:var(--zv-desk-max,1240px);margin-inline:auto;padding-inline:clamp(16px,3.5vw,40px);padding-block:18px;display:flex;flex-direction:column;gap:12px;font-size:12.5px}
    .zv-ft-brow{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
    .zv-ft-brand{display:flex;align-items:center;gap:9px;color:var(--zv-ink);font-weight:700}
    .zv-ft-brand svg{width:17px;height:17px;stroke:var(--zv-brand);fill:none;stroke-width:1.9}
    .zv-ft-legal{display:flex;gap:18px}.zv-ft-legal button{background:none;border:0;font:inherit;color:var(--zv-ink2);cursor:pointer}.zv-ft-legal button:hover{color:var(--zv-brand2)}
    .zv-ft-compliance{color:var(--zv-ink3);font-size:12px;line-height:1.8;text-align:center;border-top:1px solid var(--zv-hair);padding-top:13px}
    .zv-ft-compliance b{color:var(--zv-ink2);font-weight:600}
    @media (max-width:900px){.zv-ft-grid{grid-template-columns:1fr 1fr;gap:30px}}
    @media (max-width:540px){.zv-ft-grid{grid-template-columns:1fr;gap:26px;padding-block:32px}.zv-news-field,.zv-ft-apps{max-width:none}.zv-ft-brow{flex-direction:column;text-align:center;gap:10px}.zv-ft-legal{justify-content:center}}

    /* ══ utility bar (green delivery strip, global top) ══ */
    .zv-utilbar{background:var(--zv-brand2);color:#EAF7EF}
    .zv-utilbar-in{max-width:var(--zv-desk-max,1240px);margin-inline:auto;padding-inline:clamp(16px,3.5vw,40px);display:flex;align-items:center;gap:20px;height:38px;font-size:12.5px;font-weight:500}
    .zv-utilbar b{color:#fff;font-weight:700}
    .zv-utilbar .u{display:inline-flex;align-items:center;gap:7px}
    .zv-utilbar svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:1.8;opacity:.9}
    .zv-utilbar .spacer{margin-inline-start:auto}
    @media (max-width:640px){.zv-utilbar .u--hide{display:none}.zv-utilbar-in{font-size:11.5px;gap:12px}}

    /* ══ 2-up promo hero (home) ══ */
    .zv-hero2{display:grid;grid-template-columns:1.7fr 1fr;gap:16px;margin-top:16px}
    .zv-promo{position:relative;border-radius:var(--zv-radius-lg);overflow:hidden;min-height:220px;display:flex;padding:26px 30px;color:#fff;border:0;cursor:pointer;text-align:start;font:inherit;transition:transform .25s var(--zv-ease,ease),box-shadow .25s}
    .zv-promo:hover{transform:translateY(-2px);box-shadow:var(--zv-shadow-card)}
    .zv-promo--main{background:linear-gradient(135deg,#0C6E3B,#159A55 55%,#37B86E);align-items:center}
    .zv-promo-emoji{position:absolute;inset-inline-end:20px;bottom:4px;font-size:118px;line-height:1;filter:drop-shadow(0 12px 18px rgba(0,0,0,.25));transform:rotate(-8deg)}
    .zv-promo-copy{position:relative;z-index:1;max-width:66%}
    .zv-promo-eyebrow{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.1em;background:rgba(255,255,255,.18);padding:5px 12px;border-radius:100px;margin-bottom:12px}
    .zv-promo h3{margin:0;font-size:30px;font-weight:800;line-height:1.08;letter-spacing:-.02em}
    .zv-promo p{margin:8px 0 0;font-size:13.5px;opacity:.92;max-width:24ch}
    .zv-promo-cta{margin-top:18px;display:inline-flex;align-items:center;gap:8px;background:#fff;color:var(--zv-brand2);font-weight:700;font-size:13px;padding:10px 20px;border-radius:100px}
    .zv-promo-cta svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2.4}
    .zv-promo-side{display:grid;grid-template-rows:1fr 1fr;gap:16px}
    .zv-promo--sm{min-height:0;padding:18px 22px;flex-direction:column;justify-content:center}
    .zv-promo--amber{background:linear-gradient(135deg,#D98207,#F5A623)}
    .zv-promo--berry{background:linear-gradient(135deg,#B3253C,#E23B32)}
    .zv-promo--sm .zv-promo-emoji{font-size:70px;inset-inline-end:12px;bottom:-4px}
    .zv-promo--sm h3{font-size:19px}.zv-promo--sm .zv-promo-copy{max-width:74%}.zv-promo--sm .zv-promo-eyebrow{margin-bottom:8px}
    @media (max-width:860px){.zv-hero2{grid-template-columns:1fr}.zv-promo--main{min-height:180px}.zv-promo h3{font-size:24px}.zv-promo-side{grid-template-rows:none;grid-template-columns:1fr 1fr}}

    /* ══ "Shop by department" — 4 bold gradient banner cards (artifact .feat) ══ */
    .zv-feat{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
    .zv-fcard{position:relative;overflow:clip;border-radius:16px;min-height:120px;padding:18px 22px;color:#fff;border:0;cursor:pointer;
        display:flex;flex-direction:column;justify-content:center;text-align:start;transition:transform .25s var(--zv-ease),box-shadow .25s var(--zv-ease)}
    .zv-fcard:hover{transform:translateY(-3px);box-shadow:var(--zv-shadow-hover)}
    .zv-fcard .zv-fe{position:absolute;inset-inline-end:10px;bottom:-8px;font-size:62px;line-height:1;opacity:.95;filter:drop-shadow(0 8px 12px rgba(0,0,0,.22))}
    .zv-fcard .zv-flabel{font-size:16px;font-weight:800;position:relative;z-index:1;line-height:1.2}
    .zv-fcard .zv-foff{font-size:12.5px;font-weight:600;position:relative;z-index:1;opacity:.95;margin-top:5px}
    .zv-fcard.c1{background:linear-gradient(135deg,#0C6E3B,#22A25C)}
    .zv-fcard.c2{background:linear-gradient(135deg,#B54708,#F5A623)}
    .zv-fcard.c3{background:linear-gradient(135deg,#175CD3,#42A5F5)}
    .zv-fcard.c4{background:linear-gradient(135deg,#9B1C2E,#E23B32)}
    @media (max-width:760px){.zv-feat{grid-template-columns:1fr 1fr}}
    @media (max-width:520px){.zv-promo-side{grid-template-columns:1fr}}

    /* ---------- account sub-screens: settings / addresses / payments / help ---------- */
    .zv-setgroup{margin-top:22px}
    .zv-setgroup:first-child{margin-top:8px}
    .zv-setlabel{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--zv-ink3);margin-bottom:11px}
    .zv-segwrap{display:flex;gap:8px;flex-wrap:wrap}
    .zv-seg{flex:1;min-width:72px;text-align:center;padding:11px 12px;border-radius:12px;border:1px solid var(--zv-hair);
        background:var(--zv-card);color:var(--zv-ink2);font:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:background .15s,border-color .15s,color .15s}
    .zv-seg.is-on{background:var(--zv-brand);border-color:var(--zv-brand);color:#fff}
    .zv-setrow{display:flex;align-items:center;gap:14px;width:100%;padding:15px 16px;border:1px solid var(--zv-hair);border-radius:14px;
        background:var(--zv-card);color:var(--zv-ink);font:inherit;text-align:start;cursor:pointer}
    .zv-setrow .zv-at{flex:1;font-size:14.5px;font-weight:600}
    .zv-setrow--danger{color:var(--zv-sale,#e23b32)}
    .zv-setrow .zv-switch{position:relative;flex:0 0 auto}
    .zv-setrow .zv-switch::after{content:"";position:absolute;top:2px;inset-inline-start:2px;width:24px;height:24px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .15s}
    .zv-setrow[aria-checked="true"] .zv-switch{background:var(--zv-brand)}
    .zv-setrow[aria-checked="true"] .zv-switch::after{transform:translateX(18px)}
    [dir="rtl"] .zv-setrow[aria-checked="true"] .zv-switch::after{transform:translateX(-18px)}
    .zv-sethint{font-size:12.5px;color:var(--zv-ink3);margin:9px 2px 0;line-height:1.5}
    .zv-input{width:100%;padding:13px 15px;border:1px solid var(--zv-hair);border-radius:12px;background:var(--zv-card);color:var(--zv-ink);
        font:inherit;font-size:14.5px;margin-bottom:10px;resize:vertical}
    .zv-input:focus{outline:none;border-color:var(--zv-brand)}
    .zv-input::placeholder{color:var(--zv-ink3)}
    .zv-btn--block{width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px}
    .zv-addrlist{display:flex;flex-direction:column;gap:12px}
    .zv-addrcard{border:1px solid var(--zv-hair);border-radius:14px;padding:14px 16px;background:var(--zv-card)}
    .zv-addrcard-top{display:flex;align-items:center;gap:8px;margin-bottom:5px}
    .zv-addrcard-label{font-weight:800;font-size:14.5px;color:var(--zv-ink)}
    .zv-addrcard-def{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--zv-brand);background:var(--zv-brand-bg);padding:3px 8px;border-radius:100px}
    .zv-addrcard-text{font-size:13.5px;color:var(--zv-ink2);line-height:1.5}
    .zv-addrcard-phone{font-size:12.5px;color:var(--zv-ink3);margin-top:4px;direction:ltr}
    .zv-addrcard-acts{display:flex;gap:16px;margin-top:11px}
    .zv-addrcard-acts button{background:none;border:0;font:inherit;font-size:12.5px;font-weight:700;color:var(--zv-ink2);cursor:pointer;padding:0}
    .zv-addrcard-acts button.zv-del{color:var(--zv-sale,#e23b32)}
    .zv-addrform{margin-top:14px;border-top:1px solid var(--zv-hair);padding-top:16px}
    .zv-addrform-actions{display:flex;gap:10px;margin-top:4px}
    .zv-addrform-actions .zv-btn{flex:1}
    .zv-paylist{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .zv-paynote{display:flex;align-items:flex-start;gap:12px;margin-top:20px;padding:14px 16px;border-radius:14px;background:var(--zv-card2);border:1px solid var(--zv-hair)}
    .zv-paynote p{font-size:13px;color:var(--zv-ink2);line-height:1.55;margin:0}
    .zv-paynote .zv-aico{flex:0 0 auto;color:var(--zv-brand)}
    .zv-helpintro{font-size:14px;color:var(--zv-ink2);line-height:1.6;margin:2px 2px 18px}
    .zv-accrow--static{cursor:default}
    .zv-atsub{font-size:13px;color:var(--zv-ink3);font-weight:600;margin-inline-start:auto}
    .zv-mapbtn{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;border:1px dashed var(--zv-hair2);border-radius:12px;background:var(--zv-card);color:var(--zv-ink2);font:inherit;font-size:14px;font-weight:700;cursor:pointer;margin-bottom:10px}
    .zv-mapbtn svg{width:18px;height:18px;color:var(--zv-brand)}
    .zv-mapwrap{position:fixed;inset:0;z-index:130;background:var(--zv-ground);display:flex;flex-direction:column}
    .zv-maparea{position:relative;flex:1;min-height:0}
    #zvMap{position:absolute;inset:0;background:var(--zv-canvas)}
    .zv-mappin{position:absolute;left:50%;top:50%;transform:translate(-50%,-100%);z-index:500;pointer-events:none;color:var(--zv-brand);filter:drop-shadow(0 3px 4px rgba(0,0,0,.35))}
    .zv-mappin svg{width:38px;height:38px}
    .zv-mapbar{padding:14px 16px;border-top:1px solid var(--zv-hair);background:var(--zv-card)}
    .zv-mapaddr{font-size:13.5px;color:var(--zv-ink2);line-height:1.5;margin-bottom:12px;min-height:20px}
    .leaflet-container{font:inherit}
</style>
@endpush
@push('style')
<link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
@endpush
@push('script')
<script src="{{ asset('assets/vendor/leaflet/leaflet.js') }}"></script>
@endpush
@push('style')
<style nonce="{{ csp_nonce() }}">
    .leaflet-fade-anim .leaflet-tile{will-change:auto}
</style>
@endpush

@section('content')
<div class="zevoa" id="zevoa" data-store-id="{{ $store->id }}" data-theme-slug="zevoa">
<div class="zv-shell" id="zvShell">

    {{-- ══════════ UTILITY BAR (green delivery strip, all widths) ══════════ --}}
    <div class="zv-utilbar"><div class="zv-utilbar-in">
        <span class="u"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l2.5 1.5"/></svg><b>{{ ___('Delivery in 30 min') }}</b></span>
        <span class="u u--hide"><svg viewBox="0 0 24 24"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7.5" cy="17" r="1.7"/><circle cx="16.5" cy="17" r="1.7"/></svg>{{ ___('Free delivery over :amount', ['amount' => price_symbol_format(100)]) }}</span>
        <span class="u spacer"><svg viewBox="0 0 24 24"><path d="M12 21s-7-6-7-11a7 7 0 0114 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>{{ $store->address ? \Illuminate\Support\Str::limit($store->address, 30) : ___('Choose your location') }}</span>
    </div></div>

    {{-- ══════════ DESKTOP TOP NAV (≥1024px only) — replaces the fixed
         bottom tabbar at desktop widths. Reuses the SAME `data-zv-nav` hook
         the mobile tabbar/service tiles already use, so goTo() wires every
         button below with zero JS changes; the two new lines added to
         renderCartBadge()/paintTabbar() near the bottom of the script just
         mirror state onto this bar's own cart badge / active-link class. --}}
    <header class="zv-dtopnav" id="zvDesktopNav">
        <div class="zv-dtopnav-inner">
            <button type="button" class="zv-dbrand" data-zv-nav="home" aria-label="{{ ___('Home') }}">
                <span class="zv-dbrand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h16l-1.6 11a2 2 0 0 1-2 1.8H7.6a2 2 0 0 1-2-1.8L4 8z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/></svg>
                </span>
                <span class="zv-dbrand-name">{{ $store->title }}</span>
            </button>
            <nav class="zv-dnavlinks" aria-label="{{ ___('Primary') }}">
                <button type="button" class="zv-dlink" data-zv-nav="store">{{ ___('Explore') }}</button>
                <button type="button" class="zv-dlink" data-zv-nav="activity">{{ ___('Activity') }}</button>
            </nav>
            <div class="zv-dsearch">
                <span class="zv-search-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg></span>
                <input type="search" id="zvSearchDesktop" class="zv-search zv-dsearch-input" autocomplete="off"
                       placeholder="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}"
                       aria-label="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}">
            </div>
            <div class="zv-dactions">
                @if($zvLanguages->count() > 1)
                    <label class="zv-langsel" aria-label="{{ ___('Language') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
                        <select id="zvLangSel">
                            @foreach($zvLanguages as $lang)
                                <option value="{{ $lang->code }}" data-url="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}" @if(get_lang() === $lang->code) selected @endif>{{ strtoupper($lang->code) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <script nonce="{{ csp_nonce() }}">
                        (function(){var s=document.getElementById('zvLangSel');if(!s)return;s.addEventListener('change',function(e){var o=e.target.selectedOptions[0];if(!o)return;var code=o.value;try{document.cookie='Quick_user_lang_code='+code+';path=/;max-age=31536000;samesite=lax';document.cookie='qc_lang_choice='+code+';path=/;max-age=31536000;samesite=lax';}catch(err){}window.location.assign(o.getAttribute('data-url')||window.location.href);});})();
                    </script>
                @endif
                <button type="button" class="zv-iconbtn zv-dnotif" id="zvNotifBellDesktop" aria-label="{{ ___('Notifications') }}" style="position:relative">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16v-5a6 6 0 1 0-12 0v5l-1.5 2.5h15z"/><path d="M9.5 19a2.5 2.5 0 0 0 5 0"/></svg>
                    <i class="zv-notif-dot" hidden aria-hidden="true"></i>
                </button>
                <button type="button" class="zv-iconbtn" data-zv-nav="favorites" aria-label="{{ ___('Favorites') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
                </button>
                <button type="button" class="zv-iconbtn" data-zv-nav="account" aria-label="{{ ___('Account') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
                </button>
                <button type="button" class="zv-iconbtn zv-dcartbtn" data-zv-nav="cart" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
                    <i class="zv-badge" id="zvCartBadgeDesktop" hidden>0</i>
                </button>
                <script nonce="{{ csp_nonce() }}">
                    (function(){var b=document.getElementById('zvNotifBellDesktop');if(!b)return;
                        b.addEventListener('click',function(e){e.preventDefault();var p=document.getElementById('mbNotifBell');if(p){p.click();}});})();
                </script>
            </div>
        </div>
    </header>

    {{-- Persistent CATEGORY BAR — part of the header chrome (its OWN white
         background + hairline under it), full-bleed edge-to-edge with the discs
         centered in the page column. This is the artifact's "shop by category"
         header bar, not a strip inside the grey content. --}}
    @php
        /* category emoji (matched on the ENGLISH raw name so it works in any
           display locale) + rotating pastel disc tints. Shared with the
           "shop by department" cards below (same view scope). */
        $zvCatEmojiMap = [
            'produce' => '🥬', 'fresh' => '🥬', 'dairy' => '🥛', 'fruit' => '🍎', 'veg' => '🥕',
            'pantry' => '🥫', 'snack' => '🍿', 'bakery' => '🥐', 'bread' => '🍞',
            'beverage' => '🥤', 'drink' => '🧃', 'coffee' => '☕', 'tea' => '🍵', 'water' => '💧',
            'meat' => '🥩', 'poultry' => '🍗', 'home' => '🍳', 'kitchen' => '🍳',
            'electronic' => '🎧', 'gadget' => '🎧', 'outdoor' => '🪴', 'garden' => '🌿',
            'office' => '✏️', 'stationery' => '✏️', 'care' => '🧴', 'clean' => '🧼',
        ];
        $zvCatEmoji = function ($name) use ($zvCatEmojiMap) {
            $l = mb_strtolower((string) $name);
            foreach ($zvCatEmojiMap as $kw => $em) { if (mb_strpos($l, $kw) !== false) { return $em; } }
            return '🛒';
        };
        $zvCatTints = ['#E7F4EC', '#FEF3DD', '#F6EAD8', '#E4F1FA', '#F3ECFA', '#FDECEA', '#E9F5F3', '#EEF2E6'];
    @endphp
    @if($categories->isNotEmpty())
    <nav class="zv-cats" aria-label="{{ $vocab['categories'] ?? ___('Categories') }}">
        <div class="zv-cats-inner">
            @foreach($categories as $cat)
                <button type="button" class="zv-cattile" data-zv-cat="{{ $cat->id }}">
                    <span class="zv-cattile-img" style="background:{{ $zvCatTints[$loop->index % count($zvCatTints)] }}" aria-hidden="true">{{ $zvCatEmoji($cat->getRawOriginal('name')) }}</span>
                    <span class="zv-cattile-name">{{ $cat->name }}</span>
                </button>
            @endforeach
        </div>
    </nav>
    @endif

    <div class="zv-toast" id="zvToast" role="status" aria-live="polite"></div>

    {{-- Curated core feature: full-screen story viewer overlay (opened from
         the .zv-stories rail above). Lives outside .zv-content so it always
         covers the full viewport regardless of which screen is underneath. --}}
    <div class="zv-viewer" id="zvStoryViewer" hidden role="dialog" aria-modal="true" aria-label="{{ ___('Story') }}">
        <div class="zv-viewer-bars" id="zvViewerBars"></div>
        <button type="button" class="zv-viewer-close" id="zvViewerClose" aria-label="{{ ___('Close') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
        <div class="zv-viewer-media">
            <img id="zvViewerImg" alt="" data-csp-error="hide">
            <div class="zv-viewer-caption" id="zvViewerCaption" hidden></div>
            <a href="#" class="zv-viewer-cta" id="zvViewerCta" target="_self" rel="noopener" hidden></a>
            <button type="button" class="zv-viewer-navzone zv-viewer-navzone--prev" id="zvViewerPrev" aria-label="{{ ___('Previous') }}"></button>
            <button type="button" class="zv-viewer-navzone zv-viewer-navzone--next" id="zvViewerNext" aria-label="{{ ___('Next') }}"></button>
        </div>
    </div>

    {{-- ══════════ .zv-content: the centered ≤1200px column desktop screens
         render inside (see @media (min-width:1024px) near the end of the
         style block). Below 1024px this is a plain, unstyled wrapper —
         it changes nothing about mobile/tablet rendering. --}}
    <div class="zv-content" id="zvContent">

    {{-- ══════════ HOME (super-app shell — see docblock above) ══════════ --}}
    <section class="zv-screen" id="zvScreenHome" data-zv-screen="home" data-zv-tab="home">
        <div class="zv-scroll zv-haspad" id="zv-main">
            @if($zvShowBranches)
                <div class="zv-statusrow">
                    <label class="zv-branchsel" aria-label="{{ ___('Branch') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        <select id="zvBranchSel">
                            @foreach($zvBranches as $branch)
                                <option value="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}" @if(($zvActiveBranch->id ?? null) === $branch->id) selected @endif>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            @endif

            {{-- Supermarket reframe: search-first home (real hypermarkets —
                 Lulu, Carrefour — lead with a big product search, not a wallet).
                 Focusing it jumps to the catalog screen and mirrors its value
                 into the store search box (wired in the script, same posture as
                 the desktop top-nav search). --}}
            <div class="zv-searchrow zv-homesearch">
                <span class="zv-search-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg></span>
                <input type="search" class="zv-search" id="zvHomeSearch" autocomplete="off"
                       placeholder="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}"
                       aria-label="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}">
            </div>

            {{-- 2-up promo hero (Lulu/Carrefour split banner). --}}
            <section class="zv-hero2" aria-label="{{ ___('Featured offers') }}">
                <button type="button" class="zv-promo zv-promo--main" data-zv-nav="store">
                    <span class="zv-promo-emoji" aria-hidden="true">🛒</span>
                    <div class="zv-promo-copy">
                        <span class="zv-promo-eyebrow">{{ ___('Buy more, save more') }}</span>
                        <h3>{{ ___('Up to 40% off') }}</h3>
                        <p>{{ ___('This week on hundreds of fresh essentials.') }}</p>
                        <span class="zv-promo-cta">{{ ___('Shop offers') }} <svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></span>
                    </div>
                </button>
                <div class="zv-promo-side">
                    <button type="button" class="zv-promo zv-promo--sm zv-promo--amber" data-zv-nav="store"><span class="zv-promo-emoji" aria-hidden="true">🍊</span><div class="zv-promo-copy"><span class="zv-promo-eyebrow">{{ ___('Fresh daily') }}</span><h3>{{ ___('Fruits & veg') }}</h3></div></button>
                    <button type="button" class="zv-promo zv-promo--sm zv-promo--berry" data-zv-nav="store"><span class="zv-promo-emoji" aria-hidden="true">🎟️</span><div class="zv-promo-copy"><span class="zv-promo-eyebrow">{{ ___('Online exclusive') }}</span><h3>FRESH15</h3></div></button>
                </div>
            </section>

            {{-- "Shop by department" — bold gradient banners built from the
                 store's REAL top categories (translated), each deep-links into
                 the catalog pre-filtered (data-zv-cat). --}}
            @if($categories->isNotEmpty())
            <div class="zv-sectlabel">
                <h2>{{ ___('Shop by department') }}</h2>
                <button type="button" class="zv-seeall" data-zv-nav="store">{{ ___('All departments') }} <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M6 9l6 6 6-6"/></svg></button>
            </div>
            <div class="zv-feat">
                @foreach($categories->take(4) as $cat)
                    <button type="button" class="zv-fcard c{{ $loop->index + 1 }}" data-zv-cat="{{ $cat->id }}">
                        <span class="zv-fe" aria-hidden="true">{{ $zvCatEmoji($cat->getRawOriginal('name')) }}</span>
                        <span class="zv-flabel">{{ $cat->name }}</span>
                        <span class="zv-foff">{{ count($itemsByRoot[$cat->id] ?? []) }} {{ ___('products') }}</span>
                    </button>
                @endforeach
            </div>
            @endif

            {{-- Supermarket density: FLASH DEALS strip — best discounts first,
                 with a live rolling countdown (Carrefour "عروض أقل من ٥ ريال ·
                 00:11:32"). The timer is presentational urgency (see zvFlashTimer
                 in the script); the row hides entirely when nothing is on sale. --}}
            @if($flashDeals->isNotEmpty())
            <div class="zv-sectlabel">
                <h2>{{ ___('Flash deals') }}</h2>
                <span class="zv-flashtimer" id="zvFlashTimer" aria-hidden="true">--:--:--</span>
            </div>
            <div class="zv-hrow">
                @foreach($flashDeals->take(12) as $m)
                    @include('store_templates.zevoa._partials.product-card', ['jd' => $jsItems[$m->id]])
                @endforeach
            </div>
            @endif

            {{-- Supermarket density: a themed product carousel PER AISLE (Lulu
                 "Farm Fresh / Fresh Food", Carrefour "عروض الألبان / عروض
                 اللحوم"). Each aisle's "View all" deep-links into the catalog
                 pre-filtered to that category (data-zv-cat). --}}
            @foreach($categories as $cat)
                @php $zvCatItems = collect($itemsByRoot[$cat->id] ?? []); @endphp
                @if($zvCatItems->isNotEmpty())
                <div class="zv-sectlabel">
                    <h2>{{ $cat->name }}</h2>
                    <button type="button" class="zv-seeall" data-zv-cat="{{ $cat->id }}">{{ ___('View all') }}</button>
                </div>
                <div class="zv-hrow">
                    @foreach($zvCatItems->take(10) as $m)
                        @include('store_templates.zevoa._partials.product-card', ['jd' => $jsItems[$m->id]])
                    @endforeach
                </div>
                @endif
            @endforeach

            {{-- Supermarket trust strip (fast delivery / secure pay / quality / returns). --}}
            <section class="zv-values" aria-label="{{ ___('Why shop with us') }}">
                <div class="zv-vp"><span class="zv-vp-ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l2.5 1.5"/></svg></span><div><b>{{ ___('Delivery in 30 min') }}</b><span>{{ ___('Within the city') }}</span></div></div>
                <div class="zv-vp"><span class="zv-vp-ic"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/></svg></span><div><b>{{ ___('Secure payment') }}</b><span>mada · VISA · Apple Pay</span></div></div>
                <div class="zv-vp"><span class="zv-vp-ic"><svg viewBox="0 0 24 24"><path d="M12 3l7 4v5c0 4.5-3 8-7 9-4-1-7-4.5-7-9V7z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg></span><div><b>{{ ___('Quality guaranteed') }}</b><span>{{ ___('Fresh daily') }}</span></div></div>
                <div class="zv-vp"><span class="zv-vp-ic"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 019-9 9 9 0 010 18M3 12h9M6 8l-4 4 4 4"/></svg></span><div><b>{{ ___('Easy returns') }}</b><span>{{ ___('Within 14 days') }}</span></div></div>
            </section>
        </div>
    </section>

    {{-- ══════════ STORE (ZeMart — the ONLY screen that binds to $store/$menu) ══════════ --}}
    <section class="zv-screen" id="zvScreenStore" data-zv-screen="store" data-zv-tab="store" hidden>
        <header class="zv-hdr">
            <div class="zv-locrow">
                <span class="zv-lic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s7-6 7-11a7 7 0 0 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                <div>
                    <div class="zv-loclabel">{{ ___('Delivering to') }}</div>
                    <div class="zv-locval">
                        <span>{{ $store->address ? Str::limit($store->address, 26) : ___('Set your delivery address') }}</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>
                    </div>
                </div>
            </div>
            <button type="button" class="zv-iconbtn zv-notifbell" id="zvNotifBell" aria-label="{{ ___('Notifications') }}" style="position:relative">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                <i class="zv-notif-dot" hidden aria-hidden="true"></i>
            </button>
        </header>

        {{-- ══════════ DESKTOP-ONLY (≥1024px) sticky category sidebar. Same
             `.zv-chip` / `data-cat` hook as the mobile chip row below, so the
             existing `#zvScreenStore .zv-chip` click-wiring + applyStoreFilter()
             pick these up automatically with no JS change — only one of the
             two (this sidebar or the mobile chip row) is ever visible at a
             given width. Rendered unconditionally (even with zero categories,
             it still shows "All") so the desktop 240px/1fr grid always has a
             real second column to auto-flow the rest of the screen into. --}}
        @php $zvPmin = (int) floor($items->min('price') ?? 0); $zvPmax = (int) ceil($items->max('price') ?? 0); if ($zvPmax <= $zvPmin) { $zvPmax = $zvPmin + 1; } @endphp
        <aside class="zv-store-sidebar" id="zvStoreSidebar">
            {{-- الأقسام — departments with per-category counts --}}
            <div class="zv-panel" role="tablist" aria-label="الأقسام">
                <h4>الأقسام</h4>
                <button type="button" class="zv-catlink is-active" role="tab" aria-selected="true" data-cat="all"><span>{{ ___('All') }}</span><span class="zv-cnt">{{ $items->count() }}</span></button>
                @foreach($categories as $cat)
                    <button type="button" class="zv-catlink" role="tab" aria-selected="false" data-cat="{{ $cat->id }}"><span>{{ $cat->name }}</span><span class="zv-cnt">{{ count($itemsByRoot[$cat->id] ?? []) }}</span></button>
                @endforeach
            </div>
            {{-- العلامة التجارية — brand checkboxes --}}
            @if($brands->isNotEmpty())
            <div class="zv-panel">
                <h4>العلامة التجارية</h4>
                @foreach($brands as $b)
                    <label class="zv-check"><input type="checkbox" class="zv-brandcheck" value="{{ Str::lower($b) }}"><span>{{ $b }}</span></label>
                @endforeach
            </div>
            @endif
            {{-- نطاق السعر — price range --}}
            <div class="zv-panel">
                <h4>نطاق السعر</h4>
                <div class="zv-range">
                    <span>{{ $zvPmin }}</span>
                    <input type="range" id="zvPriceRange" min="{{ $zvPmin }}" max="{{ $zvPmax }}" value="{{ $zvPmax }}" step="1" aria-label="نطاق السعر">
                    <span id="zvPriceVal">{{ $zvPmax }}</span>
                </div>
            </div>
        </aside>

        <div class="zv-scroll zv-haspad">
            {{-- breadcrumb (artifact catalog) --}}
            <nav class="zv-crumb" aria-label="Breadcrumb">
                <button type="button" data-zv-nav="home">{{ ___('Home') }}</button>
                <span class="sep">/</span>
                <b id="zvCatCrumb">{{ ___('All') }}</b>
            </nav>

            {{-- title · product count · sort (artifact catmain-head) --}}
            <div class="zv-catmain-head">
                <h1><span id="zvCatTitle">{{ ___('All') }}</span> <span class="zv-cnt2"><span id="zvCatCount">{{ $items->count() }}</span> منتج</span></h1>
                <select class="zv-sortsel" id="zvSortSel" aria-label="ترتيب">
                    <option value="pop">الأكثر مبيعًا</option>
                    <option value="low">السعر: من الأقل للأعلى</option>
                    <option value="high">السعر: من الأعلى للأقل</option>
                </select>
            </div>

            {{-- phone-only in-body search (hidden ≥1024 — header pill covers it) --}}
            <div class="zv-searchrow">
                <span class="zv-search-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg></span>
                <input type="search" class="zv-search" id="zvSearch" autocomplete="off"
                       placeholder="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}"
                       aria-label="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}">
            </div>

            {{-- phone-only category chips (the desktop sidebar covers this ≥1024) --}}
            @if($categories->isNotEmpty())
            <nav class="zv-chips" role="tablist" aria-label="{{ $vocab['categories'] ?? ___('Categories') }}">
                <button type="button" class="zv-chip is-active" role="tab" aria-selected="true" data-cat="all">{{ ___('All') }}</button>
                @foreach($categories as $cat)
                    <button type="button" class="zv-chip" role="tab" aria-selected="false" data-cat="{{ $cat->id }}">{{ $cat->name }}</button>
                @endforeach
            </nav>
            @endif

            @if($items->isEmpty())
                <p class="zv-empty-text">{{ ___('Nothing here yet.') }}</p>
            @else
                <div class="zv-grid" id="zvGrid">
                    @foreach($items as $m)
                        @include('store_templates.zevoa._partials.product-card', ['jd' => $jsItems[$m->id]])
                    @endforeach
                </div>
                <p class="zv-empty-text" id="zvNoResults" hidden>{{ ___('No results.') }}</p>
            @endif
        </div>
    </section>

    {{-- ══════════ PRODUCT (skeleton — JS populates it from $jsItems, same
         posture as shelf's item sheet, just rendered as a full screen) ══════════ --}}
    <section class="zv-screen" id="zvScreenProduct" data-zv-screen="product" hidden>
        <header class="zv-hdr">
            <button type="button" class="zv-iconbtn" data-zv-back aria-label="{{ ___('Back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <span class="zv-hdr-title">{{ ___('Product details') }}</span>
            <button type="button" class="zv-iconbtn zv-heart" id="zvProductHeart" style="position:static;background:var(--zv-card)" aria-pressed="false" aria-label="{{ ___('Add to favorites') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
            </button>
        </header>
        <div class="zv-scroll" style="padding-bottom:110px">
            <div class="zv-pd-img" id="zvPdImgWrap">
                <span class="zv-disc" id="zvPdDisc" hidden></span>
                <img id="zvPdImg" alt="" hidden data-csp-error="hide">
                <div class="zv-img-ph" id="zvPdImgPh" role="img" aria-label="">
                    <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 8h16l-1.6 11a2 2 0 0 1-2 1.8H7.6a2 2 0 0 1-2-1.8L4 8z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/></svg>
                </div>
            </div>
            {{-- Swipeable thumbnail strip — populated by renderProductThumbs()
                 from the item's `gallery`; hidden unless the item has ≥2 images. --}}
            <div class="zv-pd-thumbs" id="zvPdThumbs" hidden></div>
            <h1 class="zv-pd-title" id="zvPdTitle"></h1>
            <div class="zv-pd-unit" id="zvPdUnit" hidden></div>
            <div class="zv-pd-prices">
                <span class="zv-pd-price" id="zvPdPrice"></span>
                <span class="zv-price zv-price--was" id="zvPdWas" hidden></span>
                <span class="zv-pd-save" id="zvPdSave" hidden></span>
            </div>
            <p class="zv-pd-desc" id="zvPdDesc"></p>
            <span class="zv-stock" id="zvPdStock" hidden></span>

            <div class="zv-agegate" id="zvAgeGate" hidden>
                <span>{{ ___('This item is age-restricted. Confirm you meet the minimum age to buy it.') }}</span>
                <div class="zv-agegate-actions">
                    <button type="button" class="zv-btn zv-btn--brand" id="zvAgeConfirm" style="height:42px">{{ ___('Confirm') }}</button>
                    <button type="button" class="zv-btn zv-btn--ghost" id="zvAgeCancel" style="height:42px">{{ ___('Cancel') }}</button>
                </div>
            </div>

            <div id="zvVariantGroups"></div>

            <div class="zv-group" id="zvAttrsGroup" hidden>
                <div class="zv-group-label">{{ ___('Details') }}</div>
                <table class="zv-attrs" id="zvAttrsTable"></table>
            </div>

            <div class="zv-qtyrow">
                <span class="zv-ql">{{ ___('Quantity') }}</span>
                <div class="zv-stepper">
                    <button type="button" class="zv-step" id="zvQtyMinus" aria-label="{{ ___('Decrease') }}">&minus;</button>
                    <span class="zv-qty" id="zvQtyVal">1</span>
                    <button type="button" class="zv-step" id="zvQtyPlus" aria-label="{{ ___('Increase') }}">&plus;</button>
                </div>
            </div>

            {{-- Assurances (artifact buy-box): fast delivery / free returns /
                 secure pay / fresh — static trust rows that fill the buy column
                 and mirror the reference product page. --}}
            <div class="zv-pd-assure">
                <div class="zv-assure"><svg viewBox="0 0 24 24"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7.5" cy="17" r="1.7"/><circle cx="16.5" cy="17" r="1.7"/></svg><div><b>{{ ___('Delivery in 30 min') }}</b> · <span>{{ ___('Within the city') }}</span></div></div>
                <div class="zv-assure"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 019-9 9 9 0 010 18M3 12h9M6 8l-4 4 4 4"/></svg><div><b>{{ ___('Easy returns') }}</b> · <span>{{ ___('Within 14 days') }}</span></div></div>
                <div class="zv-assure"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/></svg><div><b>{{ ___('Secure payment') }}</b> · <span>mada · VISA · Apple Pay</span></div></div>
                <div class="zv-assure"><svg viewBox="0 0 24 24"><path d="M12 3l7 4v5c0 4.5-3 8-7 9-4-1-7-4.5-7-9V7z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg><div><b>{{ ___('Quality guaranteed') }}</b> · <span>{{ ___('Fresh daily') }}</span></div></div>
            </div>

            <div class="zv-sectlabel" style="margin:22px 0 10px"><h2 style="font-size:14.5px">{{ ___('Complete your purchase with these items') }}</h2></div>
            <div class="zv-hrow" id="zvPdUpsell"></div>

            {{-- Curated core feature: ratings & reviews (reuses the .zv-stars
                 star markup style from the post-order rating widget above,
                 per the shared contract's own note). Fetched/reset on every
                 openProduct() call — see loadReviews() in the script. --}}
            <div class="zv-sectlabel zv-sectlabel--sm"><h2>{{ ___('Ratings & reviews') }}</h2></div>
            <div class="zv-reviews" id="zvReviews">
                <div class="zv-rv-summary" id="zvRvSummary" hidden>
                    <div class="zv-rv-avgcol">
                        <div class="zv-rv-avgnum" id="zvRvAvgNum">0.0</div>
                        <div class="zv-rv-avgstars" id="zvRvAvgStars"></div>
                        <div class="zv-rv-avgcount" id="zvRvAvgCount"></div>
                    </div>
                    <div class="zv-rv-dist" id="zvRvDist"></div>
                </div>
                <div class="zv-rv-list" id="zvRvList"></div>
                <p class="zv-rv-empty" id="zvRvEmpty" hidden>{{ ___('No reviews yet — be the first to share what you think.') }}</p>
                <div class="zv-rv-form">
                    <div class="zv-group-label">{{ ___('Write a review') }}</div>
                    <div class="zv-stars zv-stars--pick" id="zvRvStarsInput" role="radiogroup" aria-label="{{ ___('Rating') }}">
                        @for($i = 1; $i <= 5; $i++)
                            <button type="button" data-star="{{ $i }}" aria-label="{{ ___(':n stars', ['n' => $i]) }}">
                                <svg viewBox="0 0 24 24"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
                            </button>
                        @endfor
                    </div>
                    <div class="zv-field"><label for="zvRvName">{{ ___('Name') }}</label><input type="text" id="zvRvName" maxlength="80" placeholder="{{ ___('Optional') }}"></div>
                    <div class="zv-field"><label for="zvRvBody">{{ ___('Your review') }}</label><textarea id="zvRvBody" rows="3" maxlength="2000"></textarea></div>
                    <div id="zvRvMsg"></div>
                    <button type="button" class="zv-btn zv-btn--brand zv-btn--block" id="zvRvSubmit">{{ ___('Submit review') }}</button>
                </div>
            </div>
        </div>
        <div class="zv-actionbar">
            <button type="button" class="zv-btn zv-btn--brand zv-btn--block" id="zvAddToCart">
                <span id="zvAddToCartLabel">{{ $vocab['add_to_cart'] ?? ___('Add to cart') }}</span>
                <b id="zvAddToCartPrice"></b>
            </button>
        </div>
    </section>

    {{-- ══════════ CART ══════════ --}}
    <section class="zv-screen" id="zvScreenCart" data-zv-screen="cart" hidden>
        <header class="zv-hdr">
            <button type="button" class="zv-iconbtn" data-zv-back aria-label="{{ ___('Back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <span class="zv-hdr-title">{{ $vocab['orders'] ?? ___('Cart') }}</span>
            <span style="width:42px"></span>
        </header>
        <div class="zv-scroll" style="padding-bottom:24px">
            <div id="zvCartView">
                <div class="zv-hoursban">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>
                    <span>{{ ___('Open 24 hours · delivery in 30 min') }}</span>
                </div>
                <div id="zvCartLines"></div>
                <div class="zv-empty" id="zvCartEmpty">
                    <div class="zv-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 8h12l1 12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg></div>
                    <h3>{{ ___('Your cart is empty') }}</h3>
                    <p>{{ ___('Add a grocery item you love to get started.') }}</p>
                    <button type="button" class="zv-btn zv-btn--brand" style="padding:0 26px" data-zv-nav="store">{{ ___('Start shopping') }}</button>
                </div>
                <div id="zvCartUpsellWrap" hidden>
                    <div class="zv-sectlabel" style="margin:22px 0 10px"><h2 style="font-size:14.5px">{{ ___('You might also need') }}</h2></div>
                    <div class="zv-hrow" id="zvCartUpsell"></div>
                </div>
                <div class="zv-summary" id="zvCartSummary" hidden>
                    <h3>{{ $vocab['orders'] ?? ___('Cart') }}</h3>
                    <div class="zv-srow"><span>{{ ___('Price') }}</span><span id="zvSumPrice"></span></div>
                    {{-- Presentational summary rows only (see JS totals()) —
                         never sent to the server, never allowed to override
                         the order's own authoritative total. --}}
                    <div class="zv-srow"><span>{{ ___('Handling & delivery fee') }}</span><span id="zvSumHandling"></span></div>
                    <div class="zv-srow"><span>{{ ___('Packaging fee') }}</span><span id="zvSumPackaging"></span></div>
                    <div class="zv-srow" id="zvSumVoucherRow" hidden><span>{{ ___('Voucher code') }}</span><span id="zvSumVoucher"></span></div>
                    <div class="zv-srow" id="zvSumTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="zvSumTax"></span></div>
                    <div class="zv-srow zv-srow--total"><span>{{ ___('Total') }}</span><span id="zvSumTotal"></span></div>
                    <div class="zv-promo" id="zvVoucherForm">
                        <input type="text" id="zvVoucherInput" placeholder="{{ ___('Voucher code') }}" maxlength="40">
                        <button type="button" id="zvVoucherApply">{{ ___('Apply') }}</button>
                    </div>
                    <button type="button" class="zv-btn zv-btn--brand zv-btn--block" style="margin-top:16px" id="zvGoCheckout">
                        <span>{{ ___('Checkout') }}</span>&nbsp;&middot;&nbsp;<b id="zvCheckoutTotal"></b>
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- ══════════ CHECKOUT ══════════ --}}
    <section class="zv-screen" id="zvScreenCheckout" data-zv-screen="checkout" hidden>
        <header class="zv-hdr">
            <button type="button" class="zv-iconbtn" data-zv-back aria-label="{{ ___('Back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <span class="zv-hdr-title">{{ ___('Checkout') }}</span>
            <span style="width:42px"></span>
        </header>
        <div class="zv-scroll" style="padding-bottom:110px">
            <div id="zvCheckoutMsg"></div>

            <div class="zv-sect">
                <div class="zv-label">{{ ___('Your details') }}</div>
                <div class="zv-field"><label for="zvName">{{ ___('Name') }}</label><input type="text" id="zvName" required maxlength="255"></div>
                <div class="zv-field"><label for="zvPhone">{{ ___('Phone') }}</label><input type="tel" id="zvPhone" maxlength="25"></div>
            </div>

            @if($modes->count() > 1)
            <div class="zv-sect">
                <div class="zv-label">{{ ___('Delivery location') }}</div>
                <div id="zvModeOpts">
                @foreach($modes as $mode)
                    <button type="button" class="zv-optcard" data-zv-mode="{{ $mode['value'] }}"
                            data-needs-address="{{ $mode['needsAddress'] ? 1 : 0 }}" data-needs-slot="{{ $mode['needsSlot'] ? 1 : 0 }}"
                            aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
                        <span class="zv-oico">
                            @if($mode['needsAddress'])
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>
                            @elseif($mode['needsSlot'])
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M4 8h16l-1.6 11a2 2 0 0 1-2 1.8H7.6a2 2 0 0 1-2-1.8L4 8z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/></svg>
                            @endif
                        </span>
                        <span class="zv-obody"><span class="zv-ot">{{ $mode['label'] }}</span></span>
                        <span class="zv-radio"></span>
                    </button>
                @endforeach
                </div>
            </div>
            @endif

            <div class="zv-field" id="zvAddressField" hidden>
                <label for="zvAddress">{{ ___('Address') }}</label>
                <div class="zv-addr">
                    <input type="text" id="zvAddress" maxlength="255" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="{{ ___('Start typing your address…') }}">
                    <button type="button" class="zv-addr-loc" id="zvAddrLoc" aria-label="{{ ___('Use my location') }}" title="{{ ___('Use my location') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                    </button>
                    <div class="zv-addr-suggest" id="zvAddrSuggest" hidden></div>
                </div>
            </div>
            <div class="zv-field" id="zvSlotField" hidden>
                <label>{{ ___('Pick a time') }}</label>
                <div class="zv-slots" id="zvSlots"></div>
            </div>

            <div class="zv-sect">
                <div class="zv-label">{{ ___('Transfer method') }}</div>
                {{-- PRESENTATIONAL selector — no payment gateway is wired into
                     this template; GoodsCheckoutController@place only reads
                     type/address/phone_number/customer_name/scheduled_at, so
                     this choice is never sent to the server. It exists so a
                     cash-on-delivery-style store can let a shopper signal how
                     they intend to pay. --}}
                <button type="button" class="zv-optcard" data-zv-pay="cash" aria-pressed="true">
                    <span class="zv-oico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="7" width="18" height="10" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg></span>
                    <span class="zv-obody"><span class="zv-ot">{{ ___('Cash') }}</span><span class="zv-os">{{ ___('Pay when it arrives') }}</span></span>
                    <span class="zv-radio"></span>
                </button>
                <button type="button" class="zv-optcard" data-zv-pay="card" aria-pressed="false">
                    <span class="zv-oico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/></svg></span>
                    <span class="zv-obody"><span class="zv-ot">{{ ___('Card') }}</span></span>
                    <span class="zv-radio"></span>
                </button>
                <button type="button" class="zv-optcard" data-zv-pay="paypal" aria-pressed="false">
                    <span class="zv-oico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M16 12h3"/></svg></span>
                    <span class="zv-obody"><span class="zv-ot">{{ ___('PayPal') }}</span></span>
                    <span class="zv-radio"></span>
                </button>
                <button type="button" class="zv-optcard" data-zv-pay="applepay" aria-pressed="false">
                    <span class="zv-oico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M17 13c0-2.4 1.9-3.6 2-3.7-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.2-2.8.8-3.5.8-.7 0-1.8-.8-3-.8-1.5 0-2.9 1-3.7 2.5-1.6 2.7-.4 6.7 1.1 8.9.7 1 1.6 2.2 2.7 2.2 1.1 0 1.5-.7 2.8-.7 1.3 0 1.7.7 2.8.7 1.2 0 1.9-1 2.6-2.1.5-.7.7-1.1 1.1-2-2.9-1.1-2.8-4.4-1.2-4.9z"/><path d="M13.9 6.4c.6-.7 1-1.7.9-2.7-.9.1-1.9.6-2.5 1.3-.6.7-1 1.7-.9 2.6.9.1 1.9-.5 2.5-1.2z"/></svg></span>
                    <span class="zv-obody"><span class="zv-ot">{{ ___('Apple Pay') }}</span></span>
                    <span class="zv-radio"></span>
                </button>
            </div>

            {{-- Curated core feature: loyalty redeem row. Populated in JS
                 (renderLoyaltyCheckout()) from the auth:customer balance
                 endpoint — guests (401) fall back to the public teaser +
                 sign-in hint; the toggle only appears when the balance says
                 ready_to_redeem, and drives the hidden loyalty_redeem input,
                 which the order submit now includes in its POST body per the
                 shared contract. NOTE (see report): GoodsCheckoutController
                 (Lane B, this theme's own checkout route) does not currently
                 read/forward loyalty_redeem the way the food-ordering
                 checkout does — the toggle is fully wired here but the
                 backend piece that applies the discount is outside this
                 theme dir and still open. --}}
            <div class="zv-sect" id="zvLoyaltySect" hidden>
                <div class="zv-label">{{ ___('Rewards') }}</div>
                <div class="zv-swrow" id="zvLoyaltyRedeemRow" hidden>
                    <div>
                        <div class="zv-ot" id="zvLoyaltyRedeemTitle"></div>
                        <div class="zv-os" id="zvLoyaltyRedeemSub"></div>
                    </div>
                    <button type="button" class="zv-switch" id="zvLoyaltyToggle" role="switch" aria-checked="false" aria-label="{{ ___('Redeem reward') }}">
                        <span class="zv-switch-knob"></span>
                    </button>
                </div>
                <div class="zv-msg zv-msg--info" id="zvLoyaltyTeaserMsg" hidden></div>
                <input type="hidden" name="loyalty_redeem" id="zvLoyaltyRedeemInput" value="0">
            </div>

            {{-- Gift card (tender) — applied server-side via OrderPricing. --}}
            <div class="zv-giftcardbox">
                <input type="text" class="zv-coupon-input" id="zvGiftCardInput" name="gift_card_code" autocomplete="off" placeholder="{{ ___('Gift card code') }}" aria-label="{{ ___('Gift card code') }}">
            </div>

            <div class="zv-summary">
                <div class="zv-srow"><span>{{ ___('Price') }}</span><span id="zvCoSumPrice"></span></div>
                <div class="zv-srow"><span>{{ ___('Handling & delivery fee') }}</span><span id="zvCoSumHandling"></span></div>
                <div class="zv-srow"><span>{{ ___('Packaging fee') }}</span><span id="zvCoSumPackaging"></span></div>
                <div class="zv-srow" id="zvCoSumVoucherRow" hidden><span>{{ ___('Voucher code') }}</span><span id="zvCoSumVoucher"></span></div>
                <div class="zv-srow" id="zvCoTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="zvCoTax"></span></div>
                <div class="zv-srow zv-srow--total"><span>{{ ___('Total') }}</span><span id="zvCoSumTotal"></span></div>
            </div>
        </div>
        <div class="zv-actionbar">
            <button type="button" class="zv-btn zv-btn--brand zv-btn--block" id="zvPlaceOrder">
                <span>{{ ___('Place order') }}</span>&nbsp;&middot;&nbsp;<b id="zvPlaceOrderTotal"></b>
            </button>
        </div>
    </section>

    {{-- ══════════ CONFIRM (demo timer allowed here per spec — real status
         lives on the linked track.blade.php page) ══════════ --}}
    <section class="zv-screen" id="zvScreenConfirm" data-zv-screen="confirm" hidden>
        <div class="zv-scroll" style="padding-bottom:24px">
            <div class="zv-confwrap">
                <svg class="zv-checkc" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="45"/><path d="M30 52l14 14 26-30"/></svg>
                <h2>{{ ___('Purchase confirmed') }}</h2>
                <p>{{ ___('Your order has been placed and is being prepared.') }}</p>
                <div class="zv-orderno" id="zvOrderNo"></div>

                <div class="zv-etaban">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    <span>{{ ___('Delivery in about 30 min') }}</span>
                </div>

                {{-- Demo timeline — advances on a client-side timer right
                     after checkout for immediate feedback. The REAL,
                     reloadable order status lives on track.blade.php (linked
                     below via zvTrackLink), which polls the actual
                     RetailOrderBoardService state machine. --}}
                <div class="zv-track">
                    <div class="zv-tprogress"><i id="zvTrackBar"></i></div>
                    <div class="zv-tstep" id="zvTStep0">
                        <span class="zv-tdot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg></span>
                        <div><div class="zv-tt">{{ ___('Purchased') }}</div><div class="zv-td" id="zvTStep0Sub"></div></div>
                    </div>
                    <div class="zv-tstep" id="zvTStep1">
                        <span class="zv-tdot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 8l9-5 9 5-9 5z"/><path d="M3 8v8l9 5 9-5V8"/></svg></span>
                        <div><div class="zv-tt">{{ ___('Packed') }}</div><div class="zv-td" id="zvTStep1Sub"></div></div>
                    </div>
                    <div class="zv-tstep" id="zvTStep2">
                        <span class="zv-tdot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg></span>
                        <div><div class="zv-tt">{{ ___('On the way') }}</div><div class="zv-td" id="zvTStep2Sub"></div></div>
                    </div>
                    <div class="zv-tstep" id="zvTStep3">
                        <span class="zv-tdot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-9"/></svg></span>
                        <div><div class="zv-tt">{{ ___('Delivered') }}</div><div class="zv-td" id="zvTStep3Sub"></div></div>
                    </div>
                </div>

                {{-- Courier card is intentionally GENERIC — no driver
                     name/phone/photo is ever fabricated (the real tracking
                     endpoint never exposes one either — see track.blade.php).
                     Call only renders when the store published a real phone
                     number. --}}
                <div class="zv-drivercard">
                    <span class="zv-davatar" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12l1.3-4.2A2 2 0 0 1 6.2 6.5h11.6a2 2 0 0 1 1.9 1.3L21 12"/><rect x="1" y="12" width="22" height="6" rx="2"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/></svg></span>
                    <div class="zv-dinfo">
                        <div class="zv-dname">{{ ___('Your delivery courier') }}</div>
                        <div class="zv-dsub">{{ ___('We will share contact details once assigned.') }}</div>
                    </div>
                    <div class="zv-dacts">
                        @if($store->phone)
                        <a class="zv-dbtn" href="tel:{{ $store->phone }}" aria-label="{{ ___('Call') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M5 4h3l2 5-2.2 1.3a12 12 0 0 0 6 6L15 14l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>
                        </a>
                        @endif
                    </div>
                </div>

                <div class="zv-ratebox">
                    <div class="zv-rt">{{ ___('Rate your courier') }}</div>
                    <div class="zv-stars" id="zvStars">
                        @for($i = 1; $i <= 5; $i++)
                            <button type="button" data-star="{{ $i }}" aria-label="{{ ___(':n stars', ['n' => $i]) }}">
                                <svg viewBox="0 0 24 24"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
                            </button>
                        @endfor
                    </div>
                </div>

                <button type="button" class="zv-btn zv-btn--ghost zv-btn--block" style="margin-top:16px" id="zvDownloadBill">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 3v12"/><path d="M7.5 10.5L12 15l4.5-4.5"/><path d="M5 21h14"/></svg>
                    {{ ___('Download bill') }}
                </button>
                <a href="#" class="zv-btn zv-btn--ghost zv-btn--block" id="zvTrackLink" style="margin-top:10px;text-decoration:none" hidden>{{ ___('Track your order') }}</a>
            </div>
        </div>
        <div class="zv-actionbar">
            <button type="button" class="zv-btn zv-btn--brand zv-btn--block" data-zv-nav="home">{{ ___('Continue shopping') }}</button>
        </div>
    </section>

    {{-- ══════════ FAVORITES ══════════ --}}
    <section class="zv-screen" id="zvScreenFavorites" data-zv-screen="favorites" hidden>
        <header class="zv-hdr">
            <button type="button" class="zv-iconbtn" data-zv-back aria-label="{{ ___('Back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg>
            </button>
            <span class="zv-hdr-title">{{ ___('Favorites') }}</span>
            <span style="width:42px"></span>
        </header>
        <div class="zv-scroll zv-haspad">
            <div class="zv-grid" id="zvFavGrid"></div>
            <div class="zv-empty" id="zvFavEmpty">
                <div class="zv-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></div>
                <h3>{{ ___('No favourites yet') }}</h3>
                <p>{{ ___('Tap the heart on any item to save it.') }}</p>
                <button type="button" class="zv-btn zv-btn--brand" style="padding:0 26px" data-zv-nav="store">{{ ___('Start shopping') }}</button>
            </div>
        </div>
    </section>

    {{-- ══════════ ACTIVITY (session-scoped list of REAL orders THIS browser
         placed — each "View order" link is a genuine track_token URL from a
         successful checkout, never fabricated; no backend order-history
         endpoint is in scope for this theme) ══════════ --}}
    <section class="zv-screen" id="zvScreenActivity" data-zv-screen="activity" data-zv-tab="activity" hidden>
        <header class="zv-hdr"><span class="zv-hdr-title">{{ ___('Activity') }}</span><span style="width:42px"></span></header>
        <div class="zv-scroll zv-haspad">
            <div id="zvActivityList"></div>
            <div class="zv-empty" id="zvActivityEmpty">
                <div class="zv-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/></svg></div>
                <h3>{{ ___('No orders yet') }}</h3>
                <p>{{ ___('Track your ZeMart orders here once you check out.') }}</p>
                <button type="button" class="zv-btn zv-btn--brand" style="padding:0 26px" data-zv-nav="store">{{ ___('Start shopping') }}</button>
            </div>
        </div>
    </section>

    {{-- ══════════ ACCOUNT ══════════ --}}
    <section class="zv-screen" id="zvScreenAccount" data-zv-screen="account" data-zv-tab="account" hidden>
        <header class="zv-hdr"><span class="zv-hdr-title">{{ ___('Account') }}</span><span style="width:42px"></span></header>
        <div class="zv-scroll zv-haspad">
            <div class="zv-profile">
                <span class="zv-avatar zv-avatar--lg" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                </span>
                <div>
                    <div class="zv-pn">{{ ___('Guest shopper') }}</div>
                    <div class="zv-pe">{{ $store->title }}</div>
                </div>
            </div>

            {{-- Curated core feature: loyalty "Rewards" card. The
                 program/earn/reward teaser is server-rendered (public,
                 no auth); JS (renderRewardsCard()) best-effort upgrades the
                 sub-line + highlights the current tier chip when a real
                 auth:customer balance is available, and shows a sign-in hint
                 otherwise. Skipped entirely when the store never set a
                 teaser (loyalty_teaser option absent → $loyaltyTeaser null). --}}
            @if($loyaltyTeaser)
            <div class="zv-rewardscard" id="zvRewardsCard">
                <div class="zv-rewardscard-head">
                    <span class="zv-rewardscard-badge" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
                    </span>
                    <div>
                        <div class="zv-rewardscard-title">{{ $loyaltyTeaser['program'] ?? ___('Rewards') }}</div>
                        <div class="zv-rewardscard-sub" id="zvRewardsBalanceLine">{{ $loyaltyTeaser['earn'] ?? '' }}</div>
                    </div>
                </div>
                @if(!empty($loyaltyTeaser['reward']))
                    <div class="zv-rewardscard-reward">{{ $loyaltyTeaser['reward'] }}</div>
                @endif
                <div class="zv-tierrow" id="zvTierRow">
                    <span class="zv-tierchip" data-tier="silver">{{ ___('Silver') }}</span>
                    <span class="zv-tierchip" data-tier="gold">{{ ___('Gold') }}</span>
                    <span class="zv-tierchip" data-tier="platinum">{{ ___('Platinum') }}</span>
                </div>
                <div class="zv-rewardscard-hint" id="zvRewardsSigninHint" hidden>{{ ___('Sign in to earn & redeem points') }}</div>
            </div>
            @endif

            <div class="zv-acclist">
                @if(($postOptions->customer_auth_mode ?? '') !== 'demo')
                    @if(auth('customer')->check())
                        @if(\App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
                            <button type="button" class="zv-accrow" data-mb-wallet-open>
                                <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M16 14h2"/></svg></span>
                                <span class="zv-at">{{ ___('Wallet — add funds') }}</span>
                                <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                            </button>
                        @endif
                        <button type="button" class="zv-accrow" data-mb-logout data-logout-url="{{ route('customer.logout', $store->id) }}">
                            <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3M10 12h9M16 8l3 4-3 4"/></svg></span>
                            <span class="zv-at">{{ ___('Log out') }}</span>
                            <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                        </button>
                    @else
                        <button type="button" class="zv-accrow" data-mb-auth-open>
                            <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H6a2 2 0 00-2 2v12a2 2 0 002 2h3M14 12H4M11 8l-3 4 3 4"/></svg></span>
                            <span class="zv-at">{{ ___('Sign in / Register') }}</span>
                            <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                        </button>
                    @endif
                @endif
                <button type="button" class="zv-accrow" id="zvAccFavorites">
                    <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></span>
                    <span class="zv-at">{{ ___('Favorites') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                <button type="button" class="zv-accrow" id="zvAccOrders">
                    <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 8l9-5 9 5-9 5z"/><path d="M3 8v8l9 5 9-5V8"/></svg></span>
                    <span class="zv-at">{{ ___('My orders') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                <button type="button" class="zv-accrow" id="zvAccShare" data-share-url="{{ url()->current() }}" data-share-title="{{ $store->title }}">
                    <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg></span>
                    <span class="zv-at">{{ ___('Share this store') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                <button type="button" class="zv-accrow" data-zv-nav="addresses">
                    <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s7-6 7-11a7 7 0 0 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                    <span class="zv-at">{{ ___('Addresses') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                <button type="button" class="zv-accrow" data-zv-nav="payments">
                    <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/></svg></span>
                    <span class="zv-at">{{ ___('Payment methods') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                <button type="button" class="zv-accrow" data-zv-nav="settings">
                    <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg></span>
                    <span class="zv-at">{{ ___('Settings') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                <button type="button" class="zv-accrow" data-zv-nav="help">
                    <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 3.5-.5c1.5 1 .5 2.5-1 3.5v1M12 17h.01"/></svg></span>
                    <span class="zv-at">{{ ___('Help & support') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
            </div>
        </div>
    </section>

    {{-- ══════════ SETTINGS (language · appearance · notifications · data) ══════════ --}}
    <section class="zv-screen" id="zvScreenSettings" data-zv-screen="settings" hidden>
        <header class="zv-hdr">
            <button type="button" class="zv-iconbtn" data-zv-back aria-label="{{ ___('Back') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg></button>
            <span class="zv-hdr-title">{{ ___('Settings') }}</span>
            <span style="width:42px"></span>
        </header>
        <div class="zv-scroll zv-haspad">
            <div class="zv-setgroup">
                <div class="zv-setlabel">{{ ___('Language') }}</div>
                <div class="zv-segwrap">
                    @foreach($zvLanguages as $lang)
                        <a class="zv-seg {{ get_lang() === $lang->code ? 'is-on' : '' }}" href="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}">{{ strtoupper($lang->code) }}</a>
                    @endforeach
                </div>
            </div>
            <div class="zv-setgroup">
                <div class="zv-setlabel">{{ ___('Appearance') }}</div>
                <div class="zv-segwrap" id="zvThemeSeg" role="group" aria-label="{{ ___('Appearance') }}">
                    <button type="button" class="zv-seg" data-zv-theme="light">{{ ___('Light') }}</button>
                    <button type="button" class="zv-seg" data-zv-theme="dark">{{ ___('Dark') }}</button>
                    <button type="button" class="zv-seg" data-zv-theme="system">{{ ___('System') }}</button>
                </div>
            </div>
            <div class="zv-setgroup">
                <div class="zv-setlabel">{{ ___('Notifications') }}</div>
                <button type="button" class="zv-setrow" id="zvNotifToggle" role="switch" aria-checked="false">
                    <span class="zv-at">{{ ___('Order & offer alerts') }}</span>
                    <span class="zv-switch" aria-hidden="true"></span>
                </button>
                <p class="zv-sethint">{{ ___('Get notified about order updates and new offers from this store.') }}</p>
            </div>
            <div class="zv-setgroup">
                <button type="button" class="zv-setrow zv-setrow--danger" id="zvClearData">
                    <span class="zv-at">{{ ___('Clear saved data on this device') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </button>
                <p class="zv-sethint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
            </div>
        </div>
    </section>

    {{-- ══════════ ADDRESSES (real per-device saved addresses, localStorage) ══════════ --}}
    <section class="zv-screen" id="zvScreenAddresses" data-zv-screen="addresses" hidden>
        <header class="zv-hdr">
            <button type="button" class="zv-iconbtn" data-zv-back aria-label="{{ ___('Back') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg></button>
            <span class="zv-hdr-title">{{ ___('Addresses') }}</span>
            <span style="width:42px"></span>
        </header>
        <div class="zv-scroll zv-haspad">
            <div id="zvAddrList" class="zv-addrlist"></div>
            <div class="zv-empty" id="zvAddrEmpty" hidden>
                <div class="zv-eico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 21s-7-6-7-11a7 7 0 0 1 14 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></div>
                <h3>{{ ___('No saved addresses') }}</h3>
                <p>{{ ___('Add an address to check out faster.') }}</p>
            </div>
            <button type="button" class="zv-btn zv-btn--brand zv-btn--block" id="zvAddrAddBtn" style="margin-top:14px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><path d="M12 5v14M5 12h14"/></svg>
                {{ ___('Add new address') }}
            </button>
            <form class="zv-addrform" id="zvAddrForm" hidden>
                <input type="text" class="zv-input" id="zvAddrLabel" placeholder="{{ ___('Label (Home, Work…)') }}" maxlength="40" autocomplete="off">
                <div class="zv-addr">
                    <input type="text" class="zv-input" id="zvAddrText" placeholder="{{ ___('Full address') }}" maxlength="240" autocomplete="off">
                    <button type="button" class="zv-addr-loc" id="zvAddrLocNew" aria-label="{{ ___('Use my location') }}" title="{{ ___('Use my location') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                    </button>
                    <div class="zv-addr-suggest" id="zvAddrSuggestNew" hidden></div>
                </div>
                <button type="button" class="zv-mapbtn" id="zvAddrMapBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 20l-5.5 2.5V6L9 3.5m0 16.5l6 2.5m-6-2.5V3.5m6 19l5.5-2.5V3.5L15 6m0 16.5V6m0 0L9 3.5"/></svg>
                    {{ ___('Choose on map') }}
                </button>
                <input type="tel" class="zv-input" id="zvAddrPhone" placeholder="{{ ___('Phone (optional)') }}" maxlength="24" dir="ltr" autocomplete="off">
                <div class="zv-addrform-actions">
                    <button type="button" class="zv-btn zv-btn--ghost" id="zvAddrCancel">{{ ___('Cancel') }}</button>
                    <button type="submit" class="zv-btn zv-btn--brand">{{ ___('Save address') }}</button>
                </div>
            </form>
        </div>
    </section>

    {{-- Map picker overlay for the address form (self-hosted Leaflet + geo/reverse). --}}
    <div class="zv-mapwrap" id="zvMapWrap" hidden>
        <header class="zv-hdr">
            <button type="button" class="zv-iconbtn" id="zvMapClose" aria-label="{{ ___('Back') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg></button>
            <span class="zv-hdr-title">{{ ___('Choose on map') }}</span>
            <span style="width:42px"></span>
        </header>
        <div class="zv-maparea">
            <div id="zvMap"></div>
            <div class="zv-mappin" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg></div>
        </div>
        <div class="zv-mapbar">
            <div class="zv-mapaddr" id="zvMapAddr">{{ ___('Move the map to place the pin') }}</div>
            <button type="button" class="zv-btn zv-btn--brand zv-btn--block" id="zvMapConfirm">{{ ___('Use this location') }}</button>
        </div>
    </div>

    {{-- ══════════ PAYMENT METHODS (informational — accepted methods; cards are
         entered securely at checkout, nothing is stored on the device) ══════════ --}}
    <section class="zv-screen" id="zvScreenPayments" data-zv-screen="payments" hidden>
        <header class="zv-hdr">
            <button type="button" class="zv-iconbtn" data-zv-back aria-label="{{ ___('Back') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg></button>
            <span class="zv-hdr-title">{{ ___('Payment methods') }}</span>
            <span style="width:42px"></span>
        </header>
        <div class="zv-scroll zv-haspad">
            <div class="zv-setlabel">{{ ___('Accepted at checkout') }}</div>
            <div class="zv-paylist">
                <span class="zv-pay">Pay</span><span class="zv-pay stc">stc pay</span><span class="zv-pay mada">mada</span>
                <span class="zv-pay tabby">tabby</span><span class="zv-pay mc">MC</span><span class="zv-pay visa">VISA</span>
            </div>
            <div class="zv-paynote">
                <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
                <p>{{ ___('Card details are entered securely at checkout and are never stored on this device.') }}</p>
            </div>
        </div>
    </section>

    {{-- ══════════ HELP & SUPPORT (wired to the store's real contact channels) ══════════ --}}
    @php
        $zvPhone = trim((string) ($store->phone ?? ''));
        $zvEmail = trim((string) ($store->email ?? ''));
        $zvWaDigits = preg_replace('/\D+/', '', (string) store_options($store->id, 'whatsapp'));
        $zvAddr2 = trim((string) ($store->address ?? ''));
    @endphp
    <section class="zv-screen" id="zvScreenHelp" data-zv-screen="help" hidden>
        <header class="zv-hdr">
            <button type="button" class="zv-iconbtn" data-zv-back aria-label="{{ ___('Back') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg></button>
            <span class="zv-hdr-title">{{ ___('Help & support') }}</span>
            <span style="width:42px"></span>
        </header>
        <div class="zv-scroll zv-haspad">
            <p class="zv-helpintro">{{ ___('Questions about an order or a product? Reach :store directly.', ['store' => $store->title]) }}</p>
            <div class="zv-acclist">
                @if($zvWaDigits !== '')
                    <a class="zv-accrow" href="https://wa.me/{{ $zvWaDigits }}" target="_blank" rel="noopener">
                        <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 11.5a8.4 8.4 0 0 1-12.2 7.5L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5z"/></svg></span>
                        <span class="zv-at">{{ ___('WhatsApp us') }}</span>
                        <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                    </a>
                @endif
                @if($zvPhone !== '')
                    <a class="zv-accrow" href="tel:{{ $zvPhone }}">
                        <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4h3l2 5-2 1a11 11 0 0 0 5 5l1-2 5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg></span>
                        <span class="zv-at">{{ ___('Call us') }}</span>
                        <span class="zv-atsub" dir="ltr">{{ $zvPhone }}</span>
                    </a>
                @endif
                @if($zvEmail !== '')
                    <a class="zv-accrow" href="mailto:{{ $zvEmail }}">
                        <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg></span>
                        <span class="zv-at">{{ ___('Email us') }}</span>
                        <span class="zv-atsub" dir="ltr">{{ $zvEmail }}</span>
                    </a>
                @endif
                @if($zvAddr2 !== '')
                    <div class="zv-accrow zv-accrow--static">
                        <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s-7-6-7-11a7 7 0 0 1 14 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                        <span class="zv-at">{{ $zvAddr2 }}</span>
                    </div>
                @endif
                @if($zvWaDigits === '' && $zvPhone === '' && $zvEmail === '')
                    <div class="zv-accrow zv-accrow--static"><span class="zv-at">{{ ___('Contact details are being set up.') }}</span></div>
                @endif
            </div>
            <div class="zv-setlabel" style="margin-top:18px">{{ ___('Policies') }}</div>
            <div class="zv-acclist">
                <a class="zv-accrow" href="{{ route('page', 'terms-of-service') }}">
                    <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/></svg></span>
                    <span class="zv-at">{{ ___('Terms') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </a>
                <a class="zv-accrow" href="{{ route('page', 'the-privacy-policy') }}">
                    <span class="zv-aico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l7 4v5c0 4.5-3 8-7 9-4-1-7-4.5-7-9V7z"/></svg></span>
                    <span class="zv-at">{{ ___('Privacy') }}</span>
                    <span class="zv-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></span>
                </a>
            </div>
        </div>
    </section>

    </div>{{-- /.zv-content --}}

    {{-- ══════════ FOOTER (sidalih-style) — global, sits after whichever screen
         is active (each .zv-screen is min-height:100vh, inactive ones hidden). ══════════ --}}
    <footer class="zv-footer">
        <div class="zv-footer-in"><div class="zv-ft-grid">
            <div>
                <h4 class="zv-ft-h">{{ $vocab['categories'] ?? ___('Categories') }}</h4>
                <nav class="zv-ft-links">
                    @foreach($categories->take(10) as $cat)
                        <button type="button" data-zv-cat="{{ $cat->id }}">{{ $cat->name }}</button>
                    @endforeach
                    <button type="button" data-zv-nav="store">{{ ___('Offers') }}</button>
                </nav>
            </div>
            <div>
                <h4 class="zv-ft-h">{{ ___('Pages') }}</h4>
                <nav class="zv-ft-links">
                    <button type="button" data-zv-nav="store">{{ ___('Shipping & delivery') }}</button>
                    <button type="button" data-zv-nav="store">{{ ___('Payment methods') }}</button>
                    <button type="button" data-zv-nav="store">{{ ___('Return & exchange policy') }}</button>
                    <button type="button" data-zv-nav="store">{{ ___('Terms of use') }}</button>
                    <button type="button" data-zv-nav="store">{{ ___('Privacy policy') }}</button>
                    <button type="button" data-zv-nav="account">{{ ___('Contact us') }}</button>
                </nav>
            </div>
            <div class="zv-ft-news">
                <h4 class="zv-ft-h">{{ ___('Newsletter') }}</h4>
                <p>{{ ___('Subscribe to get the latest offers and discount codes.') }}</p>
                <div class="zv-news-field">
                    <input type="email" id="zvNewsInput" placeholder="{{ ___('Enter your email') }}" aria-label="{{ ___('Email') }}" autocomplete="email">
                    <button type="button" id="zvNewsBtn" aria-label="{{ ___('Subscribe') }}"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
                </div>
                @php
                    $zvSoc = [
                        'instagram' => trim((string) store_options($store->id, 'instagram_url')),
                        'tiktok'    => trim((string) store_options($store->id, 'tiktok_url')),
                        'x'         => trim((string) store_options($store->id, 'x_url')),
                        'snapchat'  => trim((string) store_options($store->id, 'snapchat_url')),
                        'facebook'  => trim((string) store_options($store->id, 'facebook_url')),
                    ];
                    $zvWaSoc = preg_replace('/\D+/', '', (string) store_options($store->id, 'whatsapp'));
                @endphp
                @if($zvWaSoc !== '' || collect($zvSoc)->filter(fn ($v) => $v !== '')->isNotEmpty())
                <div class="zv-ft-social">
                    @if($zvSoc['instagram'] !== '')<a href="{{ $zvSoc['instagram'] }}" target="_blank" rel="noopener" aria-label="Instagram"><svg viewBox="0 0 24 24"><path d="M12 2c2.7 0 3 0 4.1.1 1 0 1.7.2 2.3.5.6.2 1.1.5 1.6 1s.8 1 1 1.6c.3.6.4 1.3.5 2.3.1 1.1.1 1.4.1 4.1s0 3-.1 4.1c0 1-.2 1.7-.5 2.3-.2.6-.5 1.1-1 1.6s-1 .8-1.6 1c-.6.3-1.3.4-2.3.5-1.1.1-1.4.1-4.1.1s-3 0-4.1-.1c-1 0-1.7-.2-2.3-.5-.6-.2-1.1-.5-1.6-1s-.8-1-1-1.6c-.3-.6-.4-1.3-.5-2.3C2 15 2 14.7 2 12s0-3 .1-4.1c0-1 .2-1.7.5-2.3.2-.6.5-1.1 1-1.6s1-.8 1.6-1c.6-.3 1.3-.4 2.3-.5C8.6 2 8.9 2 12 2zm0 5a5 5 0 100 10 5 5 0 000-10zm0 2a3 3 0 110 6 3 3 0 010-6zm5.3-3.1a1.2 1.2 0 100 2.4 1.2 1.2 0 000-2.4z"/></svg></a>@endif
                    @if($zvSoc['tiktok'] !== '')<a href="{{ $zvSoc['tiktok'] }}" target="_blank" rel="noopener" aria-label="TikTok"><svg viewBox="0 0 24 24"><path d="M16.5 3c.3 2.1 1.5 3.5 3.5 3.7v2.6c-1.3.1-2.5-.3-3.5-1v6.1c0 3.4-2.6 5.6-5.6 5.6A5.5 5.5 0 015.5 14c0-3.3 3-5.9 6.4-5.4v2.8c-.4-.1-.9-.2-1.3-.2-1.5 0-2.7 1.2-2.7 2.7 0 1.6 1.5 2.8 3 2.4 1.1-.3 1.8-1.3 1.8-2.5V3h2.8z"/></svg></a>@endif
                    @if($zvSoc['x'] !== '')<a href="{{ $zvSoc['x'] }}" target="_blank" rel="noopener" aria-label="X"><svg viewBox="0 0 24 24"><path d="M17.5 3h3l-6.6 7.5L21.5 21h-6l-4.7-6-5.4 6H2.4l7-8L2 3h6.2l4.3 5.7L17.5 3zm-1 16h1.7L7.6 4.8H5.8L16.5 19z"/></svg></a>@endif
                    @if($zvSoc['snapchat'] !== '')<a href="{{ $zvSoc['snapchat'] }}" target="_blank" rel="noopener" aria-label="Snapchat"><svg viewBox="0 0 24 24"><path d="M12 2.5c2.6 0 4.6 2 4.7 4.6 0 .8 0 1.5-.1 2.2.3.2.7.3 1.1.2.5-.1.9.2 1 .6.1.5-.2.8-.7 1-.6.2-1.5.5-1.7 1-.1.3 0 .6.2 1 .5 1 1.4 1.9 2.7 2.3.4.1.6.4.5.8-.1.5-.9.8-1.7 1-.1 0-.2.3-.2.5-.1.5-.2.8-.7.8-.5 0-1-.2-1.7-.2-.9 0-1.3.2-2 .8-.6.5-1.3 1-2.4 1s-1.8-.5-2.4-1c-.7-.6-1.1-.8-2-.8-.7 0-1.2.2-1.7.2-.5 0-.6-.3-.7-.8 0-.2-.1-.5-.2-.5-.8-.2-1.6-.5-1.7-1-.1-.4.1-.7.5-.8 1.3-.4 2.2-1.3 2.7-2.3.2-.4.3-.7.2-1-.2-.5-1.1-.8-1.7-1-.5-.2-.8-.5-.7-1 .1-.4.5-.7 1-.6.4.1.8 0 1.1-.2-.1-.7-.1-1.4-.1-2.2C7.4 4.5 9.4 2.5 12 2.5z"/></svg></a>@endif
                    @if($zvSoc['facebook'] !== '')<a href="{{ $zvSoc['facebook'] }}" target="_blank" rel="noopener" aria-label="Facebook"><svg viewBox="0 0 24 24"><path d="M22 12a10 10 0 10-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.3v7A10 10 0 0022 12z"/></svg></a>@endif
                    @if($zvWaSoc !== '')<a href="https://wa.me/{{ $zvWaSoc }}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 00-8.6 15l-1.3 4.6 4.7-1.2A10 10 0 1012 2zm0 2a8 8 0 016.6 12.5l-.3.4.7 2.6-2.7-.7-.4.2A8 8 0 1112 4zm-3.4 4.3c-.2 0-.5.1-.7.4-.3.3-.9.9-.9 2.1s.9 2.4 1 2.6c.1.2 1.7 2.8 4.3 3.8 2.1.8 2.5.7 3 .6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2 0-.1-.2-.2-.5-.3l-1.8-.9c-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1-.7-.3-1.5-.7-2.3-1.6-.6-.7-1-1.5-1.1-1.7-.1-.2 0-.4.1-.5l.5-.6c.1-.2.2-.3.2-.5s0-.4-.1-.5l-.7-1.7c-.2-.5-.4-.4-.5-.4z"/></svg></a>@endif
                </div>
                @endif
                <div class="zv-ft-contact">
                    <a href="tel:{{ $store->phone ?? '920000000' }}"><span class="cic"><svg viewBox="0 0 24 24"><path d="M5 4h3l2 5-2 1a11 11 0 005 5l1-2 5 2v3a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/></svg></span><span dir="ltr">{{ $store->phone ?? '+966 920 000 000' }}</span></a>
                    <a href="mailto:{{ $store->email ?? 'help@store.sa' }}"><span class="cic"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg></span><span dir="ltr">{{ $store->email ?? 'help@store.sa' }}</span></a>
                </div>
            </div>
            @php
                $zvAppIos = trim((string) store_options($store->id, 'app_store_url'));
                $zvAppAndroid = trim((string) store_options($store->id, 'play_store_url'));
            @endphp
            @if($zvAppIos !== '' || $zvAppAndroid !== '')
            <div>
                <h4 class="zv-ft-h">{{ ___('Mobile apps') }}</h4>
                <p class="zv-ft-news" style="margin-bottom:14px">{{ ___('Download our app for faster shopping and exclusive offers.') }}</p>
                <div class="zv-ft-apps">
                    @if($zvAppIos !== '')<a class="zv-appbadge" href="{{ $zvAppIos }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24"><path d="M16.5 12.6c0-2 1.6-3 1.7-3-1-1.3-2.4-1.5-2.9-1.5-1.2-.1-2.4.7-3 .7-.6 0-1.6-.7-2.6-.7-1.3 0-2.6.8-3.3 2-1.4 2.4-.4 6 1 8 .7 1 1.4 2 2.5 2 1 0 1.3-.6 2.5-.6s1.5.6 2.6.6c1 0 1.7-1 2.4-2 .5-.7.8-1.5.9-1.6-2-.8-2.3-3.3-2.3-3.4zM14.6 6.3c.5-.7.9-1.6.8-2.6-.8 0-1.8.6-2.4 1.3-.5.6-1 1.5-.8 2.4.9.1 1.8-.4 2.4-1.1z"/></svg><span class="ab"><small>{{ ___('Download on') }}</small><b>App Store</b></span></a>@endif
                    @if($zvAppAndroid !== '')<a class="zv-appbadge" href="{{ $zvAppAndroid }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24"><path d="M4 3.3v17.4c0 .5.5.8.9.5l9.4-8.7-9.4-8.7c-.4-.4-.9-.1-.9.5z"/><path d="M16.6 9.8l-2.3-1.3L5 3.1zM5 20.9l9.3-5.4 2.3-1.3zM20 11.2l-2.9-1.6-2.6 2.4 2.6 2.4L20 12.8c.6-.4.6-1.2 0-1.6z"/></svg><span class="ab"><small>{{ ___('Available on') }}</small><b>Google Play</b></span></a>@endif
                </div>
            </div>
            @endif
        </div></div>
        <div class="zv-ft-pays">
            <span class="zv-pay">Pay</span><span class="zv-pay stc">stc pay</span><span class="zv-pay mada">mada</span>
            <span class="zv-pay tabby">tabby</span><span class="zv-pay mc">MC</span><span class="zv-pay visa">VISA</span>
        </div>
        <div class="zv-ft-bottom"><div class="zv-ft-bottom-in">
            <div class="zv-ft-brow">
                <span class="zv-ft-brand"><svg viewBox="0 0 24 24"><path d="M4 8h16l-1.6 11a2 2 0 01-2 1.8H7.6a2 2 0 01-2-1.8L4 8z"/><path d="M8 8V6a4 4 0 018 0v2"/></svg> {{ $store->title }}</span>
                <span class="zv-ft-legal"><button type="button" data-zv-nav="store">{{ ___('Terms') }}</button><button type="button" data-zv-nav="store">{{ ___('Privacy') }}</button><button type="button" data-zv-nav="account">{{ ___('Help') }}</button></span>
            </div>
            @php
                // Fiscal identifiers come from the store's own settings (the same
                // keys the fiscal receipt uses), never hardcoded — a placeholder CR/
                // VAT would read as this store's real legal number. Show each only
                // when set; drop the whole line when the store has neither.
                $zvCr  = trim((string) store_options($store->id, 'fiscal_cr_number'));
                $zvVat = trim((string) store_options($store->id, 'fiscal_vat_number'));
            @endphp
            <div class="zv-ft-compliance">
                {{ ___('All rights reserved') }} © {{ date('Y') }} {{ $store->title }}
                @if($zvCr !== '' || $zvVat !== '')
                    <br>
                    @if($zvCr !== ''){{ ___('Commercial Registration No.') }} <b>{{ $zvCr }}</b>@endif
                    @if($zvCr !== '' && $zvVat !== '') | @endif
                    @if($zvVat !== ''){{ ___('VAT No.') }} <b>{{ $zvVat }}</b>@endif
                @endif
            </div>
        </div></div>
    </footer>

    <nav class="zv-tabbar" id="zvTabbar" aria-label="{{ ___('Primary') }}">
        <button type="button" class="zv-tab" data-zv-nav="home" aria-current="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-9"/></svg>
            <span>{{ ___('Home') }}</span>
        </button>
        <button type="button" class="zv-tab" data-zv-nav="store" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="7" cy="7" r="2"/><circle cx="17" cy="7" r="2"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>
            <span>{{ ___('Explore') }}</span>
        </button>
        <button type="button" class="zv-fab" id="zvCartFab" data-zv-nav="cart" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
            <i class="zv-badge" id="zvCartBadge" hidden>0</i>
        </button>
        <button type="button" class="zv-tab" data-zv-nav="activity" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>
            <span>{{ ___('Activity') }}</span>
        </button>
        <button type="button" class="zv-tab" data-zv-nav="account" aria-current="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
            <span>{{ ___('Account') }}</span>
        </button>
    </nav>

</div>

{{-- Real notification centre. The header bell (#zvNotifBell) forwards to this
     hidden proxy that the shared mb-notify.js engine binds (proxy pattern, as in
     lumiere) — sidesteps zevoa's own click handling. Panel placed outside the
     screen sections so the screen router never hides it. --}}
<button type="button" id="mbNotifBell" class="zv-nb-proxy" aria-hidden="true" tabindex="-1"></button>
<i id="mbNotifDot" hidden></i>
@include('store_templates._partials.notif-bell')
<script nonce="{{ csp_nonce() }}">
    (function () {
        var proxy = document.getElementById('mbNotifBell');
        var srcDot = document.getElementById('mbNotifDot');
        var bell = document.getElementById('zvNotifBell');
        if (bell) { bell.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (proxy && window.mbNotify) { proxy.click(); } }); }
        if (srcDot) {
            var mirror = function () { var d = bell && bell.querySelector('.zv-notif-dot'); if (d) { d.hidden = srcDot.hidden; } };
            new MutationObserver(mirror).observe(srcDot, { attributes: true, attributeFilter: ['hidden'] });
            mirror();
        }
        var shareBtn = document.getElementById('zvAccShare');
        if (shareBtn) {
            shareBtn.addEventListener('click', function () {
                var url = shareBtn.getAttribute('data-share-url') || location.href;
                var title = shareBtn.getAttribute('data-share-title') || document.title;
                if (navigator.share) { navigator.share({ title: title, url: url }).catch(function () {}); }
                else if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(function () { if (window.toast) toast('{{ ___('Link copied') }}'); }, function () {}); }
            });
        }
    })();
</script>

@if(($postOptions->customer_auth_mode ?? '') !== 'demo')
    {{-- Real customer auth (login / phone-OTP / register), skinned to zevoa. --}}
    @include('store_templates._partials.mb-auth-sheet', ['store' => $store])
    @if(auth('customer')->check() && \App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
        @include('store_templates._partials.mb-wallet-sheet', ['store' => $store])
    @endif
    @include('store_templates._partials.mb-refund', ['store' => $store])
    <style nonce="{{ csp_nonce() }}">
        .mba-sheet{--mba-surface:var(--zv-card);--mba-ink:var(--zv-ink);--mba-muted:var(--zv-ink2);
            --mba-border:var(--zv-hair2);--mba-hair:var(--zv-hair);--mba-in-bg:var(--zv-card);
            --mba-accent:var(--zv-brand);--mba-on-accent:var(--zv-brand-ink,#fff);
            --mba-accent-ring:var(--zv-hair2);--mba-radius:20px}
    </style>
    <script nonce="{{ csp_nonce() }}">
    (function () {
        var hint = document.getElementById('zvRewardsSigninHint');
        if (hint) { hint.setAttribute('data-mb-auth-open', ''); hint.style.cursor = 'pointer'; }
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

@push('script')
{{-- Device-local guest order history (window.mbGuestOrders) — shared across
     themes so a guest still sees the order they just placed on the Activity
     screen. Included before the main script so the API is ready. --}}
@include('store_templates._partials.guest-orders')
{{-- Delivery-address autocomplete engine (window.mbAddrAutocomplete). --}}
@include('store_templates._partials.address-autocomplete')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var ITEMS = @json($jsItems);
    var MODES = @json($modes);
    var SLOT_ROUTE = @json($slotRoute);
    // rev/multi-vertical Lane V — native goods checkout (Lane B's
    // GoodsCheckoutController). Each item's own `addRoute` (see ITEMS above)
    // posts to Lane B's GoodsCartController instead.
    var CHECKOUT_ROUTE = @json($checkoutRoute);
    var MY_ORDERS_ROUTE = @json(\Illuminate\Support\Facades\Route::has('publicView.myOrders.index') ? route('publicView.myOrders.index', $store->id) : null);
    var IS_CUSTOMER = @json(auth('customer')->check());
    var COUPON_ROUTE = @json(\Illuminate\Support\Facades\Route::has('store.applyCoupon') ? route('store.applyCoupon', $store->id) : null);
    var CART_LINE_ROUTE_TEMPLATE = @json($cartLineRouteTemplate);
    function cartLineRoute(lineId) { return CART_LINE_ROUTE_TEMPLATE.replace('999999999', String(lineId)); }
    var CSRF = @json(csrf_token());
    // Curated core features (loop/enrich) — public feeds + the auth:customer
    // loyalty balance endpoint, all built the same "from $store" way as the
    // routes above.
    var STORIES_ROUTE = @json($storiesRoute);
    var MBR_BASE = @json($mbrBase);
    var LOYALTY_BALANCE_ROUTE = @json($loyaltyBalanceRoute);
    var LOY = @json($loyaltyTeaser);
    // The store's own currency contract (same shape as shelf's CUR block),
    // so every client-computed total matches price_symbol_format() exactly.
    var CUR = {
        sign: @json($curSign),
        gap: @json($curGap),
        pos: @json($curPos),
        decimals: @json($curDecimals),
    };
    // Mirrors Order::computeTax() exactly: a client-side ESTIMATE of the same
    // real tax the store charges, never a fabricated fee. 0 for every store
    // with no tax configured.
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
        soldOut: @json(___('Sold out')),
        chooseOption: @json(___('Please choose an option.')),
        cartEmpty: @json(___('Your cart is empty.')),
        placing: @json(___('Placing order…')),
        genericError: @json(___('Something went wrong. Please try again.')),
        nameRequired: @json(___('Please enter your name.')),
        addressRequired: @json(___('Please enter a delivery address.')),
        removeFav: @json(___('Remove from favorites')),
        addFav: @json(___('Add to favorites')),
        stockLeft: @json(___(':count left')),
        inStock: @json(___('In stock')),
        added: @json(___('Added to cart')),
        removed: @json(___('Removed')),
        savedFav: @json(___('Saved to favourites')),
        comingSoon: @json(___('Coming soon in this demo')),
        notifOn: @json(___('Notifications on')),
        notifOff: @json(___('Notifications off')),
        clearConfirm: @json(___('Clear your cart, favourites and saved addresses on this device?')),
        cleared: @json(___('Saved data cleared')),
        defaultLabel: @json(___('Default')),
        setDefault: @json(___('Set as default')),
        deleteLabel: @json(___('Delete')),
        addressWord: @json(___('Address')),
        addressSaved: @json(___('Address saved')),
        addressNeeded: @json(___('Please enter the full address.')),
        mapLocating: @json(___('Finding address…')),
        mapHint: @json(___('Move the map to place the pin')),
        newsInvalid: @json(___('Enter a valid email address.')),
        newsThanks: @json(___('Thanks for subscribing!')),
        newsError: @json(___('Could not subscribe. Please try again.')),
        billReady: @json(___('Bill ready (demo)')),
        thanksRating: @json(___('Thanks for your feedback!')),
        remove: @json(___('Remove')),
        decrease: @json(___('Decrease')),
        increase: @json(___('Increase')),
        justNow: @json(___('Just now')),
        inProgress: @json(___('In progress')),
        pending: @json(___('Pending')),
        // Curated core features (loop/enrich)
        anonymous: @json(___('Anonymous')),
        oneReview: @json(___('1 review')),
        reviewsCount: @json(___(':count reviews')),
        pickStars: @json(___('Please choose a star rating.')),
        reviewThanks: @json(___('Thanks for your review!')),
        applyReward: @json(___('Apply reward')),
        applyDiscount: @json(___('Apply :discount')),
        signInEarn: @json(___('Sign in to earn & redeem points')),
        points: @json(___('points')),
    };

    var root = document.getElementById('zevoa');
    if (!root) { return; }
    var storeId = root.getAttribute('data-store-id') || '';

    function escapeHtml(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    var escapeAttr = escapeHtml;
    var ICON_HEART = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>';
    var ICON_PLUS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
    var ICON_BOX = '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 8h16l-1.6 11a2 2 0 0 1-2 1.8H7.6a2 2 0 0 1-2-1.8L4 8z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/></svg>';

    /* ---------------- toast ---------------- */
    var toastEl = document.getElementById('zvToast');
    var toastTimer = null;
    function toast(msg) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.classList.add('is-show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('is-show'); }, 1800);
    }
    document.querySelectorAll('[data-zv-soon]').forEach(function (btn) {
        btn.addEventListener('click', function () { toast(T.comingSoon); });
    });

    /* ---------------- dark mode ---------------- */
    var darkKey = 'zevoa-theme-' + storeId;
    try {
        var savedTheme = window.localStorage.getItem(darkKey);
        if (savedTheme) { document.documentElement.setAttribute('data-theme', savedTheme); }
    } catch (e) { /* storage may be unavailable — default (system) stands */ }
    var darkBtn = document.getElementById('zvDarkToggle');
    if (darkBtn) {
        darkBtn.addEventListener('click', function () {
            var cur = document.documentElement.getAttribute('data-theme');
            var next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { window.localStorage.setItem(darkKey, next); } catch (e) { /* best-effort */ }
        });
    }

    /* ---------------- currency ---------------- */
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
    // PRESENTATIONAL demo balance (see zv-walletcard docblock in the markup
    // above) — only the FORMATTING is real (this store's own currency
    // sign/position/decimals), never a real wallet figure.
    var walletAmtEl = document.getElementById('zvWalletAmt');
    if (walletAmtEl) { walletAmtEl.textContent = formatMoney(1430.67); }

    /* ---------------- screen navigation ---------------- */
    var screens = {};
    document.querySelectorAll('.zv-screen').forEach(function (el) { screens[el.getAttribute('data-zv-screen')] = el; });
    var tabbarEl = document.getElementById('zvTabbar');
    var TAB_SCREENS = { home: 1, store: 1, activity: 1, account: 1 };
    var stack = ['home'];
    var currentScreen = 'home';

    function paintTabbar(name) {
        if (tabbarEl) {
            tabbarEl.querySelectorAll('.zv-tab').forEach(function (btn) {
                btn.setAttribute('aria-current', btn.getAttribute('data-zv-nav') === name ? 'true' : 'false');
            });
        }
        // Desktop top-nav links (see .zv-dlink in the markup) mirror the same
        // "which screen is active" state the mobile tabbar shows above.
        document.querySelectorAll('.zv-dlink[data-zv-nav]').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-zv-nav') === name);
        });
    }

    function showScreen(name, opts) {
        opts = opts || {};
        var el = screens[name];
        if (!el) { return; }
        Object.keys(screens).forEach(function (key) {
            var s = screens[key];
            s.classList.remove('zv-anim-push', 'zv-anim-fade');
            s.hidden = key !== name;
        });
        el.classList.add(opts.fade ? 'zv-anim-fade' : 'zv-anim-push');
        if (tabbarEl) { tabbarEl.hidden = !TAB_SCREENS[name]; }
        paintTabbar(name);
        currentScreen = name;
        // Remember the active screen for THIS tab so a same-tab reload (a language
        // switch is a full page navigation) lands back here, not on home — otherwise
        // the shopper's order history/activity looks "gone" after switching language.
        try { window.sessionStorage.setItem('mb-screen-' + storeId, name); } catch (e) { /* best-effort */ }
        window.scrollTo(0, 0);
        if (!opts.silent) {
            var heading = el.querySelector('.zv-hdr-title, .zv-nm');
            if (heading) {
                setTimeout(function () {
                    try { heading.setAttribute('tabindex', '-1'); heading.focus({ preventScroll: true }); } catch (e) { /* best-effort */ }
                }, 30);
            }
        }
    }

    function goTo(name, opts) {
        opts = opts || {};
        if (!screens[name]) { return; }
        if (name === currentScreen) { return; }
        if (name === 'cart') { renderCart(); }
        if (name === 'checkout') { openCheckout(); }
        if (name === 'favorites') { renderFavorites(); }
        if (name === 'addresses') { renderAddresses(); }
        if (name === 'activity') { renderActivity(); }
        if (name === 'account') { renderRewardsCard(); }
        var fade = !!(TAB_SCREENS[name] && TAB_SCREENS[currentScreen]);
        if (opts.replace && stack.length) { stack[stack.length - 1] = name; } else { stack.push(name); }
        showScreen(name, { fade: fade });
    }
    function goBack() {
        stack.pop();
        var prev = stack[stack.length - 1] || 'home';
        if (prev === 'cart') { renderCart(); }
        if (prev === 'activity') { renderActivity(); }
        if (prev === 'favorites') { renderFavorites(); }
        if (prev === 'addresses') { renderAddresses(); }
        if (prev === 'account') { renderRewardsCard(); }
        showScreen(prev, { fade: true });
    }
    document.querySelectorAll('[data-zv-nav]').forEach(function (btn) {
        btn.addEventListener('click', function () { goTo(btn.getAttribute('data-zv-nav')); });
    });
    // Promo-banner CTA: an in-app banner (empty or "#screen" link_url) deep-links
    // to its SPA screen instead of firing a dead href="#". Close the story viewer
    // first so a fixed overlay never hides wherever the CTA lands.
    document.querySelectorAll('.zv-banner[data-zv-banner-cta]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var viewer = document.getElementById('zvStoryViewer');
            if (viewer && !viewer.hidden) { closeStory(); }
            goTo(el.getAttribute('data-zv-banner-cta'));
        });
    });
    document.querySelectorAll('[data-zv-back]').forEach(function (btn) {
        btn.addEventListener('click', goBack);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !TAB_SCREENS[currentScreen] && currentScreen !== 'confirm') { goBack(); }
    });

    /* ---------------- stories rail + full-screen viewer (curated core
       feature) — public/ungated feed, hides the whole rail when empty,
       marks viewed bubbles in localStorage like Instagram. ---------------- */
    var STORIES_VIEWED_KEY = 'zevoa-stories-viewed-' + storeId;
    var storiesViewed = {};
    try {
        (JSON.parse(window.localStorage.getItem(STORIES_VIEWED_KEY) || '[]') || []).forEach(function (id) { storiesViewed[id] = true; });
    } catch (e) { storiesViewed = {}; }
    function saveStoriesViewed() {
        try { window.localStorage.setItem(STORIES_VIEWED_KEY, JSON.stringify(Object.keys(storiesViewed))); } catch (e) { /* best-effort */ }
    }

    var STORIES = [];
    var storyIdx = 0;
    var storyTimer = null;
    var STORY_DURATION = 5000;
    var railEl = document.getElementById('zvStories');
    var viewerEl = document.getElementById('zvStoryViewer');
    var viewerBarsEl = document.getElementById('zvViewerBars');
    var viewerImgEl = document.getElementById('zvViewerImg');
    var viewerCaptionEl = document.getElementById('zvViewerCaption');
    var viewerCtaEl = document.getElementById('zvViewerCta');

    function loadStories() {
        if (!railEl || !STORIES_ROUTE) { return; }
        fetch(STORIES_ROUTE, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            // The public endpoint wraps the feed as {data:[...]}; each item's
            // ready-to-use image URL comes back as `media_url`.
            .then(function (payload) {
                var list = (payload && Array.isArray(payload.data)) ? payload.data : (Array.isArray(payload) ? payload : []);
                STORIES = list.slice().sort(function (a, b) { return (a.sort || 0) - (b.sort || 0); });
                if (!STORIES.length) { railEl.hidden = true; return; }
                railEl.innerHTML = STORIES.map(function (s, i) {
                    var viewed = !!storiesViewed[s.id];
                    var img = s.media_url ? '<img src="' + escapeAttr(s.media_url) + '" alt="" data-csp-error="hide">' : '';
                    var cap = s.caption ? escapeHtml(s.caption) : '';
                    return '<button type="button" class="zv-story-bubble' + (viewed ? ' is-viewed' : '') + '" data-story-idx="' + i + '" aria-label="' + escapeAttr(s.caption || @json(___('Story'))) + '">' +
                        '<span class="zv-story-ring">' + img + '</span>' +
                        (cap ? '<span class="zv-story-cap">' + cap + '</span>' : '') +
                        '</button>';
                }).join('');
                railEl.querySelectorAll('[data-story-idx]').forEach(function (btn) {
                    btn.addEventListener('click', function () { openStory(parseInt(btn.getAttribute('data-story-idx'), 10)); });
                });
                railEl.hidden = false;
            })
            .catch(function () { railEl.hidden = true; });
    }

    function buildStoryBars() {
        viewerBarsEl.innerHTML = STORIES.map(function () { return '<span class="zv-viewer-bar"><i></i></span>'; }).join('');
    }
    function stopStoryTimer() {
        if (storyTimer) { clearTimeout(storyTimer); storyTimer = null; }
    }
    function animateCurrentBar() {
        stopStoryTimer();
        var bars = viewerBarsEl.querySelectorAll('.zv-viewer-bar');
        for (var i = 0; i < bars.length; i++) {
            bars[i].classList.toggle('is-done', i < storyIdx);
            if (i > storyIdx) { bars[i].querySelector('i').style.width = '0%'; }
        }
        var curBar = bars[storyIdx];
        if (!curBar) { return; }
        var bar = curBar.querySelector('i');
        bar.style.transition = 'none';
        bar.style.width = '0%';
        void bar.offsetWidth; // force reflow so the transition below actually animates
        bar.style.transition = 'width ' + STORY_DURATION + 'ms linear';
        bar.style.width = '100%';
        storyTimer = setTimeout(function () { goStoryNext(); }, STORY_DURATION);
    }
    function renderStorySlide(idx) {
        var s = STORIES[idx];
        if (!s) { closeStory(); return; }
        storyIdx = idx;
        storiesViewed[s.id] = true;
        saveStoriesViewed();
        var bubble = railEl.querySelector('.zv-story-bubble[data-story-idx="' + idx + '"]');
        if (bubble) { bubble.classList.add('is-viewed'); }
        viewerImgEl.src = s.media_url || '';
        viewerCaptionEl.textContent = s.caption || '';
        viewerCaptionEl.hidden = !s.caption;
        if (s.cta_url) {
            viewerCtaEl.hidden = false;
            viewerCtaEl.textContent = s.cta_label || @json(___('Learn more'));
            viewerCtaEl.href = s.cta_url;
            var isRelative = s.cta_url.charAt(0) === '/' && s.cta_url.charAt(1) !== '/';
            viewerCtaEl.target = isRelative ? '_self' : '_blank';
        } else {
            viewerCtaEl.hidden = true;
            viewerCtaEl.removeAttribute('href');
        }
        animateCurrentBar();
    }
    function goStoryNext() {
        if (storyIdx >= STORIES.length - 1) { closeStory(); return; }
        renderStorySlide(storyIdx + 1);
    }
    function goStoryPrev() {
        renderStorySlide(Math.max(0, storyIdx - 1));
    }
    function openStory(idx) {
        if (!STORIES.length) { return; }
        buildStoryBars();
        viewerEl.hidden = false;
        document.body.style.overflow = 'hidden';
        renderStorySlide(idx);
    }
    function closeStory() {
        stopStoryTimer();
        viewerEl.hidden = true;
        document.body.style.overflow = '';
    }
    document.getElementById('zvViewerClose').addEventListener('click', closeStory);
    document.getElementById('zvViewerPrev').addEventListener('click', function () { stopStoryTimer(); goStoryPrev(); });
    document.getElementById('zvViewerNext').addEventListener('click', function () { stopStoryTimer(); goStoryNext(); });
    document.addEventListener('keydown', function (e) {
        if (viewerEl.hidden) { return; }
        if (e.key === 'Escape') { closeStory(); }
        else if (e.key === 'ArrowRight') { stopStoryTimer(); goStoryNext(); }
        else if (e.key === 'ArrowLeft') { stopStoryTimer(); goStoryPrev(); }
    });
    var storyTouchX = null;
    viewerEl.addEventListener('touchstart', function (e) { storyTouchX = e.changedTouches[0].clientX; }, { passive: true });
    viewerEl.addEventListener('touchend', function (e) {
        if (storyTouchX === null) { return; }
        var dx = e.changedTouches[0].clientX - storyTouchX;
        storyTouchX = null;
        if (Math.abs(dx) < 40) { return; }
        var rtl = document.documentElement.getAttribute('dir') === 'rtl';
        var forward = rtl ? dx > 0 : dx < 0;
        stopStoryTimer();
        if (forward) { goStoryNext(); } else { goStoryPrev(); }
    }, { passive: true });

    /* ---------------- favorites (localStorage, same posture as shelf) ---------------- */
    var FAV_KEY = 'zevoa-favs-' + storeId;
    var favIds = {};
    try {
        (JSON.parse(window.localStorage.getItem(FAV_KEY) || '[]') || []).forEach(function (id) { favIds[id] = true; });
    } catch (e) { favIds = {}; }
    function saveFavs() {
        try { window.localStorage.setItem(FAV_KEY, JSON.stringify(Object.keys(favIds))); } catch (e) { /* best-effort */ }
    }
    function paintHeartBtn(btn, isFav) {
        btn.setAttribute('aria-pressed', isFav ? 'true' : 'false');
        btn.setAttribute('aria-label', isFav ? T.removeFav : T.addFav);
    }
    function toggleFav(id) {
        var isFav = !favIds[id];
        if (isFav) { favIds[id] = true; } else { delete favIds[id]; }
        saveFavs();
        toast(isFav ? T.savedFav : T.removed);
        document.querySelectorAll('[data-zv-heart="' + id + '"]').forEach(function (b) {
            paintHeartBtn(b, isFav);
            b.classList.add('is-pop');
            setTimeout(function () { b.classList.remove('is-pop'); }, 400);
        });
        if (currentScreen === 'favorites') { renderFavorites(); }
    }

    /* ---------------- shared product-card HTML (mirrors _partials/product-card.blade.php) ---------------- */
    function cardHTML(item) {
        var liked = !!favIds[item.id];
        var discBadge = item.discountPct > 0 ? '<span class="zv-disc">-' + item.discountPct + '%</span>' : '';
        var soldBadge = item.soldOut ? '<span class="zv-soldout">' + escapeHtml(T.soldOut) + '</span>' : '';
        var imgHtml = item.img
            ? '<img src="' + escapeAttr(item.img) + '" alt="" loading="lazy" data-csp-error="hide">'
            : '<div class="zv-img-ph" role="img" aria-label="' + escapeAttr(item.name) + '">' + ICON_BOX + '</div>';
        var unitHtml = item.unit ? '<div class="zv-punit">' + escapeHtml(item.unit) + '</div>' : '';
        var brandHtml = item.brand ? '<span class="zv-pbrand">' + escapeHtml(item.brand) + '</span>' : '';
        var wasHtml = item.compareAtFormatted ? '<span class="zv-price zv-price--was">' + escapeHtml(item.compareAtFormatted) + '</span>' : '';
        return '<article class="zv-pcard" data-id="' + item.id + '">' +
            '<div class="zv-imgwrap">' + discBadge + soldBadge +
            '<a href="?item=' + item.id + '" class="zv-card-media-link" data-zv-open-link="' + item.id + '" aria-label="' + escapeAttr(item.name) + '">' + imgHtml + '</a>' +
            '<button type="button" class="zv-heart" data-zv-heart="' + item.id + '" aria-pressed="' + (liked ? 'true' : 'false') + '" aria-label="' + escapeAttr(liked ? T.removeFav : T.addFav) + '">' + ICON_HEART + '</button>' +
            '<button type="button" class="zv-addbag" data-zv-add="' + item.id + '" aria-label="' + escapeAttr(T.addToCart) + '"' + (item.soldOut ? ' disabled' : '') + '>' + ICON_PLUS + '</button>' +
            '</div><div class="zv-pbody">' + brandHtml +
            '<div class="zv-pname">' + escapeHtml(item.name) + '</div>' + unitHtml +
            '<div class="zv-prow"><span class="zv-price">' + escapeHtml(item.priceFormatted) + '</span>' + wasHtml + '</div>' +
            '</div></article>';
    }

    function wireCards(container) {
        container.querySelectorAll('[data-zv-heart]').forEach(function (btn) {
            if (btn._zvWired) { return; }
            btn._zvWired = true;
            btn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                toggleFav(btn.getAttribute('data-zv-heart'));
            });
        });
        container.querySelectorAll('[data-zv-add]').forEach(function (btn) {
            if (btn._zvWired) { return; }
            btn._zvWired = true;
            btn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                quickAdd(parseInt(btn.getAttribute('data-zv-add'), 10), btn);
            });
        });
        container.querySelectorAll('[data-zv-open-link]').forEach(function (btn) {
            if (btn._zvWired) { return; }
            btn._zvWired = true;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openProduct(parseInt(btn.getAttribute('data-zv-open-link'), 10));
            });
        });
    }

    function renderFavorites() {
        var grid = document.getElementById('zvFavGrid');
        var empty = document.getElementById('zvFavEmpty');
        var ids = Object.keys(favIds);
        if (!ids.length) { grid.innerHTML = ''; empty.hidden = false; return; }
        empty.hidden = true;
        grid.innerHTML = ids.map(function (id) { return ITEMS[id] ? cardHTML(ITEMS[id]) : ''; }).join('');
        wireCards(grid);
    }

    /* ---------------- cart (localStorage preview + REAL server cart, same
       posture as shelf's rev/multi-vertical Lane V integration) ---------------- */
    var CART_KEY = 'zevoa-cart-' + storeId;
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
    function findLine(itemId, variantId) {
        return cart.filter(function (l) { return l.id === itemId && l.variantId === (variantId || null); })[0];
    }
    function lineUnitPrice(line) {
        var item = ITEMS[line.id];
        if (!item) { return 0; }
        var unit = item.price;
        if (line.variantId) {
            var v = item.variants.filter(function (x) { return x.id === line.variantId; })[0];
            if (v) { unit = v.price; }
        }
        return unit;
    }
    function lineTotal(line) { return lineUnitPrice(line) * line.qty; }
    function cartCount() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }
    function subtotal() { return cart.reduce(function (s, l) { return s + lineTotal(l); }, 0); }

    // PRESENTATIONAL fee lines + voucher only — see the Blade comment above
    // #zvSumHandling: these are small fixed demo figures for a realistic
    // super-app summary, NEVER sent to the server and never allowed to
    // override the order's own authoritative total (theme-totals-contract —
    // Order::getTotalAttribute() is what the customer is actually charged,
    // shown for real on the confirm/track pages once the order exists).
    var voucherApplied = false;
    var voucherCode = '';
    var voucherServerDiscount = 0; // real discount from restaurant.applyCoupon; the order re-derives it server-side
    var voucherSig = '';
    /* Bug batch 7 · 4: the cart lines go to the coupon check (submitOrder's `items` shape) — a buy-X-get-Y coupon is
       priced on them, exactly as the placed order is — and the quote is refreshed when the basket changes. */
    function couponLines() {
        return cart.map(function (l) {
            return { id: l.id, quantity: l.qty, variants: l.variantId || 0, extras: (l.extras || []).map(function (x) { return { id: (x && x.id) || x }; }) };
        });
    }
    function voucherSigFor(sub) { var m = selectedMode(); return JSON.stringify([sub, couponLines(), (m && m.value) || '']); }
    function voucherForm(code, sub) {
        var m = selectedMode(), fd = new FormData();
        fd.append('_token', CSRF); fd.append('code', code); fd.append('subtotal', sub);
        fd.append('items', JSON.stringify(couponLines())); fd.append('ordering-type', (m && m.value) || '');
        return fd;
    }
    function requoteVoucher(sub) {
        if (!voucherApplied || !voucherCode || !COUPON_ROUTE) { return; }
        var sig = voucherSigFor(sub);
        if (sig === voucherSig) { return; }
        voucherSig = sig;
        var code = voucherCode;
        fetch(COUPON_ROUTE, { method: 'POST', body: voucherForm(code, sub), headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (voucherCode !== code || voucherSig !== sig) { return; }
                voucherServerDiscount = d && d.success ? (parseFloat(d.discount) || 0) : 0;
                renderCart();
            })
            .catch(function () {});
    }
    function totals() {
        var sub = subtotal();
        requoteVoucher(sub); // bug batch 7 · 4: the basket changed since the quote → re-quote
        var handling = sub > 0 ? 2 : 0;
        var packaging = sub > 0 ? 0.5 : 0;
        var voucherDiscount = (voucherApplied && sub > 0) ? Math.min(voucherServerDiscount, sub) : 0;
        var tax = computeTax(sub);
        return {
            sub: sub, handling: handling, packaging: packaging, voucherDiscount: voucherDiscount, tax: tax,
            total: Math.max(0, sub + handling + packaging + tax - voucherDiscount),
        };
    }

    function renderCartBadge() {
        var n = cartCount();
        var badge = document.getElementById('zvCartBadge');
        if (badge) { badge.textContent = String(n); badge.hidden = n === 0; }
        // Desktop top-nav cart button (see #zvCartBadgeDesktop in the markup)
        // mirrors the same count shown on the mobile tabbar's FAB badge.
        var badgeDesktop = document.getElementById('zvCartBadgeDesktop');
        if (badgeDesktop) { badgeDesktop.textContent = String(n); badgeDesktop.hidden = n === 0; }
    }

    function cartLineHTML(line, idx) {
        var item = ITEMS[line.id];
        if (!item) { return ''; }
        var variantLabel = '';
        if (line.variantId) {
            var v = item.variants.filter(function (x) { return x.id === line.variantId; })[0];
            if (v) { variantLabel = v.label; }
        }
        var imgHtml = item.img ? '<img src="' + escapeAttr(item.img) + '" alt="" data-csp-error="hide">' : '';
        return '<div class="zv-citem">' +
            '<div class="zv-top">' +
            '<div class="zv-cthumb">' + imgHtml + '</div>' +
            '<div class="zv-cinfo"><div class="zv-cname">' + escapeHtml(item.name) + (variantLabel ? ' &middot; ' + escapeHtml(variantLabel) : '') + '</div>' +
            (item.unit ? '<div class="zv-ccol">' + escapeHtml(item.unit) + '</div>' : '') + '</div>' +
            '<div style="text-align:end"><button type="button" class="zv-cremove" data-zv-remove="' + idx + '">' + escapeHtml(T.remove) + '</button>' +
            '<div class="zv-cprice" style="margin-top:8px">' + escapeHtml(formatMoney(lineTotal(line))) + '</div></div>' +
            '</div>' +
            '<div class="zv-cfoot"><span></span><div class="zv-stepper">' +
            '<button type="button" class="zv-step" data-zv-dec="' + idx + '" aria-label="' + escapeAttr(T.decrease) + '">&minus;</button>' +
            '<span class="zv-qty">' + line.qty + '</span>' +
            '<button type="button" class="zv-step" data-zv-inc="' + idx + '" aria-label="' + escapeAttr(T.increase) + '">+</button>' +
            '</div></div></div>';
    }

    function wireCartLineButtons(container) {
        container.querySelectorAll('[data-zv-remove]').forEach(function (btn) {
            btn.addEventListener('click', function () { removeCartLine(parseInt(btn.getAttribute('data-zv-remove'), 10)); });
        });
        container.querySelectorAll('[data-zv-inc]').forEach(function (btn) {
            btn.addEventListener('click', function () { bumpCartLine(parseInt(btn.getAttribute('data-zv-inc'), 10), 1); });
        });
        container.querySelectorAll('[data-zv-dec]').forEach(function (btn) {
            btn.addEventListener('click', function () { bumpCartLine(parseInt(btn.getAttribute('data-zv-dec'), 10), -1); });
        });
    }

    function removeCartLine(idx) {
        var line = cart[idx];
        if (!line) { return; }
        cart.splice(idx, 1);
        saveCart();
        renderCart();
        if (line.lineId) {
            var fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('quantity', '0');
            fetch(cartLineRoute(line.lineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .catch(function () { /* best-effort */ });
        }
    }

    // Real quantity sync — PATCHes the existing server cart line via the
    // confirmed storefront.goods.cart.line.update contract (absolute
    // quantity; {success:true} only, no total returned, so this keeps
    // computing totals from the local mirror, same as shelf's own cart).
    function bumpCartLine(idx, delta) {
        var line = cart[idx];
        if (!line) { return; }
        var newQty = line.qty + delta;
        if (newQty <= 0) { removeCartLine(idx); return; }
        line.qty = newQty;
        saveCart();
        renderCart();
        if (line.lineId) {
            var fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('quantity', String(newQty));
            fetch(cartLineRoute(line.lineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .catch(function () { /* best-effort */ });
        }
    }

    function addToCartLocal(itemId, variantId, qty, lineId) {
        var existing = findLine(itemId, variantId);
        if (existing) {
            existing.qty += qty;
            if (lineId) { existing.lineId = lineId; }
        } else {
            cart.push({ id: itemId, variantId: variantId || null, qty: qty, lineId: lineId || null });
        }
        saveCart();
        renderCartBadge();
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
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'add-to-cart failed');
                }
                return data;
            });
    }

    function renderCart() {
        var linesEl = document.getElementById('zvCartLines');
        var emptyEl = document.getElementById('zvCartEmpty');
        var upsellWrap = document.getElementById('zvCartUpsellWrap');
        var upsellEl = document.getElementById('zvCartUpsell');
        var summaryEl = document.getElementById('zvCartSummary');
        if (!linesEl) { return; }
        if (!cart.length) {
            linesEl.innerHTML = '';
            emptyEl.hidden = false;
            upsellWrap.hidden = true;
            summaryEl.hidden = true;
            renderCartBadge();
            return;
        }
        emptyEl.hidden = true;
        summaryEl.hidden = false;
        linesEl.innerHTML = cart.map(cartLineHTML).join('');
        wireCartLineButtons(linesEl);

        var t = totals();
        document.getElementById('zvSumPrice').textContent = formatMoney(t.sub);
        document.getElementById('zvSumHandling').textContent = formatMoney(t.handling);
        document.getElementById('zvSumPackaging').textContent = formatMoney(t.packaging);
        var voucherRow = document.getElementById('zvSumVoucherRow');
        var voucherForm = document.getElementById('zvVoucherForm');
        if (voucherApplied) {
            voucherRow.hidden = false;
            document.getElementById('zvSumVoucher').textContent = '−' + formatMoney(t.voucherDiscount);
            voucherForm.hidden = true;
        } else {
            voucherRow.hidden = true;
            voucherForm.hidden = false;
        }
        var taxRow = document.getElementById('zvSumTaxRow');
        taxRow.hidden = t.tax <= 0;
        document.getElementById('zvSumTax').textContent = formatMoney(t.tax);
        document.getElementById('zvSumTotal').textContent = formatMoney(t.total);
        document.getElementById('zvCheckoutTotal').textContent = formatMoney(t.total);

        var cartIds = {};
        cart.forEach(function (l) { cartIds[l.id] = true; });
        var upsell = Object.keys(ITEMS).map(function (k) { return ITEMS[k]; }).filter(function (it) { return !cartIds[it.id] && !it.soldOut; }).slice(0, 6);
        if (upsell.length) {
            upsellWrap.hidden = false;
            upsellEl.innerHTML = upsell.map(cardHTML).join('');
            wireCards(upsellEl);
        } else {
            upsellWrap.hidden = true;
        }
        renderCartBadge();
    }

    var ageConfirmed = {};
    try {
        var storedAge = window.sessionStorage.getItem('zevoa-age-ok-' + storeId);
        if (storedAge) { ageConfirmed = JSON.parse(storedAge) || {}; }
    } catch (e) { ageConfirmed = {}; }

    function flyToCart(fromEl, imgSrc) {
        var fab = document.getElementById('zvCartFab');
        if (!fromEl || !fab) { return; }
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
        var a = fromEl.getBoundingClientRect();
        var b = fab.getBoundingClientRect();
        var el = document.createElement('div');
        el.className = 'zv-fly';
        if (imgSrc) {
            var img = document.createElement('img');
            img.src = imgSrc; img.alt = '';
            el.appendChild(img);
        }
        el.style.left = (a.left + a.width / 2 - 24) + 'px';
        el.style.top = (a.top + a.height / 2 - 28) + 'px';
        document.body.appendChild(el);
        var dx = (b.left + b.width / 2) - (a.left + a.width / 2);
        var dy = (b.top + b.height / 2) - (a.top + a.height / 2);
        var anim;
        try {
            anim = el.animate([
                { transform: 'translate(0,0) scale(1)', opacity: 1 },
                { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.2)', opacity: .3 },
            ], { duration: 550, easing: 'cubic-bezier(.5,-0.2,.4,1)' });
        } catch (e) { el.remove(); return; }
        anim.onfinish = function () {
            el.remove();
            fab.classList.add('is-bump');
            setTimeout(function () { fab.classList.remove('is-bump'); }, 320);
        };
    }

    function quickAdd(id, fromEl) {
        var item = ITEMS[id];
        if (!item || item.soldOut) { return; }
        if (item.ageRestricted && !ageConfirmed[id]) { openProduct(id); return; }
        var btn = fromEl || document.querySelector('[data-zv-add="' + id + '"]');
        if (btn) { btn.disabled = true; }
        postAddToCart(id, null, 1)
            .then(function (data) {
                addToCartLocal(id, null, 1, data.line_id);
                flyToCart(btn, item.img);
                toast(T.added);
                if (currentScreen === 'cart') { renderCart(); }
            })
            .catch(function () { toast(T.genericError); })
            .then(function () { if (btn) { btn.disabled = false; } });
    }

    /* ---------------- product screen (mirrors shelf's item sheet, rendered
       as a full screen instead of an off-canvas drawer) ---------------- */
    var currentItem = null;
    var currentVariantId = null;
    var currentQty = 1;

    function currentVariant() {
        if (!currentItem || !currentVariantId) { return null; }
        return currentItem.variants.filter(function (v) { return v.id === currentVariantId; })[0] || null;
    }
    function currentUnitPrice() {
        var v = currentVariant();
        return v ? v.price : (currentItem ? currentItem.price : 0);
    }
    function updateProductPrice() {
        document.getElementById('zvAddToCartPrice').textContent = formatMoney(currentUnitPrice() * currentQty);
        document.getElementById('zvPdPrice').textContent = formatMoney(currentUnitPrice());
    }
    function renderProductVariants() {
        var wrap = document.getElementById('zvVariantGroups');
        wrap.innerHTML = '';
        if (!currentItem.variants.length) { return; }
        var group = document.createElement('div');
        group.className = 'zv-group';
        var label = document.createElement('div');
        label.className = 'zv-group-label';
        label.textContent = @json(___('Choose an option'));
        var opts = document.createElement('div');
        opts.className = 'zv-opts';
        currentItem.variants.forEach(function (v) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'zv-opt' + (v.id === currentVariantId ? ' is-active' : '');
            btn.textContent = v.label + ' — ' + v.priceFormatted;
            var outOfStock = (v.stockQty !== null && v.stockQty !== undefined && v.stockQty <= 0);
            if (outOfStock) { btn.disabled = true; btn.title = T.outOfStock; }
            btn.addEventListener('click', function () {
                currentVariantId = v.id;
                renderProductVariants();
                updateProductPrice();
                renderProductStock();
            });
            opts.appendChild(btn);
        });
        group.appendChild(label);
        group.appendChild(opts);
        wrap.appendChild(group);
    }
    function renderProductAttrs() {
        var group = document.getElementById('zvAttrsGroup');
        var table = document.getElementById('zvAttrsTable');
        table.innerHTML = '';
        var rows = [];
        if (currentItem.brand) { rows.push([@json(___('Brand')), currentItem.brand]); }
        if (currentItem.sku) { rows.push([@json(___('SKU')), currentItem.sku]); }
        var v = currentVariant();
        if (v && v.sku) { rows.push([@json(___('Variant SKU')), v.sku]); }
        if (v && v.barcode) { rows.push([@json(___('Barcode')), v.barcode]); }
        if (currentItem.weight) { rows.push([@json(___('Weight')), currentItem.weight]); }
        if (currentItem.dimensions) { rows.push([@json(___('Dimensions')), currentItem.dimensions]); }
        if (currentItem.attrs) {
            Object.keys(currentItem.attrs).forEach(function (k) {
                if (k === 'brand' || k === 'unit' || k === 'compare_at_price') { return; }
                var val = currentItem.attrs[k];
                if (val === null || val === undefined || val === '') { return; }
                rows.push([k, String(val)]);
            });
        }
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
    function renderProductStock() {
        var badge = document.getElementById('zvPdStock');
        if (!badge || !currentItem) { return; }
        var v = currentVariant();
        var qty = (v && v.stockQty !== null && v.stockQty !== undefined) ? v.stockQty : currentItem.stockQty;
        if (!currentItem.trackInventory || qty === null || qty === undefined) { badge.hidden = true; return; }
        badge.hidden = false;
        badge.className = 'zv-stock';
        if (qty <= 0) { badge.classList.add('zv-stock--out'); badge.textContent = T.outOfStock; }
        else if (qty <= 5) { badge.classList.add('zv-stock--low'); badge.textContent = T.stockLeft.replace(':count', String(qty)); }
        else { badge.classList.add('zv-stock--in'); badge.textContent = T.inStock; }
    }

    /* ---------------- reviews (product screen, curated core feature) —
       reuses the .zv-stars markup style for both the read-only average/list
       stars and the write-form star picker. ---------------- */
    function starsHtml(n) {
        var out = '';
        for (var i = 1; i <= 5; i++) {
            out += '<svg class="' + (i <= n ? 'is-full' : '') + '" viewBox="0 0 24 24"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>';
        }
        return out;
    }
    function relativeTime(iso) {
        if (!iso) { return ''; }
        var then = new Date(iso).getTime();
        if (isNaN(then)) { return ''; }
        var diffSec = Math.max(0, (Date.now() - then) / 1000);
        if (diffSec < 60) { return T.justNow; }
        var mins = Math.floor(diffSec / 60);
        if (mins < 60) { return mins + 'm'; }
        var hours = Math.floor(mins / 60);
        if (hours < 24) { return hours + 'h'; }
        var days = Math.floor(hours / 24);
        if (days < 30) { return days + 'd'; }
        try { return new Date(iso).toLocaleDateString(document.documentElement.lang || undefined); } catch (e) { return ''; }
    }
    function reviewsUrl(menuId) { return MBR_BASE + '/' + menuId + '/reviews'; }

    var rvSelectedStars = 0;
    var rvStarsWrap = document.getElementById('zvRvStarsInput');
    if (rvStarsWrap) {
        rvStarsWrap.querySelectorAll('button').forEach(function (btn, i) {
            btn.addEventListener('click', function () {
                rvSelectedStars = i + 1;
                rvStarsWrap.querySelectorAll('button').forEach(function (b, j) { b.classList.toggle('is-on', j < rvSelectedStars); });
            });
        });
    }
    function resetReviewForm() {
        rvSelectedStars = 0;
        if (rvStarsWrap) { rvStarsWrap.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-on'); }); }
        var nameEl = document.getElementById('zvRvName');
        var bodyEl = document.getElementById('zvRvBody');
        var msgEl = document.getElementById('zvRvMsg');
        if (nameEl) { nameEl.value = ''; }
        if (bodyEl) { bodyEl.value = ''; }
        if (msgEl) { msgEl.innerHTML = ''; }
    }
    function renderReviews(payload) {
        var summary = document.getElementById('zvRvSummary');
        var list = document.getElementById('zvRvList');
        var empty = document.getElementById('zvRvEmpty');
        var count = (payload && payload.count) || 0;
        if (!count) {
            summary.hidden = true;
            list.innerHTML = '';
            empty.hidden = false;
            return;
        }
        empty.hidden = true;
        summary.hidden = false;
        var avg = (payload && payload.average) || 0;
        document.getElementById('zvRvAvgNum').textContent = (Math.round(avg * 10) / 10).toFixed(1);
        document.getElementById('zvRvAvgStars').innerHTML = starsHtml(Math.round(avg));
        document.getElementById('zvRvAvgCount').textContent = count === 1 ? T.oneReview : T.reviewsCount.replace(':count', String(count));
        var dist = (payload && payload.distribution) || {};
        var distEl = document.getElementById('zvRvDist');
        distEl.innerHTML = [5, 4, 3, 2, 1].map(function (star) {
            var n = dist[star] || dist[String(star)] || 0;
            var pct = count ? Math.round((n / count) * 100) : 0;
            return '<div class="zv-rv-bar" data-pct="' + pct + '"><span>' + star + '</span><span class="zv-rv-bar-track"><i></i></span><span>' + n + '</span></div>';
        }).join('');
        // Computed per-row fill width is set as a real DOM style (not baked
        // into the HTML string), same posture as the confirm screen's
        // #zvTrackBar progress fill below.
        distEl.querySelectorAll('.zv-rv-bar').forEach(function (row) {
            row.querySelector('.zv-rv-bar-track i').style.width = row.getAttribute('data-pct') + '%';
        });
        list.innerHTML = ((payload && payload.data) || []).map(function (rv) {
            var name = rv.name || rv.reviewer_name || T.anonymous;
            return '<div class="zv-rv-item">' +
                '<div class="zv-rv-item-head">' +
                '<span class="zv-rv-item-stars">' + starsHtml(rv.stars || 0) + '</span>' +
                '<span class="zv-rv-item-name">' + escapeHtml(name) + '</span>' +
                '<span class="zv-rv-item-date">' + escapeHtml(relativeTime(rv.created_at)) + '</span>' +
                '</div>' +
                (rv.body ? '<p class="zv-rv-item-body">' + escapeHtml(rv.body) + '</p>' : '') +
                '</div>';
        }).join('');
    }
    function loadReviews(menuId) {
        var summary = document.getElementById('zvRvSummary');
        var list = document.getElementById('zvRvList');
        var empty = document.getElementById('zvRvEmpty');
        if (!summary || !menuId) { return; }
        summary.hidden = true;
        list.innerHTML = '';
        empty.hidden = true;
        fetch(reviewsUrl(menuId) + '?filter=latest', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) { renderReviews(data || {}); })
            .catch(function () { empty.hidden = false; });
    }
    var rvSubmitBtn = document.getElementById('zvRvSubmit');
    if (rvSubmitBtn) {
        rvSubmitBtn.addEventListener('click', function () {
            if (!currentItem) { return; }
            var msg = document.getElementById('zvRvMsg');
            msg.innerHTML = '';
            if (!rvSelectedStars) {
                msg.innerHTML = '<div class="zv-msg zv-msg--error">' + escapeHtml(T.pickStars) + '</div>';
                return;
            }
            var menuId = currentItem.id;
            var body = document.getElementById('zvRvBody').value.trim();
            var name = document.getElementById('zvRvName').value.trim();
            rvSubmitBtn.disabled = true;
            // The live MenuReviewController validates rating/text/name (not
            // stars/body/reviewer_name) and answers {ok:true,...} — coded
            // against the actual endpoint, not the shorthand field names.
            fetch(reviewsUrl(menuId), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ rating: rvSelectedStars, text: body || null, name: name || null }),
            })
                .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json().catch(function () { return {}; }); })
                .then(function () {
                    resetReviewForm();
                    msg.innerHTML = '<div class="zv-msg zv-msg--ok">' + escapeHtml(T.reviewThanks) + '</div>';
                    loadReviews(menuId);
                })
                .catch(function () { msg.innerHTML = '<div class="zv-msg zv-msg--error">' + escapeHtml(T.genericError) + '</div>'; })
                .then(function () { rvSubmitBtn.disabled = false; });
        });
    }

    // Swipeable thumbnail strip for the product screen: shows every gallery
    // image and swaps the main image on tap. Hidden unless there are ≥2 images.
    function renderProductThumbs(item) {
        var wrap = document.getElementById('zvPdThumbs');
        if (!wrap) { return; }
        var imgs = (item && item.gallery) ? item.gallery : [];
        wrap.innerHTML = '';
        if (imgs.length < 2) { wrap.hidden = true; return; }
        wrap.hidden = false;
        var mainImg = document.getElementById('zvPdImg');
        var phEl = document.getElementById('zvPdImgPh');
        imgs.forEach(function (src, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'zv-pd-thumb';
            b.setAttribute('aria-current', i === 0 ? 'true' : 'false');
            var im = document.createElement('img');
            im.src = src; im.alt = ''; im.setAttribute('data-csp-error', 'hide');
            b.appendChild(im);
            b.addEventListener('click', function () {
                mainImg.src = src; mainImg.hidden = false;
                if (phEl) { phEl.hidden = true; }
                Array.prototype.forEach.call(wrap.children, function (c) { c.setAttribute('aria-current', c === b ? 'true' : 'false'); });
            });
            wrap.appendChild(b);
        });
    }

    function openProduct(id) {
        var item = ITEMS[id];
        if (!item) { return; }
        currentItem = item;
        currentVariantId = item.variants.length === 1 ? item.variants[0].id : null;
        currentQty = 1;
        document.getElementById('zvQtyVal').textContent = '1';
        document.getElementById('zvPdTitle').textContent = item.name;
        document.getElementById('zvPdDesc').textContent = item.desc;
        var unitEl = document.getElementById('zvPdUnit');
        if (item.unit) { unitEl.textContent = item.unit; unitEl.hidden = false; } else { unitEl.hidden = true; }
        var imgEl = document.getElementById('zvPdImg');
        var phEl = document.getElementById('zvPdImgPh');
        // Clear any inline display:none left by the CSP image-error handler (it
        // fires on the placeholder's empty src="" before the real src is set).
        if (item.img) { imgEl.src = item.img; imgEl.alt = item.name; imgEl.hidden = false; imgEl.style.removeProperty('display'); phEl.hidden = true; }
        else { imgEl.removeAttribute('src'); imgEl.hidden = true; phEl.hidden = false; }
        renderProductThumbs(item);
        var discEl = document.getElementById('zvPdDisc');
        if (item.discountPct > 0) { discEl.textContent = '-' + item.discountPct + '%'; discEl.hidden = false; } else { discEl.hidden = true; }
        var wasEl = document.getElementById('zvPdWas');
        if (item.compareAtFormatted) { wasEl.textContent = item.compareAtFormatted; wasEl.hidden = false; } else { wasEl.hidden = true; }
        var saveEl = document.getElementById('zvPdSave');
        if (saveEl) {
            if (item.discountPct > 0) { saveEl.textContent = @json(___('You save')) + ' ' + item.discountPct + '%'; saveEl.hidden = false; }
            else { saveEl.hidden = true; }
        }
        renderProductVariants();
        renderProductAttrs();
        renderProductStock();
        updateProductPrice();

        var gate = document.getElementById('zvAgeGate');
        var addBtn = document.getElementById('zvAddToCart');
        gate.hidden = !(item.ageRestricted && !ageConfirmed[id]);
        addBtn.disabled = !!item.soldOut;

        var heartBtn = document.getElementById('zvProductHeart');
        if (heartBtn) {
            heartBtn.setAttribute('data-zv-heart', String(id));
            paintHeartBtn(heartBtn, !!favIds[id]);
        }

        var upsellEl = document.getElementById('zvPdUpsell');
        var upsell = Object.keys(ITEMS).map(function (k) { return ITEMS[k]; }).filter(function (it) { return it.id !== id; }).slice(0, 6);
        upsellEl.innerHTML = upsell.map(cardHTML).join('');
        wireCards(upsellEl);

        resetReviewForm();
        loadReviews(id);

        var itemUrl = window.location.pathname + '?item=' + encodeURIComponent(String(id));
        try { history.replaceState(history.state, '', itemUrl); } catch (e) { /* best-effort */ }

        if (currentScreen !== 'product') { stack.push('product'); }
        showScreen('product');
    }

    var productHeartBtn = document.getElementById('zvProductHeart');
    if (productHeartBtn) {
        productHeartBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var id = productHeartBtn.getAttribute('data-zv-heart');
            if (id) { toggleFav(id); }
        });
    }
    document.getElementById('zvQtyMinus').addEventListener('click', function () {
        currentQty = Math.max(1, currentQty - 1);
        document.getElementById('zvQtyVal').textContent = String(currentQty);
        updateProductPrice();
    });
    document.getElementById('zvQtyPlus').addEventListener('click', function () {
        currentQty = currentQty + 1;
        document.getElementById('zvQtyVal').textContent = String(currentQty);
        updateProductPrice();
    });
    document.getElementById('zvAgeConfirm').addEventListener('click', function () {
        if (currentItem) {
            ageConfirmed[currentItem.id] = true;
            try { window.sessionStorage.setItem('zevoa-age-ok-' + storeId, JSON.stringify(ageConfirmed)); } catch (e) { /* best-effort */ }
        }
        document.getElementById('zvAgeGate').hidden = true;
    });
    document.getElementById('zvAgeCancel').addEventListener('click', goBack);

    document.getElementById('zvAddToCart').addEventListener('click', function () {
        if (!currentItem) { return; }
        if (currentItem.ageRestricted && !ageConfirmed[currentItem.id]) {
            document.getElementById('zvAgeGate').hidden = false;
            return;
        }
        if (currentItem.variants.length > 1 && !currentVariantId) {
            toast(T.chooseOption);
            return;
        }
        var addBtn = document.getElementById('zvAddToCart');
        var item = currentItem, variantId = currentVariantId, qty = currentQty;
        addBtn.disabled = true;
        postAddToCart(item.id, variantId, qty)
            .then(function (data) {
                addToCartLocal(item.id, variantId, qty, data.line_id);
                toast(T.added);
                goTo('cart', { replace: true });
            })
            .catch(function () { toast(T.genericError); })
            .then(function () { addBtn.disabled = false; });
    });

    /* ---------------- store screen: search / category / brand filter (mirrors shelf) ---------------- */
    var grid = document.getElementById('zvGrid');
    var storeCards = grid ? Array.prototype.slice.call(grid.querySelectorAll('.zv-pcard')) : [];
    var noResults = document.getElementById('zvNoResults');
    var activeCat = 'all';
    var activeBrands = new Set();
    var activeMaxPrice = Infinity;
    var query = '';
    // Build a catId -> display name map from the department links (real data).
    var catNames = { all: @json(___('All')) };
    document.querySelectorAll('#zvScreenStore [data-cat]').forEach(function (el) {
        var lbl = el.querySelector('span');
        catNames[el.getAttribute('data-cat')] = (lbl ? lbl.textContent : el.textContent).trim();
    });
    function updateCatHead() {
        var name = catNames[activeCat] || catNames.all;
        var t = document.getElementById('zvCatTitle'); if (t) { t.textContent = name; }
        var c = document.getElementById('zvCatCrumb'); if (c) { c.textContent = name; }
    }
    function applyStoreFilter() {
        var visible = 0;
        storeCards.forEach(function (card) {
            var matchesCat = activeCat === 'all' || card.getAttribute('data-root') === activeCat;
            var matchesBrand = activeBrands.size === 0 || activeBrands.has(card.getAttribute('data-brand'));
            var price = parseFloat(card.getAttribute('data-price'));
            var matchesPrice = !isFinite(activeMaxPrice) || !isFinite(price) || price <= activeMaxPrice;
            var matchesQuery = !query
                || card.getAttribute('data-name').indexOf(query) !== -1
                || card.getAttribute('data-desc').indexOf(query) !== -1;
            var show = matchesCat && matchesBrand && matchesPrice && matchesQuery;
            card.hidden = !show;
            if (show) { visible++; }
        });
        if (noResults) { noResults.hidden = visible !== 0; }
        var cnt = document.getElementById('zvCatCount'); if (cnt) { cnt.textContent = visible; }
    }
    // Category select — sidebar department links AND the phone chip row share
    // the same data-cat, so clicking one syncs the active state on both.
    document.querySelectorAll('#zvScreenStore [data-cat]').forEach(function (el) {
        el.addEventListener('click', function () {
            var cat = el.getAttribute('data-cat');
            document.querySelectorAll('#zvScreenStore [data-cat]').forEach(function (c) {
                var on = c.getAttribute('data-cat') === cat;
                c.classList.toggle('is-active', on);
                c.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            activeCat = cat;
            updateCatHead();
            applyStoreFilter();
        });
    });
    // Brand checkboxes (multi-select).
    document.querySelectorAll('#zvScreenStore .zv-brandcheck').forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (cb.checked) { activeBrands.add(cb.value); } else { activeBrands.delete(cb.value); }
            applyStoreFilter();
        });
    });
    // Price range slider.
    var priceRange = document.getElementById('zvPriceRange');
    var priceVal = document.getElementById('zvPriceVal');
    if (priceRange) {
        priceRange.addEventListener('input', function () {
            activeMaxPrice = parseFloat(priceRange.value);
            if (priceVal) { priceVal.textContent = priceRange.value; }
            if (parseFloat(priceRange.value) >= parseFloat(priceRange.max)) { activeMaxPrice = Infinity; }
            applyStoreFilter();
        });
    }
    // Sort (popular / price asc / price desc) — reorders cards in the grid.
    var sortSel = document.getElementById('zvSortSel');
    if (sortSel && grid) {
        sortSel.addEventListener('change', function () {
            var mode = sortSel.value;
            storeCards.slice().sort(function (a, b) {
                var pa = parseFloat(a.getAttribute('data-price')) || 0;
                var pb = parseFloat(b.getAttribute('data-price')) || 0;
                if (mode === 'low') { return pa - pb; }
                if (mode === 'high') { return pb - pa; }
                return 0;
            }).forEach(function (c) { grid.appendChild(c); });
        });
    }
    var searchInput = document.getElementById('zvSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            query = searchInput.value.trim().toLowerCase();
            applyStoreFilter();
        });
    }
    // Desktop top-nav search (see #zvSearchDesktop in the markup, only
    // visible ≥1024px) — jumps to the store screen on focus (mirroring a
    // tap on the mobile search field, which is only ever reachable from
    // there) and forwards its value into the SAME query/applyStoreFilter()
    // the mobile search box already drives, so both stay in sync.
    var searchDesktop = document.getElementById('zvSearchDesktop');
    if (searchDesktop && searchInput) {
        searchDesktop.addEventListener('focus', function () {
            if (currentScreen !== 'store') { goTo('store'); }
        });
        searchDesktop.addEventListener('input', function () {
            searchInput.value = searchDesktop.value;
            query = searchDesktop.value.trim().toLowerCase();
            applyStoreFilter();
        });
    }

    /* ---- Supermarket reframe: home search + shop-by-category tiles ----
       The home search field (search-first home) jumps to the catalog and
       forwards its value into the SAME query/applyStoreFilter() the store
       search drives; the category tiles deep-link into the catalog
       pre-filtered to a category, reusing the store chip state machine. */
    var homeSearch = document.getElementById('zvHomeSearch');
    if (homeSearch && searchInput) {
        homeSearch.addEventListener('focus', function () {
            if (currentScreen !== 'store') { goTo('store'); try { searchInput.focus({ preventScroll: true }); } catch (e) { /* best-effort */ } }
        });
        homeSearch.addEventListener('input', function () {
            searchInput.value = homeSearch.value;
            query = homeSearch.value.trim().toLowerCase();
            applyStoreFilter();
        });
    }
    function selectStoreCategory(catId) {
        var target = String(catId);
        if (currentScreen !== 'store') { goTo('store'); }
        document.querySelectorAll('#zvScreenStore [data-cat]').forEach(function (c) {
            var on = c.getAttribute('data-cat') === target;
            c.classList.toggle('is-active', on);
            c.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        activeCat = target;
        updateCatHead();
        applyStoreFilter();
    }
    document.querySelectorAll('[data-zv-cat]').forEach(function (tile) {
        tile.addEventListener('click', function () { selectStoreCategory(tile.getAttribute('data-zv-cat')); });
    });

    /* Flash-deal countdown — a presentational rolling 3-hour window (urgency
       cue, like Carrefour's flash strip). Anchored to wall-clock so it counts
       down smoothly and loops; never claims a real per-item expiry. */
    var flashTimerEl = document.getElementById('zvFlashTimer');
    if (flashTimerEl) {
        var FLASH_WINDOW = 3 * 3600;
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var tickFlash = function () {
            var rem = FLASH_WINDOW - (Math.floor(Date.now() / 1000) % FLASH_WINDOW);
            flashTimerEl.textContent = pad(Math.floor(rem / 3600)) + ':' + pad(Math.floor((rem % 3600) / 60)) + ':' + pad(rem % 60);
        };
        tickFlash();
        setInterval(tickFlash, 1000);
    }

    /* ---------------- carousel arrows on product rails ---------------- */
    document.querySelectorAll('#zv-main .zv-hrow, #zvScreenProduct .zv-hrow').forEach(function (rail) {
        var wrap = document.createElement('div');
        wrap.className = 'zv-railwrap';
        rail.parentNode.insertBefore(wrap, rail);
        wrap.appendChild(rail);
        function mkNav(cls, d, lbl) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'zv-railnav ' + cls; b.setAttribute('aria-label', lbl);
            b.innerHTML = '<svg viewBox="0 0 24 24"><path d="' + d + '"/></svg>';
            wrap.appendChild(b); return b;
        }
        var prev = mkNav('prev', 'M9 5l7 7-7 7', @json(___('Previous')));
        var next = mkNav('next', 'M15 5l-7 7 7 7', @json(___('Next')));
        var amt = function () { return Math.max(rail.clientWidth * 0.85, 200); };
        function upd() {
            var max = rail.scrollWidth - rail.clientWidth - 2, x = Math.abs(rail.scrollLeft);
            prev.disabled = x <= 2; next.disabled = x >= max;
        }
        prev.addEventListener('click', function () { rail.scrollBy({ left: amt(), behavior: 'smooth' }); });
        next.addEventListener('click', function () { rail.scrollBy({ left: -amt(), behavior: 'smooth' }); });
        rail.addEventListener('scroll', upd, { passive: true });
        setTimeout(upd, 60);
    });

    /* ---------------- checkout ---------------- */
    function selectedMode() {
        var active = document.querySelector('#zvModeOpts [data-zv-mode][aria-pressed="true"]');
        if (active) {
            return {
                value: active.getAttribute('data-zv-mode'),
                needsAddress: active.getAttribute('data-needs-address') === '1',
                needsSlot: active.getAttribute('data-needs-slot') === '1',
            };
        }
        var only = MODES[0] || { value: 'in_store', needsAddress: false, needsSlot: false };
        return { value: only.value, needsAddress: !!only.needsAddress, needsSlot: !!only.needsSlot };
    }
    document.querySelectorAll('#zvModeOpts [data-zv-mode]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#zvModeOpts [data-zv-mode]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
            btn.setAttribute('aria-pressed', 'true');
            updateModeFields();
        });
    });
    // PRESENTATIONAL — see the Blade comment above the transfer-method block.
    document.querySelectorAll('[data-zv-pay]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-zv-pay]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
            btn.setAttribute('aria-pressed', 'true');
        });
    });

    var chosenSlot = null;
    function loadSlots() {
        var wrap = document.getElementById('zvSlots');
        wrap.innerHTML = '';
        chosenSlot = null;
        fetch(SLOT_ROUTE + '?type=' + encodeURIComponent(selectedMode().value), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.enabled || !data.slots || !data.slots.length) { return; }
                data.slots.slice(0, 8).forEach(function (slot) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'zv-opt';
                    btn.textContent = slot.label;
                    btn.disabled = !!slot.full;
                    btn.addEventListener('click', function () {
                        chosenSlot = slot.value;
                        wrap.querySelectorAll('.zv-opt').forEach(function (b) { b.classList.remove('is-active'); });
                        btn.classList.add('is-active');
                    });
                    wrap.appendChild(btn);
                });
            })
            .catch(function () { /* slot picker is best-effort display; checkout still works without it */ });
    }
    function updateModeFields() {
        var mode = selectedMode();
        document.getElementById('zvAddressField').hidden = !mode.needsAddress;
        document.getElementById('zvSlotField').hidden = !mode.needsSlot;
        if (mode.needsSlot) { loadSlots(); }
    }

    // Wire the delivery-address autocomplete once (crave-style geocode suggest
    // + use-my-location). Safe to call before the field is shown — the input
    // lives in the DOM the whole time.
    if (window.mbAddrAutocomplete) {
        window.mbAddrAutocomplete(document.getElementById('zvAddress'), {
            suggest: document.getElementById('zvAddrSuggest'),
            locBtn: document.getElementById('zvAddrLoc')
        });
    }

    /* ---------------- loyalty (curated core feature) — teaser is public/no
       auth (server-rendered on the account card); the balance endpoint is
       auth:customer and 401s for a guest, in which case both surfaces fall
       back to the teaser (+ a sign-in hint) instead of a balance. ---------------- */
    function fetchLoyaltyBalance() {
        if (!LOYALTY_BALANCE_ROUTE) { return Promise.resolve({ active: false }); }
        return fetch(LOYALTY_BALANCE_ROUTE, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.status === 401 ? { active: false } : r.json(); })
            .catch(function () { return { active: false }; });
    }
    function renderRewardsCard() {
        var card = document.getElementById('zvRewardsCard');
        if (!card) { return; }
        fetchLoyaltyBalance().then(function (data) {
            var hint = document.getElementById('zvRewardsSigninHint');
            var sub = document.getElementById('zvRewardsBalanceLine');
            if (data && data.active) {
                if (hint) { hint.hidden = true; }
                if (sub) {
                    var tierPart = data.tier ? (' · ' + data.tier) : '';
                    sub.textContent = String(data.balance) + ' ' + T.points + tierPart;
                }
                var tier = String(data.tier || '').toLowerCase();
                document.querySelectorAll('#zvTierRow .zv-tierchip').forEach(function (chip) {
                    chip.classList.toggle('is-current', !!tier && chip.getAttribute('data-tier') === tier);
                });
            } else if (hint) {
                hint.hidden = false;
            }
        });
    }
    function renderLoyaltyCheckout() {
        var sect = document.getElementById('zvLoyaltySect');
        if (!sect) { return; }
        var row = document.getElementById('zvLoyaltyRedeemRow');
        var teaserMsg = document.getElementById('zvLoyaltyTeaserMsg');
        var toggle = document.getElementById('zvLoyaltyToggle');
        var hiddenInput = document.getElementById('zvLoyaltyRedeemInput');
        sect.hidden = true;
        row.hidden = true;
        teaserMsg.hidden = true;
        toggle.setAttribute('aria-checked', 'false');
        hiddenInput.value = '0';
        fetchLoyaltyBalance().then(function (data) {
            if (data && data.active && data.ready_to_redeem) {
                sect.hidden = false;
                row.hidden = false;
                document.getElementById('zvLoyaltyRedeemTitle').textContent = T.applyReward;
                document.getElementById('zvLoyaltyRedeemSub').textContent = T.applyDiscount.replace(':discount', data.reward_discount_display || '');
            } else if (LOY) {
                sect.hidden = false;
                teaserMsg.hidden = false;
                var parts = [];
                if (LOY.program) { parts.push(LOY.program); }
                if (LOY.earn) { parts.push(LOY.earn); }
                if (LOY.reward) { parts.push(LOY.reward); }
                parts.push(T.signInEarn);
                teaserMsg.textContent = parts.join(' · ');
            }
        });
    }
    var loyaltyToggleBtn = document.getElementById('zvLoyaltyToggle');
    if (loyaltyToggleBtn) {
        loyaltyToggleBtn.addEventListener('click', function () {
            var next = loyaltyToggleBtn.getAttribute('aria-checked') !== 'true';
            loyaltyToggleBtn.setAttribute('aria-checked', next ? 'true' : 'false');
            document.getElementById('zvLoyaltyRedeemInput').value = next ? '1' : '0';
        });
    }

    function openCheckout() {
        document.getElementById('zvCheckoutMsg').innerHTML = '';
        var t = totals();
        document.getElementById('zvCoSumPrice').textContent = formatMoney(t.sub);
        document.getElementById('zvCoSumHandling').textContent = formatMoney(t.handling);
        document.getElementById('zvCoSumPackaging').textContent = formatMoney(t.packaging);
        var vr = document.getElementById('zvCoSumVoucherRow');
        if (voucherApplied) {
            vr.hidden = false;
            document.getElementById('zvCoSumVoucher').textContent = '−' + formatMoney(t.voucherDiscount);
        } else {
            vr.hidden = true;
        }
        var coTaxRow = document.getElementById('zvCoTaxRow');
        coTaxRow.hidden = t.tax <= 0;
        document.getElementById('zvCoTax').textContent = formatMoney(t.tax);
        document.getElementById('zvCoSumTotal').textContent = formatMoney(t.total);
        document.getElementById('zvPlaceOrderTotal').textContent = formatMoney(t.total);
        updateModeFields();
        renderLoyaltyCheckout();
    }

    document.getElementById('zvGoCheckout').addEventListener('click', function () { goTo('checkout'); });
    document.getElementById('zvVoucherApply').addEventListener('click', function () {
        var input = document.getElementById('zvVoucherInput');
        var code = input.value.trim();
        if (!code) { return; }
        var applyBtn = document.getElementById('zvVoucherApply');
        applyBtn.disabled = true;
        var fd = voucherForm(code, subtotal());
        var sentSig = voucherSigFor(subtotal());
        fetch(COUPON_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                applyBtn.disabled = false;
                if (d && d.success) {
                    voucherApplied = true;
                    voucherCode = code;
                    voucherServerDiscount = parseFloat(d.discount) || 0;
                    voucherSig = sentSig;
                    renderCart();
                    toast(d.message || @json(___('Coupon applied')));
                } else {
                    voucherApplied = false; voucherCode = ''; voucherServerDiscount = 0;
                    renderCart();
                    toast((d && d.message) || @json(___('Coupon code is invalid.')));
                }
            })
            .catch(function () { applyBtn.disabled = false; });
    });

    document.getElementById('zvPlaceOrder').addEventListener('click', function () {
        var msg = document.getElementById('zvCheckoutMsg');
        msg.innerHTML = '';
        if (!cart.length) {
            msg.innerHTML = '<div class="zv-msg zv-msg--error">' + escapeHtml(T.cartEmpty) + '</div>';
            return;
        }
        var name = document.getElementById('zvName').value.trim();
        var phone = document.getElementById('zvPhone').value.trim();
        if (!name) {
            msg.innerHTML = '<div class="zv-msg zv-msg--error">' + escapeHtml(T.nameRequired) + '</div>';
            return;
        }
        var mode = selectedMode();
        if (mode.needsAddress && !document.getElementById('zvAddress').value.trim()) {
            msg.innerHTML = '<div class="zv-msg zv-msg--error">' + escapeHtml(T.addressRequired) + '</div>';
            return;
        }

        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('customer_name', name);
        fd.append('phone_number', phone);
        fd.append('type', mode.value);
        if (mode.needsAddress) { fd.append('address', document.getElementById('zvAddress').value.trim()); }
        if (mode.needsSlot && chosenSlot) { fd.append('scheduled_at', chosenSlot); }
        var loyaltyRedeemInput = document.getElementById('zvLoyaltyRedeemInput');
        if (loyaltyRedeemInput) { fd.append('loyalty_redeem', loyaltyRedeemInput.value); }
        if (voucherApplied && voucherCode) { fd.append('coupon_code', voucherCode); }
        var giftField = document.getElementById('zvGiftCardInput');
        if (giftField && giftField.value.trim()) { fd.append('gift_card_code', giftField.value.trim()); }

        var placeBtn = document.getElementById('zvPlaceOrder');
        placeBtn.disabled = true;
        msg.innerHTML = '<div class="zv-msg zv-msg--info">' + escapeHtml(T.placing) + '</div>';
        fetch(CHECKOUT_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    completeOrder(data);
                } else {
                    msg.innerHTML = '<div class="zv-msg zv-msg--error">' + escapeHtml((data && data.message) || T.genericError) + '</div>';
                }
            })
            .catch(function () {
                msg.innerHTML = '<div class="zv-msg zv-msg--error">' + escapeHtml(T.genericError) + '</div>';
            })
            .then(function () { placeBtn.disabled = false; });
    });

    /* ---------------- confirm (demo timeline — see docblock in the markup) ---------------- */
    var demoTimers = [];
    function clearDemoTimers() {
        demoTimers.forEach(function (id) { clearTimeout(id); });
        demoTimers = [];
    }
    function orderCode(trackToken) {
        return trackToken ? ('#ZV-' + String(trackToken).slice(-6).toUpperCase()) : '';
    }
    function setDemoStep(step) {
        var bar = document.getElementById('zvTrackBar');
        if (bar) { bar.style.width = ((step + 1) / 4 * 100) + '%'; }
        for (var i = 0; i < 4; i++) {
            var stepEl = document.getElementById('zvTStep' + i);
            var subEl = document.getElementById('zvTStep' + i + 'Sub');
            if (!stepEl) { continue; }
            stepEl.classList.remove('is-active', 'is-done');
            if (i < step) { stepEl.classList.add('is-done'); if (subEl) { subEl.textContent = ''; } }
            else if (i === step) { stepEl.classList.add('is-active'); if (subEl) { subEl.textContent = (i === 0 ? T.justNow : T.inProgress); } }
            else if (subEl) { subEl.textContent = ''; }
        }
    }
    function completeOrder(data) {
        // Device-local guest history: snapshot this order into the shared
        // mbGuestOrders store BEFORE the cart is cleared, so a guest (no
        // account) still sees it on the Activity screen with a Track link and
        // a Reorder button. totals()/cart are still populated at this point.
        try {
            if (data && data.track_token && window.mbGuestOrders) {
                window.mbGuestOrders.save(storeId, {
                    token: data.track_token,
                    trackUrl: data.redirect || null,
                    total: formatMoney(totals().total),
                    itemCount: cart.reduce(function (n, l) { return n + l.qty; }, 0),
                    itemIds: cart.map(function (l) { return l.id; })
                });
            }
        } catch (e) { /* best-effort; localStorage may be unavailable */ }
        cart = [];
        voucherApplied = false; voucherCode = ''; voucherServerDiscount = 0;
        saveCart();
        renderCartBadge();
        var loyaltyToggleEl = document.getElementById('zvLoyaltyToggle');
        var loyaltyInputEl = document.getElementById('zvLoyaltyRedeemInput');
        if (loyaltyToggleEl) { loyaltyToggleEl.setAttribute('aria-checked', 'false'); }
        if (loyaltyInputEl) { loyaltyInputEl.value = '0'; }

        var orders = [];
        try { orders = JSON.parse(window.localStorage.getItem('zevoa-orders-' + storeId) || '[]') || []; } catch (e) { orders = []; }
        orders.unshift({ trackToken: data.track_token || null, redirect: data.redirect || null, placedAt: Date.now() });
        try { window.localStorage.setItem('zevoa-orders-' + storeId, JSON.stringify(orders.slice(0, 20))); } catch (e) { /* best-effort */ }

        document.getElementById('zvOrderNo').textContent = orderCode(data.track_token);
        var trackLink = document.getElementById('zvTrackLink');
        if (data.redirect) { trackLink.href = data.redirect; trackLink.hidden = false; } else { trackLink.hidden = true; }

        clearDemoTimers();
        setDemoStep(0);
        demoTimers.push(setTimeout(function () { setDemoStep(1); }, 1600));
        demoTimers.push(setTimeout(function () { setDemoStep(2); }, 4200));

        stack = ['home', 'confirm'];
        showScreen('confirm');
    }

    document.querySelectorAll('#zvStars [data-star]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var n = parseInt(btn.getAttribute('data-star'), 10);
            document.querySelectorAll('#zvStars button').forEach(function (b, i) { b.classList.toggle('is-on', i < n); });
            toast(T.thanksRating);
        });
    });
    var billBtn = document.getElementById('zvDownloadBill');
    if (billBtn) { billBtn.addEventListener('click', function () { toast(T.billReady); }); }

    /* ---------------- activity (session-scoped list of REAL orders this
       browser placed — see docblock in the markup) ---------------- */
    // Signed-in customers see their real server order history with refund
    // controls; guests keep the session-local list (guests cannot refund).
    function renderServerActivity(list, empty) {
        var lblMap = { unpaid: @json(___('Awaiting payment')), pending: @json(___('Received')), new: @json(___('Received')), preparing: @json(___('Preparing')), ready: @json(___('Ready')), out_for_delivery: @json(___('On the way')), delivered: @json(___('Delivered')), completed: @json(___('Completed')), cancelled: @json(___('Cancelled')) };
        function lbl(s) { return lblMap[s] || (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g, ' ') : ''); }
        fetch(MY_ORDERS_ROUTE, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (r.status === 401) { throw 'guest'; } return r.json(); })
            .then(function (res) {
                var orders = (res && res.orders) || [];
                if (!orders.length) { list.innerHTML = ''; empty.hidden = false; return; }
                empty.hidden = true;
                list.innerHTML = orders.map(function (o) {
                    var when = '';
                    try { when = o.at ? new Date(o.at).toLocaleDateString(document.documentElement.lang || undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''; } catch (e) { when = ''; }
                    return '<div class="zv-actcard">' +
                        '<div class="zv-actwhen">' + escapeHtml(when) + ' · ' + escapeHtml(lbl(o.status)) + '</div>' +
                        '<div class="zv-acttitle">' + escapeHtml(o.number) + ' · ' + escapeHtml(o.total) + '</div>' +
                        '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-top:6px">' +
                        (o.trackUrl ? '<a class="zv-actlink" href="' + escapeAttr(o.trackUrl) + '" target="_blank" rel="noopener">' + escapeHtml(@json(___('Track'))) + '</a>' : '') +
                        (o.canRequestRefund ? '<button type="button" class="zv-actlink" style="background:none;border:0;cursor:pointer;padding:0;font:inherit" data-refund-request="' + escapeAttr(o.id) + '">' + escapeHtml(@json(___('Request refund'))) + '</button>' : '') +
                        (o.refundStatus ? '<span class="mb-refund mb-refund--' + escapeAttr(o.refundStatus) + '">' + escapeHtml(mbRefundLabel(o.refundStatus, o.refundMethod)) + '</span>' : '') +
                        '</div></div>';
                }).join('');
            })
            .catch(function (e) { if (e === 'guest') { if (!renderGuestLocalOrders(list, empty)) { renderLocalActivity(list, empty); } } });
    }

    // Device-local guest history: render THIS device's recent orders for a
    // guest (no account) from the shared mbGuestOrders store — each with a
    // Track link and a Reorder button when its items still exist — followed by
    // a sign-in note + button (auto-wired via data-mb-auth-open on the shared
    // auth sheet) to sync across devices. Returns the number of rows rendered
    // so the caller can fall back to the plain local/empty state when the
    // device list is empty.
    function renderGuestLocalOrders(list, empty) {
        var local = (window.mbGuestOrders ? window.mbGuestOrders.list(storeId) : []);
        if (!local.length) { return 0; }
        empty.hidden = true;
        list.innerHTML = local.map(function (o) {
            var when = '';
            try { when = o.at ? new Date(o.at).toLocaleDateString(document.documentElement.lang || undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''; } catch (e) { when = ''; }
            var meta = escapeHtml(when) + (o.itemCount ? ' · ' + o.itemCount + ' ' + escapeHtml(@json(___('items'))) : '') + (o.total ? ' · ' + escapeHtml(o.total) : '');
            var canReorder = (o.itemIds || []).some(function (id) { return ITEMS[String(id)]; });
            return '<div class="zv-actcard">' +
                '<div class="zv-actwhen">' + meta + '</div>' +
                '<div class="zv-acttitle">' + escapeHtml(@json(___('Recent order'))) + '</div>' +
                '<div class="zv-order-actions">' +
                (o.trackUrl ? '<a class="zv-order-btn" href="' + escapeAttr(o.trackUrl) + '">' + escapeHtml(@json(___('Track'))) + '</a>' : '') +
                (canReorder ? '<button type="button" class="zv-order-btn zv-order-btn--p" data-zv-reorder="' + escapeAttr((o.itemIds || []).join(',')) + '">' + escapeHtml(@json(___('Reorder'))) + '</button>' : '') +
                '</div></div>';
        }).join('') +
            '<div class="zv-actcard"><div class="zv-actwhen">' + escapeHtml(@json(___('Sign in to sync your orders across devices.'))) + '</div>' +
            '<div style="margin-top:10px"><button type="button" class="zv-order-btn zv-order-btn--p zv-order-btn--full" data-mb-auth-open>' + escapeHtml(@json(___('Sign in'))) + '</button></div></div>';
        return local.length;
    }

    // Reorder from device-local guest history: re-add each still-available item
    // to the server cart (the same path a normal add uses), then open the cart.
    function zvReorder(ids) {
        var toAdd = ids.filter(function (id) { return ITEMS[id] && !ITEMS[id].soldOut; });
        if (!toAdd.length) { toast(T.genericError); return; }
        Promise.all(toAdd.map(function (id) {
            return postAddToCart(parseInt(id, 10), null, 1)
                .then(function (data) { addToCartLocal(parseInt(id, 10), null, 1, data.line_id); })
                .catch(function () { /* skip an item that can no longer be added */ });
        })).then(function () { if (currentScreen === 'cart') { renderCart(); } goTo('cart'); });
    }
    document.addEventListener('click', function (e) {
        var rb = (e.target && e.target.closest) ? e.target.closest('[data-zv-reorder]') : null;
        if (!rb) { return; }
        e.preventDefault();
        var ids = (rb.getAttribute('data-zv-reorder') || '').split(',').filter(Boolean);
        if (ids.length) { zvReorder(ids); }
    });

    function renderActivity() {
        var list = document.getElementById('zvActivityList');
        var empty = document.getElementById('zvActivityEmpty');
        if (IS_CUSTOMER && MY_ORDERS_ROUTE) { renderServerActivity(list, empty); return; }
        if (renderGuestLocalOrders(list, empty)) { return; }
        renderLocalActivity(list, empty);
    }

    function renderLocalActivity(list, empty) {
        var orders = [];
        try { orders = JSON.parse(window.localStorage.getItem('zevoa-orders-' + storeId) || '[]') || []; } catch (e) { orders = []; }
        if (!orders.length) { list.innerHTML = ''; empty.hidden = false; return; }
        empty.hidden = true;
        list.innerHTML = orders.map(function (o) {
            var when = '';
            try { when = new Date(o.placedAt).toLocaleString(document.documentElement.lang || undefined); } catch (e) { when = ''; }
            var href = o.redirect || '#';
            return '<a class="zv-actcard" href="' + escapeAttr(href) + '">' +
                '<div class="zv-actwhen">' + escapeHtml(when) + '</div>' +
                '<div class="zv-acttitle">' + escapeHtml(orderCode(o.trackToken) || T.pending) + '</div>' +
                '<span class="zv-actlink">' + escapeHtml(@json(___('View order'))) + '</span>' +
                '</a>';
        }).join('');
    }

    /* ---------------- account ---------------- */
    var accFavBtn = document.getElementById('zvAccFavorites');
    if (accFavBtn) { accFavBtn.addEventListener('click', function () { goTo('favorites'); }); }
    var accOrdersBtn = document.getElementById('zvAccOrders');
    if (accOrdersBtn) { accOrdersBtn.addEventListener('click', function () { goTo('activity'); }); }

    /* ---------------- boot ---------------- */
    wireCards(document);
    applyStoreFilter();
    renderCartBadge();
    loadStories();
    try {
        var params = new URLSearchParams(window.location.search);
        var deepId = parseInt(params.get('item'), 10);
        if (deepId && ITEMS[deepId]) {
            stack = ['home', 'store'];
            currentScreen = 'store';
            openProduct(deepId);
        }
    } catch (e) { /* no-op on an unsupported/old browser */ }

    /* Restore the active screen across a same-tab reload (a language switch is a
       full page navigation, which otherwise dumps the shopper back on home and
       makes their order history/activity look "gone"). sessionStorage is per-tab,
       so a fresh visit still opens on home. Skipped when a ?item deep-link is
       driving the screen (handled above). Restores via the SAME goTo() nav call,
       so the target screen's renderer (e.g. renderActivity for order history) runs. */
    (function restoreScreen() {
        try {
            if (new URLSearchParams(window.location.search).get('item')) { return; }
            var saved = window.sessionStorage.getItem('mb-screen-' + storeId);
            /* Never restore a context-dependent screen: product needs a selected
               item, checkout a live cart, confirm a just-placed order. Restoring
               one after a plain reload renders an EMPTY, broken screen (a stray
               "add to cart" bar over blank space). Those fall back to home. */
            var transient = { product: 1, checkout: 1, confirm: 1 };
            if (saved && saved !== 'home' && screens[saved] && !transient[saved]) { goTo(saved); }
        } catch (e) { /* best-effort */ }
    })();

    /* crave-parity: branch switch (reload with ?branch_id) */
    (function () {
        var bs = document.getElementById('zvBranchSel');
        if (bs) { bs.addEventListener('change', function (e) { if (e.target.value) { window.location.assign(e.target.value); } }); }
    })();

    /* crave-parity: promo banner slider (dots + gentle auto-advance) */
    (function () {
        var track = document.getElementById('zvBannerTrack');
        var dotsWrap = document.getElementById('zvBannerDots');
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

    /* ══════════ account sub-screens: addresses (localStorage) + settings ══════════ */
    var ADDR_KEY = 'zevoa-addr-' + storeId;
    function loadAddresses() { try { return JSON.parse(window.localStorage.getItem(ADDR_KEY) || '[]') || []; } catch (e) { return []; } }
    function saveAddresses(list) { try { window.localStorage.setItem(ADDR_KEY, JSON.stringify(list)); } catch (e) { /* best-effort */ } }
    function renderAddresses() {
        var list = document.getElementById('zvAddrList');
        var empty = document.getElementById('zvAddrEmpty');
        if (!list) { return; }
        var items = loadAddresses();
        if (empty) { empty.hidden = items.length > 0; }
        list.textContent = '';
        items.forEach(function (a, i) {
            var card = document.createElement('div'); card.className = 'zv-addrcard';
            var top = document.createElement('div'); top.className = 'zv-addrcard-top';
            var lab = document.createElement('span'); lab.className = 'zv-addrcard-label'; lab.textContent = a.label || T.addressWord; top.appendChild(lab);
            if (a.def) { var badge = document.createElement('span'); badge.className = 'zv-addrcard-def'; badge.textContent = T.defaultLabel; top.appendChild(badge); }
            card.appendChild(top);
            var txt = document.createElement('div'); txt.className = 'zv-addrcard-text'; txt.textContent = a.text || ''; card.appendChild(txt);
            if (a.phone) { var ph = document.createElement('div'); ph.className = 'zv-addrcard-phone'; ph.textContent = a.phone; card.appendChild(ph); }
            var acts = document.createElement('div'); acts.className = 'zv-addrcard-acts';
            if (!a.def) { var setb = document.createElement('button'); setb.type = 'button'; setb.textContent = T.setDefault; setb.setAttribute('data-addr-default', String(i)); acts.appendChild(setb); }
            var delb = document.createElement('button'); delb.type = 'button'; delb.className = 'zv-del'; delb.textContent = T.deleteLabel; delb.setAttribute('data-addr-del', String(i)); acts.appendChild(delb);
            card.appendChild(acts);
            list.appendChild(card);
        });
    }
    (function () {
        var list = document.getElementById('zvAddrList');
        var form = document.getElementById('zvAddrForm');
        var addBtn = document.getElementById('zvAddrAddBtn');
        var cancel = document.getElementById('zvAddrCancel');
        var fLabel = document.getElementById('zvAddrLabel');
        var fText = document.getElementById('zvAddrText');
        var fPhone = document.getElementById('zvAddrPhone');
        // Reuse the shared geocode engine: address typeahead + "use my location"
        // (reverse-geocode the device position into the field), same as checkout.
        if (window.mbAddrAutocomplete && fText) {
            window.mbAddrAutocomplete(fText, { suggest: document.getElementById('zvAddrSuggestNew'), locBtn: document.getElementById('zvAddrLocNew') });
        }
        function showForm(on) { if (!form) { return; } form.hidden = !on; if (addBtn) { addBtn.hidden = on; } if (on && fLabel) { fLabel.focus(); } }
        if (addBtn) { addBtn.addEventListener('click', function () { showForm(true); }); }
        if (cancel) { cancel.addEventListener('click', function () { form.reset(); showForm(false); }); }
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var text = (fText.value || '').trim();
                if (!text) { toast(T.addressNeeded); fText.focus(); return; }
                var items = loadAddresses();
                items.push({ label: (fLabel.value || '').trim(), text: text, phone: (fPhone.value || '').trim(), def: items.length === 0 });
                saveAddresses(items); form.reset(); showForm(false); renderAddresses(); toast(T.addressSaved);
            });
        }
        if (list) {
            list.addEventListener('click', function (e) {
                var del = e.target.closest('[data-addr-del]');
                var def = e.target.closest('[data-addr-default]');
                if (del) {
                    var items = loadAddresses(); var idx = parseInt(del.getAttribute('data-addr-del'), 10);
                    var wasDef = items[idx] && items[idx].def; items.splice(idx, 1);
                    if (wasDef && items.length) { items[0].def = true; }
                    saveAddresses(items); renderAddresses();
                } else if (def) {
                    var it = loadAddresses(); var di = parseInt(def.getAttribute('data-addr-default'), 10);
                    it.forEach(function (a, j) { a.def = j === di; }); saveAddresses(it); renderAddresses();
                }
            });
        }
    })();
    (function () {
        var seg = document.getElementById('zvThemeSeg');
        function pref() { try { return window.localStorage.getItem(darkKey) || 'system'; } catch (e) { return 'system'; } }
        function paintSeg() { if (seg) { seg.querySelectorAll('[data-zv-theme]').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-zv-theme') === pref()); }); } }
        if (seg) {
            seg.querySelectorAll('[data-zv-theme]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var v = b.getAttribute('data-zv-theme');
                    try {
                        if (v === 'system') { window.localStorage.removeItem(darkKey); document.documentElement.removeAttribute('data-theme'); }
                        else { window.localStorage.setItem(darkKey, v); document.documentElement.setAttribute('data-theme', v); }
                    } catch (e) { /* best-effort */ }
                    paintSeg();
                });
            });
            paintSeg();
        }
        var notif = document.getElementById('zvNotifToggle');
        var NKEY = 'zevoa-notif-' + storeId;
        function nOn() { try { return window.localStorage.getItem(NKEY) === '1'; } catch (e) { return false; } }
        function paintN() { if (notif) { notif.setAttribute('aria-checked', nOn() ? 'true' : 'false'); } }
        if (notif) {
            paintN();
            notif.addEventListener('click', function () {
                var turnOn = !nOn();
                if (turnOn && 'Notification' in window && Notification.permission === 'default') { try { Notification.requestPermission(); } catch (e) { /* ignore */ } }
                try { window.localStorage.setItem(NKEY, turnOn ? '1' : '0'); } catch (e) { /* best-effort */ }
                paintN(); toast(turnOn ? T.notifOn : T.notifOff);
            });
        }
        var clr = document.getElementById('zvClearData');
        if (clr) {
            clr.addEventListener('click', function () {
                if (!window.confirm(T.clearConfirm)) { return; }
                try { [CART_KEY, FAV_KEY, ADDR_KEY].forEach(function (k) { window.localStorage.removeItem(k); }); } catch (e) { /* best-effort */ }
                toast(T.cleared); setTimeout(function () { window.location.reload(); }, 700);
            });
        }
    })();
    (function () {
        var mapBtn = document.getElementById('zvAddrMapBtn');
        var wrap = document.getElementById('zvMapWrap');
        if (!mapBtn || !wrap || typeof L === 'undefined') { if (mapBtn) { mapBtn.style.display = 'none'; } return; }
        var closeBtn = document.getElementById('zvMapClose');
        var confirmBtn = document.getElementById('zvMapConfirm');
        var addrEl = document.getElementById('zvMapAddr');
        var input = document.getElementById('zvAddrText');
        var REVERSE = @json(route('geo.reverse'));
        var map = null, revTimer = null, lastAddr = '';
        function reverse(lat, lng) {
            addrEl.textContent = T.mapLocating;
            fetch(REVERSE + '?lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { var lbl = (d && d.result && d.result.label) || ''; lastAddr = lbl; addrEl.textContent = lbl || T.mapHint; })
                .catch(function () { lastAddr = ''; addrEl.textContent = T.mapHint; });
        }
        function initMap(lat, lng) {
            map = L.map('zvMap', { attributionControl: false, zoomControl: true }).setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            map.on('moveend', function () { var c = map.getCenter(); clearTimeout(revTimer); revTimer = setTimeout(function () { reverse(c.lat, c.lng); }, 500); });
            reverse(lat, lng);
        }
        function openMap() {
            wrap.hidden = false;
            if (map) { setTimeout(function () { map.invalidateSize(); }, 60); return; }
            var def = [24.7136, 46.6753];
            var start = function (la, ln) { initMap(la, ln); setTimeout(function () { map.invalidateSize(); }, 60); };
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function (p) { start(p.coords.latitude, p.coords.longitude); }, function () { start(def[0], def[1]); }, { timeout: 6000, maximumAge: 60000 });
            } else { start(def[0], def[1]); }
        }
        function closeMap() { wrap.hidden = true; }
        mapBtn.addEventListener('click', openMap);
        closeBtn.addEventListener('click', closeMap);
        confirmBtn.addEventListener('click', function () { if (lastAddr) { input.value = lastAddr; } closeMap(); });
    })();
    (function () {
        var btn = document.getElementById('zvNewsBtn');
        var inp = document.getElementById('zvNewsInput');
        if (!btn || !inp) { return; }
        var NEWS_URL = @json(route('newsletter'));
        function submitNews() {
            var email = (inp.value || '').trim();
            if (!email || email.indexOf('@') < 1) { toast(T.newsInvalid); inp.focus(); return; }
            btn.disabled = true;
            var fd = new FormData(); fd.append('email', email); fd.append('_token', CSRF);
            fetch(NEWS_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                .then(function () { inp.value = ''; toast(T.newsThanks); })
                .catch(function () { toast(T.newsError); })
                .then(function () { btn.disabled = false; }, function () { btn.disabled = false; });
        }
        btn.addEventListener('click', submitNews);
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submitNews(); } });
    })();
})();
</script>
@endpush
