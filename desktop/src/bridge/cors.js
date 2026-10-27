// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — CORS, per rev/parity/contracts/print-bridge.md §CORS.
 *
 * `Access-Control-Allow-Origin` is an ALLOWLIST of the store's own origin(s),
 * recorded at pairing time. Never `*`: this is an HTTP server on an operator's
 * machine, and any page in any tab could otherwise drive their printers.
 *
 * Two rules that are easy to get wrong and matter a lot:
 *
 *  1. A rejected origin is refused SERVER-SIDE, not merely starved of a header.
 *     Omitting the header only stops the attacker from READING the response —
 *     the print would still have happened.
 *  2. A request with NO `Origin` header is not a browser request (curl, or T1's
 *     server-side `PrintBridgeClient` on a single-host install). Those are
 *     allowed through; the bearer token is what authenticates them.
 */

/** Normalise a URL or origin to a bare scheme://host[:port], or null. */
function normalizeOrigin(value) {
    if (typeof value !== 'string' || value.trim() === '') return null;

    let raw = value.trim();

    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(raw)) {
        // A bare host is accepted (operators type `menu.example.com`), but only if
        // it actually looks like one. Without this, `new URL('https://' + junk)`
        // happily turns a typo into a real allowlist entry.
        const host = /^([a-z0-9][a-z0-9.-]*)(:\d{1,5})?$/i.exec(raw);
        const looksLikeHost = host && (host[1].includes('.') || host[2] || /^localhost$/i.test(host[1]));
        if (!looksLikeHost) return null;

        raw = 'https://' + raw;
    }

    try {
        const url = new URL(raw);
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;

        return url.origin;
    } catch (e) {
        return null;
    }
}

/** Build a deduped allowlist from whatever the operator typed at pairing time. */
function buildAllowlist(values) {
    const list = [];

    for (const value of Array.isArray(values) ? values : [values]) {
        const origin = normalizeOrigin(value);
        if (origin && !list.includes(origin)) list.push(origin);
    }

    return list;
}

/**
 * @returns {'allow'|'no-origin'|'reject'}
 *   'no-origin' means a non-browser caller — allowed, but nothing to echo.
 */
function evaluateOrigin(origin, allowlist) {
    if (typeof origin !== 'string' || origin === '' || origin === 'null') return 'no-origin';

    const normalized = normalizeOrigin(origin);
    if (!normalized) return 'reject';

    return allowlist.includes(normalized) ? 'allow' : 'reject';
}

/**
 * Headers to attach to an allowed response.
 *
 * `Access-Control-Allow-Private-Network` is not in the frozen contract, but
 * without it Chrome's Private Network Access check fails every request from a
 * public HTTPS page to loopback — i.e. the contract could not function at all in
 * a current browser. It is emitted ONLY for an already-allowlisted origin, so it
 * widens nothing. Flagged to the lead rather than changed silently.
 */
function corsHeaders(origin, { preflight = false, privateNetwork = false } = {}) {
    const headers = {
        'Access-Control-Allow-Origin': origin,
        'Vary': 'Origin',
    };

    if (preflight) {
        headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
        headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type';
        headers['Access-Control-Max-Age'] = '600';
        if (privateNetwork) headers['Access-Control-Allow-Private-Network'] = 'true';
    }

    return headers;
}

module.exports = { normalizeOrigin, buildAllowlist, evaluateOrigin, corsHeaders };
