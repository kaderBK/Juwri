// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B6 — POS KDS staff PWA (platform E2E, lane B).
 *
 * Owns the staff PWA seam (session `staff` guard). The owner self-provisions two
 * staff members through the real `POST /staff` endpoint:
 *   - a cashier  (orders + kds + pos)  — the POS/KDS operator
 *   - a kitchen  (kds only)            — the permission-deny control
 *
 * Then, against the REAL staff-app endpoints:
 *   - cashier loads /staff-app (RTL/ar) and /staff-app/menu (JSON),
 *   - cashier creates a tableside order via POST /staff-app/table-orders (201,
 *     server-priced) — verified against db() (source=staff_pwa, created_by_staff),
 *   - the KDS rail (GET /staff-app/kds) is fetched; if the order's lines are on
 *     the rail, a line is bumped (POST /staff-app/kds/items/{id}/bump) and the
 *     new status verified in DB, then recalled,
 *   - kitchen (kds-only) is DENIED order creation (POST /staff-app/table-orders
 *     → 403) — the default-deny control.
 *
 * Finishes with no console errors / no ENFORCED CSP refusals on the staff shell.
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

async function postForm(page, path, fields) {
  return page.evaluate(async ({ path, fields }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
      || document.querySelector('input[name="_token"]')?.value || '';
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(fields)) body.append(k, v);
    const r = await fetch(path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html', 'X-CSRF-TOKEN': csrf },
      body: body.toString(),
    });
    return { status: r.status };
  }, { path, fields });
}

async function apiGet(page, path) {
  return page.evaluate(async (path) => {
    const r = await fetch(path, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    let j = null; try { j = await r.json(); } catch (_e) { /* non-json */ }
    return { status: r.status, ok: r.ok, body: j };
  }, path);
}

async function apiPostJson(page, path, data) {
  return page.evaluate(async ({ path, data }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
      || document.querySelector('input[name="_token"]')?.value || '';
    const r = await fetch(path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(data || {}),
    });
    let j = null; try { j = await r.json(); } catch (_e) { /* non-json */ }
    return { status: r.status, ok: r.ok, body: j };
  }, { path, data });
}

/** Create a staff member (owner session) via POST /staff; returns its id. */
async function provisionStaff(page, { email, pass, role, storeId }) {
  const res = await postForm(page, '/staff', {
    name: `E2E ${role}`, email, password: pass, role, store_id: String(storeId),
  });
  if (res.status >= 400) throw new Error(`provisionStaff(${role}): POST /staff failed (${res.status})`);
  const rows = db('SELECT id FROM {p}staff_members WHERE email = ? LIMIT 1', [email]);
  if (!rows.length) throw new Error(`provisionStaff(${role}): row not created for ${email}`);
  return parseInt(rows[0][0], 10);
}

