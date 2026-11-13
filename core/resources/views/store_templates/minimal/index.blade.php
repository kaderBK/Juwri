{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* ── Data preparation ── */
    $allMenus = collect($total_menus)->values();
    $items = $allMenus;
    $byCat = $items->groupBy('category_id');
    $categories = $store->menu_categories;

    $curSign = $postOptions->currency_sign ?? '$';
    $curPos  = (string) ($postOptions->currency_pos ?? '1');
    $deliveryCharge = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
    $taxCharge      = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
    $minOrderValue  = (float) (@$postOptions->min_order_value ?: 0);

    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelNamed(...);

    /* JS items map */
    $jsItems = [];
    foreach ($items as $m) {
        $itemAllergies = empty($m->allergies) ? collect() : $allergies->whereIn('id', explode(',', $m->allergies));
        $variants = [];
        foreach (($m->variants ?? collect()) as $v) {
            $variants[] = ['id' => $v->id, 'label' => $variantLabel($m, $v), 'price' => (float) $v->price];
        }
        $extras = [];
        foreach (($m->extras ?? collect()) as $e) {
            $extras[] = ['id' => $e->id, 'title' => $e->title, 'price' => (float) $e->price];
        }
        $jsItems[$m->id] = [
            'id'         => $m->id,
            'name'       => $m->name,
            'desc'       => strip_tags((string) $m->description),
            'img'        => $m->image && $m->image !== 'default.png' ? asset('storage/menu/'.$m->image) : '',
            'price'      => (float) $m->price,
            'type'       => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'is_vegan'   => (bool) $m->is_vegan,
            'is_halal'   => (bool) $m->is_halal,
            'is_popular' => (bool) $m->is_popular,
            'is_sold_out'=> (bool) $m->is_sold_out,
            'variants'   => $variants,
            'extras'     => $extras,
            'allergies'  => \Juwri\Core\Support\ViewHelpers::allergyListPlain($itemAllergies),
        ];
    }
@endphp

@push('style_at_top')
<link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div id="mn-app">

    {{-- ── Header ── --}}
    <header class="mn-header">
        <div class="mn-header-inner">
            @if($store->main_image)
            <img src="{{ asset('storage/store/logo/'.$store->main_image) }}"
                 alt="{{ $store->title }}" class="mn-logo" data-csp-error="hide">
            @endif
            <div class="mn-brand">
                <div class="mn-brand-name">{{ $store->title }}</div>
                @if($store->sub_title)
                <div class="mn-brand-sub">{{ $store->sub_title }}</div>
                @endif
            </div>
            @if($allow_order)
            <button class="mn-cart-btn" id="mn-cart-toggle" style="display:none">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                <span class="mn-cart-count">0</span>
                <span class="mn-cart-total"></span>
            </button>
            @endif
        </div>
    </header>

    {{-- ── Cover image ── --}}
    @if($store->cover_image)
    <div class="mn-cover">
        <img src="{{ asset('storage/store/cover/'.$store->cover_image) }}"
             alt="{{ $store->title }}" data-csp-error="hide">
    </div>
    @endif

    {{-- ── Restaurant info ── --}}
    <div class="mn-info">
        <h1 class="mn-info-title">{{ $store->title }}</h1>
        @if($store->description)
        <p class="mn-info-desc">{{ Str::limit(strip_tags($store->description), 200) }}</p>
        @endif
        <div class="mn-info-meta">
            @if($store->address)
            <span>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                {{ $store->address }}
            </span>
            @endif
            @if($store->phone)
            <span>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                {{ $store->phone }}
            </span>
            @endif
        </div>
    </div>

    {{-- ── Search ── --}}
    <div class="mn-search-wrap">
        <input type="search" class="mn-search" id="mn-search"
               placeholder="{{ ___('Search menu…') }}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" data-bwignore readonly data-csp-focus="unlock">
    </div>

    {{-- ── Category nav ── --}}
    @if($categories->count() > 1)
    <nav class="mn-catnav" aria-label="{{ ___('Menu categories') }}">
        <div class="mn-catnav-inner">
            @foreach($categories as $cat)
            @if(($byCat[$cat->id] ?? collect())->count())
            <button class="mn-catbtn{{ $loop->first ? ' active' : '' }}" data-cat-id="{{ $cat->id }}">
                {{ $cat->name }}
            </button>
            @endif
            @endforeach
        </div>
    </nav>
    @endif

    {{-- ── Menu sections ── --}}
    <main class="mn-body">
        @forelse($categories as $cat)
        @php $catItems = $byCat[$cat->id] ?? collect(); @endphp
        @if($catItems->count())
        <section class="mn-section" data-mn-section="{{ $cat->id }}">
            <h2 class="mn-section-title">{{ $cat->name }}</h2>
            @foreach($catItems as $menu)
            <div class="mn-item{{ $menu->is_sold_out ? ' sold-out' : '' }}"
                 data-item-id="{{ $menu->id }}"
                 data-item-name="{{ addslashes($menu->name) }}"
                 role="button" tabindex="0" aria-label="{{ $menu->name }}">

                @if($menu->image && $menu->image !== 'default.png')
                <img src="{{ asset('storage/menu/'.$menu->image) }}"
                     alt="{{ $menu->name }}" class="mn-item-img"
                     loading="lazy" data-csp-error="hide">
                @endif

                <div class="mn-item-body">
                    <div class="mn-item-name">{{ $menu->name }}</div>
                    @if($menu->description)
                    <div class="mn-item-desc">{{ Str::limit(strip_tags($menu->description), 90) }}</div>
                    @endif
                    <div class="mn-item-meta">
                        <span class="mn-item-price">{{ price_symbol_format($menu->price) }}</span>
                        @if($menu->type === 'veg')
                            <span class="mn-item-badge badge-veg">{{ ___('Veg') }}</span>
                        @elseif($menu->type === 'nonveg')
                            <span class="mn-item-badge badge-nonveg">{{ ___('Non-veg') }}</span>
                        @endif
                        @if($menu->is_popular)
                            <span class="mn-item-badge badge-popular">{{ ___('Popular') }}</span>
                        @endif
                        @if($menu->is_sold_out)
                            <span class="mn-item-badge badge-sold">{{ ___('Sold out') }}</span>
                        @endif
                    </div>
                </div>

                @if($allow_order && !$menu->is_sold_out)
                <button class="mn-item-add" data-mn-item="{{ $menu->id }}"
                        aria-label="{{ ___('Add') }} {{ $menu->name }}">+</button>
                @endif
            </div>
            @endforeach
        </section>
        @endif
        @empty
        <div class="mn-no-items">{{ ___('No menu items available.') }}</div>
        @endforelse
    </main>

    {{-- ── Footer ── --}}
    <footer class="mn-footer">
        &copy; {{ $store->title }}@if(! store_plan_allows($store, 'hide_branding')) · {{ ___('Powered by') }} <a href="{{ route('home') }}" style="color:inherit">{{ config('settings.site_title', 'juwri') }}</a>@endif
    </footer>

</div>{{-- #mn-app --}}

{{-- ══════════════════════════════════════════════════════════════
     Detail sheet overlay
══════════════════════════════════════════════════════════════ --}}
@if($allow_order)
<div class="mn-sheet-overlay" id="mn-detail-overlay" role="dialog" aria-modal="true">
    <div class="mn-sheet" id="mn-detail-sheet" style="position:relative">
        <div class="mn-sheet-handle"></div>
        <button class="mn-sheet-close" data-mn-close="mn-detail-overlay" aria-label="{{ ___('Close') }}">✕</button>

        <img alt="" class="mn-detail-img" data-csp-error="hide">
        <div class="mn-detail-body">
            <h3 class="mn-detail-name"></h3>
            <p class="mn-detail-desc"></p>
            <div class="mn-detail-price"></div>

            <div class="mn-detail-section mn-detail-variants" style="display:none">
                <div class="mn-detail-section-title">{{ ___('Options') }}</div>
                <div class="mn-variant-list"></div>
            </div>

            <div class="mn-detail-section mn-detail-extras" style="display:none">
                <div class="mn-detail-section-title">{{ ___('Add-ons') }}</div>
                <div class="mn-extra-list"></div>
            </div>
        </div>

        <div class="mn-detail-actions">
            <div class="mn-qty-row">
                <button class="mn-qty-btn" id="mn-qty-dec" aria-label="{{ ___('Decrease') }}">−</button>
                <span class="mn-qty-val">1</span>
                <button class="mn-qty-btn" id="mn-qty-inc" aria-label="{{ ___('Increase') }}">+</button>
            </div>
            <button class="mn-add-btn" id="mn-detail-add">{{ ___('Add to cart') }}</button>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════
     Cart + checkout sheet overlay
══════════════════════════════════════════════════════════════ --}}
<div class="mn-sheet-overlay" id="mn-cart-overlay" role="dialog" aria-modal="true">
    <div class="mn-sheet" style="position:relative">
        <div class="mn-sheet-handle"></div>
        <button class="mn-sheet-close" data-mn-close="mn-cart-overlay" aria-label="{{ ___('Close') }}">✕</button>

        <h2 class="mn-cart-title">{{ ___('Your order') }}</h2>

        <div id="mn-cart-empty" class="mn-cart-empty">{{ ___('Your cart is empty.') }}</div>
        <div id="mn-cart-list"></div>

        <div id="mn-cart-footer" style="display:none">
            <div class="mn-cart-totals">
                <div class="mn-cart-total-row"><span>{{ ___('Subtotal') }}</span><span id="mn-total-sub">—</span></div>
                @if($deliveryCharge > 0)
                <div class="mn-cart-total-row"><span>{{ ___('Delivery') }}</span><span id="mn-total-delivery">—</span></div>
                @else
                <div style="display:none"><span id="mn-total-delivery"></span></div>
                @endif
                @if($taxCharge > 0)
                <div class="mn-cart-total-row"><span>{{ ___('Tax') }}</span><span id="mn-total-tax">—</span></div>
                @else
                <div style="display:none"><span id="mn-total-tax"></span></div>
                @endif
                <div class="mn-cart-total-row grand"><span>{{ ___('Total') }}</span><span id="mn-total-grand">—</span></div>
            </div>

            {{-- Checkout form --}}
            <div class="mn-checkout-form">
                <div id="mn-checkout-alert" class="mn-alert"></div>

                {{-- Order type --}}
                <div class="mn-checkout-section">
                    <div class="mn-checkout-section-title">{{ ___('Order type') }}</div>
                    <div class="mn-type-group">
                        @if($allow_on_table)
                        <button class="mn-type-btn active" data-type="on-table">{{ ___('Dine In') }}</button>
                        @endif
                        @if($allow_takeaway)
                        <button class="mn-type-btn{{ !$allow_on_table ? ' active' : '' }}" data-type="takeaway">{{ ___('Takeaway') }}</button>
                        @endif
                        @if($allow_delivery)
                        <button class="mn-type-btn{{ !$allow_on_table && !$allow_takeaway ? ' active' : '' }}" data-type="delivery">{{ ___('Delivery') }}</button>
                        @endif
                    </div>
                </div>

                {{-- Contact --}}
                <div class="mn-checkout-section">
                    <div class="mn-checkout-section-title">{{ ___('Your details') }}</div>
                    <input type="text" class="mn-input" id="mn-field-name" placeholder="{{ ___('Your name') }}" autocomplete="name">
                    <div id="mn-phone-wrap" style="{{ $allow_on_table ? 'display:none' : '' }}">
                        <input type="tel" class="mn-input" id="mn-field-phone" placeholder="{{ ___('Phone number') }}" autocomplete="tel">
                    </div>
                    <div id="mn-table-wrap" style="{{ !$allow_on_table ? 'display:none' : '' }}">
                        <input type="text" class="mn-input" id="mn-field-table"
                               placeholder="{{ ___('Table number') }}"
                               value="{{ $table ?? '' }}">
                    </div>
                    <div id="mn-address-wrap" style="display:none">
                        <input type="text" class="mn-input" id="mn-field-address" placeholder="{{ ___('Delivery address') }}" autocomplete="street-address">
                    </div>
                    <textarea class="mn-note-textarea" id="mn-field-note" placeholder="{{ ___('Note (optional)') }}"></textarea>
                </div>

                {{-- Payment --}}
                <div class="mn-checkout-section">
                    <div class="mn-checkout-section-title">{{ ___('Payment') }}</div>
                    <div class="mn-pay-group">
                        <label class="mn-pay-label selected">
                            <input type="radio" name="mn-pay" value="pay_on_counter" checked>
                            {{ ___('Pay on arrival') }}
                        </label>
                        @if($allow_payment)
                        <label class="mn-pay-label">
                            <input type="radio" name="mn-pay" value="pay_online">
                            {{ ___('Pay online') }}
                        </label>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mn-checkout-footer">
                <button class="mn-submit-btn" id="mn-submit-btn">{{ ___('Place Order') }}</button>
            </div>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
window.MINIMAL = {
    orderUrl:       '{{ route('store.submitOrder', $store->id) }}',
    items:          @json($jsItems),
    csrf:           '{{ csrf_token() }}',
    curSign:        '{{ $curSign }}',
    curPos:         '{{ $curPos }}',
    currencyDecimals: {{ currency_decimals() }},
    deliveryCharge: {{ $deliveryCharge }},
    taxCharge:      {{ $taxCharge }},
    minOrder:       {{ $minOrderValue }},
    allowOrder:     {{ $allow_order ? 'true' : 'false' }},
    allowTable:     {{ $allow_on_table ? 'true' : 'false' }},
    allowTakeaway:  {{ $allow_takeaway ? 'true' : 'false' }},
    allowDelivery:  {{ $allow_delivery ? 'true' : 'false' }},
    allowPayment:   {{ $allow_payment ? 'true' : 'false' }},
    tableNo:        '{{ $table ?? '' }}',
    lang: {
        remove:         '{{ ___('Remove') }}',
        addToCart:      '{{ ___('Add to cart') }}',
        placeOrder:     '{{ ___('Place Order') }}',
        placing:        '{{ ___('Placing order…') }}',
        orderPlaced:    '{{ ___('Order placed! Thank you.') }}',
        orderFailed:    '{{ ___('Could not place order. Try again.') }}',
        networkError:   '{{ ___('Network error. Please try again.') }}',
        nameRequired:   '{{ ___('Name is required.') }}',
        phoneRequired:  '{{ ___('Phone is required.') }}',
        addressRequired:'{{ ___('Address is required.') }}',
        minOrder:       '{{ ___('Minimum order is') }}',
    },
};
</script>
@endpush
