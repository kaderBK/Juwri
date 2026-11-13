{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew, desktop: the cart as a side panel with the pickup context row on top (hidden on phones, where the cart bar opens the dialog) --}}
@php
    $__q = $cart ?? null;
    $__prep = $vm['context']['prep_time_min'] ?? null;
    $__sel = $vm['context']['selected_order_type'] ?? '';
@endphp
<aside class="c-cartpanel" data-section="cart_bar" aria-labelledby="cartpanel-title">
    <div class="c-cartpanel__head"><h2 class="c-cartpanel__title" id="cartpanel-title">{{ $kit->s('Your order') }}</h2><span class="c-cartpanel__count">{{ $kit->s('Items') }}: <span data-juwri-count>{{ collect($__q['lines'] ?? [])->sum('qty') }}</span></span></div>
    @if ($__prep && in_array($__sel, ['pickup', 'takeaway'], true))
    <p class="c-ctxrow c-ctxrow--static c-cartpanel__ctx">{{ $kit->icon('store') }}<span class="c-ctxrow__text"><span class="c-ctxrow__val">{{ $kit->s('Pickup from :branch · about :minutes min', ['branch' => $vm['branch']['current']['name'] ?? $vm['store']['name'], 'minutes' => $__prep]) }}</span></span></p>
    @endif
    @include('store_templates._kit.components.cart-body')
    <div data-juwri-filled @unless ($__q) hidden @endunless><button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="button" data-juwri-open="checkout" aria-controls="checkout" aria-expanded="false"><span data-juwri-cta>{{ $__q['cta_label'] ?? $kit->s('Go to checkout') }}</span></button></div>
</aside>
