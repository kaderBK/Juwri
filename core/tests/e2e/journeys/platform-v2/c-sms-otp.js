// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — C8: SMS / OTP stub (checkout + login) (P1).
 *
 * With SMS_PROVIDER=log the OTP is written to the sms_log table instead of a real
 * SMS (no real message, no spend). This journey drives BOTH phone-OTP surfaces
 * end-to-end and asserts the happy path AND the wrong-code rejection against
 * ground truth (the code is read back from sms_log via readSmsOtp):
 *
 *   A. CHECKOUT OTP (guest) — POST /checkout/otp/send → sms_log row; a WRONG code
 *      → 422; the REAL code (read from the log) → 200 verified.
 *   B. CUSTOMER LOGIN OTP — POST {store}/customer/otp/request → sms_log row; a
 *      WRONG code → 401; the REAL code → 200 and a Customer row is created +
 *      phone_verified_at stamped (find-or-create by store_id + phone).
 *
 * No real SMS leaves the box; the only channel is the log the harness reads.
 *   E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh c c-sms-otp
 */

const V = require('../../lib/platform-v2');
const path = require('path');
const { execFileSync } = require('child_process');

// The OTP routes are IP-throttled (throttle:6,1 + ratelimit:public-write), so
// back-to-back runs from one box accumulate hits. Clear the file rate-limiter
// cache on THIS lane site at journey start so the happy path is deterministic
// (test-env only; clears this lane's app cache, no app setting changed).
function clearRateLimiter() {
  const coreDir = V.siteCoreDir();   // …/core of the site under test
  try {
    execFileSync('php', ['artisan', 'cache:clear'], { cwd: coreDir, stdio: 'ignore', timeout: 60000 });
  } catch (_e) { /* best-effort: a warm limiter just means the send may 429 */ }
}

function phone() {
  return `+9665${Math.floor(10000000 + Math.random() * 89999999)}`;
}

/** In-page JSON POST reusing session + storefront csrf. */
async function store(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin', redirect: 'follow',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: JSON.stringify(body || {}),
    });
    let json = null; try { json = await r.json(); } catch (_e) { /* non-json */ }
    return { status: r.status, json };
  }, { url, body });
}

module.exports = async function cSmsOtp({ page, cfg, log, shot }) {
  clearRateLimiter();
  const store = V.onStore('crave');          // { slug: 'crave-demo', url }
  await page.setViewport({ width: 390, height: 844 });
  const resp = await page.goto(store.url, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!resp || resp.status() !== 200) throw new Error(`storefront ${store.slug} → ${resp ? resp.status() : 'no response'}`);
  const storeId = await page.evaluate(() => Number(window.CRAVE?.storeId || document.querySelector('meta[name="store-id"]')?.content || 0));
  if (!storeId) throw new Error('storefront did not expose a restaurant id');
  log(`store ${store.slug} store_id=${storeId}`);

  // ── A. CHECKOUT OTP (guest, platform account — no store gate) ──────────────
  const p1 = phone();
  const sendA = await post(page, '/checkout/otp/send', { phone: p1 });
  if (sendA.status !== 200 || !sendA.json || sendA.json.success !== true) {
    throw new Error(`checkout otp send expected 200 success, got ${sendA.status} (${JSON.stringify(sendA.json)})`);
  }
  const codeA = V.readSmsOtp(p1);            // from sms_log (SMS_PROVIDER=log)
  if (!/^\d{4,8}$/.test(codeA)) throw new Error(`readSmsOtp returned a non-code: "${codeA}"`);
  // wrong code → 422
  const wrongA = await post(page, '/checkout/otp/verify', { phone: p1, code: '000000' });
  if (wrongA.status !== 422) throw new Error(`checkout wrong-code should 422, got ${wrongA.status} (${JSON.stringify(wrongA.json)})`);
  // real code → 200 verified
  const okA = await post(page, '/checkout/otp/verify', { phone: p1, code: codeA });
  if (okA.status !== 200 || !okA.json || okA.json.success !== true) {
    throw new Error(`checkout real-code verify expected 200 success, got ${okA.status} (${JSON.stringify(okA.json)})`);
  }
  log(`checkout otp: send 200; log code=${codeA}; wrong→422; real→200 verified ✓`);

  // ── B. CUSTOMER LOGIN OTP (find-or-create by store_id + phone) ────────
  const p2 = phone();
  const existsBefore = Number(V.db('SELECT COUNT(*) FROM {p}customers WHERE store_id = ? AND phone = ?', [storeId, p2])[0][0]);
  if (existsBefore !== 0) throw new Error('fresh phone unexpectedly already a customer');
  const reqB = await post(page, `/${store.slug}/customer/otp/request`, { phone: p2, store_id: storeId });
  if (reqB.status !== 200 || !reqB.json || reqB.json.success !== true) {
    throw new Error(`customer otp request expected 200 success, got ${reqB.status} (${JSON.stringify(reqB.json)})`);
  }
  const codeB = V.readSmsOtp(p2);
  if (!/^\d{4,8}$/.test(codeB)) throw new Error(`readSmsOtp (login) returned a non-code: "${codeB}"`);
  // wrong code → 401
  const wrongB = await post(page, `/${store.slug}/customer/otp/verify`, { phone: p2, code: '000000', store_id: storeId });
  if (wrongB.status !== 401) throw new Error(`customer wrong-code should 401, got ${wrongB.status} (${JSON.stringify(wrongB.json)})`);
  // real code → 200 login + Customer created + phone verified
  const okB = await post(page, `/${store.slug}/customer/otp/verify`, { phone: p2, code: codeB, store_id: storeId });
  if (okB.status !== 200 || !okB.json || okB.json.success !== true) {
    throw new Error(`customer real-code verify expected 200 success, got ${okB.status} (${JSON.stringify(okB.json)})`);
  }
  const row = V.db('SELECT id, phone_verified_at FROM {p}customers WHERE store_id = ? AND phone = ? LIMIT 1', [storeId, p2]);
  if (!row.length) throw new Error(`customer row not created for ${p2} @ restaurant ${storeId}`);
  if (!row[0][1] || row[0][1] === 'NULL') throw new Error(`customer ${row[0][0]} phone_verified_at not stamped after OTP login`);
  await shot(page, 'c-sms-otp-store');
  log(`customer login otp: request 200; log code=${codeB}; wrong→401; real→200; customer #${row[0][0]} created, phone_verified ✓`);

  log(`c-sms-otp PASS — SMS_PROVIDER=log OTP round-trips both surfaces: checkout (send→wrong 422→real 200) and customer login (request→wrong 401→real 200, customer created+verified); no real SMS sent (store_id=${storeId})`);
};
