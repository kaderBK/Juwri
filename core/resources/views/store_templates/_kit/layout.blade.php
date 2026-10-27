{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-05: the layout every kit theme shares. A kit theme reads only $vm (the Storefront View Model); the head data
     ($head: title, description, canonical, alternates, ...) is prepared by KitStorefront, never read from models here.
     Load order (PORTING.md section 1): tokens.css, the core's --tc-* block, kit.css, theme.css. No inline script, no
     inline style attribute, no third-party host. --}}
@php
    $__theme = $theme ?? ($vm['theme']['slug'] ?? 'table');
    $__scheme = in_array($vm['theme']['settings']['scheme.mode'] ?? 'auto', ['light', 'dark', 'auto'], true) ? $vm['theme']['settings']['scheme.mode'] : 'auto';
    $__h = $head ?? [];
    $__v = $assetVersion ?? '1';
    $__ratio = $vm['theme']['settings']['layout.image_ratio'] ?? '1:1';
    $__cart = $vm['theme']['settings']['layout.desktop_cart'] ?? 'panel';
    $__preload = ! empty($vm['store']['cover']) && ($vm['theme']['settings']['layout.header'] ?? 'cover') !== 'compact' ? $vm['store']['cover']['src'] : null;
@endphp
<!DOCTYPE html>
<html lang="{{ $vm['store']['locale'] }}" dir="{{ $vm['store']['dir'] }}" data-scheme="{{ $__scheme }}" data-theme-slug="{{ $__theme }}" @if ($__ratio !== '1:1') data-ratio="{{ $__ratio }}" @endif @if ($__cart === 'drawer') data-desktop-cart="drawer" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $__h['title'] ?? $vm['store']['name'] }}</title>
    <meta name="robots" content="{{ $__h['robots'] ?? 'index, follow' }}">
    @if (! empty($__h['description']))<meta name="description" content="{{ $__h['description'] }}">@endif
    @if (! empty($__h['canonical']))<link rel="canonical" href="{{ $__h['canonical'] }}">@endif
    @foreach ($__h['alternates'] ?? [] as $__alt)<link rel="alternate" href="{{ $__alt['url'] }}" hreflang="{{ $__alt['code'] }}">@endforeach
    <meta property="og:title" content="{{ $__h['title'] ?? $vm['store']['name'] }}">
    <meta property="og:type" content="{{ $__h['og_type'] ?? 'website' }}">
    <meta property="og:site_name" content="{{ $__h['site_name'] ?? $vm['store']['name'] }}">
    @if (! empty($__h['canonical']))<meta property="og:url" content="{{ $__h['canonical'] }}">@endif
    @if (! empty($__h['description']))<meta property="og:description" content="{{ $__h['description'] }}">@endif
    @if (! empty($__h['image']))<meta property="og:image" content="{{ $__h['image'] }}"><meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="{{ $__h['image'] }}">@endif
    @if (! empty($__h['favicon']))<link rel="icon" href="{{ $__h['favicon'] }}"><link rel="apple-touch-icon" href="{{ $__h['favicon'] }}">@endif
    @if (! empty($__h['manifest']))<link rel="manifest" href="{{ $__h['manifest'] }}">@endif
    @if (! empty($__h['theme_color']))<meta name="theme-color" content="{{ $__h['theme_color'] }}">@endif
    @include('store_templates._partials.jsonld-kit', ['jsonLd' => $__h['json_ld'] ?? null])
    @if ($__preload)<link rel="preload" as="image" fetchpriority="high" href="{{ $__preload }}">@endif
    <link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::kitAsset('store_templates/'.$__theme.'/css/tokens.css', $__v) }}">
    @include('store_templates._partials.kit-head')
    <link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::kitAsset('store_templates/_kit/kit.css', $__v) }}">
    <link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::kitAsset('store_templates/'.$__theme.'/css/theme.css', $__v) }}">
</head>
<body class="{{ $bodyClass ?? 'p-home' }}">
<div class="u-contents" data-kit-page @if (! empty($open)) inert @endif>
<a class="c-skip" href="#main">{{ $kit->s('Skip to content') }}</a>
@yield('content')
</div>
@yield('dialogs')
@include('partials.pharmacy-licence')
@include('store_templates._partials.kit-foot')
<script type="module" src="{{ \Juwri\Core\Support\ViewHelpers::kitAsset('store_templates/'.$__theme.'/js/theme.js', $__v) }}" nonce="{{ csp_nonce() }}"></script>
</body>
</html>
