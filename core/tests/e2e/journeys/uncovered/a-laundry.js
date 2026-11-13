// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * a-laundry — P1-12 item 9 (a): the laundry & services store type, owner → customer → owner, plus cross-store IDOR.
 *
 *  owner      creates the store through the dashboard wizard (store type "Laundry & services"), a service category and
 *             two services (per kilogram, per item) with turnaround hours, turns on order scheduling with ONE booking
 *             per time slot
 *  customer   books on the storefront: services by weight (0.5 kg) and per item (2), a pickup slot and a delivery slot
 *             (address required), a second customer finds the booked slot full and cannot book it
 *  owner      sees both bookings on the service board, captures the real weight (the order is repriced) and moves a
 *             booking Received → Processing → Ready → Out for delivery → Done
 *  customer   the order-tracking page follows each status and shows the repriced total
 *  IDOR       another owner cannot open this store's catalog, service board, weight capture or status changes; a
 *             customer cannot add another store's service to this store's booking
 *
 * Owner and customer steps run on E2E_ARMED_URL when set (the same code and database as juw.test — see lib/lane-a.js),
 * else on E2E_BASE_URL.
 *
 * Run: npm run test:e2e -- a-laundry
 */

const A = require('../../lib/lane-a');

const SLUG = 'e2ea-laundry';
const TITLE = 'e2eA Laundry';
const CATEGORY = `${A.PREFIX}Wash and press`;
const KG = `${A.PREFIX}Wash and fold`;
const EACH = `${A.PREFIX}Shirt press`;

