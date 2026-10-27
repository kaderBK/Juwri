{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.lumiere.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalRegistry;
    use App\Services\Verticals\VerticalVocab;
    use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyComplianceService;

    /* ---------------------------------------------------------------------
       lumiere — AI-skincare beauty storefront · data preparation
       (rev/multi-vertical Lane C, cosmetic vertical)

       Mirrors shelf's own self-contained data-prep contract (never extends
       the shared food store_templates/layout.blade.php, never hardcodes
       "Menu"/"Dish"). Adds a few lumiere-only helpers (benefits/ingredients/
       rating readers, a server-rendered progress ring, "watchlist" grouping)
       that all degrade to empty/neutral output rather than fabricating data
       the platform does not actually have — see handoff/03-cosmetic-lumiere.md.
       --------------------------------------------------------------------- */
    $vocabSvc = app(VerticalVocab::class);
    $vocab    = $vocabSvc->map($store->store_type ?? null);

    // Same capability gate shelf uses for its OTC disclaimer. cosmetic itself
    // has age_gate=false (config/verticals.php), so this renders nothing for
    // every cosmetic store; it only lights up if a future age-gated vertical
    // (e.g. pharmacy) is ever pointed at this same theme.
    $showOtcDisclaimer = app(VerticalRegistry::class)->has($store->store_type ?? null, 'age_gate');
    $otcDisclaimerText = $showOtcDisclaimer ? app(PharmacyComplianceService::class)->disclaimerText($store) : null;

    $customer = auth('customer')->user();

    $items = collect($total_menus)->values();

    /* Defensive attribute readers (same shape as shelf's $attrsOf/$brandOf) —
       Lane A's brand/attributes columns may not exist yet on every install,
       so every read below is `?? null` / `is_array(...) ? ... : []`. */
    $attrsOf = \Juwri\Core\Support\ViewHelpers::attrsOf(...);
    $brandOf = \Juwri\Core\Support\ViewHelpers::brandOf(...);
    // Benefit/ingredient chips (product screen signature) — read from the
    // item's own `attributes` JSON when a dashboard operator has filled them
    // in (array OR comma/pipe-separated string, either shape). Empty when
    // absent: never a fabricated placeholder chip.
    $listAttr = function ($m, array $keys) use ($attrsOf): array {
        $attrs = $attrsOf($m);
        foreach ($keys as $key) {
            $val = $attrs[$key] ?? null;
            if (is_array($val) && ! empty($val)) {
                return array_values(array_filter(array_map('strval', $val)));
            }
            if (is_string($val) && trim($val) !== '') {
                return array_values(array_filter(array_map('trim', preg_split('/[|,]/', $val))));
            }
        }

        return [];
    };
    $benefitsOf = fn ($m) => $listAttr($m, ['benefits', 'benefit']);
    $ingredientsOf = fn ($m) => $listAttr($m, ['ingredients', 'ingredient', 'key_ingredients']);

    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelAxes(...);

    $stockBadge = \Juwri\Core\Support\ViewHelpers::stockBadge(...);

    // P1 currency contract — same shape every kept theme's index.blade.php
    // reads (see shelf ~L158-168), so client-computed totals (cart subtotal,
    // stepper line totals) render with this store's real symbol/side/gap/
    // decimals rather than a hardcoded "$".
    $curSignRaw  = $postOptions->currency_sign ?? config('settings.currency_sign', '$');
    $curSign     = cur_isolate($curSignRaw);
    $curGap      = currency_symbol_gap($curSignRaw);
    $curPos      = (string) currency_pos_effective($store->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);

    // Real tax contract (Order::computeTax) surfaced to the basket/checkout so
    // the Total matches the store's actual charge, same as the track receipt.
    $taxCharge = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $taxType   = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');

    $fulfillment = app(FulfillmentRegistry::class);
    $modes = \Juwri\Core\Support\ViewHelpers::fulfillmentModes($fulfillment, $store->store_type ?? null);

    // Server-rendered circular progress ring (signature "skin-analysis ring"),
    // mirrored 1:1 by ringSvg() in the script block for client-side re-renders
    // (after a scan, on the calendar screen). SVG stroke-dashoffset, no canvas.
    $ring = function (int $pct, int $size = 74, int $stroke = 8): string {
        $pct = max(0, min(100, $pct));
        $r = ($size - $stroke) / 2;
        $c = 2 * M_PI * $r;
        $off = $c * (1 - $pct / 100);

        return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'" aria-hidden="true">'
            .'<circle cx="'.($size / 2).'" cy="'.($size / 2).'" r="'.$r.'" fill="none" stroke="var(--lm-hair)" stroke-width="'.$stroke.'"/>'
            .'<circle cx="'.($size / 2).'" cy="'.($size / 2).'" r="'.$r.'" fill="none" stroke="var(--lm-accent)" stroke-width="'.$stroke.'" '
            .'stroke-linecap="round" stroke-dasharray="'.$c.'" stroke-dashoffset="'.$off.'" style="transition:stroke-dashoffset .6s ease"/>'
            .'</svg>';
    };

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

        // Product gallery — primary image first, then every extra
        // gallery_images entry, all as public URLs and de-duped. Powers the
        // product-detail thumbnail strip (rendered only when ≥2 images).
        $primaryUrl = ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '';
        $gallery = [];
        if ($primaryUrl !== '') {
            $gallery[] = $primaryUrl;
        }
        foreach ((array) ($m->gallery_images ?? []) as $gimg) {
            if (! empty($gimg) && $gimg !== 'default.png') {
                $gurl = media_url('storage/menu/', $gimg, $store->id ?? null);
                if ($gurl !== '' && ! in_array($gurl, $gallery, true)) {
                    $gallery[] = $gurl;
                }
            }
        }

        $jsItems[$m->id] = [
            'id' => $m->id,
            'cat' => (string) ($m->category_id ?? ''),
            'name' => $m->name,
            'desc' => strip_tags((string) $m->description),
            // Only a REAL uploaded image — never the food-themed default.png.
            'img' => $primaryUrl,
            'gallery' => $gallery,
            'price' => (float) $m->price,
            'priceFormatted' => price_symbol_format($m->price),
            'brand' => $brandOf($m),
            'benefits' => $benefitsOf($m),
            'ingredients' => $ingredientsOf($m),
            'rating' => isset($m->rating_avg) && $m->rating_avg > 0 ? round((float) $m->rating_avg, 1) : null,
            'ratingCount' => (int) ($m->rating_count ?? 0),
            'isPopular' => (bool) ($m->is_popular ?? false),
            'soldOut' => (bool) $m->is_sold_out,
            'trackInventory' => (bool) ($m->track_inventory ?? false),
            'stockQty' => isset($m->stock_qty) ? (int) $m->stock_qty : null,
            'stockBadge' => $stockBadge((bool) ($m->track_inventory ?? false), isset($m->stock_qty) ? (int) $m->stock_qty : null),
            'variants' => $variants,
            // Native goods cart endpoint for THIS item (Lane B) — a real
            // CartLine, not only this theme's own client-side preview array.
            'addRoute' => route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $m->id]),
        ];
    }

    // "Today's Self-Care Watchlist" — real `is_popular` items (same signal
    // aurora/crave sort "Popular" by); falls back to the first few catalog
    // items so the row never disappears on a fresh store with nothing
    // marked popular yet. "Best solution product" is the full catalog grid.
    $watchItems = $items->filter(fn ($m) => (bool) ($m->is_popular ?? false))->values();
    if ($watchItems->isEmpty()) {
        $watchItems = $items->take(6)->values();
    }
    $recoItems = ($watchItems->isNotEmpty() ? $watchItems : $items)->take(3)->values();

    $checkoutRoute = route('storefront.checkout.place', ['slug' => $store->slug]);
    $cartLineRouteTemplate = route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => 999999999]);
    $slotRoute = route('store.scheduleSlots', $store->id);

    // Skin-scan / routine-profile endpoints are a NEW, optional surface (see
    // handoff/03-cosmetic-lumiere.md "Signature components") — they may not
    // exist on every install yet, so every call site is gated on Route::has()
    // and degrades to a plausible client-side mock / localStorage instead.
    $skinScanRoute = Route::has('skin.scan') ? route('skin.scan', ['slug' => $store->slug]) : null;
    $skinProfileRoute = Route::has('skin.profile') ? route('skin.profile', ['slug' => $store->slug]) : null;

    // Feature-wiring pack (see scratchpad FEATURE-WIRING.md): stories rail,
    // item reviews and loyalty all reuse existing public/auth:customer JSON
    // endpoints — nothing here is a new backend surface.
    $storiesRoute = url('r/'.$store->id.'/stories');
    // Item-reviews base: url('r/'.$store->id.'/item')
    $mbrBase = url('r/'.$store->id.'/item');
    $loyaltyBalanceRoute = route('publicView.loyalty.balance', $store->id);
    // Public, no-auth teaser (program/earn/reward strings) — decoded stdClass,
    // may be null if the store has no loyalty program configured yet.
    $loy = store_options($store->id, 'loyalty_teaser');
    $loyTeaser = $loy ? (array) $loy : null;

    // Language switcher (profile screen) — same session-vs-URL-prefix
    // handling as store_templates/_partials/account-extras.blade.php. Unlike
    // that partial's own `$languages` (a controller-supplied Language
    // collection), this self-contained theme reads the same list off the
    // get_active_languages() helper directly — which returns a PLAIN ARRAY
    // keyed by code (`['ar' => ['name' => ..., ...], ...]`), not objects, so
    // it is mapped into {code, name} objects before the code-keyed filter.
    // Languages come from THIS store's configured, active menu_languages (the
    // same set StoreController uses to pick the render locale) — NOT the platform
    // language list. A store with 0/1 languages shows no switcher, and every
    // option offered is one the storefront can actually render.
    $lmLanguages = collect($menu_languages ?? [])
        ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
        ->values();
