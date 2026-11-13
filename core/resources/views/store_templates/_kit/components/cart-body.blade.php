{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- cart content shared by the desktop panel and the cart sheet: lines, coupon, quote, offline notes. $cart prefills (server render). --}}
@php($__q = $cart ?? null)
<div data-juwri-empty @if ($__q) hidden @endif>
    <p class="c-state__text">{{ $kit->s('Nothing in your order yet') }}</p>
    <button class="c-btn c-btn--secondary" type="button" data-juwri-close>{{ $kit->s('Browse menu') }}</button>
</div>
<div data-juwri-filled @unless ($__q) hidden @endunless>
    <ul class="c-cartpanel__lines" data-juwri-lines>
        <template data-juwri-line>@include('store_templates._kit.components.cart-line', ['line' => null])</template>
        @foreach ($__q['lines'] ?? [] as $__ln)@include('store_templates._kit.components.cart-line', ['line' => $__ln])@endforeach
    </ul>
    @include('store_templates._kit.components.quote')
    <p class="c-field__help" data-juwri-stale hidden>{{ $kit->icon('wifi-off') }} {{ $kit->s('Offline: prices may change') }}</p>
    <p class="c-field__help c-field__error" data-juwri-error role="status"></p>
</div>
