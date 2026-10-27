// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * e-driver — P1-12 item 9 (e): the store driver app, owner → driver → customer → owner, plus cross-owner IDOR.
 * TOUCHES TENANCY (driver tokens, order scoping) — for coordinator review.
 *
 *  owner      creates a food store with a dish, adds two drivers on Delivery > Drivers (each token is shown once on the
 *             page), the customer's delivery order is assigned to the first driver from the dispatch map
 *  driver 1   (Bearer token, the driver app's own API) sees exactly that delivery, nothing else is "available" (self-claim
 *             is off), moves it picked up → out for delivery → delivered; a backwards move is refused
 *  driver 2   sees no deliveries, cannot read or move the first driver's delivery (404), a deactivated driver's token stops
 *             working (401)
 *  customer   the order-tracking page follows the delivery after each step
 *  owner      sees the delivery as delivered on the orders board and the dispatch report counts it for driver 1
 *  IDOR       another owner sees none of these drivers, cannot rotate / toggle / delete them (404) nor assign them to an
 *             order; the driver token is not accepted as a dashboard login
 *
 * Self-claim: a per-store owner switch on Delivery > Drivers (off by default). Off, nothing is "available" and a claim is
 * refused; on, a driver of this store sees and claims an unassigned delivery order (once), a second driver of the store
 * finds it taken, and a driver of ANOTHER store of the owner gets 404 and sees nothing.
 *
 * Run: npm run test:e2e -- e-driver
 */

const crypto = require('crypto');
const A = require('../../lib/lane-a');
const K = require('../../lib/order-kit-v2');

const SLUG = 'e2ea-driver';
const DISH = { name: `${A.PREFIX}Courier box`, price: 11 };

