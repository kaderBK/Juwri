{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}
{{-- SEC-1120: status-only order page behind the guest lookup's Track link (signed URL, no track token).
     Shows only what the lookup already returned: order number, status, placed time. --}}
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ optional(current_language())->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ ___('Order status') }} · {{ $store->title }}</title>
    <style nonce="{{ csp_nonce() }}">
        body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f6f4;color:#1b1b1b}
        .os{max-width:28rem;margin:3rem auto;padding:1.5rem;background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
        .os h1{font-size:1.1rem;margin:0 0 .25rem}
        .os__store{color:#666;margin:0 0 1.25rem}
        .os__status{font-size:1.6rem;font-weight:700;margin:.25rem 0 1rem}
        .os dl{margin:0 0 1rem}.os dt{color:#666;font-size:.85rem}.os dd{margin:0 0 .5rem}
        .os__note{color:#666;font-size:.9rem;margin:1rem 0}
        .os a{color:inherit}
        @media (prefers-color-scheme:dark){body{background:#141414;color:#eee}.os{background:#1f1f1f}.os__store,.os dt,.os__note{color:#aaa}}
    </style>
</head>
<body>
<main class="os">
    <h1>{{ ___('Order #:id', ['id' => $orderId]) }}</h1>
    <p class="os__store">{{ $store->title }}</p>
    <dl>
        <dt>{{ ___('Order status') }}</dt>
        <dd class="os__status" data-order-status="{{ $status }}">{{ ___(ucfirst(str_replace('_', ' ', $status))) }}</dd>
        @if ($placed)
            <dt>{{ ___('Placed') }}</dt>
            <dd class="ui-ltr">{{ $placed }}</dd>
        @endif
    </dl>
    <p class="os__note">{{ ___('To see the items and the full tracking page, sign in or verify your phone.') }}</p>
    <p><a href="{{ request()->fullUrl() }}">{{ ___('Refresh') }}</a></p>
</main>
</body>
</html>
