// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { createCart } from '../../../../assets/storefront/cart.js';
import { createQueue } from '../../../../assets/storefront/queue.js';
import { UUID_RE } from '../../../../assets/storefront/util.js';
import { bootData, db, fakeApi, money, quoteOf, tick } from './helpers.mjs';

const ITEM = '0198aaaa-0000-7000-8000-000000000001';
const ITEM2 = '0198aaaa-0000-7000-8000-000000000002';

function setup({ total = 1250, handlers = {}, boot = bootData(), store = db() } = {}) {
    let current = total;
    const api = fakeApi(Object.assign({
        '/bloom/quote': () => quoteOf(current),
        '/bloom/send-order': () => ({ success: true, track_url: '/track/abc' }),
    }, handlers));
    const queue = createQueue({ boot, db: store, api });
    const cart = createCart({ boot, db: store, api, queue, debounceMs: 0 });
    return { api, cart, queue, store, boot, setTotal: (n) => { current = n; } };
}

test('the quote request carries public ids only and the shown total is the server total, untouched', async () => {
    const { cart, api } = setup({ total: 1337 });
    await cart.ready();
    await cart.add(ITEM, { qty: 2, variant: 'v1', options: [ITEM2], name: 'Latte' });
    const q = api.calls.filter((c) => c.url === '/bloom/quote').at(-1);
    assert.deepEqual(q.body, { lines: [{ item: ITEM, qty: 2, variant: 'v1', options: [ITEM2] }], order_type: 'dine_in' });
    assert.deepEqual(cart.snapshot().quote.total, money(1337)); // not recomputed, not reformatted
});

test('the same line merges, quantity is clamped, 0 removes, an empty cart has no quote', async () => {
    const { cart, api } = setup();
    await cart.ready();
    await cart.add(ITEM);
    await cart.add(ITEM);
    assert.equal(cart.count(), 2);
    const key = cart.state.lines[0].key;
    await cart.update(key, 100000);
    assert.equal(cart.count(), 999);
    await cart.update(key, 0);
    assert.equal(cart.count(), 0);
    assert.equal(cart.snapshot().quote, null);
    assert.ok(api.calls.length >= 3);
});

test('an older quote answer never overwrites a newer one', async () => {
    let n = 0;
    const resolvers = [];
    const { cart, api } = setup({
        handlers: { '/bloom/quote': () => new Promise((res) => { const i = n++; resolvers.push(() => res(quoteOf(i === 0 ? 111 : 222))); }) },
    });
    await cart.ready();
    cart.add(ITEM);
    await tick();
    cart.add(ITEM2);
    await tick();
    resolvers[1](); // newer answers first
    await tick();
    resolvers[0](); // the stale one arrives late
    await tick();
    assert.equal(cart.snapshot().quote.total.minor, 222);
    assert.equal(api.calls.length, 2);
});

test('place: the same UUIDv7 is sent on every retry of one intent, and the cart is cleared once placed', async () => {
    let fail = true;
    const { cart, api } = setup({ handlers: { '/bloom/send-order': () => (fail ? { success: false, message: 'busy' } : { success: true, track_url: '/t' }) } });
    await cart.ready();
    await cart.add(ITEM);
    const first = await cart.place({ name: 'Sam', pay_via: 'cod' });
    assert.equal(first.status, 'failed');
    fail = false;
    const second = await cart.place({ name: 'Sam', pay_via: 'cod' });
    assert.equal(second.status, 'placed');
    const sends = api.calls.filter((c) => c.url === '/bloom/send-order');
    assert.equal(sends.length, 2);
    assert.match(sends[0].body.client_order_id, UUID_RE);
    assert.equal(sends[0].body.client_order_id, sends[1].body.client_order_id);
    assert.equal(sends[1].headers['Idempotency-Key'], sends[1].body.client_order_id);
    assert.equal(cart.count(), 0);
    await cart.add(ITEM);
    await cart.place({ name: 'Sam', pay_via: 'cod' });
    const third = api.calls.filter((c) => c.url === '/bloom/send-order').at(-1);
    assert.notEqual(third.body.client_order_id, sends[0].body.client_order_id, 'the next order gets a fresh id');
});

test('place: two taps at once send one order', async () => {
    const { cart, api } = setup();
    await cart.ready();
    await cart.add(ITEM);
    const [a, b] = await Promise.all([cart.place({ pay_via: 'cod' }), cart.place({ pay_via: 'cod' })]);
    assert.deepEqual([a.status, b.status].sort(), ['busy', 'placed']);
    assert.equal(api.calls.filter((c) => c.url === '/bloom/send-order').length, 1);
});

