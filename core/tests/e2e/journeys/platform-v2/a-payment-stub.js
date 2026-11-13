// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A4 — Payment gateway STUB at checkout (Platform E2E v2, Lane A).
 *
 * Drives the fake `stub` gateway (V0.2: PaymentMethods/StubController, armed only
 * by E2E_STUBS in local/testing + a seeded `stub` payment_gateways row) end to
 * end at checkout — NO real gateway, keys, or charge — and asserts the order +
 * transaction ledger reflects each outcome:
 *   • success → the transaction settles through the REAL paySuccess() path: order
 *     is_paid=1, transaction consumed, gateway stamped `stub`.
 *   • fail    → nothing settles: order stays unpaid, transaction pending, and the
 *     customer is bounced back (declined branch).
 *
 * The gateway is reached exactly as a real one is: send-order with pay_via=
 * pay_online mints a Transaction and returns the payment URL; POST
 * payment/{txn}/pay?payment_method=stub&e2e_outcome=… settles it. The partial-
 * refund ledger invariant on a stub-settled order is pinned by
 * tests/Modules/Payments/E2eV2A4PaymentStubTest.php (PosService::refund).
 *
 * Run: `E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh a a-payment-stub`
 */

const V = require('../../lib/platform-v2');
const K = require('../../lib/order-kit-v2');

const STORE = 'crave-demo';

module.exports = async function a4PaymentStub({ page, log, shot }) {
  V.resetRateLimits(); // one IP places every order of the pack: start each journey with a clean 10/min window
  const checks = [];
  const ok = (name) => { checks.push(name); log(`  a4 ✓ ${name}`); };

  if (!V.stubsArmed()) throw new Error('E2E_STUBS not armed — the stub gateway is inert (run via the v2 bin, and add E2E_STUBS=1 to core/.env)');
  if (!V.stubGatewayInstalled(0)) throw new Error('stub payment_gateways row missing — run E2ePlatformV2Seeder');
  ok('stub gateway armed + installed');

  const rt = await K.loadRuntime(page, V.enUrl('/' + STORE), { slug: STORE });

  // Place an online-payment order → mints a Transaction, returns its payment URL.
  const place = async (uuidTag) => {
    const res = await K.submitOrder(page, {
      rt,
      items: K.pickSimpleLines(rt, { count: 1, quantity: 1 }),
      fields: { name: 'E2E Pay Diner', pay_via: 'pay_online', client_uuid: V.uuid7() /* J27-A2: was e2e-a4-${uuidTag}-<ms> */ },
    });
    if (!res.success) throw new Error(`pay_online send-order rejected: ${JSON.stringify(res)}`);
    const token = K.trackToken(res.track_url);
    const txnId = (String(res.message || '').match(/\/payment\/(\d+)/) || [])[1];
    if (!txnId) throw new Error(`no payment transaction URL in send-order response (${JSON.stringify(res.message)})`);
    const orow = V.db('SELECT id, is_paid FROM {p}orders WHERE track_token = ? LIMIT 1', [token]);
    return { token, txnId: parseInt(txnId, 10), orderId: parseInt(orow[0][0], 10), isPaid0: orow[0][1] };
  };

  const payStub = (txnId, outcome) => page.evaluate(async ({ txnId, outcome, csrf }) => {
    const fd = new FormData();
    fd.append('payment_method', 'stub');
    fd.append('e2e_outcome', outcome);
    const r = await fetch(`/payment/${txnId}/pay`, {
      method: 'POST', credentials: 'same-origin', redirect: 'follow',
      headers: Object.assign({ Accept: 'text/html,application/json' }, csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
      body: fd,
    });
    const body = await r.text().catch(() => '');
    return { status: r.status, url: r.url, body: body.replace(/\s+/g, ' ').slice(0, 240) };
  }, { txnId, outcome, csrf: rt.csrf });

  // ── SUCCESS ────────────────────────────────────────────────────────────────
  const s = await place('ok');
  if (String(s.isPaid0) === '1') throw new Error('order was paid before the gateway ran');
  ok(`online order #${s.orderId} placed unpaid, txn #${s.txnId} pending`);

  const sPay = await payStub(s.txnId, 'success');
  if (sPay.status >= 400) throw new Error(`stub success pay returned ${sPay.status}`);
  const sAfter = V.db(
    'SELECT o.is_paid, t.status, t.transaction_gatway FROM {p}orders o JOIN {p}transaction t ON t.product_id = o.id '
    + 'WHERE o.id = ? ORDER BY t.id DESC LIMIT 1', [s.orderId],
  );
  const [sPaid, sTxnStatus, sGw] = sAfter[0];
  if (String(sPaid) !== '1') throw new Error(`success: order #${s.orderId} not paid (is_paid=${sPaid}, txn status=${sTxnStatus}, gateway=${sGw}; pay -> ${sPay.status} ${sPay.url} ${sPay.body})`);
  if (sGw !== 'stub') throw new Error(`success: gateway not stamped stub (got ${sGw}) — must be the fake gateway, no real charge`);
  if (sTxnStatus !== 'consumed') throw new Error(`success: transaction status ${sTxnStatus}, expected consumed`);
  ok(`SUCCESS settled via stub: order #${s.orderId} is_paid=1, txn consumed, gateway=stub (no real charge)`);
  await shot(page, 'a4-stub-success');

  // ── FAILURE ────────────────────────────────────────────────────────────────
  const f = await place('fail');
  const fPay = await payStub(f.txnId, 'fail');
  if (fPay.status >= 500) throw new Error(`stub fail pay returned ${fPay.status}`);
  const fAfter = V.db(
    'SELECT o.is_paid, t.status, t.transaction_gatway FROM {p}orders o JOIN {p}transaction t ON t.product_id = o.id '
    + 'WHERE o.id = ? ORDER BY t.id DESC LIMIT 1', [f.orderId],
  );
  const [fPaid, fTxnStatus, fGw] = fAfter[0];
  if (String(fPaid) === '1') throw new Error(`fail: order #${f.orderId} must stay UNPAID but is_paid=${fPaid}`);
  if (fTxnStatus === 'consumed') throw new Error(`fail: transaction must NOT be consumed (got ${fTxnStatus})`);
  ok(`FAILURE declined via stub: order #${f.orderId} stays unpaid (is_paid=${fPaid}), txn ${fTxnStatus}, gateway=${fGw || 'none'}`);
  await shot(page, 'a4-stub-fail');

  log(`A4: payment gateway stub — ${checks.length} checks; success settles + fail declines, both via the fake gateway, zero real charge`);
};
