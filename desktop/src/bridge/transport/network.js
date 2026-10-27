// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — raw TCP 9100, via node-escpos's Network adapter (DECISION D2).
 *
 * This is the whole reason the desktop shell exists: a browser cannot open a raw
 * socket, so `tcp://<ip>:9100` is unreachable from the page. The bytes arrive
 * already rendered by `EscPosRenderer` — nothing here interprets or re-encodes
 * them, it only moves them.
 *
 * `escpos-network` is a thin wrapper over `net.Socket` with NO timeouts and an
 * error callback that can fire more than once, so this module layers on:
 *
 *   • the contract's 5 s connect / 10 s write budgets;
 *   • once-only settlement (a late error after we resolved must not re-reject);
 *   • a FIN + drain grace before closing, because `adapter.close()` calls
 *     `socket.destroy()`, which can discard bytes the kernel has not sent yet —
 *     a truncated kitchen ticket is worse than none;
 *   • per-target serialisation, since two sockets writing to one 9100 printer
 *     interleave into garbage.
 *
 * `createAdapter` is injectable so the whole path is testable against a fake
 * printer on loopback, and the timeout branches are testable without waiting.
 */

const { PrinterError, classify } = require('../errors');

const CONNECT_TIMEOUT_MS = 5000;
const WRITE_TIMEOUT_MS = 10000;
const DRAIN_GRACE_MS = 1000;
const MAX_QUEUE_DEPTH = 8;

function defaultAdapterFactory(host, port) {
    // Required lazily so the bridge is unit-testable without the dependency present.
    const Network = require('escpos-network');

    return new Network(host, port);
}

/**
 * Send one payload to one network printer.
 *
 * @returns {Promise<void>} rejects with a PrinterError carrying a code from the
 *                          contract's closed vocabulary.
 */
function sendToNetworkPrinter(target, bytes, options = {}) {
    const {
        createAdapter = defaultAdapterFactory,
        connectTimeoutMs = CONNECT_TIMEOUT_MS,
        writeTimeoutMs = WRITE_TIMEOUT_MS,
        drainGraceMs = DRAIN_GRACE_MS,
    } = options;

    const detail = `${target.host}:${target.port}`;

    return new Promise((resolve, reject) => {
        let settled = false;
        let phase = 'connect';
        let timer = null;
        let adapter;

        const clearTimer = () => {
            if (timer) clearTimeout(timer);
            timer = null;
        };

        const shutdown = () => {
            try {
                if (adapter && typeof adapter.close === 'function') adapter.close();
            } catch (e) {
                /* the socket is already gone — nothing to salvage */
            }
        };

        const fail = (code, cause) => {
            if (settled) return;
            settled = true;
            clearTimer();
            shutdown();
            reject(new PrinterError(code, detail, cause));
        };

        const succeed = () => {
            if (settled) return;
            settled = true;
            clearTimer();
            shutdown();
            resolve();
        };

        try {
            adapter = createAdapter(target.host, target.port);
        } catch (e) {
            fail(classify(e), e);

            return;
        }

        timer = setTimeout(() => fail('etimedout', new Error('connect timeout')), connectTimeoutMs);

        const write = () => {
            phase = 'write';
            clearTimer();
            timer = setTimeout(() => fail('etimedout', new Error('write timeout')), writeTimeoutMs);

            try {
                adapter.write(bytes, () => {
                    if (settled) return;
                    drainThenFinish();
                });
            } catch (e) {
                fail(classify(e), e);
            }
        };

        // The write callback fires when the data reaches the kernel buffer. Send a
        // FIN and wait briefly for the close so the printer really got the bytes,
        // but never let that grace period become a new way to hang.
        const drainThenFinish = () => {
            const device = adapter && adapter.device;

            if (!device || typeof device.end !== 'function') {
                succeed();

                return;
            }

            clearTimer();
            timer = setTimeout(succeed, drainGraceMs);

            try {
                device.once('close', succeed);
                device.end();
            } catch (e) {
                succeed();
            }
        };

        try {
            adapter.open((err) => {
                if (settled) return;

                if (err) {
                    // escpos-network routes every socket error through this same
                    // callback, whichever phase we are in.
                    fail(classify(err), err);

                    return;
                }

                if (phase === 'connect') write();
            });
        } catch (e) {
            fail(classify(e), e);
        }
    });
}

/**
 * A transport with one serialised lane per target id, so a burst of tickets for
 * the same printer never opens two sockets at once.
 */
function createNetworkTransport(options = {}) {
    const lanes = new Map(); // targetId -> { depth, tail: Promise }

    return {
        kind: 'network',

        print(target, bytes) {
            const lane = lanes.get(target.id) || { depth: 0, tail: Promise.resolve() };

            if (lane.depth >= MAX_QUEUE_DEPTH) {
                return Promise.reject(new PrinterError('device_busy', `${target.host}:${target.port}`));
            }

            lane.depth += 1;
            const run = lane.tail
                .catch(() => {})
                .then(() => sendToNetworkPrinter(target, bytes, options));

            lane.tail = run.catch(() => {}).then(() => {
                lane.depth -= 1;
                if (lane.depth <= 0) lanes.delete(target.id);
            });

            lanes.set(target.id, lane);

            return run;
        },
    };
}

module.exports = {
    createNetworkTransport,
    sendToNetworkPrinter,
    defaultAdapterFactory,
    CONNECT_TIMEOUT_MS,
    WRITE_TIMEOUT_MS,
    MAX_QUEUE_DEPTH,
};
