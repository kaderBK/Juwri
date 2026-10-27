// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — C1: Reseller IDOR / tenant isolation (SECURITY, P0).
 *
 * v1 proved a reseller can reach its OWN portal on its OWN host. v2 proves the
 * FENCE: reseller A can never read, list, or resolve reseller B's resources.
 * Two fresh, independent, active resellers are provisioned on resolvable lane
 * subdomains (same host model as the passing v1 c-reseller/c-fusion — NO
 * forbidden Host header), each with its own scoped customer. Then, logged in as
 * reseller A:
 *
 *   1. POSITIVE control — A reads its OWN customer detail → 200 (endpoint works,
 *      session authed). Without this a 404 could be "broken", not "fenced".
 *   2. IDOR — A reads reseller B's customer detail (route + expectsJson) → 404
 *      (PortalController scopes every customer endpoint on reseller_id +
 *      firstOrFail). A 200 here is a cross-tenant LEAK (throws).
 *   3. Sub-owner scoping — GET /reseller/customers lists ONLY A's customer, and
 *      B's customer email is ABSENT from A's list.
 *   4. Guessed/adjacent ids — the id immediately around B's customer is also
 *      denied (no off-by-one reach into another tenant).
 *   5. HOST FENCE — reseller A's brand resolves on host A and is ABSENT on host
 *      B; reseller B's brand resolves on host B and is ABSENT on host A (proves
 *      per-host resolution, not a global string).
 *
 * Ground truth only: HTTP status, DB rows, DOM brand strings. Runs ar (default).
 *   E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh c c-idor
 */

const V = require('../../lib/platform-v2');
const { provisionReseller } = require('../../lib/reseller-fixtures');

module.exports = async function cIdor({ browser, page, cfg, log, shot }) {
  const eeOnly = V.requireEe('Reseller (tenant fence)');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const A = provisionReseller(V, { tag: 'a' });
  const B = provisionReseller(V, { tag: 'b' });
  log(`provisioned A(reseller=${A.resellerId} owner=${A.ownerId} cust=${A.customerId} host=${A.host})`);
  log(`provisioned B(reseller=${B.resellerId} owner=${B.ownerId} cust=${B.customerId} host=${B.host})`);
  if (A.resellerId === B.resellerId) throw new Error('provisioned resellers collided');

  await page.setViewport({ width: 390, height: 844 });

  // ── 1. POSITIVE: A logs in, portal 200, reads its OWN customer detail 200 ──
  await V.webLogin(page, { email: A.ownerEmail, pass: A.ownerPass });
  const portal = await V.probe(page, '/reseller');
  if (portal.status !== 200) throw new Error(`reseller A portal expected 200, got ${portal.status} (final ${portal.finalUrl})`);
  const ownDetail = await V.probe(page, `/reseller/customers/${A.customerId}`);
  if (ownDetail.status !== 200) {
    throw new Error(`positive control FAILED: A reading its OWN customer ${A.customerId} → ${ownDetail.status} (expected 200)`);
  }
  log(`positive: A portal 200; A reads own customer ${A.customerId} → 200 ✓`);

  // ── 2. IDOR: A reads B's customer detail (html + json) → 404 ───────────────
  const leakHtml = await V.probe(page, `/reseller/customers/${B.customerId}`);
  if (![403, 404].includes(leakHtml.status)) {
    throw new Error(`IDOR LEAK: A read B's customer ${B.customerId} → ${leakHtml.status} (expected 403/404)\n  body: ${(leakHtml.bodySample || '').replace(/\s+/g, ' ')}`);
  }
  // JSON contract (expectsJson) — a controller can leak via the json branch even
  // when the html branch 404s, so probe it explicitly.
  const leakJson = await V.probe(page, `/reseller/customers/${B.customerId}`, { headers: { Accept: 'application/json' } });
  if (![403, 404].includes(leakJson.status)) {
    throw new Error(`IDOR LEAK (json): A read B's customer ${B.customerId} → ${leakJson.status}\n  body: ${(leakJson.bodySample || '').replace(/\s+/g, ' ')}`);
  }
  log(`IDOR: A→B customer ${B.customerId} html=${leakHtml.status} json=${leakJson.status} (denied) ✓`);

  // ── 3. Sub-owner scoping: A's customer list excludes B's customer ──────────
  const listResp = await page.goto(cfg.baseUrl + '/reseller/customers', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!listResp || listResp.status() !== 200) throw new Error(`A customers list → ${listResp ? listResp.status() : 'no response'}`);
  const listHtml = await page.evaluate(() => document.documentElement.innerHTML);
  if (!listHtml.includes(A.customerEmail)) throw new Error(`A's own customer ${A.customerEmail} missing from A's customers list`);
  if (listHtml.includes(B.customerEmail)) throw new Error(`tenant LEAK: B's customer ${B.customerEmail} appears in A's customers list`);
  await shot(page, 'c-idor-a-customers-scoped');
  log(`scoping: A list has A.customer, excludes B.customer ✓`);

  // ── 4. Adjacent-id probe: ids around B's customer are also denied ──────────
  for (const gid of [B.customerId - 1, B.customerId + 1, B.ownerId]) {
    const r = await V.probe(page, `/reseller/customers/${gid}`);
    // Allowed ONLY if that id happens to be A's own customer; otherwise must deny.
    if (r.status === 200 && gid !== A.customerId) {
      throw new Error(`adjacent-id LEAK: A read customer ${gid} → 200 (not A's own ${A.customerId})`);
    }
  }
  log(`adjacent-id: ids around B denied (no off-by-one reach) ✓`);

  // ── 5. HOST FENCE: each brand resolves only on its own host ────────────────
  const hostCtx = (await browser.createBrowserContext?.()) || browser;
  const hostPage = await hostCtx.newPage();
  await hostPage.setViewport({ width: 390, height: 844 });
  try {
    const seesBrand = async (url, brand) => {
      const resp = await hostPage.goto(url, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
      if (!resp || resp.status() >= 400) throw new Error(`${url} → ${resp ? resp.status() : 'no response'}`);
      return hostPage.evaluate((b) => document.documentElement.innerHTML.includes(b), brand);
    };
    if (!(await seesBrand(A.baseUrl + '/', A.brandName))) throw new Error(`A's brand did not resolve on host A (${A.host})`);
    if (await seesBrand(A.baseUrl + '/', B.brandName)) throw new Error(`host LEAK: B's brand resolved on host A (${A.host})`);
    await shot(hostPage, 'c-idor-host-a');
    if (!(await seesBrand(B.baseUrl + '/', B.brandName))) throw new Error(`B's brand did not resolve on host B (${B.host})`);
    if (await seesBrand(B.baseUrl + '/', A.brandName)) throw new Error(`host LEAK: A's brand resolved on host B (${B.host})`);
    await shot(hostPage, 'c-idor-host-b');
    log(`host fence: A.brand only on host A, B.brand only on host B ✓`);
  } finally {
    await hostPage.close().catch(() => {});
    if (hostCtx !== browser) await hostCtx.close().catch(() => {});
  }

  log(`c-idor PASS — reseller tenant isolation: customer IDOR 404 (html+json), list scoped, adjacent-id denied, host fence holds (A=${A.resellerId} B=${B.resellerId})`);
};
