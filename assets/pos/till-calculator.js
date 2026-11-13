// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* The till's price calculator — the JavaScript twin of Juwri\Modules\Pos\Pricing\TillCalculator (PHP).
   the offline POS spec §4.1. Both must give byte-identical results for every vector in
   core/tests/Fixtures/till-calculator/vectors.json (node --test core/tests/js/pos; CI fails on any difference).
   Integers only (BigInt inside, minor units out); see the PHP class for the rule. No dependencies. */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.TillCalculator = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';
    var MAX_MINOR = 100000000000n;
    var RATE_SCALE = 10000n;

    function fail(msg) { throw new RangeError(msg); }

    function int(v, field, min, max) {
        if (typeof v !== 'number' || !Number.isInteger(v) || v < min || v > max) fail(field + ' must be an integer from ' + min + ' to ' + max);
        return BigInt(v);
    }
    function amount(v, field) { return int(v, field, 0, Number(MAX_MINOR)); }
    function cap(v, field) { if (v > MAX_MINOR) fail(field + ' is above the maximum amount'); return v; }

    function rate(r, field) {
        var s = typeof r === 'number' && Number.isInteger(r) ? String(r) : (typeof r === 'string' ? r : '');
        var m = /^(\d{1,3})(?:\.(\d{1,4}))?$/.exec(s);
        if (!m) fail(field + ' must be a decimal string with at most 4 decimals');
        var scaled = BigInt(m[1]) * RATE_SCALE + BigInt((m[2] || '').padEnd(4, '0'));
        if (scaled > 100n * RATE_SCALE) fail(field + ' must be between 0 and 100');
        return scaled;
    }
    // round_half_up(amount × rate / 100)
    function percentOf(a, scaledRate) {
        var den = 100n * RATE_SCALE;
        return (a * scaledRate * 2n + den) / (2n * den);
    }
    function tax(t, subtotal) {
        switch ((t && t.mode) || 'none') {
            case 'none': return 0n;
            case 'percentage': return subtotal > 0n ? percentOf(subtotal, rate(t.rate, 'tax.rate')) : 0n;
            case 'fixed': return subtotal > 0n ? amount(t.amount_minor, 'tax.amount_minor') : 0n;
            default: return fail('tax.mode must be none, percentage or fixed');
        }
    }
    function discount(d, subtotal) {
        var a;
        switch ((d && d.mode) || 'none') {
            case 'none': a = 0n; break;
            case 'amount': a = amount(d.amount_minor, 'discount.amount_minor'); break;
            case 'percent': a = percentOf(subtotal, rate(d.rate, 'discount.rate')); break;
            default: return fail('discount.mode must be none, amount or percent');
        }
        return a < subtotal ? a : subtotal;
    }

    function price(input) {
        var lines = [];
        var subtotal = 0n;
        (input.lines || []).forEach(function (line, i) {
            if (!line || typeof line !== 'object') fail('line ' + i + ' is not an object');
            var qty = int(line.qty, 'lines[' + i + '].qty', 1, 9999);
            var unit = amount(line.unit_minor, 'lines[' + i + '].unit_minor');
            ['extras_minor', 'options_minor'].forEach(function (key) {
                (line[key] || []).forEach(function (add, j) { unit += amount(add, 'lines[' + i + '].' + key + '[' + j + ']'); });
            });
            var a = cap(cap(unit, 'lines[' + i + '].unit') * qty, 'lines[' + i + ']');
            lines.push({ line_minor: Number(a) });
            subtotal = cap(subtotal + a, 'subtotal');
        });
        var disc = discount(input.discount || { mode: 'none' }, subtotal);
        // P6-271: tax on the subtotal after the discount ('gross' = the pre-P6-271 rule)
        var tx = tax(input.tax || { mode: 'none' }, input.tax_base === 'gross' ? subtotal : subtotal - disc);
        var service = amount(input.service_minor === undefined ? 0 : input.service_minor, 'service_minor');
        var tip = amount(input.tip_minor === undefined ? 0 : input.tip_minor, 'tip_minor');
        var total = subtotal + tx + service + tip - disc;
        if (total < 0n) total = 0n;
        return {
            lines: lines,
            subtotal_minor: Number(subtotal),
            discount_minor: Number(disc),
            tax_minor: Number(tx),
            service_minor: Number(service),
            tip_minor: Number(tip),
            total_minor: Number(total),
        };
    }

    return { price: price, MAX_MINOR: Number(MAX_MINOR) };
}));
