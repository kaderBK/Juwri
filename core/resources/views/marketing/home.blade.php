{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- SEO title/description: on the default locale an admin-set home title and
     description win (home_meta_*, then site_title / meta_description); other
     locales use the translated defaults. A reseller host shows its own brand. --}}
@php
    $homeDefaultLocale = get_lang() === \Mcamara\LaravelLocalization\Facades\LaravelLocalization::getDefaultLocale();
    $homeTitle = active_brand_reseller()
        ? brand_title()
        : ($homeDefaultLocale ? (data_get($settings, 'home_meta_title') ?: (data_get($settings, 'site_title') ?: null)) : null);
    $homeDesc = $homeDefaultLocale
        ? (data_get($settings, 'home_meta_description') ?: (data_get($settings, 'meta_description') ?: null))
        : null;
@endphp

@section('title', $homeTitle ?: ___('Free QR menu with WhatsApp ordering').' | '.seo_brand())
@section('meta_description', $homeDesc ?: ___('Turn your paper menu into a digital ordering menu with a QR code for every restaurant and send orders straight to WhatsApp. Try it free.'))
@section('canonical', route('home'))

@push('structured_data')
@php
    $homeApp = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => brand_context()->isReseller() ? seo_brand() : ($settings->site_title ?? config('app.name')), // a reseller host never names the platform
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'url' => route('home'),
        'description' => $settings->meta_description ?? '',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    ];
    if (! empty($settings->site_logo)) {
        $homeApp['image'] = asset('storage/logo/'.$settings->site_logo);
    }
    $homeFaq = null;
    if (! empty($faqs) && $faqs->count()) {
        $homeFaq = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdModelsFallback($faqs),
        ];
    }
@endphp
<script type="application/ld+json">{!! json_encode($homeApp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@if ($homeFaq)
<script type="application/ld+json">{!! json_encode($homeFaq, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endif
{!! seo_website_jsonld() !!}
@endpush

@section('content')
    @include('marketing.sections.hero')
    @include('marketing.sections.trust')
    @include('marketing.sections.features')
    @include('marketing.sections.steps')
    @include('marketing.sections.verticals')

    {{-- Admin → Settings → "Show pricing on home page" (pricing_show_on_home,
         default on; the settings composer may hand back int 0, hence loose !=). --}}
    @if (data_get($settings, 'pricing_show_on_home', '1') != '0' && ! empty($plans) && $plans->count())
        <section class="mk-sec mk-sec--tint">
            <div class="mk-wrap">
                <div class="mk-sec__head">
                    <h2 class="mk-h2">{{ ___('Flexible pricing plans designed for your needs.') }}</h2>
                </div>
                @include('marketing.sections.pricing', ['plans' => $plans, 'gateHome' => true])
            </div>
        </section>
    @endif

    @include('marketing.sections.faq-home')
    @include('marketing.sections.blog')
    @if (Route::has('partners.index')){{-- P1-13 13f: the reseller programme is the Reseller module's (ee) --}}
    @include('marketing.sections.reseller')
    @endif
    @include('marketing.sections.cta')
@endsection
