// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * d-kiosk — P1-12 item 9 (d): the in-store self-order kiosk, owner → customer at the tablet → owner, plus IDOR.
 * TOUCHES PAYMENTS (Stub gateway) AND TENANCY — for coordinator review.
 *
 *  owner      creates a food store with a dish, switches the kiosk off and checks the kiosk URL is a 404 for the public,
 *             then on Kiosk settings enables it with a short idle time, a device-setup PIN and a staff-exit PIN (test PINs)
 *  tablet     opens {slug}/kiosk: the attract screen locks behind the setup PIN (a wrong PIN is refused, the right one
 *             unlocks), "Tap to order" opens the storefront in kiosk mode, the customer pays online through the Stub
 *             gateway; the attract screen returns to idle by itself after the idle time
 *  owner      the order is on the board with source=kiosk and is paid; an order from an ordinary browser that forges
 *             kiosk fields is NOT tagged kiosk; a kiosk session of this store does not tag an order at another store
 *  lock       the kiosk session cannot reach the dashboard (every dashboard URL goes to the login, the attract page links
 *             nowhere near it); a wrong staff-exit PIN leaves kiosk mode on (the next order is still source=kiosk), the
 *             right PIN leaves it (the next order is not)
 *  IDOR       another owner cannot open or change this store's kiosk settings; the kiosk PIN endpoints of a store whose
 *             kiosk is off are a 404
 *
 * Needs the Stub gateway (E2E_ARMED_URL, see lib/lane-a.js); without it the journey skips with the reason.
 *
 * Run: E2E_ARMED_URL=… npm run test:e2e -- d-kiosk
 */

const crypto = require('crypto');
const A = require('../../lib/lane-a');
const K = require('../../lib/order-kit-v2');

const SLUG = 'e2ea-kiosk';
const DISH = { name: `${A.PREFIX}Kiosk burger`, price: 9 };
const SETUP_PIN = '2468';
const EXIT_PIN = '1357';

