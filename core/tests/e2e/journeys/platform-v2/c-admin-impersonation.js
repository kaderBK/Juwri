// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — C5: Admin impersonation ("login as user") + audit (P1).
 *
 * The superadmin can act as any owner via POST admin/users/{user}/login
 * (Admin\UserController@loginAsUser). v2 proves the whole seam against ground
 * truth (activity_logs) and its gates:
 *
 *   1. RBAC GATE — a NON-admin owner cannot reach the impersonation endpoint:
 *      POST admin/users/{id}/login as a plain owner writes NO admin.impersonate
 *      row and does not swap auth (the admin middleware holds).
 *   2. POSITIVE + AUDIT — admin impersonates a target owner → an
 *      `admin.impersonate` activity row is written BEFORE the auth switch
 *      (actor = admin, subject/meta.target_id = owner), auth swaps to the owner,
 *      /dashboard is reachable AS the owner.
 *   3. SCOPE — while impersonating, the acting user is the OWNER, so the admin
 *      panel is NOT reachable (no retained admin power).
 *   4. EXIT — POST /logout writes `admin.impersonate_exit` (meta.admin_id) and
 *      re-authenticates the admin (admin panel reachable again).
 *
 * CSRF is read from each page's `<meta name="csrf-token">` after navigation, so
 * every mutation uses a token valid for the CURRENT session (the session id
 * regenerates on each auth switch). ar (default).
 *   E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh c c-admin-impersonation
 */

const V = require('../../lib/platform-v2');

const TENANT_B_OWNER_EMAIL = 'e2e-tenantb-owner@example.test';
const TENANT_B_OWNER_PASS = 'E2eTenantB-pass';

async function store(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'follow',
      headers: { Accept: 'text/html,application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: JSON.stringify(body || {}),
    });
    return { status: r.status, finalUrl: r.url, csrfPresent: !!csrf };
  }, { url, body });
}

// No LIMIT: the shared test DB accumulates rows across runs, so a capped query
// (the old LIMIT 5) made a "+1 delta" impossible once ≥5 rows existed. Callers
// use maxImpersonateId() for an accumulation-proof "a NEW row appeared" check.
function impersonateRows(action, targetId) {
  return V.db(
    "SELECT id, actor_user_id, subject_id, meta FROM {p}activity_logs WHERE action = ? AND subject_id = ? ORDER BY id DESC",
    [action, targetId],
  );
}

function maxImpersonateId(action, targetId) {
  const rows = V.db("SELECT COALESCE(MAX(id), 0) FROM {p}activity_logs WHERE action = ? AND subject_id = ?", [action, targetId]);
  return rows.length ? Number(rows[0][0]) : 0;
}

