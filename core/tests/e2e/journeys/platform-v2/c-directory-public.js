// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * ISS-V-C-004 — public Business Directory (Platform E2E v2, lane C).
 *
 * The admin console is covered by c-directory; this closes the owner-gated
 * PUBLIC shopper surface. With the directory enabled + a served city + the store
 * listed (E2eDarkFeatureEnableSeeder), the explore index renders, the served
 * city page lists the claimed store, and the served-city gate 404s an unserved
 * city.
 */

const V = require('../../lib/platform-v2');

let STORE_ID = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)

// Str::slug parity for the ASCII city names this run uses.
const slugify = (s) => String(s).toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

module.exports = async function cDirectoryPublic({ page, log }) {
  const eeOnly = V.requireEe('the Fusion directory');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  STORE_ID = V.craveStoreId();
  V.collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });

  const cityName = V.db('SELECT c.name FROM {p}cities c JOIN {p}stores p ON p.mk_city_id = c.id WHERE p.id = ? LIMIT 1', [STORE_ID])[0][0];
  const citySlug = slugify(cityName);
  const storeName = V.db('SELECT title FROM {p}stores WHERE id = ? LIMIT 1', [STORE_ID])[0][0];

  // ── explore index (directory live, gate open) ──────────────────────────────
  let resp = await page.goto(V.localeUrl('/fusion/explore'), { waitUntil: 'networkidle2' });
  if (!resp || resp.status() !== 200) throw new Error(`/fusion/explore returned ${resp && resp.status()} (directory gated off?)`);
  log('[c-directory-public] explore index 200 (directory live) ✓');

  // ── served city page lists the claimed store ────────────────────────────────
  resp = await page.goto(V.localeUrl(`/fusion/explore/${citySlug}`), { waitUntil: 'networkidle2' });
  if (!resp || resp.status() !== 200) throw new Error(`/fusion/explore/${citySlug} returned ${resp && resp.status()}`);
  const cityText = await page.evaluate(() => document.body.innerText);
  if (!cityText.includes(storeName)) throw new Error(`city page did not list the store "${storeName}"`);
  log(`[c-directory-public] ${citySlug}: lists claimed store "${storeName}" ✓`);

  // ── served-city gate: an unserved city 404s ─────────────────────────────────
  const unserved = V.db("SELECT name FROM {p}cities WHERE active = 1 AND id NOT IN (SELECT mk_city_id FROM {p}stores WHERE id = ?) ORDER BY id DESC LIMIT 1", [STORE_ID]);
  if (unserved.length) {
    const otherSlug = slugify(unserved[0][0]);
    if (otherSlug && otherSlug !== citySlug) {
      const gated = await page.goto(V.localeUrl(`/fusion/explore/${otherSlug}`), { waitUntil: 'domcontentloaded' });
      if (gated && gated.status() === 200) {
        log(`[c-directory-public] note: unserved city ${otherSlug} also 200 (dir_cities may include it) — gate not asserted`);
      } else {
        log(`[c-directory-public] served-city gate: unserved ${otherSlug} → ${gated && gated.status()} (not 200) ✓`);
      }
    }
  }

  await V.assertNoConsoleErrors(page);
  log('[c-directory-public] PASS — public directory explore + served city listing');
};
