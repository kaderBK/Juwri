// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import { describe, it, expect, vi } from 'vitest';
import { JuwriTrigger } from '../nodes/JuwriTrigger/JuwriTrigger.node';

/* eslint-disable @typescript-eslint/no-explicit-any */

const WEBHOOK_URL = 'https://n8n.example.com/webhook/juwri-abc';
const BASE_URL = 'https://app.example.test';

/**
 * Build a fake IHookFunctions context. `http` is the mock that stands in for
 * `helpers.httpRequestWithAuthentication`; every call records
 * (credentialType, options) so a test can assert the exact request body/url.
 */
function makeHookCtx(options: {
	staticData?: Record<string, any>;
	events?: string[];
	http: (credType: string, opts: any) => any;
	baseUrl?: string;
}) {
	const staticData = options.staticData ?? {};
	const http = vi.fn(options.http);
	const ctx: any = {
		getWorkflowStaticData: (_scope: string) => staticData,
		getNodeWebhookUrl: (_name: string) => WEBHOOK_URL,
		getNodeParameter: (_name: string) => options.events ?? [],
		getCredentials: async (_type: string) => ({
			baseUrl: options.baseUrl ?? BASE_URL,
			apiKey: 'tok_test',
		}),
		getNode: () => ({ name: 'juwri Trigger', type: 'juwriTrigger' }),
		helpers: { httpRequestWithAuthentication: http },
	};
	return { ctx, http, staticData };
}

const node = new JuwriTrigger();
const methods = node.webhookMethods.default;

describe('JuwriTrigger — description & webhook contract', () => {
	it('is a trigger with a POST webhook and no inputs', () => {
		expect(node.description.name).toBe('juwriTrigger');
		expect(node.description.group).toContain('trigger');
		expect(node.description.inputs).toEqual([]);
		expect(node.description.webhooks?.[0]).toMatchObject({
			httpMethod: 'POST',
			responseMode: 'onReceived',
			name: 'default',
		});
	});

	it('requires the credential and all three lifecycle methods exist (lint rule)', () => {
		expect(node.description.credentials).toEqual([{ name: 'juwriApi', required: true }]);
		expect(typeof methods.checkExists).toBe('function');
		expect(typeof methods.create).toBe('function');
		expect(typeof methods.delete).toBe('function');
	});

	it('offers the platform event catalog as a required multi-select', () => {
		const events = node.description.properties.find((p) => p.name === 'events')!;
		expect(events.type).toBe('multiOptions');
		expect(events.required).toBe(true);
		const values = (events.options as Array<{ value: string }>).map((o) => o.value);
		// a representative slice of SELECTABLE_EVENTS + the wildcard
		expect(values).toContain('*');
		expect(values).toContain('order.created');
		expect(values).toContain('refund.completed');
		expect(values).toContain('app.uninstalled');
	});
});

describe('JuwriTrigger — create (subscribe)', () => {
	it('POSTs {url, events} to /webhook-endpoints and stores the returned id + secret', async () => {
		const events = ['order.created', 'order.paid'];
		const { ctx, http, staticData } = makeHookCtx({
			events,
			http: (_c, opts) => {
				expect(opts.method).toBe('POST');
				return { id: 42, url: WEBHOOK_URL, events, active: true, secret: 'whsec_live' };
			},
		});

		const result = await methods.create.call(ctx);
		expect(result).toBe(true);

		expect(http).toHaveBeenCalledTimes(1);
		const [credType, opts] = http.mock.calls[0];
		expect(credType).toBe('juwriApi');
		expect(opts.method).toBe('POST');
		expect(opts.url).toBe(`${BASE_URL}/api/v1/webhook-endpoints`);
		// The field IS `url` (not endpoint/target), and events pass through verbatim.
		expect(opts.body).toEqual({ url: WEBHOOK_URL, events });

		expect(staticData.endpointId).toBe(42);
		expect(staticData.secret).toBe('whsec_live');
	});

	it('strips a trailing slash on the base URL', async () => {
		const { ctx, http } = makeHookCtx({
			events: ['menu.updated'],
			baseUrl: 'https://app.example.test/',
			http: () => ({ id: 1, url: WEBHOOK_URL }),
		});
		await methods.create.call(ctx);
		expect(http.mock.calls[0][1].url).toBe('https://app.example.test/api/v1/webhook-endpoints');
	});

	it('throws when the platform returns no id', async () => {
		const { ctx } = makeHookCtx({
			events: ['order.created'],
			http: () => ({ error: 'nope' }),
		});
		await expect(methods.create.call(ctx)).rejects.toThrow();
	});
});

