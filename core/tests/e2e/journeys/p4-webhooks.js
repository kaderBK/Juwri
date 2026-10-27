// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-03 — Integrations / webhooks: add an endpoint, an order fires it, a failing receiver is recorded and re-delivered, cross-store IDOR
 * (E2E_RUN_P4=1; needs a PUBLIC https receiver, so it runs through tools/webhook_e2e.sh, which supplies E2E_WEBHOOK_* and a CA the app trusts).
 *
 * Owner adds endpoints in the API & webhooks screen (a plain-http URL and a loopback URL are refused) → a guest order fires `order.created`
 * to the endpoint subscribed to it and NOT to the one subscribed to `order.paid`; the request carries a Standard-Webhooks signature that
 * verifies with the endpoint's secret, the event, and the order's store → the receiver answers 500: the attempt is logged as failed and the
 * failure streak counts → the receiver recovers, the owner re-delivers from the log (fresh message id) and the streak resets → a paused
 * endpoint receives nothing, a resumed one does → fifteen failures in a row auto-disable the endpoint and the owner's "resume" gives it a
 * clean slate (a stale streak/reason would disable it again at the next failure) → another owner can neither toggle, test, delete nor
 * re-deliver this endpoint (404), cannot see it, and an order in their store never reaches it.
 */

const https = require('https');
const fs = require('fs');
const crypto = require('crypto');
const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;

