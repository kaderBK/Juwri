// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const MODULES_DIR = path.join(ROOT, 'modules');
const SAMPLES_DIR = path.join(ROOT, 'samples');

function readJson(p) {
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}
function moduleFiles() {
  return fs.readdirSync(MODULES_DIR).filter((f) => f.endsWith('.json')).map((f) => path.join(MODULES_DIR, f));
}
function loadModules() {
  return moduleFiles().map(readJson);
}
function isPlainObject(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

const EVENTS = [
  'order.created', 'order.status_changed', 'order.paid',
  'customer.created', 'reservation.created', 'refund.completed',
];
const RESERVED = ['accountName', 'teamID'];

// ── Every JSON block parses ──

test('every top-level and module JSON block is valid JSON', () => {
  for (const f of ['base.json', 'connection.json', 'webhook.json']) {
    assert.doesNotThrow(() => readJson(path.join(ROOT, f)), `${f} must be valid JSON`);
  }
  for (const f of moduleFiles()) {
    assert.doesNotThrow(() => readJson(f), `${path.basename(f)} must be valid JSON`);
  }
});

// ── Base ──

test('base maps auth header and the platform error envelope', () => {
  const base = readJson(path.join(ROOT, 'base.json'));
  assert.equal(base.headers.Authorization, 'Bearer {{connection.api_key}}');
  assert.ok(base.baseUrl.includes('{{connection.api_base_url}}'));
  assert.ok(/\{\{body\.message\}\}/.test(base.response.error.message), 'error must surface body.message');
});

// ── Connection ──

test('connection validates against /api/v1/me, labels from store_name, avoids reserved names', () => {
  const conn = readJson(path.join(ROOT, 'connection.json'));
  const names = conn.parameters.map((p) => p.name);
  for (const r of RESERVED) {
    assert.ok(!names.includes(r), `connection must not use reserved parameter "${r}"`);
  }
  assert.deepEqual(names, ['api_base_url', 'api_key']);
  assert.ok(conn.communication.url.endsWith('/api/v1/me'), 'validation call must be /api/v1/me');
  assert.equal(conn.communication.response.metadata.value, '{{body.store_name}}');
});

// ── Webhook (attach/detach) ──

test('webhook attach posts { url, events:[event] } and saves externalHookId; detach DELETEs it', () => {
  const wh = readJson(path.join(ROOT, 'webhook.json'));
  assert.equal(wh.type, 'web');
  assert.ok(wh.attach.url.endsWith('/api/v1/webhook-endpoints'));
  assert.equal(wh.attach.method, 'POST');
  assert.deepEqual(wh.attach.body, { url: '{{webhook.url}}', events: ['{{parameters.event}}'] });
  assert.equal(wh.attach.response.data.externalHookId, '{{body.id}}');
  assert.equal(wh.detach.method, 'DELETE');
  assert.ok(wh.detach.url.endsWith('/api/v1/webhook-endpoints/{{webhook.externalHookId}}'));
  // The event options are exactly the 6 supported events.
  const opts = wh.parameters.find((p) => p.name === 'event').options.map((o) => o.value).sort();
  assert.deepEqual(opts, [...EVENTS].sort());
});

// ── Triggers ──

test('there is one instant trigger per event, each linking the shared webhook', () => {
  const mods = loadModules();
  const triggers = mods.filter((m) => m.type === 'instant_trigger');
  const events = triggers.map((t) => t.event).sort();
  assert.deepEqual(events, [...EVENTS].sort(), 'one instant trigger per event');
  for (const t of triggers) {
    assert.equal(t.webhook, 'juwriWebhook', `${t.name} must link the shared webhook`);
    assert.ok(t.samples && typeof t.samples === 'object', `${t.name} needs a sample`);
    assert.ok(/^Triggers when .+\.$/.test(t.description), `bad description: ${t.description}`);
  }
});

test('each trigger sample is byte-equal to its F0-5 event fixture', () => {
  const triggers = loadModules().filter((m) => m.type === 'instant_trigger');
  for (const t of triggers) {
    const fixture = readJson(path.join(SAMPLES_DIR, `${t.event}.json`));
    assert.deepEqual(t.samples, fixture, `${t.name} sample must equal the ${t.event} fixture`);
  }
});

// ── Actions ──

test('the five actions mirror the Zapier set and return the full object', () => {
  const mods = loadModules();
  const actions = mods.filter((m) => m.type === 'action');
  const names = actions.map((a) => a.name).sort();
  assert.deepEqual(names, [
    'createCustomer', 'createMenuItem', 'setItemAvailability', 'updateMenuItem', 'updateOrderStatus',
  ]);
  for (const a of actions) {
    assert.equal(a.communication.response.output, '{{body}}', `${a.name} must return the full object`);
    assert.ok(a.communication.url.includes('/api/v1/'), `${a.name} url must hit api/v1`);
  }
});

// ── Searches + pagination ──

test('searches exist, list-searches paginate via per_page (never limit)', () => {
  const searches = loadModules().filter((m) => m.type === 'search');
  const names = searches.map((s) => s.name).sort();
  assert.deepEqual(names, ['findCustomer', 'findOrder', 'listOrders']);

  for (const s of searches) {
    const qs = s.communication.qs || {};
    assert.ok(!('limit' in qs), `${s.name} must NOT use a limit param`);
    // A search that iterates a `data` array is a list → must paginate with per_page.
    const iter = s.communication.response.iterate || '';
    if (iter.includes('body.data')) {
      assert.equal(qs.per_page, 100, `${s.name} list must page-size via per_page`);
      assert.ok(s.communication.pagination, `${s.name} list must declare pagination`);
      assert.ok(
        /body\.current_page < body\.last_page/.test(s.communication.pagination.condition),
        `${s.name} pagination must follow current_page/last_page`
      );
    }
  }
});

// ── Universal module ──

test('a Universal (arbitrary API call) module is present', () => {
  const universal = loadModules().filter((m) => m.type === 'universal');
  assert.equal(universal.length, 1, 'exactly one Universal module');
  assert.ok(universal[0].communication.url.includes('/api/v1'));
});

// ── Global invariants ──

test('no module parameter uses a Make-reserved name; no `limit` query param anywhere', () => {
  for (const m of loadModules()) {
    for (const p of m.parameters || []) {
      assert.ok(!RESERVED.includes(p.name), `${m.name} uses reserved parameter ${p.name}`);
    }
    const qs = m.communication && m.communication.qs;
    if (isPlainObject(qs)) {
      assert.ok(!('limit' in qs), `${m.name} uses a limit query param`);
    }
  }
});

test('every module declares name, label and a Title-Case-ish label', () => {
  for (const m of loadModules()) {
    assert.ok(m.name, 'module needs a name');
    assert.ok(m.label && /^[A-Z]/.test(m.label), `module ${m.name} label should be Title Case`);
  }
});

test('README-ASSEMBLY names every JSON block exactly once (guide is complete)', () => {
  const guide = fs.readFileSync(path.join(ROOT, 'README-ASSEMBLY.md'), 'utf8');
  for (const f of ['base.json', 'connection.json', 'webhook.json']) {
    assert.ok(guide.includes(f), `assembly guide must reference ${f}`);
  }
  for (const f of moduleFiles()) {
    const rel = 'modules/' + path.basename(f);
    assert.ok(guide.includes(rel), `assembly guide must reference ${rel}`);
  }
});
