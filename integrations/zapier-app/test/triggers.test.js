// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const path = require('path');
const fs = require('fs');

const { makeZ, makeBundle, paginator } = require('./_harness');

const order_created = require('../triggers/order_created');
const order_status_changed = require('../triggers/order_status_changed');
const order_paid = require('../triggers/order_paid');
const customer_created = require('../triggers/customer_created');
const reservation_created = require('../triggers/reservation_created');
const refund_completed = require('../triggers/refund_completed');
const restaurant = require('../triggers/restaurant');

const FIXTURE_DIR = path.resolve(__dirname, '..', 'samples');
function fixture(event) {
  return JSON.parse(fs.readFileSync(path.join(FIXTURE_DIR, `${event}.json`), 'utf8'));
}

// Recursive key-set equality — the "transformed row keys === fixture keys" bar.
function keySet(obj) {
  return Object.keys(obj).sort();
}
function assertSameShape(actual, expected, where) {
  assert.deepEqual(
    keySet(actual),
    keySet(expected),
    `key set differs at ${where || 'root'}: got [${keySet(actual)}] want [${keySet(expected)}]`
  );
  for (const k of Object.keys(expected)) {
    const ev = expected[k];
    if (ev && typeof ev === 'object' && !Array.isArray(ev)) {
      assertSameShape(actual[k], ev, where ? `${where}.${k}` : k);
    }
  }
}

// Sample keys ⊆ live-result keys (Zapier T004/T006), recursively.
function assertSubset(sample, live, where) {
  for (const k of Object.keys(sample)) {
    assert.ok(
      Object.prototype.hasOwnProperty.call(live, k),
      `live result missing sample key "${(where ? where + '.' : '') + k}"`
    );
    const sv = sample[k];
    if (sv && typeof sv === 'object' && !Array.isArray(sv)) {
      assertSubset(sv, live[k], where ? `${where}.${k}` : k);
    }
  }
}

const ORDER_ROW = {
  id: 4812,
  status: 'new',
  type: 'delivery',
  table_number: null,
  customer_name: 'Jane Doe',
  total: 96.5,
  is_paid: false,
  created_at: '2026-07-08T14:03:11+00:00',
};
const PAID_ROW = Object.assign({}, ORDER_ROW, { id: 4813, status: 'completed', is_paid: true });
const CUSTOMER_ROW = {
  id: 8841,
  name: 'Jordan Rivera',
  phone: '+15551234567',
  email: 'jr@example.com',
  orders_count: 0,
  total_spent: 0,
  last_order_at: null,
  tags: [],
  created_at: '2026-07-08T14:03:11+00:00',
};
const RESERVATION_ROW = {
  id: 512,
  name: 'Sam Lee',
  phone: '+15559876543',
  email: 's@example.com',
  party_size: 4,
  reserved_at: '2026-07-09T19:00:00+00:00',
  table_number: 12,
  status: 'pending',
  created_at: '2026-07-08T14:03:11+00:00',
};

// ── Subscribe / unsubscribe bodies (the same for every REST-Hook trigger) ──

test('performSubscribe posts { url: targetUrl, events:[event] } to /webhook-endpoints', async () => {
  const created = { id: 99, url: 'https://hooks.zapier.com/x', events: ['order.created'], active: true, created_at: '2026-07-08T14:03:11+00:00', secret: 's3cr3t' };
  const { z, calls } = makeZ({ status: 201, data: created });
  const bundle = makeBundle({ targetUrl: 'https://hooks.zapier.com/x', inputData: { restaurant_id: 37 } });

  const result = await order_created.operation.performSubscribe(z, bundle);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].method, 'POST');
  assert.equal(calls[0].url, 'https://demo.test/api/v1/webhook-endpoints');
  assert.deepEqual(calls[0].body, { url: 'https://hooks.zapier.com/x', events: ['order.created'] });
  assert.equal(result.id, 99); // → bundle.subscribeData
});

test('every trigger subscribes with ITS OWN event key', async () => {
  const cases = [
    [order_created, 'order.created'],
    [order_status_changed, 'order.status_changed'],
    [order_paid, 'order.paid'],
    [customer_created, 'customer.created'],
    [reservation_created, 'reservation.created'],
    [refund_completed, 'refund.completed'],
  ];
  for (const [trig, event] of cases) {
    const { z, calls } = makeZ({ status: 201, data: { id: 1 } });
    const bundle = makeBundle({ targetUrl: 'https://hooks.zapier.com/y', inputData: { restaurant_id: 37 } });
    await trig.operation.performSubscribe(z, bundle);
    assert.deepEqual(calls[0].body, { url: 'https://hooks.zapier.com/y', events: [event] }, `subscribe body for ${event}`);
  }
});

test('performUnsubscribe DELETEs the subscribed id and is 404-idempotent', async () => {
  const { z, calls } = makeZ({ status: 204, data: undefined });
  const bundle = makeBundle({ subscribeData: { id: 99 } });
  const ok = await order_created.operation.performUnsubscribe(z, bundle);
  assert.equal(calls[0].method, 'DELETE');
  assert.equal(calls[0].url, 'https://demo.test/api/v1/webhook-endpoints/99');
  assert.deepEqual(ok, { id: 99 });

  // Second delete → platform 404; the package must treat it as success, not throw.
  const { z: z2 } = makeZ({ status: 404, data: { message: 'Webhook endpoint not found.', code: 'not_found' } });
  const ok2 = await order_created.operation.performUnsubscribe(z2, bundle);
  assert.deepEqual(ok2, { id: 99 });
});

