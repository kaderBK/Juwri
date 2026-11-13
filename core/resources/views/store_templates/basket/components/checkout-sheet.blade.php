{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §9: the checkout as a dialog — contact, the delivery address (delivery only), the delivery or collection time (days, the
     store's hourly slots with their capacity, express), the out-of-stock choice for the whole order, payment, the order summary with
     the server quote, and Place order. The times, the 10-minute hold, the payment methods and the card hold note are the server's
     (the shared grocery script fetches them from the view model's endpoints); a kiosk collects now: no address, no time. Fields post
     under the goods checkout's names (name, phone, country, line1, city, notes). --}}
@php
    $__c = $checkout ?? ['fields' => [], 'payment_methods' => []];
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__fields = collect($__c['fields'] ?? []);
    $__names = ['mobile' => 'phone', 'address' => 'line1'];
    $__auto = ['name' => 'name', 'mobile' => 'tel', 'country' => 'country', 'address' => 'address-line1', 'city' => 'address-level2'];
    $__contact = ['name', 'mobile'];
    $__slots = $__c['slots'] ?? null;
    $__express = $__slots['express'] ?? null;
    $__daySel = \Juwri\Core\Support\ViewHelpers::selectedDayKey($__slots['days'] ?? []);
    $__sub = $__c['substitution'] ?? null;
@endphp
<div id="checkout" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="checkout-title" @unless (($open ?? null) === 'checkout') hidden @endunless>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-sheet c-sheet--checkout c-sheet--basket">
        <div class="c-sheet__bar"><h2 class="c-sheet__title" id="checkout-title" tabindex="-1">{{ $kit->s('Checkout') }}</h2><button class="c-iconbtn c-iconbtn--sm" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <form class="c-checkout c-sheet__scroll" data-juwri-checkout data-bk-checkout novalidate data-text-held="{{ $kit->s('Held for :time') }}" data-text-expired="{{ $kit->s('Your time is no longer held. We take it again if it is still free.') }}" data-text-need-delivery="{{ $kit->s('Choose a delivery time.') }}" data-text-need-collection="{{ $kit->s('Choose a collection time.') }}" data-text-need-fields="{{ $kit->s('Fill in the fields marked as required.') }}">
            <div class="c-checkout__form">
                <fieldset class="c-section"><legend class="c-section__title">{{ $__kiosk ? $kit->s('Your order') : $kit->s('Contact') }}</legend>
                    @foreach (\Juwri\Core\Support\ViewHelpers::contactFields($__fields, $__kiosk, $__contact) as $__f)
                    <div class="c-field"><label class="c-field__label" for="f-{{ $__f['id'] }}">{{ $__f['label'] }}</label>
                        <input class="c-input" id="f-{{ $__f['id'] }}" name="{{ $__names[$__f['id']] ?? $__f['id'] }}" type="{{ $__f['type'] === 'tel' ? 'tel' : 'text' }}" value="{{ $__f['value'] ?? '' }}" @if (! empty($__f['dir'])) dir="{{ $__f['dir'] }}" @endif autocomplete="{{ $__auto[$__f['id']] ?? 'off' }}" @if (! empty($__f['required'])) required aria-required="true" @endif @if (! empty($__f['help'])) aria-describedby="f-{{ $__f['id'] }}-help" @endif>
                        @if (! empty($__f['help']))<p class="c-field__help" id="f-{{ $__f['id'] }}-help">{{ $__f['help'] }}</p>@endif
                    </div>
                    @endforeach
                </fieldset>
                @unless ($__kiosk)
                @if (\Juwri\Core\Support\ViewHelpers::hasKey($__fields, 'address', 'id'))
                <fieldset class="c-section" data-bk-address><legend class="c-section__title">{{ $kit->s('Delivery address') }}</legend>
                    @foreach (\Juwri\Core\Support\ViewHelpers::addressFields($__fields, $__contact) as $__f)
                    @if (($__f['type'] ?? '') === 'hidden')
                    <input type="hidden" name="{{ $__names[$__f['id']] ?? $__f['id'] }}" value="{{ $__f['value'] ?? '' }}">
                    @else
                    <div class="c-field"><label class="c-field__label" for="f-{{ $__f['id'] }}">{{ $__f['label'] }}</label>
                        <input class="c-input" id="f-{{ $__f['id'] }}" name="{{ $__names[$__f['id']] ?? $__f['id'] }}" type="text" value="{{ $__f['value'] ?? '' }}" autocomplete="{{ $__auto[$__f['id']] ?? 'off' }}" @if (! empty($__f['required'])) data-bk-address-required aria-required="true" @endif @if (! empty($__f['help'])) aria-describedby="f-{{ $__f['id'] }}-help" @endif>
                        @if (! empty($__f['help']))<p class="c-field__help" id="f-{{ $__f['id'] }}-help">{{ $__f['help'] }}</p>@endif
                    </div>
                    @endif
                    @endforeach
                </fieldset>
                @endif
                @if ($__slots)
                <section class="c-section c-slotpicker" data-bk-slots aria-labelledby="sec-time"><h3 class="c-section__title" id="sec-time" data-bk-slots-title>{{ $__slots['title'] ?? $kit->s('Delivery time') }}</h3>
                    <div class="c-daytabs" role="tablist" aria-label="{{ $kit->s('Days') }}" data-bk-days>
                    @foreach ($__slots['days'] ?? [] as $__i => $__d)
                        <button class="c-daytab" role="tab" type="button" id="tab-{{ $__d['id'] }}" aria-selected="{{ ($__d['state'] ?? '') === 'selected' ? 'true' : 'false' }}" aria-controls="slots-panel" data-day="{{ $__d['id'] }}" @if (($__d['state'] ?? '') !== 'selected') tabindex="-1" @endif @if (($__d['state'] ?? '') === 'closed') aria-disabled="true" @endif>{{ $__d['label'] }}@if (! empty($__d['sub']))<span class="u-sr">, {{ $__d['sub'] }}</span>@endif</button>
                    @endforeach
                    </div>
                    <div class="c-slotpanel" role="tabpanel" id="slots-panel" data-bk-panel>
                        @if ($__express)
                        <label class="c-express" for="slot-express"><input class="c-express__input u-vh" type="radio" id="slot-express" name="slot" value="express" data-bk-express>{{ $kit->icon($__express['icon'] ?? 'zap') }}<span class="c-express__text"><span class="c-express__label">{{ $__express['label'] }}</span>@if (! empty($__express['amount_label']))<span class="c-express__fee">{{ $kit->flow($__express['amount_label']) }}</span>@endif</span><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span></label>
                        @endif
                        <div class="c-slots" role="group" aria-label="{{ $__slots['groups'][0]['label'] ?? $kit->s('Times') }}" data-bk-slotlist>
                        @foreach ($__slots['groups'][0]['slots'] ?? [] as $__sl)
                            <label class="c-slot{{ ($__sl['state'] ?? '') === 'full' ? ' is-full' : (($__sl['state'] ?? '') === 'few' ? ' is-few' : '') }}" for="slot-{{ $__sl['id'] }}"><input class="c-slot__input u-vh" type="radio" id="slot-{{ $__sl['id'] }}" name="slot" value="{{ $__sl['id'] }}" @if (($__sl['state'] ?? '') === 'full') aria-disabled="true" disabled @endif><span class="c-slot__face"><span class="c-slot__time"><bdi>{{ $__sl['label'] }}</bdi></span>@if (! empty($__sl['sub']))<span class="c-slot__sub"><span>{{ $__sl['sub'] }}</span></span>@endif</span></label>
                        @endforeach
                        </div>
                        <template data-bk-slot-template><label class="c-slot" for=""><input class="c-slot__input u-vh" type="radio" name="slot" value=""><span class="c-slot__face"><span class="c-slot__time"><bdi></bdi></span><span class="c-slot__sub" hidden><span></span></span></span></label></template>
                        <template data-bk-day-template><button class="c-daytab" role="tab" type="button" aria-controls="slots-panel"><span data-bk-day-label></span><span class="u-sr" data-bk-day-sub></span></button></template>
                        <p class="c-slotnote c-slotnote--sel" aria-live="polite" data-bk-selection></p>
                        <p class="c-slotnote" data-bk-hold></p>
                        @if (! empty($__slots['timezone_note']))<p class="c-slotnote" data-bk-tz>{{ $__slots['timezone_note'] }}</p>@endif
                        <p class="c-field__help c-field__error" role="alert" data-bk-slot-error></p>
                    </div>
                </section>
                @endif
                @endunless
                @if ($__sub && ! $__kiosk)
                <fieldset class="c-section"><legend class="c-section__title">{{ $__sub['label'] }}</legend>
                    <div class="c-group__opts">
                    @foreach ($__sub['options'] as $__o)
                        <label class="c-choice c-choice--row" for="sub-{{ $__o['id'] }}"><input class="c-choice__input u-vh" type="radio" id="sub-{{ $__o['id'] }}" name="substitution_preference" value="{{ $__o['id'] }}" data-bk-substitution @checked(! empty($__o['selected']))><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span><span class="c-choice__text"><span class="c-choice__label">{{ $__o['label'] }}</span>@if (! empty($__o['sub_label']))<span class="c-choice__sub">{{ $__o['sub_label'] }}</span>@endif</span></label>
                    @endforeach
                    </div>
                </fieldset>
                @endif
                <fieldset class="c-section c-section--framed"><legend class="c-section__title">{{ $kit->s('Payment') }}</legend>
                    <div class="c-paybox" data-bk-pay>
                    @foreach ($__c['payment_methods'] as $__m)
                        <label class="c-payrow" for="pay-{{ $__m['id'] }}"><input class="c-choice__input u-vh" type="radio" id="pay-{{ $__m['id'] }}" name="pay_via" value="{{ $__m['id'] === 'online' ? 'card' : ($__m['id'] === 'cash' ? 'cod' : $__m['id']) }}" @checked(! empty($__m['selected']))><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span>{{ $kit->icon($__m['icon'] ?? 'credit-card') }}<span class="c-choice__text"><span class="c-choice__label">{{ $__m['label'] }}</span>@if (! empty($__m['sub']))<span class="c-choice__sub">{{ $__m['sub'] }}</span>@endif</span></label>
                    @endforeach
                    </div>
                    <template data-bk-pay-template><label class="c-payrow" for=""><input class="c-choice__input u-vh" type="radio" name="pay_via" value=""><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span><span class="c-choice__text"><span class="c-choice__label"></span><span class="c-choice__sub" hidden></span></span></label></template>
                    <p class="c-note" data-bk-holdnote hidden>{{ $kit->icon('info') }}<span data-bk-holdnote-text></span></p>
                </fieldset>
            </div>
            <aside class="c-checkout__summary" aria-labelledby="sum-title"><h3 class="u-sr" id="sum-title">{{ $kit->s('Order summary') }}</h3>
                <div class="c-panel">
                    <ul class="c-rlines c-slines" data-juwri-lines><template data-juwri-line><li class="c-rline"><span class="c-rline__qty"><bdi data-juwri-line-qty></bdi>×</span><div class="c-rline__body"><p class="c-rline__name" data-juwri-line-name></p><p class="c-rline__unit" data-juwri-line-unit hidden></p><p class="c-bkline__note" data-juwri-line-note hidden></p><p class="c-bkline__note c-bkline__note--plain" data-juwri-line-substitution hidden></p></div><p class="c-rline__price"><span data-juwri-line-estimate hidden aria-hidden="true">~ </span><span data-juwri-line-total></span></p></li></template></ul>
                    @include('store_templates.basket.components.quote')
                    <p class="c-field__help" data-juwri-stale hidden>{{ $kit->icon('wifi-off') }} {{ $kit->s('Offline: prices may change') }}</p>
                </div>
                @if (! empty($vm['store']['legal_links']) && ! $__kiosk)<p class="c-terms">@foreach ($vm['store']['legal_links'] as $__l)<a class="c-link" href="{{ $__l['url'] }}">{{ $__l['label'] }}</a> @endforeach</p>@endif
                <div class="c-state c-state--warning" data-kit-confirm hidden role="status"><span class="c-state__icon">{{ $kit->icon('refresh-cw') }}</span><div class="c-state__body"><h3 class="c-state__title">{{ $kit->s('Prices changed') }}</h3><dl class="c-oldnew"><div><dt>{{ $kit->s('Old total') }}</dt><dd class="c-oldnew__old" data-kit-old-total></dd></div><div><dt>{{ $kit->s('New total') }}</dt><dd data-kit-new-total></dd></div></dl></div></div>
                <p class="c-field__help c-field__error" data-bk-warning role="status"></p>
                <p class="c-field__help c-field__error" data-kit-place-message role="alert"></p>
                <button class="c-btn c-btn--primary c-btn--lg c-btn--block c-bar__cta c-checkout__place" type="button" data-juwri-place><span data-bk-place-pay hidden>{{ $kit->s('Pay') }}</span><span data-bk-place-order>{{ $kit->s('Place order') }}</span><bdi class="c-money" data-juwri-total></bdi></button>
            </aside>
        </form>
    </div>
</div>
