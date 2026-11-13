{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ function_exists('current_language') && current_language() ? current_language()->direction : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ___('Print settings') }} — {{ $store->title }}</title>
    {{-- Self-hosted Alpine (same build as the register; no third-party script host, CSP 'self' + nonce). --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset('assets/pos/alpine.min.js') }}?v={{ filemtime(public_path('assets/pos/alpine.min.js')) }}" defer></script>
    <style nonce="{{ csp_nonce() }}">
        body { font-family: system-ui, sans-serif; margin: 0; background: #0f1115; color: #e7e9ee; }
        main { padding: 20px; max-width: 560px; margin: 0 auto; }
        a { color: #10b981; }
        .card { background: #1b1f27; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        label { display: block; margin: 14px 0 6px; font-size: 14px; color: #c7ccd6; }
        select, input { width: 100%; box-sizing: border-box; background: #12151b; border: 1px solid #232833; color: #e7e9ee; border-radius: 8px; padding: 10px; font-size: 15px; }
        button { background: #10b981; color: #06231a; border: 0; border-radius: 8px; padding: 11px 16px; cursor: pointer; font-weight: 700; margin-top: 16px; }
        .hint { color: #8b93a3; font-size: 13px; margin-top: 8px; line-height: 1.5; }
        .ok { color: #10b981; } .row { display: flex; gap: 12px; } .row > div { flex: 1; }
    </style>
</head>
<body x-data="posSettings()">
    <main>
        <p><a href="{{ route('pos.index') }}?store={{ $store->id }}">&larr; {{ ___('Back to register') }}</a></p>
        <h1>{{ ___('Print settings') }}</h1>

        <div class="card">
            @if($branches->count())
                <label>{{ ___('Branch') }}</label>
                <select x-model.number="branchId" @change="loadForBranch()">
                    <option value="0">{{ ___(':noun default (no branch)', ['noun' => store_noun($store ?? null, 'title')]) }}</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($b->id == $activeBranchId)>{{ $b->name }}</option>
                    @endforeach
                </select>
            @endif

            <label>{{ ___('Auto-print on settle') }}</label>
            <select x-model="form.auto_print">
                <option value="off">{{ ___('Off') }}</option>
                <option value="receipt">{{ ___('Customer receipt') }}</option>
                <option value="kitchen">{{ ___('Kitchen ticket') }}</option>
                <option value="both">{{ ___('Both') }}</option>
            </select>

            <div class="row">
                <div>
                    <label>{{ ___('Copies') }}</label>
                    <input type="number" min="1" max="5" x-model.number="form.copies">
                </div>
                <div>
                    <label>{{ ___('Auto-lock (minutes, 0 = off)') }}</label>
                    <input type="number" min="0" max="120" x-model.number="form.autolock_minutes">
                </div>
            </div>

            <label>{{ ___('Station name (shown on ticket)') }}</label>
            <input type="text" maxlength="40" x-model="form.station_name" placeholder="{{ ___('e.g. Front counter') }}">

            <p class="hint">
                {{ ___('Browsers cannot pick a printer for you. This controls WHAT prints and WHEN — on settle the register opens the receipt/ticket and calls print; the printer is chosen once in your OS print dialog (or set as the kiosk default).') }}
            </p>

            <button @click="save()">{{ ___('Save') }}</button>
            <span class="ok" x-show="saved" x-cloak style="margin-inline-start:10px">{{ ___('Saved') }}</span>
        </div>

        {{-- D8: terminal registry — named devices, last-seen, printer pin, retire. --}}
        <div class="card">
            <h2 style="margin-top:0;font-size:18px">{{ ___('Terminals') }}</h2>
            <p class="hint" style="margin-top:0">{{ ___('Each register/device can be named and pinned to a printer that overrides the branch default.') }}</p>
            <template x-if="!terminals.length">
                <p class="hint">{{ ___('No terminals yet. A device registers itself when it unlocks the register.') }}</p>
            </template>
            <template x-for="t in terminals" :key="t.id">
                <div style="border-top:1px solid #232833;padding-top:12px;margin-top:12px">
                    <div style="display:flex;align-items:center;gap:8px">
                        <span :style="t.online ? 'color:#10b981' : 'color:#8b93a3'">●</span>
                        <input type="text" maxlength="80" x-model="t.name" style="flex:1">
                    </div>
                    <p class="hint" x-text="t.last_seen_at ? '{{ ___('Last seen') }}: ' + new Date(t.last_seen_at).toLocaleString() : '{{ ___('Never seen') }}'"></p>
                    <label>{{ ___('Printer override') }}</label>
                    <select x-model.number="t.printer_profile_id">
                        <option value="0">{{ ___('Use branch / store default') }}</option>
                        @foreach($printerProfiles as $pp)
                            <option value="{{ $pp->id }}">{{ $pp->name }}</option>
                        @endforeach
                    </select>
                    <div class="row" style="margin-top:12px">
                        <div><button @click="saveTerminal(t)" style="margin-top:0;width:100%">{{ ___('Save') }}</button></div>
                        <div><button @click="retireTerminal(t)" style="margin-top:0;width:100%;background:#b91c1c;color:#fff">{{ ___('Retire') }}</button></div>
                    </div>
                </div>
            </template>
        </div>
    </main>

    @php
        $__posForm = [
            'auto_print'       => $posSettings['auto_print'],
            'copies'           => (int) $posSettings['copies'],
            'station_name'     => $posSettings['station_name'],
            'autolock_minutes' => (int) $posSettings['autolock_minutes'],
        ];
    @endphp
    <style nonce="{{ csp_nonce() }}">[x-cloak]{display:none !important;}</style>
    <script nonce="{{ csp_nonce() }}">
        function posSettings() {
            return {
                storeId: {{ $store->id }},
                branchId: {{ (int) ($activeBranchId ?? 0) }},
                saved: false,
                csrf: document.querySelector('meta[name=csrf-token]').content,
                form: {!! json_encode($__posForm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!},
                // D8: terminals seeded server-side; actions post to the terminal endpoints.
                terminals: {!! \Illuminate\Support\Js::from($terminals->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'printer_profile_id' => (int) ($t->printer_profile_id ?? 0),
                    'last_seen_at' => optional($t->last_seen_at)->toIso8601String(),
                    'online' => $t->isOnline(),
                ])->values()) !!},
                async saveTerminal(t) {
                    const r = await fetch('{{ url('pos/terminals') }}/' + t.id + '?store=' + this.storeId, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                        body: JSON.stringify({ name: t.name, printer_profile_id: t.printer_profile_id || null }),
                    });
                    if (r.ok) { this.saved = true; setTimeout(() => this.saved = false, 2500); }
                    else { const d = await r.json().catch(() => ({})); alert(d.message || 'Error'); }
                },
                async retireTerminal(t) {
                    if (!confirm('{{ ___('Retire this terminal?') }}')) return;
                    const r = await fetch('{{ url('pos/terminals') }}/' + t.id + '/retire?store=' + this.storeId, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                    });
                    if (r.ok) { this.terminals = this.terminals.filter(x => x.id !== t.id); }
                },
                async loadForBranch() {
                    // Re-fetch the settings page for the chosen branch (simple + always correct).
                    location.href = '{{ route('pos.settings') }}?store=' + this.storeId + (this.branchId ? '&branch=' + this.branchId : '');
                },
                async save() {
                    this.saved = false;
                    const body = { branch_id: this.branchId || null, ...this.form };
                    const r = await fetch('{{ route('pos.settings.save') }}?store=' + this.storeId, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                        body: JSON.stringify(body),
                    });
                    if (r.ok) { this.saved = true; setTimeout(() => this.saved = false, 2500); }
                    else { const d = await r.json().catch(() => ({})); alert(d.message || 'Error'); }
                },
            };
        }
    </script>
</body>
</html>
