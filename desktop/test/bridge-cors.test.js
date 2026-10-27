// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Contract §CORS — an allowlist of the store's own origin(s), NEVER `*`.
 *
 * The threat is concrete: without this, any page the operator has open in any tab
 * could drive their kitchen printers. So a foreign origin is refused by the
 * SERVER — omitting the header alone would only stop the attacker from reading
 * the response, after the ticket had already printed.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { startBridge, printBody, authed, TOKEN, ORIGIN } = require('./helpers/harness');
const { normalizeOrigin, buildAllowlist, evaluateOrigin } = require('../src/bridge/cors');

test('an allowlisted origin is echoed back — and it is never a wildcard', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const res = await ctx.request('/health', { headers: authed() });

    assert.equal(res.status, 200);
    assert.equal(res.headers['access-control-allow-origin'], ORIGIN);
    assert.notEqual(res.headers['access-control-allow-origin'], '*');
    assert.equal(res.headers.vary, 'Origin');
});

test('preflight returns the allowlist plus the contract headers', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const res = await ctx.request('/print', {
        method: 'OPTIONS',
        headers: {
            Origin: ORIGIN,
            'Access-Control-Request-Method': 'POST',
            'Access-Control-Request-Headers': 'authorization,content-type',
        },
    });

    assert.equal(res.status, 204);
    assert.equal(res.headers['access-control-allow-origin'], ORIGIN);
    assert.equal(res.headers['access-control-allow-headers'], 'Authorization, Content-Type');
    assert.match(res.headers['access-control-allow-methods'], /POST/);
});

test('preflight answers Private Network Access only when asked, and only for an allowed origin', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const withPna = await ctx.request('/print', {
        method: 'OPTIONS',
        headers: {
            Origin: ORIGIN,
            'Access-Control-Request-Method': 'POST',
            'Access-Control-Request-Private-Network': 'true',
        },
    });
    assert.equal(withPna.headers['access-control-allow-private-network'], 'true');

    const without = await ctx.request('/print', {
        method: 'OPTIONS',
        headers: { Origin: ORIGIN, 'Access-Control-Request-Method': 'POST' },
    });
    assert.equal(without.headers['access-control-allow-private-network'], undefined);

    const foreign = await ctx.request('/print', {
        method: 'OPTIONS',
        headers: {
            Origin: 'https://evil.example',
            'Access-Control-Request-Method': 'POST',
            'Access-Control-Request-Private-Network': 'true',
        },
    });
    assert.equal(foreign.headers['access-control-allow-private-network'], undefined);
    assert.equal(foreign.headers['access-control-allow-origin'], undefined);
});

test('a foreign origin is refused server-side, with a valid token, and never prints', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const res = await ctx.request('/print', {
        method: 'POST',
        headers: { Authorization: `Bearer ${TOKEN}`, Origin: 'https://evil.example' },
        body: printBody(),
    });

    assert.equal(res.status, 401);
    assert.deepEqual(res.json, { ok: false, error: 'unauthorized' });
    assert.equal(res.headers['access-control-allow-origin'], undefined);
    assert.equal(ctx.transport.calls.length, 0, 'a foreign page must not be able to print');
});

test('a look-alike origin does not pass: scheme, host and port all count', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    for (const origin of [
        'http://menu.example.com',          // wrong scheme
        'https://menu.example.com:8443',    // wrong port
        'https://menu.example.com.evil.io', // suffix attack
        'https://evil.io/menu.example.com', // path is not an origin
        'null',
    ]) {
        const res = await ctx.request('/health', {
            headers: { Authorization: `Bearer ${TOKEN}`, Origin: origin },
        });

        if (origin === 'null') {
            // A sandboxed/opaque origin cannot be allowlisted, but it is also not
            // a browser origin we can echo — it is treated as a non-browser caller
            // and the token remains the gate.
            assert.equal(res.headers['access-control-allow-origin'], undefined);
        } else {
            assert.equal(res.status, 401, origin);
        }
    }
});

test('a caller with no Origin header is allowed — that is T1\'s server-side client', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const res = await ctx.request('/print', {
        method: 'POST',
        headers: { Authorization: `Bearer ${TOKEN}` },
        body: printBody(),
    });

    assert.equal(res.status, 200);
    assert.equal(res.json.ok, true);
    assert.equal(res.headers['access-control-allow-origin'], undefined, 'nothing to echo');
});

test('origin parsing normalises and rejects sensibly', () => {
    assert.equal(normalizeOrigin('https://menu.example.com/pos/register?x=1'), 'https://menu.example.com');
    assert.equal(normalizeOrigin('menu.example.com'), 'https://menu.example.com');
    assert.equal(normalizeOrigin('HTTPS://MENU.EXAMPLE.COM'), 'https://menu.example.com');
    assert.equal(normalizeOrigin('file:///etc/passwd'), null);
    assert.equal(normalizeOrigin('javascript:alert(1)'), null);
    assert.equal(normalizeOrigin(''), null);
    assert.equal(normalizeOrigin(null), null);

    assert.deepEqual(buildAllowlist(['https://a.test', 'https://a.test/x', 'bad url ']), ['https://a.test']);
    assert.equal(evaluateOrigin(undefined, ['https://a.test']), 'no-origin');
    assert.equal(evaluateOrigin('https://a.test', ['https://a.test']), 'allow');
    assert.equal(evaluateOrigin('https://b.test', ['https://a.test']), 'reject');
});