test('performUnsubscribe still throws on a real failure (500)', async () => {
  const { z } = makeZ({ status: 500, data: { message: 'boom', code: 'server_error' } });
  await assert.rejects(
    () => order_created.operation.performUnsubscribe(z, makeBundle({ subscribeData: { id: 5 } })),
    /boom/
  );
});

test('perform returns the delivered envelope as an array', () => {
  const envelope = fixture('order.created');
  const out = order_created.operation.perform(null, makeBundle({ cleanedRequest: envelope }));
  assert.ok(Array.isArray(out));
  assert.equal(out.length, 1);
  assert.deepEqual(out[0], envelope);
});

// ── performList transforms → EXACT fixture envelope shape ──

test('order.created performList transforms a row into the exact fixture shape (per_page, not limit)', async () => {
  const { z, calls } = makeZ({ status: 200, data: paginator([ORDER_ROW]) });
  const bundle = makeBundle({ inputData: { restaurant_id: 37 } });
  const list = await order_created.operation.performList(z, bundle);

  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/orders');
  assert.equal(calls[0].params.per_page, 100);
  assert.ok(!('limit' in calls[0].params), 'must NOT send a `limit` param');

  assert.equal(list.length, 1);
  assertSameShape(list[0], fixture('order.created'), 'order.created');
  assert.equal(list[0].data.order_id, 4812);
  assert.equal(list[0].data.restaurant_id, 37); // injected from the polled restaurant
  assert.equal(list[0].event, 'order.created');
  assert.equal(list[0].id, 'order.created:4812'); // stable poll dedup id
  assert.equal(list[0].data.currency, null); // absent on the index row
});

test('order.status_changed performList shape === fixture', async () => {
  const { z } = makeZ({ status: 200, data: paginator([ORDER_ROW]) });
  const list = await order_status_changed.operation.performList(z, makeBundle({ inputData: { restaurant_id: 37 } }));
  assertSameShape(list[0], fixture('order.status_changed'), 'order.status_changed');
  assert.equal(list[0].data.new_status, 'new');
});

test('order.paid performList shape === fixture AND filters to paid rows only', async () => {
  const { z } = makeZ({ status: 200, data: paginator([ORDER_ROW, PAID_ROW]) });
  const list = await order_paid.operation.performList(z, makeBundle({ inputData: { restaurant_id: 37 } }));
  assert.equal(list.length, 1, 'only the paid row survives the filter');
  assert.equal(list[0].data.order_id, 4813);
  assertSameShape(list[0], fixture('order.paid'), 'order.paid');
});

test('customer.created performList shape === fixture AND polls with sort=created', async () => {
  const { z, calls } = makeZ({ status: 200, data: paginator([CUSTOMER_ROW]) });
  const list = await customer_created.operation.performList(z, makeBundle({ inputData: { restaurant_id: 37 } }));
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/customers');
  assert.equal(calls[0].params.sort, 'created');
  assert.equal(calls[0].params.per_page, 100);
  assertSameShape(list[0], fixture('customer.created'), 'customer.created');
  assert.equal(list[0].data.customer_id, 8841);
  assert.equal(list[0].data.name, 'Jordan Rivera');
});

test('reservation.created performList shape === fixture', async () => {
  const { z, calls } = makeZ({ status: 200, data: paginator([RESERVATION_ROW]) });
  const list = await reservation_created.operation.performList(z, makeBundle({ inputData: { restaurant_id: 37 } }));
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/reservations');
  assertSameShape(list[0], fixture('reservation.created'), 'reservation.created');
  assert.equal(list[0].data.reservation_id, 512);
});

test('refund.completed is INSTANT-only — no performList', () => {
  assert.equal(typeof refund_completed.operation.performList, 'undefined');
  assert.equal(typeof refund_completed.operation.performSubscribe, 'function');
  assert.equal(refund_completed.operation.type, 'hook');
});

// ── Every trigger's static sample keys ⊆ its live performList result keys ──

test('sample keys are a subset of the live performList result (T004/T006)', async () => {
  const cases = [
    [order_created, paginator([ORDER_ROW]), 'order.created'],
    [order_status_changed, paginator([ORDER_ROW]), 'order.status_changed'],
    [order_paid, paginator([PAID_ROW]), 'order.paid'],
    [customer_created, paginator([CUSTOMER_ROW]), 'customer.created'],
    [reservation_created, paginator([RESERVATION_ROW]), 'reservation.created'],
  ];
  for (const [trig, data, event] of cases) {
    const { z } = makeZ({ status: 200, data });
    const list = await trig.operation.performList(z, makeBundle({ inputData: { restaurant_id: 37 } }));
    assertSubset(trig.operation.sample, list[0], event);
  }
});

// ── Restaurant dropdown source ──

test('restaurant helper trigger is hidden and returns the row array', async () => {
  assert.equal(restaurant.display.hidden, true);
  const { z, calls } = makeZ({ status: 200, data: paginator([{ id: 37, title: 'Demo Diner' }]) });
  const rows = await restaurant.operation.perform(z, makeBundle());
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores');
  assert.deepEqual(rows, [{ id: 37, title: 'Demo Diner' }]);
});

// ── Copy rules (Zapier review) ──

test('trigger descriptions start "Triggers when " and end with "."', () => {
  for (const trig of [order_created, order_status_changed, order_paid, customer_created, reservation_created, refund_completed]) {
    assert.ok(/^Triggers when .+\.$/.test(trig.display.description), `bad description: ${trig.display.description}`);
  }
});
