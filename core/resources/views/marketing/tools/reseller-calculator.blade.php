{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@section('title', ___('Reseller Margin Calculator — What Can You Earn White-Labelling?') . ' | ' . seo_brand())
@section('meta_description', ___('See what you can earn reselling a white-label QR menu and ordering platform: buy seats wholesale, set your own plan price, and keep the margin on every restaurant client.'))
@section('canonical', route('tools.reseller-calculator'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Reseller Margin Calculator')],
]) !!}
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'eyebrow' => ___('Free tool · For agencies & resellers'),
        'title' => ___('Reseller Margin Calculator'),
        'sub' => ___('Buy seats at wholesale, set the price you sell to restaurants, and see the margin you keep every month. Then launch it under your own brand.'),
        'crumbs' => [
            ['name' => ___('Home'), 'url' => route('home')],
            ['name' => ___('Reseller Margin Calculator')],
        ],
    ])

    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-tool" data-rc-root>
                <div class="mk-form mk-tool__inputs">
                    <div class="mk-tool__row">
                        <label class="mk-tool__label" for="rc-seat">{{ ___('Wholesale seat cost (per location / month)') }}</label>
                        <div class="mk-money">
                            <span aria-hidden="true">$</span>
                            <input type="number" id="rc-seat" class="ui-input" data-rc-seat min="0" step="0.5" value="5.99" inputmode="decimal">
                        </div>
                    </div>

                    <div class="mk-tool__row">
                        <label class="mk-tool__label" for="rc-price">{{ ___('Your price to the restaurant (per month)') }}</label>
                        <div class="mk-money">
                            <span aria-hidden="true">$</span>
                            <input type="number" id="rc-price" class="ui-input" data-rc-price min="0" step="1" value="49" inputmode="decimal">
                        </div>
                    </div>

                    <div class="mk-tool__row">
                        <label class="mk-tool__label" for="rc-clients">
                            <span>{{ ___('Restaurant clients') }}</span>
                            <b data-rc-clients-out>—</b>
                        </label>
                        <input type="range" id="rc-clients" class="mk-range" data-rc-clients min="1" max="200" step="1" value="30">
                    </div>

                    <p class="mk-tool__disclaim">{{ ___('An estimate before payment processing and your own costs. You set the price you charge; wholesale seat pricing follows your reseller tier.') }}</p>
                </div>

                <div class="mk-result mk-tool__out" aria-live="polite">
                    <span class="mk-eyebrow">{{ ___('Your estimated margin') }}</span>
                    <div class="mk-result__num" data-rc-monthly>—</div>
                    <div class="mk-result__label">{{ ___('per month, kept by you') }}</div>

                    <div class="mk-tool__year">
                        <b data-rc-yearly>—</b>
                        <span>{{ ___('per year') }}</span>
                    </div>

                    <p class="mk-tool__rate">
                        <span>{{ ___('Margin per client') }}</span>
                        <span aria-hidden="true">·</span>
                        <b data-rc-permargin>—</b>
                    </p>

                    <p class="mk-tool__keep" data-rc-note data-rc-below="{{ ___('Your price is below the wholesale seat cost — raise it above :cost to earn a margin.') }}">{{ ___('This is the spread between wholesale and your price, across every location you sign.') }}</p>

                    <div class="mk-actions">
                        @if (Route::has('partners.index')){{-- P1-13 13f: the reseller programme is the Reseller module's (ee) --}}
                            <a href="{{ route('partners.index') }}" class="ui-btn">{{ ___('See the reseller program') }}</a>
                        @endif
                        @if (Route::has('partners.signup'))
                            <a href="{{ route('partners.signup') }}" class="ui-btn ui-btn--secondary">{{ ___('Start free') }}</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap mk-narrow">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('For agencies & freelancers') }}</span>
                <h2 class="mk-h2">{{ ___('One product line, recurring margin') }}</h2>
                <p class="mk-lead">{{ ___('Sell restaurants their own branded QR menu and ordering — and keep the difference between wholesale and your price, every month.') }}</p>
            </div>
            <ul class="mk-checks">
                <li>{{ ___('Full white-label: your domain, your logo, your plans and prices — clients never see our name.') }}</li>
                <li>{{ ___('Wholesale tiers that get cheaper as your book of clients grows.') }}</li>
                <li>{{ ___('Your own wallet and payouts, plus a reseller panel with a landing-page builder.') }}</li>
            </ul>
        </div>
    </section>
@endsection

@push('scripts')
<script src="{{ asset('assets/owner-ui/tools/reseller-calc.js') }}?v={{ config('appinfo.version', '1') }}" defer></script>
@endpush
