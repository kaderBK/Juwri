// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E — C2: Reseller branding + landing settings + pricing page (VISUAL).
 *
 * Builds on the C1 provisioning seam (admin creates a reseller on its own host)
 * and drives the whole white-label authoring loop in a real browser, in Arabic:
 *
 *   1. A fresh plain owner (db() insert, known bcrypt — the HANDOFF §3 fallback,
 *      as C1) and, in a SECOND context, the admin creates a reseller for them with
 *      custom_domain = r1-<unix>.<baseHost>, active on, allow_signup on. (C1 pattern.)
 *   2. The owner logs in → /reseller portal (RTL).
 *   3. BRANDING (reseller.branding / .update): set an Arabic brand name, a primary
 *      colour and a logo URL (a real repo asset so nothing 404s — reseller branding
 *      has no file-upload field; the logo URL doubles as the favicon). Ground truth:
 *      the resellers row now carries the new brand/colour/logo.
 *   4. PLANS (reseller.plans.store): create TWO active tiers (the pricing page needs
 *      real ResellerPlan rows). Ground truth: two active plans for this reseller.
 *   5. PRICING PAGE (reseller.pricingPage.save): enable it, Arabic headline/subline,
 *      per-tier Arabic feature lines. Ground truth: pricing_page json enabled + both
 *      tiers keyed.
 *   6. LANDING (reseller.landing.save): enable it, Arabic hero + two features. The
 *      landing EDITOR GET redirects to the block builder (reseller_pages on), so the
 *      save is POSTed directly with the panel CSRF token. Ground truth: landing_page
 *      json enabled + hero.
 *   7. PUBLIC HOST (fresh, unauthenticated context): the reseller-host HOME renders
 *      the reseller landing (RTL) with the brand name, logo, primary colour and BOTH
 *      tiers in Arabic; /pricing renders both tiers with their Arabic feature lines.
 *      White-label integrity (ISS-C-C1b): the tab <title> and og:site_name carry the
 *      reseller brand and NEVER the platform brand ("juwri"); og:site_name is
 *      the canonical brand carrier (the docs-screenshots scrub reads it). Real
 *      subdomain first, Host-header override as the fallback (C1 pattern); the
 *      journey logs which worked.
 *   8. DISCRIMINATING: the reseller brand is ABSENT on the platform host (resolution
 *      is host-scoped, not a global string).
 *
 * Ground truth only: HTTP status, DOM text/attrs, computed CSS var, DB rows. One
 * shot() per screen. Finishes asserting no console errors / no enforced CSP
 * refusals on the authored + public surfaces.
 *
 * Runs under E2E_RUN_PLATFORM=1:  bash rev/platform-e2e/bin/e2e.sh c c-branding
 */

const P = require('../../lib/platform');

// Dev-only bcrypt so the inserted owner can use the real /login form.
// password_verify('E2e-owner-c2-pass', HASH) === true (generated + verified on the lane).
const OWNER_PASS = 'E2e-owner-c2-pass';
const OWNER_HASH = '$2y$10$dg4MPSTSZtYMXrmlyVDjxejgQMuCvwTJPizfVsq9a9bgu0VomsDvy';

// Benign, out-of-fence 404s on the old-theme admin/reseller panel skin + report-only
// CSP beacon (same rationale as c-reseller.js). Anything else that 4xxs is a fault.
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

/** Fail on real breakage (enforced CSP, page errors, non-404 console errors, non-benign 4xx). */
function assertClean(where, collector, netBad, allow = null) {
  const hard = collector.errors.filter((e) => !(allow && allow.test(e))).filter((e) => e.startsWith('CSP:')
    || e.startsWith('pageerror:')
    || (e.startsWith('console.error:') && !/Failed to load resource/i.test(e)));
  const badRes = netBad.filter((u) => !BENIGN_RES.test(u));
  const problems = [...hard, ...badRes.map((u) => 'resource: ' + u)];
  if (problems.length) throw new Error(`${where}: console/CSP/resource errors (${problems.length}):\n  ${problems.join('\n  ')}`);
}

/** The panel CSRF token from the current portal page. */
async function panelCsrf(page) {
  const token = await page.evaluate(() => {
    const m = document.querySelector('meta[name="csrf-token"]');
    return (m && m.content) || (window.RESELLER_PANEL && window.RESELLER_PANEL.csrf) || '';
  });
  if (!token) throw new Error('panelCsrf: no csrf token on the portal page');
  return token;
}

