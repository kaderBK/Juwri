// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { hookOperation, restaurantField } = require('../lib/triggerFactory');
const sample = require('../samples/order.paid.json');

const EVENT = 'order.paid';

module.exports = {
  key: 'order_paid',
  noun: 'Order',
  display: {
    label: 'Order Paid',
    description: 'Triggers when an order is marked paid.',
  },
  operation: Object.assign(
    {
      inputFields: [restaurantField()],
      sample,
    },
    hookOperation({
      event: EVENT,
      listPath: (b) => `/stores/${b.inputData.restaurant_id}/orders`,
      // The orders index carries `is_paid`; the polling fallback only surfaces paid rows.
      rowFilter: (row) => !!row.is_paid,
    })
  ),
};
