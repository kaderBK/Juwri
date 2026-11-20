{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.bookla.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;
    use Illuminate\Support\Str;

    /* ---------------------------------------------------------------------
       bookla — service-booking storefront · data preparation
       (rev/multi-vertical C3)

       Renders $total_menus as bookable SERVICES through VerticalVocab — never
       "Menu"/"Dish"/"Order food" (C5 grep-guard). `pricing_unit` and
       `turnaround_hours` (A9, Lane D's D1) DID land — see
       database/migrations/2026_09_10_800000_add_service_fields_to_menu_table.php
       and ServiceItemController's own validated() rules — so both are read
       straight off `$m` below; the `?? 'each'` / `?? null` fallbacks are kept
       only as ordinary defensive coding (an older row saved before D1 has
       neither column populated), not because the columns are still missing.
       --------------------------------------------------------------------- */
    $vocabSvc = app(VerticalVocab::class);
    $vocab    = $vocabSvc->map($store->store_type ?? null);

    // P1 currency fix — the SAME symbol/position/gap/decimals contract
    // price_symbol_format() (used for every priceFormatted below) already
    // honours, so client-computed totals (cart subtotal, per-line totals)
    // render with the right symbol/side/decimals instead of formatMoney()'s
    // old hardcoded-2-decimal, symbol-less number (crave's own currency
    // config block, index.blade.php ~L1542-1548, is the same shape).
    $curSignRaw  = $postOptions->currency_sign ?? config('settings.currency_sign', '$');
    $curSign     = cur_isolate($curSignRaw);
    $curGap      = currency_symbol_gap($curSignRaw);
    $curPos      = (string) currency_pos_effective($store->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);

    // P1 (service duration) — `turnaround_hours` (D1) is the menu table's one
    // duration field for a service item (a laundry ticket's "ready in N
    // hours", not a per-session appointment length). Read defensively: null
    // for any row saved before D1 or with no turnaround set.
    $formatDuration = \Juwri\Core\Support\ViewHelpers::formatDuration(...);

    $items = collect($total_menus)->values();

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

    $fulfillment = app(FulfillmentRegistry::class);
    $modes = \Juwri\Core\Support\ViewHelpers::fulfillmentModes($fulfillment, $store->store_type ?? null);

    $jsItems = [];
    foreach ($items as $m) {
        $root = $rootOf[$m->category_id] ?? $m->category_id;
        // Defensive: a row saved before D1 has no pricing_unit yet.
        $unit = in_array($m->pricing_unit ?? 'each', ['each', 'kg'], true) ? ($m->pricing_unit ?? 'each') : 'each';
        $turnaroundHours = isset($m->turnaround_hours) ? (int) $m->turnaround_hours : null;

        $jsItems[$m->id] = [
            'id' => $m->id,
            'cat' => $m->category_id,
            'root' => $root,
            'name' => $m->name,
            'desc' => strip_tags((string) $m->description),
            // Real uploaded image only — never the food-themed default.png.
            'img' => ($m->image && $m->image !== 'default.png') ? media_url('storage/menu/', $m->image, $store->id ?? null) : '',
            'price' => (float) $m->price,
            'priceFormatted' => price_symbol_format($m->price),
            'unit' => $unit,
            'unitLabel' => $unit === 'kg' ? ___('per kg') : ___('per item'),
            'soldOut' => (bool) $m->is_sold_out,
            // P1 — service duration (D1's turnaround_hours), null when unset.
            'turnaroundHours' => $turnaroundHours,
            'duration' => $formatDuration($turnaroundHours),
            // rev/multi-vertical Lane V (integration fix 1) — Lane D's native
            // laundry-booking endpoint for THIS service, so "Book" lands a
            // real booking line (fractional kg included) instead of only
            // living in this theme's own client-side preview array.
            'addRoute' => route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $m->id]),
        ];
    }

    // rev/multi-vertical Lane V (integration fix 1) — native booking (Lane D's
    // LaundryBookingController/ServiceBookingService) replaces the legacy
    // restaurant.submitOrder target below.
    $bookRoute = route('laundry.book.place', ['slug' => $store->slug]);
    // Laundry booking lines live on the SAME `cart_lines` rows Lane B's own
    // GoodsCartController manages (ServiceBookingService wraps
    // GoodsCartService — see its own docblock), so removing a line here
    // reuses that exact endpoint rather than needing a laundry-only one.
    // 999999999 is a placeholder for the real (server-assigned) line id.
    $cartLineRouteTemplate = route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => 999999999]);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --bk-bg:#f7f7f8; --bk-surface:#ffffff; --bk-ink:#15181d; --bk-muted:#6b7280;
        --bk-line:#e6e7eb; --bk-accent:{{ $store->color ?: '#0f9d78' }}; --bk-accent-ink:#ffffff;
        --bk-danger:#c0322a; --bk-radius:14px;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --bk-bg:#0f1115; --bk-surface:#181b21; --bk-ink:#f3f4f6; --bk-muted:#9aa1ac; --bk-line:#2a2e37;
        }
    }
    :root[data-theme="dark"]{
        --bk-bg:#0f1115; --bk-surface:#181b21; --bk-ink:#f3f4f6; --bk-muted:#9aa1ac; --bk-line:#2a2e37;
    }
    .bkl-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:20}
    .bkl-skip:focus{inset-inline-start:0}
    *{box-sizing:border-box}
    .bkl-body{margin:0;background:var(--bk-bg);color:var(--bk-ink);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;}
    .bk{max-width:820px;margin:0 auto;padding:0 14px 90px}
    .bk-head{position:sticky;top:0;z-index:6;display:flex;align-items:center;gap:10px;
        padding:12px 14px;background:var(--bk-surface);border-block-end:1px solid var(--bk-line)}
    .bk-brand{display:flex;align-items:center;gap:10px;min-width:0;flex:1 1 auto}
    .bk-logo{width:38px;height:38px;border-radius:10px;object-fit:cover;flex:0 0 auto;background:var(--bk-line)}
    .bk-title{font-weight:700;font-size:1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .bk-iconbtn{border:0;background:transparent;color:inherit;cursor:pointer;padding:8px;border-radius:10px;
        display:inline-flex;align-items:center;justify-content:center;position:relative}
    .bk-iconbtn:hover{background:color-mix(in srgb, currentColor 8%, transparent)}
    .bk-iconbtn svg{width:20px;height:20px}
    .bk-badge{position:absolute;top:2px;inset-inline-end:2px;background:var(--bk-accent);color:var(--bk-accent-ink);
        font-size:.62rem;font-weight:700;min-width:16px;height:16px;border-radius:999px;display:flex;
        align-items:center;justify-content:center;padding:0 3px}
    .bk-badge[hidden]{display:none}
    .bk-search-wrap{padding:12px 0 4px}
    .bk-search{width:100%;padding:10px 14px;border-radius:999px;border:1px solid var(--bk-line);
        background:var(--bk-surface);color:inherit;font-size:.92rem}
    .bk-cats{display:flex;gap:8px;overflow-x:auto;overflow-y:clip;padding:10px 0;scrollbar-width:thin}
    .bk-chip{flex:0 0 auto;border:1px solid var(--bk-line);background:var(--bk-surface);color:inherit;
        padding:7px 14px;border-radius:999px;font-size:.84rem;cursor:pointer;white-space:nowrap}
    .bk-chip.is-active{background:var(--bk-accent);border-color:var(--bk-accent);color:var(--bk-accent-ink);font-weight:600}
    .bk-list{display:flex;flex-direction:column;gap:10px;padding-block-start:6px}
    .bk-row{background:var(--bk-surface);border:1px solid var(--bk-line);border-radius:var(--bk-radius);
        display:flex;gap:12px;padding:10px;align-items:center}
    .bk-row__img{width:64px;height:64px;border-radius:10px;object-fit:cover;background:var(--bk-line);flex:0 0 auto}
    .bk-row__img--ph{display:flex;align-items:center;justify-content:center;color:var(--bk-muted,#9aa0a6)}
    .bk-row__body{flex:1;min-width:0}
    .bk-row__name{font-weight:600;font-size:.94rem;display:block;width:100%}
    .bk-row__name-link{background:none;border:0;padding:0;margin:0;color:inherit;text-align:start;cursor:pointer}
    .bk-row__name-link:hover,.bk-row__name-link:focus-visible{text-decoration:underline}
    .bk-row__desc{font-size:.8rem;color:var(--bk-muted);
        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:clip}
    .bk-row__price{font-weight:700;font-size:.9rem;white-space:nowrap}
    .bk-row__unit{font-size:.72rem;color:var(--bk-muted)}
    .bk-row__duration{font-size:.72rem;color:var(--bk-muted)}
    .bk-row__img-link{display:block;width:64px;height:64px;flex:0 0 auto;border-radius:10px;overflow:clip}
    .bk-row__side{display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex:0 0 auto}
    .bk-qtywrap{display:flex;align-items:center;gap:6px}
    .bk-qtywrap input{width:56px;text-align:center;padding:5px;border-radius:8px;border:1px solid var(--bk-line);
        background:var(--bk-surface);color:inherit;font-size:.84rem}
    .bk-qtywrap button{width:26px;height:26px;border-radius:7px;border:1px solid var(--bk-line);background:var(--bk-surface);color:inherit;cursor:pointer}
    .bk-add{border:0;background:var(--bk-accent);color:var(--bk-accent-ink);border-radius:10px;
        padding:6px 12px;font-size:.78rem;font-weight:600;cursor:pointer}
    .bk-add:disabled{opacity:.45;cursor:not-allowed}
    .bk-empty{padding:60px 10px;text-align:center;color:var(--bk-muted)}

    .bk-sheet-scrim{position:fixed;inset:0;background:rgba(10,12,16,.5);z-index:9;opacity:0;pointer-events:none;transition:opacity .18s ease}
    .bk-sheet-scrim.is-open{opacity:1;pointer-events:auto}
    .bk-sheet{position:fixed;inset-block:0;inset-inline-end:0;width:min(420px,100%);background:var(--bk-surface);
        color:inherit;z-index:10;transform:translateX(100%);transition:transform .22s ease;display:flex;flex-direction:column;
        box-shadow:-8px 0 30px rgba(0,0,0,.25)}
    [dir="rtl"] .bk-sheet{transform:translateX(-100%)}
    .bk-sheet.is-open{transform:translateX(0)}
    .bk-sheet__head{display:flex;align-items:center;justify-content:space-between;padding:14px;border-block-end:1px solid var(--bk-line)}
    .bk-sheet__title{font-weight:700}
    .bk-sheet__body{flex:1;overflow-y:auto;overflow-x:clip;padding:14px}
    .bk-sheet__foot{padding:14px;border-block-start:1px solid var(--bk-line)}
    .bk-cart-line{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-block-end:1px solid var(--bk-line);font-size:.88rem}
    .bk-cart-line__remove{background:transparent;border:0;color:var(--bk-danger);cursor:pointer;font-size:.78rem;padding:0}
    .bk-totalrow{display:flex;justify-content:space-between;font-weight:700;padding-block:8px}
    .bk-field{display:block;width:100%;margin-block-end:10px}
    .bk-field label{display:block;font-size:.78rem;color:var(--bk-muted);margin-block-end:4px}
    .bk-field input,.bk-field select{width:100%;padding:9px 10px;border-radius:8px;border:1px solid var(--bk-line);
        background:var(--bk-surface);color:inherit;font-size:.88rem}
    .bk-msg{font-size:.84rem;padding:8px 10px;border-radius:8px;margin-block-end:10px}
    .bk-msg--error{background:color-mix(in srgb, var(--bk-danger) 12%, transparent);color:var(--bk-danger)}
    .bk-msg--ok{background:color-mix(in srgb, #1a8f4c 14%, transparent);color:#1a8f4c}
    .bk-slots{display:flex;flex-wrap:wrap;gap:8px;margin-block:6px}
    .bk-opt{border:1px solid var(--bk-line);background:var(--bk-surface);color:inherit;border-radius:8px;
        padding:7px 12px;font-size:.84rem;cursor:pointer}
    .bk-opt.is-active{border-color:var(--bk-accent);background:color-mix(in srgb, var(--bk-accent) 14%, transparent);font-weight:600}
    .bk-opt:disabled{opacity:.4;text-decoration:line-through;cursor:not-allowed}
    .bk-btn{border:0;border-radius:10px;padding:9px 14px;font-size:.86rem;font-weight:600;cursor:pointer}
    .bk-btn--primary{background:var(--bk-accent);color:var(--bk-accent-ink)}

    /* P2: service detail sheet (mirrors shelf's item sheet shape). */
    .bk-detail-img{width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:12px;background:var(--bk-line);margin-block-end:12px}
    .bk-group{margin-block-end:14px}
    .bk-group__label{font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--bk-muted);margin-block-end:6px}
    .bk-detail-meta{font-size:.84rem;color:var(--bk-muted);margin-block-end:4px}
    .bk-qty{display:flex;align-items:center;gap:10px}
    .bk-qty button{width:30px;height:30px;border-radius:8px;border:1px solid var(--bk-line);background:var(--bk-surface);color:inherit;cursor:pointer}
    .bk-qty input{width:64px;text-align:center;padding:5px;border-radius:8px;border:1px solid var(--bk-line);background:var(--bk-surface);color:inherit;font-size:.88rem}

    /* P2: day picker feeding the time-slot list. */
    .bk-days{display:flex;gap:8px;overflow-x:auto;overflow-y:clip;padding-block:4px;margin-block-end:6px;scrollbar-width:thin}
    .bk-day{flex:0 0 auto;border:1px solid var(--bk-line);background:var(--bk-surface);color:inherit;
        border-radius:10px;padding:8px 12px;font-size:.8rem;cursor:pointer;white-space:nowrap}
    .bk-day.is-active{background:var(--bk-accent);border-color:var(--bk-accent);color:var(--bk-accent-ink);font-weight:600}
    [hidden]{display:none !important}
</style>
@endpush

@section('content')
<div class="bookla" id="bookla" data-store-id="{{ $store->id }}" data-theme-slug="bookla">
    <header class="bk-head">
        <div class="bk-brand">
            @if($store->main_image && $store->main_image !== 'default.png')
                @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img class="bk-logo" src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="">@endif
            @endif
            <span class="bk-title">{{ $store->title }}</span>
        </div>
        <button type="button" class="bk-iconbtn" id="bkDarkToggle" aria-label="{{ ___('Toggle dark mode') }}" title="{{ ___('Toggle dark mode') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
        </button>
        <button type="button" class="bk-iconbtn" id="bkCartOpen" aria-label="{{ $vocab['orders'] ?? ___('Bookings') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7V5a4 4 0 0 1 8 0v2M5 7h14l-1 13H6L5 7Z"/></svg>
            <i class="bk-badge" id="bkCartBadge" hidden>0</i>
        </button>
    </header>

    <main class="bk" id="bkl-main">
        <div class="bk-search-wrap">
            <input type="search" class="bk-search" id="bkSearch" autocomplete="off"
                   placeholder="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Services'))]) }}"
                   aria-label="{{ ___('Search :items', ['items' => Str::lower($vocab['items'] ?? ___('Services'))]) }}">
        </div>

        @if($categories->isNotEmpty())
        <nav class="bk-cats" role="tablist" aria-label="{{ $vocab['categories'] ?? ___('Categories') }}">
            <button type="button" class="bk-chip is-active" role="tab" aria-selected="true" aria-controls="bkList" data-cat="all">{{ ___('All') }}</button>
            @foreach($categories as $cat)
                <button type="button" class="bk-chip" role="tab" aria-selected="false" aria-controls="bkList" data-cat="{{ $cat->id }}">{{ $cat->name }}</button>
            @endforeach
        </nav>
        @endif

        @if($items->isEmpty())
            <p class="bk-empty">{{ ___('Nothing here yet.') }}</p>
        @else
            <div class="bk-list" id="bkList">
                @foreach($items as $m)
                    @php $jd = $jsItems[$m->id]; @endphp
                    <article class="bk-row" data-id="{{ $m->id }}" data-root="{{ $jd['root'] }}"
                             data-name="{{ Str::lower($jd['name']) }}" data-desc="{{ Str::lower($jd['desc']) }}">
                        {{-- P2: real crawlable/shareable ?item= URL, opens the new
                             service detail sheet — script-intercepted + pushState. --}}
                        <a href="?item={{ $m->id }}" class="bk-row__img-link" data-open-item-link="{{ $m->id }}" aria-label="{{ $jd['name'] }}">
                            @if($jd['img'])
                                @if((string) ($jd['img']) !== '')<img class="bk-row__img" src="{{ $jd['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
                            @else
                                <div class="bk-row__img bk-row__img--ph" role="img" aria-label="{{ $jd['name'] }}">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 7l9-4 9 4v10l-9 4-9-4V7z"/><path d="M3 7l9 4 9-4M12 11v10"/></svg>
                                </div>
                            @endif
                        </a>
                        <div class="bk-row__body">
                            <button type="button" class="bk-row__name bk-row__name-link" data-open-item-link="{{ $m->id }}">{{ $jd['name'] }}</button>
                            @if($jd['desc'])<div class="bk-row__desc">{{ $jd['desc'] }}</div>@endif
                            <div>
                                <span class="bk-row__price">{{ $jd['priceFormatted'] }}</span> <span class="bk-row__unit">{{ $jd['unitLabel'] }}</span>
                                @if($jd['duration'])<span class="bk-row__duration"> · {{ $jd['duration'] }}</span>@endif
                            </div>
                        </div>
                        <div class="bk-row__side">
                            <div class="bk-qtywrap">
                                <button type="button" class="bk-qty-minus" data-for="{{ $m->id }}" aria-label="{{ ___('Decrease') }}">&minus;</button>
                                <input type="number" class="bk-qty-input" data-for="{{ $m->id }}"
                                       value="{{ $jd['unit'] === 'kg' ? '1.0' : '1' }}"
                                       min="{{ $jd['unit'] === 'kg' ? '0.1' : '1' }}"
                                       step="{{ $jd['unit'] === 'kg' ? '0.1' : '1' }}">
                                <button type="button" class="bk-qty-plus" data-for="{{ $m->id }}" aria-label="{{ ___('Increase') }}">&plus;</button>
                            </div>
                            <button type="button" class="bk-add" data-add="{{ $m->id }}" @if($jd['soldOut']) disabled @endif>
                                {{ $vocab['add_to_cart'] ?? ___('Book') }}
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>
            <p class="bk-empty" id="bkNoResults" hidden>{{ ___('No results.') }}</p>
        @endif
    </main>

    {{-- P2: service detail sheet — description, duration, price, quantity,
         Book. ?item= deep-link boot further below (copies shelf's approach). --}}
    <div class="bk-sheet-scrim" id="bkItemScrim"></div>
    <aside class="bk-sheet" id="bkItemSheet" role="dialog" aria-modal="true" aria-label="{{ $vocab['item'] ?? ___('Service') }}" aria-hidden="true" inert>
        <div class="bk-sheet__head">
            <strong class="bk-sheet__title" id="bkItemName" tabindex="-1"></strong>
            <button type="button" class="bk-iconbtn" id="bkItemClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="bk-sheet__body">
            <img class="bk-detail-img" id="bkItemImg" alt="" data-csp-error="hide">
            <p id="bkItemDesc" style="color:var(--bk-muted);font-size:.88rem"></p>
            <p class="bk-detail-meta" id="bkItemDuration" hidden></p>

            <div class="bk-group">
                <div class="bk-group__label">{{ ___('Quantity') }}</div>
                <div class="bk-qty">
                    <button type="button" id="bkItemQtyMinus" aria-label="{{ ___('Decrease') }}">&minus;</button>
                    <input type="number" id="bkItemQtyInput" value="1" min="1" step="1">
                    <button type="button" id="bkItemQtyPlus" aria-label="{{ ___('Increase') }}">&plus;</button>
                </div>
            </div>
        </div>
        <div class="bk-sheet__foot">
            <button type="button" class="bk-btn bk-btn--primary" id="bkItemAdd" style="width:100%">
                <span id="bkItemAddLabel">{{ $vocab['add_to_cart'] ?? ___('Book') }}</span>
                <b id="bkItemAddPrice"></b>
            </button>
        </div>
    </aside>

    {{-- Booking cart + checkout sheet — posts straight to Lane D's native
         laundry booking endpoint (LaundryBookingController/
         ServiceBookingService), the same store-side booking cart each
         service's "Book" fills via its own `addRoute` (Lane V integration
         fix 1). --}}
    <div class="bk-sheet-scrim" id="bkCartScrim"></div>
    <aside class="bk-sheet" id="bkCartSheet" role="dialog" aria-modal="true" aria-label="{{ $vocab['orders'] ?? ___('Bookings') }}" aria-hidden="true" inert>
        <div class="bk-sheet__head">
            <strong class="bk-sheet__title" id="bkCartTitle" tabindex="-1">{{ $vocab['orders'] ?? ___('Bookings') }}</strong>
            <button type="button" class="bk-iconbtn" id="bkCartClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div class="bk-sheet__body">
            <div id="bkCartView">
                <div id="bkCartLines"></div>
                <p class="bk-empty" id="bkCartEmpty">{{ ___('Your cart is empty.') }}</p>
                <div class="bk-totalrow" id="bkCartTotalRow" hidden>
                    <span>{{ ___('Subtotal') }}</span>
                    <span id="bkCartTotal"></span>
                </div>
            </div>

            <form id="bkCheckoutForm" hidden>
                <div id="bkCheckoutMsg"></div>
                <div class="bk-field">
                    <label for="bkName">{{ ___('Name') }}</label>
                    <input type="text" id="bkName" name="name" required maxlength="255">
                </div>
                <div class="bk-field">
                    <label for="bkPhone">{{ ___('Phone') }}</label>
                    <input type="tel" id="bkPhone" name="phone" required maxlength="25">
                </div>
                @if($modes->count() > 1)
                <div class="bk-field">
                    <label for="bkMode">{{ ___('Fulfillment') }}</label>
                    <select id="bkMode" name="mode">
                        @foreach($modes as $mode)
                            <option value="{{ $mode['value'] }}">{{ $mode['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="bk-field">
                    <label for="bkAddress">{{ ___('Pickup address') }}</label>
                    <input type="text" id="bkAddress" name="address" required maxlength="255">
                </div>
                <div class="bk-field">
                    {{-- P2: a day picker feeds the time-slot list below — slots
                         used to load only once, for whatever day the server
                         defaulted to, with no way to pick another. --}}
                    <label>{{ ___('Pick a day') }}</label>
                    <div class="bk-days" id="bkDays"></div>
                </div>
                <div class="bk-field">
                    <label>{{ ___('Pick a time') }}</label>
                    <div class="bk-slots" id="bkSlots"></div>
                </div>
                <button type="button" class="bk-btn bk-btn--primary" id="bkPlaceOrder" style="width:100%">
                    {{ ___('Book') }}
                </button>
            </form>
        </div>
        <div class="bk-sheet__foot" id="bkCartFoot">
            <button type="button" class="bk-btn bk-btn--primary" id="bkCheckoutBtn" style="width:100%" disabled>
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
    // rev/multi-vertical Lane V (integration fix 1) — native laundry booking
    // (Lane D's LaundryBookingController). Each service's own `addRoute` (see
    // ITEMS above) posts to the same controller's addLine endpoint.
    var BOOK_ROUTE = @json($bookRoute);
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
        addToCart: @json($vocab['add_to_cart'] ?? ___('Book')),
        cartEmpty: @json(___('Your cart is empty.')),
        placing: @json(___('Placing order…')),
        placed: @json(___('Booking confirmed! Thank you.')),
        genericError: @json(___('Something went wrong. Please try again.')),
        nameRequired: @json(___('Please enter your name.')),
        addressRequired: @json(___('Please enter a pickup / delivery address.')),
        kg: @json(___('kg')),
    };

    var root = document.getElementById('bookla');
    if (!root) { return; }

    /* ---------------- dark mode ---------------- */
    var darkKey = 'bookla-theme-' + (root.getAttribute('data-store-id') || '');
    try {
        var saved = window.localStorage.getItem(darkKey);
        if (saved) { document.documentElement.setAttribute('data-theme', saved); }
    } catch (e) { /* storage may be unavailable — default (system) stands */ }
    var darkBtn = document.getElementById('bkDarkToggle');
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
       Same single-entry pattern as shelf (each sheet has its own full-screen
       scrim, so only one is ever open at once) — mirrors the orbit/velora
       convention (assets/store_templates/orbit/js/script.js ~L211-259): one
       guard history entry per "sheet session", Escape/scrim/× route through
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
            try { history.pushState({ bkSheet: 1 }, '', url || undefined); } catch (e) { /* best-effort */ }
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
    var list = document.getElementById('bkList');
    var rows = list ? Array.prototype.slice.call(list.querySelectorAll('.bk-row')) : [];
    var noResults = document.getElementById('bkNoResults');
    var activeCat = 'all';
    var query = '';

    function applyFilter() {
        var visible = 0;
        rows.forEach(function (row) {
            var matchesCat = activeCat === 'all' || row.getAttribute('data-root') === activeCat;
            var matchesQuery = !query
                || row.getAttribute('data-name').indexOf(query) !== -1
                || row.getAttribute('data-desc').indexOf(query) !== -1;
            var show = matchesCat && matchesQuery;
            row.hidden = !show;
            if (show) { visible++; }
        });
        if (noResults) { noResults.hidden = visible !== 0; }
    }

    document.querySelectorAll('.bk-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            document.querySelectorAll('.bk-chip').forEach(function (c) {
                c.classList.remove('is-active');
                c.setAttribute('aria-selected', 'false');
            });
            chip.classList.add('is-active');
            chip.setAttribute('aria-selected', 'true');
            activeCat = chip.getAttribute('data-cat');
            applyFilter();
        });
    });
    var searchInput = document.getElementById('bkSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            query = searchInput.value.trim().toLowerCase();
            applyFilter();
        });
    }

    /* ---------------- qty steppers ---------------- */
    function stepFor(id) {
        var item = ITEMS[id];
        return item && item.unit === 'kg' ? 0.1 : 1;
    }
    document.querySelectorAll('.bk-qty-minus').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-for');
            var input = list.querySelector('.bk-qty-input[data-for="' + id + '"]');
            var step = stepFor(id);
            var val = Math.max(step, (parseFloat(input.value) || step) - step);
            input.value = step < 1 ? val.toFixed(1) : String(Math.round(val));
        });
    });
    document.querySelectorAll('.bk-qty-plus').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-for');
            var input = list.querySelector('.bk-qty-input[data-for="' + id + '"]');
            var step = stepFor(id);
            var val = (parseFloat(input.value) || 0) + step;
            input.value = step < 1 ? val.toFixed(1) : String(Math.round(val));
        });
    });

    /* ---------------- cart state ---------------- */
    var CART_KEY = 'bookla-cart-' + (root.getAttribute('data-store-id') || '');
    var cart = [];
    try {
        var storedCart = window.localStorage.getItem(CART_KEY);
        if (storedCart) { cart = JSON.parse(storedCart) || []; }
    } catch (e) { cart = []; }

    function saveCart() {
        try { window.localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) { /* best-effort */ }
    }

    /* rev/multi-vertical Lane V (integration fix 1) — lands the line on Lane
       D's real store-side booking cart (ServiceBookingService, which keeps a
       fractional kg weight rather than rounding it to a garment count) so
       checkout below has an actual booking to place; this theme's own `cart`
       array stays a client-side preview of the same lines. */
    function postAddLine(itemId, qty) {
        var item = ITEMS[itemId];
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('quantity', qty);
        return fetch(item.addRoute, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'add-line failed');
                }
                return data;
            });
    }

    // P3 — sum of QUANTITIES, not the number of lines: a cart with one line at
    // qty 3 previously badged "1", undercounting the header badge.
    function cartCount() {
        return cart.reduce(function (n, l) { return n + (Number(l.qty) || 0); }, 0);
    }
    function formatMoney(n) {
        // P1 — mirrors price_symbol_format() server-side: the store's real
        // symbol, side (before/after), gap (dirham spacing) and per-currency
        // decimal count, not a hardcoded "$"-less 2-decimal number.
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
        var badge = document.getElementById('bkCartBadge');
        if (!badge) { return; }
        var n = cartCount();
        // A kg line's qty is fractional (e.g. 1.5) — round only for the small
        // numeric badge display, not for any actual booking math.
        badge.textContent = String(Math.round(n));
        badge.hidden = n === 0;
    }
    function renderCart() {
        var linesEl = document.getElementById('bkCartLines');
        var emptyEl = document.getElementById('bkCartEmpty');
        var totalRow = document.getElementById('bkCartTotalRow');
        var totalEl = document.getElementById('bkCartTotal');
        var checkoutBtn = document.getElementById('bkCheckoutBtn');
        linesEl.innerHTML = '';
        if (!cart.length) {
            emptyEl.hidden = false;
            totalRow.hidden = true;
            checkoutBtn.disabled = true;
            renderCartBadge();
            return;
        }
        emptyEl.hidden = true;
        totalRow.hidden = false;
        checkoutBtn.disabled = false;

        var sum = 0;
        cart.forEach(function (line, idx) {
            var item = ITEMS[line.id];
            if (!item) { return; }
            var lt = item.price * line.qty;
            sum += lt;
            var row = document.createElement('div');
            row.className = 'bk-cart-line';
            var left = document.createElement('span');
            left.textContent = item.name + ' × ' + line.qty + (item.unit === 'kg' ? ' ' + T.kg : '');
            var right = document.createElement('span');
            right.textContent = formatMoney(lt);
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'bk-cart-line__remove';
            rm.textContent = @json(___('Remove'));
            rm.addEventListener('click', function () {
                var removedLineId = line.lineId;
                cart.splice(idx, 1);
                saveCart();
                renderCart();
                // Best-effort: zero the line out on the real server-side
                // booking cart too, so a removed service doesn't still book.
                if (removedLineId) {
                    var fd = new FormData();
                    fd.append('_token', CSRF);
                    fd.append('quantity', '0');
                    fetch(cartLineRoute(removedLineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                        .catch(function () { /* best-effort */ });
                }
            });
            var wrap = document.createElement('span');
            wrap.appendChild(right);
            wrap.appendChild(document.createTextNode(' '));
            wrap.appendChild(rm);
            row.appendChild(left);
            row.appendChild(wrap);
            linesEl.appendChild(row);
        });
        totalEl.textContent = formatMoney(sum);
        renderCartBadge();
    }

    document.querySelectorAll('[data-add]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-add'), 10);
            var input = list.querySelector('.bk-qty-input[data-for="' + id + '"]');
            var qty = parseFloat(input.value) || stepFor(id);
            btn.disabled = true;
            postAddLine(id, qty)
                .then(function (data) {
                    var existing = cart.filter(function (l) { return l.id === id; })[0];
                    if (existing) {
                        existing.qty += qty;
                        existing.lineId = data.line_id;
                    } else {
                        cart.push({ id: id, qty: qty, lineId: data.line_id });
                    }
                    saveCart();
                    renderCart();
                    openCart();
                })
                .catch(function () {
                    window.alert(T.genericError);
                })
                .then(function () { btn.disabled = false; });
        });
    });

    renderCart();

    /* ---------------- P2: service detail sheet + ?item= deep link ----------------
       Mirrors shelf's item sheet: description, duration (D1's
       turnaround_hours), a unit-aware quantity stepper (0.1 kg step vs. whole
       "each" step, matching the row's own bk-qty-* steppers), and Book — which
       lands the SAME booking line the row's own "Book" button does. */
    var itemScrim = document.getElementById('bkItemScrim');
    var itemSheet = document.getElementById('bkItemSheet');
    var currentItem = null;

    function itemStep() {
        return currentItem && currentItem.unit === 'kg' ? 0.1 : 1;
    }
    function itemQtyInput() { return document.getElementById('bkItemQtyInput'); }

    function updateItemPrice() {
        var priceEl = document.getElementById('bkItemAddPrice');
        var qty = parseFloat(itemQtyInput().value) || itemStep();
        priceEl.textContent = formatMoney(currentItem.price * qty);
    }

    function openItem(id) {
        var item = ITEMS[id];
        if (!item) { return; }
        currentItem = item;
        document.getElementById('bkItemName').textContent = item.name;
        var img = document.getElementById('bkItemImg');
        if (item.img) { img.src = item.img; img.alt = item.name; img.hidden = false; }
        else { img.removeAttribute('src'); img.hidden = true; }
        document.getElementById('bkItemDesc').textContent = item.desc;
        var durationEl = document.getElementById('bkItemDuration');
        if (item.duration) { durationEl.textContent = item.duration; durationEl.hidden = false; }
        else { durationEl.hidden = true; }
        var qtyInput = itemQtyInput();
        var step = item.unit === 'kg' ? 0.1 : 1;
        qtyInput.min = String(step);
        qtyInput.step = String(step);
        qtyInput.value = item.unit === 'kg' ? '1.0' : '1';
        updateItemPrice();
        document.getElementById('bkItemAdd').disabled = !!item.soldOut;

        // P2: a real ?item= URL while the sheet is open (shareable/crawlable),
        // carried on the SAME pushState openA11ySheet does for the back-close
        // guard — no separate history entry.
        var itemUrl = window.location.pathname + '?item=' + encodeURIComponent(String(id));
        openA11ySheet(itemSheet, itemScrim, document.getElementById('bkItemName'), itemUrl);
    }
    function closeItem() { dismissA11ySheet(); }

    document.getElementById('bkItemClose').addEventListener('click', closeItem);
    itemScrim.addEventListener('click', closeItem);

    document.getElementById('bkItemQtyMinus').addEventListener('click', function () {
        var input = itemQtyInput();
        var step = itemStep();
        var val = Math.max(step, (parseFloat(input.value) || step) - step);
        input.value = step < 1 ? val.toFixed(1) : String(Math.round(val));
        updateItemPrice();
    });
    document.getElementById('bkItemQtyPlus').addEventListener('click', function () {
        var input = itemQtyInput();
        var step = itemStep();
        var val = (parseFloat(input.value) || 0) + step;
        input.value = step < 1 ? val.toFixed(1) : String(Math.round(val));
        updateItemPrice();
    });
    itemQtyInput().addEventListener('input', updateItemPrice);

    document.getElementById('bkItemAdd').addEventListener('click', function () {
        if (!currentItem) { return; }
        var id = currentItem.id;
        var qty = parseFloat(itemQtyInput().value) || itemStep();
        var addBtn = document.getElementById('bkItemAdd');
        addBtn.disabled = true;
        postAddLine(id, qty)
            .then(function (data) {
                var existing = cart.filter(function (l) { return l.id === id; })[0];
                if (existing) {
                    existing.qty += qty;
                    existing.lineId = data.line_id;
                } else {
                    cart.push({ id: id, qty: qty, lineId: data.line_id });
                }
                saveCart();
                renderCart();
                // Switching sheets (item → cart), not a user dismiss — close
                // the item sheet RAW so it doesn't consume/duplicate the
                // back-close history guard openCart() below is about to (re)use.
                rawCloseA11ySheet();
                openCart();
            })
            .catch(function () { window.alert(T.genericError); })
            .then(function () { addBtn.disabled = false; });
    });

    // P2 — the row's image/name link opens this same detail sheet, also
    // giving each service a real, crawlable `?item=` URL.
    document.querySelectorAll('[data-open-item-link]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            openItem(parseInt(link.getAttribute('data-open-item-link'), 10));
        });
    });

    // Deep link: ?item=<id> opens the detail sheet on load (same convention
    // shelf and the rest of the storefront use).
    try {
        var itemParams = new URLSearchParams(window.location.search);
        var deepId = parseInt(itemParams.get('item'), 10);
        if (deepId && ITEMS[deepId]) { openItem(deepId); }
    } catch (e) { /* no-op on an unsupported/old browser */ }

    /* ---------------- cart sheet + checkout ---------------- */
    var cartScrim = document.getElementById('bkCartScrim');
    var cartSheet = document.getElementById('bkCartSheet');
    function openCart() {
        renderCart();
        openA11ySheet(cartSheet, cartScrim, document.getElementById('bkCartTitle'));
    }
    function closeCart() {
        dismissA11ySheet();
    }
    document.getElementById('bkCartOpen').addEventListener('click', openCart);
    document.getElementById('bkCartClose').addEventListener('click', closeCart);
    cartScrim.addEventListener('click', closeCart);

    // P3 — the selected fulfillment mode's own value, not a hardcoded food
    // term: bookla's modes are pickup_slot/delivery/appointment
    // (config/verticals.php), never "takeaway".
    function selectedModeValue() {
        var select = document.getElementById('bkMode');
        if (select) { return select.value; }
        return (MODES[0] || {}).value || 'pickup_slot';
    }

    function dayLabel(d) {
        try {
            return new Date(d + 'T00:00:00').toLocaleDateString(document.documentElement.lang || undefined, {
                weekday: 'short', day: 'numeric', month: 'short',
            });
        } catch (e) { return d; }
    }

    var chosenSlot = null;
    var chosenDay = null;
    function renderSlotsFor(slots) {
        var wrap = document.getElementById('bkSlots');
        if (!wrap) { return; }
        wrap.innerHTML = '';
        chosenSlot = null;
        (slots || []).slice(0, 8).forEach(function (slot) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'bk-opt';
            b.textContent = slot.label;
            b.disabled = !!slot.full;
            b.addEventListener('click', function () {
                chosenSlot = slot.value;
                wrap.querySelectorAll('.bk-opt').forEach(function (x) { x.classList.remove('is-active'); });
                b.classList.add('is-active');
            });
            wrap.appendChild(b);
        });
    }

    // P2 — a day picker feeding the time-slot list: slots used to load only
    // once, for whatever day the server defaulted to, with no way to pick
    // another day. scheduleSlots() (StoreController) already returns the full
    // selectable `dates` list alongside one day's `slots`, so a day chip just
    // re-fetches with `&date=`.
    function loadSlotsForDay(date) {
        var daysWrap = document.getElementById('bkDays');
        fetch(SLOT_ROUTE + '?type=' + encodeURIComponent(selectedModeValue()) + (date ? '&date=' + encodeURIComponent(date) : ''), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.enabled) {
                    if (daysWrap) { daysWrap.innerHTML = ''; }
                    renderSlotsFor([]);
                    return;
                }
                chosenDay = data.date || date || null;
                if (daysWrap) {
                    daysWrap.innerHTML = '';
                    (data.dates || []).forEach(function (d) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'bk-day' + (d === chosenDay ? ' is-active' : '');
                        btn.textContent = dayLabel(d);
                        btn.addEventListener('click', function () { loadSlotsForDay(d); });
                        daysWrap.appendChild(btn);
                    });
                }
                renderSlotsFor(data.slots);
            })
            .catch(function () { /* best-effort — booking still works without a slot */ });
    }
    function loadSlots() { loadSlotsForDay(null); }

    document.getElementById('bkCheckoutBtn').addEventListener('click', function () {
        document.getElementById('bkCartView').hidden = true;
        document.getElementById('bkCheckoutForm').hidden = false;
        document.getElementById('bkCartFoot').hidden = true;
        loadSlots();
    });

    document.getElementById('bkPlaceOrder').addEventListener('click', function () {
        var msg = document.getElementById('bkCheckoutMsg');
        msg.innerHTML = '';
        if (!cart.length) {
            msg.innerHTML = '<div class="bk-msg bk-msg--error">' + T.cartEmpty + '</div>';
            return;
        }
        var name = document.getElementById('bkName').value.trim();
        var phone = document.getElementById('bkPhone').value.trim();
        var address = document.getElementById('bkAddress').value.trim();
        // Phone is optional (the server accepts a booking without it). Name and a
        // pickup/delivery address are required, each with a SPECIFIC message rather
        // than the generic "something went wrong".
        if (!name) {
            msg.innerHTML = '<div class="bk-msg bk-msg--error">' + T.nameRequired + '</div>';
            return;
        }
        if (!address) {
            msg.innerHTML = '<div class="bk-msg bk-msg--error">' + T.addressRequired + '</div>';
            return;
        }
        var modeSelect = document.getElementById('bkMode');
        var modeValue = modeSelect ? modeSelect.value : ((MODES[0] || {}).value || 'pickup_slot');

        // rev/multi-vertical Lane V (integration fix 1) — the store-side
        // booking cart (Lane D's ServiceBookingService) already holds these
        // lines, added one at a time via each service's `addRoute` (with the
        // real fractional kg weight, never rounded); booking just needs the
        // mode + customer/address/slot fields.
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('customer_name', name);
        fd.append('phone_number', phone);
        fd.append('type', modeValue);
        fd.append('address', address);
        if (chosenSlot) { fd.append('scheduled_at', chosenSlot); }

        msg.innerHTML = '<div class="bk-msg">' + T.placing + '</div>';
        fetch(BOOK_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    msg.innerHTML = '<div class="bk-msg bk-msg--ok">' + T.placed + '</div>';
                    cart = [];
                    saveCart();
                    renderCart();
                } else {
                    msg.innerHTML = '<div class="bk-msg bk-msg--error"></div>'; msg.firstChild.textContent = (data && data.message) || T.genericError;
                }
            })
            .catch(function () {
                msg.innerHTML = '<div class="bk-msg bk-msg--error">' + T.genericError + '</div>';
            });
    });
})();
</script>
@endpush
