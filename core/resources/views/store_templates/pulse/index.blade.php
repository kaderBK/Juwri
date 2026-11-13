{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    use Illuminate\Support\Str;

    $psLogo = media_url('storage/store/logo/', (string) ($store->main_image ?? ''), $store->id ?? null);
    $allMenus = collect($total_menus)->values();
    $items = $allMenus->values();
    $byCat = $items->groupBy('category_id');
    $categories = $store->menu_categories->values();
    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

    $featured = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    $specials = $items->take(8);

    $curSign = $postOptions->currency_sign ?? '$';
    $curPos  = (string) ($postOptions->currency_pos ?? '1');
    $deliveryCharge = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge      = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $minOrderValue  = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched = !empty($postOptions->allow_order_scheduling);

    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelNamed(...);

    $jsItems = [];
    foreach ($items as $m) {
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
                $extras[] = ['id' => $e->id, 'title' => $e->title, 'price' => (float) $e->price];
            }
        }
        $jsItems[$m->id] = [
            'id'        => $m->id,
            'name'      => $m->name,
            'desc'      => strip_tags((string) $m->description),
            'img'       => media_url('storage/menu/', $m->image, $store->id ?? null),
            'price'     => (float) $m->price,
            'type'      => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyList($itemAllergies),
            'variants'  => $variants,
            'extras'    => $extras,
            'is_vegan'       => (bool) $m->is_vegan,
            'is_halal'       => (bool) $m->is_halal,
            'is_gluten_free' => (bool) $m->is_gluten_free,
            'is_popular'     => (bool) $m->is_popular,
            'is_daily_special' => (bool) ($m->is_daily_special ?? false),
            'spice_level'    => (int) $m->spice_level,
            'is_sold_out'    => (bool) $m->is_sold_out,
            'available_from' => $m->available_from ?? null,
            'available_to'   => $m->available_to ?? null,
            'available_days' => $m->available_days ?? null,
            'available_now' => ($m->available_now ?? true),                 // T5: server-resolved (store clock, category window, order type)
            'available_from_label' => ($m->available_from_label ?? null),          // T5: "available from HH:MM"
            'original_price' => ($m->original_price ?? null),               // T5: pre-happy-hour price, for a strike-through
            'schedule_label' => ($m->schedule_label ?? null),               // T5: "Happy Hour" badge
            'calories'       => $m->calories ?? null,
            'protein'        => $m->protein ?? null,
            'carbs'          => $m->carbs ?? null,
            'fat'            => $m->fat ?? null,
            'gallery' => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryUrls($m->gallery_images, $store->id ?? null)
                : [],
        ];
    }

    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();
    // Coupons & Wallets: pay-with-credit for a logged-in diner with balance.
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;
    $customerDefaultAddress = $customer
        ? $customer->addresses()->orderByDesc('is_default')->orderBy('id')->first()
        : null;

    $allow_on_table = !empty($postOptions->allow_on_table_order);
    $allow_takeaway = !empty($postOptions->allow_takeaway_order);
    $allow_delivery = !empty($postOptions->allow_delivery_order);
    $allow_order    = !empty($postOptions->allow_order);
    $allow_payment  = !empty($postOptions->allow_online_payment);
    $forceTable     = !empty($postOptions->force_table_selection);
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="pulse" id="pulse">
    <div class="ps-mesh"></div>
    <div class="ps-orbs">
        <div class="ps-orb ps-orb-1"></div>
        <div class="ps-orb ps-orb-2"></div>
        <div class="ps-orb ps-orb-3"></div>
    </div>

    {{-- Social proof banner --}}
    @if(!empty($scans_today) && $scans_today > 0)
    <div class="ps-social-proof">
        <span class="ps-sp-dot"></span>
        {{ $scans_today }} {{ ___('people viewing this menu right now') }}
    </div>
    @endif

    {{-- ============================================================ TOP BAR --}}
    <header class="ps-topbar" id="ps-topbar">
        <div class="ps-wrap">
            <a href="#pulse" class="ps-brand">
                @if($psLogo !== '')<img src="{{ $psLogo }}" alt="{{ $store->title }}" data-csp-error="hide">@endif
                <b>{{ $store->title }}</b>
            </a>
            <div class="ps-topbar-actions">
                {{-- Language switcher --}}
                @if(!empty($menu_languages) && count($menu_languages) > 1)
                    <div class="ps-dropdown" id="ps-lang">
                        <button class="ps-icon-btn" type="button" id="ps-lang-toggle" aria-label="{{ ___('Language') }}">
                            <i class="ico ico-world" aria-hidden="true"><x-ui.icon name="world" /></i>
                        </button>
                        <div class="ps-dropdown-menu">
                            @foreach($menu_languages as $language)
                                <button type="button" data-code="{{ $language->code }}" data-lang="{{ $language->name }}">{{ $language->name }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
                {{-- Multi-currency --}}
                @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
                <div class="ps-dropdown" id="ps-currency-wrap">
                    <button class="ps-icon-btn" type="button" id="ps-currency-toggle" aria-label="{{ ___('Currency') }}" style="font-size:11px;font-weight:700;min-width:36px">
                        <span id="ps-cur-label">{{ config('settings.currency_code', '—') }}</span>
                    </button>
                    <div class="ps-dropdown-menu">
                        <button type="button" data-cur="{{ config('settings.currency_code', 'USD') }}" data-rate="1">{{ config('settings.currency_code', 'USD') }}</button>
                        @foreach($mcRates as $code => $rate)
                            <button type="button" data-cur="{{ $code }}" data-rate="{{ $rate }}">{{ $code }}</button>
                        @endforeach
                    </div>
                </div>
                @endif
                {{-- Dark/light toggle --}}
                <button class="ps-icon-btn" type="button" id="ps-theme-toggle" aria-label="{{ ___('Toggle light/dark mode') }}">
                    <i class="ico ico-moon" id="ps-theme-icon" aria-hidden="true"><x-ui.icon name="moon" /></i>
                </button>
                {{-- Favorites --}}
                <button class="ps-icon-btn" type="button" id="ps-fav-toggle" aria-label="{{ ___('Favorites') }}">
                    <i class="ico ico-heart-filled" aria-hidden="true"><x-ui.icon name="heart-filled" /></i>
                    <span class="ps-badge-count" id="ps-fav-count">0</span>
                </button>
                {{-- Account --}}
                @if($authMode !== 'guest_only')
                    @if($customer)
                        <div class="ps-dropdown" id="ps-account-menu">
                            <button class="ps-icon-btn" type="button" aria-label="{{ ___('Account') }}">
                                <i class="ico ico-user-check" style="color:var(--ps-accent)" aria-hidden="true"><x-ui.icon name="user-check" /></i>
                            </button>
                            <div class="ps-dropdown-menu">
                                <div style="padding:10px;font-weight:600;border-bottom:1px solid var(--ps-border)">{{ $customer->name }}</div>
                                <a href="{{ route('customer.account', $store->id) }}" style="display:block;padding:8px 10px;color:inherit;text-decoration:none">{{ ___('My Orders') }}</a>
                                <button type="button" id="ps-logout-btn">{{ ___('Logout') }}</button>
                            </div>
                        </div>
                    @else
                        <button class="ps-icon-btn" type="button" id="ps-login-toggle" aria-label="{{ ___('Login') }}">
                            <i class="ico ico-user" aria-hidden="true"><x-ui.icon name="user" /></i>
                        </button>
                    @endif
                @endif
                {{-- Cart --}}
                @if($allow_order)
                    <button class="ps-icon-btn" type="button" id="ps-cart-toggle" aria-label="{{ ___('Your order') }}">
                        <i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i>
                        <span class="ps-badge-count" id="ps-cart-count">0</span>
                    </button>
                @endif
            </div>
        </div>
    </header>

    @php
        $savedSections = isset($postOptions->menu_sections)
            ? json_decode(json_encode($postOptions->menu_sections), true)
            : null;
        $defaultSections = [
            ['id' => 'hero', 'visible' => true],
            ['id' => 'catbar', 'visible' => true],
            ['id' => 'daily_specials', 'visible' => true],
            ['id' => 'specials', 'visible' => true],
            ['id' => 'menu', 'visible' => true],
        ];
        $sectionsToRender = [];
        if ($savedSections && is_array($savedSections)) {
            foreach ($savedSections as $saved) {
                if (collect($defaultSections)->contains('id', $saved['id'])) {
                    $sectionsToRender[] = $saved;
                }
            }
        }
        if (empty($sectionsToRender)) {
            $sectionsToRender = $defaultSections;
        }
    @endphp

    <div class="ps-wrap">
        @include('store_templates.includes.menu-banners-slider')
    </div>

    @foreach($sectionsToRender as $sect)
        @if($sect['visible'] ?? true)
            @if($sect['id'] === 'hero')
                {{-- ============================================================ HERO --}}
                <section class="ps-hero" id="ps-hero">
                    <div class="ps-wrap">
                        <div class="ps-hero-card ps-reveal">
                            @if($featured && $featured->image && $featured->image !== 'default.png')
                            <div class="ps-hero-cover">
                                @if((string) (media_url('storage/menu/', $featured->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $featured->image, $store->id ?? null) }}" alt="" loading="eager"
                                     data-csp-error="parent-hide">@endif
                            </div>
                            @endif
                            <div class="ps-hero-inner">
                                <div class="ps-hero-text">
                                    @if($psLogo !== '')<img class="ps-hero-logo" src="{{ $psLogo }}" alt="{{ $store->title }}" data-csp-error="hide">@endif
                                    <div class="ps-eyebrow">
                                        <span class="ps-dot"></span>
                                        {{ ___('Open Now') }}
                                    </div>
                                    <h1><span class="ps-grad-text">{{ $store->title }}</span></h1>
                                    @if($store->description)
                                        <p class="ps-hero-tag">{{ text_shorting(strip_tags($store->description), 120) }}</p>
                                    @endif
                                    <div class="ps-hero-meta">
                                        @if($store->address)
                                            <span class="ps-chip-meta"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i> {{ text_shorting($store->address, 40) }}</span>
                                        @endif
                                        @if(!empty($scans_today) && $scans_today > 0)
                                            <span class="ps-chip-meta"><i class="ico ico-eye" aria-hidden="true"><x-ui.icon name="eye" /></i> {{ $scans_today }} {{ ___('views today') }}</span>
                                        @endif
                                        @if($items->count() > 0)
                                            <span class="ps-chip-meta"><i class="ico ico-dine-in" aria-hidden="true"><x-ui.icon name="dine-in" /></i> {{ $items->count() }} {{ ___('dishes') }}</span>
                                        @endif
                                    </div>
                                    {{-- Search --}}
                                    <div class="ps-search">
                                        <i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i>
                                        <input type="text" id="ps-search-input" placeholder="{{ ___('Search dishes...') }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
                                        <button class="ps-search-clear" id="ps-search-clear" type="button"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
                                    </div>
                                </div>
                                @if($featured)
                                <div class="ps-hero-featured" data-open-item="{{ $featured->id }}">
                                    <span class="ps-featured-tag"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ ___("Chef's Pick") }}</span>
                                    @if((string) (media_url('storage/menu/', $featured->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $featured->image, $store->id ?? null) }}" alt="{{ $featured->name }}" loading="eager"
                                         data-csp-error="opacity" data-csp-value=".3">@endif
                                    <div class="ps-featured-body">
                                        <h3>{{ $featured->name }}</h3>
                                        <span class="ps-price">{{ price_symbol_format($featured->price) }}</span>
                                    </div>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

            @elseif($sect['id'] === 'catbar')
                {{-- ======================================================== CATEGORY BAR --}}
                <nav class="ps-catbar" id="ps-catbar">
                    <div class="ps-wrap">
                        <div class="ps-chips" id="ps-chips">
                            <button class="ps-chip active" data-target="all" type="button">
                                <i class="ico ico-layout-grid" aria-hidden="true"><x-ui.icon name="layout-grid" /></i> {{ ___('All') }}
                            </button>
                            @foreach($categories as $cat)
                                @php $count = ($byCat[$cat->id] ?? collect())->count(); @endphp
                                @if($count > 0)
                                <button class="ps-chip" data-target="cat-{{ $cat->id }}" type="button">
                                    {{ $cat->name }}
                                    <span class="ps-chip-count">{{ $count }}</span>
                                </button>
                                @endif
                            @endforeach
                        </div>
                        <button class="ps-filters-toggle" id="ps-filters-toggle" type="button" aria-label="{{ ___('Filters') }}">
                            <i class="ico ico-adjustments-horizontal" aria-hidden="true"><x-ui.icon name="adjustments-horizontal" /></i>
                        </button>
                    </div>
                    {{-- Filters panel --}}
                    <div class="ps-filters-panel" id="ps-filters-panel">
                        <div class="ps-wrap">
                            <div class="ps-filters-grid">
                                <div>
                                    <h4>{{ ___('Dietary') }}</h4>
                                    <div class="ps-filters-chips">
                                        <button class="ps-filter-chip" data-filter="vegan" type="button"><i class="ico ico-leaf" aria-hidden="true"><x-ui.icon name="leaf" /></i> {{ ___('Vegan') }}</button>
                                        <button class="ps-filter-chip" data-filter="halal" type="button"><i class="ico ico-rosette-discount-check" aria-hidden="true"><x-ui.icon name="rosette-discount-check" /></i> {{ ___('Halal') }}</button>
                                        <button class="ps-filter-chip" data-filter="gf" type="button"><i class="ico ico-wheat" aria-hidden="true"><x-ui.icon name="wheat" /></i> {{ ___('Gluten Free') }}</button>
                                        <button class="ps-filter-chip" data-filter="popular" type="button"><i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ ___('Popular') }}</button>
                                    </div>
                                </div>
                                <div>
                                    <h4>{{ ___('Spice Level') }}</h4>
                                    <div class="ps-filters-chips">
                                        @for($s = 1; $s <= 3; $s++)
                                        <button class="ps-filter-chip" data-filter="spice-{{ $s }}" type="button">
                                            @for($x = 0; $x < $s; $x++)🌶️@endfor
                                        </button>
                                        @endfor
                                    </div>
                                </div>
                            </div>
                            <div class="ps-filters-foot">
                                <button class="ps-filter-clear-btn" id="ps-filter-clear" type="button"><i class="ico ico-rotate" aria-hidden="true"><x-ui.icon name="rotate" /></i> {{ ___('Clear all') }}</button>
                            </div>
                        </div>
                    </div>
                </nav>

            @elseif($sect['id'] === 'daily_specials')
                {{-- ================================================= DAILY SPECIALS --}}
                @php
                    $dailySpecials = $items->filter(fn($m) => (bool)($m->is_daily_special ?? false))->take(6);
                @endphp
                @if($dailySpecials->count() > 0)
                <section class="ps-section ps-reveal" id="ps-daily">
                    <div class="ps-wrap">
                        <div class="ps-section-head">
                            <div>
                                <span class="ps-kicker">{{ ___('Today\'s Special') }}</span>
                                <h2>{{ ___('Daily Specials') }}</h2>
                            </div>
                        </div>
                        <div class="ps-rail">
                            @foreach($dailySpecials as $sp)
                                <div class="ps-spotlight" @if(!$sp->is_sold_out) data-open-item="{{ $sp->id }}" @else style="opacity:.6" @endif>
                                    @if($sp->is_sold_out)
                                        <span class="ps-sold-badge"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i> {{ ___('Sold Out') }}</span>
                                    @endif
                                    @if((string) (media_url('storage/menu/', $sp->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $sp->image, $store->id ?? null) }}" alt="{{ $sp->name }}" loading="lazy" data-csp-error="opacity" data-csp-value=".2">@endif
                                    <div class="ps-spotlight-body">
                                        <span class="ps-daily-tag"><i class="ico ico-sparkles" aria-hidden="true"><x-ui.icon name="sparkles" /></i> {{ ___('Special') }}</span>
                                        <h4>{{ $sp->name }}</h4>
                                        <div class="ps-spotlight-foot">
                                            <span class="ps-price">{{ price_symbol_format($sp->price) }}</span>
                                            <span class="ps-add-circle"><i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i></span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
                @endif

            @elseif($sect['id'] === 'specials')
                {{-- =================================================== CHEF'S SPECIALS --}}
                @if($specials->count() > 2)
                <section class="ps-section ps-reveal" id="ps-specials">
                    <div class="ps-wrap">
                        <div class="ps-section-head">
                            <div>
                                <span class="ps-kicker">{{ ___('Handpicked') }}</span>
                                <h2>{{ ___("Chef's Specials") }}</h2>
                            </div>
                        </div>
                        <div class="ps-rail">
                            @foreach($specials as $rank => $sp)
                                <div class="ps-spotlight" @if(!$sp->is_sold_out) data-open-item="{{ $sp->id }}" @else style="opacity:.6" @endif>
                                    @if($sp->is_sold_out)
                                        <span class="ps-sold-badge"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i> {{ ___('Sold Out') }}</span>
                                    @endif
                                    @if((string) (media_url('storage/menu/', $sp->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $sp->image, $store->id ?? null) }}" alt="{{ $sp->name }}" loading="lazy" data-csp-error="opacity" data-csp-value=".2">@endif
                                    <div class="ps-spotlight-body">
                                        <span class="ps-rank">#{{ $rank + 1 }} {{ ___('Popular') }}</span>
                                        <h4>{{ $sp->name }}</h4>
                                        <div class="ps-spotlight-foot">
                                            <span class="ps-price">{{ price_symbol_format($sp->price) }}</span>
                                            <span class="ps-add-circle"><i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i></span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
                @endif

            @elseif($sect['id'] === 'menu')
                {{-- ============================================================ MENU --}}
                <div class="ps-wrap" id="ps-menu-root">
                    @foreach($categories as $cat)
                        @php
                            $direct  = $byCat[$cat->id] ?? collect();
                            $subcats = $cat->subcategories;
                            $hasSub  = $subcats->contains(fn($s) => ($byCat[$s->id] ?? collect())->count() > 0);
                        @endphp
                        @if($direct->count() > 0 || $hasSub)
                            <section class="ps-section ps-cat-section" id="cat-{{ $cat->id }}" data-cat="{{ $cat->id }}">
                                <div class="ps-section-head ps-reveal">
                                    <div>
                                        <span class="ps-kicker">{{ ___('Menu') }}</span>
                                        <h2>{{ $cat->name }}</h2>
                                    </div>
                                </div>
                                @if($direct->count() > 0)
                                    <div class="ps-grid">
                                        @foreach($direct as $i => $menu)
                                            @include('store_templates.pulse.includes.dish-card', ['menu' => $menu, 'cardIndex' => $i])
                                        @endforeach
                                    </div>
                                @endif
                                @foreach($subcats as $sub)
                                    @php $subItems = $byCat[$sub->id] ?? collect(); @endphp
                                    @if($subItems->count() > 0)
                                        <div class="ps-section-head" style="margin-top:28px">
                                            <div><h2 style="font-size:20px">{{ $sub->name }}</h2></div>
                                        </div>
                                        <div class="ps-grid">
                                            @foreach($subItems as $i => $menu)
                                                @include('store_templates.pulse.includes.dish-card', ['menu' => $menu, 'cardIndex' => $i])
                                            @endforeach
                                        </div>
                                    @endif
                                @endforeach
                            </section>
                        @endif
                    @endforeach
                    {{-- Empty state --}}
                    <div class="ps-empty" id="ps-empty">
                        <div class="ps-empty-ico"><i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i></div>
                        <h3>{{ ___('No dishes found') }}</h3>
                        <p>{{ ___('Try a different search or category.') }}</p>
                    </div>
                </div>
            @endif
        @endif
    @endforeach

    @if($items->count() === 0)
        <div class="ps-empty show">
            <div class="ps-empty-ico"><i class="ico ico-dine-in" aria-hidden="true"><x-ui.icon name="dine-in" /></i></div>
            <h3>{{ ___('Menu coming soon') }}</h3>
            <p>{{ ___('This restaurant has not added any dishes yet.') }}</p>
        </div>
    @endif
</div>

{{-- Call Waiter / Request Bill --}}
@if(@$postOptions->allow_call_waiter)
<div class="ps-wrap" style="padding-top:0;padding-bottom:24px;">
    <div class="ps-action-row">
        <button type="button" id="ps-call-waiter-btn" class="ps-action-btn ps-action-waiter">
            <i class="ico ico-bell" aria-hidden="true"><x-ui.icon name="bell" /></i> {{ ___('Call Waiter') }}
        </button>
        <button type="button" id="ps-request-bill-btn" class="ps-action-btn ps-action-bill">
            <i class="ico ico-receipt" aria-hidden="true"><x-ui.icon name="receipt" /></i> {{ ___('Request Bill') }}
        </button>
    </div>
</div>

<div class="ps-scrim" id="ps-waiter-scrim"></div>
<aside class="ps-sheet" id="ps-waiter-sheet" aria-hidden="true">
    <div class="ps-sheet-head">
        <h3 id="ps-waiter-title">{{ ___('Call Waiter') }}</h3>
        <button class="ps-close-btn" type="button" data-close-sheet="waiter"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
    </div>
    <div class="ps-sheet-body">
        <div id="ps-waiter-success" style="display:none;text-align:center;padding:30px 0;">
            <div style="font-size:48px;margin-bottom:12px;">✅</div>
            <h3>{{ ___('Request sent!') }}</h3>
            <p id="ps-waiter-sent-label" style="color:var(--ps-muted);margin-top:6px;"></p>
        </div>
        <form id="ps-waiter-form">
            <input type="hidden" id="ps-waiter-type-input" value="call_waiter">
            <p style="color:var(--ps-muted);margin-bottom:18px;">{{ ___('We will send a waiter to your table right away.') }}</p>
            <div class="ps-field">
                <label>{{ ___('Table Number') }}</label>
                <input type="text" id="ps-waiter-table" name="table" value="{{ $table->table_no ?? '' }}" {{ $table ? 'readonly' : '' }} placeholder="{{ ___('Your table number') }}" required>
            </div>
            <div class="ps-field">
                <label>{{ ___('Note') }} ({{ ___('optional') }})</label>
                <textarea name="message" placeholder="{{ ___('Any special note?') }}"></textarea>
            </div>
            <button type="submit" class="ps-cta"><i class="ico ico-send" aria-hidden="true"><x-ui.icon name="send" /></i> {{ ___('Send Request') }}</button>
        </form>
    </div>
</aside>
@endif

{{-- Guest order history lookup --}}
<div class="ps-wrap" style="padding-top:0;padding-bottom:28px;">
    <details class="ps-history-box">
        <summary><i class="ico ico-history" style="color:var(--ps-accent)" aria-hidden="true"><x-ui.icon name="history" /></i> {{ ___('Track my previous orders') }}</summary>
        <div style="margin-top:16px;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="text" id="ps-order-lookup-input" placeholder="{{ ___('Enter your phone or email') }}" class="ps-input" style="flex:1;min-width:180px;">
                <button type="button" id="ps-order-lookup-btn" class="ps-cta" style="padding:10px 18px;font-size:13px;flex-shrink:0;">
                    <i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i> {{ ___('Look up') }}
                </button>
            </div>
            <div id="ps-order-lookup-results" style="margin-top:14px;"></div>
        </div>
    </details>
</div>

{{-- ========================================================== FOOTER --}}
<footer class="ps-foot">
    <div class="ps-wrap">
        <div class="ps-foot-brand">{{ $store->title }}</div>
        @if($store->address)<div>{{ $store->address }}</div>@endif
        @if(! store_plan_allows($store, 'hide_branding'))
            <div style="margin-top:14px;opacity:.6">{{ ___('Powered by') }} <a href="{{ route('home') }}" style="color:inherit">{{ $settings->site_title }}</a></div>
        @endif
    </div>
</footer>

{{-- =================================================== STICKY CART BAR --}}
@if($allow_order)
    <div class="ps-cartbar" id="ps-cartbar">
        <span class="ps-cb-ico"><i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i></span>
        <div class="ps-cb-text"><span id="ps-cartbar-count">0 {{ ___('items') }}</span><small>{{ ___('View your order') }}</small></div>
        <span class="ps-cb-total" id="ps-cartbar-total"></span>
    </div>

    {{-- Resume order banner --}}
    <div class="ps-resume-banner" id="ps-resume-banner" style="display:none">
        <div class="ps-wrap">
            <div class="ps-resume-inner">
                <div><i class="ico ico-rotate" aria-hidden="true"><x-ui.icon name="rotate" /></i> {{ ___('You have items from your last visit.') }}</div>
                <div style="display:flex;gap:8px;margin-top:8px;">
                    <button type="button" id="ps-resume-yes" class="ps-cta" style="font-size:13px;padding:8px 16px;">{{ ___('Continue') }}</button>
                    <button type="button" id="ps-resume-no" class="ps-cta" style="font-size:13px;padding:8px 16px;background:transparent;border:1px solid var(--ps-border);color:var(--ps-text);">{{ ___('Start fresh') }}</button>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- =================================================== ITEM DETAIL SHEET --}}
