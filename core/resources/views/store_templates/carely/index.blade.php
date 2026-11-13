{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.carely.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalRegistry;
    use App\Services\Verticals\VerticalVocab;
    use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyComplianceService;
    use Illuminate\Support\Str;

    /* ---------------------------------------------------------------------
       carely — premium OTC pharmacy & health storefront · data preparation.

       Cloned from store_templates/shelf/index.blade.php (the pattern this pack
       was told to clone): same $vocab / category-root grouping / $items /
       $jsItems / addRoute / $checkoutRoute / $cartLineRouteTemplate /
       price_symbol_format / $modes contract, so this theme plugs into the
       exact same goods cart/checkout pipeline (Lane B/V) with zero backend
       changes. carely does NOT read/render the `fitment` (car-parts YMM)
       capability shelf carries — pharmacy never has it (config/verticals.php).

       Two carely-specific additions layered on top of the cloned contract:

         1. Batch/expiry awareness (`batch_no`/`expiry_date` — real Menu
            columns, migration 2026_09_10_640100) surfaced defensively in the
            product sheet's info rows + a light "Expiring soon" card badge.
         2. A store-ENTRY age-gate interstitial, IN ADDITION to shelf's own
            inline OTC disclaimer banner and per-ITEM age-restricted gate
            (both kept byte-for-byte the same mechanism/copy as shelf) — all
            three gate on the exact same `age_gate` VerticalRegistry
            capability; never a parallel setting/service.

       D3 reminder (see PharmacyOtcGuardrailTest): OTC-ONLY. No prescription
       upload, no pharmacist review/chat, no Rx badge/flag anywhere below —
       this theme only ever renders `age_restricted` (a real, non-Rx, minimum-
       age-to-purchase flag — e.g. a cough syrup with a sedating antihistamine)
       and `batch_no`/`expiry_date`.
       --------------------------------------------------------------------- */
    $vocabSvc = app(VerticalVocab::class);
    $vocab    = $vocabSvc->map($store->store_type ?? null);

    // F3 — OTC compliance disclaimer (PharmacyComplianceService), gated on the
    // SAME `age_gate` capability the entry interstitial below uses. REUSED
    // verbatim from shelf/index.blade.php — not a parallel mechanism. Drives
    // BOTH the persistent inline banner and the one-time entry interstitial.
    $showOtcDisclaimer = app(VerticalRegistry::class)->has($store->store_type ?? null, 'age_gate');
    $otcDisclaimerText = $showOtcDisclaimer ? app(PharmacyComplianceService::class)->disclaimerText($store) : null;
    // The inline banner (above) is the always-on compliance floor whenever the
    // vertical is age_gate-capable. The blocking store-ENTRY interstitial is the
    // owner-toggleable surface on top of it — on by default, off when the owner
    // turns off the pop-up in the Compliance settings panel.
    $showEntryGate = $showOtcDisclaimer && app(PharmacyComplianceService::class)->entryGateEnabled($store);

    $items = collect($total_menus)->values();

    // Two-tier-free category nav: top-level categories that own at least one
    // descendant item (own item OR a subcategory's item) — same "root"
    // mapping shelf/crave/aurora use, so nested categories still group
    // correctly under one tile/chip.
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

    // Best-effort category -> icon mapping for the home screen's tile grid.
    // Store categories are real, owner-typed data (never hardcoded nouns —
    // e.g. PharmacyDemoSeeder ships "Pain Relief", "Vitamins & Supplements",
    // "First Aid", "Cough & Cold"), so this is keyword matching against a
    // curated pharmacy vocabulary, NOT a fixed category list — any store's
    // own category names still render as their own tiles regardless of
    // match. Deliberately no 'rx'/'prescription' keyword: D3 is OTC-only, so
    // even a store that mislabels a category "Prescription" gets the plain
    // pill icon here, never a dedicated Rx tile treatment.
    $catIcon = function (string $name): string {
        $n = Str::lower($name);
        if (Str::contains($n, ['pain', 'ache', 'fever', 'headache'])) {
            return 'pill';
        }
        if (Str::contains($n, ['vitamin', 'supplement', 'mineral'])) {
            return 'leaf';
        }
        if (Str::contains($n, ['cold', 'flu', 'cough', 'sinus', 'allerg', 'throat'])) {
            return 'flu';
        }
        if (Str::contains($n, ['first aid', 'wound', 'bandage', 'antiseptic', 'injury'])) {
            return 'cross';
        }
        if (Str::contains($n, ['personal', 'hygiene', 'skin', 'sanitiz', 'care'])) {
            return 'drop';
        }
        if (Str::contains($n, ['baby', 'infant', 'child', 'kids'])) {
            return 'baby';
        }
        if (Str::contains($n, ['device', 'equipment', 'monitor', 'thermomet', 'mobility'])) {
            return 'device';
        }

        return 'pill';
    };

    // Icons: an SVG sprite (partials.icon-sprite, printed once in the page) + partials.icon per use — nothing raw.

    // Greeting — a small, calm, time-of-day touch (design signature: "clean,
    // reassuring, clinical"). Server-rendered off the request clock, never a
    // client `new Date()` (would flash the wrong greeting while the page loads).
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? ___('Good morning') : ($hour < 18 ? ___('Good afternoon') : ___('Good evening'));

    /* Defensive attribute readers — identical contract to shelf's own (Lane A
       brand/weight/dimensions/attributes may not be merged for every store). */
    $attrsOf = \Juwri\Core\Support\ViewHelpers::attrsOf(...);
    $brandOf = \Juwri\Core\Support\ViewHelpers::brandOf(...);
    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelAxes(...);

    // "Out of stock / :count left / In stock" — only when the item actually
    // tracks inventory AND a count is known (defensive, same as shelf).
    $stockBadge = \Juwri\Core\Support\ViewHelpers::stockBadge(...);

    // P1 currency contract — identical to shelf's (price_symbol_format() /
    // the client CUR block below honour the same symbol/side/gap/decimals).
    $curSignRaw = $postOptions->currency_sign ?? config('settings.currency_sign', '$');
    $curSign = cur_isolate($curSignRaw);
    $curGap = currency_symbol_gap($curSignRaw);
    $curPos = (string) currency_pos_effective($store->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);

    // Storefront language switch — self-contained (mirrors lumiere's $lmLanguages).
    $clLanguages = collect($menu_languages ?? [])
        ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
        ->values();

    // Real tax contract (Order::computeTax) — exposed to the client cart
    // preview so its "Total" row matches the store's ACTUAL charge instead of
    // a fabricated "Delivery"/"Service fee" line (pharmacy's own fulfillment
    // modes — ship/pickup_slot/in_store, config/verticals.php — never carry a
    // delivery charge: Order::getTotalAttribute() only adds one for the
    // literal 'delivery' type, which pharmacy stores never use, and this
    // codebase has no separate "service fee" concept anywhere). See BUILD
    // NOTES at the bottom of this file for the full rationale.
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
                'barcode' => $v->barcode ?? null,
                'stockQty' => isset($v->stock_qty) ? (int) $v->stock_qty : null,
            ];
        }

        $attrs = $attrsOf($m);
        $root = $rootOf[$m->category_id] ?? $m->category_id;

        // F2 — batch/expiry (real Menu columns, migration 2026_09_10_640100).
        $expiryDate = $m->expiry_date ?? null;

        // Gallery: primary image first, then every extra gallery image, all as
        // public URLs, deduped. Never the food-themed default.png. Drives the
        // item sheet's thumbnail strip (only rendered when >= 2 images).
        $gallery = [];
        $primaryUrl = ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '';
        if ($primaryUrl !== '') {
            $gallery[] = $primaryUrl;
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
            // Only a REAL uploaded image — never the food-themed default.png.
            'img' => ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '',
            'gallery' => $gallery,
            'price' => (float) $m->price,
            'priceFormatted' => price_symbol_format($m->price),
            'brand' => $brandOf($m),
            'weight' => $m->weight ?? null,
            'dimensions' => $m->dimensions ?? null,
            'attrs' => $attrs,
            'sku' => $m->plu ?? $m->external_sku ?? null,
            'soldOut' => (bool) $m->is_sold_out,
            'trackInventory' => (bool) ($m->track_inventory ?? false),
            'stockQty' => isset($m->stock_qty) ? (int) $m->stock_qty : null,
            'stockBadge' => $stockBadge((bool) ($m->track_inventory ?? false), isset($m->stock_qty) ? (int) $m->stock_qty : null),
            // Per-ITEM age gate (e.g. a cough syrup with a minimum-age-to-
            // purchase rule) — the exact same `age_restricted` flag/mechanism
            // shelf's own product sheet already gates on. NOT a prescription
            // flag (D3 — see PharmacyOtcGuardrailTest).
            'ageRestricted' => (bool) ($m->age_restricted ?? false),
            'batchNo' => $m->batch_no ?? null,
            'expiryDate' => $expiryDate ? date_formating($expiryDate, 'M d, Y') : null,
            'expiringSoon' => (bool) ($expiryDate && ! $m->is_expired && $m->isExpiringWithin(30)),
            'variants' => $variants,
            // Native goods cart endpoint (Lane B) for THIS item.
            'addRoute' => route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $m->id]),
        ];
    }

    $checkoutRoute = route('storefront.checkout.place', ['slug' => $store->slug]);
    // Route "template" for a dynamic cart-line id (999999999 placeholder swapped client-side).
    $cartLineRouteTemplate = route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => 999999999]);

    /* ---------------------------------------------------------------------
       Curated core features (loop/enrich): stories, reviews, loyalty.
       All three reuse existing, already-seeded/live platform endpoints —
       no backend changes made here. Health-appropriate copy only, no
       medical claims (same D3/OTC-only guardrail as the rest of this file).
       --------------------------------------------------------------------- */
    // Stories — public, ungated feed for this store.
    $storiesUrl = url('r/'.$store->id.'/stories');

    // Reviews — public list/write base; menu id appended client-side.
    $mbrBase = url('r/'.$store->id.'/item');

    // Loyalty — teaser is public (no auth); balance/redeem need auth:customer.
    $loy = store_options($store->id, 'loyalty_teaser');
    $loy = $loy ? (array) json_decode(json_encode($loy), true) : null;
    $loyaltyBalanceUrl = url('restaurant/'.$store->id.'/loyalty/balance');

    // Crave-parity features (same backend data; no controller change).
    $clIsOpen = store_open_now($store);
    $clHoursToday = storefront_hours_today($store);
    $clBranches = collect($branches ?? []);
    $clShowBranches = $clBranches->count() > 1 && (($postOptions->allow_multi_branch_switch ?? 1) != '0');
    $clActiveBranch = $activeBranch ?? null;
    $clBanners = \Juwri\Core\Support\ViewHelpers::activeBanners($menuBanners ?? []);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    .cl-sprite{position:absolute;width:0;height:0;overflow:hidden}
    :root{
        --cl-bg:#EDF6F1; --cl-surface:#FFFFFF; --cl-surface-2:#F1F7F3; --cl-ink:#16211C; --cl-muted:#566A60;
        --cl-line:#E4EFE9; --cl-line-2:#D5E5DC; --cl-accent:{{ $store->color ?: '#1F9D74' }}; --cl-accent-2:#14805E;
        --cl-accent-ink:#FFFFFF; --cl-accent-bg:#DDF2E8; --cl-warn:#B4620C; --cl-warn-bg:#FBEBD6;
        --cl-danger:#C0322A; --cl-danger-ink:#C0322A; --cl-ok:#14805E; --cl-radius:16px; --cl-radius-lg:22px;
        --cl-shadow-card:0 10px 28px -18px rgba(20,32,26,.22);
        --cl-font:"Saudi Riyal","Poppins",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --cl-bg:#0C1512; --cl-surface:#141E19; --cl-surface-2:#1C2822; --cl-ink:#EAF5EF; --cl-muted:#9DB2A8;
            --cl-line:#242E29; --cl-line-2:#2F3B34; --cl-accent-2:#2FAE82; --cl-accent-bg:#153025;
            --cl-warn:#E3A75C; --cl-warn-bg:#2B2313; --cl-shadow-card:0 14px 32px -18px rgba(0,0,0,.6);
            --cl-ok:#2FAE82; --cl-danger-ink:#F07A72;
        }
    }
    :root[data-theme="dark"]{
        --cl-bg:#0C1512; --cl-surface:#141E19; --cl-surface-2:#1C2822; --cl-ink:#EAF5EF; --cl-muted:#9DB2A8;
        --cl-line:#242E29; --cl-line-2:#2F3B34; --cl-accent-2:#2FAE82; --cl-accent-bg:#153025;
        --cl-warn:#E3A75C; --cl-warn-bg:#2B2313; --cl-shadow-card:0 14px 32px -18px rgba(0,0,0,.6);
            --cl-ok:#2FAE82; --cl-danger-ink:#F07A72;
    }
    .carely-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:20}
    .carely-skip:focus{inset-inline-start:0}
    *{box-sizing:border-box}
    .carely-body{margin:0;background:var(--cl-bg);color:var(--cl-ink);font-family:var(--cl-font);-webkit-font-smoothing:antialiased}
    .cl{--cl-pad:16px;max-width:1180px;margin:0 auto;padding:0 var(--cl-pad) 100px;overflow-x:clip}
    .cl-head{position:sticky;top:0;z-index:6;display:flex;align-items:center;gap:10px;
        padding:12px 16px;background:var(--cl-surface);border-block-end:1px solid var(--cl-line)}
    .cl-brand{display:flex;align-items:center;gap:10px;min-width:0;flex:1 1 auto}
    .cl-logo{width:38px;height:38px;border-radius:11px;object-fit:cover;flex:0 0 auto;background:var(--cl-line)}
    .cl-title{font-weight:600;font-size:1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cl-iconbtn{border:1px solid var(--cl-line);background:var(--cl-surface);color:inherit;cursor:pointer;width:42px;height:42px;
        border-radius:50%;display:inline-flex;align-items:center;justify-content:center;position:relative;flex:0 0 auto}
    .cl-iconbtn:hover{background:var(--cl-surface-2)}
    .cl-iconbtn svg{width:19px;height:19px}
    .cl-langsel{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--cl-line);border-radius:10px;padding:6px 10px;color:var(--cl-muted);background:var(--cl-surface)}
    .cl-langsel svg{width:16px;height:16px}
    .cl-langsel select{border:0;background:transparent;color:var(--cl-ink);font:inherit;font-size:.8rem;font-weight:600;cursor:pointer}
    .cl-langsel select:focus{outline:none}
    .cl-badge{position:absolute;top:-3px;inset-inline-end:-3px;background:var(--cl-accent);color:var(--cl-accent-ink);
        font-size:.62rem;font-weight:700;min-width:17px;height:17px;border-radius:999px;display:flex;
        align-items:center;justify-content:center;padding:0 3px}
    .cl-badge[hidden]{display:none}

    .cl-hello{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:18px 0 4px}
    .cl-hello__greet{font-weight:600;font-size:1.15rem;letter-spacing:-.01em}
    .cl-hello__sub{font-size:.84rem;color:var(--cl-muted);margin-block-start:2px}

    .cl-disclaimer{margin-block-start:10px;padding:11px 14px;border-radius:12px;font-size:.8rem;line-height:1.45;
        color:var(--cl-accent-2);background:var(--cl-accent-bg);border:1px solid var(--cl-line-2)}

    /* crave-parity: status row + branch + banners */
    .cl-statusrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:8px 0 4px}
    .cl-openchip{display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;color:var(--cl-ok);
        background:var(--cl-surface);border:1px solid var(--cl-line);border-radius:100px;padding:6px 12px}
    .cl-openchip i{width:7px;height:7px;border-radius:50%;background:var(--cl-ok);flex:none}
    .cl-openchip.is-closed{color:var(--cl-danger-ink)} .cl-openchip.is-closed i{background:#d9534f}
    .cl-branchsel{position:relative;display:inline-flex;align-items:center;height:32px;border-radius:100px;
        border:1px solid var(--cl-line);background:var(--cl-surface);color:var(--cl-ink);cursor:pointer}
    .cl-branchsel svg{position:absolute;inset-inline-start:10px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--cl-accent);pointer-events:none}
    .cl-branchsel::after{content:"";position:absolute;inset-inline-end:11px;top:50%;width:6px;height:6px;border-inline-end:1.5px solid var(--cl-muted);border-bottom:1.5px solid var(--cl-muted);transform:translateY(-65%) rotate(45deg);pointer-events:none}
    .cl-branchsel select{-webkit-appearance:none;appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-size:.8rem;font-weight:600;cursor:pointer;outline:none;height:100%;padding-inline-start:30px;padding-inline-end:24px;max-width:170px}
    /* Edge-to-edge carousel: bleeds to the screen edges (negative gutter) while
       every other section keeps the --cl-pad side gutter. Inner padding keeps
       the first/last banner aligned to the gutter. Reset to in-flow at desktop
       (>=1024) where the page becomes a rail grid. */
    .cl-banners{margin:10px calc(-1 * var(--cl-pad)) 4px;padding-inline:var(--cl-pad)}
    .cl-banner-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
    .cl-banner-track::-webkit-scrollbar{height:0}
    .cl-banner{flex:0 0 86%;scroll-snap-align:center;position:relative;min-height:100px;aspect-ratio:5/2;border-radius:18px;overflow:hidden;display:flex;align-items:center;padding:18px 20px;text-decoration:none;background:var(--cl-accent);color:var(--cl-accent-ink)}
    .cl-banner:nth-child(even){background:var(--cl-accent-2)}
    .cl-banner-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.9}
    .cl-banner-copy{position:relative;display:flex;flex-direction:column;gap:3px;z-index:1}
    .cl-banner-kick{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;opacity:.85}
    .cl-banner-title{font-size:1.15rem;font-weight:700;line-height:1.15}
    .cl-banner-dots{display:flex;gap:6px;justify-content:center;margin-top:9px}
    .cl-banner-dots i{width:6px;height:6px;border-radius:50%;background:var(--cl-line);transition:width .2s,background .2s}
    .cl-banner-dots i.on{background:var(--cl-accent);width:16px;border-radius:100px}

    .cl-search-wrap{padding:14px 0 4px}
    .cl-search{width:100%;height:50px;padding:0 18px;border-radius:999px;border:1px solid var(--cl-line);
        background:var(--cl-surface);color:inherit;font-size:.92rem;display:flex;align-items:center;gap:10px}
    .cl-search input{border:0;background:transparent;color:inherit;font:inherit;flex:1;min-width:0;outline:none}
    .cl-search input::placeholder{color:var(--cl-muted)}
    .cl-search:focus-within{outline:2px solid var(--cl-accent);outline-offset:1px}
    .cl-search svg{width:18px;height:18px;color:var(--cl-muted);flex:0 0 auto}

    .cl-label{font-size:.82rem;font-weight:600;letter-spacing:.02em;margin:18px 0 10px;color:var(--cl-ink)}

    .cl-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(78px,1fr));gap:10px}
    .cl-tile{display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 4px;background:var(--cl-surface);
        border:1px solid var(--cl-line);border-radius:16px;cursor:pointer;color:inherit;text-align:center}
    .cl-tile:hover{background:var(--cl-surface-2)}
    .cl-tile.is-active{border-color:var(--cl-accent);background:var(--cl-accent-bg)}
    .cl-tile__ico{width:44px;height:44px;border-radius:50%;background:var(--cl-accent-bg);color:var(--cl-accent-2);
        display:flex;align-items:center;justify-content:center;flex:0 0 auto}
    .cl-tile__ico svg{width:21px;height:21px}
    .cl-tile__label{font-size:.72rem;font-weight:500;line-height:1.25;
        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:clip}

    .cl-chips{display:flex;gap:8px;overflow-x:auto;overflow-y:clip;padding:14px 0 4px;scrollbar-width:thin}
    .cl-chip{flex:0 0 auto;border:1px solid var(--cl-line);background:var(--cl-surface);color:inherit;
        padding:8px 16px;border-radius:999px;font-size:.84rem;font-weight:500;cursor:pointer;white-space:nowrap}
    .cl-chip.is-active{background:var(--cl-ink);border-color:var(--cl-ink);color:var(--cl-bg);font-weight:600}

    .cl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:14px;padding-block-start:4px}
    .cl-card{background:var(--cl-surface);border:1px solid var(--cl-line);border-radius:18px;
        overflow:clip;display:flex;flex-direction:column;text-align:start;box-shadow:var(--cl-shadow-card)}
    .cl-card__imgwrap{aspect-ratio:1/1;background:var(--cl-surface-2);overflow:clip;position:relative;padding:14px}
    .cl-card__img{width:100%;height:100%;object-fit:contain;display:block}
    .cl-card__img--ph{display:flex;align-items:center;justify-content:center;color:var(--cl-muted);background:var(--cl-surface-2)}
    .cl-card__soldout{position:absolute;inset-block-start:8px;inset-inline-start:8px;background:var(--cl-danger);
        color:#fff;font-size:.64rem;font-weight:700;padding:3px 9px;border-radius:999px;z-index:2}
    .cl-card__expsoon{position:absolute;inset-block-start:8px;inset-inline-start:8px;background:var(--cl-warn);
        color:#1a1305;font-size:.64rem;font-weight:700;padding:3px 9px;border-radius:999px;z-index:2}
    .cl-card__media-link{display:block;width:100%;height:100%}
    .cl-card__media-link:focus-visible{outline:2px solid var(--cl-accent);outline-offset:-2px}
    .cl-fav-btn{position:absolute;inset-block-start:6px;inset-inline-end:6px;width:30px;height:30px;border-radius:50%;
        border:0;padding:0;cursor:pointer;display:flex;align-items:center;justify-content:center;
        background:color-mix(in srgb, var(--cl-surface) 82%, transparent);color:var(--cl-muted);z-index:2}
    .cl-fav-btn svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2}
    .cl-fav-btn.is-fav{color:var(--cl-danger-ink)}
    .cl-fav-btn.is-fav svg{fill:currentColor}
    .cl-card__body{padding:11px 13px 13px;display:flex;flex-direction:column;gap:4px;flex:1}
    .cl-card__brand{font-size:.68rem;color:var(--cl-muted);text-transform:uppercase;letter-spacing:.04em}
    .cl-card__name{font-weight:600;font-size:.9rem;line-height:1.3;
        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:clip;min-height:2.4em}
    .cl-stock{font-size:.68rem;font-weight:600;margin-block-start:2px}
    .cl-stock--low{color:var(--cl-warn)}
    .cl-stock--in{color:var(--cl-ok)}
    .cl-stock--out{color:var(--cl-danger-ink)}
    .cl-card__foot{margin-block-start:auto;display:flex;align-items:center;justify-content:space-between;gap:6px;padding-block-start:8px}
    .cl-price{font-weight:700;font-size:.94rem;color:var(--cl-accent-2);font-variant-numeric:tabular-nums}
    .cl-add{border:0;background:var(--cl-accent);color:var(--cl-accent-ink);border-radius:999px;
        width:30px;height:30px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;cursor:pointer}
    .cl-add svg{width:15px;height:15px}
    .cl-add--wide{width:auto;height:auto;border-radius:10px;padding:7px 12px;font-size:.8rem;font-weight:600}
    .cl-add:disabled{opacity:.45;cursor:not-allowed}
    .cl-empty{padding:56px 10px;text-align:center;color:var(--cl-muted)}

    .cl-trust{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin:22px 0 4px;
        padding:14px;background:var(--cl-surface-2);border-radius:14px;font-size:.78rem;color:var(--cl-muted);font-weight:500}
    .cl-trust span{display:inline-flex;align-items:center;gap:6px}
    .cl-trust svg{width:15px;height:15px;color:var(--cl-accent-2);flex:0 0 auto}
    .cl-trust .cl-tdiv{color:var(--cl-line-2)}

    /* -------- sheets (product / cart-checkout) -------- */
    .cl-sheet-scrim{position:fixed;inset:0;background:rgba(10,14,12,.5);z-index:9;opacity:0;pointer-events:none;transition:opacity .18s ease}
    .cl-sheet-scrim.is-open{opacity:1;pointer-events:auto}
    .cl-sheet{position:fixed;inset-block:0;inset-inline-end:0;width:min(430px,100%);background:var(--cl-bg);
        color:inherit;z-index:10;transform:translateX(100%);transition:transform .22s ease;display:flex;flex-direction:column;
        box-shadow:-8px 0 30px rgba(0,0,0,.25)}
    [dir="rtl"] .cl-sheet{transform:translateX(-100%)}
    .cl-sheet.is-open{transform:translateX(0)}
    .cl-sheet__head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;
        border-block-end:1px solid var(--cl-line);background:var(--cl-surface)}
    .cl-sheet__title{font-weight:600}
    .cl-sheet__body{flex:1;overflow-y:auto;overflow-x:clip;padding:16px}
    .cl-sheet__foot{padding:14px 16px;border-block-start:1px solid var(--cl-line);background:var(--cl-surface)}
    .cl-detail-img{width:100%;aspect-ratio:1/1;object-fit:contain;border-radius:var(--cl-radius-lg);
        background:var(--cl-surface-2);margin-block-end:14px;padding:20px}

    /* F2 — item gallery thumbnail strip (only shown when >= 2 images). */
    .cl-thumbs{display:flex;gap:8px;margin-block-end:14px;overflow-x:auto;overflow-y:clip;-webkit-overflow-scrolling:touch;padding-block-end:2px}
    .cl-thumbs::-webkit-scrollbar{height:0}
    .cl-thumb{flex:0 0 auto;width:60px;height:60px;border-radius:12px;overflow:clip;border:2px solid transparent;
        padding:4px;background:var(--cl-surface-2);cursor:pointer}
    .cl-thumb img{width:100%;height:100%;object-fit:contain;display:block}
    .cl-thumb[aria-current="true"]{border-color:var(--cl-accent)}

    .cl-group{margin-block-end:16px}
    .cl-group__label{font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--cl-muted);margin-block-end:8px}
    .cl-opts{display:flex;flex-wrap:wrap;gap:8px}
    .cl-opt{border:1px solid var(--cl-line);background:var(--cl-surface);color:inherit;border-radius:10px;
        padding:8px 13px;font-size:.84rem;cursor:pointer}
    .cl-opt.is-active{border-color:var(--cl-accent);background:var(--cl-accent-bg);font-weight:600;color:var(--cl-accent-2)}
    .cl-opt:disabled{opacity:.4;text-decoration:line-through;cursor:not-allowed}

    .cl-inforow{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-block-end:1px solid var(--cl-line)}
    .cl-inforow:last-child{border-block-end:none}
    .cl-inforow__ico{width:36px;height:36px;border-radius:10px;background:var(--cl-surface-2);color:var(--cl-accent-2);
        display:flex;align-items:center;justify-content:center;flex:0 0 auto}
    .cl-inforow__ico svg{width:17px;height:17px}
    .cl-inforow__label{font-size:.68rem;color:var(--cl-muted);text-transform:uppercase;letter-spacing:.04em;font-weight:600}
    .cl-inforow__value{font-size:.86rem;font-weight:500;margin-block-start:2px}
    .cl-inforow__value--warn{color:var(--cl-warn)}

    .cl-agegate{border:1px solid var(--cl-danger);background:color-mix(in srgb, var(--cl-danger) 8%, transparent);
        border-radius:12px;padding:12px;font-size:.84rem;margin-block-end:14px}
    .cl-agegate__actions{display:flex;gap:8px;margin-block-start:10px}
    .cl-agegate__actions .cl-btn{flex:1}

    .cl-btn{border:0;border-radius:999px;padding:13px 16px;font-size:.9rem;font-weight:600;cursor:pointer}
    .cl-btn--primary{background:var(--cl-accent);color:var(--cl-accent-ink)}
    .cl-btn--ghost{background:transparent;border:1px solid var(--cl-line-2);color:inherit}
    .cl-btn:disabled{opacity:.5;cursor:not-allowed}

    .cl-qty{display:flex;align-items:center;gap:12px}
    .cl-qty button{width:32px;height:32px;border-radius:9px;border:1px solid var(--cl-line-2);background:var(--cl-surface);color:inherit;cursor:pointer;font-size:1.1rem}
    .cl-qty span{min-width:20px;text-align:center;font-weight:600;font-variant-numeric:tabular-nums}

    .cl-cart-line{display:flex;gap:12px;padding:12px 0;border-block-end:1px solid var(--cl-line)}
    .cl-cart-line img{width:58px;height:58px;border-radius:12px;object-fit:contain;background:var(--cl-surface-2);flex:0 0 auto;padding:6px}
    .cl-cart-line__body{flex:1;min-width:0}
    .cl-cart-line__name{font-weight:600;font-size:.88rem}
    .cl-cart-line__price{font-weight:700;font-size:.86rem;margin-block-start:2px}
    .cl-cart-line__remove{background:transparent;border:0;color:var(--cl-danger-ink);cursor:pointer;font-size:.78rem;padding:0;font-weight:600}

    .cl-nb-proxy{position:fixed;top:0;inset-inline-start:0;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;opacity:0;pointer-events:none;border:0}
    .cl-notif-dot{position:absolute;top:6px;inset-inline-end:6px;width:8px;height:8px;border-radius:50%;background:var(--cl-accent);border:2px solid var(--cl-surface);box-sizing:content-box}
    .cl-notif-dot[hidden]{display:none}
    .mb-nb-card{--nb-surface:var(--cl-surface);--nb-surface-2:var(--cl-surface-2);--nb-ink:var(--cl-ink);--nb-muted:var(--cl-muted);--nb-line:var(--cl-line);--nb-accent:var(--cl-accent);--nb-radius:16px}
    .carely .mb-nb-panel{opacity:0}
    .carely .mb-nb-panel.is-open{opacity:1}
    .cl-orders-h{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--cl-muted);margin:20px 0 10px}
    .cl-order{border:1px solid var(--cl-line);border-radius:12px;padding:13px 15px;margin-bottom:10px}
    .cl-order-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .cl-order-status{font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:100px;background:var(--cl-surface-2);color:var(--cl-ink)}
    .cl-order-meta{font-size:.78rem;color:var(--cl-muted);margin-top:6px}
    .cl-order-track{display:inline-block;margin-top:10px;font-size:.82rem;font-weight:600;color:var(--cl-accent);text-decoration:none}
    .cl-order-actions{display:flex;gap:8px;margin-top:12px}
    .cl-order-btn{flex:1;text-align:center;height:38px;line-height:38px;border-radius:999px;font-size:.82rem;font-weight:600;text-decoration:none;color:var(--cl-ink);background:var(--cl-surface);border:1px solid var(--cl-line);cursor:pointer}
    .cl-order-btn:hover{background:var(--cl-surface-2)}
    .cl-order-btn--p{background:var(--cl-accent);color:var(--cl-accent-ink);border-color:var(--cl-accent)}
    .cl-orders-empty{font-size:.82rem;color:var(--cl-muted);padding:4px 0}
    .cl-couponbox{margin-block:8px 4px}
    .cl-couponrow{display:flex;gap:8px}
    .cl-coupon-input{flex:1;height:46px;border-radius:12px;border:1px solid var(--cl-line);background:var(--cl-surface);padding:0 14px;font:inherit;font-size:.9rem;color:var(--cl-ink)}
    .cl-coupon-apply{flex:none}
    .cl-coupon-msg{font-size:.78rem;font-weight:600;margin:7px 0 0;color:var(--cl-ok)}
    .cl-coupon-msg.is-bad{color:#d9534f}
    .cl-summary{margin-block-start:6px;padding-block-start:12px}
    .cl-srow{display:flex;justify-content:space-between;font-size:.86rem;color:var(--cl-muted);padding:5px 0}
    .cl-srow--total{color:var(--cl-ink);font-weight:700;font-size:1rem;border-block-start:1px dashed var(--cl-line);
        margin-block-start:8px;padding-block-start:12px}

    .cl-field{display:block;width:100%;margin-block-end:12px}
    .cl-field--tight{margin-block-end:0}
    .cl-field label{display:block;font-size:.78rem;color:var(--cl-muted);margin-block-end:5px;font-weight:600}
    .cl-field input,.cl-field select{width:100%;padding:11px 13px;border-radius:12px;border:1px solid var(--cl-line-2);
        background:var(--cl-surface);color:inherit;font-size:.9rem;font-family:inherit}
    /* Delivery-address autocomplete (crave-style geocode suggest) */
    .cl-addr{position:relative}
    .cl-addr input{padding-inline-end:42px}
    .cl-addr-loc{position:absolute;inset-inline-end:6px;top:6px;width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;
        border:0;background:transparent;color:var(--cl-muted);cursor:pointer;border-radius:9px}
    .cl-addr-loc:hover{background:var(--cl-surface-2);color:var(--cl-accent)}
    .cl-addr-loc.is-loading{opacity:.5;pointer-events:none}
    .cl-addr-loc svg{width:19px;height:19px}
    .cl-addr-suggest{position:absolute;inset-inline:0;top:calc(100% + 5px);z-index:40;background:var(--cl-surface);
        border:1px solid var(--cl-line);border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,.14);overflow:hidden;max-height:248px;overflow-y:auto}
    .cl-addr-suggest .mb-addr-opt{display:block;width:100%;text-align:start;padding:11px 14px;background:none;border:0;
        border-block-end:1px solid var(--cl-line);color:var(--cl-ink);font:inherit;font-size:.84rem;cursor:pointer;line-height:1.4}
    .cl-addr-suggest .mb-addr-opt:last-child{border-block-end:0}
    .cl-addr-suggest .mb-addr-opt:hover{background:var(--cl-surface-2)}
    .cl-msg{font-size:.84rem;padding:9px 12px;border-radius:10px;margin-block-end:12px}
    .cl-msg--error{background:color-mix(in srgb, var(--cl-danger) 12%, transparent);color:var(--cl-danger-ink)}
    .cl-msg--ok{background:var(--cl-accent-bg);color:var(--cl-accent-2)}
    .cl-slots{display:flex;flex-wrap:wrap;gap:8px;margin-block:6px}

    /* -------- entry age-gate interstitial -------- */
    .cl-gate{position:fixed;inset:0;z-index:50;background:var(--cl-bg);display:flex;align-items:center;justify-content:center;padding:24px}
    .cl-gate[hidden]{display:none}
    .cl-gate__card{max-width:420px;width:100%;background:var(--cl-surface);border:1px solid var(--cl-line);
        border-radius:var(--cl-radius-lg);padding:28px 24px;text-align:center;box-shadow:var(--cl-shadow-card);
        display:flex;flex-direction:column;align-items:center}
    .cl-gate__ico{width:60px;height:60px;border-radius:50%;background:var(--cl-accent-bg);color:var(--cl-accent-2);
        display:flex;align-items:center;justify-content:center;margin-block-end:16px}
    .cl-gate__ico svg{width:28px;height:28px}
    .cl-gate__title{font-weight:700;font-size:1.2rem;margin:0 0 10px}
    .cl-gate__text{font-size:.88rem;line-height:1.55;color:var(--cl-muted);margin:0 0 20px}
    .cl-gate__card .cl-btn{width:100%}

    /* -------- bottom tabbar (small screens) --------
       Root-level sibling of the header (see markup): a fixed bottom nav for the
       core actions, so the top bar is not crowded with icon buttons on a ~390px
       phone. The page already reserves 100px of bottom padding (.cl) to clear
       it. Hidden at the desktop breakpoint, where the header + rail take over. */
    .cl-tabbar{position:fixed;inset-inline:0;inset-block-end:0;z-index:8;display:flex;justify-content:space-around;
        align-items:stretch;background:var(--cl-surface);border-block-start:1px solid var(--cl-line);
        padding-block-end:env(safe-area-inset-bottom,0px)}
    .cl-tabbtn{position:relative;flex:1 1 0;border:0;background:none;color:var(--cl-muted);cursor:pointer;
        display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:9px 4px 8px;
        font:inherit;font-size:.64rem;font-weight:600}
    .cl-tabbtn svg{width:22px;height:22px}
    .cl-tabbtn[aria-current="true"]{color:var(--cl-accent-2)}
    .cl-tabbtn .cl-badge{top:2px;inset-inline-end:calc(50% - 22px)}

    /* Saved / account / cart move into the bottom tabbar on small screens, so
       hide their duplicate top-bar icons there (re-shown at the desktop bp). */
    .cl-headfold{display:none}

    [hidden]{display:none !important}

    /* ============================================================
       F1 — stories rail (bubble rail, below the compliance banner) +
       full-screen story viewer. Ungated public feed; hidden entirely
       when the feed is empty (JS toggles [hidden] on .cl-stories).
       ============================================================ */
    .cl-stories{display:flex;gap:14px;overflow-x:auto;overflow-y:clip;padding:4px 2px 12px;scrollbar-width:thin}
    .cl-story{flex:0 0 auto;display:flex;flex-direction:column;align-items:center;gap:6px;width:72px;
        background:transparent;border:0;padding:0;cursor:pointer;color:inherit;text-align:center;font:inherit}
    .cl-story__ring{width:64px;height:64px;border-radius:50%;padding:2.5px;flex:0 0 auto;
        background:linear-gradient(135deg,var(--cl-accent),var(--cl-accent-2));display:flex;align-items:center;justify-content:center}
    .cl-story.is-viewed .cl-story__ring{background:var(--cl-line-2)}
    .cl-story__thumb{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;
        border:2px solid var(--cl-bg);background:var(--cl-surface-2)}
    .cl-story__label{font-size:.68rem;font-weight:500;max-width:100%;overflow:clip;text-overflow:ellipsis;white-space:nowrap}

    /* Visibility via .is-open (not [hidden]) — the shared sheet a11y helper
       (openA11ySheet/dismissA11ySheet) only ever toggles that class + the
       aria-hidden/inert pair, exactly like every .cl-sheet, so Escape and
       the browser Back button close the viewer the same way they close the
       item/cart/account/saved sheets. */
    .cl-story-viewer{position:fixed;inset:0;z-index:60;background:#000;display:none;flex-direction:column}
    .cl-story-viewer.is-open{display:flex}
    .cl-story-viewer__progress{display:flex;gap:4px;padding:12px 12px 0;flex:0 0 auto}
    .cl-story-viewer__bar{flex:1;height:3px;border-radius:999px;background:rgba(255,255,255,.3);overflow:clip}
    .cl-story-viewer__bar-fill{display:block;height:100%;background:#fff;inline-size:0}
    .cl-story-viewer__bar.is-done .cl-story-viewer__bar-fill{inline-size:100%}
    .cl-story-viewer__bar.is-active .cl-story-viewer__bar-fill{transition:inline-size linear}
    .cl-story-viewer__head{display:flex;align-items:center;justify-content:flex-end;padding:10px 10px 0;flex:0 0 auto}
    .cl-story-viewer__close{border:0;background:rgba(255,255,255,.16);color:#fff;width:36px;height:36px;border-radius:50%;
        display:flex;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto}
    .cl-story-viewer__close svg{width:16px;height:16px}
    .cl-story-viewer__stage{position:relative;flex:1;display:flex;align-items:center;justify-content:center;overflow:clip;min-height:0}
    .cl-story-viewer__media{max-width:100%;max-height:100%;object-fit:contain}
    .cl-story-viewer__zone{position:absolute;inset-block:0;width:50%;cursor:pointer;background:transparent;border:0;padding:0}
    .cl-story-viewer__zone--prev{inset-inline-start:0}
    .cl-story-viewer__zone--next{inset-inline-end:0}
    .cl-story-viewer__foot{position:absolute;inset-inline:0;inset-block-end:0;padding:18px 18px calc(18px + env(safe-area-inset-bottom,0px));
        background:linear-gradient(to top,rgba(0,0,0,.75),transparent);color:#fff;display:flex;flex-direction:column;gap:12px;pointer-events:none}
    .cl-story-viewer__caption{font-size:.92rem;line-height:1.5;margin:0;text-shadow:0 1px 3px rgba(0,0,0,.5)}
    .cl-story-viewer__cta{align-self:flex-start;border:0;border-radius:999px;padding:11px 20px;font-weight:700;font-size:.86rem;
        background:#fff;color:#14211c;cursor:pointer;text-decoration:none;display:inline-block;pointer-events:auto}

    /* ============================================================
       F2 — reviews (average/distribution/list/write), rendered inside
       the product detail sheet. Endpoint: r/{store}/item/{menu}/reviews.
       ============================================================ */
    .cl-rv-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-block-end:10px}
    .cl-rv-avg{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
    .cl-rv-avg b{font-size:1.5rem;font-weight:700}
    .cl-rv-stars{color:var(--cl-warn);letter-spacing:1px;font-size:.92rem;white-space:nowrap}
    .cl-rv-stars .is-off{color:var(--cl-line-2)}
    .cl-rv-count{font-size:.8rem;color:var(--cl-muted)}
    .cl-rv-write-btn{border:1px solid var(--cl-line-2);background:var(--cl-surface);color:var(--cl-accent-2);
        border-radius:999px;padding:8px 14px;font-size:.8rem;font-weight:700;cursor:pointer;flex:0 0 auto}
    .cl-rv-dist{display:grid;gap:5px;margin-block-end:16px}
    .cl-rv-dist-row{display:grid;grid-template-columns:1.1em 1fr 1.6em;align-items:center;gap:8px;font-size:.74rem;color:var(--cl-muted)}
    .cl-rv-dist-row i{display:block;height:6px;border-radius:999px;background:var(--cl-line);overflow:clip}
    .cl-rv-dist-row i s{display:block;height:100%;background:var(--cl-warn);text-decoration:none}
    .cl-rv-list{display:flex;flex-direction:column;gap:14px;margin-block-end:6px}
    .cl-rv-item{padding-block-end:14px;border-block-end:1px solid var(--cl-line)}
    .cl-rv-item:last-child{border-block-end:0;padding-block-end:0}
    .cl-rv-item__top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-block-end:4px;flex-wrap:wrap}
    .cl-rv-item__name{font-weight:600;font-size:.86rem}
    .cl-rv-item__date{font-size:.72rem;color:var(--cl-muted)}
    .cl-rv-item__body{font-size:.86rem;line-height:1.5;color:var(--cl-ink);margin:4px 0 0;overflow-wrap:anywhere}
    .cl-rv-verified{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:700;color:var(--cl-accent-2);margin-inline-start:6px}
    .cl-rv-empty{padding:14px 0 18px;text-align:center;color:var(--cl-muted);font-size:.86rem}
    .cl-rv-form{display:none;gap:10px;margin-block-start:8px;padding-block-start:14px;border-block-start:1px solid var(--cl-line)}
    .cl-rv-form.is-open{display:grid}
    .cl-rv-starpicker{display:flex;gap:6px}
    .cl-rv-starpicker button{border:0;background:none;font-size:1.6rem;line-height:1;padding:0;cursor:pointer;color:var(--cl-line-2)}
    .cl-rv-starpicker button.is-on{color:var(--cl-warn)}

    /* ============================================================
       F3 — loyalty: Rewards card (account sheet) + checkout redeem row.
       Teaser is public; balance/redeem need auth:customer (guest → hint).
       ============================================================ */
    .cl-rewards-card{background:linear-gradient(135deg,var(--cl-accent-bg),var(--cl-surface));
        border:1px solid var(--cl-line-2);border-radius:var(--cl-radius-lg);padding:20px;margin-block-end:16px}
    .cl-rewards-card__top{display:flex;align-items:center;gap:12px;margin-block-end:4px}
    .cl-rewards-card__ico{width:44px;height:44px;border-radius:50%;background:var(--cl-surface);color:var(--cl-accent-2);
        display:flex;align-items:center;justify-content:center;flex:0 0 auto}
    .cl-rewards-card__ico svg{width:21px;height:21px}
    .cl-rewards-card__program{font-weight:700;font-size:1rem}
    .cl-rewards-card__line{font-size:.82rem;color:var(--cl-muted);margin-block-start:2px}
    .cl-rewards-card__balance{font-size:1.6rem;font-weight:700;margin:12px 0 0;color:var(--cl-accent-2)}
    .cl-rewards-card__balance small{font-size:.72rem;font-weight:600;color:var(--cl-muted)}
    .cl-tierchips{display:flex;gap:8px;margin-block-start:14px;flex-wrap:wrap}
    .cl-tierchip{flex:1 1 0;min-width:80px;text-align:center;padding:8px 6px;border-radius:11px;font-size:.72rem;font-weight:700;
        background:var(--cl-surface);border:1px solid var(--cl-line-2);color:var(--cl-muted)}
    .cl-tierchip.is-current{background:var(--cl-accent);border-color:var(--cl-accent);color:var(--cl-accent-ink)}
    .cl-rewards-signin{font-size:.82rem;color:var(--cl-accent-2);margin-block-start:12px;display:flex;align-items:center;gap:6px}
    .cl-rewards-signin svg{width:16px;height:16px;flex:0 0 auto}
    .cl-rewards-progress{height:6px;border-radius:999px;background:var(--cl-line);margin-block-start:14px;overflow:clip}
    .cl-rewards-progress s{display:block;height:100%;background:var(--cl-accent);text-decoration:none}

    .cl-loyalty-row{border:1px dashed var(--cl-line-2);border-radius:12px;padding:12px 14px;margin-block-end:14px;font-size:.84rem}
    .cl-loyalty-row__teaser{color:var(--cl-muted);line-height:1.5}
    .cl-loyalty-row__signin{color:var(--cl-accent-2);font-weight:500;margin-block-start:4px}
    .cl-loyalty-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer;
        background:none;border:0;width:100%;padding:0;color:inherit;font:inherit;text-align:start}
    .cl-loyalty-toggle__label{font-weight:600}
    .cl-loyalty-toggle__switch{width:40px;height:24px;border-radius:999px;background:var(--cl-line-2);position:relative;flex:0 0 auto;transition:background .15s ease}
    .cl-loyalty-toggle__switch::after{content:'';position:absolute;inset-block-start:3px;inset-inline-start:3px;width:18px;height:18px;
        border-radius:50%;background:#fff;transition:inset-inline-start .15s ease;box-shadow:0 1px 3px rgba(0,0,0,.25)}
    .cl-loyalty-toggle.is-on .cl-loyalty-toggle__switch{background:var(--cl-accent)}
    .cl-loyalty-toggle.is-on .cl-loyalty-toggle__switch::after{inset-inline-start:19px}

    /* ============================================================
       F4 — wishlist "Saved" sheet (reuses .cl-sheet). F5 account
       header icon reuses the same sheet shell for the Rewards card.
       ============================================================ */
    .cl-saved-line{display:flex;gap:12px;padding:12px 0;border-block-end:1px solid var(--cl-line);align-items:center}
    .cl-saved-line:last-child{border-block-end:0}
    .cl-saved-line img{width:58px;height:58px;border-radius:12px;object-fit:contain;background:var(--cl-surface-2);flex:0 0 auto;padding:6px}
    .cl-saved-line__body{flex:1;min-width:0}
    .cl-saved-line__name{font-weight:600;font-size:.88rem}
    .cl-saved-line__price{font-weight:700;font-size:.86rem;margin-block-start:2px;color:var(--cl-accent-2)}
    .cl-saved-line__actions{display:flex;flex-direction:column;gap:6px;flex:0 0 auto;align-items:stretch}
    .cl-saved-line__actions button{white-space:nowrap;font-size:.76rem;padding:7px 12px}

    /* ============================================================
       DESKTOP COMPOSITION (tablet >=768 / desktop >=1024 / wide >=1280)
       Additive only — mobile (<768) is byte-for-byte unchanged. New
       elements below default to display:none / display:contents (a
       no-op at the sizes above) and are only revealed inside min-width
       media queries. RTL: CSS Grid/Flexbox `row`/column tracks are
       direction-aware (the theme sets `dir` on <html>), so the rail,
       sheet columns and cart columns mirror automatically for Arabic
       — no [dir="rtl"] overrides needed here. Every color below reuses
       an existing --cl-* token, so both dark-mode blocks above already
       cover every new surface. See RESPONSIVE-SPEC.md.
       ============================================================ */
    .cl-hero,.cl-sidebar,.cl-head-search{display:none}
    .cl-head-inner{display:contents}

    @media (min-width:768px){
        .cl-tiles{grid-template-columns:repeat(auto-fill,minmax(112px,1fr))}
        .cl-grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px}
        .cl{padding-inline:24px}
    }

    @media (min-width:1024px){
        .cl{padding-inline:32px}

        /* ---- sticky top nav: centered inner row + a search box ---- */
        .cl-head{padding-inline:24px}
        .cl-head-inner{display:flex;align-items:center;gap:18px;width:100%;max-width:1180px;margin-inline:auto}
        .cl-head-search{display:flex;flex:1 1 420px;max-width:460px}
        .cl-head-search .cl-search{height:42px}

        /* ---- hero band: greeting/search/reassurance, full page width ---- */
        .cl-hero{display:block;grid-column:1/-1;grid-row:1;background:var(--cl-surface);
            border:1px solid var(--cl-line);border-radius:var(--cl-radius-lg);padding:32px 36px;
            margin-block-end:20px;box-shadow:var(--cl-shadow-card)}
        .cl-hero__inner{display:flex;align-items:center;justify-content:space-between;gap:36px}
        .cl-hero__content{flex:1 1 auto;max-width:560px}
        .cl-hero__title{font-size:1.7rem;font-weight:700;letter-spacing:-.01em;margin:0 0 6px}
        .cl-hero__sub{font-size:.94rem;color:var(--cl-muted);margin:0 0 18px}
        .cl-search.cl-hero__search{height:54px;max-width:420px}
        .cl-hero__note{display:flex;align-items:flex-start;gap:8px;margin:16px 0 0;font-size:.82rem;
            color:var(--cl-accent-2);line-height:1.5}
        .cl-hero__note svg{width:17px;height:17px;flex:0 0 auto;margin-block-start:1px}
        .cl-hero__trust{display:flex;gap:18px;flex-wrap:wrap;margin:16px 0 0;font-size:.82rem;
            color:var(--cl-muted);font-weight:500}
        .cl-hero__trust span{display:inline-flex;align-items:center;gap:6px}
        .cl-hero__trust svg{width:16px;height:16px;color:var(--cl-accent-2)}
        .cl-hero__visual{flex:0 0 auto;width:140px;height:140px;border-radius:50%;
            background:var(--cl-accent-bg);color:var(--cl-accent-2);display:flex;align-items:center;
            justify-content:center}
        .cl-hero__visual svg{width:62px;height:62px}

        /* ---- category rail replaces the mobile tile grid + chip row ---- */
        .cl-cathead,.cl-tiles,.cl-chips,.cl-search-wrap,.cl-hello{display:none}

        /* Desktop uses the full header (+ search) and side rail, so the mobile
           bottom tabbar is hidden and the header's action icons all reappear. */
        .cl-tabbar{display:none}
        .cl-headfold{display:inline-flex}
        .cl-banners{margin-inline:0;padding-inline:0}
        .cl.cl--has-rail{display:grid;grid-template-columns:240px 1fr;column-gap:32px;align-items:start}
        .cl-hello,.cl-disclaimer,.cl-stories,.cl-label,.cl-grid,.cl-empty,.cl-trust,.cl-statusrow,.cl-banners{grid-column:2}
        .cl.cl--has-rail .cl-sidebar{grid-column:1;grid-row:2 / span 20}

        .cl-sidebar{display:block;position:sticky;top:84px;align-self:start;background:var(--cl-surface);
            border:1px solid var(--cl-line);border-radius:var(--cl-radius-lg);padding:16px 12px;
            max-height:calc(100vh - 104px);overflow-y:auto}
        .cl-sidebar__title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;
            color:var(--cl-muted);margin:2px 0 10px;padding-inline:8px}
        .cl-sidebar .cl-chip{display:flex;align-items:center;gap:10px;width:100%;border-radius:12px;
            padding:10px;text-align:start;white-space:normal;background:transparent;border:1px solid transparent;
            margin-block-end:2px;font-weight:500;color:inherit}
        .cl-sidebar .cl-chip:hover{background:var(--cl-surface-2)}
        .cl-sidebar .cl-chip.is-active{background:var(--cl-accent-bg);border-color:var(--cl-line-2);
            color:var(--cl-accent-2);font-weight:600}
        .cl-sidebar__ico{width:28px;height:28px;border-radius:50%;background:var(--cl-surface-2);
            color:var(--cl-accent-2);display:flex;align-items:center;justify-content:center;flex:0 0 auto}
        .cl-sidebar__ico svg{width:14px;height:14px}
        .cl-sidebar .cl-chip.is-active .cl-sidebar__ico{background:var(--cl-surface)}

        /* ---- generous, capped product grid (was auto-fill minmax(165px)) ---- */
        .cl-grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px}

        /* ---- item detail + cart drawers: wider, image/summary become a
           second column instead of one narrow stacked panel. carely has no
           separate product-detail/checkout page/route — both are slide-in
           sheets on this same screen — so "2-column" is applied inside the
           existing sheet rather than fabricating a new page. ---- */
        #clItemSheet{width:min(760px,92vw)}
        #clItemSheet .cl-sheet__body{display:grid;grid-template-columns:280px 1fr;column-gap:28px;
            align-items:start}
        /* one cell per column so the gallery can't push the buy-box down and the
           thumbs stay under the image (col1), not in the buy-box column */
        #clItemSheet .cl-sheet__body > #clItemGallery{grid-column:1;position:sticky;top:0}
        #clItemSheet .cl-sheet__body > #clItemBuyBox{grid-column:2;min-width:0}

        #clCartSheet{width:min(560px,92vw)}
        #clCartView{display:grid;grid-template-columns:1fr 250px;column-gap:24px;align-items:start}
        #clCartLines,#clCartEmpty{grid-column:1}
        #clCartSummary{grid-column:2;position:sticky;top:0;background:var(--cl-surface-2);
            border-radius:var(--cl-radius);padding:14px 16px}

        #clCheckoutForm{display:grid;grid-template-columns:1fr 1fr;column-gap:16px}
        #clSlotField,#clCheckoutMsg,#clLoyaltyRow,#clPlaceOrder{grid-column:1/-1}
    }

    @media (min-width:1280px){
        .cl{max-width:1320px}
        .cl-grid{grid-template-columns:repeat(auto-fill,minmax(212px,1fr))}
    }
