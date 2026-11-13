{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Upstream c623b24e3 (reseller-site 1.5): error pages on a reseller's domain carry the reseller's
     brand, never the platform's. Self-contained (no layout partials) so it renders
     even when the error came from those partials. --}}
@php
    $primary = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $reseller->primary_color) ? $reseller->primary_color : '#6366f1';
    $isRtl = \Juwri\Core\Support\ErrorPage::isRtl(app()->getLocale());
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, follow">
    <title>{{ $titleC }} - {{ $reseller->brand_name }}</title>
    @if($reseller->favicon_url ?: $reseller->brand_logo)<link rel="icon" href="{{ $reseller->favicon_url ?: $reseller->brand_logo }}">@endif
    <style nonce="{{ csp_nonce() }}">
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:#fff;color:#1f2937}
        @media (prefers-color-scheme: dark){body{background:#121212;color:#e5e7eb}.err-desc{color:#9ca3af}}
        .err{max-width:480px;text-align:center}
        .err-logo{max-height:48px;margin-bottom:24px}
        .err-brand{font-weight:700;font-size:1.1rem;margin-bottom:24px}
        .err-code{font-size:4.5rem;font-weight:800;line-height:1;margin:0 0 8px;color:{{ $primary }}}
        .err-msg{font-size:1.25rem;margin:0 0 8px}
        .err-desc{color:#6b7280;margin:0 0 24px}
        .err-btn{display:inline-block;padding:10px 20px;border-radius:8px;background:{{ $primary }};color:#fff;text-decoration:none;font-weight:600}
    </style>
</head>
<body data-reseller-error="{{ $reseller->id }}">
    <div class="err">
        @if($reseller->brand_logo)
            <img class="err-logo" src="{{ $reseller->brand_logo }}" alt="{{ $reseller->brand_name }}">
        @else
            <div class="err-brand">{{ $reseller->brand_name }}</div>
        @endif
        <p class="err-code">{{ $code }}</p>
        <h1 class="err-msg">{{ $msg }}</h1>
        @if($descContent)<p class="err-desc">{{ $descContent }}</p>@endif
        <a class="err-btn" href="{{ url('/') }}">{{ ___('Back to home') }}</a>
    </div>
</body>
</html>
