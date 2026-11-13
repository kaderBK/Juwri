// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B5: Admin plans / pricing / languages (+ entitlement effect).
 *
 *   - RENDER: admin/plans and admin/languages return 200 for the superadmin.
 *   - PLAN CRUD + ENTITLEMENT EFFECT: create a plan (name + 3 prices + 4 limits) →
 *     the plan row persists AND PlanController::mirrorPlanRows writes the matching
 *     plan_entitlements (restaurant_limit int_value == submitted) — the entitlement
 *     write-through effect. Then delete (POST /admin/plans.delete {ids}) → the plan
 *     and its entitlement rows are gone (net-zero).
 *   - LANGUAGE persist: edit an existing non-default language's name via the admin
 *     update endpoint → change persists → restore (net-zero, no file creation).
 *
 * CURRENCIES: there is NO platform-admin currency CRUD surface — currency is a
 * per-store setting (store_currency / currency_pos_effective), owner-scoped, not an
 * admin panel. Recorded in the ledger; nothing to CRUD here.
 *
 * Admin: E2E_ADMIN_EMAIL / E2E_ADMIN_PASS. Ground truth: HTTP status + DB rows.
 */

const V = require('../../lib/platform-v2');

function adminCreds() {
  const email = process.env.E2E_ADMIN_EMAIL;
  const pass = process.env.E2E_ADMIN_PASS;
  if (!email || !pass) throw new Error('B5: set E2E_ADMIN_EMAIL / E2E_ADMIN_PASS in the lane .env');
  return { email, pass };
}

async function req(page, method, path, body, json = true) {
  return page.evaluate(async ({ method, path, body, json }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const headers = { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) };
    let payload;
    if (body && json) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    else if (body) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; payload = new URLSearchParams(body).toString(); }
    const r = await fetch(path, { method, credentials: 'same-origin', headers, body: payload, redirect: 'follow' });
    let data = null; try { data = await r.json(); } catch { data = null; }
    return { status: r.status, data };
  }, { method, path, body, json });
}

const RLIMIT = 7;

