{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
/* ---------------------------------------------------------------------
   NEBULA — Cosmic Dining Template Data Preparation
   --------------------------------------------------------------------- */
    use Illuminate\Support\Str;

    $allMenus = collect($total_menus)->values();
    $items = $allMenus->values();
    $byCat = $items->groupBy('category_id');
    $categories = $store->menu_categories->values();
    $mcRates = \Juwri\Modules\Storefront\Storefront\DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

    $curSign = $postOptions->currency_sign ?? '$';
    $curPos  = (string) ($postOptions->currency_pos ?? '1');
    $deliveryCharge    = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge         = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $minOrderValue     = (float) (@$postOptions->min_order_value ?: 0);
    $allowOrderSched   = !empty($postOptions->allow_order_scheduling);

    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelNamed(...);

    /* build the JS data map */
    $jsItems = [];
    foreach ($items as $m) {
        $itemAllergies = empty($m->allergies)
            ? collect()
            : $allergies->whereIn('id', explode(',', $m->allergies));

        $variants = [];
        if (isset($m->variants)) {
            foreach ($m->variants as $v) {
                $variants[] = [
                    'id'    => $v->id,
                    'label' => $variantLabel($m, $v),
                    'price' => (float) $v->price,
                ];
            }
        }
        $extras = [];
        if (isset($m->extras)) {
            foreach ($m->extras as $e) {
                $extras[] = [
                    'id'    => $e->id,
                    'title' => $e->title,
                    'price' => (float) $e->price,
                ];
            }
        }

        $jsItems[$m->id] = [
            'id'        => $m->id,
            'name'      => $m->name,
            'desc'      => strip_tags((string) $m->description),
            'img'       => media_url('storage/menu/', $m->image, $store->id ?? null),
            'price'     => (float) $m->price,
            'type'      => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'rating'    => $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : number_format(4.2 + (($m->id % 8) / 10), 1),
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyList($itemAllergies),
            'variants'  => $variants,
            'extras'    => $extras,
            'is_vegan'  => (bool) $m->is_vegan,
            'is_halal'  => (bool) $m->is_halal,
            'is_gluten_free' => (bool) $m->is_gluten_free,
            'is_popular'       => (bool) $m->is_popular,
            'is_daily_special' => (bool) ($m->is_daily_special ?? false),
            'spice_level'      => (int) $m->spice_level,
            'is_sold_out'      => (bool) $m->is_sold_out,
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
            'gallery'          => !empty($m->gallery_images)
                ? \Juwri\Core\Support\ViewHelpers::galleryUrls($m->gallery_images, $store->id ?? null)
                : [],
        ];
    }

    $authMode = @$postOptions->customer_auth_mode ?? '';
    $customer = auth('customer')->user();
    // WIRING-RUN G3 (coupons-wallets P7-6a): pay-with-credit for a logged-in diner with
    // a balance. use_wallet rides to submitOrder via the shared order-field forwarder.
    $storeCredit = $customer ? \App\Services\CustomerWalletService::balanceFor($customer) : 0.0;

    /* order type flags */
    $allow_on_table = !empty($postOptions->allow_on_table_order);
    $allow_takeaway = !empty($postOptions->allow_takeaway_order);
    $allow_delivery = !empty($postOptions->allow_delivery_order);

    /* featured item */
    $featured = \Juwri\Core\Support\ViewHelpers::firstWithImage($items);
    $specials = $items->take(10);

    /* sections config */
    $savedSections = isset($postOptions->menu_sections)
        ? json_decode(json_encode($postOptions->menu_sections), true)
        : null;
    $defaultSections = [
        ['id' => 'hero', 'visible' => true],
        ['id' => 'catbar', 'visible' => true],
        ['id' => 'daily_specials', 'visible' => true],
        ['id' => 'specials', 'visible' => true],
        ['id' => 'menu', 'visible' => true],
        ['id' => 'loyalty', 'visible' => true],
    ];
    $sectionsToRender = [];
    if ($savedSections && is_array($savedSections)) {
        foreach ($savedSections as $saved) {
            if (collect($defaultSections)->contains('id', $saved['id'])) {
                $sectionsToRender[] = [
                    'id' => $saved['id'],
                    'visible' => isset($saved['visible']) ? (bool)$saved['visible'] : true
                ];
            }
        }
        foreach ($defaultSections as $def) {
            if (!collect($sectionsToRender)->contains('id', $def['id'])) {
                $sectionsToRender[] = $def;
            }
        }
    } else {
        $sectionsToRender = $defaultSections;
    }
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="nebula" id="nebula" data-theme="dark">
    <canvas class="nb-starfield" id="nb-starfield"></canvas>

    {{-- Social proof banner --}}
    @if(!empty($scans_today) && $scans_today > 0)
    <div class="nb-social-banner">
        <i class="ico ico-eye" aria-hidden="true"><x-ui.icon name="eye" /></i>
        <span>{{ $scans_today }} {{ ___('people viewing this menu right now') }}</span>
    </div>
    @endif

    {{-- ================================================================= TOP BAR --}}
    <header class="nb-topbar" id="nb-topbar">
        <div class="nb-topbar-inner">
            <a href="#nebula" class="nb-brand">
                @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}"
                     alt="{{ $store->title }}" data-csp-error="hide">@endif
                <span class="nb-brand-name">{{ $store->title }}</span>
            </a>
            <div class="nb-topbar-actions">
                @if(!empty($menu_languages) && count($menu_languages) > 1)
                <div class="nb-dropdown" id="nb-lang-dropdown">
                    <button class="nb-icon-btn" type="button" aria-label="{{ ___('Language') }}">
                        <i class="ico ico-world" aria-hidden="true"><x-ui.icon name="world" /></i>
                    </button>
                    <div class="nb-dropdown-menu">
                        @foreach($menu_languages as $language)
                        <button type="button" data-code="{{ $language->code }}">{{ $language->name }}</button>
                        @endforeach
                    </div>
                </div>
                @endif
                @if(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0)
                <div class="nb-dropdown" id="nb-currency-dropdown">
                    <button class="nb-icon-btn" type="button" id="nb-cur-toggle" aria-label="{{ ___('Currency') }}">
                        <span id="nb-cur-label">{{ config('settings.currency_code', '—') }}</span>
                    </button>
                    <div class="nb-dropdown-menu">
                        <button type="button" data-cur="{{ config('settings.currency_code', 'USD') }}" data-rate="1">{{ config('settings.currency_code', 'USD') }}</button>
                        @foreach($mcRates as $code => $rate)
                        <button type="button" data-cur="{{ $code }}" data-rate="{{ $rate }}">{{ $code }}</button>
                        @endforeach
                    </div>
                </div>
                @endif
                <button class="nb-icon-btn nb-theme-btn" type="button" id="nb-theme-toggle" aria-label="{{ ___('Toggle theme') }}">
                    <i class="ico ico-moon" id="nb-theme-icon" aria-hidden="true"><x-ui.icon name="moon" /></i>
                </button>
                <button class="nb-icon-btn" type="button" id="nb-fav-toggle" aria-label="{{ ___('Favorites') }}">
                    <i class="ico ico-heart" aria-hidden="true"><x-ui.icon name="heart" /></i>
                    <span class="nb-badge" id="nb-fav-count">0</span>
                </button>
                @if($allow_order)
                <button class="nb-icon-btn nb-cart-btn" type="button" id="nb-cart-toggle" aria-label="{{ ___('Cart') }}">
                    <i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i>
                    <span class="nb-badge" id="nb-cart-count">0</span>
                </button>
                @endif
            </div>
        </div>
    </header>

    <div style="max-inline-size:1100px;margin-inline:auto;padding-inline:16px">
        @include('store_templates.includes.menu-banners-slider')
    </div>

    @foreach($sectionsToRender as $sect)
        @if($sect['visible'])
            @if($sect['id'] === 'hero')
            {{-- ================================================================= HERO --}}
            <section class="nb-hero" id="nb-hero">
                <div class="nb-hero-bg" style="background-image: url('{{ media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) }}');"
                     data-csp-error="parent-bg" data-csp-value="var(--nb-surface)"></div>
                <div class="nb-hero-overlay"></div>
                <div class="nb-container">
                    <div class="nb-hero-content">
                        <div class="nb-hero-main">
                            <span class="nb-badge-live"><span class="nb-pulse-dot"></span> {{ ___('Digital Menu') }}</span>
                            <h1 class="nb-grad-text">{{ $store->title }}</h1>
                            <p class="nb-hero-desc">{{ $store->sub_title ?: strip_tags((string) $store->description) ?: ___('A cosmic dining experience. Browse, customize and order in seconds.') }}</p>
                            <div class="nb-hero-meta">
                                @if($store->address)
                                <span class="nb-meta-chip"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i> {{ $store->address }}</span>
                                @endif
                                @if($store->phone)
                                <span class="nb-meta-chip"><i class="ico ico-phone" aria-hidden="true"><x-ui.icon name="phone" /></i> {{ $store->phone }}</span>
                                @endif
                                @if($table)
                                <span class="nb-meta-chip"><i class="ico ico-armchair" aria-hidden="true"><x-ui.icon name="armchair" /></i> {{ ___('Table') }} {{ $table->table_no }}</span>
                                @endif
                                <span class="nb-meta-chip"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ ___('Premium Dining') }}</span>
                            </div>
                            <div class="nb-search-row">
                                <div class="nb-search">
                                    <i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i>
                                    <input type="text" id="nb-search-input" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock" placeholder="{{ ___('Search dishes...') }}">
                                    <button class="nb-search-clear" type="button" id="nb-search-clear" aria-label="{{ ___('Clear') }}">
                                        <i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i>
                                    </button>
                                </div>
                                <button class="nb-filter-btn" type="button" id="nb-filter-toggle" aria-label="{{ ___('Filters') }}">
                                    <i class="ico ico-adjustments-horizontal" aria-hidden="true"><x-ui.icon name="adjustments-horizontal" /></i>
                                </button>
                            </div>
                            <div class="nb-filters-panel" id="nb-filters-panel">
                                <div class="nb-filters-grid">
                                    <div class="nb-filter-group">
                                        <h4>{{ ___('Dietary') }}</h4>
                                        <div class="nb-filter-chips">
                                            <button class="nb-filter-chip" type="button" data-filter="vegan"><i class="ico ico-leaf" aria-hidden="true"><x-ui.icon name="leaf" /></i> {{ ___('Vegan') }}</button>
                                            <button class="nb-filter-chip" type="button" data-filter="halal"><i class="ico ico-rosette-discount-check" aria-hidden="true"><x-ui.icon name="rosette-discount-check" /></i> {{ ___('Halal') }}</button>
                                            <button class="nb-filter-chip" type="button" data-filter="gf"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i> {{ ___('Gluten Free') }}</button>
                                            <button class="nb-filter-chip" type="button" data-filter="popular"><i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ ___('Popular') }}</button>
                                        </div>
                                    </div>
                                    <div class="nb-filter-group">
                                        <h4>{{ ___('Spice') }}</h4>
                                        <div class="nb-filter-chips">
                                            <button class="nb-filter-chip" type="button" data-filter="spice-0">{{ ___('Mild') }}</button>
                                            <button class="nb-filter-chip" type="button" data-filter="spice-1">🌶</button>
                                            <button class="nb-filter-chip" type="button" data-filter="spice-2">🌶🌶</button>
                                            <button class="nb-filter-chip" type="button" data-filter="spice-3">🌶🌶🌶</button>
                                        </div>
                                    </div>
                                    @if(count($allergies) > 0)
                                    <div class="nb-filter-group">
                                        <h4>{{ ___('Exclude') }}</h4>
                                        <div class="nb-filter-chips">
                                            @foreach($allergies as $al)
                                            <button class="nb-filter-chip nb-allergen-chip" type="button" data-allergen-id="{{ $al->id }}">
                                                {{ !empty($al->translations->{get_lang()}->title) ? $al->translations->{get_lang()}->title : $al->title }}
                                            </button>
                                            @endforeach
                                        </div>
                                    </div>
                                    @endif
                                </div>
                                <div class="nb-filters-foot">
                                    <button class="nb-btn nb-btn-sm" type="button" id="nb-filter-clear"><i class="ico ico-rotate-clockwise" aria-hidden="true"><x-ui.icon name="rotate-clockwise" /></i> {{ ___('Clear All') }}</button>
                                </div>
                            </div>
                        </div>
                        @if($featured)
                        <div class="nb-featured-card" @if(!$featured->is_sold_out) data-open-item="{{ $featured->id }}" @endif>
                            <div class="nb-featured-badge">{{ ___("Chef's Pick") }}</div>
                            @if($featured->is_sold_out)
                            <span class="nb-badge-soldout"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i> {{ ___('Sold Out') }}</span>
                            @endif
                            <div class="nb-featured-img">
                                @if((string) (media_url('storage/menu/', $featured->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $featured->image, $store->id ?? null) }}" alt="{{ $featured->name }}"
                                     data-csp-error="parent-hide">@endif
                            </div>
                            <div class="nb-featured-info">
                                <h3>{{ $featured->name }}</h3>
                                <div class="nb-price" data-base-price="{{ $featured->price }}">{{ price_symbol_format($featured->price) }}</div>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </section>

            @elseif($sect['id'] === 'catbar')
            {{-- ============================================================== CATEGORY CHIPS --}}
            <div class="nb-catbar" id="nb-catbar">
                <div class="nb-container">
                    <div class="nb-chips" id="nb-chips">
                        <button class="nb-chip active" type="button" data-target="all">
                            <span class="nb-chip-icon">✦</span> {{ ___('All') }}
                        </button>
                        @foreach($categories as $cat)
                            @php
                                $subIds   = $cat->subcategories->pluck('id')->all();
                                $catCount = ($byCat[$cat->id] ?? collect())->count();
                                foreach ($subIds as $sid) { $catCount += ($byCat[$sid] ?? collect())->count(); }
                            @endphp
                            @if($catCount > 0)
                            <button class="nb-chip" type="button" data-target="cat-{{ $cat->id }}">
                                {{ $cat->name }}
                            </button>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>

            @elseif($sect['id'] === 'daily_specials')
            {{-- ============================================================== DAILY SPECIALS --}}
            @php $dailySpecials = \Juwri\Core\Support\ViewHelpers::flagged($items, 'is_daily_special'); @endphp
            @if($dailySpecials->count() > 0)
            <section class="nb-section" id="nb-daily-specials">
                <div class="nb-container">
                    <div class="nb-section-head">
                        <span class="nb-kicker">⭐ {{ ___("Today's Specials") }}</span>
                        <h2>{{ ___("Chef's Daily Selection") }}</h2>
                    </div>
                    <div class="nb-rail" id="nb-daily-rail">
                        @foreach($dailySpecials->take(8) as $sp)
                        <div class="nb-rail-card" @if(!$sp->is_sold_out) data-open-item="{{ $sp->id }}" @endif>
                            @if($sp->is_sold_out)
                            <span class="nb-badge-soldout"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i> {{ ___('Sold Out') }}</span>
                            @endif
                            <div class="nb-rail-img">
                                @if((string) (media_url('storage/menu/', $sp->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $sp->image, $store->id ?? null) }}" alt="{{ $sp->name }}" loading="lazy"
                                     data-csp-error="parent-hide">@endif
                            </div>
                            <div class="nb-rail-body">
                                <span class="nb-rail-tag">{{ ___('Today Only') }}</span>
                                <h4>{{ $sp->name }}</h4>
                                <div class="nb-rail-foot">
                                    <span class="nb-price" data-base-price="{{ $sp->price }}">{{ price_symbol_format($sp->price) }}</span>
                                    @if(!empty($sp->calories))
                                    <span class="nb-kcal">{{ $sp->calories }} kcal</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </section>
            @endif

            @elseif($sect['id'] === 'specials')
            {{-- ============================================================== CHEF'S SPECIALS --}}
            @if($specials->count() > 2)
            <section class="nb-section" id="nb-specials">
                <div class="nb-container">
                    <div class="nb-section-head">
                        <span class="nb-kicker">{{ ___('Handpicked For You') }}</span>
                        <h2>{{ ___("Chef's Specials") }}</h2>
                    </div>
                    <div class="nb-rail" id="nb-specials-rail">
                        @foreach($specials as $rank => $sp)
                        <div class="nb-rail-card" @if(!$sp->is_sold_out) data-open-item="{{ $sp->id }}" @else style="opacity:0.6" @endif>
                            @if($sp->is_sold_out)
                            <span class="nb-badge-soldout"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i> {{ ___('Sold Out') }}</span>
                            @endif
                            <div class="nb-rail-img">
                                @if((string) (media_url('storage/menu/', $sp->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $sp->image, $store->id ?? null) }}" alt="{{ $sp->name }}" loading="lazy"
                                     data-csp-error="parent-hide">@endif
                            </div>
                            <div class="nb-rail-body">
                                <span class="nb-rail-tag">#{{ $rank + 1 }} {{ ___('Popular') }}</span>
                                <h4>{{ $sp->name }}</h4>
                                <div class="nb-rail-foot">
                                    <span class="nb-price" data-base-price="{{ $sp->price }}">{{ price_symbol_format($sp->price) }}</span>
                                    <span class="nb-add-icon"><i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i></span>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </section>
            @endif

            @elseif($sect['id'] === 'menu')
            {{-- ============================================================ MAIN MENU --}}
            <div class="nb-container" id="nb-menu-root">
                @include('store_templates.partials.branch-switcher')
                @foreach($categories as $cat)
                    @php
                        $direct  = $byCat[$cat->id] ?? collect();
                        $subcats = $cat->subcategories;
                        $hasSub  = $subcats->contains(fn($s) => ($byCat[$s->id] ?? collect())->count() > 0);
                    @endphp
                    @if($direct->count() > 0 || $hasSub)
                    <section class="nb-section nb-cat-section" id="cat-{{ $cat->id }}" data-cat="{{ $cat->id }}">
                        <div class="nb-section-head">
                            <span class="nb-kicker">{{ ___('Menu') }}</span>
                            <h2>{{ $cat->name }}</h2>
                        </div>

                        @if($direct->count() > 0)
                        <div class="nb-grid">
                            @foreach($direct as $item)
                            <div class="nb-card" data-id="{{ $item->id }}"
                                 data-name="{{ strtolower($item->name) }}"
                                 data-desc="{{ strtolower(strip_tags((string) $item->description)) }}"
                                 data-vegan="{{ $item->is_vegan ? '1' : '0' }}"
                                 data-halal="{{ $item->is_halal ? '1' : '0' }}"
                                 data-gf="{{ $item->is_gluten_free ? '1' : '0' }}"
                                 data-popular="{{ $item->is_popular ? '1' : '0' }}"
                                 data-spice="{{ $item->spice_level ?? '0' }}"
                                 data-allergens="{{ $item->allergies ?? '' }}"
                                 data-price="{{ $item->price }}"
                                 @if(!$item->is_sold_out) data-open-item="{{ $item->id }}" @endif>
                                @if($item->is_sold_out)
                                <div class="nb-card-soldout"><span>{{ ___('Sold Out') }}</span></div>
                                @endif
                                @if($item->is_popular)
                                <span class="nb-card-badge nb-badge-popular"><i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ ___('Popular') }}</span>
                                @endif
                                @if(!empty($item->is_daily_special))
                                <span class="nb-card-badge nb-badge-daily">⭐ {{ ___('Daily') }}</span>
                                @endif
                                <div class="nb-card-img">
                                    @if((string) (media_url('storage/menu/', $item->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $item->image, $store->id ?? null) }}" alt="{{ $item->name }}"
                                         loading="lazy" data-csp-error="parent-class" data-csp-class="no-img">@endif
                                    <div class="nb-card-img-shimmer"></div>
                                </div>
                                <div class="nb-card-body">
                                    <div class="nb-card-top">
                                        <span class="nb-card-type nb-type-{{ $item->type === 'nonveg' ? 'nonveg' : 'veg' }}">
                                            {{ $item->type === 'nonveg' ? '🍖' : '🥬' }}
                                        </span>
                                        @if(!empty($item->calories))
                                        <span class="nb-card-kcal">{{ $item->calories }} kcal</span>
                                        @endif
                                    </div>
                                    <h3 class="nb-card-title">{{ $item->name }}</h3>
                                    @if($item->description)
                                    <p class="nb-card-desc">{{ Str::limit(strip_tags((string) $item->description), 80) }}</p>
                                    @endif
                                    <div class="nb-card-meta">
                                        @if($item->is_vegan)<span class="nb-diet-tag">{{ ___('Vegan') }}</span>@endif
                                        @if($item->is_halal)<span class="nb-diet-tag">{{ ___('Halal') }}</span>@endif
                                        @if($item->is_gluten_free)<span class="nb-diet-tag">{{ ___('GF') }}</span>@endif
                                        @if($item->spice_level > 0)
                                        <span class="nb-spice">@for($s=0;$s<$item->spice_level;$s++)🌶@endfor</span>
                                        @endif
                                        @if(!empty($item->rating_avg) && $item->rating_avg > 0)
                                        <span class="nb-rating"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ number_format($item->rating_avg, 1) }}</span>
                                        @endif
                                    </div>
                                    <div class="nb-card-foot">
                                        <span class="nb-price" data-base-price="{{ $item->price }}">{{ price_symbol_format($item->price) }}</span>
                                        @if(!$item->is_sold_out)
                                        <button class="nb-add-btn" type="button" data-id="{{ $item->id }}">
                                            <i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i>
                                        </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @endif

                        @foreach($subcats as $sub)
                            @php $subItems = $byCat[$sub->id] ?? collect(); @endphp
                            @if($subItems->count() > 0)
                            <div class="nb-section-head" style="margin-top:32px">
                                <h3 style="font-size:18px;font-weight:600">{{ $sub->name }}</h3>
                            </div>
                            <div class="nb-grid">
                                @foreach($subItems as $item)
                                <div class="nb-card" data-id="{{ $item->id }}"
                                     data-name="{{ strtolower($item->name) }}"
                                     data-desc="{{ strtolower(strip_tags((string) $item->description)) }}"
                                     data-vegan="{{ $item->is_vegan ? '1' : '0' }}"
                                     data-halal="{{ $item->is_halal ? '1' : '0' }}"
                                     data-gf="{{ $item->is_gluten_free ? '1' : '0' }}"
                                     data-popular="{{ $item->is_popular ? '1' : '0' }}"
                                     data-spice="{{ $item->spice_level ?? '0' }}"
                                     data-allergens="{{ $item->allergies ?? '' }}"
                                     data-price="{{ $item->price }}"
                                     @if(!$item->is_sold_out) data-open-item="{{ $item->id }}" @endif>
                                    @if($item->is_sold_out)
                                    <div class="nb-card-soldout"><span>{{ ___('Sold Out') }}</span></div>
                                    @endif
                                    <div class="nb-card-img">
                                        @if((string) (media_url('storage/menu/', $item->image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/menu/', $item->image, $store->id ?? null) }}" alt="{{ $item->name }}"
                                             loading="lazy" data-csp-error="parent-class" data-csp-class="no-img">@endif
                                    </div>
                                    <div class="nb-card-body">
                                        <div class="nb-card-top">
                                            <span class="nb-card-type nb-type-{{ $item->type === 'nonveg' ? 'nonveg' : 'veg' }}">
                                                {{ $item->type === 'nonveg' ? '🍖' : '🥬' }}
                                            </span>
                                        </div>
                                        <h3 class="nb-card-title">{{ $item->name }}</h3>
                                        @if($item->description)
                                        <p class="nb-card-desc">{{ Str::limit(strip_tags((string) $item->description), 80) }}</p>
                                        @endif
                                        <div class="nb-card-foot">
                                            <span class="nb-price" data-base-price="{{ $item->price }}">{{ price_symbol_format($item->price) }}</span>
                                            @if(!$item->is_sold_out)
                                            <button class="nb-add-btn" type="button" data-id="{{ $item->id }}">
                                                <i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i>
                                            </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                            @endif
                        @endforeach
                    </section>
                    @endif
                @endforeach

                <div class="nb-empty" id="nb-empty">
                    <div class="nb-empty-icon"><i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i></div>
                    <h3>{{ ___('No dishes found') }}</h3>
                    <p>{{ ___('Try a different search or category.') }}</p>
                </div>
            </div>
            @endif
        @endif
    @endforeach

    @if($items->count() === 0)
    <div class="nb-empty show" style="padding:80px 20px">
        <div class="nb-empty-icon"><i class="ico ico-dine-in" aria-hidden="true"><x-ui.icon name="dine-in" /></i></div>
        <h3>{{ ___('Menu coming soon') }}</h3>
        <p>{{ ___('This restaurant has not added any dishes yet.') }}</p>
    </div>
    @endif

    {{-- ================================================================= CALL WAITER / REQUEST BILL --}}
    @if(@$postOptions->allow_call_waiter)
    <div class="nb-container" style="padding-top:0;padding-bottom:32px">
        <div class="nb-waiter-bar">
            <button type="button" id="nb-call-waiter-btn" class="nb-waiter-btn">
                <i class="ico ico-bell" aria-hidden="true"><x-ui.icon name="bell" /></i> {{ ___('Call Waiter') }}
            </button>
            <button type="button" id="nb-request-bill-btn" class="nb-bill-btn">
                <i class="ico ico-receipt" aria-hidden="true"><x-ui.icon name="receipt" /></i> {{ ___('Request Bill') }}
            </button>
        </div>
    </div>
    @endif

    {{-- ================================================================ FOOTER --}}
    <footer class="nb-footer">
        <div class="nb-container">
            <div class="nb-footer-inner">
                <div class="nb-footer-brand">
                    @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}"
                         alt="{{ $store->title }}" data-csp-error="hide">@endif
                    <div>
                        <strong>{{ $store->title }}</strong>
                        <span>{{ ___('Digital Menu') }}</span>
                    </div>
                </div>
                <div class="nb-footer-meta">
                    @if($store->address)
                    <span><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i> {{ $store->address }}</span>
                    @endif
                    @if($store->phone)
                    <span><i class="ico ico-phone" aria-hidden="true"><x-ui.icon name="phone" /></i> {{ $store->phone }}</span>
                    @endif
                </div>
                <div class="nb-footer-copy">
                    &copy; {{ date('Y') }} {{ $store->title }}. {{ ___('All rights reserved.') }}
                </div>
            </div>
        </div>
    </footer>
</div>

{{-- ================================================================ DISH DETAIL BOTTOM SHEET --}}
<div class="nb-sheet-overlay" id="nb-detail-overlay"></div>
<div class="nb-sheet" id="nb-detail-sheet" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="nb-sheet-scrim" data-close-sheet="detail"></div>
    <div class="nb-sheet-panel">
        <button class="nb-sheet-close" type="button" data-close-sheet="detail" aria-label="Close"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        <div class="nb-sheet-content" id="nb-detail-content"></div>
    </div>
</div>

{{-- ================================================================ CART SLIDE-IN PANEL --}}
<div class="nb-cart-overlay" id="nb-cart-overlay" data-close-cart></div>
<div class="nb-cart-panel" id="nb-cart-panel" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="nb-cart-header">
        <h3><i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i> {{ ___('Your Order') }}</h3>
        <button class="nb-cart-close" type="button" data-close-cart aria-label="Close"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
    </div>
    <div class="nb-cart-body" id="nb-cart-body">
        <div class="nb-cart-empty">
            <i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i>
            <p>{{ ___('Your cart is empty') }}</p>
            <span>{{ ___('Browse the menu to add items') }}</span>
        </div>
        <div class="nb-cart-items" id="nb-cart-items"></div>
    </div>
    <div class="nb-cart-footer" id="nb-cart-footer" style="display:none">
        <div class="nb-cart-totals" id="nb-cart-totals"></div>
        <div class="nb-cart-checkout-row">
            <div class="nb-coupon-row">
                <input type="text" id="nb-coupon-input" placeholder="{{ ___('Coupon code') }}">
                <button type="button" id="nb-coupon-apply">{{ ___('Apply') }}</button>
            </div>
            <div id="nb-coupon-msg" class="nb-coupon-msg"></div>
            @if($customer && $storeCredit > 0)
            {{-- Store credit (G3) --}}
            <label class="nb-coupon-row" style="display:flex;align-items:center;gap:8px;font-size:.9em;">
                <input type="checkbox" name="use_wallet" value="1" id="mbUseWallet">
                <span>{{ ___('Pay with store credit') }} ({{ $curSign }}{{ number_format($storeCredit, currency_decimals()) }} {{ ___('available') }})</span>
            </label>
            @endif
            @if(!empty($postOptions->gift_cards_enabled))
            <div class="nb-coupon-row">
                <input type="text" id="nb-gift-card-input" name="gift_card_code" placeholder="{{ ___('Gift card code') }}">
            </div>
            @endif
        </div>
        <div class="nb-cart-checkout-actions">
            <div class="nb-order-type">
                @if($allow_on_table)<button class="nb-type-btn active" data-type="on-table"><i class="ico ico-armchair" aria-hidden="true"><x-ui.icon name="armchair" /></i> {{ ___('On Table') }}</button>@endif
                @if($allow_takeaway)<button class="nb-type-btn" data-type="takeaway"><i class="ico ico-box" aria-hidden="true"><x-ui.icon name="box" /></i> {{ ___('Takeaway') }}</button>@endif
                @if($allow_delivery)<button class="nb-type-btn" data-type="delivery"><i class="ico ico-truck" aria-hidden="true"><x-ui.icon name="truck" /></i> {{ ___('Delivery') }}</button>@endif
            </div>
            @if($allowOrderSched)
            <div class="nb-cart-sched">
                <label for="nb-order-sched" style="display:block;font-size:.82em;opacity:.75;margin-bottom:4px">{{ ___('Schedule for later') }} ({{ ___('optional') }})</label>
                <input type="datetime-local" name="scheduled_at" id="nb-order-sched" min="{{ now()->addMinutes((int)($postOptions->scheduling_lead_minutes ?? 30))->format('Y-m-d\TH:i') }}" max="{{ now()->addDays((int)($postOptions->scheduling_max_days ?? 7))->format('Y-m-d\TH:i') }}">
            </div>
            @endif
            {{-- T5: Curbside pickup (takeaway) --}}
            @if(!empty($postOptions->curbside_enabled))
            <div class="nb-cart-sched">
                <label style="display:flex;align-items:center;gap:8px;font-size:.82em;cursor:pointer;margin-bottom:4px">
                    <input type="checkbox" name="curbside" value="1" id="nb-curbside" data-csp-toggle="nb-curbside-fields">
                    <span>{{ ___("Curbside pickup — I'll wait in my car") }}</span>
                </label>
                <div id="nb-curbside-fields" style="display:none;">
                    <input type="text" name="vehicle_make" id="nb-vehicle-make" placeholder="{{ ___('Car make/model') }}" style="margin-bottom:8px;">
                    <input type="text" name="vehicle_color" id="nb-vehicle-color" placeholder="{{ ___('Colour') }}" style="margin-bottom:8px;">
                    <input type="text" name="vehicle_plate" id="nb-vehicle-plate" placeholder="{{ ___('Plate') }}">
                </div>
            </div>
            @endif
            <button type="button" class="nb-checkout-btn" id="nb-checkout-btn">
                <i class="ico ico-check" aria-hidden="true"><x-ui.icon name="check" /></i> {{ ___('Place Order') }}
            </button>
        </div>
    </div>
</div>

{{-- ================================================================ WAITER SUCCESS MODAL --}}
<div class="nb-waiter-toast" id="nb-waiter-toast" style="display:none">
    <i class="ico ico-circle-check" aria-hidden="true"><x-ui.icon name="circle-check" /></i>
    <span id="nb-waiter-msg"></span>
</div>

{{-- ================================================================ LOGIN MODAL --}}
<div class="nb-sheet-overlay" id="nb-login-overlay" style="display:none"></div>
<div class="nb-sheet" id="nb-login-sheet" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="nb-sheet-scrim" data-close-sheet="login"></div>
    <div class="nb-sheet-panel" style="max-width:400px;margin:auto">
        <button class="nb-sheet-close" type="button" data-close-sheet="login" aria-label="Close"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        <div class="nb-sheet-content" style="padding:32px">
            <h3 style="margin-bottom:12px">{{ ___('Sign In') }}</h3>
            <form id="nb-login-form">
                @csrf
                <input type="hidden" name="store_id" value="{{ $store->id }}">
                <div class="nb-form-group">
                    <input type="email" name="email" placeholder="{{ ___('Email') }}" required>
                </div>
                <div class="nb-form-group">
                    <input type="password" name="password" placeholder="{{ ___('Password') }}" required>
                </div>
                <button type="submit" class="nb-btn nb-btn-primary" style="width:100%">{{ ___('Sign In') }}</button>
                <p style="margin-top:12px;text-align:center;font-size:13px">
                    @php $registerUrl = route('customer.register', $store->id); @endphp
                    {{ ___("Don't have an account?") }} <a href="{{ $registerUrl }}" style="color:var(--nb-accent)">{{ ___('Register') }}</a>
                </p>
            </form>
        </div>
    </div>
</div>

{{-- ================================================================ RESUME BANNER --}}
<div class="nb-resume-banner" id="nb-resume-banner">
    <span id="nb-resume-text"></span>
    <button type="button" id="nb-resume-clear-btn"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
</div>

@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
window.NEBULA = {
    slug: @json($store->slug),
    orderUrl: @json(route('store.submitOrder', $store->id)),
    csrf: @json(csrf_token()),
    curSign: @json($curSign),
    curPos: @json($curPos === '2' ? 'right' : 'left'),
    currencyDecimals: @json(currency_decimals()),
    /* the switcher converts to other currencies, so decimals must follow the
       DISPLAYED code, not the store's — see currency_decimals_map(). */
    decimalsByCode: @json(currency_decimals_map()),
    baseCurrency: @json(config('settings.currency_code', 'USD')),
    customerAuth: @json($authMode),
    storeId: @json($store->id),
    restaurantSlug: @json($store->slug),
    tableNo: @json($table ? $table->id : null),
    callWaiterUrl: @json(route('store.callTheWaiter', $store->id)),
    loyaltyBalanceUrl: @json(route('publicView.loyalty.balance', $store->id)),
    loyaltyRedeemUrl: @json(route('publicView.loyalty.redeem', $store->id)),
    waiterUrl: @json(route('store.callTheWaiter', $store->id)),
    multiCurrency: @json(!empty($postOptions->enable_multi_currency) && count($mcRates) > 0),
    allowOrderSched: @json($allowOrderSched),
    deliveryCharge: @json($deliveryCharge),
    taxCharge: @json($taxCharge),
    minOrderValue: @json($minOrderValue),
    items: @json($jsItems),
    categories: @json($categories->map(fn($c)=>['id'=>$c->id,'name'=>$c->name])),
    allergies: @json($allergies->map(fn($a)=>['id'=>$a->id,'title'=>$a->title])),
    /* strings script.js reads as CFG.translations.* (the table was never handed over: dish sheet, call waiter and resume banner threw) */
    translations: {
        remove:      @json(___('Remove')),
        dailySpecial: @json(___('Daily special')),
        nutrition:   @json(___('Nutrition facts')),
        protein:     @json(___('Protein')),
        carbs:       @json(___('Carbs')),
        fat:         @json(___('Fat')),
        allergens:   @json(___('Allergens')),
        options:     @json(___('Options')),
        extras:      @json(___('Extras')),
        addedToCart: @json(___('Added to cart')),
        orderSent:   @json(___('Order placed! Thank you.')),
        orderFailed: @json(___('Could not place order. Try again.')),
        noTable:     @json(___('Your table number')),
        sent:        @json(___('A waiter will be with you shortly!')),
        error:       @json(___('Something went wrong. Please try again.')),
        resumeText:  @json('{n} '.___('items in your cart from your last visit.')),
        addToCart:   @json(___('Add to cart')),
        callWaiter:  @json(___('Call Waiter')),
        requestBill: @json(___('Request Bill')),
        placeOrder:  @json(___('Place order')),
        processing:  @json(___('Processing')),
        popular:     @json(___('Popular')),
        soldOut:     @json(___('Sold out')),
        vegan:       @json(___('Vegan')),
        halal:       @json(___('Halal')),
        gf:          @json(___('Gluten Free')),
    },
};
</script>
{{-- script.js is loaded once by store_templates.layout (avoids double-load) --}}
</div> <!-- .nebula -->
@endpush
