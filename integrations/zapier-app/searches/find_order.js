// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { apiRequest } = require('../lib/client');
const { restaurantField } = require('../lib/triggerFactory');

/**
 * Find Order by its number (the platform identifies orders by their numeric id;
 * there is no separate order_number column). Uses GET
 * /stores/{id}/orders/{orderId} and SWALLOWS a 404 → returns `[]`, never a
 * 404, so the search can front a "search-or-create" step. On a hit returns
 * `[order]` (array, best match first).
 */
const perform = async (z, bundle) => {
  const { restaurant_id, order_id } = bundle.inputData;
  const response = await apiRequest(z, bundle, {
    path: `/stores/${restaurant_id}/orders/${order_id}`,
    okStatuses: [404],
  });
  if (response.status === 404 || !response.data) {
    return [];
  }
  return [response.data];
};

module.exports = {
  key: 'find_order',
  noun: 'Order',
  display: {
    label: 'Find Order',
    description: 'Finds an order by its number.',
  },
  operation: {
    perform,
    inputFields: [
      restaurantField(),
      { key: 'order_id', label: 'Order number', type: 'integer', required: true },
    ],
    sample: {
      id: 4812,
      status: 'preparing',
      type: 'delivery',
      total: 96.5,
      currency: 'USD',
      is_paid: true,
      created_at: '2026-07-08T14:03:11+00:00',
    },
  },
};
