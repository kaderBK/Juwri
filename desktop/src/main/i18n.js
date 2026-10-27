// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * PARITY-RUN T8 — the shell's own i18n.
 *
 * Per the T0 spec correction, this lives in `desktop/`, NOT in `core/lang`: the
 * shell's strings are about pairing, ports and printers, and pushing them through
 * the app's `___()` helper would both pollute eleven server locale files and
 * collide with existing keys (the `___()` slug is case-insensitive).
 *
 * EN + AR ship; every string is keyed, so adding a locale is dropping a JSON file
 * next to these two and listing it in LOCALES.
 */

const en = require('../locales/en.json');
const ar = require('../locales/ar.json');

const CATALOGS = { en, ar };
const LOCALES = Object.keys(CATALOGS);

function createI18n(initial = 'en') {
    let locale = CATALOGS[initial] ? initial : 'en';

    const t = (key, fallback) => {
        const catalog = CATALOGS[locale] || en;

        return catalog[key] || en[key] || fallback || key;
    };

    return {
        get locale() {
            return locale;
        },
        setLocale(next) {
            locale = CATALOGS[next] ? next : 'en';

            return locale;
        },
        t,
        dir: () => t('app.dir', 'ltr'),
        catalog: () => Object.assign({}, en, CATALOGS[locale] || {}),
        locales: () => LOCALES.slice(),
    };
}

module.exports = { createI18n, LOCALES };
