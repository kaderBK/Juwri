// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A5 — Concurrency / race, customer surface (Platform E2E v2, Lane A).
 *
 * Fires genuinely-parallel storefront requests (all fetches launched inside one
 * page.evaluate via Promise.all, so they hit the server concurrently, not
 * serialized by puppeteer) and asserts the server's atomic guarantees hold:
 *
 *   1. Double-submit dedup — N identical send-orders sharing one client_uuid
 *      create exactly ONE order (the storefront idempotency key), the rest are
 *      deduped replays.
 *   2. Coupon atomic claim — N parallel orders all applying a SINGLE-USE coupon
 *      (E2EONCE1, limit=1): exactly one lands the discount; used → 1, not N.
 *   3. Stock decrement race — an item stocked at exactly N, bought once by each
 *      of N parallel orders: the conditional `where stock_qty >= qty` decrement
 *      lands final stock at exactly 0 with no lost update and no oversell.
 *   4. Client order key (J27-A2) — the storefront mints a UUIDv7 token per checkout,
 *      the same key with another cart is a 409 (banner shows the message), and the
 *      next order after a success gets a new key.
 *
 * Run: `E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh a a-concurrency`
 */

const path = require('path');
const { execFileSync } = require('child_process');
const V = require('../../lib/platform-v2');
const K = require('../../lib/order-kit-v2');

const STORE = 'crave-demo';
const N = 5;
const ONCE_COUPON = 'E2EONCE1';

// The storefront order route is throttled 10/min per restaurant+IP. Each race
// leg is a burst; clearing the (file) cache before a leg resets that limiter so
// the leg — and a ×2 re-run — starts from a clean window WITHOUT changing any
// app-wide setting. Best-effort: a failure here just risks a 429 the leg reports.
function resetOrderThrottle() {
  try {
    const coreDir = V.siteCoreDir();
    execFileSync('php', ['artisan', 'cache:clear'], { cwd: coreDir, stdio: 'ignore' });
  } catch (_e) { /* non-fatal */ }
}

/** Fire `n` send-order POSTs in parallel from the page; returns their JSON envelopes. */
function raceOrders(page, { endpoint, csrf, baseFields, perIndex }) {
  return page.evaluate(async ({ endpoint, csrf, baseFields, perIndex, n }) => {
    const fire = async (i) => {
      const fd = new FormData();
      const fields = Object.assign({}, baseFields, perIndex[i] || {});
      Object.keys(fields).forEach((k) => fd.append(k, fields[k]));
      const r = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: Object.assign({ Accept: 'application/json' }, csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        body: fd,
      });
      let j = null; try { j = await r.json(); } catch (_e) { j = null; }
      return Object.assign({ status: r.status }, j || {});
    };
    return Promise.all(Array.from({ length: n }, (_, i) => fire(i).catch((e) => ({ error: String(e) }))));
  }, { endpoint, csrf, baseFields, perIndex, n: perIndex.length });
}

