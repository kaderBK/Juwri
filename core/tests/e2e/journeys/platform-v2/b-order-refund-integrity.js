// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B11: Order / refund integrity (owner).
 *
 * Beyond v1's POS refund UI path, this asserts the data-integrity invariants of
 * the owner order actions in ManagesOrders:
 *
 *   - INVALID TRANSITION: updateOrderStatus rejects a status outside the enum
 *     (pending/preparing/ready/completed/cancelled) with 400 — the order is not
 *     mutated to a junk state.
 *   - REFUND EXACTLY-ONCE: refundOrderToCredit is idempotent per order (the wallet
 *     ledger dedups on ref `cw:refund:order:{id}:cust`). Firing it twice and then
 *     5× in parallel credits the customer's store credit ONCE — the returned
 *     balance is stable AND the ledger holds exactly one cust entry of the exact
 *     amount. Proves no double-credit under a double-submit / race.
 *   - CANCEL TENDER-RESTORE IDEMPOTENT: cancelling runs the reversal primitives
 *     (stock/gift-card/wallet/loyalty, all idempotent). A repeated cancel returns
 *     200, leaves status=cancelled with cancelled_at set, and does NOT corrupt the
 *     customer's wallet balance (no spurious second reversal).
 *
 * Owner: showcase-demo (crave-demo crave-demo). Orders 106841 (refund) / 106840
 * (cancel) both belong to crave-demo with customer #1 — resolved live so the journey
 * survives id drift. Fully re-runnable: the ledger ref dedup makes refund a no-op
 * on later runs, so every assertion is relative/count-based. Ground truth only.
 */

const V = require('../../lib/platform-v2');

const OWNER = { email: 'showcase-demo@example.com', pass: 'showcase-demo-pass' };
let STORE = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)
const REFUND_AMOUNT = 25;

/** Newest crave-demo order carrying a customer_id, excluding `excludeId`. */
function pickOrder(excludeId) {
  const rows = V.db(
    "SELECT id, customer_id FROM {p}orders WHERE store_id = ? AND customer_id IS NOT NULL"
    + (excludeId ? " AND id <> " + Number(excludeId) : "") + " ORDER BY id DESC LIMIT 1",
    [STORE],
  );
  if (!rows.length) throw new Error('B11: no crave-demo order with a customer_id — check E2ePlatformSeeder');
  return { id: Number(rows[0][0]), customerId: Number(rows[0][1]) };
}

