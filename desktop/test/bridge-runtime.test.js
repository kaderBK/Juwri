// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * The bridge's lifecycle inside the shell — the piece `main.js` drives.
 *
 * These bind the REAL contract port (8543), because "fail loudly on a clash" is
 * only meaningful on the port the web side actually probes. They run
 * sequentially within this file, and no other test file touches that port.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const net = require('node:net');

const { createBridgeRuntime } = require('../src/main/bridge-runtime');
const { createConfig, MemoryStore } = require('../src/main/config');
const { fakePrinter } = require('./helpers/harness');

const BRIDGE_PORT = 8543;

function runtimeWith(initial = {}) {
    const config = createConfig({
        store: new MemoryStore(Object.assign({
            serverUrl: 'https://menu.example.com',
            token: 'R'.repeat(40),
            printers: [],
        }, initial)),
        secureStorage: null,
    });

    return {
        config,
        runtime: createBridgeRuntime({
            config,
            version: '9.9.9',
            logger: { info() {}, warn() {}, error() {} },
        }),
    };
}

function occupy(port) {
    return new Promise((resolve) => {
        const server = net.createServer();
        server.once('error', () => resolve(null));
        server.listen(port, '127.0.0.1', () => resolve(server));
    });
}

test('a clash on the fixed port fails loudly instead of drifting', async (t) => {
    const blocker = await occupy(BRIDGE_PORT);
    t.after(() => blocker && new Promise((resolve) => blocker.close(resolve)));

    const { runtime } = runtimeWith();
    t.after(() => runtime.stop());

    const status = await runtime.start();

    // Either we took 8543 first, or something else on this machine already had
    // it — both are the same assertion: the bridge does NOT quietly move.
    assert.equal(status.listening, false);
    assert.equal(status.error, 'port_in_use');
});

test('start() returns a snapshot that stop() cannot mutate afterwards', async (t) => {
    const { runtime } = runtimeWith();

    const status = await runtime.start();
    assert.equal(status.listening, true, 'the port is free once the blocker is gone');
    assert.equal(status.port, BRIDGE_PORT);

    await runtime.stop();

    // Regression guard: `status` used to BE the runtime's live object, so calling
    // stop() rewrote the answer start() had already given — which made the smoke
    // check exit 1 on a perfectly healthy boot.
    assert.equal(status.listening, true, 'the caller\'s copy still reflects the start');
    assert.equal(runtime.status().listening, false);
});

test('the canned test ticket reaches a printer without any HTTP or pairing', async (t) => {
    const printer = fakePrinter();
    const port = await printer.listen();
    t.after(() => printer.close());

    const { runtime } = runtimeWith({
        printers: [{ id: `tcp://127.0.0.1:${port}`, label: 'Kitchen', widthMm: 80 }],
    });
    t.after(() => runtime.stop());

    const result = await runtime.testPrint(`tcp://127.0.0.1:${port}`);

    assert.deepEqual(result, { ok: true });
    await new Promise((resolve) => setTimeout(resolve, 50));

    const bytes = printer.bytes();
    assert.deepEqual(bytes.subarray(0, 2), Buffer.from([0x1b, 0x40]), 'ESC @');
    assert.ok(bytes.includes(Buffer.from('juwri')));
    assert.ok(bytes.includes(Buffer.from(`tcp://127.0.0.1:${port}`)), 'the ticket names the target it went to');
});

test('a test print to an unconfigured or malformed target is refused', async (t) => {
    const { runtime } = runtimeWith();
    t.after(() => runtime.stop());

    assert.deepEqual(await runtime.testPrint('tcp://10.0.0.77:9100'), { ok: false, error: 'unknown_target' });
    assert.deepEqual(await runtime.testPrint('nonsense'), { ok: false, error: 'bad_request' });
});

test('a test print to a dead printer reports the contract error, not an exception', async (t) => {
    const { runtime } = runtimeWith({
        printers: [{ id: 'tcp://127.0.0.1:1', label: 'Dead', widthMm: 80 }],
    });
    t.after(() => runtime.stop());

    const result = await runtime.testPrint('tcp://127.0.0.1:1');

    assert.equal(result.ok, false);
    assert.ok(['econnrefused', 'etimedout'].includes(result.error), result.error);
});
