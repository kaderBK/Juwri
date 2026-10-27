{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §11: the retail states board — size out of stock (Notify me), choose a size, empty bag, empty wishlist, payment failed, waiting for
     payment, payment not completed (15-minute window), no shipping to this postcode, returns window closed. Every state says what happened and
     offers a way out. Offline and server-error states are the shared ones: a shipping order never queues offline. --}}
@extends('store_templates._kit.layout', ['theme' => 'aisle', 'bodyClass' => 'p-states'])
@php
    $__ref = $kit->ltr('PAY-48213');
    $__no = $kit->ltr('DC-10422');
    $__days = (int) ($vm['store']['returns']['days'] ?? 14);
@endphp
@section('content')
@include('store_templates.aisle.sections.announcement')
@include('store_templates.aisle.sections.header', ['h1' => false])
<main class="c-main c-main--states" id="main"><h1 class="c-states__h1">{{ $kit->s('States') }}</h1><div class="c-states">
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'alert-triangle', 'title' => $kit->s('This size is out of stock'), 'body' => $kit->s("We'll email you when it's back. Other sizes are available now."), 'primary' => $kit->s('Notify me'), 'extraView' => ['view' => 'store_templates.aisle.components.state-sizes', 'data' => ['kit' => $kit]]])
    @include('store_templates._kit.components.state-card', ['tone' => 'danger', 'icon' => 'alert-circle', 'title' => $kit->s('Choose a size'), 'body' => $kit->s('Select a size before adding to your bag. Not sure? Check the size guide.'), 'live' => true, 'primary' => $kit->s('Add to bag'), 'extraView' => ['view' => 'store_templates.aisle.components.state-sizes', 'data' => ['kit' => $kit, 'error' => true]]])
    @include('store_templates._kit.components.state-card', ['tone' => 'muted', 'icon' => 'shopping-bag', 'title' => $kit->s('Your bag is empty'), 'body' => $kit->s('Add something you love and it will wait here.'), 'primary' => $kit->s('Shop new in')])
    @include('store_templates._kit.components.state-card', ['tone' => 'muted', 'icon' => 'heart', 'title' => $kit->s('No saved items yet'), 'body' => $kit->s('Tap the heart on any product to save it.'), 'primary' => $kit->s('Shop new in')])
    @include('store_templates._kit.components.state-card', ['tone' => 'danger', 'icon' => 'credit-card', 'title' => $kit->s('Your card was declined'), 'body' => $kit->t("You weren't charged. Try another card. Reference: :reference", ['reference' => $__ref]), 'primary' => $kit->s('Try again'), 'secondary' => $kit->s('Pay another way')])
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'clock', 'title' => $kit->s('Waiting for payment'), 'body' => $kit->t("We've received order :number, but your payment isn't finished. Complete payment by :time or the order is cancelled.", ['number' => $__no, 'time' => '7:42 pm']), 'live' => true, 'primary' => $kit->s('Complete payment')])
    @include('store_templates._kit.components.state-card', ['tone' => 'danger', 'icon' => 'x', 'title' => $kit->s('Payment not completed'), 'body' => $kit->t("Order :number was cancelled because payment wasn't finished within 15 minutes. Nothing was charged and your items are released.", ['number' => $__no]), 'primary' => $kit->s('Try again')])
    @include('store_templates._kit.components.state-card', ['tone' => 'danger', 'icon' => 'map-pin-off', 'title' => $kit->s("We can't ship to this postcode"), 'body' => $kit->s('Try another address or pick up in store.'), 'primary' => $kit->s('Change address'), 'secondary' => $kit->s('Pick up in store')])
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'rotate-ccw', 'title' => $kit->s('Returns window closed'), 'body' => $kit->s('This order is outside the :days-day return window. Contact us for faulty items.', ['days' => (string) $__days]), 'secondary' => $kit->s('Get help')])
</div></main>
@include('store_templates._kit.sections.footer')
@endsection
