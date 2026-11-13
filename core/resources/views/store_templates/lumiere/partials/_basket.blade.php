{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Basket — lines render from the same client-side cart preview shelf's own
     drawer uses (localStorage, keyed per store), kept in sync with the real
     server-side cart via each item's addRoute / the cart-line route template.
     Skeleton only; lm-boot.js owns all of it (renderBasket()). --}}
<section class="lm-screen has-tabbar" data-screen="basket" hidden>
    <div class="lm-hdr">
        <button type="button" class="lm-iconbtn" data-back aria-label="{{ ___('Back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
        </button>
        <span class="lm-title">{{ ___('My Selections') }}</span>
        <span style="width:42px"></span>
    </div>

    <div id="lmBasketEmpty" class="lm-empty-wrap" hidden>
        <span class="lm-eico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
        </span>
        <h3>{{ ___('Your basket is empty') }}</h3>
        <p>{{ ___('Add a product you love to get started.') }}</p>
        <button type="button" class="lm-btn lm-btn-dark lm-btn-sm" data-nav="home">{{ ___('Start shopping') }}</button>
    </div>

    <div id="lmBasketView">
        <div id="lmBasketLines"></div>
        <div class="lm-summary" id="lmBasketSummary" hidden>
            <h3>{{ ___('My Selections') }}</h3>
            <div class="lm-srow"><span>{{ ___('Subtotal') }}</span><span id="lmBasketSubtotal"></span></div>
            <div class="lm-srow" id="lmBasketTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="lmBasketTax"></span></div>
            <div class="lm-srow"><span>{{ ___('Delivery') }}</span><span class="lm-freeval">{{ ___('Free') }}</span></div>
            <div class="lm-srow"><span>{{ ___('Discount') }}</span><span id="lmBasketDiscount"></span></div>
            <div class="lm-srow lm-total"><span>{{ ___('Total') }}</span><span id="lmBasketTotal"></span></div>
            <button type="button" class="lm-btn lm-btn-dark lm-btn-block" style="margin-top:16px" data-nav="checkout">
                <span id="lmBasketCheckoutLabel">{{ ___('Checkout') }}</span>
            </button>
        </div>
    </div>
</section>
