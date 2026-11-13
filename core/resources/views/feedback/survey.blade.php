{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ___('How was your order?') }}</title>
    <style nonce="{{ csp_nonce() }}">
        body { font-family: system-ui, sans-serif; max-width:420px; margin:32px auto; padding:0 16px; color:#222; }
        .stars button { font-size:2rem; background:none; border:0; cursor:pointer; color:#ccc; }
        .stars button.on { color:#f5b301; }
        textarea, button.submit { width:100%; }
        button.submit { padding:12px; border:0; border-radius:10px; background:#3b6ef5; color:#fff; font-weight:600; margin-top:12px; }
        textarea { border:1px solid #ddd; border-radius:10px; padding:10px; margin-top:12px; }
    </style>
    <script src="{{ asset('assets/js/csp-actions.js') }}?v={{ filemtime(public_path('assets/js/csp-actions.js')) }}"></script>
</head>
<body>
    <h2>{{ ___('How was your order?') }}</h2>
    <form method="post" action="{{ route('feedback.submit', $feedback->token) }}">
        @csrf
        <div class="stars" id="stars">
            @for($i=1;$i<=5;$i++)<button type="button" data-v="{{ $i }}" data-act="pick" data-args='[{{ $i }}]'>★</button>@endfor
        </div>
        <input type="hidden" name="rating" id="rating" required>
        <textarea name="comment" rows="4" placeholder="{{ ___('Tell us more (optional)') }}"></textarea>
        <button class="submit" type="submit">{{ ___('Send feedback') }}</button>
    </form>
    <script nonce="{{ csp_nonce() }}">
        function pick(v){ document.getElementById('rating').value=v; document.querySelectorAll('#stars button').forEach(b=>b.classList.toggle('on', b.dataset.v<=v)); }
    </script>
</body>
</html>
