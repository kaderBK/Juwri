{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- SEO Growth W5.2/W5.3 — long-form compliance guide. Restaurant-specific
     regulatory copy supplied verbatim by the defs file (Juwri\Modules\Content\Services\GuideDefs,
     locale-resolved into $content). The template adds no new translatable
     strings. Fields: country, eyebrow, title, meta_title, meta, sub, intro[],
     sections[{heading, body[], list[]}], faq_title, faqs[{q, a}],
     cta_eyebrow, cta_title, cta_sub, cta_label. --}}

@php
    $c = $content;
    $h1 = $c['title'] ?? $guide['slug'];
    $metaTitle = ($c['meta_title'] ?? $h1).' - '.seo_brand();
    $gdFaqs = array_values(array_filter($c['faqs'] ?? [], 'is_array'));

    // CTA points at the matching country page when that route exists; else it
    // degrades to registration so the guide never links to a 404.
    $countryCode = $guide['country'] ?? null;
    $ctaUrl = ($countryCode && Route::has('country.landing'))
        ? route('country.landing', $countryCode)
        : route('register');
@endphp

@section('title', $metaTitle)
@section('meta_description', $c['meta'] ?? $h1)
@section('canonical', $pageCanonical ?? route('guides.show', $guide['slug']))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => $h1],
]) !!}
@if ($gdFaqs)
    @include('marketing.partials.jsonld', ['ld' => [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdStripped($gdFaqs),
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

    <section class="mk-sec lp-sec--tight">
        <div class="mk-wrap">
            <article class="mk-prose">
                @foreach (($c['intro'] ?? []) as $para)
                    <p class="lp-prose-lead">{{ $para }}</p>
                @endforeach

                @foreach (($c['sections'] ?? []) as $section)
                    @if (! empty($section['heading']))
                        <h2>{{ $section['heading'] }}</h2>
                    @endif
                    @foreach (($section['body'] ?? []) as $para)
                        <p>{{ $para }}</p>
                    @endforeach
                    @if (! empty($section['list']))
                        <ul>
                            @foreach ($section['list'] as $li)
                                <li>{{ $li }}</li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach
            </article>
        </div>
    </section>

    {{-- FAQ (drives the FAQPage rich result pushed above) --}}
    @if ($gdFaqs)
        <section class="mk-sec mk-sec--tint">
            <div class="mk-wrap">
                <div class="mk-sec__head">
                    <h2 class="mk-h2">{{ $c['faq_title'] ?? ___('Frequently Asked Questions') }}</h2>
                </div>
                <div class="mk-faq">
                    @foreach ($gdFaqs as $i => $faq)
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

    {{-- Closing CTA: to the matching country page when it exists, else register. --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-cta">
                <span class="mk-eyebrow">{{ $c['cta_eyebrow'] ?? ___('Get started') }}</span>
                <h2 class="mk-h2">{{ $c['cta_title'] ?? ___('Ready to go live today?') }}</h2>
                @if (! empty($c['cta_sub']))
                    <p>{{ $c['cta_sub'] }}</p>
                @endif
                <div class="mk-actions">
                    <a href="{{ $ctaUrl }}" class="ui-btn">{{ $c['cta_label'] ?? ___('Start free') }}</a>
                    @if (Route::has('pricing'))
                        <a href="{{ route('pricing') }}" class="ui-btn ui-btn--ghost">{{ ___('See pricing') }}</a>
                    @endif
                </div>
                <p class="mk-cta__trust">{{ ___('No setup fee · No commission on orders · Cancel anytime') }}</p>
            </div>
        </div>
    </section>
@endsection
