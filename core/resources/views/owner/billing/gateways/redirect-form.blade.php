{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Gateway hand-off: posts $data to the provider's $url as soon as the page loads. --}}
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ current_language()->direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ ___('Redirecting...') }}</title>
</head>
<body>
    <form method="post" action="{{ $url }}" id="gateway-handoff">
        @foreach ($data as $field => $fieldValue)
            <input type="hidden" name="{{ $field }}" value="{{ $fieldValue }}">
        @endforeach
        <p>{{ ___('Please do not refresh this page...') }}</p>
        <noscript><button type="submit">{{ ___('Continue') }}</button></noscript>
    </form>
    <script nonce="{{ csp_nonce() }}">document.getElementById('gateway-handoff').submit();</script>
</body>
</html>
