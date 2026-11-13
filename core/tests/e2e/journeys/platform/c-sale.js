// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E — C4: sale link → sub-owner → menu under the reseller host.
 *
 * Provisions a fresh reseller on its own host (the C1 seam, inline), then drives
 * BOTH sub-owner entry paths ON THE RESELLER HOST, in Arabic:
 *
 *   Setup   A plain owner is inserted via db() (HANDOFF §3 fallback, known bcrypt
 *           hash so the real /login works). The admin (second context) creates a
 *           reseller for that owner (owner_user_id, brand_name, custom_domain=
 *           r1-<unix>.e2e-c.test) and turns allow_signup + active ON. The owner
 *           logs into /reseller, creates a RESELLER PLAN through the real
 *           create-plan form (`reseller.plans.store`, mapped to the cheapest
 *           active platform plan), then mints a sale link through the real
 *           `reseller.plans.saleLink` route (authenticated JSON) → `p/{token}`.
 *
 *   Path A  Reseller-hosted signup: a NEW visitor on the reseller host opens
 *           `reseller-signup/{resellerId}` (`reseller.signup.form`) → the page is
 *           RTL and carries the reseller brand → fills the form and submits it
 *           (the page's own fetch → `reseller.signup`) → the success card shows →
 *           db() proves the sub-owner exists with reseller_id set and group_id=free.
 *
 *   Path B  Plan sale link: a NEW visitor on the reseller host opens `p/{token}`
 *           (`reseller.sale`) → RTL, reseller brand + plan name → completes the
 *           OFFLINE/FREE purchase (`reseller.sale.pay`; the reseller has no BYOK
 *           gateway credential so ResellerGatewayCharger records a test-mode
 *           charge — no real money) → redirected to /login → db() proves the
 *           sub-owner (merchant) exists with reseller_id set, group_id=free (the
 *           paid plan is granted only on a live-verified charge — 1.1), and a
 *           reseller_customer_subscription row was written.
 *
 *   Owner   The sale-link sub-owner logs in UNDER THE RESELLER HOST → /dashboard
 *           renders (RTL) and carries the RESELLER brand (the dashboard chrome
 *           resolves BrandContext → brand_title() = the reseller's brand_name,
 *           NOT the platform's site_title). Discriminating: the reseller brand is
 *           present and the platform default brand is not the dashboard brand.
 *
 *   Menu    A B2-lite menu is built for the sub-owner via db() (a crave Post +
 *           1 active category + 2 active items carrying unique ASCII tokens). The
 *           storefront `/{slug}` is opened UNDER THE RESELLER HOST → 200, RTL, the
 *           crave menu hydrates (window.CRAVE.items carries both item ids; the
 *           grid renders 2 cards). Proves the reseller host serves the merchant's
 *           store.
 *
 * Ground truth only: HTTP status, DOM text/attrs, DB rows, the hydrated menu
 * global. One shot() per screen. Finishes asserting no console errors / no
 * enforced CSP refusals on the surfaces this WI owns.
 *
 * NOTE (logged, ISSUES §C — ISS-C-401): the storefront <title>/OG suffix is the
 * raw lang string `alt_barcode` ("منيو باركود …"), so the platform brand shows in
 * the store page title on a reseller host too. The fix is in the SHARED storefront
 * layout (store_templates/layout.blade.php, every theme) — outside the lane-C
 * fence — so it is logged, not fixed here, and this journey does not assert the
 * storefront body is brand-scrubbed (it asserts the store renders under the host).
 *
 * Runs under E2E_RUN_PLATFORM=1:  bash rev/platform-e2e/bin/e2e.sh c c-sale
 */

const P = require('../../lib/platform');

// A fixed, dev-only bcrypt hash so the inserted reseller-owner can use the real
// /login form. password_verify('E2e-owner-c1-pass', HASH) === true (same hash
// c-reseller.js uses; verified locally).
const OWNER_PASS = 'E2e-owner-c1-pass';
const OWNER_HASH = '$2y$10$L3VAT6Coa8OxNvrN53pvMeQHzCGK.ZRfBECbY.GmYfmhMb1g.j8zm';

// Non-faults to tolerate (same set as c-reseller.js): panel-skin/static 404s and
// the advisory report-only /csp-report beacon. Anything else that 4xxs (a panel
// bundle, an api/xhr) is a real fault.
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
 * benign panel-skin 404s (whose URLs puppeteer omits from the console text).
 */
function assertClean(where, collector, netBad) {
  const hard = collector.errors.filter((e) => e.startsWith('CSP:')
    || e.startsWith('pageerror:')
    || (e.startsWith('console.error:') && !/Failed to load resource/i.test(e)));
  const badRes = netBad.filter((u) => !BENIGN_RES.test(u));
  const problems = [...hard, ...badRes.map((u) => 'resource: ' + u)];
  if (problems.length) throw new Error(`${where}: console/CSP/resource errors (${problems.length}):\n  ${problems.join('\n  ')}`);
}

/** Log the sub-owner in on an explicit host base (the reseller subdomain). */
async function loginOnHost(page, hostBase, { email, pass }) {
  await P.resetBrowserSession(page);
  await page.goto(hostBase + '/login', { waitUntil: 'domcontentloaded', timeout: P.cfg.navTimeout });
  await page.waitForSelector('#username, input[name="username"], input[name="email"]', { timeout: P.cfg.navTimeout });
  await page.type('#username, input[name="username"], input[name="email"]', email);
  await page.type('#password, input[name="password"]', pass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: P.cfg.navTimeout }).catch(() => {}),
  ]);
  const landed = new URL(page.url()).pathname;
  if (landed === '/login') {
    const err = await page.evaluate(() => (document.querySelector('.alert, .invalid-feedback, [role="alert"]')?.textContent || '').replace(/\s+/g, ' ').trim());
    throw new Error(`sub-owner login rejected for ${email} (still on /login${err ? ': ' + err : ''})`);
  }
  return page.url();
}

