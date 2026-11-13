// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { apiRequest } = require('../lib/client');

/**
 * Hidden helper trigger — powers the "Restaurant" dynamic dropdown on every
 * other trigger/action (`dynamic: 'store.id.title'`). Never shown as a
 * standalone trigger in the Zap editor.
 */
const perform = async (z, bundle) => {
  const response = await apiRequest(z, bundle, {
    path: '/stores',
    params: { per_page: 100 },
  });
  return (response.data && response.data.data) || [];
};

module.exports = {
  key: 'restaurant',
  noun: 'Restaurant',
  display: {
    label: 'Restaurant',
    description: 'Lists restaurants (used to populate dropdowns).',
    hidden: true,
  },
  operation: {
    perform,
    sample: {
      id: 37,
      title: 'Demo Diner',
      slug: 'demo-diner',
      url: 'https://demo.test/demo-diner',
      template: 'crave',
      created_at: '2026-07-08T14:03:11+00:00',
    },
    outputFields: [
      { key: 'id', label: 'Restaurant ID', type: 'integer' },
      { key: 'title', label: 'Name' },
    ],
  },
};
