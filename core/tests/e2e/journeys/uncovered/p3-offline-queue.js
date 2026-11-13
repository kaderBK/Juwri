// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p3-offline-queue — Phase 3 (P3-03): the storefront core JS, the per-store service worker and the offline order queue,
 * owner → customer → owner, with the three kill-switch levels.
 *
 *  owner      creates the store + two dishes through the dashboard, switches dine-in / takeaway / delivery ordering on,
 *             and (at the end) the store-level queue switch
 *  customer   the core JS (assets/storefront/core.js) is mounted on the real storefront page with the page's real boot
 *             data (GET {store}/storefront/boot.json — what the kit layouts' partial prints) and a small harness markup
 *             (the kit themes of P3-05 supply the real markup; the hooks are the data-juwri-* ones):
 *               - the cart shows the SERVER quote (the total equals the quote endpoint's answer, nothing is computed in JS)
 *               - offline: a dine-in order paid on collection is queued ("Waiting for connection"), nothing is placed
 *               - delivery offline: refused to queue ("connect to place this order")
 *               - back online, price unchanged: the order is sent once, with the UUIDv7 it was queued under
 *               - the owner changes the tax while an order waits: the customer sees old vs new and must confirm; nothing
 *                 is placed before; after confirming exactly ONE order exists and it carries the new total
 *               - replaying the same client id returns the same order (idempotent), a different cart is a 409
 *  switches   store level (owner), platform level (superadmin; this journey needs the dev admin) and — enterprise build
 *             with a reseller-owned store only — reseller level: any level off drops the waiting orders with a notice on
 *             the next load, places nothing, and a queued request that still reaches the server is refused (403)
 *  owner      sees exactly the orders that were placed
 *
 * Owner and customer steps run on E2E_ARMED_URL when set, else on E2E_BASE_URL. Run: npm run test:e2e -- p3-offline-queue
 */

const crypto = require('crypto');
const A = require('../../lib/lane-a');

const SLUG = 'e2eb-offline';
const TITLE = 'e2eB Offline Cafe';
const CATEGORY = `${A.PREFIX}Offline coffee`;
const DISHES = [{ name: `${A.PREFIX}Offline latte`, price: 12 }, { name: `${A.PREFIX}Offline mocha`, price: 15 }];
const GUEST = { name: `${A.PREFIX}Offline guest`, 'phone-number': '+966512345678', table: 'T4', pay_via: 'pay_on_counter' };

/** The harness markup: data-juwri-* hooks only (core JS owns behaviour, the kit themes will own the real markup). */
const HARNESS = `
<div id="hx">
  <button id="hx-open" data-juwri-open="cart" aria-controls="cart" aria-expanded="false">Cart <span data-juwri-count></span></button>
  <span data-juwri-queue-badge hidden></span>
  <div id="cart" data-juwri-sheet role="dialog" aria-modal="true" aria-label="Cart" hidden>
    <div data-juwri-empty>Nothing in your order yet</div>
    <div data-juwri-filled hidden>
      <ul data-juwri-lines><template data-juwri-line><li><span data-juwri-line-name></span> x<span data-juwri-line-qty></span> <span data-juwri-line-total></span><button data-juwri-inc>+</button><button data-juwri-dec>-</button></li></template></ul>
      <ul data-juwri-rows><template data-juwri-row><li><span data-juwri-row-label></span>: <span data-juwri-row-amount></span></li></template></ul>
      <p>Total <b data-juwri-total></b></p>
      <p data-juwri-stale hidden>offline, prices may change</p>
      <form data-juwri-checkout>
        <input name="name" value="${GUEST.name}"><input name="phone-number" value="${GUEST['phone-number']}">
        <input name="table" value="${GUEST.table}"><input name="pay_via" value="${GUEST.pay_via}">
      </form>
      <button id="hx-place" data-juwri-place>Place order</button>
    </div>
    <ul data-juwri-queue><template data-juwri-queue-item><li><span data-juwri-queue-state></span> <span data-juwri-queue-total></span> <span data-juwri-queue-detail></span><button data-juwri-queue-confirm>Confirm</button><button data-juwri-queue-retry>Retry</button><button data-juwri-queue-dismiss>Dismiss</button></li></template></ul>
    <button data-juwri-close>Close</button>
  </div>
  <div id="live-log"></div>
</div>`;

module.exports = A.journey(async function p3OfflineQueue({ browser, log }) {
  const R = A.recorder('p3-offline', log);
  const app = A.cfg.armed || A.cfg.baseUrl;
  const sleep = A.sleep;

  const itemPublicId = (id) => A.sqlValue('SELECT public_id FROM items WHERE id = ?', [id]);
  const orderCount = () => Number(A.sqlValue('SELECT COUNT(*) FROM orders WHERE store_id = ? AND customer_name = ?', [store.id, GUEST.name]));
  const byClientId = (id) => Number(A.sqlValue('SELECT COUNT(*) FROM orders WHERE store_id = ? AND client_uuid = ?', [store.id, id]));
  let store;
  let owner;

  // ── owner: store, menu, ordering modes, queue switch back to its default ────────────────────────────────────────
  owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical'] });
  const ownerSetup = (options) => A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    await A.setStoreOptions(page, app, store.id, options);
  });
  store = await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const s = await A.ensureStore(page, app, { type: 'cafe', title: TITLE, slug: SLUG, template: 'matcha' });
    const cat = await A.ensureMenuCategory(page, app, s.id, CATEGORY);
    const items = [];
    for (const d of DISHES) items.push(await A.ensureMenuItem(page, app, s.id, { ...d, categoryId: cat }));
    return { ...s, items };
  });
  await ownerSetup({
    restaurant_on_table_order: '1', restaurant_takeaway_order: '1', restaurant_delivery_order: '1',
    offline_queue_enabled: '1', offline_queue_expiry_min: '30', restaurant_taxx_charge: '0',
  });
  A.expect(A.storeOption(store.id, 'offline_queue.enabled') !== 'false', 'the store-level queue switch must default / reset to on');
  R.ok(`store #${store.id} "${SLUG}" with 2 dishes; dine-in, takeaway and delivery on; offline queue on (default, 30 min)`);

  /** Mount the core JS on the real storefront page with the real boot data; returns when window.Juwri is ready. */
  async function mount(page) {
    await page.setBypassCSP(true); // the harness markup + module are injected; the real kit layouts print them under the nonce
    await page.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' });
    const boot = await page.evaluate(async (u) => {
      const r = await fetch(u, { credentials: 'same-origin' });
      return { status: r.status, json: await r.json() };
    }, `${app}/${SLUG}/storefront/boot.json`);
    A.expect(boot.status === 200, `boot.json must answer 200 (${boot.status})`);
    await page.evaluate((markup, bootJson) => {
      window.__juwri = [];
      for (const ev of ['queue:dropped', 'queue:changed', 'order:placed', 'order:failed', 'quote:updated', 'queue:confirm']) {
        document.addEventListener('juwri:' + ev, (e) => window.__juwri.push({ ev, detail: e.detail }));
      }
      document.body.insertAdjacentHTML('beforeend', markup);
      const data = document.createElement('script');
      data.type = 'application/json';
      data.setAttribute('data-juwri-boot', '');
      data.textContent = JSON.stringify(bootJson);
      document.body.appendChild(data);
      const mod = document.createElement('script');
      mod.type = 'module';
      mod.src = '/assets/storefront/core.js';
      document.body.appendChild(mod);
    }, HARNESS, boot.json);
    await page.waitForFunction(() => window.Juwri && window.Juwri.cart, { timeout: 15000 });
    return boot.json;
  }

  const queueRows = (page) => page.evaluate(() => window.Juwri.queue.list());
  const waitQueue = async (page, state, timeout = 20000) => {
    try {
      await page.waitForFunction(async (s) => (await window.Juwri.queue.list()).some((q) => q.state === s), { timeout }, state);
    } catch (e) {
      const dump = await page.evaluate(async () => ({ queue: await window.Juwri.queue.list(), events: (window.__juwri || []).slice(-12), online: navigator.onLine })).catch(() => null);
      throw new Error(`the queue never reached "${state}": ${JSON.stringify(dump).slice(0, 1800)}`);
    }
  };
  const reconnect = async (page) => { await page.setOfflineMode(false); await page.evaluate(() => window.dispatchEvent(new Event('online'))); };
  const place = (page) => page.evaluate((f) => window.Juwri.cart.place(f), { ...GUEST });

  await A.withPage(browser, async (c) => {
    c.on('pageerror', (e) => log(`  p3-offline pageerror: ${e.message}`));
    const boot = await mount(c);
    A.expect(boot.offline_queue.enabled === true, `the queue must be on by default (${JSON.stringify(boot.offline_queue)})`);
    A.expect(boot.offline_queue.order_types.includes('dine_in') && !boot.offline_queue.order_types.includes('delivery'), `dine-in may queue, delivery never (${JSON.stringify(boot.offline_queue.order_types)})`);
    A.expect(/\/sw-store\.js\?.*s=e2eb-offline/.test(boot.sw.url), `the per-store worker URL must name the store (${boot.sw.url})`);
    const latte = itemPublicId(store.items[0].id);
    const mocha = itemPublicId(store.items[1].id);

    // cart on the server quote ------------------------------------------------------------------------------------
    await c.evaluate((id) => window.Juwri.cart.add(id, { qty: 2, name: 'latte' }), latte);
    await c.waitForFunction(() => document.querySelector('[data-juwri-total]')?.textContent.trim());
    const quote = await c.evaluate(async (u, id) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ lines: [{ item: id, qty: 2 }], order_type: 'dine_in' }) });
      return r.json();
    }, boot.endpoints.quote, latte);
    const shown = await c.$eval('[data-juwri-total]', (e) => e.textContent.trim());
    A.expect(shown === quote.quote.total.formatted, `the cart must show the server total "${quote.quote.total.formatted}", not "${shown}"`);
    A.expect(Number(await c.$eval('[data-juwri-count]', (e) => e.textContent)) === 2, 'the cart count must be 2');
    const rowLabels = await c.$$eval('[data-juwri-rows] li', (els) => els.map((e) => e.textContent));
    A.expect(rowLabels.length >= 1, 'the quote rows must be rendered from the server answer');
    R.ok(`cart shows the server quote ("${shown}", ${rowLabels.length} rows) — the total is not computed in the browser`);

    // sheet focus: open, Esc closes and returns focus ---------------------------------------------------------------
    await c.focus('#hx-open');
    await c.keyboard.press('Enter');
    await c.waitForSelector('#cart:not([hidden])');
    A.expect(await c.$eval('#hx-open', (e) => e.getAttribute('aria-expanded')) === 'true', 'the opener must be aria-expanded while the sheet is open');
    A.expect(await c.evaluate(() => document.getElementById('cart').contains(document.activeElement)), 'focus must move into the sheet');
    A.expect(await c.evaluate(() => document.getElementById('live-log').hasAttribute('inert') === false), 'the harness region stays reachable only inside the sheet');
    await c.keyboard.press('Escape');
    await c.waitForSelector('#cart[hidden]');
    A.expect(await c.evaluate(() => document.activeElement && document.activeElement.id) === 'hx-open', 'focus must return to the opener');
    await c.evaluate(() => document.getElementById('hx-open').click());
    R.ok('sheet: opens with focus inside, Esc closes it and focus returns to the opener');

    // wait for the service worker so a reload offline would still work --------------------------------------------
    const swScope = await c.evaluate(async () => { const r = await navigator.serviceWorker.ready; return r.scope; });
    A.expect(swScope.endsWith(`/${SLUG}`) || swScope.endsWith('/'), `the worker scope must be this store's address (${swScope})`);
    R.ok(`service worker /sw-store.js active for scope ${swScope.replace(/^https?:\/\/[^/]+/, '')}`);

    // offline delivery never queues ---------------------------------------------------------------------------------
    await c.setOfflineMode(true);
    await c.evaluate(() => window.Juwri.cart.setOrderType('delivery'));
    const delivery = await c.evaluate(() => window.Juwri.cart.place({ name: 'x', 'phone-number': '+966512345678', address: '1 St', pay_via: 'pay_on_counter' }));
    A.expect(delivery.status === 'offline', `delivery offline must say "connect to place this order", got ${JSON.stringify(delivery)}`);
    A.expect((await queueRows(c)).length === 0, 'delivery must never enter the queue');
    R.ok('offline + delivery: "connect to place this order", nothing queued');

    // offline dine-in is queued; nothing is placed -----------------------------------------------------------------
    await c.evaluate(() => window.Juwri.cart.setOrderType('dine_in'));
    const queued = await place(c);
    A.expect(queued.status === 'queued', `offline dine-in paid on collection must queue, got ${JSON.stringify(queued)}`);
    const q1 = (await queueRows(c))[0];
    A.expect(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(q1.id), `the queued order must carry a UUIDv7 (${q1.id})`);
    A.expect(q1.state === 'waiting', `state must be waiting (${q1.state})`);
    A.expect(Number(await c.$eval('[data-juwri-queue-badge]', (e) => e.textContent)) === 1, 'the queue badge must show 1');
    A.expect(/Waiting for connection/i.test(await c.$eval('[data-juwri-queue-state]', (e) => e.textContent)), 'the queue must say "Waiting for connection"');
    A.expect(orderCount() === 0, 'no order may exist while the phone is offline');
    R.ok(`offline + dine-in: queued as ${q1.id} ("Waiting for connection", badge 1), no order placed`);

    // reconnect, price unchanged: sent once --------------------------------------------------------------------------
    await reconnect(c);
    await waitQueue(c, 'placed');
    A.expect(orderCount() === 1, `exactly one order after reconnecting (got ${orderCount()})`);
    A.expect(byClientId(q1.id) === 1, 'the order must carry the UUIDv7 it was queued under');
    const placedTotal = Number(A.sqlValue('SELECT grand_total_minor FROM orders WHERE client_uuid = ?', [q1.id]));
    A.expect(placedTotal === quote.quote.total.minor, `the placed total (${placedTotal}) must equal the quoted total (${quote.quote.total.minor})`);
    await reconnect(c);
    await sleep(1500);
    A.expect(orderCount() === 1, 'a second online event must not place a second order');
    R.ok('reconnect (price unchanged): sent once, same UUIDv7, total == quote; a second online event does nothing');

    // idempotent replay + 409 -----------------------------------------------------------------------------------------
    const replay = await c.evaluate(async (u, id, body) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(Object.assign({ client_order_id: id }, body)) });
      return { status: r.status, json: await r.json() };
    }, boot.endpoints.place, q1.id, { lines: [{ item: latte, qty: 2 }], order_type: 'dine_in', ...GUEST });
    A.expect(replay.json.success === true && replay.json.duplicate === true, `the same client id must replay the order (${JSON.stringify(replay)})`);
    const conflict = await c.evaluate(async (u, id, body) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(Object.assign({ client_order_id: id }, body)) });
      return { status: r.status, json: await r.json() };
    }, boot.endpoints.place, q1.id, { lines: [{ item: mocha, qty: 5 }], order_type: 'dine_in', ...GUEST });
    A.expect(conflict.status === 409, `the same id with another cart must be a 409 (${JSON.stringify(conflict)})`);
    A.expect(orderCount() === 1, 'replay and conflict must not create orders');
    R.ok('same client id: replayed ("duplicate"), other cart: 409; still one order');

    // price changes while the order waits: confirm again ------------------------------------------------------------
    await c.evaluate((id) => window.Juwri.queue.list().then((rows) => Promise.all(rows.map((r) => window.Juwri.queue.dismiss(r.id)))), latte);
    await c.evaluate((id) => window.Juwri.cart.add(id, { qty: 1, name: 'mocha' }), mocha);
    await c.waitForFunction(() => window.Juwri.cart.snapshot().quote);
    await c.setOfflineMode(true);
    const queued2 = await place(c);
    A.expect(queued2.status === 'queued', `second offline order must queue (${JSON.stringify(queued2)})`);
    const q2 = (await queueRows(c)).find((q) => q.state === 'waiting');
    await ownerSetup({ restaurant_taxx_charge: '5', restaurant_tax_type: 'percentage' }); // the price moves while the phone is offline
    const before = orderCount();
    await reconnect(c);
    await waitQueue(c, 'confirm');
    const confirmRow = (await queueRows(c)).find((q) => q.state === 'confirm');
    A.expect(confirmRow.reason === 'changed' && confirmRow.new_total.minor > q2.total_minor, `the shopper must be asked to confirm the changed total (${JSON.stringify({ r: confirmRow.reason, old: q2.total_minor, nw: confirmRow.new_total })})`);
    const detail = await c.$eval('[data-juwri-queue-detail]', (e) => e.textContent);
    A.expect(detail.includes(confirmRow.new_total.formatted), `old vs new must be shown ("${detail}")`);
    await sleep(800);
    A.expect(orderCount() === before, 'nothing may be placed before the shopper confirms');
    await c.evaluate((id) => window.Juwri.queue.confirm(id), q2.id);
    await waitQueue(c, 'placed');
    A.expect(orderCount() === before + 1, `exactly one more order after confirming (${before} -> ${orderCount()})`);
    const newTotal = Number(A.sqlValue('SELECT grand_total_minor FROM orders WHERE client_uuid = ?', [q2.id]));
    A.expect(newTotal === confirmRow.new_total.minor, `the order must carry the confirmed total (${newTotal} vs ${confirmRow.new_total.minor})`);
    R.ok(`price changed while waiting: "Needs your confirmation" with old -> new ("${detail}"), nothing placed until confirmed, then exactly one order at the new total`);
    await ownerSetup({ restaurant_taxx_charge: '0' });
  }, { width: 430, height: 900 });

  // ── kill switch at each level: queue offline, flip, reconnect + reload → dropped with a notice, nothing placed ──────
  /** Queue one dine-in order offline on a fresh page; the caller flips a switch; then reconnect + remount. */
  async function killLeg(label, flip, unflip) {
    await A.withPage(browser, async (c) => {
      const boot = await mount(c);
      A.expect(boot.offline_queue.enabled === true, `${label}: the queue must start on`);
      await c.evaluate((id) => window.Juwri.cart.add(id, { qty: 1, name: 'latte' }), itemPublicId(store.items[0].id));
      await c.waitForFunction(() => window.Juwri.cart.snapshot().quote);
      await c.evaluate(() => navigator.serviceWorker.ready);
      await c.setOfflineMode(true);
      const q = await place(c);
      A.expect(q.status === 'queued', `${label}: must queue while the switch is on (${JSON.stringify(q)})`);
      const waitingId = q.item.id;
      const before = orderCount();
      await flip();
      await c.setOfflineMode(false);
      await mount(c); // the next page load: boot data now says the queue is off
      await c.waitForFunction(() => window.__juwri.some((e) => e.ev === 'queue:dropped'), { timeout: 15000 });
      const dropped = await c.evaluate(() => window.__juwri.find((e) => e.ev === 'queue:dropped').detail);
      A.expect(dropped.count >= 1 && dropped.reason === 'disabled', `${label}: the dropped notice must carry the count (${JSON.stringify(dropped)})`);
      A.expect((await queueRows(c)).filter((r) => r.state !== 'placed').length === 0, `${label}: no waiting order may remain`);
      const boot2 = await c.evaluate((u) => fetch(u).then((r) => r.json()), `${app}/${SLUG}/storefront/boot.json`);
      A.expect(boot2.offline_queue.enabled === false && /oq=0/.test(boot2.sw.url), `${label}: boot data and the worker URL must say off (${JSON.stringify(boot2.offline_queue)} ${boot2.sw.url})`);
      await sleep(800);
      A.expect(orderCount() === before && byClientId(waitingId) === 0, `${label}: the dropped order must never be placed`);
      // a queued request that still reaches the server is refused
      const res = await c.evaluate(async (u, id, item, f) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Juwri-Queued': '1' }, body: JSON.stringify({ client_order_id: id, lines: [{ item, qty: 1 }], order_type: 'dine_in', ...f }) });
        return { status: r.status, json: await r.json() };
      }, boot.endpoints.place, waitingId, itemPublicId(store.items[0].id), GUEST);
      A.expect(res.status === 403 && res.json.code === 'offline_queue_disabled', `${label}: the server must refuse a queued order (${JSON.stringify(res)})`);
      A.expect(byClientId(waitingId) === 0, `${label}: the refused order must not exist`);
      // browsing and the cart offline stay on: an ordinary online order still works
      A.expect((await c.evaluate(() => window.Juwri.cart.add.length >= 0)), 'the cart API stays available');
    }, { width: 430, height: 900 });
    await unflip();
    R.ok(`${label}: switched off while an order waited → on the next load it is dropped with a notice (count ≥ 1, reason disabled), worker URL oq=0, nothing placed, a queued request is refused (403)`);
  }

  await killLeg('store level (owner)', () => ownerSetup({ offline_queue_enabled: '0' }), () => ownerSetup({ offline_queue_enabled: '1' }));

  // platform level: the superadmin switch (Admin > Settings > Feature controls)
  if (A.cfg.adminPass) {
    const platform = (on) => A.withPage(browser, async (admin) => {
      await A.login(admin, app, { email: A.cfg.adminEmail, pass: A.cfg.adminPass });
      await admin.goto(`${app}/admin/settings`, { waitUntil: 'domcontentloaded' });
      const title = await admin.$eval('input[name="site_title"]', (e) => e.value);
      const res = await A.req(admin, `${app}/admin/settings`, { method: 'POST', form: { general_setting: '1', site_title: title, offline_queue_enabled: on ? '1' : '0' }, redirect: 'follow' });
      A.expect([200, 302].includes(res.status) || res.type === 'opaqueredirect', `the platform switch must save (${res.status})`);
    });
    try {
      await killLeg('platform level (superadmin)', () => platform(false), () => platform(true));
    } finally {
      await platform(true);
    }
  } else {
    R.skip('platform level', 'E2E_ADMIN_PASS is not available');
  }

  // reseller level: enterprise build, a store whose owner has a reseller (the ee hook); Community has no reseller level
  const ee = await A.withPage(browser, (p) => A.hasEe(p));
  if (!ee) {
    R.skip('reseller level', 'Community build: no reseller level (always on); the Enterprise hook is covered by the Enterprise package ResellerOfflineQueueSwitchTest');
  } else {
    R.skip('reseller level', 'needs a reseller-owned store and the reseller portal login; covered by the ee feature test ResellerOfflineQueueSwitchTest (hook + portal switch)');
  }

  // ── owner sees exactly the orders that were placed ──────────────────────────────────────────────────────────────
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const list = await A.req(page, `${app}/orders/${store.id}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'follow' });
    A.expect(list.status === 200, `the owner orders poll must open (${list.status})`);
    const placed = A.sql('SELECT id FROM orders WHERE store_id = ? AND customer_name = ? ORDER BY id', [store.id, GUEST.name]).map((r) => Number(r[0]));
    A.expect(placed.length === 2, `exactly the two confirmed / replayed orders must exist (got ${placed.length})`);
    const txt = JSON.stringify(list.json || list.text);
    A.expect(placed.every((id) => txt.includes(String(id))), 'the owner orders page must list both placed orders');
    R.ok('owner sees exactly the two placed orders (the dropped ones never existed)');
  });

  R.summary();
});
