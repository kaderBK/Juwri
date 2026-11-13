{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the ordering page of a `book` store without bookable services (the kit sections, in the store's order) --}}
@php
    $__ids = \Juwri\Core\Support\ViewHelpers::sectionIds($vm['theme']['sections']);
    $__top = ['announcement', 'header', 'hero'];
    $__inCart = [];
    foreach (($cart['lines'] ?? []) as $__ln) {
        $__inCart[$__ln['item_id']] = ($__inCart[$__ln['item_id']] ?? 0) + $__ln['qty'];
    }
    $__pay = $checkout['payment_methods'] ?? [];
@endphp
@foreach ($__ids as $__id)
@if (in_array($__id, $__top, true) && \Juwri\Core\Support\ViewHelpers::kitView($__id))
@include(\Juwri\Core\Support\ViewHelpers::kitView($__id), ['inCart' => $__inCart, 'pay' => $__pay])
@endif
@endforeach
<main class="c-main" id="main">
@foreach ($__ids as $__id)
@if (! in_array($__id, array_merge($__top, ['footer', 'cart_bar']), true) && \Juwri\Core\Support\ViewHelpers::kitView($__id))
@include(\Juwri\Core\Support\ViewHelpers::kitView($__id), ['inCart' => $__inCart, 'pay' => $__pay])
@endif
@endforeach
@if (in_array('cart_bar', $__ids, true))
@include('store_templates._kit.components.cart-panel')
@endif
</main>
@if (in_array('footer', $__ids, true))
@include('store_templates._kit.sections.footer')
@endif
@if (in_array('cart_bar', $__ids, true))
@include('store_templates._kit.components.cart-bar')
@endif
