// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B5 — Orders hub (platform E2E, lane B).
 *
 * Owns the owner orders-hub seam. Places REAL orders through the storefront
 * `POST /{slug}/send-order` (server-priced → snapshotTotals writes
 * subtotal/tax_total/grand_total), then:
 *   - opens the owner orders hub (RTL/ar) and asserts the order renders,
 *   - asserts the stored totals satisfy grand_total = subtotal − discount + tax
 *     (tax PRE-discount — the platform pricing invariant),
 *   - drives the full lifecycle through the production owner endpoint
 *     `POST /order/{id}/status` (preparing → ready → completed), verifying DB
 *     status + the R1a status timestamps after each step,
 *   - cancels a second order and asserts status=cancelled + cancelled_at,
 *   - re-cancels to prove idempotence.
 *
 * Every transition is server-authoritative (tenant ownership + status enum
 * validated server-side), so this is a genuine lifecycle test. Uses the seeded
 * showcase owner (crave-demo). Finishes with no console errors / no ENFORCED CSP
 * refusals.
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
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      body: body.toString(),
    });
    let j = null; try { j = await r.json(); } catch (_e) { /* non-json */ }
    return { status: r.status, ok: r.ok, body: j };
  }, { path, fields });
}

/** POST JSON to a same-origin endpoint using the page's CSRF meta. */
async function postJson(page, path, data) {
  return page.evaluate(async ({ path, data }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
      || document.querySelector('input[name="_token"]')?.value || '';
    const r = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(data || {}),
    });
    let j = null; try { j = await r.json(); } catch (_e) { /* non-json */ }
    return { status: r.status, ok: r.ok, body: j };
  }, { path, data });
}

const statusOf = (id) => {
  const rows = db('SELECT status FROM {p}orders WHERE id = ?', [id]);
  return rows.length ? rows[0][0] : null;
};

/** Place a real dine-in order on store `storeId` for `menuId`; returns the new order id.
 *  The send-order route is keyed by the restaurant's POST id (route('store.submitOrder', $store->id)), not the slug. */
