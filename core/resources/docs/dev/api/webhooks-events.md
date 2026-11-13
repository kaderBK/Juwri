---
title: "Webhook events catalog"
audience: developer
group: Api
updated: 2026-08-06
---

# Webhook events catalog

Reference for every outbound webhook event, its payload, how to verify the
Standard-Webhooks signature, and how retries work. For subscribing and general
concepts see [Webhooks](webhooks.md); for token auth see
[Auth tokens](auth-tokens.md).

## Delivery envelope

Every delivery is an HTTPS `POST` with a JSON body in this envelope:

```json
{
  "id": "msg_h2K9xW3qLp8RtY5vN0aBcDe7",
  "event": "order.paid",
  "created_at": "2026-07-08T14:03:11+00:00",
  "data": { "order_id": 4812, "total": 96.5, "payment_gateway": "stripe" }
}
```

`id` is the message id — stable across retries of the same delivery, unique
per endpoint (generated as `msg_` plus 24 random characters). Deduplicate on
it. `data` is the event-specific payload listed below. Requests carry these
headers:

```
content-type: application/json
user-agent: <site name>-Webhooks/1
webhook-id: msg_h2K9xW3qLp8RtY5vN0aBcDe7
webhook-timestamp: 1783001191
webhook-signature: v1,<base64 HMAC-SHA256>
X-Webhook-Event: order.paid            (legacy)
X-Webhook-Signature: <hex HMAC of body> (legacy)
```

## Event catalog

Endpoints created in the dashboard (the **API & Webhooks** page,
`POST api/webhooks`) can subscribe to **any fired event** — validation accepts
every name in `WebhookEndpoint::SELECTABLE_EVENTS`: `*`, `order.created`,
`order.status_changed`, `order.paid`, `menu.updated`, `entitlement.changed`,
the full `subscription.*` family (`paused`, `resumed`, `renewed`, `expired`,
`plan_changed`, `past_due`, `expiring`, `trial_ending`), and `app.uninstalled`.
Marketplace app installations register their own event lists at install time,
which may also include `*`. The dispatcher matches an endpoint when its
`events` list contains the event name or `*`.

| Event | Fired when |
|---|---|
| `order.created` | An order is placed — public storefront checkout, storefront API, POS, or an inbound HungerStation order. Held POS/API orders defer the event until they resume |
| `order.paid` | An online payment completes, or the last split-bill share is paid |
| `order.status_changed` | Order status is updated via the admin API (`PUT /api/v1/stores/{id}/orders/{orderId}/status`) or by staff in the staff app |
| `order.driver_assigned` | Delivery status enters `assigned` |
| `order.out_for_delivery` | Delivery status enters `out_for_delivery` |
| `order.delivered` | Delivery status enters `delivered` |
| `order.delivery_failed` | Delivery status enters `failed` |
| `menu.updated` | A menu item or category is created, updated, or deleted — any write path (dashboard, API/MCP, CSV import, POS). Order-driven automatic stock deductions are suppressed (they already surface as `order.*`) |
| `entitlement.changed` | A plan feature is granted to or revoked from the owner |
| `subscription.renewed` | A subscription renewal charge succeeds |
| `subscription.paused` / `subscription.resumed` | An admin pauses or resumes the subscription |
| `subscription.expiring` | Daily tick, N days before `ends_at` (payload has `days_left`) |
| `subscription.trial_ending` | Daily tick, N days before a trial ends (`days_left`) |
| `subscription.past_due` | Renewal failed; the dunning grace window starts |
| `subscription.expired` | Subscription or trial lapses (`trial: true` on trial expiry) |
| `subscription.plan_changed` | A scheduled plan change is applied (`scheduled: true`) |
| `app.uninstalled` | The owner uninstalls a marketplace app (always delivered to the app's endpoint; afterwards the endpoint's event list is emptied) |
| `refund.completed` | A refund is completed for an order (to the original tender or store credit) |
| `reservation.created` | A table reservation is booked (desk, storefront, or API) |
| `reservation.cancelled` | A reservation is cancelled |
| `customer.created` | A new customer record is created for a restaurant |
| `shift.opened` | A staff member opens a POS/cash shift |
| `shift.closed` | A shift is closed (payload carries the over/short amount) |
| `ping` | The **Test** button on the API & Webhooks page (`data.message` only) |

