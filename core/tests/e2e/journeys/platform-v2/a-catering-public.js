// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * ISS-V-A-002 — catering PUBLIC request route (Platform E2E v2, lane A).
 *
 * The A10 service-level test covers the gate + lead-time guard; this closes the
 * owner-gated PUBLIC HTTP surface: with the store entitled
 * (E2eDarkFeatureEnableSeeder), POST /{slug}/catering creates a catering_requests
 * row in the 'requested' state for a valid future event.
 */

const V = require('../../lib/platform-v2');

const STORE = 'crave-demo';

module.exports = async function aCateringPublic({ page, log, sleep }) {
  V.collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });

  const rid = parseInt(V.db('SELECT id FROM {p}stores WHERE slug = ? LIMIT 1', [STORE])[0][0], 10);

  // Load the storefront for a valid same-origin CSRF token.
  const resp = await page.goto(V.localeUrl(`/${STORE}`), { waitUntil: 'networkidle2' });
  if (!resp || resp.status() !== 200) throw new Error(`storefront returned ${resp && resp.status()}`);

  const name = `E2E Catering ${Date.now()}`;
  const future = new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString().slice(0, 10); // +30d, well past lead time

  const postOnce = () => page.evaluate(async ({ slug, fields }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const body = new URLSearchParams(fields);
    const r = await fetch(`/${slug}/catering`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      body: body.toString(),
    });
    let j = null; try { j = await r.json(); } catch (_e) { /* noop */ }
    return { status: r.status, body: j };
  }, { slug: STORE, fields: { name, phone: '+966500000200', email: 'e2e-cater@example.test', event_at: future, headcount: '40', message: 'E2E catering request' } });

  // The catering endpoint carries a per-minute abuse throttle (throttle:10,1 +
  // ratelimit:public-write); back-to-back reproducibility runs can find the
  // per-IP minute bucket warm → 429 (env rate-limit, not a product failure).
  // Wait out the ~60s window and retry (watchdog-bounded).
  let post;
  for (let attempt = 1; attempt <= 3; attempt++) {
    post = await postOnce();
    if (post.status !== 429) break;
    if (attempt === 3) break;
    log(`[a-catering-public] catering POST rate-limited (429) — waiting out the window (attempt ${attempt}/3)`);
    await sleep(62000);
  }

  if (post.status >= 400) throw new Error(`catering request returned ${post.status} ${JSON.stringify(post.body)} (gate/validation?)`);

  const row = V.db("SELECT status FROM {p}catering_requests WHERE store_id = ? AND name = ? ORDER BY id DESC LIMIT 1", [rid, name]);
  if (!row.length) throw new Error('catering request created no catering_requests row');
  if (String(row[0][0]) !== 'requested') throw new Error(`catering request status is "${row[0][0]}", expected requested`);
  log(`[a-catering-public] public catering request created in 'requested' state ✓`);

  await V.assertNoConsoleErrors(page);
  log('[a-catering-public] PASS — public catering request reaches the owner queue');
};