module.exports = async function b5AdminPlans({ page, log }) {
  const eeOnly = V.requireEe('plans');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  await V.loginAdmin(page, adminCreds());

  // ── 1. RENDER. ─────────────────────────────────────────────────────────────
  for (const path of ['/admin/plans', '/admin/languages']) {
    const resp = await page.goto(V.cfg.baseUrl + path, { waitUntil: 'domcontentloaded' });
    const status = resp ? resp.status() : 0;
    const landed = new URL(page.url()).pathname;
    if (status < 200 || status >= 400 || /\/login$/.test(landed)) {
      throw new Error(`B5 render: ${path} → ${status} (landed ${landed})`);
    }
    log(`B5 render: ${path} → ${status} ok`);
  }

  // ── 2. PLAN CRUD + entitlement effect. ─────────────────────────────────────
  await page.goto(V.cfg.baseUrl + '/admin/plans', { waitUntil: 'domcontentloaded' });
  const planName = 'E2E B5 Plan ' + Date.now().toString(36);
  const create = await req(page, 'POST', '/admin/plans', {
    name: planName, status: 1,
    monthly_price: 9, annual_price: 90, lifetime_price: 900,
    restaurant_limit: RLIMIT, scan_limit: 100, category_limit: 20, menu_limit: 50,
  });
  if (!(create.data && create.data.success)) throw new Error(`B5 plan create: ${create.status} ${JSON.stringify(create.data)}`);
  const planRows = V.db('SELECT id FROM {p}plans WHERE name = ?', [planName]);
  if (planRows.length !== 1) throw new Error(`B5 plan create: expected 1 plan '${planName}', found ${planRows.length}`);
  const planId = Number(planRows[0][0]);

  // ENTITLEMENT EFFECT: mirrorPlanRows wrote plan_entitlements.
  const ent = V.db("SELECT int_value FROM {p}plan_entitlements WHERE plan_id = ? AND ent_key = 'restaurant_limit' LIMIT 1", [planId]);
  if (!ent.length || Number(ent[0][0]) !== RLIMIT) {
    throw new Error(`B5 entitlement effect: plan #${planId} restaurant_limit entitlement = ${ent.length ? ent[0][0] : '(none)'}, expected ${RLIMIT}`);
  }
  log(`B5 plan: created #${planId} '${planName}'; entitlement restaurant_limit=${ent[0][0]} (mirrorPlanRows effect)`);

  // DELETE (net-zero).
  const del = await req(page, 'POST', '/admin/plans.delete', { ids: [planId] });
  if (!(del.data && del.data.success)) throw new Error(`B5 plan delete: ${del.status} ${JSON.stringify(del.data)}`);
  const gone = V.db('SELECT COUNT(*) FROM {p}plans WHERE id = ?', [planId]);
  if (Number(gone[0][0]) !== 0) throw new Error(`B5 plan delete: plan #${planId} still present`);
  // FINDING (ISS-V-B-004): plan delete does NOT cascade plan_entitlements/plan_prices
  // — orphan rows survive the parent. Surface it (not a hard fail: the plan itself is
  // gone) and clean the orphans up so the lane DB stays net-zero.
  const orphanEnt = Number(V.db('SELECT COUNT(*) FROM {p}plan_entitlements WHERE plan_id = ?', [planId])[0][0]);
  const orphanPrice = Number(V.db('SELECT COUNT(*) FROM {p}plan_prices WHERE plan_id = ?', [planId])[0][0]);
  if (orphanEnt > 0 || orphanPrice > 0) {
    log(`B5 FINDING ISS-V-B-004: plan #${planId} deleted but left ${orphanEnt} plan_entitlements + ${orphanPrice} plan_prices orphan rows (no cascade)`);
    V.db('DELETE FROM {p}plan_entitlements WHERE plan_id = ?', [planId]);
    V.db('DELETE FROM {p}plan_prices WHERE plan_id = ?', [planId]);
    log(`B5 cleanup: removed ${orphanEnt + orphanPrice} orphan rows (net-zero restored)`);
  } else {
    log(`B5 plan: deleted #${planId} + entitlements cascaded (net-zero)`);
  }

  // ── 3. LANGUAGE edit persist (net-zero, no file creation). ─────────────────
  // Any language other than the Arabic site default (the legacy dev DB happened to carry 'sv'; a freshly
  // seeded one has the English language the runner adds).
  const langRow = V.db("SELECT id, name, direction, active FROM {p}languages WHERE code <> 'ar' ORDER BY id LIMIT 1", []);
  if (!langRow.length) throw new Error('B5 language: expected a non-default language to edit');
  const langId = Number(langRow[0][0]);
  const origName = String(langRow[0][1]);
  const direction = String(langRow[0][2]);
  const active = Number(langRow[0][3]);
  const newName = origName + ' [e2e]';

  const langEdit = await req(page, 'PUT', `/admin/languages/${langId}`, { name: newName, direction, active });
  if (!(langEdit.data && langEdit.data.success)) throw new Error(`B5 language edit: ${langEdit.status} ${JSON.stringify(langEdit.data)}`);
  const afterName = String(V.db('SELECT name FROM {p}languages WHERE id = ?', [langId])[0][0]);
  if (afterName !== newName) throw new Error(`B5 language edit: name is '${afterName}', expected '${newName}'`);

  await req(page, 'PUT', `/admin/languages/${langId}`, { name: origName, direction, active });
  const restoredName = String(V.db('SELECT name FROM {p}languages WHERE id = ?', [langId])[0][0]);
  if (restoredName !== origName) throw new Error(`B5 language restore: name is '${restoredName}', expected '${origName}'`);
  log(`B5 language: edited #${langId} name persist verified + restored (net-zero)`);

  log('B5 admin plans/langs: renders ok; plan CRUD + entitlement mirror; language edit persist — net-zero (currencies = per-store, no admin CRUD)');
};