</style>
@endpush

@section('content')
<div class="carely" id="carely" data-store-id="{{ $store->id }}" data-theme-slug="carely">
@include('store_templates.carely.partials.icon-sprite')

    @if($showEntryGate)
        {{-- Store-ENTRY age-gate interstitial. Same `age_gate` capability +
             `$otcDisclaimerText` as shelf's inline banner below — not a
             parallel mechanism, just a second (one-time, per-session)
             surface for it. Blocking (inert on the rest of the page) until
             confirmed once; a returning visitor in the SAME browser session
             does not see it again (sessionStorage), exactly like the
             per-item age gate further down. --}}
        <div class="cl-gate" id="clGate" role="dialog" aria-modal="true" aria-labelledby="clGateTitle" hidden>
            <div class="cl-gate__card">
                <span class="cl-gate__ico">@include('store_templates.carely.partials.icon', ['n' => 'shield'])</span>
                <h2 class="cl-gate__title" id="clGateTitle">{{ ___('Before you continue') }}</h2>
                <p class="cl-gate__text">{{ $otcDisclaimerText }}</p>
                <button type="button" class="cl-btn cl-btn--primary" id="clGateContinue">{{ ___('Continue') }}</button>
            </div>
        </div>
    @endif

    <header class="cl-head">
        {{-- .cl-head-inner is `display:contents` below the desktop breakpoint,
             so wrapping these existing children changes nothing about mobile
             rendering/flex order — it only becomes a real (centered, capped)
             flex row at >=1024px so the sticky bar's content lines up with
             the page content below it. The new search box is desktop-only
             (hidden below 1024 via CSS), mirroring the mobile search further
             down so a scrolled-past-the-hero visitor can still search. --}}
        <div class="cl-head-inner">
            <div class="cl-brand">
                @if($store->main_image && $store->main_image !== 'default.png')
                    @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img class="cl-logo" src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="">@endif
                @endif
                <span class="cl-title">{{ $store->title }}</span>
            </div>
            <div class="cl-head-search">
                <label class="cl-search">
                    @include('store_templates.carely.partials.icon', ['n' => 'search'])
                    <input type="search" id="clSearchDesktop" autocomplete="off"
                           placeholder="{{ ___('Search medicines & health') }}"
                           aria-label="{{ ___('Search medicines & health') }}">
                </label>
            </div>
            @if($clLanguages->count() > 1)
                <label class="cl-langsel" aria-label="{{ ___('Language') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
                    <select id="clLangSel">
                        @foreach($clLanguages as $lang)
                            <option value="{{ $lang->code }}" data-url="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}" @if(get_lang() === $lang->code) selected @endif>{{ strtoupper($lang->code) }}</option>
                        @endforeach
                    </select>
                </label>
                <script nonce="{{ csp_nonce() }}">
                    (function(){var s=document.getElementById('clLangSel');if(!s)return;s.addEventListener('change',function(e){var o=e.target.selectedOptions[0];if(!o)return;var code=o.value;try{document.cookie='Quick_user_lang_code='+code+';path=/;max-age=31536000;samesite=lax';document.cookie='qc_lang_choice='+code+';path=/;max-age=31536000;samesite=lax';}catch(err){}window.location.assign(o.getAttribute('data-url')||window.location.href);});})();
                </script>
            @endif
            <button type="button" class="cl-iconbtn cl-notifbell" id="clNotifBell" aria-label="{{ ___('Notifications') }}" title="{{ ___('Notifications') }}" style="position:relative">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                <i class="cl-notif-dot" hidden aria-hidden="true"></i>
            </button>
            <button type="button" class="cl-iconbtn" id="clShareBtn" aria-label="{{ ___('Share this store') }}" title="{{ ___('Share this store') }}" data-share-url="{{ url()->current() }}" data-share-title="{{ $store->title }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
            </button>
            <button type="button" class="cl-iconbtn cl-headfold" id="clSavedOpen" aria-label="{{ ___('Saved items') }}" title="{{ ___('Saved items') }}">
                @include('store_templates.carely.partials.icon', ['n' => 'heart'])
                <i class="cl-badge" id="clSavedBadge" hidden>0</i>
            </button>
            <button type="button" class="cl-iconbtn cl-headfold" id="clAccountOpen" aria-label="{{ ___('Account') }}" title="{{ ___('Account') }}">
                @include('store_templates.carely.partials.icon', ['n' => 'user'])
            </button>
            <button type="button" class="cl-iconbtn" id="clDarkToggle" aria-label="{{ ___('Toggle dark mode') }}" title="{{ ___('Toggle dark mode') }}">
                @include('store_templates.carely.partials.icon', ['n' => 'moon'])
            </button>
            <button type="button" class="cl-iconbtn cl-headfold" id="clCartOpen" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}">
                @include('store_templates.carely.partials.icon', ['n' => 'bag'])
                <i class="cl-badge" id="clCartBadge" hidden>0</i>
            </button>
        </div>
    </header>

    <main class="cl @if($categories->isNotEmpty()) cl--has-rail @endif" id="carely-main">
        {{-- ============== DESKTOP ONLY (>=1024px) ==============
             Hero band (search + reassurance) and the left category rail
             that REPLACE the mobile tile grid + chip row at that
             breakpoint (hidden via CSS, see the DESKTOP COMPOSITION block
             in the style above). Purely additive: the mobile markup below
             is untouched, and the rail buttons reuse the exact `cl-chip`
             class + `data-cat` contract the existing filter/search script
             already binds to, so no JS changes were needed to keep both
             in sync. ======================================= --}}
        <div class="cl-hero">
            <div class="cl-hero__inner">
                <div class="cl-hero__content">
                    <h1 class="cl-hero__title">{{ $store->title }}</h1>
                    <p class="cl-hero__sub">{{ ___('How can we help today?') }}</p>
                    <label class="cl-search cl-hero__search">
                        @include('store_templates.carely.partials.icon', ['n' => 'search'])
                        <input type="search" id="clSearchHero" autocomplete="off"
                               placeholder="{{ ___('Search medicines & health') }}"
                               aria-label="{{ ___('Search medicines & health') }}">
                    </label>
                    @if($otcDisclaimerText)
                        <p class="cl-hero__note">@include('store_templates.carely.partials.icon', ['n' => 'shield'])<span>{{ $otcDisclaimerText }}</span></p>
                    @else
                        <div class="cl-hero__trust">
                            <span>@include('store_templates.carely.partials.icon', ['n' => 'shield']){{ ___('Licensed pharmacy') }}</span>
                            <span>@include('store_templates.carely.partials.icon', ['n' => 'truck']){{ ___('Fast delivery') }}</span>
                        </div>
                    @endif
                </div>
                <div class="cl-hero__visual" aria-hidden="true">@include('store_templates.carely.partials.icon', ['n' => 'shield'])</div>
            </div>
        </div>

        @if($categories->isNotEmpty())
            <aside class="cl-sidebar" role="tablist" aria-label="{{ $vocab['categories'] ?? ___('Categories') }}">
                <div class="cl-sidebar__title">{{ $vocab['categories'] ?? ___('Categories') }}</div>
                <button type="button" class="cl-chip is-active" role="tab" aria-selected="true" aria-controls="clGrid" data-cat="all">
                    <span class="cl-sidebar__ico">@include('store_templates.carely.partials.icon', ['n' => 'box'])</span>{{ ___('All') }}
                </button>
                @foreach($categories as $cat)
                    <button type="button" class="cl-chip" role="tab" aria-selected="false" aria-controls="clGrid" data-cat="{{ $cat->id }}">
                        <span class="cl-sidebar__ico">@include('store_templates.carely.partials.icon', ['n' => $catIcon($cat->name)])</span>{{ $cat->name }}
                    </button>
                @endforeach
            </aside>
        @endif

        <div class="cl-hello">
            <div>
                <div class="cl-hello__greet">{{ $greeting }}</div>
                <div class="cl-hello__sub">{{ ___('How can we help today?') }}</div>
            </div>
        </div>

        {{-- Crave-parity status row: Open-Now chip + (multi-store) branch switch. --}}
        <div class="cl-statusrow">
            <span class="cl-openchip @if(! $clIsOpen) is-closed @endif" data-mb-openchip data-mb-closed-class="is-closed">
                <i aria-hidden="true"></i><span data-mb-open-text>{{ $clIsOpen ? ___('Open now') : ___('Closed') }}@if($clHoursToday) · {{ $clHoursToday }}@endif</span>
            </span>
            @if($clShowBranches)
                <label class="cl-branchsel" aria-label="{{ ___('Branch') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    <select id="clBranchSel">
                        @foreach($clBranches as $branch)
                            <option value="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}" @if(($clActiveBranch->id ?? null) === $branch->id) selected @endif>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>

        @if($clBanners->count())
            <div class="cl-banners" id="clBanners">
                <div class="cl-banner-track" id="clBannerTrack">
                    @foreach($clBanners as $b)
                        <a class="cl-banner" @if($b->link_url) href="{{ storefront_localized_link($b->link_url) }}" target="_blank" rel="noopener" @else href="#" data-banner-cta @endif>
                            @if($b->image)<img class="cl-banner-img" src="{{ $b->image_url ?? media_url('storage/menu_banners/', $b->image, $store->id ?? null) }}" alt="" data-csp-error="hide">@endif
                            <span class="cl-banner-copy">
                                @if($b->subtitle)<span class="cl-banner-kick">{{ $b->subtitle }}</span>@endif
                                <span class="cl-banner-title">{{ $b->title }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
                @if($clBanners->count() > 1)<div class="cl-banner-dots" id="clBannerDots" aria-hidden="true"></div>@endif
            </div>
        @endif

        @if($showOtcDisclaimer)
            {{-- Persistent inline OTC disclaimer — shelf's EXACT existing
                 mechanism (PharmacyComplianceService::disclaimerText), kept
                 visible on every visit (unlike the one-time interstitial
                 above) so the "not medical advice" notice is always in view
                 while browsing. --}}
            <div class="cl-disclaimer" role="note">{{ $otcDisclaimerText }}</div>
        @endif

        {{-- F1 — stories rail. Always rendered AFTER both the entry age-gate
             interstitial (.cl-gate, blocking overlay) and the persistent
             .cl-disclaimer banner above, per the OTC compliance requirement
             (never above either). Starts [hidden]; JS fetches the public
             feed and only reveals it when there is at least one story. --}}
        <div class="cl-stories" id="clStories" role="list" aria-label="{{ ___('Stories') }}" hidden></div>

        <div class="cl-search-wrap">
            <label class="cl-search">
                @include('store_templates.carely.partials.icon', ['n' => 'search'])
                <input type="search" id="clSearch" autocomplete="off"
                       placeholder="{{ ___('Search medicines & health') }}"
                       aria-label="{{ ___('Search medicines & health') }}">
            </label>
        </div>

        @if($categories->isNotEmpty())
            <div class="cl-label cl-cathead">{{ $vocab['categories'] ?? ___('Categories') }}</div>
            <div class="cl-tiles" id="clTiles">
                @foreach($categories as $cat)
                    <button type="button" class="cl-tile" data-tile-cat="{{ $cat->id }}">
                        <span class="cl-tile__ico">@include('store_templates.carely.partials.icon', ['n' => $catIcon($cat->name)])</span>
                        <span class="cl-tile__label">{{ $cat->name }}</span>
                    </button>
                @endforeach
            </div>

            <nav class="cl-chips" id="clChips" role="tablist" aria-label="{{ $vocab['categories'] ?? ___('Categories') }}">
                <button type="button" class="cl-chip is-active" role="tab" aria-selected="true" aria-controls="clGrid" data-cat="all">{{ ___('All') }}</button>
                @foreach($categories as $cat)
                    <button type="button" class="cl-chip" role="tab" aria-selected="false" aria-controls="clGrid" data-cat="{{ $cat->id }}">{{ $cat->name }}</button>
                @endforeach
            </nav>
        @endif

        <div class="cl-label">{{ ___('Popular products') }}</div>

        @if($items->isEmpty())
            <p class="cl-empty">{{ ___('Nothing here yet.') }}</p>
        @else
            <div class="cl-grid" id="clGrid">
                @foreach($items as $m)
                    @php $jd = $jsItems[$m->id]; @endphp
                    <article class="cl-card" data-id="{{ $m->id }}" data-root="{{ $jd['root'] }}" data-name="{{ Str::lower($jd['name']) }}" data-desc="{{ Str::lower($jd['desc']) }}">
                        <div class="cl-card__imgwrap">
                            @if($jd['soldOut'])
                                <span class="cl-card__soldout">{{ ___('Sold out') }}</span>
                            @elseif($jd['expiringSoon'])
                                <span class="cl-card__expsoon">{{ ___('Expiring soon') }}</span>
                            @endif
                            <a href="?item={{ $m->id }}" class="cl-card__media-link" data-open-item-link="{{ $m->id }}" aria-label="{{ $jd['name'] }}">
                                @if($jd['img'])
                                    @if((string) ($jd['img']) !== '')<img class="cl-card__img" src="{{ $jd['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
                                @else
                                    <div class="cl-card__img cl-card__img--ph" role="img" aria-label="{{ $jd['name'] }}">
                                        @include('store_templates.carely.partials.icon', ['n' => 'pill'])
                                    </div>
                                @endif
                            </a>
                            <button type="button" class="cl-fav-btn" data-fav-toggle="{{ $m->id }}" aria-pressed="false" aria-label="{{ ___('Add to favorites') }}">
                                <svg viewBox="0 0 24 24"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                            </button>
                        </div>
                        <div class="cl-card__body">
                            @if($jd['brand'])
                                <span class="cl-card__brand">{{ $jd['brand'] }}</span>
                            @endif
                            <span class="cl-card__name">{{ $jd['name'] }}</span>
                            @if($jd['stockBadge'] && ! $jd['soldOut'])
                                <span class="cl-stock cl-stock--{{ $jd['stockBadge']['type'] }}">{{ $jd['stockBadge']['text'] }}</span>
                            @endif
                            <div class="cl-card__foot">
                                <span class="cl-price" data-role="price">{{ $jd['priceFormatted'] }}</span>
                                <button type="button" class="cl-add @if(count($jd['variants']) > 1) cl-add--wide @endif" data-open-item="{{ $m->id }}" @if($jd['soldOut']) disabled @endif
                                        aria-label="{{ count($jd['variants']) > 1 ? ___('Choose') : ($vocab['add_to_cart'] ?? ___('Add to cart')) }}">
                                    @if(count($jd['variants']) > 1)
                                        {{ ___('Choose') }}
                                    @else
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                    @endif
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
            <p class="cl-empty" id="clNoResults" hidden>{{ ___('No results.') }}</p>
        @endif

        <div class="cl-trust">
            <span>@include('store_templates.carely.partials.icon', ['n' => 'shield']){{ ___('Licensed pharmacy') }}</span>
            <span class="cl-tdiv">&bull;</span>
            <span>@include('store_templates.carely.partials.icon', ['n' => 'truck']){{ ___('Fast delivery') }}</span>
        </div>
    </main>

    {{-- Bottom tabbar — root-level sibling of the header (NOT nested inside it),
         so its position:fixed is anchored to the viewport regardless of any
         header stacking/backdrop context. Small screens only (hidden at the
         desktop breakpoint). Buttons drive the SAME actions as the header
         icons / search, keyed off carely's existing controls. --}}
    <nav class="cl-tabbar" id="clTabbar" aria-label="{{ ___('Main') }}">
        <button type="button" class="cl-tabbtn" data-tab="home" aria-current="true" aria-label="{{ ___('Home') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11l8-7 8 7"/><path d="M6 10v9h12v-9"/></svg>
            <span>{{ ___('Home') }}</span>
        </button>
        <button type="button" class="cl-tabbtn" data-tab="search" aria-label="{{ ___('Search') }}">
            @include('store_templates.carely.partials.icon', ['n' => 'search'])
            <span>{{ ___('Search') }}</span>
        </button>
        <button type="button" class="cl-tabbtn" data-tab="saved" aria-label="{{ ___('Saved items') }}">
            @include('store_templates.carely.partials.icon', ['n' => 'heart'])
            <i class="cl-badge" id="clSavedBadgeTab" hidden>0</i>
            <span>{{ ___('Saved') }}</span>
        </button>
        <button type="button" class="cl-tabbtn" data-tab="cart" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}">
            @include('store_templates.carely.partials.icon', ['n' => 'bag'])
            <i class="cl-badge" id="clCartBadgeTab" hidden>0</i>
            <span>{{ $vocab['orders'] ?? ___('Cart') }}</span>
        </button>
        <button type="button" class="cl-tabbtn" data-tab="account" aria-label="{{ ___('Account') }}">
            @include('store_templates.carely.partials.icon', ['n' => 'user'])
            <span>{{ ___('Account') }}</span>
        </button>
    </nav>

    {{-- Product detail sheet: variant options, info rows (dosage/usage/batch/
         expiry/brand/sku...), per-item age-gate hook, add-to-cart. NO Rx
         badge/pill anywhere in this markup (D3). --}}
    <div class="cl-sheet-scrim" id="clItemScrim"></div>
    <aside class="cl-sheet" id="clItemSheet" role="dialog" aria-modal="true" aria-label="{{ $vocab['item'] ?? ___('Product') }}" aria-hidden="true" inert>
        <div class="cl-sheet__head">
            <strong class="cl-sheet__title" id="clItemName" tabindex="-1"></strong>
            <button type="button" class="cl-iconbtn" id="clItemClose" aria-label="{{ ___('Close') }}" style="width:38px;height:38px">&times;</button>
        </div>
        <div class="cl-sheet__body">
            <div id="clItemGallery">
                <img class="cl-detail-img" id="clItemImg" alt="" data-csp-error="hide">
                {{-- F2 — gallery thumbnail strip (populated by renderThumbs; only
                     shown when the item has >= 2 images). Tapping swaps the main
                     image above. --}}
                <div class="cl-thumbs" id="clItemThumbs" hidden></div>
            </div>
            <div id="clItemBuyBox">
            <p id="clItemDesc" style="color:var(--cl-muted);font-size:.88rem;margin:0 0 12px"></p>
            <span class="cl-stock" id="clItemStock" hidden></span>

            <div class="cl-agegate" id="clAgeGate" hidden>
                <span>{{ ___('This item is age-restricted. Confirm you meet the minimum age to buy it.') }}</span>
                <div class="cl-agegate__actions">
                    <button type="button" class="cl-btn cl-btn--primary" id="clAgeConfirm">{{ ___('Confirm') }}</button>
                    <button type="button" class="cl-btn cl-btn--ghost" id="clAgeCancel">{{ ___('Cancel') }}</button>
                </div>
            </div>

            <div id="clVariantGroups"></div>

            <div class="cl-group" id="clInfoGroup" hidden>
                <div class="cl-group__label">{{ ___('Details') }}</div>
                <div id="clInfoRows"></div>
            </div>

            <div class="cl-group">
                <div class="cl-group__label">{{ ___('Quantity') }}</div>
                <div class="cl-qty">
                    <button type="button" id="clQtyMinus" aria-label="{{ ___('Decrease') }}">&minus;</button>
                    <span id="clQtyVal">1</span>
                    <button type="button" id="clQtyPlus" aria-label="{{ ___('Increase') }}">&plus;</button>
                </div>
            </div>

            {{-- F2 — reviews: average/count header, 5→1 distribution, list,
                 write-a-review form. Fetched fresh each time an item opens
                 (GET r/{store}/item/{menu}/reviews?filter=latest). --}}
            <div class="cl-group" id="clReviewsGroup" hidden>
                <div class="cl-group__label">{{ ___('Reviews') }}</div>
                <div class="cl-rv-head" id="clRvHead" hidden>
                    <div class="cl-rv-avg">
                        <b id="clRvAvgNum">0.0</b>
                        <span class="cl-rv-stars" id="clRvAvgStars" aria-hidden="true"></span>
                        <span class="cl-rv-count" id="clRvCount"></span>
                    </div>
                    <button type="button" class="cl-rv-write-btn" id="clRvWriteToggle">{{ ___('Write a review') }}</button>
                </div>
                <div class="cl-rv-dist" id="clRvDist" hidden></div>
                <div class="cl-rv-list" id="clRvList"></div>
                <p class="cl-rv-empty" id="clRvEmpty" hidden>{{ ___('No reviews yet — be the first.') }}</p>
                <form class="cl-rv-form" id="clRvForm">
                    <div class="cl-rv-starpicker" id="clRvStarPicker" role="group" aria-label="{{ ___('Your rating') }}">
                        <button type="button" data-rv-star="1" aria-label="1">★</button>
                        <button type="button" data-rv-star="2" aria-label="2">★</button>
                        <button type="button" data-rv-star="3" aria-label="3">★</button>
                        <button type="button" data-rv-star="4" aria-label="4">★</button>
                        <button type="button" data-rv-star="5" aria-label="5">★</button>
                    </div>
                    <div class="cl-field cl-field--tight">
                        <label for="clRvName">{{ ___('Your name') }} ({{ ___('Optional') }})</label>
                        <input type="text" id="clRvName" maxlength="80" autocomplete="name">
                    </div>
                    <div class="cl-field cl-field--tight">
                        <label for="clRvBody">{{ ___('Your review') }} ({{ ___('Optional') }})</label>
                        <input type="text" id="clRvBody" maxlength="2000">
                    </div>
                    <div id="clRvMsg"></div>
                    <button type="submit" class="cl-btn cl-btn--primary" id="clRvSubmit">{{ ___('Submit review') }}</button>
                </form>
            </div>
            </div>{{-- /#clItemBuyBox --}}
        </div>
        <div class="cl-sheet__foot">
            <button type="button" class="cl-btn cl-btn--primary" id="clAddToCart" style="width:100%">
                <span id="clAddToCartLabel">{{ $vocab['add_to_cart'] ?? ___('Add to cart') }}</span>
                &nbsp;&middot;&nbsp;<b id="clAddToCartPrice"></b>
            </button>
        </div>
    </aside>

    {{-- Cart / checkout sheet — posts straight to Lane B's native goods
         checkout (GoodsCheckoutController), the same store-side cart each
         item's "Add to cart" fills via its own `addRoute`. --}}
    <div class="cl-sheet-scrim" id="clCartScrim"></div>
    <aside class="cl-sheet" id="clCartSheet" role="dialog" aria-modal="true" aria-label="{{ $vocab['orders'] ?? ___('Cart') }}" aria-hidden="true" inert>
        <div class="cl-sheet__head">
            <strong class="cl-sheet__title" id="clCartTitle" tabindex="-1">{{ $vocab['orders'] ?? ___('Cart') }}</strong>
            <button type="button" class="cl-iconbtn" id="clCartClose" aria-label="{{ ___('Close') }}" style="width:38px;height:38px">&times;</button>
        </div>
        <div class="cl-sheet__body">
            <div id="clCartView">
                <div id="clCartLines"></div>
                <p class="cl-empty" id="clCartEmpty">{{ ___('Your cart is empty.') }}</p>
                <div class="cl-summary" id="clCartSummary" hidden>
                    <div class="cl-srow"><span>{{ ___('Subtotal') }}</span><span id="clCartSubtotal"></span></div>
                    <div class="cl-srow" id="clCartTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="clCartTax"></span></div>
                    <div class="cl-srow cl-srow--total"><span>{{ ___('Total') }}</span><span id="clCartTotal"></span></div>
                </div>
            </div>

            <form id="clCheckoutForm" hidden>
                <div id="clCheckoutMsg"></div>
                <div class="cl-field">
                    <label for="clName">{{ ___('Name') }}</label>
                    <input type="text" id="clName" name="name" required maxlength="255">
                </div>
                <div class="cl-field">
                    <label for="clPhone">{{ ___('Phone') }}</label>
                    <input type="tel" id="clPhone" name="phone" required maxlength="25">
                </div>
                @if($modes->count() > 1)
                <div class="cl-field">
                    <label for="clMode">{{ ___('Fulfillment') }}</label>
                    <select id="clMode" name="mode">
                        @foreach($modes as $mode)
                            <option value="{{ $mode['value'] }}" data-needs-address="{{ $mode['needsAddress'] ? 1 : 0 }}" data-needs-slot="{{ $mode['needsSlot'] ? 1 : 0 }}">{{ $mode['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="cl-field" id="clAddressField" hidden>
                    <label for="clAddress">{{ ___('Address') }}</label>
                    <div class="cl-addr">
                        <input type="text" id="clAddress" name="address" maxlength="255" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="{{ ___('Start typing your address…') }}">
                        <button type="button" class="cl-addr-loc" id="clAddrLoc" aria-label="{{ ___('Use my location') }}" title="{{ ___('Use my location') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                        </button>
                        <div class="cl-addr-suggest" id="clAddrSuggest" hidden></div>
                    </div>
                    {{-- Drop-a-pin picker (shared window.mbMapPicker engine, carely-skinned .cl-sheet drawer). --}}
                    <button type="button" class="cl-maplink" id="clMapBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.4"/></svg>
                        {{ ___('Choose on map') }}
                    </button>
                </div>
                <div class="cl-field" id="clSlotField" hidden>
                    <label>{{ ___('Pick a time') }}</label>
                    <div class="cl-slots" id="clSlots"></div>
                </div>

                {{-- F3(b) — loyalty redeem row. Balance is fetched (auth:customer)
                     the moment the checkout form opens; guests / stores without
                     an active program fall back to the public teaser + a sign-in
                     hint, never a balance. --}}
                <div class="cl-loyalty-row" id="clLoyaltyRow" hidden>
                    <div class="cl-loyalty-row__teaser" id="clLoyaltyTeaser" hidden></div>
                    <div class="cl-loyalty-row__signin" id="clLoyaltySignin" hidden>{{ ___('Sign in to earn & redeem points') }}</div>
                    <button type="button" class="cl-loyalty-toggle" id="clLoyaltyToggle" hidden aria-pressed="false">
                        <span class="cl-loyalty-toggle__label" id="clLoyaltyToggleLabel"></span>
                        <span class="cl-loyalty-toggle__switch" aria-hidden="true"></span>
                    </button>
                    <input type="hidden" id="clLoyaltyRedeemInput" name="loyalty_redeem" value="0">
                </div>

                {{-- Promo code + gift card — applied server-side via OrderPricing. --}}
                <div class="cl-couponbox">
                    <div class="cl-couponrow">
                        <input type="text" class="cl-coupon-input" id="clCouponInput" autocomplete="off" placeholder="{{ ___('Promo code') }}" aria-label="{{ ___('Promo code') }}">
                        <button type="button" class="cl-btn cl-btn--ghost cl-coupon-apply" id="clCouponApply">{{ ___('Apply') }}</button>
                    </div>
                    <p class="cl-coupon-msg" id="clCouponMsg" hidden></p>
                    <input type="hidden" name="coupon_code" id="clCouponField" value="">
                    <input type="text" class="cl-coupon-input" id="clGiftCardInput" name="gift_card_code" autocomplete="off" placeholder="{{ ___('Gift card code') }}" aria-label="{{ ___('Gift card code') }}" style="margin-top:8px">
                </div>

                <button type="button" class="cl-btn cl-btn--primary" id="clPlaceOrder" style="width:100%">
                    {{ ___('Place order') }}
                </button>
            </form>
        </div>
        <div class="cl-sheet__foot" id="clCartFoot">
            <button type="button" class="cl-btn cl-btn--primary" id="clCheckoutBtn" style="width:100%" disabled>
                {{ ___('Checkout') }}
            </button>
        </div>
    </aside>

    {{-- Address map picker — carely-skinned .cl-sheet drawer driven by the shared
         window.mbMapPicker engine. Sits above the cart drawer (opened from the
         checkout address field) via the .cl-mapsheet z-index bump below. --}}
    <div class="cl-sheet-scrim cl-mapsheet-scrim" id="clMapScrim"></div>
    <aside class="cl-sheet cl-mapsheet" id="clMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" aria-hidden="true" inert>
        <div class="cl-sheet__head">
            <strong class="cl-sheet__title" id="clMapTitle" tabindex="-1">{{ ___('Choose on map') }}</strong>
            <button type="button" class="cl-iconbtn" id="clMapClose" aria-label="{{ ___('Close') }}" style="width:38px;height:38px">&times;</button>
        </div>
        <div class="cl-sheet__body cl-mapsheet__body">
            <div class="cl-map-canvas" id="clMap"></div>
            <p class="cl-map-preview" id="clMapPreview">{{ ___('Move the map to place the pin') }}</p>
        </div>
        <div class="cl-sheet__foot">
            <button type="button" class="cl-btn cl-btn--primary" id="clMapConfirm" style="width:100%">{{ ___('Use this location') }}</button>
        </div>
    </aside>

    {{-- F5 — Account sheet: the public loyalty teaser + tier chips, enhanced
         with a real balance/progress when the visitor is a signed-in
         customer (auth:customer) — otherwise a "Sign in to earn & redeem
         points" hint, never a fabricated balance. --}}
    <div class="cl-sheet-scrim" id="clAccountScrim"></div>
    <aside class="cl-sheet" id="clAccountSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Account') }}" aria-hidden="true" inert>
        <div class="cl-sheet__head">
            <strong class="cl-sheet__title" id="clAccountTitle" tabindex="-1">{{ ___('Account') }}</strong>
            <button type="button" class="cl-iconbtn" id="clAccountClose" aria-label="{{ ___('Close') }}" style="width:38px;height:38px">&times;</button>
        </div>
        <div class="cl-sheet__body">
            @if($loy)
                <div class="cl-rewards-card">
                    <div class="cl-rewards-card__top">
                        <span class="cl-rewards-card__ico">@include('store_templates.carely.partials.icon', ['n' => 'gift'])</span>
                        <div>
                            <div class="cl-rewards-card__program">{{ $loy['program'] ?? ___('Rewards') }}</div>
                            @if(!empty($loy['earn']))
                                <div class="cl-rewards-card__line">{{ $loy['earn'] }}</div>
                            @endif
                        </div>
                    </div>
                    @if(!empty($loy['reward']))
                        <div class="cl-rewards-card__line">{{ $loy['reward'] }}</div>
                    @endif
                    <div id="clRewardsBalanceArea"></div>
                    <div class="cl-tierchips" id="clTierChips">
                        <span class="cl-tierchip" data-tier="silver">{{ ___('Silver') }}</span>
                        <span class="cl-tierchip" data-tier="gold">{{ ___('Gold') }}</span>
                        <span class="cl-tierchip" data-tier="platinum">{{ ___('Platinum') }}</span>
                    </div>
                    <div class="cl-rewards-signin" id="clRewardsSignin" hidden>@include('store_templates.carely.partials.icon', ['n' => 'user'])<span>{{ ___('Sign in to earn & redeem points') }}</span></div>
                </div>
            @else
                <p class="cl-empty">{{ ___('No rewards program yet.') }}</p>
            @endif

            {{-- Order history — publicView.myOrders (auth:customer). --}}
            <h3 class="cl-orders-h">{{ ___('My Orders') }}</h3>
            <div id="clOrdersList"></div>
            <p class="cl-orders-empty" id="clOrdersEmpty" hidden>{{ ___('No orders yet') }}</p>
            <p class="cl-orders-empty" id="clOrdersGuest" hidden>{{ ___('Sign in to see your orders') }}</p>
            @if(($postOptions->customer_auth_mode ?? '') !== 'demo')
                @if(auth('customer')->check())
                    @if(\App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
                        <button type="button" class="cl-btn cl-btn--ghost" data-mb-wallet-open style="width:100%;margin-top:10px">{{ ___('Wallet — add funds') }}</button>
                    @endif
                    <button type="button" class="cl-btn cl-btn--ghost" data-mb-logout data-logout-url="{{ route('customer.logout', $store->id) }}" style="width:100%;margin-top:10px">{{ ___('Log out') }}</button>
                @else
                    <button type="button" class="cl-btn cl-btn--primary" data-mb-auth-open style="width:100%;margin-top:10px">{{ ___('Sign in / Register') }}</button>
                @endif
            @endif

            {{-- Privacy: wipe the cart / favourites / saved addresses this browser
                 keeps (shared window.mbClearDeviceData engine). The account sheet is
                 guest-reachable (opens for everyone), so the control lives here
                 rather than in the checkout footer. --}}
            <button type="button" class="cl-btn cl-btn--ghost cl-cleardata" id="clClearData" style="width:100%;margin-top:18px">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>
                <span>{{ ___('Clear saved data on this device') }}</span>
            </button>
            <p class="cl-cleardata-hint">{{ ___('Clears your cart, favourites and saved addresses stored in this browser.') }}</p>
        </div>
    </aside>

    {{-- F4 — "Saved" wishlist sheet: lists items favorited via the card heart
         (persisted in localStorage), with a quick view/remove per row. --}}
    <div class="cl-sheet-scrim" id="clSavedScrim"></div>
    <aside class="cl-sheet" id="clSavedSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Saved items') }}" aria-hidden="true" inert>
        <div class="cl-sheet__head">
            <strong class="cl-sheet__title" id="clSavedTitle" tabindex="-1">{{ ___('Saved items') }}</strong>
            <button type="button" class="cl-iconbtn" id="clSavedClose" aria-label="{{ ___('Close') }}" style="width:38px;height:38px">&times;</button>
        </div>
        <div class="cl-sheet__body">
            <div id="clSavedLines"></div>
            <p class="cl-empty" id="clSavedEmpty" hidden>{{ ___('Nothing saved yet — tap the heart on a product to save it here.') }}</p>
        </div>
    </aside>

    {{-- F1 — full-screen story viewer (image, caption, timed progress bars,
         optional CTA, tap/swipe prev-next, close). Visibility is driven by
         the shared sheet a11y helper's `.is-open` class (see DESKTOP
         COMPOSITION note below the script) rather than [hidden], so Escape /
         browser-back close it exactly like every other sheet. --}}
    <div class="cl-story-viewer" id="clStoryViewer" role="dialog" aria-modal="true" aria-label="{{ ___('Stories') }}" aria-hidden="true" inert>
        <div class="cl-story-viewer__progress" id="clStoryProgress"></div>
        <div class="cl-story-viewer__head">
            <button type="button" class="cl-story-viewer__close" id="clStoryClose" aria-label="{{ ___('Close') }}">@include('store_templates.carely.partials.icon', ['n' => 'close'])</button>
        </div>
        <div class="cl-story-viewer__stage">
            <button type="button" class="cl-story-viewer__zone cl-story-viewer__zone--prev" id="clStoryPrev" aria-label="{{ ___('Previous') }}"></button>
            <img class="cl-story-viewer__media" id="clStoryMedia" alt="" data-csp-error="hide">
            <button type="button" class="cl-story-viewer__zone cl-story-viewer__zone--next" id="clStoryNext" aria-label="{{ ___('Next') }}"></button>
        </div>
        <div class="cl-story-viewer__foot">
            <p class="cl-story-viewer__caption" id="clStoryCaption" hidden></p>
            <a class="cl-story-viewer__cta" id="clStoryCta" href="#" hidden rel="noopener"></a>
        </div>
    </div>

    {{-- Real notification centre — header bell forwards to a hidden proxy. --}}
    <button type="button" id="mbNotifBell" class="cl-nb-proxy" aria-hidden="true" tabindex="-1"></button>
    <i id="mbNotifDot" hidden></i>
    @include('store_templates._partials.notif-bell')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var proxy = document.getElementById('mbNotifBell');
            var srcDot = document.getElementById('mbNotifDot');
            var bell = document.getElementById('clNotifBell');
            if (bell) { bell.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (proxy && window.mbNotify) { proxy.click(); } }); }
            if (srcDot) {
                var mirror = function () { var d = bell && bell.querySelector('.cl-notif-dot'); if (d) { d.hidden = srcDot.hidden; } };
                new MutationObserver(mirror).observe(srcDot, { attributes: true, attributeFilter: ['hidden'] });
                mirror();
            }
            var shareBtn = document.getElementById('clShareBtn');
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
        .mba-sheet{--mba-surface:var(--cl-surface);--mba-ink:var(--cl-ink);--mba-muted:var(--cl-muted);
            --mba-border:var(--cl-line);--mba-hair:var(--cl-line);--mba-in-bg:var(--cl-surface);
            --mba-accent:var(--cl-accent);--mba-on-accent:#fff;--mba-accent-ring:var(--cl-line);--mba-radius:16px}
    </style>
    <script nonce="{{ csp_nonce() }}">
    (function () {
        ['clRewardsSignin', 'clLoyaltySignin', 'clOrdersGuest'].forEach(function (id) {
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
    /* Address map picker + clear-saved-data (shared engines, carely-skinned) */
    .cl-maplink{display:inline-flex;align-items:center;gap:7px;margin-block-start:9px;padding:0;border:0;background:none;
        font:inherit;font-size:.82rem;font-weight:600;letter-spacing:.01em;color:var(--cl-accent);cursor:pointer}
    .cl-maplink svg{width:16px;height:16px}
    .cl-maplink:hover{text-decoration:underline;text-underline-offset:3px}
    /* Sit above the cart drawer it is opened from (cart scrim/sheet = 9/10). */
    .cl-mapsheet-scrim{z-index:11}
    .cl-mapsheet{z-index:12}
    .cl-mapsheet__body{display:flex;flex-direction:column;padding:0;overflow:hidden}
    .cl-map-canvas{position:relative;flex:1;min-height:min(52vh,360px);background:var(--cl-surface-2)}
    .cl-map-canvas .leaflet-container{width:100%;height:100%;font:inherit;background:var(--cl-surface-2)}
    .cl-map-preview{margin:0;padding:13px 16px;font-size:.82rem;line-height:1.45;color:var(--cl-muted);
        border-block-start:1px solid var(--cl-line);text-align:center;min-height:20px}
    /* Teardrop pin dropped into the map centre (styled by the theme; engine injects .mb-map-pin). */
    .mb-map-pin{position:absolute;left:50%;top:50%;width:28px;height:36px;transform:translate(-50%,-100%);
        z-index:500;pointer-events:none;filter:drop-shadow(0 4px 6px rgba(20,32,26,.4))}
    .mb-map-pin::before{content:"";position:absolute;left:50%;top:0;transform:translateX(-50%) rotate(45deg);
        width:24px;height:24px;border-radius:50% 50% 50% 0;background:var(--cl-accent);border:2px solid var(--cl-surface)}
    .mb-map-pin::after{content:"";position:absolute;left:50%;top:7px;transform:translateX(-50%);width:8px;height:8px;border-radius:50%;background:var(--cl-surface)}
    /* Clear-saved-data row: ghost button tinted with the theme's danger token + a muted hint. */
    .cl-cleardata{display:inline-flex;align-items:center;justify-content:center;gap:8px;color:var(--cl-danger-ink);border-color:var(--cl-line-2)}
    .cl-cleardata svg{width:17px;height:17px}
    .cl-cleardata-hint{margin:7px 0 0;font-size:.76rem;line-height:1.45;color:var(--cl-muted);text-align:center}
</style>
@endpush

@push('script')
{{-- Device-local guest order history (shared helper: window.mbGuestOrders). Must
     load BEFORE carely's main script so save/render below can use it. --}}
@include('store_templates._partials.guest-orders')
{{-- Delivery-address autocomplete engine (window.mbAddrAutocomplete). --}}
@include('store_templates._partials.address-autocomplete')
{{-- Shared map picker + clear-device-data engines. carely is a custom layout, so
     it @includes these itself (they are NOT auto-wired by store_templates/layout);
     the theme supplies its own carely-skinned UI + wiring below. --}}
@include('store_templates._partials.map-picker')
@include('store_templates._partials.clear-device-data')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var ITEMS = @json($jsItems);
    var MODES = @json($modes);
    var SLOT_ROUTE = @json(route('store.scheduleSlots', $store->id));
    var CHECKOUT_ROUTE = @json($checkoutRoute);
    var CART_QUOTE_ROUTE = @json(route('store.cartQuote', $store->slug));
    var COUPON_ROUTE = @json(\Illuminate\Support\Facades\Route::has('store.applyCoupon') ? route('store.applyCoupon', $store->id) : null);
    var MY_ORDERS_ROUTE = @json(\Illuminate\Support\Facades\Route::has('publicView.myOrders.index') ? route('publicView.myOrders.index', $store->id) : null);
    (function () {
        var list = document.getElementById('clOrdersList');
        if (!list) { return; }
        // Per-store key must match the save side below (data-store-id = $store->id).
        var GORD_STORE = @json((string) $store->id);
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        // Device-local guest history: a guest (no account) never gets the server
        // list, so render THIS device's recent orders — Track link + Reorder when
        // the items still exist — plus a sign-in-to-sync note. Returns the number
        // of rows rendered so the caller can fall back to the sign-in prompt.
        function renderGuestLocalOrders() {
            var local = (window.mbGuestOrders ? window.mbGuestOrders.list(GORD_STORE) : []);
            if (!local.length) { return 0; }
            list.innerHTML = local.map(function (o) {
                var d = o.at ? new Date(o.at) : null;
                var when = d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                var canReorder = (o.itemIds || []).some(function (id) { return ITEMS[String(id)]; });
                return '<div class="cl-order"><div class="cl-order-top"><b>' + esc(@json(___('Recent order'))) + '</b></div>'
                    + '<div class="cl-order-meta">' + esc(when) + (o.itemCount ? ' · ' + o.itemCount : '') + (o.total ? ' · ' + esc(o.total) : '') + '</div>'
                    + '<div class="cl-order-actions">'
                    + (o.trackUrl ? '<a class="cl-order-btn" href="' + esc(o.trackUrl) + '">' + @json(___('Track')) + '</a>' : '')
                    + (canReorder ? '<button type="button" class="cl-order-btn cl-order-btn--p" data-reorder="' + esc((o.itemIds || []).join(',')) + '">' + @json(___('Reorder')) + '</button>' : '')
                    + '</div>'
                    + '</div>';
            }).join('')
                + '<p class="cl-orders-empty" style="margin-top:6px">' + esc(@json(___('Sign in to sync your orders across devices.'))) + '</p>'
                + '<button type="button" class="cl-btn cl-btn--primary" data-mb-auth-open style="width:100%;margin-top:10px">' + @json(___('Sign in')) + '</button>';
            return local.length;
        }
        function showGuest() { if (!renderGuestLocalOrders()) { var g = document.getElementById('clOrdersGuest'); if (g) { g.hidden = false; } } }
        if (!MY_ORDERS_ROUTE || @json(! auth('customer')->check())) { showGuest(); return; }
        var LBL = { unpaid: @json(___('Awaiting payment')), pending: @json(___('Received')), new: @json(___('Received')), preparing: @json(___('Preparing')), ready: @json(___('Ready')), out_for_delivery: @json(___('On the way')), delivered: @json(___('Delivered')), completed: @json(___('Completed')), cancelled: @json(___('Cancelled')) };
        function lbl(s) { return LBL[s] || (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g, ' ') : ''); }
        fetch(MY_ORDERS_ROUTE, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (r.status === 401) { throw 'guest'; } return r.json(); })
            .then(function (res) {
                var orders = (res && res.orders) || [];
                if (!orders.length) { document.getElementById('clOrdersEmpty').hidden = false; return; }
                list.innerHTML = orders.map(function (o) {
                    var d = o.at ? new Date(o.at) : null;
                    var when = d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                    return '<div class="cl-order"><div class="cl-order-top"><b>' + esc(o.number) + '</b><span class="cl-order-status">' + esc(lbl(o.status)) + '</span></div><div class="cl-order-meta">' + esc(when) + ' · ' + o.itemCount + ' · ' + esc(o.total) + '</div>' + (o.trackUrl ? '<a class="cl-order-track" href="' + esc(o.trackUrl) + '">' + @json(___('Track')) + '</a>' : '') + (o.canRequestRefund ? '<button type="button" class="cl-order-track" data-refund-request="' + esc(o.id) + '">' + @json(___('Request refund')) + '</button>' : '') + (o.refundStatus ? '<span class="mb-refund mb-refund--' + esc(o.refundStatus) + '">' + mbRefundLabel(o.refundStatus, o.refundMethod) + '</span>' : '') + '</div>';
                }).join('');
            })
            .catch(function (e) { if (e === 'guest') { showGuest(); } });
    })();
    var CART_LINE_ROUTE_TEMPLATE = @json($cartLineRouteTemplate);
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
    // Real tax contract (Order::computeTax) — see the BUILD NOTES comment at
    // the bottom of this file for why this replaces a fabricated "Delivery"/
    // "Service fee" row.
    var TAX = { charge: @json($taxCharge), type: @json($taxType) };
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
        remove: @json(___('Remove')),
        brand: @json(___('Brand')),
        sku: @json(___('SKU')),
        variantSku: @json(___('Variant SKU')),
        barcode: @json(___('Barcode')),
        weight: @json(___('Weight')),
        dimensions: @json(___('Dimensions')),
        dosage: @json(___('Dosage')),
        usage: @json(___('Usage')),
        batchNo: @json(___('Batch no.')),
        expires: @json(___('Expires')),
        expiresSoon: @json(___('Expires soon')),
        // F1 stories
        story: @json(___('Story')),
        // F2 reviews
        reviewsWord: @json(___('reviews')),
        reviewWord: @json(___('review')),
        anonymous: @json(___('Anonymous')),
        verified: @json(___('Verified')),
        chooseRatingFirst: @json(___('Choose a rating first.')),
        reviewThanks: @json(___('Thank you! Your review will appear once approved.')),
        reviewFailed: @json(___('Could not send your review. Please try again.')),
        // F3 loyalty
        points: @json(___('points')),
        applyReward: @json(___('Apply :amount off')),
        // F4 wishlist
        view: @json(___('View')),
    };

    var root = document.getElementById('carely');
    if (!root) { return; }
    var storeId = root.getAttribute('data-store-id') || '';

    /* ---------------- entry age-gate interstitial ---------------- */
    var gate = document.getElementById('clGate');
    if (gate) {
        var GATE_KEY = 'carely-gate-ok-' + storeId;
        var gateOk = false;
        try { gateOk = window.sessionStorage.getItem(GATE_KEY) === '1'; } catch (e) { gateOk = false; }
        if (!gateOk) {
            gate.hidden = false;
        }
        var gateBtn = document.getElementById('clGateContinue');
        if (gateBtn) {
            gateBtn.addEventListener('click', function () {
                try { window.sessionStorage.setItem(GATE_KEY, '1'); } catch (e) { /* best-effort */ }
                gate.hidden = true;
            });
            if (!gateOk) {
                setTimeout(function () { try { gateBtn.focus(); } catch (e) { /* best-effort */ } }, 60);
                // SEO + a11y: deliberately do NOT inert/aria-hide #carely-main while
                // this one-time compliance overlay is up. aria-modal="true" on the
                // dialog already conveys "the rest is inert" to assistive tech, and
                // keeping the storefront in the DOM/a11y tree (visually covered, not
                // hidden) lets search engines index the full page behind the gate.
                // Interaction is blocked by the fixed overlay; the keyboard is kept
                // inside the card by this focus trap (Continue is the only control).
                gate.addEventListener('keydown', function (e) {
                    if (!gate.hidden && e.key === 'Tab') {
                        e.preventDefault();
                        try { gateBtn.focus(); } catch (er) { /* best-effort */ }
                    }
                });
            }
        }
    }

    /* ---------------- dark mode ---------------- */
    var darkKey = 'carely-theme-' + storeId;
    try {
        var saved = window.localStorage.getItem(darkKey);
        if (saved) { document.documentElement.setAttribute('data-theme', saved); }
    } catch (e) { /* storage may be unavailable — default (system) stands */ }
    var darkBtn = document.getElementById('clDarkToggle');
    if (darkBtn) {
        darkBtn.addEventListener('click', function () {
            var cur = document.documentElement.getAttribute('data-theme');
            var next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { window.localStorage.setItem(darkKey, next); } catch (e) { /* best-effort */ }
        });
    }

    /* ---------------- sheet a11y (Escape / back-close / focus trap+restore
       / aria-hidden+inert) ---------------- carely's sheets only ever have
       ONE open at a time, so a single tracked entry (not a stack) mirrors
       shelf/orbit/velora's own pattern: one history entry per "sheet
       session", Escape/scrim/close route through history.back() so a real
       Back press closes it too, and SWITCHING sheets (item -> cart after a
       successful add) closes the first one RAW (no extra history entry)
       before opening the next. */
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
            try { history.pushState({ clSheet: 1 }, '', url || undefined); } catch (e) { /* best-effort */ }
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
    var grid = document.getElementById('clGrid');
    var cards = grid ? Array.prototype.slice.call(grid.querySelectorAll('.cl-card')) : [];
    var noResults = document.getElementById('clNoResults');
    var activeCat = 'all';
    var query = '';

    function applyFilter() {
        var visible = 0;
        cards.forEach(function (card) {
            var matchesCat = activeCat === 'all' || card.getAttribute('data-root') === activeCat;
            var matchesQuery = !query
                || card.getAttribute('data-name').indexOf(query) !== -1
                || card.getAttribute('data-desc').indexOf(query) !== -1;
            var show = matchesCat && matchesQuery;
            card.hidden = !show;
            if (show) { visible++; }
        });
        if (noResults) { noResults.hidden = visible !== 0; }
    }

    function setActiveCat(catId) {
        activeCat = catId;
        document.querySelectorAll('.cl-chip').forEach(function (c) {
            var active = c.getAttribute('data-cat') === catId;
            c.classList.toggle('is-active', active);
            c.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        document.querySelectorAll('.cl-tile').forEach(function (tl) {
            tl.classList.toggle('is-active', tl.getAttribute('data-tile-cat') === catId);
        });
        applyFilter();
    }

    document.querySelectorAll('.cl-chip').forEach(function (chip) {
        chip.addEventListener('click', function () { setActiveCat(chip.getAttribute('data-cat')); });
    });
    document.querySelectorAll('.cl-tile').forEach(function (tile) {
        tile.addEventListener('click', function () {
            var catId = tile.getAttribute('data-tile-cat');
            setActiveCat(activeCat === catId ? 'all' : catId);
            if (grid) { grid.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        });
    });

    /* Three search boxes share one filter: the mobile inline one (<1024),
       plus two desktop-only ones (sticky header + hero band) that are never
       visible at the same time as the mobile one — see the DESKTOP
       COMPOSITION CSS above. Each just needs its own input listener. */
    ['clSearch', 'clSearchDesktop', 'clSearchHero'].forEach(function (id) {
        var input = document.getElementById(id);
        if (!input) { return; }
        input.addEventListener('input', function () {
            query = input.value.trim().toLowerCase();
            applyFilter();
        });
    });

    /* ---------------- cart state ---------------- */
    var CART_KEY = 'carely-cart-' + storeId;
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

    /* Bug batch 7 · 4: the cart lines go to the coupon check (submitOrder's `items` shape) — a buy-X-get-Y coupon is
       priced on them, exactly as the placed order is. */
    function couponLines() {
        return cart.map(function (l) {
            return { id: l.id, quantity: l.qty, variants: l.variantId || 0, extras: (l.extras || []).map(function (x) { return { id: (x && x.id) || x }; }) };
        });
    }
    (function () {
        var applyBtn = document.getElementById('clCouponApply');
        if (!applyBtn || !COUPON_ROUTE) { return; }
        applyBtn.addEventListener('click', function () {
            var code = (document.getElementById('clCouponInput').value || '').trim();
            var msgEl = document.getElementById('clCouponMsg');
            var field = document.getElementById('clCouponField');
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
                    serverQuote();
                })
                .catch(function () { applyBtn.disabled = false; });
        });
    })();
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

    function roundTo(n, decimals) {
        var f = Math.pow(10, decimals);
        return Math.round(n * f) / f;
    }

    // Mirrors Order::computeTax() exactly (see the BUILD NOTES comment at the
    // bottom of this file): a client-side ESTIMATE of the same real tax the
    // store charges, never a fabricated fee. 0 for every store with no tax
    // configured.
    function computeTax(subtotal) {
        if (TAX.charge <= 0 || subtotal <= 0) { return 0; }
        if (TAX.type === 'fixed') { return roundTo(TAX.charge, CUR.decimals); }
        return roundTo(subtotal * (TAX.charge / 100), CUR.decimals);
    }

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

    function renderCartBadge() {
        var n = cartCount();
        ['clCartBadge', 'clCartBadgeTab'].forEach(function (bid) {
            var badge = document.getElementById(bid);
            if (!badge) { return; }
            badge.textContent = String(n);
            badge.hidden = n === 0;
        });
    }

    function renderCart() {
        var linesEl = document.getElementById('clCartLines');
        var emptyEl = document.getElementById('clCartEmpty');
        var summary = document.getElementById('clCartSummary');
        var checkoutBtn = document.getElementById('clCheckoutBtn');
        if (!linesEl) { return; }

        linesEl.innerHTML = '';
        if (!cart.length) {
            emptyEl.hidden = false;
            summary.hidden = true;
            if (checkoutBtn) { checkoutBtn.disabled = true; }
            renderCartBadge();
            return;
        }
        emptyEl.hidden = true;
        summary.hidden = false;
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
            row.className = 'cl-cart-line';
            var img = document.createElement('img');
            img.alt = '';
            img.setAttribute('data-csp-error', 'hide');
            if (item.img) { img.src = item.img; } else { img.hidden = true; }
            var body = document.createElement('div');
            body.className = 'cl-cart-line__body';
            var nameEl = document.createElement('div');
            nameEl.className = 'cl-cart-line__name';
            nameEl.textContent = item.name + (variantLabel ? ' · ' + variantLabel : '') + ' × ' + line.qty;
            var priceEl = document.createElement('div');
            priceEl.className = 'cl-cart-line__price';
            priceEl.textContent = formatMoney(lt);
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'cl-cart-line__remove';
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
                        .then(function () { serverQuote(); })
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

        var tax = computeTax(sum);
        var taxRow = document.getElementById('clCartTaxRow');
        if (taxRow) { taxRow.hidden = tax <= 0; }
        var taxEl = document.getElementById('clCartTax');
        if (taxEl) { taxEl.textContent = formatMoney(tax); }
        document.getElementById('clCartSubtotal').textContent = formatMoney(sum);
        document.getElementById('clCartTotal').textContent = formatMoney(sum + tax);
        renderCartBadge();
        serverQuote();
    }

    /* P3-13 (THEME-CONTRACT §5.2): the summary is the server quote of the goods cart the checkout will place (the
       chosen fulfilment and coupon); the sum above is only the estimate shown until it answers. */
    function serverQuote() {
        var summary = document.getElementById('clCartSummary');
        if (!window.mbQuote || !summary || !cart.length) { return; }
        var couponEl = document.getElementById('clCouponField');
        window.mbQuote.update('cart', CART_QUOTE_ROUTE, {
            _token: CSRF,
            type: selectedMode().value,
            coupon_code: couponEl ? couponEl.value : '',
            flow: 'goods'
        }, function (q) {
            var e = window.mbQuote.esc;
            var hasTax = false;
            var html = window.mbQuote.rows(q).map(function (r) {
                if (r.key === 'subtotal') {
                    return '<div class="cl-srow"><span>' + e(r.label) + '</span><span id="clCartSubtotal">' + e(r.formatted) + '</span></div>';
                }
                if (r.key === 'tax') {
                    hasTax = true;
                    return '<div class="cl-srow" id="clCartTaxRow"><span>' + e(r.label) + '</span><span id="clCartTax">' + e(r.formatted) + '</span></div>';
                }
                return '<div class="cl-srow"><span>' + e(r.label) + '</span><span>' + e(r.formatted) + '</span></div>';
            }).join('');
            if (!hasTax) { html += '<div class="cl-srow" id="clCartTaxRow" hidden><span></span><span id="clCartTax"></span></div>'; }
            summary.innerHTML = html + '<div class="cl-srow cl-srow--total"><span>' + e(q.total_label) + '</span><span id="clCartTotal">' + e(q.total.formatted) + '</span></div>';
        }, [summary]);
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
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'add-to-cart failed');
                }
                return data;
            });
    }

    renderCart();

    /* ---------------- product detail sheet ---------------- */
    var scrim = document.getElementById('clItemScrim');
    var sheet = document.getElementById('clItemSheet');
    var currentItem = null;
    var currentVariantId = null;
    var currentQty = 1;
    var ageConfirmed = {};
    try {
        var storedAge = window.sessionStorage.getItem('carely-age-ok-' + storeId);
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

    function infoRow(icon, label, value, warn) {
        var row = document.createElement('div');
        row.className = 'cl-inforow';
        var ico = document.createElement('span');
        ico.className = 'cl-inforow__ico';
        ico.innerHTML = icon;
        var body = document.createElement('div');
        var l = document.createElement('div');
        l.className = 'cl-inforow__label';
        l.textContent = label;
        var v = document.createElement('div');
        v.className = 'cl-inforow__value' + (warn ? ' cl-inforow__value--warn' : '');
        v.textContent = value;
        body.appendChild(l);
        body.appendChild(v);
        row.appendChild(ico);
        row.appendChild(body);
        return row;
    }
    var ICO_CLOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>';
    var ICO_INFO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 11h1v6h1"/></svg>';
    var ICO_TAG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 12.9L12.4 21a2 2 0 01-2.8 0L3 14.4a2 2 0 010-2.8L11.1 3.5A2 2 0 0112.5 3H19a2 2 0 012 2v6.5a2 2 0 01-.4 1.4z"/><circle cx="16" cy="8" r="1.6"/></svg>';

    function renderInfoRows() {
        var group = document.getElementById('clInfoGroup');
        var wrap = document.getElementById('clInfoRows');
        wrap.innerHTML = '';
        var attrs = currentItem.attrs || {};
        var consumed = {};
        var rowsEls = [];

        var dosageKey = Object.keys(attrs).filter(function (k) { return /^(dosage|dose)$/i.test(k); })[0];
        if (dosageKey && attrs[dosageKey]) { rowsEls.push(infoRow(ICO_CLOCK, T.dosage, String(attrs[dosageKey]))); consumed[dosageKey] = true; }
        var usageKey = Object.keys(attrs).filter(function (k) { return /^(usage|directions|how ?to ?use)$/i.test(k); })[0];
        if (usageKey && attrs[usageKey]) { rowsEls.push(infoRow(ICO_INFO, T.usage, String(attrs[usageKey]))); consumed[usageKey] = true; }
        if (currentItem.batchNo) { rowsEls.push(infoRow(ICO_TAG, T.batchNo, currentItem.batchNo)); }
        if (currentItem.expiryDate) { rowsEls.push(infoRow(ICO_TAG, currentItem.expiringSoon ? T.expiresSoon : T.expires, currentItem.expiryDate, currentItem.expiringSoon)); }
        if (currentItem.brand) { rowsEls.push(infoRow(ICO_TAG, T.brand, currentItem.brand)); }
        if (currentItem.sku) { rowsEls.push(infoRow(ICO_TAG, T.sku, currentItem.sku)); }
        var v = currentVariant();
        if (v && v.sku) { rowsEls.push(infoRow(ICO_TAG, T.variantSku, v.sku)); }
        if (v && v.barcode) { rowsEls.push(infoRow(ICO_TAG, T.barcode, v.barcode)); }
        if (currentItem.weight) { rowsEls.push(infoRow(ICO_TAG, T.weight, currentItem.weight)); }
        if (currentItem.dimensions) { rowsEls.push(infoRow(ICO_TAG, T.dimensions, currentItem.dimensions)); }
        Object.keys(attrs).forEach(function (k) {
            if (k === 'brand' || consumed[k]) { return; }
            var val = attrs[k];
            if (val === null || val === undefined || val === '') { return; }
            rowsEls.push(infoRow(ICO_TAG, k, String(val)));
        });

        if (!rowsEls.length) { group.hidden = true; return; }
        group.hidden = false;
        rowsEls.forEach(function (el) { wrap.appendChild(el); });
    }

    function renderVariantGroups() {
        var wrap = document.getElementById('clVariantGroups');
        wrap.innerHTML = '';
        if (!currentItem.variants.length) { return; }
        var group = document.createElement('div');
        group.className = 'cl-group';
        var label = document.createElement('div');
        label.className = 'cl-group__label';
        label.textContent = @json(___('Choose an option'));
        var opts = document.createElement('div');
        opts.className = 'cl-opts';
        currentItem.variants.forEach(function (v) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cl-opt' + (v.id === currentVariantId ? ' is-active' : '');
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
        var priceEl = document.getElementById('clAddToCartPrice');
        priceEl.textContent = formatMoney(currentUnitPrice() * currentQty);
    }

    function renderItemStock() {
        var badge = document.getElementById('clItemStock');
        if (!badge || !currentItem) { return; }
        var v = currentVariant();
        var qty = (v && v.stockQty !== null && v.stockQty !== undefined) ? v.stockQty : currentItem.stockQty;
        if (!currentItem.trackInventory || qty === null || qty === undefined) {
            badge.hidden = true;
            return;
        }
        badge.hidden = false;
        badge.className = 'cl-stock';
        if (qty <= 0) {
            badge.classList.add('cl-stock--out');
            badge.textContent = T.outOfStock;
        } else if (qty <= 5) {
            badge.classList.add('cl-stock--low');
            badge.textContent = T.stockLeft.replace(':count', String(qty));
        } else {
            badge.classList.add('cl-stock--in');
            badge.textContent = T.inStock;
        }
    }

    // F2 — thumbnail strip: swaps the main detail image on tap. Only rendered
    // when the item carries >= 2 images (primary + gallery_images, deduped).
    function renderThumbs() {
        var wrap = document.getElementById('clItemThumbs');
        if (!wrap) { return; }
        var mainImg = document.getElementById('clItemImg');
        var imgs = (currentItem && currentItem.gallery) ? currentItem.gallery : [];
        wrap.innerHTML = '';
        if (imgs.length < 2) { wrap.hidden = true; return; }
        wrap.hidden = false;
        imgs.forEach(function (src, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'cl-thumb';
            b.setAttribute('aria-current', i === 0 ? 'true' : 'false');
            var im = document.createElement('img');
            im.src = src; im.alt = ''; im.setAttribute('data-csp-error', 'hide');
            b.appendChild(im);
            b.addEventListener('click', function () {
                mainImg.src = src; mainImg.alt = currentItem ? currentItem.name : ''; mainImg.hidden = false;
                Array.prototype.forEach.call(wrap.children, function (c) { c.setAttribute('aria-current', c === b ? 'true' : 'false'); });
            });
            wrap.appendChild(b);
        });
    }

    function openItem(id) {
        var item = ITEMS[id];
        if (!item) { return; }
        currentItem = item;
        currentVariantId = item.variants.length === 1 ? item.variants[0].id : null;
        currentQty = 1;
        document.getElementById('clQtyVal').textContent = '1';
        document.getElementById('clItemName').textContent = item.name;
        var detailImg = document.getElementById('clItemImg');
        if (item.img) { detailImg.src = item.img; detailImg.alt = item.name; detailImg.hidden = false; }
        else { detailImg.removeAttribute('src'); detailImg.hidden = true; }
        renderThumbs();
        document.getElementById('clItemDesc').textContent = item.desc;
        renderVariantGroups();
        renderInfoRows();
        renderItemStock();
        updateSheetPrice();
        if (typeof loadReviews === 'function') { loadReviews(id); }

        var ageGateEl = document.getElementById('clAgeGate');
        var addBtn = document.getElementById('clAddToCart');
        var needsGate = item.ageRestricted && !ageConfirmed[id];
        ageGateEl.hidden = !needsGate;
        addBtn.disabled = !!(item.soldOut);

        var itemUrl = window.location.pathname + '?item=' + encodeURIComponent(String(id));
        openA11ySheet(sheet, scrim, document.getElementById('clItemName'), itemUrl);
    }

    function closeItem() {
        dismissA11ySheet();
    }

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

    // Reorder from device-local guest history (rows rendered in the Orders panel):
    // re-add each still-available item, then jump to the cart. Delegated so it
    // works for rows built after load.
    var clOrdersListEl = document.getElementById('clOrdersList');
    if (clOrdersListEl) {
        clOrdersListEl.addEventListener('click', function (e) {
            var b = e.target.closest('[data-reorder]');
            if (!b) { return; }
            var ids = (b.getAttribute('data-reorder') || '').split(',').filter(Boolean);
            ids.forEach(function (id) { if (ITEMS[String(id)]) { quickAdd(id); } });
        });
    }

    document.querySelectorAll('[data-open-item]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-open-item'), 10);
            var item = ITEMS[id];
            if (item && item.variants.length <= 1) { quickAdd(id); } else { openItem(id); }
        });
    });
    document.getElementById('clItemClose').addEventListener('click', closeItem);
    scrim.addEventListener('click', closeItem);

    document.querySelectorAll('[data-open-item-link]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            openItem(parseInt(link.getAttribute('data-open-item-link'), 10));
        });
    });

    var FAV_KEY = 'carely-favs-' + storeId;
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

    document.getElementById('clQtyMinus').addEventListener('click', function () {
        currentQty = Math.max(1, currentQty - 1);
        document.getElementById('clQtyVal').textContent = String(currentQty);
        updateSheetPrice();
    });
    document.getElementById('clQtyPlus').addEventListener('click', function () {
        currentQty = currentQty + 1;
        document.getElementById('clQtyVal').textContent = String(currentQty);
        updateSheetPrice();
    });

    document.getElementById('clAgeConfirm').addEventListener('click', function () {
        if (currentItem) {
            ageConfirmed[currentItem.id] = true;
            try {
                window.sessionStorage.setItem('carely-age-ok-' + storeId, JSON.stringify(ageConfirmed));
            } catch (e) { /* best-effort */ }
        }
        document.getElementById('clAgeGate').hidden = true;
    });
    document.getElementById('clAgeCancel').addEventListener('click', closeItem);

    document.getElementById('clAddToCart').addEventListener('click', function () {
        if (!currentItem) { return; }
        if (currentItem.ageRestricted && !ageConfirmed[currentItem.id]) {
            document.getElementById('clAgeGate').hidden = false;
            return;
        }
        if (currentItem.variants.length > 1 && !currentVariantId) {
            window.alert(T.chooseOption);
            return;
        }
        var addBtn = document.getElementById('clAddToCart');
        var item = currentItem, variantId = currentVariantId, qty = currentQty;
        addBtn.disabled = true;
        postAddToCart(item.id, variantId, qty)
            .then(function (data) {
                addToCart(item.id, variantId, qty, data.line_id);
                rawCloseA11ySheet();
                openCart();
            })
            .catch(function () {
                window.alert(T.genericError);
            })
            .then(function () { addBtn.disabled = false; });
    });

    /* Deep link: ?item=<id> opens the detail sheet on load. Deferred to the next
       tick: openItem() calls render helpers whose module vars are still being
       assigned further down this IIFE, so calling it inline here throws — silently,
       via this catch — and the sheet never opens. Running it after the script
       finishes guarantees every dependency is initialised. */
    try {
        var params = new URLSearchParams(window.location.search);
        var deepId = parseInt(params.get('item'), 10);
        if (deepId) { setTimeout(function () { try { if (ITEMS[deepId]) { openItem(deepId); } } catch (e2) { /* no-op */ } }, 0); }
    } catch (e) { /* no-op on an unsupported/old browser */ }

    /* ---------------- cart sheet + checkout ---------------- */
    var cartScrim = document.getElementById('clCartScrim');
    var cartSheet = document.getElementById('clCartSheet');

    function openCart() {
        renderCart();
        openA11ySheet(cartSheet, cartScrim, document.getElementById('clCartTitle'));
    }
    function closeCart() {
        dismissA11ySheet();
    }
    document.getElementById('clCartOpen').addEventListener('click', openCart);
    document.getElementById('clCartClose').addEventListener('click', closeCart);
    cartScrim.addEventListener('click', closeCart);

    function selectedMode() {
        var select = document.getElementById('clMode');
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
        var wrap = document.getElementById('clSlots');
        wrap.innerHTML = '';
        chosenSlot = null;
        fetch(SLOT_ROUTE + '?type=' + encodeURIComponent(selectedMode().value), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.enabled || !data.slots || !data.slots.length) { return; }
                data.slots.slice(0, 8).forEach(function (slot) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'cl-opt';
                    btn.textContent = slot.label;
                    btn.disabled = !!slot.full;
                    btn.addEventListener('click', function () {
                        chosenSlot = slot.value;
                        wrap.querySelectorAll('.cl-opt').forEach(function (b) { b.classList.remove('is-active'); });
                        btn.classList.add('is-active');
                    });
                    wrap.appendChild(btn);
                });
            })
            .catch(function () { /* slot picker is best-effort display; checkout still works without it */ });
    }

    function updateModeFields() {
        var mode = selectedMode();
        serverQuote();
        document.getElementById('clAddressField').hidden = !mode.needsAddress;
        document.getElementById('clSlotField').hidden = !mode.needsSlot;
        if (mode.needsSlot) { loadSlots(); }
    }
    var modeSelect = document.getElementById('clMode');
    if (modeSelect) { modeSelect.addEventListener('change', updateModeFields); }

    // Crave-style delivery-address autocomplete + use-my-location. Safe to wire
    // now; the input stays in the DOM even while the field is hidden.
    if (window.mbAddrAutocomplete) {
        window.mbAddrAutocomplete(document.getElementById('clAddress'), {
            suggest: document.getElementById('clAddrSuggest'),
            locBtn: document.getElementById('clAddrLoc')
        });
    }

    /* Address map picker — carely-native .cl-sheet drawer, opened via the theme's
       own focus-trapping openA11ySheet/dismissA11ySheet; map logic is the shared
       window.mbMapPicker engine. Fills the same #clAddress the checkout submits. */
    if (window.mbMapPicker) {
        window.mbMapPicker({
            trigger: document.getElementById('clMapBtn'),
            modal:   document.getElementById('clMapSheet'),
            map:     document.getElementById('clMap'),
            input:   document.getElementById('clAddress'),
            preview: document.getElementById('clMapPreview'),
            confirm: document.getElementById('clMapConfirm'),
            close:   document.getElementById('clMapClose'),
            toggle:  function (open) {
                if (open) { openA11ySheet(document.getElementById('clMapSheet'), document.getElementById('clMapScrim'), document.getElementById('clMapTitle')); }
                else { dismissA11ySheet(); }
            },
            i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
        });
        document.getElementById('clMapScrim').addEventListener('click', function () { dismissA11ySheet(); });
    } else { var _clMapBtn = document.getElementById('clMapBtn'); if (_clMapBtn) { _clMapBtn.style.display = 'none'; } }

    /* Clear saved data on this device — account-sheet row (the account sheet is
       guest-reachable). Wipes carely's own cart/favs/stories keys + store-scoped
       shared keys; the shared engine keeps the light/dark preference. */
    (function () {
        var cd = document.getElementById('clClearData');
        if (!cd) { return; }
        if (!window.mbClearDeviceData) { cd.style.display = 'none'; var h = document.querySelector('.cl-cleardata-hint'); if (h) { h.style.display = 'none'; } return; }
        cd.addEventListener('click', function () {
            window.mbClearDeviceData({
                keys: ['carely-cart-' + storeId, 'carely-favs-' + storeId, 'carely-stories-viewed-' + storeId],
                storeId: storeId,
                slug: @json($store->slug ?? ''),
                confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
            });
        });
    })();

    document.getElementById('clCheckoutBtn').addEventListener('click', function () {
        document.getElementById('clCartView').hidden = true;
        document.getElementById('clCheckoutForm').hidden = false;
        document.getElementById('clCartFoot').hidden = true;
        updateModeFields();
        if (typeof loadLoyaltyRow === 'function') { loadLoyaltyRow(); }
    });

    document.getElementById('clPlaceOrder').addEventListener('click', function () {
        var msg = document.getElementById('clCheckoutMsg');
        msg.innerHTML = '';
        if (!cart.length) {
            msg.innerHTML = '<div class="cl-msg cl-msg--error">' + T.cartEmpty + '</div>';
            return;
        }
        var name = document.getElementById('clName').value.trim();
        var phone = document.getElementById('clPhone').value.trim();
        if (!name) {
            msg.innerHTML = '<div class="cl-msg cl-msg--error">' + T.nameRequired + '</div>';
            return;
        }
        var mode = selectedMode();
        if (mode.needsAddress && !document.getElementById('clAddress').value.trim()) {
            msg.innerHTML = '<div class="cl-msg cl-msg--error">' + T.addressRequired + '</div>';
            return;
        }

        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('customer_name', name);
        fd.append('phone_number', phone);
        fd.append('type', mode.value);
        if (mode.needsAddress) { fd.append('address', document.getElementById('clAddress').value.trim()); }
        if (mode.needsSlot && chosenSlot) { fd.append('scheduled_at', chosenSlot); }
        // F3(b) — forwards whatever the redeem toggle set (0 unless the
        // shopper explicitly opted in); the server re-derives the discount.
        var loyaltyInput = document.getElementById('clLoyaltyRedeemInput');
        if (loyaltyInput) { fd.append('loyalty_redeem', loyaltyInput.value); }
        var clCouponField = document.getElementById('clCouponField');
        if (clCouponField && clCouponField.value) { fd.append('coupon_code', clCouponField.value); }
        var clGiftField = document.getElementById('clGiftCardInput');
        if (clGiftField && clGiftField.value.trim()) { fd.append('gift_card_code', clGiftField.value.trim()); }

        msg.innerHTML = '<div class="cl-msg">' + T.placing + '</div>';
        fetch(CHECKOUT_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    msg.innerHTML = '<div class="cl-msg cl-msg--ok">' + T.placed + '</div>';
                    // Device-local guest history: remember this order on THIS device
                    // so a guest (no account) still sees it on the Orders screen.
                    // Snapshot BEFORE the cart is cleared.
                    try {
                        if (data.track_token && window.mbGuestOrders) {
                            var _sub = cart.reduce(function (s, l) { return s + lineTotal(l); }, 0);
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
                } else {
                    msg.innerHTML = '<div class="cl-msg cl-msg--error">' + ((data && data.message) || T.genericError) + '</div>';
                }
            })
            .catch(function () {
                msg.innerHTML = '<div class="cl-msg cl-msg--error">' + T.genericError + '</div>';
            });
    });

    /* =====================================================================
       Curated core features (loop/enrich) — stories, reviews, loyalty,
       Saved/wishlist. Every endpoint here is an existing, already-seeded
       platform route; nothing below adds or changes backend behaviour.
       ===================================================================== */

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ---------------- F1: stories rail + full-screen viewer ---------------- */
    var storiesRail = document.getElementById('clStories');
    var storyViewer = document.getElementById('clStoryViewer');
    var storyProgress = document.getElementById('clStoryProgress');
    var storyMedia = document.getElementById('clStoryMedia');
    var storyCaption = document.getElementById('clStoryCaption');
    var storyCta = document.getElementById('clStoryCta');
    var stories = [];
    var storyIndex = 0;
    var storyTimeout = null;
    var STORY_VIEWED_KEY = 'carely-stories-viewed-' + storeId;
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
            btn.className = 'cl-story' + (storyViewedIds[s.id] ? ' is-viewed' : '');
            btn.setAttribute('role', 'listitem');
            btn.setAttribute('aria-label', s.caption || (T.story + ' ' + (idx + 1)));
            var ring = document.createElement('span');
            ring.className = 'cl-story__ring';
            var img = document.createElement('img');
            img.className = 'cl-story__thumb';
            img.alt = '';
            img.setAttribute('data-csp-error', 'hide');
            if (url) { img.src = url; }
            ring.appendChild(img);
            btn.appendChild(ring);
            if (s.caption) {
                var label = document.createElement('span');
                label.className = 'cl-story__label';
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
            bar.className = 'cl-story-viewer__bar';
            var fill = document.createElement('span');
            fill.className = 'cl-story-viewer__bar-fill';
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
            var fill = bars[i].querySelector('.cl-story-viewer__bar-fill');
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
            var curFill = curBar.querySelector('.cl-story-viewer__bar-fill');
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
        openA11ySheet(storyViewer, storyViewer, document.getElementById('clStoryClose'));
        showStory(startIndex || 0);
    }
    function closeStoryViewer() {
        clearTimeout(storyTimeout);
        dismissA11ySheet();
    }

    document.getElementById('clStoryClose').addEventListener('click', closeStoryViewer);
    // Close the full-screen viewer when its CTA is tapped, so a same-page CTA
    // (or a new-tab external link) never leaves the black overlay covering the
    // storefront underneath.
    if (storyCta) { storyCta.addEventListener('click', function () { closeStoryViewer(); }); }
    document.getElementById('clStoryPrev').addEventListener('click', function () { showStory(storyIndex - 1); });
    document.getElementById('clStoryNext').addEventListener('click', function () { showStory(storyIndex + 1); });

    fetch(STORIES_URL, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (payload) {
            var list = payload && (Array.isArray(payload) ? payload : payload.data);
            stories = Array.isArray(list) ? list.slice().sort(function (a, b) { return (a.sort || 0) - (b.sort || 0); }) : [];
            if (stories.length) { renderStoryBubbles(); }
        })
        .catch(function () { /* leave the rail hidden — never an empty shell */ });

    /* ---------------- F2: reviews (product detail sheet) ---------------- */
    var rvGroup = document.getElementById('clReviewsGroup');
    var rvHead = document.getElementById('clRvHead');
    var rvAvgNum = document.getElementById('clRvAvgNum');
    var rvAvgStars = document.getElementById('clRvAvgStars');
    var rvCount = document.getElementById('clRvCount');
    var rvDist = document.getElementById('clRvDist');
    var rvList = document.getElementById('clRvList');
    var rvEmpty = document.getElementById('clRvEmpty');
    var rvForm = document.getElementById('clRvForm');
    var rvWriteToggle = document.getElementById('clRvWriteToggle');
    var rvMsg = document.getElementById('clRvMsg');
    var rvSubmit = document.getElementById('clRvSubmit');
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
        Array.prototype.forEach.call(document.querySelectorAll('#clRvStarPicker [data-rv-star]'), function (b) {
            b.classList.toggle('is-on', Number(b.getAttribute('data-rv-star')) <= rvChosenStars);
        });
    }
    document.querySelectorAll('#clRvStarPicker [data-rv-star]').forEach(function (b) {
        b.addEventListener('click', function () {
            rvChosenStars = Number(b.getAttribute('data-rv-star'));
            paintRvStarPicker();
        });
    });

    function resetRvForm() {
        rvChosenStars = 0;
        paintRvStarPicker();
        document.getElementById('clRvName').value = '';
        document.getElementById('clRvBody').value = '';
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
                row.className = 'cl-rv-dist-row';
                row.innerHTML = '<span>' + star + '★</span><i><s style="width:' + pct + '%"></s></i><span>' + c + '</span>';
                rvDist.appendChild(row);
            });
        }

        rvList.innerHTML = '';
        list.forEach(function (r) {
            var item = document.createElement('div');
            item.className = 'cl-rv-item';
            var name = r.name || r.reviewer_name || T.anonymous;
            item.innerHTML = '<div class="cl-rv-item__top">'
                + '<span class="cl-rv-stars">' + starGlyphs(r.stars) + '</span>'
                + '<span class="cl-rv-item__date">' + esc(relativeTime(r.created_at)) + '</span>'
                + '</div>'
                + '<div class="cl-rv-item__name">' + esc(name) + (r.is_verified ? '<span class="cl-rv-verified">' + esc(T.verified) + '</span>' : '') + '</div>'
                + (r.body ? '<p class="cl-rv-item__body">' + esc(r.body) + '</p>' : '');
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
        if (!MBR_BASE) { rvGroup.hidden = true; return; }
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
            rvMsg.innerHTML = '<div class="cl-msg cl-msg--error">' + T.chooseRatingFirst + '</div>';
            return;
        }
        rvSubmit.disabled = true;
        rvMsg.innerHTML = '';
        fetch(MBR_BASE + '/' + rvCurrentMenuId + '/reviews', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({
                rating: rvChosenStars,
                text: document.getElementById('clRvBody').value.trim() || null,
                name: document.getElementById('clRvName').value.trim() || null,
            }),
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.ok) { throw new Error('review-store-failed'); }
                rvMsg.innerHTML = '<div class="cl-msg cl-msg--ok">' + T.reviewThanks + '</div>';
                rvChosenStars = 0;
                paintRvStarPicker();
                document.getElementById('clRvName').value = '';
                document.getElementById('clRvBody').value = '';
                rvSubmit.disabled = false;
            })
            .catch(function () {
                rvMsg.innerHTML = '<div class="cl-msg cl-msg--error">' + T.reviewFailed + '</div>';
                rvSubmit.disabled = false;
            });
    });

    /* ---------------- F3: loyalty (Rewards card + checkout redeem row) ---------------- */
    function loyaltyTeaserLine() {
        if (!LOY_TEASER) { return ''; }
        return [LOY_TEASER.program, LOY_TEASER.earn, LOY_TEASER.reward].filter(Boolean).join(' · ');
    }

    function renderRewardsCardBalance(data) {
        var area = document.getElementById('clRewardsBalanceArea');
        var signin = document.getElementById('clRewardsSignin');
        if (!area) { return; }
        if (data && data.active) {
            signin.hidden = true;
            var pct = Math.max(0, Math.min(100, Number(data.progress_pct) || 0));
            area.innerHTML = '<div class="cl-rewards-card__balance">' + esc(String(data.balance)) + ' <small>' + esc(T.points) + '</small></div>'
                + '<div class="cl-rewards-progress"><s style="width:' + pct + '%"></s></div>';
            document.querySelectorAll('#clTierChips .cl-tierchip').forEach(function (chip) {
                chip.classList.toggle('is-current', String(data.tier || '').toLowerCase() === chip.getAttribute('data-tier'));
            });
        } else {
            area.innerHTML = '';
            signin.hidden = false;
        }
    }

    function loadRewardsBalance() {
        if (!document.getElementById('clRewardsBalanceArea')) { return; }
        fetch(LOYALTY_BALANCE_URL, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : { active: false }; })
            .then(renderRewardsCardBalance)
            .catch(function () { renderRewardsCardBalance({ active: false }); });
    }

    function renderLoyaltyRow(data) {
        var row = document.getElementById('clLoyaltyRow');
        var teaserEl = document.getElementById('clLoyaltyTeaser');
        var signinEl = document.getElementById('clLoyaltySignin');
        var toggle = document.getElementById('clLoyaltyToggle');
        var input = document.getElementById('clLoyaltyRedeemInput');
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
            document.getElementById('clLoyaltyToggleLabel').textContent = T.applyReward.replace(':amount', data.reward_discount_display || '');
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

    var loyaltyToggleBtn = document.getElementById('clLoyaltyToggle');
    if (loyaltyToggleBtn) {
        loyaltyToggleBtn.addEventListener('click', function () {
            var on = !this.classList.contains('is-on');
            this.classList.toggle('is-on', on);
            this.setAttribute('aria-pressed', on ? 'true' : 'false');
            document.getElementById('clLoyaltyRedeemInput').value = on ? '1' : '0';
        });
    }

    var accountScrim = document.getElementById('clAccountScrim');
    var accountSheet = document.getElementById('clAccountSheet');
    document.getElementById('clAccountOpen').addEventListener('click', function () {
        loadRewardsBalance();
        openA11ySheet(accountSheet, accountScrim, document.getElementById('clAccountTitle'));
    });
    document.getElementById('clAccountClose').addEventListener('click', function () { dismissA11ySheet(); });
    accountScrim.addEventListener('click', function () { dismissA11ySheet(); });

    /* ---------------- F4: "Saved" wishlist view ---------------- */
    var savedScrim = document.getElementById('clSavedScrim');
    var savedSheet = document.getElementById('clSavedSheet');

    function renderSavedBadge() {
        var n = Object.keys(favIds).length;
        ['clSavedBadge', 'clSavedBadgeTab'].forEach(function (bid) {
            var badge = document.getElementById(bid);
            if (!badge) { return; }
            badge.textContent = String(n);
            badge.hidden = n === 0;
        });
    }

    function renderSaved() {
        var wrap = document.getElementById('clSavedLines');
        var empty = document.getElementById('clSavedEmpty');
        wrap.innerHTML = '';
        var ids = Object.keys(favIds);
        if (!ids.length) { empty.hidden = false; renderSavedBadge(); return; }
        empty.hidden = true;
        ids.forEach(function (idStr) {
            var item = ITEMS[idStr];
            if (!item) { return; }
            var row = document.createElement('div');
            row.className = 'cl-saved-line';
            var img = document.createElement('img');
            img.alt = '';
            img.setAttribute('data-csp-error', 'hide');
            if (item.img) { img.src = item.img; } else { img.hidden = true; }
            var body = document.createElement('div');
            body.className = 'cl-saved-line__body';
            var name = document.createElement('div');
            name.className = 'cl-saved-line__name';
            name.textContent = item.name;
            var price = document.createElement('div');
            price.className = 'cl-saved-line__price';
            price.textContent = item.priceFormatted;
            body.appendChild(name);
            body.appendChild(price);
            var actions = document.createElement('div');
            actions.className = 'cl-saved-line__actions';
            var viewBtn = document.createElement('button');
            viewBtn.type = 'button';
            viewBtn.className = 'cl-btn cl-btn--ghost';
            viewBtn.textContent = T.view;
            viewBtn.addEventListener('click', function () { rawCloseA11ySheet(); openItem(item.id); });
            var rmBtn = document.createElement('button');
            rmBtn.type = 'button';
            rmBtn.className = 'cl-btn cl-btn--ghost';
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

    document.getElementById('clSavedOpen').addEventListener('click', function () {
        renderSaved();
        openA11ySheet(savedSheet, savedScrim, document.getElementById('clSavedTitle'));
    });
    document.getElementById('clSavedClose').addEventListener('click', function () { dismissA11ySheet(); });
    savedScrim.addEventListener('click', function () { dismissA11ySheet(); });

    renderSavedBadge();

    /* crave-parity: branch switch */
    (function () {
        var bs = document.getElementById('clBranchSel');
        if (bs) { bs.addEventListener('change', function (e) { if (e.target.value) { window.location.assign(e.target.value); } }); }
    })();

    /* crave-parity: promo banner slider */
    (function () {
        var track = document.getElementById('clBannerTrack');
        var dotsWrap = document.getElementById('clBannerDots');
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

    /* Fix 1 — promo banner CTA. A banner with a real link_url stays a normal
       link; one without (data-banner-cta) would otherwise be a dead href="#".
       Intercept those: close any open story viewer, reset the catalog to "All"
       and scroll the product grid into view (carely is a single screen — the
       grid IS the catalog). */
    Array.prototype.forEach.call(document.querySelectorAll('.cl-banner[data-banner-cta]'), function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof closeStoryViewer === 'function') { closeStoryViewer(); }
            if (typeof setActiveCat === 'function') { setActiveCat('all'); }
            var target = document.getElementById('clGrid') || document.querySelector('.cl-grid');
            if (target) { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    });

    /* Fix 4 — bottom tabbar. Each button drives an existing carely control, so
       there is no parallel state: cart/saved/account just click their (mobile-
       hidden) header buttons; home resets the catalog + scrolls to top; search
       focuses the mobile search box. */
    (function () {
        var bar = document.getElementById('clTabbar');
        if (!bar) { return; }
        var btns = bar.querySelectorAll('.cl-tabbtn');
        function setActiveTab(tab) {
            Array.prototype.forEach.call(btns, function (b) {
                b.setAttribute('aria-current', b.getAttribute('data-tab') === tab ? 'true' : 'false');
            });
        }
        Array.prototype.forEach.call(btns, function (b) {
            b.addEventListener('click', function () {
                var tab = b.getAttribute('data-tab');
                // Remember the active screen for THIS tab so a same-tab reload (a
                // language switch is a full navigation) lands back here — e.g. on
                // the Account sheet holding order history — not on home.
                try { window.sessionStorage.setItem('mb-screen-' + storeId, tab); } catch (e) {}
                if (tab === 'home') {
                    if (typeof setActiveCat === 'function') { setActiveCat('all'); }
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    setActiveTab('home');
                } else if (tab === 'search') {
                    var s = document.getElementById('clSearch');
                    if (s) { s.scrollIntoView({ behavior: 'smooth', block: 'center' }); setTimeout(function () { s.focus(); }, 250); }
                    setActiveTab('search');
                } else if (tab === 'saved') {
                    var so = document.getElementById('clSavedOpen'); if (so) { so.click(); }
                } else if (tab === 'cart') {
                    var co = document.getElementById('clCartOpen'); if (co) { co.click(); }
                } else if (tab === 'account') {
                    var ao = document.getElementById('clAccountOpen'); if (ao) { ao.click(); }
                }
            });
        });

        /* Restore the screen across a same-tab reload (a language switch is a
           full navigation, which otherwise dumps the shopper back on home and
           makes their order history look "gone"). sessionStorage is per-tab, so
           a fresh visit still opens on home. Skipped when a ?item deep-link
           drives the view. Re-activates via the SAME tab click. Runs once. */
        (function restoreScreen() {
            try {
                if (new URLSearchParams(window.location.search).get('item')) { return; }
                var saved = window.sessionStorage.getItem('mb-screen-' + storeId);
                if (!saved || saved === 'home') { return; }
                var target = bar.querySelector('.cl-tabbtn[data-tab="' + saved + '"]');
                if (target) { target.click(); }
            } catch (e) { /* best-effort */ }
        })();
    })();
})();
</script>
@endpush

{{--
    BUILD NOTES (carely, OTC pharmacy vertical — views only, isolated worktree)

    1. Cart totals show Subtotal / (Tax, when > 0) / Total — NOT the literal
       "Subtotal/Delivery/Service fee/Total" from the design spec/mockup. This
       is a deliberate deviation: pharmacy stores only ever use the 'ship' /
       'pickup_slot' / 'in_store' fulfillment modes (config/verticals.php),
       and Order::getTotalAttribute() only ever adds a delivery charge for the
       literal 'delivery' type — a mode pharmacy stores never have. There is
       also no "service fee" concept anywhere in this codebase. Rendering
       those two rows would have shown numbers the real order never charges,
       breaking the theme-totals-contract (every kept theme's receipt must
       reconcile with Order::getTotalAttribute()). The Tax row instead mirrors
       Order::computeTax() exactly (same restaurant_taxx_charge/
       restaurant_tax_type store_options), so what the client shows pre-
       checkout matches what track.blade.php's real receipt shows after.

    2. No prescription/Rx/pharmacist feature exists anywhere in this file —
       D3 (config/verticals.php pharmacy comment: "OTC ONLY — no Rx, ever").
       The only pharmacy-specific data surfaced is `age_restricted` (a real,
       non-Rx, minimum-age-to-purchase flag) and `batch_no`/`expiry_date`
       (real Menu columns, migration 2026_09_10_640100).
--}}
