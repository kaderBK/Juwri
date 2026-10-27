// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Copy the canonical F0-5 webhook fixtures into ./samples VERBATIM.
 *
 * Source of truth: core/resources/fixtures/webhook-samples/<event>.json
 * (full delivery envelopes `{id,event,created_at,data}`). Zapier renders ONE
 * static sample per operation from these — the same bytes the platform delivers,
 * so a Zap author sees real fields at design time.
 *
 * Run: `npm run build:samples`. Re-run whenever F0 revises a fixture; the
 * conformance test (test/samples.test.js) fails if ./samples drifts from source.
 *
 * The committed ./samples copies are what ships — a published Zapier app has no
 * access to the Laravel repo, so the copies must live in the package.
 */

const fs = require('fs');
const path = require('path');

// Events this Zapier app exposes as triggers (each needs a static sample).
const EVENTS = [
  'order.created',
  'order.status_changed',
  'order.paid',
  'customer.created',
  'reservation.created',
  'refund.completed',
];

const SOURCE_DIR = path.resolve(
  __dirname,
  '..',
  '..',
  '..',
  'core',
  'resources',
  'fixtures',
  'webhook-samples'
);
const OUT_DIR = path.resolve(__dirname, '..', 'samples');

function main() {
  if (!fs.existsSync(SOURCE_DIR)) {
    console.error(
      `Source fixtures not found at ${SOURCE_DIR}. Run this from inside the juwri repo.`
    );
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
    // Verbatim byte copy — no reserialisation, so nothing can silently reshape.
    const bytes = fs.readFileSync(src);
    fs.writeFileSync(path.join(OUT_DIR, `${event}.json`), bytes);
    copied += 1;
  }
  console.log(`Copied ${copied} sample fixture(s) into ${OUT_DIR}.`);
}

main();
