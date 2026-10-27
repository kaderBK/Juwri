{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: the basket as a dialog (core JS owns focus, Esc, inert page, return focus), with the promo code and the way to choose a time. --}}
<div id="cart" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="cart-title" @unless (($open ?? null) === 'cart') hidden @endunless>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-sheet c-sheet--cart">
        <div class="c-sheet__bar"><h2 class="c-sheet__title" id="cart-title" tabindex="-1">{{ $kit->s('Your basket') }}</h2><button class="c-iconbtn c-iconbtn--sm" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <div class="c-sheet__scroll">
            @include('store_templates.basket.components.cart-body')
            <div data-juwri-filled hidden>
                <form class="c-coupon" data-kit-coupon>
                    <label class="u-sr" for="coupon">{{ $kit->s('Promo Code') }}</label>
                    <input class="c-input" id="coupon" name="code" type="text" placeholder="{{ $kit->s('Promo Code') }}" autocomplete="off" data-juwri-code>
                    <button class="c-btn c-btn--secondary" type="button" data-juwri-apply-code>{{ $kit->s('Apply') }}</button>
                </form>
                <p class="c-field__help" data-juwri-coupon-message role="status"></p>
            </div>
        </div>
        <div class="c-sheet__foot" data-juwri-filled hidden>
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block c-bar__cta" type="button" data-juwri-open="checkout" aria-controls="checkout" aria-haspopup="dialog" aria-expanded="false"><span>{{ $kit->s('Choose delivery time') }}</span><bdi class="c-money" data-juwri-total></bdi></button>
        </div>
    </div>
</div>