module.exports = async function cAdminImpersonation({ page, cfg, log, shot }) {
  const adminEmail = process.env.E2E_ADMIN_EMAIL;
  const adminPass = process.env.E2E_ADMIN_PASS;
  if (!adminEmail || !adminPass) throw new Error('c-admin-impersonation: E2E_ADMIN_EMAIL/PASS not exported');

  const adminId = Number(V.db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [adminEmail])[0][0]);
  const targetId = Number(V.db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [TENANT_B_OWNER_EMAIL])[0][0]);
  if (!targetId) throw new Error('tenant-B owner missing — run E2ePlatformV2Seeder');
  log(`admin=${adminId} target-owner=${targetId}`);

  await page.setViewport({ width: 390, height: 844 });
  const loginUrl = (id) => `/admin/users/${id}/login`;

  // ── 1. RBAC GATE: a plain owner cannot impersonate ─────────────────────────
  const gateBefore = impersonateRows('admin.impersonate', targetId).length;
  await V.webLogin(page, { email: TENANT_B_OWNER_EMAIL, pass: TENANT_B_OWNER_PASS });
  await page.goto(cfg.baseUrl + '/dashboard', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  const asOwner = await post(page, loginUrl(adminId), {});
  // Ground truth: the owner attempt wrote NO impersonate row (gate held), whatever
  // the HTTP shape of the refusal (403 / redirect / 419).
  if (impersonateRows('admin.impersonate', adminId).length > 0) {
    // adminId as a target would only exist if the owner actually impersonated the admin.
    throw new Error('RBAC GATE FAILED: a plain owner created an admin.impersonate row targeting the admin');
  }
  if (impersonateRows('admin.impersonate', targetId).length !== gateBefore) {
    throw new Error('RBAC GATE FAILED: a plain owner mutated the impersonation audit trail');
  }
  log(`RBAC gate: plain owner POST admin/users/${adminId}/login → ${asOwner.status}, no audit row (gate held) ✓`);

  // ── 2. POSITIVE + AUDIT: admin impersonates the target owner ───────────────
  // Accumulation-proof: assert a row with id > the pre-impersonation max appears
  // (a genuinely NEW row), rather than a count delta that a shared DB defeats.
  const beforeMax = maxImpersonateId('admin.impersonate', targetId);
  await V.loginAdmin(page, { email: adminEmail, pass: adminPass });
  const usersResp = await page.goto(cfg.baseUrl + '/admin/users', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!usersResp || usersResp.status() !== 200) throw new Error(`admin /admin/users → ${usersResp ? usersResp.status() : 'no response'}`);
  const imp = await post(page, loginUrl(targetId), { reason: 'e2e support check' }); // AUTH-8: a reason is required
  if (imp.status < 200 || imp.status >= 400) throw new Error(`admin impersonate → ${imp.status}`);
  const rows = impersonateRows('admin.impersonate', targetId);
  if (!rows.length || Number(rows[0][0]) <= beforeMax) throw new Error(`expected a NEW admin.impersonate row (newest id ${rows[0] ? rows[0][0] : 'none'} <= before ${beforeMax})`);
  const [rowId, actor, subj, metaJson] = rows[0];
  if (Number(actor) !== adminId) throw new Error(`impersonate audit actor should be admin ${adminId}, got ${actor} (must log BEFORE auth switch)`);
  const meta = JSON.parse(metaJson || '{}');
  if (Number(meta.target_id) !== targetId) throw new Error(`meta.target_id ${meta.target_id} !== ${targetId}`);
  if (Number(meta.admin_id) !== adminId) throw new Error(`meta.admin_id ${meta.admin_id} !== ${adminId}`);
  // Auth swapped: /dashboard reachable as the owner.
  const dashResp = await page.goto(cfg.baseUrl + '/dashboard', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!dashResp || dashResp.status() !== 200) throw new Error(`post-impersonate /dashboard → ${dashResp ? dashResp.status() : 'no response'}`);
  await shot(page, 'c-admin-impersonation-as-owner');
  log(`positive: admin.impersonate row #${rowId} actor=${actor} subj=${subj} target=${meta.target_id}; dashboard as owner 200 ✓`);

  // ── 3. SCOPE: no retained admin power while impersonating ──────────────────
  const adminProbe = await V.probe(page, '/admin/users');
  const adminReachable = adminProbe.status >= 200 && adminProbe.status < 300 && !adminProbe.redirectedToAuth
    && !/\/(login|dashboard)(\?|#|$)/.test(adminProbe.finalUrl);
  if (adminReachable) {
    throw new Error(`SCOPE LEAK: admin panel reachable while impersonating an owner (status ${adminProbe.status}, final ${adminProbe.finalUrl})`);
  }
  log(`scope: admin panel NOT reachable while acting as owner (status ${adminProbe.status}) ✓`);

  // ── 4. EXIT: /logout re-authenticates the admin + audits the exit ──────────
  const exitBefore = impersonateRows('admin.impersonate_exit', adminId).length;
  const exit = await post(page, '/logout', {});
  if (exit.status < 200 || exit.status >= 400) throw new Error(`impersonate exit (logout) → ${exit.status}`);
  const exitRows = impersonateRows('admin.impersonate_exit', adminId);
  if (exitRows.length !== exitBefore + 1) throw new Error('logout did not write an admin.impersonate_exit row');
  const exitMeta = JSON.parse(exitRows[0][3] || '{}');
  if (Number(exitMeta.admin_id) !== adminId) throw new Error(`exit meta.admin_id ${exitMeta.admin_id} !== ${adminId}`);
  // Admin re-authenticated: the admin panel is reachable again.
  const backResp = await page.goto(cfg.baseUrl + '/admin/users', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!backResp || backResp.status() !== 200) throw new Error(`after exit, /admin/users → ${backResp ? backResp.status() : 'no response'} (admin not re-authenticated)`);
  await shot(page, 'c-admin-impersonation-back-as-admin');
  log(`exit: admin.impersonate_exit row written, admin re-authenticated (/admin/users 200) ✓`);

  log(`c-admin-impersonation PASS — RBAC gate holds, admin.impersonate audited (actor=admin, pre-switch), owner-scope enforced, audited exit re-auths admin (admin=${adminId} target=${targetId})`);
};
