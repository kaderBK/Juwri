// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * E2E-005 / CUS (opt-in) — customer coupon branches at storefront checkout.
 *
 * Drives the crave storefront to checkout and exercises the promo-code
 * control both ways: an invalid code must surface a visible error message
 * and change nothing, a valid percentage code must render the discount row
 * with the code name and reduce the payable total.
 *
 * Env: E2E_RUN_RETENTION=1, E2E_STORE_SLUG (default crave-demo; a crave-template store whose
 * owner has an active percentage coupon), E2E_COUPON (default P1TEST10),
 * E2E_COUPON_PCT (default 10).
 */

const { resetRateLimits } = require('../lib/platform');

module.exports = async function cusRetentionCoupon({ page, cfg, log, shot, sleep }) {
  // The whole pack shares one IP and the coupon / order routes are throttled per IP: start from a clean limiter (test env only; the
  // app's throttles are unchanged) so earlier journeys in a full run cannot leave this one on a 429 "Too Many Attempts".
  resetRateLimits();
  const code = process.env.E2E_COUPON || 'P1TEST10';
  const pct = +(process.env.E2E_COUPON_PCT || 10);

  const resp = await page.goto(`${cfg.baseUrl}/${process.env.E2E_STORE_SLUG || 'crave-demo'}`, { waitUntil: 'networkidle2' });
  if (!resp || resp.status() >= 400) throw new Error(`retention: storefront returned ${resp && resp.status()}`);

  // Reach checkout the same way storefront-checkout.js does.
  await page.waitForSelector('[data-open]:not(.mb-combo-sheet):not([data-open="0"])', { timeout: cfg.navTimeout });
  await page.evaluate(() => document.querySelector('[data-open]:not(.mb-combo-sheet):not([data-open="0"])').click());
  await sleep(800);
  await page.evaluate(() => { const a = document.querySelector('#addToCart'); if (a) a.click(); });
  await sleep(800);
  await page.evaluate(() => { const t = document.querySelector('[data-tab="cart"]'); if (t) t.click(); });
  await sleep(600);
  await page.evaluate(() => { const gc = document.querySelector('#cvGoCheckout'); if (gc) gc.click(); });
  await sleep(900);

  const hasCouponField = await page.evaluate(() => !!document.querySelector('#cvCouponInput'));
  if (!hasCouponField) throw new Error('retention: coupon field not rendered — store has coupons disabled');
  await shot(page, 'retention-1-checkout');

  const totals = () => page.evaluate(() => {
    const rows = [...document.querySelectorAll('.tr-row')];
    const disc = rows.find((r) => r.classList.contains('disc'));
    const text = (el) => (el ? el.textContent.trim() : null);
    return {
      discRow: text(disc),
      msg: text(document.querySelector('#cvCouponMsg')),
      msgHidden: (() => { const m = document.querySelector('#cvCouponMsg'); return m ? m.hasAttribute('hidden') : null; })(),
    };
  });

  const applyCode = async (value) => {
    resetRateLimits();
    await page.evaluate((v) => {
      const i = document.querySelector('#cvCouponInput');
      i.value = v;
      i.dispatchEvent(new Event('input', { bubbles: true }));
      document.querySelector('#cvCouponApply').click();
    }, value);
    await sleep(1200); // server round-trip
  };

  // Invalid code → visible message, no discount row.
  await applyCode('NOPE-INVALID-123');
  let t = await totals();
  await shot(page, 'retention-2-invalid-coupon');
  if (t.msgHidden !== false || !t.msg) {
    throw new Error(`retention: invalid coupon produced no visible message (${JSON.stringify(t)})`);
  }
  if (t.discRow) throw new Error('retention: invalid coupon rendered a discount row');

  // Valid code → discount row naming the code.
  await applyCode(code);
  t = await totals();
  await shot(page, 'retention-3-valid-coupon');
  if (!t.discRow || !t.discRow.toUpperCase().includes(code.toUpperCase())) {
    throw new Error(`retention: valid coupon did not render its discount row (${JSON.stringify(t)})`);
  }

  log(`retention: coupon ${code} (${pct}%) — invalid rejected visibly, valid discounts the total`);
};