<div class="ps-scrim" id="ps-item-scrim"></div>
<aside class="ps-sheet ps-sheet-full" id="ps-item-sheet" aria-hidden="true">
    <div class="ps-sheet-head">
        <h3 id="ps-item-title">—</h3>
        <button class="ps-close-btn" type="button" data-close-sheet="item"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
    </div>
    <div class="ps-sheet-body">
        <div class="ps-item-img-wrap" id="ps-item-img-wrap">
            <img id="ps-item-img" alt="">
            <div class="ps-item-badges" id="ps-item-badges"></div>
        </div>
        <div id="ps-item-desc" style="color:var(--ps-muted);font-size:14px;margin-bottom:16px;"></div>
        {{-- Variants --}}
        <div id="ps-variants-section" style="display:none;margin-bottom:16px;">
            <h4 style="font-size:14px;font-weight:700;margin-bottom:10px;">{{ ___('Options') }}</h4>
            <div class="ps-variant-group" id="ps-variant-group"></div>
        </div>
        {{-- Extras --}}
        <div id="ps-extras-section" style="display:none;margin-bottom:16px;">
            <h4 style="font-size:14px;font-weight:700;margin-bottom:10px;">{{ ___('Add-ons') }}</h4>
            <div id="ps-extras-list"></div>
        </div>
        {{-- Note --}}
        <div class="ps-field" style="margin-bottom:16px;">
            <label>{{ ___('Special request') }} <small style="color:var(--ps-muted)">({{ ___('optional') }})</small></label>
            <textarea id="ps-item-note" placeholder="{{ ___('e.g. no onions, extra spicy...') }}" rows="2"></textarea>
        </div>
        {{-- Quantity --}}
        <div class="ps-qty-row">
            <div class="ps-qty" id="ps-item-qty">
                <button type="button" data-q="-1">&minus;</button>
                <span>1</span>
                <button type="button" data-q="1">+</button>
            </div>
            <div class="ps-item-total">
                <span id="ps-detail-total">—</span>
            </div>
        </div>
    </div>
    <div class="ps-sheet-foot">
        <button type="button" class="ps-cta" id="ps-add-to-cart-btn">
            <i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i> {{ ___('Add to order') }}
            <span id="ps-add-total"></span>
        </button>
    </div>
