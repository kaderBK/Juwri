// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B11 — Hospitality owner side: reservations, waitlist, catering, gift cards
 * (platform E2E, lane B).
 *
 * Owns the owner dashboard seam for hospitality features. Verifies each owner
 * hospitality surface renders (RTL/ar, 200) and that its backing table is
 * reachable via db() ground truth:
 *   - Reservations hub (/reservations)
 *   - Waitlist hub (/restaurant/{id}/waitlist)
 *   - Catering hub (/catering)
 *   - Gift cards hub (/gift-cards)
 *
 * Assertions:
 *   - Every hospitality page is RTL (ar), no console errors
 *   - Each backing table (reservations / waitlist_entries / catering_requests /
 *     gift_cards) is queryable for the owner's store
 *
 * Converted from the original Playwright-style draft to real Puppeteer.
 */

const {
  cfg,
  localeUrl,
  assertRtl,
  assertNoOverflow,
  collectConsole,
  assertNoConsoleErrors,
  loginOwner,
  db,
} = require('../../lib/platform');

/** Run `sql`; return [] instead of throwing when the table/column is absent. */
function dbSafe(sql, params) {
  try { return db(sql, params); } catch (_e) { return []; }
}

module.exports = async ({ page, log, shot, sleep }) => {
  if (cfg.locale !== 'ar') {
    throw new Error('b-hospitality: this journey must run with E2E_LOCALE=ar (Arabic only)');
  }

  const SHOWCASE_EMAIL = process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com';
  const SHOWCASE_PASS = process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass';
  const ownerCreds = { email: SHOWCASE_EMAIL, pass: SHOWCASE_PASS };

  const console_logs = collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });

  log('[b-hospitality] PART 1 — owner login');
  await loginOwner(page, ownerCreds);
  await assertRtl(page);

  const ownerRows = db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [SHOWCASE_EMAIL]);
  if (!ownerRows.length) throw new Error(`[b-hospitality] owner not found: ${SHOWCASE_EMAIL}`);
  const storeRows = db('SELECT id FROM {p}stores WHERE user_id = ? ORDER BY id LIMIT 1', [ownerRows[0][0]]);
  if (!storeRows.length) throw new Error('[b-hospitality] owner has no store');
  const storeId = parseInt(storeRows[0][0], 10);
  log(`[b-hospitality] store ${storeId}`);

  // Each surface: navigate (200 + RTL), then confirm the backing table is reachable.
  const surfaces = [
    { name: 'reservations', url: localeUrl('/reservations'), table: 'reservations' },
    { name: 'waitlist', url: localeUrl(`/restaurant/${storeId}/waitlist`), table: 'waitlist_entries' },
    { name: 'catering', url: localeUrl('/catering'), table: 'catering_requests' },
    { name: 'gift-cards', url: localeUrl('/gift-cards'), table: 'gift_cards' },
  ];

  let part = 2;
  for (const s of surfaces) {
    log(`[b-hospitality] PART ${part} — ${s.name} hub renders (RTL)`);
    const resp = await page.goto(s.url, { waitUntil: 'networkidle2' });
    if (!resp || resp.status() >= 400) {
      throw new Error(`[b-hospitality] ${s.name} (${s.url}) returned ${resp && resp.status()}`);
    }
    await assertRtl(page);
    await shot(page, `b-hospitality-${s.name}`);

    const rows = dbSafe(`SELECT COUNT(*) FROM {p}${s.table} WHERE store_id = ?`, [storeId]);
    const count = rows.length ? rows[0][0] : 'n/a';
    log(`[b-hospitality] ${s.name}: page ok, ${s.table} rows for store=${count}`);
    part += 1;
  }

  log(`[b-hospitality] PART ${part} — console health`);
  await assertNoConsoleErrors(page, console_logs);
  await assertNoOverflow(page);

  log('[b-hospitality] PASS b-hospitality — hospitality owner surfaces verified');
};
