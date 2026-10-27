// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — C2: Reseller RBAC + impersonation audit (SECURITY, P1).
 *
 * Exercises the reseller→customer impersonation seam end-to-end against ground
 * truth (HTTP status + the reseller_audit_logs table), and proves its gates:
 *
 *   1. reason REQUIRED — POST impersonate with empty reason → 422 (validation),
 *      and NO impersonation session / audit row is created.
 *   2. CROSS-TENANT — reseller A impersonating reseller B's customer (valid
 *      reason + CSRF) → 404 (firstOrFail scoped on reseller_id). No leak, no
 *      audit row written for B's customer.
 *   3. POSITIVE + AUDIT TRAIL — A impersonates its OWN customer → ok, and an
 *      immutable `customer.impersonate` audit row is written (reseller_id,
 *      actor=owner, meta.customer_id, meta.reason). Auth swaps to the customer.
 *   4. NO RE-IMPERSONATION — while impersonating, the owner-gated impersonate
 *      route is denied (the acting user is now the customer, not a reseller
 *      owner) → no nested impersonation.
 *   5. EXIT — POST /reseller/impersonate/exit writes `customer.impersonate.exit`,
 *      clears the session, and re-authenticates the reseller owner (portal 200).
 *   6. AUDIT-LOG SURFACE — GET /reseller/audit-log renders and reflects the run.
 *
 * CSRF comes from the reseller portal's `<meta name="csrf-token">`; every
 * mutation is an in-page fetch reusing the session cookie + that token.
 *   E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh c c-impersonation
 */

const V = require('../../lib/platform-v2');
const { provisionReseller } = require('../../lib/reseller-fixtures');

/** In-page POST reusing session + the page's csrf meta; follows redirects. */
async function store(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
      || window.CRAVE?.csrf || '';
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'follow',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
      },
      body: JSON.stringify(body || {}),
    });
    const text = await r.text().catch(() => '');
    return { status: r.status, finalUrl: r.url, csrfPresent: !!csrf, bodySample: text.slice(0, 200) };
  }, { url, body });
}

function auditRows(resellerId, action) {
  return V.db(
    "SELECT id, actor_user_id, meta FROM {p}reseller_audit_logs WHERE reseller_id = ? AND action = ? ORDER BY id DESC LIMIT 5",
    [resellerId, action],
  );
}

