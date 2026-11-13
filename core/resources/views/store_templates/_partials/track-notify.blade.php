{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- T5: in-page order notifications. On a genuine status CHANGE (the shared
     poller window.mbTrackPoll detects the delta vs the server-seeded status)
     the diner gets, with NO extra request and NO PII (the track JSON is
     status-only):
       - a document.title flash while the tab is backgrounded (restored on focus),
       - a navigator.vibrate pulse (no-op where unsupported),
       - and — strictly behind an explicit opt-in gesture — a Notification API
         notification (tag + renotify, click focuses the tab).
     A sound toggle is OFF by default (browsers block un-gestured audio anyway);
     the first enable click is the gesture that unlocks a short status-change
     chime from an existing global audio file — no new binary asset.
     Injected AFTER the 4-include block in all 17 themes; transparent background
     + color:inherit so it never paints over a theme
     (memory: shared-storefront-partial-placement). All inline JS is nonce'd. --}}
@php
    $__mbStore    = $store->name ?? ($store->title ?? '');
    $__mbTrackKey = 'mbnotify:'.($order->track_token ?? $order->id);
    $__mbChime    = asset('assets/global/audio/notification_message-notification-alert-7-331719.mp3');
    // P1: full Web Push (works with the tab closed) needs a configured VAPID
    // public key + a track token. When either is absent the button degrades to
    // the in-page Notification/title-flash path below (already shipped in T5).
    $__mbVapid    = (string) config('webpush.public_key');
    $__mbSubUrl   = ($order->track_token && $__mbVapid !== '') ? route('store.pushSubscribe', $order->track_token) : '';
@endphp
<div class="mb-notify" id="mbNotifyBar"
     style="display:flex;gap:14px;justify-content:center;align-items:center;flex-wrap:wrap;margin:16px auto 2px;background:transparent;color:inherit;">
    <button type="button" id="mbNotifyBtn" hidden
            style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;font:inherit;font-size:13px;font-weight:600;color:inherit;background:transparent;border:1.5px solid currentColor;border-radius:999px;opacity:.85;cursor:pointer;">
        <span aria-hidden="true">🔔</span>{{ ___('Notify me') }}
    </button>
    {{-- iOS has no web push in a Safari tab / in-app browser — only for a Home-Screen
         PWA (16.4+). When that is why the button can't show, offer that path instead. --}}
    <span id="mbNotifyIosHint" hidden
          style="display:inline-flex;align-items:center;gap:6px;font:inherit;font-size:12px;font-weight:500;color:inherit;opacity:.72;">
        <span aria-hidden="true">🔔</span>{{ ___('To get order alerts, tap Share then “Add to Home Screen”') }}
    </span>
    <label id="mbSoundWrap" for="mbSoundToggle"
           style="display:inline-flex;align-items:center;gap:7px;font:inherit;font-size:13px;font-weight:600;color:inherit;opacity:.85;cursor:pointer;">
        <input type="checkbox" id="mbSoundToggle" style="accent-color:currentColor;width:16px;height:16px;">
        <span aria-hidden="true">🔊</span>{{ ___('Sound') }}
    </label>
</div>
{{-- Screen-reader-only live region: announces the status word on change. --}}
<p id="mbNotifyLive" aria-live="polite" aria-atomic="true"
   style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;"></p>
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    if (!window.mbTrackPoll) { return; }

    var KEY    = @json($__mbTrackKey);
    var STORE  = @json($__mbStore);
    var CHIME  = @json($__mbChime);
    var VAPID  = @json($__mbVapid);              // P1 — '' when not configured
    var SUBURL = @json($__mbSubUrl);             // P1 — '' disables full push
    var last   = @json($order->status);          // server-seeded — first poll is not a false change
    var WORDS  = {
        pending_approval: @json(___('Awaiting approval')),
        new:              @json(___('Order received')),
        pending:          @json(___('Order received')),
        preparing:        @json(___('Preparing your order')),
        ready:            @json(___('Ready')),
        call_bill:        @json(___('Bill requested')),
        completed:        @json(___('Completed')),
        delivered:        @json(___('Delivered')),
        cancelled:        @json(___('Cancelled')),
    };
    var GENERIC = @json(___('Order updated'));

    var btn   = document.getElementById('mbNotifyBtn');
    var toggle = document.getElementById('mbSoundToggle');
    var live  = document.getElementById('mbNotifyLive');
    var hasNotify = ('Notification' in window);
    var iosHint = document.getElementById('mbNotifyIosHint');
    // iOS only exposes web push to an installed Home-Screen PWA (16.4+); in a
    // Safari tab or an in-app browser (Instagram/WhatsApp/…) the Notification API
    // is absent, so the button cannot render. Detect iPhone/iPad and whether the
    // page is already running standalone (where the hint would not help).
    var isIOS = /iP(hone|ad|od)/.test(navigator.userAgent || '')
        || (navigator.platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1);
    var isStandalone = (navigator.standalone === true)
        || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);

    // localStorage guarded — Safari private mode throws on access.
    function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

    // ── Notification API opt-in (behind the button = a user gesture) ──────────
    function refreshBtn() {
        if (!btn) { return; }
        // Show only while a decision is still possible + the API exists.
        btn.hidden = !(hasNotify && Notification.permission === 'default');
        // Offer Add-to-Home-Screen ONLY when that is precisely why the button is
        // gone: iOS, no Notification API, not already installed as a PWA.
        if (iosHint) { iosHint.hidden = !(!hasNotify && isIOS && !isStandalone); }
    }
    refreshBtn();
    if (btn) {
        btn.addEventListener('click', function () {
            if (!hasNotify) { return; }
            try {
                Notification.requestPermission().then(function (p) {
                    lsSet(KEY + ':notify', p);
                    refreshBtn();
                    if (p === 'granted') { subscribePush(); }   // P1: upgrade to closed-tab push when possible
                });
            } catch (e) { refreshBtn(); }
        });
    }

    // ── P1 full Web Push subscribe (closed-tab). Degrades silently to the
    //    in-page path when VAPID/SW/PushManager are unavailable. ───────────────
    function urlB64ToUint8Array(base64) {
        var padding = '='.repeat((4 - base64.length % 4) % 4);
        var b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(b64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
        return out;
    }
    function subscribePush() {
        if (!SUBURL || !VAPID) { return; }
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) { return; }
        navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription().then(function (existing) {
                return existing || reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlB64ToUint8Array(VAPID),
                });
            });
        }).then(function (sub) {
            var json = sub.toJSON();
            return fetch(SUBURL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') || {}).content || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
            });
        }).catch(function () {});   // any failure → in-page notifications still work
    }

    // ── Sound toggle (OFF by default; enable click unlocks autoplay) ──────────
    var soundOn = lsGet(KEY + ':sound') === '1';
    var chime = null;
    function makeChime() { try { return new Audio(CHIME); } catch (e) { return null; } }
    function playChime() {
        if (!soundOn) { return; }
        if (!chime) { chime = makeChime(); }
        if (chime) { try { chime.currentTime = 0; chime.play().catch(function () {}); } catch (e) {} }
    }
    if (toggle) {
        toggle.checked = soundOn;
        toggle.addEventListener('change', function () {
            soundOn = !!toggle.checked;
            lsSet(KEY + ':sound', soundOn ? '1' : '0');
            if (soundOn) { chime = makeChime(); playChime(); }   // this click is the gesture
        });
    }

    // ── Title flash (only while the tab is backgrounded) ──────────────────────
    var baseTitle = document.title, titleTimer = null;
    function stopFlash() { if (titleTimer) { clearInterval(titleTimer); titleTimer = null; document.title = baseTitle; } }
    function flashTitle(word) {
        if (document.hasFocus()) { return; }           // user is already looking
        stopFlash();
        var alt = '✅ ' + word + (STORE ? ' — ' + STORE : '');
        var n = 0;
        titleTimer = setInterval(function () {
            document.title = (n % 2 === 0) ? alt : baseTitle;
            if (++n >= 8 || document.hasFocus()) { stopFlash(); }
        }, 1000);
    }
    window.addEventListener('focus', stopFlash);

    function notify(word) {
        if (!hasNotify || Notification.permission !== 'granted') { return; }
        try {
            var nt = new Notification(word + (STORE ? ' — ' + STORE : ''), {
                body: @json(___('Your order status changed.')),
                tag: KEY, renotify: true
            });
            nt.onclick = function () { try { window.focus(); } catch (e) {} nt.close(); };
        } catch (e) {}
    }

    // ── The delta detector — one more mbTrackPoll subscriber ──────────────────
    window.mbTrackPoll(function (res) {
        var s = res && res.status;
        if (!s || s === last) { return; }              // only genuine changes fire
        last = s;
        var word = WORDS[s] || GENERIC;
        if (live) { live.textContent = word; }         // screen-reader announcement
        if (navigator.vibrate) { try { navigator.vibrate([80, 40, 80]); } catch (e) {} }
        flashTitle(word);
        notify(word);
        playChime();
    });
})();
</script>
