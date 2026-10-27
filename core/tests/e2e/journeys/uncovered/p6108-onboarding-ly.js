// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/** P6-108: owner onboarding journey, Libya (LYD, no VAT, T-Lync sandbox). Steps and assertions: lib/onboarding.js. */

const { run } = require('../../lib/onboarding');

const CASE = {
  cc: 'LY', name: 'Libya', currency: 'LYD', decimals: 3, lang: 'ar', rate: 0,
  phone: '+218912345678', guestPhone: '+218922345678', address: 'Omar Al-Mukhtar Street, Tripoli',
  iban: 'LY83002048000020100120361',
  gateway: { key: 'tdsp', title: 'T-Lync (sandbox)', sandbox: true, testKey: false, secretField: 'token', secret: 'e2e-p6108-ly-token-fake',
    fields: { store_id: '4242', token: 'e2e-p6108-ly-token-fake' } },
};

module.exports = (ctx) => run(ctx, CASE);
