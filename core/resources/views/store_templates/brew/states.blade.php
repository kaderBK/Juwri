{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: the cafe states board (ready for pickup, busy, sold out today, closed, empty cart, no results, minimum, zone, payment, offline,
     price changed, loading). Every state says what happened and offers a way out. Reads $vm (the closed-store view model) and
     $newTotal / $cartTotal for the price-changed card. --}}
@extends('store_templates._kit.layout', ['theme' => 'brew', 'bodyClass' => 'p-states'])
@php
    $__mo = $vm['context']['min_order'] ?? null;
    $__st = $vm['store']['status'];
    $__label = (string) ($__st['label'] ?? '');
    $__time = preg_match('/(?:opens|يفتح|تفتح)\s*(.+)$/u', $__label, $__m) === 1 ? trim($__m[1]) : $__label;
    $__sold = \Juwri\Core\Support\ViewHelpers::firstSoldOut($vm['catalog']['items']);
    $__cat = $__sold ? \Juwri\Core\Support\ViewHelpers::categoryWithItem($vm['catalog']['categories'], $__sold['id']) : null;
    $__alt = $__cat ? \Juwri\Core\Support\ViewHelpers::firstAvailable($__cat['item_ids'], $vm['catalog']['items']) : null;
    $__ref = $kit->ltr('PAY-3H8D');
@endphp
@section('content')
@include('store_templates.brew.sections.header')
<main class="c-main c-main--states" id="main"><h2 class="c-states__title">{{ $kit->s('Cafe states') }}</h2><div class="c-states">
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('success', 'bell-ring', $kit->s('Ready for pickup'), $kit->t(':order is ready', ['order' => $kit->ltr('B-27')]), $kit->s("Pick it up at the counter. We'll hold it for :minutes minutes.", ['minutes' => 20]), [[$kit->s("I'm on my way"), 'secondary']]))
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'hourglass', $kit->s('Busy'), $kit->s('Busy right now · about :minutes min', ['minutes' => 20]), $kit->s('Orders are taking longer than usual. You can still order or schedule for later.'), [[$kit->s('Schedule'), 'secondary'], [$kit->s('Order anyway'), 'primary']]))
    @if ($__sold)
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('muted', 'ban', $kit->s('Sold out today'), $kit->s(':name is sold out', ['name' => $__sold['name']]), $__alt ? $kit->s('Back tomorrow. Try :alternative instead?', ['alternative' => $__alt['name']]) : $kit->s('Back tomorrow.'), $__cat ? [[$kit->s('See :category', ['category' => $__cat['name']]), 'primary']] : []))
    @endif
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'moon', $kit->s('Store closed'), $kit->s('Closed now · opens :time', ['time' => $__time]), $kit->s('Order now for pickup when we open.'), [[$kit->s('Schedule for :time', ['time' => $__time]), 'primary']]))
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('muted', 'coffee', $kit->s('Nothing in your order yet'), $kit->s('Nothing in your order yet'), $kit->s('Start with a drink or something from the bakery.'), [[$kit->s('Browse menu'), 'primary']]))
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('muted', 'search', $kit->s('No search results'), $kit->s('No search results'), $kit->s('Check the spelling or try a shorter word.'), [[$kit->s('Clear search'), 'secondary']]))
    @if ($__mo)
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'alert-triangle', $kit->s('Order is below the minimum'), $kit->s('Order is below the minimum'), $kit->s('Delivery needs a minimum of :amount. Pickup has no minimum.', ['amount' => $__mo['formatted']]), [[$kit->s('Add more'), 'primary']]))
    @endif
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('danger', 'map-pin', $kit->s('Out of delivery zone'), $kit->s('Out of delivery zone'), $kit->s('Try another address, or choose pickup.'), [[$kit->s('Change address'), 'secondary'], [$kit->s('Choose pickup'), 'primary']]))
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('danger', 'alert-circle', $kit->s('Payment failed'), $kit->s('Payment failed'), $kit->s("You weren't charged. Your order is saved. Reference: :reference", ['reference' => $__ref]), [[$kit->s('Pay at the counter'), 'secondary'], [$kit->s('Try again'), 'primary']]))
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'wifi-off', $kit->s('Waiting for connection'), $kit->s('Waiting for connection'), $kit->s("Your order for :table is saved on this phone and will be sent when you're back online. Expires :time.", ['table' => ($vm['context']['table']['label'] ?? $kit->s('Table')), 'time' => '10:14']), [[$kit->s('Cancel order'), 'secondary']]))
    @isset($newTotal)
    @include('store_templates.brew.components.state-card', \Juwri\Core\Support\ViewHelpers::stateCard('warning', 'refresh-cw', $kit->s('Prices changed'), $kit->s('Prices changed'), $kit->s('Prices changed while you were offline.'), [[$kit->s('Cancel'), 'secondary'], [$kit->s('Confirm · :amount', ['amount' => $newTotal['formatted']]), 'primary']], ['extraView' => ['view' => 'store_templates._kit.components.old-new', 'data' => ['old' => $cartTotal ?? $newTotal, 'new' => $newTotal]]]))
    @endisset
    <article class="c-state c-state--plain" aria-labelledby="state-loading"><div class="c-state__body"><h2 class="c-state__title" id="state-loading">{{ $kit->s('Loading…') }}</h2>
        <div role="status" aria-label="{{ $kit->s('Loading…') }}"><ul class="c-cards c-cards--grid">
        @foreach ([1, 2] as $__n)<li class="c-gcard c-gcard--skeleton" aria-hidden="true"><div class="c-gcard__media"><span class="c-skeleton c-skeleton--tile"></span></div><div class="c-gcard__body"><span class="c-skeleton c-skeleton--line"></span><span class="c-skeleton c-skeleton--line c-skeleton--short"></span></div></li>@endforeach
        </ul></div></div></article>
</div></main>
@include('store_templates._kit.sections.footer')
@endsection
