// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// OP-8: the browser till's encrypted store (assets/pos/pos-store.js) — crypto, the outbox state machine, the
// migration of the old plaintext queue (never dropping a record), and the wipe rules.
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';

if (!globalThis.crypto) globalThis.crypto = webcrypto;
const require = createRequire(import.meta.url);
const TS = require('../../../../assets/pos/pos-store.js');
const escrow = () => Buffer.from(webcrypto.getRandomValues(new Uint8Array(32))).toString('base64');
const fast = (b) => new TS.Store(b, { pinRounds: 1000 }); // fewer PBKDF2 rounds in tests only

test('rows are encrypted at rest and bound to their id; the data key never touches storage', async () => {
    const b = new TS.MemoryBackend();
    const s = fast(b);
    await s.openWithEscrow(escrow());
    await s.add({ url: 'https://shop.test/pos/orders?restaurant=3&ref=tok123', body: { customer: 'Sara Ahmed', phone: '+966500000001' } }, 1000);
    const raw = JSON.stringify([...b.s.outbox.values(), ...b.s.keys.values(), ...b.s.meta.values()]);
    assert.ok(!raw.includes('Sara') && !raw.includes('966500000001') && !raw.includes('tok123') && !raw.includes('shop.test'), 'no plaintext at rest');
    assert.equal((await fast(b).meta())[0].path, '/pos/orders', 'only the endpoint path is readable while locked (to count orders)');
    assert.deepEqual((await s.items())[0].body, { customer: 'Sara Ahmed', phone: '+966500000001' });

    // swapping the ciphertext of two rows is detected (additional data = row id)
    await s.add({ url: '/b', body: {} }, 2000);
    const r1 = b.s.outbox.get(1), r2 = b.s.outbox.get(2);
    b.s.outbox.set(1, { ...r1, box: r2.box });
    await assert.rejects(() => s.items());
});

test('the same escrow reopens the data after a reload; a different one cannot', async () => {
    const b = new TS.MemoryBackend();
    const key = escrow();
    const s = fast(b);
    await s.openWithEscrow(key);
    await s.add({ url: '/x', body: { n: 1 } });
    const again = fast(b);
    await again.openWithEscrow(key);
    assert.equal((await again.items())[0].body.n, 1);
    await assert.rejects(() => fast(b).openWithEscrow(escrow()));
    // never silently start a new key over existing encrypted rows
    b.s.keys.delete('escrow');
    await assert.rejects(() => fast(b).openWithEscrow(escrow()), /refusing to start a new key/);
});

test('a staff PIN opens the store offline; a wrong PIN learns nothing; locked writes are refused', async () => {
    const b = new TS.MemoryBackend();
    const s = fast(b);
    await s.openWithEscrow(escrow());
    await s.rememberPin(7, '4321');
    await s.rememberPin(9, '1111');
    await s.add({ url: '/x', body: { n: 2 } });
    const offline = fast(b);
    await assert.rejects(() => offline.add({ url: '/y', body: {} }), /locked/);
    assert.equal(await offline.openWithPin('0000'), null);
    assert.equal(offline.isOpen(), false);
    assert.equal(await offline.openWithPin('4321'), 7);
    assert.equal((await offline.items())[0].body.n, 2);
    const pinRows = [...b.s.keys.entries()].filter(([k]) => k.startsWith('pin:'));
    assert.ok(pinRows.every(([, v]) => !JSON.stringify(v).includes('4321')), 'no PIN at rest');
});

test('the outbox state machine', () => {
    assert.equal(TS.nextState('queued', 'send'), 'sending');
    assert.equal(TS.nextState('sending', 'ok'), 'done');
    assert.equal(TS.nextState('sending', 'conflict_key'), 'done');
    assert.equal(TS.nextState('sending', 'network'), 'queued');
    assert.equal(TS.nextState('sending', 'refused'), 'failed');
    assert.equal(TS.nextState('failed', 'retry'), 'queued');
    for (const [from, ev] of [['queued', 'ok'], ['failed', 'send'], ['queued', 'refused'], ['done', 'send']]) {
        assert.throws(() => TS.nextState(from, ev), /not allowed/);
    }
});

