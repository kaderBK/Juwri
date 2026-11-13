// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Copy the canonical F0-5 webhook fixtures into ./samples VERBATIM, for the Make
 * module Interfaces (each instant trigger renders one static sample). Same source
 * of truth as the Zapier app: core/resources/fixtures/webhook-samples/<event>.json.
 *
 * Run: `npm run build:samples`. The conformance test (test/make.test.js) fails if
 * a trigger module's inline `samples` drifts from these copies.
 */

const fs = require('fs');
const path = require('path');

const EVENTS = [
  'order.created',
  'order.status_changed',
  'order.paid',
  'customer.created',
  'reservation.created',
  'refund.completed',
];

const SOURCE_DIR = path.resolve(
  __dirname, '..', '..', '..', 'core', 'resources', 'fixtures', 'webhook-samples'
);
const OUT_DIR = path.resolve(__dirname, '..', 'samples');

function main() {
  if (!fs.existsSync(SOURCE_DIR)) {
    console.error(`Source fixtures not found at ${SOURCE_DIR}. Run inside the juwri repo.`);
    process.exit(1);
  }
  fs.mkdirSync(OUT_DIR, { recursive: true });
  let copied = 0;
  for (const event of EVENTS) {
    const src = path.join(SOURCE_DIR, `${event}.json`);
    if (!fs.existsSync(src)) {
      console.error(`Missing fixture: ${src}`);
      process.exit(1);
    }
    fs.writeFileSync(path.join(OUT_DIR, `${event}.json`), fs.readFileSync(src));
    copied += 1;
  }
  console.log(`Copied ${copied} sample fixture(s) into ${OUT_DIR}.`);
}

main();
