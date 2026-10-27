{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §9: the checkout as a dialog — contact, shipping address, the delivery method (the methods the store offers for that address,
     each with its dates and fee — the server's, fetched when the address changes), payment (online on the store's hosted pay page, or
     cash on delivery), the order summary (read-only lines + the server quote) and Place order. Fields post under the goods checkout's
     names (name, phone, email, country, line1, city, region, postal_code). Picking up in store needs no address. --}}
@php
    $__c = $checkout ?? ['fields' => [], 'payment_methods' => []];
    $__names = ['mobile' => 'phone', 'address' => 'line1'];
    $__auto = ['name' => 'name', 'mobile' => 'tel', 'email' => 'email', 'country' => 'country', 'address' => 'address-line1', 'city' => 'address-level2', 'region' => 'address-level1', 'postal_code' => 'postal-code'];
    $__contact = ['name', 'mobile', 'email'];
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__fields = collect($__c['fields'] ?? []);
    $__online = \Juwri\Core\Support\ViewHelpers::onlineSelected($__c['payment_methods'] ?? []);
@endphp
<div id="checkout" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="checkout-title" @unless (($open ?? null) === 'checkout') hidden @endunless>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-sheet c-sheet--checkout c-sheet--aisle">
        <div class="c-sheet__bar"><h2 class="c-sheet__title" id="checkout-title" tabindex="-1">{{ $kit->s('Checkout') }}</h2><p class="c-chkhead__secure">{{ $kit->icon('lock') }}<span>{{ $kit->s('Secure checkout') }}</span></p><button class="c-iconbtn c-iconbtn--sm" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <form class="c-checkout c-sheet__scroll" data-juwri-checkout data-kit-shipping-form novalidate>
            <div class="c-checkout__form">
                <fieldset class="c-section"><legend class="c-section__title">{{ $__kiosk ? $kit->s('Your order') : $kit->s('Contact') }}</legend>
                    @foreach (\Juwri\Core\Support\ViewHelpers::contactFields($__fields, $__kiosk, $__contact) as $__f)
                    <div class="c-field"><label class="c-field__label" for="f-{{ $__f['id'] }}">{{ $__f['label'] }}</label>
                        <input class="c-input" id="f-{{ $__f['id'] }}" name="{{ $__names[$__f['id']] ?? $__f['id'] }}" type="{{ in_array($__f['type'], ['tel', 'email'], true) ? $__f['type'] : 'text' }}" value="{{ $__f['value'] ?? '' }}" @if (! empty($__f['dir'])) dir="{{ $__f['dir'] }}" @endif autocomplete="{{ $__auto[$__f['id']] ?? 'off' }}" @if (! empty($__f['required'])) required aria-required="true" @endif @if (! empty($__f['help'])) aria-describedby="f-{{ $__f['id'] }}-help" @endif>
                        @if (! empty($__f['help']))<p class="c-field__help" id="f-{{ $__f['id'] }}-help">{{ $__f['help'] }}</p>@endif
                    </div>
                    @endforeach
                </fieldset>
                @unless ($__kiosk)
                <fieldset class="c-section" data-kit-address><legend class="c-section__title">{{ $kit->s('Shipping address') }}</legend>
                    @foreach (\Juwri\Core\Support\ViewHelpers::addressFields($__fields, $__contact) as $__f)
                    <div class="c-field"><label class="c-field__label" for="f-{{ $__f['id'] }}">{{ $__f['label'] }}</label>
                        <input class="c-input" id="f-{{ $__f['id'] }}" name="{{ $__names[$__f['id']] ?? $__f['id'] }}" type="text" value="{{ $__f['value'] ?? '' }}" @if (! empty($__f['dir'])) dir="{{ $__f['dir'] }}" @endif autocomplete="{{ $__auto[$__f['id']] ?? 'off' }}" @if ($__f['id'] === 'country') maxlength="2" autocapitalize="characters" @endif @if (! empty($__f['required'])) data-kit-address-required aria-required="true" @endif @if (! empty($__f['help'])) aria-describedby="f-{{ $__f['id'] }}-help" @endif>
                        @if (! empty($__f['help']))<p class="c-field__help" id="f-{{ $__f['id'] }}-help">{{ $__f['help'] }}</p>@endif
                    </div>
                    @endforeach
                </fieldset>
                <fieldset class="c-section" data-kit-shipping><legend class="c-section__title">{{ $kit->s('Delivery method') }}</legend>
                    <p class="c-field__help" data-kit-shipping-hint>{{ $kit->s('Enter your address to see the delivery options.') }}</p>
                    <div class="c-choices" data-kit-shipping-methods role="radiogroup" aria-label="{{ $kit->s('Delivery method') }}"></div>
                    <template data-kit-shipping-template><label class="c-choice c-choice--card c-rc"><input class="c-choice__input u-vh" type="radio" name="shipping_method" value=""><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span><span class="c-choice__text"><span class="c-choice__label" data-kit-method-label></span><span class="c-rc__sub" data-kit-method-sub></span></span><span class="c-rc__price" data-kit-method-price></span></label></template>
                    <p class="c-field__help c-field__error" data-kit-shipping-message role="status" hidden>{{ $kit->s("We can't ship to this address. Try another address or pick up in store.") }}</p>
                </fieldset>
                @endunless
                <fieldset class="c-section c-section--framed"><legend class="c-section__title">{{ $kit->s('Payment') }}</legend>
                    <div class="c-choices">
                    @foreach ($__c['payment_methods'] as $__m)
                        <label class="c-choice c-choice--card c-rc" for="pay-{{ $__m['id'] }}"><input class="c-choice__input u-vh" type="radio" id="pay-{{ $__m['id'] }}" name="pay_via" value="{{ $__m['id'] === 'online' ? 'pay_online' : $__m['id'] }}" data-kit-pay="{{ $__m['id'] }}" @checked(! empty($__m['selected']))><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span>{{ $kit->icon($__m['icon'] ?? 'credit-card') }}<span class="c-choice__text"><span class="c-choice__label">{{ $__m['label'] }}</span>@if (! empty($__m['sub']))<span class="c-rc__sub">{{ $__m['sub'] }}</span>@endif</span></label>
                    @endforeach
                    </div>
                </fieldset>
            </div>
            <aside class="c-checkout__summary c-panel" aria-labelledby="sum-title"><h3 class="c-panel__title" id="sum-title">{{ $kit->s('Order summary') }}</h3>
                <ul class="c-slines" data-juwri-lines><template data-juwri-line><li class="c-sline"><img class="c-sline__thumb" data-juwri-line-thumb hidden alt="" width="64" height="80" decoding="async"><div class="c-sline__body"><p class="c-sline__name" data-juwri-line-name></p><p class="c-sline__variant"><span data-juwri-line-choices></span> · <bdi dir="ltr" class="c-code"><span data-juwri-line-qty></span>×</bdi></p></div><p class="c-sline__price"><span class="c-sline__now" data-juwri-line-total></span><s class="c-sline__was" data-juwri-line-was hidden></s></p></li></template></ul>
                <dl class="c-quote c-quote--aisle" data-juwri-rows><template data-juwri-row><div class="c-quote__row"><dt data-juwri-row-label></dt><dd data-juwri-row-amount></dd></div></template></dl>
                <dl class="c-quote c-quote--aisle c-quote--total"><div class="c-quote__row c-quote__row--total"><dt>{{ $kit->s('Total') }}</dt><dd data-juwri-total></dd></div></dl>
                <p class="c-field__help" data-juwri-stale hidden>{{ $kit->icon('wifi-off') }} {{ $kit->s('Offline: prices may change') }}</p>
                @if (! empty($vm['store']['returns']['days']))<p class="c-quote__note">{{ $kit->s(':days-day returns', ['days' => (string) $vm['store']['returns']['days']]) }}</p>@endif
                @if (! empty($vm['store']['legal_links']) && ! $__kiosk)<p class="c-terms">@foreach ($vm['store']['legal_links'] as $__l)<a class="c-link" href="{{ $__l['url'] }}">{{ $__l['label'] }}</a> @endforeach</p>@endif
                <div class="c-state c-state--warning" data-kit-confirm hidden role="status"><span class="c-state__icon">{{ $kit->icon('refresh-cw') }}</span><div class="c-state__body"><h3 class="c-state__title">{{ $kit->s('Prices changed') }}</h3><dl class="c-oldnew"><div><dt>{{ $kit->s('Old total') }}</dt><dd class="c-oldnew__old" data-kit-old-total></dd></div><div><dt>{{ $kit->s('New total') }}</dt><dd data-kit-new-total></dd></div></dl></div></div>
                <p class="c-field__help c-field__error" data-kit-place-message role="alert"></p>
                <button class="c-btn c-btn--primary c-btn--lg c-btn--block c-checkout__place" type="button" data-juwri-place><span data-kit-place-pay @unless ($__online) hidden @endunless>{{ $kit->s('Pay') }}</span><span data-kit-place-order @if ($__online) hidden @endif>{{ $kit->s('Place order') }}</span><span data-juwri-total></span></button>
            </aside>
        </form>
    </div>
</div>
