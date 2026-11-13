// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B12: English / LTR owner-dashboard parity.
 *
 * v1 exercised the owner dashboard only in Arabic (RTL). B12 proves the SAME
 * core owner surfaces render in English left-to-right with a clean console:
 * login (en) → dashboard home → orders → own-store menu → analytics → customers
 * → settings. Each must be 200, `<html lang="en">` and NOT dir="rtl" (assertLtr),
 * and free of console errors.
 *
 * Owner: showcase-demo@example.com (owns crave-demo crave-demo). Run with
 *   E2E_LOCALE=en E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh b b-dashboard-en
 */

const V = require('../../lib/platform-v2');

const OWNER = { email: 'showcase-demo@example.com', pass: 'showcase-demo-pass' };
let OWN_STORE = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)

module.exports = async function b12DashboardEn({ page, log }) {
  OWN_STORE = V.craveStoreId();
  // Login (auth is locale-agnostic), then drive the English dashboard.
  await V.loginOwner(page, OWNER);

  const pages = [
    { label: 'dashboard', path: '/dashboard' },
    { label: 'orders',    path: '/orders' },
    { label: 'menu',      path: `/stores/${OWN_STORE}/menu` },
    { label: 'analytics', path: '/analytics' },
    { label: 'customers', path: '/customers' },
    { label: 'settings',  path: '/settings' },
  ];

  // Distinguish a REAL asset break (script/style/document/xhr 404) from benign
  // missing images (dev DBs ship without every menu photo/avatar). We fail on the
  // former and record the latter. `console.error: Failed to load resource` has no
  // URL in its text, so ignore that generic line and judge from the response feed.
  for (const p of pages) {
    const url = V.enUrl(p.path);
    const col = V.collectConsole(page, { ignore: [/Failed to load resource/] });
    const badAssets = [];
    const benignImg = [];
    const onResp = (r) => {
      if (r.status() !== 404) return;
      const u = r.url();
      const type = r.request().resourceType();
      // Benign in a seeded dev DB: missing menu photos/avatars/fonts, and the
      // static dashboard search index (/assets/ajax/*.json) which ships as a file
      // and 404s identically in ar+en (so it is NOT an English-parity defect).
      if (['image', 'font', 'media'].includes(type)
        || /\.(png|jpe?g|gif|webp|svg|ico|woff2?)(\?|$)/i.test(u)
        || /\/assets\/ajax\/[^?]*\.json(\?|$)/i.test(u)) {
        benignImg.push(u);
      } else {
        badAssets.push(`${type} ${u}`);
      }
    };
    page.on('response', onResp);
    const resp = await page.goto(url, { waitUntil: 'networkidle2' });
    const status = resp ? resp.status() : 0;
    page.off('response', onResp);
    if (status < 200 || status >= 400) {
      throw new Error(`B12 ${p.label}: ${url} → ${status}`);
    }
    await page.waitForSelector('main, #main-content, .dashboard, body', { timeout: V.cfg.navTimeout });
    const shell = await V.assertLtr(page);
    await V.assertNoConsoleErrors(page, col); // pageerror/CSP-refusal still fail
    col.stop();
    if (badAssets.length) {
      throw new Error(`B12 ${p.label}: real asset 404(s):\n    ${badAssets.join('\n    ')}`);
    }
    log(`B12 en/LTR: ${p.label} ${url.replace(V.cfg.baseUrl, '')} → ${status} lang="${shell.lang}" dir="${shell.dir || 'unset'}" LTR ok${benignImg.length ? ` (${benignImg.length} missing img ignored)` : ''}`);
  }

  log(`B12 English/LTR dashboard parity: ${pages.length} owner surfaces render en/LTR, clean console`);
};
