// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — C9: English / LTR parity for reseller portal + admin core.
 *
 * v1 proved these panels in ARABIC (RTL). v2 proves the SAME surfaces render in
 * English left-to-right (assertLtr: `<html lang="en">`, dir≠rtl, 200).
 *
 * The two panels reach English DIFFERENTLY (discovered by probing this app, not
 * assumed):
 *   - The RESELLER panel is OUTSIDE the URL-prefixed localized group; it honours
 *     the SESSION locale (custom Locale middleware). So we flip the session to en
 *     once (GET /en/ — a route inside the localized group) and then the
 *     UN-prefixed /reseller* pages render en. (/en/reseller is a 404.)
 *   - The ADMIN panel IS in the URL-prefixed group, so /en/admin/* renders en
 *     directly (un-prefixed /admin/* stays the ar default).
 *
 *   E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh c c-en-parity
 */

const V = require('../../lib/platform-v2');
const { provisionReseller } = require('../../lib/reseller-fixtures');

async function ltr(page, cfg, url, label) {
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!resp || resp.status() !== 200) throw new Error(`${label} (${url}) → ${resp ? resp.status() : 'no response'}`);
  return V.assertLtr(page);
}

module.exports = async function cEnParity({ page, cfg, log, shot }) {
  const eeOnly = V.requireEe('the Reseller portal (English parity)');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const A = provisionReseller(V, { tag: 'a' });
  await page.setViewport({ width: 1024, height: 800 });

  // ── Reseller portal core in English (session locale, un-prefixed URLs) ─────
  await V.webLogin(page, { email: A.ownerEmail, pass: A.ownerPass });
  await page.goto(V.enUrl('/'), { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });   // flip session → en
  const portal = await ltr(page, cfg, cfg.baseUrl + '/reseller', 'reseller portal');
  const brandPresent = await page.evaluate((b) => document.documentElement.innerHTML.includes(b), A.brandName);
  if (!brandPresent) throw new Error('reseller brand did not resolve on the en portal');
  await shot(page, 'c-en-parity-reseller-portal');
  log(`reseller portal en: 200 lang="${portal.lang}" dir="${portal.dir || 'unset'}" brand present ✓`);
  for (const [rel, label] of [['/reseller/customers', 'reseller customers'], ['/reseller/invoices', 'reseller invoices']]) {
    const s = await ltr(page, cfg, cfg.baseUrl + rel, label);
    log(`${label} en: 200 lang="${s.lang}" dir="${s.dir || 'unset'}" ✓`);
  }

  // ── Admin core in English (URL-prefixed /en/admin/*) ───────────────────────
  const adminEmail = process.env.E2E_ADMIN_EMAIL;
  const adminPass = process.env.E2E_ADMIN_PASS;
  if (!adminEmail || !adminPass) throw new Error('c-en-parity: E2E_ADMIN_EMAIL/PASS not exported');
  await V.loginAdmin(page, { email: adminEmail, pass: adminPass });
  for (const [rel, label] of [['/admin/users', 'admin users'], ['/admin/resellers', 'admin resellers']]) {
    const s = await ltr(page, cfg, V.enUrl(rel), label);
    log(`${label} en: 200 lang="${s.lang}" dir="${s.dir || 'unset'}" ✓`);
  }
  await shot(page, 'c-en-parity-admin-users');

  log(`c-en-parity PASS — reseller portal (portal/customers/invoices, session-locale) + admin core (users/resellers, /en-prefixed) render English LTR (lang=en, dir≠rtl), reseller brand resolves in en (reseller ${A.resellerId})`);
};
