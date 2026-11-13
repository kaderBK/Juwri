// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { createQueue } from '../../../../assets/storefront/queue.js';
import { bootData, db, fakeApi, quoteOf } from './helpers.mjs';

const ID = '0198bbbb-0000-7000-8000-000000000001';
const entry = (over = {}) => Object.assign({
    id: ID, body: { client_order_id: ID, lines: [{ item: 'i', qty: 1 }], pay_via: 'cod' }, lines: [{ item: 'i', qty: 1 }],
    order_type: 'dine_in', total_minor: 1000, total_label: 'SAR 10.00', coupon: null, tip_minor: 0, branch: null,
}, over);

function setup({ boot = bootData(), total = 1000, place = () => ({ success: true, track_url: '/t' }), now = () => Date.now() } = {}) {
    const api = fakeApi({ '/bloom/quote': () => quoteOf(total), '/bloom/send-order': place });
    const store = db();
    const queue = createQueue({ boot, db: store, api, now, isOnline: () => true });
    return { api, store, queue };
}
const sends = (api) => api.calls.filter((c) => c.url === '/bloom/send-order');

test('eligibility: dine-in and pay-on-collect pickup only; delivery, online payment and a switched-off queue never', () => {
    const { queue } = setup();
    assert.equal(queue.eligible('dine_in', { pay_via: 'cod' }), true);
    assert.equal(queue.eligible('takeaway', {}), true);
    assert.equal(queue.eligible('pickup', {}), true);
    assert.equal(queue.eligible('delivery', {}), false);
    assert.equal(queue.eligible('ship', {}), false);
    assert.equal(queue.eligible('dine_in', { pay_via: 'pay_online' }), false);
    const off = setup({ boot: bootData({ offline_queue: { enabled: false, order_types: ['dine_in'], expiry_min: 30 } }) }).queue;
    assert.equal(off.eligible('dine_in', {}), false);
});

test('reconnect with an unchanged total sends the order once, with the id it was queued under', async () => {
    const { queue, api } = setup();
    await queue.enqueue(entry());
    await queue.flush();
    await queue.flush(); // a second online event must not send again
    assert.equal(sends(api).length, 1);
    assert.equal(sends(api)[0].body.client_order_id, ID);
    assert.equal(sends(api)[0].headers['Idempotency-Key'], ID);
    assert.equal(sends(api)[0].headers['X-Juwri-Queued'], '1');
    assert.equal((await queue.list())[0].state, 'placed');
});

test('a changed total waits for the shopper; confirming sends it (same id); nothing is sent before', async () => {
    const { queue, api } = setup({ total: 1300 });
    await queue.enqueue(entry({ total_minor: 1000 }));
    await queue.flush();
    const [row] = await queue.list();
    assert.equal(row.state, 'confirm');
    assert.equal(row.old, 'SAR 10.00');
    assert.equal(row.new_total.minor, 1300);
    assert.equal(sends(api).length, 0);
    await queue.confirm(ID);
    assert.equal(sends(api).length, 1);
    assert.equal((await queue.list())[0].state, 'placed');
});

test('an item that vanished (quote refused) also needs the shopper', async () => {
    const api = fakeApi({ '/bloom/quote': () => ({ throw: { status: 422, message: 'gone' } }), '/bloom/send-order': () => ({ success: true }) });
    const queue = createQueue({ boot: bootData(), db: db(), api, isOnline: () => true });
    await queue.enqueue(entry());
    await queue.flush();
    assert.equal((await queue.list())[0].state, 'confirm');
    assert.equal(sends(api).length, 0);
});

test('an order past the store-set limit expires and is never sent; confirming an expired one expires it', async () => {
    let t = 1_000_000;
    const { queue, api } = setup({ now: () => t, boot: bootData({ offline_queue: { enabled: true, order_types: ['dine_in'], expiry_min: 30 } }) });
    await queue.enqueue(entry());
    t += 31 * 60000;
    await queue.flush();
    assert.equal((await queue.list())[0].state, 'expired');
    assert.equal(sends(api).length, 0);
});

test('the store-set expiry is used (not a fixed 30 min)', async () => {
    let t = 1_000_000;
    const { queue } = setup({ now: () => t, boot: bootData({ offline_queue: { enabled: true, order_types: ['dine_in'], expiry_min: 5 } }) });
    const item = await queue.enqueue(entry());
    assert.equal(item.expires_at - item.created_at, 5 * 60000);
});

test('kill switch: when the boot data says off, the next flush drops every unsent order and keeps receipts', async () => {
    const boot = bootData();
    const { queue, api, store } = setup({ boot });
    await queue.enqueue(entry());
    await queue.enqueue(entry({ id: '0198bbbb-0000-7000-8000-000000000002' }));
    await store.put('queue', 'done', { id: 'done', state: 'placed', created_at: 1, placed_at: Date.now() });
    boot.offline_queue.enabled = false; // next page load carries the new effective switch
    await queue.flush();
    const left = await queue.list();
    assert.deepEqual(left.map((r) => r.id), ['done']);
    assert.equal(sends(api).length, 0);
});

test('kill switch at the server: an offline_queue_disabled answer drops the queue instead of retrying', async () => {
    const { queue } = setup({ place: () => ({ __status: 403, success: false, code: 'offline_queue_disabled', message: 'off' }) });
    await queue.enqueue(entry());
    await queue.flush();
    assert.equal((await queue.list()).length, 0);
});

test('idempotent replay: the server answering "duplicate" is a placed order, never a second one', async () => {
    const { queue, api } = setup({ place: () => ({ success: true, duplicate: true, track_url: '/t' }) });
    await queue.enqueue(entry());
    await queue.flush();
    const [row] = await queue.list();
    assert.equal(row.state, 'placed');
    assert.equal(row.response.duplicate, true);
    assert.equal(sends(api).length, 1);
});

test('losing the network mid-send puts the order back to waiting, with the same id', async () => {
    const { queue, api } = setup();
    await queue.enqueue(entry());
    const original = api.post.bind(api);
    api.post = async function (url, ...rest) { if (url === '/bloom/send-order') { const e = new Error('offline'); e.offline = true; throw e; } return original(url, ...rest); };
    await queue.flush();
    const [row] = await queue.list();
    assert.equal(row.state, 'waiting');
    assert.equal(row.id, ID);
});
