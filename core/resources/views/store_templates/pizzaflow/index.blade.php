{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')
@php
    // P6-95: the store colour is the accent. Whatever it is, the text on an accent button, and the active tab on the navy
    // bottom bar, must stay readable (WCAG AA): pick the ink for the button, and fall back to white on the bar.
    $pfBrand = (string) theme_setting($store->id, 'colors.primary', (string) ($store->color ?? ''));
    $pfBrand = preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $pfBrand) === 1 ? $pfBrand : '#f6bd2f';
    $pfOnAccent = \Juwri\Modules\Storefront\Services\Theme\Tokens::readableInk($pfBrand);
    $pfOnNavy = \Juwri\Modules\Storefront\Services\Theme\Tokens::contrastRatio($pfBrand, '#0d1428') >= 4.5 ? $pfBrand : '#ffffff';
@endphp
@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    <style nonce="{{ csp_nonce() }}">.pizzaflow{--pf-on-accent:{{ $pfOnAccent }};--pf-accent-on-navy:{{ $pfOnNavy }};}</style>
@endpush

@php
    $curSign = $postOptions->currency_sign ?? '$';
    $curPos  = (string) ($postOptions->currency_pos ?? '1');

    $menusJson = [];
    foreach ($total_menus as $m) {
        $img = $m->image && $m->image !== 'default.png' ? asset('storage/menu/'.$m->image) : asset('storage/menu/default.png');
        $menusJson[$m->id] = [
            'id' => $m->id, 'category_id' => $m->category_id,
            'name' => $m->name, 'description' => \Str::limit($m->description, 120),
            'price' => (float) $m->price, 'image' => $img, 'type' => $m->type,
            'is_sold_out' => (bool) $m->is_sold_out || ($m->track_inventory && $m->stock_qty !== null && $m->stock_qty <= 0),
            'is_popular' => (bool) $m->is_popular,
            'is_vegan' => (bool) $m->is_vegan, 'is_halal' => (bool) $m->is_halal,
            'is_gluten_free' => (bool) $m->is_gluten_free,
            'spice_level' => $m->spice_level,
            'rating_avg' => $m->rating_avg ? round((float) $m->rating_avg, 1) : null,
            'rating_count' => (int) $m->rating_count, 'calories' => $m->calories,
            'variants' => $m->variants->map(fn($v) => ['id' => $v->id, 'price' => (float) $v->price, 'options' => $v->options])->values()->toArray(),
            'variantOptions' => $m->variantOptions->map(fn($vo) => ['id' => $vo->id, 'title' => $vo->title, 'options' => $vo->options])->values()->toArray(),
            'extras' => $m->extras->map(fn($e) => ['id' => $e->id, 'title' => $e->title, 'price' => (float) $e->price])->values()->toArray(),
            'allergies' => $m->allergies,
        ];
    }
    $categoriesJson = $store->menu_categories->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->toArray();
    $popularIds = collect($total_menus)->filter(fn($m) => $m->is_popular && !$m->is_sold_out)->take(6)->pluck('id')->toArray();
    $menusByCat = [];
    foreach ($total_menus as $m) { $menusByCat[$m->category_id][] = $m; }
    $restaurantLogo = $store->main_image && $store->main_image !== 'default.png'
        ? asset('storage/store/logo/'.$store->main_image) : (! empty($settings->site_logo) ? asset('storage/logo/'.$settings->site_logo) : '');
@endphp

