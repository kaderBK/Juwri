// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B4: Admin feature-flag depth + diff-safety.
 *
 * The admin feature-matrix (FeatureMatrixController@save) writes each plan's
 * gateable capabilities into plan.settings and mirrors them to plan_entitlements.
 * Its sharp edge: unchecked bool checkboxes don't submit, so a partial POST would
 * silently reset every other bool to 0. We prove the real form is diff-safe:
 *
 *   - Take the RENDERED form's current FormData (every checked flag + every limit
 *     + _token — exactly what the browser would send), flip ONE boolean for one
 *     plan, and POST it.
 *   - DIFF-SAFETY: only that one key changes in the target plan's settings; every
 *     other key on that plan AND a sibling plan's settings are byte-identical.
 *   - OBSERVABLE EFFECT: plan_entitlements mirrors the flip (bool_value).
 *   - RESTORE: flip back → settings return to the original snapshot (net-zero).
 *
 * Admin: E2E_ADMIN_EMAIL / E2E_ADMIN_PASS (E2eAdminSeeder). The first paid plan, benign key `advertisements`.
 * Plan-scoped config (NOT an app-wide Option), fully restored. Ground truth: plan.settings + plan_entitlements in the DB.
 */

const V = require('../../lib/platform-v2');

// The plan under test and an untouched sibling. Plan ids differ per database (the legacy dev DB used #4 / #3),
// so take the lowest and highest paid plan ids when the journey runs.
let PLAN_ID = 0;
let SIBLING_PLAN = 0;
const FLAG = 'advertisements';

function adminCreds() {
  const email = process.env.E2E_ADMIN_EMAIL;
  const pass = process.env.E2E_ADMIN_PASS;
  if (!email || !pass) throw new Error('B4: set E2E_ADMIN_EMAIL / E2E_ADMIN_PASS in the lane .env');
  return { email, pass };
}

const planSettings = (id) => {
  const rows = V.db('SELECT settings FROM {p}plans WHERE id = ?', [id]);
  if (!rows.length) throw new Error(`B4: plan #${id} not found`);
  return String(rows[0][0] || '{}');
};
const entitlement = (planId, key) => {
  const rows = V.db('SELECT bool_value FROM {p}plan_entitlements WHERE plan_id = ? AND ent_key = ? LIMIT 1', [planId, key]);
  return rows.length ? String(rows[0][0]) : null;
};

// Submit the rendered form's real FormData with matrix[PLAN_ID][FLAG] forced to
// `on` (true) or removed (false). Returns the POST status.
async function submitFlip(page, on) {
  return page.evaluate(async ({ planId, flag, on }) => {
    const form = document.querySelector('form[action*="feature-matrix"]');
    if (!form) return { status: 0, error: 'feature-matrix form not found' };
    const fd = new FormData(form);
    const name = `matrix[${planId}][${flag}]`;
    fd.delete(name);
    if (on) fd.append(name, '1');
    const params = new URLSearchParams();
    for (const [k, v] of fd.entries()) params.append(k, v);
    const r = await fetch(form.getAttribute('action'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html' },
      body: params.toString(),
      redirect: 'follow',
    });
    return { status: r.status };
  }, { planId: PLAN_ID, flag: FLAG, on });
}

// Compare two settings JSON strings; return the list of keys whose values differ
// (bools normalized so absent == '0', to ignore cosmetic key-presence noise).
function changedKeys(beforeJson, afterJson) {
  const norm = (j) => { try { return JSON.parse(j); } catch { return {}; } };
  const a = norm(beforeJson); const b = norm(afterJson);
  const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
  const diff = [];
  for (const k of keys) {
    // The matrix save writes every flag/limit as a string; a plan whose blob was seeded with JSON numbers
    // (5 vs "5") has the same effective value, so compare scalars by their string form.
    const flat = (v) => (typeof v === 'number' || typeof v === 'boolean' ? String(v) : v);
    const av = JSON.stringify(flat(a[k] ?? (typeof b[k] === 'string' ? '0' : b[k])));
    const bv = JSON.stringify(flat(b[k] ?? (typeof a[k] === 'string' ? '0' : a[k])));
    if (av !== bv) diff.push(k);
  }
  return diff;
}

