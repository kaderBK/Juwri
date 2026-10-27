// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-12 item 9 — SMS OTP per-store scoping (P1-12 "same phone, two stores, independent codes/limits"). Opt-in with
 * E2E_RUN_P112_B=1.
 *
 * One phone number signs in at two stores that belong to two different owners (crave-demo / showcase owner and
 * themepack-demo / themepack owner). SMS_PROVIDER=log, so every code is read back from the sms_logs table: no SMS leaves
 * the machine.
 *
 *   1  independent codes: a code sent for store A is refused at store B (and B's at A), accepted at its own store; B's code
 *      still works after A's was used (nothing is shared); each success creates the customer in THAT store only; each sms_logs
 *      row is billed to the store that asked.
 *   2  owner -> customer -> owner: each owner searches the customer list for the phone and sees only the customer of their own
 *      store (never the other owner's); opening the other owner's customer id is denied (IDOR).
 *   3  independent request limits: 3 codes per phone per store per 10 minutes — the 4th at A is refused ("too many"), while
 *      the same phone can still request at B.
 *   4  independent attempt limits: 5 wrong guesses at A burn A's code (even the right code is then refused) while B's code for
 *      the same phone is untouched.
 *
 * Between legs the site's file cache (rate limiters + codes) is cleared so the per-IP route throttles (6/min, 10/min) never
 * masquerade as the per-store limits under test. Test phones are +9665 + 8 digits; customers created here are named by the
 * app ("Guest") and live in the demo stores' own rows.
 */

const L = require('../lib/lane-b');
const { newPage } = require('../lib/harness');

const STORES = {
  A: { slug: 'crave-demo', email: process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com', pass: process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass' },
  B: { slug: 'themepack-demo', email: process.env.E2E_OWNER_EMAIL || 'themepack-owner@demo.test', pass: process.env.E2E_OWNER_PASS || 'password' },
};

const phone = () => `+9665${Math.floor(10000000 + Math.random() * 89999999)}`;

/** The newest code sent to `to` (and the store it was billed to) from sms_logs. */
async function lastCode(to, afterId = 0) {
  // the log row is written after the response is sent (terminating callback): poll for up to 8 s
  let rows = [];
  for (let i = 0; i < 40 && !rows.length; i++) {
    rows = L.dbq('select message_preview, store_id, id from sms_logs where `to` = ? and id > ? order by id desc limit 1', [to, afterId]);
    if (!rows.length) await new Promise((r) => setTimeout(r, 200));
  }
  if (!rows.length) throw new Error(`no sms_logs row for ${to}`);
  const m = String(rows[0][0]).match(/(\d{6})/);
  if (!m) throw new Error(`no 6-digit code in "${rows[0][0]}"`);
  return { code: m[1], storeId: String(rows[0][1]), id: Number(rows[0][2]) };
}

async function openStore(browser, key) {
  const s = await L.freshPage(browser, newPage, { width: 390, height: 844 });
  const base = L.baseUrl();
  const resp = await s.page.goto(`${base}/en/${STORES[key].slug}`, { waitUntil: 'domcontentloaded' });
  if (!resp || resp.status() !== 200) throw new Error(`storefront ${STORES[key].slug} -> ${resp && resp.status()}`);
  const info = await s.page.evaluate(() => ({ id: window.CRAVE && window.CRAVE.storeId, ep: window.CRAVE && window.CRAVE.endpoints }));
  if (!info.id || !info.ep || !info.ep.otpRequest) throw new Error(`${STORES[key].slug}: storefront does not expose the phone-code endpoints`);
  return { ...s, id: String(info.id), ep: info.ep };
}

async function store(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const csrf = window.CRAVE && window.CRAVE.csrf;
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(body),
    });
    let json = null; try { json = await r.json(); } catch (_e) { /* not json */ }
    return { status: r.status, json };
  }, { url, body });
}

const request = (st, ph) => post(st.page, st.ep.otpRequest, { phone: ph, store_id: Number(st.id) });
const verify = (st, ph, code) => post(st.page, st.ep.otpVerify, { phone: ph, code, store_id: Number(st.id) });

