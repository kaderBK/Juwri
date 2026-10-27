// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B6: Hotel / PMS owner folio integrity.
 *
 * Targets the known gotcha (MEMORY: "folio_account_id misleading NULL; assert
 * ledger_entries"): a stay starts with folio_account_id = NULL, so trusting that
 * pointer alone would say the guest owes nothing. The money truth lives in the
 * shared ledger. This drives the real folio charge path (HotelFolioController@adjust
 * → HotelFolioService::adjust → LedgerService::post) and asserts:
 *
 *   - CHARGE POSTS TO THE LEDGER: after a +50 adjustment the stay's folio account
 *     is pinned AND ledger_entries carry exactly +50 under it (money is real, not a
 *     misleading NULL). balance() == 50.
 *   - IDEMPOTENT: re-posting the SAME idempotency_key does NOT double-charge — the
 *     ledger ref dedups (one folio-leg entry per key), balance unchanged.
 *   - LEDGER ACCUMULATES distinct charges: a second charge (+20, new key) makes the
 *     balance 70 with its own single entry.
 *   - STATEMENT renders.
 *
 * Fixture: HotelDemoSeeder — owner hotel-owner@demo.test / password, store
 * hotel-demo (#3470), checked-in stay token hoteldemostaytoken0001 (room 101).
 * FIXED idempotency keys → fully re-runnable (all charges idempotent across runs).
 * Ground truth: hotel_stays.folio_account_id + ledger_entries.
 */

const V = require('../../lib/platform-v2');

const OWNER = { email: 'hotel-owner@demo.test', pass: 'password' };
const STAY_TOKEN = 'hoteldemostaytoken0001';
const KEY1 = '0e2e0001-b006-4b00-8000-000000000001'; // +50 charge (stable)
const KEY2 = '0e2e0002-b006-4b00-8000-000000000002'; // +20 charge (stable)

function stayRow() {
  const r = V.db("SELECT id, store_id, folio_account_id FROM {p}hotel_stays WHERE token = ? LIMIT 1", [STAY_TOKEN]);
  if (!r.length) throw new Error('B6 BLOCKED: hotel-demo stay not found — run HotelDemoSeeder');
  const fa = r[0][2];
  const folioAccountId = (fa === null || fa === '' || String(fa).toUpperCase() === 'NULL') ? null : Number(fa);
  return { id: Number(r[0][0]), storeId: Number(r[0][1]), folioAccountId };
}

async function adjust(page, stayId, amount, reason, key) {
  return page.evaluate(async ({ stayId, amount, reason, key }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch(`/dashboard/hotel/folio/${stayId}/adjust`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: JSON.stringify({ amount, reason, idempotency_key: key }),
    });
    return { status: r.status, body: await r.json().catch(() => ({})) };
  }, { stayId, amount, reason, key });
}

// count + sum of the folio-side ledger legs for a given adjust idempotency key.
function folioLeg(key) {
  const ref = `hotel_folio:adjust:${key}:folio`;
  const rows = V.db('SELECT amount_minor / 100.0 FROM {p}ledger_entries WHERE ref = ?', [ref]);
  return { count: rows.length, amount: rows.length ? Number(rows[0][0]) : null };
}

// The ledger truth: sum of every entry on the folio account (2dp).
function ledgerBalance(folioAccountId) {
  const rows = V.db('SELECT COALESCE(SUM(amount_minor),0) / 100.0 FROM {p}ledger_entries WHERE ledger_account_id = ?', [folioAccountId]);
  return Math.round(Number(rows[0][0]) * 100) / 100;
}

module.exports = async function b6HotelFolio({ page, log }) {
  const before = stayRow();
  log(`B6 baseline: stay #${before.id} (store ${before.storeId}) folio_account_id=${before.folioAccountId === null ? 'NULL (the gotcha — pointer says "no account")' : before.folioAccountId}`);

  await V.loginOwner(page, OWNER);
  // Land on the hotel stays surface for a CSRF token + hotel restaurant context.
  const shell = await page.goto(V.cfg.baseUrl + '/hotel/stays', { waitUntil: 'domcontentloaded' });
  if (!shell || shell.status() >= 400) throw new Error(`B6: /hotel/stays → ${shell ? shell.status() : 'no response'} (hotel owner login / hotel_mode?)`);

  // ── 1. CHARGE +50 → lands in the ledger, pins the folio account. ───────────
  const c1 = await adjust(page, before.id, 50, 'E2E room service charge', KEY1);
  if (c1.status !== 200 || c1.body.success !== true) throw new Error(`B6 charge#1: ${c1.status} ${JSON.stringify(c1.body)}`);
  const afterCharge = stayRow();
  if (afterCharge.folioAccountId === null) {
    throw new Error('B6 INTEGRITY: folio_account_id still NULL after a charge — the pin never happened');
  }
  const folioId = afterCharge.folioAccountId;
  const leg1 = folioLeg(KEY1);
  if (leg1.count !== 1 || Math.abs(leg1.amount - 50) > 0.001) {
    throw new Error(`B6 INTEGRITY: expected 1 ledger folio-leg of 50 for KEY1, got count=${leg1.count} amount=${leg1.amount}`);
  }
  // The reported balance MUST equal the ledger truth (sum of folio entries).
  if (Math.abs(Number(c1.body.balance) - ledgerBalance(folioId)) > 0.001) {
    throw new Error(`B6: API balance ${c1.body.balance} != ledger sum ${ledgerBalance(folioId)} — folio does not match the ledger`);
  }
  log(`B6 charge: +50 posted → folio_account_id pinned (#${folioId}), KEY1 leg=50, balance=${c1.body.balance}==ledger (money is in the ledger, not a misleading NULL)`);

  // ── 2. IDEMPOTENT: same key → no double charge. ────────────────────────────
  const balBefore = Number(c1.body.balance);
  const c1b = await adjust(page, before.id, 50, 'E2E room service charge', KEY1);
  const leg1b = folioLeg(KEY1);
  if (leg1b.count !== 1) throw new Error(`B6 IDEMPOTENCY: KEY1 posted ${leg1b.count} folio legs after a repeat — expected 1 (double charge!)`);
  if (Math.abs(Number(c1b.body.balance) - balBefore) > 0.001) {
    throw new Error(`B6 IDEMPOTENCY: balance moved ${balBefore} → ${c1b.body.balance} on a repeated identical charge`);
  }
  log(`B6 idempotent: repeat of KEY1 → still 1 ledger leg, balance stays ${c1b.body.balance}`);

  // ── 3. LEDGER ACCUMULATES a distinct charge (+20, new key). ────────────────
  const c2 = await adjust(page, before.id, 20, 'E2E minibar charge', KEY2);
  if (c2.status !== 200) throw new Error(`B6 charge#2: ${c2.status} ${JSON.stringify(c2.body)}`);
  const leg2 = folioLeg(KEY2);
  if (leg2.count !== 1 || Math.abs(leg2.amount - 20) > 0.001) {
    throw new Error(`B6: expected 1 ledger folio-leg of 20 for KEY2, got count=${leg2.count} amount=${leg2.amount}`);
  }
  if (Math.abs(Number(c2.body.balance) - ledgerBalance(folioId)) > 0.001) {
    throw new Error(`B6: after KEY2 the API balance ${c2.body.balance} != ledger sum ${ledgerBalance(folioId)}`);
  }
  // KEY1 (+50) and KEY2 (+20) must both be present in the folio balance.
  if (Number(c2.body.balance) < 70 - 0.001) {
    throw new Error(`B6: folio balance ${c2.body.balance} does not include both charges (expected >= 70)`);
  }
  log(`B6 accumulate: +20 posted (KEY2), balance=${c2.body.balance}==ledger (KEY1+KEY2 both present)`);

  // ── 4. STATEMENT renders. ──────────────────────────────────────────────────
  const stmt = await page.goto(`${V.cfg.baseUrl}/dashboard/hotel/folio/${before.id}/statement`, { waitUntil: 'domcontentloaded' });
  if (!stmt || stmt.status() >= 400) throw new Error(`B6 statement → ${stmt ? stmt.status() : 'no response'}`);
  log(`B6 statement: /dashboard/hotel/folio/${before.id}/statement → ${stmt.status()}`);

  log('B6 hotel folio: charge posts to ledger (folio_account_id pinned, not a misleading NULL), idempotent per key, accumulates distinct charges, statement renders');
};
