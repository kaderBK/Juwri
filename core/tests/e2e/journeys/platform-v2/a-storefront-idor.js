// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A2 — Storefront IDOR / customer-scope tenant isolation (Platform E2E v2, Lane A).
 *
 * A signed-in customer of tenant-A (crave-demo) must not be able to read or act
 * on tenant-B's customer data. The victims are seeded by E2ePlatformV2Seeder:
 * tenant-B store `tenantb-demo`, customer `e2e-tenantb-customer@example.test`,
 * and a paid order with the known track token `e2eV2tenantBorderTRACKtoken0001`.
 * The attacker is `e2e-a-attacker@example.test` (E2eLaneAFixturesSeeder), a real
 * crave-demo customer.
 *
 * Probes (each with a POSITIVE control so a 403 is real scoping, not blanket denial):
 *   1. reorder — the attacker reorders their OWN order (200 success) but the
 *      victim's order id → 403 (customer_id + restaurant fence in reorder()).
 *   2. track — the capability is the unguessable TOKEN, not the order id:
 *      the real victim token → 200 (public by design), a guessed token → 404,
 *      and the victim's numeric order id used as a token → 404.
 *   3. guest order-lookup is tenant-scoped: looking up the victim's own email on
 *      the crave-demo endpoint must NOT return the victim's order.
 *
 * Run: `E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh a a-storefront-idor`
 */

const V = require('../../lib/platform-v2');
const K = require('../../lib/order-kit-v2');

const ATTACKER = { email: 'e2e-a-attacker@example.test', pass: 'E2eAttackerA123' };
const VICTIM_TRACK = 'e2eV2tenantBorderTRACKtoken0001';
const VICTIM_EMAIL = 'e2e-tenantb-customer@example.test';
const STORE = 'crave-demo';

