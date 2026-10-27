{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- SEO Growth W4.0 — comparison-hub page. Copy is competitor-specific and
     supplied verbatim by the defs file (Juwri\Modules\Content\Services\CompareDefs::present);
     the template adds no new translatable strings. Fields: name, eyebrow,
     title, meta_title, meta, sub, intro[], groups[{group, rows[{label, us,
     them}]}], notes_title, notes[], better_fit{title, points[]},
     verdict_title, verdict, faq_title, faqs[{q, a}]. Honest-comparison tone
     (real feature/price rows + a "better fit" section) is the credibility. --}}

@php
    $cmpName = $cmp['name'];
    $h1 = $cmp['title'];
    $metaTitle = seo_title_override('compare.'.$cmp['slug'], $cmp['meta_title'] ?? $h1).' - '.seo_brand();
    $metaDesc = $cmp['meta'] ?? $h1;
    $cmpFaqs = array_values(array_filter($cmp['faqs'] ?? [], 'is_array'));
@endphp

@section('title', $metaTitle)
@section('meta_description', $metaDesc)
@section('canonical', $pageCanonical ?? route('compare.show', $cmp['slug']))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => $h1],
]) !!}
@if ($cmpFaqs)
    @include('marketing.partials.jsonld', ['ld' => [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdStripped($cmpFaqs),
    ]])
@endif
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'title' => $h1,
        'eyebrow' => $cmp['eyebrow'],
        'sub' => $cmp['sub'],
        'crumbs' => [['name' => ___('Home'), 'url' => route('home')], ['name' => $h1]],
    ])

    {{-- Intro + at-a-glance comparison table --}}
    <section class="mk-sec lp-sec--tight">
        <div class="mk-wrap">
            @if (! empty($cmp['intro']))
                <div class="lp-narrow lp-intro">
                    @foreach ($cmp['intro'] as $para)
                        <p class="mk-lead">{{ $para }}</p>
                    @endforeach
                </div>
            @endif

            @if (! empty($cmp['groups']))
                <div class="mk-table-wrap cmp-table" role="region" tabindex="0" aria-label="{{ $h1 }}">
                    <table class="mk-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="lp-sr">{{ ___('Features') }}</span></th>
                                <th scope="col" class="is-us">{{ seo_brand() }}</th>
                                <th scope="col">{{ $cmpName }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cmp['groups'] as $group)
                                @if (! empty($group['group']))
                                    <tr class="cmp-group"><th colspan="3" scope="colgroup">{{ $group['group'] }}</th></tr>
                                @endif
                                @foreach (($group['rows'] ?? []) as $row)
                                    @php $us = \Juwri\Core\Support\Views\PublicSiteViewHelpers::compareCell($row['us'] ?? false); $them = \Juwri\Core\Support\Views\PublicSiteViewHelpers::compareCell($row['them'] ?? false); @endphp
                                    <tr>
                                        <th scope="row">{{ $row['label'] }}</th>
                                        <td class="is-us">
                                            <span class="{{ $us['cls'] }}" @if ($us['aria']) aria-label="{{ $us['aria'] }}" @endif>{{ usd_ltr_inline($us['text']) }}</span>
                                        </td>
                                        <td>
                                            <span class="{{ $them['cls'] }}" @if ($them['aria']) aria-label="{{ $them['aria'] }}" @endif>{{ usd_ltr_inline($them['text']) }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    @if (! empty($cmp['notes']) || ! empty($cmp['better_fit']) || ! empty($cmp['verdict']))
        <section class="mk-sec mk-sec--tint">
            <div class="mk-wrap lp-narrow lp-stack">
                {{-- Migration / notes: competitor-specific steps (only when supplied). --}}
                @if (! empty($cmp['notes']))
                    <div class="mk-panel lp-block">
                        @if (! empty($cmp['notes_title']))
                            <div class="mk-panel__head"><x-ui.icon name="list" size="18" /><h2 class="mk-h3">{{ $cmp['notes_title'] }}</h2></div>
                        @endif
                        <ul class="mk-checks">
                            @foreach ($cmp['notes'] as $note)
                                <li>{{ $note }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Honest "when the competitor is the better fit" — credibility, not spin. --}}
                @if (! empty($cmp['better_fit']))
                    <div class="mk-panel lp-block">
                        <div class="mk-panel__head"><x-ui.icon name="info" size="18" /><h2 class="mk-h3">{{ $cmp['better_fit']['title'] ?? '' }}</h2></div>
                        <ul class="lp-list">
                            @foreach (($cmp['better_fit']['points'] ?? []) as $pt)
                                <li>{{ $pt }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Verdict --}}
                @if (! empty($cmp['verdict']))
                    <div class="mk-band lp-block">
                        <div>
                            <h2 class="mk-h2">{{ $cmp['verdict_title'] ?? $h1 }}</h2>
                            <p>{{ $cmp['verdict'] }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- FAQ (drives the FAQPage rich result pushed above) --}}
    @if ($cmpFaqs)
        <section class="mk-sec">
            <div class="mk-wrap">
                <div class="mk-sec__head">
                    <h2 class="mk-h2">{{ $cmp['faq_title'] ?? ___('Frequently Asked Questions') }}</h2>
                </div>
                <div class="mk-faq">
                    @foreach ($cmpFaqs as $i => $faq)
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
