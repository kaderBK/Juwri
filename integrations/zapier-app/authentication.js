// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { apiRequest } = require('./lib/client');

/**
 * Custom (API-key) auth. The owner mints a token on the platform's
 * Developers → API tokens page (a `webhooks:write` token is enough to subscribe;
 * add `orders:write` / `menu:write` / `customers:read` for the actions/searches).
 *
 * Connection test + label both hit `GET /api/v1/me` (F0-3) — reachable by ANY
 * valid token, returns { store_name, owner_name, token_abilities, plan }. The
 * connection is labelled with the store name so a Zap author with several stores
 * can tell their connections apart.
 */

const test = async (z, bundle) => {
  const response = await apiRequest(z, bundle, { method: 'GET', path: '/me' });
  return response.data;
};

module.exports = {
  type: 'custom',
  test,
  // Shown next to the connection in the Zap editor.
  connectionLabel: '{{store_name}}',
  fields: [
    {
      key: 'api_base_url',
      label: 'API base URL',
      type: 'string',
      required: true,
      default: 'https://app.juwri.example/api/v1',
      helpText:
        'Your juwri API base, ending in `/api/v1`. Find it on **Developers → API** in your dashboard (the "Base URL" value). White-label resellers use their own domain.',
    },
    {
      key: 'api_key',
      label: 'API token',
      type: 'string',
      required: true,
      helpText:
        'Create one under **Developers → API tokens**. Grant `webhooks:write` for triggers; add `orders:write`, `menu:write` and `customers:read` to use the actions and searches.',
    },
  ],
};