Note the status vocabularies differ: `order.status_changed` reports the order
status (`new`, `preparing`, `ready`, `delivered`, `completed`, `cancelled`),
while the four delivery events track the separate delivery status.

## Payload shapes

`data` for each event. Fields vary by source, as shown.

`order.created` — storefront API and POS send the full shape below
(POS uses `source: "pos"`); the public storefront checkout instead sends
`order_id`, `store_id`, `table_number`, `total`, `status`; HungerStation
adds `hs_order_id` and `source: "hungerstation"` (and no `track_token`):

```json
{
  "order_id": 4812,
  "store_id": 37,
  "type": "delivery",
  "status": "new",
  "total": 96.5,
  "currency": "USD",
  "track_token": "tk_9f3a2c7e",
  "source": "api"
}
```

`order.paid` — `{ "order_id": 4812, "total": 96.5, "payment_gateway": "stripe" }`;
split-bill completion sends `{ "order_id", "total", "via": "split_bill" }`.

`order.status_changed` — `{ "order_id": 4812, "new_status": "preparing" }`
(plus `by_staff: <staff id>` when changed from the staff app).

Delivery events — `{ "order_id": 4812, "delivery_status": "assigned", "driver_id": 9 }`.

`menu.updated` —
`{ "store_id": 37, "change": "updated", "entity": "item", "id": 314 }`.
`change` is `created`, `updated`, or `deleted`; `entity` is `item` or
`category`; `id` is the item/category id.

`entitlement.changed` —
`{ "action": "granted", "feature_key": "driver_app", "source_type": "...", "source_id": 1, "user_id": 51, "occurred_at": "..." }`.
`action` is `granted` or `revoked`.

Subscription tick events (`renewed` excepted, see below) share a base payload:

```json
{
  "subscription_id": 220,
  "user_id": 51,
  "plan_id": 4,
  "status": "past_due",
  "ends_at": "2026-08-01T00:00:00+00:00"
}
```

plus the extras noted in the table (`days_left`, `trial`, `scheduled`).
`subscription.paused` sends only `{ subscription_id, plan_id }`,
`subscription.resumed` `{ subscription_id, ends_at }`, and
`subscription.renewed` `{ subscription_id, plan_id, ends_at }`.

`app.uninstalled` — `{ "app": "<slug>", "user_id": 51, "uninstalled_at": "..." }`.
When the uninstall is triggered by an account deletion, the payload carries an
extra `"reason": "account_deleted"` field; a plain owner uninstall omits `reason`.

`refund.completed` —
`{ "order_id": 4812, "amount": 20, "method": "store_credit" }`.
`method` is the refund destination (`store_credit`, or the original tender such
as `card`/`cash`); `amount` is in the order's currency.

`reservation.created` —
`{ "reservation_id": 512, "status": "pending", "source": "desk" }`.
`source` is where the booking came from (`desk`, `storefront`, `api`).
`reservation.cancelled` — `{ "reservation_id": 512, "status": "cancelled" }`.

`customer.created` — `{ "customer_id": 8841, "name": "Jordan Rivera" }`.

`shift.opened` — `{ "shift_id": 77, "branch_id": 3, "staff_id": 12 }`.
`shift.closed` — `{ "shift_id": 77, "over_short": -2.5 }` (negative = short).

Payloads are thin on purpose: they carry ids, not full records. Fetch the
current state through the [admin API](admin-api.md) — by the time you process
a retry, the resource may have changed again.

## Verifying signatures

