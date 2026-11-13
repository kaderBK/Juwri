{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
{{-- The dashboard runs Turbo Drive; a marketing page is a separate document,
     so Turbo must reload into it instead of swapping bodies. --}}
<meta name="turbo-visit-control" content="reload">
<x-ui.shell.theme-paint />

<title>@yield('title', brand_title())</title>
<meta name="description" content="@yield('meta_description', data_get($settings, 'meta_description') ?? '')">
@if (! empty(data_get($settings, 'meta_keywords')))
<meta name="keywords" content="{{ data_get($settings, 'meta_keywords') }}">
@endif
<meta name="robots" content="@yield('robots', 'index,follow')">
<link rel="canonical" href="@yield('canonical', url()->current())">
{{-- Alternates: a blog post passes its own translation set ($blogHreflang, may
     be empty), a CMS page likewise ($pageHreflang); single-canonical pages set
     $suppressHreflang; everything else gets the sitewide set. --}}
@isset($blogHreflang)
    {!! $blogHreflang !!}
@elseif (isset($pageHreflang))
    {!! $pageHreflang !!}
@elseif (! empty($suppressHreflang))
@else
    {!! seo_hreflang_tags() !!}
@endisset

@php
    $mkOgFallback = ($mkReseller && brand_logo())
        ? brand_logo()
        : (! empty(data_get($settings, 'site_logo')) ? asset('storage/logo/'.data_get($settings, 'site_logo')) : null);
@endphp
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ brand_title() }}">
<meta property="og:title" content="@yield('title', brand_title())">
<meta property="og:description" content="@yield('meta_description', data_get($settings, 'meta_description') ?? '')">
<meta property="og:url" content="@yield('canonical', url()->current())">
@hasSection('og_image')
<meta property="og:image" content="@yield('og_image')">
<meta name="twitter:image" content="@yield('og_image')">
@elseif ($mkOgFallback)
<meta property="og:image" content="{{ $mkOgFallback }}">
<meta name="twitter:image" content="{{ $mkOgFallback }}">
@endif
{!! seo_og_locale_tags() !!}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="@yield('title', brand_title())">
<meta name="twitter:description" content="@yield('meta_description', data_get($settings, 'meta_description') ?? '')">
{!! seo_organization_jsonld() !!}
@stack('structured_data')

@php $mkFavicon = brand_favicon() ?: (! empty(data_get($settings, 'site_favicon')) ? asset('storage/logo/'.data_get($settings, 'site_favicon')) : null); @endphp
@if ($mkFavicon)
<link rel="icon" href="{{ $mkFavicon }}">
@endif
<link rel="preload" href="{{ asset('assets/fonts/instrument-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
@include('partials.arabic-font')
@include('partials.brand-tile')

<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/tokens.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/components.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/layout.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/site.css') }}">
@foreach (['pages', 'tools', 'blocks', 'docs'] as $mkArea)
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/site-'.$mkArea.'.css') }}">
@endforeach

{{-- Brand accent: reseller primary colour on a reseller host, else the platform
     theme colour. Written to the kit accent and to the --ss-* names older page
     bodies and site blocks read. --}}
@php
    $mkBrand = \Juwri\Core\Support\Views\PublicSiteViewHelpers::headBrandColor($mkReseller, $settings);
@endphp
@if ($mkBrand)
    @php [$mkR, $mkG, $mkB] = sscanf($mkBrand, '#%02x%02x%02x'); @endphp
    <style nonce="{{ csp_nonce() }}">:root{--theme-color:{{ $mkBrand }};--ui-on-accent:{{ \Juwri\Core\Support\OwnerUi::onAccent($mkBrand) }};--ss-primary:{{ $mkBrand }};--ss-primary-rgb:{{ "$mkR, $mkG, $mkB" }};}</style>
@endif
{{-- HP-50: per-site tokens (sites.theme JSON), hex/length validated, applied
     after the brand colour on the platform host only (a reseller brand wins). --}}
@php
    $mkTokens = [];
    if ($mkSite && ! empty($mkSite->theme) && is_array($mkSite->theme) && ! $mkReseller) {
        foreach (['primary' => '--ss-primary', 'accent' => '--ss-accent', 'surface' => '--ss-surface', 'radius' => '--ss-radius'] as $mkKey => $mkVar) {
            $mkVal = $mkSite->theme[$mkKey] ?? null;
            if (! is_string($mkVal) || $mkVal === '') {
                continue;
            }
            $mkOk = $mkKey === 'radius'
                ? (bool) preg_match('/^\d{1,2}(px|rem|em)$/', $mkVal)
                : (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $mkVal);
            if ($mkOk) {
                $mkTokens[$mkVar] = $mkVal;
            }
        }
    }
@endphp
@if (! empty($mkTokens))
    @php
        $mkCss = '';
        foreach ($mkTokens as $mkVar => $mkVal) {
            $mkCss .= $mkVar.':'.$mkVal.';';
        }
        if (isset($mkTokens['--ss-primary'])) {
            [$mkR, $mkG, $mkB] = sscanf($mkTokens['--ss-primary'], '#%02x%02x%02x');
            $mkCss .= '--ss-primary-rgb:'.$mkR.', '.$mkG.', '.$mkB.';--theme-color:'.$mkTokens['--ss-primary'].';';
        }
    @endphp
    <style nonce="{{ csp_nonce() }}">:root{ {{ $mkCss }} }</style>
@endif
@stack('styles')
@include('partials.analytics')
