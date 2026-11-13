// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * T8 — browser e2e smoke runner.
 *
 * Runs each journey in a fresh page against a seeded instance
 * ($E2E_BASE_URL), captures screenshots to __screenshots__/, and exits
 * non-zero if any journey fails. Nightly-only in CI (slow, flake-prone) so it
 * never gates a PR merge; run locally with `npm run test:e2e`.
 *
 *   npm run test:e2e                          # all default journeys
 *   npm run test:e2e -- storefront-checkout   # a subset by name
 *   E2E_RUN_POS=1 npm run test:e2e            # include the POS sale journey
 *   E2E_ADMIN_EMAIL=... E2E_ADMIN_PASS=... npm run test:e2e -- role-pages-smoke
 */

const fs = require('fs');
const path = require('path');
const { cfg, log, sleep, launch, newPage, shot, clearServiceWorkers, SCREENSHOT_DIR } = require('./lib/harness');

// Platform group (W0.4): every journeys/platform/*.js, registered ONLY when
// E2E_RUN_PLATFORM=1, sorted by filename so the order is deterministic. Named by
// bare slug (e.g. `a-order`, `selftest`) so `bin/e2e.sh <lane> a-order` matches.
function platformJourneys() {
  const dir = path.join(__dirname, 'journeys', 'platform');
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir)
    .filter((f) => f.endsWith('.js'))
    .sort()
    .map((f) => [f.replace(/\.js$/, ''), require(path.join(dir, f))]);
}

// Platform-v2 group (V0.1): every journeys/platform-v2/*.js, registered ONLY when
// E2E_RUN_PLATFORM_V2=1, sorted by filename so the order is deterministic. Named
// by bare slug (e.g. `selftest`) so `bin/e2e.sh <lane> selftest` matches — this
// is the coverage-expansion pack (security/role-depth + English/LTR + stubs) and
// is fully independent of the v1 `platform` group above.
function platformV2Journeys() {
  const dir = path.join(__dirname, 'journeys', 'platform-v2');
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir)
    .filter((f) => f.endsWith('.js'))
    .sort()
    .map((f) => [f.replace(/\.js$/, ''), require(path.join(dir, f))]);
}

// P1-12 item 9 ("a journey for every uncovered area"): every journeys/uncovered/*.js, named by bare slug (a-pharmacy, …).
// Unlike the groups above they are registered whenever one is NAMED on the command line (npm run test:e2e -- a-pharmacy),
// and for a whole-group run with E2E_RUN_UNCOVERED=1; a bare `npm run test:e2e` leaves them out (they need the seeded
// dev database and, for some legs, the armed instance — see lib/lane-a.js). Several lanes drop files here, prefixed by lane.
// P4 (lane C): an installed edition package (a folder of core/ with a juwri-edition.php manifest) may ship journeys of
// its own areas in <package>/tests/e2e/journeys — same naming and selection rules; absent from a build without it.
function editionJourneyDirs() {
  const core = path.resolve(__dirname, '../..');
  return fs.readdirSync(core, { withFileTypes: true })
    .filter((d) => d.isDirectory() && fs.existsSync(path.join(core, d.name, 'juwri-edition.php')))
    .map((d) => path.join(core, d.name, 'tests', 'e2e', 'journeys'))
    .filter((dir) => fs.existsSync(dir));
}

function uncoveredJourneys() {
  const dirs = [path.join(__dirname, 'journeys', 'uncovered'), ...editionJourneyDirs()].filter((dir) => fs.existsSync(dir));
  const named = process.argv.slice(2).filter((a) => !a.startsWith('--'));
  const all = (process.env.E2E_JOURNEYS || '').split(',').map((s) => s.trim()).filter(Boolean).concat(named);
  const wantAll = process.env.E2E_RUN_UNCOVERED === '1' || all.includes('uncovered');
  return dirs.flatMap((dir) => fs.readdirSync(dir)
    .filter((f) => f.endsWith('.js'))
    .sort()
    .map((f) => f.replace(/\.js$/, ''))
    .filter((n) => wantAll || all.includes(n))
    .map((n) => [n, require(path.join(dir, n))]));
}

