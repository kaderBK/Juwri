// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { apiRequest } = require('../lib/client');
const { restaurantField } = require('../lib/triggerFactory');

/**
 * Find Customer by phone or email. Uses the customers index `?search=` (matches
 * name/phone/email server-side, F0-4) and returns the row array (best match
 * first). No match → `200 []`, never a 404 — so it pairs with Create Customer
 * for search-or-create. Note: the customers list is gated `feature:crm_suite`;
 * an unentitled store gets a JSON 403 the error mapping surfaces.
 */
const perform = async (z, bundle) => {
  const { restaurant_id, search } = bundle.inputData;
  const response = await apiRequest(z, bundle, {
    path: `/stores/${restaurant_id}/customers`,
    params: { search, per_page: 100 },
  });
  return (response.data && response.data.data) || [];
};

module.exports = {
  key: 'find_customer',
  noun: 'Customer',
  display: {
    label: 'Find Customer',
    description: 'Finds a customer by phone or email.',
  },
  operation: {
    perform,
    inputFields: [
      restaurantField(),
      { key: 'search', label: 'Phone or email', type: 'string', required: true },
    ],
    sample: {
      id: 8841,
      name: 'Jordan Rivera',
      phone: '+15551234567',
      email: 'jr@example.com',
      orders_count: 3,
      total_spent: 128.4,
      last_order_at: '2026-07-08T14:03:11+00:00',
      tags: ['vip'],
      created_at: '2026-07-01T09:00:00+00:00',
    },
  },
};
