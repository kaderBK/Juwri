// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { hookOperation, restaurantField } = require('../lib/triggerFactory');
const sample = require('../samples/customer.created.json');

const EVENT = 'customer.created';

module.exports = {
  key: 'customer_created',
  noun: 'Customer',
  display: {
    label: 'New Customer',
    description: 'Triggers when a new customer is created.',
  },
  operation: Object.assign(
    {
      inputFields: [restaurantField()],
      sample,
    },
    hookOperation({
      event: EVENT,
      listPath: (b) => `/stores/${b.inputData.restaurant_id}/customers`,
      // `sort=created` (F0-4) surfaces newest-registered first — the default
      // `recent` sort buries a brand-new customer whose last_order_at is null.
      listParams: { sort: 'created' },
    })
  ),
};
