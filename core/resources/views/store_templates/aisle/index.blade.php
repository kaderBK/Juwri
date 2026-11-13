{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle: the retail kit theme. The home page: the sections the store enabled, in the store's order (promo strip, header, hero, the
     "New in" rail, the filterable catalogue, info, footer), then the dialogs (bag, filters sheet, checkout). Reads only $vm; the listing
     ($listing) is the page's own query echoed. A kiosk session draws the kiosk screen instead. --}}
@extends('store_templates._kit.layout', ['theme' => 'aisle', 'bodyClass' => ! empty($vm['context']['kiosk']['on']) ? 'p-home p-kiosk' : 'p-home'])
@php
    $__ids = \Juwri\Core\Support\ViewHelpers::sectionIds($vm['theme']['sections']);
    $__top = ['announcement', 'header'];
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__pay = $checkout['payment_methods'] ?? [];
@endphp
@section('content')
@foreach ($__ids as $__id)
@if (in_array($__id, $__top, true) && \Juwri\Core\Support\ViewHelpers::themeView('aisle', $__id) && (! $__kiosk || $__id === 'header'))
@include(\Juwri\Core\Support\ViewHelpers::themeView('aisle', $__id), ['pay' => $__pay])
@endif
@endforeach
@if ($__kiosk)
@include('store_templates._kit.kiosk.main', ['theme' => 'aisle', 'inCart' => []])
@else
<main class="c-main" id="main">
@foreach ($__ids as $__id)
@if (! in_array($__id, array_merge($__top, ['footer', 'cart_bar']), true) && \Juwri\Core\Support\ViewHelpers::themeView('aisle', $__id))
@include(\Juwri\Core\Support\ViewHelpers::themeView('aisle', $__id), ['pay' => $__pay])
@endif
@endforeach
</main>
@endif
@if (in_array('footer', $__ids, true))
@include('store_templates._kit.sections.footer')
@endif
@if (in_array('cart_bar', $__ids, true) && ! $__kiosk)
@include('store_templates.aisle.components.cart-bar')
@endif
@endsection
@section('dialogs')
<div id="item-sheet-host" data-kit-item-host></div>
@include('store_templates.aisle.components.bag-sheet', ['cart' => $cart ?? null, 'open' => $open ?? null])
@include('store_templates.aisle.components.filters-sheet')
@include('store_templates.aisle.components.checkout-sheet', ['cart' => $cart ?? null, 'checkout' => $checkout ?? null, 'open' => $open ?? null])
@if ($__kiosk)
@include('store_templates._kit.kiosk.dialogs')
@endif
@endsection
