// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// OP-9: the offline register — snapshot -> menu, pricing with the shared calculator, replay records, the encrypted
// outbox (typed records), offline sale / void / receipt, and the list of what cannot be done offline.
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

if (!globalThis.crypto) globalThis.crypto = webcrypto;
const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const PO = require('../../../../assets/pos/pos-offline.js');
const TS = require('../../../../assets/pos/pos-store.js');
const TC = require('../../../../assets/pos/till-calculator.js');
const golden = () => JSON.parse(readFileSync(join(here, 'fixtures/snapshot-golden.json'), 'utf8'));
const escrow = () => Buffer.from(webcrypto.getRandomValues(new Uint8Array(32))).toString('base64');
const store = (b) => new TS.Store(b || new TS.MemoryBackend(), { pinRounds: 1000 });
const KEY = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

// A cart line the way the register builds it (pos-core makeLine).
const latte = (over = {}) => ({ id: 10, name: 'Latte', variation: null, extras: [], options: [], qty: 1, note: '', ...over });

test('the snapshot hash is checked the way the server made it (PHP golden document, framework JSON flags)', async () => {
    const doc = golden();
    assert.equal(await PO.verifyHash(doc), true, 'canonical JSON + SHA-256 agree with PHP OfflineSnapshot::canonical');
    doc.parts.catalogue.items[0].price_minor += 1;
    assert.equal(await PO.verifyHash(doc), false, 'a changed price fails the check');
    assert.equal(await PO.verifyHash({ parts: {}, hash: '' }), false);
});

test('snapshot state: none / ok / expired, and validity restarts from the last contact', () => {
    const doc = golden();
    const now = Date.parse('2026-10-12T09:00:00Z');
    assert.equal(PO.snapshotState(null, now), 'none');
    assert.equal(PO.snapshotState(doc, now), 'ok');
    assert.equal(PO.snapshotState(doc, Date.parse('2026-10-12T20:05:01Z')), 'expired');
    const again = PO.extendValidity(doc, Date.parse('2026-10-12T20:00:00Z'));
    assert.equal(PO.snapshotState(again, Date.parse('2026-10-12T23:00:00Z')), 'ok', '12 offline hours counted from the contact');
    assert.equal(doc.valid_until, '2026-10-12T20:05:00Z', 'the original is not changed');
});

test('the menu comes from the snapshot in the register\'s own shape', () => {
    const cats = PO.menuFromSnapshot(golden(), 'ar');
    assert.deepEqual(cats.map((c) => c.name), ['Drinks', 'Food']);
    const l = cats[0].items[0];
    assert.equal(l.name, 'لاتيه', 'the device language is used when the snapshot has it');
    assert.equal(l.price, 14.5);
    assert.deepEqual(l.variants.map((v) => v.price), [16.5]);
    assert.deepEqual(l.extras, [{ id: 200, title: 'Oat milk', price: 3 }]);
    assert.deepEqual(l.variant_groups[0].options.map((o) => o.price), [0, 0.5]);
    assert.equal(l.stock_qty, 5);
    assert.equal(cats[1].items[0].is_sold_out, true, 'sold out stays visible, not sellable');
    assert.equal(PO.menuFromSnapshot(golden(), 'de')[0].items[0].name.startsWith('Latte'), true, 'a missing translation falls back to the name');
});

test('pricing uses the snapshot and the shared calculator: same integers as the calculator, tax rounded half-up', () => {
    const doc = golden();
    // 14.50 x 1 at 15 % = 2.175 -> 2.18 (the float path showed 2.17)
    const p = PO.priceCart(doc, [latte()], {});
    assert.equal(p.ok, true);
    assert.deepEqual(p.calc, TC.price({ lines: [{ unit_minor: 1450, qty: 1 }], tax: { mode: 'percentage', rate: '15' } }));
    assert.equal(p.calc.tax_minor, 218);
    assert.equal(p.calc.total_minor, 1668);
    // variant replaces the base price; extras and choices add; quantity multiplies
    const q = PO.priceCart(doc, [latte({ variation: 100, extras: [{ id: 200 }], options: [{ group_id: 300, selected: [{ option: 'Extra' }] }], qty: 3 })], {});
    assert.equal(q.ok, true);
    assert.equal(q.lines[0].unit_minor, 1650);
    assert.deepEqual(q.lines[0].extras, [{ id: 200, price_minor: 300 }]);
    assert.deepEqual(q.lines[0].options, [{ group_id: 300, group: 'Sugar', option: 'Extra', price_minor: 50 }]);
    assert.equal(q.calc.subtotal_minor, (1650 + 300 + 50) * 3);
    // the price on the screen can never differ from the sale: a stale line price in the cart is ignored
    const stale = PO.priceCart(doc, [latte({ unitPrice: 99.99 })], {});
    assert.equal(stale.calc.subtotal_minor, 1450);
});

test('discounts: amount and percent go through the calculator; tip is added after tax', () => {
    const doc = golden();
    const a = PO.priceCart(doc, [latte({ qty: 2 })], { discount: { mode: 'amount', value: 5 } });
    assert.equal(a.calc.discount_minor, 500);
    const pc = PO.priceCart(doc, [latte({ qty: 2 })], { discount: { mode: 'percent', value: 10 } });
    assert.equal(pc.calc.discount_minor, 290);
    const big = PO.priceCart(doc, [latte()], { discount: { mode: 'amount', value: 999 } });
    assert.equal(big.calc.discount_minor, 1450, 'capped at the subtotal');
    const tip = PO.priceCart(doc, [latte()], { tipMinor: 100 });
    assert.equal(tip.calc.total_minor, 1450 + 218 + 100);
    assert.equal(PO.toMinor(0.1 + 0.2, 2), 30, 'no float drift when turning a typed amount into minor units');
});

test('lines the saved menu cannot sell are refused with a reason, never priced from a guess', () => {
    const doc = golden();
    assert.equal(PO.priceCart(doc, [{ id: 999, name: 'Ghost', qty: 1, extras: [], options: [] }], {}).reason, 'unknown_item');
    assert.equal(PO.priceCart(doc, [{ id: 11, name: 'Wrap', qty: 1, extras: [], options: [] }], {}).reason, 'sold_out');
    assert.equal(PO.priceCart(doc, [latte({ variation: 5 })], {}).reason, 'unknown_variant');
    assert.equal(PO.priceCart(doc, [latte({ extras: [{ id: 5 }] })], {}).reason, 'unknown_extra');
    assert.equal(PO.priceCart(doc, [latte({ options: [{ group_id: 300, selected: [{ option: 'Lots' }] }] })], {}).reason, 'unknown_option');
    assert.equal(PO.priceCart(doc, [latte({ combo_id: 4 })], {}).reason, 'combo');
    assert.equal(PO.priceCart(null, [latte()], {}).reason, 'no_snapshot');
});

