{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: section_payments_tax — payment gateways with a shipped driver
     and the e-invoicing authorities / fiscal profiles (names as chips).
     Source of truth for the lists: rev/third-parties/THIRD-PARTIES.csv. --}}
@php
    $sbGateways = ['Stripe', 'Paylink', 'Paymob', 'Geidea', 'Fawry', 'Fatora', 'Noon Payments', 'T-Lync', 'Tamara', 'Tabby', 'GoCardless', 'Mollie', 'PayPal', 'Razorpay', 'Tap', 'Syriatel Cash'];
    $sbTaxLive = ['ZATCA', 'JoFotara', 'ETA'];
    $sbTaxProfiles = [___('UAE'), ___('Qatar'), ___('Kuwait'), ___('Bahrain'), ___('Oman'), ___('Morocco'), ___('Tunisia'), ___('Algeria')];
@endphp
<section class="mk-sec mk-sec--tint sb-block" data-site-block="section_payments_tax">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <h2 class="mk-h2">{{ ___('Get paid your way, with tax-compliant e-invoicing built in.') }}</h2>
        </div>
        <div class="mk-grid mk-grid--2">
            <article class="mk-card">
                <span class="mk-ico" aria-hidden="true"><x-ui.icon name="card" size="22" /></span>
                <h3>{{ ___('27 payment gateways') }}</h3>
                <p>{{ ___('Cards, wallets, cash on delivery and buy-now-pay-later — down to Syriatel Cash, MTN Cash and Sham Cash mobile wallets. Pick what your market trusts.') }}</p>
                <ul class="mk-chips">
                    @foreach ($sbGateways as $sbName)
                        <li>{{ $sbName }}</li>
                    @endforeach
                </ul>
                <p class="mk-small sb-foot">
                    <strong>{{ ___('No commission on your orders.') }}</strong>
                    {{ ___('Flat subscriptions, so busy nights stay yours.') }}
                    <br>
                    <strong>{{ ___('Multi-currency display.') }}</strong>
                    {{ ___('Diners see prices in their own currency — pinned rates or a daily FX feed.') }}
                </p>
            </article>
            <article class="mk-card">
                <span class="mk-ico" aria-hidden="true"><x-ui.icon name="receipt" size="22" /></span>
                <h3>{{ ___('E-invoicing for 11 tax authorities') }}</h3>
                <p>{{ ___('Invoices generated, signed and chained automatically. Live drivers for Saudi Arabia, Jordan and Egypt.') }}</p>
                <ul class="mk-chips">
                    @foreach ($sbTaxLive as $sbName)
                        <li class="sb-chip--on"><x-ui.icon name="check" size="14" />{{ $sbName }}</li>
                    @endforeach
                    @foreach ($sbTaxProfiles as $sbName)
                        <li>{{ $sbName }}</li>
                    @endforeach
                </ul>
                <p class="mk-small sb-foot">{{ ___('Phase-2 QR on every receipt, and an invoice chain your auditor will actually like.') }}</p>
            </article>
        </div>
        @if (Route::has('features'))
            <div class="mk-actions"><a href="{{ route('features') }}#payments" class="ui-btn ui-btn--secondary">{{ ___('See payments & tax in detail') }}</a></div>
        @endif
    </div>
</section>
