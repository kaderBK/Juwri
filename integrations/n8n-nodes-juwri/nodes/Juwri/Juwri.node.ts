// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import type { INodeProperties, INodeType, INodeTypeDescription } from 'n8n-workflow';

/**
 * juwri — declarative action node.
 *
 * Operations map 1:1 onto the owner token REST API (`/api/v1`, documented at
 * {baseUrl}/developers and machine-readable at {baseUrl}/api/v1/openapi.json).
 * The property/routing skeleton below is the inlined result of walking that
 * OpenAPI spec — it is committed TypeScript so the package keeps ZERO runtime
 * dependencies (no generator ships at install time). `scripts/openapi-props.md`
 * records how to regenerate it from a fresh spec.
 *
 * Resources & operations:
 *   Order      → List, Get, Update Status
 *   Menu Item  → List, Create, Update, Set Availability
 *   Customer   → List, Find
 *
 * Every request authenticates with the juwri API credential (bearer
 * token). List operations return one n8n item per record (the paginator/data
 * envelope is unwrapped); single-record operations return the record object.
 */

/** Menu-item body fields shared by Create ("Additional Fields") and Update ("Update Fields"). */
const menuItemBodyOptions: INodeProperties[] = [
	{
		displayName: 'Category ID',
		name: 'category_id',
		type: 'number',
		default: 0,
		routing: { send: { type: 'body', property: 'category_id' } },
	},
	{
		displayName: 'Description',
		name: 'description',
		type: 'string',
		default: '',
		routing: { send: { type: 'body', property: 'description' } },
	},
	{
		displayName: 'Image URL',
		name: 'image',
		type: 'string',
		default: '',
		routing: { send: { type: 'body', property: 'image' } },
	},
	{
		displayName: 'Type',
		name: 'type',
		type: 'options',
		options: [
			{ name: 'Vegetarian', value: 'veg' },
			{ name: 'Non-Vegetarian', value: 'nonveg' },
		],
		default: 'veg',
		routing: { send: { type: 'body', property: 'type' } },
	},
	{
		displayName: 'Active',
		name: 'active',
		type: 'boolean',
		default: true,
		routing: { send: { type: 'body', property: 'active' } },
	},
	{
		displayName: 'Sold Out',
		name: 'is_sold_out',
		type: 'boolean',
		default: false,
		routing: { send: { type: 'body', property: 'is_sold_out' } },
	},
	{
		displayName: 'Popular',
		name: 'is_popular',
		type: 'boolean',
		default: false,
		routing: { send: { type: 'body', property: 'is_popular' } },
	},
	{
		displayName: 'Vegan',
		name: 'is_vegan',
		type: 'boolean',
		default: false,
		routing: { send: { type: 'body', property: 'is_vegan' } },
	},
	{
		displayName: 'Halal',
		name: 'is_halal',
		type: 'boolean',
		default: false,
		routing: { send: { type: 'body', property: 'is_halal' } },
	},
	{
		displayName: 'Gluten Free',
		name: 'is_gluten_free',
		type: 'boolean',
		default: false,
		routing: { send: { type: 'body', property: 'is_gluten_free' } },
	},
	{
		displayName: 'Calories',
		name: 'calories',
		type: 'number',
		default: 0,
		routing: { send: { type: 'body', property: 'calories' } },
	},
	{
		displayName: 'Prep Minutes',
		name: 'prep_minutes',
		type: 'number',
		default: 0,
		routing: { send: { type: 'body', property: 'prep_minutes' } },
	},
	{
		displayName: 'Position',
		name: 'position',
		type: 'number',
		default: 0,
		routing: { send: { type: 'body', property: 'position' } },
	},
];

