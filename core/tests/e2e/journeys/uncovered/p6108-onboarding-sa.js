// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/** P6-108: owner onboarding journey, Saudi Arabia (SAR, VAT 15%, Stripe test key). Steps and assertions: lib/onboarding.js. */

const { run } = require('../../lib/onboarding');

const CASE = {
  cc: 'SA', name: 'Saudi Arabia', currency: 'SAR', decimals: 2, lang: 'ar', rate: 15,
  phone: '+966501234567', guestPhone: '+966512345678', address: 'King Fahd Road, Riyadh',
  iban: 'SA0380000000608010167519',
  gateway: { key: 'stripe', title: 'Stripe (test)', sandbox: false, testKey: true, secretField: 'secret_key', secret: 'sk_test_e2e_p6108_sa_fake',
    fields: { secret_key: 'sk_test_e2e_p6108_sa_fake', publishable_key: 'pk_test_e2e_p6108_sa_fake', webhook_secret: 'whsec-e2e-p6108-sa-fake' } },
};

module.exports = (ctx) => run(ctx, CASE);
