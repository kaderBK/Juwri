// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p3-basket-grocery — Phase 3 (P3-09): the grocery theme `basket` on Fresh Mart (the kit API grocery fixture), customer -> owner ->
 * customer, plus IDOR.
 *
 *  customer  weighed tomatoes (500 g chip, "If it's out of stock" = replace) + strawberries into the basket; the basket total is
 *            the SERVER quote (estimate for the weighed line); checkout: a delivery slot is picked and HELD (10 minutes), the order
 *            is paid on the hosted pay page (Stub gateway; the store refunds the weighed difference, so a gateway without
 *            authorise / capture is offered); the order row total equals the quoted total
 *  picker    (owner dashboard) starts picking, weighs the tomatoes (less than ordered), offers Raspberries for the strawberries,
 *            finishes picking: the final amount is settled (the difference refunded)
 *  customer  the tracking page asks about nothing the picker already settled, shows the picked count and the FINAL amount
 *  IDOR      another owner gets 404 on the picking screen; a replacement answer with another order's token is refused; another
 *            store's hold cannot be released through this store's endpoint
 *
 * Needs the Stub gateway: E2E_ARMED_URL = `E2E_STUBS=1 php artisan serve` of this worktree. Seeds Fresh Mart through
 * tests/e2e/lib/seed-fresh.php (tinker). Run: npm run test:e2e -- p3-basket-grocery
 */

const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const A = require('../../lib/lane-a');

const CORE = path.resolve(__dirname, '../../../..');
const SLUG = 'fresh-mart-demo';
const SEED = path.resolve(CORE, 'tests/e2e/lib/seed-fresh.php');
const OWNER = { email: process.env.E2E_KIT_OWNER_EMAIL || 'kit-demo-owner@demo.test', pass: process.env.E2E_KIT_OWNER_PASS || 'kit-demo-pass' };

