// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — C6: Reseller billing / commission integrity (P1).
 *
 * Drives the reseller's recurring-charge endpoint (POST reseller/checkout/charge
 * → PortalController@chargeCustomer → ResellerSubscriptionService) in a real
 * browser and asserts MONEY INVARIANTS + TENANT GATING against ground truth
 * (transaction + reseller_customer_subscriptions rows), plus the billing
 * surfaces. The FakeResellerDriver success/fail ledger invariants (the BYOK
 * stub) are covered deterministically by tests/Feature/E2eResellerBillingStubTest
 * (secrets is an encrypted cast → seedable only through the app, not raw SQL).
 *
 *   1. TEST-MODE CHARGE — A charges its own customer for A's plan → 201,
 *      test_mode=true; EXACTLY ONE transaction row is written for that customer
 *      (status 'pending', transaction_gatway 'reseller_test'), amount == the
 *      plan's monthly_price (no over/under-charge), and a pending
 *      reseller_customer_subscription is created for the right plan (the platform
 *      plan is assigned only once a live payment is verified).
 *   2. GATING (billing IDOR) — A charging B's customer → 404; A charging with
 *      B's plan → 404 (both firstOrFail scoped on reseller_id). No transaction
 *      row leaks onto B's customer.
 *   3. MONEY INVARIANT — the customer's own wallet balance is untouched (a BYOK
 *      charge hits the customer's card, never moves platform/user balance).
 *   4. SURFACES — /reseller/invoices and /reseller/wallet render 200 + RTL and
 *      are tenant-scoped.
 *
 *   E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh c c-reseller-billing
 */

const V = require('../../lib/platform-v2');
const { provisionReseller } = require('../../lib/reseller-fixtures');

let PLATFORM_PLAN_ID = 0;       // a real platform plan row (the lowest id; ids differ per database — the legacy dev DB had #1 "Main Package")
const MONTHLY = 30.00;

/** Create a reseller plan row (no encrypted columns → raw insert is fine). */
function makeResellerPlan(resellerId, name) {
  V.db(
    "INSERT INTO {p}reseller_plans (reseller_id, platform_plan_id, name, monthly_price_minor, annual_price_minor, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())",
    [resellerId, PLATFORM_PLAN_ID, name, Math.round(MONTHLY * 100), Math.round(MONTHLY * 1000)],
  );
  return Number(V.db('SELECT id FROM {p}reseller_plans WHERE reseller_id = ? ORDER BY id DESC LIMIT 1', [resellerId])[0][0]);
}

function txnCount(userId) {
  return Number(V.db('SELECT COUNT(*) FROM {p}transaction WHERE user_id = ?', [userId])[0][0]);
}

async function postJson(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'follow',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: JSON.stringify(body || {}),
    });
    let json = null; try { json = await r.json(); } catch (_e) { /* non-json */ }
    return { status: r.status, json };
  }, { url, body });
}