test('place: a changed total is shown and must be confirmed again; nothing is sent before that', async () => {
    const { cart, api, setTotal } = setup({ total: 1000 });
    await cart.ready();
    await cart.add(ITEM);
    assert.equal(cart.snapshot().quote.total.minor, 1000);
    setTotal(1200); // price changed on the server after the shopper saw 1000
    const r = await cart.place({ pay_via: 'cod' });
    assert.equal(r.status, 'confirm');
    assert.equal(r.previous.total.minor, 1000);
    assert.equal(r.current.total.minor, 1200);
    assert.equal(api.calls.filter((c) => c.url === '/bloom/send-order').length, 0);
    const ok = await cart.place({ pay_via: 'cod' }, { confirm: true });
    assert.equal(ok.status, 'placed');
    assert.equal(api.calls.filter((c) => c.url === '/bloom/send-order').length, 1);
});

test('place: a 409 (same id, other cart) mints a new id and never claims success', async () => {
    const { cart } = setup({ handlers: { '/bloom/send-order': () => ({ __status: 409, conflict: true, success: false, message: 'other cart' }) } });
    await cart.ready();
    await cart.add(ITEM);
    const before = (await cart.place({ pay_via: 'cod' })).status;
    assert.equal(before, 'conflict');
    assert.equal(cart.count(), 1, 'the cart is kept');
});

test('offline: dine-in paid on collection is queued with its UUIDv7 and the cart is cleared', async () => {
    const { cart, api, queue } = setup({ total: 900 });
    await cart.ready();
    await cart.add(ITEM);
    api.offline = true;
    const r = await cart.place({ name: 'Sam', pay_via: 'cod', table: 'T4' });
    assert.equal(r.status, 'queued');
    const rows = await queue.list();
    assert.equal(rows.length, 1);
    assert.equal(rows[0].state, 'waiting');
    assert.match(rows[0].id, UUID_RE);
    assert.equal(rows[0].body.client_order_id, rows[0].id);
    assert.ok(rows[0].body.queued_at);
    assert.equal(rows[0].total_minor, 900);
    assert.equal(cart.count(), 0);
});

test('offline: delivery and online-paid orders never queue ("connect to place this order")', async () => {
    const { cart, api, queue } = setup();
    await cart.ready();
    await cart.add(ITEM);
    api.offline = true;
    assert.equal((await cart.place({ pay_via: 'pay_online' })).status, 'offline', 'online payment');
    cart.setOrderType('delivery');
    assert.equal((await cart.place({ pay_via: 'cod' })).status, 'offline', 'delivery');
    assert.equal((await queue.list()).length, 0);
    assert.equal(cart.count(), 1, 'the cart is kept');
});

test('the cart survives a reload (same store + host database)', async () => {
    const store = db();
    const a = setup({ store });
    await a.cart.ready();
    await a.cart.add(ITEM, { qty: 3 });
    const b = setup({ store });
    await b.cart.ready();
    assert.equal(b.cart.count(), 3);
});

test('preview quotes one hypothetical line on the server and leaves the cart alone', async () => {
    const { cart, api } = setup({ total: 4200 });
    await cart.ready();
    const r = await cart.preview({ item: ITEM, qty: 3, variant: 'v1', options: [ITEM2] });
    assert.equal(r.ok, true);
    assert.deepEqual(r.quote.total, money(4200)); // the server's total, untouched
    const q = api.calls.at(-1);
    assert.deepEqual(q.body, { lines: [{ item: ITEM, qty: 3, variant: 'v1', options: [ITEM2] }], order_type: 'dine_in' });
    assert.equal(cart.count(), 0);
    assert.equal(cart.snapshot().quote, null);
});

test('preview says so when the server cannot be reached or refuses the line', async () => {
    const { cart, api } = setup();
    await cart.ready();
    api.offline = true;
    assert.deepEqual(await cart.preview({ item: ITEM }), { ok: false, offline: true, message: 'offline' });
    api.offline = false;
    const r = await setup({ handlers: { '/bloom/quote': () => ({ throw: { message: 'no longer available', status: 422 } }) } }).cart.preview({ item: ITEM });
    assert.equal(r.ok, false);
    assert.equal(r.offline, false);
});

// P3-08 (aisle): goods carts carry the shipping method, the address and the server's quote hash.
test('a goods cart sends the shipping method and address with every quote, and keeps the server quote hash', async () => {
    const { cart, api } = setup({
        handlers: { '/bloom/quote': () => ({ quote: quoteOf(2650).quote, quote_hash: 'h-1' }) },
    });
    await cart.ready();
    await cart.add(ITEM, { variant: 'v1' });
    await cart.setShipping('ship-std', { country: 'SA', city: 'Riyadh' });
    const q = api.calls.filter((c) => c.url === '/bloom/quote').at(-1);
    assert.equal(q.body.shipping_method, 'ship-std');
    assert.deepEqual(q.body.address, { country: 'SA', city: 'Riyadh' });
    assert.equal(cart.state.quoteHash, 'h-1');
});

