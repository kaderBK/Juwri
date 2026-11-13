{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ (function_exists('is_rtl') && is_rtl()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ ___('Catering deposit') }} — {{ $store->title }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root { --brand: {{ $store->color ?: '#111827' }}; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f5f7; color: #1f2937; padding: 24px;
        }
        .card { background: #fff; width: 100%; max-width: 420px; border-radius: 16px; padding: 28px;
            box-shadow: 0 10px 40px rgba(0,0,0,.08); }
        .brand { font-size: 13px; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; margin: 0 0 4px; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        .amount { font-size: 34px; font-weight: 700; color: var(--brand); margin: 8px 0 4px; }
        .muted { color: #6b7280; font-size: 14px; }
        .details { background: #f9fafb; border-radius: 10px; padding: 12px 14px; margin: 18px 0; font-size: 14px; }
        .details div { display: flex; justify-content: space-between; padding: 3px 0; }
        label.gw { display: flex; align-items: center; gap: 10px; border: 1px solid #e5e7eb; border-radius: 10px;
            padding: 12px 14px; margin: 8px 0; cursor: pointer; font-size: 15px; }
        label.gw:hover { border-color: var(--brand); }
        button { width: 100%; border: 0; border-radius: 10px; background: var(--brand); color: #fff;
            font-size: 16px; font-weight: 600; padding: 14px; margin-top: 14px; cursor: pointer; }
        .err { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: 10px 12px; font-size: 14px; margin-bottom: 12px; }
        .empty { color: #b91c1c; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <p class="brand">{{ $store->title }}</p>
        <h1>{{ ___('Confirm your catering event') }}</h1>
        <p class="muted">{{ ___('A deposit secures your catering booking.') }}</p>

        <div class="amount">{{ $currency }} {{ number_format((float) $amount, (int) (function_exists('currency_decimals') ? currency_decimals() : 2), '.', ',') }}</div>
        <p class="muted">{{ ___('Applied to your final catering bill per the restaurant’s policy.') }}</p>

        <div class="details">
            <div><span class="muted">{{ ___('Name') }}</span><span>{{ $req->name }}</span></div>
            <div><span class="muted">{{ ___('Headcount') }}</span><span>{{ (int) $req->headcount }}</span></div>
            <div><span class="muted">{{ ___('Event') }}</span><span>{{ \Carbon\Carbon::parse($req->event_at)->format('M j, Y g:i A') }}</span></div>
        </div>

        @if (isset($errors) && $errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        @if ($gateways->isEmpty())
            <p class="empty">{{ ___('No online payment method is available right now. Please contact the restaurant.') }}</p>
        @else
            <form method="POST" action="{{ route('publicView.catering.deposit.charge', ['slug' => $store->slug, 'token' => $req->token]) }}">
                @csrf
                @foreach ($gateways as $i => $gw)
                    <label class="gw">
                        <input type="radio" name="payment_method" value="{{ $gw->id }}" @if ($i === 0) checked @endif>
                        <span>{{ $gw->payment_title ?: $gw->payment_folder }}</span>
                    </label>
                @endforeach
                <button type="submit">{{ ___('Pay deposit') }}</button>
            </form>
        @endif
    </div>
</body>
</html>