module.exports = A.journey(async function aLaundry({ browser, log }) {
  const R = A.recorder('a-laundry', log);
  const app = A.cfg.armed || A.cfg.baseUrl;
  const stamp = Date.now();

  // ── owner: store, category, services, scheduling ────────────────────────────────────────────────────────────────
  const owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical', 'scheduled_orders'] });
  const store = await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const s = await A.ensureStore(page, app, { type: 'laundry', title: TITLE, slug: SLUG, template: 'freshly' });
    A.expect(A.sqlValue('SELECT store_type FROM stores WHERE id = ?', [s.id]) === 'laundry', 'store was not created as a laundry store');
    const cat = await A.ensureServiceCategory(page, app, s.id, CATEGORY);
    const kg = await A.ensureService(page, app, s.id, { name: KG, price: '3.50', unit: 'kg', turnaround: 24, categoryId: cat.id });
    const each = await A.ensureService(page, app, s.id, { name: EACH, price: '1.25', unit: 'each', turnaround: 48, categoryId: cat.id });
    for (const svc of [kg, each]) { // a service created before the category existed is filed under it through the edit form
      if (Number(A.sqlValue('SELECT category_id FROM items WHERE id = ?', [svc.id])) !== cat.id) {
        await page.goto(`${app}/stores/${s.id}/services/${svc.id}/edit`, { waitUntil: 'domcontentloaded' });
        const f = `form[action$="/stores/${s.id}/services/${svc.id}"]`;
        await page.select(`${f} select[name=category_id]`, String(cat.id));
        await page.evaluate((sel) => { const p = document.querySelector(`${sel} input[name=price]`); if (p) p.setAttribute('step', 'any'); }, f);
        await A.submitForm(page, f);
      }
    }
    // one booking per slot, so "slot full" is reachable with two customers
    await A.setStoreOptions(page, app, s.id, { allow_order_scheduling: '1', scheduling_slot_capacity: '1', scheduling_lead_minutes: '30', scheduling_slot_interval: '15' });
    const row = (id) => A.sql('SELECT pricing_unit, turnaround_hours, price_minor, category_id FROM items WHERE id = ?', [id])[0];
    A.expect(row(kg.id).slice(0, 3).join() === 'kg,24,350', `per-kilogram service saved wrong: ${row(kg.id)}`);
    A.expect(row(each.id).slice(0, 3).join() === 'each,48,125', `per-item service saved wrong: ${row(each.id)}`);
    await page.goto(`${app}/stores/${s.id}/services`, { waitUntil: 'domcontentloaded' });
    const list = await A.pageText(page);
    A.expect(list.includes(KG) && list.includes(EACH) && /Kilogram/.test(list), 'the owner services list must show both services with their unit');
    return { ...s, kg, each, cat };
  });
  R.ok(`store #${store.id} "${SLUG}" (laundry) + category + 2 services (per kg, per item) + scheduling (1 booking per slot) set up in the owner dashboard`);

  // ── customer UI helpers (the "freshly" service theme) ────────────────────────────────────────────────────────────
  const click = (c, sel) => c.evaluate((s) => { const e = document.querySelector(s); if (e) e.click(); return !!e; }, sel);
  const clickText = (c, sel, txt) => c.evaluate((s, t) => {
    const e = [...document.querySelectorAll(s)].filter((x) => x.offsetParent !== null).find((x) => x.innerText.trim().startsWith(t));
    if (e) e.click(); return !!e;
  }, sel, txt);
  const bodyText = (c) => c.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));

  /** Add the services, open the schedule screen on `mode`, pick the first free slot on tomorrow and return its value. */
  const startBooking = async (c, { kgClicks = 5, eachClicks = 2, mode, day = 'TOMORROW' }) => {
    await c.goto(`${app}/${SLUG}`, { waitUntil: 'networkidle2' });
    await click(c, '[data-nav="services"]'); await A.sleep(500);
    A.expect(await click(c, '[data-open-service]'), 'no service category card on the storefront (the store lists services only under a category)');
    await A.sleep(600);
    for (let i = 0; i < kgClicks; i += 1) await click(c, `[data-item-inc="${store.kg.id}"]`);
    for (let i = 0; i < eachClicks; i += 1) await click(c, `[data-item-inc="${store.each.id}"]`);
    A.expect(await click(c, '[data-add-to-basket]'), 'no Book button on the service screen');
    await A.sleep(800);
    await click(c, '[data-nav="basket"]'); await A.sleep(600);
    const basket = await bodyText(c);
    await click(c, '[data-nav="schedule"]'); await A.sleep(800);
    await clickText(c, '.frs-optcard', mode); await A.sleep(1200); // the mode decides which slots exist: pick it first
    await clickText(c, '.frs-datechip', day); await A.sleep(1500);
    const slots = await c.evaluate(() => [...document.querySelectorAll('.frs-timeslot')].map((b) => ({ label: b.innerText.trim().split('\n')[0], disabled: b.disabled })));
    return { basket, slots };
  };
  const pickSlot = (c, label) => clickText(c, '.frs-timeslot', label);
  const confirm = async (c, { name, phone, address }) => {
    await c.type('#frsAddress', address);
    A.expect(await clickText(c, 'button', 'Continue'), 'no Continue button on the schedule screen');
    await A.sleep(900);
    await c.type('#frsName', name);
    await c.type('#frsPhone', phone);
    const placed = c.waitForResponse((r) => /\/book$/.test(r.url()) && r.request().method() === 'POST');
    A.expect(await clickText(c, 'button', 'Confirm booking'), 'no Confirm booking button');
    return (await placed).json();
  };

  // ── customer 1: a pickup booking ────────────────────────────────────────────────────────────────────────────────
  const first = await A.withPage(browser, async (c) => {
    const { basket, slots } = await startBooking(c, { mode: 'Pickup' });
    A.expect(/Subtotal\s*⁦?⁨?\$⁩?⁩?4\.25/.test(basket) || basket.includes('4.25'), `0.5 kg x 3.50 + 2 x 1.25 must read 4.25 in the basket (${basket.slice(0, 300)})`);
    const free = slots.filter((s) => !s.disabled);
    A.expect(free.length > 2, `tomorrow must offer free pickup slots (${JSON.stringify(slots.slice(0, 4))})`);
    const slot = free[0].label;
    A.expect(await pickSlot(c, slot), 'could not pick the slot');
    const res = await confirm(c, { name: `${A.PREFIX}Customer ${stamp}`, phone: '+966500000881', address: '5 Test Lane, Riyadh' });
    A.expect(res.success === true, `pickup booking must be accepted (${JSON.stringify(res)})`);
    const o = A.sql('SELECT id, type, status, scheduled_at, subtotal_minor, grand_total_minor FROM orders WHERE track_token = ?', [res.track_token])[0];
    A.expect(o && o[1] === 'pickup_slot' && o[2] === 'new', `pickup booking row wrong: ${o}`);
    A.expect(String(o[3]).slice(11, 16) === slot, `the booking must hold the chosen slot ${slot} (got ${o[3]})`);
    A.expect(Number(o[5]) === 425, `total must be 4.25 (got ${o[5]})`);
    const lines = A.sql('SELECT item_id, quantity, weight_kg FROM order_items WHERE order_id = ? ORDER BY item_id', [o[0]]);
    A.expect(lines.some((l) => Number(l[0]) === store.kg.id && Number(l[2]) === 0.5) && lines.some((l) => Number(l[0]) === store.each.id && Number(l[1]) === 2), `booking lines wrong: ${JSON.stringify(lines)}`);
    return { id: Number(o[0]), token: res.track_token, slot, date: String(o[3]).slice(0, 10) };
  }, { width: 430, height: 1400 });
  R.ok(`customer booked a pickup (order #${first.id}): 0.5 kg + 2 items = 4.25, slot ${first.date} ${first.slot} held`);

  // ── customer 2: the booked slot is full; a second pickup cannot take it ──────────────────────────────────────────
  await A.withPage(browser, async (c) => {
    const { slots } = await startBooking(c, { mode: 'Pickup' });
    const taken = slots.find((s) => s.label === first.slot);
    A.expect(taken && taken.disabled, `the slot ${first.slot} must show as full for the next customer (${JSON.stringify(taken)})`);
    const api = await c.evaluate(async (id, date) => {
      const r = await fetch(`/${id}/schedule-slots?type=pickup_slot&date=${date}`, { headers: { Accept: 'application/json' } });
      return r.json();
    }, store.id, first.date);
    const slotInfo = (api.slots || []).find((s) => String(s.value).endsWith(` ${first.slot}`));
    A.expect(slotInfo && slotInfo.full === true, `the slots endpoint must flag ${first.slot} full (${JSON.stringify(slotInfo)})`);
    // a hand-made request for the full slot is refused as well
    const csrf = await A.storefrontCsrf(c);
    const post = (url, fields) => c.evaluate(async (u, f, t) => {
      const fd = new FormData(); Object.entries(f).forEach(([k, v]) => fd.append(k, v));
      const r = await fetch(u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': t } });
      return { status: r.status, json: await r.json().catch(() => null) };
    }, url, fields, csrf);
    const line = await post(`${app}/${SLUG}/book/${store.each.id}/lines`, { quantity: 1 });
    A.expect(line.status === 200, `adding a service line must work (${JSON.stringify(line)})`);
    const full = await post(`${app}/${SLUG}/book`, { type: 'pickup_slot', customer_name: 'x', phone_number: '+966500000882', address: '1 St', scheduled_at: `${first.date} ${first.slot}` });
    A.expect(full.status === 422 && full.json && full.json.success === false, `a booking for a full slot must be refused (${JSON.stringify(full)})`);
    R.ok('second customer: the booked slot shows Full in the picker and in the slots endpoint; a direct booking for it is refused (422)');
  }, { width: 430, height: 1400 });

  // ── customer 3: a delivery booking needs an address and a slot ───────────────────────────────────────────────────
  const second = await A.withPage(browser, async (c) => {
    const { slots } = await startBooking(c, { kgClicks: 2, eachClicks: 0, mode: 'Delivery' });
    const free = slots.filter((s) => !s.disabled);
    A.expect(free.length > 2, 'tomorrow must offer free delivery slots');
    const slot = free[1].label;
    A.expect(await pickSlot(c, slot), 'could not pick the delivery slot');
    // no address → the theme refuses to continue; with one it books
    await clickText(c, 'button', 'Continue'); await A.sleep(500);
    A.expect(!(await bodyText(c)).includes('Confirm booking') || !(await c.evaluate(() => { const b = document.getElementById('frsConfirmBtn'); return !!b && b.offsetParent !== null; })), 'continuing without an address must not reach the confirm step');
    const res = await confirm(c, { name: `${A.PREFIX}Delivery ${stamp}`, phone: '+966500000883', address: '9 Delivery Road, Riyadh' });
    A.expect(res.success === true, `delivery booking must be accepted (${JSON.stringify(res)})`);
    const o = A.sql('SELECT id, type, address, scheduled_at, grand_total_minor FROM orders WHERE track_token = ?', [res.track_token])[0];
    A.expect(o[1] === 'delivery' && /9 Delivery Road/.test(o[2] || ''), `delivery booking must keep its address (${o})`);
    A.expect(Number(o[4]) === 70, `0.2 kg x 3.50 = 0.70 (got ${o[4]})`);
    return { id: Number(o[0]), token: res.track_token };
  }, { width: 430, height: 1400 });
  R.ok(`delivery booking (order #${second.id}) kept its address and slot; 0.2 kg priced 0.70`);

  // ── owner: service board, weight capture, status flow; customer: tracking ───────────────────────────────────────
  await A.withPage(browser, async (ob) => {
    await A.login(ob, app, { email: owner.email, pass: owner.pass });
    const boardUrl = `${app}/services/board?store_id=${store.id}`;
    const card = (id) => ob.evaluate((i) => { const a = document.querySelector(`article[data-order-id="${i}"]`); return a ? { state: a.closest('[data-state]').dataset.state, text: a.innerText.replace(/\s+/g, ' ') } : null; }, id);
    await ob.goto(boardUrl, { waitUntil: 'domcontentloaded' });
    const c1 = await card(first.id);
    const c2 = await card(second.id);
    A.expect(c1 && c1.state === 'new' && c1.text.includes(`${A.PREFIX}Customer ${stamp}`), `the pickup booking must sit in Received (${JSON.stringify(c1)})`);
    A.expect(c2 && c2.state === 'new', `the delivery booking must sit in Received (${JSON.stringify(c2)})`);
    const cols = await ob.evaluate(() => [...document.querySelectorAll('.ui-board__title')].map((h) => h.innerText.trim()));
    A.expect(cols.filter((t) => t === 'Out for delivery').length === 1, `the board must have one "Out for delivery" column (${cols})`);
    R.ok('owner service board: both bookings under Received; one "Out for delivery" column');

    // weight capture: 0.5 kg → 0.8 kg reprices the order
    const kgLine = Number(A.sql('SELECT id FROM order_items WHERE order_id = ? AND item_id = ?', [first.id, store.kg.id])[0][0]);
    await ob.evaluate((o, i) => { document.querySelector(`form[action*="/services/board/${o}/items/${i}/weight"] input[name=weight_kg]`).value = '0.8'; }, first.id, kgLine);
    await A.submitForm(ob, `form[action*="/services/board/${first.id}/items/${kgLine}/weight"]`);
    const repriced = Number(A.sqlValue('SELECT grand_total_minor FROM orders WHERE id = ?', [first.id]));
    A.expect(repriced === 530, `0.8 kg x 3.50 + 2 x 1.25 = 5.30 after the weight capture (got ${repriced})`);
    R.ok('weight capture: 0.5 kg → 0.8 kg repriced the booking 4.25 → 5.30');

    // status flow, with the customer's tracking page after each step
    await A.withPage(browser, async (cust) => {
      await cust.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' });
      const track = async () => {
        const r = await cust.evaluate(async (t) => (await fetch(`/order/track/${t}`, { headers: { Accept: 'application/json' } })).json(), first.token);
        return r.status;
      };
      A.expect(await track() === 'new', 'tracking starts at new');
      const seen = ['new'];
      for (const next of ['packing', 'ready', 'collected', 'done']) {
        await ob.goto(boardUrl, { waitUntil: 'domcontentloaded' });
        await A.submitForm(ob, `form[action*="/services/board/${first.id}/advance"]`);
        const dbStatus = A.sqlValue('SELECT status FROM orders WHERE id = ?', [first.id]);
        A.expect(dbStatus === next, `Advance must move the booking to ${next} (got ${dbStatus})`);
        A.expect(await track() === next, `the customer's tracking must follow to ${next}`);
        seen.push(next);
      }
      const page = await cust.goto(`${app}/order/track/${first.token}`, { waitUntil: 'domcontentloaded' });
      A.expect(page.status() === 200, 'the tracking page must open');
      const text = await A.pageText(cust);
      A.expect(/\$?5\.30/.test(text) && /24 h turnaround/.test(text), `the tracking page must show the repriced total and the turnaround (${text.slice(0, 400)})`);
      R.ok(`status flow ${seen.join(' → ')}: each step on the board shows on the customer's tracking page, which carries the repriced total 5.30`);
    }, { width: 430, height: 1000 });
    const final = await card(first.id);
    A.expect(final === null || final.state === 'done', 'a finished booking sits in Done');
  });

  // ── cross-store IDOR ────────────────────────────────────────────────────────────────────────────────────────────
  const foreign = A.sql("SELECT s.id, s.slug, (SELECT id FROM items WHERE store_id = s.id AND deleted_at IS NULL ORDER BY id LIMIT 1) FROM stores s WHERE s.store_type = 'laundry' AND s.id <> ? AND s.user_id <> ? ORDER BY s.id LIMIT 1", [store.id, owner.id])[0];
  await A.withPage(browser, async (other) => {
    await A.login(other, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    A.expect(await A.status(other, `${app}/stores/${store.id}/services`) === 404, "another owner must get a 404 on this store's services");
    A.expect(await A.status(other, `${app}/stores/${store.id}/services/${store.kg.id}/edit`) === 404, "another owner must get a 404 on this store's service form");
    const before = A.sql('SELECT status, grand_total_minor FROM orders WHERE id = ?', [second.id])[0].join('|');
    const board = await A.req(other, `${app}/services/board?store_id=${store.id}`, { redirect: 'follow' });
    A.expect(!board.text.includes(`${A.PREFIX}Delivery ${stamp}`), "another owner's service board must not list this store's bookings");
    const adv = await A.req(other, `${app}/services/board/${second.id}/advance?store_id=${store.id}`, { method: 'POST', form: {} });
    A.expect([403, 404].includes(adv.status) || adv.type === 'opaqueredirect', `another owner must not advance this store's booking (${adv.status})`);
    const kgItem = Number(A.sql('SELECT id FROM order_items WHERE order_id = ? AND item_id = ?', [second.id, store.kg.id])[0][0]);
    await A.req(other, `${app}/services/board/${second.id}/items/${kgItem}/weight?store_id=${store.id}`, { method: 'POST', form: { weight_kg: '9' } });
    const post = await A.req(other, `${app}/stores/${store.id}/services`, { method: 'POST', form: { name: 'outsider', price: 1, pricing_unit: 'each' } });
    A.expect([403, 404].includes(post.status), `another owner must not add a service here (${post.status})`);
    A.expect(A.sql('SELECT status, grand_total_minor FROM orders WHERE id = ?', [second.id])[0].join('|') === before, 'the booking must be untouched by the outsider');
    A.expect(A.sqlValue("SELECT 1 FROM items WHERE store_id = ? AND name = 'outsider'", [store.id]) === null, 'no service may be created by the outsider');
    R.ok("IDOR: another owner gets 404 on this store's services and service form, sees none of its bookings on the board, and cannot advance, reprice or add to it");
  });
  if (foreign && foreign[2]) {
    await A.withPage(browser, async (c) => {
      await c.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' });
      const csrf = await A.storefrontCsrf(c);
      const r = await c.evaluate(async (u, t) => {
        const fd = new FormData(); fd.append('quantity', '1');
        const x = await fetch(u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': t } });
        return x.status;
      }, `${app}/${SLUG}/book/${foreign[2]}/lines`, csrf);
      A.expect(r === 404, `another store's service must not go into this store's booking (${r})`);
      R.ok("IDOR: a service of another store cannot be added to this store's booking (404)");
    }, { width: 430, height: 900 });
  } else {
    R.skip('foreign service leg', 'no second laundry store with a service in the database');
  }

  R.summary();
});
