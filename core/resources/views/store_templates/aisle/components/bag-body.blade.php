{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §8: what the bag holds — the free-shipping row, the lines, the promo code, the server quote. The cart script fills it. --}}
<div data-juwri-empty>
    <p class="c-state__text">{{ $kit->s('Your bag is empty') }}</p>
    <a class="c-btn c-btn--secondary" href="{{ $vm['endpoints']['home'] ?? '/' }}" data-juwri-close>{{ $kit->s('Shop new in') }}</a>
</div>
<div data-juwri-filled hidden>
    <div class="c-freeship" data-juwri-freeship hidden role="status"><div class="c-freeship__body"><p class="c-freeship__label" data-juwri-freeship-label></p><progress class="c-freeship__bar" max="100" value="0" data-juwri-freeship-bar aria-hidden="true"></progress></div></div>
    <ul class="c-baglines" data-juwri-lines aria-label="{{ $kit->s('Items in your bag') }}">
        <template data-juwri-line>@include('store_templates.aisle.components.bag-line', ['line' => null])</template>
    </ul>
    <form class="c-promo" data-kit-coupon>
        <label class="c-promo__label" for="promo-{{ $idp ?? 'd' }}">{{ $kit->s('Promo or gift card code') }}</label>
        <div class="c-promo__row"><input class="c-input" id="promo-{{ $idp ?? 'd' }}" name="code" type="text" autocomplete="off" data-juwri-code><button class="c-btn c-btn--secondary" type="button" data-juwri-apply-code>{{ $kit->s('Apply') }}</button></div>
        <p class="c-field__help" data-juwri-coupon-message role="status"></p>
    </form>
    <dl class="c-quote c-quote--aisle" data-juwri-rows><template data-juwri-row><div class="c-quote__row"><dt data-juwri-row-label></dt><dd data-juwri-row-amount></dd></div></template></dl>
    <dl class="c-quote c-quote--aisle c-quote--total"><div class="c-quote__row c-quote__row--total"><dt>{{ $kit->s('Total') }}</dt><dd data-juwri-total></dd></div></dl>
    <p class="c-field__help" data-juwri-stale hidden>{{ $kit->icon('wifi-off') }} {{ $kit->s('Offline: prices may change') }}</p>
    <p class="c-field__help c-field__error" data-juwri-error role="status"></p>
</div>
