{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ get_lang() }}" dir="{{ optional(current_language())->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>{{ $store->title }} — {{ ___('Order here') }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root { color-scheme: dark; }
        * { box-sizing: border-box; margin: 0; -webkit-tap-highlight-color: transparent; }
        html, body { height: 100%; }
        body { background: radial-gradient(120% 120% at 50% 0%, #1e293b, #0b1020); color: #fff; font-family: system-ui, sans-serif; }
        .wrap { min-height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 6vh 6vw; }
        .brand { font-size: 6vw; font-weight: 800; margin-bottom: 2vh; }
        .tap { margin-top: 4vh; padding: 3vh 8vw; font-size: 4vw; font-weight: 700; border: none; border-radius: 999px; background: #22c55e; color: #06210f; cursor: pointer; }
        .exit { position: fixed; bottom: 2vh; right: 2vw; opacity: .35; font-size: 2.6vw; background: none; border: none; color: #fff; }
        .modal { position: fixed; inset: 0; background: rgba(0,0,0,.7); display: none; align-items: center; justify-content: center; }
        .modal.on { display: flex; }
        .card { background: #0f172a; padding: 4vh 6vw; border-radius: 1rem; text-align: center; }
        .card input { font-size: 5vw; letter-spacing: .3em; text-align: center; padding: 1vh; width: 60vw; border-radius: .5rem; border: 1px solid #334155; background: #020617; color: #fff; }
        .card button { margin-top: 2vh; padding: 1.5vh 6vw; font-size: 3vw; border: none; border-radius: .5rem; background: #22c55e; color: #06210f; }
        .err { color: #fca5a5; margin-top: 1vh; min-height: 1.2em; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">{{ $store->title }}</div>
        <div style="font-size:3vw;opacity:.8;">{{ ___('Tap below to start your order') }}</div>
        <button class="tap" id="startBtn">{{ ___('Tap to order') }}</button>
    </div>
    <button class="exit" id="exitBtn">{{ ___('Staff exit') }}</button>

    <div class="modal" id="pinModal">
        <div class="card">
            <div id="pinTitle" style="font-size:3vw;margin-bottom:2vh;">{{ ___('Enter PIN') }}</div>
            <input id="pinInput" inputmode="numeric" type="password" autocomplete="off">
            <div class="err" id="pinErr"></div>
            <button id="pinSubmit">{{ ___('OK') }}</button>
        </div>
    </div>

    <script nonce="{{ csp_nonce() }}">
        const SLUG = @json($store->slug);
        const ORDER_URL = @json($orderUrl);
        const IDLE_MS = {{ $idleSeconds }} * 1000;
        const NEEDS_SETUP = @json($needsSetup);
        const SETUP_KEY = 'kiosk_setup_' + SLUG;

        function post(path, pin) {
            return fetch('/' + SLUG + '/kiosk/' + path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ pin })
            }).then(r => r.json());
        }

        // Device setup gate: an un-setup device must pass the setup PIN first.
        function ensureSetup() {
            if (!NEEDS_SETUP || localStorage.getItem(SETUP_KEY) === '1') return;
            openPin('{{ ___('Device setup PIN') }}', function (pin) {
                return post('setup', pin).then(function (res) {
                    if (res.ok) { localStorage.setItem(SETUP_KEY, '1'); return true; }
                    return false;
                });
            });
        }

        let onPin = null;
        function openPin(title, handler) {
            document.getElementById('pinTitle').textContent = title;
            document.getElementById('pinErr').textContent = '';
            document.getElementById('pinInput').value = '';
            document.getElementById('pinModal').classList.add('on');
            onPin = handler;
        }
        document.getElementById('pinSubmit').addEventListener('click', async function () {
            const pin = document.getElementById('pinInput').value;
            const ok = onPin ? await onPin(pin) : false;
            if (ok) { document.getElementById('pinModal').classList.remove('on'); }
            else { document.getElementById('pinErr').textContent = '{{ ___('Incorrect PIN') }}'; }
        });

        document.getElementById('startBtn').addEventListener('click', function () { window.location.href = ORDER_URL; });
        document.getElementById('exitBtn').addEventListener('click', function () {
            openPin('{{ ___('Staff exit PIN') }}', function (pin) {
                return post('exit', pin).then(function (res) {
                    if (res.ok) { window.location.href = '/' + SLUG; return true; }
                    return false;
                });
            });
        });

        // Idle → return to attract (clears any in-progress order on the storefront).
        let idleTimer;
        function resetIdle() { clearTimeout(idleTimer); idleTimer = setTimeout(function () { window.location.reload(); }, IDLE_MS); }
        ['touchstart', 'click', 'keydown'].forEach(function (ev) { document.addEventListener(ev, resetIdle, { passive: true }); });
        resetIdle();
        ensureSetup();
    </script>
</body>
</html>
