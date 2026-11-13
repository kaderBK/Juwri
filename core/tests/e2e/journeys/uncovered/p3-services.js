// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p3-services — Phase 3 (P3-00): the new "services" store type (salon-style appointments), owner → customer → owner,
 * plus cross-store IDOR.
 *
 *  owner      creates the store through the dashboard wizard (store type "Appointments and services", theme set service),
 *             a service category and two services (Add service form), switches order scheduling on with ONE booking per
 *             slot; the Fulfilment page offers Appointment and At home only (At home: address AND time slot)
 *  customer   booking page of the inherited theme set (the bare booking page — `bookla` until the kit theme `book`,
 *             P3-11): adds services, books an At home visit (address and slot both required) and an appointment (slot
 *             only); a second customer finds the booked slot full; Delivery / Pickup are refused
 *  owner      sees both bookings on the service board with their type tags and moves one Received → Processing →
 *             Ready → Out for delivery → Done; the customer's tracking page follows
 *  IDOR       another owner gets 404 on this store's services and service board and cannot advance a booking; a
 *             customer cannot add another store's service to this store's booking
 *
 * Reported, not invented: the staff x service x time booking engine, deposits, reminders and per-staff slots are P3-10;
 * until then bookings use the order slot machinery (SchedulingService), and the service board reuses the laundry state
 * words ("Out for delivery") for the last step — wrong wording for a salon, replaced by the book theme (P3-11).
 *
 * Owner and customer steps run on E2E_ARMED_URL when set, else on E2E_BASE_URL.
 *
 * Run: npm run test:e2e -- p3-services
 */

const A = require('../../lib/lane-a');

const SLUG = 'e2ea-services';
const TITLE = 'e2eA Salon';
const CATEGORY = `${A.PREFIX}Hair and nails`;
const CUT = { name: `${A.PREFIX}Haircut`, price: '120.00', unit: 'each', turnaround: 1 };
const MANI = { name: `${A.PREFIX}Manicure`, price: '80.00', unit: 'each', turnaround: 1 };

