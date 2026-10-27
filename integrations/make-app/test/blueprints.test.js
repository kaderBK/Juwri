// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const BLUEPRINT_DIR = path.join(ROOT, 'blueprints');
const MODULES_DIR = path.join(ROOT, 'modules');

function readJson(p) {
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}

const blueprintFiles = fs.readdirSync(BLUEPRINT_DIR).filter((f) => f.endsWith('.json'));
const moduleNames = new Set(
  fs.readdirSync(MODULES_DIR).filter((f) => f.endsWith('.json')).map((f) => readJson(path.join(MODULES_DIR, f)).name)
);

test('there are 5 Make scenario blueprints', () => {
  assert.equal(blueprintFiles.length, 5);
});

test('every blueprint is valid JSON with a name, description and a >=2 module flow', () => {
  for (const f of blueprintFiles) {
    const bp = readJson(path.join(BLUEPRINT_DIR, f));
    assert.ok(bp.name, `${f} needs a name`);
    assert.ok(/\.$/.test(bp.description), `${f} description must end with a period`);
    assert.ok(Array.isArray(bp.flow) && bp.flow.length >= 2, `${f} needs a flow of >=2 modules`);
  }
});

test('every juwri: module referenced in a blueprint is a REAL module', () => {
  let juwriRefs = 0;
  for (const f of blueprintFiles) {
    const bp = readJson(path.join(BLUEPRINT_DIR, f));
    for (const step of bp.flow) {
      const m = /^juwri\d*:(.+)$/.exec(step.module);
      if (m) {
        juwriRefs += 1;
        assert.ok(moduleNames.has(m[1]), `${f} references unknown module ${step.module}`);
      }
    }
  }
  assert.ok(juwriRefs >= 5, 'each blueprint should use at least one juwri module');
});

test('blueprints exercise juwri as both trigger and action', () => {
  let asTrigger = 0;
  let asAction = 0;
  for (const f of blueprintFiles) {
    const bp = readJson(path.join(BLUEPRINT_DIR, f));
    const first = bp.flow[0].module;
    if (/^juwri\d*:/.test(first)) asTrigger += 1;
    for (const step of bp.flow.slice(1)) {
      if (/^juwri\d*:/.test(step.module)) asAction += 1;
    }
  }
  assert.ok(asTrigger >= 1, 'at least one blueprint starts with a juwri trigger');
  assert.ok(asAction >= 1, 'at least one blueprint uses a juwri action downstream');
});
