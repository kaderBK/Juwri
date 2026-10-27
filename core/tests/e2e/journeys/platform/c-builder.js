// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E — C3: Reseller page builder → publish → public (VISUAL).
 *
 * Builds on the C1 provisioning seam (admin creates a reseller on its own host)
 * and drives the whole block-composer authoring loop end-to-end in a real
 * browser, in Arabic, then proves the published page renders on the reseller's
 * OWN white-label host (RTL + dark + CSP-clean):
 *
 *   1. Fresh plain owner (db() insert, known bcrypt — the HANDOFF §3 fallback, as
 *      C1/C2) and, in a SECOND context, the admin creates a reseller with
 *      custom_domain = r1-<unix>.<baseHost>, active on. The reseller_pages
 *      kill-switch (admin_allow_reseller_pages) is ON in the copied lane DB and
 *      the feature is NOT plan-gated, so the composer is reachable.
 *   2. Owner → /reseller/builder (RTL). The landing row is auto-migrated here.
 *   3. Create a custom PAGE (reseller.builder.pages.store, template=blank).
 *   4. Author four blocks — hero, feature_grid, cta_banner, faq — in Arabic and
 *      save the DRAFT (reseller.builder.save). Ground truth: JSON ok, 4 blocks.
 *   5. TRANSLATE (reseller.builder.translate): the reseller's default content
 *      locale IS ar on this lane (content_default_locale NULL → config app.locale
 *      = ar), so a translate whose target is ar is a graceful 422 "no target"
 *      guard that fires BEFORE any AI provider is touched — the lane must never
 *      call a live AI provider. This exercises the endpoint + csrf + reseller
 *      scoping deterministically; a real cross-locale translate needs a provider
 *      key (see ISSUES ISS-C-C3a).
 *   6. PREVIEW (reseller.builder.preview): the authed draft renders via the R2
 *      partials with the "Draft preview" banner.
 *   7. PUBLISH (reseller.builder.publish): draft → published_blocks snapshot,
 *      status=published. Ground truth: DB status + published block count.
 *   8. PUBLIC (fresh, unauthenticated context on the reseller host): GET
 *      /page/{slug} renders every block (per-block ground-truth DOM assertions;
 *      ISSUES lists any block type that fails). RTL, no overflow, CSP-clean.
 *   9. DARK: prefers-color-scheme dark → data-bs-theme=dark, dark surface, every
 *      block still renders, CSP-clean (VISUAL requirement).
 *  10. EDIT + REPUBLISH: change the hero headline, save + publish → the new
 *      headline is live and the old one is gone.
 *  11. FOOTER TOGGLE (reseller.builder.pages.footer): the page appears in the
 *      public nav once flagged show_in_footer.
 *  12. SECOND PAGE + DESTROY: create+publish a second page (its public URL 200s),
 *      then reseller.builder.pages.destroy → its public URL 404s.
 *
 * Ground truth only: HTTP status, DOM text/attrs/computed style, DB rows. One
 * shot() per screen. Finishes asserting no console errors / no enforced CSP
 * refusals on the authored + public surfaces (P.assertNoConsoleErrors — it
 * ignores report-only CSP + benign 404s; not a hand-rolled console filter).
 *
 * Runs under E2E_RUN_PLATFORM=1:  bash rev/platform-e2e/bin/e2e.sh c c-builder
 */

const P = require('../../lib/platform');

// A failed sub-resource surfaces in the console as a URL-less "Failed to load
// resource: … 404" line (the URL rides on the network event, not the console
// text), so collectConsole's built-in favicon/image filter can't classify it.
// Treat every such line as benign for the CONSOLE check and let the netBad
// watcher (which carries URLs) classify real vs. benign 4xx sub-resources —
// same split C1/C2 use, expressed through collectConsole's own `ignore` hook.
const IGNORE_RES_404 = [/Failed to load resource/i];

// Dev-only bcrypt so the inserted owner can use the real /login form.
// password_verify('E2e-owner-c3-pass', HASH) === true (generated + verified on the lane).
const OWNER_PASS = 'E2e-owner-c3-pass';
const OWNER_HASH = '$2y$10$CRVRW5zvzJFmLh5Y6wh6PeMWykTcAKZDmIsGqBkEI0E5GZs.YzdOi';