const BASE = [
  ['storefront-checkout', require('./journeys/storefront-checkout')],
  ['dashboard-login', require('./journeys/dashboard-login')],
  ['staff-pwa-login', require('./journeys/staff-pwa-login')],
  ['role-pages-smoke', require('./journeys/role-pages-smoke')],
  ...(process.env.E2E_RUN_RETAINED_CSP === '1'
    ? [['retained-template-csp', require('./journeys/retained-template-csp')]]
    : []),
  // POS needs seeded staff + PosV2DemoSeeder; opt-in so the default run is green.
  ...(process.env.E2E_RUN_POS === '1' ? [['pos-sale', require('./journeys/pos-sale')]] : []),
  // STF-013: void/refund corrections from the orders hub (needs pos_refund staff).
  ...(process.env.E2E_RUN_POS_REFUND === '1' ? [['pos-refund-void', require('./journeys/pos-refund-void')]] : []),
  // E2E-005: customer coupon branches at checkout (needs a seeded coupon).
  ...(process.env.E2E_RUN_RETENTION === '1' ? [['cus-retention-coupon', require('./journeys/cus-retention-coupon')]] : []),
  // E2E-008: multi-tab store context isolation (needs a two-store owner).
  ...(process.env.E2E_RUN_MULTITAB === '1' ? [['multi-tab-context', require('./journeys/multi-tab-context')]] : []),
  // P1-G: storefront language switch actually moves the UI locale.
  ...(process.env.E2E_RUN_LANG === '1' ? [['storefront-language-switch', require('./journeys/storefront-language-switch')]] : []),
  // P1-G: every rendered interactive control has an accessible name.
  ...(process.env.E2E_RUN_A11Y === '1' ? [['storefront-a11y-names', require('./journeys/storefront-a11y-names')]] : []),
  // TPL: rating histogram + write-a-review ported to the themes with reviews.
  ...(process.env.E2E_RUN_REVIEWS === '1' ? [['storefront-item-reviews', require('./journeys/storefront-item-reviews')]] : []),
  // TPL: the home order-type bar drives each theme's own checkout control.
  ...(process.env.E2E_RUN_ORDER_TYPE === '1' ? [['storefront-home-order-type', require('./journeys/storefront-home-order-type')]] : []),
  // TPL: the shared offers strip renders live discounts and its cards open the item.
  ...(process.env.E2E_RUN_OFFERS === '1' ? [['storefront-offers-strip', require('./journeys/storefront-offers-strip')]] : []),
  // TPL: calibrated capability matrix for rev/Templats-issues.md.
  ...(process.env.E2E_RUN_TPL_MATRIX === '1' ? [['template-capability-matrix', require('./journeys/template-capability-matrix')]] : []),
  // P1-G: one checkout intent = one order (double-tap / retry).
  ...(process.env.E2E_RUN_DOUBLE_SUBMIT === '1' ? [['checkout-double-submit', require('./journeys/checkout-double-submit')]] : []),

  // fusion-reseller FR-61 — per-reseller Fusion tenancy + console (needs
  // FusionResellerDemoSeeder; the tenancy journey fakes reseller hosts via a Host header).
  ...(process.env.E2E_RUN_FUSION_TENANCY === '1' ? [['fusion-tenant-isolation', require('./journeys/fusion-tenant-isolation')]] : []),
  ...(process.env.E2E_RUN_FUSION_CONSOLE === '1' ? [['fusion-reseller-console', require('./journeys/fusion-reseller-console')]] : []),

  // fusion-multicart FM-14 — per-store mini-carts + independent checkout (needs two
  // orderable platform Fusion stores; see the journey header).
  ...(process.env.E2E_RUN_MULTICART === '1' ? [['fusion-multicart', require('./journeys/fusion-multicart')]] : []),

  // D4-7: regenerate the committed help-center screenshots (assets/docs/img/*).
  // Opt-in so the default smoke run is unaffected; the nightly e2e job sets it.
  ...(process.env.E2E_RUN_DOCS === '1' ? [['docs-screenshots', require('./journeys/docs-screenshots')]] : []),
  // Owner redesign visual baseline: every param-free owner page × 390/1280 × light/dark
  // × en/ar. Opt-in (long, ~1k screenshots); run as before/after, then open compare.html.
  ...(process.env.E2E_RUN_BASELINE === '1' ? [['redesign-baseline', require('./journeys/redesign-baseline')]] : []),
  // CSP hygiene: zero securitypolicyviolation events on load + reload of every retained theme.
  ...(process.env.E2E_RUN_CSP_CLEAN === '1' ? [['storefront-csp-clean', require('./journeys/storefront-csp-clean')]] : []),
  // P3-13: axe smoke (0 serious/critical) on the 8 D11 premium themes, en + ar.
  ...(process.env.E2E_RUN_PREMIUM_AXE === '1' ? [['premium-axe-smoke', require('./journeys/premium-axe-smoke')]] : []),
  // Owner mobile shell: phone tab bar + "More" drawer, desktop hidden, in-app chrome (S2).
  ...(process.env.E2E_RUN_SHELL === '1' ? [['owner-shell', require('./journeys/owner-shell')]] : []),

  // P1-12 item 9 (lane B): licence states, P1-23 legal promises, OTP per-store scoping, App Store, Arabic RTL.
  // Opt-in; each journey skips with a logged reason when its edition/data prerequisite is absent.
  ...(process.env.E2E_RUN_P112_B === '1' ? [
    ['licence-states', require('./journeys/licence-states')],
    ['p123-legal-promises', require('./journeys/p123-legal-promises')],
    ['otp-store-scoping', require('./journeys/otp-store-scoping')],
    ['marketplace-appstore', require('./journeys/marketplace-appstore')],
    ['arabic-rtl', require('./journeys/arabic-rtl')],
  ] : []),

  // P3-10 (lane B): services booking engine — owner setup, customer book / reschedule / cancel, IDOR. Opt-in.
  ...(process.env.E2E_RUN_P310 === '1' ? [['bookings-services', require('./journeys/bookings-services')]] : []),
  // P3-11: the `book` theme — services booking and the laundry variant (one hold for pickup + delivery). Needs the seeded Sidra Salon
  // (BookDemoSeeder) on the app under test.
  ...(process.env.E2E_RUN_P311 === '1' ? [['book-theme', require('./journeys/book-theme')]] : []),
  // P3-08b: store fitment CSV import + the catalogue vehicle filter. Opt-in.
  ...(process.env.E2E_RUN_P308B === '1' ? [['fitment-import', require('./journeys/fitment-import')]] : []),
  // Phase 4 (lane B): HR, inventory, scheduled orders, receipts — owner → customer/staff → owner + cross-store IDOR. Opt-in.
  ...(process.env.E2E_RUN_P4 === '1' ? fs.readdirSync(path.join(__dirname, 'journeys')).filter((f) => /^p4-.*\.js$/.test(f)).sort()
    .map((f) => [f.replace(/\.js$/, ''), require(path.join(__dirname, 'journeys', f))]) : []),
];

