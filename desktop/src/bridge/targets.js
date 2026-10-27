// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — target parsing, per rev/parity/contracts/print-bridge.md §POST /print.
 *
 * `target` is `tcp://<host>:<port>` or `usb` or `usb://<vid>/<pid>`, and "must
 * match an id from /health or a tcp:// the operator configured". Parsing is kept
 * separate from the allowlist check so the server can tell the two failures apart:
 * an unparseable target is `bad_request` (the caller is broken), a well-formed
 * target nobody configured is `unknown_target` (the operator is missing a setup step).
 */

const DEFAULT_TCP_PORT = 9100;

/**
 * @returns {{kind:'network',id:string,host:string,port:number}
 *          |{kind:'usb',id:string,vendorId:?number,productId:?number}
 *          |null}
 */
function parseTarget(value) {
    if (typeof value !== 'string') return null;

    const raw = value.trim();
    if (raw === '') return null;

    if (raw === 'usb') {
        return { kind: 'usb', id: 'usb', vendorId: null, productId: null };
    }

    const usb = /^usb:\/\/(0x[0-9a-f]+|\d+)\/(0x[0-9a-f]+|\d+)$/i.exec(raw);
    if (usb) {
        const vendorId = Number(usb[1]);
        const productId = Number(usb[2]);
        if (!Number.isInteger(vendorId) || !Number.isInteger(productId)) return null;

        return { kind: 'usb', id: raw.toLowerCase(), vendorId, productId };
    }

    if (!/^tcp:\/\//i.test(raw)) return null;

    // `new URL` handles IPv6 literals and rejects nonsense hosts for us.
    let url;
    try {
        url = new URL(raw.replace(/^tcp:\/\//i, 'http://'));
    } catch (e) {
        return null;
    }

    if (url.hostname === '' || url.pathname !== '/' || url.search !== '' || url.username !== '') {
        return null;
    }

    const port = url.port === '' ? DEFAULT_TCP_PORT : Number(url.port);
    if (!Number.isInteger(port) || port < 1 || port > 65535) return null;

    // Strip the brackets IPv6 literals carry inside a URL; net.connect wants the bare address.
    const host = url.hostname.replace(/^\[|\]$/g, '');

    return { kind: 'network', id: `tcp://${url.hostname}:${port}`, host, port };
}

/** Case/port normalisation so `tcp://IP:9100` and `tcp://ip:9100` are one printer. */
function canonicalId(value) {
    const parsed = parseTarget(value);

    return parsed ? parsed.id : null;
}

module.exports = { parseTarget, canonicalId, DEFAULT_TCP_PORT };
