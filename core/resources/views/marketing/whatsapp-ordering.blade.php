{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- W1.1 (SEO run): WhatsApp-ordering money landing page.
     Title + H1 verbatim per KEYWORDS §WhatsApp (exact-match per locale). --}}
@section('title', ___('WhatsApp Ordering System for Restaurants').' - '.seo_brand())
@section('meta_description', ___('Take restaurant orders on WhatsApp with zero commission. Diners scan a QR, build their cart from your live menu, and the order lands in your chat as a clean itemized message — no app to install. Free to start.'))
@section('canonical', route('whatsapp.landing'))

@php
    /* Page-specific FAQ — rendered as the FAQ list AND pushed as FAQPage schema
       from the SAME source. */
    $waFaqs = [
        [___('Do my customers need to install anything?'), ___('No. Diners scan your QR or tap your link, order in the browser, and it arrives on WhatsApp. Nothing to download.')],
        [___('How do customers order on WhatsApp?'), ___('They browse your live menu, add items and options, then confirm — the cart is sent to your number as a structured, itemized message.')],
        [___('Do you charge commission on WhatsApp orders?'), ___('Never. You pay a flat subscription and keep 100% of every order — busy nights stay yours.')],
        [___('Can I take dine-in, pickup and delivery on WhatsApp?'), ___('Yes. One number handles every order type, and each message tells you which channel it came from.')],
        [___('Do I need a WhatsApp Business account?'), ___('A regular WhatsApp or WhatsApp Business number both work — orders arrive as normal chat messages you confirm.')],
    ];

    $waSteps = [
        [___('Diner scans your QR'), ___('They open your live menu on their phone — no app, no download.')],
        [___('They build the order'), ___('Items, options and quantities, straight from the menu you already manage.')],
        [___('It lands in your WhatsApp'), ___('A clean, itemized message arrives in your chat, ready to confirm.')],
    ];

    $waActions = [['url' => route('register'), 'label' => ___('Start free')]];
    if (Route::has('pricing')) {
        $waActions[] = ['url' => route('pricing'), 'label' => ___('See pricing'), 'ghost' => true];
    }
@endphp

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('WhatsApp Orders')],
]) !!}
@include('marketing.partials.jsonld', ['ld' => [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdPairs($waFaqs),
]])
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'eyebrow' => ___('WhatsApp Orders'),
        'title' => ___('WhatsApp Ordering System for Restaurants'),
        'sub' => ___('Turn every table, poster and bio link into a WhatsApp order. Diners scan, build their cart, and the order arrives in your chat as a clean, itemized message — no app to install, no commission to pay.'),
        'actions' => $waActions,
    ])

    {{-- How it works --}}
    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('How it works') }}</span>
                <h2 class="mk-h2">{{ ___('How WhatsApp ordering works') }}</h2>
            </div>
            <ol class="mk-steps">
                @foreach ($waSteps as $i => [$title, $desc])
                    <li class="mk-step">
                        <span class="mk-step__num" aria-hidden="true">{{ $i + 1 }}</span>
                        <h3>{{ $title }}</h3>
                        <p>{{ $desc }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Order-to-WhatsApp flow: a mock chat message stands in for the screenshot --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-split">
                <div class="mk-panel" role="img" aria-label="{{ ___('WhatsApp Orders') }}">
                    <div class="mk-panel__head"><x-ui.icon name="chat" size="18" /><span>{{ ___('WhatsApp Orders') }}</span></div>
                    <div class="wa-bubble" aria-hidden="true">
                        <p><b>#1042 · {{ ___('Table') }} 12</b></p>
                        <p>{{ ___('Mixed grill, 2x kofta') }}</p>
                        <p>{{ ___('Shish tawook wrap') }}</p>
                    </div>
                    <div class="mk-panel__row">
                        <span>{{ ___('New order — table 7') }}</span>
                        <span class="mk-panel__note">{{ ___('2× Classic Deluxe · just now') }}</span>
                    </div>
                </div>
                <div>
                    <h2 class="mk-h2">{{ ___('Every order, as a tidy WhatsApp message') }}</h2>
                    <p class="mk-lead wa-lead">{{ ___('Diners order from the menu and it arrives as a structured WhatsApp message — no app installs, no missed calls.') }}</p>
                    <ul class="mk-checks">
                        <li>{{ ___('Keep 100% of every order — flat subscription, no cut') }}</li>
                        <li>{{ ___('Works on dine-in, pickup and delivery — one number, every channel.') }}</li>
                        <li>{{ ___('Nothing to install for you or your diners — it\'s just WhatsApp.') }}</li>
                    </ul>
                    <ul class="mk-chips">
                        <li><x-ui.icon name="dine-in" size="16" />{{ ___('Dine-in') }}</li>
                        <li><x-ui.icon name="pickup" size="16" />{{ ___('Pickup') }}</li>
                        <li><x-ui.icon name="delivery" size="16" />{{ ___('Delivery') }}</li>
                        <li><x-ui.icon name="chat" size="16" />{{ ___('WhatsApp') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Zero commission --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-band">
                <div>
                    <h2 class="mk-h2">{{ ___('Your channel, your margin') }}</h2>
                    <ul class="mk-checks">
                        <li>{{ ___('No commission on your orders.') }} {{ ___('Flat subscriptions, so busy nights stay yours.') }}</li>
                        <li>{{ ___('No aggregator sits between you and your regulars.') }}</li>
                    </ul>
                </div>
                @if (Route::has('no-commission'))
                    <div class="mk-actions">
                        <a href="{{ route('no-commission') }}" class="ui-btn ui-btn--secondary">{{ ___('0% commission on every order') }}</a>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- FAQ (page-specific, mirrors the FAQPage schema above) --}}
    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('WhatsApp Orders') }}</span>
                <h2 class="mk-h2">{{ ___('Everything you need to know') }}</h2>
                <p class="mk-lead">{{ ___('Answers to the questions restaurants ask us most.') }}</p>
            </div>
            <div class="mk-faq">
                @foreach ($waFaqs as $i => [$q, $a])
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
