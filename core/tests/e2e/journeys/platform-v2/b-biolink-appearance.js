// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B8: Biolink appearance persist + server-side gates + analytics.
 *
 * Beyond v1's "the biolink shells render", this drives the REAL appearance save
 * (biolink.settings.save → BioLinkService::saveSettings, stored as the
 * `bio_settings` post_option) and asserts the sanitizer contract in BioAppearance:
 *
 *   - PERSIST: a FREE appearance key (accent colour, button style) round-trips.
 *   - SERVER-SIDE GATES (entitlement-independent, so provable on this env):
 *       · bg_type='video' is a deferred stub → stripped to null.
 *       · an invalid colour ('notacolor') is rejected → key never stored.
 *       · font_upload has no pipeline → always dropped.
 *     These are real server-side blocks on disallowed input (not a UI-only lock).
 *   - PRO content when ENTITLED: bg_type='image' + bg_image persist (store carries
 *     bio_pro on this env).
 *   - ANALYTICS: the insights dashboard renders (200, real content).
 *
 * PLAN PRO-GATE (strip PRO keys when the plan does NOT grant bio_pro): NOT
 * exercisable here — every bio_* feature is plan_gated=0 (dark-on), so
 * FeatureAccess::storeAllows('bio_pro') is true for every store; demonstrating the
 * strip would require an app-wide feature flip, which the v2 constraints forbid.
 * Recorded in the ledger; the sanitizer's entitlement-independent gates ARE proven.
 *
 * Owner: showcase-demo . Net-zero: the bio_settings post_option is snapshot
 * and restored (deleted if it did not exist). Ground truth: the stored JSON.
 */

const V = require('../../lib/platform-v2');

const OWNER = { email: 'showcase-demo@example.com', pass: 'showcase-demo-pass' };
let STORE = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)

function readBioSettings() {
  // P1-07: `bio_settings` is an unmapped store setting, kept under stores.settings -> custom.
  const rows = V.db("SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, '$.custom.bio_settings')) FROM {p}stores WHERE id = ? LIMIT 1", [STORE]);
  return rows.length && rows[0][0] !== 'NULL' ? String(rows[0][0]) : null;
}
function appearanceOf(json) {
  try { return (JSON.parse(json || '{}').appearance) || {}; } catch { return {}; }
}

// POST the settings form with a flat map (keys already bracketed, e.g.
// 'appearance[accent]'). biolink.settings.save redirects (back()).
async function saveSettings(page, fields) {
  return page.evaluate(async ({ fields, store }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(fields)) params.append(k, v);
    const r = await fetch('/biolink/settings?store_id=' + store, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: params.toString(),
      redirect: 'follow',
    });
    return { status: r.status };
  }, { fields, store: STORE });
}

module.exports = async function b8BiolinkAppearance({ page, log }) {
  STORE = V.craveStoreId();
  await V.loginOwner(page, OWNER);
  const snapshot = readBioSettings();
  log(`B8 baseline: bio_settings ${snapshot === null ? '(absent)' : 'present (' + snapshot.length + ' bytes)'}`);

  // Need a CSRF token from a bio dashboard page.
  const shell = await page.goto(V.cfg.baseUrl + '/biolink?store_id=' + STORE, { waitUntil: 'domcontentloaded' });
  if (!shell || shell.status() >= 400) throw new Error(`B8: /biolink → ${shell ? shell.status() : 'no response'}`);

  // ── 1. PERSIST + SERVER-SIDE GATES. ────────────────────────────────────────
  const saveA = await saveSettings(page, {
    enabled: '1',
    'appearance[accent]': '#123456',
    'appearance[btn_style]': 'outline',
    'appearance[bg_type]': 'video',        // deferred stub → must strip
    'appearance[muted]': 'notacolor',      // invalid colour → must reject
    'appearance[font_upload]': 'evil.woff', // no pipeline → must drop
  });
  if (saveA.status >= 400) throw new Error(`B8 saveA → ${saveA.status}`);
  const apA = appearanceOf(readBioSettings());
  if (apA.accent !== '#123456') throw new Error(`B8 persist: accent='${apA.accent}', expected '#123456'`);
  if (apA.btn_style !== 'outline') throw new Error(`B8 persist: btn_style='${apA.btn_style}', expected 'outline'`);
  if (apA.bg_type === 'video') throw new Error(`B8 gate: bg_type='video' was NOT stripped (deferred stub)`);
  if (apA.muted) throw new Error(`B8 gate: invalid colour 'notacolor' was stored as muted='${apA.muted}'`);
  if (apA.font_upload) throw new Error(`B8 gate: font_upload was stored ('${apA.font_upload}') — must always drop`);
  log(`B8 persist+gates: accent=${apA.accent}, btn_style=${apA.btn_style}; bg_type(video)→${apA.bg_type}, muted(invalid)→dropped, font_upload→dropped`);

  // ── 2. PRO content when entitled (bio_pro dark-on here). ────────────────────
  const saveB = await saveSettings(page, {
    enabled: '1',
    'appearance[bg_type]': 'image',
    'appearance[bg_image]': 'e2e-b8-bg.png',
  });
  if (saveB.status >= 400) throw new Error(`B8 saveB → ${saveB.status}`);
  const apB = appearanceOf(readBioSettings());
  if (apB.bg_type !== 'image' || apB.bg_image !== 'e2e-b8-bg.png') {
    throw new Error(`B8 pro-entitled: expected bg_type=image + bg_image persisted, got bg_type='${apB.bg_type}' bg_image='${apB.bg_image}'`);
  }
  log(`B8 pro (entitled): bg_type=${apB.bg_type}, bg_image=${apB.bg_image} persisted`);

  // ── 3. ANALYTICS dashboard renders. ────────────────────────────────────────
  const ins = await page.goto(V.cfg.baseUrl + '/biolink/insights?store_id=' + STORE, { waitUntil: 'networkidle2' });
  if (!ins || ins.status() >= 400) throw new Error(`B8 insights → ${ins ? ins.status() : 'no response'}`);
  const insLen = (await page.content()).length;
  if (insLen < 500) throw new Error(`B8 insights: page looks empty (len=${insLen})`);
  log(`B8 analytics: /biolink/insights → ${ins.status()} (content ${insLen} bytes)`);

  // ── restore (net-zero). ────────────────────────────────────────────────────
  if (snapshot === null) {
    V.db("UPDATE {p}stores SET settings = JSON_REMOVE(settings, '$.custom.bio_settings') WHERE id = ? AND settings IS NOT NULL", [STORE]);
    log('B8 restore: removed the bio_settings setting (did not exist at baseline)');
  } else {
    V.db("UPDATE {p}stores SET settings = JSON_SET(COALESCE(settings, JSON_OBJECT()), '$.custom.bio_settings', ?) WHERE id = ?", [snapshot, STORE]);
    log('B8 restore: bio_settings restored to baseline');
  }

  log('B8 biolink: appearance persists, server-side sanitizer gates hold, pro content saves when entitled, analytics renders (plan pro-gate = dark-on, BLOCKED-OWNER)');
};
