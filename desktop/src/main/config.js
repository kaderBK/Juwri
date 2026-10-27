// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — persisted shell configuration.
 *
 * Everything the operator sets once and never thinks about again: the server URL
 * the shell wraps, the pairing token, the CORS allowlist derived from that URL,
 * and the printers. Backed by `electron-store` (per the contract's note) with an
 * injectable store so tests and the headless bridge runner need no Electron.
 *
 * The pairing token is a bearer credential for a device that can print money's
 * worth of tickets, so it is encrypted at rest with Electron's `safeStorage`
 * (OS keychain / DPAPI) whenever that is available. Where it is not — a Linux box
 * with no keyring, or the headless runner — it falls back to plaintext in the
 * store file, and the settings window says so rather than pretending otherwise.
 */

const { normalizeOrigin } = require('../bridge/cors');
const { canonicalId, parseTarget } = require('../bridge/targets');

const DEFAULTS = {
    serverUrl: '',
    extraOrigins: [],
    token: '',
    tokenEncrypted: '',
    printers: [],
    kiosk: false,
    autoLaunch: false,
    locale: 'en',
    firstRunDone: false,
};

/** Minimal in-memory store — used by tests and by `npm run bridge`. */
class MemoryStore {
    constructor(initial = {}) {
        this.data = { ...initial };
    }

    get(key, fallback) {
        return Object.prototype.hasOwnProperty.call(this.data, key) ? this.data[key] : fallback;
    }

    set(key, value) {
        this.data[key] = value;
    }
}

function loadElectronStore() {
    // eslint-disable-next-line global-require
    const Store = require('electron-store');

    return new Store({
        name: 'juwri-desktop',
        defaults: DEFAULTS,
        clearInvalidConfig: true,
    });
}

function createConfig({ store, secureStorage } = {}) {
    const backing = store || loadElectronStore();

    const secure = () => {
        if (secureStorage !== undefined) return secureStorage;
        try {
            // eslint-disable-next-line global-require
            const { safeStorage } = require('electron');

            return safeStorage && safeStorage.isEncryptionAvailable() ? safeStorage : null;
        } catch (e) {
            return null;
        }
    };

    function readToken() {
        const encrypted = backing.get('tokenEncrypted', '');
        if (encrypted) {
            const box = secure();
            if (!box) return '';
            try {
                return box.decryptString(Buffer.from(encrypted, 'base64'));
            } catch (e) {
                return '';
            }
        }

        return String(backing.get('token', '') || '');
    }

    function writeToken(token) {
        const value = String(token || '').trim();

        if (value === '') {
            backing.set('token', '');
            backing.set('tokenEncrypted', '');

            return { stored: true, encrypted: false };
        }

        const box = secure();
        if (box) {
            backing.set('tokenEncrypted', box.encryptString(value).toString('base64'));
            backing.set('token', '');

            return { stored: true, encrypted: true };
        }

        backing.set('token', value);
        backing.set('tokenEncrypted', '');

        return { stored: true, encrypted: false };
    }

    /**
     * The CORS allowlist. It is DERIVED from the server URL the operator paired
     * against — that is what "the store's own origin(s) recorded at pairing"
     * means — plus any extra origin they add by hand (custom domain, staging).
     */
    function origins() {
        const list = [];
        for (const value of [backing.get('serverUrl', ''), ...(backing.get('extraOrigins', []) || [])]) {
            const origin = normalizeOrigin(value);
            if (origin && !list.includes(origin)) list.push(origin);
        }

        return list;
    }

    function printers() {
        const rows = backing.get('printers', []) || [];

        return rows
            .map((row) => {
                const target = parseTarget(row.id);
                if (!target) return null;

                return {
                    id: target.id,
                    label: String(row.label || target.id).slice(0, 60),
                    kind: target.kind,
                    widthMm: row.widthMm === 57 ? 57 : 80,
                };
            })
            .filter(Boolean);
    }

    return {
        store: backing,

        all() {
            return {
                serverUrl: String(backing.get('serverUrl', '') || ''),
                extraOrigins: backing.get('extraOrigins', []) || [],
                origins: origins(),
                printers: printers(),
                kiosk: !!backing.get('kiosk', false),
                autoLaunch: !!backing.get('autoLaunch', false),
                locale: String(backing.get('locale', 'en') || 'en'),
                firstRunDone: !!backing.get('firstRunDone', false),
                paired: readToken() !== '',
                tokenEncrypted: !!backing.get('tokenEncrypted', ''),
            };
        },

        /** What the bridge itself needs. Never leaves the main process. */
        bridgeConfig() {
            return { token: readToken(), origins: origins(), printers: printers() };
        },

        serverUrl: () => String(backing.get('serverUrl', '') || ''),

        setServerUrl(url) {
            const origin = normalizeOrigin(url);
            if (!origin) throw new Error('invalid_server_url');

            // Keep the full URL (it may carry a path such as /staff/pos) but the
            // allowlist only ever contains the origin.
            let full = String(url).trim();
            if (!/^https?:\/\//i.test(full)) full = 'https://' + full;

            backing.set('serverUrl', full);
            backing.set('firstRunDone', true);

            return full;
        },

        setExtraOrigins(list) {
            const clean = [];
            for (const value of Array.isArray(list) ? list : String(list || '').split(/[\s,]+/)) {
                const origin = normalizeOrigin(value);
                if (origin && !clean.includes(origin)) clean.push(origin);
            }
            backing.set('extraOrigins', clean);

            return clean;
        },

        origins,
        printers,
        token: readToken,
        setToken: writeToken,
        paired: () => readToken() !== '',

        /** Add or update one printer, keyed by its canonical target id. */
        upsertPrinter({ id, label, widthMm }) {
            const target = parseTarget(id);
            if (!target) throw new Error('invalid_target');

            const rows = (backing.get('printers', []) || []).filter((row) => canonicalId(row.id) !== target.id);
            rows.push({
                id: target.id,
                label: String(label || target.id).slice(0, 60),
                widthMm: Number(widthMm) === 57 ? 57 : 80,
            });
            backing.set('printers', rows);

            return rows;
        },

        removePrinter(id) {
            const target = canonicalId(id);
            const rows = (backing.get('printers', []) || []).filter((row) => canonicalId(row.id) !== target);
            backing.set('printers', rows);

            return rows;
        },

        set(key, value) {
            if (!Object.prototype.hasOwnProperty.call(DEFAULTS, key)) throw new Error('unknown_setting');
            backing.set(key, value);

            return value;
        },

        get(key, fallback) {
            return backing.get(key, fallback);
        },
    };
}

module.exports = { createConfig, MemoryStore, DEFAULTS };
