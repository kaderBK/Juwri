// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p314-refund-switches — P3-14 (D7): the owner flips each per-flow refund switch in the dashboard and the customer's
 * flow changes with it. Every switch starts OFF (the journey first saves it off, then on, then off again).
 *
 *  deposits   Bookings > Settings "Refund deposits when a customer cancels in time" (booking_deposit_refunds): the
 *             customer's booking details step says "Deposits are not refunded…" while off and "…After that the deposit
 *             is kept." while on; the headless API's store context says `refunds.deposits` false / true
 *  weighed    Grocery settings "Refund the weighed difference" (refunds_weighed_difference): the pay page of a weighed
 *             basket's card hold lists only the gateways that can hold the amount (the Stub) while off, and every
 *             switched-on gateway (Stub + Mollie, which cannot hold) while on; API `refunds.weighed_difference`
 *  returns    store editor "Refund returns" (refunds_returns): the API item page's returns row says "Exchange or store
 *             credit" while off and "Exchange, store credit or refund" while on; API `refunds.returns`
 *  exempt     the switches never change the late-payment / removed-line refunds (API-28 / API-31): covered by the
 *             feature tests (RefundSwitchesTest, BookingDepositGatewayRefundTest, GroceryCardHoldTest), not here
 *
 * Needs the armed instance (E2E_ARMED_URL: the Stub gateway is offered only with E2E_STUBS=1 on local/testing). Data:
 * the KitDemoSeeder stores (sidra-salon-demo, fresh-mart-demo, owner kit-demo-owner@demo.test); the booking service,
 * staff and hours, the Mollie switch, the Stub catalogue row and one weighed card-hold transaction are fixture rows made
 * through `php artisan tinker` / the E2E seeder on the database of the app under test (E2E_DB, e.g. juwri_c), all
 * named "e2eC-…". Storefront tokens are minted by the owner on the Developer > API page (real form).
 *
 * Run: E2E_ARMED_URL=http://127.0.0.1:8613 E2E_DB=juwri_c npm run test:e2e -- p314-refund-switches
 */

const B = require('../../lib/lane-b');
const C = require('../../lib/lane-c');

const OWNER = { email: process.env.E2E_KIT_OWNER_EMAIL || 'kit-demo-owner@demo.test', pass: process.env.E2E_KIT_OWNER_PASS || 'kit-demo-pass' };
const SALON = 'sidra-salon-demo';
const MART = 'fresh-mart-demo';

const dbEnv = () => (process.env.E2E_DB ? { DB_DATABASE: process.env.E2E_DB } : {});

