{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@section('title', ___('QR menu questions, answered').' - '.seo_brand())
@section('meta_description', ___('Answers to common questions about creating a QR menu, taking online orders, payments and managing your restaurant.'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('FAQ')],
]) !!}
@php
    $faqLd = \Juwri\Core\Support\ViewHelpers::faqLdModels($faqs ?? collect());
@endphp
@if ($faqLd)
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqLd], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endif
@endpush

@section('content')
    <section class="mk-page-hero">
        <div class="mk-wrap">
            <span class="mk-eyebrow">{{ ___('FAQ') }}</span>
            <h1 class="mk-h1">{{ ___('Frequently asked questions') }}</h1>
            <p class="mk-lead">{{ ___('Can\'t find what you\'re looking for? Contact us.') }}</p>
        </div>
    </section>

    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap">
            @if (! empty($faqs) && $faqs->count())
                @include('marketing.sections.faq', ['faqs' => $faqs])
                @if (method_exists($faqs, 'links') && $faqs->hasPages())
                    <div class="mk-actions">{{ $faqs->links() }}</div>
                @endif
            @else
                <div class="mk-empty">
                    <h2>{{ ___('No questions yet') }}</h2>
                    <p>{{ ___('Check back soon - or contact us with your question.') }}</p>
                </div>
            @endif
        </div>
    </section>

    <section class="mk-sec">
        <div class="mk-wrap mk-sec__head">
            <h2 class="mk-h2">{{ ___('Still have questions?') }}</h2>
            <p class="mk-lead">{{ ___('Our team replies within one business day.') }}</p>
            @if (Route::has('contact'))
                <div class="mk-actions"><a href="{{ route('contact') }}" class="ui-btn">{{ ___('Get in touch') }}</a></div>
            @endif
        </div>
    </section>
@endsection
