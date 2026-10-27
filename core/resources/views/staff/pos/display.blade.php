{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    // MC-22: inject the store's currency decimals so the customer display's
    // money() renders 3-decimal (Gulf) currencies with their fils, not 2dp.
    $posDecimals = currency_decimals(store_options($store->id, 'currency_code') ?: null);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ function_exists('current_language') && current_language() ? current_language()->direction : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $store->title }} — {{ ___('Customer display') }}</title>
    {{-- T7-3: light theme to match the register (tokens mirror pos-register.css §1). --}}
    <style nonce="{{ csp_nonce() }}">
        :root {
            --pos-accent: {{ $store->color ?? '#10b981' }};
            --bg: #F6F7F9; --surface: #FFFFFF; --border: #E6E8EE;
            --text: #1C2430; --text-2: #6B7280; --danger: #EF4444;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body { font-family: 'Saudi Riyal', Inter, 'IBM Plex Sans Arabic', system-ui, sans-serif; background: var(--bg); color: var(--text); display: flex; flex-direction: column; }
        header { padding: 22px 32px; border-bottom: 1px solid var(--border); background: var(--surface); display: flex; align-items: center; gap: 16px; }
        header img { height: 44px; }
        header b { font-size: 26px; }
        .wrap { flex: 1; display: flex; flex-direction: column; padding: 24px 32px; overflow: hidden; }
        .cust { font-size: 20px; font-weight: 700; color: var(--pos-accent); margin-bottom: 8px; }
        .lines { flex: 1; overflow: auto; }
        .nm small.opt, .nm small.extra { display: block; font-size: .55em; color: var(--text-2); font-weight: 400; }
        .line { display: flex; justify-content: space-between; align-items: baseline; padding: 14px 0; border-bottom: 1px solid var(--border); font-size: 24px; }
        .line .q { color: var(--pos-accent); font-weight: 700; margin-inline-end: 14px; }
        .line .nm { flex: 1; }
        .line .amt { font-variant-numeric: tabular-nums; }
        .totals { border-top: 2px solid var(--border); padding-top: 18px; margin-top: 6px; }
        .totals .row { display: flex; justify-content: space-between; font-size: 22px; padding: 6px 0; }
        .totals .row.cut { color: var(--danger); }
        .totals .grand { font-size: 34px; font-weight: 800; color: var(--pos-accent); }
        .idle { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 12px; }
        .idle h1 { font-size: 44px; margin: 0; }
        .idle p { color: var(--text-2); font-size: 20px; margin: 0; }
        .badge { font-size: 15px; color: var(--text-2); text-transform: capitalize; }
        [x-cloak] { display: none !important; }
    </style>
    {{-- Saudi Riyal (U+20C1): always-on @font-face, scoped by unicode-range. --}}
    @include('partials.riyal-font')
    @include('partials.arabic-font')
    {{-- Self-hosted Alpine (same build as the register; no third-party script host, CSP 'self' + nonce). --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset('assets/pos/alpine.min.js') }}?v={{ filemtime(public_path('assets/pos/alpine.min.js')) }}" defer></script>
</head>
<body x-data="posDisplay()" x-init="boot()">
    <header>
        @if($store->main_image)<img src="{{ asset('assets/img/'.$store->main_image) }}" alt="">@endif
        <b>{{ $store->title }}</b>
        <span style="flex:1"></span>
        <span class="badge" x-text="state.type || ''"></span>
    </header>

    <div class="wrap">
        {{-- Active order --}}
        <template x-if="state.active">
            <div style="flex:1;display:flex;flex-direction:column;overflow:hidden">
                <div class="cust" x-show="state.customer" x-cloak x-text="'{{ ___('Hello') }} ' + (state.customer || '')"></div>
                <div class="lines">
                    <template x-for="(l, i) in state.lines" :key="i">
                        <div class="line">
                            <span class="q" x-text="l.qty + '×'"></span>
                            <span class="nm">
                                <span x-text="l.name"></span>
                                {{-- T6-4: options · T7-3: extras --}}
                                <template x-for="opt in (l.options || [])" :key="opt">
                                    <small class="opt" x-text="opt"></small>
                                </template>
                                <template x-for="ex in (l.extras || [])" :key="ex">
                                    <small class="extra" x-text="'+ ' + ex"></small>
                                </template>
                            </span>
                            <span class="amt" x-text="money(l.line_total)"></span>
                        </div>
                    </template>
                </div>
                <div class="totals">
                    <div class="row"><span>{{ ___('Subtotal') }}</span><span x-text="money(state.subtotal)"></span></div>
                    <div class="row cut" x-show="state.discount > 0"><span>{{ ___('Discount') }}</span><span x-text="'−' + money(state.discount)"></span></div>
                    <div class="row cut" x-show="state.coupon"><span x-text="'{{ ___('Coupon') }} ' + (state.coupon ? state.coupon.code : '')"></span><span x-text="'−' + money(state.coupon ? state.coupon.value : 0)"></span></div>
                    <div class="row cut" x-show="state.wallet > 0"><span>{{ ___('Wallet') }}</span><span x-text="'−' + money(state.wallet)"></span></div>
                    <div class="row" x-show="state.tax > 0"><span>{{ ___('Tax') }}</span><span x-text="money(state.tax)"></span></div>
                    <div class="row grand"><span>{{ ___('Payable') }}</span><span x-text="money(state.total)"></span></div>
                </div>
            </div>
        </template>

        {{-- Idle / thank-you --}}
        <template x-if="!state.active">
            <div class="idle">
                <h1>{{ ___('Welcome') }}</h1>
                <p>{{ $store->title }}</p>
                <p x-show="thanked" x-cloak>{{ ___('Thank you — see you again!') }}</p>
            </div>
        </template>
    </div>

    <script nonce="{{ csp_nonce() }}">
        function posDisplay() {
            return {
                storeId: {{ $store->id }},
                state: { active: false, lines: [], subtotal: 0, total: 0, type: '', customer: null, discount: 0, coupon: null, wallet: 0, tax: 0 },
                liveConnected: false, // a same-device register is broadcasting to us
                thanked: false,
                money(v) { return Number(v || 0).toFixed({{ $posDecimals }}); },
                boot() {
                    // Live path: same-device register broadcasts on every cart change.
                    try {
                        const ch = new BroadcastChannel('pos-cart-' + this.storeId);
                        ch.onmessage = (e) => this.apply(e.data, true);
                        // Ask any open register to push its current cart (display opened mid-order).
                        ch.postMessage({ hello: true });
                    } catch (e) { /* BroadcastChannel unsupported → poll only */ }
                    // Cross-device fallback: poll the server every 5s until a live register connects.
                    this.poll();
                    setInterval(() => this.poll(), 5000);
                },
                apply(data, live) {
                    if (!data || data.hello) return; // ignore our own handshake echo
                    if (live) this.liveConnected = true;
                    // A cart that goes empty after having items → brief "thank you".
                    if (this.state.active && !data.active) this.thanked = true;
                    if (data.active) this.thanked = false;
                    this.state = {
                        active: !!data.active,
                        type: data.type || '',
                        lines: data.lines || [],
                        subtotal: data.subtotal || 0,
                        total: data.total || 0,
                        customer: data.customer || null,
                        discount: data.discount || 0,
                        coupon: data.coupon || null,
                        wallet: data.wallet || 0,
                        tax: data.tax || 0,
                    };
                },
                async poll() {
                    // Once a same-device register is broadcasting, IT is the source of truth —
                    // the server poll only knows saved DB orders, so it must not clobber the
                    // live (unsaved) cart. Cross-device displays never connect, so they poll.
                    if (this.liveConnected) return;
                    try {
                        const url = new URL('{{ route('pos.display.state') }}', location.origin);
                        url.searchParams.set('restaurant', this.storeId);
                        const r = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (r.ok) this.apply(await r.json(), false);
                    } catch (e) { /* offline — keep last state */ }
                },
            };
        }
    </script>
</body>
</html>
