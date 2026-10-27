// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P3-10 — services booking journey (opt-in with E2E_RUN_P310=1).
 *
 * owner sets up → customer books → customer reschedules and cancels → owner sees the states, plus IDOR:
 *   1  the owner of a `services` store opens Bookings: the setup checklist says "Set your hours" and the storefront
 *      booking page says "Bookings open soon" (D13: no hours, no bookings);
 *   2  the owner adds a service, a staff member doing it and the weekly hours (real forms) → "Your booking page is
 *      open";
 *   3  a customer (own browser context) picks the service, a day and the first free time (10-minute hold), fills the
 *      details and confirms → the booking page says Confirmed, with Add to calendar (a real .ics);
 *   4  the customer reschedules to a later free time (same price, nothing to pay) and then cancels;
 *   5  the owner's list shows the booking as Cancelled; another owner gets 403 on this store's Bookings page and
 *      on its booking action, and the booking page under the other store's slug is 404.
 *
 * Data: the store is created for the ThemePackDemoSeeder owner through `php artisan tinker` (a fixture row named
 * "e2eB-…", never a dump), on the database of the app under test; nothing is sent (no reminder channel is ticked).
 * Needs the Bookings module (P3-10) in the app under test: skips with a reason when its tables are missing.
 */

const L = require('../lib/lane-b');
const { newPage } = require('../lib/harness');

const OWNER = { email: process.env.E2E_OWNER_EMAIL || 'themepack-owner@demo.test', pass: process.env.E2E_OWNER_PASS || 'password' };
const OTHER = { email: process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com', pass: process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass' };

function makeStore() {
  const slug = `e2eb-salon-${Date.now().toString(36)}`;
  const php = `
    $owner = \\Juwri\\Core\\Models\\User::where('email', '${OWNER.email}')->firstOrFail();
    $s = \\Juwri\\Core\\Models\\Store::create(['user_id' => $owner->id, 'slug' => '${slug}', 'title' => 'e2eB-Salon', 'sub_title' => 'e2e', 'description' => 'e2e',
      'color' => '#1d4ed8', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 Olaya St', 'phone' => '1', 'store_type' => 'services']);
    \\App\\Models\\StoreOption::updateStoreOption($s->id, 'timezone', 'Asia/Riyadh');
    \\App\\Models\\StoreOption::updateStoreOption($s->id, 'currency_code', 'SAR');
    echo 'ID='.$s->id;
  `;
  const out = L.artisan(['tinker', '--execute', php]);
  const m = out.match(/ID=(\d+)/);
  if (!m) throw new Error(`could not create the services store: ${out.slice(-400)}`);
  return { id: m[1], slug };
}

/** Submit the form that holds the element `sel` (a real submit: the browser posts the form). */
async function submit(page, sel) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.$eval(sel, (el) => (el.tagName === 'FORM' ? el : el.closest('form')).requestSubmit()),
  ]);
}