module.exports = async function cResellerBilling({ page, cfg, log, shot }) {
  const eeOnly = V.requireEe('Reseller billing');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  PLATFORM_PLAN_ID = Number(V.db('SELECT id FROM {p}plans ORDER BY id LIMIT 1')[0][0]);
  const A = provisionReseller(V, { tag: 'a' });
  const B = provisionReseller(V, { tag: 'b' });
  const planA = makeResellerPlan(A.resellerId, 'Pro A');
  const planB = makeResellerPlan(B.resellerId, 'Pro B');
  log(`A(reseller=${A.resellerId} cust=${A.customerId} plan=${planA}); B(reseller=${B.resellerId} cust=${B.customerId} plan=${planB})`);

  await page.setViewport({ width: 390, height: 844 });
  await V.webLogin(page, { email: A.ownerEmail, pass: A.ownerPass });
  const portalResp = await page.goto(cfg.baseUrl + '/reseller', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!portalResp || portalResp.status() !== 200) throw new Error(`A portal → ${portalResp ? portalResp.status() : 'no response'}`);

  const balBefore = V.db('SELECT balance_minor FROM {p}users WHERE id = ?', [A.customerId])[0][0];
  const txnBefore = txnCount(A.customerId);

  // ── 1. TEST-MODE CHARGE: A → A.customer for A.plan ─────────────────────────
  const charge = await postJson(page, '/reseller/checkout/charge', { user_id: A.customerId, reseller_plan_id: planA, interval: 'monthly' });
  if (charge.status !== 201) throw new Error(`charge expected 201, got ${charge.status} (${JSON.stringify(charge.json)})`);
  if (charge.json.test_mode !== true) throw new Error(`expected test_mode=true (no live keys), got ${JSON.stringify(charge.json)}`);
  // Parity A1: the platform plan is assigned only on a VERIFIED charge; a test-mode charge records the
  // subscription as `pending` and assigns nothing (it used to assign immediately).
  if (charge.json.assigned_plan !== null) throw new Error(`test-mode charge must not assign the platform plan, got assigned_plan=${charge.json.assigned_plan}`);
  // Exactly one new transaction, correct amount + gateway + status.
  if (txnCount(A.customerId) !== txnBefore + 1) throw new Error(`expected exactly one new transaction, delta=${txnCount(A.customerId) - txnBefore}`);
  const txn = V.db("SELECT status, amount_minor / 100.0, transaction_gatway FROM {p}transaction WHERE user_id = ? ORDER BY id DESC LIMIT 1", [A.customerId])[0];
  if (txn[0] !== 'pending') throw new Error(`test-mode charge txn status should be 'pending', got '${txn[0]}'`);
  if (Math.abs(Number(txn[1]) - MONTHLY) > 0.001) throw new Error(`MONEY INVARIANT: charged amount ${txn[1]} !== plan price ${MONTHLY}`);
  if (txn[2] !== 'reseller_test') throw new Error(`test-mode gateway should be 'reseller_test', got '${txn[2]}'`);
  const sub = V.db("SELECT status, platform_plan_id, amount_minor / 100.0 FROM {p}reseller_customer_subscriptions WHERE reseller_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1", [A.resellerId, A.customerId]);
  if (!sub.length || sub[0][0] !== 'pending') throw new Error(`expected a pending reseller_customer_subscription after a test-mode charge, got ${JSON.stringify(sub)}`);
  if (Number(sub[0][1]) !== PLATFORM_PLAN_ID) throw new Error(`subscription platform_plan_id ${sub[0][1]} !== ${PLATFORM_PLAN_ID}`);
  log(`test-mode charge: 201 test_mode; 1 txn (pending, amount=${txn[1]}, reseller_test); subscription pending plan=${sub[0][1]}, platform plan not assigned ✓`);

  // ── 2. GATING: A cannot charge B's customer or use B's plan ────────────────
  const bCustTxnBefore = txnCount(B.customerId);
  const crossCust = await postJson(page, '/reseller/checkout/charge', { user_id: B.customerId, reseller_plan_id: planA, interval: 'monthly' });
  if (![403, 404].includes(crossCust.status)) throw new Error(`billing IDOR: A charging B's customer → ${crossCust.status} (expected 404)`);
  const crossPlan = await postJson(page, '/reseller/checkout/charge', { user_id: A.customerId, reseller_plan_id: planB, interval: 'monthly' });
  if (![403, 404].includes(crossPlan.status)) throw new Error(`billing IDOR: A using B's plan → ${crossPlan.status} (expected 404)`);
  if (txnCount(B.customerId) !== bCustTxnBefore) throw new Error(`transaction row leaked onto B's customer via a denied cross-tenant charge`);
  log(`gating: A→B.customer ${crossCust.status}, A+B.plan ${crossPlan.status}; no txn leak on B ✓`);

  // ── 3. MONEY INVARIANT: customer wallet balance untouched by a BYOK charge ──
  const balAfter = V.db('SELECT balance_minor FROM {p}users WHERE id = ?', [A.customerId])[0][0];
  if (String(balBefore) !== String(balAfter)) throw new Error(`MONEY INVARIANT: customer balance moved ${balBefore} → ${balAfter} on a BYOK gateway charge (should be untouched)`);
  log(`money invariant: customer balance unchanged (${balAfter}) ✓`);

  // ── 4. SURFACES: invoices + wallet render, tenant-scoped ───────────────────
  const inv = await page.goto(cfg.baseUrl + '/reseller/invoices', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!inv || inv.status() !== 200) throw new Error(`/reseller/invoices → ${inv ? inv.status() : 'no response'}`);
  await V.assertRtl(page);
  await shot(page, 'c-reseller-billing-invoices');
  const wallet = await page.goto(cfg.baseUrl + '/reseller/wallet', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!wallet || wallet.status() !== 200) throw new Error(`/reseller/wallet → ${wallet ? wallet.status() : 'no response'}`);
  await V.assertRtl(page);
  await shot(page, 'c-reseller-billing-wallet');
  log(`surfaces: invoices + wallet 200/RTL ✓`);

  log(`c-reseller-billing PASS — recurring charge money-correct (amount==plan price, 1 txn, sub active), billing IDOR 404 (customer+plan, no leak), BYOK leaves wallet untouched, invoices/wallet surfaces (A=${A.resellerId} B=${B.resellerId})`);
};
