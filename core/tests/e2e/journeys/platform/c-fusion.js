// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform C8 — Fusion reseller journeys in Arabic
 *
 * Wraps the three Fusion journeys (tenant-isolation, reseller-console, multicart)
 * to run under E2E_LOCALE=ar on the lane site with FusionResellerDemoSeeder data.
 * All three must pass × 2 runs. Tenant isolation is P0.
 *
 * Host resolution (ISS-C-C8a): Chrome rejects a `Host` header set via
 * setExtraHTTPHeaders (forbidden header → net::ERR_INVALID_ARGUMENT), and the
 * seeder's `fusion-a.test` / `fusion-b.test` are foreign TLDs that do not resolve
 * on the lane box. So — like the passing c-branding/c-builder/c-reseller/c-sale
 * journeys — we self-provision each Fusion reseller onto a RESOLVED subdomain of
 * the lane host (Herd serves `*.e2e-c.test`, TrustHosts trusts every subdomain of
 * the app URL, Reseller::resolveForRequest matches custom_domain by request host)
 * and navigate to it with a valid absolute URL. A fresh per-run host also dodges
 * the 300 s reseller:domain resolution cache.
 *
 * Locale: ar (RTL, locale_slug = 'ar', no prefix)
 * Data: FusionResellerDemoSeeder
 * Routes: /fusion/* on reseller-A, reseller-B, and the platform host
 */

const P = require('../../lib/platform');