test('flush: confirmed rows leave, a network error stops and keeps the rest, a refusal is kept as failed', async () => {
    const b = new TS.MemoryBackend();
    const s = fast(b);
    await s.openWithEscrow(escrow());
    for (const n of [1, 2, 3, 4]) await s.add({ url: '/o' + n, body: { n } });
    const answers = { '/o1': 201, '/o2': 409, '/o3': 422 };
    let r = await s.flush(async (url) => { if (url === '/o4') throw new Error('offline'); return { status: answers[url] }; });
    assert.deepEqual(r, { sent: 2, failed: 1, left: 2, locked: false });
    assert.deepEqual((await s.meta()).map((m) => [m.id, m.state]), [[3, 'failed'], [4, 'queued']]);
    r = await s.flush(async () => ({ status: 200 })); // back online: the failed one is retried too
    assert.equal(r.left, 0);
    const locked = fast(b);
    assert.equal((await locked.flush(async () => ({ status: 200 }))).locked, true, 'a locked store sends nothing');
});

test('a row caught mid-send by a crash is sent again (idempotent keys make that safe)', async () => {
    const b = new TS.MemoryBackend();
    const s = fast(b);
    await s.openWithEscrow(escrow());
    const id = await s.add({ url: '/o', body: {} });
    await s.transition(id, 'send'); // crash here
    const sent = [];
    await s.flush(async (url) => { sent.push(url); return { status: 201 }; });
    assert.deepEqual(sent, ['/o']);
    assert.equal((await s.meta()).length, 0);
});

test('migration of the old plaintext queue: encrypted first, removed after, old database gone only when empty', async () => {
    const b = new TS.MemoryBackend();
    const s = fast(b);
    await s.openWithEscrow(escrow());
    let old = [{ id: 1, url: '/a', body: { n: 1 }, ts: 111 }, { id: 2, url: '/b', body: { n: 2 }, ts: 222 }];
    let destroyed = false;
    const legacy = { all: async () => old.slice(), del: async (id) => { old = old.filter((o) => o.id !== id); }, destroy: async () => { destroyed = true; } };
    assert.equal(await s.migrateLegacy(legacy), 2);
    assert.equal(destroyed, true);
    assert.deepEqual((await s.items()).map((i) => [i.url, i.ts]), [['/a', 111], ['/b', 222]], 'enqueue times kept (retention)');

    // a failure while writing the encrypted copy leaves the old item in place
    let old2 = [{ id: 5, url: '/c', body: {}, ts: 1 }];
    let destroyed2 = false;
    const broken = fast(b);
    await broken.openWithEscrow(escrow()).catch(() => {});
    const s2 = fast(new TS.MemoryBackend());
    await s2.openWithEscrow(escrow());
    s2.add = async () => { throw new Error('disk full'); };
    await assert.rejects(() => s2.migrateLegacy({ all: async () => old2.slice(), del: async (id) => { old2 = old2.filter((o) => o.id !== id); }, destroy: async () => { destroyed2 = true; } }));
    assert.equal(old2.length, 1, 'never dropped');
    assert.equal(destroyed2, false);
    // locked: nothing moves
    assert.equal(await fast(new TS.MemoryBackend()).migrateLegacy(legacy), 0);
});

test('wipe rules: lease → PINs gone, queue and escrow kept; server wipe → only when nothing is left', async () => {
    const b = new TS.MemoryBackend();
    const key = escrow();
    const s = fast(b);
    await s.openWithEscrow(key);
    await s.rememberPin(7, '4321');
    await s.add({ url: '/o', body: {} }, 5);
    await s.wipePins();
    assert.equal(s.isOpen(), false);
    assert.equal(await fast(b).openWithPin('4321'), null, 'no PIN opens it any more');
    assert.equal(await s.oldest(), 5, 'the queue (and its age, for retention) is kept while locked');
    const online = fast(b);
    await online.openWithEscrow(key); // back online: escrow still opens it to upload
    assert.equal(await online.wipeAll(), false, 'not while records are left');
    await online.flush(async () => ({ status: 201 }));
    assert.equal(await online.wipeAll(), true);
    assert.equal(b.s.keys.size + b.s.outbox.size, 0);
});