@endphp

@push('style')
@include('store_templates.lumiere.partials._styles')
@endpush

@section('content')
<div class="lm-app" id="lmApp" data-store-id="{{ $store->id }}" data-theme-slug="lumiere" dir="{{ current_language()->direction }}">

    {{-- Feature vars declared in section scope so every included partial inherits
         them (a child view top-level php-block local is not shared into the section). --}}
    @php
        $storiesRoute = url('r/'.$store->id.'/stories');
        $mbrBase = url('r/'.$store->id.'/item');
        $loyaltyBalanceRoute = route('publicView.loyalty.balance', $store->id);
        $loyRaw = store_options($store->id, 'loyalty_teaser');
        $loyTeaser = $loyRaw ? (array) $loyRaw : null;
        $lmLanguages = collect($menu_languages ?? [])
            ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
            ->values();
        // Crave-parity features (same backend data the food themes read).
        $lmIsOpen = store_open_now($store);
        $lmHoursToday = storefront_hours_today($store);
        $lmBranches = collect($branches ?? []);
        $lmShowBranches = $lmBranches->count() > 1 && (($postOptions->allow_multi_branch_switch ?? 1) != '0');
        $lmActiveBranch = $activeBranch ?? null;
        $lmBanners = \Juwri\Core\Support\ViewHelpers::activeBanners($menuBanners ?? []);
        $lmCategories = collect($store->menu_categories ?? [])
            ->map(fn ($c) => (object) ['id' => (string) $c->id, 'name' => $c->name])
            ->filter(fn ($c) => filled($c->name))
            ->values();
        $lmMyOrdersRoute = route('publicView.myOrders.index', $store->id);
    @endphp

    @include('store_templates.lumiere.partials._overlays')

    @include('store_templates.lumiere.partials._stories')

    @include('store_templates.lumiere.partials._topnav')

    <main id="lm-main">
        @if($showOtcDisclaimer)
            <div class="lm-disclaimer" role="note">{{ $otcDisclaimerText }}</div>
        @endif

        @include('store_templates.lumiere.partials._home')
        @include('store_templates.lumiere.partials._scan')
        @include('store_templates.lumiere.partials._scanresult')
        @include('store_templates.lumiere.partials._product')
        @include('store_templates.lumiere.partials._basket')
        @include('store_templates.lumiere.partials._checkout')
        @include('store_templates.lumiere.partials._confirm')
        @include('store_templates.lumiere.partials._calendar')
        @include('store_templates.lumiere.partials._favorites')
        @include('store_templates.lumiere.partials._orders')
        @include('store_templates.lumiere.partials._profile')
    </main>

    @include('store_templates.lumiere.partials._tabbar')

    {{-- Address map picker — lumiere-native bottom sheet driven by the shared
         window.mbMapPicker engine (opened from the checkout delivery-address
         field). lumiere is a custom layout with no shared drawer primitive, so
         the theme ships this .lm-msheet + its own open/close (see _script), and
         @includes the engine partials in the @push('script') stack below. --}}
    <div class="lm-scrim" id="lmMapScrim" hidden></div>
    <aside class="lm-msheet" id="lmMapSheet" role="dialog" aria-modal="true" aria-label="{{ ___('Choose on map') }}" aria-hidden="true" hidden inert>
        <div class="lm-msheet__head">
            <strong class="lm-msheet__title" id="lmMapTitle" tabindex="-1">{{ ___('Choose on map') }}</strong>
            <button type="button" class="lm-iconbtn" id="lmMapClose" aria-label="{{ ___('Close') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div class="lm-msheet__body">
            <div class="lm-map-canvas" id="lmMap"></div>
            <p class="lm-map-preview" id="lmMapPreview">{{ ___('Move the map to place the pin') }}</p>
        </div>
        <div class="lm-msheet__foot">
            <button type="button" class="lm-btn lm-btn-dark lm-btn-block" id="lmMapConfirm">{{ ___('Use this location') }}</button>
        </div>
    </aside>

    <div class="lm-toast" id="lmToast" role="status" aria-live="polite"></div>
