// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A10 — Reservations + waitlist, customer surface in English (Platform E2E v2, A).
 *
 * Drives the public storefront endpoints a diner uses (POST {slug}/waitlist and
 * {slug}/reservation) on crave-demo in English, and checks the terminal/pre-
 * condition guards:
 *   • waitlist join → a `waiting` entry with a real queue position;
 *   • reservation book for a FUTURE slot → a `pending` reservation;
 *   • reservation book for a PAST slot → rejected, NO reservation created
 *     (the "must be in the future" guard).
 *
 * Catering's public request is BLOCKED-OWNER (the `catering` feature ships as a
 * global dark kill-switch) — covered at the service level in
 * tests/Modules/Ordering/E2eV2A10CateringTest.php. See ISS-V-A-002.
 *
 * Run: `E2E_LOCALE=en E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh a a-reservations-waitlist`
 */

const V = require('../../lib/platform-v2');
const K = require('../../lib/order-kit-v2');

const STORE = 'crave-demo';

module.exports = async function a10ResWaitlist({ page, log, shot, sleep }) {
  const checks = [];
  const ok = (name) => { checks.push(name); log(`  a10 ✓ ${name}`); };

  const rt = await K.loadRuntime(page, V.enUrl('/' + STORE), { slug: STORE });
  await V.assertLtr(page);
  const rid = rt.storeId;

  const postFormOnce = (path, fields) => page.evaluate(async ({ path, fields, csrf }) => {
    const fd = new FormData();
    Object.keys(fields).forEach((k) => fd.append(k, fields[k]));
    const r = await fetch(path, {
      method: 'POST', credentials: 'same-origin', redirect: 'manual',
      headers: Object.assign({ Accept: 'application/json' }, csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
      body: fd,
    });
    let json = null; try { json = await r.json(); } catch (_e) { json = null; }
    return { status: r.status, type: r.type, json };
  }, { path, fields, csrf: rt.csrf });

  // The public waitlist/reservation endpoints carry a per-minute abuse throttle
  // (throttle:20,1 + ratelimit:public-write). Under back-to-back reproducibility
  // runs the shared per-IP minute bucket can still be warm from the previous run,
  // returning 429. That is an ENV rate-limit, not a product failure — wait out the
  // ~60s window and retry so the journey is reproducible. (sleep is provided by
  // the runner; the 15-min per-journey watchdog bounds the total.)
  const postForm = async (path, fields) => {
    for (let attempt = 1; attempt <= 3; attempt++) {
      const res = await postFormOnce(path, fields);
      if (res.status !== 429) return res;
      if (attempt === 3) return res;
      log(`  a10 · ${path} rate-limited (429) — waiting out the per-minute window (attempt ${attempt}/3)`);
      await sleep(62000);
    }
  };

  // ── Waitlist join ───────────────────────────────────────────────────────────
  const wlName = `E2E Waitlist ${Date.now()}`;
  const wl = await postForm(`/${STORE}/waitlist`, { name: wlName, phone: '+966500000010', party_size: 4 });
  if (wl.status >= 400) throw new Error(`waitlist join returned ${wl.status}`);
  const wlRow = V.db("SELECT status, party_size FROM {p}waitlist_entries WHERE store_id = ? AND name = ? ORDER BY id DESC LIMIT 1", [rid, wlName]);
  if (!wlRow.length) throw new Error('waitlist join created no entry');
  if (wlRow[0][0] !== 'waiting') throw new Error(`waitlist entry status is "${wlRow[0][0]}", expected waiting`);
  ok(`waitlist join → waiting entry (party ${wlRow[0][1]}${wl.json && wl.json.position ? `, position ${wl.json.position}` : ''})`);

  // ── Reservation: future slot books, past slot is rejected ────────────────────
  const iso = (d) => new Date(d).toISOString().slice(0, 19).replace('T', ' ');
  const future = iso(Date.now() + 7 * 24 * 3600 * 1000);
  const past = iso(Date.now() - 7 * 24 * 3600 * 1000);

  const resName = `E2E Reserve ${Date.now()}`;
  const before = parseInt(V.db('SELECT COUNT(*) FROM {p}reservations WHERE store_id = ? AND name = ?', [rid, resName])[0][0], 10);
  const rb = await postForm(`/${STORE}/reservation`, { name: resName, phone: '+966500000011', party_size: 2, reserved_at: future });
  if (rb.status >= 500) throw new Error(`reservation book returned ${rb.status}`);
  const resRow = V.db("SELECT status FROM {p}reservations WHERE store_id = ? AND name = ? ORDER BY id DESC LIMIT 1", [rid, resName]);
  if (!resRow.length) throw new Error(`future reservation created no row (status ${rb.status})`);
  if (resRow[0][0] !== 'pending') throw new Error(`reservation status is "${resRow[0][0]}", expected pending`);
  ok(`reservation (future slot) → pending reservation created`);

  const pastName = `E2E ReservePast ${Date.now()}`;
  const rp = await postForm(`/${STORE}/reservation`, { name: pastName, phone: '+966500000012', party_size: 2, reserved_at: past });
  const pastRow = V.db('SELECT COUNT(*) FROM {p}reservations WHERE store_id = ? AND name = ?', [rid, pastName]);
  if (parseInt(pastRow[0][0], 10) !== 0) throw new Error(`past-slot reservation was accepted (guard breached): ${parseInt(pastRow[0][0], 10)} rows created`);
  ok(`reservation (past slot) → rejected, NO reservation created (future-only guard)`);

  await shot(page, 'a10-res-waitlist-done');
  log(`A10: reservations + waitlist (en) — ${checks.length} checks; catering request is BLOCKED-OWNER (dark feature), covered in E2eV2A10CateringTest.`);
};
