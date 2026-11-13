{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    use Illuminate\Support\Str;

    $items = collect($total_menus)->values();
    $byCat = $items->groupBy('category_id');
    $categoryIds = $byCat->keys()->filter()->all();
    $categories = $store->menu_categories->whereIn('id', $categoryIds)->values();

    $variantLabel = \Juwri\Core\Support\ViewHelpers::variantLabelNamed(...);

    $jsItems = [];
    foreach ($items as $m) {
        $itemAllergies = empty($m->allergies)
            ? collect()
            : $allergies->whereIn('id', explode(',', $m->allergies));

        $variants = [];
        if (isset($m->variants)) {
            foreach ($m->variants as $v) {
                $variants[] = [
                    'id' => $v->id,
                    'label' => $variantLabel($m, $v),
                    'price' => (float) $v->price,
                ];
            }
        }
        $extras = [];
        if (isset($m->extras)) {
            foreach ($m->extras as $e) {
                $extras[] = [
                    'id' => $e->id,
                    'title' => $e->title,
                    'price' => (float) $e->price,
                ];
            }
        }

        $isSoldOut = (bool) $m->is_sold_out
            || ((bool) ($m->track_inventory ?? false) && (int) ($m->stock_qty ?? 1) <= 0);

        $itemImg = $m->image && $m->image !== 'default.png'
            ? media_url('storage/menu/', $m->image, $store->id ?? null)
            : null;
        $jsItems[$m->id] = [
            'id' => $m->id,
            'name' => $m->name,
            'desc' => strip_tags((string) $m->description),
            'img' => $itemImg,
            'is_fallback' => $itemImg === null,
            'price' => (float) $m->price,
            'type' => $m->type === 'nonveg' ? 'nonveg' : 'veg',
            'category_id' => $m->category_id,
            'rating' => $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : null,
            'rating_count' => (int) ($m->rating_count ?? 0),
            'allergies' => \Juwri\Core\Support\ViewHelpers::allergyListPlain($itemAllergies),
            'variants' => $variants,
            'extras' => $extras,
            'is_vegan' => (bool) $m->is_vegan,
            'is_halal' => (bool) $m->is_halal,
            'is_gluten_free' => (bool) $m->is_gluten_free,
            'is_popular' => (bool) $m->is_popular,
            'spice_level' => (int) $m->spice_level,
            'is_sold_out' => $isSoldOut,
            'calories' => $m->calories ?? null,
            'portion_size' => $m->portion_size ?? null,
        ];
    }

    $categoryEmojis = ['🍔','🍕','🍟','🥗','🍣','🍩','🌮','🍜','🥪','🍦','🥘','🍱'];
    $jsCategories = [];
    foreach ($categories as $i => $c) {
        $catImage = $byCat[$c->id]->first(fn($m) => $m->image && $m->image !== 'default.png');
        $jsCategories[] = [
            'id' => $c->id,
            'name' => $c->name,
            'img' => $catImage ? media_url('storage/menu/', $catImage->image, $store->id ?? null) : null,
            'emoji' => $categoryEmojis[$i % count($categoryEmojis)],
        ];
    }

    $slideImages = [];
    if ($store->cover_image && $store->cover_image !== 'default.png') {
        $slideImages[] = media_url('storage/store/cover/', $store->cover_image, $store->id ?? null);
    }
    foreach ($items->take(2) as $m) {
        if ($m->image && $m->image !== 'default.png') {
            $slideImages[] = media_url('storage/menu/', $m->image, $store->id ?? null);
        }
    }
    while (count($slideImages) < 3) {
        $slideImages[] = null;
    }
    $slideImages = array_slice($slideImages, 0, 3);

    $slideLabels = [___("Today's deal"), ___('Combo special'), ___('First timer')];
    $slideTitles = [___('25% off all burgers'), ___('Free fries + drink'), usd_ltr_inline(___('$12 off your order'))];
    $slideCtAs = [___('Claim now'), ___('Order combo'), ___('Use code NEW')];

    $curSign = $postOptions->currency_sign ?? '$';
    $curPos = (string) ($postOptions->currency_pos ?? '1');
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="pandabite" id="pandabite" dir="{{ current_language()->direction }}">
    @include('store_templates.pandabite.includes.header')
    @include('store_templates.pandabite.includes.hero-slider')
    @include('store_templates.pandabite.includes.category-grid')
    @include('store_templates.pandabite.includes.product-grid')
    @include('store_templates.pandabite.includes.bottom-nav')
    @include('store_templates.pandabite.includes.cart-sheet')
    @include('store_templates.pandabite.includes.item-sheet')
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
window.PB = {
    csrf: '{{ csrf_token() }}',
    orderUrl: '{{ route('store.submitOrder', $store->id) }}',
    curSign: '{{ $curSign }}',
    curPos: '{{ $curPos }}',
    currencyDecimals: {{ currency_decimals() }},
    table: '{{ $table }}',
    allow: {
        order: {{ $allow_order ? 'true' : 'false' }},
        on_table: {{ $allow_on_table ? 'true' : 'false' }},
        takeaway: {{ $allow_takeaway ? 'true' : 'false' }},
        delivery: {{ $allow_delivery ? 'true' : 'false' }},
        payment: {{ $allow_payment ? 'true' : 'false' }}
    },
    minOrder: {{ (float) ($postOptions->restaurant_minimum_order ?? 0) }},
    deliveryCharge: {{ (float) ($postOptions->restaurant_delivery_charge ?? 0) }},
    items: @json($jsItems),
    categories: @json($jsCategories),
    slides: @json($slideImages),
    strings: {
        add: '{{ ___('Add') }}',
        soldOut: '{{ ___('Sold out') }}',
        cart: '{{ ___('Cart') }}',
        checkout: '{{ ___('Checkout') }}',
        name: '{{ ___('Your name') }}',
        phone: '{{ ___('Phone number') }}',
        address: '{{ ___('Delivery address') }}',
        table: '{{ ___('Table number') }}',
        note: '{{ ___('Order note') }}',
        coupon: '{{ ___('Coupon code') }}',
        minOrderError: '{{ ___('Minimum order is') }}',
        orderSuccess: '{{ ___('Order placed successfully') }}'
    }
};
</script>
@endpush
