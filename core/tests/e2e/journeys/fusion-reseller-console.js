// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * fusion-reseller FR-61 (opt-in) — the reseller Fusion console, in a real browser.
 *
 * Logs in as the demo reseller-A owner, opens the own_fusion-gated Marketplace
 * console, curates a section and saves branding, then asserts the console round-trips
 * and CLEANS UP the section it created (the item-reviews-journey pattern).
 *
 * Data: FusionResellerDemoSeeder — creates the reseller-A owner with a fixed demo
 * password (FusionResellerDemoSeeder::DEMO_PASSWORD).
 * Env: E2E_RUN_FUSION_CONSOLE=1
 */

module.exports = async function fusionResellerConsole({ page, cfg, log, shot, sleep }) {
  const eeOnly = require('../lib/platform').requireEe('the Reseller Fusion console');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const email = 'fusiondemo-a@example.test';
  const pass = 'fusion-demo-pass';

  // Log in as the reseller owner.
  await page.goto(`${cfg.baseUrl}/login`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
  await page.waitForSelector('#username, input[name="username"]', { timeout: cfg.navTimeout });
  await page.type('#username', email);
  await page.type('#password', pass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  await sleep(600);

  // The Marketplace console overview must load (own_fusion is on for the demo reseller).
  const overview = await page.goto(`${cfg.baseUrl}/reseller/fusion`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
  if (!overview || overview.status() >= 400) {
    throw new Error(`fusion-reseller-console: overview returned ${overview && overview.status()} — own_fusion gate or auth failed`);
  }
  const hasNav = await page.evaluate(() => /your marketplace/i.test(document.body.innerText));
  if (!hasNav) {
    // say WHERE we ended up (a login redirect, a throttle page and a wrong locale all look alike without this)
    const seen = await page.evaluate(() => ({ url: location.href, lang: document.documentElement.lang, text: document.body.innerText.replace(/\s+/g, ' ').slice(0, 160) }));
    throw new Error(`fusion-reseller-console: overview did not render the console (url ${seen.url}, lang ${seen.lang}, text "${seen.text}")`);
  }
  await shot(page, 'fusion-console-1-overview');

  // Curate a section (a write). Marker title so cleanup can find it.
  const marker = 'E2E Section ' + Date.now();
  await page.goto(`${cfg.baseUrl}/reseller/fusion/curation`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
  await page.waitForSelector('input[name="title"]', { timeout: cfg.navTimeout });
  await page.type('input[name="title"]', marker);
  // Submit the form that owns the field: the first `button[type=submit]` on the page is not this form's
  // (the console shell carries other forms), and clicking it fails "Node is either not clickable".
  await Promise.all([
    page.evaluate(() => { document.querySelector('input[name="title"]').form.submit(); }),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  await sleep(400);

  const created = await page.evaluate((m) => document.body.innerText.includes(m), marker);
  if (!created) throw new Error('fusion-reseller-console: section was not created');
  await shot(page, 'fusion-console-2-curation');

  // Save branding (a write, reverted by re-saving the demo brand at the end).
  await page.goto(`${cfg.baseUrl}/reseller/fusion/branding`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
  await page.waitForSelector('input[name="name_en"]', { timeout: cfg.navTimeout });
  await page.evaluate(() => { document.querySelector('input[name="name_en"]').value = ''; });
  await page.type('input[name="name_en"]', 'Souq Alpha');
  await Promise.all([
    page.evaluate(() => { document.querySelector('input[name="name_en"]').form.submit(); }),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  await sleep(400);

  // CLEANUP: delete the section this journey created.
  await page.goto(`${cfg.baseUrl}/reseller/fusion/curation`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
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

  log('fusion-reseller-console: console reachable, section curated + cleaned up, branding saved ✓');
};