/** Fixture rows (idempotent) through tinker on the app under test; returns the ids the journey needs. */
function fixtures() {
  B.artisan(['db:seed', '--class=Juwri\\Modules\\Storefront\\Database\\Seeders\\E2eStubGatewaySeeder', '--force'], dbEnv());
  const php = `
    $salon = \\Juwri\\Core\\Models\\Store::where('slug', '${SALON}')->firstOrFail();
    $mart = \\Juwri\\Core\\Models\\Store::where('slug', '${MART}')->firstOrFail();
    \\App\\Models\\StoreOption::updateStoreOption($salon->id, 'timezone', 'Asia/Riyadh');
    $svc = \\Juwri\\Modules\\Bookings\\Models\\BookableService::query()->where('store_id', $salon->id)->where('name', 'e2eC-Haircut')->first();
    if (! $svc) {
      $svc = \\Juwri\\Modules\\Bookings\\Models\\BookableService::query()->create(['store_id' => $salon->id, 'name' => 'e2eC-Haircut', 'duration_min' => 60,
        'price_minor' => 18000, 'currency' => 'SAR', 'deposit_type' => 'amount', 'deposit_value' => 5000, 'venue' => true, 'at_home' => false, 'active' => true]);
      $st = \\Juwri\\Modules\\Bookings\\Models\\BookingStaff::query()->create(['store_id' => $salon->id, 'name' => 'e2eC-Maha', 'role' => 'Stylist', 'active' => true, 'position' => 0]);
      $st->services()->syncWithPivotValues([$svc->id], ['store_id' => $salon->id, 'account_id' => (int) $salon->account_id]);
    }
    if (! \\Juwri\\Modules\\Bookings\\Models\\BookingHour::query()->where('store_id', $salon->id)->whereNull('staff_id')->exists()) {
      foreach (range(0, 6) as $d) {
        \\Juwri\\Modules\\Bookings\\Models\\BookingHour::query()->create(['store_id' => $salon->id, 'staff_id' => null, 'weekday' => $d, 'kind' => 'open', 'starts_local' => '08:00', 'ends_local' => '23:00']);
      }
    }
    \\Juwri\\Core\\Settings\\StoreSettings::setMany((int) $mart->id, ['restaurant_online_payment' => true, 'restaurant_mollie_install' => '1', 'restaurant_mollie_title' => 'e2eC-Mollie']);
    config(['settings.admin_allow_online_payment' => '1']);
    $order = \\App\\Models\\Order::create(['store_id' => $mart->id, 'track_token' => 'e2eC-'.\\Illuminate\\Support\\Str::random(10), 'is_paid' => 0,
      'status' => 'pending', 'type' => 'delivery', 'created_at' => now()]);
    $tx = new \\App\\Models\\Transaction;
    $tx->store_id = $mart->id; $tx->product_id = $order->id; $tx->amount = 42.5; $tx->currency_code = 'SAR';
    $tx->product_name = 'e2eC-weighed basket'; $tx->transaction_method = 'order'; $tx->details = ['capture' => 'manual'];
    $tx->save();
    $item = \\DB::table('items')->where('store_id', $mart->id)->whereNull('deleted_at')->whereNotNull('public_id')->value('public_id');
    echo 'OUT='.json_encode(['salon' => $salon->id, 'mart' => $mart->id, 'service' => $svc->id, 'pay' => route('payment.index', $tx, false), 'item' => $item]);
  `;
  const out = B.artisan(['tinker', '--execute', php], dbEnv());
  const m = out.match(/OUT=(\{.*\})/);
  if (!m) throw new Error(`p314 fixtures failed: ${out.slice(-600)}`);
  return JSON.parse(m[1]);
}

async function submitNav(page, fn, arg) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.evaluate(fn, arg),
  ]);
}

/** Owner: mint a menu:read storefront token for `storeId` on Developer > API; returns the raw token shown once. */
async function mintToken(page, app, storeId) {
  await page.goto(`${app}/api`, { waitUntil: 'domcontentloaded' });
  const sel = 'form[action$="/api/storefront-tokens"]';
  await page.waitForSelector(sel);
  await submitNav(page, ({ sel, storeId }) => {
    const f = document.querySelector(sel);
    f.querySelector('select[name=store_id]').value = String(storeId);
    f.querySelector('input[name=label]').value = 'e2eC-p314';
    f.requestSubmit();
  }, { sel, storeId });
  const raw = await page.$eval('.ui-api__secret', (el) => el.textContent.trim()).catch(() => '');
  if (!raw) throw new Error(`token for store ${storeId} not shown (${page.url()})`);
  return raw;
}

async function api(app, token, path) {
  const r = await fetch(`${app}/api/v1/storefront${path}`, { headers: { 'X-Storefront-Token': token, Accept: 'application/json', 'Accept-Language': 'en' } });
  const body = await r.json().catch(() => null);
  if (r.status !== 200) throw new Error(`GET ${path} → ${r.status} ${JSON.stringify(body).slice(0, 300)}`);
  return body;
}

