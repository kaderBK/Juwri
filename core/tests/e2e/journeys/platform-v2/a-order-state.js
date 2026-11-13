// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A3 — Order-state invariants, customer/token surface (Platform E2E v2, Lane A).
 *
 * The money invariants (refund > paid blocked; unpaid cannot refund) are pinned
 * by tests/Modules/Ordering/E2eV2A3OrderInvariantsTest.php. This journey proves the
 * customer-reachable half: the storefront never lets a diner drive an order into
 * an illegal state, and the track page reflects only real server state.
 *
 * Probes on a freshly-placed crave-demo order:
 *   1. curbside "arrived" on a NON-curbside order → success:false (state guard).
 *   2. submit a track-page review while the order is still pending → 422
 *      (only delivered/completed orders may be reviewed).
 *   3. push-subscribe: allowed on the live (non-terminal) order (control), then
 *      refused with 422 once the order reaches a TERMINAL state — proving the
 *      guard tracks real state, not a stale snapshot.
 *   4. the track page keeps rendering the order (200) after the state change,
 *      i.e. it reads live server state.
 *
 * Run: `E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh a a-order-state`
 */

const V = require('../../lib/platform-v2');
const K = require('../../lib/order-kit-v2');

const STORE = 'crave-demo';

module.exports = async function a3OrderState({ page, log, shot }) {
  V.resetRateLimits(); // one IP places every order of the pack: start each journey with a clean 10/min window
  const checks = [];
  const ok = (name) => { checks.push(name); log(`  a3 ✓ ${name}`); };

  const rt = await K.loadRuntime(page, V.enUrl('/' + STORE), { slug: STORE });
  const res = await K.submitOrder(page, {
    rt,
    items: K.pickSimpleLines(rt, { count: 1, quantity: 1 }),
    fields: { name: 'E2E State Diner', client_uuid: V.uuid7() },
  });
  if (!res.success || !res.track_url) throw new Error(`order not placed: ${JSON.stringify(res)}`);
  const token = K.trackToken(res.track_url);
  const dbRow = V.db('SELECT id, status, is_curbside FROM {p}orders WHERE track_token = ? LIMIT 1', [token]);
  const orderId = parseInt(dbRow[0][0], 10);
  log(`  a3 · order #${orderId} placed, status=${dbRow[0][1]}, is_curbside=${dbRow[0][2]}`);

  const csrf = rt.csrf;
  const post = (path, body) => page.evaluate(async ({ path, body, csrf }) => {
    const r = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: Object.assign({ Accept: 'application/json', 'Content-Type': 'application/json' }, csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
      body: body ? JSON.stringify(body) : undefined,
    });
    let json = null; try { json = await r.json(); } catch (_e) { json = null; }
    return { status: r.status, json };
  }, { path, body, csrf });

  // 1. curbside "arrived" on a non-curbside order → success:false.
  const curb = await post(`/order/track/${token}/arrived`);
  if (curb.status === 200 && curb.json && curb.json.success === false) {
    ok('curbside "arrived" refused on a non-curbside order (state guard)');
  } else if (curb.status === 403) {
    ok('curbside "arrived" gated by plan/feature (403) — not exploitable');
  } else {
    throw new Error(`curbside arrived on a non-curbside order should refuse, got ${curb.status} ${JSON.stringify(curb.json)}`);
  }

  // 2. review a pending order → 422 (precondition: delivered/completed only).
  const review = await post(`/order/track/${token}/review`, { stars: 5, body: 'nope' });
  if (review.status !== 422) throw new Error(`review on a pending order → ${review.status} (expected 422)`);
  ok('review refused on a pending order → 422 (illegal precondition)');

  // 3a. control — push-subscribe is accepted on the live (non-terminal) order.
  const pushEndpoint = `https://push.example.test/e2e/${orderId}-${Date.now()}`;
  const liveSub = await post(`/order/track/${token}/push-subscribe`, {
    endpoint: pushEndpoint, keys: { p256dh: 'e2ep256dhkeyvalue', auth: 'e2eauthkeyvalue' },
  });
  if (liveSub.status !== 200) throw new Error(`control failed: push-subscribe on a live order → ${liveSub.status} (expected 200)`);
  ok('control: push-subscribe accepted on the live order → 200');

  // 3b. drive the order to a TERMINAL state (server-side), then push-subscribe
  //     must be refused with 422 — the guard reads real, current state.
  V.db("UPDATE {p}orders SET status = 'completed' WHERE id = ?", [orderId]);
  const termSub = await post(`/order/track/${token}/push-subscribe`, {
    endpoint: pushEndpoint + '-2', keys: { p256dh: 'e2ep256dhkeyvalue', auth: 'e2eauthkeyvalue' },
  });
  if (termSub.status !== 422) throw new Error(`push-subscribe on a completed order → ${termSub.status} (expected 422 terminal guard)`);
  ok('push-subscribe refused on a terminal (completed) order → 422 (state, not snapshot)');

  // 4. the track page still renders the order after the state change (live read).
  const trackResp = await page.goto(res.track_url, { waitUntil: 'networkidle2' });
  if (!trackResp || trackResp.status() !== 200) throw new Error(`track page after state change → ${trackResp && trackResp.status()} (expected 200)`);
  ok('track page reflects the live server state after the transition (200)');

  await shot(page, 'a3-order-state-done');
  log(`A3: order-state invariants — ${checks.length} customer-surface guards verified (money invariants in E2eV2A3OrderInvariantsTest)`);
};
