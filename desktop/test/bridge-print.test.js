// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Contract §POST /print — the request schema, the closed error vocabulary and
 * the status-code split.
 *
 * The split is the load-bearing part: HTTP status codes are reserved for
 * PROTOCOL problems (400/401/409/413/500), while printer-level problems come
 * back as HTTP 200 with `ok:false` so the register can show "the kitchen printer
 * is unplugged" instead of treating it as a transport bug and retrying forever.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { startBridge, printBody, authed, TARGET } = require('./helpers/harness');
const { ERRORS } = require('../src/bridge/errors');
const { parseTarget } = require('../src/bridge/targets');

test('the happy path answers {ok:true,jobId} and hands the decoded bytes to the transport', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const raw = Buffer.from([0x1b, 0x40, 0x48, 0x49, 0x0a, 0x1d, 0x56, 0x42, 0x00]);
    const res = await ctx.request('/print', {
        method: 'POST',
        headers: authed(),
        body: printBody({ dataB64: raw.toString('base64') }),
    });

    assert.equal(res.status, 200);
    assert.deepEqual(res.json, { ok: true, jobId: 'ord-91423-kitchen-1' });

    assert.equal(ctx.transport.calls.length, 1);
    assert.deepEqual(ctx.transport.calls[0].bytes, raw, 'the bytes must reach the printer unmodified');
    assert.equal(ctx.transport.calls[0].target.id, TARGET);
    assert.equal(ctx.transport.calls[0].meta.widthMm, 80);
});

test('57 mm is accepted and forwarded', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const res = await ctx.request('/print', {
        method: 'POST', headers: authed(), body: printBody({ widthMm: 57 }),
    });

    assert.equal(res.json.ok, true);
    assert.equal(ctx.transport.calls[0].meta.widthMm, 57);
});

test('a malformed body is 400 bad_request and never reaches a printer', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const cases = [
        ['not json at all', 'x-not-json'],
        ['a JSON array', JSON.stringify([1, 2, 3])],
        ['a JSON scalar', '"hello"'],
        ['no jobId', JSON.stringify(printBody({ jobId: undefined }))],
        ['an empty jobId', JSON.stringify(printBody({ jobId: '   ' }))],
        ['a jobId over 64 chars', JSON.stringify(printBody({ jobId: 'j'.repeat(65) }))],
        ['a jobId with a newline', JSON.stringify(printBody({ jobId: 'ord-1\nfake log line' }))],
        ['a numeric jobId', JSON.stringify(printBody({ jobId: 42 }))],
        ['no target', JSON.stringify(printBody({ target: undefined }))],
        ['an http target', JSON.stringify(printBody({ target: 'http://192.168.1.50:9100' }))],
        ['a bare hostname', JSON.stringify(printBody({ target: '192.168.1.50' }))],
        ['a width of 72', JSON.stringify(printBody({ widthMm: 72 }))],
        ['no width', JSON.stringify(printBody({ widthMm: undefined }))],
        ['no payload', JSON.stringify(printBody({ dataB64: undefined }))],
        ['an empty payload', JSON.stringify(printBody({ dataB64: '' }))],
        ['a payload that is not base64', JSON.stringify(printBody({ dataB64: 'not base64!!' }))],
    ];

    for (const [label, body] of cases) {
        const res = await ctx.request('/print', { method: 'POST', headers: authed(), body });
        assert.equal(res.status, 400, label);
        assert.equal(res.json.error, 'bad_request', label);
    }

    assert.equal(ctx.transport.calls.length, 0);
});

test('a well-formed target nobody configured is unknown_target at HTTP 200', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const res = await ctx.request('/print', {
        method: 'POST', headers: authed(), body: printBody({ target: 'tcp://10.9.9.9:9100' }),
    });

    // 200 + ok:false: the operator can fix this (add the printer); it is not a
    // transport bug, and the client must not retry it blindly.
    assert.equal(res.status, 200);
    assert.equal(res.json.ok, false);
    assert.equal(res.json.error, 'unknown_target');
    assert.equal(res.json.jobId, 'ord-91423-kitchen-1');
    assert.equal(res.json.detail, 'tcp://10.9.9.9:9100');
    assert.equal(ctx.transport.calls.length, 0);
});