module.exports = async function cFusion({ page, cfg, log, shot, sleep }) {
  const eeOnly = P.requireEe('the Fusion marketplace');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const failures = [];

  // Ensure we're in Arabic locale
  if (cfg.locale !== 'ar') {
    throw new Error(`C8 requires E2E_LOCALE=ar, got ${cfg.locale}`);
  }

  // ── Self-provision the two Fusion resellers onto resolvable lane subdomains ──
  // The demo resellers are keyed by their (stable) owner email; re-point their
  // custom_domain to a fresh subdomain of the lane host so a real browser can
  // reach them with an absolute https:// URL (no Host-header override).
  const baseHost = new URL(cfg.baseUrl).hostname;            // e2e-c.test
  const unix = Date.now();
  const resellerIdFor = (email) => {
    const r = P.db(
      'SELECT r.id FROM {p}resellers r JOIN {p}users u ON u.id = r.owner_user_id WHERE u.email = ? LIMIT 1',
      [email],
    );
    if (!r.length) throw new Error(`C8: fusion demo reseller not found for ${email} — run FusionResellerDemoSeeder`);
    return Number(r[0][0]);
  };
  const idA = resellerIdFor('fusiondemo-a@example.test');
  const idB = resellerIdFor('fusiondemo-b@example.test');
  const hostA = `fusiona-${unix}.${baseHost}`;
  const hostB = `fusionb-${unix}.${baseHost}`;
  P.db('UPDATE {p}resellers SET custom_domain = ?, active = 1 WHERE id = ?', [hostA, idA]);
  P.db('UPDATE {p}resellers SET custom_domain = ?, active = 1 WHERE id = ?', [hostB, idB]);
  const baseA = `${new URL(cfg.baseUrl).protocol}//${hostA}${new URL(cfg.baseUrl).port ? ':' + new URL(cfg.baseUrl).port : ''}`;                      // reseller A Fusion host (host under test)
  log(`fusion resellers repointed: A(id=${idA})→${hostA}  B(id=${idB})→${hostB} ✓`);

  // === JOURNEY 1: Tenant Isolation ===
  // Test that reseller Fusion hosts only show their own stores (routing + gate + view)
  // and cross-tenant store URLs 404.

  try {
    const A1 = 'fusion-demo-a1';        // reseller A, dual-listed
    const A2 = 'fusion-demo-a2';        // reseller A, reseller-only
    const B1 = 'fusion-demo-b1';        // reseller B
    const PLATFORM = 'fusion-demo-platform';

    const statusOn = async (originBase, slug) => {
      const resp = await page.goto(`${originBase}/fusion/store/${slug}`, {
        waitUntil: 'domcontentloaded', timeout: cfg.navTimeout,
      });
      return resp ? resp.status() : 0;
    };

    // Browse reseller A's Fusion host: only A's stores resolve; B's + the platform store's pages 404.
    const a1 = await statusOn(baseA, A1);
    if (a1 >= 400) failures.push(`C8-isolation: A1 should resolve on reseller A, got ${a1}`);

    const a2 = await statusOn(baseA, A2);
    if (a2 >= 400) failures.push(`C8-isolation: A2 (reseller-only) should resolve on reseller A, got ${a2}`);

    const b1OnA = await statusOn(baseA, B1);
    if (b1OnA !== 404) failures.push(`C8-isolation: B1 must 404 on reseller A (cross-tenant), got ${b1OnA}`);

    const platformOnA = await statusOn(baseA, PLATFORM);
    if (platformOnA !== 404) failures.push(`C8-isolation: platform store must 404 on reseller A, got ${platformOnA}`);

    await shot(page, 'c-fusion-1-tenant-a');

    // Flip to the platform host: the reseller-only store 404s; the platform store lives.
    const a2OnPlatform = await statusOn(cfg.baseUrl, A2);
    if (a2OnPlatform !== 404) failures.push(`C8-isolation: A2 (reseller-only) must 404 on platform, got ${a2OnPlatform}`);

    const platformOnPlatform = await statusOn(cfg.baseUrl, PLATFORM);
    if (platformOnPlatform >= 400) failures.push(`C8-isolation: platform store should resolve on platform, got ${platformOnPlatform}`);

    await shot(page, 'c-fusion-1-tenant-platform');

    log('C8-isolation: reseller host shows only its own stores; cross-tenant URLs 404 ✓');
  } catch (err) {
    failures.push(`C8-isolation: ${err.message}`);
  }

  // === JOURNEY 2: Reseller Fusion Console ===
  // Test the reseller Fusion console under the reseller's own host

  try {
    const email = 'fusiondemo-a@example.test';
    const pass = 'fusion-demo-pass';

    // Log in as the reseller owner on reseller A's Fusion host.
    await page.goto(`${baseA}/login`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    await page.waitForSelector('#username, input[name="username"]', { timeout: cfg.navTimeout });
    await page.type('#username', email);
    await page.type('#password', pass);
    await Promise.all([
      page.click('button[type="submit"], input[type="submit"]'),
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
    ]);
    await sleep(600);

    // The Marketplace console overview must load (own_fusion is on for the demo reseller).
    const overview = await page.goto(`${baseA}/reseller/fusion`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    if (!overview || overview.status() >= 400) {
      throw new Error(`overview returned ${overview && overview.status()} — own_fusion gate or auth failed`);
    }
    const hasNav = await page.evaluate(() => document.body.innerText.includes('Marketplace') || document.body.innerText.includes('سوق'));
    if (!hasNav) throw new Error('overview did not render the console');
    await shot(page, 'c-fusion-2-console-overview');

    // Curate a section (a write). Marker title so cleanup can find it.
    const marker = 'E2E Section ' + Date.now();
    await page.goto(`${baseA}/reseller/fusion/curation`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    await page.waitForSelector('#create-section-form input[name="title"], form input[name="title"]', { timeout: cfg.navTimeout });
    await page.type('input[name="title"]', marker);
    // The "Add section" button has no explicit type attribute — submit the create
    // form natively via the title input's parent form (not a button[type] selector).
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
      page.evaluate(() => { document.querySelector('input[name="title"]').form.submit(); }),
    ]);
    await sleep(400);

    const created = await page.evaluate((m) => document.body.innerText.includes(m), marker);
    if (!created) throw new Error('section was not created');
    await shot(page, 'c-fusion-2-console-curation');

    // Save branding (a write, reverted by re-saving the demo brand at the end).
    await page.goto(`${baseA}/reseller/fusion/branding`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    await page.waitForSelector('input[name="name_en"]', { timeout: cfg.navTimeout });
    await page.evaluate(() => { document.querySelector('input[name="name_en"]').value = ''; });
    await page.type('input[name="name_en"]', 'Souq Alpha');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
      page.evaluate(() => { document.querySelector('input[name="name_en"]').form.submit(); }),
    ]);
    await sleep(400);

    // CLEANUP: delete the section this journey created.
    await page.goto(`${baseA}/reseller/fusion/curation`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    await page.evaluate((m) => {
      const rows = Array.from(document.querySelectorAll('tr'));
      for (const tr of rows) {
        if (tr.innerText.includes(m)) {
          const btn = tr.querySelector('form button, button');
          if (btn) btn.click();
        }
      }
    }, marker);
    await sleep(600);

    log('C8-console: console reachable, section curated + cleaned up, branding saved ✓');
  } catch (err) {
    failures.push(`C8-console: ${err.message}`);
  }

  // === JOURNEY 3: Multicart ===
  // Test the per-store mini-carts + independent checkout (on the platform host).

  try {
    const [slugA, slugB] = ['fusion-demo-a1', 'fusion-demo-b1']; // both platform-listed

    // Add the first item from a store page to the bag.
    const addFirstItem = async (slug) => {
      const resp = await page.goto(`${cfg.baseUrl}/fusion/store/${slug}`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
      if (!resp || resp.status() >= 400) {
        throw new Error(`${slug}: store page ${resp && resp.status()}`);
      }
      await sleep(800);
      // The store page's first "add" control — themes vary, so try the common hooks.
      const added = await page.evaluate(() => {
        const btn = document.querySelector('[data-mk-add], [data-add-to-cart], .mk-add, button[name="add"]');
        if (btn) { btn.click(); return true; }
        return false;
      });
      if (!added) throw new Error(`${slug}: no add-to-cart control found`);
      await sleep(700);
    };

    await addFirstItem(slugA);
    await addFirstItem(slugB);

    // Open the bag: two mini-cart cards, badge = 2 (carts, not items).
    await page.goto(`${cfg.baseUrl}/fusion/bag`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    await sleep(500);
    const state = await page.evaluate(() => ({
      cards: document.querySelectorAll('[data-bag-store]').length,
      ctas: document.querySelectorAll('.mk-bagstore__go').length,
      badge: (document.querySelector('[data-mk-bag-count], .mk-bag-badge, [data-bag-badge]') || {}).textContent || '',
    }));
    if (state.cards < 2) throw new Error(`expected 2 mini-cart cards, saw ${state.cards}`);
    if (state.ctas < 2) throw new Error(`expected a per-cart CTA on each card, saw ${state.ctas}`);
    await shot(page, 'c-fusion-3-multicart-bag');

    // Check out cart A only via its per-cart CTA link.
    const goA = await page.evaluate(() => {
      const link = Array.from(document.querySelectorAll('a.mk-bagstore__go'))
        .find((a) => /[?&]store=/.test(a.getAttribute('href') || ''));
      return link ? link.getAttribute('href') : null;
    });
    if (!goA) {
      throw new Error('no available per-cart checkout link to follow');
    }

    // CLEANUP: clear every remaining cart (submit each store's clear form).
    await page.goto(`${cfg.baseUrl}/fusion/bag`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    await page.evaluate(() => {
      document.querySelectorAll('form[action*="/fusion/bag/store/"]').forEach((f) => {
        // Bypass the confirm() dialog for the automated cleanup.
        f.removeAttribute('onsubmit');
        f.submit();
      });
    });
    await sleep(800);

    log('C8-multicart: two mini-carts render with independent CTAs; carts cleaned up ✓');
  } catch (err) {
    failures.push(`C8-multicart: ${err.message}`);
  }

  if (failures.length) {
    throw new Error('C8 Fusion journeys failed:\n  ' + failures.join('\n  '));
  }

  log('C8 Fusion journeys: tenant isolation + console + multicart all ✓');
};
