// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * ISS-V-B-005 — KDS multi-station routing (Platform E2E v2, lane B depth).
 *
 * crave-demo is in advanced KDS mode with two stations (E2E Grill / E2E Cold,
 * seeded by E2eKdsStationDemoSeeder) and two items routed to different stations
 * via menu.station_id. Places a real order carrying both items, then asserts the
 * station-aware poll routes each line to its OWN station only:
 *   - kds/poll?station_id=Grill returns the Grill line, NOT the Cold line;
 *   - kds/poll?station_id=Cold returns the Cold line, NOT the Grill line.
 * Order placement stamps order_items.station_id (server-side), so this is a
 * genuine end-to-end routing test, not a fixture read-back.
 */

const V = require('../../lib/platform-v2');

let STORE_ID = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)
let GRILL_ITEM = 0;            // Crispy Onion Rings, routed to E2E Grill (resolved by name)
let COLD_ITEM = 0;             // Loaded Fries, routed to E2E Cold

module.exports = async function bKdsStations({ page, log }) {
  STORE_ID = V.craveStoreId();
  GRILL_ITEM = V.craveItemId('Crispy Onion Rings');
  COLD_ITEM = V.craveItemId('Loaded Fries');
  const SHOWCASE_EMAIL = process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com';
  const SHOWCASE_PASS = process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass';

  V.collectConsole(page, { ignore: [/Failed to load resource/i, /404/i] });
  log('[b-kds-stations] SETUP — login owner');
  await V.loginOwner(page, { email: SHOWCASE_EMAIL, pass: SHOWCASE_PASS });

  const grill = V.db("SELECT id FROM {p}kitchen_stations WHERE store_id = ? AND name = 'E2E Grill' LIMIT 1", [STORE_ID]);
  const cold = V.db("SELECT id FROM {p}kitchen_stations WHERE store_id = ? AND name = 'E2E Cold' LIMIT 1", [STORE_ID]);
  if (!grill.length || !cold.length) throw new Error('b-kds-stations: stations not seeded — run E2eKdsStationDemoSeeder');
  const grillId = parseInt(grill[0][0], 10);
  const coldId = parseInt(cold[0][0], 10);
  log(`[b-kds-stations] stations Grill #${grillId} / Cold #${coldId}`);

  // Place a real dine-in order with one Grill item + one Cold item.
  const slug = V.db('SELECT slug FROM {p}stores WHERE id = ? LIMIT 1', [STORE_ID])[0][0];
  await page.goto(V.localeUrl(`/${slug}`), { waitUntil: 'networkidle2' });
  const res = await page.evaluate(async ({ storeId, items }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const body = new URLSearchParams();
    body.append('name', 'E2E KDS Stations');
    body.append('ordering-type', 'on-table');
    body.append('table', '88');
    body.append('pay_via', 'pay_on_counter');
    body.append('items', JSON.stringify(items));
    const r = await fetch(`/${storeId}/send-order`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      body: body.toString(),
    });
    let j = null; try { j = await r.json(); } catch (_e) { /* noop */ }
    return { status: r.status, body: j };
  }, { storeId: STORE_ID, items: [{ id: GRILL_ITEM, quantity: 1 }, { id: COLD_ITEM, quantity: 1 }] });
  if (!res.body || res.body.success !== true) throw new Error(`send-order rejected (${res.status} ${JSON.stringify(res.body)})`);

  const token = (String(res.body.track_url || '').match(/order\/track\/([^/?#]+)/) || [])[1];
  const orderRow = token ? V.db('SELECT id FROM {p}orders WHERE track_token = ? LIMIT 1', [token]) : [];
  if (!orderRow.length) throw new Error('b-kds-stations: could not resolve placed order id');
  const orderId = parseInt(orderRow[0][0], 10);

  // Server-side routing: each item line carries its mapped station_id.
  const lines = V.db('SELECT item_id, station_id FROM {p}order_items WHERE order_id = ? ORDER BY item_id', [orderId]);
  const byItem = Object.fromEntries(lines.map((r) => [String(r[0]), r[1] === 'NULL' ? null : parseInt(r[1], 10)]));
  if (byItem[String(GRILL_ITEM)] !== grillId) throw new Error(`Grill item ${GRILL_ITEM} station_id=${byItem[String(GRILL_ITEM)]} expected ${grillId}`);
  if (byItem[String(COLD_ITEM)] !== coldId) throw new Error(`Cold item ${COLD_ITEM} station_id=${byItem[String(COLD_ITEM)]} expected ${coldId}`);
  log(`[b-kds-stations] order #${orderId}: item ${GRILL_ITEM}→Grill, ${COLD_ITEM}→Cold (server-stamped) ✓`);

  // A storefront order lands 'pending'; the KDS board shows ['new','preparing',
  // 'ready']. Advance it to 'preparing' through the real owner endpoint so it
  // reaches the kitchen (the same transition b-orders drives).
  const adv = await page.evaluate(async ({ orderId, csrf }) => {
    const r = await fetch(`/order/${orderId}/status`, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      body: new URLSearchParams({ status: 'preparing' }).toString(),
    });
    return { status: r.status };
  }, { orderId, csrf: await page.$eval('meta[name="csrf-token"]', (m) => m.content).catch(() => '') });
  if (![200, 302].includes(adv.status)) throw new Error(`advance to preparing returned ${adv.status}`);

  // Station-aware poll: each station sees ONLY its own line.
  const poll = (stationId) => page.evaluate(async ({ storeId, stationId }) => {
    const r = await fetch(`/kds/poll?store_id=${storeId}&station_id=${stationId}`, {
      credentials: 'same-origin', headers: { Accept: 'application/json' },
    });
    let j = null; try { j = await r.json(); } catch (_e) { /* noop */ }
    return { status: r.status, body: j };
  }, { storeId: STORE_ID, stationId });

  // The poll item exposes `name` (not the menu id), so match by name.
  const nm = (id) => V.db('SELECT name FROM {p}items WHERE id = ? LIMIT 1', [id])[0][0];
  const GRILL_NAME = nm(GRILL_ITEM);
  const COLD_NAME = nm(COLD_ITEM);

  const itemNamesFor = (resp, orderId) => {
    const orders = (resp.body && (resp.body.orders || resp.body.data || resp.body)) || [];
    const list = Array.isArray(orders) ? orders : [];
    const o = list.find((x) => Number(x.id) === orderId);
    return (o && Array.isArray(o.items)) ? o.items.map((i) => String(i.name)) : [];
  };

  const grillPoll = await poll(grillId);
  if (grillPoll.status !== 200) throw new Error(`kds/poll(Grill) returned ${grillPoll.status}`);
  const grillNames = itemNamesFor(grillPoll, orderId);
  if (!grillNames.includes(GRILL_NAME)) throw new Error(`Grill board missing "${GRILL_NAME}" (got ${JSON.stringify(grillNames)})`);
  if (grillNames.includes(COLD_NAME)) throw new Error(`ROUTING LEAK: "${COLD_NAME}" shown on the Grill board`);
  log(`[b-kds-stations] Grill board: has "${GRILL_NAME}", excludes "${COLD_NAME}" ✓`);

  const coldPoll = await poll(coldId);
  if (coldPoll.status !== 200) throw new Error(`kds/poll(Cold) returned ${coldPoll.status}`);
  const coldNames = itemNamesFor(coldPoll, orderId);
  if (!coldNames.includes(COLD_NAME)) throw new Error(`Cold board missing "${COLD_NAME}" (got ${JSON.stringify(coldNames)})`);
  if (coldNames.includes(GRILL_NAME)) throw new Error(`ROUTING LEAK: "${GRILL_NAME}" shown on the Cold board`);
  log(`[b-kds-stations] Cold board: has "${COLD_NAME}", excludes "${GRILL_NAME}" ✓`);

  await V.assertNoConsoleErrors(page);
  log('[b-kds-stations] PASS — multi-station routing isolates lines per station');
};
