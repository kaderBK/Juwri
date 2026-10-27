// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import { NodeApiError } from 'n8n-workflow';
import type {
	IDataObject,
	IHookFunctions,
	IHttpRequestMethods,
	IHttpRequestOptions,
	INodeType,
	INodeTypeDescription,
	IWebhookFunctions,
	IWebhookResponseData,
	JsonObject,
} from 'n8n-workflow';

/**
 * The topics a merchant may subscribe to — mirrors the platform's
 * `WebhookEndpoint::SELECTABLE_EVENTS` (kept in sync; a drifted name is rejected
 * by the subscribe endpoint's `Rule::in(SELECTABLE_EVENTS)` validation). `*`
 * ("All Events") subscribes to every current and future event.
 */
const SELECTABLE_EVENTS: Array<{ name: string; value: string }> = [
	{ name: 'All Events', value: '*' },
	{ name: 'Order Created', value: 'order.created' },
	{ name: 'Order Status Changed', value: 'order.status_changed' },
	{ name: 'Order Paid', value: 'order.paid' },
	{ name: 'Refund Completed', value: 'refund.completed' },
	{ name: 'Reservation Created', value: 'reservation.created' },
	{ name: 'Reservation Cancelled', value: 'reservation.cancelled' },
	{ name: 'Customer Created', value: 'customer.created' },
	{ name: 'Shift Opened', value: 'shift.opened' },
	{ name: 'Shift Closed', value: 'shift.closed' },
	{ name: 'Menu Updated', value: 'menu.updated' },
	{ name: 'Entitlement Changed', value: 'entitlement.changed' },
	{ name: 'Subscription Paused', value: 'subscription.paused' },
	{ name: 'Subscription Resumed', value: 'subscription.resumed' },
	{ name: 'Subscription Renewed', value: 'subscription.renewed' },
	{ name: 'Subscription Expired', value: 'subscription.expired' },
	{ name: 'Subscription Plan Changed', value: 'subscription.plan_changed' },
	{ name: 'Subscription Past Due', value: 'subscription.past_due' },
	{ name: 'Subscription Expiring', value: 'subscription.expiring' },
	{ name: 'Subscription Trial Ending', value: 'subscription.trial_ending' },
	{ name: 'App Uninstalled', value: 'app.uninstalled' },
];

/**
 * Owner-token REST call against `/api/v1`. Bound to a hook-functions context so
 * `checkExists`/`create`/`delete` all share one authenticated transport. Uses
 * `httpRequestWithAuthentication` so the bearer token comes from the credential,
 * never from a node parameter.
 */
async function juwriApiRequest(
	this: IHookFunctions,
	method: IHttpRequestMethods,
	resource: string,
	body?: IDataObject,
): Promise<IDataObject> {
	const credentials = await this.getCredentials('juwriApi');
	const baseUrl = String(credentials.baseUrl).replace(/\/+$/, '');

	const options: IHttpRequestOptions = {
		method,
		url: `${baseUrl}/api/v1${resource}`,
		headers: { Accept: 'application/json' },
		json: true,
	};
	if (body !== undefined) {
		options.body = body;
	}

	return (await this.helpers.httpRequestWithAuthentication.call(
		this,
		'juwriApi',
		options,
	)) as IDataObject;
}

/**
 * juwri Trigger — starts a workflow when the platform delivers a webhook.
 *
 * Registration is programmatic (n8n's REST-hook lifecycle): activating the
 * workflow SUBSCRIBES against `POST /api/v1/webhook-endpoints`, deactivating it
 * UNSUBSCRIBES against `DELETE /api/v1/webhook-endpoints/{id}`. The created
 * endpoint's id is persisted in the node's workflow static data and is the ONLY
 * id `delete` ever removes (never enumerate-and-delete — that once let a node
 * remove unrelated endpoints).
 *
 * Each delivery is the Standard-Webhooks envelope `{ id, event, created_at,
 * data }`; the node emits that object as the workflow's first item.
 */
