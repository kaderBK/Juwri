// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const SAMPLES_DIR = path.resolve(__dirname, '..', 'samples');
const SOURCE_DIR = path.resolve(
  __dirname, '..', '..', '..', 'core', 'resources', 'fixtures', 'webhook-samples'
);

const triggers = {
  order_created: require('../triggers/order_created'),
  order_status_changed: require('../triggers/order_status_changed'),
  order_paid: require('../triggers/order_paid'),
  customer_created: require('../triggers/customer_created'),
  reservation_created: require('../triggers/reservation_created'),
  refund_completed: require('../triggers/refund_completed'),
};
const creates = {
  update_order_status: require('../creates/update_order_status'),
  create_menu_item: require('../creates/create_menu_item'),
  update_menu_item: require('../creates/update_menu_item'),
  set_item_availability: require('../creates/set_item_availability'),
  create_customer: require('../creates/create_customer'),
};
const searches = {
  find_order: require('../searches/find_order'),
  find_customer: require('../searches/find_customer'),
};

const EVENTS = [
  'order.created', 'order.status_changed', 'order.paid',
  'customer.created', 'reservation.created', 'refund.completed',
];

// ISO-8601 with a timezone (Z or ±hh:mm), optional fractional seconds. Zapier D023.
const ISO_TZ = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:\d{2}|Z)$/;

function walkDates(obj, visit, where) {
  if (obj === null || typeof obj !== 'object') return;
  for (const [k, v] of Object.entries(obj)) {
    const at = where ? `${where}.${k}` : k;
    if (typeof v === 'string' && (k === 'created_at' || k.endsWith('_at'))) {
      visit(v, at);
    } else if (v && typeof v === 'object') {
      walkDates(v, visit, at);
    }
  }
}

// ── D012: exactly one static sample per operation ──

test('D012: every trigger, create and search declares a static sample', () => {
  for (const [key, op] of Object.entries(triggers)) {
    assert.ok(op.operation.sample, `trigger ${key} missing sample`);
  }
  for (const [key, op] of Object.entries(creates)) {
    assert.ok(op.operation.sample, `create ${key} missing sample`);
  }
  for (const [key, op] of Object.entries(searches)) {
    assert.ok(op.operation.sample, `search ${key} missing sample`);
  }
});

// ── D010: each sample contains an `id` ──

test('D010: every sample contains an `id`', () => {
  const all = [...Object.values(triggers), ...Object.values(creates), ...Object.values(searches)];
  for (const op of all) {
    assert.ok(
      Object.prototype.hasOwnProperty.call(op.operation.sample, 'id'),
      `sample for ${op.key} missing id`
    );
  }
});

// ── D023: ISO-8601 dates carry a timezone ──

test('D023: all *_at fields in every committed sample are ISO-8601 with a timezone', () => {
  for (const event of EVENTS) {
    const sample = JSON.parse(fs.readFileSync(path.join(SAMPLES_DIR, `${event}.json`), 'utf8'));
    walkDates(sample, (value, at) => {
      assert.ok(ISO_TZ.test(value), `${event} ${at}="${value}" is not ISO-8601+tz`);
    });
  }
});

test('D023: operation samples with dates use ISO-8601 with a timezone', () => {
  const all = [...Object.values(triggers), ...Object.values(creates), ...Object.values(searches)];
  for (const op of all) {
    walkDates(op.operation.sample, (value, at) => {
      assert.ok(ISO_TZ.test(value), `${op.key} sample ${at}="${value}" is not ISO-8601+tz`);
    });
  }
});

// ── D006: hook triggers have a polling fallback (except the documented one) ──

test('D006: every hook trigger has a performList except refund_completed (documented)', () => {
  for (const [key, op] of Object.entries(triggers)) {
    if (op.operation.type !== 'hook') continue;
    if (key === 'refund_completed') {
      assert.equal(typeof op.operation.performList, 'undefined', 'refund_completed is instant-only');
    } else {
      assert.equal(typeof op.operation.performList, 'function', `${key} needs a performList (D006)`);
    }
  }
});

// ── Verbatim copy: committed samples === source fixtures (F0-5) ──

test('committed samples are byte-identical to the F0-5 source fixtures (verbatim copy)', (t) => {
  if (!fs.existsSync(SOURCE_DIR)) {
    t.skip('source fixtures not present (published/standalone checkout) — copy verified in-repo only');
    return;
  }
  for (const event of EVENTS) {
    const committed = fs.readFileSync(path.join(SAMPLES_DIR, `${event}.json`));
    const source = fs.readFileSync(path.join(SOURCE_DIR, `${event}.json`));
    assert.ok(committed.equals(source), `${event}.json drifted from the source fixture — re-run npm run build:samples`);
  }
});

// ── Trigger samples ARE the canonical envelope for their event ──

test('each trigger sample equals its event fixture (envelope shape frozen)', () => {
  // Explicit key → event mapping (avoids the underscore ambiguity in status_changed):
  const map = {
    order_created: 'order.created',
    order_status_changed: 'order.status_changed',
    order_paid: 'order.paid',
    customer_created: 'customer.created',
    reservation_created: 'reservation.created',
    refund_completed: 'refund.completed',
  };
  for (const [key, event] of Object.entries(map)) {
    const fixture = JSON.parse(fs.readFileSync(path.join(SAMPLES_DIR, `${event}.json`), 'utf8'));
    assert.deepEqual(triggers[key].operation.sample, fixture, `${key} sample must be the ${event} fixture`);
  }
});
