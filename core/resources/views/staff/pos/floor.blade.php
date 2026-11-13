{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    // MC-22: inject the store's currency decimals so the floor money() renders
    // 3-decimal (Gulf) currencies with their fils, not 2dp.
    $posDecimals = currency_decimals(store_options($store->id, 'currency_code') ?: null);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ___('Floor') }} — {{ $store->title }}</title>
    {{-- Light is the default (matches the register); honour the shared pos-theme pref. --}}
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var t = null;
            try { t = localStorage.getItem('pos-theme'); } catch (e) {}
            document.documentElement.dataset.posTheme = (t === 'dark') ? 'dark' : 'light';
        })();
    </script>
    {{-- Self-hosted Alpine (same build as the register; no third-party script host, CSP 'self' + nonce). --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset('assets/pos/alpine.min.js') }}?v={{ filemtime(public_path('assets/pos/alpine.min.js')) }}" defer></script>
    <style nonce="{{ csp_nonce() }}">
        :root, [data-pos-theme="light"] {
            --bg: #F6F7F9; --surface: #FFFFFF; --border: #E6E8EE;
            --text: #1C2430; --text-2: #6B7280; --free: #EDF0F4; --free-ink: #6B7280; --occupied: #16A34A;
        }
        [data-pos-theme="dark"] {
            --bg: #0F1115; --surface: #12151B; --border: #232833;
            --text: #E7E9EE; --text-2: #8B93A3; --free: #2B3240; --free-ink: #CDD2DC; --occupied: #22C55E;
        }
        body { margin: 0; font-family: 'Saudi Riyal', Inter, 'IBM Plex Sans Arabic', system-ui, sans-serif; background: var(--bg); color: var(--text); }
        h1 { font-size: 16px; padding: 14px; margin: 0; }
        .floor { position: relative; margin: 12px; height: 78vh; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .tbl { position: absolute; width: 74px; height: 74px; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; color: #fff; font-weight: 700; }
        .tbl.free { background: var(--free); color: var(--free-ink); }
        .tbl.occupied { background: var(--occupied); }
        .tbl.circle { border-radius: 50%; }
        .tbl.square { border-radius: 10px; }
        .tbl small { font-weight: 400; font-size: 11px; }
        .legend { padding: 0 14px 12px; color: var(--text-2); font-size: 13px; }
    </style>
    {{-- Saudi Riyal (U+20C1): always-on @font-face, scoped by unicode-range. --}}
    @include('partials.riyal-font')
    @include('partials.arabic-font')
</head>
<body x-data="floor()" x-init="load(); setInterval(load, 6000)">
    <h1>{{ $store->title }} — {{ ___('Floor') }}</h1>
    <div class="floor">
        <template x-for="t in tables" :key="t.id">
            <div class="tbl" :class="[t.state, t.shape || 'square']"
                 :style="`left:${(t.map_x||0)}px; top:${(t.map_y||0)}px`"
                 @click="tap(t)">
                <span x-text="t.table_no"></span>
                <small x-text="t.state === 'occupied' ? money(t.order_total) : (t.seats ? t.seats + ' {{ ___('seats') }}' : '')"></small>
            </div>
        </template>
    </div>
    <div class="legend">{{ ___('Green = occupied · Grey = free. Tap a table to open its order or start a new one.') }}</div>
    <script nonce="{{ csp_nonce() }}">
        function floor() {
            return {
                tables: [],
                money(v) { return v == null ? '' : Number(v).toFixed({{ $posDecimals }}); },
                async load() {
                    const r = await fetch('{{ route('pos.floor') }}', { headers: { 'Accept': 'application/json' } });
                    this.tables = await r.json();
                },
                tap(t) {
                    // Occupied → open its order; free → start a new order for that table.
                    window.location = '{{ route('pos.index') }}' + (t.order_id ? ('?order=' + t.order_id) : ('?table=' + t.table_no));
                },
            };
        }
    </script>
</body>
</html>
