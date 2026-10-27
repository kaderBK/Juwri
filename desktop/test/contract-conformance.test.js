// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Conformance against the literal examples in rev/parity/contracts/print-bridge.md.
 *
 * The other files test behaviour; this one tests the WIRE — the exact request T1
 * sends and the exact response shape it parses. It is the file to run first if
 * anyone ever suspects the two halves have drifted.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { startBridge, authed, TOKEN, ORIGIN } = require('./helpers/harness');
const { ERRORS } = require('../src/bridge/errors');

const CONTRACT_TARGET = 'tcp://192.168.1.50:9100';

test('the contract\'s own POST /print example is accepted verbatim', async (t) => {
    const ctx = await startBridge({
        printers: [{ id: CONTRACT_TARGET, label: 'Kitchen', kind: 'network', widthMm: 80 }],
    });
    t.after(() => ctx.close());

    // Straight out of the contract, including its jobId and target.
    const res = await ctx.request('/print', {
        method: 'POST',
        headers: authed(),
        body: {
            jobId: 'ord-91423-kitchen-1',
            target: CONTRACT_TARGET,
            widthMm: 80,
            dataB64: Buffer.from('\x1b@x', 'binary').toString('base64'),
        },
    });

    assert.equal(res.status, 200);
    assert.deepEqual(Object.keys(res.json).sort(), ['jobId', 'ok']);
    assert.deepEqual(res.json, { ok: true, jobId: 'ord-91423-kitchen-1' });
});

test('a failure carries ok, jobId, error and detail — the four keys T1 reads', async (t) => {
    const ctx = await startBridge({
        printers: [{ id: CONTRACT_TARGET, label: 'Kitchen' }],
        transport: {
            print() {
                const err = new Error('refused');
                err.code = 'ECONNREFUSED';
                err.detail = '192.168.1.50:9100';

                return Promise.reject(err);
            },
        },
    });
    t.after(() => ctx.close());

    const res = await ctx.request('/print', {
        method: 'POST',
        headers: authed(),
        body: { jobId: 'ord-91423-kitchen-1', target: CONTRACT_TARGET, widthMm: 80, dataB64: 'QQ==' },
    });

    assert.equal(res.status, 200, 'printer-level problems are NOT status codes');
    assert.deepEqual(res.json, {
        ok: false,
        jobId: 'ord-91423-kitchen-1',
        error: 'econnrefused',
        detail: '192.168.1.50:9100',
    });
});

test('the /health body matches the documented shape key for key', async (t) => {
    const ctx = await startBridge({
        printers: [
            { id: CONTRACT_TARGET, label: 'Kitchen', kind: 'network' },
            { id: 'usb://0x04b8/0x0e15', label: 'Receipt', kind: 'usb' },
        ],
        version: '1.0.0',
    });
    t.after(() => ctx.close());

    const res = await ctx.request('/health', { headers: authed() });

    assert.deepEqual(Object.keys(res.json).sort(), ['ok', 'paired', 'printers', 'version']);
    assert.equal(res.json.paired, true);
    for (const printer of res.json.printers) {
        assert.deepEqual(Object.keys(printer).sort(), ['id', 'kind', 'label', 'status']);
        assert.ok(['online', 'offline', 'unknown'].includes(printer.status));
        assert.ok(['network', 'usb'].includes(printer.kind));
    }
    assert.equal(res.json.printers[1].kind, 'usb');
});

test('every error the bridge can emit is inside the closed vocabulary', async (t) => {
    const ctx = await startBridge({ printers: [{ id: CONTRACT_TARGET, label: 'Kitchen' }] });
    t.after(() => ctx.close());

    const probes = [
        ['/health', { headers: { Origin: ORIGIN } }],                                        // no token
        ['/print', { method: 'POST', headers: authed(), body: 'garbage' }],                  // bad json
        ['/print', { method: 'POST', headers: authed(), body: { jobId: 'a', target: 'tcp://10.0.0.1:9100', widthMm: 80, dataB64: 'QQ==' } }],
        ['/nope', { headers: authed() }],
    ];

    for (const [path, init] of probes) {
        const res = await ctx.request(path, init);
        if (res.json && res.json.error) {
            assert.ok(ERRORS.includes(res.json.error), `${res.json.error} (${path}) is not in the vocabulary`);
        }
    }
});

test('nothing logs the pairing token or the ticket bytes', async (t) => {
    const lines = [];
    const record = (event, fields) => lines.push(`${event} ${JSON.stringify(fields)}`);

    const secretBytes = 'SUPER-SECRET-CUSTOMER-NAME';
    const ctx = await startBridge({
        printers: [{ id: CONTRACT_TARGET, label: 'Kitchen' }],
        logger: { info: record, warn: record, error: record },
    });
    t.after(() => ctx.close());

    await ctx.request('/print', {
        method: 'POST',
        headers: authed(),
        body: {
            jobId: 'ord-1-kitchen-1',
            target: CONTRACT_TARGET,
            widthMm: 80,
            dataB64: Buffer.from(secretBytes).toString('base64'),
        },
    });
    await ctx.request('/print', { method: 'POST', headers: { Authorization: `Bearer ${TOKEN}x`, Origin: ORIGIN }, body: {} });

    const log = lines.join('\n');
    assert.ok(log.length > 0, 'something was logged');
    assert.equal(log.includes(TOKEN), false, 'the token must never be logged');
    assert.equal(log.includes(secretBytes), false, 'ticket contents must never be logged');
    assert.equal(log.includes(Buffer.from(secretBytes).toString('base64')), false);
    assert.ok(log.includes('ord-1-kitchen-1'), 'the jobId IS logged — it is the operator\'s only handle');
});

test('the transport really is node-escpos\'s Network adapter', () => {
    // DECISION D2 names the library; if the dependency is ever dropped this test
    // fails rather than the bridge silently falling back to something else.
    const { defaultAdapterFactory } = require('../src/bridge/transport/network');
    const adapter = defaultAdapterFactory('127.0.0.1', 9100);

    assert.equal(adapter.constructor.name, 'Network');
    assert.equal(typeof adapter.open, 'function');
    assert.equal(typeof adapter.write, 'function');
    assert.equal(typeof adapter.close, 'function');
    adapter.close();
});
