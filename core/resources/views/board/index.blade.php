{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- PARITY-RUN T2-2: the public order-status board.

     A standalone document, not a theme layout: this is opened fullscreen on a
     TV or a wall tablet and must render with no dashboard chrome, no cookies
     and no JS framework. It shows TOKEN NUMBERS ONLY (see OrderBoardService) —
     the payload it polls carries no customer data at all.

     Sizing is viewport-relative (vmin/vw) so the same page reads correctly on a
     55" screen across a room and on a 10" tablet on the counter. --}}
@php
    $isRtl = is_rtl();
    $brand = $store->color ?: '#10b981';
    $logo = $store->main_image && $store->main_image !== 'default.png'
        ? asset('storage/store/logo/' . $store->main_image)
        : null;
@endphp
<!doctype html>
<html lang="{{ get_lang() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $store->title }} — {{ ___('Order board') }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root {
            color-scheme: dark;
            --brand: {{ $brand }};
            --bg: #070b16;
            --panel: rgba(255, 255, 255, .05);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg);
            color: #fff;
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        header {
            display: flex;
            align-items: center;
            gap: 1.4vmin;
            padding: 1.6vmin 2.4vmin;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }
        header img { height: 6vmin; width: 6vmin; object-fit: cover; border-radius: 50%; }
        header .name { font-size: 2.6vmin; font-weight: 700; letter-spacing: .01em; }
        header .branch { font-size: 1.8vmin; opacity: .6; }
        header .clock { margin-inline-start: auto; font-size: 2.2vmin; opacity: .65; font-variant-numeric: tabular-nums; }
        /* The board toggles `main` and `.idle` with the `hidden` ATTRIBUTE, whose
           display:none comes from the UA stylesheet — any author `display` rule
           beats it. Both elements below set one, so without this the board shows
           the idle card underneath live tokens (and empty columns when idle).
           Keep this rule above them. */
        [hidden] { display: none !important; }
        main { flex: 1; display: grid; grid-template-columns: 1fr 1fr; min-height: 0; }
        .col { padding: 2.4vmin; display: flex; flex-direction: column; min-height: 0; }
        .col + .col { border-inline-start: 1px solid rgba(255, 255, 255, .08); }
        .col h2 {
            font-size: 3.4vmin;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 2vmin;
            display: flex;
            align-items: center;
            gap: 1.2vmin;
        }
        .col h2 .dot { width: 1.4vmin; height: 1.4vmin; border-radius: 50%; display: inline-block; }
        .prep h2 { color: #fbbf24; }
        .prep h2 .dot { background: #fbbf24; }
        .ready h2 { color: var(--brand); }
        .ready h2 .dot { background: var(--brand); }
        .ready { background: linear-gradient(180deg, rgba(255, 255, 255, .04), transparent); }
        .tokens { display: flex; flex-wrap: wrap; gap: 1.4vmin; align-content: flex-start; overflow: hidden; }
        .token {
            font-size: 6.5vmin;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            line-height: 1;
            padding: 1.6vmin 2.4vmin;
            border-radius: .18em;
            background: var(--panel);
            min-width: 14vmin;
            text-align: center;
            animation: pop .35s ease-out;
        }
        .ready .token { background: color-mix(in srgb, var(--brand) 22%, transparent); border: 2px solid var(--brand); }
        .ready .token.fresh { animation: flash 1.1s ease-out 3; }
        .empty { font-size: 2.4vmin; opacity: .35; padding: 2vmin 0; }
        .idle { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2.4vmin; text-align: center; }
        .idle img { height: 18vmin; width: 18vmin; object-fit: cover; border-radius: 50%; }
        .idle .title { font-size: 5vmin; font-weight: 800; }
        .idle .sub { font-size: 2.4vmin; opacity: .5; }
        .offline { position: fixed; inset-inline-end: 1.6vmin; bottom: 1.6vmin; font-size: 1.6vmin; opacity: .5; }
        @keyframes pop { from { transform: scale(.82); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes flash { 0%, 100% { filter: none; } 50% { filter: brightness(1.75); } }
        @media (max-width: 700px) {
            main { grid-template-columns: 1fr; grid-template-rows: 1fr 1fr; }
            .col + .col { border-inline-start: 0; border-top: 1px solid rgba(255, 255, 255, .08); }
            .token { font-size: 9vmin; min-width: 22vmin; }
        }
        @media (prefers-reduced-motion: reduce) {
            .token, .ready .token.fresh { animation: none; }
        }
    </style>
</head>
<body>
    <header>
        @if($logo)
            <img src="{{ $logo }}" alt="">
        @endif
        <div>
            <div class="name">{{ $store->title }}</div>
            @if($branch)
                <div class="branch">{{ $branch->name }}</div>
            @endif
        </div>
        <div class="clock" id="boardClock" aria-hidden="true"></div>
    </header>

    <main id="boardMain">
        <section class="col prep">
            <h2><span class="dot"></span>{{ ___('Preparing') }}</h2>
            <div class="tokens" id="preparing" aria-live="polite"
                 aria-label="{{ ___('Preparing') }}"></div>
        </section>
        <section class="col ready">
            <h2><span class="dot"></span>{{ ___('Ready') }}</h2>
            <div class="tokens" id="ready" aria-live="polite"
                 aria-label="{{ ___('Ready') }}"></div>
        </section>
    </main>

    <div class="idle" id="boardIdle" hidden>
        @if($logo)
            <img src="{{ $logo }}" alt="">
        @endif
        <div class="title">{{ $store->title }}</div>
        <div class="sub">{{ ___('No orders on the board right now') }}</div>
    </div>

    <div class="offline" id="boardOffline" hidden>{{ ___('Reconnecting…') }}</div>

    <script nonce="{{ csp_nonce() }}">
        (function () {
            "use strict";

            var STATE_URL = @json(route('board.state', $store->slug) . ($branch ? '?branch=' . $branch->id : ''));
            var EMPTY_LABEL = @json(___('Nothing yet'));
            var config = @json($data['config']);
            var lastReady = [];
            var audioCtx = null;

            var els = {
                preparing: document.getElementById('preparing'),
                ready: document.getElementById('ready'),
                main: document.getElementById('boardMain'),
                idle: document.getElementById('boardIdle'),
                offline: document.getElementById('boardOffline'),
                clock: document.getElementById('boardClock')
            };

            function paint(el, list, freshSet) {
                el.textContent = '';
                if (!list.length) {
                    var none = document.createElement('div');
                    none.className = 'empty';
                    none.textContent = EMPTY_LABEL;
                    el.appendChild(none);
                    return;
                }
                list.forEach(function (token) {
                    var card = document.createElement('div');
                    card.className = 'token' + (freshSet && freshSet.indexOf(token) !== -1 ? ' fresh' : '');
                    card.textContent = token;
                    el.appendChild(card);
                });
            }

            /* Chime: a short two-tone beep synthesised in the page. A TV browser
               will not autoplay it until someone has touched the screen once —
               that is a browser rule, not a bug, so the board never depends on it. */
            function chime() {
                if (!config.chime) { return; }
                try {
                    var Ctx = window.AudioContext || window.webkitAudioContext;
                    if (!Ctx) { return; }
                    audioCtx = audioCtx || new Ctx();
                    [880, 1320].forEach(function (freq, i) {
                        var osc = audioCtx.createOscillator();
                        var gain = audioCtx.createGain();
                        osc.type = 'sine';
                        osc.frequency.value = freq;
                        gain.gain.setValueAtTime(0.0001, audioCtx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.22, audioCtx.currentTime + 0.02 + i * 0.18);
                        gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.32 + i * 0.18);
                        osc.connect(gain).connect(audioCtx.destination);
                        osc.start(audioCtx.currentTime + i * 0.18);
                        osc.stop(audioCtx.currentTime + 0.4 + i * 0.18);
                    });
                } catch (e) {}
            }

            function render(data, announce) {
                var preparing = data.preparing || [];
                var ready = data.ready || [];
                if (data.config) { config = data.config; }

                var fresh = ready.filter(function (t) { return lastReady.indexOf(t) === -1; });
                paint(els.preparing, preparing, fresh);
                paint(els.ready, ready, fresh);

                if (announce && fresh.length) { chime(); }
                lastReady = ready;

                var idle = !preparing.length && !ready.length;
                els.idle.hidden = !idle;
                els.main.hidden = idle;
            }

            function tick() {
                fetch(STATE_URL, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                    .then(function (r) {
                        if (!r.ok) { throw new Error('bad status'); }
                        return r.json();
                    })
                    .then(function (data) {
                        els.offline.hidden = true;
                        render(data, true);
                    })
                    .catch(function () { els.offline.hidden = false; });
            }

            function clock() {
                if (!els.clock) { return; }
                var now = new Date();
                els.clock.textContent = ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
            }

            render(@json(['preparing' => $data['preparing'], 'ready' => $data['ready'], 'config' => $data['config']]), false);
            clock();
            setInterval(clock, 20000);
            setInterval(tick, Math.max(2, config.poll_seconds || 5) * 1000);
        })();
    </script>
</body>
</html>
