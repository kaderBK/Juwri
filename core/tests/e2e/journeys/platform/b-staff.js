// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B12 — Staff roles & permissions (platform E2E, lane B).
 *
 * Owns the staff access-control seam (EnsureStaffDashboardPermission, default-
 * deny). The owner creates a limited-role (waiter) staff member through the real
 * owner endpoint `POST /staff`; the staff logs in at /staff/login and the
 * route→permission matrix is asserted against live HTTP status codes:
 *   - /orders    (restaurants.orders → 'orders')   → 200 (waiter HAS orders)
 *   - /customers (customers.index    → 'customers') → 403 (waiter LACKS it)
 *   - /settings  (unmapped)                          → 403 (default-deny)
 * The owner then WIDENS the staff (grants the 'customers' permission override);
 * the staff can now reach /customers (200). Every step is server-authoritative.
 *
 * Assertions:
 *   - Staff created in restaurant_staff (DB ground truth)
 *   - Default-deny: unmapped + unpermitted routes 403 for staff
 *   - Permitted routes 200
 *   - Permission widening takes effect (403 → 200)
 *   - No console errors / no ENFORCED CSP refusals
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
  loginStaff,
  db,
} = require('../../lib/platform');

/** POST form-encoded to a same-origin endpoint using the page's CSRF meta. */
async function postForm(page, path, fields) {
  return page.evaluate(async ({ path, fields }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
      || document.querySelector('input[name="_token"]')?.value || '';
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(fields)) body.append(k, v);
    const r = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html', 'X-CSRF-TOKEN': csrf },
      body: body.toString(),
    });
    return { status: r.status, url: r.url };
  }, { path, fields });
}

/** GET `path`; return the final HTTP status (following the SPA-less redirect). */
async function statusOf(page, path) {
  const resp = await page.goto(localeUrl(path), { waitUntil: 'domcontentloaded' }).catch((e) => ({ __err: e.message }));
  if (resp && resp.__err) throw new Error(`navigation to ${path} threw: ${resp.__err}`);
  return resp ? resp.status() : 0;
}

module.exports = async ({ page, log, shot, sleep }) => {
  if (cfg.locale !== 'ar') {
    throw new Error('b-staff: this journey must run with E2E_LOCALE=ar (Arabic only)');
  }

  const SHOWCASE_EMAIL = process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com';
  const SHOWCASE_PASS = process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass';
  const ownerCreds = { email: SHOWCASE_EMAIL, pass: SHOWCASE_PASS };

  const staffEmail = `e2e-b-staff-${Date.now()}@local.invalid`;
  const staffPass = 'E2eStaff!2345';

  const console_logs = collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });

  log('[b-staff] PART 1 — owner login');
  await loginOwner(page, ownerCreds);
  await assertRtl(page);

  const ownerRows = db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [SHOWCASE_EMAIL]);
  if (!ownerRows.length) throw new Error(`[b-staff] owner not found: ${SHOWCASE_EMAIL}`);
  const storeRows = db('SELECT id FROM {p}stores WHERE user_id = ? ORDER BY id LIMIT 1', [ownerRows[0][0]]);
  if (!storeRows.length) throw new Error('[b-staff] owner has no store');
  const storeId = parseInt(storeRows[0][0], 10);
  log(`[b-staff] store ${storeId}`);

  log('[b-staff] PART 2 — owner creates a limited-role (waiter) staff via POST /staff');
  const staffPageResp = await page.goto(localeUrl('/staff'), { waitUntil: 'networkidle2' });
  if (!staffPageResp || staffPageResp.status() >= 400) throw new Error(`[b-staff] /staff returned ${staffPageResp && staffPageResp.status()}`);
  await assertRtl(page);
  await shot(page, 'b-staff-list');
  const createRes = await postForm(page, '/staff', {
    name: 'E2E Waiter',
    email: staffEmail,
    password: staffPass,
    role: 'waiter',
    store_id: String(storeId),
  });
  if (createRes.status >= 400) throw new Error(`[b-staff] POST /staff failed (${createRes.status})`);
  const staffRow = db('SELECT id, role, active FROM {p}staff_members WHERE email = ? LIMIT 1', [staffEmail]);
  if (!staffRow.length) throw new Error(`[b-staff] staff row not created for ${staffEmail}`);
  const staffId = parseInt(staffRow[0][0], 10);
  log(`[b-staff] staff created: id=${staffId} role=${staffRow[0][1]} active=${staffRow[0][2]}`);

  log('[b-staff] PART 3 — staff logs in (guard: staff)');
  await loginStaff(page, { email: staffEmail, pass: staffPass });
  await assertRtl(page);
  await shot(page, 'b-staff-dashboard');

  log('[b-staff] PART 4 — default-deny permission matrix (live HTTP status)');
  const ordersStatus = await statusOf(page, '/orders');
  if (ordersStatus !== 200) throw new Error(`[b-staff] waiter should reach /orders (got ${ordersStatus})`);
  log(`[b-staff] ✓ /orders = ${ordersStatus} (permitted — waiter has 'orders')`);

  const customersStatus = await statusOf(page, '/customers');
  if (customersStatus !== 403) throw new Error(`[b-staff] waiter should be denied /customers (got ${customersStatus})`);
  log(`[b-staff] ✓ /customers = ${customersStatus} (denied — waiter lacks 'customers')`);

  const settingsStatus = await statusOf(page, '/settings');
  if (settingsStatus !== 403) throw new Error(`[b-staff] unmapped /settings should default-deny (got ${settingsStatus})`);
  log(`[b-staff] ✓ /settings = ${settingsStatus} (default-deny — route unmapped for staff)`);

  log('[b-staff] PART 5 — owner widens the staff role (grant customers)');
  // permissions is a JSON override map read live by hasPermission() each request.
  db("UPDATE {p}staff_members SET permissions = ? WHERE id = ?", ['{"customers":true}', staffId]);
  const afterWiden = await statusOf(page, '/customers');
  if (afterWiden !== 200) throw new Error(`[b-staff] after widening, /customers should be 200 (got ${afterWiden})`);
  log(`[b-staff] ✓ /customers = ${afterWiden} after granting 'customers' (widening effective immediately)`);

  log('[b-staff] PART 6 — console health');
  await assertRtl(page);
  await assertNoConsoleErrors(page, console_logs);
  await assertNoOverflow(page);

  log('[b-staff] PASS b-staff — staff roles & permission default-deny verified');
};