@section('content')
<div class="pizzaflow" id="pf-root">
    <div class="pf-ambient"></div>

    {{-- ============ TOP BAR ============ --}}
    <header class="pf-topbar" id="pf-topbar">
        <div class="pf-wrap">
            <a href="{{ route('publicView', $store->slug) }}" class="pf-brand-mini">
                @if($restaurantLogo !== '')<img src="{{ $restaurantLogo }}" alt="{{ $store->title }}" data-csp-error="hide">@endif
                <b>{{ $store->title }}</b>
            </a>
            <div class="pf-topbar-actions">
                @if(!empty($menu_languages) && $menu_languages->count() > 1)
                <button class="pf-icon-btn" type="button" aria-label="{{ ___('Change language') }}">
                    <i class="ico ico-world" aria-hidden="true"><x-ui.icon name="world" /></i>
                </button>
                @endif
                <button class="pf-theme-toggle" type="button" id="pf-theme-toggle" aria-label="{{ ___('Toggle theme') }}">
                    <i class="ico ico-moon" aria-hidden="true"><x-ui.icon name="moon" /></i>
                </button>
            </div>
        </div>
    </header>

    {{-- ============ HERO ============ --}}
    <section class="pf-hero">
        <div class="pf-wrap">
            <div class="pf-hero-card">
                @if($restaurantLogo !== '')<img src="{{ $restaurantLogo }}" alt="{{ $store->title }}" class="pf-hero-logo" data-csp-error="hide">@endif
                <h1>{{ $store->title }}</h1>
                @if($store->sub_title)
                    <p class="pf-hero-tag">{{ $store->sub_title }}</p>
                @endif
                @if($store->address || $store->phone || $scans_today)
                <div class="pf-hero-meta">
                    @if($scans_today > 0)
                        <span class="pf-chip-meta"><i class="ico ico-users" aria-hidden="true"><x-ui.icon name="users" /></i> {{ $scans_today }} {{ ___('scans today') }}</span>
                    @endif
                    @if($store->address)
                        <span class="pf-chip-meta"><i class="ico ico-pin" aria-hidden="true"><x-ui.icon name="pin" /></i> {{ $store->address }}</span>
                    @endif
                    @if($store->phone)
                        <span class="pf-chip-meta"><i class="ico ico-phone" aria-hidden="true"><x-ui.icon name="phone" /></i> {{ $store->phone }}</span>
                    @endif
                </div>
                @endif
                <div class="pf-search" id="pf-search-bar">
                    <i class="ico ico-search" aria-hidden="true"><x-ui.icon name="search" /></i>
                    <input type="text" id="pf-search-input" placeholder="{{ ___('Search menu...') }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
                    <button type="button" class="pf-search-clear" id="pf-search-clear" style="display:none">
                        <i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i>
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- ============ CATEGORY CHIPS ============ --}}
    <nav class="pf-catbar" id="pf-catbar">
        <div class="pf-wrap">
            <div class="pf-chips">
                <button class="pf-chip active" data-cat="all">📋 {{ ___('All') }}</button>
                @foreach($store->menu_categories as $cat)
                    <button class="pf-chip" data-cat="{{ $cat->id }}">{{ $cat->name }}</button>
                @endforeach
            </div>
        </div>
    </nav>

    {{-- ============ POPULAR CAROUSEL ============ --}}
    @if(count($popularIds))
    <section class="pf-section pf-section--popular" id="pf-pop-section">
        <div class="pf-wrap">
            <div class="pf-section-head">
                <h2>🔥 {{ ___('Most Loved') }}</h2>
            </div>
            <div class="pf-rail">
                @foreach($popularIds as $pid)
                    @if(isset($total_menus[$pid]))
                        @php $pm = $total_menus[$pid]; @endphp
                        <div class="pf-rail-item">
                            <div class="pf-spotlight" data-id="{{ $pm->id }}" data-pf-act="openDetail" data-pf-arg="{{ $pm->id }}" role="button" tabindex="0">
                                @if((string) ($pm->image && $pm->image !== 'default.png' ? asset('storage/menu/'.$pm->image) : asset('storage/menu/default.png')) !== '')<img src="{{ $pm->image && $pm->image !== 'default.png' ? asset('storage/menu/'.$pm->image) : asset('storage/menu/default.png') }}"
                                     alt="{{ $pm->name }}" loading="lazy" data-csp-error="fallback" data-csp-fallback="{{ asset('storage/menu/default.png') }}">@endif
                                <div class="pf-spotlight-body">
                                    <h4>{{ $pm->name }}</h4>
                                    <span class="pf-price">{{ price_symbol_format($pm->price) }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- ============ MENU GRID ============ --}}
    <section class="pf-section" id="pf-menu-section">
        <div class="pf-wrap">
            <div class="pf-section-head">
                <h2>🍕 {{ ___('Menu') }}</h2>
            </div>
            <div class="pf-grid" id="pf-menu-grid">
                @foreach($store->menu_categories as $cat)
                    @foreach($cat->subcategories as $sub)
                        @if(isset($menusByCat[$sub->id]))
                            @foreach($menusByCat[$sub->id] as $menu)
                                @include('store_templates.pizzaflow.includes.dish-card', ['menu' => $menu])
                            @endforeach
                        @endif
                    @endforeach
                    @if(isset($menusByCat[$cat->id]))
                        @foreach($menusByCat[$cat->id] as $menu)
                            @include('store_templates.pizzaflow.includes.dish-card', ['menu' => $menu])
                        @endforeach
                    @endif
                @endforeach
            </div>
            <div class="pf-empty" id="pf-empty">
                <div class="pf-empty-ico">🍽️</div>
                <h3>{{ ___('No items found') }}</h3>
                <p>{{ ___('Try a different category or search term.') }}</p>
            </div>
        </div>
    </section>

    {{-- ============ FOOTER ============ --}}
    <footer class="pf-foot">
        <div class="pf-wrap">
            @if($settings->facebook_link || $settings->instagram_link)
            <div class="pf-socials">
                @if($settings->facebook_link)<a href="{{ $settings->facebook_link }}" target="_blank" rel="nofollow"><i class="ico ico-brand-facebook" aria-hidden="true"><x-ui.icon name="brand-facebook" /></i></a>@endif
                @if($settings->instagram_link)<a href="{{ $settings->instagram_link }}" target="_blank" rel="nofollow"><i class="ico ico-brand-instagram" aria-hidden="true"><x-ui.icon name="brand-instagram" /></i></a>@endif
            </div>
            @endif
            <small>© {{ date('Y') }} {{ $settings->site_title }}</small>
        </div>
    </footer>

    {{-- ============ BOTTOM NAV ============ --}}
    <nav class="pf-bottom-nav" id="pf-bnav">
        <button class="pf-nav-item active" data-tab="menu">
            <i class="ico ico-layout-grid" aria-hidden="true"><x-ui.icon name="layout-grid" /></i><span>{{ ___('Menu') }}</span>
        </button>
        <button class="pf-nav-item" data-tab="favorites">
            <i class="ico ico-heart-filled" aria-hidden="true"><x-ui.icon name="heart-filled" /></i><span>{{ ___('Favorites') }}</span>
        </button>
        <button class="pf-nav-item" data-tab="popular">
            <i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i><span>{{ ___('Popular') }}</span>
        </button>
        <button class="pf-nav-item" id="pf-nav-cart" data-tab="cart">
            <i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i><span>{{ ___('Cart') }}</span>
            <span class="pf-badge-count" id="pf-cart-badge" style="display:none">0</span>
        </button>
    </nav>

    {{-- ============ FLOATING CART BAR ============ --}}
    <button class="pf-cartbar" id="pf-cartbar" style="display:none" data-pf-act="openCart">
        <div class="cb-ico"><i class="ico ico-shopping-bag" aria-hidden="true"><x-ui.icon name="shopping-bag" /></i></div>
        <div class="cb-text">
            <span id="pf-cartbar-count">0 {{ ___('items') }}</span>
        </div>
        <div class="cb-total" id="pf-cartbar-total">0</div>
    </button>

    {{-- ============ SCRIM ============ --}}
    <div id="pf-scrim" class="pf-scrim" data-pf-act="closeSheets"></div>

    {{-- ============ DETAIL SHEET ============ --}}
    <div class="pf-sheet" id="pf-detail-sheet" role="dialog" aria-modal="true" aria-labelledby="pf-detail-name">
        <div class="pf-sheet-head">
            <h3 id="pf-detail-name"></h3>
            <button type="button" class="pf-close" data-pf-act="closeDetail" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="pf-sheet-body">
            <div class="pf-detail-media"><img id="pf-detail-img" alt=""></div>
            <div class="pf-detail-title" id="pf-detail-title"></div>
            <div class="pf-detail-badges" id="pf-detail-badges"></div>
            <div class="pf-detail-desc" id="pf-detail-desc"></div>
            <div class="pf-detail-section" id="pf-detail-variants-section" style="display:none">
                <h4>{{ ___('Options') }}</h4>
                <div id="pf-detail-variants"></div>
            </div>
            <div class="pf-detail-section" id="pf-detail-extras-section" style="display:none">
                <h4>{{ ___('Extras') }}</h4>
                <div id="pf-detail-extras"></div>
            </div>
            <div class="pf-detail-section">
                <h4>{{ ___('Quantity') }}</h4>
                <div class="pf-qty">
                    <button type="button" data-pf-act="detailQty" data-pf-arg="-1" aria-label="{{ ___('Decrease quantity') }}">−</button>
                    <span id="pf-detail-qty" aria-live="polite">1</span>
                    <button type="button" data-pf-act="detailQty" data-pf-arg="1" aria-label="{{ ___('Increase quantity') }}">+</button>
                </div>
            </div>
        </div>
        <div class="pf-sheet-foot">
            <button type="button" class="pf-cta accent" id="pf-detail-add" data-pf-act="addToCart">
                {{ ___('Add to Cart') }} · <span id="pf-detail-total">0</span>
            </button>
        </div>
    </div>

    {{-- ============ CART SHEET ============ --}}
    <div class="pf-sheet" id="pf-cart-sheet" role="dialog" aria-modal="true" aria-labelledby="pf-cart-title">
        <div class="pf-sheet-head">
            <h3 id="pf-cart-title"><span aria-hidden="true">🛒</span> {{ ___('Your Order') }}</h3>
            <button type="button" class="pf-close" data-pf-act="closeCart" aria-label="{{ ___('Close') }}"><i class="ico ico-x" aria-hidden="true"><x-ui.icon name="x" /></i></button>
        </div>
        <div class="pf-sheet-body" id="pf-cart-body">
            <div id="pf-cart-items"></div>
            <div class="pf-cart-empty" id="pf-cart-empty">
                <div class="pf-cart-empty-ico">🛒</div>
                <p>{{ ___('Your cart is empty') }}</p>
            </div>
            <div id="pf-checkout" style="display:none">
                <div class="pf-totals">
                    <div class="tr"><span>{{ ___('Subtotal') }}</span><span id="pf-cart-subtotal">0</span></div>
                    <div class="tr grand"><span>{{ ___('Total') }}</span><span id="pf-cart-total">0</span></div>
                </div>
                <div class="pf-field">
                    <label for="pf-checkout-name">{{ ___('Your Name') }} *</label>
                    <input type="text" id="pf-checkout-name" required aria-required="true" autocomplete="name" placeholder="{{ ___('Enter your name') }}">
                </div>
                @if($allow_on_table || $allow_takeaway || $allow_delivery)
                <div class="pf-field">
                    <label id="pf-ordering-type-label">{{ ___('Order Type') }}</label>
                    <div class="pf-seg" id="pf-ordering-type-group" role="group" aria-labelledby="pf-ordering-type-label">
                        @if($allow_on_table)
                            <button type="button" class="active" data-type="on-table">{{ ___('Dine-in') }}</button>
                        @endif
                        @if($allow_takeaway)
                            <button type="button" class="{{ !$allow_on_table ? 'active' : '' }}" data-type="takeaway">{{ ___('Takeaway') }}</button>
                        @endif
                        @if($allow_delivery)
                            <button type="button" class="{{ !$allow_on_table && !$allow_takeaway ? 'active' : '' }}" data-type="delivery">{{ ___('Delivery') }}</button>
                        @endif
                    </div>
                </div>
                @endif
                <div class="pf-field" id="pf-field-table">
                    <label for="pf-checkout-table">{{ ___('Table Number') }}</label>
                    <input type="text" id="pf-checkout-table" placeholder="{{ ___('e.g. 12') }}" value="{{ $table ?? '' }}">
                </div>
                <div class="pf-field" id="pf-field-phone" style="display:none">
                    <label for="pf-checkout-phone">{{ ___('Phone Number') }} *</label>
                    <input type="tel" id="pf-checkout-phone" autocomplete="tel" placeholder="{{ ___('e.g. +123456789') }}">
                </div>
                <div class="pf-field" id="pf-field-address" style="display:none">
                    <label for="pf-checkout-address">{{ ___('Delivery Address') }} *</label>
                    <textarea id="pf-checkout-address" rows="2" placeholder="{{ ___('Enter your address') }}"></textarea>
                </div>
                <div class="pf-field">
                    <label for="pf-checkout-message">{{ ___('Special Instructions') }}</label>
                    <textarea id="pf-checkout-message" rows="2" placeholder="{{ ___('Any special requests?') }}"></textarea>
                </div>
                @if($allow_payment)
                <div class="pf-field">
                    <label id="pf-pay-label">{{ ___('Payment') }}</label>
                    <div class="pf-seg" id="pf-pay-group" role="group" aria-labelledby="pf-pay-label">
                        <button type="button" class="active" data-pay="pay_on_counter" aria-pressed="true">{{ ___('Pay at Counter') }}</button>
                        <button type="button" data-pay="pay_online" aria-pressed="false">{{ ___('Pay Online') }}</button>
                    </div>
                </div>
                @endif
                <div class="pf-field">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                        <input type="checkbox" id="pf-checkout-optin" value="1">
                        <span>{{ ___('Send me offers and updates on WhatsApp') }}</span>
                    </label>
                </div>
                <div id="pf-checkout-error" class="pf-form-error" role="alert" style="display:none"></div>
                <button type="button" class="pf-cta" data-pf-act="submitOrder">
                    {{ ___('Place Order') }} · <span id="pf-checkout-total">0</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ============ SUCCESS STATE (inside cart sheet) ============ --}}
    <div class="pf-success" id="pf-success" style="display:none">
        <div class="pf-ok">✓</div>
        <h2>{{ ___('Order Placed!') }}</h2>
        <p>{{ ___('Thank you. Your order is on its way.') }}</p>
        <button class="pf-cta" data-pf-act="closeSuccess" style="margin-top:16px">
            {{ ___('Order Again') }}
        </button>
    </div>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
window.PIZZAFLOW = {
    storeId: {{ $store->id }},
    slug: @json($store->slug),
    menus: @json($menusJson),
    categories: @json($categoriesJson),
    popularIds: @json($popularIds),
    csrf: @json(csrf_token()),
    submitOrderUrl: @json(route('store.submitOrder', $store->id)),
    allowOnTable: {{ $allow_on_table ? 'true' : 'false' }},
    allowTakeaway: {{ $allow_takeaway ? 'true' : 'false' }},
    allowDelivery: {{ $allow_delivery ? 'true' : 'false' }},
    allowPayment: {{ $allow_payment ? 'true' : 'false' }},
    text: {
        decrease: @json(___('Decrease quantity')),
        increase: @json(___('Increase quantity')),
        remove: @json(___('Remove')),
    },
    curSign: @json($curSign),
    curPos: @json($curPos),
};
</script>
@endpush
