// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const App = require('../index');

test('the app assembles: version, platformVersion, authentication', () => {
  assert.match(App.version, /^\d+\.\d+\.\d+$/);
  assert.match(App.platformVersion, /^\d+\.\d+\.\d+$/);
  assert.equal(App.authentication.type, 'custom');
  assert.equal(typeof App.authentication.test, 'function');
});

test('all six public triggers plus the hidden restaurant source are registered', () => {
  const keys = Object.keys(App.triggers).sort();
  assert.deepEqual(keys, [
    'customer_created',
    'order_created',
    'order_paid',
    'order_status_changed',
    'refund_completed',
    'reservation_created',
    'restaurant',
  ]);
  assert.equal(App.triggers.restaurant.display.hidden, true);
  // The six user-facing triggers are NOT hidden.
  for (const k of Object.keys(App.triggers)) {
    if (k === 'restaurant') continue;
    assert.notEqual(App.triggers[k].display.hidden, true, `${k} should be visible`);
  }
});

test('creates and searches are registered', () => {
  assert.deepEqual(Object.keys(App.creates).sort(), [
    'create_customer',
    'create_menu_item',
    'set_item_availability',
    'update_menu_item',
    'update_order_status',
  ]);
  assert.deepEqual(Object.keys(App.searches).sort(), ['find_customer', 'find_order']);
});

test('every operation key matches its map key and is globally unique', () => {
  const seen = new Set();
  for (const group of ['triggers', 'creates', 'searches']) {
    for (const [mapKey, def] of Object.entries(App[group])) {
      assert.equal(def.key, mapKey, `${group}.${mapKey} key mismatch (${def.key})`);
      assert.ok(!seen.has(def.key), `duplicate operation key ${def.key}`);
      seen.add(def.key);
    }
  }
});

test('every trigger has a perform, hook triggers have subscribe/unsubscribe', () => {
  for (const [key, def] of Object.entries(App.triggers)) {
    assert.equal(typeof def.operation.perform, 'function', `${key} needs perform`);
    if (def.operation.type === 'hook') {
      assert.equal(typeof def.operation.performSubscribe, 'function', `${key} needs performSubscribe`);
      assert.equal(typeof def.operation.performUnsubscribe, 'function', `${key} needs performUnsubscribe`);
    }
  }
});

test('every create and search has a perform and a sample', () => {
  for (const group of ['creates', 'searches']) {
    for (const [key, def] of Object.entries(App[group])) {
      assert.equal(typeof def.operation.perform, 'function', `${group}.${key} needs perform`);
      assert.ok(def.operation.sample, `${group}.${key} needs a sample`);
    }
  }
});

test('the restaurant dynamic dropdown reference resolves to a real trigger.field', () => {
  // Every visible operation with a restaurant_id field points at `restaurant.id.title`.
  const all = [
    ...Object.values(App.triggers),
    ...Object.values(App.creates),
    ...Object.values(App.searches),
  ];
  for (const def of all) {
    const fields = def.operation.inputFields || [];
    const rid = fields.find((f) => f.key === 'restaurant_id');
    if (rid && rid.dynamic) {
      assert.equal(rid.dynamic, 'store.id.title');
      assert.ok(App.triggers.restaurant, 'restaurant source trigger must exist');
    }
  }
});
