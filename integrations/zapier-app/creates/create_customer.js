// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { apiRequest } = require('../lib/client');
const { restaurantField } = require('../lib/triggerFactory');

/**
 * Create Customer.
 *
 * NEEDS-F0(B4-a): the owner API has NO create-customer endpoint today — customers
 * are read-only over api/v1 (`GET stores/{id}/customers`, F0-4; the only
 * customer-create route is the Reseller portal's, a different guard). This action
 * is written against the intended shape
 *     POST /stores/{id}/customers  → 201, full Customer object
 * so it works the moment the platform adds it. Fields map to Customer::$fillable.
 * Until the endpoint exists this action will 4xx at runtime; it is unit-tested
 * against a mocked 201 and paired with Find Customer for search-or-create.
 */
const perform = async (z, bundle) => {
  const { restaurant_id } = bundle.inputData;
  const body = {};
  for (const key of ['name', 'email', 'phone', 'marketing_consent', 'tags', 'notes']) {
    if (bundle.inputData[key] !== undefined && bundle.inputData[key] !== null && bundle.inputData[key] !== '') {
      body[key] = bundle.inputData[key];
    }
  }
  const response = await apiRequest(z, bundle, {
    method: 'POST',
    path: `/stores/${restaurant_id}/customers`,
    body,
  });
  return response.data;
};

module.exports = {
  key: 'create_customer',
  noun: 'Customer',
  display: {
    label: 'Create Customer',
    description: 'Creates a customer record on a restaurant.',
  },
  operation: {
    perform,
    inputFields: [
      restaurantField(),
      { key: 'name', label: 'Name', type: 'string', required: true },
      { key: 'email', label: 'Email', type: 'string' },
      { key: 'phone', label: 'Phone', type: 'string' },
      { key: 'marketing_consent', label: 'Marketing consent', type: 'boolean' },
      { key: 'notes', label: 'Notes', type: 'text' },
    ],
    sample: {
      id: 8841,
      name: 'Jordan Rivera',
      email: 'jr@example.com',
      phone: '+15551234567',
      created_at: '2026-07-08T14:03:11+00:00',
    },
  },
};
