// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B2: Staff PWA offline depth.
 *
 * Goes beyond v1 b-pos-kds (create + KDS + perms). Covers the offline / register
 * operational surfaces:
 *
 *   - OFFLINE-REPLAY DEDUP: the staff PWA queues table-orders while offline and
 *     replays them on reconnect, each tagged with a client_uuid. Replaying the
 *     SAME queued ops twice (a double-fire on flaky reconnect) must create each
 *     order EXACTLY ONCE — OrderApiService dedups on client_uuid. We fire 3
 *     distinct ops, then re-fire the same 3, and assert only 3 orders exist and
 *     the second round returns the same ids.
 *   - SHIFT OPEN/CLOSE + CASH DRAWER + TIPS RECONCILE: open a register shift with
 *     an opening float (drawer open), confirm the current shift, then close it
 *     with a counted cash amount → a Z-report reconciliation is returned (cash
 *     expected/over-short + tips). A second close is refused (422) — no double
 *     reconciliation.
 *   - SW CACHE CLEAR: service workers unregister cleanly (offline shell reset).
 *
 * Staff: e2e-staff-manager@example.test / E2eStaff-pass (crave-demo crave-demo) — the
 * V0.5 matrix manager holds orders + pos + pos_drawer_open + pos_shift_close.
 * REQUIRES `php artisan db:seed --class=E2ePosModifierDemoSeeder --force` for the
 * per-user pos_enabled override on the crave-demo owner (POS is plan-gated). FIXED
 * client_uuids + self-closing shift → re-runnable. Ground truth: orders + Z-report.
 */

const V = require('../../lib/platform-v2');

const MANAGER = { email: 'e2e-staff-manager@example.test', pass: 'E2eStaff-pass', loginPath: '/staff/login' };
let STORE = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)
// Fixed keys → re-runnable (a rerun replays the same orders). J27-A2: keys must be UUIDs.
const UUIDS = ['00000000-0000-7000-8000-0000000b2001', '00000000-0000-7000-8000-0000000b2002', '00000000-0000-7000-8000-0000000b2003'];

function modifierFreeItems() {
  const rows = V.db(
    "SELECT id FROM {p}items m WHERE m.store_id = ? AND m.active = '1' AND m.deleted_at IS NULL "
    + "AND NOT EXISTS (SELECT 1 FROM {p}menu_variants v WHERE v.menu_id = m.id) "
    + "AND NOT EXISTS (SELECT 1 FROM {p}item_modifier_group piv WHERE piv.menu_id = m.id) "
    + "ORDER BY m.id LIMIT 2", [STORE]);
  if (!rows.length) throw new Error('B2: no modifier-free menu item on the store');
  return rows.map((r) => Number(r[0]));
}

