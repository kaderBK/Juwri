// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Contract §GET /health — the web side's feature detection.
 *
 * T1 caches this for at most 30 s and re-probes after any failed job, so the
 * shape has to be exactly right: `ok`, `version`, and a `printers` array whose
 * `status` is one of online|offline|unknown ("unknown is legal and common").
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { startBridge, printBody, authed, TARGET } = require('./helpers/harness');

test('/health returns the contract shape', async (t) => {
    const ctx = await startBridge({ version: '1.2.3' });
    t.after(() => ctx.close());

    const res = await ctx.request('/health', { headers: authed() });

    assert.equal(res.status, 200);
    assert.equal(res.json.ok, true);
    assert.equal(res.json.version, '1.2.3');
    assert.deepEqual(res.json.printers, [
        { id: TARGET, label: 'Kitchen', kind: 'network', status: 'unknown' },
    ]);
    assert.equal(res.headers['cache-control'], 'no-store');
});

test('a printer with no history reports unknown, not a fake online', async (t) => {
    const ctx = await startBridge({
        printers: [{ id: 'tcp://10.0.0.9:9100', label: 'Bar' }],
    });
    t.after(() => ctx.close());

    const res = await ctx.request('/health', { headers: authed() });
    assert.equal(res.json.printers[0].status, 'unknown');
    assert.equal(res.json.printers[0].kind, 'network');
});

test('status is learned from real jobs: online after a success, offline after a refusal', async (t) => {
    let fail = false;
    const ctx = await startBridge({
        transport: {
            print() {
                if (!fail) return Promise.resolve();
                const err = new Error('refused');
                err.code = 'ECONNREFUSED';

                return Promise.reject(err);
            },
        },
    });
    t.after(() => ctx.close());

    await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody({ jobId: 'j-ok' }) });
    let health = await ctx.request('/health', { headers: authed() });
    assert.equal(health.json.printers[0].status, 'online');

    fail = true;
    await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody({ jobId: 'j-bad' }) });
    health = await ctx.request('/health', { headers: authed() });
    assert.equal(health.json.printers[0].status, 'offline');
});

test('an empty printer list is valid — the shell may be paired before setup', async (t) => {
    const ctx = await startBridge({ printers: [] });
    t.after(() => ctx.close());

    const res = await ctx.request('/health', { headers: authed() });
    assert.equal(res.json.ok, true);
    assert.deepEqual(res.json.printers, []);
});

test('unknown paths 404 and wrong methods 405, both inside the error vocabulary', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const notFound = await ctx.request('/printers', { headers: authed() });
    assert.equal(notFound.status, 404);
    assert.equal(notFound.json.error, 'bad_request');

    const wrongMethod = await ctx.request('/health', { method: 'POST', headers: authed(), body: {} });
    assert.equal(wrongMethod.status, 405);
    assert.equal(wrongMethod.json.error, 'bad_request');
});

test('the bridge binds loopback only and refuses anything else', async (t) => {
    const { createBridgeServer } = require('../src/bridge/server');
    const bridge = createBridgeServer();
    t.after(() => bridge.close());

    await assert.rejects(() => bridge.listen({ port: 0, host: '0.0.0.0' }), /127\.0\.0\.1 only/);
});

test('a port clash fails loudly instead of drifting to another port', async (t) => {
    const { createBridgeServer } = require('../src/bridge/server');

    const first = createBridgeServer();
    const address = await first.listen({ port: 0, host: '127.0.0.1' });
    t.after(() => first.close());

    const second = createBridgeServer();
    t.after(() => second.close());

    // The web side has no discovery: a bridge that silently moved would simply
    // never be found again.
    await assert.rejects(
        () => second.listen({ port: address.port, host: '127.0.0.1' }),
        (err) => err.code === 'EADDRINUSE'
    );
});