const PLATFORM = process.env.E2E_RUN_PLATFORM === '1' ? platformJourneys() : [];
const PLATFORM_NAMES = new Set(PLATFORM.map(([n]) => n));
const PLATFORM_V2 = process.env.E2E_RUN_PLATFORM_V2 === '1' ? platformV2Journeys() : [];
const PLATFORM_V2_NAMES = new Set(PLATFORM_V2.map(([n]) => n));
const ALL = [...BASE, ...PLATFORM, ...PLATFORM_V2, ...uncoveredJourneys()];

// Journey selection: CLI args win, else the E2E_JOURNEYS comma filter. Either
// may include the literal token `platform` (expands to the whole v1 platform
// group) or `platform-v2` (expands to the whole v2 coverage-expansion group).
// No selector → the full registered list for the current env.
function selectJourneys() {
  const argv = process.argv.slice(2).filter((a) => !a.startsWith('--'));
  const envFilter = (process.env.E2E_JOURNEYS || '').split(',').map((s) => s.trim()).filter(Boolean);
  const tokens = argv.length ? argv : envFilter;
  if (!tokens.length) return ALL;
  const wantPlatform = tokens.includes('platform');
  const wantPlatformV2 = tokens.includes('platform-v2');
  return ALL.filter(([n]) => tokens.includes(n)
    || (wantPlatform && PLATFORM_NAMES.has(n))
    || (wantPlatformV2 && PLATFORM_V2_NAMES.has(n)));
}

