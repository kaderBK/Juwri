// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import { describe, it, expect } from 'vitest';
import { JuwriApi } from '../credentials/JuwriApi.credentials';

describe('JuwriApi credential', () => {
	const cred = new JuwriApi();

	it('has the stable machine name used by both nodes', () => {
		expect(cred.name).toBe('juwriApi');
		expect(cred.displayName).toBe('juwri API');
	});

	it('exposes a base URL field and a masked API token field', () => {
		const byName = Object.fromEntries(cred.properties.map((p) => [p.name, p]));
		expect(byName.baseUrl).toBeDefined();
		expect(byName.baseUrl.type).toBe('string');
		expect(byName.baseUrl.required).toBe(true);

		expect(byName.apiKey).toBeDefined();
		expect(byName.apiKey.required).toBe(true);
		// The token must be masked in the UI.
		expect(byName.apiKey.typeOptions?.password).toBe(true);
	});

	it('injects the bearer token on every request', () => {
		expect(cred.authenticate.type).toBe('generic');
		const headers = (cred.authenticate.properties as { headers: Record<string, string> }).headers;
		expect(headers.Authorization).toBe('=Bearer {{$credentials.apiKey}}');
	});

	it('tests the connection against GET /api/v1/me (reachable by any valid token)', () => {
		expect(cred.test.request.method).toBe('GET');
		expect(cred.test.request.url).toBe('/api/v1/me');
		expect(cred.test.request.baseURL).toBe('={{$credentials.baseUrl}}');
	});
});
