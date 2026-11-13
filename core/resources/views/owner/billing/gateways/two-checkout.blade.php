{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout-bare')
@section('title', ___('Payment confirm'))

@section('content')
<div class="ui-pay">
    <p class="ui-overline"><a href="{{ route('home') }}">{{ ___('Home') }}</a> · {{ ___('Payment') }}</p>
    <h1 class="ui-pay__title">{{ ___('Payment details') }}</h1>
    <x-ui.card :title="'2Checkout'">
        @include('owner.billing.gateways._summary')
        <form action="{{ route('ipn', 'two_checkout') }}" method="POST" class="ui-stack">
            @csrf
            <input type="hidden" name="order_id" value="{{$transaction->id}}">
            <div class="ui-form-grid ui-pay__card">
                <div class="ui-form-grid__full">
                    <label class="ui-label" for="tco-number">{{ ___('CARD NUMBER') }}</label>
                    <input id="tco-number" class="ui-input ui-ltr" type="text" placeholder="{{___('CARD NUMBER')}}" autocomplete="cc-number" autofocus/>
                </div>
                <div>
                    <label class="ui-label" for="tco-expiry">MM / YYYY</label>
                    <input id="tco-expiry" class="ui-input ui-ltr" type="tel" placeholder="MM / YYYY" autocomplete="cc-exp" aria-required="true" aria-invalid="false">
                </div>
                <div>
                    <label class="ui-label" for="tco-cvc">CVV</label>
                    <input id="tco-cvc" class="ui-input ui-ltr" type="tel" placeholder="CVV" autocomplete="cc-csc"/>
                </div>
            </div>
            <small class="form-error ui-text-danger" role="alert"></small>
            <div class="ui-pay__actions">
                <x-ui.button type="submit" :block="true" id="btn-confirm">{{ ___('Pay Now') }}</x-ui.button>
                <x-ui.button :href="$cancel_url" variant="ghost" :block="true">{{ ___('Cancel Payment') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
@php
    // TCO.loadPubKey takes the environment. $sandbox is a bool (WI-06b adapter) or the stored mode string.
    $__tcoEnv = (($sandbox ?? null) === true || in_array(strtolower((string) ($sandbox ?? '')), ['sandbox', 'yes', '1', 'test'], true)) ? 'sandbox' : 'production';
@endphp
@push('scripts_at_bottom')
    <script src="{{ asset('assets/global/js/jquery.payment.js') }}"></script>
    <script src="//www.2checkout.com/checkout/api/2co.min.js"></script>
    <script nonce="{{ csp_nonce() }}">
        // 2Checkout card form: format the three card inputs, check number and
        // expiry locally, ask 2Checkout for a one-time token, then post only the
        // token (never the card) to our IPN endpoint. PCI: the card inputs carry no
        // `name`, so the browser never submits them; they are read here by id.
        (function ($) {
            var sellerId = @json($checkout_seller_id);
            var publicKey = @json($checkout_public_key);
            // loadPubKey takes the environment (sandbox | production), not the key.
            var environment = @json($__tcoEnv);
            var messages = { number: @json(___('Invalid card number.')), expiry: @json(___('Invalid expiration date.')) };
            var $number = $('#tco-number');
            var $expiry = $('#tco-expiry');
            var $cvc = $('#tco-cvc');
            var $error = $('.form-error');
            var $pay = $('#btn-confirm');

            function showError(text) { $error.text(text).show(); }
            function setBusy(on) { $pay.prop('disabled', on); if (on) { $pay.attr('aria-busy', 'true'); } else { $pay.removeAttr('aria-busy'); } }

            TCO.loadPubKey(environment);
            $number.payment('formatCardNumber');
            $cvc.payment('formatCardCVC');
            $expiry.payment('formatCardExpiry');

            $pay.on('click', function (event) {
                event.preventDefault();
                $error.hide();

                var when = $expiry.payment('cardExpiryVal');
                if (!$.payment.validateCardNumber($number.val())) { showError(messages.number); return; }
                if (!$.payment.validateCardExpiry(when.month, when.year)) { showError(messages.expiry); return; }

                setBusy(true);
                var $form = $pay.closest('form');
                TCO.requestToken(function (result) {
                    $('<input type="hidden" name="2checkoutToken">').val(result.response.token.token).appendTo($form);
                    $form.trigger('submit');
                }, function (result) {
                    setBusy(false);
                    showError(result.errorMsg);
                }, {
                    sellerId: sellerId,
                    publishableKey: publicKey,
                    ccNo: $number.val().replace(/\s/g, ''),
                    cvv: $cvc.val(),
                    expMonth: when.month,
                    expYear: when.year
                });
            });
        })(jQuery);
    </script>
@endpush
@endsection
