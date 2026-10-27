{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- External Redirect interstitial (rev/external-redirect A6). Standalone page
     returned by ExternalRedirect::resolve() when the owner chose the "menu moved"
     variant over an instant 302. CSP-CRITICAL: no inline <script>/<style> — all
     styling is assets/global/css/external-redirect.css; the meta-refresh drives
     the auto-redirect (no JS). $target is a validated https URL, escaped by Blade
     in every attribute. dir/lang make it correct in RTL locales. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="2;url={{ $target }}">
    <title>{{ ___('This menu has moved') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/global/css/external-redirect.css?v=1') }}">
</head>
<body>
    <main class="er-card">
        @if (! empty($store->main_image) && $store->main_image !== 'default.png')
            <img class="er-logo" src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="{{ $store->title }}">
        @endif
        <p class="er-name">{{ $store->title }}</p>
        <h1 class="er-title">{{ ___('This menu has moved') }}</h1>
        <p class="er-text">{{ ___('We are taking you to the new page.') }}</p>
        <a class="er-btn" href="{{ $target }}" rel="noopener nofollow">{{ ___('Continue now') }}</a>
        <p class="er-hint">{{ ___('If you are not redirected automatically, tap the button above.') }}</p>
    </main>
</body>
</html>
