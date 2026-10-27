{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- table: the reference kit theme (restaurant). The home page: the sections the store enabled, in the store's order, then the
     dialogs (item sheet, cart, checkout). Reads only $vm. --}}
@extends('store_templates._kit.layout', ['theme' => 'table', 'bodyClass' => ! empty($vm['context']['kiosk']['on']) ? 'p-home p-kiosk' : 'p-home'])
@php
    $__ids = \Juwri\Core\Support\ViewHelpers::sectionIds($vm['theme']['sections']);
    $__top = ['announcement', 'header', 'hero'];
    $__inCart = [];
    foreach (($cart['lines'] ?? []) as $__ln) {
        $__inCart[$__ln['item_id']] = ($__inCart[$__ln['item_id']] ?? 0) + $__ln['qty'];
    }
    $__pay = $checkout['payment_methods'] ?? [];
    $__view = function (string $id): ?string {
        foreach (['store_templates.table.sections.'.$id, 'store_templates._kit.sections.'.$id] as $name) {
            if (view()->exists($name)) {
                return $name;
            }
        }

        return null;
    };
@endphp
@section('content')
@foreach ($__ids as $__id)
@if (in_array($__id, $__top, true) && $__view($__id) && (empty($vm['context']['kiosk']['on']) || $__id === 'header'))
@include($__view($__id), ['inCart' => $__inCart, 'pay' => $__pay])
@endif
@endforeach
@if (! empty($vm['context']['kiosk']['on']))
@include('store_templates._kit.kiosk.main', ['theme' => 'table', 'inCart' => $__inCart])
@else
<main class="c-main" id="main">
@foreach ($__ids as $__id)
@if (! in_array($__id, array_merge($__top, ['footer', 'cart_bar']), true) && $__view($__id))
@include($__view($__id), ['inCart' => $__inCart, 'pay' => $__pay])
@endif
@endforeach
@if (in_array('cart_bar', $__ids, true))
@include('store_templates._kit.components.cart-panel')
@endif
</main>
@endif
@if (in_array('footer', $__ids, true))
@include('store_templates._kit.sections.footer')
@endif
@if (in_array('cart_bar', $__ids, true) && empty($vm['context']['kiosk']['on']))
@include('store_templates._kit.components.cart-bar')
@endif
@endsection
@section('dialogs')
@if (! empty($item))@include('store_templates._kit.components.item-sheet', ['item' => $item, 'open' => $open ?? null])@endif
<div id="item-sheet-host" data-kit-item-host></div>
@include('store_templates._kit.components.cart-sheet', ['cart' => $cart ?? null, 'open' => $open ?? null])
@include('store_templates._kit.components.checkout-sheet', ['cart' => $cart ?? null, 'checkout' => $checkout ?? null, 'open' => $open ?? null])
@if (! empty($vm['context']['kiosk']['on']))
@include('store_templates._kit.kiosk.dialogs')
@endif
@endsection