module.exports = async function p314RefundSwitches({ browser, log }) {
  const R = C.recorder('p314-refund-switches', log);
  const app = C.cfg.armed;
  if (!app) {
    R.skip('whole journey', 'E2E_ARMED_URL is not set (the weighed leg needs the armed Stub gateway)');
    R.summary();
    return;
  }
  const fx = fixtures();
  R.ok(`fixtures: salon #${fx.salon} (deposit service), mart #${fx.mart} (Stub + Mollie, weighed card-hold transaction)`);

  const owner = await C.actorPage(browser);
  try {
    await C.login(owner.page, app, OWNER.email, OWNER.pass);
    const salonToken = await mintToken(owner.page, app, fx.salon);
    const martToken = await mintToken(owner.page, app, fx.mart);
    R.ok('owner minted two storefront tokens on Developer > API');

    // ---- owner switch helpers (the real dashboard forms) ------------------------------------------------------------
    const setDeposits = async (on) => {
      await owner.page.goto(`${app}/appointments/settings?store_id=${fx.salon}`, { waitUntil: 'domcontentloaded' });
      await submitNav(owner.page, (on) => {
        const cb = document.querySelector('input[type=checkbox][name=deposit_refunds]');
        if (cb.checked !== on) cb.click();
        cb.closest('form').requestSubmit();
      }, on);
    };
    const setWeighed = async (on) => {
      await owner.page.goto(`${app}/grocery/settings?store_id=${fx.mart}`, { waitUntil: 'domcontentloaded' });
      await submitNav(owner.page, (on) => {
        const f = document.querySelector('form[data-grocery-weighed]');
        f.querySelector('select[name=refunds_weighed_difference]').value = on ? '1' : '0';
        f.requestSubmit();
      }, on);
    };
    const setReturns = async (on) => {
      await owner.page.goto(`${app}/stores/${fx.mart}/edit`, { waitUntil: 'domcontentloaded' });
      const form = `form[action$="/stores/${fx.mart}"]`;
      await owner.page.waitForSelector(`${form} select[name=refunds_returns]`);
      await submitNav(owner.page, ({ form, on }) => {
        const f = document.querySelector(form);
        f.querySelector('input[name=returns_window_days]').value = '14';
        f.querySelector('select[name=refunds_returns]').value = on ? '1' : '0';
        f.requestSubmit();
      }, { form, on });
    };
    const setting = (storeId, key) => {
      const out = B.artisan(['tinker', '--execute', `echo 'VAL='.json_encode(\\Juwri\\Core\\Settings\\StoreSettings::bool(${Number(storeId)}, '${key}'));`], dbEnv());
      return (out.match(/VAL=(true|false)/) || [])[1] === 'true';
    };

    // ---- customer probes --------------------------------------------------------------------------------------------
    const depositCopy = async () => {
      const c = await C.actorPage(browser);
      try {
        const p = c.page;
        await p.goto(`${app}/en/${SALON}/appointments`, { waitUntil: 'domcontentloaded' });
        await p.$$eval('input[name="services[]"]', (els, id) => els.forEach((el) => { el.checked = String(el.value) === String(id) || el.closest('label')?.textContent.includes('e2eC-Haircut'); }), fx.service);
        await submitNav(p, () => { const el = document.querySelector('input[name="services[]"]:checked'); (el.form || el.closest('form')).requestSubmit(); }); // the book theme's inputs sit outside the form (form="bk-form")
        const days = await p.$$eval('a.day[href*="date="]', (as) => as.map((a) => a.getAttribute('href')));
        if (!days.length) throw new Error(`no bookable day on ${p.url()}`);
        let held = false;
        for (const d of days.slice(1)) { // tomorrow onwards: today's times may be past the lead time
          await p.goto(new URL(d, app).href, { waitUntil: 'domcontentloaded' });
          const slots = await p.$$('form[action*="/appointments/hold"] button.slot');
          if (!slots.length) continue;
          await Promise.all([p.waitForNavigation({ waitUntil: 'domcontentloaded' }), slots[Math.floor(Math.random() * slots.length)].click()]);
          held = p.url().includes('/appointments/details/');
          if (held) break;
        }
        if (!held) throw new Error(`could not hold a time (${p.url()})`);
        return await p.evaluate(() => document.body.innerText);
      } finally { await c.close(); }
    };
    const payGateways = async () => {
      const c = await C.actorPage(browser);
      try {
        await c.page.goto(app + fx.pay, { waitUntil: 'domcontentloaded' });
        return await c.page.$$eval('input[name="payment_method"], input[name="gateway"], input[type=radio]', (els) => els.map((e) => e.value).filter(Boolean));
      } finally { await c.close(); }
    };
    const refunds = async (token) => (await api(app, token, '/store')).context?.refunds || (await api(app, token, '/store')).refunds;

    // ---- deposits -----------------------------------------------------------------------------------------------------
    for (const on of [false, true, false]) {
      await setDeposits(on);
      if (Boolean(setting(fx.salon, 'booking_deposit_refunds')) !== on) R.fail(`deposit switch did not save ${on}`);
      const text = await depositCopy();
      const offCopy = /Deposits are not refunded\./.test(text);
      const onCopy = /After that the deposit is kept\./.test(text);
      if (on ? !(onCopy && !offCopy) : !(offCopy && !onCopy)) R.fail(`deposit copy wrong with the switch ${on ? 'on' : 'off'}: ${text.slice(0, 400)}`);
      const ctx = await refunds(salonToken);
      if (!ctx || ctx.deposits !== on) R.fail(`API refunds.deposits = ${JSON.stringify(ctx)} with the switch ${on}`);
      R.ok(`deposit refunds ${on ? 'ON' : 'OFF'}: details step says "${on ? 'After that the deposit is kept.' : 'Deposits are not refunded.'}", API refunds.deposits ${on}`);
    }

    // ---- weighed difference -----------------------------------------------------------------------------------------
    for (const on of [false, true, false]) {
      await setWeighed(on);
      if (Boolean(setting(fx.mart, 'refunds_weighed_difference')) !== on) R.fail(`weighed switch did not save ${on}`);
      const g = await payGateways();
      const ok = g.includes('stub') && (on ? g.includes('mollie') : !g.includes('mollie'));
      if (!ok) R.fail(`pay page gateways with the weighed switch ${on ? 'on' : 'off'}: ${JSON.stringify(g)}`);
      const ctx = await refunds(martToken);
      if (!ctx || ctx.weighed_difference !== on) R.fail(`API refunds.weighed_difference = ${JSON.stringify(ctx)} with the switch ${on}`);
      R.ok(`weighed difference ${on ? 'ON' : 'OFF'}: the card-hold pay page lists ${JSON.stringify(g)}, API refunds.weighed_difference ${on}`);
    }

    // ---- returns ------------------------------------------------------------------------------------------------------
    for (const on of [false, true, false]) {
      await setReturns(on);
      if (Boolean(setting(fx.mart, 'refunds_returns')) !== on) R.fail(`returns switch did not save ${on}`);
      const item = await api(app, martToken, `/items/${fx.item}`);
      const rows = item.item?.info_rows || item.info_rows || [];
      const sub = (rows.find((r) => r.icon === 'rotate-ccw') || {}).sub;
      const want = on ? 'Exchange, store credit or refund' : 'Exchange or store credit';
      if (sub !== want) R.fail(`returns row with the switch ${on ? 'on' : 'off'}: ${JSON.stringify(rows)}`);
      const ctx = await refunds(martToken);
      if (!ctx || ctx.returns !== on) R.fail(`API refunds.returns = ${JSON.stringify(ctx)} with the switch ${on}`);
      R.ok(`returns refunds ${on ? 'ON' : 'OFF'}: item returns row "${want}", API refunds.returns ${on}`);
    }
  } finally {
    await owner.close();
  }
  R.summary();
};
