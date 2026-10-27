// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { makeZ, makeBundle, paginator } = require('./_harness');

const find_order = require('../searches/find_order');
const find_customer = require('../searches/find_customer');

test('Find Order returns [order] on a hit', async () => {
  const order = { id: 4812, status: 'preparing', total: 96.5, currency: 'USD', is_paid: true, created_at: '2026-07-08T14:03:11+00:00' };
  const { z, calls } = makeZ({ status: 200, data: order });
  const out = await find_order.operation.perform(z, makeBundle({ inputData: { restaurant_id: 37, order_id: 4812 } }));
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/orders/4812');
  assert.ok(Array.isArray(out));
  assert.deepEqual(out, [order]);
});

test('Find Order returns [] (never throws) when the order is missing (404)', async () => {
  const { z } = makeZ({ status: 404, data: { message: 'Order not found.', code: 'not_found' } });
  const out = await find_order.operation.perform(z, makeBundle({ inputData: { restaurant_id: 37, order_id: 999999 } }));
  assert.deepEqual(out, []);
});

test('Find Order still throws on a non-404 error (403 not entitled)', async () => {
  const { z } = makeZ({ status: 403, data: { message: 'Forbidden.', code: 'forbidden' } });
  await assert.rejects(
    () => find_order.operation.perform(z, makeBundle({ inputData: { restaurant_id: 37, order_id: 1 } })),
    /Forbidden\./
  );
});

test('Find Customer searches by term and returns the row array', async () => {
  const rows = [{ id: 8841, name: 'Jordan Rivera', phone: '+15551234567', email: 'jr@example.com' }];
  const { z, calls } = makeZ({ status: 200, data: paginator(rows) });
  const out = await find_customer.operation.perform(z, makeBundle({ inputData: { restaurant_id: 37, search: 'jr@example.com' } }));
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/customers');
  assert.equal(calls[0].params.search, 'jr@example.com');
  assert.equal(calls[0].params.per_page, 100);
  assert.deepEqual(out, rows);
});

test('Find Customer returns [] (200, never 404) on no match', async () => {
  const { z } = makeZ({ status: 200, data: paginator([]) });
  const out = await find_customer.operation.perform(z, makeBundle({ inputData: { restaurant_id: 37, search: 'nobody@example.com' } }));
  assert.deepEqual(out, []);
});
