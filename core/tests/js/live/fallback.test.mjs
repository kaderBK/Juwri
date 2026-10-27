// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (p) => readFileSync(new URL('../../../../' + p, import.meta.url), 'utf8');

/**
 * P5-19 G4 static guard: the three live screens must keep working with no socket.
 * Each keeps its own polling timer, and the live listener is only an accelerator
 * behind JuwriLiveWhenReady (which is undefined while broadcasting is off). No
 * screen may ship a Reverb secret to the browser.
 */
const SCREENS = [
    {
        name: 'orders index',
        path: 'core/resources/views/owner/orders/index.blade.php',
        poll: /setInterval\(poll,\s*25000\)/,
    },
    {
        name: 'kds',
        path: 'core/resources/views/owner/kitchen/kds.blade.php',
        poll: /setInterval\(\(\)\s*=>\s*this\.poll\(\),\s*6000\)/,
    },
    {
        name: 'pos-hold',
        path: 'assets/pos/pos-hold.js',
        poll: /setInterval\(poll,\s*30000\)/,
    },
];

for (const screen of SCREENS) {
    test(`${screen.name}: keeps its polling timer and gates the live listener behind JuwriLiveWhenReady`, () => {
        const src = read(screen.path);
        assert.match(src, screen.poll, `${screen.name} must keep its polling timer`);
        assert.match(src, /JuwriLiveWhenReady/, `${screen.name} must wrap the live listener in JuwriLiveWhenReady`);
        assert.match(src, /JuwriLive\.listen\(/, `${screen.name} must still register a live listener`);
    });

    test(`${screen.name}: ships no Reverb secret`, () => {
        const src = read(screen.path);
        assert.doesNotMatch(src, /REVERB_APP_SECRET/);
        assert.doesNotMatch(src, /\bsecret\b/i);
    });
}
