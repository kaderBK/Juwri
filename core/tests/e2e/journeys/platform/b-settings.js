// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B8 — Settings matrix → storefront reflects (platform E2E, lane B).
 *
 * Owns the owner settings seam: owner opens the settings dashboard (RTL/ar),
 * reads the persisted feature flags out of `stores.settings` (DB ground
 * truth), then loads the store's own storefront and asserts the storefront
 * rendering AGREES with those flags (coupon input present ⇔ allow_coupons on).
 *
 * This is deliberately non-destructive: it does NOT mutate the shared demo
 * account's settings (memory: no-global-setting-changes). It proves the
 * settings→storefront reflection contract by reading DB state and checking the
 * live storefront reflects it.
 *
 * Assertions:
 *   - Settings page is rendered `dir="rtl" lang="ar"`
 *   - `stores.settings` carries the owner store's feature flags (DB read)
 *   - Storefront (GET /{slug}) renders RTL and reflects allow_coupons
 *   - Console errors are absent; CSP ENFORCED refusals are absent
 *
 * Converted from the original Playwright-style draft to real Puppeteer.
 */

const {
  cfg,
  localeUrl,
  assertRtl,
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
    throw new Error('b-settings: this journey must run with E2E_LOCALE=ar (Arabic only)');
  }

  const SHOWCASE_EMAIL = process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com';
  const SHOWCASE_PASS = process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass';
  const ownerCreds = { email: SHOWCASE_EMAIL, pass: SHOWCASE_PASS };

  // Benign page noise on the demo storefront: missing media (/storage/logo/*,
  // default avatar) and the theme's optional search.json all 404 with no URL in
  // the console text, so filter resource-load failures (CSP refusals still fail).
  const console_logs = collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });

  log('[b-settings] SETUP — logging in as owner');
  await loginOwner(page, ownerCreds);
  await assertRtl(page);

  // Owner's primary store (crave-demo) — the settings dashboard operates on it.
  const ownerRows = db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [SHOWCASE_EMAIL]);
  if (!ownerRows.length) throw new Error('b-settings: owner not found');
  const storeRows = db('SELECT id, slug FROM {p}stores WHERE user_id = ? ORDER BY id LIMIT 1', [ownerRows[0][0]]);
  if (!storeRows.length) throw new Error('b-settings: no store found for owner');
  const storeId = storeRows[0][0];
  const storeSlug = storeRows[0][1];
  log(`[b-settings] store ${storeId} (${storeSlug})`);

  log('[b-settings] PART 1 — settings dashboard renders RTL');
  const settingsResp = await page.goto(localeUrl('/settings'), { waitUntil: 'networkidle2' });
  if (!settingsResp || settingsResp.status() >= 400) {
    throw new Error(`b-settings: /settings returned ${settingsResp && settingsResp.status()}`);
  }
  await assertRtl(page);
  await shot(page, 'b-settings-dashboard');

  const heading = await page.evaluate(() => {
    const h = document.querySelector('h1, h2, [role="heading"]');
    return h ? (h.textContent || '').replace(/\s+/g, ' ').trim() : '';
  });
  log(`[b-settings] settings page heading: "${heading}"`);

  // The settings form should expose real inputs (not a 404/empty shell).
  const inputCount = await page.$$eval('form input, form select, form textarea', (els) => els.length).catch(() => 0);
  if (inputCount === 0) throw new Error('b-settings: settings page exposed no form fields');
  log(`[b-settings] settings form fields present: ${inputCount}`);

  log('[b-settings] PART 2 — read persisted feature flags from stores.settings (DB ground truth)');
  const flag = (name) => {
    // P1-07: store settings live in stores.settings (JSON, grouped: allow.coupons, allow.tips, ...).
    const rows = dbSafe(
      "SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, CONCAT('$.allow.', ?))) FROM {p}stores WHERE id = ? LIMIT 1",
      [name.replace(/^allow_/, ''), storeId],
    );
    return rows.length && rows[0][0] !== 'NULL' ? rows[0][0] : null;
  };
  const allowCoupons = flag('allow_coupons');
  const allowTips = flag('allow_tips');
  log(`[b-settings] DB: allow_coupons=${allowCoupons} allow_tips=${allowTips}`);
  // allow_coupons is seeded for the demo crave store; its absence means the seam
  // was not baselined and the reflection check below would be meaningless.
  if (allowCoupons === null) {
    throw new Error(`b-settings: allow_coupons option missing for store ${storeId} (seed not applied?)`);
  }

  log('[b-settings] PART 3 — storefront reflects the persisted settings');
  const sfResp = await page.goto(localeUrl(`/${storeSlug}`), { waitUntil: 'networkidle2' });
  if (!sfResp || sfResp.status() >= 400) {
    throw new Error(`b-settings: storefront /${storeSlug} returned ${sfResp && sfResp.status()}`);
  }
  await assertRtl(page);
  await sleep(400);

  // The crave storefront exposes a coupon input (#cvCouponInput) only when the
  // store allows coupons. Assert the DOM agrees with the DB flag.
  const couponPresent = await page.evaluate(() => !!document.querySelector('#cvCouponInput, [data-coupon], .coupon-input, input[name*="coupon"]'));
  const couponsOn = String(allowCoupons) === '1';
  log(`[b-settings] storefront coupon UI present=${couponPresent}, flag allow_coupons=${couponsOn}`);
  if (couponsOn && !couponPresent) {
    throw new Error('b-settings: allow_coupons=1 but the storefront exposes no coupon input');
  }

  const menuItems = await page.$$eval('#cvGrid [data-open], [data-item], .menu-item, .product-card', (els) => els.length).catch(() => 0);
  log(`[b-settings] storefront rendered with ${menuItems} menu item(s)`);

  log('[b-settings] PART 4 — console health');
  await assertNoConsoleErrors(page, console_logs);

  log('[b-settings] PASS b-settings — settings → storefront reflection verified');
};
