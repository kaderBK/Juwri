// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { hookOperation, restaurantField } = require('../lib/triggerFactory');
const sample = require('../samples/order.created.json');

const EVENT = 'order.created';

module.exports = {
  key: 'order_created',
  noun: 'Order',
  display: {
    label: 'New Order',
    description: 'Triggers when a new order is placed.',
  },
  operation: Object.assign(
    {
      inputFields: [restaurantField()],
      sample,
    },
    hookOperation({
      event: EVENT,
      listPath: (b) => `/stores/${b.inputData.restaurant_id}/orders`,
    })
  ),
};
