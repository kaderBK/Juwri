// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — the shell's canned ESC/POS test ticket.
 *
 * Deliberately NOT rendered by the server: the point of "Test print" in the
 * settings window is to prove the cable, the IP and the printer independently of
 * the web app, login, and the pairing token. It must work on a fresh install
 * before anything else does.
 *
 * Real tickets are always rendered by `EscPosRenderer` server-side and arrive
 * base64-encoded over the bridge; nothing here is used for a customer receipt.
 */

const ESC = 0x1b;
const GS = 0x1d;

function bytes(...values) {
    return Buffer.from(values);
}

function line(text = '') {
    return Buffer.from(`${text}\n`, 'ascii');
}

/**
 * @param {{label?:string, target?:string, widthMm?:number, version?:string, when?:Date}} info
 * @returns {Buffer} raw ESC/POS
 */
function testTicket(info = {}) {
    const widthMm = info.widthMm === 57 ? 57 : 80;
    const cols = widthMm === 57 ? 32 : 48;
    const when = info.when || new Date();
    const rule = '-'.repeat(cols);

    const parts = [
        bytes(ESC, 0x40),             // ESC @  — initialise
        bytes(ESC, 0x61, 0x01),       // ESC a 1 — centre
        bytes(ESC, 0x21, 0x30),       // ESC ! 48 — double height + width
        line('juwri'),
        bytes(ESC, 0x21, 0x00),       // back to normal
        line('Desktop bridge test'),
        bytes(ESC, 0x61, 0x00),       // left
        line(rule),
        line(`Printer : ${info.label || '-'}`),
        line(`Target  : ${info.target || '-'}`),
        line(`Width   : ${widthMm} mm (${cols} cols)`),
        line(`Bridge  : v${info.version || '1.0.0'}`),
        line(`Time    : ${when.toISOString().replace('T', ' ').slice(0, 19)}`),
        line(rule),
        // A column ruler: if this wraps, the width setting is wrong.
        line('123456789012345678901234567890123456789012345678'.slice(0, cols)),
        line(),
        line('If you can read this, the bridge can reach'),
        line('this printer. Ticket printing will work.'),
        line(),
        line(),
        line(),
        bytes(GS, 0x56, 0x42, 0x00),  // GS V B 0 — feed and full cut
    ];

    return Buffer.concat(parts);
}

module.exports = { testTicket };