module.exports = async function cSale({ browser, page, cfg, log, shot, sleep }) {
  const eeOnly = P.requireEe('Reseller sale links and plans');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const adminEmail = process.env.E2E_ADMIN_EMAIL;
  const adminPass = process.env.E2E_ADMIN_PASS;
  if (!adminEmail || !adminPass) throw new Error('c-sale: E2E_ADMIN_EMAIL / E2E_ADMIN_PASS not exported (bin/e2e.sh reads the lane .env)');

  const unix = Date.now();
  const ownerEmail = `e2e-c-saleowner-${unix}@local.invalid`;
  const brandName = `متجر سيل ${unix}`;               // real Arabic reseller brand
  const baseHost = new URL(cfg.baseUrl).hostname;         // e2e-c.test
  const host = `r1-${unix}.${baseHost}`;              // Herd serves the subdomain
  const hostBase = `${new URL(cfg.baseUrl).protocol}//${host}${new URL(cfg.baseUrl).port ? ':' + new URL(cfg.baseUrl).port : ''}`;

  await page.setViewport({ width: 390, height: 844 });

  // ── 1. fresh plain owner via db() ──────────────────────────────────────────
  P.db(
    "INSERT INTO {p}users (email, username, name, password, user_type, status, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'user', 1, NOW(), NOW(), NOW())",
    [ownerEmail, `saleowner${unix}`, 'C4 Owner', OWNER_HASH],
  );
  const ownerRow = P.db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [ownerEmail]);
  if (!ownerRow.length) throw new Error(`c-sale: owner row not inserted for ${ownerEmail}`);
  const ownerId = Number(ownerRow[0][0]);
  log(`owner created: id=${ownerId} email=${ownerEmail}`);

  // the cheapest active platform plan the reseller plan maps to
  const planRow = P.db('SELECT id, name FROM {p}plans WHERE status = 1 ORDER BY monthly_price_minor ASC, id ASC LIMIT 1');
  if (!planRow.length) throw new Error('c-sale: no active platform plan to map a reseller plan to');
  const platformPlanId = Number(planRow[0][0]);
  log(`platform plan for mapping: id=${platformPlanId} name=${planRow[0][1]}`);

  // ── 2. ADMIN (second context) creates the reseller + opens signup ──────────
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
    await adminPage.waitForSelector('input[name="owner_user_id"]', { timeout: cfg.navTimeout });
    await adminPage.type('input[name="owner_user_id"]', String(ownerId));
    await adminPage.type('input[name="brand_name"]', brandName);
    await adminPage.type('input[name="custom_domain"]', host);
    await Promise.all([
      adminPage.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
      adminPage.evaluate(() => { document.querySelector('input[name="owner_user_id"]').form.submit(); }),
    ]);
    const rRow = P.db('SELECT id, active FROM {p}resellers WHERE custom_domain = ? LIMIT 1', [host]);
    if (!rRow.length) throw new Error(`admin store did not create a reseller for custom_domain=${host}`);
    resellerId = Number(rRow[0][0]);

    // Turn allow_signup ON via the row's edit form (create form has no such field).
    const editReady = await adminPage.evaluate((id) => {
      const form = document.querySelector(`#edit-${id} form[action$="/admin/resellers/${id}"]`);
      if (!form) return false;
      const allow = form.querySelector('input[type="checkbox"][name="allow_signup"]');
      const act = form.querySelector('input[type="checkbox"][name="active"]');
      if (!allow || !act) return false;
      allow.checked = true; act.checked = true;
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
    log(`admin created reseller id=${resellerId} domain=${host} (active=1, allow_signup=1) ✓`);
    await shot(adminPage, 'c-sale-admin-reseller');
    assertClean('admin panel', adminCol, adminNet);
  } finally {
    await adminPage.close().catch(() => {});
    if (adminCtx !== browser) await adminCtx.close().catch(() => {});
  }

  // ── 3. OWNER creates a reseller plan + mints a sale link ───────────────────
  const col = P.collectConsole(page);
  const ownerNet = watchNet(page);
  await P.loginOwner(page, { email: ownerEmail, pass: OWNER_PASS });

  // Create the plan through the real create-plan form (reseller.plans.store).
  const plansResp = await page.goto(cfg.baseUrl + '/reseller/plans', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!plansResp || plansResp.status() !== 200) throw new Error(`reseller plans page returned ${plansResp ? plansResp.status() : 'no response'}`);
  await P.assertRtl(page);
  const planName = `باقة سيل ${unix}`;
  await page.select('select[name="platform_plan_id"]', String(platformPlanId));
  await page.type('input[name="name"]', planName);
  await page.type('input[name="monthly_price"]', '9');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
    page.evaluate(() => { document.querySelector('select[name="platform_plan_id"]').form.submit(); }),
  ]);
  // The reseller is fresh, so its newest plan is the one just created. (db() runs
  // through the mysql CLI whose connection charset mangles an Arabic WHERE param,
  // so never filter/compare on the Arabic name — key off reseller_id + numerics.)
  const rpRow = P.db(
    'SELECT id, platform_plan_id, active FROM {p}reseller_plans WHERE reseller_id = ? ORDER BY id DESC LIMIT 1',
    [resellerId],
  );
  if (!rpRow.length) throw new Error(`reseller.plans.store did not create a plan for reseller ${resellerId}`);
  const resellerPlanId = Number(rpRow[0][0]);
  if (Number(rpRow[0][1]) !== platformPlanId) throw new Error(`reseller plan platform_plan_id mismatch: ${rpRow[0][1]} !== ${platformPlanId}`);
  if (String(rpRow[0][2]) !== '1') throw new Error(`new reseller plan should be active, got active=${rpRow[0][2]}`);
  log(`owner created reseller plan id=${resellerPlanId} → platform plan ${platformPlanId} ✓`);
  await shot(page, 'c-sale-plan-created');

  // Mint the sale link through the real route (reseller.plans.saleLink, JSON).
  const saleLink = await page.evaluate(async (id) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch(`/reseller/plans/${id}/sale-link`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    });
    return { status: r.status, body: await r.json().catch(() => ({})) };
  }, resellerPlanId);
  if (saleLink.status !== 200 || !saleLink.body.sale_token) {
    throw new Error(`reseller.plans.saleLink returned ${saleLink.status} ${JSON.stringify(saleLink.body)}`);
  }
  const saleToken = saleLink.body.sale_token;
  const tokRow = P.db('SELECT sale_token FROM {p}reseller_plans WHERE id = ? LIMIT 1', [resellerPlanId]);
  if (tokRow[0][0] !== saleToken) throw new Error(`sale_token not persisted (${tokRow[0][0]} !== ${saleToken})`);
  log(`sale link minted: /p/${saleToken} ✓`);
  assertClean('reseller portal', col, ownerNet);

  // ── Path A: reseller-hosted signup on the reseller host ────────────────────
  const aIdent = P.newIdentity('c', 'sub-signup');
  const aCtx = (await browser.createBrowserContext?.()) || browser;
  const aPage = await aCtx.newPage();
  await aPage.setViewport({ width: 390, height: 844 });
  const aCol = P.collectConsole(aPage);
  const aNet = watchNet(aPage);
  try {
    const formResp = await aPage.goto(`${hostBase}/reseller-signup/${resellerId}`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!formResp || formResp.status() !== 200) throw new Error(`reseller.signup.form returned ${formResp ? formResp.status() : 'no response'} on ${host}`);
    await P.assertRtl(aPage);
    const brandOnSignup = await aPage.evaluate((b) => document.documentElement.innerHTML.includes(b), brandName);
    if (!brandOnSignup) throw new Error('hosted signup page did not render the reseller brand');
    await aPage.waitForSelector('#signup-form input[name="name"]', { timeout: cfg.navTimeout });
    await aPage.type('#signup-form input[name="name"]', 'C4 Sub Signup');
    await aPage.type('#signup-form input[name="email"]', aIdent.email);
    await aPage.type('#signup-form input[name="password"]', aIdent.password);
    await shot(aPage, 'c-sale-hosted-signup');
    // The page submits via fetch and swaps in #success-card on 201.
    await aPage.click('#submit-btn, #signup-form button[type="submit"]');
    await aPage.waitForFunction(() => {
      const s = document.getElementById('success-card');
      const e = document.getElementById('error-box');
      // The page reveals these with the `hidden` attribute (it used to set style.display).
      return (s && !s.hidden) || (e && !e.hidden);
    }, { timeout: cfg.navTimeout });
    const aErr = await aPage.evaluate(() => {
      const e = document.getElementById('error-box');
      return e && !e.hidden ? (e.textContent || '').replace(/\s+/g, ' ').trim() : '';
    });
    if (aErr) throw new Error(`hosted signup was rejected: ${aErr}`);
    // db() ground truth: sub-owner exists, tagged to the reseller, on FREE.
    const aUser = P.db('SELECT id, reseller_id, group_id FROM {p}users WHERE email = ? LIMIT 1', [aIdent.email]);
    if (!aUser.length) throw new Error(`hosted signup did not create ${aIdent.email}`);
    if (Number(aUser[0][1]) !== resellerId) throw new Error(`hosted signup sub-owner reseller_id=${aUser[0][1]} !== ${resellerId}`);
    if (aUser[0][2] !== 'free') throw new Error(`hosted signup sub-owner should land on group_id=free, got ${aUser[0][2]}`);
    log(`Path A (hosted signup) ✓ — ${aIdent.email} id=${aUser[0][0]} reseller_id=${resellerId} group=free`);
    assertClean('hosted signup', aCol, aNet);
  } finally {
    await aPage.close().catch(() => {});
    if (aCtx !== browser) await aCtx.close().catch(() => {});
  }

  // ── Path B: plan sale link on the reseller host (offline/free purchase) ─────
  const bIdent = P.newIdentity('c', 'sub-sale');
  const bCtx = (await browser.createBrowserContext?.()) || browser;
  const bPage = await bCtx.newPage();
  await bPage.setViewport({ width: 390, height: 844 });
  const bCol = P.collectConsole(bPage);
  const bNet = watchNet(bPage);
  try {
    const payResp = await bPage.goto(`${hostBase}/p/${saleToken}`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!payResp || payResp.status() !== 200) throw new Error(`reseller.sale returned ${payResp ? payResp.status() : 'no response'} on ${host}`);
    await P.assertRtl(bPage);
    const saleHtml = await bPage.evaluate((b, pn) => ({
      brand: document.documentElement.innerHTML.includes(b),
      plan: document.documentElement.innerHTML.includes(pn),
    }), brandName, planName);
    if (!saleHtml.brand) throw new Error('sale link page did not render the reseller brand');
    if (!saleHtml.plan) throw new Error('sale link page did not render the plan name');
    await bPage.waitForSelector('form input[name="name"]', { timeout: cfg.navTimeout });
    await bPage.type('form input[name="name"]', 'C4 Sub Sale');
    await bPage.type('form input[name="email"]', bIdent.email);
    await bPage.type('form input[name="password"]', bIdent.password);
    await shot(bPage, 'c-sale-salelink-pay');
    // Standard form POST → reseller.sale.pay → redirect to /login on success.
    await Promise.all([
      bPage.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
      bPage.evaluate(() => { document.querySelector('form input[name="name"]').form.submit(); }),
    ]);
    const landedB = new URL(bPage.url()).pathname;
    if (landedB !== '/login') throw new Error(`sale pay should redirect to /login, landed on ${landedB}`);
    // db() ground truth: merchant exists, tagged, on FREE (paid plan only on a
    // live-verified charge — this test-mode charge lands them on free), and a
    // reseller subscription row was written.
    const bUser = P.db('SELECT id, reseller_id, group_id FROM {p}users WHERE email = ? LIMIT 1', [bIdent.email]);
    if (!bUser.length) throw new Error(`sale pay did not create ${bIdent.email}`);
    const subOwnerId = Number(bUser[0][0]);
    if (Number(bUser[0][1]) !== resellerId) throw new Error(`sale sub-owner reseller_id=${bUser[0][1]} !== ${resellerId}`);
    if (bUser[0][2] !== 'free') throw new Error(`sale sub-owner should land on group_id=free (test-mode charge), got ${bUser[0][2]}`);
    const subRow = P.db(
      'SELECT id, platform_plan_id FROM {p}reseller_customer_subscriptions WHERE reseller_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1',
      [resellerId, subOwnerId],
    );
    if (!subRow.length) throw new Error('sale pay did not write a reseller_customer_subscription row');
    log(`Path B (sale link) ✓ — ${bIdent.email} id=${subOwnerId} reseller_id=${resellerId} group=free, subscription id=${subRow[0][0]}`);
    assertClean('sale link', bCol, bNet);

    // ── Owner: the sale-link sub-owner logs in UNDER the reseller host ────────
    await loginOnHost(bPage, hostBase, { email: bIdent.email, pass: bIdent.password });
    const dashResp = await bPage.goto(`${hostBase}/dashboard`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!dashResp || dashResp.status() !== 200) throw new Error(`sub-owner /dashboard returned ${dashResp ? dashResp.status() : 'no response'} on ${host}`);
    await P.assertRtl(bPage);
    const dashBrand = await bPage.evaluate((b) => document.documentElement.innerHTML.includes(b), brandName);
    if (!dashBrand) throw new Error('sub-owner dashboard did not carry the reseller brand (brand_title)');
    // Discriminating white-label check: the dashboard chrome's app name is
    // `brand_title()` (BrandContext), surfaced in the og:title meta (the page
    // <title> is the per-page "Dashboard" label, so it is NOT the brand). For a
    // reseller sub-owner that resolves to the reseller's brand_name; for a plain
    // platform owner it would be the platform site_title ("juwri"). Assert
    // the brand is the reseller's and the platform default is not the brand.
    const dashOg = await bPage.evaluate(() => (document.querySelector('meta[property="og:title"]')?.content || '').trim());
    if (!dashOg.includes(brandName)) throw new Error(`dashboard brand (og:title) is not white-labeled: "${dashOg}"`);
    if (/juwri/i.test(dashOg)) throw new Error(`platform brand leaks into the dashboard brand (og:title): "${dashOg}"`);
    await shot(bPage, 'c-sale-subowner-dashboard');
    log(`sub-owner dashboard under ${host} carries reseller brand (og:title="${dashOg}") ✓`);

    // ── Menu: B2-lite crave store for the sub-owner, rendered on the host ─────
    const slug = `c4-store-${unix}`;
    const tokA = `C4A-${unix}`;
    const tokB = `C4B-${unix}`;
    // P1-04 slice 5: stores.account_id is NOT NULL — a raw store needs its owner's account (the app's
    // AccountProvisioner does this for model-created stores).
    let accRow = P.db('SELECT account_id FROM {p}users WHERE id = ? AND account_id IS NOT NULL', [subOwnerId]);
    if (!accRow.length) {
      P.db("INSERT INTO {p}accounts (owner_user_id, name, region, status, created_at, updated_at) VALUES (?, ?, 'global', 'active', NOW(), NOW())", [subOwnerId, `C4 ${unix}`]);
      accRow = P.db('SELECT id FROM {p}accounts WHERE owner_user_id = ? ORDER BY id DESC LIMIT 1', [subOwnerId]);
      P.db('UPDATE {p}users SET account_id = ? WHERE id = ?', [Number(accRow[0][0]), subOwnerId]);
    }
    const accountId = Number(accRow[0][0]);
    P.db(
      "INSERT INTO {p}stores (account_id, user_id, slug, title, color, created_at, updated_at) VALUES (?, ?, ?, ?, '#6366f1', NOW(), NOW())",
      [accountId, subOwnerId, slug, 'متجر الشريك C4'],
    );
    const postRow = P.db('SELECT id FROM {p}stores WHERE slug = ? LIMIT 1', [slug]);
    if (!postRow.length) throw new Error(`c-sale: store row not inserted for slug=${slug}`);
    const storeId = Number(postRow[0][0]);
    // The lane DB (a copied dev DB) carries orphan menu/category rows from
    // long-deleted posts; posts.id is reused by AUTO_INCREMENT, so a fresh post
    // can inherit stale menu rows keyed on the same store_id. Clear any such
    // rows so this store is a clean 1-category/2-item menu (WI B2-lite).
    P.db('DELETE FROM {p}items WHERE store_id = ?', [storeId]);
    P.db('DELETE FROM {p}categories WHERE store_id = ?', [storeId]);
    P.db(
      // P1-07: the store's settings are the typed stores.settings JSON, not store_options rows.
      "UPDATE {p}stores SET settings = JSON_MERGE_PATCH(COALESCE(settings, JSON_OBJECT()), JSON_OBJECT('core', JSON_OBJECT('store_template', 'crave', 'default_language', 'ar', 'menu_languages', JSON_ARRAY('ar')))) WHERE id = ?",
      [storeId],
    );
    P.db("INSERT INTO {p}categories (account_id, store_id, name, position, active) VALUES (?, ?, ?, 1, '1')", [accountId, storeId, `قسم C4 ${unix}`]);
    const catRow = P.db('SELECT id FROM {p}categories WHERE store_id = ? ORDER BY id DESC LIMIT 1', [storeId]);
    const catId = Number(catRow[0][0]);
    P.db(
      "INSERT INTO {p}items (account_id, category_id, store_id, name, price_minor, active, position, type) VALUES (?, ?, ?, ?, 1250, '1', 1, 'veg'), (?, ?, ?, ?, 700, '1', 2, 'veg')",
      [accountId, catId, storeId, `صنف ${tokA}`, accountId, catId, storeId, `صنف ${tokB}`],
    );
    const itemRows = P.db('SELECT id FROM {p}items WHERE store_id = ? ORDER BY position ASC', [storeId]);
    if (itemRows.length !== 2) throw new Error(`c-sale: expected 2 menu items, got ${itemRows.length}`);
    const itemIds = itemRows.map((r) => Number(r[0]));
    log(`B2-lite store built: slug=${slug} post=${storeId} cat=${catId} items=${itemIds.join(',')}`);

    const storeResp = await bPage.goto(`${hostBase}/${slug}`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    if (!storeResp || storeResp.status() !== 200) throw new Error(`storefront /${slug} returned ${storeResp ? storeResp.status() : 'no response'} on ${host}`);
    await P.assertRtl(bPage);
    // The crave menu hydrates from window.CRAVE.items (keyed by item id) into #cvGrid.
    await bPage.waitForFunction((ids) => {
      const items = (window.CRAVE && window.CRAVE.items) || null;
      if (!items) return false;
      return ids.every((id) => items[String(id)] || items[id]);
    }, { timeout: cfg.navTimeout }, itemIds);
    const menuState = await bPage.evaluate((ids, ta, tb) => {
      const items = (window.CRAVE && window.CRAVE.items) || {};
      const names = Object.values(items).map((it) => it.name || '');
      const grid = document.querySelector('#cvGrid');
      return {
        count: Object.keys(items).length,
        hasA: names.some((n) => n.includes(ta)),
        hasB: names.some((n) => n.includes(tb)),
        idsPresent: ids.every((id) => items[String(id)] || items[id]),
        cards: grid ? grid.querySelectorAll('[data-open], .grid > *').length : 0,
      };
    }, itemIds, tokA, tokB);
    if (!menuState.idsPresent) throw new Error(`storefront menu missing the sub-owner items (window.CRAVE.items has ${menuState.count})`);
    if (!menuState.hasA || !menuState.hasB) throw new Error(`storefront menu item tokens not hydrated (hasA=${menuState.hasA} hasB=${menuState.hasB})`);
    if (menuState.cards < 2) throw new Error(`storefront grid rendered ${menuState.cards} cards, expected ≥ 2`);
    await P.assertNoOverflow(bPage);
    await shot(bPage, 'c-sale-subowner-storefront');
    log(`storefront /${slug} on ${host}: 2 items hydrated, ${menuState.cards} cards, RTL ✓`);

    // Storefront console/CSP hygiene (BENIGN_RES tolerated; enforced CSP is hard).
    assertClean('sub-owner storefront', bCol, bNet);
  } finally {
    await bPage.close().catch(() => {});
    if (bCtx !== browser) await bCtx.close().catch(() => {});
  }

  col.stop();
  log(`c-sale PASS — reseller id=${resellerId} host=${host}; sub-owners: signup=${aIdent.email}, sale=${bIdent.email} / ${bIdent.password} (reseller_id=${resellerId})`);
};
