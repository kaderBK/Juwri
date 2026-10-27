{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- rev/mv-marketing — per-vertical (industry) landing page. Content comes from
     the localized $v block (Juwri\Modules\Verticals\Services\VerticalLandingDefs); an un-authored
     vertical never reaches here (the controller 404s). Fields: eyebrow, title,
     meta_title, meta, sub, intro[], cta_primary, cta_demo, features_title,
     features[{icon, title, body}], faqs_title, faqs[{q, a}], cta_title. --}}

@php
    $h1 = $v['title'] ?? \Illuminate\Support\Str::title($slug);
    $metaTitle = ($v['meta_title'] ?? $h1).' - '.seo_brand();
    $metaDesc = $v['meta'] ?? ($v['sub'] ?? $h1);
    $inFaqs = array_values(array_filter($v['faqs'] ?? [], 'is_array'));
@endphp

@section('title', $metaTitle)
@section('meta_description', $metaDesc)
@section('canonical', route('industries.show', $slug))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => $h1, 'url' => route('industries.show', $slug)],
]) !!}
@if ($inFaqs)
    @include('marketing.partials.jsonld', ['ld' => [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdPlain($inFaqs),
    ]])
@endif
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'title' => $h1,
        'eyebrow' => $v['eyebrow'] ?? null,
        'sub' => $v['sub'] ?? null,
        'crumbs' => [['name' => ___('Home'), 'url' => route('home')], ['name' => $h1]],
    ])

    {{-- Intro + primary CTAs --}}
    <section class="mk-sec lp-sec--tight">
        <div class="mk-wrap lp-narrow">
            @foreach (($v['intro'] ?? []) as $para)
                <p class="mk-lead">{{ $para }}</p>
            @endforeach
            <div class="mk-actions">
                <a href="{{ route('register') }}" class="ui-btn">{{ $v['cta_primary'] ?? ___('Start free') }}</a>
                @if (! empty($demoUrl))
                    <a href="{{ $demoUrl }}" @if ($demoIsStore) target="_blank" rel="noopener" @endif class="ui-btn ui-btn--secondary">{{ $v['cta_demo'] ?? ___('See a live demo') }}</a>
                @endif
            </div>
        </div>
    </section>

    {{-- Capabilities: what this vertical gets --}}
    @if (! empty($v['features']))
        <section class="mk-sec mk-sec--tint">
            <div class="mk-wrap">
                @if (! empty($v['features_title']))
                    <div class="mk-sec__head">
                        <h2 class="mk-h2">{{ $v['features_title'] }}</h2>
                    </div>
                @endif
                <div class="mk-cards">
                    @foreach ($v['features'] as $f)
                        <article class="mk-card">
                            @if (! empty($f['icon']))
                                <span class="mk-ico" aria-hidden="true">@include('marketing.partials.vt-icon', ['icon' => $f['icon'], 'size' => 22])</span>
                            @endif
                            <h3>{{ $f['title'] ?? '' }}</h3>
                            <p>{{ $f['body'] ?? '' }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- FAQ --}}
    @if ($inFaqs)
        <section class="mk-sec">
            <div class="mk-wrap">
                <div class="mk-sec__head">
                    <h2 class="mk-h2">{{ $v['faqs_title'] ?? ___('Questions') }}</h2>
                </div>
                <div class="mk-faq">
                    @foreach ($inFaqs as $i => $f)
                        <details class="mk-faq__item">
                            <summary>
                                <span class="mk-faq__num">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}.</span>
                                <span class="mk-faq__q">{{ $f['q'] ?? '' }}</span>
                            </summary>
                            <div class="mk-faq__a"><p>{{ $f['a'] ?? '' }}</p></div>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Closing CTA --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-cta">
                <span class="mk-eyebrow">{{ $v['eyebrow'] ?? ___('Get started') }}</span>
                <h2 class="mk-h2">{{ $v['cta_title'] ?? ___('Start free — your store is live in minutes') }}</h2>
                @if (! empty($v['sub']))
                    <p>{{ $v['sub'] }}</p>
                @endif
                <div class="mk-actions">
                    <a href="{{ route('register') }}" class="ui-btn">{{ $v['cta_primary'] ?? ___('Start free') }}</a>
                    @if (Route::has('pricing'))
                        <a href="{{ route('pricing') }}" class="ui-btn ui-btn--ghost">{{ ___('See pricing') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