export class JuwriTrigger implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'juwri Trigger',
		name: 'juwriTrigger',
		icon: 'file:juwri.svg',
		group: ['trigger'],
		version: 1,
		subtitle: '=Events: {{$parameter["events"].join(", ")}}',
		description: 'Starts the workflow when juwri sends a webhook event',
		defaults: { name: 'juwri Trigger' },
		inputs: [],
		outputs: ['main'],
		credentials: [{ name: 'juwriApi', required: true }],
		webhooks: [
			{
				name: 'default',
				httpMethod: 'POST',
				responseMode: 'onReceived',
				path: 'webhook',
			},
		],
		properties: [
			{
				displayName: 'Events',
				name: 'events',
				type: 'multiOptions',
				required: true,
				default: [],
				description: 'The webhook events that should start this workflow',
				options: SELECTABLE_EVENTS,
			},
			{
				displayName:
					'Deliveries are signed with the Standard Webhooks scheme. The signing secret is shown once on subscribe; signature verification is optional and not performed by this node (see the README).',
				name: 'signatureNotice',
				type: 'notice',
				default: '',
			},
		],
	};

	webhookMethods = {
		default: {
			/**
			 * Does our subscription already exist on the platform? Matches the stored
			 * id first; if there is none (e.g. the workflow was imported), adopts an
			 * endpoint whose URL equals this node's webhook URL so activation does not
			 * create a duplicate.
			 */
			async checkExists(this: IHookFunctions): Promise<boolean> {
				const staticData = this.getWorkflowStaticData('node');
				const webhookUrl = this.getNodeWebhookUrl('default');

				const response = await juwriApiRequest.call(this, 'GET', '/webhook-endpoints');
				const rows = (response.data as Array<IDataObject> | undefined) ?? [];

				const storedId = staticData.endpointId as string | number | undefined;
				if (storedId !== undefined) {
					const stillThere = rows.some((r) => String(r.id) === String(storedId));
					if (!stillThere) {
						// Deleted on the platform side — forget it so create() re-subscribes.
						delete staticData.endpointId;
						delete staticData.secret;
					}
					return stillThere;
				}

				const match = rows.find((r) => r.url === webhookUrl);
				if (match) {
					staticData.endpointId = match.id as string | number;
					return true;
				}
				return false;
			},

			/**
			 * Subscribe. Body is `{ url, events }` (the field IS `url`). Persists the
			 * returned id (and the one-time secret) into workflow static data.
			 */
			async create(this: IHookFunctions): Promise<boolean> {
				const webhookUrl = this.getNodeWebhookUrl('default');
				const events = this.getNodeParameter('events') as string[];

				const response = await juwriApiRequest.call(this, 'POST', '/webhook-endpoints', {
					url: webhookUrl,
					events,
				});

				if (response.id === undefined) {
					throw new NodeApiError(this.getNode(), response as JsonObject, {
						message: 'juwri did not return a webhook endpoint id on subscribe.',
					});
				}

				const staticData = this.getWorkflowStaticData('node');
				staticData.endpointId = response.id as string | number;
				// The secret is returned exactly once; keep it for anyone who wants to
				// verify the signature in a downstream node.
				staticData.secret = response.secret as string | undefined;
				return true;
			},

			/**
			 * Unsubscribe — deletes ONLY the stored id, never a URL/enumeration match,
			 * then clears static data. A 404 means it is already gone: treat as done.
			 */
			async delete(this: IHookFunctions): Promise<boolean> {
				const staticData = this.getWorkflowStaticData('node');
				const storedId = staticData.endpointId as string | number | undefined;
				if (storedId === undefined) {
					return true; // nothing we created
				}

				try {
					await juwriApiRequest.call(this, 'DELETE', `/webhook-endpoints/${storedId}`);
				} catch (error) {
					const statusCode = (error as { httpCode?: string; statusCode?: number }).httpCode;
					if (statusCode !== '404') {
						throw error;
					}
				}

				delete staticData.endpointId;
				delete staticData.secret;
				return true;
			},
		},
	};

	/** Emit the delivered envelope `{ id, event, created_at, data }` as item #1. */
	async webhook(this: IWebhookFunctions): Promise<IWebhookResponseData> {
		const body = this.getBodyData() as IDataObject;
		return {
			workflowData: [this.helpers.returnJsonArray([body])],
		};
	}
}