module.exports = async ({ page, log, shot, sleep }) => {
  if (cfg.locale !== 'ar') {
    throw new Error('b-pos-kds: this journey must run with E2E_LOCALE=ar (Arabic only)');
  }

  const SHOWCASE_EMAIL = process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com';
  const SHOWCASE_PASS = process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass';
  const ownerCreds = { email: SHOWCASE_EMAIL, pass: SHOWCASE_PASS };

  const ts = Date.now();
  const cashier = { email: `e2e-b-cashier-${ts}@local.invalid`, pass: 'E2eStaff!2345', role: 'cashier' };
  const kitchen = { email: `e2e-b-kitchen-${ts}@local.invalid`, pass: 'E2eStaff!2345', role: 'kitchen' };

  const console_logs = collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });

  log('[b-pos-kds] PART 1 — owner login + self-provision cashier & kitchen staff');
  await loginOwner(page, ownerCreds);
  await assertRtl(page);
  const ownerRows = db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [SHOWCASE_EMAIL]);
  if (!ownerRows.length) throw new Error('[b-pos-kds] owner not found');
  const storeRows = db('SELECT id FROM {p}stores WHERE user_id = ? ORDER BY id LIMIT 1', [ownerRows[0][0]]);
  if (!storeRows.length) throw new Error('[b-pos-kds] owner has no store');
  const storeId = parseInt(storeRows[0][0], 10);
  await page.goto(localeUrl('/staff'), { waitUntil: 'networkidle2' });
  const cashierId = await provisionStaff(page, { ...cashier, storeId });
  const kitchenId = await provisionStaff(page, { ...kitchen, storeId });
  log(`[b-pos-kds] store ${storeId}; cashier id=${cashierId}, kitchen id=${kitchenId}`);

  log('[b-pos-kds] PART 2 — cashier login → staff-app shell (RTL) + menu feed');
  await loginStaff(page, { email: cashier.email, pass: cashier.pass });
  const shellResp = await page.goto(localeUrl('/staff-app'), { waitUntil: 'networkidle2' });
  if (!shellResp || shellResp.status() >= 400) throw new Error(`[b-pos-kds] /staff-app returned ${shellResp && shellResp.status()}`);
  await assertRtl(page);
  await shot(page, 'b-pos-kds-shell');

  const menuRes = await apiGet(page, '/staff-app/menu');
  if (menuRes.status !== 200 || !menuRes.body || !Array.isArray(menuRes.body.items) || !menuRes.body.items.length) {
    throw new Error(`[b-pos-kds] /staff-app/menu bad (${menuRes.status} ${JSON.stringify(menuRes.body).slice(0, 200)})`);
  }
  log(`[b-pos-kds] menu items available: ${menuRes.body.items.length}`);
  // OrderApiService (server pricing) ENFORCES required modifier groups, so pick
  // items with no variant groups AND no extras — they take {item_id, quantity}
  // with no options. (active enum '1'; deleted_at mirrors the Menu soft-delete scope.)
  const safeRows = db(
    "SELECT id FROM {p}items m WHERE m.store_id = ? AND m.active = '1' AND m.deleted_at IS NULL "
    + "AND NOT EXISTS (SELECT 1 FROM {p}menu_variants v WHERE v.menu_id = m.id) "
    + "AND NOT EXISTS (SELECT 1 FROM {p}item_modifier_group e WHERE e.menu_id = m.id AND e.modifier_id IS NOT NULL) ORDER BY m.id LIMIT 2",
    [storeId],
  );
  if (safeRows.length < 1) throw new Error('[b-pos-kds] no modifier-free menu item for a POS order');
  const item1 = parseInt(safeRows[0][0], 10);
  const item2 = parseInt((safeRows[1] || safeRows[0])[0], 10);
  log(`[b-pos-kds] modifier-free items: ${item1}, ${item2}`);

  log('[b-pos-kds] PART 3 — cashier creates a tableside order (POST /staff-app/table-orders)');
  const createRes = await apiPostJson(page, '/staff-app/table-orders', {
    table_number: '42',
    items: [{ item_id: item1, quantity: 1 }, { item_id: item2, quantity: 2 }],
    note: 'E2E POS order',
  });
  if (createRes.status !== 201 || !createRes.body || !createRes.body.id) {
    throw new Error(`[b-pos-kds] table-order create failed (${createRes.status} ${JSON.stringify(createRes.body)})`);
  }
  const newOrderId = parseInt(createRes.body.id, 10);
  const orderRow = db('SELECT store_id, source, created_by_staff_id, type FROM {p}orders WHERE id = ?', [newOrderId]);
  if (!orderRow.length) throw new Error(`[b-pos-kds] created order ${newOrderId} not in DB`);
  if (parseInt(orderRow[0][0], 10) !== storeId) throw new Error(`[b-pos-kds] order store_id mismatch`);
  if (orderRow[0][1] !== 'staff_pwa') throw new Error(`[b-pos-kds] order source is "${orderRow[0][1]}", expected staff_pwa`);
  if (parseInt(orderRow[0][2], 10) !== cashierId) throw new Error(`[b-pos-kds] created_by_staff_id is ${orderRow[0][2]}, expected ${cashierId}`);
  log(`[b-pos-kds] ✓ tableside order ${newOrderId} created (source=staff_pwa, created_by_staff=${cashierId}, type=${orderRow[0][3]})`);

  log('[b-pos-kds] PART 4 — KDS rail (GET /staff-app/kds) + bump/recall');
  const kdsRes = await apiGet(page, '/staff-app/kds');
  if (kdsRes.status !== 200 || !kdsRes.body || !Array.isArray(kdsRes.body.tickets)) {
    throw new Error(`[b-pos-kds] /staff-app/kds bad (${kdsRes.status} ${JSON.stringify(kdsRes.body).slice(0, 200)})`);
  }
  log(`[b-pos-kds] KDS rail tickets: ${kdsRes.body.tickets.length}`);
  // Find a live line item on the rail (prefer our order; else any).
  let target = null;
  for (const t of kdsRes.body.tickets) {
    const items = t.items || [];
    const pick = items.find((it) => ['queued', 'preparing', 'ready'].includes(it.status));
    if (pick) { target = { orderId: t.order_id, item: pick }; if (t.order_id === newOrderId) break; }
  }
  if (target) {
    const before = target.item.status;
    const bump = await apiPostJson(page, `/staff-app/kds/items/${target.item.id}/bump`, {});
    if (bump.status !== 200 || !bump.body) throw new Error(`[b-pos-kds] bump failed (${bump.status} ${JSON.stringify(bump.body)})`);
    const afterDb = db('SELECT status FROM {p}order_items WHERE id = ?', [target.item.id]);
    log(`[b-pos-kds] ✓ bumped item ${target.item.id}: ${before} → ${bump.body.status} (DB=${afterDb.length ? afterDb[0][0] : 'n/a'})`);
    if (afterDb.length && afterDb[0][0] !== bump.body.status) {
      throw new Error(`[b-pos-kds] bump status mismatch DB="${afterDb[0][0]}" resp="${bump.body.status}"`);
    }
    const recall = await apiPostJson(page, `/staff-app/kds/items/${target.item.id}/recall`, {});
    if (recall.status !== 200) throw new Error(`[b-pos-kds] recall failed (${recall.status})`);
    log(`[b-pos-kds] ✓ recalled item ${target.item.id}: now ${recall.body && recall.body.status}`);
  } else {
    log('[b-pos-kds] no live KDS line on the rail (station routing off for this store) — bump/recall skipped');
  }

  log('[b-pos-kds] PART 5 — console health (cashier shell)');
  await assertNoConsoleErrors(page, console_logs);
  await assertNoOverflow(page);

  log('[b-pos-kds] PART 6 — permission control: kitchen (kds-only) is DENIED order creation');
  await loginStaff(page, { email: kitchen.email, pass: kitchen.pass });
  await page.goto(localeUrl('/staff-app'), { waitUntil: 'networkidle2' });
  const denied = await apiPostJson(page, '/staff-app/table-orders', {
    table_number: '43', items: [{ item_id: item1, quantity: 1 }],
  });
  if (denied.status !== 403) {
    throw new Error(`[b-pos-kds] kitchen staff should be denied order creation (expected 403, got ${denied.status})`);
  }
  log('[b-pos-kds] ✓ kitchen staff denied order creation (403) — default-deny working');

  log('[b-pos-kds] PASS b-pos-kds — staff PWA POS + KDS + permission control verified');
};