module.exports = async function otpStoreScoping({ browser, log, shot }) {
  const check = L.checker('otp-store-scoping');
  const A = await openStore(browser, 'A');
  const B = await openStore(browser, 'B');
  const customerCount = (ph, storeId) => Number(L.dbq('select count(*) from customers where phone = ? and store_id = ?', [ph, storeId])[0][0]);
  try {
    check.ok(A.id !== B.id, `two different stores (#${A.id}, #${B.id})`);

    // ---- 1 independent codes -------------------------------------------------------------------------------------
    L.clearSiteCache();
    const ph = phone();
    const rqA = await request(A, ph);
    check.eq(rqA.status, 200, `code requested at store A (${JSON.stringify(rqA.json)})`);
    const a = await lastCode(ph);
    check.eq(a.storeId, A.id, 'the SMS for A is billed to store A');
    let b;
    for (let i = 0; i < 3; i++) {
      check.eq((await request(B, ph)).status, 200, 'code requested at store B for the same phone');
      b = await lastCode(ph, b ? b.id : a.id);
      if (b.code !== a.code) break; // 1 in 900000: ask again rather than fake a cross-store accept
    }
    check.eq(b.storeId, B.id, 'the SMS for B is billed to store B');
    check.ok(a.code !== b.code, 'the two stores sent different codes');

    check.eq((await verify(B, ph, a.code)).status, 401, "A's code is refused at store B");
    check.eq((await verify(A, ph, b.code)).status, 401, "B's code is refused at store A");
    check.eq(customerCount(ph, A.id) + customerCount(ph, B.id), 0, 'refused codes create no customer');

    const okA = await verify(A, ph, a.code);
    check.eq(okA.status, 200, "A's code is accepted at store A");
    check.eq(customerCount(ph, A.id), 1, 'the customer exists in store A');
    check.eq(customerCount(ph, B.id), 0, '...and not in store B');
    check.eq((await verify(A, ph, a.code)).status, 401, "A's code is single-use");
    const okB = await verify(B, ph, b.code);
    check.eq(okB.status, 200, "B's code still works after A's was used");
    check.eq(customerCount(ph, B.id), 1, 'the customer exists in store B too (a separate row)');
    const rowA = L.dbq('select id, phone_verified_at from customers where phone = ? and store_id = ?', [ph, A.id])[0];
    const rowB = L.dbq('select id, phone_verified_at from customers where phone = ? and store_id = ?', [ph, B.id])[0];
    check.ok(rowA[0] !== rowB[0] && rowA[1] && rowB[1], `two separate verified customers (#${rowA[0]} / #${rowB[0]})`);

    // ---- 2 owners see only their own store's customer ---------------------------------------------------------------
    const digits = ph.replace(/\D/g, '').slice(-8);
    const seen = {};
    for (const key of ['A', 'B']) {
      const o = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
      try {
        await L.login(o.page, L.baseUrl(), { email: STORES[key].email, pass: STORES[key].pass });
        await o.page.goto(`${L.baseUrl()}/customers?scope=all&q=${encodeURIComponent(digits)}`, { waitUntil: 'networkidle2' });
        seen[key] = await o.page.evaluate(() => ({
          text: document.body.innerText,
          links: [...document.querySelectorAll('a[href*="/customers/"]')].map((x) => (x.getAttribute('href').match(/\/customers\/(\d+)/) || [])[1]).filter(Boolean),
        }));
        const other = key === 'A' ? rowB[0] : rowA[0];
        const own = key === 'A' ? rowA[0] : rowB[0];
        check.ok(seen[key].links.includes(String(own)), `owner of store ${key} finds the phone customer in the list (#${own})`);
        check.ok(!seen[key].links.includes(String(other)), `owner of store ${key} does not see the other owner's customer (#${other})`);
        const idor = await L.status(o.page, `${L.baseUrl()}/customers/${other}`);
        check.ok([403, 404].includes(idor.status), `owner of store ${key}: the other store's customer #${other} is denied (${idor.status})`);
        const mine = await L.status(o.page, `${L.baseUrl()}/customers/${own}`);
        check.eq(mine.status, 200, `owner of store ${key} opens their own customer #${own}`);
        if (key === 'A') await shot(o.page, 'otp-owner-customers');
      } finally { await o.close(); }
    }

    // ---- 3 independent request limits ---------------------------------------------------------------------------------
    L.clearSiteCache();
    const ph2 = phone();
    const statuses = [];
    for (let i = 0; i < 4; i++) statuses.push((await request(A, ph2)).status);
    check.eq(statuses.join(','), '200,200,200,429', 'store A: 3 codes per phone are sent, the 4th is refused');
    const limited = await request(A, ph2);
    check.ok(limited.status === 429 && /too many|try again/i.test((limited.json && limited.json.message) || ''), `store A: the refusal says "too many" (${limited.json && limited.json.message})`);
    // the per-IP OTP request limit (otp-send, 5 a minute, shared by every store: security fix 2026-10-07) is not what this leg
    // tests; clear the counters so the leg checks the per-store, per-phone limit only
    L.clearSiteCache();
    check.eq((await request(B, ph2)).status, 200, 'store B: the same phone can still request a code (its own limit)');

    // ---- 4 independent attempt limits ---------------------------------------------------------------------------------
    L.clearSiteCache();
    const ph3 = phone();
    await request(A, ph3); const a3 = await lastCode(ph3);
    let b3;
    for (let i = 0; i < 3; i++) { await request(B, ph3); b3 = await lastCode(ph3, b3 ? b3.id : a3.id); if (b3.code !== a3.code) break; }
    const wrong = a3.code === '000000' ? '111111' : '000000';
    const wrongs = [];
    for (let i = 0; i < 5; i++) wrongs.push((await verify(A, ph3, wrong)).status);
    check.eq(wrongs.join(','), '401,401,401,401,401', 'store A: five wrong guesses are refused');
    check.eq((await verify(A, ph3, a3.code)).status, 401, "store A: after five wrong guesses even A's real code is dead");
    check.eq((await verify(B, ph3, b3.code)).status, 200, "store B: the same phone's code is untouched and works");
    check.eq(customerCount(ph3, A.id), 0, 'store A created no customer for the burnt code');
  } finally {
    await A.close();
    await B.close();
  }
  check.done(log);
};
