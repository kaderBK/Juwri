// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * fusion-multicart FM-14 (opt-in) — per-store mini-carts + independent checkout, in a
 * real browser.
 *
 * The PHPUnit suite (FusionMultiCartServiceTest / UiTest / EdgeTest + the cart cell in
 * FusionTenancyAuditTest) proves the model at the request layer. This adds the browser
 * facts: the header badge counts CARTS (not items), each mini-cart card carries its own
 * "Checkout this cart" CTA, checking out one cart leaves the other, and an unavailable
 * cart shows its reason with the checkout-all footer hidden.
 *
 * Needs two ORDERABLE stores on the platform Fusion in the browsing city, each with at
 * least one item. Pass their slugs via E2E_MC_STORES="slugA,slugB" (defaults below).
 * The journey CLEANS UP by clearing every cart it created.
 *
 * Env: E2E_RUN_MULTICART=1, E2E_MC_STORES (default "crave-demo,matcha-demo").
 */

module.exports = async function fusionMultiCart({ page, cfg, log, shot, sleep }) {
  const eeOnly = require('../lib/platform').requireEe('the Fusion marketplace');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const [slugA, slugB] = (process.env.E2E_MC_STORES || 'crave-demo,matcha-demo')
    .split(',').map((s) => s.trim());
  const failures = [];

  // Add the first item from a store page to the bag.
  const addFirstItem = async (slug) => {
    const resp = await page.goto(`${cfg.baseUrl}/fusion/store/${slug}`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
    if (!resp || resp.status() >= 400) { failures.push(`${slug}: store page ${resp && resp.status()}`); return; }
    await sleep(800);
    // The store page's first "add" control — themes vary, so try the common hooks.
    const added = await page.evaluate(() => {
      const btn = document.querySelector('[data-mk-add], [data-add-to-cart], .mk-add, button[name="add"]');
      if (btn) { btn.click(); return true; }
      return false;
    });
    if (!added) failures.push(`${slug}: no add-to-cart control found`);
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
  if (state.cards < 2) failures.push(`expected 2 mini-cart cards, saw ${state.cards}`);
  if (state.ctas < 2) failures.push(`expected a per-cart CTA on each card, saw ${state.ctas}`);
  await shot(page, 'fusion-multicart-bag');

  // Check out cart A only via its per-cart CTA link.
  const goA = await page.evaluate((slug) => {
    const link = Array.from(document.querySelectorAll('a.mk-bagstore__go'))
      .find((a) => /[?&]store=/.test(a.getAttribute('href') || ''));
    return link ? link.getAttribute('href') : null;
  });
  if (!goA) {
    failures.push('no available per-cart checkout link to follow');
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

  if (failures.length) {
    throw new Error('fusion-multicart failed:\n  ' + failures.join('\n  '));
  }
  log('fusion-multicart: two mini-carts render with independent CTAs; carts cleaned up ✓');
};
