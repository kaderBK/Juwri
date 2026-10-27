{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ get_lang() }}" dir="{{ optional(current_language())->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ___('Order status') }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root { color-scheme: dark; }
        * { box-sizing: border-box; margin: 0; }
        body { background:#0b1020; color:#fff; font-family: system-ui, sans-serif; height:100vh; overflow:hidden; }
        .grid { display:grid; grid-template-columns:1fr 1fr; height:100vh; }
        .col { padding: 3vh 3vw; }
        .col h2 { font-size: 3.4vw; margin-bottom: 3vh; }
        .col.prep { background:#0b1020; }
        .col.ready { background:#052e2b; }
        .col.prep h2 { color:#fbbf24; }
        .col.ready h2 { color:#34d399; }
        .card { font-size: 3vw; padding: 1.4vh 2vw; margin-bottom: 1.6vh; border-radius: .4em; background: rgba(255,255,255,.06); display:flex; gap:1.5vw; }
        .card .num { font-weight:700; }
        .empty { font-size:2.4vw; opacity:.5; }
    </style>
</head>
<body>
    <div class="grid">
        <div class="col prep"><h2>{{ ___('Preparing') }}</h2><div id="preparing"></div></div>
        <div class="col ready"><h2>{{ ___('Ready') }}</h2><div id="ready"></div></div>
    </div>

    <script nonce="{{ csp_nonce() }}">
        const TOKEN = @json($screen->token);

        function paint(id, list) {
            const el = document.getElementById(id);
            if (!list.length) { el.innerHTML = '<div class="empty">—</div>'; return; }
            el.innerHTML = '';
            list.forEach(function (o) {
                const card = document.createElement('div');
                card.className = 'card';
                const num = document.createElement('span'); num.className = 'num'; num.textContent = '#' + o.number;
                const nm = document.createElement('span'); nm.textContent = o.name || '';
                card.appendChild(num); card.appendChild(nm); el.appendChild(card);
            });
        }
        async function refresh() {
            try {
                const r = await fetch('/signage/' + TOKEN + '/state', { headers: { 'Accept': 'application/json' } });
                if (!r.ok) { document.body.innerHTML = '<div class="empty" style="padding:5vh">' + @json(___('Screen unavailable')) + '</div>'; return; }
                const d = await r.json();
                paint('preparing', d.preparing || []);
                paint('ready', d.ready || []);
            } catch (e) {}
        }
        paint('preparing', @json($data['preparing']));
        paint('ready', @json($data['ready']));
        setInterval(refresh, 10000);
    </script>
</body>
</html>
