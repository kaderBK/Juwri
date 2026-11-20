{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.shelf.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalRegistry;
    use App\Services\Verticals\VerticalVocab;
    use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyComplianceService;
    use Illuminate\Support\Str;

    /* ---------------------------------------------------------------------
       shelf — grid retail storefront · data preparation (rev/multi-vertical C1/C2)

       Renders $total_menus / $category->menus through VerticalVocab, never
       hardcoding "Menu"/"Dish"/"Order food" (C5 grep-guard). Reads Lane A's
       (A1/A2) brand/weight/dimensions/attributes columns and per-variant
       sku/stock_qty/barcode DEFENSIVELY — those columns do not exist yet, so
       every read below is `?? null`/`is_array(...) ?...:null` and simply
       lights up once Lane A merges, with no further changes needed here.
       --------------------------------------------------------------------- */
    $vocabSvc = app(VerticalVocab::class);
    $vocab    = $vocabSvc->map($store->store_type ?? null);

    /* rev/multi-vertical Lane F3 — OTC-only (D3) compliance disclaimer. Gated on
       the SAME `age_gate` capability F1's interstitial uses (today: pharmacy
       only), so every other shelf-using vertical (retail/cosmetic/clothing/
       carparts) renders byte-identical to before this existed. Configurable
       per store via PharmacyComplianceController (Lane F), never a fork of
       this theme's own markup/behaviour. NOT a prescription/Rx surface — see
       PharmacyOtcGuardrailTest. */
    $showOtcDisclaimer = app(VerticalRegistry::class)->has($store->store_type ?? null, 'age_gate');
    $otcDisclaimerText = $showOtcDisclaimer ? app(PharmacyComplianceService::class)->disclaimerText($store) : null;

    $items = collect($total_menus)->values();

    // Two-tier-free category nav: top-level categories that own at least one
    // descendant item (own item OR a subcategory's item), same "root" mapping
    // crave/aurora use, so nested categories still group correctly under one chip.
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

    /* rev/multi-vertical Lane E (E2) — car-parts YMM storefront filter +
       OEM/cross-ref search (E5). Reads the frozen VerticalRegistry seam
       (consume, never fork) so this is a no-op, zero-diff render for every
       vertical except carparts. The filter bar markup + its script/style are
       added further below, each wrapped in `@if($fitment)` / degrading to a
       silent no-op via a DOM-presence check, so nothing here changes what
       retail/cosmetic/clothing/pharmacy stores already render on this same
       shelf theme. See routes/verticals/public/lane-e.php for the endpoints
       the script fetches. */
    $fitment = app(\App\Services\Verticals\VerticalRegistry::class)->has($store->store_type ?? null, 'fitment');

    /* Defensive attribute readers — Lane A (A1 brand/weight/dimensions/attributes,
       A2 variant sku/stock/barcode) may not be merged yet. Every accessor here
       degrades to null/[] rather than erroring, on both an undefined column
       (Eloquent returns null for it) and an undefined relation (same). */
    $attrsOf = \Juwri\Core\Support\ViewHelpers::attrsOf(...);
    $brandOf = \Juwri\Core\Support\ViewHelpers::brandOf(...);
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelAxes(...);

    // P2 (variant swatches) — best-effort real color for a card's swatch dot:
    // parse a hex code or a common color NAME out of the option label text
    // ("Red", "Navy Blue", "#2563eb"...). Anything unrecognised returns null,
    // so the dot keeps the neutral var(--sh-line) the CSS already defaults to
    // — never a wrong-guess color.
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

    // P2 (stock badge) — "Out of stock" / ":count left" / "In stock", shown only
    // when this item actually tracks inventory AND a count is known (A2 is
    // defensive — trackInventory/stockQty are both null until Lane A merges,
    // so this renders nothing for every item today with no further changes
    // needed once it lands).
    $stockBadge = \Juwri\Core\Support\ViewHelpers::stockBadge(...);

    // P1 currency fix — the SAME symbol/position/gap/decimals contract
    // price_symbol_format() (used for every priceFormatted below) already
    // honours, so client-computed totals (cart subtotal, sheet unit×qty,
    // per-line totals) render with the right symbol/side/decimals instead of
    // formatMoney()'s old hardcoded-2-decimal, symbol-less number (crave's own
    // currency config block, index.blade.php ~L1542-1548, is the same shape).
    $curSignRaw  = $postOptions->currency_sign ?? config('settings.currency_sign', '$');
    $curSign     = cur_isolate($curSignRaw);
    $curGap      = currency_symbol_gap($curSignRaw);
    $curPos      = (string) currency_pos_effective($store->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);

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
                // A2 (defensive — null until Lane A merges):
                'sku' => $v->sku ?? null,
                'barcode' => $v->barcode ?? null,
                'stockQty' => isset($v->stock_qty) ? (int) $v->stock_qty : null,
                // P2 — best-effort real swatch color parsed from the option label.
                'color' => $swatchColor($vLabel),
            ];
        }

        $attrs = $attrsOf($m);
        $root = $rootOf[$m->category_id] ?? $m->category_id;

        $jsItems[$m->id] = [
            'id' => $m->id,
            'cat' => $m->category_id,
            'root' => $root,
            'name' => $m->name,
            'desc' => strip_tags((string) $m->description),
            // Only a REAL uploaded image — never the food-themed default.png, which
            // would render a fork-and-plate glyph on a retail/car-parts/pharmacy card.
            'img' => ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '',
            'price' => (float) $m->price,
            'priceFormatted' => price_symbol_format($m->price),
            // A1 (defensive — null until Lane A merges):
            'brand' => $brandOf($m),
            'weight' => $m->weight ?? null,
            'dimensions' => $m->dimensions ?? null,
            'attrs' => $attrs,
            // Existing columns usable today (barcode-ish + simple stock):
            'sku' => $m->plu ?? $m->external_sku ?? null,
            'soldOut' => (bool) $m->is_sold_out,
            'trackInventory' => (bool) ($m->track_inventory ?? false),
            'stockQty' => isset($m->stock_qty) ? (int) $m->stock_qty : null,
            // P2 — pre-computed so both the server-rendered card AND the JS sheet
            // (openItem()) can show the same "Low stock / N left / In stock" text
            // without re-deriving the threshold logic twice.
            'stockBadge' => $stockBadge((bool) ($m->track_inventory ?? false), isset($m->stock_qty) ? (int) $m->stock_qty : null),
            // A7 hook slot (Lane F/F1 owns the real column + server gate):
            'ageRestricted' => (bool) ($m->age_restricted ?? false),
            'variants' => $variants,
            // rev/multi-vertical Lane V (integration fix 1) — the native
            // goods cart endpoint (Lane B) for THIS item, so add-to-cart
            // lands a real CartLine instead of only living in this theme's
            // own client-side preview array.
            'addRoute' => route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $m->id]),
        ];
    }

    // P2 (brand facet) — unique brands actually present on this page's items,
    // alphabetised; empty for every store whose items carry no brand (A1 is
    // defensive), so the facet below renders nothing until Lane A merges.
    $brands = collect($jsItems)->pluck('brand')->filter()->unique()->sort()->values();

    // rev/multi-vertical Lane V (integration fix 1) — native checkout (Lane B's
    // GoodsCheckoutController) replaces the legacy restaurant.submitOrder target
    // below. Server-side cart state (added to via each item's `addRoute`
    // above) is what actually gets ordered; this drawer's own `cart` array is
    // just a client-side preview of the same lines.
    $checkoutRoute = route('storefront.checkout.place', ['slug' => $store->slug]);
    // A route "template" for a dynamic cart-line id, so removing a line from
    // this drawer can zero it out server-side too (999999999 is a placeholder
    // swapped for the real line id client-side — the line id only exists
    // after the server creates it, so it can't be baked in per-item like
    // `addRoute` above).
    $cartLineRouteTemplate = route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => 999999999]);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --sh-bg:#f7f7f8; --sh-surface:#ffffff; --sh-ink:#15181d; --sh-muted:#6b7280;
        --sh-line:#e6e7eb; --sh-accent:{{ $store->color ?: '#1f6feb' }}; --sh-accent-ink:#ffffff;
        --sh-danger:#c0322a; --sh-radius:14px;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --sh-bg:#0f1115; --sh-surface:#181b21; --sh-ink:#f3f4f6; --sh-muted:#9aa1ac; --sh-line:#2a2e37;
        }
    }
    :root[data-theme="dark"]{
        --sh-bg:#0f1115; --sh-surface:#181b21; --sh-ink:#f3f4f6; --sh-muted:#9aa1ac; --sh-line:#2a2e37;
    }
    .shelf-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:20}
    .shelf-skip:focus{inset-inline-start:0}
    *{box-sizing:border-box}
    .shelf-body{margin:0;background:var(--sh-bg);color:var(--sh-ink);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;}
    .sh{max-width:1180px;margin:0 auto;padding:0 14px 90px}
    .sh-head{position:sticky;top:0;z-index:6;display:flex;align-items:center;gap:10px;
        padding:12px 14px;background:var(--sh-surface);border-block-end:1px solid var(--sh-line)}
    .sh-brand{display:flex;align-items:center;gap:10px;min-width:0;flex:1 1 auto}
    .sh-logo{width:38px;height:38px;border-radius:10px;object-fit:cover;flex:0 0 auto;background:var(--sh-line)}
    .sh-title{font-weight:700;font-size:1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .sh-iconbtn{border:0;background:transparent;color:inherit;cursor:pointer;padding:8px;border-radius:10px;
        display:inline-flex;align-items:center;justify-content:center;position:relative}
    .sh-iconbtn:hover{background:color-mix(in srgb, currentColor 8%, transparent)}
    .sh-iconbtn svg{width:20px;height:20px}
    .sh-badge{position:absolute;top:2px;inset-inline-end:2px;background:var(--sh-accent);color:var(--sh-accent-ink);
        font-size:.62rem;font-weight:700;min-width:16px;height:16px;border-radius:999px;display:flex;
        align-items:center;justify-content:center;padding:0 3px}
    .sh-badge[hidden]{display:none}
    .sh-search-wrap{display:flex;flex-wrap:wrap;gap:8px;padding:12px 0 4px}
    .sh-search{flex:1 1 200px;width:100%;padding:10px 14px;border-radius:999px;border:1px solid var(--sh-line);
        background:var(--sh-surface);color:inherit;font-size:.92rem}
    .sh-search:focus{outline:2px solid var(--sh-accent);outline-offset:1px}
    .sh-brand-select{flex:0 1 160px;min-width:120px;padding:10px 12px;border-radius:999px;border:1px solid var(--sh-line);
        background:var(--sh-surface);color:inherit;font-size:.86rem}
    .sh-brand-select:focus{outline:2px solid var(--sh-accent);outline-offset:1px}
    .sh-cats{display:flex;gap:8px;overflow-x:auto;overflow-y:clip;padding:10px 0;scrollbar-width:thin}
    .sh-chip{flex:0 0 auto;border:1px solid var(--sh-line);background:var(--sh-surface);color:inherit;
        padding:7px 14px;border-radius:999px;font-size:.84rem;cursor:pointer;white-space:nowrap}
    .sh-chip.is-active{background:var(--sh-accent);border-color:var(--sh-accent);color:var(--sh-accent-ink);font-weight:600}
    .sh-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;padding-block-start:6px}
    .sh-card{background:var(--sh-surface);border:1px solid var(--sh-line);border-radius:var(--sh-radius);
        overflow:clip;display:flex;flex-direction:column;text-align:start}
    .sh-card__imgwrap{aspect-ratio:1/1;background:var(--sh-line);overflow:clip;position:relative}
    .sh-card__img{width:100%;height:100%;object-fit:cover;display:block}
    .sh-card__img--ph{display:flex;align-items:center;justify-content:center;color:var(--sh-muted,#9aa0a6);background:var(--sh-line)}
    .sh-card__soldout{position:absolute;inset-block-start:8px;inset-inline-start:8px;background:var(--sh-danger);
        color:#fff;font-size:.66rem;font-weight:700;padding:3px 8px;border-radius:6px}
    .sh-card__media-link{display:block;width:100%;height:100%}
    .sh-card__media-link:focus-visible{outline:2px solid var(--sh-accent);outline-offset:-2px}
    .sh-fav-btn{position:absolute;inset-block-start:6px;inset-inline-end:6px;width:28px;height:28px;border-radius:50%;
        border:0;padding:0;cursor:pointer;display:flex;align-items:center;justify-content:center;
        background:color-mix(in srgb, var(--sh-surface) 80%, transparent);color:var(--sh-muted)}
    .sh-fav-btn svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2}
    .sh-fav-btn.is-fav{color:var(--sh-danger)}
    .sh-fav-btn.is-fav svg{fill:currentColor}
    .sh-card__body{padding:10px 12px 12px;display:flex;flex-direction:column;gap:4px;flex:1}
    .sh-card__brand{font-size:.68rem;color:var(--sh-muted);text-transform:uppercase;letter-spacing:.04em}
    .sh-card__name{font-weight:600;font-size:.92rem;line-height:1.25;
        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:clip}
    .sh-card__swatches{display:flex;gap:4px;margin-block-start:2px}
    .sh-swatch-dot{width:12px;height:12px;border-radius:50%;border:1px solid var(--sh-line);background:var(--sh-line)}
    .sh-stock{font-size:.68rem;font-weight:600;margin-block-start:2px}
    .sh-stock--low{color:#b45309}
    .sh-stock--in{color:#1a8f4c}
    .sh-stock--out{color:var(--sh-danger)}
    .sh-card__foot{margin-block-start:auto;display:flex;align-items:center;justify-content:space-between;gap:6px;padding-block-start:6px}
    .sh-price{font-weight:700;font-size:.94rem;font-variant-numeric:tabular-nums}
    .sh-add{border:0;background:var(--sh-accent);color:var(--sh-accent-ink);border-radius:10px;
        padding:7px 11px;font-size:.8rem;font-weight:600;cursor:pointer}
    .sh-add:disabled{opacity:.45;cursor:not-allowed}
    .sh-empty{padding:60px 10px;text-align:center;color:var(--sh-muted)}
    .sh-disclaimer{margin-block-start:10px;padding:10px 12px;border-radius:10px;font-size:.78rem;line-height:1.4;
        color:var(--sh-muted);background:color-mix(in srgb, var(--sh-ink) 5%, transparent);border:1px solid var(--sh-line)}

    .sh-sheet-scrim{position:fixed;inset:0;background:rgba(10,12,16,.5);z-index:9;opacity:0;pointer-events:none;transition:opacity .18s ease}
    .sh-sheet-scrim.is-open{opacity:1;pointer-events:auto}
    .sh-sheet{position:fixed;inset-block:0;inset-inline-end:0;width:min(420px,100%);background:var(--sh-surface);
        color:inherit;z-index:10;transform:translateX(100%);transition:transform .22s ease;display:flex;flex-direction:column;
        box-shadow:-8px 0 30px rgba(0,0,0,.25)}
    [dir="rtl"] .sh-sheet{transform:translateX(-100%)}
    .sh-sheet.is-open{transform:translateX(0)}
    .sh-sheet__head{display:flex;align-items:center;justify-content:space-between;padding:14px;border-block-end:1px solid var(--sh-line)}
    .sh-sheet__title{font-weight:700}
    .sh-sheet__body{flex:1;overflow-y:auto;overflow-x:clip;padding:14px}
    .sh-sheet__foot{padding:14px;border-block-start:1px solid var(--sh-line)}
    .sh-detail-img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:12px;background:var(--sh-line);margin-block-end:12px}
    .sh-group{margin-block-end:14px}
    .sh-group__label{font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--sh-muted);margin-block-end:6px}
    .sh-opts{display:flex;flex-wrap:wrap;gap:8px}
    .sh-opt{border:1px solid var(--sh-line);background:var(--sh-surface);color:inherit;border-radius:8px;
        padding:7px 12px;font-size:.84rem;cursor:pointer}
    .sh-opt.is-active{border-color:var(--sh-accent);background:color-mix(in srgb, var(--sh-accent) 14%, transparent);font-weight:600}
    .sh-opt:disabled{opacity:.4;text-decoration:line-through;cursor:not-allowed}
    .sh-attrs{width:100%;border-collapse:collapse;font-size:.84rem}
    .sh-attrs td{padding:6px 0;border-block-end:1px solid var(--sh-line)}
    .sh-attrs td:first-child{color:var(--sh-muted);padding-inline-end:10px;width:40%}
    .sh-agegate{border:1px solid var(--sh-danger);background:color-mix(in srgb, var(--sh-danger) 8%, transparent);
        border-radius:10px;padding:10px;font-size:.84rem;margin-block-end:12px}
    .sh-agegate__actions{display:flex;gap:8px;margin-block-start:8px}
    .sh-btn{border:0;border-radius:10px;padding:9px 14px;font-size:.86rem;font-weight:600;cursor:pointer}
    .sh-btn--primary{background:var(--sh-accent);color:var(--sh-accent-ink)}
    .sh-btn--ghost{background:transparent;border:1px solid var(--sh-line);color:inherit}
    .sh-qty{display:flex;align-items:center;gap:10px}
    .sh-qty button{width:30px;height:30px;border-radius:8px;border:1px solid var(--sh-line);background:var(--sh-surface);color:inherit;cursor:pointer}
    .sh-cart-line{display:flex;gap:10px;padding:10px 0;border-block-end:1px solid var(--sh-line)}
    .sh-cart-line img{width:52px;height:52px;border-radius:8px;object-fit:cover;background:var(--sh-line);flex:0 0 auto}
    .sh-cart-line__body{flex:1;min-width:0}
    .sh-cart-line__remove{background:transparent;border:0;color:var(--sh-danger);cursor:pointer;font-size:.78rem;padding:0}
    .sh-totalrow{display:flex;justify-content:space-between;font-weight:700;padding-block:8px}
    .sh-field{display:block;width:100%;margin-block-end:10px}
    .sh-field label{display:block;font-size:.78rem;color:var(--sh-muted);margin-block-end:4px}
    .sh-field input,.sh-field select{width:100%;padding:9px 10px;border-radius:8px;border:1px solid var(--sh-line);
        background:var(--sh-surface);color:inherit;font-size:.88rem}
    .sh-msg{font-size:.84rem;padding:8px 10px;border-radius:8px;margin-block-end:10px}
    .sh-msg--error{background:color-mix(in srgb, var(--sh-danger) 12%, transparent);color:var(--sh-danger)}
    .sh-msg--ok{background:color-mix(in srgb, #1a8f4c 14%, transparent);color:#1a8f4c}
    .sh-slots{display:flex;flex-wrap:wrap;gap:8px;margin-block:6px}
    [hidden]{display:none !important}
</style>
@endpush

@section('content')
<div class="shelf" id="shelf" data-store-id="{{ $store->id }}" data-theme-slug="shelf">
    <header class="sh-head">
        <div class="sh-brand">
            @if($store->main_image && $store->main_image !== 'default.png')
                @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img class="sh-logo" src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="">@endif
            @endif
            <span class="sh-title">{{ $store->title }}</span>
        </div>
        <button type="button" class="sh-iconbtn" id="shDarkToggle" aria-label="{{ ___('Toggle dark mode') }}" title="{{ ___('Toggle dark mode') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
        </button>
        <button type="button" class="sh-iconbtn" id="shCartOpen" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg>
            <i class="sh-badge" id="shCartBadge" hidden>0</i>
        </button>
    </header>

    <main class="sh" id="shelf-main">
        @if($showOtcDisclaimer)
            {{-- Lane F3: OTC compliance disclaimer, "not medical advice". --}}
            <div class="sh-disclaimer" role="note">{{ $otcDisclaimerText }}</div>
        @endif
        <div class="sh-search-wrap">
            <input type="search" class="sh-search" id="shSearch" autocomplete="off"
                   placeholder="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}"
                   aria-label="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Products'))]) }}">
            @if($brands->isNotEmpty())
            {{-- P2: brand facet, alongside the category chips + search. --}}
            <select class="sh-brand-select" id="shBrandFilter" aria-label="{{ ___('Brand') }}">
                <option value="">{{ ___('All brands') }}</option>
                @foreach($brands as $b)
                    <option value="{{ Str::lower($b) }}">{{ $b }}</option>
                @endforeach
            </select>
            @endif
        </div>

        @if($categories->isNotEmpty())
        <nav class="sh-cats" role="tablist" aria-label="{{ $vocab['categories'] ?? ___('Categories') }}">
            <button type="button" class="sh-chip is-active" role="tab" aria-selected="true" aria-controls="shGrid" data-cat="all">{{ ___('All') }}</button>
            @foreach($categories as $cat)
                <button type="button" class="sh-chip" role="tab" aria-selected="false" aria-controls="shGrid" data-cat="{{ $cat->id }}">{{ $cat->name }}</button>
            @endforeach
        </nav>
        @endif

        @if($fitment)
        {{-- rev/multi-vertical Lane E2 — Year/Make/Model(/Submodel/Engine)
             cascading filter + OEM/cross-ref search (E5). Self-contained: its
             own CSS/JS pushes below, its own public JSON endpoints. Renders
             ONLY for carparts stores — see the `$fitment` capability check
             above. --}}
        <div class="sh-ymm" id="shYmm" data-store-slug="{{ $store->slug }}">
            <div class="sh-ymm__row">
                <select class="sh-ymm__select" id="shYmmYear" aria-label="{{ ___('Year') }}">
                    <option value="">{{ ___('Year') }}</option>
                </select>
                <select class="sh-ymm__select" id="shYmmMake" aria-label="{{ ___('Make') }}" disabled>
                    <option value="">{{ ___('Make') }}</option>
                </select>
                <select class="sh-ymm__select" id="shYmmModel" aria-label="{{ ___('Model') }}" disabled>
                    <option value="">{{ ___('Model') }}</option>
                </select>
                <select class="sh-ymm__select" id="shYmmSubmodel" aria-label="{{ ___('Submodel') }}" hidden>
                    <option value="">{{ ___('Submodel') }}</option>
                </select>
                <select class="sh-ymm__select" id="shYmmEngine" aria-label="{{ ___('Engine') }}" hidden>
                    <option value="">{{ ___('Engine') }}</option>
                </select>
                <button type="button" class="sh-ymm__clear" id="shYmmClear" hidden>{{ ___('Clear') }}</button>
            </div>
            <p class="sh-ymm__hint" id="shYmmHint">{{ ___('Select your vehicle to find compatible parts.') }}</p>
            <div class="sh-ymm__row sh-ymm__row--oem">
                <input type="search" class="sh-search" id="shOemSearch" autocomplete="off"
                       placeholder="{{ ___('Search by OEM or cross-reference number') }}"
                       aria-label="{{ ___('Search by OEM or cross-reference number') }}">
            </div>
        </div>
        @endif

        @if($items->isEmpty())
            <p class="sh-empty">{{ ___('Nothing here yet.') }}</p>
        @else
            <div class="sh-grid" id="shGrid">
                @foreach($items as $m)
                    @php $jd = $jsItems[$m->id]; @endphp
                    <article class="sh-card" data-id="{{ $m->id }}" data-root="{{ $jd['root'] }}"
                             data-brand="{{ Str::lower($jd['brand'] ?? '') }}"
                             data-name="{{ Str::lower($jd['name']) }}" data-desc="{{ Str::lower($jd['desc']) }}">
                        <div class="sh-card__imgwrap">
                            @if($jd['soldOut'])
                                <span class="sh-card__soldout">{{ ___('Sold out') }}</span>
                            @endif
                            {{-- P3: a real crawlable/shareable ?item= URL — the script
                                 intercepts this click and opens the sheet in place
                                 (pushState), same as the boot-time ?item= deep link. --}}
                            <a href="?item={{ $m->id }}" class="sh-card__media-link" data-open-item-link="{{ $m->id }}" aria-label="{{ $jd['name'] }}">
                                @if($jd['img'])
                                    @if((string) ($jd['img']) !== '')<img class="sh-card__img" src="{{ $jd['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
                                @else
                                    <div class="sh-card__img sh-card__img--ph" role="img" aria-label="{{ $jd['name'] }}">
                                        <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 7l9-4 9 4v10l-9 4-9-4V7z"/><path d="M3 7l9 4 9-4M12 11v10"/></svg>
                                    </div>
                                @endif
                            </a>
                            {{-- P3: per-card favorite toggle, persisted to localStorage. --}}
                            <button type="button" class="sh-fav-btn" data-fav-toggle="{{ $m->id }}" aria-pressed="false" aria-label="{{ ___('Add to favorites') }}">
                                <svg viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                            </button>
                        </div>
                        <div class="sh-card__body">
                            @if($jd['brand'])
                                <span class="sh-card__brand">{{ $jd['brand'] }}</span>
                            @endif
                            <span class="sh-card__name">{{ $jd['name'] }}</span>
                            @if(count($jd['variants']) > 1)
                                <div class="sh-card__swatches" aria-hidden="true">
                                    @foreach(array_slice($jd['variants'], 0, 5) as $v)
                                        <span class="sh-swatch-dot" @if($v['color']) style="background:{{ $v['color'] }}" @endif title="{{ $v['label'] }}"></span>
                                    @endforeach
                                </div>
                            @endif
                            @if($jd['stockBadge'] && ! $jd['soldOut'])
                                {{-- Skip when the card's own "Sold out" ribbon already
                                     covers this (avoids "Sold out" + "Out of stock" together). --}}
                                <span class="sh-stock sh-stock--{{ $jd['stockBadge']['type'] }}">{{ $jd['stockBadge']['text'] }}</span>
                            @endif
                            <div class="sh-card__foot">
                                <span class="sh-price" data-role="price">{{ $jd['priceFormatted'] }}</span>
                                <button type="button" class="sh-add" data-open-item="{{ $m->id }}" @if($jd['soldOut']) disabled @endif>
                                    {{ count($jd['variants']) > 1 ? ___('Choose') : ($vocab['add_to_cart'] ?? ___('Add to cart')) }}
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            <p class="sh-empty" id="shNoResults" hidden>{{ ___('No results.') }}</p>
        @endif
    </main>

    {{-- Product detail sheet (C2): variant matrix, attributes table, age-gate hook, add-to-cart. --}}
    <div class="sh-sheet-scrim" id="shItemScrim"></div>
    <aside class="sh-sheet" id="shItemSheet" role="dialog" aria-modal="true" aria-label="{{ $vocab['item'] ?? ___('Product') }}" aria-hidden="true" inert>
        <div class="sh-sheet__head">
            <strong class="sh-sheet__title" id="shItemName" tabindex="-1"></strong>
            <button type="button" class="sh-iconbtn" id="shItemClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="sh-sheet__body">
            <img class="sh-detail-img" id="shItemImg" alt="" data-csp-error="hide">
            <p id="shItemDesc" style="color:var(--sh-muted);font-size:.88rem"></p>
            <span class="sh-stock" id="shItemStock" hidden></span>

            <div class="sh-agegate" id="shAgeGate" hidden>
                <span>{{ ___('This item is age-restricted. Confirm you meet the minimum age to buy it.') }}</span>
                <div class="sh-agegate__actions">
                    <button type="button" class="sh-btn sh-btn--primary" id="shAgeConfirm">{{ ___('Confirm') }}</button>
                    <button type="button" class="sh-btn sh-btn--ghost" id="shAgeCancel">{{ ___('Cancel') }}</button>
                </div>
            </div>

            <div id="shVariantGroups"></div>

            <div class="sh-group" id="shAttrsGroup" hidden>
                <div class="sh-group__label">{{ ___('Details') }}</div>
                <table class="sh-attrs" id="shAttrsTable"></table>
            </div>

            <div class="sh-group">
                <div class="sh-group__label">{{ ___('Quantity') }}</div>
                <div class="sh-qty">
                    <button type="button" id="shQtyMinus" aria-label="{{ ___('Decrease') }}">&minus;</button>
                    <span id="shQtyVal">1</span>
                    <button type="button" id="shQtyPlus" aria-label="{{ ___('Increase') }}">&plus;</button>
                </div>
            </div>
        </div>
        <div class="sh-sheet__foot">
            <button type="button" class="sh-btn sh-btn--primary" id="shAddToCart" style="width:100%">
                <span id="shAddToCartLabel">{{ $vocab['add_to_cart'] ?? ___('Add to cart') }}</span>
                <b id="shAddToCartPrice"></b>
            </button>
        </div>
    </aside>

    {{-- Cart / checkout sheet — posts straight to Lane B's native goods
         checkout (GoodsCheckoutController), the same store-side cart each
         item's "Add to cart" fills via its own `addRoute` (Lane V integration
         fix 1). --}}
    <div class="sh-sheet-scrim" id="shCartScrim"></div>
    <aside class="sh-sheet" id="shCartSheet" role="dialog" aria-modal="true" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}" aria-hidden="true" inert>
        <div class="sh-sheet__head">
            <strong class="sh-sheet__title" id="shCartTitle" tabindex="-1">{{ $vocab['orders'] ?? ___('Cart') }}</strong>
            <button type="button" class="sh-iconbtn" id="shCartClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="sh-sheet__body">
            <div id="shCartView">
                <div id="shCartLines"></div>
                <p class="sh-empty" id="shCartEmpty">{{ ___('Your cart is empty.') }}</p>
                <div class="sh-totalrow" id="shCartTotalRow" hidden>
                    <span>{{ ___('Subtotal') }}</span>
                    <span id="shCartTotal"></span>
                </div>
            </div>

            <form id="shCheckoutForm" hidden>
                <div id="shCheckoutMsg"></div>
                <div class="sh-field">
                    <label for="shName">{{ ___('Name') }}</label>
                    <input type="text" id="shName" name="name" required maxlength="255">
                </div>
                <div class="sh-field">
                    <label for="shPhone">{{ ___('Phone') }}</label>
                    <input type="tel" id="shPhone" name="phone" required maxlength="25">
                </div>
                @if($modes->count() > 1)
                <div class="sh-field">
                    <label for="shMode">{{ ___('Fulfillment') }}</label>
                    <select id="shMode" name="mode">
                        @foreach($modes as $mode)
                            <option value="{{ $mode['value'] }}" data-needs-address="{{ $mode['needsAddress'] ? 1 : 0 }}" data-needs-slot="{{ $mode['needsSlot'] ? 1 : 0 }}">{{ $mode['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="sh-field" id="shAddressField" hidden>
                    <label for="shAddress">{{ ___('Address') }}</label>
                    <input type="text" id="shAddress" name="address" maxlength="255">
                </div>
                <div class="sh-field" id="shSlotField" hidden>
                    <label>{{ ___('Pick a time') }}</label>
                    <div class="sh-slots" id="shSlots"></div>
                </div>
                <button type="button" class="sh-btn sh-btn--primary" id="shPlaceOrder" style="width:100%">
                    {{ ___('Place order') }}
                </button>
            </form>
        </div>
        <div class="sh-sheet__foot" id="shCartFoot">
            <button type="button" class="sh-btn sh-btn--primary" id="shCheckoutBtn" style="width:100%" disabled>
                {{ ___('Checkout') }}
            </button>
        </div>
    </aside>
</div>
@endsection

@push('script')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var ITEMS = @json($jsItems);
    var MODES = @json($modes);
    var SLOT_ROUTE = @json(route('store.scheduleSlots', $store->id));
    // rev/multi-vertical Lane V (integration fix 1) — native goods checkout
    // (Lane B's GoodsCheckoutController). Each item's own `addRoute` (see
    // ITEMS above) posts to Lane B's GoodsCartController instead.
    var CHECKOUT_ROUTE = @json($checkoutRoute);
    var CART_LINE_ROUTE_TEMPLATE = @json($cartLineRouteTemplate);
    function cartLineRoute(lineId) { return CART_LINE_ROUTE_TEMPLATE.replace('999999999', String(lineId)); }
    var CSRF = @json(csrf_token());
    // P1 — the store's own currency contract (same shape crave's CFG carries:
    // index.blade.php ~L1542-1548), so client-computed totals match every
    // server-rendered priceFormatted instead of formatMoney()'s old hardcoded
    // 2-decimal, symbol-less number.
    var CUR = {
        sign: @json($curSign),
        gap: @json($curGap),
        pos: @json($curPos),
        decimals: @json($curDecimals),
    };
    var T = {
        addToCart: @json($vocab['add_to_cart'] ?? ___('Add to cart')),
        outOfStock: @json(___('Out of stock')),
        chooseOption: @json(___('Please choose an option.')),
        cartEmpty: @json(___('Your cart is empty.')),
        placing: @json(___('Placing order…')),
        placed: @json(___('Order placed! Thank you.')),
        genericError: @json(___('Something went wrong. Please try again.')),
        nameRequired: @json(___('Please enter your name.')),
        addressRequired: @json(___('Please enter a delivery address.')),
        removeFav: @json(___('Remove from favorites')),
        addFav: @json(___('Add to favorites')),
        stockLeft: @json(___(':count left')),
        inStock: @json(___('In stock')),
    };

    var root = document.getElementById('shelf');
    if (!root) { return; }

    /* ---------------- dark mode ---------------- */
    var darkKey = 'shelf-theme-' + (root.getAttribute('data-store-id') || '');
    try {
        var saved = window.localStorage.getItem(darkKey);
        if (saved) { document.documentElement.setAttribute('data-theme', saved); }
    } catch (e) { /* storage may be unavailable — default (system) stands */ }
    var darkBtn = document.getElementById('shDarkToggle');
    if (darkBtn) {
        darkBtn.addEventListener('click', function () {
            var cur = document.documentElement.getAttribute('data-theme');
            var next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { window.localStorage.setItem(darkKey, next); } catch (e) { /* best-effort */ }
        });
    }

    /* ---------------- P2: sheet a11y (Escape / back-close / focus trap+restore
       / aria-hidden+inert) ----------------
       shelf's off-canvas sheets only ever have ONE open at a time (each has its
       own full-screen scrim, which blocks pointer access to everything else),
       so a single tracked entry — not a stack — mirrors the orbit/velora
       pattern (assets/store_templates/orbit/js/script.js ~L211-259): one guard
       history entry per "sheet session", Escape/scrim/× route through
       history.back() so a real Back press closes it too, and SWITCHING sheets
       (item → cart after a successful add) closes the first one RAW (no extra
       history entry) before opening the next. */
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
            try { history.pushState({ shSheet: 1 }, '', url || undefined); } catch (e) { /* best-effort */ }
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

    /* ---------------- category filter + search ---------------- */
    var grid = document.getElementById('shGrid');
    var cards = grid ? Array.prototype.slice.call(grid.querySelectorAll('.sh-card')) : [];
    var noResults = document.getElementById('shNoResults');
    var activeCat = 'all';
    var activeBrand = '';
    var query = '';

    function applyFilter() {
        var visible = 0;
        cards.forEach(function (card) {
            var matchesCat = activeCat === 'all' || card.getAttribute('data-root') === activeCat;
            var matchesBrand = !activeBrand || card.getAttribute('data-brand') === activeBrand;
            var matchesQuery = !query
                || card.getAttribute('data-name').indexOf(query) !== -1
                || card.getAttribute('data-desc').indexOf(query) !== -1;
            var show = matchesCat && matchesBrand && matchesQuery;
            card.hidden = !show;
            if (show) { visible++; }
        });
        if (noResults) { noResults.hidden = visible !== 0; }
    }

    document.querySelectorAll('.sh-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            document.querySelectorAll('.sh-chip').forEach(function (c) {
                c.classList.remove('is-active');
                c.setAttribute('aria-selected', 'false');
            });
            chip.classList.add('is-active');
            chip.setAttribute('aria-selected', 'true');
            activeCat = chip.getAttribute('data-cat');
            applyFilter();
        });
    });

    var searchInput = document.getElementById('shSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            query = searchInput.value.trim().toLowerCase();
            applyFilter();
        });
    }

    // P2 — brand facet (rendered only when items actually carry a brand).
    var brandFilter = document.getElementById('shBrandFilter');
    if (brandFilter) {
        brandFilter.addEventListener('change', function () {
            activeBrand = brandFilter.value;
            applyFilter();
        });
    }

    /* ---------------- cart state ---------------- */
    var CART_KEY = 'shelf-cart-' + (root.getAttribute('data-store-id') || '');
    var cart = [];
    try {
        var storedCart = window.localStorage.getItem(CART_KEY);
        if (storedCart) { cart = JSON.parse(storedCart) || []; }
    } catch (e) { cart = []; }

    function saveCart() {
        try { window.localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) { /* best-effort */ }
    }

    function lineTotal(line) {
        var item = ITEMS[line.id];
        if (!item) { return 0; }
        var unit = item.price;
        if (line.variantId) {
            var v = item.variants.filter(function (x) { return x.id === line.variantId; })[0];
            if (v) { unit = v.price; }
        }
        return unit * line.qty;
    }

    function cartCount() {
        return cart.reduce(function (n, l) { return n + l.qty; }, 0);
    }

    function formatMoney(n) {
        // P1 — mirrors price_symbol_format() server-side: the store's real
        // symbol, side (before/after), gap (dirham spacing) and per-currency
        // decimal count (0 for JPY, 3 for KWD/BHD…), not a hardcoded "$"-less
        // 2-decimal number. See CUR above (same shape as crave's CFG block).
        var amount = Number(n) || 0;
        var str;
        try {
            str = amount.toLocaleString(document.documentElement.lang || undefined, {
                minimumFractionDigits: CUR.decimals, maximumFractionDigits: CUR.decimals,
            });
        } catch (e) { str = amount.toFixed(CUR.decimals); }
        return CUR.pos === '1' ? (CUR.sign + CUR.gap + str) : (str + CUR.gap + CUR.sign);
    }

    function renderCartBadge() {
        var badge = document.getElementById('shCartBadge');
        if (!badge) { return; }
        var n = cartCount();
        badge.textContent = String(n);
        badge.hidden = n === 0;
    }

    function renderCart() {
        var linesEl = document.getElementById('shCartLines');
        var emptyEl = document.getElementById('shCartEmpty');
        var totalRow = document.getElementById('shCartTotalRow');
        var totalEl = document.getElementById('shCartTotal');
        var checkoutBtn = document.getElementById('shCheckoutBtn');
        if (!linesEl) { return; }

        linesEl.innerHTML = '';
        if (!cart.length) {
            emptyEl.hidden = false;
            totalRow.hidden = true;
            if (checkoutBtn) { checkoutBtn.disabled = true; }
            renderCartBadge();
            return;
        }
        emptyEl.hidden = true;
        totalRow.hidden = false;
        if (checkoutBtn) { checkoutBtn.disabled = false; }

        var sum = 0;
        cart.forEach(function (line, idx) {
            var item = ITEMS[line.id];
            if (!item) { return; }
            var lt = lineTotal(line);
            sum += lt;
            var variantLabel = '';
            if (line.variantId) {
                var v = item.variants.filter(function (x) { return x.id === line.variantId; })[0];
                if (v) { variantLabel = v.label; }
            }
            var row = document.createElement('div');
            row.className = 'sh-cart-line';
            var img = document.createElement('img');
            img.alt = '';
            img.setAttribute('data-csp-error', 'hide');
            if (item.img) { img.src = item.img; } else { img.hidden = true; }
            var body = document.createElement('div');
            body.className = 'sh-cart-line__body';
            var nameEl = document.createElement('div');
            nameEl.textContent = item.name + (variantLabel ? ' · ' + variantLabel : '') + ' × ' + line.qty;
            var priceEl = document.createElement('div');
            priceEl.style.fontWeight = '700';
            priceEl.textContent = formatMoney(lt);
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'sh-cart-line__remove';
            rm.textContent = @json(___('Remove'));
            rm.addEventListener('click', function () {
                var removedLineId = line.lineId;
                cart.splice(idx, 1);
                saveCart();
                renderCart();
                // Best-effort: zero the line out on the real server-side cart
                // too, so a removed item doesn't still ship at checkout.
                if (removedLineId) {
                    var fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('quantity', '0');
                    fetch(cartLineRoute(removedLineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                        .catch(function () { /* best-effort */ });
                }
            });
            body.appendChild(nameEl);
            body.appendChild(priceEl);
            body.appendChild(rm);
            row.appendChild(img);
            row.appendChild(body);
            linesEl.appendChild(row);
        });
        totalEl.textContent = formatMoney(sum);
        renderCartBadge();
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

    /* rev/multi-vertical Lane V (integration fix 1) — lands the line on Lane
       B's real store-side cart (GoodsCartService) so checkout below has an
       actual order to place; this theme's own `cart` array above stays a
       client-side preview of the same lines for a snappy drawer. */
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

    renderCart();

    /* ---------------- product detail sheet ---------------- */
    var scrim = document.getElementById('shItemScrim');
    var sheet = document.getElementById('shItemSheet');
    var currentItem = null;
    var currentVariantId = null;
    var currentQty = 1;
    var ageConfirmed = {};
    try {
        var storedAge = window.sessionStorage.getItem('shelf-age-ok-' + (root.getAttribute('data-store-id') || ''));
        if (storedAge) { ageConfirmed = JSON.parse(storedAge) || {}; }
    } catch (e) { ageConfirmed = {}; }

    function currentVariant() {
        if (!currentItem || !currentVariantId) { return null; }
        return currentItem.variants.filter(function (v) { return v.id === currentVariantId; })[0] || null;
    }

    function currentUnitPrice() {
        var v = currentVariant();
        return v ? v.price : (currentItem ? currentItem.price : 0);
    }

    function renderAttrs() {
        var group = document.getElementById('shAttrsGroup');
        var table = document.getElementById('shAttrsTable');
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
                if (k === 'brand') { return; }
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

    function renderVariantGroups() {
        var wrap = document.getElementById('shVariantGroups');
        wrap.innerHTML = '';
        if (!currentItem.variants.length) { return; }
        var group = document.createElement('div');
        group.className = 'sh-group';
        var label = document.createElement('div');
        label.className = 'sh-group__label';
        label.textContent = @json(___('Choose an option'));
        var opts = document.createElement('div');
        opts.className = 'sh-opts';
        currentItem.variants.forEach(function (v) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sh-opt' + (v.id === currentVariantId ? ' is-active' : '');
            btn.textContent = v.label + ' — ' + v.priceFormatted;
            var outOfStock = (v.stockQty !== null && v.stockQty !== undefined && v.stockQty <= 0);
            if (outOfStock) { btn.disabled = true; btn.title = T.outOfStock; }
            btn.addEventListener('click', function () {
                currentVariantId = v.id;
                renderVariantGroups();
                updateSheetPrice();
                renderItemStock();
            });
            opts.appendChild(btn);
        });
        group.appendChild(label);
        group.appendChild(opts);
        wrap.appendChild(group);
    }

    function updateSheetPrice() {
        var priceEl = document.getElementById('shAddToCartPrice');
        priceEl.textContent = formatMoney(currentUnitPrice() * currentQty);
    }

    // P2 — "Low stock / N left / In stock", re-derived from the CURRENT
    // variant's own stockQty when one is selected (falling back to the
    // item-level count), so switching variants shows the right number.
    function renderItemStock() {
        var badge = document.getElementById('shItemStock');
        if (!badge || !currentItem) { return; }
        var v = currentVariant();
        var qty = (v && v.stockQty !== null && v.stockQty !== undefined) ? v.stockQty : currentItem.stockQty;
        if (!currentItem.trackInventory || qty === null || qty === undefined) {
            badge.hidden = true;
            return;
        }
        badge.hidden = false;
        badge.className = 'sh-stock';
        if (qty <= 0) {
            badge.classList.add('sh-stock--out');
            badge.textContent = T.outOfStock;
        } else if (qty <= 5) {
            badge.classList.add('sh-stock--low');
            badge.textContent = T.stockLeft.replace(':count', String(qty));
        } else {
            badge.classList.add('sh-stock--in');
            badge.textContent = T.inStock;
        }
    }

    function openItem(id) {
        var item = ITEMS[id];
        if (!item) { return; }
        currentItem = item;
        currentVariantId = item.variants.length === 1 ? item.variants[0].id : null;
        currentQty = 1;
        document.getElementById('shQtyVal').textContent = '1';
        document.getElementById('shItemName').textContent = item.name;
        var detailImg = document.getElementById('shItemImg');
        // No food default.png fallback — hide the image entirely when there is none.
        if (item.img) { detailImg.src = item.img; detailImg.alt = item.name; detailImg.hidden = false; }
        else { detailImg.removeAttribute('src'); detailImg.hidden = true; }
        document.getElementById('shItemDesc').textContent = item.desc;
        renderVariantGroups();
        renderAttrs();
        renderItemStock();
        updateSheetPrice();

        var gate = document.getElementById('shAgeGate');
        var addBtn = document.getElementById('shAddToCart');
        var needsGate = item.ageRestricted && !ageConfirmed[id];
        gate.hidden = !needsGate;
        addBtn.disabled = !!(item.soldOut);

        // P3: a real ?item= URL while the sheet is open (shareable/crawlable,
        // matches the ?item= boot link below), carried on the SAME pushState
        // openA11ySheet already does for the back-close guard — no separate
        // history entry.
        var itemUrl = window.location.pathname + '?item=' + encodeURIComponent(String(id));
        openA11ySheet(sheet, scrim, document.getElementById('shItemName'), itemUrl);
    }

    function closeItem() {
        dismissA11ySheet();
    }

    // P3 — a single/no-variant item no longer detours through the sheet just to
    // click "Add to cart" again: quick-add it straight from the card. An item
    // with a real choice (variants.length > 1) still opens the sheet to choose,
    // and an age-restricted item still opens the sheet so the gate can be shown
    // (quick-add only fires once that gate is already confirmed).
    function quickAdd(id) {
        var item = ITEMS[id];
        if (!item || item.soldOut) { return; }
        if (item.ageRestricted && !ageConfirmed[id]) {
            openItem(id);
            return;
        }
        var btn = document.querySelector('[data-open-item="' + id + '"]');
        if (btn) { btn.disabled = true; }
        postAddToCart(id, null, 1)
            .then(function (data) { addToCart(id, null, 1, data.line_id); })
            .catch(function () { window.alert(T.genericError); })
            .then(function () { if (btn) { btn.disabled = false; } });
    }

    document.querySelectorAll('[data-open-item]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-open-item'), 10);
            var item = ITEMS[id];
            if (item && item.variants.length <= 1) { quickAdd(id); } else { openItem(id); }
        });
    });
    document.getElementById('shItemClose').addEventListener('click', closeItem);
    scrim.addEventListener('click', closeItem);

    // P3 — the card's image/name link always opens the full detail sheet (view
    // description/attributes/variants), regardless of variant count; the
    // "Add to cart" button above is only a quick-add shortcut. Also gives the
    // card a real, crawlable `?item=` URL (see the anchor in the grid markup).
    document.querySelectorAll('[data-open-item-link]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            openItem(parseInt(link.getAttribute('data-open-item-link'), 10));
        });
    });

    // P3 — per-card favorite toggle, persisted to localStorage (mature-theme
    // pFav pattern), so a returning visitor's picks survive a reload.
    var FAV_KEY = 'shelf-favs-' + (root.getAttribute('data-store-id') || '');
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
        });
    });

    document.getElementById('shQtyMinus').addEventListener('click', function () {
        currentQty = Math.max(1, currentQty - 1);
        document.getElementById('shQtyVal').textContent = String(currentQty);
        updateSheetPrice();
    });
    document.getElementById('shQtyPlus').addEventListener('click', function () {
        currentQty = currentQty + 1;
        document.getElementById('shQtyVal').textContent = String(currentQty);
        updateSheetPrice();
    });

    document.getElementById('shAgeConfirm').addEventListener('click', function () {
        if (currentItem) {
            ageConfirmed[currentItem.id] = true;
            try {
                window.sessionStorage.setItem('shelf-age-ok-' + (root.getAttribute('data-store-id') || ''), JSON.stringify(ageConfirmed));
            } catch (e) { /* best-effort */ }
        }
        document.getElementById('shAgeGate').hidden = true;
    });
    document.getElementById('shAgeCancel').addEventListener('click', closeItem);

    document.getElementById('shAddToCart').addEventListener('click', function () {
        if (!currentItem) { return; }
        if (currentItem.ageRestricted && !ageConfirmed[currentItem.id]) {
            document.getElementById('shAgeGate').hidden = false;
            return;
        }
        if (currentItem.variants.length > 1 && !currentVariantId) {
            window.alert(T.chooseOption);
            return;
        }
        var addBtn = document.getElementById('shAddToCart');
        var item = currentItem, variantId = currentVariantId, qty = currentQty;
        addBtn.disabled = true;
        postAddToCart(item.id, variantId, qty)
            .then(function (data) {
                addToCart(item.id, variantId, qty, data.line_id);
                // Switching sheets (item → cart), not a user dismiss — close the
                // item sheet RAW so it doesn't consume/duplicate the back-close
                // history guard openCart() below is about to (re)use.
                rawCloseA11ySheet();
                openCart();
            })
            .catch(function () {
                window.alert(T.genericError);
            })
            .then(function () { addBtn.disabled = false; });
    });

    /* Deep link: ?item=<id> opens the detail sheet on load (menu-item-anchor
       convention used across the storefront). */
    try {
        var params = new URLSearchParams(window.location.search);
        var deepId = parseInt(params.get('item'), 10);
        if (deepId && ITEMS[deepId]) { openItem(deepId); }
    } catch (e) { /* no-op on an unsupported/old browser */ }

    /* ---------------- cart sheet + checkout ---------------- */
    var cartScrim = document.getElementById('shCartScrim');
    var cartSheet = document.getElementById('shCartSheet');

    function openCart() {
        renderCart();
        openA11ySheet(cartSheet, cartScrim, document.getElementById('shCartTitle'));
    }
    function closeCart() {
        dismissA11ySheet();
    }
    document.getElementById('shCartOpen').addEventListener('click', openCart);
    document.getElementById('shCartClose').addEventListener('click', closeCart);
    cartScrim.addEventListener('click', closeCart);

    function selectedMode() {
        var select = document.getElementById('shMode');
        if (select) {
            var opt = select.options[select.selectedIndex];
            return {
                value: select.value,
                needsAddress: opt && opt.getAttribute('data-needs-address') === '1',
                needsSlot: opt && opt.getAttribute('data-needs-slot') === '1'
            };
        }
        var only = MODES[0] || { value: 'in_store', needsAddress: false, needsSlot: false };
        return { value: only.value, needsAddress: only.needsAddress, needsSlot: only.needsSlot };
    }

    var chosenSlot = null;
    function loadSlots() {
        var wrap = document.getElementById('shSlots');
        wrap.innerHTML = '';
        chosenSlot = null;
        // P3 — the selected fulfillment mode's own value, not a hardcoded food
        // term: shelf's modes are ship/pickup_slot/in_store (config/verticals.php),
        // never "takeaway".
        fetch(SLOT_ROUTE + '?type=' + encodeURIComponent(selectedMode().value), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.enabled || !data.slots || !data.slots.length) { return; }
                data.slots.slice(0, 8).forEach(function (slot) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'sh-opt';
                    btn.textContent = slot.label;
                    btn.disabled = !!slot.full;
                    btn.addEventListener('click', function () {
                        chosenSlot = slot.value;
                        wrap.querySelectorAll('.sh-opt').forEach(function (b) { b.classList.remove('is-active'); });
                        btn.classList.add('is-active');
                    });
                    wrap.appendChild(btn);
                });
            })
            .catch(function () { /* slot picker is best-effort display; checkout still works without it */ });
    }

    function updateModeFields() {
        var mode = selectedMode();
        document.getElementById('shAddressField').hidden = !mode.needsAddress;
        document.getElementById('shSlotField').hidden = !mode.needsSlot;
        if (mode.needsSlot) { loadSlots(); }
    }
    var modeSelect = document.getElementById('shMode');
    if (modeSelect) { modeSelect.addEventListener('change', updateModeFields); }

    document.getElementById('shCheckoutBtn').addEventListener('click', function () {
        document.getElementById('shCartView').hidden = true;
        document.getElementById('shCheckoutForm').hidden = false;
        document.getElementById('shCartFoot').hidden = true;
        updateModeFields();
    });

    function money(n) { return Math.round(n * 100) / 100; }

    document.getElementById('shPlaceOrder').addEventListener('click', function () {
        var msg = document.getElementById('shCheckoutMsg');
        msg.innerHTML = '';
        if (!cart.length) {
            msg.innerHTML = '<div class="sh-msg sh-msg--error">' + T.cartEmpty + '</div>';
            return;
        }
        var name = document.getElementById('shName').value.trim();
        var phone = document.getElementById('shPhone').value.trim();
        // Phone is optional (the server accepts an order without it). Only name is
        // required, and a missing field gets a SPECIFIC message — not the generic
        // "something went wrong", which reads like a server failure.
        if (!name) {
            msg.innerHTML = '<div class="sh-msg sh-msg--error">' + T.nameRequired + '</div>';
            return;
        }
        var mode = selectedMode();
        if (mode.needsAddress && !document.getElementById('shAddress').value.trim()) {
            msg.innerHTML = '<div class="sh-msg sh-msg--error">' + T.addressRequired + '</div>';
            return;
        }

        // rev/multi-vertical Lane V (integration fix 1) — the store-side cart
        // (Lane B's GoodsCartService) already holds these lines, added one at
        // a time via each item's `addRoute`; checkout just needs the mode +
        // customer/address/slot fields, not a re-serialized item list.
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('customer_name', name);
        fd.append('phone_number', phone);
        fd.append('type', mode.value);
        if (mode.needsAddress) { fd.append('address', document.getElementById('shAddress').value.trim()); }
        if (mode.needsSlot && chosenSlot) { fd.append('scheduled_at', chosenSlot); }

        msg.innerHTML = '<div class="sh-msg">' + T.placing + '</div>';
        fetch(CHECKOUT_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    msg.innerHTML = '<div class="sh-msg sh-msg--ok">' + T.placed + '</div>';
                    cart = [];
                    saveCart();
                    renderCart();
                } else {
                    msg.innerHTML = '<div class="sh-msg sh-msg--error"></div>'; msg.firstChild.textContent = (data && data.message) || T.genericError;
                }
            })
            .catch(function () {
                msg.innerHTML = '<div class="sh-msg sh-msg--error">' + T.genericError + '</div>';
            });
    });
})();
</script>
@endpush

