// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { apiRequest } = require('../lib/client');
const { restaurantField } = require('../lib/triggerFactory');

/**
 * PUT /stores/{id}/orders/{orderId}/status — sets the order status and, per
 * F0-7, returns the FULL order row (not a {id,status} stub) so later Zap steps
 * have the record. Also fires the `order.status_changed` webhook platform-side.
 */
const perform = async (z, bundle) => {
  const { restaurant_id, order_id, status } = bundle.inputData;
  const response = await apiRequest(z, bundle, {
    method: 'PUT',
    path: `/stores/${restaurant_id}/orders/${order_id}/status`,
    body: { status },
  });
  return response.data;
};

module.exports = {
  key: 'update_order_status',
  noun: 'Order',
  display: {
    label: 'Update Order Status',
    description: 'Updates the status of an existing order.',
  },
  operation: {
    perform,
    inputFields: [
      restaurantField(),
      { key: 'order_id', label: 'Order ID', type: 'integer', required: true },
      {
        key: 'status',
        label: 'Status',
        type: 'string',
        required: true,
        choices: ['new', 'preparing', 'ready', 'delivered', 'completed', 'cancelled'],
      },
    ],
    sample: {
      id: 4812,
      status: 'preparing',
      type: 'delivery',
      table_number: null,
      customer_name: 'Jane Doe',
      total: 96.5,
      currency: 'USD',
      is_paid: true,
      created_at: '2026-07-08T14:03:11+00:00',
    },
  },
};
