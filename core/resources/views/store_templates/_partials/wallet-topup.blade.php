{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $accent = $store->color ?: '#6b4ea8';
    $sym = cur_isolate($store->currency_sign ?? config('settings.currency_sign', '$'));
    $dir = current_language()->direction ?? 'ltr';
    $bal = number_format((float) $balance, 2);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ___('Add funds') }} · {{ $store->title }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root{--acc:{{ $accent }};--ink:#1a1c22;--muted:#71727a;--line:#e7e7ee;--card:#fff;--bg:#f5f5f8}
        @media (prefers-color-scheme:dark){:root{--ink:#f2f2f5;--muted:#a0a1aa;--line:#2a2b33;--card:#1c1d24;--bg:#141419}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
            min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:20px}
        .wt{width:100%;max-width:460px}
        .wt-back{display:inline-flex;align-items:center;gap:6px;color:var(--muted);text-decoration:none;font-size:14px;margin-bottom:14px}
        .wt-card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:22px}
        .wt-bal{background:linear-gradient(135deg,var(--acc),color-mix(in srgb,var(--acc) 60%,#000));color:#fff;
            border-radius:16px;padding:18px 20px;margin-bottom:20px}
        .wt-bal small{opacity:.85;font-size:12.5px;text-transform:uppercase;letter-spacing:.05em}
        .wt-bal b{display:block;font-size:1.9rem;font-weight:800;margin-top:4px}
        h1{font-size:1.15rem;margin:0 0 14px}
        label{display:block;font-weight:600;font-size:13px;margin:0 0 8px}
        .wt-amts{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}
        .wt-amt{border:1px solid var(--line);background:transparent;color:var(--ink);border-radius:12px;padding:12px 4px;
            font:inherit;font-weight:700;cursor:pointer}
        .wt-amt.is-on{border-color:var(--acc);background:color-mix(in srgb,var(--acc) 12%,transparent);color:var(--acc)}
        .wt-in{width:100%;height:50px;border:1px solid var(--line);border-radius:12px;background:transparent;color:var(--ink);
            padding:0 14px;font:inherit;font-size:16px;outline:none;margin-bottom:18px}
        .wt-in:focus{border-color:var(--acc)}
        .wt-gw{display:flex;flex-direction:column;gap:8px;margin-bottom:18px}
        .wt-gw label{display:flex;align-items:center;gap:10px;border:1px solid var(--line);border-radius:12px;
            padding:12px 14px;font-weight:600;cursor:pointer;margin:0}
        .wt-gw input{accent-color:var(--acc);width:18px;height:18px}
        .wt-btn{width:100%;height:52px;border:0;border-radius:100px;background:var(--acc);color:#fff;font:inherit;
            font-size:15px;font-weight:700;cursor:pointer}
        .wt-btn:disabled{opacity:.55;cursor:default}
        .wt-err{background:rgba(220,38,38,.1);color:#dc2626;border-radius:10px;padding:9px 12px;font-size:13.5px;
            font-weight:600;margin-bottom:14px}
        .wt-empty{color:var(--muted);font-size:14px;text-align:center;padding:14px 0}
        .wt-note{color:var(--muted);font-size:12px;text-align:center;margin-top:14px}
    </style>
</head>
<body>
<div class="wt">
    <a class="wt-back" href="{{ url()->previous() !== url()->current() ? url()->previous() : route('publicView', $store->slug) }}">&larr; {{ ___('Back') }}</a>
    <div class="wt-card">
        <div class="wt-bal">
            <small>{{ ___('Wallet balance') }}</small>
            <b>{{ $sym }}{{ $bal }}</b>
        </div>
        <h1>{{ ___('Add funds') }}</h1>

        @if($errors->any())
            <div class="wt-err">{{ $errors->first() }}</div>
        @endif

        @if($gateways->isEmpty())
            <p class="wt-empty">{{ ___('No payment method is available right now. Please try again later.') }}</p>
        @else
        <form method="POST" action="{{ route('publicView.wallet.topup.start', $store->id) }}" id="wtForm">
            @csrf
            <label>{{ ___('Amount') }} ({{ $sym }})</label>
            <div class="wt-amts">
                @foreach([10, 25, 50, 100] as $preset)
                    <button type="button" class="wt-amt" data-amt="{{ $preset }}">{{ $sym }}{{ $preset }}</button>
                @endforeach
            </div>
            <input class="wt-in" type="number" name="amount" id="wtAmount" min="1" max="100000" step="{{ \Juwri\Core\Money\AmountInput::step(store_options($store->id, 'currency_code') ?: null) }}"
                   inputmode="decimal" placeholder="{{ ___('Enter amount') }}" required>

            <label>{{ ___('Payment method') }}</label>
            <div class="wt-gw">
                @foreach($gateways as $i => $gw)
                    <label>
                        <input type="radio" name="payment_method" value="{{ $gw->id }}" @checked($i === 0)>
                        <span>{{ $gw->payment_title ?? ucfirst(str_replace('_', ' ', $gw->payment_folder)) }}</span>
                    </label>
                @endforeach
            </div>

            <button type="submit" class="wt-btn" id="wtSubmit">{{ ___('Continue to payment') }}</button>
        </form>
        <p class="wt-note">{{ ___('You will be redirected to your payment provider to complete the top-up securely.') }}</p>
        @endif
    </div>
</div>
<script nonce="{{ csp_nonce() }}">
    (function () {
        var amt = document.getElementById('wtAmount');
        document.querySelectorAll('.wt-amt').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('.wt-amt').forEach(function (x) { x.classList.remove('is-on'); });
                b.classList.add('is-on');
                if (amt) { amt.value = b.getAttribute('data-amt'); }
            });
        });
        if (amt) {
            amt.addEventListener('input', function () {
                document.querySelectorAll('.wt-amt').forEach(function (x) {
                    x.classList.toggle('is-on', x.getAttribute('data-amt') === amt.value);
                });
            });
        }
        var form = document.getElementById('wtForm');
        if (form) {
            form.addEventListener('submit', function () {
                var b = document.getElementById('wtSubmit');
                if (b) { b.disabled = true; b.textContent = @json(___('Redirecting…')); }
            });
        }
    })();
</script>
</body>
</html>
