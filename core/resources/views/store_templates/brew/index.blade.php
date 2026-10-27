{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: the cafe kit theme (a small delta on `table`: compact header, pickup hero + order type as one block, an "Order again"
     rail, grid cards, a segmented/chip item sheet, an inverted cart bar). The home page: the sections the store enabled, in the
     store's order, then the dialogs (item sheet, cart, checkout). Reads only $vm. --}}
@extends('store_templates._kit.layout', ['theme' => 'brew', 'bodyClass' => ! empty($vm['context']['kiosk']['on']) ? 'p-home p-kiosk' : 'p-home'])
@php
    $__ids = \Juwri\Core\Support\ViewHelpers::sectionIds($vm['theme']['sections']);
    $__top = ['announcement', 'header'];
    $__merged = in_array('hero', $__ids, true) && in_array('order_type', $__ids, true);
    $__inCart = [];
    foreach (($cart['lines'] ?? []) as $__ln) {
        $__inCart[$__ln['item_id']] = ($__inCart[$__ln['item_id']] ?? 0) + $__ln['qty'];
    }
    $__pay = $checkout['payment_methods'] ?? [];
    $__placed = false;
@endphp
@section('content')
@foreach ($__ids as $__id)
@if (in_array($__id, $__top, true) && \Juwri\Core\Support\ViewHelpers::themeView('brew', $__id) && (empty($vm['context']['kiosk']['on']) || $__id === 'header'))
@include(\Juwri\Core\Support\ViewHelpers::themeView('brew', $__id), ['inCart' => $__inCart, 'pay' => $__pay])
@endif
@endforeach
@if (! empty($vm['context']['kiosk']['on']))
@include('store_templates._kit.kiosk.main', ['theme' => 'brew', 'inCart' => $__inCart])
@else
<main class="c-main" id="main">
@foreach ($__ids as $__id)
@if ($__merged && in_array($__id, ['hero', 'order_type'], true))
@unless ($__placed)
@php($__placed = true)
@include('store_templates.brew.sections.pickup')
@endunless
@elseif (! in_array($__id, array_merge($__top, ['footer', 'cart_bar']), true) && \Juwri\Core\Support\ViewHelpers::themeView('brew', $__id))
@include(\Juwri\Core\Support\ViewHelpers::themeView('brew', $__id), ['inCart' => $__inCart, 'pay' => $__pay])
@endif
@endforeach
@if (in_array('cart_bar', $__ids, true))
@include('store_templates.brew.components.cart-panel')
@endif
</main>
@endif
@if (in_array('footer', $__ids, true))
@include('store_templates._kit.sections.footer')
@endif
@if (in_array('cart_bar', $__ids, true) && empty($vm['context']['kiosk']['on']))
@include('store_templates.brew.components.cart-bar')
@endif
@endsection
@section('dialogs')
@if (! empty($item))@include('store_templates.brew.components.item-sheet', ['item' => $item, 'open' => $open ?? null])@endif
<div id="item-sheet-host" data-kit-item-host></div>
@include('store_templates._kit.components.cart-sheet', ['cart' => $cart ?? null, 'open' => $open ?? null])
@include('store_templates._kit.components.checkout-sheet', ['cart' => $cart ?? null, 'checkout' => $checkout ?? null, 'open' => $open ?? null])
@if (! empty($vm['context']['kiosk']['on']))
@include('store_templates._kit.kiosk.dialogs')
@endif
@endsection
