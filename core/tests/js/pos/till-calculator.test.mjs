// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// Offline POS OP-2 (OFFLINE-POS-SPEC §4.1): the JavaScript till calculator must reproduce every golden vector byte for
// byte — the same file Tests\Modules\Pos\Pricing\TillCalculatorTest checks against the PHP calculator.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const calc = require('../../../../assets/pos/till-calculator.js');
const { vectors } = JSON.parse(readFileSync(new URL('../../Fixtures/till-calculator/vectors.json', import.meta.url), 'utf8'));

test('there are at least 300 golden vectors', () => {
    assert.ok(vectors.length >= 300, `${vectors.length} vectors`);
});

test('every golden vector is reproduced byte for byte', () => {
    for (const v of vectors) {
        let got;
        try {
            got = calc.price(v.input);
        } catch (e) {
            if (!(e instanceof RangeError)) throw e;
            got = { error: true };
        }
        assert.equal(JSON.stringify(got), JSON.stringify(v.expected), v.name);
    }
});
