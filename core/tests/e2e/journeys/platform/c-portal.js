// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E — C5: reseller customers portal + impersonation.
 *
 * Provisions a fresh reseller (the C1 seam, inline) and a sub-owner restaurant
 * account under it, then drives the whole reseller→customer support journey in
 * Arabic on the lane site:
 *
 *   Setup     A plain owner is inserted via db() (HANDOFF §3 fallback, known
 *             bcrypt hash so the real /login works). The admin (second browser
 *             context) creates a reseller for that owner (owner_user_id,
 *             brand_name in Arabic, custom_domain = rc5-<unix>.e2e-c.test) and
 *             turns active + allow_signup ON through the row's edit form.
 *
 *   Sub-owner The reseller owner logs in, opens the portal (RTL) and creates a
 *             sub-owner restaurant account through the panel's own "Add
 *             restaurant" form (reseller.customers.store) → db() proves the row
 *             carries this reseller's id.
 *
 *   List      /reseller/customers renders (RTL) and shows that sub-owner — the
 *             row is located by email and asserted to carry the Active badge.
 *
 *   Impersonate  On the customer detail page the "Impersonate" control opens the
 *             Alpine modal; a reason is typed and the form is submitted to
 *             reseller.customers.impersonate (password.confirm-gated — see the
 *             PHPUnit slice; a browser session that logged in through /login is
 *             inside the 3h confirm window, so this run takes the happy path).
 *             The browser must LAND ON THE CUSTOMER DASHBOARD, not on a raw JSON
 *             payload: the panel form is an ordinary POST, so an unconditional
 *             response()->json() dumps {"ok":true,…} into the viewport. db()
 *             proves the customer.impersonate audit row carries the reason.
 *
 *   Act-as    The customer dashboard renders with the sticky support-session
 *             banner (customer name + reseller brand) and an exit control.
 *
 *   Exit      The banner's Exit button POSTs reseller.impersonate.exit → the
 *             browser is back on /reseller as the RESELLER OWNER (banner gone,
 *             portal brand present). db() proves the matching
 *             customer.impersonate.exit audit row.
 *
 *   Audit     /reseller/audit-log renders (RTL) and lists BOTH actions.
 *
 *   Security  /reseller/security renders (RTL): the 2FA card, its
 *             enabled/disabled state and the enable form.
 *
 *   Help      /reseller/help renders (RTL) in the ar locale and links the
 *             reseller guides; one guide page opens.
 *
 * Ground truth only: HTTP status, DOM text/attrs, DB rows. One shot() per
 * screen. Finishes asserting no console errors / no enforced CSP refusals and no
 * real 4xx sub-resource on the surfaces this WI owns.
 *
 * Runs under E2E_RUN_PLATFORM=1:  bash rev/platform-e2e/bin/e2e.sh c c-portal
 */

const P = require('../../lib/platform');

// A fixed, dev-only bcrypt hash so the inserted reseller-owner can use the real
// /login form. password_verify('E2e-owner-c1-pass', HASH) === true (the same
// hash c-reseller.js / c-sale.js use; verified locally).
const OWNER_PASS = 'E2e-owner-c1-pass';
const OWNER_HASH = '$2y$10$L3VAT6Coa8OxNvrN53pvMeQHzCGK.ZRfBECbY.GmYfmhMb1g.j8zm';

// Non-faults to tolerate (same set as c-reseller.js / c-sale.js): panel-skin and
// static 404s, plus the advisory report-only /csp-report beacon. Anything else
// that 4xxs (a panel bundle, an api/xhr) is a real fault.
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

/** Collapse a page's visible text for assertions. */
const bodyText = (page) => page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' ').trim());

/** Formal name of the audit action rows this journey writes. */
const IMPERSONATE_ACTION = 'customer.impersonate';
const EXIT_ACTION = 'customer.impersonate.exit';

