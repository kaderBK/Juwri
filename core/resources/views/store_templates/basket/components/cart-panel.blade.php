{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket, desktop: the basket as a side panel (hidden on phones, where the bar opens the sheet). Kiosk: the order panel is the
     shared kiosk one. --}}
<aside class="c-cartpanel" data-section="cart_bar" aria-labelledby="cartpanel-title">
    <div class="c-cartpanel__head"><h2 class="c-cartpanel__title" id="cartpanel-title">{{ $kit->s('Your basket') }}</h2><span class="c-cartpanel__count">{{ $kit->s('Items') }}: <bdi data-juwri-count>0</bdi></span></div>
    @include('store_templates.basket.components.cart-body')
    <div data-juwri-filled hidden><button class="c-btn c-btn--primary c-btn--lg c-btn--block c-bar__cta" type="button" data-juwri-open="checkout" aria-controls="checkout" aria-haspopup="dialog" aria-expanded="false"><span>{{ $kit->s('Choose delivery time') }}</span><bdi class="c-money" data-juwri-total></bdi></button></div>
</aside>