@if($fitment)
{{-- rev/multi-vertical Lane E2/E5 — car-parts YMM filter + OEM search. A
     SEPARATE style/script push (not touching the blocks above at all) so this
     is a purely additive extension of the shelf theme, gated on the
     `fitment` capability computed at the top of this file. --}}
@push('style')
<style nonce="{{ csp_nonce() }}">
    .sh-ymm{padding:8px 0 4px;border-block-end:1px solid var(--sh-line);margin-block-end:8px}
    .sh-ymm__row{display:flex;flex-wrap:wrap;gap:8px;margin-block-end:6px}
    .sh-ymm__select{flex:1 1 120px;min-width:100px;padding:8px 10px;border-radius:8px;border:1px solid var(--sh-line);
        background:var(--sh-surface);color:inherit;font-size:.84rem}
    .sh-ymm__select:disabled{opacity:.5}
    .sh-ymm__clear{border:1px solid var(--sh-line);background:transparent;color:inherit;border-radius:8px;
        padding:8px 12px;font-size:.8rem;cursor:pointer}
    .sh-ymm__hint{font-size:.78rem;color:var(--sh-muted);margin:0 0 6px}
    .sh-ymm__hint.is-match{color:#1a8f4c;font-weight:600}
    .sh-ymm__hint.is-nomatch{color:var(--sh-danger);font-weight:600}
    .sh-fit-badge{position:absolute;bottom:8px;inset-inline-start:8px;background:#1a8f4c;color:#fff;
        font-size:.62rem;font-weight:700;padding:3px 7px;border-radius:6px}
    #shGrid.sh-pf-active .sh-card[data-pf-match="0"]{display:none !important}
</style>
@endpush

@push('script')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var ymmRoot = document.getElementById('shYmm');
    if (!ymmRoot) { return; } // not a carparts store (or markup absent) — no-op

    var OPTIONS_URL = @json(route('storefront.fitment.options', $store->slug));
    var OEM_URL = @json(route('storefront.fitment.oemSearch', $store->slug));
    var shelfEl = document.getElementById('shelf');
    var STORE_KEY = 'shelf-vehicle-' + (shelfEl ? shelfEl.getAttribute('data-store-id') : '');

    var yearSel = document.getElementById('shYmmYear');
    var makeSel = document.getElementById('shYmmMake');
    var modelSel = document.getElementById('shYmmModel');
    var subSel = document.getElementById('shYmmSubmodel');
    var engineSel = document.getElementById('shYmmEngine');
    var clearBtn = document.getElementById('shYmmClear');
    var hint = document.getElementById('shYmmHint');
    var oemInput = document.getElementById('shOemSearch');
    var grid = document.getElementById('shGrid');

    var FITMENTS = [];
    var oemMatchIds = null; // null = OEM search not active (E5)

    function uniqueSorted(values) {
        var seen = {}, out = [];
        values.forEach(function (v) {
            var key = String(v);
            if (!seen[key]) { seen[key] = true; out.push(v); }
        });
        return out.sort(function (a, b) { return a > b ? 1 : (a < b ? -1 : 0); });
    }

    function fillSelect(sel, values, placeholder) {
        var current = sel.value;
        sel.innerHTML = '';
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        sel.appendChild(opt0);
        values.forEach(function (v) {
            var opt = document.createElement('option');
            opt.value = String(v);
            opt.textContent = String(v);
            sel.appendChild(opt);
        });
        if (values.map(String).indexOf(current) !== -1) { sel.value = current; }
    }

    function fitmentsFor(filters) {
        return FITMENTS.filter(function (f) {
            if (filters.year && String(f.year) !== String(filters.year)) { return false; }
            if (filters.make && f.make !== filters.make) { return false; }
            if (filters.model && f.model !== filters.model) { return false; }
            if (filters.submodel && f.submodel !== filters.submodel) { return false; }
            if (filters.engine && f.engine !== filters.engine) { return false; }
            return true;
        });
    }

    function refreshMakes() {
        var year = yearSel.value;
        var makes = uniqueSorted(fitmentsFor({ year: year }).map(function (f) { return f.make; }));
        makeSel.disabled = !year;
        fillSelect(makeSel, year ? makes : [], @json(___('Make')));
        refreshModels();
    }

    function refreshModels() {
        var year = yearSel.value, make = makeSel.value;
        var models = uniqueSorted(fitmentsFor({ year: year, make: make }).map(function (f) { return f.model; }));
        modelSel.disabled = !(year && make);
        fillSelect(modelSel, (year && make) ? models : [], @json(___('Model')));
        refreshRefinements();
    }

    function refreshRefinements() {
        var year = yearSel.value, make = makeSel.value, model = modelSel.value;
        var scoped = fitmentsFor({ year: year, make: make, model: model });
        var subs = uniqueSorted(scoped.map(function (f) { return f.submodel; }).filter(Boolean));
        var engines = uniqueSorted(scoped.map(function (f) { return f.engine; }).filter(Boolean));
        var ready = !!(year && make && model);
        subSel.hidden = !(ready && subs.length > 0);
        engineSel.hidden = !(ready && engines.length > 0);
        fillSelect(subSel, subs, @json(___('Submodel')));
        fillSelect(engineSel, engines, @json(___('Engine')));
        applyVehicleFilter();
    }

    function currentSelection() {
        return {
            year: yearSel.value, make: makeSel.value, model: modelSel.value,
            submodel: subSel.hidden ? '' : subSel.value,
            engine: engineSel.hidden ? '' : engineSel.value
        };
    }

    function saveSelection(sel) {
        try { window.localStorage.setItem(STORE_KEY, JSON.stringify(sel)); } catch (e) { /* best-effort */ }
    }

    function matchingMenuIds(sel) {
        if (!sel.year || !sel.make || !sel.model) { return null; }
        var ids = {};
        fitmentsFor(sel).forEach(function (f) { ids[String(f.menu_id)] = true; });
        return ids;
    }

    function clearBadges() {
        var badges = document.querySelectorAll('.sh-fit-badge');
        for (var i = 0; i < badges.length; i++) { badges[i].parentNode.removeChild(badges[i]); }
    }

    function applyPfVisibility() {
        var ymmIds = matchingMenuIds(currentSelection());
        var active = !!ymmIds || !!oemMatchIds;
        clearBadges();
        if (grid) {
            var cards = grid.querySelectorAll('.sh-card');
            for (var i = 0; i < cards.length; i++) {
                var card = cards[i];
                var id = card.getAttribute('data-id');
                var okYmm = !ymmIds || !!ymmIds[id];
                var okOem = !oemMatchIds || !!oemMatchIds[id];
                var ok = okYmm && okOem;
                card.setAttribute('data-pf-match', ok ? '1' : '0');
                if (ok && ymmIds) {
                    var wrap = card.querySelector('.sh-card__imgwrap');
                    if (wrap) {
                        var badge = document.createElement('span');
                        badge.className = 'sh-fit-badge';
                        badge.textContent = @json(___('Fits your vehicle'));
                        wrap.appendChild(badge);
                    }
                }
            }
            grid.classList.toggle('sh-pf-active', active);
        }

        if (ymmIds) {
            var count = Object.keys(ymmIds).length;
            hint.textContent = count > 0
                ? @json(___(':count compatible part(s) found.')).replace(':count', String(count))
                : @json(___('No parts found for this vehicle yet.'));
            hint.className = 'sh-ymm__hint ' + (count > 0 ? 'is-match' : 'is-nomatch');
        } else {
            hint.textContent = @json(___('Select your vehicle to find compatible parts.'));
            hint.className = 'sh-ymm__hint';
        }
        clearBtn.hidden = !(yearSel.value || makeSel.value || modelSel.value);
    }

    function applyVehicleFilter() {
        saveSelection(currentSelection());
        applyPfVisibility();
    }

    yearSel.addEventListener('change', refreshMakes);
    makeSel.addEventListener('change', refreshModels);
    modelSel.addEventListener('change', refreshRefinements);
    subSel.addEventListener('change', applyVehicleFilter);
    engineSel.addEventListener('change', applyVehicleFilter);
    clearBtn.addEventListener('click', function () {
        yearSel.value = '';
        refreshMakes();
        try { window.localStorage.removeItem(STORE_KEY); } catch (e) { /* best-effort */ }
    });

    // E5: OEM/cross-reference number search — a SEPARATE match set combined
    // (AND) with the YMM selection above in applyPfVisibility(), so typing an
    // OEM number narrows within the currently-picked vehicle instead of
    // replacing it.
    var oemTimer = null;
    if (oemInput) {
        oemInput.addEventListener('input', function () {
            var q = oemInput.value.trim();
            if (oemTimer) { clearTimeout(oemTimer); }
            if (!q) { oemMatchIds = null; applyPfVisibility(); return; }
            oemTimer = setTimeout(function () {
                fetch(OEM_URL + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var ids = {};
                        ((data && data.menu_ids) || []).forEach(function (id) { ids[String(id)] = true; });
                        oemMatchIds = ids;
                        applyPfVisibility();
                    })
                    .catch(function () { /* best-effort — search degrades to no-op */ });
            }, 250);
        });
    }

    fetch(OPTIONS_URL, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.enabled) { return; }
            FITMENTS = data.fitments || [];
            var years = uniqueSorted(FITMENTS.map(function (f) { return f.year; }))
                .sort(function (a, b) { return b - a; });
            fillSelect(yearSel, years, @json(___('Year')));

            var restored = null;
            try {
                var raw = window.localStorage.getItem(STORE_KEY);
                if (raw) { restored = JSON.parse(raw); }
            } catch (e) { restored = null; }

            if (restored && restored.year) {
                yearSel.value = String(restored.year);
                refreshMakes();
                if (restored.make) { makeSel.value = restored.make; refreshModels(); }
                if (restored.model) { modelSel.value = restored.model; refreshRefinements(); }
                if (restored.submodel && !subSel.hidden) { subSel.value = restored.submodel; }
                if (restored.engine && !engineSel.hidden) { engineSel.value = restored.engine; }
                applyVehicleFilter();
            } else {
                applyPfVisibility();
            }
        })
        .catch(function () { /* best-effort — filter degrades to hidden/no-op */ });
})();
</script>
@endpush
@endif
