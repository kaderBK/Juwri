{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Storefront "choose how to pay" page for an online order (PaymentController@index).
    Self-contained: it renders on top of any storefront theme, so it carries its own
    small stylesheet and needs no theme or jQuery assets. Contract: StorefrontPaymentPageTest.
--}}
@extends('store_templates.layout')

@push('style_at_top')
    <style nonce="{{ csp_nonce() }}">
        .mb-pay{max-width:640px;margin-inline:auto;margin-block:32px;padding-inline:16px;color:#1c1c1c}
        .mb-pay-store{display:flex;align-items:center;gap:14px;margin-block:0 20px}
        .mb-pay-logo{width:56px;height:56px;border-radius:14px;object-fit:cover;flex:none}
        .mb-pay-store h1{font-size:1.2rem;font-weight:700;margin:0}
        .mb-pay-store p{margin:2px 0 0;opacity:.7}
        .mb-pay-card{border:1px solid rgba(0,0,0,.12);border-radius:16px;padding:clamp(18px,4vw,28px);background:#fff}
        .mb-pay-title{font-size:1.25rem;font-weight:700;margin-block:0 16px}
        .mb-pay-alert{border-radius:12px;padding:12px 14px;margin-block:0 18px;background:#fdecea;color:#611a15;border:1px solid #f5c6cb}
        .mb-pay-list{display:grid;gap:10px;margin-block:0 20px}
        .mb-pay-option{border:1px solid rgba(0,0,0,.14);border-radius:12px;padding:12px 14px}
        .mb-pay-option:has(input[type=radio]:checked){border-color:var(--theme-color-1,#111);box-shadow:0 0 0 1px var(--theme-color-1,#111)}
        .mb-pay-head{display:flex;align-items:center;gap:10px}
        .mb-pay-head label{flex:1;font-weight:600;cursor:pointer;margin:0}
        .mb-pay-mark{height:24px;width:auto;max-width:90px;object-fit:contain}
        .mb-pay-body{margin:10px 0 0;padding-inline-start:26px;font-size:.92rem;opacity:.8}
        .mb-pay-option:not(:has(input[type=radio]:checked)) .mb-pay-body{display:none}
        .mb-pay-note{margin:8px 0 0;font-weight:600}
        .mb-pay-input{width:100%;box-sizing:border-box;padding:11px 13px;border-radius:10px;border:1px solid rgba(0,0,0,.25);font-size:1rem;background:#fff;color:inherit}
        .mb-pay-submit{display:block;width:100%;border:0;border-radius:12px;padding:14px 18px;font-size:1.05rem;font-weight:700;cursor:pointer;color:#fff;background:var(--theme-color-1,#111)}
    </style>
@endpush

@section('content')
    @php
        $pickedMethod = (string) old('payment_method', '');
        $knownIds = collect($paymentGateways ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (! in_array($pickedMethod, $knownIds, true)) {
            $pickedMethod = $knownIds[0] ?? '';
        }
    @endphp

    <main class="mb-pay">
        <div class="mb-pay-store">
            <img class="mb-pay-logo" src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}"
                 alt="{{ $store->title }}" loading="lazy" decoding="async">
            <div>
                <h1>{{ $store->title }}</h1>
                @if(! empty($store->sub_title))
                    <p>{{ $store->sub_title }}</p>
                @endif
            </div>
        </div>

        <div class="mb-pay-card">
            <form action="{{ route('payment.pay', $transaction->id) }}" method="POST">
                @csrf
                <h2 class="mb-pay-title">{{ ___('Payment Method') }}</h2>

                @if(session('app_alert_message'))
                    <div class="mb-pay-alert" role="alert">{{ session('app_alert_message') }}</div>
                @endif

                @if(! empty($paymentGateways))
                    <div class="mb-pay-list">
                        @foreach($paymentGateways as $gateway)
                            <div class="mb-pay-option">
                                <div class="mb-pay-head">
                                    <input type="radio" name="payment_method" id="{{ $gateway->payment_folder }}"
                                           value="{{ $gateway->id }}" data-name="{{ $gateway->payment_folder }}"
                                           @checked($pickedMethod === (string) $gateway->id)>
                                    <label for="{{ $gateway->payment_folder }}">{{ $gateway->payment_title }}</label>
                                    <img class="mb-pay-mark" alt="{{ $gateway->payment_title }}"
                                         src="{{ asset('storage/payment_gateways/'.$gateway->payment_folder.'.png') }}">
                                </div>
                                <div class="mb-pay-body">
                                    @if($gateway->payment_folder === 'paystack')
                                        <input type="email" name="email" class="mb-pay-input"
                                               placeholder="{{ ___('Email') }}" value="{{ old('email') }}">
                                    @else
                                        <p>{{ ___('You will be redirected to the payment page for complete payment.') }}</p>
                                    @endif
                                    {{-- Owner decision: Stripe/Razorpay charge 3-decimal totals rounded down to their step — say so before paying. --}}
                                    @php($roundingNotice = \Juwri\Modules\Payments\Services\GatewayRounding::notice($gateway->payment_folder, $transaction->money('amount'), \Juwri\Modules\Payments\Services\Gateways\Settlement::currency($transaction)))
                                    @if($roundingNotice)
                                        <p class="mb-pay-note" data-rounding-notice="{{ $gateway->payment_folder }}">{{ $roundingNotice }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <button type="submit" name="payment_submit" class="mb-pay-submit">{{ ___('Submit') }}</button>
                @else
                    <div class="mb-pay-alert">{{ ___('No payment methods available right now please try again later.') }}</div>
                @endif
            </form>
        </div>
    </main>
@endsection
