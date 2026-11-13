// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import type {
	IAuthenticateGeneric,
	ICredentialTestRequest,
	ICredentialType,
	INodeProperties,
} from 'n8n-workflow';

/**
 * juwri API credential.
 *
 * A single API token (minted in the merchant dashboard under Settings → API)
 * authenticates every request as `Authorization: Bearer <token>`. The token
 * carries the abilities the merchant granted it (for example `orders:read`,
 * `menu:write`, `webhooks:write`); the node surfaces a clear error when a
 * request needs an ability the token lacks.
 *
 * `baseUrl` is the origin of the merchant's platform — the same host they use
 * to reach the dashboard. White-label / reseller deployments run on their own
 * host, so this is a field rather than a hard-coded constant.
 *
 * The connection test calls `GET /api/v1/me`, which any valid token can reach
 * (even a webhooks-only token), and which returns the store name so n8n can
 * label the credential.
 */
export class JuwriApi implements ICredentialType {
	name = 'juwriApi';

	displayName = 'juwri API';

	documentationUrl = 'https://app.juwri.example/developers';

	properties: INodeProperties[] = [
		{
			displayName: 'Base URL',
			name: 'baseUrl',
			type: 'string',
			default: 'https://app.juwri.example',
			placeholder: 'https://your-restaurant-platform.com',
			required: true,
			description:
				'Origin of your juwri platform, without a trailing slash — the same host as your dashboard. On a white-label/reseller deployment, use your branded host.',
		},
		{
			displayName: 'API Token',
			name: 'apiKey',
			type: 'string',
			typeOptions: { password: true },
			default: '',
			required: true,
			description:
				'An API token from your dashboard (Settings → API). Grant it the abilities the workflow needs, e.g. orders:read, menu:write, webhooks:write.',
		},
	];

	/**
	 * Injects the bearer token on every request. Declared once here so both the
	 * declarative action node and the programmatic trigger node authenticate the
	 * same way, and so the token can also be reused from n8n's generic
	 * HTTP Request node for any endpoint this package does not wrap yet.
	 */
	authenticate: IAuthenticateGeneric = {
		type: 'generic',
		properties: {
			headers: {
				Authorization: '=Bearer {{$credentials.apiKey}}',
			},
		},
	};

	/**
	 * Connection test: the cheapest owner-token endpoint. Reachable by ANY valid
	 * token — a webhooks:write-only token minted for the trigger node must still
	 * be able to validate itself here.
	 */
	test: ICredentialTestRequest = {
		request: {
			baseURL: '={{$credentials.baseUrl}}',
			url: '/api/v1/me',
			method: 'GET',
		},
	};
}