test('placing a goods order sends the quote hash the shopper saw, then forgets the shipping and the hash', async () => {
    const { cart, api } = setup({
        handlers: { '/bloom/quote': () => ({ quote: quoteOf(2650).quote, quote_hash: 'h-2' }) },
    });
    await cart.ready();
    await cart.add(ITEM, { variant: 'v1' });
    await cart.setShipping('ship-exp', { country: 'SA', city: 'Riyadh' });
    await cart.place({ name: 'Sam', pay_via: 'cod' });
    const order = api.calls.filter((c) => c.url === '/bloom/send-order').at(-1);
    assert.equal(order.body.quote_hash, 'h-2');
    assert.equal(order.body.shipping_method, 'ship-exp');
    assert.equal(cart.state.shipping, null);
    assert.equal(cart.state.quoteHash, null);
});

test('clearing the shipping method (null) drops it from the next quote', async () => {
    const { cart, api } = setup();
    await cart.ready();
    await cart.add(ITEM);
    await cart.setShipping('ship-std', { country: 'SA' });
    await cart.setShipping(null);
    const q = api.calls.filter((c) => c.url === '/bloom/quote').at(-1);
    assert.equal('shipping_method' in q.body, false);
});

const SUB = '0198aaaa-0000-7000-8000-0000000000aa';
const SLOT = 'dslot_d0_hrrhc_0e594bc5d3';
const HOLD = '0198aaaa-0000-7000-8000-0000000000bb';

test('grocery: the slot, the hold, express and the out-of-stock choice ride on the quote and the order; the hold token is a header', async () => {
    const { cart, api } = setup({ handlers: { '/bloom/quote': () => ({ quote: { lines: [], rows: [], total: money(4190) }, quote_hash: 'qh_1', warnings: [{ code: 'below_minimum', message: 'm' }], requires: ['slot'], payment_methods: [{ id: 'cod' }], payment_modes: ['pay_later'], weighed: { cap_percent: 10 } }) } });
    await cart.ready();
    await cart.add(ITEM, { substitution: SUB });
    await cart.setExtras({ slot_id: SLOT, hold_id: HOLD, express: false, substitution_preference: SUB }, 'hld_token');

    const q = api.calls.filter((c) => c.url === '/bloom/quote').at(-1);
    assert.deepEqual(q.body.lines, [{ item: ITEM, qty: 1, substitution: SUB }]);
    assert.equal(q.body.slot_id, SLOT);
    assert.equal(q.body.hold_id, HOLD);
    assert.equal(q.body.substitution_preference, SUB);
    assert.ok(!('express' in q.body), 'false is not sent');

    // what else the quote answered is read-only for the theme
    const meta = cart.snapshot().meta;
    assert.deepEqual(meta.requires, ['slot']);
    assert.deepEqual(meta.warnings.map((w) => w.code), ['below_minimum']);
    assert.equal(meta.weighed.cap_percent, 10);
    meta.requires.push('x');
    assert.deepEqual(cart.snapshot().meta.requires, ['slot'], 'a copy');

    const placed = await cart.place({ name: 'Sam', pay_via: 'cod' });
    assert.equal(placed.status, 'placed');
    const send = api.calls.filter((c) => c.url === '/bloom/send-order').at(-1);
    assert.equal(send.body.slot_id, SLOT);
    assert.equal(send.body.hold_id, HOLD);
    assert.equal(send.headers['X-Hold-Token'], 'hld_token');
    assert.equal(send.headers['Idempotency-Key'], send.body.client_order_id);
    assert.equal(send.body.lines[0].substitution, SUB);

    // an order forgets its time, its hold and its choice
    await cart.add(ITEM);
    const next = api.calls.filter((c) => c.url === '/bloom/quote').at(-1);
    assert.ok(!('slot_id' in next.body) && !('substitution_preference' in next.body));
});

test('grocery: a different out-of-stock choice is a different line; clearing an extra removes only that key', async () => {
    const { cart, api } = setup();
    await cart.ready();
    await cart.add(ITEM);
    await cart.add(ITEM, { substitution: SUB });
    await cart.add(ITEM, { substitution: SUB });
    assert.deepEqual(cart.state.lines.map((l) => [l.qty, l.substitution]), [[1, null], [2, SUB]]);

    await cart.setExtras({ slot_id: SLOT, hold_id: HOLD, express: true }, 'tok');
    await cart.setExtras({ slot_id: null, hold_id: null }, null);
    const q = api.calls.filter((c) => c.url === '/bloom/quote').at(-1);
    assert.equal(q.body.express, true);
    assert.ok(!('slot_id' in q.body) && !('hold_id' in q.body));
    await cart.place({ name: 'Sam' });
    const send = api.calls.filter((c) => c.url === '/bloom/send-order').at(-1);
    assert.ok(!('X-Hold-Token' in send.headers), 'no token once the hold is dropped');
});
