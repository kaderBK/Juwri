// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — the contract's CLOSED error vocabulary.
 *
 * rev/parity/contracts/print-bridge.md: `unauthorized` · `unpaired` ·
 * `bad_request` · `unknown_target` · `econnrefused` · `etimedout` ·
 * `device_busy` · `payload_too_large` · `internal`.
 *
 * Nothing else may ever reach the wire — T1's clients switch on these strings,
 * and an unrecognised one degrades into a dead end for the cashier. Anything we
 * cannot classify becomes `internal`, and the real cause goes to the local log.
 */

const ERRORS = Object.freeze([
    'unauthorized',
    'unpaired',
    'bad_request',
    'unknown_target',
    'econnrefused',
    'etimedout',
    'device_busy',
    'payload_too_large',
    'internal',
]);

class PrinterError extends Error {
    constructor(code, detail = '', cause = null) {
        super(code);
        this.name = 'PrinterError';
        this.code = ERRORS.includes(code) ? code : 'internal';
        this.detail = detail;
        this.cause = cause;
    }
}

/** Map a Node socket/USB failure onto the closed vocabulary. */
function classify(err) {
    if (err instanceof PrinterError) return err.code;

    const code = (err && err.code) || '';
    const message = String((err && err.message) || '').toLowerCase();

    switch (code) {
        case 'ETIMEDOUT':
        case 'ERR_SOCKET_CONNECTION_TIMEOUT':
            return 'etimedout';
        case 'ECONNREFUSED':
        case 'ECONNRESET':
        case 'EHOSTUNREACH':
        case 'ENETUNREACH':
        case 'EHOSTDOWN':
        case 'ENOTFOUND':
        case 'EAI_AGAIN':
        case 'EPIPE':
            return 'econnrefused';
        case 'EBUSY':
        case 'EACCES':
        case 'EADDRINUSE':
        case 'LIBUSB_ERROR_BUSY':
        case 'LIBUSB_ERROR_ACCESS':
            return 'device_busy';
        default:
            break;
    }

    if (message.includes('timed out') || message.includes('timeout')) return 'etimedout';
    if (message.includes('busy') || message.includes('in use')) return 'device_busy';

    return 'internal';
}

function ensureVocabulary(code) {
    return ERRORS.includes(code) ? code : 'internal';
}

module.exports = { ERRORS, PrinterError, classify, ensureVocabulary };
