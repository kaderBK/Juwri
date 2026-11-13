{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- checkout as a dialog: contact, order-type details, payment (the core's own method list; the theme only frames it),
     the server quote and Place order. Fields post under the checkout's own names (the storefront JS collects them). --}}
@php
    $__c = $checkout ?? ['fields' => [], 'payment_methods' => []];
    $__q = $cart ?? null;
    $__names = ['mobile' => 'phone-number'];
    $__auto = ['name' => 'name', 'mobile' => 'tel', 'address' => 'street-address'];
    $__table = $vm['context']['table']['label'] ?? null;
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
@endphp
<div id="checkout" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="checkout-title" @unless (($open ?? null) === 'checkout') hidden @endunless>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-sheet c-sheet--checkout">
        <div class="c-sheet__bar"><h2 class="c-sheet__title" id="checkout-title" tabindex="-1">{{ $kit->s('Checkout') }}</h2><button class="c-iconbtn c-iconbtn--sm" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <form class="c-checkout c-sheet__scroll" data-juwri-checkout novalidate>
            @if ($__table)<input type="hidden" name="table" value="{{ $__table }}">@endif
            <fieldset class="c-section"><legend class="c-section__title">{{ $__kiosk ? $kit->s('Your order') : $kit->s('Contact') }}</legend>
                @foreach ($__c['fields'] as $__f)
                @php($__name = $__names[$__f['id']] ?? $__f['id'])
                <div class="c-field"><label class="c-field__label" for="f-{{ $__f['id'] }}">{{ $__f['label'] }}</label>
                    <input class="c-input" id="f-{{ $__f['id'] }}" name="{{ $__name }}" type="{{ $__f['type'] === 'tel' ? 'tel' : 'text' }}" value="{{ $__f['value'] ?? '' }}" @if (! empty($__f['dir'])) dir="{{ $__f['dir'] }}" @endif autocomplete="{{ $__auto[$__f['id']] ?? 'off' }}" @if (! empty($__f['required'])) required aria-required="true" @endif @if (! empty($__f['help'])) aria-describedby="f-{{ $__f['id'] }}-help" @endif>
                    @if (! empty($__f['help']))<p class="c-field__help" id="f-{{ $__f['id'] }}-help">{{ $__f['help'] }}</p>@endif
                </div>
                @endforeach
                @if (! empty($__c['pin']))
                {{-- the store checks its delivery radius on a pin (server side): drop one on the map (OpenStreetMap, no key) --}}
                <div class="c-field" data-kit-pin data-center="{{ json_encode($__c['pin']['center']) }}" data-locating="{{ $kit->s('Finding the address…') }}" data-hint="{{ $kit->s('Move the map to place the pin') }}">
                    <span class="c-field__label" id="f-pin-label">{{ $kit->s('Delivery location') }}</span>
                    <input type="hidden" name="delivery_lat" id="f-pin-lat" value="">
                    <input type="hidden" name="delivery_lng" id="f-pin-lng" value="">
                    <button class="c-btn c-btn--secondary c-btn--block" type="button" id="f-pin-open" aria-describedby="f-pin-label f-pin-status">{{ $kit->icon('map-pin') }} <span>{{ $kit->s('Pin your location on the map') }}</span></button>
                    <p class="c-field__help" id="f-pin-status" role="status">{{ $kit->s('We check that we deliver to your pin.') }}</p>
                    <div class="c-pinmap" id="f-pin-modal" hidden>
                        <div class="c-pinmap__map" id="f-pin-map"></div>
                        <div class="c-pinmap__bar">
                            <button class="c-btn c-btn--ghost" type="button" id="f-pin-cancel">{{ $kit->s('Cancel') }}</button>
                            <button class="c-btn c-btn--primary" type="button" id="f-pin-confirm">{{ $kit->s('Use this location') }}</button>
                        </div>
                    </div>
                </div>
                @include('store_templates._partials.map-picker')
                @endif
                @unless ($__kiosk)
                <div class="c-field"><label class="c-field__label" for="f-message">{{ $kit->s('Note for the kitchen') }}</label><input class="c-input" id="f-message" name="message" type="text" maxlength="200" placeholder="{{ $kit->s('e.g. no onions') }}" autocomplete="off"></div>
                @endunless
            </fieldset>
            <fieldset class="c-section c-section--framed"><legend class="c-section__title">{{ $kit->s('Payment') }}</legend>
                <div class="c-choices">
                @foreach ($__c['payment_methods'] as $__m)
                    <label class="c-choice c-choice--card" for="pay-{{ $__m['id'] }}"><input class="c-choice__input u-vh" type="radio" id="pay-{{ $__m['id'] }}" name="pay_via" value="{{ $__m['id'] === 'online' ? 'pay_online' : $__m['id'] }}" @checked(! empty($__m['selected']))><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span>{{ $kit->icon($__m['icon'] ?? 'credit-card') }}<span class="c-choice__text"><span class="c-choice__label">{{ $__m['label'] }}</span>@if (! empty($__m['sub']))<span class="c-choice__sub">{{ $__m['sub'] }}</span>@endif</span></label>
                @endforeach
                </div>
            </fieldset>
            <section class="c-checkout__summary" aria-labelledby="sum-title"><h3 class="c-section__title" id="sum-title">{{ $kit->s('Order summary') }}</h3>
                @include('store_templates._kit.components.quote')
                <p class="c-field__help" data-juwri-stale hidden>{{ $kit->icon('wifi-off') }} {{ $kit->s('Offline: prices may change') }}</p>
            </section>
            @if (! empty($vm['store']['legal_links']) && ! $__kiosk)<p class="c-terms">@foreach ($vm['store']['legal_links'] as $__l)<a class="c-link" href="{{ $__l['url'] }}">{{ $__l['label'] }}</a> @endforeach</p>@endif
            <div class="c-state c-state--warning" data-kit-confirm hidden role="status"><span class="c-state__icon">{{ $kit->icon('refresh-cw') }}</span><div class="c-state__body"><h3 class="c-state__title">{{ $kit->s('Prices changed') }}</h3><dl class="c-oldnew"><div><dt>{{ $kit->s('Old total') }}</dt><dd class="c-oldnew__old" data-kit-old-total></dd></div><div><dt>{{ $kit->s('New total') }}</dt><dd data-kit-new-total></dd></div></dl></div></div>
            <p class="c-field__help c-field__error" data-kit-place-message role="alert"></p>
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block c-checkout__place" type="button" data-juwri-place><span>{{ $kit->s('Place order') }}</span><span data-juwri-total>{{ $__q['total']['formatted'] ?? '' }}</span></button>
        </form>
    </div>
</div>