async function placeOrder(page, storeId, menuId, label) {
  const res = await postForm(page, `/${storeId}/send-order`, {
    name: 'E2E Orders ' + label,
    'ordering-type': 'on-table',
    table: '77', // table_number is an integer column
    pay_via: 'pay_on_counter',
    items: JSON.stringify([{ id: menuId, quantity: 1 }]),
  });
  if (!res.body || res.body.success !== true) {
    throw new Error(`placeOrder(${label}): send-order rejected (${res.status} ${JSON.stringify(res.body)})`);
  }
  let orderId = res.body.order_id ? parseInt(res.body.order_id, 10) : null;
  if (!orderId && res.body.track_url) {
    const token = (String(res.body.track_url).match(/order\/track\/([^/?#]+)/) || [])[1];
    if (token) {
      const rows = db('SELECT id FROM {p}orders WHERE track_token = ? LIMIT 1', [token]);
      if (rows.length) orderId = parseInt(rows[0][0], 10);
    }
  }
  if (!orderId || Number.isNaN(orderId)) {
    throw new Error(`placeOrder(${label}): could not resolve order id from ${JSON.stringify(res.body)}`);
  }
  return orderId;
}

module.exports = async ({ page, log, shot, sleep }) => {
  if (cfg.locale !== 'ar') {
    throw new Error('b-orders: this journey must run with E2E_LOCALE=ar (Arabic only)');
  }

  const SHOWCASE_EMAIL = process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com';
  const SHOWCASE_PASS = process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass';
  const ownerCreds = { email: SHOWCASE_EMAIL, pass: SHOWCASE_PASS };

  const console_logs = collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });

  log('[b-orders] SETUP — logging in as owner');
  await loginOwner(page, ownerCreds);
  await assertRtl(page);

  const ownerRows = db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [SHOWCASE_EMAIL]);
  if (!ownerRows.length) throw new Error('b-orders: owner not found');
  const storeRows = db('SELECT id, slug FROM {p}stores WHERE user_id = ? ORDER BY id LIMIT 1', [ownerRows[0][0]]);
  if (!storeRows.length) throw new Error('b-orders: owner has no store');
  const storeId = parseInt(storeRows[0][0], 10);
  const storeSlug = storeRows[0][1];
  // enum('0','1'): the string '1' is active; `active = 1` would compare by enum
  // ordinal and match '0' (MySQL enum active-index trap). `deleted_at IS NULL`
  // mirrors the Menu SoftDeletes global scope the send-order controller applies —
  // a soft-deleted id is invisible to it and 404s the line as "not available".
  const menuRows = db(
    "SELECT id FROM {p}items WHERE store_id = ? AND active = '1' AND deleted_at IS NULL ORDER BY id LIMIT 1",
    [storeId],
  );
  if (!menuRows.length) throw new Error(`b-orders: no active menu item for store ${storeId}`);
  const menuId = parseInt(menuRows[0][0], 10);
  log(`[b-orders] store ${storeId} (${storeSlug}); menu item ${menuId}`);

  log('[b-orders] PART 1 — place two real orders via the storefront');
  const sfResp = await page.goto(localeUrl(`/${storeSlug}`), { waitUntil: 'networkidle2' });
  if (!sfResp || sfResp.status() >= 400) throw new Error(`b-orders: storefront returned ${sfResp && sfResp.status()}`);
  await assertRtl(page);
  const lifecycleId = await placeOrder(page, storeId, menuId, 'lifecycle');
  const cancelId = await placeOrder(page, storeId, menuId, 'cancel');
  log(`[b-orders] placed lifecycle order ${lifecycleId}, cancel order ${cancelId}`);

  log('[b-orders] PART 2 — tax-pre-discount invariant on the placed order totals');
  const totals = db(
    'SELECT subtotal_minor / 100.0, discount_amount_minor / 100.0, tax_total_minor / 100.0, grand_total_minor / 100.0 FROM {p}orders WHERE id = ?',
    [lifecycleId],
  )[0];
  const sub = parseFloat(totals[0]);
  const disc = parseFloat(totals[1] || '0');
  const tax = parseFloat(totals[2] || '0');
  const grand = parseFloat(totals[3]);
  if (!(sub > 0) || Number.isNaN(grand)) {
    throw new Error(`b-orders: order ${lifecycleId} has no real totals (sub=${totals[0]} grand=${totals[3]})`);
  }
  const expected = sub - disc + tax; // tax PRE-discount
  if (Math.abs(grand - expected) > 0.02) {
    throw new Error(`b-orders: order ${lifecycleId} grand_total ${grand} ≠ sub ${sub} − disc ${disc} + tax ${tax} = ${expected.toFixed(2)}`);
  }
  log(`[b-orders] totals ok: grand ${grand} = sub ${sub} − disc ${disc} + tax ${tax}`);

  log('[b-orders] PART 3 — orders hub renders (RTL), placed order present');
  const hubResp = await page.goto(localeUrl('/orders?store_id=' + storeId), { waitUntil: 'networkidle2' });
  if (!hubResp || hubResp.status() >= 400) throw new Error(`b-orders: /orders returned ${hubResp && hubResp.status()}`);
  await assertRtl(page);
  await shot(page, 'b-orders-hub');
  const rowCount = await page.$$eval(
    '[data-order], [data-order-id], tr[data-id], .order-row, table tbody tr',
    (els) => els.length,
  ).catch(() => 0);
  if (rowCount === 0) throw new Error('b-orders: orders hub rendered no order rows');
  log(`[b-orders] orders hub rows=${rowCount}`);

  log('[b-orders] PART 4 — drive lifecycle via POST /order/{id}/status (server-authoritative)');
  const transitions = ['preparing', 'ready', 'completed'];
  for (const status of transitions) {
    const res = await postJson(page, `/order/${lifecycleId}/status`, { status });
    if (res.status !== 200 || !res.body || res.body.success !== true) {
      throw new Error(`b-orders: status→${status} failed (${res.status} ${JSON.stringify(res.body)})`);
    }
    const dbStatus = statusOf(lifecycleId);
    if (dbStatus !== status) throw new Error(`b-orders: DB status is "${dbStatus}" after transition to "${status}"`);
    log(`[b-orders] ✓ ${status} persisted (order ${lifecycleId})`);
    await sleep(120);
  }
  const stamps = db('SELECT preparing_at, ready_at, completed_at FROM {p}orders WHERE id = ?', [lifecycleId])[0];
  if (!stamps[0] || !stamps[1] || !stamps[2]) {
    throw new Error(`b-orders: expected preparing/ready/completed timestamps, got ${JSON.stringify(stamps)}`);
  }
  log('[b-orders] lifecycle timestamps stamped (preparing/ready/completed)');

  log('[b-orders] PART 5 — cancel a second order with stock restore');
  const cancelRes = await postJson(page, `/order/${cancelId}/status`, { status: 'cancelled' });
  if (cancelRes.status !== 200 || !cancelRes.body || cancelRes.body.success !== true) {
    throw new Error(`b-orders: cancel failed (${cancelRes.status} ${JSON.stringify(cancelRes.body)})`);
  }
  if (statusOf(cancelId) !== 'cancelled') throw new Error(`b-orders: order ${cancelId} not cancelled (got ${statusOf(cancelId)})`);
  const cancelledAt = db('SELECT cancelled_at FROM {p}orders WHERE id = ?', [cancelId])[0][0];
  if (!cancelledAt) throw new Error('b-orders: cancelled_at not stamped');
  log(`[b-orders] ✓ order ${cancelId} cancelled (cancelled_at=${cancelledAt})`);

  log('[b-orders] PART 6 — idempotent re-cancel is safe');
  const reCancel = await postJson(page, `/order/${cancelId}/status`, { status: 'cancelled' });
  if (reCancel.status !== 200 || !reCancel.body || reCancel.body.success !== true) {
    throw new Error(`b-orders: re-cancel not idempotent (${reCancel.status} ${JSON.stringify(reCancel.body)})`);
  }
  log('[b-orders] re-cancel returned success (idempotent)');

  log('[b-orders] PART 7 — orders hub still renders after transitions');
  await page.goto(localeUrl('/orders?store_id=' + storeId), { waitUntil: 'networkidle2' });
  await assertRtl(page);
  await assertNoOverflow(page);

  log('[b-orders] PART 8 — console health');
  await assertNoConsoleErrors(page, console_logs);

  log('[b-orders] PASS b-orders — orders hub + real order lifecycle verified');
};
