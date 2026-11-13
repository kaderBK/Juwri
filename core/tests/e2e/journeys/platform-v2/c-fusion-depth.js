// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — C3: Marketplace / Fusion depth in en AND ar (P1).
 *
 * v1 (fusion-*) proved the tenancy fence + reseller console in ARABIC. v2 adds
 * ENGLISH/LTR parity of the shopper-facing marketplace and re-asserts the fence
 * in BOTH locales, against ground truth (store NAME on the page, HTTP status,
 * <html> dir). The reseller Fusion host is a resolvable lane subdomain (Herd
 * serves *.e2e-c.test, TrustHosts trusts every subdomain of the app URL) — the
 * passing v1 c-fusion host model, NOT a forbidden Host header.
 *
 * For each locale (ar = default RTL; en via the marketplace session switch
 * /fusion/lang/en → LTR):
 *   1. LISTING/HOME — /fusion renders 200 with the correct dir.
 *   2. STORE — /fusion/store/fusion-demo-a1 renders 200, correct dir, and shows
 *      the store's real name ("Alpha Grill") — content parity, not just a 200.
 *   3. CART — /fusion/bag renders 200 (the multi-store bag surface).
 *   4. TENANT FENCE — reseller B's store (fusion-demo-b1) and the platform store
 *      404 on reseller A's host, in this locale too.
 *
 *   E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh c c-fusion-depth
 */

const V = require('../../lib/platform-v2');

const A1 = { slug: 'fusion-demo-a1', name: 'Alpha Grill' };
const B1 = 'fusion-demo-b1';               // reseller B — must 404 on host A
const PLATFORM = 'fusion-demo-platform';   // platform store — must 404 on host A

module.exports = async function cFusionDepth({ page, cfg, log, shot }) {
  const eeOnly = V.requireEe('the Fusion marketplace');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  // Repoint the demo Fusion reseller A onto a fresh resolvable lane subdomain.
  const baseHost = new URL(cfg.baseUrl).hostname;  // e2e-c.test (no port: the stored custom domain)
  const idA = Number(V.db("SELECT r.id FROM {p}resellers r JOIN {p}users u ON u.id = r.owner_user_id WHERE u.email = ? LIMIT 1", ['fusiondemo-a@example.test'])[0][0]);
  if (!idA) throw new Error('c-fusion-depth: fusion demo reseller A not found — run FusionResellerDemoSeeder');
  const host = `fusiondepth-${Date.now()}.${baseHost}`;
  V.db('UPDATE {p}resellers SET custom_domain = ?, active = 1 WHERE id = ?', [host, idA]);
  const base = `${new URL(cfg.baseUrl).protocol}//${host}${new URL(cfg.baseUrl).port ? ':' + new URL(cfg.baseUrl).port : ''}`;
  log(`fusion reseller A (id=${idA}) → ${host}`);

  await page.setViewport({ width: 390, height: 844 });

  const status = async (url) => {
    // networkidle2, not domcontentloaded: the file session has no locking, so a late request of the previous page (manifest, XHR)
    // that still holds the old session can save it AFTER /lang/en wrote mk_locale and put the shopper back on Arabic. Let the page settle first.
    const r = await page.goto(url, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    return r ? r.status() : 0;
  };

  for (const locale of ['ar', 'en']) {
    const isEn = locale === 'en';
    if (isEn) {
      // setLocale sets session mk_locale then back()s to the referer — land on a
      // clean 200 (/fusion) first so back() doesn't bounce to a prior 404 page.
      await status(`${base}/fusion`);
      await status(`${base}/fusion/lang/en`);              // 302 back → /fusion, now en
      if ((await page.evaluate(() => document.documentElement.dir)) === 'rtl') await status(`${base}/fusion/lang/en`); // lost-update guard, see status()
    }
    const assertDir = isEn ? V.assertLtr : V.assertRtl;

    // 1. HOME / listing
    const home = await status(`${base}/fusion`);
    if (home !== 200) throw new Error(`[${locale}] /fusion home → ${home}`);
    await assertDir(page);
    await shot(page, `c-fusion-depth-home-${locale}`);

    // 2. STORE — name parity
    const st = await status(`${base}/fusion/store/${A1.slug}`);
    if (st !== 200) throw new Error(`[${locale}] store ${A1.slug} → ${st}`);
    await assertDir(page);
    const hasName = await page.evaluate((n) => document.documentElement.innerHTML.includes(n), A1.name);
    if (!hasName) throw new Error(`[${locale}] store page did not render the store name "${A1.name}"`);

    // 3. CART / bag
    const bag = await status(`${base}/fusion/bag`);
    if (bag !== 200) throw new Error(`[${locale}] /fusion/bag → ${bag}`);
    await assertDir(page);

    // 4. TENANT FENCE — B's store + platform store 404 on host A
    const bStatus = await status(`${base}/fusion/store/${B1}`);
    if (bStatus !== 404) throw new Error(`[${locale}] TENANT LEAK: reseller B store ${B1} → ${bStatus} on host A (expected 404)`);
    const pStatus = await status(`${base}/fusion/store/${PLATFORM}`);
    if (pStatus !== 404) throw new Error(`[${locale}] platform store ${PLATFORM} → ${pStatus} on host A (expected 404)`);

    log(`[${locale}] home 200 (${isEn ? 'LTR' : 'RTL'}); store "${A1.name}" 200; bag 200; fence B1→404 platform→404 ✓`);
  }

  log(`c-fusion-depth PASS — marketplace home+store(name)+bag render in ar (RTL) AND en (LTR); tenant fence holds in both locales (reseller A id=${idA} host=${host})`);
};