// Benign, out-of-fence sub-resource 404s on the old-theme panel/public skin + the
// report-only CSP beacon (same rationale as c-reseller.js). Anything else 4xx is a fault.
const BENIGN_RES = /(favicon\.ico|\/csp-report|\/assets\/ajax\/search\.json|\/storage\/|\.(png|jpe?g|webp|svg|gif|ico|woff2?)(\?|$))/i;

// STATIC sub-resource types only: a broken stylesheet/script/font/image is real
// breakage, but a 4xx on an xhr/fetch is usually an INTENTIONAL API status the
// journey drives (e.g. the 422 no-target translate, or the destroyed-page probe),
// so those are classified by their own assertions, not this static-asset watcher.
const STATIC_RES = new Set(['stylesheet', 'script', 'image', 'font', 'media', 'manifest', 'other']);

/** Record every static sub-resource that 4xxs on `page` (URLs, for classification). */
function watchNet(page) {
  const bad = [];
  page.on('response', (r) => {
    try {
      if (r.status() >= 400 && STATIC_RES.has(r.request().resourceType())) bad.push(r.status() + ' ' + r.url());
    } catch (_e) { /* response gone */ }
  });
  return bad;
}

/** Fail on any non-benign 4xx sub-resource (the console/CSP check is assertNoConsoleErrors). */
function assertNoBadResources(where, netBad) {
  const badRes = netBad.filter((u) => !BENIGN_RES.test(u));
  if (badRes.length) throw new Error(`${where}: ${badRes.length} broken sub-resource(s):\n  ${badRes.join('\n  ')}`);
}

/** The panel CSRF token from the current portal page. */
async function panelCsrf(page) {
  const token = await page.evaluate(() => {
    const m = document.querySelector('meta[name="csrf-token"]');
    return (m && m.content) || '';
  });
  if (!token) throw new Error('panelCsrf: no csrf token on the builder page');
  return token;
}

/**
 * Same-origin fetch to a portal endpoint with the panel CSRF. `form` is a list of
 * [name, value] pairs (url-encoded); `method` defaults to POST. Returns
 * { status, type, json } (json parsed leniently). redirect:'manual' so a 302
 * back()/redirect() surfaces as status 0/opaqueredirect (type 'opaqueredirect').
 */
async function api(page, csrf, { method = 'POST', path, form = [] }) {
  return page.evaluate(async ({ method, path, form, csrf }) => {
    const opts = {
      method,
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
      redirect: 'manual',
    };
    if (form.length) {
      const body = new URLSearchParams();
      body.append('_token', csrf);
      for (const [k, v] of form) body.append(k, v);
      opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      opts.body = body.toString();
    }
    const r = await fetch(path, opts);
    let json = null;
    try { json = await r.json(); } catch (_e) { /* redirect / html */ }
    return { status: r.status, type: r.type, json };
  }, { method, path, form, csrf });
}

/** The four-block document authored in `locale` (hero, feature_grid, cta_banner, faq). */
function makeDoc(locale, ctaUrl, t) {
  const featItems = JSON.stringify([
    { title: t.featT1, body: t.featB1 },
    { title: t.featT2, body: t.featB2 },
    { title: t.featT3, body: t.featB3 },
  ]);
  const faqItems = JSON.stringify([
    { title: t.faqQ1, body: t.faqA1 },
    { title: t.faqQ2, body: t.faqA2 },
  ]);
  return {
    version: 1,
    blocks: [
      {
        id: 'blk_a1b2c3', type: 'hero', enabled: true,
        settings: { align: 'center', style: 'gradient', image: '' },
        content: {
          headline: { [locale]: t.heroHeadline },
          subline: { [locale]: t.heroSubline },
          cta_label: { [locale]: t.heroCta },
          cta_url: ctaUrl,
        },
      },
      {
        id: 'blk_d4e5f6', type: 'feature_grid', enabled: true,
        settings: { columns: '3' },
        content: { heading: { [locale]: t.featHeading }, items: { [locale]: featItems } },
      },
      {
        id: 'blk_112233', type: 'cta_banner', enabled: true,
        settings: { style: 'gradient' },
        content: {
          headline: { [locale]: t.ctaHeadline },
          cta_label: { [locale]: t.ctaLabel },
          cta_url: ctaUrl,
        },
      },
      {
        id: 'blk_445566', type: 'faq', enabled: true,
        settings: {},
        content: { heading: { [locale]: t.faqHeading }, items: { [locale]: faqItems } },
      },
    ],
  };
}

