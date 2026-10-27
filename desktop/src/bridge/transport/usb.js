// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — USB lane ("USB adapter where feasible", T8 spec §2).
 *
 * `escpos-usb` needs the native `usb`/libusb binding, which has to be rebuilt
 * against Electron's ABI and, on Windows, needs a WinUSB driver bound to the
 * printer. That is a per-machine setup step we cannot make a hard install
 * dependency without breaking `npm install` for every operator who only owns
 * network printers — and the till-attached printer already has TWO working lanes
 * in T1 (Web Serial / WebUSB straight from the page).
 *
 * So the module is loaded lazily and optionally: present ⇒ USB targets print;
 * absent ⇒ the job fails with `internal` + a detail the settings window explains,
 * and every other lane is unaffected. `npm run add:usb` installs it.
 */

const { PrinterError, classify } = require('../errors');

const WRITE_TIMEOUT_MS = 10000;

let cached; // undefined = not tried yet, null = unavailable

function loadUsbAdapter() {
    if (cached !== undefined) return cached;

    try {
        // eslint-disable-next-line import/no-extraneous-dependencies, global-require
        cached = require('escpos-usb');
    } catch (e) {
        cached = null;
    }

    return cached;
}

function usbAvailable() {
    return loadUsbAdapter() !== null;
}

function sendToUsbPrinter(target, bytes, options = {}) {
    const { loadAdapter = loadUsbAdapter, writeTimeoutMs = WRITE_TIMEOUT_MS } = options;
    const USB = loadAdapter();

    if (!USB) {
        return Promise.reject(new PrinterError('internal', 'usb_driver_unavailable'));
    }

    return new Promise((resolve, reject) => {
        let settled = false;
        let device;
        let timer = null;

        const done = (err) => {
            if (settled) return;
            settled = true;
            if (timer) clearTimeout(timer);
            try {
                if (device && typeof device.close === 'function') device.close();
            } catch (e) {
                /* already closed */
            }
            if (err) reject(err instanceof PrinterError ? err : new PrinterError(classify(err), target.id, err));
            else resolve();
        };

        try {
            device = target.vendorId === null
                ? new USB()
                : new USB(target.vendorId, target.productId);
        } catch (e) {
            done(e);

            return;
        }

        timer = setTimeout(() => done(new PrinterError('etimedout', target.id)), writeTimeoutMs);

        try {
            device.open((err) => {
                if (err) {
                    done(err);

                    return;
                }

                device.write(bytes, (writeErr) => done(writeErr || null));
            });
        } catch (e) {
            done(e);
        }
    });
}

function createUsbTransport(options = {}) {
    let chain = Promise.resolve();

    return {
        kind: 'usb',
        available: () => (options.loadAdapter ? options.loadAdapter() !== null : usbAvailable()),

        print(target, bytes) {
            const run = chain.catch(() => {}).then(() => sendToUsbPrinter(target, bytes, options));
            chain = run.catch(() => {});

            return run;
        },
    };
}

module.exports = { createUsbTransport, sendToUsbPrinter, usbAvailable };
