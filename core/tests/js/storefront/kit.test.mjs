// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { normalize, pickVariant } from '../../../../assets/store_templates/_kit/kit.js';

test('search normalisation folds Arabic letter forms, tashkeel and case', () => {
    assert.equal(normalize('  Chicken SHAWARMA '), 'chicken shawarma');
    assert.equal(normalize('أكل'), normalize('اكل'));
    assert.equal(normalize('إيدام'), normalize('ايدام'));
    assert.equal(normalize('مَدرَسة'), normalize('مدرسه')); // tashkeel stripped, taa marbuta = haa
    assert.equal(normalize('على'), normalize('علي'));
    assert.equal(normalize('كـتاب'), normalize('كتاب')); // tatweel
});

test('the variant is the combination whose options equal the picks in the variant groups', () => {
    const variants = [
        { sku: 'S', options: { size: 's' } },
        { sku: 'M', options: { size: 'm' } },
        { sku: 'M-X', options: { size: 'm', crust: 'x' } },
    ];
    assert.equal(pickVariant(variants, { size: ['m'], crust: ['x'] }).variant.sku, 'M-X');
    assert.equal(pickVariant(variants, { size: ['s'] }).variant.sku, 'S');
    assert.equal(pickVariant(variants, { size: ['m'] }).variant.sku, 'M', 'no crust picked: the plain M');
    assert.equal(pickVariant(variants, { size: ['l'] }).variant, null);
    assert.equal(pickVariant(variants, {}).variant, null);
    assert.deepEqual([...pickVariant(variants, { size: ['m'] }).groups].sort(), ['crust', 'size']);
    assert.equal(pickVariant([], { size: ['s'] }), null);
});
