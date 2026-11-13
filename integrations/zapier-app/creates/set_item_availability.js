// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { apiRequest } = require('../lib/client');
const { restaurantField } = require('../lib/triggerFactory');

/**
 * PATCH /stores/{id}/menu/items/{itemId}/sold-out — the quick "86" toggle.
 * Returns { id, is_sold_out }. Sending an explicit `is_sold_out` forces the
 * state; omitting it flips the current one (controller: $force null → toggle).
 */
const perform = async (z, bundle) => {
  const { restaurant_id, item_id, is_sold_out } = bundle.inputData;
  const body = {};
  if (is_sold_out !== undefined && is_sold_out !== null && is_sold_out !== '') {
    body.is_sold_out = is_sold_out;
  }
  const response = await apiRequest(z, bundle, {
    method: 'PATCH',
    path: `/stores/${restaurant_id}/menu/items/${item_id}/sold-out`,
    body,
  });
  return response.data;
};

module.exports = {
  key: 'set_item_availability',
  noun: 'Menu Item',
  display: {
    label: 'Set Item Availability',
    description: 'Marks a menu item sold out or available.',
  },
  operation: {
    perform,
    inputFields: [
      restaurantField(),
      { key: 'item_id', label: 'Menu Item ID', type: 'integer', required: true },
      {
        key: 'is_sold_out',
        label: 'Sold out',
        type: 'boolean',
        helpText: 'Leave blank to flip the current state.',
      },
    ],
    sample: { id: 991, is_sold_out: true },
  },
};
