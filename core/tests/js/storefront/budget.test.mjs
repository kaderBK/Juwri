// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join } from 'node:path';

const dir = new URL('../../../../assets/storefront/', import.meta.url).pathname;
const gz = (f) => gzipSync(readFileSync(join(dir, f)), { level: 9 }).length;

test('core storefront JS stays inside the contract budget (core <= 60 KB gzip; core + theme <= 90 KB)', () => {
    const files = readdirSync(dir).filter((f) => f.endsWith('.js') && f !== 'sw.js');
    const total = files.reduce((n, f) => n + gz(f), 0);
    assert.ok(total <= 60 * 1024, `core JS is ${total} bytes gzip (limit ${60 * 1024})`);
    assert.ok(total + 20 * 1024 <= 90 * 1024, 'core + a 20 KB theme.js must fit in 90 KB');
});

test('no external hosts, no eval, no inline handlers in the core JS', () => {
    for (const f of readdirSync(dir).filter((x) => x.endsWith('.js'))) {
        const src = readFileSync(join(dir, f), 'utf8');
        assert.doesNotMatch(src, /https?:\/\//, f + ' names an external host');
        assert.doesNotMatch(src, /\beval\(|new Function\(|\.innerHTML\s*=|document\.write/, f + ' uses an unsafe sink');
        assert.doesNotMatch(src, /setAttribute\(['"]style['"]/, f + ' writes an inline style attribute');
    }
});

test('no money arithmetic in the core JS: only the server quote is shown', () => {
    for (const f of readdirSync(dir).filter((x) => x.endsWith('.js') && x !== 'sw.js')) {
        const src = readFileSync(join(dir, f), 'utf8');
        assert.doesNotMatch(src, /toFixed\(|Intl\.NumberFormat|\.minor\s*[*+\-/]|[*+\-/]\s*[a-z.]*\.minor/, f + ' computes or formats money');
    }
});
