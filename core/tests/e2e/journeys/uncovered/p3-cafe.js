// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p3-cafe — Phase 3 (P3-00): the new "cafe" store type, owner → customer → owner, plus cross-store IDOR.
 *
 *  owner      creates the store through the dashboard wizard (store type "Café", theme set food), a menu category and
 *             two drinks, switches takeaway and delivery on
 *  customer   on the storefront (inherited theme `matcha` until the kit theme `brew` lands, P3-07): a takeaway order
 *             and a delivery order (address required); the fulfilment modes of other store types (ship, at_home,
 *             appointment) are refused
 *  owner      sees both orders on the orders page with their types
 *  IDOR       another owner gets 404 on this store's menu and orders; a customer cannot order another store's dish here
 *
 * The cafe journey runs the food path (send-order), so it is the regression guard that a "food" store type that is not
 * `restaurant` still orders end to end. Owner and customer steps run on E2E_ARMED_URL when set, else on E2E_BASE_URL.
 *
 * Run: npm run test:e2e -- p3-cafe
 */

const crypto = require('crypto');
const A = require('../../lib/lane-a');
const K = require('../../lib/order-kit-v2');

const SLUG = 'e2ea-cafe';
const TITLE = 'e2eA Cafe';
const CATEGORY = `${A.PREFIX}Coffee`;
const DRINKS = [{ name: `${A.PREFIX}Flat white`, price: 16 }, { name: `${A.PREFIX}Cold brew`, price: 19 }];

