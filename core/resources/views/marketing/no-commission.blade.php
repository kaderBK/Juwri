{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- W1.2 (SEO run): zero-commission money landing page.
     Title + H1 verbatim per KEYWORDS §ZeroCommission (exact-match per locale).
     The platform name comes from seo_brand() (:brand), never a literal. --}}
@section('title', ___('Online Ordering with Zero Commission').' - '.seo_brand())
@section('meta_description', ___('Delivery apps take up to 30–40% of every order. :brand is a flat subscription with 0% commission — take orders on your own QR, link and storefront and keep 100%. Free to start.', ['brand' => seo_brand()]))
@section('canonical', route('no-commission'))

@php
    /* Page-specific FAQ — rendered as the FAQ list AND pushed as FAQPage schema
       from the SAME source. */
    $ncFaqs = [
        [___('How much commission do delivery apps charge?'), ___('It varies by market, but aggregators commonly take up to 30–40% of each order once delivery and payment fees are counted. :brand charges 0% commission.', ['brand' => seo_brand()])],
        [___('How does zero commission actually work?'), ___('You pay a flat monthly or yearly subscription instead of a percentage. Every order you take is yours in full — no cut, no per-order fee.')],
        [___('Can I still offer delivery without an aggregator?'), ___('Yes. Take orders through your own QR, link and storefront, and fulfill them with your own drivers or a courier you choose.')],
        [___('Will I really save money versus the apps?'), ___('Once your monthly commission would top the subscription price, your own channel is cheaper — and every order above that is pure saving.')],
        [___('Do I keep my customer data?'), ___('Always. You own every phone number, order history and reorder — not a marketplace.')],
    ];

    /* Commission table DATA (KEYWORDS §Commissions). Region codes + brand names
       are locale-neutral; the rate is rendered as ___('Up to')." X%". Figures are
       typical public ranges cited "up to" — see the caption. */
    $ncRows = [
        ['TR', 'Yemeksepeti', '40%'],
        ['BR', 'iFood', '27%'],
        ['DE', 'Lieferando', '30%'],
        ['FR', 'Uber Eats / Deliveroo', '36%'],
        ['AE', 'Talabat', '30%'],
        ['PK', 'foodpanda', '30%'],
        ['IN', 'Zomato / Swiggy', '30%'],
    ];

    $ncActions = [['url' => route('register'), 'label' => ___('Start free')]];
    if (Route::has('pricing')) {
        $ncActions[] = ['url' => route('pricing'), 'label' => ___('See pricing'), 'ghost' => true];
    }
@endphp

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Online Ordering with Zero Commission')],
]) !!}
@include('marketing.partials.jsonld', ['ld' => [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdPairs($ncFaqs),
]])
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'eyebrow' => ___('No commission on your orders.'),
        'title' => ___('Online Ordering with Zero Commission'),
        'sub' => ___('Delivery apps skim a cut of every order and keep your customers. Take orders on your own channel instead — one flat price, zero commission, every diner is yours.'),
        'actions' => $ncActions,
    ])

    {{-- What delivery apps take --}}
    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('No commission on your orders.') }}</span>
                <h2 class="mk-h2">{{ ___('What delivery apps really take') }}</h2>
            </div>
            <div class="nc-table">
                <div class="mk-table-wrap">
                    <table class="mk-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ ___('Delivery app') }}</th>
                                <th scope="col">{{ ___('Typical commission') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ncRows as [$code, $brand, $pct])
                                <tr>
                                    <th scope="row">{{ $brand }} <span class="mk-small">{{ $code }}</span></th>
                                    <td class="mk-no">{{ ___('Up to') }} {{ $pct }}</td>
                                </tr>
                            @endforeach
                            <tr class="is-us">
                                <th scope="row">{{ seo_brand() }}</th>
                                <td class="mk-yes">0%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mk-note">{{ ___('Typical public commission ranges, 2026 — the real cost climbs once delivery and payment fees are added.') }}</p>
            </div>
        </div>
    </section>

    {{-- Flat price counter-positioning --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-split mk-split--rev">
                <div class="mk-panel" role="img" aria-label="{{ ___('One flat price. Every order is yours.') }}">
                    <div class="mk-panel__head"><x-ui.icon name="wallet" size="18" /><span>{{ seo_brand() }}</span></div>
                    <div class="mk-result">
                        <span class="mk-result__num">0%</span>
                        <span class="mk-result__label">{{ ___('Typical commission') }}</span>
                    </div>
                    <div class="mk-panel__row"><span>{{ ___('Dine-in') }}</span><x-ui.pill tone="success">0%</x-ui.pill></div>
                    <div class="mk-panel__row"><span>{{ ___('Pickup') }}</span><x-ui.pill tone="success">0%</x-ui.pill></div>
                    <div class="mk-panel__row"><span>{{ ___('Delivery') }}</span><x-ui.pill tone="success">0%</x-ui.pill></div>
                </div>
                <div>
                    <h2 class="mk-h2">{{ ___('One flat price. Every order is yours.') }}</h2>
                    <p class="mk-lead nc-lead">{{ ___('Aggregators take a slice of every single order. :brand is a flat subscription — take a thousand orders or ten thousand, the price is the same and the margin stays yours.', ['brand' => seo_brand()]) }}</p>
                    <ul class="mk-checks">
                        <li>{{ ___('No commission on your orders.') }} {{ ___('Flat subscriptions, so busy nights stay yours.') }}</li>
                        <li>{{ ___('Keep the customer relationship — their number, their reorders, your list.') }}</li>
                        <li>{{ ___('No per-order payment fee skimmed on top of the commission.') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Your own channel --}}
    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <h2 class="mk-h2">{{ ___('Your own ordering channel') }}</h2>
                <p class="mk-lead">{{ ___('QR at the table, a link in every bio, your own storefront — the order comes straight to you, not to a marketplace that owns your customer.') }}</p>
                <ul class="mk-chips mk-chips--center">
                    <li><x-ui.icon name="dine-in" size="16" />{{ ___('Dine-in') }}</li>
                    <li><x-ui.icon name="pickup" size="16" />{{ ___('Pickup') }}</li>
                    <li><x-ui.icon name="delivery" size="16" />{{ ___('Delivery') }}</li>
                    <li>
                        <x-ui.icon name="chat" size="16" />
                        @if (Route::has('whatsapp.landing'))
                            <a href="{{ route('whatsapp.landing') }}">{{ ___('WhatsApp') }}</a>
                        @else
                            {{ ___('WhatsApp') }}
                        @endif
                    </li>
                </ul>
            </div>
        </div>
    </section>

    {{-- FAQ (page-specific, mirrors the FAQPage schema above) --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('No commission on your orders.') }}</span>
                <h2 class="mk-h2">{{ ___('Everything you need to know') }}</h2>
                <p class="mk-lead">{{ ___('Answers to the questions restaurants ask us most.') }}</p>
            </div>
            <div class="mk-faq">
                @foreach ($ncFaqs as $i => [$q, $a])
                    <details class="mk-faq__item" @if ($i === 0) open @endif>
                        <summary>
                            <span class="mk-faq__num">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}.</span>
                            <span class="mk-faq__q">{{ $q }}</span>
                        </summary>
                        <div class="mk-faq__a"><p>{{ $a }}</p></div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    @include('marketing.sections.cta')
@endsection
