// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Journey 4 (opt-in) — POS register sale.
 *
 * The POS register (/pos) is behind the session `staff` guard, so this journey
 * needs seeded staff credentials and the PosV2DemoSeeder data. It is OPT-IN
 * (set E2E_RUN_POS=1) so the default nightly run stays green without staff
 * seeding; when enabled it opens the register, adds an item and tenders a sale.
 *
 * Env: E2E_RUN_POS=1, E2E_STAFF_USER / E2E_STAFF_PASS (or owner creds fallback),
 * E2E_POS_STORE_ID (PosV2DemoSeeder restaurant id).
 */

module.exports = async function posSale({ page, cfg, log, shot, sleep }) {
  // Authenticate: real staff on /staff/login (email field) when E2E_STAFF_USER is set — the owner form at
  // /login rejects staff accounts, which left the register redirecting back to login — else the owner.
  const staffUser = process.env.E2E_STAFF_USER;
  if (staffUser) {
    await page.goto(`${cfg.baseUrl}/staff/login`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('input[name=email], #email', { timeout: cfg.navTimeout });
    await page.type('input[name=email], #email', staffUser);
    await page.type('input[name=password], #password', process.env.E2E_STAFF_PASS || '');
    await Promise.all([
      page.evaluate(() => document.querySelector('form').submit()),
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
    ]);
  } else {
    await page.goto(`${cfg.baseUrl}/login`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('#username', { timeout: cfg.navTimeout });
    await page.type('#username', cfg.ownerEmail);
    await page.type('#password', cfg.ownerPass);
    await Promise.all([
      page.click('button[type="submit"], input[type="submit"]'),
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
    ]);
  }
  await sleep(600);

  const resp = await page.goto(`${cfg.baseUrl}/pos`, { waitUntil: 'networkidle2' });
  if (resp && resp.status() >= 500) throw new Error(`pos: register returned ${resp.status()}`);
  await shot(page, 'pos-1-register');

  // Register shell must render an item grid + a tender/checkout control.
  const shell = await page.evaluate(() => ({
    onPos: location.pathname.includes('pos'),
    items: document.querySelectorAll('[data-item-id], .pos-item, .register-item, [data-role="pos-item"]').length,
    tender: !!document.querySelector('[data-role="tender"], #posPay, .tender, [data-action="pay"]'),
  }));
  if (!shell.onPos) {
    throw new Error('pos: not on the register (auth redirect) — seed staff + set E2E_STAFF_USER/PASS');
  }
  if (shell.items === 0) {
    throw new Error('pos: register shows no items — run PosV2DemoSeeder for E2E_POS_STORE_ID');
  }

  // Add the first plain item (no Options sheet) — POS v2 grid: button.pos-item.
  const added = await page.evaluate(() => {
    const items = [...document.querySelectorAll('button.pos-item:not(.is-combo):not(.is-sold)')];
    const plain = items.find((b) => !b.querySelector('.pos-badge.is-options:not([style*="display: none"])')) || items[0];
    if (!plain) return false;
    plain.click();
    return true;
  });
  if (!added) throw new Error('pos: no orderable item tile on the register');
  await sleep(600);
  await shot(page, 'pos-2-item-added');

  // J27-A2: the register sends ONE UUIDv7 order key per cart attempt — the same key on every retry of this cart
  // (double tap, lost answer, offline replay), and the key that actually goes out on the create POST.
  const V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
  const keyNow = await page.evaluate(() => {
    const d = window.Alpine && Alpine.$data(document.body);
    if (!d || typeof d.payload !== 'function') return null;
    return [d.payload(false).client_order_id, d.payload(false).client_order_id];
  });
  if (!keyNow || !V7.test(keyNow[0]) || keyNow[0] !== keyNow[1]) {
    throw new Error(`pos: expected one stable UUIDv7 order key per cart attempt, got ${JSON.stringify(keyNow)}`);
  }
  let sentKey = null;
  const onReq = (req) => {
    try {
      if (req.method() === 'POST' && /\/pos\/orders(\?|$)/.test(req.url().replace(cfg.baseUrl, ''))) {
        sentKey = (JSON.parse(req.postData() || '{}') || {}).client_order_id || sentKey;
      }
    } catch (_e) { /* not JSON */ }
  };
  page.on('request', onReq);

  // Phone layout: open the cart via the floating button, then tender cash.
  await page.evaluate(() => {
    const fab = document.querySelector('.pos-cart-fab');
    if (fab && fab.getClientRects().length) fab.click();
  });
  await sleep(400);
  const cashBtn = await page.$('.acts .cash:not([disabled])');
  if (!cashBtn) throw new Error('pos: no Cash tender control in the cart (cart empty?)');
  await cashBtn.click();
  await page.waitForSelector('.pos-settle .pos-settle-confirm', { visible: true, timeout: cfg.navTimeout });
  // Exact cash preset, then confirm.
  await page.evaluate(() => {
    const ex = [...document.querySelectorAll('.pos-settle button')].find((b) => /exact/i.test(b.textContent));
    if (ex) ex.click();
  });
  await sleep(300);
  await shot(page, 'pos-3-tendered');
  await page.click('.pos-settle .pos-settle-confirm:not([disabled])');
  await page.waitForFunction(() => {
    const el = document.querySelector('.pos-settle');
    return !el || getComputedStyle(el).display === 'none';
  }, { timeout: cfg.navTimeout }).catch(() => { throw new Error('pos: settle sheet did not close after confirming the cash sale'); });
  // confirmSettle closes the sheet first, then places + settles the order (two POSTs) and resets the cart: wait for
  // that to finish instead of a fixed pause (a MySQL-backed Herd site answers slower than the sqlite runner).
  await page.waitForFunction(() => { const d = Alpine.$data(document.body); return !d.sending && d.cart.length === 0; },
    { timeout: cfg.navTimeout }).catch(() => {});
  page.off('request', onReq);
  if (sentKey !== keyNow[0]) throw new Error(`pos: the create POST carried ${sentKey}, expected the attempt key ${keyNow[0]}`);
  const keyAfter = await page.evaluate(() => Alpine.$data(document.body).orderKey);
  if (keyAfter) throw new Error(`pos: the order key was not cleared after the finished sale (${keyAfter})`);
  await shot(page, 'pos-4-after-sale');
  log(`pos: item added + sale tendered OK (order key ${keyNow[0]} sent once, cleared after the sale)`);
};
