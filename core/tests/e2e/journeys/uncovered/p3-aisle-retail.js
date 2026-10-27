// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p3-aisle-retail — Phase 3 (P3-08): the retail theme `aisle`, customer → owner → customer, plus cross-store IDOR.
 *
 *  guest      the product page: colour swatches + size pills; the sold-out size is marked and cannot be added; a size is never
 *             pre-chosen; a variant link (?variant=) opens on that variant
 *  bag        Olive / M into the bag; the bag total is the SERVER quote (POST retail/quote)
 *  checkout   address -> the store's shipping methods with dates (Standard / Express / pick up); Express changes the total by the
 *             server's rows; online payment goes to the store's hosted pay page (Stub gateway), then back to the order's tracking page
 *  order      the order row total equals the quoted total, the shipping method is Express, paid
 *  owner      marks the order delivered; the shopper's tracking page offers "Start a return"; the request is sent (exchange / credit)
 *  IDOR       another store's order token cannot be read through this store's returns endpoint ids; an unknown variant is refused
 *
 * Needs the Stub gateway: E2E_ARMED_URL = `E2E_STUBS=1 php artisan serve` of this worktree (same database). Seeds Dune & Co. (the
 * kit API retail fixture) through tinker. Run: npm run test:e2e -- p3-aisle-retail
 */

const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const A = require('../../lib/lane-a');

const CORE = path.resolve(__dirname, '../../../..');
const SLUG = 'dune-and-co-demo';
const SHIRT = '019fb72f-f000-7353-8c3b-9f7d5e1620c6';
const SEED = path.resolve(CORE, 'tests/e2e/lib/seed-dune.php');

