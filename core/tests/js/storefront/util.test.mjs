// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { UUID_RE, lineKey, uuidv7, uuidv7Time } from '../../../../assets/storefront/util.js';

test('uuidv7 is a valid RFC 9562 version 7 / variant 10 UUID', () => {
    for (let i = 0; i < 200; i++) {
        const id = uuidv7();
        assert.match(id, UUID_RE);
        assert.equal(id[14], '7');
        assert.ok('89ab'.includes(id[19]));
    }
});

test('uuidv7 carries the millisecond timestamp and sorts in creation order, also inside one millisecond', () => {
    const t = 1_800_000_000_000;
    assert.equal(uuidv7Time(uuidv7(t + 5000)), t + 5000);
    const ids = [];
    for (let i = 0; i < 50; i++) ids.push(uuidv7(t + 100_000)); // same ms: the counter keeps them ordered
    assert.deepEqual([...ids].sort(), ids);
    assert.equal(new Set(ids).size, 50);
});

test('uuidv7Time refuses other versions and junk', () => {
    assert.equal(uuidv7Time('3b241101-e2bb-4255-8caf-4136c566a962'), null);
    assert.equal(uuidv7Time('nope'), null);
});

test('lineKey: same item + variant + options (any order) + note is one line', () => {
    assert.equal(lineKey({ item: 'a', options: ['y', 'x'], note: ' hi ' }), lineKey({ item: 'a', options: ['x', 'y'], note: 'hi' }));
    assert.notEqual(lineKey({ item: 'a' }), lineKey({ item: 'a', variant: 'v' }));
});
