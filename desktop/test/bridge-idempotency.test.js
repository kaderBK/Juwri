// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Contract §Idempotency — the rule with a real-world cost attached.
 *
 * "The bridge keeps the last 200 jobIds (in memory, ~10 min TTL). A repeat of a
 * jobId that already succeeded returns the original {ok:true} WITHOUT printing
 * again."
 *
 * T1 retries the SAME jobId twice after a 15 s timeout. If any of these
 * assertions regress, restaurants get duplicate kitchen tickets.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { startBridge, printBody, authed } = require('./helpers/harness');
const { JobLedger, DEFAULT_MAX, DEFAULT_TTL_MS } = require('../src/bridge/jobs');

test('replaying a succeeded jobId returns ok without printing a second ticket', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    const first = await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });
    const second = await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });
    const third = await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });

    assert.deepEqual(first.json, { ok: true, jobId: 'ord-91423-kitchen-1' });
    assert.deepEqual(second.json, first.json, 'the ORIGINAL answer is replayed');
    assert.deepEqual(third.json, first.json);
    assert.equal(ctx.transport.calls.length, 1, 'exactly one physical ticket');
    assert.equal(ctx.bridge.state.replayed, 2);
});

test('a replay is honoured even when the payload changed — the jobId is the key', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });
    const replay = await ctx.request('/print', {
        method: 'POST',
        headers: authed(),
        body: printBody({ dataB64: Buffer.from('DIFFERENT').toString('base64') }),
    });

    assert.equal(replay.json.ok, true);
    assert.equal(ctx.transport.calls.length, 1);
});

test('two DIFFERENT jobIds both print — de-duplication must not eat real tickets', async (t) => {
    const ctx = await startBridge();
    t.after(() => ctx.close());

    await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody({ jobId: 'ord-1-kitchen-1' }) });
    await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody({ jobId: 'ord-1-kitchen-2' }) });

    assert.equal(ctx.transport.calls.length, 2);
});

test('a FAILED job is not remembered — the retry must really try again', async (t) => {
    let attempt = 0;
    const ctx = await startBridge({
        transport: {
            calls: [],
            print() {
                attempt += 1;
                if (attempt === 1) {
                    const err = new Error('refused');
                    err.code = 'ECONNREFUSED';

                    return Promise.reject(err);
                }

                return Promise.resolve();
            },
        },
    });
    t.after(() => ctx.close());

    const first = await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });
    assert.equal(first.json.error, 'econnrefused');

    // The operator plugs the cable back in; T1 retries the same jobId.
    const second = await ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });
    assert.equal(second.json.ok, true);
    assert.equal(attempt, 2);
});

test('a retry that arrives while the first attempt is still writing shares that attempt', async (t) => {
    // This is the realistic race: T1's fetch budget is 15 s and the bridge's own
    // worst case is 5 s connect + 10 s write, so the retry can easily land before
    // the first attempt has settled. Two sockets would mean two tickets.
    let started = 0;
    let release;
    const gate = new Promise((resolve) => {
        release = resolve;
    });

    const ctx = await startBridge({
        transport: {
            print() {
                started += 1;

                return gate;
            },
        },
    });
    t.after(() => ctx.close());

    const a = ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });
    const b = ctx.request('/print', { method: 'POST', headers: authed(), body: printBody() });

    await new Promise((resolve) => setTimeout(resolve, 30));
    release();

    const [first, second] = await Promise.all([a, b]);

    assert.equal(started, 1, 'one attempt, not two');
    assert.equal(first.json.ok, true);
    assert.equal(second.json.ok, true);
});

test('the ledger keeps the last 200 ids and forgets the oldest', () => {
    const ledger = new JobLedger();

    for (let i = 0; i < DEFAULT_MAX + 50; i++) ledger.remember(`job-${i}`, { ok: true, jobId: `job-${i}` });

    assert.equal(ledger.size, DEFAULT_MAX);
    assert.equal(ledger.replay('job-0'), null, 'the oldest ids are evicted');
    assert.ok(ledger.replay(`job-${DEFAULT_MAX + 49}`), 'the newest are kept');
});

test('the ledger forgets a job after the ~10 minute TTL', () => {
    let clock = 1_000_000;
    const ledger = new JobLedger({ now: () => clock });

    ledger.remember('job-a', { ok: true, jobId: 'job-a' });
    assert.ok(ledger.replay('job-a'));

    clock += DEFAULT_TTL_MS - 1;
    assert.ok(ledger.replay('job-a'), 'still inside the window');

    clock += 2;
    assert.equal(ledger.replay('job-a'), null, 'expired');
    assert.equal(DEFAULT_TTL_MS, 10 * 60 * 1000);
});

test('the ledger only remembers successes', async () => {
    const ledger = new JobLedger();

    await ledger.run('j1', async () => ({ ok: false, jobId: 'j1', error: 'etimedout' }));
    assert.equal(ledger.replay('j1'), null);

    await ledger.run('j2', async () => ({ ok: true, jobId: 'j2' }));
    assert.deepEqual(ledger.replay('j2'), { ok: true, jobId: 'j2' });
});
