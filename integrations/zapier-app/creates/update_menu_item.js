// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { apiRequest } = require('../lib/client');
const { restaurantField } = require('../lib/triggerFactory');

/**
 * PUT /stores/{id}/menu/items/{itemId} — updates a menu item and returns
 * the full item object. name/price are optional on update (itemRules `sometimes`).
 */
const perform = async (z, bundle) => {
  const { restaurant_id, item_id } = bundle.inputData;
  const body = compactBody(bundle.inputData);
  const response = await apiRequest(z, bundle, {
    method: 'PUT',
    path: `/stores/${restaurant_id}/menu/items/${item_id}`,
    body,
  });
  return response.data;
};

function compactBody(input) {
  const out = {};
  for (const key of ['category_id', 'name', 'description', 'price', 'image', 'type', 'active', 'is_sold_out', 'is_popular', 'is_vegan', 'is_halal', 'is_gluten_free', 'calories', 'prep_minutes', 'position']) {
    if (input[key] !== undefined && input[key] !== null && input[key] !== '') {
      out[key] = input[key];
    }
  }
  return out;
}

module.exports = {
  key: 'update_menu_item',
  noun: 'Menu Item',
  display: {
    label: 'Update Menu Item',
    description: 'Updates an existing menu item.',
  },
  operation: {
    perform,
    inputFields: [
      restaurantField(),
      { key: 'item_id', label: 'Menu Item ID', type: 'integer', required: true },
      { key: 'name', label: 'Name', type: 'string' },
      { key: 'price', label: 'Price', type: 'number' },
      { key: 'category_id', label: 'Category ID', type: 'integer' },
      { key: 'description', label: 'Description', type: 'text' },
      { key: 'active', label: 'Active', type: 'boolean' },
      { key: 'is_sold_out', label: 'Sold out', type: 'boolean' },
      { key: 'calories', label: 'Calories', type: 'integer' },
    ],
    sample: {
      id: 991,
      category_id: 12,
      name: 'Margherita Pizza',
      price: 13.0,
      active: true,
      is_sold_out: false,
    },
  },
};