test('the bridge is not an open TCP proxy: an unconfigured LAN host cannot be written to', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    // With a valid token from the store's own page, an unconfigured host must
    // still be refused — otherwise the bridge is a "write arbitrary bytes to any
    // host on the operator's network" primitive.
    for (const target of ['tcp://192.168.1.1:22', 'tcp://127.0.0.1:8543', 'tcp://10.0.0.5:9100']) {
        const res = await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody({ target }) });
        assert.equal(res.json.error, 'unknown_target', target);
    }

    assert.equal(ctx.transport.calls.length, 0);
});

test('a payload over 512 KB decoded is 413 payload_too_large', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const tooBig = Buffer.alloc(512 * 1024 + 1, 0x41).toString('base64');
    const res = await ctx.request('/print', {
        method: 'POST', headers: authed(), body: printBody({ dataB64: tooBig }),
    });

    assert.equal(res.status, 413);
    assert.equal(res.json.error, 'payload_too_large');
    assert.equal(ctx.transport.calls.length, 0);
});

test('exactly 512 KB decoded still prints — the limit is inclusive', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const atLimit = Buffer.alloc(512 * 1024, 0x41).toString('base64');
    const res = await ctx.request('/print', {
        method: 'POST', headers: authed(), body: printBody({ dataB64: atLimit }),
    });

    assert.equal(res.status, 200);
    assert.equal(res.json.ok, true);
    assert.equal(ctx.transport.calls[0].bytes.length, 512 * 1024);
});

test('an oversized raw body is refused without being buffered', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const res = await ctx.request('/print', {
        method: 'POST',
        headers: authed(),
        body: 'x'.repeat(900 * 1024),
    });

    assert.equal(res.status, 413);
    assert.equal(res.json.error, 'payload_too_large');
});

test('printer failures map onto the closed vocabulary, at HTTP 200', async (t) => {
    const failures = [
        ['ECONNREFUSED', 'econnrefused'],
        ['ETIMEDOUT', 'etimedout'],
        ['EHOSTUNREACH', 'econnrefused'],
        ['EBUSY', 'device_busy'],
        ['ESOMETHINGWEIRD', 'internal'],
    ];

    for (const [code, expected] of failures) {
        const ctx = await startBridge({
            transport: {
                print() {
                    const err = new Error(code);
                    err.code = code;

                    return Promise.reject(err);
                },
            },
        });

        const res = await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });

        assert.equal(res.status, 200, code);
        assert.equal(res.json.ok, false, code);
        assert.equal(res.json.error, expected, code);
        assert.ok(ERRORS.includes(res.json.error), `${res.json.error} is outside the closed vocabulary`);
        assert.equal(res.json.jobId, 'ord-91423-kitchen-1');

        await ctx.close();
    }
});

test('a transport that throws synchronously becomes internal, not a 500', async (t) => {
    const ctx = await startBridge({
        transport: {
            print() {
                throw new Error('boom');
            },
        },
    });
    t.after(() => ctx.close());

    const res = await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });

    assert.equal(res.status, 200);
    assert.equal(res.json.error, 'internal');
});

test('target parsing accepts exactly what the contract lists', () => {
    assert.deepEqual(parseTarget('tcp://192.168.1.50:9100'), {
        kind: 'network', id: 'tcp://192.168.1.50:9100', host: '192.168.1.50', port: 9100,
    });
    assert.equal(parseTarget('tcp://192.168.1.50').id, 'tcp://192.168.1.50:9100', 'port defaults to 9100');
    assert.equal(parseTarget('usb').kind, 'usb');
    assert.equal(parseTarget('usb://0x04b8/0x0e15').id, 'usb://0x04b8/0x0e15');

    for (const bad of ['', 'usb://0x04b8', 'tcp://', 'tcp://host:0', 'tcp://host:99999',
        'tcp://host:9100/path', 'ftp://host:9100', 'tcp://user@host:9100', null, 42]) {
        assert.equal(parseTarget(bad), null, String(bad));
    }
});