module.exports = async function bookingsServices({ browser, log, shot }) {
  try {
    L.dbq('select count(*) from booking_services');
  } catch (_e) {
    return L.skip(log, 'bookings-services', 'the app under test has no Bookings module (P3-10) tables');
  }
  const check = L.checker('bookings-services');
  const base = L.baseUrl();
  const store = makeStore();
  const q = `store_id=${store.id}`;
  const owner = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const customer = await L.freshPage(browser, newPage, { width: 390, height: 844 });
  try {
    // ---- 1 nothing set up yet -----------------------------------------------------------------------------------
    await L.login(owner.page, base, OWNER);
    await owner.page.goto(`${base}/appointments?${q}`, { waitUntil: 'domcontentloaded' });
    check.ok(await owner.page.$('[data-testid="booking-checklist"]') !== null, 'owner sees the setup checklist');
    check.ok((await owner.page.content()).includes('Set your hours'), 'checklist asks to set the hours');
    await customer.page.goto(`${base}/en/${store.slug}/appointments`, { waitUntil: 'domcontentloaded' });
    check.ok(await customer.page.$('[data-testid="bookings-open-soon"]') !== null, 'storefront says "Bookings open soon" before hours exist');

    // ---- 2 owner sets up services, staff, hours ------------------------------------------------------------------
    await owner.page.goto(`${base}/appointments/services?${q}`, { waitUntil: 'domcontentloaded' });
    await owner.page.type('#sv-new-name', 'e2eB Haircut');
    await owner.page.type('#sv-new-price', '120');
    await owner.page.$eval('#sv-new-dur', (el) => { el.value = '60'; });
    await submit(owner.page, '#sv-new-name');
    check.eq(Number(L.dbq('select count(*) from booking_services where store_id = ?', [store.id])[0][0]), 1, 'service saved');

    await owner.page.goto(`${base}/appointments/staff?${q}`, { waitUntil: 'domcontentloaded' });
    await owner.page.type('#st-name-new', 'Maha');
    await submit(owner.page, '#st-name-new');
    check.eq(Number(L.dbq('select count(*) from booking_staff_services where store_id = ?', [store.id])[0][0]), 1, 'staff saved with the service ticked');

    await owner.page.goto(`${base}/appointments/hours?${q}`, { waitUntil: 'domcontentloaded' });
    await owner.page.$$eval('input[name$="[open]"]', (els) => els.forEach((el) => { el.checked = true; }));
    await owner.page.$$eval('input[name$="[starts]"]', (els) => els.forEach((el) => { el.value = '09:00'; }));
    await owner.page.$$eval('input[name$="[ends]"]', (els) => els.forEach((el) => { el.value = '21:00'; }));
    await submit(owner.page, 'input[name$="[starts]"]');
    check.eq(Number(L.dbq("select count(*) from booking_hours where store_id = ? and staff_id is null and kind = 'open'", [store.id])[0][0]), 7, 'weekly hours saved');
    await owner.page.goto(`${base}/appointments?${q}`, { waitUntil: 'domcontentloaded' });
    check.ok((await owner.page.content()).includes('Your booking page is open.'), 'owner: booking page is open');
    await shot(owner.page, 'p310-owner-open');

    // ---- 3 customer books ---------------------------------------------------------------------------------------
    await customer.page.goto(`${base}/en/${store.slug}/appointments`, { waitUntil: 'domcontentloaded' });
    await customer.page.click('input[name="services[]"]');
    await submit(customer.page, 'input[name="services[]"]');
    const day = await customer.page.$$eval('a.day[href*="date="]', (as) => as.map((a) => a.getAttribute('href')).pop());
    await customer.page.goto(new URL(day, base).href, { waitUntil: 'domcontentloaded' });
    const slots = await customer.page.$$('form[action*="/appointments/hold"] button.slot');
    check.ok(slots.length > 2, `free times listed (${slots.length})`);
    await Promise.all([customer.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), slots[0].click()]);
    check.ok(customer.page.url().includes('/appointments/details/'), 'the chosen time is held and the details step opens');
    await customer.page.type('#bk-name', 'e2eB Noura');
    await customer.page.type('#bk-phone', '+966500000099');
    await submit(customer.page, '#bk-name');
    const manage = customer.page.url();
    check.ok(/\/appointments\/m\/[0-9a-f-]{36}\.[0-9a-f]{40}$/.test(new URL(manage).pathname), 'customer lands on the signed booking page');
    check.eq(await customer.page.$eval('[data-testid="booking-status"]', (el) => el.dataset.status), 'confirmed', 'booking is confirmed');
    const ics = await customer.page.evaluate(async (u) => { const r = await fetch(u); return { s: r.status, t: r.headers.get('content-type'), b: await r.text() }; }, `${manage}/calendar`);
    check.ok(ics.s === 200 && /text\/calendar/.test(ics.t) && ics.b.includes('BEGIN:VEVENT'), 'Add to calendar downloads an .ics event');
    await shot(customer.page, 'p310-customer-booked');

    // ---- 4 reschedule then cancel --------------------------------------------------------------------------------
    await customer.page.goto(`${manage}/reschedule`, { waitUntil: 'domcontentloaded' });
    const later = await customer.page.$$('form[action*="/reschedule"] button.slot');
    check.ok(later.length > 1, 'reschedule lists free times');
    await Promise.all([customer.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), later[later.length - 1].click()]);
    check.ok((await customer.page.content()).includes('Same price, nothing to pay.'), 'rescheduled at the same price');
    await submit(customer.page, 'form[action$="/cancel"]');
    check.eq(await customer.page.$eval('[data-testid="booking-status"]', (el) => el.dataset.status), 'cancelled', 'booking is cancelled');

    // ---- 5 owner sees it; tenancy -------------------------------------------------------------------------------
    await owner.page.goto(`${base}/appointments?${q}`, { waitUntil: 'domcontentloaded' });
    const html = await owner.page.content();
    check.ok(html.includes('e2eB Noura') && html.includes('Cancelled'), 'owner sees the cancelled booking');
    const bookingId = L.dbq('select id from bookings where store_id = ? order by id desc limit 1', [store.id])[0][0];
    const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
    try {
      await L.login(other.page, base, OTHER);
      const list = await L.status(other.page, `${base}/appointments?${q}`);
      check.eq(list.status, 403, 'another owner cannot open this store\'s bookings (403)');
      const act = await other.page.evaluate(async (u) => {
        const t = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': t, 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'do=complete' });
        return r.status;
      }, `${base}/appointments/${bookingId}/action`);
      check.ok([403, 404, 419].includes(act), `another owner cannot act on this booking (${act})`);
    } finally { await other.close(); }
    const foreign = await L.status(customer.page, manage.replace(`/${store.slug}/`, '/themepack-demo/'));
    check.eq(foreign.status, 404, 'the manage link under another store\'s slug is 404');
    check.eq(L.dbq('select status from bookings where id = ?', [bookingId])[0][0], 'cancelled', 'nothing changed the booking after the cancel');
  } finally {
    await owner.close();
    await customer.close();
  }
  check.done(log);
};
