// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// No sale is ever written unencrypted: with the till's store locked (restarted offline, no PIN typed), submit() and
// submitPaid() refuse in code (not only behind the lock screen), and nothing reaches the old plaintext pos-queue,
// the encrypted outbox or the network. The register's real factory (assets/pos/pos-core.js) is run with stubs.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const TS = require('../../../../assets/pos/pos-store.js');
const core = readFileSync(new URL('../../../../assets/pos/pos-core.js', import.meta.url), 'utf8');

function register(extra = {}) {
    const calls = { fetch: 0, legacyOpen: 0, alerts: [] };
    const window = { PosBoot: { routes: { ordersStore: '/pos/orders', ordersBase: '/pos/orders', offlineDevicesEscrow: '/pos/offline/devices/escrow' }, i18n: { storageFull: 'STORAGE FULL, NOT SAVED' }, restaurantId: 3 } };
    const ctx = {
        window, URL, console, JSON, Promise, Error, Object, Array, Date, Math, String, Number, setTimeout, clearTimeout,
        location: { origin: 'https://shop.test', href: 'https://shop.test/pos?restaurant=3' },
        navigator: { onLine: false },
        document: { querySelector: () => ({ content: 'csrf' }), querySelectorAll: () => [] },
        alert: (m) => { calls.alerts.push(m); },
        fetch: async () => { calls.fetch++; throw new TypeError('Failed to fetch'); },
        indexedDB: { open: () => { calls.legacyOpen++; throw new Error('the old plaintext queue was opened'); } },
    };
    Object.assign(ctx, extra);
    Object.assign(window, extra);
    vm.createContext(ctx);
    vm.runInContext(core + '\n;globalThis.__reg = posRegister();', ctx);
    return { reg: ctx.__reg, calls };
}

async function lockedStore() {
    const b = new TS.MemoryBackend();
    const s = new TS.Store(b, { pinRounds: 1000 });
    await s.openWithEscrow(Buffer.from(crypto.getRandomValues(new Uint8Array(32))).toString('base64'));
    await s.add({ url: '/pos/orders', body: { n: 0 } }, 1); // one earlier sale, encrypted
    return { b, store: new TS.Store(b, { pinRounds: 1000 }) }; // a restart: same data, not opened
}

for (const fn of ['submit', 'submitPaid']) {
    test(`a locked store: ${fn}() throws, shows the lock and writes nothing anywhere`, async () => {
        const { reg, calls } = register();
        const { b, store } = await lockedStore();
        reg.tillStore = store;
        reg.cart = [{ id: 1, qty: 1, price: 10 }];
        reg.payload = () => ({ client_order_id: 'k1', items: [] });
        await assert.rejects(() => reg[fn](fn === 'submitPaid' ? 'cash' : false), /locked/);
        assert.equal(reg.locked, true, 'the register stays on the PIN lock screen');
        assert.equal(calls.fetch, 0, 'nothing sent');
        assert.equal(calls.legacyOpen, 0, 'the old plaintext queue is never written');
        assert.equal(b.s.outbox.size, 1, 'the encrypted outbox is unchanged');
        assert.equal(reg.sending, false);
    });
}

test('idbAdd refuses while locked (any caller, not only a sale) and when there is no encrypted store at all', async () => {
    const { reg, calls } = register();
    const { b, store } = await lockedStore();
    reg.tillStore = store;
    await assert.rejects(() => reg.idbAdd({ url: '/pos/orders', body: { secret: 1 }, ts: 2 }), /locked/);
    reg.tillStore = null;
    await assert.rejects(() => reg.idbAdd({ url: '/pos/orders', body: { secret: 1 }, ts: 2 }), /locked/);
    assert.equal(calls.legacyOpen, 0);
    assert.equal(b.s.outbox.size, 1);
});

test('once a PIN opens the store, an offline sale is queued encrypted', async () => {
    const { reg, calls } = register();
    const b = new TS.MemoryBackend();
    const s = new TS.Store(b, { pinRounds: 1000 });
    await s.openWithEscrow(Buffer.from(crypto.getRandomValues(new Uint8Array(32))).toString('base64'));
    reg.tillStore = s;
    reg.payload = () => ({ client_order_id: 'k2', items: [] });
    reg.reset = () => {};
    await reg.submit(false);
    assert.equal(calls.fetch, 1);
    assert.equal(b.s.outbox.size, 1);
    assert.ok(!JSON.stringify([...b.s.outbox.values()]).includes('k2'), 'encrypted');
});

