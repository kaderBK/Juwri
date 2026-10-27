// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { makeZ, makeBundle } = require('./_harness');

const update_order_status = require('../creates/update_order_status');
const create_menu_item = require('../creates/create_menu_item');
const update_menu_item = require('../creates/update_menu_item');
const set_item_availability = require('../creates/set_item_availability');
const create_customer = require('../creates/create_customer');

test('Update Order Status PUTs {status} and returns the FULL order object', async () => {
  const full = { id: 4812, status: 'preparing', type: 'delivery', total: 96.5, currency: 'USD', is_paid: true, created_at: '2026-07-08T14:03:11+00:00' };
  const { z, calls } = makeZ({ status: 200, data: full });
  const out = await update_order_status.operation.perform(
    z,
    makeBundle({ inputData: { restaurant_id: 37, order_id: 4812, status: 'preparing' } })
  );
  assert.equal(calls[0].method, 'PUT');
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/orders/4812/status');
  assert.deepEqual(calls[0].body, { status: 'preparing' });
  assert.deepEqual(out, full);
  assert.ok(!('success' in out), 'must return the object, not {success:true}');
});

test('Create Menu Item POSTs a compacted body and returns the full item', async () => {
  const item = { id: 991, category_id: 12, name: 'Margherita Pizza', price: 12.5, active: true, is_sold_out: false };
  const { z, calls } = makeZ({ status: 201, data: item });
  const out = await create_menu_item.operation.perform(
    z,
    makeBundle({ inputData: { restaurant_id: 37, name: 'Margherita Pizza', price: 12.5, category_id: 12, description: '', calories: null } })
  );
  assert.equal(calls[0].method, 'POST');
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/menu/items');
  // Blank/undefined optionals dropped; restaurant_id (path param) never in the body.
  assert.deepEqual(calls[0].body, { name: 'Margherita Pizza', price: 12.5, category_id: 12 });
  assert.deepEqual(out, item);
});

test('Update Menu Item PUTs to the item path and returns the full item', async () => {
  const item = { id: 991, category_id: 12, name: 'Margherita Pizza', price: 13.0, active: true, is_sold_out: false };
  const { z, calls } = makeZ({ status: 200, data: item });
  const out = await update_menu_item.operation.perform(
    z,
    makeBundle({ inputData: { restaurant_id: 37, item_id: 991, price: 13.0 } })
  );
  assert.equal(calls[0].method, 'PUT');
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/menu/items/991');
  assert.deepEqual(calls[0].body, { price: 13.0 });
  assert.equal(out.id, 991);
});

test('Set Item Availability PATCHes is_sold_out and returns {id,is_sold_out}', async () => {
  const { z, calls } = makeZ({ status: 200, data: { id: 991, is_sold_out: true } });
  const out = await set_item_availability.operation.perform(
    z,
    makeBundle({ inputData: { restaurant_id: 37, item_id: 991, is_sold_out: true } })
  );
  assert.equal(calls[0].method, 'PATCH');
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/menu/items/991/sold-out');
  assert.deepEqual(calls[0].body, { is_sold_out: true });
  assert.deepEqual(out, { id: 991, is_sold_out: true });
});

test('Set Item Availability with no value sends an empty body (server flips)', async () => {
  const { z, calls } = makeZ({ status: 200, data: { id: 991, is_sold_out: false } });
  await set_item_availability.operation.perform(z, makeBundle({ inputData: { restaurant_id: 37, item_id: 991 } }));
  assert.deepEqual(calls[0].body, {});
});

test('Create Customer POSTs to the customers path and returns the full customer (NEEDS-F0 endpoint)', async () => {
  const cust = { id: 8841, name: 'Jordan Rivera', email: 'jr@example.com', phone: '+15551234567', created_at: '2026-07-08T14:03:11+00:00' };
  const { z, calls } = makeZ({ status: 201, data: cust });
  const out = await create_customer.operation.perform(
    z,
    makeBundle({ inputData: { restaurant_id: 37, name: 'Jordan Rivera', email: 'jr@example.com', phone: '+15551234567', notes: '' } })
  );
  assert.equal(calls[0].method, 'POST');
  assert.equal(calls[0].url, 'https://demo.test/api/v1/stores/37/customers');
  assert.deepEqual(calls[0].body, { name: 'Jordan Rivera', email: 'jr@example.com', phone: '+15551234567' });
  assert.deepEqual(out, cust);
});

test('a create surfaces a 422 as a 4xx error (never a false success)', async () => {
  const { z } = makeZ({ status: 422, data: { message: 'The price field is required.', code: 'unprocessable', errors: { price: ['required'] } } });
  await assert.rejects(
    () => create_menu_item.operation.perform(z, makeBundle({ inputData: { restaurant_id: 37, name: 'X' } })),
    (err) => {
      assert.equal(err.message, 'The price field is required.');
      assert.equal(err.status, 422);
      return true;
    }
  );
});

test('every create labels are Title Case and descriptions end with "."', () => {
  for (const c of [update_order_status, create_menu_item, update_menu_item, set_item_availability, create_customer]) {
    assert.ok(/\.$/.test(c.display.description), `bad description: ${c.display.description}`);
    assert.ok(/^[A-Z]/.test(c.display.label));
  }
});
