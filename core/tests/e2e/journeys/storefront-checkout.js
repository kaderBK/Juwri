// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Journey 1 — storefront add-to-cart → checkout on a LEGACY theme (crave).
 *
 * The fusion-crave run shipped 8 broken storefront controls under a fully-green
 * HTTP suite (memory fusion-crave-run-lessons): nothing clicked a real button.
 * This drives the real crave single-page storefront: open a product card, add
 * it to the cart (asserting the cart badge increments), open the cart, and
 * advance to checkout (asserting the checkout view becomes visible).
 *
 * Store: ThemePackDemoSeeder `themepack-demo`, store_template=crave.
 * Selectors verified against the live seeded storefront.
 */

module.exports = async function storefrontCheckout({ page, cfg, log, shot, sleep }) {
  const url = `${cfg.baseUrl}/${cfg.storeSlug}`;
  log(`storefront: ${url}`);
  const resp = await page.goto(url, { waitUntil: 'networkidle2' });
  if (resp && resp.status() >= 500) {
    throw new Error(`storefront: menu page returned ${resp.status()} (server error rendering the theme)`);
  }

  // Menu must render — a crash here is the $postOptions unguarded-read class.
  await page.waitForSelector('#addToCart, [data-open], main', { timeout: cfg.navTimeout });
  await shot(page, 'storefront-1-menu');

  // Open the first product card (crave: button.card[data-open]).
  const opened = await page.evaluate(() => {
    const c = document.querySelector('[data-open]:not(.mb-combo-sheet):not([data-open="0"])');
    if (c) { c.click(); return true; }
    return false;
  });
  if (!opened) throw new Error('storefront: no product card to open — menu is empty or markup changed');
  await sleep(900);

  // Add to cart.
  const added = await page.evaluate(() => {
    const a = document.querySelector('#addToCart');
    if (a && !a.disabled) { a.click(); return true; }
    return false;
  });
  if (!added) throw new Error('storefront: add-to-cart control missing or disabled');
  await sleep(900);
  await shot(page, 'storefront-2-added');

  // The cart badge must reflect the added line — proves the click did work.
  const badge = await page.evaluate(() => {
    const el = document.querySelector('#cvCartBadge');
    return el ? { hidden: el.hasAttribute('hidden'), text: (el.textContent || '').trim() } : null;
  });
  if (!badge || badge.hidden || badge.text === '' || badge.text === '0') {
    throw new Error(`storefront: cart badge did not increment after add-to-cart (${JSON.stringify(badge)})`);
  }

  // Open the cart, then advance to checkout.
  await page.evaluate(() => { const t = document.querySelector('[data-tab="cart"]'); if (t) t.click(); });
  await sleep(700);
  const wentCheckout = await page.evaluate(() => {
    const gc = document.querySelector('#cvGoCheckout');
    if (gc) { gc.click(); return true; }
    return false;
  });
  await sleep(900);
  await shot(page, 'storefront-3-checkout');

  const checkoutVisible = await page.evaluate(() => {
    const v = document.querySelector('[data-view="checkout"]');
    if (!v) return false;
    const r = v.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  });
  if (!wentCheckout || !checkoutVisible) {
    throw new Error('storefront: could not reach the checkout view — the go-checkout control is broken');
  }

  log('storefront: add-to-cart → checkout OK');
};
