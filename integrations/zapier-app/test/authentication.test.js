// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const auth = require('../authentication');
const { makeZ, makeBundle } = require('./_harness');

const IDENTITY = {
  store_name: 'Demo Diner',
  owner_name: 'Alex Rivera',
  token_abilities: ['webhooks:write'],
  plan: 'Pro',
};

test('auth.test hits GET /api/v1/me with a bearer token and returns the identity', async () => {
  const { z, calls } = makeZ({ status: 200, data: IDENTITY });
  const result = await auth.test(z, makeBundle());

  assert.equal(calls.length, 1);
  assert.equal(calls[0].method, 'GET');
  assert.equal(calls[0].url, 'https://demo.test/api/v1/me');
  assert.equal(calls[0].headers.Authorization, 'Bearer tok_test');
  assert.equal(calls[0].headers.Accept, 'application/json');
  assert.deepEqual(result, IDENTITY);
});

test('connectionLabel is the store name', () => {
  assert.equal(auth.connectionLabel, '{{store_name}}');
  assert.equal(auth.type, 'custom');
  const keys = auth.fields.map((f) => f.key);
  assert.deepEqual(keys, ['api_base_url', 'api_key']);
  assert.ok(auth.fields.every((f) => f.required));
});

test('a trailing slash on the base URL does not double up the path', async () => {
  const { z, calls } = makeZ({ status: 200, data: IDENTITY });
  await auth.test(
    z,
    makeBundle({ authData: { api_base_url: 'https://demo.test/api/v1/', api_key: 'k' } })
  );
  assert.equal(calls[0].url, 'https://demo.test/api/v1/me');
});

test('a 401 surfaces the platform error message verbatim', async () => {
  const { z } = makeZ({
    status: 401,
    data: { message: 'Unauthenticated.', code: 'unauthenticated' },
  });
  await assert.rejects(() => auth.test(z, makeBundle()), (err) => {
    assert.equal(err.message, 'Unauthenticated.');
    assert.equal(err.code, 'unauthenticated');
    assert.equal(err.status, 401);
    return true;
  });
});
