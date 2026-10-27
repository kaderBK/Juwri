{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
{{-- P3-10: plain booking pages (the `book` kit theme replaces the look in P3-11). No inline scripts or handlers;
     the one style block carries the CSP nonce. Logical properties only (RTL). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title') — {{ $store->title }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root { color-scheme: light dark; --ink: #1f2937; --muted: #4b5563; --line: #d1d5db; --bg: #f6f7f9; --card: #fff; --brand: #1d4ed8; --on-brand: #fff; --warn: #92400e; --ok: #166534; --bad: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --ink: #f3f4f6; --muted: #cbd5e1; --line: #475569; --bg: #0f172a; --card: #1e293b; --brand: #93c5fd; --on-brand: #0f172a; --warn: #fcd34d; --ok: #86efac; --bad: #fca5a5; } }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, "IBM Plex Sans Arabic", sans-serif; background: var(--bg); color: var(--ink); line-height: 1.5; }
        main { max-inline-size: 640px; margin-inline: auto; padding: 16px; }
        h1 { font-size: 1.5rem; margin-block: 8px; } h2 { font-size: 1.15rem; margin-block: 20px 8px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; margin-block: 10px; }
        .muted { color: var(--muted); } .small { font-size: .9rem; }
        .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .btn, button { display: inline-flex; align-items: center; justify-content: center; min-block-size: 44px; padding-inline: 18px; border-radius: 10px; border: 2px solid var(--brand); background: var(--brand); color: var(--on-brand); font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .btn-outline { background: transparent; color: var(--brand); }
        .btn-danger { background: transparent; color: var(--bad); border-color: var(--bad); }
        .slot { min-inline-size: 84px; background: transparent; color: var(--ink); border: 1px solid var(--line); }
        .slot[aria-disabled="true"], .day[aria-disabled="true"] { text-decoration: line-through; color: var(--muted); cursor: not-allowed; }
        .day { display: inline-flex; flex-direction: column; align-items: center; min-inline-size: 72px; min-block-size: 44px; padding: 6px 8px; border: 1px solid var(--line); border-radius: 10px; color: var(--ink); text-decoration: none; }
        .day[aria-current="date"] { background: var(--brand); color: var(--on-brand); border-color: var(--brand); }
        label { display: block; font-weight: 600; margin-block: 10px 4px; }
        label.check { display: flex; gap: 8px; font-weight: 400; align-items: center; min-block-size: 44px; }
        input[type=text], input[type=tel], input[type=email], input[type=number], textarea, select { inline-size: 100%; min-block-size: 44px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--card); color: var(--ink); font: inherit; }
        .alert { border-radius: 10px; padding: 10px 12px; margin-block: 10px; border: 1px solid currentColor; }
        .alert-bad { color: var(--bad); } .alert-ok { color: var(--ok); } .warn { color: var(--warn); }
        .ticket { background: var(--brand); color: var(--on-brand); border-radius: 14px; padding: 16px; }
        .ticket .big { font-size: 1.6rem; font-weight: 800; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; margin: 0; } dt { color: var(--muted); } dd { margin: 0; }
        :focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; }
        .sr { position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    </style>
</head>
<body>
<main>
    <p class="muted small"><a href="{{ route('publicView', $store->slug) }}">{{ $store->title }}</a></p>
    @if(session('success'))<div class="alert alert-ok" role="status">{{ session('success') }}</div>@endif
    @if(isset($errors) && $errors->any())<div class="alert alert-bad" role="alert">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
</body>
</html>
