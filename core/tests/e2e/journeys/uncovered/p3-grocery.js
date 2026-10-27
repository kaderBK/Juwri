// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p3-grocery — Phase 3 (P3-00): the new "grocery" store type, owner → customer → owner, plus cross-store IDOR.
 *
 *  owner      creates the store through the dashboard wizard (store type "Grocery store", theme set retail/goods), a
 *             category and two stock-tracked products; the Fulfilment page offers Delivery and Pickup (and not Ship to
 *             address / In-store), both switched on; order scheduling on
 *  customer   storefront (inherited theme `zevoa` until the kit theme `basket` lands, P3-09): adds products to the
 *             cart, checks out on the goods checkout page — Delivery needs an address, Pickup needs a time slot, the
 *             stock limit holds, Ship is refused; stock drops when the order is placed
 *  owner      sees both orders on the goods order board (type tag) and moves one New → Packing → Ready → Collected →
 *             Done; the customer's tracking page follows
 *  IDOR       another owner gets 404 on this store's products and board and cannot advance an order; a customer cannot
 *             put another store's product into this store's cart
 *
 * Reported, not invented: weighed items (sold by kg), pack-size unit prices, delivery slots with capacity and
 * substitutions arrive with P3-09; a delivery order ends the goods board as "Collected" (the goods state machine only
 * has Shipped for the ship mode).
 *
 * Owner and customer steps run on E2E_ARMED_URL when set, else on E2E_BASE_URL.
 *
 * Run: npm run test:e2e -- p3-grocery
 */

const A = require('../../lib/lane-a');

const SLUG = 'e2ea-grocery';
const TITLE = 'e2eA Grocery';
const CATEGORY = `${A.PREFIX}Dairy and dates`;
const MILK = { name: `${A.PREFIX}Milk 1L`, price: '7.25', stock: 10 };
const DATES = { name: `${A.PREFIX}Dates 500g`, price: '18.00', stock: 3 };