module.exports = A.journey(async function p3Cafe({ browser, log }) {
  const R = A.recorder('p3-cafe', log);
  const app = A.cfg.armed || A.cfg.baseUrl;
  const stamp = Date.now();

  // ── owner: store, menu, ordering modes ──────────────────────────────────────────────────────────────────────────
  const owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical', 'scheduled_orders'] });
  const store = await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const s = await A.ensureStore(page, app, { type: 'cafe', title: TITLE, slug: SLUG, template: 'matcha' });
    A.expect(A.sqlValue('SELECT store_type FROM stores WHERE id = ?', [s.id]) === 'cafe', 'the store was not created as a cafe');
    const cat = await A.ensureMenuCategory(page, app, s.id, CATEGORY);
    const items = [];
    for (const d of DRINKS) items.push(await A.ensureMenuItem(page, app, s.id, { ...d, categoryId: cat }));
    await A.setStoreOptions(page, app, s.id, { restaurant_takeaway_order: '1', restaurant_delivery_order: '1' });
    await page.goto(`${app}/stores/${s.id}/menu`, { waitUntil: 'domcontentloaded' });
    const menu = await A.pageText(page);
    A.expect(DRINKS.every((d) => menu.includes(d.name)), 'the owner menu page must list both drinks (a cafe uses the food menu editor)');
    await page.goto(`${app}/stores`, { waitUntil: 'domcontentloaded' });
    A.expect((await A.pageText(page)).includes(TITLE), 'the owner store list must show the cafe');
    // a food store type has no goods / service catalogue: those pages are not its own
    A.expect(await A.status(page, `${app}/stores/${s.id}/products`) === 404, 'a cafe must not open the goods catalogue');
    A.expect(await A.status(page, `${app}/stores/${s.id}/services`) === 404, 'a cafe must not open the services catalogue');
    return { ...s, items };
  });
  R.ok(`cafe #${store.id} "${SLUG}" + category + 2 drinks created in the owner dashboard (food menu editor; no goods / service catalogue)`);

  // ── customer: takeaway + delivery, wrong-vertical modes refused ─────────────────────────────────────────────────
  const orders = {};
  await A.withPage(browser, async (c) => {
    const rt = await K.loadRuntime(c, `${app}/${SLUG}`, { slug: SLUG });
    A.expect(rt.items.some((i) => i.name === DRINKS[0].name), 'the storefront must list the owner\'s drinks');
    const place = (mode, extra = {}) => K.submitOrder(c, {
      rt, items: [{ id: store.items[0].id, quantity: 2 }],
      fields: { name: `${A.PREFIX}Guest ${stamp}`, 'ordering-type': mode, 'phone-number': '+966512345678', pay_via: 'pay_on_counter', client_uuid: crypto.randomUUID(), ...extra },
    });

    const takeaway = await place('takeaway');
    A.expect(takeaway.success, `takeaway order must be accepted (${JSON.stringify(takeaway)})`);
    orders.takeaway = K.trackToken(takeaway.track_url);
    const t = A.sql('SELECT id, type, status FROM orders WHERE track_token = ?', [orders.takeaway])[0];
    A.expect(t && t[1] === 'takeaway', `takeaway order row wrong: ${t}`);
    orders.takeawayId = Number(t[0]);

    const noAddress = await place('delivery', { address: '' });
    A.expect(!noAddress.success, 'a delivery order without an address must be refused');
    const delivery = await place('delivery', { address: '8 Coffee Lane, Riyadh' });
    A.expect(delivery.success, `delivery order must be accepted (${JSON.stringify(delivery)})`);
    orders.delivery = K.trackToken(delivery.track_url);
    const d = A.sql('SELECT id, type, address FROM orders WHERE track_token = ?', [orders.delivery])[0];
    A.expect(d && d[1] === 'delivery' && /8 Coffee Lane/.test(d[2] || ''), `delivery order must keep its address: ${d}`);
    orders.deliveryId = Number(d[0]);
    const lines = A.sql('SELECT item_id, quantity FROM order_items WHERE order_id = ?', [orders.takeawayId]);
    A.expect(lines.length === 1 && Number(lines[0][0]) === store.items[0].id && Number(lines[0][1]) === 2, `order lines wrong: ${JSON.stringify(lines)}`);

    for (const wrong of ['ship', 'at_home', 'appointment', 'pickup_slot']) {
      const r = await place(wrong, { address: '1 St' });
      A.expect(!r.success, `a cafe must refuse the ${wrong} mode (${JSON.stringify(r)})`);
    }
    const count = Number(A.sqlValue('SELECT COUNT(*) FROM orders WHERE store_id = ? AND customer_name = ?', [store.id, `${A.PREFIX}Guest ${stamp}`]));
    A.expect(count === 2, `only the takeaway and the delivery order may exist (got ${count})`);
    R.ok(`customer: takeaway (#${orders.takeawayId}) and delivery (#${orders.deliveryId}, address kept) accepted; delivery without address, ship, at_home, appointment and pickup_slot refused`);
  }, { width: 430, height: 900 });

  // ── owner: sees both orders ─────────────────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const list = await A.req(page, `${app}/orders/${store.id}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'follow' });
    A.expect(list.status === 200, `the owner orders poll must open (${list.status})`);
    const txt = JSON.stringify(list.json || list.text);
    A.expect(txt.includes(`#${orders.takeawayId}`) || txt.includes(String(orders.takeawayId)), 'the owner orders page must list the takeaway order');
    A.expect(txt.includes(String(orders.deliveryId)), 'the owner orders page must list the delivery order');
    A.expect(txt.includes(`${A.PREFIX}Guest ${stamp}`), 'the orders page must show the customer name');
    R.ok('owner sees both orders on the orders page');
  });

  // ── cross-store IDOR ────────────────────────────────────────────────────────────────────────────────────────────
  const foreignItem = A.sql('SELECT i.id FROM items i JOIN stores s ON s.id = i.store_id WHERE s.id <> ? AND s.user_id <> ? AND i.deleted_at IS NULL AND i.active = ? ORDER BY i.id LIMIT 1', [store.id, owner.id, '1'])[0];
  await A.withPage(browser, async (other) => {
    await A.login(other, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    A.expect(await A.status(other, `${app}/stores/${store.id}/menu`) === 404, "another owner must get a 404 on this store's menu");
    const poll = await A.req(other, `${app}/orders/${store.id}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual' });
    A.expect([403, 404].includes(poll.status) || poll.type === 'opaqueredirect', `another owner must not read this store's orders (${poll.status})`);
    A.expect([403, 404].includes(await A.status(other, `${app}/order/${orders.takeawayId}`)), "another owner must be refused (403) on this store's order page");
    const before = A.sqlValue('SELECT status FROM orders WHERE id = ?', [orders.takeawayId]);
    await A.req(other, `${app}/order/${orders.takeawayId}/status`, { method: 'POST', form: { status: 'completed' } });
    A.expect(A.sqlValue('SELECT status FROM orders WHERE id = ?', [orders.takeawayId]) === before, "another owner must not change this store's order");
    R.ok("IDOR: another owner gets 404 on this store's menu, is refused on its order page, cannot read its orders or change one");
  });
  if (foreignItem) {
    await A.withPage(browser, async (c) => {
      const rt = await K.loadRuntime(c, `${app}/${SLUG}`, { slug: SLUG });
      const res = await K.submitOrder(c, { rt, items: [{ id: Number(foreignItem[0]), quantity: 1 }], fields: { name: `${A.PREFIX}Sneaky`, 'ordering-type': 'takeaway', 'phone-number': '+966512345678', pay_via: 'pay_on_counter', client_uuid: crypto.randomUUID() } });
      A.expect(!res.success, `a dish of another store must not be orderable here (${JSON.stringify(res)})`);
      A.expect(Number(A.sqlValue('SELECT COUNT(*) FROM orders WHERE store_id = ? AND customer_name = ?', [store.id, `${A.PREFIX}Sneaky`])) === 0, 'no order may exist for the foreign dish');
      R.ok("IDOR: another store's dish cannot be ordered through this store");
    }, { width: 430, height: 900 });
  } else {
    R.skip('foreign dish leg', 'no dish of another owner in the database');
  }

  R.summary();
});