module.exports = async function a2StorefrontIdor({ page, log, shot }) {
  V.resetRateLimits(); // one IP places every order of the pack: start each journey with a clean 10/min window
  const checks = [];
  const ok = (name) => { checks.push(name); log(`  a2 ✓ ${name}`); };

  // Resolve the victim order id (numeric) from the seeded track token.
  const vrows = V.db('SELECT id, store_id, customer_id FROM {p}orders WHERE track_token = ? LIMIT 1', [VICTIM_TRACK]);
  if (!vrows.length) throw new Error(`victim order (track ${VICTIM_TRACK}) not seeded — run E2ePlatformV2Seeder`);
  const victimOrderId = parseInt(vrows[0][0], 10);
  const victimRestaurant = parseInt(vrows[0][1], 10);
  log(`  a2 · victim order #${victimOrderId} (restaurant #${victimRestaurant}), attacker=${ATTACKER.email}`);

  // Sign in as the attacker customer on crave-demo and place an OWN order.
  let rt = await K.loadRuntime(page, V.enUrl('/' + STORE), { slug: STORE });
  await V.loginCustomer(page, { email: ATTACKER.email, pass: ATTACKER.pass, slug: STORE });
  // Login regenerates the session (and thus the CSRF token) — reload the runtime
  // so rt.csrf is the post-login token, else every subsequent POST is a 419.
  rt = await K.loadRuntime(page, V.enUrl('/' + STORE), { slug: STORE });
  ok(`attacker signed in as a crave-demo customer`);

  const ownRes = await K.submitOrder(page, {
    rt,
    items: K.pickSimpleLines(rt, { count: 1, quantity: 1 }),
    fields: { name: 'E2E Attacker A', 'phone-number': '+966500000111', client_uuid: V.uuid7() },
  });
  if (!ownRes.success || !ownRes.track_url) throw new Error(`attacker own order failed: ${JSON.stringify(ownRes)}`);
  const ownToken = K.trackToken(ownRes.track_url);
  const ownRows = V.db('SELECT id FROM {p}orders WHERE track_token = ? LIMIT 1', [ownToken]);
  const ownOrderId = parseInt(ownRows[0][0], 10);
  ok(`attacker placed own order #${ownOrderId}`);

  // In-page POST helper reusing the session + CRAVE csrf.
  const reorder = (orderId) => page.evaluate(async ({ slug, orderId, csrf }) => {
    const r = await fetch(`/${slug}/customer/orders/${orderId}/reorder`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    });
    let body = null; try { body = await r.json(); } catch (_e) { body = null; }
    return { status: r.status, success: body ? !!body.success : false };
  }, { slug: STORE, orderId, csrf: rt.csrf });

  // 1a. POSITIVE control — the endpoint must ALLOW the attacker to their OWN
  // order (not 403), so the victim's 403 below is real customer/tenant scoping
  // and not a blanket denial. Reaching the handler (200) is the control; the
  // body's success flag depends on item availability at reorder time (a
  // just-placed item can resolve to "no longer available" — success:false with
  // 200), which is orthogonal to the authorization boundary being probed.
  const ownReorder = await reorder(ownOrderId);
  if (ownReorder.status === 403 || ownReorder.status === 404) {
    throw new Error(`control failed: attacker cannot reach own order #${ownOrderId} reorder (status ${ownReorder.status}) — the 403 below would be meaningless`);
  }
  ok(`control: attacker reorder of OWN order #${ownOrderId} allowed (status ${ownReorder.status}) → victim 403 is real scoping`);

  // 1b. IDOR — reorder the victim's order must be denied.
  const victimReorder = await reorder(victimOrderId);
  if (victimReorder.status !== 403) {
    throw new Error(`IDOR LEAK: reorder victim order #${victimOrderId} → ${victimReorder.status} (expected 403)`);
  }
  ok(`reorder victim order #${victimOrderId} → 403 (cross-customer/tenant denied)`);

  // 2. Track page — the token is the capability, not the id.
  const trackProbe = (tok) => V.probe(page, `/order/track/${tok}`);
  const real = await trackProbe(VICTIM_TRACK);
  if (real.status !== 200) throw new Error(`victim real token should render (public-by-token) but got ${real.status}`);
  ok(`victim real track token → 200 (capability = the secret token)`);

  const guessed = await trackProbe('e2eV2GUESSEDtokenDOESNOTexist0000');
  if (guessed.status !== 404) throw new Error(`guessed track token → ${guessed.status} (expected 404)`);
  ok(`guessed track token → 404`);

  const byId = await trackProbe(String(victimOrderId));
  if (byId.status !== 404) throw new Error(`IDOR: victim order id ${victimOrderId} used as a track token → ${byId.status} (expected 404 — an order must not be reachable by its numeric id)`);
  ok(`victim numeric order id as track token → 404 (not id-addressable)`);

  // 3. Guest order-lookup is tenant-scoped — the victim's email on crave-demo
  //    must not surface the victim's order.
  const lookup = await page.evaluate(async ({ slug, identifier, csrf }) => {
    const r = await fetch(`/${slug}/order-lookup`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ identifier }),
    });
    let body = null; try { body = await r.json(); } catch (_e) { body = null; }
    return { status: r.status, raw: JSON.stringify(body || {}) };
  }, { slug: STORE, identifier: VICTIM_EMAIL, csrf: rt.csrf });
  if (lookup.raw.includes(VICTIM_TRACK) || lookup.raw.includes(String(victimOrderId))) {
    throw new Error(`IDOR LEAK: order-lookup on ${STORE} returned tenant-B's order for ${VICTIM_EMAIL}: ${lookup.raw.slice(0, 300)}`);
  }
  ok(`guest order-lookup on ${STORE} for victim email → no tenant-B order (tenant-scoped)`);

  await shot(page, 'a2-idor-done');
  log(`A2: storefront IDOR — ${checks.length} cross-tenant/customer denials verified (reorder, track-by-token, order-lookup)`);
};
