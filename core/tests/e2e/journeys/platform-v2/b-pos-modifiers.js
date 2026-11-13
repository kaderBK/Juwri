// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B10: POS required-modifier enforcement (+ KDS station note).
 *
 * The register (PosController@store → PosService::createOrder → OrderApiService::
 * create → resolveLines) enforces the item_modifier_group pivot's
 * required/min_select at ORDER TIME. This proves the server rejects an order that
 * omits a required modifier and accepts it once chosen — the client cannot skip it:
 *
 *   - REJECT: ordering the required-modifier item WITHOUT a selection → 422, the
 *     error names the required group ("Choose at least 1 …").
 *   - ACCEPT: the SAME item WITH a valid selection → 2xx (order created).
 *   - CONTROL: a plain item with NO required group orders fine → 2xx (so the reject
 *     is the modifier rule, not a broken register).
 *
 * Fixtures: E2ePosModifierDemoSeeder (required group "E2E Required Size" on a
 * crave-demo item) + the V0.5 staff matrix (manager holds `pos`). FIXED client_uuid
 * per case → idempotent/re-runnable.
 *
 * KDS MULTI-STATION routing: DEFERRED — kitchen_stations and branch_menu_station
 * exist but are EMPTY on every store (no station fixtures), so there is nothing to
 * route to. Would need a station + category→station map seeder + a KDS-board
 * assertion; recorded in the ledger.
 *
 * Staff: e2e-staff-manager@example.test / E2eStaff-pass (crave-demo crave-demo).
 */

const V = require('../../lib/platform-v2');

const OWNER = { email: 'showcase-demo@example.com', pass: 'showcase-demo-pass' };
let STORE = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)
const GROUP_TITLE = 'E2E Required Size';

function requiredItem() {
  const g = V.db("SELECT id, menu_id FROM {p}modifier_groups WHERE kind = 'variant' AND store_id = ? AND title = ? LIMIT 1", [STORE, GROUP_TITLE]);
  if (!g.length) throw new Error('B10 BLOCKED: required modifier group not found — run E2ePosModifierDemoSeeder');
  return { groupId: Number(g[0][0]), itemId: Number(g[0][1]) };
}
function plainItem(excludeId) {
  const r = V.db("SELECT m.id FROM {p}items m WHERE m.store_id = ? AND m.active = '1' AND m.deleted_at IS NULL AND m.id <> ? "
    + "AND NOT EXISTS (SELECT 1 FROM {p}item_modifier_group piv JOIN {p}modifier_groups g ON g.id = piv.modifier_group_id AND piv.modifier_id IS NULL "
    + "WHERE piv.menu_id = m.id AND (piv.required = 1 OR g.required = 1)) ORDER BY m.id LIMIT 1", [STORE, excludeId]);
  if (!r.length) throw new Error('B10: no plain (no-required-modifier) control item found');
  return Number(r[0][0]);
}

async function createOrder(page, payload) {
  return page.evaluate(async ({ payload, store }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch('/pos/orders?store=' + store, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: JSON.stringify(payload),
    });
    let body = null; try { body = await r.json(); } catch { body = null; }
    return { status: r.status, body };
  }, { payload, store: STORE });
}

module.exports = async function b10PosModifiers({ page, log }) {
  STORE = V.craveStoreId();
  const { groupId, itemId } = requiredItem();
  const control = plainItem(itemId);
  log(`B10 fixtures: required item #${itemId} (group #${groupId}), control item #${control}`);

  await V.loginOwner(page, OWNER);
  const boot = await page.goto(V.cfg.baseUrl + '/pos?store=' + STORE, { waitUntil: 'networkidle2' });
  if (!boot || boot.status() >= 400) throw new Error(`B10: /pos → ${boot ? boot.status() : 'no response'} (pos_enabled?)`);
  const rid = await page.evaluate(() => window.PosBoot && window.PosBoot.storeId);
  log(`B10 register: PosBoot.storeId=${rid} (want ${STORE})`);

  // ── 1. REJECT: required item, no selection → 422 naming the group. ─────────
  const reject = await createOrder(page, {
    type: 'takeaway', client_uuid: '00000000-0000-7000-8000-000000b10001', // fixed UUID (J27-A2)
    items: [{ item_id: itemId, quantity: 1 }],
  });
  if (reject.status !== 422) {
    throw new Error(`B10 REJECT: expected 422 for a missing required modifier, got ${reject.status} ${JSON.stringify(reject.body)}`);
  }
  const errStr = JSON.stringify(reject.body && reject.body.errors ? reject.body.errors : reject.body || {});
  if (!/choose at least|required|E2E Required Size/i.test(errStr)) {
    throw new Error(`B10 REJECT: 422 but the error does not name the required group: ${errStr}`);
  }
  log(`B10 reject: required item without selection → 422 (${errStr.replace(/\s+/g, ' ').slice(0, 120)})`);

  // ── 2. ACCEPT: same item WITH the required selection → 2xx. ─────────────────
  const accept = await createOrder(page, {
    type: 'takeaway', client_uuid: '00000000-0000-7000-8000-000000b10002',
    items: [{ item_id: itemId, quantity: 1, options: [{ group_id: groupId, selected: ['Small'] }] }],
  });
  if (!(accept.status >= 200 && accept.status < 300)) {
    throw new Error(`B10 ACCEPT: expected 2xx with the required selection, got ${accept.status} ${JSON.stringify(accept.body)}`);
  }
  log(`B10 accept: required item WITH 'Small' → ${accept.status} (order created)`);

  // ── 3. CONTROL: a plain item orders fine (reject was the modifier rule). ────
  const ctrl = await createOrder(page, {
    type: 'takeaway', client_uuid: '00000000-0000-7000-8000-000000b10003',
    items: [{ item_id: control, quantity: 1 }],
  });
  if (!(ctrl.status >= 200 && ctrl.status < 300)) {
    throw new Error(`B10 CONTROL: plain item should order (2xx), got ${ctrl.status} ${JSON.stringify(ctrl.body)}`);
  }
  log(`B10 control: plain item #${control} → ${ctrl.status} (register is healthy; rejection was the required-modifier rule)`);

  log('B10 POS required-modifier: server rejects a missing required modifier (422), accepts it when chosen, plain items unaffected. KDS multi-station = DEFERRED (no station fixtures)');
};
