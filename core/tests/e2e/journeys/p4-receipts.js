// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-13 — Receipts and printing: customer receipt, kitchen ticket, Arabic receipt (E2E_RUN_P4=1).
 *
 * A guest orders a burger and an Arabic-named shawarma online → the owner sees the order and opens its receipt drawer (store name,
 * order number, both lines, the total the order really has) → the cashier prints the receipt page: English (labels, lines, subtotal /
 * total adding up to the order's grand total) and ARABIC (right-to-left page, Arabic labels, the Arabic item name, the same figures) →
 * the kitchen ticket carries the lines and the order number and NO price → a thermal print job (ESC/POS) is queued for the printer
 * and its bytes hold the order number and lines (Arabic text included) → another store's cashier gets 404 on this order's receipt,
 * ticket and print job, and the other owner cannot read the order screen of store A.
 */

const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;
const AR_NAME = 'e2eB شاورما';

module.exports = async function p4Receipts({ browser, log, shot }) {
  const check = L.checker('p4-receipts');
  const base = L.baseUrl();
  const a = P.ownerRestaurant(P.OWNER.email);
  const b = P.ownerRestaurant(P.OTHER.email);
  // the currency is pinned (the seeded store has none and prints "$"): a code-prefixed figure ("SAR 28.50") must parse as well
  const prevA = P.setOptions(a, { require_phone_verification: '0', open_close_store: '1', currency_code: 'SAR' });
  const prevB = P.setOptions(b, { require_phone_verification: '0' });
  const itemsA = P.makeMenu(a, [['e2eB Burger', 12.5, {}], [AR_NAME, 8, {}]]);
  const itemsB = P.makeMenu(b, [['e2eB Wrap B', 5, {}]]);
  const sam = P.makeStaff(a, 'Sam', 'cashier');
  const zed = P.makeStaff(b, 'Zed', 'cashier');
  const profileOf = (store) => P.tinker(`
    $p = \\Juwri\\Modules\\Pos\\Models\\PrinterProfile::create(['store_id' => ${store.id}, 'name' => 'e2eB Receipt', 'transport' => 'webserial', 'width_mm' => 80,
      'events' => ['new_order', 'paid', 'kot'], 'station_ids' => [], 'auto_print' => false, 'active' => true, 'copies' => 1]);
    echo 'ID='.$p->id;`, 'ID');
  const profA = profileOf(a);
  const profB = profileOf(b);

  const owner = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const guest = await L.freshPage(browser, newPage, { width: 420, height: 800 });
  const till = await L.freshPage(browser, newPage, { width: 900, height: 900 });
  const zedPage = await L.freshPage(browser, newPage, { width: 900, height: 900 });
  const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  // the receipt pages print and close themselves on load: keep them open for the checks
  for (const pg of [till.page, zedPage.page]) await pg.evaluateOnNewDocument(() => { window.print = () => {}; window.close = () => {}; });
  const text = (page) => page.evaluate(() => document.body.innerText.replace(/[ \t]+/g, ' ').replace(/\n\s*\n+/g, '\n'));
  try {
    // ── customer orders ───────────────────────────────────────────────────────────────────────────────────────────────────
    const placed = await P.guestOrder(guest.page, base, a, [{ id: itemsA['e2eB Burger'], quantity: 1 }, { id: itemsA[AR_NAME], quantity: 2 }], { name: 'E2E Guest' });
    check.ok(placed.json && placed.json.success, `the guest's order is placed (${placed.status} ${JSON.stringify(placed.json).slice(0, 160)})`);
    const id = placed.json.order_id;
    const grand = Number(L.dbq('select grand_total_minor from orders where id = ?', [id])[0][0]) / 100;
    check.ok(grand >= 28.5, `the order's grand total is at least the lines (${grand})`);

    // ── owner: the order and its receipt drawer ──────────────────────────────────────────────────────────────────────────
    await L.login(owner.page, base, P.OWNER);
    await owner.page.goto(`${base}/orders/${a.id}?q=E2E+Guest`, { waitUntil: 'domcontentloaded' });
    const card = await owner.page.evaluate((oid) => document.querySelector(`[data-order-id="${oid}"]`)?.innerText || '', String(id));
    check.ok(/e2eB Burger/.test(card) && card.includes(AR_NAME), 'the owner sees the order with both lines');
    const openReceipt = await owner.page.evaluateHandle((oid) => [...document.querySelectorAll(`[data-order-id="${oid}"] button, [data-order-id="${oid}"] a`)].find((el) => /receipt/i.test((el.getAttribute('aria-label') || '') + (el.title || '') + el.innerText)), String(id));
    if (openReceipt.asElement()) {
      await openReceipt.asElement().click();
      await owner.page.waitForSelector('.ui-orders-drawer.is-open', { timeout: 8000 }).catch(() => {});
      const drawer = await owner.page.evaluate(() => document.querySelector('.ui-orders-drawer')?.innerText || '');
      check.ok(/Order Receipt/i.test(drawer) && drawer.includes(AR_NAME) && /e2eB Burger/.test(drawer), 'the receipt drawer shows the lines');
      check.ok(drawer.includes(a.title), 'with the store name');
      await shot(owner.page, 'p4-receipts-drawer');
    } else {
      check.ok(false, 'the order card has a receipt control');
    }

    // ── cashier: English receipt ─────────────────────────────────────────────────────────────────────────────────────────
    await P.staffLogin(till.page, base, sam);
    await till.page.goto(`${base}/pos/orders/${id}/receipt?lang=en`, { waitUntil: 'domcontentloaded' });
    const en = await text(till.page);
    if (process.env.E2E_DUMP) console.log('EN>>>\n' + en + '\n<<<');
    check.ok(/Receipt/.test(await till.page.title()) && new RegExp(`Order #${id}\\b`).test(en), 'the English receipt names itself and the order number');
    check.ok(/Type: Takeaway/.test(en), 'with its order type');
    check.ok(en.includes(a.title), 'with the store name');
    check.ok(/1× e2eB Burger/.test(en) && en.includes(`2× ${AR_NAME}`), 'both lines with their quantities');
    check.ok(/12\.50/.test(en) && /16\.00/.test(en), 'each line is priced (12.50 and 2 × 8.00 = 16.00)');
    const totalLine = P.amountOf(en, 'Total');
    check.eq(totalLine, grand, `the printed total equals the order's grand total (${totalLine})`);
    const subLine = P.amountOf(en, 'Subtotal');
    check.eq(subLine, 28.5, 'the subtotal adds the lines (28.50)');
    check.eq(await till.page.evaluate(() => document.documentElement.dir), 'ltr', 'an English receipt is left-to-right');
    await shot(till.page, 'p4-receipts-en');

    // ── cashier: Arabic receipt ──────────────────────────────────────────────────────────────────────────────────────────
    await till.page.goto(`${base}/pos/orders/${id}/receipt?lang=ar`, { waitUntil: 'domcontentloaded' });
    const ar = await text(till.page);
    if (process.env.E2E_DUMP) console.log('AR>>>\n' + ar + '\n<<<');
    check.eq(await till.page.evaluate(() => document.documentElement.dir), 'rtl', 'the Arabic receipt is right-to-left');
    check.eq(await till.page.evaluate(() => document.documentElement.lang), 'ar', 'and declares Arabic');
    check.ok((await till.page.title()).includes('الإيصال') && ar.includes('الطلب') && ar.includes('الإجمالي') && ar.includes('المجموع الفرعي'), 'Receipt / Order / Total / Subtotal are in Arabic');
    check.ok(ar.includes('النوع: سفري') && !/Takeaway/i.test(ar), 'and so is the order type (not the raw English key)');
    check.ok(ar.includes(AR_NAME) && ar.includes(a.title), 'the Arabic item name and the store name print');
    check.ok(!/\bTotal\b|\bSubtotal\b|\bReceipt\b/.test(ar), 'no English label is left on the Arabic receipt');
    const arTotal = P.amountOf(ar, 'الإجمالي');
    check.eq(arTotal, grand, `the Arabic receipt shows the same total (${arTotal})`);
    await shot(till.page, 'p4-receipts-ar');

    // ── kitchen ticket ───────────────────────────────────────────────────────────────────────────────────────────────────
    await till.page.goto(`${base}/pos/orders/${id}/receipt?format=kitchen&lang=en`, { waitUntil: 'domcontentloaded' });
    const kot = await text(till.page);
    if (process.env.E2E_DUMP) console.log('KOT>>>\n' + kot + '\n<<<');
    check.ok(/Kitchen ticket/.test(await till.page.title()) && kot.includes(`#${id} · TAKEAWAY`), 'the kitchen ticket names itself, the order number and type');
    check.ok(/1× e2eB Burger/.test(kot) && kot.includes(`2× ${AR_NAME}`), 'with the lines and quantities');
    check.ok(!/\d+[.,]\d{2}/.test(kot), 'and no price anywhere');
    check.ok(!/Total|Subtotal/.test(kot), 'no totals either');
    await till.page.goto(`${base}/pos/orders/${id}/receipt?format=kitchen&lang=ar`, { waitUntil: 'domcontentloaded' });
    const karT = await text(till.page);
    check.ok((await till.page.title()).includes('تذكرة المطبخ') && karT.includes(`#${id} · سفري`) && !/TAKEAWAY/i.test(karT) && karT.includes(AR_NAME), 'the Arabic kitchen ticket is Arabic (type included) and keeps the Arabic item name');
    await shot(till.page, 'p4-receipts-kot');

    // ── thermal print job (ESC/POS) ──────────────────────────────────────────────────────────────────────────────────────
    const pj = (page, m, u, b) => P.pageJson(page, m, u, b, '/pos'); // the register page is busy (service worker, polling): call from a static receipt page
    const job = await pj(till.page, 'POST', `/pos/print/orders/${id}`, { profile_id: Number(profA), event: 'paid' });
    check.ok(job.status === 200 && job.json && job.json.ok, `a print job is queued for the receipt printer (${job.status} ${JSON.stringify(job.json)})`);
    const jobs = await pj(till.page, 'GET', '/pos/print/jobs');
    const mine = (jobs.json?.jobs || []).find((j) => String(j.order_id) === String(id) && j.event === 'paid');
    check.ok(!!mine, 'the register collects it');
    const bytes = mine ? Buffer.from(mine.payload, 'base64') : Buffer.alloc(0);
    check.ok(bytes.length > 50 && bytes[0] === 0x1b && bytes[1] === 0x40, 'the payload is an ESC/POS document (starts with ESC @)');
    const bin = bytes.toString('utf8');
    check.ok(bin.includes(`#${id}`) && bin.includes('e2eB Burger'), 'it carries the order number and the English line');
    check.ok(/\d{2}\.\d{2}/.test(bin) && bin.includes(grand.toFixed(2)), `and the grand total (${grand.toFixed(2)})`);
    const kotJob = await pj(till.page, 'POST', `/pos/print/orders/${id}`, { profile_id: Number(profA), event: 'kot' });
    check.ok(kotJob.json && kotJob.json.ok, 'a kitchen-ticket print job queues too');
    const kotBytes = Buffer.from(((await pj(till.page, 'GET', '/pos/print/jobs')).json?.jobs || []).find((j) => j.event === 'kot' && String(j.order_id) === String(id))?.payload || '', 'base64').toString('utf8');
    check.ok(kotBytes.includes('e2eB Burger') && !kotBytes.includes(grand.toFixed(2)), 'the thermal kitchen ticket has the lines and no total');

    // ── cross-store IDOR ─────────────────────────────────────────────────────────────────────────────────────────────────
    const orderB = await P.guestOrder(guest.page, base, b, [{ id: itemsB['e2eB Wrap B'], quantity: 1 }], { name: 'E2E Guest' });
    const idB = orderB.json && orderB.json.order_id;
    await P.staffLogin(zedPage.page, base, zed);
    await zedPage.page.goto(`${base}/pos/orders/${idB}/receipt`, { waitUntil: 'domcontentloaded' }); // his own store's receipt: a static page to call from
    check.eq((await L.status(zedPage.page, `${base}/pos/orders/${id}/receipt`)).status, 404, 'another store\'s cashier: 404 on this order\'s receipt');
    check.eq((await L.status(zedPage.page, `${base}/pos/orders/${id}/receipt?format=kitchen`)).status, 404, 'and on its kitchen ticket');
    check.eq((await pj(zedPage.page, 'POST', `/pos/print/orders/${id}`, { profile_id: Number(profB), event: 'paid' })).status, 404, 'and cannot print it on their own printer (404)');
    check.eq((await pj(till.page, 'POST', `/pos/print/orders/${idB}`, { profile_id: Number(profA), event: 'paid' })).status, 404, 'Sam cannot print store B\'s order either (404)');
    check.eq((await pj(till.page, 'POST', `/pos/print/orders/${id}`, { profile_id: Number(profB), event: 'paid' })).status, 404, 'nor onto store B\'s printer (404)');
    check.eq(Number(L.dbq('select count(*) from print_jobs where store_id = ? and order_id = ?', [b.id, id])[0][0]), 0, 'no print job of store A\'s order exists in store B');
    await L.login(other.page, base, P.OTHER);
    check.eq((await L.status(other.page, `${base}/orders/${a.id}`)).status, 404, 'another owner: 404 on store A\'s order screen');
  } finally {
    await Promise.all([owner.close(), guest.close(), till.close(), zedPage.close(), other.close()]);
    P.tinker(`
      \\App\\Models\\Order::whereIn('store_id', [${a.id}, ${b.id}])->where('customer_name', 'E2E Guest')->get()->each(fn ($o) => $o->delete());
      \\Juwri\\Modules\\Pos\\Models\\PrinterProfile::whereIn('id', [${profA}, ${profB}])->delete();
      \\Juwri\\Modules\\Menu\\Models\\Menu::whereIn('id', ${P.php([...Object.values(itemsA), ...Object.values(itemsB)])})->get()->each(fn ($m) => $m->delete());
      \\App\\Models\\StaffMember::whereIn('id', [${sam.id}, ${zed.id}])->delete();
      echo 'OK=1';`, 'OK');
    P.restoreOptions(a, prevA);
    P.restoreOptions(b, prevB);
  }
  check.done(log);
};
