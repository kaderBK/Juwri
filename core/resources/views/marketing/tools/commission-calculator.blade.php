{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@section('title', ___('Delivery Commission Calculator — What Do Aggregators Really Cost You?') . ' | ' . seo_brand())
@section('meta_description', ___('See what delivery apps like Yemeksepeti, iFood, Talabat and Uber Eats really take from your restaurant each month — then keep 100% with your own commission-free ordering.'))
@section('canonical', route('tools.commission-calculator'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Delivery Commission Calculator')],
]) !!}
@endpush

@php
    $ccMarkets = require resource_path('views/marketing/data/commission-defs.php');
    /* Client-side calc needs the presets as JSON. Pure numbers + proper-noun
       aggregator names + display symbols — no user input, so a nonce'd data
       island (never executed as script) is the CSP-safe carrier. */
    $ccJson = json_encode($ccMarkets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
@endphp

@section('content')
    @include('marketing.partials.page-hero', [
        'eyebrow' => ___('Free tool'),
        'title' => ___('Delivery Commission Calculator'),
        'sub' => ___('Pick your market, set your monthly orders and average ticket, and see what aggregator commissions cost you a year — next to a flat, commission-free subscription.'),
        'crumbs' => [
            ['name' => ___('Home'), 'url' => route('home')],
            ['name' => ___('Delivery Commission Calculator')],
        ],
    ])

    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-tool" data-cc-root>
                <script type="application/json" data-cc-data nonce="{{ csp_nonce() }}">{!! $ccJson !!}</script>

                <div class="mk-form mk-tool__inputs">
                    <div class="mk-tool__row">
                        <span class="mk-tool__label" id="cc-market-label">{{ ___('Choose your market') }}</span>
                        <div class="mk-choice" role="group" aria-labelledby="cc-market-label">
                            @foreach ($ccMarkets as $cc => $m)
                                <button type="button" class="mk-choice__btn" data-cc-market="{{ $cc }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ ___($m['name']) }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="mk-tool__row">
                        <label class="mk-tool__label" for="cc-orders">
                            <span>{{ ___('Monthly orders') }}</span>
                            <b data-cc-orders-out>—</b>
                        </label>
                        <input type="range" id="cc-orders" class="mk-range" data-cc-orders min="50" max="6000" step="50" value="600">
                    </div>

                    <div class="mk-tool__row">
                        <label class="mk-tool__label" for="cc-ticket">
                            <span>{{ ___('Average order value') }}</span>
                            <b data-cc-ticket-out>—</b>
                        </label>
                        <input type="range" id="cc-ticket" class="mk-range" data-cc-ticket min="5" max="4000" step="5" value="250">
                    </div>

                    <p class="mk-tool__disclaim">{{ ___("Figures use each platform's publicly reported commission rates. Your real cost can be higher once payment and marketing fees are added.") }}</p>
                </div>

                <div class="mk-result mk-tool__out" aria-live="polite">
                    <span class="mk-eyebrow">{{ ___('Your estimated commission') }}</span>
                    <div class="mk-result__num" data-cc-monthly>—</div>
                    <div class="mk-result__label">{{ ___('per month in delivery commission') }}</div>

                    <div class="mk-tool__year">
                        <b data-cc-yearly>—</b>
                        <span>{{ ___('per year') }}</span>
                    </div>

                    <p class="mk-tool__rate">
                        <span data-cc-aggregator>—</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ ___('up to') }} <b data-cc-rate>—</b></span>
                    </p>

                    <p class="mk-tool__keep">{{ ___("That's money you keep with juwri — a flat subscription with 0% commission on your orders.") }}</p>

                    <div class="mk-actions">
                        <a href="{{ route('register') }}" class="ui-btn">{{ ___('Start free — keep 100%') }}</a>
                        @if (Route::has('pricing'))
                            <a href="{{ route('pricing') }}" class="ui-btn ui-btn--secondary">{{ ___('See pricing') }}</a>
                        @endif
                    </div>

                    <button type="button" class="mk-tool__share" data-cc-share data-copied="{{ ___('Link copied') }}">
                        <x-ui.icon name="link" size="18" />
                        <span data-cc-share-label>{{ ___('Copy shareable link') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap mk-narrow">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('The commission-free way') }}</span>
                <h2 class="mk-h2">{{ ___('Stop renting your customers') }}</h2>
                <p class="mk-lead">{{ ___('Every order on an aggregator is a cut of your revenue and a customer they own. Your own channel flips both.') }}</p>
            </div>
            <ul class="mk-checks">
                <li>{{ ___('No commission on your orders — a flat monthly subscription instead.') }}</li>
                <li>{{ ___('Your own branded ordering: QR code, WhatsApp and a website that takes orders.') }}</li>
                <li>{{ ___("Keep every customer's contact and turn one-time diners into regulars.") }}</li>
            </ul>
        </div>
    </section>
@endsection

@push('scripts')
<script src="{{ asset('assets/owner-ui/tools/commission-calc.js') }}?v={{ config('appinfo.version', '1') }}" defer></script>
@endpush
