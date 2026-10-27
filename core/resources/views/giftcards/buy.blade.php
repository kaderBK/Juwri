{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if(in_array(app()->getLocale(), ['ar','he','fa','ur'])) dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ___('Gift card') }} · {{ $store->title }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root { --brand: {{ $store->color ?: '#111' }}; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; background: #f5f5f7; color: #1d1d1f; }
        .wrap { max-width: 480px; margin: 0 auto; padding: 32px 20px; }
        .card { background: #fff; border-radius: 18px; padding: 28px; box-shadow: 0 8px 30px rgba(0,0,0,.06); }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .sub { color: #6e6e73; margin: 0 0 24px; font-size: 14px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; }
        input, select, textarea { width: 100%; padding: 12px 14px; border: 1px solid #d2d2d7; border-radius: 12px; font-size: 15px; }
        .presets { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
        .presets button { flex: 1; min-width: 72px; padding: 12px; border: 1px solid #d2d2d7; background: #fff; border-radius: 12px; cursor: pointer; font-weight: 600; }
        .presets button.active { border-color: var(--brand); color: var(--brand); box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand) 20%, transparent); }
        .cta { width: 100%; margin-top: 24px; padding: 15px; background: var(--brand); color: #fff; border: none; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; }
        .err { background: #fee; color: #b00020; padding: 10px 14px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ ___('Send a gift card') }}</h1>
        <p class="sub">{{ $store->title }}</p>

        @if($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('store.giftcards.purchase', $store->id) }}">
            @csrf

            <label>{{ ___('Amount') }} ({{ $currency }})</label>
            <div class="presets" id="gc-presets">
                @foreach($presets as $p)
                    <button type="button" data-amount="{{ (float) $p }}">{{ (int) $p }}</button>
                @endforeach
            </div>
            <input type="number" id="gc-amount" name="amount" value="{{ (float) ($presets[0] ?? $min) }}" min="{{ $min }}" max="{{ $max }}" step="0.01" style="margin-top:8px" required>

            <label>{{ ___('Recipient email') }}</label>
            <input type="email" name="recipient_email" placeholder="name@example.com">

            <label>{{ ___('Recipient phone') }} ({{ ___('optional') }})</label>
            <input type="tel" name="recipient_phone">

            <label>{{ ___('Your email') }}</label>
            <input type="email" name="purchaser_email" placeholder="you@example.com">

            <label>{{ ___('Message') }} ({{ ___('optional') }})</label>
            <textarea name="message" rows="2" maxlength="500"></textarea>

            <label>{{ ___('Send on') }} ({{ ___('optional') }})</label>
            <input type="datetime-local" name="scheduled_send_at" min="{{ now()->format('Y-m-d\TH:i') }}">

            <label>{{ ___('Payment method') }}</label>
            <select name="payment_method" required>
                @foreach($gateways as $g)
                    <option value="{{ $g->id }}">{{ $g->payment_title ?: $g->payment_folder }}</option>
                @endforeach
            </select>

            <button type="submit" class="cta">{{ ___('Continue to payment') }}</button>
        </form>
    </div>
</div>
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
    (function () {
        var input = document.getElementById('gc-amount');
        var mark = function () {
            document.querySelectorAll('#gc-presets button').forEach(function (b) {
                b.classList.toggle('active', parseFloat(b.dataset.amount) === parseFloat(input.value));
            });
        };
        document.querySelectorAll('#gc-presets button').forEach(function (b) {
            b.addEventListener('click', function () { input.value = b.dataset.amount; mark(); });
        });
        mark();
    })();
</script>
</body>
</html>