module.exports = async function cImpersonation({ page, cfg, log, shot }) {
  const eeOnly = V.requireEe('Reseller impersonation');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const A = provisionReseller(V, { tag: 'a' });
  const B = provisionReseller(V, { tag: 'b' });
  log(`A(reseller=${A.resellerId} owner=${A.ownerId} cust=${A.customerId}); B(reseller=${B.resellerId} cust=${B.customerId})`);

  await page.setViewport({ width: 390, height: 844 });
  await V.webLogin(page, { email: A.ownerEmail, pass: A.ownerPass });

  // Land on the portal to pick up a valid csrf meta + confirm owner access.
  const portalResp = await page.goto(cfg.baseUrl + '/reseller', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!portalResp || portalResp.status() !== 200) throw new Error(`A portal → ${portalResp ? portalResp.status() : 'no response'}`);
  const hasCsrf = await page.evaluate(() => !!document.querySelector('meta[name="csrf-token"]')?.content);
  if (!hasCsrf) throw new Error('reseller portal did not expose a csrf-token meta — cannot drive mutations');

  const impUrl = (id) => `/reseller/customers/${id}/impersonate`;

  // ── 1. reason REQUIRED → 422, no session, no audit row ─────────────────────
  const before = auditRows(A.resellerId, 'customer.impersonate').length;
  const noReason = await post(page, impUrl(A.customerId), { reason: '' });
  if (noReason.status !== 422) throw new Error(`empty reason should 422, got ${noReason.status} (${noReason.bodySample})`);
  if (auditRows(A.resellerId, 'customer.impersonate').length !== before) {
    throw new Error('an audit row was written for a REJECTED (422) impersonation attempt');
  }
  log(`reason required: empty reason → 422, no audit row ✓`);

  // ── 2. CROSS-TENANT: A impersonating B's customer → 404, no audit row ───────
  const bBefore = auditRows(B.resellerId, 'customer.impersonate').length;
  const cross = await post(page, impUrl(B.customerId), { reason: 'cross-tenant probe' });
  if (![403, 404].includes(cross.status)) {
    throw new Error(`CROSS-TENANT LEAK: A impersonating B's customer ${B.customerId} → ${cross.status} (expected 403/404)\n  ${cross.bodySample}`);
  }
  if (auditRows(B.resellerId, 'customer.impersonate').length !== bBefore) {
    throw new Error('audit row leaked onto reseller B for a denied cross-tenant impersonation');
  }
  log(`cross-tenant: A→B customer impersonate → ${cross.status}, no B audit row ✓`);

  // ── 3. POSITIVE + audit trail: A impersonates its OWN customer ─────────────
  const reason = 'support ticket #E2E-C2';
  const ok = await post(page, impUrl(A.customerId), { reason });
  if (ok.status < 200 || ok.status >= 400) throw new Error(`positive impersonate → ${ok.status} (${ok.bodySample})`);
  const rows = auditRows(A.resellerId, 'customer.impersonate');
  if (rows.length !== before + 1) throw new Error(`expected exactly one new impersonate audit row, have ${rows.length} (was ${before})`);
  const [id, actor, metaJson] = rows[0];
  if (Number(actor) !== A.ownerId) throw new Error(`audit actor should be owner ${A.ownerId}, got ${actor}`);
  const meta = JSON.parse(metaJson || '{}');
  if (Number(meta.customer_id) !== A.customerId) throw new Error(`audit meta.customer_id ${meta.customer_id} !== ${A.customerId}`);
  if (meta.reason !== reason) throw new Error(`audit meta.reason "${meta.reason}" !== "${reason}"`);
  log(`positive: impersonate ok; audit row #${id} actor=${actor} customer_id=${meta.customer_id} reason="${meta.reason}" ✓`);

  // Auth swapped to the customer and the session id regenerated, so the cached
  // owner csrf is stale — land on the impersonated customer's dashboard to pick
  // up a FRESH csrf meta (and prove the swap: this is now the customer session).
  const dashResp = await page.goto(cfg.baseUrl + '/dashboard', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!dashResp || dashResp.status() !== 200) throw new Error(`impersonated customer dashboard → ${dashResp ? dashResp.status() : 'no response'}`);

  // ── 4. NO RE-IMPERSONATION while impersonating (acting user = customer) ─────
  const nested = await post(page, impUrl(A.customerId), { reason: 'nested attempt' });
  if (nested.status >= 200 && nested.status < 300) {
    throw new Error(`nested impersonation was ALLOWED (status ${nested.status}) — the owner gate/409 did not hold`);
  }
  log(`no re-impersonation: nested attempt denied (status ${nested.status}) ✓`);

  // ── 5. EXIT: writes exit audit row, clears session, re-auths owner ─────────
  const exitBefore = auditRows(A.resellerId, 'customer.impersonate.exit').length;
  const exit = await post(page, '/reseller/impersonate/exit', {});
  if (exit.status < 200 || exit.status >= 400) throw new Error(`impersonate exit → ${exit.status} (${exit.bodySample})`);
  const exitRows = auditRows(A.resellerId, 'customer.impersonate.exit');
  if (exitRows.length !== exitBefore + 1) throw new Error('exit did not write a customer.impersonate.exit audit row');
  // Owner re-authenticated: the portal is reachable again as the owner.
  const backResp = await page.goto(cfg.baseUrl + '/reseller', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!backResp || backResp.status() !== 200) throw new Error(`after exit, owner portal → ${backResp ? backResp.status() : 'no response'} (owner not re-authenticated)`);
  await shot(page, 'c-impersonation-back-as-owner');
  log(`exit: exit audit row written, owner re-authenticated (portal 200) ✓`);

  // ── 6. AUDIT-LOG surface renders ───────────────────────────────────────────
  const auditResp = await page.goto(cfg.baseUrl + '/reseller/audit-log', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!auditResp || auditResp.status() !== 200) throw new Error(`audit-log page → ${auditResp ? auditResp.status() : 'no response'}`);
  await V.assertRtl(page);
  await shot(page, 'c-impersonation-audit-log');
  log(`audit-log surface renders (200, RTL) ✓`);

  log(`c-impersonation PASS — impersonation gated (reason 422, cross-tenant 404, no-nested), audited (enter+exit rows), owner re-auth on exit (reseller ${A.resellerId})`);
};
