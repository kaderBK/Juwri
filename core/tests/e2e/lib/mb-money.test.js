'use strict';
/* M16 — runs the shared money core against the SERVER-GENERATED fixture.
   No framework on purpose: core/package.json ships puppeteer and nothing else. */
const assert = require('assert');
const fs = require('fs');
const path = require('path');

global.window = global;                                     // mb-money.js assigns to window
require(path.join(__dirname, '../../../../assets/store_templates/_shared/js/mb-money.js'));

const cases = JSON.parse(fs.readFileSync(path.join(__dirname, '../../Fixtures/money-core-cases.json')));
let n = 0;
for (const c of cases) {
  const got = window.mbMoney.tax(c.subtotal, { taxCharge: c.rate, taxType: c.type, decimals: c.decimals });
  assert.strictEqual(got, c.expected, `tax(${c.subtotal} @ ${c.rate}% ${c.type}/${c.decimals}dp) = ${got}, server says ${c.expected}`);
  n++;
}
console.log(`mb-money: ${n} server parity cases OK`);
