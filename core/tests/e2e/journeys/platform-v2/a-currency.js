// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A9 — Multi-currency storefront wiring (Platform E2E v2, Lane A).
 *
 * Asserts the customer-facing storefront actually carries the multi-currency
 * config the switcher runs on: a base currency, the seeded EUR display rate
 * (E2eLaneAFixturesSeeder sets EUR @ 0.90 on crave-demo), a currency sign, and a
 * symbol position — so a shopper's currency switch converts against real rates.
 * The tax-integrity + symbol-position invariants are pinned by
 * tests/Modules/Ordering/E2eV2A9CurrencyTaxTest.php.
 *
 * Run: `E2E_LOCALE=en E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh a a-currency`
 */

const V = require('../../lib/platform-v2');
const K = require('../../lib/order-kit-v2');

const STORE = 'crave-demo';

module.exports = async function a9Currency({ page, log, shot }) {
  const checks = [];
  const ok = (name) => { checks.push(name); log(`  a9 ✓ ${name}`); };

  await K.loadRuntime(page, V.enUrl('/' + STORE), { slug: STORE });
  await V.assertLtr(page);

  const cur = await page.evaluate(() => {
    let G = null;
    for (const k of Object.keys(window)) {
      try {
        const v = window[k];
        if (v && typeof v === 'object' && v.items && v.endpoints && v.endpoints.submitOrder) { G = v; break; }
      } catch (_e) { /* */ }
    }
    if (!G) return null;
    return {
      base: G.baseCurrency || null,
      sign: G.currencySign || null,
      pos: (G.currencyPos !== undefined && G.currencyPos !== null) ? String(G.currencyPos) : null,
      rates: G.currencyRates || null,
      decimalsByCode: G.decimalsByCode || null,
    };
  });
  if (!cur) throw new Error('no commerce runtime with currency config found');
  log(`  a9 · runtime currency: base=${cur.base} sign=${cur.sign} pos=${cur.pos} rates=${JSON.stringify(cur.rates)}`);

  if (!cur.base) throw new Error('runtime exposed no base currency');
  ok(`base currency present (${cur.base})`);
  if (cur.sign === null || cur.pos === null) throw new Error('runtime missing currency sign / position (symbol placement not wired)');
  ok(`currency sign="${cur.sign}" + position=${cur.pos} wired (symbol placement)`);

  // The seeded EUR display rate must be present and correct (0.90) so a switch
  // to EUR converts base prices at that rate.
  const rates = cur.rates || {};
  const eur = Number(rates.EUR ?? rates.eur);
  if (!(eur > 0)) throw new Error(`EUR display rate not present in runtime rates (${JSON.stringify(rates)}) — currency switch not wired`);
  if (Math.abs(eur - 0.90) > 0.001) throw new Error(`EUR rate is ${eur}, expected the seeded 0.90`);
  ok(`EUR display rate wired at ${eur} → a 100 ${cur.base} item shows ${(100 * eur).toFixed(2)} EUR on switch`);

  await shot(page, 'a9-currency-runtime');
  log(`A9: multi-currency storefront wiring — ${checks.length} checks (base + sign/pos + EUR rate). Tax + symbol-position integrity in E2eV2A9CurrencyTaxTest.`);
};
