{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl(app()->getLocale()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ ___('Driver') }}</title>
    <link rel="manifest" href="{{ route('driver.pwa.manifest') }}">
    <meta name="theme-color" content="#0b1020">
    <style nonce="{{ csp_nonce() }}">
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, sans-serif; background:#0b1020; color:#e8ecf5; }
        header { padding:14px 16px; background:#131a2e; font-weight:600; display:flex; justify-content:space-between; align-items:center; }
        .wrap { padding:12px 14px 80px; }
        .card { background:#182136; border-radius:14px; padding:14px; margin-bottom:12px; }
        .cash { font-size:1.4rem; font-weight:700; color:#ffd86b; }
        .addr { color:#a9b6d6; margin:6px 0; }
        button, a.btn { display:inline-block; border:0; border-radius:10px; padding:10px 14px; font-weight:600; background:#3b6ef5; color:#fff; text-decoration:none; margin:4px 4px 0 0; }
        button.ghost { background:#26314e; }
        .tabs { display:flex; gap:8px; padding:8px 14px; }
        .tabs button { flex:1; background:#1c2743; }
        .tabs button.active { background:#3b6ef5; }
        .muted { color:#8797bd; font-size:.85rem; }
        input { width:100%; padding:10px; border-radius:10px; border:1px solid #26314e; background:#0f1626; color:#fff; }
    </style>
    <script src="{{ asset('assets/js/csp-actions.js') }}?v={{ filemtime(public_path('assets/js/csp-actions.js')) }}"></script>
    <script src="{{ asset('assets/driver/outbox.js') }}?v={{ filemtime(public_path('assets/driver/outbox.js')) }}"></script>
</head>
<body>
<div id="outbox-badge" class="muted" style="display:none;background:#b45309;color:#fff;padding:6px 12px;text-align:center"></div>
<header>
    <span>{{ ___('Deliveries') }}</span>
    <span id="who" class="muted"></span>
</header>

<div id="login" class="wrap" style="display:none">
    <div class="card">
        <p>{{ ___('Paste your driver token to sign in.') }}</p>
        <input id="tok" placeholder="{{ ___('Driver token') }}">
        <button data-act="saveToken">{{ ___('Sign in') }}</button>
    </div>
</div>

<div id="app" style="display:none">
    <div class="tabs">
        <button id="tab-mine" class="active" data-act="switchTab" data-args='["mine"]'>{{ ___('My queue') }}</button>
        <button id="tab-open" data-act="switchTab" data-args='["open"]'>{{ ___('Available') }}</button>
    </div>
    <div class="wrap"><div id="list"></div></div>
</div>

<script nonce="{{ csp_nonce() }}">
const API = '{{ url('/api/v1/driver') }}';
let TOKEN = @json($token) || localStorage.getItem('driver_token') || '';
let TAB = 'mine';

function hdr() { return { 'Authorization': 'Bearer ' + TOKEN, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With':'fetch' }; }
function saveToken() { TOKEN = document.getElementById('tok').value.trim(); localStorage.setItem('driver_token', TOKEN); boot(); }

async function boot() {
    if (!TOKEN) { show('login'); return; }
    try {
        const me = await fetch(API + '/me', { headers: hdr() });
        if (!me.ok) throw 0;
        const d = await me.json();
        document.getElementById('who').textContent = d.name || '';
        show('app'); load();
    } catch (e) { show('login'); }
}
function show(id) { ['login','app'].forEach(x => document.getElementById(x).style.display = x===id ? 'block':'none'); }
function switchTab(t){ TAB=t; document.getElementById('tab-mine').classList.toggle('active',t==='mine'); document.getElementById('tab-open').classList.toggle('active',t==='open'); load(); }

async function load() {
    const url = TAB==='mine' ? API + '/deliveries?active=1' : API + '/available';
    const res = await fetch(url, { headers: hdr() });
    const json = await res.json();
    const rows = json.data || [];
    document.getElementById('list').innerHTML = rows.length ? rows.map(card).join('') : '<p class="muted">{{ ___('Nothing here right now.') }}</p>';
}

function card(o) {
    const addr = encodeURIComponent(o.address || '');
    const cash = (o.payment_method === 'cash' || o.payment_gateway === 'cash') ? (o.amount_due ?? o.total ?? 0) : 0;
    let actions = '';
    if (TAB==='open') {
        actions = `<button data-act="claim" data-args='[${o.id}]'>{{ ___('Claim') }}</button>`;
    } else {
        actions = `
            <button data-act="setStatus" data-args='[${o.id},"picked_up"]'>{{ ___('Picked up') }}</button>
            <button data-act="setStatus" data-args='[${o.id},"out_for_delivery"]'>{{ ___('En route') }}</button>
            <button data-act="setStatus" data-args='[${o.id},"delivered"]'>{{ ___('Delivered') }}</button>`;
    }
    return `<div class="card">
        <div><strong>#${o.id}</strong> · <span class="muted">${o.delivery_status || ''}</span></div>
        <div class="addr">${o.address || ''}</div>
        ${cash>0 ? `<div class="cash">{{ ___('Collect') }} ${cash}</div>` : ''}
        <a class="btn ghost" href="https://maps.google.com/?q=${addr}" target="_blank">{{ ___('Map') }}</a>
        ${o.phone_number ? `<a class="btn ghost" href="tel:${o.phone_number}">{{ ___('Call') }}</a>`:''}
        ${actions}
    </div>`;
}

const OUTBOX = DriverOutbox.create({
    storage: localStorage, fetch: (u, o) => fetch(u, o), headers: hdr, key: 'driver_outbox_v1',
    onChange: n => { const b = document.getElementById('outbox-badge'); b.textContent = n ? '{{ ___('Not sent - will retry when you are online') }} (' + n + ')' : ''; b.style.display = n ? 'block' : 'none'; },
    onReplayed: () => load(),
});
window.addEventListener('online', () => OUTBOX.flush());
OUTBOX.flush();
async function setStatus(id, s) {
    const q = await OUTBOX.send(API + '/deliveries/' + id + '/status', 'PUT', JSON.stringify({ delivery_status: s }));
    if (q.state === 'queued') { alert('{{ ___('Not sent - will retry when you are online') }}'); }
    load();
}
async function claim(id) {
    const r = await fetch(API + '/deliveries/' + id + '/claim', { method:'POST', headers: hdr() });
    if (r.ok) { switchTab('mine'); } else { alert('{{ ___('Order already taken.') }}'); load(); }
}

// GPS ping every 30s while open.
function pingLoop() {
    if (!TOKEN || !navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(p => {
        fetch(API + '/location', { method:'PUT', headers: hdr(), body: JSON.stringify({ lat: p.coords.latitude, lng: p.coords.longitude }) });
    }, () => {}, { enableHighAccuracy:true, timeout:10000 });
}
setInterval(pingLoop, 30000);

if ('serviceWorker' in navigator) navigator.serviceWorker.register('{{ route('driver.pwa.sw') }}').catch(()=>{});
boot();
</script>
</body>
</html>
