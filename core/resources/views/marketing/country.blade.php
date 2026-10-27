{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- SEO Growth W3.0 — country landing page. All copy is country- and
     locale-specific and supplied verbatim by the defs file (App\Services\
     CountryDefs, localized block $c); the template adds no new translatable
     strings. Fields: eyebrow, title, meta_title, meta, sub, intro[],
     cta_primary, cta_demo, gateways_title, gateways[], compliance{title, body,
     points[]}, pain_title, pain, prices{title, items[]}, faq_title,
     faqs[{q, a}]. region_name/name feed the footer "Regions" menu. --}}

@php
    $h1 = $c['title'] ?? strtoupper($cc);
    $metaTitle = ($c['meta_title'] ?? $h1).' - '.seo_brand();
    $metaDesc = $c['meta'] ?? $h1;

    $ctActions = [['url' => route('register'), 'label' => $c['cta_primary'] ?? ___('Start free')]];
    $ctFaqs = array_values(array_filter($c['faqs'] ?? [], 'is_array'));
@endphp

@section('title', $metaTitle)
@section('meta_description', $metaDesc)
@section('canonical', $pageCanonical ?? route('country.landing', $cc))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => $h1],
]) !!}
@if ($ctFaqs)
    @include('marketing.partials.jsonld', ['ld' => [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdStripped($ctFaqs),
    ]])
@endif
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'title' => $h1,
        'eyebrow' => $c['eyebrow'] ?? null,
        'sub' => $c['sub'] ?? null,
        'crumbs' => [['name' => ___('Home'), 'url' => route('home')], ['name' => $h1]],
    ])

    {{-- Intro + primary CTAs --}}
    <section class="mk-sec lp-sec--tight">
        <div class="mk-wrap lp-narrow">
            @foreach (($c['intro'] ?? []) as $para)
                <p class="mk-lead">{{ $para }}</p>
            @endforeach
            <div class="mk-actions">
                <a href="{{ route('register') }}" class="ui-btn">{{ $c['cta_primary'] ?? ___('Start free') }}</a>
                @if (! empty($demoUrl))
                    <a href="{{ $demoUrl }}" @if ($demoIsStore) target="_blank" rel="noopener" @endif class="ui-btn ui-btn--secondary">{{ $c['cta_demo'] ?? ___('See how it works') }}</a>
                @endif
            </div>
        </div>
    </section>

    {{-- Payments / local gateways --}}
    @if (! empty($c['gateways']))
        <section class="mk-sec mk-sec--tint">
            <div class="mk-wrap">
                <div class="mk-sec__head">
                    @if (! empty($c['gateways_title']))
                        <h2 class="mk-h2">{{ $c['gateways_title'] }}</h2>
                    @endif
                    <ul class="mk-chips mk-chips--center">
                        @foreach ($c['gateways'] as $g)
                            <li><x-ui.icon name="card" size="16" />{{ $g }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>
    @endif

    @if (! empty($c['compliance']) || ! empty($c['pain']) || ! empty($c['prices']['items']))
        <section class="mk-sec">
            <div class="mk-wrap lp-narrow lp-stack">
                {{-- Tax authority / compliance --}}
                @if (! empty($c['compliance']))
                    @php $comp = $c['compliance']; @endphp
                    <div class="mk-panel lp-block">
                        <div class="mk-panel__head"><x-ui.icon name="check" size="18" /><h2 class="mk-h3">{{ $comp['title'] ?? '' }}</h2></div>
                        @if (! empty($comp['body']))
                            <p class="mk-muted">{{ $comp['body'] }}</p>
                        @endif
                        @if (! empty($comp['points']))
                            <ul class="mk-checks">
                                @foreach ($comp['points'] as $pt)
                                    <li>{{ $pt }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                {{-- Delivery-app pain + zero-commission counter --}}
                @if (! empty($c['pain']))
                    <div class="mk-band lp-block">
                        <div>
                            @if (! empty($c['pain_title']))
                                <h2 class="mk-h2">{{ $c['pain_title'] }}</h2>
                            @endif
                            <p>{{ $c['pain'] }}</p>
                        </div>
                    </div>
                @endif

                {{-- Local price examples --}}
                @if (! empty($c['prices']['items']))
                    <div class="mk-panel lp-block">
                        @if (! empty($c['prices']['title']))
                            <div class="mk-panel__head"><x-ui.icon name="wallet" size="18" /><h2 class="mk-h3">{{ $c['prices']['title'] }}</h2></div>
                        @endif
                        <ul class="mk-checks">
                            @foreach ($c['prices']['items'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- FAQ (drives the FAQPage schema pushed above) --}}
    @if ($ctFaqs)
        <section class="mk-sec mk-sec--tint">
            <div class="mk-wrap">
                <div class="mk-sec__head">
                    <h2 class="mk-h2">{{ $c['faq_title'] ?? ___('Frequently Asked Questions') }}</h2>
                </div>
                <div class="mk-faq">
                    @foreach ($ctFaqs as $i => $faq)
                        <details class="mk-faq__item" @if ($i === 0) open @endif>
                            <summary>
                                <span class="mk-faq__num">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}.</span>
                                <span class="mk-faq__q">{{ $faq['q'] ?? '' }}</span>
                            </summary>
                            <div class="mk-faq__a"><p>{{ $faq['a'] ?? '' }}</p></div>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @include('marketing.sections.cta')
@endsection
