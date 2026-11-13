// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-04 — Inventory: a sale reduces stock, out-of-stock blocks, low-stock alert, POS + online share one stock (E2E_RUN_P4=1).
 *
 * Owner (store A) tracks "Latte" (stock 5, low at 2) and "Muffin" (stock 1) → a guest orders 2 Lattes online (5 → 3) → the cashier sells
 * 1 at the POS (3 → 2): the same number, so both channels draw on one stock and the item reads "Low" → asking for more than is left is
 * refused and takes nothing → the last Muffin sells and the item goes Sold out and cannot be ordered, online or at the POS → the daily
 * low-stock digest tells the owner → the owner restocks and the item sells again → another owner / the other store: 403 / 404 on the stock
 * screen and every write, and one store's sales never touch the other's stock.
 */

const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;

module.exports = async function p4Inventory({ browser, log, shot }) {
  const check = L.checker('p4-inventory');
  const base = L.baseUrl();
  P.enable(P.OWNER.email, ['inventory']);
  P.enable(P.OTHER.email, ['inventory']);
  const a = P.ownerRestaurant(P.OWNER.email);
  const b = P.ownerRestaurant(P.OTHER.email);
  const prevA = P.setOptions(a, { require_phone_verification: '0', stock_oversell_count: '0' }); // a guest order must not stop at the phone-code step
  const prevB = P.setOptions(b, { require_phone_verification: '0' });
  const itemsA = P.makeMenu(a, [
    ['e2eB Latte', 10, { track_inventory: 1, stock_qty: 5, low_stock_threshold: 2 }],
    ['e2eB Muffin', 4, { track_inventory: 1, stock_qty: 1, low_stock_threshold: 0 }],
  ]);
  const itemsB = P.makeMenu(b, [['e2eB Latte B', 10, { track_inventory: 1, stock_qty: 3, low_stock_threshold: 1 }]]);
  const cashier = P.makeStaff(a, 'Sam', 'cashier');
  const stock = (id) => Number(L.dbq('select stock_qty from items where id = ?', [id])[0][0]);
  const soldOut = (id) => Number(L.dbq('select is_sold_out from items where id = ?', [id])[0][0]);

  const owner = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const guest = await L.freshPage(browser, newPage, { width: 420, height: 800 });
  const till = await L.freshPage(browser, newPage, { width: 1100, height: 800 });
  const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const nav = (p) => p.waitForNavigation({ waitUntil: 'domcontentloaded' });
  try {
    // ── owner sees the tracked items ─────────────────────────────────────────────────────────────────────────────────────
    await L.login(owner.page, base, P.OWNER);
    await owner.page.goto(`${base}/inventory?store_id=${a.id}`, { waitUntil: 'domcontentloaded' });
    let text = await owner.page.evaluate(() => document.querySelector('main').innerText);
    check.ok(/e2eB Latte/.test(text) && /e2eB Muffin/.test(text), 'the owner sees both tracked items');
    check.ok(!/e2eB Latte B/.test(text), 'and not the other store\'s item');
    await shot(owner.page, 'p4-inventory-before');

    // ── customer, online: 2 lattes ───────────────────────────────────────────────────────────────────────────────────────
    const online = await P.guestOrder(guest.page, base, a, [{ id: itemsA['e2eB Latte'], quantity: 2 }]);
    check.ok(online.json && online.json.success, `the online order is accepted (${online.status} ${JSON.stringify(online.json).slice(0, 200)})`);
    check.eq(stock(itemsA['e2eB Latte']), 3, 'an online sale of 2 takes the stock from 5 to 3');

    // ── POS: one more ────────────────────────────────────────────────────────────────────────────────────────────────────
    await P.staffLogin(till.page, base, cashier);
    const pos = await P.posOrder(till.page, base, [{ item_id: itemsA['e2eB Latte'], quantity: 1 }]);
    check.ok(pos.status === 201 || pos.status === 200, `the POS sale is accepted (${pos.status} ${JSON.stringify(pos.json).slice(0, 200)})`);
    check.eq(stock(itemsA['e2eB Latte']), 2, 'the POS sale draws on the same stock (3 → 2)');
    await owner.page.goto(`${base}/inventory?store_id=${a.id}`, { waitUntil: 'domcontentloaded' });
    text = await owner.page.evaluate(() => [...document.querySelectorAll('tr')].find((r) => /e2eB Latte/.test(r.innerText))?.innerText || '');
    check.ok(/\b2\b/.test(text) && /Low/.test(text), `the owner's stock screen shows Latte with 2 left and "Low" (${text.replace(/\s+/g, ' ')})`);

    // ── out of stock blocks ──────────────────────────────────────────────────────────────────────────────────────────────
    const tooMany = await P.guestOrder(guest.page, base, a, [{ id: itemsA['e2eB Latte'], quantity: 5 }]);
    check.ok(tooMany.status >= 400 || (tooMany.json && tooMany.json.success === false), `ordering more than is left is refused (${tooMany.status})`);
    check.eq(stock(itemsA['e2eB Latte']), 2, 'and takes nothing');
    // the register records a sale that is already rung up (staff see the shelf): it is never refused, the stock goes truthfully negative
    // and the oversell is counted for the owner (H5)
    const posTooMany = await P.posOrder(till.page, base, [{ item_id: itemsA['e2eB Latte'], quantity: 5 }]);
    check.ok(posTooMany.status === 201 || posTooMany.status === 200, `the register records an already-rung sale even past the stock (${posTooMany.status})`);
    check.eq(stock(itemsA['e2eB Latte']), -3, 'the stock goes truthfully negative (2 - 5)');
    await owner.page.goto(`${base}/inventory?store_id=${a.id}`, { waitUntil: 'domcontentloaded' });
    const facts = await owner.page.evaluate(() => document.querySelector('.ui-inv__facts')?.innerText.replace(/\s+/g, ' ') || '');
    check.ok(/Oversold\s*1/i.test(facts), `the owner's screen counts the oversell (${facts})`);
    P.tinker(`\\Juwri\\Modules\\Menu\\Models\\Menu::where('id', ${itemsA['e2eB Latte']})->update(['stock_qty' => 2]); echo 'OK=1';`, 'OK'); // back to 2 for the rest of the journey
    const lastMuffin = await P.guestOrder(guest.page, base, a, [{ id: itemsA['e2eB Muffin'], quantity: 1 }]);
    check.ok(lastMuffin.json && lastMuffin.json.success, 'the last Muffin sells online');
    check.eq(stock(itemsA['e2eB Muffin']), 0, 'its stock is 0');
    check.eq(soldOut(itemsA['e2eB Muffin']), 1, 'and the item is marked sold out');
    const noMuffin = await P.guestOrder(guest.page, base, a, [{ id: itemsA['e2eB Muffin'], quantity: 1 }]);
    check.ok(noMuffin.status >= 400 || (noMuffin.json && noMuffin.json.success === false), `a sold-out item cannot be ordered online (${noMuffin.status})`);
    check.eq(stock(itemsA['e2eB Muffin']), 0, 'the stock stays 0 after a refused online order');

    // ── low-stock alert: the owner's dashboard + the daily digest ────────────────────────────────────────────────────────
    await owner.page.goto(`${base}/dashboard?store_id=${a.id}`, { waitUntil: 'domcontentloaded' });
    const dash = await owner.page.evaluate(() => document.body.innerText);
    check.ok(/menu items? (is|are) low on stock/i.test(dash), 'the owner\'s dashboard flags the low-stock items');
    check.ok(/items? (is|are) sold out/i.test(dash), 'and the sold-out item');
    const before = Number(L.dbq('select count(*) from platform_notifications')[0][0]);
    L.artisan(['inventory:low-stock-digest']);
    check.ok(Number(L.dbq('select count(*) from platform_notifications')[0][0]) > before, 'the daily low-stock digest raises its notification');
    check.eq(Number(L.dbq("select count(*) from platform_notifications where type = 'low_stock' and title like '%e2eB%'")[0][0]), 0, 'carrying a count, never an item name');

    // ── owner restocks through the stock screen → sells again ────────────────────────────────────────────────────────────
    await owner.page.goto(`${base}/inventory?store_id=${a.id}`, { waitUntil: 'domcontentloaded' });
    const row = await owner.page.evaluateHandle(() => [...document.querySelectorAll('tr')].find((r) => /e2eB Muffin/.test(r.innerText)));
    await row.asElement().$eval('input[name=stock_qty]', (el) => { el.value = '4'; });
    await Promise.all([nav(owner.page), row.asElement().$eval('form', (f) => f.requestSubmit())]);
    check.eq(stock(itemsA['e2eB Muffin']), 4, 'the owner restocks Muffin to 4 from the stock screen');
    check.eq(soldOut(itemsA['e2eB Muffin']), 0, 'which clears sold-out');
    const again = await P.guestOrder(guest.page, base, a, [{ id: itemsA['e2eB Muffin'], quantity: 1 }]);
    check.ok(again.json && again.json.success, 'and it can be ordered again');
    await shot(owner.page, 'p4-inventory-after');

    // ── cross-store IDOR ─────────────────────────────────────────────────────────────────────────────────────────────────
    await L.login(other.page, base, P.OTHER);
    check.eq((await L.status(other.page, `${base}/inventory?store_id=${a.id}`)).status, 403, 'another owner: 403 on store A\'s stock screen');
    await other.page.goto(`${base}/inventory?store_id=${b.id}`, { waitUntil: 'domcontentloaded' });
    const post = (url, body) => P.formPost(other.page, url, body);
    check.eq(await post(`${base}/inventory/${itemsA['e2eB Latte']}`, '_method=PUT&stock_qty=999'), 404, 'another owner cannot restock store A\'s item (404)');
    check.eq(stock(itemsA['e2eB Latte']), 2, 'and its stock is untouched');
    check.eq(await post(`${base}/inventory/enable-all`, `store_id=${a.id}`), 403, 'another owner cannot switch tracking on in store A (403)');
    check.eq(await post(`${base}/inventory/mode`, `store_id=${a.id}&mode=advanced`), 403, 'nor change its inventory mode (403)');
    check.eq(stock(itemsB['e2eB Latte B']), 3, 'store B\'s stock was never touched by store A\'s sales');
    // a guest cannot order store A's item from store B's storefront
    const cross = await P.guestOrder(guest.page, base, b, [{ id: itemsA['e2eB Latte'], quantity: 1 }]);
    check.ok(cross.status >= 400 || (cross.json && cross.json.success === false), `store B's storefront will not sell store A's item (${cross.status})`);
    check.eq(stock(itemsA['e2eB Latte']), 2, 'and store A\'s stock did not move');
    // the cashier of A cannot ring up B's item
    const posCross = await P.posOrder(till.page, base, [{ item_id: itemsB['e2eB Latte B'], quantity: 1 }]);
    check.ok(posCross.status >= 400, `Sam's register will not sell store B's item (${posCross.status})`);
    check.eq(stock(itemsB['e2eB Latte B']), 3, 'store B\'s stock is still 3');
  } finally {
    await Promise.all([owner.close(), guest.close(), till.close(), other.close()]);
    P.tinker(`
      \\Juwri\\Modules\\Menu\\Models\\Menu::whereIn('id', ${P.php([...Object.values(itemsA), ...Object.values(itemsB)])})->get()->each(fn ($m) => $m->delete());
      echo 'OK=1';`, 'OK');
    P.restoreOptions(a, prevA);
    P.restoreOptions(b, prevB);
    P.tinker(`\\App\\Models\\StaffMember::where('id', ${cashier.id})->delete(); echo 'OK=1';`, 'OK');
  }
  check.done(log);
};