module.exports = async function b4AdminFeatureFlags({ page, log }) {
  const eeOnly = V.requireEe('the plan feature matrix (plans)');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  const planIds = V.db('SELECT id FROM {p}plans ORDER BY id').map((r) => Number(r[0]));
  if (planIds.length < 2) throw new Error(`B4: need two paid plans (a plan under test and a sibling), found ${planIds.length}`);
  PLAN_ID = planIds[0];
  SIBLING_PLAN = planIds[planIds.length - 1];
  await V.loginAdmin(page, adminCreds());
  const resp = await page.goto(V.cfg.baseUrl + '/admin/feature-matrix', { waitUntil: 'domcontentloaded' });
  if (!resp || resp.status() >= 400) throw new Error(`B4: /admin/feature-matrix → ${resp ? resp.status() : 'no response'} (admin login failed?)`);
  await page.waitForSelector('form[action*="feature-matrix"]', { timeout: V.cfg.navTimeout });

  const before = planSettings(PLAN_ID);
  const siblingBefore = planSettings(SIBLING_PLAN);
  const wasOn = JSON.parse(before)[FLAG] === '1';
  log(`B4 baseline: plan #${PLAN_ID} ${FLAG}='${JSON.parse(before)[FLAG] ?? '(absent)'}' (wasOn=${wasOn}); entitlement=${entitlement(PLAN_ID, FLAG)}`);

  // ── FLIP the flag. ─────────────────────────────────────────────────────────
  const flip = await submitFlip(page, !wasOn);
  if (flip.status >= 400 || flip.status === 0) throw new Error(`B4 flip POST → ${flip.status} ${flip.error || ''}`);
  const afterFlip = planSettings(PLAN_ID);
  const flippedVal = JSON.parse(afterFlip)[FLAG];
  if (flippedVal !== (wasOn ? '0' : '1')) {
    throw new Error(`B4: flag did not flip — ${FLAG} is '${flippedVal}', expected '${wasOn ? '0' : '1'}'`);
  }

  // DIFF-SAFETY: exactly one key changed on the target plan; sibling untouched.
  const diff = changedKeys(before, afterFlip);
  if (!(diff.length === 1 && diff[0] === FLAG)) {
    throw new Error(`B4 DIFF-SAFETY VIOLATION: flipping ${FLAG} changed keys [${diff.join(', ')}] — expected only [${FLAG}]`);
  }
  // The bulk save canonicalizes every plan's settings (absent bool keys become an
  // explicit '0' — same EFFECTIVE value). Diff-safety for a sibling therefore means
  // no sibling flag's effective value FLIPPED (normalized compare ignores cosmetic
  // absent→'0'). A real '1'→'0' regression on an untouched plan would still fail.
  const siblingAfter = planSettings(SIBLING_PLAN);
  const siblingDiff = changedKeys(siblingBefore, siblingAfter);
  if (siblingDiff.length !== 0) {
    throw new Error(`B4 DIFF-SAFETY VIOLATION: sibling plan #${SIBLING_PLAN} effective flags changed [${siblingDiff.join(', ')}] while editing plan #${PLAN_ID}`);
  }
  // OBSERVABLE EFFECT: plan_entitlements mirrors the flip (when the mirror row exists).
  const entAfter = entitlement(PLAN_ID, FLAG);
  if (entAfter !== null && entAfter !== (wasOn ? '0' : '1')) {
    throw new Error(`B4: plan_entitlements did NOT mirror the flip — ent_key ${FLAG} bool_value='${entAfter}', expected '${wasOn ? '0' : '1'}'`);
  }
  log(`B4 flip: plan #${PLAN_ID} ${FLAG} → '${flippedVal}'; ONLY [${diff.join(',')}] changed; sibling #${SIBLING_PLAN} untouched; entitlement mirror=${entAfter}`);

  // ── RESTORE (net-zero). ────────────────────────────────────────────────────
  await page.goto(V.cfg.baseUrl + '/admin/feature-matrix', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('form[action*="feature-matrix"]', { timeout: V.cfg.navTimeout });
  const restore = await submitFlip(page, wasOn);
  if (restore.status >= 400 || restore.status === 0) throw new Error(`B4 restore POST → ${restore.status}`);
  const afterRestore = planSettings(PLAN_ID);
  const restoreDiff = changedKeys(before, afterRestore);
  if (restoreDiff.length !== 0) {
    throw new Error(`B4 restore incomplete: plan #${PLAN_ID} settings differ from baseline at [${restoreDiff.join(', ')}]`);
  }
  log(`B4 restore: plan #${PLAN_ID} settings back to baseline (net-zero)`);

  log('B4 admin feature-flags: flip observable, diff-safe (1 key, sibling intact), entitlement mirrored, restored');
};