The `webhook-signature` header follows the Standard Webhooks scheme:
base64-encoded HMAC-SHA256 over `{webhook-id}.{webhook-timestamp}.{raw body}`,
keyed with your endpoint's signing secret (shown on the API & Webhooks page —
click to reveal). Because the id and timestamp are inside the signed content,
a captured request cannot be replayed with a different id or time. Always
verify before trusting the payload, and reject timestamps older than a few
minutes (5 is a good tolerance).

Node:

```js
const crypto = require('crypto');

function verify(req, secret) {
  const id = req.headers['webhook-id'];
  const ts = req.headers['webhook-timestamp'];
  const sig = req.headers['webhook-signature']; // "v1,<base64>"

  if (Math.abs(Date.now() / 1000 - Number(ts)) > 300) return false;

  const expected = crypto.createHmac('sha256', secret)
    .update(`${id}.${ts}.${req.rawBody}`)  // raw bytes, not re-serialised JSON
    .digest('base64');

  const given = sig.split(',')[1] ?? '';
  return given.length === expected.length &&
    crypto.timingSafeEqual(Buffer.from(given), Buffer.from(expected));
}
```

PHP:

```php
function verify(string $rawBody, array $headers, string $secret): bool
{
    $id  = $headers['webhook-id'];
    $ts  = $headers['webhook-timestamp'];
    $sig = explode(',', $headers['webhook-signature'], 2)[1] ?? '';

    if (abs(time() - (int) $ts) > 300) {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', "$id.$ts.$rawBody", $secret, true));

    return hash_equals($expected, $sig);
}
```

Library note: the secret is stored and used as a raw 32-character string, not
in the spec's `whsec_<base64>` serialisation. Official Standard-Webhooks
libraries base64-decode the secret, so to use one, pass
`'whsec_' . base64_encode($secret)`.

The legacy `X-Webhook-Signature` header (hex HMAC-SHA256 of the body alone,
same secret) is kept for older integrations. Prefer the `webhook-signature`
header — the legacy scheme does not bind the id or timestamp, so it has no
replay protection.

## Retries and backoff

Each matching endpoint gets its own queued delivery job (`DeliverWebhookJob`),
so a slow or dead endpoint never blocks another endpoint or the request that
fired the event.

- Up to 5 attempts: the initial delivery plus 4 retries. Any response outside
  2xx (or a connection error) counts as a failure and triggers a retry.
- Backoff is roughly 1 minute, 5 minutes, 15 minutes, 1 hour, each plus a
  deterministic 0–29 second jitter derived from the message id.
- `webhook-id` and `webhook-timestamp` stay the same across retries of one
  delivery — dedupe on `webhook-id`.
- Requests time out after 10 seconds. Respond 2xx fast and do slow work
  asynchronously.
- Redirects are never followed and only HTTPS is spoken. URLs are re-checked
  against an SSRF guard at delivery time; private, loopback, link-local and
  cloud-metadata addresses are rejected without retry.
- After 15 consecutive failed deliveries the endpoint is automatically
  disabled (`disabled_reason` records why). Re-enable it from the API &
  Webhooks page once your receiver is fixed; a single success resets the
  failure streak.
- On installs without a queue worker (the `sync` driver), delivery runs once
  after the HTTP response with no retries — one more reason to return 2xx
  reliably.

Every attempt is logged to the delivery log (event, HTTP status, attempt
number, first 500 characters of the response body). The API & Webhooks page
shows the 50 most recent deliveries across your endpoints.

## Testing an endpoint

Use the **Test** button next to the endpoint on the API & Webhooks page
(`POST api/webhooks/{id}/test`). It sends a fully signed `ping` event
(`data.message` explains it is a test) through the same queued pipeline, so it
exercises your signature verification and shows up in the delivery log.

## Related pages

- [Webhooks](webhooks.md) — subscribing, delivery format overview
- [Admin API reference](admin-api.md) — fetch full resources from ids in payloads
- [Auth tokens](auth-tokens.md) — API token management on the same page
- [Errors and rate limits](errors-rate-limits.md)
