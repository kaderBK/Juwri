// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P6-108b: owner onboarding journey, Egypt with Paymob (EGP, VAT 14%, Paymob test mode). The Paymob region is left on
 * "Automatic": the store country (EG) must pick the Egypt host accept.paymob.com. Steps and assertions: lib/onboarding.js.
 */

const { run } = require('../../lib/onboarding');

const CASE = {
  cc: 'EG', tag: 'eg-paymob', name: 'Egypt', currency: 'EGP', decimals: 2, lang: 'ar', rate: 14,
  phone: '+201012345679', guestPhone: '+201112345679', address: 'Corniche El Nil, Cairo',
  iban: 'EG380019000500000000263180002',
  gateway: { key: 'paymob', title: 'Paymob (test)', sandbox: true, testKey: true, secretField: 'secret_key', secret: 'sk_test_e2e_p6108_eg_paymob_fake',
    fields: { region: '', secret_key: 'sk_test_e2e_p6108_eg_paymob_fake', public_key: 'pk_test_e2e_p6108_eg_paymob', integration_id: '123456', hmac_secret: 'e2e-p6108-eg-hmac-fake' },
    region: { key: 'eg', host: 'https://accept.paymob.com' } },
};

module.exports = (ctx) => run(ctx, CASE);