test('UUIDv7 keys: version and variant bits, time ordered, unique', () => {
    const a = PO.uuid7(1760000000000), b = PO.uuid7(1760000000001);
    assert.match(a, KEY);
    assert.ok(a.slice(0, 13) < b.slice(0, 13), 'the time part orders keys');
    assert.notEqual(PO.uuid7(1760000000000), a);
});

test('a cash sale becomes a sale record and a tender record in the server\'s replay format', () => {
    const doc = golden();
    const priced = PO.priceCart(doc, [latte({ qty: 2 })], {});
    const [sale, tender] = PO.saleRecords({ doc, priced, staffId: 3, orderType: 'on-table', tenderedMinor: 5000, now: 1760000000000 });
    assert.match(sale.key, KEY); assert.match(tender.key, KEY); assert.notEqual(sale.key, tender.key);
    assert.equal(sale.type, 'sale'); assert.equal(sale.staff_id, 3); assert.equal(sale.snapshot_version, 3);
    assert.equal(sale.created_at, '2025-10-09T08:53:20.000Z');
    assert.equal(sale.payload.order_type, 'on-table');
    assert.deepEqual(sale.payload.totals, { subtotal_minor: 2900, discount_minor: 0, tax_minor: 435, service_minor: 0, tip_minor: 0, total_minor: 3335 });
    assert.deepEqual(sale.payload.lines[0], { item_id: 10, variant_id: null, qty: 2, unit_minor: 1450, extras: [], options: [], note: null });
    assert.equal(tender.type, 'tender');
    assert.deepEqual(tender.payload, { sale_key: sale.key, method: 'cash', amount_minor: 3335, tendered_minor: 5000, change_minor: 1665 });
    // the totals the till declares are exactly what the calculator gives for the lines (the server checks the same)
    const check = TC.price({ lines: sale.payload.lines.map((l) => ({ unit_minor: l.unit_minor, qty: l.qty })), tax: { mode: 'percentage', rate: '15' }, discount: sale.payload.discount, service_minor: 0, tip_minor: 0 });
    for (const k of Object.keys(sale.payload.totals)) assert.equal(sale.payload.totals[k], check[k], k);
    assert.equal(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'delivery' }).length, 1, 'no tender without cash');
    assert.equal(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'delivery' })[0].payload.order_type, 'takeaway', 'delivery is not sold offline');
});

test('a void record names the sale and a reason', () => {
    const doc = golden();
    const v = PO.voidRecord({ doc, staffId: 3, saleKey: PO.uuid7(), reason: '  wrong order  ' });
    assert.match(v.key, KEY);
    assert.equal(v.type, 'void');
    assert.equal(v.payload.reason, 'wrong order');
    assert.equal(v.payload.line, null);
    assert.equal(PO.can(doc, 3, 'pos_refund'), true);
    assert.equal(PO.can(doc, 4, 'pos_refund'), false, 'a waiter without the refund permission cannot void');
    assert.equal(PO.can(doc, 99, 'pos'), false, 'staff not in the snapshot cannot sell offline');
});

