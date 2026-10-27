{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: the basket's content, shared by the desktop panel and the basket sheet — empty state, free-delivery progress, lines, the
     out-of-stock choice, the quote. Core JS fills it. --}}
<div data-juwri-empty>
    <p class="c-state__text">{{ $kit->s('Your basket is empty') }}</p>
    <button class="c-btn c-btn--secondary" type="button" data-juwri-close>{{ $kit->s('Browse products') }}</button>
</div>
<div class="c-cartbody" data-juwri-filled hidden>
    @if (! empty($vm['context']['free_delivery_threshold']))
    <div class="c-fd" data-juwri-freeship hidden>
        <p class="c-fd__row">{{ $kit->icon('truck') }}<span class="c-fd__label" data-juwri-freeship-label></span></p>
        <progress class="c-fd__bar" data-juwri-freeship-bar max="100" value="0" aria-label="{{ $kit->s('Free delivery progress') }}"></progress>
    </div>
    @endif
    <ul class="c-cartpanel__lines c-bklines" data-juwri-lines><template data-juwri-line>@include('store_templates.basket.components.cart-line')</template></ul>
    <p class="c-subpref" data-juwri-substitution-row hidden>{{ $kit->icon('refresh-cw') }}<span class="c-subpref__text"><span class="c-subpref__title">{{ $kit->s("If it's out of stock") }}</span><span class="c-subpref__val" data-juwri-substitution></span></span></p>
    @include('store_templates.basket.components.quote')
    <p class="c-field__help" data-juwri-stale hidden>{{ $kit->icon('wifi-off') }} {{ $kit->s('Offline: prices may change') }}</p>
    <p class="c-field__help c-field__error" data-juwri-error role="status"></p>
</div>
