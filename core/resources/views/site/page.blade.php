{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- HOME-PAGES HP-13 / WI-16g: block-composed platform page on the kit frame
     (marketing.layout: SEO head, hreflang, OG, org JSON-LD, dark mode, RTL,
     nav, footer). SEO yields are set ONLY when the page's seo JSON supplies a
     value — otherwise the layout's $settings fallbacks apply.
     Block wrappers live at site/blocks/{type}.blade.php (bodies in the wrapper
     or in site/blocks/partials/*); the renderer skips a missing wrapper, so an
     unknown type can never 500 a published page.
     No hero head assets: the hero block has no admin image, so there is no LCP
     image to preload; the headline rotator lives in site.js. --}}

@if(($siteSeo['title'] ?? '') !== '')
    @section('title', $siteSeo['title'])
@endif

@if(($siteSeo['description'] ?? '') !== '')
    @section('meta_description', $siteSeo['description'])
@endif

@if(($siteSeo['og_image'] ?? '') !== '')
    @section('og_image', $siteSeo['og_image'])
@endif

@if(($siteSeo['robots'] ?? '') !== '')
    @section('robots', $siteSeo['robots'])
@endif

@section('canonical', $sitePage->type === 'home' ? route('home') : url()->current())

{{-- HP-23: structured data is OWNED by the blocks that warrant it, but the
     fragment-cached wrapper strings cannot @push into the layout stack — so
     the page view derives it from enabled published blocks: SoftwareApplication
     when a hero block is present, FAQPage when a faq_accordion block is present
     AND rows exist. Organization JSON-LD stays in the layout. --}}
@php
    $hpEnabledTypes = \Juwri\Core\Support\Views\PublicSiteViewHelpers::enabledBlockTypes($sitePage->published_blocks['blocks'] ?? []);

    $hpAppLd = null;
    if (in_array('hero', $hpEnabledTypes, true)) {
        $hpAppLd = [
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
            $hpAppLd['image'] = asset('storage/logo/'.$settings->site_logo);
        }
    }

    $hpFaqLd = null;
    if (in_array('faq_accordion', $hpEnabledTypes, true)) {
        try {
            $hpFaqRows = \Juwri\Modules\Content\Models\Faq::where('active', 1)->where(function ($query) {
                $query->where('translation_lang', get_lang())->orWhereNull('translation_lang');
            })->limit(10)->get();
        } catch (\Throwable $e) {
            $hpFaqRows = collect();
        }
        if ($hpFaqRows->count()) {
            $hpFaqLd = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => \Juwri\Core\Support\ViewHelpers::faqLdModelsFallback($hpFaqRows),
            ];
        }
    }
@endphp

@push('structured_data')
    @include('marketing.partials.jsonld', ['ld' => $hpAppLd])
    @include('marketing.partials.jsonld', ['ld' => $hpFaqLd])
@endpush

@section('content')
{!! $siteBlocksHtml !!}
@endsection
