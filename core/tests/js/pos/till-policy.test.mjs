// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// Offline till start-up rules (pos-device.js TillPolicy; owner Q5: unsynced records are never deleted silently).
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const P = require('../../../../assets/pos/pos-device.js');
const now = Date.parse('2026-10-12T12:00:00Z');
const DAY = 86400000;
const base = { online: false, serverWipe: false, leaseUntil: '2026-10-14T12:00:00Z', oldestQueuedAt: null, retentionDays: 7, now };

test('revoked or wipe asked, online: upload first, then wipe everything and report', () => {
    assert.deepEqual(P.onStart({ ...base, online: true, serverWipe: true, oldestQueuedAt: now - DAY }),
        { upload: true, wipeQueue: true, wipeSnapshot: true, wipePins: true, blockSales: 'wiped', report: true });
});

test('3 days without contact: snapshot and PIN data wiped, sales blocked, the unsynced queue KEPT', () => {
    const p = P.onStart({ ...base, leaseUntil: '2026-10-12T11:59:00Z', oldestQueuedAt: now - 2 * DAY });
    assert.deepEqual(p, { upload: false, wipeQueue: false, wipeSnapshot: true, wipePins: true, blockSales: 'lease', report: false });
});

test('a revoked till that never reconnects only loses snapshot and PINs when its lease ends (it cannot know it is revoked)', () => {
    const p = P.onStart({ ...base, serverWipe: false, leaseUntil: '2026-10-09T12:00:00Z', oldestQueuedAt: now - 3 * DAY });
    assert.equal(p.wipeQueue, false);
    assert.equal(p.blockSales, 'lease');
});

test('an unsynced record older than 7 days blocks new sales and deletes nothing', () => {
    const p = P.onStart({ ...base, oldestQueuedAt: now - 8 * DAY });
    assert.deepEqual(p, { upload: false, wipeQueue: false, wipeSnapshot: false, wipePins: false, blockSales: 'retention', report: false });
    assert.equal(P.onStart({ ...base, oldestQueuedAt: now - 6 * DAY }).blockSales, null);
});

test('online and in good standing: upload, nothing wiped, selling', () => {
    assert.deepEqual(P.onStart({ ...base, online: true, oldestQueuedAt: now - DAY }),
        { upload: true, wipeQueue: false, wipeSnapshot: false, wipePins: false, blockSales: null, report: false });
});

test('online with a record past retention: it uploads now; until it is gone, sales stay blocked', () => {
    assert.equal(P.onStart({ ...base, online: true, oldestQueuedAt: now - 9 * DAY }).blockSales, 'retention');
});
