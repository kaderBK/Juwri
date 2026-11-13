// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — shared helpers (plain ES module, no dependencies, no DOM needed). */

const HEX = '0123456789abcdef';
let lastMs = 0;
let seq = 0;

function randomBytes(n) {
    const out = new Uint8Array(n);
    const c = globalThis.crypto;
    if (c && c.getRandomValues) {
        c.getRandomValues(out);
    } else {
        for (let i = 0; i < n; i++) out[i] = Math.floor(Math.random() * 256);
    }
    return out;
}

/**
 * UUIDv7 (RFC 9562): 48-bit unix-ms timestamp, version 7, 12-bit counter (monotonic inside one millisecond),
 * variant 10, 62 random bits. The client mints one per checkout attempt (J27-A2).
 */
export function uuidv7(now = Date.now()) {
    if (now <= lastMs) {
        now = lastMs;
        seq += 1;
        if (seq > 0xfff) { now += 1; seq = 0; }
    } else {
        seq = 0;
    }
    lastMs = now;
    const b = randomBytes(16);
    const ms = BigInt(now);
    for (let i = 0; i < 6; i++) b[i] = Number((ms >> BigInt(8 * (5 - i))) & 0xffn);
    b[6] = 0x70 | ((seq >> 8) & 0x0f);
    b[7] = seq & 0xff;
    b[8] = 0x80 | (b[8] & 0x3f);
    let s = '';
    for (let i = 0; i < 16; i++) {
        s += HEX[b[i] >> 4] + HEX[b[i] & 15];
        if (i === 3 || i === 5 || i === 7 || i === 9) s += '-';
    }
    return s;
}

export const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

/** Timestamp (ms) a UUIDv7 carries, or null for any other version. */
export function uuidv7Time(id) {
    if (typeof id !== 'string' || id[14] !== '7' || !UUID_RE.test(id)) return null;
    return parseInt(id.slice(0, 8) + id.slice(9, 13), 16);
}

/** Stable key of a cart line: the same item + variant + options + note is the same line. */
export function lineKey(line) {
    const opts = (line.options || []).slice().sort().join(',');
    const key = [line.item, line.variant || '', opts, (line.note || '').trim()];
    if (line.substitution) key.push(line.substitution); // grocery: another out-of-stock choice is another line (older keys keep their shape)
    return key.join('|');
}

/** Fire a `juwri:<name>` event on the document (bubbles; `detail` carries the data). No-op without a DOM. */
export function emit(name, detail) {
    const d = globalThis.document;
    if (!d || typeof globalThis.CustomEvent !== 'function') return;
    d.dispatchEvent(new globalThis.CustomEvent('juwri:' + name, { detail, bubbles: true }));
}

export const clone = (v) => (v === undefined ? undefined : JSON.parse(JSON.stringify(v)));

/** A JSON error answer in the platform's ApiError shape, or a plain error from a status. */
export function apiError(status, body) {
    const e = new Error((body && body.message) || 'Request failed');
    e.status = status;
    e.code = (body && body.code) || null;
    e.errors = (body && body.errors) || null;
    e.body = body || null;
    return e;
}
