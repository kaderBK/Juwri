// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import { describe, it, expect } from 'vitest';
import type { INodeProperties, INodePropertyOptions } from 'n8n-workflow';
import { Juwri } from '../nodes/Juwri/Juwri.node';

const node = new Juwri();
const props = node.description.properties;

/** The operation dropdown for a given resource. */
function operationProp(resource: string): INodeProperties {
	const p = props.find(
		(x) =>
			x.name === 'operation' &&
			(x.displayOptions?.show?.resource as string[] | undefined)?.includes(resource),
	);
	if (!p) throw new Error(`no operation property for resource ${resource}`);
	return p;
}

/** A single operation option (carries the routing). */
function operation(resource: string, value: string): INodePropertyOptions {
	const opt = (operationProp(resource).options as INodePropertyOptions[]).find(
		(o) => o.value === value,
	);
	if (!opt) throw new Error(`no operation ${value} for resource ${resource}`);
	return opt;
}

/** A top-level property by name, optionally scoped to a resource+operation. */
function property(name: string, resource?: string, operationValue?: string): INodeProperties {
	const p = props.find((x) => {
		if (x.name !== name) return false;
		if (resource && !(x.displayOptions?.show?.resource as string[] | undefined)?.includes(resource))
			return false;
		if (
			operationValue &&
			!(x.displayOptions?.show?.operation as string[] | undefined)?.includes(operationValue)
		)
			return false;
		return true;
	});
	if (!p) throw new Error(`no property ${name} (${resource}/${operationValue})`);
	return p;
}

/* eslint-disable @typescript-eslint/no-explicit-any */
const routingOf = (opt: INodePropertyOptions) => (opt as any).routing;
const sendOf = (p: INodeProperties) => (p as any).routing?.send;

describe('juwri action node — description', () => {
	it('identifies itself and its transport', () => {
		expect(node.description.name).toBe('juwri');
		expect(node.description.displayName).toBe('juwri');
		expect(node.description.version).toBe(1);
		expect(node.description.group).toContain('transform');
		expect(node.description.requestDefaults?.baseURL).toBe('={{$credentials.baseUrl}}/api/v1');
	});

	it('requires the juwri API credential', () => {
		expect(node.description.credentials).toEqual([{ name: 'juwriApi', required: true }]);
	});

	it('offers Order, Menu Item and Customer resources', () => {
		const resource = props.find((p) => p.name === 'resource')!;
		const values = (resource.options as INodePropertyOptions[]).map((o) => o.value);
		expect(values).toEqual(['order', 'menuItem', 'customer']);
	});

	it('always asks for the Restaurant ID and marks it required', () => {
		const r = props.find((p) => p.name === 'storeId')!;
		expect(r.required).toBe(true);
		// no displayOptions → shown for every resource/operation
		expect(r.displayOptions).toBeUndefined();
	});
});

describe('juwri action node — Order operations', () => {
	it('List → GET /stores/{id}/orders, unwraps the data envelope', () => {
		const r = routingOf(operation('order', 'list'));
		expect(r.request.method).toBe('GET');
		expect(r.request.url).toBe('=/stores/{{$parameter.storeId}}/orders');
		expect(r.output.postReceive[0]).toEqual({
			type: 'rootProperty',
			properties: { property: 'data' },
		});
	});

	it('Get → GET /stores/{id}/orders/{orderId}', () => {
		const r = routingOf(operation('order', 'get'));
		expect(r.request.method).toBe('GET');
		expect(r.request.url).toBe('=/stores/{{$parameter.storeId}}/orders/{{$parameter.orderId}}');
	});

	it('Update Status → PUT .../status with a {status} body over the real enum', () => {
		const r = routingOf(operation('order', 'updateStatus'));
		expect(r.request.method).toBe('PUT');
		expect(r.request.url).toBe(
			'=/stores/{{$parameter.storeId}}/orders/{{$parameter.orderId}}/status',
		);

		const status = property('status', 'order', 'updateStatus');
		expect(sendOf(status)).toEqual({ type: 'body', property: 'status' });
		// the six real Order statuses (alphabetized per n8n option-ordering convention)
		const statusValues = (status.options as INodePropertyOptions[]).map((o) => o.value).sort();
		expect(statusValues).toEqual(
			['new', 'preparing', 'ready', 'delivered', 'completed', 'cancelled'].sort(),
		);
	});

	it('List Limit maps to the per_page query param', () => {
		const limit = property('limit', 'order', 'list');
		expect(sendOf(limit)).toEqual({ type: 'query', property: 'per_page' });
	});
});

describe('juwri action node — Menu Item operations', () => {
	it('List → GET /stores/{id}/menu (bare array, no data unwrap)', () => {
		const r = routingOf(operation('menuItem', 'list'));
		expect(r.request.method).toBe('GET');
		expect(r.request.url).toBe('=/stores/{{$parameter.storeId}}/menu');
		expect(r.output).toBeUndefined();
	});

	it('Create → POST /stores/{id}/menu/items with required name + price body', () => {
		const r = routingOf(operation('menuItem', 'create'));
		expect(r.request.method).toBe('POST');
		expect(r.request.url).toBe('=/stores/{{$parameter.storeId}}/menu/items');

		expect(sendOf(property('name', 'menuItem', 'create'))).toEqual({ type: 'body', property: 'name' });
		expect(sendOf(property('price', 'menuItem', 'create'))).toEqual({ type: 'body', property: 'price' });
		expect(property('name', 'menuItem', 'create').required).toBe(true);
		expect(property('price', 'menuItem', 'create').required).toBe(true);
	});

	it('Update → PUT /stores/{id}/menu/items/{itemId}', () => {
		const r = routingOf(operation('menuItem', 'update'));
		expect(r.request.method).toBe('PUT');
		expect(r.request.url).toBe('=/stores/{{$parameter.storeId}}/menu/items/{{$parameter.itemId}}');
	});

	it('Set Availability → PATCH .../sold-out with an is_sold_out body', () => {
		const r = routingOf(operation('menuItem', 'setAvailability'));
		expect(r.request.method).toBe('PATCH');
		expect(r.request.url).toBe(
			'=/stores/{{$parameter.storeId}}/menu/items/{{$parameter.itemId}}/sold-out',
		);
		expect(sendOf(property('is_sold_out', 'menuItem', 'setAvailability'))).toEqual({
			type: 'body',
			property: 'is_sold_out',
		});
	});
});

describe('juwri action node — Customer operations', () => {
	it('List → GET /stores/{id}/customers, unwraps the data envelope', () => {
		const r = routingOf(operation('customer', 'list'));
		expect(r.request.method).toBe('GET');
		expect(r.request.url).toBe('=/stores/{{$parameter.storeId}}/customers');
		expect(r.output.postReceive[0].properties.property).toBe('data');
	});

	it('Find → GET /stores/{id}/customers with a required search query', () => {
		const r = routingOf(operation('customer', 'find'));
		expect(r.request.method).toBe('GET');
		expect(r.request.url).toBe('=/stores/{{$parameter.storeId}}/customers');

		const search = property('search', 'customer', 'find');
		expect(search.required).toBe(true);
		expect(sendOf(search)).toEqual({ type: 'query', property: 'search' });
	});
});
