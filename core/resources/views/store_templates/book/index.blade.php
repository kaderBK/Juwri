{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the store page. A store that sells through the booking engine gets the service list and the booking panel; a laundry gets the
     service list with a quantity (pieces) or weight (kg) on each row; any other
     `book` store (an old services theme switched on, no bookable services yet) gets the kit's ordering page, so it never breaks. --}}
@extends('store_templates._kit.layout', ['theme' => 'book', 'bodyClass' => 'p-home'])
@section('content')
@if (! empty($bookingMode))
@include('store_templates.book.home')
@elseif (! empty($laundryMode))
@include('store_templates.book.laundry-home')
@else
@include('store_templates.book.orders-home')
@endif
@endsection
@section('dialogs')
@if (! empty($laundryMode))
{{-- a laundry has no item sheet: the quantity is on the row --}}
@elseif (! empty($bookingMode))
@if (! empty($item))@include('store_templates.book.components.item-sheet', ['item' => $item, 'open' => $open ?? null])@endif
<div id="item-sheet-host" data-book-sheet-host></div>
@else
@if (! empty($item))@include('store_templates._kit.components.item-sheet', ['item' => $item, 'open' => $open ?? null])@endif
<div id="item-sheet-host" data-kit-item-host></div>
@include('store_templates._kit.components.cart-sheet', ['cart' => $cart ?? null, 'open' => $open ?? null])
@include('store_templates._kit.components.checkout-sheet', ['cart' => $cart ?? null, 'checkout' => $checkout ?? null, 'open' => $open ?? null])
@endif
@endsection
