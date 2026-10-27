// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B7: Owner IDOR / tenant isolation.
 *
 * Tenant-A owner (showcase-demo@example.com — owns crave-demo crave-demo and the 18
 * other showcase stores) must NEVER reach tenant-B's island (E2ePlatformV2Seeder:
 * owner e2e-tenantb-owner, store tenantb-demo, victim order #<track> and
 * customer #<id>). We assert:
 *
 *   - POSITIVE CONTROL: owner A DOES reach their OWN store (crave-demo show + orders) —
 *     so a blanket 403 can't masquerade as isolation.
 *   - READ IDOR: owner A on tenant-B's restaurant/menu/edit/orders/customer/order
 *     routes is denied (403/404 or auth bounce) — via idorProbe (throws on LEAK).
 *   - WRITE IDOR: owner A POSTs an order-status change to tenant-B's order WITH a
 *     valid CSRF token from their own dashboard — must be denied, never 2xx.
 *   - API IDOR: the owner API order-show for tenant-B (api/v1/stores/{id}/
 *     orders/{orderId}) is denied.
 *
 * Ground truth only (HTTP status). Runs in ar and (E2E_LOCALE=en).
 */

const V = require('../../lib/platform-v2');

const A = { email: 'showcase-demo@example.com', pass: 'showcase-demo-pass' }; // tenant-A owner
let OWN_STORE = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)

// Resolve the concrete tenant-B victim ids from the seeded track token so the
// journey is robust to id drift across lane DBs.
function tenantBVictims() {
  const rows = V.db(
    "SELECT id, customer_id, store_id FROM {p}orders WHERE track_token = ? LIMIT 1",
    ['e2eV2tenantBorderTRACKtoken0001'],
  );
  if (!rows.length) throw new Error('B7: tenant-B victim order not found — run E2ePlatformV2Seeder');
  return { orderId: Number(rows[0][0]), customerId: Number(rows[0][1]), storeId: Number(rows[0][2]) };
}

module.exports = async function b7OwnerIdor({ page, log }) {
  OWN_STORE = V.craveStoreId();
  const V_ = tenantBVictims();
  log(`B7 victims: order #${V_.orderId}, customer #${V_.customerId}, store #${V_.storeId}`);

  // ── Login as tenant-A owner. ───────────────────────────────────────────────
  await V.loginOwner(page, A);

  // ── POSITIVE CONTROL: owner A reaches their OWN store. ─────────────────────
  for (const url of [`/stores/${OWN_STORE}`, `/orders/${OWN_STORE}`]) {
    const r = await V.probe(page, url);
    if (!(r.status >= 200 && r.status < 300) || r.redirectedToAuth) {
      throw new Error(`B7 positive-control FAIL: owner A cannot reach own ${url} → ${r.status} (final ${r.finalUrl})`);
    }
    log(`B7 positive control: own ${url} → ${r.status} ALLOW`);
  }

  // ── READ IDOR: owner A on tenant-B resources → denied. ─────────────────────
  const readTargets = [
    { url: `/stores/${V_.storeId}`,        label: 'B store show' },
    { url: `/stores/${V_.storeId}/menu`,   label: 'B store menu' },
    { url: `/stores/${V_.storeId}/edit`,   label: 'B store edit' },
    { url: `/orders/${V_.storeId}`,             label: 'B orders list' },
    { url: `/customers/${V_.customerId}`,            label: 'B customer show' },
    { url: `/orders/${V_.orderId}/split`,            label: 'B order split' },
  ];
  for (const t of readTargets) {
    const r = await V.idorProbe(page, { targetUrl: t.url, expect: [403, 404], asUser: null });
    log(`B7 read IDOR: A → ${t.label} ${t.url} → ${r.status} DENIED`);
  }

  // ── WRITE IDOR: owner A mutates tenant-B's order status WITH valid CSRF. ────
  // Land on A's own dashboard to obtain a real CSRF token, then POST cross-tenant.
  await page.goto(V.cfg.baseUrl + '/dashboard', { waitUntil: 'domcontentloaded' });
  const write = await page.evaluate(async ({ orderId }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch(`/order/${orderId}/status`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
      },
      body: JSON.stringify({ status: 'completed' }),
    });
    return { status: r.status, hadCsrf: !!csrf, body: (await r.text().catch(() => '')).slice(0, 160) };
  }, { orderId: V_.orderId });
  if (!write.hadCsrf) throw new Error('B7 write IDOR: no CSRF token on owner dashboard — cannot make a fair write probe');
  if (write.status >= 200 && write.status < 300) {
    throw new Error(`B7 write IDOR LEAK: owner A changed tenant-B order #${V_.orderId} status → ${write.status} ${write.body}`);
  }
  log(`B7 write IDOR: A POST /order/${V_.orderId}/status (csrf ok) → ${write.status} DENIED`);

  // Verify the victim order was NOT actually mutated (defense-in-depth: a 200 is
  // not the only leak — a silent write behind a redirect would be too).
  const after = V.db("SELECT status FROM {p}orders WHERE id = ?", [V_.orderId]);
  if (String(after[0][0]) === 'completed') {
    throw new Error(`B7 write IDOR LEAK: tenant-B order #${V_.orderId} status is now 'completed' after A's POST`);
  }
  log(`B7 write IDOR: tenant-B order status unchanged (='${after[0][0]}')`);

  // ── API IDOR: owner API order-show for tenant-B → denied. ──────────────────
  const apiUrl = `/api/v1/stores/${V_.storeId}/orders/${V_.orderId}`;
  const api = await V.probe(page, apiUrl);
  const apiDenied = [401, 403, 404].includes(api.status) || api.redirectedToAuth;
  if (!apiDenied) {
    throw new Error(`B7 API IDOR LEAK: ${apiUrl} → ${api.status} (final ${api.finalUrl}) body=${(api.bodySample || '').replace(/\s+/g, ' ')}`);
  }
  log(`B7 API IDOR: A → ${apiUrl} → ${api.status} DENIED`);

  log('B7 owner IDOR: positive control ok; all tenant-B read/write/api access denied');
};
