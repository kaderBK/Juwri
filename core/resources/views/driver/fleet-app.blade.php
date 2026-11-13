{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl(app()->getLocale()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ ___('Fusion Driver') }}</title>
    <link rel="manifest" href="{{ route('driver.fleet.manifest') }}">
    <meta name="theme-color" content="#0E1013">
    <style nonce="{{ csp_nonce() }}">
        /* L4 T4-3 — dark-first utilitarian skin per UI-KIT §5.
           Token values COPIED from assets/marketplace/mk.css [data-mk-theme="dark"]
           (mk.css belongs to the shopper shell — do not link it). */
        :root {
            color-scheme: dark;
            --mk-bg: #0E1013;
            --mk-surface: #171A20;
            --mk-surface-2: #1F232B;
            --mk-ink: #E9EBF0;
            --mk-ink-2: #8D94A3;
            --mk-primary: #FF4D6A;
            --mk-primary-soft: rgba(255, 77, 106, .12);
            --mk-cta: #22C55E;
            --mk-star: #F5A623;
            --mk-line: #242936;
            --mk-r: 14px;
            --mk-r-lg: 20px;
            --mk-ease: cubic-bezier(.2, .7, .3, 1);
            --mk-t-micro: 150ms;
            --mk-t-comp: 250ms;
            --mk-font: 'Inter', 'IBM Plex Sans Arabic', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin:0; font-family:var(--mk-font); background:var(--mk-bg); color:var(--mk-ink);
               font-size:15px; line-height:1.45; min-height:100dvh; -webkit-tap-highlight-color:transparent;
               padding-block-end:calc(64px + env(safe-area-inset-bottom)); }
        header { position:sticky; inset-block-start:0; z-index:40; background:var(--mk-surface);
                 border-block-end:1px solid var(--mk-line); padding:calc(10px + env(safe-area-inset-top)) 16px 10px;
                 display:flex; justify-content:space-between; align-items:center; min-height:56px; }
        header .brand { font-weight:700; }
        .wrap { padding:12px 14px; max-width:560px; margin-inline:auto; }
        .card { background:var(--mk-surface); border:1px solid var(--mk-line); border-radius:var(--mk-r);
                padding:14px; margin-block-end:12px; }
        .offer { border-color:var(--mk-primary); }
        .muted { color:var(--mk-ink-2); font-size:.85rem; }
        .money { font-size:1.3rem; font-weight:700; color:var(--mk-star); }
        .addr { color:var(--mk-ink-2); margin-block:6px; }
        button, a.btn { display:inline-block; border:0; border-radius:10px; padding:11px 16px; font-weight:600;
                 background:var(--mk-surface-2); color:var(--mk-ink); text-decoration:none; cursor:pointer;
                 font-family:inherit; font-size:.95rem; margin:4px 4px 0 0;
                 transition:filter var(--mk-t-micro) var(--mk-ease); }
        button:active, a.btn:active { filter:brightness(1.2); }
        .accept { background:var(--mk-cta); color:#06130a; }
        .reject { background:var(--mk-primary-soft); color:var(--mk-primary); }
        .primary { background:var(--mk-primary); color:#fff; }
        .full { display:block; width:100%; text-align:center; margin-inline:0; }
        input { width:100%; padding:12px; border-radius:10px; border:1px solid var(--mk-line);
                background:var(--mk-surface-2); color:var(--mk-ink); font-family:inherit; font-size:1rem;
                margin-block-end:8px; }
        /* shift toggle */
        .shift { display:flex; gap:8px; }
        .shift button { flex:1; margin:0; }
        .shift button.on { background:var(--mk-cta); color:#06130a; }
        .shift button.brk { background:var(--mk-star); color:#1c1303; }
        /* status slider (stepper) */
        .slider { display:flex; gap:6px; margin-block:10px; }
        .slider .step { flex:1; text-align:center; padding:10px 4px; border-radius:10px; font-size:.8rem;
                        background:var(--mk-surface-2); color:var(--mk-ink-2); border:1px solid var(--mk-line); }
        .slider .step.done { background:var(--mk-primary-soft); color:var(--mk-primary); border-color:var(--mk-primary); }
        .slider .step.next { background:var(--mk-cta); color:#06130a; cursor:pointer; font-weight:700; border:0; }
        /* bottom tabs */
        nav { position:fixed; inset-inline:0; inset-block-end:0; display:flex; background:var(--mk-surface);
              border-block-start:1px solid var(--mk-line); padding-block-end:env(safe-area-inset-bottom); z-index:40; }
        nav button { flex:1; margin:0; border-radius:0; background:transparent; color:var(--mk-ink-2); padding:13px 0; }
        nav button.active { color:var(--mk-primary); }
        .countdown { font-variant-numeric:tabular-nums; color:var(--mk-star); font-weight:700; }
        .dot { display:inline-block; width:9px; height:9px; border-radius:50%; background:var(--mk-ink-2); margin-inline-end:6px; }
        .dot.on { background:var(--mk-cta); }
        .toast { position:fixed; inset-inline:14px; inset-block-end:76px; background:var(--mk-surface-2);
                 border:1px solid var(--mk-line); border-radius:10px; padding:12px; z-index:60; display:none; }
    </style>
    <script src="{{ asset('assets/js/csp-actions.js') }}?v={{ filemtime(public_path('assets/js/csp-actions.js')) }}"></script>
</head>
<body>
<header>
    <span class="brand">{{ ___('Fusion Driver') }}</span>
    <span class="muted"><span id="shift-dot" class="dot"></span><span id="who"></span></span>
</header>

{{-- ---------- LOGIN (phone → OTP → token) ---------- --}}
<div id="scr-login" class="wrap" style="display:none">
    <div class="card">
        <h3 style="margin-top:0">{{ ___('Driver sign in') }}</h3>
        <div id="login-phone-step">
            <input id="phone" type="tel" inputmode="tel" placeholder="{{ ___('Phone number') }}">
            <button class="primary full" data-act="requestOtp">{{ ___('Send code') }}</button>
        </div>
        <div id="login-code-step" style="display:none">
            <p class="muted">{{ ___('Enter the code we sent by SMS.') }}</p>
            <input id="code" inputmode="numeric" placeholder="{{ ___('Verification code') }}">
            <button class="primary full" data-act="verifyOtp">{{ ___('Sign in') }}</button>
            <button class="full" data-act="showStep" data-args='["phone"]'>{{ ___('Change number') }}</button>
        </div>
        <p id="login-msg" class="muted"></p>
    </div>
</div>

{{-- ---------- HOME: shift + offers + active orders ---------- --}}
<div id="scr-home" class="wrap" style="display:none">
    <div class="card">
        <div class="muted" style="margin-bottom:8px">{{ ___('Shift') }}</div>
        <div class="shift">
            <button id="sh-online"  data-act="setShift" data-args='["online"]'>{{ ___('Online') }}</button>
            <button id="sh-break"   data-act="setShift" data-args='["break"]'>{{ ___('Break') }}</button>
            <button id="sh-offline" data-act="setShift" data-args='["offline"]'>{{ ___('Offline') }}</button>
        </div>
    </div>
    <div id="offers"></div>
    <div id="active"></div>
</div>

{{-- ---------- ORDER DETAIL ---------- --}}
<div id="scr-order" class="wrap" style="display:none"></div>

{{-- ---------- EARNINGS ---------- --}}
<div id="scr-earnings" class="wrap" style="display:none"></div>

<nav id="tabs" style="display:none">
    <button id="tab-home" class="active" data-act="go" data-args='["home"]'>{{ ___('Jobs') }}</button>
    <button id="tab-earnings" data-act="go" data-args='["earnings"]'>{{ ___('Earnings') }}</button>
    <button data-act="logout">{{ ___('Sign out') }}</button>
</nav>

<div id="outbox-badge" class="toast" style="display:none;background:#b45309"></div>
<div id="toast" class="toast"></div>

<script src="{{ asset('assets/driver/outbox.js') }}?v={{ filemtime(public_path('assets/driver/outbox.js')) }}"></script>
<script nonce="{{ csp_nonce() }}">
const API = @json(url('/api/v1/driver'));
const I18N = {
    accept: @json(___('Accept')), reject: @json(___('Reject')),
    newOffer: @json(___('New delivery offer')), expires: @json(___('Expires')),
    noOffers: @json(___('No offers right now. Stay online.')),
    activeTitle: @json(___('Active delivery')), open: @json(___('Open')),
    pickedUp: @json(___('Picked up')), enRoute: @json(___('En route')), delivered: @json(___('Delivered')),
    assigned: @json(___('Assigned')), failed: @json(___('Mark failed')),
    failReason: @json(___('Why did the delivery fail?')),
    maps: @json(___('Google Maps')), waze: @json(___('Waze')), call: @json(___('Call')),
    proofTitle: @json(___('Proof of delivery')),
    proofPin: @json(___('Customer PIN')), verifyPin: @json(___('Verify PIN')),
    proofPhoto: @json(___('Take photo')), photoSent: @json(___('Photo uploaded.')),
    pinOk: @json(___('PIN verified.')), pinBad: @json(___('PIN does not match.')),
    podNeeded: @json(___('Proof of delivery required before completing.')),
    offerGone: @json(___('Offer is no longer available.')),
    total: @json(___('Total')), collect: @json(___('Collect cash')),
    earnToday: @json(___('deliveries')), tips: @json(___('Tips')),
    noEarnings: @json(___('No delivered orders in the last 30 days.')),
    back: @json(___('Back')), codeSent: @json(___('If that phone belongs to a driver, a code was sent.')),
    badCode: @json(___('Invalid code.')),
    notSent: @json(___('Not sent - will retry when you are online')),
};

let TOKEN = localStorage.getItem('fleet_driver_token') || '';
let ME = null, CURRENT = null, pollTimer = null, gpsTimer = null;

const $ = id => document.getElementById(id);
const hdr = () => ({ 'Authorization': 'Bearer ' + TOKEN, 'Accept': 'application/json',
                     'Content-Type': 'application/json', 'X-Requested-With': 'fetch' });
const api = (path, opts = {}) => fetch(API + path, Object.assign({ headers: hdr() }, opts));
function toast(msg) { const t = $('toast'); t.textContent = msg; t.style.display = 'block';
    clearTimeout(t._h); t._h = setTimeout(() => t.style.display = 'none', 2600); }
const OUTBOX = DriverOutbox.create({
    storage: localStorage, fetch: (u, o) => fetch(u, o), headers: hdr, key: 'fleet_driver_outbox_v1',
    onChange: n => { const b = $('outbox-badge'); b.textContent = n ? I18N.notSent + ' (' + n + ')' : ''; b.style.display = n ? 'block' : 'none'; },
    onReplayed: () => { CURRENT = null; go('home'); },
});
window.addEventListener('online', () => OUTBOX.flush());
OUTBOX.flush();
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

/* ---------- login ---------- */
function showStep(s) { $('login-phone-step').style.display = s === 'phone' ? 'block' : 'none';
    $('login-code-step').style.display = s === 'code' ? 'block' : 'none'; }
async function requestOtp() {
    const phone = $('phone').value.trim();
    if (!phone) return;
    await fetch(API + '/login/request', { method: 'POST', headers: hdr(),
        body: JSON.stringify({ phone }) }).catch(() => {});
    $('login-msg').textContent = I18N.codeSent;
    showStep('code');
}
async function verifyOtp() {
    const r = await fetch(API + '/login/verify', { method: 'POST', headers: hdr(),
        body: JSON.stringify({ phone: $('phone').value.trim(), code: $('code').value.trim() }) });
    if (!r.ok) { $('login-msg').textContent = I18N.badCode; return; }
    const j = await r.json();
    TOKEN = j.token;
    // Web build: localStorage. Native build: @capacitor/preferences (see driver-app/README.md seam).
    localStorage.setItem('fleet_driver_token', TOKEN);
    boot();
}
async function logout() {
    // P6-237: server answers with Clear-Site-Data; also purge SW caches here.
    await api('/logout', { method: 'POST' }).catch(() => {});
    try { navigator.serviceWorker?.controller?.postMessage('driver-clear'); } catch (e) {}
    try { if (self.caches) { const ks = await caches.keys(); await Promise.all(ks.map(k => caches.delete(k))); } } catch (e) {}
    localStorage.removeItem('fleet_driver_token'); TOKEN = ''; location.reload();
}

/* ---------- boot + navigation ---------- */
async function boot() {
    if (!TOKEN) { show('scr-login'); return; }
    try {
        const r = await api('/me');
        if (!r.ok) throw 0;
        ME = await r.json();
        $('who').textContent = ME.name || '';
        $('tabs').style.display = 'flex';
        go('home');
        startPolling();
        startGps();
    } catch (e) { show('scr-login'); }
}
function show(id) { ['scr-login','scr-home','scr-order','scr-earnings'].forEach(x =>
    $(x).style.display = x === id ? 'block' : 'none'); }
function go(view) {
    $('tab-home').classList.toggle('active', view !== 'earnings');
    $('tab-earnings').classList.toggle('active', view === 'earnings');
    if (view === 'earnings') { show('scr-earnings'); loadEarnings(); }
    else { CURRENT = null; show('scr-home'); refresh(); }
}

/* ---------- shift ---------- */
function paintShift(status) {
    $('sh-online').className = status === 'online' ? 'on' : '';
    $('sh-break').className = status === 'break' ? 'brk' : '';
    $('sh-offline').className = status === 'offline' ? 'primary' : '';
    $('shift-dot').className = 'dot' + (status === 'online' ? ' on' : '');
}
async function setShift(status) {
    const r = await api('/shift', { method: 'POST', body: JSON.stringify({ status }) });
    if (r.ok) paintShift((await r.json()).shift_status);
}

/* ---------- offers + active jobs ---------- */
function startPolling() { clearInterval(pollTimer); pollTimer = setInterval(refresh, 10000); }
async function refresh() {
    if (!TOKEN || $('scr-home').style.display === 'none') return;
    try {
        const [meR, offR, actR] = await Promise.all([
            api('/me'), api('/offers'), api('/deliveries?active=1')]);
        if (meR.ok) { ME = await meR.json(); }
        paintShift(ME.shift_status || 'offline');
        if (offR.ok) renderOffers((await offR.json()).data || []);
        if (actR.ok) renderActive(((await actR.json()).data) || []);
    } catch (e) {}
}
function renderOffers(rows) {
    $('offers').innerHTML = rows.map(o => `
        <div class="card offer">
            <div><strong>${I18N.newOffer}</strong>
                 <span class="countdown" data-exp="${esc(o.expires_at)}"></span></div>
            <div class="addr">${esc(o.store || '')} → ${esc(o.address || '')}</div>
            <div class="money">${esc(o.total ?? '')}</div>
            <button class="accept" data-act="acceptOffer" data-args='[${o.id}]'>${I18N.accept}</button>
            <button class="reject" data-act="rejectOffer" data-args='[${o.id}]'>${I18N.reject}</button>
        </div>`).join('') || `<p class="muted" style="padding-inline:4px">${I18N.noOffers}</p>`;
    tickCountdowns();
}
function tickCountdowns() {
    document.querySelectorAll('.countdown').forEach(el => {
        const left = Math.max(0, Math.round((new Date(el.dataset.exp) - Date.now()) / 1000));
        el.textContent = ' · ' + left + 's';
    });
}
setInterval(tickCountdowns, 1000);
async function acceptOffer(id) {
    const r = await api('/offers/' + id + '/accept', { method: 'POST' });
    if (r.ok) { openOrder(await r.json()); } else { toast(I18N.offerGone); }
    refresh();
}
async function rejectOffer(id) { await api('/offers/' + id + '/reject', { method: 'POST' }); refresh(); }

function renderActive(rows) {
    $('active').innerHTML = rows.map(o => `
        <div class="card">
            <div><strong>#${o.id}</strong> · <span class="muted">${esc(o.delivery_status || '')}</span></div>
            <div class="addr">${esc(o.address || '')}</div>
            <button class="primary" data-act="openOrderById" data-args='[${o.id}]'>${I18N.open}</button>
        </div>`).join('');
}
async function openOrderById(id) {
    const r = await api('/deliveries?active=1');
    const rows = ((await r.json()).data) || [];
    const o = rows.find(x => x.id === id);
    if (o) openOrder(o);
}

/* ---------- order detail + status slider + proof ---------- */
const STEPS = [
    ['assigned', null], ['picked_up', 'pickedUp'], ['out_for_delivery', 'enRoute'], ['delivered', 'delivered']];
function openOrder(o) {
    CURRENT = o;
    const idx = Math.max(0, STEPS.findIndex(s => s[0] === o.delivery_status));
    const addr = encodeURIComponent(o.address || '');
    const cash = (o.is_paid ? 0 : (o.total ?? 0));
    const slider = STEPS.map(([st], i) => {
        const label = { assigned: I18N.assigned, picked_up: I18N.pickedUp,
                        out_for_delivery: I18N.enRoute, delivered: I18N.delivered }[st];
        if (i <= idx) return `<div class="step done">${label}</div>`;
        if (i === idx + 1) return `<button class="step next" data-act="setStatus" data-args='["${st}"]'>${label}</button>`;
        return `<div class="step">${label}</div>`;
    }).join('');
    const items = (o.items || []).map(it =>
        `<div class="muted">${it.quantity}× ${esc(it.name || '')}</div>`).join('');
    $('scr-order').innerHTML = `
        <button data-act="go" data-args='["home"]'>← ${I18N.back}</button>
        <div class="card">
            <div><strong>#${o.id}</strong> · <span class="muted">${esc(o.delivery_status || '')}</span></div>
            <div class="addr">${esc(o.address || '')}</div>
            ${items}
            <div class="money">${I18N.total} ${esc(o.total ?? '')}</div>
            ${cash > 0 ? `<div class="muted">${I18N.collect}: ${esc(cash)}</div>` : ''}
            <a class="btn" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=${addr}">${I18N.maps}</a>
            <a class="btn" target="_blank" rel="noopener" href="https://waze.com/ul?q=${addr}&navigate=yes">${I18N.waze}</a>
            ${o.phone_number ? `<a class="btn" href="tel:${esc(o.phone_number)}">${I18N.call}</a>` : ''}
        </div>
        <div class="card">
            <div class="slider">${slider}</div>
            <div id="proof" style="display:none">
                <strong>${I18N.proofTitle}</strong>
                <input id="pod-pin" inputmode="numeric" placeholder="${I18N.proofPin}" style="margin-top:8px">
                <button data-act="sendPin">${I18N.verifyPin}</button>
                <input id="pod-photo" type="file" accept="image/*" capture="environment"
                       style="display:none" data-change="sendPhoto" data-this>
                <button data-act="clickById" data-args='["pod-photo"]'>${I18N.proofPhoto}</button>
            </div>
            <button class="reject full" data-act="markFailed">${I18N.failed}</button>
        </div>`;
    show('scr-order');
}
async function setStatus(status, note) {
    if (!CURRENT) return;
    const q = await OUTBOX.send(API + '/deliveries/' + CURRENT.id + '/status', 'PUT',
        JSON.stringify({ delivery_status: status, note: note || undefined }));
    if (q.state === 'queued') { toast(I18N.notSent); return; }
    const r = q.response;
    if (r.status === 422) { // POD gate: show proof capture, keep the order open
        toast(I18N.podNeeded);
        $('proof').style.display = 'block';
        return;
    }
    if (r.ok) {
        const o = await r.json();
        if (['delivered', 'failed'].includes(o.delivery_status)) { go('home'); }
        else { openOrder(o); }
    }
}
function markFailed() {
    const reason = prompt(I18N.failReason);
    if (reason !== null) setStatus('failed', reason);
}
async function sendPin() {
    const r = await api('/deliveries/' + CURRENT.id + '/pod-otp',
        { method: 'POST', body: JSON.stringify({ otp: $('pod-pin').value.trim() }) });
    if (r.ok) { toast(I18N.pinOk); setStatus('delivered'); } else { toast(I18N.pinBad); }
}
async function sendPhoto(input) {
    if (!input.files.length) return;
    const fd = new FormData();
    fd.append('photo', input.files[0]);
    const r = await fetch(API + '/deliveries/' + CURRENT.id + '/pod-photo',
        { method: 'POST', headers: { 'Authorization': 'Bearer ' + TOKEN, 'Accept': 'application/json' }, body: fd });
    if (r.ok) { toast(I18N.photoSent); setStatus('delivered'); }
}

/* ---------- earnings ---------- */
async function loadEarnings() {
    const r = await api('/earnings');
    if (!r.ok) return;
    const j = await r.json();
    const rows = (j.data || []).map(d => `
        <div class="card">
            <div><strong>${esc(d.date)}</strong> · <span class="muted">${d.deliveries} ${I18N.earnToday}</span></div>
            <div class="money">${esc(d.total)}</div>
            ${d.tips > 0 ? `<div class="muted">${I18N.tips}: ${esc(d.tips)}</div>` : ''}
        </div>`).join('');
    $('scr-earnings').innerHTML = `
        <div class="card">
            <div class="muted">${(j.summary || {}).deliveries || 0} ${I18N.earnToday}</div>
            <div class="money">${esc((j.summary || {}).total ?? 0)}</div>
        </div>` + (rows || `<p class="muted">${I18N.noEarnings}</p>`);
}

/* ---------- GPS ping loop (reuses PUT /driver/location) ----------
   Web build: foreground getCurrentPosition every 30s while signed in + online.
   True background GPS needs the Capacitor wrapper (driver-app/README.md seam). */
function startGps() {
    clearInterval(gpsTimer);
    gpsTimer = setInterval(() => {
        if (!TOKEN || !navigator.geolocation) return;
        if (ME && ME.shift_status && ME.shift_status !== 'online') return;
        navigator.geolocation.getCurrentPosition(p => {
            api('/location', { method: 'PUT',
                body: JSON.stringify({ lat: p.coords.latitude, lng: p.coords.longitude }) }).catch(() => {});
        }, () => {}, { enableHighAccuracy: true, timeout: 10000 });
    }, 30000);
}

if ('serviceWorker' in navigator) navigator.serviceWorker.register(@json(route('driver.pwa.sw'))).catch(() => {});
boot();
</script>
</body>
</html>