test('typed records: encrypted at rest, gap-free sequence, written all-or-nothing', async () => {
    const b = new TS.MemoryBackend();
    const s = store(b);
    await s.openWithEscrow(escrow());
    const doc = golden();
    const priced = PO.priceCart(doc, [latte()], {});
    const recs = PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway', tenderedMinor: 2000 });
    await s.addRecords(recs);
    await s.addRecords(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway', tenderedMinor: 2000 }));
    const all = await s.records();
    assert.deepEqual(all.map((r) => r.body.seq), [1, 2, 3, 4], 'one counter per till, no gaps');
    const raw = JSON.stringify([...b.s.outbox.values(), ...b.s.keys.values(), ...b.s.meta.values()]);
    assert.ok(!raw.includes('1450') && !raw.includes(recs[0].key), 'prices and keys are not readable at rest');
    assert.equal((await fast2(b).meta())[0].path, '/pos/offline/replay', 'only the endpoint is readable while locked');

    // a failure on the second write removes the first: a sale never sits without its tender
    const flaky = new TS.MemoryBackend();
    const fs = store(flaky);
    await fs.openWithEscrow(escrow());
    let n = 0;
    const put = flaky.put.bind(flaky);
    flaky.put = async (st, k, v) => { if (st === 'outbox' && ++n === 2) throw new Error('QuotaExceededError'); return put(st, k, v); };
    await assert.rejects(() => fs.addRecords(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway', tenderedMinor: 2000 })));
    assert.equal((await fs.records()).length, 0, 'nothing half-written');
    assert.equal(await flaky.get('meta', 'devseq'), undefined, 'the counters rolled back with the rows: no hole in the sequence');
    flaky.put = put;
    await fs.addRecords(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway', tenderedMinor: 2000 }));
    assert.deepEqual((await fs.records()).map((r) => r.body.seq), [1, 2], 'the next sale starts at 1: gap-free');

    // two taps at once never share a sequence number
    const [a1, a2] = await Promise.all([fs.addRecords(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway' })), fs.addRecords(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway' }))]);
    assert.notDeepEqual(a1, a2);
    const seqs = (await fs.records()).map((r) => r.body.seq);
    assert.deepEqual(seqs, [1, 2, 3, 4]);

    // a typed record can never be discarded on its own (a sale would lose its tender)
    await fs.discard((await fs.records())[0].id);
    assert.equal((await fs.records()).length, 4);
    // the same key can never be queued twice
    const dupe = PO.voidRecord({ doc, staffId: 3, saleKey: recs[0].key, reason: 'y' });
    await s.addRecords([dupe]);
    await assert.rejects(() => s.addRecords([dupe]), /already queued/);

    // locked: nothing is queued
    await assert.rejects(() => store(b).addRecords(recs), /locked/);
});
function fast2(b) { return new TS.Store(b, { pinRounds: 1000 }); }

test('replay: final answers remove the local copy; waiting / errors stay queued; conflicts are listed for the manager', async () => {
    const s = store();
    await s.openWithEscrow(escrow());
    const doc = golden();
    const priced = PO.priceCart(doc, [latte()], {});
    const [sale, tender] = PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway', tenderedMinor: 2000 });
    await s.addRecords([sale, tender]);

    // 1) the network is down: both go back to queued, nothing lost
    let r = await s.flushRecords(async () => { throw new Error('offline'); });
    assert.equal(r.left, 2);
    assert.deepEqual((await s.records()).map((x) => x.state), ['queued', 'queued']);

    // 2) the sale is accepted, the tender is "waiting" (server answered it before the sale landed): tender stays
    r = await s.flushRecords(async (recs) => ({ status: 200, data: { results: [
        { key: recs[0].key, status: 'accepted' }, { key: recs[1].key, status: 'waiting' }] } }));
    assert.equal(r.sent, 1);
    assert.deepEqual((await s.records()).map((x) => x.body.type), ['tender']);

    // 3) a duplicate counts as done; a conflict is final on the server and listed for review
    r = await s.flushRecords(async (recs) => ({ status: 200, data: { results: [{ key: recs[0].key, status: 'conflict', reason: 'already_paid' }] } }));
    assert.equal((await s.records()).length, 0);
    assert.equal((await s.review())[0].reason, 'already_paid');

    // 4) order and batching: sent in sequence order, at most the batch size per request
    for (let i = 0; i < 5; i++) await s.addRecords(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway' }));
    const seen = [];
    await s.flushRecords(async (recs) => { seen.push(recs.map((x) => x.seq)); return { status: 200, data: { results: recs.map((x) => ({ key: x.key, status: 'accepted' })) } }; }, 2);
    assert.deepEqual(seen.map((x) => x.length), [2, 2, 1]);
    assert.deepEqual(seen.flat(), [...seen.flat()].sort((a, b) => a - b));

    // 5) a server error / unknown device keeps everything; an invalid batch is kept as failed (never deleted)
    await s.addRecords(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway' }));
    await s.flushRecords(async () => ({ status: 500, data: {} }));
    assert.equal((await s.records())[0].state, 'queued');
    await s.flushRecords(async () => ({ status: 401, data: { code: 'device_unknown' } }));
    assert.equal((await s.records()).length, 1);
    await s.flushRecords(async () => ({ status: 422, data: {} }));
    assert.equal((await s.records())[0].state, 'failed');
    await s.flushRecords(async () => ({ status: 422, data: {} }));
    assert.equal((await s.review()).filter((x) => x.reason === 'invalid_batch').length, 1, 'a stuck record is shown to the manager, once');
    await s.flushRecords(async (recs) => ({ status: 200, data: { results: [{ key: recs[0].key, status: 'duplicate' }] } }));
    assert.equal((await s.records()).length, 0, 'a failed record is retried, and a duplicate answer ends it');

    // the legacy raw-POST flush never touches typed records
    await s.addRecords(PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway' }));
    let called = 0;
    await s.flush(async () => { called++; return { status: 200 }; });
    assert.equal(called, 0);
    assert.equal((await s.records()).length, 1);
});

test('the snapshot is kept sealed in the store, and a lease-over wipe drops it', async () => {
    const b = new TS.MemoryBackend();
    const s = store(b);
    const escrowKey = escrow();
    await s.openWithEscrow(escrowKey);
    await s.putSnapshot(golden());
    assert.ok(!JSON.stringify([...b.s.meta.values()]).includes('Latte'), 'no menu in the clear');
    assert.equal((await s.getSnapshot()).version, 3);
    s.lock();
    assert.equal(await s.getSnapshot(), null, 'locked: unreadable');
    await s.openWithPin('0000').catch(() => null);
    const open = store(b);
    await open.openWithEscrow(escrowKey);
    await open.wipePins();
    assert.equal(b.s.meta.get('snapshot'), undefined);
});

test('the receipt is built from the records and the snapshot alone', () => {
    const doc = golden();
    const priced = PO.priceCart(doc, [latte({ qty: 2, variation: 100, extras: [{ id: 200 }] })], { discount: { mode: 'amount', value: 1 } });
    const [sale, tender] = PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway', tenderedMinor: 10000, now: 1760000000000 });
    sale.seq = 7;
    const r = PO.receiptFor(doc, sale, tender);
    assert.equal(r.no, '7');
    assert.equal(r.cashier, 'Sara');
    assert.equal(r.currency, 'SAR');
    assert.equal(r.lines[0].name.startsWith('Latte'), true);
    assert.deepEqual(r.lines[0].extras, ['Oat milk']);
    assert.equal(r.lines[0].variant, 'Large');
    assert.equal(r.subtotal, PO.fmt(priced.calc.subtotal_minor, 2));
    assert.equal(r.total, PO.fmt(priced.calc.total_minor, 2));
    assert.equal(r.tendered, '100.00');
    assert.equal(r.change, PO.fmt(10000 - priced.calc.total_minor, 2));
    assert.equal(r.discount, '1.00');
    assert.equal(PO.receiptFor(doc, sale, null).tendered, null, 'an unpaid sale has no cash lines');
});

test('the Offline tab groups sale, tender and void; the local stock view subtracts what is waiting', () => {
    const doc = golden();
    const priced = PO.priceCart(doc, [latte({ qty: 2 })], {});
    const [sale, tender] = PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway', tenderedMinor: 5000 });
    const v = PO.voidRecord({ doc, staffId: 3, saleKey: sale.key, reason: 'r' });
    const rows = PO.salesFromRecords([{ ...sale, id: 1 }, { ...tender, id: 2 }, { ...v, id: 3 }]);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].voided, true);
    assert.equal(rows[0].tender.type, 'tender');
    assert.deepEqual(PO.pendingQty([sale, tender, v]), { 10: 2 });
});

test('everything section 9 lists has a label, so the register can always say so', () => {
    for (const k of ['customers', 'coupon', 'wallet', 'tables', 'manage', 'delivery', 'card', 'other_payment', 'split', 'hold', 'kitchen', 'settle_order', 'combos', 'orders_online']) {
        assert.ok(PO.ONLINE_ONLY[k], k);
    }
});

// ── the register itself, driven through its own methods with a stand-in page ─────────────────────────────────────────────
async function register(over = {}) {
    const b = new TS.MemoryBackend();
    const tillStore = store(b);
    await tillStore.openWithEscrow(escrow());
    const alerts = [], toasts = [];
    globalThis.alert = (m) => alerts.push(m);
    globalThis.window = { prompt: () => 'wrong order', PosBoot: {} };
    globalThis.navigator ??= {};
    const mod = PO.registerModule({ PosBoot: { staffId: 3, lang: 'en', i18n: {
        offlineBlocked: 'BLOCKED', offlineNoMenu: 'NOMENU', offlineExpired: 'EXPIRED', offlineCashShort: 'SHORT', offlineNoStaff: 'NOSTAFF',
        offlineNotAllowed: 'NOTALLOWED', offlineMenuChanged: 'MENUCHANGED', storageFull: 'FULL', offlineSaved: 'SAVED', offlineOnly: ':action NOT OFFLINE',
        offlineLabels: { card: 'Card', hold: 'Hold', kitchen: 'Kitchen', combos: 'Combos' }, offlineVoided: 'VOIDED', offlineAlreadyVoided: 'ALREADYVOID',
        offlineReady: 'READY', offlineSyncing: 'SYNC', offlineReview: ':count to review',
    } } });
    const reg = Object.assign({}, mod, {
        tillStore, snap: golden(), offline: true, queued: 0, cart: [latte({ qty: 2 })], discount: { mode: 'amount', value: 0 }, tendered: 50, tipAmount: 0,
        type: 'takeaway', orderNote: '', sending: false, locked: false, salesBlocked: null, cashOpen: true,
        reset() { this.cart = []; }, showToast(m, t) { toasts.push([m, t]); }, refreshQueued() {}, loadOffline: null,
    }, over);
    // a valid-until in the future for the golden document
    if (reg.snap) reg.snap = PO.extendValidity(reg.snap, Date.now());
    Object.defineProperty(globalThis.navigator, 'onLine', { value: false, configurable: true });
    return { reg, alerts, toasts, tillStore };
}

test('register: an offline cash sale is queued with its tender, the cart is cleared and a receipt is shown', async () => {
    const { reg, alerts, toasts, tillStore } = await register();
    await reg.offlineSubmitPaid('cash');
    assert.deepEqual(alerts, []);
    const recs = (await tillStore.records()).map((r) => r.body);
    assert.deepEqual(recs.map((r) => r.type), ['sale', 'tender']);
    assert.equal(recs[1].payload.sale_key, recs[0].key);
    assert.equal(reg.cart.length, 0);
    assert.equal(reg.offlineReceiptOpen, true);
    assert.equal(reg.offlineReceipt.total, '33.35');
    assert.equal(toasts[0][0], 'SAVED');
    assert.equal(reg.sending, false);
});

test('register: not enough cash, a card, an expired snapshot, an unknown cashier or a locked store each stop the sale and keep the cart', async () => {
    let t = await register({ tendered: 10 });
    await t.reg.offlineSubmitPaid('cash');
    assert.deepEqual(t.alerts, ['SHORT']);
    assert.equal((await t.tillStore.records()).length, 0);
    assert.equal(t.reg.cart.length, 1);

    t = await register();
    await t.reg.offlineSubmitPaid('card');
    assert.equal(t.toasts[0][0], 'Card NOT OFFLINE');
    assert.equal((await t.tillStore.records()).length, 0);

    t = await register();
    t.reg.snap = { ...t.reg.snap, valid_until: '2020-01-01T00:00:00Z' };
    await t.reg.offlineSubmitPaid('cash');
    assert.deepEqual(t.alerts, ['EXPIRED']);

    t = await register({ snap: null });
    t.reg.snap = null;
    await t.reg.offlineSubmitPaid('cash');
    assert.deepEqual(t.alerts, ['NOMENU']);

    t = await register();
    t.reg.offlineStaffId = 99; // a PIN that opened the store but is not in the snapshot
    await t.reg.offlineSubmitPaid('cash');
    assert.deepEqual(t.alerts, ['NOTALLOWED']);

    t = await register();
    t.tillStore.lock();
    await t.reg.offlineSubmitPaid('cash');
    assert.equal(t.reg.locked, true, 'back to the PIN lock; nothing written');

    t = await register({ salesBlocked: 'lease' });
    await t.reg.offlineSubmitPaid('cash');
    assert.deepEqual(t.alerts, ['BLOCKED']);
});

test('register: a full disk saves nothing and keeps the cart; a changed menu stops the sale', async () => {
    let t = await register();
    const put = t.tillStore.b.put.bind(t.tillStore.b);
    t.tillStore.b.put = async (st, k, v) => { if (st === 'outbox') throw new Error('QuotaExceededError'); return put(st, k, v); };
    await t.reg.offlineSubmitPaid('cash');
    assert.deepEqual(t.alerts, ['FULL']);
    assert.equal(t.reg.cart.length, 1);
    assert.equal(t.reg.offlineReceiptOpen === true, false);

    t = await register({ cart: [{ id: 999, name: 'Ghost', qty: 1, extras: [], options: [] }] });
    await t.reg.offlineSubmitPaid('cash');
    assert.deepEqual(t.alerts, ['MENUCHANGED']);
});

test('register: kitchen and hold say they need a connection; the banner tells the state', async () => {
    const { reg, toasts } = await register();
    reg.offlineSubmit(false);
    reg.offlineSubmit(true);
    assert.deepEqual(toasts.map((x) => x[0]), ['Kitchen NOT OFFLINE', 'Hold NOT OFFLINE']);
    assert.deepEqual(reg.offlineBanner(), { tone: 'warn', text: 'READY' });
    reg.snap = { ...reg.snap, valid_until: '2020-01-01T00:00:00Z' };
    assert.equal(reg.offlineBanner().tone, 'bad');
    reg.snap = null;
    assert.equal(reg.offlineBanner().text, 'NOMENU');
    reg.offline = false; Object.defineProperty(globalThis.navigator, 'onLine', { value: true, configurable: true });
    reg.queued = 2;
    assert.equal(reg.offlineBanner().text, 'SYNC 2…');
    reg.queued = 0; reg.offlineReview = 3;
    assert.equal(reg.offlineBanner().text, '3 to review');
    reg.offlineReview = 0;
    assert.equal(reg.offlineBanner(), null, 'online and in sync: no banner');
});

test('register: voiding an offline sale needs the refund permission, queues a void record and cannot be done twice', async () => {
    const { reg, alerts, tillStore } = await register();
    await reg.offlineSubmitPaid('cash');
    reg.refreshQueued = async () => {};
    let rows = await reg.offlineSales();
    assert.equal(rows.length, 1);
    assert.equal(typeof rows[0].id, 'number', 'rows carry the outbox id (the list is keyed by it)');
    assert.equal(rows[0].total, '33.35');

    reg.offlineStaffId = 4; // Omar: pos only
    await reg.voidOfflineSale(rows[0]);
    assert.deepEqual(alerts, ['NOTALLOWED']);

    reg.offlineStaffId = 3;
    await reg.voidOfflineSale(rows[0]);
    rows = await reg.offlineSales();
    assert.equal(rows[0].voided, true);
    const recs = (await tillStore.records()).map((r) => r.body);
    assert.deepEqual(recs.map((r) => r.type), ['sale', 'tender', 'void']);
    assert.equal(recs[2].payload.sale_key, recs[0].key);
    await reg.voidOfflineSale(rows[0]);
    assert.equal(alerts.at(-1), 'ALREADYVOID');
    assert.equal((await tillStore.records()).length, 3, 'no second void');
});

test('register: a tip offline is the same figure the cashier sees (already in offlineCalc) and the sale records', async () => {
    const { reg, tillStore } = await register({ tipAmount: 2.5, tendered: 50 });
    const shown = reg.offlineCalc().total; // offlineCalc prices the tip itself
    await reg.offlineSubmitPaid('cash');
    const sale = (await tillStore.records()).map((r) => r.body).find((r) => r.type === 'sale');
    assert.equal(sale.payload.totals.total_minor / 100, shown);
    assert.equal(sale.payload.tip_minor, 250);
});

test('a reprint shows what was recorded and paid, even after the snapshot changed', () => {
    const doc = golden();
    const priced = PO.priceCart(doc, [latte()], {});
    const [sale, tender] = PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway', tenderedMinor: 2000 });
    const later = JSON.parse(JSON.stringify(doc));
    later.parts.taxes = { mode: 'percentage', rate: '5' }; // the store changed its tax afterwards
    const r = PO.receiptFor(later, sale, tender);
    assert.equal(r.tax, '2.18');
    assert.equal(r.total, '16.68');
});

test('register: the money on the screen offline is the calculator\'s, online it is left to the old path', async () => {
    const { reg } = await register();
    const o = reg.offlineCalc();
    assert.equal(o.subtotal, 29);
    assert.equal(o.tax, 4.35);
    assert.equal(o.total, 33.35);
    reg.offline = false; Object.defineProperty(globalThis.navigator, 'onLine', { value: true, configurable: true });
    assert.equal(reg.offlineCalc(), null);
});

test('register: local stock = saved count minus waiting sales (shown only from the saved menu)', async () => {
    const { reg } = await register();
    reg.menuSource = 'snapshot';
    reg.offlinePending = { 10: 2 };
    assert.equal(reg.stockLeft({ id: 10, stock_qty: 5 }), 3);
    assert.equal(reg.stockLeft({ id: 11, stock_qty: null }), null);
    reg.menuSource = 'live';
    assert.equal(reg.stockLeft({ id: 10, stock_qty: 5 }), null);
});

test('concurrent raw add and offline addRecords keep both rows with distinct ids', async () => {
    const b = new TS.MemoryBackend();
    // a real IndexedDB write yields; make the memory backend yield too so the two writers truly overlap
    const slow = (fn) => async (...a) => { await new Promise((r) => setTimeout(r, 1)); return fn(...a); };
    b.put = slow(b.put.bind(b));
    b.batch = slow(b.batch.bind(b));
    const s = store(b);
    await s.openWithEscrow(escrow());
    const doc = golden();
    const priced = PO.priceCart(doc, [latte()], {});
    const ROUNDS = 10;
    for (let i = 0; i < ROUNDS; i++) {
        const rec = PO.saleRecords({ doc, priced, staffId: 3, orderType: 'takeaway', tenderedMinor: 2000 })[0];
        await Promise.all([s.add({ url: '/x', body: {} }), s.addRecords([rec])]);
    }
    const rows = await s.items();
    assert.equal(rows.length, ROUNDS * 2, 'every write kept its own row');
    const ids = rows.map((r) => r.id);
    assert.equal(new Set(ids).size, ids.length, 'no two rows share an id');
    assert.deepEqual([...ids].sort((a, c) => a - c), Array.from({ length: ROUNDS * 2 }, (_, n) => n + 1), 'ids are the gap-free counter 1..20');
});

// ── B: the tip offlineCalc shows is the tip the sale charges ─────────────────────────────────────────────────────────────
test('register: offlineCalc prices the tip the sale charges, and re-prices when the tip changes (memo keyed on tipAmount)', async () => {
    const { reg, tillStore } = await register({ tipAmount: 2.5, tendered: 50 });
    const shown = reg.offlineCalc().total;
    assert.equal(PO.toMinor(shown, 2), 3585, '35.85 = 33.35 payable + 2.50 tip');
    reg.tipAmount = 5;
    assert.equal(PO.toMinor(reg.offlineCalc().total, 2), 3835, 'a new tip drops the memo: 33.35 + 5.00');
    reg.tipAmount = 2.5;
    await reg.offlineSubmitPaid('cash');
    const sale = (await tillStore.records()).map((r) => r.body).find((r) => r.type === 'sale');
    assert.equal(sale.payload.totals.total_minor / 100, shown, 'the screen total is what the sale carries');
    assert.equal(sale.payload.tip_minor, 250);
    assert.equal(PO.priceCart(reg.snap, [latte({ qty: 2 })], { tipMinor: 250 }).calc.total_minor, sale.payload.totals.total_minor, 'the same figure the calculator gives');
});

// ── C: the typed decimal string is rounded exactly, half-up, with no float drift ─────────────────────────────────────────
test('toMinor rounds the typed decimal string exactly, half-up, without float multiplication', () => {
    assert.equal(PO.toMinor('1.005', 2), 101, 'the string path sees the 5 the float 1.00499 hides');
    assert.equal(PO.toMinor(1.005, 2), 101, 'a number is read as its shortest round-trip decimal string');
    assert.equal(PO.toMinor(0.1 + 0.2, 2), 30);
    assert.equal(PO.toMinor('12.5', 2), 1250);
    assert.equal(PO.toMinor('1.2345', 3), 1235, '3-decimal currency rounds half-up too');
    assert.equal(PO.toMinor('0.0005', 3), 1);
    assert.equal(PO.toMinor('1.5', 0), 2, '0-decimal currencies round to the unit');
    assert.equal(PO.toMinor('', 2), 0);
    assert.equal(PO.toMinor(-1, 2), 0, 'a negative amount is refused, not flipped');
    assert.equal(PO.toMinor('abc', 2), 0);
    assert.equal(PO.toMinor(1e-7, 2), 0, 'exponent-form numbers expand safely instead of drifting');
    assert.equal(PO.toMinor('1e-7', 2), 0);
});

// ── D: choice options are matched by id when the snapshot carries one ─────────────────────────────────────────────────────
test('choice options price by id when the snapshot carries one, by label only when it does not', () => {
    const doc = golden(); // a fresh clone: the golden fixture itself is never edited
    const item = doc.parts.catalogue.items.find((m) => m.id === 10);
    item.choice_groups = [{
        id: 300, title: 'Sugar', required: false, min_select: 0, max_select: 1,
        options: [
            { id: 401, label: 'Extra', price_minor: 50 },
            { id: 402, label: 'Extra', price_minor: 500 },
        ],
    }];
    const price = (id) => PO.priceCart(doc, [latte({ options: [{ group_id: 300, selected: [{ option: 'Extra', option_id: id }] }] })], {});
    const a = price(401), b = price(402);
    assert.equal(a.ok, true);
    assert.equal(a.lines[0].options[0].price_minor, 50, 'the same label, priced by its id');
    assert.equal(b.lines[0].options[0].price_minor, 500);
    assert.equal(a.calc.subtotal_minor, 1500);
    assert.equal(b.calc.subtotal_minor, 1950);
    assert.equal(price(999).reason, 'unknown_option', 'an id the snapshot does not know is never guessed from the label');

    // the menu keeps the option id so the register can carry it into the cart
    const m = PO.menuFromSnapshot(doc, 'en')[0].items[0].variant_groups[0];
    assert.deepEqual(m.options.map((o) => o.id), [401, 402]);
    assert.deepEqual(m.options.map((o) => o.label), ['Extra', 'Extra']);

    // the label-only golden fixture (no option ids at all) still prices by label
    const off = PO.priceCart(golden(), [latte({ options: [{ group_id: 300, selected: [{ option: 'Extra' }] }] })], {});
    assert.equal(off.ok, true);
    assert.equal(off.lines[0].options[0].price_minor, 50);
});

test('register: offlineCalc prices a choice by id and re-prices when only the id changes (memo keys on the id)', async () => {
    const doc = golden();
    doc.parts.catalogue.items.find((m) => m.id === 10).choice_groups = [{
        id: 300, title: 'Sugar', required: false, min_select: 0, max_select: 1,
        options: [{ id: 401, label: 'Extra', price_minor: 50 }, { id: 402, label: 'Extra', price_minor: 500 }],
    }];
    const line = (id) => latte({ options: [{ group_id: 300, title: 'Sugar', selected: [{ option: 'Extra', option_id: id }] }] });
    const { reg } = await register({ snap: doc, cart: [line(401)] });
    assert.equal(reg.offlineCalc().subtotal, 15, '14.50 + 0.50');
    reg.cart = [line(402)];
    assert.equal(reg.offlineCalc().subtotal, 19.5, 'the memo sees the id change: 14.50 + 5.00');
});

// ── offline money on the REAL composed register ───────────────────────────────────────────────────────────────────────────
// pos-core's posRegister() is `Object.assign({}, ...Object.values(window.PosModules), coreState)`. The three real files are
// run in one node:vm sandbox (window / document / navigator stubs), so a test drives the real pos-payments methods, the
// real pos-core money methods and the real initOffline() — which installs wrapOnlineOnly → wrapSettleMoney in that order.
const paymentsSrc = readFileSync(join(here, '../../../../assets/pos/pos-payments.js'), 'utf8');
const offlineSrc = readFileSync(join(here, '../../../../assets/pos/pos-offline.js'), 'utf8');
const coreSrc = readFileSync(join(here, '../../../../assets/pos/pos-core.js'), 'utf8');

const I18N = {
    offlineBlocked: 'BLOCKED', offlineNoMenu: 'NOMENU', offlineExpired: 'EXPIRED', offlineCashShort: 'SHORT', offlineNoStaff: 'NOSTAFF',
    offlineNotAllowed: 'NOTALLOWED', offlineMenuChanged: 'MENUCHANGED', storageFull: 'FULL', offlineSaved: 'SAVED', offlineOnly: ':action NOT OFFLINE',
    offlineLabels: { card: 'Card', hold: 'Hold', kitchen: 'Kitchen', combos: 'Combos' }, offlineVoided: 'VOIDED', offlineAlreadyVoided: 'ALREADYVOID',
    offlineReady: 'READY', offlineSyncing: 'SYNC', offlineReview: ':count to review',
    offlineCreditsDropped: 'Coupon / store credit removed: they need a connection.',
    offlineQueuedSettle: 'QUEUEDSETTLE', paid: 'PAID', change: 'CHANGE', networkError: 'NETERR',
};

async function compose(over = {}) {
    const tillStore = store();
    await tillStore.openWithEscrow(escrow());
    const alerts = [], toasts = [], watches = {};
    const sandbox = {};
    sandbox.window = sandbox; sandbox.self = sandbox; sandbox.globalThis = sandbox;
    sandbox.PosBoot = Object.assign({ staffId: 3, lang: 'en', restaurantId: 1, branch: null, tax: { charge: 15 }, routes: {}, i18n: I18N }, over.PosBoot || {});
    sandbox.PosModules = {};
    sandbox.TillCalculator = TC;
    sandbox.crypto = webcrypto;
    sandbox.document = { querySelector: () => ({ content: '' }) };
    sandbox.navigator = { onLine: true };
    sandbox.location = { origin: 'http://localhost' };
    sandbox.addEventListener = () => {};
    sandbox.removeEventListener = () => {};
    sandbox.setInterval = () => 0;
    sandbox.clearInterval = () => {};
    sandbox.alert = (m) => alerts.push(m);
    vm.createContext(sandbox);
    vm.runInContext(paymentsSrc, sandbox, { filename: 'pos-payments.js' });
    vm.runInContext(offlineSrc, sandbox, { filename: 'pos-offline.js' });
    vm.runInContext(coreSrc, sandbox, { filename: 'pos-core.js' });
    const snap = over.snap === undefined ? PO.extendValidity(golden(), Date.now()) : over.snap;
    if (snap) await tillStore.putSnapshot(snap);
    const reg = sandbox.posRegister();
    reg.tillStore = tillStore;
    reg.showToast = (m, t) => { toasts.push([m, t]); };
    reg.$watch = (k, cb) => { watches[k] = cb; };
    reg.$nextTick = null;
    Object.assign(reg, {
        offline: false, queued: 0, cart: [latte({ qty: 2, unitPrice: 14.5 })], discount: { mode: 'amount', value: 0 },
        coupon: { code: '', applied: null, checking: false, error: '' }, type: 'takeaway', orderNote: '', sending: false,
        locked: false, salesBlocked: null, cashOpen: true, tipAmount: 0, tipPct: null, tendered: 0, tenderStr: '',
        useWallet: false, walletAmt: null, customer: null, reset() { this.cart = []; }, refreshQueued() {}, loadOffline: null,
    }, over);
    reg.snap = snap;
    await reg.initOffline();
    return { reg, alerts, toasts, tillStore, sandbox, watches };
}

test('money A: offline the real register charges the snapshot total (incl. tip); online it is untouched', async () => {
    const { reg, tillStore } = await compose({
        cart: [latte({ qty: 2, unitPrice: 14.5 })], tendered: 50, tipAmount: 0,
        coupon: { code: 'SAVE5', applied: { code: 'SAVE5', value: 5 }, checking: false, error: '' },
    });
    reg.offline = true; // a request failed: offline, navigator.onLine may still be true
    reg.setTipAmount(0.1 + 0.2);
    assert.equal(reg.tipAmount, 0.3, 'a typed 0.1+0.2 tip is rounded to the currency exponent offline');

    const off = reg.offlineCalc().total;
    assert.equal(PO.toMinor(off, 2), 3365, '33.35 payable + 0.30 tip');
    assert.equal(reg.settleTotal(), off, 'offline settleTotal is the charged total, coupon ignored');
    assert.equal(reg.cashCovers(), true);
    assert.equal(reg.changeDue(), 16.35);
    assert.equal(reg.balanceDue(), 0);

    await reg.offlineSubmitPaid('cash');
    const sale = (await tillStore.records()).map((r) => r.body).find((r) => r.type === 'sale');
    assert.equal(sale.payload.totals.total_minor, PO.toMinor(off, 2), 'the screen total is the sale total');
    assert.equal(sale.payload.tip_minor, 30);

    // online: the wrapper delegates to the original pos-payments payable + tip path, coupon honoured
    const { reg: on } = await compose({
        cart: [latte({ qty: 2, unitPrice: 14.5 })], tipAmount: 0.3,
        coupon: { code: 'SAVE5', applied: { code: 'SAVE5', value: 5 }, checking: false, error: '' },
    });
    assert.equal(on.settleTotal(), on.payable() + 0.3, 'online: the original path, untouched');
    assert.equal(PO.toMinor(on.settleTotal(), 2), 2790, '(29.00 - 5 coupon) * 1.15 tax + 0.30 tip');
});

test('money B: offline setTipPct is exact in minor units (half-up) for 2-, 3- and 0-decimal currencies', async () => {
    // 2-decimal half-up: 14.50 x 15% = 2.175 -> 2.18
    const docH = golden(); docH.parts.taxes = { mode: 'none' };
    let t = await compose({ cart: [latte({ qty: 1, unitPrice: 14.5 })], snap: docH, tipAmount: 0 });
    t.reg.offline = true;
    t.reg.setTipPct(15);
    assert.equal(PO.toMinor(t.reg.tipAmount, 2), 218, '217.5 minor rounds half-up to 218');

    // 2-decimal golden cart: 16.68 total, 10% -> 167 minor, matching the calculator
    t = await compose({ cart: [latte({ qty: 1, unitPrice: 14.5 })], tipAmount: 0 });
    t.reg.offline = true;
    t.reg.setTipPct(10);
    let o = t.reg.offlineCalc();
    const tip2 = PO.toMinor(t.reg.tipAmount, 2);
    assert.equal(Math.round(t.reg.tipAmount * 100), tip2, 'exactly representable at 2 decimals');
    assert.equal(PO.toMinor(o.total, 2), TC.price({ lines: [{ unit_minor: 1450, qty: 1 }], tax: { mode: 'percentage', rate: '15' }, tip_minor: tip2 }).total_minor);

    // 3-decimal
    const doc3 = golden(); doc3.parts.store.exponent = 3; doc3.parts.catalogue.items.find((m) => m.id === 10).price_minor = 14500;
    t = await compose({ cart: [latte({ qty: 1, unitPrice: 14.5 })], snap: doc3, tipAmount: 0 });
    t.reg.offline = true;
    t.reg.setTipPct(10);
    o = t.reg.offlineCalc();
    const tip3 = PO.toMinor(t.reg.tipAmount, 3);
    assert.equal(Math.round(t.reg.tipAmount * 1000), tip3, 'exactly representable at 3 decimals');
    assert.equal(PO.toMinor(o.total, 3), TC.price({ lines: [{ unit_minor: 14500, qty: 1 }], tax: { mode: 'percentage', rate: '15' }, tip_minor: tip3 }).total_minor);
    assert.equal(o.total, 18.343);

    // 0-decimal
    const doc0 = golden(); doc0.parts.store.exponent = 0; doc0.parts.catalogue.items.find((m) => m.id === 10).price_minor = 145;
    t = await compose({ cart: [latte({ qty: 1, unitPrice: 14.5 })], snap: doc0, tipAmount: 0 });
    t.reg.offline = true;
    t.reg.setTipPct(10);
    o = t.reg.offlineCalc();
    const tip0 = PO.toMinor(t.reg.tipAmount, 0);
    assert.equal(Math.round(t.reg.tipAmount), tip0, 'a whole minor unit at 0 decimals');
    assert.equal(PO.toMinor(o.total, 0), TC.price({ lines: [{ unit_minor: 145, qty: 1 }], tax: { mode: 'percentage', rate: '15' }, tip_minor: tip0 }).total_minor);
    assert.equal(o.total, 184);
});

test('money C: going offline drops the coupon + wallet with a warning; the charged sale total is the offline price; online untouched', async () => {
    const { reg, toasts, tillStore } = await compose({
        cart: [latte({ qty: 1, unitPrice: 14.5 })], tendered: 50, tipAmount: 0,
        coupon: { code: 'SAVE5', applied: { code: 'SAVE5', value: 5 }, checking: false, error: '' },
        useWallet: true, walletAmt: null, customer: { id: 1, wallet: 10 },
    });
    reg.offline = true;
    const charged = reg.offlineCalc().total;
    assert.equal(PO.toMinor(charged, 2), 1668, '16.68: the offline price ignores coupon and wallet');
    assert.ok(reg.payable() < charged, 'the core payable still subtracts coupon + wallet');

    assert.equal(reg.dropOnlineCredits(), true);
    assert.equal(reg.coupon.applied, null);
    assert.equal(reg.coupon.code, '');
    assert.equal(reg.coupon.error, '');
    assert.equal(reg.useWallet, false);
    assert.equal(reg.walletAmt, null);
    assert.deepEqual(toasts.at(-1), ['Coupon / store credit removed: they need a connection.', 'warn']);
    assert.equal(reg.settleTotal(), reg.offlineCalc().total, 'settleTotal is the offline charged total');

    // the meaningful check: the sale that is recorded carries the charged offline total (not a tautology on the screen value)
    await reg.offlineSubmitPaid('cash');
    const sale = (await tillStore.records()).map((r) => r.body).find((r) => r.type === 'sale');
    assert.equal(sale.payload.totals.total_minor, 1668, 'the recorded sale total is the offline charged price, coupon + wallet gone');
    assert.equal(sale.payload.totals.subtotal_minor, 1450);

    // online it leaves the credits alone
    const { reg: on, toasts: onToasts } = await compose({
        cart: [latte({ qty: 1, unitPrice: 14.5 })],
        coupon: { code: 'SAVE5', applied: { code: 'SAVE5', value: 5 }, checking: false, error: '' },
        useWallet: true, walletAmt: null, customer: { id: 1, wallet: 10 },
    });
    const before = onToasts.length;
    assert.equal(on.dropOnlineCredits(), false);
    assert.ok(on.coupon.applied, 'coupon untouched online');
    assert.equal(on.useWallet, true, 'wallet untouched online');
    assert.equal(onToasts.length, before, 'no warning online');
});

test('money D: this.offline flipping true with no browser offline event still drops the credits (the initOffline watcher)', async () => {
    const { reg, sandbox, toasts, watches } = await compose({
        cart: [latte({ qty: 1, unitPrice: 14.5 })],
        coupon: { code: 'SAVE5', applied: { code: 'SAVE5', value: 5 }, checking: false, error: '' },
        useWallet: true, walletAmt: null, customer: { id: 1, wallet: 10 },
    });
    assert.ok(reg.coupon.applied, 'online at init: nothing dropped');
    assert.equal(typeof watches.offline, 'function', 'initOffline registered an offline watcher');

    // a failed request flips this.offline, but navigator.onLine never changes (captive portal / server gone)
    reg.offline = true;
    assert.equal(sandbox.navigator.onLine, true, 'no browser offline event fired');
    watches.offline(true);
    assert.equal(reg.coupon.applied, null);
    assert.equal(reg.useWallet, false);
    assert.deepEqual(toasts.at(-1), ['Coupon / store credit removed: they need a connection.', 'warn']);
});

test('money E: offline openSettle drops credits only for cash; a card checkout shows only the online-only message', async () => {
    const { reg, toasts } = await compose({
        cart: [latte({ qty: 1, unitPrice: 14.5 })],
        coupon: { code: 'SAVE5', applied: { code: 'SAVE5', value: 5 }, checking: false, error: '' },
        useWallet: true, walletAmt: null, customer: { id: 1, wallet: 10 },
    });
    reg.offline = true;

    assert.equal(reg.openSettle('card'), false);
    assert.ok(reg.coupon.applied, 'offline card keeps the credits: the checkout is refused before them');
    assert.equal(reg.useWallet, true);
    assert.deepEqual(toasts.at(-1), ['Card NOT OFFLINE', 'warn']);

    reg.openSettle('cash');
    assert.equal(reg.coupon.applied, null);
    assert.equal(reg.useWallet, false);
    assert.deepEqual(toasts.at(-1), ['Coupon / store credit removed: they need a connection.', 'warn']);
    assert.equal(reg.settleOpen, true);
});

test('money F: offline presetTender / setTipPct / useSnapshotMenu from the snapshot; an unpriceable cart falls back', async () => {
    const { reg, toasts } = await compose({
        cart: [latte({ qty: 2, unitPrice: 14.5 })], tipAmount: 0, tendered: 0,
        coupon: { code: 'SAVE5', applied: { code: 'SAVE5', value: 5 }, checking: false, error: '' },
        useWallet: true, walletAmt: null, customer: { id: 1, wallet: 10 },
    });
    reg.offline = true;

    // useSnapshotMenu drops the online-only credits and builds the menu from the snapshot
    reg.useSnapshotMenu();
    assert.equal(reg.menuSource, 'snapshot');
    assert.equal(reg.coupon.applied, null);
    assert.equal(reg.useWallet, false);
    assert.deepEqual(toasts.at(-1), ['Coupon / store credit removed: they need a connection.', 'warn']);

    // presetTender('exact') offline = the charged total, formatted at the currency exponent
    reg.setTipAmount(2.5);
    const charged = reg.settleTotal();
    reg.presetTender('exact');
    assert.equal(reg.tenderStr, PO.fmt(PO.toMinor(charged, 2), 2));
    assert.equal(reg.tendered, PO.toMinor(charged, 2) / 100);

    // setTipPct(0) and setTipPct(null) both clear the tip
    reg.setTipPct(0);
    assert.equal(reg.tipAmount, 0);
    reg.setTipAmount(2.5);
    reg.setTipPct(null);
    assert.equal(reg.tipAmount, 0);

    // a fractional percentage is exact: 33.35 x 12.5% = 4.16875 -> 417 minor
    reg.setTipPct(12.5);
    assert.equal(PO.toMinor(reg.tipAmount, 2), 417);
    assert.equal(reg.tipPct, 12.5);

    // an unpriceable cart: offlineCalc is null, setTipPct is 0 and settleTotal falls back to the original path
    reg.cart = [{ id: 999, name: 'Ghost', qty: 1, unitPrice: 14.5, extras: [], options: [] }];
    assert.equal(reg.offlineCalc(), null);
    reg.setTipPct(10);
    assert.equal(reg.tipAmount, 0);
    assert.equal(PO.toMinor(reg.settleTotal(), 2), PO.toMinor(reg.payable(), 2), 'settleTotal falls back to payable when the cart cannot be priced');
});