module.exports = A.journey(async function p3Grocery({ browser, log }) {
  const R = A.recorder('p3-grocery', log);
  const app = A.cfg.armed || A.cfg.baseUrl;
  const stamp = Date.now();

  // ── owner: store, products, fulfilment, scheduling ──────────────────────────────────────────────────────────────
  const owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical', 'scheduled_orders'] });
  const store = await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const s = await A.ensureStore(page, app, { type: 'grocery', title: TITLE, slug: SLUG, template: 'zevoa' });
    A.expect(A.sqlValue('SELECT store_type FROM stores WHERE id = ?', [s.id]) === 'grocery', 'the store was not created as a grocery store');
    const cat = await A.ensureProductCategory(page, app, s.id, CATEGORY);
    const milk = await A.ensureProduct(page, app, s.id, { ...MILK, categoryId: cat });
    const dates = await A.ensureProduct(page, app, s.id, { ...DATES, categoryId: cat });
    // stock back to the start (a previous run sold some)
    for (const [p, stock] of [[milk, MILK.stock], [dates, DATES.stock]]) {
      await page.goto(`${app}/stores/${s.id}/products/${p.id}/edit`, { waitUntil: 'domcontentloaded' });
      const f = `form[action$="/stores/${s.id}/products/${p.id}"]`;
      await page.evaluate((sel, q) => {
        const f2 = document.querySelector(sel);
        f2.querySelectorAll('input[name=price], input[name=cost], input[name=weight]').forEach((i) => { if (!i.getAttribute('step')) i.setAttribute('step', 'any'); });
        const e = f2.querySelector('input[name=stock_qty]'); e.value = String(q); e.dispatchEvent(new Event('input', { bubbles: true }));
        const t = f2.querySelector('input[name=track_inventory]'); if (t && !t.checked) t.click();
      }, f, stock);
      await A.submitForm(page, f);
    }
    A.expect(Number(A.sqlValue('SELECT stock_qty FROM items WHERE id = ?', [milk.id])) === MILK.stock, 'milk stock must be reset');

    // Fulfilment page: only the modes of the grocery type
    await page.goto(`${app}/fulfillment?store_id=${s.id}`, { waitUntil: 'domcontentloaded' });
    const modes = await page.evaluate(() => [...document.querySelectorAll('input[name="modes[]"]')].map((i) => i.value));
    A.expect(modes.slice().sort().join() === 'delivery,pickup_slot', `the fulfilment page must offer delivery and pickup only (got ${modes})`);
    const fulfilText = await A.pageText(page);
    A.expect(!/Ship to address|In-store/.test(fulfilText), 'a grocery store must not offer Ship to address or In-store');
    await page.evaluate(() => document.querySelectorAll('input[name="modes[]"]').forEach((i) => { if (!i.checked) i.click(); }));
    await A.submitForm(page, 'form[action*="/fulfillment"]');
    await A.setStoreOptions(page, app, s.id, { allow_order_scheduling: '1', scheduling_lead_minutes: '30', scheduling_slot_interval: '15' });
    const stored = String(A.storeOption(s.id, 'fulfillment.enabled_modes') || '');
    A.expect(/delivery/.test(stored) && /pickup_slot/.test(stored), `both modes must be saved (${stored})`);
    return { ...s, milk, dates, cat };
  });
  R.ok(`grocery #${store.id} "${SLUG}" + category + 2 stock-tracked products created in the owner dashboard; Fulfilment offers Delivery and Pickup only, both on; scheduling on`);

  // ── customer helpers ────────────────────────────────────────────────────────────────────────────────────────────
  const addToCart = async (c, menuId, quantity = 1) => {
    const csrf = await A.storefrontCsrf(c);
    return c.evaluate(async (u, q, t) => {
      const fd = new FormData(); fd.append('quantity', String(q));
      const r = await fetch(u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': t } });
      return { status: r.status, json: await r.json().catch(() => null) };
    }, `${app}/${SLUG}/goods/${menuId}/cart`, quantity, csrf);
  };
  const fillCheckout = async (c, { mode, name, phone, address, slot }) => {
    await c.goto(`${app}/${SLUG}/checkout`, { waitUntil: 'domcontentloaded' });
    await c.evaluate((m) => { const r = document.querySelector(`input[name=type][value="${m}"]`); r.click(); }, mode);
    await c.$eval('#customer_name', (e) => { e.value = ''; });
    await c.type('#customer_name', name);
    await c.type('#phone_number', phone);
    if (address) await c.type('#address', address);
    if (slot) await c.select('#scheduled_at', slot);
  };
  const place = async (c) => {
    await Promise.all([
      c.evaluate(() => document.querySelector('form[action*="/checkout"] button[type=submit]').click()),
      c.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    ]);
    return { url: c.url(), text: await A.pageText(c) };
  };

  // ── customer: the storefront, a delivery order ──────────────────────────────────────────────────────────────────
  const orders = {};
  await A.withPage(browser, async (c) => {
    const resp = await c.goto(`${app}/${SLUG}`, { waitUntil: 'networkidle2' });
    A.expect(resp.status() === 200, 'the grocery storefront must open');
    A.expect((await A.pageText(c)).includes(MILK.name.replace(A.PREFIX, '')), 'the storefront must list the owner\'s products');

    const cart = await addToCart(c, store.milk.id, 2);
    A.expect(cart.status === 200, `adding milk to the cart must work (${JSON.stringify(cart)})`);
    // the checkout offers exactly the grocery modes
    await c.goto(`${app}/${SLUG}/checkout`, { waitUntil: 'domcontentloaded' });
    const modes = await c.evaluate(() => [...document.querySelectorAll('input[name=type]')].map((i) => i.value));
    A.expect(modes.slice().sort().join() === 'delivery,pickup_slot', `the checkout must offer delivery and pickup only (got ${modes})`);
    A.expect(/14\.50/.test(await A.pageText(c)), '2 x 7.25 must read 14.50 at checkout');

    // delivery without an address is refused on the page, with an address it is placed
    await fillCheckout(c, { mode: 'delivery', name: `${A.PREFIX}Shopper ${stamp}`, phone: '+966500000551' });
    const noAddr = await place(c);
    A.expect(/address is required/i.test(noAddr.text), `delivery without an address must show the address error (${noAddr.text.slice(0, 200)})`);
    await fillCheckout(c, { mode: 'delivery', name: `${A.PREFIX}Shopper ${stamp}`, phone: '+966500000551', address: '4 Olaya Road, Riyadh' });
    const done = await place(c);
    A.expect(/\/order\/track\//.test(done.url), `the delivery order must land on the tracking page (${done.url} ${done.text.slice(0, 200)})`);
    const token = done.url.split('/order/track/')[1].split(/[?#]/)[0];
    const o = A.sql('SELECT id, type, address, grand_total_minor, status FROM orders WHERE track_token = ?', [token])[0];
    A.expect(o && o[1] === 'delivery' && /4 Olaya Road/.test(o[2] || '') && Number(o[3]) === 1450 && o[4] === 'new', `delivery order row wrong: ${o}`);
    orders.delivery = { id: Number(o[0]), token };
    A.expect(Number(A.sqlValue('SELECT stock_qty FROM items WHERE id = ?', [store.milk.id])) === MILK.stock - 2, 'placing the order must take 2 off the milk stock');
    R.ok(`customer: delivery order #${orders.delivery.id} — address required then kept, total 14.50, milk stock ${MILK.stock} → ${MILK.stock - 2}`);

    // the stock limit: only 3 dates exist
    const tooMany = await addToCart(c, store.dates.id, 5);
    A.expect(tooMany.status >= 400, `5 dates must be refused when 3 are in stock (${JSON.stringify(tooMany)})`);
    A.expect((await addToCart(c, store.dates.id, 3)).status === 200, '3 dates must be accepted');
    R.ok('stock limit: 5 of 3 refused, 3 accepted');

    // pickup: a time slot is required; ship is not offered
    await fillCheckout(c, { mode: 'pickup_slot', name: `${A.PREFIX}Shopper ${stamp}`, phone: '+966500000551' });
    const noSlot = await place(c);
    A.expect(/pick a time slot/i.test(noSlot.text), `pickup without a slot must show the slot error (${noSlot.text.slice(0, 200)})`);
    await c.goto(`${app}/${SLUG}/checkout`, { waitUntil: 'domcontentloaded' });
    const slots = await c.evaluate(() => [...document.querySelectorAll('#scheduled_at option')].filter((o) => o.value && !o.disabled).map((o) => o.value));
    A.expect(slots.length > 2, `the pickup picker must offer free slots (${slots.length})`);
    await fillCheckout(c, { mode: 'pickup_slot', name: `${A.PREFIX}Shopper ${stamp}`, phone: '+966500000551', slot: slots[1] });
    const pick = await place(c);
    A.expect(/\/order\/track\//.test(pick.url), `the pickup order must land on the tracking page (${pick.url} ${pick.text.slice(0, 200)})`);
    const ptoken = pick.url.split('/order/track/')[1].split(/[?#]/)[0];
    const p = A.sql('SELECT id, type, scheduled_at, grand_total_minor FROM orders WHERE track_token = ?', [ptoken])[0];
    A.expect(p[1] === 'pickup_slot' && String(p[2]).slice(0, 16).replace('T', ' ') === slots[1] && Number(p[3]) === 5400, `pickup order row wrong: ${p}`);
    orders.pickup = { id: Number(p[0]), token: ptoken };
    R.ok(`customer: pickup order #${orders.pickup.id} needs a slot (${slots[1]} held), 3 x 18.00 = 54.00`);

    // a hand-made Ship request is refused
    await addToCart(c, store.milk.id, 1);
    const csrf = await A.storefrontCsrf(c);
    const ship = await c.evaluate(async (u, t) => {
      const fd = new FormData(); fd.append('type', 'ship'); fd.append('customer_name', 'x'); fd.append('phone_number', '1'); fd.append('address', '1 St');
      const r = await fetch(u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': t } });
      return r.status;
    }, `${app}/${SLUG}/checkout`, csrf);
    A.expect(ship === 422, `a grocery store must refuse the ship mode (${ship})`);
    R.ok('a hand-made Ship checkout is refused (422)');
  }, { width: 430, height: 1200 });

  // ── owner: the goods board and the status flow; customer: tracking ──────────────────────────────────────────────
  await A.withPage(browser, async (ob) => {
    await A.login(ob, app, { email: owner.email, pass: owner.pass });
    const boardUrl = `${app}/orders/board?store_id=${store.id}`;
    const card = (id) => ob.evaluate((i) => { const a = document.querySelector(`article[data-order-id="${i}"]`); return a ? { state: a.closest('[data-state]').dataset.state, text: a.innerText.replace(/\s+/g, ' ') } : null; }, id);
    await ob.goto(boardUrl, { waitUntil: 'domcontentloaded' });
    const d = await card(orders.delivery.id);
    const k = await card(orders.pickup.id);
    A.expect(d && d.state === 'new' && /Delivery/.test(d.text) && d.text.includes(`${A.PREFIX}Shopper ${stamp}`), `the delivery order must sit in New with its type (${JSON.stringify(d)})`);
    A.expect(k && k.state === 'new' && /Pickup/.test(k.text), `the pickup order must sit in New with its type (${JSON.stringify(k)})`);
    R.ok('owner: both orders on the goods board under New, tagged Delivery / Pickup');

    await A.withPage(browser, async (cust) => {
      const track = async () => (await cust.evaluate(async (t) => (await fetch(`/order/track/${t}`, { headers: { Accept: 'application/json' } })).json(), orders.delivery.token)).status;
      await cust.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' });
      A.expect(await track() === 'new', 'tracking starts at new');
      const seen = ['new'];
      for (const next of ['packing', 'ready', 'collected', 'done']) {
        await ob.goto(boardUrl, { waitUntil: 'domcontentloaded' });
        await A.submitForm(ob, `form[action*="/orders/board/${orders.delivery.id}/advance"]`);
        const db = A.sqlValue('SELECT status FROM orders WHERE id = ?', [orders.delivery.id]);
        A.expect(db === next, `Advance must move the order to ${next} (got ${db})`);
        A.expect(await track() === next, `the customer's tracking must follow to ${next}`);
        seen.push(next);
      }
      R.ok(`status flow ${seen.join(' → ')}: every step on the board shows on the customer's tracking page`);
    }, { width: 430, height: 900 });
  });

  // ── cross-store IDOR ────────────────────────────────────────────────────────────────────────────────────────────
  const foreign = A.sql("SELECT i.id FROM items i JOIN stores s ON s.id = i.store_id WHERE s.store_type IN ('retail','cosmetic','clothing','carparts','pharmacy','grocery') AND s.id <> ? AND s.user_id <> ? AND i.deleted_at IS NULL ORDER BY i.id LIMIT 1", [store.id, owner.id])[0];
  await A.withPage(browser, async (other) => {
    await A.login(other, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    A.expect(await A.status(other, `${app}/stores/${store.id}/products`) === 404, "another owner must get a 404 on this store's products");
    A.expect(await A.status(other, `${app}/stores/${store.id}/products/${store.milk.id}/edit`) === 404, "another owner must get a 404 on this store's product form");
    const board = await A.req(other, `${app}/orders/board?store_id=${store.id}`, { redirect: 'follow' });
    A.expect(!board.text.includes(`${A.PREFIX}Shopper ${stamp}`), "another owner's board must not list this store's orders");
    const before = A.sqlValue('SELECT status FROM orders WHERE id = ?', [orders.pickup.id]);
    const adv = await A.req(other, `${app}/orders/board/${orders.pickup.id}/advance?store_id=${store.id}`, { method: 'POST', form: {} });
    A.expect([403, 404].includes(adv.status) || adv.type === 'opaqueredirect', `another owner must not advance this store's order (${adv.status})`);
    A.expect(A.sqlValue('SELECT status FROM orders WHERE id = ?', [orders.pickup.id]) === before, 'the order must be untouched by the outsider');
    R.ok("IDOR: another owner gets 404 on this store's products and product form, sees none of its orders on the board and cannot advance one");
  });
  if (foreign) {
    await A.withPage(browser, async (c) => {
      await c.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' });
      const r = await addToCart(c, Number(foreign[0]), 1);
      A.expect(r.status === 404, `another store's product must not go into this store's cart (${r.status})`);
      R.ok("IDOR: a product of another store cannot be added to this store's cart (404)");
    }, { width: 430, height: 900 });
  } else {
    R.skip('foreign product leg', 'no goods product of another owner in the database');
  }

  R.summary();
});
