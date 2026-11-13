'use strict';

/**
 * One place that talks to the juwri owner API (`<base>/api/v1`).
 *
 * The juwri API base is deployment-specific (`url('/api/v1')` on the platform
 * host — see core/app/Http/Controllers/DeveloperDocsController.php), so the base
 * is a connection field (`api_base_url`) the owner fills in, not a constant.
 *
 * Every request carries `Authorization: Bearer <api_key>`. The platform speaks
 * ONE error envelope for the whole v1 surface (App\Support\ApiError):
 *   { "message": <human>, "code": <machine>, "errors"?: {field:[…]} }
 * so `throwForStatus` surfaces `message` verbatim to the Zap user.
 *
 * These helpers take the Zapier `z` object and `bundle` and are intentionally
 * free of `beforeRequest`/`afterResponse` middleware: keeping the base-URL join,
 * auth header and error mapping inside one function makes every request fully
 * assertable from unit tests (the verification bar for this package).
 */

// Placeholder — the owner overrides this per deployment in the connection form.
const DEFAULT_BASE_URL = 'https://app.juwri.example/api/v1';

function baseUrl(bundle) {
  const raw =
    (bundle && bundle.authData && bundle.authData.api_base_url) ||
    DEFAULT_BASE_URL;
  return String(raw).replace(/\/+$/, '');
}

function authHeader(bundle) {
  const key = (bundle && bundle.authData && bundle.authData.api_key) || '';
  return `Bearer ${key}`;
}

function throwForStatus(z, response) {
  if (response.status < 400) {
    return;
  }
  const data = response.data || {};
  const message =
    (data && data.message) || `juwri API request failed (HTTP ${response.status}).`;
  const code = (data && data.code) || 'api_error';
  throw new z.errors.Error(message, code, response.status);
}

/**
 * Perform an API request and (unless the status is explicitly whitelisted) map
 * any 4xx/5xx to a Zapier error carrying the platform's human message.
 *
 * @param {object} z         Zapier z object (z.request, z.errors)
 * @param {object} bundle    Zapier bundle (authData, inputData, …)
 * @param {object} opts
 * @param {string} [opts.method='GET']
 * @param {string} opts.path            Path under the api/v1 base, e.g. '/me'
 * @param {object} [opts.body]
 * @param {object} [opts.params]        Query params
 * @param {number[]} [opts.okStatuses]  Extra non-2xx statuses to treat as success
 *                                       (e.g. [404] so a missing row is a caller decision)
 * @returns {Promise<object>} the Zapier response ({ status, data, … })
 */
async function apiRequest(z, bundle, opts) {
  const {
    method = 'GET',
    path,
    body,
    params,
    okStatuses = [],
  } = opts;

  const request = {
    url: baseUrl(bundle) + path,
    method,
    headers: {
      Authorization: authHeader(bundle),
      Accept: 'application/json',
    },
    // We do our OWN status handling so callers can whitelist (searches want 404 → []).
    skipThrowForStatus: true,
  };
  if (body !== undefined) {
    request.body = body;
  }
  if (params !== undefined) {
    request.params = params;
  }

  const response = await z.request(request);

  const accepted =
    response.status < 400 || okStatuses.indexOf(response.status) !== -1;
  if (!accepted) {
    throwForStatus(z, response);
  }
  return response;
}

module.exports = {
  DEFAULT_BASE_URL,
  baseUrl,
  authHeader,
  throwForStatus,
  apiRequest,
};
