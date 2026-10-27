// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Contract §Auth — the bearer pairing token.
 *
 * The distinction the dashboard depends on: 409 means "pair the desktop app",
 * 401 means "that token is wrong". Collapsing them would send an operator to the
 * wrong troubleshooting page.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { startBridge, printBody, authed, TOKEN, ORIGIN } = require('./helpers/harness');
const { secretsMatch, bearerToken, authFailure } = require('../src/bridge/auth');

test('an unpaired bridge answers 409 on /print, but a discoverable 200 on /health', async (t) => {
    const ctx = await startBridge({ token: '' });
    t.after(() => ctx.close());

    /*
     * Contract v1.1. /health is feature detection and the web client collapses
     * any non-200 to "no bridge here" — a 409 therefore made a running-but-
     * unpaired bridge indistinguishable from an absent one, and the operator
     * could never be prompted to pair it. It answers 200 with paired:false and
     * discloses no printers; /print still refuses.
     */
    const health = await ctx.request('/health', { headers: { Origin: ORIGIN } });
    assert.equal(health.status, 200);
    assert.equal(health.json.ok, true);
    assert.equal(health.json.paired, false);
    assert.deepEqual(health.json.printers, [], 'an unpaired bridge must disclose no printers');

    const print = await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });
    assert.equal(print.status, 409);
    assert.equal(print.json.error, 'unpaired');
    assert.equal(ctx.transport.calls.length, 0);
});

test('a missing, malformed or wrong token answers 401 and never prints', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const cases = [
        undefined,
        'Bearer',
        'Bearer ',
        'Basic ' + Buffer.from('a:b').toString('base64'),
        'Bearer ' + 'B'.repeat(40),
        'Bearer ' + TOKEN.slice(0, 39),        // one char short
        'Bearer ' + TOKEN + 'x',               // one char long
        'Bearer ' + TOKEN.toLowerCase(),       // case matters
    ];

    for (const header of cases) {
        const headers = { Origin: ORIGIN };
        if (header) headers.Authorization = header;

        const res = await ctx.request('/print', { method: 'POST', headers, body: printBody() });
        assert.equal(res.status, 401, `header: ${header}`);
        assert.deepEqual(res.json, { ok: false, error: 'unauthorized' });
    }

    assert.equal(ctx.transport.calls.length, 0);
});

test('the correct token is accepted, case-sensitively and whitespace-tolerantly', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const res = await ctx.request('/health', {
        headers: { Authorization: `bearer   ${TOKEN}`, Origin: ORIGIN },
    });

    assert.equal(res.status, 200);
    assert.equal(res.json.ok, true);
});

test('comparison is length-safe: timingSafeEqual must not throw on a short token', () => {
    // crypto.timingSafeEqual throws on unequal lengths, and the length of an
    // attacker-supplied string must not be an oracle either. Hashing both sides
    // first is what makes this true.
    assert.equal(secretsMatch(TOKEN, 'x'), false);
    assert.equal(secretsMatch(TOKEN, 'x'.repeat(4096)), false);
    assert.equal(secretsMatch(TOKEN, TOKEN), true);
    assert.equal(secretsMatch('', ''), false);
    assert.equal(secretsMatch(TOKEN, null), false);
});

test('bearerToken tolerates junk headers without throwing', () => {
    assert.equal(bearerToken({ headers: {} }), null);
    assert.equal(bearerToken({ headers: { authorization: 'Bearer  abc  ' } }), 'abc');
    assert.equal(bearerToken({}), null);
    assert.equal(bearerToken({ headers: { authorization: 42 } }), null);
});

test('authFailure separates unpaired from unauthorized', () => {
    assert.equal(authFailure({ headers: {} }, ''), 'unpaired');
    assert.equal(authFailure({ headers: {} }, null), 'unpaired');
    assert.equal(authFailure({ headers: {} }, TOKEN), 'unauthorized');
    assert.equal(authFailure({ headers: { authorization: `Bearer ${TOKEN}` } }, TOKEN), null);
});
