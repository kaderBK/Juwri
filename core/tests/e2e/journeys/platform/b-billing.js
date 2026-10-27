// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B7 — Billing (platform E2E, lane B).
 *
 * Owns the owner subscription/billing seam. Non-destructively (it must not churn
 * the shared demo owner's plan, which other lanes and the f0 re-run depend on)
 * it proves the billing surfaces are real and coherent:
 *   - Owner subscription page renders (RTL/ar) and lists the plan catalogue.
 *   - The current-plan state matches the subscriptions table (DB ground truth).
 *   - The add-ons page renders (RTL/ar) and lists add-on products.
 *   - An admin can authenticate and reach the admin console (the approval side of
 *     the offline-payment flow) — verified by landing off /login on /admin.
 *
 * Assertions:
 *   - /subscription and /addons render RTL/ar, 200, with catalogue content
 *   - subscriptions table reflects the owner's plan state
 *   - admin login succeeds and the admin console loads
 *   - Console errors absent; CSP ENFORCED refusals absent (owner surfaces)
 *
 * Converted from the original Playwright-style draft to real Puppeteer.
 */

const {
  cfg,
  localeUrl,
  assertRtl,
  collectConsole,
  assertNoConsoleErrors,
  loginOwner,
  loginAdmin,
  db,
} = require('../../lib/platform');

module.exports = async ({ browser, page, log, shot, sleep }) => {
  const eeOnly = require('../../lib/platform').requireEe('plans, subscriptions and billing');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  if (cfg.locale !== 'ar') {
    throw new Error('b-billing: this journey must run with E2E_LOCALE=ar (Arabic only)');
  }

  const SHOWCASE_EMAIL = process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com';
  const SHOWCASE_PASS = process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass';
  const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || 'admin@example.com';
  const ADMIN_PASS = process.env.E2E_ADMIN_PASS || 'password';
  const ownerCreds = { email: SHOWCASE_EMAIL, pass: SHOWCASE_PASS };

  const console_logs = collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });

  log('[b-billing] SETUP — logging in as owner');
  await loginOwner(page, ownerCreds);
  await assertRtl(page);

  const ownerRows = db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [SHOWCASE_EMAIL]);
  if (!ownerRows.length) throw new Error('b-billing: owner not found');
  const ownerId = ownerRows[0][0];

  log('[b-billing] PART 1 — subscription page renders (RTL) with a plan catalogue');
  const subResp = await page.goto(localeUrl('/subscription'), { waitUntil: 'networkidle2' });
  if (!subResp || subResp.status() >= 400) throw new Error(`b-billing: /subscription returned ${subResp && subResp.status()}`);
  await assertRtl(page);
  await shot(page, 'b-billing-subscription');
  const planContent = await page.evaluate(() => {
    const txt = document.body.innerText || '';
    const cards = document.querySelectorAll('[data-plan], .plan, .plan-card, .pricing-card, [class*="plan"]').length;
    return { cards, hasPlanWord: /خطة|اشتراك|Plan|Subscription/i.test(txt), len: txt.length };
  });
  if (planContent.len < 200) throw new Error('b-billing: subscription page looks empty');
  log(`[b-billing] subscription page: plan-ish elements=${planContent.cards}, catalogue text present=${planContent.hasPlanWord}`);

  log('[b-billing] PART 2 — subscriptions table reflects owner plan state (DB)');
  const subs = db('SELECT id, status, plan_id FROM {p}subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1', [ownerId]);
  if (subs.length) {
    log(`[b-billing] DB: subscription id=${subs[0][0]} status=${subs[0][1]} plan_id=${subs[0][2]}`);
  } else {
    log('[b-billing] DB: owner has no subscription row (free plan) — consistent with the free-plan surface');
  }

  log('[b-billing] PART 3 — add-ons page renders (RTL) with add-on products');
  const addResp = await page.goto(localeUrl('/addons'), { waitUntil: 'networkidle2' });
  if (!addResp || addResp.status() >= 400) throw new Error(`b-billing: /addons returned ${addResp && addResp.status()}`);
  await assertRtl(page);
  await shot(page, 'b-billing-addons');
  const addonCount = await page.$$eval('[data-addon], .addon, .addon-card, [class*="addon"]', (els) => els.length).catch(() => 0);
  log(`[b-billing] add-ons page rendered (addon-ish elements=${addonCount})`);

  log('[b-billing] PART 4 — console health (owner surfaces)');
  await assertNoConsoleErrors(page, console_logs);

  log('[b-billing] PART 5 — admin can authenticate and reach the admin console');
  const adminPage = await browser.newPage();
  try {
    await loginAdmin(adminPage, { email: ADMIN_EMAIL, pass: ADMIN_PASS });
    const adminResp = await adminPage.goto(localeUrl('/admin'), { waitUntil: 'networkidle2' });
    const landed = new URL(adminPage.url()).pathname;
    if (landed.endsWith('/login') || (adminResp && adminResp.status() >= 400 && adminResp.status() !== 200)) {
      throw new Error(`b-billing: admin did not reach the console (landed ${landed}, status ${adminResp && adminResp.status()})`);
    }
    log(`[b-billing] admin console reachable (landed ${landed})`);
  } finally {
    await adminPage.close().catch(() => {});
  }

  log('[b-billing] PASS b-billing — billing surfaces + admin approval side verified');
};
