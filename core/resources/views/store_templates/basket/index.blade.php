{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: the grocery kit theme. The home page: the sections the store enabled, in the store's order (header, order type, free
     delivery, search, category tiles, deals, catalogue, store info, footer), the basket panel on desktop and the bar on a phone, then
     the dialogs (product sheet, basket, checkout). A kiosk session draws the kiosk screen instead. Reads only $vm. --}}
@extends('store_templates._kit.layout', ['theme' => 'basket', 'bodyClass' => ! empty($vm['context']['kiosk']['on']) ? 'p-home p-kiosk' : 'p-home'])
@php
    $__ids = \Juwri\Core\Support\ViewHelpers::sectionIds($vm['theme']['sections']);
    $__top = ['announcement', 'header'];
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__pay = $checkout['payment_methods'] ?? [];
@endphp
@section('content')
@foreach ($__ids as $__id)
@if (in_array($__id, $__top, true) && \Juwri\Core\Support\ViewHelpers::themeView('basket', $__id) && (! $__kiosk || $__id === 'header'))
@include(\Juwri\Core\Support\ViewHelpers::themeView('basket', $__id), ['pay' => $__pay])
@endif
@endforeach
@if ($__kiosk)
@include('store_templates._kit.kiosk.main', ['theme' => 'basket', 'inCart' => []])
@else
<main class="c-main" id="main">
@foreach ($__ids as $__id)
@if (! in_array($__id, array_merge($__top, ['footer', 'cart_bar']), true) && \Juwri\Core\Support\ViewHelpers::themeView('basket', $__id))
@include(\Juwri\Core\Support\ViewHelpers::themeView('basket', $__id), ['pay' => $__pay])
@endif
@endforeach
@if (in_array('cart_bar', $__ids, true))
@include('store_templates.basket.components.cart-panel')
@endif
</main>
@endif
@if (in_array('footer', $__ids, true))
@include('store_templates._kit.sections.footer')
@endif
@if (in_array('cart_bar', $__ids, true) && ! $__kiosk)
@include('store_templates.basket.components.cart-bar')
@endif
@endsection
@section('dialogs')
@if (! empty($item))@include('store_templates.basket.components.item-sheet', ['item' => $item, 'open' => $open ?? null])@endif
<div id="item-sheet-host" data-kit-item-host></div>
@include('store_templates.basket.components.cart-sheet', ['open' => $open ?? null])
@include('store_templates.basket.components.checkout-sheet', ['checkout' => $checkout ?? null, 'open' => $open ?? null])
@if ($__kiosk)
@include('store_templates._kit.kiosk.dialogs')
@endif
@endsection