module.exports = A.journey(async function dKiosk({ browser, log }) {
  const R = A.recorder('d-kiosk', log);
  const app = A.cfg.armed;
  if (!app) {
    const why = 'needs the Stub gateway: set E2E_ARMED_URL to an instance of this code with E2E_STUBS=1';
    R.skip('whole journey', why);
    R.summary();
    return { skip: why }; // run.js counts it as skipped, never as a pass
  }
  const owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical'] });
  A.seedLaneFixtures();

  // ── owner: store, menu, kiosk off → 404, kiosk on ───────────────────────────────────────────────────────────────
  const S = {};
  const saveKiosk = async (page, enabled) => {
    await page.goto(`${app}/kiosk-settings?store_id=${S.store.id}`, { waitUntil: 'domcontentloaded' });
    const form = 'form[action*="/kiosk-settings"]';
    await page.waitForSelector(`${form} input[name=kiosk_idle_seconds]`);
    await page.evaluate((sel, on, idle, setup, exit) => {
      const f = document.querySelector(sel);
      const sw = [...f.querySelectorAll('[name=kiosk_enabled]')].pop();
      if (sw && sw.type === 'checkbox' && sw.checked !== on) sw.click();
      const set = (n, v) => { const e = f.querySelector(`[name=${n}]`); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); };
      set('kiosk_idle_seconds', idle); set('kiosk_setup_pin', setup); set('kiosk_exit_pin', exit);
    }, form, enabled, '15', SETUP_PIN, EXIT_PIN);
    await A.submitForm(page, form);
  };
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    S.store = await A.ensureStore(page, app, { type: 'restaurant', title: 'e2eA Kiosk', slug: SLUG });
    const cat = await A.ensureMenuCategory(page, app, S.store.id, `${A.PREFIX}Kiosk menu`);
    S.item = await A.ensureMenuItem(page, app, S.store.id, { ...DISH, categoryId: cat });
    await saveKiosk(page, false);
  });
  A.expect(await A.publicStatus(browser, `${app}/${SLUG}/kiosk`) === 404, 'with the kiosk switched off the kiosk URL must be a 404 for the public');
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    await saveKiosk(page, true);
    const o = Object.fromEntries(['enabled', 'idle_seconds', 'exit_pin'].map((k) => [k, A.storeOption(S.store.id, `kiosk.${k}`)]));
    A.expect(o.enabled === 'true' && o.idle_seconds === '15' && o.exit_pin === EXIT_PIN, `kiosk settings must be saved (${JSON.stringify(o)})`);
  });
  R.ok(`store #${S.store.id} with a dish; kiosk off → public 404; enabled in Kiosk settings (idle 15 s, setup + exit PINs)`);

  // ── the tablet ──────────────────────────────────────────────────────────────────────────────────────────────────
  const order = {};
  const placeKioskOrder = async (c, store, item, { payOnline = true } = {}) => {
    const rt = await K.loadRuntime(c, `${app}/${store.slug}?kiosk=1`, { slug: store.slug });
    const res = await K.submitOrder(c, {
      rt, items: [{ id: item.id, quantity: 1 }],
      fields: { name: `${A.PREFIX}Kiosk guest`, 'ordering-type': 'takeaway', 'phone-number': '+966512345678', pay_via: payOnline ? 'pay_online' : 'pay_on_counter', client_uuid: crypto.randomUUID() },
    });
    A.expect(res.success, `kiosk order rejected: ${JSON.stringify(res)}`);
    const token = K.trackToken(res.track_url);
    const row = A.sql('SELECT id, source, is_paid FROM orders WHERE track_token = ?', [token])[0];
    const txn = (String(res.message || '').match(/\/payment\/(\d+)/) || [])[1];
    return { id: Number(row[0]), source: row[1], txn: txn ? Number(txn) : null, token };
  };
  const payStub = async (c, txn) => c.evaluate(async (id, csrf) => {
    const fd = new FormData(); fd.append('payment_method', 'stub'); fd.append('e2e_outcome', 'success');
    return (await fetch(`/payment/${id}/pay`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'text/html,application/json', 'X-CSRF-TOKEN': csrf }, body: fd })).status;
  }, txn, await A.storefrontCsrf(c));

  await A.withPage(browser, async (tab) => {
    const resp = await tab.goto(`${app}/${SLUG}/kiosk`, { waitUntil: 'domcontentloaded' });
    A.expect(resp.status() === 200, `the kiosk attract screen must load (${resp.status()})`);
    const html = await tab.content();
    A.expect(!/href="[^"]*(\/login|\/dashboard|\/admin|\/stores)/.test(html), 'the attract screen must not link to the login or the dashboard');
    // device setup lock
    await tab.waitForSelector('#pinModal.on', { timeout: 5000 });
    await tab.type('#pinInput', '0000');
    await tab.click('#pinSubmit'); await A.sleep(700);
    A.expect(await tab.$('#pinModal.on') && /Incorrect/i.test(await tab.$eval('#pinErr', (e) => e.textContent)), 'a wrong setup PIN must be refused and keep the lock');
    await tab.$eval('#pinInput', (e) => { e.value = ''; });
    await tab.type('#pinInput', SETUP_PIN);
    await tab.click('#pinSubmit'); await A.sleep(700);
    A.expect(!(await tab.$('#pinModal.on')), 'the right setup PIN must unlock the device');

    // the customer orders and pays through the Stub gateway
    order.kiosk = await placeKioskOrder(tab, S.store, S.item);
    A.expect(order.kiosk.txn, 'the kiosk order must come with a payment transaction');
    A.expect(await payStub(tab, order.kiosk.txn) < 400, 'the Stub gateway must accept the kiosk payment');

    // the lock: the kiosk browser session is a customer session — no dashboard door opens
    for (const p of ['/dashboard', '/kiosk-settings', `/orders/${S.store.id}`, `/stores/${S.store.id}/menu`, '/books']) {
      const s = await A.status(tab, app + p);
      A.expect(s === 'redirect' || s === 401 || s === 403 || s === 404, `the kiosk session must not open ${p} (got ${s})`);
      const final = await tab.goto(app + p, { waitUntil: 'domcontentloaded' });
      A.expect(/\/login|staff\/login/.test(tab.url()) || tab.url().includes(`/${SLUG}/kiosk`) || final.status() >= 400, `the kiosk session must end at the login, the kiosk start screen (KioskLock, P3-06) or an error on ${p} (${tab.url()})`);
    }
  }, { width: 1024, height: 768 });
  A.expect(Number(A.sqlValue('SELECT is_paid FROM orders WHERE id = ?', [order.kiosk.id])) === 1, 'the kiosk order must be paid after the Stub gateway succeeded');
  R.ok(`tablet: attract screen locked behind the setup PIN (wrong refused, right unlocks), order #${order.kiosk.id} placed and paid through the Stub gateway; no dashboard URL opens for the kiosk session`);

  // ── idle: the attract screen returns to rest by itself ──────────────────────────────────────────────────────────
  await A.withPage(browser, async (tab) => {
    await tab.goto(`${app}/${SLUG}/kiosk`, { waitUntil: 'domcontentloaded' });
    await tab.evaluate(() => { window.__e2eaMarker = 1; document.getElementById('pinModal').classList.remove('on'); });
    await A.sleep(17500);
    const reloaded = await tab.evaluate(() => window.__e2eaMarker === undefined);
    A.expect(reloaded, 'after the 15 s idle time the attract screen must reload itself back to idle');
  }, { width: 1024, height: 768 });
  R.ok('idle: the attract screen reloads itself after the 15 s idle time');
  // ── after "Tap to order": the storefront keeps the lock (idle return, PIN-gated staff exit) ─────────────────────
  await A.withPage(browser, async (tab) => {
    await tab.goto(`${app}/${SLUG}/kiosk`, { waitUntil: 'domcontentloaded' });
    await tab.evaluate((slug) => localStorage.setItem(`kiosk_setup_${slug}`, '1'), SLUG); // this device was set up earlier
    await tab.reload({ waitUntil: 'domcontentloaded' });
    await Promise.all([tab.waitForNavigation({ waitUntil: 'domcontentloaded' }), tab.click('#startBtn')]);
    A.expect(tab.url().includes('kiosk=1') && await tab.$('#kioskExit'), `"Tap to order" must open the storefront in kiosk mode with the staff-exit control (${tab.url()})`);
    await tab.evaluate(() => localStorage.setItem('e2ea_customer_data', 'x'));
    const t0 = Date.now();
    await tab.waitForFunction(() => location.pathname.endsWith('/kiosk'), { timeout: 30000 });
    A.expect(Date.now() - t0 > 12000, 'the idle return must wait for the idle time, not fire at once');
    const kept = await tab.evaluate((slug) => ({ customer: localStorage.getItem('e2ea_customer_data'), setup: localStorage.getItem(`kiosk_setup_${slug}`) }), SLUG);
    A.expect(kept.customer === null && kept.setup === '1', `the idle return must forget the customer's stored data but keep the device setup (${JSON.stringify(kept)})`);

    // staff exit from inside the storefront needs the kiosk PIN
    await Promise.all([tab.waitForNavigation({ waitUntil: 'domcontentloaded' }), tab.click('#startBtn')]);
    await tab.click('#kioskExit');
    await tab.waitForSelector('#kioskPin.on');
    await tab.type('#kioskPinInput', '0000');
    await tab.click('#kioskPinOk'); await A.sleep(800);
    A.expect(/Incorrect/i.test(await tab.$eval('#kioskPinErr', (e) => e.textContent)) && new URL(tab.url()).pathname === `/${SLUG}`, 'a wrong staff-exit PIN must be refused and keep the storefront in kiosk mode');
    await tab.$eval('#kioskPinInput', (e) => { e.value = ''; });
    await tab.type('#kioskPinInput', EXIT_PIN);
    await Promise.all([tab.waitForNavigation({ waitUntil: 'domcontentloaded' }), tab.click('#kioskPinOk')]);
    A.expect(new URL(tab.url()).pathname === `/${SLUG}` && !(await tab.$('#kioskExit')), 'the right staff-exit PIN must leave kiosk mode (no kiosk shell afterwards)');
  }, { width: 1024, height: 768 });
  R.ok('storefront after "Tap to order": staff-exit control present; idle timeout returns to the attract screen and forgets the customer\'s stored data (device setup kept); the exit control asks for the kiosk PIN (wrong refused, right leaves kiosk mode)');

  // ── owner: the result ───────────────────────────────────────────────────────────────────────────────────────────
  const sourceOf = (id) => A.sqlValue('SELECT source FROM orders WHERE id = ?', [id]);
  A.expect(sourceOf(order.kiosk.id) === 'kiosk', `the kiosk order must carry source=kiosk (got ${sourceOf(order.kiosk.id)})`);
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    await page.goto(`${app}/orders/${S.store.id}`, { waitUntil: 'domcontentloaded' });
    await A.sleep(700);
    const board = await page.evaluate(() => [...document.querySelectorAll('article.ui-order')].map((a) => a.innerText.replace(/\s+/g, ' ')));
    A.expect(board.some((t) => t.includes(`#${order.kiosk.id}`)), `the owner board must show the kiosk order (${board.length} cards)`);
    await page.goto(`${app}/order/${order.kiosk.id}`, { waitUntil: 'domcontentloaded' });
    A.expect(/kiosk/i.test(await A.pageText(page)), 'the order page must say the order came from the kiosk');
  });

  // forged kiosk fields on an ordinary browser, and a kiosk session of this store used at another store
  await A.withPage(browser, async (plain) => {
    const rt = await K.loadRuntime(plain, `${app}/${SLUG}`, { slug: SLUG });
    const res = await K.submitOrder(plain, { rt, items: [{ id: S.item.id, quantity: 1 }], fields: { name: `${A.PREFIX}Web guest`, 'ordering-type': 'takeaway', 'phone-number': '+966512345678', kiosk_mode: '1', source: 'kiosk', client_uuid: crypto.randomUUID() } });
    A.expect(res.success, `ordinary order rejected: ${JSON.stringify(res)}`);
    order.web = Number(A.sqlValue('SELECT id FROM orders WHERE track_token = ?', [K.trackToken(res.track_url)]));
    A.expect(sourceOf(order.web) !== 'kiosk', `a forged kiosk field must not tag an ordinary order (got ${sourceOf(order.web)})`);
  }, { width: 430, height: 900 });
  const other = A.sql("SELECT s.id, s.slug, i.id FROM stores s JOIN items i ON i.store_id = s.id AND i.deleted_at IS NULL WHERE s.slug = 'e2ea-books-a' ORDER BY i.id LIMIT 1")[0];
  if (other) {
    await A.withPage(browser, async (tab) => {
      await tab.goto(`${app}/${SLUG}/kiosk`, { waitUntil: 'domcontentloaded' });
      // P3-06 (KioskLock): a kiosk browser session reaches only its own store; another store's storefront sends it back to the kiosk start
      await tab.goto(`${app}/${other[1]}?kiosk=1`, { waitUntil: 'domcontentloaded' });
      A.expect(tab.url().includes(`/${SLUG}/kiosk`), `a kiosk session of this store must be sent back to its own kiosk start at another store (landed on ${tab.url()})`);
      const orders = Number(A.sqlValue("SELECT COUNT(*) FROM orders WHERE store_id = ? AND source = 'kiosk'", [other[0]]));
      A.expect(orders === 0, `no kiosk-tagged order at another store (${orders})`);
    }, { width: 430, height: 900 });
  } else {
    R.skip('kiosk session at another store', 'run b-accounting first (it creates e2ea-books-a)');
  }
  R.ok(`owner: order #${order.kiosk.id} is on the board, paid, source=kiosk; a forged kiosk field on an ordinary order (#${order.web}) and a kiosk session at another store are not tagged kiosk`);

  // ── staff exit PIN ──────────────────────────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (tab) => {
    await tab.goto(`${app}/${SLUG}/kiosk`, { waitUntil: 'domcontentloaded' });
    const pin = (p) => tab.evaluate(async (slug, v) => (await (await fetch(`/${slug}/kiosk/exit`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ pin: v }) })).json()).ok, SLUG, p);
    A.expect(await pin('0000') === false, 'a wrong staff-exit PIN must be refused');
    const still = await placeKioskOrder(tab, S.store, S.item, { payOnline: false });
    A.expect(sourceOf(still.id) === 'kiosk', 'after a wrong exit PIN the device must still be in kiosk mode');
    A.expect(await pin(EXIT_PIN) === true, 'the right staff-exit PIN must be accepted');
    const left = await placeKioskOrder(tab, S.store, S.item, { payOnline: false });
    A.expect(sourceOf(left.id) !== 'kiosk', `after the right exit PIN the device must leave kiosk mode (got ${sourceOf(left.id)})`);
  }, { width: 1024, height: 768 });
  R.ok('staff exit: a wrong PIN is refused and kiosk mode stays on; the right PIN leaves kiosk mode');

  // ── IDOR ────────────────────────────────────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (otherPage) => {
    await A.login(otherPage, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    const s = await A.status(otherPage, `${app}/kiosk-settings?store_id=${S.store.id}`);
    A.expect(s === 403 || s === 404, `another owner must not open this store's kiosk settings (${s})`);
    const r = await A.req(otherPage, `${app}/kiosk-settings?store_id=${S.store.id}`, { method: 'POST', form: { kiosk_enabled: '0', kiosk_exit_pin: '0000', kiosk_idle_seconds: '15' } });
    A.expect(r.status === 403 || r.status === 404, `another owner must not change this store's kiosk settings (${r.status})`);
  });
  A.expect(A.storeOption(S.store.id, 'kiosk.exit_pin') === EXIT_PIN && A.storeOption(S.store.id, 'kiosk.enabled') === 'true', 'the kiosk settings must be unchanged after the cross-store attempt');
  if (other) {
    const off = await A.publicStatus(browser, `${app}/${other[1]}/kiosk`);
    A.expect(off === 404, `the kiosk of a store that has it off must be a 404 (${off})`);
  }
  R.ok('IDOR: another owner gets 403/404 on this store\'s kiosk settings and cannot change them; the kiosk URL of a store with the kiosk off is a 404');

  R.summary();
});
