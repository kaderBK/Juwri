// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E — C1: Reseller created by admin → active on its own host.
 *
 * There is NO public "become a reseller" form: the superadmin creates resellers
 * (`admin.resellers.store`, `Admin\ResellerController@store`). `reseller.signup`
 * is the SUB-owner signup a reseller hosts (that's C4). This journey proves the
 * whole provisioning seam in a real browser, in Arabic:
 *
 *   1. A fresh plain owner is created (B1 owner-signup is not merged yet, so the
 *      owner is inserted via db() — HANDOFF §3 blesses this fallback). A known
 *      bcrypt hash is stored so the real /login form works.
 *   2. NEGATIVE: the plain owner logs in and hits /reseller → 403
 *      (ResellerOwnerMiddleware refuses a non-reseller).
 *   3. In a SECOND browser context the admin creates a reseller for that owner
 *      via the real "Add Reseller" form (owner_user_id, brand_name,
 *      custom_domain=r1-<unix>.e2e-c.test). `active` defaults on. The admin then
 *      edits the row to turn `allow_signup` on (the create form has no such field
 *      — only the edit form does), exercising `admin.resellers.update`.
 *   4. POSITIVE: the same owner session now reaches /reseller → 200, RTL, brand.
 *   5. The portal domain endpoint reports a status (custom_domain entitlement).
 *   6. HOST RESOLUTION: the reseller brand resolves on its own host
 *      (ResolveResellerBranding → activeBrand); the platform brand does not leak
 *      into the branded footer slot, and the reseller brand is absent on the
 *      platform host. Real subdomain first, Host-header override as the fallback
 *      (fusion-tenant-isolation.js pattern); the journey logs which worked.
 *
 * Ground truth only: HTTP status codes, DOM text/attrs, DB rows. One shot() per
 * screen. Finishes asserting no console errors / no enforced CSP refusals on the
 * portal + admin surfaces.
 *
 * Runs under E2E_RUN_PLATFORM=1:  bash rev/platform-e2e/bin/e2e.sh c c-reseller
 */

const P = require('../../lib/platform');

// A fixed, dev-only bcrypt hash so the inserted owner can use the real /login
// form. password_verify('E2e-owner-c1-pass', HASH) === true (verified locally).
const OWNER_PASS = 'E2e-owner-c1-pass';
const OWNER_HASH = '$2y$10$L3VAT6Coa8OxNvrN53pvMeQHzCGK.ZRfBECbY.GmYfmhMb1g.j8zm';

// Non-faults to tolerate: static assets that 404 repo-wide on the old-theme
// admin/reseller panel skin and on missing seeded media (skin search index,
// favicon, absent storage logos/avatars), and the report-only CSP telemetry
// beacon `/csp-report` (advisory per HANDOFF §2; its rate-limit 429 depends on
// prior traffic, so it is flaky by nature). All are out of the C1 fence and not
// app breakage. Anything else that 4xxs (a panel JS/CSS bundle, an api/xhr) is a
// real fault.
const BENIGN_RES = /(favicon\.ico|\/csp-report|\/assets\/ajax\/search\.json|\/storage\/|\.(png|jpe?g|webp|svg|gif|ico|woff2?)(\?|$))/i;

/** Record every non-document sub-resource that 4xxs on `page` (URLs, for classification). */
function watchNet(page) {
  const bad = [];
  page.on('response', (r) => {
    try {
      if (r.status() >= 400 && r.request().resourceType() !== 'document') bad.push(r.status() + ' ' + r.url());
    } catch (_e) { /* response gone */ }
  });
  return bad;
}

/**
 * Fail on real breakage — enforced CSP refusals, page JS errors, non-404 console
 * errors, and any 4xx sub-resource outside BENIGN_RES — while tolerating the
 * benign panel-skin 404s (whose URLs puppeteer omits from the console text, so
 * the shared collector cannot see them). Fence-safe: lives in the journey.
 */
function assertClean(where, collector, netBad) {
  const hard = collector.errors.filter((e) => e.startsWith('CSP:')
    || e.startsWith('pageerror:')
    || (e.startsWith('console.error:') && !/Failed to load resource/i.test(e)));
  const badRes = netBad.filter((u) => !BENIGN_RES.test(u));
  const problems = [...hard, ...badRes.map((u) => 'resource: ' + u)];
  if (problems.length) throw new Error(`${where}: console/CSP/resource errors (${problems.length}):\n  ${problems.join('\n  ')}`);
}

module.exports = async function cReseller({ browser, page, cfg, log, shot, sleep }) {
  const eeOnly = P.requireEe('Reseller');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const adminEmail = process.env.E2E_ADMIN_EMAIL;
  const adminPass = process.env.E2E_ADMIN_PASS;
  if (!adminEmail || !adminPass) throw new Error('c-reseller: E2E_ADMIN_EMAIL / E2E_ADMIN_PASS not exported (bin/e2e.sh reads the lane .env)');

  const unix = Date.now();
  const ownerEmail = `e2e-c-resowner-${unix}@local.invalid`;
  const brandName = `لين للمطاعم ${unix}`;         // real Arabic brand (the run is ar)
  const baseHost = new URL(cfg.baseUrl).hostname;       // e2e-c.test
  const host = `r1-${unix}.${baseHost}`;            // subdomain of the lane site → Herd serves it

  await page.setViewport({ width: 390, height: 844 });

  // ── 1. fresh plain owner via db() ──────────────────────────────────────────
  P.db(
    "INSERT INTO {p}users (email, username, name, password, user_type, status, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'user', 1, NOW(), NOW(), NOW())",
    [ownerEmail, `resowner${unix}`, 'C1 Owner', OWNER_HASH],
  );
  const ownerRow = P.db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [ownerEmail]);
  if (!ownerRow.length) throw new Error(`c-reseller: owner row not inserted for ${ownerEmail}`);
  const ownerId = Number(ownerRow[0][0]);
  log(`owner created: id=${ownerId} email=${ownerEmail}`);

  // ── 2. NEGATIVE: non-reseller owner is forbidden from the portal ───────────
  const col = P.collectConsole(page);
  const ownerNet = watchNet(page);
  await P.loginOwner(page, { email: ownerEmail, pass: OWNER_PASS });
  const negResp = await page.goto(cfg.baseUrl + '/reseller', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!negResp || negResp.status() !== 403) {
    throw new Error(`negative: a non-reseller owner must get 403 on /reseller, got ${negResp ? negResp.status() : 'no response'}`);
  }
  await shot(page, 'c-reseller-nonreseller-403');
  log('negative: non-reseller owner → 403 on /reseller ✓');

  // ── 3. ADMIN (second context) creates the reseller via the real form ───────
  const adminCtx = (await browser.createBrowserContext?.()) || browser;
  const adminPage = await adminCtx.newPage();
  await adminPage.setViewport({ width: 390, height: 844 });
  const adminCol = P.collectConsole(adminPage);
  const adminNet = watchNet(adminPage);
  let resellerId;
  try {
    await P.loginAdmin(adminPage, { email: adminEmail, pass: adminPass });
    const idxResp = await adminPage.goto(cfg.baseUrl + '/admin/resellers', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!idxResp || idxResp.status() !== 200) throw new Error(`admin resellers index returned ${idxResp ? idxResp.status() : 'no response'}`);

    // Fill the "Add Reseller" form (owner_user_id is unique to that form).
    await adminPage.waitForSelector('input[name="owner_user_id"]', { timeout: cfg.navTimeout });
    await adminPage.type('input[name="owner_user_id"]', String(ownerId));
    await adminPage.type('input[name="brand_name"]', brandName);
    await adminPage.type('input[name="custom_domain"]', host);
    await Promise.all([
      adminPage.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
      adminPage.evaluate(() => { document.querySelector('input[name="owner_user_id"]').form.submit(); }),
    ]);

    // Ground truth: the reseller row exists, active defaults on, owner tagged.
    const rRow = P.db(
      'SELECT id, active, allow_signup, custom_domain, owner_user_id FROM {p}resellers WHERE custom_domain = ? LIMIT 1',
      [host],
    );
    if (!rRow.length) throw new Error(`admin store did not create a reseller for custom_domain=${host}`);
    resellerId = Number(rRow[0][0]);
    const [, active0, , domain0, ownerFk0] = rRow[0];
    if (String(active0) !== '1') throw new Error(`new reseller should be active by default, got active=${active0}`);
    if (domain0 !== host) throw new Error(`custom_domain mismatch: ${domain0} !== ${host}`);
    if (Number(ownerFk0) !== ownerId) throw new Error(`owner_user_id mismatch: ${ownerFk0} !== ${ownerId}`);
    const tagged = P.db('SELECT reseller_id FROM {p}users WHERE id = ? LIMIT 1', [ownerId]);
    if (Number(tagged[0][0]) !== resellerId) throw new Error(`owner not tagged with reseller_id (${tagged[0][0]} !== ${resellerId})`);
    log(`admin created reseller id=${resellerId} domain=${host} (active=1, owner tagged) ✓`);
    await shot(adminPage, 'c-reseller-admin-created');

    // Turn allow_signup ON via the row's edit form (create form has no such
    // field). The collapse form is in the DOM already — set the checkboxes and
    // submit it (POST + _method=PUT → admin.resellers.update).
    const editReady = await adminPage.evaluate((id) => {
      const form = document.querySelector(`#edit-${id} form[action$="/admin/resellers/${id}"]`);
      if (!form) return false;
      const allow = form.querySelector('input[type="checkbox"][name="allow_signup"]');
      const act = form.querySelector('input[type="checkbox"][name="active"]');
      if (!allow || !act) return false;
      allow.checked = true;
      act.checked = true;
      return true;
    }, resellerId);
    if (!editReady) throw new Error('admin edit form for the new reseller not found on the index page');
    await Promise.all([
      adminPage.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
      adminPage.evaluate((id) => { document.querySelector(`#edit-${id} form[action$="/admin/resellers/${id}"]`).submit(); }, resellerId),
    ]);
    const afterEdit = P.db('SELECT active, allow_signup FROM {p}resellers WHERE id = ? LIMIT 1', [resellerId]);
    if (String(afterEdit[0][0]) !== '1' || String(afterEdit[0][1]) !== '1') {
      throw new Error(`admin update should leave active=1 allow_signup=1, got active=${afterEdit[0][0]} allow_signup=${afterEdit[0][1]}`);
    }
    log('admin update: allow_signup ON, active ON ✓');
    assertClean('admin panel', adminCol, adminNet);
  } finally {
    await adminPage.close().catch(() => {});
    if (adminCtx !== browser) await adminCtx.close().catch(() => {});
  }

  // ── 4. POSITIVE: the same owner session now reaches the portal ─────────────
  const posResp = await page.goto(cfg.baseUrl + '/reseller', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!posResp || posResp.status() !== 200) {
    throw new Error(`positive: the reseller owner must get 200 on /reseller, got ${posResp ? posResp.status() : 'no response'}`);
  }
  await P.assertRtl(page);                                  // <html dir="rtl" lang="ar">
  const portalHasBrand = await page.evaluate((b) => document.documentElement.innerHTML.includes(b), brandName);
  if (!portalHasBrand) throw new Error('the reseller portal did not render the reseller brand name');
  await P.assertNoOverflow(page);
  await shot(page, 'c-reseller-portal');
  log('positive: reseller owner → 200 on /reseller (RTL, brand present) ✓');

  // ── 5. Domain endpoint reports a status (custom_domain entitlement) ────────
  const domJson = await page.evaluate(async (base) => {
    const r = await fetch(base + '/reseller/domain', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    return { status: r.status, body: await r.json().catch(() => ({})) };
  }, cfg.baseUrl);
  if (domJson.status !== 200) throw new Error(`domain status endpoint returned ${domJson.status}`);
  if (!('domain_status' in domJson.body)) throw new Error(`domain status endpoint did not report domain_status: ${JSON.stringify(domJson.body)}`);
  if (domJson.body.custom_domain !== host) throw new Error(`domain status custom_domain mismatch: ${domJson.body.custom_domain} !== ${host}`);
  // The HTML domain page is entitlement-gated (Growth+); an ungated reseller passes → 200.
  const domPage = await page.goto(cfg.baseUrl + '/reseller/domain', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!domPage || domPage.status() !== 200) throw new Error(`entitled reseller must get 200 on the domain page, got ${domPage ? domPage.status() : 'no response'}`);
  await P.assertRtl(page);
  await shot(page, 'c-reseller-domain');
  assertClean('reseller portal', col, ownerNet);
  log(`domain endpoint status = "${domJson.body.domain_status}" (custom_domain entitled) ✓`);

  // ── 6. HOST RESOLUTION: brand resolves on the reseller host ────────────────
  const hostCtx = (await browser.createBrowserContext?.()) || browser;
  const hostPage = await hostCtx.newPage();
  await hostPage.setViewport({ width: 390, height: 844 });
  const hostCol = P.collectConsole(hostPage);
  let resolution = 'subdomain';
  try {
    let hResp = null;
    try {
      hResp = await hostPage.goto(`${new URL(cfg.baseUrl).protocol}//${host}${new URL(cfg.baseUrl).port ? ':' + new URL(cfg.baseUrl).port : ''}/`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    } catch (e) {
      log(`subdomain navigation failed (${e.message}); falling back to Host-header override`);
      hResp = null;
    }
    if (!hResp || hResp.status() >= 400) {
      resolution = 'host-override';
      await P.withHost(hostPage, host);
      hResp = await hostPage.goto(cfg.baseUrl + '/', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    }
    if (!hResp || hResp.status() >= 400) {
      throw new Error(`reseller host home returned ${hResp ? hResp.status() : 'no response'} (${resolution})`);
    }
    await P.assertRtl(hostPage);

    // The reseller brand resolves on this host (ResolveResellerBranding → activeBrand).
    const brandOnHost = await hostPage.evaluate((b) => document.documentElement.innerHTML.includes(b), brandName);
    if (!brandOnHost) throw new Error(`reseller brand did not resolve on ${host} (${resolution})`);

    // The branded footer copyright slot shows the reseller brand, not the platform's.
    const copyright = await hostPage.evaluate(() => {
      const m = document.documentElement.innerHTML.match(/©\s*\d{4}[^<|]{0,80}/);
      return m ? m[0].replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim() : '';
    });
    if (!copyright.includes(brandName)) throw new Error(`footer copyright is not white-labeled: "${copyright}"`);
    if (/juwri/i.test(copyright)) throw new Error(`platform brand leaks into the footer copyright: "${copyright}"`);
    await shot(hostPage, 'c-reseller-host-home');
    log(`host resolution via ${resolution}: brand resolves, footer white-labeled ("${copyright}") ✓`);

    // No ENFORCED CSP refusal on the reseller host (tenant-security relevant). The
    // marketing home is lane A / shared territory, so out-of-fence console.error
    // noise is logged, not failed, but CSP refusals are hard failures.
    const cspRefusals = hostCol.errors.filter((e) => e.startsWith('CSP:'));
    if (cspRefusals.length) throw new Error(`reseller host CSP refusals:\n  ${cspRefusals.join('\n  ')}`);
    const otherNoise = hostCol.errors.filter((e) => !e.startsWith('CSP:'));
    if (otherNoise.length) log(`note: ${otherNoise.length} console error(s) on the reseller marketing home (out of C1 fence): ${otherNoise[0]}`);

    // Discriminating: the reseller brand is ABSENT on the platform host (proves
    // host-based resolution, not a global string).
    await P.withHost(hostPage, null);
    const platResp = await hostPage.goto(cfg.baseUrl + '/', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!platResp || platResp.status() >= 400) throw new Error(`platform host home returned ${platResp ? platResp.status() : 'no response'}`);
    const brandOnPlatform = await hostPage.evaluate((b) => document.documentElement.innerHTML.includes(b), brandName);
    if (brandOnPlatform) throw new Error('reseller brand leaked onto the platform host home (resolution is not host-scoped)');
    log('discriminating: reseller brand absent on the platform host ✓');
  } finally {
    await hostPage.close().catch(() => {});
    if (hostCtx !== browser) await hostCtx.close().catch(() => {});
  }

  col.stop();
  log(`c-reseller PASS — reseller id=${resellerId} domain=${host} owner=${ownerEmail} / ${OWNER_PASS} (host via ${resolution})`);
};