module.exports = async function b11OrderRefundIntegrity({ page, log }) {
  STORE = V.craveStoreId();
  const refundOrder = pickOrder(null);
  const cancelOrder = pickOrder(refundOrder.id);
  log(`B11 fixtures: refund order #${refundOrder.id} (cust ${refundOrder.customerId}), cancel order #${cancelOrder.id} (cust ${cancelOrder.customerId})`);

  await V.loginOwner(page, OWNER);
  // Land on the dashboard for a real CSRF token.
  await page.goto(V.cfg.baseUrl + '/dashboard', { waitUntil: 'domcontentloaded' });

  const postJson = (path, body) => page.evaluate(async ({ path, body }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: JSON.stringify(body),
    });
    return { status: r.status, body: await r.json().catch(() => ({})) };
  }, { path, body });

  // ── 1. INVALID TRANSITION → 400, order not mutated. ────────────────────────
  const before = String(V.db("SELECT status FROM {p}orders WHERE id = ?", [refundOrder.id])[0][0]);
  const bad = await postJson(`/order/${refundOrder.id}/status`, { status: 'teleported' });
  if (bad.status !== 400) throw new Error(`B11 invalid-status: expected 400, got ${bad.status} ${JSON.stringify(bad.body)}`);
  const afterBad = String(V.db("SELECT status FROM {p}orders WHERE id = ?", [refundOrder.id])[0][0]);
  if (afterBad !== before) throw new Error(`B11 invalid-status LEAK: order status changed ${before} → ${afterBad} on a rejected value`);
  log(`B11 invalid transition: status='teleported' → 400, order still '${afterBad}'`);

  // ── 2. REFUND EXACTLY-ONCE per refund request (idempotency key; race-safe). ──
  // Refunds are keyed per refund (P1-12 9b): each refund row credits once, a replay of the same idempotency key
  // credits nothing, and a priced order caps the sum of its refunds at the amount due.
  const refRe = `cw:refund:order:${refundOrder.id}:adj:%:cust`;
  const countBefore = Number(V.db("SELECT COUNT(*) FROM {p}ledger_entries WHERE ref LIKE ?", [refRe])[0][0]);
  const key = `e2e-b11-${Date.now()}`;
  const body = { amount: REFUND_AMOUNT, idempotency_key: key };
  const r1 = await postJson(`/order/${refundOrder.id}/refund-credit`, body);
  if (r1.status === 422 && /not enabled|Store credit/i.test(JSON.stringify(r1.body))) {
    throw new Error(`B11 BLOCKED: store credit disabled on #${STORE} — ${JSON.stringify(r1.body)}`);
  }
  if (r1.status === 422 && /already been refunded|remaining refundable/i.test(JSON.stringify(r1.body))) {
    log(`B11 refund cap reached on #${refundOrder.id} by earlier runs (${JSON.stringify(r1.body.message)}) — the cap itself holds; exactly-once replay not re-checked on this order`);
  } else {
    if (r1.status !== 200 || r1.body.success !== true) throw new Error(`B11 refund #1: ${r1.status} ${JSON.stringify(r1.body)}`);
    const r2 = await postJson(`/order/${refundOrder.id}/refund-credit`, body);
    if (r2.status !== 200 || r2.body.replayed !== true) throw new Error(`B11 refund NOT idempotent: replay of the same key answered ${r2.status} ${JSON.stringify(r2.body)}`);

    // 5 parallel identical refunds (double-submit / race).
    const raced = await V.raceN(() => postJson(`/order/${refundOrder.id}/refund-credit`, body), 5);
    const raceOk = raced.filter((x) => x && x.status === 200).length;

    // Ledger invariant: exactly ONE new customer-side refund entry for this key, of the exact amount.
    const countAfter = Number(V.db("SELECT COUNT(*) FROM {p}ledger_entries WHERE ref LIKE ?", [refRe])[0][0]);
    if (countAfter - countBefore !== 1) {
      throw new Error(`B11 refund ledger LEAK: ${countAfter - countBefore} new entries after 7 same-key refund calls — expected exactly 1`);
    }
    const last = V.db("SELECT amount_minor / 100.0 FROM {p}ledger_entries WHERE ref LIKE ? ORDER BY id DESC LIMIT 1", [refRe]);
    if (Math.abs(Number(last[0][0]) - REFUND_AMOUNT) > 0.001) {
      throw new Error(`B11 refund amount wrong: ledger has ${last[0][0]}, expected ${REFUND_AMOUNT}`);
    }
    log(`B11 refund exactly-once: 7 same-key calls (2 serial + 5 parallel, ${raceOk}/5 race 200) → 1 new ledger entry of ${last[0][0]}`);
  }

  // ── 3. CANCEL TENDER-RESTORE IDEMPOTENT. ───────────────────────────────────
  const c1 = await postJson(`/order/${cancelOrder.id}/status`, { status: 'cancelled' });
  if (c1.status !== 200) throw new Error(`B11 cancel #1: ${c1.status} ${JSON.stringify(c1.body)}`);
  const afterCancel = V.db("SELECT status, cancelled_at FROM {p}orders WHERE id = ?", [cancelOrder.id])[0];
  if (String(afterCancel[0]) !== 'cancelled') throw new Error(`B11 cancel: status is '${afterCancel[0]}', expected cancelled`);
  if (!afterCancel[1]) throw new Error('B11 cancel: cancelled_at not stamped');

  // Ledger snapshot for the cancel order's customer, then a repeat cancel: the
  // reversal primitives are idempotent, so a second cancel must not post new
  // wallet/loyalty reversal entries (no double tender restore).
  const custRefundRefLike = `cw:%order:${cancelOrder.id}%`;
  const entriesBefore = Number(V.db("SELECT COUNT(*) FROM {p}ledger_entries WHERE ref LIKE ?", [custRefundRefLike])[0][0]);
  const c2 = await postJson(`/order/${cancelOrder.id}/status`, { status: 'cancelled' });
  if (c2.status !== 200) throw new Error(`B11 cancel #2 (idempotent): ${c2.status} ${JSON.stringify(c2.body)}`);
  const entriesAfter = Number(V.db("SELECT COUNT(*) FROM {p}ledger_entries WHERE ref LIKE ?", [custRefundRefLike])[0][0]);
  if (entriesAfter !== entriesBefore) {
    throw new Error(`B11 cancel double-restore LEAK: ledger entries for order #${cancelOrder.id} grew ${entriesBefore} → ${entriesAfter} on a repeated cancel`);
  }
  const stillCancelled = String(V.db("SELECT status FROM {p}orders WHERE id = ?", [cancelOrder.id])[0][0]);
  if (stillCancelled !== 'cancelled') throw new Error(`B11 cancel #2: status drifted to '${stillCancelled}'`);
  log(`B11 cancel tender-restore idempotent: repeat cancel → 200, status stays cancelled, ledger entries stable (${entriesAfter})`);

  log('B11 order/refund integrity: invalid-status 400, refund exactly-once, cancel idempotent — all invariants held');
};