test('a till restarted offline stays locked (no escrow from the network, none from any cache) and refuses a sale', async () => {
    const { b, store } = await lockedStore();
    const Fake = { Store: function () { return store; }, IdbBackend: function () { return b; } };
    const { reg, calls } = register({ TillStore: Fake, crypto: globalThis.crypto, navigator: { onLine: true } }); // "online" per the browser, but the network is down
    reg.ensureDevice = async () => ({ id: 'till-1', token: 't'.repeat(64) });
    await reg.initTillStore();
    assert.equal(reg.tillStore.isOpen(), false, 'the escrow fetch failed: the store stays locked');
    assert.equal(calls.fetch, 1, 'the escrow was asked of the network only');
    reg.cart = [{ id: 1, qty: 1 }];
    reg.payload = () => ({ client_order_id: 'k3', items: [] });
    await assert.rejects(() => reg.submit(false), /locked/);
    assert.equal(reg.locked, true);
    assert.equal(b.s.outbox.size, 1, 'nothing written');
    assert.equal(await reg.tillStore.openWithPin('0000'), null, 'and a wrong PIN does not open it');
});

test('storage full: the sale is NOT saved, the cashier is told, the cart stays and the register is usable again', async () => {
    const b = new TS.MemoryBackend();
    const s = new TS.Store(b, { pinRounds: 1000 });
    await s.openWithEscrow(Buffer.from(crypto.getRandomValues(new Uint8Array(32))).toString('base64'));
    const put = b.put.bind(b);
    b.put = async (store, k, v) => { if (store === 'outbox') { const e = new Error('The quota has been exceeded.'); e.name = 'QuotaExceededError'; throw e; } return put(store, k, v); };
    const { reg, calls } = register();
    reg.tillStore = s;
    reg.cart = [{ id: 1, qty: 1 }];
    reg.payload = () => ({ client_order_id: 'k4', items: [] });
    reg.reset = () => { reg.cart = []; };
    await reg.submit(false);
    assert.deepEqual(calls.alerts, ['STORAGE FULL, NOT SAVED']);
    assert.equal(reg.cart.length, 1, 'the cart is kept');
    assert.equal(reg.sending, false, 'the register is not stuck');
    assert.equal(b.s.outbox.size, 0);
    calls.alerts.length = 0;
    await reg.submitPaid('cash');
    assert.deepEqual(calls.alerts, ['STORAGE FULL, NOT SAVED']);
    assert.equal(reg.sending, false);
});

test('IndexedDB: a quota abort rejects (never hangs), and keys + values are read in one transaction', async () => {
    // a minimal IndexedDB double: the outbox put aborts with QuotaExceededError like a full disk
    const data = { outbox: new Map(), keys: new Map(), meta: new Map() };
    const db = { transaction: (name) => {
        const t = { error: null };
        const req = (fn) => { const r = {}; queueMicrotask(() => { r.result = fn(); }); return r; };
        t.objectStore = () => ({
            put: (v, k) => { if (name === 'outbox') { queueMicrotask(() => { t.error = Object.assign(new Error('quota'), { name: 'QuotaExceededError' }); t.onabort(); }); return {}; } data[name].set(k, v); return {}; },
            get: (k) => req(() => data[name].get(k)),
            getAllKeys: () => req(() => [...data[name].keys()]),
            getAll: () => req(() => [...data[name].values()]),
            delete: (k) => { data[name].delete(k); return {}; },
        });
        setTimeout(() => { if (!t.error) t.oncomplete(); }, 0);
        return t;
    } };
    globalThis.indexedDB = { open: () => { const r = { result: db }; setTimeout(() => r.onsuccess(), 0); return r; } };
    try {
        const s = new TS.Store(new TS.IdbBackend('pos-till-9'), { pinRounds: 1000 });
        await s.openWithEscrow(Buffer.from(crypto.getRandomValues(new Uint8Array(32))).toString('base64'));
        await assert.rejects(() => s.add({ url: '/pos/orders', body: {} }), /quota/);
        data.outbox.set(7, undefined); // a half-written / foreign entry
        data.outbox.set(8, { id: 8, state: 'queued', ts: 1, attempts: 0, path: '/pos/orders' });
        assert.deepEqual((await s.meta()).map((m) => m.id), [8], 'no TypeError on a row without a value');
    } finally { delete globalThis.indexedDB; }
});
