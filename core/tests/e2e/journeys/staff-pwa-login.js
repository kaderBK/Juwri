// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Journey 3 — staff PWA login reliability (service-worker aware).
 *
 * The staff PWA is served under /staff-app behind the session guard; hitting it
 * unauthenticated redirects to the shared /login form. The documented failure
 * (memory staff-pwa-browser-verify) is a FLAKY login submit when a stale
 * service worker + cache are present. This journey reproduces the fix path:
 *   1. Load the PWA entry (→ /login) and register whatever SW is served.
 *   2. Clear the service worker + all caches (the documented remedy).
 *   3. Submit the login form via the RELIABLE path (click submit +
 *      waitForNavigation), NOT the flaky ref-click, and assert we get through.
 *   4. Confirm the PWA manifest + service-worker assets are actually served
 *      (public pre-login routes) so an install is possible.
 *
 * Credentials: ThemePackDemoSeeder owner. Set E2E_STAFF_* to drive the true
 * staff-guard login instead once staff are seeded.
 */

module.exports = async function staffPwaLogin({ page, cfg, log, shot, sleep, clearServiceWorkers }) {
  // 1) PWA entry → login. This registers any service worker the shell installs.
  await page.goto(`${cfg.baseUrl}/staff-app`, { waitUntil: 'networkidle2' });
  await sleep(500);

  // 4) The manifest + SW must be served (public routes) for an installable PWA.
  const assets = await page.evaluate(async (base) => {
    const out = {};
    for (const [k, url] of [['manifest', `${base}/staff-app/manifest.webmanifest`], ['sw', `${base}/staff-app/sw.js`]]) {
      try { const r = await fetch(url, { credentials: 'include' }); out[k] = r.status; } catch (e) { out[k] = 'err'; }
    }
    return out;
  }, cfg.baseUrl);
  log(`staff-pwa: manifest=${assets.manifest} sw=${assets.sw}`);
  if (assets.manifest >= 400 || assets.sw >= 400) {
    throw new Error(`staff-pwa: PWA assets not served (manifest=${assets.manifest}, sw=${assets.sw})`);
  }

  // 2) Clear SW + caches — the documented remedy for the flaky login submit.
  await clearServiceWorkers(page);
  await shot(page, 'staff-1-login');

  // True staff guard (E2E_STAFF_*): the staff login lives at /staff/login with an
  // email field. Otherwise fall back to the shared owner /login form.
  const trueStaff = !!process.env.E2E_STAFF_USER;
  const staffUser = process.env.E2E_STAFF_USER || cfg.ownerEmail;
  const staffPass = process.env.E2E_STAFF_PASS || cfg.ownerPass;
  const userSel = trueStaff ? 'input[name="email"], #email' : '#username, input[name="username"]';
  const passSel = trueStaff ? 'input[name="password"], #password' : '#password';
  await page.goto(`${cfg.baseUrl}${trueStaff ? '/staff/login' : '/login'}`, { waitUntil: 'networkidle2' });
  await page.waitForSelector(userSel, { timeout: cfg.navTimeout });

  // 3) Reliable submit path.
  await page.type(userSel, staffUser);
  await page.type(passSel, staffPass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  await sleep(800);
  await shot(page, 'staff-2-after-login');

  const landed = await page.evaluate(() => location.pathname);
  if (landed.includes('/login')) {
    throw new Error('staff-pwa: login form did not submit through (still on /login) — the flaky SW path regressed');
  }
  log(`staff-pwa: SW cleared + login submitted reliably → ${landed} OK`);
};