module.exports = async function a5Concurrency({ page, log, shot }) {
  const checks = [];
  const ok = (name) => { checks.push(name); log(`  a5 ✓ ${name}`); };

  const rt = await K.loadRuntime(page, V.enUrl('/' + STORE), { slug: STORE });
  const lines = K.pickSimpleLines(rt, { count: 1, quantity: 1 });
  const itemsJson = JSON.stringify(lines);
  const baseOrder = {
    name: 'E2E Race', 'ordering-type': 'delivery', 'phone-number': '+966500000000',
    address: '1 Race St, Riyadh', pay_via: 'pay_on_counter', items: itemsJson,
  };

  // ── 1. Double-submit dedup (one client_uuid) ────────────────────────────────
  resetOrderThrottle();
  const dupUuid = V.uuid7(); // J27-A2: keys must be UUIDs
  const dupResults = await raceOrders(page, {
    endpoint: rt.submitOrder, csrf: rt.csrf,
    baseFields: Object.assign({}, baseOrder, { client_uuid: dupUuid }),
    perIndex: Array.from({ length: N }, () => ({})),
  });
  const dupSucc = dupResults.filter((r) => r.success);
  if (dupSucc.length !== N) throw new Error(`double-submit: expected all ${N} to return success (deduped), got ${dupSucc.length}: ${JSON.stringify(dupResults)}`);
  const dupRows = V.db('SELECT COUNT(*) FROM {p}orders WHERE client_uuid = ?', [dupUuid]);
  const dupCount = parseInt(dupRows[0][0], 10);
  if (dupCount !== 1) throw new Error(`double-submit LEAK: ${N} parallel submits with one client_uuid created ${dupCount} orders (expected 1)`);
  ok(`double-submit dedup: ${N} parallel submits, one client_uuid → exactly 1 order (rest deduped)`);

  // ── 2. Coupon atomic claim (single-use E2EONCE1) ────────────────────────────
  resetOrderThrottle();
  V.db('UPDATE {p}coupons SET used = 0 WHERE code = ?', [ONCE_COUPON]);
  const cpnMs = Date.now(); // J27-A2: this run's keys share the UUIDv7 time prefix
  const cpnRun = V.uuid7Prefix(cpnMs);
  const couponResults = await raceOrders(page, {
    endpoint: rt.submitOrder, csrf: rt.csrf,
    baseFields: Object.assign({}, baseOrder, { coupon_code: ONCE_COUPON }),
    // distinct client_uuid + phone so neither idempotency nor per-customer limit
    // masks the total-usage race we are testing.
    perIndex: Array.from({ length: N }, (_, i) => ({ client_uuid: V.uuid7(cpnMs), 'phone-number': `+96650000${1000 + i}` })),
  });
  const couponSucc = couponResults.filter((r) => r.success && r.track_url).length;
  if (couponSucc !== N) throw new Error(`coupon race: expected ${N} orders to place, got ${couponSucc}`);
  const usedRow = V.db('SELECT used FROM {p}coupons WHERE code = ? LIMIT 1', [ONCE_COUPON]);
  const used = parseInt(usedRow[0][0], 10);
  // Scope to THIS run's orders (client_uuid prefix) so a prior run's coupon order
  // is not counted.
  const withCoupon = V.db(
    'SELECT COUNT(*) FROM {p}orders o JOIN {p}coupons c ON c.id = o.coupon_id WHERE c.code = ? AND o.client_uuid LIKE ?',
    [ONCE_COUPON, cpnRun + '%'],
  );
  const claimed = parseInt(withCoupon[0][0], 10);
  if (used !== 1) throw new Error(`coupon atomic-claim LEAK: single-use coupon used=${used} after ${N} parallel claims (expected 1)`);
  if (claimed !== 1) throw new Error(`coupon atomic-claim LEAK: ${claimed} orders carry the single-use coupon_id (expected 1)`);
  ok(`coupon atomic claim: ${N} parallel orders, single-use coupon → used=1, exactly 1 order discounted`);

  // ── 3. Stock decrement race (conditional decrement) ─────────────────────────
  resetOrderThrottle();
  const stockItem = lines[0].id;
  V.db('UPDATE {p}items SET track_inventory = 1, stock_qty = ?, is_sold_out = 0 WHERE id = ?', [N, stockItem]);
  const stockResults = await raceOrders(page, {
    endpoint: rt.submitOrder, csrf: rt.csrf,
    baseFields: Object.assign({}, baseOrder, { items: JSON.stringify([{ id: stockItem, quantity: 1 }]) }),
    perIndex: Array.from({ length: N }, (_, i) => ({ client_uuid: V.uuid7(), 'phone-number': `+96650001${1000 + i}` })),
  });
  const stockSucc = stockResults.filter((r) => r.success).length;
  const finalStock = parseInt(V.db('SELECT stock_qty FROM {p}items WHERE id = ? LIMIT 1', [stockItem])[0][0], 10);
  if (stockSucc !== N) throw new Error(`stock race: expected ${N} orders to place, got ${stockSucc}: ${JSON.stringify(stockResults.map((r) => r.message || r.status))}`);
  if (finalStock !== 0) throw new Error(`stock decrement race LEAK: stock started at ${N}, ${N} single-unit orders, final stock_qty=${finalStock} (expected 0 — a lost update leaves it >0)`);
  ok(`stock decrement race: stock ${N} → ${N} parallel single-unit orders → final stock_qty=0 (atomic, no lost update)`);

  // ── 4. J27-A2 client order key: the page mints it, a different cart is a 409 ──
  resetOrderThrottle();
  V.db('UPDATE {p}items SET track_inventory = 0, is_sold_out = 0 WHERE id = ?', [stockItem]);
  const keyName = `E2E Key ${Date.now()}`;
  const keyFields = { name: keyName, 'ordering-type': 'takeaway' };
  // No client_uuid in the body: the shared order-field-forwarder adds its token.
  const k1 = await K.submitOrder(page, { rt, items: lines, fields: keyFields });
  if (!k1.success) throw new Error(`key leg: first order rejected: HTTP ${k1.status} ${JSON.stringify(k1)}`);
  const keyRows = () => V.db('SELECT client_uuid FROM {p}orders WHERE customer_name = ? ORDER BY id', [keyName]).map((r) => r[0]);
  const firstKey = keyRows()[0] || '';
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(firstKey)) {
    throw new Error(`key leg: the storefront token is not a UUIDv7: "${firstKey}"`);
  }
  // The same key resent with ANOTHER cart → 409, no second order, translated message shown.
  const k2 = await K.submitOrder(page, { rt, items: lines.map((l) => ({ id: l.id, quantity: l.quantity + 2 })), fields: Object.assign({ client_uuid: firstKey }, keyFields) });
  if (k2.status !== 409 || !k2.conflict || !k2.message) throw new Error(`key leg: same key + other cart expected 409 conflict, got HTTP ${k2.status} ${JSON.stringify(k2)}`);
  await page.waitForFunction(() => { const n = document.getElementById('mbFbNote'); return n && n.classList.contains('is-open') && n.textContent.trim().length > 0; }, { timeout: 5000 })
    .catch(() => { throw new Error('key leg: the 409 message was not shown in the checkout-feedback banner'); });
  // The forwarder rotated its token after the success: the next order is a new one.
  const k3 = await K.submitOrder(page, { rt, items: lines, fields: keyFields });
  if (!k3.success || k3.duplicate) throw new Error(`key leg: next order after a success should be NEW, got ${JSON.stringify(k3)}`);
  const keys = keyRows();
  if (keys.length !== 2 || keys[0] === keys[1]) throw new Error(`key leg: expected 2 orders with 2 different keys, got ${JSON.stringify(keys)}`);
  ok(`client order key: page-minted UUIDv7, same key + other cart → 409 (banner shown, no order), next order gets a new key`);

  await shot(page, 'a5-concurrency-done');
  log(`A5: concurrency/race — ${checks.length} atomic guarantees verified (dedup, coupon claim, stock decrement, client order key)`);
};