</div>

{{-- Real notification centre. The theme's two visible bells (mobile #lmBellBtn,
     desktop #lmBellBtnTop) forward to this hidden proxy that the shared
     mb-notify.js engine binds — so lumiere reuses the exact backend the food
     themes use (auth diner → CustomerNotification inbox; guest → recent-orders
     from localStorage) instead of a canned toast. See _script.blade.php for the
     forward + unread-dot mirror, and _styles for the --nb-* skin. --}}
<button type="button" id="mbNotifBell" class="lm-nb-proxy" aria-hidden="true" tabindex="-1"></button>
<i id="mbNotifDot" hidden></i>
@include('store_templates._partials.notif-bell')
@endsection

@push('script')
@include('store_templates._partials.guest-orders')
{{-- Delivery-address autocomplete engine (window.mbAddrAutocomplete). --}}
@include('store_templates._partials.address-autocomplete')
{{-- Shared map picker + clear-device-data engines. lumiere is a custom layout,
     so it @includes these itself (they are NOT auto-wired by the shared
     store_templates/layout); the theme supplies its own lumiere-skinned UI +
     wiring in _script.blade.php below. --}}
@include('store_templates._partials.map-picker')
@include('store_templates._partials.clear-device-data')
@include('store_templates.lumiere.partials._script')
@endpush
