// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// The settle screen's "Exact" cash tender is the whole amount due: payable + tip, at the currency's decimals.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const src = readFileSync(new URL('../../../../assets/pos/pos-payments.js', import.meta.url), 'utf8');

function settle(decimals, payable, tip) {
    const window = { PosBoot: { currencyDecimals: decimals } };
    vm.runInNewContext(src, { window, Number, Math, parseFloat, String });
    const m = Object.assign({}, window.PosModules.payments, { payable: () => payable, tipAmount: tip });
    m.presetTender('exact');
    return { str: m.tenderStr, tendered: m.tendered, total: m.settleTotal(), covers: m.cashCovers(), change: m.changeDue() };
}

for (const [d, payable, tip, want] of [[0, 1500, 150, '1650'], [2, 23.15, 3.47, '26.62'], [3, 12.345, 1.005, '13.350'], [2, 0.1, 0.2, '0.30']]) {
    test(`exact tender with a tip at ${d} decimals`, () => {
        const r = settle(d, payable, tip);
        assert.equal(r.str, want);
        assert.equal(r.tendered, Number(want));
        assert.ok(r.covers, 'the exact tender covers payable + tip');
        assert.equal(Math.round(r.change * 1000), 0, 'and leaves no change');
    });
}

test('without a tip exact is the payable; unknown decimals fall back to 2', () => {
    assert.equal(settle(2, 23.15, 0).str, '23.15');
    assert.equal(settle(undefined, 23.15, 0).str, '23.15');
    assert.equal(settle(0, 1500, 0).str, '1500');
});