describe('JuwriTrigger — checkExists', () => {
	it('returns true when the stored id is still listed', async () => {
		const { ctx, staticData } = makeHookCtx({
			staticData: { endpointId: 42, secret: 's' },
			http: () => ({ data: [{ id: 42, url: WEBHOOK_URL }, { id: 7, url: 'https://other' }] }),
		});
		expect(await methods.checkExists.call(ctx)).toBe(true);
		expect(staticData.endpointId).toBe(42); // untouched
	});

	it('returns false and forgets the id when the stored id is gone', async () => {
		const { ctx, staticData } = makeHookCtx({
			staticData: { endpointId: 99, secret: 's' },
			http: () => ({ data: [{ id: 1, url: 'https://other' }] }),
		});
		expect(await methods.checkExists.call(ctx)).toBe(false);
		expect(staticData.endpointId).toBeUndefined();
		expect(staticData.secret).toBeUndefined();
	});

	it('adopts an endpoint that matches this node URL when there is no stored id', async () => {
		const { ctx, staticData } = makeHookCtx({
			staticData: {},
			http: () => ({ data: [{ id: 7, url: WEBHOOK_URL }] }),
		});
		expect(await methods.checkExists.call(ctx)).toBe(true);
		expect(staticData.endpointId).toBe(7);
	});

	it('returns false when nothing matches', async () => {
		const { ctx, staticData } = makeHookCtx({
			staticData: {},
			http: () => ({ data: [] }),
		});
		expect(await methods.checkExists.call(ctx)).toBe(false);
		expect(staticData.endpointId).toBeUndefined();
	});
});

describe('JuwriTrigger — delete (unsubscribe)', () => {
	it('DELETEs ONLY the stored id and clears static data', async () => {
		const { ctx, http, staticData } = makeHookCtx({
			staticData: { endpointId: 42, secret: 's' },
			http: (_c, opts) => {
				expect(opts.method).toBe('DELETE');
				return null;
			},
		});
		expect(await methods.delete.call(ctx)).toBe(true);

		expect(http).toHaveBeenCalledTimes(1);
		expect(http.mock.calls[0][1].method).toBe('DELETE');
		expect(http.mock.calls[0][1].url).toBe(`${BASE_URL}/api/v1/webhook-endpoints/42`);
		expect(staticData.endpointId).toBeUndefined();
		expect(staticData.secret).toBeUndefined();
	});

	it('makes no HTTP call when there is nothing we created', async () => {
		const { ctx, http } = makeHookCtx({
			staticData: {},
			http: () => ({}),
		});
		expect(await methods.delete.call(ctx)).toBe(true);
		expect(http).not.toHaveBeenCalled();
	});

	it('swallows a 404 (already gone) and still clears static data', async () => {
		const { ctx, staticData } = makeHookCtx({
			staticData: { endpointId: 42 },
			http: () => {
				throw { httpCode: '404' };
			},
		});
		expect(await methods.delete.call(ctx)).toBe(true);
		expect(staticData.endpointId).toBeUndefined();
	});

	it('rethrows a non-404 error (leaves state so n8n can retry)', async () => {
		const { ctx } = makeHookCtx({
			staticData: { endpointId: 42 },
			http: () => {
				throw { httpCode: '500' };
			},
		});
		await expect(methods.delete.call(ctx)).rejects.toBeTruthy();
	});
});

describe('JuwriTrigger — webhook handler', () => {
	it('emits the delivered envelope {id,event,created_at,data} as item #1', async () => {
		const envelope = {
			id: 'msg_h2K9xW3qLp8RtY5vN0aBcDe7',
			event: 'order.created',
			created_at: '2026-07-08T14:03:11+00:00',
			data: {
				order_id: 4812,
				restaurant_id: 37,
				type: 'delivery',
				status: 'new',
				total: 96.5,
				currency: 'USD',
				track_token: 'tk_9f3a2c7e',
				source: 'api',
			},
		};
		const ctx: any = {
			getBodyData: () => envelope,
			helpers: { returnJsonArray: (arr: any[]) => arr.map((json) => ({ json })) },
		};

		const result = await node.webhook.call(ctx);
		expect(result.workflowData?.[0]?.[0]?.json).toEqual(envelope);
		// the emitted item preserves the event name + the data payload for routing
		expect((result.workflowData as any)[0][0].json.event).toBe('order.created');
		expect((result.workflowData as any)[0][0].json.data.order_id).toBe(4812);
	});
});