</aside>

{{-- =================================================== CART SHEET --}}
@if($allow_order)
<div class="ps-scrim" id="ps-cart-scrim"></div>
<aside class="ps-sheet ps-sheet-full" id="ps-cart-sheet" aria-hidden="true">
    <div class="ps-sheet-head">
        <h3>{{ ___('Your Order') }}</h3>
        <button class="ps-close-btn" type="button" data-close-sheet="cart"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
    </div>
    <div class="ps-sheet-body">
        <div id="ps-cart-empty" style="text-align:center;padding:40px 0;">
            <div style="font-size:48px;margin-bottom:12px;opacity:.4;">🛒</div>
            <h3 style="opacity:.5">{{ ___('Your cart is empty') }}</h3>
        </div>
        <div id="ps-cart-list"></div>
        <div id="ps-cart-totals" style="margin-top:16px;"></div>
        <form id="ps-order-form" action="{{ route('store.submitOrder', $store->id) }}" method="POST" style="display:none;">
            @csrf
            <input type="hidden" name="ordering-type" id="ps-ordering-type" value="">
            @if(@$postOptions->allow_coupons == 1)
            <div class="ps-field">
                <label>{{ ___('Promo Code') }}</label>
                <div class="ps-coupon-row">
                    <input type="text" id="ps-coupon-code" name="coupon_code" placeholder="{{ ___('Enter promo code') }}" class="ps-input" style="flex:1;margin-bottom:0;">
                    <button type="button" id="ps-apply-coupon" class="ps-coupon-btn">{{ ___('Apply') }}</button>
                </div>
                <div id="ps-coupon-msg" style="display:none;margin-top:5px;font-size:13px;font-weight:500;"></div>
            </div>
            @endif
            @if(!empty($postOptions->gift_cards_enabled))
            <div class="ps-field">
                <label>{{ ___('Gift card code') }}</label>
                <div class="ps-coupon-row">
                    <input type="text" id="ps-gift-card-code" name="gift_card_code" placeholder="{{ ___('Enter gift card code') }}" class="ps-input" style="flex:1;margin-bottom:0;">
                </div>
            </div>
            @endif
            @if($customer && $storeCredit > 0)
            <label class="wa-optin" style="display:flex;align-items:center;gap:8px;margin:10px 0;font-size:.9em;">
                <input type="checkbox" name="use_wallet" value="1" id="mbUseWallet">
                <span>{{ ___('Pay with store credit') }} ({{ $curSign }}{{ number_format($storeCredit, currency_decimals()) }} {{ ___('available') }})</span>
            </label>
            @endif
            <div class="ps-form-error" id="ps-form-error"></div>
            <div class="ps-field">
                <label>{{ ___('Order type') }}</label>
                <div class="ps-seg" id="ps-ordertype-seg">
                    @if($allow_on_table)<button type="button" data-type="on-table">{{ ___('On table') }}</button>@endif
                    @if($allow_takeaway)<button type="button" data-type="takeaway">{{ ___('Takeaway') }}</button>@endif
                    @if($allow_delivery)<button type="button" data-type="delivery">{{ ___('Delivery') }}</button>@endif
                </div>
            </div>
            @if(!empty($postOptions->allow_order_scheduling))
            <div class="ps-field">
                <label>{{ ___('Schedule for later') }} <small style="color:var(--ps-muted)">({{ ___('optional') }})</small></label>
                <input type="datetime-local" name="scheduled_at" id="ps-scheduled-at" min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}" max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}" class="ps-input">
            </div>
            @endif
            {{-- T5: Curbside pickup (takeaway) --}}
            @if(!empty($postOptions->curbside_enabled))
            <div class="ps-field">
                <label style="display:flex;align-items:center;gap:8px;font-size:.9em;cursor:pointer;">
                    <input type="checkbox" name="curbside" value="1" id="ps-curbside" data-csp-toggle="ps-curbside-fields">
                    <span>{{ ___("Curbside pickup — I'll wait in my car") }}</span>
                </label>
                <div id="ps-curbside-fields" style="display:none;margin-top:8px;">
                    <input type="text" name="vehicle_make" id="ps-vehicle-make" placeholder="{{ ___('Car make/model') }}" class="ps-input" style="margin-bottom:8px;">
                    <input type="text" name="vehicle_color" id="ps-vehicle-color" placeholder="{{ ___('Colour') }}" class="ps-input" style="margin-bottom:8px;">
                    <input type="text" name="vehicle_plate" id="ps-vehicle-plate" placeholder="{{ ___('Plate') }}" class="ps-input">
                </div>
            </div>
            @endif
            {{-- Auth fields --}}
            @if($authMode === 'required' && !$customer)
                <div class="ps-auth-notice">
                    <i class="ico ico-lock" aria-hidden="true"><x-ui.icon name="lock" /></i>
                    <h4>{{ ___('Login Required') }}</h4>
                    <p>{{ ___('You must be logged in to place an order.') }}</p>
                    <button type="button" class="ps-cta" id="ps-checkout-login-btn" style="padding:10px;">{{ ___('Login / Register') }}</button>
                </div>
                <div id="ps-guest-fields" style="display:none;">
            @else
                @if($authMode === 'optional' && !$customer)
                    <div class="ps-auth-optional">
                        <div>
                            <h4>{{ ___('Have an account?') }}</h4>
                            <p>{{ ___('Log in for faster checkout') }}</p>
                        </div>
                        <button type="button" id="ps-checkout-login-btn" class="ps-cta" style="padding:6px 12px;font-size:13px;">{{ ___('Login') }}</button>
                    </div>
                @endif
                <div id="ps-guest-fields">
            @endif
            @if($customer)
                <input type="hidden" name="name" value="{{ $customer->name }}">
                <div class="ps-field" data-field="table" style="display:none">
                    <label>{{ ___('Table') }}</label>
                    @if($table)
                        <input type="text" name="table" value="{{ $table->table_no }}" readonly class="ps-input">
                    @else
                        <input type="number" name="table" placeholder="{{ ___('Table number') }}" class="ps-input">
                    @endif
                </div>
                <div class="ps-field" data-field="phone" style="display:none">
                    <label>{{ ___('Phone number') }}</label>
                    <input type="tel" name="phone-number" value="{{ $customer->phone }}" placeholder="{{ ___('Phone number') }}" class="ps-input">
                </div>
                <div class="ps-field" data-field="address" style="display:none">
                    <label>{{ ___('Delivery address') }}</label>
                    <textarea name="address" placeholder="{{ ___('Delivery address') }}">{{ $customerDefaultAddress ? trim(collect([$customerDefaultAddress->line1, $customerDefaultAddress->line2, $customerDefaultAddress->city, $customerDefaultAddress->state, $customerDefaultAddress->zip, $customerDefaultAddress->country])->filter()->implode(', ')) : '' }}</textarea>
                </div>
            @else
                <div class="ps-field">
                    <label>{{ ___('Your Name') }}</label>
                    <input type="text" name="name" required placeholder="{{ ___('Your Name') }}" class="ps-input">
                </div>
                <div class="ps-field" data-field="table" style="display:none">
                    <label>{{ ___('Table') }}</label>
                    @if($table)
                        <input type="text" name="table" value="{{ $table->table_no }}" readonly class="ps-input">
                    @else
                        <input type="number" name="table" placeholder="{{ ___('Table number') }}" class="ps-input">
                    @endif
                </div>
                <div class="ps-field" data-field="phone" style="display:none">
                    <label>{{ ___('Phone number') }}</label>
                    <input type="tel" name="phone-number" placeholder="{{ ___('Phone number') }}" class="ps-input">
                </div>
                <div class="ps-field" data-field="address" style="display:none">
                    <label>{{ ___('Delivery address') }}</label>
                    <textarea name="address" placeholder="{{ ___('Delivery address') }}"></textarea>
                </div>
            @endif
            </div>
            {{-- Payment --}}
            @if($allow_payment)
            <div class="ps-field">
                <label>{{ ___('Pay Via') }}</label>
                <div class="ps-seg" id="ps-payment-seg">
                    @if(!empty($postOptions->paypal_enable))
                        <button type="button" data-method="paypal">PayPal</button>
                    @endif
                    @if(!empty($postOptions->stripe_enable))
                        <button type="button" data-method="stripe">Stripe</button>
                    @endif
                    @if(!empty($postOptions->paystack_enable))
                        <button type="button" data-method="paystack">Paystack</button>
                    @endif
                    @if(!empty($postOptions->razorpay_enable))
                        <button type="button" data-method="razorpay">Razorpay</button>
                    @endif
                    @if(!empty($postOptions->mollie_enable))
                        <button type="button" data-method="mollie">Mollie</button>
                    @endif
                    @if(!empty($postOptions->toyyibpay_enable))
                        <button type="button" data-method="toyyibpay">ToyyibPay</button>
                    @endif
                    @if(!empty($postOptions->flutterwave_enable))
                        <button type="button" data-method="flutterwave">Flutterwave</button>
                    @endif
                </div>
                <input type="hidden" name="payment_method" id="ps-payment-method" value="">
            </div>
            @endif
            {{-- Tip --}}
            @if(!empty($postOptions->allow_tips))
            <div class="ps-field">
                <label>{{ ___('Add a tip') }}</label>
                <div class="ps-tip-row" id="ps-tip-row">
                    <button type="button" class="ps-tip-btn" data-tip-pct="10">10%</button>
                    <button type="button" class="ps-tip-btn" data-tip-pct="15">15%</button>
                    <button type="button" class="ps-tip-btn" data-tip-pct="20">20%</button>
                    <button type="button" class="ps-tip-btn" data-tip-pct="custom">{{ ___('Custom') }}</button>
                </div>
                <input type="number" id="ps-custom-tip" class="ps-input" style="display:none;margin-top:8px;" placeholder="{{ ___('Enter tip amount') }}" min="0">
                <input type="hidden" name="tip_amount" id="ps-tip-amount" value="0">
            </div>
            @endif
        </form>
        {{-- NPS --}}
        <div class="ps-nps-block" style="margin-top:22px;">
            <p style="font-weight:600;margin-bottom:12px;text-align:center;">{{ ___('How likely are you to recommend us?') }}</p>
            <div style="display:flex;justify-content:center;gap:4px;flex-wrap:wrap;" id="ps-nps-scores">
                @for($n=0;$n<=10;$n++)
                <button type="button" data-nps="{{ $n }}" class="ps-nps-btn">{{ $n }}</button>
                @endfor
            </div>
            <p style="display:flex;justify-content:space-between;font-size:11px;color:var(--ps-muted);margin-top:6px;"><span>{{ ___('Not likely') }}</span><span>{{ ___('Very likely') }}</span></p>
        </div>
    </div>
    <div class="ps-sheet-foot" id="ps-cart-foot" style="display:none">
        @if($authMode === 'required' && !$customer)
            <button type="button" class="ps-cta" style="opacity:.5;cursor:not-allowed;">
                <i class="ico ico-lock" aria-hidden="true"><x-ui.icon name="lock" /></i> {{ ___('Login Required') }}
                <span id="ps-checkout-total"></span>
            </button>
        @else
            <button type="submit" form="ps-order-form" class="ps-cta" id="ps-checkout-btn">
                <i class="ico ico-bolt" aria-hidden="true"><x-ui.icon name="bolt" /></i> {{ ___('Place order') }}
                <span id="ps-checkout-total"></span>
            </button>
        @endif
    </div>
