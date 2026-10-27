// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * ISS-V-A-001 — biolink PUBLIC route (Platform E2E v2, lane A).
 *
 * The A6 service-level test already covers block rendering; this closes the
 * owner-gated PUBLIC HTTP surface: with the store entitled + bio enabled
 * (E2eDarkFeatureEnableSeeder), GET /{slug}/bio renders 200 in ar AND en with
 * the seeded blocks, and the public click beacon records a click.
 */

const V = require('../../lib/platform-v2');

const STORE = 'crave-demo';

module.exports = async function aBioPublic({ page, log }) {
  V.collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });

  // ── ar render ────────────────────────────────────────────────────────────
  let resp = await page.goto(V.localeUrl(`/${STORE}/bio`), { waitUntil: 'networkidle2' });
  if (!resp || resp.status() !== 200) throw new Error(`/${STORE}/bio (ar) returned ${resp && resp.status()} (feature/enable gate?)`);
  const arText = await page.evaluate(() => document.body.innerText);
  if (!/Our Menu|Order Online/.test(arText)) throw new Error('bio blocks did not render (ar)');
  await V.assertRtl(page);
  log('[a-bio-public] ar: /bio 200, blocks render, RTL ✓');

  // ── en render (LTR parity) ───────────────────────────────────────────────
  resp = await page.goto(V.enUrl(`/${STORE}/bio`), { waitUntil: 'networkidle2' });
  if (!resp || resp.status() !== 200) throw new Error(`/${STORE}/bio (en) returned ${resp && resp.status()}`);
  await V.assertLtr(page);
  log('[a-bio-public] en: /bio 200, LTR ✓');

  // ── public click beacon ──────────────────────────────────────────────────
  const block = V.db("SELECT id FROM {p}bio_blocks WHERE store_id = (SELECT id FROM {p}stores WHERE slug = ? LIMIT 1) AND active = 1 ORDER BY position LIMIT 1", [STORE]);
  if (!block.length) throw new Error('no active bio block to click');
  const blockId = parseInt(block[0][0], 10);
  const before = V.db('SELECT clicks_cached FROM {p}bio_blocks WHERE id = ?', [blockId]);
  const beacon = await page.evaluate(async ({ slug, blockId }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const r = await fetch(`/${slug}/bio/c/${blockId}`, {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    });
    return { status: r.status };
  }, { slug: STORE, blockId });
  if (![200, 204].includes(beacon.status)) throw new Error(`bio click beacon returned ${beacon.status}`);

  // The beacon may increment a cache or a bio_clicks row — assert one moved.
  const after = V.db('SELECT clicks_cached FROM {p}bio_blocks WHERE id = ?', [blockId]);
  const clicksRow = V.db('SELECT COUNT(*) FROM {p}bio_clicks WHERE block_id = ?', [blockId]);
  const moved = (after.length && before.length && Number(after[0][0]) > Number(before[0][0]))
    || (clicksRow.length && Number(clicksRow[0][0]) > 0);
  if (!moved) throw new Error('bio click beacon recorded no click (cache + bio_clicks both unchanged)');
  log(`[a-bio-public] click beacon on block #${blockId} recorded ✓`);

  await V.assertNoConsoleErrors(page);
  log('[a-bio-public] PASS — public bio renders ar+en and the click beacon records');
};
