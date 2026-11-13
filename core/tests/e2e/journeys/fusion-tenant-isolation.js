// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * fusion-reseller FR-61 (opt-in) — the tenancy fence, in a real browser.
 *
 * The PHPUnit FusionTenancyAuditTest already proves the leakage matrix at the query
 * layer. What a browser adds: that a reseller Fusion HOST actually renders only its
 * own stores and 404s a cross-tenant store URL end-to-end (routing + gate + views).
 *
 * The reseller host (the seeder's fusion-a.test) is reached by name: the runner maps it to the
 * app with Chrome's --host-resolver-rules (E2E_CHROME_ARGS), so no DNS or Herd host setup is
 * needed and the app resolves the reseller from the real Host (Reseller::resolveForRequest
 * reads getHost()). Chrome no longer accepts a Host header set through setExtraHTTPHeaders
 * (net::ERR_INVALID_ARGUMENT), which is what this journey used to do.
 *
 * Data: FusionResellerDemoSeeder (idempotent demo stores — read-only here, no cleanup).
 *   php -d opcache.enable_cli=0 artisan db:seed --class=FusionResellerDemoSeeder
 * Env: E2E_RUN_FUSION_TENANCY=1
 */

module.exports = async function fusionTenantIsolation({ page, cfg, log, shot }) {
  const eeOnly = require('../lib/platform').requireEe('the Fusion marketplace (reseller hosts)');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const failures = [];

  // The demo seeder's fixed slugs.
  const A1 = 'fusion-demo-a1';        // reseller A, dual-listed
  const A2 = 'fusion-demo-a2';        // reseller A, reseller-only
  const B1 = 'fusion-demo-b1';        // reseller B
  const PLATFORM = 'fusion-demo-platform';

  // Same scheme and port as the run's base URL, on the reseller's own host.
  const platformBase = cfg.baseUrl;
  const resellerUrl = new URL(cfg.baseUrl);
  // Reseller A's host: E2E_FUSION_HOST, else its CURRENT custom_domain (other journeys — c-fusion-depth, c-sale —
  // re-point it onto a resolvable subdomain of the site and leave it there), else the seeder's fusion-a.test.
  let hostA = process.env.E2E_FUSION_HOST || '';
  if (!hostA && process.env.E2E_DB) {
    try {
      const P = require('../lib/platform');
      const row = P.db("SELECT r.custom_domain FROM {p}resellers r JOIN {p}users u ON u.id = r.owner_user_id WHERE u.email = 'fusiondemo-a@example.test'");
      hostA = (row[0] && row[0][0] && row[0][0] !== 'NULL') ? row[0][0] : '';
    } catch (_e) { hostA = ''; }
  }
  resellerUrl.hostname = hostA || 'fusion-a.test';
  const resellerBase = resellerUrl.origin;
  let base = resellerBase;

  // Browse reseller A's Fusion host: only A's stores resolve; B's + the platform
  // store's pages 404.
  const statusOf = async (slug) => {
    const resp = await page.goto(`${base}/fusion/store/${slug}`, {
      waitUntil: 'domcontentloaded', timeout: cfg.navTimeout,
    });
    return resp ? resp.status() : 0;
  };

  const a1 = await statusOf(A1);
  if (a1 >= 400) failures.push(`A1 should resolve on reseller A, got ${a1}`);

  const a2 = await statusOf(A2);
  if (a2 >= 400) failures.push(`A2 (reseller-only) should resolve on reseller A, got ${a2}`);

  const b1OnA = await statusOf(B1);
  if (b1OnA !== 404) failures.push(`B1 must 404 on reseller A (cross-tenant), got ${b1OnA}`);

  const platformOnA = await statusOf(PLATFORM);
  if (platformOnA !== 404) failures.push(`platform store must 404 on reseller A, got ${platformOnA}`);

  await shot(page, 'fusion-tenant-isolation-hostA');

  // Flip to the platform host: the reseller-only store 404s; the platform store lives.
  base = platformBase;

  const a2OnPlatform = await statusOf(A2);
  if (a2OnPlatform !== 404) failures.push(`A2 (reseller-only) must 404 on platform, got ${a2OnPlatform}`);

  const platformOnPlatform = await statusOf(PLATFORM);
  if (platformOnPlatform >= 400) failures.push(`platform store should resolve on platform, got ${platformOnPlatform}`);

  if (failures.length) {
    throw new Error('fusion-tenant-isolation failed:\n  ' + failures.join('\n  '));
  }
  log('fusion-tenant-isolation: reseller host shows only its own stores; cross-tenant URLs 404 ✓');
};
