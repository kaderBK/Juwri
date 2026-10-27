{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ get_lang() }}" dir="{{ optional(current_language())->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $data['store'] ?? ___('Menu') }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root { color-scheme: dark; }
        * { box-sizing: border-box; margin: 0; }
        body { background:#0b1020; color:#fff; font-family: system-ui, sans-serif; height:100vh; overflow:hidden; }
        header { padding: 3vh 4vw 1vh; }
        header h1 { font-size: 4vw; letter-spacing:.02em; }
        .stage { padding: 1vh 4vw; }
        .cat { display:none; }
        .cat.active { display:block; animation: fade .6s ease; }
        .cat h2 { font-size: 3.2vw; color:#7dd3fc; margin-bottom: 2vh; border-bottom:2px solid #1e293b; padding-bottom:1vh; }
        .row { display:flex; justify-content:space-between; font-size: 2.6vw; padding: 1.1vh 0; }
        .row .p { color:#a7f3d0; font-variant-numeric: tabular-nums; }
        @keyframes fade { from { opacity:0 } to { opacity:1 } }
        .empty { font-size:2.5vw; opacity:.6; padding-top:6vh; }
    </style>
</head>
<body>
    <header><h1>{{ $data['store'] ?? '' }}</h1></header>
    <div class="stage" id="stage"></div>

    <script nonce="{{ csp_nonce() }}">
        const TOKEN = @json($screen->token);
        let cats = @json($data['categories']);
        let idx = 0;

        function render() {
            const stage = document.getElementById('stage');
            if (!cats.length) { stage.innerHTML = '<div class="empty">' + @json(___('Menu coming soon')) + '</div>'; return; }
            idx = idx % cats.length;
            const c = cats[idx];
            stage.innerHTML = '<div class="cat active"><h2></h2><div class="items"></div></div>';
            stage.querySelector('h2').textContent = c.name;
            const wrap = stage.querySelector('.items');
            c.items.forEach(function (it) {
                const row = document.createElement('div');
                row.className = 'row';
                const n = document.createElement('span'); n.textContent = it.name;
                const p = document.createElement('span'); p.className = 'p'; p.textContent = Number(it.price).toFixed(2);
                row.appendChild(n); row.appendChild(p); wrap.appendChild(row);
            });
        }
        function rotate() { idx++; render(); }
        async function refresh() {
            try {
                const r = await fetch('/signage/' + TOKEN + '/state', { headers: { 'Accept': 'application/json' } });
                if (r.ok) { const d = await r.json(); cats = d.categories || []; }
            } catch (e) {}
        }
        render();
        setInterval(rotate, 8000);
        setInterval(refresh, 60000);
    </script>
</body>
</html>
