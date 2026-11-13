{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: the grocery states board — empty basket, no search results, below the minimum order, slots full today, out of the
     delivery zone, card hold failed (the card was not charged), waiting for payment, payment not completed, price changed, offline.
     Every state says what happened and offers a way out. Reads $vm (the closed-store view model); $newTotal / $cartTotal feed the
     price-changed card. --}}
@extends('store_templates._kit.layout', ['theme' => 'basket', 'bodyClass' => 'p-states'])
@php
    $__mo = $vm['context']['min_order'] ?? null;
    $__ref = $kit->ltr('PAY-8K2Q');
    $__order = $kit->ltr('FM-50318');
@endphp
@section('content')
@include('store_templates.basket.sections.header')
<main class="c-main c-main--states" id="main"><h1 class="c-states__title">{{ $kit->s('Grocery states') }}</h1><div class="c-states">
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('muted', 'shopping-basket', $kit->s('Empty basket'), $kit->s('Your basket is empty'), $kit->s('Search, or start from a category.'), [[$kit->s('Shop categories'), 'primary']]))
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('muted', 'search', $kit->s('No search results'), $kit->s('No products found'), $kit->s('Try a shorter word or another spelling.'), [[$kit->s('Clear search'), 'secondary']]))
    @if ($__mo)
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'alert-triangle', $kit->s('Below minimum order'), $kit->s('Order is below the minimum'), $kit->s('Delivery needs a minimum of :amount.', ['amount' => $__mo['formatted']]), [[$kit->s('Keep shopping'), 'primary']]))
    @endif
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'calendar-x', $kit->s('Slots full today'), $kit->s('Today is fully booked'), $kit->s('Pick another day, or choose express delivery.'), [[$kit->s('See all slots'), 'secondary'], [$kit->s('Choose another day'), 'primary']]))
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('danger', 'map-pin-off', $kit->s('Out of delivery zone'), $kit->s("We don't deliver to this address yet"), $kit->s('Try another address, or choose pickup.'), [[$kit->s('Change address'), 'primary']]))
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('danger', 'credit-card', $kit->s('Card hold failed'), $kit->s("We couldn't hold the amount on your card"), $kit->s("You weren't charged. Your basket and time are saved. Reference: :reference", ['reference' => $__ref]), [[$kit->s('Pay another way'), 'secondary'], [$kit->s('Try again'), 'primary']]))
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'hourglass', $kit->s('Waiting for payment'), $kit->s('Waiting for payment'), $kit->s("We've received order :order, but your payment isn't finished. Complete payment or the order is cancelled.", ['order' => $__order]), [[$kit->s('Complete payment'), 'primary']], ['live' => true]))
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('danger', 'alert-circle', $kit->s('Payment not completed'), $kit->s('Payment not completed'), $kit->s("Order :order was cancelled because payment wasn't finished in time. Nothing was charged and your items are released.", ['order' => $__order]), [[$kit->s('Try again'), 'primary']]))
    @isset($newTotal)
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'refresh-cw', $kit->s('Prices changed'), $kit->s('Prices changed'), $kit->s('Prices changed while you were offline.'), [[$kit->s('Cancel'), 'secondary'], [$kit->s('Confirm · :amount', ['amount' => $newTotal['formatted']]), 'primary']], ['extraView' => ['view' => 'store_templates._kit.components.old-new', 'data' => ['old' => $cartTotal ?? $newTotal, 'new' => $newTotal]]]))
    @endisset
    @include('store_templates.basket.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'wifi-off', $kit->s('Offline'), $kit->s("You're offline"), $kit->s('You can keep browsing and editing your basket. Connect to place the order.')))
</div></main>
@include('store_templates._kit.sections.footer')
@endsection
