// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — kiosk mode (THEME-CONTRACT §2.5, sections/kiosk.md). Runs only when `context.kiosk.on` (the kiosk route marked this
   browser session on the server). The theme draws the screens; this file wires them through `data-kiosk-*` hooks:

     idle      after `idle_seconds` without a touch the "Are you still there?" dialog opens ([data-kiosk-overlay="idle"]) with a 15 s
               visible countdown ([data-kiosk-left]) announced politely once; Continue ([data-kiosk-continue]) extends, Start over
               ([data-kiosk-startover]) or the end of the countdown clears the cart, the stored form data and returns to the start screen.
               Without that markup the older flow runs (a 10 s warning event, then the same reset).
     done      [data-kiosk-done] counts down data-seconds, then returns to the start screen; the idle timer never runs there.
     start     [data-kiosk-start]: a device that still needs its setup PIN ([data-kiosk-needs-setup]) asks for it first (server-verified).
     exit      a 3 s press on [data-kiosk-exit] (the store logo) opens the staff PIN pad ([data-kiosk-overlay="pin"]); the PIN is checked
               on the server (throttled) and only then is the kiosk left. Nothing else is a hidden exit.
     lock      links that leave the store, the back button and the context menu are blocked on the page; the server refuses every
               non-storefront route for a kiosk session anyway (KioskLock), this only avoids the dead ends.
     a11y      [data-kiosk-a11y] toggles `data-kiosk-a11y="on"` on <html> (bigger text, controls in the lower half; the theme CSS does it).

   Events on `document` (`juwri:` prefix): kiosk:idle {seconds}, kiosk:active, kiosk:reset {reason}, kiosk:exit, kiosk:print-failed (in). */
import { emit } from './util.js';

const EVENTS = ['pointerdown', 'keydown', 'touchstart', 'scroll', 'wheel'];
const WARN_SECONDS = 10; // the legacy warning (no dialog markup)
const DIALOG_SECONDS = 15; // sections/kiosk.md: "a 15 s countdown"
const EXIT_PRESS_MS = 3000;
const MAX_PIN = 8;