export class Juwri implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'juwri',
		name: 'juwri',
		icon: 'file:juwri.svg',
		group: ['transform'],
		version: 1,
		subtitle: '={{$parameter["operation"] + ": " + $parameter["resource"]}}',
		description: 'Manage orders, menu items and customers in juwri',
		defaults: { name: 'juwri' },
		inputs: ['main'],
		outputs: ['main'],
		usableAsTool: true,
		credentials: [{ name: 'juwriApi', required: true }],
		requestDefaults: {
			baseURL: '={{$credentials.baseUrl}}/api/v1',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
		},
		properties: [
			{
				displayName: 'Resource',
				name: 'resource',
				type: 'options',
				noDataExpression: true,
				options: [
					{ name: 'Order', value: 'order' },
					{ name: 'Menu Item', value: 'menuItem' },
					{ name: 'Customer', value: 'customer' },
				],
				default: 'order',
			},

			// ── Order operations ──────────────────────────────────────────────
			{
				displayName: 'Operation',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['order'] } },
				options: [
					{
						name: 'List',
						value: 'list',
						action: 'List orders',
						routing: {
							request: {
								method: 'GET',
								url: '=/stores/{{$parameter.storeId}}/orders',
							},
							output: {
								postReceive: [{ type: 'rootProperty', properties: { property: 'data' } }],
							},
						},
					},
					{
						name: 'Get',
						value: 'get',
						action: 'Get an order',
						routing: {
							request: {
								method: 'GET',
								url: '=/stores/{{$parameter.storeId}}/orders/{{$parameter.orderId}}',
							},
						},
					},
					{
						name: 'Update Status',
						value: 'updateStatus',
						action: 'Update order status',
						routing: {
							request: {
								method: 'PUT',
								url: '=/stores/{{$parameter.storeId}}/orders/{{$parameter.orderId}}/status',
							},
						},
					},
				],
				default: 'list',
			},

			// ── Menu Item operations ──────────────────────────────────────────
			{
				displayName: 'Operation',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['menuItem'] } },
				options: [
					{
						name: 'List',
						value: 'list',
						action: 'List menu items',
						routing: {
							request: {
								method: 'GET',
								url: '=/stores/{{$parameter.storeId}}/menu',
							},
						},
					},
					{
						name: 'Create',
						value: 'create',
						action: 'Create a menu item',
						routing: {
							request: {
								method: 'POST',
								url: '=/stores/{{$parameter.storeId}}/menu/items',
							},
						},
					},
					{
						name: 'Update',
						value: 'update',
						action: 'Update a menu item',
						routing: {
							request: {
								method: 'PUT',
								url: '=/stores/{{$parameter.storeId}}/menu/items/{{$parameter.itemId}}',
							},
						},
					},
					{
						name: 'Set Availability',
						value: 'setAvailability',
						action: 'Set menu item availability',
						routing: {
							request: {
								method: 'PATCH',
								url: '=/stores/{{$parameter.storeId}}/menu/items/{{$parameter.itemId}}/sold-out',
							},
						},
					},
				],
				default: 'list',
			},

			// ── Customer operations ───────────────────────────────────────────
			{
				displayName: 'Operation',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['customer'] } },
				options: [
					{
						name: 'List',
						value: 'list',
						action: 'List customers',
						routing: {
							request: {
								method: 'GET',
								url: '=/stores/{{$parameter.storeId}}/customers',
							},
							output: {
								postReceive: [{ type: 'rootProperty', properties: { property: 'data' } }],
							},
						},
					},
					{
						name: 'Find',
						value: 'find',
						action: 'Find customers by name phone or email',
						routing: {
							request: {
								method: 'GET',
								url: '=/stores/{{$parameter.storeId}}/customers',
							},
							output: {
								postReceive: [{ type: 'rootProperty', properties: { property: 'data' } }],
							},
						},
					},
				],
				default: 'list',
			},

			// ── Shared: Restaurant ID (every operation) ───────────────────────
			{
				displayName: 'Restaurant ID',
				name: 'storeId',
				type: 'string',
				default: '',
				required: true,
				description: 'Numeric ID of the restaurant (from a "Restaurant → List" call or GET /api/v1/stores)',
			},

			// ── Order: Order ID (Get / Update Status) ─────────────────────────
			{
				displayName: 'Order ID',
				name: 'orderId',
				type: 'string',
				default: '',
				required: true,
				displayOptions: { show: { resource: ['order'], operation: ['get', 'updateStatus'] } },
			},
			{
				displayName: 'Status',
				name: 'status',
				type: 'options',
				options: [
					{ name: 'Cancelled', value: 'cancelled' },
					{ name: 'Completed', value: 'completed' },
					{ name: 'Delivered', value: 'delivered' },
					{ name: 'New', value: 'new' },
					{ name: 'Preparing', value: 'preparing' },
					{ name: 'Ready', value: 'ready' },
				],
				default: 'preparing',
				required: true,
				displayOptions: { show: { resource: ['order'], operation: ['updateStatus'] } },
				routing: { send: { type: 'body', property: 'status' } },
			},

			// ── Order: List filters ───────────────────────────────────────────
			{
				displayName: 'Limit',
				name: 'limit',
				type: 'number',
				typeOptions: { minValue: 1 },
				default: 50,
				displayOptions: { show: { resource: ['order'], operation: ['list'] } },
				description: 'Max number of results to return',
				routing: { send: { type: 'query', property: 'per_page' } },
			},
			{
				displayName: 'Filters',
				name: 'filters',
				type: 'collection',
				placeholder: 'Add Filter',
				default: {},
				displayOptions: { show: { resource: ['order'], operation: ['list'] } },
				options: [
					{
						displayName: 'Status',
						name: 'status',
						type: 'string',
						default: '',
						routing: { send: { type: 'query', property: 'status' } },
					},
					{
						displayName: 'Created After',
						name: 'since',
						type: 'string',
						default: '',
						description:
							'ISO-8601 timestamp; returns orders created at or after it (incremental polling)',
						routing: { send: { type: 'query', property: 'since' } },
					},
					{
						displayName: 'Created Before',
						name: 'to',
						type: 'string',
						default: '',
						description: 'ISO-8601 timestamp; returns orders created at or before it',
						routing: { send: { type: 'query', property: 'to' } },
					},
				],
			},

			// ── Menu Item: Item ID (Update / Set Availability) ────────────────
			{
				displayName: 'Item ID',
				name: 'itemId',
				type: 'string',
				default: '',
				required: true,
				displayOptions: { show: { resource: ['menuItem'], operation: ['update', 'setAvailability'] } },
			},
			{
				displayName: 'Name',
				name: 'name',
				type: 'string',
				default: '',
				required: true,
				displayOptions: { show: { resource: ['menuItem'], operation: ['create'] } },
				routing: { send: { type: 'body', property: 'name' } },
			},
			{
				displayName: 'Price',
				name: 'price',
				type: 'number',
				default: 0,
				required: true,
				displayOptions: { show: { resource: ['menuItem'], operation: ['create'] } },
				routing: { send: { type: 'body', property: 'price' } },
			},
			{
				displayName: 'Sold Out',
				name: 'is_sold_out',
				type: 'boolean',
				default: true,
				displayOptions: { show: { resource: ['menuItem'], operation: ['setAvailability'] } },
				description: 'Whether the item is sold out (unavailable)',
				routing: { send: { type: 'body', property: 'is_sold_out' } },
			},
			{
				displayName: 'Additional Fields',
				name: 'additionalFields',
				type: 'collection',
				placeholder: 'Add Field',
				default: {},
				displayOptions: { show: { resource: ['menuItem'], operation: ['create'] } },
				options: menuItemBodyOptions,
			},
			{
				displayName: 'Update Fields',
				name: 'updateFields',
				type: 'collection',
				placeholder: 'Add Field',
				default: {},
				displayOptions: { show: { resource: ['menuItem'], operation: ['update'] } },
				options: [
					{
						displayName: 'Name',
						name: 'name',
						type: 'string',
						default: '',
						routing: { send: { type: 'body', property: 'name' } },
					},
					{
						displayName: 'Price',
						name: 'price',
						type: 'number',
						default: 0,
						routing: { send: { type: 'body', property: 'price' } },
					},
					...menuItemBodyOptions,
				],
			},

			// ── Customer: List / Find filters ─────────────────────────────────
			{
				displayName: 'Search',
				name: 'search',
				type: 'string',
				default: '',
				required: true,
				displayOptions: { show: { resource: ['customer'], operation: ['find'] } },
				description: 'Match against customer name, phone or email',
				routing: { send: { type: 'query', property: 'search' } },
			},
			{
				displayName: 'Newest First',
				name: 'newestFirst',
				type: 'boolean',
				default: true,
				displayOptions: { show: { resource: ['customer'], operation: ['list'] } },
				description: 'Whether to sort by newest-registered (sort=created) instead of most-recently-active',
				routing: {
					send: {
						type: 'query',
						property: 'sort',
						value: '={{ $value ? "created" : "recent" }}',
					},
				},
			},
			{
				displayName: 'Limit',
				name: 'limit',
				type: 'number',
				typeOptions: { minValue: 1 },
				default: 50,
				displayOptions: { show: { resource: ['customer'], operation: ['list', 'find'] } },
				description: 'Max number of results to return',
				routing: { send: { type: 'query', property: 'per_page' } },
			},
		],
	};
}
