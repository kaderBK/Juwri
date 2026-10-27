/* SPDX-License-Identifier: AGPL-3.0-only */
/* Copyright (c) JUWRI LTD */
/*
 * P6-312: tiny outbox for the driver apps. A status tap that cannot reach the server (no signal, 5xx) is kept in
 * localStorage with an Idempotency-Key minted at tap time, shown as "not sent", and replayed in order when the
 * network is back. The server ignores a repeated key, so a replay never applies a status twice.
 * Answers below 500 (200, 401, 422 ...) are final: they are handed back to the caller and never queued.
 * The token is NOT stored; headers are rebuilt at replay time.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
    root.DriverOutbox = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var MAX = 50;

    function mintKey() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
        return 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    }

    function create(deps) {
        var storage = deps.storage;
        var doFetch = deps.fetch;
        var headers = deps.headers || function () { return {}; };
        var storeKey = deps.key || 'driver_outbox_v1';
        var onChange = deps.onChange || function () {};
        var newKey = deps.newKey || mintKey;
        var busy = false;

        function read() {
            try { var v = JSON.parse(storage.getItem(storeKey) || '[]'); return Array.isArray(v) ? v : []; }
            catch (e) { return []; }
        }
        function write(list) {
            try { storage.setItem(storeKey, JSON.stringify(list)); } catch (e) { /* storage full or blocked */ }
            onChange(list.length);
        }
        function attempt(entry) {
            var h = Object.assign({}, headers(), { 'Idempotency-Key': entry.key });
            return doFetch(entry.url, { method: entry.method, headers: h, body: entry.body });
        }
        function retryable(res) { return res.status >= 500; }

        /** Try now; on network failure or 5xx keep it. Resolves {state:'sent'|'queued', response?}. */
        async function send(url, method, body) {
            var entry = { key: newKey(), url: url, method: method, body: body === undefined ? undefined : String(body), at: Date.now() };
            // never jump ahead of taps that are still waiting
            if (read().length === 0) {
                try {
                    var res = await attempt(entry);
                    if (!retryable(res)) return { state: 'sent', response: res };
                } catch (e) { /* offline: fall through to the queue */ }
            }
            var list = read();
            list.push(entry);
            write(list.slice(-MAX));
            return { state: 'queued', id: entry.key };
        }

        /** Replay in order; stop at the first network failure. Resolves the number delivered. */
        async function flush() {
            if (busy) return 0;
            busy = true;
            var done = 0;
            try {
                var list = read();
                while (list.length) {
                    var res;
                    try { res = await attempt(list[0]); } catch (e) { break; }
                    if (retryable(res)) break;
                    list.shift();
                    done++;
                    write(list);
                    if (deps.onReplayed) deps.onReplayed(res);
                }
            } finally { busy = false; }
            return done;
        }

        return { send: send, flush: flush, pending: function () { return read().length; } };
    }

    return { create: create };
});