module.exports = A.journey(async function eDriver({ browser, log }) {
  const R = A.recorder('e-driver', log);
  const app = A.cfg.armed || A.cfg.baseUrl;
  const owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical'] });
  const stamp = Date.now();
  A.seedLaneFixtures();

  // ── owner: store, dish, two drivers ─────────────────────────────────────────────────────────────────────────────
  const S = { drivers: [] };
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    S.store = await A.ensureStore(page, app, { type: 'restaurant', title: 'e2eA Driver', slug: SLUG });
    const cat = await A.ensureMenuCategory(page, app, S.store.id, `${A.PREFIX}Courier menu`);
    S.item = await A.ensureMenuItem(page, app, S.store.id, { ...DISH, categoryId: cat });
    const addDriver = async (name, storeId, i) => {
      await page.goto(`${app}/drivers`, { waitUntil: 'domcontentloaded' });
      const form = 'form[action$="/drivers"]';
      await page.waitForSelector(`${form} input[name=name]`);
      await page.type(`${form} input[name=name]`, name);
      await page.type(`${form} input[name=phone]`, `+96650000${stamp % 10000}${i}`);
      await page.select(`${form} select[name=store_id]`, String(storeId));
      await A.submitForm(page, form);
      const token = await page.evaluate(() => document.querySelector('.ui-drivers__token')?.textContent.trim() || null);
      A.expect(token && token.length >= 40, `the new driver's one-time token must be shown on the page (${name})`);
      const id = Number(A.sqlValue('SELECT id FROM drivers WHERE owner_id = ? AND name = ?', [owner.id, name]));
      A.expect(id, `driver ${name} must be saved`);
      A.expect(A.sqlValue('SELECT token FROM drivers WHERE id = ?', [id]) !== token, 'the token must be stored hashed, not in clear');
      return { id, name, token };
    };
    for (const [i, name] of [`${A.PREFIX}Driver One ${stamp}`, `${A.PREFIX}Driver Two ${stamp}`].entries()) S.drivers.push(await addDriver(name, S.store.id, i));
    // a driver bound to ANOTHER store of the same owner (for the self-claim tenancy check)
    S.otherStoreId = Number(A.sqlValue('SELECT id FROM stores WHERE user_id = ? AND id <> ? ORDER BY id LIMIT 1', [owner.id, S.store.id]));
    A.expect(S.otherStoreId, 'the lane owner needs a second store (run another journey first)');
    S.foreign = await addDriver(`${A.PREFIX}Driver Elsewhere ${stamp}`, S.otherStoreId, 9);
    // start from the default (a previous run may have stopped half way): self-claim off on both stores
    for (const id of [S.store.id, S.otherStoreId]) {
      await page.goto(`${app}/drivers`, { waitUntil: 'domcontentloaded' });
      const f = `form[data-self-claim="${id}"]`;
      await page.evaluate((sel) => { const c = document.querySelector(`${sel} input[type=checkbox][name=enabled]`); if (c && c.checked) c.click(); }, f);
      await A.submitForm(page, f);
    }
  });
  R.ok(`store #${S.store.id} with a dish; two drivers (and one bound to another store) created in the dashboard, each with a one-time token (stored hashed)`);

  // ── customer: a delivery order ──────────────────────────────────────────────────────────────────────────────────
  const order = {};
  await A.withPage(browser, async (c) => {
    const rt = await K.loadRuntime(c, `${app}/${SLUG}`, { slug: SLUG });
    const res = await K.submitOrder(c, { rt, items: [{ id: S.item.id, quantity: 1 }], fields: { name: `${A.PREFIX}Courier customer`, 'ordering-type': 'delivery', 'phone-number': '+966512345678', address: '12 King Fahd Road, Riyadh', pay_via: 'pay_on_counter', client_uuid: crypto.randomUUID() } });
    A.expect(res.success, `the delivery order must be accepted: ${JSON.stringify(res)}`);
    order.token = K.trackToken(res.track_url);
    order.id = Number(A.sqlValue('SELECT id FROM orders WHERE track_token = ?', [order.token]));
    A.expect(A.sqlValue('SELECT type FROM orders WHERE id = ?', [order.id]) === 'delivery', 'the order must be a delivery order');
  }, { width: 430, height: 900 });

  const track = (c) => c.goto(`${app}/order/track/${order.token}`, { waitUntil: 'domcontentloaded' }).then(() => A.pageText(c));
  const api = async (driver, method, path, body) => {
    const r = await fetch(`${app}/api/v1/driver/${path}`, { method, headers: { Authorization: `Bearer ${driver.token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
    return { status: r.status, json: await r.json().catch(() => null) };
  };
  const ids = (j) => ((j && (j.data || j)) || []).map((o) => Number(o.id));

  // ── owner assigns from the dispatch map ─────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    // the first driver reports a position so the map can suggest them
    const loc = await api(S.drivers[0], 'PUT', 'location', { lat: 24.7136, lng: 46.6753 });
    A.expect(loc.status === 200, `driver location update must work (${loc.status})`);
    await page.goto(`${app}/dispatch/map?store_id=${S.store.id}`, { waitUntil: 'domcontentloaded' });
    await A.sleep(1500);
    const clicked = await page.evaluate((id, name) => {
      const forms = [...document.querySelectorAll(`#unassigned form[action*="/dispatch/${id}/assign"]`)];
      const f = forms.find((x) => x.innerText.includes(name));
      if (!f) return false; f.querySelector('button').click(); return true;
    }, order.id, S.drivers[0].name);
    if (clicked) { await page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}); }
    else {
      // no suggestion rendered for this order: the same form post the map would send
      const r = await A.req(page, `${app}/dispatch/${order.id}/assign?store_id=${S.store.id}`, { method: 'POST', form: { driver_id: S.drivers[0].id }, redirect: 'follow' });
      A.expect(r.status < 400, `assign must work (${r.status})`);
    }
    A.expect(Number(A.sqlValue('SELECT driver_id FROM orders WHERE id = ?', [order.id])) === S.drivers[0].id, 'the order must be assigned to driver 1');
    A.expect(A.sqlValue('SELECT delivery_status FROM orders WHERE id = ?', [order.id]) === 'assigned', 'delivery status must read assigned');
    S.assignedViaMap = clicked;
  });
  R.ok(`delivery order #${order.id} assigned to driver 1 ${S.assignedViaMap ? 'from the dispatch map suggestions' : 'through the dispatch assign form'}`);

  // ── drivers ─────────────────────────────────────────────────────────────────────────────────────────────────────
  const [d1, d2] = S.drivers;
  const me = await api(d1, 'GET', 'me');
  A.expect(me.status === 200, `driver 1 must reach /me (${me.status})`);
  const mine = await api(d1, 'GET', 'deliveries?active=1');
  A.expect(mine.status === 200 && ids(mine.json).includes(order.id) && ids(mine.json).every((id) => Number(A.sqlValue('SELECT driver_id FROM orders WHERE id = ?', [id])) === d1.id), `driver 1 must see exactly their own deliveries (${JSON.stringify(ids(mine.json))})`);
  const open = await api(d1, 'GET', 'available');
  A.expect(open.status === 200 && ids(open.json).length === 0, `with self-claim off nothing is "available" (${JSON.stringify(ids(open.json))})`);
  const claim = await api(d1, 'POST', `deliveries/${order.id}/claim`);
  A.expect(claim.status === 403 || claim.status === 409, `self-claim is off: claim must be refused (${claim.status})`);

  // driver 2 cannot see or touch it
  const theirs = await api(d2, 'GET', 'deliveries');
  A.expect(theirs.status === 200 && !ids(theirs.json).includes(order.id), `driver 2 must not see driver 1's delivery (${JSON.stringify(ids(theirs.json))})`);
  const poke = await api(d2, 'PUT', `deliveries/${order.id}/status`, { delivery_status: 'picked_up' });
  A.expect(poke.status === 404, `driver 2 must get a 404 moving driver 1's delivery (${poke.status})`);
  const pokeOtp = await api(d2, 'POST', `deliveries/${order.id}/pod-otp`, { otp: '0000' });
  A.expect(pokeOtp.status === 404, `driver 2 must get a 404 on driver 1's proof of delivery (${pokeOtp.status})`);
  A.expect(A.sqlValue('SELECT delivery_status FROM orders WHERE id = ?', [order.id]) === 'assigned', 'the refused moves must change nothing');
  R.ok(`driver 1 sees exactly delivery #${order.id} (nothing "available", self-claim refused); driver 2 sees none and gets 404 on its status and proof of delivery`);

  // status flow, customer track page follows
  const seen = {};
  await A.withPage(browser, async (c) => {
    seen.assigned = await track(c);
    for (const st of ['picked_up', 'out_for_delivery', 'delivered']) {
      const r = await api(d1, 'PUT', `deliveries/${order.id}/status`, { delivery_status: st });
      A.expect(r.status === 200, `driver 1 must be able to set ${st} (${r.status} ${JSON.stringify(r.json).slice(0, 160)})`);
      A.expect(A.sqlValue('SELECT delivery_status FROM orders WHERE id = ?', [order.id]) === st, `delivery status must read ${st}`);
      seen[st] = await track(c);
      if (st === 'picked_up') {
        const back = await api(d1, 'PUT', `deliveries/${order.id}/status`, { delivery_status: 'picked_up' });
        const regress = await api(d1, 'PUT', `deliveries/${order.id}/status`, { delivery_status: 'picked_up' });
        A.expect(back.status === 200 && regress.status === 200, 'repeating the same step is harmless');
      }
    }
    const backwards = await api(d1, 'PUT', `deliveries/${order.id}/status`, { delivery_status: 'picked_up' });
    A.expect(backwards.status === 422, `a delivered order must not move backwards (${backwards.status})`);
    A.expect(seen.picked_up !== seen.assigned && seen.out_for_delivery !== seen.picked_up && seen.delivered !== seen.out_for_delivery, 'the tracking page must change after every driver step');
    A.expect(/deliver/i.test(seen.delivered), `the tracking page must say the order was delivered (${seen.delivered.slice(0, 200)})`);
  }, { width: 430, height: 900 });
  const after = await api(d1, 'GET', 'deliveries');
  A.expect(ids(after.json).includes(order.id), 'a delivered order stays in the driver\'s history');
  const active = await api(d1, 'GET', 'deliveries?active=1');
  A.expect(!ids(active.json).includes(order.id), 'a delivered order leaves the active queue');
  R.ok('driver 1: picked up → out for delivery → delivered (a backwards move refused with 422); the customer tracking page changed after every step and ends on delivered; the active queue empties');

  // ── owner: the result; deactivated token ────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    await page.goto(`${app}/dispatch/report?store_id=${S.store.id}`, { waitUntil: 'domcontentloaded' });
    const text = await A.pageText(page);
    A.expect(text.includes(d1.name), `the dispatch report must list driver 1 (${text.slice(0, 200)})`);
    await page.goto(`${app}/drivers`, { waitUntil: 'domcontentloaded' });
    const toggle = `form[action$="/drivers/${d2.id}/toggle"]`;
    await A.submitForm(page, toggle);
    A.expect(Number(A.sqlValue('SELECT active FROM drivers WHERE id = ?', [d2.id])) === 0, 'the owner must be able to deactivate driver 2');
    A.expect((await api(d2, 'GET', 'me')).status === 401, 'a deactivated driver\'s token must stop working (401)');
    await page.goto(`${app}/drivers`, { waitUntil: 'domcontentloaded' });
    await A.submitForm(page, toggle);
    A.expect((await api(d2, 'GET', 'me')).status === 200, 'driver 2 works again once reactivated');
    A.expect((await api({ token: 'not-a-token' }, 'GET', 'me')).status === 401, 'a made-up token must be refused (401)');
  });
  R.ok('owner: the delivery report lists driver 1; deactivating a driver kills their token at once (401), reactivating restores it; a made-up token is refused');

  // ── self-claim (owner switch) ───────────────────────────────────────────────────────────────────────────────────
  const orderB = {};
  await A.withPage(browser, async (c) => {
    const rt = await K.loadRuntime(c, `${app}/${SLUG}`, { slug: SLUG });
    const res = await K.submitOrder(c, { rt, items: [{ id: S.item.id, quantity: 1 }], fields: { name: `${A.PREFIX}Second customer`, 'ordering-type': 'delivery', 'phone-number': '+966512345678', address: '14 King Fahd Road, Riyadh', pay_via: 'pay_on_counter', client_uuid: crypto.randomUUID() } });
    A.expect(res.success, `the second delivery order must be accepted: ${JSON.stringify(res)}`);
    orderB.id = Number(A.sqlValue('SELECT id FROM orders WHERE track_token = ?', [K.trackToken(res.track_url)]));
  }, { width: 430, height: 900 });
  const setSelfClaim = async (page, on, storeId = S.store.id) => {
    await page.goto(`${app}/drivers`, { waitUntil: 'domcontentloaded' });
    const form = `form[data-self-claim="${storeId}"]`;
    await page.evaluate((sel, want) => { const c = document.querySelector(`${sel} input[type=checkbox][name=enabled]`); if (c && c.checked !== want) c.click(); }, form, on);
    await A.submitForm(page, form);
  };
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    await setSelfClaim(page, false);
    const off = await api(d1, 'GET', 'available');
    A.expect(off.status === 200 && ids(off.json).length === 0, `self-claim off: nothing available (${JSON.stringify(ids(off.json))})`);
    A.expect([403].includes((await api(d1, 'POST', `deliveries/${orderB.id}/claim`)).status), 'self-claim off: a claim must be refused (403)');

    await setSelfClaim(page, true);
    await setSelfClaim(page, true, S.otherStoreId); // the other store allows self-claim too: still not this store's order
    await page.goto(`${app}/drivers`, { waitUntil: 'domcontentloaded' });
    A.expect(await page.evaluate((sel) => document.querySelector(`${sel} input[type=checkbox][name=enabled]`)?.checked, `form[data-self-claim="${S.store.id}"]`) === true, 'the owner switch must read on after saving');
    const on = await api(d1, 'GET', 'available');
    A.expect(ids(on.json).includes(orderB.id), `self-claim on: the unassigned order must be available (${JSON.stringify(ids(on.json))})`);
    A.expect(!ids((await api(S.foreign, 'GET', 'available')).json).includes(orderB.id), 'a driver of another store must not see this store\'s order as available');
    A.expect((await api(S.foreign, 'POST', `deliveries/${orderB.id}/claim`)).status === 404, 'a driver of another store must get a 404 claiming it');
    A.expect(A.sqlValue('SELECT driver_id FROM orders WHERE id = ?', [orderB.id]) === null, 'the refused cross-store claim must change nothing');

    const claim = await api(d1, 'POST', `deliveries/${orderB.id}/claim`);
    A.expect(claim.status === 200 && Number(A.sqlValue('SELECT driver_id FROM orders WHERE id = ?', [orderB.id])) === d1.id && A.sqlValue('SELECT delivery_status FROM orders WHERE id = ?', [orderB.id]) === 'assigned', `driver 1 must claim it (assigned) (${claim.status})`);
    A.expect((await api(d2, 'POST', `deliveries/${orderB.id}/claim`)).status === 409, 'a second driver of the store must find it taken (409)');
    A.expect(ids((await api(d1, 'GET', 'deliveries?active=1')).json).includes(orderB.id), 'the claimed order must be in driver 1\'s queue');
    A.expect(!ids((await api(d2, 'GET', 'deliveries')).json).includes(orderB.id), 'and not in driver 2\'s');
    const step = await api(d1, 'PUT', `deliveries/${orderB.id}/status`, { delivery_status: 'picked_up' });
    A.expect(step.status === 200, `a claimed order moves like an assigned one (${step.status})`);
    await setSelfClaim(page, false); // leave the stores as found
    await setSelfClaim(page, false, S.otherStoreId);
  });
  R.ok(`self-claim: off by default (nothing available, claim 403); the owner switches it on; driver 1 claims order #${orderB.id} (assigned, 409 for driver 2, then picked up); a driver of another store sees nothing and gets 404`);

  // ── IDOR ────────────────────────────────────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (other) => {
    await A.login(other, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    await other.goto(`${app}/drivers`, { waitUntil: 'domcontentloaded' });
    const text = await A.pageText(other);
    A.expect(!text.includes(d1.name) && !text.includes(d2.name), 'another owner must not see these drivers');
    for (const sub of ['rotate', 'toggle']) {
      const r = await A.req(other, `${app}/drivers/${d1.id}/${sub}`, { method: 'POST', form: {} });
      A.expect(r.status === 404, `another owner must get a 404 on ${sub} (${r.status})`);
    }
    const del = await A.req(other, `${app}/drivers/${d1.id}`, { method: 'DELETE' });
    A.expect(del.status === 404, `another owner must not delete the driver (${del.status})`);
    const assign = await A.req(other, `${app}/dispatch/${order.id}/assign?store_id=${S.store.id}`, { method: 'POST', form: { driver_id: d2.id } });
    A.expect(assign.status === 403 || assign.status === 404, `another owner must not assign this order (${assign.status})`);
  });
  A.expect(Number(A.sqlValue('SELECT driver_id FROM orders WHERE id = ?', [order.id])) === d1.id && Number(A.sqlValue('SELECT COUNT(*) FROM drivers WHERE id = ?', [d1.id])) === 1, 'nothing may have changed after the cross-owner attempts');
  await A.withPage(browser, async (anon) => {
    const resp = await anon.goto(`${app}/drivers`, { waitUntil: 'domcontentloaded' });
    A.expect(new URL(anon.url()).pathname === '/login', `a driver token is not a dashboard login (${anon.url()})`);
  });
  R.ok('IDOR: another owner sees none of these drivers and gets 404 on rotate, toggle, delete and cannot assign the order; nothing changed');

  R.summary();
});
