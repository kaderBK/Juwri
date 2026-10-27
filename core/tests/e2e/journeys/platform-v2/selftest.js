// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 (V0.1/V0.6) — self-test of the v2 harness + helpers.
 *
 * Runs as NOBODY (guest): it proves the v2 group registers and every v2 helper
 * loads and behaves, WITHOUT depending on a lane's journey data. It asserts
 * ground truth only (DOM attrs, HTTP status, return shapes) — no pixels.
 *
 *   - lib/platform-v2 re-exports the v1 helpers AND adds the v2 ones.
 *   - V0.3 English/LTR: enUrl() prefixes /en; the en storefront renders LTR/lang=en
 *     with a clean console.
 *   - V0.4 security: idorProbe() flags a public 200 as a LEAK (throws) and treats
 *     a guest hit on an owner route as DENIED (auth bounce); rbacMatrix() returns a
 *     per-route allow/deny table; raceN() fans out in parallel.
 *   - V0.2 stubs: stubsArmed()/stubGatewayInstalled() report the fake-gateway state.
 *
 * Runs under `E2E_RUN_PLATFORM_V2=1` and standalone:
 *   E2E_RUN_PLATFORM_V2=1 node tests/e2e/journeys/platform-v2/selftest.js
 */

const V = require('../../lib/platform-v2');

module.exports = async function platformV2Selftest({ page, log, shot }) {
  const checks = [];
  const ok = (name) => { checks.push(name); log(`v2-selftest ✓ ${name}`); };

  // 1. helper surface — v1 re-exports AND v2 additions all present.
  for (const fn of ['localeUrl', 'assertRtl', 'db', 'webLogin', 'readSmsOtp', // v1
    'enUrl', 'assertLtr', 'localeShell',                                      // v0.3
    'idorProbe', 'rbacMatrix', 'raceN', 'racePost', 'probe',                  // v0.4
    'STUB_GATEWAY', 'stubGatewayInstalled', 'stubsArmed']) {                  // v0.2
    if (typeof V[fn] !== 'function' && typeof V[fn] !== 'string') {
      throw new Error(`platform-v2 missing helper: ${fn}`);
    }
  }
  ok('helper surface (v1 re-export + v2 additions)');

  // 2. enUrl always /en-prefixes (site default is ar).
  const enHome = V.enUrl('/crave-demo');
  if (!/\/en\/crave-demo$/.test(enHome)) throw new Error(`enUrl must prefix /en, got ${enHome}`);
  ok(`enUrl → ${enHome.replace(V.cfg.baseUrl, '')}`);

  // 3. English/LTR storefront: 200, dir≠rtl, lang=en, clean console.
  const col = V.collectConsole(page);
  const resp = await page.goto(enHome, { waitUntil: 'networkidle2' });
  if (!resp || resp.status() >= 400) throw new Error(`en storefront returned ${resp ? resp.status() : 'no response'}`);
  await page.waitForSelector('main, #main-content, [data-open], body', { timeout: 30000 });
  const shell = await V.assertLtr(page);
  await shot(page, 'v2-selftest-en-storefront');
  await V.assertNoConsoleErrors(page, col);
  col.stop();
  ok(`assertLtr on en storefront (lang="${shell.lang}", dir="${shell.dir || 'unset'}")`);

  // 4. idorProbe LEAK detection — a public 200 URL must be flagged (throw).
  let leakCaught = false;
  try {
    await V.idorProbe(page, { targetUrl: enHome, expect: [403, 404] });
  } catch (e) {
    if (!/idorProbe LEAK/.test(e.message)) throw e;
    leakCaught = true;
  }
  if (!leakCaught) throw new Error('idorProbe failed to flag a public 200 as a leak');
  ok('idorProbe flags a public 200 as a LEAK');

  // 5. idorProbe DENIAL — a guest hit on an owner-only route is denied (403/404
  //    or an auth bounce). Uses /dashboard which requires an authenticated owner.
  const denial = await V.idorProbe(page, { targetUrl: '/dashboard', expect: [401, 403, 404] });
  if (!denial.denied) throw new Error('idorProbe did not treat guest /dashboard as denied');
  ok(`idorProbe denies guest /dashboard (status ${denial.status})`);

  // 6. rbacMatrix — per-route table for a guest across a public + a protected route.
  const matrix = await V.rbacMatrix(page, {
    routes: [
      { url: enHome, label: 'public storefront' },
      { url: '/dashboard', label: 'owner dashboard' },
    ],
  });
  if (matrix.results.length !== 2) throw new Error('rbacMatrix returned wrong row count');
  const pub = matrix.results.find((r) => r.label === 'public storefront');
  const dash = matrix.results.find((r) => r.label === 'owner dashboard');
  if (!pub.allowed) throw new Error(`rbacMatrix: public storefront should be allowed, got ${pub.status}`);
  if (!dash.denied) throw new Error(`rbacMatrix: guest dashboard should be denied, got ${dash.status}`);
  ok(`rbacMatrix (guest): public=${pub.status} allowed, dashboard=${dash.status} denied`);

  // 7. raceN — fans out n parallel jobs, returns all results in order.
  const raced = await V.raceN((i) => Promise.resolve(i * 2), 4);
  if (raced.length !== 4 || raced[3] !== 6) throw new Error(`raceN returned ${JSON.stringify(raced)}`);
  ok('raceN parallel fan-out');

  // 8. stub state — armed by the v2 bin (E2E_STUBS=1); the gateway row is present
  //    once E2ePlatformV2Seeder has run. Both are reported, not required, so the
  //    selftest is green on a fresh DB too.
  const armed = V.stubsArmed();
  let gwNote = 'gateway row check skipped (no E2E_DB)';
  try {
    if (V.cfg.db) gwNote = `stub gateway installed=${V.stubGatewayInstalled(0)}`;
  } catch (e) {
    gwNote = `gateway probe error: ${e.message}`;
  }
  ok(`stubs: armed=${armed}; ${gwNote}`);

  log(`platform-v2 selftest: ${checks.length} checks passed`);
};

// Standalone runner:
//   E2E_RUN_PLATFORM_V2=1 node tests/e2e/journeys/platform-v2/selftest.js
if (require.main === module) {
  const H = require('../../lib/harness');
  (async () => {
    const browser = await H.launch();
    const context = (await browser.createBrowserContext?.()) || browser;
    const page = await H.newPage(context);
    try {
      await module.exports({ browser, page, cfg: H.cfg, log: H.log, shot: H.shot, sleep: H.sleep, clearServiceWorkers: H.clearServiceWorkers });
      H.log('platform-v2 selftest PASS');
    } catch (e) {
      console.error('platform-v2 selftest FAIL:', e.stack || e.message);
      process.exitCode = 1;
    } finally {
      await page.close().catch(() => {});
      if (context !== browser) await context.close().catch(() => {});
      await browser.close().catch(() => {});
    }
  })();
}
