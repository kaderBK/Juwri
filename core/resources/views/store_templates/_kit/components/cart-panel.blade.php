{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- desktop: the cart as a sticky side panel (hidden on phones, where the cart bar opens the cart dialog) --}}
@php($__q = $cart ?? null)
<aside class="c-cartpanel" data-section="cart_bar" aria-labelledby="cartpanel-title">
    <div class="c-cartpanel__head"><h2 class="c-cartpanel__title" id="cartpanel-title">{{ $kit->s('Your order') }}</h2><span class="c-cartpanel__count">{{ $kit->s('Items') }}: <span data-juwri-count>{{ collect($__q['lines'] ?? [])->sum('qty') }}</span></span></div>
    @include('store_templates._kit.components.cart-body')
    <div data-juwri-filled @unless ($__q) hidden @endunless><button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="button" data-juwri-open="checkout" aria-controls="checkout" aria-expanded="false"><span data-juwri-cta>{{ $__q['cta_label'] ?? $kit->s('Go to checkout') }}</span></button></div>
</aside>
