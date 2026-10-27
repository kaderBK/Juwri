// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — bearer pairing token, per rev/parity/contracts/print-bridge.md §Auth.
 *
 * The dashboard generates a 40-char token (`Str::random(40)`, stored per store);
 * the operator pastes it into the shell once. Every request carries it as
 * `Authorization: Bearer <token>`.
 *
 * The contract mandates `crypto.timingSafeEqual`. That primitive throws when the
 * two buffers differ in length — and the length of an attacker-supplied string is
 * itself a side channel — so both sides are hashed to a fixed 32 bytes first and
 * the digests are compared. Same guarantee, no length oracle, never throws.
 */

const crypto = require('node:crypto');

/** Constant-time comparison of two secrets of arbitrary length. */
function secretsMatch(expected, presented) {
    if (typeof expected !== 'string' || expected === '') return false;
    if (typeof presented !== 'string' || presented === '') return false;

    const a = crypto.createHash('sha256').update(expected, 'utf8').digest();
    const b = crypto.createHash('sha256').update(presented, 'utf8').digest();

    return crypto.timingSafeEqual(a, b);
}

/**
 * Pull the bearer token out of a request. Returns null when the header is
 * absent or is not a Bearer credential — never throws, never logs the value.
 */
function bearerToken(req) {
    const header = req && req.headers ? req.headers.authorization : null;
    if (typeof header !== 'string') return null;

    const match = /^Bearer\s+(.+)$/i.exec(header.trim());

    return match ? match[1].trim() : null;
}

/**
 * Decide the auth outcome for one request.
 *
 *   'unpaired'     → 409. The operator has not pasted a token into the shell yet,
 *                    so the dashboard can say "pair the desktop app" instead of
 *                    "wrong token".
 *   'unauthorized' → 401. A token is configured and the caller's does not match.
 *   null           → authorised.
 */
function authFailure(req, configuredToken) {
    if (typeof configuredToken !== 'string' || configuredToken === '') {
        return 'unpaired';
    }

    return secretsMatch(configuredToken, bearerToken(req)) ? null : 'unauthorized';
}

module.exports = { secretsMatch, bearerToken, authFailure };
