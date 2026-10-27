// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const App = require('../index');

const templates = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', 'templates', 'zap-templates.json'), 'utf8')
).templates;

test('there are 10 Zap templates', () => {
  assert.equal(templates.length, 10);
});

test('every template has a title, a description ending with ".", a trigger and an action', () => {
  for (const t of templates) {
    assert.ok(t.title && /^[A-Z]/.test(t.title), `bad title: ${t.title}`);
    assert.ok(/\.$/.test(t.description), `description must end with a period: ${t.description}`);
    assert.ok(t.trigger && t.trigger.app && t.trigger.key);
    assert.ok(t.action && t.action.app && t.action.key);
  }
});

test('every juwri side of a template references a REAL operation key', () => {
  for (const t of templates) {
    if (t.trigger.app === 'juwri') {
      assert.ok(App.triggers[t.trigger.key], `template "${t.title}" trigger ${t.trigger.key} is not a real trigger`);
    }
    if (t.action.app === 'juwri') {
      assert.ok(App.creates[t.action.key], `template "${t.title}" action ${t.action.key} is not a real create`);
    }
  }
});

test('at least one template uses juwri as the trigger AND at least one as the action', () => {
  assert.ok(templates.some((t) => t.trigger.app === 'juwri'), 'need juwri-as-trigger templates');
  assert.ok(templates.some((t) => t.action.app === 'juwri'), 'need juwri-as-action templates');
});

test('the templates cover every visible trigger at least once', () => {
  const covered = new Set(
    templates.filter((t) => t.trigger.app === 'juwri').map((t) => t.trigger.key)
  );
  for (const key of Object.keys(App.triggers)) {
    if (key === 'restaurant') continue; // hidden dropdown source
    assert.ok(covered.has(key), `no template covers trigger ${key}`);
  }
});
