{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-06 the always-visible order panel of the landscape kiosk: lines (steppers 52 px), the server quote, and "Review order · total", which opens
     the checkout dialog (optional name, payment, summary, Place order). --}}
@php($__q = $cart ?? null)
<aside class="c-cartpanel c-kiosk__panel" data-section="cart_bar" aria-labelledby="kp-title">
    <div class="c-cartpanel__head"><h2 class="c-cartpanel__title" id="kp-title">{{ $kit->s('Your order') }}</h2><span class="c-cartpanel__count">{{ $kit->s('Items') }}: <span data-juwri-count>{{ collect($__q['lines'] ?? [])->sum('qty') }}</span></span></div>
    @include('store_templates._kit.components.cart-body')
    <div data-juwri-filled @unless ($__q) hidden @endunless><button class="c-btn c-btn--primary c-btn--xl c-btn--block" type="button" data-juwri-open="checkout" aria-controls="checkout" aria-expanded="false"><span>{{ $kit->s('Review order') }}</span><span aria-hidden="true">·</span><span data-juwri-total>{{ $__q['total']['formatted'] ?? '' }}</span></button></div>
</aside>
