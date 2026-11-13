// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/** P6-108: owner onboarding journey, Egypt (EGP, VAT 14%, Fawry sandbox). Steps and assertions: lib/onboarding.js. */

const { run } = require('../../lib/onboarding');

const CASE = {
  cc: 'EG', name: 'Egypt', currency: 'EGP', decimals: 2, lang: 'ar', rate: 14,
  phone: '+201012345678', guestPhone: '+201112345678', address: 'Tahrir Square, Cairo',
  iban: 'EG380019000500000000263180002',
  gateway: { key: 'fawry', title: 'Fawry (sandbox)', sandbox: true, testKey: false, secretField: 'security_key', secret: 'e2e-p6108-eg-security-fake',
    fields: { merchant_code: 'e2e-p6108-eg-merchant', security_key: 'e2e-p6108-eg-security-fake' } },
};

module.exports = (ctx) => run(ctx, CASE);
