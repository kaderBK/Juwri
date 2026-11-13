{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ isset($confirmUrl) ? ___('Unsubscribe from marketing messages?') : ___('Unsubscribed Successfully') }}</title>
    <style nonce="{{ csp_nonce() }}">
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #0d0d15;
            color: #f5f5fa;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 40px;
            max-width: 440px;
            width: 100%;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        .icon {
            font-size: 48px;
            color: #d97706;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 12px;
            font-weight: 700;
        }
        p {
            color: #9a9aae;
            font-size: 15px;
            line-height: 1.5;
            margin-bottom: 24px;
        }
        button {
            background: #d97706; color: #fff; border: 0; border-radius: 10px;
            padding: 12px 22px; font-size: 15px; font-weight: 600; cursor: pointer;
        }
        .restaurant {
            color: #f5b54a;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="card">
        @isset($confirmUrl)
            {{-- P6-222: GET only asks; the POST withdraws consent (mail scanners open links). --}}
            <h1>{{ ___('Unsubscribe from marketing messages?') }}</h1>
            <p>
                {{ ___('You will stop receiving promotional emails and text messages from') }}
                <span class="restaurant">{{ $restaurantName }}</span>.
            </p>
            <form method="POST" action="{{ $confirmUrl }}">
                <button type="submit">{{ ___('Yes, unsubscribe me') }}</button>
            </form>
        @else
            <div class="icon">✓</div>
            <h1>{{ ___('Unsubscribed') }}</h1>
            <p>
                {{ ___('You have been successfully unsubscribed from marketing messages sent by') }}
                <span class="restaurant">{{ $restaurantName }}</span>.
                {{ ___('You will no longer receive any promotional emails or text messages from us.') }}
            </p>
        @endisset
    </div>
</body>
</html>
