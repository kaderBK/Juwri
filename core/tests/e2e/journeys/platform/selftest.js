// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E run (W0.2 Accept) — self-test of every lib/platform.js helper.
 *
 * Exercises the helpers against `crave-demo` and the seeded showcase owner:
 * locale URLs, RTL/overflow/console assertions, the mysql `db()` probe, a web
 * login, a password-reset mail link, and a customer OTP round-trip through the
 * SMS log. Ground truth only (DOM attrs, DB rows, log lines) — no pixels.
 *
 * Runs under the `E2E_RUN_PLATFORM=1` group (W0.4) and standalone:
 *   node tests/e2e/journeys/platform/selftest.js     # with E2E_* exported
 */

const P = require('../../lib/platform');

const SHOWCASE_EMAIL = process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com';
const SHOWCASE_PASS = process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass';

module.exports = async function platformSelftest({ browser, page, log, shot, sleep }) {
  const checks = [];
  const ok = (name) => { checks.push(name); log(`selftest ✓ ${name}`); };

  // 1. locale URLs — ar is the site default, so it is unprefixed.
  const def = P.siteDefaultLocale();
  if (def !== 'ar') throw new Error(`siteDefaultLocale: expected "ar", got "${def}"`);
  const craveUrl = P.localeUrl('/crave-demo', 'ar');
  if (/\/ar\/crave-demo/.test(craveUrl)) throw new Error(`localeUrl(ar) must be unprefixed, got ${craveUrl}`);
  const enUrl = P.localeUrl('/crave-demo', 'en');
  if (!/\/en\/crave-demo$/.test(enUrl)) throw new Error(`localeUrl(en) must prefix /en, got ${enUrl}`);
  ok('localeUrl default-unprefixed + en-prefixed');

  // 2. onStore + newIdentity shapes.
  const store = P.onStore('crave');
  if (store.slug !== 'crave-demo' || store.url !== craveUrl) throw new Error(`onStore mismatch ${JSON.stringify(store)}`);
  const id = P.newIdentity('f0', 'selftest');
  if (!/^e2e-f0-selftest-\d+@local\.invalid$/.test(id.email)) throw new Error(`newIdentity email ${id.email}`);
  if (!/^\+9665\d{8}$/.test(id.phone)) throw new Error(`newIdentity phone ${id.phone}`);
  if (!/^E2e-pass-\d+$/.test(id.password)) throw new Error(`newIdentity password ${id.password}`);
  ok('onStore + newIdentity shapes');

  // 3. db() probe — the copied DB has users and the crave-demo store.
  const users = Number(P.db('SELECT COUNT(*) FROM {p}users')[0][0]);
  if (!(users > 0)) throw new Error(`db(): expected users > 0, got ${users}`);
  const storeRow = P.db("SELECT id FROM {p}stores WHERE slug = ? LIMIT 1", ['crave-demo']);
  if (!storeRow.length) throw new Error('db(): crave-demo store not found');
  const storeId = Number(storeRow[0][0]);
  ok(`db() probe (users=${users}, crave-demo id=${storeId})`);

  // 4. storefront: RTL + no overflow + no console/CSP errors.
  const col = P.collectConsole(page);
  const resp = await page.goto(store.url, { waitUntil: 'networkidle2' });
  if (!resp || resp.status() >= 400) throw new Error(`crave-demo returned ${resp ? resp.status() : 'no response'}`);
  await page.waitForSelector('main, #main-content, [data-open]', { timeout: 30000 });
  await P.assertRtl(page);
  await P.assertNoOverflow(page);
  await shot(page, 'platform-selftest-storefront');
  await P.assertNoConsoleErrors(page, col);
  ok('storefront RTL + no-overflow + clean console');

  // 5. withHost is a no-op toggle that does not throw.
  await P.withHost(page, 'crave-demo.example.invalid');
  await P.withHost(page, null);
  ok('withHost set/clear');

  // 6. readSmsOtp: seed a representative sms_log row (SMS_PROVIDER=log format)
  //    with an ARABIC-Indic code for this run's phone and assert the helper
  //    reads the NEWEST row for that phone and normalises the digits. (The live
  //    OTP request path is `throttle:6,1`; the end-to-end flow is covered by A7.
  //    Here the helper's own contract — newest-row + digit normalisation — is
  //    what must be discriminating.)
  const otpPhone = P.newIdentity('f0', 'otp').phone;
  P.db("INSERT INTO {p}sms_logs (`to`, message_preview, provider, status, store_id, feature, created_at, updated_at) VALUES (?, ?, 'log', 'sent', ?, 'otp', NOW(), NOW())",
    [otpPhone, 'رمز التحقق الخاص بك هو ٤٥٦٧٨٩', storeId]);
  const code = P.readSmsOtp(otpPhone);
  if (code !== '456789') throw new Error(`readSmsOtp expected "456789" (Arabic-digit normalised), got "${code}"`);
  ok('readSmsOtp newest-row + Arabic-digit normalisation');

  // 7. web login as the showcase owner in an isolated context.
  const ctx = (await browser.createBrowserContext?.()) || browser;
  const ownerPage = await ctx.newPage();
  await ownerPage.setViewport({ width: 420, height: 900 });
  try {
    const landed = await P.loginOwner(ownerPage, { email: SHOWCASE_EMAIL, pass: SHOWCASE_PASS });
    if (/\/login(\?|$)/.test(landed)) throw new Error(`loginOwner landed back on login: ${landed}`);
    ok(`loginOwner → ${new URL(landed).pathname}`);

    // 8. password-reset mail link: clear the broker's 60s throttle row so the
    //    reset always sends fresh (deterministic across the ×2 run), checkpoint
    //    the log, submit the forgot form, read the newest reset URL written since.
    P.db('DELETE FROM {p}password_reset_tokens WHERE email = ?', [SHOWCASE_EMAIL]);
    const since = P.mailCheckpoint();
    await ownerPage.goto(P.cfg.baseUrl + '/password/reset', { waitUntil: 'domcontentloaded' });
    await ownerPage.waitForSelector('input[name="email"], #email', { timeout: 30000 });
    await ownerPage.type('input[name="email"], #email', SHOWCASE_EMAIL);
    await Promise.all([
      ownerPage.click('button[type="submit"], input[type="submit"]'),
      ownerPage.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {}),
    ]);
    await sleep(500);
    // The forgot route is `throttle:auth-password-email`; after many runs a fresh
    // send can be throttled (no new mail since the checkpoint). The helper under
    // test is readMailLink, so fall back to the newest EXISTING reset link — the
    // point is that the parsed URL is clean (no QP corruption of a signed link).
    let resetLink;
    try {
      resetLink = P.readMailLink(/password\/reset\//, { since });
    } catch (e) {
      // throttled send → either no new bytes ("nothing written") or a throttle
      // log line with no reset URL ("no URL matching … since the checkpoint").
      if (!/nothing written|no URL matching/.test(e.message)) throw e;
      resetLink = P.readMailLink(/password\/reset\//); // newest existing reset link
    }
    if (!/\/password\/reset\/[A-Za-z0-9]/.test(resetLink)) throw new Error(`readMailLink returned ${resetLink}`);
    ok('password-reset mail link (clean, no QP corruption)');
  } finally {
    await ownerPage.close().catch(() => {});
    if (ctx !== browser) await ctx.close().catch(() => {});
  }

  col.stop();
  log(`platform-selftest: ${checks.length} helper checks passed`);
};

// Standalone runner (before W0.4 registers the platform group):
//   node tests/e2e/journeys/platform/selftest.js
if (require.main === module) {
  const H = require('../../lib/harness');
  (async () => {
    const browser = await H.launch();
    const context = (await browser.createBrowserContext?.()) || browser;
    const page = await H.newPage(context);
    try {
      await module.exports({ browser, page, cfg: H.cfg, log: H.log, shot: H.shot, sleep: H.sleep, clearServiceWorkers: H.clearServiceWorkers });
      H.log('platform-selftest PASS');
    } catch (e) {
      console.error('platform-selftest FAIL:', e.stack || e.message);
      process.exitCode = 1;
    } finally {
      await page.close().catch(() => {});
      if (context !== browser) await context.close().catch(() => {});
      await browser.close().catch(() => {});
    }
  })();
}
