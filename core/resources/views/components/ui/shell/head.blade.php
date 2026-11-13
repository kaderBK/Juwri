{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.head /> — <head> contents for the owner kit shell (<x-ui.layout>).

  Order matters: theme pre-paint first (no flash), then the legacy screen stack
  (x-ui.shell.legacy-styles, removed screen by screen in WI-16c), then the kit
  (tokens → components → shell → screens → skin → layout), so kit rules win.
--}}
@php
    $__reseller = active_brand_reseller();
    $__appName = brand_title();
    // Accent = reseller brand on a reseller host, else the platform brand colour.
    $__accent = ($__reseller && $__reseller->primary_color) ? $__reseller->primary_color : ($settings->theme_color ?? null);
    $__accent = \Juwri\Core\Support\HexColor::safe($__accent); // BL-036 R1
    $__rgb = implode(', ', array_map('hexdec', str_split(substr($__accent, 1), 2)));
    $__rtl = function_exists('current_language') && current_language() && current_language()->direction === 'rtl';
    
    $__inApp = \Juwri\Core\Support\OwnerApp::inApp();
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="csrf-token" content="{{ csrf_token() }}">

<x-ui.shell.theme-paint />

<title>@yield('title', $__appName)</title>
<meta name="description" content="@yield('description', $settings->meta_description ?? '')">
<link rel="canonical" href="@yield('canonical', url()->current())">
{!! seo_hreflang_tags() !!}
{!! seo_organization_jsonld() !!}
@hasSection('og_image')
<meta property="og:image" content="@yield('og_image')">
@elseif (! empty($settings->social_share_image))
<meta property="og:image" content="{{ asset('storage/'.$settings->social_share_image) }}">
@endif
<meta property="og:title" content="@yield('og_title', $__appName)">
<meta property="og:description" content="@yield('description', $settings->meta_description ?? '')">
<meta property="og:type" content="website">
<meta property="og:url" content="@yield('canonical', url()->current())">
<meta property="og:site_name" content="{{ seo_brand() }}">
{!! seo_og_locale_tags() !!}
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="{{ brand_favicon() ?: asset('storage/'.($settings->site_favicon ?? 'favicon.png')) }}">

{{-- Instrument Sans + Syne self-hosted (assets/fonts, OFL): no third-party request, CSP/SRI clean. --}}
<link rel="preload" href="{{ asset('assets/fonts/instrument-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/fonts/fonts.css') }}">
{{-- Alexandria (Arabic headings) is self-hosted in assets/fonts/fonts.css (OFL, ALEXANDRIA-OFL.txt): the browser fetches it only when RTL text uses it. --}}
@include('partials.riyal-font')
@include('partials.arabic-font')
@include('partials.brand-tile')

<x-ui.shell.legacy-styles :rtl="$__rtl" />

<style nonce="{{ csp_nonce() }}">:root{--theme-color:{{ $__accent }};--theme-color-rgb:{{ $__rgb }};--bs-body-font-family:var(--ui-font);}</style>
<style nonce="{{ csp_nonce() }}">:root{--ui-on-accent:{{ \Juwri\Core\Support\OwnerUi::onAccent($__accent) }};}</style>
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/tokens.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/components.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/shell.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/screens.css') }}">
@if (config('owner_ui.skin'))
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/skin.css') }}">
@endif
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/layout.css') }}">
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/owner-ui.js') }}" defer data-turbo-eval="false"></script>
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/layout.js') }}" defer data-turbo-eval="false"></script>
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/actions.js') }}" defer data-turbo-eval="false"></script>

@if ($__inApp)
    {{-- Native app (Apple 3.1.3): no link or button to a digital purchase inside the app.
         Covers every purchase surface (BlockInAppPurchases::URIS) plus [data-purchase-cta];
         the routes themselves answer 403 in the app as well. --}}
    <style nonce="{{ csp_nonce() }}">
        html[data-owner-app="1"] a[href$="/subscription"], html[data-owner-app="1"] a[href*="/subscription?"],
        html[data-owner-app="1"] a[href$="/pricing"], html[data-owner-app="1"] a[href*="/pricing?"],
        html[data-owner-app="1"] a[href$="/checkout"], html[data-owner-app="1"] a[href*="/checkout?"],
        html[data-owner-app="1"] a[href$="/addons"], html[data-owner-app="1"] a[href*="/addons/"], html[data-owner-app="1"] a[href*="/addons?"],
        html[data-owner-app="1"] a[href*="/wallet/topup"], html[data-owner-app="1"] a[href*="/studio/pricing"],
        html[data-owner-app="1"] [data-purchase-cta], html[data-owner-app="1"] [data-web-only] { display: none !important; }
    </style>
    <link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-app/bridge.css') }}">
@endif

{{-- Installable dashboard (PWA): manifest, push keys, offline page. Signed-in web only —
     the native app's shell owns navigation and push. --}}
@if (auth()->check() && ! $__inApp && Route::has('owner.pwa.manifest'))
    <link rel="manifest" href="{{ route('owner.pwa.manifest') }}" crossorigin="use-credentials">
    <meta name="theme-color" content="{{ \Juwri\Core\Support\OwnerPwa::CHROME_LIGHT }}" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="{{ \Juwri\Core\Support\OwnerPwa::CHROME_DARK }}" media="(prefers-color-scheme: dark)">
    @if (\Juwri\Core\Support\OwnerPwa::surface(request()) === 'pwa')
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    @endif
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ $__appName }}">
    <link rel="apple-touch-icon" href="{{ route('owner.pwa.icon', ['size' => 192]) }}">
    <meta name="owner-pwa"
          data-sw="{{ route('owner.pwa.sw', [], false) }}"
          data-vapid="{{ config('webpush.public_key') }}"
          data-subscribe="{{ route('owner.pwa.push.subscribe', [], false) }}"
          data-unsubscribe="{{ route('owner.pwa.push.unsubscribe', [], false) }}"
          data-store="{{ (int) (dashboard_current_store()?->id ?? 0) }}">
    <link rel="stylesheet" href="{{ asset('assets/owner-ui/pwa.css') }}?v={{ \Juwri\Core\Support\OwnerPwa::assetVersion('pwa.css') }}">
    <script src="{{ asset('assets/owner-ui/pwa.js') }}?v={{ \Juwri\Core\Support\OwnerPwa::assetVersion('pwa.js') }}" defer data-turbo-eval="false"></script>
@endif

@include('partials.analytics')

{{-- BL-052: the CSP nonce is per response and a Turbo Drive swap keeps the PREVIOUS document's policy, so the swapped-in
     inline scripts (menu editor, Alpine components) were refused; Turbo copies the new page's own nonce onto them, so the
     csp-nonce meta cannot help. Every owner page therefore loads as a real document, under its own policy. --}}
<meta name="turbo-visit-control" content="reload">
@if (\Juwri\Core\Pci\PaymentPages::current(request()) !== null)
    {{-- PCI DSS 6.4.3: a payment page runs under its own strict CSP, which a browser applies only on a full page load;
         a link out of it is a normal navigation too, so the next page never inherits the payment policy. --}}
    <script nonce="{{ csp_nonce() }}" data-turbo-eval="false">document.addEventListener('turbo:before-visit', function (e) { e.preventDefault(); window.location.href = e.detail.url; });</script>
@endif

@stack('styles')
@stack('head')
