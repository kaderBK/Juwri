// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P3-11 — the `book` theme on the Bookings engine (opt-in with E2E_RUN_P311=1).
 *
 *   services  Sidra Salon (BookDemoSeeder, template `book`): tick a service → the bar shows the quoted total (VAT included) → Choose a
 *             time → pick the first free time → held → details → confirm → the signed booking page says Confirmed with an .ics →
 *             cancel (the confirm dialog). The total the customer saw is the total the booking row holds. A service with a deposit
 *             goes to the pay page, which says online payment is not offered when the store has no gateway.
 *   laundry   a laundry store on `book` (a fixture store named "e2eB-…"): pieces and kilos → one hold for pickup and delivery → confirm
 *             → the order's total is the quoted total and its pickup slot is the held one; the track page opens.
 *
 * Needs E2E_BASE_URL on an instance of this code with the Bookings + Laundry tables, the seeded salon, a second `php artisan serve` /
 * `php -S ... router.php` with E2E_STUBS=1 on its own database, and every storefront language active. Nothing is sent.
 */

const L = require('../lib/lane-b');
const { newPage } = require('../lib/harness');

const OWNER = { email: process.env.E2E_OWNER_EMAIL || 'themepack-owner@demo.test' };
const SALON = 'sidra-salon-demo';

const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const money = (text) => Number((String(text).match(/(\d[\d,]*\.\d{2})/) || [])[1]?.replace(/,/g, ''));

/** Choose a free time a few slots in: the very first one can pass its lead time while the journey runs. */
async function pickTime(page) {
  const free = await page.$$('input[name=starts_at]:not(:disabled)');
  const label = await free[Math.min(6, free.length - 1)].evaluateHandle((el) => el.nextElementSibling);
  await label.asElement().click();
}