export function startKiosk({
    kiosk, cart, home, announce, text = (k, d) => d, doc = globalThis.document, endpoints = {}, slug = '', queueEnabled = true,
    nav = (url) => { globalThis.location.assign(url); },
    fetchFn = (...a) => globalThis.fetch(...a),
    storage = () => ({ local: globalThis.localStorage, session: globalThis.sessionStorage }),
    // wrapped: a browser's setTimeout throws "Illegal invocation" when called as a method of this object
    timers = { set: (f, ms) => setTimeout(f, ms), clear: (t) => clearTimeout(t), tick: (f, ms) => setInterval(f, ms), untick: (t) => clearInterval(t) },
    win = globalThis,
}) {
    if (!kiosk || !kiosk.on) return null;
    const idleMs = Math.max(15, Number(kiosk.idle_seconds) || 60) * 1000;
    const start = endpoints.kiosk_start || home || '/';
    const q = (sel, root = doc) => (root && root.querySelector ? root.querySelector(sel) : null);
    const qa = (sel, root = doc) => (root && root.querySelectorAll ? Array.from(root.querySelectorAll(sel)) : []);
    const onStart = !!q('[data-kiosk-start]');
    const onDone = !!q('[data-kiosk-done]');
    const idleDialog = q('[data-kiosk-overlay="idle"]');
    const pinOverlay = q('[data-kiosk-overlay="pin"]');

    let idle = null;
    let tick = null;
    let left = 0;
    let warning = false;
    let stopped = false;
    let openOverlay = null; // { el, back }
    const cleanup = [];
    const on = (target, ev, fn, opts) => {
        if (!target || !target.addEventListener) return;
        target.addEventListener(ev, fn, opts);
        cleanup.push(() => target.removeEventListener && target.removeEventListener(ev, fn, opts));
    };

    // ---- forgetting the customer --------------------------------------------------------------------------------------------
    function forget() {
        try {
            const s = storage();
            for (const k of Object.keys(s.local || {})) if (k.indexOf('kiosk_setup_') !== 0 && s.local.removeItem) s.local.removeItem(k);
            if (s.session && s.session.clear) s.session.clear();
        } catch (e) { /* storage blocked */ }
        try { doc.documentElement && doc.documentElement.removeAttribute('data-kiosk-a11y'); } catch (e) { /* no DOM */ }
    }

    function csrf() {
        const m = q('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function post(url, body) {
        return fetchFn(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(body || {}),
        }).then((r) => r.json().then((j) => ({ status: r.status, json: j }), () => ({ status: r.status, json: {} })));
    }

    function reset(reason) {
        emit('kiosk:reset', { reason });
        const done = () => { forget(); nav(start); };
        const server = () => (endpoints.kiosk_reset ? post(endpoints.kiosk_reset).catch(() => {}) : null);
        const p = cart && cart.clear ? cart.clear() : null;
        const after = () => { const s = server(); if (s && s.then) s.then(done, done); else done(); };
        if (p && typeof p.then === 'function') p.then(after, after); else after();
    }

    // ---- overlays (idle dialog, PIN pad): page behind inert, focus kept inside, restored on close ---------------------------
    function focusables(el) {
        return qa('button, [href], input, [tabindex]:not([tabindex="-1"])', el).filter((n) => !n.hidden && !n.disabled);
    }
    function show(el) {
        if (!el) return;
        const page = q('[data-kit-page]');
        if (page && page.setAttribute) page.setAttribute('inert', '');
        el.hidden = false;
        openOverlay = { el, back: doc.activeElement || null };
        const title = q('[tabindex="-1"]', el);
        const first = title || focusables(el)[0];
        if (first && first.focus) first.focus();
    }
    function hide() {
        if (!openOverlay) return;
        const { el, back } = openOverlay;
        el.hidden = true;
        const page = q('[data-kit-page]');
        if (page && page.removeAttribute) page.removeAttribute('inert');
        openOverlay = null;
        if (back && back.focus) back.focus();
    }
    on(doc, 'keydown', (e) => {
        if (!openOverlay || e.key !== 'Tab') return;
        const list = focusables(openOverlay.el);
        if (!list.length) return;
        const i = list.indexOf(doc.activeElement);
        const next = e.shiftKey ? (i <= 0 ? list.length - 1 : i - 1) : (i === list.length - 1 ? 0 : i + 1);
        e.preventDefault();
        list[next].focus();
    }, true);

    // ---- idle ---------------------------------------------------------------------------------------------------------------
    function stopTick() { if (tick) { timers.untick(tick); tick = null; } }

    function setLeft(n) {
        for (const el of qa('[data-kiosk-left]', idleDialog || doc)) el.textContent = String(n);
    }

    function warn() {
        if (stopped || onStart || onDone || (openOverlay && openOverlay.el === pinOverlay)) { arm(); return; }
        warning = true;
        left = idleDialog ? DIALOG_SECONDS : WARN_SECONDS;
        emit('kiosk:idle', { seconds: left });
        announce(text('still_there_starting_over_in_n_seconds', 'Still there? Starting over in :n seconds').replace(':n', String(left)));
        if (idleDialog) { setLeft(left); show(idleDialog); }
        tick = timers.tick(() => {
            left -= 1;
            emit('kiosk:idle', { seconds: left });
            if (idleDialog) setLeft(left);
            if (left <= 0) { stopTick(); warning = false; reset('idle'); }
        }, 1000);
    }

    function arm() {
        if (idle) timers.clear(idle);
        if (onStart || onDone) return;
        idle = timers.set(warn, idleMs);
    }

    function activity() {
        if (warning && idleDialog) return; // only Continue / Start over answer the dialog
        if (tick) { stopTick(); warning = false; emit('kiosk:active', {}); }
        arm();
    }

    function keepGoing() {
        stopTick(); warning = false; emit('kiosk:active', {});
        hide();
        arm();
    }

    for (const ev of EVENTS) on(doc, ev, activity, { passive: true, capture: true });
    on(doc, 'click', (e) => {
        const t = e.target && e.target.closest ? e.target : null;
        if (!t) return;
        if (t.closest('[data-kiosk-continue]')) keepGoing();
        else if (t.closest('[data-kiosk-startover]')) { stopTick(); warning = false; hide(); reset('start_over'); }
    });

    // ---- the lock: links, back, context menu --------------------------------------------------------------------------------
    on(doc, 'click', (e) => {
        const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        const href = a.getAttribute('href') || '';
        if (href.charAt(0) === '#') {
            // an in-page link (skip link, category rail) moves focus itself: a fragment navigation would fire `popstate`, which the
            // back lock below answers with a pushState that drops the focus starting point (the skip link then breaks)
            const target = href.length > 1 && doc.getElementById ? doc.getElementById(decodeURIComponent(href.slice(1))) : null;
            if (target) {
                e.preventDefault();
                if (!target.hasAttribute('tabindex') && target.setAttribute) target.setAttribute('tabindex', '-1');
                if (target.scrollIntoView) target.scrollIntoView();
                if (target.focus) target.focus({ preventScroll: true });
            }
            return;
        }
        if (/^(?:\/|\.)/.test(href) && !/^\/\//.test(href) && a.getAttribute('target') !== '_blank') return;
        e.preventDefault(); // another host, a new window, mailto:, tel:, javascript:
    }, true);
    on(doc, 'contextmenu', (e) => e.preventDefault());
    if (win.history && win.history.pushState && win.addEventListener) {
        try {
            win.history.pushState({ kiosk: true }, '', win.location.href);
            on(win, 'popstate', () => { win.history.pushState({ kiosk: true }, '', win.location.href); });
        } catch (e) { /* no history */ }
    }

    // ---- accessibility button -----------------------------------------------------------------------------------------------
    const a11y = q('[data-kiosk-a11y]');
    if (a11y) {
        on(a11y, 'click', () => {
            const html = doc.documentElement;
            const onNow = html.getAttribute('data-kiosk-a11y') === 'on';
            if (onNow) html.removeAttribute('data-kiosk-a11y'); else html.setAttribute('data-kiosk-a11y', 'on');
            a11y.setAttribute('aria-pressed', onNow ? 'false' : 'true');
        });
    }

    // ---- the staff PIN pad (exit, and the one-time device setup) -------------------------------------------------------------
    let mode = 'exit';
    let pin = '';
    const dots = pinOverlay ? q('[data-kiosk-pin-dots]', pinOverlay) : null;
    const err = pinOverlay ? q('[data-kiosk-pin-error]', pinOverlay) : null;
    const title = pinOverlay ? q('[data-kiosk-pin-title]', pinOverlay) : null;
    const submit = pinOverlay ? q('[data-kiosk-pin-submit]', pinOverlay) : null;
    const setupKey = 'kiosk_setup_' + slug;

    function paintPin() {
        if (!dots) return;
        const total = Math.max(4, pin.length);
        while (dots.children.length < total) {
            const d = doc.createElement('span'); d.className = 'c-pin__dot'; dots.appendChild(d);
        }
        Array.from(dots.children).forEach((d, i) => d.classList.toggle('c-pin__dot--on', i < pin.length));
        dots.setAttribute('aria-label', text('count_of_total_digits_entered', ':count of :total digits entered').replace(':count', String(pin.length)).replace(':total', String(total)));
    }
    function openPin(m) {
        mode = m; pin = ''; paintPin();
        if (err) err.textContent = '';
        if (title) title.textContent = m === 'setup' ? text('device_setup_pin', 'Device setup PIN') : text('enter_the_staff_pin', 'Enter the staff PIN');
        if (submit) { const label = submit.querySelector && submit.querySelector('span'); (label || submit).textContent = m === 'setup' ? text('continue', 'Continue') : text('exit_kiosk', 'Exit kiosk'); }
        show(pinOverlay);
    }
    function closePin() { pin = ''; hide(); }

    async function submitPin() {
        if (!pin) return;
        const url = mode === 'setup' ? endpoints.kiosk_setup : endpoints.kiosk_exit;
        if (!url) return;
        let res;
        try { res = await post(url, { pin }); } catch (e) { res = { status: 0, json: {} }; }
        if (res.json && res.json.ok) {
            if (mode === 'setup') {
                try { storage().local.setItem(setupKey, '1'); } catch (e) { /* blocked */ }
                setupDone();
                closePin();
            } else {
                emit('kiosk:exit', {});
                forget();
                nav('/' + slug);
            }
            return;
        }
        pin = ''; paintPin();
        if (err) {
            err.textContent = res.status === 429 || (res.json && res.json.throttled)
                ? text('too_many_attempts_wait_a_moment', 'Too many attempts. Wait a moment.')
                : text('incorrect_pin', 'Incorrect PIN');
        }
    }

    if (pinOverlay) {
        on(pinOverlay, 'click', (e) => {
            const t = e.target && e.target.closest ? e.target : null;
            if (!t) return;
            const key = t.closest('[data-key]');
            if (key) { if (pin.length < MAX_PIN) { pin += key.getAttribute('data-key'); paintPin(); if (err) err.textContent = ''; } }
            else if (t.closest('[data-kiosk-pin-delete]')) { pin = pin.slice(0, -1); paintPin(); }
            else if (t.closest('[data-kiosk-pin-cancel]')) closePin();
            else if (t.closest('[data-kiosk-pin-submit]')) submitPin();
        });
        on(doc, 'keydown', (e) => {
            if (!openOverlay || openOverlay.el !== pinOverlay) return;
            if (/^[0-9]$/.test(e.key) && pin.length < MAX_PIN) { pin += e.key; paintPin(); }
            else if (e.key === 'Backspace') { pin = pin.slice(0, -1); paintPin(); }
            else if (e.key === 'Enter') { e.preventDefault(); submitPin(); }
            else if (e.key === 'Escape') closePin();
        });
    }

    // a 3 s press on the logo opens the exit pad
    let press = null;
    const clearPress = () => { if (press) { timers.clear(press); press = null; } };
    if (pinOverlay) {
        on(doc, 'pointerdown', (e) => {
            const t = e.target && e.target.closest ? e.target.closest('[data-kiosk-exit]') : null;
            if (!t) return;
            clearPress();
            press = timers.set(() => { press = null; openPin('exit'); }, EXIT_PRESS_MS);
        }, true);
        for (const ev of ['pointerup', 'pointercancel', 'dragstart']) on(doc, ev, clearPress, true);
    }

    // ---- start screen: device setup gate, offline banner -------------------------------------------------------------------
    const go = q('[data-kiosk-go]');
    let needsSetup = false;
    function setupDone() {
        needsSetup = false;
        if (go) go.removeAttribute('aria-disabled');
    }
    if (onStart) {
        let ok = true;
        try { ok = storage().local.getItem(setupKey) === '1'; } catch (e) { ok = false; }
        if (q('[data-kiosk-needs-setup]') && !ok) {
            needsSetup = true;
            if (go) go.setAttribute('aria-disabled', 'true');
            if (pinOverlay) openPin('setup');
        }
        if (go) on(go, 'click', (e) => { if (needsSetup) { e.preventDefault(); if (pinOverlay) openPin('setup'); } });
        const banner = q('[data-kiosk-offline]');
        const paintOnline = () => {
            if (!banner) return;
            const off = win.navigator && win.navigator.onLine === false;
            banner.hidden = !off;
            const label = banner.querySelector && banner.querySelector('span');
            if (off && label) label.textContent = queueEnabled
                ? text('connection_lost_pay_at_the_counter_to_order_now', 'Connection lost — pay at the counter to order now')
                : text('ordering_is_paused_until_the_connection_returns', 'Ordering is paused until the connection returns');
            if (go) { if (off && !queueEnabled) go.setAttribute('aria-disabled', 'true'); else if (!needsSetup) go.removeAttribute('aria-disabled'); }
        };
        if (win.addEventListener) { on(win, 'online', paintOnline); on(win, 'offline', paintOnline); }
        paintOnline();
        if (go) on(go, 'click', (e) => { if (go.getAttribute('aria-disabled') === 'true') e.preventDefault(); });
    }

    // ---- done screen: a visible countdown, then back to the start screen ---------------------------------------------------
    if (onDone) {
        const done = q('[data-kiosk-done]');
        let n = Math.max(3, Number(done.getAttribute('data-seconds')) || 10);
        const target = done.getAttribute('data-start') || start;
        const paint = () => { for (const el of qa('[data-kiosk-left]', done)) el.textContent = String(n); };
        paint();
        tick = timers.tick(() => {
            n -= 1;
            paint();
            if (n <= 0) { stopTick(); forget(); nav(target); }
        }, 1000);
        on(doc, 'juwri:kiosk:print-failed', () => {
            const ok = q('[data-kiosk-receipt]', done); const bad = q('[data-kiosk-print-failed]', done);
            if (ok) ok.hidden = true;
            if (bad) bad.hidden = false;
        });
        const now = q('[data-kiosk-startnow]', done);
        if (now) on(now, 'click', () => { stopTick(); forget(); });
    }

    arm();
    return {
        activity,
        stop() {
            stopped = true;
            for (const ev of EVENTS) doc.removeEventListener && doc.removeEventListener(ev, activity, true);
            for (const fn of cleanup) fn();
            if (idle) timers.clear(idle);
            clearPress();
            stopTick();
        },
    };
}
