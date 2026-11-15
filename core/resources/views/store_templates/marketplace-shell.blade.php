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
    @php
        $mbIsMenu = in_array((string) ($store->store_type ?? ''), ['restaurant', 'cafe', 'food', 'bakery'], true);
    @endphp
    <title>@if($mbIsMenu){{ ___('the_qr_menu_for') }} {{ $store->title }} | {{ ___('alt_barcode') }}@else{{ $store->title }}@endif</title>

    {{-- SEO + social --}}
    {{-- Descriptions may be stored as numeric HTML entities; strip_tags leaves them
         intact and Blade re-escapes the `&`, shipping literal `&#1581;…` to crawlers. --}}
    @php $mpDesc = text_shorting(html_entity_decode(strip_tags((string) $store->description), ENT_QUOTES), 150); @endphp
    <meta name="description" content="{{ $mpDesc }} | {{ ___('alt_barcode') }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:title" content="@if($mbIsMenu){{ ___('the_qr_menu_for') }} {{ $store->title }}@else{{ $store->title }}@endif">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:description" content="{{ $mpDesc }}">
    <meta property="og:image" content="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="@if($mbIsMenu){{ ___('the_qr_menu_for') }} {{ $store->title }}@else{{ $store->title }}@endif">
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
    {{-- The platform's DARK token set (Juwri\Modules\Storefront\Services\Theme\DarkTokens):
         the colours a Liquid storefront paints when the diner picks dark mode.
         Scoped to :root[data-theme="dark"], so it beats the theme's own light
         token block while a theme that paints its OWN dark palette (declared
         in its theme.json) keeps it — the platform emits nothing for that theme. --}}
    @if(($shellStyles['dark_css'] ?? '') !== '')
        <style nonce="{{ csp_nonce() }}">{!! $shellStyles['dark_css'] !!}</style>
    @endif
    {{-- Theme Studio E2: per-section AI CSS variants, sanitised + scoped under
         #mb-section-<sid> by Juwri\Modules\Storefront\Services\ThemeStudio\SectionRestyler. --}}
    @if(($shellStyles['sections_css'] ?? '') !== '')
        <style nonce="{{ csp_nonce() }}" id="mb-section-styles">{!! $shellStyles['sections_css'] !!}</style>
    @endif

    {{-- P5-1: the owner's validated GA / Meta pixel / font — same partial the
         first-party storefronts use, so analytics work on marketplace themes too. --}}
    @include('store_templates.partials.structured-integrations')

    {{-- P1-G: the shared storefront chrome the Liquid shell did not carry, so a
         marketplace theme gets the SAME platform features a first-party theme
         does — the keyboard focus ring, the JSON-LD menu schema. The shared
         layout renders these two in <head>; the rest are behavioural layers
         and go after <body> (see below). --}}
    @include('store_templates._partials.focus-ring')
    @include('store_templates._partials.jsonld-menu')

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

{{-- P1-G: the shared storefront BEHAVIOURAL layers. Every first-party theme
     inherits these from store_templates/layout.blade.php; the Liquid shell is
     its own document and used to inherit none of them, so a marketplace theme
     was missing the platform's own features (the order feedback, the review
     prompt, the item reviews, the share/dark-mode/clear-device-data engines,
     the mouse-scrolling for the horizontal rails, the owner's custom blocks,
     the info links, the deep links, the theme anchor engine).

     They are self-gating: each renders nothing unless the store has the
     feature on, and each stands down when the theme already ships its own
     control. Six are deliberately NOT included here because the LIQUID
     commerce runtime already renders them and including them would show the
     customer two of each:
       · tip-presets           → the runtime's own tip buttons
       · schedule-slot-picker   → the runtime's own slot picker
       · checkout-enhancements  → the runtime renders the gift-card field and
                                  sends scheduled_at itself
       · order-result           → the runtime's own confirmation + track link
       · home-order-type        → the runtime's order type lives in the cart
       · theme-tokens / theme-fonts / riyal-font → the shell already writes
                                  the tokens and the font faces (ShellStyles)
     --}}
@include('store_templates._partials.splash')
@include('store_templates._partials.theme-preview-bridge')
@include('store_templates._partials.hotel-guest')
@include('store_templates._partials.order-field-forwarder')
@include('store_templates._partials.currency-default')
@include('store_templates._partials.currency-estimate-notice')
@include('store_templates._partials.locale-suggest')
@include('store_templates._partials.story-cta-policy')
@include('store_templates._partials.item-reviews')
@include('store_templates._partials.checkout-otp')
@include('store_templates._partials.checkout-feedback')
@include('store_templates._partials.store-announcement')
@include('store_templates._partials.review-prompt')
@include('store_templates._partials.loyalty-redeem')
@include('store_templates._partials.google-wallet')
@include('store_templates._partials.google-wallet-giftcards')
@include('store_templates._partials.table-verify')
@include('store_templates._partials.combos')
@include('store_templates._partials.map-picker')
@include('store_templates._partials.clear-device-data')
@include('store_templates._partials.rail-mouse-scroll')
@include('store_templates._partials.theme-anchor')
@include('store_templates._partials.offers-strip')
@include('store_templates._partials.custom-blocks')
@include('store_templates._partials.item-deeplink')
@include('store_templates._partials.info-links')
@include('store_templates._partials.store-reviews-place')

{{-- P1-G — shared language switch. The commerce runtime's own handler writes
     the menu-language cookie and reloads, which cannot work under prefix-mode
     locales: the reload keeps the old URL prefix and the UI comes back in the
     wrong language (and never switches at all when the store's language list
     comes from the platform rather than the theme). This partial owns the
     switch centrally, in the capture phase, so one implementation serves the
     Blade themes AND the Liquid marketplace ones. --}}
@include('store_templates._partials.language-switch', ['switchMode' => $languageSwitchMode ?? 'segment', 'dropFragment' => $languageSwitchDropFragment ?? true])
@include('store_templates._partials.sheet-a11y')
@include('store_templates._partials.back-link')
{{-- P1-23: consent banner — starts the owner's GA / Pixel above only after consent. --}}
@include('partials.cookie-consent')
</body>
</html>
