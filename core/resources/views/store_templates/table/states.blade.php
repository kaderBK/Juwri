{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- table: the states board (empty cart, no results, closed, minimum, zone, payment, offline, loading). Every state says what
     happened and offers a way out. Reads $vm (the closed-store view model) and $newTotal / $cartTotal for the price-changed card. --}}
@extends('store_templates._kit.layout', ['theme' => 'table', 'bodyClass' => 'p-states'])
@php
    $__mo = $vm['context']['min_order'] ?? null;
    $__st = $vm['store']['status'];
    $__ref = $kit->ltr('PAY-48213');
@endphp
@section('content')
@include('store_templates._kit.sections.header')
<main class="c-main c-main--states" id="main"><div class="c-states">
    @include('store_templates._kit.components.state-card', ['tone' => 'muted', 'icon' => 'shopping-bag', 'title' => $kit->s('Nothing in your order yet'), 'body' => $kit->s('Add a dish from the menu to start an order.'), 'primary' => $kit->s('Browse menu')])
    @include('store_templates._kit.components.state-card', ['tone' => 'muted', 'icon' => 'search', 'title' => $kit->s('No search results'), 'body' => $kit->s('Check the spelling or try a shorter word.'), 'secondary' => $kit->s('Clear search')])
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'clock', 'title' => $kit->s('Store closed'), 'body' => $__st['label'] ?? ($__st['paused_reason'] ?? '')])
    @if ($__mo)
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'alert-triangle', 'title' => $kit->s('Order is below the minimum'), 'body' => $kit->t('Delivery needs a minimum of :amount. Pickup has no minimum.', ['amount' => $kit->money($__mo)]), 'primary' => $kit->s('Add more')])
    @endif
    @include('store_templates._kit.components.state-card', ['tone' => 'danger', 'icon' => 'map-pin', 'title' => $kit->s('Out of delivery zone'), 'body' => $kit->s('Try another address, or choose pickup.'), 'primary' => $kit->s('Choose pickup'), 'secondary' => $kit->s('Change address')])
    @include('store_templates._kit.components.state-card', ['tone' => 'danger', 'icon' => 'credit-card', 'title' => $kit->s('Payment failed'), 'body' => $kit->t('The bank declined the payment. Your cart is saved. Reference: :reference', ['reference' => $__ref]), 'primary' => $kit->s('Try again'), 'secondary' => $kit->s('Pay another way')])
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'wifi-off', 'title' => $kit->s('Waiting for connection'), 'body' => $kit->s("Your order for :table is saved on this phone and will be sent when you're back online. Expires :time.", ['table' => ($vm['context']['table']['label'] ?? $kit->s('Table')), 'time' => '10:30']), 'secondary' => $kit->s('Cancel order')])
    @isset($newTotal)
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'refresh-cw', 'title' => $kit->s('Prices changed'), 'body' => $kit->s('Prices changed while you were offline.'), 'primary' => $kit->s('Confirm · :amount', ['amount' => $newTotal['formatted']]), 'secondary' => $kit->s('Cancel'), 'extraView' => ['view' => 'store_templates._kit.components.old-new', 'data' => ['old' => $cartTotal ?? $newTotal, 'new' => $newTotal]]])
    @endisset
    <article class="c-state c-state--plain" aria-labelledby="state-loading"><div class="c-state__body"><h2 class="c-state__title" id="state-loading">{{ $kit->s('Loading…') }}</h2>
        <div role="status" aria-label="{{ $kit->s('Loading…') }}"><ul class="c-cards c-cards--list">
        @foreach ([1, 2] as $__n)<li class="c-card c-card--list c-card--skeleton" aria-hidden="true"><div class="c-card__body"><span class="c-skeleton c-skeleton--line"></span><span class="c-skeleton c-skeleton--line c-skeleton--short"></span><span class="c-skeleton c-skeleton--line c-skeleton--price"></span></div><div class="c-card__media"><span class="c-skeleton c-skeleton--img"></span></div></li>@endforeach
        </ul></div></div></article>
</div></main>
@include('store_templates._kit.sections.footer')
@endsection