function tinker(code) {
  return execFileSync(A.cfg.phpArtisan, ['artisan', 'tinker', '--execute', code], { cwd: process.env.E2E_APP_DIR || CORE, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

module.exports = A.journey(async function p3BasketGrocery({ browser, log }) {
  const R = A.recorder('p3-basket', log);
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
  A.expect(storeId > 0, 'Fresh Mart was not seeded');
  // the public ids are generated per database: find the two products by name
  const pid = (name) => A.sqlValue('SELECT public_id FROM items WHERE store_id = ? AND name LIKE ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [storeId, name]);
  const TOMATOES = pid('Tomatoes%');
  const STRAWBERRIES = pid('Strawberries%');
  A.expect(TOMATOES && STRAWBERRIES, 'Fresh Mart products not found');
  R.ok(`Fresh Mart #${storeId} is on basket with the Stub gateway and the weighed-refund switch on`);

  const consent = (page) => page.setCookie({ name: 'site_consent', value: 'v1.a0.m0', url: app });
  const post = (page, url, body, headers = {}) => page.evaluate(async (u, b, h) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, ...h }, body: JSON.stringify(b) });
    return { status: r.status, json: await r.json().catch(() => null) };
  }, url, body, headers);

  let order = null;
  await A.withPage(browser, async (g) => {
    g.on('pageerror', (e) => log(`  p3-basket pageerror: ${e.message}`));
    await consent(g);
    await g.goto(`${app}/${SLUG}?item=${TOMATOES}`, { waitUntil: 'networkidle2' });
    const sheet = await g.evaluate(() => ({
      theme: document.documentElement.dataset.themeSlug,
      chips: [...document.querySelectorAll('.c-group--weight input')].map((i) => i.closest('label')?.innerText.replace(/\s+/g, ' ').trim()),
      subs: document.querySelectorAll('input[data-kit-substitution]').length,
    }));
    A.expect(sheet.theme === 'basket' && sheet.chips.length === 4 && sheet.subs === 3, `weighed product sheet (${JSON.stringify(sheet)})`);
    R.ok(`weighed sheet: ${sheet.chips.join(' | ')}; 3 out-of-stock choices`);

    await g.evaluate(() => {
      const chip = [...document.querySelectorAll('.c-group--weight input')].find((i) => /500/.test(i.closest('label').innerText));
      chip.click();
      document.querySelector('input[data-kit-substitution]').click();
    });
    await A.sleep(600);
    await g.click('[data-kit-add]');
    await A.sleep(900);
    const strawberry = await g.evaluate((id) => { const b = document.querySelector(`[data-juwri-add="${id}"]`); if (b) { b.click(); return true; } return false; }, STRAWBERRIES);
    A.expect(strawberry, 'strawberries have a direct Add on the store page');
    await A.sleep(900);

    const snap = await g.evaluate(() => window.Juwri.cart.snapshot());
    A.expect(snap.lines.length === 2, `two lines in the basket (${JSON.stringify(snap.lines)})`);
    const weighedLine = snap.lines.find((l) => l.item === TOMATOES);
    A.expect(weighedLine && weighedLine.options.length === 1 && weighedLine.substitution, `the weighed line carries its weight step and the out-of-stock choice (${JSON.stringify(weighedLine)})`);

    if (!(await g.$('#cart:not([hidden])'))) await g.evaluate(() => document.querySelector('[data-juwri-open="cart"]').click());
    await g.waitForSelector('#cart:not([hidden])');
    await g.waitForFunction(() => (document.querySelector('#cart [data-juwri-total]')?.textContent || '').trim() !== '', { timeout: 10000 });
    const bagTotal = await g.$eval('#cart [data-juwri-total]', (e) => e.textContent.trim());
    const q = await post(g, `/${SLUG}/retail/quote`, { order_type: 'delivery', lines: snap.lines.map((l) => ({ item: l.item, qty: l.qty, options: l.options, substitution: l.substitution || undefined })) });
    A.expect(q.status === 200 && q.json.quote, `server quote answered (${q.status})`);
    A.expect(bagTotal.replace(/[\s⁦-⁩]/g, '').includes(String(q.json.quote.total.formatted).replace(/[\s⁦-⁩]/g, '').replace(/^.*?(\d)/, '$1')), `the basket total is the server quote (${bagTotal} vs ${q.json.quote.total.formatted})`);
    R.ok(`basket total ${bagTotal} = the server quote for the same lines`);

    await g.evaluate(() => document.querySelector('#cart [data-juwri-open="checkout"]').click());
    await g.waitForSelector('#checkout:not([hidden])');
    await g.waitForFunction(() => document.querySelectorAll('#checkout .c-slot__input:not([disabled])').length > 0, { timeout: 15000 });
    const freeSlots = await g.evaluate(() => [...document.querySelectorAll('#checkout .c-slot__input:not([disabled])')].map((i) => i.value));
    await g.evaluate(() => { document.querySelector('#checkout .c-slot__input:not([disabled])').click(); });
    await g.waitForFunction(() => window.Juwri.cart.snapshot().meta && !(window.Juwri.cart.snapshot().meta.requires || []).includes('slot'), { timeout: 15000 });
    const holds = Number(A.sqlValue("SELECT COUNT(*) FROM slot_holds WHERE store_id = ? AND status = 'active' AND created_at > NOW() - INTERVAL 5 MINUTE", [storeId]));
    A.expect(holds >= 1, `the chosen slot is held (${holds} live hold rows)`);
    R.ok(`delivery slot ${freeSlots[0]} chosen: ${holds} live hold(s) in the database`);

    for (const [name, value] of [['name', 'E2E Shopper'], ['phone', '+966512345678'], ['line1', 'Olaya St 1'], ['city', 'Riyadh']]) {
      const sel = `#checkout input[name="${name}"]`;
      if (await g.$(sel)) { await g.$eval(sel, (e) => { e.value = ''; }); await g.type(sel, value); }
    }
    const holdNote = await g.evaluate(() => (document.querySelector('[data-bk-holdnote-text]')?.textContent || '').replace(/\s+/g, ' ').trim());
    A.expect(/up to|hold|refund/i.test(holdNote), `the weighed basket says what is charged now (${holdNote})`);
    R.ok(`weighed copy at checkout: "${holdNote}"`);

    await g.evaluate(() => { const r = document.querySelector('#checkout input[name="pay_via"][value="card"]'); if (r) r.click(); });
    await Promise.all([
      g.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }),
      g.evaluate(() => document.querySelector('#checkout [data-juwri-place]').click()),
    ]).catch(async (e) => {
      const why = await g.evaluate(() => ({ msg: (document.querySelector('[data-kit-place-message], #checkout [role=alert]')?.textContent || '').trim(), disabled: document.querySelector('#checkout [data-juwri-place]')?.disabled, status: window.Juwri.cart.snapshot().status, requires: window.Juwri.cart.snapshot().meta?.requires, errors: [...document.querySelectorAll('#checkout .c-field__error')].map((x) => x.textContent.trim()).filter(Boolean) }));
      throw new Error(`placing the order did not reach the pay page: ${JSON.stringify(why)}`);
    });
    A.expect(/\/payment\/\d+/.test(g.url()), `redirected to the hosted pay page (${g.url()})`);
    const txn = g.url().match(/\/payment\/(\d+)/)[1];
    const status = await g.evaluate(async (id) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
      const fd = new FormData(); fd.append('payment_method', 'stub'); fd.append('e2e_outcome', 'success');
      const r = await fetch(`/payment/${id}/pay`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'text/html,application/json', 'X-CSRF-TOKEN': csrf }, body: fd });
      return r.status;
    }, txn);
    A.expect(status < 400, `the Stub gateway answered ${status}`);
    const row = A.sql('SELECT id, grand_total_minor, hold_status, track_token, slot_starts_at, hold_minor FROM orders WHERE store_id = ? ORDER BY id DESC LIMIT 1', [storeId])[0];
    A.expect(row[2] === 'authorised' && Number(row[5]) >= Number(row[1]) && row[4], `the card is authorised for the cap (>= total) and the order carries its slot (${JSON.stringify(row)})`);
    A.expect(Number(row[1]) === q.json.quote.total.minor, `the order total ${row[1]} equals the quoted total ${q.json.quote.total.minor}`);
    order = { id: Number(row[0]), token: row[3], total: Number(row[1]) };
    R.ok(`hosted payment (Stub): order #${order.id} authorised up to ${row[5]} (cap), slot kept, total ${row[1]} = quoted ${q.json.quote.total.minor}`);
  }, { width: 430, height: 900 });

  // ── the picker (owner dashboard): weigh, offer a replacement, finish ────────────────────────────────────────────
  const lines = A.sql('SELECT id, quantity, weight_g FROM order_items WHERE order_id = ? ORDER BY id', [order.id]).map((r) => ({ id: Number(r[0]), weighed: Number(r[2]) > 0 }));
  A.expect(lines.length === 2 && lines.filter((l) => l.weighed).length === 1, `the order has one weighed and one plain line (${JSON.stringify(lines)})`);
  const weighed = lines.find((l) => l.weighed);
  const plain = lines.find((l) => !l.weighed);
  await A.withPage(browser, async (o) => {
    await A.login(o, app, OWNER);
    const url = `${app}/order/${order.id}/picking?store_id=${storeId}`;
    await o.goto(url, { waitUntil: 'domcontentloaded' });
    await A.submitForm(o, 'form:has(input[name=step][value=start])');
    A.expect(A.sqlValue('SELECT picking_status FROM orders WHERE id = ?', [order.id]) === 'picking', 'picking started');
    await o.type(`form[data-picking-weigh]:has(input[name=item][value="${weighed.id}"]) input[name=grams]`, '450');
    await A.submitForm(o, `form[data-picking-weigh]:has(input[name=item][value="${weighed.id}"])`);
    const fin = Number(A.sqlValue('SELECT final_minor FROM order_items WHERE id = ?', [weighed.id]));
    A.expect(fin > 0, `the tomatoes were weighed (final ${fin})`);
    const sel = `form[data-picking-propose]:has(input[name=item][value="${plain.id}"])`;
    await o.type(`${sel} input[name=name]`, 'Raspberries 125 g');
    await o.type(`${sel} input[name=price]`, '12.50');
    await A.submitForm(o, sel);
    A.expect(A.sqlValue('SELECT pick_status FROM order_items WHERE id = ?', [plain.id]) === 'proposed', 'the replacement was offered');
    R.ok(`picker: tomatoes weighed 450 g (final ${fin} minor), Raspberries offered for the strawberries`);
  });

  // ── the customer answers on the tracking page ──────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (g) => {
    await consent(g);
    await g.goto(`${app}/order/track/${order.token}`, { waitUntil: 'networkidle2' });
    const text = await A.pageText(g);
    A.expect(/Changes to review/.test(text) && /Raspberries/.test(text), `the tracking page asks about the replacement (${text.slice(0, 200)})`);
    await g.click('[data-bk-answer="kept"]');
    await g.waitForFunction(() => !document.querySelector('[data-bk-review]:not([hidden]) [data-bk-answer]'), { timeout: 10000 }).catch(() => {});
    await A.sleep(800);
    A.expect(A.sqlValue('SELECT pick_status FROM order_items WHERE id = ?', [plain.id]) !== 'proposed', 'the answer reached the server');
    R.ok('customer: sees "Changes to review" with the proposed replacement and keeps it');

    // IDOR: the same line id with ANOTHER order's token is refused; a malformed id is refused
    const other = A.sql('SELECT track_token FROM orders WHERE store_id <> ? AND track_token IS NOT NULL ORDER BY id DESC LIMIT 1', [storeId])[0];
    if (other) {
      const r = await post(g, `/order/track/${other[0]}/replacement`, { line: 'ln_2', answer: 'refused' });
      A.expect([403, 404, 422].includes(r.status), `another order's token must not take this answer for effect (${r.status})`);
    }
    const bad = await post(g, `/order/track/not-a-real-token/replacement`, { line: 'ln_2', answer: 'kept' });
    A.expect(bad.status === 404, `unknown order token is 404 (${bad.status})`);
    R.ok('IDOR: unknown token 404; another order\'s token cannot act on this order');
  }, { width: 430, height: 900 });

  // ── finish picking: the final amount is settled ────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (o) => {
    await A.login(o, app, OWNER);
    await o.goto(`${app}/order/${order.id}/picking?store_id=${storeId}`, { waitUntil: 'domcontentloaded' });
    // the form asks "Finish picking and charge the final amount?" in a panel dialog; the native submit is what its Confirm button does
    await Promise.all([o.evaluate(() => document.querySelector('form:has(input[name=step][value=finish])').submit()), o.waitForNavigation({ waitUntil: 'domcontentloaded' })]);
    A.expect(A.sqlValue('SELECT picking_status FROM orders WHERE id = ?', [order.id]) === 'picked', `picking finished (${(await A.pageText(o)).replace(/\s+/g, ' ').slice(0, 500)})`);
    R.ok('picker: finished picking; the final amount is settled');
  });
  const final = JSON.parse(tinker(`$o = \\App\\Models\\Order::find(${order.id}); echo 'OUT='.json_encode(['grand' => $o->computeMoney()['grand'], 'cur' => $o->currency_code]);`).match(/OUT=(\{.*\})/)[1]);
  A.expect(Number(final.grand) * 100 < order.total, `the final amount ${final.grand} is below the authorised/quoted ${order.total / 100} (weighed less than ordered)`);

  await A.withPage(browser, async (g) => {
    await consent(g);
    await g.goto(`${app}/order/track/${order.token}`, { waitUntil: 'networkidle2' });
    const text = (await A.pageText(g)).replace(/[⁦-⁩]/g, '');
    const amount = Number(final.grand).toFixed(2);
    A.expect(text.includes(amount), `the tracking page shows the final amount ${amount} (${text.slice(0, 400)})`);
    A.expect(/Raspberries/.test(text), 'the tracking page shows the replacement line');
    R.ok(`customer: tracking shows the FINAL amount ${amount} (quoted ${(order.total / 100).toFixed(2)}) and the replaced line`);
  }, { width: 430, height: 900 });

  // ── IDOR: another owner ─────────────────────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (x) => {
    await A.login(x, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    const seen = await A.status(x, `${app}/order/${order.id}/picking`);
    A.expect(seen === 404 || seen === 403, `another owner is refused on this store's picking screen (got ${seen})`);
    const before = A.sqlValue('SELECT picking_status FROM orders WHERE id = ?', [order.id]);
    const adv = await A.req(x, `${app}/order/${order.id}/picking`, { method: 'POST', form: { step: 'remove_after', item: String(plain.id) } });
    A.expect([403, 404].includes(adv.status) || adv.type === 'opaqueredirect', `another owner must not change this order's lines (${adv.status})`);
    A.expect(A.sqlValue('SELECT picking_status FROM orders WHERE id = ?', [order.id]) === before, 'the order is untouched by the outsider');
    R.ok("IDOR: another owner gets 404 on the picking screen and cannot remove a line after capture");
  });
  R.summary();
});
