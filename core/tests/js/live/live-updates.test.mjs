// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SRC = readFileSync(new URL('../../../../assets/js/live-updates.js', import.meta.url), 'utf8');

/** Run the client in a fake browser; returns the sandbox plus handles on the fake socket / timers / fetches. */
function boot(config, authStatus = 200) {
    const sockets = [], fetches = [], timers = [];
    class FakeWS {
        constructor(url) { this.url = url; this.readyState = 1; this.sent = []; sockets.push(this); }
        send(m) { this.sent.push(JSON.parse(m)); }
        close() { this.onclose && this.onclose(); }
    }
    const win = {
        JuwriLiveConfig: config, addEventListener() {}, 
        WebSocket: FakeWS,
    };
    const sandbox = {
        window: win, WebSocket: FakeWS, URLSearchParams,
        document: { addEventListener() {}, hidden: false, querySelector: () => ({ getAttribute: () => 'csrf-token-value' }) },
        fetch: (url, opts) => { fetches.push({ url, opts }); return Promise.resolve({ status: authStatus, ok: authStatus === 200, json: () => Promise.resolve({ auth: 'key:sig' }) }); },
        setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
        clearTimeout: () => {}, JSON, Math, Object, parseInt, encodeURIComponent,
    };
    vm.createContext(sandbox);
    vm.runInContext(SRC, sandbox);
    return { win, sockets, fetches, timers };
}

const CFG = { key: 'k1', host: 'ws.example.test', port: 443, scheme: 'https', authUrl: 'https://app.test/broadcasting/auth' };
const msg = (ws, obj) => ws.onmessage({ data: JSON.stringify(obj) });
const flush = () => new Promise((r) => setImmediate(r));

test('does nothing without config (broadcasting off): no object, no socket', () => {
    const { win, sockets } = boot(undefined);
    assert.equal(win.JuwriLive, undefined);
    assert.equal(sockets.length, 0);
});

test('connects with the key, authorizes the private channel with the CSRF token, subscribes, delivers events', async () => {
    const { win, sockets, fetches, timers } = boot(CFG);
    const got = [];
    win.JuwriLive.listen(7, 'kds', 'kds.changed', (p) => got.push(p));
    assert.equal(sockets.length, 1);
    assert.match(sockets[0].url, /^wss:\/\/ws\.example\.test:443\/app\/k1\?protocol=7/);

    msg(sockets[0], { event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '11.22' }) });
    assert.equal(fetches.length, 1);
    assert.equal(fetches[0].url, CFG.authUrl);
    assert.equal(fetches[0].opts.headers['X-CSRF-TOKEN'], 'csrf-token-value');
    assert.equal(fetches[0].opts.body.get('channel_name'), 'private-store.7.kds');
    assert.equal(fetches[0].opts.body.get('socket_id'), '11.22');
    await flush();
    assert.deepEqual(sockets[0].sent.at(-1), { event: 'pusher:subscribe', data: { channel: 'private-store.7.kds', auth: 'key:sig' } });

    msg(sockets[0], { event: 'pusher:ping', data: '{}' });
    assert.equal(sockets[0].sent.at(-1).event, 'pusher:pong');

    msg(sockets[0], { event: 'kds.changed', channel: 'private-store.7.kds', data: JSON.stringify({ order_id: 5, status: 'new' }) });
    msg(sockets[0], { event: 'kds.changed', channel: 'private-store.7.kds', data: JSON.stringify({ order_id: 6, status: 'new' }) });
    // a burst runs the handler once, after the debounce
    const debounce = timers.filter((t) => t.ms === 400);
    assert.ok(debounce.length >= 1);
    debounce.at(-1).fn();
    assert.deepEqual(got, [{ order_id: 6, status: 'new' }]);
});

test('a refused channel (403) is never subscribed and never retried', async () => {
    const { win, sockets, fetches } = boot(CFG, 403);
    win.JuwriLive.listen(7, 'kds', 'kds.changed', () => {});
    msg(sockets[0], { event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '1.2' }) });
    await flush();
    assert.equal(sockets[0].sent.filter((m) => m.event === 'pusher:subscribe').length, 0);
    // reconnect cycle: still no second auth attempt for the denied channel
    sockets[0].onclose();
    assert.equal(fetches.length, 1);
});