module.exports = A.journey(async function p3Services({ browser, log }) {
  const R = A.recorder('p3-services', log);
  const app = A.cfg.armed || A.cfg.baseUrl;
  const stamp = Date.now();

  // ── owner: store, services, scheduling, fulfilment ──────────────────────────────────────────────────────────────
  const owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical', 'scheduled_orders'] });
  const store = await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const s = await A.ensureStore(page, app, { type: 'services', title: TITLE, slug: SLUG, template: 'bookla' });
    A.expect(A.sqlValue('SELECT store_type FROM stores WHERE id = ?', [s.id]) === 'services', 'the store was not created as a services store');
    const cat = await A.ensureServiceCategory(page, app, s.id, CATEGORY);
    const cut = await A.ensureService(page, app, s.id, { ...CUT, categoryId: cat.id });
    const mani = await A.ensureService(page, app, s.id, { ...MANI, categoryId: cat.id });
    await A.setStoreOptions(page, app, s.id, { allow_order_scheduling: '1', scheduling_slot_capacity: '1', scheduling_lead_minutes: '30', scheduling_slot_interval: '15' });
    await page.goto(`${app}/stores/${s.id}/services`, { waitUntil: 'domcontentloaded' });
    const list = await A.pageText(page);
    A.expect(list.includes(CUT.name) && list.includes(MANI.name), 'the owner services list must show both services');
    // the goods catalogue is not this type's
    A.expect(await A.status(page, `${app}/stores/${s.id}/products`) === 404, 'a services store must not open the goods catalogue');

    await page.goto(`${app}/fulfillment?store_id=${s.id}`, { waitUntil: 'domcontentloaded' });
    const modes = await page.evaluate(() => [...document.querySelectorAll('input[name="modes[]"]')].map((i) => i.value));
    A.expect(modes.slice().sort().join() === 'appointment,at_home', `the fulfilment page must offer appointment and at home only (got ${modes})`);
    const text = await A.pageText(page);
    A.expect(/At home/.test(text) && /address and a time slot/i.test(text), 'the At home switch must say it collects an address AND a time slot');
    await page.evaluate(() => document.querySelectorAll('input[name="modes[]"]').forEach((i) => { if (!i.checked) i.click(); }));
    await A.submitForm(page, 'form[action*="/fulfillment"]');
    const stored = String(A.storeOption(s.id, 'fulfillment.enabled_modes') || '');
    A.expect(/appointment/.test(stored) && /at_home/.test(stored), `both modes must be saved (${stored})`);
    return { ...s, cut, mani, cat };
  });
  R.ok(`services #${store.id} "${SLUG}" + category + 2 services created in the owner dashboard; Fulfilment offers Appointment and At home (address AND slot) only; scheduling on, one booking per slot`);

  // ── customer helpers (the booking page) ─────────────────────────────────────────────────────────────────────────
  const openBooking = async (c) => c.goto(`${app}/${SLUG}/book`, { waitUntil: 'domcontentloaded' });
  const addService = async (c, serviceId) => {
    await openBooking(c);
    await Promise.all([
      c.evaluate((id) => document.querySelector(`form[action$="/book/${id}/lines"] button[type=submit]`).click(), serviceId),
      c.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    ]);
  };
  const fill = async (c, { mode, name, phone, address, slot }) => {
    await openBooking(c);
    await c.evaluate((m) => document.querySelector(`input[name=type][value="${m}"]`).click(), mode);
    await c.type('#customer_name', name);
    await c.type('#phone_number', phone);
    if (address) await c.type('#address', address);
    if (slot) await c.select('#scheduled_at', slot);
  };
  const submit = async (c) => {
    await Promise.all([
      c.evaluate(() => document.querySelector('form[action$="/book"] button[type=submit]').click()),
      c.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    ]);
    return { url: c.url(), text: await A.pageText(c) };
  };
  const freeSlots = (c) => c.evaluate(() => [...document.querySelectorAll('#scheduled_at option')].filter((o) => o.value && !o.disabled).map((o) => o.value));

  // ── customer 1: an At home visit, then an appointment ───────────────────────────────────────────────────────────
  const orders = {};
  let slotTaken;
  await A.withPage(browser, async (c) => {
    A.expect((await c.goto(`${app}/${SLUG}`, { waitUntil: 'networkidle2' })).status() === 200, 'the services storefront must open');
    await addService(c, store.cut.id);
    await openBooking(c);
    const modes = await c.evaluate(() => [...document.querySelectorAll('input[name=type]')].map((i) => i.value));
    A.expect(modes.slice().sort().join() === 'appointment,at_home', `the booking page must offer appointment and at home only (got ${modes})`);
    A.expect(/120\.00/.test(await A.pageText(c)), 'one haircut must read 120.00');
    const slots = await freeSlots(c);
    A.expect(slots.length > 3, `the booking page must offer free slots (${slots.length})`);

    // At home: address AND slot
    await fill(c, { mode: 'at_home', name: `${A.PREFIX}Client ${stamp}`, phone: '+966500000661', slot: slots[0] });
    const noAddr = await submit(c);
    A.expect(/address is required/i.test(noAddr.text), `an At home booking without an address must show the address error (${noAddr.text.slice(0, 200)})`);
    await fill(c, { mode: 'at_home', name: `${A.PREFIX}Client ${stamp}`, phone: '+966500000661', address: '7 Palm Street, Al Malqa' });
    const noSlot = await submit(c);
    A.expect(/time slot/i.test(noSlot.text), `an At home booking without a slot must show the slot error (${noSlot.text.slice(0, 200)})`);
    await fill(c, { mode: 'at_home', name: `${A.PREFIX}Client ${stamp}`, phone: '+966500000661', address: '7 Palm Street, Al Malqa', slot: slots[0] });
    const home = await submit(c);
    A.expect(/\/order\/track\//.test(home.url), `the At home booking must land on the tracking page (${home.url} ${home.text.slice(0, 200)})`);
    const ht = home.url.split('/order/track/')[1].split(/[?#]/)[0];
    const h = A.sql('SELECT id, type, address, scheduled_at, grand_total_minor FROM orders WHERE track_token = ?', [ht])[0];
    A.expect(h[1] === 'at_home' && /7 Palm Street/.test(h[2] || '') && String(h[3]).slice(0, 16).replace('T', ' ') === slots[0] && Number(h[4]) === 12000, `At home booking row wrong: ${h}`);
    orders.home = { id: Number(h[0]), token: ht };
    slotTaken = slots[0];
    R.ok(`customer: At home booking #${orders.home.id} — address and slot both required, then kept (${slots[0]}), total 120.00`);

    // appointment: slot only, no address stored
    await addService(c, store.mani.id);
    const second = (await (async () => { await openBooking(c); return freeSlots(c); })()).filter((v) => v !== slotTaken);
    await fill(c, { mode: 'appointment', name: `${A.PREFIX}Client ${stamp}`, phone: '+966500000661', slot: second[1] });
    const appt = await submit(c);
    A.expect(/\/order\/track\//.test(appt.url), `the appointment must land on the tracking page (${appt.url} ${appt.text.slice(0, 200)})`);
    const at = appt.url.split('/order/track/')[1].split(/[?#]/)[0];
    const a = A.sql('SELECT id, type, address, scheduled_at, grand_total_minor FROM orders WHERE track_token = ?', [at])[0];
    A.expect(a[1] === 'appointment' && !a[2] && Number(a[4]) === 8000, `appointment row wrong: ${a}`);
    orders.appt = { id: Number(a[0]), token: at };
    R.ok(`customer: appointment #${orders.appt.id} needs only a slot (no address stored), total 80.00`);
  }, { width: 430, height: 1400 });

  // ── customer 2: the booked slot is full; Delivery / Pickup are refused ───────────────────────────────────────────
  await A.withPage(browser, async (c) => {
    await c.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' });
    await addService(c, store.mani.id);
    await openBooking(c);
    const state = await c.evaluate((v) => { const o = [...document.querySelectorAll('#scheduled_at option')].find((x) => x.value === v); return o ? { disabled: o.disabled, text: o.textContent.trim() } : null; }, slotTaken);
    A.expect(state && state.disabled && /full/i.test(state.text), `the booked slot ${slotTaken} must show as full for the next customer (${JSON.stringify(state)})`);
    const csrf = await A.storefrontCsrf(c);
    const post = (fields) => c.evaluate(async (u, f, t) => {
      const fd = new FormData(); Object.entries(f).forEach(([k, v]) => fd.append(k, v));
      const r = await fetch(u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': t } });
      return { status: r.status, json: await r.json().catch(() => null) };
    }, `${app}/${SLUG}/book`, fields, csrf);
    const full = await post({ type: 'at_home', customer_name: 'x', phone_number: '+966500000662', address: '1 St', scheduled_at: slotTaken });
    A.expect(full.status === 422 && full.json && full.json.success === false, `a booking for the full slot must be refused (${JSON.stringify(full)})`);
    for (const wrong of ['delivery', 'pickup_slot', 'ship', 'in_store']) {
      const r = await post({ type: wrong, customer_name: 'x', phone_number: '+966500000662', address: '1 St' });
      A.expect(r.status === 422, `a services store must refuse the ${wrong} mode (${r.status})`);
    }
    A.expect(Number(A.sqlValue("SELECT COUNT(*) FROM orders WHERE store_id = ? AND customer_name = 'x'", [store.id])) === 0, 'no order may exist for the refused bookings');
    R.ok('second customer: the booked slot shows full and a direct booking for it is refused (422); delivery, pickup, ship and in-store are refused (422)');
  }, { width: 430, height: 1400 });

  // ── owner: the service board; customer: tracking ────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (ob) => {
    await A.login(ob, app, { email: owner.email, pass: owner.pass });
    const boardUrl = `${app}/services/board?store_id=${store.id}`;
    const card = (id) => ob.evaluate((i) => { const a = document.querySelector(`article[data-order-id="${i}"]`); return a ? { state: a.closest('[data-state]').dataset.state, text: a.innerText.replace(/\s+/g, ' ') } : null; }, id);
    await ob.goto(boardUrl, { waitUntil: 'domcontentloaded' });
    const h = await card(orders.home.id);
    const p = await card(orders.appt.id);
    A.expect(h && h.state === 'new' && /At home/.test(h.text), `the At home booking must sit in Received tagged At home (${JSON.stringify(h)})`);
    A.expect(p && p.state === 'new' && /Appointment/.test(p.text), `the appointment must sit in Received tagged Appointment (${JSON.stringify(p)})`);
    R.ok('owner: both bookings on the service board under Received, tagged At home / Appointment');

    await A.withPage(browser, async (cust) => {
      await cust.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' });
      const track = async () => (await cust.evaluate(async (t) => (await fetch(`/order/track/${t}`, { headers: { Accept: 'application/json' } })).json(), orders.home.token)).status;
      A.expect(await track() === 'new', 'tracking starts at new');
      const seen = ['new'];
      for (const next of ['packing', 'ready', 'collected', 'done']) {
        await ob.goto(boardUrl, { waitUntil: 'domcontentloaded' });
        await A.submitForm(ob, `form[action*="/services/board/${orders.home.id}/advance"]`);
        A.expect(A.sqlValue('SELECT status FROM orders WHERE id = ?', [orders.home.id]) === next, `Advance must move the booking to ${next}`);
        A.expect(await track() === next, `the customer's tracking must follow to ${next}`);
        seen.push(next);
      }
      R.ok(`status flow ${seen.join(' → ')}: every step on the board shows on the customer's tracking page`);
    }, { width: 430, height: 900 });
  });

  // ── cross-store IDOR ────────────────────────────────────────────────────────────────────────────────────────────
  const foreign = A.sql("SELECT i.id FROM items i JOIN stores s ON s.id = i.store_id WHERE s.store_type IN ('laundry','services') AND s.id <> ? AND s.user_id <> ? AND i.deleted_at IS NULL ORDER BY i.id LIMIT 1", [store.id, owner.id])[0];
  await A.withPage(browser, async (other) => {
    await A.login(other, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    A.expect(await A.status(other, `${app}/stores/${store.id}/services`) === 404, "another owner must get a 404 on this store's services");
    A.expect(await A.status(other, `${app}/stores/${store.id}/services/${store.cut.id}/edit`) === 404, "another owner must get a 404 on this store's service form");
    const board = await A.req(other, `${app}/services/board?store_id=${store.id}`, { redirect: 'follow' });
    A.expect(!board.text.includes(`${A.PREFIX}Client ${stamp}`), "another owner's service board must not list this store's bookings");
    const before = A.sqlValue('SELECT status FROM orders WHERE id = ?', [orders.appt.id]);
    const adv = await A.req(other, `${app}/services/board/${orders.appt.id}/advance?store_id=${store.id}`, { method: 'POST', form: {} });
    A.expect([403, 404].includes(adv.status) || adv.type === 'opaqueredirect', `another owner must not advance this store's booking (${adv.status})`);
    const post = await A.req(other, `${app}/stores/${store.id}/services`, { method: 'POST', form: { name: 'outsider', price: 1, pricing_unit: 'each' } });
    A.expect([403, 404].includes(post.status), `another owner must not add a service here (${post.status})`);
    A.expect(A.sqlValue('SELECT status FROM orders WHERE id = ?', [orders.appt.id]) === before, 'the booking must be untouched by the outsider');
    A.expect(A.sqlValue("SELECT 1 FROM items WHERE store_id = ? AND name = 'outsider'", [store.id]) === null, 'no service may be created by the outsider');
    R.ok("IDOR: another owner gets 404 on this store's services and service form, sees none of its bookings on the board, cannot advance one or add a service");
  });
  if (foreign) {
    await A.withPage(browser, async (c) => {
      await c.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' });
      const csrf = await A.storefrontCsrf(c);
      const r = await c.evaluate(async (u, t) => {
        const fd = new FormData(); fd.append('quantity', '1');
        return (await fetch(u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': t } })).status;
      }, `${app}/${SLUG}/book/${foreign[0]}/lines`, csrf);
      A.expect(r === 404, `another store's service must not go into this store's booking (${r})`);
      R.ok("IDOR: a service of another store cannot be added to this store's booking (404)");
    }, { width: 430, height: 900 });
  } else {
    R.skip('foreign service leg', 'no service of another owner in the database');
  }

  R.summary();
});
