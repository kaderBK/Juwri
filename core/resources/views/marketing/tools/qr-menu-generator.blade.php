{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@php
    // GSC 2026-09: the page earns impressions for "qr menu maker", "qr code menu
    // generator", "qr menu creator" at positions 25–37 — target those words and give
    // Google real content (print guide, static-vs-digital, FAQ + FAQPage schema).
    $qrFaqs = [
        [___('Is this QR menu generator really free?'), ___('Yes. There is no sign-up and no watermark, and you can download as many QR codes as you like.')],
        [___('Does the QR code expire?'), ___('No. The code stores your link directly, so it keeps working for as long as that link works.')],
        [___('Can I change my menu without reprinting the QR code?'), ___('Yes, as long as the link stays the same — for example, replace the PDF at the same address. With a juwri digital menu you edit items and prices in your dashboard and the same QR code shows the changes instantly.')],
        [___('What size should I print a menu QR code?'), ___('At least 2.5 × 2.5 cm (1 inch) on tables. For posters and windows, go bigger: a good rule is one tenth of the scanning distance.')],
        [___('Should I download PNG or SVG?'), ___('PNG is best for office printers, documents and social media. SVG is best for print shops and large signage, because it scales to any size without blurring.')],
        [___('Can the QR code open a PDF menu or WhatsApp?'), ___('Yes. Paste any link: a PDF, your website, Google Drive, Instagram or a wa.me WhatsApp link.')],
        [___('Do customers need an app to scan it?'), ___('No. The camera on modern iPhone and Android phones scans QR codes without any extra app.')],
    ];
    // Colour presets: the hex goes to the render endpoint; the swatch colour
    // itself comes from a site-tools.css class (no inline style attribute).
    $qrPresets = [
        ['#111827', ___('Black'), 'black'],
        ['#7c3aed', ___('Purple'), 'purple'],
        ['#0f8a57', ___('Green'), 'green'],
        ['#2563eb', ___('Blue'), 'blue'],
        ['#c23f66', ___('Red'), 'red'],
    ];
@endphp

@section('title', ___('Free QR Menu Maker & QR Code Menu Generator') . ' | ' . seo_brand())
@section('meta_description', ___('Make a free QR code for your restaurant menu in seconds: paste your menu link, pick a color and download a print-ready PNG or SVG. No sign-up, no watermark, never expires.'))
@section('canonical', route('tools.qr-generator'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Free QR Code Menu Generator')],
]) !!}
@include('marketing.partials.jsonld', ['ld' => [
    '@context' => 'https://schema.org',
    '@type' => 'WebApplication',
    'name' => ___('Free QR Code Menu Generator'),
    'url' => url()->current(),
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Any',
    'isAccessibleForFree' => true,
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
]])
@include('marketing.partials.jsonld', ['ld' => [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdPairs($qrFaqs),
]])
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'eyebrow' => ___('Free tool'),
        'title' => ___('Free QR Code Menu Generator'),
        'sub' => ___('A free QR menu maker: turn your menu link into a scannable QR code and download it print-ready in seconds. No sign-up, no watermark.'),
        'crumbs' => [
            ['name' => ___('Home'), 'url' => route('home')],
            ['name' => ___('Free QR Code Menu Generator')],
        ],
    ])

    <section class="mk-sec">
        <div class="mk-wrap" data-qr-root
             data-qr-endpoint="{{ route('tools.qr-generator.render') }}"
             data-qr-sample="{{ url('/') }}">
            <div class="mk-tool">
                <div class="mk-form mk-tool__inputs">
                    <div class="mk-tool__row">
                        <label class="ui-label" for="qr-url">{{ ___('Menu link or website URL') }}</label>
                        <input type="url" id="qr-url" class="ui-input" data-qr-url inputmode="url" placeholder="https://" autocomplete="off" aria-describedby="qr-url-help">
                        <p class="mk-tool__help" id="qr-url-help">{{ ___('Paste the link diners should land on — your menu, website or WhatsApp.') }}</p>
                    </div>

                    <div class="mk-tool__row">
                        <label class="ui-label" for="qr-name">{{ ___('Restaurant name (optional)') }}</label>
                        <input type="text" id="qr-name" class="ui-input" data-qr-name maxlength="60" autocomplete="off">
                    </div>

                    <div class="mk-tool__row">
                        <span class="ui-label" id="qr-color-label">{{ ___('QR code color') }}</span>
                        <div class="mk-choice" role="group" aria-labelledby="qr-color-label">
                            @foreach ($qrPresets as [$hex, $label, $key])
                                <button type="button" class="mk-qr__swatch mk-qr__swatch--{{ $key }}" data-qr-color="{{ $hex }}" aria-label="{{ $label }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}"></button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="mk-result mk-tool__out">
                    <span class="mk-eyebrow">{{ ___('Your QR code') }}</span>
                    <div class="mk-qr__canvas">
                        <img data-qr-preview alt="{{ ___('Your QR code') }}" width="256" height="256">
                    </div>
                    <div class="mk-qr__dl">
                        <a class="ui-btn" data-qr-dl-png href="#" download>
                            <x-ui.icon name="download" size="18" />
                            <span>{{ ___('Download PNG') }}</span>
                        </a>
                        <a class="ui-btn ui-btn--secondary" data-qr-dl-svg href="#" download>
                            <x-ui.icon name="download" size="18" />
                            <span>{{ ___('Download SVG') }}</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="mk-band">
                <div>
                    <h2 class="mk-h2">{{ ___('Make it a real ordering menu') }}</h2>
                    <p>{{ ___('A static QR is just a link. Turn it into a live menu that takes orders on WhatsApp, updates prices instantly and keeps every commission.') }}</p>
                </div>
                <div class="mk-actions">
                    <a href="{{ route('register') }}" class="ui-btn">{{ ___('Create your free menu') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('How it works') }}</span>
                <h2 class="mk-h2">{{ ___('Free QR Code Menu Generator') }}</h2>
            </div>
            <ol class="mk-steps">
                @foreach ([
                    ___('Paste your menu or website link.'),
                    ___('Pick a color and download your QR — PNG for print, SVG for signage.'),
                    ___('Print it on tables, flyers and windows. Diners scan, you are open.'),
                ] as $i => $step)
                    <li class="mk-step">
                        <span class="mk-step__num">{{ $i + 1 }}</span>
                        <p>{{ $step }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <section class="mk-sec">
        <div class="mk-wrap mk-narrow">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('Print guide') }}</span>
                <h2 class="mk-h2">{{ ___('How to print your menu QR code') }}</h2>
            </div>
            <ul class="mk-checks">
                <li>{{ ___('Size: at least 2.5 × 2.5 cm (1 inch) on tables, and bigger on posters and windows that people scan from further away.') }}</li>
                <li>{{ ___('Contrast: use a dark code on a light background. Light colors on white are hard for cameras to read.') }}</li>
                <li>{{ ___('Margin: keep a blank border around the code so phones can find its edges.') }}</li>
                <li>{{ ___('Test: scan it with an iPhone and an Android phone before you print a whole batch.') }}</li>
                <li>{{ ___('Call to action: add a short line such as “Scan for our menu” next to the code.') }}</li>
                <li>{{ ___('Placement: table tents, table stickers, the front window, receipts and takeaway bags.') }}</li>
            </ul>
        </div>
    </section>

    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap mk-narrow">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('Compare') }}</span>
                <h2 class="mk-h2">{{ ___('Static QR code or a digital menu?') }}</h2>
                <p class="mk-lead">{{ ___('A static QR code opens one link and never changes. A digital menu is a live page behind the same kind of QR code.') }}</p>
            </div>
            <div class="mk-table-wrap">
                <table class="mk-table">
                    <thead>
                        <tr>
                            <td></td>
                            <th scope="col">{{ ___('Static QR code') }}</th>
                            <th scope="col" class="is-us">{{ ___('Digital menu') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([
                            [___('Price'), ___('Free'), ___('Free plan')],
                            [___('Update items and prices without reprinting'), ___('Only if the link stays the same'), true],
                            [___('Take orders on WhatsApp and online'), false, true],
                            [___('Photos, prices and several languages'), ___('Depends on your file'), true],
                            [___('See how many people scan it'), false, true],
                        ] as [$label, $static, $digital])
                            <tr>
                                <th scope="row">{{ $label }}</th>
                                @foreach ([$static, $digital] as $ci => $v)
                                    <td @class(['is-us' => $ci === 1])>
                                        @if ($v === true)
                                            <span class="mk-yes" role="img" aria-label="{{ ___('Included') }}">✓</span>
                                        @elseif ($v === false)
                                            <span class="mk-no" role="img" aria-label="{{ ___('Not included') }}">—</span>
                                        @else
                                            {{ $v }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mk-actions">
                <a href="{{ route('register') }}" class="ui-btn">{{ ___('Create your free menu') }}</a>
            </div>
        </div>
    </section>

    {{-- FAQ (mirrors the FAQPage JSON-LD above) --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <h2 class="mk-h2">{{ ___('Frequently Asked Questions') }}</h2>
            </div>
            <div class="mk-faq">
                @foreach ($qrFaqs as $i => [$q, $a])
                    <details class="mk-faq__item" @if ($i === 0) open @endif>
                        <summary>
                            <span class="mk-faq__num">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}.</span>
                            <span class="mk-faq__q">{{ $q }}</span>
                        </summary>
                        <div class="mk-faq__a"><p>{{ $a }}</p></div>
                    </details>
                @endforeach
            </div>
            <p class="mk-more-tools">
                {{ ___('More free tools:') }}
                @if (Route::has('tools.commission-calculator'))<a href="{{ route('tools.commission-calculator') }}">{{ ___('Delivery commission calculator') }}</a>@endif
                @if (Route::has('tools.menu-scanner')) · <a href="{{ route('tools.menu-scanner') }}">{{ ___('AI menu scanner') }}</a>@endif
                @if (Route::has('whatsapp.landing')) · <a href="{{ route('whatsapp.landing') }}">{{ ___('WhatsApp ordering for restaurants') }}</a>@endif
            </p>
        </div>
    </section>
@endsection

@push('scripts')
<script src="{{ asset('assets/owner-ui/tools/qr-menu-generator.js') }}?v={{ config('appinfo.version', '1') }}" defer></script>
@endpush