</aside>
@endif

{{-- =================================================== LOGIN MODAL --}}
@if($authMode !== 'guest_only' && !$customer)
<div class="ps-scrim" id="ps-login-scrim"></div>
<aside class="ps-sheet" id="ps-login-sheet" aria-hidden="true">
    <div class="ps-sheet-head">
        <h3>{{ ___('Login / Register') }}</h3>
        <button class="ps-close-btn" type="button" data-close-sheet="login"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
    </div>
    <div class="ps-sheet-body">
        <form id="ps-login-form" action="{{ route('customer.login', $store->id) }}" method="POST">
            @csrf
            <div class="ps-field">
                <label>{{ ___('Email or Phone') }}</label>
                <input type="text" name="login" required placeholder="{{ ___('Email or phone number') }}" class="ps-input">
            </div>
            <button type="submit" class="ps-cta"><i class="ico ico-send" aria-hidden="true"><x-ui.icon name="send" /></i> {{ ___('Send OTP') }}</button>
        </form>
        @include('store_templates.partials.passkey-login', ['store' => $store])
    </div>
</aside>
@endif

@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
window.PULSE = {
    orderUrl: @json(route('store.submitOrder', $store->id)),
    restaurantSlug: @json($store->slug),
    items: @json($jsItems),
    csrf: @json(csrf_token()),
    currencySign: @json($curSign),
    currencyPos: @json($curPos),
    currencyDecimals: @json(currency_decimals()),
    deliveryCharge: @json($deliveryCharge),
    taxCharge: @json($taxCharge),
    minOrderValue: @json($minOrderValue),
    forceTable: @json($forceTable),
    allowOrder: @json($allow_order),
    i18n: {
        item: @json(___('item')),
        items: @json(___('items')),
        subtotal: @json(___('Subtotal')),
        delivery: @json(___('Delivery')),
        tax: @json(___('Tax')),
        discount: @json(___('Discount')),
        total: @json(___('Total')),
        remove: @json(___('Remove')),
        selectType: @json(___('Please select an order type.')),
        min_order: @json(___('Minimum order:')),
        add: @json(___('Add')),
        options: @json(___('Options')),
        addons: @json(___('Add-ons')),
    }
};
</script>
@endpush
