// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — test harness.
 *
 * Everything the contract specifies is exercised over REAL HTTP against the real
 * server object on an ephemeral loopback port. Only the printer itself is faked,
 * and even that comes in two flavours:
 *
 *   fakeTransport()   — records calls, so idempotency/auth/CORS assertions are
 *                       exact ("the transport was called ONCE") rather than
 *                       inferred;
 *   fakePrinter()     — an actual TCP server on 127.0.0.1 that speaks nothing and
 *                       just collects bytes, so the escpos-network path, the
 *                       socket lifecycle and the exact payload are covered end to
 *                       end without hardware.
 */

const http = require('node:http');
const net = require('node:net');

const { createBridgeServer } = require('../../src/bridge/server');

const TOKEN = 'A'.repeat(40);
const ORIGIN = 'https://menu.example.com';
const TARGET = 'tcp://127.0.0.1:9100';

function fakeTransport(behaviour = {}) {
    const calls = [];

    return {
        calls,
        kind: 'fake',
        print(target, bytes, meta) {
            calls.push({ target, bytes, meta });

            if (typeof behaviour.onPrint === 'function') return behaviour.onPrint(target, bytes, calls.length);

            return Promise.resolve();
        },
    };
}

/** Start the bridge on an ephemeral port and return a client bound to it. */
async function startBridge(options = {}) {
    const {
        token = TOKEN,
        origins = [ORIGIN],
        printers = [{ id: TARGET, label: 'Kitchen', kind: 'network', widthMm: 80 }],
        transport = fakeTransport(),
        ...rest
    } = options;

    const bridge = createBridgeServer({
        getConfig: () => ({ token, origins, printers }),
        transports: { network: transport, usb: transport, fake: transport },
        ...rest,
    });

    // The contract fixes 8543 in production; tests take an ephemeral port so a
    // developer running the real shell does not make the suite flaky.
    await new Promise((resolve, reject) => {
        bridge.server.once('error', reject);
        bridge.server.listen(0, '127.0.0.1', resolve);
    });

    const { port } = bridge.server.address();

    return {
        bridge,
        transport,
        port,
        base: `http://127.0.0.1:${port}`,
        request: (path, init) => request(`http://127.0.0.1:${port}${path}`, init),
        close: () => bridge.close(),
    };
}

/** Tiny fetch-alike over node:http so the suite has no dependencies. */
function request(url, { method = 'GET', headers = {}, body = null } = {}) {
    return new Promise((resolve, reject) => {
        const parsed = new URL(url);
        const payload = body === null ? null : Buffer.from(typeof body === 'string' ? body : JSON.stringify(body));

        const req = http.request({
            hostname: parsed.hostname,
            port: parsed.port,
            path: parsed.pathname + parsed.search,
            method,
            headers: Object.assign(
                payload ? { 'Content-Type': 'application/json', 'Content-Length': payload.length } : {},
                headers
            ),
        }, (res) => {
            const chunks = [];
            res.on('data', (chunk) => chunks.push(chunk));
            res.on('end', () => {
                const text = Buffer.concat(chunks).toString('utf8');
                let json = null;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    json = null;
                }
                resolve({ status: res.statusCode, headers: res.headers, text, json });
            });
        });

        req.on('error', reject);
        if (payload) req.write(payload);
        req.end();
    });
}

function printBody(overrides = {}) {
    return Object.assign({
        jobId: 'ord-91423-kitchen-1',
        target: TARGET,
        widthMm: 80,
        dataB64: Buffer.from('@HELLO\n').toString('base64'),
    }, overrides);
}

function authed(extra = {}) {
    return Object.assign({ Authorization: `Bearer ${TOKEN}`, Origin: ORIGIN }, extra);
}

/** A TCP server that behaves like a 9100 printer: accepts, swallows, records. */
function fakePrinter({ silentAccept = false } = {}) {
    const received = [];
    const sockets = [];

    const server = net.createServer((socket) => {
        sockets.push(socket);
        if (silentAccept) return; // accepts the connection and never reads
        socket.on('data', (chunk) => received.push(chunk));
        socket.on('error', () => {});
    });

    return {
        received,
        bytes: () => Buffer.concat(received),
        listen() {
            return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));
        },
        close() {
            for (const socket of sockets) socket.destroy();

            return new Promise((resolve) => server.close(() => resolve()));
        },
    };
}

/** An unused loopback port — bound, address taken, then released. */
function freePort() {
    return new Promise((resolve) => {
        const probe = net.createServer();
        probe.listen(0, '127.0.0.1', () => {
            const { port } = probe.address();
            probe.close(() => resolve(port));
        });
    });
}

module.exports = { startBridge, request, printBody, authed, fakeTransport, fakePrinter, freePort, TOKEN, ORIGIN, TARGET };
