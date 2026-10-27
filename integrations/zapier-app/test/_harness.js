// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A zero-dependency stand-in for the Zapier `z` object and `bundle`, so every
 * perform/performList/performSubscribe/performUnsubscribe can be driven with
 * mocked HTTP and asserted directly (request bodies, transformed shapes).
 *
 * `zapier test` runs the same operations through zapier-platform-core against a
 * mock; these node:test specs assert the SAME contract with no network and no
 * install — which is this package's verification bar.
 */

class ZapierError extends Error {
  constructor(message, code, status) {
    super(message);
    this.name = 'ZapierError';
    this.code = code;
    this.status = status;
  }
}

/**
 * @param {object|function} handler  A canned response `{status, data}`, or a
 *   function `(options, callIndex) => {status, data}` for multi-call scenarios.
 * @returns {{z: object, calls: object[]}}  `calls` records every z.request option object.
 */
function makeZ(handler) {
  const calls = [];
  const z = {
    request: async (options) => {
      calls.push(options);
      const res =
        typeof handler === 'function' ? handler(options, calls.length - 1) : handler;
      return Object.assign({ status: 200, data: undefined, headers: {} }, res || {});
    },
    errors: { Error: ZapierError },
    JSON,
    console,
  };
  return { z, calls };
}

function makeBundle(overrides) {
  return Object.assign(
    {
      authData: { api_base_url: 'https://demo.test/api/v1', api_key: 'tok_test' },
      inputData: {},
      cleanedRequest: undefined,
      targetUrl: undefined,
      subscribeData: undefined,
    },
    overrides || {}
  );
}

/** Wrap list rows in the Laravel paginator envelope the index endpoints return. */
function paginator(rows) {
  return {
    current_page: 1,
    data: rows,
    per_page: 100,
    total: rows.length,
  };
}

module.exports = { ZapierError, makeZ, makeBundle, paginator };
