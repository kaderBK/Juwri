// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { create } = createRequire(import.meta.url)('../../../assets/driver/outbox.js');

function setup() {
    const mem = {};
    const storage = { getItem: (k) => (k in mem ? mem[k] : null), setItem: (k, v) => { mem[k] = v; } };
    const net = { online: false, status: 200, calls: [] };
    const fetch = async (url, o) => {
        net.calls.push({ url, ...o });
        if (!net.online) throw new TypeError('network');
        return { status: net.status, ok: net.status < 300 };
    };
    const changes = [];
    const ob = create({ storage, fetch, headers: () => ({ Authorization: 'Bearer t' }), onChange: (n) => changes.push(n), newKey: (() => { let i = 0; return () => 'k' + (++i); })() });
    return { ob, net, mem, changes };
}

test('an offline tap is queued, then replayed once with the same key when back online', async () => {
    const { ob, net, mem } = setup();
    const r = await ob.send('/s', 'PUT', '{"delivery_status":"delivered"}');
    assert.equal(r.state, 'queued');
    assert.equal(ob.pending(), 1);
    assert.ok(!mem.driver_outbox_v1.includes('Bearer'), 'token is never stored');
    net.online = true;
    assert.equal(await ob.flush(), 1);
    assert.equal(await ob.flush(), 0); // a second online event does not resend
    const sent = net.calls.filter((c) => c.headers.Authorization);
    assert.equal(sent.at(-1).headers['Idempotency-Key'], 'k1');
    assert.equal(net.calls.at(-1).headers['Idempotency-Key'], net.calls[0].headers['Idempotency-Key']);
    assert.equal(ob.pending(), 0);
});

test('online tap goes straight through; a 422 is final and never queued', async () => {
    const { ob, net } = setup();
    net.online = true;
    assert.equal((await ob.send('/s', 'PUT', '{}')).state, 'sent');
    net.status = 422;
    const r = await ob.send('/s', 'PUT', '{}');
    assert.equal(r.state, 'sent');
    assert.equal(r.response.status, 422);
    assert.equal(ob.pending(), 0);
});

test('a 5xx keeps the tap queued; order is kept and a new tap does not overtake the queue', async () => {
    const { ob, net } = setup();
    net.online = true;
    net.status = 503;
    assert.equal((await ob.send('/a', 'PUT', '1')).state, 'queued');
    net.status = 200;
    assert.equal((await ob.send('/b', 'PUT', '2')).state, 'queued'); // behind /a
    const before = net.calls.length;
    assert.equal(await ob.flush(), 2);
    assert.deepEqual(net.calls.slice(before).map((c) => c.url), ['/a', '/b']);
});

test('flush stops at the first network failure and keeps the rest', async () => {
    const { ob, net } = setup();
    await ob.send('/a', 'PUT', '1');
    await ob.send('/b', 'PUT', '2');
    assert.equal(await ob.flush(), 0);
    assert.equal(ob.pending(), 2);
});