/** Per-block render verification on the public page; returns { marks, failures }. */
async function verifyBlocks(hostPage, t) {
  const marks = await hostPage.evaluate((t) => {
    const body = document.body.innerText;
    const has = (s) => body.includes(s);
    return {
      hero: !!document.querySelector('.rpub-hero h1') && has(t.heroHeadline) && has(t.heroSubline),
      feature_grid: document.querySelectorAll('.rpub-feature h3').length >= 3 && has(t.featHeading) && has(t.featT1) && has(t.featT2) && has(t.featT3),
      cta_banner: !!document.querySelector('.rpub-cta a.ui-btn') && has(t.ctaHeadline),
      faq: document.querySelectorAll('details summary').length >= 2 && has(t.faqHeading) && has(t.faqQ1) && has(t.faqQ2),
    };
  }, t);
  const failures = Object.entries(marks).filter(([, ok]) => !ok).map(([k]) => k);
  return { marks, failures };
}

module.exports = async function cBuilder({ browser, page, cfg, log, shot }) {
  const eeOnly = P.requireEe('Reseller (site builder)');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const adminEmail = process.env.E2E_ADMIN_EMAIL;
  const adminPass = process.env.E2E_ADMIN_PASS;
  if (!adminEmail || !adminPass) throw new Error('c-builder: E2E_ADMIN_EMAIL / E2E_ADMIN_PASS not exported (bin/e2e.sh reads the lane .env)');

  const unix = Date.now();
  const ownerEmail = `e2e-c-builderowner-${unix}@local.invalid`;
  const brandName = `منصة القوائم ${unix}`;                 // real Arabic reseller brand (the run is ar)
  const baseHost = new URL(cfg.baseUrl).hostname;               // e2e-c.test
  const host = `r1-${unix}.${baseHost}`;                    // subdomain of the lane site
  const ctaUrl = `${cfg.baseUrl}/signup`;                   // absolute http(s) → passes SafeUrl::web
  const slug1 = `home-${unix}`;
  const slug2 = `about-${unix}`;

  // Unique Arabic content strings (unix-suffixed so they're discriminating).
  const T = {
    heroHeadline: `مرحبا بكم في مطعمك ${unix}`,
    heroSubline: `أنشئ قائمتك الرقمية في دقائق ${unix}`,
    heroCta: 'ابدأ الآن',
    featHeading: `لماذا نحن ${unix}`,
    featT1: `طلب سريع ${unix}`, featB1: 'اطلب من الجوال مباشرة',
    featT2: 'تصميم أنيق', featB2: 'قوالب جاهزة وجميلة',
    featT3: 'دعم فوري', featB3: 'فريقنا معك دائما',
    ctaHeadline: `انضم إلى آلاف المطاعم ${unix}`,
    ctaLabel: 'سجّل مجانا',
    faqHeading: `الأسئلة الشائعة ${unix}`,
    faqQ1: `هل الخدمة مجانية؟ ${unix}`, faqA1: 'نعم توجد باقة مجانية',
    faqQ2: 'كيف أبدأ؟', faqA2: 'سجّل حساب وابدأ فورا',
  };
  const editedHeadline = `عنوان محدّث بعد النشر ${unix}`;

  await page.setViewport({ width: 390, height: 844 });

  // ── 1. fresh plain owner via db() ──────────────────────────────────────────
  P.db(
    "INSERT INTO {p}users (email, username, name, password, user_type, status, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'user', 1, NOW(), NOW(), NOW())",
    [ownerEmail, `builderowner${unix}`, 'C3 Owner', OWNER_HASH],
  );
  const ownerRow = P.db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [ownerEmail]);
  if (!ownerRow.length) throw new Error(`c-builder: owner row not inserted for ${ownerEmail}`);
  const ownerId = Number(ownerRow[0][0]);
  log(`owner created: id=${ownerId} email=${ownerEmail}`);

  // ── 2. ADMIN (second context) creates the reseller on its own host ─────────
  const adminCtx = (await browser.createBrowserContext?.()) || browser;
  const adminPage = await adminCtx.newPage();
  await adminPage.setViewport({ width: 390, height: 844 });
  const adminCol = P.collectConsole(adminPage, { ignore: IGNORE_RES_404 });
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
    const rRow = P.db('SELECT id, active, custom_domain, content_default_locale FROM {p}resellers WHERE custom_domain = ? LIMIT 1', [host]);
    if (!rRow.length) throw new Error(`admin store did not create a reseller for custom_domain=${host}`);
    resellerId = Number(rRow[0][0]);
    if (String(rRow[0][1]) !== '1') throw new Error(`new reseller should be active by default, got active=${rRow[0][1]}`);
    if (rRow[0][2] !== host) throw new Error(`custom_domain mismatch: ${rRow[0][2]} !== ${host}`);
    log(`admin created reseller id=${resellerId} domain=${host} (active) ✓`);
    await P.assertNoConsoleErrors(adminPage, adminCol);
    assertNoBadResources('admin panel', adminNet);
  } finally {
    await adminPage.close().catch(() => {});
    if (adminCtx !== browser) await adminCtx.close().catch(() => {});
  }

  // Source content locale (NULL on a fresh reseller → config app.locale = ar).
  const srcRow = P.db('SELECT content_default_locale FROM {p}resellers WHERE id = ? LIMIT 1', [resellerId]);
  const source = (srcRow[0] && srcRow[0][0] && srcRow[0][0] !== 'NULL') ? srcRow[0][0] : 'ar';
  log(`reseller content source locale = "${source}"`);

  // ── owner portal session → builder index ───────────────────────────────────
  const col = P.collectConsole(page, { ignore: IGNORE_RES_404 });
  const net = watchNet(page);
  await P.loginOwner(page, { email: ownerEmail, pass: OWNER_PASS });
  const idxResp = await page.goto(cfg.baseUrl + '/reseller/builder', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!idxResp || idxResp.status() !== 200) throw new Error(`builder index returned ${idxResp ? idxResp.status() : 'no response'} (reseller_pages kill-switch off?)`);
  await P.assertRtl(page);
  await P.assertNoOverflow(page);
  await shot(page, 'c-builder-index');
  const csrf = await panelCsrf(page);
  log('owner reached /reseller/builder (RTL) ✓');

  // ── 3. CREATE a custom page ────────────────────────────────────────────────
  const store1 = await api(page, csrf, {
    path: cfg.baseUrl + '/reseller/builder-pages',
    form: [['title', 'C3 Home'], ['slug', slug1], ['template', 'blank']],
  });
  if (store1.status >= 400) throw new Error(`builder.pages.store returned ${store1.status} for slug=${slug1}`);
  const p1Row = P.db("SELECT id, type, status FROM {p}reseller_pages WHERE reseller_id = ? AND slug = ? AND type = 'page' LIMIT 1", [resellerId, slug1]);
  if (!p1Row.length) throw new Error(`builder.pages.store did not create a page row for slug=${slug1}`);
  const pageId = Number(p1Row[0][0]);
  if (p1Row[0][2] !== 'draft') throw new Error(`new page should start as draft, got status=${p1Row[0][2]}`);
  log(`page created: id=${pageId} slug=${slug1} (draft) ✓`);

  // ── 4. AUTHOR the four blocks + save the draft ─────────────────────────────
  const doc = makeDoc(source, ctaUrl, T);
  const save1 = await api(page, csrf, {
    method: 'PUT',
    path: `${cfg.baseUrl}/reseller/builder/${pageId}`,
    form: [['blocks', JSON.stringify(doc)]],
  });
  if (save1.status !== 200 || !save1.json || save1.json.ok !== true) throw new Error(`builder.save returned ${save1.status} ${JSON.stringify(save1.json)}`);
  const savedTypes = (save1.json.blocks && save1.json.blocks.blocks || []).map((b) => b.type);
  for (const type of ['hero', 'feature_grid', 'cta_banner', 'faq']) {
    if (!savedTypes.includes(type)) throw new Error(`builder.save dropped the "${type}" block (kept: ${savedTypes.join(',')})`);
  }
  log(`draft saved: 4 blocks (${savedTypes.join(', ')}) ✓`);

  // ── 5. TRANSLATE — target == source (ar) → graceful 422 no-target (no AI) ───
  const tr = await api(page, csrf, {
    path: `${cfg.baseUrl}/reseller/builder/${pageId}/translate`,
    form: [['locale', source], ['mode', 'missing']],
  });
  if (tr.status !== 422 || !tr.json || tr.json.ok !== false || !tr.json.message) {
    throw new Error(`builder.translate to source locale must be a graceful 422 no-target, got ${tr.status} ${JSON.stringify(tr.json)}`);
  }
  log(`translate to source "${source}" → graceful 422 no-target (no AI provider touched) ✓`);

  // ── 6. PREVIEW the draft (authed) ──────────────────────────────────────────
  const prevResp = await page.goto(`${cfg.baseUrl}/reseller/builder/${pageId}/preview?locale=${source}`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!prevResp || prevResp.status() !== 200) throw new Error(`builder.preview returned ${prevResp ? prevResp.status() : 'no response'}`);
  await P.assertRtl(page);
  const previewOk = await page.evaluate((hh) => {
    const banner = /Draft preview|معاينة/.test(document.body.innerText);
    return { banner, hero: document.body.innerText.includes(hh) };
  }, T.heroHeadline);
  if (!previewOk.hero) throw new Error('builder.preview did not render the draft hero headline');
  await shot(page, 'c-builder-preview');
  log(`preview renders the draft (banner=${previewOk.banner}, hero present) ✓`);

  // ── 7. PUBLISH ─────────────────────────────────────────────────────────────
  const pub = await api(page, csrf, { path: `${cfg.baseUrl}/reseller/builder/${pageId}/publish` });
  if (pub.status !== 200 || !pub.json || pub.json.status !== 'published') throw new Error(`builder.publish returned ${pub.status} ${JSON.stringify(pub.json)}`);
  const pubRow = P.db("SELECT status, JSON_LENGTH(published_blocks, '$.blocks') FROM {p}reseller_pages WHERE id = ? LIMIT 1", [pageId]);
  if (pubRow[0][0] !== 'published') throw new Error(`page status should be published, got ${pubRow[0][0]}`);
  if (Number(pubRow[0][1]) !== 4) throw new Error(`published_blocks should snapshot 4 blocks, got ${pubRow[0][1]}`);
  log('published: status=published, 4 blocks snapshotted ✓');

  // ── 8+. PUBLIC HOST (fresh, unauthenticated) ───────────────────────────────
  const hostCtx = (await browser.createBrowserContext?.()) || browser;
  const hostPage = await hostCtx.newPage();
  await hostPage.setViewport({ width: 390, height: 844 });
  await hostPage.setCacheEnabled(false);
  const hostCol = P.collectConsole(hostPage, { ignore: IGNORE_RES_404 });
  const hostNet = watchNet(hostPage);
  let resolution = 'subdomain';

  /** Navigate to a path on the reseller host, subdomain first then Host-override. */
  const gotoHost = async (path) => {
    if (resolution === 'subdomain') {
      try {
        const r = await hostPage.goto(`${new URL(cfg.baseUrl).protocol}//${host}${new URL(cfg.baseUrl).port ? ':' + new URL(cfg.baseUrl).port : ''}${path}`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
        if (r && r.status() < 400) return r;
        // a real 404 under the subdomain is a fault the caller inspects; only a
        // failed-to-resolve subdomain falls through to the Host-override path.
        if (r) return r;
      } catch (e) {
        log(`subdomain navigation failed (${e.message}); switching to Host-header override`);
      }
      resolution = 'host-override';
    }
    await P.withHost(hostPage, host);
    return hostPage.goto(cfg.baseUrl + path, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  };

  try {
    // 8. LIGHT: the published page renders every block.
    const pubResp = await gotoHost('/page/' + slug1);
    if (!pubResp || pubResp.status() !== 200) throw new Error(`public /page/${slug1} returned ${pubResp ? pubResp.status() : 'no response'} (${resolution})`);
    await P.assertRtl(hostPage);
    const scoped = await hostPage.evaluate((id) => {
      const b = document.querySelector('body[data-reseller-public]');
      return b ? b.getAttribute('data-reseller-public') === String(id) : false;
    }, resellerId);
    if (!scoped) throw new Error('public page is not scoped to the reseller (data-reseller-public mismatch)');
    const { marks, failures } = await verifyBlocks(hostPage, T);
    if (failures.length) throw new Error(`public page: block type(s) failed to render: ${failures.join(', ')} — marks=${JSON.stringify(marks)}`);
    await P.assertNoOverflow(hostPage);
    await shot(hostPage, 'c-builder-public-light');
    await P.assertNoConsoleErrors(hostPage, hostCol);
    assertNoBadResources('public page (light)', hostNet);
    log(`public /page/${slug1} via ${resolution}: hero+feature_grid+cta_banner+faq all render (RTL, CSP-clean) ✓`);

    // 9. DARK: prefers-color-scheme dark → data-bs-theme=dark, dark surface.
    await hostPage.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
    await gotoHost('/page/' + slug1);
    const dark = await hostPage.evaluate(() => {
      const theme = document.documentElement.getAttribute('data-bs-theme');
      const bg = getComputedStyle(document.body).backgroundColor;
      const m = bg.match(/(\d+),\s*(\d+),\s*(\d+)/);
      const lum = m ? (0.299 * +m[1] + 0.587 * +m[2] + 0.114 * +m[3]) : 255;
      return { theme, bg, lum };
    });
    if (dark.theme !== 'dark') throw new Error(`dark: <html data-bs-theme> is "${dark.theme}", expected "dark" (prefers-color-scheme not honored)`);
    if (dark.lum >= 128) throw new Error(`dark: body background "${dark.bg}" is not dark (luminance ${dark.lum.toFixed(0)})`);
    const darkBlocks = await verifyBlocks(hostPage, T);
    if (darkBlocks.failures.length) throw new Error(`dark: block type(s) failed to render: ${darkBlocks.failures.join(', ')}`);
    await P.assertNoOverflow(hostPage);
    await shot(hostPage, 'c-builder-public-dark');
    await P.assertNoConsoleErrors(hostPage, hostCol);
    log(`public page in DARK: data-bs-theme=dark, dark surface (lum ${dark.lum.toFixed(0)}), all blocks render ✓`);
    await hostPage.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);

    // 10. EDIT + REPUBLISH — the change goes live.
    const doc2 = makeDoc(source, ctaUrl, { ...T, heroHeadline: editedHeadline });
    const save2 = await api(page, csrf, { method: 'PUT', path: `${cfg.baseUrl}/reseller/builder/${pageId}`, form: [['blocks', JSON.stringify(doc2)]] });
    if (save2.status !== 200 || !save2.json || save2.json.ok !== true) throw new Error(`edit save returned ${save2.status} ${JSON.stringify(save2.json)}`);
    const pub2 = await api(page, csrf, { path: `${cfg.baseUrl}/reseller/builder/${pageId}/publish` });
    if (pub2.status !== 200 || !pub2.json || pub2.json.status !== 'published') throw new Error(`republish returned ${pub2.status} ${JSON.stringify(pub2.json)}`);
    await gotoHost('/page/' + slug1);
    const afterEdit = await hostPage.evaluate((n) => ({ hasNew: document.body.innerText.includes(n.neu), hasOld: document.body.innerText.includes(n.old) }), { neu: editedHeadline, old: T.heroHeadline });
    if (!afterEdit.hasNew) throw new Error('republish: the edited hero headline is not live on the public page');
    if (afterEdit.hasOld) throw new Error('republish: the old hero headline is still live (stale publish)');
    await shot(hostPage, 'c-builder-public-edited');
    log('edit + republish: new headline live, old headline gone ✓');

    // 11. FOOTER TOGGLE — the page shows in the public nav.
    const foot = await api(page, csrf, { path: `${cfg.baseUrl}/reseller/builder/${pageId}/footer`, form: [['show_in_footer', '1']] });
    if (foot.status !== 200 || !foot.json || foot.json.show_in_footer !== true) throw new Error(`footer toggle returned ${foot.status} ${JSON.stringify(foot.json)}`);
    const footDb = P.db('SELECT show_in_footer FROM {p}reseller_pages WHERE id = ? LIMIT 1', [pageId]);
    if (String(footDb[0][0]) !== '1') throw new Error(`show_in_footer not persisted (got ${footDb[0][0]})`);
    await gotoHost('/page/' + slug1);
    const inNav = await hostPage.evaluate((slug) => Array.from(document.querySelectorAll('a')).some((a) => (a.getAttribute('href') || '').endsWith('/page/' + slug)), slug1);
    if (!inNav) throw new Error('footer toggle: the page does not appear in the public nav/footer after show_in_footer=1');
    log('footer toggle: page appears in the public nav ✓');

    // 12. SECOND PAGE + DESTROY — its public URL 404s.
    const store2 = await api(page, csrf, { path: cfg.baseUrl + '/reseller/builder-pages', form: [['title', 'C3 About'], ['slug', slug2], ['template', 'blank']] });
    if (store2.status >= 400) throw new Error(`second builder.pages.store returned ${store2.status}`);
    const p2Row = P.db("SELECT id FROM {p}reseller_pages WHERE reseller_id = ? AND slug = ? LIMIT 1", [resellerId, slug2]);
    if (!p2Row.length) throw new Error(`second page row not created for slug=${slug2}`);
    const pageId2 = Number(p2Row[0][0]);
    const doc2b = { version: 1, blocks: [makeDoc(source, ctaUrl, T).blocks[0]] };   // a single hero block
    await api(page, csrf, { method: 'PUT', path: `${cfg.baseUrl}/reseller/builder/${pageId2}`, form: [['blocks', JSON.stringify(doc2b)]] });
    const pub2b = await api(page, csrf, { path: `${cfg.baseUrl}/reseller/builder/${pageId2}/publish` });
    if (pub2b.status !== 200 || !pub2b.json || pub2b.json.status !== 'published') throw new Error(`second page publish returned ${pub2b.status}`);
    const live2 = await gotoHost('/page/' + slug2);
    if (!live2 || live2.status() !== 200) throw new Error(`second page public /page/${slug2} should 200 before destroy, got ${live2 ? live2.status() : 'no response'}`);
    log(`second page id=${pageId2} slug=${slug2} published + live (200) ✓`);

    const destroy = await api(page, csrf, { method: 'DELETE', path: `${cfg.baseUrl}/reseller/builder/${pageId2}` });
    if (destroy.status >= 400) throw new Error(`builder.pages.destroy returned ${destroy.status}`);
    const goneRow = P.db('SELECT id FROM {p}reseller_pages WHERE id = ? LIMIT 1', [pageId2]);
    if (goneRow.length) throw new Error(`destroy did not remove the page row (id=${pageId2} still present)`);
    const dead = await gotoHost('/page/' + slug2);
    if (!dead || dead.status() !== 404) throw new Error(`destroyed page /page/${slug2} must 404, got ${dead ? dead.status() : 'no response'}`);
    await shot(hostPage, 'c-builder-public-404');
    await P.assertNoConsoleErrors(hostPage, hostCol);
    log(`destroy: page row gone, public /page/${slug2} → 404 ✓`);
  } finally {
    await hostPage.close().catch(() => {});
    if (hostCtx !== browser) await hostCtx.close().catch(() => {});
  }

  await P.assertNoConsoleErrors(page, col);
  assertNoBadResources('reseller portal', net);
  col.stop();
  log(`c-builder PASS — reseller id=${resellerId} domain=${host} page=${pageId}/${slug1} owner=${ownerEmail} / ${OWNER_PASS} (host via ${resolution})`);
};