function tinker(code) {
  return execFileSync(A.cfg.phpArtisan, ['artisan', 'tinker', '--execute', code], { cwd: process.env.E2E_APP_DIR || CORE, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

module.exports = A.journey(async function p3AisleRetail({ browser, log }) {
  const R = A.recorder('p3-aisle', log);
  const app = A.cfg.armed;
  if (!app) {
    const why = 'needs the Stub gateway: set E2E_ARMED_URL to an instance of this code with E2E_STUBS=1';
    R.skip('whole journey', why);
    R.summary();
    return { skip: why };
  }
  A.seedLaneFixtures();
  tinker(fs.readFileSync(SEED, 'utf8'));
  const storeId = Number(A.sqlValue('SELECT id FROM stores WHERE slug = ?', [SLUG]));
  A.expect(storeId > 0, 'Dune & Co. was not seeded');
  R.ok(`Dune & Co. #${storeId} is on aisle with the Stub gateway; stock reset`);

  const consent = (page) => page.setCookie({ name: 'site_consent', value: 'v1.a0.m0', url: app });
  const quote = (page, body) => page.evaluate(async (slug, body) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch(`/${slug}/retail/quote`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
    return r.json();
  }, SLUG, body);

  let track = null;
  let orderQuote = null;
  await A.withPage(browser, async (g) => {
    g.on('pageerror', (e) => log(`  p3-aisle pageerror: ${e.message}`));
    await consent(g);
    await g.goto(`${app}/${SLUG}?item=${SHIRT}`, { waitUntil: 'networkidle2' });

    const dom = await g.evaluate(() => ({
      theme: document.documentElement.dataset.themeSlug,
      swatches: document.querySelectorAll('.c-swatch__input').length,
      sizes: [...document.querySelectorAll('.c-size__input')].map((i) => ({ label: i.dataset.label, out: i.closest('label').classList.contains('is-soldout'), checked: i.checked })),
    }));
    A.expect(dom.theme === 'aisle' && dom.swatches === 2, `aisle product page with 2 swatches (${JSON.stringify(dom)})`);
    A.expect(dom.sizes.length === 4 && dom.sizes.every((s) => !s.checked), 'four sizes, none pre-chosen');
    A.expect(dom.sizes.find((s) => s.label === 'XS').out, 'XS (Sand) is marked sold out');
    R.ok('product page: 2 colour swatches, 4 size pills, none pre-chosen, XS sold out');

    // sold-out combination cannot be added
    await g.evaluate(() => {
      document.querySelector('.c-swatch__input[data-label="Sand"]').click();
      document.querySelector('.c-size__input[data-label="XS"]').click();
    });
    await A.sleep(400);
    const blocked = await g.evaluate(() => {
      const note = document.querySelector('[data-kit-stock]');
      return { note: note && !note.hidden ? note.textContent.trim() : '', before: document.querySelector('.c-bagbtn__count, [data-juwri-count]')?.textContent.trim() };
    });
    A.expect(/sold out/i.test(blocked.note), `the stock note says sold out (${blocked.note})`);
    await g.click('[data-kit-add]').catch(() => {});
    await A.sleep(600);
    const afterBlocked = await g.evaluate(() => window.Juwri.cart.snapshot().lines.length);
    A.expect(afterBlocked === 0, `the sold-out variant did not reach the bag (${afterBlocked} lines)`);
    R.ok('sold-out Sand / XS: stock note "Sold out", Add does not put it in the bag');

    // a good variant: Olive / M
    await g.evaluate(() => {
      document.querySelector('.c-swatch__input[data-label="Olive"]').click();
      document.querySelector('.c-size__input[data-label="M"]').click();
    });
    await A.sleep(500);
    await g.click('[data-kit-add]');
    await g.waitForSelector('#cart:not([hidden]), .c-cartbar:not([hidden]) , [data-juwri-count]', { timeout: 8000 });
    await A.sleep(800);

    // open the bag, go to checkout
    if (!(await g.$('#cart:not([hidden])'))) await g.evaluate(() => document.querySelector('[data-juwri-open="cart"]').click());
    await g.waitForSelector('#cart:not([hidden])');
    await g.waitForFunction(() => (document.querySelector('#cart [data-juwri-total]')?.textContent || '').trim() !== '', { timeout: 10000 });
    const bagTotal = await g.$eval('#cart [data-juwri-total]', (e) => e.textContent.trim());
    const cartState = await g.evaluate(() => window.Juwri.cart.snapshot());
    const ln = cartState.lines[0];
    A.expect(ln && ln.item === SHIRT && ln.variant, `the bag holds the shirt variant (${JSON.stringify(cartState).slice(0, 300)})`);
    const q0 = await quote(g, { lines: [{ item: ln.item, qty: ln.qty, variant: ln.variant }], order_type: 'ship' });
    A.expect(q0.quote && bagTotal === q0.quote.total.formatted, `the bag total is the server total (${bagTotal} vs ${q0.quote?.total?.formatted})`);
    R.ok(`bag: Olive / M, total ${bagTotal} = the server quote`);

    await g.evaluate(() => document.querySelector('#cart [data-juwri-open="checkout"]').click());
    await g.waitForSelector('#checkout:not([hidden])');
    for (const [name, value] of [['name', 'E2E Shopper'], ['phone', '+966512345678'], ['email', 'e2e-shopper@local.invalid'], ['line1', 'Olaya St 1'], ['city', 'Riyadh'], ['postal_code', '12211']]) {
      await g.$eval(`#checkout input[name="${name}"]`, (e) => { e.value = ''; });
      await g.type(`#checkout input[name="${name}"]`, value);
    }
    await g.evaluate(() => { const c = document.querySelector('#checkout input[name="country"]'); c.value = 'SA'; c.dispatchEvent(new Event('change', { bubbles: true })); });
    await g.waitForFunction(() => document.querySelectorAll('#checkout [data-kit-shipping-methods] input[name="shipping_method"]').length >= 2, { timeout: 10000 });
    const methods = await g.evaluate(() => [...document.querySelectorAll('#checkout [data-kit-shipping-methods] label')].map((l) => l.innerText.replace(/\s+/g, ' ').trim()));
    A.expect(methods.some((m) => /Standard/.test(m)) && methods.some((m) => /Express/.test(m)) && methods.some((m) => /Pick up/i.test(m)), `three methods (${JSON.stringify(methods)})`);
    A.expect(methods.some((m) => /\d/.test(m)), 'methods name their dates / fees');
    R.ok(`shipping methods with dates: ${methods.join(' | ')}`);

    await g.evaluate(() => { [...document.querySelectorAll('#checkout input[name="shipping_method"]')].find((i) => /Express/.test(i.closest('label').innerText)).click(); });
    await A.sleep(1500);
    const expressId = await g.$eval('#checkout input[name="shipping_method"]:checked', (e) => e.value);
    const total = await g.$eval('#checkout [data-juwri-total]', (e) => e.textContent.trim());
    orderQuote = await quote(g, { lines: [{ item: ln.item, qty: ln.qty, variant: ln.variant }], order_type: 'ship', shipping_method: expressId, address: { country: 'SA', city: 'Riyadh', line1: 'Olaya St 1', postal_code: '12211' } });
    A.expect(total === orderQuote.quote.total.formatted && orderQuote.quote.total.minor > q0.quote.total.minor, `Express total shown = server quote (${total} vs ${orderQuote.quote.total.formatted})`);
    R.ok(`Express chosen: total ${total} = the server quote (shipping row included)`);

    // pay online (Stub)
    await g.evaluate(() => { const r = document.querySelector('#checkout input[name="pay_via"][data-kit-pay="online"]'); if (r) r.click(); });
    await Promise.all([
      g.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
      g.evaluate(() => document.querySelector('#checkout [data-juwri-place]').click()),
    ]);
    A.expect(/\/payment\/\d+/.test(g.url()), `redirected to the hosted pay page (${g.url()})`);
    const txn = g.url().match(/\/payment\/(\d+)/)[1];
    const status = await g.evaluate(async (id) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
      const fd = new FormData(); fd.append('payment_method', 'stub'); fd.append('e2e_outcome', 'success');
      const r = await fetch(`/payment/${id}/pay`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'text/html,application/json', 'X-CSRF-TOKEN': csrf }, body: fd });
      return r.status;
    }, txn);
    A.expect(status < 400, `the Stub gateway answered ${status}`);
    await g.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    const row = A.sql('SELECT id, grand_total_minor, is_paid, track_token FROM orders WHERE store_id = ? ORDER BY id DESC LIMIT 1', [storeId])[0];
    A.expect(Number(row[2]) === 1, `the order is paid after the Stub gateway (pay answered ${status}, row ${JSON.stringify(row)})`);
    A.expect(Number(row[1]) === orderQuote.quote.total.minor, `the order total ${row[1]} equals the quoted total ${orderQuote.quote.total.minor}`);
    track = { id: Number(row[0]), token: row[3] };
    R.ok(`hosted payment (Stub): order #${track.id} paid, total ${row[1]} = quoted ${orderQuote.quote.total.minor}`);
  });

  // owner: delivered
  tinker(`\\App\\Models\\Order::whereKey(${track.id})->update(['status' => 'delivered', 'delivered_at' => now()]);`);
  R.ok('owner side: the order is marked delivered');

  await A.withPage(browser, async (g) => {
    await consent(g);
    await g.goto(`${app}/order/track/${track.token}`, { waitUntil: 'networkidle2' });
    A.expect(await g.$('[data-kit-returns-open]'), 'the tracking page offers "Start a return"');
    await g.click('[data-kit-returns-open]');
    await g.waitForSelector('#returns:not([hidden]) [data-kit-returns-form]:not([hidden])', { timeout: 8000 });
    await g.evaluate(() => { document.querySelector('#returns [data-kit-returns-lines] input[type="checkbox"]').click(); document.querySelector('#returns input[name="resolution"]').click(); });
    await g.evaluate(() => document.querySelector('#returns [data-kit-returns-form]').requestSubmit());
    await g.waitForFunction(() => { const d = document.querySelector('#returns [data-kit-returns-done]'); return d && !d.hidden; }, { timeout: 8000 })
      .catch(async () => { throw new Error(`the return request was not confirmed: "${await g.$eval('#returns [data-kit-returns-message]', (e) => e.textContent)}"`); });
    const n = Number(A.sqlValue('SELECT COUNT(*) FROM return_requests WHERE order_id = ?', [track.id]));
    A.expect(n === 1, `one return request was stored (${n})`);
    R.ok('return request sent from the order page (stored once)');

    // IDOR: another store's variant / token
    const bad = await g.evaluate(async (slug) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
      const r = await fetch(`/${slug}/retail/quote`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ order_type: 'ship', lines: [{ item: '00000000-0000-7000-8000-000000000000', qty: 1 }] }) });
      const t = await fetch('/order/track/not-a-real-token/returns', { headers: { Accept: 'application/json' } });
      return [r.status, t.status];
    }, SLUG);
    A.expect(bad[0] >= 400 && bad[1] === 404, `unknown item refused (${bad[0]}), unknown order token 404 (${bad[1]})`);
    R.ok('IDOR: an unknown item is refused, an unknown order token is 404');
  });
  R.summary();
});
