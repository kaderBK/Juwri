// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B9: Delivery / dispatch (owner).
 *
 * Drives the owner dispatch surface (DispatchController@assign →
 * DispatchOrchestrator::reassign) and asserts:
 *
 *   - TENANT FENCE: assigning a driver that belongs to ANOTHER restaurant to this
 *     store's order is rejected (422) and the order stays unassigned — a driver may
 *     only be dispatched by the store that owns it (assign scopes Driver by the
 *     order's store_id).
 *   - STATE MACHINE: assigning an OWN driver moves the order pending → assigned and
 *     stamps driver_id (the orchestrator transition, not a raw write).
 *   - IDEMPOTENT re-assign: assigning the same driver again keeps it assigned.
 *
 * Fixtures: E2eDispatchDemoSeeder — driver e2e-dispatch-driver@example.test on
 * crave-demo  + a pending delivery order (track e2eDispatchOrderTRACKtoken00001).
 * The journey RESETS that order to pending at the start, so it is re-runnable.
 *
 * ZONES/FEES config: there is no owner-scoped (restaurants.*) zone-fee WRITE — zone
 * fees live under the platform admin fusion surface (admin.fusion.delivery.zones.*,
 * lane C). Recorded; B9 covers the owner dispatch/driver path.
 *
 * Owner: showcase-demo . Ground truth: orders.delivery_status + driver_id.
 */

const V = require('../../lib/platform-v2');

const OWNER = { email: 'showcase-demo@example.com', pass: 'showcase-demo-pass' };
let STORE = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)
const ORDER_TRACK = 'e2eDispatchOrderTRACKtoken00001';

function orderRow() {
  const r = V.db("SELECT id, delivery_status, driver_id FROM {p}orders WHERE track_token = ? LIMIT 1", [ORDER_TRACK]);
  if (!r.length) throw new Error('B9 BLOCKED: dispatch demo order not found — run E2eDispatchDemoSeeder');
  const d = r[0][2];
  return { id: Number(r[0][0]), deliveryStatus: r[0][1], driverId: (d === null || d === '' || String(d).toUpperCase() === 'NULL') ? null : Number(d) };
}
function ownDriverId() {
  const r = V.db("SELECT id FROM {p}drivers WHERE store_id = ? AND email = 'e2e-dispatch-driver@example.test' LIMIT 1", [STORE]);
  if (!r.length) throw new Error('B9 BLOCKED: store-owned driver not found — run E2eDispatchDemoSeeder');
  return Number(r[0][0]);
}
function foreignDriverId() {
  const r = V.db("SELECT id FROM {p}drivers WHERE store_id <> ? ORDER BY id LIMIT 1", [STORE]);
  if (!r.length) throw new Error('B9: no foreign driver to test the tenant fence');
  return Number(r[0][0]);
}

async function assign(page, orderId, driverId) {
  return page.evaluate(async ({ orderId, driverId }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch(`/dispatch/${orderId}/assign`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: JSON.stringify({ driver_id: driverId }),
      redirect: 'follow',
    });
    return { status: r.status };
  }, { orderId, driverId });
}

module.exports = async function b9Dispatch({ page, log }) {
  STORE = V.craveStoreId();
  const ord = orderRow();
  const own = ownDriverId();
  const foreign = foreignDriverId();
  log(`B9 fixtures: order #${ord.id}, own driver #${own} (store ${STORE}), foreign driver #${foreign}`);

  // Prime: reset the test order to pending/unassigned (re-runnable).
  V.db("UPDATE {p}orders SET delivery_status = 'pending', driver_id = NULL WHERE id = ?", [ord.id]);

  await V.loginOwner(page, OWNER);
  const shell = await page.goto(V.cfg.baseUrl + '/dispatch', { waitUntil: 'domcontentloaded' });
  if (!shell || shell.status() >= 400) throw new Error(`B9: /dispatch → ${shell ? shell.status() : 'no response'}`);

  // ── 1. TENANT FENCE: a foreign driver is rejected (422) + order untouched. ──
  const fence = await assign(page, ord.id, foreign);
  // The fence refuses with 422 (controller: driver not in this store) — or 403 when the tenant layer
  // (account scope / ownership check) rejects the foreign id first. Either is a refusal; anything else is not.
  if (![403, 422].includes(fence.status)) throw new Error(`B9 tenant fence: assigning foreign driver #${foreign} returned ${fence.status}, expected 403/422`);
  const afterFence = orderRow();
  if (afterFence.driverId !== null || String(afterFence.deliveryStatus) === 'assigned') {
    throw new Error(`B9 tenant fence LEAK: order was modified by a foreign-driver assign (driver_id=${afterFence.driverId}, status=${afterFence.deliveryStatus})`);
  }
  log(`B9 tenant fence: foreign driver #${foreign} → ${fence.status}, order still pending/unassigned`);

  // ── 2. STATE MACHINE: own driver → pending → assigned. ─────────────────────
  const ok = await assign(page, ord.id, own);
  if (ok.status >= 400) throw new Error(`B9 assign own driver → ${ok.status}`);
  const afterAssign = orderRow();
  if (String(afterAssign.deliveryStatus) !== 'assigned') {
    throw new Error(`B9 state machine: expected delivery_status='assigned', got '${afterAssign.deliveryStatus}'`);
  }
  if (afterAssign.driverId !== own) {
    throw new Error(`B9 state machine: expected driver_id=${own}, got ${afterAssign.driverId}`);
  }
  log(`B9 assign: own driver #${own} → delivery_status='assigned', driver_id=${afterAssign.driverId} (pending→assigned)`);

  // ── 3. IDEMPOTENT re-assign to the same driver. ────────────────────────────
  const again = await assign(page, ord.id, own);
  if (again.status >= 400) throw new Error(`B9 re-assign → ${again.status}`);
  const afterAgain = orderRow();
  if (String(afterAgain.deliveryStatus) !== 'assigned' || afterAgain.driverId !== own) {
    throw new Error(`B9 re-assign: order drifted (status=${afterAgain.deliveryStatus}, driver_id=${afterAgain.driverId})`);
  }
  log(`B9 re-assign: same driver → still assigned to #${own} (idempotent)`);

  log('B9 dispatch: tenant fence blocks a foreign driver (403/422), own-driver assign transitions pending→assigned, re-assign idempotent');
};