async function servicesLeg(browser, check, base, shot) {
  const svc = L.dbq(
    "select s.public_id, s.price_minor from booking_services s join stores r on r.id = s.store_id where r.slug = ? and s.name = 'Eyebrow threading'", [SALON]);
  if (!svc.length) throw new Error('the seeded salon has no "Eyebrow threading" service (run BookDemoSeeder)');
  const [serviceId] = svc[0];
  const c = await L.freshPage(browser, newPage, { width: 390, height: 844 });
  const { page } = c;
  const nav = () => page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  try {
    await page.goto(`${base}/en/${SALON}`, { waitUntil: 'networkidle0' });
    check.ok(await page.$('[data-theme-slug="book"], body[data-theme-slug="book"], [data-book-form]') !== null, 'the salon renders the book theme');
    await page.click('[data-ckc="reject"]').catch(() => {});
    const bar = await page.$eval('[data-book-bar]', (el) => el.hidden);
    check.ok(bar === true, 'the booking bar is hidden until a service is ticked');
    await page.click(`input[data-book-service][value="${serviceId}"]`);
    await wait(900);
    const barText = await page.$eval('[data-book-bar]', (el) => el.innerText);
    const seen = money(barText);
    check.ok(seen > 0, `the bar quotes a total (${seen})`);
    check.eq(await page.$eval('.c-bkbar__pill', (el) => el.disabled), false, 'the Choose-a-time button is enabled with a service ticked');
    await shot(page, 'p311-book-home');
    await Promise.all([nav(), page.click('[data-book-bar] .c-bkbar__pill')]);
    check.ok(page.url().includes('/appointments/times'), 'Choose a time opens the times page');

    await pickTime(page);
    await wait(400);
    await Promise.all([nav(), page.evaluate(() => [...document.querySelectorAll('button')].find((b) => /^Continue/.test(b.innerText.trim()) && b.offsetParent).click())]);
    check.ok(page.url().includes('/appointments/details/'), 'the chosen time is held and the details step opens');
    const summary = await page.evaluate(() => document.querySelector('main').innerText);
    check.ok(/VAT/.test(summary), 'the summary shows the VAT line');
    check.eq(money((summary.match(/Total[^\n]*\n?[^\n]*/) || [''])[0]), seen, 'the total on the details page is the total on the home bar');
    await page.type('#f-name', 'e2eB Noura');
    await page.type('#f-phone', '+966500000077');
    await Promise.all([nav(), page.click('button[type=submit]')]);
    const manage = page.url();
    if (!/\/appointments\/m\//.test(manage)) check.ok(false, `booking did not go through: ${(await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ').slice(0, 300)}`);
    check.ok(/\/appointments\/m\/[0-9a-f-]{36}\.[0-9a-f]{40}$/.test(new URL(manage).pathname), 'the customer lands on the signed booking page');
    check.eq(await page.$eval('[data-testid="booking-status"], [data-status]', (el) => el.dataset.status), 'confirmed', 'the booking is confirmed');
    const id = manage.match(/\/m\/([0-9a-f-]{36})\./)[1];
    const row = L.dbq('select total_minor, status from bookings where public_id = ?', [id])[0];
    check.eq(Number(row[0]) / 100, seen, 'the stored booking total equals the quoted total');
    const ics = await page.evaluate(async (u) => { const r = await fetch(u); return { s: r.status, t: r.headers.get('content-type'), b: await r.text() }; }, `${manage}/calendar`);
    check.ok(ics.s === 200 && /text\/calendar/.test(ics.t) && ics.b.includes('BEGIN:VEVENT'), 'Add to calendar downloads an .ics event');
    await shot(page, 'p311-book-confirmed');

    page.on('dialog', (d) => d.accept());
    await Promise.all([nav(), page.evaluate(() => document.querySelector('[data-book-confirm]').closest('form').requestSubmit())]);
    check.eq(await page.$eval('[data-testid="booking-status"], [data-status]', (el) => el.dataset.status), 'cancelled', 'the booking is cancelled');

    // a service with a deposit: the pay page, no gateway on this store
    const dep = L.dbq("select s.public_id from booking_services s join stores r on r.id = s.store_id where r.slug = ? and s.name = 'Haircut & blow-dry'", [SALON])[0][0];
    await page.goto(`${base}/en/${SALON}`, { waitUntil: 'networkidle0' });
    await page.click(`input[data-book-service][value="${dep}"]`);
    await wait(900);
    await Promise.all([nav(), page.click('[data-book-bar] .c-bkbar__pill')]);
    await pickTime(page);
    await wait(400);
    await Promise.all([nav(), page.evaluate(() => [...document.querySelectorAll('button')].find((b) => /^Continue/.test(b.innerText.trim()) && b.offsetParent).click())]);
    await page.type('#f-name', 'e2eB Noura');
    await page.type('#f-phone', '+966500000077');
    await Promise.all([nav(), page.click('button[type=submit]')]);
    if (!page.url().endsWith('/pay')) check.ok(false, `deposit booking did not reach the pay page: ${(await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ').slice(0, 300)}`);
    check.ok(page.url().endsWith('/pay'), 'a deposit booking goes to the pay page');
    const payText = await page.evaluate(() => document.querySelector('main').innerText);
    check.ok(/Online payment is not available/.test(payText), 'with no gateway the pay page says so and offers nothing to pay');
    check.eq(await page.$eval('form button[type=submit]', (el) => el.disabled), true, 'the pay button is off');
  } finally {
    await c.close();
  }
}


function makeLaundry() {
  const slug = `e2eb-laundry-${Date.now().toString(36)}`;
  const php = `
    $owner = \\Juwri\\Core\\Models\\User::where('email', '${OWNER.email}')->firstOrFail();
    $s = \\Juwri\\Core\\Models\\Store::create(['user_id' => $owner->id, 'slug' => '${slug}', 'title' => 'e2eB-Laundry', 'sub_title' => 'e2e', 'description' => 'e2e',
      'color' => '#0f766e', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 Olaya St', 'phone' => '1', 'store_type' => 'laundry']);
    foreach (['store_template' => 'book', 'allow_order_scheduling' => '1', 'scheduling_slot_interval' => 30, 'scheduling_lead_minutes' => 30,
      'scheduling_max_days' => 7, 'scheduling_slot_capacity' => 2, 'timezone' => 'Asia/Riyadh', 'currency_code' => 'SAR',
      'restaurant_taxx_charge' => '15', 'restaurant_tax_type' => 'percentage', 'restaurant_tax_label' => 'VAT'] as $k => $v) \\App\\Models\\StoreOption::updateStoreOption($s->id, $k, $v);
    $c = \\App\\Models\\MenuCategory::create(['store_id' => $s->id, 'name' => 'Laundry', 'active' => '1']);
    \\Juwri\\Modules\\Menu\\Models\\Menu::create(['store_id' => $s->id, 'category_id' => $c->id, 'name' => 'Shirt iron', 'price' => 3.5, 'pricing_unit' => 'each', 'active' => 1]);
    \\Juwri\\Modules\\Menu\\Models\\Menu::create(['store_id' => $s->id, 'category_id' => $c->id, 'name' => 'Wash and fold', 'price' => 5, 'pricing_unit' => 'kg', 'active' => 1]);
    echo 'ID='.$s->id;
  `;
  const out = L.artisan(['tinker', '--execute', php]);
  const m = out.match(/ID=(\d+)/);
  if (!m) throw new Error(`could not create the laundry store: ${out.slice(-400)}`);
  return { id: m[1], slug };
}

async function laundryLeg(browser, check, base, shot) {
  const store = makeLaundry();
  const c = await L.freshPage(browser, newPage, { width: 390, height: 844 });
  const { page } = c;
  const nav = () => page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  const step = async (qtyBox, dir, times) => { for (let i = 0; i < times; i++) await (await qtyBox.$(`[data-book-step="${dir}"]`)).click(); };
  try {
    await page.goto(`${base}/en/${store.slug}`, { waitUntil: 'networkidle0' });
    check.ok(await page.$('[data-book-laundry]') !== null, 'the laundry renders the book theme (pieces and kilos)');
    await page.click('[data-ckc="reject"]').catch(() => {});
    const qtys = await page.$$('.c-qty');
    await step(qtys[0], '1', 3); // 3 shirts
    await step(qtys[1], '0.5', 5); // 2.5 kg
    check.eq(await page.$$eval('input[type=number]', (x) => x.map((e) => e.value).join(',')), '3,2.5', 'three pieces and 2.5 kg');
    await page.click('input[name=type][value=delivery]+*, input[name=type][value=delivery]');
    await Promise.all([nav(), page.click('[data-book-bar] button')]);
    check.ok(page.url().includes('/book/schedule'), 'the schedule page opens');

    // ONE hold covers the pickup and the delivery: pickup first (the delivery times follow it), then the delivery time
    // a time a few hours ahead: the first slot can pass (the lead time) while the test runs
    const pickup = await page.$$eval('input[type=radio][name=pickup]:not(:disabled)', (els) => els[Math.min(8, els.length - 1)].value);
    await Promise.all([nav(), page.evaluate((v) => document.querySelector(`input[type=radio][name=pickup][value="${v}"]`).click(), pickup)]);
    check.eq(await page.$eval('input[type=radio][name=pickup]:checked', (el) => el.value), pickup, 'the chosen pickup time stays selected');
    check.ok(await page.$eval(`input[type=radio][name=pickup][value="${pickup}"]`, (el) => !el.disabled), 'and is not shown as taken');
    await page.click('input[type=radio][name=return]:not(:disabled)+*');
    await wait(300);
    await Promise.all([nav(), page.evaluate(() => [...document.querySelectorAll('button')].find((b) => /^Continue/.test(b.innerText.trim()) && b.offsetParent).click())]);
    check.ok(page.url().includes('/book/confirm/'), 'one hold, and the confirm page opens');
    const holdId = page.url().split('/').pop();
    const holds = L.dbq('select count(*) from laundry_slot_holds where public_id = ?', [holdId]);
    check.eq(Number(holds[0][0]), 1, 'one hold row covers both slots');
    const text = await page.evaluate(() => document.querySelector('main').innerText);
    const quoted = money((text.match(/Total[^\n]*\n?[^\n]*/) || [''])[0]);
    check.eq(quoted, 26.45, 'the quote is (3 x 3.50 + 2.5 kg x 5.00) + 15% VAT');
    await page.type('#f-customer_name', 'e2eB Noura');
    await page.type('#f-phone_number', '+966500000088');
    await page.type('#f-address', '12 Olaya St, Riyadh');
    await Promise.all([nav(), page.click('form[action$="/book"] button[type=submit]')]);
    check.ok(/\/order\/|track/.test(page.url()), `the order is placed and the customer lands on the tracking page (${new URL(page.url()).pathname})`);
    const order = L.dbq('select grand_total_minor, type, scheduled_at from orders where store_id = ? order by id desc limit 1', [store.id])[0];
    check.eq(Number(order[0]) / 100, quoted, 'the order total equals the quoted total');
    check.eq(order[1], 'delivery', 'the order is a delivery order');
    check.ok(order[2] !== null, 'the order carries the held pickup slot');
    check.eq(L.dbq('select status from laundry_slot_holds where public_id = ?', [holdId])[0][0], 'used', 'the hold is used up by the order');
    await shot(page, 'p311-laundry-track');
  } finally {
    await c.close();
  }
}

module.exports = async function bookTheme({ browser, log, shot }) {
  try {
    L.dbq('select count(*) from booking_services');
    L.dbq('select count(*) from laundry_slot_holds');
  } catch (_e) {
    return L.skip(log, 'book-theme', 'the app under test has no Bookings / Laundry hold tables');
  }
  L.clearSiteCache(); // the booking and hold routes are throttled per client: a repeat run starts clean
  const check = L.checker('book-theme');
  const base = L.baseUrl();
  await servicesLeg(browser, check, base, shot);
  await laundryLeg(browser, check, base, shot);
  check.done(log);
};
