// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-02 — HR: rota, staff clock in / out, worked hours, per-store isolation (opt-in with E2E_RUN_P4=1).
 *
 * Owner (store A) adds a shift for Sam and publishes the week → Sam signs in to the staff app, sees exactly his published shift,
 * clocks in (a second clock-in is refused), clocks out → the owner's payroll page and CSV show Sam's worked hours → the other
 * owner and the other store's staff get nothing of store A: 403 / 404 on the rota, payroll, delete-shift, employee-file and
 * foreign-staff shift creation, and Zed's own clock entry lands in store B only.
 *
 * Both builds (rota / HRM live in the Community core): run on the Enterprise tree and on the Community mirror.
 */

const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;
const pad = (n) => String(n).padStart(2, '0');
const local = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

module.exports = async function p4Hr({ browser, log, shot }) {
  const check = L.checker('p4-hr');
  const base = L.baseUrl();
  P.enable(P.OWNER.email, ['staff_rota', 'hrm_suite', 'payroll_suite', 'team_comms']);
  P.enable(P.OTHER.email, ['staff_rota', 'hrm_suite', 'payroll_suite', 'team_comms']);
  const a = P.makeStore(P.OWNER.email, 'e2eB-HR A');
  const b = P.makeStore(P.OTHER.email, 'e2eB-HR B');
  const sam = P.makeStaff(a, 'Sam', 'cashier');
  const zed = P.makeStaff(b, 'Zed', 'cashier');
  // store B already has a published shift for Zed (the other owner's data)
  const zedShift = P.tinker(`
    $n = now();
    $s = \\Juwri\\Modules\\Hr\\Models\\StaffShift::create(['store_id' => ${b.id}, 'staff_id' => ${zed.id}, 'role' => 'cashier', 'starts_at' => $n->copy()->setTime(10, 0), 'ends_at' => $n->copy()->setTime(18, 0), 'published' => true]);
    echo 'ID='.$s->id;
  `, 'ID');
  const rows = (sql, params) => L.dbq(sql, params);

  const owner = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const worker = await L.freshPage(browser, newPage, { width: 420, height: 800 });
  const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const zedPage = await L.freshPage(browser, newPage, { width: 420, height: 800 });
  const nav = (p) => p.waitForNavigation({ waitUntil: 'domcontentloaded' });
  try {
    // ── owner: add a shift for Sam, publish the week ─────────────────────────────────────────────────────────────────────
    await L.login(owner.page, base, P.OWNER);
    await owner.page.goto(`${base}/rota?store_id=${a.id}`, { waitUntil: 'domcontentloaded' });
    const start = new Date(); start.setHours(9, 0, 0, 0);
    const end = new Date(); end.setHours(17, 0, 0, 0);
    await owner.page.select('form:has(input[name=starts_at]) select[name=staff_id]', sam.id);
    await owner.page.$eval('form:has(select[name=staff_id]) input[name=starts_at]', (el, v) => { el.value = v; }, local(start));
    await owner.page.$eval('form:has(select[name=staff_id]) input[name=ends_at]', (el, v) => { el.value = v; }, local(end));
    await owner.page.type('form:has(select[name=staff_id]) input[name=role]', 'Till');
    await Promise.all([nav(owner.page), owner.page.click('form:has(select[name=staff_id]) button[type=submit]')]);
    const savedText = await owner.page.evaluate(() => document.body.innerText);
    check.ok(/Shift saved/.test(savedText), `the owner sees "Shift saved." (${new URL(owner.page.url()).pathname} ${savedText.replace(/\s+/g, ' ').slice(-300)})`);
    check.eq(Number(rows('select count(*) from staff_shifts where store_id = ? and staff_id = ?', [a.id, sam.id])[0][0]), 1, 'one shift is stored for Sam in store A');
    check.eq(Number(rows('select published from staff_shifts where store_id = ? and staff_id = ?', [a.id, sam.id])[0][0]), 0, 'a new shift is a draft (not visible to staff)');

    // staff cannot see a draft
    await P.staffLogin(worker.page, base, sam);
    await worker.page.goto(`${base}/staff-app`, { waitUntil: 'domcontentloaded' });
    let mine = await P.pageJson(worker.page, 'GET', '/staff-app/shifts');
    check.eq((mine.json?.shifts || []).length, 0, 'Sam sees no shift until the week is published');

    await Promise.all([nav(owner.page), owner.page.click('form[action*="/rota/publish"] button[type=submit]')]);
    check.ok(/1 shift\(s\) published/.test(await owner.page.evaluate(() => document.body.innerText)), 'publishing the week reports 1 shift');
    await shot(owner.page, 'p4-hr-rota');

    // ── staff: his published shift only, clock in / out ──────────────────────────────────────────────────────────────────
    mine = await P.pageJson(worker.page, 'GET', '/staff-app/shifts');
    check.eq((mine.json?.shifts || []).length, 1, 'Sam sees exactly his published shift');
    check.eq(mine.json?.shifts?.[0]?.role, 'Till', 'with the role the owner typed');
    const inA = await P.pageJson(worker.page, 'POST', '/staff-app/clock-in', { method: 'pin' });
    check.eq(inA.status, 201, 'clock in works');
    const inAgain = await P.pageJson(worker.page, 'POST', '/staff-app/clock-in', { method: 'pin' });
    check.eq(inAgain.status, 422, 'a second clock-in is refused while one is open');
    // put the clock-in three hours back (the fixture; the app computes everything else)
    P.tinker(`\\Juwri\\Modules\\Hr\\Models\\StaffTimeClock::where('staff_id', ${sam.id})->whereNull('clock_out_at')->update(['clock_in_at' => now()->subHours(3)]); echo 'OK=1';`, 'OK');
    const out = await P.pageJson(worker.page, 'POST', '/staff-app/clock-out');
    check.eq(out.status, 200, 'clock out works');
    check.ok(out.json && out.json.minutes >= 179 && out.json.minutes <= 181, `the app reports ~180 minutes worked (${out.json?.minutes})`);
    check.eq((await P.pageJson(worker.page, 'POST', '/staff-app/clock-out')).status, 422, 'clocking out twice is refused');
    check.eq((await L.status(worker.page, `${base}/rota?store_id=${a.id}`)).status, 403, 'a cashier cannot open the owner rota');

    // ── owner again: worked hours in payroll + CSV ───────────────────────────────────────────────────────────────────────
    await owner.page.goto(`${base}/rota/payroll?store_id=${a.id}`, { waitUntil: 'domcontentloaded' });
    const payText = await owner.page.evaluate(() => document.querySelector('main').innerText);
    check.ok(/Sam/.test(payText) && /3(\.00?)?\b/.test(payText), 'the payroll report shows Sam with 3 worked hours');
    check.ok(!/Zed/.test(payText), 'and nobody from the other store');
    await shot(owner.page, 'p4-hr-payroll');
    const csv = await owner.page.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).text(), `${base}/rota/payroll/csv?store_id=${a.id}`);
    check.ok(/Sam/.test(csv) && /,3(\.0+)?,/.test(csv) && !/Zed/.test(csv), 'the CSV has Sam with 3 worked hours and not Zed');

    // ── Zed (store B) works too: his clock entry belongs to store B only ─────────────────────────────────────────────────
    await P.staffLogin(zedPage.page, base, zed);
    await zedPage.page.goto(`${base}/staff-app`, { waitUntil: 'domcontentloaded' });
    const zedMine = await P.pageJson(zedPage.page, 'GET', '/staff-app/shifts');
    check.eq((zedMine.json?.shifts || []).map((s) => String(s.id)).join(','), zedShift, 'Zed sees his own shift and none of Sam\'s');
    check.eq((await P.pageJson(zedPage.page, 'POST', '/staff-app/clock-in', { method: 'pin' })).status, 201, 'Zed clocks in');
    check.eq(Number(rows('select count(*) from staff_time_clock_entries where staff_id = ? and store_id = ?', [zed.id, b.id])[0][0]), 1, 'his entry is in store B');
    check.eq(Number(rows('select count(*) from staff_time_clock_entries where staff_id = ? and store_id <> ?', [zed.id, b.id])[0][0]), 0, 'and in no other store');
    check.eq(Number(rows('select count(*) from staff_time_clock_entries where staff_id = ?', [sam.id])[0][0]), 1, 'Sam\'s entry is untouched by Zed');

    // ── cross-store IDOR ─────────────────────────────────────────────────────────────────────────────────────────────────
    const samShift = rows('select id from staff_shifts where store_id = ? and staff_id = ?', [a.id, sam.id])[0][0];
    await L.login(other.page, base, P.OTHER);
    check.eq((await L.status(other.page, `${base}/rota?store_id=${a.id}`)).status, 403, 'another owner: 403 on the rota of store A');
    check.eq((await L.status(other.page, `${base}/rota/payroll?store_id=${a.id}`)).status, 403, 'another owner: 403 on the payroll of store A');
    check.eq((await L.status(other.page, `${base}/rota/payroll/csv?store_id=${a.id}`)).status, 403, 'another owner: 403 on the payroll CSV of store A');
    check.eq((await L.status(other.page, `${base}/staff/${sam.id}/hrm`)).status, 404, 'another owner: 404 on Sam\'s employee file');
    await other.page.goto(`${base}/rota?store_id=${b.id}`, { waitUntil: 'domcontentloaded' });
    const del = await other.page.evaluate(async (u, token) => {
      const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/x-www-form-urlencoded' }, body: '_method=DELETE' });
      return r.status;
    }, `${base}/rota/${samShift}?store_id=${b.id}`, await other.page.$eval('meta[name=csrf-token]', (m) => m.content).catch(() => ''));
    check.eq(del, 404, 'another owner cannot delete Sam\'s shift (404)');
    check.eq(Number(rows('select count(*) from staff_shifts where id = ?', [samShift])[0][0]), 1, 'and the shift is still there');
    // owner A cannot delete or schedule across stores either
    const delB = await owner.page.evaluate(async (u, token) => {
      const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/x-www-form-urlencoded' }, body: '_method=DELETE' });
      return r.status;
    }, `${base}/rota/${zedShift}?store_id=${a.id}`, await owner.page.$eval('meta[name=csrf-token]', (m) => m.content).catch(() => ''));
    check.eq(delB, 404, 'owner A cannot delete Zed\'s shift in store B (404)');
    const sched = await owner.page.evaluate(async (u, token, body) => {
      const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/x-www-form-urlencoded' }, body });
      return r.status;
    }, `${base}/rota?store_id=${a.id}`, await owner.page.$eval('meta[name=csrf-token]', (m) => m.content).catch(() => ''),
    new URLSearchParams({ staff_id: zed.id, starts_at: local(start), ends_at: local(end) }).toString());
    check.eq(sched, 404, 'owner A cannot schedule Zed (store B staff) in store A (404)');
    check.eq(Number(rows('select count(*) from staff_shifts where staff_id = ? and store_id = ?', [zed.id, a.id])[0][0]), 0, 'no shift for Zed was created in store A');
    check.eq((await L.status(owner.page, `${base}/staff/${zed.id}/hrm`)).status, 404, 'owner A: 404 on Zed\'s employee file');
  } finally {
    await Promise.all([owner.close(), worker.close(), other.close(), zedPage.close()]);
    P.cleanup([a, b]);
  }
  check.done(log);
};