module.exports = async function cPortal({ browser, page, cfg, log, shot, sleep }) {
  const eeOnly = P.requireEe('the Reseller portal');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const adminEmail = process.env.E2E_ADMIN_EMAIL;
  const adminPass = process.env.E2E_ADMIN_PASS;
  if (!adminEmail || !adminPass) throw new Error('c-portal: E2E_ADMIN_EMAIL / E2E_ADMIN_PASS not exported (bin/e2e.sh reads the lane .env)');

  const unix = Date.now();
  const ownerEmail = `e2e-c-portalowner-${unix}@local.invalid`;
  const brandName = `شريك البوابة ${unix}`;             // real Arabic reseller brand
  const baseHost = new URL(cfg.baseUrl).host;           // e2e-c.test
  const host = `rc5-${unix}.${baseHost}`;               // Herd serves the subdomain
  // Arabic copy is what a real reseller types; the ASCII token is the reliable
  // db() probe (the mysql CLI mangles an Arabic LIKE param).
  const reasonToken = `C5-${unix}`;
  const reason = `جلسة دعم ${reasonToken}`;

  await page.setViewport({ width: 390, height: 844 });

  // ── 1. fresh plain owner via db() ──────────────────────────────────────────
  P.db(
    "INSERT INTO {p}users (email, username, name, password, user_type, status, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'user', 1, NOW(), NOW(), NOW())",
    [ownerEmail, `portalowner${unix}`, 'C5 Owner', OWNER_HASH],
  );
  const ownerRow = P.db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [ownerEmail]);
  if (!ownerRow.length) throw new Error(`c-portal: owner row not inserted for ${ownerEmail}`);
  const ownerId = Number(ownerRow[0][0]);
  log(`owner created: id=${ownerId} email=${ownerEmail}`);

  // ── 2. ADMIN (second context) creates the reseller and opens signup ────────
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

    // Turn active + allow_signup ON via the row's edit form (the create form has
    // neither field — ISS-C-C1a).
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
    await shot(adminPage, 'c-portal-admin-reseller');
    assertClean('admin panel', adminCol, adminNet);
  } finally {
    await adminPage.close().catch(() => {});
    if (adminCtx !== browser) await adminCtx.close().catch(() => {});
  }

  // ── 3. OWNER opens the portal (RTL, reseller brand) ────────────────────────
  const col = P.collectConsole(page);
  const net = watchNet(page);
  await P.loginOwner(page, { email: ownerEmail, pass: OWNER_PASS });

  const portalResp = await page.goto(cfg.baseUrl + '/reseller', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!portalResp || portalResp.status() !== 200) throw new Error(`reseller portal returned ${portalResp ? portalResp.status() : 'no response'}`);
  await P.assertRtl(page);
  const portalBrand = await page.evaluate((b) => document.documentElement.innerHTML.includes(b), brandName);
  if (!portalBrand) throw new Error('reseller portal did not render the reseller brand');
  await shot(page, 'c-portal-home');
  log(`reseller portal renders on ${cfg.baseUrl} (RTL, brand "${brandName}") ✓`);

  // ── 4. the reseller creates a sub-owner restaurant account ─────────────────
  const subIdent = P.newIdentity('c', 'sub-portal');
  const custResp = await page.goto(cfg.baseUrl + '/reseller/customers', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!custResp || custResp.status() !== 200) throw new Error(`reseller customers page returned ${custResp ? custResp.status() : 'no response'}`);
  await P.assertRtl(page);
  // The create form lives inside a Bootstrap collapse — open it before typing.
  await page.evaluate(() => {
    const btn = [...document.querySelectorAll('[aria-controls="create-customer-form"]')][0];
    if (btn) btn.click();
  });
  await page.waitForSelector('#create-customer-form input[name="email"]', { timeout: cfg.navTimeout });
  await page.type('#create-customer-form input[name="name"]', 'C5 Sub Portal');
  await page.type('#create-customer-form input[name="email"]', subIdent.email);
  await page.type('#create-customer-form input[name="password"]', subIdent.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
    page.evaluate(() => { document.querySelector('#create-customer-form input[name="email"]').form.submit(); }),
  ]);
  const subRow = P.db('SELECT id, reseller_id, status FROM {p}users WHERE email = ? LIMIT 1', [subIdent.email]);
  if (!subRow.length) throw new Error(`reseller.customers.store did not create ${subIdent.email}`);
  const subOwnerId = Number(subRow[0][0]);
  if (Number(subRow[0][1]) !== resellerId) throw new Error(`sub-owner reseller_id=${subRow[0][1]} !== ${resellerId}`);
  if (String(subRow[0][2]) !== '1') throw new Error(`sub-owner should be active, got status=${subRow[0][2]}`);
  log(`sub-owner created: id=${subOwnerId} ${subIdent.email} reseller_id=${resellerId} ✓`);
  await shot(page, 'c-portal-customer-created');

  // ── 5. customers list SHOWS the sub-owner ──────────────────────────────────
  const listResp = await page.goto(cfg.baseUrl + '/reseller/customers', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!listResp || listResp.status() !== 200) throw new Error(`reseller customers list returned ${listResp ? listResp.status() : 'no response'}`);
  await P.assertRtl(page);
  const listed = await page.evaluate((email, id) => {
    const rows = [...document.querySelectorAll('table tbody tr')];
    const row = rows.find((tr) => (tr.textContent || '').includes(email));
    if (!row) return { found: false, rows: rows.length };
    return {
      found: true,
      rows: rows.length,
      // The row's View link identifies which customer record the portal is showing.
      href: row.querySelector('a[href*="/reseller/customers/"]')?.getAttribute('href') || '',
      detail: row.querySelector(`a[href$="/reseller/customers/${id}"]`) !== null,
      text: (row.innerText || '').replace(/\s+/g, ' ').trim(),
    };
  }, subIdent.email, subOwnerId);
  if (!listed.found) throw new Error(`customers list (${listed.rows} rows) does not show the sub-owner ${subIdent.email}`);
  if (!listed.detail) throw new Error(`customers list row for ${subIdent.email} does not link to its detail page (href="${listed.href}")`);
  // The Active badge is rendered from $c->status — assert the label is there.
  if (!/نشط|Active/i.test(listed.text)) throw new Error(`customers list row has no active badge: "${listed.text}"`);
  await shot(page, 'c-portal-customers-list');
  log(`customers list shows the sub-owner (row links to /reseller/customers/${subOwnerId}) ✓`);

  // ── 6. customer detail page ────────────────────────────────────────────────
  require('../../lib/reseller-fixtures').giveCustomerAStore(P, subOwnerId, `portal${unix}`); // support login needs a store + its consent (reseller rework v2)
  const detailResp = await page.goto(`${cfg.baseUrl}/reseller/customers/${subOwnerId}`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!detailResp || detailResp.status() !== 200) throw new Error(`customer detail returned ${detailResp ? detailResp.status() : 'no response'}`);
  await P.assertRtl(page);
  const detailText = await bodyText(page);
  if (!detailText.includes(subIdent.email)) throw new Error(`customer detail page does not show ${subIdent.email}`);

  // ── 7. IMPERSONATE — open the modal, give a reason, submit ─────────────────
  const opened = await page.evaluate(() => {
    const btn = [...document.querySelectorAll('button')]
      // The panel opens its modals through one Alpine `modal` state: `@click="modal = 'impersonate'"`
      // (the control used to be a dedicated `impersonate = true` flag).
      .find((b) => /impersonate\s*=\s*true|modal\s*=\s*'impersonate'/.test((b.getAttribute('@click') || '').replace(/\s+/g, ' ')));
    if (!btn) return false;
    btn.click();
    return true;
  });
  if (!opened) throw new Error('the customer detail page has no "Impersonate" control');
  await page.waitForSelector('form input[name="reason"]', { timeout: cfg.navTimeout });
  await page.type('form input[name="reason"]', reason);
  await shot(page, 'c-portal-impersonate-modal');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
    page.evaluate(() => { document.querySelector('form input[name="reason"]').form.submit(); }),
  ]);

  // Discriminating assertion (the bug this journey exists for): the panel form is
  // an ordinary POST, so the controller must REDIRECT, not dump a JSON payload
  // into the viewport.
  const afterSubmit = await bodyText(page);
  if (/^\s*\{/.test(afterSubmit) && /"(ok|redirect)"\s*:/.test(afterSubmit)) {
    throw new Error(`reseller.customers.impersonate returned a raw JSON payload to the browser instead of redirecting to the customer dashboard: ${afterSubmit.slice(0, 160)}`);
  }
  const landed = new URL(page.url()).pathname;
  if (landed !== '/dashboard') {
    throw new Error(`impersonation did not land on the customer dashboard — URL is ${page.url()} (body: ${afterSubmit.slice(0, 160)})`);
  }
  await P.assertRtl(page);
  await shot(page, 'c-portal-as-customer');

  // db() ground truth: the audited enter row, with the reason the reseller typed.
  const enterRows = P.db(
    "SELECT id, action, actor_user_id FROM {p}reseller_audit_logs WHERE reseller_id = ? AND action = ? AND meta LIKE ? ORDER BY id DESC LIMIT 1",
    [resellerId, IMPERSONATE_ACTION, `%${reasonToken}%`],
  );
  if (!enterRows.length) throw new Error(`no ${IMPERSONATE_ACTION} audit row for reseller ${resellerId} carrying reason token ${reasonToken}`);
  if (Number(enterRows[0][2]) !== ownerId) throw new Error(`${IMPERSONATE_ACTION} audit row attributes the action to ${enterRows[0][2]}, expected the reseller owner ${ownerId}`);
  log(`impersonation entered: audit row id=${enterRows[0][0]}, landed on /dashboard ✓`);

  // (The support-session strip is <x-ui.shell.impersonation>: .ui-imp[role=alert].)
  // ── 8. ACT AS THE CUSTOMER — the support-session banner is live ────────────
  const banner = await page.evaluate(() => {
    const el = document.querySelector('.ui-imp[role="alert"]');
    if (!el) return null;
    return {
      text: (el.innerText || '').replace(/\s+/g, ' ').trim(),
      // The exit control must be a POST form (the route is POST-only).
      exitForm: el.querySelector('form[action$="/reseller/impersonate/exit"]') !== null,
      exitMethod: el.querySelector('form')?.getAttribute('method') || '',
    };
  });
  if (!banner) throw new Error('no support-session banner while impersonating the customer dashboard');
  if (!banner.text.includes('C5 Sub Portal')) throw new Error(`banner does not name the impersonated customer: "${banner.text}"`);
  if (!banner.exitForm) throw new Error('banner has no exit form posting to reseller.impersonate.exit');
  if (banner.exitMethod.toUpperCase() !== 'POST') throw new Error(`banner exit control must POST, got method="${banner.exitMethod}"`);
  log(`acting as the customer: support-session banner live ("${banner.text.slice(0, 90)}…") ✓`);

  // ── 9. EXIT — back as the reseller ─────────────────────────────────────────
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
    page.evaluate(() => { document.querySelector('.ui-imp[role="alert"] form').submit(); }),
  ]);
  const backPath = new URL(page.url()).pathname;
  if (backPath !== '/reseller') throw new Error(`reseller.impersonate.exit should land back on /reseller, landed on ${backPath}`);
  await P.assertRtl(page);
  const stillBannered = await page.evaluate(() => document.querySelector('.ui-imp[role="alert"]') !== null);
  if (stillBannered) throw new Error('support-session banner is still rendered after exiting impersonation');
  if (!(await page.evaluate((b) => document.documentElement.innerHTML.includes(b), brandName))) {
    throw new Error('after exiting impersonation the portal does not render the reseller brand (not back as the reseller owner)');
  }
  await shot(page, 'c-portal-back-as-reseller');

  const exitRows = P.db(
    "SELECT id, actor_user_id FROM {p}reseller_audit_logs WHERE reseller_id = ? AND action = ? AND meta LIKE ? ORDER BY id DESC LIMIT 1",
    [resellerId, EXIT_ACTION, `%${subOwnerId}%`],
  );
  if (!exitRows.length) throw new Error(`no ${EXIT_ACTION} audit row for reseller ${resellerId} / customer ${subOwnerId}`);
  log(`impersonation exited: audit row id=${exitRows[0][0]}, back on /reseller as the owner ✓`);

  // ── 10. ACTIVITY LOG records both ──────────────────────────────────────────
  const auditResp = await page.goto(cfg.baseUrl + '/reseller/audit-log', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!auditResp || auditResp.status() !== 200) throw new Error(`reseller audit log returned ${auditResp ? auditResp.status() : 'no response'}`);
  await P.assertRtl(page);
  const auditText = await bodyText(page);
  if (!auditText.includes(IMPERSONATE_ACTION)) throw new Error(`audit log page does not list ${IMPERSONATE_ACTION}`);
  if (!auditText.includes(EXIT_ACTION)) throw new Error(`audit log page does not list ${EXIT_ACTION}`);
  await shot(page, 'c-portal-audit-log');
  log(`audit log lists ${IMPERSONATE_ACTION} + ${EXIT_ACTION} ✓`);

  // ── 11. SECURITY page (2FA / sessions) renders ─────────────────────────────
  const secResp = await page.goto(cfg.baseUrl + '/reseller/security', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!secResp || secResp.status() !== 200) throw new Error(`reseller security page returned ${secResp ? secResp.status() : 'no response'}`);
  await P.assertRtl(page);
  const sec = await page.evaluate(() => {
    const enable = document.querySelector('form[action$="/reseller/security/2fa/enable"]');
    return {
      // The 2FA card is present when either the enable form OR the disable form
      // is rendered (brand-new owner ⇒ not enrolled ⇒ enable form).
      twoFactorCard: enable !== null || document.querySelector('form[action$="/reseller/security/2fa/disable"]') !== null,
      enableAction: enable?.getAttribute('action') || '',
      // A disabled-state badge is rendered next to the card heading.
      // (the state pill is a `.ui-tag`; it used to be a bootstrap `.badge`)
      hasStateBadge: document.querySelectorAll('.ui-tag, .badge').length > 0,
    };
  });
  if (!sec.twoFactorCard) throw new Error('reseller security page does not render the two-factor card');
  if (!sec.hasStateBadge) throw new Error('reseller security page renders no 2FA enabled/disabled state badge');
  await shot(page, 'c-portal-security');
  log(`security page renders: 2FA card ${sec.enableAction ? '(not enrolled → enable form present)' : '(enrolled)'} ✓`);

  // ── 12. RESELLER HELP CENTER renders in ar ─────────────────────────────────
  const helpResp = await page.goto(cfg.baseUrl + '/reseller/help', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!helpResp || helpResp.status() !== 200) throw new Error(`reseller help center returned ${helpResp ? helpResp.status() : 'no response'}`);
  await P.assertRtl(page);
  const help = await page.evaluate(() => {
    const links = [...document.querySelectorAll('a[href*="/reseller/help/"]')].map((a) => a.getAttribute('href'));
    return { links: [...new Set(links)], text: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 200) };
  });
  if (!help.links.length) throw new Error('reseller help center renders no guide links');
  await shot(page, 'c-portal-help-center');

  // One guide opens (the impersonation guide is the one this journey lives in).
  const guideUrl = help.links.find((h) => h.includes('impersonation')) || help.links[0];
  const guideResp = await page.goto(guideUrl.startsWith('http') ? guideUrl : cfg.baseUrl + guideUrl, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!guideResp || guideResp.status() !== 200) throw new Error(`reseller help guide ${guideUrl} returned ${guideResp ? guideResp.status() : 'no response'}`);
  await P.assertRtl(page);
  const guideText = await bodyText(page);
  if (guideText.length < 200) throw new Error(`reseller help guide ${guideUrl} rendered almost no content (${guideText.length} chars)`);
  await shot(page, 'c-portal-help-guide');
  log(`reseller help center renders in ar: ${help.links.length} guide links; opened ${guideUrl} ✓`);

  // ── 13. console / CSP hygiene across every surface this WI owns ────────────
  assertClean('reseller portal', col, net);
  col.stop();

  log(`c-portal PASS — reseller ${resellerId} (${host}); sub-owner ${subIdent.email} / ${subIdent.password}; impersonate+exit audited (${enterRows[0][0]}/${exitRows[0][0]}); security + help center render in ar`);
};
