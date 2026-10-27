// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { hookOperation, restaurantField } = require('../lib/triggerFactory');
const sample = require('../samples/order.status_changed.json');

const EVENT = 'order.status_changed';

module.exports = {
  key: 'order_status_changed',
  noun: 'Order',
  display: {
    label: 'Order Status Changed',
    description: 'Triggers when an order changes status.',
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
