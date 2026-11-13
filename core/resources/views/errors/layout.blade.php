{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Error pages (403/404/419/429/500/503) on the owner-ui kit (WI-16e).

  Self-contained on purpose: an error page must render even when the app is half
  broken, so it needs no shell, no navigation data and no database beyond the
  (optional) shared $settings. Crawlers are told not to index it, and it emits
  no canonical / hreflang (ErrorPageSeoNoindexTest).
  Sections: code, message, title, description, extra_actions.
--}}
@php
    $code = trim($__env->yieldContent('code', '500'));
    $message = trim($__env->yieldContent('message', 'Error'));
    $description = trim($__env->yieldContent('description', ''));
    $pageTitle = trim($__env->yieldContent('title', $message));
    // P6-112: direction from the fixed RTL list, not the languages table (no database lookup for the page chrome).
    $dir = \Juwri\Core\Support\ErrorPage::isRtl(app()->getLocale()) ? 'rtl' : 'ltr';
    $accent = $settings->theme_color ?? null;
    $accent = (is_string($accent) && preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) ? $accent : '#6366f1';
    $siteName = $settings->site_title ?? config('app.name');
    // Upstream c623b24e3 (reseller-site 1.5): a reseller's domain shows the reseller's brand.
    try { $errReseller = app(\Juwri\Core\Contracts\Tenancy\ResellerContext::class)->forRequest(); } catch (\Throwable $e) { $errReseller = null; }
@endphp
@if ($errReseller)
@include('errors.reseller', ['reseller' => $errReseller, 'titleC' => $pageTitle, 'msg' => $message, 'descContent' => $description])
@else
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, follow">
    <title>{{ $pageTitle }} - {{ $siteName }}</title>
    @if (! empty($settings->site_favicon))
        <link rel="icon" href="{{ asset('storage/'.$settings->site_favicon) }}">
    @endif
    <x-ui.shell.theme-paint />
    <style nonce="{{ csp_nonce() }}">:root{--theme-color:{{ $accent }};}</style>
    <link rel="stylesheet" href="{{ asset('assets/owner-ui/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/owner-ui/components.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/owner-ui/screens.css') }}">
</head>
<body class="ui-scope ui-error">
    <main class="ui-error__box">
        <p class="ui-overline">{{ ___('Error') }}</p>
        <h1 class="ui-error__code ui-num">{{ $code }}</h1>
        <h2 class="ui-error__title">{{ $message }}</h2>
        @if ($description)
            <p class="ui-error__text">{{ $description }}</p>
        @endif
        <div class="ui-actions ui-actions--center">
            <x-ui.button :href="url('/')" icon="back">{{ ___('Back to home') }}</x-ui.button>
            @hasSection('extra_actions')
                @yield('extra_actions')
            @endif
        </div>
    </main>
</body>
</html>
@endif