async function apiPost(page, path, data) {
  return page.evaluate(async ({ path, data }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch(path, {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: JSON.stringify(data),
    });
    let body = null; try { body = await r.json(); } catch { body = null; }
    return { status: r.status, body };
  }, { path, data });
}
async function apiGet(page, path) {
  return page.evaluate(async (path) => {
    const r = await fetch(path, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    let body = null; try { body = await r.json(); } catch { body = null; }
    return { status: r.status, body };
  }, path);
}

module.exports = async function b2PwaOffline({ page, log }) {
  STORE = V.craveStoreId();
  const [item1, item2] = modifierFreeItems();
  log(`B2 modifier-free items: ${item1}, ${item2}`);

  await V.loginStaff(page, MANAGER);

  // ── PART 1: OFFLINE-REPLAY DEDUP (staff-app). ──────────────────────────────
  const shell = await page.goto(V.cfg.baseUrl + '/staff-app', { waitUntil: 'networkidle2' });
  if (!shell || shell.status() >= 400) throw new Error(`B2: /staff-app → ${shell ? shell.status() : 'no response'}`);

  const fire = async (uuid, table) => apiPost(page, '/staff-app/table-orders', {
    table_number: table, client_uuid: uuid, items: [{ item_id: item1, quantity: 1 }, { item_id: item2, quantity: 1 }],
  });

  // Round 1 — the queued ops replay on reconnect.
  const round1 = [];
  for (let i = 0; i < UUIDS.length; i++) {
    const r = await fire(UUIDS[i], String(50 + i));
    if (![200, 201].includes(r.status) || !r.body || !r.body.id) throw new Error(`B2 replay op ${i}: ${r.status} ${JSON.stringify(r.body)}`);
    round1.push(Number(r.body.id));
  }
  // Round 2 — the SAME ops fire again (double-fire) → must dedup to the same ids.
  const round2 = [];
  for (let i = 0; i < UUIDS.length; i++) {
    const r = await fire(UUIDS[i], String(50 + i));
    if (![200, 201].includes(r.status) || !r.body || !r.body.id) throw new Error(`B2 dedup op ${i}: ${r.status} ${JSON.stringify(r.body)}`);
    round2.push(Number(r.body.id));
  }
  if (JSON.stringify(round1) !== JSON.stringify(round2)) {
    throw new Error(`B2 DEDUP: replay returned different ids — round1=${round1} round2=${round2} (client_uuid not deduped → duplicate orders)`);
  }
  const distinct = V.db("SELECT COUNT(*) FROM {p}orders WHERE store_id = ? AND client_uuid IN (?, ?, ?)", [STORE, ...UUIDS]);
  if (Number(distinct[0][0]) !== UUIDS.length) {
    throw new Error(`B2 DEDUP: expected ${UUIDS.length} orders for the 3 client_uuids, found ${distinct[0][0]} (double-fire created duplicates)`);
  }
  log(`B2 offline-replay dedup: 3 ops fired twice → same ids ${round1.join(',')}, exactly ${distinct[0][0]} orders (client_uuid dedups CREATE)`);

  // ── PART 2: SHIFT open/close + cash drawer + tips reconcile (register). ─────
  const pos = await page.goto(`${V.cfg.baseUrl}/pos?store=${STORE}`, { waitUntil: 'networkidle2' });
  if (!pos || pos.status() >= 400) throw new Error(`B2: /pos → ${pos ? pos.status() : 'no response'} (pos_enabled? run E2ePosModifierDemoSeeder)`);

  // Pre-close any shift left open by a prior run (one open shift per branch).
  const cur0 = await apiGet(page, '/pos/shifts/current');
  const openId = cur0.body && cur0.body.shift && (cur0.body.shift.shift_id || cur0.body.shift.id);
  if (openId) {
    await apiPost(page, `/pos/shifts/${openId}/close`, { counted_cash: 0 });
    log(`B2 shift: pre-closed a stale open shift #${openId}`);
  }

  // Open a shift with an opening float (cash drawer open).
  const open = await apiPost(page, '/pos/shifts', { opening_float: 100 });
  if (open.status !== 201 || !open.body || !open.body.shift_id) throw new Error(`B2 shift open: ${open.status} ${JSON.stringify(open.body)}`);
  const shiftId = Number(open.body.shift_id);
  const cur = await apiGet(page, '/pos/shifts/current');
  const curId = cur.body && cur.body.shift && (cur.body.shift.shift_id || cur.body.shift.id);
  if (Number(curId) !== shiftId) throw new Error(`B2 shift: current shift is ${curId}, expected the just-opened ${shiftId}`);
  log(`B2 shift: opened #${shiftId} (float 100) — cash drawer open, confirmed current`);

  // Close it with a counted cash amount → Z-report reconciliation.
  const close = await apiPost(page, `/pos/shifts/${shiftId}/close`, { counted_cash: 100 });
  if (close.status !== 200 || !close.body) throw new Error(`B2 shift close: ${close.status} ${JSON.stringify(close.body)}`);
  const z = close.body;
  const zStr = JSON.stringify(z).toLowerCase();
  if (!/cash|expected|over|short|tip|float|total/.test(zStr)) {
    throw new Error(`B2 shift close: Z-report has no cash/tips reconciliation fields: ${JSON.stringify(z).slice(0, 200)}`);
  }
  log(`B2 shift close: Z-report reconciliation returned (${Object.keys(z).join(', ')})`);

  // A second close must be refused — no double reconciliation.
  const closeAgain = await apiPost(page, `/pos/shifts/${shiftId}/close`, { counted_cash: 100 });
  if (closeAgain.status !== 422) throw new Error(`B2 shift double-close: expected 422, got ${closeAgain.status}`);
  log(`B2 shift double-close: refused (422) — reconciliation is once-only`);

  // ── PART 3: SW cache clear. ────────────────────────────────────────────────
  const sw = await page.evaluate(async () => {
    if (!('serviceWorker' in navigator)) return { supported: false, before: 0, after: 0 };
    const before = (await navigator.serviceWorker.getRegistrations()).length;
    for (const r of await navigator.serviceWorker.getRegistrations()) { await r.unregister(); }
    if (window.caches && caches.keys) { for (const k of await caches.keys()) { await caches.delete(k); } }
    const after = (await navigator.serviceWorker.getRegistrations()).length;
    return { supported: true, before, after };
  });
  if (sw.supported && sw.after !== 0) throw new Error(`B2 SW clear: ${sw.after} registrations remain after unregister`);
  log(`B2 SW cache clear: service workers unregistered (${sw.supported ? `${sw.before}→${sw.after}` : 'SW unsupported in this context'})`);

  log('B2 staff PWA offline: replay dedup (client_uuid), shift open/close + drawer + tips reconcile (Z-report, once-only), SW cache cleared');
};
