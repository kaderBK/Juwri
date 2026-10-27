// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/** P6-108: owner onboarding journey, Jordan (JOD, sales tax 16%, Stripe test key). Steps and assertions: lib/onboarding.js. */

const { run } = require('../../lib/onboarding');

const CASE = {
  cc: 'JO', name: 'Jordan', currency: 'JOD', decimals: 3, lang: 'ar', rate: 16,
  phone: '+962791234567', guestPhone: '+962781234567', address: 'Rainbow Street, Amman',
  iban: 'JO94CBJO0010000000000131000302',
  gateway: { key: 'stripe', title: 'Stripe (test)', sandbox: false, testKey: true, secretField: 'secret_key', secret: 'sk_test_e2e_p6108_jo_fake',
    fields: { secret_key: 'sk_test_e2e_p6108_jo_fake', publishable_key: 'pk_test_e2e_p6108_jo_fake', webhook_secret: 'whsec-e2e-p6108-jo-fake' } },
};

module.exports = (ctx) => run(ctx, CASE);
