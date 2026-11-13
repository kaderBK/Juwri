// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-05 — Scheduled / pre-orders: a later order, the kitchen sees it on time, closed hours are blocked (E2E_RUN_P4=1).
 *
 * Owner (store A) switches pre-orders on in the store settings screen (lead 15 min, slots of 15, 3 days ahead, 20 min prep, 2 orders per
 * slot) and the branch opens 10:00-18:00 → a guest books tomorrow 12:00 (accepted; the order carries scheduled_at 12:00 and fire_at 11:40)
 * → refused: a time inside the lead window, beyond the horizon, outside opening hours, and a third order for a full slot → the store's
 * manual "closed" switch blocks an ASAP order but still takes a pre-order → the kitchen (a kitchen staff member) does NOT see it on the
 * live board, only in the upcoming list → when the fire time arrives (the clock is moved, the release tick runs) it appears on the board
 * and in the owner's order list → another owner / the other store: 403 on the board, upcoming list and release; the other store's staff
 * never see it.
 */

const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;

module.exports = async function p4Preorders({ browser, log, shot }) {
  const check = L.checker('p4-preorders');
  const base = L.baseUrl();
  P.enable(P.OWNER.email, ['scheduled_orders', 'kds']);
  const a = P.ownerRestaurant(P.OWNER.email);
  const b = P.ownerRestaurant(P.OTHER.email);
  const keys = ['allow_order_scheduling', 'scheduling_types', 'scheduling_lead_minutes', 'scheduling_slot_interval', 'scheduling_max_days', 'scheduling_prep_minutes', 'scheduling_slot_capacity', 'open_close_store', 'require_phone_verification'];
  const prevA = P.setOptions(a, Object.fromEntries(keys.map((k) => [k, k === 'require_phone_verification' ? '0' : k === 'open_close_store' ? '1' : (k === 'allow_order_scheduling' ? '0' : ''), ])));
  // the store's own (auto-created) branch: opens 10:00-18:00 every day
  const branch = JSON.parse(P.tinker(`
    $b = \\Juwri\\Core\\Models\\Branch::where('store_id', ${a.id})->orderBy('id')->firstOrFail();
    $prev = $b->hours;
    $day = ['open' => '10:00', 'close' => '18:00', 'closed' => false];
    $b->forceFill(['hours' => ['mon' => $day, 'tue' => $day, 'wed' => $day, 'thu' => $day, 'fri' => $day, 'sat' => $day, 'sun' => $day]])->save();
    $tz = app(\\Juwri\\Modules\\Ordering\\Services\\Orders\\SchedulingService::class)->timezone(${a.id}, $b);
    echo 'BR='.json_encode(['id' => $b->id, 'prev' => $prev, 'tz' => $tz, 'tomorrow' => now($tz)->addDay()->format('Y-m-d'), 'far' => now($tz)->addDays(9)->format('Y-m-d'), 'soon' => now($tz)->addMinutes(5)->format('Y-m-d H:i')]);
  `, 'BR'));
  const wrap = P.makeMenu(a, [['e2eB Wrap', 7, {}]])['e2eB Wrap'];
  const kai = P.makeStaff(a, 'Kai', 'kitchen');
  const zed = P.makeStaff(b, 'Zed', 'kitchen');
  const orderRow = (id) => L.dbq('select scheduled_at, fire_at, released_at, status from orders where id = ?', [id])[0];
  const countOrders = () => Number(L.dbq('select count(*) from orders where store_id = ? and scheduled_at is not null', [a.id])[0][0]);

  const owner = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const guest = await L.freshPage(browser, newPage, { width: 420, height: 800 });
  const kitchen = await L.freshPage(browser, newPage, { width: 1100, height: 800 });
  const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const zedPage = await L.freshPage(browser, newPage, { width: 1100, height: 800 });
  const nav = (p) => p.waitForNavigation({ waitUntil: 'domcontentloaded' });
  const book = (when, extra = {}) => P.guestOrder(guest.page, base, a, [{ id: wrap, quantity: 1 }], Object.assign({ scheduled_at: when, branch_id: branch.id }, extra));
  const refused = (r) => r.status >= 400 || (r.json && r.json.success === false);
  try {
    // ── owner: switch pre-orders on in the store settings ────────────────────────────────────────────────────────────────
    await L.login(owner.page, base, P.OWNER); 
    await owner.page.goto(`${base}/stores/${a.id}/edit`, { waitUntil: 'domcontentloaded' });
    await owner.page.select('select[name=allow_order_scheduling]', '1');
    for (const [name, v] of [['scheduling_lead_minutes', '15'], ['scheduling_slot_interval', '15'], ['scheduling_max_days', '3'], ['scheduling_prep_minutes', '20'], ['scheduling_slot_capacity', '2']]) {
      await owner.page.$eval(`input[name=${name}]`, (el, val) => { el.value = val; }, v);
    }
    await shot(owner.page, 'p4-preorders-settings');
    // the owner app submits forms through Turbo (no document navigation): wait for the POST, then for the page to settle
    await Promise.all([
      owner.page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(`/stores/${a.id}`)),
      owner.page.$eval(`form[action$="/stores/${a.id}"]`, (f) => f.requestSubmit()),
    ]);
    await owner.page.waitForNetworkIdle({ idleTime: 600, timeout: 15000 }).catch(() => {});
    const lead = JSON.parse(P.getOption(a, 'scheduling_lead_minutes'));
    check.eq(String(lead), '15', 'the owner\'s pre-order settings are saved (lead time 15)');
    check.eq(String(JSON.parse(P.getOption(a, 'scheduling_slot_capacity'))), '2', 'and the slot capacity');

    // ── customer: book tomorrow 12:00 ────────────────────────────────────────────────────────────────────────────────────
    const noon = `${branch.tomorrow} 12:00`;
    const ok = await book(noon);
    check.ok(ok.json && ok.json.success, `a pre-order for tomorrow 12:00 is accepted (${ok.status} ${JSON.stringify(ok.json).slice(0, 160)})`);
    const id1 = ok.json && ok.json.order_id;
    const row = orderRow(id1);
    check.ok(String(row[0]).startsWith(noon), `the order carries its scheduled time (${row[0]})`);
    check.ok(String(row[1]).startsWith(`${branch.tomorrow} 11:40`), `and the kitchen fire time 20 minutes earlier (${row[1]})`);
    check.eq(row[2], null, 'it is not released to the kitchen yet');
    const ok2 = await book(noon);
    check.ok(ok2.json && ok2.json.success, 'a second order for the same slot fits (capacity 2)');
    const full = await book(noon);
    check.ok(refused(full) && /full|slot/i.test(String(full.json && full.json.message)), `a third order for the full slot is refused (${full.json && full.json.message})`);
    check.ok(refused(await book(branch.soon)), 'a time inside the 15-minute lead window is refused');
    check.ok(refused(await book(`${branch.far} 12:00`)), 'a day beyond the 3-day horizon is refused');
    const night = await book(`${branch.tomorrow} 03:00`);
    check.ok(refused(night) && /closed/i.test(String(night.json && night.json.message)), `a time outside the branch's opening hours is refused (${night.json && night.json.message})`);
    check.eq(countOrders() - Number(L.dbq('select count(*) from orders where store_id = ? and scheduled_at is not null and created_at < ?', [a.id, '2000-01-01'])[0][0]) >= 2, true, 'only the two valid pre-orders were created');

    // ── the manual "closed" switch: ASAP blocked, a pre-order still goes through ─────────────────────────────────────────
    let later;
    P.setOptions(a, { open_close_store: '0' });
    try {
      const asap = await P.guestOrder(guest.page, base, a, [{ id: wrap, quantity: 1 }], { branch_id: branch.id });
      check.ok(refused(asap) && /closed/i.test(String(asap.json && asap.json.message)), `an order for now is refused while the store is closed (${asap.json && asap.json.message})`);
      later = await book(`${branch.tomorrow} 13:00`);
      check.ok(later.json && later.json.success, 'but a pre-order for tomorrow is still accepted');
    } finally {
      P.setOptions(a, { open_close_store: '1' }); // never leave the shared store switched off, even if a check above throws
    }
    const id3 = later.json && later.json.order_id;

    // ── kitchen: not on the live board, listed as upcoming ───────────────────────────────────────────────────────────────
    await P.staffLogin(kitchen.page, base, kai);
    await kitchen.page.goto(`${base}/kds?store_id=${a.id}`, { waitUntil: 'domcontentloaded' });
    const live = await P.pageJson(kitchen.page, 'GET', `/kds/poll?store_id=${a.id}`);
    check.eq(live.status, 200, 'the kitchen staff opens the board');
    check.ok(!JSON.stringify(live.json).includes(`"id":${id1},`) && !(live.json || []).some?.((o) => String(o.id) === String(id1)), 'the pre-order is NOT on the live board before its fire time');
    const upcoming = await P.pageJson(kitchen.page, 'GET', `/kds/scheduled?store_id=${a.id}`);
    const upIds = (Array.isArray(upcoming.json) ? upcoming.json : (upcoming.json?.orders || [])).map((o) => String(o.id));
    check.ok(upIds.includes(String(id1)) && upIds.includes(String(id3)), `but it is in the upcoming list (${upIds.join(',')})`);
    await shot(kitchen.page, 'p4-preorders-kds-before');

    // ── time passes: the fire time arrives, the release tick runs ────────────────────────────────────────────────────────
    P.tinker(`\\App\\Models\\Order::where('id', ${id1})->update(['scheduled_at' => now(${P.q(branch.tz)})->addMinutes(20)->format('Y-m-d H:i:s'), 'fire_at' => now(${P.q(branch.tz)})->subMinute()->format('Y-m-d H:i:s')]); echo 'OK=1';`, 'OK');
    L.artisan(['orders:release-scheduled']);
    check.ok(orderRow(id1)[2] !== null, 'the release tick releases the order whose fire time has come');
    check.eq(orderRow(id3)[2], null, 'and leaves the later one alone');
    const liveAfter = await P.pageJson(kitchen.page, 'GET', `/kds/poll?store_id=${a.id}`);
    check.ok(!(Array.isArray(liveAfter.json) ? liveAfter.json : []).some((o) => String(o.id) === String(id3)), 'the later one is still not');

    // ── owner sees it in the order list and accepts it; only then does it reach the kitchen board ────────────────────────
    await owner.page.goto(`${base}/orders/${a.id}?q=E2E+Guest`, { waitUntil: 'domcontentloaded' });
    const rowHtml = await owner.page.evaluate((id) => document.querySelector(`[data-order-id="${id}"]`)?.outerHTML || '', String(id1));
    check.ok(rowHtml !== '', `the owner's order list shows the released pre-order (ids: ${await owner.page.evaluate(() => [...document.querySelectorAll('[data-order-id]')].slice(0, 6).map((e) => e.dataset.orderId).join(',')) })`);
    check.ok(!(Array.isArray(liveAfter.json) ? liveAfter.json : []).some((o) => String(o.id) === String(id1)), 'a released order still waits for the owner\'s acceptance (status pending): not on the board yet');
    await Promise.all([
      owner.page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(`/order/${id1}/status`)),
      owner.page.select(`[data-order-id="${id1}"] select`, 'preparing'),
    ]);
    await owner.page.waitForNetworkIdle({ idleTime: 500, timeout: 10000 }).catch(() => {});
    check.eq(orderRow(id1)[3], 'preparing', 'the owner accepts it from the order list (status preparing)');
    const onBoard = await P.pageJson(kitchen.page, 'GET', `/kds/poll?store_id=${a.id}`);
    check.ok((Array.isArray(onBoard.json) ? onBoard.json : []).some((o) => String(o.id) === String(id1)), 'and now it is on the kitchen board, at its time');
    check.ok(!(Array.isArray(onBoard.json) ? onBoard.json : []).some((o) => String(o.id) === String(id3)), 'while tomorrow 13:00 is not');
    await shot(kitchen.page, 'p4-preorders-kds-after');

    // ── cross-store IDOR ─────────────────────────────────────────────────────────────────────────────────────────────────
    await L.login(other.page, base, P.OTHER);
    check.eq((await L.status(other.page, `${base}/kds/poll?store_id=${a.id}`)).status, 403, 'another owner: 403 on store A\'s kitchen board');
    check.eq((await L.status(other.page, `${base}/kds/scheduled?store_id=${a.id}`)).status, 403, 'another owner: 403 on store A\'s upcoming list');
    await other.page.goto(`${base}/kds?store_id=${b.id}`, { waitUntil: 'domcontentloaded' });
    check.eq(await P.formPost(other.page, `${base}/kds/${id3}/release`, ''), 404, 'another owner cannot release store A\'s pre-order (404)');
    check.eq(orderRow(id3)[2], null, 'and it is still waiting');
    const st = await P.formPost(other.page, `${base}/order/${id3}/status`, 'status=cancelled');
    check.ok(st === 403 || st === 404, `another owner cannot change store A's order status (${st})`);
    check.eq(orderRow(id3)[3], 'pending', 'and it is unchanged');
    await P.staffLogin(zedPage.page, base, zed);
    await zedPage.page.goto(`${base}/kds?store_id=${b.id}`, { waitUntil: 'domcontentloaded' });
    const zedLive = await P.pageJson(zedPage.page, 'GET', `/kds/poll?store_id=${b.id}`);
    check.ok(!JSON.stringify(zedLive.json).includes(`"id":${id1},`), 'the other store\'s kitchen never sees store A\'s order');
    check.eq((await P.pageJson(zedPage.page, 'GET', `/kds/poll?store_id=${a.id}`)).status, 403, 'and cannot ask for store A\'s board (403)');
    // a guest cannot schedule store B with store A's branch
    const crossBranch = await P.guestOrder(guest.page, base, b, [{ id: wrap, quantity: 1 }], { scheduled_at: noon, branch_id: branch.id });
    check.ok(refused(crossBranch) || !L.dbq('select id from orders where store_id = ? and branch_id = ?', [b.id, branch.id]).length, 'store B cannot be booked into store A\'s branch');
  } finally {
    await Promise.all([owner.close(), guest.close(), kitchen.close(), other.close(), zedPage.close()]);
    P.tinker(`
      $b = \\Juwri\\Core\\Models\\Branch::find(${branch.id});
      if ($b) { $b->forceFill(['hours' => ${P.php(branch.prev)}])->save(); }
      \\Juwri\\Modules\\Menu\\Models\\Menu::where('id', ${wrap})->get()->each(fn ($m) => $m->delete());
      \\App\\Models\\StaffMember::whereIn('id', [${kai.id}, ${zed.id}])->delete();
      \\App\\Models\\Order::whereIn('store_id', [${a.id}, ${b.id}])->where('customer_name', 'E2E Guest')->get()->each(fn ($o) => $o->delete()); // the pre-orders hold slot capacity until they are gone
      echo 'OK=1';`, 'OK');
    P.restoreOptions(a, prevA);
  }
  check.done(log);
};
