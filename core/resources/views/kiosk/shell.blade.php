{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Added to the storefront by KioskStorefrontShell for a kiosk browser session only: idle return + PIN-gated staff exit. --}}
<style nonce="{{ csp_nonce() }}">
    #kioskExit { position: fixed; bottom: 6px; right: 6px; z-index: 2147483000; opacity: .35; font-size: 12px; background: none; border: none; color: inherit; padding: 8px; }
    #kioskPin { position: fixed; inset: 0; z-index: 2147483001; background: rgba(0,0,0,.7); display: none; align-items: center; justify-content: center; }
    #kioskPin.on { display: flex; }
    #kioskPin .card { background: #0f172a; color: #fff; padding: 24px; border-radius: 12px; text-align: center; font-family: system-ui, sans-serif; }
    #kioskPin input { font-size: 28px; letter-spacing: .3em; text-align: center; padding: 6px; width: 220px; border-radius: 8px; border: 1px solid #334155; background: #020617; color: #fff; }
    #kioskPin button { margin: 12px 6px 0; padding: 8px 18px; font-size: 16px; border: none; border-radius: 8px; background: #22c55e; color: #06210f; }
    #kioskPin .err { color: #fca5a5; min-height: 1.2em; margin-top: 6px; }
</style>
<button type="button" id="kioskExit">{{ ___('Staff exit') }}</button>
<div id="kioskPin" role="dialog" aria-modal="true">
    <div class="card">
        <div>{{ ___('Staff exit PIN') }}</div>
        <input id="kioskPinInput" inputmode="numeric" type="password" autocomplete="off">
        <div class="err" id="kioskPinErr"></div>
        <button type="button" id="kioskPinOk">{{ ___('OK') }}</button>
        <button type="button" id="kioskPinCancel">{{ ___('Cancel') }}</button>
    </div>
</div>
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var SLUG = @json($slug), IDLE_MS = {{ $idleSeconds }} * 1000, timer;
    function post(path, pin) {
        return fetch('/' + SLUG + '/kiosk/' + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ pin: pin || '' }) }).then(function (r) { return r.json(); });
    }
    /* forget the customer: stored form data and the server-side cart, but keep this device's setup flag */
    function forget() {
        try {
            Object.keys(localStorage).forEach(function (k) { if (k.indexOf('kiosk_setup_') !== 0) { localStorage.removeItem(k); } });
            sessionStorage.clear();
        } catch (e) { /* storage blocked */ }
    }
    function toAttract() {
        forget();
        post('reset').catch(function () {}).then(function () { location.replace('/' + SLUG + '/kiosk'); });
    }
    function rearm() { clearTimeout(timer); timer = setTimeout(toAttract, IDLE_MS); }
    ['touchstart', 'pointerdown', 'mousedown', 'keydown', 'scroll', 'click'].forEach(function (ev) { document.addEventListener(ev, rearm, { passive: true, capture: true }); });
    rearm();

    var modal = document.getElementById('kioskPin'), input = document.getElementById('kioskPinInput'), err = document.getElementById('kioskPinErr');
    document.getElementById('kioskExit').addEventListener('click', function () { err.textContent = ''; input.value = ''; modal.classList.add('on'); input.focus(); });
    document.getElementById('kioskPinCancel').addEventListener('click', function () { modal.classList.remove('on'); });
    document.getElementById('kioskPinOk').addEventListener('click', function () {
        post('exit', input.value).then(function (res) {
            if (res && res.ok) { forget(); location.replace('/' + SLUG); }
            else { err.textContent = @json(___('Incorrect PIN')); }
        });
    });
})();
</script>
