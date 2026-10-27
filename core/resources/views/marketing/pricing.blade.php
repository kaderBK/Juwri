{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@section('title', ___('QR menu pricing and plans').' - '.seo_brand())
@section('meta_description', ___('Simple plans for digital QR menus and online ordering. Start free and upgrade as your restaurant grows, with no hidden fees.'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Pricing')],
]) !!}
{{-- A SaaS subscription is a SoftwareApplication with one Offer per active plan
     (real prices), not a retail Product. --}}
@php
    $priceOffers = ($plans ?? collect())->map(fn ($p) => [
        '@type' => 'Offer',
        'name' => $p->name,
        'price' => number_format((float) $p->monthly_price, 2, '.', ''),
        'priceCurrency' => config('settings.currency_code', 'USD'),
        'url' => route('pricing'),
        'availability' => 'https://schema.org/InStock',
    ])->values()->all();
    $priceApp = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => seo_brand(),
        'description' => ___('Simple plans for digital QR menus and online ordering. Start free and upgrade as your restaurant grows, with no hidden fees.'),
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'image' => ! empty($settings->site_logo) ? asset('storage/logo/'.$settings->site_logo) : null,
        'offers' => $priceOffers ?: null,
    ]);
@endphp
@if ($priceOffers)
<script type="application/ld+json">{!! json_encode($priceApp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endif
@endpush

@section('content')
    <section class="mk-page-hero">
        <div class="mk-wrap">
            <span class="mk-eyebrow">{{ ___('Pricing') }}</span>
            <h1 class="mk-h1">{{ ___('Simple plans for any size') }}</h1>
            <p class="mk-lead">{{ ___('Start free. Switch monthly or save with annual.') }}</p>
        </div>
    </section>

    <section class="mk-sec">
        <div class="mk-wrap">
            @if (! empty($plans) && $plans->count())
                @include('marketing.sections.pricing', ['plans' => $plans, 'gateHome' => false])
            @else
                <div class="mk-empty">
                    <h2>{{ ___('Plans coming soon') }}</h2>
                    <p>{{ ___('Pricing tiers will be announced shortly.') }}</p>
                </div>
            @endif
        </div>
    </section>

    @include('marketing.sections.pricing-addons')

    <section class="mk-sec">
        <div class="mk-wrap mk-sec__head">
            <h2 class="mk-h2">{{ ___('Questions about pricing?') }}</h2>
            <p class="mk-lead">{{ ___('Talk to us - we will help you pick the right plan.') }}</p>
            @if (Route::has('contact'))
                <div class="mk-actions"><a href="{{ route('contact') }}" class="ui-btn">{{ ___('Contact sales') }}</a></div>
            @endif
        </div>
    </section>
@endsection