async function main() {
  // --list: print the registered journeys for the current env and exit (W0.4;
  // the runner contract test parses this).
  if (process.argv.includes('--list')) {
    for (const [n] of ALL) console.log(n);
    process.exit(0);
  }

  const journeys = selectJourneys();
  if (!journeys.length) {
    console.error('No matching journeys. Available:', ALL.map(([n]) => n).join(', '));
    process.exit(2);
  }

  log(`base=${cfg.baseUrl} store=${cfg.storeSlug} locale=${cfg.locale} journeys=${journeys.map(([n]) => n).join(',')}`);
  const browser = await launch();
  const results = [];

  // Per-journey HARD watchdog. A single hung journey (e.g. an unhandled native
  // dialog, or a wait the page never satisfies) must never burn hours — it is
  // failed after this cap and the suite moves on. Generous: a-order legitimately
  // runs ~8 min across 17 themes, so the default is 15 min. Override with
  // E2E_JOURNEY_TIMEOUT (ms).
  const JOURNEY_TIMEOUT = parseInt(process.env.E2E_JOURNEY_TIMEOUT || '900000', 10);

  for (const [name, fn] of journeys) {
    // Isolate each journey in its own browser context so cookies / session /
    // service workers never leak between journeys (e.g. an owner login in one
    // journey must not authenticate the next).
    const context = (await browser.createBrowserContext?.()) || browser;
    const page = await newPage(context);
    const started = Date.now();
    let watchdog;
    try {
      const guard = new Promise((_, reject) => {
        watchdog = setTimeout(
          () => reject(new Error(`journey watchdog: exceeded ${JOURNEY_TIMEOUT}ms hard cap`)),
          JOURNEY_TIMEOUT,
        );
      });
      const out = await Promise.race([
        fn({ browser, page, cfg, log, shot, sleep, clearServiceWorkers }),
        guard,
      ]);
      // A journey that cannot apply to this build resolves to { skip: '<reason>' } (e.g. an Enterprise-only journey
      // on the Community build): reported as skipped with its reason, never as a pass.
      if (out && typeof out.skip === 'string') {
        results.push({ name, ok: true, skipped: out.skip, ms: Date.now() - started });
        log(`SKIP ${name}: ${out.skip}`);
      } else {
        results.push({ name, ok: true, ms: Date.now() - started });
        log(`PASS ${name} (${Date.now() - started}ms)`);
      }
    } catch (err) {
      await shot(page, `${name}-FAIL`).catch(() => {});
      results.push({ name, ok: false, ms: Date.now() - started, error: err.message });
      log(`FAIL ${name}: ${err.message}`);
      if (process.env.E2E_STACK === '1' && err.stack) log(`  stack: ${String(err.stack).split('\n').slice(1, 6).map((l) => l.trim()).join(' | ')}`);
    } finally {
      clearTimeout(watchdog);
      await page.close().catch(() => {});
      if (context !== browser) await context.close().catch(() => {});
    }
  }

  await browser.close().catch(() => {});

  const failed = results.filter((r) => !r.ok);
  console.log('\n──── e2e summary ────');
  const skipped = results.filter((r) => r.skipped);
  for (const r of results) console.log(`${r.skipped ? '-' : (r.ok ? '✓' : '✗')} ${r.name} (${r.ms}ms)${r.error ? ' — ' + r.error : ''}${r.skipped ? ' — skipped: ' + r.skipped : ''}`);
  console.log(`${results.length - failed.length - skipped.length}/${results.length} journeys passed${skipped.length ? `, ${skipped.length} skipped` : ''}`);

  // W0.4: machine-readable results for CI artifacts + WF.1 reproducibility.
  try {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, 'results.json'),
      JSON.stringify({ base: cfg.baseUrl, locale: cfg.locale, when: new Date().toISOString(), results }, null, 2),
    );
  } catch (e) { console.error('could not write results.json:', e.message); }

  process.exit(failed.length ? 1 : 0);
}

main().catch((e) => { console.error('e2e runner crashed:', e); process.exit(1); });
