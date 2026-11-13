{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
$kindLabels = [                    'pricing_mismatch' => ___('Price mismatch'),
                    'old_snapshot_price' => ___('Old snapshot price'),
                    'oversell' => ___('Oversold'),
                    'deleted_item' => ___('Deleted item'),
                    'late_tender' => ___('Late tender'),
                    'void_conflict' => ___('Void conflict'),
                    'declined_after_offline' => ___('Declined after offline'),
                    'cap_exceeded' => ___('Cap exceeded'),
                    'card_reversal_not_booked' => ___('Card refund not booked'),
];
$stateLabels = [                    'declined_after_offline' => ___('Declined after offline'),
                    'resolved' => ___('Resolved'),
                    'open' => ___('Open'),
                    'refund' => ___('Card refund'),
                    'void' => ___('Card payment voided'),
                    'cash' => ___('Cash'),
                    'card' => ___('Card'),
];
$reasonLabels = [
    'pricing_mismatch' => ___('Price mismatch'),
    'order_changed' => ___('Order changed on the server'),
    'already_paid' => ___('Already paid'),
    'not_this_device' => ___('Not this till\'s sale'),
    'line_gone' => ___('Line no longer exists'),
    'shift_already_open' => ___('A shift is already open'),
    'no_open_shift' => ___('No open shift'),
];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ function_exists('current_language') && current_language() ? current_language()->direction : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ___('Offline exceptions') }} — {{ $store->title }}</title>
    {{-- Self-hosted Alpine (same build as the register; no third-party script host, CSP 'self' + nonce). --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset('assets/pos/alpine.min.js') }}?v={{ filemtime(public_path('assets/pos/alpine.min.js')) }}" defer></script>
    <style nonce="{{ csp_nonce() }}">
        body { font-family: system-ui, sans-serif; margin: 0; background: #0f1115; color: #e7e9ee; }
        main { padding: 20px; max-width: 1100px; margin: 0 auto; }
        a { color: #10b981; }
        .card { background: #1b1f27; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        h2 { margin-top: 0; font-size: 18px; }
        label { display: block; margin: 14px 0 6px; font-size: 14px; color: #c7ccd6; }
        select { width: 100%; box-sizing: border-box; background: #12151b; border: 1px solid #232833; color: #e7e9ee; border-radius: 8px; padding: 10px; font-size: 15px; }
        button { background: #10b981; color: #06231a; border: 0; border-radius: 8px; padding: 10px 14px; cursor: pointer; font-weight: 700; }
        button.secondary { background: #232833; color: #e7e9ee; }
        button:focus, button:focus-visible, select:focus, select:focus-visible, a:focus, a:focus-visible, [tabindex]:focus, [tabindex]:focus-visible { outline: 2px solid #10b981; outline-offset: 2px; }
        a, button, select { transition: none; }
        main { min-width: 0; }
        .tbl-wrap { overflow-x: auto; max-width: 100%; }
        .filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .filters > div { flex: 1; min-width: 180px; }
        .hint { color: #8b93a3; font-size: 13px; margin-top: 8px; line-height: 1.5; }
        .err { color: #f87171; font-size: 14px; }
        .ok { color: #10b981; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { text-align: start; padding: 8px 10px; border-bottom: 1px solid #232833; font-size: 14px; vertical-align: top; }
        th { color: #8b93a3; font-weight: 600; white-space: nowrap; }
        .badge { display: inline-block; background: #232833; color: #e7e9ee; border-radius: 999px; padding: 2px 9px; font-size: 12px; margin-inline-end: 4px; margin-block: 2px; }
        .card-row { border-top: 1px solid #232833; padding-top: 12px; margin-top: 12px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .card-row .grow { flex: 1; min-width: 220px; }
        .muted { color: #8b93a3; }
        .caption-start { text-align: start; }
        .cards-title { margin-top: 20px; }
        .back-arrow { display: inline-block; }
        [dir=rtl] .back-arrow { transform: scaleX(-1); }
    </style>
</head>
<body x-data="posExceptions()" x-init="load()">
    <main>
        <p><a href="{{ route('pos.index') }}?store={{ $store->id }}"><span class="back-arrow" aria-hidden="true">&larr;</span> {{ ___('Back to register') }}</a></p>
        <h1>{{ ___('Offline exceptions') }}</h1>
        <p class="hint">{{ ___('Replayed offline records the server flagged or refused, plus declined offline card payments.') }}</p>

        <div class="card">
            <div class="filters">
                <div>
                    <label for="flt-kind">{{ ___('Kind') }}</label>
                    <select id="flt-kind" x-model="filters.kind" @change="load()">
                        <option value="">{{ ___('All') }}</option>
                        <template x-for="k in kindOptions" :key="k">
                            <option :value="k" x-text="kindLabel(k)"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label for="flt-shift">{{ ___('Shift') }}</label>
                    <select id="flt-shift" x-model="filters.shift_id" @change="load()">
                        <option value="">{{ ___('All') }}</option>
                        <template x-for="s in shifts" :key="s">
                            <option :value="String(s)" x-text="'#' + s"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label for="flt-till">{{ ___('Till') }}</label>
                    <select id="flt-till" x-model="filters.device_id" @change="load()">
                        <option value="">{{ ___('All') }}</option>
                        <template x-for="d in tillOptions" :key="d">
                            <option :value="d" x-text="d"></option>
                        </template>
                    </select>
                </div>
            </div>
            <p aria-live="polite" class="hint" x-text="message"></p>
        </div>

        <div class="card" :aria-busy="loading ? 'true' : 'false'">
            <p class="hint" x-show="loading" x-cloak>{{ ___('Loading…') }}</p>

            <template x-if="error">
                <div>
                    <p class="err" role="alert" x-text="error"></p>
                    <button type="button" class="secondary" @click="load()">{{ ___('Retry') }}</button>
                </div>
            </template>

            <template x-if="!loading && !error && !exceptions.length && !cards.length && !reversals.length">
                <p class="muted">{{ ___('No offline exceptions.') }}</p>
            </template>

            <div x-show="!loading && !error && (exceptions.length || cards.length || reversals.length)" x-cloak>
                <template x-if="exceptions.length">
                    <div class="tbl-wrap" role="region" tabindex="0" aria-label="{{ ___('Offline records') }}">
                    <table>
                        <caption class="hint caption-start">{{ ___('Offline records') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ ___('Time') }}</th>
                                <th scope="col">{{ ___('Till') }}</th>
                                <th scope="col">{{ ___('Shift') }}</th>
                                <th scope="col">{{ ___('Order') }}</th>
                                <th scope="col">{{ ___('Kind') }}</th>
                                <th scope="col">{{ ___('Reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in exceptions" :key="row.client_key">
                                <tr>
                                    <td x-text="when(row)"></td>
                                    <td x-text="row.device_id"></td>
                                    <td x-text="row.shift_id ? '#' + row.shift_id : '—'"></td>
                                    <td x-text="row.order_id ? '#' + row.order_id : '—'"></td>
                                    <td>
                                        <template x-for="k in row.kinds" :key="k">
                                            <span class="badge" x-text="kindLabel(k)"></span>
                                        </template>
                                    </td>
                                    <td x-text="row.reason ? reasonLabel(row.reason) : '—'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    </div>
                </template>

                <template x-if="cards.length">
                    <section>
                        <h2 class="cards-title">{{ ___('Declined cards') }}</h2>
                        <template x-for="card in cards" :key="card.id">
                            <div class="card-row" :id="'card-' + card.id" tabindex="-1">
                                <div class="grow">
                                    <div>
                                        <strong x-text="money(card.amount_minor, card.currency_code)"></strong>
                                        <span class="muted" x-text="(card.scheme || '') + ' ' + (card.masked_pan || '')"></span>
                                    </div>
                                    <div class="hint" x-text="stateLabel(card.state) + (card.resolution_method ? ' — ' + stateLabel(card.resolution_method) : '')"></div>
                                </div>
                                <div x-show="card.state === 'declined_after_offline'">
                                    <button type="button" :aria-label="resolveLabel(card, 'cash')" :disabled="busy === card.id" @click="resolve(card, 'cash')">{{ ___('Cash collected') }}</button>
                                    <button type="button" class="secondary" :aria-label="resolveLabel(card, 'card')" :disabled="busy === card.id" @click="resolve(card, 'card')">{{ ___('Card collected') }}</button>
                                </div>
                                <span class="ok" x-show="card.state === 'resolved'" x-text="stateLabel('resolved')"></span>
                            </div>
                        </template>
                    </section>
                </template>

                <template x-if="reversals.length">
                    <section>
                        <h2 class="cards-title">{{ ___('Card refunds not booked') }}</h2>
                        <p class="hint">{{ ___('The provider refunded these card payments, but the order could not take the refund. Correct the order or the books, then mark it resolved.') }}</p>
                        <template x-for="rev in reversals" :key="rev.id">
                            <div class="card-row" :id="'rev-' + rev.id" tabindex="-1">
                                <div class="grow">
                                    <div>
                                        <strong x-text="money(rev.amount_minor, rev.currency_code)"></strong>
                                        <span class="muted" x-text="stateLabel(rev.kind) + ' — #' + rev.order_id + (rev.provider_reference ? ' — ' + rev.provider_reference : '')"></span>
                                    </div>
                                    <div class="hint" x-text="rev.reason"></div>
                                </div>
                                <div x-show="rev.state === 'open'">
                                    <button type="button" :aria-label="@json(___('Mark resolved')) + ', ' + money(rev.amount_minor, rev.currency_code)" :disabled="busy === 'rev' + rev.id" @click="resolveReversal(rev)">{{ ___('Mark resolved') }}</button>
                                </div>
                                <span class="ok" x-show="rev.state === 'resolved'" x-text="stateLabel('resolved')"></span>
                            </div>
                        </template>
                    </section>
                </template>
            </div>
        </div>
    </main>

    <style nonce="{{ csp_nonce() }}">[x-cloak]{display:none !important;}</style>
    <script nonce="{{ csp_nonce() }}">
        function posExceptions() {
            return {
                storeId: {{ $store->id }},
                csrf: document.querySelector('meta[name=csrf-token]').content,
                loading: true,
                error: '',
                message: '',
                busy: null,
                exceptions: [],
                cards: [],
                reversals: [],
                shifts: [],
                tillOptions: [],
                loadSeq: 0,
                filters: { kind: '', shift_id: '', device_id: '' },
                kindOptions: ['pricing_mismatch', 'old_snapshot_price', 'oversell', 'deleted_item', 'late_tender', 'void_conflict', 'cap_exceeded', 'declined_after_offline', 'card_reversal_not_booked'],
                kindLabels: {!! json_encode($kindLabels, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!},
                stateLabels: {!! json_encode($stateLabels, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!},
                reasonLabels: {!! json_encode($reasonLabels, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!},
                actionLabels: { cash: @json(___('Cash collected')), card: @json(___('Card collected')) },
                kindLabel(k) { return this.kindLabels[k] || k; },
                stateLabel(k) { return this.stateLabels[k] || k; },
                reasonLabel(r) { return this.reasonLabels[r] || r; },
                resolveLabel(card, action) { return [this.actionLabels[action] || action, this.money(card.amount_minor, card.currency_code), card.masked_pan || ''].filter(Boolean).join(', '); },
                locale() { return document.documentElement.lang || undefined; },
                when(row) { const t = row.business_at || row.created_at; return t ? new Date(t).toLocaleString(this.locale()) : '—'; },
                fractionDigits(code) { try { return new Intl.NumberFormat(this.locale(), { style: 'currency', currency: code || 'USD' }).resolvedOptions().maximumFractionDigits; } catch (e) { return 2; } },
                money(minor, code) { const d = this.fractionDigits(code); return new Intl.NumberFormat(this.locale(), { style: 'currency', currency: code || 'USD' }).format((minor || 0) / Math.pow(10, d)); },
                async load() {
                    const seq = ++this.loadSeq;
                    this.loading = true;
                    this.error = '';
                    const params = new URLSearchParams();
                    if (this.filters.kind) { params.set('kind', this.filters.kind); }
                    if (this.filters.device_id) { params.set('device_id', this.filters.device_id); }
                    if (this.filters.shift_id) { params.set('shift_id', this.filters.shift_id); }
                    const qs = params.toString();
                    try {
                        const r = await fetch('{{ route('pos.offline.exceptions') }}?store=' + this.storeId + (qs ? '&' + qs : ''), {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (seq !== this.loadSeq) { return; }
                        if (!r.ok) { throw new Error('HTTP ' + r.status); }
                        const data = await r.json();
                        if (seq !== this.loadSeq) { return; }
                        this.exceptions = data.exceptions || [];
                        this.cards = data.cards || [];
                        this.reversals = data.reversals || [];
                        this.shifts = data.shifts || [];
                        this.tillOptions = data.devices || [];
                    } catch (e) {
                        if (seq !== this.loadSeq) { return; }
                        this.error = @json(___('Could not load the exceptions.'));
                    }
                    if (seq === this.loadSeq) { this.loading = false; }
                },
                async resolveReversal(rev) {
                    this.busy = 'rev' + rev.id;
                    this.message = '';
                    try {
                        const r = await fetch('{{ url('pos/card-reversals') }}/' + rev.id + '/resolve?store=' + this.storeId, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                            credentials: 'same-origin',
                            body: JSON.stringify({}),
                        });
                        const data = await r.json().catch(() => ({}));
                        if (!r.ok) {
                            this.message = data.message || @json(___('Could not resolve the card.'));
                        } else {
                            rev.state = data.state || 'resolved';
                            this.message = @json(___('Card payment updated.'));
                            this.$nextTick(() => { const el = document.getElementById('rev-' + rev.id); if (el) { el.focus(); } });
                        }
                    } catch (e) {
                        this.message = @json(___('Could not resolve the card.'));
                    }
                    this.busy = null;
                },
                async resolve(card, method) {
                    this.busy = card.id;
                    this.error = '';
                    this.message = '';
                    try {
                        const r = await fetch('{{ url('pos/offline/cards') }}/' + card.id + '/resolve?store=' + this.storeId, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                            credentials: 'same-origin',
                            body: JSON.stringify({ method: method }),
                        });
                        const data = await r.json().catch(() => ({}));
                        if (!r.ok) {
                            this.message = data.message || @json(___('Could not resolve the card.'));
                        } else {
                            card.state = data.state || 'resolved';
                            card.resolution_method = data.resolution_method || method;
                            this.message = @json(___('Card payment updated.'));
                            this.$nextTick(() => { const el = document.getElementById('card-' + card.id); if (el) { el.focus(); } });
                        }
                    } catch (e) {
                        this.message = @json(___('Could not resolve the card.'));
                    }
                    this.busy = null;
                },
            };
        }
    </script>
</body>
</html>
