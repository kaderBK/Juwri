{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
{{-- P2-9 shell for marketplace (Liquid) themes; brought to head-parity with the
     first-party storefront layout so marketplace themes keep the owner's
     analytics, SEO/social meta and PWA install — the diner-facing chrome
     (header/footer) is the THEME's job, exactly like the Blade storefronts. --}}
<html lang="{{ $lang ?? app()->getLocale() }}" dir="{{ $dir ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="{{ empty($studio) ? 'index, follow' : 'noindex, nofollow' }}">
    <title>{{ ___('the_qr_menu_for') }} {{ $store->title }} | {{ ___('alt_barcode') }}</title>

    {{-- SEO + social --}}
    {{-- Descriptions may be stored as numeric HTML entities; strip_tags leaves them
         intact and Blade re-escapes the `&`, shipping literal `&#1581;…` to crawlers. --}}
    @php $mpDesc = text_shorting(html_entity_decode(strip_tags((string) $store->description), ENT_QUOTES), 150); @endphp
    <meta name="description" content="{{ $mpDesc }} | {{ ___('alt_barcode') }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:title" content="{{ ___('the_qr_menu_for') }} {{ $store->title }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:description" content="{{ $mpDesc }}">
    <meta property="og:image" content="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ ___('the_qr_menu_for') }} {{ $store->title }}">
    <meta name="twitter:image" content="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">

    {{-- PWA + mobile (parity with the Blade storefront layout) --}}
    <meta name="theme-color" content="{{ $store->color ?? '#111111' }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">
    @if(Route::has('publicView.manifest'))
        <link rel="manifest" href="{{ route('publicView.manifest', $store->slug) }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    {{-- Theme Studio B0.5: design tokens, self-hosted font faces and the section
         library stylesheet (Juwri\Modules\Storefront\Storefront\ShellStyles). Written by this trusted
         shell because Liquid output can't carry a nonce'd style tag. --}}
    @foreach(($shellStyles['stylesheets'] ?? []) as $mpSheet)
        <link rel="stylesheet" href="{{ $mpSheet }}">
    @endforeach
    @if(($shellStyles['font_css'] ?? '') !== '')
        <style nonce="{{ csp_nonce() }}">{!! $shellStyles['font_css'] !!}</style>
    @endif
    @if(($shellStyles['base_css'] ?? '') !== '')
        <style nonce="{{ csp_nonce() }}">{!! $shellStyles['base_css'] !!}</style>
    @endif
    @if(($shellStyles['tokens_css'] ?? '') !== '')
        <style nonce="{{ csp_nonce() }}">:root{ {!! $shellStyles['tokens_css'] !!} }</style>
    @endif
    @if(($shellStyles['tokens_dark_css'] ?? '') !== '')
        <style nonce="{{ csp_nonce() }}">@media (prefers-color-scheme: dark){:root{ {!! $shellStyles['tokens_dark_css'] !!} }}</style>
    @endif
    {{-- Theme Studio E2: per-section AI CSS variants, sanitised + scoped under
         #mb-section-<sid> by Juwri\Modules\Storefront\Services\ThemeStudio\SectionRestyler. --}}
    @if(($shellStyles['sections_css'] ?? '') !== '')
        <style nonce="{{ csp_nonce() }}" id="mb-section-styles">{!! $shellStyles['sections_css'] !!}</style>
    @endif

    {{-- P5-1: the owner's validated GA / Meta pixel / font — same partial the
         first-party storefronts use, so analytics work on marketplace themes too. --}}
    @include('store_templates.partials.structured-integrations')

    {{-- Theme Studio C1: the editor bridge, ONLY in the signed-in owner's studio
         preview (?preview_theme=1&studio=1). Public renders never carry it. --}}
    @if(! empty($studio))
        @php
            $mbStudioCss = base_path('../assets/theme-studio/studio-bridge.css');
            $mbStudioJs = base_path('../assets/theme-studio/studio-bridge.js');
        @endphp
        <link rel="stylesheet" href="{{ asset('assets/theme-studio/studio-bridge.css') }}{{ is_file($mbStudioCss) ? '?v='.filemtime($mbStudioCss) : '' }}">
        <script src="{{ asset('assets/theme-studio/studio-bridge.js') }}{{ is_file($mbStudioJs) ? '?v='.filemtime($mbStudioJs) : '' }}" nonce="{{ csp_nonce() }}" defer></script>
    @endif
</head>
{{-- No forced background: each theme owns its own surface (a hardcoded colour
     here would flash the wrong colour behind a light theme during load). --}}
<body style="margin:0">
{!! $body !!}
{{-- P1-23: consent banner — starts the owner's GA / Pixel above only after consent. --}}
@include('partials.cookie-consent')
</body>
</html>
