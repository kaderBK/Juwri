// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A1 — English / LTR storefront ordering (Platform E2E v2, Lane A).
 *
 * v1's `a-order.js` proves the full UI checkout across every theme in ARABIC.
 * v2/A1 adds the gap: the same real checkout in ENGLISH, with LTR asserted, on
 * three genuinely different cart engines —
 *   • crave  → the "cv" SPA engine
 *   • aurora → the bottom-sheet engine
 *   • feast  → the form-in-drawer engine
 * — and, on the server side, that the money is right: coupon discount recorded,
 * `grand_total = subtotal − discount + tax` with the tax computed on the
 * PRE-discount subtotal, and `orders.locale = 'en'`.
 *
 * The order is placed against each store's OWN `/{store}/send-order`
 * endpoint (the very endpoint the theme's "Place order" button posts to),
 * reusing the page's session + CSRF — a genuine checkout, engine-independent, so
 * A1 does not re-derive 17 cart UIs. The en storefront shell is asserted LTR
 * first (lang=en, dir≠rtl), and the track page is re-asserted LTR after.
 *
 * Data: E2eLaneAFixturesSeeder sets 15% VAT on crave/aurora/feast so the tax
 * base is observable; P1TEST10 (E2ePlatformSeeder) is the coupon.
 *
 * Run: `E2E_LOCALE=en E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh a a-en-order`
 */

const V = require('../../lib/platform-v2');
const K = require('../../lib/order-kit-v2');

const ENGINES = [
  { slug: 'crave-demo', engine: 'cv' },
  { slug: 'aurora-demo', engine: 'sheet' },
  { slug: 'feast-demo', engine: 'form' },
];

const COUPON = 'P1TEST10';
const TAX_RATE = 15; // percentage — mirrors E2eLaneAFixturesSeeder

module.exports = async function a1EnOrder({ page, log, shot }) {
  V.resetRateLimits(); // one IP places every order of the pack: start each journey with a clean 10/min window
  const failures = [];

  for (const { slug, engine } of ENGINES) {
    const tag = `${slug}[${engine}]`;
    try {
      const url = V.enUrl('/' + slug);
      const rt = await K.loadRuntime(page, url, { slug });

      // 1. English / LTR storefront shell.
      const shell = await V.assertLtr(page);
      if (shell.lang !== 'en') throw new Error(`expected lang=en, got "${shell.lang}"`);
      log(`  a1 ${tag} · en/LTR shell ok (lang=${shell.lang} dir=${shell.dir || 'unset'}, global=${rt.global}, ${rt.items.length} items)`);
      await shot(page, `a1-${slug}-1-storefront`);

      // 2. Place a REAL order in English with the coupon applied. Unique
      //    client_uuid so this is a fresh order (not a dedup replay).
      const clientUuid = V.uuid7(); // J27-A2: keys must be UUIDs
      const lines = K.pickSimpleLines(rt, { count: 2, quantity: 2 });
      const res = await K.submitOrder(page, {
        rt,
        items: lines,
        fields: { name: 'E2E English Diner', coupon_code: COUPON, client_uuid: clientUuid },
      });
      if (!res.success) throw new Error(`send-order rejected: HTTP ${res.status} ${JSON.stringify(res.message || res)}`);
      if (!res.track_url) throw new Error(`send-order success carried no track_url (${JSON.stringify(res)})`);
      const token = K.trackToken(res.track_url);
      if (!token) throw new Error(`could not parse track token from ${res.track_url}`);
      log(`  a1 ${tag} · order placed (${lines.length} lines, coupon ${COUPON}), track=${token}`);

      // 3. Server-truth totals: read the orders row and assert the money model.
      const rows = V.db(
        'SELECT o.id, o.locale, o.status, o.type, o.subtotal_minor / 100.0, o.discount_amount_minor / 100.0, o.tax_total_minor / 100.0, o.grand_total_minor / 100.0, o.coupon_id, p.slug '
        + 'FROM {p}orders o JOIN {p}stores p ON p.id = o.store_id WHERE o.track_token = ? LIMIT 1',
        [token],
      );
      if (!rows.length) throw new Error(`no orders row for track token ${token}`);
      const [id, locale, status, type, subtotal, discount, taxTotal, grandTotal, couponId, storeSlug] = rows[0];
      const nSub = parseFloat(subtotal);
      const nDisc = parseFloat(discount || '0');
      const nTax = parseFloat(taxTotal || '0');
      const nGrand = parseFloat(grandTotal);

      if (storeSlug !== slug) throw new Error(`order store slug "${storeSlug}" ≠ "${slug}"`);
      if (locale !== 'en') throw new Error(`orders.locale is "${locale}", expected "en" — English order not recorded`);
      if (!status) throw new Error('order has no status');
      if (type !== 'delivery') throw new Error(`order type "${type}", expected delivery`);
      if (!(nDisc > 0) || !couponId) throw new Error(`coupon ${COUPON} discount not recorded (discount=${discount}, coupon_id=${couponId})`);

      // grand = subtotal − discount + tax
      const expectedGrand = nSub - nDisc + nTax;
      if (Math.abs(nGrand - expectedGrand) > 0.02) {
        throw new Error(`grand_total ${nGrand} ≠ subtotal ${nSub} − discount ${nDisc} + tax ${nTax} = ${expectedGrand.toFixed(2)}`);
      }
      // tax computed on the PRE-discount subtotal (not subtotal − discount).
      const taxPre = Math.round((nSub * (TAX_RATE / 100)) * 100) / 100;
      const taxPost = Math.round(((nSub - nDisc) * (TAX_RATE / 100)) * 100) / 100;
      if (Math.abs(nTax - taxPre) > 0.02) {
        throw new Error(`tax ${nTax} ≠ pre-discount ${taxPre} (subtotal ${nSub} × ${TAX_RATE}%); post-discount would be ${taxPost}`);
      }
      if (Math.abs(taxPre - taxPost) < 0.005) {
        throw new Error(`tax base indistinguishable (discount too small: pre=${taxPre} post=${taxPost}) — fixture not exercising the invariant`);
      }
      log(`  a1 ${tag} · order #${id} money ok: grand ${nGrand} = sub ${nSub} − disc ${nDisc} + tax ${nTax} (tax PRE-discount ${taxPre}, not ${taxPost}), locale=${locale}`);

      // 4. Track page renders in English / LTR with a real status word.
      const tResp = await page.goto(res.track_url, { waitUntil: 'networkidle2' });
      if (!tResp || tResp.status() >= 400) throw new Error(`track page returned ${tResp && tResp.status()}`);
      const tShell = await V.assertLtr(page);
      if (tShell.lang !== 'en') throw new Error(`track page lang "${tShell.lang}", expected en`);
      const statusWord = await page.evaluate(() => {
        const el = document.querySelector('#cvStatusWord, #vStatusWord, #status-title, [data-order-status], .order-status, h1, h2');
        return el ? (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60) : '';
      });
      if (!statusWord) throw new Error('track page shows no status text');
      log(`  a1 ${tag} · track page en/LTR ok, status="${statusWord}"`);
      await shot(page, `a1-${slug}-2-track`);

      log(`[e2e] a1 PASS ${tag}`);
    } catch (e) {
      failures.push(`${tag}: ${e.message}`);
      log(`[e2e] a1 FAIL ${tag}: ${e.message}`);
    }
  }

  if (failures.length) throw new Error(`A1 en/LTR ordering failures (${failures.length}/${ENGINES.length}):\n  ${failures.join('\n  ')}`);
  log(`A1: en/LTR checkout + tax-pre-discount verified on ${ENGINES.length} engines (${ENGINES.map((e) => e.engine).join(', ')})`);
};