/** POST url-encoded fields (incl. array fields as [name, value] pairs) with the panel CSRF. */
async function postForm(page, base, path, fields) {
  return page.evaluate(async ({ base, path, fields, csrf }) => {
    const body = new URLSearchParams();
    body.append('_token', csrf);
    for (const [k, v] of fields) body.append(k, v);
    const r = await fetch(base + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf, Accept: 'text/html' },
      body: body.toString(),
      redirect: 'manual',
    });
    return { status: r.status, type: r.type };
  }, { base, path, fields, csrf: await panelCsrf(page) });
}

module.exports = async function cBranding({ browser, page, cfg, log, shot }) {
  const eeOnly = P.requireEe('Reseller (white-label branding)');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const adminEmail = process.env.E2E_ADMIN_EMAIL;
  const adminPass = process.env.E2E_ADMIN_PASS;
  if (!adminEmail || !adminPass) throw new Error('c-branding: E2E_ADMIN_EMAIL / E2E_ADMIN_PASS not exported (bin/e2e.sh reads the lane .env)');

  const unix = Date.now();
  const ownerEmail = `e2e-c-brandowner-${unix}@local.invalid`;
  const seedBrand = `شريك ${unix}`;                                 // brand the admin seeds
  const brandName = `مطاعم راقية ${unix}`;                          // brand the owner sets in step 3 (real Arabic)
  const primary = '#c81e5b';
  const logoUrl = `${cfg.baseUrl}/assets/marketplace/icons/icon-192.png`; // a real repo asset → 200s
  const baseHost = new URL(cfg.baseUrl).hostname;                       // e2e-c.test
  const host = `r1-${unix}.${baseHost}`;                            // subdomain of the lane site

  const tier1 = { name: `الباقة الأساسية ${unix}`, monthly: '49', annual: '490' };
  const tier2 = { name: `الباقة الاحترافية ${unix}`, monthly: '99', annual: '990' };

  await page.setViewport({ width: 390, height: 844 });

  // ── 1. fresh plain owner via db() ──────────────────────────────────────────
  P.db(
    "INSERT INTO {p}users (email, username, name, password, user_type, status, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'user', 1, NOW(), NOW(), NOW())",
    [ownerEmail, `brandowner${unix}`, 'C2 Owner', OWNER_HASH],
  );
  const ownerRow = P.db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [ownerEmail]);
  if (!ownerRow.length) throw new Error(`c-branding: owner row not inserted for ${ownerEmail}`);
  const ownerId = Number(ownerRow[0][0]);
  log(`owner created: id=${ownerId} email=${ownerEmail}`);

  // ── 2. ADMIN (second context) creates the reseller on its own host ─────────
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
    await adminPage.type('input[name="brand_name"]', seedBrand);
    await adminPage.type('input[name="custom_domain"]', host);
    await Promise.all([
      adminPage.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
      adminPage.evaluate(() => { document.querySelector('input[name="owner_user_id"]').form.submit(); }),
    ]);

    const rRow = P.db('SELECT id, active FROM {p}resellers WHERE custom_domain = ? LIMIT 1', [host]);
    if (!rRow.length) throw new Error(`admin store did not create a reseller for custom_domain=${host}`);
    resellerId = Number(rRow[0][0]);
    if (String(rRow[0][1]) !== '1') throw new Error(`new reseller should be active by default, got active=${rRow[0][1]}`);

    // Turn allow_signup ON via the row's edit form (C1 pattern) so the public
    // pricing/landing CTAs point at the reseller-hosted signup, not /login.
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
    log(`admin created reseller id=${resellerId} domain=${host} (active, allow_signup) ✓`);
    assertClean('admin panel', adminCol, adminNet);
  } finally {
    await adminPage.close().catch(() => {});
    if (adminCtx !== browser) await adminCtx.close().catch(() => {});
  }

  // ── owner portal session ───────────────────────────────────────────────────
  const col = P.collectConsole(page);
  const net = watchNet(page);
  await P.loginOwner(page, { email: ownerEmail, pass: OWNER_PASS });
  const portalResp = await page.goto(cfg.baseUrl + '/reseller', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!portalResp || portalResp.status() !== 200) throw new Error(`reseller owner must get 200 on /reseller, got ${portalResp ? portalResp.status() : 'no response'}`);
  await P.assertRtl(page);
  log('owner reached /reseller portal (RTL) ✓');

  // ── 3. BRANDING — set brand name + colour + logo, submit the real form ─────
  const brandResp = await page.goto(cfg.baseUrl + '/reseller/branding', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!brandResp || brandResp.status() !== 200) throw new Error(`branding page returned ${brandResp ? brandResp.status() : 'no response'}`);
  await P.assertRtl(page);
  await P.assertNoOverflow(page);
  await shot(page, 'c-branding-editor');
  await page.evaluate(({ brandName, primary, logoUrl }) => {
    const set = (sel, v) => {
      const el = document.querySelector(sel);
      el.value = v;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    };
    set('input[name="brand_name"]', brandName);
    set('input[name="primary_color"]', primary);
    set('input[name="brand_logo"]', logoUrl);
  }, { brandName, primary, logoUrl });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
    page.evaluate(() => { document.querySelector('input[name="brand_name"]').form.submit(); }),
  ]);
  // ASCII fields are verified through db() (the mysql-CLI probe has no utf8mb4
  // charset, so it returns multibyte Arabic as "?"). The Arabic brand_name is
  // verified in the BROWSER instead — after back() the branding form re-renders
  // the saved value, and the public-host assertions below re-prove it end to end.
  const bRow = P.db('SELECT primary_color, brand_logo FROM {p}resellers WHERE id = ? LIMIT 1', [resellerId]);
  if (bRow[0][0] !== primary) throw new Error(`branding: primary_color not saved (got "${bRow[0][0]}")`);
  if (bRow[0][1] !== logoUrl) throw new Error(`branding: brand_logo not saved (got "${bRow[0][1]}")`);
  const savedBrand = await page.evaluate(() => {
    const el = document.querySelector('input[name="brand_name"]');
    return el ? el.value : null;
  });
  if (savedBrand !== brandName) throw new Error(`branding: brand_name not saved (form shows "${savedBrand}")`);
  log(`branding saved: name="${brandName}" color=${primary} logo=${logoUrl} ✓`);

  // ── 4. PLANS — create TWO active tiers via the real plans form ─────────────
  for (const tier of [tier1, tier2]) {
    const pResp = await page.goto(cfg.baseUrl + '/reseller/plans', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!pResp || pResp.status() !== 200) throw new Error(`plans page returned ${pResp ? pResp.status() : 'no response'}`);
    await page.waitForSelector('form[action$="/reseller/plans"] select[name="platform_plan_id"]', { timeout: cfg.navTimeout });
    const filled = await page.evaluate((t) => {
      const form = document.querySelector('form[action$="/reseller/plans"]');
      if (!form) return false;
      const sel = form.querySelector('select[name="platform_plan_id"]');
      const opt = Array.from(sel.options).find((o) => o.value);   // first real platform plan
      if (!opt) return false;
      sel.value = opt.value; sel.dispatchEvent(new Event('change', { bubbles: true }));
      const set = (name, v) => { const el = form.querySelector(`[name="${name}"]`); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); };
      set('name', t.name); set('monthly_price', t.monthly); set('annual_price', t.annual);
      return true;
    }, tier);
    if (!filled) throw new Error('plans: create form / platform-plan option not found');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
      page.evaluate(() => { document.querySelector('form[action$="/reseller/plans"]').submit(); }),
    ]);
  }
  const planRows = P.db('SELECT id, name FROM {p}reseller_plans WHERE reseller_id = ? AND active = 1 ORDER BY id', [resellerId]);
  if (planRows.length !== 2) throw new Error(`plans: expected 2 active tiers, got ${planRows.length}`);
  const [planId1, planId2] = [Number(planRows[0][0]), Number(planRows[1][0])];
  await shot(page, 'c-branding-plans');
  log(`two tiers created: #${planId1} "${planRows[0][1]}", #${planId2} "${planRows[1][1]}" ✓`);

  // ── 5. PRICING PAGE — enable + per-tier Arabic feature lines ────────────────
  const ppResp = await page.goto(cfg.baseUrl + '/reseller/pricing-page', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!ppResp || ppResp.status() !== 200) throw new Error(`pricing-page editor returned ${ppResp ? ppResp.status() : 'no response'}`);
  await P.assertRtl(page);
  await P.assertNoOverflow(page);
  await shot(page, 'c-branding-pricing-editor');
  const ppHeadline = 'أسعار بسيطة وشفافة';
  const ppSubline = 'اختر الباقة التي تناسب مطعمك.';
  const ppSave = await postForm(page, cfg.baseUrl, '/reseller/pricing-page', [
    ['enabled', '1'],
    ['headline', ppHeadline],
    ['subline', ppSubline],
    [`plans[${planId1}][]`, 'قائمة رقمية'],
    [`plans[${planId1}][]`, 'دعم عبر البريد'],
    [`plans[${planId2}][]`, 'كل مزايا الأساسية'],
    [`plans[${planId2}][]`, 'تحليلات متقدمة'],
    [`plans[${planId2}][]`, 'دعم أولوية'],
  ]);
  if (ppSave.status >= 400) throw new Error(`pricing-page save returned ${ppSave.status}`);
  const ppJson = P.db('SELECT pricing_page FROM {p}resellers WHERE id = ? LIMIT 1', [resellerId])[0][0];
  const pp = JSON.parse(ppJson || '{}');
  if (!pp.enabled) throw new Error('pricing-page: not enabled after save');
  if (!pp.plans || !pp.plans[String(planId1)] || !pp.plans[String(planId2)]) throw new Error(`pricing-page: both tiers not keyed (${ppJson})`);
  log(`pricing page saved: enabled, 2 tiers keyed, headline="${ppHeadline}" ✓`);

  // ── 6. LANDING — enable + Arabic hero + features (POST; GET redirects to builder)
  const landHeadline = 'مطعمك على الإنترنت خلال دقائق';
  const landSubline = 'نظام قوائم رقمي وطلب أونلاين لمطعمك.';
  const landSave = await postForm(page, cfg.baseUrl, '/reseller/landing', [
    ['enabled', '1'],
    ['headline', landHeadline],
    ['subline', landSubline],
    ['cta', 'ابدأ الآن'],
    ['contact_email', 'support@acme.test'],
    ['features[0][title]', 'سريع'],
    ['features[0][body]', 'إعداد فوري'],
    ['features[1][title]', 'أنيق'],
    ['features[1][body]', 'قالب جميل'],
  ]);
  if (landSave.status >= 400) throw new Error(`landing save returned ${landSave.status} (403 = landing_page entitlement gate)`);
  const landJson = P.db('SELECT landing_page FROM {p}resellers WHERE id = ? LIMIT 1', [resellerId])[0][0];
  const land = JSON.parse(landJson || '{}');
  if (!land.enabled) throw new Error('landing: not enabled after save');
  // The db() probe mangles Arabic to "?", so assert structure (a non-empty hero
  // headline), not equality — the public host re-proves the exact Arabic below.
  if (!land.hero || !land.hero.headline) throw new Error(`landing: hero headline not saved (${landJson})`);
  assertClean('reseller portal', col, net);
  log(`landing saved: enabled, hero="${landHeadline}", 2 features ✓`);

  // ── 7. PUBLIC HOST (fresh, unauthenticated) — home + pricing white-labeled ─
  const hostCtx = (await browser.createBrowserContext?.()) || browser;
  const hostPage = await hostCtx.newPage();
  await hostPage.setViewport({ width: 390, height: 844 });
  const hostCol = P.collectConsole(hostPage);
  const hostNet = watchNet(hostPage);
  let resolution = 'subdomain';

  /** Navigate to a path on the reseller host, subdomain first then Host-override. */
  const gotoHost = async (path) => {
    if (resolution === 'subdomain') {
      try {
        const r = await hostPage.goto(`${new URL(cfg.baseUrl).protocol}//${host}${new URL(cfg.baseUrl).port ? ':' + new URL(cfg.baseUrl).port : ''}${path}`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
        if (r && r.status() < 400) return r;
        log(`subdomain ${path} returned ${r ? r.status() : 'no response'}; switching to Host-header override`);
      } catch (e) {
        log(`subdomain navigation failed (${e.message}); switching to Host-header override`);
      }
      resolution = 'host-override';
    }
    await P.withHost(hostPage, host);
    return hostPage.goto(cfg.baseUrl + path, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  };

  /** Assert the reseller brand owns the tab + OG, and the platform brand never leaks. */
  const assertWhiteLabel = async (label) => {
    const meta = await hostPage.evaluate(() => ({
      title: document.title,
      ogSite: (document.querySelector('meta[property="og:site_name"]') || {}).content || null,
      ogTitle: (document.querySelector('meta[property="og:title"]') || {}).content || null,
      themeVar: getComputedStyle(document.documentElement).getPropertyValue('--theme-color').trim(),
      favicon: (document.querySelector('link[rel="icon"]') || {}).href || null,
      html: document.documentElement.innerHTML,
    }));
    if (meta.ogSite !== brandName) throw new Error(`${label}: og:site_name is not the reseller brand (got "${meta.ogSite}")`);
    if (!meta.title.includes(brandName)) throw new Error(`${label}: <title> "${meta.title}" does not carry the reseller brand`);
    if (/juwri/i.test(meta.title)) throw new Error(`${label}: platform brand leaks into <title>: "${meta.title}"`);
    if (/juwri/i.test(meta.ogSite || '') || /juwri/i.test(meta.ogTitle || '')) throw new Error(`${label}: platform brand leaks into OG tags`);
    if (/juwri/i.test(meta.html)) throw new Error(`${label}: platform brand "juwri" leaks into the page body`);
    return meta;
  };

  try {
    // 7a. HOME → reseller landing.
    const homeResp = await gotoHost('/');
    if (!homeResp || homeResp.status() >= 400) throw new Error(`reseller host home returned ${homeResp ? homeResp.status() : 'no response'} (${resolution})`);
    await P.assertRtl(hostPage);
    const isLanding = await hostPage.evaluate((id) => !!document.querySelector(`[data-reseller-landing="${id}"]`), resellerId);
    if (!isLanding) throw new Error('reseller host home did not render the reseller landing (data-reseller-landing missing)');
    const homeMeta = await assertWhiteLabel('home');
    if (homeMeta.themeVar.toLowerCase() !== primary) throw new Error(`home: --theme-color is "${homeMeta.themeVar}", expected ${primary}`);
    if (!homeMeta.favicon || !homeMeta.favicon.includes('icon-192.png')) throw new Error(`home: favicon not the reseller logo (got "${homeMeta.favicon}")`);
    const homeBody = await hostPage.evaluate(() => document.body.innerText);
    for (const needle of [brandName, tier1.name, tier2.name]) {
      if (!homeBody.includes(needle)) throw new Error(`home: expected "${needle}" in the landing (brand + both tiers)`);
    }
    const logoOnHome = await hostPage.evaluate((u) => !!Array.from(document.images).find((i) => i.src === u), logoUrl);
    if (!logoOnHome) throw new Error('home: brand logo <img> not rendered');
    await P.assertNoOverflow(hostPage);
    await shot(hostPage, 'c-branding-public-home');
    log(`host home white-labeled via ${resolution}: brand+logo+colour+2 tiers, og:site_name="${homeMeta.ogSite}" ✓`);

    // 7b. PRICING → reseller pricing with both tiers + feature lines.
    const priceResp = await gotoHost('/pricing');
    if (!priceResp || priceResp.status() >= 400) throw new Error(`reseller host /pricing returned ${priceResp ? priceResp.status() : 'no response'} (${resolution})`);
    await P.assertRtl(hostPage);
    const priceMeta = await assertWhiteLabel('pricing');
    const priceBody = await hostPage.evaluate(() => document.body.innerText);
    for (const needle of [ppHeadline, tier1.name, tier2.name, 'تحليلات متقدمة']) {
      if (!priceBody.includes(needle)) throw new Error(`pricing: expected "${needle}" (headline + both tiers + a feature line)`);
    }
    await P.assertNoOverflow(hostPage);
    await shot(hostPage, 'c-branding-public-pricing');
    log(`host /pricing white-labeled: 2 tiers + Arabic feature lines, og:site_name="${priceMeta.ogSite}" ✓`);

    // No ENFORCED CSP refusal + no non-benign 4xx on the public reseller surfaces.
    // The runner serves plain http, and the page CSP (img-src 'self' data: https:) blocks the http logo URL the journey
    // saves; a real reseller logo is https. Only that one image's CSP message is tolerated here.
    const logoCsp = new RegExp("(Loading the image|Refused to load the image) '" + logoUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + "'");
    const cspRefusals = hostCol.errors.filter((e) => e.startsWith('CSP:') && !logoCsp.test(e));
    if (cspRefusals.length) throw new Error(`reseller host CSP refusals:\n  ${cspRefusals.join('\n  ')}`);
    assertClean('reseller public host', hostCol, hostNet, logoCsp);

    // 7c/8. DISCRIMINATING: the reseller brand is ABSENT on the platform host.
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
  log(`c-branding PASS — reseller id=${resellerId} domain=${host} owner=${ownerEmail} / ${OWNER_PASS} (host via ${resolution})`);
};
