// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { apiRequest } = require('../lib/client');
const { restaurantField } = require('../lib/triggerFactory');

/**
 * POST /stores/{id}/menu/items — creates a menu item (201) and returns the
 * full item object (MenuAdminService::itemArray). Fields mirror ITEM_RULES.
 */
const perform = async (z, bundle) => {
  const { restaurant_id } = bundle.inputData;
  const body = compactBody(bundle.inputData);
  const response = await apiRequest(z, bundle, {
    method: 'POST',
    path: `/stores/${restaurant_id}/menu/items`,
    body,
  });
  return response.data;
};

// Drop restaurant_id (path param) and any blank optional fields from the body.
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
  key: 'create_menu_item',
  noun: 'Menu Item',
  display: {
    label: 'Create Menu Item',
    description: 'Creates a new menu item on a restaurant.',
  },
  operation: {
    perform,
    inputFields: [
      restaurantField(),
      { key: 'name', label: 'Name', type: 'string', required: true },
      { key: 'price', label: 'Price', type: 'number', required: true },
      { key: 'category_id', label: 'Category ID', type: 'integer' },
      { key: 'description', label: 'Description', type: 'text' },
      { key: 'type', label: 'Type', type: 'string', choices: ['veg', 'nonveg'] },
      { key: 'active', label: 'Active', type: 'boolean' },
      { key: 'is_sold_out', label: 'Sold out', type: 'boolean' },
      { key: 'calories', label: 'Calories', type: 'integer' },
    ],
    sample: {
      id: 991,
      category_id: 12,
      name: 'Margherita Pizza',
      price: 12.5,
      active: true,
      is_sold_out: false,
    },
  },
};
