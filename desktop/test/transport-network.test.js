// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * The raw TCP lane — the only reason the desktop shell exists.
 *
 * These run against a REAL `net` server on loopback playing the part of a 9100
 * printer, driven through the REAL `escpos-network` adapter. Everything except
 * the printer's firmware is therefore covered without hardware: connect, write,
 * the exact bytes on the wire, FIN, refusal, and both timeout budgets.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');

const {
    createNetworkTransport,
    sendToNetworkPrinter,
    CONNECT_TIMEOUT_MS,
    WRITE_TIMEOUT_MS,
} = require('../src/bridge/transport/network');
const { startBridge, printBody, authed, fakePrinter, freePort } = require('./helpers/harness');
const { parseTarget } = require('../src/bridge/targets');

test('the contract\'s timeout budgets are the defaults', () => {
    assert.equal(CONNECT_TIMEOUT_MS, 5000, 'connect timeout: 5 s');
    assert.equal(WRITE_TIMEOUT_MS, 10000, 'write timeout: 10 s');
});

test('bytes arrive on the socket exactly as rendered', async (t) => {
    const printer = fakePrinter();
    const port = await printer.listen();
    t.after(() => printer.close());

    const payload = Buffer.from([0x1b, 0x40, 0x54, 0x45, 0x53, 0x54, 0x0a, 0x1d, 0x56, 0x42, 0x00]);

    await sendToNetworkPrinter(parseTarget(`tcp://127.0.0.1:${port}`), payload);

    // Give the socket a beat to deliver the FIN-flushed tail.
    await new Promise((resolve) => setTimeout(resolve, 50));
    assert.deepEqual(printer.bytes(), payload);
});

test('a full job flows through HTTP to a real socket', async (t) => {
    const printer = fakePrinter();
    const port = await printer.listen();
    t.after(() => printer.close());

    const target = `tcp://127.0.0.1:${port}`;
    const ctx = await startBridge({
        printers: [{ id: target, label: 'Kitchen', widthMm: 80 }],
        transport: createNetworkTransport(),
    });
    t.after(() => ctx.close());

    const raw = Buffer.from('\x1b@KITCHEN TICKET\n\x1dVB\x00', 'binary');
    const res = await ctx.request('/print', {
        method: 'POST',
        headers: authed(),
        body: printBody({ target, dataB64: raw.toString('base64') }),
    });

    assert.equal(res.status, 200);
    assert.equal(res.json.ok, true);

    await new Promise((resolve) => setTimeout(resolve, 50));
    assert.deepEqual(printer.bytes(), raw);

    // …and the replay must not put a second ticket on the same socket.
    await ctx.request('/print', {
        method: 'POST', headers: authed(), body: printBody({ target, dataB64: raw.toString('base64') }),
    });
    await new Promise((resolve) => setTimeout(resolve, 50));
    assert.deepEqual(printer.bytes(), raw, 'still exactly one ticket');
});

test('a closed port is econnrefused, with the host:port as detail', async () => {
    const port = await freePort();

    await assert.rejects(
        () => sendToNetworkPrinter(parseTarget(`tcp://127.0.0.1:${port}`), Buffer.from('x')),
        (err) => {
            assert.equal(err.code, 'econnrefused');
            assert.equal(err.detail, `127.0.0.1:${port}`);

            return true;
        }
    );
});

test('an unroutable host times out rather than hanging forever', async () => {
    // 192.0.2.0/24 is TEST-NET-1: guaranteed to route nowhere.
    await assert.rejects(
        () => sendToNetworkPrinter(parseTarget('tcp://192.0.2.1:9100'), Buffer.from('x'), { connectTimeoutMs: 120 }),
        (err) => ['etimedout', 'econnrefused'].includes(err.code)
    );
});

/** An adapter that mimics escpos-network's API but never completes a phase. */
function stalledAdapter({ stallAt }) {
    return () => {
        const adapter = new EventEmitter();
        adapter.device = new EventEmitter();
        adapter.device.end = () => {};
        adapter.open = (callback) => {
            if (stallAt !== 'connect') setImmediate(() => callback(null, adapter.device));

            return adapter;
        };
        adapter.write = () => adapter; // never calls back
        adapter.closed = false;
        adapter.close = () => {
            adapter.closed = true;
        };

        return adapter;
    };
}

test('a printer that accepts and never acknowledges hits the write timeout', async () => {
    await assert.rejects(
        () => sendToNetworkPrinter(parseTarget('tcp://127.0.0.1:9100'), Buffer.from('x'), {
            createAdapter: stalledAdapter({ stallAt: 'write' }),
            connectTimeoutMs: 200,
            writeTimeoutMs: 60,
        }),
        (err) => err.code === 'etimedout'
    );
});

test('a printer that never completes the connect hits the connect timeout', async () => {
    await assert.rejects(
        () => sendToNetworkPrinter(parseTarget('tcp://127.0.0.1:9100'), Buffer.from('x'), {
            createAdapter: stalledAdapter({ stallAt: 'connect' }),
            connectTimeoutMs: 40,
        }),
        (err) => err.code === 'etimedout'
    );
});

test('a late socket error after success cannot re-settle the promise', async () => {
    let adapterRef;
    const createAdapter = () => {
        const adapter = new EventEmitter();
        adapter.device = new EventEmitter();
        adapter.device.end = () => adapter.device.emit('close');
        adapter.open = (callback) => {
            adapterRef = { fail: (err) => callback(err) };
            setImmediate(() => callback(null, adapter.device));

            return adapter;
        };
        adapter.write = (data, callback) => {
            setImmediate(callback);

            return adapter;
        };
        adapter.close = () => {};

        return adapter;
    };

    await sendToNetworkPrinter(parseTarget('tcp://127.0.0.1:9100'), Buffer.from('x'), { createAdapter });

    // escpos-network routes every error through the same callback, including ones
    // that arrive after we are done. This must be a no-op, not an unhandled
    // rejection that takes the bridge down.
    assert.doesNotThrow(() => adapterRef.fail(Object.assign(new Error('late'), { code: 'ECONNRESET' })));
});

test('two jobs for one printer are serialised, not interleaved', async (t) => {
    const printer = fakePrinter();
    const port = await printer.listen();
    t.after(() => printer.close());

    const transport = createNetworkTransport();
    const target = parseTarget(`tcp://127.0.0.1:${port}`);

    await Promise.all([
        transport.print(target, Buffer.from('AAAA')),
        transport.print(target, Buffer.from('BBBB')),
    ]);

    await new Promise((resolve) => setTimeout(resolve, 60));
    const text = printer.bytes().toString();
    assert.ok(text === 'AAAABBBB' || text === 'BBBBAAAA', `interleaved: ${text}`);
});

test('a printer queue that is hopelessly backed up reports device_busy', async () => {
    const transport = createNetworkTransport({
        createAdapter: stalledAdapter({ stallAt: 'write' }),
        connectTimeoutMs: 50,
        writeTimeoutMs: 50,
    });
    const target = parseTarget('tcp://127.0.0.1:9100');

    const running = [];
    for (let i = 0; i < 8; i++) running.push(transport.print(target, Buffer.from('x')).catch(() => {}));

    await assert.rejects(
        () => transport.print(target, Buffer.from('x')),
        (err) => err.code === 'device_busy'
    );

    await Promise.all(running.map((p) => p.catch(() => {})));
});
