{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §12: the wishlist page — the saved products as a grid with "Move to bag" (a product that needs a size opens its page) and
     Remove. A guest's list is kept in this browser (a cookie token) and joins the account at sign-in. Reads only $vm. --}}
@extends('store_templates._kit.layout', ['theme' => 'aisle', 'bodyClass' => 'p-wishlist'])
@php
    $__ids = array_keys($vm['catalog']['items']);
    $__variants = \Juwri\Core\Support\ViewHelpers::wishlistVariants($wishlist ?? []);
@endphp
@section('content')
@include('store_templates.aisle.sections.announcement')
@include('store_templates.aisle.sections.header', ['h1' => false])
<main class="c-main c-main--wish" id="main">
    <div class="c-wish__head"><h1 class="c-wish__title">{{ $kit->s('Wishlist') }}</h1><p class="c-toolbar__count" role="status" data-kit-count>{{ $kit->n('product', count($__ids)) }}</p></div>
    @if ($__ids === [])
    <article class="c-state c-state--muted" data-kit-wish-empty role="status"><span class="c-state__icon">{{ $kit->icon('heart') }}</span><div class="c-state__body"><h2 class="c-state__title">{{ $kit->s('Your wishlist is empty') }}</h2><p class="c-state__text">{{ $kit->s('Tap the heart on a product to save it here.') }}</p><div class="c-state__actions"><a class="c-btn c-btn--primary" href="{{ $vm['endpoints']['home'] }}">{{ $kit->s('Shop new in') }}</a></div></div></article>
    @else
    <ul class="c-pgrid c-pgrid--wish" aria-label="{{ $kit->s('Wishlist items') }}" data-kit-wishlist>
    @foreach ($__ids as $__id)
        @php($__card = $vm['catalog']['items'][$__id])
        @include('store_templates.aisle.components.product-card', ['item' => $__card, 'first' => false, 'wishVariants' => $__variants, 'wishItem' => true, 'headingLevel' => 2])
    @endforeach
    </ul>
    @endif
</main>
@include('store_templates._kit.sections.footer')
@include('store_templates.aisle.components.cart-bar')
@endsection
@section('dialogs')
@include('store_templates.aisle.components.bag-sheet', ['cart' => $cart ?? null, 'open' => $open ?? null])
@include('store_templates.aisle.components.checkout-sheet', ['cart' => $cart ?? null, 'checkout' => $checkout ?? null, 'open' => $open ?? null])
@endsection
