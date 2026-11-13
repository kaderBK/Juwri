{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<div class="pb-sheet pb-cart-sheet" id="pb-cart-sheet" aria-hidden="true" role="dialog" aria-modal="true" aria-label="{{ ___('Your cart') }}">
    <div class="pb-sheet__scrim" data-close-sheet></div>
    <div class="pb-sheet__panel">
        <div class="pb-sheet__header">
            <h3>{{ ___('Your cart') }}</h3>
            <button type="button" class="pb-sheet__close" data-close-sheet aria-label="{{ ___('Close') }}">✕</button>
        </div>
        <div class="pb-cart-sheet__items" id="pb-cart-items">
            <p class="pb-cart-sheet__empty">{{ ___('Your cart is empty.') }}</p>
        </div>
        <div class="pb-cart-sheet__footer">
            <div class="pb-cart-sheet__total">
                <span>{{ ___('Total') }}</span>
                <span id="pb-cart-total">$0.00</span>
            </div>
            <button type="button" class="pb-btn pb-btn--primary" id="pb-cart-checkout">{{ ___('Checkout') }}</button>
        </div>
    </div>
</div>

<div class="pb-sheet pb-order-sheet" id="pb-order-sheet" aria-hidden="true" role="dialog" aria-modal="true" aria-label="{{ ___('Checkout') }}">
    <div class="pb-sheet__scrim" data-close-sheet></div>
    <div class="pb-sheet__panel">
        <div class="pb-sheet__header">
            <h3>{{ ___('Checkout') }}</h3>
            <button type="button" class="pb-sheet__close" data-close-sheet aria-label="{{ ___('Close') }}">✕</button>
        </div>
        <form class="pb-order-form" id="pb-order-form">
            <label class="pb-field">
                <span>{{ ___('Your name') }}</span>
                <input type="text" name="name" required placeholder="{{ ___('Your name') }}">
            </label>

            @if($allow_on_table || $allow_takeaway || $allow_delivery)
            <label class="pb-field">
                <span>{{ ___('Order type') }}</span>
                <select name="ordering-type" id="pb-order-type">
                    @if($allow_on_table)<option value="on-table">{{ ___('Dine-in') }}</option>@endif
                    @if($allow_takeaway)<option value="takeaway">{{ ___('Takeaway') }}</option>@endif
                    @if($allow_delivery)<option value="delivery">{{ ___('Delivery') }}</option>@endif
                </select>
            </label>
            @endif

            <label class="pb-field" id="pb-field-table" style="display:none;">
                <span>{{ ___('Table number') }}</span>
                <input type="text" name="table" value="{{ $table }}">
            </label>

            <label class="pb-field" id="pb-field-phone">
                <span>{{ ___('Phone number') }}</span>
                <input type="tel" name="phone-number">
            </label>

            <label class="pb-field" id="pb-field-address" style="display:none;">
                <span>{{ ___('Delivery address') }}</span>
                <textarea name="address" rows="2"></textarea>
            </label>

            <label class="pb-field">
                <span>{{ ___('Payment') }}</span>
                <select name="pay_via">
                    <option value="pay_on_counter">{{ ___('Pay on counter') }}</option>
                    @if($allow_payment)<option value="pay_online">{{ ___('Pay online') }}</option>@endif
                </select>
            </label>

            <label class="pb-field">
                <span>{{ ___('Coupon code') }}</span>
                <input type="text" name="coupon_code" placeholder="{{ ___('Optional') }}">
            </label>

            <label class="pb-field">
                <span>{{ ___('Order note') }}</span>
                <textarea name="message" rows="2"></textarea>
            </label>

            <div class="pb-order-form__error" id="pb-order-error"></div>

            <button type="submit" class="pb-btn pb-btn--primary">{{ ___('Place order') }}</button>
        </form>
    </div>
</div>
