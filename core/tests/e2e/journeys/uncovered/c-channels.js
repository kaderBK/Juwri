// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * c-channels — P1-12 item 9 (c): delivery channels (Zomato), owner → partner → owner, plus cross-store IDOR.
 *
 *  owner      creates a food store through the dashboard wizard with two dishes; on Setup > Channels > Zomato connects
 *             the outlet (outlet id, webhook secret and partner key — test values), runs the connection test and pushes
 *             the menu; the page never shows a stored secret back; the Swiggy and HungerStation configuration screens load
 *  partner    (Zomato's server, simulated) posts an order.placed webhook: unsigned and wrongly signed calls are refused
 *             (401), a signature for an unknown outlet is refused (404), the signed one creates the order (201), a replay
 *             returns the same order and creates no second one, a paused channel acknowledges without ordering (202)
 *  owner      sees the order on the orders board with its channel, the dishes mapped by the pushed item codes, the
 *             commission, and on the kitchen display (KDS); a partner cancellation cancels it
 *  IDOR       another owner cannot open this store's channel page, connect, push or disconnect it (404); a webhook that is
 *             validly signed for store A but names another store's outlet creates nothing in that store
 *
 * The Zomato and Swiggy clients are the built-in Fake clients (no partner credentials exist: the real clients are
 * partner-programme gated), so "menu sync" is the stub push recorded in the channel's sync log.
 *
 * Cloud-relay notice: the partner-credential connectors (HungerStation, Jahez, Keeta, Uber Eats, Deliverect, Foodics,
 * Marn) show a notice in Community that they run directly with the owner's own credentials and through the juwri
 * Cloud relay on Cloud (added on lane/a after coordinator review); asserted below.
 *
 * Run: npm run test:e2e -- c-channels
 */

const crypto = require('crypto');
const A = require('../../lib/lane-a');

const SLUG = 'e2ea-channels';
const SECRET = 'e2ea-zomato-test-secret';
const DISHES = [{ name: `${A.PREFIX}Paneer wrap`, price: 12.5 }, { name: `${A.PREFIX}Mango lassi`, price: 4 }];

module.exports = A.journey(async function cChannels({ browser, log }) {
  const R = A.recorder('c-channels', log);
  const app = A.cfg.armed || A.cfg.baseUrl;
  const owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical'] });
  const stamp = Date.now();

  // ── owner: store, menu, connect, test, push ─────────────────────────────────────────────────────────────────────
  A.seedLaneFixtures();
  const S = {};
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    S.store = await A.ensureStore(page, app, { type: 'restaurant', title: 'e2eA Channels', slug: SLUG });
    const cat = await A.ensureMenuCategory(page, app, S.store.id, `${A.PREFIX}Street food`);
    S.items = [];
    for (const d of DISHES) S.items.push(await A.ensureMenuItem(page, app, S.store.id, { ...d, categoryId: cat }));
    S.outlet = `e2ea-res-${S.store.id}`;

    const url = `${app}/stores/${S.store.id}/channels/zomato`;
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    const form = `form[action$="/stores/${S.store.id}/channels/zomato/connect"]`;
    await page.waitForSelector(`${form} input[name=external_store_id]`);
    await page.evaluate((sel, outlet, secret) => {
      const set = (n, v) => { const e = document.querySelector(`${sel} [name=${n}]`); if (e) { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); } };
      set('external_store_id', outlet); set('webhook_secret', secret); set('api_key', 'e2ea-zomato-test-key'); set('api_secret', 'e2ea-zomato-test-api-secret'); set('prep_minutes', '15');
      const a = document.querySelector(`${sel} [name=auto_accept]`); if (a && !a.checked) a.click();
    }, form, S.outlet, SECRET);
    await A.submitForm(page, form);
    const conn = A.sql("SELECT status, external_store_id, auto_accept FROM aggregator_connections WHERE store_id = ? AND provider = 'zomato'", [S.store.id])[0];
    A.expect(conn && conn[0] === 'active' && conn[1] === S.outlet, `the Zomato connection must be saved active with the outlet id (${conn})`);
    let text = await A.pageText(page);
    A.expect(!text.includes(SECRET) && !text.includes('e2ea-zomato-test-api-secret'), 'the channel page must never show a stored secret back');
    A.expect(await page.evaluate(() => document.querySelector('input[name=external_store_id]').value) === S.outlet, 'the channel form must show the connected outlet id');

    await A.submitForm(page, `form[action$="/stores/${S.store.id}/channels/zomato/test"]`);
    await A.submitForm(page, `form[action$="/stores/${S.store.id}/channels/zomato/menu-push"]`);
    const logs = A.sql("SELECT status, message FROM aggregator_sync_logs WHERE store_id = ? AND provider = 'zomato' ORDER BY id", [S.store.id]);
    A.expect(logs.some((l) => l[0] === 'ok' && l[1] === 'test connection'), `the connection test must be logged ok (${JSON.stringify(logs.slice(-3))})`);
    A.expect(logs.some((l) => l[0] === 'ok' && /^\d+ /.test(l[1]) && Number(l[1].split(' ')[0]) >= DISHES.length), `the menu push must be logged ok with the item count (${JSON.stringify(logs.slice(-3))})`);
    text = await A.pageText(page);

    // the other connector configuration screens load for the owner
    for (const p of [`/stores/${S.store.id}/channels/swiggy`, '/hungerstation']) {
      A.expect(await A.status(page, app + p) === 200, `the ${p} configuration screen must load for the owner`);
    }
  });
  R.ok(`store #${S.store.id} with 2 dishes; Zomato connected (outlet ${S.outlet}), secrets never echoed, connection test + menu push (stub client) logged ok; Swiggy and HungerStation screens load`);

  // ── partner: signed webhooks ────────────────────────────────────────────────────────────────────────────────────
  const post = async (payload, { secret = SECRET, sign = true, signature } = {}) => {
    const body = JSON.stringify(payload);
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    if (sign) headers['X-Zomato-Signature'] = signature || crypto.createHmac('sha256', secret).update(body).digest('hex');
    const r = await fetch(`${app}/webhook/zomato/orders`, { method: 'POST', headers, body });
    return { status: r.status, json: await r.json().catch(() => null) };
  };
  const extId = `E2EA-ZOM-${stamp}`;
  const placed = (extId, outlet) => ({
    event: 'order.placed', res_id: outlet,
    order: {
      order_id: extId, commission: 2.5, instructions: 'extra chutney',
      customer: { name: `${A.PREFIX}Zomato Diner`, phone: '+919800000001', address: { line1: '12 MG Road', city: 'Bengaluru' } },
      dishes: [{ dish_id: `zom_${S.items[0].id}`, quantity: 2, name: DISHES[0].name }, { dish_id: `zom_${S.items[1].id}`, quantity: 1, name: DISHES[1].name }],
    },
  });
  const order = {};
  {
    const payload = placed(extId, S.outlet);
    A.expect((await post(payload, { sign: false })).status === 401, 'an unsigned webhook must be refused (401)');
    A.expect((await post(payload, { secret: 'not-the-secret' })).status === 401, 'a wrongly signed webhook must be refused (401)');
    A.expect((await post(placed(extId, 'e2ea-unknown-outlet'))).status === 404, 'a webhook for an unknown outlet must be refused (404)');
    A.expect(Number(A.sqlValue("SELECT COUNT(*) FROM delivery_orders WHERE provider = 'zomato' AND external_id = ?", [extId])) === 0, 'refused webhooks must create nothing');

    const ok = await post(payload);
    A.expect(ok.status === 201 && ok.json && ok.json.order_id, `the signed webhook must create the order (201) (${ok.status} ${JSON.stringify(ok.json)})`);
    order.id = Number(ok.json.order_id); order.token = ok.json.token;
    const replay = await post(payload);
    A.expect(Number(A.sqlValue("SELECT COUNT(*) FROM delivery_orders WHERE provider = 'zomato' AND external_id = ?", [extId])) === 1 && Number(A.sqlValue("SELECT COUNT(DISTINCT order_id) FROM delivery_orders WHERE provider = 'zomato' AND external_id = ?", [extId])) === 1,
      `a replay must not create a second order (${replay.status} ${JSON.stringify(replay.json)})`);
    const row = A.sql('SELECT store_id, type, source, channel_fee_minor, grand_total_minor, status FROM orders WHERE id = ?', [order.id])[0];
    A.expect(Number(row[0]) === S.store.id, 'the order must belong to the connected store, not one named in the payload');
    A.expect(row[1] === 'delivery' && Number(row[3]) === 250, `delivery order with the 2.50 commission expected (${row})`);
    const lines = A.sql('SELECT item_id, quantity FROM order_items WHERE order_id = ? ORDER BY item_id', [order.id]).map((r) => `${r[0]}x${r[1]}`).join();
    A.expect(lines === `${S.items[0].id}x2,${S.items[1].id}x1`, `the dishes must be mapped through the pushed item codes (${lines})`);
    order.source = row[2]; order.status = row[5];
  }
  R.ok(`webhook: unsigned / wrong signature → 401, unknown outlet → 404, nothing created; signed → order #${order.id} (201) with the 2 dishes mapped and the 2.50 commission; replay creates no second order`);

  // ── owner: the order everywhere it should be ────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    await page.goto(`${app}/order/${order.id}`, { waitUntil: 'domcontentloaded' });
    const text = await A.pageText(page);
    A.expect(text.includes(`#${order.id}`) && /zomato/i.test(text), 'the order page must name the Zomato channel');
    A.expect(text.includes(DISHES[0].name) && text.includes(`${A.PREFIX}Zomato Diner`), 'the order page must show the dishes and the customer');
    await page.goto(`${app}/orders/${S.store.id}`, { waitUntil: 'domcontentloaded' });
    await A.sleep(700);
    const board = await page.evaluate(() => [...document.querySelectorAll('article.ui-order')].map((a) => a.innerText.replace(/\s+/g, ' ')));
    const mine = board.find((t) => t.includes(`#${order.id}`));
    A.expect(mine, `the orders board must list the order (${board.length} cards: ${board.slice(0, 2).join(' || ')})`);
    const kds = await A.req(page, `${app}/kds/poll?store_id=${S.store.id}`);
    A.expect(kds.status === 200 && kds.text.includes(DISHES[0].name), `the KDS poll must carry the ticket (${kds.status} status ${order.status}; ${kds.text.slice(0, 160)})`);
    await page.goto(`${app}/kds?store_id=${S.store.id}`, { waitUntil: 'domcontentloaded' });
    A.expect(await A.status(page, `${app}/kds?store_id=${S.store.id}`) === 200, 'the KDS screen must load');

    // pause: the channel acknowledges (202) but orders nothing
    await page.goto(`${app}/stores/${S.store.id}/channels/zomato`, { waitUntil: 'domcontentloaded' });
    await A.submitForm(page, `form[action$="/stores/${S.store.id}/channels/zomato/pause"]`);
    const second = await post(placed(`${extId}-P`, S.outlet));
    A.expect(second.status === 202 && Number(A.sqlValue("SELECT COUNT(*) FROM delivery_orders WHERE external_id = ?", [`${extId}-P`])) === 0, `a paused channel must acknowledge without ordering (${second.status})`);
    await page.goto(`${app}/stores/${S.store.id}/channels/zomato`, { waitUntil: 'domcontentloaded' });
    await A.submitForm(page, `form[action$="/stores/${S.store.id}/channels/zomato/pause"]`);
    A.expect(A.sqlValue("SELECT status FROM aggregator_connections WHERE store_id = ? AND provider = 'zomato'", [S.store.id]) === 'active', 'the channel must be active again after resuming');
    const cancel = await post({ event: 'order.cancelled', res_id: S.outlet, order: { order_id: extId } });
    A.expect(cancel.status === 200 && A.sqlValue('SELECT status FROM orders WHERE id = ?', [order.id]) === 'cancelled', `a partner cancellation must cancel the order (${cancel.status})`);
  });
  R.ok(`owner sees order #${order.id} (channel Zomato, dishes, customer) on the order page, the orders board and the KDS poll; pause → 202 and no order; partner cancel → cancelled`);

  // ── IDOR ────────────────────────────────────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (other) => {
    await A.login(other, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    const channel = `${app}/stores/${S.store.id}/channels/zomato`;
    A.expect(await A.status(other, channel) === 404, 'another owner must get a 404 on this store\'s channel page');
    for (const sub of ['connect', 'test', 'menu-push', 'availability', 'outlet', 'pause']) {
      const r = await A.req(other, `${channel}/${sub}`, { method: 'POST', form: { external_store_id: 'hijack-outlet', webhook_secret: 'x' } });
      A.expect(r.status === 404 || r.status === 403, `another owner must not ${sub} this store's channel (${r.status})`);
    }
    const del = await A.req(other, channel, { method: 'DELETE' });
    A.expect(del.status === 404 || del.status === 403, `another owner must not disconnect it (${del.status})`);
    A.expect(A.sqlValue("SELECT external_store_id FROM aggregator_connections WHERE store_id = ? AND provider = 'zomato'", [S.store.id]) === S.outlet, 'the connection must be unchanged after the cross-store attempts');
    const kds = await A.req(other, `${app}/kds/poll?store_id=${S.store.id}`);
    A.expect(kds.status === 403, `another owner must not poll this store's KDS (${kds.status})`);
  });
  const foreign = A.sql("SELECT external_store_id, store_id FROM aggregator_connections WHERE provider = 'zomato' AND store_id <> ? LIMIT 1", [S.store.id])[0];
  if (foreign) {
    const before = Number(A.sqlValue('SELECT COUNT(*) FROM orders WHERE store_id = ?', [foreign[1]]));
    const r = await post(placed(`${extId}-X`, foreign[0]));  // signed with OUR secret, naming ANOTHER store's outlet
    A.expect(r.status === 401 && Number(A.sqlValue('SELECT COUNT(*) FROM orders WHERE store_id = ?', [foreign[1]])) === before, `a webhook signed with this store's secret must not order in another store (${r.status})`);
  }
  // partner-credential connectors say how they run in Community: directly, with the owner's own credentials
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    for (const [name, path] of [['HungerStation', '/hungerstation'], ['delivery channels hub', '/aggregators']]) {
      await page.goto(`${app}${path}?store_id=${S.store.id}`, { waitUntil: 'domcontentloaded' });
      const note = await page.evaluate(() => document.querySelector('[data-testid=partner-relay-notice]')?.innerText.replace(/\s+/g, ' ') || null);
      A.expect(note && /Direct connection/.test(note) && /juwri Cloud relay/.test(note), `the ${name} screen must show the Cloud-relay notice in Community (${note})`);
    }
    // the India marketplaces use the owner's own keys and are not partner-credential connectors: no notice there
    await page.goto(`${app}/stores/${S.store.id}/channels/zomato`, { waitUntil: 'domcontentloaded' });
    A.expect(!(await page.$('[data-testid=partner-relay-notice]')), 'the Zomato screen (own-credential connector) must not carry the relay notice');
  });
  R.ok('Cloud-relay notice: the HungerStation and delivery-channels hub screens say the connector runs directly with the owner\'s own credentials and through the juwri Cloud relay on Cloud; Zomato (own keys) has none');
  R.ok('IDOR: another owner gets 404 on the channel page and every action, cannot disconnect it or poll its KDS; a signature for this store cannot order in another store');

  R.summary();
});
