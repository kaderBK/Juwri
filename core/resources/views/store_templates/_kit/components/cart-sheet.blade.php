{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- the cart as a dialog (core JS owns focus, Esc, inert page, return focus) --}}
@php($__q = $cart ?? null)
<div id="cart" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="cart-title" @unless (($open ?? null) === 'cart') hidden @endunless>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-sheet c-sheet--cart">
        <div class="c-sheet__bar"><h2 class="c-sheet__title" id="cart-title" tabindex="-1">{{ $kit->s('Your order') }}</h2><button class="c-iconbtn c-iconbtn--sm" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <div class="c-sheet__scroll">
            @include('store_templates._kit.components.cart-body')
            <div data-juwri-filled @unless ($__q) hidden @endunless>
                <form class="c-coupon" data-kit-coupon>
                    <label class="u-sr" for="coupon">{{ $kit->s('Promo Code') }}</label>
                    <input class="c-input" id="coupon" name="code" type="text" placeholder="{{ $kit->s('Promo Code') }}" autocomplete="off" data-juwri-code>
                    <button class="c-btn c-btn--secondary" type="button" data-juwri-apply-code>{{ $kit->s('Apply') }}</button>
                </form>
                <p class="c-field__help" data-juwri-coupon-message role="status"></p>
            </div>
            <div class="c-queue" data-juwri-queue>
                <template data-juwri-queue-item><div class="c-state c-state--warning"><div class="c-state__body"><h3 class="c-state__title" data-juwri-queue-state></h3><p class="c-state__text"><span data-juwri-queue-total></span> <span data-juwri-queue-detail></span></p><div class="c-state__actions"><button class="c-btn c-btn--primary" type="button" data-juwri-queue-confirm hidden>{{ $kit->s('Confirm') }}</button><button class="c-btn c-btn--secondary" type="button" data-juwri-queue-retry hidden>{{ $kit->s('Try again') }}</button><button class="c-btn c-btn--ghost" type="button" data-juwri-queue-dismiss>{{ $kit->s('Cancel') }}</button></div></div></div></template>
            </div>
        </div>
        <div class="c-sheet__foot" data-juwri-filled @unless ($__q) hidden @endunless>
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="button" data-juwri-open="checkout" aria-controls="checkout" aria-expanded="false"><span data-juwri-cta>{{ $__q['cta_label'] ?? $kit->s('Go to checkout') }}</span></button>
        </div>
    </div>
</div>