test('a branch id authorizes+subscribes to the branch channel and never asks for the store channel', async () => {
    const { win, sockets, fetches } = boot(CFG);
    win.JuwriLive.listen(7, 'kds', 'kds.changed', () => {}, 42);
    assert.equal(sockets.length, 1);
    msg(sockets[0], { event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '11.22' }) });
    assert.equal(fetches.length, 1);
    assert.equal(fetches[0].opts.body.get('channel_name'), 'private-store.7.branch.42.kds');
    await flush();
    assert.deepEqual(sockets[0].sent.at(-1), { event: 'pusher:subscribe', data: { channel: 'private-store.7.branch.42.kds', auth: 'key:sig' } });
    // the store-wide channel is never requested
    assert.equal(fetches.filter((f) => f.opts.body.get('channel_name') === 'private-store.7.kds').length, 0);
    assert.equal(sockets[0].sent.filter((m) => m.data && m.data.channel === 'private-store.7.kds').length, 0);
});

test('an omitted, null or zero branch id keeps the old store channel', async () => {
    for (const branch of [undefined, null, 0]) {
        const { win, sockets, fetches } = boot(CFG);
        win.JuwriLive.listen(7, 'kds', 'kds.changed', () => {}, branch);
        msg(sockets[0], { event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '1.2' }) });
        await flush();
        assert.equal(fetches[0].opts.body.get('channel_name'), 'private-store.7.kds', 'branch=' + String(branch));
        assert.equal(sockets[0].sent.at(-1).data.channel, 'private-store.7.kds', 'branch=' + String(branch));
    }
});

test('a refused branch channel (403) is never subscribed and never retried', async () => {
    const { win, sockets, fetches } = boot(CFG, 403);
    win.JuwriLive.listen(7, 'kds', 'kds.changed', () => {}, 42);
    msg(sockets[0], { event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '1.2' }) });
    await flush();
    assert.equal(sockets[0].sent.filter((m) => m.event === 'pusher:subscribe').length, 0);
    // reconnect cycle: still no second auth attempt for the denied branch channel
    sockets[0].onclose();
    assert.equal(fetches.length, 1);
});

test('drops reconnect with growing backoff and give up after 8 failures (polling carries on, no throw)', () => {
    const { win, sockets, timers } = boot(CFG);
    win.JuwriLive.listen(7, 'orders', 'order.changed', () => {});
    const delays = [];
    for (let i = 0; i < 12; i++) {
        const ws = sockets.at(-1);
        ws.onclose();
        const t = timers.at(-1);
        if (!t || t.done) { break; }
        t.done = true; delays.push(t.ms); t.fn();
    }
    assert.ok(delays.length <= 8, 'gave up after at most 8 attempts, got ' + delays.length);
    assert.ok(delays[1] > delays[0] * 1.2 && delays[3] > delays[1], 'delays grow: ' + delays.join(','));
    assert.ok(delays.every((d) => d <= 60000 * 1.25));
    assert.equal(win.JuwriLive.connected(), false);
});

test('a handler that throws never breaks the screen', () => {
    const { win, sockets, timers } = boot(CFG);
    win.JuwriLive.listen(7, 'pos', 'pos.changed', () => { throw new Error('boom'); });
    msg(sockets[0], { event: 'pusher:connection_established', data: JSON.stringify({ socket_id: '1.2' }) });
    msg(sockets[0], { event: 'pos.changed', channel: 'private-store.7.pos', data: '{}' });
    assert.doesNotThrow(() => timers.filter((t) => t.ms === 400).at(-1).fn());
});

test('no external host, no eval, no inline handlers in the client', () => {
    assert.doesNotMatch(SRC, /https?:\/\//);
    assert.doesNotMatch(SRC, /\beval\(|new Function\(/);
});