module.exports = async function p4Webhooks({ browser, log, shot }) {
  const host = process.env.E2E_WEBHOOK_HOST;
  if (!host || !process.env.E2E_WEBHOOK_CERT) {
    return L.skip(log, 'p4-webhooks', 'no public https receiver (run it through the webhook_e2e.sh wrapper)');
  }
  const check = L.checker('p4-webhooks');
  const base = L.baseUrl();
  const port = Number(process.env.E2E_WEBHOOK_PORT || 18443);

  // ── the receiver: records every request, answers with the status the journey sets ─────────────────────────────────────
  const hits = [];
  let answer = 500;
  const server = https.createServer({ key: fs.readFileSync(process.env.E2E_WEBHOOK_KEY), cert: fs.readFileSync(process.env.E2E_WEBHOOK_CERT) }, (req, res) => {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      hits.push({ path: req.url, headers: req.headers, body, status: answer });
      res.statusCode = answer;
      res.end(answer < 300 ? 'ok' : 'nope');
    });
  });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(port, '0.0.0.0', resolve); });
  const waitFor = async (fn, ms = 20000) => { const end = Date.now() + ms; while (Date.now() < end) { if (fn()) return true; await new Promise((r) => setTimeout(r, 250)); } return fn(); };
  const canon = (v) => (Array.isArray(v) ? `[${v.map(canon)}]` : (v && typeof v === 'object' ? `{${Object.keys(v).sort().map((k) => `${k}:${canon(v[k])}`)}}` : JSON.stringify(v))); // the log stores the payload as JSON: key order is not kept
  const url = (p) => `https://${host}:${port}${p}`;

  const a = P.ownerRestaurant(P.OWNER.email);
  const b = P.ownerRestaurant(P.OTHER.email);
  const prevA = P.setOptions(a, { require_phone_verification: '0', open_close_store: '1' });
  const prevB = P.setOptions(b, { require_phone_verification: '0' });
  const itemsA = P.makeMenu(a, [['e2eB Hook Latte', 6, {}]]);
  const itemsB = P.makeMenu(b, [['e2eB Hook Latte B', 6, {}]]);
  const row = (sql, params) => L.dbq(sql, params);
  const endpointRow = (id) => row('select active, consecutive_failures, disabled_reason from webhook_endpoints where id = ?', [id])[0];
  const deliveriesOf = (id) => row('select id, event, http_status, success, attempt from webhook_deliveries where endpoint_id = ? order by id', [id]);

  const owner = await L.freshPage(browser, newPage, { width: 1280, height: 1000 });
  const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const guest = await L.freshPage(browser, newPage, { width: 420, height: 800 });
  const placeOrder = async (store, items, name) => {
    const r = await P.guestOrder(guest.page, base, store, [{ id: items, quantity: 1 }], { name });
    check.ok(r.json && r.json.success, `an order is placed in ${store.slug} (${r.status} ${JSON.stringify(r.json).slice(0, 120)})`);
    return r.json && r.json.order_id;
  };
  const created = [];
  let step = 'start';
  try {
    // ── add endpoints through the screen ───────────────────────────────────────────────────────────────────────────────────
    step = 'add endpoints through the screen';
    await L.login(owner.page, base, P.OWNER);
    await owner.page.goto(`${base}/api`, { waitUntil: 'domcontentloaded' });
    const before = Number(row('select count(*) from webhook_endpoints', [])[0][0]);
    await P.formPost(owner.page, '/api/webhooks', `url=${encodeURIComponent('http://example.com/hook')}&events%5B%5D=order.created`);
    await P.formPost(owner.page, '/api/webhooks', `url=${encodeURIComponent('https://127.0.0.1:' + port + '/hook')}&events%5B%5D=order.created`);
    await P.formPost(owner.page, '/api/webhooks', `url=${encodeURIComponent('https://localhost/hook')}&events%5B%5D=order.created`);
    check.eq(Number(row('select count(*) from webhook_endpoints', [])[0][0]), before, 'a plain-http URL, a loopback address and localhost are all refused');

    await owner.page.type('#wh-url', url('/hook'));
    await Promise.all([owner.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), owner.page.click('form[action$="/api/webhooks"] button[type=submit]')]);
    const ep = row('select id, secret from webhook_endpoints where url = ? and user_id = (select id from users where email = ?)', [url('/hook'), P.OWNER.email])[0];
    check.ok(!!ep, 'the endpoint added in the screen is stored');
    const epId = ep[0];
    const secret = ep[1];
    created.push(epId);
    check.ok((await owner.page.evaluate(() => document.body.innerText)).includes(url('/hook')), 'and listed on the screen');
    await P.formPost(owner.page, '/api/webhooks', `url=${encodeURIComponent(url('/paid-only'))}&events%5B%5D=order.paid`);
    const paidOnly = row('select id from webhook_endpoints where url = ?', [url('/paid-only')])[0];
    check.ok(!!paidOnly, 'a second endpoint subscribed to order.paid only is stored');
    if (paidOnly) created.push(paidOnly[0]);
    await shot(owner.page, 'p4-webhooks-screen');

    // ── an order fires the endpoint; the receiver is down (500) ───────────────────────────────────────────────────────────
    step = 'an order fires the endpoint; the receive';
    answer = 500;
    const orderId = await placeOrder(a, itemsA['e2eB Hook Latte'], 'Hook Guest');
    check.ok(await waitFor(() => hits.some((h) => h.path === '/hook')), 'the order reaches the endpoint');
    const hit = hits.find((h) => h.path === '/hook');
    if (hit) {
      const payload = JSON.parse(hit.body);
      check.eq(payload.event, 'order.created', 'the event is order.created');
      check.eq(hit.headers['webhook-id'], payload.id, 'the webhook-id header is the message id');
      const expected = crypto.createHmac('sha256', secret).update(`${hit.headers['webhook-id']}.${hit.headers['webhook-timestamp']}.${hit.body}`).digest('base64');
      check.eq(hit.headers['webhook-signature'], `v1,${expected}`, 'the Standard-Webhooks signature verifies with the endpoint secret');
      check.ok(Math.abs(Date.now() / 1000 - Number(hit.headers['webhook-timestamp'])) < 120, 'the timestamp is current');
      check.eq(String(payload.data.store_id ?? payload.data.restaurant_id ?? ''), String(a.id), 'the payload names the order\'s store');
      check.ok(JSON.stringify(payload.data).includes(String(orderId)), 'and carries the order');
    }
    check.ok(!hits.some((h) => h.path === '/paid-only'), 'the endpoint subscribed to order.paid only is not called');
    let log1 = deliveriesOf(epId);
    check.ok(await waitFor(() => (log1 = deliveriesOf(epId)).length >= 1, 8000), 'the attempt is logged');
    check.eq(`${log1[0][2]}/${log1[0][3]}`, '500/0', 'logged as HTTP 500, not successful');
    check.eq(Number(endpointRow(epId)[1]), 1, 'the failure streak counts it');

    // ── the receiver recovers; the owner re-delivers from the log ──────────────────────────────────────────────────────────
    step = 'the receiver recovers; the owner re-deli';
    answer = 200;
    await owner.page.goto(`${base}/api`, { waitUntil: 'domcontentloaded' });
    const redeliver = await owner.page.$(`form[action$="/api/webhooks/deliveries/${log1[0][0]}/redeliver"] button`);
    check.ok(!!redeliver, 'the failed delivery has a re-deliver button in the log');
    const hitsBefore = hits.length;
    if (redeliver) await Promise.all([owner.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), redeliver.click()]);
    check.ok(await waitFor(() => hits.length > hitsBefore), 're-delivering sends the event again');
    const again = hits[hits.length - 1];
    check.ok(again && hit && again.headers['webhook-id'] !== hit.headers['webhook-id'], 'with a fresh message id');
    check.ok(!!again && !!hit && canon(JSON.parse(again.body).data) === canon(JSON.parse(hit.body).data), 'and the same data');
    await waitFor(() => deliveriesOf(epId).length >= 2, 8000);
    const log2 = deliveriesOf(epId);
    check.eq(log2.length > 1 ? `${log2[log2.length - 1][2]}/${log2[log2.length - 1][3]}` : null, '200/1', 'the second attempt is logged as successful');
    check.eq(Number(endpointRow(epId)[1]), 0, 'and the failure streak is back to 0');

    // ── pause / resume ────────────────────────────────────────────────────────────────────────────────────────────────────
    step = 'pause / resume';
    await P.formPost(owner.page, `/api/webhooks/${epId}/toggle`, '');
    check.eq(Number(endpointRow(epId)[0]), 0, 'pausing the endpoint switches it off');
    const n1 = hits.length;
    await placeOrder(a, itemsA['e2eB Hook Latte'], 'Hook Guest 2');
    await new Promise((r) => setTimeout(r, 3000));
    check.eq(hits.length, n1, 'a paused endpoint receives nothing');
    await P.formPost(owner.page, `/api/webhooks/${epId}/toggle`, '');
    check.eq(Number(endpointRow(epId)[0]), 1, 'resuming switches it back on');
    await placeOrder(a, itemsA['e2eB Hook Latte'], 'Hook Guest 3');
    check.ok(await waitFor(() => hits.length > n1), 'and the next order is delivered');

    // ── fifteen failures in a row auto-disable; resume gives a clean slate ───────────────────────────────────────────────
    step = 'fifteen failures in a row auto-disable; ';
    P.tinker(`\\App\\Models\\WebhookEndpoint::where('id', ${epId})->update(['consecutive_failures' => 14]); echo 'OK=1';`, 'OK');
    answer = 500;
    const n2 = hits.length;
    await placeOrder(a, itemsA['e2eB Hook Latte'], 'Hook Guest 4');
    check.ok(await waitFor(() => hits.length > n2), 'the 15th failing delivery is attempted');
    await waitFor(() => Number(endpointRow(epId)[0]) === 0, 8000);
    let st = endpointRow(epId);
    check.ok(Number(st[0]) === 0 && /15/.test(String(st[2])), `the endpoint is auto-disabled with a reason (${st[2]})`);
    await owner.page.goto(`${base}/api`, { waitUntil: 'domcontentloaded' });
    check.ok(!!(await owner.page.$('[data-testid="dead-endpoint-banner"]')), 'the screen warns that a webhook stopped delivering');
    await P.formPost(owner.page, `/api/webhooks/${epId}/toggle`, '');
    st = endpointRow(epId);
    check.eq(Number(st[0]), 1, 'resume switches it on');
    check.eq(Number(st[1]), 0, 'and starts a clean failure streak (not 15)');
    check.eq(st[2], null, 'and clears the old "auto-disabled" reason');
    const n3 = hits.length;
    await placeOrder(a, itemsA['e2eB Hook Latte'], 'Hook Guest 5');
    check.ok(await waitFor(() => hits.length > n3), 'a resumed endpoint is called again');
    await waitFor(() => Number(endpointRow(epId)[1]) === 1, 8000);
    st = endpointRow(epId);
    check.ok(Number(st[0]) === 1 && Number(st[1]) === 1, `one failure after resuming leaves it on, streak 1 (${st[0]}/${st[1]})`);

    // ── another owner: no access, no events ───────────────────────────────────────────────────────────────────────────────
    step = 'another owner: no access, no events';
    answer = 200;
    const deliveryId = deliveriesOf(epId)[0][0];
    await L.login(other.page, base, P.OTHER);
    await other.page.goto(`${base}/api`, { waitUntil: 'domcontentloaded' });
    check.ok(!(await other.page.evaluate(() => document.body.innerText)).includes(url('/hook')), 'the other owner does not see this endpoint');
    const snap = JSON.stringify(endpointRow(epId));
    const nLog = deliveriesOf(epId).length;
    const n4 = hits.length;
    for (const [label, u] of [['toggle', `/api/webhooks/${epId}/toggle`], ['test', `/api/webhooks/${epId}/test`], ['re-deliver', `/api/webhooks/deliveries/${deliveryId}/redeliver`]]) {
      check.eq(await P.formPost(other.page, u, ''), 404, `the other owner cannot ${label} it (404)`);
    }
    const del = await other.page.evaluate(async (u) => {
      const t = document.querySelector('meta[name=csrf-token]').content;
      return (await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': t, 'Content-Type': 'application/x-www-form-urlencoded' }, body: '_method=DELETE' })).status;
    }, `/api/webhooks/${epId}`);
    check.eq(del, 404, 'nor delete it (404)');
    await new Promise((r) => setTimeout(r, 2000));
    check.eq(JSON.stringify(endpointRow(epId)), snap, 'the endpoint is untouched');
    check.eq(deliveriesOf(epId).length, nLog, 'no delivery was created by the other owner');
    check.eq(hits.length, n4, 'and nothing reached the receiver');
    await placeOrder(b, itemsB['e2eB Hook Latte B'], 'Hook Guest B');
    await new Promise((r) => setTimeout(r, 3000));
    check.eq(hits.length, n4, 'an order in the other owner\'s store never reaches this owner\'s endpoint');
  } catch (e) {
    e.message += ` [in section: ${step}]`;
    throw e;
  } finally {
    await Promise.all([owner.close(), other.close(), guest.close()]);
    await new Promise((r) => server.close(r));
    P.tinker(`
      \\Juwri\\Modules\\Integrations\\Models\\WebhookDelivery::whereIn('endpoint_id', ${P.php(created)})->delete();
      \\App\\Models\\WebhookEndpoint::whereIn('id', ${P.php(created)})->delete();
      \\Juwri\\Modules\\Menu\\Models\\Menu::whereIn('id', ${P.php([...Object.values(itemsA), ...Object.values(itemsB)])})->get()->each(fn ($m) => $m->delete());
      echo 'OK=1';`, 'OK');
    P.restoreOptions(a, prevA);
    P.restoreOptions(b, prevB);
  }
  check.done(log);
};
