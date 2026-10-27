// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Live updates for the orders list, KDS and POS (Laravel Reverb, Pusher protocol 7).
 *
 * A tiny dependency-free client (the app has no JS build step, and the dashboard CSP allows no third-party script).
 * It is purely an accelerator: every screen keeps its own polling, so when the socket is off, blocked, refused or
 * dropped the screen behaves exactly as before and nothing is shown to the user. A "ping" from the server only makes
 * the screen run its normal refresh sooner; payloads carry ids and a status, never customer data.
 *
 *   JuwriLive.listen(storeId, 'kds', 'kds.changed', function (payload) { refresh(); });
 *
 * Does nothing unless the page set window.JuwriLiveConfig (the server only does that while Reverb is switched on).
 */
(function () {
    'use strict';
    var cfg = window.JuwriLiveConfig;
    if (!cfg || !cfg.key || !cfg.host || window.JuwriLive || typeof WebSocket === 'undefined') { return; }

    var MAX_ATTEMPTS = 8;          // consecutive failed connects before we stop trying (polling carries on)
    var BASE_DELAY = 1000;         // exponential backoff: 1s, 2s, 4s ... capped
    var MAX_DELAY = 60000;
    var DEBOUNCE_MS = 400;         // a burst of events triggers one refresh

    var ws = null, socketId = null, attempts = 0, timer = null, stopped = false, connected = false;
    var subs = {};                 // channel -> { events: { name: [handlers] }, state: 'idle'|'auth'|'ok'|'denied' }

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function url() {
        var proto = cfg.scheme === 'https' ? 'wss' : 'ws';
        return proto + '://' + cfg.host + (cfg.port ? ':' + cfg.port : '') + '/app/' + encodeURIComponent(cfg.key) +
            '?protocol=7&client=juwri-live&version=1&flash=false';
    }

    function send(obj) { try { ws.send(JSON.stringify(obj)); } catch (e) { /* socket closing */ } }

    function authorize(channel) {
        var sub = subs[channel];
        if (!sub || sub.state === 'auth' || sub.state === 'ok' || sub.state === 'denied' || !socketId) { return; }
        sub.state = 'auth';
        var body = new URLSearchParams();
        body.set('socket_id', socketId);
        body.set('channel_name', channel);
        fetch(cfg.authUrl, {
            method: 'POST', credentials: 'same-origin', body: body,
            headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (res) {
            if (res.status === 403 || res.status === 404 || res.status === 401) { sub.state = 'denied'; return null; } // not allowed / off: never retry
            if (!res.ok) { sub.state = 'idle'; return null; }
            return res.json();
        }).then(function (data) {
            if (!data || !data.auth) { if (sub.state === 'auth') { sub.state = 'idle'; } return; }
            if (ws && ws.readyState === 1) { send({ event: 'pusher:subscribe', data: { channel: channel, auth: data.auth } }); }
            else { sub.state = 'idle'; }
        }).catch(function () { sub.state = 'idle'; });
    }

    function onMessage(ev) {
        var msg;
        try { msg = JSON.parse(ev.data); } catch (e) { return; }
        var name = msg.event;
        if (name === 'pusher:connection_established') {
            try { socketId = JSON.parse(msg.data).socket_id; } catch (e) { return; }
            connected = true; attempts = 0;
            Object.keys(subs).forEach(function (c) { subs[c].state = subs[c].state === 'denied' ? 'denied' : 'idle'; authorize(c); });
        } else if (name === 'pusher:ping') {
            send({ event: 'pusher:pong', data: {} });
        } else if (name === 'pusher_internal:subscription_succeeded') {
            if (subs[msg.channel]) { subs[msg.channel].state = 'ok'; }
        } else if (msg.channel && subs[msg.channel]) {
            var list = subs[msg.channel].events[name];
            if (!list) { return; }
            var payload = {};
            try { payload = typeof msg.data === 'string' ? JSON.parse(msg.data) : (msg.data || {}); } catch (e) { /* ids only; ignore */ }
            list.forEach(function (h) { h.fire(payload); });
        }
    }

    function schedule() {
        if (stopped || timer) { return; }
        if (attempts >= MAX_ATTEMPTS) { return; } // give up quietly; a visible/online event below re-arms it
        var delay = Math.min(MAX_DELAY, BASE_DELAY * Math.pow(2, attempts)) * (0.75 + Math.random() * 0.5);
        attempts++;
        timer = setTimeout(function () { timer = null; open(); }, delay);
    }

    function open() {
        if (stopped || ws || !Object.keys(subs).length) { return; }
        try { ws = new WebSocket(url()); } catch (e) { ws = null; schedule(); return; }
        ws.onmessage = onMessage;
        ws.onclose = ws.onerror = function () {
            if (!ws) { return; }
            ws = null; socketId = null; connected = false;
            Object.keys(subs).forEach(function (c) { if (subs[c].state !== 'denied') { subs[c].state = 'idle'; } });
            schedule();
        };
    }

    // Re-arm after giving up when the tab comes back or the network returns.
    function rearm() { if (!ws && !timer && !stopped) { attempts = Math.min(attempts, MAX_ATTEMPTS - 3); schedule(); open(); } }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { rearm(); } });
    window.addEventListener('online', rearm);

    window.JuwriLive = {
        connected: function () { return connected; },
        /**
         * Run handler (debounced) when `eventName` arrives on private channel store.{storeId}.{screen}, or, when
         * branchId is a positive integer, on store.{storeId}.branch.{branchId}.{screen}. Omitted/null/0 = the store channel.
         */
        listen: function (storeId, screen, eventName, handler, branchId) {
            var branch = parseInt(branchId, 10);
            var scope = branch > 0 ? '.branch.' + branch : '';
            var channel = 'private-store.' + parseInt(storeId, 10) + scope + '.' + screen;
            var sub = subs[channel] || (subs[channel] = { events: {}, state: 'idle' });
            var t = null;
            var h = { fire: function (payload) { clearTimeout(t); t = setTimeout(function () { try { handler(payload); } catch (e) { /* never break the screen */ } }, DEBOUNCE_MS); } };
            (sub.events[eventName] = sub.events[eventName] || []).push(h);
            if (connected) { authorize(channel); } else { open(); }
        }
    };
})();
