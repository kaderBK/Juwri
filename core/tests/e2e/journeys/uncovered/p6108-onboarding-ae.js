// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/** P6-108: owner onboarding journey, United Arab Emirates (AED, VAT 5%, Telr sandbox). Steps and assertions: lib/onboarding.js. */

const { run } = require('../../lib/onboarding');

const CASE = {
  cc: 'AE', name: 'United Arab Emirates', currency: 'AED', decimals: 2, lang: 'ar', rate: 5,
  phone: '+971501234567', guestPhone: '+971521234567', address: 'Sheikh Zayed Road, Dubai',
  iban: 'AE070331234567890123456',
  gateway: { key: 'telr', title: 'Telr (sandbox)', sandbox: true, testKey: false, secretField: 'authkey', secret: 'e2e-p6108-ae-authkey-fake',
    fields: { store_id: '99999', authkey: 'e2e-p6108-ae-authkey-fake' } },
};

module.exports = (ctx) => run(ctx, CASE);
